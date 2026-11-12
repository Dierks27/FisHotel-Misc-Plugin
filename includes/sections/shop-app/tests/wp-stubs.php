<?php
/**
 * Just enough WordPress to run the Shop App section headless — and, for
 * the gate, the parts of WordPress that decide who is logged in written
 * the way core writes them:
 *
 *   - hooks with priorities and accepted arguments (add_filter,
 *     apply_filters, add_action, do_action, remove_filter, did_action,
 *     doing_action), and a snapshot / restore so one process can play
 *     many requests;
 *   - the current user exactly as pluggable.php works it out: a global,
 *     filled once per request from the determine_current_user filter, on
 *     which a stand-in for the auth cookie sits at priority 10
 *     ($_COOKIE['wordpress_logged_in_test'] = a user id);
 *   - capabilities as WP_User::has_cap() decides them: map_meta_cap (with
 *     its filter), the multisite super-admin shortcut, the user_has_cap
 *     filter, do_not_allow;
 *   - nonces as core makes them (tick | action | user id | session token,
 *     hashed with a salt), check_ajax_referer / check_admin_referer;
 *   - in-memory options that count every read a real site would answer
 *     with a query (anything not autoloaded), users, roles, transients;
 *   - redirects, wp_die and JSON answers that stop a handler by throwing
 *     FH_Halt, a recorder for status and cache headers;
 *   - a fake $wpdb that counts queries and knows which tables "exist".
 *
 * fh_request() starts a new pretend request; fh_boot_section() loads and
 * boots the section the way the Section Manager does.
 *
 * Exits 0 when run directly (CI runs every file in tests/).
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

// A stand-in WordPress root holding the one file the install step includes.
$GLOBALS['fh_abspath'] = sys_get_temp_dir() . '/fh-shop-app-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '/';
mkdir( $GLOBALS['fh_abspath'] . 'wp-admin/includes', 0777, true );
file_put_contents( $GLOBALS['fh_abspath'] . 'wp-admin/includes/upgrade.php', "<?php\n// Test stand-in: dbDelta() is defined by the stubs.\n" );
register_shutdown_function(
	static function () {
		$root = $GLOBALS['fh_abspath'];
		foreach ( array( 'wp-admin/includes/upgrade.php' ) as $file ) {
			if ( is_file( $root . $file ) ) {
				unlink( $root . $file );
			}
		}
		foreach ( array( 'wp-admin/includes', 'wp-admin', '' ) as $dir ) {
			if ( is_dir( $root . $dir ) ) {
				rmdir( $root . $dir );
			}
		}
	}
);

define( 'ABSPATH', $GLOBALS['fh_abspath'] );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://shop.example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'COOKIEHASH', 'abc123' );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ------------------------------------------------------------------ */
/*  Text                                                               */
/* ------------------------------------------------------------------ */

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) ) );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

function absint( $value ) {
	return abs( (int) $value );
}

function selected( $value, $current = true, $echo = true ) {
	$out = (string) $value === (string) $current ? " selected='selected'" : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function checked( $value, $current = true, $echo = true ) {
	$out = (string) $value === (string) $current ? " checked='checked'" : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function get_bloginfo( $show = '' ) {
	return 'language' === $show ? 'en-US' : 'Test Fish Shop';
}

function get_locale() {
	return 'en_US';
}

function current_time( $format ) {
	return gmdate( $format );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	} elseif ( ! is_array( $args ) ) {
		parse_str( (string) $args, $args );
	}
	return array_merge( (array) $defaults, $args );
}

/* ------------------------------------------------------------------ */
/*  Options — every read a real site answers with a query is counted    */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_options']       = array(
	'siteurl'     => 'https://shop.example.test',
	'home'        => 'https://shop.example.test',
	'date_format' => 'Y-m-d',
	'time_format' => 'H:i',
);
$GLOBALS['fh_autoload']      = array(
	'siteurl'     => true,
	'home'        => true,
	'date_format' => true,
	'time_format' => true,
);
$GLOBALS['fh_option_reads']  = array(); // Names read that were NOT autoloaded: each is a query on a real site.
$GLOBALS['fh_option_writes'] = array();

