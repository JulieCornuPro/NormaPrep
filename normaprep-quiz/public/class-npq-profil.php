<?php
/**
 * Gestion du profil abonné : mot de passe, email, suppression de compte.
 *
 * Sécurité :
 *   - Chaque opération exige le mot de passe actuel (preuve d'identité).
 *   - Changement d'email : revalidation par email du NOUVEL email avant application.
 *   - Suppression : confirmation explicite ; anonymisation (RGPD) plutôt qu'effacement
 *     total, pour conserver des statistiques d'examens non identifiantes.
 *   - Nonces sur chaque formulaire.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Profil {

    const OPT_PAGE_PROFIL   = 'npq_page_profil_id';
    const META_EMAIL_ATTENTE = 'npq_email_en_attente';
    const META_EMAIL_JETON   = 'npq_email_jeton';

    public static function init() {
        add_shortcode( 'npq_profil', [ __CLASS__, 'rendu' ] );
        add_action( 'template_redirect', [ __CLASS__, 'traiter' ] );
        add_action( 'template_redirect', [ __CLASS__, 'traiter_validation_nouvel_email' ] );
    }

    /**
     * Crée la page « Mon profil » à l'activation.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_PROFIL );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Mon profil',
            'post_name'    => 'mon-profil',
            'post_content' => '[npq_profil]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_PROFIL, $page_id );
        }
    }

    /* =====================================================================
     * TRAITEMENT DES ACTIONS
     * ===================================================================== */

    public static function traiter() {
        if ( empty( $_POST['npq_profil_action'] ) || ! is_user_logged_in() ) {
            return;
        }
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_profil' ) ) {
            return self::flash( 'Session expirée, merci de réessayer.', 'erreur' );
        }

        $action = sanitize_key( $_POST['npq_profil_action'] );

        if ( $action === 'mot_de_passe' ) {
            self::changer_mot_de_passe();
        } elseif ( $action === 'email' ) {
            self::demander_changement_email();
        } elseif ( $action === 'supprimer' ) {
            self::supprimer_compte();
        }
    }

    /** Vérifie le mot de passe actuel de l'utilisateur connecté. */
    private static function mdp_actuel_correct( $mdp ) {
        $user = wp_get_current_user();
        return $user && wp_check_password( $mdp, $user->user_pass, $user->ID );
    }

    private static function changer_mot_de_passe() {
        $actuel = isset( $_POST['npq_mdp_actuel'] ) ? (string) $_POST['npq_mdp_actuel'] : '';
        $nouveau = isset( $_POST['npq_mdp_nouveau'] ) ? (string) $_POST['npq_mdp_nouveau'] : '';
        $confirm = isset( $_POST['npq_mdp_confirm'] ) ? (string) $_POST['npq_mdp_confirm'] : '';

        if ( ! self::mdp_actuel_correct( $actuel ) ) {
            return self::flash( 'Mot de passe actuel incorrect.', 'erreur' );
        }
        if ( strlen( $nouveau ) < 8 ) {
            return self::flash( 'Le nouveau mot de passe doit contenir au moins 8 caractères.', 'erreur' );
        }
        if ( $nouveau !== $confirm ) {
            return self::flash( 'Les deux nouveaux mots de passe ne correspondent pas.', 'erreur' );
        }

        wp_set_password( $nouveau, get_current_user_id() );
        // wp_set_password déconnecte l'utilisateur : on le préviendra de se reconnecter.
        self::flash( 'Mot de passe modifié. Merci de vous reconnecter.', 'succes' );

        $page_connexion = get_option( NPQ_Auth::OPT_PAGE_CONNEXION );
        wp_safe_redirect( $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' ) );
        exit;
    }

    private static function demander_changement_email() {
        $actuel = isset( $_POST['npq_mdp_actuel'] ) ? (string) $_POST['npq_mdp_actuel'] : '';
        $nouvel_email = isset( $_POST['npq_nouvel_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_nouvel_email'] ) ) : '';

        if ( ! self::mdp_actuel_correct( $actuel ) ) {
            return self::flash( 'Mot de passe incorrect.', 'erreur' );
        }
        if ( ! is_email( $nouvel_email ) ) {
            return self::flash( 'Nouvelle adresse email invalide.', 'erreur' );
        }
        if ( email_exists( $nouvel_email ) ) {
            return self::flash( 'Cette adresse est déjà utilisée.', 'erreur' );
        }

        // On mémorise le nouvel email « en attente » + un jeton, sans encore l'appliquer.
        $user_id = get_current_user_id();
        $jeton = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::META_EMAIL_ATTENTE, $nouvel_email );
        update_user_meta( $user_id, self::META_EMAIL_JETON, $jeton );

        // Envoie le lien de validation au NOUVEL email.
        $lien = add_query_arg(
            [ 'npq_valider_email' => $jeton, 'uid' => $user_id ],
            home_url( '/' )
        );
        $sujet = 'Confirmez votre nouvelle adresse email NormaPrep';
        $corps = "Vous avez demandé à changer l'adresse email de votre compte NormaPrep.\n\n"
               . "Pour confirmer ce changement, cliquez sur le lien ci-dessous :\n"
               . $lien . "\n\n"
               . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : "
               . "aucune modification ne sera effectuée.";
        wp_mail( $nouvel_email, $sujet, $corps );

        self::flash(
            'Un email de confirmation a été envoyé à la nouvelle adresse. '
            . 'Le changement sera effectif après validation.',
            'succes'
        );
    }

    public static function traiter_validation_nouvel_email() {
        if ( empty( $_GET['npq_valider_email'] ) || empty( $_GET['uid'] ) ) {
            return;
        }
        $jeton   = sanitize_text_field( wp_unslash( $_GET['npq_valider_email'] ) );
        $user_id = (int) $_GET['uid'];

        $jeton_attendu = get_user_meta( $user_id, self::META_EMAIL_JETON, true );
        $nouvel_email  = get_user_meta( $user_id, self::META_EMAIL_ATTENTE, true );

        if ( $jeton_attendu && $nouvel_email && hash_equals( $jeton_attendu, $jeton ) ) {
            // Applique le nouvel email côté WordPress ET dans la fiche métier.
            wp_update_user( [ 'ID' => $user_id, 'user_email' => $nouvel_email ] );

            global $wpdb;
            $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
            $wpdb->update( "{$p}utilisateur", [ 'email' => $nouvel_email ], [ 'wp_user_id' => $user_id ] );

            delete_user_meta( $user_id, self::META_EMAIL_ATTENTE );
            delete_user_meta( $user_id, self::META_EMAIL_JETON );

            self::flash( 'Votre adresse email a été mise à jour.', 'succes' );
        } else {
            self::flash( 'Lien de confirmation invalide ou expiré.', 'erreur' );
        }

        $page_id = get_option( self::OPT_PAGE_PROFIL );
        wp_safe_redirect( $page_id ? get_permalink( $page_id ) : home_url( '/' ) );
        exit;
    }

    private static function supprimer_compte() {
        $actuel = isset( $_POST['npq_mdp_actuel'] ) ? (string) $_POST['npq_mdp_actuel'] : '';
        $confirme = ! empty( $_POST['npq_confirme_suppression'] );

        if ( ! self::mdp_actuel_correct( $actuel ) ) {
            return self::flash( 'Mot de passe incorrect.', 'erreur' );
        }
        if ( ! $confirme ) {
            return self::flash( 'Veuillez cocher la case de confirmation.', 'erreur' );
        }

        $user_id = get_current_user_id();

        // Anonymisation RGPD : on vide les données personnelles de la fiche métier,
        // mais on conserve les tentatives (rattachées à une fiche anonymisée).
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        $wpdb->update(
            "{$p}utilisateur",
            [
                'email'       => null,
                'nom_affiche' => 'Compte supprimé',
                'role'        => 'supprime',
                'wp_user_id'  => 0, // on détache du compte WordPress
            ],
            [ 'wp_user_id' => $user_id ]
        );

        // Suppression du compte WordPress (identité, mot de passe).
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user_id );

        // Déconnexion et retour à l'accueil.
        wp_logout();
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function rendu() {
        if ( ! is_user_logged_in() ) {
            $page_connexion = get_option( NPQ_Auth::OPT_PAGE_CONNEXION );
            $url = $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' );
            return '<p>Vous devez être connecté(e). <a href="' . esc_url( $url ) . '">Se connecter</a></p>';
        }

        $user = wp_get_current_user();
        $message = self::message_flash();

        ob_start();
        ?>
        <div class="npq-profil">
            <?php echo $message; ?>

            <h2>Mon profil</h2>
            <p>Adresse email actuelle : <strong><?php echo esc_html( $user->user_email ); ?></strong></p>

            <h3>Changer mon mot de passe</h3>
            <form class="npq-form" method="post">
                <input type="hidden" name="npq_profil_action" value="mot_de_passe">
                <?php wp_nonce_field( 'npq_profil', 'npq_nonce' ); ?>
                <p><label>Mot de passe actuel<br><input type="password" name="npq_mdp_actuel" required></label></p>
                <p><label>Nouveau mot de passe<br><input type="password" name="npq_mdp_nouveau" required minlength="8"></label></p>
                <p><label>Confirmer le nouveau mot de passe<br><input type="password" name="npq_mdp_confirm" required minlength="8"></label></p>
                <p><button type="submit" class="npq-btn">Modifier le mot de passe</button></p>
            </form>

            <h3>Changer mon adresse email</h3>
            <form class="npq-form" method="post">
                <input type="hidden" name="npq_profil_action" value="email">
                <?php wp_nonce_field( 'npq_profil', 'npq_nonce' ); ?>
                <p><label>Mot de passe actuel<br><input type="password" name="npq_mdp_actuel" required></label></p>
                <p><label>Nouvelle adresse email<br><input type="email" name="npq_nouvel_email" required></label></p>
                <p><button type="submit" class="npq-btn">Demander le changement</button></p>
                <p style="font-size:13px;color:#94A3B8">Un email de confirmation sera envoyé à la nouvelle adresse.</p>
            </form>

            <h3>Supprimer mon compte</h3>
            <form class="npq-form" method="post" onsubmit="return confirm('Cette action est définitive. Confirmer la suppression ?');">
                <input type="hidden" name="npq_profil_action" value="supprimer">
                <?php wp_nonce_field( 'npq_profil', 'npq_nonce' ); ?>
                <p><label>Mot de passe actuel<br><input type="password" name="npq_mdp_actuel" required></label></p>
                <p><label><input type="checkbox" name="npq_confirme_suppression" value="1"> Je confirme vouloir supprimer définitivement mon compte.</label></p>
                <p><button type="submit" class="npq-btn" style="background:#c0392b">Supprimer mon compte</button></p>
                <p style="font-size:13px;color:#94A3B8">Vos données personnelles seront effacées. Cette action est irréversible.</p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * MESSAGES FLASH
     * ===================================================================== */

    private static function flash( $texte, $type = 'info' ) {
        set_transient( 'npq_flash_p' . get_current_user_id(), [ 'texte' => $texte, 'type' => $type ], 60 );
    }

    private static function message_flash() {
        $cle = 'npq_flash_p' . get_current_user_id();
        $flash = get_transient( $cle );
        if ( ! $flash ) {
            return '';
        }
        delete_transient( $cle );
        $couleur = $flash['type'] === 'erreur' ? '#c0392b' : ( $flash['type'] === 'succes' ? '#1e8449' : '#555' );
        return '<div style="padding:12px;border-radius:6px;margin-bottom:16px;color:#fff;background:'
             . $couleur . '">' . esc_html( $flash['texte'] ) . '</div>';
    }
}
