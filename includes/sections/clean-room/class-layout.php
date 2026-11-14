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
 * The one exception is a building flagged own_water — "Temporary" (900):
 * its tanks are numbered SLOTS, each its own water. Hundreds = building,
 * tens 0 = not on a rack, ones = the slot (901 … 909). A slot is a place
 * and a laminated label ("TEMP 1 · 901"); what is set up in it comes and
 * goes (Temp_Tanks). Such a building has the area 'temp', which is
 * neither 'clean' nor 'spa': code that knows nothing about temporary
 * tanks never takes one for a Spa or a Clean Room tank.
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
	const MAX_GALLONS  = 9999;

	/**
	 * The area of a building whose tanks are each their own water (the
	 * temporary tanks) and its display name. Deliberately not in AREAS:
	 * the two permanent areas are what the pickers and filters offer.
	 */
	const TEMP_AREA  = 'temp';
	const TEMP_LABEL = 'Temporary';

	/**
	 * The Temporary building's number (the highest free round hundred when
	 * 900 is already an ordinary building), how many slots it starts with
	 * and how many it can have — the ones digit is the slot.
	 */
	const TEMP_BUILDING = 900;
	const TEMP_SLOTS    = 6;
	const MAX_SLOTS     = 9;

	/**
	 * The seed's system volumes (sump included) of the two Spa buildings —
	 * the figures Jeff's dose maths use, not workbook data.
	 */
	const SPA_500_GALLONS = 205;
	const SPA_600_GALLONS = 100;

	/**
	 * The seed layout — inserted ONLY when the buildings table is empty.
	 *
	 * @return array[] Buildings with nested tanks (numbers derived by the rule).
	 */
	public static function seed() {
		$long    = array( '20G long', 20 );
		$breeder = array( '40G breeder', 40 );

		// The last value is the system's water volume (sump included): the
		// two Spa systems hold 205 and 100 gallons — the figures Jeff's dose
		// log and dose maths use; a null means "the sum of the tanks".
		$buildings = array(
			array( 'clean', 100, 'active', array( 2 => array( $long, $long, $long ), 1 => array( $breeder, $breeder ) ), null ),
			array( 'clean', 200, 'active', array( 2 => array( $long, $long ), 1 => array( $breeder, $breeder ) ), null ),
			array( 'clean', 300, 'active', array( 2 => array( $long, $long ), 1 => array( $breeder, $breeder ) ), null ),
			array( 'clean', 400, 'coming_soon', array(), null ),
			array( 'spa', 500, 'active', array( 2 => array( $long, $long, $long, $long ), 1 => array( $breeder, $breeder, $breeder ) ), self::SPA_500_GALLONS ),
			array( 'spa', 600, 'active', array( 2 => array( $breeder ), 1 => array( $breeder ) ), self::SPA_600_GALLONS ),
		);

		$out  = array();
		$sort = 0;
		foreach ( $buildings as $def ) {
			list( $area, $number, $status, $rows, $gallons ) = $def;
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
				'area'           => $area,
				'number'         => $number,
				'name'           => '',
				'status'         => $status,
				'sort'           => $sort,
				'notes'          => '',
				'schedule'       => '',
				'system_gallons' => $gallons,
				'tanks'          => $tanks,
			);
		}

		return $out;
	}

	/**
	 * The water volume of a building's system: its own figure when set,
	 * else the gallons of its tanks (offline tanks are dry) added up.
	 *
	 * @param array   $building Building (id, number, system_gallons).
	 * @param array[] $tanks    Tanks (of this building, or every tank).
	 * @return int 0 when nothing is known.
	 */
	public static function system_gallons( array $building, array $tanks ) {
		if ( self::is_own_water( $building ) ) {
			return 0; // Each tank its own water: there is no system to add up.
		}
		if ( isset( $building['system_gallons'] ) && null !== $building['system_gallons'] && (int) $building['system_gallons'] > 0 ) {
			return (int) $building['system_gallons'];
		}
		$sum = 0;
		foreach ( $tanks as $tank ) {
			if ( 'offline' === (string) ( $tank['status'] ?? 'active' ) ) {
				continue;
			}
			if ( ! empty( $building['id'] ) && ! empty( $tank['building_id'] ) ) {
				$mine = (int) $tank['building_id'] === (int) $building['id'];
			} else {
				$mine = (int) floor( (int) ( $tank['number'] ?? 0 ) / 100 ) * 100 === (int) floor( (int) ( $building['number'] ?? 0 ) / 100 ) * 100;
			}
			if ( $mine ) {
				$sum += max( 0, (int) ( $tank['gallons'] ?? 0 ) );
			}
		}
		return $sum;
	}

	/**
	 * '205 gal system' ('' when the volume is not known).
	 *
	 * @param int $gallons System gallons.
	 * @return string
	 */
	public static function system_label( $gallons ) {
		return (int) $gallons > 0 ? (int) $gallons . ' gal system' : '';
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

	/* ============================================================== */
	/*  Slots: the tanks of an own-water building                      */
	/* ============================================================== */

	/**
	 * Whether a building's tanks are each their own water (the Temporary
	 * building) rather than one shared system.
	 *
	 * @param array $building Building row, or a tank's 'building' part (own_water).
	 * @return bool
	 */
	public static function is_own_water( array $building ) {
		return ! empty( $building['own_water'] );
	}

	/**
	 * Whether a tank is a slot of an own-water building.
	 *
	 * @param array $tank Tank (own_water, or its 'building' part).
	 * @return bool
	 */
	public static function is_slot( array $tank ) {
		if ( ! empty( $tank['own_water'] ) ) {
			return true;
		}
		return isset( $tank['building'] ) && is_array( $tank['building'] ) && self::is_own_water( $tank['building'] );
	}

	/**
	 * The number of a slot: building + slot (900, 3 → 903).
	 *
	 * @param int $building_number Building number.
	 * @param int $slot            1–9.
	 * @return int
	 */
	public static function slot_number( $building_number, $slot ) {
		return (int) ( floor( (int) $building_number / 100 ) * 100 ) + (int) $slot;
	}

	/**
	 * Split a slot number into its parts, or null when it is not one
	 * (hundreds = building, tens 0, ones 1–9).
	 *
	 * @param mixed $number Tank number.
	 * @return array{building: int, slot: int}|null
	 */
	public static function parse_slot( $number ) {
		if ( ! is_scalar( $number ) || ! is_numeric( $number ) ) {
			return null;
		}
		$number = (int) $number;
		if ( $number < 101 || $number > 909 ) {
			return null;
		}
		$slot = $number % 100;
		if ( $slot < 1 || $slot > self::MAX_SLOTS ) {
			return null;
		}
		return array(
			'building' => (int) floor( $number / 100 ) * 100,
			'slot'     => $slot,
		);
	}

	/**
	 * The slot of a tank row (0 when it is not a slot).
	 *
	 * @param array $tank Tank (number; own_water or its building).
	 * @return int
	 */
	public static function slot_of( array $tank ) {
		if ( ! self::is_slot( $tank ) ) {
			return 0;
		}
		$parts = self::parse_slot( $tank['number'] ?? 0 );
		return $parts ? $parts['slot'] : 0;
	}

	/**
	 * 'TEMP 1' — what a slot is called on its label and on every screen.
	 *
	 * @param int $slot Slot.
	 * @return string
	 */
	public static function slot_name( $slot ) {
		return 'TEMP ' . (int) $slot;
	}

	/**
	 * 'TEMP 1 · 901' for a slot ('Tank 121' for any other tank).
	 *
	 * @param array $tank Tank.
	 * @return string
	 */
	public static function tank_label( array $tank ) {
		$slot = self::slot_of( $tank );
		if ( $slot > 0 ) {
			return self::slot_name( $slot ) . ' · ' . (int) $tank['number'];
		}
		return 'Tank ' . (int) ( $tank['number'] ?? 0 );
	}

	/**
	 * The number the Temporary building gets: 900, else the highest round
	 * hundred no building uses (0 when all nine are taken).
	 *
	 * @param int[] $taken Building numbers in use.
	 * @return int
	 */
	public static function temp_building_number( array $taken ) {
		$taken = array_map( 'intval', $taken );
		for ( $number = self::TEMP_BUILDING; $number >= 100; $number -= 100 ) {
			if ( ! in_array( $number, $taken, true ) ) {
				return $number;
			}
		}
		return 0;
	}

	/**
	 * The Temporary building with its slots, in the shape of a seed()
	 * entry — inserted once, when the layout has no own-water building.
	 *
	 * @param int $number Building number (temp_building_number()).
	 * @param int $slots  How many slots (1–9).
	 * @param int $sort   Sort (after the seeded buildings).
	 * @return array
	 */
	public static function temp_seed( $number = self::TEMP_BUILDING, $slots = self::TEMP_SLOTS, $sort = 90 ) {
		$slots = max( 1, min( self::MAX_SLOTS, (int) $slots ) );
		$tanks = array();
		for ( $slot = 1; $slot <= $slots; $slot++ ) {
			$tanks[] = self::slot_row( $number, $slot );
		}

		return array(
			'area'           => self::TEMP_AREA,
			'number'         => (int) $number,
			'name'           => self::TEMP_LABEL,
			'status'         => 'active',
			'sort'           => (int) $sort,
			'notes'          => '',
			'schedule'       => '',
			'system_gallons' => null,
			'own_water'      => 1,
			'tanks'          => $tanks,
		);
	}

	/**
	 * One slot as a tank row: level 0 (not on a rack), position = the
	 * slot, no size and no gallons of its own — those belong to what is
	 * set up in it.
	 *
	 * @param int $building_number Building number.
	 * @param int $slot            Slot.
	 * @return array
	 */
	public static function slot_row( $building_number, $slot ) {
		return array(
			'number'     => self::slot_number( $building_number, $slot ),
			'level'      => 0,
			'position'   => (int) $slot,
			'size_label' => '',
			'gallons'    => 0,
			'status'     => 'active',
			'sort'       => (int) $slot,
		);
	}

	/**
	 * The next slot an own-water building can get: the lowest free one,
	 * or 0 when all nine exist.
	 *
	 * @param int   $building_number Building number.
	 * @param int[] $numbers         Tank numbers already in the building.
	 * @return int Slot (1–9) or 0.
	 */
	public static function next_slot( $building_number, array $numbers ) {
		$numbers = array_map( 'intval', $numbers );
		for ( $slot = 1; $slot <= self::MAX_SLOTS; $slot++ ) {
			if ( ! in_array( self::slot_number( $building_number, $slot ), $numbers, true ) ) {
				return $slot;
			}
		}
		return 0;
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
		$area = (string) ( $building['area'] ?? '' );
		if ( self::is_own_water( $building ) ? self::TEMP_AREA !== $area : ! isset( self::AREAS[ $area ] ) ) {
			$errors[] = self::is_own_water( $building ) ? 'the temporary tanks keep their own area' : 'area must be clean or spa';
		}
		if ( ! in_array( (string) ( $building['status'] ?? 'active' ), self::BUILDING_STATUSES, true ) ) {
			$errors[] = 'unknown building status';
		}
		if ( isset( $building['name'] ) && strlen( (string) $building['name'] ) > 60 ) {
			$errors[] = 'name is longer than 60 characters';
		}
		if ( isset( $building['schedule'] ) && strlen( (string) $building['schedule'] ) > 40 ) {
			$errors[] = 'schedule name is longer than 40 characters';
		}
		if ( array_key_exists( 'system_gallons', $building ) && null !== $building['system_gallons'] && ( ! is_numeric( $building['system_gallons'] ) || (int) $building['system_gallons'] < 0 || (int) $building['system_gallons'] > self::MAX_GALLONS ) ) {
			$errors[] = 'system gallons must be blank or 0–9999';
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
		if ( self::TEMP_AREA === $area ) {
			return self::TEMP_LABEL;
		}
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
	 * ascending. Offline tanks are left out: they are dry. A slot of an
	 * own-water building shares with nobody.
	 *
	 * @param array   $tank  The tank (building_id or building_number, number).
	 * @param array[] $tanks Every tank.
	 * @return int[]
	 */
	public static function shares_water( array $tank, array $tanks ) {
		if ( self::is_slot( $tank ) ) {
			return array(); // Its own water, whatever else stands in the building.
		}
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
		if ( self::is_own_water( $building ) ) {
			// 'Temporary · TEMP 1 · Tank 901' — the area and the building are the same thing here.
			$parts = self::parse_slot( $tank['number'] ?? 0 );
			return self::building_label( $building ) . ( $parts ? ' · ' . self::slot_name( $parts['slot'] ) : '' ) . ' · Tank ' . (int) ( $tank['number'] ?? 0 );
		}
		return self::area_label( $building['area'] ?? '' ) . ' · ' . self::building_label( $building ) . ' · Tank ' . (int) ( $tank['number'] ?? 0 );
	}

	/**
	 * The printed label: the number large, the rest small. A slot of an
	 * own-water building prints 'TEMP 1' large and its number small (and
	 * carries 'slot', so the page can fit the longer word).
	 *
	 * @param array $tank     Tank.
	 * @param array $building Building.
	 * @return array{number: string, line: string, slot?: int}
	 */
	public static function label( array $tank, array $building ) {
		$parts = self::is_own_water( $building ) ? self::parse_slot( $tank['number'] ?? 0 ) : null;
		if ( $parts ) {
			// A slot's label is laminated once and reused: it names the slot,
			// never what happens to be set up in it.
			return array(
				'number' => self::slot_name( $parts['slot'] ),
				'line'   => (int) $tank['number'] . ' · ' . self::building_label( $building ) . ' · scan to see what is set up here',
				'slot'   => $parts['slot'],
			);
		}
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
