<?php
/**
 * Stylesheet and script loading.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stylesheets in cascade order. Keys are handles, values are files under
 * assets/css/. Order is the cascade — do not reorder without checking.
 */
function mcr_stylesheets(): array {
	return array(
		'mcr-tokens'     => '01-tokens.css',
		'mcr-base'       => '02-base.css',
		'mcr-header'     => '03-header.css',
		'mcr-hero'       => '04-hero.css',
		'mcr-components' => '05-components.css',
		'mcr-overlays'   => '06-overlays.css',
		'mcr-shop'       => '07-shop.css',
		'mcr-woo'        => '08-woocommerce.css',
		'mcr-woo-blocks' => '09-woo-blocks.css',
	);
}

add_action( 'wp_enqueue_scripts', 'mcr_enqueue_assets' );
/**
 * Enqueue front-end assets.
 */
function mcr_enqueue_assets(): void {
	mcr_enqueue_fonts();

	$previous = null;
	foreach ( mcr_stylesheets() as $handle => $file ) {
		$path = MCR_DIR . '/assets/css/' . $file;
		if ( ! file_exists( $path ) ) {
			continue;
		}
		wp_enqueue_style(
			$handle,
			MCR_URI . '/assets/css/' . $file,
			null === $previous ? array() : array( $previous ),
			mcr_asset_version( $path )
		);
		$previous = $handle;
	}

	// The theme header stylesheet carries no rules; register it so child
	// themes and plugins have a stable handle to depend on.
	wp_register_style( 'mcr-style', get_stylesheet_uri(), array( $previous ), MCR_VERSION );
	wp_enqueue_style( 'mcr-style' );

	wp_enqueue_script(
		'mcr-app',
		MCR_URI . '/assets/js/app.js',
		array(),
		mcr_asset_version( MCR_DIR . '/assets/js/app.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	wp_localize_script( 'mcr-app', 'mcrData', mcr_script_data() );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

/**
 * Data handed to the front-end script.
 *
 * Everything user-facing is translated server-side so the script never has to
 * carry a second copy of the string table.
 */
function mcr_script_data(): array {
	$free_threshold = mcr_free_delivery_threshold();

	return array(
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'restUrl'       => esc_url_raw( rest_url() ),
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		'lang'          => mcr_lang(),
		'freeThreshold' => $free_threshold,
		'currencySym'   => mcr_is_woocommerce_active() ? get_woocommerce_currency_symbol() : '$',
		'i18n'          => array(
			'added'        => __( 'In cart', 'mcr' ),
			'add'          => __( 'Add', 'mcr' ),
			'adding'       => __( 'Adding…', 'mcr' ),
			'error'        => __( 'Could not add that item. Please try again.', 'mcr' ),
			'cartEmpty'    => __( 'Your basket is empty.', 'mcr' ),
			'freeUnlocked' => __( 'Free delivery unlocked', 'mcr' ),
			/* translators: %s: formatted remaining amount, e.g. $12.50 */
			'freeRemain'   => __( '%s more for free delivery', 'mcr' ),
			'removed'      => __( 'Item removed.', 'mcr' ),
			'undo'         => __( 'Undo', 'mcr' ),
		),
	);
}

/**
 * Load the display, text and mono families.
 *
 * Self-hosted files under assets/fonts/ are preferred and used automatically
 * when present. Quebec's Law 25 and the GDPR both make the Google Fonts CDN a
 * data-transfer question, so self-hosting is the recommended production setup —
 * see docs/DEPLOY.md. The CDN is the fallback so the theme looks right the
 * moment it is activated.
 */
function mcr_enqueue_fonts(): void {
	$local = MCR_DIR . '/assets/fonts/fonts.css';

	if ( file_exists( $local ) ) {
		wp_enqueue_style( 'mcr-fonts', MCR_URI . '/assets/fonts/fonts.css', array(), mcr_asset_version( $local ) );
		return;
	}

	if ( ! apply_filters( 'mcr_use_google_fonts', true ) ) {
		return;
	}

	add_filter( 'wp_resource_hints', 'mcr_font_resource_hints', 10, 2 );

	wp_enqueue_style(
		'mcr-fonts',
		'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&family=Karla:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap',
		array(),
		null
	);
}

/**
 * Preconnect to the font CDN when it is in use.
 *
 * @param string[] $hints Hint URLs.
 * @param string   $relation Relation type.
 * @return string[]
 */
function mcr_font_resource_hints( array $hints, string $relation ): array {
	if ( 'preconnect' === $relation && wp_style_is( 'mcr-fonts', 'enqueued' ) ) {
		$hints[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
	}
	return $hints;
}

/**
 * Cache-busting version for an asset.
 *
 * Uses the file mtime in debug so local edits show up immediately, and the
 * theme version in production so browser caches stay warm across deploys.
 *
 * @param string $path Absolute path.
 */
function mcr_asset_version( string $path ): string {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $path ) ) {
		return (string) filemtime( $path );
	}
	return MCR_VERSION;
}

add_action( 'enqueue_block_editor_assets', 'mcr_editor_assets' );
/**
 * Give the block editor the same tokens as the front end.
 */
function mcr_editor_assets(): void {
	$tokens = MCR_DIR . '/assets/css/01-tokens.css';
	if ( file_exists( $tokens ) ) {
		wp_enqueue_style( 'mcr-editor-tokens', MCR_URI . '/assets/css/01-tokens.css', array(), mcr_asset_version( $tokens ) );
	}
}
