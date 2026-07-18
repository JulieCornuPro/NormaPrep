<?php
/**
 * Liste des certifications en administration.
 *
 * La certification ACTIVE est celle sur laquelle porte le travail : import de
 * contenu, création de questions, scénarios, parcours et examens s'y rattachent.
 * Une seule peut être active à la fois (invariant garanti par
 * NPQ_Certification::definir_active).
 *
 * Calqué sur NPQ_Table_Examens / NPQ_Table_Parcours pour rester cohérent avec
 * le reste de l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Certifications extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'certification',
            'plural'   => 'certifications',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'code'        => 'Code',
            'nom'         => 'Nom',
            'questions'   => 'Questions',
            'scenarios'   => 'Scénarios',
            'flashcards'  => 'Flashcards',
            'statut'      => 'Statut',
        ];
    }

    public function get_sortable_columns() {
        return [
            'code' => [ 'code', true ],
            'nom'  => [ 'nom', false ],
        ];
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Code » : en monospace, comme un identifiant technique. */
    public function column_code( $item ) {
        return '<code>' . esc_html( $item['code'] ) . '</code>';
    }

    /**
     * Colonne « Nom » : le libellé, avec les actions au survol.
     * Les actions dépendent de l'état : on ne propose « Travailler dessus » que
     * pour une certification inactive, et « Supprimer » que si elle est vide.
     */
    public function column_nom( $item ) {
        $id = (int) $item['id'];
        $est_active = ( $id === $this->active_id );

        $nom = '<strong>' . esc_html( $item['nom'] ) . '</strong>';

        if ( self::est_vide( $item ) ) {
            $nom .= '<br><span style="color:#646970;font-size:12px">Aucun contenu importé</span>';
        }

        $actions = [];

        if ( ! $est_active ) {
            $url_activer = wp_nonce_url(
                add_query_arg(
                    [
                        'page'       => 'normaprep-certifications',
                        'npq_action' => 'activer_certif',
                        'id'         => $id,
                    ],
                    admin_url( 'admin.php' )
                ),
                'npq_activer_certif_' . $id
            );
            $actions['activer'] = '<a href="' . esc_url( $url_activer ) . '">Travailler dessus</a>';

            // Suppression proposée seulement si la certification est vide :
            // supprimer une certification qui porte du contenu serait une perte
            // de données silencieuse (le refus est aussi vérifié côté serveur).
            if ( self::est_vide( $item ) ) {
                $url_supprimer = wp_nonce_url(
                    add_query_arg(
                        [
                            'page'       => 'normaprep-certifications',
                            'npq_action' => 'supprimer_certif',
                            'id'         => $id,
                        ],
                        admin_url( 'admin.php' )
                    ),
                    'npq_supprimer_certif_' . $id
                );
                $actions['supprimer'] = '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                    . ' onclick="return confirm(\'Supprimer cette certification ?\');">Supprimer</a>';
            }
        }

        return $nom . $this->row_actions( $actions );
    }

    public function column_questions( $item ) {
        return (int) $item['nb_questions'];
    }

    public function column_scenarios( $item ) {
        return (int) $item['nb_scenarios'];
    }

    public function column_flashcards( $item ) {
        return (int) $item['nb_flashcards'];
    }

    /** Colonne « Statut » : active ou non. */
    public function column_statut( $item ) {
        if ( (int) $item['id'] === $this->active_id ) {
            return '<strong style="color:#00a32a">● Active</strong>';
        }
        return '<span style="color:#646970">—</span>';
    }

    /** Met en évidence la ligne de la certification active. */
    public function single_row( $item ) {
        $classe = ( (int) $item['id'] === $this->active_id ) ? ' class="npq-certif-active"' : '';
        echo '<tr' . $classe . ' style="' . ( $classe ? 'background:#f0f6fc' : '' ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    public function no_items() {
        echo 'Aucune certification. Créez-en une ci-dessous.';
    }

    public function prepare_items() {
        $this->active_id = NPQ_Certification::id();

        // La liste est courte (quelques certifications) : on charge tout et on
        // trie en PHP plutôt que de complexifier la requête.
        $items = NPQ_Certification::toutes();

        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'code';
        $order   = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'desc' : 'asc';

        if ( in_array( $orderby, [ 'code', 'nom' ], true ) ) {
            usort( $items, function ( $a, $b ) use ( $orderby, $order ) {
                $cmp = strcasecmp( (string) $a[ $orderby ], (string) $b[ $orderby ] );
                return ( $order === 'desc' ) ? -$cmp : $cmp;
            } );
        }

        $this->items = $items;

        $this->set_pagination_args( [
            'total_items' => count( $items ),
            'per_page'    => count( $items ) ?: 1,
            'total_pages' => 1,
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /** Une certification sans aucun contenu rattaché. */
    private static function est_vide( $item ) {
        return ( (int) $item['nb_questions']
               + (int) $item['nb_scenarios']
               + (int) $item['nb_flashcards'] ) === 0;
    }
}
