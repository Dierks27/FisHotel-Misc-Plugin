<?php
/**
 * The supplier order screen's layout (views/order.php), read as text:
 * the "Goes on sale" card sits under the order's own title, inside the
 * order form, while its date and buttons still post to their own form
 * (an HTML form cannot sit in another, so they name it with form="…").
 * The order's Save gathers only its own fields, so the sale date never
 * travels with it.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-view-test.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

$fh_src = (string) file_get_contents( dirname( __DIR__ ) . '/views/order.php' );

echo "1. Goes on sale sits under the order's title\n";

$fh_sale_form  = strpos( $fh_src, '<form id="fh-cr-sale-form"' );
$fh_order_form = strpos( $fh_src, '<form id="fh-cr-order"' );
$fh_title      = strpos( $fh_src, 'class="fh-cr-order__title"' );
$fh_card       = strpos( $fh_src, 'class="fishotel-misc-card fh-cr-card fh-cr-sale"' );
$fh_head       = strpos( $fh_src, 'class="fishotel-misc-card fh-cr-card fh-cr-order__head"' );

check( 'every piece is there', false !== $fh_sale_form && false !== $fh_order_form && false !== $fh_title && false !== $fh_card && false !== $fh_head );
check( 'the card comes after the order\'s title and before its figures', $fh_title < $fh_card && $fh_card < $fh_head );
$fh_between = substr( $fh_src, $fh_sale_form, $fh_order_form - $fh_sale_form );
check( 'the sale form is closed before the order form opens (no form inside a form)', false !== strpos( $fh_between, '</form>' ) && 1 === substr_count( $fh_between, '<form' ) );
check( '… and carries the nonce, the action and the order', false !== strpos( $fh_between, 'wp_nonce_field( $batch[\'nonce_action\'] )' ) && false !== strpos( $fh_between, 'Admin_Workbook::SALE_DATE_ACTION' ) && false !== strpos( $fh_between, 'name="order_id"' ) );
$fh_card_src = substr( $fh_src, $fh_card, $fh_head - $fh_card );
check( 'the date, Save and Clear post to the sale form', 1 === substr_count( $fh_card_src, 'name="sale_date"' ) && 3 === substr_count( $fh_card_src, 'form="fh-cr-sale-form"' ) && 1 === preg_match( '/<input[^>]*form="fh-cr-sale-form"[^>]*name="sale_date"/', $fh_card_src ) );
check( 'the card itself is no form', false === strpos( $fh_card_src, '<form' ) && false === strpos( $fh_card_src, '</form>' ) );
check( 'the sale form and the card are shown for a saved order with a batch only', 2 === substr_count( $fh_src, '<?php if ( ! $is_new && ! empty( $batch ) ) : ?>' ) );

echo "\n" . ( $fh_failures ? "$fh_failures of $fh_checks checks FAILED\n" : "All $fh_checks checks passed\n" );
exit( $fh_failures ? 1 : 0 );
