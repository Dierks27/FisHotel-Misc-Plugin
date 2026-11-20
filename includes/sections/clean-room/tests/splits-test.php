<?php
/**
 * Splits — an order line laid out over several tanks at its check-in, on
 * the in-memory store: the plan and its rules (a count and a tank per
 * row, only tanks that take arrivals, no tank twice, never more than the
 * live fish), the tanks a part may go into (the empty ones first), "1 per
 * tank", what a save keeps and refuses, the check-in's events ONE BY ONE
 * (the arrival, the DOA loss, the split-moves, the sister stays' link to
 * the line), the line's live / survived / sold after losses in two of the
 * tanks, the Undo of one split, the second click, a split posted for a
 * line that is already checked in, what is remembered per fish, bag mode,
 * a tank that went away between the save and the check-in, and the
 * bounds of what is stored.
 *
 * Every supplier, fish and number here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/splits-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Splits;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

update_option( Clean_Room::OPTION_KEY, array( 'size_list' => array( 'Small', 'Medium', 'Large' ) ) );
$today    = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp their events with the real clock (the Undo window).
$settings = Settings::get();
$actor    = 1; // The one who clicks Check in — and who may undo it.

function fh_store() {
	$store = new Fake_Store( array( 12 => 'Copper Test Butterfly', 13 => 'Lilac Test Anthias', 14 => 'Plain Test Damsel' ) );
	$store->save_species( Species::normalize( array( 'name' => 'Copper Test Butterfly', 'per_box' => 6, 'my_price' => 60, 'product_id' => 12 ) ) ); // 1
	$store->save_species( Species::normalize( array( 'name' => 'Lilac Test Anthias', 'per_box' => 24, 'my_price' => 30, 'product_id' => 13 ) ) );  // 2
	return $store;
}

function tank_id( Fake_Store $store, $number ) {
	return (int) $store->tank_by_number( $number )['id'];
}

/** A plan as the page posts it: [[qty, tank number], …] → the JSON of [[qty, tank id], …]. */
function plan( Fake_Store $store, array $rows ) {
	$out = array();
	foreach ( $rows as $row ) {
		$out[] = array( (int) $row[0], tank_id( $store, $row[1] ) );
	}
	return (string) json_encode( $out );
}

function row( $species, array $extra = array() ) {
	return array_merge(
		array(
			'id'           => '0',
			'species'      => $species,
			'qty_ordered'  => '6',
			'qty_received' => '6',
			'qty_doa'      => '0',
			'unit_price'   => '10',
		),
		$extra
	);
}

function save( Fake_Store $store, array $lines, $id = 0, array $header = array() ) {
	global $today;
	return Admin_Workbook::save_order(
		$store,
		array_merge(
			array(
				'ref'           => $id > 0 ? $store->order( $id )['ref'] : '',
				'supplier'      => 'Example Aquatics',
				'origin'        => 'Northland',
				'status'        => 'ordered',
				'cost_mode'     => 'box',
				'received_date' => $today,
				'building_id'   => '5', // The Spa building 500.
				'lines'         => $lines,
			),
			$header
		),
		$id,
		$today
	);
}

function check_in( Fake_Store $store, $order_id, array $base = array() ) {
	global $today, $settings, $actor;
	return Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array_merge( array( 'actor_id' => $actor ), $base ) );
}

function act( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $today, $settings, $actor;
	return Front_Desk::perform(
		$act,
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $store->tank_by_number( $tank_number ),
			'base'     => array(
				'actor_id' => $actor,
				'source'   => 'qr',
			),
		),
		$raw
	);
}

function resolved( Fake_Store $store, $order_id ) {
	return Admin_Workbook::resolve_order( $store, $store->order( $order_id ) );
}

/** type / qty / note / from → to of a stay's events, in the order they happened. */
function events_of( Fake_Store $store, $stay_id ) {
	$out = array();
	foreach ( $store->events( $stay_id ) as $event ) {
		$out[] = $event['type'] . ' ' . ( null === $event['qty'] ? '-' : (int) $event['qty'] ) . ' [' . $event['note'] . '] ' . ( ! empty( $event['from_tank_number'] ) ? (int) $event['from_tank_number'] : '-' ) . '>' . ( ! empty( $event['to_tank_number'] ) ? (int) $event['to_tank_number'] : '-' );
	}
	return $out;
}

