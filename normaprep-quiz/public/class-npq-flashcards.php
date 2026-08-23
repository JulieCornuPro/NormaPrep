<?php
/**
 * Flashcards NormaPrep — côté abonné.
 *
 * Un outil de mémorisation : recto (la question), verso (la réponse). Le candidat
 * choisit ses domaines, un nombre de cartes, et déroule le paquet.
 *
 * Tout se passe côté navigateur : les cartes du paquet sont chargées d'un coup,
 * et le retournement est instantané. Contrairement à un examen, il n'y a rien à
 * protéger — la réponse EST le contenu, le candidat vient pour la voir.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Flashcards {

    const OPT_PAGE_FLASHCARDS = 'npq_page_flashcards_id';

    public static function init() {
        add_shortcode( 'npq_flashcards', [ __CLASS__, 'rendu' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_script' ] );
    }

    /**
     * Crée la page « Flashcards » à l'activation.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_FLASHCARDS );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Flashcards',
            'post_name'    => 'flashcards',
            'post_content' => '[npq_flashcards]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_FLASHCARDS, $page_id );
        }
    }

    public static function charger_script() {
        $page_id = get_option( self::OPT_PAGE_FLASHCARDS );
        if ( ! $page_id || ! is_page( $page_id ) ) {
            return;
        }
        wp_enqueue_script(
            'npq-flashcards',
            NPQ_URL . 'assets/npq-flashcards.js',
            [],
            NPQ_VERSION,
            true
        );
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function rendu() {
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            $url = NPQ_Comptes::url_offres();
            return '<p class="empty">Les flashcards sont réservées aux abonnés. '
                 . '<a href="' . esc_url( $url ) . '">Découvrir les offres</a>.</p>';
        }

        // Certifications de la bibliothèque de l'utilisateur.
        $certifs = self::certifications_utilisateur();
        $plusieurs = ( count( $certifs ) > 1 );

        // Domaines et cartes de TOUTES les certifications de la bibliothèque.
        // Le JavaScript filtrera selon la certification choisie dans le
        // sélecteur (et selon les domaines cochés).
        $domaines_par_certif = [];
        $cartes_tous = [];
        $total = 0;
        foreach ( $certifs as $certif ) {
            $cid = (int) $certif['id'];
            $domaines_par_certif[ $cid ] = self::domaines_disponibles( $cid );
            foreach ( $domaines_par_certif[ $cid ] as $d ) {
                $total += (int) $d['nb'];
            }
            $cartes_tous = array_merge( $cartes_tous, self::toutes_les_cartes( $cid ) );
        }

        // Aucune carte dans toute la bibliothèque : on le dit.
        if ( $total === 0 ) {
            return '<div class="npq-flashcards"><h2>Flashcards</h2>'
                 . '<p class="empty">Aucune flashcard disponible pour le moment.</p></div>';
        }

        ob_start();
        ?>
        <div class="npq-flashcards" id="npq-flashcards">

            <!-- Écran de composition -->
            <div id="npq-fc-composer">
                <h2>Flashcards</h2>
                <p class="npq-fc-intro">
                    Mémorisez les points clés de la norme : articles, définitions, mesures.
                    Regardez la question, réfléchissez, puis retournez la carte.
                </p>

                <form class="npq-fc-form" id="npq-fc-form">
                    <?php if ( $plusieurs ) : ?>
                        <p class="npq-champ-label">Certification</p>
                        <select id="npq-fc-certif" class="npq-compo-select">
                            <?php foreach ( $certifs as $certif ) : ?>
                                <option value="<?php echo (int) $certif['id']; ?>">
                                    <?php echo esc_html( $certif['nom'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="hidden" id="npq-fc-certif" value="<?php echo (int) $certifs[0]['id']; ?>">
                    <?php endif; ?>

                    <p class="npq-champ-label">Domaines à réviser</p>
                    <p class="npq-champ-aide">
                        Laissez tout décoché pour piocher dans l'ensemble du programme.
                    </p>

                    <div class="npq-domaines-liste">
                        <?php foreach ( $certifs as $certif ) :
                            $cid = (int) $certif['id'];
                            foreach ( $domaines_par_certif[ $cid ] as $d ) :
                                if ( (int) $d['nb'] === 0 ) {
                                    continue; // domaine sans carte : inutile de le proposer
                                }
                        ?>
                            <label class="npq-domaine-case" data-certification="<?php echo $cid; ?>">
                                <input type="checkbox" name="npq_domaines[]"
                                       value="<?php echo esc_attr( $d['code'] ); ?>">
                                <span class="npq-dom-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                                <span class="npq-dom-nb"><?php echo (int) $d['nb']; ?></span>
                            </label>
                        <?php
                            endforeach;
                        endforeach;
                        ?>
                    </div>

                    <p class="npq-champ-label">Nombre de cartes</p>
                    <div class="npq-nombre-choix">
                        <?php foreach ( [ 5, 10, 20, 30 ] as $n ) : ?>
                            <label class="npq-nombre-case">
                                <input type="radio" name="npq_nombre" value="<?php echo $n; ?>"
                                    <?php checked( $n, 10 ); ?>>
                                <?php echo $n; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <p>
                        <button type="submit" class="npq-btn">Commencer</button>
                    </p>
                </form>
            </div>

            <!-- Écran de session (rempli par le JavaScript) -->
            <div id="npq-fc-session" style="display:none"></div>

            <!-- Toutes les cartes de la bibliothèque, pour le tirage côté
                 navigateur. Chaque carte porte sa certification (champ certif),
                 pour que le JavaScript ne tire que dans la certification choisie.
                 Rien à protéger ici : la réponse EST le contenu, le candidat
                 vient précisément pour la voir. -->
            <script type="application/json" id="npq-fc-donnees">
                <?php echo wp_json_encode( $cartes_tous ); ?>
            </script>
        </div>

        <?php if ( $plusieurs ) : ?>
        <script>
        /* Le sélecteur de certification n'affiche que les domaines de la
           certification choisie (et décoche les autres). Le filtrage des cartes
           par certification, lui, est fait par le script principal des
           flashcards, qui lit la valeur de #npq-fc-certif. */
        ( function () {
            var select = document.getElementById( 'npq-fc-certif' );
            var cases  = document.querySelectorAll( '.npq-domaine-case' );
            if ( ! select || ! select.tagName || select.tagName.toLowerCase() !== 'select' ) { return; }

            function filtrer() {
                var certif = parseInt( select.value, 10 ) || 0;
                cases.forEach( function ( c ) {
                    var ok = ( parseInt( c.getAttribute( 'data-certification' ), 10 ) === certif );
                    c.style.display = ok ? '' : 'none';
                    if ( ! ok ) {
                        var input = c.querySelector( 'input[type="checkbox"]' );
                        if ( input ) { input.checked = false; }
                    }
                } );
            }

            select.addEventListener( 'change', filtrer );
            filtrer();
        } )();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * DONNÉES
     * ===================================================================== */

    /** Toutes les cartes publiées, avec leur domaine (code et libellé). */
    private static function toutes_les_cartes( $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = self::certification_courante();
        }
        if ( ! $certification_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // On joint le domaine pour avoir son libellé : « D1 » seul ne dit rien
        // au candidat, il faut « D1 — Fondamentaux et principes… ».
        //
        // La jointure porte sur le COUPLE code + certification : un même code de
        // domaine (« D1 ») peut exister dans plusieurs certifications. Joindre
        // sur le seul code ferait correspondre chaque carte à autant de lignes
        // qu'il y a de certifications possédant ce code — et la carte
        // apparaîtrait en double, voire en triple, dans le paquet.
        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT f.id, f.domaine, f.recto, f.verso,
                    d.libelle AS domaine_libelle
             FROM {$p}flashcard f
             LEFT JOIN {$p}domaine d
                    ON d.code = f.domaine
                   AND d.certification_id = f.certification_id
             WHERE f.certification_id = %d
               AND f.statut = 'publie'",
            $certification_id
        ), ARRAY_A );

        $cartes = [];
        foreach ( $lignes as $l ) {
            $cartes[] = [
                'id'      => (int) $l['id'],
                'certif'  => (int) $certification_id,
                'domaine' => $l['domaine'],
                'libelle' => (string) ( $l['domaine_libelle'] ?? '' ),
                'recto'   => $l['recto'],
                'verso'   => $l['verso'],
            ];
        }
        return $cartes;
    }

    /** Domaines, avec le nombre de cartes de chacun. */
    private static function domaines_disponibles( $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = self::certification_courante();
        }
        if ( ! $certification_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT d.code, d.libelle, COUNT(f.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}flashcard f
                    ON f.domaine = d.code
                   AND f.statut = 'publie'
                   AND f.certification_id = %d
             WHERE d.certification_id = %d
             GROUP BY d.code, d.libelle
             ORDER BY d.code ASC",
            $certification_id,
            $certification_id
        ), ARRAY_A );
    }

    /** Certification active — délègue à la résolution centralisée. */
    private static function certification_courante() {
        return NPQ_Certification::id();
    }

    /**
     * Certifications de la bibliothèque de l'utilisateur courant. Repli sur la
     * certification active si aucun accès n'est encore enregistré (cohérent
     * avec la page révisions).
     *
     * @return array Lignes : id, code, nom.
     */
    private static function certifications_utilisateur() {
        return NPQ_Bibliotheque::certifications_utilisateur();
    }
}
