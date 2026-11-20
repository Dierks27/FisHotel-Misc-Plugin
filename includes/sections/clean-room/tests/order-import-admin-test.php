<?php
/**
 * Import lines on the in-memory store (Admin_Order_Import): BOTH MOMENTS
 * — ordered (received left empty, the order becomes "ordered", appends
 * only, the costing and the box shares exactly as a typed line, a line
 * with a stay untouched, duplicates, an alias learned on a corrected
 * match) and arrived (each of the four groups, a short line, an over
 * line, a did-not-come line kept at 0, an extra, a substitution paired
 * by hand, a typed price kept unless ticked, a line with a stay skipped
 * and said so, the foot, the fees only when ticked, the subtotal that
 * does and does not add up, the sections, the fill rate in the Stats
 * afterwards) —, the stale preview, the limits, the saved layouts
 * through a whole import, an XLSX and a CSV file, the form's lines as
 * one field, the nonce and the capabilities, and the silence of a
 * section that is switched off.
 *
 * Every supplier, fish, code and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-import-admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/xlsx-builder.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Order_Import as AOI;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Import_Layouts;
use FisHotel\Misc\Sections\Clean_Room\Order_Import as OI;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

update_option( Clean_Room::OPTION_KEY, array( 'size_list' => array( 'Small', 'Medium', 'Large' ) ) );
$today = '2026-03-02';
$ctx   = array( 'today' => $today, 'settings' => Settings::get(), 'actor_id' => 1 );

function fh_store() {
	$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Red Test Goby', 14 => 'Green Test Chromis', 15 => 'Spotted Sample Blenny', 16 => 'Striped Sample Blenny' ) );
	$store->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'sci_name' => 'Testus caeruleus', 'per_box' => 8, 'my_price' => 40, 'product_id' => 12 ) ) );
	$store->save_species( Species::normalize( array( 'name' => 'Pale Test Tang', 'sci_name' => 'Testus pallidus', 'per_box' => 4, 'my_price' => 90 ) ) );
	return $store;
}

/** The order form as the page posts it: the header and the lines as they stand. */
function fh_form( $store, $id, array $header = array() ) {
	$order = $id > 0 ? $store->order( $id ) : null;
	$form  = array_merge(
		array(
			'order_id'        => $id,
			'ref'             => $order ? $order['ref'] : '',
			'supplier'        => $order ? $order['supplier'] : 'Example Aquatics',
			'origin'          => $order ? $order['origin'] : 'Northland',
			'order_date'      => $order ? (string) $order['order_date'] : '2026-03-01',
			'received_date'   => $order ? (string) $order['received_date'] : '',
			'status'          => $order ? $order['status'] : 'draft',
			'cost_mode'       => 'box',
			'tariffs'         => $order ? (string) $order['tariffs'] : '0.00',
			'domestic_flight' => $order ? (string) $order['domestic_flight'] : '0.00',
			'import_fees'     => $order ? (string) $order['import_fees'] : '0.00',
			'credit'          => '0.00',
			'lines'           => array(),
		),
		$header
	);
	foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
		$row = array(
			'id'          => (string) $line['id'],
			'species_id'  => (string) $line['species_id'],
			'seen'        => Identify::seen( $line ),
			'species'     => $line['species'],
			'size_slug'   => (string) $line['size_slug'],
			'sci_name'    => $line['sci_name'],
			'qty_ordered' => (string) $line['qty_ordered'],
			'per_box'     => (string) $line['per_box'],
			'box_no'      => (string) $line['box_no'],
			'box_pct'     => (string) $line['box_pct'],
			'unit_price'  => null !== $line['unit_price'] ? (string) $line['unit_price'] : '',
			'worth'       => null !== $line['worth'] ? (string) $line['worth'] : '',
		);
		if ( ! empty( $line['needs_id'] ) ) {
			$row['needs_id'] = '1';
		}
		if ( empty( $line['stay_id'] ) ) { // A checked-in line shows these read-only.
			$row['qty_received'] = null !== $line['qty_received'] ? (string) $line['qty_received'] : '';
			$row['qty_doa']      = (string) $line['qty_doa'];
			$row['qty_survived'] = null !== $line['qty_survived'] ? (string) $line['qty_survived'] : '';
			$row['tank_id']      = (string) $line['tank_id'];
		}
		$form['lines'][] = $row;
	}
	return $form;
}

function fh_ask( $store, array $form, $op, array $import ) {
	global $ctx;
	return AOI::request( $store, array_merge( $form, array( 'op' => $op, 'import' => wp_json_encode( $import ) ) ), $ctx );
}

function fh_row( array $preview, $typed ) {
	foreach ( $preview['rows'] as $row ) {
		if ( $row['typed'] === $typed ) {
			return $row;
		}
	}
	return null;
}

function fh_line( $store, $id, $species ) {
	foreach ( $store->order( $id )['lines'] as $line ) {
		if ( $line['species'] === $species ) {
			return $line;
		}
	}
	return null;
}

function fh_group( array $preview, $key ) {
	foreach ( $preview['arrived']['groups'] as $group ) {
		if ( $group['key'] === $key ) {
			return $group['entries'];
		}
	}
	return array();
}

$sheet = implode(
	"\n",
	array(
		'Example Aquatics Export',
		'PRICE LIST AND ORDER FORM',
		"CODE\tSTOCK\tORDER\tENGLISH NAME\tLATIN NAME\tQTY/BOX\tPRICE US\$",
		"XA1\t2m\t4\tBlue Test Wrasse (L) (10-14 CM)\tTestus caeruleus\t12\t2.50",
		"XA2\t5\t6\tRed Test Gobi\t\t24\t1.2916666",
		"XA3\t\t2\tZzyzx Whatsit\t\t\t\$9.00 ea",
		"XA4\t12\t\tGreen Test Chromis\t\t30\t0.75",
		"XA5\t1sm\t3\tSample Blenny\t\t10\t3",
		"XA6\t\t0\tPale Test Tang\tTestus pallidus\t4\t30",
	)
);

echo "1. \"This is what I ORDERED\": the preview\n";
$store = fh_store();
$p     = fh_ask( $store, fh_form( $store, 0 ), 'preview', array( 'text' => $sheet ) );
check( 'a new draft order: the ordered moment is the default', 'ordered' === $p['mode'] && 'ordered' === $p['default'] );
check( 'the header row is found under the title rows; the columns by their headings — no dropdowns needed', 2 === $p['columns']['row'] && 'headers' === $p['columns']['source'] && true === $p['columns']['complete'] && false === $p['columns']['open'] && 'stock' === ( (array) $p['columns']['map'] )[1] && 'ordered' === ( (array) $p['columns']['map'] )[2] );
check_same( 'only the rows with an order quantity above 0 are lines — and it says so', array( '4 of 6 rows have an order quantity', 4 ), array( $p['summary'], count( $p['rows'] ) ) );
$wrasse = fh_row( $p, 'Blue Test Wrasse (L) (10-14 CM)' );
check( 'a row: the fish as typed, "matched: …", the size lifted into the Size, the code, the per-box count, the price — received EMPTY', 'Blue Test Wrasse' === $wrasse['name'] && 'exact' === $wrasse['match']['kind'] && false === $wrasse['match']['flag'] && 'large' === $wrasse['size']['slug'] && 'XA1' === $wrasse['code'] && 12 === $wrasse['per_box'] && 2.5 === $wrasse['price'] && 4 === $wrasse['ordered'] && null === $wrasse['received'] && true === $wrasse['on'] );
$goby = fh_row( $p, 'Red Test Gobi' );
check( 'a typo within two letters is matched, and shown as such', 'close' === $goby['match']['kind'] && 'Red Test Goby' === $goby['name'] && 13 === $goby['product_id'] && abs( $goby['price'] - 1.2916666 ) < 1e-9 );
$odd = fh_row( $p, 'Zzyzx Whatsit' );
check( 'a fish the lists do not know is used as typed (an unlisted fish); "$9.00 ea" is 9', 'none' === $odd['match']['kind'] && 'typed' === $odd['kind'] && 'Zzyzx Whatsit' === $odd['name'] && 9.0 === $odd['price'] );
$blenny = fh_row( $p, 'Sample Blenny' );
check( 'an ambiguous match is flagged and NOT chosen: the line is the text as typed until the owner picks; the candidates are offered', 'ambiguous' === $blenny['match']['kind'] && true === $blenny['match']['flag'] && 'typed' === $blenny['kind'] && 'Sample Blenny' === $blenny['name'] && array( 'Spotted Sample Blenny', 'Striped Sample Blenny' ) === array_column( $blenny['match']['candidates'], 'name' ) );
check( 'the foot: total lines and total fish', '4 lines · 15 fish' === $p['foot'] && true === $p['can_confirm'] );
check( 'the reply carries the cleaned grid for the page to post back, and a token', is_array( $p['grid'] ) && 9 === count( $p['grid'] ) && 32 === strlen( $p['token'] ) );
check( 'nothing was saved by a preview', array() === $store->orders() && array() === Import_Layouts::all() );

