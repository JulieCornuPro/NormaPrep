<?php
/**
 * Export du contenu (scénarios, questions, options, tags, flashcards) vers un
 * fichier JSON téléchargeable.
 *
 * Le format produit est IDENTIQUE à celui attendu par NPQ_Importer : un objet
 * avec « meta », « scenarios », « questions » et « flashcards ». Un fichier
 * exporté est donc directement ré-importable — ce qui donne, gratuitement, une
 * fonction de sauvegarde / restauration et de transfert entre sites.
 *
 * Deux portées d'export :
 *   - 'tout'       : scénarios + questions + flashcards ;
 *   - 'flashcards' : uniquement le tableau flashcards (+ meta).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Exporter {

    /**
     * Enregistre l'action d'export (déclenchée par le formulaire de la page
     * d'import/export). Appelée au chargement du plugin, côté admin.
     */
    public static function init() {
        add_action( 'admin_post_npq_exporter', [ __CLASS__, 'traiter_export' ] );
    }

    /**
     * Traite le clic sur un bouton d'export : construit le JSON et l'envoie au
     * navigateur en téléchargement.
     */
    public static function traiter_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        check_admin_referer( 'npq_exporter_action', 'npq_nonce' );

        // Portée demandée : 'tout' (par défaut) ou 'flashcards'.
        $portee = ( ( $_POST['npq_portee'] ?? '' ) === 'flashcards' ) ? 'flashcards' : 'tout';

        $certification_id = self::certification_courante();

        // Construction du document au format d'échange.
        $document = [
            'meta' => self::construire_meta( $certification_id, $portee ),
        ];

        if ( $portee === 'tout' ) {
            $document['scenarios'] = self::exporter_scenarios( $certification_id );
            $document['questions'] = self::exporter_questions( $certification_id );
        }

        // Les flashcards sont incluses dans les deux portées.
        $document['flashcards'] = self::exporter_flashcards( $certification_id );

        // Nom de fichier daté et parlant.
        $suffixe = ( $portee === 'flashcards' ) ? 'flashcards' : 'complet';
        $nom_fichier = sprintf( 'normaprep-%s-%s.json', $suffixe, gmdate( 'Y-m-d' ) );

        // JSON lisible (indenté), sans échappement des caractères accentués ni des /.
        $json = wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // En-têtes de téléchargement, puis on émet le fichier et on s'arrête.
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $nom_fichier . '"' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json; // JSON déjà encodé : pas d'échappement HTML ici (fichier, pas page).
        exit;
    }

    /* =====================================================================
     * CONSTRUCTION DES SECTIONS
     * ===================================================================== */

    /**
     * Bloc « meta » : version de schéma, certification, domaines, compteurs.
     * Reproduit la structure du fichier question_bank.json d'origine.
     */
    private static function construire_meta( $certification_id, $portee ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Libellé de la certification.
        $cert_nom = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT nom FROM {$p}certification WHERE id = %d",
            $certification_id
        ) );

        // Domaines de la certification, au format { "D1": { code, label }, ... }.
        $domaines = [];
        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine
             WHERE certification_id = %d ORDER BY code ASC",
            $certification_id
        ), ARRAY_A );
        foreach ( $lignes as $d ) {
            $domaines[ $d['code'] ] = [
                'code'  => $d['code'],
                'label' => $d['libelle'],
            ];
        }

        $meta = [
            'schema_version' => '1.1', // 1.1 : ajout du tableau « flashcards »
            'generated_at'   => gmdate( 'Y-m-d' ),
            'certification'  => $cert_nom,
            'export_scope'   => $portee, // 'tout' ou 'flashcards'
            'domains'        => $domaines,
        ];

        // Compteurs, selon la portée.
        $counts = [ 'flashcards' => self::compter( "{$p}flashcard", $certification_id ) ];
        if ( $portee === 'tout' ) {
            $counts['scenarios'] = self::compter( "{$p}scenario", $certification_id );
            $counts['questions'] = self::compter( "{$p}question", $certification_id );
        }
        $meta['counts'] = $counts;

        return $meta;
    }

    /** Scénarios, avec leur référence externe. */
    private static function exporter_scenarios( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ref_externe, nom, resume, contexte
             FROM {$p}scenario
             WHERE certification_id = %d
             ORDER BY id ASC",
            $certification_id
        ), ARRAY_A );

        $sortie = [];
        foreach ( $lignes as $s ) {
            // On expose la référence externe comme identifiant ré-importable.
            // À défaut (scénario créé à la main, sans ref), on retombe sur SCx.
            $ref = $s['ref_externe'] ? $s['ref_externe'] : ( 'SC' . $s['id'] );
            $sortie[] = [
                'id'      => $ref,
                'name'    => $s['nom'],
                'summary' => (string) $s['resume'],
                'context' => (string) $s['contexte'],
            ];
        }
        return $sortie;
    }

    /** Questions, avec options, tags, et rattachement au scénario par sa référence. */
    private static function exporter_questions( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $questions = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ref_externe, scenario_id, domaine, enonce,
                    explication, difficulte
             FROM {$p}question
             WHERE certification_id = %d
             ORDER BY id ASC",
            $certification_id
        ), ARRAY_A );

        // Libellés de domaine pour enrichir chaque question (domain_label).
        $libelles = self::libelles_domaines( $certification_id );

        $sortie = [];
        foreach ( $questions as $q ) {
            $qid = (int) $q['id'];

            // Référence du scénario associé (pour rejouer le lien à l'import).
            $scenario_ref = null;
            if ( $q['scenario_id'] ) {
                $scenario_ref = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT ref_externe FROM {$p}scenario WHERE id = %d",
                    (int) $q['scenario_id']
                ) );
                if ( ! $scenario_ref ) {
                    $scenario_ref = 'SC' . (int) $q['scenario_id'];
                }
            }

            // Options, dans l'ordre de position. On expose l'index de la bonne
            // réponse (answer_index), comme dans le fichier d'origine.
            $options = [];
            $answer_index = 0;
            $opt_lignes = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT texte, correcte, position
                 FROM {$p}option_reponse
                 WHERE question_id = %d
                 ORDER BY position ASC",
                $qid
            ), ARRAY_A );
            foreach ( $opt_lignes as $i => $opt ) {
                $options[] = [
                    'id'   => chr( 65 + $i ), // A, B, C, D…
                    'text' => $opt['texte'],
                ];
                if ( (int) $opt['correcte'] === 1 ) {
                    $answer_index = $i;
                }
            }

            // Tags, regroupés par type : { "iso_clause": [...], "skill_type": "..." }.
            $tags = self::tags_de_question( $qid );

            $sortie[] = [
                'id'           => $q['ref_externe'] ? $q['ref_externe'] : ( 'Q' . $qid ),
                'scenario_id'  => $scenario_ref,
                'domain'       => $q['domaine'],
                'domain_label' => isset( $libelles[ $q['domaine'] ] ) ? $libelles[ $q['domaine'] ] : '',
                'difficulty'   => (string) $q['difficulte'],
                'question'     => $q['enonce'],
                'options'      => $options,
                'answer_index' => $answer_index,
                'explanation'  => (string) $q['explication'],
                'tags'         => $tags,
            ];
        }
        return $sortie;
    }

    /** Flashcards, avec leur référence externe (clé d'import rejouable). */
    private static function exporter_flashcards( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $libelles = self::libelles_domaines( $certification_id );

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ref_externe, domaine, recto, verso, statut
             FROM {$p}flashcard
             WHERE certification_id = %d
             ORDER BY domaine ASC, id ASC",
            $certification_id
        ), ARRAY_A );

        $sortie = [];
        foreach ( $lignes as $index => $f ) {
            // Une flashcard créée à la main n'a pas de référence : on en fabrique
            // une, stable et lisible, pour que le fichier soit ré-importable.
            $ref = $f['ref_externe']
                ? $f['ref_externe']
                : sprintf( 'LI27001-FC-%04d', $index + 1 );

            $sortie[] = [
                'id'           => $ref,
                'domain'       => $f['domaine'],
                'domain_label' => isset( $libelles[ $f['domaine'] ] ) ? $libelles[ $f['domaine'] ] : '',
                'recto'        => $f['recto'],
                'verso'        => $f['verso'],
                'status'       => (string) $f['statut'],
            ];
        }
        return $sortie;
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /** Libellés de domaine indexés par code, pour la certification donnée. */
    private static function libelles_domaines( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $libelles = [];
        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine WHERE certification_id = %d",
            $certification_id
        ), ARRAY_A );
        foreach ( $lignes as $d ) {
            $libelles[ $d['code'] ] = $d['libelle'];
        }
        return $libelles;
    }

    /** Tags d'une question, regroupés par type de tag. */
    private static function tags_de_question( $question_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.nom AS type_nom, t.valeur
             FROM {$p}question_tag qt
             INNER JOIN {$p}tag t       ON t.id = qt.tag_id
             INNER JOIN {$p}tag_type tt ON tt.id = t.tag_type_id
             WHERE qt.question_id = %d
             ORDER BY tt.nom ASC, t.valeur ASC",
            $question_id
        ), ARRAY_A );

        // Regroupe en tableaux par type. (À l'import, une valeur unique ou un
        // tableau sont tous deux acceptés, donc ce format est compatible.)
        $tags = [];
        foreach ( $lignes as $l ) {
            $tags[ $l['type_nom'] ][] = $l['valeur'];
        }
        return $tags;
    }

    /** Compte les lignes d'une table pour la certification donnée. */
    private static function compter( $table, $certification_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE certification_id = %d",
            $certification_id
        ) );
    }

    private static function certification_courante() {
        return NPQ_Certification::id();
    }
}
