<?php
/**
 * Clean Room replay — PURE: a stay's events, in order, → its current
 * state. The stays table only caches what this computes; nothing is ever
 * edited in place and nothing is ever deleted — an undo is a compensating
 * event (compensating()).
 *
 * The rules, as Jeff runs the room:
 *   - every arrival lands in a Spa (QT) tank; day 1 is the check-in day,
 *     day number = floor( (today − checkin) / 86400 ) + 1 over the two
 *     calendar dates (never stored, untouched by DST);
 *   - alive = received − losses − sold/shipped; a 'count' (full recount)
 *     overrides, because fish hide and Jeff counts bodies, not heads;
 *   - 'cleared' is the day a stay first MOVES from a Spa tank (or an
 *     unknown one) into a Clean Room tank (the day it comes off copper); a
 *     'clear' event sets or corrects that date by hand — the date in its
 *     note ("cleared:YYYY-MM-DD"), else the event's own date — and a clear
 *     with qty −1 unsets it;
 *   - the check-in date is the SITE date of the first arrival, corrected
 *     by a note event "checkin:YYYY-MM-DD";
 *   - sale-ready = N days after check-in (N = the sale_ready_days setting,
 *     default 28 — Jeff offers a group for sale after the full four weeks),
 *     or the stay's own override (a note event "sale_ready:YYYY-MM-DD";
 *     "sale_ready:" clears it); a stay is ready only once it is cleared
 *     AND that day has come;
 *   - a move with a quantity smaller than the group splits it: that many
 *     leave this stay (the Store creates the sister stay), the rest stay
 *     put; a negative quantity is the undo of a split;
 *   - qty_live reaching 0 closes the stay (closed_date = that event's
 *     site date); a later compensating event re-opens it;
 *   - a negative qty on arrival / loss / sold reverses that much;
 *   - qty_live is never reported below 0, but 'overdrawn' says when the
 *     events add up to less (two phones recorded a loss against the same
 *     count) so the screen can ask for a recount.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Replay
 */
class Replay {

	/**
	 * Event types the table accepts.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'arrival', 'move', 'loss', 'clear', 'sold', 'count', 'note' );

	/**
	 * Stay statuses (stored).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'in_qt', 'cleared', 'closed' );

	/**
	 * Default day number on which a stay is sale-ready.
	 *
	 * @var int
	 */
	const DEFAULT_SALE_READY_DAYS = 28;

	/**
	 * A note event starting with this sets (or, with nothing after it,
	 * clears) the stay's sale-ready override.
	 *
	 * @var string
	 */
	const NOTE_SALE_READY = 'sale_ready:';

	/**
	 * A clear event whose note starts with this carries the cleared date
	 * (the event itself is recorded when the date was typed, so a later
	 * correction always wins — even to an earlier date).
	 *
	 * @var string
	 */
	const NOTE_CLEARED = 'cleared:';

	/**
	 * A note event starting with this corrects the check-in date (with
	 * nothing after it: back to the first arrival's date).
	 *
	 * @var string
	 */
	const NOTE_CHECKIN = 'checkin:';

	/**
	 * The note a compensating event carries: 'undo of #<id>'.
	 *
	 * @var string
	 */
	const NOTE_UNDO = 'undo of #';

	/**
	 * The area whose tanks mean "cleared" when a stay moves into them.
	 *
	 * @var string
	 */
	const CLEAN_AREA = 'clean';

	/* ============================================================== */
	/*  Dates                                                          */
	/* ============================================================== */

