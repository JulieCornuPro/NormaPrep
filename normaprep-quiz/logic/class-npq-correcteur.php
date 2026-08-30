<?php
/**
 * Correction d'un examen : évalue les réponses, calcule le score et la réussite.
 *
 * Règles :
 *   - Réponse unique   : juste si l'unique option cochée est la bonne.
 *   - Réponses multiples : « tout ou rien » — juste seulement si TOUTES les bonnes
 *     options sont cochées et AUCUNE mauvaise.
 *   - Score global : pourcentage de questions justes.
 *   - Réussite : score >= seuil (configurable, 70 % par défaut).
 *   - Score par domaine : calculé pour alimenter le suivi de progression.
 *
 * La correction se fait entièrement côté serveur : la liste des bonnes réponses
 * n'est jamais exposée au navigateur pendant l'examen.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Correcteur {

    /** Seuil de réussite par défaut, en pourcentage. */
    const SEUIL_DEFAUT = 70;

    /**
     * Corrige UNE question : compare les options cochées aux bonnes réponses.
     *
     * @param int   $question_id     Id de la question.
     * @param array $options_cochees Ids des options cochées par l'utilisateur.
     * @return bool  true si la réponse est entièrement correcte.
     */
    public static function corriger_question( $question_id, $options_cochees ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Ids des bonnes options de cette question.
        $bonnes = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}option_reponse
             WHERE question_id = %d AND correcte = 1",
            $question_id
        ) );

        // Normalisation en entiers pour une comparaison fiable.
        $bonnes  = array_map( 'intval', $bonnes );
        $cochees = array_map( 'intval', (array) $options_cochees );

        // On retire les doublons éventuels et on trie pour comparer les ensembles.
        $bonnes  = array_values( array_unique( $bonnes ) );
        $cochees = array_values( array_unique( $cochees ) );
        sort( $bonnes );
        sort( $cochees );

        // Tout ou rien : les deux ensembles doivent être strictement identiques.
        // Cela couvre à la fois la réponse unique et les réponses multiples.
        return $bonnes === $cochees && ! empty( $bonnes );
    }

    /**
     * Liste des questions composant une tentative, dans l'ordre.
     *
     * La composition est figée au démarrage dans la colonne `criteres` : c'est
     * elle qui fait foi, et non les réponses effectivement données.
     *
     * @param int $tentative_id
     * @return int[]
     */
    private static function ids_questions( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $criteres = $wpdb->get_var( $wpdb->prepare(
            "SELECT criteres FROM {$p}tentative WHERE id = %d",
            (int) $tentative_id
        ) );

        $data = json_decode( (string) $criteres, true );
        if ( ! isset( $data['questions'] ) || ! is_array( $data['questions'] ) ) {
            return [];
        }

        return array_values( array_filter( array_map( 'intval', $data['questions'] ) ) );
    }

    /**
     * Corrige une TENTATIVE complète et enregistre les résultats en base.
     *
     * Attend les réponses de l'utilisateur sous la forme :
     *   [ question_id => [option_id, option_id, ...], ... ]
     *
     * Le dénominateur du score est le nombre de questions COMPOSANT l'examen,
     * pas le nombre de questions répondues. C'est la règle d'une épreuve : une
     * question laissée blanche est fausse, elle n'est pas retirée du barème.
     *
     * Auparavant la boucle parcourait les seules réponses reçues : un candidat
     * qui répondait juste à une question puis rendait copie obtenait 100 %.
     *
     * @param int   $tentative_id Id de la tentative en cours.
     * @param array $reponses     Réponses de l'utilisateur.
     * @param int   $seuil        Seuil de réussite en pourcentage (défaut 70).
     * @return array Bilan : score, reussi, nb_correctes, total, par_domaine.
     */
    public static function corriger_tentative( $tentative_id, $reponses, $seuil = self::SEUIL_DEFAUT ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $tentative_id = (int) $tentative_id;

        // Une tentative ne se corrige qu'UNE fois. On RÉSERVE la tentative
        // avant d'écrire la moindre réponse : un UPDATE conditionnel est
        // atomique — la base verrouille la ligne et ne l'accorde qu'à un seul
        // appelant.
        //
        // Le garde-fou précédent lisait date_fin, mais date_fin n'était posée
        // qu'APRÈS l'insertion de toutes les réponses : entre la lecture et
        // l'écriture, la porte restait ouverte le temps d'une correction
        // entière. Deux « Terminer » partis ensemble — double clic, POST
        // rejoué, ou script d'examen évalué en double, qui pose deux écouteurs
        // sur le même bouton — franchissaient tous deux le contrôle et
        // inséraient chacun une ligne par question. La révision se terminait
        // avec deux fois plus de réponses que de questions : correction
        // détaillée en double, et fractions par domaine impossibles (16/8).
        //
        // Poser date_fin d'abord ferme la fenêtre. Si la correction échouait
        // ensuite, la tentative resterait sans score — visible comme telle,
        // là où des réponses en double faussent silencieusement l'historique.
        $reserve = $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}tentative SET date_fin = %s
             WHERE id = %d AND date_fin IS NULL",
            current_time( 'mysql' ),
            $tentative_id
        ) );

        if ( ! $reserve ) {
            // Tentative déjà close : corrigée, abandonnée, ou correction
            // concurrente en cours. On rend l'état enregistré sans rien écrire.
            $deja = $wpdb->get_row( $wpdb->prepare(
                "SELECT score, reussi FROM {$p}tentative WHERE id = %d",
                $tentative_id
            ), ARRAY_A );

            return [
                'score'       => ( $deja && $deja['score'] !== null ) ? (int) $deja['score'] : null,
                'reussi'      => ( $deja && $deja['reussi'] !== null ) ? (bool) $deja['reussi'] : null,
                'correctes'   => null,
                'total'       => null,
                'seuil'       => $seuil,
                'par_domaine' => [],
                'deja_corrigee' => true,
            ];
        }

        // Ardoise nette. Une correction interrompue en cours de route (erreur
        // fatale, coupure) a pu laisser des lignes derrière elle ; les garder
        // ferait double emploi avec celles qu'on s'apprête à écrire.
        self::effacer_reponses( $tentative_id );

        $total       = 0;
        $correctes   = 0;
        $par_domaine = []; // domaine => ['correctes' => x, 'total' => y]

        $reponses = (array) $reponses;

        // Toutes les questions de l'examen. Repli sur les seules réponses
        // reçues si la composition est introuvable (tentative ancienne ou
        // critères corrompus) : mieux vaut un score approché que pas de score.
        $questions = self::ids_questions( $tentative_id );
        if ( empty( $questions ) ) {
            $questions = array_map( 'intval', array_keys( $reponses ) );
        }

        // Une question ne compte qu'une fois, même si la composition en base
        // la mentionne deux fois : sinon elle pèserait double au barème et
        // apparaîtrait deux fois dans la correction détaillée.
        $questions = array_values( array_unique( $questions ) );

        // Domaines en UNE requête plutôt qu'une par question : la boucle porte
        // désormais sur 80 questions et non plus sur les seules répondues.
        $domaines = [];
        if ( ! empty( $questions ) ) {
            $marqueurs = implode( ',', array_fill( 0, count( $questions ), '%d' ) );
            $lignes = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, domaine FROM {$p}question WHERE id IN ( {$marqueurs} )",
                $questions
            ), ARRAY_A );
            foreach ( (array) $lignes as $l ) {
                $domaines[ (int) $l['id'] ] = $l['domaine'];
            }
        }

        foreach ( $questions as $question_id ) {
            $question_id = (int) $question_id;

            // Question sans réponse : tableau vide. corriger_question() la
            // déclare fausse, ce qui est le comportement attendu.
            $options_cochees = isset( $reponses[ $question_id ] )
                ? (array) $reponses[ $question_id ]
                : [];

            $domaine = isset( $domaines[ $question_id ] ) ? $domaines[ $question_id ] : '';
            if ( ! isset( $par_domaine[ $domaine ] ) ) {
                $par_domaine[ $domaine ] = [ 'correctes' => 0, 'total' => 0 ];
            }

            $est_correcte = self::corriger_question( $question_id, $options_cochees );

            $total++;
            $par_domaine[ $domaine ]['total']++;
            if ( $est_correcte ) {
                $correctes++;
                $par_domaine[ $domaine ]['correctes']++;
            }

            // Enregistre la réponse (une ligne par question dans la tentative).
            // Y compris pour les questions laissées blanches : l'écran de
            // correction doit les montrer, et le taux par domaine les compter.
            $wpdb->insert( "{$p}reponse", [
                'tentative_id' => $tentative_id,
                'question_id'  => $question_id,
                'correcte'     => $est_correcte ? 1 : 0,
            ] );
            $reponse_id = $wpdb->insert_id;

            // Enregistre chaque option cochée (gère le multi-réponses).
            // Une question blanche n'en a aucune : c'est ce qui permet, plus
            // tard, de distinguer « pas su » de « pas vue ».
            foreach ( $options_cochees as $option_id ) {
                $wpdb->insert( "{$p}reponse_option", [
                    'reponse_id' => $reponse_id,
                    'option_id'  => (int) $option_id,
                ] );
            }
        }

        // Score global en pourcentage (arrondi à l'entier).
        $score  = ( $total > 0 ) ? (int) round( $correctes * 100 / $total ) : 0;
        $reussi = ( $score >= $seuil ) ? 1 : 0;

        // Met à jour la tentative avec le résultat final. date_fin a déjà été
        // posée par la réservation, en tête de méthode : on ne la réécrit pas.
        $wpdb->update( "{$p}tentative",
            [
                'score'  => $score,
                'reussi' => $reussi,
            ],
            [ 'id' => $tentative_id ]
        );

        // Calcule le pourcentage par domaine (pour le tableau de bord).
        $domaines_pct = [];
        foreach ( $par_domaine as $dom => $stats ) {
            $domaines_pct[ $dom ] = ( $stats['total'] > 0 )
                ? (int) round( $stats['correctes'] * 100 / $stats['total'] )
                : 0;
        }

        return [
            'score'       => $score,
            'reussi'      => (bool) $reussi,
            'correctes'   => $correctes,
            'total'       => $total,
            'seuil'       => $seuil,
            'par_domaine' => $domaines_pct,
        ];
    }

    /**
     * Efface les réponses déjà enregistrées pour une tentative, options cochées
     * comprises. Les deux tables sont liées à la main (pas de clé étrangère) :
     * supprimer les réponses sans leurs options laisserait des orphelines.
     *
     * @param int $tentative_id
     */
    private static function effacer_reponses( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $tentative_id = (int) $tentative_id;

        // Les options d'abord : une fois les réponses parties, plus rien ne
        // permet de retrouver à quelle tentative ces options appartenaient.
        $wpdb->query( $wpdb->prepare(
            "DELETE ro FROM {$p}reponse_option ro
             INNER JOIN {$p}reponse r ON r.id = ro.reponse_id
             WHERE r.tentative_id = %d",
            $tentative_id
        ) );

        $wpdb->delete( "{$p}reponse", [ 'tentative_id' => $tentative_id ] );
    }

    /**
     * Prépare la CORRECTION DÉTAILLÉE d'une tentative déjà corrigée, pour affichage.
     * Pour chaque question : énoncé, options avec le bon/mauvais, ce que l'utilisateur
     * a coché, et l'explication. C'est ici que la bonne réponse est enfin révélée.
     *
     * @param int $tentative_id
     * @return array Liste détaillée par question.
     */
    public static function detail_correction( $tentative_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $reponses = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question_id, correcte
             FROM {$p}reponse WHERE tentative_id = %d
             ORDER BY id ASC",
            $tentative_id
        ), ARRAY_A );

        $detail = [];
        $vues   = []; // question_id => déjà présentée

        foreach ( $reponses as $rep ) {
            // Filet pour l'historique : les tentatives corrigées avant la pose
            // du verrou d'écriture peuvent porter la même question deux fois.
            // On garde la première ligne — l'affichage doit montrer autant de
            // blocs que de questions posées, pas autant que de lignes en base.
            $qid = (int) $rep['question_id'];
            if ( isset( $vues[ $qid ] ) ) {
                continue;
            }
            $vues[ $qid ] = true;

            $question = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, enonce, explication, domaine, multi_reponses
                 FROM {$p}question WHERE id = %d",
                $rep['question_id']
            ), ARRAY_A );

            // Toutes les options de la question, avec leur statut correct/incorrect.
            $options = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, texte, correcte, position
                 FROM {$p}option_reponse
                 WHERE question_id = %d ORDER BY position ASC",
                $rep['question_id']
            ), ARRAY_A );

            // Options que l'utilisateur avait cochées.
            $cochees = $wpdb->get_col( $wpdb->prepare(
                "SELECT option_id FROM {$p}reponse_option WHERE reponse_id = %d",
                $rep['id']
            ) );
            $cochees = array_map( 'intval', $cochees );

            // Marque chaque option : cochée ou non par l'utilisateur.
            foreach ( $options as &$opt ) {
                $opt['cochee'] = in_array( (int) $opt['id'], $cochees, true );
            }
            unset( $opt );

            $detail[] = [
                'question'  => $question,
                'options'   => $options,
                'correcte'  => (bool) $rep['correcte'],
            ];
        }

        return $detail;
    }
}
