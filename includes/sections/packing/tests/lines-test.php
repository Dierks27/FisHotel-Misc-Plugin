<?php
/**
 * An order's fish lines and its suggestion, end to end — on invented
 * products, customers' orders and numbers: which lines are fish (the pick
 * list's rules), what is ignored and named, what is left out (shipped,
 * refunded), a line with no bag size (listed, the suggestion marked
 * incomplete), invoice fish in each shape an order can carry them, the
 * "Livestock — packed in a bag" checkbox of the product deciding (ticked,
 * unticked — ignored and named as that —, no stored choice, a Bag size
 * from before the checkbox), a
 * combined box read through its live source orders, an order that went
 * into one, and what a person is told about it all.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/lines-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Lines;
use FisHotel\Misc\Sections\Packing\Livestock;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Suggest;

$settings = fh_test_settings();
$standard = fh_test_sizes();
$current  = Boxes::current( $settings, null, false );

/**
 * The invented catalogue.
 */
function fh_catalogue() {
	fh_reset_shop();
	fh_product( new Fh_Fake_Product( 100, 'variable', array( 'pa_size' => array( 'small', 'mid', 'show' ) ) ) );
	fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '3' ), 100 ) );
	fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'show' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 110, 'simple', array( 'pa_size' => array( 'big' ) ) ) );
	fh_product( new Fh_Fake_Product( 120, 'simple' ) );
	fh_product( new Fh_Fake_Product( 130, 'simple', array(), array( '_fh_bag_size' => '1' ) ) );
	fh_product( new Fh_Fake_Product( 140, 'simple' ) );
	$GLOBALS['fh_terms'] = array(
		100 => array( 'quarantined-fish' ),
		110 => array( 'inverts' ),
		120 => array( 'dry-goods' ),
		140 => array( 'invoices' ),
	);
}

/**
 * The suggestion of an order with the test's settings.
 */
function fh_suggest( $order ) {
	return Order_Packing::for_order( $order, $GLOBALS['settings'], $GLOBALS['standard'], $GLOBALS['current'] );
}

/**
 * "qty × name → bag" of every fish line.
 */
function fh_lines( array $result ) {
	$out = array();
	foreach ( $result['lines'] as $line ) {
		$out[] = $line['qty'] . ' × ' . $line['name'] . ' → ' . ( $line['bag'] ? $line['bag']['name'] : 'none' );
	}
	return $out;
}

fh_catalogue();

echo "1. An ordinary order\n";
$order  = fh_order(
	new Fh_Fake_Order(
		500,
		array(
			new Fh_Fake_Item( 1, 'Invented Goby - Small', 2, 100, array( 'pa_size' => 'small' ), 101 ),
			new Fh_Fake_Item( 2, 'Invented Goby - Mid', 1, 100, array( 'pa_size' => 'mid' ), 102 ),
			new Fh_Fake_Item( 3, 'Invented Snail', 1, 110 ),
			new Fh_Fake_Item( 4, 'Reef Salt 5 lb', 1, 120 ),
		)
	)
);
$result = fh_suggest( $order );
check( 'each fish line with its bag: the map, the variation\'s own value, a simple product\'s size', array( '2 × Invented Goby - Small → Small', '1 × Invented Goby - Mid → Large', '1 × Invented Snail → Large' ) === fh_lines( $result ), fh_test_export( fh_lines( $result ) ) );
check( 'a line says where its bag came from, its size, and one bag per fish', 'map' === $result['lines'][0]['bag']['source'] && 'own' === $result['lines'][1]['bag']['source'] && 'small' === $result['lines'][0]['size'] && 'Small (1-2")' === $result['lines'][0]['size_label'] && 2 === $result['lines'][0]['bags'] && 1 === $result['lines'][0]['fish_per_bag'] && 500 === $result['lines'][0]['order_id'] && 1 === $result['lines'][0]['item_id'] );
check( 'the bags by size, in the bag list\'s order, and their total', array( array( 1, 'Small', 2 ), array( 3, 'Large', 2 ) ) === array_map( static function ( $bag ) { return array( $bag['id'], $bag['name'], $bag['count'] ); }, $result['bags'] ) && 4 === $result['bag_total'] );
check( 'the suggested box and its fill: 2/12 + 2/4 = 66.7 % of the smallest box that takes a Large bag', Suggest::STATUS_FITS === $result['status'] && 1 === count( $result['boxes'] ) && 'Cube 12' === $result['boxes'][0]['name'] && 66.7 === $result['boxes'][0]['fill_pct'] && false === $result['on_the_line'] && false === $result['split'] );
check( 'what is not a fish is ignored — and named', array( array( 'item_id' => 4, 'order_id' => 500, 'name' => 'Reef Salt 5 lb', 'qty' => 1, 'reason' => 'not a fish' ) ) === $result['ignored'] );
check( 'every fish line has a bag: the suggestion is complete, nothing to say', true === $result['complete'] && array() === $result['incomplete'] && array() === $result['messages'] && true === $result['available'] && 'own' === $result['box_source'] );
check( 'working it out wrote nothing: not to the order, not to a line, not to a product', array() === $order->writes() && array() === $order->items[1]->writes && 0 === wc_get_product( 101 )->meta_saves );

