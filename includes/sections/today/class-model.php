<?php
/**
 * Today model — pure functions, no WordPress calls.
 *
 * Everything the page and the widget show is computed here from plain
 * arrays the Collector builds: the lookahead window, the ship plan ("do we
 * ship today, and for which delivery date"), the weather state read from
 * the Weather section's meta, the per-destination checklist, the chips,
 * the needs-attention strip, the sort order and the date labels. No
 * get_option(), no wc_*: tests/model-test.php runs this with plain php.
 *
 * Ship day = delivery date minus one calendar day (the box goes out the
 * day before it lands). Weekends and holidays need no special casing —
 * they simply have no orders, because the Batch plugin's delivery-date
 * rules never let a customer pick them.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Weather\Checker;
use FisHotel\Misc\Sections\Weather\Note;

/**
 * Class Model
 */
class Model {

	/**
	 * Lookahead bounds (days).
	 */
	const LOOKAHEAD_MIN = 1;
	const LOOKAHEAD_MAX = 60;

	/**
	 * Badge keys, most serious first. The index is the sort rank of a
	 * destination (holds first, "nothing needed" last).
	 *
	 * @var string[]
	 */
	const SEVERITY = array( 'hold', 'ups_store', 'at_line', 'unavailable', 'not_checked', 'heat_pack', 'cooling', 'none' );

	/**
	 * Badge text per key.
	 *
	 * @var array<string, string>
	 */
	const BADGES = array(
		'heat_pack'   => 'HEAT PACK',
		'none'        => 'NOTHING',
		'cooling'     => 'COOLING',
		'ups_store'   => 'UPS STORE',
		'hold'        => 'HOLD',
		'at_line'     => 'AT THE LINE',
		'unavailable' => 'UNAVAILABLE',
		'not_checked' => 'NOT CHECKED',
	);

	/**
	 * Needs-attention ranks: weather trouble first (an active NWS alert
	 * sits between "at the line" and "unavailable"), then missing paperwork.
	 *
	 * @var array<string, int>
	 */
	const ATTENTION_RANK = array(
		'hold'        => 0,
		'ups_store'   => 1,
		'at_line'     => 2,
		'alerts'      => 3,
		'unavailable' => 4,
		'not_checked' => 5,
		'invoice'     => 6,
		'tracking'    => 7,
	);

	/**
	 * The flags every column-aware function assumes when none are given:
	 * weather on, so callers that predate the flags keep their behaviour.
	 *
	 * @var array<string, bool>
	 */
	const DEFAULT_FLAGS = array( 'weather' => true );

	/* ============================================================== */
	/*  Dates                                                          */
	/* ============================================================== */

	/**
	 * The dates to look at: today + 1 … today + lookahead (Y-m-d list).
	 * Calendar arithmetic, so a DST change inside the window is just
	 * another day.
	 *
	 * @param string $today     Y-m-d.
	 * @param int    $lookahead Days (clamped to 1–60).
	 * @return string[]
	 */
	public static function window( $today, $lookahead ) {
		if ( ! Note::date( $today ) ) {
			return array();
		}

		$lookahead = max( self::LOOKAHEAD_MIN, min( self::LOOKAHEAD_MAX, (int) $lookahead ) );
		$out       = array();

		for ( $i = 1; $i <= $lookahead; $i++ ) {
			$out[] = Note::add_days( $today, $i );
		}

		return $out;
	}

