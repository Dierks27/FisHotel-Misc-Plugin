<?php
/**
 * The fish search and "Fish with no product yet", together.
 *
 * Two features meet in the fish search: the fish of the fish orders are
 * found (Order_Fish), and a one-letter slip, a product in a deeper fish
 * category and a product outside the fish categories are answered
 * (Species::near(), Store::fish_term_ids(), Store::search_hint()). This
 * test holds what neither feature's own test can see:
 *
 *   - how the answers rank together: what answers letter for letter
 *     first (the lists, then the order-line fish, as before), every
 *     similar spelling under it, each fish once;
 *   - that the search and the list "Fish with no product yet" go by the
 *     same fish: every fish on the list is found, a group whose product
 *     is in the trash or deleted is an unlisted fish in both;
 *   - that a fish linked to a product, or given a draft, from the list
 *     is then found as that product, once — and that each write the
 *     link makes drops the search's kept list on the real Store;
 *   - that the real search reads the deeper categories and leaves the
 *     trash out, in the product query and in the two order-fish reads.
 *
 * Every fish, product, supplier and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/search-together-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\No_Product;
use FisHotel\Misc\Sections\Clean_Room\Order_Fish;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = '2026-11-20';
$settings = Settings::get();

$GLOBALS['fh_current_user'] = 7;
$GLOBALS['fh_user_caps']    = array(
	'manage_woocommerce' => array( 7 ),
	'edit_products'      => array( 7 ),
);

/** The shop a test's "Start a draft product" makes its products in. */
class Fh_Together_Shop {
	public $made = array();
	private $store;

	public function __construct( Fake_Store $store ) {
		$this->store = $store;
	}

	public function create( array $plan ) {
		$id                                   = 9000 + count( $this->made ) + 1;
		$this->made[ $id ]                    = $plan;
		$this->store->products[ $id ]         = $plan['name'];
		$this->store->product_statuses[ $id ] = 'draft';
		$this->store->product_meta[ $id ]     = array( No_Product::DRAFT_META => $plan['marker'] );
		return array( 'ok' => true, 'id' => $id, 'error' => '' );
	}