echo "2. A line with no bag size\n";
$order->items[5] = new Fh_Fake_Item( 5, 'Invented Goby - Show', 3, 100, array( 'pa_size' => 'show' ), 103 );
$result          = fh_suggest( $order );
check( 'it is listed by name with the reason, and the suggestion is marked incomplete', false === $result['complete'] && array( array( 'item_id' => 5, 'order_id' => 500, 'name' => 'Invented Goby - Show', 'qty' => 3, 'reason' => 'no bag for the size Show (5"+) in the size → bag map' ) ) === $result['incomplete'], fh_test_export( $result['incomplete'] ) );
check( 'the box is still suggested for the lines that have a bag', 'Cube 12' === $result['boxes'][0]['name'] && 4 === $result['bag_total'] && null === $result['lines'][3]['bag'] && 0 === $result['lines'][3]['bags'] );
check( 'and a person is told so', 1 === count( $result['messages'] ) && false !== strpos( $result['messages'][0], '1 fish line has no bag size, so the suggestion is incomplete: 3 × Invented Goby - Show' ), fh_test_export( $result['messages'] ) );
unset( $order->items[5] );

echo "3. Refunds and lines that already left\n";
$order = fh_order(
	new Fh_Fake_Order(
		501,
		array(
			new Fh_Fake_Item( 1, 'Invented Goby - Small', 5, 100, array(), 101 ),
			new Fh_Fake_Item( 2, 'Invented Snail', 2, 110 ),
			new Fh_Fake_Item( 3, 'Invented Goby - Mid', 1, 100, array( '_fishotel_line_status' => 'shipped' ), 102 ),
			new Fh_Fake_Item( 4, 'Invented Goby - Mid', 1, 100, array( '_fishotel_line_status' => 'na' ), 102 ),
			new Fh_Fake_Item( 6, 'Invented Goby - Mid', 2, 100, array( '_fishotel_line_status' => 'backordered' ), 102 ),
		)
	)
);
$order->refunded = array( 1 => 2, 2 => 2 );
$result          = fh_suggest( $order );
check( 'a partly refunded line counts what is left; a backordered line still counts, flagged', array( '3 × Invented Goby - Small → Small', '2 × Invented Goby - Mid → Large' ) === fh_lines( $result ) && 'backordered' === $result['lines'][1]['flag'], fh_test_export( fh_lines( $result ) ) );
check( 'a fully refunded line, a shipped line and a line that never ships are left out, with the reason', array( 'refunded', 'already shipped', 'does not ship' ) === array_column( $result['skipped'], 'reason' ) && array( 2, 3, 4 ) === array_column( $result['skipped'], 'item_id' ), fh_test_export( $result['skipped'] ) );

echo "4. Fish that are not shop products\n";
$order  = fh_order(
	new Fh_Fake_Order(
		502,
		array(
			new Fh_Fake_Item( 1, 'Invented Tang', 2, 0, array( '_fh_invoice_fish' => '1', '_fh_invoice_product_id' => '140', 'pa_size' => 'mid' ) ),
			new Fh_Fake_Item( 2, 'Invented Wrasse', 1, 0, array( '_fh_invoice_fish' => 'yes' ) ),
			new Fh_Fake_Item( 3, 'A batch-invoice fish', 1, 0, array( '_fishotel_fish_id' => '77' ) ),
			new Fh_Fake_Item( 4, 'Credit: a lost fish', 1, 0, array( '_fh_invoice_credit' => '1' ) ),
			new Fh_Fake_Item( 5, 'Something with no product', 1, 0 ),
			new Fh_Fake_Item( 6, 'Sample Invoice (1/2/26)', 1, 140 ),
		)
	)
);
$result = fh_suggest( $order );
check( 'an itemised invoice fish with a size gets the map\'s bag; without one it has no bag', array( '2 × Invented Tang → Medium', '1 × Invented Wrasse → none', '1 × A batch-invoice fish → none' ) === fh_lines( $result ), fh_test_export( fh_lines( $result ) ) );
check( 'the ones without a bag are named as incomplete', array( 'Invented Wrasse', 'A batch-invoice fish' ) === array_column( $result['incomplete'], 'name' ) && 'no bag size' === $result['incomplete'][0]['reason'] );
check( 'a credit is money: neither a fish nor "ignored"', ! in_array( 'Credit: a lost fish', array_merge( array_column( $result['lines'], 'name' ), array_column( $result['ignored'], 'name' ) ), true ) );
check( 'a line with no product and nothing that says fish is ignored, with that reason', array( array( 'item_id' => 5, 'order_id' => 502, 'name' => 'Something with no product', 'qty' => 1, 'reason' => 'no product — nothing says it is a fish' ) ) === $result['ignored'] );
check( 'the Invoice product whose fish are already lines of their own is not opened up again', 3 === count( $result['lines'] ) );

