<?php
/**
 * Menu walkers.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Header aisle menu: renders each top-level item as an artifact `.tab`,
 * marking the current one with aria-selected so the red underline follows the
 * user rather than being hard-coded.
 */
class MCR_Tab_Walker extends Walker_Nav_Menu {

	/**
	 * Start an element.
	 *
	 * @param string   $output Walker output, by reference.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     Item ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		if ( $depth > 0 ) {
			return; // The tab strip is single-level by design.
		}

		$current = in_array( 'current-menu-item', (array) $item->classes, true )
			|| in_array( 'current-menu-ancestor', (array) $item->classes, true );

		$icon = '';
		if ( 'taxonomy' === $item->type && 'product_cat' === $item->object ) {
			$icon = mcr_icon( mcr_aisle_icon( (int) $item->object_id ) );
		}

		$output .= sprintf(
			'<a class="tab" href="%s" aria-selected="%s"%s>%s%s</a>',
			esc_url( $item->url ),
			$current ? 'true' : 'false',
			$current ? ' aria-current="page"' : '',
			$icon,
			esc_html( $item->title )
		);
	}

	/**
	 * No closing tag needed — start_el emits a complete anchor.
	 *
	 * @param string   $output Output.
	 * @param WP_Post  $item   Item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {}

	/**
	 * The tab strip has no sub-lists.
	 *
	 * @param string   $output Output.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {}

	/**
	 * No sub-list close.
	 *
	 * @param string   $output Output.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {}
}
