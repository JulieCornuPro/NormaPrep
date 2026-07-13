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
    }

    /** Aiguille les actions vers les classes qui les gèrent. */
    public static function traiter_actions() {
        require_once NPQ_PATH . 'admin/class-npq-scenario-form.php';
        NPQ_Scenario_Form::traiter();

        require_once NPQ_PATH . 'admin/class-npq-question-form.php';
        NPQ_Question_Form::traiter();
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
            'Importer le contenu',
            'Import',
            'manage_options',
            'normaprep-import',
            [ 'NPQ_Importer', 'afficher_page' ]
        );
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
                $table->search_box( 'Rechercher', 'npq-recherche-question' );
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
                <?php $table->search_box( 'Rechercher', 'npq-recherche-scenario' ); ?>
            </form>

            <form method="post">
                <?php $table->display(); ?>
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
}
