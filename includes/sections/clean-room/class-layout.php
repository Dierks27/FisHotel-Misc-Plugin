<?php
/**
 * Clean Room layout — PURE: the numbering rule, the seed layout, the
 * shares-water lists, the title / label text and the grouping the board
 * and the phone screen render. No WordPress, no database.
 *
 * Numbering rule: hundreds = building, tens = level (1 bottom, 2 top),
 * ones = position (1 = leftmost). Tank 121 is building 100, top row, the
 * leftmost tank. Every building is ONE shared water system (sump +
 * skimmer): nothing extra is stored, but every tank title says which
 * tanks it shares water with.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Layout
 */
class Layout {

	/**
	 * Areas and their display names.
	 *
	 * @var array<string, string>
	 */
	const AREAS = array(
		'clean' => 'Clean Room',
		'spa'   => 'Spa',
	);

	/**
	 * Levels: 1 = bottom row, 2 = top row.
	 *
	 * @var array<int, string>
	 */
	const LEVELS = array(
		1 => 'bottom',
		2 => 'top',
	);

	const BUILDING_STATUSES = array( 'active', 'coming_soon', 'offline' );
	const TANK_STATUSES     = array( 'active', 'offline' );

	const MAX_POSITION = 9;
	const MAX_BUILDING = 900;

	/**
	 * The seed layout — inserted ONLY when the buildings table is empty.
	 *
	 * @return array[] Buildings with nested tanks (numbers derived by the rule).
	 */
	public static function seed() {
		$long    = array( '20G long', 20 );
		$breeder = array( '40G breeder', 40 );

		$buildings = array(
			array( 'clean', 100, 'active', array( 2 => array( $long, $long, $long ), 1 => array( $breeder, $breeder ) ) ),
			array( 'clean', 200, 'active', array( 2 => array( $long, $long ), 1 => array( $breeder, $breeder ) ) ),
			array( 'clean', 300, 'active', array( 2 => array( $long, $long ), 1 => array( $breeder, $breeder ) ) ),
			array( 'clean', 400, 'coming_soon', array() ),
			array( 'spa', 500, 'active', array( 2 => array( $long, $long, $long, $long ), 1 => array( $breeder, $breeder, $breeder ) ) ),
			array( 'spa', 600, 'active', array( 2 => array( $breeder ), 1 => array( $breeder ) ) ),
		);

		$out  = array();
		$sort = 0;
		foreach ( $buildings as $def ) {
			list( $area, $number, $status, $rows ) = $def;
			$sort++;

			$tanks = array();
			$tsort = 0;
			foreach ( array( 2, 1 ) as $level ) {
				$position = 0;
				foreach ( isset( $rows[ $level ] ) ? $rows[ $level ] : array() as $size ) {
					$position++;
					$tsort++;
					$tanks[] = array(
						'number'     => self::tank_number( $number, $level, $position ),
						'level'      => $level,
						'position'   => $position,
						'size_label' => $size[0],
						'gallons'    => $size[1],
						'status'     => 'active',
						'sort'       => $tsort,
					);
				}
			}

			$out[] = array(
				'area'   => $area,
				'number' => $number,
				'name'   => '',
				'status' => $status,
				'sort'   => $sort,
				'notes'  => '',
				'tanks'  => $tanks,
			);
		}

		return $out;
	}

	/* ============================================================== */
	/*  The numbering rule                                             */
	/* ============================================================== */

	/**
	 * The tank number for a building / level / position.
	 *
	 * @param int $building_number Building number (100, 200, …).
	 * @param int $level           1 bottom, 2 top.
	 * @param int $position        1 = leftmost.
	 * @return int
	 */
	public static function tank_number( $building_number, $level, $position ) {
		return (int) ( floor( (int) $building_number / 100 ) * 100 ) + (int) $level * 10 + (int) $position;
	}

	/**
	 * Split a tank number into its parts, or null when it breaks the rule.
	 *
	 * @param mixed $number Tank number.
	 * @return array{building: int, level: int, position: int}|null
	 */
	public static function parse_number( $number ) {
		if ( ! is_scalar( $number ) || ! is_numeric( $number ) ) {
			return null;
		}

		$number = (int) $number;
		if ( $number < 111 || $number > 999 ) {
			return null;
		}

		$building = (int) floor( $number / 100 ) * 100;
		$level    = (int) floor( ( $number % 100 ) / 10 );
		$position = $number % 10;

		if ( ! isset( self::LEVELS[ $level ] ) || $position < 1 ) {
			return null;
		}

		return array(
			'building' => $building,
			'level'    => $level,
			'position' => $position,
		);
	}

