<?php
/**
 * The order import and "Tidy up the sizes" on ONE shop — two pieces
 * that were each proved by themselves: an order sheet imported on the
 * shop's long list of sizes (3-5" and 3.5"+, which share a slug), the
 * real tidy-up (Sizes_Tidy_Run) run over the store's own order lines —
 * two sizes of one fish become one, so two lines of one order read
 * alike —, the invoice imported afterwards with the lines in another
 * order and a size that is no size any more (each row on the line with
 * its price; without a price the owner is asked, and the lines are told
 * apart for them), a closed order whose numbers do not move while its
 * size's name follows, and Undo.
 *
 * Every supplier, fish and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-import-tidy-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy-run.php';
require_once __DIR__ . '/fake-tidy-shop.php';
require_once __DIR__ . '/tidy-fixture.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Order_Import as AOI;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy_Run;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Tidy_Shop;

update_option( Clean_Room::OPTION_KEY, array() );
$today = '2026-03-02';
$ctx   = array( 'today' => $today, 'settings' => Settings::get(), 'actor_id' => 1 );

/**
 * What the store reads from the shop as it stands: the sizes, and the
 * prices of the variations that are switched on.
 */
function fh_read_shop( Fake_Tidy_Shop $shop, Fake_Store $store ) {
	$store->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms      = array( 'pa_size' => $shop->read_shop( 'pa_size' )['terms'] );
	$store->product_prices  = array();
	foreach ( $shop->products as $pid => $product ) {
		$info = array( 'prices' => array(), 'variations' => array() );
		foreach ( $product['variations'] as $vid => $variation ) {
			if ( 'publish' === $variation['status'] && '' !== $variation['price'] ) {
				$info['prices'][]     = (float) $variation['price'];
				$info['variations'][] = array( 'id' => $vid, 'price' => (float) $variation['price'], 'attributes' => $variation['attrs'] );
			}
		}
		$store->product_prices[ $pid ] = $info;
	}
}

/** A store beside the shop: its fish are the shop's products. */
function fh_store_of( Fake_Tidy_Shop $shop ) {
	$fish = array();
	foreach ( $shop->products as $pid => $product ) {
		if ( ! empty( $product['fish'] ) ) {
			$fish[ $pid ] = $product['title'];
		}
	}
	$store = new Fake_Store( $fish );
	fh_read_shop( $shop, $store );
	return $store;
}

/** The store's order lines as the shop's seam hands them to a run — and back again afterwards. */
function fh_lines_to_shop( Fake_Tidy_Shop $shop, Fake_Store $store ) {
	$shop->tables['order_lines'] = array();
	foreach ( $store->lines as $id => $line ) {
		$shop->add_row( 'order_lines', array( 'id' => $id, 'order_id' => $line['order_id'], 'species' => $line['species'], 'size_slug' => $line['size_slug'], 'size_label' => $line['size_label'], 'share_key' => $line['share_key'] ?? null ) );
	}
}

function fh_lines_from_shop( Fake_Tidy_Shop $shop, Fake_Store $store ) {
	foreach ( $shop->tables['order_lines'] as $id => $row ) {
		$store->lines[ $id ]['size_slug']  = $row['size_slug'];
		$store->lines[ $id ]['size_label'] = $row['size_label'];
	}
	fh_read_shop( $shop, $store );
}

/** Run to the end. */
function fh_steps( Fake_Tidy_Shop $shop, $run_id ) {
	for ( $i = 0; $i < 5000; $i++ ) {
		$progress = Sizes_Tidy_Run::step( $shop, $run_id );
		if ( $progress['finished'] ) {
			break;
		}
	}
	return $progress;
}

