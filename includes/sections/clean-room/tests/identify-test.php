<?php
/**
 * Identify — unknown and mislabeled fish, and the one correction that
 * puts a stay and its supplier order line right together. With invented
 * fish, suppliers and numbers on the in-memory Fake_Store: the "??" name
 * of an unknown fish, the schema (the flag on a line and on a stay, the
 * label a line keeps, version 1.5 and its gate), an unknown fish on the
 * order form, costed and checked in like any other, the fish a correction
 * names (a product, a species-list fish, a typed name, still unknown; a
 * size), the correction itself (stay + line together, label_name set once
 * and never overwritten, the note event, the remembered box share written
 * after and never for a fish that still needs identifying), the split
 * rule (a line follows its stays only when they all agree; the "part of
 * this line" note), the Stays tab's Identify and Edit, the order page's
 * "Correct the fish…" and its grid, the Front Desk's unknown arrival and
 * identify, the flags and filters (Board chip, Stays filter, markers),
 * the mislabel stats, the exports, the importer that flags nothing, the
 * capability and nonce in front of the three AJAX endpoints, and the page
 * loaded before a correction that must not undo it (the order page and
 * the Stays tab's Edit post the token their row was rendered with).
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/identify-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fixture-builder.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Import;
use FisHotel\Misc\Sections\Clean_Room\Import_Map;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;
use FisHotel\Misc\Sections\Clean_Room\Workbook;

$today    = Replay::date_in_zone( time(), wp_timezone() );
$settings = Settings::get();
$ctx      = array(
	'today'    => $today,
	'settings' => $settings,
	'actor_id' => 7,
	'source'   => 'manual',
);
$base     = array(
	'actor_id' => 7,
	'source'   => 'manual',
	'event_at' => gmdate( 'Y-m-d H:i:s' ),
);

$GLOBALS['fh_user_caps']    = array( Clean_Room::CAPABILITY => array( 7 ) );
$GLOBALS['fh_current_user'] = 7;

function near( $a, $b, $eps = 1e-9 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/**
 * A store with three standard sizes, two products and three species-list
 * fish (one linked to a product, one named like a product, one unlisted
 * as a product).
 */
function fh_identify_store() {
	$store                  = new Fake_Store( array( 21 => 'Lined Test Wrasse', 22 => 'Powder Test Tang' ) );
	$store->size_attributes = array(
		array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ),
	);
	$store->size_terms      = array(
		'pa_size' => array(
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
			array( 'term_id' => 31, 'name' => '3-4"', 'slug' => '3-4', 'menu_order' => 2 ),
		),
	);
	$store->save_species( array( 'name' => 'Lined Test Wrasse', 'sci_name' => 'Testus lineatus', 'per_box' => 20, 'product_id' => 21 ) );   // 1
	$store->save_species( array( 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus', 'per_box' => 8 ) );                          // 2
	$store->save_species( array( 'name' => 'Powder Test Tang', 'sci_name' => 'Testus pulvis', 'per_box' => 8 ) );                           // 3
	return $store;
}

/**
 * Save an order of Test Supplier One / Testland through the editor's own
 * save and return its id.
 */
function fh_identify_order( $store, array $lines, $today, array $header = array() ) {
	$saved = Admin_Workbook::save_order(
		$store,
		array_merge(
			array(
				'ref'             => '',
				'supplier'        => 'Test Supplier One',
				'origin'          => 'Testland',
				'order_date'      => $today,
				'received_date'   => $today,
				'status'          => 'ordered',
				'cost_mode'       => 'box',
				'boxes'           => 1,
				'domestic_flight' => '100',
				'import_fees'     => '100',
				'lines'           => $lines,
			),
			$header
		),
		0,
		$today
	);
	if ( ! empty( $saved['error'] ) ) {
		fwrite( STDERR, 'fixture order: ' . $saved['error'] . "\n" );
		exit( 1 );
	}
	return (int) $saved['order_id'];
}

/**
 * A line of an order by its position.
 */
function fh_line( $store, $order_id, $index ) {
	return $store->order( $order_id )['lines'][ $index ];
}

echo "1. The name of an unknown fish\n";
$names = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/unknown-names.json' ), true );
foreach ( (array) ( $names['unknown'] ?? array() ) as $case ) {
	check( 'unknown_name(' . json_encode( $case[0] ) . ') → ' . json_encode( $case[1] ) . ' (the fixture both JS files read too)', $case[1] === Identify::unknown_name( $case[0] ), Identify::unknown_name( $case[0] ) );
}
check( 'the fixture was read', is_array( $names ) && count( $names['unknown'] ) >= 10 );
check( '"??" alone, or "?? " and what it looks like', '??' === Identify::unknown_name() && '??' === Identify::unknown_name( '   ' ) && '?? small wrasse' === Identify::unknown_name( ' small   wrasse ' ) );
check( 'a mark that is already there is not doubled, however it was typed', '?? small wrasse' === Identify::unknown_name( '?? small wrasse' ) && '?? small wrasse' === Identify::unknown_name( '???small wrasse' ) && '??' === Identify::unknown_name( ' ?? ' ) && '?? a?b' === Identify::unknown_name( '? a?b' ) );
check( 'junk is no description; the name fits the column', '??' === Identify::unknown_name( array( 'x' ) ) && '??' === Identify::unknown_name( true ) && '??' === Identify::unknown_name( null ) && Identify::MAX_NAME === strlen( Identify::unknown_name( str_repeat( 'x', 300 ) ) ) );
check( 'a name is an unknown fish when it starts with "??"', Identify::is_unknown_name( '??' ) && Identify::is_unknown_name( '  ?? eel' ) && ! Identify::is_unknown_name( '? eel' ) && ! Identify::is_unknown_name( 'Eel ??' ) && ! Identify::is_unknown_name( '' ) && ! Identify::is_unknown_name( array() ) );
check( 'two names are the same fish case and spacing aside', Identify::same_fish( ' blue  TEST tang', 'Blue Test Tang' ) && ! Identify::same_fish( 'Blue Test Tang', 'Powder Test Tang' ) );
check( 'is(): the same name AND as unknown', Identify::is( array( 'species' => 'blue test tang', 'needs_id' => false ), array( 'name' => 'Blue Test Tang', 'unknown' => false ) ) && ! Identify::is( array( 'species' => '?? eel', 'needs_id' => true ), array( 'name' => '?? eel', 'unknown' => false ) ) && Identify::is( array( 'species' => '?? eel', 'needs_id' => 1 ), array( 'name' => '?? Eel', 'unknown' => true ) ) );

echo "2. The schema: the flag, the label, version 1.5 and its gate\n";
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$tables          = array();
foreach ( Store::schema( 'DEFAULT CHARSET=utf8mb4' ) as $statement ) {
	if ( 1 === preg_match( '/^CREATE TABLE wp_fh_([a-z_]+) \(/', $statement, $m ) ) {
		$tables[ $m[1] ] = $statement;
	}
}
check( 'schema version 1.5 or later: a site that already ran 1.4 gets the new columns', version_compare( Store::DB_VERSION, '1.5', '>=' ) && count( Store::TABLES ) >= 10 );
check( 'fh_order_lines: needs_id (0 by default) and a nullable label_name, each on its own line', 1 === preg_match( "/\n\t+sci_name VARCHAR\(120\) NOT NULL DEFAULT '',\n\t+needs_id TINYINT\(1\) NOT NULL DEFAULT 0,\n\t+label_name VARCHAR\(120\) NULL,\n/", $tables['order_lines'] ) );
check( 'fh_stays: needs_id, and a key on order_line_id for the stays of a line', 1 === preg_match( "/\n\t+needs_id TINYINT\(1\) NOT NULL DEFAULT 0,\n/", $tables['stays'] ) && false !== strpos( $tables['stays'], "KEY order_line_id (order_line_id)\n" ) );
check( 'the gate waits for the three new columns', in_array( 'needs_id', Store::REQUIRED_COLUMNS['order_lines'], true ) && in_array( 'label_name', Store::REQUIRED_COLUMNS['order_lines'], true ) && in_array( 'needs_id', Store::REQUIRED_COLUMNS['stays'], true ) );
$present = array();
foreach ( Store::REQUIRED_COLUMNS as $table => $columns ) {
	$present[ $table ] = array_merge( array( 'id' ), $columns );
}
check( 'with every column there it opens', Store::schema_ready( $present ) );
foreach ( array( array( 'order_lines', 'needs_id' ), array( 'order_lines', 'label_name' ), array( 'stays', 'needs_id' ) ) as $pair ) {
	$short             = $present;
	$short[ $pair[0] ] = array_values( array_diff( $short[ $pair[0] ], array( $pair[1] ) ) );
	check( 'without ' . $pair[0] . '.' . $pair[1] . ' it stays shut (the version is not written, the ALTER is retried)', ! Store::schema_ready( $short ) && array( $pair[0] . '.' . $pair[1] ) === Store::missing_columns( $short ) );
}
$stay = Store::hydrate_stay( array( 'id' => 1, 'species' => '?? eel', 'checkin_date' => $today, 'needs_id' => '1' ), $today, $settings );
check( 'a stay row carries needs_id as a bool; a row from before the column is a known fish', true === $stay['needs_id'] && false === Store::hydrate_stay( array( 'id' => 2, 'species' => 'x', 'checkin_date' => $today ), $today, $settings )['needs_id'] && false === Store::hydrate_stay( array( 'id' => 3, 'species' => 'x', 'checkin_date' => $today, 'needs_id' => '0' ), $today, $settings )['needs_id'] );
$line = Store::hydrate_line( array( 'id' => 1, 'order_id' => 1, 'species' => 'x', 'needs_id' => '1', 'label_name' => 'Blue Test Tang' ) );
check( 'an order line carries needs_id and label_name; an empty label is none', true === $line['needs_id'] && 'Blue Test Tang' === $line['label_name'] && false === Store::hydrate_line( array( 'id' => 2 ) )['needs_id'] && null === Store::hydrate_line( array( 'id' => 2, 'label_name' => '  ' ) )['label_name'] );

echo "3. An unknown fish on the order form: named \"??\", costed and saved like any other\n";
$raw = array( 'species' => 'small wrasse', 'species_id' => '2', 'needs_id' => '1', 'qty_ordered' => '4' );
$l   = Orders::normalize_line( $raw );
check( 'the "??" box ticked: the name is "??" and what was typed, and it names no species-list fish', true === $l['needs_id'] && '?? small wrasse' === $l['species'] && null === $l['species_id'] );
check( 'ticked with nothing typed: "??" — and such a line is kept', '??' === Orders::normalize_line( array( 'needs_id' => '1' ) )['species'] && 1 === count( Orders::normalize_lines( array( array( 'needs_id' => '1', 'qty_ordered' => 1 ), array( 'species' => '' ) ) ) ) );
check( 'not ticked: a line as it always was', false === Orders::normalize_line( array( 'species' => 'Blue Test Tang', 'species_id' => 2 ) )['needs_id'] && 2 === Orders::normalize_line( array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'needs_id' => '0' ) )['species_id'] && false === Orders::normalize_line( array( 'species' => 'x', 'needs_id' => array( '1' ) ) )['needs_id'] );
$flagged = Identify::flag_lines( Orders::normalize_lines( array( array( 'species' => '?? odd eel', 'species_id' => 3 ), array( 'species' => 'Blue Test Tang', 'species_id' => 2 ) ) ) );
check( 'a name typed with a leading "??" is an unknown fish too (the form only — normalize_line() itself flags nothing)', true === $flagged[0]['needs_id'] && null === $flagged[0]['species_id'] && '?? odd eel' === $flagged[0]['species'] && false === $flagged[1]['needs_id'] && 2 === $flagged[1]['species_id'] && false === Orders::normalize_line( array( 'species' => '?? odd eel' ) )['needs_id'] );

