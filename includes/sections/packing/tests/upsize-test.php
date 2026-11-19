<?php
/**
 * The fit model and upsizing — on invented bags, boxes and orders:
 *
 *   - the fit model by the numbers: D = 2W / π, the filled share (the
 *     setting and a bag's own), the foam on every side (this section's
 *     setting, and Foam Cuts' thickness while that section is on), the
 *     D × D floor squares, the single-size grid check, the rows of mixed
 *     sizes, a typed number winning ("your number"), a bag taller than the
 *     box still used with where to tie it off, a bag too wide for the
 *     floor never used;
 *   - the round robin: the owner's example (one 2" fish alone in an
 *     8 × 8 × 8 box ends in the largest bag that fits), two fish of
 *     different sizes both moved up, the larger first in each round, a
 *     tight box moving nobody, a bag with no number for the box passed
 *     over, never past the last bag of the list, a split upsized box by
 *     box;
 *   - the bags chosen by hand: who may choose, kept on the order, respected
 *     by the upsizing, cleared, a warning (never a refusal) when they
 *     overfill the box, and confirming another box working the bags out
 *     again for it;
 *   - fishotel_packing_bag_for_item() with and without a plan;
 *   - nothing written by working any of it out.
 *
 * Every bag, box, fish and order here is invented.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/upsize-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Bags;
use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Fit;
use FisHotel\Misc\Sections\Packing\Order_Box;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Plan;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Suggest;
use FisHotel\Misc\Sections\Packing\Upsize;

/**
 * Whether two numbers agree to a ten-thousandth.
 *
 * @param mixed $a A number.
 * @param mixed $b Another.
 * @return bool
 */
function fh_near( $a, $b ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < 0.0001;
}

/**
 * The invented bags: the list runs from the smallest to the largest.
 * Pouch has no size (typed numbers only); Extra large is filled only half
 * way. Filled and tied they stand (D = 2W / π across, L × share tall):
 * Small 2.5465 × 7 in, Medium 3.8197 × 9.8 in, Large 6.6845 × 12.6 in,
 * Extra large 7.6394 × 11 in.
 *
 * @return array[]
 */
function fh_up_bags() {
	return array(
		array( 'id' => 1, 'name' => 'Small', 'width' => '4', 'length' => '10', 'share' => '', 'sort' => 10 ),
		array( 'id' => 5, 'name' => 'Pouch', 'width' => '', 'length' => '', 'share' => '', 'sort' => 15 ),
		array( 'id' => 2, 'name' => 'Medium', 'width' => '6', 'length' => '14', 'share' => '', 'sort' => 20 ),
		array( 'id' => 3, 'name' => 'Large', 'width' => '10.5', 'length' => '18', 'share' => '', 'sort' => 30 ),
		array( 'id' => 4, 'name' => 'Extra large', 'width' => '12', 'length' => '22', 'share' => '50', 'sort' => 40 ),
	);
}

/**
 * Store invented settings: the bags above, two boxes of the own list
 * (Cube 8 and Cube 12 — 7 × 7 × 7 and 11 × 11 × 11 in inside 0.5 in of
 * foam), nothing typed unless given.
 *
 * @param array $more Settings over these.
 * @return array
 */
function fh_up_settings( array $more = array() ) {
	return Settings::store(
		array_merge(
			array(
				'bags'        => fh_up_bags(),
				'last_bag_id' => 5,
				'boxes'       => array(
					array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ),
					array( 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '12' ),
				),
				'fits'        => array(),
				'size_map'    => array(),
				'threshold'   => 90,
				'fill_share'  => 70,
				'foam'        => 0.5,
			),
			$more
		)
	);
}

/**
 * The fitted boxes of the stored settings.
 *
 * @return array[] Cube 8, Cube 12.
 */
function fh_up_boxes() {
	$settings = Settings::get();
	$current  = Boxes::current( $settings, null, false );
	return Boxes::fitted( $current['boxes'], $settings );
}

/**
 * The invented catalogue: a fish parent with one variation per bag
 * (its own Bag size), in a fish category.
 */
function fh_up_shop() {
	fh_reset_shop();
	fh_product( new Fh_Fake_Product( 300, 'variable' ) );
	fh_product( new Fh_Fake_Product( 301, 'variation', array(), array( '_fh_bag_size' => '1' ), 300 ) ); // Small.
	fh_product( new Fh_Fake_Product( 302, 'variation', array(), array( '_fh_bag_size' => '2' ), 300 ) ); // Medium.
	fh_product( new Fh_Fake_Product( 303, 'variation', array(), array( '_fh_bag_size' => '3' ), 300 ) ); // Large.
	$GLOBALS['fh_terms'] = array( 300 => array( 'quarantined-fish' ) );
	Order_Packing::forget();
}

