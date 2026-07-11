<?php
/**
 * Template Name: Examen NormaPrep
 *
 * Page « Passer un examen » dans la coquille de l'espace membre :
 * en-tête du thème + barre latérale partagée + écrans d'examen + pied du thème.
 *
 * Les trois écrans (choix du scénario, question, résultat) sont produits par
 * la classe NPQ_Examen via son shortcode — source unique, pas de duplication.
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

    <?php echo NPQ_Espace::barre_laterale( 'examens' ); ?>

    <main class="main">
      <?php echo do_shortcode( '[npq_examen]' ); ?>
    </main>
  </div>
</div>

<?php get_footer(); ?>
