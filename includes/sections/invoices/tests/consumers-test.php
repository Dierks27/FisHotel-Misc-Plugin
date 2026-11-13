<?php
/**
 * The seam — what this section answers, read by the real readers of the
 * other sections, all loaded together:
 *
 *   Clean Room → Stats → Real sales   Admin_Stats::invoice_lines_for(),
 *                                     Stats_Sales::invoice_parts() /
 *                                     snapshot_parts() / finish()
 *   Lost Fish                         Orders::lines(), rest_left()
 *   Packing                           Lines::for_order()
 *   Today                             Collector::count_lines()
 *
 * through the real fishotel_invoices_for_order() and
 * fishotel_invoices_is_adjustment() (the store and the shop are this
 * section's twins, handed in with Invoices::use_doubles()).
 *
 * The invoice here has a stated price ABOVE the sum of its lines, so it
 * carries an "Adjustment". The theme knows fish, credits and notes only:
 * the Adjustment reaches an order as a fish line. Every reader must take
 * it for what it is — money — and never for a fish to sell, to replace,
 * to bag or to ship.
 *
 * The orders are made the way the theme's inc/invoice-lines.php documents
 * it (and Grammar ports it): itemised — one product-less line per fish
 * line flagged `_fh_invoice_fish`, one fee per credit — or kept as one
 * line with the lines as the `_fh_invoice_lines` snapshot.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/consumers-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

// What the readers call that this section's own tests never needed.
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		return ! empty( $GLOBALS['fh_test_filters'][ $hook ] );
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-species.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-stats.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-stats-sales.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-admin-stats.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-money.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-cover.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-orders.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/packing/class-packing.php';
\FisHotel\Misc\Sections\Packing\Packing::load();
require_once FISHOTEL_MISC_PATH . 'includes/sections/today/class-collector.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Stats_Sales;
use FisHotel\Misc\Sections\Invoices\Grammar;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Rules;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;
use FisHotel\Misc\Sections\Lost_Fish\Orders as Lost_Fish_Orders;
use FisHotel\Misc\Sections\Packing\Lines as Packing_Lines;
use FisHotel\Misc\Sections\Packing\Settings as Packing_Settings;
use FisHotel\Misc\Sections\Today\Collector;

const USER  = 8;
const TODAY = '2026-11-03';

/**
 * The order lines the theme makes out of an invoice product: itemised
 * (every fish row a product-less line, every credit a fee) or kept whole
 * (one line with the snapshot).
 *
 * @param int    $product_id The invoice product.
 * @param string $title      Its name.
 * @param float  $price      Its price.
 * @param bool   $itemise    Itemised, or kept as one line.
 * @return array[] Items for fh_wc_order().
 */
function fh_theme_items( $product_id, $title, $price, $itemise ) {
	$product = wc_get_product( $product_id );
	$rows    = Grammar::product_lines( $product->get_meta( Invoices::PRODUCT_LINES_META ), $product->get_description() );

	if ( ! $itemise ) {
		return array(
			array(
				'name'       => $title,
				'qty'        => 1,
				'total'      => $price,
				'product_id' => $product_id,
				'meta'       => array(
					Invoices::ITEM_LINES_META   => json_encode( array_values( $rows ) ),
					Invoices::ITEM_PRODUCT_META => $product_id,
				),
			),
		);
	}

	$items = array();
	foreach ( $rows as $row ) {
		if ( 'fish' === $row['kind'] ) {
			$items[] = array(
				'name'       => $row['name'],
				'qty'        => $row['qty'],
				'total'      => $row['qty'] * (float) $row['price'],
				'product_id' => (int) $row['product_id'],
				'meta'       => array(
					Invoices::ITEM_FISH_META    => 1,
					Invoices::ITEM_PRODUCT_META => $product_id,
					Invoices::ITEM_TITLE_META   => $title,
				),
			);
		} elseif ( 'credit' === $row['kind'] ) {
			$items[] = array(
				'type'  => 'fee',
				'name'  => $row['name'],
				'total' => (float) $row['price'],
				'meta'  => array(
					Invoices::ITEM_CREDIT_META  => 1,
					Invoices::ITEM_PRODUCT_META => $product_id,
				),
			);
		}
	}
	return $items;
}

/**
 * An order line of a shop product that happens to carry a name.
 *
 * @param string $name Name.
 * @return WC_Order_Item_Product
 */
