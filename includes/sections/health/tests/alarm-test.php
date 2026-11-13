<?php
/**
 * The checkout alarm: each signal (new fatals from WooCommerce's log
 * files, its database log and WordPress's recovery mode; orders stuck at
 * payment; the checkout page), the hour of silence per signal, the
 * e-mail's wording, the Today line, the settings, and that the
 * customer's request only ever schedules.
 *
 * Run from the plugin root:
 *   php includes/sections/health/tests/alarm-test.php
 *
 * @package FisHotel\Misc\Sections\Health
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Alarm;
use FisHotel\Misc\Sections\Health\Health;
use FisHotel\Misc\Sections\Health\Settings;

// The few WordPress functions this test needs that the shared stubs leave out.
$GLOBALS['fh_filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fh_filters'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $callback ) {
			$value = call_user_func( $callback, $value );
		}
		return $value;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return in_array( $capability, $GLOBALS['fh_test_caps'] ?? array(), true );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['fh_options'][ $key ] );
		return true;
	}
}

// WooCommerce's order API, recorded.
$GLOBALS['fh_order_queries'] = array();
$GLOBALS['fh_orders']        = array(); // status => list of [ id, created ts ].
function wc_get_orders( $args ) {
	$GLOBALS['fh_order_queries'][] = $args;
	$after                         = (int) substr( (string) $args['date_created'], 1 );
	$ids                           = array();
	foreach ( (array) $args['status'] as $status ) {
		foreach ( $GLOBALS['fh_orders'][ $status ] ?? array() as $order ) {
			// [ id, created ts, created via (default: the checkout page) ].
			$via = $order[2] ?? 'checkout';
			if ( isset( $args['created_via'] ) && ! in_array( $via, (array) $args['created_via'], true ) ) {
				continue;
			}
			if ( $order[1] > $after ) {
				$ids[] = $order[0];
			}
		}
	}
	return array_slice( $ids, 0, (int) $args['limit'] );
}
function wc_get_is_paid_statuses() {
	return array( 'processing', 'completed', 'shipped' );
}

// A $wpdb that answers the three queries Store::wc_fatals_after() makes.
define( 'ARRAY_A', 'ARRAY_A' );
class FH_Fake_WPDB {
	public $prefix = 'wp_';
	public $rows   = array(); // woocommerce_log rows: log_id, timestamp, source, message.
	public $table  = true;
	public $sql    = array();
	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[sd]/', is_int( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_var( $sql ) {
		$this->sql[] = $sql;
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $this->table ? 'wp_woocommerce_log' : null;
		}
		$max = 0;
		foreach ( $this->rows as $r ) {
			$max = max( $max, $r['log_id'] );
		}
		return $max;
	}
	public function get_results( $sql, $output ) {
		$this->sql[] = $sql;
		preg_match( '/log_id > (\d+)/', $sql, $m );
		$out = array();
		foreach ( array_reverse( $this->rows ) as $r ) {
			if ( $r['log_id'] > (int) $m[1] && 'fatal-errors' === $r['source'] ) {
				$out[] = $r;
			}
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new FH_Fake_WPDB();

// WooCommerce's log folder (WC_LOG_DIR, as when its Settings class is absent).
$fh_dir = sys_get_temp_dir() . '/fh-alarm-' . getmypid() . '/wc-logs';
@mkdir( $fh_dir, 0777, true );
define( 'WC_LOG_DIR', $fh_dir . '/' );

require_once FISHOTEL_MISC_PATH . 'includes/sections/health/class-alarm.php';

$GLOBALS['fh_options']['admin_email'] = 'owner@example.test';
$GLOBALS['fh_test_caps']              = array( 'manage_options' );

/**
 * One WooCommerce log line in the format WooCommerce 11 writes.
 */
function fh_line( $ts, $message, $level = 'CRITICAL' ) {
	return gmdate( 'Y-m-d\TH:i:s', $ts ) . '+00:00 ' . $level . ' ' . $message . ' CONTEXT: {"error":{"type":1,"file":"' . ABSPATH . 'wp-content/plugins/demo/demo.php","line":12},"remote-logging":true,"backtrace":["#0 {main}"]}';
}

