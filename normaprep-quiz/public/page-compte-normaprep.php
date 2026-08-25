<?php
/**
 * Template Name: Mes commandes NormaPrep
 *
 * Page « Mon compte » de WooCommerce, dans la coquille de l'espace membre :
 * en-tête du thème + barre latérale partagée + contenu WooCommerce + pied.
 *
 * POURQUOI PASSER PAR THE_CONTENT()
 *
 * On n'appelle pas la fonction d'affichage de WooCommerce directement. La
 * page « Mon compte » contient soit un shortcode, soit un bloc, selon la
 * version qui a créé le site — et l'on ne saurait pas laquelle. the_content()
 * rend l'un comme l'autre : c'est le seul chemin qui marche dans les deux cas.
 *
 * @package NormaPrep_Quiz
 */

if ( ! is_user_logged_in() ) {
    // Même règle que les autres pages de l'espace : c'est l'écran de connexion
    // de NormaPrep qui accueille, pas celui de WooCommerce. Deux formulaires de
    // connexion pour un seul compte n'apprendraient rien à personne.
    $page_connexion = get_option( 'npq_page_connexion_id' );
    wp_safe_redirect( $page_connexion ? get_permalink( $page_connexion ) : home_url( '/' ) );
    exit;
}

get_header();
?>

<div class="npq-app npq-app--compte">
  <div class="shell">

    <?php echo NPQ_Espace::barre_laterale( 'commandes' ); ?>

    <main class="main">
      <?php
      /*
       * Le titre est posé ici, et non repris de la page WordPress : celle-ci
       * s'appelle « Mon compte », alors que l'entrée qui y mène s'appelle
       * « Mes commandes ». Arriver sur un intitulé différent de celui qu'on
       * vient de cliquer fait douter d'avoir atterri au bon endroit.
       */
      ?>
      <div class="sec-title">Mes commandes</div>

      <?php while ( have_posts() ) : the_post(); ?>
        <?php the_content(); ?>
      <?php endwhile; ?>
    </main>
  </div>
</div>

<?php get_footer(); ?>
