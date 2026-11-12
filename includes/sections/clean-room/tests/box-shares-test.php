<?php
/**
 * Percent of a box on the order lines, and the remembered shares. With
 * invented suppliers, fish and numbers on the in-memory Fake_Store: the
 * schema (the new columns, the box_shares table, the version gate that
 * opens only once every added column is there), a line's box number and
 * percent from a form (blank, 0, out of range, junk), an order save that
 * refuses a mistyped percent before anything is written, the Boxes
 * card's chips and total and the remembered-share maths against the
 * fixture the page's JS reads too, what a save remembers (the percent ÷
 * the received count, per supplier + origin + fish + size), the count
 * going up once per order line, the latest order winning, a line that
 * changes fish / size, loses its percent or goes away giving its count
 * back, the suggestion on the next order from the same place (never
 * saved by itself), "How full", the Settings → Box shares save
 * (capability, nonce, invalid input, change, forget) and the exports.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/box-shares-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

$today    = '2026-05-20';
$settings = Settings::get();
$fixture  = fh_box_fixture();

/**
 * A store with one "size" attribute (three sizes) and one species row.
 */
function fh_box_store() {
	$store                  = new Fake_Store( array( 21 => 'Blue Test Wrasse' ) );
	$store->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms      = array(
		'pa_size' => array(
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
			array( 'term_id' => 31, 'name' => '3-4"', 'slug' => '3-4', 'menu_order' => 2 ),
		),
	);
	$store->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'per_box' => 8, 'my_price' => 40 ) ) );
	return $store;
}

/**
 * Save an order through the editor's save (header + lines), as the page posts it.
 */
function fh_save( $store, $id, array $header, array $lines ) {
	global $today;
	return Admin_Workbook::save_order( $store, array_merge( array( 'cost_mode' => 'box', 'status' => 'received' ), $header, array( 'lines' => $lines ) ), $id, $today );
}

/**
 * The lines of a stored order as the form would post them back.
 */
function fh_form_lines( $store, $order_id ) {
	$out = array();
	foreach ( $store->order( $order_id )['lines'] as $line ) {
		$out[] = array(
			'id'           => $line['id'],
			'species_id'   => (string) $line['species_id'],
			'species'      => $line['species'],
			'size_slug'    => (string) $line['size_slug'],
			'qty_ordered'  => $line['qty_ordered'],
			'qty_received' => null === $line['qty_received'] ? '' : $line['qty_received'],
			'box_no'       => null === $line['box_no'] ? '' : $line['box_no'],
			'box_pct'      => null === $line['box_pct'] ? '' : $line['box_pct'],
			'unit_price'   => $line['unit_price'],
		);
	}
	return $out;
}

/**
 * The remembered share of a fish + size at Test Supplier One / an origin.
 */
function fh_share_of( $store, $fish_key, $size, $origin = 'Testland' ) {
	foreach ( $store->box_shares( array( 'location_key' => Box_Shares::location_key( 'Test Supplier One', $origin ) ) ) as $share ) {
		if ( $share['fish_key'] === $fish_key && $share['size_slug'] === $size ) {
			return $share;
		}
	}
	return null;
}

echo "1. The schema: the new columns, the table, the version gate\n";
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$tables          = array();
foreach ( Store::schema( 'DEFAULT CHARSET=utf8mb4' ) as $statement ) {
	if ( 1 === preg_match( '/^CREATE TABLE wp_fh_([a-z_]+) \(/', $statement, $m ) ) {
		$tables[ $m[1] ] = $statement;
	}
}
check( 'schema version 1.4 or later; ten tables, box_shares among them, one CREATE per table', version_compare( Store::DB_VERSION, '1.4', '>=' ) && 10 === count( Store::TABLES ) && in_array( 'box_shares', Store::TABLES, true ) && array_keys( $tables ) === Store::TABLES );
check( 'fh_order_lines: box_no and box_pct beside per_box, the size, the share key — each nullable, on its own line', 1 === preg_match( "/\n\t+per_box SMALLINT NULL,\n\t+box_no SMALLINT NULL,\n\t+box_pct DECIMAL\(6,2\) NULL,\n/", $tables['order_lines'] ) && 1 === preg_match( "/\n\t+size_slug VARCHAR\(100\) NULL,\n\t+size_label VARCHAR\(100\) NULL,\n/", $tables['order_lines'] ) && 1 === preg_match( "/\n\t+share_key CHAR\(32\) NULL,\n/", $tables['order_lines'] ) );
check( 'fh_stays: the size', 1 === preg_match( "/\n\t+size_slug VARCHAR\(100\) NULL,\n\t+size_label VARCHAR\(100\) NULL,\n/", $tables['stays'] ) );
check( 'fh_box_shares: the key, the location, the fish, the size, the percent per fish, the count, the line and date of the value', 1 === preg_match( "/\n\t+share_key CHAR\(32\) NOT NULL,\n\t+location_key CHAR\(32\) NOT NULL,\n/", $tables['box_shares'] ) && 1 === preg_match( "/\n\t+pct_per_fish DECIMAL\(9,4\) NOT NULL DEFAULT 0,\n\t+order_count INT NOT NULL DEFAULT 0,\n\t+last_line_id BIGINT\(20\) UNSIGNED NULL,\n\t+last_date DATE NULL,\n/", $tables['box_shares'] ) && false !== strpos( $tables['box_shares'], "PRIMARY KEY  (id),\n" ) && false !== strpos( $tables['box_shares'], 'UNIQUE KEY share_key (share_key)' ) && false !== strpos( $tables['box_shares'], 'KEY location_key (location_key)' ) );
$all_in_schema = true;
$present       = array();
foreach ( Store::REQUIRED_COLUMNS as $table => $columns ) {
	$present[ $table ] = array_merge( array( 'id' ), $columns );
	foreach ( $columns as $column ) {
		$all_in_schema = $all_in_schema && 1 === preg_match( "/\n\t+" . preg_quote( $column, '/' ) . ' [A-Z]/', $tables[ $table ] );
	}
}
check( 'every column the gate waits for is in the schema of its table', $all_in_schema && array( 'orders', 'order_lines', 'stays' ) === array_keys( Store::REQUIRED_COLUMNS ) );
check( 'the gate opens once every added column is there', Store::schema_ready( $present ) && array() === Store::missing_columns( $present ) );
foreach ( array( array( 'order_lines', 'box_pct' ), array( 'order_lines', 'box_no' ), array( 'order_lines', 'share_key' ), array( 'order_lines', 'size_slug' ), array( 'stays', 'size_label' ), array( 'orders', 'sale_date' ) ) as $pair ) {
	$short                = $present;
	$short[ $pair[0] ]    = array_values( array_diff( $short[ $pair[0] ], array( $pair[1] ) ) );
	check( 'without ' . $pair[0] . '.' . $pair[1] . ' the gate stays shut (the version is not written, the ALTER is retried)', ! Store::schema_ready( $short ) && array( $pair[0] . '.' . $pair[1] ) === Store::missing_columns( $short ) );
}
check( 'a table that could not be read at all keeps it shut; column names compare case-insensitively', ! Store::schema_ready( array() ) && ! Store::schema_ready( array( 'orders' => array( 'sale_date' ) ) ) && Store::schema_ready( array_map( static function ( $columns ) { return array_map( 'strtoupper', $columns ); }, $present ) ) );