$fh_t0 = 1767225600; // 2026-01-01 00:00 UTC.

/* ------------------------------------------------------------------ */
echo "1. Settings\n";

$fh_s = Settings::get();
check( 'on by default with Health; 3 orders in 30 minutes; the page check off; no address of its own', true === $fh_s['checkout_alarm'] && 3 === $fh_s['stall_orders'] && 30 === $fh_s['stall_minutes'] && false === $fh_s['checkout_page_check'] && '' === $fh_s['checkout_alarm_email'] );
check( 'with no address of its own it writes to the site\'s admin address', 'owner@example.test' === Alarm::recipient( $fh_s ) );
check( 'an address in Settings wins', 'shop@example.test' === Alarm::recipient( array_merge( $fh_s, array( 'checkout_alarm_email' => 'shop@example.test' ) ) ) );

$fh_dropped = array();
$fh_saved   = Settings::sanitize( array( 'retention_days' => 30, 'max_rows' => 5000, 'alert_cooldown_minutes' => 60, 'stall_orders' => '0', 'stall_minutes' => '2000', 'checkout_alarm_email' => 'not-an-address', 'checkout_page_check' => '1' ), $fh_dropped );
check( 'a save without the "on" box switches the alarm off; the page check box is kept', false === $fh_saved['checkout_alarm'] && true === $fh_saved['checkout_page_check'] );
check( 'numbers out of range fall back to 3 and 30, a bad address to blank, and each is said', 3 === $fh_saved['stall_orders'] && 30 === $fh_saved['stall_minutes'] && '' === $fh_saved['checkout_alarm_email'] && 3 === count( $fh_dropped ), implode( '; ', $fh_dropped ) );
$fh_saved = Settings::sanitize( array( 'checkout_alarm' => '1', 'stall_orders' => '5', 'stall_minutes' => '45', 'checkout_alarm_email' => 'shop@example.test' ), $fh_dropped );
check( 'good values are kept', true === $fh_saved['checkout_alarm'] && 5 === $fh_saved['stall_orders'] && 45 === $fh_saved['stall_minutes'] && 'shop@example.test' === $fh_saved['checkout_alarm_email'] );

/* ------------------------------------------------------------------ */
echo "2. Signal 1: new fatals\n";

$fh_text = substr( fh_line( $fh_t0 - 600, 'Uncaught Error: old one' ), 20 ) . "\n" // The tail can start mid-line.
	. fh_line( $fh_t0 - 60, 'Uncaught Error: before the last look' ) . "\n"
	. fh_line( $fh_t0 + 60, 'Uncaught TypeError: first new one in /var/www/html/wp-content/plugins/demo/demo.php:12' ) . "\n"
	. fh_line( $fh_t0 + 120, 'Something informational', 'INFO' ) . "\n"
	. fh_line( $fh_t0 + 180, 'Uncaught Error: Call to undefined function demo_missing()' ) . "\n";
$fh_p = Alarm::parse_fatal_lines( $fh_text, $fh_t0 );
check( 'only the lines after the last look count; INFO and a cut-off line do not', 2 === $fh_p['count'], json_encode( $fh_p ) );
check( 'the newest message is kept, without its CONTEXT', 'Uncaught Error: Call to undefined function demo_missing()' === $fh_p['message'] );

$fh_state = Alarm::state();
$fh_file  = $fh_dir . '/fatal-errors-2026-01-01-0123abcd.log';
file_put_contents( $fh_file, fh_line( $fh_t0 - 3600, 'Uncaught Error: from yesterday' ) . "\n" );
touch( $fh_file, $fh_t0 - 3600 );
$fh_r = Alarm::look_fatal( $fh_state, $fh_t0 );
check( 'the first look only notes "now": a fatal from before it is never e-mailed', null === $fh_r['finding'] && $fh_t0 === $fh_r['marks']['since'] );

$fh_state['marks'] = $fh_r['marks'];
$fh_r              = Alarm::look_fatal( $fh_state, $fh_t0 + 300 );
check( 'nothing new, file not changed since: no finding', null === $fh_r['finding'] && $fh_t0 + 300 === $fh_r['marks']['since'] );

