<?php
/**
 * Activité NormaPrep : les indicateurs de progression du candidat.
 *
 * Répond aux deux questions qu'un candidat se pose :
 *   « Suis-je en progrès ? »  -> courbe des scores dans le temps.
 *   « Sur quoi travailler ? » -> points faibles par domaine (à venir).
 *
 * Réutilise les composants dynamiques du thème (Carto.sparkline, etc.) pour
 * rester cohérent visuellement et éviter d'ajouter une bibliothèque.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Activite {

    const OPT_PAGE_ACTIVITE = 'npq_page_activite_id';

    /** Nombre d'examens montrés dans la courbe de progression. */
    const NB_EXAMENS_COURBE = 10;

    public static function init() {
        add_shortcode( 'npq_activite', [ __CLASS__, 'rendu' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_script' ] );
    }

    /**
     * Crée la page « Activité » à l'activation.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_ACTIVITE );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Activité',
            'post_name'    => 'activite',
            'post_content' => '[npq_activite]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_ACTIVITE, $page_id );
        }
    }

    /**
     * Charge le script qui alimente les composants du thème avec les données.
     */
    public static function charger_script() {
        $page_id = get_option( self::OPT_PAGE_ACTIVITE );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }
        wp_enqueue_script(
            'npq-activite',
            NPQ_URL . 'assets/npq-activite.js',
            // Dépend de la bibliothèque de composants du thème (Carto.sparkline…) :
            // WordPress garantit ainsi qu'elle est chargée avant notre script.
            [ 'carto-components' ],
            NPQ_VERSION,
            true
        );
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function rendu() {
        if ( ! is_user_logged_in() ) {
            return '<p class="empty">Vous devez être connecté(e) pour voir votre activité.</p>';
        }

        $examens = self::examens_recents();

        // Aucun examen : on n'affiche pas de courbe vide, on invite à commencer.
        if ( empty( $examens ) ) {
            return self::ecran_vide();
        }

        $chiffres = self::chiffres_cles( $examens );

        // Les scores, du plus ancien au plus récent (sens de lecture de la courbe).
        $scores = array_map( function ( $e ) {
            return (int) $e['score'];
        }, array_reverse( $examens ) );

        ob_start();
        ?>
        <div class="npq-activite">
            <h2>Mon activité</h2>
            <p class="npq-act-intro">
                Suivez votre progression au fil de vos examens blancs.
            </p>

            <!-- Progression -->
            <section class="npq-kpi-bloc reveal-on-scroll">
                <div class="sec-title">Ma progression</div>
                <p class="npq-kpi-aide">
                    Scores de vos <?php echo count( $examens ); ?> derniers examens,
                    du plus ancien au plus récent.
                </p>

                <div class="npq-courbe-cadre">
                    <div id="npq-courbe-progression"
                         data-scores="<?php echo esc_attr( wp_json_encode( $scores ) ); ?>"></div>
                </div>

                <div class="npq-chiffres-cles">
                    <div class="stat-block">
                        <div class="stat-block__value">
                            <?php echo (int) $chiffres['dernier']; ?><span class="accent">%</span>
                        </div>
                        <div class="stat-block__label">Dernier examen</div>
                        <div class="stat-block__sub"><?php echo esc_html( $chiffres['date_dernier'] ); ?></div>
                    </div>

                    <div class="stat-block">
                        <div class="stat-block__value">
                            <?php echo (int) $chiffres['meilleur']; ?><span class="accent">%</span>
                        </div>
                        <div class="stat-block__label">Meilleur score</div>
                        <div class="stat-block__sub">Votre record</div>
                    </div>

                    <div class="stat-block">
                        <?php if ( $chiffres['evolution'] === null ) : ?>
                            <div class="stat-block__value">&mdash;</div>
                            <div class="stat-block__label">Évolution</div>
                            <div class="stat-block__sub">Un seul examen pour l'instant</div>
                        <?php else :
                            $ev = (int) $chiffres['evolution'];
                            $classe = ( $ev > 0 ) ? 'hausse' : ( ( $ev < 0 ) ? 'baisse' : 'stable' );
                            $signe  = ( $ev > 0 ) ? '+' : '';
                        ?>
                            <div class="stat-block__value npq-ev-<?php echo $classe; ?>">
                                <?php echo $signe . $ev; ?><span class="accent">pts</span>
                            </div>
                            <div class="stat-block__label">Évolution</div>
                            <div class="stat-block__sub">Par rapport à l'examen précédent</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php
            $domaines = self::taux_par_domaine();
            $seuil    = self::seuil_reussite();
            ?>
            <?php if ( ! empty( $domaines ) ) : ?>
                <!-- Points faibles -->
                <section class="npq-kpi-bloc reveal-on-scroll">
                    <div class="sec-title">Mes points faibles</div>
                    <p class="npq-kpi-aide">
                        Taux de réussite par domaine, sur l'ensemble de vos examens.
                        Les domaines sous <?php echo (int) $seuil; ?> % sont à travailler.
                    </p>

                    <div class="npq-barres-cadre">
                        <div id="npq-barres-domaines"
                             data-domaines="<?php echo esc_attr( wp_json_encode( self::donnees_barres( $domaines, $seuil ) ) ); ?>"></div>
                    </div>

                    <!-- Légende : les codes sous les barres ne parlent pas seuls.
                         On donne ici le libellé complet, le taux, et sur combien de
                         questions il repose (un taux sur 1 question n'est pas fiable). -->
                    <div class="npq-legende-domaines">
                        <?php foreach ( $domaines as $d ) :
                            $faible = ( $d['taux'] < $seuil );
                        ?>
                            <div class="npq-legende-ligne<?php echo $faible ? ' faible' : ''; ?>">
                                <span class="npq-leg-code"><?php echo esc_html( $d['code'] ); ?></span>
                                <span class="npq-leg-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                                <span class="npq-leg-taux"><?php echo (int) $d['taux']; ?> %</span>
                                <span class="npq-leg-nb"><?php echo (int) $d['total']; ?> q.</span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // Le domaine le plus faible : on le met en avant avec une action.
                    $plus_faible = $domaines[0];
                    $url_revision = get_option( 'npq_page_revision_id' );
                    ?>
                    <?php if ( $plus_faible['taux'] < $seuil && $url_revision ) : ?>
                        <div class="npq-conseil">
                            <p>
                                Votre domaine le plus fragile est
                                <strong><?php echo esc_html( $plus_faible['libelle'] ); ?></strong>
                                (<?php echo (int) $plus_faible['taux']; ?> % sur
                                <?php echo (int) $plus_faible['total']; ?> question(s)).
                            </p>
                            <a href="<?php echo esc_url( get_permalink( $url_revision ) ); ?>" class="npq-btn">
                                Réviser ce domaine
                            </a>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php $volume = self::volume_travail(); ?>
            <?php if ( $volume && $volume['questions'] > 0 ) : ?>
                <!-- Volume de travail -->
                <section class="npq-kpi-bloc reveal-on-scroll">
                    <div class="sec-title">Mon volume de travail</div>
                    <p class="npq-kpi-aide">
                        Vos efforts accumulés, examens et révisions confondus.
                    </p>

                    <div class="npq-volume-grille">
                        <div class="npq-compteur"
                             data-valeur="<?php echo (int) $volume['questions']; ?>"
                             data-libelle="Questions travaillées"></div>

                        <div class="npq-compteur"
                             data-valeur="<?php echo (int) $volume['domaines_couverts']; ?>"
                             data-suffixe="/<?php echo (int) $volume['domaines_total']; ?>"
                             data-libelle="Domaines couverts"></div>

                        <div class="npq-compteur"
                             data-valeur="<?php echo (int) $volume['sessions_examens']; ?>"
                             data-libelle="Examens passés"></div>

                        <div class="npq-compteur"
                             data-valeur="<?php echo (int) $volume['sessions_revisions']; ?>"
                             data-libelle="Sessions de révision"></div>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Prépare les données pour le composant barChart du thème.
     * Chaque domaine devient une barre, colorée selon qu'il est acquis ou à travailler.
     */
    private static function donnees_barres( $domaines, $seuil ) {
        $barres = [];
        foreach ( $domaines as $d ) {
            $barres[] = [
                // Libellé court : le code du domaine (le nom complet est trop long
                // sous une barre). Le détail est donné dans la légende en dessous.
                'label'   => $d['code'],
                'value'   => (int) $d['taux'],
                'faible'  => ( $d['taux'] < $seuil ),
                'libelle' => $d['libelle'],
                'total'   => (int) $d['total'],
            ];
        }
        return $barres;
    }

    /**
     * Écran affiché quand le candidat n'a encore passé aucun examen.
     * Pas de courbe vide ni de zéros : on l'invite à commencer.
     */
    private static function ecran_vide() {
        $page_examen = get_option( 'npq_page_examen_id' );
        $url = $page_examen ? get_permalink( $page_examen ) : home_url( '/' );

        ob_start();
        ?>
        <div class="npq-activite">
            <h2>Mon activité</h2>
            <div class="npq-act-vide">
                <p>
                    Vous n'avez pas encore passé d'examen blanc. Vos indicateurs de
                    progression apparaîtront ici dès votre premier résultat.
                </p>
                <a href="<?php echo esc_url( $url ); ?>" class="npq-btn">Passer mon premier examen</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * DONNÉES
     * ===================================================================== */

    /**
     * Volume de travail du candidat : l'effort accumulé.
     *
     * Contrairement aux deux autres KPI, celui-ci INCLUT les révisions : il mesure
     * l'effort, pas la performance. Un candidat qui révise beaucoup travaille dur,
     * même s'il passe peu d'examens.
     */
    private static function volume_travail() {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Questions travaillées : toutes les réponses données, examens et révisions.
        $questions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             WHERE t.utilisateur_id = %d
               AND t.date_fin IS NOT NULL",
            $fiche['id']
        ) );

        // Domaines couverts : sur combien de domaines distincts a-t-il travaillé ?
        $domaines_couverts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT q.domaine)
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             INNER JOIN {$p}question  q ON q.id = r.question_id
             WHERE t.utilisateur_id = %d
               AND t.date_fin IS NOT NULL",
            $fiche['id']
        ) );

        // Total de domaines existants (pour donner le contexte : 5 sur 7).
        $domaines_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}domaine"
        );

        // Sessions, en distinguant examens et révisions.
        $sessions = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM( CASE WHEN mode  = 'revision' THEN 1 ELSE 0 END ) AS revisions,
                SUM( CASE WHEN mode <> 'revision' THEN 1 ELSE 0 END ) AS examens
             FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL",
            $fiche['id']
        ), ARRAY_A );

        return [
            'questions'         => $questions,
            'domaines_couverts' => $domaines_couverts,
            'domaines_total'    => $domaines_total,
            'sessions_examens'  => (int) ( $sessions['examens'] ?? 0 ),
            'sessions_revisions'=> (int) ( $sessions['revisions'] ?? 0 ),
        ];
    }

    /**
     * Taux de réussite par domaine, cumulé sur tous les EXAMENS du candidat.
     * (Les révisions sont exclues : on mesure la performance en épreuve.)
     *
     * Renvoie, par domaine : le libellé, le taux, le nombre de questions répondues.
     * Le nombre compte : un domaine avec une seule question donne 0 % ou 100 %,
     * ce qui n'est pas fiable — le candidat doit le savoir.
     */
    private static function taux_par_domaine() {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On croise les réponses du candidat avec le domaine de chaque question,
        // en ne gardant que les tentatives de type examen.
        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.domaine AS code,
                    COUNT(*) AS total,
                    SUM(r.correcte) AS reussies
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             INNER JOIN {$p}question  q ON q.id = r.question_id
             WHERE t.utilisateur_id = %d
               AND t.date_fin IS NOT NULL
               AND t.score IS NOT NULL
               AND t.mode <> 'revision'
             GROUP BY q.domaine
             ORDER BY q.domaine ASC",
            $fiche['id']
        ), ARRAY_A );

        if ( empty( $lignes ) ) {
            return [];
        }

        // Libellés lisibles des domaines.
        $libelles = [];
        $rows = $wpdb->get_results(
            "SELECT code, libelle FROM {$p}domaine",
            ARRAY_A
        );
        foreach ( (array) $rows as $r ) {
            $libelles[ $r['code'] ] = $r['libelle'];
        }

        $resultat = [];
        foreach ( $lignes as $l ) {
            $total    = (int) $l['total'];
            $reussies = (int) $l['reussies'];
            $taux     = $total > 0 ? (int) round( $reussies * 100 / $total ) : 0;

            $resultat[] = [
                'code'    => $l['code'],
                'libelle' => isset( $libelles[ $l['code'] ] ) ? $libelles[ $l['code'] ] : $l['code'],
                'taux'    => $taux,
                'total'   => $total,
            ];
        }

        // Du plus faible au plus fort : le candidat voit d'abord où ça coince.
        usort( $resultat, function ( $a, $b ) {
            return $a['taux'] <=> $b['taux'];
        } );

        return $resultat;
    }

    /** Seuil en dessous duquel un domaine est considéré comme à travailler. */
    private static function seuil_reussite() {
        return (int) get_option( 'npq_seuil_reussite', 70 );
    }

    /**
     * Les N derniers examens du candidat (révisions exclues), du plus récent au plus ancien.
     */
    private static function examens_recents() {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, score, reussi, date_debut
             FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL
               AND score IS NOT NULL
               AND mode <> 'revision'
             ORDER BY date_debut DESC
             LIMIT %d",
            $fiche['id'],
            self::NB_EXAMENS_COURBE
        ), ARRAY_A );
    }

    /**
     * Chiffres clés autour de la courbe.
     *
     * - dernier   : score du dernier examen (« où j'en suis »).
     * - meilleur  : record personnel (« ce dont je suis capable »).
     * - evolution : écart avec l'examen précédent (« suis-je en progrès ? »).
     *
     * On n'affiche pas la moyenne ici : elle est sur le tableau de bord, et sur une
     * page de progression une moyenne écrase justement la progression.
     */
    private static function chiffres_cles( $examens ) {
        // $examens est trié du plus récent au plus ancien.
        $dernier = (int) $examens[0]['score'];

        $scores = array_map( function ( $e ) { return (int) $e['score']; }, $examens );
        $meilleur = max( $scores );

        // Évolution : dernier moins avant-dernier (null s'il n'y a qu'un examen).
        $evolution = null;
        if ( count( $examens ) >= 2 ) {
            $evolution = $dernier - (int) $examens[1]['score'];
        }

        return [
            'dernier'      => $dernier,
            'meilleur'     => $meilleur,
            'evolution'    => $evolution,
            'date_dernier' => mysql2date( 'd/m/Y', $examens[0]['date_debut'] ),
        ];
    }
}
