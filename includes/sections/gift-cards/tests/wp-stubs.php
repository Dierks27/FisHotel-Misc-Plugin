<?php
/**
 * Just enough WordPress to run the Gift Cards section headless: the
 * translation and escaping helpers, options and transients (in memory),
 * nonces that match wp_create_nonce(), users and capabilities seeded by a
 * test, the action / filter registries, a wp_mail() that keeps what it was
 * given, a WP_Error, wp_die() and the JSON replies as exceptions, dbDelta()
 * on the SQLite $wpdb of fake-wpdb.php, and the check() harness.
 *
 * fh_gc_fresh() gives a Service on a NEW database with the tables
 * installed, a clock a test can move (fh_gc_days()) and the site in
 * America/Chicago. Every name, code, amount and address in these tests is
 * invented.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

define( 'ABSPATH', '/var/www/html/' );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), (string) $url );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}

function selected( $a, $b = true, $echo = true ) {
	$out = (string) $a === (string) $b ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function checked( $a, $b = true, $echo = true ) {
	$out = (string) $a === (string) $b ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function disabled( $a, $b = true, $echo = true ) {
	$out = (string) $a === (string) $b ? ' disabled="disabled"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' !== (string) $path ? '/' . ltrim( (string) $path, '/' ) : '' );
}

function add_query_arg( $args, $value = null, $url = null ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $value );
	} else {
		$url = $value;
	}
	$url = (string) $url;
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function get_bloginfo( $what = '' ) {
	return 'language' === $what ? 'en-US' : 'Test Fish Shop';
}

function wp_specialchars_decode( $text, $flags = ENT_NOQUOTES ) {
	return htmlspecialchars_decode( (string) $text, $flags );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . substr( md5( (string) $action . '|' . get_current_user_id() ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return is_string( $nonce ) && '' !== $nonce && hash_equals( wp_create_nonce( $action ), $nonce ) ? 1 : false;
}

/**
 * Options and transients live in memory so a test can seed and read back.
 */
$GLOBALS['fh_options']    = array();
$GLOBALS['fh_transients'] = array();

function get_option( $key, $default = false ) {
	$GLOBALS['fh_option_reads'][] = $key;
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['fh_options'][ $key ] = $value;
	return true;
}

function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['fh_options'] ) ) {
		return false;
	}
	$GLOBALS['fh_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	return true;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['fh_transients'][ $key ] = array( $value, (int) $expiration );
	return true;
}

function get_transient( $key ) {
	return isset( $GLOBALS['fh_transients'][ $key ] ) ? $GLOBALS['fh_transients'][ $key ][0] : false;
}

function delete_transient( $key ) {
	unset( $GLOBALS['fh_transients'][ $key ] );
	return true;
}

/**
 * "Now" in site time for the Clean Room's helpers (Y-m-d H:i:s).
 */
$GLOBALS['fh_now'] = '2026-04-01 09:00:00';

function current_time( $type, $gmt = 0 ) {
	$ts = strtotime( $GLOBALS['fh_now'] . ' UTC' );
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
	if ( 'timestamp' === $type ) {
		return $ts;
	}
	return gmdate( $type, $ts );
}

function wp_timezone() {
	return new \DateTimeZone( 'America/Chicago' );
}

/**
 * Users: $GLOBALS['fh_users'][ id ] = array( login, email, first, last, display, meta => array() ),
 * capabilities: $GLOBALS['fh_user_caps'][ cap ] = array( user ids ), the current user: $GLOBALS['fh_current_user'].
 */
$GLOBALS['fh_users']        = array();
$GLOBALS['fh_user_caps']    = array();
$GLOBALS['fh_current_user'] = 0;

function fh_user_object( $id ) {
	$row = $GLOBALS['fh_users'][ (int) $id ] ?? null;
	if ( ! $row ) {
		return false;
	}
	return (object) array(
		'ID'           => (int) $id,
		'user_login'   => (string) ( $row['login'] ?? 'user' . $id ),
		'user_email'   => (string) ( $row['email'] ?? '' ),
		'first_name'   => (string) ( $row['first'] ?? '' ),
		'last_name'    => (string) ( $row['last'] ?? '' ),
		'display_name' => (string) ( $row['display'] ?? ( $row['login'] ?? 'user' . $id ) ),
	);
}