	/**
	 * Y-m-d → true when it is a real calendar date.
	 *
	 * @param mixed $ymd Date.
	 * @return bool
	 */
	public static function is_date( $ymd ) {
		if ( ! is_string( $ymd ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The calendar date of a UTC timestamp in a zone.
	 *
	 * @param int                      $timestamp UNIX timestamp.
	 * @param \DateTimeZone|string|null $tz       Zone (null = UTC).
	 * @return string Y-m-d
	 */
	public static function date_in_zone( $timestamp, $tz = null ) {
		try {
			$zone = $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( is_string( $tz ) && '' !== $tz ? $tz : 'UTC' );
			$dt   = new \DateTimeImmutable( '@' . (int) $timestamp );
			return $dt->setTimezone( $zone )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return gmdate( 'Y-m-d', (int) $timestamp );
		}
	}

	/**
	 * The site date of an event_at (UTC 'Y-m-d H:i:s').
	 *
	 * @param string                   $event_at UTC datetime.
	 * @param \DateTimeZone|string|null $tz      Zone.
	 * @return string Y-m-d ('' when unparseable)
	 */
	public static function event_date( $event_at, $tz = null ) {
		$ts = is_string( $event_at ) && '' !== $event_at ? strtotime( $event_at . ' UTC' ) : false;
		if ( false === $ts ) {
			return '';
		}
		return self::date_in_zone( (int) $ts, $tz );
	}

	/**
	 * Day number: floor( (today − checkin) / 86400 ) + 1 over the two
	 * calendar dates (both read as UTC midnights, so a DST change between
	 * them changes nothing). Day 1 is the check-in day.
	 *
	 * @param string $checkin Y-m-d.
	 * @param string $today   Y-m-d.
	 * @return int|null Null when either date is not a date.
	 */
	public static function day_number( $checkin, $today ) {
		if ( ! self::is_date( $checkin ) || ! self::is_date( $today ) ) {
			return null;
		}
		$from = (int) strtotime( $checkin . ' 00:00:00 UTC' );
		$to   = (int) strtotime( $today . ' 00:00:00 UTC' );

		return (int) floor( ( $to - $from ) / 86400 ) + 1;
	}

	/**
	 * A date plus N calendar days (UTC date math, DST-proof).
	 *
	 * @param string $ymd  Y-m-d.
	 * @param int    $days Days (may be negative).
	 * @return string Y-m-d ('' when the input is not a date)
	 */
	public static function add_days( $ymd, $days ) {
		if ( ! self::is_date( $ymd ) ) {
			return '';
		}
		return gmdate( 'Y-m-d', (int) strtotime( $ymd . ' 00:00:00 UTC' ) + (int) $days * 86400 );
	}

	/**
	 * The sale-ready date: the stay's override when set, else N days after
	 * check-in (check-in + N: the full N days have passed).
	 *
	 * @param string      $checkin  Y-m-d.
	 * @param string|null $override Y-m-d or null.
	 * @param int         $days     N (the sale_ready_days setting).
	 * @return string Y-m-d ('' when there is no check-in)
	 */
	public static function sale_ready_date( $checkin, $override, $days = self::DEFAULT_SALE_READY_DAYS ) {
		if ( self::is_date( $override ) ) {
			return (string) $override;
		}
		return self::add_days( $checkin, max( 1, (int) $days ) );
	}

	/**
	 * The date carried by a note of the form "<prefix>YYYY-MM-DD".
	 *
	 * @param string $note   The note.
	 * @param string $prefix NOTE_CLEARED / NOTE_CHECKIN / NOTE_SALE_READY.
	 * @return string Y-m-d ('' when the note has no (valid) date).
	 */
	public static function note_date( $note, $prefix ) {
		$note = (string) $note;
		if ( '' === $prefix || 0 !== strpos( $note, $prefix ) ) {
			return '';
		}
		$value = trim( substr( $note, strlen( $prefix ) ) );
		return self::is_date( $value ) ? $value : '';
	}

	/* ============================================================== */
	/*  Events → state                                                 */
	/* ============================================================== */

	/**
	 * Events in the order they happened: event_at, then id.
	 *
	 * @param array[] $events Events.
	 * @return array[]
	 */
	public static function sort_events( array $events ) {
		$events = array_values( $events );
		usort(
			$events,
			static function ( $a, $b ) {
				$t = strcmp( (string) ( $a['event_at'] ?? '' ), (string) ( $b['event_at'] ?? '' ) );
				return 0 !== $t ? $t : (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
			}
		);
		return $events;
	}

	/**
	 * Rebuild a stay's state from its events.
	 *
	 * @param array   $stay   The stay row (checkin_date as a fallback, tank_id as a fallback).
	 * @param array[] $events Its events (any order).
	 * @param string  $today  Site date Y-m-d.
	 * @param array   $opts   sale_ready_days (int, default 28),
	 *                        tz (DateTimeZone|string|null) for event dates,
	 *                        tank_areas (array tank_id => 'clean'|'spa') to spot a move into the Clean Room.
	 * @return array{
	 *   qty_arrived: int, qty_live: int, qty_lost: int, qty_sold: int, overdrawn: bool,
	 *   checkin_date: string, cleared_date: string|null, closed_date: string|null,
	 *   sale_ready_override: string|null, sale_ready_date: string, sale_ready: bool,
	 *   tank_id: int|null, status: string, stored_status: string, day: int|null,
	 *   state_label: string, last_event_at: string
	 * }
	 */
	public static function state( array $stay, array $events, $today, array $opts = array() ) {
		$days  = max( 1, (int) ( $opts['sale_ready_days'] ?? self::DEFAULT_SALE_READY_DAYS ) );
		$tz    = $opts['tz'] ?? null;
		$areas = isset( $opts['tank_areas'] ) && is_array( $opts['tank_areas'] ) ? $opts['tank_areas'] : array();

		$qty_arrived  = 0;
		$qty_live     = 0;
		$qty_lost     = 0;
		$qty_sold     = 0;
		$checkin      = self::is_date( $stay['checkin_date'] ?? null ) ? (string) $stay['checkin_date'] : '';
		$checkin_set  = null; // A "checkin:" note, which wins over the arrival's date.
		$cleared_date = null;
		$closed_date  = null;
		$override     = null;
		$tank_id      = isset( $stay['tank_id'] ) && (int) $stay['tank_id'] > 0 ? (int) $stay['tank_id'] : null;
		$had_arrival  = false;
		$last_at      = '';
		$first_seen   = false;

		foreach ( self::sort_events( $events ) as $event ) {
			$type = (string) ( $event['type'] ?? '' );
			$qty  = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
			$date = self::event_date( (string) ( $event['event_at'] ?? '' ), $tz );
			if ( '' !== (string) ( $event['event_at'] ?? '' ) ) {
				$last_at = (string) $event['event_at'];
			}

			switch ( $type ) {
				case 'arrival':
					$n            = null === $qty ? 0 : $qty;
					$qty_arrived += $n;
					$qty_live    += $n;
					if ( $n > 0 ) {
						$had_arrival = true;
						if ( ! $first_seen && '' !== $date ) {
							$checkin    = $date;
							$first_seen = true;
						}
						if ( ! empty( $event['to_tank_id'] ) ) {
							$tank_id = (int) $event['to_tank_id'];
						}
					}
					break;

				case 'loss':
					$n         = null === $qty ? 1 : $qty;
					$qty_live -= $n;
					$qty_lost += $n;
					break;

				case 'sold':
					$n         = null === $qty ? 1 : $qty;
					$qty_live -= $n;
					$qty_sold += $n;
					break;

				case 'count':
					if ( null !== $qty && $qty >= 0 ) {
						$qty_live = $qty;
					}
					break;

				case 'move':
					if ( null !== $qty && $qty < 0 ) {
						$qty_live += -$qty; // The undo of a split: they are back.
					} elseif ( null !== $qty && $qty > 0 && $qty < $qty_live ) {
						$qty_live -= $qty; // A split: that many continue in a sister stay.
					} elseif ( ! empty( $event['to_tank_id'] ) ) {
						// The first move INTO the Clean Room clears — but only out
						// of the Spa (or an unknown tank): after a hand unset, a
						// Clean → Clean move must not re-clear.
						$from_area = ! empty( $event['from_tank_id'] ) ? (string) ( $areas[ (int) $event['from_tank_id'] ] ?? '' ) : '';
						$tank_id   = (int) $event['to_tank_id'];
						if ( null === $cleared_date && '' !== $date && self::CLEAN_AREA === (string) ( $areas[ $tank_id ] ?? '' ) && self::CLEAN_AREA !== $from_area ) {
							$cleared_date = $date;
						}
					}
					break;

				case 'clear':
					if ( null !== $qty && $qty < 0 ) {
						$cleared_date = null;
					} else {
						// The date typed (in the note) wins; an old-shape event
						// without one is dated by its event_at.
						$typed = self::note_date( (string) ( $event['note'] ?? '' ), self::NOTE_CLEARED );
						if ( '' !== $typed ) {
							$cleared_date = $typed;
						} elseif ( '' !== $date ) {
							$cleared_date = $date;
						}
					}
					break;

				case 'note':
					$note = (string) ( $event['note'] ?? '' );
					if ( 0 === strpos( $note, self::NOTE_SALE_READY ) ) {
						$value    = trim( substr( $note, strlen( self::NOTE_SALE_READY ) ) );
						$override = self::is_date( $value ) ? $value : null;
					} elseif ( 0 === strpos( $note, self::NOTE_CHECKIN ) ) {
						$value       = self::note_date( $note, self::NOTE_CHECKIN );
						$checkin_set = '' !== $value ? $value : null; // Empty: back to the arrival's date.
					}
					break;

				default:
					break;
			}

			// Reaching zero closes the stay on that event's date; climbing
			// back above zero (an undo) re-opens it.
			if ( $had_arrival && $qty_live <= 0 && null === $closed_date ) {
				$closed_date = '' !== $date ? $date : (string) $today;
			} elseif ( $qty_live > 0 && null !== $closed_date ) {
				$closed_date = null;
			}
		}

		$overdrawn   = $had_arrival && $qty_live < 0;
		$qty_arrived = max( 0, $qty_arrived );
		$qty_lost    = max( 0, $qty_lost );
		$qty_sold    = max( 0, $qty_sold );
		$qty_live    = max( 0, $qty_live );

		if ( null !== $checkin_set ) {
			$checkin = $checkin_set;
		}

		$day        = self::day_number( $checkin, (string) $today );
		$ready_date = self::sale_ready_date( $checkin, $override, $days );
		$status     = null !== $closed_date ? 'closed' : ( null !== $cleared_date ? 'cleared' : 'in_qt' );
		$ready      = 'cleared' === $status && '' !== $ready_date && self::is_date( $today ) && strcmp( (string) $today, $ready_date ) >= 0;

		return array(
			'qty_arrived'         => $qty_arrived,
			'qty_live'            => $qty_live,
			'qty_lost'            => $qty_lost,
			'qty_sold'            => $qty_sold,
			'overdrawn'           => $overdrawn,
			'checkin_date'        => $checkin,
			'cleared_date'        => $cleared_date,
			'closed_date'         => $closed_date,
			'sale_ready_override' => $override,
			'sale_ready_date'     => $ready_date,
			'sale_ready'          => $ready,
			'tank_id'             => $tank_id,
			'status'              => $status,
			'stored_status'       => $status,
			'day'                 => $day,
			'state_label'         => self::state_label( $status, $ready ),
			'last_event_at'       => $last_at,
		);
	}

	/**
	 * The effective state of a cached row today — the stored status plus
	 * the day number and the sale-ready flag (the only parts that move
	 * without an event).
	 *
	 * @param array  $row   Stay row (status, checkin_date, cleared_date, sale_ready_date = override).
	 * @param string $today Site date.
	 * @param array  $opts  sale_ready_days.
	 * @return array{status: string, day: int|null, sale_ready_date: string, sale_ready: bool, state_label: string}
	 */
	public static function effective( array $row, $today, array $opts = array() ) {
		$days       = max( 1, (int) ( $opts['sale_ready_days'] ?? self::DEFAULT_SALE_READY_DAYS ) );
		$checkin    = (string) ( $row['checkin_date'] ?? '' );
		$day        = self::day_number( $checkin, (string) $today );
		$status     = (string) ( $row['status'] ?? 'in_qt' );
		$override   = self::is_date( $row['sale_ready_date'] ?? null ) ? (string) $row['sale_ready_date'] : null;
		$ready_date = self::sale_ready_date( $checkin, $override, $days );

		if ( 'closed' !== $status ) {
			$status = ! empty( $row['cleared_date'] ) ? 'cleared' : 'in_qt';
		}
		$ready = 'cleared' === $status && '' !== $ready_date && self::is_date( $today ) && strcmp( (string) $today, $ready_date ) >= 0;

		return array(
			'status'          => $status,
			'day'             => $day,
			'sale_ready_date' => $ready_date,
			'sale_ready'      => $ready,
			'state_label'     => self::state_label( $status, $ready ),
		);
	}

	/**
	 * 'in QT' / 'cleared' / 'ready' / 'closed'.
	 *
	 * @param string $status Status.
	 * @param bool   $ready  Sale-ready.
	 * @return string
	 */
	public static function state_label( $status, $ready ) {
		if ( 'closed' === $status ) {
			return 'closed';
		}
		if ( 'cleared' === $status ) {
			return $ready ? 'ready' : 'cleared';
		}
		return 'in QT';
	}

	/* ============================================================== */
	/*  Undo                                                           */
	/* ============================================================== */

	/**
	 * The event that reverses another one. Never a delete: the history
	 * keeps both. Null when there is nothing to reverse (a plain note).
	 *
	 * @param array $event        The event to undo (type, qty, from_tank_id, to_tank_id, note).
	 * @param array $state_before The state just before that event (for a count's previous
	 *                            qty_live and a sale-ready note's previous override).
	 * @return array|null type, qty, from_tank_id, to_tank_id, note.
	 */
	public static function compensating( array $event, array $state_before = array() ) {
		$type = (string) ( $event['type'] ?? '' );
		$qty  = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
		$id   = (int) ( $event['id'] ?? 0 );
		$note = $id > 0 ? self::NOTE_UNDO . $id : 'undo';

		switch ( $type ) {
			case 'arrival':
			case 'loss':
			case 'sold':
				$n = null === $qty ? ( 'arrival' === $type ? 0 : 1 ) : $qty;
				if ( 0 === $n ) {
					return null;
				}
				return array(
					'type'         => $type,
					'qty'          => -$n,
					'from_tank_id' => null,
					'to_tank_id'   => null,
					'note'         => $note,
				);

			case 'move':
				if ( null !== $qty && 0 !== $qty ) {
					// A split (or its undo): give the fish back (or take them again).
					return array(
						'type'         => 'move',
						'qty'          => -$qty,
						'from_tank_id' => isset( $event['to_tank_id'] ) ? (int) $event['to_tank_id'] : null,
						'to_tank_id'   => isset( $event['from_tank_id'] ) ? (int) $event['from_tank_id'] : null,
						'note'         => $note,
					);
				}
				return array(
					'type'         => 'move',
					'qty'          => null,
					'from_tank_id' => isset( $event['to_tank_id'] ) ? (int) $event['to_tank_id'] : null,
					'to_tank_id'   => isset( $event['from_tank_id'] ) ? (int) $event['from_tank_id'] : null,
					'note'         => $note,
				);

			case 'clear':
				if ( null !== $qty && $qty < 0 ) {
					return null; // Un-clearing an un-clear would need the old date; not offered.
				}
				return array(
					'type'         => 'clear',
					'qty'          => -1,
					'from_tank_id' => null,
					'to_tank_id'   => null,
					'note'         => $note,
				);

			case 'count':
				return array(
					'type'         => 'count',
					'qty'          => max( 0, (int) ( $state_before['qty_live'] ?? 0 ) ),
					'from_tank_id' => null,
					'to_tank_id'   => null,
					'note'         => $note,
				);

			case 'note':
				$text = (string) ( $event['note'] ?? '' );
				if ( 0 === strpos( $text, self::NOTE_SALE_READY ) ) {
					return array(
						'type'         => 'note',
						'qty'          => null,
						'from_tank_id' => null,
						'to_tank_id'   => null,
						'note'         => self::NOTE_SALE_READY . (string) ( $state_before['sale_ready_override'] ?? '' ),
					);
				}
				if ( 0 === strpos( $text, self::NOTE_CHECKIN ) ) {
					return array(
						'type'         => 'note',
						'qty'          => null,
						'from_tank_id' => null,
						'to_tank_id'   => null,
						'note'         => self::NOTE_CHECKIN . (string) ( $state_before['checkin_date'] ?? '' ),
					);
				}
				return null;

			default:
				return null;
		}
	}

	/**
	 * Whether an event was already undone: a later event of the stay
	 * carries the note 'undo of #<id>'. The undo button can be tapped
	 * twice (or the same ids posted twice); the second must write nothing.
	 *
	 * @param array[] $events   The stay's events (any order).
	 * @param int     $event_id The event.
	 * @return bool
	 */
	public static function is_undone( array $events, $event_id ) {
		$event_id = (int) $event_id;
		if ( $event_id <= 0 ) {
			return false;
		}
		$note = self::NOTE_UNDO . $event_id;
		foreach ( $events as $event ) {
			if ( $note === (string) ( $event['note'] ?? '' ) && (int) ( $event['id'] ?? 0 ) !== $event_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The ids every 'undo of #<id>' note in a list points at (so a list
	 * can hide the Undo button on events already undone).
	 *
	 * @param array[] $events Events.
	 * @return int[]
	 */
	public static function undone_ids( array $events ) {
		$out = array();
		foreach ( $events as $event ) {
			$note = (string) ( $event['note'] ?? '' );
			if ( 0 === strpos( $note, self::NOTE_UNDO ) && ctype_digit( substr( $note, strlen( self::NOTE_UNDO ) ) ) ) {
				$out[] = (int) substr( $note, strlen( self::NOTE_UNDO ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether an event looks like a reversal (so the lists can mark it).
	 *
	 * @param array $event Event.
	 * @return bool
	 */
	public static function is_reversal( array $event ) {
		$qty = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
		if ( null !== $qty && $qty < 0 && in_array( (string) ( $event['type'] ?? '' ), array( 'arrival', 'loss', 'sold', 'clear', 'move' ), true ) ) {
			return true;
		}
		return 0 === strpos( (string) ( $event['note'] ?? '' ), 'undo' );
	}

	/**
	 * Whether a move event moved a whole group (as opposed to a split).
	 *
	 * @param array $event Event.
	 * @return bool
	 */
	public static function is_whole_move( array $event ) {
		$qty = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null;
		return 'move' === (string) ( $event['type'] ?? '' ) && ( null === $qty || 0 === $qty );
	}
}
