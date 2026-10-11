<?php
/**
 * Checks — fixture facts → expected statuses for every rule, the summary,
 * the update-age thresholds, the cron overdue math, kill-switch rows, the
 * fst-debug.log warning text and the GitHub 401 hint.
 *
 * Run from the plugin root:  php includes/sections/health/tests/checks-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Checks;
use FisHotel\Misc\Sections\Health\Facts;

$now = strtotime( '2026-04-01 21:00:00 UTC' );

/**
 * A site where everything is fine.
 */
function fh_healthy( $now ) {
	$plugin = static function ( $label, $version ) {
		return array(
			'label'     => $label,
			'version'   => $version,
			'installed' => true,
			'active'    => true,
		);
	};
	$update = static function ( $label, $age ) {
		return array(
			'label'      => $label,
			'active'     => true,
			'present'    => true,
			'checked_at' => 0,
			'age'        => $age,
		);
	};
	$hook   = static function ( $label, $owner, $next ) {
		return array(
			'label'           => $label,
			'owner'           => $owner,
			'owner_active'    => true,
			'inactive_reason' => '',
			'next'            => $next,
		);
	};

	return array(
		'now'                => $now,
		'php'                => array(
			'version'               => '8.2.10',
			'memory_limit'          => '512M',
			'memory_limit_bytes'    => 512 * 1024 * 1024,
			'memory_limit_global'   => '512M',
			'wp_memory_limit'       => '40M',
			'wp_max_memory_limit'   => '256M',
			'effective_limit'       => '512M',
			'effective_limit_bytes' => 512 * 1024 * 1024,
			'max_execution_time'    => 60,
		),
		'wp'                 => array(
			'version'       => '6.6',
			'debug'         => false,
			'debug_display' => false,
			'disable_cron'  => false,
			'object_cache'  => false,
		),
		'woocommerce'        => array(
			'active'  => true,
			'version' => '9.6.0',
			'hpos'    => true,
		),
		'disk_free'          => 20 * 1024 * 1024 * 1024,
		'plugins'            => array(
			'misc'        => $plugin( 'FisHotel Misc', '0.38' ),
			'batch'       => $plugin( 'FisHotel Batch Manager', '10.27' ),
			'shiptracker' => $plugin( 'FisHotel ShipTracker', '1.9.16' ),
			'smtp'        => $plugin( 'FisHotel SMTP', '1.2.5' ),
			'boards'      => $plugin( 'FH Boards', '1.8.5' ),
			'wpit'        => $plugin( 'WPIT Updater', '1.8.0' ),
		),
		'theme'              => array(
			'name'        => 'FisHotel',
			'version'     => '1.23.0',
			'is_fishotel' => true,
		),
		'dompdf'             => array(
			'available'     => true,
			'class'         => true,
			'autoload'      => true,
			'autoload_path' => '/theme/vendor/autoload.php',
		),
		'updates'            => array(
			'misc'      => $update( 'FisHotel Misc', 600 ),
			'batch'     => $update( 'FisHotel Batch Manager', 3600 ),
			'wordpress' => $update( 'WordPress plugin updates', 7200 ),
		),
		'github_auth_failed' => null,
		'cron'               => array(
			'hooks'           => array(
				'fishotel_weather_nightly'   => $hook( 'Weather nightly scan', 'weather', $now + 3600 ),
				'fishotel_health_daily'      => $hook( 'Health daily prune', 'misc', $now + 86400 ),
				'fishotel_verification_cron' => $hook( 'Batch verification', 'batch', $now + 1800 ),
				'fishotel_lastcall_cron'     => $hook( 'Batch last call', 'batch', $now + 1800 ),
				'fhsmtp_retry_failed_emails' => $hook( 'SMTP retry failed emails', 'smtp', $now + 900 ),
				'fst_poll_shipments'         => $hook( 'ShipTracker poll', 'shiptracker', $now + 600 ),
			),
			'last_run'        => $now - 600,
			'disable_wp_cron' => false,
		),
		'kill_switches'      => array(
			'shiptracker_installed' => true,
			'fst_fanout_constant'   => false,
			'fst_fanout_option'     => '1',
			'fst_debug_logging'     => false,
			'fst_debug_log_exists'  => false,
			'fst_debug_log_size'    => 0,
		),
		'sections'           => array(
			'enabled'            => array( 'weather', 'health' ),
			'weather_enabled'    => true,
			'smtp_retry_enabled' => true,
		),
		'mail_failures_24h'  => 0,
		'recent_errors'      => array(),
	);
}

function fh_row( array $rows, $id ) {
	foreach ( $rows as $row ) {
		if ( $row['id'] === $id ) {
			return $row;
		}
	}
	return null;
}

