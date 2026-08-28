<?php
/**
 * Theme supports, menus and image sizes.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'mcr_setup' );
/**
 * Register theme features.
 */
function mcr_setup(): void {
	load_theme_textdomain( 'mcr', MCR_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'wp-block-styles' );
	add_editor_style( 'assets/css/editor.css' );

	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	add_theme_support( 'custom-logo', array(
		'height'      => 76,
		'width'       => 76,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	/*
	 * Product imagery. The artifact drew flat SVG icons at 1:1; real photography
	 * keeps that square crop so the grid never reflows when a photo is swapped in.
	 */
	add_image_size( 'mcr-card', 520, 520, true );
	add_image_size( 'mcr-rail', 380, 380, true );
	add_image_size( 'mcr-gallery', 1000, 1000, true );

	register_nav_menus( array(
		'primary'   => __( 'Main aisles (header)', 'mcr' ),
		'utility'   => __( 'Utility links (top bar)', 'mcr' ),
		'footer-1'  => __( 'Footer — Shop', 'mcr' ),
		'footer-2'  => __( 'Footer — Services', 'mcr' ),
		'footer-3'  => __( 'Footer — About', 'mcr' ),
	) );

	// The artifact's layout is fluid to 1180px; tell the editor the same.
	add_theme_support( 'align-wide' );
}

add_action( 'after_setup_theme', 'mcr_content_width', 0 );
/**
 * Content width used by embeds and oEmbed.
 */
function mcr_content_width(): void {
	$GLOBALS['content_width'] = 1180;
}

add_action( 'widgets_init', 'mcr_widgets_init' );
/**
 * Widget areas. Deliberately few — the design is template-driven, not widgetised.
 */
function mcr_widgets_init(): void {
	register_sidebar( array(
		'name'          => __( 'Shop sidebar (filters)', 'mcr' ),
		'id'            => 'shop-filters',
		'description'   => __( 'Shown in the filter rail on shop and category pages.', 'mcr' ),
		'before_widget' => '<section id="%1$s" class="fgroup %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h4>',
		'after_title'   => '</h4>',
	) );
}

add_filter( 'body_class', 'mcr_body_class' );
/**
 * Body classes the stylesheet keys off.
 *
 * @param string[] $classes Existing classes.
 * @return string[]
 */
function mcr_body_class( array $classes ): array {
	$classes[] = 'mcr';
	$classes[] = 'mcr-lang-' . mcr_lang();

	if ( mcr_is_woocommerce_active() && ( is_shop() || is_product_taxonomy() ) ) {
		$classes[] = 'mcr-listing';
	}

	/*
	 * The beauty catalogue inverts to the indigo palette, matching the
	 * artifact's `.band--beaute`. Driven by the top-level product category so
	 * the store can rename or add aisles without a code change.
	 */
	if ( mcr_is_woocommerce_active() && mcr_current_catalogue() === 'beaute' ) {
		$classes[] = 'mcr-catalogue-beaute';
	}

	return $classes;
}
