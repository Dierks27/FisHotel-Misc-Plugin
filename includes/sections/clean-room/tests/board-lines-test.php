<?php
/**
 * The Room Board's tank cards — Board_Lines, the ONE pure function a
 * tank's stays go through on their way to the screen: the order (by name,
 * natural and case-blind, an unknown fish last; then the standard sizes'
 * own order; then the check-in, an unknown one last), the gathering (the
 * same fish, size and state badge are one line: the counts summed, the
 * day a number, a range or "?"), the same fish in another size kept apart
 * but next to it, the foot's "Sale date not set" count, the quarantine
 * tank a cleared group came out of, the species filter on a gathered
 * line — and that the building's and the room's counts still count
 * stays, never lines. Then the two screens: the Board as it renders
 * (views/board.php over the stand-in store), the Stays tab's two filters
 * a card links with, and the phone's tank screen, which takes the order
 * and nothing else (every group keeps its own row).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/board-lines-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Board_Lines;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
foreach ( array( 'selected', 'checked', 'disabled' ) as $fh_word ) {
	if ( ! function_exists( $fh_word ) ) {
		eval( 'function ' . $fh_word . '( $a, $b = true, $echo = true ) { if ( (string) $a === (string) $b ) { echo " ' . $fh_word . '=\"' . $fh_word . '\""; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- three one-line template stubs.
	}
}

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events with the real clock.
$settings  = Settings::get();
$protocols = Settings::protocols();
$sizes     = array(
	array( 'slug' => '1-2', 'label' => '1-2"' ),
	array( 'slug' => '2-3', 'label' => '2-3"' ),
	array( 'slug' => '3-4', 'label' => '3-4"' ),
);

/**
 * A hydrated stay as the Board reads it, by hand.
 */
function fh_stay( $id, $species, array $more = array() ) {
	return array_merge(
		array(
			'id'              => $id,
			'species'         => $species,
			'product_id'      => null,
			'size_slug'       => null,
			'size_label'      => null,
			'needs_id'        => false,
			'qty_live'        => 1,
			'checkin_date'    => '2026-11-01',
			'checkin_unknown' => false,
			'day'             => 20,
			'status'          => 'cleared',
			'state_label'     => 'cleared',
			'sale_ready'      => false,
			'ready_in_spa'    => false,
			'sale_ready_date' => null,
		),
		$more
	);
}

function fh_size( $slug ) {
	return array(
		'size_slug'  => $slug,
		'size_label' => $slug . '"',
	);
}

function fh_ids( array $stays ) {
	return array_map( 'intval', array_column( $stays, 'id' ) );
}

/* ------------------------------------------------------------------ */
echo "1. The order\n";

$mixed = array(
	fh_stay( 1, 'test wrasse 10' ),
	fh_stay( 2, 'Test Wrasse 2' ),
	fh_stay( 3, '?? small yellow fish', array( 'needs_id' => true ) ),
	fh_stay( 4, 'Test Blenny' ),
	fh_stay( 5, 'test anthias' ),
	fh_stay( 6, 'Test Zebra Goby' ),
);
check_same( 'by name — case-blind, a number read as a number — and the unknown fish last', array( 5, 4, 2, 1, 6, 3 ), fh_ids( Board_Lines::sort( $mixed, $sizes ) ) );
check_same( 'a fish named with "??" goes last even without the flag', array( 4, 7 ), fh_ids( Board_Lines::sort( array( fh_stay( 7, '?? grey thing' ), fh_stay( 4, 'Test Blenny' ) ), $sizes ) ) );
check_same( 'two unknown fish: by what they look like', array( 8, 3 ), fh_ids( Board_Lines::sort( array( fh_stay( 3, '?? small yellow fish', array( 'needs_id' => true ) ), fh_stay( 8, '?? blue fish', array( 'needs_id' => true ) ) ), $sizes ) ) );

$sized = array(
	fh_stay( 11, 'Test Tang', fh_size( '3-4' ) ),
	fh_stay( 12, 'Test Tang' ),
	fh_stay( 13, 'Test Tang', fh_size( '1-2' ) ),
	fh_stay( 14, 'Test Tang', array( 'size_slug' => 'huge', 'size_label' => 'Huge' ) ),
	fh_stay( 15, 'Test Tang', fh_size( '2-3' ) ),
);
check_same( 'then by size: the standard sizes in their own order, a size that is not standard, no size', array( 13, 15, 11, 14, 12 ), fh_ids( Board_Lines::sort( $sized, $sizes ) ) );
check_same( '… the list\'s order, not the alphabet\'s', array( 11, 13 ), fh_ids( Board_Lines::sort( array( fh_stay( 13, 'Test Tang', fh_size( '1-2' ) ), fh_stay( 11, 'Test Tang', fh_size( '3-4' ) ) ), array_reverse( $sizes ) ) ) );
check_same( 'the name comes before the size', array( 16, 13 ), fh_ids( Board_Lines::sort( array( fh_stay( 13, 'Test Tang', fh_size( '1-2' ) ), fh_stay( 16, 'Test Anthias', fh_size( '3-4' ) ) ), $sizes ) ) );

