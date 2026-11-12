<?php
/**
 * The box suggestion — the pure rule, on invented bags and boxes: one bag
 * size, mixed sizes, the smallest box by volume, a fill that lands exactly
 * on 100 %, "on the line" at and around the threshold, a bag that does not
 * go in a box (a blank "how many fit"), a split across boxes (largest
 * first, bulkiest bags first, the smallest box for what is left), bags no
 * box takes, no boxes at all, nothing to pack, and the fish-per-bag seam.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/suggest-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Suggest;

// Bags: 1 Small, 2 Medium, 3 Large, 4 Jumbo.
$order = array( 1, 2, 3, 4 );

/**
 * The invented boxes with how many of each bag fit.
 *
 * @param string[] $only Keys to keep (empty = all three).
 * @return array[]
 */
function fh_boxes( array $only = array() ) {
	$rows = array(
		array( 'name' => 'Cube 8', 'length' => 8, 'width' => 8, 'height' => 8 ),
		array( 'name' => 'Cube 12', 'length' => 12, 'width' => 12, 'height' => 12 ),
		array( 'name' => 'Long 20', 'length' => 20, 'width' => 14, 'height' => 12 ),
	);
	$fits = array(
		'cube 8'  => array( 'name' => 'Cube 8', 'dims' => '8x8x8', 'bags' => array( 1 => 6, 2 => 3 ) ),
		'cube 12' => array( 'name' => 'Cube 12', 'dims' => '12x12x12', 'bags' => array( 1 => 12, 2 => 8, 3 => 4 ) ),
		'long 20' => array( 'name' => 'Long 20', 'dims' => '20x14x12', 'bags' => array( 1 => 30, 2 => 18, 3 => 10, 4 => 2 ) ),
	);
	$boxes = Boxes::with_fits( Boxes::rows( $rows, Boxes::SOURCE_OWN ), $fits );
	if ( ! $only ) {
		return $boxes;
	}
	return array_values(
		array_filter(
			$boxes,
			static function ( $box ) use ( $only ) {
				return in_array( $box['key'], $only, true );
			}
		)
	);
}

/**
 * "name fill%" of every suggested box.
 */
function fh_names( array $plan ) {
	$out = array();
	foreach ( $plan['boxes'] as $box ) {
		$out[] = $box['name'] . ' ' . $box['fill_pct'];
	}
	return $out;
}

echo "1. One bag size\n";
$plan = Suggest::suggest( array( 1 => 5 ), fh_boxes(), 90, $order );
check( 'five Small bags: the smallest box that holds them, 83.3 % full', Suggest::STATUS_FITS === $plan['status'] && array( 'Cube 8 83.3' ) === fh_names( $plan ) && ! $plan['split'], fh_test_export( fh_names( $plan ) ) );
check( 'not on the line under 90 %', false === $plan['on_the_line'] && false === $plan['boxes'][0]['on_the_line'] );
check( 'the box says what goes in it, its size and its volume', array( 1 => 5 ) === $plan['boxes'][0]['bags'] && '8x8x8' === $plan['boxes'][0]['dims'] && 512.0 === $plan['boxes'][0]['volume'] && 8.0 === $plan['boxes'][0]['length'] );
check( 'the bag total is reported', 5 === $plan['bag_total'] && array() === $plan['unplaced'] );

$plan = Suggest::suggest( array( 1 => 6 ), fh_boxes(), 90, $order );
check( 'six Small bags fill the small box exactly: it still holds them — 100 % is "at most 100 %"', array( 'Cube 8 100' ) === fh_names( $plan ) && Suggest::STATUS_FITS === $plan['status'] );
check( 'a full box is on the line', true === $plan['on_the_line'] );

