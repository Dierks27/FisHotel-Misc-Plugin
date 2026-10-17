<?php
/**
 * Settings tab — the numbers, the protocol template, the layout editor,
 * the learned aliases, the labels button.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $settings      Settings.
 * @var array   $protocols     Settings::protocols() — { default, templates }.
 * @var string  $template_name The template the editor shows.
 * @var array[] $protocol      Its steps.
 * @var array[] $buildings     Buildings.
 * @var array[] $tanks        Tanks.
 * @var array   $groups       Layout::group().
 * @var array[] $aliases      Aliases.
 * @var array[] $box_rates    Settings::box_rates().
 * @var string  $labels_url   Printable labels URL.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Always true here.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Settings;

$tab = 'settings';
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-cr-columns">

		<?php /* ── Numbers ───────────────────────────────────────── */ ?>
		<form id="fishotel-cleanroom-form" class="fishotel-misc-card fh-cr-card" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" onsubmit="return false;">
			<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_save">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-calendar"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Numbers', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'A stay is cleared the day it moves from the Spa into the Clean Room (editable per stay) and sale-ready this many days after check-in — the full stay, not the day number (overridable per stay).', 'fishotel-misc-plugin' ); ?></p>

			<div class="fh-cr-field fh-cr-field--row">
				<label class="fh-cr-label" for="fh-cr-days"><?php esc_html_e( 'Sale-ready: days after check-in (1–120)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" id="fh-cr-days" class="fh-cr-input fh-cr-input--num" name="sale_ready_days" min="<?php echo esc_attr( Settings::SALE_READY_MIN ); ?>" max="<?php echo esc_attr( Settings::SALE_READY_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['sale_ready_days'] ); ?>">
			</div>
			<div class="fh-cr-field fh-cr-field--row">
				<label class="fh-cr-label" for="fh-cr-floor"><?php esc_html_e( 'Copper floor', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" id="fh-cr-floor" class="fh-cr-input fh-cr-input--num" name="copper_floor" min="0" max="9.99" step="0.01" required value="<?php echo esc_attr( $settings['copper_floor'] ); ?>">
			</div>
			<div class="fh-cr-field fh-cr-field--row">
				<label class="fh-cr-label" for="fh-cr-target"><?php esc_html_e( 'Copper target', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" id="fh-cr-target" class="fh-cr-input fh-cr-input--num" name="copper_target" min="0" max="9.99" step="0.01" required value="<?php echo esc_attr( $settings['copper_target'] ); ?>">
			</div>
			<div class="fh-cr-field fh-cr-field--row">
				<label class="fh-cr-label" for="fh-cr-confirm"><?php esc_html_e( 'Voice auto-confirm (seconds, 0 = tap to confirm)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" id="fh-cr-confirm" class="fh-cr-input fh-cr-input--num" name="auto_confirm_seconds" min="<?php echo esc_attr( Settings::AUTO_CONFIRM_MIN ); ?>" max="<?php echo esc_attr( Settings::AUTO_CONFIRM_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['auto_confirm_seconds'] ); ?>">
			</div>
			<div class="fh-cr-field fh-cr-field--row">
				<div>
					<span class="fh-cr-label"><?php esc_html_e( 'Mic button on the Front Desk', 'fishotel-misc-plugin' ); ?></span>
					<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Off: the Copper button takes its place; the typed phrase box still understands the same grammar.', 'fishotel-misc-plugin' ); ?></p>
				</div>
				<label class="fishotel-misc-toggle">
					<input type="checkbox" name="voice_enabled" value="1" <?php checked( ! empty( $settings['voice_enabled'] ) ); ?>>
					<span class="slider"></span>
				</label>
			</div>
			<p class="fh-cr-actions-row">
				<button type="submit" class="button button-primary fh-cr-save"><?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?></button>
				<a class="button" href="<?php echo esc_url( $labels_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print tank labels', 'fishotel-misc-plugin' ); ?></a>
			</p>
		</form>

		<?php /* ── Protocol ──────────────────────────────────────── */ ?>
		<form id="fishotel-cleanroom-protocol" class="fishotel-misc-card fh-cr-card fh-cr-card--wide" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" onsubmit="return false;" data-template="<?php echo esc_attr( $template_name ); ?>" data-page-url="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>">
			<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_protocol">
			<input type="hidden" name="op" value="save">
			<input type="hidden" name="template" value="<?php echo esc_attr( $template_name ); ?>">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-list-view"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Treatment schedules', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Each Spa building runs one of these templates (Layout, below); a building without one runs the default. The daily to-do of every tank comes from its building\'s template: day 1 is the check-in day; "every" repeats a step until the last day; "where" limits it to the Spa or the Clean Room. The five seeds are the workbook\'s schedules with site day = sheet day + 1; a dose logged on the Front Desk ticks the dose step that names the same medication.', 'fishotel-misc-plugin' ); ?></p>

			<div class="fh-cr-template-bar">
				<label class="fh-cr-label" for="fh-cr-template"><?php esc_html_e( 'Template', 'fishotel-misc-plugin' ); ?></label>
				<select id="fh-cr-template" class="fh-cr-input fh-cr-template-pick">
					<?php foreach ( array_keys( $protocols['templates'] ) as $fh_name ) : ?>
						<option value="<?php echo esc_attr( $fh_name ); ?>" <?php selected( $template_name, $fh_name ); ?>><?php echo esc_html( $fh_name . ( $fh_name === $protocols['default'] ? ' ' . __( '(default)', 'fishotel-misc-plugin' ) : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button button-small fh-cr-template-add"><?php esc_html_e( 'Add', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button button-small fh-cr-template-rename"><?php esc_html_e( 'Rename', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button button-small fh-cr-template-default" <?php disabled( $template_name === $protocols['default'] ); ?>><?php esc_html_e( 'Make default', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button button-small fh-cr-template-delete" <?php disabled( count( $protocols['templates'] ) <= 1 ); ?>><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></button>
			</div>

			<table class="widefat fh-cr-table fh-cr-protocol" id="fh-cr-protocol-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Step', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Day', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Every', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Until', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Hint', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $protocol as $fh_i => $fh_step ) : ?>
						<tr class="fh-cr-step">
							<td><input type="text" class="fh-cr-input" name="steps[<?php echo esc_attr( $fh_i ); ?>][label]" value="<?php echo esc_attr( $fh_step['label'] ); ?>" maxlength="80"></td>
							<td>
								<select class="fh-cr-input" name="steps[<?php echo esc_attr( $fh_i ); ?>][kind]">
									<?php foreach ( Protocol::KINDS as $fh_kind ) : ?>
										<option value="<?php echo esc_attr( $fh_kind ); ?>" <?php selected( $fh_step['kind'], $fh_kind ); ?>><?php echo esc_html( str_replace( '_', ' ', $fh_kind ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[<?php echo esc_attr( $fh_i ); ?>][day]" min="1" max="<?php echo esc_attr( Protocol::MAX_DAY ); ?>" value="<?php echo esc_attr( $fh_step['day'] ); ?>"></td>
							<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[<?php echo esc_attr( $fh_i ); ?>][every]" min="0" max="60" value="<?php echo esc_attr( $fh_step['every'] ); ?>"></td>
							<td><input type="number" class="fh-cr-input fh-cr-input--num" name="steps[<?php echo esc_attr( $fh_i ); ?>][until]" min="1" max="<?php echo esc_attr( Protocol::MAX_DAY ); ?>" value="<?php echo esc_attr( null === $fh_step['until'] ? '' : $fh_step['until'] ); ?>"></td>
							<td>
								<select class="fh-cr-input" name="steps[<?php echo esc_attr( $fh_i ); ?>][where]">
									<?php foreach ( Protocol::WHERES as $fh_where ) : ?>
										<option value="<?php echo esc_attr( $fh_where ); ?>" <?php selected( $fh_step['where'], $fh_where ); ?>><?php echo esc_html( 'any' === $fh_where ? __( 'anywhere', 'fishotel-misc-plugin' ) : Layout::area_label( $fh_where ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" class="fh-cr-input" name="steps[<?php echo esc_attr( $fh_i ); ?>][note]" value="<?php echo esc_attr( $fh_step['note'] ); ?>" maxlength="200"></td>
							<td><button type="button" class="button button-small fh-cr-step-remove">&times;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="fh-cr-actions-row">
				<button type="button" class="button fh-cr-step-add"><?php esc_html_e( 'Add step', 'fishotel-misc-plugin' ); ?></button>
				<button type="submit" class="button button-primary fh-cr-protocol-save"><?php echo esc_html( sprintf( /* translators: %s: template name */ __( 'Save "%s"', 'fishotel-misc-plugin' ), $template_name ) ); ?></button>
				<button type="button" class="button fh-cr-protocol-reset"><?php echo esc_html( null !== Protocol::seed_template( $template_name ) ? __( 'Reset to the workbook\'s schedule', 'fishotel-misc-plugin' ) : __( 'Reset to the daily feeding', 'fishotel-misc-plugin' ) ); ?></button>
			</p>
		</form>

		<?php /* ── Layout ────────────────────────────────────────── */ ?>
		<div class="fishotel-misc-card fh-cr-card fh-cr-card--wide">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-layout"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Layout', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Buildings are round hundreds (100–900); a tank\'s number is derived from its building, level (1 bottom, 2 top) and position (1 = leftmost) — never typed. Retire with the status instead of deleting: stays point at tanks. Each building runs a schedule template (blank = the default) and has a system volume in gallons (sump included — blank = the sum of its tanks); the Dose sheet suggests amounts from it and the workbook import matches the log\'s 205 / 100 systems to it.', 'fishotel-misc-plugin' ); ?></p>

			<?php foreach ( $groups as $fh_area => $fh_group ) : ?>
				<h3 class="fh-cr-h3"><?php echo esc_html( $fh_group['label'] ); ?></h3>
				<?php foreach ( $fh_group['buildings'] as $fh_entry ) : ?>
					<?php
					$fh_b       = $fh_entry['building'];
					$fh_gallons = Layout::system_gallons( $fh_b, $fh_entry['tanks'] );
					?>
					<div class="fh-cr-layout-building">
						<form class="fh-cr-layout-form" data-op="building_save">
							<input type="hidden" name="id" value="<?php echo esc_attr( $fh_b['id'] ); ?>">
							<strong class="fh-cr-layout-number"><?php echo esc_html( $fh_b['number'] ); ?></strong>
							<input type="text" class="fh-cr-input" name="name" value="<?php echo esc_attr( $fh_b['name'] ); ?>" placeholder="<?php echo esc_attr( 'Building ' . $fh_b['number'] ); ?>" maxlength="60">
							<select class="fh-cr-input" name="status">
								<?php foreach ( Layout::BUILDING_STATUSES as $fh_status ) : ?>
									<option value="<?php echo esc_attr( $fh_status ); ?>" <?php selected( $fh_b['status'], $fh_status ); ?>><?php echo esc_html( str_replace( '_', ' ', $fh_status ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="number" class="fh-cr-input fh-cr-input--num" name="sort" value="<?php echo esc_attr( $fh_b['sort'] ); ?>" title="<?php esc_attr_e( 'sort', 'fishotel-misc-plugin' ); ?>">
							<select class="fh-cr-input" name="schedule" title="<?php esc_attr_e( 'schedule template', 'fishotel-misc-plugin' ); ?>">
								<option value=""><?php echo esc_html( sprintf( /* translators: %s: the default template */ __( 'schedule: default (%s)', 'fishotel-misc-plugin' ), $protocols['default'] ) ); ?></option>
								<?php foreach ( array_keys( $protocols['templates'] ) as $fh_name ) : ?>
									<option value="<?php echo esc_attr( $fh_name ); ?>" <?php selected( $fh_b['schedule'], $fh_name ); ?>><?php echo esc_html( $fh_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="number" class="fh-cr-input fh-cr-input--num" name="system_gallons" value="<?php echo esc_attr( null === $fh_b['system_gallons'] ? '' : $fh_b['system_gallons'] ); ?>" min="0" max="9999" placeholder="<?php echo esc_attr( $fh_gallons > 0 ? $fh_gallons : 'gal' ); ?>" title="<?php esc_attr_e( 'system gallons (blank = the sum of the tanks)', 'fishotel-misc-plugin' ); ?>">
							<span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( Layout::system_label( $fh_gallons ) ); ?></span>
							<input type="text" class="fh-cr-input" name="notes" value="<?php echo esc_attr( $fh_b['notes'] ); ?>" placeholder="<?php esc_attr_e( 'notes', 'fishotel-misc-plugin' ); ?>">
							<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
						</form>

						<table class="widefat fh-cr-table fh-cr-layout-tanks">
							<tbody>
								<?php foreach ( $fh_entry['tanks'] as $fh_tank ) : ?>
									<tr>
										<td>
											<form class="fh-cr-layout-form" data-op="tank_save">
												<input type="hidden" name="id" value="<?php echo esc_attr( $fh_tank['id'] ); ?>">
												<strong class="fh-cr-layout-number"><?php echo esc_html( $fh_tank['number'] ); ?></strong>
												<span class="fh-cr-sub"><?php echo esc_html( Layout::LEVELS[ (int) $fh_tank['level'] ] ?? '' ); ?> · <?php echo esc_html( sprintf( /* translators: %d: position */ __( 'pos %d', 'fishotel-misc-plugin' ), (int) $fh_tank['position'] ) ); ?></span>
												<input type="text" class="fh-cr-input" name="size_label" value="<?php echo esc_attr( $fh_tank['size_label'] ); ?>" placeholder="<?php esc_attr_e( 'size', 'fishotel-misc-plugin' ); ?>" maxlength="40">
												<input type="number" class="fh-cr-input fh-cr-input--num" name="gallons" value="<?php echo esc_attr( $fh_tank['gallons'] ); ?>" min="0" max="9999" title="<?php esc_attr_e( 'gallons', 'fishotel-misc-plugin' ); ?>">
												<select class="fh-cr-input" name="status">
													<?php foreach ( Layout::TANK_STATUSES as $fh_status ) : ?>
														<option value="<?php echo esc_attr( $fh_status ); ?>" <?php selected( $fh_tank['status'], $fh_status ); ?>><?php echo esc_html( $fh_status ); ?></option>
													<?php endforeach; ?>
												</select>
												<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr>
									<td>
										<form class="fh-cr-layout-form fh-cr-layout-form--add" data-op="tank_add">
											<input type="hidden" name="building_id" value="<?php echo esc_attr( $fh_b['id'] ); ?>">
											<span class="fh-cr-sub"><?php esc_html_e( 'Add tank:', 'fishotel-misc-plugin' ); ?></span>
											<select class="fh-cr-input" name="level">
												<option value="2"><?php esc_html_e( 'top', 'fishotel-misc-plugin' ); ?></option>
												<option value="1"><?php esc_html_e( 'bottom', 'fishotel-misc-plugin' ); ?></option>
											</select>
											<input type="number" class="fh-cr-input fh-cr-input--num" name="position" min="1" max="9" value="1" title="<?php esc_attr_e( 'position (1 = leftmost)', 'fishotel-misc-plugin' ); ?>">
											<input type="text" class="fh-cr-input" name="size_label" placeholder="<?php esc_attr_e( '20G long', 'fishotel-misc-plugin' ); ?>" maxlength="40">
											<input type="number" class="fh-cr-input fh-cr-input--num" name="gallons" min="0" max="9999" placeholder="20">
											<button type="submit" class="button button-small"><?php esc_html_e( 'Add', 'fishotel-misc-plugin' ); ?></button>
										</form>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<form class="fh-cr-layout-form fh-cr-layout-form--add" data-op="building_add">
				<span class="fh-cr-sub"><?php esc_html_e( 'Add building:', 'fishotel-misc-plugin' ); ?></span>
				<select class="fh-cr-input" name="area">
					<?php foreach ( Layout::AREAS as $fh_key => $fh_label ) : ?>
						<option value="<?php echo esc_attr( $fh_key ); ?>"><?php echo esc_html( $fh_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" class="fh-cr-input fh-cr-input--num" name="number" min="100" max="900" step="100" placeholder="700" required>
				<input type="text" class="fh-cr-input" name="name" placeholder="<?php esc_attr_e( 'name (optional)', 'fishotel-misc-plugin' ); ?>" maxlength="60">
				<select class="fh-cr-input" name="status">
					<?php foreach ( Layout::BUILDING_STATUSES as $fh_status ) : ?>
						<option value="<?php echo esc_attr( $fh_status ); ?>"><?php echo esc_html( str_replace( '_', ' ', $fh_status ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Add', 'fishotel-misc-plugin' ); ?></button>
			</form>
			<p class="fh-cr-status fh-cr-layout-status" aria-live="polite"></p>
		</div>

		<?php /* ── Box rates ─────────────────────────────────────── */ ?>
		<form id="fishotel-cleanroom-box-rates" class="fishotel-misc-card fh-cr-card fh-cr-card--wide" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" onsubmit="return false;">
			<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="fishotel_cleanroom_box_rates">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Box rates', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The per-box shipping and the re-ox fee each supplier charges by origin. The order editor\'s "Suggest" button fills the import & re-ox fees with boxes × (per box + re-ox) for the order\'s supplier and origin — never on its own. A row with no origin stands for every origin of that supplier.', 'fishotel-misc-plugin' ); ?></p>

			<table class="widefat fh-cr-table fh-cr-box-rates" id="fh-cr-box-rates-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Origin', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Re-ox', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $box_rates as $fh_i => $fh_rate ) : ?>
						<tr class="fh-cr-rate">
							<td><input type="text" class="fh-cr-input" name="rates[<?php echo esc_attr( $fh_i ); ?>][supplier]" value="<?php echo esc_attr( $fh_rate['supplier'] ); ?>" maxlength="80"></td>
							<td><input type="text" class="fh-cr-input" name="rates[<?php echo esc_attr( $fh_i ); ?>][origin]" value="<?php echo esc_attr( $fh_rate['origin'] ); ?>" maxlength="80"></td>
							<td><input type="number" step="0.01" min="0" class="fh-cr-input fh-cr-input--num" name="rates[<?php echo esc_attr( $fh_i ); ?>][per_box]" value="<?php echo esc_attr( number_format( (float) $fh_rate['per_box'], 2, '.', '' ) ); ?>"></td>
							<td><input type="number" step="0.01" min="0" class="fh-cr-input fh-cr-input--num" name="rates[<?php echo esc_attr( $fh_i ); ?>][reox]" value="<?php echo esc_attr( number_format( (float) $fh_rate['reox'], 2, '.', '' ) ); ?>"></td>
							<td><button type="button" class="button button-small fh-cr-rate-remove">&times;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="fh-cr-actions-row">
				<button type="button" class="button fh-cr-rate-add"><?php esc_html_e( 'Add rate', 'fishotel-misc-plugin' ); ?></button>
				<button type="submit" class="button button-primary fh-cr-box-rates-save"><?php esc_html_e( 'Save box rates', 'fishotel-misc-plugin' ); ?></button>
			</p>
		</form>

		<?php /* ── Aliases ───────────────────────────────────────── */ ?>
		<div class="fishotel-misc-card fh-cr-card">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-microphone"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Learned aliases', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'What the Front Desk learned from "Not that fish": the spoken words on the left now mean the fish on the right.', 'fishotel-misc-plugin' ); ?></p>
			<?php if ( ! $aliases ) : ?>
				<p class="fh-cr-sub"><?php esc_html_e( 'Nothing learned yet.', 'fishotel-misc-plugin' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fh-cr-table">
					<tbody>
						<?php foreach ( $aliases as $fh_alias ) : ?>
							<tr data-alias="<?php echo esc_attr( $fh_alias['id'] ); ?>">
								<td><code><?php echo esc_html( $fh_alias['alias'] ); ?></code></td>
								<td><?php echo esc_html( $fh_alias['species'] ); ?><?php echo $fh_alias['product_id'] ? ' <span class="fh-cr-sub">#' . esc_html( $fh_alias['product_id'] ) . '</span>' : ''; ?></td>
								<td class="fh-cr-row__actions"><button type="button" class="button button-small fh-cr-alias-delete"><?php esc_html_e( 'Forget', 'fishotel-misc-plugin' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

	</div><!-- .fh-cr-columns -->
</div>