/**
 * An order of fish lines, each line knowing its order.
 *
 * @param int     $id    Order id.
 * @param array[] $lines Each: item id, name, qty, variation id.
 * @param array   $meta  Order meta.
 * @return Fh_Fake_Order
 */
function fh_up_order( $id, array $lines, array $meta = array() ) {
	$items = array();
	foreach ( $lines as $line ) {
		$item           = new Fh_Fake_Item( $line[0], $line[1], $line[2], 300, array(), $line[3] );
		$item->order_id = $id;
		$items[]        = $item;
	}
	return fh_order( new Fh_Fake_Order( $id, $items, $meta ) );
}

/**
 * "qty × name → bags to use" of every line of a suggestion.
 *
 * @param array $result fishotel_packing_suggest().
 * @return string[]
 */
function fh_up_lines( array $result ) {
	$out = array();
	foreach ( $result['lines'] as $line ) {
		$out[] = $line['qty'] . ' × ' . $line['name'] . ' → ' . $line['use_name'] . ( $line['upsized'] ? ' (upsized)' : '' ) . ( $line['chosen'] ? ' (by hand)' : '' );
	}
	return $out;
}

fh_use_sizes( fh_test_sizes() );
Packing::set_enabled( true );
fh_login( 5, array( 'manage_woocommerce' ) );
$nonce = wp_create_nonce( Order_Packing::NONCE_ACTION );

echo "1. The fit model, by the numbers\n";
check( 'a filled bag stands as a round column 2W / π across: 4 in wide is 2.5465 in, π in wide is 2 in, no width is nothing', fh_near( Fit::diameter( 4 ), 2.5465 ) && fh_near( Fit::diameter( M_PI ), 2.0 ) && 0.0 === Fit::diameter( '' ) && 0.0 === Fit::diameter( 'wide' ) );
$bags = fh_up_bags();
check( 'it stands as tall as its length × the filled share: 10 in at 70 % is 7 in; a bag with its own 50 % stands 22 × 50 % = 11 in', fh_near( Fit::standing( $bags[0], 70 ), 7.0 ) && fh_near( Fit::standing( $bags[4], 70 ), 11.0 ) && fh_near( Fit::standing( $bags[0], 50 ), 5.0 ) );
check( 'a share out of range is the default 70 %; the foam, 0 to 6 in, is 0.5 in otherwise', 70 === Fit::share( 0 ) && 70 === Fit::share( 101 ) && 55 === Fit::share( '55' ) && fh_near( Fit::foam( 'x' ), 0.5 ) && fh_near( Fit::foam( 7 ), 0.5 ) && fh_near( Fit::foam( 0 ), 0.0 ) );
$room = Fit::usable( array( 'length' => 8, 'width' => 10, 'height' => 6 ), 0.5 );
check( 'the foam comes off every side: a 8 × 10 × 6 in box with 0.5 in of foam leaves a 9 × 7 in floor (the longer side first), 5 in tall', fh_near( $room['length'], 9 ) && fh_near( $room['width'], 7 ) && fh_near( $room['height'], 5 ) && fh_near( $room['area'], 63 ) );
$calc = Fit::bag_in_box( array( 'width' => M_PI, 'length' => '10' ), array( 'length' => 11, 'width' => 11, 'height' => 11 ), 0.5, 70 );
check( 'each bag takes a D × D square of floor: 2 in across on a 10 × 10 in floor is 25 by area and 5 × 5 in rows — 25, each 4 % of the box', 25 === $calc['count'] && 25 === $calc['by_area'] && 25 === $calc['by_rows'] && fh_near( $calc['share'], 0.04 ), fh_test_export( $calc ) );
$calc = Fit::bag_in_box( array( 'width' => 1.5 * M_PI, 'length' => '10' ), array( 'length' => 11, 'width' => 11, 'height' => 11 ), 0.5, 70 );
check( 'one size alone: the smaller of the two answers wins — 3 in across is 11 by area but only 3 × 3 = 9 in rows: 9', 9 === $calc['count'] && 11 === $calc['by_area'] && 9 === $calc['by_rows'], fh_test_export( $calc ) );
$calc = Fit::bag_in_box( $bags[4], array( 'length' => 8, 'width' => 8, 'height' => 8 ), 0.5, 70 );
check( 'a bag wider than the floor\'s shorter side does not go in: Extra large (7.64 in across) on a 7 × 7 in floor', 0 === $calc['count'] && 0.0 === $calc['share'] );
$calc = Fit::bag_in_box( $bags[3], array( 'length' => 8, 'width' => 8, 'height' => 8 ), 0.5, 70 );
check( 'height never refuses a bag: Large stands 12.6 in, Cube 8 has 7 in inside the foam — it goes, tied off at about 7 in', 1 === $calc['count'] && fh_near( $calc['tie_off'], 7.0 ) && fh_near( $calc['standing'], 12.6 ) );
check( 'a bag that is short enough has no tie-off note; a bag without both sizes is not worked out at all', null === Fit::bag_in_box( $bags[0], array( 'length' => 8, 'width' => 8, 'height' => 8 ), 0.5, 70 )['tie_off'] && null === Fit::bag_in_box( $bags[1], array( 'length' => 8, 'width' => 8, 'height' => 8 ), 0.5, 70 ) && null === Fit::bag_in_box( array( 'width' => '4', 'length' => '' ), array( 'length' => 8, 'width' => 8, 'height' => 8 ), 0.5, 70 ) );
check( 'rows of round bags: the widest first, each row as deep as its first bag — two 6.68 in bags on an 11 in floor run 13.4 in deep, a 6.68 and a 3.82 share a row', fh_near( Fit::rows( array( 6.6845, 6.6845 ), 11 ), 13.369 ) && fh_near( Fit::rows( array( 3.8197, 6.6845 ), 11 ), 6.6845 ) && 0.0 === Fit::rows( array(), 11 ) );

