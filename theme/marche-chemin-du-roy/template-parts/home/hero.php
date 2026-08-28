<?php
/**
 * Home hero.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

$mcr_eyebrow = get_theme_mod( 'mcr_hero_eyebrow', __( 'African & Caribbean grocery · Trois-Rivières', 'mcr' ) );
$mcr_title   = get_theme_mod( 'mcr_hero_title', __( 'The flavours of home, <em>two streets away</em>', 'mcr' ) );
$mcr_sub     = get_theme_mod( 'mcr_hero_sub', __( 'Gari, attiéké, palm oil, fresh fish, wigs and hair care — picked in store and delivered across Trois-Rivières.', 'mcr' ) );
$mcr_shop    = mcr_is_woocommerce_active() ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
?>
<section class="hero">
	<div class="wrap hero-grid">
		<div class="hero-main">
			<div class="motif-layer motif" aria-hidden="true"></div>
			<div class="glow" aria-hidden="true"></div>
			<div class="glow2" aria-hidden="true"></div>

			<?php if ( $mcr_eyebrow ) : ?>
				<span class="eyebrow" data-partial="mcr_hero_eyebrow"><?php echo wp_kses_post( $mcr_eyebrow ); ?></span>
			<?php endif; ?>

			<h1 data-partial="mcr_hero_title"><?php echo wp_kses_post( $mcr_title ); ?></h1>

			<?php if ( $mcr_sub ) : ?>
				<p class="sub" data-partial="mcr_hero_sub"><?php echo wp_kses_post( $mcr_sub ); ?></p>
			<?php endif; ?>

			<div class="hero-cta">
				<a class="btn btn-primary" href="<?php echo esc_url( $mcr_shop ); ?>">
					<?php mcr_the_icon( 'bag' ); ?>
					<?php esc_html_e( 'Shop the aisles', 'mcr' ); ?>
				</a>
				<?php $mcr_map = get_theme_mod( 'mcr_map_url', '' ); ?>
				<?php if ( $mcr_map ) : ?>
					<a class="btn btn-ghost" href="<?php echo esc_url( $mcr_map ); ?>" target="_blank" rel="noopener noreferrer">
						<?php mcr_the_icon( 'pin' ); ?>
						<?php esc_html_e( 'Find the shop', 'mcr' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="hero-side">
			<?php get_template_part( 'template-parts/home/promo' ); ?>
		</div>
	</div>
</section>
