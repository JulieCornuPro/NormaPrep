<?php
/**
 * Gestion des accès d'un utilisateur : attribuer ou retirer des certifications
 * de sa bibliothèque.
 *
 * Calqué sur NPQ_Certification_Form pour rester cohérent avec le reste de
 * l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Acces_Form {

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_acces_action'] ) && $_POST['npq_acces_action'] === 'attribuer' ) {
            self::attribuer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'retirer_acces' ) {
            self::retirer();
        }
    }

    private static function attribuer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_acces_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $utilisateur_id   = isset( $_POST['npq_utilisateur'] ) ? (int) $_POST['npq_utilisateur'] : 0;
        $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;

        // Date de fin optionnelle (accès temporaire). Vide = permanent.
        $fin = isset( $_POST['npq_fin_acces'] ) ? sanitize_text_field( wp_unslash( $_POST['npq_fin_acces'] ) ) : '';
        $fin_acces = ( $fin !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fin ) ) ? $fin : null;

        if ( ! $utilisateur_id || ! $certification_id ) {
            self::rediriger( $utilisateur_id, 'Sélectionnez une certification.', 'error' );
        }

        NPQ_Bibliotheque::attribuer( $utilisateur_id, $certification_id, $fin_acces );

        self::rediriger( $utilisateur_id, 'Certification ajoutée à la bibliothèque.' );
    }

    private static function retirer() {
        $utilisateur_id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $certification_id = isset( $_GET['certif'] ) ? (int) $_GET['certif'] : 0;

        if ( ! $utilisateur_id || ! $certification_id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_retirer_acces_' . $utilisateur_id . '_' . $certification_id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        NPQ_Bibliotheque::retirer( $utilisateur_id, $certification_id );

        self::rediriger( $utilisateur_id, 'Certification retirée de la bibliothèque.' );
    }

    private static function rediriger( $utilisateur_id, $message, $type = 'success' ) {
        set_transient( 'npq_acces_message', [ 'texte' => $message, 'type' => $type ], 60 );
        $url = add_query_arg(
            [ 'page' => 'normaprep-acces', 'npq_vue' => 'form', 'id' => (int) $utilisateur_id ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $utilisateur_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $utilisateur = self::charger_utilisateur( $utilisateur_id );
        if ( ! $utilisateur ) {
            echo '<div class="wrap"><h1>Accès</h1><div class="notice notice-error"><p>Utilisateur introuvable.</p></div></div>';
            return;
        }

        $possedees      = NPQ_Bibliotheque::certifications_de( $utilisateur_id );
        $possedees_ids  = array_map( static function ( $c ) { return (int) $c['id']; }, $possedees );
        $certifications = NPQ_Certification::toutes();

        // Certifications encore attribuables (non déjà possédées).
        $attribuables = array_filter( $certifications, static function ( $c ) use ( $possedees_ids ) {
            return ! in_array( (int) $c['id'], $possedees_ids, true );
        } );

        $message = get_transient( 'npq_acces_message' );
        delete_transient( 'npq_acces_message' );

        $nom = $utilisateur['nom_affiche'] !== '' ? $utilisateur['nom_affiche'] : $utilisateur['email'];

        $url_retour = admin_url( 'admin.php?page=normaprep-acces' );
        ?>
        <div class="wrap">
            <h1>Accès de <?php echo esc_html( $nom ); ?></h1>
            <a href="<?php echo esc_url( $url_retour ); ?>" class="page-title-action">← Tous les utilisateurs</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo $message['type'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html( $message['texte'] ); ?></p>
                </div>
            <?php endif; ?>

            <h2 style="font-size:1.1em">Certifications de la bibliothèque</h2>

            <?php if ( empty( $possedees ) ) : ?>
                <p><em>Cet utilisateur ne possède encore aucune certification.</em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width:720px">
                    <thead>
                        <tr>
                            <th style="width:120px">Code</th>
                            <th>Nom</th>
                            <th style="width:150px">Acquise le</th>
                            <th style="width:130px">Accès</th>
                            <th style="width:100px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $possedees as $c ) :
                        $url_retirer = wp_nonce_url(
                            add_query_arg(
                                [
                                    'page'       => 'normaprep-acces',
                                    'npq_action' => 'retirer_acces',
                                    'id'         => $utilisateur_id,
                                    'certif'     => (int) $c['id'],
                                ],
                                admin_url( 'admin.php' )
                            ),
                            'npq_retirer_acces_' . $utilisateur_id . '_' . (int) $c['id']
                        );
                        $date = ! empty( $c['date_acquisition'] ) ? substr( $c['date_acquisition'], 0, 10 ) : '—';
                    ?>
                        <tr>
                            <td><code><?php echo esc_html( $c['code'] ); ?></code></td>
                            <td><?php echo esc_html( $c['nom'] ); ?></td>
                            <td><?php echo esc_html( $date ); ?></td>
                            <td>
                                <?php if ( ! empty( $c['fin_acces'] ) ) : ?>
                                    <span title="Expire">jusqu'au <?php echo esc_html( $c['fin_acces'] ); ?></span>
                                <?php else : ?>
                                    <span style="color:#00a32a">Permanent</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $url_retirer ); ?>"
                                   style="color:#b32d2e"
                                   onclick="return confirm('Retirer cette certification de sa bibliothèque ?');">
                                    Retirer
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="font-size:1.1em;margin-top:28px">Ajouter une certification</h2>

            <?php if ( empty( $attribuables ) ) : ?>
                <p><em>Cet utilisateur possède déjà toutes les certifications existantes.</em></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-acces' ) ); ?>">
                    <input type="hidden" name="npq_acces_action" value="attribuer">
                    <input type="hidden" name="npq_utilisateur" value="<?php echo (int) $utilisateur_id; ?>">
                    <?php wp_nonce_field( 'npq_acces_form', 'npq_nonce' ); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="npq_certification">Certification</label></th>
                            <td>
                                <select name="npq_certification" id="npq_certification">
                                    <?php foreach ( $attribuables as $c ) : ?>
                                        <option value="<?php echo (int) $c['id']; ?>">
                                            <?php echo esc_html( $c['code'] . ' — ' . $c['nom'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="npq_fin_acces">Fin d'accès</label></th>
                            <td>
                                <input type="date" name="npq_fin_acces" id="npq_fin_acces">
                                <p class="description">
                                    Optionnel. Laissez vide pour un accès permanent.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Ajouter à la bibliothèque</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function charger_utilisateur( $id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, nom_affiche, email FROM {$p}utilisateur WHERE id = %d",
            (int) $id
        ), ARRAY_A );
    }
}