$dated = array(
	fh_stay( 21, 'Test Tang', array( 'checkin_date' => '2026-11-05' ) ),
	fh_stay( 22, 'Test Tang', array( 'checkin_date' => '', 'checkin_unknown' => true, 'day' => null ) ),
	fh_stay( 23, 'Test Tang', array( 'checkin_date' => '2026-10-01' ) ),
	fh_stay( 20, 'Test Tang', array( 'checkin_date' => '2026-11-05' ) ),
);
check_same( 'then by check-in: oldest first, an unknown check-in last, the id between equals', array( 23, 20, 21, 22 ), fh_ids( Board_Lines::sort( $dated, $sizes ) ) );
check( 'nothing is added or dropped, and the stays come back whole', $mixed[2] === Board_Lines::sort( $mixed, $sizes )[5] && 6 === count( Board_Lines::sort( $mixed, $sizes ) ) && array() === Board_Lines::sort( array(), $sizes ) );
check_same( 'the same list in any order gives the same order', fh_ids( Board_Lines::sort( $mixed, $sizes ) ), fh_ids( Board_Lines::sort( array_reverse( $mixed ), $sizes ) ) );

/* ------------------------------------------------------------------ */
echo "2. The same fish, size and state: one line\n";

$tank = array(
	fh_stay( 31, 'Test Yellow Tang', array_merge( fh_size( '2-3' ), array( 'qty_live' => 1, 'day' => 20, 'checkin_date' => '2026-11-01' ) ) ),
	fh_stay( 32, 'test  yellow tang', array_merge( fh_size( '2-3' ), array( 'qty_live' => 1, 'day' => 15, 'checkin_date' => '2026-11-06' ) ) ),
	fh_stay( 33, 'Test Yellow Tang', array_merge( fh_size( '3-4' ), array( 'qty_live' => 1, 'day' => 20 ) ) ),
	fh_stay( 34, 'Test Anthias', array( 'qty_live' => 3, 'day' => 12 ) ),
	fh_stay( 35, 'Test Anthias', array( 'qty_live' => 2, 'day' => 12 ) ),
	fh_stay( 36, 'Test Blenny', array( 'qty_live' => 4, 'status' => 'in_qt', 'state_label' => 'in QT' ) ),
	fh_stay( 37, 'Test Blenny', array( 'qty_live' => 1 ) ),
	fh_stay( 38, '?? small yellow fish', array( 'needs_id' => true, 'qty_live' => 1, 'day' => null, 'checkin_date' => '', 'checkin_unknown' => true ) ),
);
$card = Board_Lines::card( $tank, $sizes );
$line = static function ( $name, $at = 0 ) use ( &$card ) {
	$found = array();
	foreach ( $card['lines'] as $row ) {
		if ( 0 === strcasecmp( $row['species'], $name ) ) {
			$found[] = $row;
		}
	}
	return isset( $found[ $at ] ) ? $found[ $at ] : null;
};
check_same( 'eight groups, six lines, in order', array( 'Test Anthias', 'Test Blenny', 'Test Blenny', 'Test Yellow Tang', 'Test Yellow Tang', '?? small yellow fish' ), array_column( $card['lines'], 'species' ) );
check( 'the two tangs of one size are one line, × 2, whatever tank they came out of and however the name was typed', 2 === $line( 'Test Yellow Tang' )['qty'] && array( 31, 32 ) === fh_ids( $line( 'Test Yellow Tang' )['stays'] ) && '2-3' === $line( 'Test Yellow Tang' )['size']['slug'] );
check_same( 'their day is a range', 'day 15–20', Board_Lines::day_text( $line( 'Test Yellow Tang' ) ) );
check( 'the tang of another size is a line of its own, right under it', '3-4' === $line( 'Test Yellow Tang', 1 )['size']['slug'] && 1 === $line( 'Test Yellow Tang', 1 )['qty'] && true === $line( 'Test Yellow Tang', 1 )['repeat'] && false === $line( 'Test Yellow Tang' )['repeat'] );
check( 'the counts are summed', 5 === $line( 'Test Anthias' )['qty'] && 2 === count( $line( 'Test Anthias' )['stays'] ) );
check_same( 'the same day is one number', 'day 12', Board_Lines::day_text( $line( 'Test Anthias' ) ) );
check( 'the same fish in another state is a line of its own, next to it', 'in_qt' === $line( 'Test Blenny' )['state'] && 4 === $line( 'Test Blenny' )['qty'] && 'cleared' === $line( 'Test Blenny', 1 )['state'] && true === $line( 'Test Blenny', 1 )['repeat'] );
check( 'a fish nobody knows keeps its line, marked', true === $line( '?? small yellow fish' )['unknown'] && false === $line( 'Test Anthias' )['unknown'] );
check_same( 'an unknown check-in stays "day ?" — no number is guessed', 'day ?', Board_Lines::day_text( $line( '?? small yellow fish' ) ) );
check_same( 'a known day beside an unknown one says both', 'day 9 / ?', Board_Lines::day_text( Board_Lines::card( array( fh_stay( 1, 'Test Goby', array( 'day' => 9 ) ), fh_stay( 2, 'Test Goby', array( 'day' => null, 'checkin_date' => '', 'checkin_unknown' => true ) ) ), $sizes )['lines'][0] ) );
check_same( 'a temporary tank\'s card says "QT day"', 'QT day 15–20', Board_Lines::day_text( $line( 'Test Yellow Tang' ), true ) );
check_same( 'no day and no unknown check-in: nothing', '', Board_Lines::day_text( array( 'day_min' => null, 'day_max' => null, 'day_unknown' => false ) ) );
check( 'a line counts as its stays: 8 on the card, every stay in exactly one line', 8 === $card['stays'] && 8 === array_sum( array_map( static function ( $row ) { return count( $row['stays'] ); }, $card['lines'] ) ) && array( 31, 32, 33, 34, 35, 36, 37, 38 ) === ( static function ( $ids ) { sort( $ids ); return $ids; } )( array_merge( ...array_map( static function ( $row ) { return fh_ids( $row['stays'] ); }, $card['lines'] ) ) ) );
check( 'the fish alive add up too', 14 === array_sum( array_column( $card['lines'], 'qty' ) ) );

