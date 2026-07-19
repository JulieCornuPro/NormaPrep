<?php
/**
 * Liste des domaines en administration.
 *
 * Un domaine est une subdivision thématique d'une certification (ex. « D3 —
 * Appréciation des risques »). Les questions, flashcards et parcours s'y
 * rattachent ; la pondération des examens s'appuie dessus.
 *
 * Les domaines étaient auparavant créés uniquement par effet de bord de
 * l'import (assurer_domaine). Cette page permet de les gérer directement, ce
 * qui est indispensable pour préparer une certification avant d'en importer le
 * contenu.
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

class NPQ_Table_Domaines extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'domaine',
            'plural'   => 'domaines',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'code'          => 'Code',
            'libelle'       => 'Libellé',
            'certification' => 'Certification',
            'questions'     => 'Questions',
            'flashcards'    => 'Flashcards',
        ];
    }

    public function get_sortable_columns() {
        return [
            'code'    => [ 'code', true ],
            'libelle' => [ 'libelle', false ],
        ];
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Code » : identifiant technique, avec les actions au survol. */
    public function column_code( $item ) {
        $id = (int) $item['id'];

        $url_modifier = add_query_arg(
            [
                'page'    => 'normaprep-domaines',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $code = '<strong><a href="' . esc_url( $url_modifier ) . '"><code>'
              . esc_html( $item['code'] ) . '</code></a></strong>';

        $actions = [
            'modifier' => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
        ];

        // Suppression proposée seulement si le domaine ne porte aucun contenu :
        // supprimer un domaine utilisé laisserait des questions et des cartes
        // pointant vers un code inexistant.
        if ( self::est_vide( $item ) ) {
            $url_supprimer = wp_nonce_url(
                add_query_arg(
                    [
                        'page'       => 'normaprep-domaines',
                        'npq_action' => 'supprimer_domaine',
                        'id'         => $id,
                    ],
                    admin_url( 'admin.php' )
                ),
                'npq_supprimer_domaine_' . $id
            );
            $actions['supprimer'] = '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                . ' onclick="return confirm(\'Supprimer ce domaine ?\');">Supprimer</a>';
        }

        return $code . $this->row_actions( $actions );
    }

    /**
     * Colonne « Certification » : à quelle certification appartient ce domaine.
     * Un point vert signale la certification active.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Domaine rattaché à aucune certification">—</span>';
        }

        $libelle = '<code>' . esc_html( $code ) . '</code>';

        if ( (int) $item['certification_id'] === $this->active_id ) {
            $libelle .= ' <span style="color:#00a32a" title="Certification active">●</span>';
        }

        return $libelle;
    }

    public function column_questions( $item ) {
        return (int) $item['nb_questions'];
    }

    public function column_flashcards( $item ) {
        return (int) $item['nb_flashcards'];
    }

    /**
     * Barre de filtres : sélection de la certification.
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        $certifications = NPQ_Certification::toutes();

        // Inutile de proposer le filtre s'il n'y a qu'une certification.
        if ( count( $certifications ) <= 1 ) {
            return;
        }
        ?>
        <div class="alignleft actions">
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

            <?php if ( $certif_filtre > 0 ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-domaines' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function no_items() {
        echo 'Aucun domaine. Créez-en un, ou importez le contenu d\'une certification.';
    }

    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $this->active_id = NPQ_Certification::id();

        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Tri (liste blanche : on n'injecte jamais une colonne venue de l'URL).
        $tri_autorise = [ 'code' => 'd.code', 'libelle' => 'd.libelle' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'code';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'd.code';
        $order = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'DESC' : 'ASC';

        $where = '1=1';
        $args  = [];

        if ( $certif_filtre > 0 ) {
            $where .= ' AND d.certification_id = %d';
            $args[] = $certif_filtre;
        }

        $sql_total = "SELECT COUNT(*) FROM {$p}domaine d WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        // Les compteurs joignent sur la CERTIFICATION en plus du code : deux
        // certifications peuvent avoir chacune un domaine « D1 », et leurs
        // contenus ne doivent pas être additionnés.
        $sql = "SELECT d.id, d.code, d.libelle, d.certification_id,
                       c.code AS certification_code,
                       ( SELECT COUNT(*) FROM {$p}question q
                         WHERE q.domaine = d.code
                           AND q.certification_id = d.certification_id ) AS nb_questions,
                       ( SELECT COUNT(*) FROM {$p}flashcard f
                         WHERE f.domaine = d.code
                           AND f.certification_id = d.certification_id ) AS nb_flashcards
                FROM {$p}domaine d
                LEFT JOIN {$p}certification c ON c.id = d.certification_id
                WHERE {$where}
                ORDER BY d.certification_id ASC, {$orderby} {$order}";

        $this->items = $args
            ? (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A )
            : (array) $wpdb->get_results( $sql, ARRAY_A );

        // Liste courte par nature : pas de pagination.
        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $total ?: 1,
            'total_pages' => 1,
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /** Un domaine sans aucune question ni flashcard. */
    private static function est_vide( $item ) {
        return ( (int) $item['nb_questions'] + (int) $item['nb_flashcards'] ) === 0;
    }
}
