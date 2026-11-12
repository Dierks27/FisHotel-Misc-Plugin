<?php
/**
 * Admin tests — every write needs the capability AND the nonce (the three
 * *_request() entry points, called as the admin-post handlers call them),
 * what each operation on a case does through them, the settings as they
 * are stored (the switch that sends by itself is OFF unless ticked), and
 * the screens themselves: the Cases tab with a case in every state, the
 * form that opens a case by hand, the Log, the Settings and the link on
 * the order screen — rendered for real, where a PHP notice is a failure.
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/admin-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/fakes.php';

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Email;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Settings;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;
use FisHotel\Misc\Sections\Lost_Fish\Store;

class Fh_Died extends \RuntimeException {
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Fh_Died( (string) $message );
}

/**
 * A $wpdb for Store::tables_exist() and the order screen's lookup: both
 * tables are there, no case rows.
 */
class Fh_Tables_Wpdb {

	public $prefix  = 'wp_';
	public $queries = 0;

	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args );
	}

	public function get_var( $sql ) {
		$this->queries++;
		return preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ? $m[1] : null;
	}

	public function get_results( $sql, $output = null ) {
		$this->queries++;
		return array();
	}
}

/**
 * The shop of the cases test, with user 5 allowed to manage WooCommerce
 * and user 6 not.
 *
 * @return array{0: Cases, 1: Fh_Fake_Store, 2: Fh_Fake_Rooms}
 */
function fh_shop() {
	fh_reset();
	$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = Store::DB_VERSION;
	$GLOBALS['fh_user_caps']                           = array( 'manage_woocommerce' => array( 5 ) );
	$GLOBALS['fh_current_user']                        = 5;
	$GLOBALS['wpdb']                                   = new Fh_Tables_Wpdb();
	Shipping::$rater                                   = 'fh_rater';

	fh_product( 812, array( 'class' => 'premium' ) );
	fh_product( 813, array( 'class' => 'premium' ) );
	fh_order( 501, array( 'paid' => 1000, 'shipping' => 15.00, 'first_name' => 'Avery', 'last_name' => 'Example', 'email' => 'avery@example.test', 'customer_id' => 41, 'meta' => array( '_fishotel_shipping_date' => '2031-03-12' ), 'lines' => array( 51 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ), 52 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ) ) ) );
	fh_order( 502, array( 'paid' => 3000, 'shipping' => 15.00, 'first_name' => 'Blake', 'last_name' => 'Sample', 'email' => 'blake@example.test', 'customer_id' => 42, 'lines' => array( 53 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 2, 'total' => 120.00 ), 54 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 3, 'total' => 690.00 ) ) ) );
	fh_order( 503, array( 'paid' => 2000, 'shipping' => 40.00, 'first_name' => 'Casey', 'last_name' => 'Tester', 'email' => 'casey@example.test', 'lines' => array( 55 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ) ) );

	$rooms = new Fh_Fake_Rooms();
	$rooms->add( 1, array( 'product_id' => 812, 'species' => 'Lagoon Goby', 'size_slug' => 'm', 'size_label' => 'Medium', 'qty_live' => 3 ) );
	$store = new Fh_Fake_Store();
	$cases = fh_cases( $store, $rooms );
	Cases::set_instance( $cases );

	return array( $cases, $store, $rooms );
}

/**
 * A request on a case, as its form posts it.
 */
function fh_post( $case_id, $op, array $fields = array() ) {
	return array_merge(
		array(
			'case_id'  => (string) $case_id,
			'op'       => $op,
			'_wpnonce' => wp_create_nonce( Admin::NONCE_CASE . $case_id ),
			'seen'     => fh_seen( Cases::instance(), $case_id ), // The case as the page shows it right now.
		),
		$fields
	);
}

echo "Who may write\n";
list( $cases, $store, $rooms ) = fh_shop();
$rooms->lose( 1, 1 );
$cases->loss_recorded( 1, 9001, 1, 5 ); // Two alive, four owed: 502's two gobies.
$good = fh_post( 1, 'send' );

