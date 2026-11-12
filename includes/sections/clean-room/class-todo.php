<?php
/**
 * Clean Room to-do — PURE: everything due today as ONE list, by water
 * system. "There isn't really single tanks except the hospital tanks.
 * Everything else are systems when it comes to medication and such, as
 * they are all connected."
 *
 * One block per building with fish — the whole shared system: its name,
 * its gallons, its schedule and the day(s) its groups are on — and one
 * block per temporary tank that is set up (its own water, its own
 * schedule, its own clock). The quarantine systems come first, then the
 * temporary tanks, then any Clean Room building with something due.
 *
 * In a building's block every due dose, test and water change appears
 * ONCE FOR THE SYSTEM, never once per tank: it is worked out from the
 * groups in the building with the same rules as a tank's own to-do
 * (Protocol::todo() — a step is due on a day number, in an area), one run
 * per distinct day. When the groups are on different days and that makes
 * different steps due, each step is listed once and says which day's
 * group it is for. Only what really is per tank — the feeding — is per
 * tank, collapsed to one line ("Feed — 7 tanks with fish").
 *
 * One truth, wherever it is ticked. A system step is done when the day's
 * log of the building — the entries on the building itself and on any of
 * its tanks: one water, one pool — holds
 *   - a tick of that step: on the system (note = the step's key) or on a
 *     tank's own screen (note = "<key> #<stay>"), or
 *   - for the copper test, a copper reading taken on any tank, or
 *   - for a dose step, a dose naming the same medication.
 * overlay() then marks the same step done on every tank of the building,
 * so the Board and each tank's phone screen say what this list says.
 *
 * The medication and the dose a line shows come from the step's own
 * label ("Chloroquine 40 mg/gal" names both); a step that names a
 * medication but no rate gets the Dose sheet's own suggestion for the
 * system's gallons, worded as a suggestion.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Todo
 */
class Todo {

	/**
	 * The kinds a tick may carry: a step's own kind. Never 'copper' (a
	 * reading has a value) and never 'note'.
	 *
	 * @var string[]
	 */
	const TICK_KINDS = array( 'feed', 'dose', 'test', 'water_change', 'other' );

	/**
	 * The filter the count is offered through —
	 * apply_filters( 'fishotel_cleanroom_todo', null ) answers summary(),
	 * or stays null while this section is off — and the Today section's
	 * filter this section adds its one line to (today_line()).
	 *
	 * @var string
	 */
	const SUMMARY_FILTER = 'fishotel_cleanroom_todo';
	const TODAY_FILTER   = 'fishotel_today_lines';

	/**
	 * The key of this section's line among the Today section's lines.
	 *
	 * @var string
	 */
	const TODAY_LINE_KEY = 'clean-room-todo';

	/* ============================================================== */
	/*  Medications and doses a step names                             */
	/* ============================================================== */

