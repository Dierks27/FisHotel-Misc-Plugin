<?php
/**
 * Capture — the shutdown handler ignores warnings/notices and logs fatals;
 * http_api_debug ignores non-GitHub hosts, strips the query string and
 * never stores a header; wp_mail_failed keeps to + subject and no body;
 * the fishotel_log action / helper; re-entrancy; the alert email's
 * cooldown and recursion guard.
 *
 * Run from the plugin root:  php includes/sections/health/tests/capture-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Capture;
use FisHotel\Misc\Sections\Health\Health;
use FisHotel\Misc\Sections\Health\Logger;
use FisHotel\Misc\Sections\Health\Settings;
use FisHotel\Misc\Sections\Health\Tests\Fake_Store;

echo "1. Shutdown handler — fatal_row()\n";
check( 'null (no error) → null', null === Capture::fatal_row( null ) );
check( 'E_WARNING ignored', null === Capture::fatal_row( array( 'type' => E_WARNING, 'message' => 'Undefined variable', 'file' => ABSPATH . 'x.php', 'line' => 1 ) ) );
check( 'E_NOTICE ignored', null === Capture::fatal_row( array( 'type' => E_NOTICE, 'message' => 'n', 'file' => ABSPATH . 'x.php', 'line' => 1 ) ) );
check( 'E_DEPRECATED ignored', null === Capture::fatal_row( array( 'type' => E_DEPRECATED, 'message' => 'd', 'file' => ABSPATH . 'x.php', 'line' => 1 ) ) );
$row = Capture::fatal_row(
	array(
		'type'    => E_ERROR,
		'message' => "Uncaught Error: Call to undefined function wc_get_order() in /var/www/html/wp-content/plugins/x/x.php:12\nStack trace:\n#0 {main}\n  thrown",
		'file'    => '/var/www/html/wp-content/plugins/x/x.php',
		'line'    => 12,
	)
);
check( 'E_ERROR → critical, source php', is_array( $row ) && 'critical' === $row['level'] && 'php' === $row['source'] );
check( 'message is "<type>: <first line> in <relative file>:<line>"', 'E_ERROR: Uncaught Error: Call to undefined function wc_get_order() in /var/www/html/wp-content/plugins/x/x.php:12 in wp-content/plugins/x/x.php:12' === $row['message'], $row['message'] );
check( 'stack trace is not in the headline', false === strpos( $row['message'], 'Stack trace' ) );
check( 'context file is relative to ABSPATH', 'wp-content/plugins/x/x.php' === $row['context']['file'] && 12 === $row['context']['line'] );
foreach ( array( E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ) as $type ) {
	$r = Capture::fatal_row( array( 'type' => $type, 'message' => 'm', 'file' => '/elsewhere/y.php', 'line' => 3 ) );
	check( Capture::error_type_label( $type ) . ' is a fatal', is_array( $r ) && 0 === strpos( $r['message'], Capture::error_type_label( $type ) . ': m in /elsewhere/y.php:3' ) );
}
check( 'a file outside ABSPATH is kept as is', '/elsewhere/y.php' === Capture::relative_path( '/elsewhere/y.php' ) );
check( 'Windows separators are normalised', 'wp-content/a.php' === Capture::relative_path( '/var/www/html\\wp-content\\a.php' ) );

echo "2. Shutdown handler — on_shutdown() through the logger\n";
fh_reset_actions();
$store  = new Fake_Store();
$logger = new Logger( $store, array() );
Capture::init( $logger, array( 'capture_fatals' => false, 'capture_http' => true, 'extra_hosts' => array( 'api.easypost.com' ) ) );
Capture::on_shutdown();
check( 'no last error → nothing logged', 0 === $store->count() );

echo "3. http_api_debug — http_row()\n";
$ok   = array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'x-ratelimit' => '1' ) );
$u401 = array( 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'headers' => array( 'www-authenticate' => 'Basic realm="GitHub"' ), 'body' => '{"message":"Bad credentials"}' );
$u500 = array( 'response' => array( 'code' => 503, 'message' => 'Service Unavailable' ) );
$args = array( 'method' => 'GET', 'headers' => array( 'Authorization' => 'token ghp_' . str_repeat( 'Z', 36 ) ) );

check( 'non-GitHub host ignored even on 401', null === Capture::http_row( $u401, 'response', 'WP_Http', $args, 'https://api.worldpressit.com/x?token=abc' ) );
check( '200 from GitHub ignored', null === Capture::http_row( $ok, 'response', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/x' ) );
check( '399 ignored', null === Capture::http_row( array( 'response' => array( 'code' => 399 ) ), 'response', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/x' ) );
check( 'a non-"response" context ignored', null === Capture::http_row( $u401, 'request', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/x' ) );

$row = Capture::http_row( $u401, 'response', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/fishotel-misc-plugin/contents/fishotel-misc-plugin.php?ref=main&token=ghp_abc' );
check( '401 → error, source updater', is_array( $row ) && 'error' === $row['level'] && 'updater' === $row['source'] );
check( 'message is "GitHub returned 401 for host/path" with the query string removed', 'GitHub returned 401 for api.github.com/repos/Dierks27/fishotel-misc-plugin/contents/fishotel-misc-plugin.php' === $row['message'], $row['message'] );
check( 'auth_failed flag set for 401', true === $row['auth_failed'] );
check( 'context has host/path/method/code only — no headers, no body', array( 'host', 'path', 'method', 'code' ) === array_keys( $row['context'] ) );
check( 'nothing in the row contains the token or a header', false === strpos( json_encode( $row ), 'ghp_' ) && false === stripos( json_encode( $row ), 'authorization' ) && false === strpos( json_encode( $row ), 'Bad credentials' ) );

$row = Capture::http_row( array( 'response' => array( 'code' => 403 ) ), 'response', 'WP_Http', array(), 'https://raw.githubusercontent.com/Dierks27/x/main/x.php?nocache=1' );
check( '403 → error + auth_failed, raw.githubusercontent.com is GitHub', 'error' === $row['level'] && true === $row['auth_failed'] && 'GitHub returned 403 for raw.githubusercontent.com/Dierks27/x/main/x.php' === $row['message'] );
$row = Capture::http_row( array( 'response' => array( 'code' => 404 ) ), 'response', 'WP_Http', array(), 'https://codeload.github.com/Dierks27/x/zip/refs/heads/main' );
check( '404 → error, not auth_failed', 'error' === $row['level'] && false === $row['auth_failed'] );
$row = Capture::http_row( $u500, 'response', 'WP_Http', array(), 'https://objects.githubusercontent.com/blob' );
check( '5xx → warning, not auth_failed', 'warning' === $row['level'] && false === $row['auth_failed'] && 'GitHub returned 503 for objects.githubusercontent.com/blob' === $row['message'] );
$row = Capture::http_row( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), 'response', 'WP_Http', array(), 'https://github.com/Dierks27/x/archive/refs/heads/main.zip?x=1' );
check( 'WP_Error → error with the error message, query stripped', 'error' === $row['level'] && 'GitHub request failed for github.com/Dierks27/x/archive/refs/heads/main.zip: cURL error 28: Operation timed out' === $row['message'] && 'http_request_failed' === $row['context']['error'] );
$row = Capture::http_row( $u500, 'response', 'WP_Http', array( 'method' => 'post' ), 'https://api.easypost.com/v2/trackers?x=1', array( 'api.easypost.com' ) );
check( 'extra host from the settings is watched, labelled by host, method upper-cased', is_array( $row ) && 'api.easypost.com returned 503 for api.easypost.com/v2/trackers' === $row['message'] && 'POST' === $row['context']['method'] );
check( 'host matching is case-insensitive', is_array( Capture::http_row( $u401, 'response', 'WP_Http', array(), 'https://API.GitHub.com/x' ) ) );
$row = Capture::http_row( $u401, 'response', 'WP_Http', array(), 'https://api.easypost.com/v2/trackers', array( 'api.easypost.com' ) );
check( 'a 401 from an extra host is an error row but NOT a GitHub auth failure', is_array( $row ) && 'error' === $row['level'] && false === $row['auth_failed'] && 'api.easypost.com returned 401 for api.easypost.com/v2/trackers' === $row['message'] );
$row = Capture::http_row( array( 'response' => array( 'code' => 403 ) ), 'response', 'WP_Http', array(), 'https://worldpressit.com/api/x', array( 'worldpressit.com' ) );
check( 'nor is a 403 from one', 'error' === $row['level'] && false === $row['auth_failed'] );

echo "4. http_api_debug — on_http_api_debug() end to end\n";
$GLOBALS['fh_transients'] = array();
do_action( 'http_api_debug', $u401, 'response', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/x?token=1' );
check( 'the 401 is logged', 1 === $store->count() && 'GitHub returned 401 for api.github.com/repos/Dierks27/x' === $store->last()['message'] );
$flag = get_transient( Health::TRANSIENT_GITHUB_AUTH );
check( 'the auth-failed transient is set with host/code/time for 24 h', is_array( $flag ) && 'api.github.com' === $flag['host'] && 401 === $flag['code'] && is_int( $flag['time'] ) && DAY_IN_SECONDS === $GLOBALS['fh_transients'][ Health::TRANSIENT_GITHUB_AUTH ][1] );
do_action( 'http_api_debug', $ok, 'response', 'WP_Http', $args, 'https://api.github.com/repos/Dierks27/x' );
do_action( 'http_api_debug', $u401, 'response', 'WP_Http', $args, 'https://example.com/x' );
check( 'a 200 and a foreign host add nothing', 1 === $store->count() );
do_action( 'http_api_debug', $u500, 'response', 'WP_Http', array(), 'https://api.easypost.com/v2/trackers' );
check( 'the settings host is watched end to end', 2 === $store->count() && 'warning' === $store->last()['level'] );
$GLOBALS['fh_transients'] = array();
do_action( 'http_api_debug', $u401, 'response', 'WP_Http', array(), 'https://api.easypost.com/v2/trackers' );
check( 'a 401 from the extra host is logged as an error row…', 3 === $store->count() && 'error' === $store->last()['level'] && 'api.easypost.com' === $store->last()['context']['host'] );
check( '…but does not set the GitHub auth-failed transient', false === get_transient( Health::TRANSIENT_GITHUB_AUTH ) );

echo "5. wp_mail_failed — mail_row()\n";
$err = new WP_Error(
	'wp_mail_failed',
	'SMTP Error: Could not authenticate.',
	array(
		'to'                      => array( 'dana@example.test', 'jeff@example.test' ),
		'subject'                 => 'Your EA packing slip',
		'message'                 => 'SECRET BODY with Bearer abc',
		'headers'                 => array( 'From: shop@example.test', 'X-Secret: s3cr3t' ),
		'attachments'             => array( '/tmp/slip.pdf' ),
		'phpmailer_exception_code' => 2,
	)
);
$row = Capture::mail_row( $err );
check( 'error, source mail, message = the error message', 'error' === $row['level'] && 'mail' === $row['source'] && 'SMTP Error: Could not authenticate.' === $row['message'] );
check( 'context has to (array) and subject', array( 'dana@example.test', 'jeff@example.test' ) === $row['context']['to'] && 'Your EA packing slip' === $row['context']['subject'] );
check( 'never the body, headers or attachments', ! isset( $row['context']['message'] ) && ! isset( $row['context']['headers'] ) && ! isset( $row['context']['attachments'] ) && false === strpos( json_encode( $row ), 'SECRET BODY' ) && false === strpos( json_encode( $row ), 's3cr3t' ) );
$row = Capture::mail_row( new WP_Error( 'wp_mail_failed', '', array( 'to' => 'a@x.test, b@x.test', 'subject' => 'S' ) ) );
check( 'a string "to" becomes an array; empty message gets a default', array( 'a@x.test', 'b@x.test' ) === $row['context']['to'] && 'wp_mail failed' === $row['message'] );
check( 'not a WP_Error → null', null === Capture::mail_row( 'nope' ) );
do_action( 'wp_mail_failed', $err );
check( 'the action is wired to the logger', 4 === $store->count() && 'mail' === $store->last()['source'] );

echo "6. fhsmtp_email_failed\n";
$row = Capture::smtp_row( 'primary' );
check( 'smtp row names the connection', 'error' === $row['level'] && 'smtp' === $row['source'] && 'SMTP send failed on the primary connection' === $row['message'] && 'primary' === $row['context']['connection'] );
check( 'unknown connection', 'SMTP send failed on the unknown connection' === Capture::smtp_row( null )['message'] );
do_action( 'fhsmtp_email_failed', 'backup' );
check( 'the action is wired to the logger', 5 === $store->count() && 'smtp' === $store->last()['source'] );

echo "7. fishotel_log action and helper\n";
do_action( 'fishotel_log', 'error', 'theme/packing-slip', 'EA email failed', array( 'order' => 123, 'api_key' => 'k' ) );
$last = $store->last();
check( 'the action logs with the given source and a sanitised context', 6 === $store->count() && 'theme/packing-slip' === $last['source'] && 'EA email failed' === $last['message'] && 123 === $last['context']['order'] && '[redacted]' === $last['context']['api_key'] );
check( 'fishotel_log() helper exists', function_exists( 'fishotel_log' ) );
fishotel_log( 'warning', 'batch', 'Last call skipped', 'not an array' );
$last = $store->last();
check( 'the helper routes through the action and wraps a non-array context', 7 === $store->count() && 'warning' === $last['level'] && 'batch' === $last['source'] && array( 'context' => 'not an array' ) === $last['context'] );
do_action( 'fishotel_log', 'debug', 'x', 'dropped' );
check( 'debug through the action is dropped by default', 7 === $store->count() );

echo "8. Re-entrancy — one nested capture is stored, the next is cut\n";
$logger->set_on_insert(
	static function () {
		// Something that logs during a log (e.g. a failing alert). Each
		// stored row re-enters here, so without the depth cap this loops.
		do_action( 'fishotel_log', 'error', 'loop', 'inner' );
	}
);
do_action( 'fishotel_log', 'error', 'outer', 'outer' );
check( 'the outer row and ONE nested row are stored; the third level is cut, no loop', 9 === $store->count() && 'loop' === $store->last()['source'] && 'outer' === $store->rows[8]['source'], $store->count() . ' rows' );
$logger->set_on_insert( null );
do_action( 'fishotel_log', 'error', 'after', 'depth is back to zero' );
check( 'the depth counter unwinds so later events log normally', 10 === $store->count() && 'after' === $store->last()['source'] );

echo "9. Alert email — cooldown and recursion guard\n";
$store  = new Fake_Store();
$logger = new Logger( $store, array() );
$logger->set_on_insert( array( Settings::class, 'maybe_alert' ) );
fh_reset_actions();
Capture::init( $logger, array( 'capture_fatals' => false, 'capture_http' => false ) );
$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array( 'alert_email' => 'jeff@example.test', 'alert_cooldown_minutes' => 60 );
$GLOBALS['fh_transients'] = array();
$GLOBALS['fh_mails']      = array();

check( 'should_alert: error from php', Settings::should_alert( array( 'level' => 'error', 'source' => 'php' ), Settings::get() ) );
check( 'should_alert: critical from updater', Settings::should_alert( array( 'level' => 'critical', 'source' => 'updater' ), Settings::get() ) );
check( 'should_alert: not for warnings', ! Settings::should_alert( array( 'level' => 'warning', 'source' => 'php' ), Settings::get() ) );
check( 'should_alert: not for other sources', ! Settings::should_alert( array( 'level' => 'error', 'source' => 'theme/packing-slip' ), Settings::get() ) );
check( 'should_alert: off without an address', ! Settings::should_alert( array( 'level' => 'error', 'source' => 'php' ), array( 'alert_email' => '' ) ) );

do_action( 'fishotel_log', 'critical', 'php', 'E_ERROR: boom in x.php:1' );
check( 'one alert mail sent to the address', 1 === count( $GLOBALS['fh_mails'] ) && 'jeff@example.test' === $GLOBALS['fh_mails'][0]['to'] );
check( 'subject and body carry level, source and message', false !== strpos( $GLOBALS['fh_mails'][0]['subject'], 'critical (php)' ) && false !== strpos( $GLOBALS['fh_mails'][0]['message'], 'E_ERROR: boom in x.php:1' ) );
check( 'plain text', in_array( 'Content-Type: text/plain; charset=UTF-8', (array) $GLOBALS['fh_mails'][0]['headers'], true ) );
check( 'cooldown transient set for 60 min', isset( $GLOBALS['fh_transients'][ Settings::ALERT_TRANSIENT ] ) && 3600 === $GLOBALS['fh_transients'][ Settings::ALERT_TRANSIENT ][1] );
do_action( 'fishotel_log', 'error', 'updater', 'GitHub returned 401 for api.github.com/x' );
check( 'a second qualifying row inside the cooldown sends nothing', 1 === count( $GLOBALS['fh_mails'] ) && 2 === $store->count() );
delete_transient( Settings::ALERT_TRANSIENT );
do_action( 'fishotel_log', 'error', 'php', 'another fatal' );
check( 'after the cooldown a new alert goes out', 2 === count( $GLOBALS['fh_mails'] ) );

// Recursion: the alert mail itself fails → wp_mail_failed → a 'mail' row
// (stored: a bouncing alert address must be visible) → maybe_alert() again
// for that row, which must bail on the static flag — no second send, no loop.
delete_transient( Settings::ALERT_TRANSIENT );
$GLOBALS['fh_mails']     = array();
$GLOBALS['fh_mail_fail'] = true;
$before                  = $store->count();
do_action( 'fishotel_log', 'error', 'php', 'fatal while mail is broken' );
check( 'exactly one send attempt even though it failed', 1 === count( $GLOBALS['fh_mails'] ) );
check( 'the failed alert is logged as a mail row addressed to the alert address: two new rows, no loop', $before + 2 === $store->count() && 'mail' === $store->last()['source'] && array( 'jeff@example.test' ) === $store->last()['context']['to'] && 'php' === $store->rows[ $store->last()['id'] - 1 ]['source'], $store->count() . ' rows' );
check( 'the cooldown window was still claimed, so the mail row itself sent nothing more', 1 === count( $GLOBALS['fh_mails'] ) && false !== get_transient( Settings::ALERT_TRANSIENT ) );
$GLOBALS['fh_mail_fail'] = false;

// And when the alert machinery is idle, a failing wp_mail elsewhere IS
// logged (fresh store: the stub's error message is the same one, so with
// the old store it would fold into the alert's mail row by the 10-minute
// fingerprint dedupe).
$store  = new Fake_Store();
$logger = new Logger( $store, array() );
fh_reset_actions();
Capture::init( $logger, array( 'capture_fatals' => false, 'capture_http' => false ) );
$GLOBALS['fh_mail_fail'] = true;
wp_mail( 'customer@example.test', 'Your order', 'BODY' );
check( 'a normal wp_mail failure is logged as a mail row with to + subject, no body', 1 === $store->count() && 'mail' === $store->last()['source'] && 'Your order' === $store->last()['context']['subject'] && false === strpos( json_encode( $store->last() ), 'BODY' ) );
$GLOBALS['fh_mail_fail'] = false;

fh_finish();