echo "2. A box with every bag: the typed numbers and the formula\n";
fh_up_settings();
list( $cube8, $cube12 ) = fh_up_boxes();
check( 'nothing typed: every bag with a size gets the formula\'s number; Pouch (no size) goes in no box; Extra large is too wide for Cube 8', array( 1 => 4, 2 => 1, 3 => 1 ) === $cube8['fits'] && array( 1 => 16, 2 => 4, 3 => 1, 4 => 1 ) === $cube12['fits'] && array( 1 => 4, 2 => 1, 3 => 1, 4 => 0 ) === $cube8['fits_computed'] && ! isset( $cube8['fits_computed'][5] ), fh_test_export( array( $cube8['fits'], $cube12['fits'] ) ) );
check( 'each bag\'s share of the box is D² ÷ the floor: Small in Cube 8 is 6.485 ÷ 49', fh_near( $cube8['shares'][1], 2.5465 * 2.5465 / 49 ) && 'computed' === $cube8['fits_by'][1] && array() === $cube8['fits_typed'] );
check( 'where to tie off, per box: in Cube 8 Medium (9.8 in), Large and Extra large stand taller than 7 in; in Cube 12 only Large (12.6 in) stands over 11 in — Extra large, filled half way, stands 11 in', array( 2 => 7.0, 3 => 7.0, 4 => 7.0 ) === $cube8['tie_off'] && array( 3 => 11.0 ) === $cube12['tie_off'], fh_test_export( array( $cube8['tie_off'], $cube12['tie_off'] ) ) );
fh_up_settings( array( 'fits' => array( 'cube 8' => array( 'name' => 'Cube 8', 'dims' => '8x8x8', 'bags' => array( 2 => 2, 5 => 3 ) ) ) ) );
list( $cube8 ) = fh_up_boxes();
check( 'a typed number wins over the formula for that box and bag ("your number"): Medium 2 in Cube 8, its share ½; Pouch goes in by the typed 3', 2 === $cube8['fits'][2] && 'typed' === $cube8['fits_by'][2] && fh_near( $cube8['shares'][2], 0.5 ) && 3 === $cube8['fits'][5] && array( 2 => 2, 5 => 3 ) === $cube8['fits_typed'] && 1 === $cube8['fits_computed'][2] && ! isset( $cube8['diameters'][2] ) );
check( 'the saves of the Boxes tab still read only what was typed', array( 2 => 2, 5 => 3 ) === Boxes::with_fits( Boxes::current( Settings::get(), null, false )['boxes'], Settings::get()['fits'] )[0]['fits'] );
fh_up_settings( array( 'foam' => 1 ) );
list( $cube8 ) = fh_up_boxes();
check( 'more foam, less floor: 1 in of foam leaves Cube 8 a 6 × 6 in floor — 2 × 2 Small, and Large (6.68 in) no more', 4 === $cube8['fits'][1] && ! isset( $cube8['fits'][3] ) && 0 === $cube8['fits_computed'][3] && 'packing' === Boxes::model( Settings::get() )['foam_from'] );