function fh_plain_item( $name ) {
	$item             = new WC_Order_Item_Product();
	$item->name       = $name;
	$item->product_id = 3001;
	return $item;
}

/**
 * One year's ledger of an order, the way Admin_Stats::build_ledger()
 * fills it: a lookup row per product line, its name and invoice flags
 * beside it, then the Invoices section asked through the real function.
 *
 * @param int $order_id Order.
 * @return array A finished ledger.
 */
function fh_ledger_of( $order_id ) {
	$ledger  = Stats_Sales::new_ledger( 2026, array( 'processing', 'completed' ) );
	$rows    = array();
	$details = array();
	foreach ( wc_get_order( $order_id )->get_items( 'line_item' ) as $key => $item ) {
		$row_id             = 100 * $order_id + $key + 1;
		$rows[]             = array(
			'row_id'           => $row_id,
			'order_id'         => $order_id,
			'product_id'       => $item->get_product_id(),
			'variation_id'     => $item->get_variation_id(),
			'qty'              => $item->get_quantity(),
			'revenue'          => (float) $item->get_total(),
			'parent_id'        => 0,
			'status'           => 'wc-processing',
			'date_created'     => '2026-11-03 10:00:00',
			'parent_status'    => '',
			'parent_date'      => '',
			'refunded_item_id' => 0,
		);
		$lines              = $item->get_meta( Stats_Sales::META_LINES );
		$details[ $row_id ] = array(
			'name'               => $item->get_name(),
			'fish'               => '' !== (string) $item->get_meta( Stats_Sales::META_FISH ),
			'lines'              => '' !== (string) $lines ? (string) $lines : null,
			'invoice_product_id' => (int) $item->get_meta( Stats_Sales::META_PRODUCT_ID ),
		);
	}
	Stats_Sales::add_rows( $ledger, $rows, $details );

	$parts = array();
	if ( Admin_Stats::invoices_available() ) {
		foreach ( Stats_Sales::invoice_orders( $ledger ) as $id ) {
			$found = Admin_Stats::invoice_lines_for( $id );
			if ( $found ) {
				$parts[ $id ] = $found;
			}
		}
	}
	return Stats_Sales::finish( $ledger, $parts );
}

/**
 * The named sales of a ledger: name => array( units, money ).
 *
 * @param array $ledger A finished ledger.
 * @return array
 */
function fh_named( array $ledger ) {
	$out = array();
	foreach ( $ledger['named'] as $row ) {
		$out[ $row['name'] ] = array( (int) $row['qty'], round( (float) $row['revenue'], 2 ) );
	}
	ksort( $out );
	return $out;
}

/* ================================================================== */

$world     = fh_wc_world();
$store     = new Fake_Store();
$shop      = new Shop();
$publisher = new Publisher( $store, $shop, new Fake_Tanks(), Settings::defaults() );

// 2 × 45 + 12.50 + 70 − 10 = 162.50; the stated price is 200: an Adjustment of 37.50.
$check = $publisher->precheck(
	array(
		'handle'         => 'Tester',
		'customer_id'    => $world['customer'],
		'customer_name'  => 'Test Person',
		'customer_email' => 'person@example.test',
		'shipping'       => 'free',
		'flat_on'        => true,
		'flat_total'     => '200',
		'lines'          => array(
			array( 'kind' => 'fish', 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'unit_price' => '45', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'size_slug' => 'medium', 'size_label' => 'Medium', 'take_stock' => true ),
			array( 'kind' => 'fish', 'name' => 'Red Test Goby', 'qty' => 1, 'unit_price' => '12.50', 'source' => 'tank', 'stay_id' => 9, 'product_id' => 502 ),
			array( 'kind' => 'fish', 'name' => 'Pink Test Anthias', 'qty' => 1, 'unit_price' => '70', 'source' => 'species', 'species_id' => 31 ),
			array( 'kind' => 'credit', 'name' => 'lost Test Cleaner Wrasse', 'unit_price' => '10' ),
		),
	),
	USER,
	TODAY
);
$done  = $publisher->publish( $check['id'], $check['token'], array(), USER, TODAY );
$pid   = (int) $done['product_id'];
$title = wc_get_product( $pid )->get_name();

