<?php
/**
 * Template Name: Espace membre NormaPrep
 *
 * Espace membre utilisant l'en-tête et le pied de page du thème (source unique
 * pour le menu et le logo), avec une barre latérale pleine hauteur et le
 * contenu de la maquette au milieu.
 *
 * @package NormaPrep_Quiz
 */

if ( ! is_user_logged_in() ) {
    $page_connexion = get_option( 'npq_page_connexion_id' );
    wp_safe_redirect( $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' ) );
    exit;
}

$user   = wp_get_current_user();
$nom    = $user->display_name ? $user->display_name : $user->user_email;
$abonne = class_exists( 'NPQ_Comptes' ) ? NPQ_Comptes::est_abonne_actif() : false;

$initiales = strtoupper( mb_substr( preg_replace( '/[^A-Za-z0-9]/', '', $nom ), 0, 2 ) );
if ( $initiales === '' ) { $initiales = 'NP'; }

$url_examen  = ( $id = get_option( 'npq_page_examen_id' ) ) ? get_permalink( $id ) : '#';
$url_espace  = ( $id = get_option( 'npq_page_espace_id' ) ) ? get_permalink( $id ) : home_url( '/' );
$page_offres = get_page_by_path( 'offres' );
$url_offres  = $page_offres ? get_permalink( $page_offres ) : '#';

// Vraies données : EXAMENS passés (les révisions sont exclues — ce sont des
// entraînements, elles ne comptent ni dans l'historique ni dans les statistiques).
$examens = [];
$nb_examens = 0;
$nb_revisions = 0;
if ( class_exists( 'NPQ_Comptes' ) ) {
    $fiche = NPQ_Comptes::fiche_courante();
    if ( $fiche ) {
        global $wpdb;
        $p = $wpdb->prefix . NPQ_TABLE_PREFIX;
        // L'historique montre TOUT (y compris les abandons, affichés comme tels).
        $examens = $wpdb->get_results( $wpdb->prepare(
            "SELECT score, reussi, date_debut FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL
               AND mode <> 'revision'
             ORDER BY date_debut DESC LIMIT 20",
            $fiche['id']
        ), ARRAY_A );

        // Mais les STATISTIQUES ne comptent que les examens vraiment passés
        // (un abandon a score NULL : il ne doit pas peser sur la moyenne).
        $examens_notes = array_filter( $examens, function ( $e ) {
            return $e['score'] !== null;
        } );
        $nb_examens = count( $examens_notes );

        // Sessions de révision terminées (élément d'engagement).
        $nb_revisions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL
               AND mode = 'revision'",
            $fiche['id']
        ) );
    }
}

$score_moyen = null;
if ( $nb_examens > 0 ) {
    $somme = 0;
    foreach ( $examens_notes as $e ) { $somme += (int) $e['score']; }
    $score_moyen = (int) round( $somme / $nb_examens );
}

get_header();
?>

<div class="npq-app">
  <div class="shell" id="npqShell">

    <?php echo NPQ_Espace::barre_laterale( 'dashboard' ); ?>

    <main class="main">
      <div class="greet">Bonjour <?php echo esc_html( $nom ); ?></div>
      <div class="status-line">Statut : <span class="val<?php echo $abonne ? '' : ' inactif'; ?>"><?php echo $abonne ? 'Abonnement actif' : 'Compte gratuit'; ?></span></div>

      <div class="cta-row">
        <?php if ( $abonne ) : ?>
          <a href="<?php echo esc_url( $url_examen ); ?>" class="btn btn-primary">Lancer un examen</a>
        <?php else : ?>
          <a href="<?php echo esc_url( $url_offres ); ?>" class="btn btn-primary">Découvrir les offres</a>
        <?php endif; ?>
      </div>

      <div class="stat-grid">
        <div class="stat-card"><div class="sc-label">Révisions complétées</div><div class="sc-val"><?php echo (int) $nb_revisions; ?></div></div>
        <div class="stat-card"><div class="sc-label">Score de réussite moyen</div><div class="sc-val"><?php echo ( $score_moyen !== null ) ? $score_moyen . '<span>%</span>' : '&mdash;'; ?></div></div>
        <div class="stat-card soon"><div class="sc-label">Temps de révision</div><div class="sc-val">à venir</div></div>
        <div class="stat-card soon"><div class="sc-label">Régularité</div><div class="sc-val">à venir</div></div>
      </div>

      <div class="sec-title">Mes examens</div>

      <?php if ( ! empty( $examens ) ) : ?>

        <?php
        // Comptage par statut, pour les compteurs des onglets.
        $nb_reussis = 0;
        $nb_echoues = 0;
        $nb_abandon = 0;
        foreach ( $examens as $e ) {
            if ( $e['score'] === null ) { $nb_abandon++; }
            elseif ( $e['reussi'] )     { $nb_reussis++; }
            else                        { $nb_echoues++; }
        }
        ?>

        <!-- Filtres : le filtrage se fait côté navigateur, sur les examens affichés -->
        <div class="npq-filtres" id="npq-filtres-examens">
          <button type="button" class="npq-filtre actif" data-filtre="tous">
            Tous <span class="npq-filtre-nb"><?php echo count( $examens ); ?></span>
          </button>
          <button type="button" class="npq-filtre" data-filtre="reussi">
            Réussis <span class="npq-filtre-nb"><?php echo $nb_reussis; ?></span>
          </button>
          <button type="button" class="npq-filtre" data-filtre="echoue">
            Échoués <span class="npq-filtre-nb"><?php echo $nb_echoues; ?></span>
          </button>
          <button type="button" class="npq-filtre" data-filtre="abandon">
            Abandonnés <span class="npq-filtre-nb"><?php echo $nb_abandon; ?></span>
          </button>
        </div>

        <table id="npq-table-examens">
          <thead><tr><th>Date</th><th>Score</th><th>Résultat</th></tr></thead>
          <tbody>
            <?php foreach ( $examens as $e ) :
              $date = esc_html( mysql2date( 'd/m/Y', $e['date_debut'] ) );
              $abandonne = ( $e['score'] === null );

              if ( $abandonne ) {
                  // Un abandon n'a pas de score : ce n'est pas un échec.
                  $score  = '&mdash;';
                  $res    = 'Abandonné';
                  $cls    = 'result-abandon';
                  $statut = 'abandon';
              } else {
                  $score  = intval( $e['score'] ) . ' %';
                  $res    = $e['reussi'] ? 'Réussi' : 'Échoué';
                  $cls    = $e['reussi'] ? 'result-ok' : 'result-ko';
                  $statut = $e['reussi'] ? 'reussi' : 'echoue';
              }
            ?>
              <tr data-statut="<?php echo esc_attr( $statut ); ?>">
                <td><?php echo $date; ?></td>
                <td><?php echo $score; ?></td>
                <td class="<?php echo $cls; ?>"><?php echo $res; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p class="npq-table-vide" id="npq-table-vide" style="display:none">
          Aucun examen dans cette catégorie.
        </p>

        <?php if ( count( $examens ) >= 20 ) : ?>
          <p class="npq-table-note">
            Les 20 derniers examens. Les filtres portent sur cette liste.
          </p>
        <?php endif; ?>

      <?php else : ?>
        <p class="empty">Vous n'avez pas encore passé d'examen. Lancez votre premier examen pour suivre votre progression ici.</p>
      <?php endif; ?>

    </main>
  </div>
</div>

<?php get_footer(); ?>
