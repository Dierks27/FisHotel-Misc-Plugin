<?php
/**
 * SVG — PURE. One source of a plan (a leftover, or a whole sheet) drawn to
 * scale: every piece outlined and labelled (box, piece, size), the cuts as
 * numbered lines in the order they are made, the new leftovers hatched and
 * labelled with what to write on them, the waste cross-hatched, a ruler
 * in inches along the top and the left edge.
 *
 * To scale means ONE number of drawing units per inch for both directions
 * (data-scale on the <svg>); the source's top-left corner sits at
 * (MARGIN_LEFT, MARGIN_TOP). The page scales the whole drawing with CSS.
 *
 * Nothing is loaded from anywhere and every text is escaped for XML, so
 * the drawing can be inlined in the admin page and the printable page.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Svg
 */
class Svg {

	/**
	 * Room for the rulers and a border, in drawing units.
	 */
	const MARGIN_LEFT   = 34;
	const MARGIN_TOP    = 30;
	const MARGIN_RIGHT  = 12;
	const MARGIN_BOTTOM = 12;

	/**
	 * The drawing area a source is fitted into, in drawing units (a whole
	 * 96 × 48 in sheet fills the width).
	 */
	const MAX_WIDTH  = 1000;
	const MAX_HEIGHT = 520;

	/**
	 * The most drawing units an inch is given (a small leftover is not
	 * blown up to fill the page).
	 *
	 * @var int
	 */
	const MAX_SCALE = 16;

	/**
	 * Drawing units per inch for a source.
	 *
	 * @param int|float $length Source length, inches.
	 * @param int|float $width  Source width, inches.
	 * @return float
	 */
	public static function scale( $length, $width ) {
		$length = max( 0.01, (float) $length );
		$width  = max( 0.01, (float) $width );

		return floor( 100 * min( self::MAX_SCALE, self::MAX_WIDTH / $length, self::MAX_HEIGHT / $width ) ) / 100;
	}

