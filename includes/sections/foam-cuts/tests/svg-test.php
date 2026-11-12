<?php
/**
 * SVG — the drawing of one source: well-formed XML, to scale (one number
 * of drawing units per inch, both ways, for every rectangle and every
 * cut), every piece labelled with its box, name and size, the cuts
 * numbered in the order they are made, the leftovers hatched and
 * labelled, the waste cross-hatched, texts escaped, nothing loaded from
 * anywhere.
 *
 * Run from the plugin root:  php includes/sections/foam-cuts/tests/svg-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Foam_Cuts\Cut_List;
use FisHotel\Misc\Sections\Foam_Cuts\Plan;
use FisHotel\Misc\Sections\Foam_Cuts\Svg;

/**
 * Parse an SVG string; null when it is not well-formed XML.
 *
 * @return array[]|null Elements in document order: tag, attributes, value, level, type.
 */
function fh_parse_svg( $svg ) {
	$parser = xml_parser_create( 'UTF-8' );
	xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, 0 );
	xml_parser_set_option( $parser, XML_OPTION_SKIP_WHITE, 1 );
	$values = array();
	$ok     = xml_parse_into_struct( $parser, $svg, $values );
	$error  = xml_get_error_code( $parser );
	xml_parser_free( $parser );
	return 1 === $ok && 0 === $error ? $values : null;
}

/**
 * The elements of a parsed SVG grouped by the class of the <g> (or the
 * element) they belong to: class → list of groups, each a list of elements.
 */
function fh_groups( array $elements ) {
	$out   = array();
	$stack = array();
	foreach ( $elements as $el ) {
		$class = isset( $el['attributes']['class'] ) ? $el['attributes']['class'] : '';
		if ( 'g' === $el['tag'] && 'open' === $el['type'] ) {
			$stack[]         = $class;
			$out[ $class ][] = array( $el );
			continue;
		}
		if ( 'g' === $el['tag'] && 'close' === $el['type'] ) {
			array_pop( $stack );
			continue;
		}
		if ( $stack ) {
			$group = end( $stack );
			$out[ $group ][ count( $out[ $group ] ) - 1 ][] = $el;
		} elseif ( '' !== $class ) {
			$out[ $class ][] = array( $el );
		}
	}
	return $out;
}

function fh_plan_for_svg( $kerf = 0, $boxes = null ) {
	$settings = array(
		'sheet_length' => fh_in( 96 ),
		'sheet_width'  => fh_in( 48 ),
		'thickness'    => fh_in( 0.5 ),
		'kerf'         => fh_in( $kerf ),
		'rotate'       => true,
		'min_length'   => fh_in( 4 ),
		'min_width'    => fh_in( 4 ),
	);
	$rows     = array();
	foreach ( null === $boxes ? array( array( 'Cube', 12, 12, 12, 3 ), array( 'Flat', 16, 12, 10, 2 ), array( 'Tiny', 6, 5, 4, 2 ) ) : $boxes as $box ) {
		$rows[] = array(
			'key'      => strtolower( $box[0] ),
			'name'     => $box[0],
			'length'   => fh_in( $box[1] ),
			'width'    => fh_in( $box[2] ),
			'height'   => fh_in( $box[3] ),
			'count'    => $box[4],
			'cut_list' => 'suggested',
			'pieces'   => Cut_List::suggest( fh_in( $box[1] ), fh_in( $box[2] ), fh_in( $box[3] ), fh_in( 0.5 ) )['pieces'],
		);
	}
	$stock = array(
		'sheets'    => 2,
		'leftovers' => array(
			array( 'id' => 1, 'label' => 'L1', 'length' => fh_in( 30 ), 'width' => fh_in( 20 ) ),
			array( 'id' => 2, 'label' => 'L2', 'length' => fh_in( 13 ), 'width' => fh_in( 13 ) ),
		),
	);
	return Plan::build( $rows, $stock, $settings, 3 );
}

echo "1. The scale\n";
check_same( 'a whole 96 × 48 in sheet fills the width: 10.41 units per inch', 10.41, Svg::scale( 96, 48 ) );
check_same( 'a small leftover is not blown up: capped at 16', 16.0, Svg::scale( 13, 13 ) );
check_same( 'a tall narrow leftover is fitted by its height', 8.66, Svg::scale( 12, 60 ) );
check_same( 'numbers are written short', array( '10.41', '12', '0.5', '0', '1046.36' ), array( Svg::n( 10.41 ), Svg::n( 12.0 ), Svg::n( 0.5 ), Svg::n( -0.001 ), Svg::n( 1046.36 ) ) );