	public function edit_url( $id ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}

function fh_tg_row( $species, array $extra = array() ) {
	return array_merge( array( 'id' => '0', 'species' => $species, 'qty_ordered' => '6', 'unit_price' => '5', 'per_box' => '12' ), $extra );
}

function fh_tg_save( $store, array $lines, array $header ) {
	global $today;
	$saved = Admin_Workbook::save_order( $store, array_merge( array( 'ref' => '', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'status' => 'ordered', 'cost_mode' => 'box', 'boxes' => 1, 'domestic_flight' => '60', 'import_fees' => '60', 'lines' => $lines ), $header ), 0, $today );
	if ( ! empty( $saved['error'] ) ) {
		fwrite( STDERR, 'fixture order: ' . $saved['error'] . "\n" );
		exit( 1 );
	}
	return $saved;
}

/** A group put straight into a tank, as "Add fish already here" leaves one. */
function fh_tg_group( $store, $tank_number, $species, $qty, $product_id = 0 ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$id   = $store->create_stay( array( 'species' => $species, 'checkin_date' => $today, 'tank_id' => (int) $tank['id'], 'source' => 'manual', 'product_id' => $product_id ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Store::site_noon_utc( $today ) ), $today, $settings );
	return $id;
}

/** The list "Fish with no product yet" as its page gathers it (what matters now). */
function fh_tg_list( $store ) {
	global $today, $settings;
	$orders = Admin_Workbook::resolved_orders( $store, array( 'open' => true ) );
	$stays  = $store->open_stays( array( 'limit' => 2000 ), $today, $settings );
	return Admin_No_Product::gathered( $store, $orders, $stays, array( 'sizes' => Sizes::standard( $store, $settings )['sizes'] ) );
}

function fh_tg_keys( array $items ) {
	return array_map( array( Species::class, 'key' ), array_column( $items, 'name' ) );
}

/** The items of a search that are a fish order's or a tank's own (no product, no Species row). */
function fh_tg_unlisted( array $items ) {
	return array_values(
		array_filter(
			$items,
			static function ( $item ) {
				return isset( $item['where'] );
			}
		)
	);
}

/* ------------------------------------------------------------------ */
echo "1. Ranked together: letter for letter first, a similar spelling under it, each fish once\n";

$product = static function ( $id, $name, $match = 'title', array $more = array() ) {
	return array_merge( array( 'product_id' => $id, 'name' => $name, 'common' => '', 'sci' => '', 'match' => $match, 'alias' => '' ), $more );
};
$fish    = static function ( $name, $from = 'order', $ref = '2026-9', $tank = null, $sci = '' ) {
	return array( 'name' => $name, 'sci' => $sci, 'from' => $from, 'ref' => 'order' === $from ? $ref : '', 'tank' => $tank );
};

$offered = array(
	$fish( 'Filimented Test Flasher' ),                      // Typed on the order with a slip of its own.
	$fish( 'Hand Test Puffer', 'tank', '', 512 ),
	$fish( 'Orange Test Dart', 'order', '2026-8', null, 'Testus aurantius' ),
);

$both = Order_Fish::place( array( $product( 10, 'Filamented Test Flasher Wrasse' ), $product( 11, 'Carpenter Test Flasher Wrasse' ) ), $offered, 'flasher', 8 );
check_same( 'the lists and a fish of a fish order both answer letter for letter: the products first, the order-line fish under them — as before', array( 'Filamented Test Flasher Wrasse', 'Carpenter Test Flasher Wrasse', 'Filimented Test Flasher' ), array_column( $both, 'name' ) );

$near_product = $product( 10, 'Filamented Test Flasher Wrasse', 'near' );
$mixed        = Order_Fish::place( array( $near_product ), $offered, 'filimented', 8 );
check_same( 'the lists only have a similar spelling, a fish of a fish order is spelled as typed: the order-line fish first, the similar spelling UNDER it', array( array( 'Filimented Test Flasher', 'order' ), array( 'Filamented Test Flasher Wrasse', 'near' ) ), array( array( $mixed[0]['name'], $mixed[0]['match'] ), array( $mixed[1]['name'], $mixed[1]['match'] ) ) );
check( '… and nothing else is tried nearly: one letter-for-letter answer is enough', 2 === count( $mixed ) );

$by_sci = Order_Fish::place( array( $product( 10, 'Aurantius Test Wrase', 'near' ) ), $offered, 'aurantius', 8 );
check_same( '… also when the order-line fish answers by its scientific name only (the weakest letter-for-letter answer is still above a similar spelling)', array( 'Orange Test Dart', 'Aurantius Test Wrase' ), array_column( $by_sci, 'name' ) );

$nearly = Order_Fish::place( array(), $offered, 'ornge test dart', 8 );
check( 'nothing answers letter for letter anywhere: the order-line fish is found by its similar spelling — marked so, still saying where it comes from and carrying its fish order', 1 === count( $nearly ) && 'Orange Test Dart' === $nearly[0]['name'] && 'near' === $nearly[0]['match'] && 'on fish order 2026-8' === $nearly[0]['where'] && '2026-8' === $nearly[0]['ref'] && null === $nearly[0]['product_id'] && 'Testus aurantius' === $nearly[0]['sci'] );
$tank_near = Order_Fish::place( array(), $offered, 'hand test pufer', 8 );
check( '… a fish alive in a tank too ("in tank 512"), with no fish order', 1 === count( $tank_near ) && 'in tank 512' === $tank_near[0]['where'] && '' === $tank_near[0]['ref'] && 'near' === $tank_near[0]['match'] );

$all_near = Order_Fish::place( array( $near_product ), array( $fish( 'Filamented Test Goby' ), $fish( 'Zebra Test Eel' ) ), 'filimented', 8 );
check_same( 'similar spellings on both sides: the lists\' first (products and Species rows first, as letter for letter), then the order-line fish\'s', array( array( 'Filamented Test Flasher Wrasse', false ), array( 'Filamented Test Goby', true ) ), array( array( $all_near[0]['name'], isset( $all_near[0]['where'] ) ), array( $all_near[1]['name'], isset( $all_near[1]['where'] ) ) ) );
check( '… both marked as similar spellings', array( 'near', 'near' ) === array_column( $all_near, 'match' ) );

$exact_list = Order_Fish::place( array( $product( 12, 'Orange Test Clown' ) ), $offered, 'orange test clown', 8 );
check_same( 'the lists answer letter for letter: no order-line fish is offered for a similar spelling', array( 'Orange Test Clown' ), array_column( $exact_list, 'name' ) );

$learned = Order_Fish::place( array( $product( null, 'Orange Test Dart', 'near', array( 'alias' => '' ) ) ), $offered, 'ornge test dart', 8 );
check( 'each fish once: a name the lists already answered with — a learned name\'s fish, found nearly — is not offered a second time as the order line\'s', 1 === count( $learned ) && ! isset( $learned[0]['where'] ) );
$twins = Order_Fish::place( array( $product( 25, 'Twin Test Chromis', 'near' ), $product( 26, 'Twin Test Chromis', 'near' ) ), array(), 'twin tset chromis', 8 );
check( '… what the lists answered is never dropped: two products of one name are both there', array( 25, 26 ) === array_column( $twins, 'product_id' ) );
check( 'the row cap holds for the whole list', 1 === count( Order_Fish::place( array( $near_product ), $offered, 'filimented', 1 ) ) && 'Filimented Test Flasher' === Order_Fish::place( array( $near_product ), $offered, 'filimented', 1 )[0]['name'] );

check( 'the similar-spelling rule is the lists\' own (Species::near()): by the scientific name too; a word under four letters as it stands; a far-off word finds nothing', array( 'Orange Test Dart' ) === array_column( Order_Fish::nearly( $offered, 'testus aurantios' ), 'name' ) && array() === Order_Fish::nearly( $offered, 'hnd' ) && array() === Order_Fish::nearly( $offered, 'zebra' ) && array() === Order_Fish::nearly( $offered, '' ) && array() === Order_Fish::nearly( array(), 'orange' ) );
check( 'whether the LISTS answered letter for letter: a product, a Species row or a learned name did; a similar spelling, a fish of a fish order or of a tank did not', Order_Fish::lists_answered( array( $product( 10, 'x' ) ) ) && Order_Fish::lists_answered( array( $nearly[0], $product( null, 'x', 'species' ) ) ) && Order_Fish::lists_answered( array( $product( null, 'x', 'alias' ) ) ) && ! Order_Fish::lists_answered( array( $near_product, $nearly[0] ) ) && ! Order_Fish::lists_answered( $both ? array( $both[2] ) : array() ) && ! Order_Fish::lists_answered( array( Order_Fish::suggestion( $offered[1] ) ) ) && ! Order_Fish::lists_answered( array() ) );

/* ------------------------------------------------------------------ */
echo "2. The search boxes, end to end\n";

$store = new Fake_Store( array( 10 => 'Filamented Test Flasher Wrasse', 11 => 'Carpenter Test Flasher Wrasse', 45 => 'Nocat Test Blenny' ) );
$store->not_fish = array( 45 );
fh_tg_save(
	$store,
	array(
		fh_tg_row( 'Filimented Test Flasher' ),
		fh_tg_row( 'Orange Test Dart', array( 'sci_name' => 'Testus aurantius' ) ),
		fh_tg_row( 'Nocat Test Blenny' ),
	),
	array( 'order_date' => '2026-11-01', 'ref' => '2026-9' )
);

$found = $store->fish_search( 'filimented', 8 );
check_same( 'a slip typed in the box that is also the spelling on a fish order: that fish first, the product of the similar spelling under it', array( array( 'Filimented Test Flasher', 'order', 'on fish order 2026-9' ), array( 'Filamented Test Flasher Wrasse', 'near', null ) ), array( array( $found[0]['name'], $found[0]['match'], $found[0]['where'] ?? null ), array( $found[1]['name'], $found[1]['match'], $found[1]['where'] ?? null ) ) );
check( '… the product keeps its id: picking it is picking the product', 10 === $found[1]['product_id'] && 2 === count( $found ) );
$dart = $store->fish_search( 'ornge dart', 8 );
check( 'a slip that only a fish order\'s fish is near to: found, marked as a similar spelling, with its fish order', 1 === count( $dart ) && 'Orange Test Dart' === $dart[0]['name'] && 'near' === $dart[0]['match'] && 'on fish order 2026-9' === $dart[0]['where'] );
check_same( 'a word every flasher has: the products first, then the order-line fish — none marked as a similar spelling', array( 'title', 'title', 'order' ), array_column( $store->fish_search( 'flasher', 8 ), 'match' ) );
check( 'the product link\'s box (species_suggestions()) and the Requests page still name no fish of a fish order — letter for letter or nearly', array() === $store->species_suggestions( 'ornge dart', 8 ) && array() === $store->species_suggestions( 'orange test dart', 8 ) );

$nocat = $store->fish_search( 'nocat test', 8 );
$hint  = $store->search_hint( 'nocat test', $nocat );
check( 'a fish typed on a fish order whose product IS in the shop, outside the fish categories: the fish is offered as the order line\'s — and the box still says the shop has the product, and what to do', 1 === count( $nocat ) && 'order' === $nocat[0]['match'] && false !== strpos( $hint, '"Nocat Test Blenny" (#45)' ) && false !== strpos( $hint, 'Tick one on the product' ) );
check( '… nothing is said when a product or a Species row answered, with or without order-line fish beside it', '' === $store->search_hint( 'flasher', $store->fish_search( 'flasher', 8 ) ) && '' === $store->search_hint( 'orange', $store->fish_search( 'orange', 8 ) ) );
$real_source = (string) file_get_contents( dirname( __DIR__ ) . '/class-store.php' );
check( 'the real Store asks the same question (Order_Fish::lists_answered()), and its search is the lists\' answer placed with the order-line fish', 1 === preg_match( '/function search_hint\( \$term, array \$items \) \{\s*if \( Order_Fish::lists_answered\( \$items \) \) \{\s*return \'\';/', $real_source ) && false !== strpos( $real_source, 'return Order_Fish::place( $this->species_suggestions( $term, $limit ), $this->order_fish(), $term, $limit );' ) );

/* ------------------------------------------------------------------ */
echo "3. The search and \"Fish with no product yet\" go by the same fish\n";

/*
 * Products: 21 Lined Test Wrasse, 22 Powder Test Tang, 25 + 26 two Twin
 * Test Chromis, 27 Orange Dartfish (Test), 28 Binned Test Puffer (in the
 * trash), 40 Nocat Test Blenny (in no fish category). Species list:
 * 1 Lined Test Wrasse → 21, 2 Blue Test Tang (no product).
 */
$world = new Fake_Store( array( 21 => 'Lined Test Wrasse', 22 => 'Powder Test Tang', 25 => 'Twin Test Chromis', 26 => 'Twin Test Chromis', 27 => 'Orange Dartfish (Test)', 28 => 'Binned Test Puffer', 40 => 'Nocat Test Blenny' ) );
$world->product_statuses = array( 28 => 'trash' );
$world->not_fish         = array( 40 );
$world->save_species( array( 'name' => 'Lined Test Wrasse', 'sci_name' => 'Testus lineatus', 'product_id' => 21 ) ); // 1
$world->save_species( array( 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus' ) );                       // 2

$a = fh_tg_save(
	$world,
	array(
		fh_tg_row( 'Lined Test Wrasse', array( 'species_id' => '1' ) ),
		fh_tg_row( 'Blue Test Tang', array( 'species_id' => '2', 'qty_received' => '6' ) ),
		fh_tg_row( 'Powder Test Tang', array( 'qty_received' => '2' ) ),
		fh_tg_row( 'Twin Test Chromis' ),
		fh_tg_row( 'Orange Test Dart', array( 'sci_name' => 'Testus aurantius', 'qty_received' => '3', 'worth' => '45' ) ),
		fh_tg_row( 'orange  test DART', array( 'qty_received' => '1' ) ),
		fh_tg_row( 'Nocat Test Blenny' ),
		fh_tg_row( 'small wrasse', array( 'needs_id' => '1', 'qty_received' => '2' ) ),
	),
	array( 'order_date' => '2026-10-01', 'received_date' => '2026-10-05', 'status' => 'received', 'ref' => '2026-9' )
);
Orders::check_in( $world, $world->order( $a['order_id'] ), '2026-10-05', $settings, array( 'actor_id' => 7 ) );
fh_tg_save( $world, array( fh_tg_row( 'Old Test Angel', array( 'qty_received' => '6' ) ) ), array( 'order_date' => '2025-03-01', 'received_date' => '2025-03-04', 'status' => 'closed', 'ref' => '2025-3' ) );
fh_tg_group( $world, 121, 'Grey Test Dartfish', 5 );
fh_tg_group( $world, 122, 'Binned Test Puffer', 2, 28 );   // Linked to a product that is in the trash now.
fh_tg_group( $world, 123, 'Deleted Test Basslet', 1, 99 ); // Linked to a product that was deleted.

$list     = fh_tg_list( $world );
$rows     = $list['fish'];
$by_key   = array();
foreach ( $rows as $row ) {
	$by_key[ $row['key'] ] = $row;
}
$unlisted = $world->order_fish();

$missing = array();
foreach ( $unlisted as $one ) {
	if ( ! isset( $by_key[ 'n:' . Species::key( $one['name'] ) ] ) ) {
		$missing[] = $one['name'];
	}
}
check_same( 'every fish the search offers as a fish order\'s or a tank\'s own is a row of the list, under the same key — the name, case and spacing aside', array(), $missing );
check_same( '… the two spellings of one fish are ONE suggestion and ONE row', array( 1, 1 ), array( count( array_keys( fh_tg_keys( $unlisted ), 'orange test dart', true ) ), count( array_filter( array_keys( $by_key ), static function ( $key ) { return 'n:orange test dart' === $key; } ) ) ) );

$unfound = array();
$twice   = array();
foreach ( $rows as $row ) {
	$items = $world->fish_search( $row['name'], 20 );
	$same  = array_values( array_filter( $items, static function ( $item ) use ( $row ) { return Species::key( $item['name'] ) === Species::key( $row['name'] ); } ) );
	if ( ! $same ) {
		$unfound[] = $row['name'];
	}
	if ( count( fh_tg_unlisted( $same ) ) > 1 || ( fh_tg_unlisted( $same ) && count( $same ) > 1 ) ) {
		$twice[] = $row['name'];
	}
}
check_same( 'every fish on the list can be found by its name in the fish search', array(), $unfound );
check_same( '… and none is offered both as a product or Species-list fish AND as an unlisted fish', array(), $twice );
check( 'the list and the search agree fish by fish: the typed fish with no product are in both; a Species-list fish with no product is on the list and found as "list"; a line named like ONE product is in neither; a "??" fish in neither', isset( $by_key['n:orange test dart'], $by_key['n:grey test dartfish'], $by_key['n:nocat test blenny'], $by_key['s:2'] ) && ! isset( $by_key['n:powder test tang'], $by_key['s:1'] ) && array( 'species' ) === array_column( $world->fish_search( 'blue test tang', 8 ), 'match' ) && ! in_array( 'powder test tang', fh_tg_keys( $unlisted ), true ) && array() === $world->fish_search( 'small wrasse', 8 ) && array( 'Old Test Angel' ) !== array_column( $world->fish_search( 'old test angel', 8 ), 'name' ) );

$binned = $world->fish_search( 'binned test', 8 );
check( 'a group whose product is in the trash: the list shows the fish — and the search finds it, as the unlisted fish in its tank, not as the product nobody can pick', isset( $by_key['n:binned test puffer'] ) && 1 === count( $binned ) && 'tank' === $binned[0]['match'] && 'in tank 122' === $binned[0]['where'] && null === $binned[0]['product_id'] );
$deleted = $world->fish_search( 'deleted test', 8 );
check( '… a group whose product was deleted too', isset( $by_key['n:deleted test basslet'] ) && 1 === count( $deleted ) && 'in tank 123' === $deleted[0]['where'] );
$world->product_statuses[28] = 'publish';
$back                        = $world->fish_search( 'binned test', 8 );
check( '… the product out of the trash again: found as the product, once — and off the list', 1 === count( $back ) && 28 === $back[0]['product_id'] && ! isset( $back[0]['where'] ) && null === ( array_column( fh_tg_list( $world )['fish'], null, 'key' )['n:binned test puffer'] ?? null ) );
$world->product_statuses[28] = 'trash';

$draft_line = $world->fish_search( 'twin test', 8 );
check( 'a line named like TWO products is on the list (which one is its product?) and is found as those products, not a third time', isset( $by_key['n:twin test chromis'] ) && array( 25, 26 ) === array_column( $draft_line, 'product_id' ) );

/* ------------------------------------------------------------------ */
echo "4. Linked to a product, or given a draft, from the list: found as that product, once\n";

$before = $world->fish_search( 'orange test dart', 8 );
check( 'before: the fish is an order line\'s own — "use as an unlisted fish" — and the product it will be linked to stands under it only as a similar spelling', 2 === count( $before ) && 'order' === $before[0]['match'] && null === $before[0]['product_id'] && 27 === $before[1]['product_id'] && 'near' === $before[1]['match'] );

$linked = Admin_No_Product::link_request( $world, array( 'key' => 'n:orange test dart', 'name' => 'Orange Test Dart', 'product_id' => '27' ), $today, $settings );
$after  = $world->fish_search( 'orange test dart', 8 );
check( '"Link to a product…": the fish is then found under the name it was typed with AS the product — once, and no longer as an unlisted fish', true === $linked['ok'] && 1 === count( $after ) && 27 === $after[0]['product_id'] && ! isset( $after[0]['where'] ) && array() === fh_tg_unlisted( $after ) );
$by_title = $world->fish_search( 'dartfish (test)', 8 );
check( '… and under the product\'s own title: the same product, once', 1 === count( $by_title ) && 27 === $by_title[0]['product_id'] );
check( '… a slip finds it as the product too, never as an order line\'s fish', array( 27 ) === array_column( $world->fish_search( 'ornge test dart', 8 ), 'product_id' ) && array() === fh_tg_unlisted( $world->fish_search( 'ornge test dart', 8 ) ) );
check( '… it is off the list, and no order line or group of it is offered any more', ! isset( array_column( fh_tg_list( $world )['fish'], null, 'key' )['n:orange test dart'] ) && ! in_array( 'orange test dart', fh_tg_keys( $world->order_fish() ), true ) );

$shop  = new Fh_Together_Shop( $world );
$nonce = wp_create_nonce( Admin_No_Product::NONCE_DRAFT );
$grey  = $world->fish_search( 'grey test', 8 );
$made  = Admin_No_Product::draft_request( $world, $shop, array( 'nonce' => $nonce, 'key' => 'n:grey test dartfish', 'name' => 'Grey Test Dartfish' ), $today, $settings );
$drawn = $world->fish_search( 'grey test', 8 );
check( '"Start a draft product": before, the fish in the tank is an unlisted fish; after, the search finds the DRAFT product — once', 1 === count( $grey ) && 'tank' === $grey[0]['match'] && true === $made['ok'] && 'create' === $made['act'] && 1 === count( $drawn ) && (int) $made['product_id'] === $drawn[0]['product_id'] && ! isset( $drawn[0]['where'] ) );
check( '… and the list no longer has it', ! isset( array_column( fh_tg_list( $world )['fish'], null, 'key' )['n:grey test dartfish'] ) );

$twin = Admin_No_Product::link_request( $world, array( 'key' => 'n:nocat test blenny', 'name' => 'Nocat Test Blenny', 'product_id' => '40' ), $today, $settings );
$seen = $world->fish_search( 'nocat test', 8 );
check( '"Link to it" — a product outside the fish categories: the fish is found through its Species-list row with that product, once, and the box no longer has to say the shop has it', true === $twin['ok'] && 1 === count( $seen ) && 40 === $seen[0]['product_id'] && ! isset( $seen[0]['where'] ) && '' === $world->search_hint( 'nocat test', $seen ) );

/* ------------------------------------------------------------------ */
echo "5. The real Store: the link's writes drop the kept list; deeper categories and the trash in the reads\n";

/** A database that has the tables, answers from canned rows and records what it is asked. */
$GLOBALS['wpdb'] = new class() {
	public $prefix    = 'wp_';
	public $users     = 'wp_users';
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public $terms     = 'wp_terms';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';
	public $insert_id = 0;
	public $queries   = array();
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
	}
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
	public function get_var( $query ) {
		return 1 === preg_match( '/^SHOW TABLES LIKE (\S+)$/', (string) $query, $m ) ? $m[1] : 0;
	}
	public function get_col( $query ) {
		$this->queries[] = (string) $query;
		return false !== strpos( (string) $query, 'wp_terms' ) ? array( 9 ) : array(); // "Quarantined Fish".
	}
	public function get_row( $query, $output = null ) {
		$this->queries[] = (string) $query;
		return null;
	}
	public function get_results( $query, $output = null ) {
		$query           = (string) $query;
		$this->queries[] = $query;
		if ( 0 === strpos( $query, 'SELECT term_id, parent FROM wp_term_taxonomy' ) ) {
			// Quarantined Fish (9) → Wrasses (20) → Flasher Wrasses (31); Dry Goods (49) → Nets (50).
			return array( array( 'term_id' => 20, 'parent' => 9 ), array( 'term_id' => 31, 'parent' => 20 ), array( 'term_id' => 50, 'parent' => 49 ) );
		}
		if ( 0 === strpos( $query, 'SELECT l.id, l.species' ) ) {
			return array(
				array( 'id' => 5, 'species' => 'Golden Sample Tang', 'sci_name' => 'Samplus aureus', 'needs_id' => 0, 'species_id' => null, 'stay_product_id' => null, 'order_ref' => '2026-9', 'order_status' => 'ordered' ),
				array( 'id' => 6, 'species' => 'Deep Sample Flasher', 'sci_name' => '', 'needs_id' => 0, 'species_id' => null, 'stay_product_id' => null, 'order_ref' => '2026-9', 'order_status' => 'ordered' ),
			);
		}
		if ( 0 === strpos( $query, 'SELECT s.id, s.species' ) ) {
			return array( array( 'id' => 3, 'species' => 'Binned Sample Puffer', 'needs_id' => 0, 'product_id' => null, 'qty_live' => 1, 'status' => 'in_qt', 'tank_number' => 512, 'sci_name' => null, 'line_species_id' => null ) );
		}
		if ( false !== strpos( $query, 'FROM wp_posts p' ) && false !== strpos( $query, 'wp_term_relationships' ) && false === strpos( $query, 'NOT IN (SELECT' ) ) {
			// The fish products: one, ticked only in "Flasher Wrasses" — two levels under the fish category.
			return false !== strpos( $query, ' LIKE ' ) && false === strpos( $query, '%sample%' ) ? array() : array( array( 'ID' => 12, 'post_title' => 'Deep Sample Flasher', 'common' => '', 'sci' => '' ) );
		}
		return array();
	}
	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		return 1;
	}
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		return 1;
	}
	public function delete( $table, $where, $where_format = null ) {
		return 1;
	}
};
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' ); // As WordPress has it: the real Store's reads name it.
$db   = $GLOBALS['wpdb'];
$flag = new ReflectionProperty( Store::class, 'tables_exist' ); // Asked before, without a database: ask again.
$flag->setAccessible( true );
$flag->setValue( null, null );
$real = new Store();
$kept = static function () {
	return false !== get_transient( Store::ORDER_FISH_TRANSIENT );
};
$fill = static function () use ( $real ) {
	Store::clear_order_fish_cache();
	$real->order_fish();
	return false !== get_transient( Store::ORDER_FISH_TRANSIENT );
};

