<?php
/**
 * Branchement WooCommerce : vendre l'accès à une certification.
 *
 * PRINCIPE
 *
 * La bibliothèque est le registre des droits. WooCommerce n'en est qu'une
 * SOURCE D'ÉCRITURE — au même titre qu'une attribution manuelle en
 * administration.
 *
 * Aucun code de lecture n'interroge jamais WooCommerce pour savoir si
 * quelqu'un a accès. C'est essentiel : sinon une panne, une migration ou un
 * changement de solution de paiement couperait l'accès de tout le monde. Tout
 * ce que ce fichier fait, c'est appeler NPQ_Bibliotheque au bon moment.
 *
 * On peut donc désactiver WooCommerce sans que personne ne perde ses droits.
 *
 * COMMENT ON RATTACHE UN PRODUIT À UNE CERTIFICATION
 *
 * Deux champs sur la fiche produit, onglet « Général » :
 *   - la certification vendue ;
 *   - la durée d'accès en mois (0 = accès définitif).
 *
 * Un produit sans certification n'est pas concerné : la boutique peut vendre
 * autre chose sans que ce module s'en mêle.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_WooCommerce {

    /** Méta produit : la certification vendue. */
    const META_CERTIFICATION = '_npq_certification_id';

    /** Méta produit : durée d'accès en mois (0 = définitif). */
    const META_DUREE = '_npq_duree_mois';

    /** Méta commande : témoin d'attribution, pour ne jamais l'exécuter deux fois. */
    const META_COMMANDE_TRAITEE = '_npq_acces_attribues';

    /**
     * Colonne de filtres rendue à l'ouverture de l'enveloppe.
     *
     * Mémorisée parce que la fermeture doit refermer exactement ce que
     * l'ouverture a ouvert : recalculer la condition en fin de page risquerait
     * de fermer une balise qui n'a jamais été ouverte, et de casser la mise en
     * page de tout ce qui suit.
     *
     * @var string
     */
    private static $colonne_filtres = '';

    /**
     * Les bandeaux sous l'en-tête ont-ils déjà été rendus sur cette page ?
     *
     * Deux points d'accroche peuvent y mener — celui du thème, et le repli
     * sur WooCommerce quand le thème n'est pas à jour. Sans ce témoin, un
     * thème à jour afficherait le fil d'Ariane deux fois sur le catalogue.
     *
     * @var bool
     */
    private static $bandeaux_rendus = false;

    /**
     * WooCommerce est-il actif ?
     * On ne teste pas le fichier du plugin mais la présence de sa classe :
     * c'est le seul indicateur fiable qu'il est réellement chargé.
     */
    public static function disponible() {
        return class_exists( 'WooCommerce' );
    }

    public static function init() {
        if ( ! self::disponible() ) {
            return;
        }

        // --- Fiche produit : rattachement à une certification ---
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'champs_produit' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'enregistrer_champs_produit' ] );

        // --- Cycle de vie de la commande ---
        // Deux statuts, parce que deux chemins mènent à un paiement acquis :
        // « terminée » pour un produit virtuel payé en ligne, « en cours »
        // pour un règlement que l'on valide à la main (virement, chèque).
        // L'attribution est protégée par un témoin : la déclencher deux fois
        // ne prolongerait pas l'accès par erreur.
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'attribuer_acces' ] );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'attribuer_acces' ] );

        // Remboursement ou annulation : on reprend ce qui a été donné.
        add_action( 'woocommerce_order_status_refunded',  [ __CLASS__, 'reprendre_acces' ] );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'reprendre_acces' ] );

        // --- Intégration visuelle au thème ---
        // Priorité 20 : après le after_setup_theme du thème (priorité 10 par
        // défaut), sans quoi current_theme_supports() répondrait avant que le
        // thème ait eu l'occasion de se déclarer.
        add_action( 'after_setup_theme', [ __CLASS__, 'passerelle_theme' ], 20 );

        // Habillage de la boutique. SÉPARÉ DE LA PASSERELLE À DESSEIN : la
        // passerelle est une rustine qui s'efface le jour où le thème déclare
        // son support de WooCommerce, alors que ces réglages-ci sont
        // l'intégration graphique elle-même. Les mélanger ferait disparaître
        // le fil d'Ariane et le fil d'étapes avec la rustine.
        add_action( 'after_setup_theme', [ __CLASS__, 'habillage_boutique' ], 20 );

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'charger_styles' ] );

        // Zone visuelle des produits : image du produit s'il en a une, motif
        // graphique généré sinon. Remplace le visuel de remplacement gris de
        // WooCommerce, qui ne ressemble à rien du reste du site.
        require_once NPQ_PATH . 'includes/class-npq-boutique-vignette.php';
        NPQ_Boutique_Vignette::init();
    }

    /* =====================================================================
     * INTÉGRATION AU THÈME
     * ===================================================================== */

    /**
     * Réglages d'habillage de la boutique.
     *
     * Ce que WooCommerce affiche de lui-même et qu'on remplace, et ce qu'on
     * ajoute par-dessus. Indépendant de la passerelle : ces choix valent que
     * le thème déclare ou non son support de WooCommerce.
     */
    public static function habillage_boutique() {
        // Le fil d'Ariane sort de l'enveloppe pour devenir une barre pleine
        // largeur, comme dans la maquette. WooCommerce l'affiche à la
        // priorité 20, donc APRÈS l'ouverture de nos conteneurs : il se
        // retrouvait enfermé dans la colonne des produits, à côté des filtres.
        remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );

        // « 4 résultats affichés » disait la même chose que le décompte de la
        // barre, deux fois sur le même écran. On garde celui de la barre : il
        // est à sa place dans la maquette, et discret. Sous le titre, la
        // maquette ne montre que le tri.
        remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );

        // La boutique manquait au fil d'Ariane des fiches produit.
        add_filter( 'woocommerce_get_breadcrumb', [ __CLASS__, 'fil_avec_boutique' ], 10, 2 );

        // Les bandeaux (fil d'Ariane, fil d'étapes) se posent sous l'en-tête,
        // par un point d'accroche du THÈME et non de WooCommerce.
        //
        // C'est ce qui les rend indépendants des gabarits : le panier et la
        // commande sont des pages WordPress ordinaires, et selon la version de
        // WooCommerce elles sont rendues par un shortcode ou par des blocs —
        // deux chemins qui ne déclenchent pas les mêmes actions, et parfois
        // aucune de celles qu'on attendait. Un point d'accroche du thème, lui,
        // est traversé par toutes les pages, quelle qu'en soit la fabrique.
        //
        // Le repli sur woocommerce_before_main_content garde les bandeaux
        // visibles sur le catalogue si le thème n'est pas encore à jour ; le
        // témoin de rendu empêche qu'ils sortent deux fois quand il l'est.
        add_action( 'carto_apres_entete', [ __CLASS__, 'bandeaux' ], 10 );
        add_action( 'woocommerce_before_main_content', [ __CLASS__, 'bandeaux' ], 5 );

        // Onglets du compte client.
        add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'onglets_compte' ] );
        add_action( 'template_redirect', [ __CLASS__, 'rediriger_edition_compte' ] );

        // Sorties de la page de confirmation.
        //
        // Par le contenu, et non par un point d'accroche de WooCommerce : la
        // confirmation existe elle aussi en deux versions, ancien gabarit et
        // bloc, qui n'exposent pas les mêmes actions. Le contenu de la page,
        // lui, est filtré dans les deux cas.
        add_filter( 'the_content', [ __CLASS__, 'sorties_confirmation' ], 20 );
    }

    /**
     * Onglets du compte client.
     *
     * Retire « Se déconnecter ». L'en-tête du site porte déjà ce lien, sur
     * toutes les pages : une action aussi fréquente doit se trouver toujours
     * au même endroit, pas à deux endroits dont l'un n'existe que sur
     * certains écrans. Le doublon n'ajoutait rien et occupait un onglet.
     *
     * Le point de sortie de WooCommerce reste actif : seule sa présence dans
     * ce menu disparaît.
     *
     * @param array $onglets
     * @return array
     */
    public static function onglets_compte( $onglets ) {
        unset( $onglets['customer-logout'] );

        // « Détails du compte » cède la place à « Mon profil ».
        //
        // Les deux écrans changent le mot de passe et l'adresse email du même
        // compte. Deux formulaires pour une même donnée posent une question
        // sans réponse : lequel fait foi ? Et celui de WooCommerce ignore les
        // règles de NormaPrep — il applique un changement d'adresse sans la
        // faire confirmer, alors que NPQ_Profil n'y touche qu'après clic sur
        // un lien envoyé à la NOUVELLE adresse. Une faute de frappe rend le
        // compte injoignable chez l'un, sans effet chez l'autre.
        //
        // On ne la remplace pas par un lien : « Mon profil » vit déjà dans la
        // barre latérale de l'espace, qui est la navigation du site. Ces
        // onglets-ci disent où l'on est dans le COMPTE marchand, et l'identité
        // n'en fait pas partie.
        unset( $onglets['edit-account'] );

        return $onglets;
    }

    /**
     * Renvoie « Mon profil » vers la page de NormaPrep.
     *
     * Retirer l'entrée du menu ne suffit pas : l'adresse
     * /mon-compte/edit-account/ reste atteignable, par un marque-page ou un
     * lien d'email. Une page qu'on a désignée comme n'étant plus la bonne ne
     * doit pas continuer de répondre.
     */
    public static function rediriger_edition_compte() {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
            return;
        }

        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-account' ) ) {
            return;
        }

        $page_profil = get_option( 'npq_page_profil_id' );
        if ( ! $page_profil ) {
            return; // sans page de profil, mieux vaut l'écran de WooCommerce que rien
        }

        wp_safe_redirect( get_permalink( $page_profil ) );
        exit;
    }

    /**
     * Ajoute les sorties sous la confirmation de commande.
     *
     * La page dit « merci » et s'arrête là : rien n'indique où aller
     * ensuite, et l'on reste sur un cul-de-sac au moment précis où l'on
     * vient de payer.
     *
     * Deux directions, dans cet ordre :
     *
     *   1. L'ESPACE MEMBRE, en action principale. C'est là que se trouve ce
     *      qu'on vient d'acheter. Quelqu'un qui achète un accès à une
     *      certification veut s'en servir, pas retourner faire les courses.
     *   2. La boutique, en second.
     *
     * L'espace n'est proposé que si la commande a RÉELLEMENT ouvert des
     * accès. Un règlement par virement laisse la commande « en attente » :
     * l'accès n'existe pas encore, et envoyer vers un espace vide serait
     * pire que ne rien proposer. Dans ce cas on le dit, plutôt que de
     * laisser chercher.
     *
     * @param string $contenu
     * @return string
     */
    public static function sorties_confirmation( $contenu ) {
        if ( ! is_order_received_page() || ! in_the_loop() || ! is_main_query() ) {
            return $contenu;
        }

        $order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
        if ( ! $order ) {
            return $contenu;
        }

        $boutons = '';
        $note    = '';

        $page_boutique = wc_get_page_id( 'shop' );
        $url_boutique  = $page_boutique > 0 ? get_permalink( $page_boutique ) : home_url( '/' );

        $page_espace = class_exists( 'NPQ_Espace' ) ? get_option( NPQ_Espace::OPT_PAGE_ESPACE ) : 0;
        $url_espace  = $page_espace ? get_permalink( $page_espace ) : '';

        $acces_ouverts = (bool) $order->get_meta( self::META_COMMANDE_TRAITEE );
        $a_du_normaprep = ! empty( self::lignes_normaprep( $order ) );

        if ( $url_espace && $acces_ouverts && $order->get_user_id() ) {
            $boutons .= '<a class="npq-sortie" href="' . esc_url( $url_espace ) . '">Accéder à mon espace</a>';
            $boutons .= '<a class="npq-sortie npq-sortie--secondaire" href="' . esc_url( $url_boutique ) . '">Retour à la boutique</a>';
        } else {
            $boutons .= '<a class="npq-sortie" href="' . esc_url( $url_boutique ) . '">Retour à la boutique</a>';

            if ( $url_espace && $order->get_user_id() ) {
                $boutons .= '<a class="npq-sortie npq-sortie--secondaire" href="' . esc_url( $url_espace ) . '">Mon espace</a>';
            }

            // On n'annonce l'attente que s'il y a bien un accès à attendre.
            if ( $a_du_normaprep && ! $acces_ouverts ) {
                $note = 'Votre accès s\'ouvrira dès la validation du paiement. '
                      . 'Vous le retrouverez ensuite dans votre espace.';
            }
        }

        $html = '<div class="npq-sorties">' . $boutons
              . ( $note ? '<p class="npq-sorties__note">' . esc_html( $note ) . '</p>' : '' )
              . '</div>';

        return $contenu . $html;
    }

    /**
     * Fait entrer les pages boutique dans la mise en page du thème.
     *
     * Le thème CARTO ne déclare pas add_theme_support( 'woocommerce' ).
     * WooCommerce enveloppe donc son contenu dans son balisage par défaut
     * (#primary, #main) — des identifiants que CARTO n'utilise pas : il
     * travaille avec .carto-wrap. Résultat, les pages boutique s'affichaient
     * hors de la grille du thème, sans ses marges ni sa largeur.
     *
     * On déclare le support manquant et on remplace ces enveloppes par celles
     * du thème, reprises de son page.php.
     *
     * LE JOUR OÙ LE THÈME S'EN CHARGERA, CE CODE S'EFFACE : si
     * current_theme_supports('woocommerce') est déjà vrai, on ne touche à
     * rien. Une rustine doit savoir se retirer quand le mur est réparé.
     */
    public static function passerelle_theme() {
        if ( current_theme_supports( 'woocommerce' ) ) {
            return;
        }

        add_theme_support( 'woocommerce' );

        remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
        remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

        add_action( 'woocommerce_before_main_content', [ __CLASS__, 'ouvrir_enveloppe' ], 10 );
        add_action( 'woocommerce_after_main_content', [ __CLASS__, 'fermer_enveloppe' ], 10 );


        // CARTO n'a pas de barre latérale. WooCommerce en réclame une sur
        // plusieurs de ses gabarits ; sans ce retrait, on demande au thème un
        // fichier qui n'existe pas.
        remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
    }

    /**
     * Ouvre l'enveloppe de page du thème (reprise de son page.php).
     *
     * Sur les pages de catalogue, l'enveloppe se dédouble en deux colonnes :
     * les filtres à gauche, les produits à droite. Ailleurs — fiche produit,
     * panier, commande — le contenu prend toute la largeur.
     */
    public static function ouvrir_enveloppe() {
        echo '<section class="npq-boutique"><div class="carto-wrap">';

        self::$colonne_filtres = self::rendu_filtres();

        if ( '' !== self::$colonne_filtres ) {
            echo '<div class="npq-boutique-grille">';
            echo self::$colonne_filtres;
            echo '<div class="npq-boutique-colonne">';
        }
    }

    /** Ferme l'enveloppe de page du thème. */
    public static function fermer_enveloppe() {
        if ( '' !== self::$colonne_filtres ) {
            echo '</div></div>';
        }

        echo '</div></section>';
    }

    /**
     * Les bandeaux pleine largeur, sous l'en-tête.
     *
     * Une seule sortie par page : deux points d'accroche y mènent, et le
     * premier arrivé ferme la porte derrière lui.
     */
    public static function bandeaux() {
        if ( self::$bandeaux_rendus ) {
            return;
        }

        if ( ! function_exists( 'is_woocommerce' ) ) {
            return;
        }

        // Toutes les pages de la boutique, tunnel d'achat et compte client
        // compris : c'est là que le fil d'Ariane du thème s'efface au profit
        // de celui-ci, et il ne doit pas laisser de trou.
        $sur_boutique = is_woocommerce() || is_cart() || is_checkout() || is_account_page();
        if ( ! $sur_boutique ) {
            return;
        }

        self::$bandeaux_rendus = true;

        self::barre_fil();
        self::fil_etapes();
    }

    /**
     * Fil d'étapes du tunnel d'achat.
     *
     * Trois écrans séparent le panier de l'accès ouvert. Sans repère, on ne
     * sait ni combien il en reste, ni si valider le panier déclenche déjà le
     * paiement — l'incertitude qui fait abandonner un achat.
     *
     * Le fil est décoratif au sens propre : il n'ouvre aucun raccourci. On ne
     * saute pas à l'étape 3, et proposer des liens inertes serait pire que
     * n'en proposer aucun.
     */
    private static function fil_etapes() {
        $etapes = [
            [ '01', 'Panier' ],
            [ '02', 'Commande' ],
            [ '03', 'Confirmation' ],
        ];

        $courante = self::etape_courante();
        if ( $courante < 1 ) {
            return;
        }

        echo '<div class="npq-tunnel"><div class="carto-wrap"><ol class="npq-etapes">';

        foreach ( $etapes as $rang => $etape ) {
            $numero = $rang + 1;

            $classe = 'npq-etape';
            if ( $numero < $courante ) {
                $classe .= ' est-faite';
            } elseif ( $numero === $courante ) {
                $classe .= ' est-active';
            }

            echo '<li class="' . esc_attr( $classe ) . '"'
               . ( $numero === $courante ? ' aria-current="step"' : '' ) . '>'
               . '<span class="npq-etape__n">' . esc_html( $etape[0] ) . '</span>'
               . '<span class="npq-etape__lbl">' . esc_html( $etape[1] ) . '</span>'
               . '</li>';
        }

        echo '</ol></div></div>';
    }

    /**
     * Rang de l'étape en cours, 0 si l'on n'est pas dans le tunnel.
     *
     * L'ordre des tests compte : is_checkout() reste vrai sur la page de
     * remerciement, qui est techniquement une étape de la commande. On teste
     * donc la fin avant le milieu.
     */
    private static function etape_courante() {
        if ( is_order_received_page() ) {
            return 3;
        }
        if ( is_checkout() ) {
            return 2;
        }
        if ( is_cart() ) {
            return 1;
        }
        return 0;
    }

    /**
     * Insère la boutique dans le fil d'Ariane, après l'accueil.
     *
     * WooCommerce ne l'y met que si la base des permaliens produit contient
     * le nom de la page boutique — autrement dit si les adresses ressemblent
     * à /boutique/mon-produit/ et non à /produit/mon-produit/. C'est une
     * condition sur la FORME DES ADRESSES, alors que la question posée est
     * celle de la place du produit dans le site. Les deux n'ont pas de raison
     * d'être liées : un produit appartient à la boutique quelle que soit son
     * adresse.
     *
     * On ajoute donc l'échelon manquant, sans toucher aux permaliens — les
     * changer réécrirait toutes les adresses de produits déjà en ligne.
     *
     * @param array $crumbs Échelons : [ libellé, url ].
     * @param WC_Breadcrumb $breadcrumb
     * @return array
     */
    public static function fil_avec_boutique( $crumbs, $breadcrumb = null ) {
        if ( empty( $crumbs ) || ! ( is_product() || is_product_taxonomy() ) ) {
            return $crumbs;
        }

        $page_boutique = wc_get_page_id( 'shop' );
        if ( $page_boutique < 1 ) {
            return $crumbs;
        }

        // Boutique en page d'accueil : l'accueil EST déjà la boutique, et
        // l'ajouter écrirait deux fois le même échelon sous deux noms.
        if ( (int) get_option( 'page_on_front' ) === $page_boutique ) {
            return $crumbs;
        }

        $url = get_permalink( $page_boutique );

        // Déjà présente ? C'est le cas si les permaliens sont réglés sur la
        // base boutique : WooCommerce l'a alors mise lui-même.
        foreach ( $crumbs as $echelon ) {
            if ( ! empty( $echelon[1] ) && untrailingslashit( $echelon[1] ) === untrailingslashit( $url ) ) {
                return $crumbs;
            }
        }

        // Position 1 : juste après l'accueil, avant les catégories.
        array_splice( $crumbs, 1, 0, [ [ get_the_title( $page_boutique ), $url ] ] );

        return $crumbs;
    }

    /**
     * Barre de fil d'Ariane, sous l'en-tête.
     *
     * Deux informations, aux deux bouts : où l'on se trouve, à gauche ; ce
     * que la page contient, à droite. La maquette y met un état de stock —
     * sans objet ici, où l'on vend un accès à une plateforme et non des
     * pièces en réserve. La place revient donc à ce qui compte vraiment
     * selon la page : un décompte de produits, ou une référence.
     */
    private static function barre_fil() {
        // Le fil est bufferisé : WooCommerce n'affiche rien sur certaines
        // pages, et une barre vide vaudrait moins que pas de barre du tout.
        ob_start();
        woocommerce_breadcrumb();
        $fil = trim( ob_get_clean() );

        $etat = self::etat_page();

        if ( '' === $fil && '' === $etat ) {
            return;
        }

        echo '<div class="npq-fil"><div class="carto-wrap npq-fil__inner">';
        echo $fil;

        if ( '' !== $etat ) {
            echo '<span class="npq-fil__etat">' . esc_html( $etat ) . '</span>';
        }

        echo '</div></div>';
    }

    /**
     * Ce que la page contient, en bout de barre.
     *
     * @return string Chaîne vide quand il n'y a rien d'utile à dire — la
     *                barre reste alors muette plutôt que de meubler.
     */
    private static function etat_page() {
        if ( is_product() ) {
            require_once NPQ_PATH . 'includes/class-npq-boutique-vignette.php';
            $product = wc_get_product( get_queried_object_id() );
            return $product ? '// ' . NPQ_Boutique_Vignette::reference( $product ) : '';
        }

        if ( is_cart() ) {
            $n = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
            return sprintf( _n( '%s article', '%s articles', $n, 'normaprep-quiz' ), number_format_i18n( $n ) );
        }

        if ( is_shop() || is_product_taxonomy() ) {
            $total = isset( $GLOBALS['wp_query'] ) ? (int) $GLOBALS['wp_query']->found_posts : 0;

            $etat = sprintf( _n( '%s produit', '%s produits', $total, 'normaprep-quiz' ), number_format_i18n( $total ) );

            // Le nom du rayon complète le décompte : « 3 produits » seul ne
            // dit pas de quoi, quand on arrive par un lien de filtre.
            if ( is_product_taxonomy() ) {
                $terme = get_queried_object();
                if ( $terme instanceof WP_Term ) {
                    $etat .= ' · ' . $terme->name;
                }
            }

            return $etat;
        }

        return '';
    }

    /**
     * La colonne de filtres, si la page en attend une.
     *
     * Chargée à la demande : sur une fiche produit ou dans le tunnel d'achat,
     * ce fichier n'a aucune raison d'être lu.
     *
     * @return string HTML, chaîne vide s'il n'y a rien à filtrer.
     */
    private static function rendu_filtres() {
        require_once NPQ_PATH . 'includes/class-npq-boutique-filtres.php';

        if ( ! NPQ_Boutique_Filtres::sur_catalogue() ) {
            return '';
        }

        return NPQ_Boutique_Filtres::rendu();
    }

    /**
     * Charge la feuille de style de la boutique, uniquement sur ses pages.
     *
     * is_woocommerce() ne couvre QUE la boutique et les fiches produit : le
     * panier, la commande et le compte client lui échappent. On les ajoute
     * explicitement, sinon le tunnel d'achat — l'endroit où l'apparence
     * compte le plus — resterait au style par défaut.
     */
    public static function charger_styles() {
        if ( ! function_exists( 'is_woocommerce' ) ) {
            return;
        }

        $sur_boutique = is_woocommerce() || is_cart() || is_checkout() || is_account_page();
        if ( ! $sur_boutique ) {
            return;
        }

        // Le composant de menu déroulant, partagé avec l'espace membre. Il
        // est déclaré AVANT la feuille de la boutique, qui en dépend : c'est
        // cette dépendance qui garantit l'ordre de chargement, et donc que
        // les réglages de la boutique aient le dernier mot.
        wp_enqueue_style(
            'npq-select',
            NPQ_URL . 'assets/npq-select.css',
            [],
            NPQ_VERSION
        );

        wp_enqueue_script(
            'npq-select',
            NPQ_URL . 'assets/npq-select.js',
            [],           // aucune dépendance : vanilla JS
            NPQ_VERSION,
            true          // dans le pied de page
        );

        wp_enqueue_style(
            'npq-boutique',
            NPQ_URL . 'assets/npq-boutique.css',
            [ 'npq-select' ],
            NPQ_VERSION
        );
    }

    /* =====================================================================
     * FICHE PRODUIT
     * ===================================================================== */

    /**
     * Ajoute les deux champs à l'onglet « Général » de la fiche produit.
     */
    public static function champs_produit() {
        $certifications = class_exists( 'NPQ_Certification' ) ? NPQ_Certification::toutes() : [];

        $options = [ '' => '— Aucune (produit hors NormaPrep) —' ];
        foreach ( $certifications as $c ) {
            $options[ (int) $c['id'] ] = $c['code'] . ' — ' . $c['nom'];
        }

        echo '<div class="options_group">';

        woocommerce_wp_select( [
            'id'          => self::META_CERTIFICATION,
            'label'       => 'Certification NormaPrep',
            'options'     => $options,
            'desc_tip'    => true,
            'description' => "L'achat de ce produit ouvrira l'accès à cette certification. "
                           . "Laissez vide pour un produit sans rapport avec NormaPrep.",
        ] );

        woocommerce_wp_text_input( [
            'id'                => self::META_DUREE,
            'label'             => "Durée d'accès (mois)",
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
            'desc_tip'          => true,
            'description'       => "Nombre de mois d'accès. Mettez 0 pour un accès définitif. "
                                 . "Un rachat avant l'échéance prolonge l'accès au lieu de le remplacer.",
        ] );

        echo '</div>';
    }

    /**
     * Enregistre les deux champs à la sauvegarde du produit.
     *
     * @param int $product_id
     */
    public static function enregistrer_champs_produit( $product_id ) {
        // WooCommerce a déjà vérifié le nonce et les droits avant d'appeler ce
        // hook ; on se contente d'assainir ce qu'on écrit.
        $certification_id = isset( $_POST[ self::META_CERTIFICATION ] )
            ? (int) $_POST[ self::META_CERTIFICATION ]
            : 0;

        $duree = isset( $_POST[ self::META_DUREE ] )
            ? max( 0, (int) $_POST[ self::META_DUREE ] )
            : 0;

        if ( $certification_id > 0 ) {
            update_post_meta( $product_id, self::META_CERTIFICATION, $certification_id );
            update_post_meta( $product_id, self::META_DUREE, $duree );
        } else {
            // Produit détaché de NormaPrep : on nettoie plutôt que de laisser
            // traîner une certification fantôme.
            delete_post_meta( $product_id, self::META_CERTIFICATION );
            delete_post_meta( $product_id, self::META_DUREE );
        }
    }

    /* =====================================================================
     * ATTRIBUTION
     * ===================================================================== */

    /**
     * Ouvre les accès achetés dans une commande.
     *
     * @param int $order_id
     */
    public static function attribuer_acces( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Témoin : une commande n'ouvre ses accès qu'une fois. Sans lui, un
        // passage « en cours » puis « terminée » prolongerait l'accès deux fois.
        if ( $order->get_meta( self::META_COMMANDE_TRAITEE ) ) {
            return;
        }

        $lignes = self::lignes_normaprep( $order );
        if ( empty( $lignes ) ) {
            return; // commande sans produit NormaPrep : rien à faire
        }

        // Il faut un compte : un accès se rattache à une personne, et une
        // commande passée en visiteur n'en désigne aucune.
        $wp_user_id = (int) $order->get_user_id();
        if ( ! $wp_user_id ) {
            $order->add_order_note(
                'NormaPrep : accès NON attribué — commande passée sans compte client. '
                . 'Rattachez la commande à un utilisateur, puis repassez-la en « Terminée ».'
            );
            $order->save();
            return;
        }

        $utilisateur_id = self::fiche_normaprep( $wp_user_id );
        if ( ! $utilisateur_id ) {
            $order->add_order_note(
                'NormaPrep : accès NON attribué — impossible de créer la fiche NormaPrep '
                . 'du client (utilisateur WordPress introuvable).'
            );
            $order->save();
            return;
        }

        $resume = [];
        foreach ( $lignes as $ligne ) {
            $fin = NPQ_Bibliotheque::prolonger(
                $utilisateur_id,
                $ligne['certification_id'],
                $ligne['duree']
            );

            $resume[] = sprintf(
                '%s : accès %s',
                $ligne['nom'],
                $fin ? 'jusqu\'au ' . date_i18n( 'd/m/Y', strtotime( $fin ) ) : 'définitif'
            );
        }

        // Trace dans la commande : en cas de litige, l'historique de la
        // commande doit dire ce qui a été ouvert, et quand.
        $order->add_order_note( "NormaPrep — accès ouverts :\n" . implode( "\n", $resume ) );
        $order->update_meta_data( self::META_COMMANDE_TRAITEE, current_time( 'mysql' ) );
        $order->save();
    }

    /**
     * Reprend les accès d'une commande remboursée ou annulée.
     *
     * On retire l'accès plutôt que de l'écourter : la commande étant annulée,
     * le droit qu'elle a ouvert n'a plus de fondement. Un client qui détenait
     * déjà la certification par un autre achat la perd aussi — cas assez rare
     * pour être traité à la main, et signalé dans la note de commande.
     *
     * @param int $order_id
     */
    public static function reprendre_acces( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_meta( self::META_COMMANDE_TRAITEE ) ) {
            return; // rien n'avait été ouvert
        }

        $wp_user_id = (int) $order->get_user_id();
        $utilisateur_id = $wp_user_id ? self::fiche_normaprep( $wp_user_id, false ) : 0;
        if ( ! $utilisateur_id ) {
            return;
        }

        $resume = [];
        foreach ( self::lignes_normaprep( $order ) as $ligne ) {
            NPQ_Bibliotheque::retirer( $utilisateur_id, $ligne['certification_id'] );
            $resume[] = $ligne['nom'];
        }

        if ( ! empty( $resume ) ) {
            $order->add_order_note(
                "NormaPrep — accès repris : " . implode( ', ', $resume )
                . ". Si ce client détenait l'une de ces certifications par un autre achat, "
                . "réattribuez-la depuis NormaPrep → Accès."
            );
        }

        // Le témoin est levé : si la commande repasse en « Terminée », les
        // accès seront réouverts.
        $order->delete_meta_data( self::META_COMMANDE_TRAITEE );
        $order->save();
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /**
     * Lignes de commande rattachées à une certification.
     *
     * @param WC_Order $order
     * @return array Lignes : certification_id, duree, nom.
     */
    private static function lignes_normaprep( $order ) {
        $lignes = [];

        foreach ( $order->get_items() as $item ) {
            $product_id = (int) $item->get_product_id();
            if ( ! $product_id ) {
                continue;
            }

            $certification_id = (int) get_post_meta( $product_id, self::META_CERTIFICATION, true );
            if ( $certification_id < 1 ) {
                continue;
            }

            $lignes[] = [
                'certification_id' => $certification_id,
                'duree'            => (int) get_post_meta( $product_id, self::META_DUREE, true ),
                'nom'              => $item->get_name(),
            ];
        }

        return $lignes;
    }

    /**
     * Identifiant de la fiche métier NormaPrep d'un compte WordPress.
     *
     * Un client WooCommerce a le rôle « customer », pas « npq_abonne » — donc
     * ni la capacité de passer un examen, ni de fiche métier. On les lui donne
     * ici, à l'achat.
     *
     * add_role() et non set_role() : on AJOUTE le rôle NormaPrep sans retirer
     * « customer », dont WooCommerce a besoin pour son propre espace client.
     *
     * @param int  $wp_user_id
     * @param bool $creer  Faux pour une simple lecture (reprise d'accès).
     * @return int 0 si la fiche n'existe pas et n'a pas pu être créée.
     */
    private static function fiche_normaprep( $wp_user_id, $creer = true ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}utilisateur WHERE wp_user_id = %d",
            $wp_user_id
        ) );

        if ( $id || ! $creer ) {
            return $id;
        }

        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return 0;
        }

        if ( ! in_array( NPQ_Comptes::ROLE, (array) $user->roles, true ) ) {
            $user->add_role( NPQ_Comptes::ROLE );
        }

        // creer_fiche_metier() n'agit que sur un compte portant le rôle : le
        // rôle vient d'être ajouté, l'ordre est donc important.
        NPQ_Comptes::creer_fiche_metier( $wp_user_id );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}utilisateur WHERE wp_user_id = %d",
            $wp_user_id
        ) );
    }
}
