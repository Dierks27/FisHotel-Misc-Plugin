<?php
/**
 * The section as the framework sees it: found by its folder and class
 * name, booted on every request — and what that costs. An ordinary
 * front-end request reads one autoloaded option, runs no query, loads no
 * class beyond the store's, reads no setting; the admin side and the REST
 * routes are loaded only where they are used. Also: the first boot
 * installs the tables, activation forces it, and every PHP file of the
 * section sticks to PHP 7.4 syntax.
 *
 * Only the section class is loaded to begin with, as the Section Manager
 * does it.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/section-test.php
 */

define( 'FH_FOAM_SECTION_ONLY', true );
require_once __DIR__ . '/wp-stubs.php';

$ns      = 'FisHotel\\Misc\\Sections\\Foam_Cuts\\';
$section = $ns . 'Foam_Cuts';
$dir     = dirname( __DIR__ );

echo "1. Found by the Section Manager\n";
$slug       = basename( $dir );
$ns_segment = str_replace( ' ', '_', ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
check_same( 'the folder is foam-cuts and holds class-foam-cuts.php', array( 'foam-cuts', true ), array( $slug, is_file( $dir . '/class-' . $slug . '.php' ) ) );
check_same( 'the class the manager derives from the folder name exists', true, class_exists( 'FisHotel\\Misc\\Sections\\' . $ns_segment . '\\' . $ns_segment, false ) );
foreach ( array( 'get_section_info', 'load', 'boot', 'register_menu', 'activate', 'deactivate' ) as $method ) {
	check( "it has a static $method()", method_exists( $section, $method ) && ( new ReflectionMethod( $section, $method ) )->isStatic() );
}
$info = $section::get_section_info();
check( 'its card has a name, a description and a dashicon', array( 'name', 'description', 'icon' ) === array_keys( $info ) && 'Foam Cuts' === $info['name'] && strlen( $info['description'] ) > 80 && 0 === strpos( $info['icon'], 'dashicons-' ) );
check( 'loading the section class alone loads nothing else of the section', ! class_exists( $ns . 'Store', false ) && ! class_exists( $ns . 'Packer', false ) && ! class_exists( $ns . 'Admin', false ) && ! class_exists( $ns . 'Rest', false ) );
check( 'the helper other code may call exists', function_exists( 'fishotel_foam_cuts_plan' ) );

echo "2. The first boot installs the tables\n";
$wpdb                   = $GLOBALS['wpdb'];
$GLOBALS['fh_is_admin'] = false;
$section::boot();
check_same( 'dbDelta ran for the three tables', 3, count( $GLOBALS['fh_dbdelta'] ) );
check( 'and the version is written, autoloaded', '1.0' === get_option( 'fishotel_foam_cuts_db_version' ) && true === $GLOBALS['fh_autoload']['fishotel_foam_cuts_db_version'] );

echo "3. An ordinary front-end request after that\n";
fh_reset_hooks();
$GLOBALS['fh_dbdelta']      = array();
$GLOBALS['fh_option_reads'] = array();
$wpdb->queries              = array();
$section::boot();
check_same( 'reads exactly one option — the autoloaded schema version', array( 'fishotel_foam_cuts_db_version' ), $GLOBALS['fh_option_reads'] );
check_same( 'runs no query', array(), $wpdb->queries );
check_same( 'runs no dbDelta', array(), $GLOBALS['fh_dbdelta'] );
check_same( 'hooks one action: rest_api_init', array( 'rest_api_init' ), array_keys( $GLOBALS['fh_hooks'] ) );
check( 'loads the store class and nothing else: no packer, no settings, no admin, no REST class', class_exists( $ns . 'Store', false ) && ! class_exists( $ns . 'Packer', false ) && ! class_exists( $ns . 'Settings', false ) && ! class_exists( $ns . 'Orders', false ) && ! class_exists( $ns . 'Admin', false ) && ! class_exists( $ns . 'Rest', false ) );
check( 'does not read the settings or the label counter', ! in_array( 'fishotel_foam_cuts_settings', $GLOBALS['fh_option_reads'], true ) && ! in_array( 'fishotel_foam_cuts_next_label', $GLOBALS['fh_option_reads'], true ) );
check( 'asks WooCommerce for nothing', array() === $GLOBALS['fh_order_queries'] );

echo "4. The helper before anything is asked of it\n";
$wpdb->queries = array();
check( 'fishotel_foam_cuts_plan() answers null for a plan that does not exist — through the store, never an error', null === fishotel_foam_cuts_plan( 999 ) );
delete_option( 'fishotel_foam_cuts_db_version' );
$wpdb->queries = array();
check( 'and without the tables it answers null without a single query', null === fishotel_foam_cuts_plan( 1 ) && array() === $wpdb->queries );
update_option( 'fishotel_foam_cuts_db_version', '1.0', true );

update_option( 'fishotel_misc_enabled_sections', array( 'weather' ) );
$wpdb->queries              = array();
$GLOBALS['fh_option_reads'] = array();
check( 'with the section switched off it answers null — though the tables are there — without a query', null === fishotel_foam_cuts_plan( 0 ) && null === fishotel_foam_cuts_plan( 1 ) && array() === $wpdb->queries, fh_export( $wpdb->queries ) );
check_same( '…having read one option: which sections are on', array( 'fishotel_misc_enabled_sections' ), array_values( array_unique( $GLOBALS['fh_option_reads'] ) ) );
delete_option( 'fishotel_misc_enabled_sections' );
check( 'the same when that option is not there at all', null === fishotel_foam_cuts_plan( 0 ) && array() === $wpdb->queries );
update_option( 'fishotel_misc_enabled_sections', array( 'weather', 'foam-cuts' ) );
fishotel_foam_cuts_plan( 0 );
check( 'switched on, it asks the store', 1 === count( $wpdb->queries ) && false !== strpos( $wpdb->queries[0], 'fh_foam_plans' ), fh_export( $wpdb->queries ) );

echo "5. A REST request\n";
check_same( 'no route yet', array(), $GLOBALS['fh_rest_routes'] );
do_action( 'rest_api_init' );
check_same(
	'rest_api_init registers the routes under fishotel-misc/v1',
	array( 'fishotel-misc/v1/foam-cuts/plan/(?P<id>\d+|latest)', 'fishotel-misc/v1/foam-cuts/plans', 'fishotel-misc/v1/foam-cuts/plan' ),
	array_keys( $GLOBALS['fh_rest_routes'] )
);
check( 'and only then loads the REST class — still not the admin class', class_exists( $ns . 'Rest', false ) && ! class_exists( $ns . 'Admin', false ) );

echo "6. A wp-admin request\n";
fh_reset_hooks();
$GLOBALS['fh_is_admin'] = true;
$section::boot();
$hooks = array_keys( $GLOBALS['fh_hooks'] );
sort( $hooks );
check_same( 'hooks the write handler, the printable page (a visitor is sent to log in) and the style sheet', array( 'admin_enqueue_scripts', 'admin_post_fishotel_foam_cuts', 'admin_post_fishotel_foam_cuts_print', 'admin_post_nopriv_fishotel_foam_cuts_print', 'rest_api_init' ), $hooks );
check( 'no write can be reached by a visitor: the only nopriv hook is the print page\'s way to the login screen', array( 'admin_post_nopriv_fishotel_foam_cuts_print' ) === array_values( preg_grep( '/nopriv/', $hooks ) ) && 'login_first' === $GLOBALS['fh_hooks']['admin_post_nopriv_fishotel_foam_cuts_print'][10][0][0][1] );
check( 'the admin class is loaded now', class_exists( $ns . 'Admin', false ) );
$section::register_menu();
$menu = $GLOBALS['fh_submenus']['fishotel-misc-foam-cuts'];
check( 'the page sits under FisHotel Tools for manage_woocommerce', 'fishotel-misc' === $menu['parent'] && 'manage_woocommerce' === $menu['capability'] && 'Foam Cuts' === $menu['title'] && is_callable( $menu['callback'] ) );
check( 'its slug carries "fishotel-misc", so the plugin\'s dark style sheet is loaded on it', false !== strpos( $section::MENU_SLUG, 'fishotel-misc' ) );

echo "7. Activation, deactivation\n";
$GLOBALS['fh_dbdelta'] = array();
$wpdb->tables          = array();
$section::activate();
check( 'activation creates the tables again even though the version says they are there', 3 === count( $GLOBALS['fh_dbdelta'] ) && 3 === count( $wpdb->tables ) );
$before = $GLOBALS['fh_options'];
$section::deactivate();
check( 'deactivation removes nothing: the version, the tables and the stock stay', $before === $GLOBALS['fh_options'] && 3 === count( $wpdb->tables ) );

echo "8. Every file of the section\n";
$files = array_merge( glob( $dir . '/*.php' ), glob( $dir . '/views/*.php' ) );
check( 'there are files to check', count( $files ) >= 18 );
$guards  = array();
$modern  = array();
$he      = array();
$domains = array();
$newer   = array( 'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'fdiv', 'array_find', 'array_any', 'array_all', 'json_validate', 'mb_trim', 'mb_str_pad' );
foreach ( $files as $file ) {
	$code = (string) file_get_contents( $file );
	$name = str_replace( $dir . '/', '', $file );

	if ( 1 !== preg_match( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit\\s*;/", $code ) ) {
		$guards[] = $name;
	}
	if ( preg_match( '/\b(he|his|him|she|her|hers)\b/i', (string) preg_replace( '/[^a-zA-Z\s]/', ' ', $code ) ) ) {
		$he[] = $name;
	}
	if ( preg_match_all( "/(?:__|_e|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\((?:[^;]*?),\\s*'([a-z0-9-]+)'\\s*\\)/s", $code, $m ) ) {
		foreach ( array_unique( $m[1] ) as $domain ) {
			if ( 'fishotel-misc-plugin' !== $domain ) {
				$domains[] = $name . ': ' . $domain;
			}
		}
	}

	$tokens = token_get_all( $code );
	$count  = count( $tokens );
	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}
		$id = token_name( $token[0] );
		if ( in_array( $id, array( 'T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ENUM', 'T_READONLY', 'T_ATTRIBUTE', 'T_ELLIPSIS_UNUSED' ), true ) ) {
			$modern[] = $name . ':' . $token[2] . ' ' . $id;
		}
		if ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), $newer, true ) ) {
			$modern[] = $name . ':' . $token[2] . ' ' . $token[1] . '()';
		}
		// catch ( Type ) without a variable is PHP 8.
		if ( T_CATCH === $token[0] ) {
			$j     = $i + 1;
			$depth = 0;
			$has   = false;
			while ( $j < $count ) {
				$t = $tokens[ $j ];
				if ( '(' === $t ) {
					$depth++;
				} elseif ( ')' === $t ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( is_array( $t ) && T_VARIABLE === $t[0] ) {
					$has = true;
				}
				$j++;
			}
			if ( ! $has ) {
				$modern[] = $name . ':' . $token[2] . ' catch without a variable';
			}
		}
	}

	// A comma before the closing bracket of a parameter list is PHP 8.
	if ( preg_match( '/function\s*[A-Za-z_0-9]*\s*\([^()]*,\s*\)/', $code ) ) {
		$modern[] = $name . ' trailing comma in a parameter list';
	}
	// Union types, static / mixed / never return types, constructor promotion.
	if ( preg_match( '/\)\s*:\s*\??(static|mixed|never)\b|\b(public|protected|private)\s+[A-Za-z_\\\\?]+\s+\$[a-z_]+\s*[,)]|function\s*[A-Za-z_0-9]*\s*\([^)]*\b[A-Za-z_\\\\]+\|[A-Za-z_\\\\]+\s+\$/', $code ) ) {
		$modern[] = $name . ' a PHP 8 type';
	}
}
check_same( 'every file refuses to run outside WordPress', array(), $guards );
check_same( 'no file uses syntax or a function newer than PHP 7.4', array(), $modern );
check_same( 'every string is in the plugin\'s text domain', array(), $domains );
check_same( 'the wording is neutral throughout', array(), $he );
$outside = array();
foreach ( $files as $file ) {
	if ( preg_match( '#https?://(?!www\.w3\.org/2000/svg|example\.)#', (string) file_get_contents( $file ) ) ) {
		$outside[] = basename( $file );
	}
}
check_same( 'no file names an outside address: nothing is fetched, nothing is sent', array(), $outside );
$calls = array();
foreach ( $files as $file ) {
	if ( preg_match( '/\b(wp_mail|mail|wp_remote_get|wp_remote_post|wp_remote_request|curl_init|fsockopen|file_get_contents\s*\(\s*[\'"]http|wc_update_product_stock|wc_reduce_stock_levels|update_post_meta|update_meta_data|add_order_note|set_status|->save\s*\()/', (string) php_strip_whitespace( $file ), $m ) ) {
		$calls[] = basename( $file ) . ': ' . $m[1];
	}
}
check_same( 'no mail, no HTTP request, no product stock change, and no write to an order or a post anywhere in the section', array(), $calls );

fh_finish();