$spa = Board_Lines::card(
	array(
		fh_stay( 41, 'Test Goby', array( 'status' => 'in_qt', 'state_label' => 'ready', 'sale_ready' => true, 'ready_in_spa' => true, 'sale_ready_date' => '2026-11-01' ) ),
		fh_stay( 42, 'Test Goby', array( 'status' => 'in_qt', 'state_label' => 'in QT' ) ),
		fh_stay( 43, 'Test Goby', array( 'status' => 'in_qt', 'state_label' => 'ready', 'sale_ready' => true, 'ready_in_spa' => true, 'sale_ready_date' => '2026-11-03' ) ),
	),
	$sizes
);
check( 'for sale and not for sale are two lines; the two for sale are one', 2 === count( $spa['lines'] ) && 'ready' === $spa['lines'][0]['state'] && true === $spa['lines'][0]['in_spa'] && array( 41, 43 ) === fh_ids( $spa['lines'][0]['stays'] ) && 'in_qt' === $spa['lines'][1]['state'] && array( 42 ) === fh_ids( $spa['lines'][1]['stays'] ) );
check( 'an unknown fish and a known one of the same name are not gathered', 2 === count( Board_Lines::card( array( fh_stay( 1, 'Test Goby' ), fh_stay( 2, 'Test Goby', array( 'needs_id' => true ) ) ), $sizes )['lines'] ) );
check( 'an empty tank: no line', array( 'lines' => array(), 'stays' => 0, 'no_sale' => 0 ) === Board_Lines::card( array(), $sizes ) );

/* ------------------------------------------------------------------ */
echo "3. \"Sale date not set\": once, in the card's foot\n";

$sale = Board_Lines::card(
	array(
		fh_stay( 51, 'Test Tang', array( 'sale_ready_date' => '2026-12-12' ) ),
		fh_stay( 52, 'Test Tang' ),
		fh_stay( 53, 'Test Anthias' ),
		fh_stay( 54, 'Test Anthias' ),
		fh_stay( 55, 'Test Wrasse', array( 'sale_ready_date' => '2026-12-19' ) ),
		fh_stay( 56, 'Test Wrasse', array( 'sale_ready_date' => '2026-12-12' ) ),
		fh_stay( 57, 'Test Blenny', array( 'state_label' => 'ready', 'sale_ready' => true, 'sale_ready_date' => '2026-11-01' ) ),
	),
	$sizes
);
check_same( 'the foot counts the stays without a date, gathered or not', 3, $sale['no_sale'] );
check_same( 'a line whose stays have none says nothing itself', '', Board_Lines::sale_text( $sale['lines'][0] ) );
check_same( 'a line that has a sale date keeps saying it', 'on sale 2026-12-12', Board_Lines::sale_text( $sale['lines'][2] ) );
check( '… and still counts its stay without one', 1 === $sale['lines'][2]['no_sale'] && 2 === $sale['lines'][0]['no_sale'] );
check_same( 'two dates in one line: the first and the last', 'on sale 2026-12-12 – 2026-12-19', Board_Lines::sale_text( $sale['lines'][3] ) );
check_same( 'a line for sale says so with its badge alone', '', Board_Lines::sale_text( $sale['lines'][1] ) );

