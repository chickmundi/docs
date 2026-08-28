<?php
/**
 * Re-wire WooCommerce's default output to the artifact's layout.
 *
 * WooCommerce builds pages from hooks. Rather than overriding whole templates
 * (which then drift out of date with Woo releases), the theme unhooks what it
 * does not want and hooks its own partials in. Fewer template overrides means
 * fewer "template is out of date" warnings after a Woo update.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'mcr_rewire_woocommerce' );
/**
 * Move Woo's callbacks around.
 *
 * Hooked on init rather than at file level so plugins that add their own
 * callbacks are all present and the removals land predictably.
 */
function mcr_rewire_woocommerce(): void {

	/* ---------- Wrappers ---------- */
	remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
	remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
	add_action( 'woocommerce_before_main_content', 'mcr_content_wrapper_open', 10 );
	add_action( 'woocommerce_after_main_content', 'mcr_content_wrapper_close', 10 );

	/* ---------- Sidebar ---------- */
	// The artifact filters in a left rail, not a widget sidebar.
	remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

	/* ---------- Breadcrumb ---------- */
	remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
	add_action( 'woocommerce_before_main_content', 'mcr_breadcrumbs', 20 );

	/* ---------- Archive header ---------- */
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
	remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
	add_action( 'woocommerce_before_shop_loop', 'mcr_listing_toolbar', 25 );

	/* ---------- Product card ---------- */
	// Replaced wholesale by mcr_product_card() — see product-card.php.
	remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
	remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
	remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
	remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
	remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

	/* ---------- Single product ---------- */
	// The artifact's PDP leads with the gallery and a compact info pane.
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
	add_action( 'woocommerce_single_product_summary', 'mcr_single_trust_row', 25 );
	add_action( 'woocommerce_single_product_summary', 'mcr_single_product_meta', 45 );

	/* ---------- Cart ---------- */
	add_action( 'woocommerce_before_cart', 'mcr_free_delivery_progress', 5 );
	add_action( 'woocommerce_checkout_before_order_review', 'mcr_free_delivery_progress', 5 );
}

/**
 * Open the main content wrapper.
 */
function mcr_content_wrapper_open(): void {
	echo '<main id="content" class="mcr-main"><div class="wrap">';
}

/**
 * Close the main content wrapper.
 */
function mcr_content_wrapper_close(): void {
	echo '</div></main>';
}

/**
 * Listing toolbar: result count, sort control and the mobile filter trigger.
 */
function mcr_listing_toolbar(): void {
	global $wp_query;

	$total = (int) $wp_query->found_posts;

	echo '<div class="toolbar">';

	printf(
		'<p class="count num">%s</p>',
		esc_html( sprintf(
			/* translators: %s: number of products. */
			_n( '%s product', '%s products', $total, 'mcr' ),
			number_format_i18n( $total )
		) )
	);

	echo '<div class="right">';

	echo '<button type="button" class="btn-filter" id="fopen" aria-expanded="false" aria-controls="filters">';
	mcr_the_icon( 'grid' );
	echo esc_html__( 'Filters', 'mcr' );
	echo '</button>';

	echo '<span class="selectish">';
	woocommerce_catalog_ordering();
	echo '</span>';

	echo '</div></div>';
}

/**
 * Trust row on the product page — pickup, delivery and stock reassurance.
 */
function mcr_single_trust_row(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$items = array();

	if ( $product->is_in_stock() ) {
		$items[] = array(
			'icon'  => 'check',
			'label' => __( 'In stock in Trois-Rivières', 'mcr' ),
		);
	}

	$items[] = array(
		'icon'  => 'truck',
		'label' => sprintf(
			/* translators: %s: formatted free-delivery threshold. */
			__( 'Free delivery over %s', 'mcr' ),
			wp_strip_all_tags( wc_price( mcr_free_delivery_threshold() ) )
		),
	);

	$items[] = array(
		'icon'  => 'bag',
		'label' => __( 'Free pickup at the counter', 'mcr' ),
	);

	echo '<ul class="pdp-trust">';
	foreach ( $items as $item ) {
		echo '<li>';
		mcr_the_icon( $item['icon'] );
		echo '<span>' . esc_html( $item['label'] ) . '</span>';
		echo '</li>';
	}
	echo '</ul>';
}

