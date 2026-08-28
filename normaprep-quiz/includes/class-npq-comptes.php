<?php
/**
 * Gestion des comptes abonnés NormaPrep.
 *
 * Architecture à trois niveaux :
 *   1. Authentification  -> déléguée à WordPress (wp_users).
 *   2. Cloisonnement     -> rôle dédié « Abonné NormaPrep », étanche vis-à-vis de l'admin.
 *   3. Données métier    -> table npq_utilisateur (abonnement, progression...).
 *
 * Cette classe crée le rôle, relie un compte WordPress à sa fiche métier,
 * et fournit les fonctions de contrôle d'accès (est-il abonné actif ?).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Comptes {

    /** Identifiant technique de notre rôle sur mesure. */
    const ROLE = 'npq_abonne';

    /** Capacité sur mesure : le droit de passer un examen. */
    const CAP_PASSER_EXAMEN = 'npq_passer_examen';

    /**
     * Crée le rôle « Abonné NormaPrep ».
     * Appelée à l'activation du plugin. Idempotente : ne fait rien si le rôle existe.
     */
    /**
     * Nom d'affichage déduit d'une adresse email.
     *
     * WordPress refuse d'enregistrer un compte dont le nom affiché est une
     * adresse email — une protection contre la collecte d'adresses. Or à
     * l'inscription, l'adresse est tout ce que l'on connaît de la personne.
     *
     * On garde donc ce qui précède l'arobase. Ce n'est pas un vrai nom, mais
     * c'est lisible, ce n'est pas une adresse, et la personne pourra le
     * changer. Un repli existe pour le cas improbable d'une partie locale
     * vide : mieux vaut un nom générique qu'un compte qu'on ne peut plus
     * modifier.
     *
     * @param string $email
     * @return string
     */
    public static function nom_depuis_email( $email ) {
        $partie = strstr( (string) $email, '@', true );
        $partie = trim( (string) $partie );

        return ( '' !== $partie ) ? $partie : 'Membre';
    }

    public static function creer_role() {
        // add_role ne recrée pas un rôle déjà présent ; on peut l'appeler sans risque.
        add_role(
            self::ROLE,
            'Abonné NormaPrep',
            [
                'read'                   => true,  // capacité minimale pour se connecter
                self::CAP_PASSER_EXAMEN  => true,  // notre permission sur mesure
            ]
        );
    }

    /**
     * Supprime le rôle. Réservé à la désinstallation complète (pas à la désactivation).
     */
    public static function supprimer_role() {
        remove_role( self::ROLE );
    }

    /**
     * Enregistre les branchements nécessaires au fonctionnement normal.
     * Appelée au chargement du plugin.
     */
    public static function init() {
        // Quand un utilisateur WordPress est créé, on crée sa fiche métier si c'est un abonné.
        add_action( 'user_register', [ __CLASS__, 'creer_fiche_metier' ] );
    }

    /**
     * Crée (ou complète) la fiche métier NormaPrep pour un compte WordPress donné.
     * Ne duplique pas l'authentification : stocke seulement le lien et des infos métier.
     *
     * @param int $wp_user_id Identifiant du compte WordPress.
     */
    public static function creer_fiche_metier( $wp_user_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On ne crée une fiche que pour les comptes ayant le rôle abonné NormaPrep.
        $user = get_userdata( $wp_user_id );
        if ( ! $user || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
            return;
        }

        // Existe déjà ? (idempotent)
        $existe = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}utilisateur WHERE wp_user_id = %d",
            $wp_user_id
        ) );
        if ( $existe ) {
            return;
        }

        $wpdb->insert( "{$p}utilisateur", [
            'wp_user_id'  => $wp_user_id,
            'email'       => $user->user_email,
            'nom_affiche' => $user->display_name,
            'role'        => 'gratuit', // devient 'abonne' à la souscription
        ] );
    }

    /**
     * Retourne la fiche métier de l'utilisateur WordPress actuellement connecté,
     * ou null s'il n'est pas connecté / pas un abonné NormaPrep.
     *
     * @return array|null
     */
    public static function fiche_courante() {
        if ( ! is_user_logged_in() ) {
            return null;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wp_user_id = get_current_user_id();
        $fiche = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}utilisateur WHERE wp_user_id = %d",
            $wp_user_id
        ), ARRAY_A );

        return $fiche ?: null;
    }

    /**
     * L'utilisateur connecté détient-il au moins un accès valide ?
     *
     * LA BIBLIOTHÈQUE EST LE REGISTRE DES DROITS — l'unique. Détenir une
     * certification non expirée, c'est avoir le droit de s'en servir.
     *
     * Auparavant, deux barrières se superposaient : la table `abonnement`
     * disait « est-ce un client payant ? », la bibliothèque « à quelles
     * certifications ? ». Cette superposition devient intenable dès qu'on vend
     * à l'unité : un client ayant acheté une certification obtiendrait sa ligne
     * de bibliothèque et resterait pourtant bloqué, faute de ligne dans
     * `abonnement`. Il faudrait écrire deux fois le même fait — donc deux
     * occasions de désynchronisation, et un jour un client payant à la porte.
     *
     * Une seule table décide, une seule table s'écrit.
     *
     * @return bool
     */
    public static function a_acces_actif() {
        $fiche = self::fiche_courante();
        if ( ! $fiche ) {
            return false;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $actifs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d
               AND ( fin_acces IS NULL OR fin_acces >= CURDATE() )",
            $fiche['id']
        ) );

        return ( $actifs > 0 );
    }

    /**
     * Conservé sous son ancien nom : il est appelé depuis des gabarits de page
     * (public/page-espace-normaprep.php), susceptibles d'avoir été recopiés
     * dans le thème. Le renommer casserait ces copies sans prévenir.
     *
     * @deprecated Préférer a_acces_actif(), dont le nom dit la vérité.
     * @return bool
     */
    public static function est_abonne_actif() {
        return self::a_acces_actif();
    }

    /**
     * Où envoyer quelqu'un qui n'a pas (ou plus) accès.
     *
     * Cette résolution vivait recopiée dans cinq fichiers, et retombait sur
     * « # » quand la page « offres » n'existait pas — un lien mort au moment
     * précis où l'on essaie de vendre.
     *
     * Ordre : la page « offres » si tu en as fait une, sinon la boutique
     * WooCommerce, sinon l'accueil. Le repli sur la boutique évite d'avoir à
     * créer une page vitrine pour commencer à vendre.
     *
     * @return string
     */
    public static function url_offres() {
        $page = get_page_by_path( 'offres' );
        if ( $page ) {
            return get_permalink( $page );
        }

        if ( function_exists( 'wc_get_page_id' ) ) {
            $boutique = wc_get_page_id( 'shop' );
            if ( $boutique && $boutique > 0 ) {
                return get_permalink( $boutique );
            }
        }

        return home_url( '/' );
    }

    /**
     * L'utilisateur a-t-il le droit de passer un examen complet ?
     * Combine le contrôle de capacité (rôle) et la détention d'un accès.
     *
     * Barrière GÉNÉRALE : elle dit « cette personne a-t-elle accès à quelque
     * chose ». Le périmètre — quelle certification précisément — est vérifié
     * en aval, à chaque usage, par NPQ_Bibliotheque::utilisateur_peut_acceder().
     *
     * @return bool
     */
    public static function peut_passer_examen_complet() {
        return is_user_logged_in()
            && current_user_can( self::CAP_PASSER_EXAMEN )
            && self::a_acces_actif();
    }
}