$GLOBALS['fh_current_user'] = 6;
$reply                      = Admin::case_request( $cases, array_merge( $good, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_CASE . 1 ) ) ) );
check_same( 'a user without manage_woocommerce: 403, nothing sent', array( 403, false, array(), 'open' ), array( $reply['status'], $reply['ok'], $GLOBALS['fh_mail'], $store->get_case( 1 )['status'] ) );
$GLOBALS['fh_current_user'] = 0;
check_same( 'nobody logged in: 403', 403, Admin::case_request( $cases, $good )['status'] );
$GLOBALS['fh_current_user'] = 5;

foreach ( array(
	'no nonce'                => array_diff_key( $good, array( '_wpnonce' => 1 ) ),
	'a wrong nonce'           => array_merge( $good, array( '_wpnonce' => 'nonce-0000000000' ) ),
	'the nonce of case 2'     => array_merge( $good, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_CASE . 2 ) ) ),
	'the settings nonce'      => array_merge( $good, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_SETTINGS ) ) ),
	'a nonce that is a list'  => array_merge( $good, array( '_wpnonce' => array( $good['_wpnonce'] ) ) ),
	'no case'                 => array_merge( $good, array( 'case_id' => '0' ) ),
) as $label => $raw ) {
	check_same( $label . ': 403', 403, Admin::case_request( $cases, $raw )['status'] );
}
check_same( '… and after all of that nothing was sent and the case is as it was', array( array(), 'open' ), array( $GLOBALS['fh_mail'], $store->get_case( 1 )['status'] ) );
check_same( 'an operation that does not exist: 400', 400, Admin::case_request( $cases, fh_post( 1, 'delete_everything' ) )['status'] );

