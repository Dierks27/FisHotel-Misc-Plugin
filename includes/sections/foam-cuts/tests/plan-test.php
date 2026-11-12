<?php
/**
 * Plan — "how many of each box" into the cut sheet: the sources named (a
 * leftover's label, "new sheet 1"), the cuts in plain words in the order
 * they are made, every piece with its box and name, the new leftovers
 * with the labels to write on them, the waste, the pieces per box, the
 * warnings — and the shape of the data a CNC export would read. Invented
 * boxes only.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/plan-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Cut_List;
use FisHotel\Misc\Sections\Foam_Cuts\Plan;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

/**
 * A box with its suggested cut list.
 */
function fh_box( $key, $name, $l, $w, $h, $count, $thickness = 0.5 ) {
	return array(
		'key'      => $key,
		'name'     => $name,
		'length'   => fh_in( $l ),
		'width'    => fh_in( $w ),
		'height'   => fh_in( $h ),
		'count'    => $count,
		'cut_list' => 'suggested',
		'pieces'   => Cut_List::suggest( fh_in( $l ), fh_in( $w ), fh_in( $h ), fh_in( $thickness ) )['pieces'],
	);
}

$settings = array(
	'sheet_length' => fh_in( 96 ),
	'sheet_width'  => fh_in( 48 ),
	'thickness'    => fh_in( 0.5 ),
	'kerf'         => 0,
	'rotate'       => true,
	'min_length'   => fh_in( 4 ),
	'min_width'    => fh_in( 4 ),
);

