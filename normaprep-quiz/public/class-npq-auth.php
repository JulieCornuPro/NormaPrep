<?php
/**
 * Inscription, validation d'email et connexion des abonnés NormaPrep.
 *
 * S'appuie sur les fonctions natives et sécurisées de WordPress :
 *   - wp_create_user / wp_insert_user : création de compte (mot de passe haché).
 *   - wp_signon : connexion (gestion de session).
 *   - wp_generate_password : génération du jeton de validation.
 *
 * Sécurité :
 *   - Validation d'email obligatoire : le compte reste inactif tant que le lien
 *     de validation n'a pas été cliqué.
 *   - Honeypot : champ caché piégeant les robots.
 *   - Nonces WordPress sur chaque formulaire (protection CSRF).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Auth {

    /** Clés de métadonnées utilisateur. */
    const META_EMAIL_VERIFIE = 'npq_email_verifie';
    const META_JETON         = 'npq_jeton_validation';

    /** Options mémorisant les id des pages créées automatiquement. */
    const OPT_PAGE_INSCRIPTION = 'npq_page_inscription_id';
    const OPT_PAGE_CONNEXION   = 'npq_page_connexion_id';

    /**
     * Branchements au chargement du plugin.
     */
    public static function init() {
        add_shortcode( 'npq_inscription', [ __CLASS__, 'rendu_inscription' ] );
        add_shortcode( 'npq_connexion',   [ __CLASS__, 'rendu_connexion' ] );

        // Traitement des formulaires (avant tout affichage, pour pouvoir rediriger).
        add_action( 'template_redirect', [ __CLASS__, 'traiter_formulaires' ] );

        // Validation du compte via le lien reçu par email (?npq_valider=JETON&uid=ID).
        add_action( 'template_redirect', [ __CLASS__, 'traiter_validation_email' ] );
    }

    /* =====================================================================
     * CRÉATION DES PAGES À L'ACTIVATION
     * ===================================================================== */

    /**
     * Crée les pages « Inscription » et « Connexion » si elles n'existent pas.
     * Appelée à l'activation du plugin.
     */
    public static function creer_pages() {
        self::creer_page_si_absente(
            self::OPT_PAGE_INSCRIPTION, 'Inscription', 'inscription', '[npq_inscription]'
        );
        self::creer_page_si_absente(
            self::OPT_PAGE_CONNEXION, 'Connexion', 'connexion', '[npq_connexion]'
        );
    }

    private static function creer_page_si_absente( $option, $titre, $slug, $contenu ) {
        $page_id = get_option( $option );
        if ( $page_id && get_post( $page_id ) ) {
            return; // déjà créée
        }
        $page_id = wp_insert_post( [
            'post_title'   => $titre,
            'post_name'    => $slug,
            'post_content' => $contenu,
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( $option, $page_id );
        }
    }

    /* =====================================================================
     * AFFICHAGE DES FORMULAIRES (shortcodes)
     * ===================================================================== */

    public static function rendu_inscription() {
        if ( is_user_logged_in() ) {
            return '<p>Vous êtes déjà connecté(e).</p>';
        }

        $message = self::message_flash();

        ob_start();
        ?>
        <form class="npq-form" method="post">
            <?php echo $message; ?>
            <input type="hidden" name="npq_action" value="inscription">
            <?php wp_nonce_field( 'npq_inscription', 'npq_nonce' ); ?>

            <p>
                <label for="npq_email">Adresse email</label><br>
                <input type="email" id="npq_email" name="npq_email" required
                       value="<?php echo isset( $_POST['npq_email'] ) ? esc_attr( wp_unslash( $_POST['npq_email'] ) ) : ''; ?>">
            </p>
            <p>
                <label for="npq_mdp">Mot de passe</label><br>
                <input type="password" id="npq_mdp" name="npq_mdp" required minlength="8">
            </p>
            <p>
                <label for="npq_mdp2">Confirmer le mot de passe</label><br>
                <input type="password" id="npq_mdp2" name="npq_mdp2" required minlength="8">
            </p>

            <?php // Honeypot : champ caché. Un humain ne le remplit jamais. ?>
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <label>Ne pas remplir ce champ
                    <input type="text" name="npq_site" tabindex="-1" autocomplete="off">
                </label>
            </div>

            <p><button type="submit" class="npq-btn">Créer mon compte</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function rendu_connexion() {
        if ( is_user_logged_in() ) {
            return '<p>Vous êtes déjà connecté(e).</p>';
        }

        $message = self::message_flash();

        ob_start();
        ?>
        <form class="npq-form" method="post">
            <?php echo $message; ?>
            <input type="hidden" name="npq_action" value="connexion">
            <?php wp_nonce_field( 'npq_connexion', 'npq_nonce' ); ?>

            <p>
                <label for="npq_email_c">Adresse email</label><br>
                <input type="email" id="npq_email_c" name="npq_email" required>
            </p>
            <p>
                <label for="npq_mdp_c">Mot de passe</label><br>
                <input type="password" id="npq_mdp_c" name="npq_mdp" required>
            </p>
            <p><button type="submit" class="npq-btn">Se connecter</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * TRAITEMENT DES FORMULAIRES
     * ===================================================================== */

    public static function traiter_formulaires() {
        if ( empty( $_POST['npq_action'] ) ) {
            return;
        }
        $action = sanitize_key( $_POST['npq_action'] );

        if ( $action === 'inscription' ) {
            self::traiter_inscription();
        } elseif ( $action === 'connexion' ) {
            self::traiter_connexion();
        }
    }

    private static function traiter_inscription() {
        // Sécurité : nonce.
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_inscription' ) ) {
            return self::flash( 'Session expirée, merci de réessayer.', 'erreur' );
        }

        // Honeypot : si rempli, c'est un robot. On arrête silencieusement.
        if ( ! empty( $_POST['npq_site'] ) ) {
            return self::flash( 'Inscription refusée.', 'erreur' );
        }

        $email = isset( $_POST['npq_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_email'] ) ) : '';
        $mdp   = isset( $_POST['npq_mdp'] ) ? (string) $_POST['npq_mdp'] : '';
        $mdp2  = isset( $_POST['npq_mdp2'] ) ? (string) $_POST['npq_mdp2'] : '';

        // Validations.
        if ( ! is_email( $email ) ) {
            return self::flash( 'Adresse email invalide.', 'erreur' );
        }
        if ( email_exists( $email ) ) {
            return self::flash( 'Un compte existe déjà avec cette adresse.', 'erreur' );
        }
        if ( strlen( $mdp ) < 8 ) {
            return self::flash( 'Le mot de passe doit contenir au moins 8 caractères.', 'erreur' );
        }
        if ( $mdp !== $mdp2 ) {
            return self::flash( 'Les deux mots de passe ne correspondent pas.', 'erreur' );
        }

        // Création du compte WordPress avec le rôle abonné NormaPrep.
        $user_id = wp_insert_user( [
            'user_login' => $email,
            'user_email' => $email,
            'user_pass'  => $mdp,
            'role'       => NPQ_Comptes::ROLE,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return self::flash( 'Erreur lors de la création du compte.', 'erreur' );
        }

        // Marque le compte comme non vérifié + génère un jeton de validation.
        $jeton = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::META_EMAIL_VERIFIE, 0 );
        update_user_meta( $user_id, self::META_JETON, $jeton );

        // Envoie l'email de validation (attrapé par Mailpit en local).
        self::envoyer_email_validation( $user_id, $email, $jeton );

        return self::flash(
            'Votre compte a été créé. Un email de validation vous a été envoyé : '
            . 'cliquez sur le lien qu\'il contient pour activer votre accès.',
            'succes'
        );
    }

    private static function envoyer_email_validation( $user_id, $email, $jeton ) {
        $lien = add_query_arg(
            [ 'npq_valider' => $jeton, 'uid' => $user_id ],
            home_url( '/' )
        );

        $sujet = 'Validez votre compte NormaPrep';
        $corps = "Bienvenue sur NormaPrep.\n\n"
               . "Pour activer votre compte, cliquez sur le lien ci-dessous :\n"
               . $lien . "\n\n"
               . "Si vous n'êtes pas à l'origine de cette inscription, ignorez cet email.";

        wp_mail( $email, $sujet, $corps );
    }

    public static function traiter_validation_email() {
        if ( empty( $_GET['npq_valider'] ) || empty( $_GET['uid'] ) ) {
            return;
        }
        $jeton   = sanitize_text_field( wp_unslash( $_GET['npq_valider'] ) );
        $user_id = (int) $_GET['uid'];

        $jeton_attendu = get_user_meta( $user_id, self::META_JETON, true );

        if ( $jeton_attendu && hash_equals( $jeton_attendu, $jeton ) ) {
            update_user_meta( $user_id, self::META_EMAIL_VERIFIE, 1 );
            delete_user_meta( $user_id, self::META_JETON ); // jeton à usage unique
            self::flash( 'Votre email est validé. Vous pouvez maintenant vous connecter.', 'succes' );
        } else {
            self::flash( 'Lien de validation invalide ou expiré.', 'erreur' );
        }

        // Redirige vers la page de connexion.
        $page_id = get_option( self::OPT_PAGE_CONNEXION );
        wp_safe_redirect( $page_id ? get_permalink( $page_id ) : home_url( '/' ) );
        exit;
    }

    private static function traiter_connexion() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_connexion' ) ) {
            return self::flash( 'Session expirée, merci de réessayer.', 'erreur' );
        }

        $email = isset( $_POST['npq_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_email'] ) ) : '';
        $mdp   = isset( $_POST['npq_mdp'] ) ? (string) $_POST['npq_mdp'] : '';

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return self::flash( 'Identifiants incorrects.', 'erreur' );
        }

        // Bloque la connexion tant que l'email n'est pas validé.
        if ( ! get_user_meta( $user->ID, self::META_EMAIL_VERIFIE, true ) ) {
            return self::flash(
                'Votre email n\'est pas encore validé. Vérifiez votre boîte de réception.',
                'erreur'
            );
        }

        // Connexion via la fonction sécurisée de WordPress.
        $resultat = wp_signon( [
            'user_login'    => $user->user_login,
            'user_password' => $mdp,
            'remember'      => true,
        ] );

        if ( is_wp_error( $resultat ) ) {
            return self::flash( 'Identifiants incorrects.', 'erreur' );
        }

        // Redirige vers l'accueil (ou plus tard vers le tableau de bord).
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    /* =====================================================================
     * MESSAGES FLASH (affichés après redirection)
     * ===================================================================== */

    private static function flash( $texte, $type = 'info' ) {
        set_transient( 'npq_flash_' . self::cle_visiteur(), [ 'texte' => $texte, 'type' => $type ], 60 );
    }

    private static function message_flash() {
        $cle = 'npq_flash_' . self::cle_visiteur();
        $flash = get_transient( $cle );
        if ( ! $flash ) {
            return '';
        }
        delete_transient( $cle );
        $couleur = $flash['type'] === 'erreur' ? '#c0392b' : ( $flash['type'] === 'succes' ? '#1e8449' : '#555' );
        return '<div class="npq-flash" style="padding:12px;border-radius:6px;margin-bottom:16px;'
             . 'color:#fff;background:' . $couleur . '">' . esc_html( $flash['texte'] ) . '</div>';
    }

    /** Clé simple pour rattacher un message flash au visiteur courant. */
    private static function cle_visiteur() {
        if ( is_user_logged_in() ) {
            return 'u' . get_current_user_id();
        }
        return 'ip' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'anon' );
    }
}