	/**
	 * The medication a step label or a log note names (Protocol's list,
	 * by alias), or null. Wrapped: whatever happens to that list, the
	 * to-do still renders.
	 *
	 * @param string $text Label or note.
	 * @return string|null Medication key.
	 */
	public static function medication_key( $text ) {
		try {
			$key = Protocol::medication_key( (string) $text );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * The display name of the medication a label names ('' when none).
	 *
	 * @param string $text Step label.
	 * @return string
	 */
	public static function medication_name( $text ) {
		$key = self::medication_key( $text );
		if ( null === $key ) {
			return '';
		}
		try {
			return (string) Protocol::medication_label( $key );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * An amount as text: one decimal from 1 up, two below, no trailing
	 * zeros — the Dose sheet's rounding.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function amount_text( $amount ) {
		$amount = (float) $amount;
		$text   = number_format( round( $amount, $amount >= 1 ? 1 : 2 ), 2, '.', '' );
		return rtrim( rtrim( $text, '0' ), '.' );
	}

	/**
	 * The dose a step names, for a water volume: a rate in its label
	 * ("40 mg/gal" → "40 mg/gal = 8.2 g for 205 gal"), else the Dose
	 * sheet's own suggestion for the medication it names ("suggested
	 * 5.1 g for 205 gal"), else ''. Never a number the step and the sheet
	 * do not already give.
	 *
	 * @param string $label   Step label.
	 * @param int    $gallons The water it goes into (0 = not known).
	 * @return string
	 */
	public static function dose_text( $label, $gallons ) {
		$label   = (string) $label;
		$gallons = (int) $gallons;

		if ( 1 === preg_match( '/(\d+(?:\.\d+)?)\s*mg\s*(?:\/|per)\s*gal(?:lon)?\b/i', $label, $m ) ) {
			$rate = rtrim( rtrim( number_format( (float) $m[1], 2, '.', '' ), '0' ), '.' ) . ' mg/gal';
			if ( $gallons <= 0 ) {
				return $rate;
			}
			return $rate . ' = ' . self::amount_text( (float) $m[1] * $gallons / 1000 ) . ' g for ' . $gallons . ' gal';
		}

		$key = self::medication_key( $label );
		if ( null === $key || $gallons <= 0 ) {
			return '';
		}
		try {
			$suggestion = Protocol::dose_suggestion( $key, $gallons );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $suggestion ) || ! isset( $suggestion['amount'] ) ) {
			return '';
		}
		return 'suggested ' . self::amount_text( (float) $suggestion['amount'] ) . ' ' . (string) ( $suggestion['unit'] ?? '' ) . ' for ' . $gallons . ' gal';
	}

	/**
	 * What a line says beside its step: the medication it names (when the
	 * label is not already just that name) and the dose.
	 *
	 * @param string $label   Step label.
	 * @param string $kind    Step kind.
	 * @param int    $gallons Water volume.
	 * @return array{medication: string, dose: string}
	 */
	public static function names( $label, $kind, $gallons ) {
		if ( 'dose' !== (string) $kind ) {
			return array(
				'medication' => '',
				'dose'       => '',
			);
		}
		$name = self::medication_name( $label );
		return array(
			'medication' => '' !== $name && strtolower( $name ) !== strtolower( trim( (string) $label ) ) ? $name : '',
			'dose'       => self::dose_text( $label, $gallons ),
		);
	}

	/* ============================================================== */
	/*  Keys and ticks                                                 */
	/* ============================================================== */

	/**
	 * A tick's note as a label again: "furan-2 #0" → "furan-2".
	 *
	 * @param string $key The note.
	 * @return string
	 */
	public static function key_label( $key ) {
		return trim( (string) preg_replace( '/\s+#\d+$/', '', (string) $key ) );
	}

	/**
	 * Whether the day's entries hold a tick of a step: its system key, or
	 * the key of one group on one tank ("<key> #<stay>").
	 *
	 * @param string  $key  Protocol::tick_key() of the step's label.
	 * @param array[] $rows Log rows (note).
	 * @return bool
	 */
	public static function ticked( $key, array $rows ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$note = Protocol::tick_key( (string) ( $row['note'] ?? '' ) );
			if ( $note === $key || ( 0 === strpos( $note, $key . ' #' ) && ctype_digit( substr( $note, strlen( $key ) + 2 ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The day's pool of a shared system: every entry on the building
	 * itself and on any of its tanks — it is one water.
	 *
	 * @param array[] $rows        The day's log rows (tank_id, building_id, …).
	 * @param int     $building_id Building id.
	 * @param int[]   $tank_ids    Its tanks.
	 * @return array[]
	 */
	public static function pool( array $rows, $building_id, array $tank_ids ) {
		$tank_ids = array_map( 'intval', $tank_ids );
		$out      = array();
		foreach ( $rows as $row ) {
			$tank = (int) ( $row['tank_id'] ?? 0 );
			if ( $tank > 0 ? in_array( $tank, $tank_ids, true ) : (int) ( $row['building_id'] ?? 0 ) === (int) $building_id ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * The day's entries of one setup: on its tank, written while it stood
	 * there. What an earlier setup in the same slot logged is not this
	 * one's.
	 *
	 * @param array[] $rows     The day's log rows (tank_id, setup_id, …).
	 * @param int     $tank_id  The slot's tank.
	 * @param int     $setup_id The setup.
	 * @return array[]
	 */
	public static function setup_rows( array $rows, $tank_id, $setup_id ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['tank_id'] ?? 0 ) === (int) $tank_id && (int) $setup_id > 0 && (int) ( $row['setup_id'] ?? 0 ) === (int) $setup_id ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  A tank's own to-do                                             */
	/* ============================================================== */

	/**
	 * The to-do of a temporary tank today, in the shape Protocol::todo()
	 * gives a rack tank: from the setup's OWN steps, on the setup's OWN
	 * clock (day 1 = its treatment start date), ticked by its own
	 * entries. The feeding is listed only while fish are in it; the
	 * treatment runs whether or not they have been moved in yet. A step's
	 * "where" does not apply: the tank is its own place.
	 *
	 * @param array   $setup     The open setup (steps, start_date).
	 * @param array[] $occupants The tank's open stays.
	 * @param string  $today     Site date.
	 * @param array[] $rows      This setup's entries of today (setup_rows()).
	 * @return array[] key, label, kind, note, stay_id (0), species (''), day, done.
	 */
	public static function temp_todo( array $setup, array $occupants, $today, array $rows ) {
		$day   = Temp_Tanks::day( $setup, $today );
		$steps = isset( $setup['steps'] ) && is_array( $setup['steps'] ) ? $setup['steps'] : array();
		if ( null === $day || ! $steps ) {
			return array();
		}

		$fish = false;
		foreach ( $occupants as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) > 0 ) {
				$fish = true;
				break;
			}
		}

		// One stand-in "group" on the tank's own day: the same due rules
		// and the same ticking (a reading, a dose, a tick) as everywhere.
		$clock = array(
			array(
				'id'       => 0,
				'species'  => '',
				'qty_live' => 1,
				'day'      => $day,
			),
		);

		$out = array();
		foreach ( Protocol::todo( $steps, $clock, $today, '', $rows ) as $item ) {
			if ( 'feed' === (string) $item['kind'] && ! $fish ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/* ============================================================== */
	/*  The list                                                       */
	/* ============================================================== */

	/**
	 * "day 3" / "days 3 and 9" / "days 2, 5 and 9".
	 *
	 * @param int[]  $days   Day numbers.
	 * @param string $suffix Appended after each number ('' or ' group').
	 * @return string
	 */
	public static function days_text( array $days, $suffix = '' ) {
		$days = array_values( array_unique( array_map( 'intval', $days ) ) );
		sort( $days );
		if ( ! $days ) {
			return '';
		}
		if ( '' !== $suffix ) {
			$parts = array();
			foreach ( $days as $day ) {
				$parts[] = 'day ' . $day;
			}
			$last = array_pop( $parts );
			return ( $parts ? implode( ', ', $parts ) . ' and ' : '' ) . $last . ( $parts ? ' groups' : ' group' );
		}
		if ( 1 === count( $days ) ) {
			return 'day ' . $days[0];
		}
		$last = array_pop( $days );
		return 'days ' . implode( ', ', $days ) . ' and ' . $last;
	}

	/**
	 * The system's due steps — every dose, test, water change and other
	 * step ONCE, however many tanks have fish: one run of the tank rules
	 * per distinct day, merged by step.
	 *
	 * @param array[] $steps       The building's template.
	 * @param int[]   $days        The distinct days its groups are on.
	 * @param string  $area        The building's area.
	 * @param array[] $pool        The day's pool of the building (pool()).
	 * @param string  $today       Site date.
	 * @param int     $gallons     System gallons.
	 * @param int     $building_id Building id.
	 * @return array[] Items (scope 'system'), template order.
	 */
	public static function system_items( array $steps, array $days, $area, array $pool, $today, $gallons, $building_id ) {
		$days = array_values( array_unique( array_map( 'intval', $days ) ) );
		sort( $days );
		if ( ! $days ) {
			return array();
		}

		$groups = array();
		foreach ( $days as $day ) {
			$groups[] = array(
				'id'       => 0,
				'species'  => '',
				'qty_live' => 1,
				'day'      => $day,
			);
		}

		$items = array();
		foreach ( Protocol::todo( $steps, $groups, $today, (string) $area, $pool ) as $raw ) {
			if ( 'feed' === (string) $raw['kind'] ) {
				continue;
			}
			$label = (string) $raw['label'];
			$key   = Protocol::tick_key( $label );
			if ( ! isset( $items[ $key ] ) ) {
				$names         = self::names( $label, (string) $raw['kind'], $gallons );
				$items[ $key ] = array(
					'key'         => $key,
					'label'       => $label,
					'kind'        => (string) $raw['kind'],
					'note'        => (string) ( $raw['note'] ?? '' ),
					'medication'  => $names['medication'],
					'dose'        => $names['dose'],
					'days'        => array(),
					'for'         => '',
					'done'        => false,
					'scope'       => 'system',
					'building_id' => (int) $building_id,
					'tank'        => 0,
				);
			}
			$items[ $key ]['days'][] = (int) $raw['day'];
			if ( ! empty( $raw['done'] ) ) {
				$items[ $key ]['done'] = true;
			}
		}

		foreach ( $items as $key => $item ) {
			$item['days'] = array_values( array_unique( $item['days'] ) );
			sort( $item['days'] );
			if ( ! $item['done'] && self::ticked( (string) $key, $pool ) ) {
				$item['done'] = true;
			}
			// Which day's group it is for — said only when the groups are
			// on different days and the step is not due for all of them.
			if ( count( $days ) > 1 && $item['days'] !== $days ) {
				$item['for'] = self::days_text( $item['days'], ' group' );
			}
			$items[ $key ] = $item;
		}

		return array_values( $items );
	}

	/**
	 * The feeding of a building, one line per feeding step: the tanks with
	 * fish, each fed or not (its own tick, or the building's), ticked all
	 * at once on the system or tank by tank.
	 *
	 * @param array[] $tanks       The building's tanks with their own to-do ('todo', 'number', 'id').
	 * @param int     $building_id Building id.
	 * @return array[] Items (scope 'feed').
	 */
	public static function feed_lines( array $tanks, $building_id ) {
		$lines = array();
		foreach ( $tanks as $tank ) {
			foreach ( (array) ( $tank['todo'] ?? array() ) as $item ) {
				if ( 'feed' !== (string) ( $item['kind'] ?? '' ) ) {
					continue;
				}
				$key = (string) $item['key'];
				if ( ! isset( $lines[ $key ] ) ) {
					$lines[ $key ] = array(
						'key'         => $key,
						'label'       => (string) $item['label'],
						'kind'        => 'feed',
						'note'        => (string) ( $item['note'] ?? '' ),
						'medication'  => '',
						'dose'        => '',
						'days'        => array(),
						'for'         => '',
						'done'        => true,
						'scope'       => 'feed',
						'building_id' => (int) $building_id,
						'tank'        => 0,
						'tanks'       => array(),
						'count'       => 0,
						'left'        => 0,
					);
				}
				$done                      = ! empty( $item['done'] );
				$lines[ $key ]['tanks'][]  = array(
					'tank_id' => (int) $tank['id'],
					'number'  => (int) $tank['number'],
					'done'    => $done,
				);
				$lines[ $key ]['count']++;
				if ( ! $done ) {
					$lines[ $key ]['left']++;
					$lines[ $key ]['done'] = false;
				}
			}
		}
		return array_values( $lines );
	}

	/**
	 * The newest copper reading among a system's tanks, the tank it was
	 * taken on (0 = on the building itself) and how it stands against the
	 * floor.
	 *
	 * @param array[] $tanks Tanks ('copper' => { value, logged_at, tank_id } | null, 'id', 'number').
	 * @param float   $floor The copper floor.
	 * @return array{value: float|null, logged_at: string, tank: int, state: string}
	 */
	public static function system_copper( array $tanks, $floor ) {
		$best    = null;
		$numbers = array();
		foreach ( $tanks as $tank ) {
			$numbers[ (int) ( $tank['id'] ?? 0 ) ] = (int) ( $tank['number'] ?? 0 );
			$reading                                = isset( $tank['copper'] ) && is_array( $tank['copper'] ) ? $tank['copper'] : null;
			if ( $reading && ( null === $best || strcmp( (string) $reading['logged_at'], (string) $best['logged_at'] ) > 0 ) ) {
				$best = array(
					'value'     => (float) $reading['value'],
					'logged_at' => (string) $reading['logged_at'],
					'tank'      => array_key_exists( 'tank_id', $reading ) ? (int) $reading['tank_id'] : (int) ( $tank['id'] ?? 0 ),
				);
			}
		}
		if ( null !== $best ) {
			$best['tank'] = isset( $numbers[ $best['tank'] ] ) ? $numbers[ $best['tank'] ] : 0;
		}
		if ( null === $best ) {
			return array(
				'value'     => null,
				'logged_at' => '',
				'tank'      => 0,
				'state'     => 'none',
			);
		}
		$best['state'] = Protocol::copper_state( $best['value'], (float) $floor );
		return $best;
	}

	/**
	 * The flag a block raises about its copper (null when there is none):
	 * 'low' — the last reading is under the floor, as the tank screen
	 * flags it — and / or 'due' — the copper test is due today and no
	 * reading has ticked it; 'overdue' when the last reading is two days
	 * old or more, or there never was one. Only a block whose list holds
	 * a copper test today can be flagged: a hospital tank on antibiotics,
	 * or a Clean Room building, is not "low" for having no copper.
	 *
	 * @param string $where   The block's name.
	 * @param array  $copper  system_copper().
	 * @param bool   $due     Whether a copper test is still open today.
	 * @param string $today   Site date.
	 * @param mixed  $tz      Site zone.
	 * @param bool   $matters Whether a copper test is on the block's list today, open or ticked.
	 * @return array|null where, low, due, overdue, value, logged_at, days_ago.
	 */
	public static function copper_flag( $where, array $copper, $due, $today, $tz = null, $matters = true ) {
		$low = $matters && 'low' === (string) $copper['state'];
		if ( ! $low && ! $due ) {
			return null;
		}
		$ago = null;
		if ( '' !== (string) $copper['logged_at'] ) {
			$date = Replay::event_date( (string) $copper['logged_at'], $tz );
			$n    = Replay::day_number( $date, (string) $today );
			$ago  = null === $n ? null : max( 0, $n - 1 );
		}
		return array(
			'where'     => (string) $where,
			'low'       => $low,
			'due'       => (bool) $due,
			'overdue'   => $due && ( null === $ago || $ago >= 2 ),
			'value'     => $copper['value'],
			'logged_at' => (string) $copper['logged_at'],
			'days_ago'  => $ago,
		);
	}

	/**
	 * Whether a list of items holds a copper test — still open, or
	 * (with $any) at all.
	 *
	 * @param array[] $items Items.
	 * @param bool    $any   Whether a ticked one counts too.
	 * @return bool
	 */
	private static function copper_due( array $items, $any = false ) {
		foreach ( $items as $item ) {
			if ( ( $any || empty( $item['done'] ) ) && Protocol::is_copper_test( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many items are still open.
	 *
	 * @param array[] $items Items.
	 * @return int
	 */
	private static function left( array $items ) {
		$n = 0;
		foreach ( $items as $item ) {
			if ( empty( $item['done'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The block of one shared system, or null when it has no fish.
	 *
	 * @param array   $building  Building row.
	 * @param array[] $tanks     Its tanks (occupants, todo, copper).
	 * @param array   $protocols Settings::protocols().
	 * @param array[] $rows      The day's log rows.
	 * @param string  $today     Site date.
	 * @param array   $settings  Settings (copper_floor).
	 * @return array|null
	 */
	public static function system_block( array $building, array $tanks, array $protocols, array $rows, $today, array $settings ) {
		$live = array();
		$days = array();
		$ids  = array();
		$fish = 0;
		foreach ( $tanks as $tank ) {
			$ids[]   = (int) $tank['id'];
			$has_any = false;
			foreach ( (array) ( $tank['occupants'] ?? array() ) as $stay ) {
				if ( (int) ( $stay['qty_live'] ?? 0 ) <= 0 ) {
					continue;
				}
				$has_any = true;
				$fish   += (int) $stay['qty_live'];
				if ( isset( $stay['day'] ) && null !== $stay['day'] ) {
					$days[] = (int) $stay['day'];
				}
			}
			if ( $has_any ) {
				$live[] = $tank;
			}
		}
		if ( ! $live ) {
			return null;
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );

		$area    = (string) ( $building['area'] ?? '' );
		$gallons = Layout::system_gallons( $building, $tanks );
		$pool    = self::pool( $rows, (int) $building['id'], $ids );
		$items   = array_merge(
			self::system_items( Protocol::steps_for( $building, $protocols ), $days, $area, $pool, $today, $gallons, (int) $building['id'] ),
			self::feed_lines( $live, (int) $building['id'] )
		);
		$label   = Layout::building_label( $building );
		$copper  = self::system_copper( $tanks, (float) ( $settings['copper_floor'] ?? 0 ) );

		return array(
			'type'        => 'system',
			'building_id' => (int) $building['id'],
			'number'      => (int) $building['number'],
			'area'        => $area,
			'label'       => $label,
			'title'       => Layout::area_label( $area ) . ' · ' . $label,
			'gallons'     => $gallons,
			'schedule'    => Protocol::template_for( $building, $protocols ),
			'days'        => $days,
			'day_text'    => self::days_text( $days ),
			'tanks'       => count( $live ),
			'fish'        => $fish,
			'items'       => $items,
			'left'        => self::left( $items ),
			'copper'      => $copper,
			'flag'        => self::copper_flag( $label, $copper, self::copper_due( $items ), $today, Clean_Room::timezone(), self::copper_due( $items, true ) ),
		);
	}

	/**
	 * The block of one temporary tank that is set up: its own water, its
	 * own schedule, its own clock. Its lines are the tank's own to-do.
	 *
	 * @param array  $tank     Hydrated slot (setup, occupants, todo, copper).
	 * @param string $today    Site date.
	 * @param array  $settings Settings (copper_floor).
	 * @return array
	 */
	public static function temp_block( array $tank, $today, array $settings ) {
		$setup   = (array) $tank['setup'];
		$gallons = (int) ( $setup['gallons'] ?? 0 );
		$fish    = 0;
		$groups  = 0;
		foreach ( (array) ( $tank['occupants'] ?? array() ) as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) > 0 ) {
				$fish += (int) $stay['qty_live'];
				$groups++;
			}
		}

		$items = array();
		foreach ( (array) ( $tank['todo'] ?? array() ) as $raw ) {
			$names   = self::names( (string) $raw['label'], (string) $raw['kind'], $gallons );
			$items[] = array(
				'key'         => (string) $raw['key'],
				'label'       => (string) $raw['label'],
				'kind'        => (string) $raw['kind'],
				'note'        => (string) ( $raw['note'] ?? '' ),
				'medication'  => $names['medication'],
				'dose'        => $names['dose'],
				'days'        => null !== ( $raw['day'] ?? null ) ? array( (int) $raw['day'] ) : array(),
				'for'         => '',
				'done'        => ! empty( $raw['done'] ),
				'scope'       => 'tank',
				'building_id' => (int) $tank['building_id'],
				'tank'        => (int) $tank['number'],
			);
		}

		$label  = Layout::tank_label( $tank );
		$name   = trim( (string) ( $setup['name'] ?? '' ) );
		$copper = self::system_copper( array( $tank ), (float) ( $settings['copper_floor'] ?? 0 ) );
		$day    = Temp_Tanks::day( $setup, $today );

		return array(
			'type'        => 'temp',
			'building_id' => (int) $tank['building_id'],
			'tank_id'     => (int) $tank['id'],
			'number'      => (int) $tank['number'],
			'slot'        => Layout::slot_of( $tank ),
			'setup_id'    => (int) ( $setup['id'] ?? 0 ),
			'area'        => Layout::TEMP_AREA,
			'label'       => $label,
			'title'       => $label . ( '' !== $name ? ' · ' . $name : '' ),
			'name'        => $name,
			'kind'        => Temp_Tanks::kind( $setup['kind'] ?? '' ),
			'kind_label'  => Temp_Tanks::kind_label( $setup['kind'] ?? '' ),
			'purpose'     => (string) ( $setup['purpose'] ?? '' ),
			'gallons'     => $gallons,
			'schedule'    => Temp_Tanks::schedule_label( $setup ),
			'days'        => null !== $day && $day >= 1 ? array( $day ) : array(),
			'day_text'    => Temp_Tanks::day_text( $setup, $today ),
			'tanks'       => $fish > 0 ? 1 : 0,
			'fish'        => $fish,
			'groups'      => $groups,
			'items'       => $items,
			'left'        => self::left( $items ),
			'copper'      => $copper,
			'flag'        => self::copper_flag( $label . ( '' !== $name ? ' · ' . $name : '' ), $copper, self::copper_due( $items ), $today, Clean_Room::timezone(), self::copper_due( $items, true ) ),
		);
	}

	/**
	 * Everything due today, by water system.
	 *
	 * @param array[] $buildings Buildings (id, area, number, name, status, schedule, system_gallons, own_water).
	 * @param array[] $tanks     Every tank with its occupants, its own to-do, its last copper reading and —
	 *                           a slot — its setup.
	 * @param array   $protocols Settings::protocols().
	 * @param array[] $rows      The day's log rows (tank_id, building_id, kind, value, note, setup_id).
	 * @param string  $today     Site date.
	 * @param array   $settings  Settings (copper_floor).
	 * @return array{date: string, blocks: array[], left: int, total: int, all_done: bool, flags: array[]}
	 */
	public static function build( array $buildings, array $tanks, array $protocols, array $rows, $today, array $settings = array() ) {
		$by_building = array();
		foreach ( $tanks as $tank ) {
			if ( 'offline' === (string) ( $tank['status'] ?? 'active' ) ) {
				continue;
			}
			$by_building[ (int) ( $tank['building_id'] ?? 0 ) ][] = $tank;
		}

		$quarantine = array();
		$temporary  = array();
		$clean      = array();
		foreach ( Layout::sort_buildings( $buildings ) as $building ) {
			if ( 'active' !== (string) ( $building['status'] ?? 'active' ) ) {
				continue; // Coming soon, or offline: nothing lives there.
			}
			$mine = isset( $by_building[ (int) $building['id'] ] ) ? $by_building[ (int) $building['id'] ] : array();

			if ( Layout::is_own_water( $building ) ) {
				foreach ( Temp_Tanks::slots( $mine ) as $tank ) {
					if ( ! empty( $tank['setup'] ) ) {
						$temporary[] = self::temp_block( $tank, $today, $settings );
					}
				}
				continue;
			}

			$block = self::system_block( $building, $mine, $protocols, $rows, $today, $settings );
			if ( 'spa' === (string) $building['area'] ) {
				// Every quarantine system has its block, with fish or without.
				$quarantine[] = null !== $block ? $block : self::empty_block( $building, $mine, $protocols, $settings );
			} elseif ( null !== $block && $block['items'] ) {
				$clean[] = $block;
			}
		}

		$blocks = array_merge( $quarantine, $temporary, $clean );
		$left   = 0;
		$total  = 0;
		$flags  = array();
		foreach ( $blocks as $block ) {
			$left  += (int) $block['left'];
			$total += count( $block['items'] );
			if ( ! empty( $block['flag'] ) ) {
				$flags[] = $block['flag'];
			}
		}

		return array(
			'date'     => (string) $today,
			'blocks'   => $blocks,
			'left'     => $left,
			'total'    => $total,
			'all_done' => $total > 0 && 0 === $left,
			'flags'    => $flags,
		);
	}

	/**
	 * A quarantine system with no fish in it: its block still stands, so
	 * the list shows the whole room.
	 *
	 * @param array   $building  Building.
	 * @param array[] $tanks     Its tanks.
	 * @param array   $protocols Settings::protocols().
	 * @param array   $settings  Settings.
	 * @return array
	 */
	private static function empty_block( array $building, array $tanks, array $protocols, array $settings ) {
		$area  = (string) ( $building['area'] ?? '' );
		$label = Layout::building_label( $building );
		return array(
			'type'        => 'system',
			'building_id' => (int) $building['id'],
			'number'      => (int) $building['number'],
			'area'        => $area,
			'label'       => $label,
			'title'       => Layout::area_label( $area ) . ' · ' . $label,
			'gallons'     => Layout::system_gallons( $building, $tanks ),
			'schedule'    => Protocol::template_for( $building, $protocols ),
			'days'        => array(),
			'day_text'    => '',
			'tanks'       => 0,
			'fish'        => 0,
			'items'       => array(),
			'left'        => 0,
			'copper'      => self::system_copper( $tanks, (float) ( $settings['copper_floor'] ?? 0 ) ),
			'flag'        => null,
		);
	}

	/**
	 * One truth: every step the system has done is done on each tank of
	 * that building too — whichever tank, or the list, it was ticked on.
	 * A slot keeps its own to-do: it shares water with nobody.
	 *
	 * @param array[] $tanks Tanks with their own to-do.
	 * @param array   $list  build().
	 * @return array[]
	 */
	public static function overlay( array $tanks, array $list ) {
		$done = array();
		foreach ( (array) ( $list['blocks'] ?? array() ) as $block ) {
			if ( 'system' !== (string) $block['type'] ) {
				continue;
			}
			foreach ( $block['items'] as $item ) {
				if ( 'system' === (string) $item['scope'] && ! empty( $item['done'] ) ) {
					$done[ (int) $block['building_id'] ][ (string) $item['key'] ] = true;
				}
			}
		}
		if ( ! $done ) {
			return $tanks;
		}

		foreach ( $tanks as $t => $tank ) {
			$building = (int) ( $tank['building_id'] ?? 0 );
			if ( ! isset( $done[ $building ] ) || Layout::is_slot( $tank ) ) {
				continue;
			}
			foreach ( (array) ( $tank['todo'] ?? array() ) as $i => $item ) {
				if ( 'feed' !== (string) $item['kind'] && empty( $item['done'] ) && isset( $done[ $building ][ Protocol::tick_key( (string) $item['label'] ) ] ) ) {
					$tanks[ $t ]['todo'][ $i ]['done'] = true;
				}
			}
		}

		return $tanks;
	}

	/* ============================================================== */
	/*  Ticking a line                                                 */
	/* ============================================================== */

	/**
	 * Tick one line of the list: `scope` system — once for the whole
	 * building (its key on the building's log; every tank of it then
	 * shows it done) — or tank — one tank's own line (a temporary tank's
	 * step, or one tank's feeding). The caller has checked the capability
	 * and the nonce.
	 *
	 * @param object $store Store (or stand-in) with building(), tank_by_number(), log_building(), log_tank().
	 * @param array  $raw   scope (system|tank), building (id) or tick_tank (the tank's number — not `tank`,
	 *                      which is the page the phone is on), key, kind, label.
	 * @param array  $base  actor_id, source.
	 * @return array message, event_ids — or error.
	 */
	public static function tick( $store, array $raw, array $base ) {
		$key   = Protocol::tick_key( isset( $raw['key'] ) && is_scalar( $raw['key'] ) ? substr( sanitize_text_field( (string) $raw['key'] ), 0, 160 ) : '' );
		$kind  = isset( $raw['kind'] ) && is_scalar( $raw['kind'] ) ? sanitize_key( (string) $raw['kind'] ) : '';
		$label = isset( $raw['label'] ) && is_scalar( $raw['label'] ) ? substr( sanitize_text_field( (string) $raw['label'] ), 0, 80 ) : '';
		$scope = isset( $raw['scope'] ) && is_scalar( $raw['scope'] ) ? sanitize_key( (string) $raw['scope'] ) : '';

		if ( '' === $key ) {
			return array( 'error' => __( 'Nothing to tick.', 'fishotel-misc-plugin' ) );
		}
		if ( ! in_array( $kind, self::TICK_KINDS, true ) ) {
			$kind = 'other';
		}
		$log = array_merge(
			$base,
			array(
				'kind' => $kind,
				'note' => $key,
			)
		);

		if ( 'system' === $scope ) {
			$building = $store->building( isset( $raw['building'] ) && is_scalar( $raw['building'] ) ? (int) $raw['building'] : 0 );
			if ( ! $building || 'active' !== (string) $building['status'] ) {
				return array( 'error' => __( 'That building is not in the layout.', 'fishotel-misc-plugin' ) );
			}
			if ( Layout::is_own_water( $building ) ) {
				return array( 'error' => __( 'A temporary tank is its own water — tick it on that tank.', 'fishotel-misc-plugin' ) );
			}
			if ( ! $store->log_building( (int) $building['id'], $log ) ) {
				return array( 'error' => __( 'That could not be saved.', 'fishotel-misc-plugin' ) );
			}
			return array(
				/* translators: 1: to-do item, 2: building label */
				'message'   => sprintf( __( 'Done: %1$s · %2$s, whole system', 'fishotel-misc-plugin' ), '' !== $label ? $label : $key, Layout::building_label( $building ) ),
				'event_ids' => array(),
			);
		}

		$tank = $store->tank_by_number( isset( $raw['tick_tank'] ) && is_scalar( $raw['tick_tank'] ) ? (int) $raw['tick_tank'] : 0 );
		if ( ! $tank || 'offline' === (string) $tank['status'] ) {
			return array( 'error' => __( 'That tank is not in the layout.', 'fishotel-misc-plugin' ) );
		}
		$refused = Temp_Tanks::destination_error( $tank );
		if ( '' !== $refused ) {
			return array( 'error' => $refused );
		}
		if ( ! $store->log_tank( (int) $tank['id'], $log ) ) {
			return array( 'error' => __( 'That could not be saved.', 'fishotel-misc-plugin' ) );
		}
		return array(
			/* translators: 1: to-do item, 2: tank label */
			'message'   => sprintf( __( 'Done: %1$s · %2$s', 'fishotel-misc-plugin' ), '' !== $label ? $label : self::key_label( $key ), Layout::tank_label( $tank ) ),
			'event_ids' => array(),
		);
	}

	/* ============================================================== */
	/*  The count other sections read                                  */
	/* ============================================================== */

	/**
	 * "Tanks: 3 things to do" / "Tanks: all done" / "Tanks: nothing due
	 * today" — the one line another screen needs.
	 *
	 * @param array $list build().
	 * @return string
	 */
	public static function headline( array $list ) {
		$left  = (int) ( $list['left'] ?? 0 );
		$total = (int) ( $list['total'] ?? 0 );
		if ( 0 === $total ) {
			return __( 'Tanks: nothing due today', 'fishotel-misc-plugin' );
		}
		if ( 0 === $left ) {
			return __( 'Tanks: all done', 'fishotel-misc-plugin' );
		}
		/* translators: %d: things left to do */
		return sprintf( _n( 'Tanks: %d thing to do', 'Tanks: %d things to do', $left, 'fishotel-misc-plugin' ), $left );
	}

	/**
	 * The one line the Today section shows (pure): "Tanks: 3 things to
	 * do" with the copper flags beside it and a link to the list, "Tanks:
	 * all done" once everything is ticked — and null on a day with
	 * nothing due at all, so a room without fish adds no line.
	 *
	 * @param array $summary summary().
	 * @return array{key: string, text: string, detail: string, url: string, count: int, tone: string}|null
	 */
	public static function today_line( array $summary ) {
		if ( (int) ( $summary['total'] ?? 0 ) <= 0 ) {
			return null;
		}
		$flags = (int) ( $summary['flags'] ?? 0 );
		$left  = (int) ( $summary['left'] ?? 0 );
		return array(
			'key'    => self::TODAY_LINE_KEY,
			'text'   => (string) ( $summary['text'] ?? '' ),
			/* translators: %d: copper flags */
			'detail' => $flags > 0 ? sprintf( _n( '%d copper flag', '%d copper flags', $flags, 'fishotel-misc-plugin' ), $flags ) : '',
			'url'    => (string) ( $summary['url'] ?? '' ),
			'count'  => $left,
			'tone'   => $flags > 0 ? 'bad' : ( $left > 0 ? 'todo' : 'done' ),
		);
	}

	/**
	 * The Today section's lines with this section's one added (pure): a
	 * line already there under the same key is replaced, never doubled.
	 *
	 * @param mixed      $lines What the filter carries so far.
	 * @param array|null $line  today_line(), or null for none.
	 * @return array[]
	 */
	public static function with_today_line( $lines, $line ) {
		$out = array();
		foreach ( is_array( $lines ) ? $lines : array() as $existing ) {
			if ( is_array( $existing ) && self::TODAY_LINE_KEY === (string) ( $existing['key'] ?? '' ) ) {
				continue;
			}
			$out[] = $existing;
		}
		if ( is_array( $line ) ) {
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * What the count is offered as: how many things are left and in all,
	 * the copper flags, the one line and where the list is.
	 *
	 * @param array  $list build().
	 * @param string $url  The Board's To do tab.
	 * @return array{left: int, total: int, all_done: bool, flags: int, text: string, url: string}
	 */
	public static function summary( array $list, $url = '' ) {
		return array(
			'left'     => (int) ( $list['left'] ?? 0 ),
			'total'    => (int) ( $list['total'] ?? 0 ),
			'all_done' => ! empty( $list['all_done'] ),
			'flags'    => count( (array) ( $list['flags'] ?? array() ) ),
			'text'     => self::headline( $list ),
			'url'      => (string) $url,
		);
	}
}
