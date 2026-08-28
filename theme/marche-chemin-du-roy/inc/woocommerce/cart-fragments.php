<?php
/**
 * Live cart updates.
 *
 * Add-to-cart rides WooCommerce's own `?wc-ajax=add_to_cart` endpoint rather
 * than a bespoke one, so stock checks, sold-individually rules, cart hooks and
 * third-party plugins all behave exactly as they do on the product page.
 * The theme's job is only to declare which regions get replaced afterwards.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_add_to_cart_fragments', 'mcr_cart_fragments' );
/**
 * Regions WooCommerce refreshes after any cart change.
 *
 * @param array $fragments Existing fragments.
 * @return array
 */
function mcr_cart_fragments( array $fragments ): array {
	$fragments['[data-cart-count]']    = mcr_cart_count_html();
	$fragments['[data-cart-drawer]']   = mcr_cart_drawer_html();
	$fragments['[data-cart-subtotal]'] = mcr_cart_subtotal_html();

	return $fragments;
}

/**
 * Cart count badge.
 */
function mcr_cart_count_html(): string {
	$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;

	ob_start();
	printf(
		'<span class="badge" data-cart-count%s>%s</span>',
		$count > 0 ? '' : ' hidden',
		esc_html( number_format_i18n( $count ) )
	);
	return (string) ob_get_clean();
}

/**
 * Cart subtotal, used by the drawer footer.
 */
function mcr_cart_subtotal_html(): string {
	ob_start();
	echo '<span class="t num" data-cart-subtotal>';
	echo WC()->cart ? wp_kses_post( WC()->cart->get_cart_subtotal() ) : '';
	echo '</span>';
	return (string) ob_get_clean();
}

/**
 * The slide-in basket.
 *
 * Mirrors the artifact's `.sheet`: a bottom sheet on phones, a right-hand
 * drawer from 700px up.
 */
function mcr_cart_drawer_html(): string {
	ob_start();
	?>
	<div class="sheet-body" data-cart-drawer>
		<?php if ( ! WC()->cart || WC()->cart->is_empty() ) : ?>
			<p class="empty"><?php esc_html_e( 'Your basket is empty.', 'mcr' ); ?></p>
		<?php else : ?>
			<div class="items">
				<?php
				foreach ( WC()->cart->get_cart() as $key => $item ) {
					$product = $item['data'] ?? null;
					if ( ! $product instanceof WC_Product || ! apply_filters( 'woocommerce_widget_cart_item_visible', true, $item, $key ) ) {
						continue;
					}
					mcr_cart_drawer_line( $key, $item, $product );
				}
				?>
			</div>
			<?php mcr_free_delivery_progress(); ?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * One line in the basket drawer.
 *
 * @param string     $key     Cart item key.
 * @param array      $item    Cart item.
 * @param WC_Product $product Product.
 */
function mcr_cart_drawer_line( string $key, array $item, WC_Product $product ): void {
	$permalink = $product->is_visible() ? $product->get_permalink( $item ) : '';
	$quantity  = (int) $item['quantity'];
	?>
	<div class="line">
		<span class="th">
			<?php
			if ( $product->get_image_id() ) {
				echo wp_get_attachment_image( $product->get_image_id(), 'woocommerce_gallery_thumbnail', false, array( 'alt' => '' ) );
			} else {
				$terms = get_the_terms( $product->get_id(), 'product_cat' );
				mcr_the_icon( ( is_array( $terms ) && $terms ) ? mcr_aisle_icon( $terms[0] ) : 'a-epicerie' );
			}
			?>
		</span>

		<span class="nm">
			<?php if ( $permalink ) : ?>
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $product->get_name() ); ?>
			<?php endif; ?>

			<?php
			// Variation attributes, e.g. "26 pouces · Natural 1B".
			$meta = wc_get_formatted_cart_item_data( $item, true );
			if ( $meta ) {
				echo '<em class="qt">' . esc_html( $meta ) . '</em>';
			}
			?>
			<em class="qt">&times; <?php echo esc_html( number_format_i18n( $quantity ) ); ?></em>
		</span>

		<span class="pr num"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $quantity ) ); ?></span>

		<a href="<?php echo esc_url( wc_get_cart_remove_url( $key ) ); ?>"
		   class="line-x"
		   aria-label="<?php
			/* translators: %s: product name. */
			echo esc_attr( sprintf( __( 'Remove %s from basket', 'mcr' ), $product->get_name() ) );
			?>"
		   data-product_id="<?php echo esc_attr( (string) $product->get_id() ); ?>"
		   data-cart_item_key="<?php echo esc_attr( $key ); ?>">
			<?php mcr_the_icon( 'x' ); ?>
		</a>
	</div>
	<?php
}

add_action( 'wp_footer', 'mcr_render_cart_drawer' );
/**
 * Print the drawer, its scrim and the announcement region.
 */
function mcr_render_cart_drawer(): void {
	if ( ! mcr_is_woocommerce_active() || is_cart() || is_checkout() ) {
		return;
	}
	?>
	<div class="scrim" data-cart-scrim hidden></div>

	<aside class="sheet" id="cart-drawer" data-cart-sheet
	       role="dialog" aria-modal="true"
	       aria-label="<?php esc_attr_e( 'Your basket', 'mcr' ); ?>" hidden>
		<header>
			<h3><?php esc_html_e( 'Your basket', 'mcr' ); ?></h3>
			<button type="button" class="iconbtn" data-cart-close aria-label="<?php esc_attr_e( 'Close basket', 'mcr' ); ?>">
				<?php mcr_the_icon( 'x' ); ?>
			</button>
		</header>

		<?php
		// Replaced wholesale by the cart fragment above on every change.
		echo mcr_cart_drawer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>

		<footer>
			<div class="totals">
				<span><?php esc_html_e( 'Subtotal', 'mcr' ); ?></span>
				<?php echo mcr_cart_subtotal_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<a class="btn btn-primary" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
				<?php mcr_the_icon( 'shield' ); ?>
				<?php esc_html_e( 'Checkout', 'mcr' ); ?>
			</a>
			<a class="btn btn-ghost btn-quiet" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
				<?php esc_html_e( 'View basket', 'mcr' ); ?>
			</a>
		</footer>
	</aside>

	<?php // Cart changes are announced here for screen readers. ?>
	<div class="sr" role="status" aria-live="polite" data-cart-announce></div>
	<?php
}