echo "5. An Invoice kept as one line\n";
$snapshot = array(
	array( 'name' => 'Invented Tang', 'qty' => 2, 'kind' => 'fish', 'product_id' => 101 ),
	array( 'name' => 'Invented Wrasse', 'qty' => 1, 'kind' => 'fish', 'size' => 'Mid (2-3")' ),
	array( 'name' => 'Credit: a lost fish', 'qty' => 1, 'price' => -10, 'kind' => 'credit' ),
	array( 'name' => 'Free shipping this time', 'kind' => 'note' ),
	array( 'name' => 'An unlisted fish', 'qty' => 1 ),
);
$order    = fh_order( new Fh_Fake_Order( 503, array( new Fh_Fake_Item( 1, 'Sample Invoice (1/2/26)', 1, 140, array( '_fh_invoice_lines' => wp_json_encode( $snapshot ) ) ) ) ) );
$result   = fh_suggest( $order );
check( 'its fish come from the snapshot on the line: a product\'s bag, the bag of a size, or none', array( '2 × Invented Tang → Small', '1 × Invented Wrasse → Medium', '1 × An unlisted fish → none' ) === fh_lines( $result ), fh_test_export( fh_lines( $result ) ) );
check( 'each says which invoice it is from; credits and notes are not fish', array( 'Sample Invoice (1/2/26)', 'Sample Invoice (1/2/26)', 'Sample Invoice (1/2/26)' ) === array_column( $result['lines'], 'from' ) && false === $result['complete'] && array( 'An unlisted fish' ) === array_column( $result['incomplete'], 'name' ) );
$order->items[1]->qty  = 2;
$order->items[1]->meta = array( '_fh_invoice_lines' => $snapshot );
check( 'two of the invoice: twice the fish; a snapshot stored as an array reads the same', array( '4 × Invented Tang → Small', '2 × Invented Wrasse → Medium', '2 × An unlisted fish → none' ) === fh_lines( fh_suggest( $order ) ) );
$order->items[1]->qty  = 1;
$order->items[1]->meta = array();
$result                = fh_suggest( $order );
check( 'no snapshot and nothing here that can read the invoice: reported as a fish line without a bag, never dropped', array( '1 × Sample Invoice (1/2/26) → none' ) === fh_lines( $result ) && false === $result['complete'] && 'an invoice whose fish cannot be read here' === $result['incomplete'][0]['reason'] );
$order->items[1]->meta = array( '_fh_invoice_lines' => wp_json_encode( array( array( 'name' => 'Credit: a lost fish', 'price' => -5 ) ) ) );
check( 'a snapshot with no fish in it is flagged too', 'an invoice with no fish lines' === fh_suggest( $order )['incomplete'][0]['reason'] );
check( 'the snapshot reader: a JSON string, an array, one object, junk', 3 === count( Lines::invoice_fish( $snapshot ) ) && 1 === count( Lines::invoice_fish( array( 'name' => 'One fish' ) ) ) && null === Lines::invoice_fish( '' ) && null === Lines::invoice_fish( 'not json' ) && null === Lines::invoice_fish( 12 ) && array() === Lines::invoice_fish( '[]' ) );

