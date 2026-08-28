<?php
/**
 * Empty state.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="empty-state">
	<?php mcr_the_icon( 'search', array( 'width' => 32, 'height' => 32 ) ); ?>
	<h3><?php esc_html_e( 'Nothing found', 'mcr' ); ?></h3>
	<p><?php esc_html_e( 'We could not find anything matching that. Try a different spelling or browse the aisles.', 'mcr' ); ?></p>
	<?php get_search_form(); ?>
</div>
