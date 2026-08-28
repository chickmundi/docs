<?php
/**
 * Template helpers.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which top-level catalogue the current view belongs to: 'cuisine' or 'beaute'.
 *
 * The artifact hard-coded two catalogues. Here they are resolved from the
 * top-level product categories chosen in the Customizer, so the split survives
 * renaming and a third catalogue is a settings change.
 */
function mcr_current_catalogue(): string {
	if ( ! mcr_is_woocommerce_active() ) {
		return 'cuisine';
	}

	$beauty_root = (int) get_theme_mod( 'mcr_catalogue_beaute', 0 );
	if ( ! $beauty_root ) {
		return 'cuisine';
	}

	$term = null;

	if ( is_product_category() ) {
		$term = get_queried_object();
	} elseif ( is_singular( 'product' ) ) {
		$terms = get_the_terms( get_the_ID(), 'product_cat' );
		$term  = ( is_array( $terms ) && $terms ) ? $terms[0] : null;
	}

	if ( ! $term instanceof WP_Term ) {
		return 'cuisine';
	}

	if ( $term->term_id === $beauty_root ) {
		return 'beaute';
	}

	$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );

	return in_array( $beauty_root, array_map( 'intval', $ancestors ), true ) ? 'beaute' : 'cuisine';
}

/**
 * Free-delivery threshold in store currency.
 *
 * Read from the "free shipping" method's own minimum-order setting when one is
 * configured, so the progress bar can never contradict what checkout charges.
 * That desync is the classic version of this bug.
 */
function mcr_free_delivery_threshold(): float {
	$fallback = (float) get_theme_mod( 'mcr_free_threshold', 99 );

	if ( ! mcr_is_woocommerce_active() ) {
		return $fallback;
	}

	$cached = wp_cache_get( 'mcr_free_threshold', 'mcr' );
	if ( false !== $cached ) {
		return (float) $cached;
	}

	$threshold = $fallback;

	/*
	 * Walk every shipping zone, plus zone 0 ("locations not covered"), and take
	 * the lowest free-shipping minimum on offer. WC_Shipping_Zones is the
	 * public API for this; WC()->shipping() only knows about method classes.
	 */
	if ( class_exists( 'WC_Shipping_Zones' ) ) {
		$minimums = array();

		$zone_ids = array_map(
			static fn( array $zone ): int => (int) $zone['zone_id'],
			WC_Shipping_Zones::get_zones()
		);
		$zone_ids[] = 0;

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' !== $method->id ) {
					continue;
				}
				$minimum = (float) ( $method->get_option( 'min_amount' ) ?: 0 );
				if ( $minimum > 0 ) {
					$minimums[] = $minimum;
				}
			}
		}

		if ( $minimums ) {
			$threshold = min( $minimums );
		}
	}

	wp_cache_set( 'mcr_free_threshold', $threshold, 'mcr', HOUR_IN_SECONDS );

	return $threshold;
}

/**
 * Breadcrumb trail matching the artifact's `.crumbs`.
 *
 * Yoast and Rank Math are handed the job when either is active, so the site has
 * exactly one source of breadcrumb schema.
 */
function mcr_breadcrumbs(): void {
	if ( is_front_page() ) {
		return;
	}

	if ( function_exists( 'yoast_breadcrumb' ) ) {
		yoast_breadcrumb( '<nav class="crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'mcr' ) . '">', '</nav>' );
		return;
	}

	if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
		echo '<nav class="crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'mcr' ) . '">';
		rank_math_the_breadcrumbs();
		echo '</nav>';
		return;
	}

	$items = mcr_breadcrumb_items();
	if ( count( $items ) < 2 ) {
		return;
	}

	$last = count( $items ) - 1;

	echo '<nav class="crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'mcr' ) . '">';
	foreach ( $items as $i => $item ) {
		if ( $i > 0 ) {
			mcr_the_icon( 'arrow' );
		}
		if ( $i === $last || empty( $item['url'] ) ) {
			printf( '<b aria-current="page">%s</b>', esc_html( $item['label'] ) );
		} else {
			printf( '<a href="%s">%s</a>', esc_url( $item['url'] ), esc_html( $item['label'] ) );
		}
	}
	echo '</nav>';
}

