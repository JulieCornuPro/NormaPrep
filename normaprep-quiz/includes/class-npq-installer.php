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
        // Domaines d'examen (D1, D2...) avec leur libellé lisible.
        // Rattachés à une certification : chaque référentiel a ses propres domaines.
        $sql[] = "CREATE TABLE {$p}domaine (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            code VARCHAR(20) NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cert_code (certification_id, code)
        ) $charset;";

        // Flashcards : cartes de mémorisation (recto / verso).
        //
        // Contrairement aux questions, une flashcard n'est PAS rattachée à un
        // scénario : c'est une carte générale (« Que dit l'article 6.1.3 d) ? »),
        // sans contexte d'entreprise. C'est ce qui la rend efficace pour retenir
        // la norme. Elle est en revanche rattachée à un domaine, pour pouvoir
        // réviser par thème.
        $sql[] = "CREATE TABLE {$p}flashcard (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            ref_externe VARCHAR(50) NULL,
            domaine VARCHAR(20) NOT NULL,
            recto LONGTEXT NOT NULL,
            verso LONGTEXT NOT NULL,
            statut VARCHAR(20) NOT NULL DEFAULT 'publie',
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY certification_id (certification_id),
            KEY domaine (domaine),
            KEY ref_externe (ref_externe)
        ) $charset;";

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
            nombre_questions SMALLINT UNSIGNED NOT NULL DEFAULT 80,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY certification_id (certification_id)
        ) $charset;";

        // --- Liaison modèle d'examen <-> scénarios (pour les modèles « scenarios ») ---
        // L'examen pioche ses questions parmi celles des scénarios rattachés.
        $sql[] = "CREATE TABLE {$p}examen_scenario (
            examen_modele_id BIGINT UNSIGNED NOT NULL,
            scenario_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (examen_modele_id, scenario_id),
            KEY scenario_id (scenario_id)
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

        // --- Parcours de révision : compositions préprogrammées proposées
        // sur la page « Révisions ». Auparavant figées dans le code, elles sont
        // désormais administrables. Les domaines sont stockés en JSON (une
        // courte liste, toujours lue en bloc — comme la colonne « criteres »
        // de la table tentative).
        $sql[] = "CREATE TABLE {$p}parcours (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certification_id BIGINT UNSIGNED NULL,
            titre VARCHAR(190) NOT NULL,
            resume TEXT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'criteres',
            domaines TEXT NULL,
            nombre SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            statut VARCHAR(20) NOT NULL DEFAULT 'publie',
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY certification_id (certification_id)
        ) $charset;";

        // --- Liaison parcours <-> questions (pour les parcours à questions
        // choisies). La position fixe l'ordre de présentation. Prépare aussi
        // une future réorganisation par glisser-déposer.
        $sql[] = "CREATE TABLE {$p}parcours_question (
            parcours_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (parcours_id, question_id),
            KEY question_id (question_id)
        ) $charset;";

        // Exécution : dbDelta traite chaque CREATE TABLE.
        foreach ( $sql as $requete ) {
            dbDelta( $requete );
        }

        // Amorçage : recrée une seule fois les 4 parcours qui étaient figés
        // dans le code, pour ne pas partir d'une page Révisions vide. Ne
        // s'exécute que si la table est vide (idempotent : pas de doublons si
        // creer_tables est rappelée).
        self::amorcer_parcours();

        // On mémorise la version de schéma installée. Utile plus tard pour gérer
        // les migrations d'une version à l'autre.
        update_option( 'npq_db_version', NPQ_VERSION );
    }

    /**
     * Insère les parcours de révision d'origine, une seule fois.
     * Rattachés à la certification active. Idempotent : ne fait rien si la
     * table contient déjà des parcours.
     */
    private static function amorcer_parcours() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $deja = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}parcours" );
        if ( $deja > 0 ) {
            return;
        }

        $certification_id = (int) $wpdb->get_var(
            "SELECT id FROM {$p}certification WHERE actif = 1 ORDER BY id ASC LIMIT 1"
        );

        $parcours = [
            [
                'titre'    => 'Appréciation des risques',
                'resume'   => 'Identifier, analyser et traiter les risques : le cœur du SMSI.',
                'domaines' => [ 'D3' ],
                'nombre'   => 10,
            ],
            [
                'titre'    => 'Fondamentaux et exigences',
                'resume'   => "Les bases de la sécurité de l'information et les exigences de la norme.",
                'domaines' => [ 'D1', 'D2' ],
                'nombre'   => 12,
            ],
            [
                'titre'    => 'Mise en œuvre et surveillance',
                'resume'   => 'Déployer le SMSI, puis mesurer et évaluer son efficacité.',
                'domaines' => [ 'D4', 'D5' ],
                'nombre'   => 12,
            ],
            [
                'titre'    => 'Audit et amélioration continue',
                'resume'   => "Préparer la certification : audit interne et boucle d'amélioration.",
                'domaines' => [ 'D6', 'D7' ],
                'nombre'   => 10,
            ],
        ];

        $position = 1;
        foreach ( $parcours as $par ) {
            $wpdb->insert( "{$p}parcours", [
                'certification_id' => $certification_id ?: null,
                'titre'            => $par['titre'],
                'resume'           => $par['resume'],
                'domaines'         => wp_json_encode( $par['domaines'] ),
                'nombre'           => $par['nombre'],
                'statut'           => 'publie',
                'position'         => $position++,
                'date_creation'    => current_time( 'mysql' ),
            ] );
        }
    }
}
