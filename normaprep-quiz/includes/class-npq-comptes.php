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
     * L'utilisateur connecté a-t-il un abonnement actif ?
     * Vérifie la table abonnement (statut 'actif' et période non expirée).
     *
     * @return bool
     */
    public static function est_abonne_actif() {
        $fiche = self::fiche_courante();
        if ( ! $fiche ) {
            return false;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $actif = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}abonnement
             WHERE utilisateur_id = %d
               AND statut = 'actif'
               AND ( fin_periode IS NULL OR fin_periode >= CURDATE() )",
            $fiche['id']
        ) );

        return $actif > 0;
    }

    /**
     * L'utilisateur a-t-il le droit de passer un examen complet ?
     * Combine le contrôle de capacité (rôle) et l'abonnement actif.
     *
     * @return bool
     */
    public static function peut_passer_examen_complet() {
        return is_user_logged_in()
            && current_user_can( self::CAP_PASSER_EXAMEN )
            && self::est_abonne_actif();
    }
}
