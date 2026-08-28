<?php
/**
 * Listing chrome: subcategory chips and the filter rail.
 *
 * Price and attribute filtering is delegated to WooCommerce's own query layer
 * (`min_price`, `max_price`, `filter_pa_*`), which WC_Query already understands.
 * Only the two filters Woo has no native parameter for — on sale, in stock —
 * are handled here.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Child-category chips above the grid.
 */
function mcr_subcategory_chips(): void {
	if ( ! is_product_taxonomy() && ! is_shop() ) {
		return;
	}

	$parent = 0;

	if ( is_product_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$parent = $term->term_id;
		}
	}

	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => $parent,
		'hide_empty' => true,
	) );

	if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
		return;
	}

	echo '<div class="subcats">';

	if ( $parent ) {
		printf(
			'<a class="chip" href="%s" aria-pressed="true">%s</a>',
			esc_url( get_term_link( $parent, 'product_cat' ) ),
			esc_html__( 'All', 'mcr' )
		);
	}

	foreach ( $terms as $term ) {
		printf(
			'<a class="chip" href="%s" aria-pressed="false">%s <span class="c num">%s</span></a>',
			esc_url( get_term_link( $term ) ),
			esc_html( mcr_term_name( $term ) ),
			esc_html( number_format_i18n( $term->count ) )
		);
	}

	echo '</div>';
}

/**
 * The filter rail.
 *
 * A plain GET form: it works with JavaScript disabled, every state is
 * linkable and shareable, and the back button behaves. The artifact's version
 * was JS-only and lost its state on reload.
 */
function mcr_filter_rail(): void {
	if ( ! is_shop() && ! is_product_taxonomy() ) {
		return;
	}

	$action = mcr_listing_base_url();
	?>
	<aside class="filters" id="filters" aria-label="<?php esc_attr_e( 'Filters', 'mcr' ); ?>">
		<form method="get" action="<?php echo esc_url( $action ); ?>" class="filters-form">
			<div class="fhead">
				<h3><?php esc_html_e( 'Filters', 'mcr' ); ?></h3>
				<button type="button" class="iconbtn" id="fclose" aria-label="<?php esc_attr_e( 'Close filters', 'mcr' ); ?>">
					<?php mcr_the_icon( 'x' ); ?>
				</button>
			</div>

			<?php
			// Preserve search and sort across a filter submission.
			foreach ( array( 's', 'post_type', 'orderby', 'product_cat' ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					printf(
						'<input type="hidden" name="%s" value="%s">',
						esc_attr( $key ),
						esc_attr( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
				}
			}
			?>

			<section class="fgroup">
				<h4><?php esc_html_e( 'Availability', 'mcr' ); ?></h4>

				<label class="fopt">
					<input type="checkbox" name="mcr_stock" value="in" <?php checked( mcr_filter_value( 'mcr_stock' ), 'in' ); ?>>
					<span><?php esc_html_e( 'In stock only', 'mcr' ); ?></span>
				</label>

				<label class="fopt">
					<input type="checkbox" name="mcr_sale" value="1" <?php checked( mcr_filter_value( 'mcr_sale' ), '1' ); ?>>
					<span><?php esc_html_e( 'On sale', 'mcr' ); ?></span>
				</label>
			</section>

			<?php mcr_price_filter(); ?>
			<?php mcr_attribute_filters(); ?>

			<div class="fbar-mobile">
				<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Apply', 'mcr' ); ?></button>
				<a class="btn btn-ghost btn-quiet" href="<?php echo esc_url( $action ); ?>"><?php esc_html_e( 'Clear', 'mcr' ); ?></a>
			</div>
		</form>

		<?php if ( is_active_sidebar( 'shop-filters' ) ) : ?>
			<div class="fgroup-widgets"><?php dynamic_sidebar( 'shop-filters' ); ?></div>
		<?php endif; ?>
	</aside>
	<?php
}

/**
 * Canonical URL of the current listing, without filter parameters.
 */
function mcr_listing_base_url(): string {
	if ( is_product_taxonomy() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				return $link;
			}
		}
	}

	return (string) wc_get_page_permalink( 'shop' );
}

/**
 * Read a filter value from the query string.
 *
 * @param string $key Parameter name.
 */
function mcr_filter_value( string $key ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state.
	return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
}

/**
 * Price range inputs, bounded by the catalogue's real min and max.
 */
function mcr_price_filter(): void {
	$range = mcr_price_range();

	if ( $range['max'] <= $range['min'] ) {
		return;
	}
	?>
	<section class="fgroup">
		<h4><?php esc_html_e( 'Price', 'mcr' ); ?></h4>
		<div class="frange">
			<label class="sr" for="mcr-min-price"><?php esc_html_e( 'Minimum price', 'mcr' ); ?></label>
			<input id="mcr-min-price" type="number" name="min_price" inputmode="decimal"
			       min="<?php echo esc_attr( (string) $range['min'] ); ?>"
			       max="<?php echo esc_attr( (string) $range['max'] ); ?>"
			       placeholder="<?php echo esc_attr( (string) $range['min'] ); ?>"
			       value="<?php echo esc_attr( mcr_filter_value( 'min_price' ) ); ?>">
			<span aria-hidden="true">—</span>
			<label class="sr" for="mcr-max-price"><?php esc_html_e( 'Maximum price', 'mcr' ); ?></label>
			<input id="mcr-max-price" type="number" name="max_price" inputmode="decimal"
			       min="<?php echo esc_attr( (string) $range['min'] ); ?>"
			       max="<?php echo esc_attr( (string) $range['max'] ); ?>"
			       placeholder="<?php echo esc_attr( (string) $range['max'] ); ?>"
			       value="<?php echo esc_attr( mcr_filter_value( 'max_price' ) ); ?>">
		</div>
	</section>
	<?php
}