echo "1. One box from a whole sheet\n";
$plan = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 1 ) ), array( 'sheets' => 3, 'leftovers' => array() ), $settings, 1 );
check_same( 'no errors, no warnings', array( array(), array() ), array( $plan['errors'], $plan['warnings'] ) );
check_same( 'the schema is named', 'fishotel-foam-cuts/plan@1', $plan['schema'] );
check_same( 'sizes are inches', 'in', $plan['units'] );
check_same( 'the sheet as bought', array( 'length' => 96, 'width' => 48, 'thickness' => 0.5 ), $plan['sheet'] );
check_same( 'the settings it was made with', array( 'kerf' => 0, 'rotate' => true, 'min_leftover' => array( 'length' => 4, 'width' => 4 ) ), $plan['settings'] );
check_same( 'one source: new sheet 1', array( 'new sheet 1' ), array_column( $plan['sources'], 'label' ) );
$source = $plan['sources'][0];
check( 'a sheet has no leftover id; its size is the sheet', 'sheet' === $source['kind'] && null === $source['leftover_id'] && 96 === $source['length'] && 48 === $source['width'] );
check_same( 'six pieces', 6, count( $source['pieces'] ) );
$labels = array();
foreach ( $source['pieces'] as $piece ) {
	$labels[] = $piece['box'] . ' / ' . $piece['name'] . ' / ' . $piece['length'] . ' x ' . $piece['width'];
}
sort( $labels );
check_same(
	'each piece carries its box, its name and its size',
	array( 'Cube / Long side / 12 x 11', 'Cube / Long side / 12 x 11', 'Cube / Short side / 11 x 11', 'Cube / Short side / 11 x 11', 'Cube / Top / bottom / 12 x 12', 'Cube / Top / bottom / 12 x 12' ),
	$labels
);
check( 'pieces are numbered 1 … 6 and know where they lie', range( 1, 6 ) === array_column( $source['pieces'], 'n' ) && isset( $source['pieces'][0]['x'], $source['pieces'][0]['y'], $source['pieces'][0]['w'], $source['pieces'][0]['h'], $source['pieces'][0]['rotated'], $source['pieces'][0]['box_key'] ) );
check_same( 'the first cut in plain words', 'Cut 1: straight across the whole sheet, top to bottom, 12 in from the left edge.', $source['cuts'][0]['text'] );
check( 'a later cut names the part it is made on and what it frees', 1 === preg_match( '/^Cut 2: straight across the 12 × 48 in part left of cut 1, left to right, 1[12] in from its top edge\. That frees Cube · /', $source['cuts'][1]['text'] ), $source['cuts'][1]['text'] );
check( 'cuts are numbered in order', range( 1, count( $source['cuts'] ) ) === array_column( $source['cuts'], 'n' ) );
$cut = $source['cuts'][0];
check( 'a cut carries its line on the source for a machine: x1, y1 → x2, y2, the kerf and the part it crosses', 12 === $cut['x1'] && 0 === $cut['y1'] && 12 === $cut['x2'] && 48 === $cut['y2'] && 0 === $cut['kerf'] && array( 'x' => 0, 'y' => 0, 'w' => 96, 'h' => 48 ) === $cut['part'] && 'v' === $cut['axis'] && 12 === $cut['at'] && 0 === $cut['of'] );
check( 'the new leftovers get the labels L1, L2 … to write on them', array( 'L1', 'L2' ) === array_slice( array_column( $source['leftovers'], 'label' ), 0, 2 ) && count( $source['leftovers'] ) === $plan['labels']['count'] && 1 === $plan['labels']['from'] );
$mentioned = 0;
foreach ( $source['cuts'] as $cut ) {
	$mentioned += preg_match_all( '/The rest is leftover (L\d+) \(/', $cut['text'] );
}
check( 'a cut that leaves a leftover says which label it gets', $mentioned >= 1 );
check( 'the areas add up: sheet = pieces + leftovers + waste', abs( $source['area'] - $source['pieces_area'] - $source['leftover_area'] - $source['waste_area'] ) < 0.01 && 4608.0 === $source['area'] && 794.0 === $source['pieces_area'] );
check( 'the waste is a percentage of the source', $source['waste_pct'] === round( 100 * $source['waste_area'] / $source['area'], 1 ) );
check_same(
	'the totals',
	array( 'boxes' => 1, 'pieces' => 6, 'placed' => 6, 'sheets' => 1, 'sheets_in_stock' => 3, 'leftovers_used' => 0 ),
	array_intersect_key( $plan['totals'], array_flip( array( 'boxes', 'pieces', 'placed', 'sheets', 'sheets_in_stock', 'leftovers_used' ) ) )
);
check_same( 'the title says what it is for', '1 × Cube', Plan::title( $plan ) );
check( 'the whole plan is JSON', is_string( json_encode( $plan ) ) && json_decode( json_encode( $plan ), true ) == $plan ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- 12.0 and 12 are the same inch.

echo "2. The pieces per box\n";
$boxes = array( fh_box( 'cube', 'Cube', 12, 12, 12, 3 ), fh_box( 'flat', 'Flat', 16, 12, 10, 2 ), fh_box( 'none', 'Not today', 20, 10, 10, 0 ) );
$stock = array(
	'sheets'    => 1,
	'leftovers' => array(
		array( 'id' => 7, 'label' => 'L3', 'length' => fh_in( 30 ), 'width' => fh_in( 20 ) ),
		array( 'id' => 9, 'label' => 'L5', 'length' => fh_in( 13 ), 'width' => fh_in( 13 ) ),
		array( 'id' => 11, 'label' => 'L6', 'length' => fh_in( 3 ), 'width' => fh_in( 60 ) ),
	),
);
$plan  = Plan::build( $boxes, $stock, $settings, 12 );
check_same( 'no errors', array(), $plan['errors'] );
check_same( 'a box with a count of 0 is not in the plan', array( 'Cube', 'Flat' ), array_column( $plan['boxes'], 'name' ) );
check_same( 'two boxes in the summary, with how many of each', array( 3, 2 ), array_column( $plan['summary'], 'count' ) );
$row = $plan['summary'][0]['pieces'][0];
check( 'per piece: its size, how many per box, in total and placed', 'Top / bottom' === $row['name'] && 12 === $row['length'] && 12 === $row['width'] && 2 === $row['per_box'] && 6 === $row['total'] && 6 === $row['placed'] );
check( '…and which sources they come from', 6 === array_sum( $row['from'] ) && array() === array_diff( array_keys( $row['from'] ), array_column( $plan['sources'], 'label' ) ) );
$placed = 0;
foreach ( $plan['summary'] as $box ) {
	foreach ( $box['pieces'] as $row ) {
		$placed += $row['placed'];
	}
}
check_same( 'the summary accounts for every piece: 5 boxes × 6', 30, $placed );
check_same( '…as the totals do', array( 5, 30, 30 ), array( $plan['totals']['boxes'], $plan['totals']['pieces'], $plan['totals']['placed'] ) );
check_same( 'Plan::title()', '3 × Cube, 2 × Flat', Plan::title( $plan ) );

echo "3. Leftovers in stock\n";
$kinds = array();
foreach ( $plan['sources'] as $source ) {
	$kinds[] = $source['kind'] . ' ' . $source['label'];
}
check( 'the leftovers that are cut into come first, under their own labels, then the sheets', 'leftover L3' === $kinds[0] && 'leftover L5' === $kinds[1] && 'sheet new sheet 1' === $kinds[2], implode( ', ', $kinds ) );
check( 'a leftover source carries its id and its size', 7 === $plan['sources'][0]['leftover_id'] && 30 === $plan['sources'][0]['length'] && 20 === $plan['sources'][0]['width'] );
check_same( 'the ids of the leftovers the plan uses', array( 7, 9 ), Plan::used_leftover_ids( $plan ) );
check_same( '…as the plan saw them, in units', array( 'label' => 'L3', 'length' => fh_in( 30 ), 'width' => fh_in( 20 ) ), Plan::used_leftovers( $plan )[7] );
check( 'the leftover nothing fits (3 × 60) is not part of the plan', ! in_array( 11, Plan::used_leftover_ids( $plan ), true ) );
$new = Plan::new_leftovers( $plan );
check( 'new labels continue from the first free number (L12 …), each once', $new && 'L12' === $new[0]['label'] && count( $new ) === count( array_unique( array_column( $new, 'label' ) ) ) && count( $new ) === $plan['labels']['count'] && 12 === $plan['labels']['from'] );
check( 'new leftovers are in units and name their source', is_int( $new[0]['length'] ) && is_int( $new[0]['width'] ) && in_array( $new[0]['source'], array_column( $plan['sources'], 'label' ), true ) );
check_same( 'leftovers used / made in the totals', array( 2, count( $new ) ), array( $plan['totals']['leftovers_used'], $plan['totals']['leftovers_new'] ) );
check_same( 'the sheets the plan takes', $plan['totals']['sheets'], Plan::sheets( $plan ) );
$text = '';
foreach ( $plan['sources'][0]['cuts'] as $cut ) {
	$text .= $cut['text'] . "\n";
}
check( 'a cut on a leftover speaks of "the whole leftover"', false !== strpos( $text, 'straight across the whole leftover' ), $text );
$waste = '';
foreach ( $plan['sources'][1]['cuts'] as $cut ) {
	$waste .= $cut['text'] . "\n";
}
check( 'an offcut too small to keep is called waste, with its size', 1 === preg_match( '/The rest \(1 × 13 in\) is waste\./', $waste ), $waste );
check( 'waste rectangles are listed for the drawing', 2 === count( $plan['sources'][1]['waste'] ) && array() === $plan['sources'][1]['leftovers'] );

$odd   = array(
	'sheets'    => 0,
	'leftovers' => array(
		array( 'id' => 21, 'label' => 'L1', 'length' => 0, 'width' => fh_in( 10 ) ),
		array( 'id' => 22, 'label' => 'L2', 'length' => fh_in( 30 ), 'width' => fh_in( 20 ) ),
		array( 'id' => 23, 'label' => 'L3', 'length' => fh_in( 30 ), 'width' => -5 ),
	),
);
$small = array( array( 'key' => 'tiny', 'name' => 'Tiny', 'length' => fh_in( 6 ), 'width' => fh_in( 6 ), 'height' => fh_in( 6 ), 'count' => 1, 'pieces' => array( array( 'name' => 'Square', 'length' => fh_in( 6 ), 'width' => fh_in( 6 ), 'count' => 2 ) ) ) );
$built = Plan::build( $small, $odd, $settings, 4 );
check( 'a leftover row without a size is left out — the source that is used keeps its own label, id and size', array() === $built['errors'] && 'L2' === $built['sources'][0]['label'] && 22 === $built['sources'][0]['leftover_id'] && 30 === $built['sources'][0]['length'] && array( 22 ) === Plan::used_leftover_ids( $built ), fh_export( array( $built['errors'], isset( $built['sources'][0] ) ? array( $built['sources'][0]['label'], $built['sources'][0]['leftover_id'] ) : null ) ) );

echo "4. Warnings\n";
check( 'more sheets than the stock says: a warning naming both numbers', $plan['totals']['sheets'] > 1 ? 1 === count( preg_grep( '/takes ' . $plan['totals']['sheets'] . ' whole sheet\(s\) and the stock says 1/', $plan['warnings'] ) ) : array() === $plan['warnings'], implode( ' | ', $plan['warnings'] ) );
$many = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 12 ) ), array( 'sheets' => 0, 'leftovers' => array() ), $settings, 1 );
check( '12 boxes with no sheet in stock: the plan is still made, with the warning', array() === $many['errors'] && 1 === count( preg_grep( '/the stock says 0/', $many['warnings'] ) ) && $many['totals']['sheets'] >= 2 );
$changed            = fh_box( 'cube', 'Cube', 12, 12, 12, 1 );
$changed['changed'] = true;
$warn               = Plan::build( array( $changed ), array( 'sheets' => 5, 'leftovers' => array() ), $settings, 1 );
check( 'a box that changed size since its cut list was saved is pointed out', 1 === count( preg_grep( '/"Cube" has changed size/', $warn['warnings'] ) ) );

