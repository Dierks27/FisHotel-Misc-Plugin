<?php
/**
 * Stock — a plan applied to the stock once (used leftovers go, sheets are
 * taken, new leftovers are added), a second click that finds nothing to
 * do, Undo that puts everything back while nothing else has touched what
 * the plan touched, a plan made for stock that has since changed, a
 * failure half-way (with and without transactions) leaving the stock as
 * it was, and the stock kept by hand: sheets bought, the count corrected,
 * leftovers added, resized and removed. Invented boxes and numbers.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/stock-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Plan;
use FisHotel\Misc\Sections\Foam_Cuts\Planner;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Stock;
use FisHotel\Misc\Sections\Foam_Cuts\Tests\Fake_Store;

/**
 * A store with 3 sheets and two leftovers (L1 30 × 20, L2 13 × 13), and
 * the section's own box list holding two boxes.
 */
function fh_shop() {
	update_option(
		Settings::OPTION_KEY,
		array(
			'boxes' => array(
				array( 'name' => 'Cube', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ),
				array( 'name' => 'Flat', 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 10 ) ),
			),
		)
	);

	$store = new Fake_Store();
	$store->add_sheet_entry( 'bought', 3, 0, 'first delivery', 1 );
	Stock::add_leftover( $store, '30', '20', 'offcut' );
	Stock::add_leftover( $store, '13', '13' );
	$store->calls = array();

	return $store;
}

echo "1. The stock as a plan sees it\n";
$store = fh_shop();
$snap  = Stock::snapshot( $store );
check_same( 'three sheets', 3, $snap['sheets'] );
check_same(
	'the leftovers in stock with their labels and sizes',
	array(
		array( 'id' => 1, 'label' => 'L1', 'length' => fh_in( 30 ), 'width' => fh_in( 20 ) ),
		array( 'id' => 2, 'label' => 'L2', 'length' => fh_in( 13 ), 'width' => fh_in( 13 ) ),
	),
	$snap['leftovers']
);

echo "2. Making a plan changes nothing in the stock\n";
$before = $store->state();
$made   = Planner::create( $store, array( 'cube' => 2, 'flat' => 1 ), array(), 7 );
check( 'the plan is saved as a draft', true === $made['ok'] && 1 === $made['id'] && 'draft' === $store->plan( 1 )['status'], $made['message'] );
$plan = $store->plan( 1 );
$data = $plan['data'];
check_same( 'its title says what it is for', '2 × Cube, 1 × Flat', $plan['title'] );
check( 'the leftovers, the sheets and the entries are untouched', $before['leftovers'] === $store->state()['leftovers'] && 3 === $store->sheets() && 1 === count( $store->sheet_log ) );
check( 'only the labels it will hand out are reserved (L3 …)', 3 === $plan['label_from'] && $plan['label_count'] === count( Plan::new_leftovers( $data ) ) && 3 + $plan['label_count'] === $store->next_label() && $plan['label_count'] > 0 );
check_same( 'it uses both leftovers', array( 1, 2 ), Plan::used_leftover_ids( $data ) );
check_same( 'and one whole sheet', 1, Plan::sheets( $data ) );
check_same( 'the draft matches the stock', array( 'stale' => array(), 'undo_blockers' => array() ), Stock::state( $store, $plan ) );

