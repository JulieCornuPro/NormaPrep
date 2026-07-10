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

        // Cloisonnement : ces réglages ne concernent QUE les abonnés NormaPrep,
        // jamais les administrateurs.
        add_action( 'after_setup_theme', [ __CLASS__, 'masquer_barre_admin' ] );
        add_action( 'admin_init', [ __CLASS__, 'bloquer_acces_admin' ] );
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

        $user     = wp_get_current_user();
        $prenom   = $user->display_name ? $user->display_name : $user->user_email;
        $abonne   = NPQ_Comptes::est_abonne_actif();
        $deco_url = wp_logout_url( home_url( '/' ) );

        // Statut d'abonnement affiché.
        $statut_html = $abonne
            ? '<span style="color:#1e8449;font-weight:600">Abonnement actif</span>'
            : '<span style="color:#b9770e;font-weight:600">Compte gratuit</span>';

        // Historique des tentatives (vide au départ).
        $historique_html = self::rendu_historique();

        ob_start();
        ?>
        <div class="npq-espace">
            <h2>Bonjour <?php echo esc_html( $prenom ); ?></h2>
            <p>Statut : <?php echo $statut_html; ?></p>

            <div class="npq-espace-actions" style="margin:24px 0">
                <?php if ( $abonne ) : ?>
                    <?php
                    $page_examen = get_option( 'npq_page_examen_id' );
                    $url_examen = $page_examen ? get_permalink( $page_examen ) : '#';
                    ?>
                    <a href="<?php echo esc_url( $url_examen ); ?>" class="npq-btn">Lancer un examen</a>
                <?php else : ?>
                    <?php
                    $page_offres = get_page_by_path( 'offres' );
                    $url_offres = $page_offres ? get_permalink( $page_offres ) : '#';
                    ?>
                    <a href="<?php echo esc_url( $url_offres ); ?>" class="npq-btn">Découvrir les offres</a>
                <?php endif; ?>
            </div>

            <h3>Mes examens passés</h3>
            <?php echo $historique_html; ?>

            <p style="margin-top:32px">
                <a href="<?php echo esc_url( $deco_url ); ?>">Se déconnecter</a>
            </p>
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
            return '<p>Aucun examen passé pour le moment.</p>';
        }

        $html = '<table class="npq-historique" style="width:100%;border-collapse:collapse">';
        $html .= '<tr style="text-align:left;border-bottom:1px solid #ccc">'
               . '<th style="padding:8px">Date</th><th style="padding:8px">Score</th>'
               . '<th style="padding:8px">Résultat</th></tr>';
        foreach ( $tentatives as $t ) {
            $date = esc_html( mysql2date( 'd/m/Y', $t['date_debut'] ) );
            $score = ( $t['score'] !== null ) ? intval( $t['score'] ) . ' %' : '—';
            $res = $t['reussi'] ? 'Réussi' : 'Échoué';
            $couleur = $t['reussi'] ? '#1e8449' : '#c0392b';
            $html .= '<tr style="border-bottom:1px solid #eee">'
                   . '<td style="padding:8px">' . $date . '</td>'
                   . '<td style="padding:8px">' . $score . '</td>'
                   . '<td style="padding:8px;color:' . $couleur . '">' . $res . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }
}
