<?php
/**
 * The state follows the fish. First the pure plan on hand-made supplier
 * orders (the oldest request first, never more fish than the line orders,
 * the size when both have one, a line that was checked in, a tank group
 * that is on sale, fewer fish alive than asked for, an order that arrived
 * without the fish, a match made or undone by hand); then the whole run
 * against the Clean Room's own classes on its in-memory store: a real
 * supplier order, a real check-in, a real sale date — and the "ready"
 * e-mail going out by itself, or waiting for one click.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/follow-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Requests\Cleanroom;
use FisHotel\Misc\Sections\Requests\Cron;
use FisHotel\Misc\Sections\Requests\Follow;
use FisHotel\Misc\Sections\Requests\Listing;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Service;

/**
 * A request as the planner reads one.
 */
function fh_req( $id, $state, array $more = array() ) {
	return array_merge(
		array(
			'id'          => $id,
			'state'       => $state,
			'fish'        => 'Blue Test Wrasse',
			'species_id'  => null,
			'product_id'  => null,
			'size_slug'   => null,
			'qty'         => 1,
			'line_id'     => null,
			'stay_id'     => null,
			'link_manual' => 0,
			'auto_match'  => 1,
			'created_at'  => sprintf( '2026-01-%02d 10:00:00', $id ),
		),
		$more
	);
}

/**
 * A supplier order line as the planner reads one.
 */
function fh_line( $id, array $more = array() ) {
	return array_merge(
		array(
			'id'                 => $id,
			'species_id'         => null,
			'species'            => 'Blue Test Wrasse',
			'species_product_id' => null,
			'size_slug'          => '',
			'qty_ordered'        => 2,
			'needs_id'           => false,
			'stay_id'            => null,
			'stays'              => array(),
		),
		$more
	);
}

function fh_order( $id, $status, array $lines, $date = '2026-02-01' ) {
	return array(
		'id'     => $id,
		'ref'    => '2026-' . $id,
		'status' => $status,
		'date'   => $date,
		'lines'  => $lines,
	);
}

/**
 * The steps by request id.
 */
function fh_steps( array $requests, array $orders ) {
	$out = array();
	foreach ( Follow::plan( $requests, $orders ) as $step ) {
		$out[ $step['id'] ] = $step;
	}
	return $out;
}

/* ================================================================== */
echo "The same fish\n";
/* ================================================================== */

check( 'the same name, whatever the case', Follow::same_fish( array( 'fish' => 'blue test WRASSE' ), fh_line( 1 ) ) );
check( 'another name is another fish', ! Follow::same_fish( array( 'fish' => 'Red Test Goby' ), fh_line( 1 ) ) );
check( 'the same species-list fish under two names', Follow::same_fish( array( 'fish' => 'Azure Wrasse', 'species_id' => 7 ), fh_line( 1, array( 'species_id' => 7 ) ) ) );
check( 'two species-list fish with the same name are not the same', ! Follow::same_fish( array( 'fish' => 'Blue Test Wrasse', 'species_id' => 7 ), fh_line( 1, array( 'species_id' => 8 ) ) ) );
check( 'the same product', Follow::same_fish( array( 'fish' => 'Azure Wrasse', 'product_id' => 501 ), fh_line( 1, array( 'species_product_id' => 501 ) ) ) );
check( 'sizes that differ are not the same fish', ! Follow::same_fish( array( 'fish' => 'Blue Test Wrasse', 'size_slug' => '1-2' ), fh_line( 1, array( 'size_slug' => '2-3-5' ) ) ) );
check( 'a size on one side only does not matter', Follow::same_fish( array( 'fish' => 'Blue Test Wrasse', 'size_slug' => '1-2' ), fh_line( 1 ) ) && Follow::same_fish( array( 'fish' => 'Blue Test Wrasse' ), fh_line( 1, array( 'size_slug' => '2-3-5' ) ) ) );
check( 'a fish that still has to be identified never matches', ! Follow::same_fish( array( 'fish' => 'Blue Test Wrasse' ), fh_line( 1, array( 'needs_id' => true ) ) ) );
check( 'a name that only resembles is offered to the owner, never used by the job', Follow::similar_fish( array( 'fish' => 'Blue Test Wrasse (large)' ), fh_line( 1 ) ) && ! Follow::same_fish( array( 'fish' => 'Blue Test Wrasse (large)' ), fh_line( 1 ) ) );

/* ================================================================== */
echo "\nWaiting → on order\n";
/* ================================================================== */

