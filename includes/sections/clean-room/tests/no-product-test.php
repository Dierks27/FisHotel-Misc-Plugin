<?php
/**
 * Fish with no product yet (No_Product, Admin_No_Product): the one rule —
 * a line by its species-list row or the one product named like it, a stay
 * by its own link, a product in the trash or deleted is none, a draft is
 * one, a "??" fish is a case of its own —, lines and stays gathered into
 * fish, the list's rows (what matters now / closed orders too / one
 * order, the sort, the "??" group, a page), the counts for the Board's
 * chip and the Fish orders list, the tag on an order line whatever its
 * price, "Link to a product…" (the species row, every line, every open
 * stay — no event, no label), the page that was open before the link, what
 * a draft product is made of (name, scientific name, sizes → variations,
 * the planned price or none) and "a second click makes no second
 * product".
 *
 * Every fish, product, supplier and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/no-product-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Dose;
use FisHotel\Misc\Sections\Clean_Room\Identify;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\No_Product;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Shop_Price;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

// The template helpers the views echo with (wp-stubs.php has the returning ones).
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
/** selected() / checked() / disabled(): the attribute when the two values are the same. */
function fh_form_attribute( $name, $a, $b, $echo ) {
	$out = (string) $a === (string) $b ? ' ' . $name . '="' . $name . '"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute.
	}
	return $out;
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'selected', $a, $b, $echo );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'checked', $a, $b, $echo );
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'disabled', $a, $b, $echo );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}
}

$today     = '2026-11-20';
$settings  = Settings::get();
$protocols = Settings::protocols();

$GLOBALS['fh_current_user'] = 7;
$GLOBALS['fh_user_caps']    = array(
	'manage_woocommerce' => array( 7, 8 ),
	'edit_products'      => array( 7 ),
);

