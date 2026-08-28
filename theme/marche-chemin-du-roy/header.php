<?php
/**
 * Site header: top bar, masthead and catalogue tabs.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link sr" href="#content"><?php esc_html_e( 'Skip to content', 'mcr' ); ?></a>

<div class="topbar">
	<div class="wrap">
		<span class="open-status">
			<?php if ( mcr_store_is_open() ) : ?>
				<span class="open-dot" aria-hidden="true"></span>
				<?php esc_html_e( 'Open now', 'mcr' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Closed right now', 'mcr' ); ?>
			<?php endif; ?>
		</span>

		<?php $address = get_theme_mod( 'mcr_address', '65 rue Notre-Dame E, Trois-Rivières' ); ?>
		<?php if ( $address ) : ?>
			<?php $map = get_theme_mod( 'mcr_map_url', '' ); ?>
			<?php if ( $map ) : ?>
				<a class="addr-lnk" href="<?php echo esc_url( $map ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $address ); ?>
				</a>
			<?php else : ?>
				<span class="addr-lnk"><?php echo esc_html( $address ); ?></span>
			<?php endif; ?>
		<?php endif; ?>

		<?php $phone = get_theme_mod( 'mcr_phone', '' ); ?>
		<?php if ( $phone ) : ?>
			<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>">
				<?php echo esc_html( $phone ); ?>
			</a>
		<?php endif; ?>

		<span class="spacer"></span>

		<?php mcr_language_switcher(); ?>
	</div>
</div>

<header class="hdr">
	<div class="wrap">
		<?php mcr_site_brand(); ?>

		<?php mcr_header_search(); ?>

		<div class="hdr-acts">
			<a class="iconbtn" href="<?php echo esc_url( mcr_account_url() ); ?>"
			   aria-label="<?php esc_attr_e( 'My account', 'mcr' ); ?>">
				<?php mcr_the_icon( 'user' ); ?>
			</a>

			<a class="iconbtn" href="<?php echo esc_url( mcr_wishlist_url() ); ?>"
			   aria-label="<?php esc_attr_e( 'My list', 'mcr' ); ?>" data-wishlist-link>
				<?php mcr_the_icon( 'heart' ); ?>
				<span class="badge num" data-wishlist-count hidden>0</span>
			</a>

			<?php if ( mcr_is_woocommerce_active() ) : ?>
				<a class="iconbtn" href="<?php echo esc_url( wc_get_cart_url() ); ?>"
				   aria-label="<?php esc_attr_e( 'Basket', 'mcr' ); ?>"
				   data-cart-open aria-haspopup="dialog" aria-expanded="false" aria-controls="cart-drawer">
					<?php mcr_the_icon( 'cart' ); ?>
					<?php echo mcr_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php mcr_catalogue_tabs(); ?>