$found = $real->fish_search( 'sample', 8 );
check_same( 'the real search: the product first, then the order line\'s and the tank\'s own fish — a line named like a product in a DEEPER fish category is that product, not an unlisted fish', array( array( 'Deep Sample Flasher', 12 ), array( 'Binned Sample Puffer', null ), array( 'Golden Sample Tang', null ) ), array_map( static function ( $item ) { return array( $item['name'], $item['product_id'] ); }, $found ) );
$products = array_values( array_filter( $db->queries, static function ( $q ) { return false !== strpos( $q, 'FROM wp_posts p' ) && false !== strpos( $q, 'wp_term_relationships' ); } ) );
$deep     = true;
foreach ( $products as $query ) {
	$deep = $deep && false !== strpos( $query, 'tt.term_id IN (9,20,31)' ) && false === strpos( $query, '50' ) && false !== strpos( $query, "p.post_status IN ('publish','private','draft','pending','future')" );
}
check( '… every product read of the search — the matches and the names an order line is held against — asks the fish categories with EVERY category under them, and leaves the trash out', count( $products ) >= 2 && $deep );
$reads = array_values( array_filter( $db->queries, static function ( $q ) { return 0 === strpos( $q, 'SELECT l.id, l.species' ) || 0 === strpos( $q, 'SELECT s.id, s.species' ); } ) );
check( 'the two order-fish reads count a group\'s product only while it exists — the rule of "Fish with no product yet": the trash and a deleted product are none', 2 === count( $reads ) && false !== strpos( $reads[0], "sp.ID AS stay_product_id" ) && false !== strpos( $reads[0], "LEFT JOIN wp_posts sp ON sp.ID = st.product_id AND sp.post_type = 'product' AND sp.post_status NOT IN ('trash','auto-draft')" ) && false !== strpos( $reads[0], 'AND sp.ID IS NULL' ) && false !== strpos( $reads[1], 'p.ID AS product_id' ) && false !== strpos( $reads[1], "LEFT JOIN wp_posts p ON p.ID = s.product_id AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')" ) && false !== strpos( $reads[1], 'AND p.ID IS NULL' ) && false === strpos( $reads[0], 'st.product_id IS NULL' ) && false === strpos( $reads[1], 's.product_id IS NULL' ) );
check_same( '… and that rule\'s own words name the statuses', array( 'trash', 'auto-draft' ), No_Product::GONE );

