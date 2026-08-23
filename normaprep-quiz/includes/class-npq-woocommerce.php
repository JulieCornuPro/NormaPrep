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
