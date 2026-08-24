<?php
/**
 * Colonne de filtres de la boutique.
 *
 * POURQUOI CE FICHIER PLUTÔT QU'UN WIDGET
 *
 * WooCommerce sait filtrer un catalogue : ses widgets « Navigation par
 * facettes » posent des paramètres dans l'URL (filter_pa_xxx), et sa requête
 * principale les applique d'elle-même. Ce qu'il ne sait pas faire, c'est les
 * afficher dans la colonne dessinée par la maquette CARTO — un widget suppose
 * une zone de widgets, or le thème n'en a pas sur la boutique.
 *
 * On garde donc le mécanisme de WooCommerce, qui est le sien et qui restera
 * valable, et on ne réécrit QUE l'affichage. Les liens produits ici sont ceux
 * qu'un widget produirait : c'est WooCommerce, pas nous, qui filtre.
 *
 * CE QUE LA COLONNE CONTIENT
 *
 *   1. les sous-catégories du rayon courant ;
 *   2. un groupe par attribut de produit (déploiement, niveau, etc.).
 *
 * Aucun de ces groupes n'est écrit en dur : ils se construisent à partir de
 * ce que la boutique contient réellement. Une boutique sans attribut affiche
 * simplement une colonne plus courte.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Boutique_Filtres {

    /**
     * La colonne est-elle attendue sur la page courante ?
     *
     * Uniquement sur les pages de catalogue. Sur une fiche produit, filtrer
     * n'a pas de sens : il n'y a rien à réduire.
     */
    public static function sur_catalogue() {
        if ( ! function_exists( 'is_shop' ) ) {
            return false;
        }
        return is_shop() || is_product_taxonomy();
    }

    /**
     * Rend la colonne complète.
     *
     * @return string HTML, chaîne vide s'il n'y a rien à proposer.
     */
    public static function rendu() {
        $groupes = self::groupe_categories() . self::groupes_attributs();

        // Une colonne vide occuperait 232px pour ne rien dire. Mieux vaut
        // laisser le catalogue prendre toute la largeur.
        if ( '' === trim( $groupes ) ) {
            return '';
        }

        $reinit = self::filtres_actifs()
            ? '<a class="npq-filtres__reinit" href="' . esc_url( self::url_base() ) . '">Effacer les filtres</a>'
            : '';

        return '<aside class="npq-filtres" aria-label="Filtrer le catalogue">'
             . $groupes . $reinit
             . '</aside>';
    }

    /* =====================================================================
     * GROUPE : SOUS-CATÉGORIES
     * ===================================================================== */

    /**
     * Liste des rayons voisins.
     *
     * Sur la boutique : les rayons de premier niveau.
     * Dans un rayon : ses sous-rayons s'il en a, sinon ses voisins de même
     * niveau — on ne montre jamais une liste vide, et rester à côté de là où
     * l'on est reste le plus utile.
     */
    private static function groupe_categories() {
        $terme  = is_product_taxonomy() ? get_queried_object() : null;
        $actuel = ( $terme instanceof WP_Term && 'product_cat' === $terme->taxonomy ) ? $terme : null;

        $parent = $actuel ? $actuel->term_id : 0;

        $termes = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => $parent,
        ] );

        // Rayon sans sous-rayon : on remonte d'un cran pour afficher ses voisins.
        if ( $actuel && ( is_wp_error( $termes ) || empty( $termes ) ) ) {
            $termes = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => true,
                'parent'     => $actuel->parent,
            ] );
        }

        if ( is_wp_error( $termes ) || empty( $termes ) ) {
            return '';
        }

        $lignes = '';
        foreach ( $termes as $t ) {
            $actif = $actuel && $t->term_id === $actuel->term_id;

            $lignes .= '<a class="npq-filtre-lien' . ( $actif ? ' est-actif' : '' ) . '"'
                     . ( $actif ? ' aria-current="page"' : '' )
                     . ' href="' . esc_url( get_term_link( $t ) ) . '">'
                     . '<span class="npq-filtre-lien__lbl">' . esc_html( $t->name ) . '</span>'
                     . '<span class="npq-filtre-lien__nb">' . esc_html( self::deux_chiffres( $t->count ) ) . '</span>'
                     . '</a>';
        }

        return self::groupe( 'Sous-catégories', '<div class="npq-filtres__liens">' . $lignes . '</div>', true );
    }

    /* =====================================================================
     * GROUPES : ATTRIBUTS DE PRODUIT
     * ===================================================================== */

    /**
     * Un groupe de cases à cocher par attribut global de la boutique.
     *
     * Les liens reprennent la convention de WooCommerce :
     *   filter_deploiement=saas,on-premise & query_type_deploiement=or
     *
     * « or » et non « and » : dans une liste de cases à cocher, on s'attend à
     * ÉLARGIR sa sélection en cochant, pas à la restreindre.
     */
    private static function groupes_attributs() {
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return '';
        }

        $sortie  = '';
        $choisis = self::filtres_courants();

        foreach ( wc_get_attribute_taxonomies() as $attribut ) {
            $taxonomie = wc_attribute_taxonomy_name( $attribut->attribute_name );

            $termes = get_terms( [
                'taxonomy'   => $taxonomie,
                'hide_empty' => true,
            ] );

            if ( is_wp_error( $termes ) || empty( $termes ) ) {
                continue;
            }

            $coches = isset( $choisis[ $taxonomie ] ) ? $choisis[ $taxonomie ] : [];
            $cases  = '';

            foreach ( $termes as $t ) {
                $actif = in_array( $t->slug, $coches, true );

                // Cocher ajoute, décocher retire : chaque lien porte l'état
                // que la page aura APRÈS le clic.
                $apres = $actif
                    ? array_values( array_diff( $coches, [ $t->slug ] ) )
                    : array_merge( $coches, [ $t->slug ] );

                // Un lien, pas une case à cocher : le clic recharge la page
                // avec une autre URL. Lui coller role="checkbox" mentirait sur
                // ce qui va se passer. L'état actif est dit dans le libellé
                // lu à voix haute, et montré par la case dessinée en CSS.
                $lu = ( $actif ? 'Retirer le filtre ' : 'Filtrer par ' ) . $t->name;

                $cases .= '<a class="npq-filtre-case' . ( $actif ? ' est-actif' : '' ) . '"'
                        . ' href="' . esc_url( self::url_filtre( $taxonomie, $apres ) ) . '"'
                        . ' aria-label="' . esc_attr( $lu ) . '">'
                        . '<span class="npq-filtre-case__marque" aria-hidden="true"></span>'
                        . '<span class="npq-filtre-case__lbl">' . esc_html( $t->name ) . '</span>'
                        . '</a>';
            }

            $sortie .= self::groupe(
                $attribut->attribute_label ? $attribut->attribute_label : $attribut->attribute_name,
                '<div class="npq-filtres__cases">' . $cases . '</div>'
            );
        }

        return $sortie;
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /**
     * Enveloppe commune d'un groupe : intitulé monospace, filet, contenu.
     *
     * @param string $titre
     * @param string $contenu
     * @param bool   $filet  Le filet dégradé, réservé au premier groupe dans
     *                       la maquette.
     */
    private static function groupe( $titre, $contenu, $filet = false ) {
        return '<div class="npq-filtres__groupe">'
             . '<div class="npq-filtres__titre">// ' . esc_html( $titre ) . '</div>'
             . ( $filet ? '<div class="npq-filtres__filet" aria-hidden="true"></div>' : '' )
             . $contenu
             . '</div>';
    }

    /**
     * Filtres d'attribut présents dans l'URL.
     *
     * On passe par WooCommerce plutôt que de lire $_GET nous-mêmes : c'est lui
     * qui décide de ce qui compte comme filtre valable, et sa lecture reste
     * juste si la convention change un jour.
     *
     * @return array<string,string[]> taxonomie => slugs cochés.
     */
    private static function filtres_courants() {
        if ( ! class_exists( 'WC_Query' ) ) {
            return [];
        }

        $sortie = [];
        foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomie => $donnees ) {
            $sortie[ $taxonomie ] = isset( $donnees['terms'] ) ? (array) $donnees['terms'] : [];
        }

        return $sortie;
    }

    /** Un filtre d'attribut est-il actif ? */
    private static function filtres_actifs() {
        foreach ( self::filtres_courants() as $slugs ) {
            if ( ! empty( $slugs ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * URL du catalogue courant, sans aucun filtre.
     *
     * On repart de la page de rayon, et non de l'URL courante : celle-ci
     * traîne la pagination, et un changement de filtre doit toujours ramener
     * à la première page — la page 3 d'un résultat qui n'en compte plus qu'une
     * afficherait un catalogue vide.
     */
    private static function url_base() {
        if ( is_product_taxonomy() ) {
            $terme = get_queried_object();
            $lien  = ( $terme instanceof WP_Term ) ? get_term_link( $terme ) : '';
            if ( $lien && ! is_wp_error( $lien ) ) {
                return $lien;
            }
        }

        $page_boutique = wc_get_page_id( 'shop' );
        return $page_boutique > 0 ? get_permalink( $page_boutique ) : home_url( '/' );
    }

    /**
     * URL du catalogue avec un groupe d'attribut redéfini.
     *
     * @param string   $taxonomie Attribut modifié.
     * @param string[] $slugs     Termes cochés après le clic (vide = groupe effacé).
     */
    private static function url_filtre( $taxonomie, $slugs ) {
        $filtres = self::filtres_courants();

        if ( empty( $slugs ) ) {
            unset( $filtres[ $taxonomie ] );
        } else {
            $filtres[ $taxonomie ] = $slugs;
        }

        $args = [];
        foreach ( $filtres as $tax => $valeurs ) {
            if ( empty( $valeurs ) ) {
                continue;
            }
            $nom = str_replace( 'pa_', '', $tax );
            $args[ 'filter_' . $nom ]     = implode( ',', array_map( 'sanitize_title', $valeurs ) );
            $args[ 'query_type_' . $nom ] = 'or';
        }

        $base = self::url_base();
        return empty( $args ) ? $base : add_query_arg( $args, $base );
    }

    /**
     * Compteur sur deux chiffres, comme la maquette (« 06 », « 04 »).
     * Des largeurs égales s'alignent ; « 6 » et « 12 » ne s'alignent pas.
     */
    private static function deux_chiffres( $n ) {
        $n = (int) $n;
        return ( $n < 10 ) ? '0' . $n : (string) $n;
    }
}
