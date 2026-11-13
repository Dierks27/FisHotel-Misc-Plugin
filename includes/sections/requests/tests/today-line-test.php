<?php
/**
 * The Requests line on the Today page — "Requests: 3 need you": the
 * line itself (pure), the filter with the section on (requests waiting
 * in an in-memory store), empty (nothing waiting), off (never hooked:
 * only boot() in wp-admin hooks it) and for a user who may not open the
 * section.
 *
 * Run from the plugin root:
 *   php includes/sections/requests/tests/today-line-test.php
 *
 * @package FisHotel\Misc\Sections\Requests
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';

use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Today_Line;

Requests::load();

$GLOBALS['fh_user_caps']    = array( 'manage_woocommerce' => array( 1 ) );
$GLOBALS['fh_current_user'] = 1;

$service = fh_service();
$store   = $service->store;
Requests::use_service( $service );

echo "The line\n";
check( 'nothing waiting: no line', null === Today_Line::line( array( 'approve' => array(), 'overdue' => array(), 'tell' => array(), 'owed' => array(), 'count' => 0 ), '' ) && null === Today_Line::line( array(), '' ) );
$fh_line = Today_Line::line( array( 'approve' => array( 1, 2 ), 'overdue' => array( 3 ), 'tell' => 0, 'owed' => 1 ), 'https://example.test/x' );
check( 'counted from the strip\'s lists: "Requests: 4 need you", under Customers', 'Requests: 4 need you' === $fh_line['text'] && 4 === $fh_line['count'] && 'customers' === $fh_line['group'] && 'requests' === $fh_line['key'] );
check( 'with what waits, in plain words', '2 to approve · 1 payment overdue · 1 owed fish to send' === $fh_line['detail'], $fh_line['detail'] );
check( 'an overdue payment colours it red; otherwise "todo"', 'bad' === $fh_line['tone'] && 'todo' === Today_Line::line( array( 'tell' => 1 ), '' )['tone'] );
check( 'one: "needs you"', 'Requests: 1 needs you' === Today_Line::line( array( 'tell' => 1 ), '' )['text'] && '1 person to tell' === Today_Line::line( array( 'tell' => 1 ), '' )['detail'] );

echo "\nOff\n";
check( 'the section never booted in wp-admin: no line is hooked', array() === apply_filters( 'fishotel_today_lines', array() ) );

echo "\nOn\n";
Today_Line::register();
check( 'nothing waiting in the store: no line (empty)', array() === apply_filters( 'fishotel_today_lines', array() ) );

$store->insert( array( 'fish' => 'Invented Tang', 'name' => 'Sam Example', 'email' => 'sam@example.test', 'state' => 'new' ) );
$store->insert( array( 'fish' => 'Invented Goby', 'name' => 'Lee Example', 'email' => 'lee@example.test', 'state' => 'ready' ) );
$store->insert( array( 'fish' => 'Invented Wrasse', 'name' => 'Kim Example', 'email' => 'kim@example.test', 'state' => 'waiting' ) );
Today_Line::use_source();
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'two requests need you (one to approve, one person to tell); a waiting one does not', 2 === count( $fh_lines ) && 'Requests: 2 need you' === $fh_lines[1]['text'] && '1 to approve · 1 person to tell' === $fh_lines[1]['detail'], fh_test_export( $fh_lines ) );
check( 'the other lines stay first; the link opens the Requests list', 'other' === $fh_lines[0]['key'] && false !== strpos( $fh_lines[1]['url'], 'page=fishotel-misc-requests' ) );
$store->insert( array( 'fish' => 'Invented Blenny', 'state' => 'new' ) );
check( 'worked out once per request: a row added afterwards changes nothing until the next request', 'Requests: 2 need you' === apply_filters( 'fishotel_today_lines', array() )[0]['text'] );
Today_Line::use_source();
check( '… and is there on the next one', 'Requests: 3 need you' === apply_filters( 'fishotel_today_lines', array() )[0]['text'] );
check( 'never twice: hooked twice, still one line', 1 === count( Today_Line::with_line( apply_filters( 'fishotel_today_lines', array() ), Today_Line::line( array( 'approve' => 1 ), '' ) ) ) );

$GLOBALS['fh_current_user'] = 7;
check( 'a user who may not open Requests: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_current_user'] = 1;
Today_Line::use_source( static function () {
	throw new \RuntimeException( 'the table is gone' );
} );
check( 'a source that fails adds nothing', array() === apply_filters( 'fishotel_today_lines', array() ) );

$fh_boot = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php' );
check( 'boot() hooks it inside the wp-admin branch only', 1 === preg_match( '/if \( is_admin\(\) \) \{[^}]*Today_Line::register\(\);/s', $fh_boot ) );

fh_finish();