/**
 * Build the breadcrumb trail.
 *
 * @return array<int,array{label:string,url:string}>
 */
function mcr_breadcrumb_items(): array {
	$items = array(
		array(
			'label' => __( 'Home', 'mcr' ),
			'url'   => home_url( '/' ),
		),
	);

	if ( mcr_is_woocommerce_active() && ( is_shop() || is_product_taxonomy() || is_singular( 'product' ) ) ) {
		$shop_id = wc_get_page_id( 'shop' );
		if ( $shop_id > 0 && ! is_shop() ) {
			$items[] = array(
				'label' => get_the_title( $shop_id ),
				'url'   => get_permalink( $shop_id ),
			);
		}
	}

	if ( is_singular( 'product' ) ) {
		$terms = get_the_terms( get_the_ID(), 'product_cat' );
		if ( is_array( $terms ) && $terms ) {
			// Deepest term reads most naturally in a trail.
			usort( $terms, static fn( $a, $b ) => count( get_ancestors( $b->term_id, 'product_cat' ) ) <=> count( get_ancestors( $a->term_id, 'product_cat' ) ) );
			$term = $terms[0];
			foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor instanceof WP_Term ) {
					$items[] = array(
						'label' => mcr_term_name( $ancestor ),
						'url'   => get_term_link( $ancestor ),
					);
				}
			}
			$items[] = array(
				'label' => mcr_term_name( $term ),
				'url'   => get_term_link( $term ),
			);
		}
		$items[] = array(
			'label' => get_the_title(),
			'url'   => '',
		);

		return $items;
	}

	if ( is_product_taxonomy() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );
				if ( $ancestor instanceof WP_Term ) {
					$items[] = array(
						'label' => mcr_term_name( $ancestor ),
						'url'   => get_term_link( $ancestor ),
					);
				}
			}
			$items[] = array(
				'label' => mcr_term_name( $term ),
				'url'   => '',
			);
		}
		return $items;
	}

	if ( is_search() ) {
		$items[] = array(
			/* translators: %s: search query. */
			'label' => sprintf( __( 'Search: %s', 'mcr' ), get_search_query() ),
			'url'   => '',
		);
		return $items;
	}

	if ( is_singular() ) {
		$items[] = array(
			'label' => get_the_title(),
			'url'   => '',
		);
		return $items;
	}

	if ( is_archive() ) {
		$items[] = array(
			'label' => wp_strip_all_tags( get_the_archive_title() ),
			'url'   => '',
		);
	}

	return $items;
}

/**
 * Translated term name, via the companion plugin when present.
 *
 * @param WP_Term $term Term.
 */
function mcr_term_name( WP_Term $term ): string {
	if ( function_exists( 'mcr_translate_term_field' ) ) {
		return mcr_translate_term_field( $term, 'name' );
	}
	return $term->name;
}

/**
 * Store opening hours.
 *
 * @return array<int,array{label:string,hours:string,open:int,close:int}>
 */
function mcr_store_hours(): array {
	$default = array(
		array( 'label' => __( 'Monday', 'mcr' ),    'hours' => '8:30 – 21:00', 'open' => 510, 'close' => 1260 ),
		array( 'label' => __( 'Tuesday', 'mcr' ),   'hours' => '8:30 – 21:00', 'open' => 510, 'close' => 1260 ),
		array( 'label' => __( 'Wednesday', 'mcr' ), 'hours' => '8:30 – 21:00', 'open' => 510, 'close' => 1260 ),
		array( 'label' => __( 'Thursday', 'mcr' ),  'hours' => '8:30 – 22:00', 'open' => 510, 'close' => 1320 ),
		array( 'label' => __( 'Friday', 'mcr' ),    'hours' => '8:30 – 22:00', 'open' => 510, 'close' => 1320 ),
		array( 'label' => __( 'Saturday', 'mcr' ),  'hours' => '8:30 – 22:00', 'open' => 510, 'close' => 1320 ),
		array( 'label' => __( 'Sunday', 'mcr' ),    'hours' => '9:00 – 20:00', 'open' => 540, 'close' => 1200 ),
	);

	return apply_filters( 'mcr_store_hours', $default );
}

/**
 * Whether the shop is open right now, in the site's timezone.
 *
 * Uses wp_timezone() rather than the server clock — a host in another region
 * would otherwise show "open" at 3am local.
 */
