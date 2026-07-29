<?php
/**
 * Pondération de l'examen blanc, par certification.
 *
 * L'examen blanc tire ses questions selon une répartition officielle par
 * domaine (ex. PECB : D1→15, D2→12…). Cette répartition n'est pas propre au
 * contenu : c'est l'organisme de certification qui la fixe. Elle doit donc être
 * saisie et stockée par certification, et non déduite du nombre de questions.
 *
 * Cette classe centralise la lecture et l'écriture de cette pondération, de la
 * même façon que NPQ_Certification centralise la certification courante. La
 * pondération est stockée en JSON dans la colonne `ponderation` de la table
 * certification : { "D1": 15, "D2": 12, ... }.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Ponderation {

    /**
     * Pondération historique PECB pour ISO 27001 Lead Implementer.
     * Sert uniquement de REPLI si une certification n'a pas encore de
     * pondération enregistrée (rétro-compatibilité au déploiement).
     */
    const REPLI_LEAD_IMPLEMENTER = [
        'D1' => 15,
        'D2' => 12,
        'D3' => 18,
        'D4' => 14,
        'D5' => 10,
        'D6' => 6,
        'D7' => 5,
    ];

    /**
     * Pondération d'une certification : tableau code_domaine => nombre.
     * Vide si aucune n'est définie et qu'aucun repli ne s'applique.
     *
     * @param int $certification_id
     * @return array
     */
    public static function de( $certification_id ) {
        $certification_id = (int) $certification_id;
        if ( ! $certification_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $brut = $wpdb->get_var( $wpdb->prepare(
            "SELECT ponderation FROM {$p}certification WHERE id = %d",
            $certification_id
        ) );

        if ( ! empty( $brut ) ) {
            $decode = json_decode( $brut, true );
            if ( is_array( $decode ) && ! empty( $decode ) ) {
                // On ne garde que des entiers positifs, indexés par code.
                $propre = [];
                foreach ( $decode as $code => $nb ) {
                    $nb = (int) $nb;
                    if ( $nb > 0 ) {
                        $propre[ (string) $code ] = $nb;
                    }
                }
                return $propre;
            }
        }

        // Repli : la certification active historique retombe sur la pondération
        // PECB Lead Implementer, pour ne pas casser l'examen blanc existant tant
        // qu'aucune pondération n'a été saisie.
        return self::repli_pour( $certification_id );
    }

    /**
     * Total de questions d'une pondération (ex. 80 pour PECB).
     *
     * @param int $certification_id
     * @return int
     */
    public static function total( $certification_id ) {
        return (int) array_sum( self::de( $certification_id ) );
    }

    /**
     * Enregistre la pondération d'une certification.
     * Les valeurs nulles ou négatives sont retirées.
     *
     * @param int   $certification_id
     * @param array $ponderation  code_domaine => nombre.
     * @return bool
     */
    public static function enregistrer( $certification_id, $ponderation ) {
        $certification_id = (int) $certification_id;
        if ( ! $certification_id ) {
            return false;
        }

        $propre = [];
        foreach ( (array) $ponderation as $code => $nb ) {
            $nb = (int) $nb;
            if ( $nb > 0 ) {
                $propre[ (string) $code ] = $nb;
            }
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->update(
            "{$p}certification",
            [ 'ponderation' => wp_json_encode( $propre ) ],
            [ 'id' => $certification_id ]
        );

        return true;
    }

    /**
     * Une pondération est-elle définie pour cette certification ?
     * (Distinct du repli : renvoie vrai seulement si une valeur est stockée.)
     *
     * @param int $certification_id
     * @return bool
     */
    public static function est_definie( $certification_id ) {
        $certification_id = (int) $certification_id;
        if ( ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $brut = $wpdb->get_var( $wpdb->prepare(
            "SELECT ponderation FROM {$p}certification WHERE id = %d",
            $certification_id
        ) );

        if ( empty( $brut ) ) {
            return false;
        }
        $decode = json_decode( $brut, true );
        return ( is_array( $decode ) && ! empty( $decode ) );
    }

    /**
     * Repli pour une certification sans pondération enregistrée.
     * Seule la certification dont le code correspond à l'historique Lead
     * Implementer reçoit la pondération PECB ; les autres n'ont pas de repli
     * (il faut la saisir).
     *
     * @param int $certification_id
     * @return array
     */
    private static function repli_pour( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $code = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT code FROM {$p}certification WHERE id = %d",
            (int) $certification_id
        ) );

        // Codes historiques probables de Lead Implementer.
        $codes_li = [ 'LI27001', 'ISO27001LI', 'LEADIMPL', 'LI' ];

        if ( in_array( strtoupper( $code ), $codes_li, true ) ) {
            return self::REPLI_LEAD_IMPLEMENTER;
        }

        return [];
    }
}