$plan = fh_plan_for_svg();
check_same( 'the plan to draw has no errors', array(), $plan['errors'] );
check( 'and uses leftovers and a sheet', count( $plan['sources'] ) >= 3 );

echo "2. Every source: well-formed, to scale\n";
$well      = true;
$scaled    = true;
$inside    = true;
$all_cuts  = true;
$all_named = true;
$detail    = '';
foreach ( $plan['sources'] as $source ) {
	$svg      = Svg::source( $source );
	$elements = fh_parse_svg( $svg );
	if ( null === $elements ) {
		$well = false;
		continue;
	}

	$root  = $elements[0];
	$scale = (float) $root['attributes']['data-scale'];
	$view  = array_map( 'floatval', explode( ' ', $root['attributes']['viewBox'] ) );

	$scaled = $scaled && 'svg' === $root['tag'] && abs( $scale - Svg::scale( $source['length'], $source['width'] ) ) < 1e-9
		&& abs( $view[2] - ( Svg::MARGIN_LEFT + $source['length'] * $scale + Svg::MARGIN_RIGHT ) ) < 0.011
		&& abs( $view[3] - ( Svg::MARGIN_TOP + $source['width'] * $scale + Svg::MARGIN_BOTTOM ) ) < 0.011
		&& (float) $root['attributes']['width'] === $view[2] && (float) $root['attributes']['height'] === $view[3]
		&& (float) $root['attributes']['data-length'] === (float) $source['length'];

	$groups = fh_groups( $elements );

	// The source's own rectangle.
	$outline = $groups['fc-outline'][0][0]['attributes'];
	$scaled  = $scaled && abs( (float) $outline['width'] - $source['length'] * $scale ) < 0.011 && abs( (float) $outline['height'] - $source['width'] * $scale ) < 0.011
		&& (float) $outline['x'] === (float) Svg::MARGIN_LEFT && (float) $outline['y'] === (float) Svg::MARGIN_TOP;

	// Pieces, leftovers and waste: the same scale across and down.
	$sets = array(
		'fc-piece'    => $source['pieces'],
		'fc-leftover' => $source['leftovers'],
		'fc-waste'    => $source['waste'],
	);
	foreach ( $sets as $class => $rects ) {
		$drawn = isset( $groups[ $class ] ) ? $groups[ $class ] : array();
		if ( count( $drawn ) !== count( $rects ) ) {
			$scaled = false;
			$detail = "$class: " . count( $drawn ) . ' drawn, ' . count( $rects ) . ' in the plan';
			continue;
		}
		foreach ( $rects as $i => $rect ) {
			$a = null;
			foreach ( $drawn[ $i ] as $el ) {
				if ( 'rect' === $el['tag'] ) {
					$a = $el['attributes'];
				}
			}
			$ok = $a
				&& abs( (float) $a['x'] - ( Svg::MARGIN_LEFT + $rect['x'] * $scale ) ) < 0.011
				&& abs( (float) $a['y'] - ( Svg::MARGIN_TOP + $rect['y'] * $scale ) ) < 0.011
				&& abs( (float) $a['width'] - $rect['w'] * $scale ) < 0.011
				&& abs( (float) $a['height'] - $rect['h'] * $scale ) < 0.011;
			$scaled = $scaled && $ok;
			$inside = $inside && $a && (float) $a['x'] + (float) $a['width'] <= $view[2] - Svg::MARGIN_RIGHT + 0.02 && (float) $a['y'] + (float) $a['height'] <= $view[3] - Svg::MARGIN_BOTTOM + 0.02;

			if ( 'fc-piece' === $class ) {
				$title = '';
				foreach ( $drawn[ $i ] as $el ) {
					if ( 'title' === $el['tag'] ) {
						$title = (string) $el['value'];
					}
				}
				$all_named = $all_named && $rect['box'] . ' · ' . $rect['name'] . ' · ' . Svg::size( $rect['length'], $rect['width'] ) . ' in' === $title
					&& (int) $drawn[ $i ][0]['attributes']['data-n'] === $rect['n'];
			}
		}
	}

	// The cuts: a line each, in order, where the plan says, numbered.
	$lines  = isset( $groups['fc-cut'] ) ? $groups['fc-cut'] : array();
	$badges = isset( $groups['fc-cut-number'] ) ? $groups['fc-cut-number'] : array();
	if ( count( $lines ) !== count( $source['cuts'] ) || count( $badges ) !== count( $source['cuts'] ) ) {
		$all_cuts = false;
	} else {
		foreach ( $source['cuts'] as $i => $cut ) {
			$a      = $lines[ $i ][0]['attributes'];
			$number = '';
			foreach ( $badges[ $i ] as $el ) {
				if ( 'text' === $el['tag'] ) {
					$number = (string) $el['value'];
				}
			}
			$all_cuts = $all_cuts
				&& (int) $a['data-n'] === $i + 1 && (string) ( $i + 1 ) === $number && $cut['n'] === $i + 1
				&& abs( (float) $a['x1'] - ( Svg::MARGIN_LEFT + $cut['x1'] * $scale ) ) < 0.011
				&& abs( (float) $a['y1'] - ( Svg::MARGIN_TOP + $cut['y1'] * $scale ) ) < 0.011
				&& abs( (float) $a['x2'] - ( Svg::MARGIN_LEFT + $cut['x2'] * $scale ) ) < 0.011
				&& abs( (float) $a['y2'] - ( Svg::MARGIN_TOP + $cut['y2'] * $scale ) ) < 0.011
				&& ( 'v' === $cut['axis'] ? $a['x1'] === $a['x2'] : $a['y1'] === $a['y2'] );
		}
	}
}
check( 'each drawing is well-formed XML', $well );
check( 'the drawing area is the source at one scale, plus the margins for the rulers', $scaled, $detail );
check( 'every piece, leftover and waste rectangle is drawn where the plan puts it, at that same scale across and down', $scaled );
check( 'nothing is drawn outside the source', $inside );
check( 'every piece carries its number and, in full, its box, its name and its size', $all_named );
check( 'every cut is a line where the plan says, top to bottom or left to right, numbered 1, 2, 3 … in the order they are made', $all_cuts );

