<?php
/**
 * Section tests — the section as the Section Manager finds and runs it:
 * the class and its static methods, what boot() hooks (and that an
 * ordinary front-end request then costs no query, no option write and no
 * cron call), activation and deactivation, the menu label with its count,
 * the contract function other sections read, and the rules the folder
 * keeps: one $wpdb file, an ABSPATH guard on every file, no shipping rate
 * written in code, and the ONE line in the Clean Room this section
 * depends on.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/section-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

// A temporary "WordPress" for activation: its dbDelta() creates the tables in the stand-in $wpdb.
$fh_base = getenv( 'FH_TEST_TMP' );
$fh_root = rtrim( false !== $fh_base && '' !== $fh_base ? $fh_base : sys_get_temp_dir(), '/\\' ) . '/fh-lostfish-section-' . getmypid() . '/';
if ( ! is_dir( $fh_root . 'wp-admin/includes' ) && ! mkdir( $fh_root . 'wp-admin/includes', 0700, true ) ) {
	fwrite( STDERR, "Cannot create {$fh_root}\n" );
	exit( 1 );
}
file_put_contents(
	$fh_root . 'wp-admin/includes/upgrade.php',
	<<<'PHP'
<?php
function dbDelta( $sql ) {
	global $wpdb;
	$wpdb->dbdelta++;
	if ( preg_match( '/CREATE TABLE (\S+) \((.*)\)/s', $sql, $m ) ) {
		$columns = array();
		foreach ( explode( "\n", $m[2] ) as $line ) {
			if ( preg_match( '/^\s*([a-z_]+) (bigint|int|tinyint|varchar|datetime|text)/', $line, $c ) ) {
				$columns[] = $c[1];
			}
		}
		$wpdb->tables[ $m[1] ] = $columns;
	}
	return array();
}
PHP
);
define( 'ABSPATH', $fh_root );
register_shutdown_function(
	static function () use ( $fh_root ) {
		@unlink( $fh_root . 'wp-admin/includes/upgrade.php' );
		@rmdir( $fh_root . 'wp-admin/includes' );
		@rmdir( $fh_root . 'wp-admin' );
		@rmdir( $fh_root );
	}
);

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Customer_Page;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Store;
use FisHotel\Misc\Sections\Lost_Fish\Today_Line;

/**
 * A $wpdb that counts what it is asked.
 */
class Fh_Counting_Wpdb {

	public $prefix  = 'wp_';
	public $queries = 0;
	public $dbdelta = 0;
	public $tables  = array();
	public $rows    = array();

	public function get_charset_collate() {
		return '';
	}

	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args );
	}

	public function get_var( $sql ) {
		$this->queries++;
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ) {
			return isset( $this->tables[ $m[1] ] ) ? $m[1] : null;
		}
		return 0;
	}

	public function get_col( $sql ) {
		$this->queries++;
		return preg_match( '/SHOW COLUMNS FROM (\S+)/', $sql, $m ) && isset( $this->tables[ $m[1] ] ) ? $this->tables[ $m[1] ] : array();
	}

	public function get_results( $sql, $output = null ) {
		$this->queries++;
		return $this->rows;
	}

	public function get_row( $sql, $output = null ) {
		$this->queries++;
		return null;
	}

	public function query( $sql ) {
		$this->queries++;
		return 0;
	}
}

$GLOBALS['fh_menus'] = array();

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
	$GLOBALS['fh_menus'][] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return 'fishotel-tools_page_' . $slug;
}

$folder = dirname( __DIR__ );