/**
 * Cheapest and dearest product price in the catalogue.
 *
 * @return array{min:int,max:int}
 */
function mcr_price_range(): array {
	$cached = get_transient( 'mcr_price_range' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$max = (float) $wpdb->get_var(
		"SELECT MAX(max_price) FROM {$wpdb->wc_product_meta_lookup}"
	);
	$min = (float) $wpdb->get_var(
		"SELECT MIN(min_price) FROM {$wpdb->wc_product_meta_lookup} WHERE min_price > 0"
	);

	$range = array(
		'min' => (int) floor( $min ),
		'max' => (int) ceil( $max ),
	);

	set_transient( 'mcr_price_range', $range, HOUR_IN_SECONDS );

	return $range;
}

/**
 * Checkbox groups for attributes flagged "used for filtering".
 *
 * Emits `filter_pa_*`, which WC_Query already applies — no custom query code.
 */
function mcr_attribute_filters(): void {
	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		/**
		 * Whether to offer this attribute as a filter.
		 *
		 * @param bool   $show     Default true.
		 * @param string $taxonomy Attribute taxonomy.
		 */
		if ( ! apply_filters( 'mcr_show_attribute_filter', true, $taxonomy ) ) {
			continue;
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		) );

		if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
			continue;
		}

		$chosen = array_filter( explode( ',', mcr_filter_value( 'filter_' . $attribute->attribute_name ) ) );
		?>
		<section class="fgroup">
			<h4><?php echo esc_html( $attribute->attribute_label ); ?></h4>
			<?php foreach ( $terms as $term ) : ?>
				<label class="fopt">
					<input type="checkbox"
					       name="filter_<?php echo esc_attr( $attribute->attribute_name ); ?>[]"
					       value="<?php echo esc_attr( $term->slug ); ?>"
						<?php checked( in_array( $term->slug, $chosen, true ) ); ?>>
					<span><?php echo esc_html( mcr_term_name( $term ) ); ?></span>
					<span class="c num"><?php echo esc_html( number_format_i18n( $term->count ) ); ?></span>
				</label>
			<?php endforeach; ?>
		</section>
		<?php
	}
}

add_filter( 'woocommerce_product_query_meta_query', 'mcr_stock_filter_meta_query', 10, 2 );
/**
 * Apply the "in stock only" filter.
 *
 * @param array    $meta_query Meta query.
 * @param WC_Query $query      Woo query object.
 * @return array
 */
function mcr_stock_filter_meta_query( array $meta_query, $query ): array {
	if ( 'in' !== mcr_filter_value( 'mcr_stock' ) ) {
		return $meta_query;
	}

	$meta_query[] = array(
		'key'     => '_stock_status',
		'value'   => 'instock',
		'compare' => '=',
	);

	return $meta_query;
}

add_filter( 'woocommerce_product_query_post_in', 'mcr_sale_filter_post_in' );
/**
 * Apply the "on sale" filter.
 *
 * @param array $ids Post IDs already constrained by other filters.
 * @return array
 */
function mcr_sale_filter_post_in( array $ids ): array {
	if ( '1' !== mcr_filter_value( 'mcr_sale' ) ) {
		return $ids;
	}

	$on_sale = wc_get_product_ids_on_sale();

	// Empty post__in returns everything, so fall back to an impossible ID.
	if ( ! $on_sale ) {
		return array( 0 );
	}

	return $ids ? array_intersect( $ids, $on_sale ) : $on_sale;
}

add_action( 'woocommerce_product_set_stock', 'mcr_flush_price_range' );
add_action( 'woocommerce_variation_set_stock', 'mcr_flush_price_range' );
add_action( 'woocommerce_update_product', 'mcr_flush_price_range' );
/**
 * Drop the cached price range when the catalogue changes.
 */
function mcr_flush_price_range(): void {
	delete_transient( 'mcr_price_range' );
	wp_cache_delete( 'mcr_free_threshold', 'mcr' );
}

add_filter( 'woocommerce_product_loop_start', 'mcr_product_loop_start' );
/**
 * Add the theme's grid class to WooCommerce's loop wrapper.
 *
 * The <ul class="products"> element is kept — plugins and Woo's own scripts
 * select on it — and the layout class is added alongside rather than replacing
 * the markup with a bespoke wrapper.
 *
 * @param string $html Loop opening markup.
 */
function mcr_product_loop_start( string $html ): string {
	return str_replace( 'class="products', 'class="grid products', $html );
}
