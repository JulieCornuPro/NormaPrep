<?php
/**
 * Espace abonné NormaPrep : tableau de bord et cloisonnement vis-à-vis de WordPress.
 *
 * - Masque la barre d'administration WordPress pour les abonnés.
 * - Empêche les abonnés d'accéder à /wp-admin (redirection vers leur espace).
 * - Crée la page « Mon espace » à l'activation.
 * - Affiche le tableau de bord via le shortcode [npq_espace].
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Espace {

    const OPT_PAGE_ESPACE = 'npq_page_espace_id';

    /**
     * Branchements au chargement du plugin.
     */
    public static function init() {
        add_shortcode( 'npq_espace', [ __CLASS__, 'rendu_espace' ] );

        // Feuille de style de l'espace membre.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_styles' ] );

        // Enregistre le template de page dédié fourni par le plugin.
        add_filter( 'theme_page_templates', [ __CLASS__, 'declarer_template' ] );
        add_filter( 'template_include', [ __CLASS__, 'charger_template' ] );

        // Cloisonnement : ces réglages ne concernent QUE les abonnés NormaPrep,
        // jamais les administrateurs.
        add_action( 'after_setup_theme', [ __CLASS__, 'masquer_barre_admin' ] );
        add_action( 'admin_init', [ __CLASS__, 'bloquer_acces_admin' ] );
    }

    /** Déclare le template dans la liste des modèles de page disponibles. */
    public static function declarer_template( $templates ) {
        $templates['npq-espace'] = 'Espace membre NormaPrep';
        return $templates;
    }

    /** Charge notre template pour la page « Mon espace » (ou si le modèle est choisi). */
    public static function charger_template( $template ) {
        $page_id = get_option( self::OPT_PAGE_ESPACE );
        $est_espace = ( $page_id && is_page( $page_id ) );
        $modele_choisi = is_page() && get_page_template_slug() === 'npq-espace';

        if ( $est_espace || $modele_choisi ) {
            $fichier = NPQ_PATH . 'public/page-espace-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }
        return $template;
    }

    /**
     * Charge la feuille de style de l'espace, sur la page « Mon espace ».
     */
    public static function charger_styles() {
        $page_id = get_option( self::OPT_PAGE_ESPACE );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }
        wp_enqueue_style(
            'npq-espace',
            NPQ_URL . 'assets/npq-espace.css',
            [],
            NPQ_VERSION
        );
    }

    /**
     * Génère le bloc « compte » à afficher dans l'en-tête du site.
     * - Visiteur non connecté : lien « Connexion ».
     * - Abonné connecté : liens « Mon espace » et « Se déconnecter ».
     *
     * Le thème n'a qu'à appeler NPQ_Espace::bloc_compte() dans son header.
     * Toute la logique reste ici, dans le plugin.
     *
     * @return string HTML du bloc.
     */
    public static function bloc_compte() {
        if ( is_user_logged_in() ) {
            $page_id    = get_option( self::OPT_PAGE_ESPACE );
            $url_espace = $page_id ? get_permalink( $page_id ) : home_url( '/' );
            $url_deco   = wp_logout_url( home_url( '/' ) );

            return '<div class="npq-compte npq-compte--connecte">'
                 . '<a href="' . esc_url( $url_espace ) . '" class="npq-compte-lien">Mon espace</a>'
                 . '<a href="' . esc_url( $url_deco ) . '" class="npq-compte-lien npq-compte-deco">Se déconnecter</a>'
                 . '</div>';
        }

        $page_connexion = get_option( NPQ_Auth::OPT_PAGE_CONNEXION );
        $url_connexion  = $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' );

        return '<div class="npq-compte npq-compte--visiteur">'
             . '<a href="' . esc_url( $url_connexion ) . '" class="npq-compte-lien">Connexion</a>'
             . '</div>';
    }

    /**
     * Crée la page « Mon espace » à l'activation, si absente.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_ESPACE );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Mon espace',
            'post_name'    => 'mon-espace',
            'post_content' => '[npq_espace]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_ESPACE, $page_id );
        }
    }

    /* =====================================================================
     * CLOISONNEMENT
     * ===================================================================== */

    /**
     * Masque la barre d'administration WordPress pour les abonnés.
     * Les administrateurs la gardent.
     */
    public static function masquer_barre_admin() {
        if ( self::est_abonne_simple() ) {
            show_admin_bar( false );
        }
    }

    /**
     * Empêche un abonné d'accéder au tableau de bord WordPress (/wp-admin).
     * Il est redirigé vers son espace NormaPrep.
     * On laisse passer les requêtes AJAX (utilisées légitimement côté public).
     */
    public static function bloquer_acces_admin() {
        if ( self::est_abonne_simple() && ! wp_doing_ajax() ) {
            $page_id = get_option( self::OPT_PAGE_ESPACE );
            wp_safe_redirect( $page_id ? get_permalink( $page_id ) : home_url( '/' ) );
            exit;
        }
    }

    /**
     * Vrai si l'utilisateur connecté est un abonné NormaPrep (et rien d'autre).
     * Un administrateur qui aurait aussi ce rôle n'est pas concerné.
     */
    private static function est_abonne_simple() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        // Concerné uniquement si le SEUL rôle est abonné NormaPrep.
        return $roles === [ NPQ_Comptes::ROLE ];
    }

    /* =====================================================================
     * TABLEAU DE BORD
     * ===================================================================== */

    public static function rendu_espace() {
        if ( ! is_user_logged_in() ) {
            $page_connexion = get_option( NPQ_Auth::OPT_PAGE_CONNEXION );
            $url = $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' );
            return '<p>Vous devez être connecté(e) pour accéder à votre espace. '
                 . '<a href="' . esc_url( $url ) . '">Se connecter</a></p>';
        }

        $user   = wp_get_current_user();
        $nom    = $user->display_name ? $user->display_name : $user->user_email;
        $abonne = NPQ_Comptes::est_abonne_actif();

        // Initiales pour l'avatar (2 premières lettres significatives).
        $initiales = strtoupper( mb_substr( preg_replace( '/[^A-Za-z0-9]/', '', $nom ), 0, 2 ) );
        if ( $initiales === '' ) {
            $initiales = 'NP';
        }

        // URLs des pages (seulement celles qui existent).
        $url_examen = ( $id = get_option( 'npq_page_examen_id' ) ) ? get_permalink( $id ) : '#';
        $url_profil = ( $id = get_option( 'npq_page_profil_id' ) ) ? get_permalink( $id ) : '';
        $page_offres = get_page_by_path( 'offres' );
        $url_offres  = $page_offres ? get_permalink( $page_offres ) : '#';

        $historique_html = self::rendu_historique();

        ob_start();
        ?>
        <div class="npq-espace-shell">

            <!-- Barre latérale -->
            <aside class="npq-sidebar">
                <div class="npq-side-user">
                    <div class="npq-avatar"><?php echo esc_html( $initiales ); ?></div>
                    <div>
                        <div class="npq-su-name"><?php echo esc_html( $nom ); ?></div>
                        <div class="npq-su-status<?php echo $abonne ? '' : ' inactif'; ?>">
                            ● <?php echo $abonne ? 'Abonnement actif' : 'Compte gratuit'; ?>
                        </div>
                    </div>
                </div>

                <div class="npq-side-group">Navigation</div>
                <a class="npq-side-link active" href="#">Tableau de bord</a>
                <?php if ( $abonne ) : ?>
                    <a class="npq-side-link" href="<?php echo esc_url( $url_examen ); ?>">Passer un examen</a>
                <?php endif; ?>

                <div class="npq-side-group">Compte</div>
                <?php if ( $url_profil ) : ?>
                    <a class="npq-side-link" href="<?php echo esc_url( $url_profil ); ?>">Mon profil</a>
                <?php endif; ?>
            </aside>

            <!-- Contenu principal -->
            <main class="npq-espace-main">
                <div class="npq-greet">Bonjour <?php echo esc_html( $nom ); ?></div>
                <div class="npq-status-line">
                    Statut :
                    <span class="val<?php echo $abonne ? '' : ' inactif'; ?>">
                        <?php echo $abonne ? 'Abonnement actif' : 'Compte gratuit'; ?>
                    </span>
                </div>

                <div class="npq-cta-row">
                    <?php if ( $abonne ) : ?>
                        <a href="<?php echo esc_url( $url_examen ); ?>" class="npq-btn">Lancer un examen</a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( $url_offres ); ?>" class="npq-btn">Découvrir les offres</a>
                    <?php endif; ?>
                </div>

                <div class="npq-sec-title">Mes examens</div>
                <?php echo $historique_html; ?>

                <?php if ( $url_profil ) : ?>
                    <div class="npq-quick-links">
                        <a href="<?php echo esc_url( $url_profil ); ?>">Gérer mon profil</a>
                    </div>
                <?php endif; ?>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Affiche l'historique des tentatives de l'abonné (vide pour l'instant).
     */
    private static function rendu_historique() {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return '<p>Aucun examen passé pour le moment.</p>';
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $tentatives = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, mode, score, reussi, date_debut, date_fin
             FROM {$p}tentative
             WHERE utilisateur_id = %d AND date_fin IS NOT NULL
             ORDER BY date_debut DESC
             LIMIT 20",
            $fiche['id']
        ), ARRAY_A );

        if ( empty( $tentatives ) ) {
            return '<p style="color:#8B98B3">Aucun examen passé pour le moment.</p>';
        }

        $html  = '<table class="npq-table">';
        $html .= '<thead><tr><th>Date</th><th>Score</th><th>Résultat</th></tr></thead><tbody>';
        foreach ( $tentatives as $t ) {
            $date  = esc_html( mysql2date( 'd/m/Y', $t['date_debut'] ) );
            $score = ( $t['score'] !== null ) ? intval( $t['score'] ) . ' %' : '—';
            $res   = $t['reussi'] ? 'Réussi' : 'Échoué';
            $classe = $t['reussi'] ? 'npq-result-ok' : 'npq-result-ko';
            $html .= '<tr>'
                   . '<td>' . $date . '</td>'
                   . '<td>' . $score . '</td>'
                   . '<td class="' . $classe . '">' . $res . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}
