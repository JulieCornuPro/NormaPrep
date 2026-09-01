<?php
/**
 * Activité NormaPrep : les indicateurs de progression du candidat.
 *
 * Répond aux deux questions qu'un candidat se pose :
 *   « Suis-je en progrès ? »  -> courbe des scores dans le temps.
 *   « Sur quoi travailler ? » -> points faibles par domaine (à venir).
 *
 * Réutilise le composant Carto.barChart du thème pour les points faibles, afin
 * de rester cohérent visuellement et d'éviter d'ajouter une bibliothèque.
 *
 * Deux exceptions, expliquées là où elles sont écrites : la courbe de
 * progression, qui demande une échelle et des valeurs chiffrées qu'aucun
 * composant du thème ne sait produire (npq-activite.js) ; et le calendrier
 * d'assiduité, dont la gamme de couleurs est l'inverse de celle de
 * Carto.heatmap (voir calendrier_assiduite plus bas).
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

    /**
     * Nombre minimum de questions traitées pour qu'un taux par domaine soit
     * considéré comme fiable.
     *
     * En dessous, un écart d'une seule question fait bouger le taux de dizaines
     * de points : sur 3 questions, on passe de 33 % à 67 % en en réussissant
     * une de plus. Ce n'est pas une mesure, c'est du bruit — et il ne doit ni
     * peser visuellement comme un résultat, ni déclencher un conseil de
     * révision.
     */
    const FIABILITE_MIN = 5;

    /**
     * Nombre de semaines montrées par le calendrier d'assiduité.
     *
     * Six mois : c'est l'ordre de grandeur d'une préparation à la
     * certification. Sur douze semaines la grille ne montrait que la poussée en
     * cours, sans dire si elle succédait à un trimestre vide — et elle laissait
     * les deux tiers du cadre inoccupés.
     */
    const SEMAINES_CALENDRIER = 26;

    /**
     * Nombre de jours travaillés en dessous duquel on n'affiche PAS le
     * calendrier.
     *
     * Une grille presque vide ne renseigne sur rien : elle accuse.
     *
     * Le seuil est bas parce que la grille ne remonte jamais avant la première
     * activité du candidat : un débutant obtient deux ou trois colonnes
     * denses, pas six mois de cases éteintes. C'est ce bornage qui protège du
     * reproche, pas ce nombre — il ne reste ici que pour éviter la grille d'une
     * seule journée, qui ne dessine aucun rythme.
     */
    const JOURS_ACTIFS_MIN = 3;

    /**
     * Paliers d'intensité du calendrier, en questions répondues dans la
     * journée.
     *
     * Fixes, et non calculés sur le maximum du candidat : une échelle relative
     * rendrait deux visites incomparables et peindrait en couleur la plus vive
     * la meilleure journée d'un candidat qui n'en a fait que dix. C'est
     * exactement l'erreur que la courbe de progression a coûté à réparer.
     * Les bornes correspondent à l'usage : une révision courte tourne autour de
     * la dizaine de questions, un examen blanc complet en dépasse la
     * cinquantaine.
     */
    const PALIERS_ASSIDUITE = [ 10, 25, 50 ];

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
            // Dépend de la bibliothèque de composants du thème (Carto.barChart…) :
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

        // Le seuil sert deux fois : la ligne de référence tracée dans la courbe
        // et l'écart affiché plus bas. On le lit une seule fois.
        $seuil_reussite = self::seuil_reussite();

        // Les points de la courbe, du plus ancien au plus récent (sens de
        // lecture). La date accompagne le score : sans elle, l'axe horizontal
        // ne dit pas sur quelle période la progression s'est jouée — deux
        // examens passés le même jour et deux examens espacés d'un mois se
        // lisaient exactement pareil.
        $points = array_map( function ( $e ) {
            return [
                'score' => (int) $e['score'],
                'date'  => mysql2date( 'd/m', $e['date_debut'] ),
            ];
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
                    du plus ancien au plus récent. La ligne ambre marque le seuil
                    de réussite : en teal les examens qui l'atteignent, en orange
                    ceux qui restent en dessous.
                </p>

                <div class="npq-courbe-cadre">
                    <div id="npq-courbe-progression"
                         data-points="<?php echo esc_attr( wp_json_encode( $points ) ); ?>"
                         data-seuil="<?php echo (int) $seuil_reussite; ?>"></div>
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
                    //
                    // $seuil_reussite est lu plus haut : la courbe en trace la
                    // ligne de référence, ce chiffre en donne l'écart.
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
                        Taux de réussite par domaine, calculé sur les questions
                        que vous avez traitées. Les domaines sous
                        <?php echo (int) $seuil; ?> % sont à travailler.
                    </p>

                    <?php
                    // Calculé une fois : sert au graphique et à décider s'il y
                    // a seulement quelque chose à tracer. Un candidat qui n'a
                    // répondu à aucune question n'a aucune barre — on n'affiche
                    // pas un cadre vide.
                    $barres = self::donnees_barres( $domaines, $seuil );
                    ?>
                    <?php if ( ! empty( $barres ) ) : ?>
                        <div class="npq-barres-cadre">
                            <div id="npq-barres-domaines"
                                 data-domaines="<?php echo esc_attr( wp_json_encode( $barres ) ); ?>"></div>
                        </div>
                    <?php endif; ?>

                    <!-- Légende : les codes sous les barres ne parlent pas seuls.
                         On donne ici le libellé complet, le taux, et sur combien de
                         questions il repose (un taux sur 1 question n'est pas fiable). -->
                    <div class="npq-legende-domaines">
                        <?php foreach ( $domaines as $d ) :
                            $mesure = ( $d['taux'] !== null );
                            $fiable = ( $mesure && $d['fiable'] );
                            $faible = ( $fiable && $d['taux'] < $seuil );
                        ?>
                            <div class="npq-legende-ligne<?php echo $faible ? ' faible' : ''; ?><?php echo $mesure ? '' : ' non-aborde'; ?><?php echo ( $mesure && ! $fiable ) ? ' peu-fiable' : ''; ?>">
                                <span class="npq-leg-code"><?php echo esc_html( $d['code'] ); ?></span>
                                <span class="npq-leg-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                                <?php if ( $mesure ) : ?>
                                    <span class="npq-leg-taux"><?php echo (int) $d['taux']; ?> %</span>
                                    <span class="npq-leg-nb">
                                        <?php echo (int) $d['total']; ?> q.<?php
                                        // Les questions non atteintes sont signalées
                                        // à côté du taux, sans peser dessus : elles
                                        // relèvent du temps, pas de la connaissance.
                                        if ( $d['non_atteintes'] > 0 ) :
                                            ?> <span class="npq-leg-blanches">+<?php
                                                echo (int) $d['non_atteintes'];
                                            ?> non traitée(s)</span><?php
                                        endif;
                                        // Trop peu de questions pour conclure : on
                                        // le dit, plutôt que de laisser un taux
                                        // spectaculaire passer pour un constat.
                                        if ( ! $fiable ) :
                                            ?> <span class="npq-leg-blanches">trop peu pour conclure</span><?php
                                        endif; ?>
                                    </span>
                                <?php else : ?>
                                    <span class="npq-leg-taux">&mdash;</span>
                                    <span class="npq-leg-nb">
                                        <span class="npq-leg-blanches">jamais abordé</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // Total des questions laissées blanches, tous domaines
                    // confondus. Ne pas terminer l'épreuve est un vrai problème,
                    // mais il appelle un autre remède que la révision — d'où un
                    // message distinct du conseil de révision qui suit.
                    $non_traitees = 0;
                    foreach ( $domaines as $d ) {
                        $non_traitees += (int) $d['non_atteintes'];
                    }
                    ?>
                    <?php if ( $non_traitees > 0 ) : ?>
                        <div class="npq-conseil npq-conseil-temps">
                            <p>
                                <strong><?php echo (int) $non_traitees; ?> question(s)</strong>
                                sont restées sans réponse sur l'ensemble de vos examens.
                                Elles comptent fausses au score, mais ne pèsent pas sur
                                les taux ci-dessus : c'est votre gestion du temps qu'elles
                                interrogent, pas vos connaissances.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Le domaine le plus faible PARMI CEUX QUI SONT MESURÉS.
                    // Un domaine jamais abordé a un taux null : le désigner
                    // comme « le plus fragile » serait faux, et en PHP la
                    // comparaison null < seuil est vraie — le piège est discret.
                    // On exige aussi un nombre de questions suffisant : envoyer
                    // réviser un domaine sur la foi de deux questions ratées
                    // ferait perdre du temps au candidat sur un faux signal.
                    $plus_faible = null;
                    foreach ( $domaines as $d ) {
                        if ( $d['taux'] !== null && $d['fiable'] ) {
                            $plus_faible = $d;
                            break; // la liste est déjà triée du plus faible au plus fort
                        }
                    }
                    $url_reviser = $plus_faible
                        ? self::url_reviser_domaine( $plus_faible['code'], $certification_id )
                        : '';
                    ?>
                    <?php if ( $plus_faible && $plus_faible['taux'] < $seuil && $url_reviser ) : ?>
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
                <!-- Régularité et couverture -->
                <section class="npq-kpi-bloc reveal-on-scroll">
                    <div class="sec-title">Ma régularité</div>
                    <p class="npq-kpi-aide">
                        Travailler souvent compte autant que travailler beaucoup.
                        Examens et révisions confondus : ici on mesure l'effort,
                        pas la performance.
                    </p>

                    <?php
                    // Fenêtre du calendrier : on remonte jusqu'au lundi d'il y a
                    // douze semaines, pour que la grille commence toujours en
                    // haut d'une colonne pleine.
                    //
                    // current_time() et non date() : les dates de tentative sont
                    // écrites avec current_time('mysql'), donc dans le fuseau du
                    // site. Comparer à l'heure serveur décalerait les journées
                    // d'un cran pour toute activité de fin de soirée.
                    $aujourdhui   = current_time( 'Y-m-d' );
                    $lundi_actuel = gmdate( 'Y-m-d', strtotime( 'monday this week', strtotime( $aujourdhui ) ) );
                    $lundi_fenetre = gmdate(
                        'Y-m-d',
                        strtotime( $lundi_actuel . ' -' . ( self::SEMAINES_CALENDRIER - 1 ) . ' weeks' )
                    );

                    $assiduite = self::assiduite( $certification_id, $lundi_fenetre );

                    // La grille ne commence jamais avant la première activité du
                    // candidat : sinon un inscrit de la semaine verrait cinq
                    // mois de cases éteintes antérieures à son arrivée.
                    $lundi_depart = $lundi_fenetre;
                    if ( ! empty( $assiduite['premiere'] ) ) {
                        $lundi_premiere = gmdate(
                            'Y-m-d',
                            strtotime( 'monday this week', strtotime( gmdate( 'Y-m-d', strtotime( $assiduite['premiere'] ) ) ) )
                        );
                        if ( $lundi_premiere > $lundi_depart ) {
                            $lundi_depart = $lundi_premiere;
                        }
                    }

                    // Nombre de colonnes réellement tracées, entre ce lundi de
                    // départ et la semaine en cours (incluse).
                    $nb_semaines = 1 + (int) round(
                        ( strtotime( $lundi_actuel ) - strtotime( $lundi_depart ) ) / ( 7 * 86400 )
                    );

                    $jours_actifs = count( $assiduite['jours'] );
                    ?>

                    <?php if ( $jours_actifs >= self::JOURS_ACTIFS_MIN ) : ?>
                        <div class="npq-calendrier-cadre">
                            <p class="npq-cal-titre">
                                <?php echo (int) $jours_actifs; ?> jours travaillés
                                <span class="npq-cal-sous">depuis
                                le <?php echo esc_html( mysql2date( 'j F Y', $lundi_depart ) ); ?></span>
                            </p>
                            <?php echo self::calendrier_assiduite( $assiduite['jours'], $lundi_depart, $aujourdhui, $nb_semaines ); ?>
                        </div>
                    <?php elseif ( $assiduite['derniere'] ) : ?>
                        <?php
                        // Trop peu de jours travaillés pour qu'une grille de
                        // douze semaines dise quelque chose. La date, elle, est
                        // vraie et suffit.
                        ?>
                        <p class="npq-cal-repli">
                            Dernière activité le
                            <strong><?php echo esc_html( mysql2date( 'j F Y', $assiduite['derniere'] ) ); ?></strong>.
                            Votre calendrier d'assiduité apparaîtra ici après
                            <?php echo (int) self::JOURS_ACTIFS_MIN; ?> jours de travail.
                        </p>
                    <?php endif; ?>

                    <?php
                    // Les cumuls ne sont plus des compteurs animés mais une
                    // ligne de contexte. Un cumul ne peut que monter : il ne
                    // dit rien à la deuxième visite, et affiché en grand il
                    // félicitait un candidat quel que soit son niveau. Il garde
                    // sa place — savoir sur quel volume repose le reste est
                    // utile — mais pas la vedette.
                    ?>
                    <p class="npq-volume-ligne">
                        <span><strong><?php echo (int) $volume['questions']; ?></strong> questions traitées</span>
                        <span><strong><?php echo (int) $volume['sessions_examens']; ?></strong> examen(s) blanc(s)</span>
                        <span><strong><?php echo (int) $volume['sessions_revisions']; ?></strong> session(s) de révision</span>
                    </p>

                    <?php
                    $couverture = self::couverture_domaines( $certification_id );
                    $nb_couverts = 0;
                    foreach ( $couverture as $d ) {
                        if ( $d['couvert'] ) { $nb_couverts++; }
                    }
                    ?>
                    <?php if ( ! empty( $couverture ) ) : ?>
                        <div class="npq-couverture-cadre">
                            <p class="npq-couv-titre">
                                Couverture du programme
                                <?php
                                // Teal seulement si le programme est entier :
                                // le teal annonce ce qui est acquis, et « 5/7 »
                                // dit précisément le contraire. Tant qu'il
                                // manque un domaine, l'ambre — ce qui est visé,
                                // pas encore atteint.
                                ?>
                                <span class="npq-couv-compte<?php echo ( $nb_couverts < count( $couverture ) ) ? ' incomplet' : ''; ?>">
                                    <?php echo (int) $nb_couverts; ?>/<?php echo count( $couverture ); ?>
                                </span>
                            </p>

                            <?php
                            // La liste ENTIÈRE, chaque domaine marqué ouvert ou
                            // non. « 5 sur 7 » dit qu'il manque quelque chose
                            // sans dire quoi : le candidat ne peut rien en
                            // faire. Nommer les domaines non ouverts, si.
                            ?>
                            <div class="npq-couv-liste">
                                <?php foreach ( $couverture as $d ) : ?>
                                    <span class="npq-couv-domaine<?php echo $d['couvert'] ? '' : ' vierge'; ?>"
                                          title="<?php echo esc_attr(
                                              $d['couvert']
                                                  ? $d['libelle'] . ' — déjà travaillé'
                                                  : $d['libelle'] . ' — jamais ouvert'
                                          ); ?>">
                                        <span class="npq-couv-code"><?php echo esc_html( $d['code'] ); ?></span>
                                        <span class="npq-couv-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <p class="npq-couv-pied">
                                <?php if ( $nb_couverts === count( $couverture ) ) : ?>
                                    Vous avez travaillé chacun des domaines du référentiel.
                                <?php else : ?>
                                    Les domaines en pointillés n'ont jamais été ouverts,
                                    ni en examen ni en révision.
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php
                        // Un domaine jamais ouvert est le trou le plus grave de
                        // la page, et c'était le seul sans bouton.
                        //
                        // Le conseil des points faibles ne peut pas le couvrir :
                        // il désigne le domaine au taux le plus bas, or un
                        // domaine jamais abordé n'a pas de taux du tout. Il ne
                        // pouvait donc jamais être « le plus fragile », et
                        // n'apparaissait qu'en gris dans une liste. On peut
                        // réviser un domaine faible ; on ne peut pas réviser
                        // celui dont on ignore l'avoir manqué.
                        $vierges = [];
                        foreach ( $couverture as $d ) {
                            if ( ! $d['couvert'] ) {
                                $vierges[] = $d;
                            }
                        }

                        // Le premier dans l'ordre du référentiel, et non un
                        // « meilleur choix » : aucune donnée ne permet de
                        // classer des domaines dont on ne sait rien. Le bouton
                        // le nomme, plutôt que de laisser croire à une
                        // recommandation.
                        $premier_vierge = $vierges ? $vierges[0] : null;
                        $url_decouvrir  = $premier_vierge
                            ? self::url_reviser_domaine( $premier_vierge['code'], $certification_id )
                            : '';
                        ?>
                        <?php if ( $premier_vierge && $url_decouvrir ) : ?>
                            <div class="npq-conseil npq-conseil-decouverte">
                                <p>
                                    <?php if ( count( $vierges ) === 1 ) : ?>
                                        Un domaine du référentiel n'a jamais été ouvert :
                                        <strong><?php echo esc_html( $premier_vierge['libelle'] ); ?></strong>.
                                    <?php else : ?>
                                        <strong><?php echo count( $vierges ); ?> domaines</strong>
                                        du référentiel n'ont jamais été ouverts :
                                        <?php
                                        $noms = array_map( 'esc_html', wp_list_pluck( $vierges, 'libelle' ) );
                                        echo implode( ', ', $noms );
                                        ?>.
                                    <?php endif; ?>
                                    Les questions de l'examen s'y répartissent pourtant comme
                                    ailleurs.
                                </p>
                                <a href="<?php echo esc_url( $url_decouvrir ); ?>" class="npq-btn">
                                    <?php if ( count( $vierges ) === 1 ) : ?>
                                        Découvrir ce domaine
                                    <?php else : ?>
                                        Commencer par <?php echo esc_html( $premier_vierge['code'] ); ?>
                                    <?php endif; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
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
            // Domaine jamais abordé : aucune barre. Une barre à zéro se lirait
            // comme un échec total, alors qu'il n'y a simplement rien à
            // mesurer. La légende le mentionne, c'est suffisant.
            if ( $d['taux'] === null ) {
                continue;
            }

            $barres[] = [
                // Libellé court : le code du domaine (le nom complet est trop long
                // sous une barre). Le détail est donné dans la légende en dessous.
                'label'   => $d['code'],
                'value'   => (int) $d['taux'],
                // Un taux peu fiable n'est pas signalé comme faible : on ne
                // met pas en rouge un résultat dont on ne répond pas.
                'faible'  => ( $d['fiable'] && $d['taux'] < $seuil ),
                'fiable'  => (bool) $d['fiable'],
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

        // Le compte des domaines couverts était calculé ici. Il est désormais
        // rendu par couverture_domaines(), qui donne aussi leurs NOMS : « 5 sur
        // 7 » dit qu'il manque quelque chose sans dire quoi, ce qui laisse le
        // candidat sans prise.

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
            'questions'          => $questions,
            'sessions_examens'   => (int) ( $sessions['examens'] ?? 0 ),
            'sessions_revisions' => (int) ( $sessions['revisions'] ?? 0 ),
        ];
    }

    /**
     * Lien vers la page Révisions, pré-réglée sur un domaine.
     *
     * Le lien porte le domaine ET la certification. Sans ces paramètres, le
     * bouton promettait de réviser CE domaine puis déposait le candidat sur la
     * page nue, à lui de retrouver lequel. L'ancre l'amène directement au
     * formulaire, situé sous les parcours proposés.
     *
     * Renvoie une chaîne vide si la page Révisions n'est pas configurée : un
     * bouton qui ne mène nulle part est pire que pas de bouton.
     */
    private static function url_reviser_domaine( $code, $certification_id ) {
        $page_revision = get_option( 'npq_page_revision_id' );
        if ( ! $page_revision ) {
            return '';
        }

        return add_query_arg(
            [
                'npq_domaine' => $code,
                'npq_certif'  => $certification_id,
            ],
            get_permalink( $page_revision )
        ) . '#npq-composer';
    }

    /**
     * Couverture du programme : quels domaines le candidat a déjà ouverts, et
     * lesquels il n'a jamais touchés.
     *
     * INCLUT les révisions, contrairement au taux par domaine : la question
     * n'est pas « suis-je bon ? » mais « ai-je seulement ouvert ce chapitre ? ».
     * Un domaine travaillé en révision est un domaine ouvert.
     *
     * Même règle qu'ailleurs pour ce qui compte comme travaillé : au moins une
     * option cochée. Un domaine seulement effleuré par des questions laissées
     * blanches n'est pas un domaine couvert.
     *
     * Renvoie la liste complète des domaines du référentiel, chacun marqué
     * couvert ou non — et non les seuls manquants : c'est la liste entière qui
     * permet au candidat de situer le trou dans le programme.
     */
    private static function couverture_domaines( $certification_id ) {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Le référentiel est filtré sur la certification : sans cela, la liste
        // cumulait les domaines de TOUS les référentiels et annonçait « 5 sur
        // 34 ». Le piège a déjà été rencontré sur le compteur qu'on remplace.
        $domaines = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine
             WHERE certification_id = %d
             ORDER BY code",
            $certification_id
        ), ARRAY_A );

        if ( empty( $domaines ) ) {
            return [];
        }

        $codes_couverts = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT q.domaine
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

        foreach ( $domaines as &$d ) {
            $d['couvert'] = in_array( $d['code'], $codes_couverts, true );
        }
        unset( $d );

        return $domaines;
    }

    /**
     * Assiduité : combien de questions le candidat a réellement traitées chaque
     * jour, sur la fenêtre du calendrier.
     *
     * INCLUT les révisions, comme le volume de travail : on mesure l'effort
     * fourni, pas la performance. Et on applique la même règle qu'ailleurs —
     * une question « traitée » est une question dont au moins une option a été
     * cochée. Compter les copies blanches gonflerait l'assiduité de journées où
     * le candidat n'a rien fait d'autre que lancer un examen.
     *
     * Renvoie [ 'jours' => [ 'AAAA-MM-JJ' => nb ], 'premiere' => …, 'derniere' => … ].
     */
    private static function assiduite( $certification_id, $debut ) {
        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return [ 'jours' => [], 'premiere' => null, 'derniere' => null ];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(t.date_debut) AS jour, COUNT(*) AS questions
             FROM {$p}reponse r
             INNER JOIN {$p}tentative t ON t.id = r.tentative_id
             WHERE t.utilisateur_id = %d
               AND t.certification_id = %d
               AND t.date_fin IS NOT NULL
               AND t.date_debut >= %s
               AND EXISTS ( SELECT 1 FROM {$p}reponse_option ro
                            WHERE ro.reponse_id = r.id )
             GROUP BY DATE(t.date_debut)",
            $fiche['id'],
            $certification_id,
            $debut . ' 00:00:00'
        ), ARRAY_A );

        $jours = [];
        foreach ( $lignes as $l ) {
            $jours[ $l['jour'] ] = (int) $l['questions'];
        }

        // La dernière activité est cherchée SANS borne de date : un candidat
        // qui n'a rien fait depuis quatre mois doit lire « depuis le 12/04 »,
        // pas « aucune activité » — c'est précisément l'information utile.
        //
        // La PREMIÈRE activité sert, elle, à ne pas remonter avant l'arrivée du
        // candidat : une grille de six mois affichée à quelqu'un d'inscrit
        // depuis deux semaines lui montrerait cinq mois de cases éteintes qui
        // ne sont pas les siennes. Ce serait un reproche fabriqué.
        $bornes = $wpdb->get_row( $wpdb->prepare(
            "SELECT MIN(date_debut) AS premiere, MAX(date_debut) AS derniere
             FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND certification_id = %d
               AND date_fin IS NOT NULL",
            $fiche['id'],
            $certification_id
        ), ARRAY_A );

        return [
            'jours'    => $jours,
            'premiere' => $bornes['premiere'] ?? null,
            'derniere' => $bornes['derniere'] ?? null,
        ];
    }

    /**
     * Le calendrier d'assiduité : une case par jour, douze semaines en colonnes.
     *
     * Rendu en PHP et non en JavaScript, contrairement à la courbe : c'est une
     * grille de cases, donc du CSS suffit. Le composant Carto.heatmap du thème
     * ne convenait pas — sa gamme va du teal à l'orange avec le chaud pour le
     * mauvais, alors qu'ici une journée chargée est une bonne nouvelle. Il
     * aurait inversé le sens des couleurs.
     *
     * La semaine commence le lundi : les libellés de lignes sont écrits en dur
     * dans cet ordre.
     */
    private static function calendrier_assiduite( $jours, $lundi_depart, $aujourdhui, $nb_semaines ) {
        $jours_semaine = [ 'Lun', '', 'Mer', '', 'Ven', '', 'Dim' ];
        $mois_courts   = [ 1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
                           'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' ];

        ob_start();
        ?>
        <div class="npq-calendrier" style="--npq-cal-semaines:<?php echo (int) $nb_semaines; ?>">
            <!-- Seule la grille défile sur un écran étroit. La légende reste en
                 dehors, et la colonne des jours reste collée à gauche : sans
                 cela, le défilement emportait les deux repères qui permettent
                 de lire la grille. -->
            <div class="npq-cal-defile">
            <div class="npq-cal-mois">
                <?php
                $mois_precedent = '';
                for ( $sem = 0; $sem < $nb_semaines; $sem++ ) {
                    $lundi = gmdate( 'Y-m-d', strtotime( $lundi_depart . ' +' . ( $sem * 7 ) . ' days' ) );
                    $mois  = gmdate( 'n', strtotime( $lundi ) );
                    // Le mois n'est écrit qu'au-dessus de la colonne où il
                    // change : répété douze fois, il ferait un bandeau illisible.
                    $libelle = ( $mois !== $mois_precedent ) ? $mois_courts[ (int) $mois ] : '';
                    $mois_precedent = $mois;
                    ?>
                    <span><?php echo esc_html( $libelle ); ?></span>
                    <?php
                }
                ?>
            </div>

            <div class="npq-cal-corps">
                <div class="npq-cal-jours">
                    <?php foreach ( $jours_semaine as $j ) : ?>
                        <span><?php echo esc_html( $j ); ?></span>
                    <?php endforeach; ?>
                </div>

                <div class="npq-cal-grille">
                    <?php
                    for ( $sem = 0; $sem < $nb_semaines; $sem++ ) {
                        for ( $jour = 0; $jour < 7; $jour++ ) {
                            $date = gmdate(
                                'Y-m-d',
                                strtotime( $lundi_depart . ' +' . ( $sem * 7 + $jour ) . ' days' )
                            );

                            // Les jours à venir ne sont pas des jours sans
                            // travail : ils n'ont pas encore eu lieu. On garde
                            // la place pour ne pas déformer la grille, sans
                            // dessiner de case.
                            if ( $date > $aujourdhui ) {
                                echo '<span class="npq-cal-case npq-cal-futur"></span>';
                                continue;
                            }

                            $nb      = isset( $jours[ $date ] ) ? (int) $jours[ $date ] : 0;
                            $niveau  = self::niveau_assiduite( $nb );
                            $lisible = mysql2date( 'j F Y', $date );
                            $titre   = ( $nb > 0 )
                                ? sprintf( '%s : %d question(s) traitée(s)', $lisible, $nb )
                                : sprintf( '%s : aucune activité', $lisible );
                            ?>
                            <span class="npq-cal-case npq-cal-n<?php echo (int) $niveau; ?>"
                                  title="<?php echo esc_attr( $titre ); ?>"></span>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
            </div>

            <div class="npq-cal-legende">
                <span>Moins</span>
                <?php for ( $n = 0; $n <= 4; $n++ ) : ?>
                    <i class="npq-cal-case npq-cal-n<?php echo $n; ?>"></i>
                <?php endfor; ?>
                <span>Plus</span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Palier d'intensité d'une journée, de 0 (rien) à 4 (grosse journée). */
    private static function niveau_assiduite( $nb ) {
        if ( $nb <= 0 ) {
            return 0;
        }
        $niveau = 1;
        foreach ( self::PALIERS_ASSIDUITE as $palier ) {
            if ( $nb >= $palier ) {
                $niveau++;
            }
        }
        return $niveau;
    }

    /**
     * Taux de réussite par domaine, cumulé sur tous les EXAMENS du candidat.
     * (Les révisions sont exclues : on mesure la performance en épreuve.)
     *
     * Ce bloc répond à « sur quoi travailler ? ». Il doit donc mesurer une
     * CONNAISSANCE, et le taux est calculé sur les seules questions répondues.
     *
     * Une question laissée blanche compte fausse au barème — c'est la règle de
     * l'épreuve, et le score global s'en charge. Mais la faire peser ici
     * confondrait deux causes opposées : « je ne savais pas » et « je n'ai pas
     * eu le temps d'y arriver ». Un candidat à court de temps voyait ses
     * derniers domaines en rouge vif sans les avoir seulement lus, et partait
     * réviser ce qu'il maîtrisait déjà. Un indicateur qui oriente dans la
     * mauvaise direction est pire qu'un indicateur absent.
     *
     * Les questions non atteintes ne disparaissent pas pour autant : elles sont
     * comptées à part, et remontées telles quelles au candidat — ne pas
     * terminer l'épreuve est un problème réel, mais c'est un problème de
     * gestion du temps, qui appelle un autre remède que la révision.
     *
     * Renvoie, par domaine : code, libellé, taux (sur les répondues), nombre de
     * répondues, nombre de non atteintes. Le nombre de répondues compte : un
     * taux appuyé sur une seule question n'est pas fiable, et le candidat doit
     * pouvoir le voir.
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
        // « repondue » distingue une question traitée d'une question laissée
        // blanche : la correction enregistre une ligne dans les deux cas, mais
        // seule une question traitée porte des options cochées.
        $lignes = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.domaine AS code,
                    COUNT(*) AS total,
                    SUM( CASE WHEN EXISTS (
                            SELECT 1 FROM {$p}reponse_option ro
                            WHERE ro.reponse_id = r.id
                         ) THEN 1 ELSE 0 END ) AS repondues,
                    SUM( CASE WHEN r.correcte = 1 THEN 1 ELSE 0 END ) AS reussies
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
            $total     = (int) $l['total'];
            $repondues = (int) $l['repondues'];
            $reussies  = (int) $l['reussies'];

            // Taux sur les seules questions traitées. Un domaine entièrement
            // laissé blanc n'a pas de taux : afficher 0 % laisserait croire à
            // une lacune alors qu'il n'a jamais été abordé.
            $taux = $repondues > 0 ? (int) round( $reussies * 100 / $repondues ) : null;

            $resultat[] = [
                'code'          => $l['code'],
                'libelle'       => isset( $libelles[ $l['code'] ] ) ? $libelles[ $l['code'] ] : $l['code'],
                'taux'          => $taux,
                'total'         => $repondues,          // ce sur quoi porte le taux
                'non_atteintes' => $total - $repondues,
                'fiable'        => ( $repondues >= self::FIABILITE_MIN ),
            ];
        }

        // Du plus faible au plus fort : le candidat voit d'abord où ça coince.
        // Les domaines jamais abordés (taux null) sont renvoyés en fin de liste :
        // ils ne sont pas des points faibles, ils sont hors mesure.
        usort( $resultat, function ( $a, $b ) {
            if ( $a['taux'] === null || $b['taux'] === null ) {
                return ( $a['taux'] === null ? 1 : 0 ) <=> ( $b['taux'] === null ? 1 : 0 );
            }
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
