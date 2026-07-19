<?php
/**
 * Création, modification et suppression des questions en administration.
 *
 * Une question porte : un scénario, un domaine, un énoncé, 4 options dont une
 * bonne, une explication, une difficulté.
 *
 * La CERTIFICATION se déduit du scénario choisi : un scénario appartient à une
 * certification, et la question en hérite. Le formulaire fonctionne donc en
 * cascade — choisir un scénario met à jour la certification affichée et filtre
 * les domaines proposés à ceux de cette certification.
 *
 * Les options vivent dans une table séparée (option_reponse). À l'enregistrement,
 * on les remplace entièrement : plus simple et plus sûr que de tenter une mise à
 * jour ligne par ligne.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Question_Form {

    /** Nombre d'options par question (format PECB / banque NormaPrep). */
    const NB_OPTIONS = 4;

    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_question_action'] ) && $_POST['npq_question_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_question' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_question_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id          = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $scenario_id = isset( $_POST['npq_scenario_id'] ) ? (int) $_POST['npq_scenario_id'] : 0;
        $domaine     = sanitize_text_field( wp_unslash( $_POST['npq_domaine'] ?? '' ) );
        $enonce      = sanitize_textarea_field( wp_unslash( $_POST['npq_enonce'] ?? '' ) );
        $explication = sanitize_textarea_field( wp_unslash( $_POST['npq_explication'] ?? '' ) );
        $difficulte  = sanitize_text_field( wp_unslash( $_POST['npq_difficulte'] ?? 'hard' ) );
        $statut      = ( ( $_POST['npq_statut'] ?? '' ) === 'brouillon' ) ? 'brouillon' : 'publie';

        // Les 4 options, et laquelle est la bonne.
        $options   = isset( $_POST['npq_option'] ) ? (array) wp_unslash( $_POST['npq_option'] ) : [];
        $correcte  = isset( $_POST['npq_correcte'] ) ? (int) $_POST['npq_correcte'] : -1;

        $options = array_map( 'sanitize_textarea_field', $options );

        // --- Validation ---
        $erreurs = [];

        if ( $enonce === '' ) {
            $erreurs[] = 'L\'énoncé est obligatoire.';
        }
        if ( $domaine === '' ) {
            $erreurs[] = 'Le domaine est obligatoire.';
        }
        if ( ! $scenario_id ) {
            $erreurs[] = 'Le scénario est obligatoire : une question s\'inscrit toujours dans un contexte.';
        }

        // Toutes les options doivent être remplies.
        $options_remplies = 0;
        for ( $i = 0; $i < self::NB_OPTIONS; $i++ ) {
            if ( ! empty( trim( $options[ $i ] ?? '' ) ) ) {
                $options_remplies++;
            }
        }
        if ( $options_remplies < self::NB_OPTIONS ) {
            $erreurs[] = sprintf(
                'Les %d options doivent être renseignées (%d remplie(s)).',
                self::NB_OPTIONS,
                $options_remplies
            );
        }

        if ( $correcte < 0 || $correcte >= self::NB_OPTIONS ) {
            $erreurs[] = 'Vous devez désigner la bonne réponse.';
        }

        if ( $explication === '' ) {
            $erreurs[] = 'L\'explication est obligatoire : c\'est ce qui donne sa valeur pédagogique à la question.';
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_question_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        // --- Enregistrement ---
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // La certification se déduit du scénario : c'est lui qui la porte.
        $certification_id = self::certification_du_scenario( $scenario_id );
        if ( ! $certification_id ) {
            $certification_id = NPQ_Certification::id();
        }

        // Le domaine doit appartenir à cette certification (liste blanche) :
        // on ne veut pas d'une question rattachée à un domaine d'ailleurs.
        $codes_valides = self::codes_domaines_de( $certification_id );
        if ( $domaine !== '' && ! in_array( $domaine, $codes_valides, true ) ) {
            set_transient(
                'npq_question_erreurs',
                [ 'Le domaine choisi n\'appartient pas à la certification du scénario.' ],
                60
            );
            self::rediriger_formulaire( $id );
        }

        $donnees = [
            'scenario_id'    => $scenario_id,
            'domaine'        => $domaine,
            'enonce'         => $enonce,
            'explication'    => $explication,
            'difficulte'     => $difficulte,
            'statut'         => $statut,
            'multi_reponses' => 0,  // une seule bonne réponse (format PECB)
        ];

        // La certification suit toujours le scénario, en création comme en
        // modification : changer de scénario change de certification.
        $donnees['certification_id'] = $certification_id;

        if ( $id > 0 ) {
            $wpdb->update( "{$p}question", $donnees, [ 'id' => $id ] );
            $message = 'Question mise à jour.';
        } else {
            // Pas de ref_externe : cette question ne sera jamais écrasée par l'import.
            $wpdb->insert( "{$p}question", $donnees );
            $id = (int) $wpdb->insert_id;
            $message = 'Question créée.';
        }

        // Les options : on les remplace entièrement.
        // (Plus simple et plus sûr qu'une mise à jour ligne par ligne, et sans
        //  risque de laisser des options orphelines.)
        $wpdb->delete( "{$p}option_reponse", [ 'question_id' => $id ] );

        for ( $i = 0; $i < self::NB_OPTIONS; $i++ ) {
            $wpdb->insert( "{$p}option_reponse", [
                'question_id' => $id,
                'texte'       => $options[ $i ],
                'correcte'    => ( $i === $correcte ) ? 1 : 0,
                'position'    => $i,
            ] );
        }

        set_transient( 'npq_question_message', $message, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-questions' ) );
        exit;
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_question_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On supprime la question et ses options.
        // (Les réponses déjà données par des candidats restent en base : elles
        //  documentent des tentatives passées. Les effacer fausserait l'historique.)
        $wpdb->delete( "{$p}option_reponse", [ 'question_id' => $id ] );
        $wpdb->delete( "{$p}question", [ 'id' => $id ] );

        set_transient( 'npq_question_message', 'Question supprimée.', 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-questions' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $question = $id ? self::charger( $id ) : null;
        $modification = ( $question !== null );

        $scenario_id = $modification ? (int) $question['scenario_id'] : 0;
        $domaine     = $modification ? $question['domaine'] : '';

        // Scénarios et domaines de TOUTES les certifications : le JavaScript
        // n'affiche que ceux qui correspondent au scénario choisi.
        $scenarios_tous = self::scenarios();
        $domaines_tous  = self::domaines();
        $certifications = NPQ_Certification::toutes();

        // Certification déduite du scénario (ou l'active si aucun scénario).
        $certification_id = $scenario_id
            ? self::certification_du_scenario( $scenario_id )
            : NPQ_Certification::id();
        $enonce      = $modification ? $question['enonce'] : '';
        $explication = $modification ? $question['explication'] : '';
        $difficulte  = $modification ? $question['difficulte'] : 'hard';
        $statut      = $modification ? $question['statut'] : 'publie';
        $importee    = $modification && ! empty( $question['ref_externe'] );

        // Les options existantes (ou vides pour une création).
        $options  = $modification ? self::charger_options( $id ) : [];
        $correcte = -1;
        foreach ( $options as $i => $o ) {
            if ( (int) $o['correcte'] === 1 ) {
                $correcte = $i;
            }
        }

        $erreurs = get_transient( 'npq_question_erreurs' );
        delete_transient( 'npq_question_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier la question' : 'Nouvelle question'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $importee ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Cette question vient de l'import.</strong>
                        Vos modifications seront <strong>écrasées au prochain import</strong>.
                        Pour les conserver durablement, modifiez le fichier
                        <code>question_bank.json</code>.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-questions' ) ); ?>">
                <input type="hidden" name="npq_question_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_question_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_scenario_id">Scénario <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <select name="npq_scenario_id" id="npq_scenario_id" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ( $scenarios_tous as $s ) : ?>
                                    <option value="<?php echo (int) $s['id']; ?>"
                                        data-certification="<?php echo (int) $s['certification_id']; ?>"
                                        <?php selected( $scenario_id, (int) $s['id'] ); ?>>
                                        <?php echo esc_html( $s['nom'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Le contexte dans lequel s'inscrit la question. Le candidat le lit
                                avant de répondre.
                            </p>
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
                                        (<?php echo (int) $d['nb']; ?> question<?php echo $d['nb'] > 1 ? 's' : ''; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" id="npq-certif-info" style="margin-top:6px">
                                Certification :
                                <strong id="npq-certif-nom"><?php
                                    $c_nom = '';
                                    foreach ( $certifications as $c ) {
                                        if ( (int) $c['id'] === (int) $certification_id ) {
                                            $c_nom = $c['nom'];
                                            break;
                                        }
                                    }
                                    echo esc_html( $c_nom !== '' ? $c_nom : '— choisissez un scénario —' );
                                ?></strong>
                                <br>Déduite du scénario. Les domaines proposés sont les siens.
                            </p>
                            <p class="description">
                                Le nombre entre parenthèses indique combien de questions ce domaine
                                contient déjà.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_enonce">Énoncé <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <textarea name="npq_enonce" id="npq_enonce" rows="4" class="large-text"
                                      required><?php echo esc_textarea( $enonce ); ?></textarea>
                            <p class="description">
                                La question posée au candidat. Elle doit s'appuyer sur le scénario.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Réponses</h2>
                <p class="description" style="margin-bottom:12px">
                    Les <?php echo self::NB_OPTIONS; ?> options sont obligatoires.
                    Cochez celle qui est correcte. Les mauvaises réponses doivent être
                    <strong>plausibles</strong> : c'est ce qui fait la difficulté d'une bonne question.
                </p>

                <table class="form-table" role="presentation">
                    <?php for ( $i = 0; $i < self::NB_OPTIONS; $i++ ) :
                        $texte = isset( $options[ $i ]['texte'] ) ? $options[ $i ]['texte'] : '';
                    ?>
                        <tr>
                            <th scope="row" style="width:120px">
                                <label>
                                    <input type="radio" name="npq_correcte" value="<?php echo $i; ?>"
                                        <?php checked( $correcte, $i ); ?>>
                                    <strong>Option <?php echo chr( 65 + $i ); ?></strong>
                                </label>
                            </th>
                            <td>
                                <textarea name="npq_option[<?php echo $i; ?>]" rows="2" class="large-text"
                                          required><?php echo esc_textarea( $texte ); ?></textarea>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </table>

                <h2>Explication</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_explication">Explication <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <textarea name="npq_explication" id="npq_explication" rows="6" class="large-text"
                                      required><?php echo esc_textarea( $explication ); ?></textarea>
                            <p class="description">
                                <strong>C'est le cœur pédagogique de NormaPrep.</strong>
                                Expliquez pourquoi la bonne réponse est correcte, et pourquoi les
                                autres ne le sont pas. Citez les articles de la norme quand c'est
                                pertinent (ex. : <em>« L'article 6.1.3 d) précise que… »</em>).
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Paramètres</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="npq_difficulte">Difficulté</label></th>
                        <td>
                            <select name="npq_difficulte" id="npq_difficulte">
                                <?php foreach ( [ 'easy' => 'Facile', 'medium' => 'Moyenne', 'hard' => 'Difficile' ] as $val => $lbl ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"
                                        <?php selected( $difficulte, $val ); ?>>
                                        <?php echo esc_html( $lbl ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_statut">Statut</label></th>
                        <td>
                            <select name="npq_statut" id="npq_statut">
                                <option value="publie" <?php selected( $statut, 'publie' ); ?>>
                                    Publiée (utilisable dans les examens)
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
                        <?php echo $modification ? 'Mettre à jour' : 'Créer la question'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-questions' ) ); ?>"
                       class="button">Annuler</a>
                </p>
            </form>
        </div>

        <script>
        /* Cascade : le scénario détermine la certification, qui détermine les
           domaines proposés. Choisir un scénario met donc à jour l'affichage de
           la certification et ne laisse que les domaines qui lui appartiennent.

           Les <option> hors périmètre sont retirées du DOM puis réinjectées :
           « display:none » sur une <option> n'est pas traité de la même façon
           par tous les navigateurs.
           (Le serveur revalide de toute façon à l'enregistrement.) */
        ( function () {
            var selectScenario = document.getElementById( 'npq_scenario_id' );
            var selectDomaine  = document.getElementById( 'npq_domaine' );
            var certifNom      = document.getElementById( 'npq-certif-nom' );

            if ( ! selectScenario || ! selectDomaine ) { return; }

            // Noms des certifications, pour l'affichage.
            var noms = <?php
                $map = [];
                foreach ( $certifications as $c ) {
                    $map[ (int) $c['id'] ] = $c['nom'];
                }
                echo wp_json_encode( $map );
            ?>;

            // Mémorise toutes les options de domaine une fois pour toutes.
            var tousDomaines = Array.prototype.slice.call( selectDomaine.options ).map( function ( o ) {
                return {
                    value: o.value,
                    text: o.text,
                    certif: parseInt( o.getAttribute( 'data-certification' ), 10 ) || 0
                };
            } );

            function certifDuScenario() {
                var opt = selectScenario.options[ selectScenario.selectedIndex ];
                if ( ! opt || opt.value === '' ) { return 0; }
                return parseInt( opt.getAttribute( 'data-certification' ), 10 ) || 0;
            }

            function majCascade() {
                var certif = certifDuScenario();

                // 1) Afficher la certification déduite.
                if ( certifNom ) {
                    certifNom.textContent = noms[ certif ] || '— choisissez un scénario —';
                }

                // 2) Ne garder que les domaines de cette certification.
                var valeurCourante = selectDomaine.value;
                selectDomaine.innerHTML = '';

                tousDomaines.forEach( function ( o ) {
                    // L'option vide (« — Choisir — ») est toujours conservée.
                    if ( o.value !== '' && o.certif !== certif ) { return; }

                    var opt = document.createElement( 'option' );
                    opt.value = o.value;
                    opt.text  = o.text;
                    if ( o.value === valeurCourante ) { opt.selected = true; }
                    selectDomaine.appendChild( opt );
                } );
            }

            selectScenario.addEventListener( 'change', majCascade );
            majCascade();
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
            "SELECT id, scenario_id, domaine, enonce, explication, difficulte, statut, ref_externe
             FROM {$p}question WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    private static function charger_options( $question_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT texte, correcte FROM {$p}option_reponse
             WHERE question_id = %d
             ORDER BY position ASC",
            $question_id
        ), ARRAY_A );
    }

    /**
     * TOUS les domaines, toutes certifications, avec leur nombre de questions.
     *
     * Le comptage joint sur la CERTIFICATION en plus du code : deux
     * certifications peuvent avoir chacune un domaine « D1 », et leurs
     * questions ne doivent pas être additionnées.
     */
    private static function domaines() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT d.code, d.libelle, d.certification_id,
                    COUNT(q.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}question q
                    ON q.domaine = d.code
                   AND q.certification_id = d.certification_id
                   AND q.statut = 'publie'
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

    /** La certification que porte un scénario. */
    private static function certification_du_scenario( $scenario_id ) {
        if ( ! $scenario_id ) {
            return 0;
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT certification_id FROM {$p}scenario WHERE id = %d",
            (int) $scenario_id
        ) );
    }

    private static function scenarios() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT id, nom, certification_id FROM {$p}scenario
             WHERE statut = 'publie'
             ORDER BY nom ASC",
            ARRAY_A
        );
    }

    /** Certification active — délègue à la résolution centralisée. */
    private static function certification_courante() {
        return NPQ_Certification::id();
    }

    private static function rediriger_formulaire( $id ) {
        $url = admin_url( 'admin.php?page=normaprep-questions&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