echo "Found by the Section Manager\n";
// The manager's own mapping: 'lost-fish' → class-lost-fish.php → …\Lost_Fish\Lost_Fish.
$slug       = basename( $folder );
$ns_segment = str_replace( ' ', '_', ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
$class      = 'FisHotel\\Misc\\Sections\\' . $ns_segment . '\\' . $ns_segment;
check_same( 'the folder is lost-fish', 'lost-fish', $slug );
check( 'its class file is class-lost-fish.php', is_file( $folder . '/class-' . $slug . '.php' ) );
check( 'which declares ' . $class, class_exists( $class ) && Lost_Fish::class === $class );
foreach ( array( 'get_section_info', 'load', 'boot', 'register_menu', 'activate', 'deactivate' ) as $method ) {
	$ref = new \ReflectionMethod( $class, $method );
	check( $method . '() is public and static', $ref->isPublic() && $ref->isStatic() );
}
$info = Lost_Fish::get_section_info();
check_same( 'the card on the dashboard: a name, a description, an icon', array( 'Lost Fish', true, true ), array( $info['name'], strlen( $info['description'] ) > 40, 0 === strpos( $info['icon'], 'dashicons-' ) ) );
check_same( 'the screen and every write need manage_woocommerce', 'manage_woocommerce', Lost_Fish::CAPABILITY );

echo "\nWhile the section is switched off\n";
$GLOBALS['wpdb'] = new Fh_Counting_Wpdb();
unset( $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] ); // Never switched on: the tables do not exist.
check( 'the contract function exists all the same — the Section Manager loads this file to list the section', function_exists( 'fishotel_lostfish_cases_for_order' ) );
check_same( 'it answers an empty list, and asks the database NOTHING (there is no table to ask)', array( array(), 0 ), array( fishotel_lostfish_cases_for_order( 12345 ), $GLOBALS['wpdb']->queries ) );
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION; // Switched off again later: the tables are still there.
check_same( '… also when the tables are there but the section did not boot', array( array(), 0 ), array( fishotel_lostfish_cases_for_order( 12345 ), $GLOBALS['wpdb']->queries ) );

echo "\nAn ordinary front-end request\n";
$GLOBALS['wpdb']                                   = new Fh_Counting_Wpdb();
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
$GLOBALS['fh_option_writes']                       = 0;
$GLOBALS['fh_is_admin']                            = false;
$GLOBALS['fh_actions']                             = array();
$GLOBALS['fh_cron']                                = array();
Lost_Fish::boot();
check_same( 'boot() runs no query', 0, $GLOBALS['wpdb']->queries );
check_same( '… writes no option', 0, $GLOBALS['fh_option_writes'] );
check_same( '… schedules nothing', array(), $GLOBALS['fh_cron'] );
check_same( '… and hooks three things, nothing else', array( 'fishotel_cleanroom_event_recorded', 'fishotel_lostfish_tick', 'template_redirect' ), array_keys( $GLOBALS['fh_actions'] ) );
check_same( 'the Clean Room\'s action, with its four arguments', array( array( Cases::class, 'on_cleanroom_event' ), 4 ), array_slice( $GLOBALS['fh_actions']['fishotel_cleanroom_event_recorded'][0], 0, 2 ) );
check_same( 'the page behind the links, before any theme template', array( array( Customer_Page::class, 'maybe_render' ), 0, 0 ), $GLOBALS['fh_actions']['template_redirect'][0] );

$_GET  = array( 'p' => '12', 's' => 'goby' );
$_POST = array();
ob_start();
do_action( 'template_redirect' );
check_same( 'a page view without the link argument: the hook returns at once — no output, no query', array( '', 0 ), array( ob_get_clean(), $GLOBALS['wpdb']->queries ) );

$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array( 'done' => 4, 'closed' => 1 );
do_action( 'fishotel_lostfish_tick' );
check_same( 'the cron tick with no case open or sent: no query either', 0, $GLOBALS['wpdb']->queries );
check( 'off the front end: no line for the Today page is hooked (that is wp-admin only)', empty( $GLOBALS['fh_filters']['fishotel_today_lines'] ) );