$steps = fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'draft', array( fh_line( 11 ) ) ) ) );
check_same( 'a draft supplier order with a line of the fish: on order', array( 'on_order', 11, 1 ), array( $steps[1]['to'], $steps[1]['line_id'], $steps[1]['order_id'] ) );
check_contains( 'the log will name the order', '2026-1', $steps[1]['why'] );
check_same( 'an ordered one too', 'on_order', fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'ordered', array( fh_line( 11 ) ) ) ) )[1]['to'] );
check_same( 'a received order is not matched by the job', array(), fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'received', array( fh_line( 11 ) ) ) ) ) );
check_same( 'nor a closed one', array(), fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'closed', array( fh_line( 11 ) ) ) ) ) );
check_same( 'no order, no step', array(), fh_steps( array( fh_req( 1, 'waiting' ) ), array() ) );
check_same( 'a new or unpaid request is never matched', array(), fh_steps( array( fh_req( 1, 'new' ), fh_req( 2, 'awaiting_payment' ) ), array( fh_order( 1, 'draft', array( fh_line( 11 ) ) ) ) ) );

echo "\nThe quantity cap\n";
$steps = fh_steps( array( fh_req( 3, 'waiting' ), fh_req( 1, 'waiting' ), fh_req( 2, 'waiting' ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 2 ) ) ) ) ) );
check_same( 'two fish ordered, three requests: the two OLDEST are on order', array( 1, 2 ), array_keys( $steps ) );
check( 'the third stays waiting', ! isset( $steps[3] ) );

$steps = fh_steps( array( fh_req( 1, 'waiting', array( 'qty' => 2 ) ), fh_req( 2, 'waiting', array( 'qty' => 2 ) ), fh_req( 3, 'waiting', array( 'qty' => 1 ) ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 3 ) ) ) ) ) );
check_same( 'quantities count: 2 + 1 of 3 fish — the request for two more does not fit', array( 1, 3 ), array_keys( $steps ) );

$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ), fh_req( 2, 'waiting' ), fh_req( 3, 'waiting' ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 2 ) ) ) ) ) );
check_same( 'a line that already carries a request has only its remaining fish to give', array( 2 ), array_keys( $steps ) );

$steps = fh_steps( array( fh_req( 1, 'waiting' ), fh_req( 2, 'waiting' ), fh_req( 3, 'waiting' ) ), array( fh_order( 2, 'ordered', array( fh_line( 21, array( 'qty_ordered' => 1 ) ) ), '2026-03-01' ), fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 1 ) ) ), '2026-02-01' ) ) );
check_same( 'two orders carry the fish: the oldest request takes the oldest order', array( 11, 21 ), array( $steps[1]['line_id'], $steps[2]['line_id'] ) );
check( 'and the third waits', ! isset( $steps[3] ) );

echo "\nSize and switches\n";
$orders = array( fh_order( 1, 'draft', array( fh_line( 11, array( 'size_slug' => '1-2' ) ), fh_line( 12, array( 'size_slug' => '2-3-5' ) ) ) ) );
check_same( 'a request with a size goes to the line of that size', 12, fh_steps( array( fh_req( 1, 'waiting', array( 'size_slug' => '2-3-5' ) ) ), $orders )[1]['line_id'] );
check_same( 'a size nobody ordered: no match', array(), fh_steps( array( fh_req( 1, 'waiting', array( 'size_slug' => '4-5' ) ) ), $orders ) );
check_same( 'a request without a size takes the first line', 11, fh_steps( array( fh_req( 1, 'waiting' ) ), $orders )[1]['line_id'] );
check_same( 'a request the owner took off its order is left alone until matching is switched back on', array(), fh_steps( array( fh_req( 1, 'waiting', array( 'auto_match' => 0 ) ) ), $orders ) );
check_same( 'a line with no fish ordered matches nothing', array(), fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 0 ) ) ) ) ) ) );
check_same( 'an unknown fish ("??") matches nothing', array(), fh_steps( array( fh_req( 1, 'waiting' ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'needs_id' => true ) ) ) ) ) ) );

/* ================================================================== */
echo "\nOn order → in quarantine → ready\n";
/* ================================================================== */

$tank  = array( 'id' => 71, 'qty_live' => 2, 'sale_ready' => false, 'open' => true, 'product_id' => 501, 'tank' => 521 );
$line  = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( $tank ) ) );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ) ), array( fh_order( 1, 'received', array( $line ) ) ) );
check_same( 'the line was checked in to a tank: in quarantine, on that tank group', array( 'in_quarantine', 71 ), array( $steps[1]['to'], $steps[1]['stay_id'] ) );
check_same( 'the request learns the product of the tank group', 501, $steps[1]['product_id'] );