function near( $a, $b, $eps = 1e-6 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/** The shop a test's "Start a draft product" makes its products in: it counts them. */
class Fh_Test_Shop {
	public $made = array();
	public $fail = false;
	private $store;

	public function __construct( Fake_Store $store ) {
		$this->store = $store;
	}

	public function create( array $plan ) {
		if ( $this->fail ) {
			return array( 'ok' => false, 'id' => 0, 'error' => 'the test shop is closed' );
		}
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

function fh_np_row( $species, array $extra = array() ) {
	return array_merge(
		array(
			'id'          => '0',
			'species'     => $species,
			'qty_ordered' => '6',
			'unit_price'  => '5',
			'per_box'     => '12',
		),
		$extra
	);
}

function fh_np_save( $store, array $lines, array $header, $id = 0 ) {
	global $today;
	$saved = Admin_Workbook::save_order(
		$store,
		array_merge(
			array(
				'ref'             => $id > 0 ? $store->order( $id )['ref'] : '',
				'supplier'        => 'Test Supplier One',
				'origin'          => 'Testland',
				'status'          => 'ordered',
				'cost_mode'       => 'box',
				'boxes'           => 1,
				'domestic_flight' => '60',
				'import_fees'     => '60',
				'lines'           => $lines,
			),
			$header
		),
		$id,
		$today
	);
	if ( ! empty( $saved['error'] ) ) {
		fwrite( STDERR, 'fixture order: ' . $saved['error'] . "\n" );
		exit( 1 );
	}
	return $saved;
}

/** A group put straight into a tank, as "Add fish already here" leaves one. */
function fh_np_group( $store, $tank_number, $species, $qty, $product_id = 0 ) {
	global $today, $settings;
	$tank = $store->tank_by_number( $tank_number );
	$id   = $store->create_stay( array( 'species' => $species, 'checkin_date' => $today, 'tank_id' => (int) $tank['id'], 'source' => 'manual', 'product_id' => $product_id ) );
	$store->record_event( $id, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => (int) $tank['id'], 'event_at' => Store::site_noon_utc( $today ) ), $today, $settings );
	return $id;
}

/**
 * The shop and the room every part of this test starts from.
 *
 * Products: 21 Lined Test Wrasse, 22 Powder Test Tang, 23 Trashed Test
 * Goby (in the trash), 24 Draft Test Blenny (a draft), 25 + 26 two Twin
 * Test Chromis, 27 Orange Dartfish (Test). Species list: 1 Lined Test
 * Wrasse → 21, 2 Blue Test Tang (no product), 3 Trashed Test Goby → 23,
 * 4 Draft Test Blenny → 24, 5 Gone Test Dart → 99 (a product that was
 * deleted).
 *
 * Order A (received 2026-10-05, five lines checked in), order B (closed,
 * 2025), order C (a draft of 2026-11-01), and two groups put into tanks
 * by hand.
 */
function fh_np_world() {
	global $today, $settings;
	$store = new Fake_Store(
		array(
			21 => 'Lined Test Wrasse',
			22 => 'Powder Test Tang',
			23 => 'Trashed Test Goby',
			24 => 'Draft Test Blenny',
			25 => 'Twin Test Chromis',
			26 => 'Twin Test Chromis',
			27 => 'Orange Dartfish (Test)',
		)
	);
	$store->product_statuses = array( 23 => 'trash', 24 => 'draft' );
	$store->size_attributes  = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms       = array(
		'pa_size' => array(
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
			array( 'term_id' => 31, 'name' => '3-4"', 'slug' => '3-4', 'menu_order' => 2 ),
		),
	);
	$store->save_species( array( 'name' => 'Lined Test Wrasse', 'sci_name' => 'Testus lineatus', 'product_id' => 21 ) );   // 1
	$store->save_species( array( 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus' ) );                         // 2
	$store->save_species( array( 'name' => 'Trashed Test Goby', 'product_id' => 23 ) );                                    // 3
	$store->save_species( array( 'name' => 'Draft Test Blenny', 'product_id' => 24 ) );                                    // 4
	$store->save_species( array( 'name' => 'Gone Test Dart', 'product_id' => 99 ) );                                       // 5

	$a = fh_np_save(
		$store,
		array(
			fh_np_row( 'Lined Test Wrasse', array( 'species_id' => '1' ) ),
			fh_np_row( 'Blue Test Tang', array( 'species_id' => '2', 'size_slug' => '2-3', 'qty_received' => '6' ) ),
			fh_np_row( 'blue test tang', array( 'size_slug' => '1-2', 'qty_received' => '4' ) ),
			fh_np_row( 'Powder Test Tang', array( 'qty_received' => '2' ) ),
			fh_np_row( 'Trashed Test Goby', array( 'species_id' => '3' ) ),
			fh_np_row( 'Draft Test Blenny', array( 'species_id' => '4' ) ),
			fh_np_row( 'Gone Test Dart', array( 'species_id' => '5' ) ),
			fh_np_row( 'Twin Test Chromis' ),
			fh_np_row( 'Orange Test Dart', array( 'sci_name' => 'Testus aurantius', 'size_slug' => '3-4', 'qty_received' => '3', 'worth' => '45' ) ),
			fh_np_row( 'small wrasse', array( 'needs_id' => '1', 'qty_received' => '2' ) ),
		),
		array( 'order_date' => '2026-10-01', 'received_date' => '2026-10-05', 'status' => 'received' )
	);
	$checked = Orders::check_in( $store, $store->order( $a['order_id'] ), '2026-10-05', $settings, array( 'actor_id' => 7 ) );

	$b = fh_np_save(
		$store,
		array(
			fh_np_row( 'Old Test Angel', array( 'qty_received' => '6' ) ),
			fh_np_row( 'Blue Test Tang', array( 'species_id' => '2', 'qty_received' => '6', 'size_slug' => '3-4' ) ),
		),
		array( 'order_date' => '2025-03-01', 'received_date' => '2025-03-04', 'status' => 'closed' )
	);

	$c = fh_np_save(
		$store,
		array(
			fh_np_row( 'Orange Test Dart', array( 'size_slug' => '2-3', 'worth' => '50' ) ),
		),
		array( 'order_date' => '2026-11-01', 'status' => 'draft' )
	);

	$grey  = fh_np_group( $store, 121, 'Grey Test Dartfish', 5 );
	$loose = fh_np_group( $store, 122, 'lined test wrasse', 1 ); // Typed as an unlisted fish: no product link, though the list has one.

	return array(
		'store'   => $store,
		'a'       => (int) $a['order_id'],
		'b'       => (int) $b['order_id'],
		'c'       => (int) $c['order_id'],
		'checked' => $checked,
		'grey'    => $grey,
		'loose'   => $loose,
	);
}

/** The orders a page reads, resolved, and the open stays. */
function fh_np_read( $store, array $filters = array() ) {
	global $today, $settings;
	return array( Admin_Workbook::resolved_orders( $store, $filters ), $store->open_stays( array( 'limit' => 2000 ), $today, $settings ) );
}

function fh_np_gather( $store, array $ctx = array(), array $filters = array( 'open' => true ) ) {
	global $settings;
	list( $orders, $stays ) = fh_np_read( $store, $filters );
	return Admin_No_Product::gathered( $store, $orders, $stays, array_merge( array( 'sizes' => Sizes::standard( $store, $settings )['sizes'] ), $ctx ) );
}

function fh_np_named( array $rows, $name ) {
	foreach ( $rows as $row ) {
		if ( $row['name'] === $name ) {
			return $row;
		}
	}
	return null;
}

/* ------------------------------------------------------------------ */
echo "1. The rule: which line and which stay has a product\n";

$w     = fh_np_world();
$store = $w['store'];
$names = Admin_No_Product::names( $store );
$alive = No_Product::alive( $store->product_statuses( array( 21, 22, 23, 24, 25, 26, 27, 99 ) ) );
$a     = $store->order( $w['a'] );
$state = static function ( $index ) use ( $a, $names, $alive ) {
	return No_Product::line_state( $a['lines'][ $index ], $names, $alive );
};
check_same( 'what still exists: a published and a draft product — not the one in the trash, not the deleted one', array( 21, 22, 24, 25, 26, 27 ), array_keys( $alive ) );
check( 'a line whose species-list row is linked to a product has one', 'linked' === $state( 0 ) && 21 === No_Product::line_product( $a['lines'][0], $names, $alive ) );
check( 'a line whose row is linked to none has none — picked from the list, or only typed with its name', 'none' === $state( 1 ) && 'none' === $state( 2 ) );
check( 'a line only typed, named exactly like ONE fish product: that product', 'linked' === $state( 3 ) && 22 === No_Product::line_product( $a['lines'][3], $names, $alive ) );
check( 'a product in the trash counts as none', 'none' === $state( 4 ) && null === No_Product::line_product( $a['lines'][4], $names, $alive ) );
check( 'a DRAFT product counts as linked', 'linked' === $state( 5 ) && 24 === No_Product::line_product( $a['lines'][5], $names, $alive ) );
check( 'a product that was deleted counts as none', 'none' === $state( 6 ) );
check( 'two products with the same name link nothing', 'none' === $state( 7 ) );
check( 'a fish typed under a name the shop does not have: none', 'none' === $state( 8 ) );
check( 'a "??" fish is a different case: it needs identifying first', 'unknown' === $state( 9 ) && null === No_Product::line_product( $a['lines'][9], $names, $alive ) && 'unknown' === No_Product::line_state( array( 'species' => '?? odd goby', 'species_product_id' => 21 ), $names, $alive ) );
check( 'it is the rule the shop price reads by — plus "does the product still exist"', 23 === Shop_Price::product_of( $a['lines'][4], $names ) && null === No_Product::line_product( $a['lines'][4], $names, $alive ) && 23 === No_Product::line_product( $a['lines'][4], $names, null ) );

$closed = Admin_Workbook::resolve_order( $store, $store->order( $w['b'] ) );
check( 'a closed order is frozen for prices (no shop product is read) — the rule is asked directly and still answers', null === $closed['lines'][0]['shop_product_id'] && 'none' === No_Product::line_state( $closed['lines'][0], $names, $alive ) );

$stays   = $store->open_stays( array(), $today, $settings );
$by_name = array();
foreach ( $stays as $stay ) {
	$by_name[ $stay['species'] ] = $stay;
}
check( 'the check-in gave the Powder Test Tang group its product (by its name); a stay is linked by its own link', 22 === $by_name['Powder Test Tang']['product_id'] && 'linked' === No_Product::stay_state( $by_name['Powder Test Tang'], $alive ) );
check( 'a stay without a link has none — also when the species list knows a product under its name', 'none' === No_Product::stay_state( $by_name['Blue Test Tang'], $alive ) && 'none' === No_Product::stay_state( $by_name['lined test wrasse'], $alive ) );
check( 'a stay linked to a product in the trash, or to one that was deleted, has none', 'none' === No_Product::stay_state( array( 'species' => 'x', 'product_id' => 23 ), $alive ) && 'none' === No_Product::stay_state( array( 'species' => 'x', 'product_id' => 99 ), $alive ) && 'linked' === No_Product::stay_state( array( 'species' => 'x', 'product_id' => 24 ), $alive ) );
check( 'a "??" stay needs identifying — flagged, or only named so', 'unknown' === No_Product::stay_state( $by_name['?? small wrasse'], $alive ) && 'unknown' === No_Product::stay_state( array( 'species' => '?? x', 'product_id' => 21 ), $alive ) );
check( 'nobody read the statuses (a stand-in store): every id counts as a product', 'linked' === No_Product::stay_state( array( 'species' => 'x', 'product_id' => 23 ), null ) && true === No_Product::exists( 5, null ) && false === No_Product::exists( 0, null ) && false === No_Product::exists( 5, array() ) );
check_same( 'the ids one read of the statuses asks about: the lines\' products and the stays\' links, each once', array( 21, 22, 23, 24, 99 ), ( static function () use ( $store, $w, $stays, $names ) {
	$ids = No_Product::product_ids( array( $store->order( $w['a'] ) ), $stays, $names );
	sort( $ids );
	return $ids;
} )() );

/* ------------------------------------------------------------------ */
echo "2. What matters now\n";

check( 'a line of a draft, an ordered or a received order matters now', No_Product::is_current( array( 'status' => 'draft' ), array() ) && No_Product::is_current( array( 'status' => 'ordered' ), array() ) && No_Product::is_current( array( 'status' => 'received' ), array() ) );
check( 'a line of a closed order is history …', false === No_Product::is_current( array( 'status' => 'closed' ), array() ) && false === No_Product::is_current( array( 'status' => 'closed' ), array( 'stay_id' => 4, 'stay_status' => 'closed' ) ) );
check( '… unless its own group is still in a tank', true === No_Product::is_current( array( 'status' => 'closed' ), array( 'stay_id' => 4, 'stay_status' => 'in_qt' ) ) && true === No_Product::is_current( array( 'status' => 'closed' ), array( 'stay_id' => 4, 'stay_status' => 'cleared' ) ) );

/* ------------------------------------------------------------------ */
echo "3. Lines and stays gathered into fish — one row per fish\n";

$reads = $store->status_reads;
$now   = fh_np_gather( $store );
check_same( 'ONE read of the products\' statuses for the whole list — never one per row', 1, $store->status_reads - $reads );
check_same( 'the fish that matter now, those in a tank first, then by name', array( 'Blue Test Tang', 'Grey Test Dartfish', 'lined test wrasse', 'Orange Test Dart', 'Gone Test Dart', 'Trashed Test Goby', 'Twin Test Chromis' ), array_column( $now['fish'], 'name' ) );
check( 'no linked fish is on it: not the wrasse\'s line, not the tang named like a product, not the fish with a draft', null === fh_np_named( $now['fish'], 'Lined Test Wrasse' ) && null === fh_np_named( $now['fish'], 'Powder Test Tang' ) && null === fh_np_named( $now['fish'], 'Draft Test Blenny' ) );

$tang = fh_np_named( $now['fish'], 'Blue Test Tang' );
check( 'two lines of one fish — one picked from the list, one only typed with its name in another case — are ONE row, under its species-list row', 's:2' === $tang['key'] && 2 === $tang['species_id'] && 2 === count( $tang['line_ids'] ) );
check( '… with its groups in the tanks: the stay typed "blue test tang" goes with its line', 2 === count( $tang['stay_ids'] ) && 10 === $tang['alive'] && true === $tang['in_tank'] && 2 === count( $tang['tanks'] ) );
check_same( '… the sizes seen, in the standard sizes\' own order', array( '1-2"', '2-3"' ), array_column( $tang['sizes'], 'label' ) );
check( '… its scientific name from the species list, its order by ref', 'Testus caeruleus' === $tang['sci_name'] && 1 === count( $tang['orders'] ) && $w['a'] === $tang['orders'][0]['id'] && 'received' === $tang['orders'][0]['status'] && '' !== $tang['orders'][0]['ref'] );

$resolved = Admin_Workbook::resolve_order( $store, $store->order( $w['a'] ) );
check( '… what it cost landed per fish on its newest order, and that order\'s target price', near( $tang['cost'], $resolved['lines'][1]['landed_per_fish'] ) && $tang['cost'] > 0 && near( $tang['target'], $resolved['lines'][1]['suggested_price'] ) && $resolved['ref'] === $tang['cost_ref'] && null === $tang['planned'] );

$dart = fh_np_named( $now['fish'], 'Orange Test Dart' );
check( 'a fish with no species-list row is gathered by its name as typed: its two orders, one row', 'n:orange test dart' === $dart['key'] && null === $dart['species_id'] && 2 === count( $dart['orders'] ) && 2 === count( $dart['line_ids'] ) && 1 === count( $dart['stay_ids'] ) && 3 === $dart['alive'] );
check( '… the scientific name typed on its line; both sizes', 'Testus aurantius' === $dart['sci_name'] && array( '2-3"', '3-4"' ) === array_column( $dart['sizes'], 'label' ) );
check( '… the cost from the newest order that HAS one (the draft order has received nothing), the planned price from the newest open order', near( $dart['cost'], $resolved['lines'][8]['landed_per_fish'] ) && $resolved['ref'] === $dart['cost_ref'] && near( $dart['planned'], 50 ) );

$grey = fh_np_named( $now['fish'], 'Grey Test Dartfish' );
check( 'a group put into a tank by hand, on no order: a row of its own, with its tank and how many are alive', 'n:grey test dartfish' === $grey['key'] && array() === $grey['orders'] && array( 121 ) === $grey['tanks'] && 5 === $grey['alive'] && null === $grey['cost'] && array() === $grey['sizes'] && '' === $grey['sci_name'] );

$loose = fh_np_named( $now['fish'], 'lined test wrasse' );
check( 'a group typed as an unlisted fish whose name the species list ties to a product: on the list (the group has no link) with that product offered', 's:1' === $loose['key'] && 21 === $loose['candidate'] && array() === $loose['line_ids'] && array( $w['loose'] ) === $loose['stay_ids'] );
check( 'no other row has a product to offer', array( 21 ) === array_values( array_filter( array_column( $now['fish'], 'candidate' ) ) ) );

check( 'the "??" fish are a group of their own, never mixed in — and a line that is a group in a tank is listed once, as that group', 1 === count( $now['unknown'] ) && 'stay' === $now['unknown'][0]['kind'] && '?? small wrasse' === $now['unknown'][0]['name'] && 2 === $now['unknown'][0]['alive'] && $w['a'] === $now['unknown'][0]['order_id'] && null === fh_np_named( $now['fish'], '?? small wrasse' ) );
$late = No_Product::gather( array(), array( array( 'id' => 3, 'species' => 'Late Test Hawkfish', 'qty_live' => 4, 'qty_arrived' => 5, 'landed_cost' => 25.0, 'tank_number' => 311, 'batch_order_id' => 6, 'batch_order_ref' => 'T-6', 'order_line_id' => 60 ) ) );
check( 'a group whose order is closed (its line is not read): the order by the stay\'s own ref, the cost the stay was checked in with — per fish', 1 === count( $late['fish'] ) && near( $late['fish'][0]['cost'], 25.0 ) && 'T-6' === $late['fish'][0]['cost_ref'] && array( array( 'id' => 6, 'ref' => 'T-6', 'status' => '' ) ) === $late['fish'][0]['orders'] && array( 311 ) === $late['fish'][0]['tanks'] && 4 === $late['fish'][0]['alive'] );
$bare = No_Product::gather( array( array( 'id' => 9, 'ref' => 'T-9', 'status' => 'draft', 'lines' => array( array( 'id' => 90, 'species' => '?? odd goby', 'needs_id' => true ), array( 'id' => 91, 'species' => 'Plain Test Goby' ) ) ) ), array() );
check( 'a "??" line that is not in a tank yet is listed as a line of its order', 1 === count( $bare['unknown'] ) && 'line' === $bare['unknown'][0]['kind'] && 9 === $bare['unknown'][0]['order_id'] && 1 === count( $bare['fish'] ) && false === $bare['fish'][0]['in_tank'] && null === $bare['fish'][0]['cost'] );

/* ------------------------------------------------------------------ */
echo "4. Closed orders too, one order, a page\n";

check( 'by default the fish of a closed order is not listed', null === fh_np_named( $now['fish'], 'Old Test Angel' ) );
$all = fh_np_gather( $store, array( 'closed_too' => true ), array() );
check( 'with the switch it is — after the fish in tanks, by name', null !== fh_np_named( $all['fish'], 'Old Test Angel' ) && array( 'Blue Test Tang', 'Grey Test Dartfish', 'lined test wrasse', 'Orange Test Dart', 'Gone Test Dart', 'Old Test Angel', 'Trashed Test Goby', 'Twin Test Chromis' ) === array_column( $all['fish'], 'name' ) );
$tang_all = fh_np_named( $all['fish'], 'Blue Test Tang' );
check( '… and a fish of both then names both orders and every size, the cost still from its newest order', 2 === count( $tang_all['orders'] ) && array( '1-2"', '2-3"', '3-4"' ) === array_column( $tang_all['sizes'], 'label' ) && near( $tang_all['cost'], $tang['cost'] ) );
$old = fh_np_named( $all['fish'], 'Old Test Angel' );
check( '… a closed order\'s price is not a planned price', null === $old['planned'] && false === $old['in_tank'] );

$one = Admin_No_Product::data( $store, array( 'order' => (string) $w['c'] ), $today, $settings );
check( 'one order asked for: only its fish', 1 === $one['total'] && 'Orange Test Dart' === $one['rows'][0]['name'] && $w['c'] === $one['order']['id'] && array() === $one['unknown'] );
$one_closed = Admin_No_Product::data( $store, array( 'order' => (string) $w['b'] ), $today, $settings );
check( '… a closed one: what matters now of it — nothing of this one is in a tank, and its count on the Fish orders list said none', array() === $one_closed['rows'] && false === $one_closed['closed_too'] && $w['b'] === $one_closed['order']['id'] );
check( '… and every fish of it with the switch', array( 'Blue Test Tang', 'Old Test Angel' ) === array_column( Admin_No_Product::data( $store, array( 'order' => (string) $w['b'], 'closed' => '1' ), $today, $settings )['rows'], 'name' ) );

$data = Admin_No_Product::data( $store, array(), $today, $settings );
check( 'the page\'s data: what matters now, the "??" group, the title of the product a row offers', 7 === $data['total'] && 1 === $data['pages'] && false === $data['closed_too'] && null === $data['order'] && 1 === count( $data['unknown'] ) && array( 21 => 'Lined Test Wrasse' ) === $data['titles'] && false === $data['orders_capped'] );
check( '… with the switch: the closed orders\' fish as well', 8 === Admin_No_Product::data( $store, array( 'closed' => '1' ), $today, $settings )['total'] && true === Admin_No_Product::data( $store, array( 'closed' => '1' ), $today, $settings )['closed_too'] );
$page = No_Product::page( $all['fish'], 2, 3 );
check( 'a page of the list: its rows, the count, the pages; a page beyond the last is the last', 8 === $page['total'] && 3 === $page['pages'] && 2 === $page['paged'] && array( 'Orange Test Dart', 'Gone Test Dart', 'Old Test Angel' ) === array_column( $page['rows'], 'name' ) && 3 === No_Product::page( $all['fish'], 99, 3 )['paged'] && 1 === No_Product::page( array(), 4 )['pages'] );
check( 'the sort alone: in a tank first, then natural and case-blind by name', array( 'b', 'a 2', 'A 10', 'c' ) === array_column( No_Product::sort( array( array( 'name' => 'c', 'key' => 'n:c' ), array( 'name' => 'A 10', 'key' => 'n:a 10' ), array( 'name' => 'b', 'key' => 'n:b', 'in_tank' => true ), array( 'name' => 'a 2', 'key' => 'n:a 2' ) ) ), 'name' ) );

/* ------------------------------------------------------------------ */
echo "5. The marks: the Board's chip, the Fish orders list, an order's lines, the Stays tab\n";

$room  = $store->room( $today, $settings, $protocols );
$reads = $store->status_reads;
check_same( 'the Board\'s chip counts the fish of the list', 7, Admin_No_Product::board_count( $store, $room['tanks'] ) );
check_same( '… for one read of the statuses', 1, $store->status_reads - $reads );
check( 'the chip\'s words; none at 0', '7 fish with no product yet' === No_Product::chip_text( 7 ) && '' === No_Product::chip_text( 0 ) && '3 without a product' === No_Product::count_text( 3 ) && '' === No_Product::count_text( 0 ) && 'no product yet' === No_Product::tag_text() );
check( 'a store that cannot say counts nothing — the Board never breaks for a chip', 0 === Admin_No_Product::board_count( new stdClass(), $room['tanks'] ) );

function fh_np_board_html( Fake_Store $store, $no_product ) {
	global $today, $settings, $protocols;
	$tab          = 'board';
	$can_settings = false;
	$tables_ok    = true;
	$labels_url   = 'https://example.test/labels';
	$filters      = Admin::filters_from_request( array() );
	$room         = $store->room( $today, $settings, $protocols );
	$tanks        = $room['tanks'];
	$todo         = is_array( $room['todo'] ) ? $room['todo'] : array( 'left' => 0, 'total' => 0 );
	$groups       = Layout::group( $store->buildings(), $tanks );
	$totals       = Admin::totals( $tanks );
	$doses        = Dose::board_doses( $store->latest_doses() );
	$sizes        = Sizes::standard( $store, $settings )['sizes'];
	$temp         = Admin_Temp::board( $store, $tanks, $protocols, $today );
	$origins      = Admin::origins( $store, $tanks );
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/board.php';
	return (string) ob_get_clean();
}
$board = fh_np_board_html( $store, 7 );
check( 'the Board shows it as one chip that leads to the list', 1 === substr_count( $board, 'fh-cr-chip--noproduct' ) && 1 === preg_match( '/fh-cr-chip--noproduct" href="([^"]*)"[^>]*><strong>7<\/strong> fish with no product yet &rarr;/', $board, $m ) && false !== strpos( html_entity_decode( $m[1] ), 'tab=species' ) && false !== strpos( html_entity_decode( $m[1] ), 'view=no-product' ) );
check( '… and no chip at 0', false === strpos( fh_np_board_html( $store, 0 ), 'fh-cr-chip--noproduct' ) );

$reads  = $store->status_reads;
$marked = Admin_No_Product::mark_orders( $store, Admin_Workbook::resolved_orders( $store, array() ) );
$by_id  = array();
foreach ( $marked as $order ) {
	$by_id[ $order['id'] ] = $order;
}
check_same( 'the Fish orders list: one read for every order on it', 1, $store->status_reads - $reads );
check( 'an order\'s count is its FISH without a product — the tang\'s two lines are one; the "??" fish is not among them', 5 === $by_id[ $w['a'] ]['no_product'] && 1 === $by_id[ $w['c'] ]['no_product'] );
check( 'a closed order counts none: it is history', 0 === $by_id[ $w['b'] ]['no_product'] && false === $by_id[ $w['b'] ]['lines'][0]['no_product'] );
check_same( 'the lines marked on order A', array( false, true, true, false, true, false, true, true, true, false ), array_column( $by_id[ $w['a'] ]['lines'], 'no_product' ) );
check( 'order_count() alone, with and without the species list (a typed name then counts apart)', 5 === No_Product::order_count( $store->order( $w['a'] ), $names, $alive, Stats::species_index( $store->species_brief() )['names'] ) && 6 === No_Product::order_count( $store->order( $w['a'] ), $names, $alive ) );

function fh_np_page( Fake_Store $store, array $get ) {
	global $today, $settings;
	$keep = $_GET;
	$_GET = $get;
	ob_start();
	Admin_Workbook::render(
		isset( $get['tab'] ) ? $get['tab'] : 'arrivals',
		array(
			'store'        => $store,
			'settings'     => $settings,
			'today'        => $today,
			'tables_ok'    => true,
			'can_settings' => true,
			'labels_url'   => '',
		)
	);
	$_GET = $keep;
	return (string) ob_get_clean();
}
$list = fh_np_page( $store, array() );
check( 'the Fish orders list says "5 without a product" and "1 without a product", each a link to the list narrowed to that order', 1 === substr_count( $list, '5 without a product' ) && 1 === substr_count( $list, '1 without a product' ) && 2 === substr_count( $list, 'class="fh-cr-sub fh-cr-noproduct fh-cr-noproduct--count"' ) && false !== strpos( html_entity_decode( $list ), 'view=no-product&order=' . $w['a'] ) );
check( '… and nothing beside a closed order', false === strpos( fh_np_page( $store, array( 'year' => '2025' ) ), 'without a product' ) );

$order_html = fh_np_page( $store, array( 'order' => (string) $w['a'] ) );
check_same( 'the order page: every line has the tag\'s place, six of them say "no product yet"', array( 10 + 1, 6 ), array( substr_count( $order_html, 'data-f="no_product"' ), preg_match_all( '/data-f="no_product"[^>]*>no product yet</', $order_html ) ) );
check( '… the tag belongs to neither view: it shows in Entry and in Numbers', 1 === preg_match( '/<a class="([^"]*)" data-f="no_product"/', $order_html, $m ) && false === strpos( $m[1], 'fh-cr-c-' ) );
$figures = Admin_Workbook::figures( Admin_No_Product::mark_order( $store, Admin_Workbook::resolve_order( $store, $store->order( $w['a'] ) ) ) );
check( 'a planned price typed with the pencil does not take the tag away', true === $figures['lines'][8]['priced'] && 'typed' === $figures['lines'][8]['shop_from'] && 'no product yet' === $figures['lines'][8]['no_product'] );
check( '… a linked line and a "??" line carry none', '' === $figures['lines'][0]['no_product'] && '' === $figures['lines'][9]['no_product'] && '' === $figures['lines'][5]['no_product'] );
$replied = fh_np_save( new Fake_Store( array( 21 => 'Lined Test Wrasse' ) ), array( fh_np_row( 'Mint Test Goby' ), fh_np_row( 'Lined Test Wrasse' ) ), array( 'order_date' => '2026-11-02' ) )['figures']['lines'];
check( 'a Save answers with the tags as they are now, for the page to redraw', 'no product yet' === $replied[0]['no_product'] && '' === $replied[1]['no_product'] );

$states = Admin_No_Product::stay_states( $store, $stays );
check( 'the Stays tab: each stay\'s state from one read', 'none' === $states[ $by_name['Blue Test Tang']['id'] ] && 'linked' === $states[ $by_name['Powder Test Tang']['id'] ] && 'unknown' === $states[ $by_name['?? small wrasse']['id'] ] && 'none' === $states[ $w['grey'] ] );
/** The Stays tab as Admin::render_page() draws it, on the test's store. */
function fh_np_stays_html( Fake_Store $store, $show_closed = false ) {
	global $today, $settings;
	$tab            = 'stays';
	$can_settings   = false;
	$tables_ok      = true;
	$filters        = Admin::filters_from_request( array() );
	$stays          = Admin::narrow_stays( $store->open_stays( $filters, $today, $settings ), $filters );
	$closed_stays   = $show_closed ? $store->open_stays( array( 'include_closed' => true ), $today, $settings ) : array();
	$csv_url        = 'https://example.test/csv';
	$xlsx_url       = 'https://example.test/xlsx';
	$flash          = '';
	$sizes          = Sizes::standard( $store, $settings )['sizes'];
	$fish_tanks     = Admin::existing_tanks( $store->tanks( false ) );
	$picks          = array( 'suppliers' => array(), 'origins' => array() );
	$product_states = Admin_No_Product::stay_states( $store, array_merge( $stays, $closed_stays ) );
	ob_start();
	include FISHOTEL_MISC_PATH . 'includes/sections/clean-room/views/stays.php';
	return (string) ob_get_clean();
}
$trashed    = fh_np_group( $store, 123, 'Binned Test Basslet', 1, 23 ); // Linked to the product that is in the trash.
$stays_html = fh_np_stays_html( $store );
$stay_row   = static function ( $id ) use ( $stays_html ) {
	$from = strpos( $stays_html, 'id="fh-cr-stay-' . (int) $id . '"' );
	return false === $from ? '' : substr( $stays_html, $from, strpos( $stays_html, '</tr>', $from ) - $from );
};
check( 'the Stays tab as it renders: a group without a product says "no product yet" and leads to the list; a linked one names its product', 1 === preg_match( '/class="fh-cr-sub fh-cr-noproduct" href="[^"]*view=no-product[^"]*"[^>]*>no product yet &rarr;</', $stay_row( $by_name['Blue Test Tang']['id'] ) ) && false === strpos( $stay_row( $by_name['Powder Test Tang']['id'] ), 'no product' ) && false !== strpos( $stay_row( $by_name['Powder Test Tang']['id'] ), 'product #22' ) );
check( '… a group linked to a product in the trash says it too — the states read for the page reach the rows', false !== strpos( $stay_row( $trashed ), 'no product yet &rarr;' ) && false === strpos( $stay_row( $trashed ), 'product #23' ) );
check( '… and a "??" group says "needs identifying", not "no product"', false === strpos( $stay_row( $by_name['?? small wrasse']['id'] ), 'no product' ) && false !== strpos( $stay_row( $by_name['?? small wrasse']['id'] ), 'needs identifying' ) );
$store->update_stay_fields( $trashed, array( 'product_id' => 0 ) );
$store->stays[ $trashed ]['status'] = 'closed'; // Out of the room again: the lists below count what the world() made.
$stays_src = (string) file_get_contents( Clean_Room::path() . 'views/stays.php' );
check( '… where an open stay\'s "no product" now reads "no product yet" and links to the list, from the states read once for the page', false !== strpos( $stays_src, 'No_Product::tag_text()' ) && false !== strpos( $stays_src, 'Admin_No_Product::url()' ) && false !== strpos( $stays_src, 'use ( $today, $sizes, $fh_states )' ) && false !== strpos( $stays_src, '$fh_states[ (int) $fh_stay[\'id\'] ]' ) );
check( '… a closed stay is history: it keeps the quiet "no product", unmarked', false !== strpos( $stays_src, "'none' === \$fh_np && ! \$open" ) );

/* ------------------------------------------------------------------ */
echo "6. The list as it renders\n";

$html = fh_np_page( $store, array( 'tab' => 'species', 'view' => 'no-product' ) );
check( 'Species → Fish with no product yet: the view, its place in the tab\'s strip, a row per fish', false !== strpos( $html, '<h2 class="fh-cr-np__title">Fish with no product yet</h2>' ) && false !== strpos( $html, 'class="fh-cr-subtab is-active"' ) && 7 === substr_count( $html, 'class="fh-cr-np-row' ) - 1 && false !== strpos( $html, '7 fish with no product yet · page 1 of 1' ) );
check( '… each with the two acts — or, where the shop has a product under its name already, that product alone: "Link to it"', 6 === substr_count( $html, 'fh-cr-np-link-open' ) && 5 === substr_count( $html, 'fh-cr-np-draft"' ) && 1 === substr_count( $html, 'fh-cr-np-candidate" data-product="21"' ) && false !== strpos( $html, 'The shop has &quot;Lined Test Wrasse&quot; under this name.' ) );
check( '… where it is, what it cost, the planned price', false !== strpos( $html, 'Planned price 50.00' ) && false !== strpos( $html, 'Landed ' ) && false !== strpos( $html, 'Target price ' ) && false !== strpos( $html, 'Sizes seen: 2-3&quot;, 3-4&quot;' ) && false !== strpos( $html, '10 alive' ) && false !== strpos( $html, 'not in a tank' ) );
check( '… the "??" group with the existing Identify action, and its panel', false !== strpos( $html, 'Not identified yet (1)' ) && 1 === substr_count( $html, 'class="button fh-cr-identify-open" data-stay="' . $by_name['?? small wrasse']['id'] . '"' ) && false !== strpos( $html, 'id="fh-cr-identify-template"' ) );
check( '… the switch to the closed orders, and back', false !== strpos( $html, 'Show the fish of closed fish orders too' ) && false !== strpos( fh_np_page( $store, array( 'tab' => 'species', 'view' => 'no-product', 'closed' => '1' ) ), 'Only the fish that matter now' ) && false !== strpos( fh_np_page( $store, array( 'tab' => 'species', 'view' => 'no-product', 'closed' => '1' ) ), 'Old Test Angel' ) && false === strpos( $html, 'Old Test Angel' ) );
check( 'the Species tab itself still opens on the species list, with the strip above it', false !== strpos( fh_np_page( $store, array( 'tab' => 'species' ) ), 'fh-cr-species-table' ) && false !== strpos( fh_np_page( $store, array( 'tab' => 'species' ) ), 'Fish with no product yet</a>' ) );
$GLOBALS['fh_current_user'] = 8;
$html8                      = fh_np_page( $store, array( 'tab' => 'species', 'view' => 'no-product' ) );
check( 'a fish the shop has TWO products for under its name: neither is offered, none is made — the owner links the right one', 1 === substr_count( $html, 'The shop has 2 products called &quot;Twin Test Chromis&quot;' ) && 2 === fh_np_named( $data['rows'], 'Twin Test Chromis' )['twins'] && null === fh_np_named( $data['rows'], 'Twin Test Chromis' )['candidate'] );
check( 'a planned price is said with the size it was typed for', false !== strpos( $html, '<strong>Planned price 50.00</strong> for 2-3&quot;' ) && false !== strpos( $html, '<strong>Planned price 45.00</strong> for 3-4&quot;' ) );
check( 'the orders of a fish are listed with no space before a comma', 1 === preg_match( '/\(draft\)<\/span>,\s+<a href=/', $html ) && 0 === preg_match( '/\s,/', strip_tags( substr( $html, strpos( $html, 'fh-cr-np-table' ) ) ) ) );
check( 'a user who may not make products is offered the link only, and told why', 0 === substr_count( $html8, 'fh-cr-np-draft"' ) && 6 === substr_count( $html8, 'fh-cr-np-link-open' ) && 1 === substr_count( $html8, 'fh-cr-np-candidate' ) && false !== strpos( $html8, 'Your account may not make products' ) && '' === Admin_No_Product::js_config()['draftNonce'] );
$GLOBALS['fh_current_user'] = 7;
check( '… the page\'s script gets the draft\'s own nonce only for a user who may', wp_create_nonce( Admin_No_Product::NONCE_DRAFT ) === Admin_No_Product::js_config()['draftNonce'] && Admin_No_Product::NONCE_DRAFT !== Admin::NONCE_AJAX );
$css = (string) file_get_contents( Clean_Room::path() . 'css/clean-room-admin.css' );
check( 'on a narrow window the list is cards — the table\'s cells stack, its tab strip wraps, nothing scrolls sideways', 1 === preg_match( '/@media \(max-width: 782px\) \{\s*\.fh-cr-np \.fh-cr-tabs \{\s*flex-wrap: wrap;\s*\}\s*\.fh-cr-np-table,\s*\.fh-cr-np-table tbody,\s*\.fh-cr-np-table tr,\s*\.fh-cr-np-table td \{\s*display: block;/', $css ) && false !== strpos( $css, '.fh-cr-np-table thead {' ) );

/* ------------------------------------------------------------------ */
echo "7. Link to a product\n";

$events = $store->event_count();
$linked = Admin_No_Product::link_request( $store, array( 'key' => 'n:orange test dart', 'name' => 'Orange Test Dart', 'product_id' => '27' ), $today, $settings );
check( 'the fish typed "Orange Test Dart" is linked to the product "Orange Dartfish (Test)"', true === $linked['ok'] && 200 === $linked['status'] && 27 === $linked['product_id'] && false !== strpos( $linked['message'], 'Orange Test Dart is now linked to the product "Orange Dartfish (Test)".' ), json_encode( $linked ) );
$row = $store->species_by_name( 'Orange Test Dart' );
check( 'the species list gets the fish under the name it was typed with, its scientific name, linked to the product', null !== $row && 27 === $row['product_id'] && 'Testus aurantius' === $row['sci_name'] && false !== strpos( $linked['message'], 'added to the species list' ) );
$la = $store->order( $w['a'] )['lines'][8];
$lc = $store->order( $w['c'] )['lines'][0];
check( 'every line of it follows — on both orders — through the species-list row', (int) $row['id'] === $la['species_id'] && (int) $row['id'] === $lc['species_id'] && 27 === $la['species_product_id'] && 2 === count( $linked['lines'] ) && 'linked' === No_Product::line_state( $la, $names, No_Product::alive( $store->product_statuses( array( 27 ) ) ) ) );
$st = $store->stay( $by_name['Orange Test Dart']['id'], $today, $settings );
check( 'its group in the tank follows: the product link, nothing else — the name, the size, the count as they were', 27 === $st['product_id'] && 'Orange Test Dart' === $st['species'] && '3-4' === $st['size_slug'] && 3 === $st['qty_live'] && array( $st['id'] ) === $linked['stays'] && false !== strpos( $linked['message'], '1 group in the tanks follows.' ) );
check( 'no event is written, no label kept: linking is not a correction (the fish is not "mislabeled")', $events === $store->event_count() && null === $la['label_name'] && false === Identify::is_mislabeled( $la ) && 'Orange Test Dart' === $la['species'] );
$found = false;
foreach ( $store->species_candidates() as $candidate ) {
	if ( 27 === $candidate['product_id'] && in_array( 'Orange Test Dart', array_column( $candidate['variants'], 'text' ), true ) ) {
		$found = true;
	}
}
check( 'the name it was typed under now finds the product in the fish search — no alias row needed', $found && array() === $store->aliases() );
$after = fh_np_gather( $store );
check( 'the fish has left the list; the chip counts one less', null === fh_np_named( $after['fish'], 'Orange Test Dart' ) && 6 === Admin_No_Product::board_count( $store, $store->room( $today, $settings, $protocols )['tanks'] ) );
$price = Admin_Workbook::resolve_order( $store, $store->order( $w['a'] ) )['lines'][8];
check( 'every number of the order is what it was: the landed cost, the typed price', near( $price['landed_per_fish'], $resolved['lines'][8]['landed_per_fish'] ) && near( $price['worth'], 45 ) && near( Admin_Workbook::resolve_order( $store, $store->order( $w['a'] ) )['costing']['total'], $resolved['costing']['total'] ) );

$again = Admin_No_Product::link_request( $store, array( 'key' => 'n:orange test dart', 'name' => 'Orange Test Dart', 'product_id' => '22' ), $today, $settings );
check( 'a second link from a page that still shows the fish is refused plainly — it has a product', false === $again['ok'] && 409 === $again['status'] && false !== strpos( $again['message'], 'has a product already' ) && 27 === $store->species_by_name( 'Orange Test Dart' )['product_id'] );
check( 'a product in the trash, a deleted one or none cannot be linked to', false === Admin_No_Product::link_request( $store, array( 'key' => 's:2', 'name' => 'Blue Test Tang', 'product_id' => '23' ), $today, $settings )['ok'] && false === Admin_No_Product::link_request( $store, array( 'key' => 's:2', 'product_id' => '99' ), $today, $settings )['ok'] && false === Admin_No_Product::link_request( $store, array( 'key' => 's:2' ), $today, $settings )['ok'] && null === $store->species( 2 )['product_id'] );

$tang_link = Admin_No_Product::link_request( $store, array( 'key' => 's:2', 'name' => 'Blue Test Tang', 'product_id' => '22' ), $today, $settings );
$la        = $store->order( $w['a'] )['lines'];
check( 'a fish that HAS a species-list row: the row is linked (the Species tab\'s own link), the line only typed with its name gets the row\'s id, both groups follow', true === $tang_link['ok'] && 22 === $store->species( 2 )['product_id'] && 2 === $la[2]['species_id'] && 'blue test tang' === $la[2]['species'] && 2 === count( $tang_link['stays'] ) && false === strpos( $tang_link['message'], 'added to the species list' ) );
check( '… its line on the closed order follows too (through the row), though closed orders were never read for the list', 22 === $store->order( $w['b'] )['lines'][1]['species_product_id'] );

$elsewhere = Admin_No_Product::link_request( $store, array( 'key' => 's:1', 'name' => 'lined test wrasse', 'product_id' => '27' ), $today, $settings );
check( 'a fish the species list ties to a product already (only its group lacks the link) cannot be linked to ANOTHER product from here: every line of it would move too', false === $elsewhere['ok'] && 409 === $elsewhere['status'] && false !== strpos( $elsewhere['message'], 'already has the product "Lined Test Wrasse"' ) && 21 === $store->species( 1 )['product_id'] && null === $store->stay( $w['loose'], $today, $settings )['product_id'] );
$offer = Admin_No_Product::link_request( $store, array( 'key' => 's:1', 'name' => 'lined test wrasse', 'product_id' => '21' ), $today, $settings );
check( 'the offered product, one click: the group typed as unlisted gets the link its fish already had', true === $offer['ok'] && 21 === $store->stay( $w['loose'], $today, $settings )['product_id'] && array() === $offer['lines'] && 'lined test wrasse' === $store->stay( $w['loose'], $today, $settings )['species'] );

check( 'a fish that is on no list is refused', 409 === Admin_No_Product::link_request( $store, array( 'key' => 'n:no such test fish', 'name' => 'No Such Test Fish', 'product_id' => '21' ), $today, $settings )['status'] );
check( 'link() itself refuses a "??" fish and a link to nothing', false === No_Product::link( $store, array( 'name' => '?? x' ), array(), array(), 21 )['ok'] && false === No_Product::link( $store, array( 'name' => 'Plain Test Goby' ), array(), array(), 0 )['ok'] );

/* ------------------------------------------------------------------ */
echo "8. A page that was open before the link does not undo it\n";

$before = array( 5 => array( 'species' => 'Orange Test Dart', 'species_id' => 12, 'needs_id' => false ) );
$keep   = static function ( array $line ) use ( $before ) {
	return No_Product::keep_species_ids( array( array_merge( array( 'id' => 5, 'needs_id' => false ), $line ) ), $before )[0]['species_id'];
};
check( 'pure: a line posted without its species-list id, naming the fish it already is, keeps the id it has — case and spacing aside', 12 === $keep( array( 'species' => 'Orange Test Dart', 'species_id' => null ) ) && 12 === $keep( array( 'species' => 'orange  test dart', 'species_id' => null ) ) );
check( '… a line typed to another fish, ticked "??", or picked from the list anew is taken as typed', null === $keep( array( 'species' => 'Red Test Goby', 'species_id' => null ) ) && null === $keep( array( 'species' => '?? Orange Test Dart', 'species_id' => null, 'needs_id' => true ) ) && 7 === $keep( array( 'species' => 'Orange Test Dart', 'species_id' => 7 ) ) );
check( '… and a new line, or one that had no id, is left alone', null === No_Product::keep_species_ids( array( array( 'id' => 0, 'species' => 'Orange Test Dart', 'species_id' => null ) ), $before )[0]['species_id'] && null === No_Product::keep_species_ids( array( array( 'id' => 5, 'species' => 'Orange Test Dart', 'species_id' => null ) ), array( 5 => array( 'species' => 'Orange Test Dart', 'species_id' => null ) ) )[0]['species_id'] );

// The draft order C as its page held it before the link: the line's hidden species-list id is empty.
$stale = fh_np_save( $store, array( fh_np_row( 'Orange Test Dart', array( 'id' => (string) $lc['id'], 'species_id' => '', 'size_slug' => '2-3', 'worth' => '50' ) ) ), array( 'order_date' => '2026-11-01', 'status' => 'draft' ), $w['c'] );
$lc2   = $store->order( $w['c'] )['lines'][0];
check( 'the order page saved from before the link: the line is still tied to its row, and so to its product — the tag does not come back', (int) $row['id'] === $lc2['species_id'] && 27 === $lc2['species_product_id'] && '' === $stale['figures']['lines'][0]['no_product'] );
$retyped = fh_np_save( $store, array( fh_np_row( 'Mint Test Goby', array( 'id' => (string) $lc['id'], 'species_id' => '', 'size_slug' => '2-3' ) ) ), array( 'order_date' => '2026-11-01', 'status' => 'draft' ), $w['c'] );
check( '… while a line really typed to another fish is that other fish', null === $store->order( $w['c'] )['lines'][0]['species_id'] && 'no product yet' === $retyped['figures']['lines'][0]['no_product'] );

/* ------------------------------------------------------------------ */
echo "9. What a draft product is made of\n";

$standard = Sizes::standard( $store, $settings );
$plan     = No_Product::draft_plan( $dart, $standard );
check( 'the name as typed, the scientific name — also as the italic line under the title —, a draft in the fish category', 'Orange Test Dart' === $plan['name'] && 'Testus aurantius' === $plan['sci_name'] && '<em>Testus aurantius</em>' === $plan['short_description'] && 'draft' === $plan['status'] && 'quarantined-fish' === $plan['category'] );
check_same( 'one variation per size seen, in the standard sizes\' order, on the shop\'s size attribute — each at the price planned for THAT size', array( 'pa_size', array( array( 'slug' => '2-3', 'label' => '2-3"', 'price' => '50.00' ), array( 'slug' => '3-4', 'label' => '3-4"', 'price' => '45.00' ) ) ), array( $plan['taxonomy'], $plan['sizes'] ) );
check( 'the planned prices of the fish: 45 typed on the 3-4" line of the received order, 50 on the 2-3" line of the draft', array( array( 'slug' => '2-3', 'label' => '2-3"', 'price' => 50.0 ), array( 'slug' => '3-4', 'label' => '3-4"', 'price' => 45.0 ) ) === $dart['planned_sizes'] && 2 === $plan['priced'] && array() === $plan['unplaced'] );
$one_size = No_Product::draft_plan( array( 'name' => 'Twosize Test Anthias', 'sizes' => array( array( 'slug' => '1-2', 'label' => '1-2"' ), array( 'slug' => '2-3', 'label' => '2-3"' ) ), 'planned' => 59.99, 'planned_sizes' => array( array( 'slug' => '1-2', 'label' => '1-2"', 'price' => 59.99 ) ) ), $standard );
check( 'a price typed on ONE size goes on that size only — the other variation is left empty, never guessed', array( '59.99', '' ) === array_column( $one_size['sizes'], 'price' ) && 1 === $one_size['priced'] && in_array( 'the price of the sizes that have none, and a look at the others', $one_size['left'], true ) );
check( '… and the reply says which size has a price and which has none', false !== strpos( No_Product::draft_message( $one_size, 'create' ), 'Sizes: 1-2" at the planned price of 59.99; 2-3" with no price (none was typed for that size).' ) && false === strpos( No_Product::draft_message( $one_size, 'create' ), 'each at' ) );
check( 'the marker a second click finds it by: the fish\'s name, case and spacing aside', 'orange test dart' === $plan['marker'] && No_Product::draft_plan( array( 'name' => '  ORANGE   Test dart ' ), $standard )['marker'] === $plan['marker'] );

$plan_tang = No_Product::draft_plan( $tang, $standard );
check( 'no planned price: the price is left empty — never a guessed one, though a target price is known', array( '', '' ) === array_column( $plan_tang['sizes'], 'price' ) && 0 === $plan_tang['priced'] && null !== $tang['target'] && 2 === count( $plan_tang['sizes'] ) && in_array( 'the price of each size (none was typed on the fish order)', $plan_tang['left'], true ) );
$plan_grey = No_Product::draft_plan( $grey, $standard );
check( 'no size seen: no variation, nowhere to put a price — and the plan says so; no scientific name: said too', array() === $plan_grey['sizes'] && '' === $plan_grey['taxonomy'] && 0 === $plan_grey['priced'] && '' === $plan_grey['short_description'] && in_array( 'its sizes and their prices (none was known here)', $plan_grey['left'], true ) && in_array( 'the scientific name', $plan_grey['left'], true ) );
$no_size = No_Product::draft_plan( array( 'name' => 'Plain Test Goby', 'planned' => 30, 'planned_sizes' => array( array( 'slug' => '', 'label' => '', 'price' => 30 ) ), 'sizes' => array() ), $standard );
check( '… a planned price without a size cannot be put on the product: "price not set", plainly', array( '30.00' ) === $no_size['unplaced'] && 0 === $no_size['priced'] && false !== strpos( No_Product::draft_message( $no_size, 'create' ), 'Price not set.' ) && false !== strpos( No_Product::draft_message( $no_size, 'create' ), 'The planned price of 30.00 was typed on a line that is no size of the product, so it was put nowhere' ) );
$mixed = No_Product::draft_plan( array( 'name' => 'Plain Test Goby', 'sizes' => array( array( 'slug' => '2-3', 'label' => '2-3"' ) ), 'planned_sizes' => array( array( 'slug' => '', 'label' => '', 'price' => 30 ) ) ), $standard );
check( '… nor is a price typed on a line without a size put on the sizes the fish has elsewhere', array( '' ) === array_column( $mixed['sizes'], 'price' ) && array( '30.00' ) === $mixed['unplaced'] && 0 === $mixed['priced'] );
$listed = No_Product::draft_plan( $dart, array( 'attribute' => '', 'sizes' => array( array( 'slug' => '2-3', 'label' => '2-3"' ) ) ) );
check( 'the sizes are a typed list, not the shop\'s attribute: no variation can be made — they are named as left out', array() === $listed['sizes'] && array( '2-3"', '3-4"' ) === $listed['skipped'] && 0 === $listed['priced'] && array( '50.00', '45.00' ) === $listed['unplaced'] );
$odd = No_Product::draft_plan( array( 'name' => 'Plain Test Goby', 'sizes' => array( array( 'slug' => '2-3', 'label' => '2-3"' ), array( 'slug' => 'jumbo', 'label' => 'Jumbo' ) ) ), $standard );
check( 'a size that is no longer one of the shop\'s sizes is left out and named', array( array( 'slug' => '2-3', 'label' => '2-3"', 'price' => '' ) ) === $odd['sizes'] && array( 'Jumbo' ) === $odd['skipped'] && false !== strpos( No_Product::draft_message( $odd, 'create' ), 'Jumbo' ) );
check( 'a scientific name is plain text in the short description', '<em>Testus &lt;b&gt;</em>' === No_Product::draft_plan( array( 'name' => 'x', 'sci_name' => 'Testus <b>' ), $standard )['short_description'] );
$said = No_Product::draft_message( $plan, 'create' );
check( 'what it did and what is left, in plain words', false !== strpos( $said, 'A draft product "Orange Test Dart" was made and the fish is linked to it.' ) && false !== strpos( $said, 'Sizes: 2-3" at the planned price of 50.00; 3-4" at the planned price of 45.00.' ) && false !== strpos( $said, 'It is a draft: customers cannot see it.' ) && false !== strpos( $said, 'a photo' ) && false !== strpos( $said, 'the description' ) && false !== strpos( $said, 'Publish' ) && false !== strpos( No_Product::draft_message( $plan_tang, 'create' ), 'Price not set.' ) );

check_same( 'the decision: the product the fish has is opened', array( 'act' => 'open', 'product_id' => 31 ), No_Product::draft_decision( 31, 44 ) );
check_same( '… a draft started for it before is adopted', array( 'act' => 'adopt', 'product_id' => 44 ), No_Product::draft_decision( null, 44 ) );
check_same( '… a product the shop has under exactly that name is named, never doubled', array( 'act' => 'twin', 'product_id' => 77 ), No_Product::draft_decision( null, 0, 77 ) );
check( '… the fish\'s own product and its own draft come before a namesake', 'open' === No_Product::draft_decision( 31, 0, 77 )['act'] && 'adopt' === No_Product::draft_decision( null, 44, 77 )['act'] );
check_same( '… and only otherwise one is made', array( 'act' => 'create', 'product_id' => 0 ), No_Product::draft_decision( null, 0 ) );

/* ------------------------------------------------------------------ */
echo "10. Start a draft product — and a second click makes no second product\n";

$w2     = fh_np_world();
$store2 = $w2['store'];
$shop   = new Fh_Test_Shop( $store2 );
$nonce  = wp_create_nonce( Admin_No_Product::NONCE_DRAFT );
$post   = array( 'nonce' => $nonce, 'key' => 'n:orange test dart', 'name' => 'Orange Test Dart' );

$GLOBALS['fh_current_user'] = 8;
$denied                     = Admin_No_Product::draft_request( $store2, $shop, $post, $today, $settings );
$GLOBALS['fh_current_user'] = 7;
check( 'it needs the right to edit products: the section\'s own capability is not enough', false === $denied['ok'] && 403 === $denied['status'] && array() === $shop->made );
$bad = Admin_No_Product::draft_request( $store2, $shop, array_merge( $post, array( 'nonce' => wp_create_nonce( Admin::NONCE_AJAX ) ) ), $today, $settings );
check( '… and its own nonce: the page\'s other nonce is refused', false === $bad['ok'] && 403 === $bad['status'] && array() === $shop->made && false === Admin_No_Product::draft_request( $store2, $shop, array( 'key' => 'n:orange test dart' ), $today, $settings )['ok'] );

$first = Admin_No_Product::draft_request( $store2, $shop, $post, $today, $settings );
$id    = (int) $first['product_id'];
check( 'the first click makes ONE product, as a draft', true === $first['ok'] && 'create' === $first['act'] && 1 === count( $shop->made ) && $id > 0 && 'draft' === $shop->made[ $id ]['status'] && 'draft' === $store2->product_statuses( array( $id ) )[ $id ], json_encode( $first ) );
check( '… made of the fish\'s name, scientific name, sizes and planned price', 'Orange Test Dart' === $shop->made[ $id ]['name'] && 'Testus aurantius' === $shop->made[ $id ]['sci_name'] && array( '2-3', '3-4' ) === array_column( $shop->made[ $id ]['sizes'], 'slug' ) && array( '50.00', '45.00' ) === array_column( $shop->made[ $id ]['sizes'], 'price' ) && 2 === $shop->made[ $id ]['priced'] );
check( '… the fish is linked to it — its lines and its group — and the reply says so and leads to the product', $id === $store2->species_by_name( 'Orange Test Dart' )['product_id'] && $id === $store2->order( $w2['a'] )['lines'][8]['species_product_id'] && $id === $store2->order( $w2['c'] )['lines'][0]['species_product_id'] && false !== strpos( $first['edit_url'], 'post=' . $id ) && false !== strpos( $first['message'], 'was made and the fish is linked to it' ) && false !== strpos( $first['message'], 'Left to do in the shop' ) );
$drafted = fh_np_gather( $store2 );
check( 'a draft counts as a product: the fish has left the list', null === fh_np_named( $drafted['fish'], 'Orange Test Dart' ) && 6 === count( $drafted['fish'] ) );

$second = Admin_No_Product::draft_request( $store2, $shop, $post, $today, $settings );
check( 'a second click — the same row, still on a page loaded before — makes NO second product: it opens the first', true === $second['ok'] && 'open' === $second['act'] && $id === $second['product_id'] && 1 === count( $shop->made ) && $first['edit_url'] === $second['edit_url'] && false !== strpos( $second['message'], 'nothing new was made' ) );
$third = Admin_No_Product::draft_request( $store2, $shop, array( 'nonce' => $nonce, 'key' => 's:' . (int) $store2->species_by_name( 'Orange Test Dart' )['id'], 'name' => 'Orange Test Dart' ), $today, $settings );
check( '… and so does a click from a page loaded after, under the fish\'s new key', 'open' === $third['act'] && $id === $third['product_id'] && 1 === count( $shop->made ) );

// The first click made the product, but the link never got written (the request died there).
$w3     = fh_np_world();
$store3 = $w3['store'];
$shop3  = new Fh_Test_Shop( $store3 );
$store3->products[9500]         = 'Renamed Test Draft';
$store3->product_statuses[9500] = 'draft';
$store3->product_meta[9500]     = array( No_Product::DRAFT_META => 'orange test dart' );
$adopt                          = Admin_No_Product::draft_request( $store3, $shop3, $post, $today, $settings );
check( 'a draft that was started for the fish before (found by its marker, whatever it is called now) is adopted: nothing new, the fish linked to it', true === $adopt['ok'] && 'adopt' === $adopt['act'] && 9500 === $adopt['product_id'] && array() === $shop3->made && 9500 === $store3->species_by_name( 'Orange Test Dart' )['product_id'] && false !== strpos( $adopt['message'], 'was started here before' ) );

$w4     = fh_np_world();
$store4 = $w4['store'];
$shop4  = new Fh_Test_Shop( $store4 );
$store4->products[9500]         = 'Orange Test Dart';
$store4->product_statuses[9500] = 'trash';
$store4->product_meta[9500]     = array( No_Product::DRAFT_META => 'orange test dart' );
$fresh                          = Admin_No_Product::draft_request( $store4, $shop4, $post, $today, $settings );
check( '… but one that was thrown in the trash is not: a product in the trash is none, a new draft is made', 'create' === $fresh['act'] && 1 === count( $shop4->made ) && 9500 !== $fresh['product_id'] );

$offered = Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 's:1', 'name' => 'lined test wrasse' ), $today, $settings );
check( 'a fish the shop has a product for already (only its group lacks the link) gets no draft: it is linked to that product', 'open' === $offered['act'] && 21 === $offered['product_id'] && 1 === count( $shop4->made ) && 21 === $store4->stay( $w4['loose'], $today, $settings )['product_id'] );

$grey_draft = Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 'n:grey test dartfish', 'name' => 'Grey Test Dartfish' ), $today, $settings );
$grey_id    = (int) $grey_draft['product_id'];
check( 'a fish that is only in a tank, with no size: a draft without sizes, "Price not set", its group linked', 'create' === $grey_draft['act'] && array() === $shop4->made[ $grey_id ]['sizes'] && false !== strpos( $grey_draft['message'], 'No size was known' ) && false !== strpos( $grey_draft['message'], 'Price not set.' ) && $grey_id === $store4->stay( $w4['grey'], $today, $settings )['product_id'] && $grey_id === $store4->species_by_name( 'Grey Test Dartfish' )['product_id'] );