// Foam Cuts switched on: its thickness is taken off instead.
require_once FISHOTEL_MISC_PATH . 'includes/sections/foam-cuts/class-foam-cuts.php';
\FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts::load();
$GLOBALS['fh_options']['fishotel_misc_enabled_sections'] = array( 'foam-cuts' );
$GLOBALS['fh_options']['fishotel_foam_cuts_settings']    = array( 'thickness' => 2500 ); // A quarter inch.
$model = Boxes::model( Settings::get() );
check( 'while Foam Cuts is switched on its foam thickness is used (¼ in), not this section\'s setting', fh_near( $model['foam'], 0.25 ) && 'foam-cuts' === $model['foam_from'] );
$GLOBALS['fh_options']['fishotel_misc_enabled_sections'] = array();
$model = Boxes::model( Settings::get() );
check( 'switched off, this section\'s own setting again', fh_near( $model['foam'], 1.0 ) && 'packing' === $model['foam_from'] );
unset( $GLOBALS['fh_options']['fishotel_foam_cuts_settings'] );

echo "2b. The two settings and a bag's own share\n";
$stored = Settings::get();
check( 'nothing typed: the filled share is 70 %, the foam 0.5 in', 70 === Settings::defaults()['fill_share'] && fh_near( Settings::defaults()['foam'], 0.5 ) && 70 === Settings::normalize( array() )['fill_share'] );
$out = Settings::apply_fits( $stored, array(), array( 'fill_share' => '55', 'foam' => '0' ) );
check( 'saved with the "how many fit" form: a share of 55 % and no foam at all', array() === $out['errors'] && 55 === $out['settings']['fill_share'] && fh_near( $out['settings']['foam'], 0 ) );
$out = Settings::apply_fits( $stored, array(), array( 'fill_share' => '5', 'foam' => '7' ) );
check( 'a share under 10 % and foam over 6 in are refused, and nothing else changes', 2 === count( $out['errors'] ) && $stored['fill_share'] === $out['settings']['fill_share'] );
$out = Settings::apply_bags( $stored, array( array( 'id' => '4', 'name' => 'Extra large', 'width' => '12', 'length' => '22', 'share' => '45' ), array( 'id' => '1', 'name' => 'Small', 'width' => '4', 'length' => '10', 'share' => '' ) ) );
check( 'a bag keeps its own filled share; blank follows the setting', array() === $out['errors'] && 45 === Bags::find( $out['settings']['bags'], 4 )['share'] && '' === Bags::find( $out['settings']['bags'], 1 )['share'] );
$out = Settings::apply_bags( $stored, array( array( 'id' => '4', 'name' => 'Extra large', 'width' => '12', 'length' => '22', 'share' => 'half' ) ) );
check( 'a share that is not a whole percent from 10 to 100 is refused', 1 === count( $out['errors'] ) && false !== strpos( $out['errors'][0], 'filled share' ) );

echo "3. The fill of a box\n";
fh_up_settings();
list( $cube8, $cube12 ) = fh_up_boxes();
check( 'mixed sizes add up their squares: a Medium and a Small in Cube 12 is (14.59 + 6.48) ÷ 121 = 17.4 % — but never less than the fullest size alone (Medium: 1 of 4 = 25 %)', fh_near( Suggest::fill_of( array( 2 => 1, 1 => 1 ), $cube12 ), 0.25 ) && fh_near( Suggest::fill_of( array( 1 => 1 ), $cube12 ), 1 / 16 ) );
check( 'one size alone goes by its count: 4 Small fill Cube 8, a fifth is over', fh_near( Suggest::fill_of( array( 1 => 4 ), $cube8 ), 1.0 ) && Suggest::pct( Suggest::fill_of( array( 1 => 5 ), $cube8 ) ) > 100 );
check( 'two bags that each fit alone but not side by side are over: Large and Extra large in Cube 12 (13.4 + … in of rows on an 11 in floor)', Suggest::pct( Suggest::fill_of( array( 3 => 1, 4 => 1 ), $cube12 ) ) > 100 && Suggest::pct( Suggest::fill_of( array( 3 => 1, 2 => 1 ), $cube12 ) ) <= 100 );
check( 'with typed numbers only the fill is the plain sum, as before', fh_near( Suggest::fill( array( 1 => 6, 2 => 2 ), array( 1 => 12, 2 => 6 ) ), 0.5 + 1 / 3 ) );