function fh_status( array $rows, $id ) {
	$row = fh_row( $rows, $id );
	return $row ? $row['status'] : 'MISSING';
}

echo "1. A healthy site\n";
$rows    = Checks::evaluate( fh_healthy( $now ), $now );
$summary = Checks::summary( $rows );
check( 'no errors or warnings', 'ok' === $summary['status'] && 0 === $summary['errors'] && 0 === $summary['warnings'], json_encode( $summary ) );
check( 'every row has the six keys', array_reduce( $rows, static function ( $ok, $row ) {
	return $ok && array( 'id', 'group', 'label', 'status', 'value', 'hint' ) === array_keys( $row );
}, true ) );
$groups = array_keys( Checks::by_group( $rows ) );
check( 'groups come out in the documented order', Checks::groups() === $groups, implode( ',', $groups ) );
check( 'every status is one of ok/warn/error/info', array_reduce( $rows, static function ( $ok, $row ) {
	return $ok && in_array( $row['status'], array( 'ok', 'warn', 'error', 'info' ), true );
}, true ) );
check( 'an active plugin shows its version', 'v10.27' === fh_row( $rows, 'plugin-batch' )['value'] );
check( 'the theme row shows FisHotel + version', 'FisHotel v1.23.0' === fh_row( $rows, 'theme' )['value'] );
check( 'WooCommerce row shows version + HPOS', 'v9.6.0, HPOS on' === fh_row( $rows, 'woocommerce' )['value'] );
check( 'an update checked 10 min ago is ok', 'ok' === fh_status( $rows, 'update-misc' ) && 'checked 10 min ago' === fh_row( $rows, 'update-misc' )['value'] );
check( 'GitHub auth row is ok without the transient', 'ok' === fh_status( $rows, 'github-auth' ) );
check( 'dompdf ok', 'ok' === fh_status( $rows, 'dompdf' ) );
check( 'cron hooks ok with a "next in" value', 'ok' === fh_status( $rows, 'cron-fishotel_weather_nightly' ) && 'next in 1 h' === fh_row( $rows, 'cron-fishotel_weather_nightly' )['value'] );
check( 'WP-Cron row is info with the last run', 'info' === fh_status( $rows, 'wp-cron' ) && false !== strpos( fh_row( $rows, 'wp-cron' )['value'], 'last run 10 min ago' ) );
check( 'kill switches ok', 'ok' === fh_status( $rows, 'fst-fanout' ) && 'ok' === fh_status( $rows, 'fst-debug-log' ) );
check( 'server rows ok', 'ok' === fh_status( $rows, 'php' ) && 'ok' === fh_status( $rows, 'memory' ) && 'ok' === fh_status( $rows, 'disk' ) && 'ok' === fh_status( $rows, 'debug-display' ) );
check( 'errors group: 0 mail failures, none logged', 'ok' === fh_status( $rows, 'mail-failures' ) && 'ok' === fh_status( $rows, 'recent-errors' ) );

echo "2. Plugins group\n";
$f = fh_healthy( $now );
$f['plugins']['batch']['active']        = false;
$f['plugins']['smtp']['installed']      = false;
$f['plugins']['smtp']['active']         = false;
$f['plugins']['smtp']['version']        = null;
$f['plugins']['misc']['active']         = false;
$f['theme']                             = array( 'name' => 'Twenty Twenty-Four', 'version' => '1.0', 'is_fishotel' => false );
$f['woocommerce']                       = array( 'active' => false, 'version' => null, 'hpos' => null );
$rows = Checks::evaluate( $f, $now );
check( 'inactive plugin → warn with "inactive"', 'warn' === fh_status( $rows, 'plugin-batch' ) && 0 === strpos( fh_row( $rows, 'plugin-batch' )['value'], 'inactive' ) );
check( 'missing plugin → warn with "not installed"', 'warn' === fh_status( $rows, 'plugin-smtp' ) && 'not installed' === fh_row( $rows, 'plugin-smtp' )['value'] );
check( 'Misc itself → info, never warn', 'info' === fh_status( $rows, 'plugin-misc' ) );
check( 'theme not FisHotel → error naming the theme', 'error' === fh_status( $rows, 'theme' ) && 'Twenty Twenty-Four' === fh_row( $rows, 'theme' )['value'] );
check( 'WooCommerce inactive → warn', 'warn' === fh_status( $rows, 'woocommerce' ) );