echo "2. A line's box number and percent from a form\n";
$l = Orders::normalize_line( array( 'species' => 'Blue Test Wrasse', 'box_no' => ' 2 ', 'box_pct' => '25.5' ) );
check( 'typed: box 2, 25.5 %', 2 === $l['box_no'] && 25.5 === $l['box_pct'] );
$l = Orders::normalize_line( array( 'species' => 'Blue Test Wrasse' ) );
check( 'not posted: both null (an imported line, an older form)', null === $l['box_no'] && null === $l['box_pct'] && null === $l['size_slug'] );
$l = Orders::normalize_line( array( 'species' => 'x', 'box_no' => '0', 'box_pct' => '401' ) );
check( 'out of range reads as none here (the save names the mistake first)', null === $l['box_no'] && null === $l['box_pct'] );
$rows = array(
	array( 'species' => 'Blue Test Wrasse', 'box_no' => '1', 'box_pct' => '25' ),
	array( 'species' => '', 'box_pct' => '999' ),
	array( 'species' => 'Red Test Goby', 'box_no' => '', 'box_pct' => '' ),
	array( 'species' => 'Green Test Blenny', 'box_pct' => '0' ),
	'junk',
	array( 'species' => 'Yellow Test Goby', 'box_no' => '2', 'box_pct' => '400' ),
);
check( 'well-typed lines: no mistake — blank is none, 0 is none, 400 is allowed, a row without a species is skipped', array() === Orders::line_errors( $rows ) && array() === Orders::line_errors( null ) );
$errors = Orders::line_errors(
	array(
		array( 'species' => 'Blue Test Wrasse', 'box_pct' => '400.01' ),
		array( 'species' => '' ),
		array( 'species' => 'Red Test Goby', 'box_pct' => '-5', 'box_no' => '1000' ),
		array( 'species' => 'Green Test Blenny', 'box_pct' => 'half', 'box_no' => '1.5' ),
	)
);
check( 'mistyped: each one named with its line number (as the saved lines are numbered) and its fish', 5 === count( $errors ) && 'Line 1 (Blue Test Wrasse): the % of box must be above 0 and at most 400.' === $errors[0] && 0 === strpos( $errors[1], 'Line 2 (Red Test Goby): the % of box' ) && 'Line 2 (Red Test Goby): the box number must be a whole number from 1 to 999.' === $errors[2] && 0 === strpos( $errors[3], 'Line 3 (Green Test Blenny): the % of box' ) && 0 === strpos( $errors[4], 'Line 3 (Green Test Blenny): the box number' ), json_encode( $errors ) );
$line = Store::hydrate_line( array( 'id' => 5, 'order_id' => 1, 'species' => 'x', 'box_no' => '3', 'box_pct' => '12.50', 'share_key' => str_repeat( 'a', 32 ) ) );
check( 'a stored line reads box 3, 12.5 %, its share key; all null when empty', 3 === $line['box_no'] && 12.5 === $line['box_pct'] && str_repeat( 'a', 32 ) === $line['share_key'] && null === Store::hydrate_line( array( 'id' => 6 ) )['box_pct'] && null === Store::hydrate_line( array( 'id' => 6, 'share_key' => '' ) )['share_key'] );

echo "3. The order save: a mistyped percent refuses the save, a good one costs the order\n";
$store = fh_box_store();
$r     = fh_save(
	$store,
	0,
	array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-03-10' ),
	array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'qty_received' => 4, 'box_pct' => '450', 'unit_price' => 5 ) )
);
check( 'refused, with the line named, and NOTHING was saved (no order, no line, no share)', isset( $r['error'] ) && false !== strpos( $r['error'], 'Line 1 (Blue Test Wrasse)' ) && false !== strpos( $r['error'], 'Nothing was saved.' ) && array() === $store->orders && array() === $store->lines && array() === $store->box_shares );
$r = fh_save(
	$store,
	0,
	array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-03-10', 'domestic_flight' => 100, 'import_fees' => 200, 'boxes' => 1 ),
	array(
		array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'box_no' => 1, 'box_pct' => '50', 'unit_price' => 5 ),
		array( 'species' => 'Red Test Goby', 'qty_ordered' => 10, 'qty_received' => 10, 'box_no' => 1, 'box_pct' => '30', 'unit_price' => 2 ),
		array( 'species' => 'Green Test Blenny', 'qty_ordered' => 5, 'qty_received' => 0, 'box_pct' => '20', 'unit_price' => 9 ),
		array( 'species' => 'Yellow Test Goby', 'qty_ordered' => 6, 'qty_received' => 6, 'unit_price' => 1 ),
	)
);
$a     = $r['order_id'];
$order = Admin_Workbook::resolve_order( $store, $store->order( $a ) );
check( 'saved: the lines keep their box number and percent', empty( $r['error'] ) && array( 1, 1, null, null ) === array_column( $order['lines'], 'box_no' ) && array( 50.0, 30.0, 20.0, null ) === array_column( $order['lines'], 'box_pct' ) );
/*
 * Fractions: 0.5 (percent) · 0.3 (percent) · 0 (nothing received) · 6 ÷ 24 = 0.25 (per box) → Σ 1.05
 * fees per box = 300 ÷ 1.05 ; landed 1 = 20 + 0.5 × 300 ÷ 1.05
 */