/** The order form as the page posts it. */
function fh_form( $store, $id, array $header = array() ) {
	$order = $id > 0 ? $store->order( $id ) : null;
	$form  = array_merge(
		array(
			'order_id'        => $id,
			'ref'             => $order ? $order['ref'] : '',
			'supplier'        => 'Example Aquatics',
			'origin'          => 'Northland',
			'order_date'      => '2026-03-01',
			'received_date'   => $order ? (string) $order['received_date'] : '',
			'status'          => $order ? $order['status'] : 'draft',
			'cost_mode'       => 'box',
			'tariffs'         => '0.00',
			'domestic_flight' => '0.00',
			'import_fees'     => '0.00',
			'credit'          => '0.00',
			'lines'           => array(),
		),
		$header
	);
	foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
		$form['lines'][] = array(
			'id'           => (string) $line['id'],
			'species_id'   => (string) $line['species_id'],
			'seen'         => Identify::seen( $line ),
			'species'      => $line['species'],
			'size_slug'    => (string) $line['size_slug'],
			'sci_name'     => $line['sci_name'],
			'qty_ordered'  => (string) $line['qty_ordered'],
			'qty_received' => null !== $line['qty_received'] ? (string) $line['qty_received'] : '',
			'qty_doa'      => (string) $line['qty_doa'],
			'qty_survived' => null !== $line['qty_survived'] ? (string) $line['qty_survived'] : '',
			'per_box'      => (string) $line['per_box'],
			'box_no'       => (string) $line['box_no'],
			'box_pct'      => (string) $line['box_pct'],
			'unit_price'   => null !== $line['unit_price'] ? (string) $line['unit_price'] : '',
			'worth'        => null !== $line['worth'] ? (string) $line['worth'] : '',
			'tank_id'      => (string) $line['tank_id'],
		);
	}
	return $form;
}

function fh_ask( $store, array $form, $op, array $import ) {
	global $ctx;
	return AOI::request( $store, array_merge( $form, array( 'op' => $op, 'import' => wp_json_encode( $import ) ) ), $ctx );
}

function fh_entries( array $preview, $key ) {
	foreach ( $preview['arrived']['groups'] as $group ) {
		if ( $group['key'] === $key ) {
			return $group['entries'];
		}
	}
	return array();
}

/** Each line in a few words: the fish and size, ordered, received, the price. */
function fh_told( $store, $id ) {
	$out = array();
	foreach ( $store->order( $id )['lines'] as $line ) {
		$out[] = array( AOI::line_text( $line ), $line['qty_ordered'], $line['qty_received'], $line['unit_price'] );
	}
	return $out;
}

/** The numbers of an order: its costing, and each line's price, revenue and profit. */
function fh_numbers( $store, $id ) {
	$order = Admin_Workbook::resolve_order( $store, $store->order( $id ) );
	$lines = array();
	foreach ( $order['lines'] as $line ) {
		$lines[] = array( $line['worth'], $line['revenue'], $line['profit'], $line['priced'] );
	}
	return array( $order['costing'], $lines );
}

/** The shop price each line of an order reads today. */
function fh_shop_prices( $store, $id ) {
	return array_column( Admin_Workbook::resolve_order( $store, $store->order( $id ) )['lines'], 'shop_price' );
}

/** An order imported from an order sheet. */
function fh_ordered( $store, $sheet ) {
	$preview = fh_ask( $store, fh_form( $store, 0 ), 'preview', array( 'text' => $sheet, 'mode' => 'ordered' ) );
	$done    = fh_ask( $store, fh_form( $store, 0 ), 'confirm', array( 'grid' => $preview['grid'], 'mode' => 'ordered', 'token' => $preview['token'] ) );
	return array( (int) ( $done['order_id'] ?? 0 ), $preview );
}

$sheet   = "fish\tsize\tordered\tprice\nBanded Goby\t2-3\"\t4\t6.00\nBanded Goby\t2-3.5\"\t2\t7.00\nCoral Beauty\t3-5\"\t3\t9.00\nDwarf Lion\t5\t1\t20.00\n";
$invoice = "fish\tsize\treceived\tprice\nBanded Goby\t2-3.5\"\t2\t7.00\nBanded Goby\t2-3\"\t4\t6.00\nCoral Beauty\t3-5\"\t3\t9.00\nDwarf Lion\t5\t1\t20.00\n";

echo "1. Before the tidy-up: the order sheet on the shop's long list of sizes\n";
$shop  = fh_tidy_shop();
$store = fh_store_of( $shop );
list( $id, $p ) = fh_ordered( $store, $sheet );
check_same( 'four lines; 3-5" is read as 3-5" although 3.5"+ makes the same slug; a bare "5" is no size and stays in the notes', array( array( 'Banded Goby · 2-3"', 4, null, 6.0 ), array( 'Banded Goby · 2-3.5"', 2, null, 7.0 ), array( 'Coral Beauty · 3-5"', 3, null, 9.0 ), array( 'Dwarf Lion', 1, null, 20.0 ) ), fh_told( $store, $id ) );
check( '… and the preview said so of the bare number only', '' === $p['rows'][2]['size']['note'] && false !== strpos( $p['rows'][3]['size']['note'], 'size "5" is not in your list of sizes' ) && '[size as written: 5]' === $store->order( $id )['lines'][3]['notes'] );