$plan = Suggest::suggest( array( 1 => 7 ), fh_boxes(), 90, $order );
check( 'seven do not fit it: the next box by volume', array( 'Cube 12 58.3' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );

echo "2. Mixed sizes: the fill is the sum of bags needed ÷ how many fit\n";
$plan = Suggest::suggest( array( 1 => 6, 3 => 1 ), fh_boxes(), 90, $order );
check( '6 Small + 1 Large in a box that takes 12 and 4: 50 % + 25 % = 75 %', array( 'Cube 12 75' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
check_same( 'the fill is exact', 0.75, $plan['boxes'][0]['fill'] );

$plan = Suggest::suggest( array( 1 => 4, 2 => 1 ), fh_boxes(), 90, $order );
check( '4/6 + 1/3 is exactly 100 % whatever floating point makes of it: the small box holds it', array( 'Cube 8 100' ) === fh_names( $plan ) && Suggest::STATUS_FITS === $plan['status'], fh_test_export( fh_names( $plan ) ) );

$plan = Suggest::suggest( array( 1 => 6, 3 => 2 ), fh_boxes(), 90, $order );
check( '6/12 + 2/4 = 100 %: holds, and is on the line', array( 'Cube 12 100' ) === fh_names( $plan ) && $plan['on_the_line'] );

$plan = Suggest::suggest( array( 1 => 5, 2 => 1 ), fh_boxes(), 90, $order );
check( 'one bag more than the small box takes (5/6 + 1/3 = 116.7 %): the next box', array( 'Cube 12 54.2' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );

echo "3. The smallest box by volume — not by the order of the list\n";
$shuffled = array_reverse( fh_boxes() );
$plan     = Suggest::suggest( array( 1 => 2 ), $shuffled, 90, $order );
check( 'the list order does not matter', array( 'Cube 8 33.3' ) === fh_names( $plan ) );
$twins = Boxes::with_fits(
	Boxes::rows(
		array(
			array( 'name' => 'Twin B', 'length' => 10, 'width' => 10, 'height' => 10 ),
			array( 'name' => 'Twin A', 'length' => 20, 'width' => 10, 'height' => 5 ),
		),
		Boxes::SOURCE_OWN
	),
	array(
		'twin a' => array( 'name' => 'Twin A', 'dims' => '20x10x5', 'bags' => array( 1 => 10 ) ),
		'twin b' => array( 'name' => 'Twin B', 'dims' => '10x10x10', 'bags' => array( 1 => 20 ) ),
	)
);
$plan  = Suggest::suggest( array( 1 => 5 ), $twins, 90, $order );
check( 'two boxes of the same volume: the emptier one', array( 'Twin B 25' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
$twins[0]['fits'] = array( 1 => 10 );
$twins[1]['fits'] = array( 1 => 10 );
$plan             = Suggest::suggest( array( 1 => 5 ), $twins, 90, $order );
check( 'the same volume and the same fill: by name', array( 'Twin A 50' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );

echo "4. On the line\n";
$plan = Suggest::suggest( array( 3 => 9 ), fh_boxes(), 90, $order );
check( '9 of 10 is exactly 90 %: at the threshold counts as on the line', array( 'Long 20 90' ) === fh_names( $plan ) && true === $plan['on_the_line'] );
$plan = Suggest::suggest( array( 3 => 9 ), fh_boxes(), 91, $order );
check( 'with the threshold at 91 it is not', false === $plan['on_the_line'] && 91.0 === $plan['threshold'] );
$plan = Suggest::suggest( array( 3 => 8 ), fh_boxes(), 90, $order );
check( '8 of 10 (80 %) is not on the line', array( 'Long 20 80' ) === fh_names( $plan ) && false === $plan['on_the_line'] );
$plan = Suggest::suggest( array( 3 => 8 ), fh_boxes(), 80, $order );
check( 'unless the threshold is 80', true === $plan['on_the_line'] );
$plan = Suggest::suggest( array( 1 => 1 ), fh_boxes(), 'soon', $order );
check( 'a threshold that is not a percent from 1 to 100 reads as the default, 90', 90.0 === $plan['threshold'] && 90.0 === Suggest::threshold( 0 ) && 90.0 === Suggest::threshold( 250 ) && 55.0 === Suggest::threshold( '55' ) );
check( 'on_the_line() compares the percent rounded to four places', Suggest::on_the_line( 0.9, 90 ) && Suggest::on_the_line( 0.89999999, 90 ) && ! Suggest::on_the_line( 0.8999, 90 ) );

echo "5. A blank \"how many fit\": that bag does not go in that box\n";
check( 'the fill of a box that lacks a needed bag size is null, not 0', null === Suggest::fill( array( 3 => 1 ), array( 1 => 6, 2 => 3 ) ) && 0.0 === Suggest::fill( array(), array( 1 => 6 ) ) );
$plan = Suggest::suggest( array( 3 => 1 ), fh_boxes(), 90, $order );
check( 'one Large bag skips the small box (blank) although it is smaller', array( 'Cube 12 25' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
$plan = Suggest::suggest( array( 1 => 1, 4 => 1 ), fh_boxes(), 90, $order );
check( 'a Jumbo bag goes only where a number was typed for it', array( 'Long 20 53.3' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );

echo "6. A split across boxes\n";
$plan = Suggest::suggest( array( 1 => 40 ), fh_boxes(), 90, $order );
check( '40 Small bags fit no box (133 % of the largest): the largest is filled, the smallest box that holds the rest takes the rest', Suggest::STATUS_SPLIT === $plan['status'] && true === $plan['split'] && array( 'Long 20 100', 'Cube 12 83.3' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
check( 'each box says what goes in it', array( 1 => 30 ) === $plan['boxes'][0]['bags'] && array( 1 => 10 ) === $plan['boxes'][1]['bags'] && array() === $plan['unplaced'] );
check( 'a split is flagged: its full box is on the line', true === $plan['on_the_line'] && true === $plan['boxes'][0]['on_the_line'] && false === $plan['boxes'][1]['on_the_line'] );

$plan = Suggest::suggest( array( 4 => 3, 3 => 4 ), fh_boxes(), 90, $order );
check( '3 Jumbo + 4 Large (190 %): the bulkiest bags fill the first box, the rest is one more', array( 'Long 20 100', 'Long 20 90' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
check( 'two Jumbo in the first, the third with the four Large in the second', array( 4 => 2 ) === $plan['boxes'][0]['bags'] && array( 3 => 4, 4 => 1 ) === $plan['boxes'][1]['bags'], fh_test_export( array( $plan['boxes'][0]['bags'], $plan['boxes'][1]['bags'] ) ) );

$plan = Suggest::suggest( array( 1 => 20, 3 => 9 ), fh_boxes( array( 'cube 12', 'long 20' ) ), 90, $order );
check( '20 Small + 9 Large (156.7 %): Large first, Small bags top the box up to 100 %', array( 'Long 20 100', 'Long 20 56.7' ) === fh_names( $plan ) && array( 1 => 3, 3 => 9 ) === $plan['boxes'][0]['bags'] && array( 1 => 17 ) === $plan['boxes'][1]['bags'], fh_test_export( $plan['boxes'][0]['bags'] ) );

$plan = Suggest::suggest( array( 1 => 100 ), fh_boxes(), 90, $order );
check( '100 Small bags: three full boxes and the smallest box for the last ten', array( 'Long 20 100', 'Long 20 100', 'Long 20 100', 'Cube 12 83.3' ) === fh_names( $plan ), fh_test_export( fh_names( $plan ) ) );
$total = 0;
foreach ( $plan['boxes'] as $box ) {
	$total += array_sum( $box['bags'] );
}
check( 'every bag is in exactly one box', 100 === $total );

$plan = Suggest::suggest( array( 1 => 5000 ), fh_boxes( array( 'cube 8' ) ), 90, $order );
check( 'a split never runs past ' . Suggest::MAX_BOXES . ' boxes; what is left is reported, not dropped', Suggest::MAX_BOXES === count( $plan['boxes'] ) && array( 1 => 5000 - 6 * Suggest::MAX_BOXES ) === $plan['unplaced'] && Suggest::STATUS_PARTIAL === $plan['status'] );

echo "7. Bags no box takes\n";
$plan = Suggest::suggest( array( 1 => 2, 4 => 1 ), fh_boxes( array( 'cube 8', 'cube 12' ) ), 90, $order );
check( 'a Jumbo bag with no number in any box is set aside and named; the rest gets the smallest box that holds it', Suggest::STATUS_PARTIAL === $plan['status'] && array( 'Cube 8 33.3' ) === fh_names( $plan ) && array( 4 => 1 ) === $plan['unplaced'] && false === $plan['split'], fh_test_export( array( fh_names( $plan ), $plan['unplaced'] ) ) );
$plan = Suggest::suggest( array( 1 => 20, 4 => 1 ), fh_boxes( array( 'cube 8', 'cube 12' ) ), 90, $order );
check( 'the rest may itself need a split: both are said', Suggest::STATUS_PARTIAL === $plan['status'] && true === $plan['split'] && array( 'Cube 12 100', 'Cube 12 66.7' ) === fh_names( $plan ) && array( 4 => 1 ) === $plan['unplaced'], fh_test_export( array( fh_names( $plan ), $plan['unplaced'] ) ) );
$plan = Suggest::suggest( array( 4 => 2 ), fh_boxes( array( 'cube 8', 'cube 12' ) ), 90, $order );
check( 'nothing at all can be boxed: no box, the status says so', Suggest::STATUS_NO_FIT === $plan['status'] && array() === $plan['boxes'] && array( 4 => 2 ) === $plan['unplaced'] && false === $plan['split'] );

echo "8. No boxes at all, nothing to pack\n";
$plan = Suggest::suggest( array( 1 => 3 ), array(), 90, $order );
check( 'no boxes: no suggestion, every bag unplaced', Suggest::STATUS_NO_BOXES === $plan['status'] && array() === $plan['boxes'] && array( 1 => 3 ) === $plan['unplaced'] && 3 === $plan['bag_total'] );
$plan = Suggest::suggest( array(), fh_boxes(), 90, $order );
check( 'no bags needed: nothing to suggest', Suggest::STATUS_EMPTY === $plan['status'] && array() === $plan['boxes'] && 0 === $plan['bag_total'] );
$plan = Suggest::suggest( array(), array(), 90, $order );
check( 'neither bags nor boxes: still "nothing to pack"', Suggest::STATUS_EMPTY === $plan['status'] );
check( 'junk in the needs is dropped: zero, negative, a bag id 0, text', array( 2 => 3 ) === Suggest::needs( array( 1 => 0, 2 => '3', 3 => -4, 0 => 5, 5 => 'many' ) ) );
$plan = Suggest::suggest( array( 1 => 2 ), array( 'not a box', fh_boxes()[0] ), 90, $order );
check( 'a row that is not a box is skipped', array( 'Cube 8 33.3' ) === fh_names( $plan ) );

echo "9. One bag per fish — and the seam for more fish per bag\n";
check( 'one bag per fish', 5 === Suggest::bags_for( 5 ) && 5 === Suggest::bags_for( 5, 1 ) && 1 === Suggest::bags_for( 1 ) );
check( 'two fish per bag: as many bags as it takes', 3 === Suggest::bags_for( 5, 2 ) && 2 === Suggest::bags_for( 4, 2 ) && 1 === Suggest::bags_for( 1, 6 ) );
check( 'nothing needs no bag; a number below one counts as one per bag', 0 === Suggest::bags_for( 0 ) && 0 === Suggest::bags_for( -3 ) && 4 === Suggest::bags_for( 4, 0 ) && 4 === Suggest::bags_for( 4, 'x' ) );

echo "10. Every box against the order — the \"choose another box\" list\n";
$list = Suggest::candidates( array( 1 => 6, 3 => 1 ), array_reverse( fh_boxes() ), 90 );
check( 'smallest first', array( 'Cube 8', 'Cube 12', 'Long 20' ) === array_column( $list, 'name' ) );
check( 'a box a needed bag does not go in has no fill and names the bag', null === $list[0]['fill'] && false === $list[0]['holds'] && array( 3 ) === $list[0]['missing'] );
check( 'a box that holds it: its fill', 0.75 === $list[1]['fill'] && true === $list[1]['holds'] && array() === $list[1]['missing'] && false === $list[1]['on_the_line'] );
check( 'a bigger box: a lower fill', 30.0 === $list[2]['fill_pct'] && true === $list[2]['holds'] );
$list = Suggest::candidates( array( 1 => 9 ), fh_boxes(), 90 );
check( 'a box that is too small says by how much', 150.0 === $list[0]['fill_pct'] && false === $list[0]['holds'] && true === $list[1]['holds'] );

fh_finish();
