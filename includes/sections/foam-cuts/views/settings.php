<?php
/**
 * Settings tab — the sheet as bought, the blade, turning, the smallest
 * leftover worth keeping; and how the printing assistant reaches a plan.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var array $settings Settings (units).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

$fh_fields = array(
	'sheet_length' => array( __( 'Sheet length', 'fishotel-misc-plugin' ), __( 'The foam sheet as you buy it. 96 in = 8 ft.', 'fishotel-misc-plugin' ) ),
	'sheet_width'  => array( __( 'Sheet width', 'fishotel-misc-plugin' ), __( '48 in = 4 ft. No cut on the sheet is longer than this unless a piece is.', 'fishotel-misc-plugin' ) ),
	'thickness'    => array( __( 'Thickness', 'fishotel-misc-plugin' ), __( 'What the suggested side pieces are shortened by (twice). 0.5 = half an inch.', 'fishotel-misc-plugin' ) ),
	'kerf'         => array( __( 'Blade width', 'fishotel-misc-plugin' ), __( 'What every cut eats. 0 for a knife; 1/8 for a saw.', 'fishotel-misc-plugin' ) ),
	'min_length'   => array( __( 'Smallest leftover worth keeping: length', 'fishotel-misc-plugin' ), __( 'An offcut that does not hold this size (either way round) is waste and gets no label.', 'fishotel-misc-plugin' ) ),
	'min_width'    => array( __( 'Smallest leftover worth keeping: width', 'fishotel-misc-plugin' ), '' ),
);
?>
<section class="fh-fc-card">
	<h2><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php Admin::form_fields( 'settings_save' ); ?>
		<table class="fh-fc-form">
			<tbody>
				<?php foreach ( $fh_fields as $fh_key => $fh_field ) : ?>
					<tr>
						<th scope="row"><label for="fh-fc-<?php echo esc_attr( $fh_key ); ?>"><?php echo esc_html( $fh_field[0] ); ?></label></th>
						<td>
							<input type="text" id="fh-fc-<?php echo esc_attr( $fh_key ); ?>" name="<?php echo esc_attr( $fh_key ); ?>" value="<?php echo esc_attr( Units::decimal( $settings[ $fh_key ] ) ); ?>" class="fh-fc-size" inputmode="decimal" />
							<?php esc_html_e( 'in', 'fishotel-misc-plugin' ); ?>
							<?php if ( '' !== $fh_field[1] ) : ?>
								<span class="fh-fc-muted"><?php echo esc_html( $fh_field[1] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Turning pieces', 'fishotel-misc-plugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="rotate" value="1"<?php checked( ! empty( $settings['rotate'] ) ); ?> />
							<?php esc_html_e( 'A piece may be turned 90 degrees on the sheet', 'fishotel-misc-plugin' ); ?>
						</label>
						<span class="fh-fc-muted"><?php esc_html_e( 'Plain foam has no direction, so leave this on: it wastes less.', 'fishotel-misc-plugin' ); ?></span>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="fh-fc-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-fc-muted"><?php esc_html_e( 'Sizes in inches: 0.125 or 1/8 both work.', 'fishotel-misc-plugin' ); ?></span>
		</p>
	</form>
</section>

<section class="fh-fc-card">
	<h2><?php esc_html_e( 'For the assistant that prints the day\'s paperwork', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-fc-muted"><?php esc_html_e( 'The assistant can make the day\'s cut plan and print it without this screen. Nothing to do here unless you are setting that up.', 'fishotel-misc-plugin' ); ?></p>
	<details class="fh-fc-dev">
	<summary><?php esc_html_e( 'For the developer', 'fishotel-misc-plugin' ); ?></summary>
	<p class="fh-fc-muted"><?php esc_html_e( 'A plan can be made and fetched without this screen, with an application password of a shop manager or administrator (Users → Profile → Application Passwords). The answer carries a print_url that opens the cut sheet in that user\'s logged-in browser.', 'fishotel-misc-plugin' ); ?></p>
	<ul class="fh-fc-pieces">
		<li><code>POST <?php echo esc_html( rest_url( Foam_Cuts::REST_NAMESPACE . Foam_Cuts::REST_BASE . '/plan' ) ); ?></code> — <?php esc_html_e( 'body {"source":"orders"} makes a plan for the orders that ship today (needs the Packing section); {"boxes":{"<box name>":2}} for typed numbers.', 'fishotel-misc-plugin' ); ?></li>
		<li><code>GET <?php echo esc_html( rest_url( Foam_Cuts::REST_NAMESPACE . Foam_Cuts::REST_BASE . '/plan/latest' ) ); ?></code> — <?php esc_html_e( 'the newest plan; /plan/<number> for one plan; /plans for the list.', 'fishotel-misc-plugin' ); ?></li>
	</ul>
	</details>
	<p class="fh-fc-muted"><?php esc_html_e( 'Making a plan never changes the stock. "Mark as cut" is pressed here, by whoever cut the foam.', 'fishotel-misc-plugin' ); ?></p>
</section>