function mcr_store_is_open(): bool {
	$now     = new DateTimeImmutable( 'now', wp_timezone() );
	$hours   = mcr_store_hours();
	$weekday = (int) $now->format( 'N' ) - 1; // Monday = 0.

	if ( ! isset( $hours[ $weekday ] ) ) {
		return false;
	}

	$minutes = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );

	return $minutes >= $hours[ $weekday ]['open'] && $minutes < $hours[ $weekday ]['close'];
}

/**
 * Index of today's row in mcr_store_hours().
 */
function mcr_today_index(): int {
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	return (int) $now->format( 'N' ) - 1;
}

/**
 * Site brand lockup — custom logo when set, wordmark otherwise.
 *
 * @param bool $footer Whether this is the footer variant.
 */
function mcr_site_brand( bool $footer = false ): void {
	$classes = 'brand' . ( $footer ? ' brand--foot' : '' );
	?>
	<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
		<?php if ( has_custom_logo() ) : ?>
			<?php
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			echo wp_get_attachment_image( $logo_id, 'full', false, array(
				'class' => 'mark mark--logo',
				'alt'   => get_bloginfo( 'name' ),
			) );
			?>
		<?php else : ?>
			<span class="mark"><?php mcr_the_icon( 'bag' ); ?></span>
		<?php endif; ?>

		<span class="txt">
			<b><?php echo wp_kses_post( mcr_brand_name_html() ); ?></b>
			<span><?php echo esc_html( get_theme_mod( 'mcr_brand_locality', 'Trois-Rivières' ) ); ?></span>
		</span>
	</a>
	<?php
}

/**
 * Site name with a line break before the last word, as in the artifact.
 */
function mcr_brand_name_html(): string {
	$name  = get_bloginfo( 'name', 'display' );
	$parts = preg_split( '/\s+/', trim( $name ), 2 );

	if ( count( $parts ) < 2 ) {
		return esc_html( $name );
	}

	return esc_html( $parts[0] ) . '<br>' . esc_html( $parts[1] );
}

/**
 * Header search, scoped to products when WooCommerce is active.
 */