echo "4. The round robin\n";
$ladder = array( 1, 5, 2, 3, 4 );
$done   = Upsize::box( array( 'goby' => array( 'min' => 1, 'fixed' => 0, 'pos' => 0 ) ), $cube8, $ladder );
check( 'the owner\'s example: one 2" fish alone in an 8 × 8 × 8 box ends in the largest bag that fits — Small, then Medium, then Large (Pouch has no number for the box; Extra large is too wide)', 3 === $done['bags']['goby'] && 1 === $done['moved'] && fh_near( $done['fill'], 1.0 ) && fh_near( $done['fill_min'], 0.25 ), fh_test_export( $done ) );
check( 'a bag size with no number for the box is passed over: the next bag up from Small in Cube 8 is Medium, not Pouch', 2 === Upsize::next_bag( 1, $ladder, $cube8['fits'] ) && 0 === Upsize::next_bag( 3, $ladder, $cube8['fits'] ) && 4 === Upsize::next_bag( 3, $ladder, $cube12['fits'] ) && 0 === Upsize::next_bag( 4, $ladder, $cube12['fits'] ) );
$done = Upsize::box(
	array(
		'small one' => array( 'min' => 1, 'fixed' => 0, 'pos' => 0 ),
		'mid one'   => array( 'min' => 2, 'fixed' => 0, 'pos' => 1 ),
	),
	$cube12,
	$ladder
);
check( 'two fish of different sizes in Cube 12 both get upgrades: the Medium fish to Large, the Small fish to Medium', array( 'small one' => 2, 'mid one' => 3 ) === $done['bags'] && 2 === $done['moved'] && Suggest::pct( $done['fill'] ) <= 100, fh_test_export( $done ) );
$typed = array( 'fits' => array( 1 => 6, 2 => 3, 3 => 2 ) );
$done  = Upsize::box(
	array(
		'b' => array( 'min' => 1, 'fixed' => 0, 'pos' => 0 ),
		'c' => array( 'min' => 1, 'fixed' => 0, 'pos' => 1 ),
		'a' => array( 'min' => 2, 'fixed' => 0, 'pos' => 2 ),
	),
	$typed,
	array( 1, 2, 3 )
);
check( 'the larger fish goes first in each round, wherever its line is: with room for 6 Small, 3 Medium or 2 Large, the Medium fish gets the Large bag (taken the other way round, all three would end in Medium)', array( 'b' => 2, 'c' => 1, 'a' => 3 ) === $done['bags'], fh_test_export( $done['bags'] ) );
check( 'and a tie keeps the order of the lines: of the two Small fish, the first line\'s moves up, the second\'s finds no room', 2 === $done['bags']['b'] && 1 === $done['bags']['c'] && 2 === $done['moved'] );
$six = array();
for ( $n = 0; $n < 6; $n++ ) {
	$six[ $n ] = array( 'min' => 1, 'fixed' => 0, 'pos' => $n );
}
$done = Upsize::box( $six, $typed, array( 1, 2, 3 ) );
check( 'a tight box moves nobody: six Small in a box that takes six', array( 1, 1, 1, 1, 1, 1 ) === array_values( $done['bags'] ) && 0 === $done['moved'] && 1 === $done['rounds'] && false === $done['over'] && fh_near( $done['fill'], 1.0 ) );
$four = array();
for ( $n = 0; $n < 4; $n++ ) {
	$four[ $n ] = array( 'min' => 1, 'fixed' => 0, 'pos' => $n );
}
$done = Upsize::box( $four, $cube8, $ladder );
check( 'by the rows, four Small (2.55 in) on Cube 8\'s 7 in floor leave room for one Medium (3.82 + 2.55 in in a row): the first fish moves up, the others do not', array( 2, 1, 1, 1 ) === array_values( $done['bags'] ) && 1 === $done['moved'] && false === $done['over'], fh_test_export( $done ) );
$done = Upsize::box( array( array( 'min' => 4, 'fixed' => 0, 'pos' => 0 ) ), $cube12, $ladder );
check( 'a fish never goes past the last bag of the list', 4 === $done['bags'][0] && 0 === $done['moved'] );
$done = Upsize::box(
	array(
		'mid'  => array( 'min' => 2, 'fixed' => 0, 'pos' => 1 ),
		'tiny' => array( 'min' => 1, 'fixed' => 3, 'pos' => 0 ),
	),
	$cube12,
	$ladder
);
check( 'a bag chosen by hand takes its space first; the others round-robin into what is left (the Small fish fixed in Large: the Medium fish has no room to move up)', array( 'mid' => 2, 'tiny' => 3 ) === $done['bags'] && 0 === $done['moved'] && false === $done['over'], fh_test_export( $done ) );
$done = Upsize::box(
	array(
		'mid'  => array( 'min' => 2, 'fixed' => 0, 'pos' => 1 ),
		'tiny' => array( 'min' => 1, 'fixed' => 4, 'pos' => 0 ),
	),
	$cube12,
	$ladder
);
check( 'bags chosen by hand that overfill the box: kept, nobody moves, and the box is over — a warning, not a refusal', array( 'mid' => 2, 'tiny' => 4 ) === $done['bags'] && true === $done['over'] && Suggest::pct( $done['fill'] ) > 100 );
$done = Upsize::box( array( array( 'min' => 1, 'fixed' => 5, 'pos' => 0 ) ), $cube8, $ladder );
check( 'a bag chosen by hand that does not go in the box at all (Pouch, no number for Cube 8): over, with no fill', true === $done['over'] && null === $done['fill'] );