$store    = fh_identify_store();
$order_id = fh_identify_order(
	$store,
	array(
		array( 'species' => 'small wrasse', 'needs_id' => '1', 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'box_no' => 1, 'box_pct' => '40', 'unit_price' => '5' ),
		array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'size_slug' => '3-4', 'qty_ordered' => 2, 'qty_received' => 2, 'box_no' => 1, 'box_pct' => '60', 'unit_price' => '20' ),
	),
	$today
);
$known_store = fh_identify_store();
$known_id    = fh_identify_order(
	$known_store,
	array(
		array( 'species' => 'Lined Test Wrasse', 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'box_no' => 1, 'box_pct' => '40', 'unit_price' => '5' ),
		array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'size_slug' => '3-4', 'qty_ordered' => 2, 'qty_received' => 2, 'box_no' => 1, 'box_pct' => '60', 'unit_price' => '20' ),
	),
	$today
);
$order    = Admin_Workbook::resolve_order( $store, $store->order( $order_id ) );
$unknown  = $order['lines'][0];
check( 'the saved line: "?? small wrasse", flagged, its size, box and percent as typed', '?? small wrasse' === $unknown['species'] && true === $unknown['needs_id'] && null === $unknown['species_id'] && '2-3' === $unknown['size_slug'] && 1 === $unknown['box_no'] && 40.0 === $unknown['box_pct'] && null === $unknown['label_name'] );
check( 'its landed cost: 4 × 5 + 40 % of the 200 in fees = 100, 25 a fish', near( $unknown['landed_total'], 100.0 ) && near( $unknown['landed_per_fish'], 25.0 ) && near( $order['lines'][1]['landed_total'], 160.0 ) );
$known = Admin_Workbook::resolve_order( $known_store, $known_store->order( $known_id ) );
check( '…exactly what a known fish with the same numbers gets', near( $known['lines'][0]['landed_total'], $unknown['landed_total'] ) && near( $known['costing']['total'], $order['costing']['total'] ) && near( $known['lines'][0]['fraction'], $unknown['fraction'] ) );
check( 'a remembered box share is written for the known fish only — never for one that still needs identifying', 1 === count( $store->box_shares ) && 'Blue Test Tang' === array_values( $store->box_shares )[0]['species'] && null === $unknown['share_key'] && null !== $order['lines'][1]['share_key'] );
check( 'Box_Shares::eligible() is where that is refused', false === Box_Shares::eligible( $order, $unknown ) && true === Box_Shares::eligible( $order, $order['lines'][1] ) );
check( 'the order counts its fish to identify', 1 === Admin_Workbook::unknown_lines( $order ) && 0 === Admin_Workbook::unknown_lines( array( 'lines' => array( array( 'species' => 'x' ) ) ) ) && 0 === Admin_Workbook::unknown_lines( array() ) );

echo "4. Check-in and a split: as unknown as the line\n";
$out   = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
$ids   = array_column( $out['created'], 'stay_id' );
$stay  = $store->stay( $ids[0], $today, $settings );
$tang  = $store->stay( $ids[1], $today, $settings );
check( 'the unknown line checks in like any other: its tank, count and size — flagged, named "??…", linked to no product', 2 === count( $ids ) && array() === $out['errors'] && '?? small wrasse' === $stay['species'] && true === $stay['needs_id'] && null === $stay['product_id'] && 4 === $stay['qty_live'] && '2-3' === $stay['size_slug'] && $stay['tank_id'] > 0 && near( $stay['landed_cost'], 25.0 ) );
check( 'the known line gives a known stay', false === $tang['needs_id'] && 'Blue Test Tang' === $tang['species'] );
$split  = $store->split_stay( $stay, 1, $store->tank_by_number( 522 ), array( 'actor_id' => 7, 'source' => 'manual' ), $today, $settings, 'pairing' );
$sister = $store->stay( $split['stay_id'], $today, $settings );
check( 'a split: the sister stay is as unknown as the original, on the same order line', true === $sister['needs_id'] && '?? small wrasse' === $sister['species'] && $stay['order_line_id'] === $sister['order_line_id'] && 1 === $sister['qty_live'] );
check( 'the stays of a line, open and closed, oldest first', array( $ids[0], $split['stay_id'] ) === array_column( $store->stays_for_line( $stay['order_line_id'], $today, $settings ), 'id' ) && array() === $store->stays_for_line( 0, $today, $settings ) && array( $ids[1] ) === array_column( $store->stays_for_line( $tang['order_line_id'], $today, $settings ), 'id' ) );

echo "5. The fish a correction names\n";
$fish = Identify::fish_from( $store, array( 'species' => 'Lined Test Wrasse', 'product_id' => '21', 'size' => '3-4' ), $settings, $stay );
check( 'a product: its id, the species-list fish linked to it (id and scientific name), the size with the list\'s label', array( 'name' => 'Lined Test Wrasse', 'unknown' => false, 'product_id' => 21, 'species_id' => 1, 'sci_name' => 'Testus lineatus', 'size_slug' => '3-4', 'size_label' => '3-4"' ) === $fish, json_encode( $fish ) );
$fish = Identify::fish_from( $store, array( 'product_id' => 22 ), $settings, $stay );
check( 'a product alone: its title is the name; the species-list fish named like it; no `size` leaves the size as it is', 'Powder Test Tang' === $fish['name'] && 22 === $fish['product_id'] && 3 === $fish['species_id'] && '2-3' === $fish['size_slug'] && '2-3"' === $fish['size_label'] );
$fish = Identify::fish_from( $store, array( 'species' => ' lined  test wrasse ' ), $settings, array() );
check( 'a species-list fish typed any which way: the list\'s spelling, its id, its scientific name and its product link', 'Lined Test Wrasse' === $fish['name'] && 1 === $fish['species_id'] && 21 === $fish['product_id'] && 'Testus lineatus' === $fish['sci_name'] && null === $fish['size_slug'] );
$fish = Identify::fish_from( $store, array( 'species' => 'Green Test Blenny', 'product_id' => 999, 'size' => '' ), $settings, $stay );
check( 'a typed name nobody lists: no id, no product (one that does not exist is never assumed), and an empty `size` clears it', 'Green Test Blenny' === $fish['name'] && null === $fish['species_id'] && null === $fish['product_id'] && null === $fish['sci_name'] && null === $fish['size_slug'] && null === $fish['size_label'] );
$fish = Identify::fish_from( $store, array( 'species' => 'striped eel', 'product_id' => 21, 'unknown' => '1' ), $settings, $stay );
check( 'still unknown: "??" and what it looks like, no product, no species-list fish', array( '?? striped eel', true, null, null, '' ) === array( $fish['name'], $fish['unknown'], $fish['product_id'], $fish['species_id'], $fish['sci_name'] ) );
check( 'a name typed with "??" is unknown without the box; unknown with nothing typed is "??"', true === Identify::fish_from( $store, array( 'species' => '?? striped eel' ), $settings )['unknown'] && '??' === Identify::fish_from( $store, array( 'unknown' => 'yes' ), $settings )['name'] && false === Identify::fish_from( $store, array( 'species' => 'x', 'unknown' => '0' ), $settings )['unknown'] );
check( 'no fish at all is refused', isset( Identify::fish_from( $store, array(), $settings )['error'] ) && isset( Identify::fish_from( $store, array( 'species' => array( 'x' ), 'product_id' => 999 ), $settings )['error'] ) );
check( 'a size that is not in the list is refused; the row\'s own stale size is kept', isset( Identify::fish_from( $store, array( 'species' => 'x', 'size' => 'jumbo' ), $settings, $stay )['error'] ) && isset( Identify::fish_from( $store, array( 'species' => 'x', 'size' => array( '2-3' ) ), $settings, $stay )['error'] ) && 'jumbo' === Identify::fish_from( $store, array( 'species' => 'x', 'size' => 'jumbo' ), $settings, array( 'size_slug' => 'jumbo', 'size_label' => 'Jumbo 8"' ) )['size_slug'] );
$linked = array( 'species' => 'Powder Test Tang', 'product_id' => 22, 'size_slug' => null );
$fish   = Identify::fish_from( $store, array( 'species' => ' powder test  tang', 'product_id' => 0, 'size' => '2-3' ), $settings, $linked );
check( 'the fish the row already is, typed again (not picked) to set its size: it keeps the product the row is linked to', 22 === $fish['product_id'] && 'Powder Test Tang' === $fish['name'] && '2-3' === $fish['size_slug'], json_encode( $fish ) );
check( '…another fish does not inherit that product, and a product that no longer exists is not assumed', null === Identify::fish_from( $store, array( 'species' => 'Green Test Blenny' ), $settings, $linked )['product_id'] && null === Identify::fish_from( $store, array( 'species' => 'Powder Test Tang' ), $settings, array( 'species' => 'Powder Test Tang', 'product_id' => 999 ) )['product_id'] && 21 === Identify::fish_from( $store, array( 'species' => 'Lined Test Wrasse' ), $settings, $linked )['product_id'] );
check( 'markup in a typed name is stripped', 'Bold Test Fish' === Identify::fish_from( $store, array( 'species' => '<b>Bold</b> Test Fish' ), $settings )['name'] );

echo "6. One correction: the stay and its order line together\n";
$store    = fh_identify_store();
$order_id = fh_identify_order(
	$store,
	array(
		array( 'species' => 'small wrasse', 'needs_id' => '1', 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'box_no' => 1, 'box_pct' => '40', 'unit_price' => '5' ),
		array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'sci_name' => 'Testus caeruleus', 'size_slug' => '3-4', 'qty_ordered' => 2, 'qty_received' => 2, 'box_no' => 1, 'box_pct' => '60', 'unit_price' => '20' ),
	),
	$today
);
$out      = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
list( $a, $b ) = array_column( $out['created'], 'stay_id' );
$events   = $store->event_count();
$r        = Admin::identify_stay( $store, $store->stay( $a, $today, $settings ), array( 'species' => 'Lined Test Wrasse', 'product_id' => '21', 'size' => '3-4' ), $base, $today, $settings );
$stay     = $store->stay( $a, $today, $settings );
$line     = fh_line( $store, $order_id, 0 );
check( 'identified: the stay has the fish, its product and its size, and no longer needs identifying', true === $r['ok'] && 200 === $r['status'] && 'Lined Test Wrasse' === $stay['species'] && 21 === $stay['product_id'] && '3-4' === $stay['size_slug'] && '3-4"' === $stay['size_label'] && false === $stay['needs_id'], json_encode( $r ) );
check( '…and its order line with it: the species-list id, the name, the scientific name, the size, the flag gone', 1 === $line['species_id'] && 'Lined Test Wrasse' === $line['species'] && 'Testus lineatus' === $line['sci_name'] && '3-4' === $line['size_slug'] && '3-4"' === $line['size_label'] && false === $line['needs_id'] && 'followed' === $r['result']['line'] && $line['id'] === $r['result']['line_id'] );
check( 'a fish that arrived unknown had no label to be wrong: its "??" name is kept in the label\'s place — reported, shown and counted nowhere', '?? small wrasse' === $line['label_name'] && null === $r['result']['label'] && '' === Identify::label_shown( $line ) && false === Identify::is_mislabeled( $line ) );
$event = $store->last_event();
check( 'one note event on the stay says who corrected what: "identified: <old> → <new>"', $events + 1 === $store->event_count() && 'note' === $event['type'] && 'identified: ?? small wrasse → Lined Test Wrasse · 3-4"' === $event['note'] && 7 === $event['actor_id'] && $a === $event['stay_id'] && array( $event['id'] ) === $r['result']['event_ids'] );
check( 'the Events tab reads it as it is; any other note keeps its "note:"', 'identified: ?? small wrasse → Lined Test Wrasse · 3-4"' === Admin::event_text( $event ) && 'note: fed twice' === Admin::event_text( array( 'type' => 'note', 'note' => 'fed twice' ) ) && Identify::is_note( 'marked unknown: a → ?? b' ) && ! Identify::is_note( 'sale_ready:2026-01-01' ) );
$share = $store->box_share_by_key( (string) $line['share_key'] );
check( 'then the remembered box share of the corrected fish is written: 40 % ÷ 4 fish = 10 % a fish, by its species-list id and its new size', 2 === count( $store->box_shares ) && null !== $share && 's:1' === $share['fish_key'] && '3-4' === $share['size_slug'] && near( $share['pct_per_fish'], 10.0 ) && 1 === $share['order_count'] && $line['id'] === $share['last_line_id'] && 'Lined Test Wrasse' === $share['species'] );
check( 'the reply words it', 'Identified: ?? small wrasse → Lined Test Wrasse · 3-4" · the order line follows.' === $r['message'], $r['message'] );
$r = Admin::identify_stay( $store, $store->stay( $a, $today, $settings ), array( 'species' => 'Lined Test Wrasse', 'product_id' => '21', 'size' => '3-4' ), $base, $today, $settings );
check( 'the same again changes nothing: no event, no second count', true === $r['ok'] && 'Nothing changed.' === $r['message'] && false === $r['result']['changed'] && $events + 1 === $store->event_count() && 1 === $store->box_share_by_key( (string) $line['share_key'] )['order_count'] );