echo "\nThe operations\n";
$reply = Admin::case_request( $cases, fh_post( 1, 'numbers', array( 'unit_paid' => '58.00', 'ship_diff' => '' ) ) );
check_same( 'numbers: what was paid for one fish', array( 200, 5800 ), array( $reply['status'], $store->get_case( 1 )['unit_paid'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'send', array( 'shown_refund' => '99999' ) ) );
check_same( 'send, with a refund on the page that is not the refund now: 400, nothing sent', array( 400, array() ), array( $reply['status'], $GLOBALS['fh_mail'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'send', array( 'shown_refund' => '11600' ) ) );
check_same( 'send, with the refund the page showed (2 × 58.00): one e-mail', array( 200, 1, 'sent' ), array( $reply['status'], count( $GLOBALS['fh_mail'] ), $store->get_case( 1 )['status'] ) );
check_same( '… the sender of the note is the user who clicked', 5, $store->log[ count( $store->log ) - 1 ]['user_id'] );
$reply = Admin::case_request( $cases, fh_post( 1, 'send' ) );
check_same( 'send again: 400, still one e-mail', array( 400, 1 ), array( $reply['status'], count( $GLOBALS['fh_mail'] ) ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'answer', array( 'choice' => 'refund' ) ) );
check_same( 'answer by hand', array( 200, 'answered', 'owner' ), array( $reply['status'], $store->get_case( 1 )['status'], $store->get_case( 1 )['choice_via'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'request' ) );
check_same( 'add to Requests on a refund: 400', 400, $reply['status'] );
$reply = Admin::case_request( $cases, fh_post( 1, 'done', array( 'note' => "Refunded <b>today</b>\n" ) ) );
check_same( 'done, the note as plain text', array( 200, 'done', 'Refunded today' ), array( $reply['status'], $store->get_case( 1 )['status'], $store->get_case( 1 )['done_note'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'reopen' ) );
check_same( 'reopen', array( 200, 'open' ), array( $reply['status'], $store->get_case( 1 )['status'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'reassign', array( 'holder' => '503|i:55' ) ) );
check_same( 'give to another customer: not for two fish when that line is owed one', array( 400, 502 ), array( $reply['status'], $store->get_case( 1 )['order_id'] ) );
$reply = Admin::case_request( $cases, fh_post( 1, 'close', array( 'reason' => 'mistake' ) ) );
check_same( 'close', array( 200, 'closed', 'mistake' ), array( $reply['status'], $store->get_case( 1 )['status'], $store->get_case( 1 )['close_reason'] ) );
check_same( 'every reply names its case, for the page to scroll back to', 1, $reply['case_id'] );

echo "\nOpening a case by hand\n";
list( $cases, $store, $rooms ) = fh_shop();
$open = array( 'order_id' => '502', 'line' => 'i:54', 'qty' => '1', '_wpnonce' => wp_create_nonce( Admin::NONCE_OPEN ) );
$GLOBALS['fh_current_user'] = 6;
check_same( 'without the capability: 403', array( 403, array() ), array( Admin::open_request( $cases, array_merge( $open, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_OPEN ) ) ) )['status'], $store->cases() ) );
$GLOBALS['fh_current_user'] = 5;
check_same( 'without the nonce: 403', array( 403, array() ), array( Admin::open_request( $cases, array_diff_key( $open, array( '_wpnonce' => 1 ) ) )['status'], $store->cases() ) );
check_same( 'with a case nonce instead: 403', 403, Admin::open_request( $cases, array_merge( $open, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_CASE . 1 ) ) ) )['status'] );
$reply = Admin::open_request( $cases, $open );
check_same( 'with both: a case, by hand, by that user, nothing sent', array( 200, 1, 'manual', 5, array() ), array( $reply['status'], $reply['case_id'], $store->get_case( 1 )['origin'], $store->get_case( 1 )['created_by'], $GLOBALS['fh_mail'] ) );
check_same( 'a line that is not on the order: 400', array( 400, 502 ), array_values( array_intersect_key( Admin::open_request( $cases, array_merge( $open, array( 'line' => 'i:999' ) ) ), array( 'status' => 1, 'order_id' => 1 ) ) ) );

echo "\nThe settings\n";
fh_shop();
check_same( 'out of the box: nothing is sent by itself, one reminder after two days, links good for two weeks', array( false, 2, 14, array( 'free' ), true ), array( Settings::get()['auto_send'], Settings::get()['reminder_days'], Settings::get()['link_days'], Settings::get()['free_classes'], Settings::get()['store_design'] ) );
$form = array(
	'_wpnonce' => wp_create_nonce( Admin::NONCE_SETTINGS ),
	'fh_lf'    => array(
		'reminder_days' => '3',
		'link_days'     => '21',
		'free_classes'  => 'free, Gratis  no-charge',
		'subject'       => Email::default_subject(),
		'body'          => str_replace( "\n", "\r\n", Email::default_body() ),
		'reply_to'      => 'hello@example.test',
	),
);
$GLOBALS['fh_current_user'] = 6;
check_same( 'without the capability: 403, nothing stored', array( 403, false ), array( Admin::settings_request( array_merge( $form, array( '_wpnonce' => wp_create_nonce( Admin::NONCE_SETTINGS ) ) ) )['status'], array_key_exists( Settings::OPTION_KEY, $GLOBALS['fh_options'] ) ) );
$GLOBALS['fh_current_user'] = 5;
check_same( 'without the nonce: 403', 403, Admin::settings_request( array_diff_key( $form, array( '_wpnonce' => 1 ) ) )['status'] );
$reply = Admin::settings_request( $form );
$saved = Settings::get();
check_same( 'saved', array( 200, 3, 21, array( 'free', 'gratis', 'no-charge' ), 'hello@example.test' ), array( $reply['status'], $saved['reminder_days'], $saved['link_days'], $saved['free_classes'], $saved['reply_to'] ) );
check_same( 'a box that was not ticked is OFF — send by itself stays off unless somebody ticks it', array( false, false ), array( $saved['auto_send'], $saved['store_design'] ) );
check_same( 'the built-in text, saved untouched (even with Windows line ends), is stored as "use the built-in text"', array( '', '' ), array( $saved['subject'], $saved['body'] ) );
check_same( 'the option is not autoloaded: nothing reads it on an ordinary request', false, $GLOBALS['fh_autoload'][ Settings::OPTION_KEY ] );

$form['fh_lf']['auto_send'] = '1';
$form['fh_lf']['body']      = "Hello {customer_name}\n\n<script>alert(1)</script>{choices}";
$form['fh_lf']['subject']   = "About <b>#{order_number}</b>\nsecond line";
Admin::settings_request( $form );
$saved = Settings::get();
check_same( 'ticked: on', true, $saved['auto_send'] );
check_same( 'the template is kept as plain text: tags are dropped, the placeholders stay', array( "Hello {customer_name}\n\nalert(1){choices}", 'About #{order_number} second line' ), array( $saved['body'], $saved['subject'] ) );
$reply = Admin::settings_request( array( '_wpnonce' => $form['_wpnonce'], 'reset' => '1', 'fh_lf' => $form['fh_lf'] ) );
check_same( '"Back to the built-in text" empties both and keeps the rest', array( '', '', true, 3 ), array( Settings::get()['subject'], Settings::get()['body'], Settings::get()['auto_send'], Settings::get()['reminder_days'] ) );

foreach ( array(
	'a reminder of -1 days'   => array( array( 'reminder_days' => '-1' ), 'reminder_days', 2 ),
	'a reminder of 31 days'   => array( array( 'reminder_days' => '31' ), 'reminder_days', 2 ),
	'a reminder of "soon"'    => array( array( 'reminder_days' => 'soon' ), 'reminder_days', 2 ),
	'a reminder of 2.5 days'  => array( array( 'reminder_days' => '2.5' ), 'reminder_days', 2 ),
	'a reminder of 0 days'    => array( array( 'reminder_days' => '0' ), 'reminder_days', 0 ),
	'links good for 0 days'   => array( array( 'link_days' => '0' ), 'link_days', 14 ),
	'links good for 91 days'  => array( array( 'link_days' => '91' ), 'link_days', 14 ),
	'a Reply-To that is none' => array( array( 'reply_to' => 'not an address' ), 'reply_to', '' ),
	'free classes as a list'  => array( array( 'free_classes' => array( 'Free', 'free', '' ) ), 'free_classes', array( 'free' ) ),
) as $label => $case ) {
	check_same( $label, $case[2], Settings::sanitize( $case[0] )[ $case[1] ] );
}
check_same( 'a stored option that is garbage reads as the defaults', Settings::normalize( array() ), Settings::normalize( array( 'auto_send' => 0, 'reminder_days' => array(), 'link_days' => null, 'subject' => 5, 'unknown' => 'x' ) ) );

echo "\nThe Cases screen\n";
list( $cases, $store, $rooms ) = fh_shop();
$rooms->add( 2, array( 'product_id' => 813, 'species' => 'Harbor Tang', 'qty_live' => 5 ) );
// One case in every state.
$a = $cases->open_by_hand( 501, 'i:51', 1, 5 )['case']['id'];                    // Open, ready: drops under the threshold.
$rooms->lose( 1, 1 );
$cases->loss_recorded( 1, 9001, 1, 5 );                                          // Open, from a loss: 502's two gobies.
$b = $store->cases()[0]['id'];
$c = $cases->open_by_hand( 502, 'i:54', 1, 5 )['case']['id'];                    // Open, ready: another case waits on 502, but 502 stays over the threshold either way.
fh_order( 510, array( 'paid' => 900, 'shipping' => 15.00, 'first_name' => 'Emery', 'last_name' => 'Pilot', 'email' => 'emery@example.test', 'customer_id' => 45, 'lines' => array( 5101 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ), 5102 => array( 'name' => 'Harbor Tang', 'product_id' => 813, 'qty' => 2, 'total' => 460.00 ) ) ) );
$cases->open_by_hand( 510, 'i:5101', 1, 5 );                                     // Open, blocked: two cases wait on 510,
$cases->open_by_hand( 510, 'i:5102', 1, 5 );                                     // and its shipping depends on what is picked.
$d = $cases->open_by_hand( 503, 'i:55', 1, 5 )['case']['id'];
$cases->send( $d, 5 );                                                           // Sent, waiting.
foreach ( array( 505, 506, 507, 508, 509 ) as $id ) {
	fh_order( $id, array( 'paid' => 100 + $id, 'shipping' => 40.00, 'first_name' => 'Dana', 'last_name' => 'Trial', 'email' => 'dana' . $id . '@example.test', 'customer_id' => $id, 'lines' => array( $id * 10 => array( 'name' => 'Lagoon Goby', 'product_id' => 812, 'qty' => 1, 'total' => 60.00 ) ) ) );
}
$ids = array();
foreach ( array( 505 => 'refund', 506 => 'credit', 507 => 'retry', 508 => 'cancel' ) as $order_id => $choice ) {
	$id = $cases->open_by_hand( $order_id, 'i:' . ( $order_id * 10 ), 1, 5 )['case']['id'];
	$cases->send( $id, 5 );
	$cases->answer( $id, $choice, 'customer' );
	$ids[ $choice ] = $id;
}
$e = $cases->open_by_hand( 509, 'i:5090', 1, 5 )['case']['id'];
$cases->send( $e, 5 );
$store->rows[ $e ]['reminded_at'] = gmdate( 'Y-m-d H:i:s' );                     // Sent, reminded: no answer.
$cases->done( $ids['refund'], 'Refunded through the card.', 5 );                 // Done.
$h = $cases->open_by_hand( 501, 'i:52', 1, 5 )['case']['id'];
$cases->close( $h, 'mistake', 5 );                                               // Closed.