echo "\nIn wp-admin\n";
$GLOBALS['fh_is_admin'] = true;
$GLOBALS['fh_actions']  = array();
Lost_Fish::boot();
check_same( 'still no query', 0, $GLOBALS['wpdb']->queries );
check( 'the hourly tick is scheduled', array( 'hourly', Lost_Fish::TICK_HOOK ) === array( $GLOBALS['fh_cron'][0][0], $GLOBALS['fh_cron'][0][2] ) );
Lost_Fish::boot();
check_same( '… once', 1, count( $GLOBALS['fh_cron'] ) );
foreach ( array( 'admin_post_fishotel_lostfish_case', 'admin_post_fishotel_lostfish_open', 'admin_post_fishotel_lostfish_settings', 'woocommerce_after_order_itemmeta', 'admin_enqueue_scripts' ) as $hook ) {
	check( 'hooked: ' . $hook, isset( $GLOBALS['fh_actions'][ $hook ] ) );
}
$public = array();
foreach ( array_keys( $GLOBALS['fh_actions'] ) as $hook ) {
	if ( 0 === strpos( $hook, 'wp_ajax_nopriv_' ) || 0 === strpos( $hook, 'admin_post_nopriv_' ) || 'rest_api_init' === $hook ) {
		$public[] = $hook;
	}
}
check_same( 'no handler for visitors who are not logged in, and no REST route — the customer\'s page is the only way in from outside', array(), $public );

echo "\nIts line on the Today page\n";
$GLOBALS['fh_current_user']                       = 7;
$GLOBALS['fh_user_caps']['manage_woocommerce']    = array( 7 );
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ]    = array( 'open' => 1, 'answered' => 2, 'stale' => 0, 'done' => 4 );
Today_Line::use_source();
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check_same( 'on: "Lost fish: 3 cases need you" joins the other lines, under Customers', array( 'other', 'lost-fish', 'Lost fish: 3 cases need you', 'customers', 3 ), array( $fh_lines[0]['key'], $fh_lines[1]['key'], $fh_lines[1]['text'], $fh_lines[1]['group'], $fh_lines[1]['count'] ) );
check_same( '… with what waits beside it, and a link to the cases', array( '1 to send · 2 answers came in', 'bad', 'http://example.test/wp-admin/admin.php?page=fishotel-misc-lost-fish' ), array( $fh_lines[1]['detail'], $fh_lines[1]['tone'], str_replace( 'https://', 'http://', $fh_lines[1]['url'] ) ) );
$GLOBALS['wpdb'] = new Fh_Counting_Wpdb();
apply_filters( 'fishotel_today_lines', array() );
check_same( '… read from the stored counts (no query), once per request', 0, $GLOBALS['wpdb']->queries );
Today_Line::use_source( static function () { return array( 'open' => 0, 'answered' => 0, 'stale' => 0, 'sent' => 3, 'done' => 9 ); } );
check_same( 'empty: nothing needs the owner (cases only sent or done) — no line', array(), apply_filters( 'fishotel_today_lines', array() ) );
Today_Line::use_source( static function () { return array( 'stale' => 1 ); } );
check_same( 'one case with no answer after the reminder: one case, "todo"', array( 'Lost fish: 1 case needs you', '1 with no answer', 'todo' ), array_values( array_intersect_key( Today_Line::with_line( array(), Today_Line::line( array( 'stale' => 1 ), '' ) )[0], array_flip( array( 'text', 'detail', 'tone' ) ) ) ) );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array();
check_same( 'a user who may not open the section: no line', array(), apply_filters( 'fishotel_today_lines', array() ) );
check_same( 'never twice: a line under the same key is replaced', 1, count( Today_Line::with_line( array( array( 'key' => 'lost-fish', 'text' => 'old' ) ), Today_Line::line( array( 'open' => 1 ), '' ) ) ) );
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 7 );
Today_Line::use_source( static function () { throw new \RuntimeException( 'x' ); } );
check_same( 'a source that fails adds nothing', array(), apply_filters( 'fishotel_today_lines', array() ) );
Today_Line::use_source();

echo "\nThe first boot, activation, deactivation\n";
unset( $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] );
$GLOBALS['wpdb']        = new Fh_Counting_Wpdb();
$GLOBALS['fh_is_admin'] = false;
Lost_Fish::boot();
check_same( 'the very first boot installs the two tables and writes the version', array( 2, Store::DB_VERSION ), array( $GLOBALS['wpdb']->dbdelta, get_option( Store::DB_VERSION_OPTION ) ) );
$GLOBALS['wpdb'] = new Fh_Counting_Wpdb();
Lost_Fish::boot();
check_same( '… and the next one does nothing', array( 0, 0 ), array( $GLOBALS['wpdb']->dbdelta, $GLOBALS['wpdb']->queries ) );