echo "7. What the label said: set once, never overwritten\n";
$old_key = (string) fh_line( $store, $order_id, 1 )['share_key'];
$r       = Admin::identify_stay( $store, $store->stay( $b, $today, $settings ), array( 'species' => 'Powder Test Tang', 'product_id' => '22' ), $base, $today, $settings );
$stay    = $store->stay( $b, $today, $settings );
$line    = fh_line( $store, $order_id, 1 );
check( 'a mislabeled fish corrected: the stay and the line are the real fish (the size untouched: none was posted)', true === $r['ok'] && 'Powder Test Tang' === $stay['species'] && 22 === $stay['product_id'] && '3-4' === $stay['size_slug'] && 'Powder Test Tang' === $line['species'] && 3 === $line['species_id'] && 'Testus pulvis' === $line['sci_name'] && '3-4' === $line['size_slug'] );
check( 'the line keeps what the label said — the first time it is corrected to a different fish', 'Blue Test Tang' === $line['label_name'] && 'Blue Test Tang' === $r['result']['label'] && 'Identified: Blue Test Tang → Powder Test Tang · 3-4" · the order line follows · arrived labeled: Blue Test Tang.' === $r['message'], $r['message'] );
check( 'the share counted under the wrong fish is given back (nobody else counted in it: gone), the right one written: 60 % ÷ 2 = 30', null === $store->box_share_by_key( $old_key ) && $old_key !== $line['share_key'] && 's:3' === $store->box_share_by_key( (string) $line['share_key'] )['fish_key'] && near( $store->box_share_by_key( (string) $line['share_key'] )['pct_per_fish'], 30.0 ) && 2 === count( $store->box_shares ) );
check( 'such a line is mislabeled', true === Identify::is_mislabeled( $line ) );
$r    = Admin::identify_stay( $store, $store->stay( $b, $today, $settings ), array( 'species' => 'Green Test Blenny', 'size' => '2-3' ), $base, $today, $settings );
$line = fh_line( $store, $order_id, 1 );
check( 'corrected again, to a fish nobody lists: the name, no id, no scientific name, no product — and the label is NOT overwritten', 'Green Test Blenny' === $line['species'] && null === $line['species_id'] && '' === $line['sci_name'] && null === $store->stay( $b, $today, $settings )['product_id'] && 'Blue Test Tang' === $line['label_name'] && null === $r['result']['label'] && '2-3' === $line['size_slug'] );
check( 'its share is keyed by the name now', 'n:green test blenny' === $store->box_share_by_key( (string) $line['share_key'] )['fish_key'] && '2-3' === $store->box_share_by_key( (string) $line['share_key'] )['size_slug'] && 2 === count( $store->box_shares ) );
$r    = Admin::identify_stay( $store, $store->stay( $b, $today, $settings ), array( 'species' => 'no idea', 'unknown' => '1', 'size' => '2-3' ), $base, $today, $settings );
$line = fh_line( $store, $order_id, 1 );
$stay = $store->stay( $b, $today, $settings );
check( 'marked unknown after all: the stay and the line read "?? no idea" and need identifying; the label stays', '?? no idea' === $stay['species'] && true === $stay['needs_id'] && '?? no idea' === $line['species'] && true === $line['needs_id'] && null === $line['species_id'] && 'Blue Test Tang' === $line['label_name'] );
check( 'its event says so; the fish it was is not remembered any more, and is not mislabeled while unknown', 'marked unknown: Green Test Blenny → ?? no idea · 2-3"' === $store->last_event()['note'] && null === $line['share_key'] && 1 === count( $store->box_shares ) && false === Identify::is_mislabeled( $line ) && 0 === strpos( $r['message'], 'Marked unknown: Green Test Blenny → ?? no idea' ) );
$r    = Admin::identify_stay( $store, $store->stay( $b, $today, $settings ), array( 'species' => 'blue test tang', 'size' => '2-3' ), $base, $today, $settings );
$line = fh_line( $store, $order_id, 1 );
check( 'identified as what the label said after all (typed any which way → the list\'s spelling): not mislabeled, the label still there', 'Blue Test Tang' === $line['species'] && 2 === $line['species_id'] && false === $line['needs_id'] && 'Blue Test Tang' === $line['label_name'] && false === Identify::is_mislabeled( $line ) && 2 === count( $store->box_shares ) );
check( 'label_for(): the old name once; never over a label; never the same fish', 'Blue Test Tang' === Identify::label_for( array( 'species' => 'Blue Test Tang' ), 'Powder Test Tang' ) && null === Identify::label_for( array( 'species' => 'Blue Test Tang', 'label_name' => 'Older Label' ), 'Powder Test Tang' ) && null === Identify::label_for( array( 'species' => 'blue  test tang' ), 'Blue Test Tang' ) && null === Identify::label_for( array( 'species' => '' ), 'x' ) && 'Blue Test Tang' === Identify::label_for( array( 'species' => 'Blue Test Tang' ), '?? eel' ) );
check( 'label_for(): a fish that arrived unknown keeps its "??" name in the label\'s place the first time it is identified — not while it is only described again, never over a label', '?? eel' === Identify::label_for( array( 'species' => '?? eel', 'needs_id' => true ), 'Powder Test Tang' ) && '?? eel' === Identify::label_for( array( 'species' => '?? eel' ), 'x' ) && '?? Blue Test Tang' === Identify::label_for( array( 'species' => 'Blue Test Tang', 'needs_id' => true ), 'x' ) && null === Identify::label_for( array( 'species' => '?? eel', 'needs_id' => true ), '?? striped eel' ) && null === Identify::label_for( array( 'species' => '?? eel', 'needs_id' => true, 'label_name' => 'Blue Test Tang' ), 'x' ) );
check( 'is_mislabeled(): a label, an identified fish, and another fish than the label — a "??" label is none', ! Identify::is_mislabeled( array( 'species' => 'x' ) ) && ! Identify::is_mislabeled( array( 'species' => 'x', 'label_name' => ' X ' ) ) && Identify::is_mislabeled( array( 'species' => 'x', 'label_name' => 'y' ) ) && ! Identify::is_mislabeled( array( 'species' => '?? x', 'label_name' => 'y', 'needs_id' => true ) ) && ! Identify::is_mislabeled( array( 'species' => 'x', 'label_name' => '?? y' ) ) && 'y' === Identify::label_shown( array( 'label_name' => ' y ' ) ) && '' === Identify::label_shown( array( 'label_name' => '?? y' ) ) && '' === Identify::label_shown( array() ) );
$guess    = fh_identify_store();
$guess_id = fh_identify_order( $guess, array( array( 'species' => 'small wrasse', 'needs_id' => '1', 'qty_ordered' => 3, 'qty_received' => 3, 'box_no' => 1, 'box_pct' => '30', 'unit_price' => '5' ) ), $today );
$out      = Orders::check_in( $guess, $guess->order( $guess_id ), $today, $settings, array( 'actor_id' => 7 ) );
$gid      = $out['created'][0]['stay_id'];
Admin::identify_stay( $guess, $guess->stay( $gid, $today, $settings ), array( 'species' => 'Lined Test Wrasse', 'product_id' => 21 ), $base, $today, $settings );
$r    = Admin::identify_stay( $guess, $guess->stay( $gid, $today, $settings ), array( 'species' => 'Blue Test Tang' ), $base, $today, $settings );
$line = fh_line( $guess, $guess_id, 0 );
check( 'an unknown fish identified, then corrected again: the first guess is not "what the label said" — nothing arrived labeled, nothing is mislabeled', 'Blue Test Tang' === $line['species'] && '?? small wrasse' === $line['label_name'] && '' === Identify::label_shown( $line ) && false === Identify::is_mislabeled( $line ) && null === $r['result']['label'] && 'Identified: Lined Test Wrasse → Blue Test Tang · the order line follows.' === $r['message'], $r['message'] );
check( '…and the stats agree: 0 mislabeled of 1 received line', array( 'received_lines' => 1, 'mislabeled' => 0, 'unidentified' => 0 ) === Stats::label_counts( $guess->order( $guess_id )['lines'] ) );

echo "8. The split rule: a line follows its stays only when they all agree\n";
$store    = fh_identify_store();
$order_id = fh_identify_order( $store, array( array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'qty_ordered' => 6, 'qty_received' => 6, 'box_no' => 1, 'box_pct' => '60', 'unit_price' => '20' ) ), $today );
$out      = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
$s1       = $out['created'][0]['stay_id'];
$split    = $store->split_stay( $store->stay( $s1, $today, $settings ), 2, $store->tank_by_number( 522 ), array( 'actor_id' => 7, 'source' => 'manual' ), $today, $settings, '' );
$s2       = $split['stay_id'];
$store->update_line_fields( fh_line( $store, $order_id, 0 )['id'], array( 'notes' => 'two bags' ) );
$r        = Admin::identify_stay( $store, $store->stay( $s2, $today, $settings ), array( 'species' => 'Lined Test Wrasse', 'product_id' => 21 ), $base, $today, $settings );
$line     = fh_line( $store, $order_id, 0 );
check( 'part of a split group identified as something else: only that stay changes', true === $r['ok'] && 'part' === $r['result']['line'] && 'Lined Test Wrasse' === $store->stay( $s2, $today, $settings )['species'] && 'Blue Test Tang' === $store->stay( $s1, $today, $settings )['species'] );
check( 'the order line keeps its fish and gets a note naming the part (its own notes kept); no label, since the line was not corrected', 'Blue Test Tang' === $line['species'] && 2 === $line['species_id'] && null === $line['label_name'] && 'two bags [part of this line is Lined Test Wrasse — stay #' . $s2 . ']' === $line['notes'], $line['notes'] );
check( 'no box share is written for the part: the line\'s percent is still the whole line\'s', 1 === count( $store->box_shares ) && 's:2' === array_values( $store->box_shares )[0]['fish_key'] && 1 === array_values( $store->box_shares )[0]['order_count'] );
check( 'the reply says the line kept its name', false !== strpos( $r['message'], 'its order line keeps its name' ), $r['message'] );
$r    = Admin::identify_stay( $store, $store->stay( $s2, $today, $settings ), array( 'species' => 'Powder Test Tang', 'product_id' => 22 ), $base, $today, $settings );
$line = fh_line( $store, $order_id, 0 );
check( 'the part corrected again: one note, with the fish it is now', 'two bags [part of this line is Powder Test Tang — stay #' . $s2 . ']' === $line['notes'] && 'part' === $r['result']['line'] );
$r    = Admin::identify_stay( $store, $store->stay( $s1, $today, $settings ), array( 'species' => 'Powder Test Tang', 'product_id' => 22 ), $base, $today, $settings );
$line = fh_line( $store, $order_id, 0 );
check( 'the last sister corrected to the same fish: every stay agrees, so the line follows — the fish, the label, the note gone', 'followed' === $r['result']['line'] && 'Powder Test Tang' === $line['species'] && 3 === $line['species_id'] && 'Blue Test Tang' === $line['label_name'] && 'two bags' === $line['notes'] );
check( '…and the remembered share moves to the corrected fish: 60 % ÷ 6 = 10', 1 === count( $store->box_shares ) && 's:3' === array_values( $store->box_shares )[0]['fish_key'] && near( array_values( $store->box_shares )[0]['pct_per_fish'], 10.0 ) );
check( 'part_notes(): one mark per stay that is not what the line says, the older marks dropped, the line\'s own words kept', 'keep me [part of this line is B — stay #4]' === Identify::part_notes( 'keep me [part of this line is A — stay #3] [part of this line is Old — stay #4]', 'A', false, array( array( 'id' => 3, 'species' => 'a' ), array( 'id' => 4, 'species' => 'B' ) ) ) && '' === Identify::part_notes( '[part of this line is A — stay #3]', 'A', false, array() ) && '[part of this line is ?? eel (odd) — stay #5]' === Identify::part_notes( '', 'A', false, array( array( 'id' => 5, 'species' => '?? eel [odd]', 'needs_id' => true ) ) ) && '[part of this line is A — stay #6]' === Identify::part_notes( '', 'A', true, array( array( 'id' => 6, 'species' => 'A' ) ) ) );

