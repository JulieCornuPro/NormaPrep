<?php
/**
 * Création, modification et suppression des flashcards.
 *
 * Une flashcard est simple : un recto (la question), un verso (la réponse),
 * un domaine. Pas de scénario — c'est une carte générale de mémorisation.
 *
 * La carte appartient à une CERTIFICATION, choisie à la création (pré-remplie
 * sur la certification active). En modification, la certification est affichée
 * mais verrouillée : la déplacer laisserait son domaine incohérent, puisque les
 * domaines sont propres à chaque certification.
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

        // Certification cible : uniquement à la CRÉATION. En modification, elle
        // est verrouillée (le champ est affiché en lecture seule), donc on ne
        // touche pas à la valeur déjà en base.
        $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
        if ( ! self::certification_valide( $certification_id ) ) {
            $certification_id = NPQ_Certification::id();
        }

        // Le domaine doit appartenir à la certification retenue (liste blanche).
        if ( $domaine !== '' && $id === 0 ) {
            $codes_valides = self::codes_domaines_de( $certification_id );
            if ( ! in_array( $domaine, $codes_valides, true ) ) {
                $domaine = '';
            }
        }

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
            $donnees['certification_id'] = $certification_id;
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

        // Certification : celle de la carte en modification, sinon l'active.
        $certification_id = $modification
            ? (int) $carte['certification_id']
            : NPQ_Certification::id();

        $certifications = NPQ_Certification::toutes();

        // TOUS les domaines, toutes certifications : le JavaScript n'affiche que
        // ceux de la certification choisie (à la création). Charger l'ensemble
        // évite un aller-retour serveur au changement de certification.
        $domaines_tous = self::domaines();

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
                            <label for="npq_certification">Certification <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <?php if ( $modification ) : ?>
                                <?php
                                // Verrouillée : déplacer une carte laisserait son
                                // domaine incohérent (les domaines sont propres à
                                // chaque certification).
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
                                    La certification d'une carte existante ne peut pas être
                                    changée : son domaine n'aurait plus de sens ailleurs.
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
                                <p class="description">
                                    Choisissez la certification avant le domaine : la liste des
                                    domaines en dépend.
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_domaine">Domaine <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <select name="npq_domaine" id="npq_domaine" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ( $domaines_tous as $d ) : ?>
                                    <option value="<?php echo esc_attr( $d['code'] ); ?>"
                                        data-certification="<?php echo (int) $d['certification_id']; ?>"
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

        <?php if ( ! $modification ) : ?>
        <script>
        /* À la création, n'affiche que les domaines de la certification choisie.
           Les <option> masquées sont retirées du DOM puis réinjectées : c'est le
           moyen le plus fiable, « display:none » sur une <option> n'étant pas
           traité de la même façon par tous les navigateurs.
           (Le serveur revalide de toute façon à l'enregistrement.) */
        ( function () {
            var selectCertif  = document.getElementById( 'npq_certification' );
            var selectDomaine = document.getElementById( 'npq_domaine' );

            if ( ! selectCertif || ! selectDomaine ) { return; }

            // Mémorise toutes les options une fois pour toutes.
            var toutes = Array.prototype.slice.call( selectDomaine.options ).map( function ( o ) {
                return {
                    value: o.value,
                    text: o.text,
                    certif: parseInt( o.getAttribute( 'data-certification' ), 10 ) || 0
                };
            } );

            function filtrerDomaines() {
                var certif = parseInt( selectCertif.value, 10 ) || 0;
                var valeurCourante = selectDomaine.value;

                selectDomaine.innerHTML = '';

                toutes.forEach( function ( o ) {
                    // L'option vide (« — Choisir — ») est toujours conservée.
                    if ( o.value !== '' && o.certif !== certif ) { return; }

                    var opt = document.createElement( 'option' );
                    opt.value = o.value;
                    opt.text  = o.text;
                    if ( o.value === valeurCourante ) { opt.selected = true; }
                    selectDomaine.appendChild( opt );
                } );
            }

            selectCertif.addEventListener( 'change', filtrerDomaines );
            filtrerDomaines();
        } )();
        </script>
        <?php endif; ?>
        <?php
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    private static function charger( $id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, certification_id, domaine, recto, verso, statut
             FROM {$p}flashcard WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /**
     * TOUS les domaines, toutes certifications, avec le nombre de cartes de
     * chacun.
     *
     * Le comptage joint sur la CERTIFICATION en plus du code : sans cela, deux
     * certifications ayant toutes deux un domaine « D1 » verraient leurs cartes
     * additionnées, et les compteurs seraient faux.
     */
    private static function domaines() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT d.code, d.libelle, d.certification_id,
                    COUNT(f.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}flashcard f
                    ON f.domaine = d.code
                   AND f.certification_id = d.certification_id
                   AND f.statut = 'publie'
             GROUP BY d.code, d.libelle, d.certification_id
             ORDER BY d.certification_id ASC, d.code ASC",
            ARRAY_A
        );
    }

    /** Codes de domaine d'une certification donnée (liste blanche). */
    private static function codes_domaines_de( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT code FROM {$p}domaine WHERE certification_id = %d",
            (int) $certification_id
        ) );
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

    private static function rediriger_formulaire( $id ) {
        $url = admin_url( 'admin.php?page=normaprep-flashcards&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
