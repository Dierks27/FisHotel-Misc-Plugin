<?php
/**
 * Settings → Layout — the Temporary building: how many slots exist, one
 * more with a click, and each slot's status. Included from settings.php
 * inside the layout loop for the building whose tanks are each their own
 * water.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array $fh_b     The own-water building.
 * @var array $fh_entry Layout::group() entry: building, tanks.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;

$fh_slots = Temp_Tanks::slots( (array) $fh_entry['tanks'] );
$fh_next  = Layout::next_slot( (int) $fh_b['number'], array_map( 'intval', array_column( $fh_slots, 'number' ) ) );
?>
<div class="fh-cr-layout-building fh-cr-layout-building--temp">
	<form class="fh-cr-layout-form" data-op="building_save">
		<input type="hidden" name="id" value="<?php echo esc_attr( $fh_b['id'] ); ?>">
		<strong class="fh-cr-layout-number"><?php echo esc_html( $fh_b['number'] ); ?></strong>
		<input type="text" class="fh-cr-input" name="name" value="<?php echo esc_attr( $fh_b['name'] ); ?>" placeholder="<?php echo esc_attr( Layout::TEMP_LABEL ); ?>" maxlength="60">
		<select class="fh-cr-input" name="status">
			<?php foreach ( array( 'active', 'offline' ) as $fh_status ) : ?>
				<option value="<?php echo esc_attr( $fh_status ); ?>" <?php selected( $fh_b['status'], $fh_status ); ?>><?php echo esc_html( $fh_status ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="number" class="fh-cr-input fh-cr-input--num" name="sort" value="<?php echo esc_attr( $fh_b['sort'] ); ?>" title="<?php esc_attr_e( 'sort', 'fishotel-misc-plugin' ); ?>">
		<input type="text" class="fh-cr-input" name="notes" value="<?php echo esc_attr( $fh_b['notes'] ); ?>" placeholder="<?php esc_attr_e( 'notes', 'fishotel-misc-plugin' ); ?>">
		<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
	</form>

	<p class="fh-cr-sub">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of slots, 2: first slot number, 3: last slot number, 4: the most there can be */
				_n(
					'Each tank its own water — not a shared system. %1$d slot (%2$d): a place with a label, laminated once and reused. What is set up in a slot — its name, kind, gallons and treatment — comes and goes on the Board. Up to %4$d slots.',
					'Each tank its own water — not a shared system. %1$d slots (%2$d–%3$d): places with a label each, laminated once and reused. What is set up in a slot — its name, kind, gallons and treatment — comes and goes on the Board. Up to %4$d slots.',
					count( $fh_slots ),
					'fishotel-misc-plugin'
				),
				count( $fh_slots ),
				$fh_slots ? (int) $fh_slots[0]['number'] : 0,
				$fh_slots ? (int) $fh_slots[ count( $fh_slots ) - 1 ]['number'] : 0,
				Layout::MAX_SLOTS
			)
		);
		?>
	</p>

	<table class="widefat fh-cr-table fh-cr-layout-tanks">
		<tbody>
			<?php foreach ( $fh_slots as $fh_tank ) : ?>
				<tr>
					<td>
						<form class="fh-cr-layout-form" data-op="tank_save">
							<input type="hidden" name="id" value="<?php echo esc_attr( $fh_tank['id'] ); ?>">
							<strong class="fh-cr-layout-number"><?php echo esc_html( Layout::tank_label( $fh_tank ) ); ?></strong>
							<span class="fh-cr-sub">
								<?php
								if ( ! empty( $fh_tank['setup'] ) ) {
									$fh_what = trim( (string) $fh_tank['setup']['name'] );
									/* translators: 1: what is set up, 2: kind */
									echo esc_html( sprintf( __( 'set up: %1$s (%2$s)', 'fishotel-misc-plugin' ), '' !== $fh_what ? $fh_what : Layout::slot_name( (int) $fh_tank['slot'] ), strtolower( Temp_Tanks::kind_label( $fh_tank['setup']['kind'] ) ) ) );
								} else {
									esc_html_e( 'free', 'fishotel-misc-plugin' );
								}
								?>
							</span>
							<select class="fh-cr-input" name="status" <?php disabled( ! empty( $fh_tank['setup'] ) ); ?>>
								<?php foreach ( Layout::TANK_STATUSES as $fh_status ) : ?>
									<option value="<?php echo esc_attr( $fh_status ); ?>" <?php selected( $fh_tank['status'], $fh_status ); ?>><?php echo esc_html( $fh_status ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button button-small" <?php disabled( ! empty( $fh_tank['setup'] ) ); ?>><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<td>
					<?php if ( $fh_next > 0 ) : ?>
						<form class="fh-cr-layout-form fh-cr-layout-form--add" data-op="slot_add">
							<span class="fh-cr-sub"><?php esc_html_e( 'More temporary tanks:', 'fishotel-misc-plugin' ); ?></span>
							<button type="submit" class="button button-small">
								<?php
								/* translators: %s: slot label (TEMP 7 · 907) */
								echo esc_html( sprintf( __( 'Add a slot (%s)', 'fishotel-misc-plugin' ), Layout::slot_name( $fh_next ) . ' · ' . Layout::slot_number( (int) $fh_b['number'], $fh_next ) ) );
								?>
							</button>
						</form>
					<?php else : ?>
						<span class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: the most slots */ __( 'All %d slots exist: the last digit of the number is the slot.', 'fishotel-misc-plugin' ), Layout::MAX_SLOTS ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