echo "1. A plan as it is posted\n";
check( 'JSON rows of [count, tank id]', array( array( 'qty' => 2, 'tank_id' => 14 ), array( 'qty' => 1, 'tank_id' => 15 ) ) === Splits::parts_in( '[[2,14],[1,15]]' ) );
check( '… or of {qty, tank_id}, numbers or digit strings', array( array( 'qty' => 2, 'tank_id' => 14 ) ) === Splits::parts_in( array( array( 'qty' => '2', 'tank_id' => '14' ) ) ) );
check( 'nothing said: an empty field, a missing one, an empty list, junk', null === Splits::parts_in( '' ) && null === Splits::parts_in( null ) && null === Splits::parts_in( '[]' ) && null === Splits::parts_in( 'two in tank 4' ) && null === Splits::parts_in( '{"a":1}' ) && null === Splits::parts_in( 7 ) );
check( '"keep together" is kept as said', Splits::NONE === Splits::parts_in( 'none' ) && Splits::NONE === Splits::parts_in( ' NONE ' ) );
check( 'a row with neither a count nor a tank is dropped; one with only one of them is kept for errors() to name', array( array( 'qty' => 3, 'tank_id' => 0 ), array( 'qty' => 0, 'tank_id' => 15 ) ) === Splits::parts_in( '[[3,""],["",""],[0,15],[null,null]]' ) );
check( 'a formula, a negative, a decimal or a huge number is no count', array( array( 'qty' => 0, 'tank_id' => 14 ) ) === Splits::parts_in( '[["=1+1",14]]' ) && array( array( 'qty' => 0, 'tank_id' => 14 ) ) === Splits::parts_in( '[[-2,14]]' ) && array( array( 'qty' => 0, 'tank_id' => 14 ) ) === Splits::parts_in( '[[1.5,14]]' ) && array( array( 'qty' => 0, 'tank_id' => 14 ) ) === Splits::parts_in( '[[12345678,14]]' ) );
$many = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$many[] = array( 1, $i );
}
check( 'at most ' . Splits::MAX_PARTS . ' parts', Splits::MAX_PARTS === count( Splits::parts_in( $many ) ) );
check( 'placed / is_split / is_ones', 3 === Splits::placed( Splits::parts_in( '[[2,14],[1,15]]' ) ) && true === Splits::is_split( Splits::parts_in( '[[2,14],[1,15]]' ) ) && false === Splits::is_ones( Splits::parts_in( '[[2,14],[1,15]]' ) ) && true === Splits::is_ones( Splits::parts_in( '[[1,14],[1,15]]' ) ) && false === Splits::is_split( Splits::parts_in( '[[6,14]]' ) ) && false === Splits::is_ones( Splits::parts_in( '[[1,14]]' ) ) && false === Splits::is_split( Splits::NONE ) && false === Splits::is_split( null ) );
check( 'the live fish of a line: received − DOA, never below 0, unknown while nothing is received', 5 === Splits::live_of( array( 'qty_received' => 6, 'qty_doa' => 1 ) ) && 0 === Splits::live_of( array( 'qty_received' => 2, 'qty_doa' => 5 ) ) && null === Splits::live_of( array( 'qty_received' => null, 'qty_doa' => 0 ) ) && null === Splits::live_of( array( 'qty_received' => '' ) ) && 0 === Splits::live_of( array( 'qty_received' => 0 ) ) );

echo "2. The tanks a part may go into\n";
$store = fh_store();
$tanks = Splits::tanks( $store, $today, $settings );
check( 'every Spa tank and nothing else (a slot with nothing set up holds no fish)', array( 511, 512, 513, 521, 522, 523, 524, 611, 621 ) === array_column( $tanks, 'number' ) && array( 'spa' ) === array_values( array_unique( array_column( $tanks, 'area' ) ) ) && array( true ) === array_values( array_unique( array_column( $tanks, 'empty' ) ) ) );
// A quarantine temporary tank takes arrivals; a clean one does not.
$set = act( $store, 0, 'setup', array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10 ) );
$cln = act( $store, 0, 'setup', array( 'name' => 'Holding B', 'kind' => 'clean', 'gallons' => 20 ) );
check( 'two temporary tanks are set up', empty( $set['error'] ) && empty( $cln['error'] ), json_encode( array( $set, $cln ) ) );
act( $store, 512, 'arrival', array( 'species' => 'Plain Test Damsel', 'qty' => 3 ) );
$tanks = Splits::tanks( $store, $today, $settings );
$by_id = Splits::by_id( $tanks );
$temp  = (int) $set['tank'];
check( 'a quarantine temporary tank is offered, a clean one and a Clean Room tank are not', in_array( $temp, array_column( $tanks, 'number' ), true ) && ! in_array( (int) $cln['tank'], array_column( $tanks, 'number' ), true ) && ! isset( $by_id[ tank_id( $store, 121 ) ] ) );
check( 'the empty tanks first — Spa before temporary, by number —, then the ones with fish', array( 511, 513, 521, 522, 523, 524, 611, 621, $temp, 512 ) === array_column( $tanks, 'number' ) && false === $by_id[ tank_id( $store, 512 ) ]['empty'] );
check( 'a tank reads as the page shows it', '511' === $by_id[ tank_id( $store, 511 ) ]['label'] && 'Tank 511' === $by_id[ tank_id( $store, 511 ) ]['name'] && 'temp' === $by_id[ tank_id( $store, $temp ) ]['area'] && false !== strpos( $by_id[ tank_id( $store, $temp ) ]['label'], (string) $temp ) && 5 === $by_id[ tank_id( $store, 511 ) ]['building_id'] && 6 === $by_id[ tank_id( $store, 611 ) ]['building_id'] );
check( 'a plan in words', '2 → Tank 511 · 1 → Tank 513' === Splits::text( Splits::parts_in( plan( $store, array( array( 2, 511 ), array( 1, 513 ) ) ) ), $by_id ) );

echo "3. What is wrong with a plan\n";
$ok = Splits::parts_in( plan( $store, array( array( 3, 511 ), array( 2, 513 ), array( 1, $temp ) ) ) );
check( 'a sound plan: three tanks that take arrivals, 6 of 6 placed', array() === Splits::errors( $ok, 6, $by_id ) );
check( 'fewer placed than alive is fine: the rest stays in the first tank', array() === Splits::errors( $ok, 9, $by_id ) );
check( 'more placed than alive', array( 'the split places 6 fish but only 5 arrived alive (received − DOA)' ) === Splits::errors( $ok, 5, $by_id ) );
check( 'nothing received yet: the counts cannot be checked, the plan waits', array() === Splits::errors( $ok, null, $by_id ) );
check( 'a tank picked twice', array( 'Tank 511 is picked twice' ) === Splits::errors( Splits::parts_in( plan( $store, array( array( 1, 511 ), array( 1, 511 ) ) ) ), 6, $by_id ) );
check( 'a Clean Room tank, a clean temporary tank and a tank that does not exist take no arrivals', 1 === count( Splits::errors( Splits::parts_in( plan( $store, array( array( 1, 511 ), array( 1, 121 ) ) ) ), 6, $by_id ) ) && false !== strpos( Splits::errors( Splits::parts_in( plan( $store, array( array( 1, 511 ), array( 1, (int) $cln['tank'] ) ) ) ), 6, $by_id )[0], 'takes no arrivals' ) && 1 === count( Splits::errors( Splits::parts_in( '[[1,99999]]' ), 6, $by_id ) ) );
check( 'a further row without a count, a row without a tank', array( 'a part of the split has no count', 'a part of the split has no tank' ) === Splits::errors( Splits::parts_in( '[[1,' . tank_id( $store, 513 ) . '],[0,' . tank_id( $store, 511 ) . '],[2,0]]' ), 6, $by_id ) );
check( 'the first tank needs no count: it keeps what is not placed elsewhere', array() === Splits::errors( Splits::parts_in( '[[0,' . tank_id( $store, 511 ) . '],[2,' . tank_id( $store, 513 ) . ']]' ), 6, $by_id ) && array( 'a part of the split has no tank' ) === Splits::errors( Splits::parts_in( '[[3,0],[2,' . tank_id( $store, 513 ) . ']]' ), 6, $by_id ) );

