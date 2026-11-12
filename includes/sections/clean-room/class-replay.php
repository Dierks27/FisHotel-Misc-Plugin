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
 *   - the sale date is CHOSEN, never computed: the stay's own date (a note
 *     event "sale_ready:YYYY-MM-DD"; "sale_ready:" clears it) when set,
 *     else its batch's date (the supplier order it was checked in from —
 *     the Store hands it in as the stay's batch_sale_date), else none;
 *     a stay is ready (for sale) once that date is set, the site-local
 *     today is that date or later and the stay is not closed — cleared or
 *     not. One that is ready while still in the Spa (not cleared) says so
 *     (ready_in_spa), so every screen can warn. Without a date a stay is
 *     not for sale; check-in + N days (the sale_ready_days setting) is
 *     only the suggestion the screens show;
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
	 * Default number of days after check-in the SUGGESTED sale date falls
	 * on (the hint and the date pickers' "suggested" fill — never what puts
	 * a stay on sale).
	 *
	 * @var int
	 */
	const DEFAULT_SALE_READY_DAYS = 28;

	/**
	 * A note event starting with this sets (or, with nothing after it,
	 * clears) the stay's own sale date.
	 *
	 * @var string
	 */
	const NOTE_SALE_READY = 'sale_ready:';

	/**
	 * Where a stay's sale date comes from (sale_date_source()): its own
	 * date, its batch's, or '' (none).
	 *
	 * @var string
	 */
	const SALE_OWN   = 'own';
	const SALE_BATCH = 'batch';

	/**
	 * The earliest sale date a screen accepts (the latest is two years
	 * after today, sale_date_max()). A date field turns a typed "26" into
	 * the year 0026, which would put a group on sale at once.
	 *
	 * @var string
	 */
	const SALE_DATE_MIN = '2000-01-01';

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
	 * The SUGGESTED sale date: N days after check-in (check-in + N: the
	 * full N days have passed). Only a hint and what the "suggested" fill
	 * of a date picker puts in — never saved by itself, never what makes
	 * a stay ready.
	 *
	 * @param string $checkin Y-m-d.
	 * @param int    $days    N (the sale_ready_days setting).
	 * @return string Y-m-d ('' when there is no check-in)
	 */
	public static function suggested_sale_date( $checkin, $days = self::DEFAULT_SALE_READY_DAYS ) {
		return self::add_days( $checkin, max( 1, (int) $days ) );
	}

	/**
	 * The effective sale date: the stay's own date when set, else its
	 * batch's date, else none.
	 *
	 * @param string|null $own   The stay's own date (the note event's).
	 * @param string|null $batch The batch's date (its supplier order's).
	 * @return string|null Y-m-d, or null when neither is set.
	 */
	public static function sale_date( $own, $batch ) {
		if ( self::is_date( $own ) ) {
			return (string) $own;
		}
		if ( self::is_date( $batch ) ) {
			return (string) $batch;
		}
		return null;
	}

	/**
	 * Where the effective sale date comes from.
	 *
	 * @param string|null $own   The stay's own date.
	 * @param string|null $batch The batch's date.
	 * @return string SALE_OWN, SALE_BATCH or '' (none).
	 */
	public static function sale_date_source( $own, $batch ) {
		if ( self::is_date( $own ) ) {
			return self::SALE_OWN;
		}
		if ( self::is_date( $batch ) ) {
			return self::SALE_BATCH;
		}
		return '';
	}

	/**
	 * The latest sale date a screen accepts: two years after today (Feb 29
	 * → Feb 28).
	 *
	 * @param string $today Site date.
	 * @return string Y-m-d ('' when today is not a date).
	 */
	public static function sale_date_max( $today ) {
		if ( ! self::is_date( $today ) ) {
			return '';
		}
		$max = sprintf( '%04d', (int) substr( (string) $today, 0, 4 ) + 2 ) . substr( (string) $today, 4 );
		return self::is_date( $max ) ? $max : substr( $max, 0, 8 ) . '28';
	}

	/**
	 * Whether a chosen sale date is a real date from SALE_DATE_MIN to two
	 * years after today (no upper bound when today is not a date).
	 *
	 * @param mixed  $ymd   The date typed.
	 * @param string $today Site date.
	 * @return bool
	 */
	public static function is_sale_date_in_range( $ymd, $today ) {
		if ( ! self::is_date( $ymd ) || strcmp( (string) $ymd, self::SALE_DATE_MIN ) < 0 ) {
			return false;
		}
		$max = self::sale_date_max( $today );
		return '' === $max || strcmp( (string) $ymd, $max ) <= 0;
	}

	/**
	 * What a typed own sale date means for a stay (pure): 'invalid' (not
	 * a date), 'range' (a real date outside is_sale_date_in_range()),
	 * 'same' (what the stay already has — nothing to write; '' = no own
	 * date) or 'ok'.
	 *
	 * @param string      $typed   The date typed ('' = no own date).
	 * @param string|null $current The stay's own date now.
	 * @param string      $today   Site date.
	 * @return string
	 */
	public static function own_sale_date_check( $typed, $current, $today ) {
		$typed   = (string) $typed;
		$current = self::is_date( $current ) ? (string) $current : '';
		if ( '' !== $typed && ! self::is_date( $typed ) ) {
			return 'invalid';
		}
		if ( '' !== $typed && ! self::is_sale_date_in_range( $typed, $today ) ) {
			return 'range';
		}
		return $typed === $current ? 'same' : 'ok';
	}

	/**
	 * Whether a stay is for sale: a sale date is set, the site-local today
	 * is that date or later (the date itself counts), and the stay is not
	 * closed. Cleared or not does not matter — the chosen date rules.
	 *
	 * @param string|null $sale_date The effective sale date.
	 * @param string      $today     Site date Y-m-d.
	 * @param string      $status    in_qt|cleared|closed.
	 * @return bool
	 */
	public static function is_sale_ready( $sale_date, $today, $status ) {
		return 'closed' !== (string) $status
			&& self::is_date( $sale_date )
			&& self::is_date( $today )
			&& strcmp( (string) $today, (string) $sale_date ) >= 0;
	}

	/**
	 * Every sale field of a stay, from its own date, its batch's date, the
	 * check-in, today and its status (pure; state() and effective() both
	 * return these).
	 *
	 * @param string|null $own     The stay's own date.
	 * @param string|null $batch   The batch's date.
	 * @param string      $checkin Check-in date.
	 * @param string      $today   Site date.
	 * @param string      $status  in_qt|cleared|closed.
	 * @param int         $days    The sale_ready_days setting (the suggestion only).
	 * @return array{sale_ready_override: string|null, batch_sale_date: string|null, sale_ready_date: string|null,
	 *               sale_date_source: string, sale_ready_suggested: string, sale_ready: bool, ready_in_spa: bool}
	 */
	public static function sale_fields( $own, $batch, $checkin, $today, $status, $days = self::DEFAULT_SALE_READY_DAYS ) {
		$own   = self::is_date( $own ) ? (string) $own : null;
		$batch = self::is_date( $batch ) ? (string) $batch : null;
		$date  = self::sale_date( $own, $batch );
		$ready = self::is_sale_ready( $date, $today, $status );

		return array(
			'sale_ready_override'  => $own,
			'batch_sale_date'      => $batch,
			'sale_ready_date'      => $date,
			'sale_date_source'     => self::sale_date_source( $own, $batch ),
			'sale_ready_suggested' => self::suggested_sale_date( $checkin, $days ),
			'sale_ready'           => $ready,
			'ready_in_spa'         => $ready && 'in_qt' === (string) $status,
		);
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
	 * @param array   $stay   The stay row (checkin_date as a fallback, tank_id as a fallback,
	 *                        batch_sale_date = its supplier order's sale date, when it has one).
	 * @param array[] $events Its events (any order).
	 * @param string  $today  Site date Y-m-d.
	 * @param array   $opts   sale_ready_days (int, default 28 — the suggested date only),
	 *                        tz (DateTimeZone|string|null) for event dates,
	 *                        tank_areas (array tank_id => 'clean'|'spa') to spot a move into the Clean Room.
	 * @return array{
	 *   qty_arrived: int, qty_live: int, qty_lost: int, qty_sold: int, overdrawn: bool,
	 *   checkin_date: string, cleared_date: string|null, closed_date: string|null,
	 *   sale_ready_override: string|null, batch_sale_date: string|null, sale_ready_date: string|null,
	 *   sale_date_source: string, sale_ready_suggested: string, sale_ready: bool, ready_in_spa: bool,
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

		$day    = self::day_number( $checkin, (string) $today );
		$status = null !== $closed_date ? 'closed' : ( null !== $cleared_date ? 'cleared' : 'in_qt' );
		$sale   = self::sale_fields( $override, $stay['batch_sale_date'] ?? null, $checkin, (string) $today, $status, $days );

		return array_merge(
			array(
				'qty_arrived'   => $qty_arrived,
				'qty_live'      => $qty_live,
				'qty_lost'      => $qty_lost,
				'qty_sold'      => $qty_sold,
				'overdrawn'     => $overdrawn,
				'checkin_date'  => $checkin,
				'cleared_date'  => $cleared_date,
				'closed_date'   => $closed_date,
			),
			$sale,
			array(
				'tank_id'       => $tank_id,
				'status'        => $status,
				'stored_status' => $status,
				'day'           => $day,
				'state_label'   => self::state_label( $status, $sale['sale_ready'] ),
				'last_event_at' => $last_at,
			)
		);
	}

	/**
	 * The effective state of a cached row today — the stored status plus
	 * the day number and the sale fields (the only parts that move without
	 * an event: today, and the batch's date on its supplier order).
	 *
	 * @param array  $row   Stay row (status, checkin_date, cleared_date, sale_ready_date = the stay's
	 *                      own date, batch_sale_date = its supplier order's).
	 * @param string $today Site date.
	 * @param array  $opts  sale_ready_days (the suggested date only).
	 * @return array{status: string, day: int|null, sale_ready_override: string|null, batch_sale_date: string|null,
	 *               sale_ready_date: string|null, sale_date_source: string, sale_ready_suggested: string,
	 *               sale_ready: bool, ready_in_spa: bool, state_label: string}
	 */
	public static function effective( array $row, $today, array $opts = array() ) {
		$days    = max( 1, (int) ( $opts['sale_ready_days'] ?? self::DEFAULT_SALE_READY_DAYS ) );
		$checkin = (string) ( $row['checkin_date'] ?? '' );
		$day     = self::day_number( $checkin, (string) $today );
		$status  = (string) ( $row['status'] ?? 'in_qt' );

		if ( 'closed' !== $status ) {
			$status = ! empty( $row['cleared_date'] ) ? 'cleared' : 'in_qt';
		}
		$sale = self::sale_fields( $row['sale_ready_date'] ?? null, $row['batch_sale_date'] ?? null, $checkin, (string) $today, $status, $days );

		return array_merge(
			array(
				'status' => $status,
				'day'    => $day,
			),
			$sale,
			array( 'state_label' => self::state_label( $status, $sale['sale_ready'] ) )
		);
	}

	/**
	 * 'in QT' / 'cleared' / 'ready' / 'closed'. A stay whose sale date has
	 * come is 'ready' whether it is cleared or not (the screens add the
	 * "still in the Spa" warning from ready_in_spa).
	 *
	 * @param string $status Status.
	 * @param bool   $ready  For sale (is_sale_ready()).
	 * @return string
	 */
	public static function state_label( $status, $ready ) {
		if ( 'closed' === $status ) {
			return 'closed';
		}
		if ( $ready ) {
			return 'ready';
		}
		return 'cleared' === $status ? 'cleared' : 'in QT';
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
	 *                            qty_live and a sale-date note's previous own date).
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