file_put_contents( $fh_file, fh_line( $fh_t0 + 400, 'Uncaught Error: Call to undefined method Demo::go()' ) . "\n", FILE_APPEND );
touch( $fh_file, $fh_t0 + 400 );
$fh_state['marks'] = $fh_r['marks'];
$fh_r              = Alarm::look_fatal( $fh_state, $fh_t0 + 600 );
check( 'a new line in WooCommerce\'s fatal-errors file is a finding', is_array( $fh_r['finding'] ) && false !== strpos( $fh_r['finding']['what'], 'A new PHP fatal error: Uncaught Error: Call to undefined method Demo::go()' ), json_encode( $fh_r ) );
check( '… that names the file and WooCommerce → Status → Logs', false !== strpos( $fh_r['finding']['where'], 'WooCommerce → Status → Logs, source "fatal-errors" (file fatal-errors-2026-01-01-0123abcd.log)' ) && false !== strpos( $fh_r['finding']['where'], 'Health → Log' ) );

$fh_state['marks'] = $fh_r['marks'];
file_put_contents( $fh_dir . '/wc_logger-2026-01-01-ffff.log', fh_line( $fh_t0 + 700, 'Uncaught Error: another source' ) . "\n" );
$fh_r = Alarm::look_fatal( $fh_state, $fh_t0 + 900 );
check( 'other WooCommerce log sources are not read', null === $fh_r['finding'] );

$fh_state['marks']                                      = $fh_r['marks'];
$GLOBALS['fh_options']['recovery_mode_email_last_sent'] = $fh_t0 + 1000;
$fh_r = Alarm::look_fatal( $fh_state, $fh_t0 + 1200 );
check( 'WordPress\'s recovery mode e-mailing after a fatal is a finding', is_array( $fh_r['finding'] ) && false !== strpos( $fh_r['finding']['what'], 'WordPress paused a plugin or the theme' ) );
$fh_state['marks'] = $fh_r['marks'];
$fh_r              = Alarm::look_fatal( $fh_state, $fh_t0 + 1500 );
check( '… once: the next look does not see it again', null === $fh_r['finding'] );

// WooCommerce set to its database log handler.
$GLOBALS['fh_options']['woocommerce_logs_default_handler'] = 'WC_Log_Handler_DB';
$GLOBALS['wpdb']->rows = array(
	array( 'log_id' => 7, 'timestamp' => '2026-01-01 00:01:00', 'source' => 'fatal-errors', 'message' => 'Uncaught Error: old in the database' ),
);
$fh_state['marks'] = $fh_r['marks'];
$fh_r              = Alarm::look_fatal( $fh_state, $fh_t0 + 1800 );
check( 'database handler: the first look notes the highest log_id and finds nothing', null === $fh_r['finding'] && 7 === $fh_r['marks']['db_id'] );
$GLOBALS['wpdb']->rows[] = array( 'log_id' => 8, 'timestamp' => '2026-01-01 00:35:00', 'source' => 'plugin-demo', 'message' => 'not a fatal' );
$GLOBALS['wpdb']->rows[] = array( 'log_id' => 9, 'timestamp' => '2026-01-01 00:36:00', 'source' => 'fatal-errors', 'message' => 'Uncaught Error: new in the database' );
$fh_state['marks'] = $fh_r['marks'];
$GLOBALS['wpdb']->sql = array();
$fh_r              = Alarm::look_fatal( $fh_state, $fh_t0 + 2100 );
check( 'database handler: a new "fatal-errors" row after the mark is a finding', is_array( $fh_r['finding'] ) && false !== strpos( $fh_r['finding']['what'], 'new in the database' ) && 9 === $fh_r['marks']['db_id'] );
check( '… read by log_id after the mark, newest first, at most 20 rows', 1 === preg_match( "/WHERE log_id > 7 AND source = 'fatal-errors' ORDER BY log_id DESC LIMIT 20/", implode( "\n", $GLOBALS['wpdb']->sql ) ) );
$GLOBALS['wpdb']->table = false;
$fh_state['marks']      = $fh_r['marks'];
$fh_r                   = Alarm::look_fatal( $fh_state, $fh_t0 + 2400 );
check( 'database handler with no log table: nothing, no error', null === $fh_r['finding'] );
unset( $GLOBALS['fh_options']['woocommerce_logs_default_handler'] );

