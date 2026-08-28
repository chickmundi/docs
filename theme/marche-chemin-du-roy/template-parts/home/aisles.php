<?php
/**
 * Aisle grid.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

if ( ! mcr_is_woocommerce_active() ) {
	return;
}

$mcr_beauty_root = (int) get_theme_mod( 'mcr_catalogue_beaute', 0 );

$mcr_aisles = get_terms( array(
	'taxonomy'   => 'product_cat',
	'hide_empty' => true,
	'parent'     => 0,
	'number'     => 10,
) );

if ( is_wp_error( $mcr_aisles ) || ! $mcr_aisles ) {
	return;
}

// Second level reads as the real aisle list when the shop uses catalogues.
if ( count( $mcr_aisles ) <= 2 ) {
	$mcr_children = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'parent'     => $mcr_aisles[0]->term_id,
		'number'     => 10,
	) );

	if ( ! is_wp_error( $mcr_children ) && $mcr_children ) {
		$mcr_aisles = $mcr_children;
	}
}
?>
<section class="band" id="rayons">
	<div class="wrap">
		<div class="sec-head">
			<div>
				<span class="lbl"><?php esc_html_e( 'Browse', 'mcr' ); ?></span>
				<h2><?php esc_html_e( 'Every aisle in the shop', 'mcr' ); ?></h2>
				<p><?php esc_html_e( 'From cassava flour to frozen fish and hair care — the whole shop, aisle by aisle.', 'mcr' ); ?></p>
			</div>
			<?php if ( mcr_is_woocommerce_active() ) : ?>
				<a class="more" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
					<?php esc_html_e( 'See everything', 'mcr' ); ?>
					<?php mcr_the_icon( 'arrow' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="aisles" style="--cols:<?php echo esc_attr( (string) min( 5, max( 2, count( $mcr_aisles ) ) ) ); ?>">
			<?php foreach ( $mcr_aisles as $mcr_aisle ) : ?>
				<a class="aisle" href="<?php echo esc_url( get_term_link( $mcr_aisle ) ); ?>">
					<span class="ill"><?php mcr_the_icon( mcr_aisle_icon( $mcr_aisle ) ); ?></span>
					<span class="nm">
						<b><?php echo esc_html( mcr_term_name( $mcr_aisle ) ); ?></b>
						<small><?php
							printf(
								/* translators: %s: number of products. */
								esc_html( _n( '%s item', '%s items', $mcr_aisle->count, 'mcr' ) ),
								esc_html( number_format_i18n( $mcr_aisle->count ) )
							);
						?></small>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