echo "9. From the order line: \"Correct the fish…\"\n";
$store    = fh_identify_store();
$order_id = fh_identify_order(
	$store,
	array(
		array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'size_slug' => '3-4', 'qty_ordered' => 6, 'qty_received' => 6, 'box_no' => 1, 'box_pct' => '60', 'unit_price' => '20' ),
		array( 'species' => 'Red Test Goby', 'qty_ordered' => 5, 'qty_received' => 5, 'box_no' => 1, 'box_pct' => '10', 'unit_price' => '3' ),
		array( 'species' => 'Green Test Blenny', 'qty_ordered' => 3 ),
	),
	$today
);
$order    = $store->order( $order_id );
$goby     = $order['lines'][1];
$r        = Admin_Workbook::identify_line( $store, $order, array( 'line_id' => 9999, 'species' => 'x' ), $today, $settings, 7 );
check( 'a line that is not on the order is refused', isset( $r['error'] ) && false !== strpos( $r['error'], 'not on this order' ) );
$r = Admin_Workbook::identify_line( $store, $order, array( 'line_id' => $order['lines'][2]['id'], 'species' => 'x' ), $today, $settings, 7 );
check( 'a line nothing arrived on is refused: its species is simply retyped', isset( $r['error'] ) && false !== strpos( $r['error'], 'Nothing has arrived' ) && 'Green Test Blenny' === fh_line( $store, $order_id, 2 )['species'] );
$r = Admin_Workbook::identify_line( $store, $order, array( 'line_id' => $goby['id'], 'species' => '', 'size' => '1-2' ), $today, $settings, 7 );
check( 'no fish, or a size that is not in the list: refused, nothing written', isset( $r['error'] ) && isset( Admin_Workbook::identify_line( $store, $order, array( 'line_id' => $goby['id'], 'species' => 'x', 'size' => 'jumbo' ), $today, $settings, 7 )['error'] ) && 'Red Test Goby' === fh_line( $store, $order_id, 1 )['species'] && null === fh_line( $store, $order_id, 1 )['size_slug'] );
$events = $store->event_count();
$r      = Admin_Workbook::identify_line( $store, $order, array( 'line_id' => $goby['id'], 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '1-2' ), $today, $settings, 7 );
$line   = fh_line( $store, $order_id, 1 );
check( 'a received line that is not checked in yet: the line alone — the fish, its size, what the label said, its share (10 % ÷ 5 = 2)', empty( $r['error'] ) && true === $r['changed'] && array() === $r['stays'] && 'Lined Test Wrasse' === $line['species'] && 1 === $line['species_id'] && '1-2' === $line['size_slug'] && 'Red Test Goby' === $line['label_name'] && 'Red Test Goby' === $r['label'] && $events === $store->event_count() && near( $store->box_share_by_key( (string) $line['share_key'] )['pct_per_fish'], 2.0 ) && 's:1' === $store->box_share_by_key( (string) $line['share_key'] )['fish_key'], json_encode( $r ) );
check( 'its reply', 'Identified: Red Test Goby → Lined Test Wrasse · 1-2" · arrived labeled: Red Test Goby.' === $r['message'], $r['message'] );
check( 'the same again changes nothing', false === Admin_Workbook::identify_line( $store, $store->order( $order_id ), array( 'line_id' => $goby['id'], 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '1-2' ), $today, $settings, 7 )['changed'] && 'Nothing changed.' === Admin_Workbook::identify_line( $store, $store->order( $order_id ), array( 'line_id' => $goby['id'], 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '1-2' ), $today, $settings, 7 )['message'] );
$out   = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
$tang  = $out['created'][0]['stay_id'];
$split = $store->split_stay( $store->stay( $tang, $today, $settings ), 2, $store->tank_by_number( 522 ), array( 'actor_id' => 7, 'source' => 'manual' ), $today, $settings, '' );
$part  = $split['stay_id'];
Admin::identify_stay( $store, $store->stay( $part, $today, $settings ), array( 'species' => 'Green Test Blenny' ), $base, $today, $settings );
$events = $store->event_count();
$r      = Admin_Workbook::identify_line( $store, $store->order( $order_id ), array( 'line_id' => fh_line( $store, $order_id, 0 )['id'], 'species' => 'Powder Test Tang', 'product_id' => 22, 'size' => '2-3' ), $today, $settings, 7 );
$line   = fh_line( $store, $order_id, 0 );
$stay   = $store->stay( $tang, $today, $settings );
check( 'a checked-in line corrected on the order page: the line, and the stay that still carried its old name', empty( $r['error'] ) && array( $tang ) === $r['stays'] && 'Powder Test Tang' === $line['species'] && 3 === $line['species_id'] && '2-3' === $line['size_slug'] && 'Blue Test Tang' === $line['label_name'] && 'Powder Test Tang' === $stay['species'] && 22 === $stay['product_id'] && '2-3' === $stay['size_slug'] );
check( 'its reply counts the stays that followed', 'Identified: Blue Test Tang → Powder Test Tang · 2-3" · 1 stay follows · arrived labeled: Blue Test Tang.' === $r['message'], $r['message'] );
check( 'that stay gets the event; the sister already identified as something else keeps its own and stays noted on the line', $events + 1 === $store->event_count() && 'identified: Blue Test Tang → Powder Test Tang · 2-3"' === $store->last_event()['note'] && $tang === $store->last_event()['stay_id'] && 'Green Test Blenny' === $store->stay( $part, $today, $settings )['species'] && '[part of this line is Green Test Blenny — stay #' . $part . ']' === $line['notes'] );

