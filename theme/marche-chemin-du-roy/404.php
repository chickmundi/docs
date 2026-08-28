<?php
/**
 * 404.
 *
 * A dead end is a chance to recover the visit, so this page offers the aisles
 * and a search box rather than an apology alone.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="content" class="mcr-main">
	<div class="wrap">
		<div class="confirm">
			<h1><?php esc_html_e( 'This page has moved off the shelf', 'mcr' ); ?></h1>
			<p class="lead"><?php esc_html_e( 'The page you were looking for is not here. Try a search, or head back to the aisles.', 'mcr' ); ?></p>

			<div class="notfound-search"><?php get_search_form(); ?></div>

			<?php
			$terms = mcr_catalogue_terms();
			if ( $terms ) :
				?>
				<div class="aisles" style="--cols:<?php echo esc_attr( (string) min( 5, count( $terms ) ) ); ?>">
					<?php foreach ( $terms as $term ) : ?>
						<a class="aisle" href="<?php echo esc_url( get_term_link( $term ) ); ?>">
							<span class="ill"><?php mcr_the_icon( mcr_aisle_icon( $term ) ); ?></span>
							<span class="nm"><b><?php echo esc_html( mcr_term_name( $term ) ); ?></b></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p style="margin-top:26px">
				<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Back to the shop', 'mcr' ); ?>
				</a>
			</p>
		</div>
	</div>
</main>

<?php
get_footer();