$shop4->fail = true;
$failed      = Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 's:2', 'name' => 'Blue Test Tang' ), $today, $settings );
check( 'the shop cannot make it: said, nothing linked, and the next click may try again', false === $failed['ok'] && 500 === $failed['status'] && 'the test shop is closed' === $failed['message'] && null === $store4->species( 2 )['product_id'] && array() === $store4->locks && false === get_transient( Admin_No_Product::LOCK_TRANSIENT . md5( 'blue test tang' ) ) );
$store4->locks['draft:blue test tang'] = true; // Another click holds the database's lock for this fish.
$shop4->fail                           = false;
$busy                                  = Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 's:2', 'name' => 'Blue Test Tang' ), $today, $settings );
check( 'two clicks that arrive together: the lock is taken or refused in one step — the second is told one is being made, and makes none', false === $busy['ok'] && 409 === $busy['status'] && false !== strpos( $busy['message'], 'being made right now' ) && 2 === count( $shop4->made ) && array( 'draft:blue test tang' => true ) === $store4->locks );
$store4->locks = array();
check( '… every way out of the request gives the lock back: made, opened, refused', array() === $store3->locks && array() === $store2->locks && array() === $store4->locks );
$store4->has_locks = false; // A database without named locks: the short transient stands in, as before.
set_transient( Admin_No_Product::LOCK_TRANSIENT . md5( 'blue test tang' ), 1, 60 );
$busy2 = Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 's:2', 'name' => 'Blue Test Tang' ), $today, $settings );
check( '… where the database has no such lock, the transient still stops the second click', false === $busy2['ok'] && 409 === $busy2['status'] && 2 === count( $shop4->made ) );
delete_transient( Admin_No_Product::LOCK_TRANSIENT . md5( 'blue test tang' ) );
$store4->has_locks = true;
$admin_src         = (string) file_get_contents( Clean_Room::path() . 'class-admin-no-product.php' );
check( 'the real lock is the database\'s GET_LOCK, asked without waiting; the fish is read again once it is held', false !== strpos( (string) file_get_contents( Clean_Room::path() . 'class-store.php' ), "'SELECT GET_LOCK(%s, 0)'" ) && strpos( $admin_src, '$store->lock( $name )' ) < strpos( $admin_src, '// Read the fish again, now that no other click can be at work on it' ) );
check( 'a fish that is on no list gets no product', 409 === Admin_No_Product::draft_request( $store4, $shop4, array( 'nonce' => $nonce, 'key' => 'n:no such test fish', 'name' => 'No Such Test Fish' ), $today, $settings )['status'] && 2 === count( $shop4->made ) );

