<?php
/**
 * Plugin Name:       NormaPrep Quiz
 * Plugin URI:        https://github.com/【votre-compte】/normaprep-quiz
 * Description:       Module d'examens blancs pour la certification ISO/IEC 27001 Lead Implementer : scénarios, questions à choix multiples, composition d'examens par thèmes, correction détaillée et suivi de progression.
 * Version:           2.18.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NormaPrep
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       normaprep-quiz
 */

/* -------------------------------------------------------------------------
 * 1. Garde-fou de sécurité
 * -------------------------------------------------------------------------
 * Empêche l'accès direct au fichier via l'URL. Si WordPress n'est pas chargé,
 * la constante ABSPATH n'existe pas : on arrête tout immédiatement.
 * C'est une protection standard présente en tête de chaque fichier d'un plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 2. Constantes du plugin
 * -------------------------------------------------------------------------
 * Des repères réutilisés partout dans le code, définis une seule fois ici.
 * Modifier une valeur ici la met à jour dans tout le plugin.
 */

// Version courante. IMPORTANT : cette valeur doit rester synchronisée avec
// la ligne « Version: » de l'en-tête ci-dessus.
define( 'NPQ_VERSION', '2.18.1' );

// Chemin absolu vers le dossier du plugin sur le serveur (pour charger des fichiers PHP).
define( 'NPQ_PATH', plugin_dir_path( __FILE__ ) );

// URL vers le dossier du plugin (pour charger des styles CSS ou des scripts JS).
define( 'NPQ_URL', plugin_dir_url( __FILE__ ) );

// Préfixe de nos tables. Combiné au préfixe WordPress, il donnera par exemple
// « wp_npq_scenario ». On récupère le préfixe WordPress dynamiquement plus tard,
// ce qui rend le plugin portable sur n'importe quelle installation.
define( 'NPQ_TABLE_PREFIX', 'npq_' );

/* -------------------------------------------------------------------------
 * 3. Activation du plugin
 * -------------------------------------------------------------------------
 * Le code ci-dessous s'exécute UNE SEULE FOIS, au moment où l'on clique sur
 * « Activer » dans l'administration WordPress. C'est ici que l'on créera les
 * tables de la base de données (à l'étape suivante de notre construction).
 *
 * Pour l'instant, on se contente de charger le fichier d'installation et
 * d'appeler sa fonction. Ce fichier sera créé à la prochaine étape ; la ligne
 * est préparée mais commentée pour ne pas provoquer d'erreur tant que le
 * fichier n'existe pas.
 */
function npq_activation() {
    require_once NPQ_PATH . 'includes/class-npq-installer.php';
    NPQ_Installer::creer_tables();

    require_once NPQ_PATH . 'includes/class-npq-comptes.php';
    NPQ_Comptes::creer_role();

    require_once NPQ_PATH . 'public/class-npq-auth.php';
    NPQ_Auth::creer_pages();

    require_once NPQ_PATH . 'public/class-npq-espace.php';
    NPQ_Espace::creer_page();

    require_once NPQ_PATH . 'public/class-npq-examen.php';
    NPQ_Examen::creer_page();

    require_once NPQ_PATH . 'public/class-npq-profil.php';
    NPQ_Profil::creer_page();

    require_once NPQ_PATH . 'public/class-npq-revision.php';
    NPQ_Revision::creer_page();

    require_once NPQ_PATH . 'public/class-npq-activite.php';
    NPQ_Activite::creer_page();

    require_once NPQ_PATH . 'public/class-npq-flashcards.php';
    NPQ_Flashcards::creer_page();
}
register_activation_hook( __FILE__, 'npq_activation' );

/* -------------------------------------------------------------------------
 * 4. Désactivation du plugin
 * -------------------------------------------------------------------------
 * S'exécute quand on clique sur « Désactiver ». On NE supprime PAS les tables
 * ici : désactiver n'est pas désinstaller, et on ne veut jamais perdre les
 * données des abonnés par accident. Le nettoyage optionnel (avec le choix que
 * l'on offrira à l'utilisateur) se fera à la désinstallation complète, gérée
 * dans un fichier séparé « uninstall.php » que l'on créera plus tard.
 */
function npq_desactivation() {
    // Rien à faire à ce stade. Emplacement réservé pour un éventuel nettoyage
    // temporaire (par exemple vider un cache) lors d'une désactivation.
}
register_deactivation_hook( __FILE__, 'npq_desactivation' );

/* -------------------------------------------------------------------------
 * 5. Chargement du plugin
 * -------------------------------------------------------------------------
 * Point de départ du fonctionnement normal (hors activation). Au fil de la
 * construction, on chargera ici les différents dossiers du plugin
 * (database, logic, public, admin). Pour l'instant, l'ossature est en place
 * et prête à accueillir la suite.
 */
function npq_init() {
    // Logique de composition et de correction (disponible partout).
    require_once NPQ_PATH . 'logic/class-npq-composeur.php';
    require_once NPQ_PATH . 'logic/class-npq-correcteur.php';

    // Gestion des comptes abonnés (rôle, lien WordPress, droits d'accès).
    require_once NPQ_PATH . 'includes/class-npq-comptes.php';
    NPQ_Comptes::init();

    // Inscription, validation d'email et connexion des abonnés (côté public).
    require_once NPQ_PATH . 'public/class-npq-auth.php';
    NPQ_Auth::init();

    // Espace abonné (tableau de bord) et cloisonnement WordPress.
    require_once NPQ_PATH . 'public/class-npq-espace.php';
    NPQ_Espace::init();

    // Déroulé d'examen (réservé aux abonnés actifs).
    require_once NPQ_PATH . 'public/class-npq-examen.php';
    NPQ_Examen::init();

    // Gestion du profil abonné (mot de passe, email, suppression).
    require_once NPQ_PATH . 'public/class-npq-profil.php';
    NPQ_Profil::init();

    // Révisions (entraînement sans chrono, explications immédiates).
    require_once NPQ_PATH . 'public/class-npq-revision.php';
    NPQ_Revision::init();

    // Activité (indicateurs de progression du candidat).
    require_once NPQ_PATH . 'public/class-npq-activite.php';
    NPQ_Activite::init();

    // Flashcards (mémorisation : recto / verso).
    require_once NPQ_PATH . 'public/class-npq-flashcards.php';
    NPQ_Flashcards::init();

    // Administration du contenu (état de la banque, couverture PECB).
    if ( is_admin() ) {
        require_once NPQ_PATH . 'admin/class-npq-admin.php';
        NPQ_Admin::init();
    }

    // Chargement de l'import de contenu (uniquement dans l'administration).
    if ( is_admin() ) {
        require_once NPQ_PATH . 'database/class-npq-importer.php';
        NPQ_Importer::init();
    }
}
add_action( 'plugins_loaded', 'npq_init' );