echo "3. Mark as cut\n";
$new    = Plan::new_leftovers( $data );
$result = Stock::apply( $store, $plan, 7 );
check( 'applied', true === $result['ok'], $result['message'] );
check( 'the message says what happened', false !== strpos( $result['message'], 'Marked as cut.' ) && false !== strpos( $result['message'], '1 whole sheet taken' ) && false !== strpos( $result['message'], '2 leftovers used up' ) && false !== strpos( $result['message'], 'new leftover' ), $result['message'] );
check( 'the plan is cut, by whom and when', 'cut' === $store->plan( 1 )['status'] && 7 === $store->plan( 1 )['cut_by'] && '' !== $store->plan( 1 )['cut_at'] );
check( 'the leftovers it cut into are gone from the stock, marked as used by it', 'used' === $store->leftover( 1 )['status'] && 1 === $store->leftover( 1 )['used_plan_id'] && 'used' === $store->leftover( 2 )['status'] );
check_same( 'a sheet is taken: 3 − 1', 2, $store->sheets() );
$entry = $store->sheet_log( 1 )[0];
check( 'as an entry of its own: plan, −1, the plan, who', 'plan' === $entry['kind'] && -1 === $entry['sheets'] && 1 === $entry['plan_id'] && 7 === $entry['user_id'] );
$stock = array();
foreach ( $store->leftovers( 'stock' ) as $row ) {
	$stock[ $row['label'] ] = array( $row['length'], $row['width'], $row['origin'], $row['plan_id'] );
}
$want = array();
foreach ( $new as $left ) {
	$want[ $left['label'] ] = array( $left['length'], $left['width'], 'plan', 1 );
}
check_same( 'the new leftovers are in stock under the labels the cut sheet printed', $want, $stock );
check( 'each notes where it was cut from', 0 === strpos( $store->leftovers( 'stock' )[0]['note'], 'from ' ) );
$writes = array_slice( $store->calls, (int) array_search( 'begin', $store->calls, true ) );
check( 'it all happened in one transaction, the plan\'s status first', array( 'begin', 'set_plan_status' ) === array_slice( $writes, 0, 2 ) && 'commit' === end( $writes ) && ! in_array( 'rollback', $writes, true ), implode( ' ', $writes ) );

echo "4. Only once\n";
$after  = $store->state();
$result = Stock::apply( $store, $store->plan( 1 ), 7 );
check( 'a second click is refused', false === $result['ok'] && false !== strpos( $result['message'], 'already marked as cut' ), $result['message'] );
check( 'and changes nothing', $after === $store->state() );
$result = Stock::apply( $store, $plan, 7 );
check( 'another tab still holding the draft is refused too', false === $result['ok'] );
check( 'and changes nothing', $after === $store->state() );
$store2 = fh_shop();
Planner::create( $store2, array( 'cube' => 1 ), array(), 7 );
$row    = $store2->plan( 1 );
$first  = Stock::apply( $store2, $row, 7 );
$state  = $store2->state();
$second = Stock::apply( $store2, $row, 8 );
check( 'two requests carrying the same draft: the first applies, the second finds the plan already cut', true === $first['ok'] && false === $second['ok'] && $state === $store2->state() );

echo "5. Undo\n";
check_same( 'a cut plan nothing has touched can be undone', array(), Stock::state( $store, $store->plan( 1 ) )['undo_blockers'] );
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'undone — saying what came back in numbers, with the count as it is now', true === $result['ok'] && false !== strpos( $result['message'], '1 whole sheet is back on the count (3 in stock now)' ) && false !== strpos( $result['message'], 'The plan is a draft again.' ) && false === strpos( $result['message'], 'as it was' ), $result['message'] );
$now = $store->state();
check( 'the leftovers are exactly as before the plan was cut', $before['leftovers'] === $now['leftovers'] );
check_same( 'the sheets are back: 3', 3, $store->sheets() );
check( 'the undo is an entry too (+1), so the entries still add up', 'undo' === $store->sheet_log( 1 )[0]['kind'] && 1 === $store->sheet_log( 1 )[0]['sheets'] && 3 === count( $store->sheet_log ) );
check( 'the plan is a draft again', 'draft' === $store->plan( 1 )['status'] && '' !== $store->plan( 1 )['undone_at'] );
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'undoing a draft is refused and changes nothing', false === $result['ok'] && $now === $store->state() );
$result = Stock::apply( $store, $store->plan( 1 ), 7 );
$by_label = static function ( array $state ) {
	$out = array();
	foreach ( $state['leftovers'] as $row ) {
		$out[ $row['label'] ] = array( $row['length'], $row['width'], $row['status'], $row['plan_id'], $row['used_plan_id'] );
	}
	ksort( $out );
	return $out;
};
check( 'it can be marked as cut again — same labels, same result', true === $result['ok'] && $by_label( $after ) === $by_label( $store->state() ) && 2 === $store->sheets() );

