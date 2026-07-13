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

    /**
     * Message affiché sur mobile à la place de l'examen ou des révisions.
     *
     * Personne ne passe un examen de 80 questions en 3 heures sur un téléphone.
     * Plutôt que d'optimiser pour un usage qui n'existe pas, on l'invite à passer
     * sur ordinateur — c'est plus honnête que de le laisser peiner.
     *
     * (Le CSS ne l'affiche que sous 900px ; au-dessus, il est masqué.)
     *
     * @param string $quoi 'examen' ou 'révision', pour adapter le texte.
     * @return string HTML du message.
     */
    public static function message_mobile( $quoi = 'examen' ) {
        $page_espace = get_option( self::OPT_PAGE_ESPACE );
        $url_espace  = $page_espace ? get_permalink( $page_espace ) : home_url( '/' );

        $article = ( $quoi === 'révision' ) ? 'une révision' : 'un examen';

        ob_start();
        ?>
        <div class="npq-mobile-requis">
            <h2>Un ordinateur est nécessaire</h2>
            <p>
                Passer <?php echo esc_html( $article ); ?> demande de la concentration,
                de la lecture attentive et du temps. L'écran d'un téléphone n'est pas
                adapté à cet exercice.
            </p>
            <p>
                Retrouvez-nous sur ordinateur pour vous entraîner dans de bonnes conditions.
            </p>
            <p>
                Vous pouvez en revanche consulter votre tableau de bord et votre
                progression depuis votre mobile.
            </p>
            <a href="<?php echo esc_url( $url_espace ); ?>" class="npq-btn">
                Retour à mon espace
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Déclare le template dans la liste des modèles de page disponibles. */
    public static function declarer_template( $templates ) {
        $templates['npq-espace'] = 'Espace membre NormaPrep';
        return $templates;
    }

    /**
     * Génère la barre latérale de l'espace membre.
     * Source unique : utilisée par toutes les pages de l'espace (tableau de bord,
     * profil, et futures sections). Évite de dupliquer le code.
     *
     * @param string $active Clé de l'entrée à surligner : 'dashboard', 'examens', 'profil'.
     * @return string HTML de la barre latérale.
     */
    public static function barre_laterale( $active = '' ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user   = wp_get_current_user();
        $nom    = $user->display_name ? $user->display_name : $user->user_email;
        $abonne = NPQ_Comptes::est_abonne_actif();

        $initiales = strtoupper( mb_substr( preg_replace( '/[^A-Za-z0-9]/', '', $nom ), 0, 2 ) );
        if ( $initiales === '' ) { $initiales = 'NP'; }

        $url_espace = ( $id = get_option( self::OPT_PAGE_ESPACE ) ) ? get_permalink( $id ) : home_url( '/' );
        $url_examen = ( $id = get_option( 'npq_page_examen_id' ) ) ? get_permalink( $id ) : '#';
        $url_profil = ( $id = get_option( 'npq_page_profil_id' ) ) ? get_permalink( $id ) : '#';
        $url_revision = ( $id = get_option( 'npq_page_revision_id' ) ) ? get_permalink( $id ) : '#';
        $url_activite = ( $id = get_option( 'npq_page_activite_id' ) ) ? get_permalink( $id ) : '#';

        // Nombre d'EXAMENS passés (badge). Les révisions ne comptent pas :
        // ce sont des entraînements, pas des épreuves.
        $nb_examens = 0;
        $fiche = NPQ_Comptes::fiche_courante();
        if ( $fiche ) {
            global $wpdb;
            $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
            $nb_examens = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tentative
                 WHERE utilisateur_id = %d
                   AND date_fin IS NOT NULL
                   AND score IS NOT NULL
                   AND mode <> 'revision'",
                $fiche['id']
            ) );
        }

        $cls = function ( $cle ) use ( $active ) {
            return ( $active === $cle ) ? ' active' : '';
        };

        ob_start();
        ?>
        <aside class="sidebar">
          <div class="side-user">
            <div class="avatar"><?php echo esc_html( $initiales ); ?></div>
            <div class="su-meta">
              <div class="su-name"><?php echo esc_html( $nom ); ?></div>
              <div class="su-status mono<?php echo $abonne ? '' : ' inactif'; ?>">&#9679; <?php echo $abonne ? 'Abonnement actif' : 'Compte gratuit'; ?></div>
            </div>
          </div>

          <nav class="side-nav">
            <div class="side-group-label">Navigation</div>

            <a class="side-link<?php echo $cls( 'dashboard' ); ?>" href="<?php echo esc_url( $url_espace ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg></span>
              <span class="lbl">Tableau de bord</span>
            </a>

            <a class="side-link<?php echo $cls( 'examens' ); ?>" href="<?php echo esc_url( $url_examen ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18"/><path d="M9 9h6M9 13h6M9 17h3"/></svg></span>
              <span class="lbl">Examens</span>
              <?php if ( $nb_examens ) : ?><span class="badge"><?php echo $nb_examens; ?></span><?php endif; ?>
            </a>

            <a class="side-link<?php echo $cls( 'activite' ); ?>" href="<?php echo esc_url( $url_activite ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span>
              <span class="lbl">Activité</span>
            </a>

            <a class="side-link<?php echo $cls( 'revisions' ); ?>" href="<?php echo esc_url( $url_revision ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="12" height="15"/><rect x="8" y="2" width="12" height="15"/></svg></span>
              <span class="lbl">Révisions</span>
            </a>

            <a class="side-link soon" href="#" title="Bientot disponible">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16"/><path d="M3 10h18M8 2v6M16 2v6"/></svg></span>
              <span class="lbl">Calendrier</span>
              <span class="badge">à venir</span>
            </a>

            <div class="side-divider"></div>
            <div class="side-group-label">Compte</div>

            <a class="side-link<?php echo $cls( 'profil' ); ?>" href="<?php echo esc_url( $url_profil ); ?>">
              <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg></span>
              <span class="lbl">Mon profil</span>
            </a>

            <a class="side-link soon" href="#" title="Bientot disponible">
              <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><path d="M12 3v3M12 18v3M4.2 6.2l2.1 2.1M17.7 15.7l2.1 2.1M3 12h3M18 12h3M4.2 17.8l2.1-2.1M17.7 8.3l2.1-2.1"/></svg></span>
              <span class="lbl">Configuration</span>
              <span class="badge">à venir</span>
            </a>

            <a class="side-link soon" href="#" title="Bientot disponible">
              <span class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13"/><path d="M3 10h18"/></svg></span>
              <span class="lbl">Facturation</span>
              <span class="badge">à venir</span>
            </a>
          </nav>

          <div class="side-bottom">
            <button class="collapse-btn" id="npqCollapseToggle" type="button">
              <span class="icon"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></span>
              <span class="lbl">Réduire le menu</span>
            </button>
          </div>
        </aside>
        <?php
        return ob_get_clean();
    }

    /** Charge nos templates pour les pages de l'espace membre. */
    public static function charger_template( $template ) {
        // Page « Mon espace » (tableau de bord).
        $page_espace = get_option( self::OPT_PAGE_ESPACE );
        if ( ( $page_espace && is_page( $page_espace ) )
             || ( is_page() && get_page_template_slug() === 'npq-espace' ) ) {
            $fichier = NPQ_PATH . 'public/page-espace-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }

        // Page « Mon profil » (même coquille, barre latérale partagée).
        $page_profil = get_option( 'npq_page_profil_id' );
        if ( $page_profil && is_page( $page_profil ) ) {
            $fichier = NPQ_PATH . 'public/page-profil-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }

        // Page « Passer un examen » (même coquille).
        $page_examen = get_option( 'npq_page_examen_id' );
        if ( $page_examen && is_page( $page_examen ) ) {
            $fichier = NPQ_PATH . 'public/page-examen-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }

        // Page « Révisions » (même coquille).
        $page_revision = get_option( 'npq_page_revision_id' );
        if ( $page_revision && is_page( $page_revision ) ) {
            $fichier = NPQ_PATH . 'public/page-revision-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }

        // Page « Activité » (même coquille).
        $page_activite = get_option( 'npq_page_activite_id' );
        if ( $page_activite && is_page( $page_activite ) ) {
            $fichier = NPQ_PATH . 'public/page-activite-normaprep.php';
            if ( file_exists( $fichier ) ) {
                return $fichier;
            }
        }

        return $template;
    }

    /**
     * Charge les ressources de l'espace (style + script), sur ses pages.
     */
    public static function charger_styles() {
        $page_espace = get_option( self::OPT_PAGE_ESPACE );
        $page_profil = get_option( 'npq_page_profil_id' );
        $page_examen = get_option( 'npq_page_examen_id' );

        $page_revision = get_option( 'npq_page_revision_id' );
        $page_activite = get_option( 'npq_page_activite_id' );

        $sur_espace = ( $page_espace && is_page( $page_espace ) )
                   || ( $page_profil && is_page( $page_profil ) )
                   || ( $page_examen && is_page( $page_examen ) )
                   || ( $page_revision && is_page( $page_revision ) )
                   || ( $page_activite && is_page( $page_activite ) );

        if ( ! $sur_espace ) {
            return;
        }
        wp_enqueue_style(
            'npq-espace',
            NPQ_URL . 'assets/npq-espace.css',
            [],
            NPQ_VERSION
        );
        // Script de repli de la barre latérale.
        wp_enqueue_script(
            'npq-espace',
            NPQ_URL . 'assets/npq-espace.js',
            [],
            NPQ_VERSION,
            true
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
     * @param string $contexte 'header' (barre du haut, desktop) ou 'menu'
     *                         (à l'intérieur du menu burger, mobile).
     *                         Sur mobile, le thème masque la nav principale et
     *                         l'ouvre en panneau : le bloc compte doit s'y trouver,
     *                         sinon l'utilisateur ne peut plus se connecter.
     * @return string HTML du bloc.
     */
    public static function bloc_compte( $contexte = 'header' ) {
        $classe_contexte = ( $contexte === 'menu' )
            ? 'npq-compte--menu'
            : 'npq-compte--header';

        if ( is_user_logged_in() ) {
            $page_id    = get_option( self::OPT_PAGE_ESPACE );
            $url_espace = $page_id ? get_permalink( $page_id ) : home_url( '/' );
            $url_deco   = wp_logout_url( home_url( '/' ) );

            return '<div class="npq-compte npq-compte--connecte ' . $classe_contexte . '">'
                 . '<a href="' . esc_url( $url_espace ) . '" class="npq-compte-lien">Mon espace</a>'
                 . '<a href="' . esc_url( $url_deco ) . '" class="npq-compte-lien npq-compte-deco">Se déconnecter</a>'
                 . '</div>';
        }

        $page_connexion = get_option( NPQ_Auth::OPT_PAGE_CONNEXION );
        $url_connexion  = $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' );

        return '<div class="npq-compte npq-compte--visiteur ' . $classe_contexte . '">'
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
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL
               AND mode <> 'revision'
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
