<?php
/**
 * Room Board — the Temporary area: the slots of the own-water building,
 * "Set up a temporary tank", and a card per setup with its kind, purpose
 * and day, its own schedule editor, Edit and Take down.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $temp     Admin_Temp::board(): building, slots, doses, sheet, closed.
 * @var array   $settings Settings.
 * @var array[] $sizes    The standard sizes (slug, label).
 * @var string  $today    Site date.
 * @var string  $fh_species The Board's species filter.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;

$fh_sheet   = (array) $temp['sheet'];
$fh_free    = (array) $fh_sheet['free'];
$fh_in_use  = 0;
$fh_offline = $temp['building'] && 'offline' === (string) $temp['building']['status'];
foreach ( $temp['slots'] as $fh_slot ) {
	if ( ! empty( $fh_slot['setup'] ) ) {
		$fh_in_use++;
	}
}
?>
<section class="fh-cr-area fh-cr-area--temp" id="fh-cr-temp">
	<h2 class="fh-cr-area__title">
		<?php echo esc_html( Layout::TEMP_LABEL ); ?>
		<span class="fh-cr-area__sub"><?php esc_html_e( 'hospital tanks and the like — each its own water, set up for a while and taken down again', 'fishotel-misc-plugin' ); ?></span>
	</h2>

	<?php if ( ! $temp['building'] ) : ?>
		<p class="fh-cr-building__empty"><?php esc_html_e( 'Temporary tanks are not available: the layout has no building for them, and every building number from 100 to 900 is in use.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>

		<div class="fh-cr-temp-bar">
			<button type="button" class="button button-primary fh-cr-temp-open" <?php disabled( ! $fh_free || $fh_offline ); ?>><?php esc_html_e( 'Set up a temporary tank', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-cr-building__meta">
				<?php
				/* translators: 1: slots in use, 2: slots */
				echo esc_html( sprintf( __( '%1$d of %2$d slots in use', 'fishotel-misc-plugin' ), $fh_in_use, count( $temp['slots'] ) ) );
				if ( $fh_offline ) {
					echo ' · ' . esc_html__( 'offline', 'fishotel-misc-plugin' );
				} elseif ( ! $fh_free ) {
					echo ' · ' . esc_html__( 'every slot is in use — take one down, or add a slot in Settings → Layout', 'fishotel-misc-plugin' );
				}
				?>
			</span>
			<a class="fh-cr-tank__link" href="<?php echo esc_url( Admin_Temp::history_url() ); ?>">
				<?php
				/* translators: %d: setups taken down */
				echo esc_html( sprintf( __( 'History (%d)', 'fishotel-misc-plugin' ), (int) $temp['closed'] ) );
				?>
				&rarr;
			</a>
		</div>

		<?php /* ── Set up a temporary tank ───────────────────────────── */ ?>
		<form class="fh-cr-temp-form" hidden data-templates="<?php echo esc_attr( (string) wp_json_encode( $fh_sheet['templates'] ) ); ?>">
			<h3 class="fh-cr-h3"><?php esc_html_e( 'Set up a temporary tank', 'fishotel-misc-plugin' ); ?></h3>
			<div class="fh-cr-temp-form__grid">
				<label class="fh-cr-filter">
					<span><?php esc_html_e( 'Slot', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input" name="slot">
						<?php foreach ( $fh_free as $fh_option ) : ?>
							<option value="<?php echo esc_attr( $fh_option['number'] ); ?>"><?php echo esc_html( $fh_option['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="fh-cr-filter fh-cr-filter--grow">
					<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-cr-input" name="name" maxlength="<?php echo esc_attr( Temp_Tanks::MAX_NAME ); ?>" placeholder="<?php esc_attr_e( 'Hospital 1', 'fishotel-misc-plugin' ); ?>">
				</label>
				<label class="fh-cr-filter fh-cr-filter--grow">
					<span><?php esc_html_e( 'What is it for?', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input fh-cr-temp-purpose">
						<option value=""><?php esc_html_e( '— choose —', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( (array) $fh_sheet['tags'] as $fh_tag ) : ?>
							<option value="<?php echo esc_attr( $fh_tag ); ?>"><?php echo esc_html( $fh_tag ); ?></option>
						<?php endforeach; ?>
						<option value="__other"><?php esc_html_e( 'Something else…', 'fishotel-misc-plugin' ); ?></option>
					</select>
					<input type="text" class="fh-cr-input fh-cr-temp-purpose-text" name="purpose" maxlength="<?php echo esc_attr( Temp_Tanks::MAX_PURPOSE ); ?>" placeholder="<?php esc_attr_e( 'in a few words', 'fishotel-misc-plugin' ); ?>" hidden>
				</label>
				<fieldset class="fh-cr-filter fh-cr-temp-kind">
					<legend><?php esc_html_e( 'Kind', 'fishotel-misc-plugin' ); ?></legend>
					<label><input type="radio" name="kind" value="quarantine"> <strong><?php esc_html_e( 'Quarantine', 'fishotel-misc-plugin' ); ?></strong> — <?php esc_html_e( 'for fish still in quarantine or under treatment (arrivals may go in; fish in it stay uncleared)', 'fishotel-misc-plugin' ); ?></label>
					<label><input type="radio" name="kind" value="clean"> <strong><?php esc_html_e( 'Clean', 'fishotel-misc-plugin' ); ?></strong> — <?php esc_html_e( 'for fish that are already cleared (a quarantine group moved into it is cleared)', 'fishotel-misc-plugin' ); ?></label>
				</fieldset>
				<label class="fh-cr-filter">
					<span><?php esc_html_e( 'Gallons', 'fishotel-misc-plugin' ); ?></span>
					<input type="number" class="fh-cr-input fh-cr-input--num" name="gallons" min="0" max="<?php echo esc_attr( Layout::MAX_GALLONS ); ?>" step="1" placeholder="10">
				</label>
				<label class="fh-cr-filter fh-cr-filter--grow">
					<span><?php esc_html_e( 'Treatment', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input fh-cr-temp-template" name="template">
						<option value=""><?php esc_html_e( 'No treatment, just watching', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( (array) $fh_sheet['templates'] as $fh_template ) : ?>
							<option value="<?php echo esc_attr( $fh_template['name'] ); ?>"><?php echo esc_html( $fh_template['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="fh-cr-filter">
					<span><?php esc_html_e( 'Treatment starts', 'fishotel-misc-plugin' ); ?></span>
					<input type="date" class="fh-cr-input" name="start_date" value="<?php echo esc_attr( $today ); ?>" min="<?php echo esc_attr( $fh_sheet['startMin'] ); ?>" max="<?php echo esc_attr( $fh_sheet['startMax'] ); ?>">
				</label>
				<label class="fh-cr-filter fh-cr-filter--wide">
					<span><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-cr-input" name="note" maxlength="500">
				</label>
			</div>
			<div class="fh-cr-temp-preview" aria-live="polite"></div>
			<p class="fh-cr-actions-row">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Set it up', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button fh-cr-temp-cancel"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'The treatment\'s steps are copied into this tank: it runs its own schedule from its own start date, and can be changed for this tank alone.', 'fishotel-misc-plugin' ); ?></span>
			</p>
		</form>
		<p class="fh-cr-status fh-cr-temp-status" aria-live="polite"></p>

		<?php /* The free slots: places with a label, nothing set up — one row, not a card each. */ ?>
		<?php if ( $fh_in_use < count( $temp['slots'] ) ) : ?>
			<p class="fh-cr-temp-free">
				<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'Nothing set up in:', 'fishotel-misc-plugin' ); ?></span>
				<?php foreach ( $temp['slots'] as $fh_tank ) : ?>
					<?php
					if ( ! empty( $fh_tank['setup'] ) ) {
						continue;
					}
					$fh_dead = 'offline' === $fh_tank['status'] || $fh_offline;
					?>
					<button type="button" class="button button-small fh-cr-temp-slot<?php echo $fh_dead ? '' : ' fh-cr-temp-open'; ?>" data-slot="<?php echo esc_attr( $fh_tank['number'] ); ?>" <?php disabled( $fh_dead ); ?> title="<?php echo esc_attr( $fh_dead ? __( 'offline', 'fishotel-misc-plugin' ) : __( 'Set up a temporary tank in this slot', 'fishotel-misc-plugin' ) ); ?>"><?php echo esc_html( Layout::tank_label( $fh_tank ) ); ?></button>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<div class="fh-cr-tanks fh-cr-tanks--temp">
			<?php foreach ( $temp['slots'] as $fh_tank ) : ?>
				<?php
				$fh_setup = ! empty( $fh_tank['setup'] ) ? (array) $fh_tank['setup'] : null;
				$fh_name  = Layout::slot_name( (int) $fh_tank['slot'] );
				if ( ! $fh_setup ) {
					continue; // The free slots are the row of chips above.
				}

				$fh_occupants = (array) ( $fh_tank['occupants'] ?? array() );
				$fh_todo      = (array) ( $fh_tank['todo'] ?? array() );
				$fh_due       = array_values( array_filter( $fh_todo, static function ( $i ) { return empty( $i['done'] ); } ) );
				$fh_labels    = array_values( array_unique( array_map( static function ( $i ) { return (string) $i['label']; }, $fh_due ) ) );
				$fh_cu        = $fh_tank['copper'] ?? null;
				$fh_state     = $fh_cu ? Protocol::copper_state( (float) $fh_cu['value'], (float) $settings['copper_floor'] ) : 'none';
				$fh_doses     = isset( $temp['doses'][ (int) $fh_setup['id'] ] ) ? $temp['doses'][ (int) $fh_setup['id'] ] : array();
				$fh_what      = '' !== trim( $fh_setup['name'] ) ? trim( $fh_setup['name'] ) : $fh_name;
				$fh_match     = false;
				foreach ( $fh_occupants as $fh_stay ) {
					if ( '' !== $fh_species && Admin::matches_species( $fh_stay, $fh_species ) ) {
						$fh_match = true;
					}
				}
				?>
				<div class="fh-cr-tank fh-cr-tank--temp fh-cr-tank--<?php echo esc_attr( $fh_setup['kind'] ); ?><?php echo ( '' !== $fh_species && ! $fh_match ) ? ' is-dim' : ''; ?>" data-tank="<?php echo esc_attr( $fh_tank['number'] ); ?>" data-setup="<?php echo esc_attr( (int) $fh_setup['id'] ); ?>" data-seen="<?php echo esc_attr( Temp_Tanks::seen( $fh_setup ) ); ?>" data-name="<?php echo esc_attr( $fh_what ); ?>">
					<div class="fh-cr-tank__head">
						<span class="fh-cr-tank__number"><?php echo esc_html( $fh_name ); ?></span>
						<span class="fh-cr-tank__size"><?php echo esc_html( $fh_tank['number'] ); ?><?php echo (int) $fh_setup['gallons'] > 0 ? ' · ' . esc_html( (int) $fh_setup['gallons'] . ' gal' ) : ''; ?></span>
					</div>
					<div class="fh-cr-temp-what">
						<?php if ( '' !== trim( $fh_setup['name'] ) ) : ?>
							<strong><?php echo esc_html( $fh_setup['name'] ); ?></strong>
						<?php endif; ?>
						<span class="fh-cr-badge fh-cr-badge--<?php echo 'clean' === $fh_setup['kind'] ? 'cleared' : 'in_qt'; ?>"><?php echo esc_html( Temp_Tanks::kind_label( $fh_setup['kind'] ) ); ?></span>
						<?php if ( '' !== trim( $fh_setup['purpose'] ) ) : ?>
							<span class="fh-cr-temp-purpose-word"><?php echo esc_html( $fh_setup['purpose'] ); ?></span>
						<?php endif; ?>
					</div>
					<div class="fh-cr-temp-clock">
						<?php echo esc_html( Temp_Tanks::schedule_label( $fh_setup ) ); ?>
						· <strong><?php echo esc_html( Temp_Tanks::day_text( $fh_setup, $today ) ); ?></strong>
						<span class="fh-cr-muted">· <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'since %s', 'fishotel-misc-plugin' ), $fh_setup['start_date'] ) ); ?></span>
					</div>
					<?php if ( '' !== trim( $fh_setup['note'] ) ) : ?>
						<p class="fh-cr-temp-note"><?php echo esc_html( $fh_setup['note'] ); ?></p>
					<?php endif; ?>

					<?php if ( $fh_occupants ) : ?>
						<ul class="fh-cr-tank__list">
							<?php foreach ( $fh_occupants as $fh_stay ) : ?>
								<li class="<?php echo ( '' !== $fh_species && Admin::matches_species( $fh_stay, $fh_species ) ) ? 'is-hit' : ''; ?><?php echo ! empty( $fh_stay['needs_id'] ) ? ' is-unknown' : ''; ?>">
									<span class="fh-cr-tank__species"><?php echo esc_html( $fh_stay['species'] ); ?></span>
									<?php echo Admin::size_html( $fh_stay, $sizes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by size_html(). ?>
									· <?php echo esc_html( $fh_stay['qty_live'] ); ?>
									<?php if ( null !== $fh_stay['day'] ) : ?>
										· <?php echo esc_html( sprintf( /* translators: %d: the fish's own quarantine day */ __( 'QT day %d', 'fishotel-misc-plugin' ), $fh_stay['day'] ) ); ?>
									<?php endif; ?>
									<?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="fh-cr-tank__empty"><?php esc_html_e( 'no fish in it', 'fishotel-misc-plugin' ); ?></p>
					<?php endif; ?>

					<div class="fh-cr-tank__foot">
						<?php if ( $fh_cu ) : ?>
							<span class="fh-cr-cu fh-cr-cu--<?php echo esc_attr( $fh_state ); ?>">Cu <?php echo esc_html( rtrim( rtrim( number_format( (float) $fh_cu['value'], 2, '.', '' ), '0' ), '.' ) ); ?></span>
						<?php endif; ?>
						<?php if ( $fh_todo ) : ?>
							<span class="fh-cr-todo"><?php echo esc_html( sprintf( /* translators: 1: open items, 2: all items */ __( 'to-do %1$d/%2$d', 'fishotel-misc-plugin' ), count( $fh_due ), count( $fh_todo ) ) ); ?></span>
							<?php if ( $fh_labels ) : ?>
								<span class="fh-cr-todo__due"><?php echo esc_html( implode( ' · ', $fh_labels ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( $fh_doses ) : ?>
							<span class="fh-cr-building__doses">
								<?php
								$fh_parts = array();
								foreach ( $fh_doses as $fh_dose ) {
									$fh_parts[] = $fh_dose['label'] . ' ' . Admin::site_time( $fh_dose['logged_at'] );
								}
								echo esc_html( __( 'last dose:', 'fishotel-misc-plugin' ) . ' ' . implode( ' · ', $fh_parts ) );
								?>
							</span>
						<?php endif; ?>
						<a class="fh-cr-tank__link" href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_tank['number'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Front Desk', 'fishotel-misc-plugin' ); ?> &rarr;</a>
					</div>

					<div class="fh-cr-temp-actions">
						<button type="button" class="button button-small fh-cr-temp-toggle" data-panel="steps"><?php esc_html_e( 'Schedule…', 'fishotel-misc-plugin' ); ?></button>
						<button type="button" class="button button-small fh-cr-temp-toggle" data-panel="edit"><?php esc_html_e( 'Edit…', 'fishotel-misc-plugin' ); ?></button>
						<a class="button button-small" href="<?php echo esc_url( Admin_Temp::history_url( (int) $fh_setup['id'] ) ); ?>"><?php esc_html_e( 'Log', 'fishotel-misc-plugin' ); ?></a>
						<?php if ( $fh_occupants ) : ?>
							<button type="button" class="button button-small" disabled title="<?php esc_attr_e( 'Move the fish out first — then it can be taken down.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Take down', 'fishotel-misc-plugin' ); ?></button>
							<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'move the fish out first', 'fishotel-misc-plugin' ); ?></span>
						<?php else : ?>
							<button type="button" class="button button-small fh-cr-temp-down"><?php esc_html_e( 'Take down', 'fishotel-misc-plugin' ); ?></button>
						<?php endif; ?>
					</div>

					<?php /* Edit: what can change on a running setup. */ ?>
					<form class="fh-cr-temp-panel fh-cr-temp-edit" data-panel="edit" hidden>
						<label class="fh-cr-filter"><span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="name" value="<?php echo esc_attr( $fh_setup['name'] ); ?>" maxlength="<?php echo esc_attr( Temp_Tanks::MAX_NAME ); ?>"></label>
						<label class="fh-cr-filter"><span><?php esc_html_e( 'What it is for', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="purpose" value="<?php echo esc_attr( $fh_setup['purpose'] ); ?>" maxlength="<?php echo esc_attr( Temp_Tanks::MAX_PURPOSE ); ?>"></label>
						<label class="fh-cr-filter"><span><?php esc_html_e( 'Gallons', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-cr-input fh-cr-input--num" name="gallons" value="<?php echo esc_attr( (int) $fh_setup['gallons'] > 0 ? (int) $fh_setup['gallons'] : '' ); ?>" min="0" max="<?php echo esc_attr( Layout::MAX_GALLONS ); ?>" step="1"></label>
						<label class="fh-cr-filter"><span><?php esc_html_e( 'Treatment starts (day 1)', 'fishotel-misc-plugin' ); ?></span><input type="date" class="fh-cr-input" name="start_date" value="<?php echo esc_attr( $fh_setup['start_date'] ); ?>"></label>
						<label class="fh-cr-filter"><span><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-cr-input" name="note" value="<?php echo esc_attr( $fh_setup['note'] ); ?>" maxlength="500"></label>
						<p class="fh-cr-sub"><?php esc_html_e( 'The kind and the slot stay as they are: take it down and set it up again to change those.', 'fishotel-misc-plugin' ); ?></p>
						<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
					</form>

					<?php /* Schedule: this tank's own copy of the steps. */ ?>
					<form class="fh-cr-temp-panel fh-cr-temp-steps" data-panel="steps" hidden>
						<p class="fh-cr-sub"><?php esc_html_e( 'This tank\'s own schedule — changes here never touch the template, and the template never changes this tank. Day 1 is the treatment start date.', 'fishotel-misc-plugin' ); ?></p>
						<table class="widefat fh-cr-table fh-cr-temp-steps__table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Step', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Kind', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Day', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Every', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Until', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Hint', 'fishotel-misc-plugin' ); ?></th>
									<th scope="col"></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( (array) $fh_setup['steps'] as $fh_step ) : ?>
									<tr class="fh-cr-temp-step">
										<td><input type="text" class="fh-cr-input" data-field="label" value="<?php echo esc_attr( $fh_step['label'] ); ?>" maxlength="80"></td>
										<td>
											<select class="fh-cr-input" data-field="kind">
												<?php foreach ( Protocol::KINDS as $fh_kind ) : ?>
													<option value="<?php echo esc_attr( $fh_kind ); ?>" <?php selected( $fh_step['kind'], $fh_kind ); ?>><?php echo esc_html( str_replace( '_', ' ', $fh_kind ) ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
										<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="day" min="1" max="<?php echo esc_attr( Protocol::MAX_DAY ); ?>" value="<?php echo esc_attr( $fh_step['day'] ); ?>"></td>
										<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="every" min="0" max="60" value="<?php echo esc_attr( $fh_step['every'] ); ?>"></td>
										<td><input type="number" class="fh-cr-input fh-cr-input--num" data-field="until" min="1" max="<?php echo esc_attr( Protocol::MAX_DAY ); ?>" value="<?php echo esc_attr( null === $fh_step['until'] ? '' : $fh_step['until'] ); ?>"></td>
										<td><input type="text" class="fh-cr-input" data-field="note" value="<?php echo esc_attr( $fh_step['note'] ); ?>" maxlength="200"></td>
										<td><button type="button" class="button button-small fh-cr-temp-step-remove" aria-label="<?php esc_attr_e( 'Remove this step', 'fishotel-misc-plugin' ); ?>">&times;</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="fh-cr-actions-row">
							<button type="button" class="button button-small fh-cr-temp-step-add"><?php esc_html_e( 'Add step', 'fishotel-misc-plugin' ); ?></button>
							<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Save this tank\'s schedule', 'fishotel-misc-plugin' ); ?></button>
						</p>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