echo "5. A piece that fits no sheet\n";
$giant = array(
	'key'      => 'giant',
	'name'     => 'Giant',
	'length'   => fh_in( 100 ),
	'width'    => fh_in( 30 ),
	'height'   => fh_in( 20 ),
	'count'    => 2,
	'cut_list' => 'saved',
	'pieces'   => array(
		array( 'name' => 'Base', 'length' => fh_in( 100 ), 'width' => fh_in( 30 ), 'count' => 1 ),
		array( 'name' => 'End', 'length' => fh_in( 29 ), 'width' => fh_in( 19 ), 'count' => 2 ),
	),
);
$plan  = Plan::build( array( $giant ), array( 'sheets' => 5, 'leftovers' => array() ), $settings, 1 );
check_same( 'no error: the rest is still planned', array(), $plan['errors'] );
check( 'the piece is reported once, with how many and why', 1 === count( $plan['unplaced'] ) && 'Giant' === $plan['unplaced'][0]['box'] && 'Base' === $plan['unplaced'][0]['name'] && 2 === $plan['unplaced'][0]['count'] && 'too_big' === $plan['unplaced'][0]['reason'] );
check_same( 'in words', 'Giant · Base (100 × 30 in) does not fit a whole sheet (96 × 48 in).', $plan['unplaced'][0]['text'] );
check( 'a warning points at the list', 1 === count( preg_grep( '/too large for a sheet/', $plan['warnings'] ) ) );
check( 'the summary shows 0 of 2 placed for it and 4 of 4 for the ends', 0 === $plan['summary'][0]['pieces'][0]['placed'] && 2 === $plan['summary'][0]['pieces'][0]['total'] && 4 === $plan['summary'][0]['pieces'][1]['placed'] );
check_same( 'totals: 6 pieces asked for, 4 placed', array( 6, 4 ), array( $plan['totals']['pieces'], $plan['totals']['placed'] ) );
check_same( 'the saved cut list is named as such', 'saved', $plan['boxes'][0]['cut_list'] );
$fixed = Plan::build( array( fh_box( 'tall', 'Tall', 10, 10, 60, 1 ) ), array( 'sheets' => 5, 'leftovers' => array() ), array_merge( $settings, array( 'rotate' => false ) ), 1 );
check( 'with turning off the reason says so (both kinds of side are reported)', 2 === count( $fixed['unplaced'] ) && false !== strpos( $fixed['unplaced'][0]['text'], 'turning pieces is switched off' ), isset( $fixed['unplaced'][0] ) ? $fixed['unplaced'][0]['text'] : 'nothing reported' );

