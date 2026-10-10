<?php
/**
 * Weather settings tab.
 *
 * @package FisHotel\Misc\Sections\Weather
 *
 * @var array  $settings     Active settings.
 * @var array  $statuses_all wc_get_order_statuses() (wc-slug => label).
 * @var string $next_run     Next scheduled run label (may be '').
 * @var array  $last_run     Last run summary (may be empty).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Weather\Bands;
use FisHotel\Misc\Sections\Weather\Settings;
use FisHotel\Misc\Sections\Weather\Weather;

$fh_page_url  = add_query_arg( array( 'page' => Weather::MENU_SLUG ), admin_url( 'admin.php' ) );
$fh_threshold = array(
	'cold_hold_below' => __( 'HOLD (wait to ship) when the high is below', 'fishotel-misc-plugin' ),
	'cold_ups_to'     => __( 'UPS Store pickup (cold) up to', 'fishotel-misc-plugin' ),
	'heat_pack_to'    => __( 'Heat pack up to', 'fishotel-misc-plugin' ),
	'nothing_to'      => __( 'Nothing needed up to', 'fishotel-misc-plugin' ),
	'cooling_to'      => __( 'Cooling packs up to', 'fishotel-misc-plugin' ),
	'hot_ups_to'      => __( 'UPS Store pickup (hot) up to — HOLD (no ship) above', 'fishotel-misc-plugin' ),
);
?>
<div class="wrap fishotel-misc-wrap fh-weather-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Weather', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-weather-tabs">
		<a class="fh-weather-tab" href="<?php echo esc_url( $fh_page_url ); ?>"><?php esc_html_e( 'Overview', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-weather-tab is-active" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $fh_page_url ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<form id="fishotel-weather-form"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		onsubmit="return false;">

		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_weather_save">

		<div class="fh-weather-columns">

			<?php /* ── Nightly job ───────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-weather-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-clock"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Nightly job', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'Once a night, every open order delivering tomorrow through three days out gets its delivery-day forecast looked up, one private note written, and the packing action recorded. Orders already noted for their delivery date are skipped.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-weather-field fh-weather-field--row">
					<div>
						<span class="fh-weather-label"><?php esc_html_e( 'Enable the nightly job', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc fh-weather-next-run" style="margin: 4px 0 0;">
							<?php
							if ( '' !== $next_run ) {
								/* translators: %s: date/time */
								echo esc_html( sprintf( __( 'Next run: %s', 'fishotel-misc-plugin' ), $next_run ) );
							} else {
								esc_html_e( 'Not scheduled.', 'fishotel-misc-plugin' );
							}
							?>
						</p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-weather-field">
					<label class="fh-weather-label" for="fh-weather-run-time"><?php esc_html_e( 'Run at (site time)', 'fishotel-misc-plugin' ); ?></label>
					<input type="time" id="fh-weather-run-time" class="fh-weather-input fh-weather-input--short" name="run_time" value="<?php echo esc_attr( $settings['run_time'] ); ?>" step="60">
					<p class="fishotel-misc-card-desc" style="margin: 6px 0 0;">
						<?php
						/* translators: %s: timezone name */
						echo esc_html( sprintf( __( 'Site timezone: %s. Default 21:00.', 'fishotel-misc-plugin' ), wp_timezone_string() ) );
						?>
					</p>
				</div>

				<div class="fh-weather-field">
					<label class="fh-weather-label" for="fh-weather-contact"><?php esc_html_e( 'Contact email for the NWS User-Agent', 'fishotel-misc-plugin' ); ?></label>
					<input type="email" id="fh-weather-contact" class="fh-weather-input" name="contact_email" value="<?php echo esc_attr( $settings['contact_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					<p class="fishotel-misc-card-desc" style="margin: 6px 0 0;">
						<?php esc_html_e( 'The National Weather Service asks every API client to identify itself with a contact. Blank = the site admin email.', 'fishotel-misc-plugin' ); ?>
					</p>
				</div>

				<div class="fh-weather-field">
					<span class="fh-weather-label"><?php esc_html_e( 'Order statuses to scan', 'fishotel-misc-plugin' ); ?></span>
					<div class="fh-weather-checks">
						<?php foreach ( $statuses_all as $fh_slug => $fh_label ) : ?>
							<?php $fh_bare = 0 === strpos( $fh_slug, 'wc-' ) ? substr( $fh_slug, 3 ) : $fh_slug; ?>
							<label class="fh-weather-check">
								<input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $fh_bare ); ?>" <?php checked( in_array( $fh_bare, $settings['statuses'], true ) ); ?>>
								<span><?php echo esc_html( $fh_label ); ?></span>
								<code><?php echo esc_html( $fh_bare ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="fh-weather-field fh-weather-field--row">
					<div>
						<span class="fh-weather-label"><?php esc_html_e( 'Also note the source orders of a combined box', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc" style="margin: 4px 0 0;"><?php esc_html_e( 'A fulfillment order is the destination; with this on, each order combined into it gets the same note and badge.', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="note_sources" value="1" <?php checked( ! empty( $settings['note_sources'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			</div>

			<?php /* ── Bands ─────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-weather-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Temperature bands (°F)', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'Every band goes by the delivery-day HIGH at the destination, cold side included. Each line must be higher than the one above it. A high exactly on a line is flagged "AT THE LINE" for Jeff to decide and stored as the more cautious of the two bands.', 'fishotel-misc-plugin' ); ?>
				</p>

				<?php foreach ( $fh_threshold as $fh_key => $fh_label ) : ?>
					<div class="fh-weather-field fh-weather-field--row">
						<label class="fh-weather-label" for="fh-weather-t-<?php echo esc_attr( $fh_key ); ?>"><?php echo esc_html( $fh_label ); ?></label>
						<input type="number" id="fh-weather-t-<?php echo esc_attr( $fh_key ); ?>" class="fh-weather-input fh-weather-input--num" name="thresholds[<?php echo esc_attr( $fh_key ); ?>]" step="1" min="<?php echo esc_attr( Bands::THRESHOLD_MIN ); ?>" max="<?php echo esc_attr( Bands::THRESHOLD_MAX ); ?>" required value="<?php echo esc_attr( $settings['thresholds'][ $fh_key ] ); ?>" data-default="<?php echo esc_attr( Bands::DEFAULT_THRESHOLDS[ $fh_key ] ); ?>">
					</div>
				<?php endforeach; ?>

				<p class="fh-weather-field">
					<button type="button" class="button fh-weather-reset"><?php esc_html_e( 'Reset to defaults', 'fishotel-misc-plugin' ); ?></button>
					<span class="fishotel-misc-card-desc"><?php esc_html_e( '30 / 40 / 70 / 80 / 90 / 95 — the chart from the order workflow skill.', 'fishotel-misc-plugin' ); ?></span>
				</p>

				<div class="fh-weather-callout">
					<span class="dashicons dashicons-info-outline"></span>
					<span>
						<?php esc_html_e( 'Note endings, verbatim from the skill: "Heat pack." / "Nothing needed — great shipping conditions." / "Cooling packs." / "UPS STORE (30-40°F): ship to local UPS Store for customer pickup. Email drafted for customer." / "HOLD: below 30°F — wait to ship." / "HOLD: above 95°F — no ship." The numbers follow these thresholds.', 'fishotel-misc-plugin' ); ?>
					</span>
				</div>
			</div>

		</div><!-- .fh-weather-columns -->

		<p class="fh-weather-actions-row">
			<button type="submit" class="button button-primary fh-weather-save">
				<?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>

	</form>
</div>
