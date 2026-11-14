<?php
/**
 * The list page's arithmetic — pure: the filters (state, source, where it
 * came from), the search (name, e-mail, handle, fish), the sorting, the
 * default view grouped by fish ("<fish> — 3 waiting · longest 4 months"),
 * the "Needs you" strip, the sheets to approve, who has waited longest,
 * and the Demand table. The store's own find() is checked against the
 * same rows, so the two cannot disagree.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/listing-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Requests\Listing;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Tests\Fake_Store;

$now   = strtotime( '2026-06-01 12:00:00 UTC' );
$store = new Fake_Store();

/**
 * One row straight into the store (the list only reads).
 */
function fh_row( $store, array $row ) {
	$row = array_merge(
		array(
			'name'   => 'Pat Example',
			'email'  => 'pat@example.test',
			'fish'   => 'Blue Test Wrasse',
			'qty'    => 1,
			'source' => 'link',
			'state'  => 'waiting',
		),
		$row
	);
	if ( ! isset( $row['fish_key'] ) ) {
		$row['fish_key'] = Rules::fish_key( $row );
	}
	return $store->insert( $row );
}

$w1 = fh_row( $store, array( 'created_at' => '2026-01-25 10:00:00', 'name' => 'Pat Example', 'handle' => 'reef_pat', 'came_from' => 'forum' ) );
$w2 = fh_row( $store, array( 'created_at' => '2026-04-10 10:00:00', 'name' => 'Sam Sample', 'email' => 'sam@example.test', 'qty' => 2, 'source' => 'hand', 'came_from' => 'phone', 'state' => 'on_order' ) );
$w3 = fh_row( $store, array( 'created_at' => '2026-05-20 10:00:00', 'name' => 'Lee Local', 'email' => '', 'fish' => 'blue test  wrasse', 'source' => 'hand', 'came_from' => 'in person' ) );
$g1 = fh_row( $store, array( 'created_at' => '2026-03-01 10:00:00', 'name' => 'Sam Sample', 'email' => 'sam@example.test', 'fish' => 'Red Test Goby', 'state' => 'ready' ) );
$g2 = fh_row( $store, array( 'created_at' => '2025-11-01 10:00:00', 'name' => 'Noah Newcomer', 'email' => 'noah@example.test', 'fish' => 'Red Test Goby', 'state' => 'filled', 'closed_at' => '2026-02-01 10:00:00' ) );
$t1 = fh_row( $store, array( 'created_at' => '2026-05-30 10:00:00', 'name' => 'Noah Newcomer', 'email' => 'noah@example.test', 'fish' => 'Green Test Tang', 'species_id' => 31, 'state' => 'new', 'sheet' => 'aaaa', 'fee_amount' => 12.0 ) );
$t2 = fh_row( $store, array( 'created_at' => '2026-05-30 10:00:01', 'name' => 'Noah Newcomer', 'email' => 'noah@example.test', 'fish' => 'Emerald Tang', 'species_id' => 31, 'state' => 'new', 'sheet' => 'aaaa', 'fee_amount' => 12.0 ) );
$t3 = fh_row( $store, array( 'created_at' => '2026-05-31 10:00:00', 'name' => 'Lee Local', 'email' => 'lee@example.test', 'fish' => 'Yellow Test Blenny', 'state' => 'new', 'sheet' => 'bbbb' ) );
$p1 = fh_row( $store, array( 'created_at' => '2026-05-20 10:00:00', 'name' => 'Pat Example', 'fish' => 'Orange Test Anthias', 'state' => 'awaiting_payment', 'approved_at' => '2026-05-25 12:00:00', 'fee_amount' => 12.0 ) );
$store->update( $p1, array( 'fee_ref' => $p1 ) );
$p2 = fh_row( $store, array( 'created_at' => '2026-05-29 10:00:00', 'name' => 'Sam Sample', 'email' => 'sam@example.test', 'fish' => 'Orange Test Anthias', 'state' => 'awaiting_payment', 'approved_at' => '2026-05-31 12:00:00', 'fee_amount' => 12.0 ) );
$o1 = fh_row( $store, array( 'created_at' => '2026-02-01 10:00:00', 'name' => 'Pat Example', 'fish' => 'Purple Test Dottyback', 'source' => 'lost_fish', 'owed' => 1, 'state' => 'offered' ) );

$all  = $store->find( array() );
$open = $store->find( array( 'state' => Rules::OPEN_STATES ) );
$ids  = static function ( array $rows ) {
	return array_map( 'intval', array_column( $rows, 'id' ) );
};