$ready = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'sale_ready' => true ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $ready ) ) ) );
check_same( 'the tank group\'s sale date has come: ready', 'ready', $steps[1]['to'] );
check_same( 'a request that is ready and stays ready needs no step', array(), fh_steps( array( fh_req( 1, 'ready', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $ready ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'ready', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $line ) ) ) );
check_same( 'the sale date was moved later: back in quarantine', 'in_quarantine', $steps[1]['to'] );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ) ), array( fh_order( 1, 'received', array( $ready ) ) ) );
check_same( 'checked in and already on sale: one step at a time — in quarantine first', 'in_quarantine', $steps[1]['to'] );
$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $ready ) ) ) );
check_same( 'and ready with the next plan', 'ready', $steps[1]['to'] );
$one   = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'sale_ready' => true, 'qty_live' => 1 ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ), fh_req( 2, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $one ) ) ) );
check( 'while the oldest request takes its first step, its fish on sale is not promised to a younger one', 'in_quarantine' === $steps[1]['to'] && ( ! isset( $steps[2] ) || 'ready' !== $steps[2]['to'] ) );

$split = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'qty_live' => 1 ) ), array( 'id' => 72, 'qty_live' => 1, 'sale_ready' => true, 'open' => true, 'product_id' => 501, 'tank' => 121 ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ), fh_req( 2, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $split ) ) ) );
check_same( 'one of two fish is on sale: the OLDEST request is ready, on the group that is', array( 'ready', 72 ), array( $steps[1]['to'], $steps[1]['stay_id'] ) );
check( 'the other stays in quarantine', ! isset( $steps[2] ) );

echo "\nThe fish is not there after all\n";
$one   = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'qty_live' => 1 ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ), fh_req( 2, 'on_order', array( 'line_id' => 11 ) ) ), array( fh_order( 1, 'received', array( $one ) ) ) );
check_same( 'two were ordered, one arrived alive: the oldest request keeps its place', 'in_quarantine', $steps[1]['to'] );
check_same( 'the other goes back to waiting', array( 'waiting', null ), array( $steps[2]['to'], $steps[2]['line_id'] ) );
check_contains( 'and its log says why', 'Fewer fish are alive', $steps[2]['why'] );

$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ), fh_req( 2, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71, 'link_manual' => 1 ) ) ), array( fh_order( 1, 'received', array( $one ) ) ) );
check_same( 'a request the owner matched by hand goes before an older one', array( 'waiting' ), array( $steps[1]['to'] ) );
check( 'and keeps the fish', ! isset( $steps[2] ) );

$gone  = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'qty_live' => 0, 'open' => false ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $gone ) ) ) );
check_same( 'the tank group is closed (sold or lost): back to waiting', 'waiting', $steps[1]['to'] );

$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ) ), array( fh_order( 1, 'received', array( fh_line( 11 ) ) ) ) );
check_same( 'the order was received without this line\'s fish: back to waiting', 'waiting', $steps[1]['to'] );
check_contains( 'the log says so', 'arrived without this fish', $steps[1]['why'] );

$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 99 ) ) ), array( fh_order( 1, 'draft', array( fh_line( 12, array( 'species' => 'Red Test Goby' ) ) ) ) ) );
check_same( 'its line was removed from the order: back to waiting', 'waiting', $steps[1]['to'] );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 99 ) ) ), array( fh_order( 1, 'draft', array( fh_line( 11 ) ) ) ) );
check_same( 'its line was replaced by a new one for the same fish: it follows to the new line', array( 'on_order', 11 ), array( $steps[1]['to'], $steps[1]['line_id'] ) );

$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ), fh_req( 2, 'on_order', array( 'line_id' => 11 ) ), fh_req( 3, 'on_order', array( 'line_id' => 11, 'link_manual' => 1 ) ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'qty_ordered' => 1 ) ) ) ) ) );
check_same( 'the line now orders one fish: the request matched by hand stays, both others wait again', array( 1, 2 ), array_keys( $steps ) );
check_same( 'state', array( 'waiting', 'waiting' ), array( $steps[1]['to'], $steps[2]['to'] ) );

