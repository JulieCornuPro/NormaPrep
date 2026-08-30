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
     * Longueurs maximales acceptées.
     *
     * Le message est le seul champ libre, donc le seul par lequel on peut
     * remplir la base : la colonne est un LONGTEXT, qui accepte quatre
     * gigaoctets. Sans borne, quelques requêtes suffisent à saturer
     * l'hébergement — et rien dans le formulaire ne l'aurait empêché.
     *
     * 5000 caractères, c'est une dizaine de paragraphes. Personne ne dit une
     * demande de devis plus longuement ; qui a besoin de plus joindra un
     * document par email une fois le contact établi.
     *
     * Les deux autres bornes valent la taille de leur colonne : au-delà,
     * MySQL en mode strict rejette l'écriture, et le message serait perdu
     * sur une faute de frappe.
     */
    const MAX_MESSAGE = 5000;
    const MAX_NOM     = 190;
    const MAX_EMAIL   = 190;

    /**
     * Jours au bout desquels l'adresse IP d'un message est effacée.
     *
     * Une IP n'a d'utilité que pour traiter un abus en cours. Passé ce
     * délai, elle ne sert plus à rien — et la conserver sans usage est
     * exactement ce que le RGPD proscrit. Le message, lui, reste : c'est un
     * échange commercial, pas une donnée de surveillance.
     */
    const RETENTION_IP_JOURS = 30;

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

        // --- Données personnelles ---
        //
        // Un formulaire de contact collecte un nom, une adresse email et ce
        // que la personne a bien voulu écrire. C'est peu, mais c'est
        // exactement ce que le RGPD appelle des données personnelles, et il
        // ouvre trois obligations concrètes : ne pas garder plus longtemps
        // que nécessaire, savoir dire ce que l'on détient sur quelqu'un, et
        // savoir l'effacer sur demande.
        //
        // WordPress fournit les deux derniers outils depuis sa version 4.9 :
        // Outils → Exporter / Effacer les données personnelles. Il suffit de
        // s'y brancher — les réécrire serait doublement absurde, puisque
        // l'administrateur devrait alors penser à interroger deux endroits.
        add_action( 'npq_purge_ip_messages', [ __CLASS__, 'purger_ip' ] );
        add_action( 'init', [ __CLASS__, 'planifier_purge' ] );

        add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'declarer_exporteur' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'declarer_effaceur' ] );
    }

    /* =====================================================================
     * DONNÉES PERSONNELLES
     * ===================================================================== */

    /** Programme la purge quotidienne des adresses IP, si elle ne l'est pas. */
    public static function planifier_purge() {
        if ( ! wp_next_scheduled( 'npq_purge_ip_messages' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'npq_purge_ip_messages' );
        }
    }

    /**
     * Efface les adresses IP des messages anciens.
     *
     * On vide la colonne plutôt que de supprimer la ligne : le message reste
     * consultable — c'est un échange commercial qui peut resservir — mais la
     * donnée qui n'a plus d'usage disparaît. Une IP ne sert qu'à traiter un
     * abus en cours ; passé un mois, elle n'est plus qu'un risque.
     */
    public static function purger_ip() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $limite = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_IP_JOURS * DAY_IN_SECONDS ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}message SET ip = NULL WHERE ip IS NOT NULL AND date_envoi < %s",
            $limite
        ) );
    }

    /**
     * Déclare l'exportateur : « qu'avez-vous sur moi ? »
     *
     * @param array $exporteurs
     * @return array
     */
    public static function declarer_exporteur( $exporteurs ) {
        $exporteurs['normaprep-messages'] = [
            'exporter_friendly_name' => 'Messages de contact NormaPrep',
            'callback'               => [ __CLASS__, 'exporter_donnees' ],
        ];
        return $exporteurs;
    }

    public static function exporter_donnees( $email, $page = 1 ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}message WHERE email = %s ORDER BY date_envoi",
            $email
        ), ARRAY_A );

        $motifs  = self::motifs();
        $donnees = [];

        foreach ( $lignes as $ligne ) {
            $donnees[] = [
                'group_id'    => 'npq-messages',
                'group_label' => 'Messages de contact',
                'item_id'     => 'message-' . (int) $ligne['id'],
                'data'        => [
                    [ 'name' => 'Date',    'value' => $ligne['date_envoi'] ],
                    [ 'name' => 'Nom',     'value' => $ligne['nom'] ],
                    [ 'name' => 'Email',   'value' => $ligne['email'] ],
                    [ 'name' => 'Motif',   'value' => $motifs[ $ligne['motif'] ] ?? $ligne['motif'] ],
                    [ 'name' => 'Message', 'value' => $ligne['message'] ],
                ],
            ];
        }

        return [ 'data' => $donnees, 'done' => true ];
    }

    /**
     * Déclare l'effaceur : « supprimez ce que vous avez sur moi. »
     *
     * @param array $effaceurs
     * @return array
     */
    public static function declarer_effaceur( $effaceurs ) {
        $effaceurs['normaprep-messages'] = [
            'eraser_friendly_name' => 'Messages de contact NormaPrep',
            'callback'             => [ __CLASS__, 'effacer_donnees' ],
        ];
        return $effaceurs;
    }

    public static function effacer_donnees( $email, $page = 1 ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $supprimes = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}message WHERE email = %s",
            $email
        ) );

        return [
            'items_removed'  => (bool) $supprimes,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
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

        // Le compteur avance AVANT toute validation, et non après un envoi
        // réussi. Sinon un robot qui poste des données invalides — une adresse
        // email malformée suffit — n'est jamais compté, et peut marteler le
        // formulaire indéfiniment sans jamais franchir la limite.
        self::compter_tentative();

        // sanitize_text_field() écrase au passage les retours à la ligne, ce
        // qui ferme la porte à l'injection d'en-têtes email : une valeur qui
        // ne peut pas contenir de saut de ligne ne peut pas ajouter un « Bcc: »
        // à la notification. On ne s'en remet pas à cet effet de bord seul —
        // voir aussi la vérification explicite dans notifier().
        $nom     = isset( $_POST['npq_nom'] ) ? sanitize_text_field( wp_unslash( $_POST['npq_nom'] ) ) : '';
        $email   = isset( $_POST['npq_email'] ) ? sanitize_email( wp_unslash( $_POST['npq_email'] ) ) : '';
        $motif   = isset( $_POST['npq_motif'] ) ? sanitize_key( $_POST['npq_motif'] ) : 'autre';
        $message = isset( $_POST['npq_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npq_message'] ) ) : '';

        // Bornes de longueur. On tronque plutôt que de refuser : quelqu'un qui
        // a écrit trop long a écrit quelque chose, et lui rendre sa page vide
        // avec un reproche coûte plus que de garder les 5000 premiers
        // caractères. Le refus est réservé à ce qui est inexploitable.
        $nom     = mb_substr( $nom, 0, self::MAX_NOM );
        $email   = mb_substr( $email, 0, self::MAX_EMAIL );
        $message = mb_substr( $message, 0, self::MAX_MESSAGE );

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
            preg_replace( '/[\r\n]+/', ' ', $nom )
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

        // DÉFENSE EN PROFONDEUR SUR L'EN-TÊTE
        //
        // Un saut de ligne dans « Répondre à » permettrait d'y greffer un
        // en-tête de plus — un « Bcc: » vers une adresse choisie, et la
        // notification part aussi ailleurs. sanitize_text_field() écrase déjà
        // ces caractères en amont, mais on ne fait pas reposer une faille
        // d'injection sur un effet de bord : si un jour cette fonction change,
        // ou si quelqu'un modifie la ligne de nettoyage, la vérification
        // ci-dessous tient toujours.
        $nom_entete = preg_replace( '/[\r\n]+/', ' ', $nom );

        $entetes = [];
        if ( is_email( $email ) && false === strpbrk( $email, "\r\n" ) ) {
            $entetes[] = 'Reply-To: ' . $nom_entete . ' <' . $email . '>';
        }

        wp_mail( $destinataire, $sujet, $corps, $entetes );
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

    private static function compter_tentative() {
        $cle = self::cle_limite();
        $n   = (int) get_transient( $cle );

        // La fenêtre repart de la dernière tentative : c'est volontaire.
        // Quelqu'un qui insiste attend plus longtemps que quelqu'un qui
        // s'arrête.
        set_transient( $cle, $n + 1, NPQ_Limitation::FENETRE );
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    /**
     * Clé du message d'état, propre au VISITEUR et non à son adresse IP.
     *
     * L'IP ne désigne pas une personne : derrière celle d'une entreprise, ou
     * d'un opérateur mobile, il y en a des centaines. Une clé fondée sur elle
     * faisait lire à l'un le message destiné à l'autre — « votre message a
     * bien été envoyé » à quelqu'un qui n'avait rien envoyé.
     *
     * WordPress pose déjà un jeton de session anonyme sur chaque visiteur
     * (COOKIEHASH). On s'en sert quand il existe ; sinon on retombe sur l'IP,
     * qui vaut mieux que rien pour l'affichage d'un simple accusé.
     */
    private static function cle_flash() {
        $jeton = '';

        if ( ! empty( $_COOKIE[ 'npq_visiteur' ] ) ) {
            $jeton = sanitize_key( $_COOKIE['npq_visiteur'] );
        }

        if ( '' === $jeton ) {
            $jeton = self::ip();
        }

        return 'npq_flash_contact_' . md5( $jeton );
    }

    private static function flash( $texte, $type = 'info' ) {
        // Le témoin de visiteur est posé au moment où l'on en a besoin, et
        // pas avant : un cookie déposé sur toutes les pages sans usage serait
        // à déclarer, celui-ci ne sert qu'à se rendre son propre accusé de
        // réception et disparaît avec la session.
        if ( empty( $_COOKIE['npq_visiteur'] ) && ! headers_sent() ) {
            $jeton = wp_generate_password( 20, false );
            setcookie( 'npq_visiteur', $jeton, 0, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
            $_COOKIE['npq_visiteur'] = $jeton;
        }

        set_transient( self::cle_flash(), [ 'texte' => $texte, 'type' => $type ], 60 );
    }

    private static function message_flash() {
        $cle   = self::cle_flash();
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