echo "3. Updates group — age thresholds\n";
$f = fh_healthy( $now );
$f['updates']['misc']['age']  = 24 * 3600 + 59 * 60;       // 24 h 59 min
$f['updates']['batch']['age'] = 25 * 3600;                 // exactly 25 h
$f['updates']['wordpress']['age'] = 25 * 3600 + 1;         // 25 h + 1 s
$f['updates']['smtp'] = array( 'label' => 'FisHotel SMTP', 'active' => true, 'present' => false, 'checked_at' => null, 'age' => null );
$f['updates']['wpit'] = array( 'label' => 'WPIT Updater', 'active' => false, 'present' => false, 'checked_at' => null, 'age' => null );
$rows = Checks::evaluate( $f, $now );
check( '24 h 59 min → ok', 'ok' === fh_status( $rows, 'update-misc' ), fh_row( $rows, 'update-misc' )['value'] );
check( 'exactly 25 h → still ok (rule is "older than")', 'ok' === fh_status( $rows, 'update-batch' ) );
check( '25 h + 1 s → warn "last update check 25 h ago"', 'warn' === fh_status( $rows, 'update-wordpress' ) && 'last update check 25 h ago' === fh_row( $rows, 'update-wordpress' )['value'], fh_row( $rows, 'update-wordpress' )['value'] );
check( 'never → warn "last update check never"', 'warn' === fh_status( $rows, 'update-smtp' ) && 'last update check never' === fh_row( $rows, 'update-smtp' )['value'] );
check( 'an inactive plugin gets no update row', null === fh_row( $rows, 'update-wpit' ) );
$f['updates']['misc']['age'] = 3 * 86400;
$rows = Checks::evaluate( $f, $now );
check( 'three days → "72 h ago" (hours, not days, so the number is obvious)', 'last update check 72 h ago' === fh_row( $rows, 'update-misc' )['value'] );

echo "4. Updates group — GitHub 401\n";
$f = fh_healthy( $now );
$f['github_auth_failed'] = array( 'host' => 'api.github.com', 'code' => 401, 'time' => $now - 2 * 3600 );
$rows = Checks::evaluate( $f, $now );
$row  = fh_row( $rows, 'github-auth' );
check( '401 transient → error', 'error' === $row['status'] );
check( 'value names the code, host and age', 'GitHub returned 401 for api.github.com 2 h ago' === $row['value'], $row['value'] );
check( 'hint is the documented one', 'GitHub returned 401 — the token is expired or the repo is private; see wpit/updater settings' === $row['hint'], $row['hint'] );
$f['github_auth_failed']['code'] = 403;
$rows = Checks::evaluate( $f, $now );
check( '403 uses its own code in the hint', 0 === strpos( fh_row( $rows, 'github-auth' )['hint'], 'GitHub returned 403' ) );

echo "5. Paperwork group\n";
$f = fh_healthy( $now );
$f['dompdf'] = array( 'available' => false, 'class' => false, 'autoload' => false, 'autoload_path' => '/theme/vendor/autoload.php' );
$rows = Checks::evaluate( $f, $now );
check( 'dompdf missing → error', 'error' === fh_status( $rows, 'dompdf' ) );
check( 'with the packing-slip hint', 'EA packing slips send without the PDF' === fh_row( $rows, 'dompdf' )['hint'] );
$f['dompdf'] = array( 'available' => true, 'class' => false, 'autoload' => true, 'autoload_path' => '/theme/vendor/autoload.php' );
$rows = Checks::evaluate( $f, $now );
check( 'autoload present without the class loaded yet → ok', 'ok' === fh_status( $rows, 'dompdf' ) && 'theme vendor/autoload.php present' === fh_row( $rows, 'dompdf' )['value'] );