echo "\nThe line was corrected to another fish\n";
$goby_line = fh_line( 11, array( 'species' => 'Red Test Goby', 'species_product_id' => 502, 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'sale_ready' => true ) ) ) ) );
$steps     = fh_steps(
	array(
		fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71, 'product_id' => 501 ) ),
		fh_req( 2, 'ready', array( 'line_id' => 11, 'stay_id' => 71 ) ),
		fh_req( 3, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71, 'link_manual' => 1 ) ),
		fh_req( 4, 'offered', array( 'line_id' => 11, 'stay_id' => 71 ) ),
	),
	array( fh_order( 1, 'received', array( $goby_line ) ) )
);
check_same( 'a request the job matched does not follow a line that now holds another fish: back to waiting', array( 'waiting', 'waiting' ), array( $steps[1]['to'] ?? '', $steps[2]['to'] ?? '' ) );
check_contains( 'and its history says what the line reads now', 'Red Test Goby', $steps[1]['why'] );
check_same( 'a match made by hand is the owner\'s: it goes on with the line', 'ready', $steps[3]['to'] ?? '' );
check( 'a request that was told is left alone', ! isset( $steps[4] ) );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11 ) ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'needs_id' => true ) ) ) ) ) );
check_same( 'a line whose fish now has to be identified is not the fish that was asked for either', 'waiting', $steps[1]['to'] ?? '' );
$steps = fh_steps( array( fh_req( 1, 'on_order', array( 'line_id' => 11, 'size_slug' => 'm' ) ) ), array( fh_order( 1, 'draft', array( fh_line( 11, array( 'size_slug' => 'l' ) ) ) ) ) );
check_same( 'nor is a line whose size was changed to another one', 'waiting', $steps[1]['to'] ?? '' );

echo "\nRequests that were already told\n";
$steps = fh_steps( array( fh_req( 1, 'offered', array( 'line_id' => 11, 'stay_id' => 71 ) ), fh_req( 2, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'qty_live' => 1, 'sale_ready' => true ) ) ) ) ) ) ) ) );
check( 'a request that was told is never taken back', ! isset( $steps[1] ) );
check_same( 'and its fish is not promised to the next one', 'waiting', $steps[2]['to'] );
check_same( 'a told request whose line is gone stays told', array(), fh_steps( array( fh_req( 1, 'offered', array( 'line_id' => 99 ) ) ), array() ) );

echo "\nNothing is changed on a guess\n";
$unread = fh_line( 11, array( 'stay_id' => 71, 'stays' => array() ) );
check_same( 'a checked-in line whose tank groups could not be read changes nothing', array(), fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ), fh_req( 2, 'ready', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $unread ) ) ) ) );
$moved = fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'id' => 73 ) ) ) ) );
$steps = fh_steps( array( fh_req( 1, 'in_quarantine', array( 'line_id' => 11, 'stay_id' => 71 ) ) ), array( fh_order( 1, 'received', array( $moved ) ) ) );
check( 'only the tank group changed: a quiet step, the same state', 'in_quarantine' === $steps[1]['to'] && true === $steps[1]['quiet'] && 73 === $steps[1]['stay_id'] );

/* ================================================================== */
echo "\nThe run without the Clean Room\n";
/* ================================================================== */

$service = fh_service();
$id      = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$run     = Follow::run( $service, null );
check_same( 'nothing follows, and it says the Clean Room is not there', array( false, 'waiting' ), array( $run['available'], $service->store->get( $id )['state'] ) );
check_contains( 'the last-check line says so too', 'Clean Room is off', Cron::summary_words( Cron::daily( $service, null ) ) );
check( 'the adapter is not made while the Clean Room is not loaded', null === Cleanroom::make() );

/* ================================================================== */
echo "\nOne run takes both steps\n";
/* ================================================================== */

/**
 * A stand-in for the Clean Room adapter: the supplier orders it is given.
 */
class Fh_Fake_Room {
	public $orders = array();
	public function snapshot( $requests ) {
		unset( $requests );
		return $this->orders;
	}
}

$service      = fh_service();
$fake         = new Fh_Fake_Room();
$id           = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$fake->orders = array( fh_order( 1, 'ordered', array( fh_line( 11 ) ) ) );
Follow::run( $service, $fake );
check_same( 'matched to the order', 'on_order', $service->store->get( $id )['state'] );
$fake->orders = array( fh_order( 1, 'received', array( fh_line( 11, array( 'stay_id' => 71, 'stays' => array( array_merge( $tank, array( 'sale_ready' => true ) ) ) ) ) ) ) );
$run          = Follow::run( $service, $fake );
check_same( 'checked in and already on sale: ONE run brings it to ready', 'ready', $service->store->get( $id )['state'] );
check_same( 'and it counts both steps', array( 1, 1 ), array( $run['in_quarantine'], $run['ready'] ) );
$notes = implode( ' | ', $service->store->notes( $id ) );
check( 'the history has the check-in AND the sale date', false !== strpos( $notes, 'in quarantine' ) && false !== strpos( $notes, 'the fish is ready' ) );
check_same( 'another run changes nothing', 0, array_sum( array_slice( Follow::run( $service, $fake ), 1 ) ) );

/* ================================================================== */
echo "\nThe run against the Clean Room's own classes\n";
/* ================================================================== */

$room = FISHOTEL_MISC_PATH . 'includes/sections/clean-room/';
if ( ! is_readable( $room . 'tests/fake-store.php' ) ) {
	echo "  (the Clean Room section is not in this tree — skipped)\n";
	fh_finish();
}

