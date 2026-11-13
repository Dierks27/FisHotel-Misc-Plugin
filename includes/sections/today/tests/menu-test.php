<?php
/**
 * Today's menu: the Today page, and Ship Day right under it — the Batch
 * Manager's own page, listed by its address (so nothing new is
 * registered), only when that page is there and the user may open it.
 *
 * Run from the plugin root:  php includes/sections/today/tests/menu-test.php
 *
 * @package FisHotel\Misc\Sections\Today
 */

require_once __DIR__ . '/wp-stubs.php';

$GLOBALS['fh_menu'] = array();
$GLOBALS['fh_can']  = true;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = null ) {
	$GLOBALS['fh_menu'][] = array( $parent, $menu_title, $capability, $slug, null !== $callback );
	return 'hook';
}
function current_user_can( $cap ) {
	return $GLOBALS['fh_can'];
}
class WooCommerce {}

require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-today.php';

use FisHotel\Misc\Sections\Today\Today;

echo "1. Without Batch Manager's Ship Day\n";
Today::register_menu();
check( 'Today only', array( 'Today' ) === array_column( $GLOBALS['fh_menu'], 1 ) );

echo "2. With it\n";
// The class Collector::has_ship_day() looks for (declared only now: a class
// written at the top level of a file would exist from the start).
eval( 'class FisHotel_Ship_Day { public static function build_destination() {} }' );
$GLOBALS['fh_menu'] = array();
Today::register_menu();
check( 'Today, then Ship Day right under it', array( 'Today', 'Ship Day' ) === array_column( $GLOBALS['fh_menu'], 1 ) );
$fh_ship = $GLOBALS['fh_menu'][1];
check( '… in FisHotel Tools, pointing at the Batch Manager page by its address, with no page of its own', 'fishotel-misc' === $fh_ship[0] && 'admin.php?page=fishotel-invoicing&tab=ship-day' === $fh_ship[3] && false === $fh_ship[4] && 'manage_options' === $fh_ship[2] );

echo "3. A user who cannot open it\n";
$GLOBALS['fh_can']  = false;
$GLOBALS['fh_menu'] = array();
Today::register_menu();
check( 'no Ship Day entry', ! in_array( 'Ship Day', array_column( $GLOBALS['fh_menu'], 1 ), true ) );

fh_finish();
