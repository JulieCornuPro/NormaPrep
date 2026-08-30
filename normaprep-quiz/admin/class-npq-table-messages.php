<?php
/**
 * Liste des messages reçus par le formulaire de contact.
 *
 * Calqué sur NPQ_Table_Acces pour rester cohérent avec le reste de l'admin.
 *
 * L'écran sert à UNE chose : voir ce qui est arrivé, et marquer ce qui est
 * traité. Il ne permet pas de répondre — une réponse se rédige depuis sa
 * messagerie, où l'on a ses signatures, ses pièces jointes et l'historique de
 * l'échange. Reconstruire cela ici serait un mauvais client de messagerie.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Messages extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'message',
            'plural'   => 'messages',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'date_envoi' => 'Reçu le',
            'nom'        => 'De',
            'motif'      => 'Motif',
            'message'    => 'Message',
            'statut'     => 'Statut',
        ];
    }

    public function get_sortable_columns() {
        return [
            'date_envoi' => [ 'date_envoi', true ],
            'motif'      => [ 'motif', false ],
        ];
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Date, avec l'action « marquer comme traité » ou son inverse. */
    public function column_date_envoi( $item ) {
        $id      = (int) $item['id'];
        $traite  = ( 'traite' === $item['statut'] );
        $cible   = $traite ? 'nouveau' : 'traite';
        $libelle = $traite ? 'Rouvrir' : 'Marquer traité';

        $url = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'normaprep-messages', 'npq_statut' => $cible, 'id' => $id ],
                admin_url( 'admin.php' )
            ),
            'npq_message_statut_' . $id
        );

        $actions = [
            'statut' => '<a href="' . esc_url( $url ) . '">' . esc_html( $libelle ) . '</a>',
        ];

        return sprintf(
            '<strong>%s</strong>%s',
            esc_html( date_i18n( 'd/m/Y H:i', strtotime( $item['date_envoi'] ) ) ),
            $this->row_actions( $actions )
        );
    }

    /** Expéditeur : nom, puis adresse cliquable pour répondre. */
    public function column_nom( $item ) {
        return sprintf(
            '%s<br><a href="mailto:%s">%s</a>',
            esc_html( $item['nom'] ),
            esc_attr( $item['email'] ),
            esc_html( $item['email'] )
        );
    }

    public function column_motif( $item ) {
        $motifs = NPQ_Contact::motifs();
        return esc_html( $motifs[ $item['motif'] ] ?? $item['motif'] );
    }

    /**
     * Le message, en entier.
     *
     * Pas de troncature : un message de contact tient en quelques lignes, et
     * devoir cliquer pour lire chacun d'eux ferait de cet écran une liste de
     * choses à ouvrir plutôt qu'une liste de choses à lire.
     */
    public function column_message( $item ) {
        return '<div style="max-width:60ch;white-space:pre-wrap">'
             . esc_html( $item['message'] )
             . '</div>';
    }

    public function column_statut( $item ) {
        $traite = ( 'traite' === $item['statut'] );

        return sprintf(
            '<span style="color:%s">%s</span>',
            $traite ? '#46b450' : '#d63638',
            $traite ? 'Traité' : 'Nouveau'
        );
    }

    public function no_items() {
        echo 'Aucun message reçu pour le moment.';
    }

    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

        $par_page = 20;
        $page     = $this->get_pagenum();

        // Liste blanche sur le tri : ces valeurs partent dans du SQL que
        // prepare() ne peut pas protéger — un nom de colonne n'est pas une
        // valeur, il ne peut pas être passé en paramètre.
        $tri_permis = [ 'date_envoi', 'motif' ];
        $tri = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $tri_permis, true )
            ? $_GET['orderby']
            : 'date_envoi';

        $sens = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}message" );

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}message ORDER BY {$tri} {$sens} LIMIT %d OFFSET %d",
                $par_page,
                ( $page - 1 ) * $par_page
            ),
            ARRAY_A
        );

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $par_page,
            'total_pages' => (int) ceil( $total / $par_page ),
        ] );
    }

    /**
     * Applique un changement de statut demandé par l'URL.
     *
     * Appelée avant l'affichage de la liste, pour que celle-ci montre déjà
     * l'état à jour.
     */
    public static function traiter_action() {
        if ( empty( $_GET['npq_statut'] ) || empty( $_GET['id'] ) ) {
            return;
        }

        $id = (int) $_GET['id'];

        if ( ! current_user_can( 'manage_options' )
             || ! isset( $_GET['_wpnonce'] )
             || ! wp_verify_nonce( $_GET['_wpnonce'], 'npq_message_statut_' . $id ) ) {
            return;
        }

        $statut = ( 'traite' === $_GET['npq_statut'] ) ? 'traite' : 'nouveau';

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $wpdb->update( "{$p}message", [ 'statut' => $statut ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
    }
}