$closed_store = fh_identify_store();
$closed_order = fh_identify_order( $closed_store, array( array( 'species' => 'striped eel', 'needs_id' => '1', 'qty_ordered' => 3, 'qty_received' => 3, 'box_no' => 1, 'box_pct' => '30' ) ), $today );
$out          = Orders::check_in( $closed_store, $closed_store->order( $closed_order ), $today, $settings, array( 'actor_id' => 7 ) );
$alive        = $out['created'][0]['stay_id'];
$split        = $closed_store->split_stay( $closed_store->stay( $alive, $today, $settings ), 1, $closed_store->tank_by_number( 522 ), array( 'actor_id' => 7, 'source' => 'manual' ), $today, $settings, '' );
$dead         = $split['stay_id'];
$closed_store->record_event( $dead, array( 'type' => 'loss', 'qty' => 1, 'actor_id' => 7, 'source' => 'manual', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$r            = Admin::identify_stay( $closed_store, $closed_store->stay( $alive, $today, $settings ), array( 'species' => 'Lined Test Wrasse', 'product_id' => 21 ), $base, $today, $settings );
check( 'a sister stay that died unidentified still counts: the line waits (and still reads "??")', 'closed' === $closed_store->stay( $dead, $today, $settings )['status'] && 'part' === $r['result']['line'] && true === fh_line( $closed_store, $closed_order, 0 )['needs_id'] && 1 === Admin_Workbook::unknown_lines( $closed_store->order( $closed_order ) ) && false !== strpos( $r['message'], 'the whole line on its order' ) );
$r = Admin_Workbook::identify_line( $closed_store, $closed_store->order( $closed_order ), array( 'line_id' => fh_line( $closed_store, $closed_order, 0 )['id'], 'species' => 'Lined Test Wrasse', 'product_id' => 21 ), $today, $settings, 7 );
check( 'correcting the line on its order reaches the closed stay too: the line and every stay are the fish, nothing left to identify, the note gone, the share written', empty( $r['error'] ) && array( $dead ) === $r['stays'] && 'Lined Test Wrasse' === $closed_store->stay( $dead, $today, $settings )['species'] && false === $closed_store->stay( $dead, $today, $settings )['needs_id'] && false === fh_line( $closed_store, $closed_order, 0 )['needs_id'] && '' === fh_line( $closed_store, $closed_order, 0 )['notes'] && '?? striped eel' === fh_line( $closed_store, $closed_order, 0 )['label_name'] && null === $r['label'] && 0 === Admin_Workbook::unknown_lines( $closed_store->order( $closed_order ) ) && 1 === count( $closed_store->box_shares ) && near( array_values( $closed_store->box_shares )[0]['pct_per_fish'], 10.0 ), json_encode( $r ) );

echo "10. The order editor's grid\n";
$post = static function ( $store, $order_id, array $changes = array(), array $header = array() ) use ( $today ) {
	$lines = array();
	foreach ( $store->order( $order_id )['lines'] as $i => $line ) {
		$row = array( 'id' => $line['id'], 'species' => $line['species'], 'species_id' => (string) $line['species_id'], 'sci_name' => $line['sci_name'], 'size_slug' => (string) $line['size_slug'], 'qty_ordered' => $line['qty_ordered'], 'qty_received' => $line['qty_received'], 'box_no' => $line['box_no'], 'box_pct' => $line['box_pct'], 'unit_price' => $line['unit_price'] );
		if ( $line['needs_id'] ) {
			$row['needs_id'] = '1';
		}
		$lines[] = array_merge( $row, isset( $changes[ $i ] ) ? $changes[ $i ] : array() );
	}
	$order = $store->order( $order_id );
	return Admin_Workbook::save_order( $store, array_merge( array( 'ref' => $order['ref'], 'supplier' => $order['supplier'], 'origin' => $order['origin'], 'order_date' => $order['order_date'], 'received_date' => $order['received_date'], 'status' => $order['status'], 'cost_mode' => 'box', 'boxes' => 1, 'domestic_flight' => '100', 'import_fees' => '100', 'lines' => $lines ), $header ), $order_id, $today );
};
$events = $store->event_count();
$saved  = $post( $store, $order_id );
check( 'a save that changes no fish corrects nothing: no event, no stay touched, the line\'s notes and label kept (the grid has no field for them)', empty( $saved['error'] ) && 0 === $saved['followed'] && 'Order saved.' === $saved['message'] && $events === $store->event_count() && '[part of this line is Green Test Blenny — stay #' . $part . ']' === fh_line( $store, $order_id, 0 )['notes'] && 'Blue Test Tang' === fh_line( $store, $order_id, 0 )['label_name'] );
$saved = $post( $store, $order_id, array( 0 => array( 'species' => 'Lined Test Wrasse', 'species_id' => '1', 'size_slug' => '1-2' ) ) );
$line  = fh_line( $store, $order_id, 0 );
$stay  = $store->stay( $tang, $today, $settings );
check( 'a line that became a stay, its species and size changed in the grid: one correction — the stay follows, with its event', empty( $saved['error'] ) && 1 === $saved['followed'] && 'Order saved. 1 stay follows its corrected line.' === $saved['message'] && 'Lined Test Wrasse' === $stay['species'] && 21 === $stay['product_id'] && '1-2' === $stay['size_slug'] && 'identified: Powder Test Tang → Lined Test Wrasse · 1-2"' === $store->last_event()['note'] && 'Lined Test Wrasse' === $line['species'] && 1 === $line['species_id'], $saved['message'] );
check( '…the label still the first one, the other sister still its own', 'Blue Test Tang' === $line['label_name'] && 'Green Test Blenny' === $store->stay( $part, $today, $settings )['species'] );
$events = $store->event_count();
$saved  = $post( $store, $order_id, array( 0 => array( 'size_slug' => '3-4' ) ) );
check( 'a size alone: the stay follows, without an event', 1 === $saved['followed'] && '3-4' === $store->stay( $tang, $today, $settings )['size_slug'] && '3-4' === fh_line( $store, $order_id, 0 )['size_slug'] && $events === $store->event_count() );
$saved = $post( $store, $order_id, array( 0 => array( 'needs_id' => '1', 'species' => 'striped thing' ) ) );
$stay  = $store->stay( $tang, $today, $settings );
check( 'the "??" box ticked on a checked-in line: the line and its stay need identifying', '?? striped thing' === fh_line( $store, $order_id, 0 )['species'] && true === fh_line( $store, $order_id, 0 )['needs_id'] && '?? striped thing' === $stay['species'] && true === $stay['needs_id'] && null === $stay['product_id'] && null === fh_line( $store, $order_id, 0 )['share_key'] );
$saved = $post( $store, $order_id, array( 0 => array( 'needs_id' => '0', 'species' => 'Blue Test Tang', 'species_id' => '2' ) ) );
check( '…and unticked with the real name: identified again', 'Blue Test Tang' === $store->stay( $tang, $today, $settings )['species'] && false === $store->stay( $tang, $today, $settings )['needs_id'] && false === fh_line( $store, $order_id, 0 )['needs_id'] && null !== fh_line( $store, $order_id, 0 )['share_key'] );
$draft  = fh_identify_order( $store, array( array( 'species' => 'Red Test Goby', 'qty_ordered' => 5, 'qty_received' => 5, 'box_pct' => '10' ) ), $today, array( 'ref' => 'T-2' ) );
$events = $store->event_count();
$saved  = $post( $store, $draft, array( 0 => array( 'species' => 'Green Test Blenny' ) ) );
check( 'a line without a stay is the order as typed: retyping its species records no label and writes no event ("Correct the fish…" does)', empty( $saved['error'] ) && 0 === $saved['followed'] && 'Green Test Blenny' === fh_line( $store, $draft, 0 )['species'] && null === fh_line( $store, $draft, 0 )['label_name'] && $events === $store->event_count() );
check( '…and the reply asks for no reload', false === $saved['reload'] && true === $post( $store, $order_id, array( 0 => array( 'size_slug' => '2-3' ) ) )['reload'] );
$saved = $post( $store, $draft, array( 0 => array( 'species' => '?? spotted thing' ) ) );
check( 'a name typed with a leading "??" on the form is an unknown fish without the box', true === fh_line( $store, $draft, 0 )['needs_id'] && '?? spotted thing' === fh_line( $store, $draft, 0 )['species'] && null === fh_line( $store, $draft, 0 )['share_key'] );
check( '…and marking a named line that received fish unknown keeps its name as what the label said (the fish was not what the line said)', 'Green Test Blenny' === fh_line( $store, $draft, 0 )['label_name'] && true === $saved['reload'] );
$saved = $post( $store, $draft, array( 0 => array( 'needs_id' => '0', 'species' => 'Red Test Goby' ) ) );
check( 'identified later in the grid: the label is still the first one, and the line counts as mislabeled', 'Red Test Goby' === fh_line( $store, $draft, 0 )['species'] && false === fh_line( $store, $draft, 0 )['needs_id'] && 'Green Test Blenny' === fh_line( $store, $draft, 0 )['label_name'] && true === Identify::is_mislabeled( fh_line( $store, $draft, 0 ) ) );
$none  = fh_identify_order( $store, array( array( 'species' => 'Red Test Goby', 'qty_ordered' => 5 ) ), $today, array( 'ref' => 'T-3', 'status' => 'draft' ) );
$saved = $post( $store, $none, array( 0 => array( 'needs_id' => '1' ) ) );
check( 'a line nothing arrived on yet, ticked "??": unknown, but nothing was labeled', true === fh_line( $store, $none, 0 )['needs_id'] && '?? Red Test Goby' === fh_line( $store, $none, 0 )['species'] && null === fh_line( $store, $none, 0 )['label_name'] && false === $saved['reload'] );
$arrived = fh_identify_order( $store, array( array( 'species' => 'spotted thing', 'needs_id' => '1', 'qty_ordered' => 2, 'qty_received' => 2, 'box_pct' => '10' ) ), $today, array( 'ref' => 'T-4' ) );
$saved   = $post( $store, $arrived, array( 0 => array( 'needs_id' => '0', 'species' => 'Red Test Goby' ) ) );
check( 'a line that arrived unknown, named in the grid before it is a stay: its "??" name is kept in the label\'s place (nothing to show: no reload)', 'Red Test Goby' === fh_line( $store, $arrived, 0 )['species'] && false === fh_line( $store, $arrived, 0 )['needs_id'] && '?? spotted thing' === fh_line( $store, $arrived, 0 )['label_name'] && false === $saved['reload'] );
Admin_Workbook::identify_line( $store, $store->order( $arrived ), array( 'line_id' => fh_line( $store, $arrived, 0 )['id'], 'species' => 'Green Test Blenny' ), $today, $settings, 7 );
check( '…so correcting that name later is no mislabel', 'Green Test Blenny' === fh_line( $store, $arrived, 0 )['species'] && '?? spotted thing' === fh_line( $store, $arrived, 0 )['label_name'] && false === Identify::is_mislabeled( fh_line( $store, $arrived, 0 ) ) );
check( 'line_differs(): the name as written, the flag or the size', ! Identify::line_differs( array( 'species' => 'A ', 'size_slug' => null ), array( 'species' => 'A', 'size_slug' => '' ) ) && Identify::line_differs( array( 'species' => 'A' ), array( 'species' => 'a' ) ) && Identify::line_differs( array( 'species' => 'A' ), array( 'species' => 'A', 'needs_id' => true ) ) && Identify::line_differs( array( 'species' => 'A', 'size_slug' => '1-2' ), array( 'species' => 'A', 'size_slug' => '2-3' ) ) );

echo "11. The Stays tab: Identify… refuses bad input; the plain Edit follows the same rules\n";
$store    = fh_identify_store();
$order_id = fh_identify_order( $store, array( array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'sci_name' => 'Testus caeruleus', 'size_slug' => '2-3', 'qty_ordered' => 4, 'qty_received' => 4, 'box_no' => 1, 'box_pct' => '80', 'unit_price' => '20' ) ), $today );
$out      = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
$sid      = $out['created'][0]['stay_id'];
$events   = $store->event_count();
$r        = Admin::identify_stay( $store, $store->stay( $sid, $today, $settings ), array( 'species' => '  ', 'size' => '2-3' ), $base, $today, $settings );
check( 'Identify… without a fish is refused (400) and nothing is written', false === $r['ok'] && 400 === $r['status'] && false !== strpos( $r['message'], 'Which fish' ) && 'Blue Test Tang' === $store->stay( $sid, $today, $settings )['species'] && $events === $store->event_count() );
$r = Admin::identify_stay( $store, $store->stay( $sid, $today, $settings ), array( 'species' => 'Powder Test Tang', 'size' => 'jumbo' ), $base, $today, $settings );
check( '…and with a size that is not in the list', false === $r['ok'] && 400 === $r['status'] && false !== strpos( $r['message'], 'not in the list' ) && 'Blue Test Tang' === $store->stay( $sid, $today, $settings )['species'] && 'Blue Test Tang' === fh_line( $store, $order_id, 0 )['species'] && $events === $store->event_count() );
$edit = static function ( array $raw ) use ( $store, $sid, $base, $today, $settings ) {
	return Admin::edit_stay_fields( $store, $store->stay( $sid, $today, $settings ), $raw, $base, $today, $settings );
};
$r    = $edit( array( 'species' => 'Blue Test Tang', 'supplier' => 'Test Supplier One', 'order_ref' => 'T-1', 'size_slug' => '2-3' ) );
check( 'an Edit that changes no fish writes no event and touches no line', true === $r['ok'] && 'Stay saved.' === $r['message'] && $events === $store->event_count() && null === fh_line( $store, $order_id, 0 )['label_name'] && 'T-1' === $store->stay( $sid, $today, $settings )['order_ref'] );
$r    = $edit( array( 'species' => 'Blue Test Tang', 'supplier' => 'Test Supplier One', 'size_slug' => '3-4' ) );
$line = fh_line( $store, $order_id, 0 );
check( 'the size changed in Edit: the stay and its order line follow (the share re-keyed to that size), no event', true === $r['ok'] && 'Stay saved. The order line follows.' === $r['message'] && '3-4' === $store->stay( $sid, $today, $settings )['size_slug'] && '3-4' === $line['size_slug'] && '3-4"' === $line['size_label'] && 2 === $line['species_id'] && 'Testus caeruleus' === $line['sci_name'] && null === $line['label_name'] && $events === $store->event_count() && '3-4' === $store->box_share_by_key( (string) $line['share_key'] )['size_slug'] && 1 === count( $store->box_shares ), $r['message'] );
$r    = $edit( array( 'species' => 'Powder Test Tang', 'product_id' => '22', 'supplier' => 'Test Supplier One', 'size_slug' => '3-4' ) );
$line = fh_line( $store, $order_id, 0 );
$stay = $store->stay( $sid, $today, $settings );
check( 'the species changed in Edit is the same correction: the line follows and keeps what the label said, the event is written', true === $r['ok'] && 'Powder Test Tang' === $stay['species'] && 22 === $stay['product_id'] && 'Powder Test Tang' === $line['species'] && 3 === $line['species_id'] && 'Testus pulvis' === $line['sci_name'] && 'Blue Test Tang' === $line['label_name'] && $events + 1 === $store->event_count() && 'identified: Blue Test Tang → Powder Test Tang · 3-4"' === $store->last_event()['note'] && 's:3' === $store->box_share_by_key( (string) $line['share_key'] )['fish_key'] );
check( 'its reply', 'Stay saved. Identified: Blue Test Tang → Powder Test Tang · 3-4" · the order line follows · arrived labeled: Blue Test Tang.' === $r['message'], $r['message'] );
$r    = $edit( array( 'species' => 'Powder Test Tang', 'supplier' => 'Test Supplier One', 'size_slug' => '3-4' ) );
check( 'the product link is exactly the Product ID typed in Edit (none: unlinked) — not a fish correction: no event', true === $r['ok'] && null === $store->stay( $sid, $today, $settings )['product_id'] && $events + 1 === $store->event_count() );
$r    = $edit( array( 'species' => '?? looks like a tang', 'supplier' => 'Test Supplier One', 'size_slug' => '3-4' ) );
$line = fh_line( $store, $order_id, 0 );
check( 'a name typed with a leading "??" in Edit marks the fish unknown, on the stay and on its line', true === $r['ok'] && true === $store->stay( $sid, $today, $settings )['needs_id'] && '?? looks like a tang' === $store->stay( $sid, $today, $settings )['species'] && true === $line['needs_id'] && 'Blue Test Tang' === $line['label_name'] && null === $line['share_key'] && 0 === count( $store->box_shares ) );
$r    = $edit( array( 'species' => 'Renamed Test Tang', 'supplier' => 'Someone Else', 'size_slug' => 'jumbo' ) );
check( 'the older guard still stands: a size that is not in the list refuses the whole Edit', false === $r['ok'] && 400 === $r['status'] && '?? looks like a tang' === $store->stay( $sid, $today, $settings )['species'] && 'Test Supplier One' === $store->stay( $sid, $today, $settings )['supplier'] );

echo "12. The Front Desk: an unknown arrival, and Identify on a group's sheet\n";
$store = fh_identify_store();
$fd    = array(
	'store'    => $store,
	'settings' => $settings,
	'today'    => $today,
	'tank'     => $store->tank_by_number( 521 ),
	'base'     => array( 'actor_id' => 8, 'source' => 'qr' ),
);
$r     = Front_Desk::perform( 'arrival', $fd, array( 'species' => 'small wrasse', 'unknown' => '1', 'product_id' => 21, 'qty' => 2, 'size' => '1-2' ) );
$s     = $store->stay( 1, $today, $settings );
check( '"Unknown fish (??)": the group is "??" and what was typed, flagged, linked to no product — with its tank, count and size', empty( $r['error'] ) && '?? small wrasse' === $s['species'] && true === $s['needs_id'] && null === $s['product_id'] && 2 === $s['qty_live'] && '1-2' === $s['size_slug'] && 521 === $s['tank_number'] && '2 × ?? small wrasse · 1-2" in Tank 521' === $r['message'], json_encode( $r ) );
$r = Front_Desk::perform( 'arrival', $fd, array( 'unknown' => '1', 'qty' => 1 ) );
check( 'unknown with nothing typed is "??" (no "Which fish?")', empty( $r['error'] ) && '??' === $store->stay( 2, $today, $settings )['species'] && true === $store->stay( 2, $today, $settings )['needs_id'] );
$r = Front_Desk::perform( 'arrival', $fd, array( 'species' => '?? odd eel', 'qty' => 1 ) );
check( 'a name that starts with "??" is an unknown fish without the choice', empty( $r['error'] ) && true === $store->stay( 3, $today, $settings )['needs_id'] && '?? odd eel' === $store->stay( 3, $today, $settings )['species'] );
$r = Front_Desk::perform( 'arrival', $fd, array( 'species' => 'Red Test Goby', 'qty' => 3, 'unknown' => '0' ) );
check( 'any other arrival is a known fish, as before', empty( $r['error'] ) && false === $store->stay( 4, $today, $settings )['needs_id'] && '3 × Red Test Goby in Tank 521' === $r['message'] );
check( 'a row says so: needsId', true === Front_Desk::occupant( $store->stay( 1, $today, $settings ) )['needsId'] && false === Front_Desk::occupant( $store->stay( 4, $today, $settings ) )['needsId'] && '?? small wrasse' === Front_Desk::occupant( $store->stay( 1, $today, $settings ) )['species'] );
check( 'the page\'s strings name the choice, the marker and the action', isset( Front_Desk::i18n()['unknownFish'], Front_Desk::i18n()['needsId'], Front_Desk::i18n()['identify'], Front_Desk::i18n()['correctFish'], Front_Desk::i18n()['identifyHint'] ) && 'Unknown fish (??)' === Front_Desk::i18n()['unknownFish'] );
$events = $store->event_count();
$r      = Front_Desk::perform( 'identify', $fd, array( 'stay_id' => 1, 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '2-3' ) );
$s      = $store->stay( 1, $today, $settings );
check( 'Identify on the sheet: the fish, its product, its size; the flag gone; the note event by that user and source', empty( $r['error'] ) && 'Lined Test Wrasse' === $s['species'] && 21 === $s['product_id'] && '2-3' === $s['size_slug'] && false === $s['needs_id'] && $events + 1 === $store->event_count() && 'identified: ?? small wrasse → Lined Test Wrasse · 2-3"' === $store->last_event()['note'] && 8 === $store->last_event()['actor_id'] && 'qr' === $store->last_event()['source'], json_encode( $r ) );
check( 'the toast words it and offers no Undo (who a fish is is corrected again, not undone)', 'Identified: ?? small wrasse → Lined Test Wrasse · 2-3"' === $r['message'] && array() === $r['event_ids'] );
$r = Front_Desk::perform( 'identify', $fd, array( 'stay_id' => 1, 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '2-3' ) );
check( 'the same again: nothing changed, nothing written', empty( $r['error'] ) && 'Lined Test Wrasse: nothing changed' === $r['message'] && $events + 1 === $store->event_count() );
$r = Front_Desk::perform( 'identify', $fd, array( 'stay_id' => 4, 'species' => 'ghost', 'unknown' => 1 ) );
check( 'a known fish can be marked unknown from the sheet', empty( $r['error'] ) && '?? ghost' === $store->stay( 4, $today, $settings )['species'] && true === $store->stay( 4, $today, $settings )['needs_id'] && 0 === strpos( $r['message'], 'Marked unknown: Red Test Goby → ?? ghost' ) );
$events = $store->event_count();
check( 'no fish, a size that is not in the list, or a group that is not in this tank: refused, nothing written', isset( Front_Desk::perform( 'identify', $fd, array( 'stay_id' => 2 ) )['error'] ) && isset( Front_Desk::perform( 'identify', $fd, array( 'stay_id' => 2, 'species' => 'x', 'size' => 'jumbo' ) )['error'] ) && isset( Front_Desk::perform( 'identify', array_merge( $fd, array( 'tank' => $store->tank_by_number( 522 ) ) ), array( 'stay_id' => 2, 'species' => 'x' ) )['error'] ) && '??' === $store->stay( 2, $today, $settings )['species'] && $events === $store->event_count() );
$order_id = fh_identify_order( $store, array( array( 'species' => 'Blue Test Tang', 'species_id' => 2, 'qty_ordered' => 2, 'qty_received' => 2, 'box_pct' => '50', 'tank_id' => $store->tank_by_number( 523 )['id'] ) ), $today );
$out      = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
$r        = Front_Desk::perform( 'identify', array_merge( $fd, array( 'tank' => $store->tank_by_number( (int) $out['created'][0]['tank'] ) ) ), array( 'stay_id' => $out['created'][0]['stay_id'], 'species' => 'Powder Test Tang', 'product_id' => 22 ) );
check( 'from the phone too the order line follows, keeps the label and the share is written', empty( $r['error'] ) && 'Powder Test Tang' === fh_line( $store, $order_id, 0 )['species'] && 'Blue Test Tang' === fh_line( $store, $order_id, 0 )['label_name'] && 's:3' === $store->box_share_by_key( (string) fh_line( $store, $order_id, 0 )['share_key'] )['fish_key'] && 'Identified: Blue Test Tang → Powder Test Tang · the order line follows · arrived labeled: Blue Test Tang' === $r['message'], json_encode( $r ) );
$pstore = fh_identify_store();
Front_Desk::perform( 'arrival', array_merge( $fd, array( 'store' => $pstore ) ), array( 'species' => 'Powder Test Tang', 'product_id' => 22, 'qty' => 3 ) );
$live   = $pstore->product_facts( 22, $today, $settings )['live_total'];
$r      = Admin::identify_stay( $pstore, $pstore->stay( 1, $today, $settings ), array( 'species' => 'Powder Test Tang', 'product_id' => 0, 'size' => '2-3', 'unknown' => 0 ), $base, $today, $settings );
check( '"Correct fish…" with the same name typed (not picked) and a size: the size is set, the product link stays, the fish page keeps the group', true === $r['ok'] && 22 === $pstore->stay( 1, $today, $settings )['product_id'] && '2-3' === $pstore->stay( 1, $today, $settings )['size_slug'] && 3 === $live && 3 === $pstore->product_facts( 22, $today, $settings )['live_total'], json_encode( $r ) );
$r = Front_Desk::perform( 'identify', array_merge( $fd, array( 'store' => $pstore ) ), array( 'stay_id' => 1, 'species' => 'powder test tang', 'size' => '3-4' ) );
check( '…from the phone too', empty( $r['error'] ) && 22 === $pstore->stay( 1, $today, $settings )['product_id'] && '3-4' === $pstore->stay( 1, $today, $settings )['size_slug'] );

echo "13. Flags and filters\n";
$open = $store->open_stays( array(), $today, $settings );
$need = $store->open_stays( array( 'needs_id' => true ), $today, $settings );
check( 'the Stays filter: only the fish that still need identifying', 5 === count( $open ) && array( 2, 3, 4 ) === array_column( $need, 'id' ) );
check( 'the request: needs_id=1 turns it on, anything else does not', true === Admin::filters_from_request( array( 'needs_id' => '1' ) )['needs_id'] && false === Admin::filters_from_request( array( 'needs_id' => 'yes' ) )['needs_id'] && false === Admin::filters_from_request( array( 'needs_id' => array( '1' ) ) )['needs_id'] && false === Admin::filters_from_request( array() )['needs_id'] );
$totals = Admin::totals( array( array( 'status' => 'active', 'occupants' => $open ), array( 'status' => 'offline', 'occupants' => $need ) ) );
check( 'the Board counts them (an offline tank skipped)', 3 === $totals['needs_id'] && 0 === Admin::totals( array( array( 'status' => 'active', 'occupants' => array( $store->stay( 1, $today, $settings ) ) ) ) )['needs_id'] );
check( 'its chip links to the Stays tab filtered to them', false !== strpos( Admin::page_url( 'stays', array( 'needs_id' => '1' ) ), 'tab=stays' ) && false !== strpos( Admin::page_url( 'stays', array( 'needs_id' => '1' ) ), 'needs_id=1' ) );
check( 'the marker: "needs identifying" on a flagged row only, escaped', '' === Admin::unknown_html( array( 'needs_id' => false ) ) && '' === Admin::unknown_html( array() ) && false !== strpos( Admin::unknown_html( array( 'needs_id' => true ) ), '>needs identifying</span>' ) && false !== strpos( Admin::unknown_html( array( 'needs_id' => 1 ) ), 'class="fh-cr-warn fh-cr-unknown"' ) );
check( 'the tank screens print the "??" with the species, whatever reads it', '?? ghost' === $need[2]['species'] && 0 === strpos( Admin::csv_row( $need[2] )[1], '?? ghost' ) );

echo "14. Stats: mislabeled, and not identified yet\n";
$L = static function ( $species, $received, array $extra = array() ) {
	return array_merge( array( 'species' => $species, 'qty_ordered' => 5, 'qty_received' => $received, 'unit_price' => 1.0, 'worth' => 5.0, 'qty_doa' => 0, 'qty_survived' => null, 'group_name' => 'Test Tangs' ), $extra );
};
$orders = array(
	array(
		'id'            => 1,
		'ref'           => 'T-1',
		'supplier'      => 'Test Supplier One',
		'origin'        => 'Testland',
		'received_date' => '2026-03-01',
		'cost_mode'     => 'box',
		'lines'         => array(
			$L( 'Powder Test Tang', 4, array( 'label_name' => 'Blue Test Tang' ) ),            // mislabeled
			$L( '?? small wrasse', 2, array( 'needs_id' => true, 'label_name' => 'Red Test Goby', 'group_name' => '' ) ), // not identified yet (not "mislabeled" while unknown)
			$L( 'Blue Test Tang', 3, array( 'label_name' => 'blue test  TANG' ) ),              // corrected back to its label: fine
			$L( 'Green Test Blenny', 0, array( 'label_name' => 'Something Else' ) ),            // nothing received: not counted at all
			$L( 'Lined Test Wrasse', 6 ),                                                      // never corrected: fine
		),
	),
	array(
		'id'            => 2,
		'ref'           => 'T-2',
		'supplier'      => 'test supplier one',
		'origin'        => 'Otherland',
		'received_date' => '2026-03-08',
		'cost_mode'     => 'box',
		'lines'         => array(
			$L( 'Lined Test Wrasse', 5, array( 'label_name' => 'Red Test Goby' ) ),            // mislabeled
			$L( 'Red Test Goby', 5 ),
		),
	),
	array(
		'id'            => 3,
		'ref'           => 'T-3',
		'supplier'      => 'Test Supplier Two',
		'origin'        => 'Testland',
		'received_date' => '2026-03-09',
		'cost_mode'     => 'bag',
		'lines'         => array( $L( 'Red Test Goby', 5 ), $L( 'Blue Test Tang', null ) ),
	),
);
check( 'label_counts(): of the lines that received fish — mislabeled, and not identified yet apart', array( 'received_lines' => 4, 'mislabeled' => 1, 'unidentified' => 1 ) === Stats::label_counts( $orders[0]['lines'] ) && array( 'received_lines' => 0, 'mislabeled' => 0, 'unidentified' => 0 ) === Stats::label_counts( array( 'junk', array( 'species' => 'x', 'label_name' => 'y' ) ) ) );
$stats = Stats::build( $orders );
$row   = $stats['orders'][0];
check( 'each order row carries them, with the rate over its received lines', 4 === $row['received_lines'] && 1 === $row['mislabeled'] && near( $row['mislabeled_rate'], 0.25 ) && 1 === $row['unidentified'] && near( $stats['orders'][1]['mislabeled_rate'], 0.5 ) && 0 === $stats['orders'][2]['mislabeled'] && near( $stats['orders'][2]['mislabeled_rate'], 0.0 ) );
$sup = array();
foreach ( $stats['by_supplier'] as $g ) {
	$sup[ strtolower( $g['name'] ) ] = $g;
}
check( 'by supplier (any case): Test Supplier One 2 mislabeled of 6 received lines (33.3 %), 1 not identified yet; Test Supplier Two none of 1', 2 === $sup['test supplier one']['mislabeled'] && 6 === $sup['test supplier one']['received_lines'] && near( $sup['test supplier one']['mislabeled_rate'], 2 / 6 ) && 1 === $sup['test supplier one']['unidentified'] && 0 === $sup['test supplier two']['mislabeled'] && 1 === $sup['test supplier two']['received_lines'] && 0 === $sup['test supplier two']['unidentified'], json_encode( $stats['by_supplier'] ) );
$org = array();
foreach ( $stats['by_origin'] as $g ) {
	$org[ $g['name'] ] = $g;
}
check( 'by origin: Testland 1 of 5 (20 %) and 1 not identified yet; Otherland 1 of 2 (50 %)', 1 === $org['Testland']['mislabeled'] && 5 === $org['Testland']['received_lines'] && near( $org['Testland']['mislabeled_rate'], 0.2 ) && 1 === $org['Testland']['unidentified'] && 1 === $org['Otherland']['mislabeled'] && near( $org['Otherland']['mislabeled_rate'], 0.5 ) && 0 === $org['Otherland']['unidentified'] );
check( 'the totals: 2 mislabeled of 7 received lines, 1 not identified yet', 2 === $stats['totals']['mislabeled'] && 7 === $stats['totals']['received_lines'] && near( $stats['totals']['mislabeled_rate'], 2 / 7 ) && 1 === $stats['totals']['unidentified'] );
check( 'no received line: the rate is undefined, not 0', null === Stats::build( array() )['totals']['mislabeled_rate'] && null === Stats::row( array(), Costing::compute( array(), array() )['order'] )['mislabeled_rate'] && 0 === Stats::row( array(), Costing::compute( array(), array() )['order'] )['mislabeled'] );
check( 'the older figures do not move', 3 === $stats['totals']['orders'] && 30 === $stats['totals']['received'] && 2 === count( $stats['by_group'] ) && array_key_exists( 'mislabeled', $stats['by_group'][0] ) );

echo "15. Exports\n";
$head = Admin::csv_header();
check( 'the stays CSV ends with needs_id: 1 while the fish still has to be identified, else 0', 'needs_id' === $head[ count( $head ) - 1 ] && '1' === Admin::csv_row( $need[0] )[ count( $head ) - 1 ] && '0' === Admin::csv_row( $store->stay( 1, $today, $settings ) )[ count( $head ) - 1 ] && count( $head ) === count( Admin::csv_row( $need[0] ) ) );
$rows = Admin_Workbook::stays_rows( array( $need[0], $store->stay( 1, $today, $settings ) ) );
check( 'the stays sheet: the same, typed', $head === $rows[0] && 1 === $rows[1][ count( $head ) - 1 ] && 0 === $rows[2][ count( $head ) - 1 ] && '??' === $rows[1][1] );
$sheets = Admin_Workbook::orders_sheets( array( Admin_Workbook::resolve_order( $store, $store->order( $order_id ) ), Admin_Workbook::resolve_order( $store, $store->order( fh_identify_order( $store, array( array( 'species' => 'striped eel', 'needs_id' => '1', 'qty_ordered' => 1 ) ), $today, array( 'ref' => 'T-9' ) ) ) ) ) );
$lhead  = $sheets['Lines'][0];
$first  = array_combine( $lhead, $sheets['Lines'][1] );
$second = array_combine( $lhead, $sheets['Lines'][2] );
check( 'the orders workbook: the Lines sheet ends with needs_id and label_name, after the columns it had', array( 'size', 'box_no', 'box_pct', 'needs_id', 'label_name' ) === array_slice( $lhead, -5 ) && false === $first['needs_id'] && 'Blue Test Tang' === $first['label_name'] && 'Powder Test Tang' === $first['species'] && true === $second['needs_id'] && null === $second['label_name'] && '?? striped eel' === $second['species'] );
$out = fopen( 'php://memory', 'w+' );
Admin_Workbook::write_csv( $out, $sheets['Lines'] );
rewind( $out );
$csv = stream_get_contents( $out );
fclose( $out );
check( 'the lines CSV: the flag as 1 / 0, the label as text', false !== strpos( $csv, ',needs_id,label_name' ) && false !== strpos( $csv, ',0,"Blue Test Tang"' ) && 1 === preg_match( '/,1,\n?$/', rtrim( $csv, "\n" ) . "\n" ), $csv );
$st = Admin_Workbook::stats_sheets( $stats, 2026 );
check( 'the stats workbook: Orders (so the CSV too), By supplier and By origin end with received_lines, mislabeled, mislabeled_rate, not_identified', array( 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) === array_slice( $st['Orders'][0], -4 ) && array( 4, 1, 0.25, 1 ) === array_slice( $st['Orders'][1], -4 ) && array( 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) === array_slice( $st['By supplier'][0], -4 ) && array( 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) === array_slice( $st['By origin'][0], -4 ) && count( $st['By supplier'][0] ) === count( $st['By supplier'][1] ) && 2 === $st['By supplier'][1][15] && 1 === $st['By supplier'][1][17] );
check( 'By group keeps the columns it had; Totals ends with the four rows', 'ratio' === $st['By group'][0][ count( $st['By group'][0] ) - 1 ] && array( 'received_lines', 'mislabeled', 'mislabeled_rate', 'not_identified' ) === array_column( array_slice( $st['Totals'], -4 ), 0 ) && array( 7, 2, 2 / 7, 1 ) === array_column( array_slice( $st['Totals'], -4 ), 1 ) );

echo "16. The importer flags nothing\n";
$dir  = fh_test_tmp_dir();
$path = fh_fixture_build( $dir . '/FisHotel-2031-fixture.xlsx' );
$wb   = new Workbook();
$wb->open( $path );
$map = Import_Map::map( $wb );
$wb->close();
fh_test_rmdir( $dir );
$map['orders'][0]['lines'][0]['name'] = '?? Test Mystery Fish';
$istore                               = fh_identify_store();
$result                               = Import::run( $map, $istore, array( 'today' => '2026-10-17', 'settings' => $settings, 'actor_id' => 1, 'stays_days' => 4000 ) );
$clean                                = true;
$n                                    = 0;
$named                                = false;
foreach ( $istore->lines as $line ) {
	$line  = Store::hydrate_line( $line );
	$clean = $clean && false === $line['needs_id'] && null === $line['label_name'] && null === $line['box_pct'] && null === $line['box_no'] && null === $line['size_slug'] && null === $line['share_key'];
	$named = $named || '?? Test Mystery Fish' === $line['species'];
	$n++;
}
check( 'every imported line: no flag, no label name, no size, no box number, no percent, no remembered share — even one the workbook itself calls "??"', $n > 0 && $clean && $named && array() === $istore->box_shares, json_encode( $result['errors'] ?? array() ) );
$flagged = 0;
foreach ( $istore->stays as $row ) {
	$flagged += ! empty( $row['needs_id'] ) ? 1 : 0;
}
check( 'and so are the stays the import checks in', count( $istore->stays ) > 0 && 0 === $flagged );

echo "17. The three AJAX endpoints: capability and nonce before the new action\n";
$call = static function ( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		$handler();
	} catch ( Fh_Test_Json $reply ) {
		return $reply;
	}
	return null;
};
$endpoints = array(
	'the Stays tab (fishotel_cleanroom_stay)'  => array( array( new Admin(), 'ajax_stay' ), Admin::NONCE_AJAX, array( 'op' => 'identify', 'stay_id' => '1', 'species' => 'x' ) ),
	'the order page (fishotel_cleanroom_order)' => array( array( new Admin_Workbook(), 'ajax_order' ), Admin::NONCE_AJAX, array( 'op' => 'identify', 'order_id' => '1', 'line_id' => '1', 'species' => 'x' ) ),
	'the Front Desk (fishotel_cleanroom_act)'   => array( array( new Front_Desk(), 'ajax_act' ), Front_Desk::NONCE, array( 'act' => 'identify', 'tank' => '521', 'stay_id' => '1', 'species' => 'x' ) ),
);
foreach ( $endpoints as $label => $endpoint ) {
	list( $handler, $action, $post ) = $endpoint;
	$GLOBALS['fh_current_user']      = 9; // No capability.
	$reply                           = $call( $handler, array_merge( $post, array( 'nonce' => wp_create_nonce( $action ) ) ) );
	check( $label . ': without the capability → 403, whatever the nonce', $reply instanceof Fh_Test_Json && false === $reply->success && 403 === $reply->status );
	$GLOBALS['fh_current_user'] = 7;
	$reply                      = $call( $handler, array_merge( $post, array( 'nonce' => 'wrong' ) ) );
	check( $label . ': with a wrong nonce → 403', $reply instanceof Fh_Test_Json && false === $reply->success && 403 === $reply->status && 'bad nonce' === $reply->getMessage() );
	$reply = $call( $handler, $post );
	check( $label . ': without a nonce → 403', $reply instanceof Fh_Test_Json && 403 === $reply->status );
	$reply = $call( $handler, array_merge( $post, array( 'nonce' => wp_create_nonce( $action ) ) ) );
	check( $label . ': with both it gets past the guards (and stops at the database this test does not have)', $reply instanceof Fh_Test_Json && false === $reply->success && 403 !== $reply->status && 'bad nonce' !== $reply->getMessage() );
}
$_POST    = array();
$_REQUEST = array();