// The few more WordPress functions the Clean Room's classes touch.
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function sanitize_textarea_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( bin2hex( random_bytes( max( 1, (int) $length ) ) ), 0, max( 1, (int) $length ) );
}

foreach ( array( 'clean-room', 'layout', 'replay', 'protocol', 'parser', 'qr', 'costing', 'sizes', 'box-shares', 'stats', 'species', 'identify', 'orders', 'xlsx', 'workbook', 'import-map', 'import', 'store', 'settings' ) as $fh_class ) {
	require_once $room . 'class-' . $fh_class . '.php';
}
require_once $room . 'tests/fake-store.php';

use FisHotel\Misc\Sections\Clean_Room\Identify as Room_Identify;
use FisHotel\Misc\Sections\Clean_Room\Orders as Room_Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings as Room_Settings;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store as Room_Store;

$today         = '2026-03-02';
$room_settings = Room_Settings::get();
$room_store    = new Room_Store( array( 501 => 'Blue Test Wrasse', 502 => 'Red Test Goby' ) );
$adapter       = new Cleanroom( $room_store, $today, $room_settings );

$service = fh_service();
$store   = $service->store;
$GLOBALS['fh_known_fish'] = array();

$GLOBALS['fh_users'] = array( 7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example' ) );

$pat = $service->create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse' ), fh_staff() );
fh_days_pass( 1 );
$sam = $service->create( array( 'name' => 'Sam Sample', 'email' => 'sam@example.test', 'fish' => 'blue test wrasse' ), fh_staff() );
fh_days_pass( 1 );
$lee  = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Blue Test Wrasse' ), fh_staff() );
$goby = $service->create( array( 'name' => 'Lee Local', 'fish' => 'Red Test Goby' ), fh_staff() );
check( 'four requests are on the list', is_int( $pat ) && is_int( $sam ) && is_int( $lee ) && is_int( $goby ) );
fh_take_mails();

check_same( 'no supplier order yet: nothing moves', 0, Follow::run( $service, $adapter )['on_order'] );

$order_id = $room_store->save_order( Room_Orders::normalize_order( array( 'ref' => '2026-9', 'supplier' => 'Test Supplier One', 'status' => 'draft', 'order_date' => '2026-03-01' ) ) );
$room_store->save_order_lines(
	$order_id,
	Room_Orders::normalize_lines(
		array(
			array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 2 ),
			array( 'species' => 'Yellow Test Blenny', 'qty_ordered' => 5 ),
		)
	)
);
$lines     = $room_store->order_lines( $order_id );
$wrasse_id = (int) $lines[0]['id'];

$run = Follow::run( $service, $adapter );
check_same( 'a draft order with two wrasses: two requests are on order', array( true, 2 ), array( $run['available'], $run['on_order'] ) );
check_same( 'the two oldest', array( 'on_order', 'on_order', 'waiting' ), array( $store->get( $pat )['state'], $store->get( $sam )['state'], $store->get( $lee )['state'] ) );
check_same( 'matched to the line and its order', array( $wrasse_id, $order_id ), array( $store->get( $pat )['line_id'], $store->get( $pat )['cr_order_id'] ) );
check_same( 'the goby, which nobody ordered, waits', 'waiting', $store->get( $goby )['state'] );
check_contains( 'the log names the order', '2026-9', implode( ' | ', $store->notes( $pat ) ) );
check_same( 'a second run changes nothing', 0, array_sum( array_slice( Follow::run( $service, $adapter ), 1 ) ) );
check_same( 'nobody is e-mailed for being on order', array(), fh_take_mails() );

$requested = $store->find( array( 'cr_order_id' => $order_id, 'state' => Rules::LINKED_STATES ) );
check_same( '"Requested by" on the supplier order reads two requests for that line', array( $wrasse_id, $wrasse_id ), array_column( $requested, 'line_id' ) );

echo "\nBy hand: link and unlink\n";
$candidates = $adapter->candidates( array( $store->get( $lee ), $store->get( $goby ) ) );
check_same( 'the wrasse line is offered for the third request', $wrasse_id, $candidates[ $lee ][0]['id'] );
check( 'nothing is offered for a fish no order carries', ! isset( $candidates[ $goby ] ) );
$line = $adapter->line( $order_id, $wrasse_id );
check_same( 'the line as the link reads it', array( $wrasse_id, $order_id, '2026-9', 'Blue Test Wrasse' ), array_values( $line ) );
check( 'a line of another order is not found', null === $adapter->line( $order_id, 99999 ) );