echo "5. On an order: the box from the smallest bags, then the bags to use\n";
fh_up_shop();
$order  = fh_up_order( 900, array( array( 1, 'Invented Goby 2"', 1, 301 ) ) );
$result = fishotel_packing_suggest( 900 );
check( 'one small fish: the box is chosen from its smallest bag (Cube 8, 25 %) — not on the line', 'Cube 8' === $result['boxes'][0]['name'] && 25.0 === $result['boxes'][0]['fill_pct'] && false === $result['on_the_line'], fh_test_export( $result['boxes'] ) );
check( 'and inside the box it goes in Large — upsized, its smallest bag still there', array( '1 × Invented Goby 2" → Large (upsized)' ) === fh_up_lines( $result ) && 1 === $result['lines'][0]['bag']['id'] && '900-1-0' === $result['lines'][0]['key'] && fh_near( $result['lines'][0]['tie_off'], 7.0 ), fh_test_export( fh_up_lines( $result ) ) );
check( 'the plan says where the bags go: Cube 8, 100 % after upsizing, 25 % with the smallest bags', Plan::FROM_SUGGESTION === $result['packed']['from'] && array( 3 => 1 ) === $result['packed']['boxes'][0]['bags'] && 100.0 === $result['packed']['boxes'][0]['fill_pct'] && 25.0 === $result['packed']['boxes'][0]['fill_min_pct'] && 1 === $result['packed']['moved'] && array( array( 3, 1 ) ) === array_map( static function ( $bag ) { return array( $bag['id'], $bag['count'] ); }, $result['bags_used'] ) );
check( 'working it out wrote nothing', array() === $order->writes() );

$order  = fh_up_order( 901, array( array( 1, 'Invented Goby', 1, 301 ), array( 2, 'Invented Tang', 1, 302 ) ) );
$result = fishotel_packing_suggest( 901 );
check( 'a Small and a Medium fish fill Cube 8 already (one Medium stands alone in 7 × 7 in): on the line, and nobody moves', 'Cube 8' === $result['boxes'][0]['name'] && true === $result['on_the_line'] && array( '1 × Invented Goby → Small', '1 × Invented Tang → Medium' ) === fh_up_lines( $result ) && 0 === $result['packed']['moved'], fh_test_export( fh_up_lines( $result ) ) );

echo "6. A split upsizes box by box\n";
fh_up_settings( array( 'boxes' => array( array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ) ) ) );
Order_Packing::forget();
$order  = fh_up_order( 902, array( array( 1, 'Invented Chromis', 6, 301 ) ) );
$result = fishotel_packing_suggest( 902 );
check( 'six Small fish and only Cube 8: two boxes, four and two', Suggest::STATUS_SPLIT === $result['status'] && array( array( 1 => 4 ), array( 1 => 2 ) ) === array_column( $result['boxes'], 'bags' ), fh_test_export( $result['boxes'] ) );
check( 'each box is upsized on its own: in each, one fish moves up to Medium and the others have no room left', array( 1 => 3, 2 => 1 ) === $result['packed']['boxes'][0]['bags'] && array( 1 => 1, 2 => 1 ) === $result['packed']['boxes'][1]['bags'] && 1 === $result['packed']['boxes'][0]['moved'] && 1 === $result['packed']['boxes'][1]['moved'] && 100.0 === $result['packed']['boxes'][1]['fill_pct'] && 50.0 === $result['packed']['boxes'][1]['fill_min_pct'], fh_test_export( $result['packed']['boxes'] ) );
check( 'the line says it as one: 2 × Medium + 4 × Small, upsized', array( '6 × Invented Chromis → 2 × Medium + 4 × Small (upsized)' ) === fh_up_lines( $result ), fh_test_export( fh_up_lines( $result ) ) );