/* ------------------------------------------------------------------ */
echo "11. The edge: one action, and the shop's product shape in one place\n";

$src = (string) file_get_contents( Clean_Room::path() . 'class-admin-no-product.php' );
check( 'the product is made as a draft and never published here', 1 === substr_count( $src, "set_status( 'draft' )" ) && false === strpos( $src, "'publish'" ) && false === strpos( $src, 'wp_publish_post' ) );
check( 'shaped as this shop\'s fish products: with variations, the category on the product itself, the scientific name in its meta, stock managed from 0, the price on the variations — and no shipping class set', false !== strpos( $src, 'new \WC_Product_Variable()' ) && false !== strpos( $src, 'set_category_ids' ) && false !== strpos( $src, 'No_Product::SCI_META' ) && '_fh_scientific_name' === No_Product::SCI_META && false !== strpos( $src, 'set_manage_stock( true )' ) && false !== strpos( $src, 'set_stock_quantity( 0 )' ) && false !== strpos( $src, '$variation->set_regular_price' ) && false === strpos( $src, 'set_shipping_class_id' ) );
check( 'no $wpdb outside the store, and nothing of WooCommerce in the pure rule', false === strpos( $src, '$wpdb' ) && false === strpos( (string) file_get_contents( Clean_Room::path() . 'class-no-product.php' ), '$wpdb' ) && false === strpos( (string) file_get_contents( Clean_Room::path() . 'class-no-product.php' ), 'WC_' ) );
$boot = (string) file_get_contents( Clean_Room::path() . 'class-clean-room.php' );
check( 'the section loads both files; the admin one only in wp-admin, after the switch', false !== strpos( $boot, "'class-no-product.php'" ) && strpos( $boot, "'class-admin-no-product.php'" ) > strpos( $boot, 'if ( is_admin() ) {' ) && false !== strpos( $boot, '$no_product->init();' ) );

