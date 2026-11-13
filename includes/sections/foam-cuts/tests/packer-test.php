<?php
/**
 * Packer — the layout itself, checked independently of how it was made:
 * every piece inside its source, no two parts overlapping, the layout
 * separable by straight edge-to-edge cuts alone (worked out from the
 * rectangles), the listed cuts replayed one by one, the kerf between
 * neighbours, turning on and off, leftovers before sheets and the
 * snuggest leftover first, the largest possible remainder, no cut longer
 * than a sheet is wide, the same answer every time, a piece that fits no
 * sheet reported, and hundreds of pieces in well under a second or two.
 * Invented sizes only.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/packer-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Packer;

/**
 * Pieces from [ w, h, count ] rows (inches).
 */
function fh_pieces( array $rows ) {
	$out = array();
	foreach ( $rows as $row ) {
		for ( $i = 0; $i < $row[2]; $i++ ) {
			$out[] = array(
				'id' => 'p' . count( $out ),
				'w'  => fh_in( $row[0] ),
				'h'  => fh_in( $row[1] ),
			);
		}
	}
	return $out;
}

/**
 * Leftovers from [ w, h ] rows (inches); ids 1, 2, 3 …
 */
function fh_leftovers( array $rows ) {
	$out = array();
	foreach ( $rows as $i => $row ) {
		$out[] = array(
			'id'    => $i + 1,
			'label' => 'L' . ( $i + 1 ),
			'w'     => fh_in( $row[0] ),
			'h'     => fh_in( $row[1] ),
		);
	}
	return $out;
}

/**
 * Every independent check of one result; returns the problems found.
 *
 * @param array $result Packer::pack().
 * @param array $pieces The pieces asked for.
 * @param array $opts   The options.
 * @return string[]
 */
function fh_audit( array $result, array $pieces, array $opts ) {
	$o        = Packer::options( $opts );
	$problems = array();
	$seen     = array();
	$asked    = array();
	foreach ( $pieces as $piece ) {
		$asked[ $piece['id'] ] = $piece;
	}

	foreach ( $result['sources'] as $s => $source ) {
		$rects = array();
		$area  = 0;

		foreach ( $source['pieces'] as $piece ) {
			$rect = array( $piece['x'], $piece['y'], $piece['w'], $piece['h'] );

			if ( isset( $seen[ $piece['id'] ] ) ) {
				$problems[] = "piece {$piece['id']} is placed twice";
			}
			$seen[ $piece['id'] ] = true;

			if ( $rect[0] < 0 || $rect[1] < 0 || $rect[0] + $rect[2] > $source['w'] || $rect[1] + $rect[3] > $source['h'] ) {
				$problems[] = "piece {$piece['id']} sticks out of source $s";
			}

			$want = $asked[ $piece['id'] ];
			$same = $piece['w'] === $want['w'] && $piece['h'] === $want['h'];
			$turn = $piece['w'] === $want['h'] && $piece['h'] === $want['w'];
			if ( ! $same && ! $turn ) {
				$problems[] = "piece {$piece['id']} changed size";
			}
			if ( $piece['rotated'] && ! $o['rotate'] ) {
				$problems[] = "piece {$piece['id']} was turned although turning is off";
			}
			if ( ! $o['rotate'] && ! $same ) {
				$problems[] = "piece {$piece['id']} lies the wrong way round";
			}
			if ( $piece['rotated'] !== ( ! $same ) && $want['w'] !== $want['h'] ) {
				$problems[] = "piece {$piece['id']}: the rotated flag is wrong";
			}

			$rects[] = $rect;
			$area   += $rect[2] * $rect[3];
		}

		$kept = 0;
		foreach ( $source['free'] as $free ) {
			$rect = array( $free['x'], $free['y'], $free['w'], $free['h'] );
			if ( $rect[0] < 0 || $rect[1] < 0 || $rect[0] + $rect[2] > $source['w'] || $rect[1] + $rect[3] > $source['h'] ) {
				$problems[] = "a remainder sticks out of source $s";
			}
			if ( $free['keep'] !== Packer::keepable( $free['w'], $free['h'], $o ) ) {
				$problems[] = "a remainder of source $s is kept (or thrown away) against the smallest-leftover rule";
			}
			if ( $free['keep'] ) {
				$kept += $rect[2] * $rect[3];
			}
			$rects[] = $rect;
		}

		$count = count( $rects );
		for ( $a = 0; $a < $count; $a++ ) {
			for ( $b = $a + 1; $b < $count; $b++ ) {
				if ( fh_overlap( $rects[ $a ], $rects[ $b ] ) ) {
					$problems[] = "two parts of source $s overlap";
				}
				// Neighbours are a blade's width apart wherever a cut separates them.
				$gap_x = max( $rects[ $a ][0] - ( $rects[ $b ][0] + $rects[ $b ][2] ), $rects[ $b ][0] - ( $rects[ $a ][0] + $rects[ $a ][2] ) );
				$gap_y = max( $rects[ $a ][1] - ( $rects[ $b ][1] + $rects[ $b ][3] ), $rects[ $b ][1] - ( $rects[ $a ][1] + $rects[ $a ][3] ) );
				if ( $gap_x < $o['kerf'] && $gap_y < $o['kerf'] ) {
					$problems[] = "two parts of source $s are closer than the blade is wide";
				}
			}
		}

		if ( ! fh_guillotine( $rects ) ) {
			$problems[] = "source $s cannot be cut apart with edge-to-edge cuts";
		}

		if ( $area !== $source['pieces_area'] || $kept !== $source['leftover_area'] || $source['w'] * $source['h'] !== $source['area'] || $source['area'] - $area - $kept !== $source['waste_area'] || $source['waste_area'] < 0 ) {
			$problems[] = "the areas of source $s do not add up";
		}
		if ( ! $source['pieces'] ) {
			$problems[] = "source $s is listed without a piece";
		}
	}

	foreach ( $result['unplaced'] as $piece ) {
		if ( isset( $seen[ $piece['id'] ] ) ) {
			$problems[] = "piece {$piece['id']} is both placed and reported";
		}
		$seen[ $piece['id'] ] = true;
	}
	if ( count( $seen ) !== count( $asked ) ) {
		$problems[] = 'a piece went missing: ' . count( $seen ) . ' of ' . count( $asked ) . ' accounted for';
	}

	// The listed cuts, replayed (Packer::verify is the same check the plan runs on itself).
	return array_merge( $problems, Packer::verify( $result, $opts ) );
}