echo "6. Undo while nothing else has changed what the plan touched\n";
Stock::buy_sheets( $store, 4, 'second delivery', 7 );
$other = Stock::add_leftover( $store, '9', '9' );
check_same( 'sheets bought and an unrelated leftover added since do not stand in the way', array(), Stock::state( $store, $store->plan( 1 ) )['undo_blockers'] );
$label = $new[0]['label'];
$made  = null;
foreach ( $store->leftovers( 'stock' ) as $row ) {
	if ( $row['label'] === $label ) {
		$made = $row;
	}
}
Stock::resize_leftover( $store, $made['id'], '5', '5' );
$state  = $store->state();
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'a leftover the plan made was resized: Undo is refused', false === $result['ok'] && false !== strpos( $result['message'], "Leftover $label, which this plan made, has been resized since." ), $result['message'] );
check( 'and nothing changed', $state === $store->state() );
check_same( 'the page knows it beforehand', 1, count( Stock::state( $store, $store->plan( 1 ) )['undo_blockers'] ) );
Stock::remove_leftover( $store, $made['id'] );
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'a leftover the plan made was removed: refused', false === $result['ok'] && false !== strpos( $result['message'], 'has been used or removed since' ), $result['message'] );

$store = fh_shop();
Planner::create( $store, array( 'flat' => 1 ), array(), 7 );
Stock::apply( $store, $store->plan( 1 ), 7 );
Planner::create( $store, array( 'cube' => 6 ), array(), 7 );
$second = $store->plan( 2 );
$shared = array_intersect( Plan::used_leftover_ids( $second['data'] ), array_column( $store->leftovers_of_plan( 1 ), 'id' ) );
check( 'a second plan cuts into a leftover the first one made', count( $shared ) > 0 );
Stock::apply( $store, $second, 7 );
$state  = $store->state();
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'the first plan can then no longer be undone', false === $result['ok'] && $state === $store->state(), $result['message'] );
$result = Stock::undo( $store, $store->plan( 2 ), 7 );
check( 'the second one can', true === $result['ok'], $result['message'] );
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( '…and after that the first one again', true === $result['ok'] && 3 === $store->sheets() && array( 'L1', 'L2' ) === array_column( $store->leftovers( 'stock' ), 'label' ), $result['message'] );

echo "7. A plan made for stock that has since changed\n";
$store = fh_shop();
Planner::create( $store, array( 'cube' => 2, 'flat' => 1 ), array(), 7 );
Stock::remove_leftover( $store, 1 );
$state  = $store->state();
$result = Stock::apply( $store, $store->plan( 1 ), 7 );
check( 'a leftover it counted on was removed: not applied', false === $result['ok'] && false !== strpos( $result['message'], 'Leftover L1 is no longer in stock.' ) && false !== strpos( $result['message'], 'Make the plan again.' ), $result['message'] );
check( 'nothing changed, the plan is still a draft', $state === $store->state() && 'draft' === $store->plan( 1 )['status'] );
check_same( 'the page says so beforehand', array( 'Leftover L1 is no longer in stock.' ), Stock::state( $store, $store->plan( 1 ) )['stale'] );
Stock::resize_leftover( $store, 2, '13', '10' );
check_same( 'a resized leftover is named with both sizes', 'Leftover L2 is now 13 × 10 in; it was 13 × 13 in when this plan was made.', Stock::state( $store, $store->plan( 1 ) )['stale'][1] );
$store = fh_shop();
Planner::create( $store, array( 'cube' => 2 ), array(), 7 );
Stock::buy_sheets( $store, 2, '', 7 );
Stock::add_leftover( $store, '40', '8' );
check( 'sheets bought and a leftover added since do not make a plan stale', array() === Stock::state( $store, $store->plan( 1 ) )['stale'] && true === Stock::apply( $store, $store->plan( 1 ), 7 )['ok'] );
$empty = array( 'id' => 9, 'status' => 'draft', 'title' => '', 'data' => array( 'sources' => array(), 'errors' => array() ) );
check( 'a plan without sources is not applied', false === Stock::apply( $store, $empty, 7 )['ok'] );