// An order of the same fish that was received and CLOSED before the tidy-up.
$old = Admin_Workbook::save_order( $store, fh_form( $store, 0, array( 'ref' => '2026-90', 'status' => 'received', 'received_date' => '2026-02-20', 'lines' => array( array( 'species' => 'Banded Goby', 'size_slug' => '2-3-5', 'qty_ordered' => '5', 'qty_received' => '5', 'qty_survived' => '5', 'unit_price' => '7', 'per_box' => '5' ), array( 'species' => 'Flame Hawk', 'size_slug' => 'small-medium', 'qty_ordered' => '2', 'qty_received' => '2', 'qty_survived' => '2', 'unit_price' => '8', 'per_box' => '5' ) ) ) ), 0, $today );
$old = (int) $old['order_id'];
Admin_Workbook::save_order( $store, fh_form( $store, $old, array( 'status' => 'closed', 'received_date' => '2026-02-20' ) ), $old, $today );
$closed_before = fh_numbers( $store, $old );
$open_before   = fh_shop_prices( $store, $id );
check( 'the closed order holds the prices it was closed with (the goby 2-3.5" at 18.00, the hawk Small-Medium at 22.00)', 'closed' === $store->order( $old )['status'] && array( 18.0, 22.0 ) === array_column( $store->order( $old )['lines'], 'worth' ) && 2 === count( $closed_before[1] ) );

echo "2. The tidy-up, run over the store's own order lines\n";
$lines_before = array_map( static function ( $line ) { return array( $line['size_slug'], $line['size_label'] ); }, $store->lines );
fh_lines_to_shop( $shop, $store );
$start = Sizes_Tidy_Run::start( $shop, Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) ), 'owner' );
$run   = fh_steps( $shop, $start['run'] );
fh_lines_from_shop( $shop, $store );
check( 'the run finished', true === $start['ok'] && true === $run['finished'] );
check_same( 'the open order: the two goby lines now read alike (2-3.5" became 2-3"), the coral beauty follows 3-5" to 3-4"; nothing else on a line moved', array( array( 'Banded Goby · 2-3"', 4, null, 6.0 ), array( 'Banded Goby · 2-3"', 2, null, 7.0 ), array( 'Coral Beauty · 3-4"', 3, null, 9.0 ), array( 'Dwarf Lion', 1, null, 20.0 ) ), fh_told( $store, $id ) );
check_same( 'the closed order: the name of a size that became another follows, a size that was taken off stays as it was given', array( 'Banded Goby · 2-3"', 'Flame Hawk · Small-Medium' ), array_column( fh_told( $store, $old ), 0 ) );
check( '… and not one of its numbers moved: the costing, every line\'s price, revenue and profit', $closed_before === fh_numbers( $store, $old ) );
check( 'the open order still finds the goby\'s price under the size\'s new name (18.00 before and after)', 18.0 === $open_before[1] && 18.0 === fh_shop_prices( $store, $id )[1] );

echo "3. The invoice afterwards: the dearer goby first, under a size that is no size any more\n";
$a = fh_ask( $store, fh_form( $store, $id ), 'preview', array( 'text' => $invoice, 'mode' => 'arrived' ) );
check( 'the row "2-3.5"" has no size now, and says so', null === $a['rows'][0]['size']['slug'] && false !== strpos( $a['rows'][0]['size']['note'], 'not in your list of sizes' ) && '2-3' === $a['rows'][1]['size']['slug'] );
$same = fh_entries( $a, 'same' );
check( 'each goby row is paired with the line of ITS price — not with the first line nobody took: all four lines came as ordered, none short or over', 4 === count( $same ) && array() === fh_entries( $a, 'diff' ) && array() === fh_entries( $a, 'extra' ) && array( 2 ) === $same[0]['rows'] && array( 1 ) === $same[1]['rows'] && array() === $same[0]['notes'] && array() === $same[1]['notes'] );
$c = fh_ask( $store, fh_form( $store, $id ), 'confirm', array( 'grid' => $a['grid'], 'mode' => 'arrived', 'token' => $a['token'] ) );
$goods = 0.0;
foreach ( $store->order( $id )['lines'] as $line ) {
	$goods += (float) $line['unit_price'] * (int) $line['qty_received'];
}
check_same( 'confirmed: 4 received on the 6.00 line and 2 on the 7.00 line — the goods cost what the invoice says (4 × 6 + 2 × 7 + 3 × 9 + 20 = 85.00)', array( true, array( 4, 2, 3, 1 ), 85.0 ), array( empty( $c['error'] ), array_column( $store->order( $id )['lines'], 'qty_received' ), $goods ) );