	/**
	 * Do we ship today, and for which delivery date?
	 *
	 * A day ships today when today + 1 is a delivery date with orders.
	 *
	 * @param string   $today             Y-m-d.
	 * @param string[] $dates_with_orders Y-m-d list (any order, duplicates fine).
	 * @return array{ships_today: bool, delivery: string|null, ship: string|null, next: array{delivery: string, ship: string}|null}
	 */
	public static function ship_plan( $today, array $dates_with_orders ) {
		$plan = array(
			'ships_today' => false,
			'delivery'    => null,
			'ship'        => null,
			'next'        => null,
		);

		if ( ! Note::date( $today ) ) {
			return $plan;
		}

		$dates = array();
		foreach ( $dates_with_orders as $date ) {
			$date = (string) $date;
			if ( Note::date( $date ) && $date > $today && ! in_array( $date, $dates, true ) ) {
				$dates[] = $date;
			}
		}
		sort( $dates );

		$tomorrow = Note::add_days( $today, 1 );

		if ( in_array( $tomorrow, $dates, true ) ) {
			$plan['ships_today'] = true;
			$plan['delivery']    = $tomorrow;
			$plan['ship']        = $today;
		}

		foreach ( $dates as $date ) {
			if ( $plan['ships_today'] && $date === $tomorrow ) {
				continue;
			}
			$plan['next'] = array(
				'delivery' => $date,
				'ship'     => Note::add_days( $date, -1 ),
			);
			break;
		}

		return $plan;
	}

	/**
	 * 'Tue, Oct 14' for a Y-m-d. A calendar date has no time zone; $tz is
	 * accepted so callers can pass the site zone uniformly.
	 *
	 * @param string                    $ymd    Y-m-d.
	 * @param \DateTimeZone|string|null $tz     Zone (ignored for the label).
	 * @param string                    $format PHP date format.
	 * @return string The label, or the input unchanged when it is not Y-m-d.
	 */
	public static function date_label( $ymd, $tz = null, $format = 'D, M j' ) {
		$dt = self::parse( $ymd, $tz );
		return $dt ? $dt->format( $format ) : (string) $ymd;
	}

	/**
	 * Ship date for a delivery date: minus one calendar day in the site
	 * zone, DST-safe (modify('-1 day') is calendar arithmetic, so the day
	 * after a clock change is still one day).
	 *
	 * @param string                    $delivery_ymd Y-m-d.
	 * @param \DateTimeZone|string|null $tz           Zone (null = UTC).
	 * @return string Y-m-d, or '' when the input is not a date.
	 */
	public static function ship_date_for( $delivery_ymd, $tz = null ) {
		$dt = self::parse( $delivery_ymd, $tz );
		if ( ! $dt ) {
			return '';
		}

		$ship = $dt->modify( '-1 day' );

		return $ship ? $ship->format( 'Y-m-d' ) : '';
	}