echo "6. Cron group\n";
$f = fh_healthy( $now );
$f['cron']['hooks']['fishotel_verification_cron']['next'] = false;                                   // active, not scheduled
$f['cron']['hooks']['fishotel_lastcall_cron']['owner_active'] = false;                                // plugin inactive
$f['cron']['hooks']['fishotel_lastcall_cron']['next'] = false;
$f['cron']['hooks']['fhsmtp_retry_failed_emails']['next'] = $now - 2 * 3600 - 1;                      // 2 h + 1 s overdue
$f['cron']['hooks']['fst_poll_shipments']['next'] = $now - 2 * 3600;                                  // exactly 2 h: not overdue
$f['cron']['hooks']['fishotel_health_daily']['next'] = $now - 1800;                                   // 30 min late: fine
$f['cron']['hooks']['fishotel_weather_nightly']['next'] = false;                                      // weather enabled, missing
$rows = Checks::evaluate( $f, $now );
check( 'active plugin, hook not scheduled → error', 'error' === fh_status( $rows, 'cron-fishotel_verification_cron' ) && 'not scheduled' === fh_row( $rows, 'cron-fishotel_verification_cron' )['value'] );
check( 'inactive owner → info, not an error', 'info' === fh_status( $rows, 'cron-fishotel_lastcall_cron' ) );
check( '2 h + 1 s in the past → warn "WP-Cron overdue"', 'warn' === fh_status( $rows, 'cron-fhsmtp_retry_failed_emails' ) && 0 === strpos( fh_row( $rows, 'cron-fhsmtp_retry_failed_emails' )['value'], 'WP-Cron overdue' ), fh_row( $rows, 'cron-fhsmtp_retry_failed_emails' )['value'] );
check( 'exactly 2 h in the past → still ok', 'ok' === fh_status( $rows, 'cron-fst_poll_shipments' ) );
check( '30 min late → ok with "due 30 min ago"', 'ok' === fh_status( $rows, 'cron-fishotel_health_daily' ) && 'due 30 min ago' === fh_row( $rows, 'cron-fishotel_health_daily' )['value'] );
check( 'Weather enabled but nightly missing → error with the Weather hint', 'error' === fh_status( $rows, 'cron-fishotel_weather_nightly' ) && false !== strpos( fh_row( $rows, 'cron-fishotel_weather_nightly' )['hint'], 'Weather settings' ) );
$f['cron']['hooks']['fishotel_weather_nightly']['owner_active'] = false;
$rows = Checks::evaluate( $f, $now );
check( 'Weather disabled and nightly missing → info', 'info' === fh_status( $rows, 'cron-fishotel_weather_nightly' ) );

// FisHotel SMTP active but "Enable Auto-Retry" off: it never schedules the
// hook, so a missing event is expected, not an error (the default config).
$f = fh_healthy( $now );
$f['cron']['hooks']['fhsmtp_retry_failed_emails']['owner_active']    = false;
$f['cron']['hooks']['fhsmtp_retry_failed_emails']['inactive_reason'] = 'retry off';
$f['cron']['hooks']['fhsmtp_retry_failed_emails']['next']            = false;
$rows = Checks::evaluate( $f, $now );
$row  = fh_row( $rows, 'cron-fhsmtp_retry_failed_emails' );
check( 'SMTP active, retry off, hook missing → info "retry off", not error', 'info' === $row['status'] && 'retry off' === $row['value'], json_encode( $row ) );
check( 'with a hint pointing at the SMTP setting', false !== strpos( $row['hint'], 'Enable Auto-Retry' ) );
check( 'and the summary stays ok', 'ok' === Checks::summary( $rows )['status'] );
$f['cron']['hooks']['fhsmtp_retry_failed_emails']['inactive_reason'] = '';
$rows = Checks::evaluate( $f, $now );
check( 'no reason → the generic "owner not active" with no hint', 'owner not active' === fh_row( $rows, 'cron-fhsmtp_retry_failed_emails' )['value'] && '' === fh_row( $rows, 'cron-fhsmtp_retry_failed_emails' )['hint'] );

// Facts::cron() derives that from the plugin + the SMTP setting.
$plugins_facts  = array( 'smtp' => array( 'active' => true ) );
$sections_facts = array( 'weather_enabled' => false, 'smtp_retry_enabled' => false );
$cron_facts     = Facts::cron( $plugins_facts, $sections_facts );
check( 'Facts::cron(): SMTP active + retry off → owner_active false, reason "retry off"', false === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['owner_active'] && 'retry off' === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['inactive_reason'] );
$sections_facts['smtp_retry_enabled'] = true;
$cron_facts = Facts::cron( $plugins_facts, $sections_facts );
check( 'Facts::cron(): SMTP active + retry on → owner_active true', true === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['owner_active'] && '' === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['inactive_reason'] );
$cron_facts = Facts::cron( array( 'smtp' => array( 'active' => false ) ), $sections_facts );
check( 'Facts::cron(): SMTP inactive → owner_active false, no reason', false === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['owner_active'] && '' === $cron_facts['hooks']['fhsmtp_retry_failed_emails']['inactive_reason'] );
$GLOBALS['fh_options']['fhsmtp_log_settings'] = array( 'logging_enabled' => '1', 'retention_days' => 30 );
check( 'Facts::sections(): the SMTP activation defaults (no retry_enabled) read as retry off', false === Facts::sections()['smtp_retry_enabled'] );
$GLOBALS['fh_options']['fhsmtp_log_settings']['retry_enabled'] = '1';
check( 'Facts::sections(): retry_enabled "1" reads as on', true === Facts::sections()['smtp_retry_enabled'] );
unset( $GLOBALS['fh_options']['fhsmtp_log_settings'] );