function mcr_header_search(): void {
	$catalogues = mcr_catalogue_terms();
	?>
	<form class="search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php if ( $catalogues ) : ?>
			<span class="scope">
				<label class="sr" for="mcr-scope"><?php esc_html_e( 'Aisle', 'mcr' ); ?></label>
				<select id="mcr-scope" name="product_cat">
					<option value=""><?php esc_html_e( 'Whole store', 'mcr' ); ?></option>
					<?php foreach ( $catalogues as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>"
							<?php selected( get_query_var( 'product_cat' ), $term->slug ); ?>>
							<?php echo esc_html( mcr_term_name( $term ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</span>
		<?php endif; ?>

		<label class="sr" for="mcr-q"><?php esc_html_e( 'Search', 'mcr' ); ?></label>
		<input id="mcr-q" type="search" name="s" autocomplete="off"
		       value="<?php echo esc_attr( get_search_query() ); ?>"
		       placeholder="<?php esc_attr_e( 'Gari, attiéké, palm oil, wigs…', 'mcr' ); ?>">

		<?php if ( mcr_is_woocommerce_active() ) : ?>
			<input type="hidden" name="post_type" value="product">
		<?php endif; ?>

		<button class="go" type="submit" aria-label="<?php esc_attr_e( 'Search', 'mcr' ); ?>">
			<?php mcr_the_icon( 'search', array( 'width' => 16, 'height' => 16 ) ); ?>
		</button>
	</form>
	<?php
}

/**
 * Top-level product categories, used as catalogue tabs and search scopes.
 *
 * @return WP_Term[]
 */
function mcr_catalogue_terms(): array {
	if ( ! mcr_is_woocommerce_active() || ! taxonomy_exists( 'product_cat' ) ) {
		return array();
	}

	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'orderby'    => 'menu_order',
	) );

	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * The catalogue tab strip.
 *
 * Uses the "primary" menu when one is assigned so the shop controls the order,
 * and falls back to top-level product categories otherwise.
 */
function mcr_catalogue_tabs(): void {
	$has_menu = has_nav_menu( 'primary' );
	$terms    = $has_menu ? array() : mcr_catalogue_terms();

	if ( ! $has_menu && ! $terms ) {
		return;
	}
	?>
	<nav class="switch" aria-label="<?php esc_attr_e( 'Catalogues', 'mcr' ); ?>">
		<div class="wrap">
			<?php
			if ( $has_menu ) {
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'container'      => false,
					'items_wrap'     => '%3$s',
					'depth'          => 1,
					'walker'         => new MCR_Tab_Walker(),
					'fallback_cb'    => false,
				) );
			} else {
				$current = get_queried_object();
				foreach ( $terms as $term ) {
					$is_current = ( $current instanceof WP_Term && $current->term_id === $term->term_id );
					printf(
						'<a class="tab" href="%s" aria-selected="%s"%s>%s%s</a>',
						esc_url( get_term_link( $term ) ),
						$is_current ? 'true' : 'false',
						$is_current ? ' aria-current="page"' : '',
						mcr_icon( mcr_aisle_icon( $term ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( mcr_term_name( $term ) )
					);
				}
			}
			?>

			<?php if ( mcr_is_woocommerce_active() ) : ?>
				<div class="right">
					<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Basket', 'mcr' ); ?></a>
					<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Checkout', 'mcr' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
	</nav>
	<?php
}

/**
 * My-account URL, or the login page when WooCommerce is absent.
 */
function mcr_account_url(): string {
	if ( mcr_is_woocommerce_active() ) {
		$page = wc_get_page_permalink( 'myaccount' );
		if ( $page ) {
			return $page;
		}
	}
	return wp_login_url();
}

/**
 * Wishlist page URL.
 *
 * Favourites are stored in the visitor's own browser, matching the artifact.
 * They are deliberately not account-bound: that would need a data-retention
 * decision the shop has not made.
 */
function mcr_wishlist_url(): string {
	$page_id = (int) get_theme_mod( 'mcr_wishlist_page', 0 );

	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return (string) get_permalink( $page_id );
	}

	return add_query_arg( 'mcr-list', '1', home_url( '/' ) );
}

/**
 * Payment method labels for the footer strip.
 *
 * Reads the gateways actually enabled at checkout, so the badges cannot
 * advertise a method the store has since switched off.
 *
 * @return string[]
 */
function mcr_payment_labels(): array {
	if ( ! mcr_is_woocommerce_active() ) {
		return array();
	}

	$labels = array();

	foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
		$title = wp_strip_all_tags( $gateway->get_title() );
		if ( $title ) {
			$labels[] = mb_strtoupper( $title );
		}
	}

	return array_slice( array_unique( $labels ), 0, 5 );
}

/**
 * Fixed bottom navigation on phones.
 */
function mcr_mobile_tabbar(): void {
	$shop_url = mcr_is_woocommerce_active() ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
	?>
	<nav class="tabbar" aria-label="<?php esc_attr_e( 'Mobile navigation', 'mcr' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="<?php echo is_front_page() ? 'on' : ''; ?>">
			<?php mcr_the_icon( 'home' ); ?>
			<span><?php esc_html_e( 'Home', 'mcr' ); ?></span>
		</a>

		<a href="<?php echo esc_url( $shop_url ); ?>" class="<?php echo ( mcr_is_woocommerce_active() && ( is_shop() || is_product_taxonomy() ) ) ? 'on' : ''; ?>">
			<?php mcr_the_icon( 'grid' ); ?>
			<span><?php esc_html_e( 'Aisles', 'mcr' ); ?></span>
		</a>

		<a href="<?php echo esc_url( mcr_wishlist_url() ); ?>">
			<?php mcr_the_icon( 'heart' ); ?>
			<span><?php esc_html_e( 'My list', 'mcr' ); ?></span>
		</a>

		<?php if ( mcr_is_woocommerce_active() ) : ?>
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"
			   class="<?php echo is_cart() ? 'on' : ''; ?>"
			   data-cart-open aria-haspopup="dialog" aria-expanded="false" aria-controls="cart-drawer">
				<?php mcr_the_icon( 'cart' ); ?>
				<span><?php esc_html_e( 'Basket', 'mcr' ); ?></span>
				<?php echo mcr_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
		<?php endif; ?>
	</nav>
	<?php
}
