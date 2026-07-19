<?php
/**
 * Gestion des scénarios en administration.
 *
 * Étape 1 : lister, chercher, paginer.
 * (Créer / modifier / supprimer viendront ensuite.)
 *
 * Utilise WP_List_Table, la classe native de WordPress : elle apporte la
 * pagination, le tri et la recherche avec le rendu standard de l'admin.
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

class NPQ_Table_Scenarios extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'scénario',
            'plural'   => 'scénarios',
            'ajax'     => false,
        ] );
    }

    /** Colonnes affichées. */
    public function get_columns() {
        return [
            'nom'           => 'Scénario',
            'certification' => 'Certification',
            'resume'        => 'Résumé',
            'nb_questions'  => 'Questions',
            'ref_externe'   => 'Origine',
            'statut'        => 'Statut',
        ];
    }

    /** Colonnes triables. */
    public function get_sortable_columns() {
        return [
            'nom'          => [ 'nom', true ],
            'nb_questions' => [ 'nb_questions', false ],
        ];
    }

    /** Rendu par défaut d'une cellule. */
    public function column_default( $item, $nom_colonne ) {
        return isset( $item[ $nom_colonne ] ) ? esc_html( $item[ $nom_colonne ] ) : '';
    }

    /** Colonne « Scénario » : le nom, avec les actions au survol. */
    public function column_nom( $item ) {
        $id = (int) $item['id'];

        $url_modifier = add_query_arg(
            [
                'page'    => 'normaprep-scenarios',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $url_supprimer = wp_nonce_url(
            add_query_arg(
                [
                    'page'       => 'normaprep-scenarios',
                    'npq_action' => 'supprimer_scenario',
                    'id'         => $id,
                ],
                admin_url( 'admin.php' )
            ),
            'npq_supprimer_scenario_' . $id
        );

        $nom = '<strong><a href="' . esc_url( $url_modifier ) . '">'
             . esc_html( $item['nom'] ) . '</a></strong>';

        $actions = [
            'modifier' => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
        ];

        // La suppression n'est proposée que si le scénario ne porte aucune question
        // (sinon elles deviendraient orphelines). On le signale plutôt que de
        // laisser cliquer pour rien.
        if ( (int) $item['nb_questions'] === 0 ) {
            $actions['supprimer'] =
                '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                . ' onclick="return confirm(\'Supprimer définitivement ce scénario ?\');">'
                . 'Supprimer</a>';
        } else {
            $actions['supprimer'] =
                '<span style="color:#8c8f94" title="Ce scénario porte des questions">'
                . 'Suppression impossible</span>';
        }

        return $nom . $this->row_actions( $actions );
    }

    /** Colonne « Résumé » : tronqué, pour ne pas casser la mise en page. */
    /**
     * Colonne « Certification » : à quelle certification appartient ce scénario.
     * La certification active est signalée, pour repérer d'un coup d'œil ce sur
     * quoi on travaille quand la liste est affichée sans filtre.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Ce scénario n\'est rattaché à aucune certification">—</span>';
        }

        $libelle = '<code>' . esc_html( $code ) . '</code>';

        if ( (int) $item['certification_id'] === $this->active_id ) {
            $libelle .= ' <span style="color:#00a32a" title="Certification active">●</span>';
        }

        return $libelle;
    }

    public function column_resume( $item ) {
        $resume = (string) $item['resume'];
        if ( mb_strlen( $resume ) > 90 ) {
            $resume = mb_substr( $resume, 0, 90 ) . '…';
        }
        return esc_html( $resume );
    }

    /** Colonne « Questions » : combien de questions porte ce scénario. */
    public function column_nb_questions( $item ) {
        $nb = (int) $item['nb_questions'];

        if ( $nb === 0 ) {
            return '<span style="color:#d63638">0</span>';
        }
        return $nb;
    }

    /**
     * Colonne « Origine » : le scénario vient-il de l'import ou a-t-il été
     * créé à la main ? C'est important : l'import ne touche QUE les scénarios
     * importés (identifiés par leur ref_externe). Ceux créés en admin sont
     * préservés.
     */
    public function column_ref_externe( $item ) {
        if ( ! empty( $item['ref_externe'] ) ) {
            return '<span title="' . esc_attr( $item['ref_externe'] ) . '" '
                 . 'style="color:#646970">Importé</span>';
        }
        return '<span style="color:#00a32a">Créé ici</span>';
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
    /**
     * Barre de filtres au-dessus du tableau : sélection de la certification,
     * et bouton de réinitialisation quand un filtre ou une recherche est actif.
     *
     * Le filtre est un simple paramètre d'URL (npq_certif) : il se combine
     * naturellement avec la recherche, le tri et la pagination de WP_List_Table.
     */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        $certifications = NPQ_Certification::toutes();

        // Inutile de proposer un filtre s'il n'y a qu'une seule certification.
        $afficher_filtre = ( count( $certifications ) > 1 );
        ?>
        <div class="alignleft actions">
            <?php if ( $afficher_filtre ) : ?>
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
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-scenarios' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Message quand il n'y a rien à afficher. */
    public function no_items() {
        echo 'Aucun scénario. Lancez l\'import ou créez-en un.';
    }

    /**
     * Prépare les données : recherche, tri, pagination.
     */
    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Certification active : sert à signaler la ligne correspondante.
        $this->active_id = NPQ_Certification::id();

        $par_page = 20;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        // Recherche.
        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        // Filtre certification (0 = toutes, le défaut).
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Tri (avec liste blanche : on n'injecte jamais une colonne venue de l'URL).
        $tri_autorise = [ 'nom' => 's.nom', 'nb_questions' => 'nb_questions' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'nom';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 's.nom';

        $order = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' )
               ? 'DESC' : 'ASC';

        // Clauses de filtrage.
        $where = '1=1';
        $args  = [];

        if ( $recherche !== '' ) {
            $like  = '%' . $wpdb->esc_like( $recherche ) . '%';
            $where .= ' AND ( s.nom LIKE %s OR s.resume LIKE %s OR s.contexte LIKE %s )';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        if ( $certif_filtre > 0 ) {
            $where .= ' AND s.certification_id = %d';
            $args[] = $certif_filtre;
        }

        // Total (pour la pagination).
        $sql_total = "SELECT COUNT(*) FROM {$p}scenario s WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        // Les scénarios, avec le nombre de questions et le code de certification.
        $sql = "SELECT s.id, s.nom, s.resume, s.ref_externe, s.statut,
                       s.certification_id,
                       c.code AS certification_code,
                       COUNT(q.id) AS nb_questions
                FROM {$p}scenario s
                LEFT JOIN {$p}question q
                       ON q.scenario_id = s.id AND q.statut = 'publie'
                LEFT JOIN {$p}certification c
                       ON c.id = s.certification_id
                WHERE {$where}
                GROUP BY s.id, s.nom, s.resume, s.ref_externe, s.statut,
                         s.certification_id, c.code
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
