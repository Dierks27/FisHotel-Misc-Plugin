<?php
/**
 * The Invoices line on the Today page — "Invoices: 2 published and not
 * paid for 7 days or more": the line itself (pure), the setting behind it
 * (unpaid_days, default 7), the filter with the section on, empty, off
 * (never hooked: only boot() in wp-admin hooks it) and for a user who may
 * not open the section.
 *
 * Run from the plugin root:
 *   php includes/sections/invoices/tests/today-line-test.php
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Today_Line;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/invoices/class-today-line.php';

$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 1 );
$GLOBALS['fh_current_user']                    = 1;

echo "The setting\n";
check( 'unpaid_days starts at 7', 7 === Settings::defaults()['unpaid_days'] && 7 === Settings::sanitize( array() )['settings']['unpaid_days'] );
check( 'a whole number from 1 to 90 is kept', 14 === Settings::sanitize( array( 'unpaid_days' => '14' ) )['settings']['unpaid_days'] && 1 === Settings::sanitize( array( 'unpaid_days' => 1 ) )['settings']['unpaid_days'] );
$fh_bad = Settings::sanitize( array( 'unpaid_days' => '0' ), array( 'unpaid_days' => 10 ) );
check( '0, 91 or words are refused with a reason, and the earlier value kept', 10 === $fh_bad['settings']['unpaid_days'] && 1 === count( $fh_bad['errors'] ) && 10 === Settings::sanitize( array( 'unpaid_days' => 'a week' ), array( 'unpaid_days' => 10 ) )['settings']['unpaid_days'] && 10 === Settings::sanitize( array( 'unpaid_days' => '91' ), array( 'unpaid_days' => 10 ) )['settings']['unpaid_days'] );
check( 'left blank: kept as it was', 10 === Settings::sanitize( array( 'unpaid_days' => '' ), array( 'unpaid_days' => 10 ) )['settings']['unpaid_days'] );

echo "\nThe line\n";
check( 'no invoice waiting: no line', null === Today_Line::line( array( 'count' => 0, 'days' => 7 ), '' ) && null === Today_Line::line( array(), '' ) );
$fh_line = Today_Line::line( array( 'count' => 2, 'days' => 7, 'oldest_days' => 12 ), 'https://example.test/x' );
check( '"Invoices: 2 published and not paid for 7 days or more", under Customers', 'Invoices: 2 published and not paid for 7 days or more' === $fh_line['text'] && 'customers' === $fh_line['group'] && 2 === $fh_line['count'] && 'invoices' === $fh_line['key'] );
check( '… with how long the oldest has waited', 'the oldest for 12 days' === $fh_line['detail'] && '' === Today_Line::line( array( 'count' => 1, 'days' => 7, 'oldest_days' => 7 ), '' )['detail'] );

echo "\nOff\n";
check( 'the section never booted in wp-admin: no line is hooked', array() === apply_filters( 'fishotel_today_lines', array() ) );

echo "\nOn\n";
Today_Line::register();
Today_Line::use_source( static function () {
	return array( 'count' => 0, 'days' => 7, 'oldest_days' => 0 );
} );
check( 'nothing unpaid that long: no line (empty)', array() === apply_filters( 'fishotel_today_lines', array() ) );
Today_Line::use_source( static function () {
	return array( 'count' => 3, 'days' => 10, 'oldest_days' => 20 );
} );
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'three waiting: the line joins the others', 2 === count( $fh_lines ) && 'Invoices: 3 published and not paid for 10 days or more' === $fh_lines[1]['text'] );
check( '… and leads to the published invoices', false !== strpos( $fh_lines[1]['url'], 'page=fishotel-misc-invoices' ) && false !== strpos( $fh_lines[1]['url'], 'state=published' ) );
$GLOBALS['fh_current_user'] = 2;
check( 'a user who may not open Invoices: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_current_user'] = 1;
Today_Line::use_source( static function () {
	throw new \RuntimeException( 'no table' );
} );
check( 'a source that fails adds nothing', array() === apply_filters( 'fishotel_today_lines', array() ) );

$fh_store = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/invoices/class-store.php' );
check( 'the count asks for published invoices only (a paid, draft or cancelled one is never "unpaid")', false !== strpos( $fh_store, "WHERE state = 'published' AND published_at IS NOT NULL AND published_at < %s" ) );
$fh_boot = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/invoices/class-invoices.php' );
check( 'boot() hooks it inside the wp-admin branch only', 1 === preg_match( '/if \( is_admin\(\) \) \{[^}]*Today_Line::register\(\);/s', $fh_boot ) );
check( 'the setting is on the Settings screen', false !== strpos( (string) file_get_contents( Invoices::path() . 'views/settings.php' ), 'settings[unpaid_days]' ) );

fh_finish();
