<?php
/**
 * Template Name: Révisions NormaPrep
 *
 * Page « Révisions » dans la coquille de l'espace membre.
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

    <?php echo NPQ_Espace::barre_laterale( 'revisions' ); ?>

    <main class="main">
      <?php
      // Sur mobile, le déroulé est masqué et ce message s'affiche à la place.
      echo NPQ_Espace::message_mobile( 'révision' );

      echo do_shortcode( '[npq_revision]' );
      ?>
    </main>
  </div>
</div>

<?php get_footer(); ?>