echo "7. Bags chosen by hand\n";
fh_up_settings();
fh_up_shop();
$order = fh_up_order( 910, array( array( 1, 'Invented Goby', 1, 301 ), array( 2, 'Invented Tang', 1, 302 ) ) );
$post  = array(
	'_wpnonce' => $nonce,
	'order_id' => '910',
	'op'       => 'bag',
	'line'     => '910-1-0',
	'bag'      => '3',
);
fh_login( 0 );
$out = Order_Packing::confirm_request( $post );
check( 'nobody logged in: 403, nothing written', 403 === $out['status'] && array() === $order->writes() );
fh_login( 8, array( 'edit_shop_orders' ) );
$out = Order_Packing::confirm_request( $post );
check( 'a user who cannot manage WooCommerce: 403, nothing written', 403 === $out['status'] && array() === $order->writes() );
fh_login( 5 );
$out = Order_Packing::confirm_request( array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ) ) + $post );
check( 'the settings page\'s nonce: 403; no nonce: 403', 403 === $out['status'] && 403 === Order_Packing::confirm_request( array_diff_key( $post, array( '_wpnonce' => 1 ) ) )['status'] && array() === $order->writes() );
$out = Order_Packing::confirm_request( array( 'line' => '910-7-0' ) + $post );
check( 'a line that is not on the order: 400, nothing written', 400 === $out['status'] && array() === $order->writes() );
$out = Order_Packing::confirm_request( array( 'bag' => '99' ) + $post );
check( 'a bag that is not in the list: 400, nothing written', 400 === $out['status'] && array() === $order->writes() && 400 === Order_Packing::confirm_request( array( 'bag' => array( '3' ) ) + $post )['status'] );

// The confirmed box is Cube 12: there, on Auto, the Tang goes in Large and the Goby in Medium.
Order_Packing::confirm_request(
	array(
		'_wpnonce' => $nonce,
		'order_id' => '910',
		'op'       => 'confirm',
		'choice'   => 'cube 12',
	)
);
$result = fishotel_packing_suggest( 910 );
check( 'confirming another box works the bags out again for it: in Cube 12 the Tang goes in Large, the Goby in Medium', Plan::FROM_CONFIRMED === $result['packed']['from'] && 'Cube 12' === $result['packed']['boxes'][0]['name'] && array( '1 × Invented Goby → Medium (upsized)', '1 × Invented Tang → Large (upsized)' ) === fh_up_lines( $result ), fh_test_export( fh_up_lines( $result ) ) );

$order->calls = array();
$out          = Order_Packing::confirm_request( $post );
check( 'choosing Large for the Goby: 200, stored on the order through the order object — one update_meta_data() and one save()', 200 === $out['status'] && array( 'update_meta_data:_fh_packing_bags', 'save' ) === $order->writes() && array( '910-1-0' => 3 ) === $order->meta['_fh_packing_bags'] && false !== strpos( $out['message'], 'chosen by hand' ), fh_test_export( $order->writes() ) );
$result = fishotel_packing_suggest( 910 );
check( 'it survives a reload and is respected: the Goby keeps Large (chosen by hand), it takes its space first, and the Tang has no room left to move up', array( '1 × Invented Goby → Large (by hand)', '1 × Invented Tang → Medium' ) === fh_up_lines( $result ) && 3 === $result['lines'][0]['chosen'] && array( '910-1-0' => 3 ) === $result['overrides'] && 1 === $result['packed']['chosen'], fh_test_export( fh_up_lines( $result ) ) );

$out    = Order_Packing::confirm_request( array( 'bag' => '4' ) + $post );
$result = fishotel_packing_suggest( 910 );
check( 'chosen bags that overfill the box are stored all the same — and the box says so', 200 === $out['status'] && true === $result['packed']['over'] && true === $result['packed']['boxes'][0]['over'] && 1 === count( preg_grep( '/^Check it: the bags chosen come to .*% of Cube 12 — more than it holds\.$/', $result['messages'] ) ), fh_test_export( $result['messages'] ) );
ob_start();
( new Order_Box() )->render_meta_box( $order );
$html = (string) ob_get_clean();
check( 'the Packing box shows the line as chosen by hand, the dropdown on that bag, and the box as too full', false !== strpos( $html, 'chosen by hand' ) && false !== strpos( $html, 'data-line="910-1-0"' ) && 1 === preg_match( '/<option value="4" selected=["\']selected["\']>Extra large/', $html ) && false !== strpos( $html, 'Too full — check it' ), substr( $html, 0, 400 ) );

$order->calls = array();
$out          = Order_Packing::confirm_request( array( 'bag' => '' ) + $post );
check( 'back to Auto: the choice is removed (the last one: the meta goes) through the order object', 200 === $out['status'] && array( 'delete_meta_data:_fh_packing_bags', 'save' ) === $order->writes() && ! isset( $order->meta['_fh_packing_bags'] ) && false !== strpos( $out['message'], 'back on Auto' ) );
$result = fishotel_packing_suggest( 910 );
check( 'and the bags are worked out again', array( '1 × Invented Goby → Medium (upsized)', '1 × Invented Tang → Large (upsized)' ) === fh_up_lines( $result ) && array() === $result['overrides'] );
$order->calls = array();
$out          = Order_Packing::confirm_request( array( 'bag' => 'auto' ) + $post );
check( 'Auto when it is Auto already writes nothing', 200 === $out['status'] && array() === $order->writes() );