$mail_before = count( $GLOBALS['fh_mail'] );
$_GET        = array( 'page' => Lost_Fish::MENU_SLUG );
$admin       = new Admin();
ob_start();
$admin->render_page();
$html = ob_get_clean();

check_same( 'looking at the screen sends nothing', $mail_before, count( $GLOBALS['fh_mail'] ) );
check_contains( 'the three groups', 'Needs you', $html );
check_contains( '…', 'Waiting for the customer', $html );
check_contains( '…', 'Finished (latest', $html );
check_contains( 'it says send by itself is off', 'Send by itself: off — nothing is sent without your click', $html );
check_same( 'ONE "Send to customer" button per case that was not sent yet', 5, substr_count( $html, 'class="button button-primary button-hero"' ) );
check_same( '… and no other button on the page sends anything', 5, substr_count( $html, 'name="op" value="send"' ) );
check_same( '… two of them cannot be pressed: two cases wait on one order whose shipping depends on the answers, so neither difference is known', 2, substr_count( $html, 'button-hero"  disabled="disabled"' ) );
check_contains( '… two cases on an order that stays over the threshold either way need nothing typed', 'also when the fish of its other open case goes too', $html );
check_same( 'every form of a case carries what the page shows of it', substr_count( $html, 'name="op" value=' ), substr_count( $html, 'name="seen" value="' ) );
check_contains( '… and the case says why', 'other lost-fish case on this order still waits for an answer', $html );
check_contains( '… and what blocks it', 'fill it in, or confirm zero', $html );
check_contains( 'the numbers of every choice: the fish', 'Paid for the fish', $html );
check_contains( '… the shipping difference, worked out', '$25.00', $html );
check_contains( '… refund and credit less the difference', '$35.00', $html );
check_contains( '… cancel the whole order', '$535.00', $html );
check_contains( 'the e-mail preview, in a frame that can run nothing', '<iframe class="fh-lf-preview__frame" sandbox=""', $html );
check_contains( '… with the words of the e-mail', 'we lost 1 × Lagoon Goby from your order #501', $html );
check_lacks( '… and no live link in it', 'fh_lost_fish=', $html );
check_contains( 'the form carries the refund the page shows', 'name="shown_refund" value="3500"', $html );
check_contains( 'every form carries its case\'s nonce', 'name="_wpnonce" value="' . wp_create_nonce( Admin::NONCE_CASE . $a ) . '"', $html );
check_contains( 'after an answer: what to do — refund', 'Cancel order #508 and refund $100.00.', $html );
check_contains( '… credit', 'to the customer&#039;s wallet.', $html );
check_contains( '… try again, with the reminder to note it', 'note it yourself', $html );
check_contains( '… and that the site did none of it', 'the site did none of it', $html );
check_contains( 'a Done button with a note', 'placeholder="Note (optional)', $html );
check_contains( 'a case without an answer after its reminder is flagged', 'No answer', $html );
check_contains( 'a finished case shows its note', 'Note: Refunded through the card.', $html );
check_contains( '… and what was chosen, with its amount', 'The customer chose: refund the lost fish — $60.00', $html );
check_contains( 'reopen is offered on what is not open', '>Reopen</button>', $html );
check_contains( 'what the screen can reach is said plainly', 'ShipTracker is not active', $html );
check_contains( '…', 'The Requests section is not available', $html );
check_lacks( 'no nonce or token of a case leaks into another', 'fh-lf-case-0', $html );

