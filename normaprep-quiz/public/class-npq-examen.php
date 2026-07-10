<?php
/**
 * Déroulé d'un examen : orchestration du parcours question par question.
 *
 * Fonctionnement (navigation par rechargement de page) :
 *   1. L'abonné choisit un scénario -> on compose l'examen et on crée une tentative.
 *   2. Chaque question s'affiche seule ; la réponse est enregistrée à la validation.
 *   3. À la dernière question, la soumission déclenche la correction (étape suivante).
 *
 * L'état de l'examen (quelle tentative, quelle position) voyage dans l'URL et
 * s'appuie sur la base de données : la tentative et ses réponses y sont stockées.
 *
 * Accès réservé aux abonnés actifs (barrière NPQ_Comptes::peut_passer_examen_complet).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Examen {

    const OPT_PAGE_EXAMEN = 'npq_page_examen_id';

    public static function init() {
        add_shortcode( 'npq_examen', [ __CLASS__, 'rendu' ] );
        add_action( 'template_redirect', [ __CLASS__, 'traiter_actions' ] );
    }

    /**
     * Crée la page « Passer un examen » à l'activation.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_EXAMEN );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Passer un examen',
            'post_name'    => 'examen',
            'post_content' => '[npq_examen]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_EXAMEN, $page_id );
        }
    }

    /* =====================================================================
     * TRAITEMENT DES ACTIONS (avant affichage, pour pouvoir rediriger)
     * ===================================================================== */

    public static function traiter_actions() {
        if ( empty( $_POST['npq_examen_action'] ) ) {
            return;
        }
        // Barrière d'accès : abonnés actifs uniquement.
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            return;
        }
        // Sécurité : nonce.
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_examen' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['npq_examen_action'] );

        if ( $action === 'demarrer' ) {
            self::demarrer();
        } elseif ( $action === 'repondre' ) {
            self::enregistrer_reponse();
        }
    }

    /**
     * Démarre un examen : compose les questions d'un scénario et crée la tentative.
     */
    private static function demarrer() {
        $scenario_id = isset( $_POST['npq_scenario'] ) ? (int) $_POST['npq_scenario'] : 0;
        if ( ! $scenario_id ) {
            return;
        }

        $questions = NPQ_Composeur::par_scenario( $scenario_id, false );
        if ( empty( $questions ) ) {
            return;
        }

        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Crée la tentative. On mémorise la liste ordonnée des questions dans
        // le champ criteres (JSON), pour savoir quoi afficher à chaque position.
        $ids = array_map( function ( $q ) { return (int) $q['id']; }, $questions );

        $wpdb->insert( "{$p}tentative", [
            'utilisateur_id'   => $fiche['id'],
            'examen_modele_id' => null,
            'mode'             => 'libre',
            'criteres'         => wp_json_encode( [
                'type'      => 'scenario',
                'scenario'  => $scenario_id,
                'questions' => $ids,
            ] ),
            'date_debut'       => current_time( 'mysql' ),
        ] );
        $tentative_id = $wpdb->insert_id;

        // Redirige vers la première question.
        self::rediriger_vers( $tentative_id, 0 );
    }

    /**
     * Enregistre la réponse à la question courante, puis avance.
     * (À ce stade on stocke la réponse « en attente » dans une option temporaire ;
     *  la correction définitive se fera à la soumission finale, étape suivante.)
     */
    private static function enregistrer_reponse() {
        $tentative_id = isset( $_POST['npq_tentative'] ) ? (int) $_POST['npq_tentative'] : 0;
        $position     = isset( $_POST['npq_position'] ) ? (int) $_POST['npq_position'] : 0;
        $options      = isset( $_POST['npq_options'] ) ? array_map( 'intval', (array) $_POST['npq_options'] ) : [];

        if ( ! $tentative_id ) {
            return;
        }

        // Vérifie que la tentative appartient bien à l'utilisateur courant.
        if ( ! self::tentative_appartient( $tentative_id ) ) {
            return;
        }

        // Stocke la réponse en cours dans une métadonnée de la tentative.
        // On utilise une option dédiée « brouillon » indexée par tentative.
        $brouillon = get_option( 'npq_brouillon_' . $tentative_id, [] );
        $question  = self::question_a_position( $tentative_id, $position );
        if ( $question ) {
            $brouillon[ $question['id'] ] = $options;
            update_option( 'npq_brouillon_' . $tentative_id, $brouillon, false );
        }

        // Position suivante.
        $total = self::nombre_questions( $tentative_id );
        $suivante = $position + 1;

        if ( $suivante >= $total ) {
            // Dernière question répondue : on corrige et on finalise la tentative.
            self::finaliser( $tentative_id );
        } else {
            self::rediriger_vers( $tentative_id, $suivante );
        }
    }

    /**
     * Finalise l'examen : corrige les réponses du brouillon, enregistre le
     * résultat via le correcteur, nettoie le brouillon, et redirige vers le résultat.
     */
    private static function finaliser( $tentative_id ) {
        $brouillon = get_option( 'npq_brouillon_' . $tentative_id, [] );

        // Le correcteur enregistre les réponses, calcule le score et la réussite.
        NPQ_Correcteur::corriger_tentative( $tentative_id, $brouillon );

        // Le brouillon n'est plus utile.
        delete_option( 'npq_brouillon_' . $tentative_id );

        // Redirige vers l'écran de résultat.
        $page_id = get_option( self::OPT_PAGE_EXAMEN );
        $url = $page_id ? get_permalink( $page_id ) : home_url( '/' );
        wp_safe_redirect( add_query_arg( [ 't' => $tentative_id, 'resultat' => 1 ], $url ) );
        exit;
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function rendu() {
        // Barrière d'accès.
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            return '<p>L\'accès aux examens est réservé aux abonnés. '
                 . '<a href="' . esc_url( self::url_offres() ) . '">Découvrir les offres</a>.</p>';
        }

        $tentative_id = isset( $_GET['t'] ) ? (int) $_GET['t'] : 0;
        $position     = isset( $_GET['q'] ) ? (int) $_GET['q'] : 0;
        $resultat     = isset( $_GET['resultat'] );

        // Aucun examen en cours -> écran de choix.
        if ( ! $tentative_id ) {
            return self::ecran_choix();
        }
        if ( ! self::tentative_appartient( $tentative_id ) ) {
            return '<p>Examen introuvable.</p>';
        }
        if ( $resultat ) {
            return self::ecran_resultat( $tentative_id );
        }

        return self::ecran_question( $tentative_id, $position );
    }

    /**
     * Écran de choix : liste des scénarios disponibles.
     */
    private static function ecran_choix() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $scenarios = $wpdb->get_results(
            "SELECT id, nom, resume FROM {$p}scenario WHERE statut = 'publie' ORDER BY nom ASC",
            ARRAY_A
        );

        if ( empty( $scenarios ) ) {
            return '<p>Aucun scénario disponible pour le moment.</p>';
        }

        ob_start();
        ?>
        <div class="npq-examen-choix">
            <h2>Choisissez un scénario</h2>
            <?php foreach ( $scenarios as $s ) :
                $nb = NPQ_Composeur::compter( 'scenario', [ 'scenario_id' => $s['id'] ] );
            ?>
                <div class="npq-scenario-carte" style="border:1px solid #1E3A52;border-radius:8px;padding:16px;margin-bottom:12px">
                    <h3 style="margin:0 0 6px"><?php echo esc_html( $s['nom'] ); ?></h3>
                    <?php if ( $s['resume'] ) : ?>
                        <p style="margin:0 0 12px;color:#94A3B8"><?php echo esc_html( $s['resume'] ); ?></p>
                    <?php endif; ?>
                    <p style="margin:0 0 12px;font-size:13px"><?php echo (int) $nb; ?> question(s)</p>
                    <form method="post">
                        <input type="hidden" name="npq_examen_action" value="demarrer">
                        <input type="hidden" name="npq_scenario" value="<?php echo (int) $s['id']; ?>">
                        <?php wp_nonce_field( 'npq_examen', 'npq_nonce' ); ?>
                        <button type="submit" class="npq-btn">Commencer</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Écran d'une question (une seule à la fois).
     */
    private static function ecran_question( $tentative_id, $position ) {
        $question = self::question_a_position( $tentative_id, $position );
        if ( ! $question ) {
            return '<p>Question introuvable.</p>';
        }

        $total = self::nombre_questions( $tentative_id );
        $scenario = self::scenario_de_tentative( $tentative_id );

        // Réponse déjà donnée (si le candidat revient en arrière).
        $brouillon = get_option( 'npq_brouillon_' . $tentative_id, [] );
        $deja = isset( $brouillon[ $question['id'] ] ) ? (array) $brouillon[ $question['id'] ] : [];

        $type_input = $question['multi_reponses'] ? 'checkbox' : 'radio';

        ob_start();
        ?>
        <div class="npq-examen">
            <?php if ( $scenario ) : ?>
                <div class="npq-scenario-contexte" style="background:#0F1E33;border-left:3px solid #00CFCF;padding:14px 18px;margin-bottom:20px">
                    <strong><?php echo esc_html( $scenario['nom'] ); ?></strong>
                    <p style="margin:8px 0 0;color:#CBD5E1;font-size:14px"><?php echo esc_html( $scenario['contexte'] ); ?></p>
                </div>
            <?php endif; ?>

            <p style="color:#94A3B8;font-size:13px">Question <?php echo ( $position + 1 ); ?> / <?php echo $total; ?></p>

            <div class="npq-enonce" style="font-size:17px;margin-bottom:20px">
                <?php echo esc_html( $question['enonce'] ); ?>
            </div>

            <form method="post">
                <input type="hidden" name="npq_examen_action" value="repondre">
                <input type="hidden" name="npq_tentative" value="<?php echo (int) $tentative_id; ?>">
                <input type="hidden" name="npq_position" value="<?php echo (int) $position; ?>">
                <?php wp_nonce_field( 'npq_examen', 'npq_nonce' ); ?>

                <?php foreach ( $question['options'] as $opt ) :
                    $checked = in_array( (int) $opt['id'], array_map( 'intval', $deja ), true ) ? 'checked' : '';
                ?>
                    <label style="display:block;padding:12px;border:1px solid #1E3A52;border-radius:6px;margin-bottom:10px;cursor:pointer">
                        <input type="<?php echo $type_input; ?>" name="npq_options[]"
                               value="<?php echo (int) $opt['id']; ?>" <?php echo $checked; ?>>
                        <?php echo esc_html( $opt['texte'] ); ?>
                    </label>
                <?php endforeach; ?>

                <p style="margin-top:20px">
                    <button type="submit" class="npq-btn">
                        <?php echo ( $position + 1 >= $total ) ? 'Terminer l\'examen' : 'Question suivante'; ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Écran de résultat : score, réussite, score par domaine, correction détaillée.
     */
    private static function ecran_resultat( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Résultat global de la tentative.
        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT score, reussi FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ), ARRAY_A );

        if ( ! $t || $t['score'] === null ) {
            return '<p>Résultat indisponible.</p>';
        }

        $score  = (int) $t['score'];
        $reussi = (bool) $t['reussi'];

        // Correction détaillée (via le correcteur).
        $detail = NPQ_Correcteur::detail_correction( $tentative_id );

        // Score par domaine, recalculé pour l'affichage.
        $par_domaine = [];
        foreach ( $detail as $d ) {
            $dom = $d['question']['domaine'];
            if ( ! isset( $par_domaine[ $dom ] ) ) {
                $par_domaine[ $dom ] = [ 'ok' => 0, 'total' => 0 ];
            }
            $par_domaine[ $dom ]['total']++;
            if ( $d['correcte'] ) {
                $par_domaine[ $dom ]['ok']++;
            }
        }

        $url_espace = self::url_espace();

        ob_start();
        ?>
        <div class="npq-resultat">
            <h2>Votre résultat</h2>

            <div style="text-align:center;padding:24px;border-radius:12px;margin-bottom:24px;
                        background:<?php echo $reussi ? 'rgba(30,132,73,0.15)' : 'rgba(192,57,43,0.15)'; ?>">
                <div style="font-size:48px;font-weight:700;color:<?php echo $reussi ? '#1e8449' : '#c0392b'; ?>">
                    <?php echo $score; ?> %
                </div>
                <div style="font-size:18px;margin-top:8px;color:<?php echo $reussi ? '#1e8449' : '#c0392b'; ?>">
                    <?php echo $reussi ? 'Réussi' : 'Échoué'; ?>
                </div>
            </div>

            <h3>Score par domaine</h3>
            <div style="margin-bottom:28px">
                <?php foreach ( $par_domaine as $dom => $stats ) :
                    $pct = $stats['total'] > 0 ? (int) round( $stats['ok'] * 100 / $stats['total'] ) : 0;
                ?>
                    <div style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px">
                            <span><?php echo esc_html( $dom ); ?></span>
                            <span><?php echo $pct; ?> % (<?php echo (int) $stats['ok']; ?>/<?php echo (int) $stats['total']; ?>)</span>
                        </div>
                        <div style="height:8px;background:#1E3A52;border-radius:4px;overflow:hidden">
                            <div style="height:100%;width:<?php echo $pct; ?>%;background:#00CFCF"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3>Correction détaillée</h3>
            <?php foreach ( $detail as $i => $d ) : ?>
                <div style="border:1px solid #1E3A52;border-radius:8px;padding:16px;margin-bottom:16px">
                    <p style="font-weight:600;margin:0 0 12px">
                        <?php echo ( $i + 1 ) . '. ' . esc_html( $d['question']['enonce'] ); ?>
                        <span style="float:right;color:<?php echo $d['correcte'] ? '#1e8449' : '#c0392b'; ?>">
                            <?php echo $d['correcte'] ? '✓ Correct' : '✗ Incorrect'; ?>
                        </span>
                    </p>
                    <?php foreach ( $d['options'] as $opt ) :
                        $est_bonne = (int) $opt['correcte'] === 1;
                        $est_cochee = ! empty( $opt['cochee'] );
                        // Couleur : verte si bonne réponse, rouge si cochée à tort.
                        $bg = '';
                        if ( $est_bonne ) {
                            $bg = 'background:rgba(30,132,73,0.15)';
                        } elseif ( $est_cochee ) {
                            $bg = 'background:rgba(192,57,43,0.15)';
                        }
                        $marque = '';
                        if ( $est_bonne )  { $marque = ' ✓'; }
                        if ( $est_cochee && ! $est_bonne ) { $marque = ' ✗ (votre choix)'; }
                        if ( $est_cochee && $est_bonne )   { $marque = ' ✓ (votre choix)'; }
                    ?>
                        <div style="padding:8px 12px;border-radius:4px;margin-bottom:6px;font-size:14px;<?php echo $bg; ?>">
                            <?php echo esc_html( $opt['texte'] ) . $marque; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ( ! empty( $d['question']['explication'] ) ) : ?>
                        <p style="margin:12px 0 0;padding:12px;background:#0F1E33;border-radius:6px;font-size:14px;color:#CBD5E1">
                            <strong>Explication :</strong> <?php echo esc_html( $d['question']['explication'] ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <p style="margin-top:24px">
                <a href="<?php echo esc_url( $url_espace ); ?>" class="npq-btn">Retour à mon espace</a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * OUTILS INTERNES
     * ===================================================================== */

    private static function rediriger_vers( $tentative_id, $position ) {
        $page_id = get_option( self::OPT_PAGE_EXAMEN );
        $url = $page_id ? get_permalink( $page_id ) : home_url( '/' );
        $args = [ 't' => $tentative_id, 'q' => $position ];
        wp_safe_redirect( add_query_arg( $args, $url ) );
        exit;
    }

    /** La tentative appartient-elle à l'utilisateur connecté ? */
    private static function tentative_appartient( $tentative_id ) {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return false;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $proprio = $wpdb->get_var( $wpdb->prepare(
            "SELECT utilisateur_id FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );
        return (int) $proprio === (int) $fiche['id'];
    }

    /** Liste des ids de questions mémorisée dans la tentative. */
    private static function ids_questions( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $criteres = $wpdb->get_var( $wpdb->prepare(
            "SELECT criteres FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );
        $data = json_decode( (string) $criteres, true );
        return isset( $data['questions'] ) ? array_map( 'intval', $data['questions'] ) : [];
    }

    private static function nombre_questions( $tentative_id ) {
        return count( self::ids_questions( $tentative_id ) );
    }

    /** Charge la question complète à une position donnée. */
    private static function question_a_position( $tentative_id, $position ) {
        $ids = self::ids_questions( $tentative_id );
        if ( ! isset( $ids[ $position ] ) ) {
            return null;
        }
        $qid = $ids[ $position ];

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $question = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, scenario_id, domaine, enonce, multi_reponses
             FROM {$p}question WHERE id = %d",
            $qid
        ), ARRAY_A );
        if ( ! $question ) {
            return null;
        }
        // Options SANS l'info « correcte » (jamais envoyée au navigateur).
        $question['options'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, texte, position FROM {$p}option_reponse
             WHERE question_id = %d ORDER BY position ASC",
            $qid
        ), ARRAY_A );

        return $question;
    }

    private static function scenario_de_tentative( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $criteres = $wpdb->get_var( $wpdb->prepare(
            "SELECT criteres FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );
        $data = json_decode( (string) $criteres, true );
        if ( empty( $data['scenario'] ) ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, contexte FROM {$p}scenario WHERE id = %d",
            (int) $data['scenario']
        ), ARRAY_A );
    }

    private static function url_offres() {
        $page = get_page_by_path( 'offres' );
        return $page ? get_permalink( $page ) : home_url( '/' );
    }

    private static function url_espace() {
        $page_id = get_option( 'npq_page_espace_id' );
        return $page_id ? get_permalink( $page_id ) : home_url( '/' );
    }
}