	/**
	 * Y-m-d → local midnight in a zone, or null.
	 *
	 * @param string                    $ymd Y-m-d.
	 * @param \DateTimeZone|string|null $tz  Zone.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse( $ymd, $tz = null ) {
		if ( ! Note::date( $ymd ) ) {
			return null;
		}

		$zone = self::zone( $tz );
		$dt   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $zone );

		return ( $dt && $dt->format( 'Y-m-d' ) === $ymd ) ? $dt : null;
	}

	/**
	 * A DateTimeZone from a zone, a zone name or null (UTC).
	 *
	 * @param \DateTimeZone|string|null $tz Zone.
	 * @return \DateTimeZone
	 */
	public static function zone( $tz ) {
		if ( $tz instanceof \DateTimeZone ) {
			return $tz;
		}

		if ( is_string( $tz ) && '' !== $tz ) {
			try {
				return new \DateTimeZone( $tz );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}

		return new \DateTimeZone( 'UTC' );
	}

	/* ============================================================== */
	/*  Weather                                                        */
	/* ============================================================== */

	/**
	 * The Weather section's meta on a destination order, normalised for a
	 * delivery date. Same reading as FisHotel_Ship_Day::weather_state():
	 * a result counts only when `_fh_weather_checked_for` is this date; the
	 * `unavailable` action counts when `_fh_weather_unavailable_for` is this
	 * date (or unset); anything else is "not checked".
	 *
	 * Active NWS alerts are written only into the note (`Alerts: Flood
	 * Watch, Wind Advisory.`), so they are parsed from `_fh_weather_note`
	 * for a checked result.
	 *
	 * @param array  $meta Meta key => value (Checker::META_* keys).
	 * @param string $ymd  Delivery date.
	 * @return array{status: string, action: string, at_line: bool, high: int|null, low: int|null, summary: string, checked_for: string, alerts: string[]}
	 */
	public static function weather_state( array $meta, $ymd ) {
		$ymd             = trim( (string) $ymd );
		$action          = trim( (string) ( $meta[ Checker::META_ACTION ] ?? '' ) );
		$checked_for     = trim( (string) ( $meta[ Checker::META_CHECKED_FOR ] ?? '' ) );
		$unavailable_for = trim( (string) ( $meta[ Checker::META_UNAVAILABLE_FOR ] ?? '' ) );

		$state = array(
			'status'      => 'not_checked',
			'action'      => '',
			'at_line'     => false,
			'high'        => null,
			'low'         => null,
			'summary'     => trim( (string) ( $meta[ Checker::META_SUMMARY ] ?? '' ) ),
			'checked_for' => $checked_for,
			'alerts'      => array(),
		);

		if ( 'unavailable' === $action ) {
			if ( '' === $unavailable_for || '' === $ymd || $unavailable_for === $ymd ) {
				$state['status'] = 'unavailable';
				$state['action'] = 'unavailable';
			}
			return $state;
		}

		if ( '' === $action || $checked_for !== $ymd ) {
			return $state;
		}

		$high = $meta[ Checker::META_HIGH ] ?? null;
		$low  = $meta[ Checker::META_LOW ] ?? null;

		$state['status']  = 'checked';
		$state['action']  = $action;
		$state['at_line'] = '1' === (string) ( $meta[ Checker::META_AT_LINE ] ?? '' );
		$state['high']    = is_numeric( $high ) ? (int) $high : null;
		$state['low']     = is_numeric( $low ) ? (int) $low : null;
		$state['alerts']  = self::parse_alerts( (string) ( $meta[ Checker::META_NOTE ] ?? '' ) );

		return $state;
	}

	/**
	 * Active NWS alert names from a weather note: the `Alerts: Flood Watch,
	 * Wind Advisory.` sentence the Weather section's Note::format() writes,
	 * upper-cased and de-duplicated. Same reading as Ship Day's
	 * parse_alerts(), kept local so the Batch plugin may be absent.
	 *
	 * @param string $note The order's `_fh_weather_note`.
	 * @return string[]
	 */
	public static function parse_alerts( $note ) {
		$note = (string) $note;
		if ( '' === $note || ! preg_match( '/\bAlerts:\s*(.+?)\.(?:\s|$)/u', $note, $m ) ) {
			return array();
		}

		$out = array();
		foreach ( preg_split( '/\s*,\s*/u', trim( $m[1] ) ) as $alert ) {
			$alert = trim( (string) $alert );
			if ( '' === $alert ) {
				continue;
			}
			$alert = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $alert, 'UTF-8' ) : strtoupper( $alert );
			if ( ! in_array( $alert, $out, true ) ) {
				$out[] = $alert;
			}
		}

		return $out;
	}

	/**
	 * Whether a flags array shows the Weather column (absent = shown, so
	 * callers without flags keep the old behaviour).
	 *
	 * @param array $flags weather / invoice / tracking => bool.
	 * @return bool
	 */
	private static function weather_on( array $flags ) {
		return ! empty( $flags['weather'] );
	}

	/**
	 * The badge for a weather state: key (CSS / counting) + text.
	 *
	 * @param array $weather From self::weather_state().
	 * @return array{key: string, label: string}
	 */
	public static function badge( array $weather ) {
		$key = self::badge_key( $weather );

		return array(
			'key'   => $key,
			'label' => self::BADGES[ $key ],
		);
	}