/* ------------------------------------------------------------------ */
echo "12. A link never rewrites a closed fish order\n";

$w5     = fh_np_world();
$store5 = $w5['store'];
$perbox = (int) $store5->save_species( array( 'name' => 'Perbox Test Goby', 'per_box' => 6, 'group_name' => 'Gobies' ) );
$old    = fh_np_save( $store5, array( fh_np_row( 'Perbox Test Goby', array( 'per_box' => '', 'qty_ordered' => '12', 'qty_received' => '12', 'worth' => '40' ) ), fh_np_row( 'Other Closed Test Fish', array( 'per_box' => '', 'qty_ordered' => '12', 'qty_received' => '12', 'worth' => '40' ) ) ), array( 'order_date' => '2026-02-01', 'received_date' => '2026-02-03', 'status' => 'closed', 'boxes' => 2, 'domestic_flight' => '100' ) );
$open   = fh_np_save( $store5, array( fh_np_row( 'Perbox Test Goby', array( 'per_box' => '' ) ) ), array( 'order_date' => '2026-11-10' ) );
$costs  = static function ( $store, $id ) {
	$order = Admin_Workbook::resolve_order( $store, $store->order( $id ) );
	return array( array_column( $order['lines'], 'landed_per_fish' ), array_column( $order['lines'], 'suggested_price' ), array_column( $order['lines'], 'group_name' ) );
};
$before5 = $costs( $store5, $old['order_id'] );
$tied    = clone $store5; // What the link did before: the closed line got the row's id — and with it the row's per box and group.
$tied->update_line_fields( (int) $tied->order( $old['order_id'] )['lines'][0]['id'], array( 'species_id' => $perbox ) );
check( 'the fault, shown: tying a closed order\'s line to the species row changes what BOTH its fish cost landed, and its group', $before5 !== $costs( $tied, $old['order_id'] ) && ! near( $before5[0][0], $costs( $tied, $old['order_id'] )[0][0] ) && ! near( $before5[0][1], $costs( $tied, $old['order_id'] )[0][1] ) );