$near = $real->fish_search( 'goldn tang', 8 );
check( 'the real search finds a fish of a fish order by a similar spelling when nothing answers letter for letter', 1 === count( $near ) && 'Golden Sample Tang' === $near[0]['name'] && 'near' === $near[0]['match'] && 'on fish order 2026-9' === $near[0]['where'] );

$writes = array(
	'the Species row is linked to the product (Store::link_species_product())' => static function () use ( $real ) { $real->link_species_product( 4, 27 ); },
	'a new Species row is made for the fish (Store::save_species())'            => static function () use ( $real ) { $real->save_species( Species::normalize( array( 'name' => 'Golden Sample Tang', 'product_id' => 27 ) ) ); },
	'an order line gets the row\'s id (Store::update_line_fields())'             => static function () use ( $real ) { $real->update_line_fields( 5, array( 'species_id' => 4 ) ); },
	'a group in a tank gets the product (Store::update_stay_fields())'          => static function () use ( $real ) { $real->update_stay_fields( 3, array( 'product_id' => 27 ) ); },
	'the Species tab moves a group to no product (Store::update_stay_fields())' => static function () use ( $real ) { $real->update_stay_fields( 3, array( 'product_id' => 0 ) ); },
);
foreach ( $writes as $label => $write ) {
	$was = $fill();
	$write();
	check( 'the kept list is dropped when ' . $label, $was && ! $kept() );
}
$np_source = (string) file_get_contents( dirname( __DIR__ ) . '/class-no-product.php' );
check( '… and those are the writes "Link to a product…" and "Start a draft product" make: nothing of the link goes around the Store', false !== strpos( $np_source, '$store->link_species_product( $species_id, $product_id )' ) && false !== strpos( $np_source, '$store->save_species(' ) && false !== strpos( $np_source, "\$store->update_line_fields( \$line_id, array( 'species_id' => \$species_id ) )" ) && false !== strpos( $np_source, 'Identify::stay(' ) && false === strpos( $np_source, '$wpdb' ) );
$room_source = (string) file_get_contents( dirname( __DIR__ ) . '/class-clean-room.php' );
check( 'a product saved in the shop — a draft made, a product moved to the trash or back — drops both kept lists', false !== strpos( $room_source, "add_action( 'save_post_product', array( Store::class, 'clear_species_cache' ), 10, 0 );" ) && 1 === preg_match( '/function clear_species_cache\(\) \{.*?self::clear_order_fish_cache\(\);\s*\}/s', $real_source ) );
/* ------------------------------------------------------------------ */
echo "6. A fish picked on the phone as \"on fish order …\": the list knows its fish order\n";

