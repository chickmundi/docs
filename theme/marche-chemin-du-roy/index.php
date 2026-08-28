<?php
/**
 * Fallback template — blog index and any archive without a more specific file.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="content" class="mcr-main">
	<div class="wrap">
		<?php mcr_breadcrumbs(); ?>

		<?php if ( have_posts() ) : ?>
			<div class="page-head">
				<div>
					<h1><?php echo esc_html( is_home() ? get_the_title( (int) get_option( 'page_for_posts' ) ) : wp_strip_all_tags( get_the_archive_title() ) ); ?></h1>
					<?php the_archive_description( '<p class="lead">', '</p>' ); ?>
				</div>
			</div>

			<div class="post-list">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/content', 'excerpt' );
				endwhile;
				?>
			</div>

			<?php
			the_posts_pagination( array(
				'mid_size'  => 1,
				'prev_text' => esc_html__( 'Previous', 'mcr' ),
				'next_text' => esc_html__( 'Next', 'mcr' ),
			) );
			?>
		<?php else : ?>
			<?php get_template_part( 'template-parts/content', 'none' ); ?>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
