<?php
/**
 * Liste des parcours de révision en administration.
 *
 * Un parcours de révision est une composition préprogrammée proposée au
 * candidat sur la page « Révisions » : un titre, un résumé, une liste de
 * domaines à piocher et un nombre de questions. Auparavant figés dans le code
 * (NPQ_Revision::parcours_proposes), ils sont désormais administrables.
 *
 * Utilise WP_List_Table, la classe native de WordPress : pagination, tri et
 * recherche avec le rendu standard de l'admin. Calqué sur NPQ_Table_Scenarios.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// WP_List_Table n'est pas chargée par défaut sur toutes les pages d'admin.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Parcours extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'parcours',
            'plural'   => 'parcours',
            'ajax'     => false,
        ] );
    }

    /** Colonnes affichées. */
    public function get_columns() {
        return [
            'titre'         => 'Parcours',
            'certification' => 'Certification',
            'resume'        => 'Résumé',
            'domaines'      => 'Domaines',
            'nombre'        => 'Questions',
            'statut'        => 'Statut',
        ];
    }

    /** Colonnes triables. */
    public function get_sortable_columns() {
        return [
            'titre'  => [ 'titre', true ],
            'nombre' => [ 'nombre', false ],
        ];
    }

    /** Rendu par défaut d'une cellule. */
    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Parcours » : le titre, avec les actions au survol. */
    public function column_titre( $item ) {
        $id = (int) $item['id'];

        $url_modifier = add_query_arg(
            [
                'page'    => 'normaprep-parcours',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $url_supprimer = wp_nonce_url(
            add_query_arg(
                [
                    'page'       => 'normaprep-parcours',
                    'npq_action' => 'supprimer_parcours',
                    'id'         => $id,
                ],
                admin_url( 'admin.php' )
            ),
            'npq_supprimer_parcours_' . $id
        );

        $titre = '<strong><a href="' . esc_url( $url_modifier ) . '">'
               . esc_html( $item['titre'] ) . '</a></strong>';

        $actions = [
            'modifier'  => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
            'supprimer' => '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                         . ' onclick="return confirm(\'Supprimer définitivement ce parcours ?\');">'
                         . 'Supprimer</a>',
        ];

        return $titre . $this->row_actions( $actions );
    }

    /**
     * Colonne « Certification » : à quelle certification appartient le parcours.
     * Un point vert signale la certification active.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Parcours rattaché à aucune certification">—</span>';
        }

        $libelle = '<code>' . esc_html( $code ) . '</code>';

        if ( (int) $item['certification_id'] === $this->active_id ) {
            $libelle .= ' <span style="color:#00a32a" title="Certification active">●</span>';
        }

        return $libelle;
    }

    /** Colonne « Résumé » : tronqué, pour ne pas casser la mise en page. */
    public function column_resume( $item ) {
        $resume = (string) $item['resume'];
        if ( mb_strlen( $resume ) > 90 ) {
            $resume = mb_substr( $resume, 0, 90 ) . '…';
        }
        return esc_html( $resume );
    }

    /**
     * Colonne « Domaines » : la liste des codes de domaine du parcours.
     * Stockée en JSON, on la décode pour l'afficher lisiblement.
     */
    public function column_domaines( $item ) {
        $codes = json_decode( (string) $item['domaines'], true );
        if ( ! is_array( $codes ) || empty( $codes ) ) {
            return '<span style="color:#646970" title="Pioche dans tout le programme">Tout le programme</span>';
        }
        return esc_html( implode( ', ', $codes ) );
    }

    /** Colonne « Questions » : le nombre visé pour ce parcours. */
    public function column_nombre( $item ) {
        return (int) $item['nombre'];
    }

    /** Colonne « Statut ». */
    public function column_statut( $item ) {
        $statut = (string) $item['statut'];

        if ( $statut === 'publie' ) {
            return '<span style="color:#00a32a">Publié</span>';
        }
        return '<span style="color:#646970">' . esc_html( $statut ) . '</span>';
    }

    /**
     * Bouton « Réinitialiser », affiché seulement si une recherche est active.
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

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
                <?php submit_button( 'Filtrer', '', 'filtrer', false ); ?>
            <?php endif; ?>

            <?php if ( $recherche !== '' || $certif_filtre > 0 ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-parcours' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Message quand il n'y a rien à afficher. */
    public function no_items() {
        echo 'Aucun parcours de révision. Créez-en un pour le proposer sur la page Révisions.';
    }

    /**
     * Prépare les données : recherche, tri, pagination.
     */
    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $par_page = 20;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        // Certification active : sert à signaler la ligne correspondante.
        $this->active_id = NPQ_Certification::id();

        // Recherche.
        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Tri (avec liste blanche : on n'injecte jamais une colonne venue de l'URL).
        $tri_autorise = [ 'titre' => 'p2.titre', 'nombre' => 'p2.nombre' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'position';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'p2.position';

        $order = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' )
               ? 'DESC' : 'ASC';

        // Clause de recherche.
        $where = '1=1';
        $args  = [];

        if ( $recherche !== '' ) {
            $like  = '%' . $wpdb->esc_like( $recherche ) . '%';
            $where .= ' AND ( p2.titre LIKE %s OR p2.resume LIKE %s )';
            $args[] = $like;
            $args[] = $like;
        }

        if ( $certif_filtre > 0 ) {
            $where .= ' AND p2.certification_id = %d';
            $args[] = $certif_filtre;
        }

        // Total (pour la pagination).
        $sql_total = "SELECT COUNT(*) FROM {$p}parcours p2 WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        // La liste.
        $sql = "SELECT p2.id, p2.titre, p2.resume, p2.domaines, p2.nombre,
                       p2.statut, p2.position, p2.certification_id,
                       c.code AS certification_code
                FROM {$p}parcours p2
                LEFT JOIN {$p}certification c ON c.id = p2.certification_id
                WHERE {$where}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $args_liste = array_merge( $args, [ $par_page, $offset ] );
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
}