Order_Packing::confirm_request(
	array(
		'_wpnonce' => $nonce,
		'order_id' => '910',
		'op'       => 'clear',
	)
);
$result = fishotel_packing_suggest( 910 );
check( 'with the box cleared the bags are worked out in the suggested box again (Cube 8: nobody moves)', Plan::FROM_SUGGESTION === $result['packed']['from'] && array( '1 × Invented Goby → Small', '1 × Invented Tang → Medium' ) === fh_up_lines( $result ) );

$wrapped = fh_up_order( 911, array( array( 1, 'Invented Goby', 1, 301 ) ), array( '_fishotel_fulfilled_by_order' => 910 ) );
$out     = Order_Packing::confirm_request( array( 'order_id' => '911', 'line' => '911-1-0' ) + $post );
check( 'an order that went into a combined box: its bags are chosen there — 409, nothing written', 409 === $out['status'] && array() === $wrapped->writes() );

echo "8. fishotel_packing_bag_for_item()\n";
fh_up_shop();
fh_up_order( 920, array( array( 1, 'Invented Goby 2"', 1, 301 ) ) );
fh_up_order( 922, array( array( 2, 'Invented Chromis', 3, 301 ) ) );
$items = wc_get_order( 920 )->get_items();
$bag   = fishotel_packing_bag_for_item( $items[1] );
check( 'with a plan: the bag to USE (Large, upsized), the smallest one under min', is_array( $bag ) && 3 === $bag['id'] && 'Large' === $bag['name'] && 'Large (10.5 × 18 in)' === $bag['label'] && 'upsized' === $bag['source'] && true === $bag['upsized'] && false === $bag['chosen_by_hand'] && 1 === $bag['min']['id'] && 'Small' === $bag['min']['name'] && false === $bag['mixed'], fh_test_export( $bag ) );
$bag = fishotel_packing_bag_for_item( wc_get_order( 922 )->get_items()[2] );
check( 'a line whose fish ended in different bags: the name says all of them, largest first ("Medium + 2 × Small"), the largest is its id', is_array( $bag ) && true === $bag['mixed'] && 'Medium + 2 × Small' === $bag['name'] && 2 === $bag['id'] && array( array( 2, 1 ), array( 1, 2 ) ) === array_map( static function ( $row ) { return array( $row['id'], $row['count'] ); }, $bag['bags'] ), fh_test_export( $bag ) );
$loose = new Fh_Fake_Item( 9, 'Invented Goby', 1, 300, array(), 301 );
$bag   = fishotel_packing_bag_for_item( $loose );
check( 'without a plan (a line on no order): the smallest bag, as before — no min key', is_array( $bag ) && 1 === $bag['id'] && 'own' === $bag['source'] && ! isset( $bag['min'] ) );
fh_up_order( 921, array( array( 1, 'Invented Goby', 1, 301 ) ), array( '_fh_packing_bags' => array( '921-1-0' => 2 ) ) );
$bag = fishotel_packing_bag_for_item( wc_get_order( 921 )->get_items()[1] );
check( 'a bag chosen by hand is the bag to use', is_array( $bag ) && 2 === $bag['id'] && 'hand' === $bag['source'] && true === $bag['chosen_by_hand'] && 1 === $bag['min']['id'] );
Packing::set_enabled( false );
check( 'while the section is off: null', null === fishotel_packing_bag_for_item( $items[1] ) );
Packing::set_enabled( true );

echo "9. Nothing written, nothing for the storefront\n";
fh_up_shop();
$order   = fh_up_order( 930, array( array( 1, 'Invented Goby', 2, 301 ) ) );
$options = count( $GLOBALS['fh_option_writes'] );
fishotel_packing_suggest( 930 );
fishotel_packing_bag_for_item( $order->get_items()[1] );
check( 'working out the bags and asking for a line\'s bag write nothing: not to the order, the line or the options', array() === $order->writes() && array() === $order->get_items()[1]->writes && count( $GLOBALS['fh_option_writes'] ) === $options );
$pure = array();
foreach ( array( 'class-fit.php', 'class-upsize.php', 'class-plan.php' ) as $file ) {
	$text = (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/sections/packing/' . $file );
	if ( preg_match( '/add_action|add_filter|update_option|get_option|update_meta_data|\$wpdb|wp_enqueue/', $text ) ) {
		$pure[] = $file;
	}
}
check( 'the fit model, the round robin and the plan are pure: no hook, no option, no meta, no query', array() === $pure, implode( ', ', $pure ) );

fh_finish();
