<?php
/**
 * Empty listing.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package MCR
 * @version 7.8.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="empty-state">
	<?php mcr_the_icon( 'search', array( 'width' => 32, 'height' => 32 ) ); ?>
	<h3><?php esc_html_e( 'Nothing on this shelf yet', 'mcr' ); ?></h3>
	<p><?php esc_html_e( 'No products matched these filters. Try clearing one, or browse another aisle.', 'mcr' ); ?></p>
	<p>
		<a class="btn btn-primary" href="<?php echo esc_url( mcr_listing_base_url() ); ?>">
			<?php esc_html_e( 'Clear filters', 'mcr' ); ?>
		</a>
	</p>
</div>
