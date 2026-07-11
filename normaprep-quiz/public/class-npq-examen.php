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

        // Point d'entrée AJAX (navigation fluide sans rechargement).
        // Réservé aux utilisateurs connectés (préfixe wp_ajax_, pas nopriv).
        add_action( 'wp_ajax_npq_examen_etape', [ __CLASS__, 'ajax_etape' ] );

        // Chargement du script d'examen sur la page dédiée.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_script' ] );
    }

    /**
     * Charge le JavaScript d'examen, uniquement sur la page « Passer un examen ».
     */
    public static function charger_script() {
        $page_id = get_option( self::OPT_PAGE_EXAMEN );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }
        wp_enqueue_script(
            'npq-examen',
            NPQ_URL . 'assets/npq-examen.js',
            [],
            NPQ_VERSION,
            true // dans le pied de page
        );
        // Transmet au script l'URL AJAX et un jeton de sécurité.
        wp_localize_script( 'npq-examen', 'NPQ_EXAMEN', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'npq_examen_ajax' ),
        ] );
    }

    /**
     * Point d'entrée AJAX : enregistre la réponse courante, puis va où on lui demande.
     *
     * Le navigateur envoie :
     *   - position   : la question qu'on quitte (pour enregistrer sa réponse)
     *   - options    : les options cochées à cette position
     *   - marquee    : 1 si la question courante est marquée « à revoir »
     *   - destination: la position cible, ou 'terminer' pour finir l'examen
     *
     * Ne divulgue JAMAIS les bonnes réponses.
     */
    public static function ajax_etape() {
        // Sécurité : connexion, abonnement, nonce.
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        if ( ! check_ajax_referer( 'npq_examen_ajax', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Session expirée.' ], 403 );
        }

        $tentative_id = isset( $_POST['tentative'] ) ? (int) $_POST['tentative'] : 0;
        $position     = isset( $_POST['position'] ) ? (int) $_POST['position'] : 0;
        $options      = isset( $_POST['options'] ) ? array_map( 'intval', (array) $_POST['options'] ) : [];
        $marquee      = ! empty( $_POST['marquee'] );
        $destination  = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';

        if ( ! $tentative_id || ! self::tentative_appartient( $tentative_id ) ) {
            wp_send_json_error( [ 'message' => 'Examen introuvable.' ], 404 );
        }

        $total = self::nombre_questions( $tentative_id );

        // Enregistre la réponse et le marquage de la question qu'on quitte.
        $question_courante = self::question_a_position( $tentative_id, $position );
        if ( $question_courante ) {
            self::enregistrer_brouillon( $tentative_id, (int) $question_courante['id'], $options, $marquee );
        }

        // Fin de l'examen demandée : on corrige et on renvoie l'URL du résultat.
        if ( $destination === 'terminer' ) {
            $brouillon = self::lire_brouillon( $tentative_id );
            NPQ_Correcteur::corriger_tentative( $tentative_id, $brouillon['reponses'] );
            self::effacer_brouillon( $tentative_id );

            $page_id = get_option( self::OPT_PAGE_EXAMEN );
            $url = $page_id ? get_permalink( $page_id ) : home_url( '/' );
            wp_send_json_success( [
                'termine'      => true,
                'url_resultat' => add_query_arg( [ 't' => $tentative_id, 'resultat' => 1 ], $url ),
            ] );
        }

        // Sinon : on va à la position demandée (par défaut, la suivante).
        $cible = ( $destination !== '' && is_numeric( $destination ) )
               ? (int) $destination
               : $position + 1;

        // Garde-fous : on reste dans les bornes.
        if ( $cible < 0 ) { $cible = 0; }
        if ( $cible >= $total ) { $cible = $total - 1; }

        wp_send_json_success( self::donnees_etape( $tentative_id, $cible, $total ) );
    }

    /**
     * Prépare les données d'une étape pour le navigateur.
     * Contient la question, son état, et la vue d'ensemble de l'examen.
     * Ne contient JAMAIS les bonnes réponses.
     */
    private static function donnees_etape( $tentative_id, $position, $total ) {
        $q = self::question_a_position( $tentative_id, $position );
        if ( ! $q ) {
            return [ 'termine' => false, 'position' => $position, 'total' => $total, 'question' => null ];
        }

        $brouillon = self::lire_brouillon( $tentative_id );
        $qid  = (int) $q['id'];
        $deja = isset( $brouillon['reponses'][ $qid ] ) ? array_map( 'intval', (array) $brouillon['reponses'][ $qid ] ) : [];
        $marquee = ! empty( $brouillon['marquees'][ $qid ] );

        return [
            'termine'  => false,
            'position' => $position,
            'total'    => $total,
            'question' => [
                'enonce'         => $q['enonce'],
                'multi_reponses' => (int) $q['multi_reponses'],
                'options'        => array_map( function ( $o ) {
                    return [ 'id' => (int) $o['id'], 'texte' => $o['texte'] ];
                }, $q['options'] ),
                'deja'    => $deja,
                'marquee' => $marquee,
            ],
            // Vue d'ensemble : état de chaque question (répondue / marquée).
            'apercu'   => self::apercu_questions( $tentative_id ),
        ];
    }

    /**
     * Construit l'état de toutes les questions de la tentative, pour la vue d'ensemble.
     * Renvoie un tableau indexé par position : ['repondue' => bool, 'marquee' => bool].
     */
    private static function apercu_questions( $tentative_id ) {
        $ids = self::ids_questions( $tentative_id );
        $brouillon = self::lire_brouillon( $tentative_id );

        $apercu = [];
        foreach ( $ids as $pos => $qid ) {
            $qid = (int) $qid;
            $rep = isset( $brouillon['reponses'][ $qid ] ) ? (array) $brouillon['reponses'][ $qid ] : [];
            $apercu[] = [
                'repondue' => ! empty( $rep ),
                'marquee'  => ! empty( $brouillon['marquees'][ $qid ] ),
            ];
        }
        return $apercu;
    }

    /* ---- Brouillon : réponses + questions marquées « à revoir » ---- */

    /**
     * Lit le brouillon de la tentative.
     * Structure : [ 'reponses' => [question_id => [option_ids]], 'marquees' => [question_id => true] ]
     * Gère aussi l'ancien format (tableau simple de réponses) pour ne rien casser.
     */
    private static function lire_brouillon( $tentative_id ) {
        $brut = get_option( 'npq_brouillon_' . $tentative_id, [] );

        // Nouveau format.
        if ( isset( $brut['reponses'] ) || isset( $brut['marquees'] ) ) {
            return [
                'reponses' => isset( $brut['reponses'] ) ? (array) $brut['reponses'] : [],
                'marquees' => isset( $brut['marquees'] ) ? (array) $brut['marquees'] : [],
            ];
        }

        // Ancien format (tableau de réponses seules) : on le convertit à la volée.
        return [
            'reponses' => (array) $brut,
            'marquees' => [],
        ];
    }

    private static function enregistrer_brouillon( $tentative_id, $question_id, $options, $marquee ) {
        $brouillon = self::lire_brouillon( $tentative_id );
        $brouillon['reponses'][ $question_id ] = $options;

        if ( $marquee ) {
            $brouillon['marquees'][ $question_id ] = true;
        } else {
            unset( $brouillon['marquees'][ $question_id ] );
        }

        update_option( 'npq_brouillon_' . $tentative_id, $brouillon, false );
    }

    private static function effacer_brouillon( $tentative_id ) {
        delete_option( 'npq_brouillon_' . $tentative_id );
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

        // Enregistre la réponse et le marquage de la question quittée.
        $marquee  = ! empty( $_POST['npq_marquee'] );
        $question = self::question_a_position( $tentative_id, $position );
        if ( $question ) {
            self::enregistrer_brouillon( $tentative_id, (int) $question['id'], $options, $marquee );
        }

        $total = self::nombre_questions( $tentative_id );

        // Destination demandée (navigation libre : avant, arrière, saut, ou terminer).
        // Sans JS, elle vient du bouton cliqué ; avec JS, du champ caché.
        $destination = '';
        if ( isset( $_POST['npq_destination'] ) && $_POST['npq_destination'] !== '' ) {
            $destination = sanitize_text_field( wp_unslash( $_POST['npq_destination'] ) );
        } elseif ( isset( $_POST['npq_destination_btn'] ) ) {
            $destination = sanitize_text_field( wp_unslash( $_POST['npq_destination_btn'] ) );
        }

        if ( $destination === 'terminer' ) {
            self::finaliser( $tentative_id );
            return;
        }

        $cible = ( $destination !== '' && is_numeric( $destination ) )
               ? (int) $destination
               : $position + 1;

        if ( $cible < 0 ) { $cible = 0; }
        if ( $cible >= $total ) { $cible = $total - 1; }

        self::rediriger_vers( $tentative_id, $cible );
    }

    /**
     * Finalise l'examen : corrige les réponses du brouillon, enregistre le
     * résultat via le correcteur, nettoie le brouillon, et redirige vers le résultat.
     */
    private static function finaliser( $tentative_id ) {
        $brouillon = self::lire_brouillon( $tentative_id );

        // Le correcteur enregistre les réponses, calcule le score et la réussite.
        NPQ_Correcteur::corriger_tentative( $tentative_id, $brouillon['reponses'] );

        // Le brouillon n'est plus utile.
        self::effacer_brouillon( $tentative_id );

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
                <div class="npq-scenario-carte">
                    <h3><?php echo esc_html( $s['nom'] ); ?></h3>
                    <?php if ( $s['resume'] ) : ?>
                        <p class="npq-sc-resume"><?php echo esc_html( $s['resume'] ); ?></p>
                    <?php endif; ?>
                    <p class="npq-sc-nb"><?php echo (int) $nb; ?> question(s)</p>
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

        $total    = self::nombre_questions( $tentative_id );
        $scenario = self::scenario_de_tentative( $tentative_id );

        // État de la question courante (réponse déjà donnée, marquage).
        $brouillon = self::lire_brouillon( $tentative_id );
        $qid     = (int) $question['id'];
        $deja    = isset( $brouillon['reponses'][ $qid ] ) ? (array) $brouillon['reponses'][ $qid ] : [];
        $marquee = ! empty( $brouillon['marquees'][ $qid ] );

        // Vue d'ensemble : état de toutes les questions.
        $apercu = self::apercu_questions( $tentative_id );

        $type_input = $question['multi_reponses'] ? 'checkbox' : 'radio';
        $est_derniere = ( $position + 1 >= $total );

        ob_start();
        ?>
        <div class="npq-examen" id="npq-examen-zone">

            <?php if ( $scenario ) : ?>
                <div class="npq-scenario-contexte">
                    <strong><?php echo esc_html( $scenario['nom'] ); ?></strong>
                    <p><?php echo esc_html( $scenario['contexte'] ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Vue d'ensemble : pastilles cliquables (répondue / marquée / courante) -->
            <div class="npq-apercu" id="npq-apercu">
                <?php foreach ( $apercu as $i => $etat ) :
                    $classes = 'npq-pastille';
                    if ( $i === (int) $position )  { $classes .= ' courante'; }
                    if ( ! empty( $etat['repondue'] ) ) { $classes .= ' repondue'; }
                    if ( ! empty( $etat['marquee'] ) )  { $classes .= ' marquee'; }
                ?>
                    <button type="button" class="<?php echo esc_attr( $classes ); ?>"
                            data-pos="<?php echo (int) $i; ?>"
                            title="Question <?php echo ( $i + 1 ); ?><?php echo ! empty( $etat['marquee'] ) ? ' (à revoir)' : ''; ?>">
                        <?php echo ( $i + 1 ); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div id="npq-question-contenu">
                <p class="npq-progression">Question <?php echo ( $position + 1 ); ?> / <?php echo $total; ?></p>

                <div class="npq-enonce"><?php echo esc_html( $question['enonce'] ); ?></div>

                <form id="npq-examen-form" method="post">
                    <input type="hidden" name="npq_examen_action" value="repondre">
                    <input type="hidden" name="npq_tentative" value="<?php echo (int) $tentative_id; ?>">
                    <input type="hidden" name="npq_position" value="<?php echo (int) $position; ?>">
                    <!-- Destination : rempli par le JS (ou par les boutons en repli sans JS) -->
                    <input type="hidden" name="npq_destination" id="npq-destination" value="">
                    <?php wp_nonce_field( 'npq_examen', 'npq_nonce' ); ?>

                    <?php foreach ( $question['options'] as $opt ) :
                        $checked = in_array( (int) $opt['id'], array_map( 'intval', $deja ), true ) ? 'checked' : '';
                    ?>
                        <label class="npq-option">
                            <input type="<?php echo $type_input; ?>" name="npq_options[]"
                                   value="<?php echo (int) $opt['id']; ?>" <?php echo $checked; ?>>
                            <?php echo esc_html( $opt['texte'] ); ?>
                        </label>
                    <?php endforeach; ?>

                    <!-- Marquer « à revoir » -->
                    <label class="npq-marquer">
                        <input type="checkbox" name="npq_marquee" id="npq-marquee" value="1" <?php checked( $marquee ); ?>>
                        Marquer cette question pour y revenir
                    </label>

                    <!-- Navigation : précédent / suivant / terminer -->
                    <div class="npq-nav">
                        <?php if ( $position > 0 ) : ?>
                            <button type="submit" class="npq-btn npq-btn-ghost"
                                    name="npq_destination_btn" value="<?php echo (int) ( $position - 1 ); ?>"
                                    data-dest="<?php echo (int) ( $position - 1 ); ?>">
                                Question précédente
                            </button>
                        <?php endif; ?>

                        <?php if ( ! $est_derniere ) : ?>
                            <button type="submit" class="npq-btn"
                                    name="npq_destination_btn" value="<?php echo (int) ( $position + 1 ); ?>"
                                    data-dest="<?php echo (int) ( $position + 1 ); ?>">
                                Question suivante
                            </button>
                        <?php endif; ?>

                        <button type="submit" class="npq-btn npq-btn-terminer"
                                name="npq_destination_btn" value="terminer" data-dest="terminer">
                            Terminer l'examen
                        </button>
                    </div>
                </form>
            </div>
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

        // Libellés lisibles des domaines (D1 -> « Planification du SMSI »).
        $libelles = self::libelles_domaines( array_keys( $par_domaine ) );

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
                    $nom_dom = isset( $libelles[ $dom ] ) ? $libelles[ $dom ] : $dom;
                ?>
                    <div style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:4px">
                            <span><?php echo esc_html( $nom_dom ); ?></span>
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

    /**
     * Récupère les libellés lisibles des domaines depuis la base.
     * Renvoie un tableau code => libellé (ex : 'D3' => 'Planification du SMSI').
     * Si un domaine n'a pas de libellé, il n'est pas dans le tableau (on affichera le code).
     */
    private static function libelles_domaines( $codes ) {
        if ( empty( $codes ) ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine WHERE code IN ( $placeholders )",
            $codes
        ), ARRAY_A );

        $map = [];
        foreach ( (array) $lignes as $l ) {
            $map[ $l['code'] ] = $l['libelle'];
        }
        return $map;
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
