<?php
/**
 * Marché Chemin-du-Roy — theme bootstrap.
 *
 * Loads the theme in dependency order. Everything of substance lives in inc/;
 * this file only wires it together so the load order stays readable.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

define( 'MCR_VERSION', '1.0.0' );
define( 'MCR_DIR', get_template_directory() );
define( 'MCR_URI', get_template_directory_uri() );

/**
 * Minimum platform the theme is built against. Below these the theme bails out
 * to a safe notice rather than fataling on a live storefront.
 */
define( 'MCR_MIN_PHP', '8.0' );
define( 'MCR_MIN_WP', '6.4' );

require_once MCR_DIR . '/inc/compat.php';

if ( ! mcr_environment_supported() ) {
	// inc/compat.php has registered an admin notice; load nothing further.
	return;
}

require_once MCR_DIR . '/inc/setup.php';
require_once MCR_DIR . '/inc/assets.php';
require_once MCR_DIR . '/inc/icons.php';
require_once MCR_DIR . '/inc/template-tags.php';
require_once MCR_DIR . '/inc/i18n.php';
require_once MCR_DIR . '/inc/nav-walker.php';
require_once MCR_DIR . '/inc/customizer.php';

if ( mcr_is_woocommerce_active() ) {
	require_once MCR_DIR . '/inc/woocommerce/setup.php';
	require_once MCR_DIR . '/inc/woocommerce/hooks.php';
	require_once MCR_DIR . '/inc/woocommerce/cart-fragments.php';
	require_once MCR_DIR . '/inc/woocommerce/delivery-zones.php';
	require_once MCR_DIR . '/inc/woocommerce/product-card.php';
	require_once MCR_DIR . '/inc/woocommerce/listing.php';
}
