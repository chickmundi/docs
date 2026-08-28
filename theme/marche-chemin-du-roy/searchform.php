<?php
/**
 * Search form.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

$mcr_field_id = 'mcr-search-' . wp_unique_id();
?>
<form class="search search--block" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="sr" for="<?php echo esc_attr( $mcr_field_id ); ?>"><?php esc_html_e( 'Search', 'mcr' ); ?></label>
	<input id="<?php echo esc_attr( $mcr_field_id ); ?>" type="search" name="s"
	       value="<?php echo esc_attr( get_search_query() ); ?>"
	       placeholder="<?php esc_attr_e( 'Search the shop…', 'mcr' ); ?>">
	<?php if ( mcr_is_woocommerce_active() ) : ?>
		<input type="hidden" name="post_type" value="product">
	<?php endif; ?>
	<button class="go" type="submit" aria-label="<?php esc_attr_e( 'Search', 'mcr' ); ?>">
		<?php mcr_the_icon( 'search', array( 'width' => 16, 'height' => 16 ) ); ?>
	</button>
</form>
