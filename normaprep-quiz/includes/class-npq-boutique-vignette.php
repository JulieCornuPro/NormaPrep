<?php
/**
 * Zone visuelle des produits de la boutique.
 *
 * LE PROBLÈME
 *
 * La maquette CARTO prévoit, en tête de chaque carte produit, une zone de
 * 160px : fond quadrillé sombre, trigramme géant en Syncopate, drapeau de
 * catégorie en haut à gauche, référence en bas à droite.
 *
 * WooCommerce, lui, y place l'image du produit — et à défaut son visuel de
 * remplacement, un cadre gris pâle qui jure avec tout le reste. Une boutique
 * qui vend des accès à des certifications n'a pas de photo à montrer : le
 * visuel de remplacement n'est donc pas un cas d'exception, c'est le cas
 * courant.
 *
 * LE CHOIX RETENU : LES DEUX
 *
 * On ne tranche pas entre « zone générée » et « vraies images » :
 *
 *   - le produit a une image  → on l'affiche, dans le cadre de la maquette ;
 *   - il n'en a pas           → on génère la zone graphique.
 *
 * Ainsi rien n'est jamais laid par défaut, et rien n'empêche d'ajouter une
 * image le jour où il y en a une à montrer. Le drapeau et la référence
 * restent posés dans les deux cas : c'est ce qui garde la grille homogène
 * quand seuls quelques produits ont une image.
 *
 * Le visuel de remplacement de WooCommerce, lui, ne s'affiche plus jamais.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Boutique_Vignette {

    /** Méta produit : trigramme affiché dans la zone visuelle. */
    const META_TRIGRAMME = '_npq_trigramme';

    /**
     * Les trois accents de la maquette.
     *
     * Le liseré haut de la carte et la couleur du drapeau les reprennent.
     * L'ordre compte : il sert de table de correspondance (voir accent()).
     */
    const ACCENTS = [ 'teal', 'amber', 'orange' ];

    public static function init() {
        // Le décrochage des gabarits attend « init » : WooCommerce pose ses
        // propres actions au chargement de son plugin, et on ne peut retirer
        // que ce qui est déjà en place. S'y prendre plus tôt échouerait en
        // silence — le pire des échecs, parce qu'il ne se voit qu'à l'écran.
        add_action( 'init', [ __CLASS__, 'decrocher_gabarits' ], 20 );

        // L'accent voyage sur la carte elle-même : c'est elle qui porte le
        // liseré haut de 3px, et le CSS ne peut pas le déduire du contenu.
        //
        // Deux filtres, parce que WooCommerce classe ses produits par deux
        // chemins différents : post_class dans les archives, et son propre
        // woocommerce_post_class sur la fiche. N'en brancher qu'un laisserait
        // la moitié des pages sans accent.
        add_filter( 'post_class', [ __CLASS__, 'classe_accent' ], 10, 3 );
        add_filter( 'woocommerce_post_class', [ __CLASS__, 'classe_accent_produit' ], 10, 2 );

        // Champ « trigramme » sur la fiche produit, dans son propre groupe.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'champ_produit' ], 20 );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'enregistrer_champ_produit' ] );
    }

    /** Remplace les vignettes de WooCommerce par les nôtres. */
    public static function decrocher_gabarits() {
        // Catalogue.
        remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
        add_action( 'woocommerce_before_shop_loop_item_title', [ __CLASS__, 'rendu_boucle' ], 10 );

        // Fiche produit : notre rendu rend la main à WooCommerce dès qu'il y a
        // de vraies images (galerie, zoom, lightbox).
        remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
        add_action( 'woocommerce_before_single_product_summary', [ __CLASS__, 'rendu_fiche' ], 20 );
    }

    /* =====================================================================
     * FICHE PRODUIT (ADMINISTRATION)
     * ===================================================================== */

    /**
     * Champ « trigramme » de l'onglet « Général ».
     *
     * Facultatif : vide, le trigramme se déduit du nom. Le champ n'existe que
     * pour les cas où la déduction tombe mal — « Pre-commit hook » donne PRE,
     * alors que HOK dirait mieux de quoi il s'agit.
     */
    public static function champ_produit() {
        echo '<div class="options_group">';

        woocommerce_wp_text_input( [
            'id'                => self::META_TRIGRAMME,
            'label'             => 'Trigramme (visuel)',
            'placeholder'       => 'déduit du nom',
            'desc_tip'          => true,
            'custom_attributes' => [ 'maxlength' => '3' ],
            'description'       => "Les trois lettres affichées en grand sur la vignette, quand le "
                                 . "produit n'a pas d'image. Laissez vide pour reprendre les trois "
                                 . "premières lettres du nom.",
        ] );

        echo '</div>';
    }

    /**
     * Enregistre le trigramme.
     *
     * @param int $product_id
     */
    public static function enregistrer_champ_produit( $product_id ) {
        // WooCommerce a déjà vérifié le nonce et les droits avant d'appeler ce
        // hook ; on se contente d'assainir ce qu'on écrit.
        $valeur = isset( $_POST[ self::META_TRIGRAMME ] )
            ? sanitize_text_field( wp_unslash( $_POST[ self::META_TRIGRAMME ] ) )
            : '';

        $valeur = mb_substr( trim( $valeur ), 0, 3 );

        if ( '' !== $valeur ) {
            update_post_meta( $product_id, self::META_TRIGRAMME, $valeur );
        } else {
            // Vidé, le champ doit redonner la main à la déduction : garder une
            // chaîne vide en base la court-circuiterait tout de même.
            delete_post_meta( $product_id, self::META_TRIGRAMME );
        }
    }

    /* =====================================================================
     * RENDUS
     * ===================================================================== */

    /** Zone visuelle d'une carte du catalogue. */
    public static function rendu_boucle() {
        global $product;
        echo self::zone( $product, 'boucle' );
    }

    /**
     * Zone visuelle de la fiche produit.
     *
     * Avec des images, on laisse WooCommerce faire : sa galerie apporte le
     * zoom, les miniatures et la lightbox, qu'il serait absurde de réécrire.
     */
    public static function rendu_fiche() {
        global $product;

        if ( self::a_une_image( $product ) ) {
            woocommerce_show_product_images();
            return;
        }

        echo self::zone( $product, 'fiche' );
    }

    /**
     * Construit la zone visuelle d'un produit.
     *
     * @param WC_Product $product
     * @param string     $format 'boucle' (160px) ou 'fiche' (pleine hauteur).
     * @return string
     */
    private static function zone( $product, $format ) {
        if ( ! $product instanceof WC_Product ) {
            return '';
        }

        $accent   = self::accent( $product );
        $drapeau  = self::drapeau( $product );
        $ref      = self::reference( $product );
        $avec_img = self::a_une_image( $product );

        $classes = 'npq-vignette npq-vignette--' . $format . ' npq-vignette--' . $accent;
        if ( $avec_img ) {
            $classes .= ' npq-vignette--image';
        }

        $html = '<div class="' . esc_attr( $classes ) . '">';

        if ( $avec_img ) {
            // get_image() passe par WooCommerce : tailles, srcset et attribut
            // alt restent les siens, on ne fait qu'encadrer le résultat.
            $html .= $product->get_image( 'woocommerce_thumbnail' );
        } else {
            // aria-hidden : le trigramme est un motif, pas une information.
            // Le nom du produit est juste en dessous, en toutes lettres.
            $html .= '<span class="npq-vignette__marque" aria-hidden="true">'
                   . esc_html( self::trigramme( $product ) ) . '</span>';
        }

        if ( '' !== $drapeau ) {
            $html .= '<span class="npq-vignette__drapeau">' . esc_html( $drapeau ) . '</span>';
        }

        if ( '' !== $ref ) {
            $html .= '<span class="npq-vignette__ref">// ' . esc_html( $ref ) . '</span>';
        }

        return $html . '</div>';
    }

    /**
     * Ajoute la classe d'accent à la carte produit.
     *
     * @param string[] $classes
     * @param string[] $classe_supplementaire
     * @param int      $post_id
     * @return string[]
     */
    public static function classe_accent( $classes, $classe_supplementaire = [], $post_id = 0 ) {
        if ( ! in_array( 'product', (array) $classes, true ) ) {
            return $classes;
        }

        $product = wc_get_product( $post_id );
        if ( $product instanceof WC_Product ) {
            $classes[] = 'npq-accent-' . self::accent( $product );
        }

        return $classes;
    }

    /**
     * Même chose, pour la fiche produit.
     *
     * @param string[]   $classes
     * @param WC_Product $product
     * @return string[]
     */
    public static function classe_accent_produit( $classes, $product = null ) {
        if ( $product instanceof WC_Product ) {
            $classes[] = 'npq-accent-' . self::accent( $product );
        }

        return $classes;
    }

    /* =====================================================================
     * DONNÉES DE LA ZONE
     * ===================================================================== */

    /**
     * Trigramme affiché en grand.
     *
     * Le champ de la fiche produit prime. Sans lui, on prend les trois
     * premières lettres du nom : « Secret Scanner » donne SEC, « Rotation
     * assistée » donne ROT. Le résultat est prévisible, ce qui compte plus
     * qu'être malin — un trigramme deviné autrement changerait au moindre
     * renommage.
     *
     * @param WC_Product $product
     */
    private static function trigramme( $product ) {
        $choisi = trim( (string) get_post_meta( $product->get_id(), self::META_TRIGRAMME, true ) );
        if ( '' !== $choisi ) {
            // remove_accents avant strtoupper : PHP ne sait pas mettre « é »
            // en capitale sans extension, et mb_strtoupper n'est pas garanti
            // (WordPress ne fournit un repli que pour mb_substr).
            return strtoupper( remove_accents( mb_substr( $choisi, 0, 3 ) ) );
        }

        // Les accents doivent tomber sur leur lettre de base : « Élévation »
        // donne ELE, pas un caractère de remplacement.
        $nom = remove_accents( $product->get_name() );
        $nom = preg_replace( '/[^A-Za-z0-9]/', '', $nom );

        return strtoupper( substr( $nom, 0, 3 ) );
    }

    /**
     * Drapeau de catégorie, en haut à gauche.
     *
     * La catégorie la plus profonde du produit : entre « Plateforme » et
     * « Détection de secrets », c'est la seconde qui dit quelque chose.
     *
     * @param WC_Product $product
     */
    private static function drapeau( $product ) {
        $termes = get_the_terms( $product->get_id(), 'product_cat' );
        if ( ! $termes || is_wp_error( $termes ) ) {
            return '';
        }

        $meilleur = null;
        foreach ( $termes as $t ) {
            if ( ! $meilleur || count( get_ancestors( $t->term_id, 'product_cat' ) )
                                > count( get_ancestors( $meilleur->term_id, 'product_cat' ) ) ) {
                $meilleur = $t;
            }
        }

        return $meilleur ? $meilleur->name : '';
    }

    /**
     * Référence, en bas à droite.
     *
     * L'UGS si elle est renseignée — c'est la référence que le client cite au
     * support. Sinon l'identifiant du produit, qui a le mérite d'exister
     * toujours et de désigner sans ambiguïté.
     *
     * Publique : la barre de fil d'Ariane l'affiche aussi, et deux façons de
     * calculer une même référence finiraient par diverger.
     *
     * @param WC_Product $product
     */
    public static function reference( $product ) {
        $sku = $product->get_sku();
        return ( '' !== $sku ) ? $sku : 'REF-' . $product->get_id();
    }

    /**
     * Couleur d'accent d'un produit.
     *
     * Elle suit la CATÉGORIE, pas le produit : dans une grille, deux articles
     * du même rayon doivent se répondre, et deux rayons se distinguer.
     *
     * La répartition se fait sur l'identifiant du terme, qui ne change jamais.
     * Une répartition par ordre alphabétique aurait recoloré la moitié de la
     * boutique à chaque catégorie ajoutée.
     *
     * @param WC_Product $product
     */
    private static function accent( $product ) {
        $termes = get_the_terms( $product->get_id(), 'product_cat' );

        if ( ! $termes || is_wp_error( $termes ) ) {
            return self::ACCENTS[0];
        }

        $premier = reset( $termes );
        return self::ACCENTS[ $premier->term_id % count( self::ACCENTS ) ];
    }

    /** Le produit a-t-il une image à montrer ? */
    private static function a_une_image( $product ) {
        return $product instanceof WC_Product && $product->get_image_id();
    }
}
