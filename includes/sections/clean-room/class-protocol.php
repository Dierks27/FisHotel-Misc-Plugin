<?php
/**
 * Clean Room protocol — PURE: the treatment schedule as an editable
 * template (steps with a day offset, a label, a kind, an optional repeat
 * and an optional "where"), and the daily to-do it generates for a tank
 * from the stays in it.
 *
 * Nothing about the schedule is hard-coded: the seed below is only the
 * rough version Jeff described (about six Metronidazole treatments,
 * fourteen days of copper held at 2.25 or higher with 2.5 the target,
 * two Praziquantel treatments for flukes) and is meant to be replaced by
 * the workbook's schedule in Settings → Protocol.
 *
 * Day numbers follow Replay::day_number(): day 1 is the check-in day.
 * A step is due on day d when d == day, or when it repeats (every > 0)
 * and d >= day, (d − day) % every == 0 and d <= until (when set).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Protocol
 */
class Protocol {

	/**
	 * Option holding the template (Settings reads / writes it).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_cleanroom_protocol';

	const KINDS  = array( 'feed', 'dose', 'test', 'water_change', 'other' );
	const WHERES = array( 'any', 'spa', 'clean' );

	const MAX_STEPS = 60;
	const MAX_DAY   = 120;

	/**
	 * The rough seed — to be replaced by the workbook's schedule.
	 *
	 * @return array[]
	 */
	public static function seed() {
		return array(
			self::step( 'Feed', 'feed', 1, 1, null, 'any', 'every tank with fish, every day' ),
			self::step( 'Start copper', 'dose', 1, 0, null, 'spa', 'bring copper to 2.5 (hold at 2.25 or higher)' ),
			self::step( 'Copper test', 'test', 2, 1, 14, 'spa', 'hold at 2.25 or higher, target 2.5 — log it: "521 copper 2.4"' ),
			self::step( 'Metronidazole', 'dose', 1, 2, 11, 'spa', 'six treatments, every other day' ),
			self::step( 'Copper off — water change', 'water_change', 14, 0, null, 'spa', 'copper comes off after 14 days; ready to move to the Clean Room' ),
			self::step( 'Praziquantel 1', 'dose', 15, 0, null, 'any', 'flukes, first treatment' ),
			self::step( 'Praziquantel 2', 'dose', 22, 0, null, 'any', 'flukes, second treatment' ),
		);
	}

	/**
	 * Whether a step is the copper test — a 'test' step whose label names
	 * copper (or Cu). A copper reading logged today ticks it off, so the
	 * chip does not stay open after "521 copper 2.4".
	 *
	 * @param array $step Step.
	 * @return bool
	 */
	public static function is_copper_test( array $step ) {
		return 'test' === (string) ( $step['kind'] ?? '' ) && 1 === preg_match( '/copper|\bcu\b/i', (string) ( $step['label'] ?? '' ) );
	}

	/**
	 * One step.
	 *
	 * @param string   $label Label.
	 * @param string   $kind  feed|dose|test|water_change|other.
	 * @param int      $day   First day (1 = check-in day).
	 * @param int      $every Repeat every N days (0 = once).
	 * @param int|null $until Last day of the repeat (null = open).
	 * @param string   $where any|spa|clean.
	 * @param string   $note  Hint.
	 * @return array
	 */
	public static function step( $label, $kind, $day, $every = 0, $until = null, $where = 'any', $note = '' ) {
		return array(
			'label' => (string) $label,
			'kind'  => (string) $kind,
			'day'   => (int) $day,
			'every' => (int) $every,
			'until' => null === $until ? null : (int) $until,
			'where' => (string) $where,
			'note'  => (string) $note,
		);
	}

