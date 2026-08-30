<?php
/**
 * Registre des migrations de données.
 *
 * Une migration de données transforme l'existant : rattacher un historique,
 * recalculer une colonne, normaliser des valeurs. Elle ne doit s'exécuter
 * qu'une fois, et il faut pouvoir savoir si elle a eu lieu.
 *
 * POURQUOI CETTE CLASSE
 *
 * Les migrations étaient jusqu'ici déclenchées par une comparaison entre la
 * version enregistrée en base et celle du plugin :
 *
 *     if ( get_option( 'npq_db_version' ) !== NPQ_VERSION ) { ... }
 *
 * Ce mécanisme reposait sur une discipline humaine — ne jamais réutiliser un
 * numéro de version déjà déployé. La discipline a été prise en défaut, et le
 * mode d'échec était le pire possible : SILENCIEUX. Une version 2.23.8 avait
 * été déployée puis réutilisée pour un contenu différent ; la comparaison
 * était fausse, les migrations n'ont jamais tourné, et rien ne l'a signalé.
 * Soixante-et-onze tentatives sont restées orphelines sans qu'aucun écran
 * n'en fasse état.
 *
 * Le défaut de fond : l'état d'une migration était déduit d'une variable qui
 * ne la concerne pas. Ici, chaque migration porte son propre témoin, sous une
 * clé stable qui ne dépend d'aucun numéro de version. On peut renuméroter le
 * plugin, revenir en arrière, rejouer une version : une migration déjà faite
 * reste faite, une migration jamais faite finit par se faire.
 *
 * AJOUTER UNE MIGRATION
 *
 * Ajouter une entrée à liste(). La clé est définitive : la renommer ferait
 * rejouer la migration partout. Écrire la fonction de façon idempotente
 * malgré tout — une migration qu'on peut rejouer sans dégât est une migration
 * qu'on peut déboguer.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Migrations {

    /** Option stockant les clés des migrations déjà exécutées. */
    const OPTION = 'npq_migrations_faites';

    /**
     * Migrations connues, dans leur ordre d'exécution.
     *
     * Clé stable => appelable. L'ordre compte : une migration peut dépendre
     * du résultat d'une précédente.
     *
     * @return array<string, callable>
     */
    private static function liste() {
        return [
            // Rattache à leur certification les tentatives enregistrées avant
            // l'existence de la colonne tentative.certification_id.
            'tentative_certification' => [ 'NPQ_Installer', 'migration_tentative_certification' ],

            // Fait de la bibliothèque le registre unique des droits d'accès,
            // en y inscrivant ce que la table `abonnement` disait jusqu'ici.
            // Retire les accès qui n'étaient que le résidu de l'attribution
            // automatique. Sauvegarde les lignes retirées avant suppression.
            'alignement_acces' => [ 'NPQ_Installer', 'migration_aligner_acces_sur_abonnement' ],

            // Nettoie les réponses enregistrées en double au sein d'une même
            // tentative, héritées d'une correction jouée deux fois avant que
            // le verrou d'écriture ne soit posé (une révision de 10 questions
            // affichait 20 blocs de correction).
            'reponses_dupliquees' => [ 'NPQ_Installer', 'migration_supprimer_reponses_dupliquees' ],
        ];
    }

    /**
     * Clés des migrations déjà exécutées.
     *
     * @return string[]
     */
    public static function faites() {
        $faites = get_option( self::OPTION, [] );
        return is_array( $faites ) ? $faites : [];
    }

    /**
     * Cette migration a-t-elle déjà été exécutée ?
     *
     * @param string $cle
     * @return bool
     */
    public static function est_faite( $cle ) {
        return in_array( $cle, self::faites(), true );
    }

    /**
     * Migrations connues mais pas encore exécutées.
     *
     * @return string[]
     */
    public static function en_attente() {
        return array_values( array_diff( array_keys( self::liste() ), self::faites() ) );
    }

    /**
     * Exécute les migrations en attente, dans l'ordre.
     *
     * Appelée à chaque chargement du plugin. Le cas courant — rien à faire —
     * coûte une lecture d'option déjà en cache et une comparaison de tableaux ;
     * on ne touche à la base que s'il reste effectivement du travail.
     *
     * Une migration qui échoue n'est PAS marquée comme faite : elle sera
     * retentée au chargement suivant. C'est le comportement voulu — mieux vaut
     * réessayer que graver un échec.
     *
     * @return string[] Clés des migrations exécutées lors de cet appel.
     */
    public static function executer() {
        $en_attente = self::en_attente();
        if ( empty( $en_attente ) ) {
            return [];
        }

        $liste     = self::liste();
        $faites    = self::faites();
        $executees = [];

        foreach ( $en_attente as $cle ) {
            if ( ! isset( $liste[ $cle ] ) || ! is_callable( $liste[ $cle ] ) ) {
                continue;
            }

            call_user_func( $liste[ $cle ] );

            $faites[]    = $cle;
            $executees[] = $cle;
        }

        if ( ! empty( $executees ) ) {
            update_option( self::OPTION, array_values( array_unique( $faites ) ) );
        }

        return $executees;
    }

    /**
     * Marque une migration comme faite sans l'exécuter.
     *
     * Sert au cas d'une installation où le travail a déjà été accompli par
     * l'ancien mécanisme : inutile de le refaire. À n'employer que pour une
     * migration dont on sait qu'elle est sans objet.
     *
     * @param string $cle
     */
    public static function marquer_faite( $cle ) {
        if ( self::est_faite( $cle ) ) {
            return;
        }
        $faites   = self::faites();
        $faites[] = $cle;
        update_option( self::OPTION, array_values( array_unique( $faites ) ) );
    }
}
