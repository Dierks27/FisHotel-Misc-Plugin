<?php
/**
 * Clean Room splits — an order line laid out over several tanks when it
 * is checked in. "For things like butterflyfish and angels I always have
 * to split. But for anthias and damsels they almost always stay as a
 * group."
 *
 * THE PLAN. In the order page's Entry view the Tank cell has a Split
 * button: rows of "how many → which tank". The first row is the line's
 * first tank (its count may be left empty: it keeps what is not placed
 * elsewhere); every further row is a part that goes on to a tank of its
 * own. A tank must take arrivals (a Spa tank or a quarantine temporary
 * tank — Temp_Tanks::takes_arrivals()), a tank cannot be picked twice on
 * one line, and the parts may not add up to more than the line's live
 * fish (received − DOA). What is not placed stays in the first tank.
 * "1 per tank" (one_per_tank()) lays the live fish out one to a tank over
 * the next empty Spa tanks of the order's building.
 *
 * The plan is the owner's INTENT until the check-in; it is kept beside
 * the section's other settings (the option fishotel_cleanroom_splits, no
 * table, not autoloaded) by order line id, bounded and sanitised, and it
 * is forgotten the moment the line is checked in or removed.
 *
 * THE CHECK-IN (apply()) makes the plan real THROUGH THE SAME EVENTS A
 * PERSON WOULD MAKE BY HAND: Orders::check_in() writes the arrival of the
 * received count into the first tank and the DOA loss, then one
 * Store::split_stay() per further part — the Front Desk's own move of a
 * smaller quantity, which creates the sister stay. Nothing here writes an
 * event of its own, so the replay, the line's figures (a split group's
 * sister stays are counted on its line — Orders::with_sisters()), Undo
 * and the Stats need nothing new. A line that already is a stay is never
 * split from here: that group is split from its tank (a Move of a
 * smaller quantity).
 *
 * THE MEMORY. Per fish, whether its last order line was split at its
 * check-in — and whether that was one fish per tank. The next time that
 * fish is on an order, its line offers the split opened (with "1 per
 * tank" laid out once the received count is there). It is an OFFER the
 * owner sees under the line and can remove with one click: nothing is
 * ever split without having been shown. Nothing is seeded.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Splits
 */
class Splits {

	const OPTION = 'fishotel_cleanroom_splits';

	/**
	 * The most parts one line may be split into.
	 *
	 * @var int
	 */
	const MAX_PARTS = 40;

	/**
	 * The most plans and remembered fish the option keeps (the oldest go).
	 *
	 * @var int
	 */
	const MAX_PLANS   = 1000;
	const MAX_SPECIES = 2000;

	/**
	 * What a line posts when the owner said "keep together" to an offered
	 * split: the offer is not made again on that line.
	 *
	 * @var string
	 */
	const NONE = 'none';

	/* ============================================================== */
	/*  The plan (pure)                                                */
	/* ============================================================== */

	/**
	 * A posted plan, cleaned (pure): null when nothing was said (the field
	 * is empty or missing), NONE when the owner declined an offered split,
	 * else the rows — each a count and a tank id, 0 where one is missing
	 * (errors() names those); a row with neither is dropped. At most
	 * MAX_PARTS rows.
	 *
	 * @param mixed $raw A JSON string or an array of [qty, tank_id] / {qty, tank_id}.
	 * @return array[]|string|null
	 */
	public static function parts_in( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return null;
			}
			if ( self::NONE === strtolower( $raw ) ) {
				return self::NONE;
			}
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$whole = static function ( $value ) {
			return is_scalar( $value ) && ! is_bool( $value ) && 1 === preg_match( '/^\d{1,6}$/', trim( (string) $value ) ) ? (int) trim( (string) $value ) : 0;
		};

