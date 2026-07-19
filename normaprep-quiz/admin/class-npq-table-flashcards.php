<?php
/**
 * Liste des flashcards en administration.
 *
 * Une flashcard est une carte de mémorisation : recto (la question), verso (la
 * réponse). Contrairement aux questions d'examen, elle n'est rattachée à aucun
 * scénario — c'est une carte générale, taillée pour retenir la norme.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class NPQ_Table_Flashcards extends WP_List_Table {

    /** Id de la certification active, résolu une fois pour tout le rendu. */
    private $active_id = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'flashcard',
            'plural'   => 'flashcards',
            'ajax'     => false,
        ] );
    }

    public function get_columns() {
        return [
            'recto'         => 'Recto (la question)',
            'verso'         => 'Verso (la réponse)',
            'certification' => 'Certification',
            'domaine'       => 'Domaine',
            'statut'        => 'Statut',
        ];
    }

    public function get_sortable_columns() {
        return [
            'domaine' => [ 'domaine', false ],
        ];
    }

    /**
     * Colonne « Certification » : à quelle certification appartient la carte.
     * Un point vert signale la certification active.
     */
    public function column_certification( $item ) {
        $code = (string) $item['certification_code'];

        if ( $code === '' ) {
            return '<span style="color:#b32d2e" title="Carte rattachée à aucune certification">—</span>';
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

    /** Colonne « Recto » : la question, avec les actions. */
    public function column_recto( $item ) {
        $id = (int) $item['id'];

        $recto = (string) $item['recto'];
        if ( mb_strlen( $recto ) > 90 ) {
            $recto = mb_substr( $recto, 0, 90 ) . '…';
        }

        $url_modifier = add_query_arg(
            [
                'page'    => 'normaprep-flashcards',
                'npq_vue' => 'form',
                'id'      => $id,
            ],
            admin_url( 'admin.php' )
        );

        $url_supprimer = wp_nonce_url(
            add_query_arg(
                [
                    'page'       => 'normaprep-flashcards',
                    'npq_action' => 'supprimer_flashcard',
                    'id'         => $id,
                ],
                admin_url( 'admin.php' )
            ),
            'npq_supprimer_flashcard_' . $id
        );

        $titre = '<strong><a href="' . esc_url( $url_modifier ) . '">'
               . esc_html( $recto ) . '</a></strong>';

        $actions = [
            'modifier'  => '<a href="' . esc_url( $url_modifier ) . '">Modifier</a>',
            'supprimer' => '<a href="' . esc_url( $url_supprimer ) . '" style="color:#b32d2e"'
                         . ' onclick="return confirm(\'Supprimer définitivement cette carte ?\');">'
                         . 'Supprimer</a>',
        ];

        return $titre . $this->row_actions( $actions );
    }

    /** Colonne « Verso » : la réponse, tronquée. */
    public function column_verso( $item ) {
        $verso = (string) $item['verso'];
        if ( mb_strlen( $verso ) > 110 ) {
            $verso = mb_substr( $verso, 0, 110 ) . '…';
        }
        return '<span style="color:#646970">' . esc_html( $verso ) . '</span>';
    }

    public function column_domaine( $item ) {
        $code = esc_html( $item['domaine'] );
        $lib  = esc_html( $item['domaine_libelle'] ?? '' );

        if ( $lib === '' ) {
            return '<strong>' . $code . '</strong>';
        }
        return '<strong>' . $code . '</strong><br>'
             . '<span style="color:#646970;font-size:12px">' . $lib . '</span>';
    }

    public function column_statut( $item ) {
        if ( $item['statut'] === 'publie' ) {
            return '<span style="color:#00a32a">Publiée</span>';
        }
        return '<span style="color:#646970">Brouillon</span>';
    }

    public function no_items() {
        echo 'Aucune flashcard. Créez-en une pour commencer.';
    }

    /** Filtre par domaine, avec bouton de réinitialisation. */
    protected function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $domaine_actif = isset( $_REQUEST['npq_domaine'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['npq_domaine'] ) ) : '';
        $recherche     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        $filtre_actif = ( $domaine_actif !== '' ) || ( $recherche !== '' ) || ( $certif_filtre > 0 );

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

            <?php submit_button( 'Filtrer', '', 'filtrer', false ); ?>

            <?php if ( $filtre_actif ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=normaprep-flashcards' ) ); ?>"
                   class="button">Réinitialiser</a>
            <?php endif; ?>
        </div>
        <?php
    }

    public function prepare_items() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $par_page = 20;
        $page     = $this->get_pagenum();
        $offset   = ( $page - 1 ) * $par_page;

        // Certification active : sert à signaler la ligne correspondante.
        $this->active_id = NPQ_Certification::id();

        $recherche = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $domaine   = isset( $_REQUEST['npq_domaine'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['npq_domaine'] ) ) : '';
        $certif_filtre = isset( $_REQUEST['npq_certif'] ) ? (int) $_REQUEST['npq_certif'] : 0;

        // Tri : liste blanche stricte.
        $tri_autorise = [ 'domaine' => 'f.domaine' ];
        $orderby_brut = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'domaine';
        $orderby      = isset( $tri_autorise[ $orderby_brut ] ) ? $tri_autorise[ $orderby_brut ] : 'f.domaine';
        $order        = ( isset( $_REQUEST['order'] ) && strtolower( $_REQUEST['order'] ) === 'desc' ) ? 'DESC' : 'ASC';

        $where = '1=1';
        $args  = [];

        if ( $recherche !== '' ) {
            $like   = '%' . $wpdb->esc_like( $recherche ) . '%';
            $where .= ' AND ( f.recto LIKE %s OR f.verso LIKE %s )';
            $args[] = $like;
            $args[] = $like;
        }

        if ( $domaine !== '' ) {
            $where .= ' AND f.domaine = %s';
            $args[] = $domaine;
        }

        if ( $certif_filtre > 0 ) {
            $where .= ' AND f.certification_id = %d';
            $args[] = $certif_filtre;
        }

        $sql_total = "SELECT COUNT(*) FROM {$p}flashcard f WHERE {$where}";
        $total = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql_total, $args ) )
            : (int) $wpdb->get_var( $sql_total );

        $sql = "SELECT f.id, f.recto, f.verso, f.domaine, f.statut,
                       f.certification_id,
                       c.code AS certification_code,
                       d.libelle AS domaine_libelle
                FROM {$p}flashcard f
                LEFT JOIN {$p}domaine d
                       ON d.code = f.domaine
                      AND d.certification_id = f.certification_id
                LEFT JOIN {$p}certification c ON c.id = f.certification_id
                WHERE {$where}
                ORDER BY {$orderby} {$order}, f.id ASC
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

    /** Domaines, avec le nombre de flashcards de chacun. */
    private static function domaines() {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results(
            "SELECT d.code, d.libelle, COUNT(f.id) AS nb
             FROM {$p}domaine d
             LEFT JOIN {$p}flashcard f
                    ON f.domaine = d.code
                   AND f.certification_id = d.certification_id
                   AND f.statut = 'publie'
             GROUP BY d.code, d.libelle
             ORDER BY d.code ASC",
            ARRAY_A
        );
    }
}