$paid      = array( 'status' => 'processing', 'date_paid' => true, 'total' => 200, 'customer_id' => $world['customer'], 'billing_first_name' => 'Test', 'billing_last_name' => 'Person', 'billing_email' => 'billing@example.test' );
$itemised  = fh_wc_order( $paid, fh_theme_items( $pid, $title, 200.0, true ) );
$kept      = fh_wc_order( $paid, fh_theme_items( $pid, $title, 200.0, false ) );
$item_rows = array_values( wc_get_order( $itemised )->get_items( 'line_item' ) );

echo "1. The marker the section really writes\n";
$json = json_decode( wc_get_product( $pid )->get_meta( Invoices::PRODUCT_LINES_META ), true );
$adj  = array_values( array_filter( $json, static function ( $row ) {
	return 'Adjustment' === $row['name'];
} ) );
check_same( 'the theme\'s JSON carries the Adjustment as ONE fish row — name "Adjustment", quantity 1, the difference, no product', array( array( 'name' => 'Adjustment', 'qty' => 1, 'price' => 37.5, 'kind' => 'fish' ) ), $adj );
check_same( 'so an itemised order has it as a product-less line flagged _fh_invoice_fish', array( 'Blue Test Tang (Medium)', 'Red Test Goby', 'Pink Test Anthias', 'Adjustment' ), array_map( static function ( $item ) {
	return $item->get_name();
}, $item_rows ) );
check( 'the name is the marker: one constant, the same in the lines and in the file that is loaded while the section is off', Invoices::ADJUSTMENT_NAME === Lines::ADJUSTMENT_NAME && 'Adjustment' === Invoices::ADJUSTMENT_NAME );
check( 'fishotel_invoices_is_adjustment(): that name, whatever the case and the spaces around it — and nothing else', true === fishotel_invoices_is_adjustment( 'Adjustment' ) && true === fishotel_invoices_is_adjustment( ' adjustment ' ) && false === fishotel_invoices_is_adjustment( 'Adjustment Test Wrasse' ) && false === fishotel_invoices_is_adjustment( 'Discount' ) && false === fishotel_invoices_is_adjustment( '' ) && false === fishotel_invoices_is_adjustment( null ) && false === fishotel_invoices_is_adjustment( array( 'Adjustment' ) ) );
check( 'it answers while the section is switched off (the orders it wrote stay)', false === Invoices::is_on() && true === fishotel_invoices_is_adjustment( 'Adjustment' ) );
check_contains( 'a fish cannot be given that name: Publish is refused', 'cannot be named "Adjustment"', Rules::roundtrip_problem( array( 'kind' => 'fish', 'name' => 'adjustment', 'qty' => 1, 'unit_price' => 5.0 ) ) );
check_same( '… while the Adjustment line itself reads back fine', '', Rules::roundtrip_problem( array( 'kind' => 'discount', 'name' => 'Adjustment', 'qty' => 1, 'unit_price' => 37.5 ) ) );

echo "2. Switched off: nobody is answered, and the readers know it\n";
check( 'the functions exist and answer nothing', function_exists( 'fishotel_invoices_for_order' ) && array() === fishotel_invoices_for_order( $itemised ) && array() === fishotel_invoices_lines_for_product( $pid ) );
check( 'Stats does not take the function for the section: not available, nobody asked', false === Admin_Stats::invoices_available() && null === Admin_Stats::invoice_lines_for( $itemised ) );
check( 'Lost Fish neither', false === Lost_Fish_Orders::invoices_available() );
$off = fh_ledger_of( $itemised );
check_same( 'Stats, itemised order: the three fish by their names — and the Adjustment with the credits (37.50), not as a fish sold', array( array( 'Blue Test Tang (Medium)' => array( 2, 90.0 ), 'Pink Test Anthias' => array( 1, 70.0 ), 'Red Test Goby' => array( 1, 12.5 ) ), array( 'lines' => 1, 'revenue' => 37.5 ) ), array( fh_named( $off ), $off['credits'] ) );
$off = fh_ledger_of( $kept );
check_same( 'Stats, kept as one line: split by the snapshot into the same three fish; the credit (−10) and the Adjustment (+37.50) are the credits — it adds up to the 200 paid', array( array( 'Blue Test Tang (Medium)' => array( 2, 90.0 ), 'Pink Test Anthias' => array( 1, 70.0 ), 'Red Test Goby' => array( 1, 12.5 ) ), array( 'lines' => 2, 'revenue' => 27.5 ), array() ), array( fh_named( $off ), $off['credits'], $off['unsplit'] ) );

