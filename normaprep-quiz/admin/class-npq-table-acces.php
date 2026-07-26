<?php
/**
 * Liste des utilisateurs et des certifications de leur bibliothèque.
 *
 * Permet de voir qui a accès à quoi, et d'ouvrir la fiche d'attribution d'un
 * utilisateur (via NPQ_Acces_Form).
 *
 * Calqué sur NPQ_Table_Certifications pour rester cohérent avec le reste de
 * l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Acces extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'accès',
            'plural'   => 'accès',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'utilisateur'    => 'Utilisateur',
            'email'          => 'E-mail',
            'certifications' => 'Certifications acquises',
        ];
    }

    public function get_sortable_columns() {
        return [
            'utilisateur' => [ 'nom_affiche', true ],
        ];
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Utilisateur » : nom, avec l'action « Gérer les accès ». */
    public function column_utilisateur( $item ) {
        $id = (int) $item['id'];

        $url_gerer = add_query_arg(
            [
                'page'    => 'normaprep-acces',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $nom = $item['nom_affiche'] !== '' ? $item['nom_affiche'] : '(sans nom)';
        $lien = '<strong><a href="' . esc_url( $url_gerer ) . '">' . esc_html( $nom ) . '</a></strong>';

        $actions = [
            'gerer' => '<a href="' . esc_url( $url_gerer ) . '">Gérer les accès</a>',
        ];

        return $lien . $this->row_actions( $actions );
    }

    public function column_email( $item ) {
        return $item['email'] !== '' ? esc_html( $item['email'] ) : '<span style="color:#646970">—</span>';
    }

    /** Colonne « Certifications » : la liste des codes acquis, ou « aucune ». */
    public function column_certifications( $item ) {
        $codes = NPQ_Bibliotheque::certifications_de( (int) $item['id'] );

        if ( empty( $codes ) ) {
            return '<span style="color:#b32d2e">Aucune</span>';
        }

        $etiquettes = array_map( static function ( $c ) {
            $txt = '<code>' . esc_html( $c['code'] ) . '</code>';
            if ( ! empty( $c['fin_acces'] ) ) {
                $txt .= ' <span style="color:#646970" title="Expire le ' . esc_attr( $c['fin_acces'] ) . '">(temporaire)</span>';
            }
            return $txt;
        }, $codes );

        return implode( ' &nbsp; ', $etiquettes );
    }

    public function no_items() {
        echo 'Aucun utilisateur inscrit pour le moment.';
    }

    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $par_page = 30;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        $tri_autorise = [ 'nom_affiche' => 'nom_affiche' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'nom_affiche';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'nom_affiche';
        $order = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'DESC' : 'ASC';

        $where = '1=1';
        $args  = [];

        if ( $recherche !== '' ) {
            $like = '%' . $wpdb->esc_like( $recherche ) . '%';
            $where .= ' AND ( nom_affiche LIKE %s OR email LIKE %s )';
            $args[] = $like;
            $args[] = $like;
        }

        $sql_total = "SELECT COUNT(*) FROM {$p}utilisateur WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        $sql = "SELECT id, nom_affiche, email
                FROM {$p}utilisateur
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

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }
}
