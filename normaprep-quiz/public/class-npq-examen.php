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

    /**
     * Règles officielles de l'examen PECB ISO/IEC 27001 Lead Implementer.
     * Source : guide de préparation PECB (tableau de pondération des domaines).
     */

    /** Nombre de questions par domaine, tel que défini par PECB (total : 80). */
    const PONDERATION_PECB = [
        'D1' => 15,  // Principes et concepts fondamentaux du SMSI
        'D2' => 12,  // Système de management de la sécurité de l'information
        'D3' => 18,  // Planification de la mise en œuvre du SMSI
        'D4' => 14,  // Mise en œuvre du SMSI
        'D5' => 10,  // Surveillance et mesure du SMSI
        'D6' => 6,   // Amélioration continue
        'D7' => 5,   // Préparation à l'audit de certification
    ];

    /** Durée de l'examen, en minutes (PECB : 180 minutes pour 80 questions). */
    const DUREE_MINUTES = 180;

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
     * Charge le JavaScript d'examen, sur la page « Passer un examen » ET sur la
     * page « Révisions » (le déroulé y est le même, en mode révision).
     */
    public static function charger_script() {
        $page_examen   = get_option( self::OPT_PAGE_EXAMEN );
        $page_revision = get_option( 'npq_page_revision_id' );

        $sur_deroule = ( $page_examen && is_page( $page_examen ) )
                    || ( $page_revision && is_page( $page_revision ) );

        if ( ! $sur_deroule ) {
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

        // Examen déjà clos : on ne le rouvre pas.
        if ( self::est_terminee( $tentative_id ) ) {
            wp_send_json_error( [ 'message' => 'Cet examen est déjà terminé.' ], 409 );
        }

        $total = self::nombre_questions( $tentative_id );
        $mode  = self::mode_tentative( $tentative_id );

        // Enregistre la réponse et le marquage de la question qu'on quitte.
        $question_courante = self::question_a_position( $tentative_id, $position );
        if ( $question_courante ) {
            self::enregistrer_brouillon( $tentative_id, (int) $question_courante['id'], $options, $marquee );
        }

        // TEMPS ÉCOULÉ : le serveur fait foi. On corrige d'office, quelle que soit
        // la demande du navigateur. Les questions sans réponse comptent fausses.
        if ( self::temps_ecoule( $tentative_id ) ) {
            $brouillon = self::lire_brouillon( $tentative_id );
            NPQ_Correcteur::corriger_tentative( $tentative_id, $brouillon['reponses'] );
            self::effacer_brouillon( $tentative_id );

            $url = self::url_deroule( $tentative_id );
            wp_send_json_success( [
                'termine'      => true,
                'expire'       => true,
                'url_resultat' => add_query_arg( [ 't' => $tentative_id, 'resultat' => 1 ], $url ),
            ] );
        }

        // Fin de l'examen demandée par le candidat : on corrige.
        if ( $destination === 'terminer' ) {
            $brouillon = self::lire_brouillon( $tentative_id );
            NPQ_Correcteur::corriger_tentative( $tentative_id, $brouillon['reponses'] );
            self::effacer_brouillon( $tentative_id );

            $url = self::url_deroule( $tentative_id );
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

        $donnees = self::donnees_etape( $tentative_id, $cible, $total );
        $donnees['mode'] = $mode;

        // En RÉVISION seulement : on renvoie la correction de la question QU'ON VIENT
        // DE QUITTER (le candidat y a déjà répondu, donc plus rien à protéger).
        // Jamais celle de la question à venir : les bonnes réponses ne doivent jamais
        // partir avant que le candidat ait répondu.
        if ( $mode === 'revision' && $question_courante && ! empty( $options ) ) {
            $donnees['correction'] = self::correction_question(
                (int) $question_courante['id'],
                $options
            );
        }

        wp_send_json_success( $donnees );
    }

    /**
     * Correction d'une seule question, pour le retour immédiat en mode révision.
     * N'est appelée QU'APRÈS que le candidat a répondu à cette question.
     *
     * @param int   $question_id
     * @param array $choisies Ids des options cochées par le candidat.
     * @return array Correction (bonne réponse, choix, explication, verdict).
     */
    private static function correction_question( $question_id, $choisies ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $options = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, texte, correcte FROM {$p}option_reponse
             WHERE question_id = %d ORDER BY position ASC",
            $question_id
        ), ARRAY_A );

        $explication = $wpdb->get_var( $wpdb->prepare(
            "SELECT explication FROM {$p}question WHERE id = %d",
            $question_id
        ) );

        $choisies = array_map( 'intval', (array) $choisies );

        // Verdict : tout ou rien (règle du produit).
        $attendues = [];
        foreach ( $options as $o ) {
            if ( (int) $o['correcte'] === 1 ) {
                $attendues[] = (int) $o['id'];
            }
        }
        sort( $attendues );
        $donnees_triees = $choisies;
        sort( $donnees_triees );
        $correcte = ( $attendues === $donnees_triees );

        $liste = [];
        foreach ( $options as $o ) {
            $liste[] = [
                'texte'    => $o['texte'],
                'correcte' => ( (int) $o['correcte'] === 1 ),
                'choisie'  => in_array( (int) $o['id'], $choisies, true ),
            ];
        }

        return [
            'correcte'    => $correcte,
            'options'     => $liste,
            'explication' => (string) $explication,
        ];
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

        // Le scénario de CETTE question (un examen en mélange plusieurs).
        $scenario = self::scenario_de_question( $q['scenario_id'] ?? 0 );

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
            'scenario' => $scenario ? [
                'id'       => (int) $scenario['id'],
                'nom'      => $scenario['nom'],
                'resume'   => (string) $scenario['resume'],
                'contexte' => (string) $scenario['contexte'],
            ] : null,
            // Temps restant (null si non chronométré). Renvoyé à chaque étape :
            // le compte à rebours du navigateur se resynchronise sur le serveur.
            'restant'  => self::temps_restant( $tentative_id ),
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
    /**
     * Démarre un examen blanc : compose 80 questions selon la pondération PECB,
     * tirées au hasard dans chaque domaine, et crée la tentative chronométrée.
     *
     * Il n'y a plus de choix de scénario : un examen est aléatoire, comme le vrai.
     */
    private static function demarrer() {
        $certification_id = self::certification_courante();
        if ( ! $certification_id ) {
            return;
        }

        $questions = NPQ_Composeur::par_ponderation( $certification_id, self::PONDERATION_PECB );
        if ( empty( $questions ) ) {
            return;
        }

        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = array_map( function ( $q ) { return (int) $q['id']; }, $questions );

        // L'heure de fin est calculée dès le départ : c'est elle qui fait foi.
        // Même si le candidat ferme son navigateur, le temps continue de courir.
        //
        // On utilise time() (UTC absolu), PAS current_time('timestamp') : cette
        // dernière applique un décalage de fuseau horaire, ce qui fausse un
        // chronomètre (un décalage de +3h le ferait expirer instantanément).
        // Un compteur de secondes ne doit dépendre d'aucun fuseau.
        $debut = time();
        $fin   = $debut + ( self::DUREE_MINUTES * 60 );

        $wpdb->insert( "{$p}tentative", [
            'utilisateur_id'   => $fiche['id'],
            'examen_modele_id' => null,
            'mode'             => 'examen',
            'criteres'         => wp_json_encode( [
                'type'        => 'examen_pecb',
                'questions'   => $ids,
                'duree'       => self::DUREE_MINUTES,
                'expire_le'   => $fin,   // horodatage de fin (fait foi)
                'ponderation' => self::PONDERATION_PECB,
            ] ),
            'date_debut'       => current_time( 'mysql' ),
        ] );
        $tentative_id = $wpdb->insert_id;

        self::rediriger_vers( $tentative_id, 0 );
    }

    /** Certification active (la première publiée). */
    private static function certification_courante() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        return (int) $wpdb->get_var(
            "SELECT id FROM {$p}certification WHERE actif = 1 ORDER BY id ASC LIMIT 1"
        );
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

        // Redirige vers l'écran de résultat (page examen ou révision selon le mode).
        $url = self::url_deroule( $tentative_id );
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

        // Sécurité : la tentative doit appartenir à l'utilisateur connecté.
        // (Empêche d'accéder à l'examen d'un autre en changeant l'id dans l'URL.)
        if ( ! self::tentative_appartient( $tentative_id ) ) {
            return '<p class="empty">Examen introuvable.</p>';
        }

        if ( $resultat ) {
            return self::ecran_resultat( $tentative_id );
        }

        // Un examen TERMINÉ ne se rouvre pas : on renvoie vers son résultat.
        // (Sinon, le bouton « retour » du navigateur relançait le déroulé d'un
        //  examen déjà corrigé.)
        if ( self::est_terminee( $tentative_id ) ) {
            return self::ecran_resultat( $tentative_id );
        }

        return self::ecran_question( $tentative_id, $position );
    }

    /**
     * Écran d'accueil de l'examen : les règles, puis le bouton de démarrage.
     *
     * Le candidat doit savoir dans quoi il s'engage AVANT de commencer :
     * le chronomètre tourne, quitter l'examen l'abandonne.
     */
    private static function ecran_choix() {
        $certification_id = self::certification_courante();
        if ( ! $certification_id ) {
            return '<p class="empty">Aucune certification disponible.</p>';
        }

        // Vérifie que la banque permet de composer l'examen.
        $verif = NPQ_Composeur::verifier_ponderation( $certification_id, self::PONDERATION_PECB );
        if ( ! $verif['possible'] ) {
            return '<p class="empty">Les questions ne sont pas encore disponibles.</p>';
        }

        $nb_questions = (int) $verif['total'];
        $duree        = self::DUREE_MINUTES;
        $seuil        = (int) get_option( 'npq_seuil_reussite', 70 );

        ob_start();
        ?>
        <div class="npq-examen-accueil">
            <h2>Examen blanc</h2>
            <p class="npq-exam-intro">
                Une simulation dans les conditions du véritable examen PECB
                ISO/IEC 27001 Lead Implementer.
            </p>

            <!-- Les conditions de l'épreuve -->
            <div class="npq-exam-conditions">
                <div class="npq-exam-cond">
                    <span class="npq-cond-val"><?php echo $nb_questions; ?></span>
                    <span class="npq-cond-lbl">Questions</span>
                </div>
                <div class="npq-exam-cond">
                    <span class="npq-cond-val"><?php echo (int) ( $duree / 60 ); ?><span class="u">h</span></span>
                    <span class="npq-cond-lbl">Durée</span>
                </div>
                <div class="npq-exam-cond">
                    <span class="npq-cond-val"><?php echo $seuil; ?><span class="u">%</span></span>
                    <span class="npq-cond-lbl">Seuil de réussite</span>
                </div>
            </div>

            <!-- L'avertissement : ce qu'il faut savoir avant de se lancer -->
            <div class="npq-exam-regles">
                <h3>Avant de commencer</h3>
                <ul>
                    <li>
                        <strong>Le chronomètre démarre immédiatement</strong> et ne s'arrête pas.
                        Vous disposez de <?php echo $duree; ?> minutes.
                    </li>
                    <li>
                        <strong>Si vous quittez l'examen</strong> (bouton « Quitter », fermeture
                        de l'onglet ou du navigateur), la tentative est <strong>abandonnée</strong> :
                        elle n'aura pas de score et apparaîtra comme telle dans votre historique.
                    </li>
                    <li>
                        <strong>À l'expiration du temps</strong>, votre copie est remise
                        automatiquement. Les questions sans réponse sont comptées fausses.
                    </li>
                    <li>
                        Vous pouvez <strong>naviguer librement</strong> entre les questions,
                        marquer celles qui vous font douter, et <strong>terminer avant la fin</strong>
                        si vous le souhaitez.
                    </li>
                    <li>
                        Les questions sont <strong>tirées au hasard</strong> selon la répartition
                        officielle des domaines de compétence.
                    </li>
                </ul>
            </div>

            <form method="post" class="npq-exam-lancer"
                  onsubmit="return confirm('Le chronomètre va démarrer. Prêt(e) à commencer ?');">
                <input type="hidden" name="npq_examen_action" value="demarrer">
                <?php wp_nonce_field( 'npq_examen', 'npq_nonce' ); ?>
                <button type="submit" class="npq-btn">Démarrer l'examen</button>
            </form>
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
        $mode  = self::mode_tentative( $tentative_id );

        // Le scénario de CETTE question (un examen en mélange plusieurs).
        $scenario = self::scenario_de_question( $question['scenario_id'] ?? 0 );

        // État de la question courante.
        $brouillon = self::lire_brouillon( $tentative_id );
        $qid     = (int) $question['id'];
        $deja    = isset( $brouillon['reponses'][ $qid ] ) ? (array) $brouillon['reponses'][ $qid ] : [];
        $marquee = ! empty( $brouillon['marquees'][ $qid ] );

        // Vue d'ensemble.
        $apercu = self::apercu_questions( $tentative_id );

        // Progression : combien de répondues, combien de marquées.
        $nb_repondues = 0;
        $nb_marquees  = 0;
        foreach ( $apercu as $e ) {
            if ( ! empty( $e['repondue'] ) ) { $nb_repondues++; }
            if ( ! empty( $e['marquee'] ) )  { $nb_marquees++; }
        }

        $type_input   = $question['multi_reponses'] ? 'checkbox' : 'radio';
        $est_derniere = ( $position + 1 >= $total );

        ob_start();
        ?>
        <div class="npq-examen npq-examen-deux-col" id="npq-examen-zone">

            <!-- Colonne principale : scénario + question -->
            <div class="npq-col-principale">

                <?php if ( $scenario ) : ?>
                    <!-- Scénario repliable : le candidat le lit une fois, puis le replie.
                         Sans lui, l'énoncé serait incompréhensible. -->
                    <div class="npq-scenario-box" id="npq-scenario-box">
                        <div class="npq-scen-titre">&#11041; <?php echo esc_html( $scenario['resume'] ? $scenario['resume'] : $scenario['nom'] ); ?></div>
                        <div class="npq-scen-corps" id="npq-scen-corps"><?php echo esc_html( $scenario['contexte'] ); ?></div>
                        <span class="npq-scen-bascule" id="npq-scen-bascule">[ + Lire le scénario ]</span>
                    </div>
                <?php endif; ?>

                <div id="npq-question-contenu">
                    <p class="npq-progression">Question <?php echo ( $position + 1 ); ?> / <?php echo $total; ?></p>

                    <div class="npq-enonce"><?php echo esc_html( $question['enonce'] ); ?></div>

                    <form id="npq-examen-form" method="post">
                        <input type="hidden" name="npq_examen_action" value="repondre">
                        <input type="hidden" name="npq_tentative" value="<?php echo (int) $tentative_id; ?>">
                        <input type="hidden" name="npq_position" value="<?php echo (int) $position; ?>">
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

                        <label class="npq-marquer">
                            <input type="checkbox" name="npq_marquee" id="npq-marquee" value="1" <?php checked( $marquee ); ?>>
                            Marquer cette question pour y revenir
                        </label>

                        <div class="npq-nav">
                            <?php if ( $position > 0 ) : ?>
                                <button type="submit" class="npq-btn npq-btn-ghost"
                                        name="npq_destination_btn" value="<?php echo (int) ( $position - 1 ); ?>"
                                        data-dest="<?php echo (int) ( $position - 1 ); ?>">
                                    Précédente
                                </button>
                            <?php endif; ?>

                            <?php if ( ! $est_derniere ) : ?>
                                <button type="submit" class="npq-btn"
                                        name="npq_destination_btn" value="<?php echo (int) ( $position + 1 ); ?>"
                                        data-dest="<?php echo (int) ( $position + 1 ); ?>">
                                    Suivante
                                </button>
                            <?php else : ?>
                                <!-- Dernière question : bouton explicite dans le flux. -->
                                <button type="submit" class="npq-btn npq-btn-fin"
                                        name="npq_destination_btn" value="terminer"
                                        data-dest="terminer">
                                    Terminer et voir mon résultat
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Colonne droite : suivi de l'épreuve -->
            <aside class="npq-col-suivi">

                <?php
                $restant = self::temps_restant( $tentative_id );
                if ( $restant !== null ) :
                ?>
                    <!-- Chronomètre. Le serveur fait foi : cette valeur est
                         resynchronisée à chaque étape. -->
                    <div class="npq-chrono-box" id="npq-chrono-box"
                         data-restant="<?php echo (int) $restant; ?>">
                        <div class="npq-chrono-lbl">Temps restant</div>
                        <div class="npq-chrono-val" id="npq-chrono-val">
                            <?php
                            $h = floor( $restant / 3600 );
                            $m = floor( ( $restant % 3600 ) / 60 );
                            $s = $restant % 60;
                            printf( '%02d:%02d:%02d', $h, $m, $s );
                            ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="npq-chrono-box" id="npq-chrono-box"></div>
                <?php endif; ?>

                <!-- Progression -->
                <div class="npq-suivi-box">
                    <div class="npq-suivi-titre">Progression</div>
                    <div class="npq-suivi-ligne">
                        <span class="npq-suivi-lbl">Répondues</span>
                        <span class="npq-suivi-val" id="npq-nb-repondues"><?php echo (int) $nb_repondues; ?> / <?php echo (int) $total; ?></span>
                    </div>
                    <div class="npq-suivi-ligne">
                        <span class="npq-suivi-lbl">À revoir</span>
                        <span class="npq-suivi-val marquee" id="npq-nb-marquees"><?php echo (int) $nb_marquees; ?></span>
                    </div>
                    <div class="npq-barre-progression">
                        <div class="npq-barre-remplie" id="npq-barre-remplie"
                             style="width:<?php echo $total > 0 ? (int) round( $nb_repondues * 100 / $total ) : 0; ?>%"></div>
                    </div>
                </div>

                <!-- Vue d'ensemble : pastilles cliquables -->
                <div class="npq-suivi-box">
                    <div class="npq-suivi-titre">Questions</div>
                    <div class="npq-apercu" id="npq-apercu">
                        <?php foreach ( $apercu as $i => $etat ) :
                            $classes = 'npq-pastille';
                            if ( $i === (int) $position )       { $classes .= ' courante'; }
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
                </div>

                <!-- Terminer -->
                <button type="button" class="npq-btn npq-btn-terminer" data-dest="terminer">
                    <?php echo ( $mode === 'revision' ) ? 'Terminer la révision' : "Terminer l'examen"; ?>
                </button>
            </aside>
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
        $url = self::url_deroule( $tentative_id );
        $args = [ 't' => $tentative_id, 'q' => $position ];
        wp_safe_redirect( add_query_arg( $args, $url ) );
        exit;
    }

    /**
     * Page où se déroule la tentative, selon son mode.
     * Une révision se déroule sur la page Révisions, un examen sur la page Examen :
     * le candidat reste dans son contexte (barre latérale, URL cohérentes).
     */
    private static function url_deroule( $tentative_id ) {
        $mode = self::mode_tentative( $tentative_id );

        if ( $mode === 'revision' ) {
            $page_id = get_option( 'npq_page_revision_id' );
        } else {
            $page_id = get_option( self::OPT_PAGE_EXAMEN );
        }

        return $page_id ? get_permalink( $page_id ) : home_url( '/' );
    }

    /**
     * Temps restant d'une tentative chronométrée, en secondes.
     *
     * C'est LE SERVEUR qui fait foi, jamais le navigateur : l'heure de fin est
     * enregistrée en base au démarrage. Trafiquer l'horloge du navigateur ou le
     * JavaScript ne donne pas une seconde de plus.
     *
     * @return int|null Secondes restantes, 0 si expiré, null si non chronométré.
     */
    private static function temps_restant( $tentative_id ) {
        $criteres = self::criteres( $tentative_id );

        if ( empty( $criteres['duree'] ) ) {
            return null; // pas de chronomètre (révision)
        }

        // On recalcule depuis la DATE DE DÉBUT en base, qui est la source fiable.
        // (Le champ expire_le des anciennes tentatives a pu être faussé par un
        //  décalage de fuseau horaire ; on ne s'y fie plus.)
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $date_debut = $wpdb->get_var( $wpdb->prepare(
            "SELECT date_debut FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );

        if ( ! $date_debut ) {
            return null;
        }

        // get_gmt_from_date convertit la date locale WordPress en UTC, puis
        // strtotime la ramène en secondes. Même référence des deux côtés.
        $debut_ts = strtotime( get_gmt_from_date( $date_debut ) . ' UTC' );
        if ( ! $debut_ts ) {
            return null;
        }

        $duree_s = (int) $criteres['duree'] * 60;
        $restant = ( $debut_ts + $duree_s ) - time();

        return max( 0, $restant );
    }

    /** La tentative est-elle chronométrée et son temps est-il écoulé ? */
    private static function temps_ecoule( $tentative_id ) {
        $restant = self::temps_restant( $tentative_id );
        return ( $restant !== null && $restant <= 0 );
    }

    /** Critères de la tentative (JSON décodé). */
    private static function criteres( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $brut = $wpdb->get_var( $wpdb->prepare(
            "SELECT criteres FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );

        $data = json_decode( (string) $brut, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * La tentative est-elle déjà terminée (corrigée) ?
     * Sert à ne pas rouvrir un examen clos.
     */
    private static function est_terminee( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $fin = $wpdb->get_var( $wpdb->prepare(
            "SELECT date_fin FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );

        return ! empty( $fin );
    }

    /** Mode de la tentative : 'examen' (simulation) ou 'revision' (entraînement). */
    private static function mode_tentative( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $mode = $wpdb->get_var( $wpdb->prepare(
            "SELECT mode FROM {$p}tentative WHERE id = %d",
            $tentative_id
        ) );
        return ( $mode === 'revision' ) ? 'revision' : 'examen';
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

    /**
     * Scénario d'une question donnée.
     *
     * Un examen mélange des questions de plusieurs scénarios : chaque question
     * porte donc le sien. Sans lui, l'énoncé serait incompréhensible (il parle
     * d'entreprises que le candidat ne connaîtrait pas).
     */
    private static function scenario_de_question( $scenario_id ) {
        if ( ! $scenario_id ) {
            return null;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, resume, contexte FROM {$p}scenario WHERE id = %d",
            (int) $scenario_id
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