check( 'the site\'s own path is cut from a message', 'Uncaught Error in wp-content/plugins/demo/demo.php:12' === Alarm::short( 'Uncaught Error in ' . ABSPATH . 'wp-content/plugins/demo/demo.php:12' ) );
check( 'two new fatals say how many', 0 === strpos( Alarm::fatal_finding( 2, 'x', '' )['what'], '2 new PHP fatal errors. The newest: x' ) );

/* ------------------------------------------------------------------ */
echo "3. Signal 2: orders stuck at payment\n";

check( 'below the threshold: nothing', null === Alarm::stall_finding( 2, 0, 3, 30 ) );
check( 'at the threshold but one went through: nothing', null === Alarm::stall_finding( 3, 1, 3, 30 ) );
$fh_f = Alarm::stall_finding( 3, 0, 3, 30 );
check( 'at the threshold and none paid: a finding in plain words', is_array( $fh_f ) && '3 or more orders from the last 30 minutes are still "Pending payment" or "Failed", and none in that time was paid.' === $fh_f['what'] );

$fh_now                = $fh_t0 + 7200;
$GLOBALS['fh_orders']  = array(
	'pending' => array( array( 1, $fh_now - 600 ), array( 2, $fh_now - 300 ), array( 3, $fh_now - 3000 ) ),
	'failed'  => array(),
);
$GLOBALS['fh_order_queries'] = array();
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'two stuck in the window (one is older): one query only, the second is skipped', 2 === $fh_l['stuck'] && 1 === count( $GLOBALS['fh_order_queries'] ) );
$fh_q = $GLOBALS['fh_order_queries'][0];
check( '… through the order API: Pending payment and Failed, created after now − 30 minutes, ids only, at most 3', array( 'pending', 'failed' ) === $fh_q['status'] && '>' . ( $fh_now - 1800 ) === $fh_q['date_created'] && 'ids' === $fh_q['return'] && 3 === $fh_q['limit'] && 'shop_order' === $fh_q['type'] );
$GLOBALS['fh_orders']['failed'] = array( array( 4, $fh_now - 60 ) );
$GLOBALS['fh_order_queries']    = array();
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'three stuck: the second query asks for one order that went through, in the same window', 3 === $fh_l['stuck'] && 0 === $fh_l['through'] && 2 === count( $GLOBALS['fh_order_queries'] ) && 1 === $GLOBALS['fh_order_queries'][1]['limit'] );
check( '… "went through" is every paid status (a plugin\'s too) and On hold', array( 'processing', 'completed', 'shipped', 'on-hold' ) === $GLOBALS['fh_order_queries'][1]['status'] );
$GLOBALS['fh_orders']['on-hold'] = array( array( 5, $fh_now - 120 ) );
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'an On hold order in the window means checkout works', 1 === $fh_l['through'] && null === Alarm::stall_finding( $fh_l['stuck'], $fh_l['through'], 3, 30 ) );
$GLOBALS['fh_orders'] = array();