echo "18. A page loaded before a correction cannot undo it\n";
check( 'seen(): the name (spacing aside), the "??" flag, the size and a stay\'s product, as one token', 32 === strlen( Identify::seen( array() ) ) && Identify::seen( array( 'species' => ' Blue  Test Tang ', 'size_slug' => null ) ) === Identify::seen( array( 'species' => 'Blue Test Tang', 'needs_id' => false, 'size_slug' => '', 'product_id' => null ) ) && Identify::seen( array( 'species' => 'Blue Test Tang' ) ) !== Identify::seen( array( 'species' => 'Powder Test Tang' ) ) && Identify::seen( array( 'species' => '?? eel' ) ) !== Identify::seen( array( 'species' => '?? eel', 'needs_id' => true ) ) && Identify::seen( array( 'species' => 'x' ) ) !== Identify::seen( array( 'species' => 'x', 'size_slug' => '2-3' ) ) && Identify::seen( array( 'species' => 'x' ) ) !== Identify::seen( array( 'species' => 'x', 'product_id' => 22 ) ) );
check( 'is_stale(): a token that is no longer the row; no token (an older page, the importer) is never stale', Identify::is_stale( Identify::seen( array( 'species' => 'a' ) ), array( 'species' => 'b' ) ) && ! Identify::is_stale( Identify::seen( array( 'species' => 'a' ) ), array( 'species' => 'a' ) ) && ! Identify::is_stale( null, array( 'species' => 'b' ) ) && ! Identify::is_stale( '', array( 'species' => 'b' ) ) && ! Identify::is_stale( array( 'x' ), array( 'species' => 'b' ) ) );
/** The order page as it would post the order as stored now: every row with the token it was rendered with. */
$page = static function ( $store, $order_id ) {
	$rows = array();
	foreach ( $store->order( $order_id )['lines'] as $line ) {
		$row = array( 'id' => $line['id'], 'seen' => Identify::seen( $line ), 'species' => $line['species'], 'species_id' => (string) $line['species_id'], 'sci_name' => $line['sci_name'], 'size_slug' => (string) $line['size_slug'], 'qty_ordered' => $line['qty_ordered'], 'qty_received' => $line['qty_received'], 'box_no' => $line['box_no'], 'box_pct' => $line['box_pct'], 'unit_price' => $line['unit_price'] );
		if ( $line['needs_id'] ) {
			$row['needs_id'] = '1';
		}
		$rows[] = $row;
	}
	return $rows;
};
$save = static function ( $store, $order_id, array $rows ) use ( $today ) {
	$order = $store->order( $order_id );
	return Admin_Workbook::save_order( $store, array( 'ref' => $order['ref'], 'supplier' => $order['supplier'], 'origin' => $order['origin'], 'order_date' => $order['order_date'], 'received_date' => $order['received_date'], 'status' => $order['status'], 'cost_mode' => 'box', 'boxes' => 1, 'domestic_flight' => '100', 'import_fees' => '100', 'lines' => $rows ), $order_id, $today );
};
$phone = static function ( $store, $stay_id, array $fish ) use ( $settings, $today ) {
	$stay = $store->stay( $stay_id, $today, $settings );
	return Front_Desk::perform(
		'identify',
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $store->tank_by_number( (int) $stay['tank_number'] ),
			'base'     => array( 'actor_id' => 8, 'source' => 'qr' ),
		),
		array_merge( array( 'stay_id' => $stay_id ), $fish )
	);
};
$store    = fh_identify_store();
$order_id = fh_identify_order(
	$store,
	array(
		array( 'species' => 'small wrasse', 'needs_id' => '1', 'size_slug' => '1-2', 'qty_ordered' => 3, 'qty_received' => 3, 'box_no' => 1, 'box_pct' => '30', 'unit_price' => '5' ),
		array( 'species' => 'Powder Test Tang', 'species_id' => 3, 'qty_ordered' => 1, 'qty_received' => 1, 'box_no' => 1, 'box_pct' => '40', 'unit_price' => '30' ),
	),
	$today
);
$out      = Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 7 ) );
list( $a, $b ) = array_column( $out['created'], 'stay_id' );
$open     = $page( $store, $order_id ); // The order page is loaded now…
$phone( $store, $a, array( 'species' => 'Lined Test Wrasse', 'product_id' => 21, 'size' => '2-3' ) ); // …and the phone identifies both fish.
$phone( $store, $b, array( 'species' => 'Blue Test Tang' ) );
$events   = $store->event_count();
$shares   = $store->box_shares;
$open[1]['unit_price'] = '31'; // Only a price is changed on the page that was open.
$saved    = $save( $store, $order_id, $open );
$one      = fh_line( $store, $order_id, 0 );
$two      = fh_line( $store, $order_id, 1 );
check( 'the page that was open is saved: both lines keep the fish they were corrected to — name, species-list id, scientific name, flag and size', empty( $saved['error'] ) && 'Lined Test Wrasse' === $one['species'] && 1 === $one['species_id'] && 'Testus lineatus' === $one['sci_name'] && false === $one['needs_id'] && '2-3' === $one['size_slug'] && '2-3"' === $one['size_label'] && 'Blue Test Tang' === $two['species'] && 2 === $two['species_id'] && 'Testus caeruleus' === $two['sci_name'], json_encode( $saved ) );
check( '…their stays are not touched: the fish, the product link, no second event', 'Lined Test Wrasse' === $store->stay( $a, $today, $settings )['species'] && 21 === $store->stay( $a, $today, $settings )['product_id'] && '2-3' === $store->stay( $a, $today, $settings )['size_slug'] && 'Blue Test Tang' === $store->stay( $b, $today, $settings )['species'] && $events === $store->event_count() && 0 === $saved['followed'] );
check( '…what the label said, the remembered shares and the mislabel count stand', 'Powder Test Tang' === $two['label_name'] && '?? small wrasse' === $one['label_name'] && $shares == $store->box_shares && 2 === count( $store->box_shares ) && array( 'received_lines' => 2, 'mislabeled' => 1, 'unidentified' => 0 ) === Stats::label_counts( $store->order( $order_id )['lines'] ) );
check( '…and the rest of the row IS saved (the price that was typed)', 31.0 === (float) $two['unit_price'] );
check( 'the reply says so and asks for a reload', 2 === $saved['kept'] && true === $saved['reload'] && array() === $saved['warnings'] && 'Order saved. 2 lines were corrected after this page was loaded and keep that fish.' === $saved['message'], $saved['message'] );
check( 'a kept line stays stale on that page (it still shows the old fish): the reply hands its own token back, not the stored line\'s', array_column( $open, 'seen' ) === $saved['seen'] && array_column( $page( $store, $order_id ), 'seen' ) !== $saved['seen'] && count( $saved['seen'] ) === count( $saved['line_ids'] ) );
foreach ( $saved['seen'] as $i => $token ) {
	$open[ $i ]['seen'] = $token; // What the page's JS does with the reply.
}
$saved = $save( $store, $order_id, $open );
check( '…so Save pressed twice before the reload keeps the correction twice', 2 === $saved['kept'] && 0 === $saved['followed'] && 'Lined Test Wrasse' === fh_line( $store, $order_id, 0 )['species'] && 'Blue Test Tang' === fh_line( $store, $order_id, 1 )['species'] && $events === $store->event_count() );
$rows                  = $page( $store, $order_id );
$rows[1]['species']    = 'Powder Test Tang';
$rows[1]['species_id'] = '3';
$saved                 = $save( $store, $order_id, $rows );
check( 'a page that is up to date still corrects in the grid: the stay follows, nothing is "kept"', 1 === $saved['followed'] && 0 === $saved['kept'] && 'Powder Test Tang' === fh_line( $store, $order_id, 1 )['species'] && 'Powder Test Tang' === $store->stay( $b, $today, $settings )['species'] && 'identified: Blue Test Tang → Powder Test Tang' === $store->last_event()['note'] );
check( '…and its reply carries the tokens of the lines as saved, so the page that stays open is not stale after its own save', array_column( $page( $store, $order_id ), 'seen' ) === $saved['seen'] && $saved['seen'][1] !== $rows[1]['seen'] );
foreach ( $saved['seen'] as $i => $token ) {
	$rows[ $i ]['seen'] = $token;
}
$rows[1]['species']    = 'Blue Test Tang';
$rows[1]['species_id'] = '2';
$saved                 = $save( $store, $order_id, $rows );
check( '…a second change from that same page goes through', 1 === $saved['followed'] && 0 === $saved['kept'] && 'Blue Test Tang' === fh_line( $store, $order_id, 1 )['species'] && 'Blue Test Tang' === $store->stay( $b, $today, $settings )['species'] );
$open = $page( $store, $order_id );
$phone( $store, $b, array( 'species' => 'Green Test Blenny' ) );
$open[1]['species']    = 'Red Test Goby'; // Typed on the page that is now stale.
$open[1]['species_id'] = '';
$events                = $store->event_count();
$saved                 = $save( $store, $order_id, $open );
check( 'a fish typed on a stale row is not written over the correction — and the reply names what was not saved', 1 === $saved['kept'] && 0 === $saved['followed'] && 'Green Test Blenny' === fh_line( $store, $order_id, 1 )['species'] && 'Green Test Blenny' === $store->stay( $b, $today, $settings )['species'] && $events === $store->event_count() && 1 === count( $saved['warnings'] ) && false !== strpos( $saved['warnings'][0], 'the fish typed over Green Test Blenny' ) && false !== strpos( $saved['message'], 'Not saved: the fish typed over Green Test Blenny' ), $saved['message'] );
$open = $page( $store, $order_id );
$phone( $store, $b, array( 'species' => 'Red Test Goby' ) );
$open[1]['species'] = 'Red Test Goby'; // Typed to the very fish the line is now.
$saved              = $save( $store, $order_id, $open );
check( 'a stale row typed to the fish the line already is: nothing to keep, nothing to correct', 0 === $saved['kept'] && 0 === $saved['followed'] && 'Order saved.' === $saved['message'] && 'Red Test Goby' === fh_line( $store, $order_id, 1 )['species'] );
$draft = fh_identify_order( $store, array( array( 'species' => 'Red Test Goby', 'qty_ordered' => 5, 'qty_received' => 5, 'box_pct' => '10' ) ), $today, array( 'ref' => 'T-7' ) );
$open  = $page( $store, $draft );
Admin_Workbook::identify_line( $store, $store->order( $draft ), array( 'line_id' => fh_line( $store, $draft, 0 )['id'], 'species' => 'Lined Test Wrasse', 'product_id' => 21 ), $today, $settings, 7 );
$saved = $save( $store, $draft, $open );
check( 'a line that is not a stay yet, corrected in another tab: the stale tab keeps it too, label and share with it', 1 === $saved['kept'] && 'Lined Test Wrasse' === fh_line( $store, $draft, 0 )['species'] && 1 === fh_line( $store, $draft, 0 )['species_id'] && 'Red Test Goby' === fh_line( $store, $draft, 0 )['label_name'] && 's:1' === $store->box_share_by_key( (string) fh_line( $store, $draft, 0 )['share_key'] )['fish_key'] );
$kept = array();
check( 'keep_corrected(): a row without a token, a new row and a row of another order are taken as typed', array( array( 'id' => 5, 'species' => 'typed' ), array( 'id' => 0, 'species' => 'new' ), array( 'id' => 6, 'species' => 'typed' ) ) === Identify::keep_corrected( array( array( 'id' => 5, 'species' => 'typed' ), array( 'id' => 0, 'species' => 'new' ), array( 'id' => 6, 'species' => 'typed' ) ), array( 6 => Identify::seen( array( 'species' => 'old' ) ) ), array( 5 => array( 'species' => 'stored' ) ), $kept ) && array() === $kept );

