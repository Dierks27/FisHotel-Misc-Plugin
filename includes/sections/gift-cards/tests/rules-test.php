<?php
/**
 * The pure rules: codes, settings, the modes and the Live guard, the
 * schema, the e-mail, the stats.
 *
 * Run from the plugin root:  php includes/sections/gift-cards/tests/rules-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Gift_Cards\Codes;
use FisHotel\Misc\Sections\Gift_Cards\Mailer;
use FisHotel\Misc\Sections\Gift_Cards\Modes;
use FisHotel\Misc\Sections\Gift_Cards\Settings;
use FisHotel\Misc\Sections\Gift_Cards\Store;

echo "Codes\n";
$seen = array();
$ok   = true;
for ( $i = 0; $i < 5000; $i++ ) {
	$code = Codes::generate();
	$ok   = $ok && (bool) preg_match( '/^([' . Codes::ALPHABET . ']{4}-){3}[' . Codes::ALPHABET . ']{4}$/', $code );
	$seen[ Codes::key( $code ) ] = true;
}
check( 'a code is four groups of four from the alphabet', $ok );
check( '5,000 codes, 5,000 different', 5000 === count( $seen ) );
check( 'the alphabet has no look-alikes (0 O 1 I L)', ! preg_match( '/[0O1IL]/', Codes::ALPHABET ) && 31 === strlen( Codes::ALPHABET ) && 31 === count( array_unique( str_split( Codes::ALPHABET ) ) ) );
check( 'about 79 bits of randomness', 16 * log( strlen( Codes::ALPHABET ), 2 ) > 78 );
check( 'the random source is the secure one unless a test hands one in', 'AAAA-AAAA-AAAA-AAAA' === Codes::generate( static function () { return 0; } ) && false !== strpos( file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-codes.php' ), "'random_int'" ) );
check( 'a code typed any way finds the same key', 'K7QM2XRT9WHCNB4E' === Codes::key( ' k7qm 2xrt-9whc nb4e ' ) );
check( 'too short or not text is no key', '' === Codes::key( 'ABC' ) && '' === Codes::key( array( 'x' ) ) );
check( 'masked to the last four', '••••-••••-••••-NB4E' === Codes::mask( 'K7QM-2XRT-9WHC-NB4E' ) && 'NB4E' === Codes::last4( 'K7QM-2XRT-9WHC-NB4E' ) );

echo "\nSettings\n";
$d = Settings::merge( array() );
check( 'the safer answers by default', 'no' === $d['guest_codes'] && 'yes' === $d['cancel_paid_restores'] && 'yes' === $d['wallet_pays_gift_cards'] && 'yes' === $d['books_include_disabled'] && 'no' === $d['send_imported_pending'] && 'manage_options' === $d['editor_capability'] );
$x = Settings::sanitize( array( 'guest_codes' => 'yes', 'editor_capability' => 'edit_posts', 'custom_min' => '10.00', 'custom_max' => '5.00', 'redeem_tries' => 1000, 'email_body' => 'No code here' ) );
check( 'yes / no', 'yes' === $x['guest_codes'] );
check( 'only the listed capabilities', 'manage_options' === $x['editor_capability'] );
check( 'dollars to cents, the largest never below the smallest', 1000 === $x['custom_min'] && 1000 === $x['custom_max'] );
check( 'tries kept between 3 and 50', 50 === $x['redeem_tries'] );
check( 'an e-mail without {code} gets it back', false !== strpos( $x['email_body'], '{code}' ) );

echo "\nModes and the Live guard\n";
check( 'off / shadow / live as stored when the old plugin is not loaded', 'off' === Modes::effective( 'off', false ) && 'shadow' === Modes::effective( 'shadow', false ) && 'live' === Modes::effective( 'live', false ) );
check( 'Live falls back to Shadow when the old plugin comes back', 'shadow' === Modes::effective( 'live', true ) );
check( 'an unknown stored value is Off', 'off' === Modes::effective( 'banana', false ) );
check( 'stored defaults to Off', 'off' === Modes::stored() );
update_option( 'active_plugins', array( 'woocommerce/woocommerce.php', 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
check( 'the old plugin is found in the active list', Modes::legacy_loaded() );
update_option( 'active_plugins', array( 'some-folder-name/woocommerce-gift-cards.php' ) );
check( '…whatever folder it sits in', Modes::legacy_loaded() );
update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
check( '…and not when it is off', ! Modes::legacy_loaded() );
update_option( Modes::OPTION, 'live' );
check( 'current(): Live, no fallback', array( 'stored' => 'live', 'effective' => 'live', 'legacy' => false, 'fallback' => false ) === Modes::current() );
update_option( 'active_plugins', array( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) );
check( 'current(): Live stored, Shadow in effect, fallback', array( 'stored' => 'live', 'effective' => 'shadow', 'legacy' => true, 'fallback' => true ) === Modes::current() );
delete_option( Modes::OPTION );
delete_option( 'active_plugins' );
$no_old = array( 'exists' => false );
$old    = array(
	'exists'   => true,
	'cards'    => array( 'rows' => 6, 'max_id' => 6 ),
	'activity' => array( 'rows' => 9, 'max_id' => 9 ),
);
$good   = array(
	'kind'       => 'import',
	'ok'         => true,
	'reconciled' => true,
	'legacy_max' => $old,
);
check( 'a site that never had the old plugin may go Live', array() === Modes::live_blockers( false, $no_old, null ) );
check( 'not while the old plugin is switched on', 1 === count( Modes::live_blockers( true, $no_old, null ) ) );
check( 'not before the old cards are imported', 1 === count( Modes::live_blockers( false, $old, null ) ) );
check( 'not after a dry run only', 1 === count( Modes::live_blockers( false, $old, array( 'kind' => 'dry_run', 'ok' => true ) ) ) );
check( 'not after an import that did not reconcile', array() !== Modes::live_blockers( false, $old, array_merge( $good, array( 'reconciled' => false ) ) ) );
check( 'yes after a clean import of exactly what is there', array() === Modes::live_blockers( false, $old, $good ) );
$moved                       = $old;
$moved['activity']['max_id'] = 10;
check( 'not when the old plugin has moved on since', 1 === count( Modes::live_blockers( false, $moved, $good ) ) );

echo "\nThe schema\n";
$sql = implode( "\n", ( new Store() )->schema( '' ) );
check( 'three InnoDB tables', 3 === substr_count( $sql, 'ENGINE=InnoDB' ) );
check( 'the code key is UNIQUE', false !== strpos( $sql, 'UNIQUE KEY code_key (code_key)' ) );
check( 'an order line + unit issues one card (UNIQUE)', false !== strpos( $sql, 'UNIQUE KEY order_unit (order_item_id,order_item_unit)' ) );
check( 'every money movement has a UNIQUE key', false !== strpos( $sql, 'UNIQUE KEY idempotency_key (idempotency_key)' ) && false !== strpos( $sql, 'idempotency_key varchar(120) NOT NULL' ) );
check( 'an old card / activity row is copied once (UNIQUE)', false !== strpos( $sql, 'UNIQUE KEY legacy_id (legacy_id)' ) && false !== strpos( $sql, 'UNIQUE KEY legacy_activity_id (legacy_activity_id)' ) );
check( 'money is whole cents', preg_match_all( '/_cents bigint\(20\)/', $sql ) >= 5 );
check( 'no float or decimal money column anywhere', ! preg_match( '/\b(float|double|decimal)\b/i', $sql ) );
check( 'no expiry column for new cards (only the old one, to show)', false === strpos( $sql, ' expires_at' ) && false !== strpos( $sql, 'legacy_expires_at datetime NULL' ) );
check( 'the version gate is an autoloaded option', false !== strpos( file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/gift-cards/class-store.php' ), "update_option( self::DB_VERSION_OPTION, \$ok ? self::DB_VERSION : 'failed:' . self::DB_VERSION . ':' . time(), true )" ) );
check( 'a front-end request never installs', false === Store::maybe_install( false ) );

echo "\nThe e-mail\n";
$m    = new Mailer( Settings::merge( array() ), array( 'shop' => 'Test Fish Shop', 'shop_url' => 'https://example.test/', 'account_url' => 'https://example.test/my-account/giftcards/' ) );
$card = array( 'code' => 'K7QM-2XRT-9WHC-NB4E', 'amount_cents' => 2500, 'balance_cents' => 2500, 'recipient_name' => 'Pat <b>Tester</b>', 'recipient_email' => 'pat@example.test', 'sender_name' => 'Lee', 'sender_email' => '', 'message' => 'Enjoy & happy reefing' );
$mail = $m->compose( $card );
check( 'to the recipient', 'pat@example.test' === $mail['to'] );
check( 'the subject names the amount and the shop', 'A $25.00 gift card from Test Fish Shop' === $mail['subject'] );
check( 'the full code is in it (the recipient is one of the two who see it)', false !== strpos( $mail['text'], 'K7QM-2XRT-9WHC-NB4E' ) && false !== strpos( $mail['html'], 'K7QM-2XRT-9WHC-NB4E' ) );
check( 'names and the message are escaped in the HTML', false !== strpos( $mail['html'], 'Pat &lt;b&gt;Tester&lt;/b&gt;' ) && false !== strpos( $mail['html'], 'Enjoy &amp; happy reefing' ) );
check( 'it says it never expires and how to use it', false !== strpos( $mail['text'], 'never expires' ) && false !== strpos( $mail['text'], 'https://example.test/my-account/giftcards/' ) );
check( 'no recipient address: to the buyer', 'lee@example.test' === Mailer::address( array_merge( $card, array( 'recipient_email' => '', 'sender_email' => 'lee@example.test' ) ) ) );

echo "\nStats\n";
$s = fh_gc_fresh();
$a = fh_gc_wallet_card( $s, 5, '50.00' );
$b = fh_gc_card( $s, '20.00' );
$c = fh_gc_card( $s, '10.00' );
$s->set_status( $c, 'disabled', 'lost', fh_gc_admin_ctx() );
$s->debit_order( 1, 5, array(), 1500, fh_gc_customer_ctx( 5, 'checkout' ) );
$st = $s->stats();
check( 'still owed: $55 on active cards, $10 on disabled, $65 for the books', 5500 === $st['outstanding_active'] && 1000 === $st['outstanding_disabled'] && 6500 === $st['books_cents'] );
check( 'redeemed this month and this year: $15', 1500 === $st['month_redeemed_cents'] && 1500 === $st['year_redeemed_cents'] );
check( 'issued by hand this month: 3 cards, $80', 3 === $st['month_issued'] && 8000 === $st['month_issued_cents'] );
check( 'never used: 2 cards, $30', 2 === $st['never_used'] && 3000 === $st['never_used_cents'] );
check( 'average value $26.67', 2667 === $st['average_amount'] );
fh_gc_days( 400 );
$st = $s->stats();
check( 'a year later all three are "not used in over a year"', 3 === $st['idle_year'] && 6500 === $st['idle_year_cents'] );
check( 'the books leave out disabled cards when the setting says so', 5500 === fh_gc_service( $s->store, array( 'books_include_disabled' => 'no' ) )->stats()['books_cents'] );
check( 'the health check finds nothing wrong', array() === $st['mismatches'] );
$GLOBALS['wpdb']->query( 'UPDATE ' . $s->store->table( 'gift_cards' ) . ' SET balance_cents = 1 WHERE id = ' . $b );
check( '…and names a card whose balance was changed behind the ledger\'s back', $b === (int) $s->stats()['mismatches'][0]['id'] );

check( 'no unexpected database error', empty( $GLOBALS['fh_db_errors'] ), implode( "\n", (array) ( $GLOBALS['fh_db_errors'] ?? array() ) ) );

fh_finish();