$link5 = Admin_No_Product::link_request( $store5, array( 'key' => 's:' . $perbox, 'name' => 'Perbox Test Goby', 'product_id' => '22' ), $today, $settings );
check( 'the fish is linked; its line on the OPEN order follows through the row', true === $link5['ok'] && 22 === $store5->species( $perbox )['product_id'] && $perbox === $store5->order( $open['order_id'] )['lines'][0]['species_id'] && array( (int) $store5->order( $open['order_id'] )['lines'][0]['id'] ) === $link5['lines'] );
check( 'its line on the CLOSED order is not written on: no species-list id', null === $store5->order( $old['order_id'] )['lines'][0]['species_id'] );
check_same( '… so every number of the closed order — landed per fish, target price, group — is what it was', $before5, $costs( $store5, $old['order_id'] ) );
$names5  = Admin_No_Product::names( $store5 );
$listed5 = No_Product::listed_products( $store5->species_brief() );
$closed5 = $store5->order( $old['order_id'] );
check( 'the closed line still counts as having the product: it reads it through the species-list row of its name', 22 === $listed5['perbox test goby'] && 'linked' === No_Product::line_state( No_Product::through_list( $closed5, $closed5['lines'][0], $listed5 ), $names5, null ) && 'none' === No_Product::line_state( $closed5['lines'][0], $names5, null ) );
check( '… only a closed order\'s line that carries no row of its own does: an open order\'s line is tied instead, and a tied line keeps its own row', $closed5['lines'][0] !== No_Product::through_list( $closed5, $closed5['lines'][0], $listed5 ) && array( 'species' => 'Perbox Test Goby' ) === No_Product::through_list( array( 'status' => 'ordered' ), array( 'species' => 'Perbox Test Goby' ), $listed5 ) && array( 'species' => 'Perbox Test Goby', 'species_id' => 99 ) === No_Product::through_list( array( 'status' => 'closed' ), array( 'species' => 'Perbox Test Goby', 'species_id' => 99 ), $listed5 ) );
$all5 = fh_np_gather( $store5, array( 'closed_too' => true ), array() );
check( 'with the closed orders shown, the fish has left the list for good — and its neighbour on the closed order is still there', null === fh_np_named( $all5['fish'], 'Perbox Test Goby' ) && null !== fh_np_named( $all5['fish'], 'Other Closed Test Fish' ) );
check( 'link() itself leaves a line alone when its order is closed', array( 5 ) === No_Product::link( $store5, array( 'name' => 'Perbox Test Goby', 'species_id' => $perbox ), array( array( 'id' => 5, 'species' => 'Perbox Test Goby', 'species_id' => null, 'order_status' => 'ordered' ), array( 'id' => 6, 'species' => 'Perbox Test Goby', 'species_id' => null, 'order_status' => 'closed' ) ), array(), 22 )['lines'] );

