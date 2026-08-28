<?php
/**
 * Single page.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="content" class="mcr-main">
	<div class="wrap">
		<?php mcr_breadcrumbs(); ?>

		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'doc' ); ?>>
				<div class="page-head">
					<div><h1><?php the_title(); ?></h1></div>
				</div>

				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="doc-media"><?php the_post_thumbnail( 'large' ); ?></figure>
				<?php endif; ?>

				<div class="doc-body">
					<?php
					the_content();
					wp_link_pages( array(
						'before' => '<nav class="page-links">',
						'after'  => '</nav>',
					) );
					?>
				</div>
			</article>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
		endwhile;
		?>
	</div>
</main>

<?php
get_footer();
