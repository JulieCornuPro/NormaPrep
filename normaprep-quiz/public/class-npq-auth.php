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
    const META_JETON_DATE    = 'npq_jeton_date';

    /**
     * Durée de validité d'un lien de validation, en secondes.
     *
     * Un jeton sans expiration reste exploitable indéfiniment : un lien oublié
     * au fond d'une boîte mail, ou lu par quelqu'un qui y a accès des années
     * plus tard, permettrait encore d'activer le compte. Sept jours laissent
     * largement le temps de relever son courrier.
     */
    const JETON_VALIDITE = 7 * DAY_IN_SECONDS;

    /**
     * Réponse unique du formulaire d'inscription.
     *
     * Volontairement identique que l'adresse soit libre ou déjà prise : c'est
     * ce qui empêche d'utiliser le formulaire pour vérifier si une adresse est
     * inscrite. Le message reste vrai dans les deux cas — un email part bien.
     */
    const MESSAGE_INSCRIPTION = 'Si cette adresse peut être utilisée, un email vient de vous être '
                              . 'envoyé. Cliquez sur le lien qu\'il contient pour activer votre accès.';

    /** Options mémorisant les id des pages créées automatiquement. */
    const OPT_PAGE_INSCRIPTION = 'npq_page_inscription_id';
    const OPT_PAGE_CONNEXION   = 'npq_page_connexion_id';

    /**
     * Branchements au chargement du plugin.
     */
    public static function init() {
        add_shortcode( 'npq_inscription', [ __CLASS__, 'rendu_inscription' ] );
        add_shortcode( 'npq_connexion',   [ __CLASS__, 'rendu_connexion' ] );

        // Feuille de style des formulaires publics.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_styles' ] );

        // Traitement des formulaires (avant tout affichage, pour pouvoir rediriger).
        add_action( 'template_redirect', [ __CLASS__, 'traiter_formulaires' ] );

        // Validation du compte via le lien reçu par email (?npq_valider=JETON&uid=ID).
        add_action( 'template_redirect', [ __CLASS__, 'traiter_validation_email' ] );
    }

    /**
     * Charge la feuille de style des formulaires publics.
     */
    public static function charger_styles() {
        wp_enqueue_style(
            'npq-public',
            NPQ_URL . 'assets/npq-public.css',
            [],
            NPQ_VERSION
        );
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

        // Trop de comptes créés depuis cette adresse : on freine. Le honeypot
        // arrête les robots naïfs, pas un outil qui remplit correctement le
        // formulaire.
        if ( NPQ_Limitation::inscription_bloquee() ) {
            return self::flash(
                'Trop de comptes créés depuis cette connexion. Réessayez plus tard.',
                'erreur'
            );
        }

        // Validations.
        if ( ! is_email( $email ) ) {
            return self::flash( 'Adresse email invalide.', 'erreur' );
        }

        // Adresse déjà inscrite : on ne le dit PAS, et on répond exactement
        // comme pour une inscription réussie. Annoncer « un compte existe
        // déjà » transforme ce formulaire en outil de vérification d'adresses.
        //
        // Un email part quand même — vers l'adresse existante, pour signaler
        // la tentative. La personne concernée est ainsi informée, sans que
        // celui qui a rempli le formulaire n'apprenne quoi que ce soit.
        if ( email_exists( $email ) ) {
            self::avertir_compte_existant( $email );
            NPQ_Limitation::inscription_effectuee();
            return self::flash( self::MESSAGE_INSCRIPTION, 'succes' );
        }

        if ( strlen( $mdp ) < 8 ) {
            return self::flash( 'Le mot de passe doit contenir au moins 8 caractères.', 'erreur' );
        }
        if ( $mdp !== $mdp2 ) {
            return self::flash( 'Les deux mots de passe ne correspondent pas.', 'erreur' );
        }

        // Création du compte WordPress avec le rôle abonné NormaPrep.
        // display_name : surtout PAS l'adresse email.
        //
        // Sans cette clé, WordPress recopie user_login — ici l'adresse. Or il
        // REFUSE ensuite toute mise à jour d'un compte dont le nom affiché est
        // une adresse email, par protection contre la collecte d'adresses. Le
        // compte devient alors impossible à modifier, y compris pour un simple
        // changement de mot de passe, et le message d'erreur parle du nom
        // affiché alors qu'on n'y a jamais touché.
        //
        // On prend donc ce qui précède l'arobase. Ce n'est pas un vrai nom,
        // mais c'est la seule chose qu'on connaisse de la personne à
        // l'inscription, et elle pourra le changer.
        $nom_affiche = NPQ_Comptes::nom_depuis_email( $email );

        $user_id = wp_insert_user( [
            'user_login'   => $email,
            'user_email'   => $email,
            'user_pass'    => $mdp,
            'display_name' => $nom_affiche,
            'nickname'     => $nom_affiche,
            'role'         => NPQ_Comptes::ROLE,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return self::flash( 'Erreur lors de la création du compte.', 'erreur' );
        }

        // Marque le compte comme non vérifié + génère un jeton de validation,
        // horodaté pour qu'il puisse expirer (voir jeton_expire()).
        $jeton = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::META_EMAIL_VERIFIE, 0 );
        update_user_meta( $user_id, self::META_JETON, $jeton );
        update_user_meta( $user_id, self::META_JETON_DATE, time() );

        // Envoie l'email de validation (attrapé par Mailpit en local).
        self::envoyer_email_validation( $user_id, $email, $jeton );

        NPQ_Limitation::inscription_effectuee();

        return self::flash( self::MESSAGE_INSCRIPTION, 'succes' );
    }

    /**
     * Prévient le titulaire d'un compte qu'on a tenté de se réinscrire avec
     * son adresse.
     *
     * C'est la contrepartie du silence : le formulaire ne révèle rien à celui
     * qui l'a rempli, mais la personne concernée est avertie — et si elle
     * avait simplement oublié son compte, l'email le lui rappelle.
     *
     * @param string $email
     */
    private static function avertir_compte_existant( $email ) {
        $page_connexion = get_option( self::OPT_PAGE_CONNEXION );
        $lien = $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' );

        wp_mail(
            $email,
            'Tentative d\'inscription avec votre adresse',
            "Quelqu'un a tenté de créer un compte NormaPrep avec cette adresse, "
            . "qui est déjà associée à un compte existant.\n\n"
            . "Si c'était vous : connectez-vous plutôt ici.\n"
            . $lien . "\n\n"
            . "Si ce n'était pas vous, aucune action n'est nécessaire : "
            . "aucun compte n'a été créé et le vôtre n'a pas été modifié."
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

        // hash_equals : comparaison à durée constante. Comparer avec === ferait
        // varier le temps de réponse selon le nombre de caractères devinés,
        // ce qui, répété, permet de reconstituer le jeton.
        $valide = ( $jeton_attendu && hash_equals( $jeton_attendu, $jeton ) );

        if ( $valide && self::jeton_expire( $user_id ) ) {
            // Jeton juste mais périmé : on le retire plutôt que de le laisser
            // traîner, et on invite à recommencer.
            delete_user_meta( $user_id, self::META_JETON );
            delete_user_meta( $user_id, self::META_JETON_DATE );
            $valide = false;
        }

        if ( $valide ) {
            update_user_meta( $user_id, self::META_EMAIL_VERIFIE, 1 );
            delete_user_meta( $user_id, self::META_JETON ); // jeton à usage unique
            delete_user_meta( $user_id, self::META_JETON_DATE );
            self::flash( 'Votre email est validé. Vous pouvez maintenant vous connecter.', 'succes' );
        } else {
            self::flash( 'Lien de validation invalide ou expiré.', 'erreur' );
        }

        // Redirige vers la page de connexion.
        $page_id = get_option( self::OPT_PAGE_CONNEXION );
        wp_safe_redirect( $page_id ? get_permalink( $page_id ) : home_url( '/' ) );
        exit;
    }

    /**
     * Le jeton de validation de ce compte a-t-il dépassé sa durée de vie ?
     *
     * Les comptes créés avant l'horodatage n'en ont pas : on les considère
     * valides, faute de pouvoir dater leur jeton. Ils s'épuiseront d'eux-mêmes
     * à mesure que leurs titulaires valideront leur adresse.
     *
     * @param int $user_id
     * @return bool
     */
    private static function jeton_expire( $user_id ) {
        $date = (int) get_user_meta( $user_id, self::META_JETON_DATE, true );
        if ( ! $date ) {
            return false;
        }
        return ( ( time() - $date ) > self::JETON_VALIDITE );
    }

    private static function traiter_connexion() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_connexion' ) ) {
            return self::flash( 'Session expirée, merci de réessayer.', 'erreur' );
        }

        $email = isset( $_POST['npq_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_email'] ) ) : '';
        $mdp   = isset( $_POST['npq_mdp'] ) ? (string) $_POST['npq_mdp'] : '';

        // Trop d'échecs récents : on refuse d'examiner la demande. WordPress ne
        // limite pas nativement les tentatives ; sans ce garde, le formulaire
        // accepte des milliers d'essais par minute.
        if ( NPQ_Limitation::connexion_bloquee( $email ) ) {
            return self::flash(
                sprintf(
                    'Trop de tentatives de connexion. Réessayez dans %d minutes.',
                    NPQ_Limitation::minutes_restantes()
                ),
                'erreur'
            );
        }

        $user = get_user_by( 'email', $email );

        // Email inconnu, email non validé, mot de passe faux : TROIS causes,
        // UN SEUL message. Distinguer « identifiants incorrects » de « email
        // pas encore validé » revenait à confirmer l'existence du compte —
        // de quoi constituer une liste d'adresses valides, qui sert ensuite
        // au bourrage d'identifiants et au hameçonnage.
        //
        // On compte l'échec dans tous les cas, y compris quand l'email
        // n'existe pas : sinon le balayage d'adresses ne serait jamais ralenti.
        $echec = function () use ( $email ) {
            NPQ_Limitation::connexion_echouee( $email );
            return self::flash( 'Identifiants incorrects, ou compte pas encore validé.', 'erreur' );
        };

        if ( ! $user ) {
            return $echec();
        }

        if ( ! get_user_meta( $user->ID, self::META_EMAIL_VERIFIE, true ) ) {
            return $echec();
        }

        // Connexion via la fonction sécurisée de WordPress.
        $resultat = wp_signon( [
            'user_login'    => $user->user_login,
            'user_password' => $mdp,
            'remember'      => true,
        ] );

        if ( is_wp_error( $resultat ) ) {
            return $echec();
        }

        NPQ_Limitation::connexion_reussie( $email );

        // Redirige vers l'espace abonné.
        $page_espace = get_option( 'npq_page_espace_id' );
        wp_safe_redirect( $page_espace ? get_permalink( $page_espace ) : home_url( '/' ) );
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