echo "7. Cron group — DISABLE_WP_CRON\n";
$f = fh_healthy( $now );
$f['cron']['disable_wp_cron'] = true;
$f['cron']['last_run']        = null;
$rows = Checks::evaluate( $f, $now );
check( 'disabled + never seen → error', 'error' === fh_status( $rows, 'wp-cron' ) );
$f['cron']['last_run'] = $now - 3601;
$rows = Checks::evaluate( $f, $now );
check( 'disabled + last run 1 h + 1 s ago → error', 'error' === fh_status( $rows, 'wp-cron' ) && false !== strpos( fh_row( $rows, 'wp-cron' )['value'], '1 h ago' ) );
$f['cron']['last_run'] = $now - 3600;
$rows = Checks::evaluate( $f, $now );
check( 'disabled + last run exactly 1 h ago → info', 'info' === fh_status( $rows, 'wp-cron' ) );
$f['cron']['last_run'] = $now - 600;
$rows = Checks::evaluate( $f, $now );
check( 'disabled + last run 10 min ago → info', 'info' === fh_status( $rows, 'wp-cron' ) );
$f['cron']['disable_wp_cron'] = false;
$f['cron']['last_run']        = null;
$rows = Checks::evaluate( $f, $now );
check( 'built-in cron, never seen → info (request-driven)', 'info' === fh_status( $rows, 'wp-cron' ) );

echo "8. Kill switches\n";
$f = fh_healthy( $now );
$f['kill_switches']['fst_fanout_constant'] = true;
$rows = Checks::evaluate( $f, $now );
check( 'FST_FULFILLMENT_FANOUT_OFF → warn "ShipTracker fan-out is OFF"', 'warn' === fh_status( $rows, 'fst-fanout' ) && 'ShipTracker fan-out is OFF' === fh_row( $rows, 'fst-fanout' )['value'] );
check( 'hint names the constant', false !== strpos( fh_row( $rows, 'fst-fanout' )['hint'], 'FST_FULFILLMENT_FANOUT_OFF' ) );
$f['kill_switches']['fst_fanout_constant'] = false;
$f['kill_switches']['fst_fanout_option']   = '0';
$rows = Checks::evaluate( $f, $now );
check( 'option "0" → warn, hint names the option', 'warn' === fh_status( $rows, 'fst-fanout' ) && false !== strpos( fh_row( $rows, 'fst-fanout' )['hint'], 'fst_fulfillment_fanout_enabled' ) );
$f['kill_switches']['fst_fanout_option']     = '1';
$f['kill_switches']['fst_debug_logging']     = true;
$f['kill_switches']['fst_debug_log_exists']  = true;
$f['kill_switches']['fst_debug_log_size']    = 12 * 1024 - 100;
$rows = Checks::evaluate( $f, $now );
$row  = fh_row( $rows, 'fst-debug-log' );
check( 'fst_debug_logging on → warn', 'warn' === $row['status'] );
check( 'warning text is the documented one with the size', 'fst-debug.log is web-readable at /wp-content/fst-debug.log (12 KB): turn off debug logging or move it (ShipTracker fix pending)' === $row['value'], $row['value'] );
$f['kill_switches']['fst_debug_logging'] = false;
$rows = Checks::evaluate( $f, $now );
check( 'logging off but file still there → info with the size', 'info' === fh_status( $rows, 'fst-debug-log' ) && false !== strpos( fh_row( $rows, 'fst-debug-log' )['value'], '12 KB' ) );
$f['kill_switches']['shiptracker_installed'] = false;
$rows = Checks::evaluate( $f, $now );
check( 'no ShipTracker → no kill-switch rows at all', null === fh_row( $rows, 'fst-fanout' ) && null === fh_row( $rows, 'fst-debug-log' ) );