echo "4. … and a packing list without prices: the owner is asked\n";
$shop2  = fh_tidy_shop();
$store2 = fh_store_of( $shop2 );
list( $id2 ) = fh_ordered( $store2, "fish\tsize\tordered\tprice\nBanded Goby\t2-3\"\t4\t6.00\nBanded Goby\t2-3.5\"\t4\t7.00\n" );
fh_lines_to_shop( $shop2, $store2 );
fh_steps( $shop2, Sizes_Tidy_Run::start( $shop2, Sizes_Tidy::plan( $shop2->read_shop( 'pa_size' ) ), 'owner' )['run'] );
fh_lines_from_shop( $shop2, $store2 );
$list = "fish\tsize\treceived\nBanded Goby\t2-3.5\"\t4\nBanded Goby\t2-3\"\t3\n";
$a2   = fh_ask( $store2, fh_form( $store2, $id2 ), 'preview', array( 'text' => $list, 'mode' => 'arrived' ) );
$x2   = fh_entries( $a2, 'extra' );
check( 'nothing tells the two lines apart: both rows wait among the extras, neither line is given a count by a guess', 2 === count( $x2 ) && array() === fh_entries( $a2, 'same' ) && array() === fh_entries( $a2, 'diff' ) && 2 === count( fh_entries( $a2, 'missing' ) ) );
check_same( '… each naming the lines it could be, told apart by their price', 'Could be Banded Goby · 2-3" — ordered 4 · 6.00 each / Banded Goby · 2-3" — ordered 4 · 7.00 each — lines of the same fish in the same size. Say which line it is.', $x2[0]['note'] );
check_same( '… as the dropdown that pairs a row with a line does', array( 'Banded Goby · 2-3" — ordered 4 · 6.00 each', 'Banded Goby · 2-3" — ordered 4 · 7.00 each' ), array_column( $a2['arrived']['lines'], 'text' ) );
$key  = (string) $a2['arrived']['lines'][1]['key'];
$said = array( 'grid' => $a2['grid'], 'mode' => 'arrived', 'rows' => array( 1 => array( 'pair' => 'line:' . $key ) ) );
$a2b  = fh_ask( $store2, fh_form( $store2, $id2 ), 'preview', $said );
check( 'the owner says the first row is the 7.00 line: the other row takes the line that is left — one as ordered, one short', array() === fh_entries( $a2b, 'extra' ) && array( 1 ) === fh_entries( $a2b, 'same' )[0]['rows'] && array( 2 ) === fh_entries( $a2b, 'diff' )[0]['rows'] && 'ordered 4 · arrived 3' === fh_entries( $a2b, 'diff' )[0]['detail'] );
$c2 = fh_ask( $store2, fh_form( $store2, $id2 ), 'confirm', array_merge( $said, array( 'token' => $a2b['token'] ) ) );
check_same( '… and that is what is written', array( true, array( array( 'Banded Goby · 2-3"', 4, 3, 6.0 ), array( 'Banded Goby · 2-3"', 4, 4, 7.0 ) ) ), array( empty( $c2['error'] ), fh_told( $store2, $id2 ) ) );
$plain = fh_store_of( fh_tidy_shop() );
list( $id3 ) = fh_ordered( $plain, "fish\tordered\nBanded Goby\t4\nDwarf Lion\t1\n" );
check_same( 'two lines that do not read alike keep their plain words in the dropdown', array( 'Banded Goby — ordered 4', 'Dwarf Lion — ordered 1' ), array_column( fh_ask( $plain, fh_form( $plain, $id3 ), 'preview', array( 'text' => "fish\treceived\nBanded Goby\t4\n", 'mode' => 'arrived' ) )['arrived']['lines'], 'text' ) );

echo "5. Undo\n";
fh_lines_to_shop( $shop, $store );
$undo = Sizes_Tidy_Run::undo( $shop );
fh_steps( $shop, $undo['run'] );
fh_lines_from_shop( $shop, $store );
check( 'every line of both orders has the size it had', true === $undo['ok'] && $lines_before === array_map( static function ( $line ) { return array( $line['size_slug'], $line['size_label'] ); }, $store->lines ) );
check( '… and the closed order\'s numbers are still the ones it was closed with', $closed_before === fh_numbers( $store, $old ) );

fh_finish();