function get_userdata( $id ) {
	return fh_user_object( $id );
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['fh_users'] as $id => $row ) {
		if ( 'email' === $field && strtolower( (string) ( $row['email'] ?? '' ) ) === strtolower( (string) $value ) ) {
			return fh_user_object( $id );
		}
		if ( 'id' === $field && (int) $id === (int) $value ) {
			return fh_user_object( $id );
		}
	}
	return false;
}

function get_user_meta( $id, $key = '', $single = false ) {
	$meta = $GLOBALS['fh_users'][ (int) $id ]['meta'] ?? array();
	return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
}

function user_can( $user, $capability ) {
	$id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) $user;
	return in_array( $id, $GLOBALS['fh_user_caps'][ $capability ] ?? array(), true );
}

function get_current_user_id() {
	return (int) ( $GLOBALS['fh_current_user'] ?? 0 );
}

function is_user_logged_in() {
	return get_current_user_id() > 0;
}

function current_user_can( $capability, ...$args ) {
	return user_can( get_current_user_id(), $capability );
}

function wp_get_current_user() {
	$user = fh_user_object( get_current_user_id() );
	return $user ? $user : (object) array(
		'ID'           => 0,
		'user_login'   => '',
		'user_email'   => '',
		'first_name'   => '',
		'last_name'    => '',
		'display_name' => '',
	);
}

/**
 * Actions and filters: a flat registry (remove_* drops the same callback).
 */
$GLOBALS['fh_actions'] = array();
$GLOBALS['fh_filters'] = array();
$GLOBALS['fh_fired']   = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = array( $callback, (int) $accepted_args, (int) $priority );
	return true;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['fh_actions'][ $hook ] ?? array() as $index => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['fh_actions'][ $hook ][ $index ] );
		}
	}
	return true;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_fired'][] = array( $hook, $args );
	foreach ( $GLOBALS['fh_actions'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_filters'][ $hook ][] = array( $callback, (int) $accepted_args, (int) $priority );
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['fh_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[1] - 1 ) ) ) );
	}
	return $value;
}

function add_shortcode( $tag, $callback ) {
	$GLOBALS['fh_shortcodes'][ $tag ] = $callback;
}

/**
 * How often a hook fired since the last fh_reset_fired().
 */
function fh_fired( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['fh_fired'] as $entry ) {
		if ( $entry[0] === $hook ) {
			$n++;
		}
	}
	return $n;
}

/**
 * wp_mail(): nothing is sent — every call is kept in $GLOBALS['fh_mails']
 * (to, subject, message, headers, alt = the plain-text part the
 * phpmailer_init hook set). $GLOBALS['fh_mail_fails'] = true makes it
 * answer false, as a site that cannot send does.
 */
$GLOBALS['fh_mails']      = array();
$GLOBALS['fh_mail_fails'] = false;

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$phpmailer          = new \stdClass();
	$phpmailer->AltBody = '';
	do_action( 'phpmailer_init', $phpmailer );

	$GLOBALS['fh_mails'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
		'alt'     => $phpmailer->AltBody,
	);

	return ! $GLOBALS['fh_mail_fails'];
}

/**
 * The e-mails kept since the last fh_take_mails().
 */
function fh_take_mails() {
	$mails               = $GLOBALS['fh_mails'];
	$GLOBALS['fh_mails'] = array();
	return $mails;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}

		public function add( $code, $message, $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof \WP_Error;
}

/**
 * The AJAX replies: wp_send_json_*() ends the request in WordPress; here
 * it throws, so a test can call a real handler and read the answer.
 */
class Fh_Test_Json extends \RuntimeException {

	public $success = false;
	public $status  = 200;
	public $data    = null;

	public function __construct( $success, $data, $status ) {
		parent::__construct( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '' );
		$this->success = (bool) $success;
		$this->data    = $data;
		$this->status  = (int) $status;
	}
}

function wp_send_json_error( $data = null, $status_code = null ) {
	throw new Fh_Test_Json( false, $data, null === $status_code ? 200 : (int) $status_code );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	throw new Fh_Test_Json( true, $data, null === $status_code ? 200 : (int) $status_code );
}


function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Fh_Test_Die( is_string( $message ) ? $message : 'wp_die', is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500 );
}

class Fh_Test_Die extends \RuntimeException {

	public $status;