echo "3. Switched on: fishotel_invoices_for_order() as it really answers\n";
Invoices::use_doubles( $store, $shop );
$answer = fishotel_invoices_for_order( $itemised );
check_same( 'one invoice, its lines: the Adjustment is a `discount` line with a positive amount', array( array( 'fish', 'fish', 'fish', 'credit', 'discount' ), 37.5, 200.0 ), array( array_column( $answer[0]['lines'], 'kind' ), $answer[0]['lines'][4]['amount'], array_sum( array_column( $answer[0]['lines'], 'amount' ) ) ) );
check( 'the readers see the section', true === Admin_Stats::invoices_available() && true === Lost_Fish_Orders::invoices_available() );

$parts = Admin_Stats::invoice_lines_for( $itemised );
check_same( 'Stats reads exactly that: three fish and two credits (−10, +37.50) that add up to the 200 paid', array( array( 'fish', 'fish', 'fish', 'credit', 'credit' ), array( 90.0, 12.5, 70.0, -10.0, 37.5 ), '' ), array( array_column( $parts, 'kind' ), array_column( $parts, 'total' ), Stats_Sales::split_reason( $parts, 1, 200.0 ) ) );
check( '… each fish with what its line links to: the product and variation, the tank line\'s product, the species-list entry', 501 === $parts[0]['product_id'] && 777 === $parts[0]['variation_id'] && 502 === $parts[1]['product_id'] && 31 === $parts[2]['species_id'] && $pid === $parts[0]['invoice_product_id'] );
$on = fh_ledger_of( $itemised );
check( 'itemised order: the anthias line now carries its species (31), the goby its product (502) — and the Adjustment is still money', 31 === (int) array_column( $on['named'], 'species_id', 'name' )['Pink Test Anthias'] && 502 === (int) array_column( $on['named'], 'product_id', 'name' )['Red Test Goby'] && ! isset( fh_named( $on )['Adjustment'] ) && array( 'lines' => 1, 'revenue' => 37.5 ) === $on['credits'] );
$on = fh_ledger_of( $kept );
check_same( 'kept as one line: split by the section\'s own lines — the same fish, both credits, nothing unsplit', array( array( 'Blue Test Tang (Medium)' => array( 2, 90.0 ), 'Pink Test Anthias' => array( 1, 70.0 ), 'Red Test Goby' => array( 1, 12.5 ) ), array( 'lines' => 2, 'revenue' => 27.5 ), array() ), array( fh_named( $on ), $on['credits'], $on['unsplit'] ) );

$hand = fh_wc_product( array( 'name' => 'Hand Invoice (7/3/26)', 'description' => "<ul>\n<li>Hand Test Goby x2 — \$39</li>\n<li>Adjustment x1 — \$20</li>\n</ul>", 'regular_price' => '98', 'category_ids' => array( $world['cat_invoices'] ) ) );
check_same( 'an invoice made by hand with an "Adjustment x1" line: handed out as a discount line too, never as a fish', array( array( 'fish', 'discount' ), array( 78.0, 20.0 ) ), array( array_column( fishotel_invoices_lines_for_product( $hand ), 'kind' ), array_column( fishotel_invoices_lines_for_product( $hand ), 'amount' ) ) );