	/**
	 * Whether a tank number obeys the rule for its building.
	 *
	 * @param int $number          Tank number.
	 * @param int $building_number Building number.
	 * @return bool
	 */
	public static function number_fits( $number, $building_number ) {
		$parts = self::parse_number( $number );
		return null !== $parts && $parts['building'] === (int) ( floor( (int) $building_number / 100 ) * 100 );
	}

	/**
	 * Validate a building before it is saved. Returns a list of problems
	 * (empty = fine).
	 *
	 * @param array $building area, number, name, status.
	 * @param int[] $existing Numbers already taken (excluding this row).
	 * @return string[]
	 */
	public static function validate_building( array $building, array $existing = array() ) {
		$errors = array();
		$number = isset( $building['number'] ) && is_numeric( $building['number'] ) ? (int) $building['number'] : 0;

		if ( $number < 100 || $number > self::MAX_BUILDING || 0 !== $number % 100 ) {
			$errors[] = 'building number must be a round hundred between 100 and 900';
		}
		if ( in_array( $number, array_map( 'intval', $existing ), true ) ) {
			$errors[] = 'building number ' . $number . ' is already used';
		}
		if ( ! isset( self::AREAS[ (string) ( $building['area'] ?? '' ) ] ) ) {
			$errors[] = 'area must be clean or spa';
		}
		if ( ! in_array( (string) ( $building['status'] ?? 'active' ), self::BUILDING_STATUSES, true ) ) {
			$errors[] = 'unknown building status';
		}
		if ( isset( $building['name'] ) && strlen( (string) $building['name'] ) > 60 ) {
			$errors[] = 'name is longer than 60 characters';
		}

		return $errors;
	}

	/**
	 * Validate a tank before it is saved; the number is derived here, never
	 * typed. Returns a list of problems (empty = fine).
	 *
	 * @param array $tank     building_number, level, position, size_label, gallons, status.
	 * @param int[] $existing Tank numbers already taken (excluding this row).
	 * @return string[]
	 */
	public static function validate_tank( array $tank, array $existing = array() ) {
		$errors   = array();
		$building = isset( $tank['building_number'] ) && is_numeric( $tank['building_number'] ) ? (int) $tank['building_number'] : 0;
		$level    = isset( $tank['level'] ) && is_numeric( $tank['level'] ) ? (int) $tank['level'] : 0;
		$position = isset( $tank['position'] ) && is_numeric( $tank['position'] ) ? (int) $tank['position'] : 0;

		if ( $building < 100 || 0 !== $building % 100 ) {
			$errors[] = 'tank needs a building';
		}
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$errors[] = 'level must be 1 (bottom) or 2 (top)';
		}
		if ( $position < 1 || $position > self::MAX_POSITION ) {
			$errors[] = 'position must be 1–9 (1 = leftmost)';
		}
		if ( ! $errors ) {
			$number = self::tank_number( $building, $level, $position );
			if ( in_array( $number, array_map( 'intval', $existing ), true ) ) {
				$errors[] = 'tank ' . $number . ' already exists';
			}
		}
		if ( isset( $tank['size_label'] ) && strlen( (string) $tank['size_label'] ) > 40 ) {
			$errors[] = 'size label is longer than 40 characters';
		}
		if ( isset( $tank['gallons'] ) && ( ! is_numeric( $tank['gallons'] ) || (int) $tank['gallons'] < 0 || (int) $tank['gallons'] > 9999 ) ) {
			$errors[] = 'gallons must be 0–9999';
		}
		if ( ! in_array( (string) ( $tank['status'] ?? 'active' ), self::TANK_STATUSES, true ) ) {
			$errors[] = 'unknown tank status';
		}

