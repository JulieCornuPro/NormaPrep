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
        </div>
        <?php
        return ob_get_clean();
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
