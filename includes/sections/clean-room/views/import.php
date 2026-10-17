<?php
/**
 * Settings tab — the "Import workbook" panel: upload → preview → import.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array $import Admin_Import::panel_data(): pending, result, spa_buildings, defaults, action_url, max_bytes.
 * @var bool  $tables_ok Whether the tables exist.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Import;
use FisHotel\Misc\Sections\Clean_Room\Import;
use FisHotel\Misc\Sections\Clean_Room\Layout;

$fh_pending = $import['pending'];
$fh_result  = $import['result'];
$fh_spa     = $import['spa_buildings'];
?>
<div class="fishotel-misc-card fh-cr-card fh-cr-card--wide" id="<?php echo esc_attr( Admin_Import::ANCHOR ); ?>">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-upload"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Import workbook', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The FisHotel-YEAR workbook (.xlsx or .xlsm, at most 20 MB): the Fishlist becomes the species list, each Recieved (N) sheet an order with its lines (the QT Tanks (N) start date as its received date), the Data sheet\'s box rates go into Settings. The file waits ten minutes in a private folder for the preview, is read once more on "Import now" and is then deleted; the PW sheet is never opened. Running the same file twice updates the species and creates nothing else.', 'fishotel-misc-plugin' ); ?></p>

	<?php if ( $fh_result && ! empty( $fh_result['error'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( $fh_result['error'] ); ?></div>
	<?php elseif ( $fh_result && ! empty( $fh_result['result'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--success">
			<strong><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Imported %s.', 'fishotel-misc-plugin' ), (string) $fh_result['file'] ) ); ?></strong>
			<ul class="fh-cr-import__lines">
				<?php foreach ( Admin_Import::summary_lines( (array) $fh_result['result'] ) as $fh_line ) : ?>
					<li><?php echo esc_html( $fh_line ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $fh_result['result']['errors'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Problems:', 'fishotel-misc-plugin' ); ?></strong></p>
				<ul class="fh-cr-import__lines">
					<?php foreach ( (array) $fh_result['result']['errors'] as $fh_line ) : ?>
						<li><?php echo esc_html( $fh_line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $fh_result['result']['warnings'] ) ) : ?>
				<details>
					<summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d warnings from the reader', 'fishotel-misc-plugin' ), count( (array) $fh_result['result']['warnings'] ) ) ); ?></summary>
					<ul class="fh-cr-import__lines fh-cr-import__warnings">
						<?php foreach ( (array) $fh_result['result']['warnings'] as $fh_line ) : ?>
							<li><?php echo esc_html( $fh_line ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $fh_pending ) : ?>
		<?php $fh_p = (array) $fh_pending['preview']; ?>
		<h3 class="fh-cr-h3"><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Preview of %s', 'fishotel-misc-plugin' ), (string) $fh_pending['name'] ) ); ?></h3>
		<table class="widefat striped fh-cr-table fh-cr-import__table">
			<thead>
				<tr>
					<th scope="col"></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Create', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Update', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Skip', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['species']['create'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['species']['update'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['species']['skip'] ); ?></td>
					<td class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: %d: rows */ __( 'group, reef-safe and max size from %d stock rows where the species has none', 'fishotel-misc-plugin' ), (int) $fh_p['stock'] ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Orders', 'fishotel-misc-plugin' ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['orders']['create'] ); ?></td>
					<td class="fh-cr-num">—</td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['orders']['skip'] ); ?></td>
					<td class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: 1: lines, 2: log rows */ __( '%1$d lines and %2$d treatment-log rows on the orders to create; an order whose ref exists is never overwritten', 'fishotel-misc-plugin' ), (int) $fh_p['orders']['lines'], (int) $fh_p['orders']['logs'] ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Box rates', 'fishotel-misc-plugin' ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['box_rates']['create'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['box_rates']['update'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['box_rates']['skip'] ); ?></td>
					<td class="fh-cr-sub"><?php esc_html_e( 'merged into Settings → Box rates by supplier and origin', 'fishotel-misc-plugin' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Stays', 'fishotel-misc-plugin' ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_p['stays']['lines'] ); ?></td>
					<td class="fh-cr-num">—</td>
					<td class="fh-cr-num">—</td>
					<td class="fh-cr-sub"><?php echo esc_html( sprintf( /* translators: 1: orders, 2: days */ __( 'lines with fish on the %1$d batches received in the last %2$d days, checked in like the Arrivals button (a line that already has a stay is skipped)', 'fishotel-misc-plugin' ), (int) $fh_p['stays']['orders'], (int) Import::DEFAULT_STAYS_DAYS ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php if ( ! empty( $fh_p['warnings'] ) ) : ?>
			<details class="fh-cr-import__details">
				<summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d warnings from the reader (a species on a batch that is not in the Fishlist, a label not found, a sheet skipped …)', 'fishotel-misc-plugin' ), count( (array) $fh_p['warnings'] ) ) ); ?></summary>
				<ul class="fh-cr-import__lines fh-cr-import__warnings">
					<?php foreach ( (array) $fh_p['warnings'] as $fh_line ) : ?>
						<li><?php echo esc_html( $fh_line ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $import['action_url'] ); ?>" class="fh-cr-import__form">
			<?php wp_nonce_field( Admin_Import::NONCE_RUN ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_import_run">
			<div class="fh-cr-fields-grid">
				<label><span><?php esc_html_e( 'Create stays for batches received in the last … days (0 = none)', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-cr-input" name="stays_days" min="0" max="<?php echo esc_attr( Admin_Import::STAYS_DAYS_MAX ); ?>" value="<?php echo esc_attr( $import['defaults']['stays_days'] ); ?>"></label>
				<label><span><?php esc_html_e( 'Spa building those batches land in', 'fishotel-misc-plugin' ); ?></span>
					<select class="fh-cr-input" name="stays_building_id">
						<?php foreach ( $fh_spa as $fh_b ) : ?>
							<option value="<?php echo esc_attr( $fh_b['id'] ); ?>" <?php selected( (int) $fh_b['id'], (int) $import['defaults']['stays_building_id'] ); ?>><?php echo esc_html( Layout::building_label( array( 'number' => $fh_b['number'], 'name' => '' ) ) . ( $fh_b['gallons'] > 0 ? ' · ' . Layout::system_label( $fh_b['gallons'] ) : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="fh-cr-fields-grid__wide"><input type="checkbox" name="dose_logs" value="1" <?php checked( ! empty( $import['defaults']['dose_logs'] ) ); ?>> <?php esc_html_e( 'Import the treatment logs (QT Tanks sheets): doses, copper tests and notes on the Spa building the log\'s "Tanks" column names (205 / 100 gallons, "Both" = both), dated as in the sheet', 'fishotel-misc-plugin' ); ?></label>
			</div>
			<p class="fh-cr-actions-row">
				<button type="submit" class="button button-primary" <?php disabled( ! $tables_ok || ! $fh_spa ); ?>><?php esc_html_e( 'Import now', 'fishotel-misc-plugin' ); ?></button>
				<?php if ( ! $fh_spa ) : ?>
					<span class="fh-cr-status is-error"><?php esc_html_e( 'No active Spa building: add one in Layout first.', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( $import['action_url'] ); ?>" class="fh-cr-import__form">
			<?php wp_nonce_field( Admin_Import::NONCE_DISCARD ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_import_discard">
			<p class="fh-cr-actions-row"><button type="submit" class="button"><?php esc_html_e( 'Discard this file', 'fishotel-misc-plugin' ); ?></button></p>
		</form>
	<?php else : ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $import['action_url'] ); ?>" class="fh-cr-import__form">
			<?php wp_nonce_field( Admin_Import::NONCE_UPLOAD ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_import_upload">
			<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( $import['max_bytes'] ); ?>">
			<p class="fh-cr-actions-row">
				<input type="file" name="workbook" accept=".xlsx,.xlsm" required>
				<button type="submit" class="button button-primary" <?php disabled( ! $tables_ok ); ?>><?php esc_html_e( 'Upload and preview', 'fishotel-misc-plugin' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
