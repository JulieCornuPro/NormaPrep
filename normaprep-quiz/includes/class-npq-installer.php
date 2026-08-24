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

    /** Option portant l'empreinte du schéma effectivement appliqué. */
    const OPT_EMPREINTE = 'npq_schema_empreinte';

    /**
     * Applique le schéma s'il a changé depuis la dernière fois.
     *
     * Le déclencheur est l'EMPREINTE des définitions de tables, pas le numéro
     * de version du plugin.
     *
     * L'ancien mécanisme comparait npq_db_version à NPQ_VERSION. Il reposait
     * donc sur une discipline humaine : ne jamais réutiliser un numéro de
     * version déjà déployé. Cette discipline a été prise en défaut, et
     * l'échec était SILENCIEUX — le schéma n'était pas appliqué, sans le
     * moindre signal. Une empreinte ne s'oublie pas : si le texte des
     * définitions change d'un caractère, dbDelta est rejoué ; s'il ne change
     * pas, il n'y a rien à faire, quel que soit le numéro de version.
     *
     * dbDelta() est non destructif : il ajoute les colonnes et index
     * manquants sans toucher aux données existantes.
     */
    public static function verifier_schema() {
        if ( get_option( self::OPT_EMPREINTE ) === self::empreinte() ) {
            return;
        }
        self::creer_tables();
    }

    /**
     * Définitions de toutes les tables du plugin, sous forme d'instructions
     * CREATE TABLE prêtes pour dbDelta.
     *
     * Extraites dans leur propre méthode pour pouvoir être EMPREINTÉES sans
     * être exécutées : c'est l'empreinte de ce texte qui décide si le schéma
     * doit être rejoué, et non plus le numéro de version du plugin.
     *
     * @return string[]
     */
    private static function definitions() {
        global $wpdb;

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
            ponderation TEXT NULL,
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
            secteur VARCHAR(30) NOT NULL DEFAULT 'transverse',
            statut VARCHAR(20) NOT NULL DEFAULT 'publie',
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY ref_externe (ref_externe),
            KEY certification_id (certification_id),
            KEY secteur (secteur)
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

        // --- Abonnements : TABLE HISTORIQUE, PLUS AUCUNE AUTORITÉ ------------
        //
        // Elle a gardé l'accès au produit jusqu'à la version 2.28.0. Depuis,
        // la bibliothèque (utilisateur_certification) est le registre UNIQUE
        // des droits : détenir une certification non expirée est le droit de
        // s'en servir.
        //
        // Conservée pour ne pas détruire l'historique de paiement qu'elle peut
        // porter, et parce qu'aucune donnée ne se supprime à la légère. Mais
        // plus une seule ligne de code ne la lit pour décider d'un accès —
        // hormis la migration qui a transféré son contenu.
        //
        // NE PAS LA REBRANCHER. Deux registres pour un même droit, c'est deux
        // occasions de désynchronisation, et un jour un client payant à la
        // porte. Toute mécanique de vente doit écrire dans la bibliothèque,
        // via NPQ_Bibliotheque::attribuer().
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

        // --- Bibliothèque : certifications acquises par un utilisateur ---
        // La présence d'une ligne vaut droit d'accès. fin_acces à NULL =
        // accès permanent ; une date = accès temporaire (prévu pour plus tard).
        $sql[] = "CREATE TABLE {$p}utilisateur_certification (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            utilisateur_id BIGINT UNSIGNED NOT NULL,
            certification_id BIGINT UNSIGNED NOT NULL,
            date_acquisition DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fin_acces DATE NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lien ( utilisateur_id, certification_id ),
            KEY certification_id ( certification_id )
        ) $charset;";

        /* =====================================================================
         * ACTIVITÉ (tentatives d'examen et réponses)
         * ===================================================================== */

        // --- Tentatives : une session d'examen passée par un utilisateur ---
        // examen_modele_id renseigné si l'examen vient d'un modèle ; sinon criteres
        // (JSON) décrit la génération à la volée.
        //
        // certification_id mémorise SUR QUELLE certification la tentative a été
        // passée. Sans cette colonne, l'historique et les statistiques ne peuvent
        // pas être segmentés : les codes de domaine (D1, D2…) étant réutilisés
        // d'une certification à l'autre, deux référentiels fusionneraient sous
        // le même libellé. On la lit plutôt que de remonter aux questions à
        // chaque requête. NULL = tentative ancienne dont l'origine n'a pas pu
        // être déterminée (voir migration_tentative_certification()).
        $sql[] = "CREATE TABLE {$p}tentative (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            utilisateur_id BIGINT UNSIGNED NOT NULL,
            certification_id BIGINT UNSIGNED NULL,
            examen_modele_id BIGINT UNSIGNED NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'libre',
            criteres LONGTEXT NULL,
            score SMALLINT UNSIGNED NULL,
            reussi TINYINT(1) NULL,
            date_debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_fin DATETIME NULL,
            PRIMARY KEY  (id),
            KEY utilisateur_id (utilisateur_id),
            KEY certification_id (certification_id),
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

        return $sql;
    }

    /**
     * Applique le schéma : crée les tables manquantes et ajoute les colonnes
     * et index absents. Non destructif — dbDelta ne supprime rien.
     *
     * Appelée à l'activation, et par verifier_schema() dès que les définitions
     * ci-dessus changent.
     */
    public static function creer_tables() {
        // dbDelta() vit dans un fichier WordPress qui n'est pas chargé par défaut.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $definitions = self::definitions();
        foreach ( $definitions as $requete ) {
            dbDelta( $requete );
        }

        // Amorçage : recrée une seule fois les 4 parcours qui étaient figés
        // dans le code, pour ne pas partir d'une page Révisions vide. Ne
        // s'exécute que si la table est vide (idempotent : pas de doublons si
        // creer_tables est rappelée).
        self::amorcer_parcours();

        // Empreinte du schéma appliqué : c'est elle qui sert de témoin.
        update_option( self::OPT_EMPREINTE, self::empreinte( $definitions ) );

        // Version du plugin au moment de l'application. Purement informatif —
        // affiché en administration, plus jamais utilisé pour DÉCIDER.
        update_option( 'npq_db_version', NPQ_VERSION );
    }

    /**
     * Empreinte du schéma : condensé du texte des définitions.
     *
     * @param string[]|null $definitions
     * @return string
     */
    public static function empreinte( $definitions = null ) {
        if ( $definitions === null ) {
            $definitions = self::definitions();
        }
        return md5( implode( "\n", $definitions ) );
    }

    /**
     * Rattache à leur certification les tentatives qui n'en portent pas encore.
     *
     * La colonne tentative.certification_id est apparue après coup : tout
     * l'historique déjà enregistré l'a à NULL. On la reconstitue par déductions
     * successives, de la plus fiable à la moins fiable — chaque passe ne touche
     * que les lignes encore NULL, donc l'ordre fait foi et la méthode est
     * idempotente (rejouée, elle ne défait rien).
     *
     *   1. Les questions réellement répondues : c'est la preuve directe.
     *   2. Le modèle d'examen utilisé, quand la tentative n'a aucune réponse
     *      (examen ouvert puis abandonné).
     *   3. Les questions mémorisées dans « criteres », qui couvrent aussi les
     *      tentatives abandonnées sans modèle.
     *   4. Repli : s'il n'existe qu'UNE seule certification en base, tout
     *      l'historique lui appartient forcément. Au-delà d'une, on s'abstient :
     *      mieux vaut une tentative sans certification qu'une tentative classée
     *      dans le mauvais référentiel.
     *
     * Publique et nommée « migration_ » : elle est appelée par le registre
     * NPQ_Migrations, sous la clé stable « tentative_certification ».
     *
     * @return int Nombre de tentatives rattachées.
     */
    public static function migration_tentative_certification() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $restantes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative WHERE certification_id IS NULL"
        );
        if ( $restantes < 1 ) {
            return 0; // rien à reprendre : cas courant après la première passe
        }

        // 1) D'après les questions répondues. MIN() suffit : une tentative ne
        //    mélange jamais deux certifications (la composition part toujours
        //    d'une seule).
        $wpdb->query(
            "UPDATE {$p}tentative t
             INNER JOIN (
                 SELECT r.tentative_id, MIN(q.certification_id) AS cid
                 FROM {$p}reponse r
                 INNER JOIN {$p}question q ON q.id = r.question_id
                 WHERE q.certification_id IS NOT NULL
                 GROUP BY r.tentative_id
             ) src ON src.tentative_id = t.id
             SET t.certification_id = src.cid
             WHERE t.certification_id IS NULL"
        );

        // 2) D'après le modèle d'examen, pour les tentatives sans réponse.
        $wpdb->query(
            "UPDATE {$p}tentative t
             INNER JOIN {$p}examen_modele m ON m.id = t.examen_modele_id
             SET t.certification_id = m.certification_id
             WHERE t.certification_id IS NULL
               AND m.certification_id IS NOT NULL"
        );

        // 3) D'après les ids de questions mémorisés dans « criteres ». Le JSON
        //    n'est pas interrogeable en SQL sur les vieilles versions de MySQL :
        //    on le décode côté PHP, sur le reliquat seulement.
        $orphelines = (array) $wpdb->get_results(
            "SELECT id, criteres FROM {$p}tentative
             WHERE certification_id IS NULL AND criteres IS NOT NULL",
            ARRAY_A
        );

        foreach ( $orphelines as $ligne ) {
            $criteres = json_decode( (string) $ligne['criteres'], true );
            if ( ! is_array( $criteres ) || empty( $criteres['questions'] ) ) {
                continue;
            }

            $ids = array_filter( array_map( 'intval', (array) $criteres['questions'] ) );
            if ( empty( $ids ) ) {
                continue;
            }

            // %d répété autant de fois qu'il y a d'ids : jamais d'interpolation
            // directe dans la requête.
            $marqueurs = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $cid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT certification_id FROM {$p}question
                 WHERE id IN ( {$marqueurs} ) AND certification_id IS NOT NULL
                 LIMIT 1",
                $ids
            ) );

            if ( $cid ) {
                $wpdb->update(
                    "{$p}tentative",
                    [ 'certification_id' => $cid ],
                    [ 'id' => (int) $ligne['id'] ]
                );
            }
        }

        // 4) Repli mono-certification : sans ambiguïté possible, on rattache
        //    tout le reste. Les questions ont pu être supprimées depuis, ce qui
        //    rend les passes précédentes muettes — l'historique reste pourtant
        //    bien celui de l'unique certification en place.
        $nb_certifs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}certification" );
        if ( $nb_certifs === 1 ) {
            $certification_id = (int) $wpdb->get_var(
                "SELECT id FROM {$p}certification LIMIT 1"
            );
            if ( $certification_id ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$p}tentative SET certification_id = %d
                     WHERE certification_id IS NULL",
                    $certification_id
                ) );
            }
        }

        $reste = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative WHERE certification_id IS NULL"
        );

        return $restantes - $reste;
    }

    /**
     * Aligne la bibliothèque sur la vérité que portait la table `abonnement`.
     *
     * POURQUOI
     *
     * L'accès au produit était gardé par deux barrières superposées :
     * `abonnement` disait « est-ce un client payant ? », la bibliothèque
     * « à quelles certifications ? ». La bibliothèque étant par ailleurs
     * remplie automatiquement pour TOUS les utilisateurs à chaque chargement,
     * elle ne valait rien comme droit : c'est `abonnement` qui faisait seule
     * barrage.
     *
     * En faisant de la bibliothèque le registre unique, on doit donc y inscrire
     * ce que `abonnement` disait — sans quoi tout inscrit, payant ou non,
     * obtiendrait un accès permanent.
     *
     * CE QU'ELLE FAIT
     *
     *   1. Les utilisateurs ayant un abonnement actif conservent leur accès,
     *      dont la date de fin est reprise de l'abonnement (fin_periode).
     *      Un abonnement sans date reste un accès permanent.
     *   2. Les accès des utilisateurs SANS abonnement actif sont retirés :
     *      ils n'ont jamais été un droit, seulement le résidu de l'attribution
     *      automatique.
     *
     * PRUDENCE
     *
     * L'étape 2 supprime des lignes. Elles sont donc recopiées au préalable
     * dans une option, ce qui permet de rétablir à la main si le résultat
     * surprend. La sauvegarde est bornée : au-delà, on préfère ne pas gonfler
     * indéfiniment la table des options.
     *
     * @return array Compte-rendu : conserves, retires, sauvegardes.
     */
    public static function migration_aligner_acces_sur_abonnement() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Condition d'un abonnement en cours de validité. Écrite une fois,
        // réutilisée par les deux étapes pour qu'elles ne puissent pas diverger.
        $abonnement_actif = "a.statut = 'actif'
                             AND ( a.fin_periode IS NULL OR a.fin_periode >= CURDATE() )";

        // --- 1. Report des dates d'échéance sur les accès conservés ---------
        // MAX() : si un utilisateur porte plusieurs abonnements actifs, c'est
        // la fin la plus lointaine qui vaut. MAX ignore les NULL, d'où le
        // décompte séparé d'un éventuel abonnement sans date, qui l'emporte
        // sur toute date (accès permanent).
        $conserves = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$p}utilisateur_certification uc
             WHERE EXISTS (
                 SELECT 1 FROM {$p}abonnement a
                 WHERE a.utilisateur_id = uc.utilisateur_id AND {$abonnement_actif}
             )"
        );

        $wpdb->query(
            "UPDATE {$p}utilisateur_certification uc
             INNER JOIN (
                 SELECT a.utilisateur_id,
                        MAX( a.fin_periode ) AS fin,
                        SUM( CASE WHEN a.fin_periode IS NULL THEN 1 ELSE 0 END ) AS sans_fin
                 FROM {$p}abonnement a
                 WHERE {$abonnement_actif}
                 GROUP BY a.utilisateur_id
             ) src ON src.utilisateur_id = uc.utilisateur_id
             SET uc.fin_acces = CASE WHEN src.sans_fin > 0 THEN NULL ELSE src.fin END"
        );

        // --- 2. Retrait des accès sans abonnement derrière eux --------------
        // Sauvegarde avant suppression : ces lignes ne sont pas reconstituables
        // autrement, la table abonnement ne les mentionnant pas.
        $a_retirer = (array) $wpdb->get_results(
            "SELECT uc.utilisateur_id, uc.certification_id, uc.date_acquisition, uc.fin_acces
             FROM {$p}utilisateur_certification uc
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$p}abonnement a
                 WHERE a.utilisateur_id = uc.utilisateur_id AND {$abonnement_actif}
             )
             LIMIT 5000",
            ARRAY_A
        );

        if ( ! empty( $a_retirer ) ) {
            update_option( 'npq_acces_retires_sauvegarde', $a_retirer, false );
        }

        $retires = (int) $wpdb->query(
            "DELETE uc FROM {$p}utilisateur_certification uc
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$p}abonnement a
                 WHERE a.utilisateur_id = uc.utilisateur_id AND {$abonnement_actif}
             )"
        );

        $bilan = [
            'conserves'  => $conserves,
            'retires'    => $retires,
            'sauvegardes'=> count( $a_retirer ),
        ];

        // Compte-rendu affiché en administration : une migration qui touche
        // aux droits d'accès ne doit pas passer inaperçue.
        update_option( 'npq_alignement_acces_bilan', $bilan, false );

        return $bilan;
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