	/**
	 * The badge key for a weather state.
	 *
	 * @param array $weather From self::weather_state().
	 * @return string
	 */
	public static function badge_key( array $weather ) {
		$status = (string) ( $weather['status'] ?? 'not_checked' );

		if ( 'unavailable' === $status ) {
			return 'unavailable';
		}
		if ( 'checked' !== $status ) {
			return 'not_checked';
		}

		$action = (string) ( $weather['action'] ?? '' );

		if ( 'hold_cold' === $action || 'hold_hot' === $action ) {
			return 'hold';
		}
		if ( 'ups_store_cold' === $action || 'ups_store_hot' === $action ) {
			return 'ups_store';
		}
		if ( ! empty( $weather['at_line'] ) ) {
			return 'at_line';
		}
		if ( 'heat_pack' === $action || 'cooling' === $action || 'none' === $action ) {
			return $action;
		}

		return 'not_checked';
	}

	/**
	 * Sort rank of a weather state (0 = most serious).
	 *
	 * @param array $weather From self::weather_state().
	 * @return int
	 */
	public static function weather_rank( array $weather ) {
		$idx = array_search( self::badge_key( $weather ), self::SEVERITY, true );
		return false === $idx ? count( self::SEVERITY ) : (int) $idx;
	}

	/**
	 * Destinations by weather severity (hold, UPS Store, at the line,
	 * unavailable, not checked, then the rest), then order number. Stable.
	 * With the Weather column off the weather rank is skipped: order
	 * number only.
	 *
	 * @param array[] $destinations Destinations.
	 * @param array   $flags        weather / invoice / tracking => bool.
	 * @return array[]
	 */
	public static function sort_destinations( array $destinations, array $flags = self::DEFAULT_FLAGS ) {
		$by_weather = self::weather_on( $flags );

		$indexed = array();
		foreach ( array_values( $destinations ) as $i => $destination ) {
			$indexed[] = array( $i, $destination );
		}

		usort(
			$indexed,
			static function ( $a, $b ) use ( $by_weather ) {
				if ( $by_weather ) {
					$ra = self::weather_rank( (array) ( $a[1]['weather'] ?? array() ) );
					$rb = self::weather_rank( (array) ( $b[1]['weather'] ?? array() ) );
					if ( $ra !== $rb ) {
						return $ra < $rb ? -1 : 1;
					}
				}

				$cmp = self::compare_numbers( (string) ( $a[1]['number'] ?? '' ), (string) ( $b[1]['number'] ?? '' ) );
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return $a[0] < $b[0] ? -1 : 1;
			}
		);

		$out = array();
		foreach ( $indexed as $pair ) {
			$out[] = $pair[1];
		}

		return $out;
	}

	/**
	 * Order numbers compared numerically when both are digits, else as text.
	 *
	 * @param string $a Number.
	 * @param string $b Number.
	 * @return int
	 */
	public static function compare_numbers( $a, $b ) {
		if ( ctype_digit( $a ) && ctype_digit( $b ) ) {
			$ia = (int) $a;
			$ib = (int) $b;
			return $ia === $ib ? 0 : ( $ia < $ib ? -1 : 1 );
		}

		return strcmp( $a, $b );
	}

	/* ============================================================== */
	/*  Checklist, chips, attention                                    */
	/* ============================================================== */

