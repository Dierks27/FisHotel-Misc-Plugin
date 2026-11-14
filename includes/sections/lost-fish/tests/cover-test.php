<?php
/**
 * Cover tests — the maths that decides whether a lost fish is replaced
 * from the extra or somebody is now short: the kind of a tank group
 * (product versus species + size), COVERED across several groups,
 * COMMITTED from the holders (minus refunds and answered cases), the
 * shortage, the fish open cases already speak for, and who is suggested
 * (the most recently paid first).
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/cover-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Cover;

/**
 * A tank group.
 */
function fh_stay( $id, $product_id, $species, $live, $size = '', $status = 'in_qt', $needs_id = false ) {
	return array(
		'id'         => $id,
		'product_id' => $product_id,
		'species'    => $species,
		'size_slug'  => $size,
		'size_label' => '' !== $size ? ucfirst( $size ) : '',
		'qty_live'   => $live,
		'status'     => $status,
		'needs_id'   => $needs_id,
	);
}

/**
 * A holder line.
 */
function fh_holder( $order_id, $ref, $qty, $paid_at, $refunded = 0 ) {
	return array(
		'order_id'     => $order_id,
		'ref'          => $ref,
		'qty'          => $qty,
		'qty_refunded' => $refunded,
		'paid_at'      => $paid_at,
		'name'         => 'Lagoon Goby',
	);
}

/**
 * A case.
 */
function fh_case( $order_id, $ref, $qty, $status, $close_reason = '' ) {
	return array(
		'order_id'     => $order_id,
		'line_ref'     => $ref,
		'qty'          => $qty,
		'status'       => $status,
		'close_reason' => $close_reason,
	);
}

echo "Names and kinds\n";
check_same( 'a name is compared in lower case, punctuation as spaces', 'lagoon goby', Cover::norm( '  Lagoon-Goby! ' ) );
check_same( 'accents and other letters survive', 'gobi doré 2', Cover::norm( 'Gobi Doré (2)' ) );
check_same( 'a product is its own kind, whatever the size', 'p:812', Cover::kind_key( 812, 'Lagoon Goby', 'm' ) );
check_same( 'without a product: species and size together', 's:lagoon goby|m', Cover::kind_key( 0, 'Lagoon Goby', 'M' ) );
check_same( 'neither a product nor a species: no kind', '', Cover::kind_key( 0, '  ', 'm' ) );

$kind_product = Cover::kind_of_stay( fh_stay( 1, 812, 'Lagoon Goby', 3, 'm' ), 'Lagoon Goby (Captive Bred)' );
$kind_species = Cover::kind_of_stay( fh_stay( 2, 0, 'Reef Blenny', 2, 'm' ) );
check_same( 'the kind of a group with a product', 'p:812', $kind_product['key'] );
check_same( 'the kind of a group without one', 's:reef blenny|m', $kind_species['key'] );
check_same( 'the names a product-less invoice line may carry: species and title, alone and with the size', array( 'lagoon goby', 'lagoon goby m', 'lagoon goby captive bred', 'lagoon goby captive bred m' ), $kind_product['names'] );

echo "\nWhich groups are of a kind\n";
check( 'same product, another size: the same kind', Cover::stay_matches( $kind_product, fh_stay( 3, 812, 'Lagoon Goby', 1, 'l' ) ) );
check( 'same product under another name: the same kind', Cover::stay_matches( $kind_product, fh_stay( 4, 812, 'Goby, Lagoon', 1 ) ) );
check( 'another product: not the kind', ! Cover::stay_matches( $kind_product, fh_stay( 5, 813, 'Lagoon Goby', 1, 'm' ) ) );
check( 'the same name without a product is not the product\'s kind', ! Cover::stay_matches( $kind_product, fh_stay( 6, 0, 'Lagoon Goby', 1, 'm' ) ) );
check( 'no product: the same species and size', Cover::stay_matches( $kind_species, fh_stay( 7, 0, 'reef  blenny', 1, 'm' ) ) );
check( 'no product: the same species in another size is another kind', ! Cover::stay_matches( $kind_species, fh_stay( 8, 0, 'Reef Blenny', 1, 'l' ) ) );
check( 'no product: the same species without a size is another kind', ! Cover::stay_matches( $kind_species, fh_stay( 9, 0, 'Reef Blenny', 1 ) ) );
check( 'no product: a group that has a product is not the species\' kind', ! Cover::stay_matches( $kind_species, fh_stay( 10, 900, 'Reef Blenny', 1, 'm' ) ) );