/* ------------------------------------------------------------------ */
echo "4. The species filter on a gathered line\n";

check( 'a line is lit when one of its stays matches', true === Board_Lines::matches( $line( 'Test Yellow Tang' ), 'tang' ) && true === Board_Lines::matches( $line( 'Test Yellow Tang' ), 'YELLOW' ) && false === Board_Lines::matches( $line( 'Test Anthias' ), 'tang' ) );
check( '… by any of them, however each was typed', true === Board_Lines::matches( array( 'stays' => array( fh_stay( 1, 'Test Goby' ), fh_stay( 2, 'test goby (pair)' ) ) ), 'pair' ) );
check( 'no filter lights nothing', false === Board_Lines::matches( $line( 'Test Anthias' ), '' ) && false === Board_Lines::matches( $line( 'Test Anthias' ), '  ' ) );
check( 'the card\'s own test is unchanged: a stay of it matches', true === Admin::matches_species( $tank[1], 'yellow tang' ) );

/* ------------------------------------------------------------------ */
echo "5. The room: a tank of eight groups\n";

function fh_room_store() {
	$store                  = new Fake_Store( array( 12 => 'Test Yellow Tang' ) );
	$store->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms      = array(
		'pa_size' => array(
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
			array( 'term_id' => 34, 'name' => '3-4"', 'slug' => '3-4', 'menu_order' => 2 ),
		),
	);
	return $store;
}

function fh_act( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $settings, $today;
	return Front_Desk::perform(
		$act,
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $tank_number > 0 ? $store->tank_by_number( $tank_number ) : null,
			'base'     => array(
				'actor_id' => 8,
				'source'   => 'qr',
			),
		),
		$raw
	);
}

function fh_days_ago( $days ) {
	global $today;
	return gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - $days * 86400 );
}

/**
 * A group arrives in a Spa tank some days ago and (unless $to is 0) moves
 * to another tank today. Returns its stay id.
 */
function fh_group( Fake_Store $store, $spa, $to, array $fish, $days ) {
	$before = array_keys( $store->stays );
	$r      = fh_act( $store, $spa, 'arrival', array_merge( array( 'qty' => 1, 'date' => fh_days_ago( $days ) ), $fish ) );
	$id     = (int) current( array_diff( array_keys( $store->stays ), $before ) );
	if ( ! empty( $r['error'] ) || $id <= 0 ) {
		return 0;
	}
	if ( $to > 0 ) {
		fh_act( $store, $spa, 'move', array( 'stay_id' => $id, 'to_tank' => $to ) );
	}
	return $id;
}

$GLOBALS['fh_user_caps'][ Clean_Room::CAPABILITY ] = array( 8 );
$GLOBALS['fh_current_user']                        = 8;

$store = fh_room_store();
// In the order they were moved — which is how the card used to list them.
$g = array(
	'wrasse10' => fh_group( $store, 521, 121, array( 'species' => 'Test Wrasse 10' ), 30 ),
	'tang_a'   => fh_group( $store, 521, 121, array( 'product_id' => 12, 'size' => '2-3' ), 19 ),
	'unknown'  => fh_group( $store, 523, 121, array( 'species' => 'small yellow fish', 'unknown' => '1' ), 9 ),
	'anthias_a' => fh_group( $store, 523, 121, array( 'species' => 'Test Anthias', 'qty' => 3 ), 11 ),
	'tang_big' => fh_group( $store, 521, 121, array( 'product_id' => 12, 'size' => '3-4' ), 19 ),
	'wrasse2'  => fh_group( $store, 524, 121, array( 'species' => 'Test Wrasse 2' ), 25 ),
	'tang_b'   => fh_group( $store, 522, 121, array( 'product_id' => 12, 'size' => '2-3' ), 14 ),
	'anthias_b' => fh_group( $store, 524, 121, array( 'species' => 'test anthias', 'qty' => 2 ), 11 ),
);
// Elsewhere: one group still in the Spa, one in another clean tank.
$g['goby']   = fh_group( $store, 511, 0, array( 'species' => 'Test Goby', 'qty' => 6 ), 3 );
$g['blenny'] = fh_group( $store, 512, 221, array( 'species' => 'Test Blenny' ), 40 );
check( 'ten groups were set up', 10 === count( array_filter( $g ) ) && 10 === count( $store->stays ) );
// One tang has its own sale date, in a week; the wrasse is for sale today.
$in_a_week = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) + 7 * 86400 );
fh_act( $store, 121, 'ready', array( 'stay_id' => $g['tang_a'], 'date' => $in_a_week ) );
fh_act( $store, 121, 'ready', array( 'stay_id' => $g['wrasse2'], 'date' => $today ) );