echo "Filters\n";
check_same( 'eleven requests, ten of them open', array( 11, 10 ), array( count( $all ), count( $open ) ) );
check_same( 'by state', array( $w1, $w3 ), $ids( Listing::filter( $all, array( 'state' => 'waiting' ) ) ) );
check_same( 'by several states', array( $w1, $w2, $w3 ), $ids( Listing::filter( $all, array( 'state' => array( 'waiting', 'on_order' ) ) ) ) );
check_same( 'by source', array( $w2, $w3 ), $ids( Listing::filter( $all, array( 'source' => 'hand' ) ) ) );
check_same( 'by where it came from, whatever the case', array( $w2 ), $ids( Listing::filter( $all, array( 'came_from' => 'PHONE' ) ) ) );
check_same( 'filters add up', array( $w3 ), $ids( Listing::filter( $all, array( 'source' => 'hand', 'state' => 'waiting' ) ) ) );
check_same( 'no filter, every row', 11, count( Listing::filter( $all, array() ) ) );
check_same( 'the store filters the same way', $ids( Listing::filter( $all, array( 'source' => 'hand', 'state' => 'waiting' ) ) ), $ids( $store->find( array( 'source' => 'hand', 'state' => 'waiting' ) ) ) );

echo "\nSearch\n";
check_same( 'by name', array( $w2, $g1, $p2 ), $ids( Listing::filter( $all, array( 'search' => 'sam' ) ) ) );
check_same( 'by e-mail', array( $g2, $t1, $t2 ), $ids( Listing::filter( $all, array( 'search' => 'NOAH@example' ) ) ) );
check_same( 'by handle', array( $w1 ), $ids( Listing::filter( $all, array( 'search' => 'reef_p' ) ) ) );
check_same( 'by fish, whatever the case and the spacing', array( $w1, $w2, $w3 ), $ids( Listing::filter( $all, array( 'search' => 'blue  TEST wrasse' ) ) ) );
check_same( 'part of a word', array( $t1, $t2 ), $ids( Listing::filter( $all, array( 'search' => 'tang' ) ) ) );
check_same( 'nothing found', array(), Listing::filter( $all, array( 'search' => 'unicorn' ) ) );
check_same( 'search and a filter together', array( $g1 ), $ids( Listing::filter( $all, array( 'search' => 'sam', 'state' => 'ready' ) ) ) );
check_same( 'the store\'s search finds the same', $ids( Listing::filter( $all, array( 'search' => 'sam' ) ) ), $ids( $store->find( array( 'search' => 'sam' ) ) ) );

echo "\nSorting\n";
check_same( 'by date, the oldest first', $g2, Listing::sort( $all, 'created', 'asc' )[0]['id'] );
check_same( 'by date, the newest first', $t3, Listing::sort( $all, 'created', 'desc' )[0]['id'] );
$by_name = Listing::sort( $all, 'name', 'asc' );
check_same( 'by name', array( 'Lee Local', 'Sam Sample' ), array( $by_name[0]['name'], $by_name[10]['name'] ) );
check_same( 'the same name: the oldest request first', array( $w3, $t3 ), array( $by_name[0]['id'], $by_name[1]['id'] ) );
check_same( 'by name, Z to A', 'Sam Sample', Listing::sort( $all, 'name', 'desc' )[0]['name'] );
check_same( 'by fish', array( 'Blue Test Wrasse', 'Yellow Test Blenny' ), array( Listing::sort( $all, 'fish', 'asc' )[0]['fish'], Listing::sort( $all, 'fish', 'asc' )[10]['fish'] ) );
$by_state = array_column( Listing::sort( $all, 'state', 'asc' ), 'state' );
check_same( 'by state, in travel order', array( 'new', 'filled' ), array( $by_state[0], $by_state[10] ) );
check_same( 'by quantity, the most first', $w2, Listing::sort( $all, 'qty', 'desc' )[0]['id'] );
check_same( 'an unknown sort falls back to the date', $g2, Listing::sort( $all, 'colour', 'asc' )[0]['id'] );

echo "\nGrouped by fish\n";
$groups = Listing::groups( $open, $now );
$titles = array_column( $groups, 'title' );
check_same( 'one group per fish', 6, count( $groups ) );
check_same( 'the group that has waited longest comes first', 'Blue Test Wrasse — 3 waiting (4 fish) · longest 4 months', $titles[0] );
check_same( 'spellings that differ only in case and spacing are one fish', array( $w1, $w2, $w3 ), $ids( $groups[0]['rows'] ) );
check_same( 'a species-list fish groups its requests under one id, whatever they typed', array( $t1, $t2 ), $ids( $groups[ array_search( 's:31', array_column( $groups, 'key' ), true ) ]['rows'] ) );
check_contains( 'one request, one fish: no fish count', 'Red Test Goby — 1 waiting · longest 3 months', $titles );
check_same( 'the rows of a group are the oldest first', $w1, $groups[0]['rows'][0]['id'] );

$with_closed = Listing::groups( $all, $now );
check_contains( 'closed requests are counted apart', 'Red Test Goby — 1 waiting · longest 3 months · 1 closed', array_column( $with_closed, 'title' ) );
$only_closed = Listing::groups( Listing::filter( $all, array( 'state' => 'filled' ) ), $now );
check_same( 'a group with nothing open says so', 'Red Test Goby — 1 closed', $only_closed[0]['title'] );