echo "6. What is a fish\n";
$fish = array( 'quarantined-fish', 'inverts' );
check( 'a product in a fish category', Lines::KIND_FISH === Lines::kind( array(), array( 'Quarantined-Fish' ), 'Invented Goby', true, $fish ) );
check( 'a product in the invoices category, or named an Invoice', Lines::KIND_INVOICE === Lines::kind( array(), array( 'invoices' ), 'x', true, $fish ) && Lines::KIND_INVOICE === Lines::kind( array(), array(), 'Sample Invoice (1/2/26)', true, $fish ) );
check( 'the flags on the line are read before the categories', Lines::KIND_CREDIT === Lines::kind( array( '_fh_invoice_credit' => 1, '_fh_invoice_fish' => 1 ), array( 'quarantined-fish' ), 'x', true, $fish ) && Lines::KIND_FISH === Lines::kind( array( '_fh_invoice_fish' => true ), array( 'dry-goods' ), 'x', false, $fish ) );
check( 'anything else is not a fish', Lines::KIND_OTHER === Lines::kind( array(), array( 'dry-goods' ), 'Reef Salt', true, $fish ) && Lines::KIND_OTHER === Lines::kind( array( '_fishotel_fish_id' => '7' ), array(), 'x', true, $fish ) );
$order  = fh_order( new Fh_Fake_Order( 504, array( new Fh_Fake_Item( 1, 'Frag plug with a bag', 2, 130 ), new Fh_Fake_Item( 2, 'Reef Salt 5 lb', 1, 120 ) ) ) );
$result = fh_suggest( $order );
check( 'a product the owner gave a Bag size of its own before the checkbox existed — and has no stored choice — is still bagged, whatever its category', array( '2 × Frag plug with a bag → Small' ) === fh_lines( $result ) && array( 'Reef Salt 5 lb' ) === array_column( $result['ignored'], 'name' ) );
check( 'the fish categories are the Ship Day list\'s default', array( 'quarantined-fish', 'inverts' ) === Lines::fish_categories() );
add_filter(
	'fishotel_ship_day_fish_categories',
	static function ( $cats ) {
		$cats[] = ' Dry-Goods ';
		return $cats;
	}
);
check( 'under the same filter the pick list uses', array( 'quarantined-fish', 'inverts', 'dry-goods' ) === Lines::fish_categories() && array( '2 × Frag plug with a bag → Small', '1 × Reef Salt 5 lb → none' ) === fh_lines( fh_suggest( $order ) ) );
fh_reset_hooks();

echo "6b. Livestock — packed in a bag: the product decides\n";
$unticked = 'not packed in a bag — “Livestock — packed in a bag” is unticked on the product';
check( 'ticked on the product: a fish, whatever its category', Lines::KIND_FISH === Lines::kind( array(), array( 'dry-goods' ), 'A net', true, $fish, false, 'yes' ) && Lines::KIND_FISH === Lines::kind( array(), array(), 'x', true, $fish, false, ' YES ' ) );
check( 'unticked: not a fish — in a fish category, and with a Bag size stored earlier', Lines::KIND_OTHER === Lines::kind( array(), array( 'quarantined-fish' ), 'Invented Goby', true, $fish, false, 'no' ) && Lines::KIND_OTHER === Lines::kind( array(), array( 'quarantined-fish' ), 'Invented Goby', true, $fish, true, 'no' ) && Lines::KIND_OTHER === Lines::kind( array(), array(), 'x', true, $fish, true, 'no' ) );
check( 'no stored choice: the category decides, as it did — and a Bag size from before the checkbox still counts', Lines::KIND_FISH === Lines::kind( array(), array( 'inverts' ), 'x', true, $fish, false, '' ) && Lines::KIND_OTHER === Lines::kind( array(), array( 'dry-goods' ), 'x', true, $fish, false, '' ) && Lines::KIND_FISH === Lines::kind( array(), array( 'dry-goods' ), 'x', true, $fish, true, '' ) && Lines::KIND_FISH === Lines::kind( array(), array( 'dry-goods' ), 'x', true, $fish, true, 'junk' ) );
check( 'a fish of an invoice whose product was unticked is not bagged either; a credit is still money', Lines::KIND_OTHER === Lines::kind( array( '_fh_invoice_fish' => 1 ), array(), 'x', true, $fish, false, 'no' ) && Lines::KIND_FISH === Lines::kind( array( '_fh_invoice_fish' => 1 ), array(), 'x', true, $fish, false, '' ) && Lines::KIND_CREDIT === Lines::kind( array( '_fh_invoice_credit' => 1 ), array(), 'x', true, $fish, false, 'yes' ) );
check( 'a line with no product has no checkbox to go by: what the line says counts, as before', Lines::KIND_FISH === Lines::kind( array( '_fishotel_fish_id' => '7' ), array(), 'x', false, $fish, false, 'no' ) && Lines::KIND_OTHER === Lines::kind( array(), array(), 'x', false, $fish, false, 'yes' ) );

$order = fh_order(
	new Fh_Fake_Order(
		540,
		array(
			new Fh_Fake_Item( 1, 'Invented Goby - Small', 2, 100, array( 'pa_size' => 'small' ), 101 ),
			new Fh_Fake_Item( 2, 'Invented Goby - Mid', 1, 100, array( 'pa_size' => 'mid' ), 102 ),
			new Fh_Fake_Item( 3, 'Invented Snail', 1, 110 ),
			new Fh_Fake_Item( 4, 'Reef Salt 5 lb', 1, 120 ),
			new Fh_Fake_Item( 5, 'Frag plug with a bag', 2, 130 ),
		)
	)
);
$result = fh_suggest( $order );
check( 'with no choice stored anywhere the order reads as it always did', array( '2 × Invented Goby - Small → Small', '1 × Invented Goby - Mid → Large', '1 × Invented Snail → Large', '2 × Frag plug with a bag → Small' ) === fh_lines( $result ) && array( 'not a fish' ) === array_column( $result['ignored'], 'reason' ) && 'Cube 12' === $result['boxes'][0]['name'], fh_test_export( fh_lines( $result ) ) );