// Orders the site makes itself start as Pending payment too: they are not checkouts.
check( '… both queries count only orders placed through the checkout (classic page or block checkout)', array( 'checkout', 'store-api' ) === $GLOBALS['fh_order_queries'][0]['created_via'] && array( 'checkout', 'store-api' ) === $GLOBALS['fh_order_queries'][1]['created_via'] );
$GLOBALS['fh_orders'] = array(
	// Batch Manager's invoices: wc_create_order() with no source, one per customer.
	'pending' => array( array( 11, $fh_now - 60, '' ), array( 12, $fh_now - 60, '' ), array( 13, $fh_now - 60, '' ), array( 14, $fh_now - 60, '' ) ),
);
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'four batch invoices made at once: not stuck checkouts, no alarm', 0 === $fh_l['stuck'] && null === Alarm::stall_finding( $fh_l['stuck'], $fh_l['through'], 3, 30 ) );
$GLOBALS['fh_orders'] = array(
	'pending' => array( array( 21, $fh_now - 60, 'admin' ), array( 22, $fh_now - 90, 'admin' ), array( 23, $fh_now - 120, 'admin' ) ),
);
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'three orders made by hand in wp-admin: not stuck checkouts, no alarm', 0 === $fh_l['stuck'] && null === Alarm::stall_finding( $fh_l['stuck'], $fh_l['through'], 3, 30 ) );
$GLOBALS['fh_orders'] = array(
	'pending' => array( array( 31, $fh_now - 60, '' ), array( 32, $fh_now - 60, 'admin' ), array( 33, $fh_now - 60, 'checkout' ), array( 34, $fh_now - 90, 'store-api' ) ),
	'failed'  => array( array( 35, $fh_now - 30, 'checkout' ) ),
);
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( 'mixed in with invoices, three real checkouts stuck (classic, block, failed) still raise the alarm', 3 === $fh_l['stuck'] && 0 === $fh_l['through'] && is_array( Alarm::stall_finding( $fh_l['stuck'], $fh_l['through'], 3, 30 ) ) );
$GLOBALS['fh_orders']['processing'] = array( array( 36, $fh_now - 30, 'admin' ) );
$fh_l = Alarm::look_stall( $fh_s, $fh_now );
check( '… and a paid order made in wp-admin does not prove the checkout works', 0 === $fh_l['through'] );
$GLOBALS['fh_orders'] = array();

/* ------------------------------------------------------------------ */
echo "4. Signal 3: the checkout page\n";

check( 'a 500 is a finding', is_array( Alarm::page_finding( 500 ) ) && 'The checkout page answered with error 500.' === Alarm::page_finding( 500 )['what'] );
check( 'a 200, a redirect or a 404 is not', null === Alarm::page_finding( 200 ) && null === Alarm::page_finding( 302 ) && null === Alarm::page_finding( 404 ) );
check( 'a loopback that cannot connect is not a finding (the host may block it)', null === Alarm::page_finding( 0, 'cURL error 7' ) );

/* ------------------------------------------------------------------ */
echo "5. The hour of silence\n";

$fh_fatal = array( 'what' => 'A new PHP fatal error: x', 'where' => 'Where to look: y' );
$fh_stall = Alarm::stall_finding( 3, 0, 3, 30 );
$fh_st    = Alarm::state();

$fh_d = Alarm::decide( $fh_st, array( 'fatal' => $fh_fatal, 'stall' => null ), $fh_now );
check( 'a first finding is e-mailed and shown', array( 'fatal' ) === array_keys( $fh_d['mail'] ) && isset( $fh_d['state']['active']['fatal'] ) );
$fh_st           = $fh_d['state'];
$fh_st['mailed'] = array( 'fatal' => $fh_now );

$fh_d = Alarm::decide( $fh_st, array( 'fatal' => $fh_fatal ), $fh_now + 300 );
check( 'the same signal 5 minutes later: shown, not e-mailed', array() === $fh_d['mail'] && isset( $fh_d['state']['active']['fatal'] ) );
$fh_d = Alarm::decide( $fh_st, array( 'fatal' => $fh_fatal, 'stall' => $fh_stall ), $fh_now + 600 );
check( 'another signal in that hour has its own hour: e-mailed', array( 'stall' ) === array_keys( $fh_d['mail'] ) );
$fh_d = Alarm::decide( $fh_st, array( 'fatal' => $fh_fatal ), $fh_now + 3600 );
check( 'an hour after its e-mail the same signal e-mails again', array( 'fatal' ) === array_keys( $fh_d['mail'] ) );

$fh_d = Alarm::decide( $fh_st, array( 'fatal' => null ), $fh_now + 1800 );
check( 'a fatal stays on the screens for its hour after it was seen', isset( $fh_d['state']['active']['fatal'] ) && array() === $fh_d['mail'] );
$fh_d = Alarm::decide( $fh_st, array( 'fatal' => null ), $fh_now + 3600 );
check( '… then goes, quietly ("all clear" is not e-mailed)', ! isset( $fh_d['state']['active']['fatal'] ) && array() === $fh_d['mail'] );

