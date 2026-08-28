<?php
/**
 * Single product layout.
 *
 * The `.pdp` grid holds the gallery and the buy pane side by side from 900px.
 * Tabs, upsells and related products sit outside it, full width. Everything
 * inside is still driven by the standard WooCommerce hooks, so review,
 * subscription and payment plugins that hook the summary keep working.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package MCR
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

/**
 * Hook: woocommerce_before_single_product.
 */
do_action( 'woocommerce_before_single_product' );

if ( post_password_required() ) {
	echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( '', $product ); ?>>

	<div class="pdp">
		<div class="gal">
			<?php
			/**
			 * Hook: woocommerce_before_single_product_summary.
			 *
			 * Sale flash and the product gallery.
			 */
			do_action( 'woocommerce_before_single_product_summary' );
			?>
		</div>

		<div class="summary entry-summary pdp-info">
			<?php
			/**
			 * Hook: woocommerce_single_product_summary.
			 *
			 * Title, price, excerpt, add-to-cart form, trust row and meta.
			 */
			do_action( 'woocommerce_single_product_summary' );
			?>
		</div>
	</div>

	<?php
	/**
	 * Hook: woocommerce_after_single_product_summary.
	 *
	 * Tabs, upsells and related products.
	 */
	do_action( 'woocommerce_after_single_product_summary' );
	?>
</div>

<?php
/**
 * Hook: woocommerce_after_single_product.
 */
do_action( 'woocommerce_after_single_product' );
