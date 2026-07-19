<?php
/**
 * Liste des questions en administration.
 *
 * Avec 130 questions et plus, la recherche textuelle ne suffit pas : on filtre
 * par domaine (pour enrichir D1, par exemple), par scénario, et par origine
 * (importée / créée ici).
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Questions extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'question',
            'plural'   => 'questions',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'enonce'        => 'Question',
            'certification' => 'Certification',
            'domaine'       => 'Domaine',
            'scenario'      => 'Scénario',
            'difficulte'    => 'Difficulté',
            'ref_externe'   => 'Origine',
        ];
    }

    public function get_sortable_columns() {
        return [
            'domaine'    => [ 'domaine', false ],
            'difficulte' => [ 'difficulte', false ],
        ];
    }

    /**
     * Colonne « Certification » : à quelle certification appartient la question.
     * Un point vert signale la certification active.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Question rattachée à aucune certification">—</span>';
        }

        $libelle = '<code>' . esc_html( $code ) . '</code>';

        if ( (int) $item['certification_id'] === $this->active_id ) {
            $libelle .= ' <span style="color:#00a32a" title="Certification active">●</span>';
        }

        return $libelle;
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Question » : l'énoncé tronqué, avec les actions. */
    public function column_enonce( $item ) {
        $id = (int) $item['id'];

        $enonce = (string) $item['enonce'];
        if ( mb_strlen( $enonce ) > 110 ) {
            $enonce = mb_substr( $enonce, 0, 110 ) . '…';
        }

        $url_modifier = add_query_arg(
            [
                'page'    => 'normaprep-questions',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $url_supprimer = wp_nonce_url(
            add_query_arg(
                [
                    'page'       => 'normaprep-questions',
                    'npq_action' => 'supprimer_question',
                    'id'         => $id,
                ],
                admin_url( 'admin.php' )
            ),
            'npq_supprimer_question_' . $id
        );

        $titre = '<strong><a href="' . esc_url( $url_modifier ) . '">'
               . esc_html( $enonce ) . '</a></strong>';

        $actions = [
            'modifier'  => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
            'supprimer' => '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                         . ' onclick="return confirm(\'Supprimer définitivement cette question ?\');">'
                         . 'Supprimer</a>',
        ];

        return $titre . $this->row_actions( $actions );
    }

    /** Colonne « Domaine » : le code et son libellé. */
    public function column_domaine( $item ) {
        $code = esc_html( $item['domaine'] );
        $lib  = esc_html( $item['domaine_libelle'] ?? '' );

        if ( $lib === '' ) {
            return '<strong>' . $code . '</strong>';
        }
        return '<strong>' . $code . '</strong><br>'
             . '<span style="color:#646970;font-size:12px">' . $lib . '</span>';
    }

    public function column_scenario( $item ) {
        $nom = (string) ( $item['scenario_nom'] ?? '' );
        return $nom !== '' ? esc_html( $nom ) : '<span style="color:#8c8f94">—</span>';
    }

    public function column_difficulte( $item ) {
        return esc_html( $item['difficulte'] );
    }

    public function column_ref_externe( $item ) {
        if ( ! empty( $item['ref_externe'] ) ) {
            return '<span title="' . esc_attr( $item['ref_externe'] ) . '" '
                 . 'style="color:#646970">Importée</span>';
        }
        return '<span style="color:#00a32a">Créée ici</span>';
    }

    public function no_items() {
        echo 'Aucune question ne correspond.';
    }

    /**
     * Filtres au-dessus de la liste : domaine, scénario, origine.
     * Un bouton « Réinitialiser » apparaît dès qu'un filtre est actif : sans lui,
     * il faudrait remettre chaque liste déroulante à zéro une par une.
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $domaine_actif  = isset( $_REQUEST['npq_domaine'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['npq_domaine'] ) ) : '';
        $scenario_actif = isset( $_REQUEST['npq_scenario'] ) ? (int) $_REQUEST['npq_scenario'] : 0;
        $origine_active = isset( $_REQUEST['npq_origine'] ) ? sanitize_key( $_REQUEST['npq_origine'] ) : '';
        $recherche      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Un filtre (ou une recherche) est-il actif ?
        $filtre_actif = ( $domaine_actif !== '' )
                     || ( $scenario_actif > 0 )
                     || ( $origine_active !== '' )
                     || ( $certif_filtre > 0 )
                     || ( $recherche !== '' );

        $certifications = NPQ_Certification::toutes();
        // Inutile de proposer le filtre s'il n'y a qu'une certification.
        $afficher_certif = ( count( $certifications ) > 1 );
        ?>
        <div class="alignleft actions">
            <?php if ( $afficher_certif ) : ?>
                <label class="screen-reader-text" for="npq-filtre-certif">Filtrer par certification</label>
                <select name="npq_certif" id="npq-filtre-certif">
                    <option value="0">Toutes les certifications</option>
                    <?php foreach ( $certifications as $c ) : ?>
                        <option value="<?php echo (int) $c['id']; ?>"
                            <?php selected( $certif_filtre, (int) $c['id'] ); ?>>
                            <?php
                            echo esc_html( $c['nom'] );
                            if ( (int) $c['id'] === $this->active_id ) {
                                echo ' (active)';
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select name="npq_domaine">
                <option value="">Tous les domaines</option>
                <?php foreach ( self::domaines() as $d ) : ?>
                    <option value="<?php echo esc_attr( $d['code'] ); ?>"
                        <?php selected( $domaine_actif, $d['code'] ); ?>>
                        <?php echo esc_html( $d['code'] . ' — ' . $d['libelle'] ); ?>
                        (<?php echo (int) $d['nb']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="npq_scenario">
                <option value="">Tous les scénarios</option>
                <?php foreach ( self::scenarios() as $s ) : ?>
                    <option value="<?php echo (int) $s['id']; ?>"
                        <?php selected( $scenario_actif, (int) $s['id'] ); ?>>
                        <?php echo esc_html( $s['nom'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="npq_origine">
                <option value="">Toutes origines</option>
                <option value="importee" <?php selected( $origine_active, 'importee' ); ?>>Importées</option>
                <option value="locale" <?php selected( $origine_active, 'locale' ); ?>>Créées ici</option>
            </select>

            <?php submit_button( 'Filtrer', '', 'filtrer', false ); ?>

            <?php if ( $filtre_actif ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-questions' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Prépare les données : filtres, recherche, tri, pagination.
     */
    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $par_page = 20;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $domaine   = isset( $_REQUEST['npq_domaine'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['npq_domaine'] ) ) : '';
        $scenario  = isset( $_REQUEST['npq_scenario'] ) ? (int) $_REQUEST['npq_scenario'] : 0;
        $origine   = isset( $_REQUEST['npq_origine'] ) ? sanitize_key( $_REQUEST['npq_origine'] ) : '';
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Certification active : sert à signaler la ligne correspondante.
        $this->active_id = NPQ_Certification::id();

        // Tri : liste blanche stricte (jamais de colonne venue de l'URL dans le SQL).
        $tri_autorise = [ 'domaine' => 'q.domaine', 'difficulte' => 'q.difficulte' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'domaine';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'q.domaine';
        $order        = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'DESC' : 'ASC';

        // Construction de la clause WHERE.
        $where = 'q.statut = %s';
        $args  = [ 'publie' ];

        if ( $recherche !== '' ) {
            $like   = '%' . $wpdb->esc_like( $recherche ) . '%';
            $where .= ' AND ( q.enonce LIKE %s OR q.explication LIKE %s )';
            $args[] = $like;
            $args[] = $like;
        }

        if ( $domaine !== '' ) {
            $where .= ' AND q.domaine = %s';
            $args[] = $domaine;
        }

        if ( $scenario > 0 ) {
            $where .= ' AND q.scenario_id = %d';
            $args[] = $scenario;
        }

        if ( $certif_filtre > 0 ) {
            $where .= ' AND q.certification_id = %d';
            $args[] = $certif_filtre;
        }

        if ( $origine === 'importee' ) {
            $where .= ' AND q.ref_externe IS NOT NULL';
        } elseif ( $origine === 'locale' ) {
            $where .= ' AND q.ref_externe IS NULL';
        }

        // Total (pagination).
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}question q WHERE {$where}",
            $args
        ) );

        // Les questions, avec leur domaine et leur scénario.
        $sql = "SELECT q.id, q.enonce, q.domaine, q.difficulte, q.ref_externe,
                       q.certification_id,
                       c.code    AS certification_code,
                       d.libelle AS domaine_libelle,
                       s.nom     AS scenario_nom
                FROM {$p}question q
                LEFT JOIN {$p}domaine  d
                       ON d.code = q.domaine
                      AND d.certification_id = q.certification_id
                LEFT JOIN {$p}scenario s ON s.id  = q.scenario_id
                LEFT JOIN {$p}certification c ON c.id = q.certification_id
                WHERE {$where}
                ORDER BY {$orderby} {$order}, q.id ASC
                LIMIT %d OFFSET %d";

        $args_liste  = array_merge( $args, [ $par_page, $offset ] );
        $this->items = (array) $wpdb->get_results(
            $wpdb->prepare( $sql, $args_liste ),
            ARRAY_A
        );

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $par_page,
            'total_pages' => (int) ceil( $total / $par_page ),
        ] );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /* =====================================================================
     * DONNÉES DES FILTRES
     * ===================================================================== */

    /** Domaines, avec le nombre de questions de chacun. */
    private static function domaines() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT d.code, d.libelle, COUNT(q.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}question q
                    ON q.domaine = d.code
                   AND q.certification_id = d.certification_id
                   AND q.statut = 'publie'
             GROUP BY d.code, d.libelle
             ORDER BY d.code ASC",
            ARRAY_A
        );
    }

    /** Scénarios publiés. */
    private static function scenarios() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT id, nom FROM {$p}scenario
             WHERE statut = 'publie'
             ORDER BY nom ASC",
            ARRAY_A
        );
    }
}
