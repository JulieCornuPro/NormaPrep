<?php
/**
 * Création, modification et suppression des flashcards.
 *
 * Une flashcard est simple : un recto (la question), un verso (la réponse),
 * un domaine. Pas de scénario — c'est une carte générale de mémorisation.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Flashcard_Form {

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_flashcard_action'] ) && $_POST['npq_flashcard_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_flashcard' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_flashcard_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id      = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $domaine = sanitize_text_field( wp_unslash( $_POST['npq_domaine'] ?? '' ) );
        $recto   = sanitize_textarea_field( wp_unslash( $_POST['npq_recto'] ?? '' ) );
        $verso   = sanitize_textarea_field( wp_unslash( $_POST['npq_verso'] ?? '' ) );
        $statut  = ( ( $_POST['npq_statut'] ?? '' ) === 'brouillon' ) ? 'brouillon' : 'publie';

        // Validation.
        $erreurs = [];
        if ( $recto === '' ) {
            $erreurs[] = 'Le recto est obligatoire : c\'est la question posée.';
        }
        if ( $verso === '' ) {
            $erreurs[] = 'Le verso est obligatoire : c\'est la réponse à mémoriser.';
        }
        if ( $domaine === '' ) {
            $erreurs[] = 'Le domaine est obligatoire : il permet de réviser par thème.';
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_flashcard_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $donnees = [
            'domaine' => $domaine,
            'recto'   => $recto,
            'verso'   => $verso,
            'statut'  => $statut,
        ];

        if ( $id > 0 ) {
            $wpdb->update( "{$p}flashcard", $donnees, [ 'id' => $id ] );
            $message = 'Flashcard mise à jour.';
        } else {
            $donnees['certification_id'] = self::certification_courante();
            $donnees['date_creation']    = current_time( 'mysql' );

            $wpdb->insert( "{$p}flashcard", $donnees );
            $message = 'Flashcard créée.';
        }

        set_transient( 'npq_flashcard_message', $message, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-flashcards' ) );
        exit;
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_flashcard_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->delete( "{$p}flashcard", [ 'id' => $id ] );

        set_transient( 'npq_flashcard_message', 'Flashcard supprimée.', 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-flashcards' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $carte = $id ? self::charger( $id ) : null;
        $modification = ( $carte !== null );

        $domaine = $modification ? $carte['domaine'] : '';
        $recto   = $modification ? $carte['recto'] : '';
        $verso   = $modification ? $carte['verso'] : '';
        $statut  = $modification ? $carte['statut'] : 'publie';

        $erreurs = get_transient( 'npq_flashcard_erreurs' );
        delete_transient( 'npq_flashcard_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier la flashcard' : 'Nouvelle flashcard'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:760px">
                Une flashcard sert à <strong>mémoriser</strong>, pas à raisonner sur un cas.
                Contrairement aux questions d'examen, elle n'a pas de scénario : elle est
                générale et directe.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-flashcards' ) ); ?>">
                <input type="hidden" name="npq_flashcard_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_flashcard_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_domaine">Domaine <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <select name="npq_domaine" id="npq_domaine" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ( self::domaines() as $d ) : ?>
                                    <option value="<?php echo esc_attr( $d['code'] ); ?>"
                                        <?php selected( $domaine, $d['code'] ); ?>>
                                        <?php echo esc_html( $d['code'] . ' — ' . $d['libelle'] ); ?>
                                        (<?php echo (int) $d['nb']; ?> carte<?php echo $d['nb'] > 1 ? 's' : ''; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Permet au candidat de réviser un domaine précis.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_recto">Recto <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <textarea name="npq_recto" id="npq_recto" rows="4" class="large-text"
                                      required><?php echo esc_textarea( $recto ); ?></textarea>
                            <p class="description">
                                La question, courte et directe.
                                Ex. : <em>« Que doit contenir la Déclaration d'Applicabilité (SoA) ? »</em>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_verso">Verso <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <textarea name="npq_verso" id="npq_verso" rows="8" class="large-text"
                                      required><?php echo esc_textarea( $verso ); ?></textarea>
                            <p class="description">
                                La réponse à mémoriser. Soyez précise et citez la norme quand
                                c'est utile. Ex. : <em>« Art. 6.1.3 d) — les mesures nécessaires,
                                leur justification, leur statut de mise en œuvre, et la
                                justification des exclusions de l'Annexe A. »</em>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_statut">Statut</label></th>
                        <td>
                            <select name="npq_statut" id="npq_statut">
                                <option value="publie" <?php selected( $statut, 'publie' ); ?>>
                                    Publiée (visible par les abonnés)
                                </option>
                                <option value="brouillon" <?php selected( $statut, 'brouillon' ); ?>>
                                    Brouillon (masquée)
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $modification ? 'Mettre à jour' : 'Créer la flashcard'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-flashcards' ) ); ?>"
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
            "SELECT id, domaine, recto, verso, statut
             FROM {$p}flashcard WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    private static function domaines() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT d.code, d.libelle, COUNT(f.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}flashcard f ON f.domaine = d.code AND f.statut = 'publie'
             GROUP BY d.code, d.libelle
             ORDER BY d.code ASC",
            ARRAY_A
        );
    }

    private static function certification_courante() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (int) $wpdb->get_var(
            "SELECT id FROM {$p}certification WHERE actif = 1 ORDER BY id ASC LIMIT 1"
        );
    }

    private static function rediriger_formulaire( $id ) {
        $url = admin_url( 'admin.php?page=normaprep-flashcards&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
