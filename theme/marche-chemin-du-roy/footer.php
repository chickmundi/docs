<?php
/**
 * Site footer and the mobile tab bar.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;
?>

<footer class="foot">
	<div class="wrap foot-grid">
		<div>
			<?php mcr_site_brand( true ); ?>
			<?php $about = get_theme_mod( 'mcr_footer_about', get_bloginfo( 'description' ) ); ?>
			<?php if ( $about ) : ?>
				<p class="about"><?php echo wp_kses_post( $about ); ?></p>
			<?php endif; ?>
		</div>

		<?php
		$columns = array(
			'footer-1' => __( 'Shop', 'mcr' ),
			'footer-2' => __( 'Orders', 'mcr' ),
			'footer-3' => __( 'The shop', 'mcr' ),
		);

		foreach ( $columns as $location => $heading ) :
			if ( ! has_nav_menu( $location ) ) {
				continue;
			}
			?>
			<div>
				<h4><?php echo esc_html( $heading ); ?></h4>
				<?php
				wp_nav_menu( array(
					'theme_location' => $location,
					'container'      => false,
					'menu_class'     => '',
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
				?>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="wrap">
		<div class="foot-bot">
			<span>
				<?php
				printf(
					/* translators: 1: current year, 2: site name. */
					esc_html__( '© %1$s %2$s', 'mcr' ),
					esc_html( wp_date( 'Y' ) ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</span>

			<span class="spacer"></span>

			<?php if ( mcr_is_woocommerce_active() ) : ?>
				<div class="pay" aria-label="<?php esc_attr_e( 'Accepted payment methods', 'mcr' ); ?>">
					<?php foreach ( mcr_payment_labels() as $label ) : ?>
						<span><?php echo esc_html( $label ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</footer>

<?php mcr_mobile_tabbar(); ?>

<?php wp_footer(); ?>
</body>
</html>