echo "4. \"1 per tank\"\n";
$store = fh_store();
$tanks = Splits::tanks( $store, $today, $settings );
$nums  = static function ( array $parts ) use ( $store ) {
	$out = array();
	foreach ( $parts as $part ) {
		$out[] = (int) $part['qty'] . '>' . (int) $store->tank( $part['tank_id'] )['number'];
	}
	return $out;
};
check( 'six live fish: one each over the next empty Spa tanks of the order\'s building, in rack order', array( '1>511', '1>512', '1>513', '1>521', '1>522', '1>523' ) === $nums( Splits::one_per_tank( 6, $tanks, 5 ) ) );
check( 'the line\'s own first tank leads', array( '1>522', '1>511', '1>512' ) === $nums( Splits::one_per_tank( 3, $tanks, 5, array(), tank_id( $store, 522 ) ) ) );
check( 'tanks other lines of the page already use are skipped', array( '1>513', '1>521' ) === $nums( Splits::one_per_tank( 2, $tanks, 5, array( tank_id( $store, 511 ), tank_id( $store, 512 ) ) ) ) );
check( 'another building: only its tanks — and the plan stops when they run out (2 of 5 placed)', array( '1>611', '1>621' ) === $nums( Splits::one_per_tank( 5, $tanks, 6 ) ) );
check( 'no building on the order: the whole Spa', 9 === count( Splits::one_per_tank( 20, $tanks, null ) ) && array( '1>511', '1>512' ) === $nums( Splits::one_per_tank( 2, $tanks, 0 ) ) );
act( $store, 511, 'arrival', array( 'species' => 'Plain Test Damsel', 'qty' => 3 ) );
check( 'a tank with fish in it is not an empty tank — also not as the first', array( '1>512', '1>513' ) === $nums( Splits::one_per_tank( 2, Splits::tanks( $store, $today, $settings ), 5, array(), tank_id( $store, 511 ) ) ) );
check( 'nothing alive: nothing to lay out; a temporary tank is never picked by itself', array() === Splits::one_per_tank( 0, $tanks, 5 ) && array() === Splits::one_per_tank( 3, array( array( 'id' => 90, 'number' => 901, 'area' => 'temp', 'empty' => true, 'building_id' => 7 ) ), null ) );
check( 'every part is one fish', true === Splits::is_ones( Splits::one_per_tank( 6, $tanks, 5 ) ) );

echo "5. What a save keeps\n";
$store = fh_store();
$saved = save(
	$store,
	array(
		row( 'Copper Test Butterfly', array( 'species_id' => '1', 'size_slug' => 'medium', 'qty_received' => '6', 'qty_doa' => '1', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ), array( 1, 523 ), array( 1, 524 ), array( 1, 511 ) ) ) ) ),
		row( 'Lilac Test Anthias', array( 'species_id' => '2', 'qty_ordered' => '12', 'qty_received' => '12', 'tank_id' => (string) tank_id( $store, 513 ) ) ),
	)
);
check( 'the order is saved', empty( $saved['error'] ) && 2 === count( $saved['line_ids'] ), json_encode( $saved ) );
$order_id = (int) $saved['order_id'];
list( $fly, $ant ) = $saved['line_ids'];
check( 'the plan is kept for its line, by the id the line got', 5 === count( (array) Splits::plan( $fly ) ) && '1 → Tank 521 · 1 → Tank 522 · 1 → Tank 523 · 1 → Tank 524 · 1 → Tank 511' === Splits::text( Splits::plan( $fly ), Splits::by_id( Splits::tanks( $store, $today, $settings ) ) ) );
check( 'the line that is not split has none', null === Splits::plan( $ant ) && array( $fly ) === array_keys( Splits::plans( array( $fly, $ant, 999 ) ) ) );
$lines = $store->order( $order_id )['lines'];
check( 'the split line\'s tank is the first tank of its plan (521); the other keeps the tank it was given (513)', 521 === $lines[0]['tank_number'] && 513 === $lines[1]['tank_number'] );
check( 'nothing is checked in by a save', 0 === count( $store->stays ) && 0 === $store->event_count() && null === $lines[0]['stay_id'] );

