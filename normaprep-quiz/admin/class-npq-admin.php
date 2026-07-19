<?php
/**
 * Administration du contenu NormaPrep.
 *
 * Tableau de bord qui répond à la question : « où dois-je enrichir ma banque ? »
 *
 * L'examen tire ses questions selon la pondération officielle PECB. Si un domaine
 * a tout juste le nombre requis, ses questions seront presque toutes tirées à
 * chaque examen — le candidat les reverra sans cesse. Ce tableau montre la marge
 * de chaque domaine et signale ceux à enrichir en priorité.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Admin {

    /** Marge en dessous de laquelle un domaine est jugé trop tendu. */
    const MARGE_CRITIQUE = 5;

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'ajouter_pages' ], 20 );

        // Traitement des actions (enregistrer, supprimer) avant tout affichage :
        // on peut ainsi rediriger proprement après l'opération.
        add_action( 'admin_init', [ __CLASS__, 'traiter_actions' ] );
        // Modification par drag & drop
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'charger_script_parcours' ] );
    }

    /** Aiguille les actions vers les classes qui les gèrent. */
    public static function traiter_actions() {
        require_once NPQ_PATH . 'admin/class-npq-certification-form.php';
        NPQ_Certification_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-domaine-form.php';
        NPQ_Domaine_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-examen-form.php';
        NPQ_Examen_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-scenario-form.php';
        NPQ_Scenario_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-question-form.php';
        NPQ_Question_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-flashcard-form.php';
        NPQ_Flashcard_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-parcours-form.php';
        NPQ_Parcours_Form::traiter();
    }

    /**
     * Structure du menu NormaPrep : Accueil, État du contenu, Import.
     *
     * WordPress crée par défaut un sous-menu qui répète le nom du menu parent —
     * ce qui est confus. On le renomme explicitement « Accueil ».
     */
    public static function ajouter_pages() {
        // Renomme le premier sous-menu (qui répétait « NormaPrep ») en « Accueil »,
        // et le fait pointer vers la vraie page d'accueil.
        add_submenu_page(
            'normaprep-quiz',
            'NormaPrep — Accueil',
            'Accueil',
            'manage_options',
            'normaprep-quiz',           // même identifiant que le menu parent
            [ __CLASS__, 'page_accueil' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'État du contenu',
            'État du contenu',
            'manage_options',
            'normaprep-contenu',
            [ __CLASS__, 'page_contenu' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Certifications',
            'Certifications',
            'manage_options',
            'normaprep-certifications',
            [ __CLASS__, 'page_certifications' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Domaines',
            'Domaines',
            'manage_options',
            'normaprep-domaines',
            [ __CLASS__, 'page_domaines' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Examens',
            'Examens',
            'manage_options',
            'normaprep-examens',
            [ __CLASS__, 'page_examens' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Scénarios',
            'Scénarios',
            'manage_options',
            'normaprep-scenarios',
            [ __CLASS__, 'page_scenarios' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Questions',
            'Questions',
            'manage_options',
            'normaprep-questions',
            [ __CLASS__, 'page_questions' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Parcours de révision',
            'Parcours de révision',
            'manage_options',
            'normaprep-parcours',
            [ __CLASS__, 'page_parcours' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Flashcards',
            'Flashcards',
            'manage_options',
            'normaprep-flashcards',
            [ __CLASS__, 'page_flashcards' ]
        );

        add_submenu_page(
            'normaprep-quiz',
            'Importer le contenu',
            'Import',
            'manage_options',
            'normaprep-import',
            [ 'NPQ_Importer', 'afficher_page' ]
        );
    }

    /* =====================================================================
     * PAGE : CERTIFICATIONS
     * ===================================================================== */

    public static function page_certifications() {
        require_once NPQ_PATH . 'admin/class-npq-table-certifications.php';
        require_once NPQ_PATH . 'admin/class-npq-certification-form.php';

        $table = new NPQ_Table_Certifications();
        $table->prepare_items();

        $message = get_transient( 'npq_certif_message' );
        delete_transient( 'npq_certif_message' );
        ?>
        <div class="wrap">
            <h1>Certifications</h1>

            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo $message['type'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html( $message['texte'] ); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                La certification <strong>active</strong> est celle sur laquelle porte votre
                travail : import de contenu, création de questions, scénarios, parcours et
                examens s'y rattachent. Une seule peut être active à la fois.
            </p>

            <?php
            $table->display();
            NPQ_Certification_Form::afficher_formulaire();
            ?>
        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : DOMAINES
     * ===================================================================== */

    public static function page_domaines() {
        require_once NPQ_PATH . 'admin/class-npq-domaine-form.php';

        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Domaine_Form::afficher_formulaire();
            return;
        }

        require_once NPQ_PATH . 'admin/class-npq-table-domaines.php';

        $table = new NPQ_Table_Domaines();
        $table->prepare_items();

        $message = get_transient( 'npq_domaine_message' );
        delete_transient( 'npq_domaine_message' );

        $url_nouveau = admin_url( 'admin.php?page=normaprep-domaines&npq_vue=form' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Domaines</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo $message['type'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html( $message['texte'] ); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Les domaines découpent une certification en thèmes. Questions, flashcards
                et parcours s'y rattachent. Deux certifications peuvent avoir chacune un
                domaine portant le même code : ils restent distincts.
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-domaines">
                <?php
                // Conserve le filtre certification lors d'un tri.
                $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;
                if ( $certif_filtre > 0 ) {
                    echo '<input type="hidden" name="npq_certif" value="' . (int) $certif_filtre . '">';
                }

                $table->display();
                ?>
            </form>
        </div>
        <?php
    }


    /* =====================================================================
     * PAGE : PARCOURS DE RÉVISION
     * ===================================================================== */

    public static function page_parcours() {
        require_once NPQ_PATH . 'admin/class-npq-parcours-form.php';

        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Parcours_Form::afficher_formulaire();
            return;
        }

        require_once NPQ_PATH . 'admin/class-npq-table-parcours.php';

        $table = new NPQ_Table_Parcours();
        $table->prepare_items();

        $message = get_transient( 'npq_parcours_message' );
        delete_transient( 'npq_parcours_message' );

        $erreurs = get_transient( 'npq_parcours_erreurs' );
        delete_transient( 'npq_parcours_erreurs' );

        $url_nouveau = admin_url( 'admin.php?page=normaprep-parcours&npq_vue=form' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Parcours de révision</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Les parcours sont les compositions préprogrammées proposées sur la
                page <strong>Révisions</strong>. Chacun pioche un nombre de questions
                dans les domaines choisis. Laissez tous les domaines décochés pour
                puiser dans l'ensemble du programme.
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-parcours">
                <?php
                // Conserve le filtre certification lors d'une recherche,
                // d'un tri ou d'un changement de page.
                $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;
                if ( $certif_filtre > 0 ) {
                    echo '<input type="hidden" name="npq_certif" value="' . (int) $certif_filtre . '">';
                }

                $table->search_box( 'Rechercher', 'npq-recherche-flashcards' );
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : FLASHCARDS
     * ===================================================================== */

    public static function page_flashcards() {
        require_once NPQ_PATH . 'admin/class-npq-flashcard-form.php';

        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Flashcard_Form::afficher_formulaire();
            return;
        }

        require_once NPQ_PATH . 'admin/class-npq-table-flashcards.php';

        $table = new NPQ_Table_Flashcards();
        $table->prepare_items();

        $message = get_transient( 'npq_flashcard_message' );
        delete_transient( 'npq_flashcard_message' );

        $url_nouveau = admin_url( 'admin.php?page=normaprep-flashcards&npq_vue=form' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Flashcards</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Les flashcards servent à <strong>mémoriser</strong> : articles, définitions,
                mesures de l'Annexe A. Contrairement aux questions d'examen, elles n'ont pas
                de scénario — elles sont générales et directes.
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-flashcards">
                <?php
                // Conserve le filtre certification lors d'une recherche,
                // d'un tri ou d'un changement de page.
                $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;
                if ( $certif_filtre > 0 ) {
                    echo '<input type="hidden" name="npq_certif" value="' . (int) $certif_filtre . '">';
                }

                $table->search_box( 'Rechercher', 'npq-recherche-flashcards' );
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : QUESTIONS
     * ===================================================================== */

    public static function page_questions() {
        require_once NPQ_PATH . 'admin/class-npq-question-form.php';

        // Vue « formulaire » : création ou modification.
        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Question_Form::afficher_formulaire();
            return;
        }

        require_once NPQ_PATH . 'admin/class-npq-table-questions.php';

        $table = new NPQ_Table_Questions();
        $table->prepare_items();

        $message = get_transient( 'npq_question_message' );
        delete_transient( 'npq_question_message' );

        $erreurs = get_transient( 'npq_question_erreurs' );
        delete_transient( 'npq_question_erreurs' );
        ?>
        <?php $url_nouveau = admin_url( 'admin.php?page=normaprep-questions&npq_vue=form' ); ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Questions</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Filtrez par domaine pour voir où votre banque est faible.
                Consultez <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-contenu' ) ); ?>">l'état
                du contenu</a> pour savoir quels domaines enrichir en priorité.
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-questions">
                <?php
                // Conserve le filtre certification lors d'une recherche,
                // d'un tri ou d'un changement de page.
                $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;
                if ( $certif_filtre > 0 ) {
                    echo '<input type="hidden" name="npq_certif" value="' . (int) $certif_filtre . '">';
                }

                $table->search_box( 'Rechercher', 'npq-recherche-flashcards' );
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : SCÉNARIOS
     * ===================================================================== */

    public static function page_scenarios() {
        require_once NPQ_PATH . 'admin/class-npq-scenario-form.php';

        // Vue « formulaire » : création ou modification.
        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Scenario_Form::afficher_formulaire();
            return;
        }

        // Vue « liste » (par défaut).
        require_once NPQ_PATH . 'admin/class-npq-table-scenarios.php';

        $table = new NPQ_Table_Scenarios();
        $table->prepare_items();

        // Message de confirmation après une action.
        $message = get_transient( 'npq_scenario_message' );
        delete_transient( 'npq_scenario_message' );

        $erreurs = get_transient( 'npq_scenario_erreurs' );
        delete_transient( 'npq_scenario_erreurs' );

        $url_nouveau = admin_url( 'admin.php?page=normaprep-scenarios&npq_vue=form' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Scénarios</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $erreurs ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <?php foreach ( (array) $erreurs as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:760px">
                Les scénarios portent les questions : chaque question s'inscrit dans le
                contexte d'une entreprise fictive. Un scénario <strong>importé</strong>
                sera mis à jour au prochain import ; un scénario <strong>créé ici</strong>
                ne sera jamais écrasé.
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-scenarios">
                <?php
                // Conserve le filtre certification lors d'une recherche
                // ou d'un changement de page.
                $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;
                if ( $certif_filtre > 0 ) {
                    echo '<input type="hidden" name="npq_certif" value="' . (int) $certif_filtre . '">';
                }

                $table->search_box( 'Rechercher', 'npq-recherche-scenarios' );
                $table->display();
                ?>
            </form>

        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : ACCUEIL (tableau de bord d'exploitation)
     * ===================================================================== */

    public static function page_accueil() {
        $contenu = self::statistiques();
        $usage   = self::statistiques_usage();
        ?>
        <div class="wrap">
            <h1>NormaPrep</h1>
            <p class="description" style="max-width:760px">
                Vue d'ensemble de votre plateforme d'examens blancs.
            </p>

            <!-- Activité de la plateforme -->
            <h2 style="margin-top:28px">Activité</h2>
            <div style="display:flex;gap:16px;flex-wrap:wrap;max-width:900px">

                <div class="card" style="flex:1;min-width:170px;padding:18px 20px;margin:0">
                    <p style="margin:0 0 6px;color:#646970;font-size:13px">Abonnés</p>
                    <p style="margin:0;font-size:28px;font-weight:600">
                        <?php echo (int) $usage['abonnes']; ?>
                    </p>
                    <p style="margin:6px 0 0;color:#646970;font-size:12px">
                        dont <?php echo (int) $usage['abonnes_actifs']; ?> avec un abonnement actif
                    </p>
                </div>

                <div class="card" style="flex:1;min-width:170px;padding:18px 20px;margin:0">
                    <p style="margin:0 0 6px;color:#646970;font-size:13px">Examens passés</p>
                    <p style="margin:0;font-size:28px;font-weight:600">
                        <?php echo (int) $usage['examens']; ?>
                    </p>
                    <p style="margin:6px 0 0;color:#646970;font-size:12px">
                        <?php echo (int) $usage['examens_reussis']; ?> réussi(s),
                        <?php echo (int) $usage['examens_abandonnes']; ?> abandonné(s)
                    </p>
                </div>

                <div class="card" style="flex:1;min-width:170px;padding:18px 20px;margin:0">
                    <p style="margin:0 0 6px;color:#646970;font-size:13px">Sessions de révision</p>
                    <p style="margin:0;font-size:28px;font-weight:600">
                        <?php echo (int) $usage['revisions']; ?>
                    </p>
                    <p style="margin:6px 0 0;color:#646970;font-size:12px">
                        entraînements terminés
                    </p>
                </div>

                <div class="card" style="flex:1;min-width:170px;padding:18px 20px;margin:0">
                    <p style="margin:0 0 6px;color:#646970;font-size:13px">Score moyen</p>
                    <p style="margin:0;font-size:28px;font-weight:600">
                        <?php echo ( $usage['score_moyen'] !== null ) ? (int) $usage['score_moyen'] . ' %' : '—'; ?>
                    </p>
                    <p style="margin:6px 0 0;color:#646970;font-size:12px">
                        sur l'ensemble des examens
                    </p>
                </div>
            </div>

            <!-- Contenu -->
            <h2 style="margin-top:32px">Contenu</h2>
            <table class="widefat" style="max-width:520px">
                <tbody>
                    <tr>
                        <td><strong>Questions</strong></td>
                        <td><?php echo (int) $contenu['questions']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Scénarios</strong></td>
                        <td><?php echo (int) $contenu['scenarios']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Flashcards</strong></td>
                        <td><?php echo (int) $contenu['flashcards']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Domaines</strong></td>
                        <td><?php echo (int) $contenu['domaines']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Certifications</strong></td>
                        <td><?php echo (int) $contenu['certifications']; ?></td>
                    </tr>
                </tbody>
            </table>

            <?php
            // Alerte si un domaine est trop tendu pour un examen varié.
            $tendus = array_filter( self::couverture_pecb(), function ( $c ) {
                return $c['marge'] < self::MARGE_CRITIQUE;
            } );
            ?>
            <?php if ( ! empty( $tendus ) ) : ?>
                <div class="notice notice-warning inline" style="max-width:760px;margin-top:20px">
                    <p>
                        <strong><?php echo count( $tendus ); ?> domaine(s)</strong> n'ont pas assez
                        de questions pour que vos examens soient variés.
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-contenu' ) ); ?>">
                            Voir le détail
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Raccourcis -->
            <h2 style="margin-top:32px">Accès rapide</h2>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-contenu' ) ); ?>"
                   class="button">État du contenu</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-import' ) ); ?>"
                   class="button">Importer le contenu</a>
            </p>
        </div>
        <?php
    }

    /* =====================================================================
     * PAGE : ÉTAT DU CONTENU
     * ===================================================================== */

    public static function page_contenu() {
        $stats       = self::statistiques();
        $couverture  = self::couverture_pecb();
        $ponderation = NPQ_Examen::PONDERATION_PECB;
        $total_pecb  = array_sum( $ponderation );

        // Aucun contenu : on invite à importer, et on s'arrête là.
        if ( $stats['questions'] === 0 ) {
            ?>
            <div class="wrap">
                <h1>État du contenu</h1>
                <div class="notice notice-warning">
                    <p>
                        Aucune question en base. Lancez l'import depuis la page
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-quiz' ) ); ?>">NormaPrep</a>.
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1>État du contenu</h1>

            <!-- Vue d'ensemble -->
            <h2>Vue d'ensemble</h2>
            <table class="widefat" style="max-width:640px">
                <tbody>
                    <tr>
                        <td><strong>Certifications</strong></td>
                        <td><?php echo (int) $stats['certifications']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Scénarios</strong></td>
                        <td><?php echo (int) $stats['scenarios']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Questions</strong></td>
                        <td><?php echo (int) $stats['questions']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Domaines</strong></td>
                        <td><?php echo (int) $stats['domaines']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Questions par examen</strong></td>
                        <td><?php echo (int) $total_pecb; ?> (pondération PECB)</td>
                    </tr>
                </tbody>
            </table>

            <!-- Couverture par domaine : le cœur de cette page -->
            <h2 style="margin-top:32px">Couverture par domaine</h2>
            <p class="description" style="max-width:760px">
                Chaque examen tire un nombre précis de questions par domaine, selon la
                pondération PECB. <strong>Plus la marge est faible, moins l'examen est
                varié</strong> : un domaine qui a 16 questions en banque et en fournit 15
                par examen donnera pratiquement toujours les mêmes.
                Enrichissez en priorité les domaines signalés.
            </p>

            <table class="widefat striped" style="max-width:900px">
                <thead>
                    <tr>
                        <th>Domaine</th>
                        <th style="width:110px">En banque</th>
                        <th style="width:130px">Par examen</th>
                        <th style="width:90px">Marge</th>
                        <th style="width:170px">Renouvellement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $couverture as $c ) :
                        $tendu = ( $c['marge'] < self::MARGE_CRITIQUE );
                        $manque = ( $c['marge'] < 0 );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $c['code'] ); ?></strong>
                                &nbsp;<?php echo esc_html( $c['libelle'] ); ?>
                            </td>
                            <td><?php echo (int) $c['en_banque']; ?></td>
                            <td><?php echo (int) $c['par_examen']; ?></td>
                            <td>
                                <?php if ( $manque ) : ?>
                                    <span style="color:#d63638;font-weight:600">
                                        <?php echo (int) $c['marge']; ?>
                                    </span>
                                <?php elseif ( $tendu ) : ?>
                                    <span style="color:#dba617;font-weight:600">
                                        +<?php echo (int) $c['marge']; ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color:#00a32a">
                                        +<?php echo (int) $c['marge']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $manque ) : ?>
                                    <strong style="color:#d63638">Insuffisant</strong>
                                <?php else : ?>
                                    <?php echo (int) $c['renouvellement']; ?> %
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description" style="max-width:760px;margin-top:12px">
                Le <strong>renouvellement</strong> indique la part des questions du domaine
                qui ne sera <em>pas</em> tirée à un examen donné. À 0 %, toutes les questions
                du domaine sortent à chaque fois.
            </p>

            <!-- Ce qu'il faudrait pour bien faire -->
            <?php $conseils = self::conseils( $couverture ); ?>
            <?php if ( ! empty( $conseils ) ) : ?>
                <h2 style="margin-top:32px">À faire en priorité</h2>
                <div class="notice notice-warning inline" style="max-width:760px">
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ( $conseils as $conseil ) : ?>
                            <li><?php echo wp_kses_post( $conseil ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                <h2 style="margin-top:32px">Couverture</h2>
                <div class="notice notice-success inline" style="max-width:760px">
                    <p>Tous les domaines ont une marge confortable. Vos examens seront bien variés.</p>
                </div>
            <?php endif; ?>

            <!-- Répartition par scénario -->
            <h2 style="margin-top:32px">Questions par scénario</h2>
            <table class="widefat striped" style="max-width:640px">
                <thead>
                    <tr><th>Scénario</th><th style="width:120px">Questions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( self::par_scenario() as $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( $s['nom'] ); ?></td>
                            <td><?php echo (int) $s['nb']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* =====================================================================
     * EXAMENS
     * ===================================================================== */
    public static function page_examens() {
        require_once NPQ_PATH . 'admin/class-npq-examen-form.php';

        $vue = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : 'liste';

        if ( $vue === 'form' ) {
            NPQ_Examen_Form::afficher_formulaire();
            return;
        }

        require_once NPQ_PATH . 'admin/class-npq-table-examens.php';

        $table = new NPQ_Table_Examens();
        $table->prepare_items();

        $message = get_transient( 'npq_examen_message' );
        delete_transient( 'npq_examen_message' );

        $url_nouveau = admin_url( 'admin.php?page=normaprep-examens&npq_vue=form' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Examens</h1>
            <a href="<?php echo esc_url( $url_nouveau ); ?>" class="page-title-action">Ajouter</a>
            <hr class="wp-header-end">

            <?php if ( $message ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                Un examen de type « par scénarios » tire ses questions parmi celles des
                scénarios rattachés, à chaque passage. Rattachez assez de scénarios pour
                atteindre le nombre de questions visé (80 pour une simulation complète).
            </p>

            <form method="get">
                <input type="hidden" name="page" value="normaprep-examens">
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    /* =====================================================================
     * DONNÉES
     * ===================================================================== */

    /**
     * Statistiques d'usage de la plateforme (activité réelle des abonnés).
     */
    private static function statistiques_usage() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Abonnés (fiches métier), et ceux qui ont un abonnement actif.
        $abonnes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}utilisateur" );

        $abonnes_actifs = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT utilisateur_id)
             FROM {$p}abonnement
             WHERE statut = 'actif'"
        );

        // Examens : passés, réussis, abandonnés.
        // (Un abandon a date_fin renseignée mais score NULL.)
        $examens = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative
             WHERE mode <> 'revision'
               AND date_fin IS NOT NULL
               AND score IS NOT NULL"
        );

        $examens_reussis = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative
             WHERE mode <> 'revision'
               AND date_fin IS NOT NULL
               AND reussi = 1"
        );

        $examens_abandonnes = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative
             WHERE mode = 'examen'
               AND date_fin IS NOT NULL
               AND score IS NULL"
        );

        // Révisions terminées.
        $revisions = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}tentative
             WHERE mode = 'revision'
               AND date_fin IS NOT NULL"
        );

        // Score moyen sur l'ensemble des examens notés.
        $score_moyen = $wpdb->get_var(
            "SELECT AVG(score) FROM {$p}tentative
             WHERE mode <> 'revision'
               AND date_fin IS NOT NULL
               AND score IS NOT NULL"
        );

        return [
            'abonnes'            => $abonnes,
            'abonnes_actifs'     => $abonnes_actifs,
            'examens'            => $examens,
            'examens_reussis'    => $examens_reussis,
            'examens_abandonnes' => $examens_abandonnes,
            'revisions'          => $revisions,
            'score_moyen'        => ( $score_moyen !== null ) ? round( (float) $score_moyen ) : null,
        ];
    }

    /** Compteurs généraux. */
    private static function statistiques() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return [
            'certifications' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}certification" ),
            'scenarios'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}scenario WHERE statut = 'publie'" ),
            'questions'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}question WHERE statut = 'publie'" ),
            'domaines'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}domaine" ),
            'flashcards'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}flashcard WHERE statut = 'publie'" ),
        ];
    }

    /**
     * Couverture de chaque domaine par rapport à la pondération PECB.
     *
     * Pour chaque domaine : combien de questions en banque, combien l'examen en
     * demande, quelle marge, et quel taux de renouvellement entre deux examens.
     */
    private static function couverture_pecb() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Questions disponibles par domaine.
        $en_banque = [];
        $lignes = (array) $wpdb->get_results(
            "SELECT domaine, COUNT(*) AS nb
             FROM {$p}question
             WHERE statut = 'publie'
             GROUP BY domaine",
            ARRAY_A
        );
        foreach ( $lignes as $l ) {
            $en_banque[ $l['domaine'] ] = (int) $l['nb'];
        }

        // Libellés lisibles.
        $libelles = [];
        $rows = (array) $wpdb->get_results( "SELECT code, libelle FROM {$p}domaine", ARRAY_A );
        foreach ( $rows as $r ) {
            $libelles[ $r['code'] ] = $r['libelle'];
        }

        $resultat = [];
        foreach ( NPQ_Examen::PONDERATION_PECB as $code => $par_examen ) {
            $dispo = isset( $en_banque[ $code ] ) ? $en_banque[ $code ] : 0;
            $marge = $dispo - (int) $par_examen;

            // Renouvellement : part des questions NON tirées à un examen donné.
            $renouvellement = ( $dispo > 0 )
                ? (int) round( max( 0, $dispo - $par_examen ) * 100 / $dispo )
                : 0;

            $resultat[] = [
                'code'           => $code,
                'libelle'        => isset( $libelles[ $code ] ) ? $libelles[ $code ] : '',
                'en_banque'      => $dispo,
                'par_examen'     => (int) $par_examen,
                'marge'          => $marge,
                'renouvellement' => $renouvellement,
            ];
        }

        // Du plus tendu au plus confortable : ce qui manque saute aux yeux.
        usort( $resultat, function ( $a, $b ) {
            return $a['marge'] <=> $b['marge'];
        } );

        return $resultat;
    }

    /** Conseils concrets, tirés de la couverture. */
    private static function conseils( $couverture ) {
        $conseils = [];

        foreach ( $couverture as $c ) {
            $nom = '<strong>' . esc_html( $c['code'] ) . '</strong> ('
                 . esc_html( $c['libelle'] ) . ')';

            if ( $c['marge'] < 0 ) {
                $conseils[] = sprintf(
                    '%s : il manque <strong>%d question(s)</strong> pour composer un examen '
                    . 'complet. L\'examen sera plus court que prévu.',
                    $nom,
                    abs( $c['marge'] )
                );
            } elseif ( $c['marge'] < self::MARGE_CRITIQUE ) {
                $conseils[] = sprintf(
                    '%s : marge de seulement <strong>+%d</strong> (%d en banque, %d par examen). '
                    . 'Le candidat reverra presque toujours les mêmes questions. '
                    . 'Ajoutez-en au moins %d pour un renouvellement correct.',
                    $nom,
                    $c['marge'],
                    $c['en_banque'],
                    $c['par_examen'],
                    max( 5, $c['par_examen'] )
                );
            }
        }

        return $conseils;
    }

    /** Nombre de questions par scénario. */
    private static function par_scenario() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT s.nom, COUNT(q.id) AS nb
             FROM {$p}scenario s
             LEFT JOIN {$p}question q ON q.scenario_id = s.id AND q.statut = 'publie'
             WHERE s.statut = 'publie'
             GROUP BY s.id, s.nom
             ORDER BY s.nom ASC",
            ARRAY_A
        );
    }

    /**
     * Charge le sélecteur de questions (glisser-déposer) uniquement sur
     * l'écran du formulaire de parcours. Passe au JavaScript la liste des
     * questions publiées et la sélection courante.
     *
     * La certification est résolue dans le même ordre que le formulaire :
     *   1. celle du parcours édité (modification) ;
     *   2. celle passée en URL (après changement dans le menu, qui recharge) ;
     *   3. à défaut, la certification active.
     * Sans cela, le panneau afficherait les questions d'une autre certification
     * que celle choisie.
     */
    public static function charger_script_parcours( $hook ) {
        // On ne cible que notre page parcours, en vue « form ».
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $vue  = isset( $_GET['npq_vue'] ) ? sanitize_key( $_GET['npq_vue'] ) : '';
        if ( $page !== 'normaprep-parcours' || $vue !== 'form' ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $parcours_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        // 1) Certification du parcours édité.
        $certification_id = 0;
        if ( $parcours_id > 0 ) {
            $certification_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT certification_id FROM {$p}parcours WHERE id = %d",
                $parcours_id
            ) );
        }

        // 2) Sinon, celle choisie dans le menu (passée en URL au rechargement).
        if ( ! $certification_id && isset( $_GET['npq_certif'] ) ) {
            $candidate = (int) $_GET['npq_certif'];
            $existe = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}certification WHERE id = %d",
                $candidate
            ) );
            if ( $existe ) {
                $certification_id = $candidate;
            }
        }

        // 3) À défaut, la certification active.
        if ( ! $certification_id ) {
            $certification_id = NPQ_Certification::id();
        }

        // Questions publiées de cette certification.
        $questions = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, enonce, domaine, scenario_id
             FROM {$p}question
             WHERE certification_id = %d AND statut = 'publie'
             ORDER BY domaine ASC, id ASC",
            $certification_id
        ), ARRAY_A );

        // Normalisation en types simples pour le JSON.
        $liste = array_map( function ( $q ) {
            return [
                'id'       => (int) $q['id'],
                'enonce'   => (string) $q['enonce'],
                'domaine'  => (string) $q['domaine'],
                'scenario' => (int) $q['scenario_id'], // 0 si pas de scénario
            ];
        }, $questions );

        // Sélection courante (si on édite un parcours existant), dans l'ordre.
        $choisies = [];
        if ( $parcours_id > 0 ) {
            $choisies = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT question_id FROM {$p}parcours_question
                 WHERE parcours_id = %d ORDER BY position ASC",
                $parcours_id
            ) ) );
        }

        wp_enqueue_script(
            'npq-admin-parcours',
            NPQ_URL . 'assets/npq-admin-parcours.js',
            [ 'jquery', 'jquery-ui-sortable' ], // dépendances fournies par WordPress
            NPQ_VERSION,
            true
        );

        wp_localize_script( 'npq-admin-parcours', 'NPQ_PARCOURS', [
            'questions' => $liste,
            'choisies'  => array_values( $choisies ),
        ] );
    }
}