$fh_st2                    = $fh_st;
$fh_st2['active']['stall'] = array_merge( $fh_stall, array( 'seen_at' => $fh_now ) );
$fh_d                      = Alarm::decide( $fh_st2, array( 'stall' => null ), $fh_now + 300 );
check( 'stuck orders that clear go from the screens at once, quietly', ! isset( $fh_d['state']['active']['stall'] ) && array() === $fh_d['mail'] );

/* ------------------------------------------------------------------ */
echo "6. A look from start to e-mail\n";

$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array();
$GLOBALS['fh_mails']                         = array();
unset( $GLOBALS['fh_options'][ Alarm::STATE_OPTION ] );
$fh_calls = array();
Alarm::use_sources(
	array(
		'now'   => $fh_now,
		'fatal' => static function ( $state, $now ) use ( &$fh_calls, $fh_fatal ) {
			$fh_calls[] = 'fatal';
			return array(
				'finding' => $fh_fatal,
				'marks'   => array( 'since' => $now ),
			);
		},
		'stall' => static function () use ( &$fh_calls ) {
			$fh_calls[] = 'stall';
			return array( 'stuck' => 4, 'through' => 0 );
		},
		'page'  => static function () use ( &$fh_calls ) {
			$fh_calls[] = 'page';
			return array( 'code' => 0, 'error' => 'cURL error 7: Failed to connect' );
		},
	)
);
$fh_state = Alarm::run();
check( 'both signals found: ONE e-mail to the admin address', 1 === count( $GLOBALS['fh_mails'] ) && 'owner@example.test' === $GLOBALS['fh_mails'][0]['to'] );
check( 'the page is not opened while its check is off', array( 'fatal', 'stall' ) === $fh_calls );
$fh_mail = $GLOBALS['fh_mails'][0];
check( 'subject says what it means', '[FisHotel] Checkout alarm: customers may not be able to check out' === $fh_mail['subject'], $fh_mail['subject'] );
check( 'body: what was seen, each with where to look', false !== strpos( $fh_mail['message'], '- A new PHP fatal error: x' ) && false !== strpos( $fh_mail['message'], '- 4 or more orders from the last 30 minutes' ) && 2 === substr_count( $fh_mail['message'], 'Where to look' ) );
check( 'body: how to go back on the last upload, for a plugin and for the theme', false !== strpos( $fh_mail['message'], 'Plugins → Add New Plugin → Upload Plugin → choose the zip of the version you had before → "Replace current with uploaded".' ) && false !== strpos( $fh_mail['message'], 'Appearance → Themes → Add New Theme → Upload Theme' ) );
check( 'body: the Health page, and that it e-mails at most once an hour per problem', false !== strpos( $fh_mail['message'], 'admin.php?page=' . Health::MENU_SLUG ) && false !== strpos( $fh_mail['message'], 'at most one e-mail per problem per hour' ) );
check( 'plain text, no owner\'s name, no "he" or "she"', in_array( 'Content-Type: text/plain; charset=UTF-8', $fh_mail['headers'], true ) && 0 === preg_match( '/\b(Jeff|he|she|his|her)\b/i', $fh_mail['subject'] . $fh_mail['message'] ) );
check( 'the state remembers the look, the marks, what is on and when each e-mailed', $fh_now === $fh_state['last_look'] && $fh_now === $fh_state['mailed']['fatal'] && $fh_now === $fh_state['mailed']['stall'] && isset( $fh_state['active']['fatal'], $fh_state['active']['stall'] ) && $fh_state === $GLOBALS['fh_options'][ Alarm::STATE_OPTION ] );

Alarm::use_sources( array_merge( array( 'now' => $fh_now + 300 ), array_diff_key( (array) ( new ReflectionProperty( Alarm::class, 'sources' ) )->getValue(), array( 'now' => 1 ) ) ) );
Alarm::run();
check( '5 minutes later, still wrong: no second e-mail', 1 === count( $GLOBALS['fh_mails'] ) );