$form = static function () use ( $store, $order_id ) {
	$rows = array();
	foreach ( $store->order( $order_id )['lines'] as $line ) {
		$rows[] = row(
			$line['species'],
			array(
				'id'           => (string) $line['id'],
				'species_id'   => (string) $line['species_id'],
				'size_slug'    => (string) $line['size_slug'],
				'qty_ordered'  => (string) $line['qty_ordered'],
				'qty_received' => null === $line['qty_received'] ? '' : (string) $line['qty_received'],
				'qty_doa'      => (string) $line['qty_doa'],
				'tank_id'      => (string) $line['tank_id'],
				'split'        => is_array( Splits::plan( $line['id'] ) ) ? (string) json_encode( array_map( 'array_values', Splits::plan( $line['id'] ) ) ) : (string) Splits::plan( $line['id'] ),
			)
		);
	}
	return $rows;
};
$rows             = $form();
$rows[0]['split'] = plan( $store, array( array( 2, 521 ), array( 2, 522 ), array( 2, 523 ) ) ); // Six placed, five alive.
$r                = save( $store, $rows, $order_id );
check( 'more fish placed than arrived alive: the save is refused, with the line and the reason', isset( $r['error'] ) && 'Line 1 (Copper Test Butterfly): the split places 6 fish but only 5 arrived alive (received − DOA). Nothing was saved.' === $r['error'], json_encode( $r ) );
check( '… and the plan stored before is untouched', 5 === count( (array) Splits::plan( $fly ) ) );
$rows[0]['split'] = plan( $store, array( array( 2, 521 ), array( 2, 521 ) ) );
check( 'a tank picked twice refuses the save', 'Line 1 (Copper Test Butterfly): Tank 521 is picked twice. Nothing was saved.' === ( save( $store, $rows, $order_id )['error'] ?? '' ) );
$rows[0]['split'] = plan( $store, array( array( 2, 521 ), array( 2, 121 ) ) );
check( 'a Clean Room tank refuses the save', false !== strpos( (string) ( save( $store, $rows, $order_id )['error'] ?? '' ), 'takes no arrivals' ) );
$rows[1]['qty_received'] = '';
$rows[1]['split']        = plan( $store, array( array( 8, 513 ), array( 8, 512 ) ) );
$rows[0]['split']        = plan( $store, array( array( 1, 521 ), array( 1, 522 ), array( 1, 523 ), array( 1, 524 ), array( 1, 511 ) ) );
$r                       = save( $store, $rows, $order_id );
check( 'a line with nothing received yet may carry a plan: its counts are checked when the fish are in', empty( $r['error'] ) && 2 === count( (array) Splits::plan( $ant ) ) );
$rows             = $form();
$rows[1]['split'] = '';
$rows[1]['qty_received'] = '12';
$r                = save( $store, $rows, $order_id );
check( 'the plan taken away again (the anthias stay together): forgotten', empty( $r['error'] ) && null === Splits::plan( $ant ) && 5 === count( (array) Splits::plan( $fly ) ) );
$rows             = $form();
$rows[1]['split'] = Splits::NONE;
save( $store, $rows, $order_id );
check( '"keep together" said to an offer is kept as said', Splits::NONE === Splits::plan( $ant ) );
$rows             = $form();
$rows[1]['split'] = plan( $store, array( array( 12, 512 ) ) );
save( $store, $rows, $order_id );
check( 'a plan of one part is only the line\'s tank', 512 === $store->order( $order_id )['lines'][1]['tank_number'] && false === Splits::is_split( Splits::plan( $ant ) ) );
$rows             = $form();
$rows[1]['split'] = '';
$rows[1]['tank_id'] = (string) tank_id( $store, 513 );
save( $store, $rows, $order_id );

echo "6. The check-in, event by event\n";
$before_events = $store->event_count();
$result        = check_in( $store, $order_id );
check( 'two lines checked in, no error', 2 === count( $result['created'] ) && array() === $result['errors'], json_encode( $result ) );
$made = $result['created'][0];
check( 'the butterfly line: its stay in the first tank (521), 6 received, 1 DOA, four splits', $fly === $made['line_id'] && 521 === $made['tank'] && 6 === $made['qty'] && 1 === $made['doa'] && 4 === count( $made['splits'] ) );
$own = (int) $made['stay_id'];
check(
	'the line\'s own stay, in order: the arrival of all 6 into 521, the DOA loss, then one move of 1 per further tank',
	array(
		'arrival 6 [] ->521',
		'loss 1 [doa] ->-',
		'move 1 [split to #' . $made['splits'][0]['stay_id'] . '] 521>522',
		'move 1 [split to #' . $made['splits'][1]['stay_id'] . '] 521>523',
		'move 1 [split to #' . $made['splits'][2]['stay_id'] . '] 521>524',
		'move 1 [split to #' . $made['splits'][3]['stay_id'] . '] 521>511',
	) === events_of( $store, $own ),
	json_encode( events_of( $store, $own ) )
);
$first = $store->events( $own )[0];
check( 'the arrival is on the received date, written by the check-in (source order)', $today === Replay::date_in_zone( strtotime( $first['event_at'] . ' UTC' ), wp_timezone() ) && 'order' === $first['source'] && $actor === (int) $first['actor_id'] );
$sister = $store->stay( $made['splits'][0]['stay_id'], $today, $settings );
check( 'a sister stay, in order: its backdated arrival in 521, then its move to its own tank', array( 'arrival 1 [split from #' . $own . '] ->521', 'move - [] 521>522' ) === events_of( $store, $sister['id'] ), json_encode( events_of( $store, $sister['id'] ) ) );
check( 'three events per split, exactly as the Front Desk\'s Move of a smaller quantity writes them', array( 3, 3, 3, 3 ) === array_map( 'count', array_column( $made['splits'], 'event_ids' ) ) && $store->event_count() === $before_events + 2 + 4 * 3 + 1 );
$links = array();
foreach ( $made['splits'] as $split ) {
	$stay    = $store->stay( $split['stay_id'], $today, $settings );
	$links[] = array( $stay['order_line_id'], $stay['species'], $stay['size_slug'], $stay['product_id'], $stay['qty_live'], $stay['tank_number'], $stay['checkin_date'], $stay['source'] );
}
check(
	'every sister keeps the order line, the fish, the size, the product and the check-in date — one fish each in 522, 523, 524 and 511',
	array(
		array( $fly, 'Copper Test Butterfly', 'medium', 12, 1, 522, $today, 'order' ),
		array( $fly, 'Copper Test Butterfly', 'medium', 12, 1, 523, $today, 'order' ),
		array( $fly, 'Copper Test Butterfly', 'medium', 12, 1, 524, $today, 'order' ),
		array( $fly, 'Copper Test Butterfly', 'medium', 12, 1, 511, $today, 'order' ),
	) === $links,
	json_encode( $links )
);
check( 'one fish is left in the first tank; the line points at that stay', 1 === $store->stay( $own, $today, $settings )['qty_live'] && $own === $store->order( $order_id )['lines'][0]['stay_id'] && 5 === count( $store->stays_for_line( $fly, $today, $settings ) ) );
$anthias = $result['created'][1];
check( 'the anthias stay together: one stay of 12 in 513, no split', 12 === $anthias['qty'] && 513 === $anthias['tank'] && array() === $anthias['splits'] && array( 'arrival 12 [] ->513' ) === events_of( $store, $anthias['stay_id'] ) );
check( 'the plan was an intent: once the line is a stay it is forgotten', null === Splits::plan( $fly ) && array() === Splits::all()['plans'] );
check( 'the message says it', '2 stays created in Tank 513, 521. 1 line split on into Tank 511, 522, 523, 524.' === Orders::check_in_message( $result ), Orders::check_in_message( $result ) );
check( 'the order is received', 'received' === $store->order( $order_id )['status'] );