/** A stand-in that notes which orders the list asked for. */
class Fh_Together_Spy extends Fake_Store {
	public $asked = array();

	public function orders( array $filters = array() ) {
		$this->asked[] = $filters;
		return parent::orders( $filters );
	}
}

$sea = new Fh_Together_Spy( array( 21 => 'Lined Test Wrasse' ) );
$ten = fh_tg_save(
	$sea,
	array(
		fh_tg_row( 'Pale Sample Tang', array( 'sci_name' => 'Samplus pallidus' ) ),
		fh_tg_row( 'Lined Test Wrasse' ),
	),
	array( 'order_date' => '2026-11-10', 'ref' => '2026-10' )
);
$eleven = fh_tg_save(
	$sea,
	array(
		fh_tg_row( 'Filimented Test Goby', array( 'sci_name' => 'Testus filum', 'qty_received' => '4' ) ),
		fh_tg_row( 'Other Test Blenny', array( 'qty_received' => '6' ) ),
	),
	array( 'order_date' => '2026-11-12', 'received_date' => '2026-11-15', 'status' => 'received', 'ref' => '2026-11' )
);
$phone = static function ( $tank_number, $term, $qty ) use ( $sea, $today, $settings ) {
	$picked = $sea->fish_search( $term, 8 )[0];
	$done   = Front_Desk::perform(
		'arrival',
		array( 'store' => $sea, 'settings' => $settings, 'today' => $today, 'tank' => $sea->tank_by_number( $tank_number ), 'base' => array( 'actor_id' => 7, 'source' => 'qr' ) ),
		array( 'species' => $picked['name'], 'product_id' => 0, 'qty' => $qty, 'order_ref' => (string) ( $picked['ref'] ?? '' ) )
	);
	return array( $picked, $done );
};
$page  = static function ( array $request ) use ( $sea, $today, $settings ) {
	$data = Admin_No_Product::data( $sea, $request, $today, $settings );
	return array_column( $data['rows'], null, 'key' );
};
$count = static function ( $order_id ) use ( $sea ) {
	foreach ( Admin_No_Product::mark_orders( $sea, Admin_No_Product::open_orders( $sea ) ) as $order ) {
		if ( (int) $order['id'] === (int) $order_id ) {
			return (int) $order['no_product'];
		}
	}
	return null;
};

