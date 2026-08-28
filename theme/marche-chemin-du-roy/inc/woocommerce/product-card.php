<?php
/**
 * The product card.
 *
 * One renderer for every grid on the site — shop, category, rail, related,
 * search, cross-sells — so a change to the card is a change in one place.
 * Mirrors the artifact's `.card` markup exactly.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render one product card.
 *
 * @param WC_Product|null $product Product. Defaults to the loop's product.
 */
function mcr_product_card( ?WC_Product $product = null ): void {
	$product = $product ?: ( $GLOBALS['product'] ?? null );

	if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
		return;
	}

	$id        = $product->get_id();
	$permalink = get_permalink( $id );
	$title     = $product->get_name();

	?>
	<article <?php wc_product_class( 'card', $product ); ?>>
		<a class="hit" href="<?php echo esc_url( $permalink ); ?>">
			<span class="sr"><?php echo esc_html( $title ); ?></span>
		</a>

		<div class="shot"<?php echo mcr_card_tint_attr( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php mcr_card_badge( $product ); ?>
			<?php mcr_card_image( $product ); ?>
		</div>

		<div class="body">
			<?php mcr_card_format_line( $product ); ?>
			<h3><?php echo esc_html( $title ); ?></h3>

			<div class="pricerow">
				<span class="p num"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			</div>

			<?php mcr_card_add_to_cart( $product ); ?>
		</div>
	</article>
	<?php
}

/**
 * Sale / new / local badge.
 *
 * @param WC_Product $product Product.
 */
function mcr_card_badge( WC_Product $product ): void {
	if ( $product->is_on_sale() ) {
		printf( '<span class="tag">%s</span>', esc_html__( 'SALE', 'mcr' ) );
		return;
	}

	// "New" is derived from publish date, so nobody has to remember to clear it.
	$new_for_days = (int) apply_filters( 'mcr_new_product_days', 30 );
	$published    = $product->get_date_created();

	if ( $published && $new_for_days > 0 ) {
		$age_days = ( time() - $published->getTimestamp() ) / DAY_IN_SECONDS;
		if ( $age_days <= $new_for_days ) {
			printf( '<span class="tag tag--green">%s</span>', esc_html__( 'NEW', 'mcr' ) );
			return;
		}
	}

	if ( has_term( 'local', 'product_tag', $product->get_id() ) || has_term( 'dici', 'product_tag', $product->get_id() ) ) {
		printf( '<span class="tag tag--green">%s</span>', esc_html__( 'LOCAL', 'mcr' ) );
	}
}

/**
 * Card image, or the aisle icon when a product has no photo.
 *
 * The artifact had no photography at all, so an icon fallback is not a
 * degraded state here — it is the established look.
 *
 * @param WC_Product $product Product.
 */
function mcr_card_image( WC_Product $product ): void {
	if ( $product->get_image_id() ) {
		echo wp_get_attachment_image(
			$product->get_image_id(),
			'mcr-card',
			false,
			array(
				'class'   => 'card-img',
				'loading' => 'lazy',
				'alt'     => $product->get_name(),
			)
		);
		return;
	}

	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	$icon  = ( is_array( $terms ) && $terms ) ? mcr_aisle_icon( $terms[0] ) : 'a-epicerie';

	mcr_the_icon( $icon );
}

/**
 * Format line: brand and pack size, from product attributes.
 *
 * @param WC_Product $product Product.
 */
function mcr_card_format_line( WC_Product $product ): void {
	$parts = array();

	foreach ( array( 'pa_marque', 'pa_brand' ) as $taxonomy ) {
		$brand = $product->get_attribute( $taxonomy );
		if ( $brand ) {
			$parts[] = $brand;
			break;
		}
	}

	foreach ( array( 'pa_format', 'pa_size' ) as $taxonomy ) {
		$format = $product->get_attribute( $taxonomy );
		if ( $format ) {
			$parts[] = $format;
			break;
		}
	}

	if ( ! $parts ) {
		return;
	}

	printf( '<span class="fmt">%s</span>', esc_html( implode( ' · ', $parts ) ) );
}

/**
 * Add-to-cart control.
 *
 * Simple in-stock products add over AJAX without leaving the grid. Anything
 * needing a choice — a variable product, or one sold in several lengths —
 * links to the product page instead of guessing a variant. The artifact added
 * the first option at the cheapest variant's price, which could charge one
 * variant's price for another.
 *
 * @param WC_Product $product Product.
 */
function mcr_card_add_to_cart( WC_Product $product ): void {
	if ( ! $product->is_in_stock() ) {
		printf(
			'<a class="add add--out" href="%s">%s</a>',
			esc_url( get_permalink( $product->get_id() ) ),
			esc_html__( 'Out of stock', 'mcr' )
		);
		return;
	}

	$needs_choice = ! $product->is_type( 'simple' ) || ! $product->is_purchasable();

	if ( $needs_choice ) {
		printf(
			'<a class="add add--choose" href="%s">%s%s</a>',
			esc_url( get_permalink( $product->get_id() ) ),
			mcr_icon( 'arrow' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Choose options', 'mcr' )
		);
		return;
	}

	printf(
		'<button type="button" class="add" data-add-to-cart="%d" aria-label="%s">%s%s</button>',
		(int) $product->get_id(),
		/* translators: %s: product name. */
		esc_attr( sprintf( __( 'Add %s to basket', 'mcr' ), $product->get_name() ) ),
		mcr_icon( 'plus' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Add', 'mcr' )
	);
}

/**
 * Deterministic background tint for the image well.
 *
 * Same hash as the artifact's tint(): the same product always gets the same
 * colour, and a shelf of them looks varied rather than striped.
 *
 * @param WC_Product $product Product.
 */
function mcr_card_tint_attr( WC_Product $product ): string {
	$tints = array(
		'var(--paper-2)',
		'var(--gold-soft)',
		'var(--green-soft)',
		'var(--surface-2)',
		'var(--accent-soft)',
	);

	$slug = $product->get_slug();
	$hash = 0;
	$len  = strlen( $slug );

	for ( $i = 0; $i < $len; $i++ ) {
		$hash = ( $hash * 31 + ord( $slug[ $i ] ) ) % 997;
	}

	return ' style="background:' . esc_attr( $tints[ $hash % count( $tints ) ] ) . '"';
}