/**
 * Product meta shown as the artifact's definition list rather than Woo's
 * comma-separated paragraph.
 */
function mcr_single_product_meta(): void {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$rows = array();

	$sku = $product->get_sku();
	if ( $sku ) {
		$rows[ __( 'Item code', 'mcr' ) ] = $sku;
	}

	$categories = wc_get_product_category_list( $product->get_id(), ', ' );
	if ( $categories ) {
		$rows[ __( 'Aisle', 'mcr' ) ] = $categories;
	}

	foreach ( $product->get_attributes() as $attribute ) {
		if ( $attribute->get_variation() ) {
			continue; // Variation attributes are the selector, not metadata.
		}
		$label = wc_attribute_label( $attribute->get_name() );
		$rows[ $label ] = $attribute->is_taxonomy()
			? implode( ', ', wp_get_post_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) )
			: implode( ', ', $attribute->get_options() );
	}

	if ( ! $rows ) {
		return;
	}

	echo '<dl class="deflist">';
	foreach ( $rows as $label => $value ) {
		echo '<dt>' . esc_html( $label ) . '</dt>';
		// Category lists arrive as markup from WooCommerce.
		echo '<dd>' . wp_kses_post( $value ) . '</dd>';
	}
	echo '</dl>';
}

/**
 * Free-delivery progress bar.
 *
 * Reads the live cart rather than a JS-side running total, so a coupon,
 * a removed line or a tax change can never leave it stale.
 */
function mcr_free_delivery_progress(): void {
	if ( ! mcr_is_woocommerce_active() || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	$threshold = mcr_free_delivery_threshold();
	if ( $threshold <= 0 ) {
		return;
	}

	// Subtotal after discounts — the same basis WooCommerce free shipping uses.
	$total     = (float) WC()->cart->get_displayed_subtotal() - (float) WC()->cart->get_discount_total();
	$remaining = max( 0, $threshold - $total );
	$percent   = min( 100, $threshold > 0 ? ( $total / $threshold ) * 100 : 100 );

	printf(
		'<div class="prog-wrap" role="status"><div class="prog"><i style="width:%1$s%%"></i></div><p class="prog-txt">%2$s</p></div>',
		esc_attr( (string) round( $percent, 2 ) ),
		$remaining > 0
			? wp_kses_post( sprintf(
				/* translators: %s: formatted remaining amount. */
				__( '<b>%s</b> more for free delivery', 'mcr' ),
				wp_strip_all_tags( wc_price( $remaining ) )
			) )
			: wp_kses_post( __( '<b>Free delivery</b> unlocked', 'mcr' ) )
	);
}

add_filter( 'render_block', 'mcr_prepend_progress_to_cart_blocks', 10, 2 );
/**
 * Put the free-delivery bar above the block Cart and Checkout.
 *
 * The block versions of those pages render client-side and never fire
 * `woocommerce_before_cart`, so the bar is prepended to the block output
 * instead. Stores still on the [woocommerce_cart] shortcode get it from the
 * classic hook in mcr_rewire_woocommerce(); this covers the other half.
 *
 * @param string $content Rendered block HTML.
 * @param array  $block   Parsed block.
 */
function mcr_prepend_progress_to_cart_blocks( string $content, array $block ): string {
	$name = $block['blockName'] ?? '';

	if ( ! in_array( $name, array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) {
		return $content;
	}

	ob_start();
	mcr_free_delivery_progress();
	$progress = (string) ob_get_clean();

	return $progress . $content;
}