		$parts = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$qty  = $whole( array_key_exists( 'qty', $row ) ? $row['qty'] : ( $row[0] ?? null ) );
			$tank = $whole( array_key_exists( 'tank_id', $row ) ? $row['tank_id'] : ( $row[1] ?? null ) );
			if ( 0 === $qty && 0 === $tank ) {
				continue;
			}
			$parts[] = array(
				'qty'     => min( Orders::MAX_QTY, $qty ),
				'tank_id' => $tank,
			);
			if ( count( $parts ) >= self::MAX_PARTS ) {
				break;
			}
		}

		return $parts ? $parts : null;
	}

	/**
	 * How many fish a plan places.
	 *
	 * @param array[] $parts The parts.
	 * @return int
	 */
	public static function placed( array $parts ) {
		$sum = 0;
		foreach ( $parts as $part ) {
			$sum += max( 0, (int) ( $part['qty'] ?? 0 ) );
		}
		return $sum;
	}

	/**
	 * Whether a plan really splits a line: two parts or more.
	 *
	 * @param mixed $parts parts_in().
	 * @return bool
	 */
	public static function is_split( $parts ) {
		return is_array( $parts ) && count( $parts ) > 1;
	}

	/**
	 * Whether a plan is one fish per tank.
	 *
	 * @param mixed $parts parts_in().
	 * @return bool
	 */
	public static function is_ones( $parts ) {
		if ( ! self::is_split( $parts ) ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( 1 !== (int) $part['qty'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The sentences of a plan's mistakes — one table for errors() and for
	 * the page's JS (splitErrors() fills the same templates as the rows are
	 * typed).
	 *
	 * @return array<string, string>
	 */
	public static function strings() {
		return array(
			'noQty'  => __( 'a part of the split has no count', 'fishotel-misc-plugin' ),
			'noTank' => __( 'a part of the split has no tank', 'fishotel-misc-plugin' ),
			'area'   => __( 'a part of the split names a tank that takes no arrivals (Spa tanks and quarantine temporary tanks only)', 'fishotel-misc-plugin' ),
			/* translators: %s: tank number */
			'twice'  => __( 'Tank %s is picked twice', 'fishotel-misc-plugin' ),
			/* translators: 1: fish placed, 2: fish alive */
			'live'   => __( 'the split places %1$d fish but only %2$d arrived alive (received − DOA)', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * What is wrong with a plan (pure): a row without a tank, a further row
	 * without a count (the first tank needs none — it keeps what is not
	 * placed elsewhere), a tank that takes no arrivals, a tank picked twice,
	 * more fish placed than are alive.
	 *
	 * @param array[]  $parts The parts (parts_in()).
	 * @param int|null $live  The line's live fish (received − DOA); null while nothing is received.
	 * @param array    $tanks Tank id → tank (id, number) for every tank that takes arrivals.
	 * @return string[] One sentence per mistake; empty when the plan is sound.
	 */
	public static function errors( array $parts, $live, array $tanks ) {
		$s      = self::strings();
		$errors = array();
		$seen   = array();
		foreach ( array_values( $parts ) as $index => $part ) {
			$qty  = (int) ( $part['qty'] ?? 0 );
			$tank = (int) ( $part['tank_id'] ?? 0 );
			// The first tank needs no count: what is not placed elsewhere stays there.
			if ( $qty < 1 && $index > 0 ) {
				$errors['qty'] = $s['noQty'];
			}
			if ( $tank < 1 ) {
				$errors['tank'] = $s['noTank'];
				continue;
			}
			if ( ! isset( $tanks[ $tank ] ) ) {
				$errors['area'] = $s['area'];
				continue;
			}
			if ( isset( $seen[ $tank ] ) ) {
				$errors[ 'twice' . $tank ] = sprintf( $s['twice'], (string) $tanks[ $tank ]['number'] );
			}
			$seen[ $tank ] = true;
		}
		if ( null !== $live && self::placed( $parts ) > (int) $live ) {
			$errors['live'] = sprintf( $s['live'], self::placed( $parts ), max( 0, (int) $live ) );
		}
		return array_values( $errors );
	}

	/**
	 * The live fish of a line as typed: received − DOA; null while no
	 * received count is typed.
	 *
	 * @param array $line qty_received, qty_doa.
	 * @return int|null
	 */
	public static function live_of( array $line ) {
		if ( ! isset( $line['qty_received'] ) || null === $line['qty_received'] || '' === $line['qty_received'] ) {
			return null;
		}
		return max( 0, (int) $line['qty_received'] - max( 0, (int) ( $line['qty_doa'] ?? 0 ) ) );
	}

	/**
	 * "1 per tank" (pure; the page's JS onePerTank() gives the same): one
	 * fish to a tank over the next EMPTY Spa tanks — of the order's building
	 * when it has one, else of the whole Spa —, in rack order, skipping the
	 * tanks other lines of the page already took. The line's own first tank
	 * leads when it is one of them. When the empty tanks run out the plan
	 * stops there: what is not placed stays in the first tank.
	 *
	 * @param int      $live        Live fish (received − DOA).
	 * @param array[]  $tanks       tanks(): id, number, building_id, area, empty.
	 * @param int|null $building_id The order's building (null / 0 = any).
	 * @param int[]    $taken       Tank ids other lines already use.
	 * @param int      $first       The line's own first tank (0 = none).
	 * @return array[] Parts (qty 1, tank_id).
	 */
	public static function one_per_tank( $live, array $tanks, $building_id = null, array $taken = array(), $first = 0 ) {
		$live  = max( 0, (int) $live );
		$taken = array_map( 'intval', $taken );
		$free  = array();
		foreach ( $tanks as $tank ) {
			if ( 'spa' !== (string) ( $tank['area'] ?? '' ) || empty( $tank['empty'] ) || in_array( (int) $tank['id'], $taken, true ) ) {
				continue;
			}
			if ( (int) $building_id > 0 && (int) ( $tank['building_id'] ?? 0 ) !== (int) $building_id ) {
				continue;
			}
			$free[ (int) $tank['id'] ] = $tank;
		}
		uasort(
			$free,
			static function ( $a, $b ) {
				return (int) $a['number'] <=> (int) $b['number'];
			}
		);

		$order = array_keys( $free );
		if ( (int) $first > 0 && isset( $free[ (int) $first ] ) ) {
			$order = array_merge( array( (int) $first ), array_values( array_diff( $order, array( (int) $first ) ) ) );
		}

		$parts = array();
		foreach ( $order as $tank_id ) {
			if ( count( $parts ) >= min( $live, self::MAX_PARTS ) ) {
				break;
			}
			$parts[] = array(
				'qty'     => 1,
				'tank_id' => (int) $tank_id,
			);
		}
		return $parts;
	}

	/**
	 * The tanks a part may go into, as the page lists them: every tank
	 * that takes arrivals, the empty ones first, then by number.
	 *
	 * @param object $store    Store or stand-in (tanks(), open_stays()).
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @return array[] id, number, label (as a dropdown shows it), name ("Tank 121"), building_id,
	 *                 area (spa | temp), empty.
	 */
	public static function tanks( $store, $today, array $settings ) {
		$occupied = array();
		foreach ( (array) $store->open_stays( array(), $today, $settings ) as $stay ) {
			if ( ! empty( $stay['tank_id'] ) ) {
				$occupied[ (int) $stay['tank_id'] ] = true;
			}
		}

		$out = array();
		foreach ( (array) $store->tanks( false ) as $tank ) {
			if ( ! Temp_Tanks::takes_arrivals( $tank ) ) {
				continue;
			}
			$slot  = Layout::is_slot( $tank );
			$out[] = array(
				'id'          => (int) $tank['id'],
				'number'      => (int) $tank['number'],
				'label'       => $slot ? Layout::tank_label( $tank ) : (string) (int) $tank['number'],
				'name'        => Layout::tank_label( $tank ),
				'building_id' => (int) ( $tank['building_id'] ?? ( $tank['building']['id'] ?? 0 ) ),
				'area'        => $slot ? 'temp' : 'spa',
				'empty'       => ! isset( $occupied[ (int) $tank['id'] ] ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['empty'] !== $b['empty'] ) {
					return $a['empty'] ? -1 : 1;
				}
				if ( $a['area'] !== $b['area'] ) {
					return 'spa' === $a['area'] ? -1 : 1;
				}
				return $a['number'] <=> $b['number'];
			}
		);
		return $out;
	}

	/**
	 * tanks() by tank id.
	 *
	 * @param array[] $tanks tanks().
	 * @return array<int, array>
	 */
	public static function by_id( array $tanks ) {
		$out = array();
		foreach ( $tanks as $tank ) {
			$out[ (int) $tank['id'] ] = $tank;
		}
		return $out;
	}

	/**
	 * A plan in words: "2 → Tank 121 · 1 → Tank 122".
	 *
	 * @param array[] $parts The parts.
	 * @param array   $tanks by_id().
	 * @return string
	 */
	public static function text( array $parts, array $tanks ) {
		$bits = array();
		foreach ( $parts as $part ) {
			$tank = (int) $part['tank_id'];
			/* translators: 1: fish, 2: the tank ("Tank 121") */
			$bits[] = sprintf( __( '%1$d → %2$s', 'fishotel-misc-plugin' ), (int) $part['qty'], isset( $tanks[ $tank ] ) ? (string) $tanks[ $tank ]['name'] : '?' );
		}
		return implode( ' · ', $bits );
	}

	/* ============================================================== */
	/*  Stored: the plans and the memory                               */
	/* ============================================================== */

	/**
	 * The option, sanitised: plans by line id, the remembered fish by key.
	 *
	 * @return array{plans: array, species: array}
	 */
	public static function all() {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * The option as it may be stored (pure): unknown keys dropped, every
	 * plan cleaned by parts_in(), every remembered fish a key, a flag and a
	 * date, both lists bounded (the oldest entries go first).
	 *
	 * @param mixed $raw The stored value.
	 * @return array{plans: array, species: array}
	 */
	public static function sanitize( $raw ) {
		$out = array(
			'plans'   => array(),
			'species' => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( (array) ( $raw['plans'] ?? array() ) as $line_id => $plan ) {
			$line_id = (int) $line_id;
			$parts   = self::NONE === $plan ? self::NONE : ( is_array( $plan ) ? self::parts_in( $plan ) : null );
			if ( $line_id > 0 && null !== $parts ) {
				$out['plans'][ $line_id ] = $parts;
			}
		}
		if ( count( $out['plans'] ) > self::MAX_PLANS ) {
			$out['plans'] = array_slice( $out['plans'], -self::MAX_PLANS, null, true );
		}

		foreach ( (array) ( $raw['species'] ?? array() ) as $key => $entry ) {
			$key = substr( trim( (string) $key ), 0, 140 );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$out['species'][ $key ] = array(
				'ones' => ! empty( $entry['ones'] ),
				'on'   => Replay::is_date( $entry['on'] ?? null ) ? (string) $entry['on'] : '',
			);
		}
		if ( count( $out['species'] ) > self::MAX_SPECIES ) {
			$out['species'] = array_slice( $out['species'], -self::MAX_SPECIES, null, true );
		}

		return $out;
	}

	private static function store( array $all ) {
		$all = self::sanitize( $all );
		if ( ! $all['plans'] && ! $all['species'] ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * The plan kept for a line: its parts, NONE, or null.
	 *
	 * @param int $line_id Order line id.
	 * @return array[]|string|null
	 */
	public static function plan( $line_id ) {
		$all = self::all();
		return isset( $all['plans'][ (int) $line_id ] ) ? $all['plans'][ (int) $line_id ] : null;
	}

	/**
	 * The plans of a set of lines.
	 *
	 * @param int[] $line_ids Order line ids.
	 * @return array<int, array[]|string>
	 */
	public static function plans( array $line_ids ) {
		$all = self::all();
		$out = array();
		foreach ( $line_ids as $line_id ) {
			if ( isset( $all['plans'][ (int) $line_id ] ) ) {
				$out[ (int) $line_id ] = $all['plans'][ (int) $line_id ];
			}
		}
		return $out;
	}

	/**
	 * Keep what an order's save says about its lines' plans: a plan (or
	 * NONE) is stored, null forgets the line's plan; the plans of lines the
	 * save removed are forgotten with them.
	 *
	 * @param array $by_line Line id → parts | NONE | null.
	 * @param int[] $gone    Line ids that no longer exist.
	 */
	public static function keep( array $by_line, array $gone = array() ) {
		$all    = self::all();
		$before = $all;
		foreach ( $by_line as $line_id => $plan ) {
			$line_id = (int) $line_id;
			if ( $line_id < 1 ) {
				continue;
			}
			unset( $all['plans'][ $line_id ] ); // A changed plan moves to the end: the newest are kept longest.
			if ( null !== $plan ) {
				$all['plans'][ $line_id ] = $plan;
			}
		}
		foreach ( $gone as $line_id ) {
			unset( $all['plans'][ (int) $line_id ] );
		}
		if ( $all !== $before ) {
			self::store( $all );
		}
	}

	/**
	 * Forget the plans of lines (checked in, or deleted with their order).
	 *
	 * @param int[] $line_ids Order line ids.
	 */
	public static function forget( array $line_ids ) {
		self::keep( array(), $line_ids );
	}

	/**
	 * The keys a fish is remembered under: its species-list row and its
	 * name — a line typed by name finds what a listed one taught, and the
	 * other way round. None for a fish that still has to be identified.
	 *
	 * @param array $line species_id, species, needs_id.
	 * @return string[]
	 */
	public static function species_keys( array $line ) {
		if ( ! empty( $line['needs_id'] ) ) {
			return array();
		}
		$keys = array();
		if ( ! empty( $line['species_id'] ) && (int) $line['species_id'] > 0 ) {
			$keys[] = 's:' . (int) $line['species_id'];
		}
		$name = Species::key( (string) ( $line['species'] ?? '' ) );
		if ( '' !== $name ) {
			$keys[] = 'n:' . $name;
		}
		return $keys;
	}

	/**
	 * Remember how a fish's line was checked in: split (and whether one per
	 * tank), or kept together — which forgets it.
	 *
	 * @param array  $line  The order line.
	 * @param bool   $split Whether it was split.
	 * @param bool   $ones  Whether that was one fish per tank.
	 * @param string $today Site date.
	 */
	public static function remember( array $line, $split, $ones, $today ) {
		$keys = self::species_keys( $line );
		if ( ! $keys ) {
			return;
		}
		$all    = self::all();
		$before = $all;
		foreach ( $keys as $key ) {
			unset( $all['species'][ $key ] );
			if ( $split ) {
				$all['species'][ $key ] = array(
					'ones' => (bool) $ones,
					'on'   => Replay::is_date( $today ) ? (string) $today : '',
				);
			}
		}
		if ( $all !== $before ) {
			self::store( $all );
		}
	}

	/**
	 * What is remembered for a line's fish: null when its last line was not
	 * split (or nothing is known), else ones (bool) and on (the date).
	 *
	 * @param array      $line   The order line.
	 * @param array|null $memory all()['species'] (null = read it).
	 * @return array{ones: bool, on: string}|null
	 */
	public static function remembered( array $line, $memory = null ) {
		$memory = is_array( $memory ) ? $memory : self::all()['species'];
		foreach ( self::species_keys( $line ) as $key ) {
			if ( isset( $memory[ $key ] ) ) {
				return $memory[ $key ];
			}
		}
		return null;
	}

	/* ============================================================== */
	/*  The check-in                                                   */
	/* ============================================================== */

	/**
	 * The first tank of a plan when it still takes arrivals — where the
	 * line's arrival goes —, else null (the check-in then picks a tank as
	 * it does for any line).
	 *
	 * @param object $store Store or stand-in (tank()).
	 * @param mixed  $parts The line's plan.
	 * @return array|null The tank row.
	 */
	public static function first_tank( $store, $parts ) {
		if ( ! is_array( $parts ) || ! $parts || (int) $parts[0]['tank_id'] < 1 ) {
			return null;
		}
		$tank = $store->tank( (int) $parts[0]['tank_id'] );
		return Temp_Tanks::takes_arrivals( $tank ) ? $tank : null;
	}

	/**
	 * Make a line's plan real once its arrival and its DOA loss are
	 * written: one Store::split_stay() per further part — exactly what the
	 * Front Desk's Move of a smaller quantity does. A part is skipped, and
	 * said so, when its tank no longer takes arrivals, when it is the tank
	 * the fish are in, or when it would take the last fish out of the first
	 * tank; those fish stay where they are.
	 *
	 * @param object  $store    Store or stand-in (stay(), tank(), split_stay()).
	 * @param int     $stay_id  The line's stay (just checked in).
	 * @param array[] $parts    The plan; the first part is the first tank.
	 * @param array   $base     actor_id, source.
	 * @param string  $today    Site date.
	 * @param array   $settings Settings.
	 * @return array{made: array[], errors: string[]} made: qty, tank, tank_id, stay_id, event_ids.
	 */
	public static function apply( $store, $stay_id, array $parts, array $base, $today, array $settings ) {
		$out = array(
			'made'   => array(),
			'errors' => array(),
		);

		foreach ( array_slice( array_values( $parts ), 1 ) as $part ) {
			$qty  = (int) ( $part['qty'] ?? 0 );
			$stay = $store->stay( (int) $stay_id, $today, $settings );
			if ( ! $stay || $qty < 1 ) {
				continue;
			}
			$to = $store->tank( (int) ( $part['tank_id'] ?? 0 ) );
			if ( ! Temp_Tanks::takes_arrivals( $to ) || (int) $to['id'] === (int) $stay['tank_id'] ) {
				/* translators: 1: fish, 2: species */
				$out['errors'][] = sprintf( __( '%1$d × %2$s stay in their first tank: the tank picked for them takes no arrivals any more.', 'fishotel-misc-plugin' ), $qty, (string) $stay['species'] );
				continue;
			}
			if ( $qty >= (int) $stay['qty_live'] ) {
				/* translators: 1: fish, 2: species, 3: tank number */
				$out['errors'][] = sprintf( __( '%1$d × %2$s stay in their first tank: there were not enough left to move to Tank %3$s.', 'fishotel-misc-plugin' ), $qty, (string) $stay['species'], (string) $to['number'] );
				continue;
			}

			$split = $store->split_stay( $stay, $qty, $to, $base, $today, $settings );
			if ( ! $split ) {
				/* translators: 1: species, 2: tank number */
				$out['errors'][] = sprintf( __( '%1$s: the split to Tank %2$s could not be recorded.', 'fishotel-misc-plugin' ), (string) $stay['species'], (string) $to['number'] );
				continue;
			}
			$out['made'][] = array(
				'qty'       => $qty,
				'tank'      => (int) $to['number'],
				'tank_id'   => (int) $to['id'],
				'stay_id'   => (int) $split['stay_id'],
				'event_ids' => array_values( array_map( 'intval', (array) $split['event_ids'] ) ),
			);
		}

		return $out;
	}
}