	public function __construct( $message, $status ) {
		parent::__construct( (string) $message );
		$this->status = (int) $status;
	}
}

class Fh_Test_Redirect extends \RuntimeException {

	public $url;

	public function __construct( $url ) {
		parent::__construct( 'redirect' );
		$this->url = (string) $url;
	}
}

function wp_safe_redirect( $url, $status = 302 ) {
	throw new Fh_Test_Redirect( $url );
}

function wp_redirect( $url, $status = 302 ) {
	throw new Fh_Test_Redirect( $url );
}

function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
	$nonce = isset( $_REQUEST[ $name ] ) ? (string) $_REQUEST[ $name ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		wp_die( 'The link you followed has expired.', '', array( 'response' => 403 ) );
	}
	return 1;
}

function check_ajax_referer( $action = -1, $name = false, $die = true ) {
	$key   = $name ? $name : '_ajax_nonce';
	$nonce = isset( $_REQUEST[ $key ] ) ? (string) $_REQUEST[ $key ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		if ( $die ) {
			wp_die( '-1', '', array( 'response' => 403 ) );
		}
		return false;
	}
	return 1;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$out = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function is_admin() {
	return ! empty( $GLOBALS['fh_is_admin'] );
}

function wp_doing_ajax() {
	return ! empty( $GLOBALS['fh_doing_ajax'] );
}

function wp_doing_cron() {
	return false;
}

function wp_kses_post( $text ) {
	return (string) $text;
}

function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, (int) $d );
}

function date_i18n( $format, $ts = false, $gmt = false ) {
	return gmdate( $format, false === $ts ? time() : (int) $ts );
}

function wp_date( $format, $ts = null, $tz = null ) {
	$tz = $tz ? $tz : wp_timezone();
	return ( new \DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) ) )->setTimezone( $tz )->format( $format );
}

function get_option_stub_reset() {
	$GLOBALS['fh_options'] = array();
}

/**
 * Cron: a schedule kept in memory.
 */
$GLOBALS['fh_cron'] = array();

function wp_next_scheduled( $hook, $args = array() ) {
	return isset( $GLOBALS['fh_cron'][ $hook ] ) ? $GLOBALS['fh_cron'][ $hook ] : false;
}

function wp_schedule_event( $ts, $recurrence, $hook, $args = array() ) {
	$GLOBALS['fh_cron'][ $hook ] = (int) $ts;
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	unset( $GLOBALS['fh_cron'][ $hook ] );
	return 1;
}

function dbDelta( $sql ) {
	$GLOBALS['wpdb']->create_from_mysql( is_array( $sql ) ? implode( '', $sql ) : $sql );
	return array();
}

function is_multisite() {
	return false;
}

