<?php
/**
 * E-mail tests — what the customer is sent: which fish was lost, when the
 * rest of the order ships (with a date, without one, with nothing else
 * left), the four choices as links with their money (an order that drops
 * under the threshold, one that stays over, a difference larger than the
 * fish, no cancel once part has shipped), the reminder, the editable
 * template (placeholders, escaping, a template that forgot the links) and
 * the send itself (to whom, from whom, never to a broken address).
 *
 * Run from the plugin root:
 *   php includes/sections/lost-fish/tests/email-test.php
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lost_Fish\Email;
use FisHotel\Misc\Sections\Lost_Fish\Money;
use FisHotel\Misc\Sections\Lost_Fish\Settings;

$case = array(
	'id'             => 12,
	'fish_name'      => 'Lagoon Goby',
	'species'        => 'Lagoon Goby',
	'qty'            => 1,
	'order_id'       => 501,
	'ship_charged'   => 1900,
	'unit_paid'      => 6000,
	'customer_name'  => 'Avery Example',
	'customer_email' => 'avery@example.test',
);
$facts = array(
	'number'        => '501',
	'symbol'        => '$',
	'first_name'    => 'Avery',
	'customer_name' => 'Avery Example',
	'email'         => 'avery@example.test',
	'ship_date'     => '2026-11-17',
	'delivery_date' => '2026-11-18',
);
$links = array(
	'refund' => 'https://example.test/?fh_lost_fish=12.refund.1.aaaa',
	'credit' => 'https://example.test/?fh_lost_fish=12.credit.1.bbbb',
	'retry'  => 'https://example.test/?fh_lost_fish=12.retry.1.cccc',
	'cancel' => 'https://example.test/?fh_lost_fish=12.cancel.1.dddd',
);
$drop     = Money::choices( array( 'unit_paid' => 6000, 'qty' => 1, 'ship_diff' => 3099, 'order_total' => 53900 ) );
$stays    = Money::choices( array( 'unit_paid' => 6000, 'qty' => 1, 'ship_diff' => 0, 'order_total' => 71900 ) );
$settings = Settings::normalize( array( 'store_design' => false ) );

echo "When the rest of the order ships\n";
check_same( 'with a date on the order: the ship date (the day before) and the delivery date', 'The rest of your order is still on its way as planned: it ships on Tuesday, November 17, 2026, for delivery on Wednesday, November 18, 2026.', Email::ship_line( $facts ) );
check_same( 'without one: "we will confirm your ship date" — no date is made up', 'The rest of your order is not affected. We will confirm your ship date with you.', Email::ship_line( array_merge( $facts, array( 'ship_date' => '', 'delivery_date' => '' ) ) ) );
check_same( 'the lost fish was all that was left', 'There is nothing else left to ship on this order.', Email::ship_line( $facts, false ) );
check_same( 'a date that is no date is no date', '', Email::date_label( '2026-02-30' ) );

echo "\nThe four choices — the order drops under the threshold\n";
$rows = Email::choice_rows( $case, $drop, $facts, $links );
check_same( 'four, in order', array( 'refund', 'credit', 'retry', 'cancel' ), array_column( $rows, 'choice' ) );
check_same( 'refund and credit carry the fish MINUS the shipping difference; try again no money; cancel everything paid', array( '$29.01', '$29.01', '', '$539.00' ), array_column( $rows, 'money' ) );
check_same( 'the refund, spelled out', 'We refund $29.01 to the way you paid: $60.00 for the fish, less $30.99 shipping. Without this fish your order is no longer over the amount for reduced shipping, so its shipping is $49.99 instead of the $19.00 you paid.', $rows[0]['text'] );
check_same( 'the credit says the same about shipping — a credit does not keep the cheaper shipping', 'We add $29.01 to your store credit: $60.00 for the fish, less $30.99 shipping. Without this fish your order is no longer over the amount for reduced shipping, so its shipping is $49.99 instead of the $19.00 you paid.', $rows[1]['text'] );
check_contains( 'try again leaves this order\'s shipping as it is', 'the shipping of your order stays as it is', $rows[2]['text'] );
check_same( 'cancel names the order and the whole amount', 'We cancel order #501 and refund everything you paid for it: $539.00.', $rows[3]['text'] );
check_same( 'each choice has its own link', array_values( $links ), array_column( $rows, 'url' ) );

echo "\n… stays over it\n";
$rows = Email::choice_rows( $case, $stays, $facts, $links );
check_same( 'the whole price of the fish, and not a word about shipping', array( '$60.00', 'We refund $60.00 — what you paid for this fish — to the way you paid.' ), array( $rows[0]['money'], $rows[0]['text'] ) );
check_lacks( '…', 'shipping', $rows[1]['text'] );

echo "\n… the difference is more than the fish\n";
$more = Money::choices( array( 'unit_paid' => 1500, 'qty' => 1, 'ship_diff' => 3099, 'order_total' => 51900 ) );
$rows = Email::choice_rows( $case, $more, $facts, $links );
check_same( 'nothing to refund, and it says why', array( '$0.00', true, true ), array( $rows[0]['money'], false !== strpos( $rows[0]['text'], 'There would be nothing to refund: you paid $15.00 for the fish, and the shipping difference is $30.99.' ), false !== strpos( $rows[0]['text'], 'instead of the $19.00 you paid' ) ) );

echo "\n… part of the order has shipped\n";
$shipped = Money::choices( array( 'unit_paid' => 6000, 'qty' => 1, 'ship_diff' => 0, 'order_total' => 71900, 'offer_cancel' => false ) );
check_same( 'three choices: cancelling the whole order is not offered', array( 'refund', 'credit', 'retry' ), array_column( Email::choice_rows( $case, $shipped, $facts, $links ), 'choice' ) );

echo "\nThe message\n";
$message = Email::build( $case, $facts, $drop, $links, $settings );
check_same( 'to the customer of the order', 'avery@example.test', $message['to'] );
check_same( 'the subject names the order and the shop', 'About your order #501 at Example Reef Shop: a fish we lost', $message['subject'] );
check_contains( 'it greets by first name', 'Hi Avery,', $message['html'] );
check_contains( 'it says which fish was lost, from which order', 'we lost 1 × Lagoon Goby from your order #501', $message['html'] );
check_contains( 'it says when the rest ships', 'it ships on Tuesday, November 17, 2026, for delivery on Wednesday, November 18, 2026', $message['html'] );
foreach ( $links as $choice => $url ) {
	check_contains( 'the ' . $choice . ' link is in it', 'href="' . $url . '"', $message['html'] );
}
check_contains( 'the money sits beside refund', 'Refund the lost fish — $29.01</a>', $message['html'] );
check_contains( '… and beside credit', 'Take a store credit — $29.01</a>', $message['html'] );
check_contains( 'it makes the shipping plain', 'its shipping is $49.99 instead of the $19.00 you paid', $message['html'] );
check_contains( 'it says nothing changes until they confirm', 'Nothing changes until you pick one and press Confirm', $message['html'] );
check_same( 'four links, no more', 4, substr_count( $message['html'], 'fh_lost_fish=' ) );
check_same( 'sent as HTML', array( 'Content-Type: text/html; charset=UTF-8' ), $message['headers'] );
check( 'a whole HTML document', 0 === strpos( $message['html'], '<!DOCTYPE html>' ) && false !== strpos( $message['html'], '</html>' ) );

$message = Email::build( $case, array_merge( $facts, array( 'ship_date' => '', 'delivery_date' => '' ) ), $drop, $links, $settings );
check_contains( 'without a ship date: we will confirm it', 'We will confirm your ship date with you.', $message['html'] );
check_lacks( '… and no date anywhere', '2026', $message['html'] );

$message = Email::build( $case, $facts, $drop, $links, $settings, array( 'rest_left' => false ) );
check_contains( 'nothing else left to ship', 'There is nothing else left to ship on this order.', $message['html'] );

$two     = array_merge( $case, array( 'qty' => 2 ) );
$message = Email::build( $two, $facts, $drop, $links, $settings );
check_contains( 'two fish of a line', 'we lost 2 × Lagoon Goby', $message['html'] );

$message = Email::build( $case, array_merge( $facts, array( 'first_name' => '', 'customer_name' => '' ) ), $drop, $links, $settings );
check_contains( 'no name on the order: a neutral greeting', 'Hi there,', $message['html'] );

echo "\nThe reminder\n";
$message = Email::build( $case, $facts, $drop, $links, $settings, array( 'reminder' => true ) );
check_same( 'the subject says it is one', 'Reminder: About your order #501 at Example Reef Shop: a fish we lost', $message['subject'] );
check_contains( 'the text says so first', 'we have not heard back yet', $message['html'] );
check_same( '… and brings the four links again', 4, substr_count( $message['html'], 'fh_lost_fish=' ) );

echo "\nThe preview\n";
$preview = Email::build( $case, $facts, $drop, array( 'refund' => '#', 'credit' => '#', 'retry' => '#', 'cancel' => '#' ), $settings );
check_same( 'the same words with links that go nowhere', array( 0, 4 ), array( substr_count( $preview['html'], 'fh_lost_fish=' ), substr_count( $preview['html'], 'href="#"' ) ) );

echo "\nThe template\n";
check_same( 'an empty template is the built-in one', array( Email::default_subject(), Email::default_body() ), array_values( Email::template( Settings::normalize( array() ) ) ) );
foreach ( array( 'customer_name', 'fish_line', 'order_number', 'ship_line', 'choices', 'site_name' ) as $placeholder ) {
	check_contains( 'the built-in text uses {' . $placeholder . '}', '{' . $placeholder . '}', Email::default_subject() . Email::default_body() );
}

$custom = Settings::normalize(
	array(
		'store_design' => false,
		'subject'      => "Order {order_number}:\n {fish} {choices}",
		'body'         => "Dear {customer_name},\n\nYour {qty} {fish} ({fish_line}) did not make it.\nShip: {ship_date} / arrive: {delivery_date}\n\n{choices}\n\nBye from {site_name} <b>bold</b> & co",
	)
);
$message = Email::build( $case, $facts, $drop, $links, $custom );
check_same( 'a subject of one line, its placeholders filled, never the links', 'Order 501: Lagoon Goby', $message['subject'] );
check_contains( 'every placeholder is filled', 'Your 1 Lagoon Goby (1 × Lagoon Goby) did not make it.<br />' . "\n" . 'Ship: Tuesday, November 17, 2026 / arrive: Wednesday, November 18, 2026', $message['html'] );
check_contains( 'a blank line makes a paragraph', '<p style="margin:0 0 16px;">Dear Avery,</p>', $message['html'] );
check_contains( 'the template is plain text: markup in it is shown, not run', 'Bye from Example Reef Shop &lt;b&gt;bold&lt;/b&gt; &amp; co', $message['html'] );
check( 'the links sit where {choices} was', strpos( $message['html'], 'did not make it' ) < strpos( $message['html'], 'fh_lost_fish=' ) && strpos( $message['html'], 'fh_lost_fish=' ) < strpos( $message['html'], 'Bye from' ) );

$forgot  = Settings::normalize( array( 'store_design' => false, 'body' => 'Dear {customer_name}, please write back.' ) );
$message = Email::build( $case, $facts, $drop, $links, $forgot );
check_same( 'a template that forgot {choices} still carries the four links', 4, substr_count( $message['html'], 'fh_lost_fish=' ) );

$evil    = array_merge( $case, array( 'fish_name' => 'Goby <script>alert(1)</script>' ) );
$message = Email::build( $evil, array_merge( $facts, array( 'first_name' => '<img src=x onerror=alert(1)>' ) ), $drop, $links, $settings );
check_lacks( 'a fish name cannot bring markup into the e-mail', '<script>', $message['html'] );
check_lacks( '… nor can a customer name', '<img src=x', $message['html'] );
check_contains( '… it is shown as text', 'Goby &lt;script&gt;alert(1)&lt;/script&gt;', $message['html'] );

$reply   = Settings::normalize( array( 'store_design' => false, 'reply_to' => 'hello@example.test' ) );
$message = Email::build( $case, $facts, $drop, $links, $reply );
check_same( 'a Reply-To address, when one is set', 'Reply-To: hello@example.test', $message['headers'][1] );

add_filter(
	'fishotel_lostfish_email',
	static function ( $message, $case ) {
		$message['subject'] = 'Changed for case ' . $case['id'];
		return $message;
	},
	10,
	2
);
check_same( 'other code can shape the message through fishotel_lostfish_email', 'Changed for case 12', Email::build( $case, $facts, $drop, $links, $settings )['subject'] );
$GLOBALS['fh_filters']['fishotel_lostfish_email'] = array();

echo "\nSending\n";
$message = Email::build( $case, $facts, $drop, $links, $settings );
check( 'wp_mail takes it', Email::send( $message ) );
$mail = $GLOBALS['fh_mail'][0];
check_same( 'to, subject, body and headers as built', array( 'avery@example.test', $message['subject'], $message['html'], $message['headers'] ), array( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'] ) );
check_same( 'without a store sender, WordPress\'s own', array( 'wordpress@example.test', 'WordPress' ), array( $mail['from'], $mail['from_name'] ) );

$GLOBALS['fh_options']['woocommerce_email_from_address'] = 'orders@example.test';
$GLOBALS['fh_options']['woocommerce_email_from_name']    = 'Example Reef Shop';
Email::send( $message );
$mail = $GLOBALS['fh_mail'][1];
check_same( 'with one: the store\'s own sender, like its order e-mails', array( 'orders@example.test', 'Example Reef Shop' ), array( $mail['from'], $mail['from_name'] ) );
check_same( '… and the sender is not left changed for the next e-mail of the site', array( 'x@example.test', 'X' ), array( apply_filters( 'wp_mail_from', 'x@example.test' ), apply_filters( 'wp_mail_from_name', 'X' ) ) );

check_same( 'no address: nothing is handed to wp_mail', array( false, 2 ), array( Email::send( array_merge( $message, array( 'to' => '' ) ) ), count( $GLOBALS['fh_mail'] ) ) );
check_same( 'a broken address: nothing is handed to wp_mail', array( false, 2 ), array( Email::send( array_merge( $message, array( 'to' => 'not an address' ) ) ), count( $GLOBALS['fh_mail'] ) ) );
$GLOBALS['fh_mail_fail'] = true;
check_same( 'wp_mail refuses: the caller is told', false, Email::send( $message ) );

fh_finish();
