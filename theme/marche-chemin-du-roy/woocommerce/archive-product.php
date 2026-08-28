<?php
/**
 * Product archive: shop, category and tag listings.
 *
 * Layout follows the artifact's aisle page — a filter rail on the left from
 * 1000px up, the product grid on the right, and a bottom sheet on phones.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package MCR
 * @version 8.6.0
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

/**
 * Hook: woocommerce_before_main_content.
 *
 * Opens <main class="mcr-main"><div class="wrap"> and prints breadcrumbs.
 */
do_action( 'woocommerce_before_main_content' );
?>

<div class="page-head">
	<div>
		<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
			<h1 class="woocommerce-products-header__title"><?php woocommerce_page_title(); ?></h1>
		<?php endif; ?>

		<?php
		/**
		 * Hook: woocommerce_archive_description.
		 */
		do_action( 'woocommerce_archive_description' );
		?>
	</div>
</div>

<?php mcr_subcategory_chips(); ?>

<div class="listing">
	<?php mcr_filter_rail(); ?>

	<div class="listing-main">
		<?php if ( woocommerce_product_loop() ) : ?>

			<?php
			/**
			 * Hook: woocommerce_before_shop_loop.
			 *
			 * Prints the toolbar: result count, filter trigger and sort control.
			 */
			do_action( 'woocommerce_before_shop_loop' );
			?>

			<?php woocommerce_product_loop_start(); ?>

			<?php
			while ( have_posts() ) {
				the_post();

				/**
				 * Hook: woocommerce_shop_loop.
				 */
				do_action( 'woocommerce_shop_loop' );

				wc_get_template_part( 'content', 'product' );
			}
			?>

			<?php woocommerce_product_loop_end(); ?>

			<?php
			/**
			 * Hook: woocommerce_after_shop_loop.
			 */
			do_action( 'woocommerce_after_shop_loop' );
			?>

		<?php else : ?>

			<?php
			/**
			 * Hook: woocommerce_no_products_found.
			 */
			do_action( 'woocommerce_no_products_found' );
			?>

		<?php endif; ?>
	</div>
</div>

<?php
/**
 * Hook: woocommerce_after_main_content.
 */
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
