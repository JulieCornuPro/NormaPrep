<?php
/**
 * Bibliothèque de certifications d'un utilisateur.
 *
 * Modèle « bibliothèque personnelle » : un utilisateur acquiert une ou plusieurs
 * certifications, qui s'ajoutent à son compte. Cette classe centralise la
 * question « à quelles certifications cet utilisateur a-t-il accès ? », de la
 * même façon que NPQ_Certification centralise « quelle est la certification
 * courante ».
 *
 * Le DROIT d'accès est porté par la table de liaison utilisateur_certification,
 * indépendamment de la mécanique de vente : peu importe comment la ligne a été
 * créée (achat, abonnement, attribution manuelle), sa présence vaut accès.
 *
 * La colonne fin_acces permet, à terme, des accès à durée limitée. Tant qu'elle
 * vaut NULL, l'accès est permanent. La vérification en tient déjà compte, pour
 * n'avoir rien à changer le jour où tu vendras des accès temporaires.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Bibliotheque {

    /**
     * L'utilisateur a-t-il accès à cette certification ?
     * Un accès expiré (fin_acces dépassée) ne compte pas.
     *
     * @param int $utilisateur_id  Id métier (table utilisateur), pas l'id WP.
     * @param int $certification_id
     * @return bool
     */
    public static function a_acces( $utilisateur_id, $certification_id ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d
               AND certification_id = %d
               AND ( fin_acces IS NULL OR fin_acces >= CURDATE() )",
            $utilisateur_id,
            $certification_id
        ) );

        return ( $n > 0 );
    }

    /**
     * Les certifications auxquelles l'utilisateur a accès (non expirées),
     * avec leur code et leur nom, dans l'ordre d'acquisition.
     *
     * @param int $utilisateur_id
     * @return array Lignes : id, code, nom, date_acquisition, fin_acces.
     */
    public static function certifications_de( $utilisateur_id ) {
        $utilisateur_id = (int) $utilisateur_id;
        if ( ! $utilisateur_id ) {
            return [];
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.code, c.nom,
                    uc.date_acquisition, uc.fin_acces
             FROM {$p}utilisateur_certification uc
             INNER JOIN {$p}certification c ON c.id = uc.certification_id
             WHERE uc.utilisateur_id = %d
               AND ( uc.fin_acces IS NULL OR uc.fin_acces >= CURDATE() )
             ORDER BY uc.date_acquisition ASC, c.nom ASC",
            $utilisateur_id
        ), ARRAY_A );
    }

    /**
     * Ids des certifications accessibles (raccourci pour les clauses IN).
     *
     * @param int $utilisateur_id
     * @return int[]
     */
    public static function ids_de( $utilisateur_id ) {
        return array_map(
            static function ( $c ) { return (int) $c['id']; },
            self::certifications_de( $utilisateur_id )
        );
    }

    /**
     * Certifications à présenter à l'utilisateur CONNECTÉ : celles de sa
     * bibliothèque. S'il n'en a aucune (compte dont l'accès n'est pas encore
     * enregistré), on retombe sur la certification active plutôt que d'afficher
     * une page vide.
     *
     * Les pages Révisions, Flashcards et Activité avaient chacune leur copie de
     * cette logique, mot pour mot. Trois copies, c'est trois occasions de
     * diverger : la règle de repli vit désormais à un seul endroit.
     *
     * @return array Lignes : id, code, nom.
     */
    public static function certifications_utilisateur() {
        $fiche = NPQ_Comptes::fiche_courante();
        $utilisateur_id = $fiche ? (int) $fiche['id'] : 0;

        $certifs = $utilisateur_id ? self::certifications_de( $utilisateur_id ) : [];

        if ( empty( $certifs ) ) {
            $active = NPQ_Certification::courante();
            if ( $active ) {
                $certifs = [ [
                    'id'   => (int) $active['id'],
                    'code' => $active['code'],
                    'nom'  => $active['nom'],
                ] ];
            }
        }

        return $certifs;
    }

    /**
     * L'utilisateur connecté a-t-il accès à cette certification ?
     * Garde-fou à appeler avant d'exposer le contenu d'une certification.
     *
     * Si sa bibliothèque est constituée, elle fait foi. Sinon on tolère la
     * certification active — cohérent avec le repli ci-dessus.
     *
     * @param int $certification_id
     * @return bool
     */
    public static function utilisateur_peut_acceder( $certification_id ) {
        $certification_id = (int) $certification_id;
        if ( ! $certification_id ) {
            return false;
        }

        $fiche = NPQ_Comptes::fiche_courante();
        $utilisateur_id = $fiche ? (int) $fiche['id'] : 0;
        if ( ! $utilisateur_id ) {
            return false;
        }

        $ids = self::ids_de( $utilisateur_id );
        if ( ! empty( $ids ) ) {
            return in_array( $certification_id, $ids, true );
        }

        return ( $certification_id === NPQ_Certification::id() );
    }

    /* =====================================================================
     * CERTIFICATION CONSULTÉE (pages publiques multi-certification)
     * ===================================================================== */

    /**
     * Certification que l'utilisateur consulte, lue dans l'URL (?npq_certif=…).
     *
     * L'état de la page vit dans l'URL plutôt qu'en session : la page reste
     * ainsi partageable et compatible avec le bouton « précédent ».
     *
     * La valeur est TOUJOURS revérifiée contre la bibliothèque : le paramètre
     * vient du navigateur, donc il se falsifie à la main. Un identifiant qui
     * n'y figure pas est ignoré au profit de la première certification, plutôt
     * que rejeté par une erreur — l'utilisateur n'a pas à subir un message
     * technique pour une URL mal recopiée.
     *
     * @param array|null $certifs Bibliothèque déjà chargée, ou null pour la lire.
     * @return int 0 si l'utilisateur n'a accès à aucune certification.
     */
    public static function certification_choisie( $certifs = null ) {
        if ( $certifs === null ) {
            $certifs = self::certifications_utilisateur();
        }
        if ( empty( $certifs ) ) {
            return 0;
        }

        $demandee = isset( $_GET['npq_certif'] ) ? (int) $_GET['npq_certif'] : 0;

        if ( $demandee ) {
            foreach ( $certifs as $c ) {
                if ( (int) $c['id'] === $demandee ) {
                    return $demandee;
                }
            }
        }

        return (int) $certifs[0]['id'];
    }

    /**
     * Sélecteur de certification, partagé par les pages publiques.
     *
     * Renvoie une chaîne vide si l'utilisateur ne possède qu'une certification :
     * un choix à une seule option n'est pas un choix, c'est du bruit.
     *
     * Formulaire en GET, sans JavaScript obligatoire — l'envoi automatique au
     * changement est un confort, le bouton reste le filet de sécurité.
     *
     * @param array  $certifs
     * @param int    $actuelle
     * @param string $id_champ Identifiant DOM, unique par page (deux pages
     *                         peuvent afficher ce sélecteur).
     * @return string
     */
    public static function selecteur_certification( $certifs, $actuelle, $id_champ = 'npq-certif-select' ) {
        if ( count( $certifs ) < 2 ) {
            return '';
        }

        ob_start();
        ?>
        <form method="get" class="npq-act-certif">
            <label for="<?php echo esc_attr( $id_champ ); ?>" class="npq-champ-label">Certification</label>
            <select name="npq_certif" id="<?php echo esc_attr( $id_champ ); ?>"
                    class="npq-compo-select" onchange="this.form.submit()">
                <?php foreach ( $certifs as $c ) : ?>
                    <option value="<?php echo (int) $c['id']; ?>"
                        <?php selected( (int) $c['id'], (int) $actuelle ); ?>>
                        <?php echo esc_html( $c['nom'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="npq-btn">Afficher</button></noscript>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Attribue une certification à un utilisateur (entrée dans la bibliothèque).
     * Idempotent : réattribuer une certification déjà présente ne crée pas de
     * doublon, mais peut mettre à jour la date de fin d'accès.
     *
     * @param int         $utilisateur_id
     * @param int         $certification_id
     * @param string|null $fin_acces  Date 'AAAA-MM-JJ', ou null (permanent).
     * @return bool Vrai si l'opération a réussi.
     */
    public static function attribuer( $utilisateur_id, $certification_id, $fin_acces = null ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        // Déjà présente ? On met éventuellement à jour la fin d'accès.
        $existant = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d AND certification_id = %d",
            $utilisateur_id,
            $certification_id
        ) );

        if ( $existant ) {
            $wpdb->update(
                "{$p}utilisateur_certification",
                [ 'fin_acces' => $fin_acces ],
                [ 'id' => $existant ]
            );
            return true;
        }

        $wpdb->insert( "{$p}utilisateur_certification", [
            'utilisateur_id'   => $utilisateur_id,
            'certification_id' => $certification_id,
            'date_acquisition' => current_time( 'mysql' ),
            'fin_acces'        => $fin_acces,
        ] );

        return (bool) $wpdb->insert_id;
    }

    /**
     * Ouvre ou PROLONGE un accès de N mois.
     *
     * La différence avec attribuer() est la règle de calcul de la date de fin,
     * et elle compte : un client qui renouvelle deux mois avant l'échéance ne
     * doit pas perdre les deux mois qu'il a déjà payés. On repart donc de la
     * date de fin en cours si elle est future, et d'aujourd'hui sinon.
     *
     * Un accès permanent (fin_acces NULL) le reste : rien ne peut le raccourcir.
     *
     * @param int $utilisateur_id
     * @param int $certification_id
     * @param int $mois  Nombre de mois à ajouter. 0 = accès permanent.
     * @return string|null Nouvelle date de fin ('AAAA-MM-JJ'), ou null si permanent.
     */
    public static function prolonger( $utilisateur_id, $certification_id, $mois ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        $mois             = (int) $mois;

        if ( ! $utilisateur_id || ! $certification_id ) {
            return null;
        }

        // Durée nulle : accès sans limite. On écrase toute date existante,
        // puisqu'un accès permanent est toujours plus favorable.
        if ( $mois <= 0 ) {
            self::attribuer( $utilisateur_id, $certification_id, null );
            return null;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $ligne = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, fin_acces FROM {$p}utilisateur_certification
             WHERE utilisateur_id = %d AND certification_id = %d",
            $utilisateur_id,
            $certification_id
        ), ARRAY_A );

        // Accès permanent déjà en place : on n'y touche pas. Le prolonger
        // reviendrait à lui donner une fin, donc à retirer un droit.
        if ( $ligne && $ligne['fin_acces'] === null ) {
            return null;
        }

        $aujourdhui = current_time( 'Y-m-d' );

        // Point de départ : l'échéance en cours si elle est encore future,
        // aujourd'hui sinon (premier achat, ou reprise après expiration).
        $depart = ( $ligne && $ligne['fin_acces'] >= $aujourdhui )
            ? $ligne['fin_acces']
            : $aujourdhui;

        $fin = gmdate( 'Y-m-d', strtotime( $depart . ' +' . $mois . ' months' ) );

        self::attribuer( $utilisateur_id, $certification_id, $fin );

        return $fin;
    }

    /**
     * Retire une certification de la bibliothèque d'un utilisateur.
     *
     * @param int $utilisateur_id
     * @param int $certification_id
     * @return bool
     */
    public static function retirer( $utilisateur_id, $certification_id ) {
        $utilisateur_id   = (int) $utilisateur_id;
        $certification_id = (int) $certification_id;
        if ( ! $utilisateur_id || ! $certification_id ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        return (bool) $wpdb->delete( "{$p}utilisateur_certification", [
            'utilisateur_id'   => $utilisateur_id,
            'certification_id' => $certification_id,
        ] );
    }

    /**
     * Attribue une certification à TOUS les utilisateurs, en accès permanent.
     *
     * ⚠️ NE JAMAIS APPELER AU CHARGEMENT DU PLUGIN.
     *
     * Cette méthode s'appelait « migration_douce » et tournait à chaque
     * chargement de page. Deux conséquences, longtemps invisibles :
     *
     *   1. Tout nouvel inscrit — y compris un compte gratuit créé la seconde
     *      d'avant — recevait un accès PERMANENT à la certification active.
     *      Tant que la barrière d'accès reposait sur la table `abonnement`,
     *      cela ne se voyait pas. Depuis que la bibliothèque fait seule
     *      autorité, cela reviendrait à donner le produit.
     *
     *   2. Révoquer un accès était impossible : retirer() supprimait la ligne,
     *      que le chargement suivant recréait aussitôt. Le bouton « retirer »
     *      de la page Accès semblait fonctionner, puis l'accès revenait.
     *
     * Elle reste disponible pour un geste d'administration délibéré — offrir
     * une certification à toute une promotion, par exemple. Son nom dit
     * désormais ce qu'elle fait, pour que personne ne la rebranche en croyant
     * appeler une migration inoffensive.
     *
     * @param int|null $certification_id  Défaut : la certification active.
     * @return int Nombre d'accès créés.
     */
    public static function attribuer_a_tous_les_utilisateurs( $certification_id = null ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $certification_id = $certification_id ? (int) $certification_id : NPQ_Certification::id();
        if ( ! $certification_id ) {
            return 0;
        }

        // Utilisateurs sans aucune ligne pour cette certification.
        $manquants = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT u.id
             FROM {$p}utilisateur u
             LEFT JOIN {$p}utilisateur_certification uc
                    ON uc.utilisateur_id = u.id
                   AND uc.certification_id = %d
             WHERE uc.id IS NULL",
            $certification_id
        ) );

        $crees = 0;
        foreach ( $manquants as $uid ) {
            $wpdb->insert( "{$p}utilisateur_certification", [
                'utilisateur_id'   => (int) $uid,
                'certification_id' => $certification_id,
                'date_acquisition' => current_time( 'mysql' ),
                'fin_acces'        => null,
            ] );
            $crees++;
        }

        return $crees;
    }
}
