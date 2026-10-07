<?php
/**
 * Acceptance tests for the Sticker Giveaway rules.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/rules-test.php
 *
 * These are the decisions that cost money when wrong: who gets a free
 * sticker, how many add-ons a customer can buy, and what each line costs.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Rules;
use FisHotel\Misc\Sections\Sticker_Giveaway\Settings;

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

function line( $pid, array $slugs, $subtotal, $is_sticker = false ) {
	return array(
		'product_id'     => $pid,
		'category_slugs' => $slugs,
		'line_subtotal'  => $subtotal,
		'is_sticker'     => $is_sticker,
	);
}

$settings = array_merge(
	Settings::DEFAULTS,
	array(
		'sticker_product_ids'     => array( 101, 102, 103 ),
		'eligible_category_slugs' => array( 'quarantined-fish' ),
		'upsell_price'            => 1.00,
		'upsell_max_qty'          => 5,
	)
);

$fish = line( 11, array( 'quarantined-fish' ), 89.00 );
$meds = line( 21, array( 'medications', 'antiparasitic' ), 24.00 );
$food = line( 31, array( 'fish-food' ), 12.00 );

echo "1. Eligibility by category\n";
check( 'a fish line qualifies', Rules::is_eligible( array( $fish ), $settings ) );
check( 'fish plus meds still qualifies', Rules::is_eligible( array( $meds, $fish ), $settings ) );
check( 'a descendant slug passed in by the caller qualifies', Rules::is_eligible( array( line( 12, array( 'wrasses', 'quarantined-fish' ), 40.00 ) ), $settings ) );
check( 'slug matching ignores case', Rules::is_eligible( array( line( 12, array( 'Quarantined-Fish' ), 40.00 ) ), $settings ) );
check( 'meds only does not qualify', ! Rules::is_eligible( array( $meds ), $settings ) );
check( 'food only does not qualify', ! Rules::is_eligible( array( $food ), $settings ) );
check( 'an empty cart does not qualify', ! Rules::is_eligible( array(), $settings ) );
check( 'a line with no categories does not qualify', ! Rules::is_eligible( array( line( 41, array(), 500.00 ) ), $settings ) );

$any_order = array_merge( $settings, array( 'eligible_category_slugs' => array() ) );
check( 'with no categories ticked, every order qualifies', Rules::is_eligible( array( $meds ), $any_order ) );
check( 'with no categories ticked, a sticker-only cart still does not', ! Rules::is_eligible( array( line( 101, array( 'merchandise' ), 1.00, true ) ), $any_order ) );

$two_cats = array_merge( $settings, array( 'eligible_category_slugs' => array( 'quarantined-fish', 'merchandise' ) ) );
check( 'any one of several ticked categories qualifies', Rules::is_eligible( array( line( 51, array( 'merchandise' ), 20.00 ) ), $two_cats ) );

echo "2. Minimum subtotal boundary\n";
$min50 = array_merge( $settings, array( 'min_subtotal' => 50.00 ) );
check( 'subtotal exactly at the minimum qualifies', Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 50.00 ) ), $min50 ) );
check( 'one cent under the minimum does not', ! Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 49.99 ) ), $min50 ) );
check( 'float noise under the minimum is rounded to cents', Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 49.999 ) ), $min50 ) );
check( 'lines are summed to reach the minimum', Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 30.00 ), $meds ), $min50 ) );
check( 'a non-fish line counts toward the subtotal once a fish is present', Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 10.00 ), line( 21, array( 'medications' ), 40.00 ) ), $min50 ) );
check( 'min_subtotal 0 means no minimum', Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 0.01 ) ), $settings ) );

echo "3. Sticker lines are ignored for eligibility\n";
$free_line  = line( 101, array( 'merchandise', 'quarantined-fish' ), 0.00, true );
$extra_line = line( 102, array( 'quarantined-fish' ), 5.00, true );
check( 'a sticker line in an eligible category cannot keep itself eligible', ! Rules::is_eligible( array( $free_line ), $settings ) );
check( 'sticker lines plus meds is still not eligible', ! Rules::is_eligible( array( $free_line, $extra_line, $meds ), $settings ) );
check( 'sticker lines plus a fish is eligible', Rules::is_eligible( array( $free_line, $extra_line, $fish ), $settings ) );
check( 'sticker subtotals do not count toward the minimum', ! Rules::is_eligible( array( line( 11, array( 'quarantined-fish' ), 45.00 ), line( 102, array(), 10.00, true ) ), $min50 ) );

echo "4. Selection clamping to pool and max\n";
$sel = Rules::normalise_selection( 102, array( 101 => 2, 103 => 1 ), $settings );
check( 'a pool sticker is accepted as the free pick', 102 === $sel['free'] );
check( 'add-ons in the pool are kept with their quantities', array( 101 => 2, 103 => 1 ) === $sel['extras'], json_encode( $sel['extras'] ) );

$sel = Rules::normalise_selection( 999, array( 999 => 3, 101 => 1 ), $settings );
check( 'a product outside the pool cannot be the free pick', 0 === $sel['free'] );
check( 'add-ons outside the pool are dropped', array( 101 => 1 ) === $sel['extras'], json_encode( $sel['extras'] ) );

$sel = Rules::normalise_selection( 0, array( 101 => 50, 102 => -3, 103 => 0 ), $settings );
check( '"no sticker" is honoured', 0 === $sel['free'] );
check( 'quantity above the max is clamped to the max', array( 101 => 5 ) === $sel['extras'], json_encode( $sel['extras'] ) );

$sel = Rules::normalise_selection( '102', array( '101' => '2', '103' => 'abc' ), $settings );
check( 'string form values are coerced', 102 === $sel['free'] && array( 101 => 2 ) === $sel['extras'], json_encode( $sel ) );

$no_upsell = array_merge( $settings, array( 'upsell_enabled' => false ) );
$sel       = Rules::normalise_selection( 101, array( 102 => 2 ), $no_upsell );
check( 'add-ons are emptied when the upsell is disabled', array() === $sel['extras'] );
check( 'the free pick survives the upsell being disabled', 101 === $sel['free'] );

$empty_pool = array_merge( $settings, array( 'sticker_product_ids' => array() ) );
$sel        = Rules::normalise_selection( 101, array( 101 => 1 ), $empty_pool );
check( 'an empty pool yields no free pick and no add-ons', 0 === $sel['free'] && array() === $sel['extras'] );

check( 'pool() dedupes and drops junk', array( 101, 102 ) === Rules::pool( array( 'sticker_product_ids' => array( '101', 101, 0, -4, 'x', 102 ) ) ) );

echo "5. Pricing\n";
check( 'the free sticker costs nothing', 0.0 === Rules::price_for( Rules::KIND_FREE, $settings ) );
check( 'an add-on costs the upsell price', 1.0 === Rules::price_for( Rules::KIND_EXTRA, $settings ) );
check( 'the upsell price follows the setting', 2.5 === Rules::price_for( Rules::KIND_EXTRA, array_merge( $settings, array( 'upsell_price' => '2.50' ) ) ) );
check( 'a negative upsell price is treated as free', 0.0 === Rules::price_for( Rules::KIND_EXTRA, array_merge( $settings, array( 'upsell_price' => -1 ) ) ) );
check( 'an unknown kind is never charged', 0.0 === Rules::price_for( 'bogus', $settings ) );

echo "6. Offering the upsell\n";
check( 'eligible carts are offered add-ons', Rules::offers_upsell( true, $settings ) );
check( 'ineligible carts are offered add-ons when for-everyone is on', Rules::offers_upsell( false, $settings ) );
check( 'ineligible carts are not offered add-ons when for-everyone is off', ! Rules::offers_upsell( false, array_merge( $settings, array( 'upsell_for_everyone' => false ) ) ) );
check( 'nobody is offered add-ons when the upsell is off', ! Rules::offers_upsell( true, $no_upsell ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
