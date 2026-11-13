<?php
/**
 * Cut list — PURE. The pieces of foam one box takes.
 *
 * A box is lined on all six sides. From its INSIDE dimensions L × W × H
 * and the foam thickness t the six pieces that fit together without gaps
 * are suggested:
 *
 *   top and bottom   L × W              (2) — they cover the whole footprint
 *   long sides       L × (H − 2t)       (2) — they stand between top and bottom
 *   short sides      (W − 2t) × (H − 2t)(2) — they fit between the long sides
 *
 * L is taken as the longer of the two footprint sizes, so "long sides"
 * are the long ones however the box was typed in. The suggestion is only
 * a starting point: the owner saves the list the way the pieces are
 * really cut (a name, a size and a count per piece), and that saved list
 * is what a plan uses from then on.
 *
 * Every size here is in units (Units::PER_INCH per inch).
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cut_List
 */
class Cut_List {

	/**
	 * The most rows a saved cut list keeps.
	 *
	 * @var int
	 */
	const MAX_ROWS = 12;

	/**
	 * The most copies of one piece a box takes.
	 *
	 * @var int
	 */
	const MAX_COUNT = 24;

	/**
	 * The longest piece name kept.
	 *
	 * @var int
	 */
	const NAME_LENGTH = 40;

	/**
	 * The six suggested pieces for a box.
	 *
	 * @param int $length    Inside length, units.
	 * @param int $width     Inside width, units.
	 * @param int $height    Inside height, units.
	 * @param int $thickness Foam thickness, units.
	 * @return array{pieces: array<int, array{name: string, length: int, width: int, count: int}>, notes: string[]}
	 *         notes says why a piece is missing (foam too thick for the box).
	 */
	public static function suggest( $length, $width, $height, $thickness ) {
		$a = max( (int) $length, (int) $width );
		$b = min( (int) $length, (int) $width );
		$h = (int) $height;
		$t = max( 0, (int) $thickness );

		$out = array(
			'pieces' => array(),
			'notes'  => array(),
		);

		if ( $a <= 0 || $b <= 0 || $h <= 0 ) {
			$out['notes'][] = __( 'The box has no size, so there is nothing to suggest.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['pieces'][] = array(
			'name'   => __( 'Top / bottom', 'fishotel-misc-plugin' ),
			'length' => $a,
			'width'  => $b,
			'count'  => 2,
		);

		$side_height = $h - 2 * $t;
		$short_width = $b - 2 * $t;

		if ( $side_height <= 0 ) {
			$out['notes'][] = __( 'The foam is too thick for this box: top and bottom leave no room for the sides.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['pieces'][] = array(
			'name'   => __( 'Long side', 'fishotel-misc-plugin' ),
			'length' => $a,
			'width'  => $side_height,
			'count'  => 2,
		);

		if ( $short_width <= 0 ) {
			$out['notes'][] = __( 'The foam is too thick for this box: the long sides leave no room for the short sides.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['pieces'][] = array(
			'name'   => __( 'Short side', 'fishotel-misc-plugin' ),
			'length' => $short_width,
			'width'  => $side_height,
			'count'  => 2,
		);

		return $out;
	}

	/**
	 * Clean a list of pieces (from a form in inches, or a stored list in
	 * units): a row needs a size above 0 in both directions and a count of
	 * 1 or more; a row without a name gets "Piece N". Anything else is
	 * dropped.
	 *
	 * @param mixed $rows   [ { name, length, width, count } ].
	 * @param bool  $inches True when length / width are typed inches (parsed
	 *                      by Units::parse), false when they are units.
	 * @return array<int, array{name: string, length: int, width: int, count: int}>
	 */
	public static function sanitize( $rows, $inches = false ) {
		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX_ROWS ) {
				continue;
			}

			$length = isset( $row['length'] ) ? ( $inches ? Units::parse( is_scalar( $row['length'] ) ? (string) $row['length'] : '' ) : (int) $row['length'] ) : null;
			$width  = isset( $row['width'] ) ? ( $inches ? Units::parse( is_scalar( $row['width'] ) ? (string) $row['width'] : '' ) : (int) $row['width'] ) : null;
			$count  = isset( $row['count'] ) && is_scalar( $row['count'] ) ? (int) $row['count'] : 0;

			if ( null === $length || null === $width || $length <= 0 || $width <= 0 || $count < 1 ) {
				continue;
			}

			$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? self::clean_name( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				/* translators: %d: the piece's row number */
				$name = sprintf( __( 'Piece %d', 'fishotel-misc-plugin' ), count( $out ) + 1 );
			}

			$out[] = array(
				'name'   => $name,
				'length' => $length,
				'width'  => $width,
				'count'  => min( self::MAX_COUNT, $count ),
			);
		}

		return $out;
	}

	/**
	 * The cut list a plan uses for a box: the saved one when there is one,
	 * else the suggestion.
	 *
	 * @param array      $box       { length, width, height } in units.
	 * @param array|null $saved     The saved entry { pieces, box: "LxWxH" in units } or null.
	 * @param int        $thickness Foam thickness, units.
	 * @return array{pieces: array[], source: string, changed: bool, notes: string[]}
	 *         source is 'saved' or 'suggested'; changed is true when the box
	 *         was a different size when the list was saved.
	 */
	public static function for_box( array $box, $saved, $thickness ) {
		$length = isset( $box['length'] ) ? (int) $box['length'] : 0;
		$width  = isset( $box['width'] ) ? (int) $box['width'] : 0;
		$height = isset( $box['height'] ) ? (int) $box['height'] : 0;

		if ( is_array( $saved ) && ! empty( $saved['pieces'] ) ) {
			$pieces = self::sanitize( $saved['pieces'] );
			if ( $pieces ) {
				return array(
					'pieces'  => $pieces,
					'source'  => 'saved',
					'changed' => isset( $saved['box'] ) && self::box_signature( $length, $width, $height ) !== (string) $saved['box'],
					'notes'   => array(),
				);
			}
		}

		$suggested = self::suggest( $length, $width, $height, $thickness );

		return array(
			'pieces'  => $suggested['pieces'],
			'source'  => 'suggested',
			'changed' => false,
			'notes'   => $suggested['notes'],
		);
	}

	/**
	 * "LxWxH" in units — what a saved cut list remembers about its box, so
	 * a box that changed size since can be pointed out.
	 *
	 * @param int $length Units.
	 * @param int $width  Units.
	 * @param int $height Units.
	 * @return string
	 */
	public static function box_signature( $length, $width, $height ) {
		return (int) $length . 'x' . (int) $width . 'x' . (int) $height;
	}

	/**
	 * How many pieces one box takes.
	 *
	 * @param array $pieces Cut list.
	 * @return int
	 */
	public static function piece_count( array $pieces ) {
		$n = 0;
		foreach ( $pieces as $piece ) {
			$n += isset( $piece['count'] ) ? max( 0, (int) $piece['count'] ) : 0;
		}
		return $n;
	}

	/**
	 * The foam one box takes, in square units.
	 *
	 * @param array $pieces Cut list.
	 * @return int
	 */
	public static function area( array $pieces ) {
		$area = 0;
		foreach ( $pieces as $piece ) {
			$area += (int) $piece['length'] * (int) $piece['width'] * max( 0, (int) $piece['count'] );
		}
		return $area;
	}

	/**
	 * A name as stored: one line, no tags, at most NAME_LENGTH characters.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $name ) ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, self::NAME_LENGTH, 'UTF-8' );
		}

		return substr( $name, 0, self::NAME_LENGTH );
	}
}