echo "3. What is written on the drawing\n";
$sheet = null;
foreach ( $plan['sources'] as $source ) {
	if ( 'sheet' === $source['kind'] ) {
		$sheet = $source;
	}
}
$svg      = Svg::source( $sheet );
$elements = fh_parse_svg( $svg );
$groups   = fh_groups( $elements );
$texts    = static function ( array $group ) {
	$out = array();
	foreach ( $group as $el ) {
		if ( 'tspan' === $el['tag'] && isset( $el['value'] ) ) {
			$out[] = $el['value'];
		}
	}
	return $out;
};
$big = null;
foreach ( $sheet['pieces'] as $i => $piece ) {
	if ( 'Flat' === $piece['box'] && 'Top / bottom' === $piece['name'] ) {
		$big = $i;
	}
}
check_same( 'a piece with room shows three lines: box, piece, size', array( 'Flat', 'Top / bottom', '16 × 12' ), $texts( $groups['fc-piece'][ $big ] ) );
$shown = 0;
foreach ( $sheet['pieces'] as $i => $piece ) {
	$lines = $texts( $groups['fc-piece'][ $i ] );
	$shown += $lines ? 1 : 0;
	if ( $lines && end( $lines ) !== Svg::size( $piece['length'], $piece['width'] ) ) {
		$shown = -999;
	}
}
check( 'when a piece is too small for all three, the size is the line that stays', $shown === count( $sheet['pieces'] ) || $shown > 0 );
check( 'a leftover is hatched and shows its label first, then its size', $sheet['leftovers'] && 0 === strpos( $groups['fc-leftover'][0][0]['attributes']['data-label'], 'L' ) && $sheet['leftovers'][0]['label'] === $texts( $groups['fc-leftover'][0] )[0] );
$fills = array();
foreach ( $groups['fc-leftover'] as $group ) {
	foreach ( $group as $el ) {
		if ( 'rect' === $el['tag'] ) {
			$fills[ $el['attributes']['fill'] ] = true;
		}
	}
}
check_same( 'the leftover hatching is a pattern of this drawing', array( 'url(#fc' . $sheet['n'] . '-keep)' ), array_keys( $fills ) );
check( 'the two hatchings are defined once each: leftovers one way, waste both ways', 1 === substr_count( $svg, '<pattern id="fc' . $sheet['n'] . '-keep"' ) && 1 === substr_count( $svg, '<pattern id="fc' . $sheet['n'] . '-waste"' ) );
$other = Svg::source( $plan['sources'][0] );
check( 'two drawings on one page do not share an id', false === strpos( $other, 'id="fc' . $sheet['n'] . '-keep"' ) && false !== strpos( $other, 'id="fc' . $plan['sources'][0]['n'] . '-keep"' ) );
check( 'the ruler counts inches from the top-left corner: 0, 12 … 96 along the top', 1 === preg_match( '/<g class="fc-ruler".*>0<\/text>.*>12<\/text>.*>96<\/text>/s', $svg ) );
check( 'the drawing names its source in its title', 1 === preg_match( '/<title>new sheet 1 — 96 × 48 in<\/title>/u', $svg ) );
check( 'cut lines are drawn over the pieces, their numbers over everything', strrpos( $svg, 'class="fc-piece"' ) < strpos( $svg, 'class="fc-cut"' ) && strrpos( $svg, 'class="fc-cut"' ) < strpos( $svg, 'class="fc-cut-number"' ) );