$sheet = array( 'sheet_w' => fh_in( 96 ), 'sheet_h' => fh_in( 48 ), 'kerf' => 0, 'rotate' => true, 'min_w' => fh_in( 4 ), 'min_h' => fh_in( 4 ) );

echo "1. One piece from a whole sheet\n";
$pieces = fh_pieces( array( array( 12, 12, 1 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check_same( 'sound', array(), fh_audit( $result, $pieces, $sheet ) );
check( 'one new sheet, one piece in its top-left corner', 1 === $result['sheets'] && 1 === count( $result['sources'] ) && 'sheet' === $result['sources'][0]['kind'] && 0 === $result['sources'][0]['pieces'][0]['x'] && 0 === $result['sources'][0]['pieces'][0]['y'] );
$sizes = array();
foreach ( $result['sources'][0]['free'] as $free ) {
	$sizes[] = $free['w'] / 10000 . 'x' . $free['h'] / 10000;
}
sort( $sizes );
check_same( 'the remainder is kept as large as it can be: 84 × 48 and the 12 × 36 under the piece', array( '12x36', '84x48' ), $sizes );
check_same( 'two cuts', 2, count( $result['sources'][0]['cuts'] ) );
$first = $result['sources'][0]['cuts'][0];
check( 'cut 1 runs top to bottom across the whole sheet, 12 in from the left edge', 'v' === $first['axis'] && fh_in( 12 ) === $first['at'] && 0 === $first['of'] && array( 'x' => 0, 'y' => 0, 'w' => fh_in( 96 ), 'h' => fh_in( 48 ) ) === $first['part'] );
$second = $result['sources'][0]['cuts'][1];
check( 'cut 2 is made on the strip to the left of cut 1 and frees the piece', 'h' === $second['axis'] && 1 === $second['of'] && 'near' === $second['side'] && 'piece' === $second['near']['kind'] && 0 === $second['near']['piece'] );
check( 'no waste', 0 === $result['sources'][0]['waste_area'] );

echo "2. A piece that is the whole sheet, and an exact row\n";
$pieces = fh_pieces( array( array( 96, 48, 1 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check( 'no cut at all', array() === fh_audit( $result, $pieces, $sheet ) && array() === $result['sources'][0]['cuts'] && array() === $result['sources'][0]['free'] );
$pieces = fh_pieces( array( array( 12, 48, 8 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check( 'eight 12 × 48 strips are one sheet and seven cuts, nothing left', array() === fh_audit( $result, $pieces, $sheet ) && 1 === $result['sheets'] && 7 === count( $result['sources'][0]['cuts'] ) && array() === $result['sources'][0]['free'] );

echo "3. The kerf\n";
$kerf   = array_merge( $sheet, array( 'kerf' => fh_in( 0.125 ) ) );
$result = Packer::pack( $pieces, array(), $kerf );
check_same( 'sound with an eighth-inch blade', array(), fh_audit( $result, $pieces, $kerf ) );
check_same( 'the eighth strip no longer fits: a second sheet', 2, $result['sheets'] );
check_same( 'seven strips on the first sheet', 7, count( $result['sources'][0]['pieces'] ) );
$xs = array();
foreach ( $result['sources'][0]['pieces'] as $piece ) {
	$xs[] = $piece['x'] / 10000;
}
sort( $xs );
check_same( 'each strip starts a blade further along: 0, 12.125, 24.25 …', array( 0, 12.125, 24.25, 36.375, 48.5, 60.625, 72.75 ), $xs );
check( 'the kerf is waste, never part of a piece: 7 cuts × 1/8 × 48 = 42 sq in less', fh_in( 96 ) * fh_in( 48 ) - 7 * fh_in( 12 ) * fh_in( 48 ) - $result['sources'][0]['leftover_area'] === $result['sources'][0]['waste_area'] && $result['sources'][0]['waste_area'] >= 7 * fh_in( 0.125 ) * fh_in( 48 ) );
$pieces = fh_pieces( array( array( 47.95, 12, 1 ) ) );
$result = Packer::pack( $pieces, fh_leftovers( array( array( 48, 12 ) ) ), $kerf );
check( 'a sliver thinner than the blade: one trimming cut, nothing behind it', array() === fh_audit( $result, $pieces, $kerf ) && 1 === count( $result['sources'][0]['cuts'] ) && 'none' === $result['sources'][0]['cuts'][0]['far']['kind'] && array() === $result['sources'][0]['free'] );
$pieces = fh_pieces( array( array( 10, 10, 30 ), array( 14, 9, 12 ), array( 7.5, 5.25, 9 ) ) );
$wide   = array_merge( $sheet, array( 'kerf' => fh_in( 0.25 ) ) );
check_same( 'a mixed plan with a quarter-inch blade is sound', array(), fh_audit( Packer::pack( $pieces, fh_leftovers( array( array( 40, 30 ), array( 22, 9.5 ) ) ), $wide ), $pieces, $wide ) );

echo "4. Turning pieces\n";
$pieces = fh_pieces( array( array( 20, 60, 1 ), array( 30, 10, 2 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check( 'allowed: the 20 × 60 piece is laid along the sheet', array() === fh_audit( $result, $pieces, $sheet ) && array() === $result['unplaced'] );
$turned = null;
foreach ( $result['sources'][0]['pieces'] as $piece ) {
	if ( 'p0' === $piece['id'] ) {
		$turned = $piece;
	}
}
check( '…turned: 60 along the length, 20 across, flagged as rotated', $turned && fh_in( 60 ) === $turned['w'] && fh_in( 20 ) === $turned['h'] && true === $turned['rotated'] );
$fixed  = array_merge( $sheet, array( 'rotate' => false ) );
$result = Packer::pack( $pieces, array(), $fixed );
check_same( 'not allowed: sound', array(), fh_audit( $result, $pieces, $fixed ) );
check( '…and the 20 × 60 piece is reported, the others are placed as typed', 1 === count( $result['unplaced'] ) && 'p0' === $result['unplaced'][0]['id'] && 'too_big' === $result['unplaced'][0]['reason'] && 2 === count( $result['sources'][0]['pieces'] ) );
$pieces = fh_pieces( array( array( 11, 12, 16 ), array( 9, 30, 5 ) ) );
$result = Packer::pack( $pieces, array(), $fixed );
$any    = false;
foreach ( $result['sources'] as $source ) {
	foreach ( $source['pieces'] as $piece ) {
		$any = $any || $piece['rotated'];
	}
}
check( 'with turning off nothing is ever turned', array() === fh_audit( $result, $pieces, $fixed ) && false === $any );
$square = fh_pieces( array( array( 10, 10, 6 ) ) );
$result = Packer::pack( $square, array(), $sheet );
$any    = false;
foreach ( $result['sources'][0]['pieces'] as $piece ) {
	$any = $any || $piece['rotated'];
}
check( 'a square is never "turned"', false === $any );

echo "5. Leftovers before sheets\n";
$pieces = fh_pieces( array( array( 12, 12, 2 ) ) );
$stock  = fh_leftovers( array( array( 60, 40 ), array( 13, 25 ), array( 30, 30 ) ) );
$result = Packer::pack( $pieces, $stock, $sheet );
check_same( 'sound', array(), fh_audit( $result, $pieces, $sheet ) );
check_same( 'no whole sheet is taken while a leftover holds the pieces', 0, $result['sheets'] );
check( 'the leftover that wastes least is used: L2 (13 × 25), not the 60 × 40 or the 30 × 30', 1 === count( $result['sources'] ) && 'leftover' === $result['sources'][0]['kind'] && 1 === $result['sources'][0]['stock'] );
check( 'a leftover that is not cut into is not part of the plan', 1 === count( $result['sources'] ) );
$pieces = fh_pieces( array( array( 40, 30, 1 ), array( 12, 12, 3 ) ) );
$result = Packer::pack( $pieces, fh_leftovers( array( array( 26, 13 ), array( 14, 14 ) ) ), $sheet );
$kinds  = array();
foreach ( $result['sources'] as $source ) {
	$kinds[] = $source['kind'];
}
check_same( 'sound', array(), fh_audit( $result, $pieces, $sheet ) );
check( 'what fits the leftovers goes there; only the large piece takes a sheet', array( 'leftover', 'leftover', 'sheet' ) === $kinds && 1 === $result['sheets'] && 1 === count( $result['sources'][2]['pieces'] ) );
check( 'leftovers come first in the plan, in the order they are in stock, then the sheets', 0 === $result['sources'][0]['stock'] && 1 === $result['sources'][1]['stock'] && 1 === $result['sources'][2]['sheet'] );
$pieces = fh_pieces( array( array( 12, 12, 1 ) ) );
$result = Packer::pack( $pieces, fh_leftovers( array( array( 12, 12 ) ) ), $sheet );
check( 'a leftover that is exactly the piece: used whole, no cut, nothing left', array() === fh_audit( $result, $pieces, $sheet ) && 0 === $result['sheets'] && array() === $result['sources'][0]['cuts'] && array() === $result['sources'][0]['free'] );
$result = Packer::pack( $pieces, fh_leftovers( array( array( 11, 30 ), array( 0, 5 ) ) ), $sheet );
check( 'a leftover too small for the piece (or without a size) is left alone', 1 === $result['sheets'] && 1 === count( $result['sources'] ) );

echo "6. What is left over\n";
$small  = array_merge( $sheet, array( 'min_w' => fh_in( 6 ), 'min_h' => fh_in( 10 ), 'rotate' => false ) );
$pieces = fh_pieces( array( array( 40, 43, 1 ) ) );
$result = Packer::pack( $pieces, array(), $small );
$keeps  = array();
foreach ( $result['sources'][0]['free'] as $free ) {
	$keeps[ $free['w'] / 10000 . 'x' . $free['h'] / 10000 ] = $free['keep'];
}
ksort( $keeps );
check_same( 'a remainder is kept when it holds the smallest leftover either way round (40 × 5 does not hold 6 × 10)', array( '40x5' => false, '56x48' => true ), $keeps );
check( 'what is not kept is waste', fh_in( 40 ) * fh_in( 5 ) === $result['sources'][0]['waste_area'] && fh_in( 56 ) * fh_in( 48 ) === $result['sources'][0]['leftover_area'] );
$turnable = Packer::pack( $pieces, array(), array_merge( $small, array( 'rotate' => true ) ) );
check( 'when the piece may be turned it is, so that nothing is wasted (43 × 8 is kept)', 0 === $turnable['sources'][0]['waste_area'] && true === $turnable['sources'][0]['pieces'][0]['rotated'] );
check( 'a 10 × 6 remainder is kept the same as a 6 × 10 one', Packer::keepable( fh_in( 10 ), fh_in( 6 ), $small ) && Packer::keepable( fh_in( 6 ), fh_in( 10 ), $small ) && ! Packer::keepable( fh_in( 9 ), fh_in( 9 ), $small ) );

echo "7. Cuts a straightedge can make\n";
$pieces = fh_pieces( array( array( 12, 12, 10 ), array( 12, 11, 10 ), array( 11, 11, 10 ), array( 16, 9, 6 ), array( 24, 7, 4 ) ) );
$result = Packer::pack( $pieces, fh_leftovers( array( array( 30, 20 ) ) ), $sheet );
check_same( 'sound', array(), fh_audit( $result, $pieces, $sheet ) );
$longest = 0;
$ordered = true;
foreach ( $result['sources'] as $source ) {
	foreach ( $source['cuts'] as $i => $cut ) {
		$longest = max( $longest, 'v' === $cut['axis'] ? $cut['part']['h'] : $cut['part']['w'] );
		$ordered = $ordered && $cut['n'] === $i + 1 && $cut['of'] < $cut['n'];
	}
}
check( 'no cut is longer than the sheet is wide (48 in)', $longest <= fh_in( 48 ), ( $longest / 10000 ) . ' in' );
check( 'the cuts are numbered from 1 and each is made on a part an earlier cut (or the source itself) gave', $ordered );
$pieces = fh_pieces( array( array( 70, 10, 2 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check( 'a piece longer than that still gets its long cut — there is no other way', array() === fh_audit( $result, $pieces, $sheet ) && array() === $result['unplaced'] );

echo "8. The same question, the same answer\n";
$pieces = fh_pieces( array( array( 12, 12, 9 ), array( 12, 11, 9 ), array( 11, 11, 9 ), array( 16, 12, 4 ), array( 16, 9, 4 ), array( 11, 9, 4 ), array( 7, 5.5, 7 ) ) );
$stock  = fh_leftovers( array( array( 30, 20 ), array( 13, 13 ), array( 50, 9 ) ) );
$one    = Packer::pack( $pieces, $stock, $sheet );
$two    = Packer::pack( $pieces, $stock, $sheet );
check( 'twice in a row: identical', $one === $two );
check_same( 'sound', array(), fh_audit( $one, $pieces, $sheet ) );
$mixed = $pieces;
usort(
	$mixed,
	static function ( $a, $b ) {
		return strcmp( md5( $a['id'] ), md5( $b['id'] ) );
	}
);
$three = Packer::pack( $mixed, $stock, $sheet );
$shape = static function ( array $result ) {
	$out = array();
	foreach ( $result['sources'] as $source ) {
		$rects = array();
		foreach ( $source['pieces'] as $piece ) {
			$rects[] = $piece['x'] . ',' . $piece['y'] . ',' . $piece['w'] . ',' . $piece['h'];
		}
		sort( $rects );
		$out[] = $source['kind'] . ':' . implode( ' ', $rects );
	}
	return $out;
};
check( 'the pieces handed over in another order: the same layout', $shape( $one ) === $shape( $three ) && $one['sheets'] === $three['sheets'] );
check( 'the search it won with is named', 1 === preg_match( '#^(area|long|short)/(max|fill|short|long)$#', $one['strategy'] ) );

echo "9. A piece that fits no sheet\n";
$pieces = fh_pieces( array( array( 100, 20, 1 ), array( 50, 50, 2 ), array( 12, 12, 3 ), array( 0, 5, 1 ) ) );
$result = Packer::pack( $pieces, array(), $sheet );
check_same( 'sound', array(), fh_audit( $result, $pieces, $sheet ) );
$reasons = array();
foreach ( $result['unplaced'] as $piece ) {
	$reasons[ $piece['id'] ] = $piece['reason'];
}
ksort( $reasons );
check_same( 'the 100 × 20 and both 50 × 50 are reported as too big, the piece without a size as such', array( 'p0' => 'too_big', 'p1' => 'too_big', 'p2' => 'too_big', 'p6' => 'no_size' ), $reasons );
check( 'the pieces that do fit are still laid out', 1 === $result['sheets'] && 3 === count( $result['sources'][0]['pieces'] ) );
$result = Packer::pack( array(), array(), $sheet );
check( 'nothing asked for: nothing planned', array() === $result['sources'] && 0 === $result['sheets'] && array() === $result['unplaced'] );

echo "10. Sixty invented jobs, every check on each\n";
$seed = 20261112;
$rand = static function ( $min, $max ) use ( &$seed ) {
	$seed = ( $seed * 1103515245 + 12345 ) % 2147483648;
	return $min + ( $seed >> 8 ) % ( $max - $min + 1 );
};
$bad     = array();
$placed  = 0;
$sources = 0;
for ( $job = 0; $job < 60; $job++ ) {
	$rows = array();
	for ( $i = $rand( 1, 7 ); $i > 0; $i-- ) {
		$rows[] = array( $rand( 8, 104 ) / 4, $rand( 8, 80 ) / 4, $rand( 1, 9 ) );
	}
	$left = array();
	for ( $i = $rand( 0, 5 ); $i > 0; $i-- ) {
		$left[] = array( $rand( 16, 240 ) / 4, $rand( 16, 160 ) / 4 );
	}
	$opts = array(
		'sheet_w' => fh_in( 96 ),
		'sheet_h' => fh_in( 48 ),
		'kerf'    => array( 0, 0, fh_in( 0.0625 ), fh_in( 0.125 ), fh_in( 0.3 ) )[ $rand( 0, 4 ) ],
		'rotate'  => 0 !== $rand( 0, 3 ),
		'min_w'   => fh_in( $rand( 0, 12 ) ),
		'min_h'   => fh_in( $rand( 0, 12 ) ),
	);

	$pieces   = fh_pieces( $rows );
	$result   = Packer::pack( $pieces, fh_leftovers( $left ), $opts );
	$problems = fh_audit( $result, $pieces, $opts );
	if ( $problems ) {
		$bad[] = "job $job: " . implode( '; ', array_slice( $problems, 0, 3 ) );
	}
	if ( Packer::pack( $pieces, fh_leftovers( $left ), $opts ) !== $result ) {
		$bad[] = "job $job: a second run differs";
	}
	foreach ( $result['unplaced'] as $piece ) {
		if ( Packer::fits( $piece['w'], $piece['h'], $opts['sheet_w'], $opts['sheet_h'], $opts['rotate'] ) ) {
			$bad[] = "job $job: a piece that fits a sheet was reported as too big";
		}
	}
	foreach ( $result['sources'] as $source ) {
		$placed += count( $source['pieces'] );
		$sources++;
	}
}
check( 'all sixty are sound, repeatable and report only what truly fits nowhere', array() === $bad, implode( "\n       ", array_slice( $bad, 0, 5 ) ) );
check( 'they were real jobs', $placed > 500 && $sources > 100, "$placed pieces on $sources sources" );

echo "11. Hundreds of pieces\n";
$pieces = fh_pieces( array( array( 12, 12, 120 ), array( 12, 11, 120 ), array( 11, 11, 120 ), array( 16, 12, 60 ), array( 16, 9, 60 ), array( 11, 9, 60 ), array( 8, 5, 60 ) ) );
$stock  = fh_leftovers( array( array( 30, 20 ), array( 13, 13 ), array( 50, 9 ), array( 44, 31 ), array( 12, 70 ) ) );
$start  = microtime( true );
$result = Packer::pack( $pieces, $stock, $sheet );
$took   = microtime( true ) - $start;
check( '600 pieces are laid out in under two seconds', $took < 2.0, sprintf( '%.2f s', $took ) );
printf( "       (%.0f ms, %d sheets)\n", $took * 1000, $result['sheets'] );
check_same( 'and the layout is sound', array(), fh_audit( $result, $pieces, $sheet ) );
check( 'all of them are placed', array() === $result['unplaced'] );
$used = 0;
foreach ( $result['sources'] as $source ) {
	$used += $source['pieces_area'];
}
$bound = (int) ceil( ( $used - 30 * 20 * 1e8 - 13 * 13 * 1e8 - 50 * 9 * 1e8 - 44 * 31 * 1e8 - 12 * 70 * 1e8 ) / ( fh_in( 96 ) * fh_in( 48 ) ) );
check( 'within two sheets of the least any layout could take', $result['sheets'] <= $bound + 2, $result['sheets'] . ' sheets, lower bound ' . $bound );
$pieces = fh_pieces( array( array( 10, 10, 100 ), array( 10, 9, 100 ) ) );
$start  = microtime( true );
$result = Packer::pack( $pieces, array(), $sheet );
check( '200 pieces (the full search): well under two seconds, sound', microtime( true ) - $start < 2.0 && array() === fh_audit( $result, $pieces, $sheet ) );

fh_finish();