function get_option( $name, $default = false ) {
	if ( empty( $GLOBALS['fh_autoload'][ $name ] ) ) {
		$GLOBALS['fh_option_reads'][] = $name;
	}
	return array_key_exists( $name, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['fh_option_writes'][] = $name;
	if ( array_key_exists( $name, $GLOBALS['fh_options'] ) && $GLOBALS['fh_options'][ $name ] === $value ) {
		return false;
	}
	if ( ! array_key_exists( $name, $GLOBALS['fh_options'] ) || null !== $autoload ) {
		$GLOBALS['fh_autoload'][ $name ] = null === $autoload ? true : ( true === $autoload || 'yes' === $autoload || 'on' === $autoload );
	}
	$GLOBALS['fh_options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = true ) {
	if ( array_key_exists( $name, $GLOBALS['fh_options'] ) ) {
		return false;
	}
	$GLOBALS['fh_option_writes'][]   = $name;
	$GLOBALS['fh_options'][ $name ]  = $value;
	$GLOBALS['fh_autoload'][ $name ] = ( true === $autoload || 'yes' === $autoload || 'on' === $autoload );
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['fh_options'][ $name ], $GLOBALS['fh_autoload'][ $name ] );
	return true;
}

$GLOBALS['fh_transients'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['fh_transients'] ) ? $GLOBALS['fh_transients'][ $key ] : false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['fh_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['fh_transients'][ $key ] );
	return true;
}

/* ------------------------------------------------------------------ */
/*  Hooks                                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_hooks'] = array(); // hook => priority => list of array( callback, accepted args ).
$GLOBALS['fh_did']   = array();
$GLOBALS['fh_doing'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['fh_hooks'][ $hook ][ $priority ][] = array( $callback, (int) $accepted_args );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ][ $priority ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['fh_hooks'][ $hook ][ $priority ] as $i => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['fh_hooks'][ $hook ][ $priority ][ $i ] );
			return true;
		}
	}
	return false;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	return remove_filter( $hook, $callback, $priority );
}

function has_filter( $hook, $callback = false ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		foreach ( $GLOBALS['fh_hooks'][ $hook ] as $list ) {
			if ( $list ) {
				return true;
			}
		}
		return false;
	}
	foreach ( $GLOBALS['fh_hooks'][ $hook ] as $priority => $list ) {
		foreach ( $list as $entry ) {
			if ( $entry[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function has_action( $hook, $callback = false ) {
	return has_filter( $hook, $callback );
}

function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return $value;
	}
	$GLOBALS['fh_doing'][] = $hook;
	$priorities            = array_keys( $GLOBALS['fh_hooks'][ $hook ] );
	sort( $priorities, SORT_NUMERIC );
	foreach ( $priorities as $priority ) {
		// Callbacks added while the hook runs at this priority are picked up, as in core.
		$i = 0;
		while ( isset( $GLOBALS['fh_hooks'][ $hook ][ $priority ] ) ) {
			$list = array_values( $GLOBALS['fh_hooks'][ $hook ][ $priority ] );
			if ( ! isset( $list[ $i ] ) ) {
				break;
			}
			$entry = $list[ $i ];
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $entry[1] ) ) );
			++$i;
		}
	}
	array_pop( $GLOBALS['fh_doing'] );
	return $value;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['fh_did'][ $hook ] = did_action( $hook ) + 1;
	if ( empty( $GLOBALS['fh_hooks'][ $hook ] ) ) {
		return;
	}
	$GLOBALS['fh_doing'][] = $hook;
	$priorities            = array_keys( $GLOBALS['fh_hooks'][ $hook ] );
	sort( $priorities, SORT_NUMERIC );
	foreach ( $priorities as $priority ) {
		$i = 0;
		while ( isset( $GLOBALS['fh_hooks'][ $hook ][ $priority ] ) ) {
			$list = array_values( $GLOBALS['fh_hooks'][ $hook ][ $priority ] );
			if ( ! isset( $list[ $i ] ) ) {
				break;
			}
			$entry = $list[ $i ];
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
			++$i;
		}
	}
	array_pop( $GLOBALS['fh_doing'] );
}

function did_action( $hook ) {
	return isset( $GLOBALS['fh_did'][ $hook ] ) ? (int) $GLOBALS['fh_did'][ $hook ] : 0;
}

function doing_action( $hook = null ) {
	return null === $hook ? (bool) $GLOBALS['fh_doing'] : in_array( $hook, $GLOBALS['fh_doing'], true );
}

function __return_false() {
	return false;
}

function __return_true() {
	return true;
}

/* ------------------------------------------------------------------ */
/*  Users, roles, capabilities — as WP_User::has_cap() decides          */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_roles']       = array(
	'administrator' => array(
		'manage_options'     => true,
		'manage_woocommerce' => true,
		'edit_users'         => true,
		'edit_posts'         => true,
		'read'               => true,
	),
	'shop_manager'  => array(
		'manage_woocommerce' => true,
		'edit_posts'         => true,
		'read'               => true,
	),
	'customer'      => array( 'read' => true ),
);
$GLOBALS['fh_users']       = array(); // id => array( login, display_name, roles ).
$GLOBALS['fh_next_user']   = 1;
$GLOBALS['fh_multisite']   = false;
$GLOBALS['fh_super_admin'] = array(); // user ids.
$GLOBALS['fh_user_emails'] = array(); // user id => e-mail given to wp_insert_user().

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_User {
	public $ID = 0;
	public $user_login   = '';
	public $display_name = '';
	public $roles        = array();
	public $allcaps      = array();

	public function __construct( $id = 0 ) {
		$id = (int) $id;
		if ( $id > 0 && isset( $GLOBALS['fh_users'][ $id ] ) ) {
			$row                = $GLOBALS['fh_users'][ $id ];
			$this->ID           = $id;
			$this->user_login   = $row['login'];
			$this->display_name = $row['display_name'];
			$this->roles        = $row['roles'];
			foreach ( $row['roles'] as $role ) {
				foreach ( isset( $GLOBALS['fh_roles'][ $role ] ) ? $GLOBALS['fh_roles'][ $role ] : array() as $cap => $on ) {
					$this->allcaps[ $cap ] = $on;
				}
				$this->allcaps[ $role ] = true;
			}
		}
	}

	public function exists() {
		return $this->ID > 0;
	}

	/**
	 * WP_User::has_cap(), as core writes it.
	 */
	public function has_cap( $cap, ...$args ) {
		$caps = map_meta_cap( $cap, $this->ID, ...$args );

		// Multisite super admin has all caps by definition, unless specifically denied.
		if ( is_multisite() && is_super_admin( $this->ID ) ) {
			if ( in_array( 'do_not_allow', $caps, true ) ) {
				return false;
			}
			return true;
		}

		$args         = array_merge( array( $cap, $this->ID ), $args );
		$capabilities = apply_filters( 'user_has_cap', $this->allcaps, $caps, $args, $this );

		// Everyone is allowed to exist.
		$capabilities['exist'] = true;

		// Nobody is allowed to do things they are not allowed to do.
		unset( $capabilities['do_not_allow'] );

		// Must have ALL requested caps.
		foreach ( (array) $caps as $one ) {
			if ( empty( $capabilities[ $one ] ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * map_meta_cap(): edit_user as core maps it, every other capability as
 * itself — then the filter.
 */
function map_meta_cap( $cap, $user_id, ...$args ) {
	$caps = array();
	if ( 'edit_user' === $cap ) {
		if ( ! ( isset( $args[0] ) && (int) $user_id === (int) $args[0] ) ) {
			$caps[] = 'edit_users';
		}
	} else {
		$caps[] = $cap;
	}
	return apply_filters( 'map_meta_cap', $caps, $cap, $user_id, $args );
}

function is_multisite() {
	return (bool) $GLOBALS['fh_multisite'];
}

function is_super_admin( $user_id = false ) {
	return in_array( (int) ( false === $user_id ? get_current_user_id() : $user_id ), $GLOBALS['fh_super_admin'], true );
}

/**
 * Add a user to the pretend site.
 *
 * @return int The user id.
 */
function fh_add_user( $login, $role, $display_name = '' ) {
	$id                         = $GLOBALS['fh_next_user']++;
	$GLOBALS['fh_users'][ $id ] = array(
		'login'        => (string) $login,
		'display_name' => '' !== $display_name ? (string) $display_name : (string) $login,
		'roles'        => '' === (string) $role ? array() : array( (string) $role ),
	);
	return $id;
}

function get_userdata( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['fh_users'][ $id ] ) ? new WP_User( $id ) : false;
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['fh_users'] as $id => $row ) {
		if ( ( 'login' === $field && $row['login'] === $value ) || ( 'id' === $field && (int) $value === (int) $id ) ) {
			return new WP_User( $id );
		}
	}
	return false;
}

function get_users( $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['fh_users'] as $id => $row ) {
		if ( ! empty( $args['role__in'] ) && ! array_intersect( $row['roles'], (array) $args['role__in'] ) ) {
			continue;
		}
		if ( ! empty( $args['role'] ) && ! in_array( $args['role'], $row['roles'], true ) ) {
			continue;
		}
		$out[] = new WP_User( $id );
	}
	return empty( $args['number'] ) ? $out : array_slice( $out, 0, (int) $args['number'] );
}

function wp_insert_user( $data ) {
	foreach ( $GLOBALS['fh_users'] as $row ) {
		if ( $row['login'] === $data['user_login'] ) {
			return new WP_Error( 'existing_user_login', 'exists' );
		}
	}
	$id                               = fh_add_user( $data['user_login'], isset( $data['role'] ) ? $data['role'] : '', isset( $data['display_name'] ) ? $data['display_name'] : '' );
	$GLOBALS['fh_user_emails'][ $id ] = isset( $data['user_email'] ) ? (string) $data['user_email'] : '';
	return $id;
}

function get_role( $role ) {
	return isset( $GLOBALS['fh_roles'][ $role ] ) ? (object) array( 'name' => $role, 'capabilities' => $GLOBALS['fh_roles'][ $role ] ) : null;
}

function add_role( $role, $display_name, $capabilities = array() ) {
	if ( isset( $GLOBALS['fh_roles'][ $role ] ) ) {
		return null;
	}
	$GLOBALS['fh_roles'][ $role ] = $capabilities;
	return get_role( $role );
}

/* --- The current user, as pluggable.php works it out. --------------- */

$GLOBALS['current_user']       = null;
$GLOBALS['fh_determine_calls'] = 0;

function wp_set_current_user( $id, $name = '' ) {
	$GLOBALS['current_user'] = new WP_User( $id );
	do_action( 'set_current_user' );
	return $GLOBALS['current_user'];
}

function wp_get_current_user() {
	if ( ! empty( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof WP_User ) {
		return $GLOBALS['current_user'];
	}

	++$GLOBALS['fh_determine_calls'];
	$user_id = apply_filters( 'determine_current_user', false );
	if ( ! $user_id ) {
		wp_set_current_user( 0 );
		return $GLOBALS['current_user'];
	}

	wp_set_current_user( $user_id );
	return $GLOBALS['current_user'];
}

function get_current_user_id() {
	return (int) wp_get_current_user()->ID;
}

function is_user_logged_in() {
	return wp_get_current_user()->exists();
}

function current_user_can( $capability, ...$args ) {
	return wp_get_current_user()->has_cap( $capability, ...$args );
}

function user_can( $user, $capability, ...$args ) {
	$user = is_object( $user ) ? $user : get_userdata( $user );
	return $user ? $user->has_cap( $capability, ...$args ) : false;
}

/**
 * The stand-in for wp_validate_auth_cookie(): a WordPress login is a
 * cookie holding the user id.
 */
function fh_native_login( $user_id ) {
	if ( $user_id ) {
		return $user_id;
	}
	if ( isset( $_COOKIE['wordpress_logged_in_test'] ) && isset( $GLOBALS['fh_users'][ (int) $_COOKIE['wordpress_logged_in_test'] ] ) ) {
		return (int) $_COOKIE['wordpress_logged_in_test'];
	}
	return $user_id;
}

/* --- Nonces, as core makes them. ------------------------------------ */

function wp_salt( $scheme = 'auth' ) {
	return isset( $GLOBALS['fh_salt'] ) ? (string) $GLOBALS['fh_salt'] . $scheme : 'test-salt-' . $scheme;
}

function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'md5', $data, wp_salt( $scheme ) );
}

function wp_nonce_tick( $action = -1 ) {
	return ceil( time() / ( 86400 / 2 ) );
}

function wp_get_session_token() {
	return isset( $_COOKIE['wordpress_logged_in_test'] ) ? 'token-of-' . (int) $_COOKIE['wordpress_logged_in_test'] : '';
}

function wp_create_nonce( $action = -1 ) {
	$uid = (int) wp_get_current_user()->ID;
	if ( ! $uid ) {
		$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
	}
	return substr( wp_hash( wp_nonce_tick( $action ) . '|' . $action . '|' . $uid . '|' . wp_get_session_token(), 'nonce' ), -12, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	$nonce = (string) $nonce;
	$uid   = (int) wp_get_current_user()->ID;
	if ( ! $uid ) {
		$uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
	}
	if ( '' === $nonce ) {
		return false;
	}
	$token = wp_get_session_token();
	$i     = wp_nonce_tick( $action );

	if ( hash_equals( substr( wp_hash( $i . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 ), $nonce ) ) {
		return 1;
	}
	if ( hash_equals( substr( wp_hash( ( $i - 1 ) . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 ), $nonce ) ) {
		return 2;
	}
	return false;
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	$nonce = '';
	if ( $query_arg && isset( $_REQUEST[ $query_arg ] ) ) {
		$nonce = $_REQUEST[ $query_arg ];
	} elseif ( isset( $_REQUEST['_ajax_nonce'] ) ) {
		$nonce = $_REQUEST['_ajax_nonce'];
	} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
		$nonce = $_REQUEST['_wpnonce'];
	}
	$result = wp_verify_nonce( $nonce, $action );
	do_action( 'check_ajax_referer', $action, $result );
	if ( $stop && false === $result ) {
		throw new FH_Halt( 'die', '-1', 403 );
	}
	return $result;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	$result = isset( $_REQUEST[ $query_arg ] ) ? wp_verify_nonce( $_REQUEST[ $query_arg ], $action ) : false;
	if ( ! $result ) {
		throw new FH_Halt( 'die', 'The link you followed has expired.', 403 );
	}
	return $result;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	if ( $echo ) {
		echo $field;
	}
	return $field;
}

/* ------------------------------------------------------------------ */
/*  The request                                                        */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_is_admin']   = false;
$GLOBALS['fh_doing_ajax'] = false;
$GLOBALS['fh_is_ssl']     = true;
$GLOBALS['fh_query_vars'] = array();
$GLOBALS['fh_sent']       = array(
	'status'  => 200,
	'nocache' => false,
	'headers' => array(),
	'cookies' => array(),
);

/**
 * Where WordPress would exit: a redirect, a wp_die, a JSON answer.
 */
class FH_Halt extends Exception {
	public $kind;
	public $data;
	public $status;

	public function __construct( $kind, $data = null, $status = 200 ) {
		parent::__construct( (string) $kind );
		$this->kind   = $kind;
		$this->data   = $data;
		$this->status = $status;
	}
}

function is_admin() {
	return (bool) $GLOBALS['fh_is_admin'];
}

function wp_doing_ajax() {
	return (bool) $GLOBALS['fh_doing_ajax'];
}

function is_ssl() {
	return (bool) $GLOBALS['fh_is_ssl'];
}

function home_url( $path = '' ) {
	return rtrim( (string) $GLOBALS['fh_options']['home'], '/' ) . '/' . ltrim( (string) $path, '/' );
}

function site_url( $path = '' ) {
	return rtrim( (string) $GLOBALS['fh_options']['siteurl'], '/' ) . '/' . ltrim( (string) $path, '/' );
}

function admin_url( $path = '' ) {
	return site_url( 'wp-admin/' . ltrim( (string) $path, '/' ) );
}

function wp_login_url( $redirect = '' ) {
	return site_url( 'wp-login.php' ) . ( '' !== $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
}

function wp_logout_url( $redirect = '' ) {
	return site_url( 'wp-login.php' ) . '?action=logout&amp;_wpnonce=' . wp_create_nonce( 'log-out' ) . ( '' !== $redirect ? '&amp;redirect_to=' . rawurlencode( $redirect ) : '' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		$args = array( (string) $args => $url );
		$url  = func_num_args() > 2 ? func_get_arg( 2 ) : '';
	}
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args );
}

function get_query_var( $name, $default = '' ) {
	return isset( $GLOBALS['fh_query_vars'][ $name ] ) ? $GLOBALS['fh_query_vars'][ $name ] : $default;
}

function status_header( $code ) {
	$GLOBALS['fh_sent']['status'] = (int) $code;
}

function nocache_headers() {
	$GLOBALS['fh_sent']['nocache'] = true;
}

function wp_safe_redirect( $location, $status = 302 ) {
	throw new FH_Halt( 'redirect', (string) $location, $status );
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new FH_Halt( 'die', (string) $message, is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500 );
}

/**
 * wp_send_json_*(): the JSON is printed — as on a real site — and the
 * handler stops.
 */
function wp_send_json_success( $data = null, $status = null ) {
	$GLOBALS['fh_sent']['status'] = null === $status ? 200 : (int) $status;
	echo json_encode( array( 'success' => true, 'data' => $data ) );
	throw new FH_Halt( 'json', array( 'success' => true, 'data' => $data ), null === $status ? 200 : $status );
}

function wp_send_json_error( $data = null, $status = null ) {
	$GLOBALS['fh_sent']['status'] = null === $status ? 200 : (int) $status;
	echo json_encode( array( 'success' => false, 'data' => $data ) );
	throw new FH_Halt( 'json', array( 'success' => false, 'data' => $data ), null === $status ? 200 : $status );
}

function wp_enqueue_style() {
}

function wp_enqueue_script() {
}

function wp_localize_script() {
}

function add_submenu_page() {
	$GLOBALS['fh_submenu'] = func_get_args();
	return 'fishotel-tools_page_fishotel-misc-shop-app';
}

/* ------------------------------------------------------------------ */
/*  $wpdb — counts queries, knows which tables "exist"                  */
/* ------------------------------------------------------------------ */

class FH_Fake_WPDB {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $terms              = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $users              = 'wp_users';
	public $usermeta           = 'wp_usermeta';
	public $options            = 'wp_options';
	public $insert_id  = 0;
	public $last_error = '';
	public $queries    = array();
	public $tables     = array(); // Tables that "exist" (SHOW TABLES).
	public $deltas     = array(); // The statements dbDelta() was handed.
	public $delta_ok   = true;    // Whether dbDelta() really "creates" its table.

	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback(
				'/%[ds]/',
				static function ( $m ) use ( $arg ) {
					return '%d' === $m[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
				},
				$sql,
				1
			);
		}
		return $sql;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) {
			return in_array( stripslashes( $m[1] ), $this->tables, true ) ? stripslashes( $m[1] ) : null;
		}
		return null;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		return array();
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return array();
	}

	public function get_row( $sql, $output = null ) {
		$this->queries[] = $sql;
		return null;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 0;
	}

	public function insert( $table, $data, $format = null ) {
		$this->queries[] = 'INSERT INTO ' . $table;
		return false;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->queries[] = 'UPDATE ' . $table;
		return false;
	}

	public function delete( $table, $where, $where_format = null ) {
		$this->queries[] = 'DELETE FROM ' . $table;
		return false;
	}
}

$GLOBALS['wpdb'] = new FH_Fake_WPDB();

function dbDelta( $sql ) {
	$GLOBALS['wpdb']->deltas[] = $sql;
	if ( $GLOBALS['wpdb']->delta_ok && preg_match( '/CREATE TABLE (\S+)/', $sql, $m ) ) {
		$GLOBALS['wpdb']->tables[] = $m[1];
	}
	return array();
}

/* ------------------------------------------------------------------ */
/*  The section                                                        */
/* ------------------------------------------------------------------ */

require_once FISHOTEL_MISC_PATH . 'includes/class-section-manager.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/shop-app/class-shop-app.php';
\FisHotel\Misc\Sections\Shop_App\Shop_App::load();
require_once FISHOTEL_MISC_PATH . 'includes/sections/shop-app/class-admin.php';
require_once __DIR__ . '/fake-store.php';

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\App;
use FisHotel\Misc\Sections\Shop_App\Gate;
use FisHotel\Misc\Sections\Shop_App\Http;
use FisHotel\Misc\Sections\Shop_App\People;
use FisHotel\Misc\Sections\Shop_App\Saves;
use FisHotel\Misc\Sections\Shop_App\Settings;

// Cookies and headers go to a recorder instead of the (absent) browser.
Http::$cookie_sender = static function ( $name, $value, $options ) {
	$GLOBALS['fh_sent']['cookies'][] = array(
		'name'    => $name,
		'value'   => $value,
		'options' => $options,
	);
};
Http::$header_sender = static function ( $line ) {
	$GLOBALS['fh_sent']['headers'][] = $line;
};

/**
 * The clock every Access in the tests reads; a test moves it.
 */
$GLOBALS['fh_now'] = 1800000000;

function fh_clock() {
	return (int) $GLOBALS['fh_now'];
}

/**
 * A fresh in-memory store behind a fresh Access (the people, devices and
 * log of earlier checks are gone), with the site secret in place.
 *
 * @return \FisHotel\Misc\Sections\Shop_App\Tests\Fake_Store
 */
function fh_fresh_store() {
	$store = new \FisHotel\Misc\Sections\Shop_App\Tests\Fake_Store();
	Access::set_instance( new Access( $store, 'fh_clock' ) );
	if ( ! isset( $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] ) ) {
		$GLOBALS['fh_options'][ Settings::SECRET_OPTION ]  = str_repeat( 'ab', 32 );
		$GLOBALS['fh_autoload'][ Settings::SECRET_OPTION ] = false;
	}
	Settings::forget();
	return $store;
}