echo "4. A blade with a width\n";
$kerf  = fh_plan_for_svg( 0.25 );
$sheet = null;
foreach ( $kerf['sources'] as $source ) {
	if ( 'sheet' === $source['kind'] ) {
		$sheet = $source;
	}
}
$svg      = Svg::source( $sheet );
$elements = fh_parse_svg( $svg );
$groups   = fh_groups( $elements );
$scale    = Svg::scale( 96, 48 );
$first    = $groups['fc-cut'][0][0]['attributes'];
$cut      = $sheet['cuts'][0];
check( 'well-formed', null !== $elements );
check( 'the cut line is as wide as the blade (0.25 in at scale)', abs( (float) $first['stroke-width'] - 0.25 * $scale ) < 0.011 );
check( '…and runs down the middle of what the blade takes', abs( (float) $first[ 'v' === $cut['axis'] ? 'x1' : 'y1' ] - ( ( 'v' === $cut['axis'] ? Svg::MARGIN_LEFT + $cut['x1'] * $scale : Svg::MARGIN_TOP + $cut['y1'] * $scale ) + 0.125 * $scale ) ) < 0.011 );

echo "5. Names that need escaping, pieces too small to write on\n";
$odd = fh_plan_for_svg( 0, array( array( 'Fish & <Chips> "XL"', 14, 10, 8, 1 ), array( 'Speck', 2, 1.5, 1.25, 1 ) ) );
check_same( 'the plan has no errors', array(), $odd['errors'] );
$ok       = true;
$titles   = 0;
$unnamed  = 0;
$raw      = false;
foreach ( $odd['sources'] as $source ) {
	$svg      = Svg::source( $source );
	$elements = fh_parse_svg( $svg );
	$ok       = $ok && null !== $elements;
	$raw      = $raw || false !== strpos( $svg, '<Chips>' ) || false !== strpos( $svg, '"XL"<' );
	if ( null === $elements ) {
		continue;
	}
	$groups = fh_groups( $elements );
	foreach ( $source['pieces'] as $i => $piece ) {
		$has_text = false;
		foreach ( $groups['fc-piece'][ $i ] as $el ) {
			if ( 'title' === $el['tag'] && false !== strpos( (string) $el['value'], $piece['box'] ) ) {
				$titles++;
			}
			$has_text = $has_text || 'tspan' === $el['tag'];
		}
		$unnamed += $has_text ? 0 : 1;
	}
}
check( 'still well-formed with &, <, > and quotes in a box name', $ok );
check( 'the name is never written raw', false === $raw );
check( 'and comes back out of the XML as it was typed', $titles > 0 && $titles === array_sum( array_map( static function ( $s ) { return count( $s['pieces'] ); }, $odd['sources'] ) ) );
check( 'a piece too small for any text has none — its title still says what it is', $unnamed >= 1 );
check_same( 'escaping', 'A &amp; B &lt;c&gt; &quot;d&quot; &apos;e&apos;', Svg::esc( 'A & B <c> "d" \'e\'' ) );

echo "6. Self-contained\n";
$all = '';
foreach ( array_merge( $plan['sources'], $kerf['sources'], $odd['sources'] ) as $source ) {
	$all .= Svg::source( $source );
}
check( 'no script, no style sheet, no image, no foreign object', 0 === preg_match( '/<(script|style|image|foreignObject|use|iframe)\b/i', $all ) );
check( 'no link out: the only URL is the SVG namespace, the only url() a pattern of the drawing itself', 0 === preg_match( '/(href|src)\s*=/i', $all ) && 0 === preg_match( '/url\((?!#fc\d+-(keep|waste)\))/', $all ) && 0 === preg_match( '#https?://(?!www\.w3\.org/2000/svg")#', $all ) );
check( 'no event handler attributes', 0 === preg_match( '/\son[a-z]+\s*=/i', $all ) );
check( 'the same source drawn twice is the same drawing', Svg::source( $plan['sources'][0] ) === Svg::source( $plan['sources'][0] ) );

fh_finish();
