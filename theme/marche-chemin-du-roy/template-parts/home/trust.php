<?php
/**
 * Trust strip.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

$mcr_threshold = mcr_free_delivery_threshold();
$mcr_radius    = (float) get_theme_mod( 'mcr_delivery_radius', 15 );

$mcr_items = array(
	array(
		'icon'  => 'truck',
		'title' => sprintf(
			/* translators: %s: formatted amount. */
			__( 'Free delivery over %s', 'mcr' ),
			wp_strip_all_tags( wc_price( $mcr_threshold ) )
		),
		'sub'   => sprintf(
			/* translators: %s: radius in kilometres. */
			__( 'Within %s km of the shop', 'mcr' ),
			number_format_i18n( $mcr_radius )
		),
	),
	array(
		'icon'  => 'bag',
		'title' => __( 'Pickup at the counter', 'mcr' ),
		'sub'   => __( 'Ready the same day, free', 'mcr' ),
	),
	array(
		'icon'  => 'check',
		'title' => __( 'Picked by hand', 'mcr' ),
		'sub'   => __( 'Fresh and frozen packed last', 'mcr' ),
	),
	array(
		'icon'  => 'globe',
		'title' => __( 'Bilingual service', 'mcr' ),
		'sub'   => __( 'Français · English', 'mcr' ),
	),
);
?>
<section class="trust">
	<div class="wrap">
		<?php foreach ( $mcr_items as $mcr_item ) : ?>
			<div class="trust-item">
				<?php mcr_the_icon( $mcr_item['icon'] ); ?>
				<div>
					<b><?php echo esc_html( $mcr_item['title'] ); ?></b>
					<span><?php echo esc_html( $mcr_item['sub'] ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</section>