$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array( 'checkout_page_check' => true, 'checkout_alarm' => true );
$fh_calls = array();
$fh_state = Alarm::run();
check( 'page check on: the page is opened; a host that blocks it gets a note, not an e-mail', in_array( 'page', $fh_calls, true ) && isset( $fh_state['notes']['page'] ) && false !== strpos( $fh_state['notes']['page'], 'Some hosts block a site from calling itself' ) && ! isset( $fh_state['active']['page'] ) && 1 === count( $GLOBALS['fh_mails'] ) );

$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array( 'checkout_alarm' => false );
$fh_calls = array();
check( 'switched off in Settings: no look, no e-mail', array() === Alarm::run() && array() === $fh_calls && 1 === count( $GLOBALS['fh_mails'] ) );
$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array();

Alarm::use_sources(
	array(
		'now'   => $fh_now,
		'fatal' => static function () {
			throw new \RuntimeException( 'disk gone' );
		},
	)
);
check( 'a look that throws: run() returns quietly', array() === Alarm::run() );
Alarm::use_sources();

$GLOBALS['fh_mails']                                     = array();
$GLOBALS['fh_options'][ Health::OPTION_KEY ]['checkout_alarm_email'] = 'shop@example.test';
check( 'the test alarm goes to the Settings address', Alarm::send( array(), Settings::get(), true ) && 'shop@example.test' === $GLOBALS['fh_mails'][0]['to'] );
check( '… and says it is a test and that nothing is wrong', '[FisHotel] Test: checkout alarm' === $GLOBALS['fh_mails'][0]['subject'] && false !== strpos( $GLOBALS['fh_mails'][0]['message'], 'This is a test of the checkout alarm on FisHotel. Nothing is wrong.' ) && false !== strpos( $GLOBALS['fh_mails'][0]['message'], 'Replace current with uploaded' ) );
$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array();
$fh_admin_email                              = $GLOBALS['fh_options']['admin_email'];
unset( $GLOBALS['fh_options']['admin_email'] );
check( 'no address anywhere: nothing is sent', false === Alarm::send( array( 'fatal' => $fh_fatal ), Settings::get(), false ) && 1 === count( $GLOBALS['fh_mails'] ) );
$GLOBALS['fh_options']['admin_email'] = $fh_admin_email;

/* ------------------------------------------------------------------ */
echo "7. When it looks — the customer's request only schedules\n";

fh_reset_actions();
$GLOBALS['fh_cron']    = array();
$GLOBALS['fh_filters'] = array();
Alarm::init();
check( 'its own 5-minute event is armed', 1 === count( array_filter( $GLOBALS['fh_cron'], static function ( $e ) { return Alarm::HOOK === $e[1] && Alarm::SCHEDULE === $e[2]; } ) ) );
check( '… on a 5-minute schedule it adds', 300 === apply_filters( 'cron_schedules', array() )[ Alarm::SCHEDULE ]['interval'] );
check( 'it looks on WooCommerce\'s real hooks: an order marked Failed, a checkout that throws, a fatal at shutdown', isset( $GLOBALS['fh_actions']['woocommerce_order_status_failed'], $GLOBALS['fh_actions']['woocommerce_checkout_order_exception'], $GLOBALS['fh_actions']['woocommerce_shutdown_error'] ) );
Alarm::init();
check( 'a second request does not arm it twice', 1 === count( array_filter( $GLOBALS['fh_cron'], static function ( $e ) { return Alarm::HOOK === $e[1]; } ) ) );

$GLOBALS['fh_mails']  = array();
$fh_opts_before       = $GLOBALS['fh_options'];
do_action( 'woocommerce_order_status_failed', 123, null );
do_action( 'woocommerce_checkout_order_exception', null );
check( 'a failed checkout schedules ONE look a minute later, and does nothing else', 1 === count( array_filter( $GLOBALS['fh_cron'], static function ( $e ) { return Alarm::SOON_HOOK === $e[1] && 'single' === $e[2]; } ) ) && array() === $GLOBALS['fh_mails'] && $fh_opts_before === $GLOBALS['fh_options'] );
check( 'both events run the same look', isset( $GLOBALS['fh_actions'][ Alarm::HOOK ], $GLOBALS['fh_actions'][ Alarm::SOON_HOOK ] ) );