echo "7. The line's figures count every tank of the split\n";
$o = resolved( $store, $order_id );
$l = $o['lines'][0];
check( 'straight after the check-in: 6 arrived, 1 DOA, 5 survived, 5 live over five tanks, none sold', true === $l['linked'] && 5 === $l['live'] && 5 === $l['survived'] && 1 === $l['doa'] && 0 === $l['sold'] && 6 === $l['qty_received'] );
$r1 = act( $store, 521, 'loss', array( 'stay_id' => $own, 'qty' => 1 ) );                                  // The first tank.
$r2 = act( $store, 523, 'loss', array( 'stay_id' => $made['splits'][1]['stay_id'], 'qty' => 1 ) );       // A sister's tank.
$r3 = act( $store, 524, 'sold', array( 'stay_id' => $made['splits'][2]['stay_id'], 'qty' => 1 ) );       // Another sister's tank.
check( 'a loss in the first tank, a loss in a sister\'s tank and a sale out of a third', empty( $r1['error'] ) && empty( $r2['error'] ) && empty( $r3['error'] ), json_encode( array( $r1, $r2, $r3 ) ) );
$o = resolved( $store, $order_id );
$l = $o['lines'][0];
check( 'live 2 (522 and 511), survived 3 (6 − the DOA − the two losses), sold 1, DOA still 1', 2 === $l['live'] && 3 === $l['survived'] && 1 === $l['sold'] && 1 === $l['doa'], json_encode( array( $l['live'], $l['survived'], $l['sold'], $l['doa'] ) ) );
$f = Admin_Workbook::figures( $o )['lines'][0];
check( 'the order page shows them', '2' === $f['live'] && '3' === $f['survived'] && '1' === $f['sold'] && '1' === $f['doa'] );
check( 'the line\'s own stay alone would have said otherwise (4 survived, 0 sold): the sisters are what makes it true', 4 === $store->stay_figures( array( $own ) )[ $own ]['survived'] && 0 === $store->stay_figures( array( $own ) )[ $own ]['sold'] && 3 === $store->line_figures( array( $own ) )[ $own ]['survived'] && 5 === $store->line_figures( array( $own ) )[ $own ]['stays'] );
check( 'the pure sum: arrived is the own stay\'s, the rest adds up', array( 'arrived' => 6, 'lost' => 3, 'sold' => 1, 'doa' => 1, 'live' => 2, 'stays' => 3, 'survived' => 3 ) === Orders::with_sisters( array( 'arrived' => 6, 'lost' => 2, 'sold' => 0, 'doa' => 1, 'live' => 0 ), array( array( 'arrived' => 1, 'lost' => 1, 'sold' => 0, 'doa' => 0, 'live' => 0 ), array( 'arrived' => 3, 'lost' => 0, 'sold' => 1, 'doa' => 0, 'live' => 2 ) ) ) );
check( 'a line that was not split reads as before', 12 === $o['lines'][1]['live'] && 12 === $o['lines'][1]['survived'] && 1 === $store->line_figures( array( $anthias['stay_id'] ) )[ $anthias['stay_id'] ]['stays'] );
/* The order: 18 received, 1 DOA, survived 3 + 12 = 15 → survival 15 ÷ 17; fill 18 ÷ 18. */
$stats = Stats::build( array( $o ) );
check( 'the Stats need nothing new: fill 100%, survival 15 of 17, 1 DOA', abs( $stats['totals']['fill_rate'] - 1 ) < 1e-9 && 15 === $stats['totals']['survived'] && abs( $stats['totals']['survival_rate'] - 15 / 17 ) < 1e-9 && 1 === $stats['totals']['doa'] );

