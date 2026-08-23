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
    public static function parcours_proposes( $certification_id = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        if ( ! $certification_id ) {
            $certification_id = self::certification_courante();
        }

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

    /**
     * Charge un parcours publié par son id, quelle que soit sa certification.
     * Sert au traitement d'une action « parcours » : on doit connaître la
     * certification et le mode du parcours avant de vérifier l'accès.
     *
     * @param int $parcours_id
     * @return array|null
     */
    private static function parcours_par_id( $parcours_id ) {
        $parcours_id = (int) $parcours_id;
        if ( ! $parcours_id ) {
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ligne = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, certification_id, titre, type, domaines, nombre
             FROM {$p}parcours
             WHERE id = %d AND statut = 'publie'",
            $parcours_id
        ), ARRAY_A );

        if ( ! $ligne ) {
            return null;
        }

        $domaines = json_decode( (string) $ligne['domaines'], true );
        if ( ! is_array( $domaines ) ) {
            $domaines = [];
        }

        return [
            'id'               => (int) $ligne['id'],
            'certification_id' => (int) $ligne['certification_id'],
            'titre'            => $ligne['titre'],
            'type'             => $ligne['type'],
            'domaines'         => $domaines,
            'nombre'           => (int) $ligne['nombre'],
        ];
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

            // Certification choisie dans le sélecteur de composition libre.
            $certification_id = isset( $_POST['npq_certification'] ) ? (int) $_POST['npq_certification'] : 0;
            if ( ! $certification_id ) {
                $certification_id = self::certification_courante();
            }
            // Garde-fou : l'utilisateur doit avoir accès à cette certification.
            if ( ! self::peut_acceder( $certification_id ) ) {
                return;
            }
            self::lancer( $domaines, $nombre, $certification_id );

        } elseif ( $action === 'parcours' ) {
            $cle = (int) ( $_POST['npq_parcours'] ?? 0 );

            // On recharge le parcours par son id (toutes certifications de la
            // bibliothèque), pour connaître sa certification et son mode.
            $parcours = self::parcours_par_id( $cle );
            if ( ! $parcours ) {
                return;
            }

            // Garde-fou d'accès : la certification du parcours doit être dans
            // la bibliothèque de l'utilisateur.
            if ( ! self::peut_acceder( (int) $parcours['certification_id'] ) ) {
                return;
            }

            if ( ( $parcours['type'] ?? 'criteres' ) === 'questions' ) {
                self::lancer_questions( $cle, (int) $parcours['certification_id'] );
            } else {
                self::lancer(
                    $parcours['domaines'],
                    $parcours['nombre'],
                    (int) $parcours['certification_id']
                );
            }
        }
    }

    /**
     * Crée une tentative en mode « revision » et lance le déroulé.
     */
    private static function lancer( $domaines, $nombre, $certification_id = 0 ) {
        if ( ! $certification_id ) {
            $certification_id = self::certification_courante();
        }
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
            // Certification révisée : même rôle que sur une tentative d'examen.
            'certification_id' => $certification_id,
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
     *
     * @param int $parcours_id
     * @param int $certification_id Certification du parcours. Transmise par
     *                              l'appelant, qui vient de la vérifier.
     */
    private static function lancer_questions( $parcours_id, $certification_id = 0 ) {
        $questions = NPQ_Composeur::par_parcours( $parcours_id );
        if ( empty( $questions ) ) {
            return;
        }

        // Un parcours peut n'être rattaché à aucune certification (parcours
        // transverse) : on retombe alors sur la certification courante.
        $certification_id = (int) $certification_id;
        if ( ! $certification_id ) {
            $certification_id = self::certification_courante();
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
            // 0 signifierait « aucune certification en base » : on écrit NULL
            // plutôt qu'un 0 qui ressemblerait à un identifiant réel.
            'certification_id' => $certification_id ?: null,
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
            $url = NPQ_Comptes::url_offres();
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
        // Certifications de la bibliothèque de l'utilisateur.
        $certifs = self::certifications_utilisateur();
        $plusieurs = ( count( $certifs ) > 1 );

        // Pré-sélection transmise par la page Activité, qui désigne au candidat
        // son domaine le plus fragile : ?npq_domaine=D3&npq_certif=12
        //
        // Le formulaire de composition est en POST, mais on y arrive par un
        // lien — donc en GET. Sans cette lecture, le bouton « Réviser ce
        // domaine » ramenait sur la page nue et le candidat devait retrouver
        // à la main le domaine qu'on venait de lui désigner.
        $pre_domaine = isset( $_GET['npq_domaine'] )
            ? sanitize_text_field( wp_unslash( $_GET['npq_domaine'] ) )
            : '';

        $pre_certif = isset( $_GET['npq_certif'] ) ? (int) $_GET['npq_certif'] : 0;

        // La certification demandée doit appartenir à la bibliothèque : le
        // paramètre vient du navigateur. Sinon, la première.
        if ( $pre_certif && ! self::peut_acceder( $pre_certif ) ) {
            $pre_certif = 0;
        }
        if ( $pre_domaine !== '' && ! $pre_certif && ! empty( $certifs ) ) {
            $pre_certif = (int) $certifs[0]['id'];
        }

        ob_start();
        ?>
        <div class="npq-revision">
            <h2>Réviser</h2>
            <p class="npq-rev-intro">
                Entraînez-vous sans contrainte de temps. Les explications s'affichent
                après chaque réponse, pour comprendre au fur et à mesure.
            </p>

            <?php
            // --- Parcours proposés, groupés par certification ---
            // Avec une seule certification, pas de titre de groupe : l'affichage
            // reste identique à avant. Avec plusieurs, chaque certification a sa
            // propre section.
            foreach ( $certifs as $certif ) :
                $cid      = (int) $certif['id'];
                $parcours = self::parcours_proposes( $cid );

                if ( empty( $parcours ) ) {
                    continue;
                }
                ?>
                <div class="sec-title">
                    Parcours proposés
                    <?php if ( $plusieurs ) : ?>
                        <span class="npq-sec-certif">— <?php echo esc_html( $certif['nom'] ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="npq-parcours-grille">
                    <?php foreach ( $parcours as $cle => $par ) :
                        if ( ( $par['type'] ?? 'criteres' ) === 'questions' ) {
                            $dispo = NPQ_Composeur::compter_parcours_questions( $cle );
                            $affiche = $dispo;
                        } else {
                            $dispo = NPQ_Composeur::compter_domaines( $cid, $par['domaines'] );
                            $affiche = (int) min( $par['nombre'], $dispo );
                        }
                    ?>
                        <div class="npq-parcours-carte">
                            <h3><?php echo esc_html( $par['titre'] ); ?></h3>
                            <p class="npq-parcours-resume"><?php echo esc_html( $par['resume'] ); ?></p>
                            <p class="npq-parcours-nb">
                                <?php echo (int) $affiche; ?> question(s)
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
            <?php endforeach; ?>

            <!-- Composition libre : une seule, avec choix de la certification -->
            <div class="sec-title" id="npq-composer">Composer ma révision</div>
            <?php if ( $pre_domaine !== '' ) : ?>
                <p class="npq-champ-aide npq-prereglage">
                    Domaine pré-sélectionné depuis votre activité. Ajustez librement.
                </p>
            <?php endif; ?>
            <form method="post" class="npq-composer">
                <input type="hidden" name="npq_revision_action" value="composer">
                <?php wp_nonce_field( 'npq_revision', 'npq_nonce' ); ?>

                <?php if ( $plusieurs ) : ?>
                    <p class="npq-champ-label">Certification</p>
                    <select name="npq_certification" id="npq-compo-certif" class="npq-compo-select" data-npq-select>
                        <?php foreach ( $certifs as $certif ) : ?>
                            <option value="<?php echo (int) $certif['id']; ?>"
                                <?php selected( (int) $certif['id'], $pre_certif ); ?>>
                                <?php echo esc_html( $certif['nom'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="hidden" name="npq_certification"
                           value="<?php echo (int) $certifs[0]['id']; ?>">
                <?php endif; ?>

                <p class="npq-champ-label">Domaines à réviser</p>
                <p class="npq-champ-aide">
                    Laissez tout décoché pour piocher dans l'ensemble du programme.
                </p>

                <div class="npq-domaines-liste">
                    <?php
                    // Tous les domaines de toutes les certifications de la
                    // bibliothèque, marqués de leur certification. Le JavaScript
                    // n'affiche que ceux de la certification choisie.
                    foreach ( $certifs as $certif ) :
                        $cid = (int) $certif['id'];
                        foreach ( self::domaines_disponibles( $cid ) as $d ) :
                            $nb = NPQ_Composeur::compter_domaines( $cid, [ $d['code'] ] );
                    ?>
                        <label class="npq-domaine-case" data-certification="<?php echo $cid; ?>">
                            <input type="checkbox" name="npq_domaines[]" value="<?php echo esc_attr( $d['code'] ); ?>"
                                <?php
                                // Les codes de domaine étant partagés entre
                                // référentiels, on exige que la certification
                                // corresponde aussi — sinon cocher « D3 »
                                // cocherait le D3 des cinq certifications.
                                checked( $pre_domaine !== '' && $d['code'] === $pre_domaine && $cid === $pre_certif );
                                ?>>
                            <span class="npq-dom-nom"><?php echo esc_html( $d['libelle'] ); ?></span>
                            <span class="npq-dom-nb"><?php echo (int) $nb; ?></span>
                        </label>
                    <?php
                        endforeach;
                    endforeach;
                    ?>
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

        <?php if ( $plusieurs ) : ?>
        <script>
        /* Composition libre : n'afficher que les domaines de la certification
           choisie dans le sélecteur. Les cases masquées sont décochées pour ne
           pas être envoyées. (Le serveur revalide l'accès de toute façon.) */
        ( function () {
            var select = document.getElementById( 'npq-compo-certif' );
            var cases  = document.querySelectorAll( '.npq-domaine-case' );
            if ( ! select ) { return; }

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
     * OUTILS
     * ===================================================================== */

    /** Domaines disponibles (code + libellé) pour la certification courante. */
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
            "SELECT code, libelle FROM {$p}domaine
             WHERE certification_id = %d
             ORDER BY code ASC",
            $certification_id
        ), ARRAY_A );
    }

    /**
     * Certifications à afficher pour l'utilisateur courant : celles de sa
     * bibliothèque. S'il n'en a aucune (cas limite : compte sans accès encore
     * enregistré), on retombe sur la certification active pour ne pas présenter
     * une page vide.
     *
     * @return array Lignes de certification : id, code, nom.
     */
    private static function certifications_utilisateur() {
        return NPQ_Bibliotheque::certifications_utilisateur();
    }

    /**
     * L'utilisateur courant a-t-il accès à cette certification ?
     * Garde-fou appelé avant de lancer un parcours ou une composition.
     */
    private static function peut_acceder( $certification_id ) {
        return NPQ_Bibliotheque::utilisateur_peut_acceder( $certification_id );
    }

    /** Certification active — délègue à la résolution centralisée. */
    private static function certification_courante() {
        return NPQ_Certification::id();
    }
}
