<?php
/**
 * The Foam Cuts line on the Today page — "Foam: today's cut plan is not
 * marked as cut yet": the line itself (pure), the one query behind it,
 * and the filter with the section on, empty, off (never hooked: only
 * boot() in wp-admin hooks it) and for a user who may not open the
 * section.
 *
 * Run from the plugin root:
 *   php includes/sections/foam-cuts/tests/today-line-test.php
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/foam-cuts/class-today-line.php';

use FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts;
use FisHotel\Misc\Sections\Foam_Cuts\Orders;
use FisHotel\Misc\Sections\Foam_Cuts\Today_Line;

$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 1 );
$GLOBALS['fh_current_user']                    = 1;

echo "1. The line\n";
check( 'no plan waiting: no line', null === Today_Line::line( array( 'plans' => array() ), 'u' ) && null === Today_Line::line( array(), 'u' ) );
$fh_one = Today_Line::line( array( 'plans' => array( array( 'id' => 12, 'title' => 'Invented plan — 3 boxes' ) ) ), 'https://example.test/wp-admin/admin.php?page=fishotel-misc-foam-cuts' );
check( 'one plan: "Foam: today\'s cut plan is not marked as cut yet", under Shipping', 'Foam: today\'s cut plan is not marked as cut yet' === $fh_one['text'] && 'shipping' === $fh_one['group'] && 1 === $fh_one['count'] && 'foam-cuts' === $fh_one['key'] );
check( '… its title beside it, and the link opens that plan', 'Invented plan — 3 boxes' === $fh_one['detail'] && 'https://example.test/wp-admin/admin.php?page=fishotel-misc-foam-cuts&plan=12' === $fh_one['url'] );
$fh_two = Today_Line::line( array( 'plans' => array( array( 'id' => 3, 'title' => 'a' ), array( 'id' => 4, 'title' => 'b' ) ) ), 'https://example.test/p' );
check( 'two: counted, and the link is the Plan tab itself', 'Foam: 2 cut plans for today are not marked as cut yet' === $fh_two['text'] && 2 === $fh_two['count'] && 'https://example.test/p' === $fh_two['url'] && '' === $fh_two['detail'] );

echo "2. Off\n";
check( 'the section never booted in wp-admin: no line is hooked', array() === apply_filters( 'fishotel_today_lines', array() ) );

echo "3. On\n";
Today_Line::register();
$GLOBALS['wpdb']->queries = array();
$fh_lines                 = apply_filters( 'fishotel_today_lines', array() );
check( 'nothing in the plans table: no line (empty)', array() === $fh_lines );
$fh_sql = implode( "\n", $GLOBALS['wpdb']->queries );
check( 'one query: the drafts of today\'s ship day', 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $fh_sql, "ship_date = '" . Orders::today() . "' AND status = 'draft'" ), $fh_sql );
apply_filters( 'fishotel_today_lines', array() );
check( '… asked once per request', 1 === count( $GLOBALS['wpdb']->queries ) );

Today_Line::use_source( static function () {
	return array( 'plans' => array( array( 'id' => 7, 'title' => 'Invented plan' ) ) );
} );
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'a draft for today: the line joins the others', 2 === count( $fh_lines ) && 'foam-cuts' === $fh_lines[1]['key'] && false !== strpos( $fh_lines[1]['url'], 'page=' . Foam_Cuts::MENU_SLUG ) && false !== strpos( $fh_lines[1]['url'], 'plan=7' ) );
$GLOBALS['fh_current_user'] = 2;
check( 'a user who may not open Foam Cuts: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_current_user'] = 1;
Today_Line::use_source( static function () {
	throw new \RuntimeException( 'no table' );
} );
check( 'a source that fails adds nothing', array() === apply_filters( 'fishotel_today_lines', array() ) );

fh_finish();