/* ------------------------------------------------------------------ */
echo "13. A product the shop already has under the fish's name is never doubled\n";

$w6     = fh_np_world();
$store6 = $w6['store'];
$shop6  = new Fh_Test_Shop( $store6 );
$store6->products[40]         = 'Nocat Test Blenny';   // Made by hand, in no fish category.
$store6->products[41]         = 'Binned Test Blenny';  // A namesake in the trash.
$store6->product_statuses[41] = 'trash';
$store6->not_fish             = array( 40, 41 );
fh_np_save( $store6, array( fh_np_row( 'Nocat Test Blenny', array( 'size_slug' => '1-2', 'worth' => '25' ) ), fh_np_row( 'Binned Test Blenny' ) ), array( 'order_date' => '2026-11-12' ) );
check_same( 'the products called exactly like a name, in any category, the trash left out', array( 'nocat test blenny' => array( 40 ) ), $store6->products_titled( array( 'nocat  test BLENNY', 'Binned Test Blenny', 'Nobody Test Fish' ) ) );
$g6 = fh_np_gather( $store6 );
check( 'the rule does not see a product outside the fish categories: the fish is on the list, nothing offered by the rule', null !== fh_np_named( $g6['fish'], 'Nocat Test Blenny' ) && null === fh_np_named( $g6['fish'], 'Nocat Test Blenny' )['candidate'] );
$d6    = Admin_No_Product::data( $store6, array(), $today, $settings );
$nocat = fh_np_named( $d6['rows'], 'Nocat Test Blenny' );
check( 'the list finds it by its name and offers it — one read for the page', 40 === $nocat['candidate'] && true === $nocat['elsewhere'] && 1 === $nocat['twins'] && 'Nocat Test Blenny' === $d6['titles'][40] && null === fh_np_named( $d6['rows'], 'Binned Test Blenny' )['candidate'] && 0 === fh_np_named( $d6['rows'], 'Binned Test Blenny' )['twins'] );
$html6 = fh_np_page( $store6, array( 'tab' => 'species', 'view' => 'no-product' ) );
$from6 = strpos( $html6, 'data-name="Nocat Test Blenny"' );
$row6  = substr( $html6, $from6, strpos( $html6, '</tr>', $from6 ) - $from6 );
check( '… the row says so, offers "Link to it" and another pick — and no draft', false !== strpos( $row6, 'The shop has a product called &quot;Nocat Test Blenny&quot; (#40), but not in a fish category' ) && 1 === substr_count( $row6, 'fh-cr-np-candidate" data-product="40"' ) && false !== strpos( $row6, 'Link to another product' ) && false === strpos( $row6, 'fh-cr-np-draft' ) );
$twin = Admin_No_Product::draft_request( $store6, $shop6, array( 'nonce' => $nonce, 'key' => 'n:nocat test blenny', 'name' => 'Nocat Test Blenny' ), $today, $settings );
check( 'a click on "Start a draft product" from a page that still offers it makes NO second product: the one the shop has is named', false === $twin['ok'] && 409 === $twin['status'] && array() === $shop6->made && false !== strpos( $twin['message'], 'already has a product called "Nocat Test Blenny" (#40)' ) && false !== strpos( $twin['message'], 'no second one was made' ) && null === $store6->species_by_name( 'Nocat Test Blenny' ) && array() === $store6->locks );
$store6->product_statuses[40] = 'draft';
check( '… a draft of that name too, said as one', false !== strpos( Admin_No_Product::draft_request( $store6, $shop6, array( 'nonce' => $nonce, 'key' => 'n:nocat test blenny', 'name' => 'Nocat Test Blenny' ), $today, $settings )['message'], '(#40, a draft)' ) && array() === $shop6->made );
$binned = Admin_No_Product::draft_request( $store6, $shop6, array( 'nonce' => $nonce, 'key' => 'n:binned test blenny', 'name' => 'Binned Test Blenny' ), $today, $settings );
check( '… while a namesake in the trash is none: that fish gets its draft', true === $binned['ok'] && 'create' === $binned['act'] && 1 === count( $shop6->made ) );
$to_twin = Admin_No_Product::link_request( $store6, array( 'key' => 'n:nocat test blenny', 'name' => 'Nocat Test Blenny', 'product_id' => '40' ), $today, $settings );
check( '"Link to it" links the fish to the product it had all along, and it leaves the list', true === $to_twin['ok'] && 40 === $store6->species_by_name( 'Nocat Test Blenny' )['product_id'] && null === fh_np_named( fh_np_gather( $store6 )['fish'], 'Nocat Test Blenny' ) );
$again6 = Admin_No_Product::draft_request( $store6, $shop6, array( 'nonce' => $nonce, 'key' => 'n:twin test chromis', 'name' => 'Twin Test Chromis' ), $today, $settings );
check( 'a fish with two same-named fish products gets no third', false === $again6['ok'] && 409 === $again6['status'] && 1 === count( $shop6->made ) && false !== strpos( $again6['message'], '(#25)' ) );

/* ------------------------------------------------------------------ */
echo "14. The fish search: every category under the fish categories, a near spelling, and a plain answer\n";

check_same( 'the fish categories with EVERY category under them, however deep — and no other', array( 73, 80, 19, 20, 21 ), Store::with_descendants( array( 73, 80 ), array( 19 => 73, 20 => 19, 21 => 20, 30 => 76, 31 => 30 ) ) );
check_same( '… a loop in the categories does not hang it', array( 73, 19, 20 ), Store::with_descendants( array( 73 ), array( 19 => 73, 20 => 19, 73 => 20 ) ) );
$store_src = (string) file_get_contents( Clean_Room::path() . 'class-store.php' );
check( '… and the store reads the fish products, the name index and the search by them', false !== strpos( $store_src, '$this->fish_term_ids = self::with_descendants( $ids, $parent_of );' ) );

$cands = array(
	array( 'product_id' => 10, 'name' => 'Filamented Test Flasher Wrasse', 'variants' => array( array( 'text' => 'Filamented Test Flasher Wrasse', 'kind' => 'title' ), array( 'text' => 'Paracheilinus exemplaris', 'kind' => 'sci' ) ) ),
	array( 'product_id' => 11, 'name' => 'Carpenter Test Flasher Wrasse', 'variants' => array( array( 'text' => 'Carpenter Test Flasher Wrasse', 'kind' => 'title' ) ) ),
	array( 'product_id' => null, 'name' => 'Blue Test Tang', 'variants' => array( array( 'text' => 'Blue Test Tang', 'kind' => 'title' ) ) ),
);
$near_names = static function ( $term ) use ( $cands ) {
	return array_column( Species::near( $term, $cands ), 'name' );
};
check_same( 'a word one letter off still finds the fish — the owner\'s own "filimented flasher wrasse"', array( 'Filamented Test Flasher Wrasse' ), $near_names( 'filimented flasher wrasse' ) );
check_same( '… while it is still being typed', array( 'Filamented Test Flasher Wrasse' ), $near_names( 'filimen' ) );
check_same( '… two letters off in a long word, a dropped letter in a short one', array( array( 'Filamented Test Flasher Wrasse' ), array( 'Carpenter Test Flasher Wrasse', 'Filamented Test Flasher Wrasse' ) ), array( $near_names( 'fillamentid' ), $near_names( 'flasher wrase' ) ) );
check_same( '… by the scientific name too', array( 'Filamented Test Flasher Wrasse' ), $near_names( 'paracheilinus exemplarus' ) );
check( '… every word typed has to be in the fish\'s name; a word under four letters as it stands; a far-off word finds nothing', array() === $near_names( 'filimented tang' ) && array() === $near_names( 'blu tng' ) && array() === $near_names( 'zebra' ) && array() === $near_names( '' ) );
$n = Species::near( 'filimented', $cands );
check_same( '… two letters swapped are one slip ("teh"), not two', array( array( 'Blue Test Tang' ), array( 'Blue Test Tang' ) ), array( $near_names( 'bleu test tang' ), $near_names( 'blue tset tang' ) ) );
check( 'a near match is marked as one, with its product', 'near' === $n[0]['match'] && 10 === $n[0]['product_id'] && 'Paracheilinus exemplaris' === $n[0]['sci'] );

$store7 = new Fake_Store( array( 10 => 'Filamented Test Flasher Wrasse', 11 => 'Carpenter Test Flasher Wrasse', 45 => 'Nocat Test Blenny' ) );
$store7->not_fish = array( 45 );
$store7->save_species( array( 'name' => 'Blue Test Tang', 'sci_name' => 'Testus caeruleus' ) );
$found7 = $store7->species_suggestions( 'filimented flasher wrasse', 8 );
check( 'the search box: nothing letter for letter, so the near spelling answers', 1 === count( $found7 ) && 10 === $found7[0]['product_id'] && 'near' === $found7[0]['match'] );
check( '… a letter-for-letter match is never mixed with near ones', array( 'title', 'title' ) === array_column( $store7->species_suggestions( 'flasher', 8 ), 'match' ) );
$tang7 = $store7->species_suggestions( 'blue tset tang', 8 );
check( '… a species-list row found by a near spelling keeps its own fields', 1 === count( $tang7 ) && 'near' === $tang7[0]['match'] && 1 === $tang7[0]['species_id'] && null === $tang7[0]['product_id'] );
$hint7 = $store7->search_hint( 'Nocat Test', $store7->species_suggestions( 'Nocat Test', 8 ) );
check( 'a product the shop has outside the fish categories is not found — and the box says why, and what to do', array() === $store7->species_suggestions( 'Nocat Test', 8 ) && false !== strpos( $hint7, '"Nocat Test Blenny" (#45)' ) && false !== strpos( $hint7, 'in no fish category (Quarantined Fish, Inverts, or one under them)' ) && false !== strpos( $hint7, 'Tick one on the product' ) );
check( '… nothing is said when the search found fish, or the shop has no such product', '' === $store7->search_hint( 'flasher', $store7->species_suggestions( 'flasher', 8 ) ) && '' === $store7->search_hint( 'zebra', array() ) && '' === Species::elsewhere_hint( array(), Store::FISH_CATEGORIES ) );
$wb_src = (string) file_get_contents( Clean_Room::path() . 'class-admin-workbook.php' );
$fd_src = (string) file_get_contents( Clean_Room::path() . 'class-front-desk.php' );
check( 'both search boxes — wp-admin\'s and the phone\'s — answer with the hint', false !== strpos( $wb_src, "'hint'  => \$hint," ) && false !== strpos( $fd_src, "'hint'  => \$store->search_hint( \$term, \$items )," ) );
check( 'the product box has its own "nothing found": where it looks, how it reads — not the order editor\'s "unlisted species"', false !== strpos( (string) file_get_contents( Clean_Room::path() . 'class-admin.php' ), "'noProduct'      => __( 'No fish product matches. This box looks at the products in Quarantined Fish and Inverts (and every category under them)" ) );

