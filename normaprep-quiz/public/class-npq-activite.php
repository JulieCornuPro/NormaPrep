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

        // Toute la page est relative à UNE certification : mélanger les
        // référentiels n'aurait aucun sens (un score de 72 % en Lead Auditor
        // ne se moyenne pas avec un 65 % en Lead Implementer).
        $certifs          = NPQ_Bibliotheque::certifications_utilisateur();
        $certification_id = self::certification_choisie( $certifs );

        if ( ! $certification_id ) {
            return '<p class="empty">Aucune certification disponible.</p>';
        }

        $selecteur = self::selecteur_certification( $certifs, $certification_id );
        $examens   = self::examens_recents( $certification_id );

        // Aucun examen : on n'affiche pas de courbe vide, on invite à commencer.
        // Le sélecteur reste affiché — sans lui, un candidat qui n'a pas encore
        // passé d'examen sur la certification choisie n'aurait aucun moyen de
        // revenir sur celle où il a travaillé.
        if ( empty( $examens ) ) {
            return self::ecran_vide( $selecteur );
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

            <?php echo $selecteur; ?>

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

                    <?php
                    // Écart au seuil de réussite, plutôt que l'écart avec
                    // l'examen précédent.
                    //
                    // Deux points de mesure ne font pas une tendance : sur des
                    // tirages aléatoires, un candidat stable voyait alterner
                    // « +6 pts » et « −6 pts », lus comme des progrès et des
                    // rechutes. Surtout, la question qui décide de tout est
                    // binaire — suis-je au-dessus du seuil ? — et aucun
                    // indicateur n'y répondait.
                    $seuil_reussite = self::seuil_reussite();
                    $ecart  = (int) $chiffres['dernier'] - $seuil_reussite;
                    $classe = ( $ecart >= 0 ) ? 'hausse' : 'baisse';
                    $signe  = ( $ecart > 0 ) ? '+' : '';
                    ?>
                    <div class="stat-block">
                        <div class="stat-block__value npq-ev-<?php echo $classe; ?>">
                            <?php echo $signe . $ecart; ?><span class="accent">pts</span>
                        </div>
                        <div class="stat-block__label">
                            <?php echo ( $ecart >= 0 ) ? 'Au-dessus du seuil' : 'Sous le seuil'; ?>
                        </div>
                        <div class="stat-block__sub">
                            <?php if ( $ecart >= 0 ) : ?>
                                Votre dernier examen valide les <?php echo $seuil_reussite; ?> % requis
                            <?php else : ?>
                                Il vous manque <?php echo abs( $ecart ); ?> points pour atteindre
                                les <?php echo $seuil_reussite; ?> % requis
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <?php
            $domaines = self::taux_par_domaine( $certification_id );
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

                    // Le lien porte le domaine ET la certification, pour que la
                    // page Révisions arrive pré-réglée. Sans ces paramètres, le
                    // bouton promettait de réviser CE domaine puis déposait le
                    // candidat sur la page nue, à lui de retrouver lequel.
                    // L'ancre l'amène directement au formulaire, situé sous les
                    // parcours proposés.
                    $url_reviser = $url_revision
                        ? add_query_arg(
                            [
                                'npq_domaine' => $plus_faible['code'],
                                'npq_certif'  => $certification_id,
                            ],
                            get_permalink( $url_revision )
                          ) . '#npq-composer'
                        : '';
                    ?>
                    <?php if ( $plus_faible['taux'] < $seuil && $url_reviser ) : ?>
                        <div class="npq-conseil">
                            <p>
                                Votre domaine le plus fragile est
                                <strong><?php echo esc_html( $plus_faible['libelle'] ); ?></strong>
                                (<?php echo (int) $plus_faible['taux']; ?> % sur
                                <?php echo (int) $plus_faible['total']; ?> question(s)).
                            </p>
                            <a href="<?php echo esc_url( $url_reviser ); ?>" class="npq-btn">
                                Réviser ce domaine
                            </a>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php $volume = self::volume_travail( $certification_id ); ?>
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
    private static function ecran_vide( $selecteur = '' ) {
        $page_examen = get_option( 'npq_page_examen_id' );
        $url = $page_examen ? get_permalink( $page_examen ) : home_url( '/' );

        ob_start();
        ?>
        <div class="npq-activite">
            <h2>Mon activité</h2>

            <?php echo $selecteur; ?>

            <div class="npq-act-vide">
                <p>
                    <?php if ( $selecteur !== '' ) : ?>
                        Vous n'avez pas encore passé d'examen blanc sur cette
                        certification. Choisissez-en une autre ci-dessus, ou
                        lancez votre premier examen.
                    <?php else : ?>
                        Vous n'avez pas encore passé d'examen blanc. Vos indicateurs de
                        progression apparaîtront ici dès votre premier résultat.
                    <?php endif; ?>
                </p>
                <a href="<?php echo esc_url( $url ); ?>" class="npq-btn">Passer mon premier examen</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * CERTIFICATION AFFICHÉE
     * ===================================================================== */

    /**
     * Certification consultée et sélecteur : mutualisés avec la page Examens,
     * qui présente exactement le même choix. Voir NPQ_Bibliotheque.
     */
    private static function certification_choisie( $certifs ) {
        return NPQ_Bibliotheque::certification_choisie( $certifs );
    }

    private static function selecteur_certification( $certifs, $actuelle ) {
        return NPQ_Bibliotheque::selecteur_certification(
            $certifs, $actuelle, 'npq-act-certif-select'
        );
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
    private static function volume_travail( $certification_id ) {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Questions travaillées : celles auxquelles le candidat a réellement
        // répondu. La correction enregistre aussi une ligne pour les questions
        // laissées blanches (elles comptent fausses au barème) ; les inclure
        // ici gonflerait l'effort — rendre copie blanche n'est pas travailler.
        // Une question répondue est une question ayant au moins une option
        // cochée.
        $questions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             WHERE t.utilisateur_id = %d
               AND t.certification_id = %d
               AND t.date_fin IS NOT NULL
               AND EXISTS ( SELECT 1 FROM {$p}reponse_option ro
                            WHERE ro.reponse_id = r.id )",
            $fiche['id'],
            $certification_id
        ) );

        // Domaines couverts : sur combien de domaines distincts a-t-il travaillé ?
        // Même règle : un domaine seulement effleuré par des questions non
        // répondues n'est pas un domaine couvert.
        $domaines_couverts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT q.domaine)
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             INNER JOIN {$p}question  q ON q.id = r.question_id
             WHERE t.utilisateur_id = %d
               AND t.certification_id = %d
               AND t.date_fin IS NOT NULL
               AND EXISTS ( SELECT 1 FROM {$p}reponse_option ro
                            WHERE ro.reponse_id = r.id )",
            $fiche['id'],
            $certification_id
        ) );

        // Total de domaines existants (pour donner le contexte : 5 sur 7).
        // Filtré sur la certification : sans cela, le dénominateur cumulait les
        // domaines de TOUS les référentiels et affichait « 5 sur 34 ».
        $domaines_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}domaine WHERE certification_id = %d",
            $certification_id
        ) );

        // Sessions, en distinguant examens et révisions.
        $sessions = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM( CASE WHEN mode  = 'revision' THEN 1 ELSE 0 END ) AS revisions,
                SUM( CASE WHEN mode <> 'revision' THEN 1 ELSE 0 END ) AS examens
             FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND certification_id = %d
               AND date_fin IS NOT NULL",
            $fiche['id'],
            $certification_id
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
    private static function taux_par_domaine( $certification_id ) {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On croise les réponses du candidat avec le domaine de chaque question,
        // en ne gardant que les tentatives de type examen.
        //
        // Le filtre sur la certification est indispensable : les codes de
        // domaine (D1, D2…) sont réutilisés d'un référentiel à l'autre. Sans
        // lui, le GROUP BY fusionnait sous une même barre les résultats de
        // deux certifications sans rapport.
        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.domaine AS code,
                    COUNT(*) AS total,
                    SUM(r.correcte) AS reussies
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             INNER JOIN {$p}question  q ON q.id = r.question_id
             WHERE t.utilisateur_id = %d
               AND t.certification_id = %d
               AND t.date_fin IS NOT NULL
               AND t.score IS NOT NULL
               AND t.mode <> 'revision'
             GROUP BY q.domaine
             ORDER BY q.domaine ASC",
            $fiche['id'],
            $certification_id
        ), ARRAY_A );

        if ( empty( $lignes ) ) {
            return [];
        }

        // Libellés lisibles des domaines, ceux de CETTE certification : deux
        // référentiels donnent des intitulés différents au même code D1.
        $libelles = [];
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine WHERE certification_id = %d",
            $certification_id
        ), ARRAY_A );
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
    private static function examens_recents( $certification_id ) {
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
               AND certification_id = %d
               AND date_fin IS NOT NULL
               AND score IS NOT NULL
               AND mode <> 'revision'
             ORDER BY date_debut DESC
             LIMIT %d",
            $fiche['id'],
            $certification_id,
            self::NB_EXAMENS_COURBE
        ), ARRAY_A );
    }

    /**
     * Chiffres clés autour de la courbe.
     *
     * - dernier   : score du dernier examen (« où j'en suis »).
     * - meilleur  : record personnel (« ce dont je suis capable »).
     *
     * L'écart au seuil de réussite est calculé à l'affichage, à partir de
     * « dernier » : il dépend d'un réglage administrable, pas de l'historique.
     *
     * On n'affiche pas la moyenne ici : elle est sur le tableau de bord, et sur une
     * page de progression une moyenne écrase justement la progression.
     */
    private static function chiffres_cles( $examens ) {
        // $examens est trié du plus récent au plus ancien.
        $dernier = (int) $examens[0]['score'];

        $scores = array_map( function ( $e ) { return (int) $e['score']; }, $examens );
        $meilleur = max( $scores );

        return [
            'dernier'      => $dernier,
            'meilleur'     => $meilleur,
            'date_dernier' => mysql2date( 'd/m/Y', $examens[0]['date_debut'] ),
        ];
    }
}