wc_get_product( 100 )->meta['_fh_packs_in_bag'] = 'no';  // The fish, unticked — on the parent.
wc_get_product( 130 )->meta['_fh_packs_in_bag'] = 'no';  // The frag plug with its Bag size from before.
$result = fh_suggest( $order );
check( 'a product that was unticked is ignored by the suggestion — every variation of it, its own Bag size or the map\'s', array( '1 × Invented Snail → Large' ) === fh_lines( $result ), fh_test_export( fh_lines( $result ) ) );
check( 'and named as ignored, with that reason — apart from what is simply not a fish', array( array( 'item_id' => 1, 'order_id' => 540, 'name' => 'Invented Goby - Small', 'qty' => 2, 'reason' => $unticked ), array( 'item_id' => 2, 'order_id' => 540, 'name' => 'Invented Goby - Mid', 'qty' => 1, 'reason' => $unticked ), array( 'item_id' => 4, 'order_id' => 540, 'name' => 'Reef Salt 5 lb', 'qty' => 1, 'reason' => 'not a fish' ), array( 'item_id' => 5, 'order_id' => 540, 'name' => 'Frag plug with a bag', 'qty' => 2, 'reason' => $unticked ) ) === $result['ignored'] && $unticked === Livestock::unticked_reason(), fh_test_export( $result['ignored'] ) );
check( 'the box is worked out for what is left: one Large bag, 25 % of the smallest box that takes it — and the suggestion is complete', 1 === $result['bag_total'] && array( array( 3, 1 ) ) === array_map( static function ( $bag ) { return array( $bag['id'], $bag['count'] ); }, $result['bags'] ) && 'Cube 12' === $result['boxes'][0]['name'] && 25.0 === $result['boxes'][0]['fill_pct'] && true === $result['complete'] && array() === $result['incomplete'] );
check( 'the Bag sizes stored earlier are still there: unticking deleted nothing, and working it out wrote nothing', '3' === wc_get_product( 102 )->meta['_fh_bag_size'] && '1' === wc_get_product( 130 )->meta['_fh_bag_size'] && 0 === wc_get_product( 130 )->meta_saves && array() === $order->writes() );

wc_get_product( 120 )->meta['_fh_packs_in_bag'] = 'yes'; // The salt, ticked: packed in a bag after all.
$result = fh_suggest( $order );
check( 'a product that was ticked is a fish line whatever its category — without a size or a Bag size it is listed as having no bag', array( '1 × Invented Snail → Large', '1 × Reef Salt 5 lb → none' ) === fh_lines( $result ) && false === $result['complete'] && array( 'Reef Salt 5 lb' ) === array_column( $result['incomplete'], 'name' ) && ! in_array( 'Reef Salt 5 lb', array_column( $result['ignored'], 'name' ), true ), fh_test_export( fh_lines( $result ) ) );
wc_get_product( 120 )->meta['_fh_bag_size'] = '2';
check( 'given a Bag size, it is bagged', array( '1 × Invented Snail → Large', '1 × Reef Salt 5 lb → Medium' ) === fh_lines( fh_suggest( $order ) ) );
unset( wc_get_product( 120 )->meta['_fh_bag_size'], wc_get_product( 120 )->meta['_fh_packs_in_bag'] );

wc_get_product( 100 )->meta['_fh_packs_in_bag'] = 'yes';
unset( wc_get_product( 130 )->meta['_fh_packs_in_bag'] );
check( 'ticked on a fish, and the choice taken off the frag plug: both are back', array( '2 × Invented Goby - Small → Small', '1 × Invented Goby - Mid → Large', '1 × Invented Snail → Large', '2 × Frag plug with a bag → Small' ) === fh_lines( fh_suggest( $order ) ) );
add_filter(
	'fishotel_ship_day_fish_categories',
	static function () {
		return array( 'inverts' );
	}
);
check( 'a ticked product stays a fish when its category is no longer a fish category; one with no stored choice follows the list', array( '2 × Invented Goby - Small → Small', '1 × Invented Goby - Mid → Large', '1 × Invented Snail → Large', '2 × Frag plug with a bag → Small' ) === fh_lines( fh_suggest( $order ) ) );
unset( wc_get_product( 100 )->meta['_fh_packs_in_bag'] );
check( '— without the choice the same fish is not one any more, except the variation that has a Bag size of its own', array( '1 × Invented Goby - Mid → Large', '1 × Invented Snail → Large', '2 × Frag plug with a bag → Small' ) === fh_lines( fh_suggest( $order ) ) );
fh_reset_hooks();