/**
 * The store behind the current Access.
 *
 * @return \FisHotel\Misc\Sections\Shop_App\Tests\Fake_Store
 */
function fh_store() {
	return Access::instance()->store();
}

/**
 * Boot the section as the Section Manager does (once per process), with
 * core's auth-cookie stand-in in place, and remember the hooks so every
 * pretend request starts from them.
 *
 * @param bool $clean_room Whether the Clean Room section is enabled (its real classes are then loaded).
 * @param bool $shop_app   Whether the Shop App section is switched on (false = the site as it was before it).
 */
function fh_boot_section( $clean_room = true, $shop_app = true ) {
	add_filter( 'determine_current_user', 'fh_native_login', 10 );

	$enabled = array( 'shop-app' );
	if ( $clean_room ) {
		$enabled[] = 'clean-room';
		require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-clean-room.php';
		\FisHotel\Misc\Sections\Clean_Room\Clean_Room::load();
		$front = new \FisHotel\Misc\Sections\Clean_Room\Front_Desk();
		$front->init();
	}
	$GLOBALS['fh_options']['fishotel_misc_enabled_sections']  = $enabled;
	$GLOBALS['fh_autoload']['fishotel_misc_enabled_sections'] = true;

	// The tables "exist" and the version gate is open: boot() installs nothing.
	$GLOBALS['fh_options'][ \FisHotel\Misc\Sections\Shop_App\Store::DB_VERSION_OPTION ]  = \FisHotel\Misc\Sections\Shop_App\Store::DB_VERSION;
	$GLOBALS['fh_autoload'][ \FisHotel\Misc\Sections\Shop_App\Store::DB_VERSION_OPTION ] = true;

	if ( $shop_app ) {
		\FisHotel\Misc\Sections\Shop_App\Shop_App::boot();
	}

	$GLOBALS['fh_hooks_boot'] = $GLOBALS['fh_hooks'];
}

