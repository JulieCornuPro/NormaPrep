<?php
/**
 * Création, modification et suppression des parcours de révision.
 *
 * Un parcours est une composition proposée sur la page « Révisions ». Il existe
 * désormais deux modes, exclusifs l'un de l'autre (colonne `type`) :
 *
 *   - 'criteres' : le parcours décrit des critères (domaines + nombre). Les
 *     questions sont tirées AU HASARD à chaque lancement. Bon pour le
 *     réentraînement : on ne revoit pas la même série.
 *
 *   - 'questions' : le parcours pointe une LISTE DE QUESTIONS choisies à la main
 *     (table de liaison `parcours_question`). Elles sont toujours les mêmes,
 *     dans le même ordre. Bon pour un parcours pédagogique construit.
 *
 * La sélection des questions (mode 'questions') se fait par cases à cocher
 * directement dans ce formulaire : toutes les questions publiées sont affichées,
 * groupées par domaine, avec un compteur de sélection tenu à jour en JavaScript.
 *
 * Le parcours appartient à une CERTIFICATION, choisie à la création
 * (pré-remplie sur la certification active). En modification elle est
 * verrouillée : la déplacer laisserait ses domaines et ses questions choisies
 * incohérents, puisque les uns comme les autres sont propres à une
 * certification.
 *
 * Calqué sur NPQ_Scenario_Form pour rester cohérent avec le reste de l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Parcours_Form {

    /**
     * Traite les actions (enregistrer, supprimer) avant tout affichage.
     * Appelée sur admin_init : on peut donc rediriger proprement.
     */
    public static function traiter() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['npq_parcours_action'] ) && $_POST['npq_parcours_action'] === 'enregistrer' ) {
            self::enregistrer();
        }

        if ( isset( $_GET['npq_action'] ) && $_GET['npq_action'] === 'supprimer_parcours' ) {
            self::supprimer();
        }
    }

    /* =====================================================================
     * ENREGISTREMENT
     * ===================================================================== */

    private static function enregistrer() {
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_parcours_form' ) ) {
            wp_die( 'Session expirée. Revenez en arrière et réessayez.' );
        }

        $id     = isset( $_POST['npq_id'] ) ? (int) $_POST['npq_id'] : 0;
        $titre  = sanitize_text_field( wp_unslash( $_POST['npq_titre'] ?? '' ) );
        $resume = sanitize_text_field( wp_unslash( $_POST['npq_resume'] ?? '' ) );
        $statut = ( ( $_POST['npq_statut'] ?? '' ) === 'brouillon' ) ? 'brouillon' : 'publie';

        // Mode de composition : 'criteres' (par défaut) ou 'questions'.
        $type = ( ( $_POST['npq_type'] ?? '' ) === 'questions' ) ? 'questions' : 'criteres';

        // Certification cible. À la CRÉATION seulement : en modification elle
        // est verrouillée, on reprend celle déjà enregistrée.
        if ( $id > 0 ) {
            $existant = self::charger( $id );
            $certification_id = $existant ? (int) $existant['certification_id'] : NPQ_Certification::id();
        } else {
            $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
            if ( ! self::certification_valide( $certification_id ) ) {
                $certification_id = NPQ_Certification::id();
            }
        }

        // --- Champs du mode « critères » ---
        $domaines_valides = self::codes_domaines_de( $certification_id );
        $domaines = isset( $_POST['npq_domaines'] )
            ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npq_domaines'] ) )
            : [];
        $domaines = array_values( array_intersect( $domaines, $domaines_valides ) );

        $nombre = isset( $_POST['npq_nombre'] ) ? (int) $_POST['npq_nombre'] : 10;
        $nombre = max( 5, min( 40, $nombre ) );

        // --- Champs du mode « questions » ---
        // Le JavaScript sérialise l'ordre choisi (ids séparés par des virgules)
        // dans un champ caché. On le découpe, on garde des entiers, on retire
        // les doublons en conservant l'ordre. La validation « la question existe
        // et est publiée » se fait dans enregistrer_liaison().
        $ordre_brut = isset( $_POST['npq_questions_ordre'] )
            ? sanitize_text_field( wp_unslash( $_POST['npq_questions_ordre'] ) )
            : '';
        $questions_choisies = [];
        foreach ( explode( ',', $ordre_brut ) as $morceau ) {
            $qid = (int) trim( $morceau );
            if ( $qid > 0 && ! in_array( $qid, $questions_choisies, true ) ) {
                $questions_choisies[] = $qid;
            }
        }

        // Validation.
        $erreurs = [];
        if ( $titre === '' ) {
            $erreurs[] = 'Le titre est obligatoire.';
        }
        if ( $type === 'questions' && empty( $questions_choisies ) ) {
            $erreurs[] = 'Sélectionnez au moins une question, ou choisissez le mode « par critères ».';
        }

        if ( ! empty( $erreurs ) ) {
            set_transient( 'npq_parcours_erreurs', $erreurs, 60 );
            self::rediriger_formulaire( $id );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // En mode « questions », le nombre effectif est le nombre de questions
        // choisies : on l'enregistre pour l'affichage (colonne « Questions »).
        if ( $type === 'questions' ) {
            $nombre = count( $questions_choisies );
        }

        $donnees = [
            'titre'    => $titre,
            'resume'   => $resume,
            'type'     => $type,
            'domaines' => wp_json_encode( $domaines ),
            'nombre'   => $nombre,
            'statut'   => $statut,
        ];

        if ( $id > 0 ) {
            $wpdb->update( "{$p}parcours", $donnees, [ 'id' => $id ] );
            $message = 'Parcours mis à jour.';
        } else {
            $donnees['certification_id'] = $certification_id;
            $donnees['position']         = self::prochaine_position();
            $donnees['date_creation']    = current_time( 'mysql' );

            $wpdb->insert( "{$p}parcours", $donnees );
            $id = (int) $wpdb->insert_id;
            $message = 'Parcours créé.';
        }

        // Synchronise la liste des questions choisies (mode « questions »).
        // En mode « critères », on vide la liaison : le parcours ne pointe plus
        // aucune question figée, il repart sur des critères.
        self::enregistrer_liaison(
            $id,
            $type === 'questions' ? $questions_choisies : [],
            $certification_id
        );

        set_transient( 'npq_parcours_message', $message, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-parcours' ) );
        exit;
    }

    /**
     * Réécrit la table de liaison parcours_question pour un parcours donné.
     * On efface puis on réinsère : simple et sûr pour une petite liste.
     * La position suit l'ordre de sélection (ordre des ids reçus), ce qui
     * prépare déjà une éventuelle réorganisation ultérieure (glisser-déposer).
     */
    private static function enregistrer_liaison( $parcours_id, $question_ids, $certification_id = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->delete( "{$p}parcours_question", [ 'parcours_id' => $parcours_id ] );

        if ( empty( $question_ids ) ) {
            return;
        }

        // Ne garder que des ids de questions réellement publiées ET appartenant
        // à la certification du parcours (liste blanche) : un parcours ne peut
        // pas piocher dans une autre certification.
        $placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
        $args = $question_ids;

        $clause_certif = '';
        if ( $certification_id > 0 ) {
            $clause_certif = ' AND certification_id = %d';
            $args[] = $certification_id;
        }

        $valides = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}question
             WHERE id IN ( $placeholders ) AND statut = 'publie'{$clause_certif}",
            $args
        ) );
        $valides = array_map( 'intval', $valides );

        // On réordonne selon l'ordre de sélection reçu, en ne gardant que les valides.
        $position = 1;
        foreach ( $question_ids as $qid ) {
            if ( ! in_array( $qid, $valides, true ) ) {
                continue;
            }
            $wpdb->insert( "{$p}parcours_question", [
                'parcours_id' => $parcours_id,
                'question_id' => $qid,
                'position'    => $position++,
            ] );
        }
    }

    /* =====================================================================
     * SUPPRESSION
     * ===================================================================== */

    private static function supprimer() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        if ( ! $id || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_supprimer_parcours_' . $id ) ) {
            wp_die( 'Lien invalide ou expiré.' );
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On retire d'abord les liaisons (les questions elles-mêmes ne sont pas
        // touchées : on ne supprime QUE le lien parcours <-> question).
        $wpdb->delete( "{$p}parcours_question", [ 'parcours_id' => $id ] );
        $wpdb->delete( "{$p}parcours", [ 'id' => $id ] );

        set_transient( 'npq_parcours_message', 'Parcours supprimé.', 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-parcours' ) );
        exit;
    }

    /* =====================================================================
     * FORMULAIRE
     * ===================================================================== */

    public static function afficher_formulaire() {
        $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        $parcours = $id ? self::charger( $id ) : null;
        $modification = ( $parcours !== null );

        $titre  = $modification ? $parcours['titre'] : '';
        $resume = $modification ? $parcours['resume'] : '';
        // Nombre borné à [5, 40] même à l'affichage : un parcours « questions »
        // stocke dans `nombre` sa quantité de questions, qui peut sortir de ces
        // bornes (ex. 1). On ne veut pas afficher une valeur invalide dans le
        // champ du bloc critères (min=5) : on la ramène dans l'intervalle.
        $nombre = $modification ? (int) $parcours['nombre'] : 10;
        $nombre = max( 5, min( 40, $nombre ) );
        $statut = $modification ? $parcours['statut'] : 'publie';
        $type   = $modification ? $parcours['type'] : 'criteres';

        $domaines_choisis = [];
        if ( $modification ) {
            $decode = json_decode( (string) $parcours['domaines'], true );
            if ( is_array( $decode ) ) {
                $domaines_choisis = $decode;
            }
        }

        // Questions déjà choisies (mode « questions »).
        $questions_choisies = $modification ? self::questions_du_parcours( $id ) : [];

        // Certification : celle du parcours en modification ; à la création,
        // celle passée en URL (après un changement dans le menu, qui recharge
        // la page pour que le serveur renvoie les bons domaines et questions),
        // sinon l'active.
        if ( $modification ) {
            $certification_id = (int) $parcours['certification_id'];
        } else {
            $certification_id = isset( $_GET['npq_certif'] ) ? (int) $_GET['npq_certif'] : 0;
            if ( ! self::certification_valide( $certification_id ) ) {
                $certification_id = NPQ_Certification::id();
            }
        }

        // Champs conservés à travers le rechargement (saisie non perdue).
        if ( ! $modification ) {
            if ( isset( $_GET['npq_titre_tmp'] ) ) {
                $titre = sanitize_text_field( wp_unslash( $_GET['npq_titre_tmp'] ) );
            }
            if ( isset( $_GET['npq_resume_tmp'] ) ) {
                $resume = sanitize_text_field( wp_unslash( $_GET['npq_resume_tmp'] ) );
            }
            if ( isset( $_GET['npq_type_tmp'] ) && $_GET['npq_type_tmp'] === 'questions' ) {
                $type = 'questions';
            }
        }

        $certifications = NPQ_Certification::toutes();

        $domaines_dispo = self::domaines_disponibles( $certification_id );
        $scenarios_dispo = self::scenarios_disponibles( $certification_id );
        $questions_par_domaine = self::questions_groupees_par_domaine( $certification_id );

        $erreurs = get_transient( 'npq_parcours_erreurs' );
        delete_transient( 'npq_parcours_erreurs' );
        ?>
        <div class="wrap">
            <h1><?php echo $modification ? 'Modifier le parcours' : 'Nouveau parcours'; ?></h1>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" id="npq-parcours-form" action="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-parcours' ) ); ?>">
                <input type="hidden" name="npq_parcours_action" value="enregistrer">
                <input type="hidden" name="npq_id" value="<?php echo (int) $id; ?>">
                <?php wp_nonce_field( 'npq_parcours_form', 'npq_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="npq_certification">Certification <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <?php if ( $modification ) : ?>
                                <?php
                                // Verrouillée : ses domaines et ses questions
                                // choisies n'auraient plus de sens ailleurs.
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
                                    La certification d'un parcours existant ne peut pas être
                                    changée : ses domaines et ses questions lui appartiennent.
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
                                <span id="npq-certif-chargement"
                                      style="display:none;margin-left:8px;color:#646970">
                                    Chargement…
                                </span>
                                <p class="description">
                                    Domaines et questions proposés dépendent de ce choix : changer
                                    de certification recharge la page (votre saisie est conservée).
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="npq_titre">Titre <span style="color:#d63638">*</span></label>
                        </th>
                        <td>
                            <input name="npq_titre" id="npq_titre" type="text" class="regular-text"
                                   value="<?php echo esc_attr( $titre ); ?>" required>
                            <p class="description">Le nom du parcours. Ex. : <em>Appréciation des risques</em></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="npq_resume">Résumé</label></th>
                        <td>
                            <input name="npq_resume" id="npq_resume" type="text" class="large-text"
                                   value="<?php echo esc_attr( $resume ); ?>">
                            <p class="description">Une ligne, affichée sur la carte du parcours.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Composition</th>
                        <td>
                            <fieldset>
                                <label style="display:block;margin:.2em 0">
                                    <input type="radio" name="npq_type" value="criteres"
                                           class="npq-type-choix" <?php checked( $type, 'criteres' ); ?>>
                                    <strong>Par critères</strong> — questions tirées au hasard
                                    dans les domaines choisis (série différente à chaque fois).
                                </label>
                                <label style="display:block;margin:.2em 0">
                                    <input type="radio" name="npq_type" value="questions"
                                           class="npq-type-choix" <?php checked( $type, 'questions' ); ?>>
                                    <strong>Questions choisies</strong> — vous sélectionnez
                                    précisément les questions (toujours les mêmes, dans l'ordre).
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- ============ BLOC « PAR CRITÈRES » ============ -->
                <div id="npq-bloc-criteres" class="npq-bloc-mode">
                    <h2 class="title" style="font-size:1.1em">Critères</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Domaines</th>
                            <td>
                                <p class="description" style="margin-top:0">
                                    Laissez tout décoché pour puiser dans l'ensemble du programme.
                                </p>
                                <?php if ( empty( $domaines_dispo ) ) : ?>
                                    <p><em>Aucun domaine défini pour la certification active.</em></p>
                                <?php else : ?>
                                    <fieldset>
                                        <?php foreach ( $domaines_dispo as $d ) :
                                            $coche = in_array( $d['code'], $domaines_choisis, true );
                                        ?>
                                            <label style="display:block;margin:.3em 0">
                                                <input type="checkbox" name="npq_domaines[]"
                                                       value="<?php echo esc_attr( $d['code'] ); ?>"
                                                       <?php checked( $coche ); ?>>
                                                <strong><?php echo esc_html( $d['code'] ); ?></strong>
                                                — <?php echo esc_html( $d['libelle'] ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="npq_nombre">Nombre de questions</label></th>
                            <td>
                                <input name="npq_nombre" id="npq_nombre" type="number"
                                       min="5" max="40" step="1"
                                       value="<?php echo (int) $nombre; ?>" class="small-text">
                                <p class="description">Entre 5 et 40. Limité par les questions disponibles.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============ BLOC « QUESTIONS CHOISIES » ============ -->
                <div id="npq-bloc-questions" class="npq-bloc-mode">
                    <h2 class="title" style="font-size:1.1em">
                        Questions choisies
                    </h2>
                    <p class="description">
                        À gauche, les questions disponibles (filtrables). Cliquez pour les
                        ajouter à droite. À droite, les questions du parcours : glissez-les
                        par la poignée <span aria-hidden="true">⠿</span> pour changer leur
                        ordre, qui sera celui présenté au candidat.
                    </p>

                    <?php if ( empty( $questions_par_domaine ) ) : ?>
                        <p><em>Aucune question publiée pour l'instant.</em></p>
                    <?php else : ?>
                        <!-- Champ caché : c'est LUI que PHP lit à l'enregistrement.
                             Le JavaScript y écrit l'ordre courant (ids séparés par
                             des virgules). Pré-rempli avec l'ordre existant pour que,
                             même sans JS, la valeur de départ soit correcte. -->
                        <input type="hidden" id="npq-questions-serialise" name="npq_questions_ordre"
                               value="<?php echo esc_attr( implode( ',', $questions_choisies ) ); ?>">

                        <div class="npq-selecteur">
                            <!-- Colonne gauche : disponibles + filtres -->
                            <div class="npq-colonne">
                                <div class="npq-colonne-titre">Questions disponibles</div>
                                <div class="npq-filtres">
                                    <select id="npq-filtre-domaine">
                                        <option value="">Tous les domaines</option>
                                        <?php foreach ( $domaines_dispo as $d ) : ?>
                                            <option value="<?php echo esc_attr( $d['code'] ); ?>">
                                                <?php echo esc_html( $d['code'] . ' — ' . $d['libelle'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ( ! empty( $scenarios_dispo ) ) : ?>
                                        <select id="npq-filtre-scenario">
                                            <option value="">Tous les scénarios</option>
                                            <?php foreach ( $scenarios_dispo as $s ) : ?>
                                                <option value="<?php echo (int) $s['id']; ?>">
                                                    <?php echo esc_html( $s['nom'] ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <input type="search" id="npq-recherche-q"
                                           placeholder="Rechercher un mot…">
                                </div>
                                <ul id="npq-dispo-liste" class="npq-liste"></ul>
                            </div>

                            <!-- Colonne droite : choisies, réordonnables -->
                            <div class="npq-colonne">
                                <div class="npq-colonne-titre">
                                    Questions du parcours
                                    <span style="font-weight:400;color:#646970">
                                        (<span id="npq-compteur">0</span>)
                                    </span>
                                </div>
                                <ul id="npq-choisies-liste" class="npq-liste npq-liste-choisies"></ul>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <style>
                /* Panneau à deux colonnes du sélecteur de questions. Styles
                   volontairement inline (un seul écran concerné) pour ne pas
                   ajouter de feuille CSS à charger. */
                .npq-selecteur { display:flex; gap:16px; align-items:flex-start; }
                .npq-selecteur .npq-colonne { flex:1; min-width:0; }
                .npq-colonne-titre { font-weight:600; margin-bottom:6px; }
                .npq-filtres { display:flex; gap:6px; margin-bottom:6px; }
                .npq-filtres select, .npq-filtres input { flex:1; min-width:0; }
                .npq-liste {
                    margin:0; list-style:none; height:420px; overflow:auto;
                    border:1px solid #dcdcde; background:#fff; border-radius:4px; padding:6px;
                }
                .npq-liste li {
                    display:flex; align-items:center; gap:8px; padding:6px 8px;
                    border:1px solid #f0f0f1; border-radius:4px; margin-bottom:5px;
                    background:#fff; line-height:1.35;
                }
                .npq-q-dispo { cursor:pointer; }
                .npq-q-dispo:hover { background:#f6f7f7; border-color:#c3c4c7; }
                .npq-q-choisie { cursor:default; background:#f6fbff; }
                .npq-q-poignee { cursor:grab; color:#8c8f94; font-size:16px; user-select:none; }
                .npq-q-dom {
                    flex:none; font-weight:600; font-size:11px; color:#1d2327;
                    background:#f0f0f1; border-radius:3px; padding:1px 6px;
                }
                .npq-q-texte { flex:1; min-width:0; font-size:13px; }
                .npq-q-ajouter, .npq-q-retirer { flex:none; text-decoration:none; }
                .npq-q-retirer { color:#b32d2e; font-weight:700; }
                .npq-q-vide { justify-content:center; color:#8c8f94; border:none; }
                .npq-q-placeholder {
                    height:34px; border:1px dashed #2271b1 !important;
                    background:#f0f6fc !important; border-radius:4px; margin-bottom:5px;
                }
                </style>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="npq_statut">Statut</label></th>
                        <td>
                            <select name="npq_statut" id="npq_statut">
                                <option value="publie" <?php selected( $statut, 'publie' ); ?>>
                                    Publié (proposé sur la page Révisions)
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
                        <?php echo $modification ? 'Mettre à jour' : 'Créer le parcours'; ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-parcours' ) ); ?>"
                       class="button">Annuler</a>
                </p>
            </form>
        </div>

        <script>
        /* Bascule entre le bloc « critères » et le bloc « questions » selon le
           mode choisi. La sélection des questions elle-même (deux colonnes,
           glisser-déposer, compteur) est gérée par npq-admin-parcours.js. */
        ( function () {
            var choix    = document.querySelectorAll( '.npq-type-choix' );
            var blocCrit = document.getElementById( 'npq-bloc-criteres' );
            var blocQues = document.getElementById( 'npq-bloc-questions' );

            /* Active ou désactive les champs d'un bloc. Un champ « disabled »
               n'est ni validé par le navigateur ni envoyé au serveur : c'est ce
               qui évite l'erreur « invalid form control is not focusable » sur un
               champ masqué (ex. npq_nombre, qui a min=5, quand on est en mode
               questions et que le bloc critères est caché). */
            function basculerChamps( bloc, actif ) {
                var champs = bloc.querySelectorAll( 'input, select, textarea' );
                champs.forEach( function ( c ) { c.disabled = ! actif; } );
            }

            function majAffichage() {
                var coche = document.querySelector( '.npq-type-choix:checked' );
                var mode  = coche ? coche.value : 'criteres';
                var estCrit = ( mode === 'criteres' );

                blocCrit.style.display = estCrit ? '' : 'none';
                blocQues.style.display = estCrit ? 'none' : '';

                // Seul le bloc visible participe à la validation et à l'envoi.
                basculerChamps( blocCrit, estCrit );
                basculerChamps( blocQues, ! estCrit );
            }

            choix.forEach( function ( r ) { r.addEventListener( 'change', majAffichage ); } );
            majAffichage();

            /* Changement de certification : on recharge la page pour que le
               serveur renvoie les domaines et les questions de la certification
               choisie. Un simple filtrage côté navigateur ne suffirait pas —
               les données ne sont pas chargées.

               La saisie en cours (titre, résumé, mode) est passée en URL pour
               ne pas être perdue. Uniquement à la création : en modification,
               la certification est verrouillée. */
            var selectCertif = document.getElementById( 'npq_certification' );
            var indicateur   = document.getElementById( 'npq-certif-chargement' );

            if ( selectCertif ) {
                selectCertif.addEventListener( 'change', function () {
                    if ( indicateur ) { indicateur.style.display = 'inline'; }

                    var champTitre  = document.getElementById( 'npq_titre' );
                    var champResume = document.getElementById( 'npq_resume' );
                    var modeCoche   = document.querySelector( '.npq-type-choix:checked' );

                    var params = new URLSearchParams();
                    params.set( 'page', 'normaprep-parcours' );
                    params.set( 'npq_vue', 'form' );
                    params.set( 'npq_certif', selectCertif.value );

                    if ( champTitre && champTitre.value ) {
                        params.set( 'npq_titre_tmp', champTitre.value );
                    }
                    if ( champResume && champResume.value ) {
                        params.set( 'npq_resume_tmp', champResume.value );
                    }
                    if ( modeCoche ) {
                        params.set( 'npq_type_tmp', modeCoche.value );
                    }

                    window.location.href = 'admin.php?' + params.toString();
                } );
            }
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
            "SELECT id, certification_id, titre, resume, type, domaines, nombre, statut
             FROM {$p}parcours WHERE id = %d",
            $id
        ), ARRAY_A );
    }

    /** Ids des questions rattachées à un parcours, dans l'ordre de position. */
    private static function questions_du_parcours( $parcours_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT question_id FROM {$p}parcours_question
             WHERE parcours_id = %d ORDER BY position ASC",
            $parcours_id
        ) ) );
    }

    /** Domaines disponibles (code + libellé) pour la certification courante. */
    private static function domaines_disponibles( $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = NPQ_Certification::id();
        }
        if ( ! $certification_id ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine
             WHERE certification_id = %d ORDER BY code ASC",
            $certification_id
        ), ARRAY_A );
    }

    /** Codes de domaine d'une certification donnée (liste blanche). */
    private static function codes_domaines_de( $certification_id ) {
        return array_map(
            function ( $d ) { return $d['code']; },
            self::domaines_disponibles( $certification_id )
        );
    }

    /**
     * Scénarios publiés de la certification, pour le filtre de la colonne de
     * gauche. Filtrer ici évite de proposer des scénarios d'une autre
     * certification, dont aucune question n'apparaîtrait dans la liste.
     */
    private static function scenarios_disponibles( $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = NPQ_Certification::id();
        }
        if ( ! $certification_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, nom FROM {$p}scenario
             WHERE certification_id = %d AND statut = 'publie'
             ORDER BY nom ASC",
            (int) $certification_id
        ), ARRAY_A );
    }

    /**
     * Toutes les questions publiées, groupées par domaine, pour l'affichage
     * en cases à cocher. À l'échelle du plugin (≈130 questions), on peut tout
     * charger d'un coup sans souci de performance.
     */
    private static function questions_groupees_par_domaine( $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = NPQ_Certification::id();
        }
        if ( ! $certification_id ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id, q.enonce, q.domaine, d.libelle AS domaine_libelle
             FROM {$p}question q
             LEFT JOIN {$p}domaine d ON d.code = q.domaine AND d.certification_id = q.certification_id
             WHERE q.certification_id = %d AND q.statut = 'publie'
             ORDER BY q.domaine ASC, q.id ASC",
            $certification_id
        ), ARRAY_A );

        $groupes = [];
        foreach ( $lignes as $l ) {
            $code = (string) $l['domaine'];
            if ( ! isset( $groupes[ $code ] ) ) {
                $groupes[ $code ] = [
                    'code'      => $code,
                    'libelle'   => (string) ( $l['domaine_libelle'] ?? '' ),
                    'questions' => [],
                ];
            }
            $groupes[ $code ]['questions'][] = [ 'id' => $l['id'], 'enonce' => $l['enonce'] ];
        }
        return array_values( $groupes );
    }

    private static function prochaine_position() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        return 1 + (int) $wpdb->get_var( "SELECT MAX(position) FROM {$p}parcours" );
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
        $url = admin_url( 'admin.php?page=normaprep-parcours&npq_vue=form' );
        if ( $id > 0 ) {
            $url = add_query_arg( 'id', $id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