echo "9. Server group\n";
$f = fh_healthy( $now );
$f['php']['version']               = '7.4.33';
$f['php']['memory_limit']          = '256M';              // what wp-admin raised this request to
$f['php']['memory_limit_bytes']    = 256 * 1024 * 1024;
$f['php']['memory_limit_global']   = '128M';              // php.ini
$f['php']['effective_limit']       = '128M';              // front-end / admin-ajax / admin-post
$f['php']['effective_limit_bytes'] = 128 * 1024 * 1024;
$f['wp']['debug']                  = true;
$f['wp']['debug_display']          = true;
$f['disk_free']                    = 1536 * 1024 * 1024; // 1.5 GB
$rows = Checks::evaluate( $f, $now );
check( 'PHP < 8.0 → info', 'info' === fh_status( $rows, 'php' ) && '7.4.33' === fh_row( $rows, 'php' )['value'] );
check( 'effective limit 128M → warn even though wp-admin runs at 256M, showing both', 'warn' === fh_status( $rows, 'memory' ) && '128M (admin 256M)' === fh_row( $rows, 'memory' )['value'], fh_row( $rows, 'memory' )['value'] );
check( 'WP_DEBUG + WP_DEBUG_DISPLAY on → warn', 'warn' === fh_status( $rows, 'debug-display' ) );
check( '1.5 GB free → warn', 'warn' === fh_status( $rows, 'disk' ) && '1.5 GB' === fh_row( $rows, 'disk' )['value'], fh_row( $rows, 'disk' )['value'] );
$f['disk_free'] = 400 * 1024 * 1024;
$rows = Checks::evaluate( $f, $now );
check( '400 MB free → error', 'error' === fh_status( $rows, 'disk' ) && '400 MB' === fh_row( $rows, 'disk' )['value'] );
$f['disk_free']                    = null;
$f['php']['memory_limit']          = '-1';
$f['php']['memory_limit_bytes']    = -1;
$f['php']['effective_limit']       = '-1';
$f['php']['effective_limit_bytes'] = -1;
$f['php']['version']               = '8.0.0';
$f['wp']['debug_display']          = false;
$rows = Checks::evaluate( $f, $now );
check( 'unknown disk → info', 'info' === fh_status( $rows, 'disk' ) );
check( 'unlimited memory → ok', 'ok' === fh_status( $rows, 'memory' ) && 'unlimited' === fh_row( $rows, 'memory' )['value'] );
check( 'PHP 8.0 exactly → ok', 'ok' === fh_status( $rows, 'php' ) );
check( 'WP_DEBUG on with display off → ok, says so', 'ok' === fh_status( $rows, 'debug-display' ) && 'off (WP_DEBUG on, display off)' === fh_row( $rows, 'debug-display' )['value'] );
check( 'WordPress row is info and mentions the object cache', 'info' === fh_status( $rows, 'wp-version' ) && false !== strpos( fh_row( $rows, 'wp-version' )['value'], 'no persistent object cache' ) );
// Older facts without the effective_* keys still work (memory_limit alone).
$f['php'] = array( 'version' => '8.2.0', 'memory_limit' => '128M', 'memory_limit_bytes' => 128 * 1024 * 1024, 'max_execution_time' => 30 );
$rows     = Checks::evaluate( $f, $now );
check( 'without effective_* keys the row falls back to memory_limit', 'warn' === fh_status( $rows, 'memory' ) && '128M' === fh_row( $rows, 'memory' )['value'] );
$f['php']['memory_limit']       = '512M';
$f['php']['memory_limit_bytes'] = 512 * 1024 * 1024;
$rows                           = Checks::evaluate( $f, $now );
check( 'equal admin and effective limits show one number', 'ok' === fh_status( $rows, 'memory' ) && '512M' === fh_row( $rows, 'memory' )['value'] );
// The live fact mirrors wp_debug_mode(): the stubs define WP_DEBUG false
// and WP_DEBUG_DISPLAY true, exactly core's defaults on a stock wp-config.
$wp_facts = Facts::wp();
check( 'Facts::wp(): WP_DEBUG off + WP_DEBUG_DISPLAY true (core default) → debug_display false', false === $wp_facts['debug_display'] && false === $wp_facts['debug'] );
check( 'and the default install gets an ok debug-display row', 'ok' === fh_status( Checks::evaluate( array_merge( fh_healthy( $now ), array( 'wp' => $wp_facts ) ), $now ), 'debug-display' ) );
$php_facts = Facts::php();
check( 'Facts::php(): effective limit is the larger of php.ini and WP_MEMORY_LIMIT (never below 40M here)', isset( $php_facts['effective_limit_bytes'] ) && ( -1 === $php_facts['effective_limit_bytes'] || $php_facts['effective_limit_bytes'] >= 40 * 1024 * 1024 ) && '40M' === $php_facts['wp_memory_limit'] && '256M' === $php_facts['wp_max_memory_limit'], json_encode( $php_facts ) );
check( 'to_bytes', 128 * 1024 * 1024 === Facts::to_bytes( '128M' ) && -1 === Facts::to_bytes( '-1' ) && 2 * 1024 * 1024 * 1024 === Facts::to_bytes( '2G' ) && 0 === Facts::to_bytes( '' ) );

