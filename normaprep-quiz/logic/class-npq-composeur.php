<?php
/**
 * Composition d'un examen : sélectionne un ensemble de questions selon un mode.
 *
 * Modes disponibles :
 *   - par scénario   : toutes les questions d'un scénario donné
 *   - par domaine    : toutes les questions d'un domaine (D1..D7) d'une certification
 *   - par tag        : toutes les questions portant un tag (article ISO, compétence...)
 *   - aléatoire      : un tirage sur toute une certification
 *   - modèle         : les questions d'un modèle d'examen prédéfini (type 'fige')
 *
 * Chaque méthode retourne un tableau de questions complètes (avec leurs options),
 * prêtes à être affichées ou enregistrées dans une tentative.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Composeur {

    /**
     * Compose par SCÉNARIO : toutes les questions publiées d'un scénario.
     *
     * @param int  $scenario_id Id interne du scénario.
     * @param bool $melanger    Mélanger l'ordre des questions.
     * @return array Liste de questions complètes.
     */
    public static function par_scenario( $scenario_id, $melanger = false ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}question
             WHERE scenario_id = %d AND statut = 'publie'
             ORDER BY id ASC",
            $scenario_id
        ) );

        return self::assembler( $ids, $melanger );
    }

    /**
     * Compose par DOMAINE : toutes les questions d'un domaine pour une certification.
     *
     * @param int    $certification_id Id de la certification.
     * @param string $domaine          Code du domaine (ex. 'D3').
     * @param bool   $melanger
     * @return array
     */
    public static function par_domaine( $certification_id, $domaine, $melanger = false ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}question
             WHERE certification_id = %d AND domaine = %s AND statut = 'publie'
             ORDER BY id ASC",
            $certification_id, $domaine
        ) );

        return self::assembler( $ids, $melanger );
    }

    /**
     * Compose par TAG : toutes les questions portant un tag donné.
     * Le tag est identifié par son type (ex. 'annexA_controls') et sa valeur (ex. '5.23').
     *
     * @param string $type_nom
     * @param string $valeur
     * @param int    $limite   Nombre max de questions (0 = pas de limite).
     * @param bool   $melanger
     * @return array
     */
    /**
     * Compose une session de révision : plusieurs domaines à la fois, nombre limité.
     *
     * Les questions sont tirées au hasard parmi les domaines choisis, ce qui évite
     * de toujours réviser les mêmes. Si le nombre demandé dépasse ce qui existe,
     * on renvoie tout ce qui existe.
     *
     * @param int   $certification_id
     * @param array $domaines Codes des domaines (ex : ['D1','D3']). Vide = tous.
     * @param int   $nombre   Nombre de questions souhaité (0 = toutes).
     * @return array Questions assemblées.
     */
    public static function par_domaines( $certification_id, $domaines = [], $nombre = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $domaines = array_filter( array_map( 'strval', (array) $domaines ) );

        if ( ! empty( $domaines ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $domaines ), '%s' ) );
            $args = array_merge( [ $certification_id ], $domaines );
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}question
                 WHERE certification_id = %d
                   AND domaine IN ( $placeholders )
                   AND statut = 'publie'
                 ORDER BY RAND()",
                $args
            ) );
        } else {
            // Aucun domaine choisi : on pioche dans toute la certification.
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}question
                 WHERE certification_id = %d AND statut = 'publie'
                 ORDER BY RAND()",
                $certification_id
            ) );
        }

        // Limite au nombre demandé.
        if ( $nombre > 0 && count( $ids ) > $nombre ) {
            $ids = array_slice( $ids, 0, $nombre );
        }

        return self::assembler( $ids, false );
    }

    /**
     * Compte les questions disponibles pour un ensemble de domaines.
     * Sert à informer le candidat avant qu'il compose sa révision.
     */
    public static function compter_domaines( $certification_id, $domaines = [] ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $domaines = array_filter( array_map( 'strval', (array) $domaines ) );

        if ( empty( $domaines ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}question
                 WHERE certification_id = %d AND statut = 'publie'",
                $certification_id
            ) );
        }

        $placeholders = implode( ',', array_fill( 0, count( $domaines ), '%s' ) );
        $args = array_merge( [ $certification_id ], $domaines );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}question
             WHERE certification_id = %d
               AND domaine IN ( $placeholders )
               AND statut = 'publie'",
            $args
        ) );
    }

    public static function par_tag( $type_nom, $valeur, $limite = 0, $melanger = false ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT q.id
             FROM {$p}question q
             INNER JOIN {$p}question_tag qt ON qt.question_id = q.id
             INNER JOIN {$p}tag t           ON t.id = qt.tag_id
             INNER JOIN {$p}tag_type tt      ON tt.id = t.tag_type_id
             WHERE tt.nom = %s AND t.valeur = %s AND q.statut = 'publie'
             ORDER BY q.id ASC",
            $type_nom, $valeur
        ) );

        if ( $limite > 0 && count( $ids ) > $limite ) {
            $ids = array_slice( $ids, 0, $limite );
        }

        return self::assembler( $ids, $melanger );
    }

    /**
     * Compose ALÉATOIREMENT : tirage de N questions sur une certification.
     *
     * @param int  $certification_id
     * @param int  $nombre           Nombre de questions voulu.
     * @param bool $melanger         Sans effet ici (déjà aléatoire), gardé pour cohérence.
     * @return array
     */
    public static function aleatoire( $certification_id, $nombre = 20, $melanger = true ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // RAND() effectue le tirage aléatoire côté base de données.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}question
             WHERE certification_id = %d AND statut = 'publie'
             ORDER BY RAND()
             LIMIT %d",
            $certification_id, $nombre
        ) );

        return self::assembler( $ids, false );
    }

    /**
     * Compose depuis un MODÈLE d'examen prédéfini (liste de questions figée).
     *
     * @param int  $examen_modele_id
     * @param bool $melanger
     * @return array
     */
    public static function par_modele( $examen_modele_id, $melanger = false ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT eq.question_id
             FROM {$p}examen_question eq
             INNER JOIN {$p}question q ON q.id = eq.question_id
             WHERE eq.examen_modele_id = %d AND q.statut = 'publie'
             ORDER BY eq.position ASC",
            $examen_modele_id
        ) );

        return self::assembler( $ids, $melanger );
    }

    /**
     * Assemble la liste finale : pour chaque id de question, récupère la question
     * complète et ses options. Mélange l'ordre si demandé.
     *
     * @param array $ids      Identifiants de questions.
     * @param bool  $melanger
     * @return array Liste de questions, chacune avec sa clé 'options'.
     */
    private static function assembler( $ids, $melanger = false ) {
        if ( empty( $ids ) ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        if ( $melanger ) {
            shuffle( $ids );
        }

        $questions = [];
        foreach ( $ids as $qid ) {
            $question = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, certification_id, scenario_id, domaine, enonce,
                        multi_reponses, explication, difficulte
                 FROM {$p}question WHERE id = %d",
                $qid
            ), ARRAY_A );

            if ( ! $question ) {
                continue;
            }

            // Options associées, dans leur ordre de position.
            // Note : on ne renvoie PAS l'information 'correcte' ici, pour éviter
            // qu'elle transite vers le navigateur pendant l'examen. La correction
            // se fera côté serveur au moment de la validation.
            $question['options'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, texte, position
                 FROM {$p}option_reponse
                 WHERE question_id = %d
                 ORDER BY position ASC",
                $qid
            ), ARRAY_A );

            $questions[] = $question;
        }

        return $questions;
    }

    /**
     * Compte le nombre de questions disponibles pour un mode donné, sans les charger.
     * Utile pour afficher « 12 questions disponibles » avant de lancer un examen.
     *
     * @param string $mode   'scenario' | 'domaine' | 'tag' | 'certification'
     * @param array  $params Paramètres selon le mode.
     * @return int
     */
    public static function compter( $mode, $params ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        switch ( $mode ) {
            case 'scenario':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}question
                     WHERE scenario_id = %d AND statut = 'publie'",
                    $params['scenario_id']
                ) );

            case 'domaine':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}question
                     WHERE certification_id = %d AND domaine = %s AND statut = 'publie'",
                    $params['certification_id'], $params['domaine']
                ) );

            case 'tag':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT q.id)
                     FROM {$p}question q
                     INNER JOIN {$p}question_tag qt ON qt.question_id = q.id
                     INNER JOIN {$p}tag t           ON t.id = qt.tag_id
                     INNER JOIN {$p}tag_type tt      ON tt.id = t.tag_type_id
                     WHERE tt.nom = %s AND t.valeur = %s AND q.statut = 'publie'",
                    $params['type_nom'], $params['valeur']
                ) );

            case 'certification':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}question
                     WHERE certification_id = %d AND statut = 'publie'",
                    $params['certification_id']
                ) );
        }

        return 0;
    }
}