$room   = $store->room( $today, $settings, $protocols );
$tanks  = $room['tanks'];
$by_no  = array();
foreach ( $tanks as $t ) {
	$by_no[ (int) $t['number'] ] = $t;
}
$std    = Sizes::standard( $store, $settings )['sizes'];
$before = $by_no[121]['occupants'];
$card   = Board_Lines::card( $before, $std );
$names  = array_column( $card['lines'], 'species' );
check_same( 'tank 121: eight groups in six lines, alphabetical, the unknown fish last', array( 'Test Anthias', 'Test Wrasse 2', 'Test Wrasse 10', 'Test Yellow Tang', 'Test Yellow Tang', '?? small yellow fish' ), $names );
check( 'the anthias from two Spa tanks: one line × 5', 5 === $card['lines'][0]['qty'] && 2 === count( $card['lines'][0]['stays'] ) && 'day 12' === Board_Lines::day_text( $card['lines'][0] ) );
check( 'the two tangs of one size from two Spa tanks: one line × 2, day 15–20; the larger one under it', 2 === $card['lines'][3]['qty'] && 'day 15–20' === Board_Lines::day_text( $card['lines'][3] ) && '2-3"' === $card['lines'][3]['size']['label'] && '3-4"' === $card['lines'][4]['size']['label'] && true === $card['lines'][4]['repeat'] );
check( 'the wrasse for sale wears READY; the rest are cleared', 'ready' === $card['lines'][1]['state'] && 'cleared' === $card['lines'][0]['state'] && 'cleared' === $card['lines'][3]['state'] );
check_same( 'the foot: six of the eight have no sale date', 6, $card['no_sale'] );
check_same( 'the tangs\' line keeps the one date it has', 'on sale ' . $in_a_week, Board_Lines::sale_text( $card['lines'][3] ) );
check( 'the card did not touch the tank\'s own list', $before === $by_no[121]['occupants'] );

$totals = Admin::totals( $tanks );
check( 'the room\'s summary still counts stays, not lines: 1 in QT, 8 cleared, 1 for sale, 18 fish, 8 without a sale date, 1 to identify', 1 === $totals['in_qt'] && 8 === $totals['cleared'] && 1 === $totals['ready'] && 18 === $totals['live'] && 8 === $totals['no_sale_date'] && 1 === $totals['needs_id'], json_encode( $totals ) );
$b100 = Admin::totals( array_values( array_filter( $tanks, static function ( $t ) { return 100 === (int) $t['building']['number']; } ) ) );
check( 'so does the building\'s header: Building 100 has 7 cleared and 1 for sale — eight groups, not six', 0 === $b100['in_qt'] && 7 === $b100['cleared'] && 1 === $b100['ready'], json_encode( $b100 ) );
$cards_no_sale = 0;
$cards_stays   = 0;
foreach ( $tanks as $t ) {
	$c              = Board_Lines::card( (array) $t['occupants'], $std );
	$cards_no_sale += $c['no_sale'];
	$cards_stays   += $c['stays'];
}
check( 'the cards\' feet add up to the summary\'s "without a sale date"', $totals['no_sale_date'] === $cards_no_sale && 10 === $cards_stays );

/* ------------------------------------------------------------------ */
echo "6. Where a cleared group came out of\n";