check( 'the costing uses the percents: fractions 0.5 / 0.3 / 0 / 0.25, fees per box 300 ÷ 1.05', near( $order['lines'][0]['fraction'], 0.5 ) && near( $order['lines'][1]['fraction'], 0.3 ) && near( $order['lines'][2]['fraction'], 0 ) && near( $order['lines'][3]['fraction'], 0.25 ) && near( $order['costing']['fees_per_box'], 300 / 1.05 ) && near( $order['lines'][0]['landed_total'], 20 + 0.5 * 300 / 1.05 ) );
check( 'the Save reply carries the figures, and the location\'s remembered shares for the page', isset( $r['figures']['lines'][0]['units'] ) && '0.5' === $r['figures']['lines'][0]['units'] && 2 === count( $r['shares'] ) && array( 'fish_key', 'name_key', 'size_slug', 'pct_per_fish', 'order_count' ) === array_keys( $r['shares'][0] ) && array() === $r['warnings'] );

echo "4. The Boxes card: chips, total, \"How full\" (the fixture the page's JS reads too)\n";
foreach ( $fixture['summary'] as $case ) {
	$summary = Costing::box_summary( $case['lines'], $case['boxes'] );
	check_json( 'chips — ' . $case['name'], $case['rows'], Admin_Workbook::box_summary_rows( $summary ) );
	check( 'total — ' . $case['name'], $case['total'] === Admin_Workbook::box_total_text( $summary ), Admin_Workbook::box_total_text( $summary ) );
}
$panel = Admin_Workbook::boxes_panel( $order, $store->box_shares() );
check( 'this order: box 1 holds 80 % (under), the 20 % of the line without a box number apart, 1 box in all beside Boxes = 1 — and the one received line still without a percent is counted', array( array( 'state' => 'under', 'text' => 'Box 1 · 80% · under' ), array( 'state' => 'unboxed', 'text' => 'no box number · 20% · lines: 1' ) ) === $panel['rows'] && 'Boxes by the percents: 1 · Boxes field: 1 · received lines without a %: 1 (still on their per-box count)' === $panel['total'] && near( $panel['summary']['pct_total'], 100 ) && 1 === $panel['summary']['missing'], $panel['total'] );
/*
 * Why the card counts them: one box, 200 in fees, line A at 25 % and one big fish left blank
 * (1 ÷ 24 of a box). A pays 0.25 ÷ (0.25 + 1 ÷ 24) of the fees; with the blank line at 25 % too,
 * half. And raising A alone by a tenth moves the split — the percents are only "right compared
 * to each other" once every received line has one.
 */
$half = static function ( $pct_a, $pct_b ) {
	$lines = array( array( 'species' => 'A', 'qty_ordered' => 1, 'qty_received' => 1, 'box_no' => 1, 'box_pct' => $pct_a, 'unit_price' => 0 ), array( 'species' => 'B', 'qty_ordered' => 1, 'qty_received' => 1, 'box_pct' => $pct_b, 'unit_price' => 0 ) );
	$cost  = Costing::compute( array( 'cost_mode' => 'box', 'domestic_flight' => 100, 'import_fees' => 100 ), $lines );
	return array( round( $cost['lines'][0]['landed_total'], 2 ), round( $cost['lines'][1]['landed_total'], 2 ), Admin_Workbook::box_total_text( Costing::box_summary( $lines, 1 ) ) );
};
check( 'a half-filled order: 25 % beside a blank line splits 171.43 / 28.57 and says "received lines without a %: 1"', array( 171.43, 28.57, 'Boxes by the percents: 0.25 · Boxes field: 1 · received lines without a %: 1 (still on their per-box count)' ) === $half( '25', '' ), json_encode( $half( '25', '' ) ) );
check( '…every guess a little high only gives the same costs once every received line has a percent: 25 / 25 and 27.5 / 27.5 both split 100 / 100, the card silent', array( 100.0, 100.0, 'Boxes by the percents: 0.5 · Boxes field: 1' ) === $half( '25', '25' ) && array( 100.0, 100.0 ) === array_slice( $half( '27.5', '27.5' ), 0, 2 ) && array( 173.68, 26.32 ) === array_slice( $half( '27.5', '' ), 0, 2 ) );
check( 'a received order shows no "How full" line; a draft or an ordered one does', '' === $panel['how_full'] && '' !== Admin_Workbook::boxes_panel( array_merge( $order, array( 'status' => 'ordered' ) ), array() )['how_full'] && '' !== Admin_Workbook::boxes_panel( array_merge( $order, array( 'status' => 'draft' ) ), array() )['how_full'] && '' === Admin_Workbook::boxes_panel( array_merge( $order, array( 'status' => 'closed' ) ), array() )['how_full'] );
check( 'the page and its JS fill the same templates: every sentence is in one table', array( 'box', 'unboxed', 'under', 'full', 'over', 'none', 'total', 'totalField', 'missing', 'suggest1', 'suggestN', 'howNone', 'howNothing', 'howFull' ) === array_keys( Admin_Workbook::box_strings() ) );
check( 'the chips take a translated table', 'Kiste 1: 95.5 (voll)' === Admin_Workbook::box_summary_rows( array( 'boxes' => array( array( 'box_no' => 1, 'pct' => 95.5, 'lines' => 1, 'state' => 'full' ) ), 'unboxed' => array( 'pct' => 0, 'lines' => 0 ) ), array_merge( Admin_Workbook::box_strings(), array( 'box' => 'Kiste %1$s: %2$s (%3$s)', 'full' => 'voll' ) ) )[0]['text'] );

echo "5. Keys: a location, a fish, a share\n";
check( 'a location is supplier + origin, case and spacing aside', Box_Shares::location_key( 'Test Supplier One', 'Testland' ) === Box_Shares::location_key( '  test  SUPPLIER one ', 'TESTLAND' ) && Box_Shares::location_key( 'Test Supplier One', 'Testland' ) !== Box_Shares::location_key( 'Test Supplier One', 'Otherland' ) && Box_Shares::location_key( 'Test Supplier One', '' ) !== Box_Shares::location_key( '', 'Test Supplier One' ) && 32 === strlen( Box_Shares::location_key( '', '' ) ) );
check( 'a fish is its species list id when the line has one, else its lower-cased name', 's:7' === Box_Shares::fish_key( array( 'species_id' => 7, 'species' => 'Blue Test Wrasse' ) ) && 'n:blue test wrasse' === Box_Shares::fish_key( array( 'species_id' => null, 'species' => ' Blue  TEST Wrasse ' ) ) && 'n:blue test wrasse' === Box_Shares::name_key( array( 'species_id' => 7, 'species' => 'Blue Test Wrasse' ) ) && '' === Box_Shares::fish_key( array( 'species' => '  ' ) ) );
$loc = Box_Shares::location_key( 'Test Supplier One', 'Testland' );
check( 'a share is location + fish + size: another size, another fish or another place is another share', Box_Shares::share_key( $loc, 's:7', '2-3' ) !== Box_Shares::share_key( $loc, 's:7', '3-4' ) && Box_Shares::share_key( $loc, 's:7', '2-3' ) !== Box_Shares::share_key( $loc, 's:8', '2-3' ) && Box_Shares::share_key( $loc, 's:7', '' ) !== Box_Shares::share_key( $loc, 's:7', '2-3' ) && Box_Shares::line_key( array( 'supplier' => 'test supplier one', 'origin' => ' Testland' ), array( 'species_id' => 7, 'size_slug' => '2-3' ) ) === Box_Shares::share_key( $loc, 's:7', '2-3' ) );