$rooms->available = false;
ob_start();
$admin->render_page();
$html = ob_get_clean();
check_contains( 'the Clean Room is off: the screen says so, and that cases by hand still work', 'a loss cannot open a case by itself. Cases opened by hand still work', $html );
$rooms->available = true;

echo "\nNot allowed in\n";
$GLOBALS['fh_current_user'] = 6;
$died                       = false;
ob_start();
try {
	$admin->render_page();
} catch ( Fh_Died $e ) {
	$died = true;
}
check_same( 'a user without the capability gets nothing of the page', array( true, '' ), array( $died, ob_get_clean() ) );
$GLOBALS['fh_current_user'] = 5;

echo "\nOpen a case by hand: the form\n";
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'new', 'order' => '502', 'line' => 'i:54' );
ob_start();
$admin->render_page();
$html = ob_get_clean();
check_contains( 'the order and its customer', 'Order #502', $html );
check_contains( 'its lines, with what one fish was paid', '$230.00', $html );
check_contains( 'the line the order screen pointed at is marked', 'class="is-picked"', $html );
check_contains( 'a line that already has a case says so', 'case #' . $c, $html );
check_contains( 'the form posts the order, the line and its own nonce', 'name="_wpnonce" value="' . wp_create_nonce( Admin::NONCE_OPEN ) . '"', $html );
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'new', 'order' => '99999' );
ob_start();
$admin->render_page();
check_contains( 'an order that does not exist', 'There is no order #99999', ob_get_clean() );
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'new' );
ob_start();
$admin->render_page();
check_contains( 'no order asked for yet: just the question', 'Order number', ob_get_clean() );

