<?php
/**
 * Édition de la pondération de l'examen blanc, pour une certification donnée.
 *
 * Affiche chaque domaine de la certification avec un champ « nombre de
 * questions », et un total mis à jour en direct. La pondération est la
 * répartition officielle de l'organisme (ex. PECB) : elle est saisie, pas
 * déduite.
 *
 * Accessible depuis la liste des certifications (action « Pondération »).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Ponderation_Form {

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_ponderation_action'] ) && $_POST['npq_ponderation_action'] === 'enregistrer' ) {
            self::enregistrer();
        }
    }

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_ponderation_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
        if ( ! $certification_id ) {
            self::rediriger( 0, 'Certification introuvable.', 'error' );
        }

        // Les nombres arrivent indexés par code de domaine.
        $valeurs = isset( $_POST['npq_pond'] ) && is_array( $_POST['npq_pond'] )
            ? wp_unslash( $_POST['npq_pond'] )
            : [];

        // On ne retient que les domaines réels de la certification (liste blanche).
        $codes_valides = self::codes_domaines( $certification_id );
        $ponderation = [];
        foreach ( $valeurs as $code => $nb ) {
            $code = (string) $code;
            if ( in_array( $code, $codes_valides, true ) ) {
                $ponderation[ $code ] = (int) $nb;
            }
        }

        NPQ_Ponderation::enregistrer( $certification_id, $ponderation );

        $total = array_sum( $ponderation );
        self::rediriger(
            $certification_id,
            sprintf( 'Pondération enregistrée (total : %d questions).', $total )
        );
    }

    private static function rediriger( $certification_id, $message, $type = 'success' ) {
        set_transient( 'npq_ponderation_message', [ 'texte' => $message, 'type' => $type ], 60 );
        $url = add_query_arg(
            [ 'page' => 'normaprep-certifications', 'npq_vue' => 'ponderation', 'id' => (int) $certification_id ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    public static function afficher_formulaire() {
        $certification_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $certif = self::charger_certification( $certification_id );
        if ( ! $certif ) {
            echo '<div class="wrap"><h1>Pondération</h1><div class="notice notice-error"><p>Certification introuvable.</p></div></div>';
            return;
        }

        $domaines = self::domaines_avec_compte( $certification_id );
        $actuelle = NPQ_Ponderation::de( $certification_id );

        $message = get_transient( 'npq_ponderation_message' );
        delete_transient( 'npq_ponderation_message' );

        $url_retour = admin_url( 'admin.php?page=normaprep-certifications' );
        ?>
        <div class="wrap">
            <h1>Pondération de l'examen blanc — <?php echo esc_html( $certif['nom'] ); ?></h1>
            <a href="<?php echo esc_url( $url_retour ); ?>" class="page-title-action">← Certifications</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo $message['type'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html( $message['texte'] ); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Répartition officielle des questions de l'examen blanc, par domaine.
                C'est l'organisme de certification qui la fixe (ex. PECB : 80 questions
                au total). L'examen blanc tirera aléatoirement le nombre indiqué de
                questions dans chaque domaine.
            </p>

            <?php if ( empty( $domaines ) ) : ?>
                <div class="notice notice-warning">
                    <p>
                        Cette certification n'a pas encore de domaines. Créez-les d'abord
                        dans <strong>Domaines</strong>, puis revenez définir la pondération.
                    </p>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-certifications' ) ); ?>">
                    <input type="hidden" name="npq_ponderation_action" value="enregistrer">
                    <input type="hidden" name="npq_certification" value="<?php echo (int) $certification_id; ?>">
                    <?php wp_nonce_field( 'npq_ponderation_form', 'npq_nonce' ); ?>

                    <table class="wp-list-table widefat fixed striped" style="max-width:720px;margin-top:16px">
                        <thead>
                            <tr>
                                <th style="width:110px">Domaine</th>
                                <th>Libellé</th>
                                <th style="width:130px">Questions dispo.</th>
                                <th style="width:150px">Nombre à tirer</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $domaines as $d ) :
                            $code = $d['code'];
                            $val  = isset( $actuelle[ $code ] ) ? (int) $actuelle[ $code ] : 0;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $code ); ?></code></td>
                                <td><?php echo esc_html( $d['libelle'] ); ?></td>
                                <td>
                                    <?php echo (int) $d['nb']; ?>
                                    <?php if ( $val > (int) $d['nb'] ) : ?>
                                        <span style="color:#b32d2e" title="Pas assez de questions publiées">⚠</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" min="0" step="1"
                                           name="npq_pond[<?php echo esc_attr( $code ); ?>]"
                                           value="<?php echo (int) $val; ?>"
                                           class="small-text npq-pond-champ"
                                           data-dispo="<?php echo (int) $d['nb']; ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" style="text-align:right">Total</th>
                                <th><span id="npq-pond-total">0</span></th>
                            </tr>
                        </tfoot>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Enregistrer la pondération</button>
                    </p>
                </form>

                <script>
                /* Total en direct + alerte si un domaine demande plus de questions
                   qu'il n'en existe (l'examen blanc serait alors incomplet). */
                ( function () {
                    var champs = document.querySelectorAll( '.npq-pond-champ' );
                    var total  = document.getElementById( 'npq-pond-total' );

                    function recalculer() {
                        var somme = 0;
                        champs.forEach( function ( c ) {
                            somme += parseInt( c.value, 10 ) || 0;
                            var dispo = parseInt( c.getAttribute( 'data-dispo' ), 10 ) || 0;
                            var demande = parseInt( c.value, 10 ) || 0;
                            c.style.borderColor = ( demande > dispo ) ? '#b32d2e' : '';
                        } );
                        total.textContent = somme;
                    }

                    champs.forEach( function ( c ) {
                        c.addEventListener( 'input', recalculer );
                    } );
                    recalculer();
                } )();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ===================================================================== */

    private static function charger_certification( $id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, code, nom FROM {$p}certification WHERE id = %d",
            (int) $id
        ), ARRAY_A );
    }

    /** Domaines de la certification, avec leur nombre de questions publiées. */
    private static function domaines_avec_compte( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT d.code, d.libelle,
                    ( SELECT COUNT(*) FROM {$p}question q
                      WHERE q.domaine = d.code
                        AND q.certification_id = d.certification_id
                        AND q.statut = 'publie' ) AS nb
             FROM {$p}domaine d
             WHERE d.certification_id = %d
             ORDER BY d.code ASC",
            (int) $certification_id
        ), ARRAY_A );
    }

    private static function codes_domaines( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT code FROM {$p}domaine WHERE certification_id = %d",
            (int) $certification_id
        ) );
    }
}