echo "6. What is remembered: eligible lines, the percent per fish, the order's date\n";
$box = array( 'cost_mode' => 'box' );
check( 'a box-mode line with a percent, received fish and a fish is remembered', Box_Shares::eligible( $box, array( 'species' => 'x', 'qty_received' => 2, 'box_pct' => 25 ) ) );
check( 'not without a percent, not with nothing received, not without a fish, not in bag mode', ! Box_Shares::eligible( $box, array( 'species' => 'x', 'qty_received' => 2, 'box_pct' => null ) ) && ! Box_Shares::eligible( $box, array( 'species' => 'x', 'qty_received' => 0, 'box_pct' => 25 ) ) && ! Box_Shares::eligible( $box, array( 'species' => '', 'qty_received' => 2, 'box_pct' => 25 ) ) && ! Box_Shares::eligible( array( 'cost_mode' => 'bag' ), array( 'species' => 'x', 'qty_received' => 2, 'box_pct' => 25 ) ) );
check( 'never for a fish that still needs identifying (the flag a line may carry)', ! Box_Shares::eligible( $box, array( 'species' => 'x', 'qty_received' => 2, 'box_pct' => 25, 'needs_id' => 1 ) ) && Box_Shares::eligible( $box, array( 'species' => 'x', 'qty_received' => 2, 'box_pct' => 25, 'needs_id' => 0 ) ) );
check( 'the value is the line\'s percent ÷ its received count, to four decimals', 12.5 === Box_Shares::per_fish( array( 'qty_received' => 2, 'box_pct' => 25 ) ) && 8.3333 === Box_Shares::per_fish( array( 'qty_received' => 3, 'box_pct' => 25 ) ) && null === Box_Shares::per_fish( array( 'qty_received' => 0, 'box_pct' => 25 ) ) && null === Box_Shares::per_fish( array( 'qty_received' => 3 ) ) );
check( 'an order\'s guesses are dated: received, else ordered, else today', '2026-03-10' === Box_Shares::order_date( array( 'received_date' => '2026-03-10', 'order_date' => '2026-03-01' ), $today ) && '2026-03-01' === Box_Shares::order_date( array( 'received_date' => null, 'order_date' => '2026-03-01' ), $today ) && $today === Box_Shares::order_date( array(), $today ) && '' === Box_Shares::order_date( array(), 'soon' ) );
check( 'which guess is the latest: the same line, a share without a date, or an order dated on or after the value\'s', Box_Shares::is_latest( array( 'last_line_id' => 4, 'last_date' => '2026-04-02' ), 4, '2026-01-01' ) && Box_Shares::is_latest( array( 'last_line_id' => null, 'last_date' => null ), 9, '' ) && Box_Shares::is_latest( array( 'last_line_id' => 4, 'last_date' => '2026-04-02' ), 9, '2026-04-02' ) && ! Box_Shares::is_latest( array( 'last_line_id' => 4, 'last_date' => '2026-04-02' ), 9, '2026-04-01' ) && ! Box_Shares::is_latest( array( 'last_line_id' => 4, 'last_date' => '2026-04-02' ), 9, '' ) );

echo "7. Saving an order remembers its percents — once per line\n";
$wrasse = fh_share_of( $store, 's:1', '2-3' );
$goby   = fh_share_of( $store, 'n:red test goby', '' );
$lines  = $store->order( $a )['lines'];
check( 'two shares from order 2026-1: the wrasse by its species id and size, the goby by its name without a size', 2 === count( $store->box_shares ) && null !== $wrasse && null !== $goby && 1 === $wrasse['species_id'] && null === $goby['species_id'] && 'Blue Test Wrasse' === $wrasse['species'] && '2-3"' === $wrasse['size_label'] && '' === $goby['size_label'] );
check( 'the value is percent ÷ received: 50 ÷ 4 = 12.5 and 30 ÷ 10 = 3', 12.5 === $wrasse['pct_per_fish'] && 3.0 === $goby['pct_per_fish'] );
check( 'each came from one order line, dated by the order', 1 === $wrasse['order_count'] && 1 === $goby['order_count'] && $lines[0]['id'] === $wrasse['last_line_id'] && '2026-03-10' === $wrasse['last_date'] && 'Test Supplier One' === $wrasse['supplier'] && 'Testland' === $wrasse['origin'] && $loc === $wrasse['location_key'] );
check( 'the line with nothing received and the line without a percent are not remembered; the lines know the share they count in', $wrasse['share_key'] === $lines[0]['share_key'] && $goby['share_key'] === $lines[1]['share_key'] && null === $lines[2]['share_key'] && null === $lines[3]['share_key'] );
$header_a = array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-03-10', 'domestic_flight' => 100, 'import_fees' => 200, 'boxes' => 1 );
fh_save( $store, $a, $header_a, fh_form_lines( $store, $a ) );
fh_save( $store, $a, $header_a, fh_form_lines( $store, $a ) );
check( 'saving the same order again (twice): still one line each, the same values', 2 === count( $store->box_shares ) && 1 === fh_share_of( $store, 's:1', '2-3' )['order_count'] && 1 === fh_share_of( $store, 'n:red test goby', '' )['order_count'] && 12.5 === fh_share_of( $store, 's:1', '2-3' )['pct_per_fish'] );
check( 'remember() reports what it did: nothing on an unchanged order', array( 'written' => 0, 'counted' => 0, 'released' => 0 ) === Box_Shares::remember( $store, $store->order( $a ), $store->order( $a )['lines'], $today ) );