$GLOBALS['fh_cron'] = array();
Lost_Fish::activate();
check_same( 'activation installs again, past the version gate (a dropped table comes back), and schedules the tick', array( 2, 1 ), array( $GLOBALS['wpdb']->dbdelta, count( $GLOBALS['fh_cron'] ) ) );
Lost_Fish::deactivate();
check_same( 'deactivation unschedules the tick', array(), array_values( $GLOBALS['fh_cron'] ) );
check_same( '… and keeps the tables and the version: the cases are not thrown away', array( Store::DB_VERSION, 2 ), array( get_option( Store::DB_VERSION_OPTION ), count( $GLOBALS['wpdb']->tables ) ) );

echo "\nThe menu\n";
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array();
$GLOBALS['wpdb']                               = new Fh_Counting_Wpdb();
Lost_Fish::register_menu();
$menu = $GLOBALS['fh_menus'][0];
check_same( 'under FisHotel Tools, for manage_woocommerce', array( 'fishotel-misc', 'manage_woocommerce', Lost_Fish::MENU_SLUG, 'Lost Fish' ), array( $menu['parent'], $menu['capability'], $menu['slug'], $menu['menu_title'] ) );
$GLOBALS['fh_options'][ Store::COUNTS_OPTION ] = array( 'open' => 2, 'answered' => 1, 'sent' => 5, 'stale' => 1 );
Lost_Fish::register_menu();
check_same( 'with the number of cases that need the owner beside it', 'Lost Fish <span class="awaiting-mod">4</span>', $GLOBALS['fh_menus'][1]['menu_title'] );
check_same( '… read from the autoloaded option, without a query', 0, $GLOBALS['wpdb']->queries );

echo "\nFor other sections\n";
check( 'fishotel_lostfish_cases_for_order() exists', function_exists( 'fishotel_lostfish_cases_for_order' ) );
$GLOBALS['wpdb']->rows = array(
	array( 'id' => '7', 'status' => 'answered', 'fish_name' => 'Lagoon Goby', 'qty' => '1', 'order_id' => '501', 'order_item_id' => '51', 'choice' => 'refund', 'amount_refund' => '2901', 'amount_cancel' => '53900' ),
);
$rows = fishotel_lostfish_cases_for_order( 501 );
check_same( 'the cases of an order: status, fish, choice, the amount owed back', array( 7, 'answered', 'Lagoon Goby', 1, 501, 51, 'refund', 2901 ), array( $rows[0]['id'], $rows[0]['status'], $rows[0]['fish'], $rows[0]['qty'], $rows[0]['order_id'], $rows[0]['order_item_id'], $rows[0]['choice'], $rows[0]['amount'] ) );
$GLOBALS['wpdb']->rows = array();
check_same( 'an order without one: an empty list', array(), fishotel_lostfish_cases_for_order( 502 ) );
$version                 = $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ];
$GLOBALS['wpdb']->rows   = array( array( 'id' => '7', 'status' => 'open', 'order_id' => '501' ) );
$GLOBALS['wpdb']->queries = 0;
unset( $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] );
check_same( 'switched on, but the tables are not installed (yet): an empty list and no query', array( array(), 0 ), array( fishotel_lostfish_cases_for_order( 501 ), $GLOBALS['wpdb']->queries ) );
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = $version;
$GLOBALS['wpdb']->rows                             = array();