	/**
	 * The drawing of one source.
	 *
	 * @param array $source One of a plan's sources (inches).
	 * @return string An <svg> element.
	 */
	public static function source( array $source ) {
		$length = (float) $source['length'];
		$width  = (float) $source['width'];
		$scale  = self::scale( $length, $width );
		$left   = self::MARGIN_LEFT;
		$top    = self::MARGIN_TOP;
		$w      = $length * $scale;
		$h      = $width * $scale;
		$total  = array( $left + $w + self::MARGIN_RIGHT, $top + $h + self::MARGIN_BOTTOM );
		$id     = 'fc' . (int) ( isset( $source['n'] ) ? $source['n'] : 0 );

		$out  = '<svg xmlns="http://www.w3.org/2000/svg" class="fc-drawing" viewBox="0 0 ' . self::n( $total[0] ) . ' ' . self::n( $total[1] ) . '" width="' . self::n( $total[0] ) . '" height="' . self::n( $total[1] ) . '"';
		$out .= ' data-scale="' . self::n( $scale ) . '" data-length="' . self::n( $length ) . '" data-width="' . self::n( $width ) . '" role="img" font-family="Helvetica, Arial, sans-serif">';
		$out .= '<title>' . self::esc( (string) $source['label'] . ' — ' . self::size( $length, $width ) . ' in' ) . '</title>';

		// The two hatchings: leftovers (one way) and waste (both ways).
		$out .= '<defs>';
		$out .= '<pattern id="' . $id . '-keep" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="8" height="8" fill="#eef6fc"/><line x1="0" y1="0" x2="0" y2="8" stroke="#2271b1" stroke-width="1.6"/></pattern>';
		$out .= '<pattern id="' . $id . '-waste" width="6" height="6" patternUnits="userSpaceOnUse"><rect width="6" height="6" fill="#f0f0f0"/><path d="M0 0L6 6M6 0L0 6" stroke="#8c8f94" stroke-width="0.8"/></pattern>';
		$out .= '</defs>';

		$out .= '<rect class="fc-source" x="' . self::n( $left ) . '" y="' . self::n( $top ) . '" width="' . self::n( $w ) . '" height="' . self::n( $h ) . '" fill="#ffffff" stroke="none"/>';

		foreach ( isset( $source['waste'] ) ? (array) $source['waste'] : array() as $rect ) {
			$box  = self::box( $rect, $scale );
			$out .= '<g class="fc-waste"><rect x="' . self::n( $box[0] ) . '" y="' . self::n( $box[1] ) . '" width="' . self::n( $box[2] ) . '" height="' . self::n( $box[3] ) . '" fill="url(#' . $id . '-waste)" stroke="#8c8f94" stroke-width="0.6"/>';
			$out .= self::label( array( __( 'waste', 'fishotel-misc-plugin' ) ), $box, 9, 6, '#50575e', false );
			$out .= '</g>';
		}

		foreach ( isset( $source['leftovers'] ) ? (array) $source['leftovers'] : array() as $rect ) {
			$box  = self::box( $rect, $scale );
			$out .= '<g class="fc-leftover" data-label="' . self::esc( (string) $rect['label'] ) . '"><rect x="' . self::n( $box[0] ) . '" y="' . self::n( $box[1] ) . '" width="' . self::n( $box[2] ) . '" height="' . self::n( $box[3] ) . '" fill="url(#' . $id . '-keep)" stroke="#2271b1" stroke-width="1"/>';
			$out .= '<title>' . self::esc( sprintf( /* translators: 1: leftover label, 2: its size */ __( 'Leftover %1$s — %2$s in', 'fishotel-misc-plugin' ), (string) $rect['label'], self::size( $rect['w'], $rect['h'] ) ) ) . '</title>';
			$out .= self::label( array( (string) $rect['label'], self::size( $rect['w'], $rect['h'] ) ), $box, 16, 6, '#0a4b78', true );
			$out .= '</g>';
		}

		foreach ( isset( $source['pieces'] ) ? (array) $source['pieces'] : array() as $piece ) {
			$box   = self::box( $piece, $scale );
			$size  = self::size( $piece['length'], $piece['width'] );
			$out  .= '<g class="fc-piece" data-n="' . (int) $piece['n'] . '"><rect x="' . self::n( $box[0] ) . '" y="' . self::n( $box[1] ) . '" width="' . self::n( $box[2] ) . '" height="' . self::n( $box[3] ) . '" fill="#ffffff" stroke="#1d2327" stroke-width="1"/>';
			$out  .= '<title>' . self::esc( (string) $piece['box'] . ' · ' . (string) $piece['name'] . ' · ' . $size . ' in' ) . '</title>';
			$out  .= self::label( array( (string) $piece['box'], (string) $piece['name'], $size ), $box, 13, 5, '#1d2327', false );
			$out  .= '</g>';
		}

		$out .= '<rect class="fc-outline" x="' . self::n( $left ) . '" y="' . self::n( $top ) . '" width="' . self::n( $w ) . '" height="' . self::n( $h ) . '" fill="none" stroke="#1d2327" stroke-width="1.6"/>';
		$out .= self::rulers( $length, $width, $scale );

		// The cuts on top: a line (as wide as the blade) and its number.
		$badges = '';
		foreach ( isset( $source['cuts'] ) ? (array) $source['cuts'] : array() as $cut ) {
			$kerf     = isset( $cut['kerf'] ) ? (float) $cut['kerf'] : 0.0;
			$vertical = 'v' === $cut['axis'];
			$x1       = $left + ( (float) $cut['x1'] + ( $vertical ? $kerf / 2 : 0 ) ) * $scale;
			$y1       = $top + ( (float) $cut['y1'] + ( $vertical ? 0 : $kerf / 2 ) ) * $scale;
			$x2       = $left + ( (float) $cut['x2'] + ( $vertical ? $kerf / 2 : 0 ) ) * $scale;
			$y2       = $top + ( (float) $cut['y2'] + ( $vertical ? 0 : $kerf / 2 ) ) * $scale;
			$stroke   = max( 1.4, $kerf * $scale );
			$n        = (int) $cut['n'];
			$radius   = $n > 99 ? 9 : 7.5;

			$out    .= '<line class="fc-cut" data-n="' . $n . '" x1="' . self::n( $x1 ) . '" y1="' . self::n( $y1 ) . '" x2="' . self::n( $x2 ) . '" y2="' . self::n( $y2 ) . '" stroke="#d63638" stroke-width="' . self::n( $stroke ) . '" stroke-dasharray="6 3"/>';
			$badges .= '<g class="fc-cut-number" data-n="' . $n . '"><circle cx="' . self::n( ( $x1 + $x2 ) / 2 ) . '" cy="' . self::n( ( $y1 + $y2 ) / 2 ) . '" r="' . self::n( $radius ) . '" fill="#d63638" stroke="#ffffff" stroke-width="1"/>';
			$badges .= '<text x="' . self::n( ( $x1 + $x2 ) / 2 ) . '" y="' . self::n( ( $y1 + $y2 ) / 2 + 3 ) . '" font-size="' . ( $n > 99 ? '7.5' : '8.5' ) . '" font-weight="700" fill="#ffffff" text-anchor="middle">' . $n . '</text></g>';
		}

		return $out . $badges . '</svg>';
	}

	/**
	 * A rectangle of the plan (inches) as drawing units: [ x, y, w, h ].
	 *
	 * @param array $rect  { x, y, w, h } in inches.
	 * @param float $scale Drawing units per inch.
	 * @return float[]
	 */
	private static function box( array $rect, $scale ) {
		return array(
			self::MARGIN_LEFT + (float) $rect['x'] * $scale,
			self::MARGIN_TOP + (float) $rect['y'] * $scale,
			(float) $rect['w'] * $scale,
			(float) $rect['h'] * $scale,
		);
	}

