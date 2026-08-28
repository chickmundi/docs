<?php
/**
 * WooCommerce theme support and layout re-wiring.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'mcr_woocommerce_support' );
/**
 * Declare WooCommerce support.
 *
 * Declaring gallery support is what enables the zoom/lightbox/slider on the
 * product page; without it Woo renders a single static image.
 */
function mcr_woocommerce_support(): void {
	add_theme_support( 'woocommerce', array(
		'thumbnail_image_width' => 520,
		'single_image_width'    => 1000,
		'product_grid'          => array(
			'default_rows'    => 4,
			'min_rows'        => 1,
			'default_columns' => 4,
			'min_columns'     => 2,
			'max_columns'     => 5,
		),
	) );

	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
}

add_action( 'before_woocommerce_init', 'mcr_declare_woocommerce_compat' );
/**
 * Declare compatibility with modern WooCommerce features.
 *
 * Without the HPOS declaration the store shows an "incompatible plugin"
 * warning and can refuse to enable high-performance order storage.
 */
function mcr_declare_woocommerce_compat(): void {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'custom_order_tables',
		get_template_directory() . '/functions.php',
		true
	);
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
		'cart_checkout_blocks',
		get_template_directory() . '/functions.php',
		false
	);
}

/**
 * Products per row in the listing grid.
 */
add_filter( 'loop_shop_columns', static fn() => 4 );

/**
 * Products per page — 25, matching the artifact's PER constant.
 */
add_filter( 'loop_shop_per_page', static fn() => (int) apply_filters( 'mcr_products_per_page', 25 ), 20 );

/**
 * Related products: 4 in one row rather than Woo's default 3.
 */
add_filter( 'woocommerce_output_related_products_args', static function ( array $args ): array {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
} );

add_filter( 'woocommerce_enqueue_styles', 'mcr_dequeue_woocommerce_styles' );
/**
 * Drop WooCommerce's own layout stylesheets.
 *
 * The theme restyles every Woo component, so shipping woocommerce-layout.css
 * and woocommerce.css only creates specificity fights. The smallscreen
 * stylesheet goes too — this theme is mobile-first on its own grid.
 *
 * @param array $styles Registered Woo styles.
 * @return array
 */
function mcr_dequeue_woocommerce_styles( array $styles ): array {
	unset( $styles['woocommerce-layout'], $styles['woocommerce-smallscreen'], $styles['woocommerce-general'] );
	return $styles;
}

add_action( 'wp_enqueue_scripts', 'mcr_dequeue_woocommerce_block_styles', 100 );
/**
 * Remove the Woo block library CSS on pages with no Woo blocks.
 *
 * Cart and checkout keep it: the store may be using the block versions of
 * those pages, and stripping it there would break them.
 */
function mcr_dequeue_woocommerce_block_styles(): void {
	if ( is_cart() || is_checkout() || is_account_page() ) {
		return;
	}

	if ( ! has_block( 'woocommerce/cart' ) && ! has_block( 'woocommerce/checkout' ) ) {
		wp_dequeue_style( 'wc-blocks-style' );
	}
}