echo "8. The latest order wins\n";
$r = fh_save(
	$store,
	0,
	array( 'ref' => '2026-2', 'supplier' => ' test supplier ONE ', 'origin' => 'testland', 'received_date' => '2026-04-02' ),
	array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_received' => 2, 'box_pct' => '30', 'unit_price' => 5 ) )
);
$b = $r['order_id'];
$w = fh_share_of( $store, 's:1', '2-3' );
check( 'a later order from the same place (typed in another case): the same share, counted twice, the newer value 30 ÷ 2 = 15', 2 === count( $store->box_shares ) && 2 === $w['order_count'] && 15.0 === $w['pct_per_fish'] && '2026-04-02' === $w['last_date'] && $store->order( $b )['lines'][0]['id'] === $w['last_line_id'] );
$form               = fh_form_lines( $store, $a );
$form[0]['box_pct'] = '60';
fh_save( $store, $a, $header_a, $form );
$w = fh_share_of( $store, 's:1', '2-3' );
check( 're-saving the OLDER order with another percent does not overwrite the newer guess, nor count again', 15.0 === $w['pct_per_fish'] && 2 === $w['order_count'] && '2026-04-02' === $w['last_date'] && 60.0 === $store->order( $a )['lines'][0]['box_pct'] );
$form               = fh_form_lines( $store, $b );
$form[0]['box_pct'] = '40';
fh_save( $store, $b, array( 'ref' => '2026-2', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-04-02' ), $form );
$w = fh_share_of( $store, 's:1', '2-3' );
check( 'correcting the very line the value came from replaces it: 40 ÷ 2 = 20', 20.0 === $w['pct_per_fish'] && 2 === $w['order_count'] );
$r = fh_save(
	$store,
	0,
	array( 'ref' => '2026-3', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-02-01' ),
	array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_received' => 1, 'box_pct' => '9', 'unit_price' => 5 ) )
);
$c = $r['order_id'];
$w = fh_share_of( $store, 's:1', '2-3' );
check( 'an order dated EARLIER, saved for the first time: it counts (3 lines now) but the newer value stays', 3 === $w['order_count'] && 20.0 === $w['pct_per_fish'] && '2026-04-02' === $w['last_date'] );
$other = fh_save( $store, 0, array( 'ref' => '2026-4', 'supplier' => 'Test Supplier One', 'origin' => 'Otherland', 'received_date' => '2026-04-20' ), array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_received' => 1, 'box_pct' => '70', 'unit_price' => 5 ) ) );
check( 'another origin is another location: its own share, the first one untouched', 3 === count( $store->box_shares ) && 20.0 === fh_share_of( $store, 's:1', '2-3' )['pct_per_fish'] && 70.0 === fh_share_of( $store, 's:1', '2-3', 'Otherland' )['pct_per_fish'] && 1 === count( $store->box_shares( array( 'location_key' => Box_Shares::location_key( 'Test Supplier One', 'Otherland' ) ) ) ) && 2 === count( $store->box_shares( array( 'location_key' => $loc ) ) ) );
$store->delete_order( $other['order_id'] );
Box_Shares::release_lines( $store, array( array( 'id' => 99, 'share_key' => $store->box_shares( array( 'location_key' => Box_Shares::location_key( 'Test Supplier One', 'Otherland' ) ) )[0]['share_key'] ) ) );
check( 'deleting that order gives its line\'s count back: the share nobody counts in is gone', 2 === count( $store->box_shares ) );

echo "9. A line that changes, loses its percent or goes away gives its count back\n";
$header_c            = array( 'ref' => '2026-3', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-02-01' );
$form                = fh_form_lines( $store, $c );
$form[0]['size_slug'] = '3-4';
fh_save( $store, $c, $header_c, $form );
$w   = fh_share_of( $store, 's:1', '2-3' );
$big = fh_share_of( $store, 's:1', '3-4' );
check( 'the line turns out to be another size: it leaves the old share (2 lines now) and starts its own (9 ÷ 1 = 9)', 2 === $w['order_count'] && 20.0 === $w['pct_per_fish'] && null !== $big && 9.0 === $big['pct_per_fish'] && 1 === $big['order_count'] && '3-4"' === $big['size_label'] && $big['share_key'] === $store->order( $c )['lines'][0]['share_key'] );
$form               = fh_form_lines( $store, $c );
$form[0]['box_pct'] = '';
fh_save( $store, $c, $header_c, $form );
check( 'the percent is cleared: the line counts nowhere, and the share only it counted in is deleted', null === fh_share_of( $store, 's:1', '3-4' ) && null === $store->order( $c )['lines'][0]['share_key'] && 2 === count( $store->box_shares ) );
fh_save( $store, $b, array( 'ref' => '2026-2', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-04-02' ), array() );
$w = fh_share_of( $store, 's:1', '2-3' );
check( 'the line the value came from is removed from its order: one line left, and the value loses its line and date', array() === $store->order( $b )['lines'] && 1 === $w['order_count'] && 20.0 === $w['pct_per_fish'] && null === $w['last_line_id'] && null === $w['last_date'] );
fh_save( $store, $a, $header_a, fh_form_lines( $store, $a ) );
$w = fh_share_of( $store, 's:1', '2-3' );
check( '…so the next save from any order replaces it: 60 ÷ 4 = 15, dated by that order', 15.0 === $w['pct_per_fish'] && 1 === $w['order_count'] && '2026-03-10' === $w['last_date'] && $store->order( $a )['lines'][0]['id'] === $w['last_line_id'] );
$form               = fh_form_lines( $store, $a );
$form[0]['species'] = 'Purple Test Wrasse';
$form[0]['species_id'] = '';
fh_save( $store, $a, $header_a, $form );
check( 'the fish is corrected to another one: the old share is gone with its only line, the new fish has its own', null === fh_share_of( $store, 's:1', '2-3' ) && null !== fh_share_of( $store, 'n:purple test wrasse', '2-3' ) && 15.0 === fh_share_of( $store, 'n:purple test wrasse', '2-3' )['pct_per_fish'] );
fh_save( $store, $a, array_merge( $header_a, array( 'cost_mode' => 'bag' ) ), fh_form_lines( $store, $a ) );
check( 'the order goes to bag mode: there are no boxes to remember — its lines count nowhere', array() === $store->box_shares && array( null, null, null, null ) === array_column( $store->order( $a )['lines'], 'share_key' ) );
fh_save( $store, $a, $header_a, fh_form_lines( $store, $a ) );
check( '…and back to box mode: remembered again', 2 === count( $store->box_shares ) );
$one = $store->order( $a )['lines'][1];
$did = Box_Shares::remember_line( $store, $store->order( $a ), array_merge( $one, array( 'needs_id' => 1 ) ), $today );
check( 'one line on its own (remember_line()): flagged as still to identify, it stops counting', array( 'written' => 0, 'counted' => 0, 'released' => 1 ) === $did && null === fh_share_of( $store, 'n:red test goby', '' ) && null === $store->order( $a )['lines'][1]['share_key'] );
$did = Box_Shares::remember_line( $store, $store->order( $a ), $store->order( $a )['lines'][1], $today );
check( '…and identified again, it is remembered again', array( 'written' => 1, 'counted' => 1, 'released' => 0 ) === $did && 3.0 === fh_share_of( $store, 'n:red test goby', '' )['pct_per_fish'] );
check( 'release() of a key nobody has, or of no key, changes nothing', false === Box_Shares::release( $store, str_repeat( 'f', 32 ), 1 ) && false === Box_Shares::release( $store, '', 1 ) && 0 === Box_Shares::release_lines( $store, array( array( 'id' => 1 ), 'junk' ) ) );

echo "10. The next order from the same place: the suggestion, never saved by itself\n";
foreach ( $fixture['suggest'] as $case ) {
	$suggestion = Box_Shares::suggest_for( $fixture['shares'], $case['line'] );
	check_json( 'suggest_for — ' . $case['name'], $case['expect'], $suggestion );
	if ( null !== $case['expect'] ) {
		check( 'suggest_text — ' . $case['text'], $case['text'] === Box_Shares::suggest_text( $suggestion ), Box_Shares::suggest_text( $suggestion ) );
	}
}
check( 'find(): the id first, then the name; null when the fish is not remembered; suggest() of nothing is nothing', 12.5 === Box_Shares::find( $fixture['shares'], array( 'species_id' => 7, 'species' => 'Blue Test Wrasse', 'size_slug' => '2-3' ) )['pct_per_fish'] && 99 === (int) Box_Shares::find( $fixture['shares'], array( 'species' => 'blue test wrasse', 'size_slug' => '2-3' ) )['pct_per_fish'] && null === Box_Shares::find( $fixture['shares'], array( 'species' => 'Red Test Goby', 'size_slug' => '2-3' ) ) && null === Box_Shares::suggest( null, array( 'qty_received' => 2 ) ) && null === Box_Shares::suggest( array( 'pct_per_fish' => 0, 'order_count' => 1 ), array( 'qty_received' => 2 ) ) );
$r     = fh_save(
	$store,
	0,
	array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'status' => 'ordered' ),
	array(
		array( 'species' => 'Purple Test Wrasse', 'size_slug' => '2-3', 'qty_ordered' => 6, 'unit_price' => 5 ),
		array( 'species' => 'Red Test Goby', 'qty_ordered' => 20, 'unit_price' => 2 ),
		array( 'species' => 'Orange Test Clown', 'qty_ordered' => 3, 'unit_price' => 7 ),
	)
);
$d      = $r['order_id'];
$order  = $store->order( $d );
$shares = $store->box_shares( array( 'location_key' => Box_Shares::location_key( $order['supplier'], $order['origin'] ) ) );
$hints  = array();
foreach ( $order['lines'] as $line ) {
	$hints[] = Box_Shares::suggest_for( $shares, $line );
}
check( 'a new order from the same supplier and origin: 15 × 6 ordered = 90 % and 3 × 20 = 60 % suggested; nothing for a fish never seen', near( $hints[0]['pct'], 90 ) && 1 === $hints[0]['orders'] && near( $hints[1]['pct'], 60 ) && null === $hints[2] && 'suggested 90% (from 1 order)' === Box_Shares::suggest_text( $hints[0] ) );
check( 'a suggestion is only shown: the saved lines have no percent, and nothing new was remembered', array( null, null, null ) === array_column( $order['lines'], 'box_pct' ) && array( null, null, null ) === array_column( $order['lines'], 'share_key' ) && 2 === count( $store->box_shares ) && 1 === fh_share_of( $store, 'n:purple test wrasse', '2-3' )['order_count'] );
$panel = Admin_Workbook::boxes_panel( $order, $shares );
check( 'the ordered order\'s "How full": 90 + 60 = 150 % = 1.5 boxes, one line with nothing remembered', 'How full: boxes for the ordered counts: about 1.5 · lines with a remembered share or a typed %: 2 · lines with no remembered share yet: 1' === $panel['how_full'], $panel['how_full'] );
check( 'the page gets the location\'s shares for its chips', 2 === count( $panel['shares'] ) && 2 === count( Admin_Workbook::shares_for( $store, array( 'supplier' => 'TEST SUPPLIER ONE', 'origin' => 'testland ' ) ) ) && array() === Admin_Workbook::shares_for( $store, array( 'supplier' => 'Test Supplier One', 'origin' => 'Otherland' ) ) && array() === Admin_Workbook::shares_for( $store, array( 'supplier' => array( 'x' ) ) ) );
foreach ( $fixture['how_full'] as $case ) {
	$full = Box_Shares::how_full( $case['lines'], $fixture['shares'] );
	check_json( 'how_full — ' . $case['name'], $case['expect'], $full );
	check( 'how_full_text — ' . $case['name'], $case['text'] === Box_Shares::how_full_text( $full ), Box_Shares::how_full_text( $full ) );
}
$form                    = fh_form_lines( $store, $d );
$form[0]['qty_received'] = 5;
$form[0]['box_pct']      = '80';
fh_save( $store, $d, array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'received_date' => '2026-05-19' ), $form );
$p = fh_share_of( $store, 'n:purple test wrasse', '2-3' );
check( 'once its fish are received and a percent is typed, the order teaches the share: 80 ÷ 5 = 16, two lines now', 16.0 === $p['pct_per_fish'] && 2 === $p['order_count'] && '2026-05-19' === $p['last_date'] );

/*
 * A fish picked from the species list on one order (its id) and typed on the next (its name) is
 * one fish: one share, one count per line, and a suggestion either way.
 */
$twice = fh_box_store();
$place = array( 'supplier' => 'Test Supplier One', 'origin' => 'Testland' );
$r     = fh_save( $twice, 0, array_merge( $place, array( 'ref' => '2026-20', 'received_date' => '2026-05-10' ) ), array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_ordered' => 2, 'qty_received' => 2, 'box_pct' => '25', 'unit_price' => 5 ) ) );
$typed = array( 'species' => 'blue  test wrasse', 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'unit_price' => 5 );
$page  = Admin_Workbook::shares_for( $twice, $place );
check( 'picked on one order (kept under its species-list id), typed on the next: the typed line is offered its share — 12.5 × 4 = 50 %', 's:1' === $page[0]['fish_key'] && 'n:blue test wrasse' === $page[0]['name_key'] && array( 'pct' => 50.0, 'orders' => 1, 'count' => 4 ) === Box_Shares::suggest_for( $page, $typed ) && array( 'pct' => 50.0, 'orders' => 1, 'count' => 4 ) === Box_Shares::suggest_for( $twice->box_shares( array( 'location_key' => Box_Shares::location_key( 'Test Supplier One', 'Testland' ) ) ), $typed ) );
$r     = fh_save( $twice, 0, array_merge( $place, array( 'ref' => '2026-21', 'received_date' => '2026-05-12' ) ), array( array_merge( $typed, array( 'box_pct' => '40' ) ) ) );
$two   = $r['order_id'];
$only  = array_values( $twice->box_shares );
check( '…and saving it counts in that same share — one row, 40 ÷ 4 = 10 from 2 lines, never a second row under the name', 1 === count( $only ) && 's:1' === $only[0]['fish_key'] && 10.0 === (float) $only[0]['pct_per_fish'] && 2 === (int) $only[0]['order_count'] && $only[0]['share_key'] === $twice->order( $two )['lines'][0]['share_key'] && '2026-05-12' === $only[0]['last_date'] );
fh_save( $twice, $two, array_merge( $place, array( 'ref' => '2026-21', 'received_date' => '2026-05-12' ) ), fh_form_lines( $twice, $two ) );
check( '…saving it again counts nothing twice', 1 === count( $twice->box_shares ) && 2 === (int) array_values( $twice->box_shares )[0]['order_count'] );
check( '…and the next order that picks the fish is offered the latest guess (10 %, from 2 orders), not the older one', array( 'pct' => 10.0, 'orders' => 2, 'count' => 1 ) === Box_Shares::suggest_for( Admin_Workbook::shares_for( $twice, $place ), array( 'species_id' => 1, 'species' => 'Blue Test Wrasse', 'size_slug' => '2-3', 'qty_ordered' => 1 ) ) );
$form               = fh_form_lines( $twice, $two );
$form[0]['box_pct'] = '';
fh_save( $twice, $two, array_merge( $place, array( 'ref' => '2026-21', 'received_date' => '2026-05-12' ) ), $form );
check( '…a typed line that loses its percent gives its count back to that share', 1 === count( $twice->box_shares ) && 1 === (int) array_values( $twice->box_shares )[0]['order_count'] && null === $twice->order( $two )['lines'][0]['share_key'] );
$other = fh_box_store();
fh_save( $other, 0, array_merge( $place, array( 'ref' => '2026-30', 'received_date' => '2026-05-10' ) ), array( array_merge( $typed, array( 'box_pct' => '40' ) ) ) );
fh_save( $other, 0, array_merge( $place, array( 'ref' => '2026-31', 'received_date' => '2026-05-12' ) ), array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '2-3', 'qty_ordered' => 2, 'qty_received' => 2, 'box_pct' => '25', 'unit_price' => 5 ) ) );
check( 'the other way round — typed first, picked next: one share too (kept under the name), 25 ÷ 2 = 12.5 from 2 lines', 1 === count( $other->box_shares ) && 'n:blue test wrasse' === array_values( $other->box_shares )[0]['fish_key'] && 12.5 === (float) array_values( $other->box_shares )[0]['pct_per_fish'] && 2 === (int) array_values( $other->box_shares )[0]['order_count'] );
fh_save( $other, 0, array_merge( $place, array( 'ref' => '2026-32', 'received_date' => '2026-05-13' ) ), array( array( 'species' => 'Blue Test Wrasse', 'species_id' => 1, 'size_slug' => '3-4', 'qty_ordered' => 1, 'qty_received' => 1, 'box_pct' => '30', 'unit_price' => 5 ) ) );
check( 'another size of that fish is still another share', 2 === count( $other->box_shares ) );

echo "11. Settings → Box shares: change a value, forget a row\n";
$GLOBALS['fh_user_caps']    = array( Clean_Room::SETTINGS_CAPABILITY => array( 1 ), Clean_Room::CAPABILITY => array( 1, 2 ) );
$GLOBALS['fh_current_user'] = 2;
$nonce                      = wp_create_nonce( Settings::NONCE_ACTION );
$id                         = $p['id'];
$r                          = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'save', 'id' => $id, 'pct_per_fish' => '7.5' ), $today );
check( 'a user who may not change settings is refused (403), nothing changed', 403 === $r['status'] && false === $r['ok'] && 16.0 === $store->box_share( $id )['pct_per_fish'] );
$GLOBALS['fh_current_user'] = 1;
$r                          = Settings::box_share_request( $store, array( '_wpnonce' => 'wrong', 'op' => 'delete', 'id' => $id ), $today );
check( 'a wrong nonce is refused (403), the row is still there', 403 === $r['status'] && null !== $store->box_share( $id ) );
$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'save', 'id' => 9999, 'pct_per_fish' => '7.5' ), $today );
check( 'a row that does not exist: 404', 404 === $r['status'] && false === $r['ok'] );
foreach ( array( '0', '', 'abc', '-3', '400.5', array( '7' ) ) as $bad ) {
	$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'save', 'id' => $id, 'pct_per_fish' => $bad ), $today );
	check( 'a percent per fish of ' . json_encode( $bad ) . ' is refused (400), nothing changed', 400 === $r['status'] && false === $r['ok'] && 16.0 === $store->box_share( $id )['pct_per_fish'] );
}
$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'shrug', 'id' => $id ), $today );
check( 'an unknown action is refused (400)', 400 === $r['status'] && 16.0 === $store->box_share( $id )['pct_per_fish'] );
$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'save', 'id' => $id, 'pct_per_fish' => '16' ), $today );
check( 'the value it already has: nothing changed', 200 === $r['status'] && true === $r['ok'] && 'Nothing changed.' === $r['message'] && '2026-05-19' === $store->box_share( $id )['last_date'] );
$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'save', 'id' => $id, 'pct_per_fish' => ' 7.5% ' ), $today );
$s = $store->box_share( $id );
check( 'a typed value is saved, dated today, belonging to no order line; the count is kept', 200 === $r['status'] && true === $r['ok'] && 7.5 === $s['pct_per_fish'] && null === $s['last_line_id'] && $today === $s['last_date'] && 2 === $s['order_count'] && false !== strpos( $r['message'], 'Purple Test Wrasse takes 7.5% of a box per fish' ) );
fh_save( $store, $d, array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'received_date' => '2026-05-19' ), fh_form_lines( $store, $d ) );
check( 're-saving an order dated before the typed value keeps the typed value', 7.5 === $store->box_share( $id )['pct_per_fish'] );
fh_save( $store, $d, array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'received_date' => $today ), fh_form_lines( $store, $d ) );
check( 'saving that day\'s order again does not put the old guess back: the typed value stands, still nobody\'s line', 7.5 === $store->box_share( $id )['pct_per_fish'] && null === $store->box_share( $id )['last_line_id'] && $today === $store->box_share( $id )['last_date'] && 2 === $store->box_share( $id )['order_count'] );
fh_save( $store, $d, array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'received_date' => '2026-05-21' ), fh_form_lines( $store, $d ) );
check( 'an order dated after it replaces it again (80 ÷ 5 = 16)', 16.0 === $store->box_share( $id )['pct_per_fish'] && '2026-05-21' === $store->box_share( $id )['last_date'] );
check( 'is_latest(): a value typed in Settings (a date, no line) yields only to an order dated after it; a value from a line, to one dated on or after', ! Box_Shares::is_latest( array( 'last_line_id' => null, 'last_date' => '2026-05-20' ), 9, '2026-05-20' ) && Box_Shares::is_latest( array( 'last_line_id' => null, 'last_date' => '2026-05-20' ), 9, '2026-05-21' ) && ! Box_Shares::is_latest( array( 'last_line_id' => 0, 'last_date' => '2026-05-20' ), 9, '2026-05-19' ) && Box_Shares::is_latest( array( 'last_line_id' => 4, 'last_date' => '2026-05-20' ), 9, '2026-05-20' ) && ! Box_Shares::is_latest( array( 'last_line_id' => null, 'last_date' => '2026-05-20' ), 9, '' ) );
check( 'per_fish_in(): above 0 and at most 400, to four decimals', 8.3333 === Box_Shares::per_fish_in( '8.33333' ) && 400.0 === Box_Shares::per_fish_in( 400 ) && null === Box_Shares::per_fish_in( 400.0001 ) && null === Box_Shares::per_fish_in( 0 ) && null === Box_Shares::per_fish_in( null ) && null === Box_Shares::per_fish_in( true ) );
$r = Settings::box_share_request( $store, array( '_wpnonce' => $nonce, 'op' => 'delete', 'id' => $id ), $today );
check( 'Forget removes the row, and the lines that counted in it count nowhere', 200 === $r['status'] && true === $r['ok'] && null === $store->box_share( $id ) && null === fh_share_of( $store, 'n:purple test wrasse', '2-3' ) && null === $store->order( $a )['lines'][0]['share_key'] && null === $store->order( $d )['lines'][0]['share_key'] && null !== $store->order( $a )['lines'][1]['share_key'] );
fh_save( $store, $d, array( 'ref' => '2026-5', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'order_date' => '2026-05-18', 'received_date' => $today ), fh_form_lines( $store, $d ) );
$back = fh_share_of( $store, 'n:purple test wrasse', '2-3' );
check( 'a forgotten share comes back the next time such a line is saved, counted from one', null !== $back && 16.0 === $back['pct_per_fish'] && 1 === $back['order_count'] );
fh_save( $store, $a, $header_a, fh_form_lines( $store, $a ) );
$back = fh_share_of( $store, 'n:purple test wrasse', '2-3' );
check( '…and the other order\'s line counts again when that order is saved (its older guess does not replace the value)', 2 === $back['order_count'] && 16.0 === $back['pct_per_fish'] );

