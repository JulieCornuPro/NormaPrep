<?php
/**
 * Résolution centralisée de la certification « courante ».
 *
 * Avant cette classe, chaque module refaisait la même requête
 * (« SELECT id FROM certification WHERE actif = 1 … LIMIT 1 »), avec des
 * variantes de repli différentes. Cette duplication était une dette : changer
 * la façon de déterminer la certification active obligeait à toucher une
 * dizaine de fichiers.
 *
 * Désormais, TOUT passe par ici. La logique de sélection vit à un seul endroit,
 * ce qui prépare aussi le multi-certification : le jour où un choix d'admin
 * pilotera la certification active, seule la méthode id() changera — tous les
 * appelants en bénéficieront sans modification.
 *
 * Règle de résolution actuelle (iso-comportement avec l'existant) :
 *   1. la certification marquée active (actif = 1), la plus ancienne d'abord ;
 *   2. à défaut, la première certification existante ;
 *   3. à défaut (base vide), aucune (0). Les appelants gèrent ce cas.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Certification {

    /** Cache mémoire par requête HTTP : évite de refaire le SELECT à chaque appel. */
    private static $cache_id = null;

    /**
     * Id de la certification courante. 0 si aucune n'existe.
     *
     * @return int
     */
    public static function id() {
        if ( self::$cache_id !== null ) {
            return self::$cache_id;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // 1) la certification active, la plus ancienne d'abord.
        $id = (int) $wpdb->get_var(
            "SELECT id FROM {$p}certification WHERE actif = 1 ORDER BY id ASC LIMIT 1"
        );

        // 2) à défaut, la première existante (repli : aucune n'est marquée active).
        if ( ! $id ) {
            $id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}certification ORDER BY id ASC LIMIT 1"
            );
        }

        self::$cache_id = $id;
        return $id;
    }

    /**
     * Ligne complète de la certification courante (id, code, nom, actif), ou
     * null si aucune n'existe.
     *
     * @return array|null
     */
    public static function courante() {
        $id = self::id();
        if ( ! $id ) {
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, code, nom, actif FROM {$p}certification WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /**
     * Code de la certification courante (ex. « LI27001 »), ou chaîne vide.
     * Remplace le code écrit en dur qui traînait dans l'import.
     *
     * @return string
     */
    public static function code() {
        $c = self::courante();
        return $c ? (string) $c['code'] : '';
    }

    /**
     * Nom lisible de la certification courante, ou chaîne vide.
     *
     * @return string
     */
    public static function nom() {
        $c = self::courante();
        return $c ? (string) $c['nom'] : '';
    }

    /**
     * Vide le cache mémoire. Utile après un import qui vient de créer la
     * première certification, ou après un changement de certification active.
     */
    public static function vider_cache() {
        self::$cache_id = null;
    }

    /* =====================================================================
     * GESTION (étape B1 : pilotage multi-certification en admin)
     * ===================================================================== */

    /**
     * Toutes les certifications, avec le volume de contenu de chacune.
     * Sert à la page de gestion et au sélecteur.
     *
     * @return array Lignes : id, code, nom, actif, nb_questions, nb_scenarios…
     */
    public static function toutes() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT c.id, c.code, c.nom, c.actif,
                    ( SELECT COUNT(*) FROM {$p}question q
                      WHERE q.certification_id = c.id ) AS nb_questions,
                    ( SELECT COUNT(*) FROM {$p}scenario s
                      WHERE s.certification_id = c.id ) AS nb_scenarios,
                    ( SELECT COUNT(*) FROM {$p}flashcard f
                      WHERE f.certification_id = c.id ) AS nb_flashcards
             FROM {$p}certification c
             ORDER BY c.id ASC",
            ARRAY_A
        );
    }

    /**
     * Définit LA certification active. Désactive toutes les autres :
     * l'invariant « une seule active à la fois » est essentiel, car la
     * résolution courante prend la plus ancienne active — deux actives
     * donneraient un résultat déroutant.
     *
     * @param int $id
     * @return bool Vrai si la certification existe et a été activée.
     */
    public static function definir_active( $id ) {
        $id = (int) $id;
        if ( ! $id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $existe = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}certification WHERE id = %d", $id
        ) );
        if ( ! $existe ) {
            return false;
        }

        // Une seule active : on désactive tout, puis on active la choisie.
        $wpdb->query( "UPDATE {$p}certification SET actif = 0" );
        $wpdb->update( "{$p}certification", [ 'actif' => 1 ], [ 'id' => $id ] );

        self::vider_cache();
        return true;
    }

    /**
     * Crée une certification si son code n'existe pas déjà, et renvoie son id.
     * La nouvelle certification est créée INACTIVE : créer du contenu ne doit
     * pas basculer silencieusement la certification de travail. On l'active
     * explicitement via definir_active().
     *
     * @param string $code Ex. « LA27001 ».
     * @param string $nom  Ex. « ISO/IEC 27001 Lead Auditor ».
     * @return int Id de la certification (existante ou créée), 0 si code vide.
     */
    public static function creer( $code, $nom ) {
        $code = strtoupper( trim( (string) $code ) );
        $nom  = trim( (string) $nom );
        if ( $code === '' || $nom === '' ) {
            return 0;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}certification WHERE code = %s", $code
        ) );
        if ( $id ) {
            return $id; // le code est unique : on ne duplique pas
        }

        // actif = 0 : la création n'change pas la certification de travail.
        $wpdb->insert( "{$p}certification", [
            'code'  => $code,
            'nom'   => $nom,
            'actif' => 0,
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Supprime une certification, à condition qu'elle soit vide de contenu.
     * On refuse la suppression d'une certification qui porte des questions,
     * scénarios ou flashcards : ce serait une perte de données silencieuse.
     *
     * @param int $id
     * @return true|string Vrai si supprimée, sinon un message d'erreur.
     */
    public static function supprimer( $id ) {
        $id = (int) $id;
        if ( ! $id ) {
            return 'Certification introuvable.';
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Refus si du contenu y est rattaché.
        $tables = [
            'question'  => 'question(s)',
            'scenario'  => 'scénario(s)',
            'flashcard' => 'flashcard(s)',
        ];
        foreach ( $tables as $table => $libelle ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}{$table} WHERE certification_id = %d", $id
            ) );
            if ( $n > 0 ) {
                return sprintf(
                    'Suppression impossible : cette certification contient %d %s. Videz-la d\'abord.',
                    $n, $libelle
                );
            }
        }

        // Refus si c'est la dernière certification restante.
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}certification" );
        if ( $total <= 1 ) {
            return 'Suppression impossible : c\'est la seule certification.';
        }

        $etait_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT actif FROM {$p}certification WHERE id = %d", $id
        ) );

        $wpdb->delete( "{$p}certification", [ 'id' => $id ] );
        self::vider_cache();

        // Si on vient de supprimer l'active, on en réactive une pour ne jamais
        // laisser l'application sans certification courante.
        if ( $etait_active ) {
            $suivante = (int) $wpdb->get_var(
                "SELECT id FROM {$p}certification ORDER BY id ASC LIMIT 1"
            );
            if ( $suivante ) {
                self::definir_active( $suivante );
            }
        }

        return true;
    }
}
