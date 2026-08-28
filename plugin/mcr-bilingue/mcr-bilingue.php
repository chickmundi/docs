<?php
/**
 * Plugin Name:       MCR Bilingue
 * Plugin URI:        https://marchecheminduroy.com
 * Description:       Bilingue FR/EN pour le Marché Chemin-du-Roy. Stocke la traduction anglaise à côté du contenu français — un seul produit, un seul stock, un seul prix — et sert /en/ avec ses propres URLs, hreflang et formats de prix. / Field-level FR/EN translation for WooCommerce: one product record, one stock count, served at both /produit/… and /en/product/….
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Marché Chemin-du-Roy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcr-bilingue
 * Domain Path:       /languages
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

define( 'MCRB_VERSION', '1.0.0' );
define( 'MCRB_FILE', __FILE__ );
define( 'MCRB_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCRB_URL', plugin_dir_url( __FILE__ ) );

require_once MCRB_DIR . 'includes/class-mcr-languages.php';
require_once MCRB_DIR . 'includes/class-mcr-translator.php';
require_once MCRB_DIR . 'includes/class-mcr-router.php';
require_once MCRB_DIR . 'includes/class-mcr-post-filters.php';
require_once MCRB_DIR . 'includes/class-mcr-term-filters.php';
require_once MCRB_DIR . 'includes/class-mcr-search.php';
require_once MCRB_DIR . 'includes/class-mcr-admin.php';
require_once MCRB_DIR . 'includes/class-mcr-woocommerce.php';
require_once MCRB_DIR . 'includes/functions.php';

add_action( 'plugins_loaded', 'mcrb_boot', 5 );
/**
 * Boot the plugin.
 *
 * Priority 5 so language detection is settled before themes and other plugins
 * run their own `plugins_loaded` work and start asking what language it is.
 */
function mcrb_boot(): void {
	load_plugin_textdomain( 'mcr-bilingue', false, dirname( plugin_basename( MCRB_FILE ) ) . '/languages' );

	MCR_Languages::instance()->init();
	MCR_Router::instance()->init();
	MCR_Post_Filters::instance()->init();
	MCR_Term_Filters::instance()->init();
	MCR_Search::instance()->init();

	if ( is_admin() ) {
		MCR_Admin::instance()->init();
	}

	if ( class_exists( 'WooCommerce' ) ) {
		MCR_WooCommerce_Bilingual::instance()->init();
	}
}

register_activation_hook( __FILE__, 'mcrb_activate' );
/**
 * Flush rewrite rules so /en/ URLs resolve immediately on activation.
 */
function mcrb_activate(): void {
	MCR_Languages::instance()->init();
	MCR_Router::instance()->add_rewrite_rules();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'mcrb_deactivate' );
/**
 * Drop the /en/ rules on deactivation.
 *
 * Translations stay in the database untouched — deactivating hides English,
 * it never destroys content.
 */
function mcrb_deactivate(): void {
	flush_rewrite_rules();
}