function trailingslashit( $s ) {
	return rtrim( (string) $s, '/' ) . '/';
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function nocache_headers() {
}

function status_header( $code ) {
}

require_once __DIR__ . '/fake-wpdb.php';
$GLOBALS['wpdb'] = new Fh_Wpdb();

foreach ( array( 'money', 'codes', 'settings', 'modes', 'audit', 'store', 'mailer', 'service', 'legacy-map', 'importer' ) as $fh_file ) {
	require_once FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-' . $fh_file . '.php';
}

/**
 * Shared check() helper for every test file.
 */
$GLOBALS['fh_failures'] = 0;
$GLOBALS['fh_checks']   = 0;

function check( $label, $condition, $detail = '' ) {
	$GLOBALS['fh_checks']++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$GLOBALS['fh_failures']++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

function check_same( $label, $expected, $actual ) {
	check( $label, $expected === $actual, 'expected ' . fh_test_export( $expected ) . ', got ' . fh_test_export( $actual ) );
}

function check_contains( $label, $needle, $haystack ) {
	$list = is_array( $haystack ) ? $haystack : array( (string) $haystack );
	foreach ( $list as $item ) {
		if ( false !== stripos( (string) $item, (string) $needle ) ) {
			check( $label, true );
			return;
		}
	}
	check( $label, false, '"' . $needle . '" not found in ' . fh_test_export( $list ) );
}

function check_lacks( $label, $needle, $haystack ) {
	check( $label, false === stripos( (string) $haystack, (string) $needle ), '"' . $needle . '" found in ' . fh_test_export( $haystack ) );
}

function fh_test_export( $value ) {
	$text = var_export( $value, true );
	$text = preg_replace( '/\s+/', ' ', $text );
	return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}


/**
 * A Service on a NEW database with the tables installed.
 *
 * $GLOBALS['fh_clock']  UNIX time the Service reads as "now"
 *
 * @param array $settings Settings over the defaults.
 * @param array $world    More world (before_move, code).
 * @return \FisHotel\Misc\Sections\Gift_Cards\Service
 */
function fh_gc_fresh( array $settings = array(), array $world = array() ) {
	$GLOBALS['wpdb']       = new Fh_Wpdb();
	$GLOBALS['fh_options'] = array();
	$GLOBALS['fh_mails']   = array();
	$GLOBALS['fh_transients'] = array();
	\FisHotel\Misc\Sections\Gift_Cards\Store::forget();
	$store = new \FisHotel\Misc\Sections\Gift_Cards\Store();
	$store->install( true );
	$GLOBALS['fh_clock'] = strtotime( '2026-03-02 15:00:00 UTC' );
	return fh_gc_service( $store, $settings, $world );
}

/**
 * A Service on a given store (a second "request" on another connection).
 */
function fh_gc_service( $store, array $settings = array(), array $world = array() ) {
	$settings = \FisHotel\Misc\Sections\Gift_Cards\Settings::merge( $settings );
	return new \FisHotel\Misc\Sections\Gift_Cards\Service(
		$store,
		$settings,
		new \FisHotel\Misc\Sections\Gift_Cards\Mailer(
			$settings,
			array(
				'shop'        => 'Test Fish Shop',
				'shop_url'    => 'https://example.test/',
				'account_url' => 'https://example.test/my-account/giftcards/',
			)
		),
		array_merge(
			array(
				'now' => static function () {
					return $GLOBALS['fh_clock'];
				},
				'tz'  => static function () {
					return new \DateTimeZone( 'America/Chicago' );
				},
				'log' => static function ( $level, $message ) {
					$GLOBALS['fh_logged'][] = array( $level, $message );
				},
			),
			$world
		),
		'test-audit-key'
	);
}

function fh_gc_days( $days ) {
	$GLOBALS['fh_clock'] += (int) $days * 86400;
}

/**
 * Audit contexts.
 */
function fh_gc_admin_ctx( $user_id = 1 ) {
	return array( 'user_id' => $user_id, 'name' => 'Test Owner (owner)', 'source' => 'admin', 'screen' => 'gift-cards/card', 'ip' => '203.0.113.9' );
}

function fh_gc_customer_ctx( $user_id, $source = 'account' ) {
	return array( 'user_id' => $user_id, 'name' => 'Customer ' . $user_id, 'source' => $source, 'screen' => 'test', 'ip' => '198.51.100.20' );
}

/**
 * Issue a card the quick way; its id.
 */
function fh_gc_card( $service, $amount = '50.00', array $more = array() ) {
	$r = $service->issue( array_merge( array( 'amount' => $amount, 'recipient_name' => 'Pat Tester', 'recipient_email' => 'pat@example.test', 'delivery' => 'none', 'request_key' => bin2hex( random_bytes( 8 ) ) ), $more ), fh_gc_admin_ctx() );
	if ( empty( $r['ok'] ) ) {
		throw new \RuntimeException( 'issue failed: ' . $r['message'] );
	}
	return (int) $r['card_id'];
}

/**
 * Issue a card straight into a customer's account.
 */
function fh_gc_wallet_card( $service, $user_id, $amount = '50.00' ) {
	$id   = fh_gc_card( $service, $amount );
	$card = $service->store->card( $id );
	$r    = $service->redeem( $card['code'], $user_id, fh_gc_customer_ctx( $user_id ) );
	if ( empty( $r['ok'] ) ) {
		throw new \RuntimeException( 'redeem failed: ' . $r['message'] );
	}
	fh_gc_tick();
	return $id;
}

/**
 * One second passes (so rows get distinct times).
 */
function fh_gc_tick( $seconds = 1 ) {
	$GLOBALS['fh_clock'] += (int) $seconds;
}

/**
 * Whether the ledger adds up to every card's stored balance, and every
 * money movement has its audit row.
 */
function fh_gc_balanced( $service ) {
	return array() === $service->store->mismatches( 10 ) && array() === $service->store->unlogged_movements( 10 );
}
