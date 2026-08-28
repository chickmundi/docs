<?php
/**
 * Store information: hours, address and services.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;

$mcr_hours   = mcr_store_hours();
$mcr_today   = mcr_today_index();
$mcr_address = get_theme_mod( 'mcr_address', '' );
$mcr_phone   = get_theme_mod( 'mcr_phone', '' );
$mcr_map     = get_theme_mod( 'mcr_map_url', '' );
?>
<section class="band" style="padding-top:0" id="magasin">
	<div class="wrap store-grid">
		<div class="panel">
			<h3><?php esc_html_e( 'Opening hours', 'mcr' ); ?></h3>
			<table class="hours">
				<tbody>
					<?php foreach ( $mcr_hours as $mcr_index => $mcr_row ) : ?>
						<tr<?php echo $mcr_index === $mcr_today ? ' class="now"' : ''; ?>>
							<td><?php echo esc_html( $mcr_row['label'] ); ?></td>
							<td><?php echo esc_html( $mcr_row['hours'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="panel">
			<h3><?php esc_html_e( 'Find us', 'mcr' ); ?></h3>
			<p class="addr">
				<?php if ( $mcr_address ) : ?>
					<b><?php echo esc_html( $mcr_address ); ?></b><br>
				<?php endif; ?>
				<?php if ( $mcr_phone ) : ?>
					<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $mcr_phone ) ); ?>">
						<?php echo esc_html( $mcr_phone ); ?>
					</a>
				<?php endif; ?>
			</p>

			<?php if ( $mcr_map ) : ?>
				<a class="maplike" href="<?php echo esc_url( $mcr_map ); ?>" target="_blank" rel="noopener noreferrer"
				   aria-label="<?php esc_attr_e( 'Open the map', 'mcr' ); ?>">
					<span class="pin"><?php mcr_the_icon( 'pin' ); ?></span>
				</a>
			<?php else : ?>
				<div class="maplike" aria-hidden="true"><span class="pin"><?php mcr_the_icon( 'pin' ); ?></span></div>
			<?php endif; ?>
		</div>

		<div class="panel">
			<h3><?php esc_html_e( 'Services', 'mcr' ); ?></h3>
			<?php
			$mcr_services = array(
				array( 'truck', __( 'Local delivery', 'mcr' ), __( 'Priced by distance, seven days a week.', 'mcr' ) ),
				array( 'bag', __( 'Order and collect', 'mcr' ), __( 'Ready at the counter the same day.', 'mcr' ) ),
				array( 'box', __( 'Special orders', 'mcr' ), __( 'Ask us for anything you cannot find.', 'mcr' ) ),
			);

			foreach ( $mcr_services as $mcr_service ) :
				?>
				<div class="svc">
					<span class="ic"><?php mcr_the_icon( $mcr_service[0] ); ?></span>
					<div>
						<b><?php echo esc_html( $mcr_service[1] ); ?></b>
						<span><?php echo esc_html( $mcr_service[2] ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