$fh_src = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/health/class-alarm.php' );
check( 'soon() is wrapped in try / catch', 1 === preg_match( '/public static function soon\(\) \{\s*try \{.*?\} catch \( \\\\Throwable \$e \)/s', $fh_src ) );

$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array( 'checkout_alarm' => false );
Alarm::init();
check( 'switched off in Settings: the next request drops its events', ! wp_next_scheduled( Alarm::HOOK ) );
$GLOBALS['fh_options'][ Health::OPTION_KEY ] = array();

Alarm::init();
$GLOBALS['fh_options'][ Alarm::STATE_OPTION ] = array( 'marks' => array( 'since' => 1 ) );
Health::boot_disabled();
check( 'the Health section switched off: the event and the marks go', ! wp_next_scheduled( Alarm::HOOK ) && ! isset( $GLOBALS['fh_options'][ Alarm::STATE_OPTION ] ) );
Health::boot_disabled();
check( '… and with nothing scheduled, switched off costs one look at the cron list', ! wp_next_scheduled( Alarm::HOOK ) );

/* ------------------------------------------------------------------ */
echo "8. Today and the Health screen\n";

check( 'nothing active: no Today line', null === Alarm::today_line( array(), 'u' ) );
$fh_line = Alarm::today_line( array( 'stall' => $fh_stall, 'fatal' => $fh_fatal ), 'https://example.test/h' );
check( 'active: one red line under Site, its signals beside it in a fixed order', 'Checkout alarm: customers may not be able to check out' === $fh_line['text'] && 'site' === $fh_line['group'] && 'bad' === $fh_line['tone'] && 'a new PHP fatal error · orders stuck at payment' === $fh_line['detail'] && 2 === $fh_line['count'] );

$GLOBALS['fh_filters'] = array();
Alarm::register_today();
$GLOBALS['fh_options'][ Alarm::STATE_OPTION ] = array( 'active' => array( 'stall' => $fh_stall ) );
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other' ), array( 'key' => Alarm::TODAY_KEY, 'text' => 'stale' ) ) );
check( 'on Today: the line joins the others, replaces an old one, and leads to Health', 2 === count( $fh_lines ) && Alarm::TODAY_KEY === $fh_lines[1]['key'] && 'stale' !== $fh_lines[1]['text'] && false !== strpos( $fh_lines[1]['url'], 'page=' . Health::MENU_SLUG ) );
$GLOBALS['fh_test_caps'] = array( 'manage_woocommerce' );
check( 'a user who may not open Health: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_test_caps']                      = array( 'manage_options' );
$GLOBALS['fh_options'][ Alarm::STATE_OPTION ] = array();
check( 'all clear: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );

$fh_w = Alarm::watching( Settings::get(), false, ABSPATH . 'wp-content/uploads/wc-logs/' );
check( 'the Health card says what it watches, in plain words, and where the files are', false !== strpos( $fh_w['fatal'], 'log files in wp-content/uploads/wc-logs/' ) && false !== strpos( $fh_w['stall'], '3 or more' ) && false !== strpos( $fh_w['page'], 'off' ) );
check( '… and says so when WooCommerce logs to the database', false !== strpos( Alarm::watching( Settings::get(), true, '' )['fatal'], 'in the database' ) );

$fh_boot = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/health/class-health.php' );
check( 'boot() starts the alarm on every request, its Today line in wp-admin only', false !== strpos( $fh_boot, "Cron::init();\n\n\t\t// The checkout alarm" ) && 1 === preg_match( '/if \( is_admin\(\) \) \{.*Alarm::register_today\(\);/s', $fh_boot ) );

@unlink( $fh_file );
@unlink( $fh_dir . '/wc_logger-2026-01-01-ffff.log' );
@rmdir( $fh_dir );
@rmdir( dirname( $fh_dir ) );

fh_finish();