echo "12. Exports; an order without percents remembers nothing\n";
$sheets = Admin_Workbook::orders_sheets( array( Admin_Workbook::resolve_order( $store, $store->order( $a ) ) ) );
$head   = $sheets['Lines'][0];
$first  = array_combine( $head, $sheets['Lines'][1] );
$last   = array_combine( $head, $sheets['Lines'][4] );
check( 'the Lines sheet has size, box_no, box_pct right after the columns it always had', array( 'size', 'box_no', 'box_pct' ) === array_slice( $head, (int) array_search( 'notes', $head, true ) + 1, 3 ) && 28 === array_search( 'size', $head, true ) && 'order_ref' === $head[0] );
check( 'a line\'s size label, box number and percent are exported typed; empty cells without them', '2-3"' === $first['size'] && 1 === $first['box_no'] && 60.0 === $first['box_pct'] && null === $last['size'] && null === $last['box_no'] && null === $last['box_pct'] );
$plain    = new Fake_Store();
$order_id = $plain->save_order( Orders::normalize_order( array( 'ref' => '2025-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2025-06-01', 'status' => 'closed' ) ) );
$plain->save_order_lines( $order_id, Orders::normalize_lines( array( array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 8, 'qty_received' => 8, 'per_box' => 8, 'unit_price' => 5 ) ) ) );
$did      = Box_Shares::remember( $plain, $plain->order( $order_id ), array(), $today );
check( 'an older or imported order (per box only): no percent, no size, nothing remembered, its fraction still received ÷ per box', array( 'written' => 0, 'counted' => 0, 'released' => 0 ) === $did && array() === $plain->box_shares && null === $plain->order( $order_id )['lines'][0]['box_pct'] && null === $plain->order( $order_id )['lines'][0]['size_slug'] && near( Admin_Workbook::resolve_order( $plain, $plain->order( $order_id ) )['lines'][0]['fraction'], 1 ) );
$key = $store->order( $a )['lines'][0]['share_key'];
$store->save_order_lines( $a, Orders::normalize_lines( fh_form_lines( $store, $a ) ) );
check( 'saving an order\'s lines never touches the share a line counts in (Box_Shares owns that key)', null !== $key && $key === $store->order( $a )['lines'][0]['share_key'] && $key === fh_share_of( $store, 'n:purple test wrasse', '2-3' )['share_key'] );

fh_finish();