/**
 * Change the hooks every pretend request starts from (a test adding a
 * filter of its own): the callback runs on the hooks as they were after
 * boot — not on what an earlier pretend request left behind.
 *
 * @param callable $change Adds or removes hooks.
 */
function fh_boot_hooks( callable $change ) {
	$GLOBALS['fh_hooks'] = $GLOBALS['fh_hooks_boot'];
	$change();
	$GLOBALS['fh_hooks_boot'] = $GLOBALS['fh_hooks'];
}

/**
 * Start a new pretend request: the hooks as they were after boot, nobody
 * logged in yet, nothing sent, the section's per-request memory empty.
 *
 * @param array $request method, uri (path + query), post, cookies, admin, ajax.
 */
function fh_request( array $request = array() ) {
	$method = isset( $request['method'] ) ? strtoupper( $request['method'] ) : 'GET';
	$uri    = isset( $request['uri'] ) ? (string) $request['uri'] : '/';

	if ( isset( $GLOBALS['fh_hooks_boot'] ) ) {
		$GLOBALS['fh_hooks'] = $GLOBALS['fh_hooks_boot'];
	}
	$GLOBALS['fh_did']             = array();
	$GLOBALS['fh_doing']           = array();
	$GLOBALS['current_user']       = null;
	$GLOBALS['fh_determine_calls'] = 0;
	$GLOBALS['fh_option_reads']    = array();
	$GLOBALS['fh_option_writes']   = array();
	$GLOBALS['fh_query_vars']      = array();
	$GLOBALS['fh_is_admin']        = ! empty( $request['admin'] );
	$GLOBALS['fh_doing_ajax']      = ! empty( $request['ajax'] );
	$GLOBALS['fh_sent']            = array(
		'status'  => 200,
		'nocache' => false,
		'headers' => array(),
		'cookies' => array(),
	);
	$GLOBALS['wpdb']->queries      = array();

	$query = (string) parse_url( $uri, PHP_URL_QUERY );
	parse_str( $query, $get );

	$_GET     = $get;
	$_POST    = isset( $request['post'] ) ? (array) $request['post'] : array();
	$_REQUEST = array_merge( $_GET, $_POST );
	$_COOKIE  = isset( $request['cookies'] ) ? (array) $request['cookies'] : array();
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['REQUEST_URI']    = $uri;
	unset( $_SERVER['HTTP_X_FH_SHOP_APP'] );
	if ( ! empty( $request['app_header'] ) ) {
		$_SERVER['HTTP_X_FH_SHOP_APP'] = '1';
	}

	Gate::reset();
	App::reset();
	Saves::reset();
	Settings::forget();
	Access::instance()->forget();
	fh_store()->queries = 0;
}