	/**
	 * The per-destination checklist: one item per shown column, done or
	 * open, with a short note.
	 *
	 * @param array $destination Destination (weather, invoice, tracking).
	 * @param array $flags       weather / invoice / tracking => bool (shown or not).
	 * @return array<int, array{key: string, done: bool, label: string, note: string}>
	 */
	public static function checklist( array $destination, array $flags ) {
		$items = array();

		if ( ! empty( $flags['weather'] ) ) {
			$weather = (array) ( $destination['weather'] ?? array() );
			$status  = (string) ( $weather['status'] ?? 'not_checked' );
			$done    = 'checked' === $status;

			if ( $done ) {
				$note = self::badge( $weather )['label'];
			} elseif ( 'unavailable' === $status ) {
				$note = 'unavailable — check manually';
			} else {
				$note = 'not checked';
			}

			$items[] = array(
				'key'   => 'weather',
				'done'  => $done,
				'label' => 'Weather',
				'note'  => $note,
			);
		}

		if ( ! empty( $flags['invoice'] ) ) {
			$invoice = $destination['invoice'] ?? null;
			$done    = is_array( $invoice ) && ! empty( $invoice['done'] );

			$items[] = array(
				'key'   => 'invoice',
				'done'  => $done,
				'label' => 'Invoice',
				'note'  => $done ? 'PDF generated' : 'no invoice PDF yet',
			);
		}

		if ( ! empty( $flags['tracking'] ) ) {
			$tracking = $destination['tracking'] ?? null;
			$done     = is_array( $tracking ) && ! empty( $tracking['done'] );
			$count    = is_array( $tracking ) ? (int) ( $tracking['count'] ?? 0 ) : 0;
			$label    = is_array( $tracking ) ? (string) ( $tracking['status_label'] ?? '' ) : '';

			$items[] = array(
				'key'   => 'tracking',
				'done'  => $done,
				'label' => 'Tracking',
				'note'  => $done ? trim( $count . ( '' !== $label ? ' · ' . $label : '' ) ) : 'no tracking yet',
			);
		}

		return $items;
	}

	/**
	 * Counts for the chips above a date's table.
	 *
	 * needs_* counts a destination only when the column was evaluated (an
	 * `invoice` / `tracking` of null means "column hidden"; the weather
	 * counts — needs_weather, holds, UPS Store, at the line, unavailable —
	 * stay 0 while the Weather column is off).
	 *
	 * @param array[] $destinations Destinations.
	 * @param array   $flags        weather / invoice / tracking => bool.
	 * @return array<string, int>
	 */
	public static function chips( array $destinations, array $flags = self::DEFAULT_FLAGS ) {
		$weather_on = self::weather_on( $flags );

		$chips = array(
			'orders'         => 0,
			'fish'           => 0,
			'dry'            => 0,
			'needs_weather'  => 0,
			'needs_invoice'  => 0,
			'needs_tracking' => 0,
			'holds'          => 0,
			'ups_store'      => 0,
			'at_line'        => 0,
			'unavailable'    => 0,
		);

		foreach ( $destinations as $destination ) {
			$chips['orders']++;
			$chips['fish'] += max( 0, (int) ( $destination['fish'] ?? 0 ) );
			$chips['dry']  += max( 0, (int) ( $destination['dry'] ?? 0 ) );

			if ( $weather_on ) {
				$weather = (array) ( $destination['weather'] ?? array() );
				$key     = self::badge_key( $weather );

				if ( 'checked' !== (string) ( $weather['status'] ?? '' ) ) {
					$chips['needs_weather']++;
				}
				if ( 'hold' === $key ) {
					$chips['holds']++;
				} elseif ( 'ups_store' === $key ) {
					$chips['ups_store']++;
				} elseif ( 'unavailable' === $key ) {
					$chips['unavailable']++;
				}
				if ( 'checked' === (string) ( $weather['status'] ?? '' ) && ! empty( $weather['at_line'] ) ) {
					$chips['at_line']++;
				}
			}

			$invoice = $destination['invoice'] ?? null;
			if ( is_array( $invoice ) && empty( $invoice['done'] ) ) {
				$chips['needs_invoice']++;
			}

			$tracking = $destination['tracking'] ?? null;
			if ( is_array( $tracking ) && empty( $tracking['done'] ) ) {
				$chips['needs_tracking']++;
			}
		}

		return $chips;
	}