$origins = Admin::origins( $store, $tanks );
check( 'each group of tank 121 names its own Spa tank', 521 === $origins[ $g['tang_a'] ] && 522 === $origins[ $g['tang_b'] ] && 523 === $origins[ $g['anthias_a'] ] && 524 === $origins[ $g['anthias_b'] ] );
check( 'a group still in the Spa has none', ! isset( $origins[ $g['goby'] ] ) );
// A move on within the Clean Room keeps the Spa tank; a move back forgets it.
fh_act( $store, 221, 'move', array( 'stay_id' => $g['blenny'], 'to_tank' => 222 ) );
$tanks2 = $store->room( $today, $settings, $protocols )['tanks'];
check( 'a move from clean tank to clean tank keeps the quarantine tank', 512 === Admin::origins( $store, $tanks2 )[ $g['blenny'] ] );
$moves = array(
	array( 'id' => 1, 'stay_id' => 7, 'type' => 'move', 'qty' => null, 'from_tank_id' => 50, 'to_tank_id' => 10, 'from_tank_number' => 521, 'event_at' => '2026-11-01 10:00:00' ),
	array( 'id' => 2, 'stay_id' => 7, 'type' => 'move', 'qty' => null, 'from_tank_id' => 10, 'to_tank_id' => 51, 'from_tank_number' => 121, 'event_at' => '2026-11-02 10:00:00' ),
	array( 'id' => 3, 'stay_id' => 8, 'type' => 'move', 'qty' => 2, 'from_tank_id' => 50, 'to_tank_id' => 10, 'from_tank_number' => 521, 'event_at' => '2026-11-01 10:00:00' ),
	array( 'id' => 4, 'stay_id' => 9, 'type' => 'move', 'qty' => null, 'from_tank_id' => 90, 'to_tank_id' => 10, 'from_tank_number' => 901, 'from_area' => 'spa', 'event_at' => '2026-11-01 10:00:00' ),
	array( 'id' => 5, 'stay_id' => 6, 'type' => 'loss', 'qty' => 1, 'event_at' => '2026-11-01 10:00:00' ),
);
$areas = array( 10 => 'clean', 50 => 'spa', 51 => 'spa', 90 => 'temp' );
check_same( 'pure: back into quarantine forgets it; the fish a split leaves behind have not moved; a quarantine temporary tank counts', array( 9 => 901 ), Board_Lines::origins( $moves, $areas ) );
check_same( '… in the order the moves happened, however they are handed over', array( 7 => 521, 9 => 901 ), Board_Lines::origins( array( $moves[3], $moves[0] ), $areas ) );
check( 'a store that cannot say gives nothing', array() === Admin::origins( new stdClass(), $tanks ) && array() === Admin::origins( $store, array() ) );

/* ------------------------------------------------------------------ */
echo "7. The Board as it renders\n";

function fh_board_html( Fake_Store $store, array $request = array() ) {
	global $today, $settings, $protocols;
	$tab          = 'board';
	$can_settings = false;
	$tables_ok    = true;
	$labels_url   = 'https://example.test/labels';
	$filters      = Admin::filters_from_request( $request );
	$room         = $store->room( $today, $settings, $protocols );
	$tanks        = $room['tanks'];
	$todo         = is_array( $room['todo'] ) ? $room['todo'] : array( 'left' => 0, 'total' => 0 );
	$groups       = Layout::group( $store->buildings(), $tanks );
	$totals       = Admin::totals( $tanks );
	$doses        = Dose::board_doses( $store->latest_doses() );
	$sizes        = Sizes::standard( $store, $settings )['sizes'];
	$temp         = Admin_Temp::board( $store, $tanks, $protocols, $today );
	$origins      = Admin::origins( $store, $tanks );
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/board.php';
	return (string) ob_get_clean();
}

/**
 * One tank's card out of the Board's HTML, by its Front Desk link.
 */
function fh_card_html( $html, $number ) {
	$cards = preg_split( '/<div class="fh-cr-tank(?= |")/', $html );
	foreach ( $cards as $card ) {
		if ( false !== strpos( $card, esc_url( Clean_Room::front_desk_url( (int) $number ) ) ) ) {
			return $card;
		}
	}
	return '';
}

