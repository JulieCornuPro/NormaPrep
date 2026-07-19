<?php
/**
 * Liste des modèles d'examen en administration.
 *
 * Un modèle d'examen décrit une épreuve réutilisable. Pour cette première
 * brique, on gère le type « scenarios » : l'examen est rattaché à un ou
 * plusieurs scénarios (table de liaison examen_scenario) et, au démarrage,
 * il tire aléatoirement son nombre de questions cible parmi les questions de
 * ces scénarios. Le tirage variant à chaque passage, la rotation s'améliore à
 * mesure que la banque de questions grossit.
 *
 * Calqué sur NPQ_Table_Parcours pour rester cohérent avec le reste de l'admin.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Examens extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'examen',
            'plural'   => 'examens',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'nom'           => 'Examen',
            'certification' => 'Certification',
            'type'          => 'Composition',
            'scenarios'     => 'Scénarios',
            'nombre'        => 'Questions',
            'actif'         => 'Actif',
        ];
    }

    public function get_sortable_columns() {
        return [
            'nom' => [ 'nom', true ],
        ];
    }

    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Examen » : le nom, avec les actions au survol. */
    public function column_nom( $item ) {
        $id = (int) $item['id'];

        $url_modifier = add_query_arg(
            [ 'page' => 'normaprep-examens', 'npq_vue' => 'form', 'id' => $id ],
            admin_url( 'admin.php' )
        );

        $url_supprimer = wp_nonce_url(
            add_query_arg(
                [ 'page' => 'normaprep-examens', 'npq_action' => 'supprimer_examen', 'id' => $id ],
                admin_url( 'admin.php' )
            ),
            'npq_supprimer_examen_' . $id
        );

        $nom = '<strong><a href="' . esc_url( $url_modifier ) . '">'
             . esc_html( $item['nom'] ) . '</a></strong>';

        $actions = [
            'modifier'  => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
            'supprimer' => '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                         . ' onclick="return confirm(\'Supprimer définitivement cet examen ?\');">'
                         . 'Supprimer</a>',
        ];

        return $nom . $this->row_actions( $actions );
    }

    /**
     * Colonne « Certification » : à quelle certification appartient l'examen.
     * Un point vert signale la certification active.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Examen rattaché à aucune certification">—</span>';
        }

        $libelle = '<code>' . esc_html( $code ) . '</code>';

        if ( (int) $item['certification_id'] === $this->active_id ) {
            $libelle .= ' <span style="color:#00a32a" title="Certification active">●</span>';
        }

        return $libelle;
    }

    /** Colonne « Composition ». */
    public function column_type( $item ) {
        switch ( $item['type'] ) {
            case 'scenarios':
                return 'Par scénarios';
            case 'genere':
                return 'Par critères';
            case 'fige':
                return 'Questions figées';
            default:
                return esc_html( $item['type'] );
        }
    }

    /** Colonne « Scénarios » : combien de scénarios rattachés. */
    public function column_scenarios( $item ) {
        $n = (int) $item['nb_scenarios'];
        if ( $n === 0 ) {
            return '<span style="color:#b32d2e" title="Aucun scénario rattaché">0</span>';
        }
        return $n;
    }

    /**
     * Colonne « Questions » : nombre cible et, entre parenthèses, le total
     * réellement disponible dans les scénarios rattachés. Si le disponible est
     * inférieur à la cible, on le signale en rouge.
     */
    public function column_nombre( $item ) {
        $cible = (int) $item['nombre_questions'];
        $dispo = (int) $item['questions_dispo'];

        if ( $item['type'] === 'scenarios' && $dispo < $cible ) {
            return sprintf(
                '%d <span style="color:#b32d2e" title="Questions disponibles dans les scénarios rattachés">(seulement %d dispo.)</span>',
                $cible, $dispo
            );
        }
        return $cible;
    }

    /** Colonne « Actif ». */
    public function column_actif( $item ) {
        return ( (int) $item['actif'] === 1 )
            ? '<span style="color:#00a32a">Oui</span>'
            : '<span style="color:#646970">Non</span>';
    }

    /**
     * Barre de filtres : sélection de la certification.
     * Le filtre est un paramètre d'URL (npq_certif) qui se combine avec le tri
     * et la pagination de WP_List_Table.
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
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-examens' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function no_items() {
        echo 'Aucun examen. Créez-en un, puis rattachez-lui des scénarios.';
    }

    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $par_page = 20;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        // Certification active : sert à signaler la ligne correspondante.
        $this->active_id = NPQ_Certification::id();

        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        $tri_autorise = [ 'nom' => 'm.nom' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'nom';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'm.nom';
        $order = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'DESC' : 'ASC';

        // Clause de filtrage (certification).
        $where = '1=1';
        $args  = [];

        if ( $certif_filtre > 0 ) {
            $where .= ' AND m.certification_id = %d';
            $args[] = $certif_filtre;
        }

        $sql_total = "SELECT COUNT(*) FROM {$p}examen_modele m WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        // Modèles + nombre de scénarios rattachés (sous-requête) + certification.
        $sql = "SELECT m.id, m.nom, m.type, m.nombre_questions, m.actif,
                       m.certification_id,
                       c.code AS certification_code,
                       ( SELECT COUNT(*) FROM {$p}examen_scenario es
                         WHERE es.examen_modele_id = m.id ) AS nb_scenarios
                FROM {$p}examen_modele m
                LEFT JOIN {$p}certification c ON c.id = m.certification_id
                WHERE {$where}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $args_liste = array_merge( $args, [ $par_page, $offset ] );
        $modeles = (array) $wpdb->get_results(
            $wpdb->prepare( $sql, $args_liste ),
            ARRAY_A
        );

        // Pour chaque modèle « scenarios », combien de questions sont réellement
        // disponibles dans les scénarios rattachés (pour l'alerte « < cible »).
        foreach ( $modeles as &$m ) {
            $m['questions_dispo'] = ( $m['type'] === 'scenarios' )
                ? self::compter_questions_dispo( (int) $m['id'] )
                : 0;
        }
        unset( $m );

        $this->items = $modeles;

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $par_page,
            'total_pages' => (int) ceil( $total / $par_page ),
        ] );

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
    }

    /**
     * Nombre de questions publiées disponibles dans les scénarios rattachés à
     * un modèle d'examen. Sert à prévenir l'admin quand la cible n'est pas
     * atteignable.
     */
    private static function compter_questions_dispo( $examen_modele_id ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$p}question q
             INNER JOIN {$p}examen_scenario es ON es.scenario_id = q.scenario_id
             WHERE es.examen_modele_id = %d AND q.statut = 'publie'",
            $examen_modele_id
        ) );
    }
}