/* ------------------------------------------------------------------ */
echo "15. A wrong pick is undone in one place: the Species tab's link moves the groups too\n";

$w8     = fh_np_world();
$store8 = $w8['store'];
$wrong  = Admin_No_Product::link_request( $store8, array( 'key' => 'n:orange test dart', 'name' => 'Orange Test Dart', 'product_id' => '22' ), $today, $settings ); // The wrong product.
$row8   = $store8->species_by_name( 'Orange Test Dart' );
$stay8  = (int) $wrong['stays'][0];
$other8 = fh_np_group( $store8, 123, 'Orange Test Dart', 2, 21 ); // A group of that name the owner linked elsewhere by hand.
$events = $store8->event_count();
$store8->link_species_product( (int) $row8['id'], 27 ); // Species → Link product: the right one.
$moved = Admin_No_Product::species_relinked( $store8, $row8, 27, $today, $settings );
check( 'the group that carried the old product follows the row to the new one; a group linked elsewhere is left', array( $stay8 ) === $moved && 27 === $store8->stay( $stay8, $today, $settings )['product_id'] && 21 === $store8->stay( $other8, $today, $settings )['product_id'] && $events === $store8->event_count() );
check( '… and the lines read the new product through the row, as before', 27 === $store8->order( $w8['a'] )['lines'][8]['species_product_id'] && null === fh_np_named( fh_np_gather( $store8 )['fish'], 'Orange Test Dart' ) );
check( 'the reply says so', 'Linked to "Orange Dartfish (Test)". 1 group in the tanks that carried the old product follows.' === Admin_Workbook::species_link_message( 'Orange Dartfish (Test)', $moved ) && 'Linked to "x".' === Admin_Workbook::species_link_message( 'x', array() ) );
$row8 = $store8->species_by_name( 'Orange Test Dart' );
$store8->link_species_product( (int) $row8['id'], 0 ); // The link removed altogether.
$freed = Admin_No_Product::species_relinked( $store8, $row8, 0, $today, $settings );
$back  = fh_np_named( fh_np_gather( $store8 )['fish'], 'Orange Test Dart' );
check( 'the link removed: the group loses the product too, and the fish — lines and group — is back on the list', array( $stay8 ) === $freed && null === $store8->stay( $stay8, $today, $settings )['product_id'] && null !== $back && array( $stay8 ) === $back['stay_ids'] && 2 === count( $back['line_ids'] ) && 'Product link removed. 1 group in the tanks that carried the old product follows.' === Admin_Workbook::species_link_message( '', $freed ) );
check( 'nothing moves when the row had no product, or keeps the one it had', array() === Admin_No_Product::species_relinked( $store8, array( 'id' => 2, 'name' => 'Blue Test Tang', 'product_id' => null ), 22, $today, $settings ) && array() === No_Product::relink_stays( $store8, array( array( 'id' => 1, 'species' => 'x', 'product_id' => 22 ) ), 22, 22, 1 ) );
check( 'the Species tab\'s own link does it', false !== strpos( $wb_src, 'Admin_No_Product::species_relinked( $store, $before, $product_id' ) );

/* ------------------------------------------------------------------ */
echo "16. The count beside a fish order is the list narrowed to that order\n";

$w9     = fh_np_world();
$store9 = $w9['store'];
$hawk   = fh_np_save( $store9, array( fh_np_row( 'Late Test Hawkfish', array( 'sci_name' => 'Testus tardus', 'size_slug' => '1-2', 'qty_received' => '4' ) ), fh_np_row( 'Mint Test Goby', array( 'qty_received' => '2' ) ), fh_np_row( 'Lined Test Wrasse', array( 'species_id' => '1', 'qty_received' => '2' ) ) ), array( 'order_date' => '2026-11-14', 'received_date' => '2026-11-16', 'status' => 'received' ) );
Orders::check_in( $store9, $store9->order( $hawk['order_id'] ), '2026-11-16', $settings, array( 'actor_id' => 7 ) );
$store9->products[50] = 'Late Test Hawkfish'; // The owner adds the product AFTER the fish went into its tank.
$names9  = Admin_No_Product::names( $store9 );
$order9  = $store9->order( $hawk['order_id'] );
$alive9  = Admin_No_Product::alive_for( $store9, array( $order9 ), array(), $names9 );
check( 'the line now has a product by its name; its group in the tank, checked in before, has none', 'linked' === No_Product::line_state( $order9['lines'][0], $names9, $alive9 ) && null === $order9['lines'][0]['stay_product_id'] && 'Late Test Hawkfish' === $order9['lines'][0]['stay_species'] && true === No_Product::group_lacks( $order9['lines'][0], $alive9 ) );
check( '… a group that has its product, a line with no group, a closed group, a group renamed to another fish: not lacking', false === No_Product::group_lacks( $order9['lines'][2], $alive9 ) && false === No_Product::group_lacks( array( 'species' => 'x' ), null ) && false === No_Product::group_lacks( array( 'species' => 'x', 'stay_id' => 3, 'stay_status' => 'closed', 'stay_species' => 'x' ), null ) && false === No_Product::group_lacks( array( 'species' => 'x', 'stay_id' => 3, 'stay_status' => 'in_qt', 'stay_species' => 'y' ), null ) );
$marked9 = Admin_No_Product::mark_orders( $store9, array( $order9 ) )[0];
$data9   = Admin_No_Product::data( $store9, array( 'order' => (string) $hawk['order_id'] ), $today, $settings );
check_same( 'the count says 2 and the list it opens has 2 rows: the fish whose group lacks the link is counted with the fish that has no product at all', array( 2, 2, array( 'Late Test Hawkfish', 'Mint Test Goby' ) ), array( $marked9['no_product'], $data9['total'], array_column( $data9['rows'], 'name' ) ) );
check_same( '… and both lines carry the tag', array( true, true, false ), array_column( $marked9['lines'], 'no_product' ) );
$late9 = fh_np_named( $data9['rows'], 'Late Test Hawkfish' );
check( '… the row of that group knows what its line knows: the scientific name, the order and its status — and offers the product', 'Testus tardus' === $late9['sci_name'] && array( array( 'id' => $hawk['order_id'], 'ref' => $order9['ref'], 'status' => 'received' ) ) === $late9['orders'] && 50 === $late9['candidate'] && array() === $late9['line_ids'] );
$fix9 = Admin_No_Product::link_request( $store9, array( 'key' => $late9['key'], 'name' => 'Late Test Hawkfish', 'product_id' => '50' ), $today, $settings );
check( '"Link to it" gives the group its link; the count and the list both drop to 1', true === $fix9['ok'] && 1 === Admin_No_Product::mark_orders( $store9, array( $store9->order( $hawk['order_id'] ) ) )[0]['no_product'] && 1 === Admin_No_Product::data( $store9, array( 'order' => (string) $hawk['order_id'] ), $today, $settings )['total'] );
check_same( 'every open order of the room: its count is its list', array( true, true, true ), array_map(
	static function ( $order ) use ( $store9, $today, $settings ) {
		return (int) $order['no_product'] === Admin_No_Product::data( $store9, array( 'order' => (string) $order['id'] ), $today, $settings )['total'];
	},
	array_values( array_filter( Admin_No_Product::mark_orders( $store9, Admin_Workbook::resolved_orders( $store9, array( 'open' => true ) ) ), static function ( $order ) { return 'closed' !== $order['status']; } ) )
) );

// A closed order with one group still in a tank: its count is that one fish — and so is its list.
$shut = fh_np_save( $store9, array( fh_np_row( 'Shut Test Basslet', array( 'qty_received' => '3' ) ), fh_np_row( 'Shut Test Anthias', array( 'qty_received' => '0' ) ), fh_np_row( 'Shut Test Chromis', array( 'qty_received' => '0' ) ) ), array( 'order_date' => '2026-09-01', 'received_date' => '2026-09-03', 'status' => 'received' ) );
Orders::check_in( $store9, $store9->order( $shut['order_id'] ), '2026-09-03', $settings, array( 'actor_id' => 7 ) );
$store9->orders[ $shut['order_id'] ]['status'] = 'closed';
$shut_marked = Admin_No_Product::mark_orders( $store9, array( $store9->order( $shut['order_id'] ) ) )[0];
$shut_list   = Admin_No_Product::data( $store9, array( 'order' => (string) $shut['order_id'] ), $today, $settings );
check_same( 'a closed order with one group still in a tank: "1 without a product", and its link lists that one fish', array( 1, 1, array( 'Shut Test Basslet' ) ), array( $shut_marked['no_product'], $shut_list['total'], array_column( $shut_list['rows'], 'name' ) ) );
$shut_all = Admin_No_Product::data( $store9, array( 'order' => (string) $shut['order_id'], 'closed' => '1' ), $today, $settings );
check( '… every fish of it behind the switch, which keeps the order', 3 === $shut_all['total'] && true === $shut_all['closed_too'] && $shut['order_id'] === $shut_all['order']['id'] );
$shut_html = fh_np_page( $store9, array( 'tab' => 'species', 'view' => 'no-product', 'order' => (string) $shut['order_id'] ) );
check( '… and the page says which it shows', false !== strpos( $shut_html, 'is closed: only its fish that are still alive in a tank' ) && false !== strpos( html_entity_decode( $shut_html ), 'order=' . $shut['order_id'] . '&closed=1' ) && false !== strpos( $shut_html, 'Show every fish of this closed order' ) );

/* ------------------------------------------------------------------ */
echo "17. A tagged order line is no taller than its neighbours; the count never widens the list\n";

$order_src = (string) file_get_contents( Clean_Room::path() . 'views/order.php' );
check( 'the tag stands on the line of "stay #N", in one span', 1 === preg_match( '/<span class="fh-cr-sub fh-cr-line__marks"><\?php if \( \$linked \) : \?><a href=[^\n]*stay #%d[^\n]*<\?php endif; \?><a class="fh-cr-noproduct" data-f="no_product"/', $order_src ) && 1 === substr_count( $order_src, "__( 'stay #%d'" ) );
check( '… and the count beside a fish order may break onto a second line', false !== strpos( (string) file_get_contents( Clean_Room::path() . 'views/arrivals.php' ), 'fh-cr-noproduct fh-cr-noproduct--count' ) && 1 === preg_match( '/\.fh-cr-wrap a\.fh-cr-noproduct--count \{\s*max-width: 100%;\s*white-space: normal;/', $css ) );

fh_finish();