echo "10. Errors group\n";
$f = fh_healthy( $now );
$f['mail_failures_24h'] = 3;
$f['recent_errors']     = array();
for ( $i = 1; $i <= 7; $i++ ) {
	$f['recent_errors'][] = array(
		'id'        => $i,
		'logged_at' => gmdate( 'Y-m-d H:i:s', $now - $i * 3600 ),
		'logged_ts' => $now - $i * 3600,
		'level'     => 'error',
		'source'    => 'mail',
		'message'   => "wp_mail failed for order $i " . str_repeat( 'x', 200 ),
		'context'   => array( 'repeats' => 1 === $i ? 4 : 1 ),
		'repeats'   => 1 === $i ? 4 : 1,
	);
}
$errors_fixture = $f;
$rows           = Checks::evaluate( $f, $now );
check( 'mail failures > 0 → error with the count', 'error' === fh_status( $rows, 'mail-failures' ) && '3' === fh_row( $rows, 'mail-failures' )['value'] );
$error_rows = array_values( array_filter( $rows, static function ( $row ) {
	return 0 === strpos( $row['id'], 'error-' );
} ) );
check( 'only the last 5 errors become rows', 5 === count( $error_rows ) );
check( 'each (1–5 h old) is status error in the Errors group with a relative age', 'error' === $error_rows[0]['status'] && 'error' === $error_rows[4]['status'] && Checks::GROUP_ERRORS === $error_rows[0]['group'] && '1 h ago' === $error_rows[0]['value'] && '5 h ago' === $error_rows[4]['value'] );
check( 'label is source: message, message capped', 0 === strpos( $error_rows[0]['label'], 'mail: wp_mail failed for order 1' ) && strlen( $error_rows[0]['label'] ) <= 170 );
check( 'repeats show in the hint', 'repeated 4× within 10 minutes' === $error_rows[0]['hint'] && '' === $error_rows[1]['hint'] );
check( 'no "none logged" row when there are errors', null === fh_row( $rows, 'recent-errors' ) );

// Age: an error older than 24 h is history (info), not a live problem.
$f = fh_healthy( $now );
$f['recent_errors'] = array(
	array( 'id' => 1, 'logged_ts' => $now - 24 * 3600, 'level' => 'critical', 'source' => 'php', 'message' => 'E_ERROR: boom in x.php:1', 'context' => array(), 'repeats' => 1 ),
	array( 'id' => 2, 'logged_ts' => $now - 24 * 3600 - 1, 'level' => 'error', 'source' => 'updater', 'message' => 'GitHub returned 401 for api.github.com/x', 'context' => array(), 'repeats' => 1 ),
	array( 'id' => 3, 'logged_ts' => $now - 21 * 86400, 'level' => 'critical', 'source' => 'php', 'message' => 'E_ERROR: old fatal', 'context' => array(), 'repeats' => 1 ),
);
$rows = Checks::evaluate( $f, $now );
check( 'exactly 24 h old → still error', 'error' === fh_status( $rows, 'error-1' ) );
check( '24 h + 1 s old → info, with its age', 'info' === fh_status( $rows, 'error-2' ) && '24 h ago' === fh_row( $rows, 'error-2' )['value'] );
check( '21 days old → info "21 d ago"', 'info' === fh_status( $rows, 'error-3' ) && '21 d ago' === fh_row( $rows, 'error-3' )['value'] );
check( 'summary counts only the fresh one', array( 'status' => 'error', 'errors' => 1, 'warnings' => 0 ) === Checks::summary( $rows ) );
$f['recent_errors'] = array( $f['recent_errors'][2] );
$rows = Checks::evaluate( $f, $now );
check( 'one 3-week-old fatal alone → summary ok, not "Action needed"', 'ok' === Checks::summary( $rows )['status'] && 'info' === fh_status( $rows, 'error-3' ) );
check( 'and problems() does not list it', array() === Checks::problems( $rows ) );

// Multibyte: a byte-wise cut would split the 'é' and esc_html() would
// then blank the whole label. 157 ASCII bytes + 'é' (2 bytes) straddles
// the 160/159-byte boundary.
$f['recent_errors'] = array(
	array( 'id' => 9, 'logged_ts' => $now - 60, 'level' => 'error', 'source' => 'mail', 'message' => str_repeat( 'a', 157 ) . 'é' . str_repeat( 'b', 60 ), 'context' => array(), 'repeats' => 1 ),
);
$rows  = Checks::evaluate( $f, $now );
$label = fh_row( $rows, 'error-9' )['label'];
check( 'a message cut at a multibyte boundary is still valid UTF-8', false !== mb_check_encoding( $label, 'UTF-8' ) && '…' === mb_substr( $label, -1 ), bin2hex( substr( $label, -8 ) ) );
check( 'and no longer than source + 160 bytes', strlen( $label ) <= strlen( 'mail: ' ) + 160 );
check( 'cut(): short text untouched', 'abc' === Checks::cut( 'abc', 160 ) && '' === Checks::cut( '', 10 ) );
check( 'cut(): ASCII cut keeps cap − 3 bytes + ellipsis', str_repeat( 'x', 117 ) . '…' === Checks::cut( str_repeat( 'x', 200 ), 120 ) && 120 === strlen( Checks::cut( str_repeat( 'x', 200 ), 120 ) ) );
$cut_sample = Checks::cut( str_repeat( 'a', 116 ) . 'é' . str_repeat( 'b', 50 ), 120 );
check( 'cut(): never splits a character (widget 120-byte cap)', false !== mb_check_encoding( $cut_sample, 'UTF-8' ) && strlen( $cut_sample ) <= 120 && str_repeat( 'a', 116 ) . '…' === $cut_sample, bin2hex( $cut_sample ) );

