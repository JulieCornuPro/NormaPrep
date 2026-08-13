<?php
/**
 * Import du contenu (certifications, scénarios, questions, options, tags).
 *
 * ORGANISATION DES FICHIERS
 * -------------------------
 * Un dossier par certification, un fichier par scénario :
 *
 *   data/
 *     RM27005/
 *       _certification.json      <- manifeste : code, nom, domaines
 *       SC01_Novalis.json        <- un scénario + ses questions
 *       _flashcards.json         <- optionnel : cartes (non liées à un scénario)
 *     LI27001/
 *       _certification.json
 *       SC00_NovaTech.json
 *       ...
 *
 * Les fichiers dont le nom commence par « _ » sont réservés (manifeste,
 * flashcards). Tous les autres .json sont lus comme des fichiers de scénario.
 *
 * RÉFÉRENCES EXTERNES
 * -------------------
 * Elles ne dépendent plus de la position dans le tableau, mais des
 * identifiants portés par les fichiers :
 *
 *   scénario : RM27005-SC01
 *   question : RM27005-SC01-Q07
 *
 * C'est ce qui rend l'import rejouable ET permet d'importer les scénarios
 * indépendamment les uns des autres sans collision de références.
 *
 * SUPPRESSIONS
 * ------------
 * Une question retirée d'un fichier est supprimée en base lors de l'import
 * de ce fichier. Le nettoyage est strictement limité aux références
 * commençant par le préfixe du scénario concerné.
 *
 * @package NormaPrep_Quiz
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPQ_Importer {

    /** Clé du transient qui transporte le compte rendu jusqu'à l'affichage. */
    const TRANSIENT_RAPPORT = 'npq_rapport_import';

    /**
     * Enregistre la page d'admin et traite l'action d'import.
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'ajouter_page_admin' ] );
        add_action( 'admin_post_npq_importer', [ __CLASS__, 'traiter_import' ] );
    }

    /**
     * Déclare le menu parent « NormaPrep ».
     * Le contenu des pages est défini par NPQ_Admin.
     */
    public static function ajouter_page_admin() {
        add_menu_page(
            'NormaPrep',
            'NormaPrep',
            'manage_options',
            'normaprep-quiz',
            '__return_null',
            'dashicons-welcome-learn-more',
            30
        );
    }

    /* =====================================================================
     * PAGE D'ADMINISTRATION
     * ===================================================================== */

    /**
     * Affiche l'inventaire des fichiers détectés et le bouton d'import.
     *
     * On liste le contenu du dossier data/ AVANT d'importer : l'utilisateur
     * voit ce qui va être traité, et repère immédiatement un dossier mal formé
     * (manifeste manquant, par exemple).
     */
    public static function afficher_page() {
        $rapport = get_transient( self::TRANSIENT_RAPPORT );
        if ( $rapport ) {
            delete_transient( self::TRANSIENT_RAPPORT );
        }

        $inventaire = self::inventorier();
        ?>
        <div class="wrap">
            <h1>NormaPrep Quiz</h1>

            <?php if ( $rapport ) : ?>
                <h2>Résultat du dernier import</h2>
                <?php foreach ( $rapport as $ligne ) :
                    $classe = ( $ligne['statut'] === 'ok' ) ? 'notice-success' : 'notice-error';
                    ?>
                    <div class="notice <?php echo esc_attr( $classe ); ?>">
                        <p>
                            <strong><?php echo esc_html( $ligne['fichier'] ); ?></strong> —
                            <?php echo esc_html( $ligne['message'] ); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2>Contenu détecté</h2>

            <?php if ( empty( $inventaire ) ) : ?>
                <p>
                    Aucune certification trouvée. Créez un dossier par certification
                    dans <code>data/</code>, contenant un fichier
                    <code>_certification.json</code>.
                </p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px">
                    <thead>
                        <tr>
                            <th>Certification</th>
                            <th>Dossier</th>
                            <th>Fichiers de scénario</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $inventaire as $cert ) : ?>
                        <tr>
                            <td>
                                <?php if ( $cert['erreur'] ) : ?>
                                    <span style="color:#d63638">⚠ <?php echo esc_html( $cert['erreur'] ); ?></span>
                                <?php else : ?>
                                    <strong><?php echo esc_html( $cert['code'] ); ?></strong><br>
                                    <?php echo esc_html( $cert['nom'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td><code>data/<?php echo esc_html( $cert['dossier'] ); ?>/</code></td>
                            <td>
                                <?php if ( empty( $cert['fichiers'] ) ) : ?>
                                    <em>aucun</em>
                                <?php else : ?>
                                    <?php echo esc_html( implode( ', ', $cert['fichiers'] ) ); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Importer</h2>
            <p>
                L'opération peut être relancée sans créer de doublon. Les éléments
                déjà présents sont mis à jour ; les questions retirées d'un fichier
                sont supprimées de la base.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="npq_importer">
                <?php wp_nonce_field( 'npq_importer_action', 'npq_nonce' ); ?>
                <p>
                    <button type="submit" class="button button-primary">
                        Importer le contenu
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Parcourt data/ et décrit ce qui s'y trouve, sans rien importer.
     *
     * @return array Une entrée par dossier de certification.
     */
    private static function inventorier() {
        $racine = NPQ_PATH . 'data';
        if ( ! is_dir( $racine ) ) {
            return [];
        }

        $resultat = [];
        foreach ( scandir( $racine ) as $entree ) {
            if ( $entree === '.' || $entree === '..' ) {
                continue;
            }
            $chemin = $racine . '/' . $entree;
            if ( ! is_dir( $chemin ) ) {
                continue;
            }

            $ligne = [
                'dossier'  => $entree,
                'code'     => '',
                'nom'      => '',
                'fichiers' => [],
                'erreur'   => '',
            ];

            $manifeste = self::lire_json( $chemin . '/_certification.json' );
            if ( ! $manifeste || empty( $manifeste['certification']['code'] ) ) {
                $ligne['erreur'] = 'Manifeste _certification.json manquant ou illisible';
            } else {
                $ligne['code'] = $manifeste['certification']['code'];
                $ligne['nom']  = $manifeste['certification']['name'] ?? '';
            }

            foreach ( self::fichiers_scenarios( $chemin ) as $f ) {
                $ligne['fichiers'][] = basename( $f );
            }

            $resultat[] = $ligne;
        }

        return $resultat;
    }

    /* =====================================================================
     * IMPORT
     * ===================================================================== */

    /**
     * Point d'entrée de l'import : parcourt les dossiers de certification.
     */
    public static function traiter_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.' );
        }
        check_admin_referer( 'npq_importer_action', 'npq_nonce' );

        $racine  = NPQ_PATH . 'data';
        $rapport = [];

        if ( ! is_dir( $racine ) ) {
            self::rediriger( [ [
                'fichier' => 'data/',
                'statut'  => 'erreur',
                'message' => 'Dossier data/ introuvable dans le plugin.',
            ] ] );
        }

        foreach ( scandir( $racine ) as $entree ) {
            if ( $entree === '.' || $entree === '..' ) {
                continue;
            }
            $dossier = $racine . '/' . $entree;
            if ( ! is_dir( $dossier ) ) {
                continue;
            }
            $rapport = array_merge( $rapport, self::importer_certification( $dossier, $entree ) );
        }

        if ( empty( $rapport ) ) {
            $rapport[] = [
                'fichier' => 'data/',
                'statut'  => 'erreur',
                'message' => 'Aucun dossier de certification trouvé.',
            ];
        }

        self::rediriger( $rapport );
    }

    /**
     * Importe un dossier de certification : manifeste, puis chaque scénario.
     *
     * @param string $dossier Chemin absolu du dossier.
     * @param string $nom_dossier Nom du dossier (pour les messages).
     * @return array Lignes de compte rendu.
     */
    private static function importer_certification( $dossier, $nom_dossier ) {
        $rapport = [];

        /* ---- Manifeste ---- */
        $manifeste = self::lire_json( $dossier . '/_certification.json' );
        if ( ! $manifeste || empty( $manifeste['certification']['code'] ) ) {
            return [ [
                'fichier' => 'data/' . $nom_dossier . '/_certification.json',
                'statut'  => 'erreur',
                'message' => 'Manifeste manquant, illisible, ou sans code de certification. Dossier ignoré.',
            ] ];
        }

        $cert_code = (string) $manifeste['certification']['code'];
        $cert_nom  = (string) ( $manifeste['certification']['name'] ?? $cert_code );

        // Le manifeste fait autorité. On avertit seulement si le nom de dossier
        // diverge, car un renommage ne doit jamais changer silencieusement la
        // certification cible.
        if ( $nom_dossier !== $cert_code ) {
            $rapport[] = [
                'fichier' => 'data/' . $nom_dossier . '/',
                'statut'  => 'erreur',
                'message' => sprintf(
                    'Avertissement : le dossier s\'appelle « %s » mais le manifeste déclare le code « %s ». C\'est le manifeste qui est appliqué.',
                    $nom_dossier, $cert_code
                ),
            ];
        }

        $certification_id = self::obtenir_ou_creer_certification( $cert_code, $cert_nom );

        /* ---- Domaines (déclarés une seule fois, dans le manifeste) ---- */
        $domaines_connus = [];
        if ( ! empty( $manifeste['domains'] ) && is_array( $manifeste['domains'] ) ) {
            foreach ( $manifeste['domains'] as $code => $d ) {
                $libelle = is_array( $d ) ? ( $d['label'] ?? $code ) : (string) $d;
                self::assurer_domaine( $certification_id, $code, $libelle );
                $domaines_connus[ $code ] = true;
            }
        }

        $rapport[] = [
            'fichier' => 'data/' . $nom_dossier . '/_certification.json',
            'statut'  => 'ok',
            'message' => sprintf(
                'Certification « %s » (%s) — %d domaine(s).',
                $cert_nom, $cert_code, count( $domaines_connus )
            ),
        ];

        /* ---- Scénarios ---- */
        foreach ( self::fichiers_scenarios( $dossier ) as $fichier ) {
            $rapport[] = self::importer_scenario(
                $fichier,
                'data/' . $nom_dossier . '/' . basename( $fichier ),
                $certification_id,
                $cert_code,
                $domaines_connus
            );
        }

        return $rapport;
    }

    /**
     * Importe un fichier de scénario : le scénario, ses questions, options et tags.
     *
     * @return array Une ligne de compte rendu.
     */
    private static function importer_scenario( $chemin, $libelle, $certification_id, $cert_code, $domaines_connus ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $data = self::lire_json( $chemin );
        if ( ! $data ) {
            return [ 'fichier' => $libelle, 'statut' => 'erreur',
                     'message' => 'Fichier illisible ou JSON invalide.' ];
        }

        $s = $data['scenario'] ?? null;
        if ( ! $s || empty( $s['id'] ) || empty( $s['name'] ) || empty( $s['context'] ) ) {
            return [ 'fichier' => $libelle, 'statut' => 'erreur',
                     'message' => 'Bloc « scenario » incomplet (id, name et context sont obligatoires).' ];
        }

        $questions = $data['questions'] ?? [];
        if ( ! is_array( $questions ) ) {
            $questions = [];
        }

        /* ---- Contrôle de cohérence AVANT toute écriture ----
         * Un fichier partiellement importé est pire qu'un fichier rejeté :
         * on valide tout d'abord, et on n'écrit que si tout est bon. */
        $erreurs = self::valider_questions( $questions, $domaines_connus );
        if ( ! empty( $erreurs ) ) {
            return [ 'fichier' => $libelle, 'statut' => 'erreur',
                     'message' => 'Import annulé — ' . implode( ' | ', $erreurs ) ];
        }

        /* ---- Scénario ---- */
        $ref_sc = sprintf( '%s-%s', $cert_code, $s['id'] );   // ex. RM27005-SC01

        // Secteur : vocabulaire fermé. Un code inconnu est refusé plutôt que
        // créé à la volée — sinon « industrie », « industriel » et
        // « manufacturing » coexisteraient et fausseraient les parcours.
        $secteur   = $s['sector'] ?? 'transverse';
        $autorises = self::secteurs_autorises();
        if ( ! empty( $autorises ) && ! isset( $autorises[ $secteur ] ) ) {
            return [
                'fichier' => $libelle,
                'statut'  => 'erreur',
                'message' => sprintf(
                    'Import annulé — secteur « %s » inconnu. Valeurs admises : %s.',
                    $secteur,
                    implode( ', ', array_keys( $autorises ) )
                ),
            ];
        }

        $donnees_sc = [
            'certification_id' => $certification_id,
            'ref_externe'      => $ref_sc,
            'nom'              => $s['name'],
            'resume'           => $s['summary'] ?? '',
            'contexte'         => $s['context'],
            'secteur'          => $secteur,
        ];
        if ( ! empty( $s['status'] ) ) {
            $donnees_sc['statut'] = ( $s['status'] === 'draft' ) ? 'brouillon' : 'publie';
        }

        $scenario_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}scenario WHERE ref_externe = %s", $ref_sc
        ) );

        if ( $scenario_id ) {
            $wpdb->update( "{$p}scenario", $donnees_sc, [ 'id' => $scenario_id ] );
        } else {
            $wpdb->insert( "{$p}scenario", $donnees_sc );
            $scenario_id = $wpdb->insert_id;
        }

        /* ---- Questions ---- */
        $refs_vues    = [];
        $nb_creees    = 0;
        $nb_majs      = 0;

        foreach ( $questions as $q ) {
            $ref_q = sprintf( '%s-%s', $ref_sc, $q['id'] );    // ex. RM27005-SC01-Q07
            $refs_vues[] = $ref_q;

            $donnees_q = [
                'certification_id' => $certification_id,
                'ref_externe'      => $ref_q,
                'scenario_id'      => $scenario_id,
                'domaine'          => $q['domain'],
                'enonce'           => $q['question'],
                'multi_reponses'   => 0,   // banque à réponse unique
                'explication'      => $q['explanation'] ?? '',
                'difficulte'       => $q['difficulty'] ?? 'hard',
            ];
            if ( ! empty( $q['status'] ) ) {
                $donnees_q['statut'] = ( $q['status'] === 'draft' ) ? 'brouillon' : 'publie';
            }

            $question_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}question WHERE ref_externe = %s", $ref_q
            ) );

            if ( $question_id ) {
                $wpdb->update( "{$p}question", $donnees_q, [ 'id' => $question_id ] );
                $wpdb->delete( "{$p}option_reponse", [ 'question_id' => $question_id ] );
                $wpdb->delete( "{$p}question_tag",   [ 'question_id' => $question_id ] );
                $nb_majs++;
            } else {
                $wpdb->insert( "{$p}question", $donnees_q );
                $question_id = $wpdb->insert_id;
                $nb_creees++;
            }

            // Options : la bonne réponse est désignée par son identifiant (A/B/C/D),
            // pas par sa position — ainsi une randomisation du fichier reste sûre.
            foreach ( $q['options'] as $i => $opt ) {
                $wpdb->insert( "{$p}option_reponse", [
                    'question_id' => $question_id,
                    'texte'       => $opt['text'],
                    'correcte'    => ( $opt['id'] === $q['answer'] ) ? 1 : 0,
                    'position'    => $i,
                ] );
            }

            // Tags : chaque famille du JSON devient un tag_type.
            if ( ! empty( $q['tags'] ) ) {
                foreach ( $q['tags'] as $type_nom => $valeurs ) {
                    $liste = is_array( $valeurs ) ? $valeurs : [ $valeurs ];
                    foreach ( $liste as $valeur ) {
                        if ( $valeur === '' || $valeur === null ) {
                            continue;
                        }
                        $tag_id = self::obtenir_ou_creer_tag( $type_nom, (string) $valeur );
                        $wpdb->query( $wpdb->prepare(
                            "INSERT IGNORE INTO {$p}question_tag (question_id, tag_id) VALUES (%d, %d)",
                            $question_id, $tag_id
                        ) );
                    }
                }
            }
        }

        /* ---- Nettoyage : questions retirées du fichier ----
         * Périmètre strictement limité aux références de CE scénario. */
        $nb_supprimees = self::supprimer_questions_obsoletes( $ref_sc, $refs_vues );

        $message = sprintf(
            'Scénario « %s » [%s] — %d question(s) créée(s), %d mise(s) à jour, %d supprimée(s).',
            $s['name'], $secteur, $nb_creees, $nb_majs, $nb_supprimees
        );

        return [ 'fichier' => $libelle, 'statut' => 'ok', 'message' => $message ];
    }

    /* =====================================================================
     * CONTRÔLES DE COHÉRENCE
     * ===================================================================== */

    /**
     * Vérifie chaque question avant écriture.
     *
     * Ces trois contrôles rattrapent les erreurs les plus coûteuses :
     * une clé de réponse qui ne correspond à aucune option, un answer_index
     * désynchronisé de answer, et un domaine absent du manifeste (la question
     * serait alors invisible dans les écrans de révision).
     *
     * @return array Messages d'erreur (vide si tout est bon).
     */
    private static function valider_questions( $questions, $domaines_connus ) {
        $erreurs = [];
        $ids_vus = [];

        foreach ( $questions as $q ) {
            $id = $q['id'] ?? '(sans id)';

            if ( empty( $q['id'] ) || empty( $q['question'] ) || empty( $q['options'] ) ) {
                $erreurs[] = sprintf( '%s : id, question ou options manquants', $id );
                continue;
            }

            if ( isset( $ids_vus[ $q['id'] ] ) ) {
                $erreurs[] = sprintf( '%s : identifiant en double dans le fichier', $id );
            }
            $ids_vus[ $q['id'] ] = true;

            $ids_options = array_column( $q['options'], 'id' );

            // 1. answer doit désigner une option existante.
            if ( ! isset( $q['answer'] ) || ! in_array( $q['answer'], $ids_options, true ) ) {
                $erreurs[] = sprintf( '%s : answer « %s » ne correspond à aucune option',
                                      $id, $q['answer'] ?? '' );
                continue;
            }

            // 2. answer_index doit pointer sur la même option que answer.
            if ( isset( $q['answer_index'] ) ) {
                $i = (int) $q['answer_index'];
                if ( ! isset( $ids_options[ $i ] ) || $ids_options[ $i ] !== $q['answer'] ) {
                    $erreurs[] = sprintf( '%s : answer_index (%d) ne pointe pas sur answer (%s)',
                                          $id, $i, $q['answer'] );
                }
            }

            // 3. Le domaine doit exister dans le manifeste.
            if ( empty( $q['domain'] ) ) {
                $erreurs[] = sprintf( '%s : domaine manquant', $id );
            } elseif ( ! empty( $domaines_connus ) && ! isset( $domaines_connus[ $q['domain'] ] ) ) {
                $erreurs[] = sprintf( '%s : domaine « %s » absent du manifeste',
                                      $id, $q['domain'] );
            }
        }

        return $erreurs;
    }

    /* =====================================================================
     * OUTILS
     * ===================================================================== */

    /**
     * Supprime les questions d'un scénario absentes du fichier, ainsi que
     * leurs options et leurs liaisons de tags.
     *
     * @param string $ref_sc    Préfixe du scénario (ex. RM27005-SC01).
     * @param array  $refs_vues Références présentes dans le fichier.
     * @return int Nombre de questions supprimées.
     */
    private static function supprimer_questions_obsoletes( $ref_sc, $refs_vues ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $like = $wpdb->esc_like( $ref_sc . '-' ) . '%';
        $existantes = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ref_externe FROM {$p}question WHERE ref_externe LIKE %s", $like
        ), ARRAY_A );

        $nb = 0;
        foreach ( $existantes as $ligne ) {
            if ( in_array( $ligne['ref_externe'], $refs_vues, true ) ) {
                continue;
            }
            $id = (int) $ligne['id'];
            $wpdb->delete( "{$p}option_reponse", [ 'question_id' => $id ] );
            $wpdb->delete( "{$p}question_tag",   [ 'question_id' => $id ] );
            $wpdb->delete( "{$p}question",       [ 'id' => $id ] );
            $nb++;
        }

        return $nb;
    }

    /**
     * Liste les fichiers de scénario d'un dossier (.json ne commençant pas par « _ »).
     * Triés par nom : SC01_… puis SC02_…
     */
    private static function fichiers_scenarios( $dossier ) {
        $fichiers = [];
        foreach ( scandir( $dossier ) as $f ) {
            if ( $f[0] === '_' || $f[0] === '.' ) {
                continue;
            }
            if ( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ) !== 'json' ) {
                continue;
            }
            $fichiers[] = $dossier . '/' . $f;
        }
        sort( $fichiers );
        return $fichiers;
    }

    /** Lit et décode un fichier JSON. Renvoie null en cas d'échec. */
    private static function lire_json( $chemin ) {
        if ( ! file_exists( $chemin ) || ! is_readable( $chemin ) ) {
            return null;
        }
        $contenu = file_get_contents( $chemin );
        $data    = json_decode( $contenu, true );
        return ( json_last_error() === JSON_ERROR_NONE ) ? $data : null;
    }

    /** Récupère l'id d'une certification (par son code), en la créant si besoin. */
    private static function obtenir_ou_creer_certification( $code, $nom ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}certification WHERE code = %s", $code
        ) );
        if ( ! $id ) {
            $wpdb->insert( "{$p}certification", [ 'code' => $code, 'nom' => $nom ] );
            $id = $wpdb->insert_id;
        } else {
            $wpdb->update( "{$p}certification", [ 'nom' => $nom ], [ 'id' => $id ] );
        }
        return $id;
    }

    /** Récupère l'id d'un tag (type + valeur), en le créant si nécessaire. */
    private static function obtenir_ou_creer_tag( $type_nom, $valeur ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $type_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tag_type WHERE nom = %s", $type_nom
        ) );
        if ( ! $type_id ) {
            $wpdb->insert( "{$p}tag_type", [ 'nom' => $type_nom ] );
            $type_id = $wpdb->insert_id;
        }

        $tag_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tag WHERE tag_type_id = %d AND valeur = %s",
            $type_id, $valeur
        ) );
        if ( ! $tag_id ) {
            $wpdb->insert( "{$p}tag", [ 'tag_type_id' => $type_id, 'valeur' => $valeur ] );
            $tag_id = $wpdb->insert_id;
        }

        return $tag_id;
    }

    /** Crée le domaine s'il n'existe pas, ou met à jour son libellé. */
    private static function assurer_domaine( $certification_id, $code, $libelle ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;

        $existant = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}domaine WHERE certification_id = %d AND code = %s",
            $certification_id, $code
        ) );

        if ( $existant ) {
            $wpdb->update( "{$p}domaine", [ 'libelle' => $libelle ], [ 'id' => $existant ] );
        } else {
            $wpdb->insert( "{$p}domaine", [
                'certification_id' => $certification_id,
                'code'             => $code,
                'libelle'          => $libelle,
            ] );
        }
    }

    /**
     * Mémorise le compte rendu et revient sur la page d'import.
     *
     * Le rapport passe par un transient (stockage temporaire côté serveur)
     * plutôt que par l'URL : il contient plusieurs lignes, et une URL a une
     * longueur limitée.
     */
    private static function rediriger( $rapport ) {
        set_transient( self::TRANSIENT_RAPPORT, $rapport, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=normaprep-import' ) );
        exit;
    }

    /**
     * Charge le vocabulaire fermé des secteurs (data/_secteurs.json).
     *
     * @return array Codes de secteur autorisés, en clés. Vide si le fichier
     *               est absent : dans ce cas le contrôle est neutralisé
     *               plutôt que bloquant, pour ne pas casser un import
     *               existant si le fichier n'a pas encore été déployé.
     */
    private static function secteurs_autorises() {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }
        $data  = self::lire_json( NPQ_PATH . 'data/_secteurs.json' );
        $cache = ( $data && ! empty( $data['secteurs'] ) )
            ? $data['secteurs']
            : [];
        return $cache;
    }
}