	/**
	 * Lines of text centred in a rectangle, as large as fits: upright or
	 * turned a quarter. When the rectangle is too small for all the lines,
	 * lines are dropped one by one — from the top for a piece (its size is
	 * the last line and stays longest), from the bottom for a leftover (its
	 * label is the first line). Nothing is drawn when not even one line
	 * fits — the element's <title> and the list under the drawing still
	 * say it all.
	 *
	 * @param string[] $lines Lines.
	 * @param float[]  $box   [ x, y, w, h ] in drawing units.
	 * @param float    $max   Largest font size.
	 * @param float    $min   Smallest font size.
	 * @param string   $fill  Text colour.
	 * @param bool     $head  Whether the first line is the one that matters
	 *                        most (it is bold and dropped last).
	 * @return string
	 */
	private static function label( array $lines, array $box, $max, $min, $fill, $head ) {
		$lines = array_values( array_filter( array_map( 'strval', $lines ), 'strlen' ) );

		while ( $lines ) {
			$longest = 1;
			foreach ( $lines as $line ) {
				$longest = max( $longest, function_exists( 'mb_strlen' ) ? mb_strlen( $line, 'UTF-8' ) : strlen( $line ) );
			}
			$count = count( $lines );

			// The font size that fits, upright and turned.
			$upright = min( $max, ( $box[2] - 4 ) / ( 0.58 * $longest ), ( $box[3] - 3 ) / ( 1.2 * $count ) );
			$turned  = min( $max, ( $box[3] - 4 ) / ( 0.58 * $longest ), ( $box[2] - 3 ) / ( 1.2 * $count ) );
			$size    = max( $upright, $turned );

			if ( $size >= $min ) {
				$size   = floor( $size * 10 ) / 10;
				$rotate = $turned > $upright;
				$cx     = $box[0] + $box[2] / 2;
				$cy     = $box[1] + $box[3] / 2;
				$first  = $cy - ( $count - 1 ) * 0.6 * $size + 0.35 * $size;

				$out = '<text font-size="' . self::n( $size ) . '" fill="' . $fill . '" text-anchor="middle"' . ( $rotate ? ' transform="rotate(-90 ' . self::n( $cx ) . ' ' . self::n( $cy ) . ')"' : '' ) . '>';
				foreach ( $lines as $i => $line ) {
					$out .= '<tspan x="' . self::n( $cx ) . '" y="' . self::n( $first + $i * 1.2 * $size ) . '"' . ( $head && 0 === $i ? ' font-weight="700"' : '' ) . '>' . self::esc( $line ) . '</tspan>';
				}
				return $out . '</text>';
			}

			if ( $head ) {
				array_pop( $lines );
			} else {
				array_shift( $lines );
			}
		}

		return '';
	}

	/**
	 * The inch rulers along the top and the left edge.
	 *
	 * @param float $length Source length, inches.
	 * @param float $width  Source width, inches.
	 * @param float $scale  Drawing units per inch.
	 * @return string
	 */
	private static function rulers( $length, $width, $scale ) {
		$left = self::MARGIN_LEFT;
		$top  = self::MARGIN_TOP;
		$out  = '<g class="fc-ruler" stroke="#50575e" stroke-width="0.7" font-size="9" fill="#50575e">';

		foreach ( array( array( $length, true ), array( $width, false ) ) as $axis ) {
			$span   = $axis[0];
			$across = $axis[1];
			$number = $span > 48 ? 12 : ( $span > 20 ? 6 : ( $span > 8 ? 2 : 1 ) );
			$step   = $scale >= 4 ? 1 : 6;

			for ( $inch = 0; $inch <= floor( $span + 0.0001 ); $inch += $step ) {
				$tick = 0 === $inch % 12 ? 9 : ( 0 === $inch % 6 ? 6 : 3 );
				$at   = $inch * $scale;

				if ( $across ) {
					$out .= '<line x1="' . self::n( $left + $at ) . '" y1="' . self::n( $top - $tick ) . '" x2="' . self::n( $left + $at ) . '" y2="' . self::n( $top ) . '"/>';
				} else {
					$out .= '<line x1="' . self::n( $left - $tick ) . '" y1="' . self::n( $top + $at ) . '" x2="' . self::n( $left ) . '" y2="' . self::n( $top + $at ) . '"/>';
				}

				if ( 0 === $inch % $number ) {
					$out .= $across
						? '<text x="' . self::n( $left + $at ) . '" y="' . self::n( $top - 12 ) . '" text-anchor="middle" stroke="none">' . $inch . '</text>'
						: '<text x="' . self::n( $left - 12 ) . '" y="' . self::n( $top + $at + 3 ) . '" text-anchor="end" stroke="none">' . $inch . '</text>';
				}
			}
		}

		return $out . '</g>';
	}

	/**
	 * Two sizes in inches as people say them: "16 × 9 1/2".
	 *
	 * @param int|float $a First size, inches.
	 * @param int|float $b Second size, inches.
	 * @return string
	 */
	public static function size( $a, $b ) {
		return Units::size( (int) Units::from_inches( $a ), (int) Units::from_inches( $b ) );
	}

	/**
	 * A number for an attribute: at most two decimals, no trailing zeros.
	 *
	 * @param int|float $value Value.
	 * @return string
	 */
	public static function n( $value ) {
		$text = number_format( (float) $value, 2, '.', '' );
		$text = rtrim( rtrim( $text, '0' ), '.' );
		return '' === $text || '-0' === $text ? '0' : $text;
	}

	/**
	 * Text escaped for XML.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