	/**
	 * The needs-attention strip: one entry per destination with something
	 * to do — weather trouble (hold, UPS Store, at the line, an active NWS
	 * alert, unavailable, not checked) first, then missing paperwork
	 * (invoice, tracking) — ordered by the most serious reason, then order
	 * number. With the Weather column off no weather reason is raised.
	 *
	 * @param array[] $destinations Destinations.
	 * @param array   $flags        weather / invoice / tracking => bool.
	 * @return array<int, array{order_id: int, number: string, label: string, edit_url: string, kind: string, reasons: string[], rank: int}>
	 */
	public static function needs_attention( array $destinations, array $flags = self::DEFAULT_FLAGS ) {
		$weather_on = self::weather_on( $flags );
		$out        = array();

		foreach ( $destinations as $destination ) {
			$reasons = array();

			if ( $weather_on ) {
				$weather = (array) ( $destination['weather'] ?? array() );
				$key     = self::badge_key( $weather );

				switch ( $key ) {
					case 'hold':
						$reasons['hold'] = 'HOLD — do not ship';
						break;
					case 'ups_store':
						$reasons['ups_store'] = 'UPS Store pickup — customer email';
						break;
					case 'at_line':
						$reasons['at_line'] = 'at the line — your call';
						break;
					case 'unavailable':
						$reasons['unavailable'] = 'weather unavailable — check manually';
						break;
					case 'not_checked':
						$reasons['not_checked'] = 'weather not checked';
						break;
				}

				$alerts = array_values( array_filter( array_map( 'strval', (array) ( $weather['alerts'] ?? array() ) ), 'strlen' ) );
				if ( $alerts ) {
					$reasons['alerts'] = implode( ', ', $alerts );
				}
			}

			$invoice = $destination['invoice'] ?? null;
			if ( is_array( $invoice ) && empty( $invoice['done'] ) ) {
				$reasons['invoice'] = 'no invoice PDF';
			}

			$tracking = $destination['tracking'] ?? null;
			if ( is_array( $tracking ) && empty( $tracking['done'] ) ) {
				$reasons['tracking'] = 'no tracking';
			}

			if ( ! $reasons ) {
				continue;
			}

			$rank = PHP_INT_MAX;
			$kind = '';
			foreach ( array_keys( $reasons ) as $reason_key ) {
				$r = self::ATTENTION_RANK[ $reason_key ] ?? 99;
				if ( $r < $rank ) {
					$rank = $r;
					$kind = $reason_key;
				}
			}

			$out[] = array(
				'order_id' => (int) ( $destination['order_id'] ?? 0 ),
				'number'   => (string) ( $destination['number'] ?? '' ),
				'label'    => (string) ( $destination['label'] ?? '' ),
				'edit_url' => (string) ( $destination['edit_url'] ?? '' ),
				'kind'     => $kind,
				'reasons'  => array_values( $reasons ),
				'rank'     => $rank,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['rank'] !== $b['rank'] ) {
					return $a['rank'] < $b['rank'] ? -1 : 1;
				}
				return self::compare_numbers( $a['number'], $b['number'] );
			}
		);

