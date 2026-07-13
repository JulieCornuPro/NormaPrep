<?php
/**
 * Création, modification et suppression des scénarios en administration.
 *
 * Règles :
 *   - Un scénario IMPORTÉ peut être modifié, mais l'utilisateur est averti :
 *     ses changements seront écrasés au prochain import (le JSON fait foi).
 *   - Un scénario CRÉÉ ICI n'est jamais touché par l'import.
 *   - On refuse de supprimer un scénario qui porte des questions : elles
 *     deviendraient orphelines (des questions parlant d'une entreprise dont
 *     le contexte n'existe plus).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Scenario_Form {

    /**
     * Traite les actions (enregistrer, supprimer) avant tout affichage.
     * Appelée sur admin_init : on peut donc rediriger proprement.
     */
    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Enregistrement (création ou modification).
        if ( isset( $_POST['npq_scenario_action'] ) && $_POST['npq_scenario_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        // Suppression (lien avec nonce dans la liste).
        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_scenario' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_scenario_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id       = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $nom      = sanitize_text_field( wp_unslash( $_POST['npq_nom'] ?? '' ) );
        $resume   = sanitize_text_field( wp_unslash( $_POST['npq_resume'] ?? '' ) );
        $contexte = sanitize_textarea_field( wp_unslash( $_POST['npq_contexte'] ?? '' ) );
        $statut   = ( ( $_POST['npq_statut'] ?? '' ) === 'brouillon' ) ? 'brouillon' : 'publie';

        // Validation.
        $erreurs = [];
        if ( $nom === '' ) {
            $erreurs[] = 'Le nom est obligatoire.';
        }
        if ( $contexte === '' ) {
            $erreurs[] = 'Le contexte est obligatoire : c\'est le texte que le candidat lit.';
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_scenario_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $donnees = [
            'nom'      => $nom,
            'resume'   => $resume,
            'contexte' => $contexte,
            'statut'   => $statut,
        ];

        if ( $id > 0 ) {
            // Modification.
            $wpdb->update( "{$p}scenario", $donnees, [ 'id' => $id ] );
            $message = 'Scénario mis à jour.';
        } else {
            // Création : rattachée à la certification active.
            // Pas de ref_externe -> ce scénario ne sera jamais touché par l'import.
            $donnees['certification_id'] = self::certification_courante();
            $donnees['date_creation']    = current_time( 'mysql' );

            $wpdb->insert( "{$p}scenario", $donnees );
            $id = (int) $wpdb->insert_id;
            $message = 'Scénario créé.';
        }

        set_transient( 'npq_scenario_message', $message, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-scenarios' ) );
        exit;
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_scenario_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // GARDE-FOU : on ne supprime pas un scénario qui porte des questions.
        // Elles deviendraient orphelines — des questions parlant d'une entreprise
        // dont le contexte n'existerait plus.
        $nb_questions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}question WHERE scenario_id = %d",
            $id
        ) );

        if ( $nb_questions > 0 ) {
            set_transient(
                'npq_scenario_erreurs',
                [ sprintf(
                    'Impossible de supprimer ce scénario : il porte %d question(s). '
                    . 'Supprimez ou déplacez d\'abord ces questions.',
                    $nb_questions
                ) ],
                60
            );
        } else {
            $wpdb->delete( "{$p}scenario", [ 'id' => $id ] );
            set_transient( 'npq_scenario_message', 'Scénario supprimé.', 60 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-scenarios' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    /**
     * Affiche le formulaire de création ou de modification.
     */
    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $scenario = $id ? self::charger( $id ) : null;
        $modification = ( $scenario !== null );

        // Valeurs affichées.
        $nom      = $modification ? $scenario['nom'] : '';
        $resume   = $modification ? $scenario['resume'] : '';
        $contexte = $modification ? $scenario['contexte'] : '';
        $statut   = $modification ? $scenario['statut'] : 'publie';
        $importe  = $modification && ! empty( $scenario['ref_externe'] );

        // Messages d'erreur éventuels.
        $erreurs = get_transient( 'npq_scenario_erreurs' );
        delete_transient( 'npq_scenario_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier le scénario' : 'Nouveau scénario'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $importe ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Ce scénario vient de l'import.</strong>
                        Vos modifications seront <strong>écrasées au prochain import</strong>
                        du fichier de contenu. Pour les conserver durablement, modifiez
                        plutôt le fichier <code>question_bank.json</code>.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-scenarios' ) ); ?>">
                <input type="hidden" name="npq_scenario_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_scenario_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_nom">Nom <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <input name="npq_nom" id="npq_nom" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $nom ); ?>" required>
                            <p class="description">Le nom de l'entreprise fictive. Ex. : <em>PharmaCorp</em></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_resume">Résumé</label></th>
                        <td>
                            <input name="npq_resume" id="npq_resume" type="text" class="large-text"
                                   value="<?php echo esc_attr( $resume ); ?>">
                            <p class="description">
                                Une ligne, affichée au-dessus des questions.
                                Ex. : <em>PharmaCorp — laboratoire pharma, 3 100 employés, Toulouse</em>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_contexte">Contexte <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <textarea name="npq_contexte" id="npq_contexte" rows="12" class="large-text"
                                      required><?php echo esc_textarea( $contexte ); ?></textarea>
                            <p class="description">
                                Le texte que le candidat lit avant de répondre. Décrivez la situation,
                                les personnes, les problèmes rencontrés. C'est ce qui donne du sens
                                aux questions.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_statut">Statut</label></th>
                        <td>
                            <select name="npq_statut" id="npq_statut">
                                <option value="publie" <?php selected( $statut, 'publie' ); ?>>
                                    Publié (utilisable dans les examens)
                                </option>
                                <option value="brouillon" <?php selected( $statut, 'brouillon' ); ?>>
                                    Brouillon (masqué)
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $modification ? 'Mettre à jour' : 'Créer le scénario'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-scenarios' ) ); ?>"
                       class="button">Annuler</a>
                </p>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    private static function charger( $id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom, resume, contexte, statut, ref_externe
             FROM {$p}scenario WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    private static function certification_courante() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (int) $wpdb->get_var(
            "SELECT id FROM {$p}certification WHERE actif = 1 ORDER BY id ASC LIMIT 1"
        );
    }

    private static function rediriger_formulaire( $id ) {
        $url = admin_url( 'admin.php?page=normaprep-scenarios&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