check( 'the owner matches the third request by hand, past the two fish ordered', $service->link( $lee, $line, true, fh_staff() )['ok'] );
Follow::run( $service, $adapter );
check_same( 'the hand match stays; the youngest request matched by itself gives way', array( 'on_order', 'on_order', 'waiting' ), array( $store->get( $lee )['state'], $store->get( $pat )['state'], $store->get( $sam )['state'] ) );
check( 'the owner takes it off again', $service->unlink( $lee, true, fh_staff() )['ok'] );
Follow::run( $service, $adapter );
check_same( 'taken off by hand it is left alone; the freed fish goes to the next request', array( 'waiting', 'on_order' ), array( $store->get( $lee )['state'], $store->get( $sam )['state'] ) );
check_same( 'matching by itself is off for it', 0, $store->get( $lee )['auto_match'] );
$service->set_auto_match( $lee, true, fh_staff() );
check_same( 'switched back on, it waits for room like everybody else', 0, array_sum( array_slice( Follow::run( $service, $adapter ), 1 ) ) );

echo "\nCheck-in\n";
$room_store->update_order_fields( $order_id, array( 'status' => 'ordered', 'received_date' => $today ) );
$lines                    = $room_store->order_lines( $order_id );
$lines[0]['qty_received'] = 2;
$room_store->save_order_lines( $order_id, $lines );
$checked = Room_Orders::check_in( $room_store, $room_store->order( $order_id ), $today, $room_settings, array( 'actor_id' => 1 ) );
check_same( 'the Clean Room checked the wrasses in: one tank group', 1, count( $checked['created'] ) );
$stay_id = (int) $checked['created'][0]['stay_id'];

$run = Follow::run( $service, $adapter );
check_same( 'both requests are in quarantine', array( 2, 'in_quarantine', 'in_quarantine' ), array( $run['in_quarantine'], $store->get( $pat )['state'], $store->get( $sam )['state'] ) );
check_same( 'on that tank group', $stay_id, $store->get( $pat )['stay_id'] );
check_contains( 'the log says checked in', 'Checked in from supplier order 2026-9', implode( ' | ', $store->notes( $pat ) ) );
check_same( 'the request waiting for room still waits (the order is received now)', 'waiting', $store->get( $lee )['state'] );
check_same( 'nobody is e-mailed for a fish in quarantine', array(), fh_take_mails() );