wc_get_product( 100 )->meta['_fh_packs_in_bag'] = 'no';
$invoice = fh_order( new Fh_Fake_Order( 541, array( new Fh_Fake_Item( 1, 'Sample Invoice (1/2/26)', 2, 140, array( '_fh_invoice_lines' => array( array( 'name' => 'Invented Tang', 'qty' => 2, 'kind' => 'fish', 'product_id' => 101 ), array( 'name' => 'Invented Wrasse', 'qty' => 1, 'kind' => 'fish', 'size' => 'mid' ) ) ) ) ) ) );
$result  = fh_suggest( $invoice );
check( 'an invoice that names an unticked product: that fish is ignored and named, the others are bagged', array( '2 × Invented Wrasse → Medium' ) === fh_lines( $result ) && array( array( 'item_id' => 1, 'order_id' => 541, 'name' => 'Invented Tang', 'qty' => 4, 'reason' => $unticked ) ) === $result['ignored'], fh_test_export( $result['ignored'] ) );
$flagged = fh_order( new Fh_Fake_Order( 542, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 1, 100, array( '_fh_invoice_fish' => '1' ), 101 ) ) ) );
check( 'a line the invoice flags as a fish, of an unticked product: ignored as well', array() === fh_lines( fh_suggest( $flagged ) ) && array( $unticked ) === array_column( fh_suggest( $flagged )['ignored'], 'reason' ) );
unset( wc_get_product( 100 )->meta['_fh_packs_in_bag'] );
check( 'and with the choice gone both are fish again', array( '4 × Invented Tang → Small', '2 × Invented Wrasse → Medium' ) === fh_lines( fh_suggest( $invoice ) ) && array( '1 × Invented Goby - Small → Small' ) === fh_lines( fh_suggest( $flagged ) ) );

echo "7. Fish per bag\n";
add_filter(
	'fishotel_packing_fish_per_bag',
	static function ( $per_bag, $product ) {
		return is_object( $product ) && 101 === $product->get_id() ? 2 : $per_bag;
	},
	10,
	2
);
$order  = fh_order( new Fh_Fake_Order( 505, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 5, 100, array(), 101 ), new Fh_Fake_Item( 2, 'Invented Snail', 1, 110 ) ) ) );
$result = fh_suggest( $order );
check( 'five fish at two per bag need three bags; the line says so', 3 === $result['lines'][0]['bags'] && 2 === $result['lines'][0]['fish_per_bag'] && 5 === $result['lines'][0]['qty'] && 3 === $result['bags'][0]['count'] && 4 === $result['bag_total'] );
fh_reset_hooks();

echo "8. A combined box is read through its live source orders\n";
fh_order( new Fh_Fake_Order( 510, array( new Fh_Fake_Item( 11, 'Invented Goby - Small', 2, 100, array(), 101 ) ), array( '_fishotel_fulfilled_by_order' => '600' ) ) );
fh_order( new Fh_Fake_Order( 511, array( new Fh_Fake_Item( 21, 'Invented Snail', 3, 110 ), new Fh_Fake_Item( 22, 'Reef Salt 5 lb', 1, 120 ) ), array( '_fishotel_fulfilled_by_order' => '600' ) ) );
fh_order( new Fh_Fake_Order( 512, array( new Fh_Fake_Item( 31, 'Invented Goby - Mid', 9, 100, array(), 102 ) ), array( '_fishotel_fulfilled_by_order' => '600' ), 'cancelled' ) );
$wrapper = fh_order(
	new Fh_Fake_Order(
		600,
		array( new Fh_Fake_Item( 91, 'A stale copy on the wrapper', 50, 100, array(), 101 ) ),
		array(
			'_fishotel_is_fulfillment'      => '1',
			'_fishotel_fulfillment_sources' => '510, 511,512,599,600',
		),
		'fulfillment'
	)
);
$result  = fh_suggest( $wrapper );
check( 'its lines are those of its live sources — not its own copies, not a cancelled source, not a source that does not exist', array( '2 × Invented Goby - Small → Small', '3 × Invented Snail → Large' ) === fh_lines( $result ), fh_test_export( fh_lines( $result ) ) );
check( 'each line says which order it is from; the sources are named', array( 510, 511 ) === array_column( $result['lines'], 'order_id' ) && array( 510, 511 ) === $result['sources'] && true === $result['is_fulfillment'] && 0 === $result['packed_with'] );
check( 'one box for the whole combined box: 2/12 + 3/4 = 91.7 %, on the line', 'Cube 12' === $result['boxes'][0]['name'] && 91.7 === $result['boxes'][0]['fill_pct'] && true === $result['on_the_line'] && array( 'Reef Salt 5 lb' ) === array_column( $result['ignored'], 'name' ) );
check( 'the source ids are read from the meta as a list, without the wrapper itself', array( 510, 511, 512, 599 ) === Lines::source_ids( $wrapper ) );