echo "6. Nothing to cut, too much to cut\n";
$none = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 0 ) ), array( 'sheets' => 1, 'leftovers' => array() ), $settings, 1 );
check( 'every count 0: an error, no sources', 1 === count( $none['errors'] ) && array() === $none['sources'] );
$none = Plan::build( array(), array( 'sheets' => 1, 'leftovers' => array() ), $settings, 1 );
check( 'no boxes at all: the same error', 1 === count( $none['errors'] ) );
$empty           = fh_box( 'cube', 'Cube', 12, 12, 12, 4 );
$empty['pieces'] = array();
$none            = Plan::build( array( $empty ), array( 'sheets' => 1, 'leftovers' => array() ), $settings, 1 );
check( 'a box without a cut list: nothing to cut', 1 === count( $none['errors'] ) );
$huge = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 500 ), fh_box( 'flat', 'Flat', 16, 12, 10, 500 ) ), array( 'sheets' => 1, 'leftovers' => array() ), $settings, 1 );
check( 'more than ' . Plan::MAX_PIECES . ' pieces: refused with an error, nothing laid out', 1 === count( $huge['errors'] ) && array() === $huge['sources'] && false !== strpos( $huge['errors'][0], (string) Plan::MAX_PIECES ) );
$capped = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 100000 ) ), array( 'sheets' => 1, 'leftovers' => array() ), $settings, 1 );
check_same( 'a count is capped at ' . Plan::MAX_BOX_COUNT, Plan::MAX_BOX_COUNT, $capped['boxes'][0]['count'] );