echo "8. Undo of one split\n";
$split = $made['splits'][0]; // The fish that went to 522.
$undo  = act( $store, 522, 'undo', array( 'event_ids' => $split['event_ids'] ) );
check( 'the Front Desk\'s own Undo takes the split back', empty( $undo['error'] ) && 'Undone' === $undo['message'], json_encode( $undo ) );
check( 'the fish is back in the first tank\'s group; the sister holds none', 1 === $store->stay( $own, $today, $settings )['qty_live'] && 0 === $store->stay( $split['stay_id'], $today, $settings )['qty_live'] );
$l = resolved( $store, $order_id )['lines'][0];
check( 'the line still counts the same fish: live 2, survived 3, sold 1', 2 === $l['live'] && 3 === $l['survived'] && 1 === $l['sold'] && 1 === $l['doa'] );
check( 'the other splits are untouched', 1 === $store->stay( $made['splits'][3]['stay_id'], $today, $settings )['qty_live'] && 511 === $store->stay( $made['splits'][3]['stay_id'], $today, $settings )['tank_number'] );
check( 'undone once only', 'Already undone' === ( act( $store, 522, 'undo', array( 'event_ids' => $split['event_ids'] ) )['message'] ?? '' ) );

echo "9. A second click creates nothing\n";
$count  = $store->event_count();
$stays  = count( $store->stays );
$second = check_in( $store, $order_id );
check( 'no stay, no event, both lines skipped', array() === $second['created'] && 2 === $second['skipped'] && $count === $store->event_count() && $stays === count( $store->stays ) );
check( '… and it says so', 0 === strpos( Orders::check_in_message( $second ), 'Nothing to check in' ) );

echo "10. A split posted for a line that is already checked in\n";
$rows             = $form();
$rows[1]['split'] = plan( $store, array( array( 6, 513 ), array( 6, 512 ) ) ); // The page was loaded before the check-in.
$r                = save( $store, $rows, $order_id, array( 'status' => 'received' ) );
check( 'refused plainly — and the rest of the save goes on', empty( $r['error'] ) && false !== strpos( $r['message'], 'Not saved: the split of Lilac Test Anthias — that line is already checked in: split the group from its tank instead (Move, with a smaller count).' ), json_encode( $r['message'] ?? $r ) );
check( 'nothing is kept for it, nothing is moved', null === Splits::plan( $ant ) && $stays === count( $store->stays ) && $count === $store->event_count() && 12 === $store->stay( $anthias['stay_id'], $today, $settings )['qty_live'] );
check( 'the line keeps its stay and its tank', $anthias['stay_id'] === $store->order( $order_id )['lines'][1]['stay_id'] && 513 === $store->order( $order_id )['lines'][1]['tank_number'] );
$third = check_in( $store, $order_id );
check( 'another check-in still creates nothing', array() === $third['created'] && $stays === count( $store->stays ) );
$by_hand = act( $store, 513, 'move', array( 'stay_id' => $anthias['stay_id'], 'to_tank' => 512, 'qty' => 4 ) );
$l       = resolved( $store, $order_id )['lines'][1];
check( 'split by hand from its tank instead, the line counts both halves', empty( $by_hand['error'] ) && 12 === $l['live'] && 12 === $l['survived'] && 8 === $store->stay( $anthias['stay_id'], $today, $settings )['qty_live'] );

echo "11. What is remembered per fish\n";
$memory = Splits::all()['species'];
check( 'the butterfly was split last time, one per tank — under its species row and under its name', array( 's:1', 'n:copper test butterfly' ) === array_keys( $memory ) && true === $memory['s:1']['ones'] && $today === $memory['s:1']['on'] );
check( 'the anthias were not: nothing is remembered for them', null === Splits::remembered( array( 'species_id' => 2, 'species' => 'Lilac Test Anthias' ) ) );
check( 'the next butterfly line is offered the split — by its species row, or by its name alone', array( 'ones' => true, 'on' => $today ) === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) && null !== Splits::remembered( array( 'species_id' => null, 'species' => ' copper  TEST butterfly ' ) ) );
check( 'a fish nobody has identified is never remembered', array() === Splits::species_keys( array( 'species' => '?? small brown wrasse', 'needs_id' => true ) ) && null === Splits::remembered( array( 'species' => 'Copper Test Butterfly', 'needs_id' => true, 'species_id' => 1 ) ) );
// The next order: the butterflies are kept together this time — and a group of 3 + 2 is not "one per tank".
$next = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '5', 'split' => plan( $store, array( array( 3, 611 ), array( 2, 621 ) ) ) ) ) ), 0, array( 'building_id' => '6' ) );
$in   = check_in( $store, (int) $next['order_id'] );
check( 'a second order: 3 in 611 and 2 in 621', empty( $next['error'] ) && 1 === count( $in['created'] ) && 611 === $in['created'][0]['tank'] && 1 === count( $in['created'][0]['splits'] ) && 621 === $in['created'][0]['splits'][0]['tank'] && 2 === $in['created'][0]['splits'][0]['qty'] && 3 === $store->stay( $in['created'][0]['stay_id'], $today, $settings )['qty_live'] );
check( '… remembered as split, but not as one per tank', array( 'ones' => false, 'on' => $today ) === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) );
$third_order = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '2', 'split' => Splits::NONE ) ) ), 0, array( 'building_id' => '6' ) );
$in          = check_in( $store, (int) $third_order['order_id'] );
check( 'a third order kept together (the offer declined): the fish is forgotten again', 1 === count( $in['created'] ) && array() === $in['created'][0]['splits'] && null === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) && array() === Splits::all()['species'] && array() === Splits::all()['plans'] );
check( 'nothing is seeded: an install that never split anything stores nothing', false === get_option( Splits::OPTION, false ) );
// The workbook import checks history in: it neither reads a plan nor teaches the memory.
Splits::remember( array( 'species_id' => 2, 'species' => 'Lilac Test Anthias' ), true, true, $today );
$hist = save( $store, array( row( 'Lilac Test Anthias', array( 'species_id' => '2', 'qty_received' => '4', 'split' => plan( $store, array( array( 2, 522 ), array( 2, 523 ) ) ) ) ) ) );
$in   = check_in( $store, (int) $hist['order_id'], array( 'source' => 'import' ) );
check( 'an import\'s check-in splits nothing and leaves the memory alone', 1 === count( $in['created'] ) && array() === $in['created'][0]['splits'] && null !== Splits::remembered( array( 'species_id' => 2, 'species' => 'Lilac Test Anthias' ) ) );