check_same( 'groups by the most fish asked for', 'Blue Test Wrasse', Listing::groups( $open, $now, 'most' )[0]['fish'] );
check_same( 'groups by name', array( 'Blue Test Wrasse', 'Yellow Test Blenny' ), array( Listing::groups( $open, $now, 'name' )[0]['fish'], Listing::groups( $open, $now, 'name' )[5]['fish'] ) );
check_same( 'a search narrows the groups', array( 'Red Test Goby — 1 waiting · longest 3 months' ), array_column( Listing::groups( Listing::filter( $open, array( 'search' => 'goby' ) ), $now ), 'title' ) );
check_same( 'no rows, no groups', array(), Listing::groups( array(), $now ) );

echo "\nNeeds you\n";
$needs = Listing::needs_you( $open, $now, array( 'reminder_days' => 3 ) );
check_same( 'new requests to approve', array( $t1, $t2, $t3 ), $ids( $needs['approve'] ) );
check_same( 'payments overdue: unpaid past the reminder day', array( $p1 ), $ids( $needs['overdue'] ) );
check_same( 'people to tell: ready and not yet told', array( $g1 ), $ids( $needs['tell'] ) );
check_same( 'owed fish that were told: to send', array( $o1 ), $ids( $needs['owed'] ) );
check_same( 'the count on the tab', 6, $needs['count'] );
check_same( 'nothing to do, nothing listed', 0, Listing::needs_you( array(), $now, array() )['count'] );

$sheets = Listing::sheets( $needs['approve'] );
check_same( 'the new requests come as sheets: two', 2, count( $sheets ) );
check_same( 'a sheet keeps its fish together', array( $t1, $t2 ), $ids( $sheets[0]['rows'] ) );
check_same( 'with the fee its link proposed', 12.0, $sheets[0]['fee'] );
check_same( 'and who sent it', array( 'Noah Newcomer', 'noah@example.test' ), array( $sheets[0]['who'], $sheets[0]['email'] ) );
check_same( 'a link without a fee proposes none', 0.0, $sheets[1]['fee'] );

echo "\nWho has waited longest\n";
$longest = Listing::longest( $all, $now, 3 );
check_same( 'the three oldest OPEN requests', array( $w1, $o1, $g1 ), $ids( $longest ) );
check_same( 'each with its wait in words', array( '4 months', '3 months', '3 months' ), array_column( $longest, 'age' ) );

echo "\nDemand\n";
$demand = $store->demand();
$wrasse = $demand[ array_search( 't:blue test wrasse', array_column( $demand, 'fish_key' ), true ) ];
check_same( 'open requests and fish, all-time requests and fish', array( 3, 4, 3, 4 ), array( $wrasse['open_requests'], $wrasse['open_qty'], $wrasse['all_requests'], $wrasse['all_qty'] ) );
$goby = $demand[ array_search( 't:red test goby', array_column( $demand, 'fish_key' ), true ) ];
check_same( 'a filled request counts all-time, not as open', array( 1, 2 ), array( $goby['open_requests'], $goby['all_requests'] ) );

$ordered = array(
	'years' => array( 2024, 2026, 2025, 2023, 2022, 2021 ),
	'rows'  => array(
		't:blue test wrasse' => array( 'species' => 'Blue Test Wrasse', 'species_id' => null, 'years' => array( 2026 => 2, 2025 => 4 ), 'total' => 6 ),
		's:31'               => array( 'species' => 'Green Test Tang', 'species_id' => 31, 'years' => array( 2024 => 3 ), 'total' => 3 ),
		't:pink test basslet' => array( 'species' => 'Pink Test Basslet', 'species_id' => null, 'years' => array( 2021 => 9 ), 'total' => 9 ),
	),
);
$table   = Listing::demand( $demand, $ordered, 5 );
check_same( 'the five newest years, newest first', array( 2026, 2025, 2024, 2023, 2022 ), $table['years'] );
check_same( 'the most requested fish comes first', 'Blue Test Wrasse', $table['rows'][0]['fish'] );
check_same( 'beside what past supplier orders ordered, per year', array( 2026 => 2, 2025 => 4 ), $table['rows'][0]['ordered'] );
$tang = null;
foreach ( $table['rows'] as $fh_demand_row ) {
	if ( array( 2024 => 3 ) === $fh_demand_row['ordered'] ) {
		$tang = $fh_demand_row;
	}
}
check( 'a species-list fish finds its history by id, whatever name was typed', null !== $tang && 2 === $tang['open_requests'] );
$basslet = $table['rows'][ count( $table['rows'] ) - 1 ];
check_same( 'a fish that was only ever ordered is listed as past demand', array( 'Pink Test Basslet', 0, 9 ), array( $basslet['fish'], $basslet['all_requests'], $basslet['ordered_total'] ) );
$alone = Listing::demand( $demand, array() );
check_same( 'without the Clean Room the requests stand alone', array( array(), 6, array() ), array( $alone['years'], count( $alone['rows'] ), $alone['rows'][0]['ordered'] ) );

fh_finish();