list( $pale, $pale_done ) = $phone( 513, 'pale sample', 1 );
list( $goby, $goby_done ) = $phone( 522, 'filimented', 2 );
$pale_stay                = $sea->open_stays( array( 'species' => 'Pale Sample Tang', 'limit' => 5 ), $today, $settings )[0];
check( 'the phone\'s Arrival of a fish picked as "on fish order 2026-10": the group carries that ref, and stands for no order line', empty( $pale_done['error'] ) && empty( $goby_done['error'] ) && 'on fish order 2026-10' === $pale['where'] && '2026-10' === $pale_stay['order_ref'] && null === $pale_stay['batch_order_id'] && null === $pale_stay['order_line_id'] );

$sea->asked = array();
$full       = $page( array() );
$row        = $full['n:pale sample tang'] ?? array();
check( 'the full list: that fish ONCE, with its fish order and its tank', isset( $full['n:pale sample tang'] ) && array( (int) $ten['order_id'] ) === array_column( $row['orders'], 'id' ) && array( 513 ) === $row['tanks'] && 1 === $row['alive'] && true === $row['in_tank'] && 1 === count( $row['stay_ids'] ) && 1 === count( $row['line_ids'] ) );
check( '… no order is read for it while its fish order is at hand: the same reads as before', array() === array_values( array_filter( $sea->asked, static function ( $filters ) { return isset( $filters['refs'] ); } ) ) );