$html = fh_board_html( $store );
$c121 = fh_card_html( $html, 121 );
check( 'tank 121\'s card is there', '' !== $c121 );
check_same( 'it has six lines for its eight groups', 6, substr_count( $c121, '<li class="fh-cr-cardline' ) );
check_same( '… and every group is still listed inside its line', 8, preg_match_all( '/#fh-cr-stay-\d+"/', $c121 ) );
$order = array();
preg_match_all( '/class="fh-cr-cardline__name"[^>]*>([^<]*)</', $c121, $m );
check_same( 'the names read down the card in order', array( 'Test Anthias', 'Test Wrasse 2', 'Test Wrasse 10', 'Test Yellow Tang', 'Test Yellow Tang', '?? small yellow fish' ), $m[1] );
check( 'a gathered line shows the sum, the range and that it is two groups', false !== strpos( $c121, '&times;&nbsp;5' ) && false !== strpos( $c121, 'day 15–20' ) && 2 === substr_count( $c121, '2 groups' ) );
check_same( '"Sale date not set" is said once, in the foot, with the count', 1, substr_count( $c121, 'Sale date not set: 6' ) );
check( '… as a link to the Stays tab filtered to that tank\'s groups without one', false !== strpos( $c121, 'class="fh-cr-tank__nosale" href="' . esc_url( Admin::tank_stays_url( 121, true ) ) . '"' ) && false !== strpos( Admin::tank_stays_url( 121, true ), 'tab=stays' ) && false !== strpos( Admin::tank_stays_url( 121, true ), 'tank=121' ) && false !== strpos( Admin::tank_stays_url( 121, true ), 'no_sale=1' ) );
$rows = (string) preg_replace( '/<div class="fh-cr-cardline__more".*?<\/div>/s', '', $c121 );
check( '… and on no line: only the opened groups say it, each for itself', 1 === substr_count( $rows, 'Sale date not set' ) && 7 === substr_count( $c121, 'Sale date not set' ) );
check( 'the line that has a sale date keeps saying it', false !== strpos( $rows, '<span class="fh-cr-tank__sale">on sale ' . $in_a_week . '</span>' ) );
check( 'the badges are the Board\'s own', 1 === substr_count( $c121, 'fh-cr-badge--ready' ) && 5 === substr_count( $c121, 'fh-cr-badge--cleared' ) );
check( 'the unknown fish says "needs identifying" on its line', 1 === substr_count( $rows, 'needs identifying' ) && false !== strpos( $c121, 'fh-cr-cardline is-unknown' ) );
check( 'an opened line names the tank each group was cleared from, its own date and its row on the Stays tab', false !== strpos( $c121, 'cleared from 521' ) && false !== strpos( $c121, 'cleared from 522' ) && false !== strpos( $c121, 'on sale ' . $in_a_week . ' (own date)' ) && false !== strpos( $c121, esc_url( Admin::tank_stays_url( 121, false, $g['tang_b'] ) ) ) );
check( 'the opened part starts closed and its name is the button that opens it', 6 === substr_count( $c121, 'class="fh-cr-cardline__more" id="fh-cr-cardline-121-' ) && 6 === preg_match_all( '/<div class="fh-cr-cardline__more" id="[^"]+" hidden>/', $c121 ) && 6 === substr_count( $c121, 'aria-expanded="false" aria-controls="fh-cr-cardline-121-' ) );
check( 'the second tang line is marked as the same fish again', 1 === substr_count( $c121, 'fh-cr-cardline is-repeat' ) );
check( 'the Front Desk link and the to-do stay in the foot', false !== strpos( $c121, 'Front Desk' ) && false !== strpos( $c121, 'class="fh-cr-tank__foot"' ) );
check( 'a card with nothing missing says nothing in its foot', '' !== fh_card_html( fh_board_html( fh_room_store() ), 121 ) && false === strpos( fh_card_html( fh_board_html( fh_room_store() ), 121 ), 'Sale date not set' ) );
check( 'the summary chips keep their numbers', false !== strpos( $html, '<strong>1</strong> groups in QT' ) && false !== strpos( $html, '<strong>8</strong> cleared' ) && false !== strpos( $html, '<strong>1</strong> for sale' ) && false !== strpos( $html, '<strong>8</strong> without a sale date' ) && false !== strpos( $html, '<strong>18</strong> fish alive' ) );
check( 'the building header keeps its counts', 1 === preg_match( '/Building 100.*?0 in QT\s*· 1 for sale/s', $html ) );

$hit = fh_board_html( $store, array( 's' => 'yellow tang' ) );
$h121 = fh_card_html( $hit, 121 );
check( 'the species filter lights the gathered line and its sister, and no other', 2 === substr_count( $h121, 'fh-cr-cardline is-hit' ) && 1 === preg_match( '/fh-cr-cardline is-hit">\s*<button[^>]*>Test Yellow Tang</', $h121 ) );
check( '… keeps that card bright and dims the tanks without the fish', 0 !== strpos( $h121, ' is-dim' ) && 0 === strpos( fh_card_html( $hit, 222 ), ' is-dim' ) && 0 === strpos( fh_card_html( $hit, 511 ), ' is-dim' ) );
check( 'an empty tank is dimmed as before', 0 === strpos( fh_card_html( $html, 122 ), ' is-dim' ) && false !== strpos( fh_card_html( $html, 122 ), 'fh-cr-tank__empty' ) );
$area = fh_board_html( $store, array( 'area' => 'spa' ) );
check( 'the area filter still shows that area alone', '' === fh_card_html( $area, 121 ) && '' !== fh_card_html( $area, 511 ) );

