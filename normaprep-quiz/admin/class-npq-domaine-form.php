<?php
/**
 * Création, modification et suppression des domaines.
 *
 * Un domaine est une subdivision thématique d'une certification (ex. « D3 —
 * Appréciation des risques »). Il appartient à une CERTIFICATION : deux
 * certifications peuvent avoir chacune un domaine « D1 » sans conflit (la clé
 * unique porte sur le couple certification + code).
 *
 * Comme pour les scénarios et les flashcards, la certification est choisie à la
 * création puis verrouillée : déplacer un domaine laisserait ses questions et
 * ses cartes rattachées à un code inexistant dans la nouvelle certification.
 *
 * Calqué sur NPQ_Certification_Form pour rester cohérent avec le reste de
 * l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Domaine_Form {

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_domaine_action'] ) && $_POST['npq_domaine_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_domaine' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_domaine_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id      = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $code    = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['npq_code'] ?? '' ) ) ) );
        $libelle = sanitize_text_field( wp_unslash( $_POST['npq_libelle'] ?? '' ) );

        // Certification : uniquement à la CRÉATION (verrouillée en modification).
        $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
        if ( ! self::certification_valide( $certification_id ) ) {
            $certification_id = NPQ_Certification::id();
        }

        // Validation.
        $erreurs = [];
        if ( $code === '' ) {
            $erreurs[] = 'Le code est obligatoire (ex. D1).';
        }
        if ( $libelle === '' ) {
            $erreurs[] = 'Le libellé est obligatoire.';
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // En modification, la certification reste celle enregistrée.
        if ( $id > 0 ) {
            $existant = self::charger( $id );
            if ( ! $existant ) {
                self::rediriger( 'Domaine introuvable.', 'error' );
            }
            $certification_id = (int) $existant['certification_id'];
        }

        // Unicité du couple (certification, code) : c'est la clé de la table.
        if ( $code !== '' ) {
            $doublon = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}domaine
                 WHERE certification_id = %d AND code = %s AND id <> %d",
                $certification_id,
                $code,
                $id
            ) );
            if ( $doublon ) {
                $erreurs[] = sprintf(
                    'Le code « %s » existe déjà pour cette certification.',
                    $code
                );
            }
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_domaine_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        if ( $id > 0 ) {
            // On ne renomme PAS le code d'un domaine qui porte du contenu :
            // les questions et cartes référencent le domaine par son code, pas
            // par son id. Changer le code les rendrait orphelines.
            $donnees = [ 'libelle' => $libelle ];

            if ( self::est_vide( $id, $certification_id ) ) {
                $donnees['code'] = $code;
            }

            $wpdb->update( "{$p}domaine", $donnees, [ 'id' => $id ] );
            $message = 'Domaine mis à jour.';
        } else {
            $wpdb->insert( "{$p}domaine", [
                'certification_id' => $certification_id,
                'code'             => $code,
                'libelle'          => $libelle,
            ] );
            $message = 'Domaine créé.';
        }

        self::rediriger( $message );
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_domaine_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        $domaine = self::charger( $id );
        if ( ! $domaine ) {
            self::rediriger( 'Domaine introuvable.', 'error' );
        }

        // GARDE-FOU : on ne supprime pas un domaine utilisé. Les questions et
        // cartes le référencent par son code ; elles pointeraient dans le vide.
        if ( ! self::est_vide( $id, (int) $domaine['certification_id'] ) ) {
            self::rediriger(
                'Suppression impossible : ce domaine porte des questions ou des flashcards.',
                'error'
            );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->delete( "{$p}domaine", [ 'id' => $id ] );

        self::rediriger( 'Domaine supprimé.' );
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $domaine = $id ? self::charger( $id ) : null;
        $modification = ( $domaine !== null );

        $code    = $modification ? $domaine['code'] : '';
        $libelle = $modification ? $domaine['libelle'] : '';

        $certification_id = $modification
            ? (int) $domaine['certification_id']
            : NPQ_Certification::id();

        $certifications = NPQ_Certification::toutes();

        // Un domaine déjà utilisé ne peut pas voir son code changer.
        $verrouille_code = $modification && ! self::est_vide( $id, $certification_id );

        $erreurs = get_transient( 'npq_domaine_erreurs' );
        delete_transient( 'npq_domaine_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier le domaine' : 'Nouveau domaine'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( empty( $certifications ) ) : ?>
                <div class="notice notice-error">
                    <p>Aucune certification n'existe. Créez-en une avant d'ajouter un domaine.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-domaines' ) ); ?>">
                <input type="hidden" name="npq_domaine_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_domaine_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_certification">Certification <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <?php if ( $modification ) : ?>
                                <?php
                                $c_nom = '';
                                foreach ( $certifications as $c ) {
                                    if ( (int) $c['id'] === $certification_id ) {
                                        $c_nom = $c['nom'];
                                        break;
                                    }
                                }
                                ?>
                                <strong><?php echo esc_html( $c_nom ); ?></strong>
                                <p class="description">
                                    La certification d'un domaine existant ne peut pas être changée.
                                </p>
                            <?php else : ?>
                                <select name="npq_certification" id="npq_certification">
                                    <?php foreach ( $certifications as $c ) : ?>
                                        <option value="<?php echo (int) $c['id']; ?>"
                                            <?php selected( $certification_id, (int) $c['id'] ); ?>>
                                            <?php
                                            echo esc_html( $c['nom'] );
                                            if ( (int) $c['id'] === NPQ_Certification::id() ) {
                                                echo ' (active)';
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_code">Code <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <?php if ( $verrouille_code ) : ?>
                                <strong><code><?php echo esc_html( $code ); ?></code></strong>
                                <input type="hidden" name="npq_code" value="<?php echo esc_attr( $code ); ?>">
                                <p class="description">
                                    Ce code ne peut plus être modifié : des questions ou des
                                    flashcards y font référence.
                                </p>
                            <?php else : ?>
                                <input name="npq_code" id="npq_code" type="text" class="small-text"
                                       value="<?php echo esc_attr( $code ); ?>"
                                       placeholder="D1" required>
                                <p class="description">
                                    Court et unique au sein de la certification. Ex. :
                                    <code>D1</code>, <code>D2</code>…
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_libelle">Libellé <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <input name="npq_libelle" id="npq_libelle" type="text" class="large-text"
                                   value="<?php echo esc_attr( $libelle ); ?>"
                                   placeholder="Appréciation des risques" required>
                            <p class="description">
                                Le nom lisible du domaine, affiché aux candidats.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $modification ? 'Mettre à jour' : 'Créer le domaine'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-domaines' ) ); ?>"
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
            "SELECT id, certification_id, code, libelle FROM {$p}domaine WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /**
     * Le domaine ne porte-t-il aucune question ni flashcard ?
     * Le comptage tient compte de la certification : deux certifications
     * peuvent avoir un domaine de même code sans que leurs contenus se mêlent.
     */
    private static function est_vide( $id, $certification_id ) {
        $domaine = self::charger( $id );
        if ( ! $domaine ) {
            return true;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $nb = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT
               ( SELECT COUNT(*) FROM {$p}question q
                 WHERE q.domaine = %s AND q.certification_id = %d )
             + ( SELECT COUNT(*) FROM {$p}flashcard f
                 WHERE f.domaine = %s AND f.certification_id = %d )",
            $domaine['code'],
            $certification_id,
            $domaine['code'],
            $certification_id
        ) );

        return ( $nb === 0 );
    }

    /** La certification existe-t-elle ? */
    private static function certification_valide( $certification_id ) {
        if ( ! $certification_id ) {
            return false;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}certification WHERE id = %d",
            (int) $certification_id
        ) );
    }

    private static function rediriger( $message, $type = 'success' ) {
        set_transient( 'npq_domaine_message', [ 'texte' => $message, 'type' => $type ], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-domaines' ) );
        exit;
    }

    private static function rediriger_formulaire( $id ) {
        $url = admin_url( 'admin.php?page=normaprep-domaines&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
