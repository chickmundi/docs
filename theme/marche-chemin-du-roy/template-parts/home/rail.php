<?php
/**
 * A horizontal product rail.
 *
 * @package MCR
 *
 * @var array $args Passed from get_template_part(): title, lead, orderby, category.
 */

defined( 'ABSPATH' ) || exit;

if ( ! mcr_is_woocommerce_active() ) {
	return;
}

$mcr_title   = $args['title'] ?? __( 'Picked for you', 'mcr' );
$mcr_lead    = $args['lead'] ?? '';
$mcr_orderby = $args['orderby'] ?? 'date';
$mcr_cat     = $args['category'] ?? '';

$mcr_query_args = array(
	'status'  => 'publish',
	'limit'   => 5,
	'orderby' => $mcr_orderby,
	'order'   => 'DESC',
	'visibility' => 'catalog',
);

if ( $mcr_cat ) {
	$mcr_query_args['category'] = array( $mcr_cat );
}

$mcr_products = wc_get_products( $mcr_query_args );

if ( ! $mcr_products ) {
	return;
}
?>
<section class="band" style="padding-top:0">
	<div class="wrap">
		<div class="sec-head">
			<div>
				<h2><?php echo esc_html( $mcr_title ); ?></h2>
				<?php if ( $mcr_lead ) : ?>
					<p><?php echo esc_html( $mcr_lead ); ?></p>
				<?php endif; ?>
			</div>
			<a class="more" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
				<?php esc_html_e( 'See all', 'mcr' ); ?>
				<?php mcr_the_icon( 'arrow' ); ?>
			</a>
		</div>

		<div class="rail">
			<?php
			foreach ( $mcr_products as $mcr_product ) {
				mcr_product_card( $mcr_product );
			}
			?>
		</div>
	</div>
</section>
