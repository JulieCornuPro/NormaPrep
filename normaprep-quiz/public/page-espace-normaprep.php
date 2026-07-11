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
$url_profil  = ( $id = get_option( 'npq_page_profil_id' ) ) ? get_permalink( $id ) : '#';
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
        $examens = $wpdb->get_results( $wpdb->prepare(
            "SELECT score, reussi, date_debut FROM {$p}tentative
             WHERE utilisateur_id = %d
               AND date_fin IS NOT NULL
               AND mode <> 'revision'
             ORDER BY date_debut DESC LIMIT 20",
            $fiche['id']
        ), ARRAY_A );
        $nb_examens = count( $examens );

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
    foreach ( $examens as $e ) { $somme += (int) $e['score']; }
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

      <?php if ( $nb_examens > 0 ) : ?>
        <table>
          <thead><tr><th>Date</th><th>Score</th><th>Résultat</th></tr></thead>
          <tbody>
            <?php foreach ( $examens as $e ) :
              $date = esc_html( mysql2date( 'd/m/Y', $e['date_debut'] ) );
              $score = ( $e['score'] !== null ) ? intval( $e['score'] ) . ' %' : '&mdash;';
              $res = $e['reussi'] ? 'Réussi' : 'Échoué';
              $cls = $e['reussi'] ? 'result-ok' : 'result-ko';
            ?>
              <tr><td><?php echo $date; ?></td><td><?php echo $score; ?></td><td class="<?php echo $cls; ?>"><?php echo $res; ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else : ?>
        <p class="empty">Vous n'avez pas encore passé d'examen. Lancez votre premier examen pour suivre votre progression ici.</p>
      <?php endif; ?>

      <div class="quick-links">
        <a href="<?php echo esc_url( $url_profil ); ?>">Gérer mon profil</a>
      </div>
    </main>
  </div>
</div>

<?php get_footer(); ?>