echo "7. Fractions, a kerf, the same answer twice\n";
$odd  = array_merge( $settings, array( 'kerf' => fh_in( 0.125 ), 'thickness' => fh_in( 0.75 ) ) );
$plan = Plan::build( array( fh_box( 'odd', 'Odd', 14.5, 9.25, 7, 4, 0.75 ) ), array( 'sheets' => 2, 'leftovers' => array( array( 'id' => 2, 'label' => 'L2', 'length' => fh_in( 20.5 ), 'width' => fh_in( 15 ) ) ) ), $odd, 3 );
check_same( 'no errors', array(), $plan['errors'] );
check_same( 'the kerf is in the plan', 0.125, $plan['settings']['kerf'] );
check_same( 'sizes read like a tape measure in the words', 1, preg_match( '/14 1\/2 × 9 1\/4 in/', json_encode( $plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
$exact = true;
foreach ( $plan['sources'] as $source ) {
	foreach ( $source['pieces'] as $piece ) {
		foreach ( array( 'x', 'y', 'w', 'h' ) as $key ) {
			$exact = $exact && Units::to_inches( Units::from_inches( $piece[ $key ] ) ) === $piece[ $key ];
		}
	}
	foreach ( $source['cuts'] as $cut ) {
		$exact = $exact && 0.125 === $cut['kerf'];
	}
}
check( 'every coordinate survives inches → units → inches unchanged', $exact );
check( 'built twice: identical', Plan::build( array( fh_box( 'odd', 'Odd', 14.5, 9.25, 7, 4, 0.75 ) ), array( 'sheets' => 2, 'leftovers' => array( array( 'id' => 2, 'label' => 'L2', 'length' => fh_in( 20.5 ), 'width' => fh_in( 15 ) ) ) ), $odd, 3 ) === $plan );

echo "8. The shape other code may rely on\n";
$plan = Plan::build( array( fh_box( 'cube', 'Cube', 12, 12, 12, 2 ) ), array( 'sheets' => 3, 'leftovers' => array( array( 'id' => 4, 'label' => 'L4', 'length' => fh_in( 26 ), 'width' => fh_in( 13 ) ) ) ), $settings, 5 );
check_same( 'top level', array( 'schema', 'units', 'sheet', 'settings', 'boxes', 'sources', 'unplaced', 'summary', 'totals', 'labels', 'warnings', 'errors' ), array_keys( $plan ) );
check_same( 'a source', array( 'n', 'kind', 'label', 'leftover_id', 'length', 'width', 'pieces', 'cuts', 'leftovers', 'waste', 'area', 'pieces_area', 'leftover_area', 'waste_area', 'waste_pct' ), array_keys( $plan['sources'][0] ) );
check_same( 'a piece', array( 'n', 'box', 'box_key', 'name', 'length', 'width', 'x', 'y', 'w', 'h', 'rotated' ), array_keys( $plan['sources'][0]['pieces'][0] ) );
check_same( 'a cut', array( 'n', 'axis', 'at', 'x1', 'y1', 'x2', 'y2', 'kerf', 'part', 'of', 'side', 'text' ), array_keys( $plan['sources'][0]['cuts'][0] ) );
$last = end( $plan['sources'] );
check_same( 'a new leftover', array( 'label', 'x', 'y', 'w', 'h' ), array_keys( $last['leftovers'][0] ) );
check_same( 'a box', array( 'key', 'name', 'length', 'width', 'height', 'count', 'cut_list', 'pieces' ), array_keys( $plan['boxes'][0] ) );
check_same( 'the totals', array( 'boxes', 'pieces', 'placed', 'sheets', 'sheets_in_stock', 'leftovers_used', 'leftovers_new', 'cuts', 'area', 'pieces_area', 'leftover_area', 'waste_area', 'waste_pct' ), array_keys( $plan['totals'] ) );
$inside = true;
foreach ( $plan['sources'] as $source ) {
	foreach ( array_merge( $source['pieces'], $source['leftovers'], $source['waste'] ) as $rect ) {
		$inside = $inside && $rect['x'] >= 0 && $rect['y'] >= 0 && $rect['x'] + $rect['w'] <= $source['length'] && $rect['y'] + $rect['h'] <= $source['width'];
	}
	foreach ( $source['cuts'] as $cut ) {
		$inside = $inside && ( 'v' === $cut['axis'] ? $cut['x1'] === $cut['x2'] && $cut['y2'] - $cut['y1'] === $cut['part']['h'] : $cut['y1'] === $cut['y2'] && $cut['x2'] - $cut['x1'] === $cut['part']['w'] );
	}
}
check( 'x runs along a source\'s length, y along its width; every rectangle is inside; every cut spans its part', $inside );

fh_finish();
