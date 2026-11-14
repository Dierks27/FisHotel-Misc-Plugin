<?php
/**
 * The seam with the Requests section — BOTH real: this section's Cases
 * (on its in-memory store and stand-in orders) hands a "try again" to the
 * real fishotel_requests_create(), which runs the Requests section's own
 * Service on that section's in-memory store.
 *
 * requests-contract-test.php tries the same with a stand-in function and
 * every answer a receiver may give; here it is the receiver itself:
 *
 *   - the function is declared whenever the plugin is active — also
 *     while the Requests section is switched off. Then nothing is offered
 *     ("Add to Requests" is not shown, the case says "note it yourself")
 *     and nothing is created;
 *   - switched on, the customer's "try again" becomes ONE request: source
 *     lost_fish, owed, no fee whatever the settings say, on the list at
 *     once, with the fish, its product, how many, the customer, the order
 *     — and the case keeps its id;
 *   - a size the Requests section does not have in its list is not lost:
 *     the request is made without it and the size is in the note.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/requests-seam-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

/* What the Requests section calls that this section's tests never needed. */

/**
 * WordPress's error object, as far as it is read.
 */
class WP_Error {

	private $code;
	private $message;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

foreach ( array(
	'is_email'       => 'return false !== strpos( (string) $a, "@" ) ? (string) $a : false;',
	'sanitize_email' => 'return strtolower( trim( (string) $a ) );',
	'get_user_by'    => 'return false;',
	'has_filter'     => 'return false;',
) as $fh_name => $fh_body ) {
	if ( ! function_exists( $fh_name ) ) {
		eval( 'function ' . $fh_name . '( $a = null, $b = null, $c = null ) { ' . $fh_body . ' }' );
	}
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';
\FisHotel\Misc\Sections\Requests\Requests::load();
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/tests/fake-store.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lost-fish/class-admin.php';

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;
use FisHotel\Misc\Sections\Requests\Mailer;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Service;
use FisHotel\Misc\Sections\Requests\Settings as Requests_Settings;
use FisHotel\Misc\Sections\Requests\Store as Requests_Store;
use FisHotel\Misc\Sections\Requests\Tests\Fake_Store as Requests_Fake_Store;

/**
 * One sent case — a Lagoon Goby lost, order 502 short of it — and the
 * Requests section's real service on its in-memory store, with a fee set
 * for requests (which a lost fish must never be charged).
 *
 * @param bool   $requests_on Whether the Requests section is switched on.
 * @param string $size_slug   The lost fish's size.
 * @param string $size_label  Its label.
 * @return array{0: Cases, 1: Fh_Fake_Store, 2: Requests_Fake_Store}
 */
function fh_seam_world( $requests_on, $size_slug = '', $size_label = '' ) {
	fh_reset();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
	Shipping::$rater                                   = 'fh_rater';

	$GLOBALS['fh_options']['fishotel_misc_enabled_sections'] = $requests_on ? array( 'lost-fish', 'requests' ) : array( 'lost-fish' );
	$GLOBALS['fh_options'][ Requests_Store::DB_VERSION_OPTION ] = Requests_Store::DB_VERSION;

	$requests = new Requests_Fake_Store();
	$settings = Requests_Settings::merge( array( 'default_fee' => 25.0 ) );
	Requests::use_service(
		new Service(
			$requests,
			$settings,
			new Mailer( $requests, $settings ),
			array(
				'user'          => static function ( $id ) {
					return 42 === (int) $id ? array( 'id' => 42, 'name' => 'Blake Sample', 'email' => 'blake@example.test', 'handle' => 'reef_blake' ) : null;
				},
				'user_by_email' => static function ( $email ) {
					return 0;
				},
				'match_fish'    => static function ( $fish ) {
					return array( 'species_id' => null, 'product_id' => null );
				},
				'product_title' => static function ( $product_id ) {
					return 812 === (int) $product_id ? 'Lagoon Goby' : '';
				},
				'fees'          => static function () {
					return array( 'available' => true, 'reason' => '', 'product_id' => 9001 );
				},
			)
		)
	);

	fh_product( 812, array( 'class' => 'premium' ) );
	fh_order( 502, array( 'paid' => 3000, 'shipping' => 40.00, 'first_name' => 'Blake', 'last_name' => 'Sample', 'email' => 'blake@example.test', 'customer_id' => 42, 'lines' => array( 53 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ) ) ) );

	$rooms = new Fh_Fake_Rooms();
	$rooms->add( 1, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => $size_slug, 'size_label' => $size_label, 'qty_live' => 2 ) );
	$store = new Fh_Fake_Store();
	$cases = fh_cases( $store, $rooms );