echo "4. Lost Fish: the lines of the order\n";
$orders = new Lost_Fish_Orders();
$lines  = $orders->lines( wc_get_order( $itemised ) );
check_same( 'itemised: the three fish are lines — the Adjustment is not one', array( 'Blue Test Tang (Medium)', 'Red Test Goby', 'Pink Test Anthias' ), array_column( $lines, 'name' ) );
check( '… each linked by the section\'s line (found again by its name and quantity): the tang to its product, variation and size, the goby to its tank group (9) and product (502), the anthias by its name', 501 === $lines[0]['link']['product_id'] && 777 === $lines[0]['link']['variation_id'] && 'medium' === $lines[0]['link']['size_slug'] && 9 === $lines[1]['link']['stay_id'] && 502 === $lines[1]['link']['product_id'] && 'Pink Test Anthias' === $lines[2]['link']['species'] );
check( '… which is how a loss in tank group 9, or of product 502, finds the goby\'s line — and no other', 'invoice' === Lost_Fish_Orders::match_line( $lines[1], array( 'product_id' => 0, 'species' => 'Some Other Test Fish', 'names' => array() ), array( 9 ) ) && 'invoice' === Lost_Fish_Orders::match_line( $lines[1], array( 'product_id' => 502, 'species' => '', 'names' => array() ) ) && '' === Lost_Fish_Orders::match_line( $lines[2], array( 'product_id' => 502, 'species' => '', 'names' => array() ), array( 9 ) ) );
check_same( 'kept as one line: its three fish, each a line of its own — the Adjustment is not one', array( 'Blue Test Tang (Medium)', 'Red Test Goby', 'Pink Test Anthias' ), array_column( $orders->lines( wc_get_order( $kept ) ), 'name' ) );
$solo  = $publisher->precheck( array( 'handle' => 'Solo', 'customer_name' => 'Solo Person', 'customer_email' => 'solo@example.test', 'shipping' => 'free', 'flat_on' => true, 'flat_total' => '50', 'lines' => array( array( 'kind' => 'fish', 'name' => 'Green Test Chromis', 'qty' => 1, 'unit_price' => '40', 'source' => 'typed' ) ) ), USER, TODAY );
$solo  = $publisher->publish( $solo['id'], $solo['token'], array(), USER, TODAY );
$alone = fh_wc_order( array_merge( $paid, array( 'total' => 50 ) ), fh_theme_items( (int) $solo['product_id'], wc_get_product( (int) $solo['product_id'] )->get_name(), 50.0, true ) );
$only  = $orders->lines( wc_get_order( $alone ) );
check( 'an order of ONE fish and an Adjustment: when that fish is lost nothing else is left to ship (the Adjustment is not "the rest of the order")', 1 === count( $only ) && false === $orders->rest_left( wc_get_order( $alone ), $only[0]['ref'], 1 ) && true === $orders->rest_left( wc_get_order( $itemised ), $lines[1]['ref'], 1 ) );

echo "5. Packing: what has to be bagged\n";
$settings = Packing_Settings::defaults();
$standard = array( 'source' => 'none', 'kind' => '', 'attribute' => '', 'label' => '', 'sizes' => array() );
$bagged   = Packing_Lines::for_order( wc_get_order( $itemised ), $settings, $standard );
check_same( 'itemised: 4 fish in three lines — the Adjustment is not a fish to bag, and not a line "without a bag size" either', array( array( 'Blue Test Tang (Medium)' => 2, 'Red Test Goby' => 1, 'Pink Test Anthias' => 1 ), array() ), array( array_column( $bagged['lines'], 'qty', 'name' ), array_column( $bagged['ignored'], 'name' ) ) );
$bagged = Packing_Lines::for_order( wc_get_order( $kept ), $settings, $standard );
check_same( 'kept as one line: opened up into the same three', array( 'Blue Test Tang (Medium)' => 2, 'Red Test Goby' => 1, 'Pink Test Anthias' => 1 ), array_column( $bagged['lines'], 'qty', 'name' ) );
check( 'the pure pieces: an invoice fish line named Adjustment is money; with a product it is whatever its product is; the snapshot reader leaves it out', Packing_Lines::KIND_CREDIT === Packing_Lines::kind( array( Packing_Lines::META_INV_FISH => '1' ), array(), 'Adjustment', false, array( 'quarantined-fish' ) ) && Packing_Lines::KIND_FISH === Packing_Lines::kind( array( Packing_Lines::META_INV_FISH => '1' ), array(), 'Red Test Goby', false, array( 'quarantined-fish' ) ) && Packing_Lines::KIND_FISH === Packing_Lines::kind( array( Packing_Lines::META_INV_FISH => '1' ), array(), 'Adjustment', true, array( 'quarantined-fish' ) ) && array( 'Red Test Goby' ) === array_column( Packing_Lines::invoice_fish( '[{"name":"Red Test Goby","qty":1,"price":12.5,"kind":"fish"},{"name":"Adjustment","qty":1,"price":37.5,"kind":"fish"}]' ), 'name' ) );

echo "6. Today: what ships\n";
check_same( 'the Adjustment is not counted among the things to ship (4, not 5)', 4, array_sum( Collector::count_lines( wc_get_order( $itemised ) ) ) );
check( '… a line that merely has that name but is no invoice line still counts', false === Collector::is_invoice_adjustment( fh_plain_item( 'Adjustment' ) ) && true === Collector::is_invoice_adjustment( $item_rows[3] ) && false === Collector::is_invoice_adjustment( $item_rows[0] ) );

Invoices::use_doubles( null );
check( 'the twins handed back: the section is off again', false === Invoices::is_on() && array() === fishotel_invoices_for_order( $itemised ) );

fh_finish();