echo "12. Bag mode\n";
$store = fh_store();
delete_option( Splits::OPTION );
$bag = save(
	$store,
	array(
		row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '4', 'qty_doa' => '1', 'bags' => '2', 'unit_price' => '15', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ), array( 1, 523 ) ) ) ) ),
		row( 'Lilac Test Anthias', array( 'species_id' => '2', 'qty_received' => '6', 'unit_price' => '3' ) ),
	),
	0,
	array( 'cost_mode' => 'bag', 'tariffs' => '20', 'domestic_flight' => '50', 'import_fees' => '30' )
);
$bag_id  = (int) $bag['order_id'];
$costing = resolved( $store, $bag_id )['costing'];
$in      = check_in( $store, $bag_id );
check( 'a bag-mode order checks in and splits the same way: 1 in 521, 1 in 522, 1 in 523', empty( $bag['error'] ) && 2 === count( $in['created'] ) && array( 522, 523 ) === array_column( $in['created'][0]['splits'], 'tank' ) && 1 === $store->stay( $in['created'][0]['stay_id'], $today, $settings )['qty_live'] );
$after = resolved( $store, $bag_id );
/* Units 2 bags + 6 = 8; per bag (50 + 30 + 20) ÷ 8 = 12.5; landed 2 × 15 + 2 × 12.5 = 55 and 6 × 3 + 6 × 12.5 = 93. */
check( 'the costing does not move by a split: units 8, per bag 12.50, landed 55.00 / 93.00', abs( $after['costing']['units'] - 8 ) < 1e-9 && abs( $after['costing']['per_bag'] - 12.5 ) < 1e-9 && abs( $after['lines'][0]['landed_total'] - 55 ) < 1e-9 && abs( $after['lines'][1]['landed_total'] - 93 ) < 1e-9 && abs( $after['costing']['total'] - $costing['total'] ) < 1e-9 );
check( 'every stay of the split carries the line\'s landed cost per fish (55 ÷ 4 = 13.75)', array( 13.75 ) === array_values( array_unique( array_column( $store->stays_for_line( $in['created'][0]['line_id'], $today, $settings ), 'landed_cost' ) ) ) );
check( 'the line: 3 live over three tanks, 3 survived, 1 DOA', 3 === $after['lines'][0]['live'] && 3 === $after['lines'][0]['survived'] && 1 === $after['lines'][0]['doa'] );

echo "13. A tank that went away between the save and the check-in\n";
$store = fh_store();
delete_option( Splits::OPTION );
$gone = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '3', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ), array( 1, 523 ) ) ) ) ) ) );
$t522 = $store->tank_by_number( 522 );
$store->save_tank( array_merge( $t522, array( 'status' => 'offline' ) ) );
$in = check_in( $store, (int) $gone['order_id'] );
check( 'the part for the tank that is offline stays in the first tank, and the check-in says so', 1 === count( $in['created'] ) && array( 523 ) === array_column( $in['created'][0]['splits'], 'tank' ) && 2 === $store->stay( $in['created'][0]['stay_id'], $today, $settings )['qty_live'] && 1 === count( $in['errors'] ) && false !== strpos( $in['errors'][0], 'stay in their first tank' ), json_encode( $in ) );
check( '… and the fish is still remembered as split, though not as one per tank', array( 'ones' => false, 'on' => $today ) === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) );
// A first tank that is gone: the check-in picks one as it does for any line.
delete_option( Splits::OPTION );
$store = fh_store();
$gone  = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '2', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ) ) ) ) ) ) );
$store->save_tank( array_merge( $store->tank_by_number( 521 ), array( 'status' => 'offline' ) ) );
$in = check_in( $store, (int) $gone['order_id'] );
check( 'a first tank that is gone: the line arrives in the next empty Spa tank of the building and the split goes on from there', 1 === count( $in['created'] ) && 511 === $in['created'][0]['tank'] && array( 522 ) === array_column( $in['created'][0]['splits'], 'tank' ) && array() === $in['errors'], json_encode( $in ) );
// A plan saved before the DOA count was known: the last part would empty the first tank.
delete_option( Splits::OPTION );
$store = fh_store();
$early = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ), array( 1, 523 ) ) ) ) ) ) );
$store->update_line_fields( (int) $early['line_ids'][0], array() ); // (No-op: the line is as saved.)
$store->lines[ (int) $early['line_ids'][0] ]['qty_received'] = 2; // Two came, typed elsewhere after the plan was made.
$in = check_in( $store, (int) $early['order_id'] );
check( 'a plan for more fish than came: one moves on, the part that would empty the first tank is skipped and said', 1 === count( $in['created'] ) && array( 522 ) === array_column( $in['created'][0]['splits'], 'tank' ) && 1 === $store->stay( $in['created'][0]['stay_id'], $today, $settings )['qty_live'] && 1 === count( $in['errors'] ) && false !== strpos( $in['errors'][0], 'not enough left' ), json_encode( $in ) );

