<?php
/**
 * Customizer settings.
 *
 * Everything the artifact hard-coded — address, phone, hours, hero copy,
 * delivery threshold — is editable here, so the shop is not dependent on a
 * developer for a phone number change.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'customize_register', 'mcr_customize_register' );
/**
 * Register panels, sections and controls.
 *
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function mcr_customize_register( WP_Customize_Manager $wp_customize ): void {
	$wp_customize->add_panel( 'mcr_store', array(
		'title'    => __( 'Marché Chemin-du-Roy', 'mcr' ),
		'priority' => 20,
	) );

	/* ---------- Store details ---------- */
	$wp_customize->add_section( 'mcr_store_details', array(
		'title' => __( 'Store details', 'mcr' ),
		'panel' => 'mcr_store',
	) );

	$fields = array(
		'mcr_address'  => array( __( 'Street address', 'mcr' ), '65 rue Notre-Dame E, Trois-Rivières', 'text' ),
		'mcr_phone'    => array( __( 'Phone number', 'mcr' ), '819 372-9966', 'text' ),
		'mcr_whatsapp' => array( __( 'WhatsApp number (digits only)', 'mcr' ), '', 'text' ),
		'mcr_email'    => array( __( 'Contact email', 'mcr' ), '', 'email' ),
		'mcr_map_url'  => array( __( 'Map link', 'mcr' ), '', 'url' ),
	);

	foreach ( $fields as $id => $config ) {
		list( $label, $default, $type ) = $config;

		$wp_customize->add_setting( $id, array(
			'default'           => $default,
			'sanitize_callback' => 'url' === $type ? 'esc_url_raw' : ( 'email' === $type ? 'sanitize_email' : 'sanitize_text_field' ),
			'transport'         => 'refresh',
		) );

		$wp_customize->add_control( $id, array(
			'label'   => $label,
			'section' => 'mcr_store_details',
			'type'    => 'url' === $type ? 'url' : ( 'email' === $type ? 'email' : 'text' ),
		) );
	}

	/* ---------- Catalogues ---------- */
	$wp_customize->add_section( 'mcr_catalogues', array(
		'title'       => __( 'Catalogues', 'mcr' ),
		'panel'       => 'mcr_store',
		'description' => __( 'The beauty catalogue uses the indigo palette. Pick the top-level product category that holds it.', 'mcr' ),
	) );

	$wp_customize->add_setting( 'mcr_catalogue_beaute', array(
		'default'           => 0,
		'sanitize_callback' => 'absint',
	) );

	$wp_customize->add_control( 'mcr_catalogue_beaute', array(
		'label'   => __( 'Beauty catalogue category', 'mcr' ),
		'section' => 'mcr_catalogues',
		'type'    => 'select',
		'choices' => mcr_product_category_choices(),
	) );

	/* ---------- Delivery ---------- */
	$wp_customize->add_section( 'mcr_delivery', array(
		'title'       => __( 'Delivery', 'mcr' ),
		'panel'       => 'mcr_store',
		'description' => __( 'Used for the free-delivery progress bar. When a WooCommerce free-shipping method has a minimum order amount, that value wins so the bar can never disagree with checkout.', 'mcr' ),
	) );

	$wp_customize->add_setting( 'mcr_free_threshold', array(
		'default'           => 99,
		'sanitize_callback' => 'mcr_sanitize_amount',
	) );

	$wp_customize->add_control( 'mcr_free_threshold', array(
		'label'       => __( 'Free delivery from', 'mcr' ),
		'section'     => 'mcr_delivery',
		'type'        => 'number',
		'input_attrs' => array( 'min' => 0, 'step' => 1 ),
	) );

	$wp_customize->add_setting( 'mcr_delivery_radius', array(
		'default'           => 15,
		'sanitize_callback' => 'mcr_sanitize_amount',
	) );

	$wp_customize->add_control( 'mcr_delivery_radius', array(
		'label'       => __( 'Delivery radius (km)', 'mcr' ),
		'section'     => 'mcr_delivery',
		'type'        => 'number',
		'input_attrs' => array( 'min' => 0, 'step' => 1 ),
	) );

	/* ---------- Hero ---------- */
	$wp_customize->add_section( 'mcr_hero', array(
		'title' => __( 'Home hero', 'mcr' ),
		'panel' => 'mcr_store',
	) );

	$hero = array(
		'mcr_hero_eyebrow' => __( 'Eyebrow', 'mcr' ),
		'mcr_hero_title'   => __( 'Headline', 'mcr' ),
		'mcr_hero_sub'     => __( 'Subheading', 'mcr' ),
	);

	foreach ( $hero as $id => $label ) {
		$wp_customize->add_setting( $id, array(
			'default'           => '',
			'sanitize_callback' => 'wp_kses_post',
			'transport'         => 'postMessage',
		) );

		$wp_customize->add_control( $id, array(
			'label'       => $label,
			'section'     => 'mcr_hero',
			'type'        => 'mcr_hero_title' === $id ? 'textarea' : 'text',
			'description' => 'mcr_hero_title' === $id
				? __( 'Wrap a word in <em> to print it in gold.', 'mcr' )
				: '',
		) );
	}

	if ( isset( $wp_customize->selective_refresh ) ) {
		foreach ( array_keys( $hero ) as $id ) {
			$wp_customize->selective_refresh->add_partial( $id, array(
				'selector'        => '.hero-main [data-partial="' . $id . '"]',
				'render_callback' => static fn() => get_theme_mod( $id, '' ),
			) );
		}
	}
}

/**
 * Sanitize a positive amount.
 *
 * @param mixed $value Raw value.
 */
function mcr_sanitize_amount( $value ): float {
	return max( 0, (float) $value );
}

/**
 * Product categories as Customizer select choices.
 *
 * @return array<int|string,string>
 */
function mcr_product_category_choices(): array {
	$choices = array( 0 => __( '— None —', 'mcr' ) );

	if ( ! mcr_is_woocommerce_active() || ! taxonomy_exists( 'product_cat' ) ) {
		return $choices;
	}

	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'parent'     => 0,
	) );

	if ( is_wp_error( $terms ) ) {
		return $choices;
	}

	foreach ( $terms as $term ) {
		$choices[ $term->term_id ] = $term->name;
	}

	return $choices;
}
