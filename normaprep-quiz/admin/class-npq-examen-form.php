<?php
/**
 * Création, modification et suppression des modèles d'examen.
 *
 * Un modèle de type « scenarios » est rattaché à une CERTIFICATION et à un ou
 * plusieurs scénarios de cette certification. Au démarrage, il tire
 * aléatoirement son nombre de questions cible parmi celles des scénarios
 * rattachés — le tirage variant à chaque passage, la rotation s'améliore à
 * mesure que la banque grossit.
 *
 * La certification est choisie explicitement dans ce formulaire (pré-remplie
 * sur la certification active). La liste des scénarios proposés suit ce choix :
 * on ne peut rattacher que des scénarios de la certification retenue.
 *
 * Calqué sur NPQ_Parcours_Form pour rester cohérent avec le reste de l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Examen_Form {

    /** Nombre de questions cible par défaut (une simulation complète). */
    const CIBLE_DEFAUT = 80;

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_examen_form_action'] ) && $_POST['npq_examen_form_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_examen' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_examen_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id     = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $nom    = sanitize_text_field( wp_unslash( $_POST['npq_nom'] ?? '' ) );
        $desc   = sanitize_textarea_field( wp_unslash( $_POST['npq_description'] ?? '' ) );
        $nombre = isset( $_POST['npq_nombre'] ) ? (int) $_POST['npq_nombre'] : self::CIBLE_DEFAUT;
        $actif  = isset( $_POST['npq_actif'] ) ? 1 : 0;

        // Certification cible : celle choisie dans le formulaire, validée contre
        // la liste réelle (jamais une valeur brute venue du POST).
        $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
        if ( ! self::certification_valide( $certification_id ) ) {
            $certification_id = NPQ_Certification::id();
        }

        // Bornes raisonnables pour une simulation.
        $nombre = max( 1, min( 200, $nombre ) );

        // Scénarios cochés : on ne garde que ceux qui appartiennent RÉELLEMENT
        // à la certification retenue. C'est ce qui empêche de rattacher un
        // scénario Lead Implementer à un examen Lead Auditor, même si le
        // JavaScript a été contourné.
        $scenarios_valides = self::ids_scenarios_de( $certification_id );
        $scenarios = isset( $_POST['npq_scenarios'] )
            ? array_map( 'intval', (array) wp_unslash( $_POST['npq_scenarios'] ) )
            : [];
        $scenarios = array_values( array_intersect( $scenarios, $scenarios_valides ) );

        // Validation.
        $erreurs = [];
        if ( $nom === '' ) {
            $erreurs[] = 'Le nom de l\'examen est obligatoire.';
        }
        if ( ! $certification_id ) {
            $erreurs[] = 'Aucune certification disponible. Créez-en une d\'abord.';
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_examen_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $donnees = [
            'certification_id' => $certification_id,
            'nom'              => $nom,
            'description'      => $desc,
            'type'             => 'scenarios',
            'nombre_questions' => $nombre,
            'actif'            => $actif,
        ];

        if ( $id > 0 ) {
            $wpdb->update( "{$p}examen_modele", $donnees, [ 'id' => $id ] );
            $message = 'Examen mis à jour.';
        } else {
            $wpdb->insert( "{$p}examen_modele", $donnees );
            $id = (int) $wpdb->insert_id;
            $message = 'Examen créé.';
        }

        self::enregistrer_scenarios( $id, $scenarios );

        set_transient( 'npq_examen_message', $message, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-examens' ) );
        exit;
    }

    /** Réécrit la table de liaison examen_scenario pour un examen donné. */
    private static function enregistrer_scenarios( $examen_id, $scenario_ids ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->delete( "{$p}examen_scenario", [ 'examen_modele_id' => $examen_id ] );

        foreach ( $scenario_ids as $sid ) {
            $wpdb->insert( "{$p}examen_scenario", [
                'examen_modele_id' => $examen_id,
                'scenario_id'      => (int) $sid,
            ] );
        }
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_examen_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On retire d'abord les liaisons (les scénarios eux-mêmes ne sont pas
        // touchés : on ne supprime QUE le lien examen <-> scénario).
        $wpdb->delete( "{$p}examen_scenario", [ 'examen_modele_id' => $id ] );
        $wpdb->delete( "{$p}examen_modele", [ 'id' => $id ] );

        set_transient( 'npq_examen_message', 'Examen supprimé.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-examens' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $examen = $id ? self::charger( $id ) : null;
        $modification = ( $examen !== null );

        $nom    = $modification ? $examen['nom'] : '';
        $desc   = $modification ? $examen['description'] : '';
        $nombre = $modification ? (int) $examen['nombre_questions'] : self::CIBLE_DEFAUT;
        $actif  = $modification ? (int) $examen['actif'] : 1;

        // Certification : celle de l'examen en modification, sinon l'active.
        $certification_id = $modification
            ? (int) $examen['certification_id']
            : NPQ_Certification::id();

        $scenarios_choisis = $modification ? self::scenarios_de_examen( $id ) : [];

        $certifications = NPQ_Certification::toutes();

        // TOUS les scénarios, toutes certifications : le JavaScript n'affiche
        // que ceux de la certification choisie. Charger l'ensemble évite un
        // aller-retour serveur au changement de certification.
        $scenarios_dispo = self::scenarios_avec_compte();

        $erreurs = get_transient( 'npq_examen_erreurs' );
        delete_transient( 'npq_examen_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier l\'examen' : 'Nouvel examen'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( empty( $certifications ) ) : ?>
                <div class="notice notice-error">
                    <p>Aucune certification n'existe. Créez-en une avant d'ajouter un examen.</p>
                </div>
            <?php endif; ?>

            <form method="post" id="npq-examen-form" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-examens' ) ); ?>">
                <input type="hidden" name="npq_examen_form_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_examen_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_certification">Certification <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
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
                                L'examen et ses scénarios appartiennent à cette certification.
                                Changer ce choix met à jour la liste des scénarios ci-dessous.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_nom">Nom <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <input name="npq_nom" id="npq_nom" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $nom ); ?>" required>
                            <p class="description">Ex. : <em>Simulation complète — 80 questions</em></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_description">Description</label></th>
                        <td>
                            <textarea name="npq_description" id="npq_description" class="large-text" rows="2"><?php
                                echo esc_textarea( $desc );
                            ?></textarea>
                            <p class="description">Optionnel. Une phrase pour situer l'examen.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_nombre">Nombre de questions</label>
                        </th>
                        <td>
                            <input name="npq_nombre" id="npq_nombre" type="number"
                                   min="1" max="200" step="1"
                                   value="<?php echo (int) $nombre; ?>" class="small-text">
                            <p class="description">
                                Nombre de questions tirées à chaque passage parmi les scénarios
                                rattachés. Une simulation complète en compte <strong>80</strong>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Actif</th>
                        <td>
                            <label>
                                <input type="checkbox" name="npq_actif" value="1" <?php checked( $actif, 1 ); ?>>
                                L'examen est proposé aux candidats.
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 class="title" style="font-size:1.1em">Scénarios rattachés</h2>
                <p class="description">
                    Cochez les scénarios dont les questions alimentent cet examen. Seuls les
                    scénarios de la certification choisie sont proposés. Le total de questions
                    disponibles doit atteindre le nombre visé
                    (<span id="npq-cible"><?php echo (int) $nombre; ?></span>).
                </p>

                <?php if ( empty( $scenarios_dispo ) ) : ?>
                    <p><em>Aucun scénario publié. Importez ou créez des scénarios d'abord.</em></p>
                <?php else : ?>
                    <p style="margin:.4em 0 1em">
                        Questions disponibles dans la sélection :
                        <strong id="npq-total-dispo">0</strong>
                        <span id="npq-alerte-dispo" style="color:#b32d2e;display:none">
                            — insuffisant pour atteindre la cible.
                        </span>
                    </p>

                    <div id="npq-scenarios-zone"
                         style="max-height:360px;overflow:auto;border:1px solid #dcdcde;
                                padding:8px 14px;background:#fff;border-radius:4px">
                        <?php foreach ( $scenarios_dispo as $s ) :
                            $sid   = (int) $s['id'];
                            $coche = in_array( $sid, $scenarios_choisis, true );
                        ?>
                            <label class="npq-scenario-ligne"
                                   data-certification="<?php echo (int) $s['certification_id']; ?>"
                                   style="display:block;margin:.35em 0;line-height:1.4">
                                <input type="checkbox" class="npq-scenario-case"
                                       name="npq_scenarios[]" value="<?php echo $sid; ?>"
                                       data-questions="<?php echo (int) $s['nb_questions']; ?>"
                                       <?php checked( $coche ); ?>>
                                <strong><?php echo esc_html( $s['nom'] ); ?></strong>
                                <span style="color:#646970">
                                    — <?php echo (int) $s['nb_questions']; ?> question(s)
                                </span>
                            </label>
                        <?php endforeach; ?>

                        <p id="npq-aucun-scenario" style="display:none;color:#646970;margin:.6em 0">
                            <em>Aucun scénario publié pour cette certification.</em>
                        </p>
                    </div>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $modification ? 'Mettre à jour' : 'Créer l\'examen'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-examens' ) ); ?>"
                       class="button">Annuler</a>
                </p>
            </form>
        </div>

        <script>
        /* N'affiche que les scénarios de la certification choisie, et tient à
           jour le total de questions disponibles. Si l'on change de
           certification, les scénarios devenus hors périmètre sont décochés :
           un examen ne peut pas mélanger deux certifications.
           (Le serveur revalide de toute façon à l'enregistrement.) */
        ( function () {
            var selectCertif = document.getElementById( 'npq_certification' );
            var lignes       = document.querySelectorAll( '.npq-scenario-ligne' );
            var champCible   = document.getElementById( 'npq_nombre' );
            var totalEl      = document.getElementById( 'npq-total-dispo' );
            var cibleEl      = document.getElementById( 'npq-cible' );
            var alerteEl     = document.getElementById( 'npq-alerte-dispo' );
            var aucunEl      = document.getElementById( 'npq-aucun-scenario' );

            if ( ! selectCertif ) { return; }

            function certifChoisie() {
                return parseInt( selectCertif.value, 10 ) || 0;
            }

            // Affiche les scénarios de la certification choisie, masque les autres
            // (et décoche ceux qu'on masque, pour ne pas les enregistrer).
            function filtrerScenarios() {
                var certif = certifChoisie();
                var visibles = 0;

                lignes.forEach( function ( ligne ) {
                    var appartient = ( parseInt( ligne.getAttribute( 'data-certification' ), 10 ) === certif );

                    // 'block' explicite (et non '') : remettre la chaîne vide
                    // effacerait le style inline et le <label> reviendrait à
                    // son display par défaut (inline), ce qui casserait la
                    // disposition en liste verticale.
                    ligne.style.display = appartient ? 'block' : 'none';

                    if ( ! appartient ) {
                        var c = ligne.querySelector( '.npq-scenario-case' );
                        if ( c ) { c.checked = false; }
                    } else {
                        visibles++;
                    }
                } );

                if ( aucunEl ) {
                    aucunEl.style.display = ( visibles === 0 ) ? 'block' : 'none';
                }
            }

            // Additionne les questions des scénarios cochés ET visibles.
            function recalculer() {
                var total = 0;

                lignes.forEach( function ( ligne ) {
                    if ( ligne.style.display === 'none' ) { return; }
                    var c = ligne.querySelector( '.npq-scenario-case' );
                    if ( c && c.checked ) {
                        total += parseInt( c.getAttribute( 'data-questions' ), 10 ) || 0;
                    }
                } );

                if ( totalEl ) { totalEl.textContent = total; }

                var cible = parseInt( champCible.value, 10 ) || 0;
                if ( cibleEl ) { cibleEl.textContent = cible; }
                if ( alerteEl ) {
                    // 'inline' explicite : l'alerte est un <span> dans une phrase.
                    alerteEl.style.display = ( total < cible ) ? 'inline' : 'none';
                }
            }

            selectCertif.addEventListener( 'change', function () {
                filtrerScenarios();
                recalculer();
            } );

            document.querySelectorAll( '.npq-scenario-case' ).forEach( function ( c ) {
                c.addEventListener( 'change', recalculer );
            } );

            if ( champCible ) { champCible.addEventListener( 'input', recalculer ); }

            filtrerScenarios();
            recalculer();
        } )();
        </script>
        <?php
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    private static function charger( $id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, certification_id, nom, description, type, nombre_questions, actif
             FROM {$p}examen_modele WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /** Ids des scénarios rattachés à un examen. */
    private static function scenarios_de_examen( $examen_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT scenario_id FROM {$p}examen_scenario WHERE examen_modele_id = %d",
            $examen_id
        ) ) );
    }

    /**
     * TOUS les scénarios publiés (toutes certifications), avec leur
     * certification et leur nombre de questions publiées. Le filtrage par
     * certification se fait à l'affichage (JavaScript) et à l'enregistrement
     * (serveur).
     */
    private static function scenarios_avec_compte() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT s.id, s.nom, s.certification_id,
                    ( SELECT COUNT(*) FROM {$p}question q
                      WHERE q.scenario_id = s.id AND q.statut = 'publie' ) AS nb_questions
             FROM {$p}scenario s
             WHERE s.statut = 'publie'
             ORDER BY s.nom ASC",
            ARRAY_A
        );
    }

    /** Ids des scénarios publiés d'une certification donnée (liste blanche). */
    private static function ids_scenarios_de( $certification_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}scenario
             WHERE certification_id = %d AND statut = 'publie'",
            (int) $certification_id
        ) ) );
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
        $url = admin_url( 'admin.php?page=normaprep-examens&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
