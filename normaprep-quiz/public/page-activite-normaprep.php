<?php
/**
 * Template Name: Activité NormaPrep
 *
 * Page « Activité » dans la coquille de l'espace membre.
 *
 * @package NormaPrep_Quiz
 */

if ( ! is_user_logged_in() ) {
    $page_connexion = get_option( 'npq_page_connexion_id' );
    wp_safe_redirect( $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' ) );
    exit;
}

get_header();
?>

<div class="npq-app">
  <div class="shell">

    <?php echo NPQ_Espace::barre_laterale( 'activite' ); ?>

    <main class="main">
      <?php echo do_shortcode( '[npq_activite]' ); ?>
    </main>
  </div>
</div>

<?php get_footer(); ?>
