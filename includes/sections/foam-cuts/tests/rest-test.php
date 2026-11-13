<?php
/**
 * REST — who may call the routes (nobody without manage_woocommerce; a
 * request made with an application password is simply "that user" to
 * WordPress), a plan by its number or "latest" as JSON with a print_url
 * that opens for that user, the list, and a draft plan made from typed
 * numbers or from the orders of a day — without the stock ever changing.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/rest-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Planner;
use FisHotel\Misc\Sections\Foam_Cuts\Rest;
use FisHotel\Misc\Sections\Foam_Cuts\Settings;
use FisHotel\Misc\Sections\Foam_Cuts\Stock;
use FisHotel\Misc\Sections\Foam_Cuts\Tests\Fake_Store;

update_option(
	Settings::OPTION_KEY,
	array(
		'boxes' => array(
			array( 'name' => 'Cube', 'length' => fh_in( 12 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ),
			array( 'name' => 'Flat', 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 10 ) ),
		),
	)
);

$store = new Fake_Store();
$store->add_sheet_entry( 'bought', 5, 0, '', 1 );
$rest        = new Rest();
$rest->store = $store;

echo "1. The routes\n";
$rest->register();
$routes = $GLOBALS['fh_rest_routes'];
check_same( 'three routes under fishotel-misc/v1/foam-cuts', array( 'fishotel-misc/v1/foam-cuts/plan/(?P<id>\d+|latest)', 'fishotel-misc/v1/foam-cuts/plans', 'fishotel-misc/v1/foam-cuts/plan' ), array_keys( $routes ) );
check_same( 'reading is GET, making a plan is POST', array( 'GET', 'GET', 'POST' ), array_column( $routes, 'methods' ) );
$guarded = true;
foreach ( $routes as $route ) {
	$guarded = $guarded && array( $rest, 'permission' ) === $route['permission_callback'];
}
check( 'every route has the same permission check — none is public', $guarded );
$pattern = '#^/foam-cuts/plan/(?P<id>\d+|latest)$#';
check( 'the plan route takes a number or "latest", nothing else', 1 === preg_match( $pattern, '/foam-cuts/plan/12' ) && 1 === preg_match( $pattern, '/foam-cuts/plan/latest' ) && 0 === preg_match( $pattern, '/foam-cuts/plan/abc' ) && 0 === preg_match( $pattern, '/foam-cuts/plan/12/cut' ) );

echo "2. Who may call them\n";
fh_login( 0 );
$denied = $rest->permission();
check( 'nobody logged in: refused with 401', $denied instanceof WP_Error && 'rest_forbidden' === $denied->get_error_code() && 401 === $denied->get_error_data()['status'] );
fh_login( 5, array( 'read' ) );
$denied = $rest->permission();
check( 'a customer: refused with 403', $denied instanceof WP_Error && 403 === $denied->get_error_data()['status'] );
fh_login( 6, array( 'edit_posts', 'manage_options_not' ) );
check( 'an editor without manage_woocommerce: refused', $rest->permission() instanceof WP_Error );
fh_login( 7, array( 'manage_woocommerce' ) );
check_same( 'a shop manager (cookie + nonce, or an application password — WordPress only says who it is): allowed', true, $rest->permission() );

echo "3. A plan by its number\n";
$missing = $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => '12' ) ) );
check( 'no such plan: 404', $missing instanceof WP_Error && 'fishotel_foam_cuts_not_found' === $missing->get_error_code() && 404 === $missing->get_error_data()['status'] );
$missing = $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => 'latest' ) ) );
check( '"latest" with no plan at all: 404, saying so', $missing instanceof WP_Error && false !== strpos( $missing->get_error_message(), 'no foam cut plan yet' ) );

Planner::create( $store, array( 'cube' => 2 ), array(), 7 );
Planner::create( $store, array( 'flat' => 1 ), array(), 7 );
$found = $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => '1' ) ) );
check( 'found: 200', $found instanceof WP_REST_Response && 200 === $found->get_status() );
$body = $found->get_data();
check( 'the plan as JSON: id, status, the schema, the sources with pieces, cuts and leftovers in inches', 1 === $body['id'] && 'draft' === $body['status'] && 'fishotel-foam-cuts/plan@1' === $body['schema'] && 'in' === $body['units'] && isset( $body['sources'][0]['pieces'][0]['x'], $body['sources'][0]['cuts'][0]['text'], $body['sources'][0]['leftovers'], $body['summary'], $body['totals'] ) );
check( 'it is JSON all the way down', is_string( json_encode( $body ) ) && null !== json_decode( json_encode( $body ), true ) );
parse_str( (string) parse_url( $body['print_url'], PHP_URL_QUERY ), $query );
check( 'with a print_url: the admin-post page of that plan, carrying a key', 'fishotel_foam_cuts_print' === $query['action'] && '1' === $query['plan'] && '' !== $query['key'] );
check( 'the key opens the sheet for the user the request was made as — and nobody else', true === Planner::verify_print_key( $query['key'], 1, 7 ) && false === Planner::verify_print_key( $query['key'], 1, 5 ) );
$latest = $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => 'latest' ) ) )->get_data();
check_same( '"latest" is the newest plan', array( 2, '1 × Flat' ), array( $latest['id'], $latest['title'] ) );

echo "4. The list\n";
$list = $rest->get_plans( new WP_REST_Request( 'GET' ) )->get_data();
check_same( 'newest first', array( 2, 1 ), array_column( $list['plans'], 'id' ) );
check_same( 'each with what a caller needs to pick one', array( 'id', 'status', 'title', 'origin', 'ship_date', 'sheets', 'created_at', 'cut_at', 'print_url' ), array_keys( $list['plans'][0] ) );
check( 'the list does not carry the plans\' data', ! isset( $list['plans'][0]['sources'] ) );

echo "5. Making a plan from typed numbers\n";
$state = $store->state();
$made  = $rest->create_plan( new WP_REST_Request( 'POST', array( 'boxes' => array( 'Cube' => 1, 'flat' => '2' ) ) ) );
check( 'made: 201 with the plan, as GET gives it', $made instanceof WP_REST_Response && 201 === $made->get_status() && 3 === $made->get_data()['id'] && 'draft' === $made->get_data()['status'] && '1 × Cube, 2 × Flat' === $made->get_data()['title'] && isset( $made->get_data()['print_url'] ) );
check_same( 'by the user the request was made as', 7, $store->plan( 3 )['created_by'] );
$now = $store->state();
check( 'the stock is untouched: same sheets, same leftovers, no entry', $state['sheets'] === $now['sheets'] && $state['leftovers'] === $now['leftovers'] && $state['entries'] === $now['entries'] );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'boxes' => array( 'Cube' => 1, 'Jumbo' => 2 ) ) ) );
check( 'a box that is not on the list: 400, naming it and listing the boxes there are', $bad instanceof WP_Error && 'fishotel_foam_cuts_unknown_box' === $bad->get_error_code() && 400 === $bad->get_error_data()['status'] && false !== strpos( $bad->get_error_message(), 'Jumbo' ) && array( 'Cube', 'Flat' ) === $bad->get_error_data()['boxes'] );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'boxes' => array( 'Cube' => 0 ) ) ) );
check( 'every number 0: 409 with the reason', $bad instanceof WP_Error && 'fishotel_foam_cuts_no_plan' === $bad->get_error_code() && 409 === $bad->get_error_data()['status'] && false !== strpos( $bad->get_error_message(), 'Nothing to cut' ) );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'boxes' ) ) );
check( 'source "boxes" without boxes: 400', $bad instanceof WP_Error && 'fishotel_foam_cuts_no_boxes' === $bad->get_error_code() );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'stock' ) ) );
check( 'a source that is neither: 400', $bad instanceof WP_Error && 'fishotel_foam_cuts_bad_source' === $bad->get_error_code() );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'boxes' => array( 'Cube' => 100000 ) ) ) );
check( 'more of one box than a plan lays out: 400 naming the limit — not a plan for 500', $bad instanceof WP_Error && 'fishotel_foam_cuts_too_many' === $bad->get_error_code() && 400 === $bad->get_error_data()['status'] && 500 === $bad->get_error_data()['max'] && false !== strpos( $bad->get_error_message(), 'Cube' ) );
$most = $rest->create_plan( new WP_REST_Request( 'POST', array( 'boxes' => array( 'Cube' => 500 ) ) ) );
check( 'exactly the limit is a plan', $most instanceof WP_REST_Response && 201 === $most->get_status() && '500 × Cube' === $most->get_data()['title'] && false === $most->get_data()['reused'] );
Planner::delete( $store, $store->plan( $most->get_data()['id'] ) );
check_same( 'none of the refused ones saved a plan', 3, count( $store->plans ) );

echo "6. Making a plan from the orders of a day\n";
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array() ) );
check( 'an empty body means "today\'s orders" — without the Packing section: 409, saying why', $bad instanceof WP_Error && 409 === $bad->get_error_data()['status'] && false !== strpos( $bad->get_error_message(), 'Packing section is not switched on' ) );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'orders', 'ship_date' => 'tuesday' ) ) );
check( 'a ship_date that is not a date: 400', $bad instanceof WP_Error && 'fishotel_foam_cuts_bad_date' === $bad->get_error_code() );

$GLOBALS['fh_suggest'] = null;
if ( ! function_exists( 'fishotel_packing_suggest' ) ) { // Inside an if: declared when this line is reached.
	function fishotel_packing_suggest( $order ) {
		return is_callable( $GLOBALS['fh_suggest'] ) ? call_user_func( $GLOBALS['fh_suggest'], $order ) : null;
	}
}
$GLOBALS['fh_orders'] = array(
	301 => new WC_Order( 301, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	302 => new WC_Order( 302, 'processing', array( '_fishotel_shipping_date' => '2026-03-11' ) ),
	303 => new WC_Order( 303, 'processing', array( '_fishotel_shipping_date' => '2026-03-15' ) ),
);
$GLOBALS['fh_suggest'] = static function ( $order ) {
	return 302 === fh_oid( $order ) ? null : 'Cube';
};

$made = $rest->create_plan( new WP_REST_Request( 'POST', array() ) );
check( 'with it: 201, for today (2026-03-10, site time)', $made instanceof WP_REST_Response && 201 === $made->get_status() && 'orders' === $made->get_data()['origin'] && '2026-03-10' === $made->get_data()['ship_date'] );
$body = $made->get_data();
check_same( 'one cube for the one order that has a box', '1 × Cube', $body['title'] );
check_same( 'the order without a box is named in the answer, so the caller can say so', array( 302 ), $body['orders_without_box'] );
check( 'the plan remembers the orders by id and number only', array( 301, 302 ) === array_column( $body['for']['orders'], 'id' ) && array( 'id', 'number', 'how', 'boxes' ) === array_keys( $body['for']['orders'][0] ) );
$count  = count( $store->plans );
$labels = $store->next_label();
$again  = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'orders', 'ship_date' => '2026-03-10' ) ) );
check( 'the same day asked for again (a reprint): 200 with the SAME plan, flagged as reused — no second plan, no second set of labels', $again instanceof WP_REST_Response && 200 === $again->get_status() && $body['id'] === $again->get_data()['id'] && true === $again->get_data()['reused'] && false === $body['reused'] && $count === count( $store->plans ) && $labels === $store->next_label() );
check( '…with the orders without a box named again, and no other plan for the day', array( 302 ) === $again->get_data()['orders_without_box'] && array() === $again->get_data()['same_day'] );
$GLOBALS['fh_suggest'] = static function ( $order ) {
	return 'Cube';
};
$more = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'orders', 'ship_date' => '2026-03-10' ) ) );
check( 'the day has changed (an order got its box): 201 with a new plan that names the earlier one', $more instanceof WP_REST_Response && 201 === $more->get_status() && '2 × Cube' === $more->get_data()['title'] && array( $body['id'] ) === array_column( $more->get_data()['same_day'], 'id' ) && 'draft' === $more->get_data()['same_day'][0]['status'] );
check_same( 'GET shows the same list on the earlier plan', array( $more->get_data()['id'] ), array_column( $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => (string) $body['id'] ) ) )->get_data()['same_day'], 'id' ) );
$made = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'orders', 'ship_date' => '2026-03-14' ) ) );
check( 'another day by ship_date', $made instanceof WP_REST_Response && '2026-03-14' === $made->get_data()['ship_date'] && array( 303 ) === array_column( $made->get_data()['for']['orders'], 'id' ) );
$bad = $rest->create_plan( new WP_REST_Request( 'POST', array( 'source' => 'orders', 'ship_date' => '2026-03-20' ) ) );
check( 'a day nothing ships: 409 with the dates', $bad instanceof WP_Error && 409 === $bad->get_error_data()['status'] && '2026-03-21' === $bad->get_error_data()['delivery_date'] && array() === $bad->get_error_data()['orders'] );
$now = $store->state();
check( 'after all of that the stock is still untouched', $state['sheets'] === $now['sheets'] && $state['leftovers'] === $now['leftovers'] && $state['entries'] === $now['entries'] );

echo "7. What the routes cannot do\n";
$source = (string) php_strip_whitespace( dirname( __DIR__ ) . '/class-rest.php' );
check( 'no route marks a plan as cut, undoes one or touches the stock', 0 === preg_match( '/Stock::(apply|undo|buy_sheets|count_sheets|add_leftover|resize_leftover|remove_leftover)|Planner::(cut|undo|delete)|->(set_plan_status|mark_leftovers|add_sheet_entry|delete_plan)\(/', $source ) );
check( 'nothing in it hooks authentication', 0 === preg_match( '/determine_current_user|rest_authentication_errors|application_password/', $source ) );
check( 'a cut plan is served like any other, flagged as cut', true === Stock::apply( $store, $store->plan( 1 ), 7 )['ok'] && 'cut' === $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => '1' ) ) )->get_data()['status'] && null !== $rest->get_plan( new WP_REST_Request( 'GET', array( 'id' => '1' ) ) )->get_data()['cut_at'] );

fh_finish();
