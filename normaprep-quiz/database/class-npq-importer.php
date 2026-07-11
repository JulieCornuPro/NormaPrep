<?php
/**
 * Import du contenu (scénarios, questions, options, tags) depuis un fichier JSON.
 *
 * Fournit une page d'administration avec un bouton « Importer le contenu ».
 * L'import est REJOUABLE : il s'appuie sur la référence externe (SC0, Q001…)
 * pour créer les éléments absents et mettre à jour ceux déjà présents, sans
 * jamais créer de doublon.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Importer {

    /**
     * Enregistre la page d'admin et traite l'action d'import.
     * Appelée au chargement du plugin (côté admin).
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'ajouter_page_admin' ] );
        add_action( 'admin_post_npq_importer', [ __CLASS__, 'traiter_import' ] );
    }

    /**
     * Ajoute une entrée « NormaPrep » dans le menu d'administration.
     */
    public static function ajouter_page_admin() {
        add_menu_page(
            'NormaPrep Quiz',            // titre de la page
            'NormaPrep',                 // libellé du menu
            'manage_options',            // capacité requise (administrateur)
            'normaprep-quiz',            // identifiant de la page
            [ __CLASS__, 'afficher_page' ],
            'dashicons-welcome-learn-more',
            30
        );
    }

    /**
     * Affiche la page d'admin : un bouton pour lancer l'import.
     */
    public static function afficher_page() {
        // Message de retour éventuel (après un import).
        $message = '';
        if ( isset( $_GET['npq_resultat'] ) ) {
            $r = sanitize_text_field( wp_unslash( $_GET['npq_resultat'] ) );
            $message = '<div class="notice notice-success"><p>' . esc_html( $r ) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>NormaPrep Quiz</h1>
            <?php echo $message; ?>
            <h2>Importer le contenu</h2>
            <p>
                Importe les scénarios et questions depuis le fichier
                <code>data/question_bank.json</code> du plugin.
                L'opération peut être relancée sans créer de doublon.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="npq_importer">
                <?php wp_nonce_field( 'npq_importer_action', 'npq_nonce' ); ?>
                <p>
                    <button type="submit" class="button button-primary">
                        Importer le contenu
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Traite le clic sur le bouton : lit le JSON et remplit les tables.
     */
    public static function traiter_import() {
        // Sécurité : vérifier le droit et le jeton anti-CSRF.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        check_admin_referer( 'npq_importer_action', 'npq_nonce' );

        $chemin = NPQ_PATH . 'data/question_bank.json';
        if ( ! file_exists( $chemin ) ) {
            self::rediriger( 'Fichier question_bank.json introuvable dans le dossier data/.' );
        }

        $json = file_get_contents( $chemin );
        $data = json_decode( $json, true );
        if ( ! $data || empty( $data['questions'] ) ) {
            self::rediriger( 'Le fichier JSON est vide ou illisible.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // La certification cible de cet import. À terme, ce couple code/nom
        // pourra provenir du fichier JSON lui-même pour gérer plusieurs certifs.
        $cert_code = 'LI27001';
        $cert_nom  = 'ISO/IEC 27001 Lead Implementer';
        $certification_id = self::obtenir_ou_creer_certification( $cert_code, $cert_nom );

        // On mémorise la correspondance « référence externe » -> « id en base »
        // pour rattacher ensuite les questions à leurs scénarios.
        $map_scenarios = [];
        $nb_scenarios = 0;
        $nb_questions = 0;

        /* ---- 1. Scénarios ---- */
        foreach ( $data['scenarios'] as $index => $s ) {
            // Nouvelle référence au format multi-certif : LI27001-SC-0001
            $ref = sprintf( '%s-SC-%04d', $cert_code, $index + 1 );

            $existant = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}scenario WHERE ref_externe = %s", $ref
            ) );

            $donnees = [
                'certification_id' => $certification_id,
                'ref_externe'      => $ref,
                'nom'              => $s['name'],
                'resume'           => isset( $s['summary'] ) ? $s['summary'] : '',
                'contexte'         => $s['context'],
            ];

            if ( $existant ) {
                $wpdb->update( "{$p}scenario", $donnees, [ 'id' => $existant ] );
                // On garde la correspondance avec l'ancien id JSON (SC0, SC1...)
                $map_scenarios[ $s['id'] ] = $existant;
            } else {
                $wpdb->insert( "{$p}scenario", $donnees );
                $map_scenarios[ $s['id'] ] = $wpdb->insert_id;
                $nb_scenarios++;
            }
        }

        /* ---- 2. Questions, options et tags ---- */
        foreach ( $data['questions'] as $index => $q ) {
            // Nouvelle référence au format multi-certif : LI27001-Q-0001
            $ref = sprintf( '%s-Q-%04d', $cert_code, $index + 1 );

            $scenario_id = isset( $map_scenarios[ $q['scenario_id'] ] )
                ? $map_scenarios[ $q['scenario_id'] ]
                : null;

            // multi_reponses : le JSON actuel est à réponse unique, mais on gère
            // le cas où plusieurs options seraient marquées correctes.
            $nb_correctes = 0;
            foreach ( $q['options'] as $i => $opt ) {
                if ( $i === $q['answer_index'] ) {
                    $nb_correctes++;
                }
            }
            $multi = ( $nb_correctes > 1 ) ? 1 : 0;

            // Enregistre le domaine (code + libellé) s'il n'existe pas encore.
            // Le libellé vient du champ domain_label de la banque de questions.
            if ( ! empty( $q['domain'] ) && ! empty( $q['domain_label'] ) ) {
                self::assurer_domaine( $certification_id, $q['domain'], $q['domain_label'] );
            }

            $donnees = [
                'certification_id' => $certification_id,
                'ref_externe'      => $ref,
                'scenario_id'      => $scenario_id,
                'domaine'          => $q['domain'],
                'enonce'           => $q['question'],
                'multi_reponses'   => $multi,
                'explication'      => isset( $q['explanation'] ) ? $q['explanation'] : '',
                'difficulte'       => isset( $q['difficulty'] ) ? $q['difficulty'] : 'hard',
            ];

            $existante = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}question WHERE ref_externe = %s", $ref
            ) );

            if ( $existante ) {
                $wpdb->update( "{$p}question", $donnees, [ 'id' => $existante ] );
                $question_id = $existante;
                // On efface les options existantes pour les réinsérer proprement.
                $wpdb->delete( "{$p}option_reponse", [ 'question_id' => $question_id ] );
                $wpdb->delete( "{$p}question_tag", [ 'question_id' => $question_id ] );
            } else {
                $wpdb->insert( "{$p}question", $donnees );
                $question_id = $wpdb->insert_id;
                $nb_questions++;
            }

            // Options de réponse.
            foreach ( $q['options'] as $i => $opt ) {
                $wpdb->insert( "{$p}option_reponse", [
                    'question_id' => $question_id,
                    'texte'       => $opt['text'],
                    'correcte'    => ( $i === $q['answer_index'] ) ? 1 : 0,
                    'position'    => $i,
                ] );
            }

            // Tags : chaque famille du JSON devient un tag_type.
            if ( ! empty( $q['tags'] ) ) {
                foreach ( $q['tags'] as $type_nom => $valeurs ) {
                    // skill_type est une chaîne unique ; les autres sont des tableaux.
                    $liste = is_array( $valeurs ) ? $valeurs : [ $valeurs ];
                    foreach ( $liste as $valeur ) {
                        if ( $valeur === '' || $valeur === null ) {
                            continue;
                        }
                        $tag_id = self::obtenir_ou_creer_tag( $type_nom, (string) $valeur );
                        // Liaison question <-> tag (ignore si déjà présente).
                        $wpdb->query( $wpdb->prepare(
                            "INSERT IGNORE INTO {$p}question_tag (question_id, tag_id) VALUES (%d, %d)",
                            $question_id, $tag_id
                        ) );
                    }
                }
            }
        }

        self::rediriger( sprintf(
            'Import terminé : %d scénario(s) et %d question(s) ajouté(s). Les éléments déjà présents ont été mis à jour.',
            $nb_scenarios, $nb_questions
        ) );
    }

    /**
     * Récupère l'id d'une certification (par son code), en la créant si besoin.
     */
    private static function obtenir_ou_creer_certification( $code, $nom ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}certification WHERE code = %s", $code
        ) );
        if ( ! $id ) {
            $wpdb->insert( "{$p}certification", [ 'code' => $code, 'nom' => $nom ] );
            $id = $wpdb->insert_id;
        }
        return $id;
    }

    /**
     * Récupère l'id d'un tag (type + valeur), en le créant si nécessaire.
     * Crée aussi le type de tag s'il n'existe pas encore.
     */
    private static function obtenir_ou_creer_tag( $type_nom, $valeur ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Type de tag.
        $type_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tag_type WHERE nom = %s", $type_nom
        ) );
        if ( ! $type_id ) {
            $wpdb->insert( "{$p}tag_type", [ 'nom' => $type_nom ] );
            $type_id = $wpdb->insert_id;
        }

        // Tag.
        $tag_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tag WHERE tag_type_id = %d AND valeur = %s",
            $type_id, $valeur
        ) );
        if ( ! $tag_id ) {
            $wpdb->insert( "{$p}tag", [ 'tag_type_id' => $type_id, 'valeur' => $valeur ] );
            $tag_id = $wpdb->insert_id;
        }

        return $tag_id;
    }

    /**
     * Crée le domaine s'il n'existe pas, ou met à jour son libellé.
     * Rejouable sans doublon (clé unique certification + code).
     */
    private static function assurer_domaine( $certification_id, $code, $libelle ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $existant = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}domaine WHERE certification_id = %d AND code = %s",
            $certification_id,
            $code
        ) );

        if ( $existant ) {
            $wpdb->update( "{$p}domaine", [ 'libelle' => $libelle ], [ 'id' => $existant ] );
        } else {
            $wpdb->insert( "{$p}domaine", [
                'certification_id' => $certification_id,
                'code'             => $code,
                'libelle'          => $libelle,
            ] );
        }
    }

    /**
     * Redirige vers la page d'admin avec un message de résultat.
     */
    private static function rediriger( $message ) {
        wp_safe_redirect( add_query_arg(
            'npq_resultat',
            rawurlencode( $message ),
            admin_url( 'admin.php?page=normaprep-quiz' )
        ) );
        exit;
    }
}