echo "\nCOVERED\n";
$tanks = array(
	fh_stay( 1, 812, 'Lagoon Goby', 3, 'm' ),
	fh_stay( 3, 812, 'Lagoon Goby', 2, 'l', 'cleared' ),
	fh_stay( 11, 812, 'Lagoon Goby', 4, 'm', 'closed' ),
	fh_stay( 12, 812, '?? small brown goby', 5, '', 'in_qt', true ),
	fh_stay( 5, 813, 'Lagoon Goby', 9 ),
	fh_stay( 2, 0, 'Reef Blenny', 2, 'm' ),
	fh_stay( 7, 0, 'Reef Blenny', 1, 'm' ),
	fh_stay( 8, 0, 'Reef Blenny', 6, 'l' ),
);
check_same( 'a product: every open group of it, in QT or cleared, any size — not the closed one, not the unidentified one', 5, Cover::covered( $kind_product, $tanks ) );
check_same( 'a species + size: only the groups of that species and size without a product', 3, Cover::covered( $kind_species, $tanks ) );
check_same( 'no group at all', 0, Cover::covered( $kind_product, array() ) );

echo "\nReplaced from the extra\n";
$a = Cover::assess(
	$kind_product,
	$tanks,
	array(
		fh_holder( 501, 'i:1', 2, 1000 ),
		fh_holder( 502, 'i:2', 3, 2000 ),
	),
	array()
);
check_same( 'five alive for five owed: covered, committed, no shortage, nothing new', array( 5, 5, 0, 0 ), array( $a['covered'], $a['committed'], $a['shortage'], $a['new'] ) );
check_same( '… and nobody is allocated', array(), $a['allocations'] );

echo "\nShort\n";
$holders = array(
	fh_holder( 501, 'i:1', 2, 1000 ),
	fh_holder( 502, 'i:2', 3, 3000 ),
	fh_holder( 503, 'i:3', 2, 2000 ),
);
$a = Cover::assess( $kind_product, $tanks, $holders, array() );
check_same( 'five alive for seven owed: short by two', array( 5, 7, 2, 2 ), array( $a['covered'], $a['committed'], $a['shortage'], $a['new'] ) );
check_same( 'the most recently PAID order is suggested first', 502, $a['holders'][0]['order_id'] );
check_same( '… then the next most recent, then the oldest', array( 502, 503, 501 ), array_column( $a['holders'], 'order_id' ) );
check_same( 'one case, on that order\'s line, for both fish', array( array( 502, 2 ) ), array_map( static function ( $x ) { return array( $x['holder']['order_id'], $x['qty'] ); }, $a['allocations'] ) );

$a = Cover::assess( $kind_product, array( fh_stay( 1, 812, 'Lagoon Goby', 1 ) ), $holders, array() );
check_same( 'one alive for seven owed: the shortage runs down the holders, most recent first', array( array( 502, 3 ), array( 503, 2 ), array( 501, 1 ) ), array_map( static function ( $x ) { return array( $x['holder']['order_id'], $x['qty'] ); }, $a['allocations'] ) );

$tie = Cover::rank_holders(
	array(
		fh_holder( 601, 'i:1', 1, 5000 ),
		fh_holder( 602, 'i:9', 1, 5000 ),
		fh_holder( 602, 'i:4', 1, 5000 ),
	),
	array()
);
check_same( 'paid at the same moment: the newer order first, then its later line', array( '602/i:9', '602/i:4', '601/i:1' ), array_map( static function ( $h ) { return $h['order_id'] . '/' . $h['ref']; }, $tie ) );

echo "\nRefunds and cases\n";
$a = Cover::assess( $kind_product, $tanks, array( fh_holder( 501, 'i:1', 2, 1000 ), fh_holder( 502, 'i:2', 3, 3000, 1 ), fh_holder( 503, 'i:3', 2, 2000 ) ), array() );
check_same( 'a fish already refunded off a line is not owed', array( 6, 1 ), array( $a['committed'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 2, 'open' ) ) );
check_same( 'an open case already speaks for its fish: no second case for the same shortage', array( 2, 2, 0 ), array( $a['shortage'], $a['pending'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 1, 'sent' ) ) );
check_same( 'a sent case speaks for one of two: one more is needed', array( 2, 1, 1 ), array( $a['shortage'], $a['pending'], $a['new'] ) );
check_same( '… and it goes to the same line, which still has free fish', array( array( 502, 1 ) ), array_map( static function ( $x ) { return array( $x['holder']['order_id'], $x['qty'] ); }, $a['allocations'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 3, 'sent' ) ) );
check_same( 'a line whose every fish has a case is passed over: the next holder is short', array( 2, 3, 0 ), array( $a['shortage'], $a['pending'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 2, 'answered' ) ) );
check_same( 'an answered case released its fish: five alive for five owed again', array( 5, 0, 0 ), array( $a['committed'], $a['shortage'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 2, 'done' ) ) );
check_same( 'a done case keeps them released', 5, $a['committed'] );

