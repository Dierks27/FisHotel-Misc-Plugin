<?php
/**
 * Units — what the owner types (decimals, tape-measure fractions) into
 * whole units and back out the way a tape measure reads, and the cut-list
 * suggestion for several boxes: the six pieces from the inside dimensions
 * and the foam thickness, proven to fill the shell exactly.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/units-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Cut_List;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

echo "1. Typed sizes → units\n";
check_same( '12 → 120000', 120000, Units::parse( '12' ) );
check_same( '9.5 → 95000', 95000, Units::parse( '9.5' ) );
check_same( '.5 → 5000', 5000, Units::parse( '.5' ) );
check_same( '11 3/4 → 117500', 117500, Units::parse( '11 3/4' ) );
check_same( '11-3/4 → 117500', 117500, Units::parse( '11-3/4' ) );
check_same( '3/4 → 7500', 7500, Units::parse( '3/4' ) );
check_same( '1/16 → 625 (exact)', 625, Units::parse( '1/16' ) );
check_same( '0.0625 → 625', 625, Units::parse( '0.0625' ) );
check_same( 'a trailing unit is ignored: 12 in, 12", 12 inches', array( 120000, 120000, 120000 ), array( Units::parse( '12 in' ), Units::parse( '12"' ), Units::parse( '12 inches' ) ) );
check_same( 'a comma is a decimal point: 9,5', 95000, Units::parse( '9,5' ) );
check_same( 'spaces around are fine', 480000, Units::parse( '  48  ' ) );
check_same( '0 is a size (a kerf of nothing)', 0, Units::parse( '0' ) );
check_same( 'numbers are taken as inches', array( 960000, 5000 ), array( Units::parse( 96 ), Units::parse( 0.5 ) ) );
check_same( 'empty → null', null, Units::parse( '' ) );
check_same( 'words → null', null, Units::parse( 'big' ) );
check_same( 'negative → null', null, Units::parse( '-3' ) );
check_same( 'a fraction over zero → null', null, Units::parse( '3/0' ) );
check_same( 'absurdly large → null', null, Units::parse( '100000' ) );
check_same( 'an array → null', null, Units::parse( array( 12 ) ) );
check_same( 'a third of an inch rounds to four decimals', 3333, Units::parse( '1/3' ) );

echo "2. Units → what is shown\n";
check_same( 'whole inches', '12', Units::format( 120000 ) );
check_same( 'a half', '9 1/2', Units::format( 95000 ) );
check_same( 'three quarters', '11 3/4', Units::format( 117500 ) );
check_same( 'a sixteenth', '1/16', Units::format( 625 ) );
check_same( 'thirteen sixteenths', '2 13/16', Units::format( 28125 ) );
check_same( 'not a sixteenth → decimal', '10.3', Units::format( 103000 ) );
check_same( 'zero', '0', Units::format( 0 ) );
check_same( 'a size: 16 × 12', '16 × 12', Units::size( 160000, 120000 ) );
check_same( 'decimal for a form field', array( '12', '9.5', '0.0625', '11.75' ), array( Units::decimal( 120000 ), Units::decimal( 95000 ), Units::decimal( 625 ), Units::decimal( 117500 ) ) );
check_same( 'inches for JSON: whole numbers are integers', array( 96, 9.5, 0.0625 ), array( Units::to_inches( 960000 ), Units::to_inches( 95000 ), Units::to_inches( 625 ) ) );
check_same( 'typed → units → decimal → units is stable', 117500, Units::parse( Units::decimal( Units::parse( '11 3/4' ) ) ) );
check_same( 'inches → units → inches is stable', 12.375, Units::to_inches( Units::from_inches( 12.375 ) ) );
check_same( 'percent with one decimal', 3.3, Units::percent( 40, 1200 ) );
check_same( 'percent of nothing is 0', 0.0, Units::percent( 5, 0 ) );
check_same( 'a whole sheet is 32 square feet', 32.0, Units::square_feet( fh_in( 96 ) * fh_in( 48 ) ) );

echo "3. The six suggested pieces\n";
$sizes = static function ( array $suggestion ) {
	$out = array();
	foreach ( $suggestion['pieces'] as $piece ) {
		$out[] = $piece['name'] . ': ' . Units::size( $piece['length'], $piece['width'] ) . ' ×' . $piece['count'];
	}
	return $out;
};
$t = fh_in( 0.5 );

check_same(
	'12 × 12 × 12 with half-inch foam',
	array( 'Top / bottom: 12 × 12 ×2', 'Long side: 12 × 11 ×2', 'Short side: 11 × 11 ×2' ),
	$sizes( Cut_List::suggest( fh_in( 12 ), fh_in( 12 ), fh_in( 12 ), $t ) )
);
check_same(
	'16 × 12 × 10: top and bottom L × W, long sides L × (H − 2t), short sides (W − 2t) × (H − 2t)',
	array( 'Top / bottom: 16 × 12 ×2', 'Long side: 16 × 9 ×2', 'Short side: 11 × 9 ×2' ),
	$sizes( Cut_List::suggest( fh_in( 16 ), fh_in( 12 ), fh_in( 10 ), $t ) )
);
check_same(
	'the same box typed 12 × 16 × 10: the long sides are still the long ones',
	$sizes( Cut_List::suggest( fh_in( 16 ), fh_in( 12 ), fh_in( 10 ), $t ) ),
	$sizes( Cut_List::suggest( fh_in( 12 ), fh_in( 16 ), fh_in( 10 ), $t ) )
);
check_same(
	'20 × 14 × 12 with three-quarter-inch foam',
	array( 'Top / bottom: 20 × 14 ×2', 'Long side: 20 × 10 1/2 ×2', 'Short side: 12 1/2 × 10 1/2 ×2' ),
	$sizes( Cut_List::suggest( fh_in( 20 ), fh_in( 14 ), fh_in( 12 ), fh_in( 0.75 ) ) )
);
check_same(
	'8.5 × 6.25 × 5 with half-inch foam',
	array( 'Top / bottom: 8 1/2 × 6 1/4 ×2', 'Long side: 8 1/2 × 4 ×2', 'Short side: 5 1/4 × 4 ×2' ),
	$sizes( Cut_List::suggest( fh_in( 8.5 ), fh_in( 6.25 ), fh_in( 5 ), $t ) )
);
check_same( 'six pieces per box', 6, Cut_List::piece_count( Cut_List::suggest( fh_in( 16 ), fh_in( 12 ), fh_in( 10 ), $t )['pieces'] ) );

echo "4. The six pieces fill the shell exactly (no gaps, no overlaps)\n";
foreach ( array( array( 12, 12, 12, 0.5 ), array( 16, 12, 10, 0.5 ), array( 20, 14, 12, 0.75 ), array( 24, 10, 8, 1 ), array( 8.5, 6.25, 5, 0.5 ), array( 10, 10, 30, 0.25 ) ) as $box ) {
	list( $l, $w, $h, $thick ) = $box;
	$pieces                    = Cut_List::suggest( fh_in( $l ), fh_in( $w ), fh_in( $h ), fh_in( $thick ) )['pieces'];
	$foam                      = 0.0;
	foreach ( $pieces as $piece ) {
		$foam += Units::to_inches( $piece['length'] ) * Units::to_inches( $piece['width'] ) * $thick * $piece['count'];
	}
	$shell = $l * $w * $h - ( $l - 2 * $thick ) * ( $w - 2 * $thick ) * ( $h - 2 * $thick );
	check( "$l × $w × $h, foam $thick: the pieces' volume is the box minus the space left inside", abs( $foam - $shell ) < 1e-6, "foam $foam, shell $shell" );
}

echo "5. Boxes the foam does not fit\n";
$thick = Cut_List::suggest( fh_in( 6 ), fh_in( 4 ), fh_in( 1 ), $t );
check( 'a box as high as two layers of foam: only top and bottom, and a note', 1 === count( $thick['pieces'] ) && 1 === count( $thick['notes'] ) );
$narrow = Cut_List::suggest( fh_in( 6 ), fh_in( 1 ), fh_in( 4 ), $t );
check( 'a box as wide as two layers of foam: no short sides, and a note', 2 === count( $narrow['pieces'] ) && 1 === count( $narrow['notes'] ) );
check( 'a box without a size: nothing, and a note', array() === Cut_List::suggest( 0, 0, 0, $t )['pieces'] && 1 === count( Cut_List::suggest( 0, 0, 0, $t )['notes'] ) );
check_same(
	'no foam thickness: the sides are the full height',
	array( 'Top / bottom: 10 × 8 ×2', 'Long side: 10 × 6 ×2', 'Short side: 8 × 6 ×2' ),
	$sizes( Cut_List::suggest( fh_in( 10 ), fh_in( 8 ), fh_in( 6 ), 0 ) )
);

echo "6. A cut list the owner saved\n";
$clean = Cut_List::sanitize(
	array(
		array( 'name' => ' Lid <b>and</b> base ', 'length' => '16', 'width' => '12 1/2', 'count' => '2' ),
		array( 'name' => '', 'length' => '9.5', 'width' => '4', 'count' => '4' ),
		array( 'name' => 'no size', 'length' => '', 'width' => '4', 'count' => '1' ),
		array( 'name' => 'no count', 'length' => '5', 'width' => '4', 'count' => '0' ),
		array( 'name' => 'negative', 'length' => '-5', 'width' => '4', 'count' => '1' ),
		'not a row',
		array( 'name' => 'many', 'length' => '3', 'width' => '3', 'count' => '900' ),
	),
	true
);
check_same( 'rows without a size or a count are dropped', 3, count( $clean ) );
check_same( 'a name is one plain line', 'Lid and base', $clean[0]['name'] );
check_same( 'sizes are parsed as typed', array( 160000, 125000 ), array( $clean[0]['length'], $clean[0]['width'] ) );
check_same( 'a row without a name is "Piece N"', 'Piece 2', $clean[1]['name'] );
check_same( 'the count is capped', Cut_List::MAX_COUNT, $clean[2]['count'] );
check_same( 'sanitising a clean list (units) changes nothing', $clean, Cut_List::sanitize( $clean ) );
check_same( 'at most MAX_ROWS rows are kept', Cut_List::MAX_ROWS, count( Cut_List::sanitize( array_fill( 0, 40, array( 'name' => 'x', 'length' => 10000, 'width' => 10000, 'count' => 1 ) ) ) ) );

$box   = array( 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 10 ) );
$saved = array( 'pieces' => $clean, 'box' => Cut_List::box_signature( fh_in( 16 ), fh_in( 12 ), fh_in( 10 ) ) );
$used  = Cut_List::for_box( $box, $saved, $t );
check( 'a saved list is what the box uses', 'saved' === $used['source'] && $clean === $used['pieces'] && false === $used['changed'] );
$used = Cut_List::for_box( array( 'length' => fh_in( 16 ), 'width' => fh_in( 12 ), 'height' => fh_in( 12 ) ), $saved, $t );
check( 'a box that changed size since is pointed out', 'saved' === $used['source'] && true === $used['changed'] );
$used = Cut_List::for_box( $box, null, $t );
check( 'without a saved list: the suggestion', 'suggested' === $used['source'] && 3 === count( $used['pieces'] ) );
$used = Cut_List::for_box( $box, array( 'pieces' => array(), 'box' => 'x' ), $t );
check( 'an empty saved list falls back to the suggestion', 'suggested' === $used['source'] );
check_same( 'the foam one box takes (16 × 12 × 10): 2 × 192 + 2 × 144 + 2 × 99 square inches', 870.0, (float) ( Cut_List::area( Cut_List::for_box( $box, null, $t )['pieces'] ) / ( Units::PER_INCH * Units::PER_INCH ) ) );

fh_finish();
