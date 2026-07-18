<?php
/**
 * Révisions NormaPrep.
 *
 * La révision est un outil d'entraînement, distinct de l'examen :
 *   - Pas de chronomètre (on prend son temps).
 *   - Questions composées selon des critères choisis (domaines, nombre).
 *   - Explications visibles immédiatement après chaque question (retour instantané).
 *
 * Deux façons de composer :
 *   - Le candidat choisit ses domaines et le nombre de questions.
 *   - Ou il prend un parcours préprogrammé (proposé par NormaPrep).
 *
 * Le déroulé réutilise la mécanique de l'examen (navigation, brouillon, correction),
 * en mode « revision » — pas de duplication de code.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Revision {

    const OPT_PAGE_REVISION = 'npq_page_revision_id';

    /**
     * Parcours de révision proposés, désormais lus depuis la base
     * (administrables via NormaPrep → Parcours de révision).
     *
     * Renvoie un tableau indexé par id de parcours :
     *   [ 12 => [ 'titre' => ..., 'resume' => ..., 'domaines' => [...], 'nombre' => 10 ], ... ]
     */
    public static function parcours_proposes() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $certification_id = self::certification_courante();

        $lignes = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, titre, resume, type, domaines, nombre
             FROM {$p}parcours
             WHERE statut = 'publie'
               AND ( certification_id = %d OR certification_id IS NULL )
             ORDER BY position ASC, id ASC",
            $certification_id
        ), ARRAY_A );

        $parcours = [];
        foreach ( $lignes as $ligne ) {
            $domaines = json_decode( (string) $ligne['domaines'], true );
            if ( ! is_array( $domaines ) ) {
                $domaines = [];
            }
            $parcours[ (int) $ligne['id'] ] = [
                'titre'    => $ligne['titre'],
                'resume'   => $ligne['resume'],
                'type'     => $ligne['type'],
                'domaines' => $domaines,
                'nombre'   => (int) $ligne['nombre'],
            ];
        }
        return $parcours;
    }

    public static function init() {
        add_shortcode( 'npq_revision', [ __CLASS__, 'rendu' ] );
        add_action( 'template_redirect', [ __CLASS__, 'traiter' ] );
    }

    /**
     * Crée la page « Révisions » à l'activation.
     */
    public static function creer_page() {
        $page_id = get_option( self::OPT_PAGE_REVISION );
        if ( $page_id && get_post( $page_id ) ) {
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => 'Révisions',
            'post_name'    => 'revisions',
            'post_content' => '[npq_revision]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( self::OPT_PAGE_REVISION, $page_id );
        }
    }

    /* =====================================================================
     * TRAITEMENT : lancer une révision
     * ===================================================================== */

    public static function traiter() {
        if ( empty( $_POST['npq_revision_action'] ) ) {
            return;
        }
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            return;
        }
        if ( ! isset( $_POST['npq_nonce'] ) || ! wp_verify_nonce( $_POST['npq_nonce'], 'npq_revision' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['npq_revision_action'] );

        if ( $action === 'composer' ) {
            $domaines = isset( $_POST['npq_domaines'] )
                ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npq_domaines'] ) )
                : [];
            $nombre = isset( $_POST['npq_nombre'] ) ? (int) $_POST['npq_nombre'] : 10;
            self::lancer( $domaines, $nombre );

        } elseif ( $action === 'parcours' ) {
            $cle = (int) ( $_POST['npq_parcours'] ?? 0 );
            $parcours = self::parcours_proposes();
            if ( isset( $parcours[ $cle ] ) ) {
                if ( ( $parcours[ $cle ]['type'] ?? 'criteres' ) === 'questions' ) {
                    self::lancer_questions( $cle );
                } else {
                    self::lancer( $parcours[ $cle ]['domaines'], $parcours[ $cle ]['nombre'] );
                }
            }
        }
    }

    /**
     * Crée une tentative en mode « revision » et lance le déroulé.
     */
    private static function lancer( $domaines, $nombre ) {
        $certification_id = self::certification_courante();
        if ( ! $certification_id ) {
            return;
        }

        // Bornes raisonnables sur le nombre de questions.
        $nombre = max( 5, min( 40, (int) $nombre ) );

        $questions = NPQ_Composeur::par_domaines( $certification_id, $domaines, $nombre );
        if ( empty( $questions ) ) {
            return;
        }

        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = array_map( function ( $q ) { return (int) $q['id']; }, $questions );

        $wpdb->insert( "{$p}tentative", [
            'utilisateur_id'   => $fiche['id'],
            'examen_modele_id' => null,
            'mode'             => 'revision',
            'criteres'         => wp_json_encode( [
                'type'      => 'revision',
                'domaines'  => array_values( $domaines ),
                'questions' => $ids,
            ] ),
            'date_debut'       => current_time( 'mysql' ),
        ] );
        $tentative_id = $wpdb->insert_id;

        // Le déroulé se fait SUR LA PAGE RÉVISIONS : le candidat reste dans son
        // contexte (barre latérale « Révisions », URL /revisions/).
        $page_revision = get_option( self::OPT_PAGE_REVISION );
        $url = $page_revision ? get_permalink( $page_revision ) : home_url( '/' );
        wp_safe_redirect( add_query_arg( [ 't' => $tentative_id, 'q' => 0 ], $url ) );
        exit;
    }

    /**
     * Lance une révision à partir des questions figées d'un parcours
     * (mode « questions choisies »). Jumelle de lancer(), mais la composition
     * vient de la table de liaison plutôt que d'un tirage par critères.
     */
    private static function lancer_questions( $parcours_id ) {
        $questions = NPQ_Composeur::par_parcours( $parcours_id );
        if ( empty( $questions ) ) {
            return;
        }

        $fiche = NPQ_Comptes::fiche_courante();
        if ( ! $fiche ) {
            return;
        }

        global $wpdb;
        $pfx = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ids = array_map( function ( $q ) { return (int) $q['id']; }, $questions );

        $wpdb->insert( "{$pfx}tentative", [
            'utilisateur_id'   => $fiche['id'],
            'examen_modele_id' => null,
            'mode'             => 'revision',
            'criteres'         => wp_json_encode( [
                'type'         => 'revision',
                'parcours_id'  => (int) $parcours_id,
                'questions'    => $ids,
            ] ),
            'date_debut'       => current_time( 'mysql' ),
        ] );
        $tentative_id = $wpdb->insert_id;

        $page_revision = get_option( self::OPT_PAGE_REVISION );
        $url = $page_revision ? get_permalink( $page_revision ) : home_url( '/' );
        wp_safe_redirect( add_query_arg( [ 't' => $tentative_id, 'q' => 0 ], $url ) );
        exit;
    }

    /* =====================================================================
     * AFFICHAGE
     * ===================================================================== */

    public static function rendu() {
        if ( ! NPQ_Comptes::peut_passer_examen_complet() ) {
            $page = get_page_by_path( 'offres' );
            $url  = $page ? get_permalink( $page ) : home_url( '/' );
            return '<p class="empty">Les révisions sont réservées aux abonnés. '
                 . '<a href="' . esc_url( $url ) . '">Découvrir les offres</a>.</p>';
        }

        // Une révision est-elle en cours (ou son résultat demandé) ?
        // Si oui, on déroule ICI, sur la page Révisions : le candidat doit rester
        // dans le contexte « révision », pas être renvoyé vers la page Examens.
        $tentative_id = isset( $_GET['t'] ) ? (int) $_GET['t'] : 0;
        if ( $tentative_id ) {
            // Le déroulé et le résultat sont ceux de l'examen (même mécanique),
            // mais affichés dans le contexte de la révision.
            return NPQ_Examen::rendu();
        }

        return self::ecran_choix();
    }

    /**
     * Écran de choix : parcours proposés + composition libre.
     */
    private static function ecran_choix() {
        $domaines         = self::domaines_disponibles();
        $parcours         = self::parcours_proposes();
        $certification_id = self::certification_courante();

        ob_start();
        ?>
        <div class="npq-revision">
            <h2>Réviser</h2>
            <p class="npq-rev-intro">
                Entraînez-vous sans contrainte de temps. Les explications s'affichent
                après chaque réponse, pour comprendre au fur et à mesure.
            </p>

            <!-- Parcours proposés par NormaPrep -->
            <div class="sec-title">Parcours proposés</div>
            <div class="npq-parcours-grille">
                <?php foreach ( $parcours as $cle => $par ) :
                    $dispo = NPQ_Composeur::compter_domaines( $certification_id, $par['domaines'] );
                ?>
                    <div class="npq-parcours-carte">
                        <h3><?php echo esc_html( $par['titre'] ); ?></h3>
                        <p class="npq-parcours-resume"><?php echo esc_html( $par['resume'] ); ?></p>
                        <p class="npq-parcours-nb">
                            <?php echo (int) min( $par['nombre'], $dispo ); ?> question(s)
                        </p>
                        <form method="post">
                            <input type="hidden" name="npq_revision_action" value="parcours">
                            <input type="hidden" name="npq_parcours" value="<?php echo (int) $cle; ?>">
                            <?php wp_nonce_field( 'npq_revision', 'npq_nonce' ); ?>
                            <button type="submit" class="npq-btn" <?php disabled( $dispo === 0 ); ?>>
                                Réviser
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Composition libre -->
            <div class="sec-title">Composer ma révision</div>
            <form method="post" class="npq-composer">
                <input type="hidden" name="npq_revision_action" value="composer">
                <?php wp_nonce_field( 'npq_revision', 'npq_nonce' ); ?>

                <p class="npq-champ-label">Domaines à réviser</p>
                <p class="npq-champ-aide">
                    Laissez tout décoché pour piocher dans l'ensemble du programme.
                </p>

                <div class="npq-domaines-liste">
                    <?php foreach ( $domaines as $d ) :
                        $nb = NPQ_Composeur::compter_domaines( $certification_id, [ $d['code'] ] );
                    ?>
                        <label class="npq-domaine-case">
                            <input type="checkbox" name="npq_domaines[]" value="<?php echo esc_attr( $d['code'] ); ?>">
                            <span class="npq-dom-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                            <span class="npq-dom-nb"><?php echo (int) $nb; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p class="npq-champ-label">Nombre de questions</p>
                <div class="npq-nombre-choix">
                    <?php foreach ( [ 5, 10, 15, 20, 30 ] as $n ) : ?>
                        <label class="npq-nombre-case">
                            <input type="radio" name="npq_nombre" value="<?php echo $n; ?>" <?php checked( $n, 10 ); ?>>
                            <?php echo $n; ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="submit" class="npq-btn">Lancer la révision</button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /** Domaines disponibles (code + libellé) pour la certification courante. */
    private static function domaines_disponibles() {
        $certification_id = self::certification_courante();
        if ( ! $certification_id ) {
            return [];
        }
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT code, libelle FROM {$p}domaine
             WHERE certification_id = %d
             ORDER BY code ASC",
            $certification_id
        ), ARRAY_A );
    }

    /** Certification active — délègue à la résolution centralisée. */
    private static function certification_courante() {
        return NPQ_Certification::id();
    }
}
