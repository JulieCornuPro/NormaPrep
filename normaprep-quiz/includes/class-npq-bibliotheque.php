<?php
/**
 * Bibliothèque de certifications d'un utilisateur.
 *
 * Modèle « bibliothèque personnelle » : un utilisateur acquiert une ou plusieurs
 * certifications, qui s'ajoutent à son compte. Cette classe centralise la
 * question « à quelles certifications cet utilisateur a-t-il accès ? », de la
 * même façon que NPQ_Certification centralise « quelle est la certification
 * courante ».
 *
 * Le DROIT d'accès est porté par la table de liaison utilisateur_certification,
 * indépendamment de la mécanique de vente : peu importe comment la ligne a été
 * créée (achat, abonnement, attribution manuelle), sa présence vaut accès.
 *
 * La colonne fin_acces permet, à terme, des accès à durée limitée. Tant qu'elle
 * vaut NULL, l'accès est permanent. La vérification en tient déjà compte, pour
 * n'avoir rien à changer le jour où tu vendras des accès temporaires.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Bibliotheque {

    /**
     * L'utilisateur a-t-il accès à cette certification ?
     * Un accès expiré (fin_acces dépassée) ne compte pas.
     *
     * @param int $utilisateur_id  Id métier (table utilisateur), pas l'id WP.
     * @param int $certification_id
     * @return bool
     */
    public static function a_acces( $utilisateur_id, $certification_id ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d
               AND certification_id = %d
               AND ( fin_acces IS NULL OR fin_acces >= CURDATE() )",
            $utilisateur_id,
            $certification_id
        ) );

        return ( $n > 0 );
    }

    /**
     * Les certifications auxquelles l'utilisateur a accès (non expirées),
     * avec leur code et leur nom, dans l'ordre d'acquisition.
     *
     * @param int $utilisateur_id
     * @return array Lignes : id, code, nom, date_acquisition, fin_acces.
     */
    public static function certifications_de( $utilisateur_id ) {
        $utilisateur_id = (int) $utilisateur_id;
        if ( ! $utilisateur_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.code, c.nom,
                    uc.date_acquisition, uc.fin_acces
             FROM {$p}utilisateur_certification uc
             INNER JOIN {$p}certification c ON c.id = uc.certification_id
             WHERE uc.utilisateur_id = %d
               AND ( uc.fin_acces IS NULL OR uc.fin_acces >= CURDATE() )
             ORDER BY uc.date_acquisition ASC, c.nom ASC",
            $utilisateur_id
        ), ARRAY_A );
    }

    /**
     * Ids des certifications accessibles (raccourci pour les clauses IN).
     *
     * @param int $utilisateur_id
     * @return int[]
     */
    public static function ids_de( $utilisateur_id ) {
        return array_map(
            static function ( $c ) { return (int) $c['id']; },
            self::certifications_de( $utilisateur_id )
        );
    }

    /**
     * Attribue une certification à un utilisateur (entrée dans la bibliothèque).
     * Idempotent : réattribuer une certification déjà présente ne crée pas de
     * doublon, mais peut mettre à jour la date de fin d'accès.
     *
     * @param int         $utilisateur_id
     * @param int         $certification_id
     * @param string|null $fin_acces  Date 'AAAA-MM-JJ', ou null (permanent).
     * @return bool Vrai si l'opération a réussi.
     */
    public static function attribuer( $utilisateur_id, $certification_id, $fin_acces = null ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Déjà présente ? On met éventuellement à jour la fin d'accès.
        $existant = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d AND certification_id = %d",
            $utilisateur_id,
            $certification_id
        ) );

        if ( $existant ) {
            $wpdb->update(
                "{$p}utilisateur_certification",
                [ 'fin_acces' => $fin_acces ],
                [ 'id' => $existant ]
            );
            return true;
        }

        $wpdb->insert( "{$p}utilisateur_certification", [
            'utilisateur_id'   => $utilisateur_id,
            'certification_id' => $certification_id,
            'date_acquisition' => current_time( 'mysql' ),
            'fin_acces'        => $fin_acces,
        ] );

        return (bool) $wpdb->insert_id;
    }

    /**
     * Retire une certification de la bibliothèque d'un utilisateur.
     *
     * @param int $utilisateur_id
     * @param int $certification_id
     * @return bool
     */
    public static function retirer( $utilisateur_id, $certification_id ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (bool) $wpdb->delete( "{$p}utilisateur_certification", [
            'utilisateur_id'   => $utilisateur_id,
            'certification_id' => $certification_id,
        ] );
    }

    /**
     * Migration douce : garantit que chaque utilisateur existant a accès à la
     * certification donnée (par défaut, la certification active). Idempotent —
     * on peut l'appeler à chaque chargement sans créer de doublons.
     *
     * Sert à ne casser l'accès de personne au déploiement de la bibliothèque :
     * les comptes créés avant l'existence de la table reçoivent leur accès
     * historique.
     *
     * @param int|null $certification_id  Défaut : la certification active.
     * @return int Nombre d'accès créés.
     */
    public static function migration_douce( $certification_id = null ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $certification_id = $certification_id ? (int) $certification_id : NPQ_Certification::id();
        if ( ! $certification_id ) {
            return 0;
        }

        // Utilisateurs sans aucune ligne pour cette certification.
        $manquants = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT u.id
             FROM {$p}utilisateur u
             LEFT JOIN {$p}utilisateur_certification uc
                    ON uc.utilisateur_id = u.id
                   AND uc.certification_id = %d
             WHERE uc.id IS NULL",
            $certification_id
        ) );

        $crees = 0;
        foreach ( $manquants as $uid ) {
            $wpdb->insert( "{$p}utilisateur_certification", [
                'utilisateur_id'   => (int) $uid,
                'certification_id' => $certification_id,
                'date_acquisition' => current_time( 'mysql' ),
                'fin_acces'        => null,
            ] );
            $crees++;
        }

        return $crees;
    }
}
