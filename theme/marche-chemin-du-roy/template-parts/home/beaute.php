<?php
/**
 * Beauty catalogue band — the indigo inversion from the artifact.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

if ( ! mcr_is_woocommerce_active() ) {
	return;
}

$mcr_root = (int) get_theme_mod( 'mcr_catalogue_beaute', 0 );
if ( ! $mcr_root ) {
	return;
}

$mcr_term = get_term( $mcr_root, 'product_cat' );
if ( ! $mcr_term instanceof WP_Term ) {
	return;
}

$mcr_products = wc_get_products( array(
	'status'     => 'publish',
	'limit'      => 5,
	'category'   => array( $mcr_term->slug ),
	'orderby'    => 'popularity',
	'visibility' => 'catalog',
) );

if ( ! $mcr_products ) {
	return;
}

$mcr_subcats = get_terms( array(
	'taxonomy'   => 'product_cat',
	'parent'     => $mcr_root,
	'hide_empty' => true,
	'number'     => 6,
) );
?>
<section class="band band--beaute">
	<div class="motif-layer motif" aria-hidden="true"></div>
	<div class="wrap">
		<div class="sec-head">
			<div>
				<span class="lbl"><?php esc_html_e( 'Beauty & hair', 'mcr' ); ?></span>
				<h2><?php echo esc_html( mcr_term_name( $mcr_term ) ); ?></h2>
				<?php if ( $mcr_term->description ) : ?>
					<p><?php echo esc_html( wp_strip_all_tags( $mcr_term->description ) ); ?></p>
				<?php endif; ?>
			</div>
			<a class="more" href="<?php echo esc_url( get_term_link( $mcr_term ) ); ?>">
				<?php esc_html_e( 'See the whole range', 'mcr' ); ?>
				<?php mcr_the_icon( 'arrow' ); ?>
			</a>
		</div>

		<?php if ( ! is_wp_error( $mcr_subcats ) && $mcr_subcats ) : ?>
			<div class="subcats">
				<?php foreach ( $mcr_subcats as $mcr_sub ) : ?>
					<a class="chip" href="<?php echo esc_url( get_term_link( $mcr_sub ) ); ?>">
						<?php echo esc_html( mcr_term_name( $mcr_sub ) ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="rail">
			<?php
			foreach ( $mcr_products as $mcr_product ) {
				mcr_product_card( $mcr_product );
			}
			?>
		</div>
	</div>
</section>