/**
 * The cookies the pretend response set (name => value), deletions as ''.
 *
 * @return array<string, string>
 */
function fh_cookies_set() {
	$out = array();
	foreach ( $GLOBALS['fh_sent']['cookies'] as $cookie ) {
		$out[ $cookie['name'] ] = $cookie['options']['expires'] < time() ? '' : $cookie['value'];
	}
	return $out;
}

/**
 * A browser's cookie jar after the pretend response: what it had, plus
 * what was set, minus what was deleted.
 *
 * @param array $jar The cookies it sent.
 * @return array
 */
function fh_jar( array $jar ) {
	foreach ( fh_cookies_set() as $name => $value ) {
		if ( '' === $value ) {
			unset( $jar[ $name ] );
		} else {
			$jar[ $name ] = $value;
		}
	}
	return $jar;
}

/**
 * Run something that ends where WordPress would exit.
 *
 * @param callable $fn The handler.
 * @return array{halt: FH_Halt|null, output: string}
 */
function fh_run( callable $fn ) {
	$halt  = null;
	$level = ob_get_level();
	ob_start();
	try {
		$fn();
	} catch ( FH_Halt $e ) {
		$halt = $e;
	}
	// A handler may have opened buffers of its own (the bar, a remembered save).
	$output = '';
	while ( ob_get_level() > $level ) {
		$output = ob_get_clean() . $output;
	}
	return array(
		'halt'   => $halt,
		'output' => $output,
	);
}

/**
 * Add a person with a PIN straight into the store.
 *
 * @return int The person's id.
 */
function fh_add_person( $name, $pin, $user_id = 0, $active = true ) {
	$salt = \FisHotel\Misc\Sections\Shop_App\Pins::new_salt();
	return (int) fh_store()->insert_person(
		array(
			'name'       => $name,
			'pin_hash'   => \FisHotel\Misc\Sections\Shop_App\Pins::hash( $pin, $salt, Settings::secret() ),
			'pin_salt'   => $salt,
			'pin_length' => strlen( $pin ),
			'user_id'    => $user_id,
			'active'     => $active ? 1 : 0,
		)
	);
}

/* ------------------------------------------------------------------ */
/*  check() / fh_finish()                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['fh_failures'] = 0;
$GLOBALS['fh_checks']   = 0;

function check( $label, $condition, $detail = '' ) {
	$GLOBALS['fh_checks']++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$GLOBALS['fh_failures']++;
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n";
}

function fh_finish() {
	$failures = $GLOBALS['fh_failures'];
	$checks   = $GLOBALS['fh_checks'];

	echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
	exit( $failures ? 1 : 0 );
}
