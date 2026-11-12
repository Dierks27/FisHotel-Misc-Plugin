<?php
/**
 * Rules, tokens, settings and the schema — pure: what a request from a
 * form must look like (the person, the fish, the size against a standard
 * list or as free text, the quantity, the fee), the rules of a private
 * link (label, fee, expiry at the end of a site-zone day, maximum uses),
 * the fee timers and the "still want it?" timers, the signed pay /
 * personal / answer tokens (a changed amount is refused), the settings
 * (defaults, sanitising, the e-mails falling back to their defaults), and
 * the store's schema (dbDelta-friendly, the log append-only).
 *
 * Run from the plugin root:  php includes/sections/requests/tests/rules-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Settings;
use FisHotel\Misc\Sections\Requests\Store;
use FisHotel\Misc\Sections\Requests\Tokens;
use FisHotel\Misc\Sections\Requests\Urls;

echo "States\n";
check_same( 'ten states, in travel order', array( 'new', 'awaiting_payment', 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered', 'filled', 'cancelled', 'expired' ), Rules::STATES );
check( 'open and closed states make up all of them', array() === array_diff( Rules::STATES, array_merge( Rules::OPEN_STATES, Rules::CLOSED_STATES ) ) && 10 === count( Rules::OPEN_STATES ) + count( Rules::CLOSED_STATES ) );
check( 'every state has a label for the owner', count( Rules::STATES ) === count( array_intersect_key( Rules::state_labels(), array_flip( Rules::STATES ) ) ) );
foreach ( Rules::STATES as $fh_state ) {
	check( "a customer reads plain words for \"$fh_state\"", '' !== Rules::state_words( array( 'state' => $fh_state ) ) );
}
check( 'the four sources', array( 'link', 'hand', 'lost_fish', 'public' ) === Rules::SOURCES );
check_contains( 'an owed fish that is ready reads "already paid for"', 'already paid for', Rules::state_words( array( 'state' => 'offered', 'owed' => 1 ) ) );

echo "\nThe person\n";
$person = Rules::person( array( 'name' => "  Pat   <b>Example</b>\n", 'email' => ' Pat@Example.TEST ', 'handle' => 'reef_pat' ) );
check_same( 'the name is plain text on one line', 'Pat Example', $person['name'] );
check_same( 'the e-mail is trimmed and lower-cased', 'pat@example.test', $person['email'] );
check_same( 'nothing wrong with a full person', array(), Rules::person_errors( $person, true ) );
check( 'a missing name and handle is an error', 1 === count( Rules::person_errors( Rules::person( array( 'email' => 'a@example.test' ) ), true ) ) );
check_same( 'a handle alone is enough of a name', array(), Rules::person_errors( Rules::person( array( 'handle' => 'reef_pat', 'email' => 'a@example.test' ) ), true ) );
check( 'a link request needs an e-mail', 1 === count( Rules::person_errors( Rules::person( array( 'name' => 'Pat' ) ), true ) ) );
check_same( 'a request by hand does not', array(), Rules::person_errors( Rules::person( array( 'name' => 'Pat' ) ), false ) );
check( 'a mistyped e-mail is an error even by hand', 1 === count( Rules::person_errors( Rules::person( array( 'name' => 'Pat', 'email' => 'pat at example' ) ), false ) ) );
check_same( 'an e-mail with a line break is no e-mail', '', Rules::email( "pat@example.test\nBcc: x@example.test" ) );
check_same( 'an array is no e-mail', '', Rules::email( array( 'pat@example.test' ) ) );

echo "\nThe fish row\n";
$sizes = array(
	array( 'slug' => '1-2', 'label' => '1 - 2"' ),
	array( 'slug' => '2-3-5', 'label' => '2 - 3.5"' ),
);
$row   = Rules::fish_row( array( 'fish' => ' Blue  Test Wrasse ', 'size_slug' => '2-3-5', 'qty' => '2', 'note' => "a pair\nplease" ), $sizes );
check_same( 'a good row has no errors', array(), $row['errors'] );
check_same( 'the fish is trimmed', 'Blue Test Wrasse', $row['row']['fish'] );
check_same( 'the size is the slug with the label as it reads now', array( '2-3-5', '2 - 3.5"' ), array( $row['row']['size_slug'], $row['row']['size_label'] ) );
check_same( 'the quantity is a number', 2, $row['row']['qty'] );
check_same( 'the note keeps its line break', "a pair\nplease", $row['row']['note'] );
check( 'a row without a fish is refused', 1 === count( Rules::fish_row( array( 'qty' => 1 ), $sizes )['errors'] ) );
check( 'a size that is not in the list is refused', 1 === count( Rules::fish_row( array( 'fish' => 'Red Test Goby', 'size_slug' => 'huge' ), $sizes )['errors'] ) );
check_same( 'no size is fine', null, Rules::fish_row( array( 'fish' => 'Red Test Goby' ), $sizes )['row']['size_slug'] );
$kept = Rules::fish_row( array( 'fish' => 'Red Test Goby', 'size_slug' => 'old-size' ), $sizes, array( 'size_slug' => 'old-size', 'size_label' => 'Old size' ) );
check_same( 'the row\'s own stored size stays selectable when it left the list', array( array(), 'old-size', 'Old size' ), array( $kept['errors'], $kept['row']['size_slug'], $kept['row']['size_label'] ) );
$free = Rules::fish_row( array( 'fish' => 'Red Test Goby', 'size_label' => 'About 3 inches' ), null );
check_same( 'without a standard list the size is free text', array( 'about-3-inches', 'About 3 inches' ), array( $free['row']['size_slug'], $free['row']['size_label'] ) );
$given = Rules::fish_row( array( 'fish' => 'Red Test Goby', 'size_slug' => '2-3-5', 'size_label' => '2 - 3.5"' ), null );
check_same( 'a slug handed over by another section is kept as it is', array( '2-3-5', '2 - 3.5"' ), array( $given['row']['size_slug'], $given['row']['size_label'] ) );
foreach ( array( '0', '-1', '100', '1.5', 'two', array( 2 ) ) as $fh_bad ) {
	check( 'quantity ' . fh_test_export( $fh_bad ) . ' is refused', null === Rules::qty( $fh_bad ) );
}
check_same( 'an empty quantity is 1', 1, Rules::qty( '' ) );
check_same( 'the largest quantity is 99', 99, Rules::qty( '99' ) );
check_same( 'species and product ids are kept when given', array( 12, 34 ), array( Rules::fish_row( array( 'fish' => 'X', 'species_id' => '12', 'product_id' => 34 ) )['row']['species_id'], Rules::fish_row( array( 'fish' => 'X', 'species_id' => '12', 'product_id' => 34 ) )['row']['product_id'] ) );
check_same( 'tags never survive in a fish name', 'alert(1) Wrasse', Rules::text( '<script>alert(1)</script> Wrasse' ) );

echo "\nForm rows\n";
$rows = Rules::form_rows( array( array( 'fish' => 'A' ), array( 'fish' => '', 'note' => '' ), 'junk', array( 'fish' => '', 'note' => 'see above' ), array( 'fish' => 'B' ) ) );
check_same( 'empty rows and junk are dropped, a row with only a note is kept to be refused', 3, count( $rows ) );
check( 'more than ten rows come back as eleven (the caller refuses the sheet)', 11 === count( Rules::form_rows( array_fill( 0, 15, array( 'fish' => 'A' ) ) ) ) );

echo "\nThe fee\n";
check_same( 'an empty fee is none', 0.0, Rules::fee( '' ) );
check_same( 'a typed fee with a dollar sign', 12.5, Rules::fee( '$12.50' ) );
check_same( 'a float', 18.0, Rules::fee( 18.0 ) );
foreach ( array( '-5', '12.345', 'ten', '10000', array( 5 ), '1e3' ) as $fh_bad ) {
	check( 'fee ' . fh_test_export( $fh_bad ) . ' is refused', null === Rules::fee( $fh_bad ) );
}
check_same( 'money reads with two decimals', '$1,234.50', Rules::money( 1234.5 ) );

echo "\nGrouping key\n";
check_same( 'a species-list fish groups by its id', 's:7', Rules::fish_key( array( 'fish' => 'Blue Test Wrasse', 'species_id' => 7, 'product_id' => 3 ) ) );
check_same( 'else by its product', 'p:3', Rules::fish_key( array( 'fish' => 'Blue Test Wrasse', 'product_id' => 3 ) ) );
check_same( 'else by the name without case or extra spaces', 't:blue test wrasse', Rules::fish_key( array( 'fish' => '  BLUE   test Wrasse' ) ) );

echo "\nPrivate links\n";
$tz   = new DateTimeZone( 'America/Chicago' );
$link = Rules::link( array( 'label' => ' Spring batch ', 'fee' => '12', 'expires' => '2026-03-31', 'max_uses' => '20' ), $tz );
check_same( 'a full link is clean', array(), $link['errors'] );
check_same( 'label, fee and uses', array( 'Spring batch', 12.0, 20 ), array( $link['link']['label'], $link['link']['fee_amount'], $link['link']['max_uses'] ) );
check_same( 'the expiry is the END of that day in the site zone, stored in UTC', '2026-04-01 04:59:59', $link['link']['expires_at'] );
check( 'a link needs a label', 1 === count( Rules::link( array( 'label' => '' ), $tz )['errors'] ) );
check( 'a bad date is refused', 1 === count( Rules::link( array( 'label' => 'x', 'expires' => '2026-02-30' ), $tz )['errors'] ) );
check( 'a bad fee is refused', 1 === count( Rules::link( array( 'label' => 'x', 'fee' => 'lots' ), $tz )['errors'] ) );
check( 'a bad number of uses is refused', 1 === count( Rules::link( array( 'label' => 'x', 'max_uses' => 'many' ), $tz )['errors'] ) );
$open = Rules::link( array( 'label' => 'x' ), $tz )['link'];
check_same( 'no fee, no expiry, no limit by default', array( 0.0, null, 0 ), array( $open['fee_amount'], $open['expires_at'], $open['max_uses'] ) );

$now  = strtotime( '2026-03-31 20:00:00 UTC' );
$base = array( 'id' => 1, 'active' => true, 'expires_at' => '2026-04-01 04:59:59', 'max_uses' => 2, 'uses' => 1 );
check_same( 'a live link has no problem', '', Rules::link_problem( $base, $now ) );
check_same( 'on its last day it still works in the evening (site time)', '', Rules::link_problem( $base, strtotime( '2026-04-01 04:00:00 UTC' ) ) );
check_same( 'after midnight site time it has expired', 'expired', Rules::link_problem( $base, strtotime( '2026-04-01 05:00:00 UTC' ) ) );
check_same( 'at its maximum uses it is used up', 'used_up', Rules::link_problem( array_merge( $base, array( 'uses' => 2 ) ), $now ) );
check_same( 'switched off', 'inactive', Rules::link_problem( array_merge( $base, array( 'active' => false ) ), $now ) );
check_same( 'no link at all', 'missing', Rules::link_problem( null, $now ) );
check_same( 'no limit means any number of uses', '', Rules::link_problem( array_merge( $base, array( 'max_uses' => 0, 'uses' => 999 ) ), $now ) );
foreach ( array( 'missing', 'inactive', 'expired', 'used_up' ) as $fh_problem ) {
	check( "\"$fh_problem\" is said in words", '' !== Rules::link_problem_words( $fh_problem ) );
}

echo "\nFee timers\n";
$approved = strtotime( '2026-03-01 12:00:00 UTC' );
$carrier  = array( 'id' => 5, 'fee_ref' => 5, 'fee_amount' => 12.0, 'state' => 'awaiting_payment', 'approved_at' => Rules::utc( $approved ) );
check( 'the carrier of an invoice', Rules::is_carrier( $carrier ) );
check( 'a request on somebody else\'s invoice is not a carrier', ! Rules::is_carrier( array( 'id' => 6, 'fee_ref' => 5, 'fee_amount' => 0 ) ) );
check( 'no reminder after two days', ! Rules::reminder_due( $carrier, $approved + 2 * 86400, 3 ) );
check( 'a reminder after three days', Rules::reminder_due( $carrier, $approved + 3 * 86400, 3 ) );
check( 'only ONE reminder', ! Rules::reminder_due( array_merge( $carrier, array( 'reminded_at' => '2026-03-04 12:00:00' ) ), $approved + 5 * 86400, 3 ) );
check( 'no reminder once paid', ! Rules::reminder_due( array_merge( $carrier, array( 'fee_paid_at' => '2026-03-02 12:00:00' ) ), $approved + 5 * 86400, 3 ) );
check( 'not expired after six days', ! Rules::fee_expired( $carrier, $approved + 6 * 86400, 7 ) );
check( 'expired after seven days', Rules::fee_expired( $carrier, $approved + 7 * 86400, 7 ) );
check( 'a request that is on the list never expires for its fee', ! Rules::fee_expired( array_merge( $carrier, array( 'state' => 'waiting' ) ), $approved + 30 * 86400, 7 ) );
check_same( 'days left: 7 on the day of approval', 7, Rules::fee_days_left( $carrier, $approved, 7 ) );
check_same( 'days left: 4 after three days', 4, Rules::fee_days_left( $carrier, $approved + 3 * 86400, 7 ) );
check_same( 'days left never reads 0', 1, Rules::fee_days_left( $carrier, $approved + 10 * 86400, 7 ) );

echo "\n\"Still want it?\" timers\n";
$waiting = array( 'id' => 9, 'state' => 'waiting', 'source' => 'link', 'owed' => 0, 'created_at' => '2025-10-01 10:00:00', 'approved_at' => '2025-10-02 10:00:00' );
check( 'not asked after three months', ! Rules::ask_due( $waiting, strtotime( '2026-01-01 10:00:00 UTC' ), 4 ) );
check( 'asked after four months', Rules::ask_due( $waiting, strtotime( '2026-02-02 10:00:00 UTC' ), 4 ) );
check( 'a request added by hand is never asked', ! Rules::ask_due( array_merge( $waiting, array( 'source' => 'hand' ) ), strtotime( '2027-01-01 10:00:00 UTC' ), 4 ) );
check( 'an owed fish is never asked about', ! Rules::ask_due( array_merge( $waiting, array( 'owed' => 1 ) ), strtotime( '2027-01-01 10:00:00 UTC' ), 4 ) );
check( 'a lost-fish request is never asked', ! Rules::ask_due( array_merge( $waiting, array( 'source' => 'lost_fish' ) ), strtotime( '2027-01-01 10:00:00 UTC' ), 4 ) );
check( 'not asked twice', ! Rules::ask_due( array_merge( $waiting, array( 'asked_at' => '2026-02-02 10:00:00' ) ), strtotime( '2026-03-02 10:00:00 UTC' ), 4 ) );
check( '"keep it" starts the clock again', ! Rules::ask_due( array_merge( $waiting, array( 'kept_at' => '2026-02-10 10:00:00' ) ), strtotime( '2026-05-01 10:00:00 UTC' ), 4 ) && Rules::ask_due( array_merge( $waiting, array( 'kept_at' => '2026-02-10 10:00:00' ) ), strtotime( '2026-06-11 10:00:00 UTC' ), 4 ) );
check( 'a request that is on order is not asked', ! Rules::ask_due( array_merge( $waiting, array( 'state' => 'on_order' ) ), strtotime( '2027-01-01 10:00:00 UTC' ), 4 ) );
$asked = array_merge( $waiting, array( 'asked_at' => '2026-02-02 10:00:00' ) );
check( 'no expiry 13 days after asking', ! Rules::ask_expired( $asked, strtotime( '2026-02-15 10:00:00 UTC' ) ) );
check( 'expiry 14 days after asking', Rules::ask_expired( $asked, strtotime( '2026-02-16 10:00:00 UTC' ) ) );
check( 'never expired when nobody asked', ! Rules::ask_expired( $waiting, strtotime( '2030-01-01 10:00:00 UTC' ) ) );

echo "\nAge in words\n";
$t0 = strtotime( '2026-01-01 00:00:00 UTC' );
check_same( 'today', 'today', Rules::age_words( $t0, $t0 + 3600 ) );
check_same( '1 day', '1 day', Rules::age_words( $t0, $t0 + 86400 ) );
check_same( '3 days', '3 days', Rules::age_words( $t0, $t0 + 3 * 86400 ) );
check_same( '3 weeks', '3 weeks', Rules::age_words( $t0, $t0 + 22 * 86400 ) );
check_same( '4 months', '4 months', Rules::age_words( $t0, $t0 + 123 * 86400 ) );
check_same( '1 year', '1 year', Rules::age_words( $t0, $t0 + 400 * 86400 ) );

echo "\nWho may cancel\n";
check( 'a customer may cancel an open request', Rules::can_customer_cancel( array( 'state' => 'waiting', 'owed' => 0 ) ) );
check( 'not a closed one', ! Rules::can_customer_cancel( array( 'state' => 'filled', 'owed' => 0 ) ) );
check( 'not an owed one (the shop settles that)', ! Rules::can_customer_cancel( array( 'state' => 'waiting', 'owed' => 1 ) ) );
check( 'owned by its account', Rules::owned_by_user( array( 'user_id' => 7 ), 7 ) );
check( 'not by another account', ! Rules::owned_by_user( array( 'user_id' => 7 ), 8 ) );
check( 'a request without an account is nobody\'s by account', ! Rules::owned_by_user( array( 'user_id' => 0 ), 0 ) );
check( 'owned by its e-mail address, whatever the case', Rules::owned_by_email( array( 'email' => 'pat@example.test' ), 'PAT@example.test' ) );
check( 'not by another address', ! Rules::owned_by_email( array( 'email' => 'pat@example.test' ), 'sam@example.test' ) );
check( 'a request without an address is nobody\'s by address', ! Rules::owned_by_email( array( 'email' => '' ), '' ) );

echo "\nThe pay token\n";
$token = Tokens::pay( 42, 12.5 );
check( 'shape: id-cents-signature', 1 === preg_match( '/^42-1250-[a-f0-9]{32}$/', $token ) );
check_same( 'it reads back as the request and the amount', array( 'id' => 42, 'amount' => 12.5 ), Tokens::read_pay( $token ) );
check( 'a CHANGED AMOUNT is refused', null === Tokens::read_pay( str_replace( '-1250-', '-100-', $token ) ) );
check( 'a changed request is refused', null === Tokens::read_pay( preg_replace( '/^42-/', '43-', $token ) ) );
check( 'a changed signature is refused', null === Tokens::read_pay( substr( $token, 0, -1 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' ) ) );
check( 'junk is refused', null === Tokens::read_pay( 'abc' ) && null === Tokens::read_pay( array( $token ) ) && null === Tokens::read_pay( '' ) );
check( 'a zero amount is refused even when signed', null === Tokens::read_pay( '42-0-' . Tokens::pay_signature( 42, 0 ) ) );
check( 'the cart line check accepts the genuine parts', Tokens::check_pay( 42, 12.5, Tokens::pay_signature( 42, 12.5 ) ) );
check( 'and refuses another amount with the same signature', ! Tokens::check_pay( 42, 1.0, Tokens::pay_signature( 42, 12.5 ) ) );
check_same( 'the pay link', 'https://example.test/?fh_request_pay=' . $token, Urls::pay( 42, 12.5 ) );

echo "\nThe personal token\n";
$personal = Tokens::person( 'Pat@Example.test' );
check( 'no e-mail address is readable in it', false === strpos( $personal, '@' ) && false === strpos( $personal, 'pat' ) );
check_same( 'it reads back as the address', 'pat@example.test', Tokens::read_person( $personal ) );
check_same( 'a changed signature gives nothing', '', Tokens::read_person( substr( $personal, 0, -1 ) . ( 'a' === substr( $personal, -1 ) ? 'b' : 'a' ) ) );
$other = Tokens::person( 'sam@example.test' );
$mixed = explode( '.', $other )[0] . '.' . explode( '.', $personal )[1];
check_same( 'another address with this signature gives nothing', '', Tokens::read_person( $mixed ) );
check_same( 'no token for no address', '', Tokens::person( 'not an address' ) );
check_same( 'junk gives nothing', '', Tokens::read_person( 'x.y' ) );

echo "\nThe answer token\n";
$request = array( 'id' => 77, 'email' => 'pat@example.test' );
$answer  = Tokens::answer( $request );
check_same( 'it names its request', 77, Tokens::answer_id( $answer ) );
check( 'it is the token of that request', Tokens::check_answer( $answer, $request ) );
check( 'not of another request', ! Tokens::check_answer( $answer, array( 'id' => 78, 'email' => 'pat@example.test' ) ) );
check( 'not once the request belongs to another address', ! Tokens::check_answer( $answer, array( 'id' => 77, 'email' => 'sam@example.test' ) ) );
check( 'a request without an address has no answer link', '' === Urls::answer( array( 'id' => 77, 'email' => '' ), 'keep' ) );
check_contains( 'the keep link', '&do=keep', Urls::answer( $request, 'keep' ) );
check_contains( 'the cancel link', '&do=cancel', Urls::answer( $request, 'cancel' ) );

echo "\nPrivate link tokens\n";
$a = Tokens::link();
$b = Tokens::link();
check( '32 hex characters', Tokens::is_link( $a ) && 32 === strlen( $a ) );
check( 'never the same twice', $a !== $b );
check( 'anything else is not a link token', ! Tokens::is_link( 'abc' ) && ! Tokens::is_link( array( $a ) ) && ! Tokens::is_link( strtoupper( $a ) ) );
check_same( 'the form link', 'https://example.test/?fh_request=' . $a, Urls::form( $a ) );

echo "\nSettings\n";
$defaults = Settings::defaults();
check_same( 'hidden from the public by default', 0, $defaults['public_open'] );
check_same( 'people are told by themselves by default', 1, $defaults['auto_offer'] );
check_same( 'reminder after 3 days, expiry after 7, still-want-it after 4 months, 5 open requests', array( 3, 7, 4, 5 ), array( $defaults['reminder_days'], $defaults['expire_days'], $defaults['ask_months'], $defaults['public_cap'] ) );
check_same( 'the "came from" list', array( 'e-mail', 'in person', 'local', 'long-time client', 'forum', 'phone', 'other' ), $defaults['came_from'] );
check_same( 'five e-mails', Settings::MAIL_KINDS, array_keys( $defaults['mail'] ) );
foreach ( $defaults['mail'] as $fh_kind => $fh_mail ) {
	check( "the \"$fh_kind\" e-mail is on, with a subject and a body", 1 === $fh_mail['on'] && '' !== $fh_mail['subject'] && '' !== $fh_mail['body'] );
}
check_contains( 'the invoice names the pay link', '{pay_url}', $defaults['mail']['invoice']['body'] );
check_contains( 'the invoice says where the money goes', 'wallet', $defaults['mail']['invoice']['body'] );
check_contains( 'still-want-it has a keep link', '{keep_url}', $defaults['mail']['still_want']['body'] );
check_contains( 'and a cancel link', '{cancel_url}', $defaults['mail']['still_want']['body'] );

check_same( 'an empty stored option gives the defaults', $defaults, Settings::merge( array() ) );
$merged = Settings::merge( array( 'auto_offer' => 0, 'mail' => array( 'ready' => array( 'on' => 0, 'subject' => '', 'body' => 'Custom body' ) ), 'junk' => 1 ) );
check_same( 'a stored switch wins', 0, $merged['auto_offer'] );
check( 'unknown keys are dropped', ! isset( $merged['junk'] ) );
check_same( 'an e-mail switched off stays off', 0, $merged['mail']['ready']['on'] );
check_same( 'its empty subject falls back to the default', $defaults['mail']['ready']['subject'], $merged['mail']['ready']['subject'] );
check_same( 'its own body is kept', 'Custom body', $merged['mail']['ready']['body'] );

$clean = Settings::sanitize(
	array(
		'public_open'     => '1',
		'default_fee'     => '18.50',
		'public_cap'      => '500',
		'reminder_days'   => '9',
		'expire_days'     => '4',
		'ask_months'      => 'x',
		'came_from'       => "e-mail\n\n  Reef   club \nE-MAIL\n<b>show</b>",
		'deposit_product' => '123456',
		'mail'            => array(
			'invoice' => array( 'on' => '1', 'subject' => " Pay <i>now</i>\n", 'body' => "Line one\r\n\r\nLine two" ),
			'ready'   => array( 'subject' => '', 'body' => '' ),
			'unknown' => array( 'on' => 1 ),
		),
	)
);
check_same( 'open to the public', 1, $clean['public_open'] );
check_same( 'the default fee', 18.5, $clean['default_fee'] );
check_same( 'the cap is clamped', 100, $clean['public_cap'] );
check( 'the expiry is always after the reminder', $clean['expire_days'] > $clean['reminder_days'] );
check_same( 'a bad number keeps the default', 4, $clean['ask_months'] );
check_same( 'the "came from" list: no empties, no repeats, no tags', array( 'e-mail', 'Reef club', 'show' ), $clean['came_from'] );
check_same( 'a long product id is kept', 123456, $clean['deposit_product'] );
check_same( 'an unticked switch is off', 0, $clean['auto_offer'] );
check_same( 'the subject is one plain line', 'Pay now', $clean['mail']['invoice']['subject'] );
check_same( 'the body keeps its paragraphs', "Line one\n\nLine two", $clean['mail']['invoice']['body'] );
check_same( 'an unticked e-mail is off', 0, $clean['mail']['ready']['on'] );
check_same( 'an emptied body goes back to the default', $defaults['mail']['ready']['body'], $clean['mail']['ready']['body'] );
check( 'an unknown e-mail kind is dropped', ! isset( $clean['mail']['unknown'] ) );
check_same( 'an emptied "came from" list goes back to the default', $defaults['came_from'], Settings::sanitize( array( 'came_from' => '' ) )['came_from'] );
check_same( 'a "came from" value must be one of the list', array( 'forum', '' ), array( Settings::came_from( 'FORUM', $defaults['came_from'] ), Settings::came_from( 'telepathy', $defaults['came_from'] ) ) );

echo "\nSchema\n";
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$schema          = Store::schema( 'DEFAULT CHARSET=utf8mb4' );
check_same( 'three tables', 3, count( $schema ) );
check_contains( 'the requests table', 'CREATE TABLE wp_fh_requests (', $schema[0] );
check_contains( 'the links table', 'CREATE TABLE wp_fh_request_links (', $schema[1] );
check_contains( 'the log table', 'CREATE TABLE wp_fh_request_log (', $schema[2] );
foreach ( $schema as $fh_index => $fh_sql ) {
	check( "table $fh_index: two spaces after PRIMARY KEY (dbDelta)", false !== strpos( $fh_sql, 'PRIMARY KEY  (id)' ) );
	check( "table $fh_index: lower-case column types, as the database reports them", 0 === preg_match( '/\b(BIGINT|VARCHAR|DATETIME|TINYINT|DECIMAL|TEXT|INT)\b/', preg_replace( '/CREATE TABLE|PRIMARY KEY|UNIQUE KEY|KEY|NOT NULL|NULL|DEFAULT|AUTO_INCREMENT|CHARSET/', '', $fh_sql ) ) );
	check( "table $fh_index: no INDEX keyword", false === stripos( $fh_sql, ' INDEX ' ) );
}
foreach ( array_keys( Store::COLUMNS ) as $fh_column ) {
	check( "the requests table has a \"$fh_column\" column", 1 === preg_match( '/^\s*' . preg_quote( $fh_column, '/' ) . ' /m', $schema[0] ) );
}
foreach ( array( 'state', 'user_id', 'email', 'fish_key', 'line_id', 'fee_ref' ) as $fh_key ) {
	check( "requests are indexed on $fh_key", false !== strpos( $schema[0], "KEY $fh_key ($fh_key)" ) );
}
check( 'the link token is unique', false !== strpos( $schema[1], 'UNIQUE KEY token (token)' ) );

$source = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/requests/class-store.php' );
check( 'the log is append-only: no UPDATE or DELETE names the log table', 0 === preg_match( "/(UPDATE|DELETE FROM)[^;]*request_log|->(update|delete)\(\s*self::table\(\s*'request_log'/i", $source ) );
check( 'no request is ever deleted', 0 === preg_match( "/DELETE FROM|->delete\(/i", $source ) );

$section = FISHOTEL_MISC_PATH . 'includes/sections/requests/';
$touches = array();
foreach ( array_merge( glob( $section . '*.php' ) ?: array(), glob( $section . 'views/*.php' ) ?: array() ) as $fh_file ) {
	// A use of the database object, not a mention of it in a comment.
	if ( 'class-store.php' !== basename( $fh_file ) && preg_match( '/global\s+\$wpdb|\$wpdb->|\$GLOBALS\[\s*.wpdb/', (string) file_get_contents( $fh_file ) ) ) {
		$touches[] = basename( $fh_file );
	}
}
check_same( 'the Store is the only file that touches $wpdb', array(), $touches );

echo "\nInstall gate\n";
$GLOBALS['fh_options'] = array();
check( 'not installed without the version option', ! Store::installed() && ! Store::ready() );
update_option( Store::DB_VERSION_OPTION, 'failed:1.0:' . time() );
check( 'a failed install is not retried on the front end', false === Store::maybe_install( false ) );
check( 'nor in wp-admin within the hour', false === Store::maybe_install( true ) );
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION );
check( 'installed once the option carries the version', Store::installed() && false === Store::maybe_install( true ) );
check( 'the gate opens with no added column missing', Store::schema_ready( array() ) );

fh_finish();