echo "\nLog and Settings\n";
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'log' );
ob_start();
$admin->render_page();
$html = ob_get_clean();
check_contains( 'the log lists what happened', 'E-mail sent to', $html );
check_contains( '… and who did it', 'User 5', $html );
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'settings' );
ob_start();
$admin->render_page();
$html = ob_get_clean();
check_contains( 'the switch, with what it means', 'Send by itself after the undo window', $html );
check_lacks( '… not ticked', 'name="fh_lf[auto_send]" value="1"  checked', $html );
check_contains( '… and the window in minutes', '15 minutes after a loss', $html );
check_contains( 'the template fields show the built-in text to edit from', esc_textarea( Email::default_body() ), $html );
check_contains( 'the placeholders are listed', '{ship_line}', $html );
check_contains( 'a preview with invented data', 'Sample Reef Fish', $html );
$_GET = array( 'page' => Lost_Fish::MENU_SLUG, 'tab' => 'nonsense' );
ob_start();
$admin->render_page();
check_contains( 'a tab that does not exist is the Cases tab', 'Needs you', ob_get_clean() );

echo "\nThe order screen\n";
$item = $GLOBALS['fh_orders'][502]->items[53];
$GLOBALS['wpdb']->queries = 0;
ob_start();
$admin->order_line_link( 53, $item, null );
$html = ob_get_clean();
check_contains( 'a line without a case: the link that opens one, for that order and line', 'tab=new&order=502&line=i%3A53', $html );
check_contains( '…', 'Lost this fish? Open a case…', $html );
ob_start();
$admin->order_line_link( 54, $GLOBALS['fh_orders'][502]->items[54], null );
ob_get_clean();
check_same( 'the cases of the order are read once for all of its lines', 1, $GLOBALS['wpdb']->queries );
$fee       = new Fh_Item( 99, array( 'order_id' => 502, 'type' => 'fee' ) );
ob_start();
$admin->order_line_link( 99, $fee, null );
check_same( 'a fee or a shipping line gets nothing', '', ob_get_clean() );
$GLOBALS['fh_current_user'] = 6;
ob_start();
$admin->order_line_link( 53, $item, null );
check_same( 'a user without the capability sees nothing', '', ob_get_clean() );
$GLOBALS['fh_current_user'] = 5;

echo "\nThe one-time notice\n";
Admin::flash( 'Sent to the customer.', 'success' );
check_same( 'kept for the next page of the same user', array( 'message' => 'Sent to the customer.', 'type' => 'success' ), Admin::take_flash() );
check_same( '… and shown once', null, Admin::take_flash() );
Admin::flash( 'No.', 'error' );
$GLOBALS['fh_current_user'] = 6;
check_same( 'another user does not see it', null, Admin::take_flash() );

fh_finish();