$wrapper->meta['_fishotel_fulfillment_sources'] = array( 598, 599 );
$result = fh_suggest( $wrapper );
check( 'no source can be loaded: the copies on the combined box are all there is', array( '50 × A stale copy on the wrapper → Small' ) === fh_lines( $result ) && array() === $result['sources'] );
$wrapper->meta['_fishotel_fulfillment_sources'] = '512';
$result = fh_suggest( $wrapper );
check( 'every source is cancelled: nothing to pack, said plainly', array() === $result['lines'] && Suggest::STATUS_EMPTY === $result['status'] && array( 'Every order in this combined box is cancelled or refunded: nothing to pack.' ) === $result['messages'], fh_test_export( $result['messages'] ) );

$source = wc_get_order( 510 );
$result = fh_suggest( $source );
check( 'an order that went into a combined box says which one: its box is suggested and confirmed there', 600 === $result['packed_with'] && false === $result['is_fulfillment'] && false !== strpos( $result['messages'][0], 'packed with combined box #600' ) && array( '2 × Invented Goby - Small → Small' ) === fh_lines( $result ) );
check( 'a combined box is never "packed with" itself or another', 0 === Lines::packed_with( $wrapper ) );

echo "9. What a person is told\n";
$order  = fh_order( new Fh_Fake_Order( 520, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 40, 100, array(), 101 ) ) ) );
$result = fh_suggest( $order );
check( 'a split says so, and names the box that is on the line', Suggest::STATUS_SPLIT === $result['status'] && 2 === count( $result['boxes'] ) && 'No single box holds it all: split into 2 boxes.' === $result['messages'][0] && false !== strpos( $result['messages'][1], 'On the line: Long 20 is 100% full (90% or more)' ), fh_test_export( $result['messages'] ) );
$order  = fh_order( new Fh_Fake_Order( 524, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 60, 100, array(), 101 ) ) ) );
$result = fh_suggest( $order );
check( 'two boxes of one kind, equally full, are said once', array( 'Long 20', 'Long 20' ) === array_column( $result['boxes'], 'name' ) && array( 'No single box holds it all: split into 2 boxes.', 'On the line: Long 20 is 100% full (90% or more) — confirm it.' ) === $result['messages'], fh_test_export( $result['messages'] ) );
$order  = fh_order( new Fh_Fake_Order( 521, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 6, 100, array(), 101 ) ) ) );
$result = fh_suggest( $order );
check( 'a single box at or over the threshold is flagged for the owner', true === $result['on_the_line'] && false !== strpos( $result['messages'][0], 'On the line: Cube 8 is 100% full' ) );
$result = Order_Packing::for_order( $order, $settings, $standard, Boxes::current( array_merge( $settings, array( 'boxes' => array() ) ), null, false ) );
check( 'no boxes at all: no suggestion, and where to add them', Suggest::STATUS_NO_BOXES === $result['status'] && array() === $result['boxes'] && false !== strpos( $result['messages'][0], 'There are no boxes to choose from' ) && false !== strpos( $result['messages'][0], 'FisHotel Tools → Packing → Boxes' ), fh_test_export( $result['messages'] ) );
$result = Order_Packing::for_order( $order, array_merge( $settings, array( 'fits' => array() ) ), $standard, $current );
check( 'boxes but no "how many fit" yet: no box takes the bags, and where to type them', Suggest::STATUS_NO_FIT === $result['status'] && array( array( 'id' => 1, 'name' => 'Small', 'count' => 6 ) ) === $result['unplaced'] && false !== strpos( $result['messages'][0], 'No box takes these bags' ) );
$order  = fh_order( new Fh_Fake_Order( 522, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 2, 100, array(), 101 ), new Fh_Fake_Item( 2, 'A jumbo fish', 1, 130 ) ) ) );
wc_get_product( 130 )->meta['_fh_bag_size'] = '4';
$result = Order_Packing::for_order( $order, $settings, $standard, Boxes::current( array_merge( $settings, array( 'boxes' => array_slice( $settings['boxes'], 0, 2 ) ) ), null, false ) );
check( 'a bag no box takes is named; the rest is still boxed, in the smallest box that holds it', Suggest::STATUS_PARTIAL === $result['status'] && array( array( 'id' => 4, 'name' => 'Jumbo', 'count' => 1 ) ) === $result['unplaced'] && 'Cube 8' === $result['boxes'][0]['name'] && array( 'No box takes: 1 × Jumbo — type how many fit under FisHotel Tools → Packing → Boxes.' ) === $result['messages'], fh_test_export( $result['messages'] ) );
$huge   = fh_order( new Fh_Fake_Order( 525, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 30 * Suggest::MAX_BOXES + 7, 100, array(), 101 ) ) ) );
$result = fh_suggest( $huge );
check( 'a split that stops at the most boxes a suggestion names says that — not that no box takes the bags', Suggest::STATUS_PARTIAL === $result['status'] && Suggest::MAX_BOXES === count( $result['boxes'] ) && array( array( 'id' => 1, 'name' => 'Small', 'count' => 7 ) ) === $result['unplaced'] && in_array( 'Not boxed: 7 × Small — a suggestion stops at ' . Suggest::MAX_BOXES . ' boxes.', $result['messages'], true ) && false === strpos( implode( ' ', $result['messages'] ), 'No box takes' ), fh_test_export( $result['messages'] ) );
$result = Order_Packing::for_order( $order, array_merge( $settings, array( 'bags' => array(), 'size_map' => array(), 'fits' => array() ) ), $standard, $current );
check( 'no bag sizes defined at all: said first', false !== strpos( $result['messages'][0], 'No bag sizes yet' ) && false === $result['complete'] );
$order  = fh_order( new Fh_Fake_Order( 523, array( new Fh_Fake_Item( 1, 'Reef Salt 5 lb', 1, 120 ) ) ) );
$result = fh_suggest( $order );
check( 'an order with no fish: nothing to bag, the dry goods named as ignored', Suggest::STATUS_EMPTY === $result['status'] && array( 'No fish to bag on this order.' ) === $result['messages'] && 1 === count( $result['ignored'] ) && true === $result['complete'] );