$stay = $store->stay( $a, $today, $settings ); // The Stays tab is loaded now: its Edit row holds these values.
$form = array( 'seen' => Identify::seen( $stay ), 'species' => $stay['species'], 'size_slug' => (string) $stay['size_slug'], 'product_id' => (string) $stay['product_id'], 'supplier' => (string) $stay['supplier'], 'order_ref' => (string) $stay['order_ref'], 'landed_cost' => '12.50', 'checkin_date' => $stay['checkin_date'] );
$phone( $store, $a, array( 'species' => 'Powder Test Tang', 'product_id' => 22, 'size' => '3-4' ) );
$events = $store->event_count();
$r      = Admin::edit_stay_fields( $store, $store->stay( $a, $today, $settings ), $form, $base, $today, $settings );
$stay   = $store->stay( $a, $today, $settings );
check( 'the Stays tab\'s Edit on a row loaded before the correction, only the landed cost changed: the cost is saved, the fish, its size and its product stay as corrected', true === $r['ok'] && 12.5 === (float) $stay['landed_cost'] && 'Powder Test Tang' === $stay['species'] && '3-4' === $stay['size_slug'] && 22 === $stay['product_id'] && $events === $store->event_count() && 'Powder Test Tang' === fh_line( $store, $order_id, 0 )['species'] && 'Stay saved. Its fish was corrected after this page was loaded and stays Powder Test Tang.' === $r['message'] && true === $r['kept'], json_encode( $r ) );
$phone( $store, $a, array( 'species' => 'Blue Test Tang', 'size' => '2-3' ) );
$events = $store->event_count();
$r      = Admin::edit_stay_fields( $store, $store->stay( $a, $today, $settings ), array_merge( $form, array( 'species' => 'Red Test Goby', 'landed_cost' => '13.00' ) ), $base, $today, $settings );
check( '…and when the fish was also changed on that stale row, nothing is saved (409): reload first', false === $r['ok'] && 409 === $r['status'] && false !== strpos( $r['message'], 'it is now Blue Test Tang' ) && 'Blue Test Tang' === $store->stay( $a, $today, $settings )['species'] && 12.5 === (float) $store->stay( $a, $today, $settings )['landed_cost'] && $events === $store->event_count() );
$stay = $store->stay( $a, $today, $settings );
$r    = Admin::edit_stay_fields( $store, $stay, array( 'seen' => Identify::seen( $stay ), 'species' => 'Green Test Blenny', 'size_slug' => '2-3', 'product_id' => '', 'supplier' => (string) $stay['supplier'], 'order_ref' => (string) $stay['order_ref'], 'landed_cost' => '13.00' ), $base, $today, $settings );
check( 'a row that is up to date edits the fish as before', true === $r['ok'] && 'Green Test Blenny' === $store->stay( $a, $today, $settings )['species'] && 'Green Test Blenny' === fh_line( $store, $order_id, 0 )['species'] && 13.0 === (float) $store->stay( $a, $today, $settings )['landed_cost'] && $events + 1 === $store->event_count() && false === $r['kept'], json_encode( $r ) );

fh_finish();