echo "2. … and the confirm\n";
$grid = $p['grid'];
$done = fh_ask( $store, fh_form( $store, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id   = (int) $done['order_id'];
$o    = $store->order( $id );
check( 'the order is saved — as ORDERED, no longer a draft — with its four lines, and the page is sent to it', empty( $done['error'] ) && $id > 0 && 4 === $done['imported'] && 'ordered' === $o['status'] && 4 === count( $o['lines'] ) && false !== strpos( $done['redirect'], 'order=' . $id ) && '4 lines imported.' === $done['message'] );
check_same( 'every line has its ordered count and received left EMPTY (not 0)', array( array( 4, 6, 2, 3 ), array( null, null, null, null ) ), array( array_column( $o['lines'], 'qty_ordered' ), array_column( $o['lines'], 'qty_received' ) ) );
$w = $o['lines'][0];
check( 'the matched line: the species-list fish, its scientific name, the standard size, QTY/BOX as its per box, the price, the list\'s worth', 'Blue Test Wrasse' === $w['species'] && 1 === $w['species_id'] && 'Testus caeruleus' === $w['sci_name'] && 'large' === $w['size_slug'] && 'Large' === $w['size_label'] && 12 === $w['per_box'] && 2.5 === $w['unit_price'] && 40.0 === $w['worth'] && false === $w['needs_id'] );
check( 'the supplier\'s CODE is kept on the line, with the name as the sheet writes it', '[as written: Blue Test Wrasse (L) (10-14 CM)] [code XA1]' === $w['notes'] && 'XA1' === OI::code_of( $w['notes'] ) && 'XA2' === OI::code_of( $o['lines'][1]['notes'] ) && 'Red Test Gobi' === OI::written_of( $o['lines'][1]['notes'] ) );
check( 'a long-float price is stored to four decimals; an unlisted fish keeps its typed name and only its code', 1.2917 === $o['lines'][1]['unit_price'] && 'Zzyzx Whatsit' === $o['lines'][2]['species'] && '[code XA3]' === $o['lines'][2]['notes'] && null === $o['lines'][2]['species_id'] );
check( 'no label is invented for a name that was only spelled differently, and nothing was checked in', array( null, null, null, null ) === array_column( $o['lines'], 'label_name' ) && array() === $store->stays && array( null, null, null, null ) === array_column( $o['lines'], 'stay_id' ) );

echo "3. An imported line takes the path a typed line takes\n";
$typed_store = fh_store();
$typed       = fh_form( $typed_store, 0, array( 'status' => 'ordered' ) );
foreach ( $o['lines'] as $line ) {
	$typed['lines'][] = array( 'species' => $line['species'], 'species_id' => (string) $line['species_id'], 'sci_name' => $line['sci_name'], 'size_slug' => (string) $line['size_slug'], 'qty_ordered' => (string) $line['qty_ordered'], 'qty_received' => '', 'per_box' => (string) $line['per_box'], 'unit_price' => (string) $line['unit_price'], 'worth' => null !== $line['worth'] ? (string) $line['worth'] : '' );
}
$typed_done = Admin_Workbook::save_order( $typed_store, $typed, 0, $today );
check( 'the same lines typed by hand on another store give the same figures, line for line — the costing is one code path', $typed_done['figures'] === $done['figures'] && 4 === count( $done['figures']['lines'] ) );
$received = fh_form( $store, $id, array( 'received_date' => '2026-03-08' ) );
foreach ( $received['lines'] as $i => $row ) {
	$received['lines'][ $i ]['qty_received'] = $row['qty_ordered'];
	$received['lines'][ $i ]['box_pct']      = '25';
}
$typed_received = fh_form( $typed_store, (int) $typed_done['order_id'], array( 'received_date' => '2026-03-08' ) );
foreach ( $typed_received['lines'] as $i => $row ) {
	$typed_received['lines'][ $i ]['qty_received'] = $row['qty_ordered'];
	$typed_received['lines'][ $i ]['box_pct']      = '25';
}
$a = Admin_Workbook::save_order( $store, $received, $id, $today );
$b = Admin_Workbook::save_order( $typed_store, $typed_received, (int) $typed_done['order_id'], $today );
check( '… and once they are received with a percent of a box, the same remembered box shares', $a['figures'] === $b['figures'] && 4 === count( $store->box_shares ) && array_column( $store->box_shares, 'pct_per_fish', 'share_key' ) === array_column( $typed_store->box_shares, 'pct_per_fish', 'share_key' ) && array() !== $a['shares'] );

echo "4. On a saved order: appends only, duplicates, a line with a stay\n";
$check_in = Orders::check_in( $store, $store->order( $id ), $today, $ctx['settings'], array( 'actor_id' => 1 ) );
$store->update_order_fields( $id, array( 'status' => 'ordered' ) );
check( 'the four received lines are checked in for this part (four stays)', 4 === count( $check_in['created'] ) && 4 === count( $store->stays ) );
$before = $store->order( $id )['lines'];
$more   = "fish\tqty\tprice\nBlue Test Wrasse (L)\t9\t99\nRed Test Goby\t9\t99\nGreen Test Chromis\t5\t0.75\n=HYPERLINK(\"http://example.test\")\t1\t1\nPale Test Tang\t=2+2\t30\nSpotted Sample Blenny\t2\t6,50\n";
$p2     = fh_ask( $store, fh_form( $store, $id ), 'preview', array( 'text' => $more, 'mode' => 'ordered' ) );
$dup    = fh_row( $p2, 'Blue Test Wrasse (L)' );
check( 'duplicates of a line already on the order (same species + size) are flagged, with a choice per row — default: skip', 'Blue Test Wrasse · Large' === $dup['dup'] && 'skip' === $dup['dup_choice'] && 'Red Test Goby' === fh_row( $p2, 'Red Test Goby' )['dup'] && '' === fh_row( $p2, 'Green Test Chromis' )['dup'] );
$tang = fh_row( $p2, 'Pale Test Tang' );
check( 'a formula-looking quantity is flagged on its cell and the row is unticked; a decimal-comma price is flagged and left empty', in_array( 'qty', $tang['flags'], true ) && false === $tang['on'] && in_array( 'price', fh_row( $p2, 'Spotted Sample Blenny' )['flags'], true ) && null === fh_row( $p2, 'Spotted Sample Blenny' )['price'] );
check( 'the foot counts what would be added: the chromis, the formula-named row and the blenny', '3 lines · 8 fish' === $p2['foot'] );
$d2 = fh_ask( $store, fh_form( $store, $id ), 'confirm', array( 'grid' => $p2['grid'], 'mode' => 'ordered', 'token' => $p2['token'], 'rows' => array( 2 => array( 'dup' => 'add' ) ) ) );
$o2 = $store->order( $id );
check( 'confirm appends: four new lines (the goby "add anyway" too), seven… eight lines in all', empty( $d2['error'] ) && 4 === $d2['imported'] && 8 === count( $o2['lines'] ) && array( 'Red Test Goby', 'Green Test Chromis', '=HYPERLINK("http://example.test")', 'Spotted Sample Blenny' ) === array_column( array_slice( $o2['lines'], 4 ), 'species' ), json_encode( array_column( $o2['lines'], 'species' ) ) );
check( 'the lines that were there are exactly as they were — a line with a stay is never touched', array_slice( $o2['lines'], 0, 4 ) === $before && 4 === count( $store->stays ) );
check( 'a formula-looking name is text on the line, like any other name', '=HYPERLINK("http://example.test")' === $o2['lines'][6]['species'] && 1 === $o2['lines'][6]['qty_ordered'] );
check( 'the unparseable price was left empty rather than guessed', null === $o2['lines'][7]['unit_price'] && 2 === $o2['lines'][7]['qty_ordered'] );
check( 'an order that already has received fish stays as it is: only a draft becomes "ordered"', 'ordered' === $o2['status'] );

echo "5. A corrected match teaches a name\n";
$store3 = fh_store();
$p3     = fh_ask( $store3, fh_form( $store3, 0 ), 'preview', array( 'text' => "fish\tordered\nSample Blenny\t3\nBlue Thing\t2\nRed Test Goby\t1\n", 'mode' => 'ordered' ) );
check( 'a weak match is flagged and left for the owner', 'weak' === fh_row( $p3, 'Blue Thing' )['match']['kind'] && true === fh_row( $p3, 'Blue Thing' )['match']['flag'] && 'typed' === fh_row( $p3, 'Blue Thing' )['kind'] );
$picks = array(
	1 => array( 'pick' => array( 'kind' => 'fish', 'name' => 'Spotted Sample Blenny', 'product_id' => 15 ) ),
	2 => array( 'pick' => array( 'kind' => 'unknown' ) ),
);
$p3b = fh_ask( $store3, fh_form( $store3, 0 ), 'preview', array( 'grid' => $p3['grid'], 'mode' => 'ordered', 'rows' => $picks ) );
check( 'the preview follows the owner\'s picks: the fish, and "Unknown fish (??)" — no longer flagged', 'Spotted Sample Blenny' === fh_row( $p3b, 'Sample Blenny' )['name'] && false === fh_row( $p3b, 'Sample Blenny' )['match']['flag'] && 'unknown' === fh_row( $p3b, 'Blue Thing' )['kind'] && '?? Blue Thing' === fh_row( $p3b, 'Blue Thing' )['name'] && null === $p3b['grid'] );
$d3 = fh_ask( $store3, fh_form( $store3, 0 ), 'confirm', array( 'grid' => $p3['grid'], 'mode' => 'ordered', 'token' => $p3b['token'], 'rows' => $picks ) );
$o3 = $store3->order( (int) $d3['order_id'] );
check( 'the corrected line is the fish the owner picked, with the name as written in its notes', 'Spotted Sample Blenny' === $o3['lines'][0]['species'] && '[as written: Sample Blenny]' === $o3['lines'][0]['notes'] && null === $o3['lines'][0]['label_name'] );
check( 'the unknown fish is a "??" line that needs identifying', '?? Blue Thing' === $o3['lines'][1]['species'] && true === $o3['lines'][1]['needs_id'] && null === $o3['lines'][1]['species_id'] );
check( 'the name the supplier uses is LEARNED (an alias, as the phone learns one) — once, for the corrected row only', 1 === $d3['learned'] && array( 'sample blenny' ) === array_column( $store3->aliases(), 'alias' ) && 15 === $store3->aliases()[0]['product_id'] && false !== strpos( $d3['message'], '1 name learned' ) );
$p3c = fh_ask( $store3, fh_form( $store3, 0, array( 'ref' => '2026-77' ) ), 'preview', array( 'text' => "fish\tordered\nSample Blenny M\t3\n", 'mode' => 'ordered' ) );
check( '… so next time it matches by itself', 'alias' === $p3c['rows'][0]['match']['kind'] && 'Spotted Sample Blenny' === $p3c['rows'][0]['name'] && 'medium' === $p3c['rows'][0]['size']['slug'] );

echo "6. \"These fish have arrived — use the quantity as received too\"\n";
$store4 = fh_store();
$p4     = fh_ask( $store4, fh_form( $store4, 0 ), 'preview', array( 'text' => "fish\tqty\nBlue Test Wrasse\t4\n", 'mode' => 'ordered', 'arrived_too' => 1 ) );
$d4     = fh_ask( $store4, fh_form( $store4, 0 ), 'confirm', array( 'grid' => $p4['grid'], 'mode' => 'ordered', 'arrived_too' => 1, 'token' => $p4['token'] ) );
$o4     = $store4->order( (int) $d4['order_id'] );
check( 'a row with only a quantity: ordered and received both, and the order is not called "ordered" for it', 4 === $p4['rows'][0]['received'] && true === $p4['too'] && 4 === $o4['lines'][0]['qty_ordered'] && 4 === $o4['lines'][0]['qty_received'] && 'draft' === $o4['status'] );
check( 'on a received order the ARRIVED moment is the default', 'arrived' === fh_ask( $store4, fh_form( $store4, (int) $d4['order_id'], array( 'status' => 'received' ) ), 'preview', array( 'text' => "fish\tqty\nBlue Test Wrasse\t4\n" ) )['mode'] );

echo "7. A stale preview is refused and drawn again\n";
$store5 = fh_store();
$d5     = fh_ask( $store5, fh_form( $store5, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id5    = (int) $d5['order_id'];
$text5  = "fish\tqty\nGreen Test Chromis\t5\n";
$p5     = fh_ask( $store5, fh_form( $store5, $id5 ), 'preview', array( 'text' => $text5, 'mode' => 'ordered' ) );
$page   = fh_form( $store5, $id5 ); // The page as it was loaded.
$store5->update_line_fields( $store5->order( $id5 )['lines'][2]['id'], array( 'species' => 'Corrected Elsewhere' ) );
$stale = fh_ask( $store5, $page, 'confirm', array( 'grid' => $p5['grid'], 'mode' => 'ordered', 'token' => $p5['token'] ) );
check( 'the order was changed elsewhere after the preview: refused (409), nothing imported, the preview drawn again against the order as it is', 409 === $stale['status'] && false !== strpos( $stale['error'], 'changed since this preview' ) && 4 === count( $store5->order( $id5 )['lines'] ) && is_array( $stale['preview'] ) && $stale['preview']['token'] !== $p5['token'] && 1 === count( $stale['preview']['rows'] ) );
$typed_over                            = fh_form( $store5, $id5 );
$typed_over['lines'][0]['qty_ordered'] = '40';
$stale2                                = fh_ask( $store5, $typed_over, 'confirm', array( 'grid' => $p5['grid'], 'mode' => 'ordered', 'token' => $stale['preview']['token'] ) );
check( '… and so is a preview made before the owner typed on the page', 409 === $stale2['status'] && 4 === count( $store5->order( $id5 )['lines'] ) );
check( 'a confirm without a token is refused too', 409 === fh_ask( $store5, fh_form( $store5, $id5 ), 'confirm', array( 'grid' => $p5['grid'], 'mode' => 'ordered' ) )['status'] );
$fresh = fh_ask( $store5, $typed_over, 'preview', array( 'grid' => $p5['grid'], 'mode' => 'ordered' ) );
$ok    = fh_ask( $store5, $typed_over, 'confirm', array( 'grid' => $p5['grid'], 'mode' => 'ordered', 'token' => $fresh['token'] ) );
check( 'against the page as it stands it goes through — and what the owner typed on the page is saved with it, as Save would', empty( $ok['error'] ) && 5 === count( $store5->order( $id5 )['lines'] ) && 40 === $store5->order( $id5 )['lines'][0]['qty_ordered'] );
check( 'an order that no longer exists: 404', 404 === fh_ask( $store5, fh_form( $store5, 0, array( 'order_id' => 9999 ) ), 'preview', array( 'text' => $text5 ) )['status'] );

echo "8. Limits\n";
$store6 = fh_store();
$big    = fh_form( $store6, 0 );
for ( $i = 0; $i < Orders::MAX_LINES - 2; $i++ ) {
	$big['lines'][] = array( 'species' => 'Typed Test Fish ' . $i, 'qty_ordered' => '1' );
}
$p6 = fh_ask( $store6, $big, 'preview', array( 'text' => "fish\tqty\nNew Test Fish A\t1\nNew Test Fish B\t1\nNew Test Fish C\t1\n", 'mode' => 'ordered' ) );
check( 'an order holds at most ' . Orders::MAX_LINES . ' lines: an import that does not fit cannot be confirmed, and says why', false === $p6['can_confirm'] && 1 === count( preg_grep( '/do not fit/', $p6['notes'] ) ) );
$d6 = fh_ask( $store6, $big, 'confirm', array( 'grid' => $p6['grid'], 'mode' => 'ordered', 'token' => $p6['token'] ) );
check( '… and a confirm is refused whole: nothing imported, nothing cut short', ! empty( $d6['error'] ) && false !== strpos( $d6['error'], 'at most ' . Orders::MAX_LINES ) && array() === $store6->orders() );
$d6b = fh_ask( $store6, $big, 'confirm', array( 'grid' => $p6['grid'], 'mode' => 'ordered', 'token' => $p6['token'], 'rows' => array( 3 => array( 'on' => 0 ) ) ) );
check( 'with one row unticked it fits: ' . Orders::MAX_LINES . ' lines', empty( $d6b['error'] ) && Orders::MAX_LINES === count( $store6->order( (int) $d6b['order_id'] )['lines'] ) );
$long = array();
for ( $i = 0; $i < Orders::MAX_LINES + 30; $i++ ) {
	$long[] = "Many Test Fish $i\t1";
}
$p6c = fh_ask( fh_store(), fh_form( fh_store(), 0 ), 'preview', array( 'text' => "fish\tqty\n" . implode( "\n", $long ), 'mode' => 'ordered' ) );
check( 'a sheet with more ordered rows than an order holds: the first ' . Orders::MAX_LINES . ' are shown, the rest counted', Orders::MAX_LINES === count( $p6c['rows'] ) && 1 === count( preg_grep( '/30 more rows are not shown/', $p6c['notes'] ) ) );
check( 'more than 2 MB of pasted text is refused', false !== strpos( fh_ask( fh_store(), fh_form( fh_store(), 0 ), 'preview', array( 'text' => str_repeat( "Test Fish\t1\n", 200000 ) ) )['error'], '2 MB' ) );
check( 'nothing pasted, nothing picked: it says so', false !== strpos( fh_ask( fh_store(), fh_form( fh_store(), 0 ), 'preview', array( 'text' => "  \n" ) )['error'], 'Nothing to read' ) );
$p6d = fh_ask( fh_store(), fh_form( fh_store(), 0 ), 'preview', array( 'grid' => array( array( 'fish', 'qty', array( 'x' ) ), array( str_repeat( 'F', 900 ), '2', "a\x00b" ), 'junk' ), 'mode' => 'ordered' ) );
check( 'a posted grid is cleaned again on the way in: cells cut to ' . OI::MAX_CELL . ' characters, junk dropped', 1 === count( $p6d['rows'] ) && OI::MAX_CELL === strlen( $p6d['rows'][0]['typed'] ) );
$d6d = fh_ask( $store6, fh_form( $store6, 0, array( 'ref' => '2026-90' ) ), 'confirm', array( 'grid' => array( array( 'fish', 'qty' ), array( str_repeat( 'F', 900 ), '2' ) ), 'mode' => 'ordered', 'token' => fh_ask( $store6, fh_form( $store6, 0, array( 'ref' => '2026-90' ) ), 'preview', array( 'grid' => array( array( 'fish', 'qty' ) ), 'mode' => 'ordered' ) )['token'] ) );
check( '… and a name is stored at the length a typed name is (120)', 120 === strlen( $store6->order( (int) $d6d['order_id'] )['lines'][0]['species'] ) );
check( 'nothing ticked: nothing to import', false !== strpos( fh_ask( $store6, fh_form( $store6, 0, array( 'ref' => '2026-91' ) ), 'confirm', array( 'grid' => array( array( 'fish', 'qty' ), array( 'Test Fish', '2' ) ), 'mode' => 'ordered', 'token' => $p6['token'] === '' ? '' : fh_ask( $store6, fh_form( $store6, 0, array( 'ref' => '2026-91' ) ), 'preview', array( 'grid' => array( array( 'fish', 'qty' ), array( 'Test Fish', '2' ) ), 'mode' => 'ordered' ) )['token'], 'rows' => array( 1 => array( 'on' => 0 ) ) ) )['error'], 'Nothing is ticked' ) );

echo "9. No header row: the dropdowns, and nothing saved before a fish and a quantity are chosen\n";
$store7 = fh_store();
$bare   = "XA1\tBlue Test Wrasse\tfresh\t4\nXA2\tRed Test Goby\tfresh\t6\nNOTE\tSPECIAL OFFERS\t\t\nXA3\tGreen Test Chromis\tfresh\t10\n";
$form7  = fh_form( $store7, 0, array( 'supplier' => 'Sample Reef Supply', 'origin' => '' ) );
$p7     = fh_ask( $store7, $form7, 'preview', array( 'text' => $bare, 'mode' => 'ordered' ) );
check( 'no header: the columns are a guess — the wordy column the fish, the first whole-number column the quantity — shown above the first rows', 'guess' === $p7['columns']['source'] && null === $p7['columns']['row'] && true === $p7['columns']['open'] && array( 1 => 'fish', 3 => 'qty' ) === (array) $p7['columns']['map'] && 4 === $p7['columns']['width'] && 4 === count( $p7['columns']['sample'] ) && 3 === count( $p7['rows'] ) );
check( '… and a row with a text and no number is left out as a heading, counted and named', 1 === $p7['left_out']['count'] && '1 row left out' === $p7['left_out']['text'] && 'SPECIAL OFFERS — a heading' === $p7['left_out']['items'][0] );
check( 'a guess the owner accepts is worth remembering: the box is offered, for the order\'s supplier', 'Remember this layout for Sample Reef Supply' === $p7['layout']['remember'] );
$none = fh_ask( $store7, $form7, 'preview', array( 'grid' => $p7['grid'], 'mode' => 'ordered', 'hand' => 1, 'map' => array( 1 => 'fish', 0 => 'code' ), 'header_row' => '' ) );
check( 'without a quantity column: no rows, no confirm, and it says what is missing', false === $none['columns']['complete'] && array() === $none['rows'] && false === $none['can_confirm'] && true === $none['columns']['open'] && 1 === count( preg_grep( '/which column is the fish and which one is the quantity/', $none['notes'] ) ) && '' === $none['layout']['remember'] );
check( '… and a confirm is refused', false !== strpos( fh_ask( $store7, $form7, 'confirm', array( 'grid' => $p7['grid'], 'mode' => 'ordered', 'hand' => 1, 'map' => array( 1 => 'fish' ), 'token' => $none['token'] ) )['error'], 'which column is the fish' ) && array() === $store7->orders() );

echo "10. Saved layouts, through whole imports\n";
$hand = array( 'grid' => $p7['grid'], 'mode' => 'ordered', 'hand' => 1, 'map' => array( 0 => 'code', 1 => 'fish', 3 => 'ordered' ), 'header_row' => '', 'rows' => array( 2 => array( 'on' => 0, 'junk' => 1 ) ) );
$p7b  = fh_ask( $store7, $form7, 'preview', $hand );
check( 'the columns set by hand: the code is read too, and the quantity is the ordered count', 'hand' === $p7b['columns']['source'] && 3 === count( $p7b['rows'] ) && 'XA1' === $p7b['rows'][0]['code'] && 4 === $p7b['rows'][0]['ordered'] && '3 of 4 rows have an order quantity' === $p7b['summary'] && 'Columns' !== $p7b['layout']['line'] );
$d7 = fh_ask( $store7, $form7, 'confirm', array_merge( $hand, array( 'token' => $p7b['token'], 'remember' => 1, 'rows' => array( 1 => array( 'on' => 0, 'junk' => 1 ) ) ) ) );
$l7 = Import_Layouts::all();
check( 'confirmed with "Remember this layout" ticked: ONE layout saved — for the supplier, the ordered moment, by position (no header), four columns', empty( $d7['error'] ) && 1 === count( $l7 ) && 'Sample Reef Supply' === $l7[0]['supplier'] && 'ordered' === $l7[0]['moment'] && false === $l7[0]['header'] && array( 'code', 'fish', '', 'ordered' ) === array_column( $l7[0]['columns'], 'role' ) && $today === $l7[0]['used'] && false !== strpos( $d7['message'], 'remembered for Sample Reef Supply' ) );
check( '… with the row the owner said to leave out every time', array( 'red test goby' ) === $l7[0]['junk'] && 2 === $d7['imported'] );
$p7c = fh_ask( $store7, fh_form( $store7, 0, array( 'supplier' => 'sample reef supply', 'origin' => '', 'ref' => '2026-50' ) ), 'preview', array( 'text' => "XB1\tPale Test Tang\tfresh\t2\nXB2\tRed Test Goby\tfresh\t9\n", 'mode' => 'ordered' ) );
check( 'the next paste from that supplier is recognised by itself — without a header — and says so in one plain line', 'layout' === $p7c['columns']['source'] && 'Using the layout saved for Sample Reef Supply' === $p7c['layout']['line'] && false === $p7c['columns']['open'] && 'XB1' === $p7c['rows'][0]['code'] && 2 === $p7c['rows'][0]['ordered'] && '' === $p7c['layout']['remember'] );
check( '… and the learned row is left out, "as last time"', 1 === count( $p7c['rows'] ) && false !== strpos( $p7c['left_out']['items'][0], 'left out as last time' ) );
check( 'an unticked "Remember" box saves nothing', 1 === count( Import_Layouts::all() ) && empty( fh_ask( $store7, fh_form( $store7, 0, array( 'supplier' => 'Nobody Remembered', 'ref' => '2026-51' ) ), 'confirm', array_merge( $hand, array( 'rows' => array(), 'token' => fh_ask( $store7, fh_form( $store7, 0, array( 'supplier' => 'Nobody Remembered', 'ref' => '2026-51' ) ), 'preview', $hand )['token'] ) ) )['error'] ) && 1 === count( Import_Layouts::all() ) );

// A headed sheet, mapped by hand because nobody recognises its headings.
$store8 = fh_store();
$kode   = "Example Aquatics Export\nKODE\tNAMA\tPESAN\tHARGA\nXA1\tBlue Test Wrasse\t4\t2.50\nXA2\tRed Test Goby\t6\t1.25\n";
$form8  = fh_form( $store8, 0 );
$p8     = fh_ask( $store8, $form8, 'preview', array( 'text' => $kode, 'mode' => 'ordered' ) );
check( 'a header nobody recognises: the dropdowns, pre-guessed', 'guess' === $p8['columns']['source'] && true === $p8['columns']['open'] );
$hand8 = array( 'grid' => $p8['grid'], 'mode' => 'ordered', 'hand' => 1, 'map' => array( 0 => 'code', 1 => 'fish', 2 => 'ordered', 3 => 'price' ), 'header_row' => 1 );
$p8b   = fh_ask( $store8, $form8, 'preview', $hand8 );
$d8    = fh_ask( $store8, $form8, 'confirm', array_merge( $hand8, array( 'token' => $p8b['token'], 'remember' => 1 ) ) );
$own   = Import_Layouts::find( Import_Layouts::all(), Import_Layouts::id( 'Example Aquatics', 'Northland', 'ordered' ) );
check( 'saved with its headings\' texts, the row the header stands on (the second) and the origin of the order', empty( $d8['error'] ) && $own && true === $own['header'] && 2 === $own['header_row'] && 'Northland' === $own['origin'] && array( 'kode', 'nama', 'pesan', 'harga' ) === array_column( $own['columns'], 'text' ) );
$kode2 = "Example Aquatics Export — week 12\nValid until Friday\nKODE\tNAMA\tPESAN\tHARGA\nXA9\tGreen Test Chromis\t12\t0.75\n";
$p8c   = fh_ask( $store8, fh_form( $store8, 0, array( 'ref' => '2026-60' ) ), 'preview', array( 'text' => $kode2, 'mode' => 'ordered' ) );
check( 'next week\'s sheet — one title row more — is recognised by its headings', 'layout' === $p8c['columns']['source'] && 2 === $p8c['columns']['row'] && 'Using the layout saved for Example Aquatics (Northland)' === $p8c['layout']['line'] && 12 === $p8c['rows'][0]['ordered'] && 0.75 === $p8c['rows'][0]['price'] && '' === $p8c['layout']['partial'] );
$other = fh_form( $store8, 0, array( 'supplier' => 'Demo Fish Trading', 'origin' => '', 'ref' => '2026-61' ) );
$p8d   = fh_ask( $store8, $other, 'preview', array( 'text' => $kode2, 'mode' => 'ordered' ) );
check( 'the same sheet on ANOTHER supplier\'s order: the layout is NOT applied — "This looks like Example Aquatics\'s sheet. Use that layout?"', 'layout' !== $p8d['columns']['source'] && '' === $p8d['layout']['applied'] && $own['id'] === $p8d['layout']['offer']['id'] && 'This looks like Example Aquatics\'s sheet. Use that layout?' === $p8d['layout']['offer']['text'] && '' === $p8d['layout']['offer']['set'] );
check( '… on a new order with no supplier chosen yet it offers to set the supplier too', 'and set this order\'s supplier to Example Aquatics' === fh_ask( $store8, fh_form( $store8, 0, array( 'supplier' => '', 'origin' => '', 'ref' => '2026-62' ) ), 'preview', array( 'text' => $kode2, 'mode' => 'ordered' ) )['layout']['offer']['set'] );
$p8e = fh_ask( $store8, $other, 'preview', array( 'grid' => $p8d['grid'], 'mode' => 'ordered', 'use_layout' => $own['id'] ) );
check( 'once the owner says yes it is applied — and offered to be remembered for THIS supplier', 'layout' === $p8e['columns']['source'] && 12 === $p8e['rows'][0]['ordered'] && 'Remember this layout for Demo Fish Trading' === $p8e['layout']['remember'] );
$d8e = fh_ask( $store8, $other, 'confirm', array( 'grid' => $p8d['grid'], 'mode' => 'ordered', 'use_layout' => $own['id'], 'token' => $p8e['token'], 'remember' => 1 ) );
check( 'two suppliers whose sheets have the same headings each keep their own layout', empty( $d8e['error'] ) && 3 === count( Import_Layouts::all() ) && null !== Import_Layouts::find( Import_Layouts::all(), Import_Layouts::id( 'Demo Fish Trading', '', 'ordered' ) ) && null !== Import_Layouts::find( Import_Layouts::all(), $own['id'] ) );
$lost = "KODE\tNAMA\tPESAN\tSize\nXA9\tGreen Test Chromis\t12\tM\n";
$p8f  = fh_ask( $store8, fh_form( $store8, 0, array( 'ref' => '2026-63' ) ), 'preview', array( 'text' => $lost, 'mode' => 'ordered' ) );
check( 'a layout that fits only partly (the price column gone, a size column new): applied to what still fits, says which did not, and asks whether to update it', 'layout' === $p8f['columns']['source'] && false !== strpos( $p8f['layout']['partial'], 'no longer on the sheet: harga' ) && false !== strpos( $p8f['layout']['partial'], 'new on the sheet: size' ) && 'Update the layout saved for Example Aquatics' === $p8f['layout']['remember'] && 'medium' === $p8f['rows'][0]['size']['slug'] && null === $p8f['rows'][0]['price'] );
$d8f = fh_ask( $store8, fh_form( $store8, 0, array( 'ref' => '2026-63' ) ), 'confirm', array( 'grid' => $p8f['grid'], 'mode' => 'ordered', 'token' => $p8f['token'], 'remember' => 1 ) );
check( '… ticked: the saved layout is the sheet as it is now', empty( $d8f['error'] ) && array( 'kode', 'nama', 'pesan', 'size' ) === array_column( Import_Layouts::find( Import_Layouts::all(), $own['id'] )['columns'], 'text' ) && 3 === count( Import_Layouts::all() ) );

echo "11. \"This is what ARRIVED\": the reconciliation\n";
Import_Layouts::store( array() );
$s  = fh_store();
$d  = fh_ask( $s, fh_form( $s, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id = (int) $d['order_id'];
// Two lines typed by hand on the page, one of them with a price; then one line is checked in.
$form                    = fh_form( $s, $id );
$form['lines'][]         = array( 'species' => 'Pale Test Tang', 'species_id' => '2', 'qty_ordered' => '2', 'unit_price' => '30', 'qty_received' => '' );
$form['lines'][]         = array( 'species' => 'Dusky Test Angel', 'qty_ordered' => '3', 'qty_received' => '3', 'unit_price' => '12' );
$form['lines'][0]['unit_price'] = '2.50';
Admin_Workbook::save_order( $s, $form, $id, $today );
$angel = fh_line( $s, $id, 'Dusky Test Angel' );
$s->set_line_stay( (int) $angel['id'], $s->create_stay( array( 'species' => 'Dusky Test Angel', 'checkin_date' => $today, 'tank_id' => 1, 'source' => 'order', 'order_line_id' => (int) $angel['id'] ) ), 1 );
$s->update_order_fields( $id, array( 'status' => 'ordered' ) );
$stays_before = count( $s->stays );

$invoice = implode(
	"\n",
	array(
		OI::block_header(),
		"Blue Test Wrasse\tTestus caeruleus\tL\t\t4\t2.75\t\t1\t30\t\tXA1\tNorthland\t",
		"Goby merah\t\t\t\t4\t1.30\t1\t1\t\t\tXA2\tNorthland\t?",
		"Goby merah\t\t\t\t1\t1.30\t\t2\t\t\tXA2\tNorthland\t",
		"Zzyzx Whatsit\t\t\t\t3\t9.00\t\t2\t\t\t\tNorthland\t",
		"Masked Test Damsel\t\t\t\t7\t0.50\t\t2\t\t\tXA8\tNorthland\t",
		"Golden Test Hawk\t\t\t\t2\t28.00\t\t2\t\t\tXA9\tNorthland\t",
		"Dusky Test Angel\t\t\t\t3\t12.00\t\t1\t\t\t\tNorthland\t",
		"Green Test Chromis\t\t\t\t20\t0.75\t\t3\t\t\tXB1\tSouthland\t",
		'',
		OI::fee_header(),
		"Packing charge\t24.00\tNorthland",
		"Freight per kg\t180.00\tNorthland",
		"Re-oxygen\t30.00\tSouthland",
		"Tariff\t5.25\t",
		"Livestock subtotal\t155.00\t",
		"Invoice total\t394.25\t",
	)
);
$ask = array( 'text' => $invoice, 'mode' => 'arrived' );
$pa  = fh_ask( $s, fh_form( $s, $id ), 'preview', $ask );
check( 'the block a chat assistant made maps by its own header, both parts', 'headers' === $pa['columns']['source'] && true === $pa['columns']['complete'] && 8 === count( $pa['rows'] ) && 4 === $pa['arrived']['fees']['count'] );
check( 'boxes from two origins on one invoice: the sections are offered, all ticked by default', array( array( 'name' => 'Northland', 'count' => 7, 'on' => true ), array( 'name' => 'Southland', 'count' => 1, 'on' => true ) ) === $pa['arrived']['sections'] );
check( 'with every section the rows add up to the invoice\'s own subtotal, and it says so', 'The rows add up to 155.00 — the invoice says 155.00. They agree.' === $pa['arrived']['sum'] && true === $pa['arrived']['sum_ok'] );
$wrong = fh_ask( $s, fh_form( $s, $id ), 'preview', array( 'text' => str_replace( "Livestock subtotal\t155.00", "Livestock subtotal\t156.00", $invoice ), 'mode' => 'arrived' ) );
check( '… and when it does not add up, it says that plainly', 0 === strpos( $wrong['arrived']['sum'], 'The rows add up to 155.00 — the invoice says 156.00. They do NOT agree' ) && false === $wrong['arrived']['sum_ok'] );

$north = array( 'grid' => $pa['grid'], 'mode' => 'arrived', 'sections' => array( 'Northland' ) );
$pn    = fh_ask( $s, fh_form( $s, $id ), 'preview', $north );
check( 'the owner keeps one order per origin: with only this order\'s section ticked, the other section\'s row is left out and counted', 7 === count( $pn['rows'] ) && null === fh_row( $pn, 'Green Test Chromis' ) && false !== strpos( implode( ' ', $pn['left_out']['items'] ), '1 row of another section' ) && array( true, false ) === array_column( $pn['arrived']['sections'], 'on' ) );
$same = fh_group( $pn, 'same' );
$diff = fh_group( $pn, 'diff' );
$miss = fh_group( $pn, 'missing' );
$xtra = fh_group( $pn, 'extra' );
$stay = fh_group( $pn, 'stay' );
check( 'Came as ordered: the wrasse — 4 of 4, paired by the supplier\'s code', 1 === count( $same ) && 'Blue Test Wrasse · Large' === $same[0]['text'] && 'ordered 4 · arrived 4' === $same[0]['detail'] && in_array( 'paired by the supplier\'s code', $same[0]['notes'], true ) );
check( 'Came short / over: the goby 5 of 6 — the same fish in two boxes, added up, the boxes noted, paired by CODE though the invoice calls it something else; the whatsit 3 of 2, over', 2 === count( $diff ) && 'ordered 6 · arrived 5' === $diff[0]['detail'] && false === $diff[0]['over'] && in_array( '2 rows added up', $diff[0]['notes'], true ) && in_array( 'boxes 1, 2', $diff[0]['notes'], true ) && 'ordered 2 · arrived 3' === $diff[1]['detail'] && true === $diff[1]['over'] );
check( 'Did not come: the blenny and the tang — ordered lines no row matched', array( 'Sample Blenny', 'Pale Test Tang' ) === array_column( $miss, 'text' ) && 'ordered 3 · arrived 0' === $miss[0]['detail'] && 'zero' === $miss[0]['choice'] );
check( 'Extra / substituted: the damsel and the hawk — rows no ordered line matched', array( 7, 2 ) === array_column( $xtra, 'arrived' ) && array( '', '' ) === array_column( $xtra, 'instead' ) );
check( 'a line with a stay is skipped and said so: the angel\'s row is set aside', 1 === count( $stay ) && 'Dusky Test Angel' === $stay[0]['text'] );
check( 'the price the wrasse already has (2.50) differs from the file\'s (2.75): shown, and a tick is offered', true === $pn['arrived']['differs'] && in_array( 'price typed 2.5 · the file says 2.75', $same[0]['notes'], true ) );
check_same( 'the foot, the way the owner says it — the checked-in angel counts with its 3', 'Ordered 20 · arrived 24 · 120% filled · 2 kinds did not come', $pn['foot'] );
check( 'the fees of this section: its own, and the one that names no section; the other section\'s left out', 'Fees on this invoice: tariffs 5.25 · everything else 204.00' === $pn['arrived']['fees']['text'] && 1 === count( preg_grep( '/1 fee of another section is left out/', $pn['arrived']['fees']['notes'] ) ) && '' === $pn['arrived']['sum'] );
check( 'a row marked "?" by whoever read the invoice is flagged', true === fh_row( $pn, 'Goby merah' )['check'] );

echo "12. … a substitution paired by hand, and the confirm\n";
$hawk_n  = fh_row( $pn, 'Golden Test Hawk' )['n'];
$tang_k  = (string) array_search( 'Pale Test Tang', array_column( fh_form( $s, $id )['lines'], 'species' ), true );
$chosen  = array_merge( $north, array( 'rows' => array( $hawk_n => array( 'pair' => 'instead:' . $tang_k ) ) ) );
$ph      = fh_ask( $s, fh_form( $s, $id ), 'preview', $chosen );
$xtra    = fh_group( $ph, 'extra' );
check( '"this came instead of …": the hawk is an extra instead of the tang, which stays at 0', $tang_k === $xtra[1]['instead'] && 'zero' === fh_group( $ph, 'missing' )[1]['choice'] );
$form_a                = fh_form( $s, $id, array( 'import_fees' => '50.00' ) );
$pf                    = fh_ask( $s, $form_a, 'preview', $chosen );
check( 'the order already has an import fee typed: the preview says a typed amount is kept unless replaced', true === $pf['arrived']['fees']['typed'] && 1 === count( preg_grep( '/already says: tariffs 0.00 · import & re-ox fees 50.00/', $pf['arrived']['fees']['notes'] ) ) );
$da = fh_ask( $s, $form_a, 'confirm', array_merge( $chosen, array( 'token' => $pf['token'], 'fees' => 1 ) ) );
$oa = $s->order( $id );
check( 'confirmed: the message is the foot, with what was added and what was left alone — and nothing was checked in', empty( $da['error'] ) && 0 === strpos( $da['message'], 'Ordered 20 · arrived 24 · 120% filled · 2 kinds did not come. 2 extra lines added. 1 line is already checked in and was left as it is. Nothing was checked in.' ) && $stays_before === count( $s->stays ) && 'ordered' === $oa['status'], $da['message'] ?? $da['error'] );
$l = static function ( $species ) use ( $s, $id ) {
	return fh_line( $s, $id, $species );
};
check( 'received is WRITTEN on the paired lines: 4, 5 (short), 3 (over)', 4 === $l( 'Blue Test Wrasse' )['qty_received'] && 5 === $l( 'Red Test Goby' )['qty_received'] && 3 === $l( 'Zzyzx Whatsit' )['qty_received'] );
check( 'a did-not-come line is KEPT, at 0 — never removed', 0 === $l( 'Sample Blenny' )['qty_received'] && 3 === $l( 'Sample Blenny' )['qty_ordered'] && 0 === $l( 'Pale Test Tang' )['qty_received'] && 8 === count( $oa['lines'] ) );
check( 'a typed price is kept (2.50, not the file\'s 2.75); an empty one is filled from the file (the goby: 1.2917 was imported, so kept; the whatsit keeps 9)', 2.5 === $l( 'Blue Test Wrasse' )['unit_price'] && 1.2917 === $l( 'Red Test Goby' )['unit_price'] && 9.0 === $l( 'Zzyzx Whatsit' )['unit_price'] );
check( 'DOA, the box and the percent of a box fill the line\'s EMPTY fields: the goby 1 DOA; the wrasse box 1 and 30%; two boxes are a note, not a box number', 1 === $l( 'Red Test Goby' )['qty_doa'] && 1 === $l( 'Blue Test Wrasse' )['box_no'] && 30.0 === $l( 'Blue Test Wrasse' )['box_pct'] && null === $l( 'Red Test Goby' )['box_no'] && false !== strpos( $l( 'Red Test Goby' )['notes'], '[boxes 1, 2]' ) && false !== strpos( $l( 'Red Test Goby' )['notes'], '[code XA2]' ) );
$damsel = $l( 'Masked Test Damsel' );
$hawk   = $l( 'Golden Test Hawk' );
check( 'the extras are appended as new lines with ordered 0, what arrived, the price, the box and the code', $damsel && 0 === $damsel['qty_ordered'] && 7 === $damsel['qty_received'] && 0.5 === $damsel['unit_price'] && 2 === $damsel['box_no'] && '[code XA8]' === $damsel['notes'] );
check( 'the substitution is noted on both: the hawk "came instead of" the tang, the tang "substituted by" the hawk and at 0', $hawk && 0 === $hawk['qty_ordered'] && 2 === $hawk['qty_received'] && false !== strpos( $hawk['notes'], '[came instead of: Pale Test Tang]' ) && false !== strpos( $l( 'Pale Test Tang' )['notes'], '[substituted by: Golden Test Hawk]' ) && 0 === $l( 'Pale Test Tang' )['qty_received'] );
check( 'the line with a stay is untouched', $angel === array_merge( $l( 'Dusky Test Angel' ), array( 'stay_id' => null, 'tank_id' => null, 'tank_number' => null, 'stay_live' => null, 'stay_status' => null ) ) || ( 3 === $l( 'Dusky Test Angel' )['qty_received'] && 12.0 === $l( 'Dusky Test Angel' )['unit_price'] && 3 === $l( 'Dusky Test Angel' )['qty_ordered'] && '' === $l( 'Dusky Test Angel' )['notes'] ) );
check( 'the fees, ticked: the EMPTY tariffs field takes the invoice\'s 5.25; the import fee already typed (50) is kept — nothing is added to it', 5.25 === $oa['tariffs'] && 50.0 === $oa['import_fees'] && 0.0 === $oa['domestic_flight'] );

echo "13. The fees and the price, by their ticks\n";
$again = fh_ask( $s, fh_form( $s, $id ), 'preview', $north );
check( 'the same invoice again: nothing is extra any more — the damsel and the hawk pair with the lines the first import made', array() === fh_group( $again, 'extra' ) && 'Ordered 20 · arrived 24 · 120% filled · 2 kinds did not come' === $again['foot'], $again['foot'] );
$s2  = fh_store();
$d2  = fh_ask( $s2, fh_form( $s2, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id2 = (int) $d2['order_id'];
$f2  = fh_form( $s2, $id2, array( 'tariffs' => '0.00', 'import_fees' => '50.00' ) );
$pv  = fh_ask( $s2, $f2, 'preview', $north );
$nf  = fh_ask( $s2, $f2, 'confirm', array_merge( $north, array( 'token' => $pv['token'] ) ) );
check( 'without the tick the fees are not touched', empty( $nf['error'] ) && 0.0 === $s2->order( $id2 )['tariffs'] && 50.0 === $s2->order( $id2 )['import_fees'] );
check( 'a line whose price was imported with the order sheet keeps it: 2.50, not the invoice\'s 2.75', 2.5 === fh_line( $s2, $id2, 'Blue Test Wrasse' )['unit_price'] );
$f2 = fh_form( $s2, $id2, array( 'import_fees' => '50.00' ) );
$pv = fh_ask( $s2, $f2, 'preview', $north );
$rf = fh_ask( $s2, $f2, 'confirm', array_merge( $north, array( 'token' => $pv['token'], 'fees' => 1, 'fees_replace' => 1, 'use_file_price' => 1 ) ) );
check( 'with the second tick the typed import fee is REPLACED by the invoice\'s 204.00 (never added to: not 254)', empty( $rf['error'] ) && 204.0 === $s2->order( $id2 )['import_fees'] && 5.25 === $s2->order( $id2 )['tariffs'] );
check( 'with "use the file\'s price" ticked the typed price is overwritten: 2.75', 2.75 === fh_line( $s2, $id2, 'Blue Test Wrasse' )['unit_price'] && 1.3 === fh_line( $s2, $id2, 'Red Test Goby' )['unit_price'] );
check( 'a second import of the same invoice adds no second set of extras: it pairs them with the lines the first one made (4 ordered + 3 extras)', 7 === count( $s2->order( $id2 )['lines'] ) && 0 === $rf['imported'] && 7 === fh_line( $s2, $id2, 'Masked Test Damsel' )['qty_received'] );
check( 'a received line no row matched is LEFT AS IT IS by default the second time (it holds a count or was never ordered)', 0 === fh_line( $s2, $id2, 'Sample Blenny' )['qty_received'] );

echo "14. Separate lines for the same fish in two boxes; a line the owner says did not come\n";
$s3  = fh_store();
$d3  = fh_ask( $s3, fh_form( $s3, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id3 = (int) $d3['order_id'];
$sep = array_merge( $north, array( 'same_fish' => 'separate' ) );
$ps  = fh_ask( $s3, fh_form( $s3, $id3 ), 'preview', $sep );
check( 'kept as two lines at the owner\'s choice: the first box fills the ordered line (4 of 6), the second is an extra', 'separate' === $ps['arrived']['same_fish'] && 'ordered 6 · arrived 4' === fh_group( $ps, 'diff' )[0]['detail'] && 4 === count( fh_group( $ps, 'extra' ) ) );
$blenny_k = (string) array_search( 'Sample Blenny', array_column( fh_form( $s3, $id3 )['lines'], 'species' ), true );
$keep     = array_merge( $north, array( 'lines' => array( $blenny_k => 'keep' ) ) );
$pk       = fh_ask( $s3, fh_form( $s3, $id3 ), 'preview', $keep );
$dk       = fh_ask( $s3, fh_form( $s3, $id3 ), 'confirm', array_merge( $keep, array( 'token' => $pk['token'] ) ) );
check( 'a did-not-come line the owner says to leave alone keeps its empty received — and is not counted as a kind that did not come', empty( $dk['error'] ) && null === fh_line( $s3, $id3, 'Sample Blenny' )['qty_received'] && false === strpos( $pk['foot'], 'did not come' ) && 'keep' === fh_group( $pk, 'missing' )[0]['choice'] );

echo "15. The Stats afterwards\n";
$stats = Stats::build( Admin_Workbook::resolved_orders( $s, array() ) );
$row   = $stats['orders'][0];
check( 'the order\'s fill rate reads ordered and received as imported — the did-not-come lines at 0 included: 24 ÷ 20', 20 === $row['ordered'] && 24 === $row['received'] && abs( $row['fill_rate'] - 1.2 ) < 1e-9 );
check( '… and so does "by supplier"', 'Example Aquatics' === $stats['by_supplier'][0]['name'] && abs( $stats['by_supplier'][0]['fill_rate'] - 1.2 ) < 1e-9 );
$s4  = fh_store();
$d4  = fh_ask( $s4, fh_form( $s4, 0 ), 'confirm', array( 'grid' => $grid, 'mode' => 'ordered', 'token' => $p['token'] ) );
$id4 = (int) $d4['order_id'];
$few = array( 'text' => "fish\tqty\nBlue Test Wrasse (L)\t4\nRed Test Goby\t3\n", 'mode' => 'arrived' );
$p4  = fh_ask( $s4, fh_form( $s4, $id4 ), 'preview', $few );
$d4b = fh_ask( $s4, fh_form( $s4, $id4 ), 'confirm', array( 'grid' => $p4['grid'], 'mode' => 'arrived', 'token' => $p4['token'] ) );
$st4 = Stats::build( Admin_Workbook::resolved_orders( $s4, array() ) );
check( 'an order that got 7 of its 15: "Ordered 15 · arrived 7 · 47% filled · 2 kinds did not come", and the Stats say 46.7%', 'Ordered 15 · arrived 7 · 47% filled · 2 kinds did not come' === $p4['foot'] && empty( $d4b['error'] ) && abs( $st4['orders'][0]['fill_rate'] - 7 / 15 ) < 1e-9 && '46.7%' === Admin_Workbook::figures( Admin_Workbook::resolve_order( $s4, $s4->order( $id4 ) ) )['order']['fill_rate'] && array( 4, 3, 0, 0 ) === array_column( $s4->order( $id4 )['lines'], 'qty_received' ) );
check( 'a plain pasted invoice (no code, no block): paired by fish and size', 2 === count( fh_group( $p4, 'same' ) ) + count( fh_group( $p4, 'diff' ) ) && 0 === count( fh_group( $p4, 'extra' ) ) );

echo "16. What the label said\n";
$s5  = fh_store();
$lab = "fish\treceived\tlabel\nBlue Test Wrasse\t2\tRed Test Goby\nGreen Test Chromis\t3\tGreen Test Chromis\nRed Test Goby\t1\t\n";
$p5  = fh_ask( $s5, fh_form( $s5, 0 ), 'preview', array( 'text' => $lab, 'mode' => 'arrived' ) );
$pick = array( 3 => array( 'pick' => array( 'kind' => 'fish', 'name' => 'Green Test Chromis', 'product_id' => 14 ) ) );
$p5b = fh_ask( $s5, fh_form( $s5, 0 ), 'preview', array( 'grid' => $p5['grid'], 'mode' => 'arrived', 'rows' => $pick ) );
$d5  = fh_ask( $s5, fh_form( $s5, 0 ), 'confirm', array( 'grid' => $p5['grid'], 'mode' => 'arrived', 'rows' => $pick, 'token' => $p5b['token'] ) );
$o5  = $s5->order( (int) $d5['order_id'] );
check( 'an invoice on an order with no lines: every row is an extra with ordered 0', empty( $d5['error'] ) && 3 === count( $o5['lines'] ) && array( 0, 0, 0 ) === array_column( $o5['lines'], 'qty_ordered' ) && array( 2, 3, 1 ) === array_column( $o5['lines'], 'qty_received' ) );
check( 'the file\'s own "label" column is kept when it names ANOTHER fish than the line — and counts as a mislabel', 'Red Test Goby' === $o5['lines'][0]['label_name'] && true === Identify::is_mislabeled( $o5['lines'][0] ) && null === $o5['lines'][1]['label_name'] );
check( 'a row that IS a fish the lists know, that the owner said is another fish — and that arrived — keeps the typed text as what the label said; no alias is learned for a real fish\'s own name… it is learned only as the supplier\'s word', 'Green Test Chromis' === $o5['lines'][2]['species'] && 'Red Test Goby' === $o5['lines'][2]['label_name'] && true === Identify::is_mislabeled( $o5['lines'][2] ) );

echo "17. A file: XLSX and CSV through the section's own reader\n";
$dir  = fh_test_tmp_dir();
$xlsx = fh_test_write_xlsx(
	$dir . '/order-sheet.xlsx',
	array(
		'Notes'      => array( 'A1' => 'Terms and conditions', 'A2' => 'Payment within 14 days' ),
		'Order form' => array(
			'A1' => 'Example Aquatics Export',
			'A3' => 'CODE', 'B3' => 'STOCK', 'C3' => 'ORDER', 'D3' => 'ENGLISH NAME', 'E3' => 'QTY/BOX', 'F3' => 'PRICE US$', 'H3' => 'BOXES',
			'A4' => 'XA1', 'B4' => '2m', 'C4' => 4, 'D4' => 'Blue Test Wrasse (L)', 'E4' => 12, 'F4' => 0.2291666, 'H4' => array( 'f' => 'C4/E4', 'v' => 0.3333333 ),
			'A5' => 'XA2', 'B5' => 5, 'D5' => 'Red Test Goby', 'E5' => 24, 'F5' => array( 'currency' => 1.25 ),
			'A6' => 'XA3', 'C6' => 2, 'D6' => 'Green Test Chromis', 'E6' => 30, 'F6' => 0.75,
		),
	)
);
$read = AOI::read_file( $xlsx, 'order-sheet.xlsx' );
check( 'an .xlsx: the first sheet with a header row the importer can save from is read — not the notes sheet in front of it', 'Order form' === $read['sheet'] && array( 'Notes', 'Order form' ) === $read['sheets'] && 5 === count( $read['grid'] ) && 'ENGLISH NAME' === $read['grid'][1][3] && '0.2291666' === $read['grid'][2][5] && '4' === $read['grid'][2][2] );
check( 'a sheet asked for by name is the one read', 'Notes' === AOI::read_file( $xlsx, 'order-sheet.xlsx', 'notes' )['sheet'] );
$sf = fh_store();
$px = AOI::request( $sf, array_merge( fh_form( $sf, 0 ), array( 'op' => 'preview', 'import' => wp_json_encode( array( 'mode' => 'ordered' ) ) ) ), array_merge( $ctx, array( 'file' => array( 'path' => $xlsx, 'name' => 'order-sheet.xlsx' ) ) ) );
check( 'previewed like a paste: 2 of 3 rows have an order quantity, the formula column is nobody\'s, STOCK is no quantity', '2 of 3 rows have an order quantity' === $px['summary'] && 'large' === $px['rows'][0]['size']['slug'] && 12 === $px['rows'][0]['per_box'] && 'Order form' === $px['sheet'] && 4 === $px['rows'][0]['ordered'] );
check( 'the uploaded file is deleted as soon as it was read', ! file_exists( $xlsx ) );
$csv = $dir . '/invoice.csv';
file_put_contents( $csv, "Code,Qty,Common Name,Unit,Amt\r\nXA1,4,\"Wrasse, Blue Test\",\"\$2.50\",10.00\r\nXA2,6,Red Test Goby,1.25,7.50\r\nSubtotal,,,,17.50\r\n" );
$pc = AOI::request( $sf, array_merge( fh_form( $sf, 0 ), array( 'op' => 'preview', 'import' => wp_json_encode( array( 'mode' => 'arrived' ) ) ) ), array_merge( $ctx, array( 'file' => array( 'path' => $csv, 'name' => 'invoice.csv' ) ) ) );
check( 'a .csv with quoted cells: read, mapped by its headings, the subtotal checked — and deleted', 2 === count( $pc['rows'] ) && 'Wrasse, Blue Test' === $pc['rows'][0]['typed'] && 2.5 === $pc['rows'][0]['price'] && 'XA2' === $pc['rows'][1]['code'] && 0 === strpos( $pc['arrived']['sum'], 'The rows add up to 17.50 — the invoice says 17.50. They agree.' ) && ! file_exists( $csv ) );
file_put_contents( $dir . '/notes.pdf', '%PDF-1.4 not a sheet' );
check( 'any other kind of file is refused', false !== strpos( AOI::read_file( $dir . '/notes.pdf', 'notes.pdf' )['error'], 'Only a .csv, .tsv, .txt, .xlsx or .xlsm' ) );
file_put_contents( $dir . '/fake.xlsx', 'not a zip at all' );
check( 'a file that only calls itself a workbook is refused by the reader', false !== strpos( AOI::read_file( $dir . '/fake.xlsx', 'fake.xlsx' )['error'], 'could not be read as a workbook' ) );
file_put_contents( $dir . '/huge.csv', str_repeat( "Test Fish,1\n", 200000 ) );
check( 'a text file over 2 MB is refused', false !== strpos( AOI::read_file( $dir . '/huge.csv', 'huge.csv' )['error'], '2 MB' ) );
check( 'the text types are what the upload adds to the workbook import\'s own', array( 'csv', 'tsv', 'txt' ) === array_keys( AOI::TEXT_MIMES ) );
fh_test_rmdir( $dir );

echo "18. The form's lines as one field\n";
$packed = Admin_Workbook::unpack_lines( array( 'ref' => 'x', 'lines_json' => wp_json_encode( array( array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => '4', 'needs_id' => true, 'bad' => array( 1 ) ), 'junk', array( 'species' => 'Red Test Goby', 'qty_ordered' => 6 ) ) ) ) );
check_same( 'lines_json is unpacked into the rows every reader of the form expects — scalars only, junk dropped', array( array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => '4' ), array( 'species' => 'Red Test Goby', 'qty_ordered' => '6' ) ), $packed['lines'] );
check( '… and a request without it is left as it is', array( 'lines' => array( array( 'species' => 'x' ) ) ) === Admin_Workbook::unpack_lines( array( 'lines' => array( array( 'species' => 'x' ) ) ) ) && ! isset( $packed['lines_json'] ) );
$s6  = fh_store();
$one = fh_form( $s6, 0 );
$rows = array();
for ( $i = 0; $i < 150; $i++ ) {
	$rows[] = array( 'id' => '0', 'species_id' => '', 'seen' => '', 'species' => 'Many Test Fish ' . $i, 'size_slug' => '', 'sci_name' => '', 'qty_ordered' => '1', 'per_box' => '', 'box_no' => '', 'box_pct' => '', 'bags' => '', 'qty_received' => '', 'unit_price' => '', 'worth' => '', 'qty_doa' => '0', 'qty_survived' => '', 'tank_id' => '' );
}
unset( $one['lines'] );
$one['lines_json'] = wp_json_encode( $rows );
$r6 = Admin_Workbook::save_order( $s6, Admin_Workbook::unpack_lines( $one ), 0, $today );
check( 'an order of 150 fish is one field, not 2,550 — more than PHP reads by default: all 150 lines are saved', count( $one ) < 20 && 150 * 17 > 1000 && 150 === count( $s6->order( (int) $r6['order_id'] )['lines'] ) );
$pj = AOI::request( $s6, array( 'order_id' => (int) $r6['order_id'], 'ref' => '2026-1', 'supplier' => 'Example Aquatics', 'lines_json' => wp_json_encode( fh_form( $s6, (int) $r6['order_id'] )['lines'] ), 'op' => 'preview', 'import' => wp_json_encode( array( 'text' => "fish\tqty\nMany Test Fish 7\t1\nNew Test Fish\t1\n", 'mode' => 'ordered' ) ) ), $ctx );
check( 'the importer reads the page\'s lines from that field too', 'Many Test Fish 7' === $pj['rows'][0]['dup'] && '' === $pj['rows'][1]['dup'] );

echo "19. The nonce and the capabilities\n";
$GLOBALS['fh_user_caps']    = array( Clean_Room::CAPABILITY => array( 2, 3 ), Clean_Room::SETTINGS_CAPABILITY => array( 3 ) );
$GLOBALS['fh_current_user'] = 9;
$handler                    = new AOI();
$reply                      = static function ( callable $call ) {
	try {
		$call();
	} catch ( Fh_Test_Json $json ) {
		return $json;
	}
	return null;
};
$_POST    = array( 'op' => 'preview', 'import' => wp_json_encode( array( 'text' => "fish\tqty\nTest Fish\t1\n" ) ) );
$_REQUEST = array( 'nonce' => wp_create_nonce( Admin::NONCE_AJAX ) );
$r        = $reply( array( $handler, 'ajax' ) );
check( 'a user who may not edit orders is refused (403)', $r && false === $r->success && 403 === $r->status );
$GLOBALS['fh_current_user'] = 2;
$_REQUEST                   = array( 'nonce' => 'wrong' );
$r                          = $reply( array( $handler, 'ajax' ) );
check( 'a wrong nonce is refused (403)', $r && false === $r->success && 403 === $r->status );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ) );
$_POST    = array( 'id' => 'abc' );
$r        = $reply( array( $handler, 'ajax_layout' ) );
check( 'forgetting a layout is a Settings matter: a user without that capability is refused (403)', $r && 403 === $r->status );
Import_Layouts::save( Import_Layouts::build( array( 'supplier' => 'Example Aquatics', 'moment' => 'ordered', 'grid' => array( array( 'fish', 'qty' ) ), 'header_row' => 0, 'map' => array( 0 => 'fish', 1 => 'qty' ), 'today' => $today ) ) );
$saved                      = Import_Layouts::all()[0]['id'];
$GLOBALS['fh_current_user'] = 3;
$_POST                      = array( 'id' => $saved );
$_REQUEST                   = array( '_wpnonce' => 'wrong' );
$r                          = $reply( array( $handler, 'ajax_layout' ) );
check( '… a wrong nonce too, and the layout is still there', $r && 403 === $r->status && 1 === count( Import_Layouts::all() ) );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ) );
$r        = $reply( array( $handler, 'ajax_layout' ) );
check( 'Forget, by a user who may: the layout goes', $r && true === $r->success && array() === Import_Layouts::all() );
$r = $reply( array( $handler, 'ajax_layout' ) );
check( '… and forgetting it twice says it is gone (400)', $r && false === $r->success && 400 === $r->status );
$GLOBALS['fh_current_user'] = 2;
$GLOBALS['wpdb']            = new class() {
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
	}
	public function get_var( $query ) {
		return 1 === preg_match( '/^SHOW TABLES LIKE (\S+)$/', (string) $query, $m ) ? $m[1] : null; // The tables are there.
	}
};
$_POST    = array( 'op' => 'preview', 'import' => '{}' );
$_REQUEST = array( 'nonce' => wp_create_nonce( Admin::NONCE_AJAX ) );
$_FILES   = array( 'file' => array( 'name' => 'x.csv', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK, 'size' => 10 ) );
$r        = $reply( array( $handler, 'ajax' ) );
check( 'a FILE asks for what the workbook import asks for: a user who may edit orders but not the settings cannot upload one (403) — pasting works for them', $r && false === $r->success && 403 === $r->status && false !== strpos( $r->data['message'], 'Paste the rows instead' ) );
$_FILES = array();
$_POST  = array();

echo "20. A section that is switched off is silent\n";
check( 'the section has not booted in this run: no import action is registered', false === Clean_Room::is_booted() && empty( $GLOBALS['fh_actions'][ 'wp_ajax_' . AOI::ACTION ] ) && empty( $GLOBALS['fh_actions'][ 'wp_ajax_' . AOI::ACTION_LAYOUT ] ) );
$handler->init();
check( 'once the admin half boots, both are — and each names a method that exists', 1 === count( $GLOBALS['fh_actions'][ 'wp_ajax_' . AOI::ACTION ] ) && is_callable( $GLOBALS['fh_actions'][ 'wp_ajax_' . AOI::ACTION ][0][0] ) && is_callable( $GLOBALS['fh_actions'][ 'wp_ajax_' . AOI::ACTION_LAYOUT ][0][0] ) );
$panel = (string) file_get_contents( Clean_Room::path() . 'views/order-import.php' );
$page  = (string) file_get_contents( Clean_Room::path() . 'views/order.php' );
check( 'the panel sits inside the order form, so not one of its fields has a name: the order\'s Save never gathers them — and every button is a plain button', 0 === preg_match( '/<(input|textarea|select)[^>]*\sname=/', $panel ) && 0 === preg_match( '/<button(?![^>]*type="button")/', $panel ) && strpos( $page, "include Clean_Room::path() . 'views/order-import.php'" ) > strpos( $page, '<form id="fh-cr-order"' ) && strpos( $page, "include Clean_Room::path() . 'views/order-import.php'" ) < strrpos( $page, '</form>' ) );
check( 'the order page offers "Import lines…" and "Received: all as ordered" under the lines; a file is offered only to who may upload one', false !== strpos( $page, 'fh-cr-import-open' ) && false !== strpos( $page, 'fh-cr-received-all' ) && false !== strpos( $panel, '<?php if ( $can_settings ) : ?>' ) && 1 === substr_count( $panel, 'type="file"' ) );
check( 'the two moments are two buttons, and the one that fits the order\'s status is on', 2 === substr_count( $panel, 'class="fh-cr-import__mode<' ) && false !== strpos( $panel, 'This is what I ORDERED' ) && false !== strpos( $panel, 'This is what ARRIVED' ) && false !== strpos( $panel, "array( 'received', 'closed' )" ) );
check( 'the instructions are printed escaped, from the one text the test above reads', false !== strpos( $panel, 'esc_textarea( Order_Import::instructions() )' ) );
check( 'the Arrivals list offers the same page with the panel open — no second screen', false !== strpos( (string) file_get_contents( Clean_Room::path() . 'views/arrivals.php' ), "add_query_arg( 'import', '1', \$new_url )" ) );
$config = AOI::js_config();
check( 'what the page is handed: the action, every role with its words, and a word for every thing the page says', AOI::ACTION === $config['action'] && array_keys( OI::roles() ) === array_keys( $config['roles'] ) && count( $config['i18n'] ) > 50 && array() === array_filter( $config['i18n'], static function ( $text ) { return ! is_string( $text ) || '' === $text; } ) );

fh_finish();