	/**
	 * Clean a raw template (from the settings form or the option): every
	 * step typed and clamped, empty labels dropped, at most MAX_STEPS.
	 *
	 * @param mixed $raw Steps.
	 * @return array[]
	 */
	public static function normalize( $raw ) {
		$out = array();
		foreach ( (array) $raw as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$label = isset( $step['label'] ) && is_scalar( $step['label'] ) ? trim( (string) $step['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$kind  = isset( $step['kind'] ) && in_array( (string) $step['kind'], self::KINDS, true ) ? (string) $step['kind'] : 'other';
			$where = isset( $step['where'] ) && in_array( (string) $step['where'], self::WHERES, true ) ? (string) $step['where'] : 'any';
			$day   = isset( $step['day'] ) && is_numeric( $step['day'] ) ? (int) $step['day'] : 1;
			$day   = max( 1, min( self::MAX_DAY, $day ) );
			$every = isset( $step['every'] ) && is_numeric( $step['every'] ) ? (int) $step['every'] : 0;
			$every = max( 0, min( 60, $every ) );
			$until = isset( $step['until'] ) && is_numeric( $step['until'] ) && '' !== (string) $step['until'] ? (int) $step['until'] : null;
			if ( null !== $until ) {
				$until = max( $day, min( self::MAX_DAY, $until ) );
			}
			if ( 0 === $every ) {
				$until = null;
			}
			$note  = isset( $step['note'] ) && is_scalar( $step['note'] ) ? trim( (string) $step['note'] ) : '';
			$out[] = self::step( substr( $label, 0, 80 ), $kind, $day, $every, $until, $where, substr( $note, 0, 200 ) );
			if ( count( $out ) >= self::MAX_STEPS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether a step is due on a day number.
	 *
	 * @param array $step Step.
	 * @param int   $day  Day number.
	 * @return bool
	 */
	public static function is_due( array $step, $day ) {
		$day   = (int) $day;
		$start = (int) ( $step['day'] ?? 1 );
		$every = (int) ( $step['every'] ?? 0 );
		$until = isset( $step['until'] ) && null !== $step['until'] ? (int) $step['until'] : null;

		if ( $day < 1 || $day < $start ) {
			return false;
		}
		if ( $day === $start ) {
			return true;
		}
		if ( $every <= 0 ) {
			return false;
		}
		if ( null !== $until && $day > $until ) {
			return false;
		}
		return 0 === ( $day - $start ) % $every;
	}

	/**
	 * Whether a step applies in an area.
	 *
	 * @param array  $step Step.
	 * @param string $area clean|spa.
	 * @return bool
	 */
	public static function applies( array $step, $area ) {
		$where = (string) ( $step['where'] ?? 'any' );
		return 'any' === $where || '' === (string) $area || $where === (string) $area;
	}

	/**
	 * The steps due on a day in an area, template order.
	 *
	 * @param array[] $steps Template.
	 * @param int     $day   Day number.
	 * @param string  $area  clean|spa ('' = any).
	 * @return array[]
	 */
	public static function due( array $steps, $day, $area = '' ) {
		$out = array();
		foreach ( $steps as $step ) {
			if ( self::is_due( $step, $day ) && self::applies( $step, $area ) ) {
				$out[] = $step;
			}
		}
		return $out;
	}

	/**
	 * The to-do of one tank today: feeding once per tank with live fish
	 * (kind 'feed', regardless of day), then every other due step per
	 * stay. Each item carries a stable key so a tank log can tick it off.
	 *
	 * @param array[] $steps  Template.
	 * @param array[] $stays  The tank's open stays (id, species, qty_live, checkin_date, day).
	 * @param string  $today  Site date.
	 * @param string  $area   The tank's area.
	 * @param array[] $done   Tank logs of today (kind, note): a tick-off's note is the item
	 *                        key; a 'copper' log ticks the copper test by itself.
	 * @return array[] key, label, kind, note, stay_id, species, day, done.
	 */
	public static function todo( array $steps, array $stays, $today, $area = '', array $done = array() ) {
		$out    = array();
		$ticked = array();
		$copper = false;
		foreach ( $done as $log ) {
			$ticked[ self::tick_key( (string) ( $log['note'] ?? '' ) ) ] = true;
			if ( 'copper' === (string) ( $log['kind'] ?? '' ) ) {
				$copper = true;
			}
		}

		$live = array();
		foreach ( $stays as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) > 0 ) {
				$live[] = $stay;
			}
		}
		if ( ! $live ) {
			return $out;
		}

		foreach ( $steps as $step ) {
			if ( 'feed' !== (string) ( $step['kind'] ?? '' ) || ! self::applies( $step, $area ) ) {
				continue;
			}
			$label = (string) $step['label'];
			$out[] = array(
				'key'     => self::tick_key( $label ),
				'label'   => $label,
				'kind'    => 'feed',
				'note'    => (string) ( $step['note'] ?? '' ),
				'stay_id' => null,
				'species' => '',
				'day'     => null,
				'done'    => isset( $ticked[ self::tick_key( $label ) ] ),
			);
		}

		foreach ( $live as $stay ) {
			$day = isset( $stay['day'] ) && null !== $stay['day'] ? (int) $stay['day'] : Replay::day_number( (string) ( $stay['checkin_date'] ?? '' ), (string) $today );
			if ( null === $day ) {
				continue;
			}
			foreach ( self::due( $steps, $day, $area ) as $step ) {
				if ( 'feed' === (string) $step['kind'] ) {
					continue;
				}
				$label = (string) $step['label'];
				$key   = self::tick_key( $label . ' #' . (int) ( $stay['id'] ?? 0 ) );
				$out[] = array(
					'key'     => $key,
					'label'   => $label,
					'kind'    => (string) $step['kind'],
					'note'    => (string) ( $step['note'] ?? '' ),
					'stay_id' => (int) ( $stay['id'] ?? 0 ),
					'species' => (string) ( $stay['species'] ?? '' ),
					'day'     => $day,
					'done'    => isset( $ticked[ $key ] ) || ( $copper && self::is_copper_test( $step ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * The note a tick-off log carries, and the key both sides compare.
	 *
	 * @param string $label Step label (+ ' #stay' for a per-stay step).
	 * @return string
	 */
	public static function tick_key( $label ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $label ) ) );
	}

	/**
	 * ok / low / none for a copper reading against the floor.
	 *
	 * @param float|null $reading Last reading (null = none).
	 * @param float      $floor   Minimum (2.25).
	 * @return string
	 */
	public static function copper_state( $reading, $floor ) {
		if ( null === $reading || ! is_numeric( $reading ) ) {
			return 'none';
		}
		return (float) $reading + 0.0001 >= (float) $floor ? 'ok' : 'low';
	}

	/**
	 * "Copper test · test · days 2–14, daily · Spa".
	 *
	 * @param array $step Step.
	 * @return string
	 */
	public static function describe( array $step ) {
		$day   = (int) ( $step['day'] ?? 1 );
		$every = (int) ( $step['every'] ?? 0 );
		$until = isset( $step['until'] ) && null !== $step['until'] ? (int) $step['until'] : null;

		if ( $every <= 0 ) {
			$when = 'day ' . $day;
		} else {
			$when = 'days ' . $day . '–' . ( null === $until ? '…' : $until ) . ', ' . ( 1 === $every ? 'daily' : 'every ' . $every . ' days' );
		}
		$where = (string) ( $step['where'] ?? 'any' );
		$place = 'any' === $where ? 'anywhere' : Layout::area_label( $where );

		return (string) ( $step['label'] ?? '' ) . ' · ' . str_replace( '_', ' ', (string) ( $step['kind'] ?? 'other' ) ) . ' · ' . $when . ' · ' . $place;
	}
}