echo "12. Facts with a store — mail failures count events, not folded rows\n";
$store12  = new \FisHotel\Misc\Sections\Health\Tests\Fake_Store();
$logger12 = new \FisHotel\Misc\Sections\Health\Logger( $store12, array() );
for ( $i = 1; $i <= 30; $i++ ) {
	$logger12->log( 'error', 'mail', "SMTP connect() failed for order $i" );
}
$logger12->log( 'error', 'mail', 'Could not authenticate' );
$logger12->log( 'warning', 'mail', 'SMTP connect() failed for order 99' ); // different level: not an error row, still a mail row
check( '30 identical failures folded into one row', 2 === count( array_filter( $store12->rows, static function ( $r ) { return 'error' === $r['level']; } ) ) );
$facts12 = Facts::collect( $store12 );
check( 'mail_failures_24h sums the repeats: 30 + 1 + 1', 32 === $facts12['mail_failures_24h'], (string) $facts12['mail_failures_24h'] );
check( 'count_since would have said 3 rows', 3 === $store12->count_since( '', time() - 86400, 'mail' ) );
check( 'recent_errors is passed through', 2 === count( $facts12['recent_errors'] ) );
check( 'the Errors row shows the summed count', '32' === fh_row( Checks::evaluate( $facts12, $facts12['now'] ), 'mail-failures' )['value'] );

echo "11. Summary and helpers\n";
check( 'summary: warn when only warnings', 'warn' === Checks::summary( array( array( 'status' => 'ok' ), array( 'status' => 'warn' ), array( 'status' => 'info' ) ) )['status'] );
check( 'summary: error beats warn', 'error' === Checks::summary( array( array( 'status' => 'warn' ), array( 'status' => 'error' ), array( 'status' => 'warn' ) ) )['status'] );
check( 'summary counts', array( 'status' => 'error', 'errors' => 1, 'warnings' => 2 ) === Checks::summary( array( array( 'status' => 'warn' ), array( 'status' => 'error' ), array( 'status' => 'warn' ) ) ) );
check( 'summary: info only → ok', 'ok' === Checks::summary( array( array( 'status' => 'info' ) ) )['status'] );
check( 'summary: empty → ok', 'ok' === Checks::summary( array() )['status'] );
$rows     = Checks::evaluate( $errors_fixture, $now ); // 3 mail failures + 5 fresh error rows
$problems = Checks::problems( $rows, 3 );
check( 'problems(): errors first, capped', 3 === count( $problems ) && 'error' === $problems[0]['status'] );
$mixed = Checks::problems( array( array( 'status' => 'warn', 'id' => 'w' ), array( 'status' => 'ok', 'id' => 'o' ), array( 'status' => 'error', 'id' => 'e' ) ), 8 );
check( 'problems(): error before warn, ok dropped', array( 'e', 'w' ) === array_column( $mixed, 'id' ) );
check( 'relative_age', 'just now' === Checks::relative_age( 30 ) && '5 min ago' === Checks::relative_age( 330 ) && '3 h ago' === Checks::relative_age( 3 * 3600 + 59 ) && '47 h ago' === Checks::relative_age( 47 * 3600 ) && '2 d ago' === Checks::relative_age( 2 * 86400 ) );
check( 'kb rounds up', '12 KB' === Checks::kb( 12 * 1024 - 100 ) && '0 KB' === Checks::kb( 0 ) && '1 KB' === Checks::kb( 1 ) );
check( 'bytes', '1.5 GB' === Checks::bytes( 1536 * 1024 * 1024 ) && '400 MB' === Checks::bytes( 400 * 1024 * 1024 ) && '3 KB' === Checks::bytes( 3000 ) );
check( 'evaluate() tolerates empty facts', is_array( Checks::evaluate( array(), $now ) ) && count( Checks::evaluate( array(), $now ) ) > 0 );

fh_finish();
