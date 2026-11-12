<?php
/**
 * Rules — the owner's standing rules as pure decisions: which order pays
 * an invoice (the paid flag and the theme's lock, never a status name;
 * cancelled / failed / trashed orders release it, a refunded one does
 * not), what an invoice really is and whether it may be edited or moved
 * to the Trash (the lock rules and the Trash rule), the pre-check's
 * fish-on-two-invoices detection and its confirmation keys, the content
 * fingerprint a pre-check token is bound to, the validation before
 * Publish, and how a hand-made invoice's title names its handle.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/rules-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Rules;

function order_facts( $id, $status, $paid, array $more = array() ) {
	return array_merge(
		array(
			'id'           => $id,
			'number'       => (string) $id,
			'status'       => $status,
			'status_label' => ucfirst( str_replace( '-', ' ', $status ) ),
			'paid'         => $paid,
			'mirror'       => false,
		),
		$more
	);
}

function product_facts( array $more = array() ) {
	return array_merge(
		array(
			'id'            => 900,
			'exists'        => true,
			'status'        => 'publish',
			'marker'        => 7,
			'lock_order_id' => 0,
		),
		$more
	);
}

function invoice_row( array $more = array() ) {
	return array_merge(
		array(
			'id'         => 7,
			'product_id' => 900,
			'state'      => 'published',
			'order_id'   => null,
			'handle'     => 'Tester',
		),
		$more
	);
}

echo "1. Keys\n";
check_same( 'a handle is compared without case, spacing or entities', array( 'jpreef', 'jpreef', 'jp reef', 't&j' ), array( Rules::handle_key( 'JPReef' ), Rules::handle_key( "  jpREEF \n" ), Rules::handle_key( 'JP   Reef' ), Rules::handle_key( 'T&amp;j' ) ) );
check_same( 'no handle', array( '', '', '' ), array( Rules::handle_key( '' ), Rules::handle_key( null ), Rules::handle_key( array() ) ) );
check_same( 'a fish: case, punctuation and a size in brackets do not matter', 'blue test tang', Rules::fish_key( 'Blue Test-Tang (Medium)' ) );
check_same( 'a plural is the singular', array( 'blue test tang', 'test blenny', 'test wrasse', 'test wrasse', 'test bass' ), array( Rules::fish_key( 'Blue Test Tangs' ), Rules::fish_key( 'Test Blennies' ), Rules::fish_key( 'Test Wrasses' ), Rules::fish_key( 'Test Wrasse' ), Rules::fish_key( 'Test Bass' ) ) );
check_same( 'square brackets and entities too', 'black white test clown', Rules::fish_key( 'Black &amp; White Test Clown [pair]' ) );
check_same( 'nothing left: no key', array( '', '' ), array( Rules::fish_key( ' (Medium) ' ), Rules::fish_key( null ) ) );

echo "2. Which order pays an invoice\n";
check_same( 'no order: none', 0, Rules::paying_order( 0, array() ) );
check_same( 'a paid Processing order pays', 51, Rules::paying_order( 0, array( order_facts( 51, 'processing', true ) ) ) );
check_same( 'an order with a made-up status and the paid flag pays — paid is not read from the status name', 52, Rules::paying_order( 0, array( order_facts( 52, 'shipped', true ) ) ) );
check_same( 'a Processing order that is NOT paid does not', 0, Rules::paying_order( 0, array( order_facts( 53, 'processing', false ) ) ) );
check_same( 'pending and on hold do not', 0, Rules::paying_order( 0, array( order_facts( 54, 'pending', false ), order_facts( 55, 'on-hold', false ) ) ) );
check_same( 'a paid order that was cancelled, failed or trashed no longer does', 0, Rules::paying_order( 0, array( order_facts( 56, 'cancelled', true ), order_facts( 57, 'failed', true ), order_facts( 58, 'trash', true ) ) ) );
check_same( 'a refunded order still holds its invoice', 59, Rules::paying_order( 0, array( order_facts( 59, 'refunded', true ) ) ) );
check_same( 'the theme\'s lock counts while its order stands, paid flag or not', 60, Rules::paying_order( 60, array( order_facts( 60, 'on-hold', false ) ) ) );
check_same( 'the theme\'s lock on a cancelled order does not', 0, Rules::paying_order( 61, array( order_facts( 61, 'cancelled', false ) ) ) );
check_same( 'the theme\'s lock on an order that is gone does not', 0, Rules::paying_order( 62, array() ) );
check_same( 'the lock order wins over a newer paid one', 63, Rules::paying_order( 63, array( order_facts( 70, 'completed', true ), order_facts( 63, 'processing', true ) ) ) );
check_same( 'without a lock, the newest paid one', 70, Rules::paying_order( 0, array( order_facts( 64, 'completed', true ), order_facts( 70, 'completed', true ), order_facts( 71, 'pending', false ) ) ) );
check( 'order_is_dead(): cancelled, failed, trash, and nothing at all', Rules::order_is_dead( order_facts( 1, 'cancelled', true ) ) && Rules::order_is_dead( order_facts( 1, 'failed', false ) ) && Rules::order_is_dead( order_facts( 1, 'trash', false ) ) && Rules::order_is_dead( null ) && ! Rules::order_is_dead( order_facts( 1, 'refunded', true ) ) && ! Rules::order_is_dead( order_facts( 1, 'pending', false ) ) );
check_same( 'order_label()', '#51 (Processing)', Rules::order_label( order_facts( 51, 'processing', true ) ) );

echo "3. A draft and a withdrawn invoice\n";
$e = Rules::effective( invoice_row( array( 'state' => 'draft', 'product_id' => null ) ), null, array() );
check( 'a draft is editable (as a draft), has nothing to trash and is not locked', 'draft' === $e['state'] && $e['can_edit'] && ! $e['can_trash'] && ! $e['locked'] && ! $e['changed'] );
$e = Rules::effective( invoice_row( array( 'state' => 'cancelled', 'product_id' => null ) ), null, array() );
check( 'a withdrawn invoice stays withdrawn and locked', 'cancelled' === $e['state'] && $e['locked'] && ! $e['can_edit'] && ! $e['can_trash'] );

echo "4. The lock rules\n";
$e = Rules::effective( invoice_row(), product_facts(), array() );
check( 'published, of this section, no order: editable on the deliberate path, trashable', 'published' === $e['state'] && ! $e['locked'] && $e['can_edit'] && $e['can_trash'] && $e['created_here'] && ! $e['changed'] );

$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 51, 'processing', true ) ) );
check( 'a paid order contains it: PAID, locked, not editable, not trashable — and the stored state is behind', 'paid' === $e['state'] && 51 === $e['order_id'] && $e['locked'] && ! $e['can_edit'] && ! $e['can_trash'] && $e['changed'] && 51 === $e['order']['id'] );
check_contains( 'the reason names the order', '#51 (Processing)', $e['lock_reason'] );

$e = Rules::effective( invoice_row(), product_facts( array( 'lock_order_id' => 60 ) ), array( order_facts( 60, 'completed', true ) ) );
check( 'the theme\'s `_fh_invoice_order_id` on the product: PAID and locked', 'paid' === $e['state'] && 60 === $e['order_id'] && $e['locked'] && ! $e['can_edit'] );

$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), product_facts(), array( order_facts( 51, 'refunded', true ) ) );
check( 'a refunded order keeps it paid and locked', 'paid' === $e['state'] && $e['locked'] && ! $e['changed'] );

$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), product_facts(), array( order_facts( 51, 'cancelled', true ) ) );
check( 'its order cancelled: released — published again, the stored state is behind', 'published' === $e['state'] && 0 === $e['order_id'] && $e['changed'] && ! $e['locked'] && $e['can_edit'] );
check( '…but still not trashable: an order contains it', ! $e['can_trash'] );

$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), product_facts(), array( order_facts( 51, 'failed', false ) ) );
check( 'its order failed: released', 'published' === $e['state'] && $e['changed'] );

$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), product_facts(), array( order_facts( 51, 'completed', true ), order_facts( 52, 'completed', true ) ) );
check( 'paid by one order while a newer paid order also contains it: paid, and by the newest', 'paid' === $e['state'] && 52 === $e['order_id'] && $e['changed'] );

$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 54, 'on-hold', false ) ) );
check( 'an unpaid order that still stands (on hold) contains it: not paid, but locked — the customer has checked out', 'published' === $e['state'] && $e['locked'] && ! $e['can_edit'] && ! $e['can_trash'] && ! $e['changed'] );
check_contains( 'the reason names that order', '#54 (On hold)', $e['lock_reason'] );

$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 54, 'pending', false ) ) );
check( 'pending payment: locked too', $e['locked'] && ! $e['can_edit'] );

$e = Rules::effective( invoice_row(), product_facts(), null );
check( 'the orders could not be read: nothing may be changed, and the stored state is kept', 'published' === $e['state'] && $e['locked'] && ! $e['can_edit'] && ! $e['can_trash'] && ! $e['orders_known'] && ! $e['changed'] );
$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), product_facts(), null );
check( '…a paid one stays paid', 'paid' === $e['state'] && 51 === $e['order_id'] && $e['locked'] );

$e = Rules::effective( invoice_row(), product_facts( array( 'marker' => 0 ) ), array() );
check( 'a product without this section\'s mark is never changed: locked, not trashable', $e['locked'] && ! $e['can_edit'] && ! $e['can_trash'] && ! $e['created_here'] );
$e = Rules::effective( invoice_row(), product_facts( array( 'marker' => 8 ) ), array() );
check( 'the mark of ANOTHER invoice is not this section\'s mark for it', ! $e['created_here'] && $e['locked'] );
$e = Rules::effective( invoice_row( array( 'product_id' => 901 ) ), product_facts(), array() );
check( 'a mark on a product the invoice does not point at does not count either', ! $e['created_here'] && ! $e['can_edit'] );

$e = Rules::effective( invoice_row(), product_facts( array( 'status' => 'draft' ) ), array() );
check( 'its product unpublished by hand: locked', $e['locked'] && ! $e['can_edit'] );

$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 80, 'processing', true, array( 'mirror' => true ) ) ) );
check( 'a fulfilment copy of other orders pays for nothing and blocks nothing', 'published' === $e['state'] && $e['can_edit'] && $e['can_trash'] );

echo "5. The Trash rule: only when not one order contains the product\n";
foreach ( array( 'pending', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled', 'failed', 'trash' ) as $status ) {
	$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 90, $status, in_array( $status, array( 'processing', 'completed', 'refunded' ), true ) ) ) );
	check( "an order with the status \"$status\" contains it: not offered", ! $e['can_trash'] && '' !== $e['trash_reason'] );
}
$e = Rules::effective( invoice_row(), product_facts(), array( order_facts( 90, 'cancelled', false ), order_facts( 91, 'failed', false ) ) );
check_contains( 'the reason lists the orders', '#90 (Cancelled), #91 (Failed)', $e['trash_reason'] );
$e = Rules::effective( invoice_row(), product_facts( array( 'lock_order_id' => 77 ) ), array() );
check( 'the theme\'s lock names an order that is gone: still not offered', ! $e['can_trash'] );
$e = Rules::effective( invoice_row(), product_facts( array( 'status' => 'trash' ) ), array() );
check( 'already in the Trash: the stored state follows, nothing more to trash', 'trashed' === $e['state'] && $e['changed'] && ! $e['can_trash'] && $e['locked'] );
$e = Rules::effective( invoice_row( array( 'state' => 'trashed' ) ), product_facts( array( 'status' => 'publish' ) ), array() );
check( 'taken back out of the Trash by hand: published again', 'published' === $e['state'] && $e['changed'] && $e['can_edit'] );

echo "6. The product is gone\n";
$e = Rules::effective( invoice_row(), null, array() );
check( 'a published invoice whose product no longer exists is withdrawn', 'cancelled' === $e['state'] && $e['changed'] && $e['locked'] && ! $e['can_trash'] );
$e = Rules::effective( invoice_row( array( 'state' => 'paid', 'order_id' => 51 ) ), null, array() );
check( 'a paid one stays paid (the order remains)', 'paid' === $e['state'] && 51 === $e['order_id'] && ! $e['changed'] && $e['locked'] );
$e = Rules::effective( invoice_row( array( 'state' => 'trashed' ) ), null, array() );
check( 'a trashed one stays trashed (WordPress emptied the Trash)', 'trashed' === $e['state'] && ! $e['changed'] );

echo "7. A fish on two invoices\n";
$new = Lines::normalize(
	array(
		array( 'name' => 'Blue Test Tang (Medium)', 'qty' => 1, 'unit_price' => '45', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777 ),
		array( 'name' => 'Red Test Goby', 'qty' => 2, 'unit_price' => '12', 'source' => 'tank', 'stay_id' => 9 ),
		array( 'name' => 'Green Test Blenny', 'qty' => 1, 'unit_price' => '30' ),
		array( 'kind' => 'credit', 'name' => 'lost Blue Test Tang', 'unit_price' => '10' ),
		array( 'kind' => 'note', 'name' => 'Blue Test Tang ships Tuesday' ),
		array( 'name' => 'Pink Test Anthias', 'qty' => 1, 'unit_price' => '25', 'source' => 'species', 'species_id' => 31 ),
	)
);
$others = array(
	array(
		'ref'   => 'product:400',
		'label' => 'Tester Invoice (10/1/26)',
		'paid'  => true,
		'state' => 'Made by hand — PAID',
		'lines' => array(
			array( 'kind' => 'fish', 'name' => 'blue test tangs', 'qty' => 1 ),
			array( 'kind' => 'credit', 'name' => 'Credit: Green Test Blenny' ),
			array( 'kind' => 'note', 'name' => 'Red Test Goby next time' ),
		),
	),
	array(
		'ref'   => 'invoice:3',
		'label' => 'Tester Invoice (10/20/26)',
		'paid'  => false,
		'state' => 'Published — not paid',
		'lines' => array(
			array( 'kind' => 'fish', 'name' => 'Renamed by hand', 'qty' => 3, 'stay_id' => 9 ),
			array( 'kind' => 'fish', 'name' => 'Some other name', 'qty' => 1, 'product_id' => 501, 'variation_id' => 778 ),
			array( 'kind' => 'fish', 'name' => 'Yet another name', 'qty' => 1, 'species_id' => 31 ),
			array( 'kind' => 'fish', 'name' => 'Orange Test Clown', 'qty' => 1 ),
		),
	),
	array(
		'ref'   => '',
		'label' => 'no reference: ignored',
		'lines' => array( array( 'kind' => 'fish', 'name' => 'Green Test Blenny' ) ),
	),
);
$dups = Rules::duplicates( $new, $others );
check_same( 'the flagged lines, by position', array( 0, 1, 5 ), array_column( $dups, 'index' ) );
check( 'the tang: by name on the hand-made invoice (plural, case, size ignored), by product on the other (another size of the same product)', 2 === count( $dups[0]['matches'] ) && array( 'name', 'product' ) === array_column( $dups[0]['matches'], 'by' ) && array( 'product:400', 'invoice:3' ) === array_column( $dups[0]['matches'], 'ref' ) && true === $dups[0]['matches'][0]['paid'] && false === $dups[0]['matches'][1]['paid'], json_encode( $dups[0] ) );
check( 'the goby: by its tank group, although the other invoice renamed it', 1 === count( $dups[1]['matches'] ) && 'stay' === $dups[1]['matches'][0]['by'] && 'Renamed by hand' === $dups[1]['matches'][0]['name'] && 3 === $dups[1]['matches'][0]['qty'] );
check( 'the anthias: by its species-list entry', 'species' === $dups[2]['matches'][0]['by'] );
check( 'the blenny is not flagged: a credit or a note that names a fish is not that fish, and an invoice without a reference is ignored', ! in_array( 2, array_column( $dups, 'index' ), true ) );
check( 'credits and notes of the NEW invoice are never flagged', ! in_array( 3, array_column( $dups, 'index' ), true ) && ! in_array( 4, array_column( $dups, 'index' ), true ) );
check( 'one match per other invoice, even when it holds the fish twice', 1 === count( Rules::duplicates( Lines::normalize( array( array( 'name' => 'A' ) ) ), array( array( 'ref' => 'x', 'lines' => array( array( 'kind' => 'fish', 'name' => 'A' ), array( 'kind' => 'fish', 'name' => 'a' ) ) ) ) )[0]['matches'] ) );
check_same( 'nothing in common: nothing flagged', array(), Rules::duplicates( Lines::normalize( array( array( 'name' => 'Lone Test Fish' ) ) ), $others ) );
check_same( 'no other invoice: nothing flagged', array(), Rules::duplicates( $new, array() ) );
check( 'a line without a kind counts as a fish (a hand-made line)', 1 === count( Rules::duplicates( array( array( 'name' => 'A' ) ), array( array( 'ref' => 'x', 'lines' => array( array( 'name' => 'A' ) ) ) ) ) ) );

$keys = Rules::dup_keys( $dups );
check( 'one confirmation key per match, 32 hex characters each', 4 === count( $keys ) && 4 === count( array_unique( $keys ) ) && 4 === count( preg_grep( '/^[a-f0-9]{32}$/', $keys ) ) );
check( 'the key is the fish and the invoice it was found on — the same for the same pair, different for another', Rules::dup_key( $new[0], 'product:400' ) === $dups[0]['matches'][0]['key'] && Rules::dup_key( $new[0], 'invoice:3' ) !== $dups[0]['matches'][0]['key'] && Rules::dup_key( array( 'name' => 'BLUE test tangs' ), 'product:400' ) === $dups[0]['matches'][0]['key'] );
check_same( 'nothing confirmed: every match is open', $keys, Rules::unconfirmed( $dups, array() ) );
check_same( 'all but one confirmed: that one is open', array( $keys[3] ), Rules::unconfirmed( $dups, array( $keys[0], $keys[1], $keys[2], 'something-else' ) ) );
check_same( 'all confirmed: Publish may go on', array(), Rules::unconfirmed( $dups, $keys ) );
check_same( 'junk instead of the confirmed list confirms nothing', $keys, Rules::unconfirmed( $dups, 'yes' ) );
check_same( 'nothing flagged: nothing to confirm', array(), Rules::unconfirmed( array(), null ) );

echo "8. The fingerprint a pre-check token is bound to\n";
$invoice = array( 'handle' => 'Tester', 'forum_user_id' => null, 'customer_id' => 5, 'customer_name' => 'Test Person', 'customer_email' => 'person@example.test', 'shipping' => 'premium', 'shipping_slug' => '', 'flat_total' => null );
$hash    = Rules::content_hash( $invoice, $new );
check( 'the same content, the same fingerprint — whatever the notes, the version or the case of the handle', $hash === Rules::content_hash( array_merge( $invoice, array( 'notes' => 'x', 'version' => 9, 'handle' => 'TESTER ' ) ), $new ) );
foreach ( array( 'handle' => 'Other', 'forum_user_id' => 3, 'customer_id' => 6, 'customer_name' => 'Other Person', 'customer_email' => 'other@example.test', 'shipping' => 'free', 'shipping_slug' => 'quote', 'flat_total' => 100.0 ) as $key => $value ) {
	check( "another $key: another fingerprint", $hash !== Rules::content_hash( array_merge( $invoice, array( $key => $value ) ), $new ) );
}
foreach ( array( 'name' => 'Blue Test Tang (Large)', 'qty' => 2, 'unit_price' => 46.0, 'product_id' => 502, 'variation_id' => 778, 'take_stock' => true, 'kind' => 'note', 'species_id' => 4, 'stay_id' => 4, 'size_slug' => 'large', 'source' => 'typed' ) as $key => $value ) {
	$changed        = $new;
	$changed[0][ $key ] = $value;
	check( "a line's $key changed: another fingerprint", $hash !== Rules::content_hash( $invoice, $changed ) );
}
check( 'a line more, a line less, or two lines swapped: another fingerprint', $hash !== Rules::content_hash( $invoice, array_slice( $new, 0, 5 ) ) && $hash !== Rules::content_hash( $invoice, array_merge( $new, array( $new[0] ) ) ) && $hash !== Rules::content_hash( $invoice, array_merge( array( $new[1], $new[0] ), array_slice( $new, 2 ) ) ) );
check( 'a flat total of 0 is not "no flat total"', Rules::content_hash( array_merge( $invoice, array( 'flat_total' => 0.0 ) ), $new ) !== $hash );

echo "9. Validation before Publish\n";
$ok = Lines::with_flat_total( Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ), array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '10' ), array( 'kind' => 'note', 'name' => 'Ships Tuesday' ) ) ), '70' )['lines'];
$v  = Rules::validate( array( 'handle' => 'Tester' ), $ok );
check( 'a sound invoice: no error, no warning', array() === $v['errors'] && array() === $v['warnings'] && array() === $v['lines'], json_encode( $v ) );
$v = Rules::validate( array( 'handle' => ' ' ), $ok );
check( 'no handle: an error', 1 === count( $v['errors'] ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'kind' => 'note', 'name' => 'Only a note' ) ) ) );
check( 'no fish: an error', 1 === count( $v['errors'] ) && false !== strpos( $v['errors'][0], 'at least one fish' ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '10' ), array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '82' ) ) ) );
check( 'the credits are larger than the fish: an error, with the amount', 1 === count( $v['errors'] ) && false !== strpos( $v['errors'][0], '-$72' ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '-5' ) ) ) );
check( 'a fish with a negative price: an error on that line', isset( $v['lines'][0] ) && false !== strpos( $v['lines'][0], 'credit line' ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '5' ), array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '' ) ) ) );
check( 'a credit without an amount: an error on that line', isset( $v['lines'][1] ) && ! isset( $v['lines'][0] ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'name' => 'Free Test Chromis', 'qty' => 2, 'unit_price' => '' ), array( 'name' => 'Zero Test Snail', 'unit_price' => '0' ), array( 'name' => 'Blue Test Tang', 'unit_price' => '45' ) ) ) );
check( 'a fish without a price (blank or 0) is only a warning — the order then shows ONE line', array() === $v['errors'] && 1 === count( $v['warnings'] ) && false !== strpos( $v['warnings'][0], 'Free Test Chromis, Zero Test Snail' ) && false !== strpos( $v['warnings'][0], 'ONE line' ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::with_flat_total( Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '45' ) ) ), '0' )['lines'] );
check( 'a total of $0: a warning', array() === $v['errors'] && 1 === count( $v['warnings'] ) && false !== strpos( $v['warnings'][0], '$0' ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::with_flat_total( Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '45' ) ) ), '50' )['lines'] );
check( 'an Adjustment line: a warning that says how it will appear', array() === $v['errors'] && 1 === count( $v['warnings'] ) && false !== strpos( $v['warnings'][0], '$5' ) && false !== strpos( $v['warnings'][0], 'pick list' ) );
$huge = array();
for ( $i = 0; $i < 11; $i++ ) {
	$huge[] = array( 'name' => 'Huge Test Fish ' . $i, 'qty' => 999, 'unit_price' => '999999.99' );
}
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( $huge ) );
check( 'lines that add up to more than the columns hold ($9,999,999,999.99): an error — the stored total would be cut off', 1 === count( $v['errors'] ) && false !== strpos( $v['errors'][0], '$9,999,999,999.99' ), json_encode( $v['errors'] ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::with_flat_total( Lines::normalize( $huge ), '500' )['lines'] );
check( 'the same lines under a small flat total: the Discount line would not fit either — an error', 1 === count( preg_grep( '/more than an invoice can hold/', $v['errors'] ) ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array_slice( $huge, 0, 10 ) ) );
check( 'just below the limit (ten such lines, $9,989,999,900.10): fine', array() === $v['errors'] );

echo "10. A line must read back from the description as typed\n";
$problem = static function ( array $line ) {
	$lines = Lines::normalize( array( $line ) );
	return $lines ? Rules::roundtrip_problem( $lines[0] ) : 'dropped';
};
check_same( 'an ordinary fish, a sized fish, a fish without a price', array( '', '', '' ), array( $problem( array( 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ) ), $problem( array( 'name' => 'Six Line Test Wrasse (X-Large)', 'unit_price' => '1,250.50' ) ), $problem( array( 'name' => 'Test Paddlefish Max', 'qty' => 3 ) ) ) );
check( 'a fish named "Credit…" would be read as a credit', false !== strpos( $problem( array( 'name' => 'Credit Card Test Goby', 'unit_price' => '5' ) ), 'credit' ) );
check_same( 'a fish whose name carries a price of its own still reads back', '', $problem( array( 'name' => 'Test Tang $5 special', 'unit_price' => '20' ) ) );
check_same( 'even a negative amount inside a fish name reads back: the "xN" at the end makes it a fish', '', $problem( array( 'name' => 'Test Tang -$5 off', 'qty' => 1 ) ) );
check_same( 'an ordinary note', '', $problem( array( 'kind' => 'note', 'name' => 'Ships Tuesday, 2 boxes' ) ) );
check( 'a note that ends in "x2" reads like a fish', false !== strpos( $problem( array( 'kind' => 'note', 'name' => 'Box x2' ) ), 'fish line' ) );
check( 'a note that starts with "Credit" or carries a negative amount reads like a credit', false !== strpos( $problem( array( 'kind' => 'note', 'name' => 'Credit next time' ) ), 'credit' ) && false !== strpos( $problem( array( 'kind' => 'note', 'name' => 'We took -$5 off' ) ), 'credit' ) );
check_same( 'an ordinary credit, and one without a reason', array( '', '' ), array( $problem( array( 'kind' => 'credit', 'name' => 'lost Test Cleaner Wrasse', 'unit_price' => '82' ) ), $problem( array( 'kind' => 'credit', 'name' => '', 'unit_price' => '5.50' ) ) ) );
check( 'a credit whose reason carries an amount would be read with that amount', '' !== $problem( array( 'kind' => 'credit', 'name' => 'lost $40 Test Goby', 'unit_price' => '82' ) ) );
check_same( 'the Discount and the Adjustment line read back', array( '', '' ), array( Rules::roundtrip_problem( Lines::with_flat_total( Lines::normalize( array( array( 'name' => 'A', 'unit_price' => '9' ) ) ), '5' )['lines'][1] ), Rules::roundtrip_problem( Lines::with_flat_total( Lines::normalize( array( array( 'name' => 'A', 'unit_price' => '9' ) ) ), '15' )['lines'][1] ) ) );
$v = Rules::validate( array( 'handle' => 'Tester' ), Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'unit_price' => '45' ), array( 'kind' => 'note', 'name' => 'Box x2' ) ) ) );
check( 'validate() refuses such a line, and says which', 1 === count( $v['errors'] ) && isset( $v['lines'][1] ) && false !== strpos( $v['errors'][0], '"Box x2"' ) );

echo "11. Hand-made invoices: whose is it?\n";
check( 'the title of a hand-made invoice names its handle', Rules::title_is_for_handle( 'Tester Invoice (7/3/26)', 'Tester' ) && Rules::title_is_for_handle( 'tester   invoice (7/3/26)', 'TESTER' ) && Rules::title_is_for_handle( 'Tester Invoice', 'Tester' ) && Rules::title_is_for_handle( 'Tester Invoice 2', 'tester' ) );
check( 'a longer handle that starts the same is someone else', ! Rules::title_is_for_handle( 'Tester Two Invoice (7/3/26)', 'Tester' ) && ! Rules::title_is_for_handle( 'Tester2 Invoice (7/3/26)', 'Tester' ) && ! Rules::title_is_for_handle( 'Big Tester Invoice (7/3/26)', 'Tester' ) );
check( 'a handle of two words', Rules::title_is_for_handle( 'Tester Two Invoice (7/3/26)', 'Tester Two' ) );
check( '"Invoice" must be a whole word', ! Rules::title_is_for_handle( 'Tester Invoices (7/3/26)', 'Tester' ) && ! Rules::title_is_for_handle( 'Tester Invoicer', 'Tester' ) );
check( 'a handle with an ampersand, stored either way', Rules::title_is_for_handle( 'T&j Invoice (10/27/26)', 'T&j' ) && Rules::title_is_for_handle( 'T&amp;j Invoice (10/27/26)', 'T&j' ) );
check( 'a handle with characters a pattern would trip over', Rules::title_is_for_handle( 'a.b(c)+ Invoice (1/1/26)', 'a.b(c)+' ) && ! Rules::title_is_for_handle( 'aXb(c)+ Invoice (1/1/26)', 'a.b(c)+' ) );
check( 'no title or no handle: no', ! Rules::title_is_for_handle( '', 'Tester' ) && ! Rules::title_is_for_handle( 'Tester Invoice', '' ) && ! Rules::title_is_for_handle( null, null ) );
check_same( 'handle_from_title()', array( 'Tester', 'Tester Two', 'T&j', '', '' ), array( Rules::handle_from_title( 'Tester Invoice (7/3/26)' ), Rules::handle_from_title( '  Tester Two   invoice (7/3/26)' ), Rules::handle_from_title( 'T&amp;j Invoice (10/27/26)' ), Rules::handle_from_title( 'Blue Test Tang' ), Rules::handle_from_title( null ) ) );
check( 'another title format: the handle after a word', Rules::title_is_for_handle( 'Bill for Tester (7/3/26)', 'Tester', 'Bill for {handle} ({date})' ) && ! Rules::title_is_for_handle( 'Bill for Tester Two (7/3/26)', 'Tester', 'Bill for {handle} ({date})' ) && ! Rules::title_is_for_handle( 'Tester Invoice (7/3/26)', 'Tester', 'Bill for {handle} ({date})' ) );
check_same( '…and read back', 'Tester Two', Rules::handle_from_title( 'Bill for Tester Two (7/3/26)', 'Bill for {handle} ({date})' ) );
check( 'a format with the handle last, and one with the date first', Rules::title_is_for_handle( 'Invoice Tester', 'Tester', 'Invoice {handle}' ) && ! Rules::title_is_for_handle( 'Invoice Tester Two', 'Tester', 'Invoice {handle}' ) && Rules::title_is_for_handle( '7/3/26 Tester Invoice', 'Tester', '{date} {handle} Invoice' ) && ! Rules::title_is_for_handle( '7/3/26 BigTester Invoice', 'Tester', '{date} {handle} Invoice' ) );
check_same( 'title_start(): what an order line of that invoice is named like', array( 'tester invoice', 't&j invoice', 'bill for tester', '', '' ), array( Rules::title_start( 'Tester' ), Rules::title_start( 'T&j' ), Rules::title_start( 'Tester', 'Bill for {handle} ({date})' ), Rules::title_start( '' ), Rules::title_start( 'Tester', '{date} {handle} Invoice' ) ) );
check_same( 'title_word(): the word to search hand-made titles by', array( 'invoice', 'bill' ), array( Rules::title_word(), Rules::title_word( 'Bill for {handle} ({date})' ) ) );

echo "12. States\n";
check_same( 'the five states', array( 'draft', 'published', 'paid', 'cancelled', 'trashed' ), Rules::STATES );
check( 'each has a label', 5 === count( array_unique( array_map( array( Rules::class, 'state_label' ), Rules::STATES ) ) ) );

fh_finish();