$a = Cover::assess( $kind_product, $tanks, array( fh_holder( 501, 'i:1', 2, 1000 ), fh_holder( 502, 'i:2', 3, 3000, 2 ), fh_holder( 503, 'i:3', 2, 2000 ) ), array( fh_case( 502, 'i:2', 2, 'done' ) ) );
check_same( 'released by a case AND refunded with a quantity is the same two fish, counted once', 5, $a['committed'] );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 2, 'closed', 'loss_undone' ) ) );
check_same( 'a case closed because the loss was undone releases nothing', array( 7, 2 ), array( $a['committed'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 502, 'i:2', 2, 'closed', 'settled' ) ) );
check_same( 'a case closed as settled with the customer releases its fish', array( 5, 0 ), array( $a['committed'], $a['new'] ) );

$a = Cover::assess( $kind_product, $tanks, $holders, array( fh_case( 999, 'i:2', 2, 'open' ) ) );
check_same( 'a case on a line that is no holder (shipped or cancelled since) speaks for nobody here', array( 0, 2 ), array( $a['pending'], $a['new'] ) );

echo "\nSpecies without a product\n";
$a = Cover::assess( $kind_species, $tanks, array( fh_holder( 701, 'inv:4:1', 4, 100 ) ), array() );
check_same( 'three medium alive for four owed: short by one (the six large are another kind)', array( 3, 4, 1 ), array( $a['covered'], $a['committed'], $a['shortage'] ) );

echo "\nGiving a case to another holder\n";
$ranked = Cover::rank_holders( $holders, array( fh_case( 502, 'i:2', 2, 'open' ), fh_case( 503, 'i:3', 2, 'sent' ) ) );
$others = Cover::alternatives( fh_case( 502, 'i:2', 2, 'open' ), $ranked );
check_same( 'the other holders with enough free fish — not the case\'s own line, not a line whose fish all have a case', array( 501 ), array_column( $others, 'order_id' ) );
check_same( 'a case for more fish than any other line has free: nobody', array(), Cover::alternatives( fh_case( 502, 'i:2', 3, 'open' ), $ranked ) );

echo "\nAn order whose customer chose to cancel\n";
$cancel_holders = array(
	array( 'order_id' => 901, 'ref' => 'i:1', 'qty' => 2, 'qty_refunded' => 0, 'paid_at' => 3000 ),
	array( 'order_id' => 901, 'ref' => 'i:2', 'qty' => 1, 'qty_refunded' => 0, 'paid_at' => 3000 ),
	array( 'order_id' => 902, 'ref' => 'i:3', 'qty' => 1, 'qty_refunded' => 0, 'paid_at' => 2000 ),
);
$cancel_cases   = array(
	array( 'status' => 'answered', 'choice' => 'cancel', 'order_id' => 901, 'line_ref' => 'i:1', 'qty' => 1 ),
);
check_same( 'the orders being cancelled', array( 901 => true ), Cover::cancelled_orders( $cancel_cases ) );
check_same( '… not while the case only waits, not for another choice, not when it was closed', array(), Cover::cancelled_orders( array( array( 'status' => 'sent', 'choice' => 'cancel', 'order_id' => 901 ), array( 'status' => 'done', 'choice' => 'refund', 'order_id' => 902 ), array( 'status' => 'closed', 'choice' => 'cancel', 'order_id' => 903 ) ) ) );
$ranked = Cover::rank_holders( $cancel_holders, $cancel_cases );
check_same( 'EVERY line of that order owes nothing — not only the line of the case', array( 0, 0, 1 ), array( $ranked[0]['owed'] + 0, $ranked[1]['owed'] + 0, $ranked[2]['owed'] ) );
$kind_any = Cover::kind_of_stay( array( 'product_id' => 812, 'species' => 'Lagoon Goby' ) );
$assess   = Cover::assess( $kind_any, array( array( 'product_id' => 812, 'qty_live' => 1, 'status' => 'in_qt' ) ), $cancel_holders, $cancel_cases );
check_same( 'one alive, one owed (the other order): covered', array( 1, 1, 0 ), array( $assess['covered'], $assess['committed'], $assess['shortage'] ) );

fh_finish();