echo "\nA loss in quarantine\n";
$room_store->record_event( $stay_id, array( 'type' => 'loss', 'qty' => 1, 'note' => 'disease', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $room_settings );
$run = Follow::run( $service, $adapter );
check_same( 'one wrasse died: the younger request goes back to waiting', array( 'in_quarantine', 'waiting' ), array( $store->get( $pat )['state'], $store->get( $sam )['state'] ) );
check_contains( 'and its history says why', 'Fewer fish are alive', implode( ' | ', $store->notes( $sam ) ) );

echo "\nThe sale date\n";
$room_store->update_order_fields( $order_id, array( 'sale_date' => '2026-03-20' ) );
check_same( 'a sale date in the future: still in quarantine', 'in_quarantine', $store->get( $pat )['state'] === 'in_quarantine' && 0 === Follow::run( $service, $adapter )['ready'] ? 'in_quarantine' : 'moved' );

$later   = new Cleanroom( $room_store, '2026-03-20', $room_settings );
$service = new Service( $store, array_merge( $service->settings, array( 'auto_offer' => 0 ) ), $service->mailer, array(
	'now'         => static function () {
		return $GLOBALS['fh_clock'];
	},
	'product_url' => static function ( $request ) {
		return ! empty( $request['product_id'] ) ? 'https://example.test/product/' . (int) $request['product_id'] . '/' : '';
	},
) );
$summary = Cron::daily( $service, $later );
check_same( 'on the sale date the request is ready', array( 1, 'ready' ), array( $summary['follow']['ready'], $store->get( $pat )['state'] ) );
check_same( '"Tell people by themselves" is OFF: nobody is told', array( 0, array() ), array( $summary['told'], fh_take_mails() ) );
$needs = Listing::needs_you( $store->find( array( 'state' => Rules::OPEN_STATES ) ), $GLOBALS['fh_clock'], $service->settings );
check_same( 'the request waits under "Needs you" for one click', array( $pat ), array_column( $needs['tell'], 'id' ) );
check( 'one click tells them', $service->offer( $pat, fh_staff() )['ok'] && 'offered' === $store->get( $pat )['state'] );
$mails = fh_take_mails();
check_same( 'one e-mail', array( 1, 'pat@example.test' ), array( count( $mails ), $mails[0]['to'] ) );
check_contains( 'that the fish is ready', 'Blue Test Wrasse', $mails[0]['alt'] );

echo "\nTold by themselves\n";
$store->update( $pat, array( 'state' => 'ready', 'offered_at' => null ) );
$service->settings['auto_offer'] = 1;
$summary                         = Cron::daily( $service, $later );
check_same( '"Tell people by themselves" is ON: the daily job tells them', array( 1, 'offered' ), array( $summary['told'], $store->get( $pat )['state'] ) );
check_same( 'with one e-mail', 1, count( fh_take_mails() ) );
$summary = Cron::daily( $service, $later );
check_same( 'the next day nobody is told twice', array( 0, array() ), array( $summary['told'], fh_take_mails() ) );
check_contains( 'the last-check line', 'person told', Cron::summary_words( array_merge( $summary, array( 'told' => 1 ) ) ) );

$store->update( $lee, array( 'state' => 'ready', 'line_id' => $wrasse_id, 'cr_order_id' => $order_id, 'stay_id' => $stay_id, 'link_manual' => 1 ) );
$store->update( $pat, array( 'state' => 'filled' ) );
$summary = Cron::daily( $service, $later );
check_same( 'somebody without an e-mail address cannot be told by the job: they wait under "Needs you"', array( 0, 'ready' ), array( $summary['told'], $store->get( $lee )['state'] ) );
check_same( 'and the log is not filled with attempts', 0, count( array_keys( $store->actions( $lee ), 'offered' ) ) );

echo "\nBought after the offer\n";
$store->update( $pat, array( 'state' => 'offered', 'offered_at' => Rules::utc( $GLOBALS['fh_clock'] ), 'product_id' => 501 ) );
$filled = Follow::bought( $service, array( 'id' => 801, 'user_id' => 7, 'email' => 'pat@example.test', 'created' => $GLOBALS['fh_clock'] - 3600, 'product_ids' => array( 501 ) ) );
check_same( 'an order placed BEFORE the offer fills nothing', array( array(), 'offered' ), array( $filled, $store->get( $pat )['state'] ) );
$filled = Follow::bought( $service, array( 'id' => 802, 'user_id' => 7, 'email' => 'pat@example.test', 'created' => $GLOBALS['fh_clock'] + 3600, 'product_ids' => array( 502 ) ) );
check_same( 'another product fills nothing', array(), $filled );
$filled = Follow::bought( $service, array( 'id' => 803, 'user_id' => 9, 'email' => 'other@example.test', 'created' => $GLOBALS['fh_clock'] + 3600, 'product_ids' => array( 501 ) ) );
check_same( 'another customer buying it fills nothing', array(), $filled );
$filled = Follow::bought( $service, array( 'id' => 804, 'user_id' => 7, 'email' => 'pat@example.test', 'created' => $GLOBALS['fh_clock'] + 3600, 'product_ids' => array( 77, 501 ) ) );
check_same( 'that customer buying that product after the offer fills the request', array( array( $pat ), 'filled' ), array( $filled, $store->get( $pat )['state'] ) );
check_contains( 'the log names the order', 'order #804', implode( ' | ', $store->notes( $pat ) ) );
$guest = $service->create( array( 'name' => 'Noah', 'email' => 'noah@example.test', 'fish' => 'Blue Test Wrasse', 'product_id' => 501 ), fh_staff() );
$store->update( $guest, array( 'state' => 'offered', 'offered_at' => Rules::utc( $GLOBALS['fh_clock'] ) ) );
$filled = Follow::bought( $service, array( 'id' => 805, 'user_id' => 0, 'email' => 'NOAH@example.test', 'created' => $GLOBALS['fh_clock'] + 60, 'product_ids' => array( 501 ) ) );
check_same( 'somebody without an account is found by the order\'s e-mail address', array( $guest ), $filled );
$owed = $service->create( array( 'name' => 'Pat Example', 'email' => 'pat@example.test', 'fish' => 'Blue Test Wrasse', 'product_id' => 501, 'source' => 'lost_fish' ) );
check( 'an owed request for the same customer and product', is_int( $owed ) );
$store->update( $owed, array( 'user_id' => 7, 'state' => 'offered', 'offered_at' => Rules::utc( $GLOBALS['fh_clock'] ) ) );
check_same( 'an owed fish is never filled by a purchase (it was paid for long ago)', array(), Follow::bought( $service, array( 'id' => 806, 'user_id' => 7, 'email' => 'pat@example.test', 'created' => $GLOBALS['fh_clock'] + 60, 'product_ids' => array( 501 ) ) ) );

echo "\nThe owner corrects the fish of a line after check-in\n";
$kim   = $service->create( array( 'name' => 'Kim Keeper', 'email' => 'kim@example.test', 'fish' => 'Green Test Tang' ), fh_staff() );
$wrong = $room_store->save_order( Room_Orders::normalize_order( array( 'ref' => '2026-77', 'supplier' => 'Test Supplier One', 'status' => 'ordered', 'order_date' => '2026-03-01' ) ) );
$room_store->save_order_lines( $wrong, Room_Orders::normalize_lines( array( array( 'species' => 'Green Test Tang', 'qty_ordered' => 1 ) ) ) );
Follow::run( $service, $later );
check_same( 'matched by itself', 'on_order', $store->get( $kim )['state'] );
$wrong_lines                    = $room_store->order_lines( $wrong );
$wrong_lines[0]['qty_received'] = 1;
$room_store->save_order_lines( $wrong, $wrong_lines );
Room_Orders::check_in( $room_store, $room_store->order( $wrong ), $today, $room_settings, array( 'actor_id' => 1 ) );
$wrong_line = $room_store->order_lines( $wrong )[0];
Room_Identify::line( $room_store, $room_store->order( $wrong ), $wrong_line, Room_Identify::fish_from( $room_store, array( 'species' => 'Red Test Goby', 'product_id' => 502 ), $room_settings, $wrong_line ), array( 'today' => $today, 'settings' => $room_settings, 'actor_id' => 1, 'source' => 'test' ) );
check_same( 'the Clean Room\'s own "Correct the fish…" made the line a goby', 'Red Test Goby', $room_store->order_lines( $wrong )[0]['species'] );
$room_store->update_order_fields( $wrong, array( 'sale_date' => '2026-03-20' ) );
fh_take_mails();
Cron::daily( $service, $later );
check_same( 'the tang request does not follow the goby: it waits again', array( 'waiting', null ), array( $store->get( $kim )['state'], $store->get( $kim )['line_id'] ) );
check_same( 'and nobody is told a goby is their tang', 0, count( fh_take_mails() ) );
check_contains( 'the history says why', 'Red Test Goby', implode( ' | ', $store->notes( $kim ) ) );

echo "\nPast demand from the Clean Room's order lines\n";
$old = $room_store->save_order( Room_Orders::normalize_order( array( 'ref' => '2025-4', 'supplier' => 'Test Supplier Two', 'status' => 'closed', 'received_date' => '2025-06-10' ) ) );
$room_store->save_order_lines( $old, Room_Orders::normalize_lines( array( array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 4 ), array( 'species' => 'Red Test Goby', 'qty_ordered' => 3 ), array( 'species' => 'small wrasse', 'needs_id' => 1, 'qty_ordered' => 9 ) ) ) );
$ordered = $adapter->ordered_by_year();
check_same( 'the years with orders, newest first', array( 2026, 2025 ), $ordered['years'] );
check_same( 'wrasses ordered per year', array( 2026 => 2, 2025 => 4 ), $ordered['rows']['t:blue test wrasse']['years'] );
check_same( 'and in all', 6, $ordered['rows']['t:blue test wrasse']['total'] );
check( 'a fish that still has to be identified is not demand', 3 === count( $ordered['rows'] ) );
$table = Listing::demand( $store->demand(), $ordered );
$first = $table['rows'][0];
check_same( 'the Demand table puts the requests beside what was ordered', array( 'Blue Test Wrasse', array( 2026 => 2, 2025 => 4 ) ), array( $first['fish'], $first['ordered'] ) );
check( 'a fish that was ordered but never requested here is listed as past demand', in_array( 'Yellow Test Blenny', array_column( $table['rows'], 'fish' ), true ) );

echo "\nNames and sizes from the Clean Room\n";
check_contains( 'names are suggested from the fish products', 'Blue Test Wrasse', implode( ' | ', array_column( $adapter->suggest( 'wras', 8 ), 'name' ) ) );
check_same( 'a visitor is not shown a product that is not public', array(), $adapter->suggest( 'wras', 8, static function ( $product_id ) { return false; } ) );
check_same( 'a typed name that IS a product is matched to it', 501, $adapter->match_name( 'blue test wrasse' )['product_id'] );
check_same( 'a name that only resembles one is not', null, $adapter->match_name( 'blue wrasse' )['product_id'] );
check( 'no standard size is set up in this store: the size is free text (null), not an empty dropdown', null === $adapter->sizes() );
$sized = new Cleanroom( $room_store, $today, array_merge( $room_settings, array( 'size_list' => array( '1 - 2"', '2 - 3.5"' ) ) ) );
check_same( 'the standard sizes come as slug and label', array( array( 'slug' => '1-2', 'label' => '1 - 2"' ), array( 'slug' => '2-3-5', 'label' => '2 - 3.5"' ) ), $sized->sizes() );

fh_finish();