// The first tank's count left empty: "the rest".
delete_option( Splits::OPTION );
$store = fh_store();
$rest  = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '6', 'qty_doa' => '1', 'split' => (string) json_encode( array( array( 0, tank_id( $store, 521 ) ), array( 2, tank_id( $store, 522 ) ) ) ) ) ) ) );
$in    = check_in( $store, (int) $rest['order_id'] );
check( 'the first tank\'s count left empty: 2 go to 522, the other 3 alive stay in 521', empty( $rest['error'] ) && 1 === count( $in['created'] ) && 521 === $in['created'][0]['tank'] && array( 522 ) === array_column( $in['created'][0]['splits'], 'tank' ) && 3 === $store->stay( $in['created'][0]['stay_id'], $today, $settings )['qty_live'] && 2 === $store->stay( $in['created'][0]['splits'][0]['stay_id'], $today, $settings )['qty_live'] && array() === $in['errors'], json_encode( array( $rest['error'] ?? '', $in ) ) );
check( '… and that is no "one per tank"', array( 'ones' => false, 'on' => $today ) === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) );
delete_option( Splits::OPTION );
$store = fh_store();
$ones  = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '3', 'split' => (string) json_encode( array( array( 0, tank_id( $store, 521 ) ), array( 1, tank_id( $store, 522 ) ), array( 1, tank_id( $store, 523 ) ) ) ) ) ) ) );
check_in( $store, (int) $ones['order_id'] );
check( 'three fish, the rest in the first tank and one each in two more: that IS one per tank, and remembered so', array( 'ones' => true, 'on' => $today ) === Splits::remembered( array( 'species_id' => 1, 'species' => 'Copper Test Butterfly' ) ) );

echo "14. What is stored is bounded and sanitised\n";
delete_option( Splits::OPTION );
$raw = array(
	'plans'   => array(
		7     => array( array( 'qty' => 2, 'tank_id' => 14 ), array( 'qty' => '<b>3</b>', 'tank_id' => 15 ) ),
		'x'   => array( array( 'qty' => 1, 'tank_id' => 14 ) ),
		8     => 'none',
		9     => 'whatever',
		10    => array(),
	),
	'species' => array(
		's:1'  => array( 'ones' => 1, 'on' => '2026-03-02', 'extra' => '<script>' ),
		''     => array( 'ones' => true ),
		'n:x'  => 'yes',
		'n:ok' => array( 'on' => 'yesterday' ),
	),
	'other'   => 'dropped',
);
$clean = Splits::sanitize( $raw );
check( 'plans: a line id, parts cleaned (a count that is no number is 0), NONE kept, junk dropped', array( 7, 8 ) === array_keys( $clean['plans'] ) && array( array( 'qty' => 2, 'tank_id' => 14 ), array( 'qty' => 0, 'tank_id' => 15 ) ) === $clean['plans'][7] && Splits::NONE === $clean['plans'][8] );
check( 'the memory: a key, a flag and a date — nothing else', array( 's:1' => array( 'ones' => true, 'on' => '2026-03-02' ), 'n:ok' => array( 'ones' => false, 'on' => '' ) ) === $clean['species'] && array( 'plans', 'species' ) === array_keys( $clean ) );
check( 'anything that is no array is nothing', array( 'plans' => array(), 'species' => array() ) === Splits::sanitize( 'x' ) && array( 'plans' => array(), 'species' => array() ) === Splits::sanitize( null ) );
$big = array( 'plans' => array(), 'species' => array() );
for ( $i = 1; $i <= Splits::MAX_PLANS + 25; $i++ ) {
	$big['plans'][ $i ] = array( array( 'qty' => 1, 'tank_id' => 14 ) );
}
for ( $i = 1; $i <= Splits::MAX_SPECIES + 25; $i++ ) {
	$big['species'][ 'n:fish ' . $i ] = array( 'ones' => false, 'on' => '2026-03-02' );
}
$clean = Splits::sanitize( $big );
check( 'at most ' . Splits::MAX_PLANS . ' plans and ' . Splits::MAX_SPECIES . ' fish — the oldest go first', Splits::MAX_PLANS === count( $clean['plans'] ) && ! isset( $clean['plans'][25] ) && isset( $clean['plans'][26], $clean['plans'][ Splits::MAX_PLANS + 25 ] ) && Splits::MAX_SPECIES === count( $clean['species'] ) && ! isset( $clean['species']['n:fish 25'] ) && isset( $clean['species']['n:fish 26'] ) );
Splits::keep( array( 5 => array( array( 'qty' => 1, 'tank_id' => 14 ), array( 'qty' => 1, 'tank_id' => 15 ) ), 6 => Splits::NONE, 0 => Splits::NONE ) );
check( 'keep() stores by line id; a line without an id is ignored', array( 5, 6 ) === array_keys( Splits::all()['plans'] ) );
Splits::keep( array( 5 => null ), array( 6 ) );
check( 'a plan taken away and a line that is gone leave nothing behind — not even the option', false === get_option( Splits::OPTION, false ) );
// Deleting an order forgets its lines' plans (the "delete" op does exactly this).
$store = fh_store();
$del   = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '2', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ) ) ) ) ) ) );
Splits::forget( array_column( $store->order( (int) $del['order_id'] )['lines'], 'id' ) );
check( 'forget() by the lines of a deleted order', array() === Splits::all()['plans'] );
// A line removed from the order takes its plan along.
$del  = save( $store, array( row( 'Copper Test Butterfly', array( 'species_id' => '1', 'qty_received' => '2', 'split' => plan( $store, array( array( 1, 521 ), array( 1, 522 ) ) ) ) ), row( 'Lilac Test Anthias', array( 'species_id' => '2' ) ) ) );
$keep = $store->order( (int) $del['order_id'] )['lines'][1];
save( $store, array( row( 'Lilac Test Anthias', array( 'id' => (string) $keep['id'], 'species_id' => '2' ) ) ), (int) $del['order_id'] );
check( 'a line removed by a save takes its plan along', 1 === count( $store->order( (int) $del['order_id'] )['lines'] ) && array() === Splits::all()['plans'] );

fh_finish();
