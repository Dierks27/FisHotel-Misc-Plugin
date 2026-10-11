<?php
/**
 * Health settings tab.
 *
 * @package FisHotel\Misc\Sections\Health
 *
 * @var array     $settings   Active settings.
 * @var array     $last_prune Last prune summary (may be empty).
 * @var int|false $next_prune Next daily event timestamp.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Health\Admin;
use FisHotel\Misc\Sections\Health\Settings;
?>
<div class="wrap fishotel-misc-wrap fh-health-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Health', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-health-tabs">
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'overview' ) ); ?>"><?php esc_html_e( 'Overview', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'log' ) ); ?>"><?php esc_html_e( 'Log', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab is-active" href="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<form id="fishotel-health-form"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		onsubmit="return false;">

		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_health_save">

		<div class="fh-health-columns">

			<?php /* ── Retention ─────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-health-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-database"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Retention', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php
					if ( $next_prune ) {
						/* translators: %s: date/time */
						echo esc_html( sprintf( __( 'A daily job prunes the table. Next run: %s.', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_prune ) ) );
					} else {
						esc_html_e( 'A daily job prunes the table. It is not scheduled right now — saving re-arms it.', 'fishotel-misc-plugin' );
					}
					if ( ! empty( $last_prune['at'] ) ) {
						/* translators: 1: date, 2: rows */
						echo ' ' . esc_html( sprintf( __( 'Last prune %1$s removed %2$d rows.', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ), (int) $last_prune['at'] ), (int) ( $last_prune['deleted'] ?? 0 ) ) );
					}
					?>
				</p>

				<div class="fh-health-field fh-health-field--row">
					<label class="fh-health-label" for="fh-health-retention"><?php esc_html_e( 'Keep rows for (days)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-health-retention" class="fh-health-input fh-health-input--num" name="retention_days" min="<?php echo esc_attr( Settings::RETENTION_MIN ); ?>" max="<?php echo esc_attr( Settings::RETENTION_MAX ); ?>" step="1" required value="<?php echo esc_attr( $settings['retention_days'] ); ?>">
				</div>

				<div class="fh-health-field fh-health-field--row">
					<label class="fh-health-label" for="fh-health-max-rows"><?php esc_html_e( 'Row cap', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-health-max-rows" class="fh-health-input fh-health-input--num" name="max_rows" min="<?php echo esc_attr( Settings::MAX_ROWS_MIN ); ?>" max="<?php echo esc_attr( Settings::MAX_ROWS_MAX ); ?>" step="100" required value="<?php echo esc_attr( $settings['max_rows'] ); ?>">
				</div>

				<div class="fh-health-field fh-health-field--row">
					<div>
						<span class="fh-health-label"><?php esc_html_e( 'Store debug rows', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Off by default: debug-level calls are dropped so the table stays about failures.', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="store_debug" value="1" <?php checked( ! empty( $settings['store_debug'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			</div>

			<?php /* ── Capture ───────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-health-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-visibility"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Capture', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'wp_mail failures, the SMTP plugin\'s failover failures and fishotel_log calls are always captured. These two can be switched off.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-health-field fh-health-field--row">
					<div>
						<span class="fh-health-label"><?php esc_html_e( 'PHP fatals', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc"><?php esc_html_e( 'A shutdown handler records the fatal that ended a request (file and line, no request body).', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="capture_fatals" value="1" <?php checked( ! empty( $settings['capture_fatals'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-health-field fh-health-field--row">
					<div>
						<span class="fh-health-label"><?php esc_html_e( 'GitHub / updater HTTP failures', 'fishotel-misc-plugin' ); ?></span>
						<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Records 4xx/5xx and connection errors from github.com, api.github.com, raw.githubusercontent.com, codeload.github.com and objects.githubusercontent.com. Never the headers or the token.', 'fishotel-misc-plugin' ); ?></p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox" name="capture_http" value="1" <?php checked( ! empty( $settings['capture_http'] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>

				<div class="fh-health-field">
					<label class="fh-health-label" for="fh-health-hosts"><?php esc_html_e( 'Extra hosts to watch', 'fishotel-misc-plugin' ); ?></label>
					<input type="text" id="fh-health-hosts" class="fh-health-input" name="extra_hosts" value="<?php echo esc_attr( implode( ', ', $settings['extra_hosts'] ) ); ?>" placeholder="api.easypost.com, worldpressit.com">
					<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Comma-separated hostnames. Failures against these are logged with source "updater" too.', 'fishotel-misc-plugin' ); ?></p>
				</div>
			</div>

			<?php /* ── Alerts ────────────────────────────────────────── */ ?>
			<div class="fishotel-misc-card fh-health-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-email-alt"></span></div>
					<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Alert email', 'fishotel-misc-plugin' ); ?></h2>
				</div>
				<p class="fishotel-misc-card-desc">
					<?php esc_html_e( 'When an address is set, a new error or critical row from PHP, mail or the updater sends one plain-text email per cooldown window. The email never repeats within the window and can never loop on its own failure.', 'fishotel-misc-plugin' ); ?>
				</p>

				<div class="fh-health-field">
					<label class="fh-health-label" for="fh-health-alert-email"><?php esc_html_e( 'Send alerts to', 'fishotel-misc-plugin' ); ?></label>
					<input type="email" id="fh-health-alert-email" class="fh-health-input" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>" placeholder="<?php esc_attr_e( 'blank = alerts off', 'fishotel-misc-plugin' ); ?>">
				</div>

				<div class="fh-health-field fh-health-field--row">
					<label class="fh-health-label" for="fh-health-cooldown"><?php esc_html_e( 'Cooldown (minutes)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-health-cooldown" class="fh-health-input fh-health-input--num" name="alert_cooldown_minutes" min="<?php echo esc_attr( Settings::COOLDOWN_MIN ); ?>" max="<?php echo esc_attr( Settings::COOLDOWN_MAX ); ?>" step="5" required value="<?php echo esc_attr( $settings['alert_cooldown_minutes'] ); ?>">
				</div>
			</div>

		</div><!-- .fh-health-columns -->

		<p class="fh-health-actions-row">
			<button type="submit" class="button button-primary fh-health-save">
				<?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>

	</form>
</div>
