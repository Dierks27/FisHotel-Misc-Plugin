<?php
/**
 * The picker's search — one box, the results in groups: fish in the
 * tanks now (species, size, how many alive, tank, which supplier order,
 * how many already stand on other open invoices), shop products with
 * their size variations (and whether each tracks stock), the species
 * list, and "use what I typed"; the price that fills in (the variation's
 * / product's, else the species list's, else none); the ids each row
 * keeps; what is said when the Clean Room or WooCommerce cannot be
 * read; the group another section adds through the filter; and the
 * Tanks class against a Clean Room that is not there.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/search-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Search;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tanks;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

$world    = fh_wc_world();
$store    = new Fake_Store();
$shop     = new Shop();
$tanks    = new Fake_Tanks();
$settings = Settings::defaults();

// The shop: a variable fish with two sizes, a simple fish, a draft fish, a fish without a price,
// a fish on sale, something that only mentions the word in its description — and two invoices.
$tang = fh_wc_product( array( 'name' => 'Blue Test Tang', 'category_ids' => array( $world['cat_fish'] ) ), array(), 'WC_Product_Variable' );
$tang_m = fh_wc_product( array( 'name' => 'Blue Test Tang - Medium', 'parent_id' => $tang, 'regular_price' => '45', 'attributes' => array( 'pa_size' => 'medium' ), 'manage_stock' => true, 'stock_quantity' => 3 ), array(), 'WC_Product_Variation' );
$tang_l = fh_wc_product( array( 'name' => 'Blue Test Tang - Large', 'parent_id' => $tang, 'regular_price' => '60', 'attributes' => array( 'pa_size' => 'large' ) ), array(), 'WC_Product_Variation' );
$tang_x = fh_wc_product( array( 'name' => 'Blue Test Tang - Private', 'parent_id' => $tang, 'regular_price' => '99', 'status' => 'private', 'attributes' => array( 'pa_size' => 'medium' ) ), array(), 'WC_Product_Variation' );
$GLOBALS['fh_wc']['products'][ $tang ]['data']['children'] = array( $tang_m, $tang_l, $tang_x );
$goby    = fh_wc_product( array( 'name' => 'Red Test Goby', 'regular_price' => '12.50', 'category_ids' => array( $world['cat_fish'] ) ) );
$draft   = fh_wc_product( array( 'name' => 'Draft Test Tang', 'regular_price' => '80', 'status' => 'draft', 'category_ids' => array( $world['cat_fish'] ) ) );
$noprice = fh_wc_product( array( 'name' => 'Unpriced Test Tang', 'category_ids' => array( $world['cat_fish'] ) ) );
$sale    = fh_wc_product( array( 'name' => 'Sale Test Tang', 'regular_price' => '50', 'sale_price' => '40', 'category_ids' => array( $world['cat_fish'] ), 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) );
$mention = fh_wc_product( array( 'name' => 'Test Fish Food', 'description' => 'Every tang loves it.', 'regular_price' => '9', 'sku' => 'FOOD-1' ) );
$trashed = fh_wc_product( array( 'name' => 'Trashed Test Tang', 'regular_price' => '5', 'status' => 'trash' ) );
$invoice = fh_wc_product( array( 'name' => 'Tang Lover Invoice (7/3/26)', 'regular_price' => '45', 'category_ids' => array( $world['cat_invoices'] ) ) );
$titled  = fh_wc_product( array( 'name' => 'Tang Fan Invoice (8/8/26)', 'regular_price' => '45' ) );

$tanks->groups  = array(
	array( 'stay_id' => 21, 'species' => 'Blue Test Tang', 'size_slug' => 'medium', 'size_label' => 'Medium', 'alive' => 5, 'tank' => 121, 'order_ref' => '2026-3', 'product_id' => $tang, 'needs_id' => false, 'open' => true ),
	array( 'stay_id' => 22, 'species' => 'Blue Test Tang', 'size_slug' => 'jumbo', 'size_label' => 'Jumbo', 'alive' => 1, 'tank' => 511, 'order_ref' => '2026-4', 'product_id' => $tang, 'needs_id' => false, 'open' => true ),
	array( 'stay_id' => 23, 'species' => '?? grey Test Tang', 'size_slug' => null, 'size_label' => null, 'alive' => 2, 'tank' => 512, 'order_ref' => '', 'product_id' => null, 'needs_id' => true, 'open' => true ),
	array( 'stay_id' => 24, 'species' => 'Sold Test Tang', 'size_slug' => null, 'size_label' => null, 'alive' => 0, 'tank' => 122, 'order_ref' => '2026-3', 'product_id' => null, 'needs_id' => false, 'open' => false ),
	array( 'stay_id' => 25, 'species' => 'Red Test Goby', 'size_slug' => null, 'size_label' => null, 'alive' => 4, 'tank' => 123, 'order_ref' => '2026-3', 'product_id' => $goby, 'needs_id' => false, 'open' => true ),
);
$tanks->species = array(
	array( 'species_id' => 31, 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus', 'price' => 55.0, 'product_id' => $tang ),
	array( 'species_id' => 32, 'name' => 'Unpriced Test Tang', 'sci_name' => '', 'price' => 70.0, 'product_id' => $noprice ),
	array( 'species_id' => 33, 'name' => 'Listed Test Tang', 'sci_name' => 'Testus listus', 'price' => null, 'product_id' => null ),
);
// Two of the five medium tangs already stand on another open invoice; one on a paid one (which does not count).
$store->save_invoice( 0, array( 'handle' => 'Other', 'handle_key' => 'other', 'state' => 'published' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 2, 'source' => 'tank', 'stay_id' => 21 ) ) ), null, 1 );
$store->save_invoice( 0, array( 'handle' => 'Paid', 'handle_key' => 'paid', 'state' => 'paid' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 1, 'source' => 'tank', 'stay_id' => 21 ) ) ), null, 1 );

function group( array $groups, $key ) {
	foreach ( $groups as $group ) {
		if ( $group['key'] === $key ) {
			return $group;
		}
	}
	return null;
}

function item( array $group, $name ) {
	foreach ( $group['items'] as $item ) {
		if ( $item['name'] === $name ) {
			return $item;
		}
	}
	return null;
}

echo "1. The groups\n";
$groups = Search::run( $shop, $tanks, $store, $settings, 'tang' );
check_same( 'four groups, in this order', array( 'tanks', 'products', 'species', 'typed' ), array_column( $groups, 'key' ) );
check( 'each has a label and says it could be read', 4 === count( array_filter( array_column( $groups, 'label' ) ) ) && array( true, true, true, true ) === array_column( $groups, 'available' ) );
check_same( 'a search text of one letter searches nothing', array(), Search::run( $shop, $tanks, $store, $settings, 't' ) );
check_same( '…nor does an empty one', array(), Search::run( $shop, $tanks, $store, $settings, '   ' ) );

echo "2. In the tanks now\n";
$in_tanks = group( $groups, 'tanks' );
check_same( 'the groups with fish alive whose species matches — a closed, empty group is not offered', array( 'Blue Test Tang (Medium)', 'Blue Test Tang (Jumbo)', '?? grey Test Tang' ), array_column( $in_tanks['items'], 'name' ) );
$medium = item( $in_tanks, 'Blue Test Tang (Medium)' );
check_same( 'species and size in the name; alive, tank, supplier order and what other open invoices hold in the detail', '5 alive · tank 121 · order 2026-3 · 2 on other open invoices', $medium['detail'] );
check( 'it keeps the ids of where it came from: the tank group, its product, the variation of that size', 'tank' === $medium['source'] && 21 === $medium['stay_id'] && $tang === $medium['product_id'] && $tang_m === $medium['variation_id'] && 'medium' === $medium['size_slug'] && 'Medium' === $medium['size_label'] );
check( 'the price fills in from the variation of that size', 45.0 === $medium['price'] && 'variation' === $medium['price_from'] );
check( 'alive and reserved are numbers the page can warn with; a paid invoice\'s fish are not "reserved"', 5 === $medium['alive'] && 2 === $medium['reserved'] );
$jumbo = item( $in_tanks, 'Blue Test Tang (Jumbo)' );
check( 'a size the product has no variation for: the species list\'s price, and the species id', 55.0 === $jumbo['price'] && 'species' === $jumbo['price_from'] && 0 === $jumbo['variation_id'] && 31 === $jumbo['species_id'] );
$unknown = item( $in_tanks, '?? grey Test Tang' );
check( 'a fish nobody has identified yet: no price, and a warning', null === $unknown['price'] && '' === $unknown['price_from'] && 'not identified yet' === $unknown['warn'] && '2 alive · tank 512' === $unknown['detail'] );
check_same( 'a tank group linked to a simple product: that product\'s price', array( 12.5, 'product' ), array( item( group( Search::run( $shop, $tanks, $store, $settings, 'goby' ), 'tanks' ), 'Red Test Goby' )['price'], item( group( Search::run( $shop, $tanks, $store, $settings, 'goby' ), 'tanks' ), 'Red Test Goby' )['price_from'] ) );
$own = group( Search::run( $shop, $tanks, $store, $settings, 'tang', 1 ), 'tanks' );
check_same( 'the invoice being built does not count against itself', 0, item( $own, 'Blue Test Tang (Medium)' )['reserved'] );

echo "3. Shop products and their size variations\n";
$products = group( $groups, 'products' );
check_same( 'every published variation of a variable product, and the simple products — titles that hold the word', array( 'Blue Test Tang (Medium)', 'Blue Test Tang (Large)', 'Draft Test Tang', 'Unpriced Test Tang', 'Sale Test Tang' ), array_column( $products['items'], 'name' ) );
$pm = item( $products, 'Blue Test Tang (Medium)' );
check( 'a variation: the product id, the VARIATION id, its size, its price', 'product' === $pm['source'] && $tang === $pm['product_id'] && $tang_m === $pm['variation_id'] && 'medium' === $pm['size_slug'] && 'Medium' === $pm['size_label'] && 45.0 === $pm['price'] && 'variation' === $pm['price_from'] );
check( 'it tracks stock: the stock tick can be offered, with how many are left', true === $pm['managed'] && 3 === $pm['stock'] && 'shop stock 3' === $pm['detail'] );
$pl = item( $products, 'Blue Test Tang (Large)' );
check( 'a variation that does not track stock: no tick, said so', false === $pl['managed'] && null === $pl['stock'] && 'stock not tracked' === $pl['detail'] && 60.0 === $pl['price'] );
check( 'a draft product is offered and marked', false !== strpos( item( $products, 'Draft Test Tang' )['detail'], 'product is draft' ) );
$np = item( $products, 'Unpriced Test Tang' );
check( 'a product without a price: the species list\'s price', 70.0 === $np['price'] && 'species' === $np['price_from'] && 32 === $np['species_id'] );
$sl = item( $products, 'Sale Test Tang' );
check( 'a product on sale: the price a shopper pays now, and the regular price in the detail; stock 0 shown', 40.0 === $sl['price'] && false !== strpos( $sl['detail'], 'on sale, regular $50' ) && 0 === $sl['stock'] && true === $sl['managed'] );
check( 'an invoice is never offered as a fish — by its category or by its title', null === item( $products, 'Tang Lover Invoice (7/3/26)' ) && null === item( $products, 'Tang Fan Invoice (8/8/26)' ) );
check( 'a trashed product is not offered; a private variation is not either', null === item( $products, 'Trashed Test Tang' ) && 2 === count( array_filter( array_column( $products['items'], 'variation_id' ) ) ) );
check( 'a product that only mentions the word in its description is left out when titles match', null === item( $products, 'Test Fish Food' ) );
check_same( '…and shown when no title does', array( 'Test Fish Food' ), array_column( group( Search::run( $shop, $tanks, $store, $settings, 'loves' ), 'products' )['items'], 'name' ) );
check_same( 'found by its SKU', array( 'Test Fish Food' ), array_column( group( Search::run( $shop, $tanks, $store, $settings, 'food-1' ), 'products' )['items'], 'name' ) );
check_same( 'every word must match, in any order', array( 'Red Test Goby' ), array_column( group( Search::run( $shop, $tanks, $store, $settings, 'goby red' ), 'products' )['items'], 'name' ) );

echo "4. The species list, and what was typed\n";
$species = group( $groups, 'species' );
check_same( 'the species-list rows', array( 'Blue Test Tang', 'Unpriced Test Tang', 'Listed Test Tang' ), array_column( $species['items'], 'name' ) );
$sp = item( $species, 'Blue Test Tang' );
check( 'the species id, its linked product, the list\'s price, the scientific name', 'species' === $sp['source'] && 31 === $sp['species_id'] && $tang === $sp['product_id'] && 55.0 === $sp['price'] && 'species' === $sp['price_from'] && 'Testus caeruleus' === $sp['detail'] );
check( 'a species without a price: none fills in', null === item( $species, 'Listed Test Tang' )['price'] && '' === item( $species, 'Listed Test Tang' )['price_from'] );
$typed = group( Search::run( $shop, $tanks, $store, $settings, '  Brand  New <b>Test</b> Fish ' ), 'typed' );
check( '"use what I typed": the text, cleaned, with no id and no price', 1 === count( $typed['items'] ) && 'Brand New Test Fish' === $typed['items'][0]['name'] && 'typed' === $typed['items'][0]['source'] && null === $typed['items'][0]['price'] && 0 === $typed['items'][0]['product_id'] + $typed['items'][0]['stay_id'] + $typed['items'][0]['species_id'] );

echo "5. What cannot be read is said plainly\n";
$tanks->on = false;
$off       = Search::run( $shop, $tanks, $store, $settings, 'tang' );
check( 'the Clean Room switched off: the tanks and the species list say so and offer nothing', false === group( $off, 'tanks' )['available'] && '' !== group( $off, 'tanks' )['note'] && array() === group( $off, 'tanks' )['items'] && false === group( $off, 'species' )['available'] && '' !== group( $off, 'species' )['note'] );
check( '…the shop products and the typed name still work', 5 === count( group( $off, 'products' )['items'] ) && 1 === count( group( $off, 'typed' )['items'] ) );
check( '…and a product without a price then simply has none', null === item( group( $off, 'products' ), 'Unpriced Test Tang' )['price'] );
$tanks->on = true;

echo "6. The real Tanks class, with no Clean Room loaded\n";
$real = new Tanks();
check( 'not available, with the reason in plain words', false === $real->available() && false !== strpos( $real->reason(), 'Clean Room section is switched off' ) );
check( 'every read answers "nothing" instead of failing', array() === $real->search( 'tang' ) && null === $real->group_by_id( 21 ) && array() === $real->species_search( 'tang' ) && array( 'price' => null, 'species_id' => 0 ) === $real->species_price( 5, 'Blue Test Tang' ) );
$groups_real = Search::run( $shop, $real, $store, $settings, 'tang' );
check( 'the search still answers: shop products and the typed name', false === group( $groups_real, 'tanks' )['available'] && 5 === count( group( $groups_real, 'products' )['items'] ) );

echo "7. A group another section adds through the filter\n";
add_filter(
	'fishotel_invoices_search_groups',
	static function ( $groups, $term, $invoice_id ) {
		$groups[] = array(
			'key'   => 'Asked For!',
			'label' => 'Asked for by this customer <script>',
			'note'  => "  for \"$term\", invoice $invoice_id ",
			'items' => array(
				array( 'name' => ' Requested <i>Test</i> Angel ', 'price' => '120', 'kind' => 'fish', 'source' => 'species', 'species_id' => '44', 'qty' => 3, 'detail' => 'asked for on the round\'s form', 'evil' => 'x' ),
				array( 'name' => 'lost Test Wrasse', 'price' => -82, 'kind' => 'credit' ),
				array( 'name' => '' ),
				'junk',
				array( 'name' => 'Weird', 'kind' => 'gift', 'source' => 'moon', 'price' => 'abc', 'product_id' => -5 ),
			),
		);
		$groups[] = 'junk';
		$groups[] = array( 'label' => 'no key' );
		return $groups;
	},
	10,
	3
);
$groups = Search::run( $shop, $tanks, $store, $settings, 'tang', 7 );
$added  = group( $groups, 'askedfor' );
check( 'the group is there, after the four of this section, its key and texts cleaned', 5 === count( $groups ) && null !== $added && 'Asked for by this customer' === $added['label'] && 'for "tang", invoice 7' === $added['note'] && true === $added['available'] );
check( 'its rows are cleaned: only known keys, typed values, the nameless row and the junk dropped', 3 === count( $added['items'] ) && 'Requested Test Angel' === $added['items'][0]['name'] && 120.0 === $added['items'][0]['price'] && 44 === $added['items'][0]['species_id'] && 3 === $added['items'][0]['qty'] && ! isset( $added['items'][0]['evil'] ) );
check( 'a row may be a credit', 'credit' === $added['items'][1]['kind'] && -82.0 === $added['items'][1]['price'] );
check( 'an unknown kind is a fish, an unknown source is typed, a price that is no number is none, a negative id is none', 'fish' === $added['items'][2]['kind'] && 'typed' === $added['items'][2]['source'] && null === $added['items'][2]['price'] && 0 === $added['items'][2]['product_id'] );
$GLOBALS['fh_filters'] = array();
add_filter(
	'fishotel_invoices_search_groups',
	static function () {
		return 'not a list';
	}
);
check_same( 'a filter that returns nonsense leaves nothing dangerous behind', array(), Search::run( $shop, $tanks, $store, $settings, 'tang' ) );
$GLOBALS['fh_filters'] = array();

echo "8. A shop product as a line names it now\n";
$pick = $shop->pick_facts( $tang, $tang_m );
check( 'a variation: exists, its price, tracks stock, 3 left', $pick['exists'] && 45.0 === $pick['price'] && true === $pick['managed'] && 3 === $pick['stock'] && false === $pick['backorders'] && true === $pick['in_stock'] );
$pick = $shop->pick_facts( $goby );
check( 'a simple product that does not track stock', $pick['exists'] && false === $pick['managed'] && null === $pick['stock'] && 12.5 === $pick['price'] );
$pick = $shop->pick_facts( 987654 );
check( 'a product that is gone', false === $pick['exists'] && false === $pick['managed'] && null === $pick['price'] );
check_same( 'price_for(): the variation of a size, a simple product, a size nobody sells, a variable product without a size', array( 45.0, 12.5, null, null ), array( $shop->price_for( $tang, 'medium' )['price'], $shop->price_for( $goby )['price'], $shop->price_for( $tang, 'jumbo' )['price'], $shop->price_for( $tang )['price'] ) );
check_same( 'a private variation of the same size is not the one whose price is taken first', $tang_m, $shop->price_for( $tang, 'medium' )['variation_id'] );

echo "9. Customers\n";
$found = $shop->search_customers( 'person' );
check( 'a site customer found by name or e-mail: the billing name, the billing e-mail first, both e-mails', 1 === count( $found ) && $world['customer'] === $found[0]['id'] && 'Test Person' === $found[0]['name'] && 'billing@example.test' === $found[0]['email'] && array( 'billing@example.test', 'person@example.test' ) === $found[0]['emails'] && 'testperson' === $found[0]['login'] );
check( 'one letter searches nothing; nobody found: an empty list', array() === $shop->search_customers( 'p' ) && array() === $shop->search_customers( 'nobody-at-all' ) );
check( 'a user without billing details falls back to the display name', 'Shop Keeper' === $shop->customer( $world['keeper'] )['name'] && null === $shop->customer( 987654 ) );

echo "10. A title WordPress stored with entities\n";
fh_wc_product( array( 'name' => 'Okapi &amp; Stripe Test Goby &lt;2&quot;', 'regular_price' => '15', 'category_ids' => array( $world['cat_fish'] ) ) );
$picked = group( Search::run( $shop, $tanks, $store, $settings, 'okapi' ), 'products' )['items'];
check_same( 'the picker hands on the text the title stands for, not the stored entities', array( 'Okapi & Stripe Test Goby <2"' ), array_column( $picked, 'name' ) );
$html = Lines::description_html( Lines::normalize( array( array( 'name' => $picked[0]['name'], 'unit_price' => '15' ) ) ) );
check( 'so the customer reads "&" in the description, not "&amp;"', false !== strpos( $html, 'Okapi &amp; Stripe Test Goby &lt;2" x1' ) && false === strpos( $html, '&amp;amp;' ), $html );
check_same( 'Shop::plain(): entities decoded, trimmed; plain text untouched', array( 'A & B', "it's", 'Plain Test Tang' ), array( Shop::plain( ' A &amp; B ' ), Shop::plain( 'it&#039;s' ), Shop::plain( 'Plain Test Tang' ) ) );

fh_finish();
