<?php
/**
 * WooCommerce-specific bilingual behaviour.
 *
 * @package MCR_Bilingue
 */

defined( 'ABSPATH' ) || exit;

/**
 * Price formatting, order language and emails.
 */
final class MCR_WooCommerce_Bilingual {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Order meta key recording the language an order was placed in.
	 */
	public const ORDER_META = '_mcr_order_language';

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Hook up.
	 */
	public function init(): void {
		add_filter( 'wc_price_args', array( $this, 'price_args' ) );
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_range_prefix' ), 10, 2 );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'record_order_language' ), 10, 2 );
		add_filter( 'woocommerce_email_setup_locale', array( $this, 'email_locale' ) );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'show_order_language' ) );
	}

	/**
	 * Format prices for the displayed language.
	 *
	 * Quebec French writes 12,99 $ — comma decimal, space, trailing sign.
	 * Canadian English writes $12.99. The source artifact printed the French
	 * form in both languages; this is where that is corrected, and it applies
	 * everywhere WooCommerce formats a price.
	 *
	 * @param array $args Price formatting arguments.
	 * @return array
	 */
	public function price_args( array $args ): array {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $args;
		}

		if ( 'en' === MCR_Languages::instance()->current() ) {
			$args['decimal_separator']  = '.';
			$args['thousand_separator'] = ',';
			$args['price_format']       = '%1$s%2$s'; // $12.99
		} else {
			$args['decimal_separator']  = ',';
			$args['thousand_separator'] = ' ';
			$args['price_format']       = '%2$s&nbsp;%1$s'; // 12,99 $
		}

		return $args;
	}

	/**
	 * Prefix variable-product price ranges with "from".
	 *
	 * The artifact printed "dès" on every variable product even when all its
	 * variants cost the same, which read as a discount that did not exist.
	 * Here the word only appears when the range is genuinely a range.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 */
	public function price_range_prefix( string $html, $product ): string {
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
			return $html;
		}

		$prices = $product->get_variation_prices( true );
		if ( empty( $prices['price'] ) ) {
			return $html;
		}

		$min = (float) current( $prices['price'] );
		$max = (float) end( $prices['price'] );

		if ( $min >= $max ) {
			return $html;
		}

		return '<span class="from">' . esc_html__( 'from', 'mcr-bilingue' ) . '</span> ' . $html;
	}

	/**
	 * Stamp the order with the language it was placed in.
	 *
	 * Staff then know which language to write back in, and follow-up emails
	 * reach the customer in the language they actually shopped in.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted checkout data.
	 */
	public function record_order_language( $order, $data ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, MCR_Languages::instance()->current() );
	}

	/**
	 * Send each order email in the language of that order.
	 *
	 * @param bool $setup Whether WooCommerce should switch locale.
	 */
	public function email_locale( $setup ): bool {
		return true;
	}

	/**
	 * Show the order language in the admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public function show_order_language( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$language = $order->get_meta( self::ORDER_META );
		if ( ! $language ) {
			return;
		}

		$languages = MCR_Languages::instance()->languages();
		$name      = $languages[ $language ]['name'] ?? $language;

		printf(
			'<p class="form-field form-field-wide"><strong>%s</strong><br>%s</p>',
			esc_html__( 'Order language', 'mcr-bilingue' ),
			esc_html( $name )
		);
	}
}
