<?php
/**
 * Distance-banded local delivery.
 *
 * The artifact priced delivery in five bands by road distance from the shop and
 * refused orders past 15 km. That is reproduced here as a real WooCommerce
 * shipping method rather than front-end arithmetic, so the quoted price is the
 * charged price and tax, coupons and free-shipping rules all apply normally.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_shipping_init', 'mcr_shipping_method_init' );
/**
 * Define the shipping method class once WooCommerce shipping is loaded.
 */
function mcr_shipping_method_init(): void {

	if ( class_exists( 'MCR_Shipping_Distance' ) ) {
		return;
	}

	/**
	 * Distance-banded local delivery method.
	 */
	class MCR_Shipping_Distance extends WC_Shipping_Method {

		/**
		 * Constructor.
		 *
		 * @param int $instance_id Zone instance id.
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'mcr_distance';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Local delivery by distance', 'mcr' );
			$this->method_description = __( 'Prices delivery in bands by road distance from the shop, using the customer\'s postal code. Refuses addresses beyond the maximum radius.', 'mcr' );
			$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

			$this->init();
		}

		/**
		 * Load settings.
		 */
		public function init(): void {
			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->get_option( 'title', __( 'Local delivery', 'mcr' ) );

			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Instance settings.
		 */
		public function init_form_fields(): void {
			$this->instance_form_fields = array(
				'title'  => array(
					'title'       => __( 'Method title', 'mcr' ),
					'type'        => 'text',
					'description' => __( 'Shown to the customer at checkout.', 'mcr' ),
					'default'     => __( 'Local delivery', 'mcr' ),
					'desc_tip'    => true,
				),
				'bands'  => array(
					'title'       => __( 'Distance bands', 'mcr' ),
					'type'        => 'textarea',
					'description' => __( 'One band per line as <code>up-to-km | price</code>. Example: <code>5 | 5.99</code>. Bands are read in order; the first whose limit the distance falls within wins.', 'mcr' ),
					'default'     => "5 | 5.99\n9 | 6.99\n11 | 7.99\n13 | 8.99\n15 | 9.99",
					'css'         => 'height:120px',
				),
				'max_km' => array(
					'title'             => __( 'Maximum radius (km)', 'mcr' ),
					'type'              => 'number',
					'description'       => __( 'Addresses beyond this distance are not offered delivery.', 'mcr' ),
					'default'           => '15',
					'custom_attributes' => array( 'min' => '0', 'step' => '0.1' ),
					'desc_tip'          => true,
				),
			);
		}

		/**
		 * Offer a rate for the current package.
		 *
		 * @param array $package Shipping package.
		 */
		public function calculate_shipping( $package = array() ): void {
			$postcode = $package['destination']['postcode'] ?? '';
			$distance = mcr_distance_for_postcode( $postcode );

			// Unknown postcode: stay silent so other methods (pickup, flat rate)
			// can still quote, rather than blocking checkout outright.
			if ( null === $distance ) {
				return;
			}

			$max = (float) $this->get_option( 'max_km', 15 );
			if ( $distance > $max ) {
				return;
			}

			$band = $this->band_for( $distance );
			if ( null === $band ) {
				return;
			}

			$this->add_rate( array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => $band,
				'package' => $package,
				'meta_data' => array(
					__( 'Distance', 'mcr' ) => sprintf(
						/* translators: %s: distance in kilometres. */
						__( '≈ %s km from the shop', 'mcr' ),
						number_format_i18n( $distance, 1 )
					),
				),
			) );
		}

		/**
		 * Price for a distance, or null when out of range.
		 *
		 * @param float $distance Distance in km.
		 */
		private function band_for( float $distance ): ?float {
			foreach ( $this->bands() as $band ) {
				if ( $distance <= $band['limit'] ) {
					return $band['price'];
				}
			}
			return null;
		}

		/**
		 * Parsed bands.
		 *
		 * @return array<int,array{limit:float,price:float}>
		 */
		private function bands(): array {
			$raw   = (string) $this->get_option( 'bands', '' );
			$bands = array();

			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || ! str_contains( $line, '|' ) ) {
					continue;
				}
				list( $limit, $price ) = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( ! is_numeric( $limit ) || ! is_numeric( $price ) ) {
					continue;
				}
				$bands[] = array(
					'limit' => (float) $limit,
					'price' => (float) $price,
				);
			}

			usort( $bands, static fn( $a, $b ) => $a['limit'] <=> $b['limit'] );

			return $bands;
		}
	}
}

add_filter( 'woocommerce_shipping_methods', 'mcr_register_shipping_method' );
/**
 * Register the method with WooCommerce.
 *
 * @param array $methods Methods.
 * @return array
 */
function mcr_register_shipping_method( array $methods ): array {
	$methods['mcr_distance'] = 'MCR_Shipping_Distance';
	return $methods;
}

/**
 * Road distance in km for a Canadian postal code, or null if unknown.
 *
 * Ships with the artifact's forward-sortation-area table as a starting point.
 * Swap in a real routing lookup by filtering `mcr_distance_for_postcode` —
 * everything downstream only needs the number back.
 *
 * @param string $postcode Raw postcode.
 */
function mcr_distance_for_postcode( string $postcode ): ?float {
	$fsa = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $postcode ) ?? '' );
	$fsa = substr( $fsa, 0, 3 );

	$distance = null;

	if ( preg_match( '/^[A-Z]\d[A-Z]$/', $fsa ) ) {
		$table = apply_filters( 'mcr_fsa_distance_table', array(
			'G8T' => 1.2,  'G8Z' => 2.8,  'G9A' => 4.6,  'G8V' => 5.7,
			'G8Y' => 7.2,  'G8W' => 8.4,  'G9B' => 10.3, 'G9C' => 12.6,
			'G0X' => 14.1, 'G9H' => 16.9, 'G9N' => 32.0, 'G9P' => 34.5,
			'G9R' => 36.0, 'G9T' => 41.0,
		) );

		$distance = isset( $table[ $fsa ] ) ? (float) $table[ $fsa ] : null;
	}

	/**
	 * Filter the resolved distance.
	 *
	 * @param float|null $distance Distance in km, or null when unknown.
	 * @param string     $postcode Raw postcode as entered.
	 * @param string     $fsa      Normalised forward sortation area.
	 */
	return apply_filters( 'mcr_distance_for_postcode', $distance, $postcode, $fsa );
}
