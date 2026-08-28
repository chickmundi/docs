<?php
/**
 * Hero side promos — the two featured products.
 *
 * Driven by the "featured" flag in WooCommerce, so the shop controls what
 * appears here from the products screen.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

if ( ! mcr_is_woocommerce_active() ) {
	return;
}

$mcr_featured = wc_get_products( array(
	'status'   => 'publish',
	'limit'    => 2,
	'featured' => true,
	'orderby'  => 'date',
	'order'    => 'DESC',
) );

if ( ! $mcr_featured ) {
	return;
}

foreach ( $mcr_featured as $index => $mcr_product ) :
	$mcr_beauty = 1 === $index;
	?>
	<a class="promo<?php echo $mcr_beauty ? ' promo--beaute' : ''; ?>" href="<?php echo esc_url( $mcr_product->get_permalink() ); ?>">
		<h3><?php echo esc_html( $mcr_product->get_name() ); ?></h3>

		<?php if ( $mcr_product->get_short_description() ) : ?>
			<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $mcr_product->get_short_description() ), 16 ) ); ?></p>
		<?php endif; ?>

		<div class="price">
			<b><?php echo wp_kses_post( wc_price( (float) $mcr_product->get_price() ) ); ?></b>
			<?php if ( $mcr_product->is_on_sale() && $mcr_product->get_regular_price() ) : ?>
				<s><?php echo wp_kses_post( wc_price( (float) $mcr_product->get_regular_price() ) ); ?></s>
			<?php endif; ?>
		</div>

		<span class="cta">
			<?php esc_html_e( 'See the product', 'mcr' ); ?>
			<?php mcr_the_icon( 'arrow' ); ?>
		</span>
	</a>
	<?php
endforeach;