echo "10. With the Batch plugin and the theme there\n";
if ( ! class_exists( 'FisHotel_Ship_Day' ) ) {
	/**
	 * The Batch plugin's Ship Day class, as far as this section calls it.
	 */
	class FisHotel_Ship_Day {

		public static function fish_categories() {
			return array( 'inverts', 'corals' );
		}

		public static function parse_invoice_lines( $html ) {
			return array(
				array( 'fish' => 'Invented Blenny', 'qty' => 2, 'price' => 20.0, 'flag' => '' ),
				array( 'fish' => 'A line it could not read fully', 'qty' => 1, 'price' => null, 'flag' => 'check' ),
			);
		}
	}
}
check( 'the Ship Day list is used when the Batch plugin has it', array( 'inverts', 'corals' ) === Lines::fish_categories() );
$order  = fh_order( new Fh_Fake_Order( 530, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 1, 100, array(), 101 ), new Fh_Fake_Item( 2, 'Invented Snail', 1, 110 ) ) ) );
$result = fh_suggest( $order );
check( 'so the Packing box and the pick list agree on what is a fish', array( '1 × Invented Snail → Large' ) === fh_lines( $result ) && array( 'Invented Goby - Small' ) === array_column( $result['ignored'], 'name' ) );
$order  = fh_order( new Fh_Fake_Order( 531, array( new Fh_Fake_Item( 1, 'Sample Invoice (1/2/26)', 1, 140 ) ) ) );
$result = fh_suggest( $order );
check( 'an Invoice with no snapshot is read from its description by the Batch plugin\'s parser — a line it could not read fully still counts', array( '2 × Invented Blenny → none', '1 × A line it could not read fully → none' ) === fh_lines( $result ) && false === $result['complete'], fh_test_export( fh_lines( $result ) ) );

if ( ! class_exists( 'FisHotel_Invoice_Lines' ) ) {
	/**
	 * The theme's invoice reader, as far as this section calls it.
	 */
	class FisHotel_Invoice_Lines {

		public static function get_lines( $product ) {
			return array(
				array( 'name' => 'Invented Tang', 'qty' => 3, 'price' => 30.0, 'kind' => 'fish', 'product_id' => 101 ),
				array( 'name' => 'Credit: a lost fish', 'qty' => 1, 'price' => -10.0, 'kind' => 'credit', 'product_id' => 0 ),
			);
		}
	}
}
$result = fh_suggest( $order );
check( 'the theme\'s reader comes first when it is there: its fish, with the product\'s bag; its credits are not fish', array( '3 × Invented Tang → Small' ) === fh_lines( $result ) && true === $result['complete'], fh_test_export( fh_lines( $result ) ) );

fh_finish();
