<?php
/**
 * Environment guards.
 *
 * A theme that fatals takes the whole storefront down, including wp-admin, so
 * every hard dependency is checked before anything else loads.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether PHP and WordPress meet the theme's minimums.
 */
function mcr_environment_supported(): bool {
	static $supported = null;

	if ( null !== $supported ) {
		return $supported;
	}

	$problems = array();

	if ( version_compare( PHP_VERSION, MCR_MIN_PHP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'PHP %1$s or later is required. This server runs PHP %2$s.', 'mcr' ),
			MCR_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), MCR_MIN_WP, '<' ) ) {
		$problems[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'WordPress %1$s or later is required. This site runs WordPress %2$s.', 'mcr' ),
			MCR_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	$supported = empty( $problems );

	if ( ! $supported ) {
		add_action(
			'admin_notices',
			static function () use ( $problems ) {
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px">%s</ul></div>',
					esc_html__( 'The Marché Chemin-du-Roy theme cannot load.', 'mcr' ),
					implode( '', array_map( static fn( $p ) => '<li>' . esc_html( $p ) . '</li>', $problems ) )
				);
			}
		);
	}

	return $supported;
}

/**
 * Whether WooCommerce is active.
 *
 * The theme degrades to a plain content site without it rather than fataling,
 * which matters during plugin updates and staging restores.
 */
function mcr_is_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * Whether the MCR Bilingue companion plugin is providing translations.
 *
 * Every bilingual call site checks this, so deactivating the plugin leaves a
 * working French storefront instead of a broken one.
 */
function mcr_is_bilingual(): bool {
	return function_exists( 'mcr_current_language' );
}

/**
 * Current language code, falling back to French when the plugin is absent.
 */
function mcr_lang(): string {
	return mcr_is_bilingual() ? mcr_current_language() : 'fr';
}

/**
 * Pick one of two values by current language.
 *
 * Mirrors the artifact's `L()` helper so template code reads the same way.
 *
 * @param string $fr French value.
 * @param string $en English value.
 */
function mcr_t( string $fr, string $en ): string {
	return 'en' === mcr_lang() ? $en : $fr;
}