echo "8. Something fails half-way\n";
foreach ( array( 'add_leftover', 'add_sheet_entry', 'commit', 'mark_leftovers' ) as $method ) {
	$store = fh_shop();
	Planner::create( $store, array( 'cube' => 2, 'flat' => 1 ), array(), 7 );
	$state                  = $store->state();
	$store->fail[ $method ] = true;
	$result                 = Stock::apply( $store, $store->plan( 1 ), 7 );
	$store->fail            = array();
	check( "$method fails: not applied, the stock and the plan are as they were", false === $result['ok'] && $state === $store->state() && false !== strpos( $result['message'], 'The stock is as it was.' ), $result['message'] );
	check( '…and it can be applied afterwards', true === Stock::apply( $store, $store->plan( 1 ), 7 )['ok'] );
}
foreach ( array( 'add_leftover', 'add_sheet_entry' ) as $method ) {
	$store               = fh_shop();
	$store->transactions = false;
	Planner::create( $store, array( 'cube' => 2, 'flat' => 1 ), array(), 7 );
	$state                  = $store->state();
	$store->fail[ $method ] = true;
	$result                 = Stock::apply( $store, $store->plan( 1 ), 7 );
	$store->fail            = array();
	check( "tables without transactions, $method fails: what was written is taken back by hand", false === $result['ok'] && $state === $store->state() );
}
$store = fh_shop();
Planner::create( $store, array( 'cube' => 2, 'flat' => 1 ), array(), 7 );
Stock::apply( $store, $store->plan( 1 ), 7 );
$state                         = $store->state();
$store->fail['add_sheet_entry'] = true;
$result                        = Stock::undo( $store, $store->plan( 1 ), 7 );
$store->fail                   = array();
check( 'an undo that fails half-way leaves the stock as it was (still cut)', false === $result['ok'] && $state === $store->state() );

echo "9. More sheets than the count says\n";
$store = fh_shop();
Planner::create( $store, array( 'cube' => 24 ), array(), 7 );
$plan = $store->plan( 1 );
check( 'the plan takes more whole sheets than are in stock and says so', Plan::sheets( $plan['data'] ) > 3 && 1 === count( preg_grep( '/the stock says 3/', $plan['data']['warnings'] ) ) );
check( 'it can still be marked as cut — the cutting happened', true === Stock::apply( $store, $plan, 7 )['ok'] );
check( 'the count goes below zero rather than lying; an undo brings it back to 3', $store->sheets() < 0 && true === Stock::undo( $store, $store->plan( 1 ), 7 )['ok'] && 3 === $store->sheets() );

$store = fh_shop();
Stock::buy_sheets( $store, 3, '', 7 );
Planner::create( $store, array( 'cube' => 24 ), array(), 7 );
$took = Plan::sheets( $store->plan( 1 )['data'] );
Stock::apply( $store, $store->plan( 1 ), 7 );
Stock::count_sheets( $store, '4', 'counted the rack', 7 );
$result = Stock::undo( $store, $store->plan( 1 ), 7 );
check( 'the count corrected by hand between cut and undo: the undo says the count it leaves, not "as it was"', true === $result['ok'] && 4 + $took === $store->sheets() && false !== strpos( $result['message'], '(' . ( 4 + $took ) . ' in stock now)' ), $result['message'] );

echo "10. The stock by hand\n";
$store  = fh_shop();
$result = Stock::buy_sheets( $store, '5', 'from the hardware shop', 7 );
check( 'bought 5 sheets: an entry, 8 in stock', true === $result['ok'] && 8 === $store->sheets() && 'bought' === $store->sheet_log( 1 )[0]['kind'] && 'from the hardware shop' === $store->sheet_log( 1 )[0]['note'] && false !== strpos( $result['message'], '8 in stock now' ), $result['message'] );
foreach ( array( '0', '-2', 'many', '', '99999', '2.5', '1e2', '0x10', ' 3 sheets' ) as $bad ) {
	check( "bought \"$bad\" is refused", false === Stock::buy_sheets( $store, $bad, '', 7 )['ok'] && 8 === $store->sheets() );
}
$result = Stock::count_sheets( $store, '6', 'counted the rack', 7 );
check( 'the count corrected to 6: stored as the difference (−2)', true === $result['ok'] && 6 === $store->sheets() && 'count' === $store->sheet_log( 1 )[0]['kind'] && -2 === $store->sheet_log( 1 )[0]['sheets'] );
$entries = count( $store->sheet_log );
check( 'correcting to what it already says writes nothing', true === Stock::count_sheets( $store, '6', '', 7 )['ok'] && $entries === count( $store->sheet_log ) );
check( 'a count of 0 is a count', true === Stock::count_sheets( $store, '0', '', 7 )['ok'] && 0 === $store->sheets() );
foreach ( array( '-1', '2.5', 'six', '' ) as $bad ) {
	check( "a count of \"$bad\" is refused", false === Stock::count_sheets( $store, $bad, '', 7 )['ok'] && 0 === $store->sheets() );
}

