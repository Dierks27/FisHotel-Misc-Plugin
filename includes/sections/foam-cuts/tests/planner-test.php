<?php
/**
 * Planner — the one path a plan is made through: the boxes and settings
 * as they are now, the counts typed or taken from the orders of a day,
 * the labels reserved, the draft saved; a draft deleted; what a plan
 * looks like to other code (export()); and the key that opens its
 * printable page for one user, one plan, for a limited time.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/planner-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Plan;
use FisHotel\Misc\Sections\Foam_Cuts\Planner;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Stock;
use FisHotel\Misc\Sections\Foam_Cuts\Tests\Fake_Store;

update_option(
	Settings::OPTION_KEY,
	array(
		'kerf'      => fh_in( 0.125 ),
		'boxes'     => array(
			array( 'name' => 'Cube', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ),
			array( 'name' => 'Flat', 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 10 ) ),
			array( 'name' => 'Bare', 'length' => fh_in( 9 ), 'width' => fh_in( 9 ), 'height' => fh_in( 9 ) ),
		),
		'cut_lists' => array(
			'flat' => array( 'name' => 'Flat', 'box' => '160000x120000x100000', 'pieces' => array( array( 'name' => 'Wrap', 'length' => fh_in( 44 ), 'width' => fh_in( 9 ), 'count' => 1 ), array( 'name' => 'Lid', 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'count' => 2 ) ) ),
		),
	)
);
fh_login( 7, array( 'manage_woocommerce' ) );

echo "1. What a plan is made from\n";
$context = Planner::context();
check_same( 'the box list in use and where it comes from', array( 'own', array( 'Cube', 'Flat', 'Bare' ) ), array( $context['source'], array_column( $context['boxes'], 'name' ) ) );
check_same( 'each box with the cut list it would use', array( 'suggested', 'saved', 'suggested' ), array_column( $context['boxes'], 'cut_list' ) );
check_same( 'the settings', fh_in( 0.125 ), $context['settings']['kerf'] );

echo "2. A plan from typed numbers\n";
$store = new Fake_Store();
$store->add_sheet_entry( 'bought', 4, 0, '', 7 );
$made = Planner::create( $store, array( 'cube' => '2', 'flat' => 1, 'bare' => 0, 'ghost' => 5 ), array(), 7 );
check( 'made', true === $made['ok'] && 1 === $made['id'] && false !== strpos( $made['message'], 'Plan 1 is ready' ), $made['message'] );
$plan = $store->plan( 1 );
check_same( 'a draft, typed, by user 7', array( 'draft', 'typed', 7, '' ), array( $plan['status'], $plan['origin'], $plan['created_by'], $plan['ship_date'] ) );
check_same( 'only the boxes with a number above 0 that are on the list', '2 × Cube, 1 × Flat', $plan['title'] );
check_same( 'the saved cut list is what was cut for the Flat box', array( 'Wrap', 'Lid' ), array_column( $plan['data']['boxes'][1]['pieces'], 'name' ) );
check_same( 'the settings of the moment are in the plan', 0.125, $plan['data']['settings']['kerf'] );
check_same( 'what it is for', array( 'origin' => 'typed' ), $plan['data']['for'] );
check( 'its labels are reserved', 1 === $plan['label_from'] && $plan['label_count'] === $plan['data']['labels']['count'] && 1 + $plan['label_count'] === $store->next_label() );
check_same( 'the sheets it takes are on the row', Plan::sheets( $plan['data'] ), $plan['sheets'] );
check( 'the stock was not touched', 4 === $store->sheets() && array() === $store->leftovers( 'stock' ) );

$none = Planner::create( $store, array( 'cube' => 0, 'flat' => '' ), array(), 7 );
check( 'every number 0: refused with the reason, nothing saved, no label taken', false === $none['ok'] && 0 === $none['id'] && false !== strpos( $none['message'], 'Nothing to cut' ) && 1 === count( $store->plans ) && 1 + $plan['label_count'] === $store->next_label() );
$store->fail['add_plan'] = true;
$lost                    = Planner::create( $store, array( 'cube' => 1 ), array(), 7 );
$store->fail             = array();
check( 'a plan that cannot be saved gives its labels back', false === $lost['ok'] && 1 + $plan['label_count'] === $store->next_label() );

echo "3. Another plan, and deleting drafts\n";
Planner::create( $store, array( 'bare' => 3 ), array(), 7 );
$second = $store->plan( 2 );
check( 'the second plan\'s labels follow the first\'s', $second['label_from'] === 1 + $plan['label_count'] );
$top    = $store->next_label();
$result = Planner::delete( $store, $second );
check( 'the newest draft deleted: gone, and its labels are free again', true === $result['ok'] && null === $store->plan( 2 ) && $store->next_label() === $second['label_from'] && $store->next_label() < $top );
Planner::create( $store, array( 'bare' => 3 ), array(), 7 );
$third  = $store->plan( 3 );
$result = Planner::delete( $store, $store->plan( 1 ) );
check( 'an older draft deleted: gone, its labels are not handed out again', true === $result['ok'] && null === $store->plan( 1 ) && $store->next_label() === $third['label_from'] + $third['label_count'] );
Stock::apply( $store, $third, 7 );
$result = Planner::delete( $store, $store->plan( 3 ) );
check( 'a plan that is marked as cut is kept', false === $result['ok'] && null !== $store->plan( 3 ) && false !== strpos( $result['message'], 'undo it first' ) );

echo "4. A plan as other code gets it\n";
$out = Planner::export( $store->plan( 3 ), $store );
check_same( 'id, status and dates first, then the plan itself, then the print URL', array( 'id', 'status', 'title', 'origin', 'ship_date', 'created_at', 'cut_at', 'stale', 'stale_reasons', 'can_undo', 'schema' ), array_slice( array_keys( $out ), 0, 11 ) );
check( 'a cut plan: status, when (ISO 8601, UTC), undo possible', 3 === $out['id'] && 'cut' === $out['status'] && '2026-03-10T10:00:00Z' === $out['cut_at'] && '2026-03-10T09:00:00Z' === $out['created_at'] && true === $out['can_undo'] && false === $out['stale'] && null === $out['ship_date'] );
check( 'the plan data is all there', 'fishotel-foam-cuts/plan@1' === $out['schema'] && isset( $out['sources'], $out['summary'], $out['totals'], $out['boxes'], $out['sheet'] ) && 'print_url' === array_key_last( $out ) );
Planner::create( $store, array( 'cube' => 1 ), array(), 7 );
$draft = Planner::export( $store->plan( 4 ), $store );
check( 'a draft: no cut date, nothing to undo, not stale', 'draft' === $draft['status'] && null === $draft['cut_at'] && false === $draft['can_undo'] && false === $draft['stale'] );
$used = Plan::used_leftover_ids( $store->plan( 4 )['data'] );
check( 'this draft cuts into a leftover the earlier plan made', count( $used ) > 0 );
Stock::remove_leftover( $store, $used[0] );
$draft = Planner::export( $store->plan( 4 ), $store );
check( 'when that leftover goes, the draft says it is stale and why', true === $draft['stale'] && 1 === count( $draft['stale_reasons'] ) && false !== strpos( $draft['stale_reasons'][0], 'no longer in stock' ) );
check( '…and the cut plan that made it can no longer be undone', false === Planner::export( $store->plan( 3 ), $store )['can_undo'] );
check( 'everything exported is JSON', is_string( json_encode( $draft ) ) );
check_same( 'a stored time that is not one', array( null, null ), array( Planner::iso( '' ), Planner::iso( '0000-00-00 00:00:00' ) ) );

echo "5. The key to the printable page\n";
$url = $out['print_url'];
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
check( 'the print URL is the admin-post action with the plan and a key', 0 === strpos( $url, 'https://example.test/wp-admin/admin-post.php?' ) && 'fishotel_foam_cuts_print' === $query['action'] && '3' === $query['plan'] && 10 === strlen( $query['key'] ) );
check( 'the key opens that plan for that user', true === Planner::verify_print_key( $query['key'], 3, 7 ) );
check( 'not another plan', false === Planner::verify_print_key( $query['key'], 4, 7 ) );
check( 'not for another user', false === Planner::verify_print_key( $query['key'], 3, 8 ) );
check( 'not for nobody', false === Planner::verify_print_key( $query['key'], 3, 0 ) );
check( 'not an empty or a made-up key, nor something that is not text', false === Planner::verify_print_key( '', 3, 7 ) && false === Planner::verify_print_key( 'aaaaaaaaaa', 3, 7 ) && false === Planner::verify_print_key( array( $query['key'] ), 3, 7 ) );
$GLOBALS['fh_nonce_tick'] = 1001;
check( 'still good in the next 12-hour tick', true === Planner::verify_print_key( $query['key'], 3, 7 ) );
$GLOBALS['fh_nonce_tick'] = 1002;
check( 'expired the tick after', false === Planner::verify_print_key( $query['key'], 3, 7 ) );
$GLOBALS['fh_nonce_tick'] = 1000;
check( 'it does not depend on a browser session: made again later in the same tick it is the same key', Planner::print_key( 3, 7 ) === $query['key'] );
fh_login( 8, array( 'manage_woocommerce' ) );
check( 'another user gets a key of their own for the same plan', Planner::print_key( 3, 8 ) !== $query['key'] && false !== strpos( Planner::print_url( 3 ), Planner::print_key( 3, 8 ) ) );
fh_login( 7, array( 'manage_woocommerce' ) );

echo "6. A plan for the orders of a day\n";
$store = new Fake_Store();
$made  = Planner::create_for_orders( $store, '2026-03-10', 7 );
check( 'without the Packing section: refused, in words, nothing saved', false === $made['ok'] && false !== strpos( $made['message'], 'Packing section is not switched on' ) && array() === $store->plans );

$GLOBALS['fh_suggest'] = null;
if ( ! function_exists( 'fishotel_packing_suggest' ) ) { // Inside an if: declared when this line is reached.
	function fishotel_packing_suggest( $order ) {
		return is_callable( $GLOBALS['fh_suggest'] ) ? call_user_func( $GLOBALS['fh_suggest'], $order ) : null;
	}
}
$GLOBALS['fh_orders'] = array(
	201 => new WC_Order( 201, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	202 => new WC_Order( 202, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	203 => new WC_Order( 203, 'fulfillment', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	204 => new WC_Order( 204, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
);

$made = Planner::create_for_orders( $store, '2026-03-10', 7 );
check( 'orders, but none with a box yet: refused, saying so', false === $made['ok'] && false !== strpos( $made['message'], 'has a box yet' ) && array() === $store->plans, $made['message'] );
$made = Planner::create_for_orders( $store, '2026-03-20', 7 );
check( 'a day nothing ships: refused, naming the day and its delivery date', false === $made['ok'] && false !== strpos( $made['message'], '2026-03-20' ) && false !== strpos( $made['message'], '2026-03-21' ), $made['message'] );

$GLOBALS['fh_suggest'] = static function ( $order ) {
	$map = array( 201 => 'Cube', 202 => array( 'box' => 'cube' ), 203 => array( 'boxes' => array( 'Flat', '14x9x7' ) ) );
	return isset( $map[ $order->get_id() ] ) ? $map[ $order->get_id() ] : null;
};
$made = Planner::create_for_orders( $store, '2026-03-10', 7 );
check( 'made', true === $made['ok'] && 1 === $made['id'], $made['message'] );
$plan = $store->plan( 1 );
check_same( 'two cubes, a flat, and the box that is only a size (cut by the suggestion)', '2 × Cube, 1 × Flat, 1 × 14 × 9 × 7', $plan['title'] );
check_same( 'the row says it came from the orders of that day', array( 'orders', '2026-03-10' ), array( $plan['origin'], $plan['ship_date'] ) );
$for = $plan['data']['for'];
check( 'the plan remembers the day and its delivery date', 'orders' === $for['origin'] && '2026-03-10' === $for['ship_date'] && '2026-03-11' === $for['delivery_date'] );
check_same( 'and, per order, its number and its boxes', array( '1 × Cube', '1 × Cube', '1 × Flat + 1 × 14 × 9 × 7', '' ), array_map( static function ( $order ) { return implode( ' + ', $order['boxes'] ); }, $for['orders'] ) );
check_same( 'nothing about a customer: id, number, how, boxes', array( 'id', 'number', 'how', 'boxes' ), array_keys( $for['orders'][0] ) );
check_same( 'the order without a box is reported back to the screen', array( 204 ), $made['orders']['without_box'] );
check_same( 'the unlisted box has the six suggested pieces', 6, array_sum( array_column( $plan['data']['boxes'][2]['pieces'], 'count' ) ) );
check( 'the stock was not touched', array() === $store->leftovers( 'stock' ) && 0 === $store->sheets() );
$out = Planner::export( $plan, $store );
check( 'exported: origin and ship date', 'orders' === $out['origin'] && '2026-03-10' === $out['ship_date'] );

fh_finish();