		return $errors;
	}

	/* ============================================================== */
	/*  Text                                                           */
	/* ============================================================== */

	/**
	 * 'Clean Room' / 'Spa'.
	 *
	 * @param string $area Area key.
	 * @return string
	 */
	public static function area_label( $area ) {
		$area = (string) $area;
		return isset( self::AREAS[ $area ] ) ? self::AREAS[ $area ] : ucfirst( $area );
	}

	/**
	 * 'Building 100', or the building's own name when it has one.
	 *
	 * @param array $building number, name.
	 * @return string
	 */
	public static function building_label( array $building ) {
		$name = trim( (string) ( $building['name'] ?? '' ) );
		if ( '' !== $name ) {
			return $name;
		}
		return 'Building ' . (int) ( $building['number'] ?? 0 );
	}

	/**
	 * The numbers of the other tanks in the same building (shared water),
	 * ascending. Offline tanks are left out: they are dry.
	 *
	 * @param array   $tank  The tank (building_id or building_number, number).
	 * @param array[] $tanks Every tank.
	 * @return int[]
	 */
	public static function shares_water( array $tank, array $tanks ) {
		$out = array();
		foreach ( $tanks as $other ) {
			if ( (int) ( $other['number'] ?? 0 ) === (int) ( $tank['number'] ?? 0 ) ) {
				continue;
			}
			if ( 'offline' === (string) ( $other['status'] ?? 'active' ) ) {
				continue;
			}
			if ( ! self::same_building( $tank, $other ) ) {
				continue;
			}
			$out[] = (int) $other['number'];
		}
		sort( $out );
		return $out;
	}

	/**
	 * Whether two tanks sit in the same building (by id when both carry
	 * one, else by the hundreds of their numbers).
	 *
	 * @param array $a Tank.
	 * @param array $b Tank.
	 * @return bool
	 */
	public static function same_building( array $a, array $b ) {
		if ( ! empty( $a['building_id'] ) && ! empty( $b['building_id'] ) ) {
			return (int) $a['building_id'] === (int) $b['building_id'];
		}
		return (int) floor( (int) ( $a['number'] ?? 0 ) / 100 ) === (int) floor( (int) ( $b['number'] ?? 0 ) / 100 );
	}

	/**
	 * The phone screen title:
	 * 'Tank 121 · Clean Room · Building 100 · 20G long · shares water with 111, 112, 122, 123'.
	 *
	 * @param array $tank     Tank (number, size_label).
	 * @param array $building Building (area, number, name).
	 * @param int[] $shares   From shares_water().
	 * @return string
	 */
	public static function tank_title( array $tank, array $building, array $shares ) {
		$bits = array(
			'Tank ' . (int) ( $tank['number'] ?? 0 ),
			self::area_label( $building['area'] ?? '' ),
			self::building_label( $building ),
		);
		$size = trim( (string) ( $tank['size_label'] ?? '' ) );
		if ( '' !== $size ) {
			$bits[] = $size;
		}
		if ( $shares ) {
			$bits[] = 'shares water with ' . implode( ', ', array_map( 'intval', $shares ) );
		} else {
			$bits[] = 'its own water';
		}
		return implode( ' · ', $bits );
	}

	/**
	 * 'Clean Room · Building 100 · Tank 121' — the location string the fish
	 * page and the product meta box show.
	 *
	 * @param array $tank     Tank (number).
	 * @param array $building Building (area, number, name).
	 * @return string
	 */
	public static function tank_path( array $tank, array $building ) {
		return self::area_label( $building['area'] ?? '' ) . ' · ' . self::building_label( $building ) . ' · Tank ' . (int) ( $tank['number'] ?? 0 );
	}

	/**
	 * The printed label: the number large, the rest small.
	 *
	 * @param array $tank     Tank.
	 * @param array $building Building.
	 * @return array{number: string, line: string}
	 */
	public static function label( array $tank, array $building ) {
		$bits = array( self::area_label( $building['area'] ?? '' ), self::building_label( $building ) );
		$size = trim( (string) ( $tank['size_label'] ?? '' ) );
		if ( '' !== $size ) {
			$bits[] = $size;
		}
		return array(
			'number' => (string) (int) ( $tank['number'] ?? 0 ),
			'line'   => implode( ' · ', $bits ),
		);
	}

	/* ============================================================== */
	/*  Ordering and grouping                                          */
	/* ============================================================== */

	/**
	 * Buildings by sort then number.
	 *
	 * @param array[] $buildings Buildings.
	 * @return array[]
	 */
	public static function sort_buildings( array $buildings ) {
		usort(
			$buildings,
			static function ( $a, $b ) {
				$s = (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
				return 0 !== $s ? $s : (int) ( $a['number'] ?? 0 ) <=> (int) ( $b['number'] ?? 0 );
			}
		);
		return array_values( $buildings );
	}

	/**
	 * Tanks the way a rack reads: top row first, then bottom, left to right
	 * (sort, then number inside a level).
	 *
	 * @param array[] $tanks Tanks.
	 * @return array[]
	 */
	public static function sort_tanks( array $tanks ) {
		usort(
			$tanks,
			static function ( $a, $b ) {
				$l = (int) ( $b['level'] ?? 0 ) <=> (int) ( $a['level'] ?? 0 );
				if ( 0 !== $l ) {
					return $l;
				}
				$s = (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
				return 0 !== $s ? $s : (int) ( $a['number'] ?? 0 ) <=> (int) ( $b['number'] ?? 0 );
			}
		);
		return array_values( $tanks );
	}

	/**
	 * Area → building → level (2 then 1) → tanks, for the board and the
	 * destination grid. Buildings without tanks still appear (coming soon).
	 *
	 * @param array[] $buildings Buildings (id, area, number, name, status, sort).
	 * @param array[] $tanks     Tanks (building_id, number, level, …).
	 * @return array<string, array{label: string, buildings: array[]}>
	 */
	public static function group( array $buildings, array $tanks ) {
		$by_building = array();
		foreach ( $tanks as $tank ) {
			$by_building[ (int) ( $tank['building_id'] ?? 0 ) ][] = $tank;
		}

		$groups = array();
		foreach ( array_keys( self::AREAS ) as $area ) {
			$groups[ $area ] = array(
				'label'     => self::AREAS[ $area ],
				'buildings' => array(),
			);
		}

		foreach ( self::sort_buildings( $buildings ) as $building ) {
			$area = (string) ( $building['area'] ?? 'clean' );
			if ( ! isset( $groups[ $area ] ) ) {
				$groups[ $area ] = array(
					'label'     => self::area_label( $area ),
					'buildings' => array(),
				);
			}

			$mine   = self::sort_tanks( isset( $by_building[ (int) ( $building['id'] ?? 0 ) ] ) ? $by_building[ (int) $building['id'] ] : array() );
			$levels = array();
			foreach ( array( 2, 1 ) as $level ) {
				$levels[ $level ] = array();
			}
			foreach ( $mine as $tank ) {
				$levels[ (int) ( $tank['level'] ?? 1 ) ][] = $tank;
			}

			$groups[ $area ]['buildings'][] = array(
				'building' => $building,
				'label'    => self::building_label( $building ),
				'levels'   => $levels,
				'tanks'    => $mine,
			);
		}

		return $groups;
	}

	/**
	 * The tank at a spoken level / position inside a building ('top left',
	 * 'bottom middle', 'top right', 'top second'), or null.
	 *
	 * @param array[] $tanks    Tanks of that building (any order).
	 * @param int     $level    1 bottom, 2 top.
	 * @param string  $position left|right|middle|center|first|last|second|third|fourth|fifth or a digit.
	 * @return array|null
	 */
	public static function tank_at( array $tanks, $level, $position ) {
		$row = array();
		foreach ( $tanks as $tank ) {
			if ( (int) ( $tank['level'] ?? 0 ) === (int) $level && 'offline' !== (string) ( $tank['status'] ?? 'active' ) ) {
				$row[] = $tank;
			}
		}
		if ( ! $row ) {
			return null;
		}
		usort(
			$row,
			static function ( $a, $b ) {
				return (int) ( $a['position'] ?? 0 ) <=> (int) ( $b['position'] ?? 0 );
			}
		);

		$n   = count( $row );
		$map = array(
			'left'   => 1,
			'first'  => 1,
			'second' => 2,
			'third'  => 3,
			'fourth' => 4,
			'fifth'  => 5,
			'right'  => $n,
			'last'   => $n,
			'middle' => (int) ceil( $n / 2 ),
			'center' => (int) ceil( $n / 2 ),
			'centre' => (int) ceil( $n / 2 ),
		);

		$position = strtolower( trim( (string) $position ) );
		$index    = isset( $map[ $position ] ) ? $map[ $position ] : ( is_numeric( $position ) ? (int) $position : 0 );

		if ( $index < 1 || $index > $n ) {
			return null;
		}
		if ( in_array( $position, array( 'middle', 'center', 'centre' ), true ) && 0 === $n % 2 ) {
			return null; // No middle tank in an even row.
		}

		return $row[ $index - 1 ];
	}
}
