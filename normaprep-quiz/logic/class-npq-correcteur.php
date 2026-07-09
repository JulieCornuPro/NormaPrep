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
     * Corrige une TENTATIVE complète et enregistre les résultats en base.
     *
     * Attend les réponses de l'utilisateur sous la forme :
     *   [ question_id => [option_id, option_id, ...], ... ]
     *
     * @param int   $tentative_id Id de la tentative en cours.
     * @param array $reponses     Réponses de l'utilisateur.
     * @param int   $seuil        Seuil de réussite en pourcentage (défaut 70).
     * @return array Bilan : score, reussi, nb_correctes, total, par_domaine.
     */
    public static function corriger_tentative( $tentative_id, $reponses, $seuil = self::SEUIL_DEFAUT ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $total       = 0;
        $correctes   = 0;
        $par_domaine = []; // domaine => ['correctes' => x, 'total' => y]

        foreach ( $reponses as $question_id => $options_cochees ) {
            $question_id = (int) $question_id;

            // Domaine de la question (pour le score par domaine).
            $domaine = $wpdb->get_var( $wpdb->prepare(
                "SELECT domaine FROM {$p}question WHERE id = %d",
                $question_id
            ) );
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
            $wpdb->insert( "{$p}reponse", [
                'tentative_id' => $tentative_id,
                'question_id'  => $question_id,
                'correcte'     => $est_correcte ? 1 : 0,
            ] );
            $reponse_id = $wpdb->insert_id;

            // Enregistre chaque option cochée (gère le multi-réponses).
            foreach ( (array) $options_cochees as $option_id ) {
                $wpdb->insert( "{$p}reponse_option", [
                    'reponse_id' => $reponse_id,
                    'option_id'  => (int) $option_id,
                ] );
            }
        }

        // Score global en pourcentage (arrondi à l'entier).
        $score  = ( $total > 0 ) ? (int) round( $correctes * 100 / $total ) : 0;
        $reussi = ( $score >= $seuil ) ? 1 : 0;

        // Met à jour la tentative avec le résultat final.
        $wpdb->update( "{$p}tentative",
            [
                'score'    => $score,
                'reussi'   => $reussi,
                'date_fin' => current_time( 'mysql' ),
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
             FROM {$p}reponse WHERE tentative_id = %d",
            $tentative_id
        ), ARRAY_A );

        $detail = [];
        foreach ( $reponses as $rep ) {
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
