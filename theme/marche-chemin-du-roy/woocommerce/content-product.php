<?php
/**
 * Product card in a loop.
 *
 * The whole card is rendered by mcr_product_card() so every grid on the site —
 * shop, category, rail, related, cross-sell, search — shares one renderer.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package MCR
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
	return;
}

mcr_product_card( $product );
