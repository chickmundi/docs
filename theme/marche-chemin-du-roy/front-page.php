<?php
/**
 * Home page.
 *
 * Assembled from the artifact's home sections. Each is a template part so the
 * order can be changed, or a section dropped, without touching the others.
 *
 * If a static page is assigned as the front page and it has content, that
 * content is rendered after the hero — so marketing can add a block-editor
 * section without a developer.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="content" class="mcr-main">
	<?php
	get_template_part( 'template-parts/home/hero' );
	get_template_part( 'template-parts/home/trust' );
	get_template_part( 'template-parts/home/aisles' );
	get_template_part( 'template-parts/home/rail', null, array(
		'title'   => __( 'This week at the counter', 'mcr' ),
		'lead'    => __( 'Fresh arrivals and the staples that move fastest.', 'mcr' ),
		'orderby' => 'date',
	) );
	get_template_part( 'template-parts/home/beaute' );

	if ( is_page() && get_the_content() ) {
		echo '<section class="band"><div class="wrap doc-body">';
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		echo '</div></section>';
	}

	get_template_part( 'template-parts/home/store' );
	?>
</main>

<?php
get_footer();