$result = Stock::add_leftover( $store, '22 1/2', '9.25', 'by the door' );
check( 'a leftover added by hand gets the next label and says to write it on the piece', true === $result['ok'] && 'L3' === $result['label'] && 'Added. Write L3 on it (22 1/2 × 9 1/4 in).' === $result['message'], $result['message'] );
$row = $store->leftover( $result['id'] );
check( 'it is in stock, by hand, with its size and note', 'stock' === $row['status'] && 'manual' === $row['origin'] && fh_in( 22.5 ) === $row['length'] && fh_in( 9.25 ) === $row['width'] && 'by the door' === $row['note'] );
foreach ( array( array( '', '5' ), array( '0', '5' ), array( '5', 'wide' ), array( '-3', '4' ) ) as $bad ) {
	check( 'a leftover of "' . $bad[0] . '" × "' . $bad[1] . '" is refused and takes no label', false === Stock::add_leftover( $store, $bad[0], $bad[1] )['ok'] && 4 === $store->next_label() );
}
$store->fail['add_leftover'] = true;
check( 'a leftover that cannot be saved gives its label back', false === Stock::add_leftover( $store, '5', '5' )['ok'] && 4 === $store->next_label() );
$store->fail = array( 'reserve_labels' => true );
$count       = count( $store->leftovers );
check( 'when no label can be reserved no leftover is added', false === Stock::add_leftover( $store, '5', '5' )['ok'] && $count === count( $store->leftovers ) );
$store->fail = array();

$sheet  = array( fh_in( 96 ), fh_in( 48 ) );
$result = Stock::add_leftover( $store, '200', '100', '', $sheet );
check( 'a leftover larger than a whole sheet is refused, in words, and takes no label', false === $result['ok'] && false !== strpos( $result['message'], 'larger than a whole sheet (96 × 48 in)' ) && 4 === $store->next_label(), $result['message'] );
check( '…in either direction: 97 × 10 and 60 × 50 do not come out of a 96 × 48 sheet', false === Stock::add_leftover( $store, '97', '10', '', $sheet )['ok'] && false === Stock::add_leftover( $store, '60', '50', '', $sheet )['ok'] && 4 === $store->next_label() );
check( 'a piece typed the other way round is fine (40 × 90), and so is a whole sheet', true === Stock::within( array( fh_in( 40 ), fh_in( 90 ) ), $sheet ) && true === Stock::within( array( fh_in( 96 ), fh_in( 48 ) ), $sheet ) );
check( 'a leftover cannot be resized past a whole sheet either', false === Stock::resize_leftover( $store, 3, '120', '9', $sheet )['ok'] && fh_in( 22.5 ) === $store->leftover( 3 )['length'] );

$result = Stock::resize_leftover( $store, 3, '20', '9.25' );
check( 'resized: L3 is now 20 × 9 1/4 in', true === $result['ok'] && 'L3 is now 20 × 9 1/4 in.' === $result['message'] && fh_in( 20 ) === $store->leftover( 3 )['length'] );
check( 'a size that is not one is refused', false === Stock::resize_leftover( $store, 3, 'x', '9' )['ok'] && fh_in( 20 ) === $store->leftover( 3 )['length'] );
$result = Stock::remove_leftover( $store, 3 );
check( 'removed: out of the stock, the row and its label kept', true === $result['ok'] && 'removed' === $store->leftover( 3 )['status'] && 'L3' === $store->leftover( 3 )['label'] && ! in_array( 'L3', array_column( $store->leftovers( 'stock' ), 'label' ), true ) );
check( 'a removed leftover cannot be resized or removed again', false === Stock::resize_leftover( $store, 3, '5', '5' )['ok'] && false === Stock::remove_leftover( $store, 3 )['ok'] );
check( 'nor one that does not exist', false === Stock::remove_leftover( $store, 99 )['ok'] && false === Stock::resize_leftover( $store, 99, '5', '5' )['ok'] );
$again = Stock::add_leftover( $store, '6', '6' );
check_same( 'a label is never given out twice: the next one is L4', 'L4', $again['label'] );

fh_finish();