$narrow = $page( array( 'order' => (string) $ten['order_id'] ) );
$row    = $narrow['n:pale sample tang'] ?? array();
check( 'the list narrowed to that fish order ("… without a product →"): the same fish IN ITS TANK — it was "not in a tank" there', isset( $narrow['n:pale sample tang'] ) && true === $row['in_tank'] && array( 513 ) === $row['tanks'] && 1 === $row['alive'] && array( (int) $ten['order_id'] ) === array_column( $row['orders'], 'id' ) );
check( '… and no group of another fish order: the goby of 2026-11 is not there', ! isset( $narrow['n:filimented test goby'] ) && isset( $page( array( 'order' => (string) $eleven['order_id'] ) )['n:filimented test goby'] ) && array( 522 ) === $page( array( 'order' => (string) $eleven['order_id'] ) )['n:filimented test goby']['tanks'] );

// A ref typed on a group whose fish that order never named: the order's count counted its lines.
$stray = $sea->create_stay( array( 'species' => 'Stray Test Chromis', 'checkin_date' => $today, 'tank_id' => (int) $sea->tank_by_number( 512 )['id'], 'source' => 'manual', 'order_ref' => ' 2026-10 ' ) );
$sea->record_event( $stray, array( 'type' => 'arrival', 'qty' => 2, 'to_tank_id' => (int) $sea->tank_by_number( 512 )['id'], 'event_at' => Store::site_noon_utc( $today ) ), $today, $settings );
$full   = $page( array() );
$narrow = $page( array( 'order' => (string) $ten['order_id'] ) );
check( 'a group that names the fish order by hand, of a fish the order does not have: on the full list with that order — and the narrowed list still has exactly the fish the order\'s count counted', isset( $full['n:stray test chromis'] ) && array( (int) $ten['order_id'] ) === array_column( $full['n:stray test chromis']['orders'], 'id' ) && ! isset( $narrow['n:stray test chromis'] ) && count( $narrow ) === $count( $ten['order_id'] ) && 1 === count( $narrow ) );

$before = $page( array() )['n:filimented test goby'] ?? array();
$sea->update_order_fields( (int) $eleven['order_id'], array( 'status' => 'closed' ) );
$sea->asked = array();
$after      = $page( array() )['n:filimented test goby'] ?? array();
$read       = array_values( array_filter( $sea->asked, static function ( $filters ) { return isset( $filters['refs'] ); } ) );
check( 'while its fish order is open the goby\'s row has the order, its landed cost per fish and its scientific name', $before && array( '2026-11' ) === array_column( $before['orders'], 'ref' ) && null !== $before['cost'] && $before['cost'] > 0 && '2026-11' === $before['cost_ref'] && 'Testus filum' === $before['sci_name'] );
check( 'the fish order CLOSED, the group still alive: the row still names fish order 2026-11 — it showed none, while the Stays tab said 2026-11', $after && array( array( (int) $eleven['order_id'], '2026-11', 'closed' ) ) === array_map( 'array_values', $after['orders'] ) && array( 522 ) === $after['tanks'] && 2 === $after['alive'] );
check_same( '… with the landed cost and the target price that order\'s line of the fish has, and its scientific name — not "landed cost not known yet"', array( $before['cost'], $before['target'], '2026-11', 'Testus filum' ), array( $after['cost'], $after['target'], $after['cost_ref'], $after['sci_name'] ) );
check( '… the closed order\'s lines make no row of their own: the blenny that only that order names is not on the list', ! isset( $page( array() )['n:other test blenny'] ) && array() === $after['line_ids'] );
check_same( '… read in ONE more read of orders, by the refs the groups name and no open order has', array( array( 'refs' => array( '2026-11' ) ) ), $read );

