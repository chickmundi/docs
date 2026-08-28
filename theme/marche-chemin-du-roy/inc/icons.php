<?php
/**
 * SVG icon sprite.
 *
 * The artifact inlined 45 symbols in one hidden <svg> and referenced them with
 * <use href="#i-cart">. That is kept: one HTTP request, icons inherit
 * currentColor, and no icon font.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_body_open', 'mcr_print_icon_sprite', 1 );
/**
 * Print the sprite once, immediately inside <body>.
 */
function mcr_print_icon_sprite(): void {
	static $printed = false;

	if ( $printed ) {
		return;
	}
	$printed = true;

	$sprite = MCR_DIR . '/assets/icons/sprite.svg';
	if ( ! file_exists( $sprite ) ) {
		return;
	}

	/*
	 * Read rather than <img>/<use href="file.svg#id">: cross-file sprite
	 * references do not inherit currentColor in Safari, and the icons are
	 * two-tone with the palette.
	 */
	$svg = file_get_contents( $sprite ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	// Author-controlled file shipped with the theme; no user data passes through.
	echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render an icon.
 *
 * @param string $name  Symbol id without the prefix, e.g. 'cart' or 'a-epicerie'.
 * @param array  $attrs Optional. class, width, height, title.
 */
function mcr_icon( string $name, array $attrs = array() ): string {
	$id = str_starts_with( $name, 'a-' ) || str_starts_with( $name, 'b-' ) ? $name : 'i-' . $name;

	$class  = isset( $attrs['class'] ) ? ' class="' . esc_attr( $attrs['class'] ) . '"' : '';
	$width  = isset( $attrs['width'] ) ? ' width="' . esc_attr( (string) $attrs['width'] ) . '"' : '';
	$height = isset( $attrs['height'] ) ? ' height="' . esc_attr( (string) $attrs['height'] ) . '"' : '';

	/*
	 * An icon with a title is exposed to assistive tech; a decorative one is
	 * hidden. Getting this wrong is the most common icon a11y failure, so the
	 * signature forces a choice.
	 */
	if ( ! empty( $attrs['title'] ) ) {
		$title_id = 'icon-' . $id . '-' . wp_unique_id();
		return sprintf(
			'<svg%s%s%s role="img" aria-labelledby="%s"><title id="%s">%s</title><use href="#%s"></use></svg>',
			$class,
			$width,
			$height,
			esc_attr( $title_id ),
			esc_attr( $title_id ),
			esc_html( $attrs['title'] ),
			esc_attr( $id )
		);
	}

	return sprintf(
		'<svg%s%s%s aria-hidden="true" focusable="false"><use href="#%s"></use></svg>',
		$class,
		$width,
		$height,
		esc_attr( $id )
	);
}

/**
 * Echo an icon.
 *
 * @param string $name  Symbol id.
 * @param array  $attrs Attributes.
 */
function mcr_the_icon( string $name, array $attrs = array() ): void {
	// mcr_icon() escapes every interpolated value.
	echo mcr_icon( $name, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Icon slug for a product category, matching the artifact's aisle icons.
 *
 * Resolution order: a per-term choice saved in term meta, then a slug-keyed
 * default, then a generic bag. Stores can therefore add an aisle without code.
 *
 * @param WP_Term|int|null $term Category term.
 */
function mcr_aisle_icon( $term = null ): string {
	$term = $term instanceof WP_Term ? $term : ( $term ? get_term( $term ) : null );

	if ( $term instanceof WP_Term ) {
		$saved = get_term_meta( $term->term_id, '_mcr_icon', true );
		if ( $saved ) {
			return (string) $saved;
		}
	}

	$defaults = array(
		'epicerie'   => 'a-epicerie',
		'farines'    => 'a-farines',
		'epices'     => 'a-epices',
		'surgeles'   => 'a-surgeles',
		'poissons'   => 'a-poissons',
		'viandes'    => 'a-viandes',
		'fruits'     => 'a-fruits',
		'conserves'  => 'a-conserves',
		'dejeuner'   => 'a-dejeuner',
		'garde'      => 'a-garde',
		'perruques'  => 'b-perruque',
		'extensions' => 'b-extension',
		'lace'       => 'b-lace',
		'bonnets'    => 'b-bonnet',
		'soins'      => 'b-soins',
		'corps'      => 'b-corps',
	);

	$slug = $term instanceof WP_Term ? $term->slug : '';

	if ( isset( $defaults[ $slug ] ) ) {
		return $defaults[ $slug ];
	}

	// Partial match so 'epices-et-condiments' still finds the spice icon.
	foreach ( $defaults as $key => $icon ) {
		if ( $slug && str_contains( $slug, $key ) ) {
			return $icon;
		}
	}

	return 'a-epicerie';
}