echo "\nThe folder's rules\n";
$files = array_merge( glob( $folder . '/*.php' ), glob( $folder . '/views/*.php' ) );
check( 'the files are there', count( $files ) >= 18 );
$wpdb_files = array();
$unguarded  = array();
$rates      = array();
$fh_money   = '/(?<![\w.])\d+\.\d{2}(?![\w.])|\$\d/';
check( 'the check itself: it sees an amount written as 12.34 or with a dollar sign, and not a version, a line height or a %1$s', 1 === preg_match( $fh_money, '$cost = 12.34;' ) && 1 === preg_match( $fh_money, "'over $" . "12'" ) && 0 === preg_match( $fh_money, "'1.0' line-height:1.6 %1\$s 16px 2.5.1 .08em" ) );
foreach ( $files as $file ) {
	$text = (string) file_get_contents( $file );
	$code = preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', $text ); // Without comments.
	if ( false !== strpos( $code, '$wpdb' ) ) {
		$wpdb_files[] = basename( $file );
	}
	if ( 1 !== preg_match( "/defined\(\s*'ABSPATH'\s*\)\s*\|\|\s*exit\s*;/", $text ) ) {
		$unguarded[] = basename( $file );
	}
	// A rate or a threshold in code would be money written out: 12.34, or $12.
	if ( 'class-admin.php' !== basename( $file ) && 1 === preg_match( $fh_money, $code ) ) {
		$rates[] = basename( $file );
	}
}
check_same( 'class-store.php is the only file that touches $wpdb', array( 'class-store.php' ), $wpdb_files );
check_same( 'every file refuses to be opened directly', array(), $unguarded );
check_same( 'no shipping rate and no threshold is written in the code that decides: no amount of money appears in it', array(), $rates );
check( 'the Settings preview\'s sample (class-admin.php, the one file set aside) says it is invented', false !== strpos( (string) file_get_contents( $folder . '/class-admin.php' ), 'invented sample data' ) );

$mail_calls = array();
foreach ( $files as $file ) {
	$code = preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents( $file ) );
	if ( preg_match( '/\bwp_mail\s*\(/', $code ) ) {
		$mail_calls[] = basename( $file );
	}
}
check_same( 'wp_mail() is called in one place: Email::send()', array( 'class-email.php' ), $mail_calls );
$senders = array();
foreach ( $files as $file ) {
	$code = preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents( $file ) );
	if ( preg_match( '/Email::send\s*\(/', $code ) ) {
		$senders[] = basename( $file );
	}
}
check_same( '… which only Cases calls: for the send behind the click (or the setting) and for the one reminder', array( array( 'class-cases.php' ), 2 ), array( $senders, substr_count( preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents( $folder . '/class-cases.php' ) ), 'Email::send(' ) ) );
$money_movers = array();
foreach ( $files as $file ) {
	$code = preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents( $file ) );
	if ( preg_match( '/wc_create_refund|->update_status\s*\(|->set_status\s*\(|wc_update_product_stock|->set_stock|update_user_meta|->delete\s*\(|wp_delete_post|->save\s*\(/', $code ) ) {
		$money_movers[] = basename( $file );
	}
}
check_same( 'nothing refunds, cancels, changes stock, a wallet or an order: the only thing written to an order is a note', array(), $money_movers );

echo "\nThe one line in the Clean Room\n";
$cleanroom = (string) file_get_contents( dirname( $folder ) . '/clean-room/class-store.php' );
check_same( 'Store::record_event() fires fishotel_cleanroom_event_recorded, once', 1, substr_count( $cleanroom, "do_action( 'fishotel_cleanroom_event_recorded'," ) );
$start = strpos( $cleanroom, 'public function record_event(' );
$end   = strpos( $cleanroom, 'public function undo_event(' );
$body  = substr( $cleanroom, $start, $end - $start );
check( '… after the event was written and the stay replayed, with the stay, the event id, the event and the state', 1 === preg_match( '/\$state\s*=\s*\$this->replay\(.*?\);\s*do_action\( \'fishotel_cleanroom_event_recorded\', \(int\) \$stay_id, \$event_id, \$data, \$state \);/s', $body ) );
check_same( 'the name this section listens for is that name', 'fishotel_cleanroom_event_recorded', Lost_Fish::CLEANROOM_EVENT );
check( '… and the undo it understands is the note the Clean Room\'s Replay writes', false !== strpos( (string) file_get_contents( dirname( $folder ) . '/clean-room/class-replay.php' ), "const NOTE_UNDO = '" . Cases::NOTE_UNDO . "';" ) );
check_same( 'an undo note points at its event', array( 9002, 0, 0 ), array( Cases::undo_target( 'undo of #9002' ), Cases::undo_target( 'undo of #abc' ), Cases::undo_target( 'disease' ) ) );

fh_finish();
