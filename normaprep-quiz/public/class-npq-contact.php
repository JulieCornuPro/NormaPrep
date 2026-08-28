<?php
/**
 * Formulaire de contact.
 *
 * DEUX DESTINATIONS, PAS UNE
 *
 * Chaque message est ENREGISTRÉ en base ET envoyé par email. L'email seul ne
 * suffit pas : il peut se perdre — filtre anti-spam, panne du serveur
 * d'envoi, adresse de réception mal réglée — et l'on ne s'en aperçoit jamais.
 * Rien ne distingue « personne ne m'écrit » de « je ne reçois plus mes
 * messages ». L'enregistrement est la seule trace qui ne dépend de personne
 * d'autre ; l'email n'est qu'une notification.
 *
 * L'ordre compte : on enregistre AVANT d'envoyer. Si l'envoi échoue, le
 * message existe quand même.
 *
 * CE QUI PROTÈGE LE FORMULAIRE
 *
 * Un formulaire public ouvert sur une base de données attire les robots.
 * Trois garde-fous, du moins gênant au plus visible :
 *
 *   1. un champ piège, invisible aux humains : un robot qui remplit tout
 *      remplit aussi celui-là, et se signale ;
 *   2. la limitation par IP de NPQ_Limitation, déjà en place pour la
 *      connexion — un même visiteur ne peut pas envoyer en rafale ;
 *   3. le jeton de session de WordPress, contre les envois depuis un autre
 *      site.
 *
 * Aucun ne demande d'effort au visiteur légitime. C'est le but : un CAPTCHA
 * fait fuir des clients pour arrêter des robots que ces trois-là arrêtent
 * déjà.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Contact {

    /** Option portant l'identifiant de la page « Contact ». */
    const OPT_PAGE_CONTACT = 'npq_page_contact_id';

    /** Clé de limitation, distincte de celles de la connexion. */
    const LIMITE_CLE = 'contact';

    /** Envois autorisés depuis une même adresse, par fenêtre de limitation. */
    const MAX_PAR_IP = 5;

    /**
     * Motifs proposés.
     *
     * Le motif oriente la réponse avant même de lire le message : une
     * demande de devis ne se traite pas comme un souci technique. Il est
     * repris dans l'objet de l'email, pour que le tri se fasse dans la boîte
     * de réception sans avoir à ouvrir.
     *
     * @return array<string,string>
     */
    public static function motifs() {
        return [
            'certification' => 'Question sur une certification',
            'devis'         => 'Demande de devis ou d\'audit',
            'commande'      => 'Commande ou facturation',
            'technique'     => 'Problème technique',
            'autre'         => 'Autre',
        ];
    }

    public static function init() {
        add_shortcode( 'npq_contact', [ __CLASS__, 'rendu' ] );

        // Priorité 5 : le traitement doit avoir lieu avant tout affichage,
        // pour pouvoir rediriger sans que rien n'ait été envoyé au navigateur.
        add_action( 'template_redirect', [ __CLASS__, 'traiter' ], 5 );

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_styles' ] );
    }

    /**
     * Crée la page « Contact » à l'activation, si absente.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_CONTACT );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }

        $page_id = wp_insert_post( [
            'post_title'   => 'Contact',
            'post_name'    => 'contact',
            'post_content' => '[npq_contact]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_CONTACT, $page_id );
        }
    }

    /** Charge la feuille de style, sur la seule page de contact. */
    public static function charger_styles() {
        $page_id = get_option( self::OPT_PAGE_CONTACT );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }

        wp_enqueue_style(
            'npq-contact',
            NPQ_URL . 'assets/npq-contact.css',
            [],
            NPQ_VERSION
        );
    }

    /* =====================================================================
     * TRAITEMENT
     * ===================================================================== */

    public static function traiter() {
        if ( empty( $_POST['npq_contact_envoi'] ) ) {
            return;
        }

        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_contact' ) ) {
            return self::flash( 'Session expirée, merci de renvoyer votre message.', 'erreur' );
        }

        // Le champ piège. Un humain ne le voit pas et ne peut donc pas le
        // remplir. On répond « envoyé » plutôt qu'une erreur : un robot à qui
        // l'on dit qu'il a été repéré adapte son prochain essai.
        if ( ! empty( $_POST['npq_site_web'] ) ) {
            return self::flash( 'Merci, votre message a bien été envoyé.', 'succes' );
        }

        if ( self::trop_d_envois() ) {
            return self::flash(
                'Vous avez envoyé plusieurs messages coup sur coup. '
                . 'Merci de patienter quelques minutes avant le suivant.',
                'erreur'
            );
        }

        $nom     = isset( $_POST['npq_nom'] ) ? sanitize_text_field( wp_unslash( $_POST['npq_nom'] ) ) : '';
        $email   = isset( $_POST['npq_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_email'] ) ) : '';
        $motif   = isset( $_POST['npq_motif'] ) ? sanitize_key( $_POST['npq_motif'] ) : 'autre';
        $message = isset( $_POST['npq_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npq_message'] ) ) : '';

        if ( '' === trim( $nom ) ) {
            return self::flash( 'Merci d\'indiquer votre nom.', 'erreur' );
        }
        if ( ! is_email( $email ) ) {
            return self::flash( 'L\'adresse email indiquée n\'est pas valide.', 'erreur' );
        }
        if ( mb_strlen( trim( $message ) ) < 10 ) {
            return self::flash( 'Votre message est trop court pour qu\'on puisse y répondre utilement.', 'erreur' );
        }

        $motifs = self::motifs();
        if ( ! isset( $motifs[ $motif ] ) ) {
            $motif = 'autre';
        }

        // ENREGISTREMENT D'ABORD. Si l'envoi échoue ensuite, le message reste.
        $enregistre = self::enregistrer( $nom, $email, $motif, $message );

        if ( ! $enregistre ) {
            return self::flash(
                'Votre message n\'a pas pu être enregistré. Merci de réessayer, '
                . 'ou de nous écrire directement si le problème persiste.',
                'erreur'
            );
        }

        self::compter_envoi();
        self::notifier( $nom, $email, $motif, $message );

        self::flash(
            'Merci, votre message a bien été envoyé. Nous vous répondrons à l\'adresse indiquée.',
            'succes'
        );

        // Redirection après traitement : sans elle, un rechargement de page
        // renverrait le formulaire une seconde fois.
        $page_id = get_option( self::OPT_PAGE_CONTACT );
        wp_safe_redirect( $page_id ? get_permalink( $page_id ) : home_url( '/' ) );
        exit;
    }

    /**
     * Enregistre le message.
     *
     * @return bool Faux si l'écriture a échoué.
     */
    private static function enregistrer( $nom, $email, $motif, $message ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ok = $wpdb->insert(
            "{$p}message",
            [
                'date_envoi' => current_time( 'mysql' ),
                'nom'        => $nom,
                'email'      => $email,
                'motif'      => $motif,
                'message'    => $message,
                'statut'     => 'nouveau',
                'wp_user_id' => get_current_user_id() ?: null,
                'ip'         => self::ip(),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return (bool) $ok;
    }

    /**
     * Prévient par email qu'un message est arrivé.
     *
     * L'expéditeur est mis en « Répondre à », et non en « De » : usurper
     * l'adresse d'un tiers dans le champ De fait rejeter le message par les
     * filtres modernes (SPF, DKIM). Le message partirait alors de la boîte
     * d'expédition du site — et n'arriverait nulle part.
     */
    private static function notifier( $nom, $email, $motif, $message ) {
        $motifs = self::motifs();
        $destinataire = get_option( 'admin_email' );

        $sujet = sprintf(
            '[%s] %s — %s',
            get_bloginfo( 'name' ),
            $motifs[ $motif ],
            $nom
        );

        $corps = "Nouveau message reçu depuis le formulaire de contact.\n\n"
               . "Nom    : {$nom}\n"
               . "Email  : {$email}\n"
               . "Motif  : {$motifs[ $motif ]}\n"
               . "Date   : " . date_i18n( 'd/m/Y à H:i' ) . "\n\n"
               . "--------------------------------------------------\n"
               . $message . "\n"
               . "--------------------------------------------------\n\n"
               . "Ce message est aussi consultable dans NormaPrep → Messages, "
               . "même si cet email se perd.";

        wp_mail(
            $destinataire,
            $sujet,
            $corps,
            [ 'Reply-To: ' . $nom . ' <' . $email . '>' ]
        );
    }

    /* =====================================================================
     * LIMITATION
     * ===================================================================== */

    /** Adresse IP de l'appelant, tronquée à la longueur de la colonne. */
    private static function ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return substr( $ip, 0, 45 );
    }

    /** Clé de comptage propre à cette adresse. */
    private static function cle_limite() {
        return NPQ_Limitation::PREFIXE . self::LIMITE_CLE . '_' . md5( self::ip() );
    }

    private static function trop_d_envois() {
        return (int) get_transient( self::cle_limite() ) >= self::MAX_PAR_IP;
    }

    private static function compter_envoi() {
        $cle = self::cle_limite();
        $n   = (int) get_transient( $cle );

        // La fenêtre repart du dernier envoi : c'est volontaire. Quelqu'un qui
        // insiste attend plus longtemps que quelqu'un qui s'arrête.
        set_transient( $cle, $n + 1, NPQ_Limitation::FENETRE );
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    private static function flash( $texte, $type = 'info' ) {
        set_transient( 'npq_flash_contact_' . md5( self::ip() ), [ 'texte' => $texte, 'type' => $type ], 60 );
    }

    private static function message_flash() {
        $cle   = 'npq_flash_contact_' . md5( self::ip() );
        $flash = get_transient( $cle );
        if ( ! $flash ) {
            return '';
        }
        delete_transient( $cle );

        $classe = 'npq-contact-flash npq-contact-flash--' . sanitize_html_class( $flash['type'] );

        return '<div class="' . esc_attr( $classe ) . '">' . esc_html( $flash['texte'] ) . '</div>';
    }

    /**
     * Le formulaire.
     *
     * Les champs sont pré-remplis pour un visiteur connecté : lui redemander
     * son nom et son email alors qu'on les connaît est une question de trop.
     */
    public static function rendu() {
        $nom_defaut   = '';
        $email_defaut = '';

        if ( is_user_logged_in() ) {
            $user         = wp_get_current_user();
            $nom_defaut   = $user->display_name;
            $email_defaut = $user->user_email;
        }

        ob_start();
        ?>
        <div class="npq-contact">
            <?php echo self::message_flash(); ?>

            <form class="npq-contact-form" method="post">
                <input type="hidden" name="npq_contact_envoi" value="1">
                <?php wp_nonce_field( 'npq_contact', 'npq_nonce' ); ?>

                <?php
                /*
                 * Le champ piège. Masqué en CSS et non par type="hidden" :
                 * un robot ignore les champs cachés du navigateur, mais
                 * remplit volontiers un champ texte qu'il croit ordinaire.
                 * tabindex et autocomplete l'écartent du parcours clavier et
                 * du remplissage automatique, pour qu'aucun humain ne tombe
                 * dessus par accident.
                 */
                ?>
                <div class="npq-contact-piege" aria-hidden="true">
                    <label>Site web (laissez vide)
                        <input type="text" name="npq_site_web" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <div class="npq-contact-ligne">
                    <label class="npq-contact-champ">
                        <span class="npq-contact-label">Votre nom</span>
                        <input type="text" name="npq_nom" required
                               value="<?php echo esc_attr( $nom_defaut ); ?>"
                               autocomplete="name">
                    </label>

                    <label class="npq-contact-champ">
                        <span class="npq-contact-label">Votre adresse email</span>
                        <input type="email" name="npq_email" required
                               value="<?php echo esc_attr( $email_defaut ); ?>"
                               autocomplete="email">
                    </label>
                </div>

                <label class="npq-contact-champ">
                    <span class="npq-contact-label">Motif</span>
                    <select name="npq_motif" data-npq-select>
                        <?php foreach ( self::motifs() as $cle => $libelle ) : ?>
                            <option value="<?php echo esc_attr( $cle ); ?>"><?php echo esc_html( $libelle ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="npq-contact-champ">
                    <span class="npq-contact-label">Votre message</span>
                    <textarea name="npq_message" rows="8" required minlength="10"></textarea>
                </label>

                <button type="submit" class="npq-contact-btn">Envoyer le message</button>

                <p class="npq-contact-note">
                    Les informations transmises servent uniquement à traiter votre demande.
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