check_same( 'which refs are asked for (pure): a group by hand whose ref no order at hand has — each once, as spelled, the case and the spaces aside; never a group that has its line\'s order, or no ref', array( '2025-4', 'T-9' ), No_Product::missing_refs( array( array( 'order_ref' => ' 2025-4 ' ), array( 'order_ref' => '2025-4' ), array( 'order_ref' => 't-9', 'batch_order_id' => 6 ), array( 'order_ref' => 'T-9' ), array( 'order_ref' => '2026-10' ), array( 'order_ref' => null ), array( 'order_ref' => '' ), 'x' ), array( array( 'ref' => '2026-10' ) ) ) );
check( '… capped', 1 === count( No_Product::missing_refs( array( array( 'order_ref' => 'a' ), array( 'order_ref' => 'b' ) ), array(), 1 ) ) );
check( 'which order a group names (pure): by its own ref when it stands for no line — never over its line\'s order, never an order nobody handed in', array( 'id' => 4, 'ref' => 'T-4', 'status' => 'received' ) === No_Product::named_order( array( 'order_ref' => ' t-4' ), array( 't-4' => array( 'id' => 4, 'ref' => 'T-4', 'status' => 'received' ) ) ) && null === No_Product::named_order( array( 'order_ref' => 'T-4', 'batch_order_id' => 9 ), array( 't-4' => array( 'id' => 4, 'ref' => 'T-4', 'status' => '' ) ) ) && null === No_Product::named_order( array( 'order_ref' => 'T-5' ), array( 't-4' => array( 'id' => 4, 'ref' => 'T-4', 'status' => '' ) ) ) && null === No_Product::named_order( array(), array() ) );

$unknown = No_Product::gather( array( array( 'id' => 4, 'ref' => 'T-4', 'status' => 'received', 'lines' => array() ) ), array( array( 'id' => 8, 'species' => '?? small goby', 'needs_id' => true, 'qty_live' => 2, 'tank_number' => 311, 'order_ref' => 'T-4' ) ) )['unknown'];
check( 'a "??" group that names its fish order by a ref: listed under "Not identified yet" with that order', 1 === count( $unknown ) && 4 === $unknown[0]['order_id'] && 'T-4' === $unknown[0]['order_ref'] && 311 === $unknown[0]['tank'] );

$db->queries = array();
$real->orders( array( 'refs' => array( '2026-11', ' 2025-4 ', '2026-11', '', array( 'x' ) ) ) );
$asked = $db->queries;
check( 'the real Store reads those orders in one query, by their refs', 1 === count( $asked ) && false !== strpos( $asked[0], 'o.ref IN (2026-11,2025-4)' ) );
$db->queries = array();
check( '… and asks nothing when no ref is left', array() === $real->orders( array( 'refs' => array( ' ', '' ) ) ) && array() === $db->queries );

/* ------------------------------------------------------------------ */
echo "7. A group whose product is in the trash, or deleted for good: never stale in the search\n";

$trashed = $world->fish_search( 'binned test', 8 );
$binned  = $world->open_stays( array( 'species' => 'Binned Test Puffer', 'limit' => 5 ), $today, $settings )[0];
$world->record_event( (int) $binned['id'], array( 'type' => 'move', 'from_tank_id' => (int) $world->tank_by_number( 122 )['id'], 'to_tank_id' => (int) $world->tank_by_number( 111 )['id'], 'event_at' => Store::site_noon_utc( $today ) ), $today, $settings );
$moved = $world->fish_search( 'binned test', 8 );
check( 'the group whose product is in the trash, moved to another tank: the search says the new tank', 28 === $binned['product_id'] && 1 === count( $trashed ) && 'in tank 122' === $trashed[0]['where'] && 1 === count( $moved ) && 'in tank 111' === $moved[0]['where'] );
check( '… and the rule that drops the search\'s kept list says so for a group that carries a product: it cannot know the product is in the trash', true === Order_Fish::stay_changes( array( 'product_id' => 28, 'qty_live' => 2, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 2 ) ) && true === Order_Fish::stay_changes( array( 'product_id' => 28, 'qty_live' => 0, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 1 ) ) && false === Order_Fish::stay_changes( array( 'product_id' => 28, 'qty_live' => 3, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 1 ) ) && false === Order_Fish::stay_changes( array( 'needs_id' => 1, 'qty_live' => 0, 'tank_id' => 1 ), array( 'qty_live' => 2, 'tank_id' => 2 ) ) );

$both_kept = static function () {
	set_transient( Store::SPECIES_TRANSIENT, array( 'kept' ), 600 );
	set_transient( Store::ORDER_FISH_TRANSIENT, array( 'kept' ), 600 );
};
$any_kept  = static function () {
	return array( false !== get_transient( Store::SPECIES_TRANSIENT ), false !== get_transient( Store::ORDER_FISH_TRANSIENT ) );
};
check( 'a product deleted for good (no trash) is saved by nothing — WordPress says "deleted_post", and the section listens', false !== strpos( $room_source, "add_action( 'deleted_post', array( self::class, 'product_deleted' ), 10, 2 );" ) );
$both_kept();
Clean_Room::product_deleted( 14, (object) array( 'ID' => 14, 'post_type' => 'product' ) );
check_same( '… a product deleted: both kept lists are dropped — its fish is found as the unlisted fish in its tank at once', array( false, false ), $any_kept() );
$both_kept();
Clean_Room::product_deleted( 15, (object) array( 'ID' => 15, 'post_type' => 'shop_order' ) );
Clean_Room::product_deleted( 16, (object) array( 'ID' => 16, 'post_type' => 'revision' ) );
check_same( '… any other post deleted (a shop order, a revision): both kept', array( true, true ), $any_kept() );
Clean_Room::product_deleted( 17 );
check_same( '… a WordPress that hands no post over: dropped, to be safe', array( false, false ), $any_kept() );
$GLOBALS['fh_actions']['deleted_post'] = array( array( array( Clean_Room::class, 'product_deleted' ), 2 ) );
$both_kept();
do_action( 'deleted_post', 14, (object) array( 'ID' => 14, 'post_type' => 'product' ) );
check_same( '… through the hook as WordPress fires it', array( false, false ), $any_kept() );
unset( $GLOBALS['fh_actions']['deleted_post'] );

unset( $GLOBALS['wpdb'] );

fh_finish();