// A temporary setup's card runs through the same lines.
fh_act( $store, 0, 'setup', array( 'slot' => 901, 'name' => 'Test Hospital', 'purpose' => 'watching', 'kind' => 'quarantine', 'gallons' => '10', 'template' => '' ) );
$t1 = fh_group( $store, 513, 901, array( 'species' => 'Test Dartfish', 'qty' => 2 ), 6 );
$t2 = fh_group( $store, 513, 901, array( 'species' => 'test dartfish', 'qty' => 1 ), 4 );
$t3 = fh_group( $store, 513, 901, array( 'species' => 'Test Basslet' ), 4 );
$temp_html = fh_board_html( $store );
$c901      = fh_card_html( $temp_html, 901 );
preg_match_all( '/class="fh-cr-cardline__name"[^>]*>([^<]*)</', $c901, $m );
check( 'the temporary tank\'s card: set up, with three groups', $t1 > 0 && $t2 > 0 && $t3 > 0 && '' !== $c901 && false !== strpos( $c901, 'Test Hospital' ) );
check_same( '… in two lines, in order', array( 'Test Basslet', 'Test Dartfish' ), $m[1] );
check( '… the gathered one × 3 with its fish\'s own days, and the foot\'s count', false !== strpos( $c901, '&times;&nbsp;3' ) && false !== strpos( $c901, 'QT day 5–7' ) && 1 === substr_count( $c901, 'Sale date not set: 3' ) && false !== strpos( $c901, 'Take down' ) );

/* ------------------------------------------------------------------ */
echo "8. The Stays tab filtered from a card\n";

$all = $store->open_stays( array(), $today, $settings );
check( 'the request: tank and no_sale', 121 === Admin::filters_from_request( array( 'tank' => '121', 'no_sale' => '1' ) )['tank'] && true === Admin::filters_from_request( array( 'tank' => '121', 'no_sale' => '1' ) )['no_sale'] && 0 === Admin::filters_from_request( array( 'tank' => array( '121' ), 'no_sale' => 'yes' ) )['tank'] && false === Admin::filters_from_request( array( 'no_sale' => 'yes' ) )['no_sale'] && 0 === Admin::filters_from_request( array( 'tank' => 'abc' ) )['tank'] );
check_same( 'one tank\'s groups', 8, count( Admin::narrow_stays( $all, array( 'tank' => 121 ) ) ) );
check_same( '… those of them without a sale date: what the card\'s foot counted', 6, count( Admin::narrow_stays( $all, array( 'tank' => 121, 'no_sale' => true ) ) ) );
check_same( 'the whole room without a sale date: what the summary counts', Admin::totals( $store->room( $today, $settings, $protocols )['tanks'] )['no_sale_date'], count( Admin::narrow_stays( $all, array( 'no_sale' => true ) ) ) );
check( 'neither filter: every stay, untouched', $all === Admin::narrow_stays( $all, array( 'tank' => 0, 'no_sale' => false ) ) && $all === Admin::narrow_stays( $all, array() ) );
check( 'a tank that holds nothing: none', array() === Admin::narrow_stays( $all, array( 'tank' => 122 ) ) );
check( 'a group\'s link lands on its row', '#fh-cr-stay-' . $g['tang_b'] === substr( Admin::tank_stays_url( 121, false, $g['tang_b'] ), -strlen( '#fh-cr-stay-' . $g['tang_b'] ) ) && false === strpos( Admin::tank_stays_url( 121 ), '#' ) && false === strpos( Admin::tank_stays_url( 121 ), 'no_sale' ) );
$stays_src = (string) file_get_contents( dirname( __DIR__ ) . '/views/stays.php' );
check( 'the Stays tab gives every row that id and offers the filter', false !== strpos( $stays_src, '<tr id="fh-cr-stay-<?php echo esc_attr( $fh_stay[\'id\'] ); ?>"' ) && false !== strpos( $stays_src, 'name="no_sale"' ) && false !== strpos( $stays_src, 'name="tank"' ) );

/* ------------------------------------------------------------------ */
echo "9. The phone: the same order, every group its own row\n";

$screen = Front_Desk::screen_from( $store, 121, $settings, $today, true );
$occ    = $screen['tank']['occupants'];
check_same( 'tank 121 on the phone lists its groups in the Board\'s order', array( 'Test Anthias', 'test anthias', 'Test Wrasse 2', 'Test Wrasse 10', 'Test Yellow Tang', 'Test Yellow Tang', 'Test Yellow Tang', '?? small yellow fish' ), array_column( $occ, 'species' ) );
check_same( '… the tangs by size, then by check-in', array( $g['tang_a'], $g['tang_b'], $g['tang_big'] ), array_slice( array_column( $occ, 'stayId' ), 4, 3 ) );
check( '… eight rows for eight groups, each with its own id and count — nothing gathered', 8 === count( $occ ) && 8 === count( array_unique( array_column( $occ, 'stayId' ) ) ) && array( 3, 2, 1, 1, 1, 1, 1, 1 ) === array_column( $occ, 'qtyLive' ) );
check_same( 'the order is the one function\'s', fh_ids( Board_Lines::sort( $by_no[121]['occupants'], $std ) ), array_column( $occ, 'stayId' ) );
check( 'the phone\'s script and styles are not part of this', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/js/front-desk.js' ), 'cardline' ) && false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/css/front-desk.css' ), 'cardline' ) );

fh_finish();
