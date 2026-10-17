<?php
/**
 * Today settings tab.
 *
 * @package FisHotel\Misc\Sections\Today
 *
 * @var array $settings     Active settings.
 * @var array $statuses_all wc_get_order_statuses() (wc-slug => label).
 * @var array $columns      Collector::columns() (which columns can show, and why not).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Today\Admin;
use FisHotel\Misc\Sections\Today\Settings;

$fh_notes = (array) ( $columns['notes'] ?? array() );
?>
<div class="wrap fishotel-misc-wrap fh-today-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Today at FisHotel', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-today-tabs">
		<a class="fh-today-tab" href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Today', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-today-tab is-active" href="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<form id="fishotel-today-form"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		onsubmit="return false;">

		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_today_save">

		<div class="fh-today-columns">

			<?php /* ── Window ─────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-today-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Window', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'The page and the widget look from tomorrow up to this many days ahead for open orders with a delivery date, and lay out the first few delivery dates that have any.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-today-field fh-today-field--row">
					<label class="fh-today-label" for="fh-today-lookahead"><?php esc_html_e( 'Look ahead (days, 1–60)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-today-lookahead" class="fh-today-input fh-today-input--num" name="lookahead_days" min="<?php echo esc_attr( Settings::LOOKAHEAD_MIN ); ?>" max="<?php echo esc_attr( Settings::LOOKAHEAD_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['lookahead_days'] ); ?>">
				</div>

				<div class="fh-today-field fh-today-field--row">
					<label class="fh-today-label" for="fh-today-dates"><?php esc_html_e( 'Delivery dates shown (1–7)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-today-dates" class="fh-today-input fh-today-input--num" name="dates_shown" min="<?php echo esc_attr( Settings::DATES_MIN ); ?>" max="<?php echo esc_attr( Settings::DATES_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['dates_shown'] ); ?>">
				</div>

				<div class="fh-today-field fh-today-field--row">
					<label class="fh-today-label" for="fh-today-cache"><?php esc_html_e( 'Cache (minutes, 0 = off)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-today-cache" class="fh-today-input fh-today-input--num" name="cache_minutes" min="<?php echo esc_attr( Settings::CACHE_MIN ); ?>" max="<?php echo esc_attr( Settings::CACHE_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['cache_minutes'] ); ?>">
				</div>
				<p class="fishotel-misc-card-desc" style="margin: 6px 0 0;">
					<?php esc_html_e( 'The collected window is kept for this long so the dashboard stays quick. Any order change, a settings save and the Refresh button drop it at once.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-today-field">
					<span class="fh-today-label"><?php esc_html_e( 'Order statuses that count as open', 'fishotel-misc-plugin' ); ?></span>
					<div class="fh-today-checks">
						<?php foreach ( $statuses_all as $fh_slug => $fh_label ) : ?>
							<?php $fh_bare = 0 === strpos( $fh_slug, 'wc-' ) ? substr( $fh_slug, 3 ) : $fh_slug; ?>
							<label class="fh-today-check">
								<input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $fh_bare ); ?>" <?php checked( in_array( $fh_bare, $settings['statuses'], true ) ); ?>>
								<span><?php echo esc_html( $fh_label ); ?></span>
								<code><?php echo esc_html( $fh_bare ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<?php /* ── Columns ────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-today-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-editor-table"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Columns', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'Each column reads what another FisHotel plugin writes on the order. A column whose plugin is not active is hidden with a note, whatever the toggle says.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-today-field fh-today-field--row">
					<div>
						<span class="fh-today-label"><?php esc_html_e( 'Weather', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'The Weather section\'s note on the destination order: HEAT PACK / NOTHING / COOLING / UPS STORE / HOLD / AT THE LINE / UNAVAILABLE / NOT CHECKED.', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="show_weather" value="1" <?php checked( ! empty( $settings['show_weather'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-today-field fh-today-field--row">
					<div>
						<span class="fh-today-label"><?php esc_html_e( 'Invoice', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'Done when the PDF Invoices plugin has generated the invoice PDF (its _wcpdf_invoice_date meta; other keys via the fishotel_today_invoice_meta_keys filter). A combined box counts when the wrapper or every source order has it.', 'fishotel-misc-plugin' ); ?></p>
						<?php if ( ! empty( $fh_notes['invoice'] ) ) : ?>
							<p class="fh-today-colnote" style="margin: 4px 0 0;"><?php echo esc_html( $fh_notes['invoice'] ); ?></p>
						<?php endif; ?>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="show_invoice" value="1" <?php checked( ! empty( $settings['show_invoice'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-today-field fh-today-field--row">
					<div>
						<span class="fh-today-label"><?php esc_html_e( 'Tracking', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'Done when ShipTracker has a shipment on the destination order (or on every source order of a combined box), with the count and the newest status.', 'fishotel-misc-plugin' ); ?></p>
						<?php if ( ! empty( $fh_notes['tracking'] ) ) : ?>
							<p class="fh-today-colnote" style="margin: 4px 0 0;"><?php echo esc_html( $fh_notes['tracking'] ); ?></p>
						<?php endif; ?>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="show_tracking" value="1" <?php checked( ! empty( $settings['show_tracking'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-today-callout">
					<span class="dashicons dashicons-info-outline"></span>
					<span><?php esc_html_e( 'Fish and dry-goods counts come from the Batch plugin\'s Ship Day class when it is active (Invoice products expanded, shipped / n/a / refunded lines skipped); otherwise lines in the quarantined-fish and inverts categories count as fish and the rest as dry goods.', 'fishotel-misc-plugin' ); ?></span>
				</div>
			</div>

		</div><!-- .fh-today-columns -->

		<p class="fh-today-actions-row">
			<button type="submit" class="button button-primary fh-today-save">
				<?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>

	</form>
</div>
