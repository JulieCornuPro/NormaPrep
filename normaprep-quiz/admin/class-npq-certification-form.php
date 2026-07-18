<?php
/**
 * Création d'une certification et traitement des actions associées
 * (activer une certification, en supprimer une vide).
 *
 * La certification ACTIVE est celle sur laquelle porte le travail : import,
 * création de questions, scénarios, parcours et examens s'y rattachent.
 * Une seule est active à la fois.
 *
 * Calqué sur NPQ_Examen_Form / NPQ_Parcours_Form pour rester cohérent avec le
 * reste de l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Certification_Form {

    /**
     * Traite les actions avant tout affichage.
     * Appelée sur admin_init : on peut donc rediriger proprement.
     */
    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_certif_action'] ) && $_POST['npq_certif_action'] === 'creer' ) {
            self::creer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'activer_certif' ) {
            self::activer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_certif' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ACTIONS
     * ===================================================================== */

    private static function creer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_certif_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $code = sanitize_text_field( wp_unslash( $_POST['npq_code'] ?? '' ) );
        $nom  = sanitize_text_field( wp_unslash( $_POST['npq_nom'] ?? '' ) );

        if ( $code === '' || $nom === '' ) {
            self::rediriger( 'Le code et le nom sont obligatoires.', 'error' );
        }

        // creer() renvoie l'id existant si le code est déjà pris (code unique).
        $id = NPQ_Certification::creer( $code, $nom );

        if ( ! $id ) {
            self::rediriger( 'Création impossible : vérifiez le code et le nom.', 'error' );
        }

        self::rediriger( sprintf(
            'Certification « %s » enregistrée. Cliquez sur « Travailler dessus » pour l\'activer.',
            $nom
        ) );
    }

    private static function activer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_activer_certif_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        if ( NPQ_Certification::definir_active( $id ) ) {
            $c = NPQ_Certification::courante();
            self::rediriger( sprintf(
                'Vous travaillez maintenant sur « %s ».',
                $c ? $c['nom'] : ''
            ) );
        }

        self::rediriger( 'Certification introuvable.', 'error' );
    }

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_certif_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        // La classe refuse de supprimer une certification qui porte du contenu,
        // ou la dernière restante : elle renvoie alors le message d'explication.
        $resultat = NPQ_Certification::supprimer( $id );

        if ( $resultat === true ) {
            self::rediriger( 'Certification supprimée.' );
        }

        self::rediriger( $resultat, 'error' );
    }

    private static function rediriger( $message, $type = 'success' ) {
        set_transient( 'npq_certif_message', [ 'texte' => $message, 'type' => $type ], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-certifications' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    /**
     * Formulaire d'ajout d'une certification, affiché sous la liste.
     */
    public static function afficher_formulaire() {
        ?>
        <h2 style="margin-top:32px">Ajouter une certification</h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-certifications' ) ); ?>">
            <input type="hidden" name="npq_certif_action" value="creer">
            <?php wp_nonce_field( 'npq_certif_form', 'npq_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="npq_code">Code <span style="color:#d63638">*</span></label>
                    </th>
                    <td>
                        <input name="npq_code" id="npq_code" type="text" class="regular-text"
                               placeholder="LA27001" required>
                        <p class="description">
                            Court et unique, en majuscules. Il préfixe les références du
                            contenu importé (ex. <code>LA27001-Q-0001</code>).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="npq_nom">Nom <span style="color:#d63638">*</span></label>
                    </th>
                    <td>
                        <input name="npq_nom" id="npq_nom" type="text" class="regular-text"
                               placeholder="ISO/IEC 27001 Lead Auditor" required>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Créer la certification</button>
            </p>
        </form>

        <hr style="margin:30px 0">

        <h3>Ajouter le contenu d'une nouvelle certification</h3>
        <ol style="max-width:820px">
            <li>Créez la certification ci-dessus (code + nom).</li>
            <li>Cliquez sur <strong>Travailler dessus</strong> dans la liste pour l'activer.</li>
            <li>Allez dans <strong>Import / Export</strong> et importez son fichier JSON :
                le contenu se rattache à la certification active.</li>
            <li>Créez ensuite ses parcours de révision et ses examens : ils se
                rattachent eux aussi à la certification active.</li>
        </ol>
        <?php
    }
}
