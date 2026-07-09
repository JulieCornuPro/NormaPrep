<?php
/**
 * Installation du plugin NormaPrep Quiz.
 *
 * Cette classe crée les tables de la base de données à l'activation du plugin,
 * à partir du modèle de données validé. Elle utilise dbDelta(), la fonction
 * WordPress qui crée les tables si elles n'existent pas, ou met à jour leur
 * structure si elle a évolué — sans détruire les données existantes.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Installer {

    /**
     * Crée l'ensemble des tables du plugin.
     * Appelée une seule fois, à l'activation.
     */
    public static function creer_tables() {
        global $wpdb;

        // dbDelta() vit dans un fichier WordPress qui n'est pas chargé par défaut.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Préfixe complet : préfixe WordPress (ex. wp_) + notre préfixe (npq_).
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Jeu de caractères / collation de l'installation WordPress (UTF-8 en général).
        $charset = $wpdb->get_charset_collate();

        // On collecte toutes les instructions CREATE TABLE, puis on les passe à dbDelta.
        // Note : dbDelta est exigeant sur le format (deux espaces après PRIMARY KEY,
        // une clé par ligne, etc.). Le format ci-dessous respecte ces contraintes.
        $sql = [];

        /* =====================================================================
         * CONTENU
         * ===================================================================== */

        // --- Certifications : le sommet de la hiérarchie de contenu ---
        // Une certification (ex. ISO 27001 Lead Implementer) regroupe scénarios,
        // questions et examens. Permet de gérer plusieurs certifications à terme.
        $sql[] = "CREATE TABLE {$p}certification (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(30) NOT NULL,
            nom VARCHAR(190) NOT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code)
        ) $charset;";

        // --- Scénarios : le contexte d'entreprise qui encadre les questions ---
        $sql[] = "CREATE TABLE {$p}scenario (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            ref_externe VARCHAR(50) NULL,
            nom VARCHAR(190) NOT NULL,
            resume TEXT NULL,
            contexte LONGTEXT NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'publie',
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY ref_externe (ref_externe),
            KEY certification_id (certification_id)
        ) $charset;";

        // --- Types de tags : article ISO, domaine, phase, compétence... ---
        $sql[] = "CREATE TABLE {$p}tag_type (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nom VARCHAR(100) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY nom (nom)
        ) $charset;";

        // --- Tags : une valeur rattachée à un type (ex. type=article_iso, valeur=10.2) ---
        $sql[] = "CREATE TABLE {$p}tag (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tag_type_id BIGINT UNSIGNED NOT NULL,
            valeur VARCHAR(190) NOT NULL,
            PRIMARY KEY  (id),
            KEY tag_type_id (tag_type_id),
            UNIQUE KEY type_valeur (tag_type_id, valeur)
        ) $charset;";

        // --- Questions : rattachées à un scénario, avec explication de la correction ---
        $sql[] = "CREATE TABLE {$p}question (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            ref_externe VARCHAR(50) NULL,
            scenario_id BIGINT UNSIGNED NULL,
            domaine VARCHAR(20) NOT NULL,
            enonce LONGTEXT NOT NULL,
            multi_reponses TINYINT(1) NOT NULL DEFAULT 0,
            explication LONGTEXT NULL,
            difficulte VARCHAR(20) NOT NULL DEFAULT 'hard',
            statut VARCHAR(20) NOT NULL DEFAULT 'publie',
            PRIMARY KEY  (id),
            UNIQUE KEY ref_externe (ref_externe),
            KEY certification_id (certification_id),
            KEY scenario_id (scenario_id),
            KEY domaine (domaine)
        ) $charset;";

        // --- Options de réponse : plusieurs par question, chacune juste ou fausse ---
        $sql[] = "CREATE TABLE {$p}option_reponse (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED NOT NULL,
            texte TEXT NOT NULL,
            correcte TINYINT(1) NOT NULL DEFAULT 0,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY question_id (question_id)
        ) $charset;";

        // --- Liaison question <-> tag (plusieurs-à-plusieurs) ---
        $sql[] = "CREATE TABLE {$p}question_tag (
            question_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (question_id, tag_id),
            KEY tag_id (tag_id)
        ) $charset;";

        /* =====================================================================
         * EXAMENS
         * ===================================================================== */

        // --- Modèles d'examen : examens prédéfinis et réutilisables ---
        // type = 'fige' (liste de questions fixe) ou 'genere' (base d'un modèle de génération)
        $sql[] = "CREATE TABLE {$p}examen_modele (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            nom VARCHAR(190) NOT NULL,
            description TEXT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'fige',
            actif TINYINT(1) NOT NULL DEFAULT 1,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY certification_id (certification_id)
        ) $charset;";

        // --- Liaison modèle d'examen <-> questions (pour les modèles figés) ---
        $sql[] = "CREATE TABLE {$p}examen_question (
            examen_modele_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (examen_modele_id, question_id),
            KEY question_id (question_id)
        ) $charset;";

        /* =====================================================================
         * UTILISATEURS (séparés des comptes d'administration WordPress)
         * ===================================================================== */

        // --- Utilisateurs abonnés : fiche MÉTIER reliée au compte WordPress ---
        // L'authentification (mot de passe, session) est gérée par WordPress.
        // Cette table ne stocke que les informations propres à NormaPrep.
        // wp_user_id fait le lien avec le compte WordPress (table wp_users).
        $sql[] = "CREATE TABLE {$p}utilisateur (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NULL,
            nom_affiche VARCHAR(190) NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'gratuit',
            date_inscription DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY wp_user_id (wp_user_id)
        ) $charset;";

        // --- Abonnements : au plus un actif par utilisateur ---
        $sql[] = "CREATE TABLE {$p}abonnement (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            utilisateur_id BIGINT UNSIGNED NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'inactif',
            formule VARCHAR(20) NULL,
            fin_periode DATE NULL,
            reference_paiement VARCHAR(190) NULL,
            PRIMARY KEY  (id),
            KEY utilisateur_id (utilisateur_id)
        ) $charset;";

        /* =====================================================================
         * ACTIVITÉ (tentatives d'examen et réponses)
         * ===================================================================== */

        // --- Tentatives : une session d'examen passée par un utilisateur ---
        // examen_modele_id renseigné si l'examen vient d'un modèle ; sinon criteres
        // (JSON) décrit la génération à la volée.
        $sql[] = "CREATE TABLE {$p}tentative (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            utilisateur_id BIGINT UNSIGNED NOT NULL,
            examen_modele_id BIGINT UNSIGNED NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'libre',
            criteres LONGTEXT NULL,
            score SMALLINT UNSIGNED NULL,
            reussi TINYINT(1) NULL,
            date_debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_fin DATETIME NULL,
            PRIMARY KEY  (id),
            KEY utilisateur_id (utilisateur_id),
            KEY examen_modele_id (examen_modele_id)
        ) $charset;";

        // --- Réponses : une par question au sein d'une tentative ---
        $sql[] = "CREATE TABLE {$p}reponse (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tentative_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            correcte TINYINT(1) NULL,
            PRIMARY KEY  (id),
            KEY tentative_id (tentative_id),
            KEY question_id (question_id)
        ) $charset;";

        // --- Options cochées : plusieurs par réponse (gère le multi-réponses) ---
        $sql[] = "CREATE TABLE {$p}reponse_option (
            reponse_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (reponse_id, option_id),
            KEY option_id (option_id)
        ) $charset;";

        // Exécution : dbDelta traite chaque CREATE TABLE.
        foreach ( $sql as $requete ) {
            dbDelta( $requete );
        }

        // On mémorise la version de schéma installée. Utile plus tard pour gérer
        // les migrations d'une version à l'autre.
        update_option( 'npq_db_version', NPQ_VERSION );
    }
}