		return $out;
	}

	/* ============================================================== */
	/*  Text                                                           */
	/* ============================================================== */

	/**
	 * The header line.
	 *
	 *   Today, Mon Oct 13 — ship day for Tue Oct 14 delivery · 6 orders · 23 fish
	 *   Nothing ships today. Next: ship Mon Oct 13 for Tue Oct 14 · 6 orders
	 *   Nothing ships today. No open orders deliver in the next 21 days.
	 *
	 * @param string                    $today     Y-m-d.
	 * @param array                     $plan      From self::ship_plan().
	 * @param array<string, array>      $chips     Y-m-d => chips (orders, fish).
	 * @param int                       $lookahead Days, for the empty line.
	 * @param \DateTimeZone|string|null $tz        Zone.
	 * @return string
	 */
	public static function header_line( $today, array $plan, array $chips, $lookahead, $tz = null ) {
		if ( ! empty( $plan['ships_today'] ) && ! empty( $plan['delivery'] ) ) {
			$c = (array) ( $chips[ $plan['delivery'] ] ?? array() );

			return sprintf(
				'Today, %s — ship day for %s delivery · %s · %s',
				self::date_label( $today, $tz, 'D M j' ),
				self::date_label( $plan['delivery'], $tz, 'D M j' ),
				self::plural( (int) ( $c['orders'] ?? 0 ), 'order', 'orders' ),
				self::plural( (int) ( $c['fish'] ?? 0 ), 'fish', 'fish' )
			);
		}

		$next = isset( $plan['next'] ) && is_array( $plan['next'] ) ? $plan['next'] : null;
		if ( $next && ! empty( $next['delivery'] ) ) {
			$c = (array) ( $chips[ $next['delivery'] ] ?? array() );

			return sprintf(
				'Nothing ships today. Next: ship %s for %s · %s',
				self::date_label( $next['ship'], $tz, 'D M j' ),
				self::date_label( $next['delivery'], $tz, 'D M j' ),
				self::plural( (int) ( $c['orders'] ?? 0 ), 'order', 'orders' )
			);
		}

		return sprintf( 'Nothing ships today. No open orders deliver in the next %d days.', (int) $lookahead );
	}

	/**
	 * One line for a further date in the widget:
	 * 'Wed Oct 15 · 4 orders · 2 need weather · 1 needs invoice'.
	 *
	 * @param string                    $ymd   Y-m-d.
	 * @param array                     $chips From self::chips().
	 * @param array                     $flags weather / invoice / tracking => bool.
	 * @param \DateTimeZone|string|null $tz    Zone.
	 * @return string
	 */
	public static function date_summary( $ymd, array $chips, array $flags, $tz = null ) {
		$parts = array(
			self::date_label( $ymd, $tz, 'D M j' ),
			self::plural( (int) ( $chips['orders'] ?? 0 ), 'order', 'orders' ),
		);

		$needs = array(
			'weather'  => 'needs_weather',
			'invoice'  => 'needs_invoice',
			'tracking' => 'needs_tracking',
		);
		foreach ( $needs as $flag => $key ) {
			$n = (int) ( $chips[ $key ] ?? 0 );
			if ( ! empty( $flags[ $flag ] ) && $n > 0 ) {
				$parts[] = sprintf( '%d %s %s', $n, 1 === $n ? 'needs' : 'need', $flag );
			}
		}

		if ( (int) ( $chips['holds'] ?? 0 ) > 0 ) {
			$parts[] = self::plural( (int) $chips['holds'], 'hold', 'holds' );
		}
		if ( (int) ( $chips['ups_store'] ?? 0 ) > 0 ) {
			$parts[] = (int) $chips['ups_store'] . ' UPS Store';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * '1 order' / '6 orders'.
	 *
	 * @param int    $n        Count.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return string
	 */
	public static function plural( $n, $singular, $plural ) {
		return (int) $n . ' ' . ( 1 === (int) $n ? $singular : $plural );
	}

	/**
	 * 'Order #W (combined: #a, #b)' for a wrapper, 'Order #N' otherwise.
	 *
	 * @param string   $number         Order number.
	 * @param string[] $source_numbers Live source numbers (wrapper only).
	 * @return string
	 */
	public static function order_label( $number, array $source_numbers = array() ) {
		$label = 'Order #' . $number;
		if ( $source_numbers ) {
			$label .= ' (combined: #' . implode( ', #', $source_numbers ) . ')';
		}
		return $label;
	}

	/**
	 * 'Carrollton, VA' — whichever parts are known.
	 *
	 * @param string $city  City.
	 * @param string $state State.
	 * @return string
	 */
	public static function place( $city, $state ) {
		return Note::place( $city, $state );
	}

	/**
	 * The cache key for a day's data: today + a hash of the settings that
	 * shape the result, so a changed setting is a miss.
	 *
	 * @param string $today    Y-m-d.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public static function cache_key( $today, array $settings ) {
		$shape = array(
			'lookahead_days' => (int) ( $settings['lookahead_days'] ?? 0 ),
			'dates_shown'    => (int) ( $settings['dates_shown'] ?? 0 ),
			'statuses'       => array_values( (array) ( $settings['statuses'] ?? array() ) ),
			'show_weather'   => ! empty( $settings['show_weather'] ),
			'show_invoice'   => ! empty( $settings['show_invoice'] ),
			'show_tracking'  => ! empty( $settings['show_tracking'] ),
		);

		return (string) $today . ':' . md5( (string) json_encode( $shape ) );
	}
}
