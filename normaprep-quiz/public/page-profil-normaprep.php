<?php
/**
 * Template Name: Profil NormaPrep
 *
 * Page « Mon profil » dans la coquille de l'espace membre :
 * en-tête du thème + barre latérale partagée + contenu du profil + pied du thème.
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

    <?php echo NPQ_Espace::barre_laterale( 'profil' ); ?>

    <main class="main">
      <?php
      // Le contenu du profil est produit par la classe NPQ_Profil (formulaires
      // mot de passe, email, suppression) — source unique, pas de duplication.
      echo do_shortcode( '[npq_profil]' );
      ?>
    </main>
  </div>
</div>

<?php get_footer(); ?>