	$rooms->lose( 1, 1 );
	$cases->loss_recorded( 1, 9001, 1, 7 );
	$cases->send( 1, 5 );

	return array( $cases, $store, $requests );
}

/* ================================================================== */
echo "The Requests section switched off\n";
list( $cases, $store, $requests ) = fh_seam_world( false );
check( 'its function is declared all the same (the plugin loads every section\'s file)', function_exists( 'fishotel_requests_create' ) && false === Requests::enabled() );
check_same( 'so the function is not the answer: the section is asked — not available', false, Cases::requests_available() );
$cases->answer( 1, 'retry', 'customer' );
$case = $store->get_case( 1 );
check_same( 'the customer\'s "try again" is recorded; nothing was created over there', array( 'retry', '', array() ), array( $case['choice'], $case['request_ref'], $requests->rows ) );
check_contains( 'the case tells the owner to note it', 'The Requests section is not available: note it yourself', $cases->todo( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) )['note'] );
check_same( '"Add to Requests" (were it posted) says so and creates nothing', array( false, array() ), array( $cases->create_request( 1, 5 )['ok'], $requests->rows ) );
check_same( 'the screen\'s list of what is there: Requests is not', false, Admin::connections( $cases )['requests']['ok'] );

/* ================================================================== */
echo "\nSwitched on: a \"try again\" becomes a request\n";
list( $cases, $store, $requests ) = fh_seam_world( true );
check_same( 'available', array( true, true ), array( Requests::enabled(), Cases::requests_available() ) );
check_same( 'the screen\'s list says so too', true, Admin::connections( $cases )['requests']['ok'] );
check_same( 'nothing is handed over before the customer has answered', array(), $requests->rows );
$answer = $cases->answer( 1, 'retry', 'customer' );
check( 'the customer chooses to try again', $answer['ok'] );
check_same( 'ONE request was created by the Requests section itself', 1, count( $requests->rows ) );
$row = array_values( $requests->rows )[0];
check_same( 'source lost_fish, owed, on the list at once', array( 'lost_fish', 1, 'waiting' ), array( $row['source'], (int) $row['owed'], $row['state'] ) );
check_same( 'NO fee — although a request\'s fee is 25.00 in its settings', array( 0.0, null ), array( (float) $row['fee_amount'], $row['fee_ref'] ) );
check_same( 'the fish, its product, how many', array( 'Lagoon Goby', 812, 1 ), array( $row['fish'], (int) $row['product_id'], (int) $row['qty'] ) );
check_same( 'the customer: the site account, the name and the e-mail', array( 42, 'Blake Sample', 'blake@example.test' ), array( (int) $row['user_id'], $row['name'], $row['email'] ) );
check_same( 'the order it came from', 502, (int) $row['from_order_id'] );
check_contains( 'the note says it is already paid for', 'already paid for', $row['note'] );
$case = $store->get_case( 1 );
check_same( 'the case keeps the request\'s id', (string) $row['id'], $case['request_ref'] );
check_same( 'fishotel_requests_get() reads it back', array( (int) $row['id'], 'lost_fish' ), array( (int) fishotel_requests_get( (int) $row['id'] )['id'], fishotel_requests_get( (int) $row['id'] )['source'] ) );
check_contains( 'the order is told', 'A request for a new fish was created (#' . $row['id'] . '): owed, already paid for.', implode( "\n", fh_notes( 502 ) ) );
check_contains( 'what the owner has to do names the request', 'A request was created in Requests (#' . $row['id'] . ')', $cases->todo( $case, $cases->orders()->facts( $GLOBALS['fh_orders'][502] ) )['note'] );
check_same( 'no e-mail from the Requests section (the customer chose it a moment ago), one from this one (the case)', 1, count( $GLOBALS['fh_mail'] ) );
check_same( '"Add to Requests" on a case that has one: nothing is created twice', array( true, 1 ), array( $cases->create_request( 1, 5 )['ok'], count( $requests->rows ) ) );

/* ================================================================== */
echo "\nA size the Requests section does not list\n";
list( $cases, $store, $requests ) = fh_seam_world( true, 'm', 'Medium' );
$cases->answer( 1, 'retry', 'customer' );
$row = array_values( $requests->rows );
check_same( 'the request is made all the same — once', 1, count( $row ) );
check( 'with the size it was handed, or (a list that does not have it) with the size in the note', 'm' === (string) $row[0]['size_slug'] || false !== strpos( (string) $row[0]['note'], 'Size: Medium.' ), json_encode( array( $row[0]['size_slug'], $row[0]['size_label'], $row[0]['note'] ) ) );
check_same( 'and the case has its id', (string) $row[0]['id'], $store->get_case( 1 )['request_ref'] );

Requests::use_service( null );

fh_finish();
