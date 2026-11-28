<?php
/**
 * Clean Room board lines — PURE: a tank's stays → the lines its card
 * shows. One place for the order and the gathering, shared by the Room
 * Board's rack cards, its temporary cards and (the order only) the Front
 * Desk's tank screen.
 *
 *   order     sort(): by name — natural ("Tang 2" before "Tang 10") and
 *             case-blind —, an unknown fish ("??") last; then by size,
 *             the standard sizes in their own order, then a size that is
 *             no longer standard, then no size; then by check-in, oldest
 *             first, an unknown check-in last; then by id.
 *
 *   gathered  card(): the stays of the same fish (the same name, case and
 *             spacing aside — Identify::same_fish()'s rule), the same
 *             size and the same state badge are ONE line: their counts
 *             summed, their days as one number or a range. The same fish
 *             in another size or state is a line of its own, next to it
 *             ('repeat' = the line above is the same fish). A fish nobody
 *             knows yet is never gathered: two "?? small wrasse" may be
 *             two fish, so each group keeps its line. A line keeps its
 *             stays, so nothing is lost: the card lists them when the
 *             line is opened. The name printed is the catalogue's when
 *             one of the fish's groups is linked to a product, else the
 *             first group's as it was typed.
 *
 *   counted   the card's 'stays' and 'no_sale' count stays, never lines —
 *             as Admin::totals() does for the building and the room.
 *
 * origins() reads the move events of a set of stays: the quarantine tank
 * each one came out of ("from 104"), for the opened line.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

use FisHotel\Misc\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Class Board_Lines
 */
class Board_Lines {

	/**
	 * Whether a stay's fish is one nobody knows yet: flagged, or named
	 * with the "??" mark.
	 *
	 * @param array $stay needs_id, species.
	 * @return bool
	 */
	public static function is_unknown( array $stay ) {
		return ! empty( $stay['needs_id'] ) || Identify::is_unknown_name( $stay['species'] ?? '' );
	}

	/**
	 * Which fish a stay is: its name, case and spacing aside — two stays
	 * are the same fish as Identify::same_fish() says two names are. A
	 * fish nobody knows yet is only ever itself: what two groups look
	 * like does not make them one fish, so its key carries the stay's id.
	 *
	 * @param array $stay species, needs_id, id.
	 * @return string
	 */
	public static function fish_key( array $stay ) {
		$name = Species::key( (string) ( $stay['species'] ?? '' ) );
		return self::is_unknown( $stay ) ? 'u:' . (int) ( $stay['id'] ?? 0 ) . ':' . $name : 'n:' . $name;
	}

	/**
	 * The badge a stay wears: 'ready' once its sale date has come, else
	 * its status (as Admin::badge_html()).
	 *
	 * @param array $stay sale_ready, status.
	 * @return string in_qt|cleared|ready|closed.
	 */
	public static function state_key( array $stay ) {
		$key = ! empty( $stay['sale_ready'] ) ? 'ready' : (string) ( $stay['status'] ?? 'in_qt' );
		return in_array( $key, array( 'in_qt', 'cleared', 'ready', 'closed' ), true ) ? $key : 'in_qt';
	}

	/**
	 * Where a stay's size stands in the order: a standard size by its
	 * place in the list, then a size that is not (or no longer) standard
	 * by its label, then no size.
	 *
	 * @param array        $stay  size_slug, size_label.
	 * @param array[]|null $sizes The standard sizes in their own order (null = not known here).
	 * @return array{0: int, 1: int, 2: string} group, place, label.
	 */
	public static function size_rank( array $stay, $sizes = null ) {
		$size = Sizes::of( $stay, $sizes );
		if ( null === $size ) {
			return array( 2, 0, '' );
		}
		foreach ( is_array( $sizes ) ? array_values( $sizes ) : array() as $place => $standard ) {
			if ( (string) ( $standard['slug'] ?? '' ) === $size['slug'] ) {
				return array( 0, (int) $place, $size['label'] );
			}
		}
		return array( 1, 0, $size['label'] );
	}

	/**
	 * A tank's stays in the order every screen lists them (see the file
	 * header). Nothing is added or dropped.
	 *
	 * @param array[]      $stays Hydrated stays.
	 * @param array[]|null $sizes The standard sizes in their own order.
	 * @return array[]
	 */
	public static function sort( array $stays, $sizes = null ) {
		$rows = array();
		foreach ( array_values( $stays ) as $index => $stay ) {
			$stay   = (array) $stay;
			$rows[] = array(
				'stay'    => $stay,
				'unknown' => self::is_unknown( $stay ) ? 1 : 0,
				'name'    => Species::key( (string) ( $stay['species'] ?? '' ) ),
				'size'    => self::size_rank( $stay, $sizes ),
				'checkin' => Replay::is_date( $stay['checkin_date'] ?? null ) ? (string) $stay['checkin_date'] : '',
				'id'      => (int) ( $stay['id'] ?? 0 ),
				'index'   => $index,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['unknown'] !== $b['unknown'] ) {
					return $a['unknown'] <=> $b['unknown'];
				}
				$by_name = strnatcasecmp( $a['name'], $b['name'] );
				if ( 0 === $by_name ) {
					$by_name = strcmp( $a['name'], $b['name'] );
				}
				if ( 0 !== $by_name ) {
					return $by_name;
				}
				if ( $a['size'][0] !== $b['size'][0] ) {
					return $a['size'][0] <=> $b['size'][0];
				}
				if ( $a['size'][1] !== $b['size'][1] ) {
					return $a['size'][1] <=> $b['size'][1];
				}
				$by_label = strnatcasecmp( $a['size'][2], $b['size'][2] );
				if ( 0 !== $by_label ) {
					return $by_label;
				}
				if ( $a['checkin'] !== $b['checkin'] ) {
					if ( '' === $a['checkin'] || '' === $b['checkin'] ) {
						return '' === $a['checkin'] ? 1 : -1; // An unknown check-in last.
					}
					return strcmp( $a['checkin'], $b['checkin'] );
				}
				return ( $a['id'] <=> $b['id'] ) ?: ( $a['index'] <=> $b['index'] );
			}
		);

		return array_column( $rows, 'stay' );
	}

	/**
	 * A tank card: its stays in order, gathered into lines (see the file
	 * header).
	 *
	 * Each line: key (the fish), species (the name printed), repeat (the
	 * line above is the same fish), unknown (needs identifying), size
	 * (Sizes::of(): slug, label, standard — or null), state (state_key()),
	 * in_spa (for sale but still in the Spa), qty (fish alive, summed),
	 * day_min / day_max (null when no stay has a day), day_unknown (a stay
	 * with an unknown check-in is among them), sale_dates (the sale dates
	 * of its stays, oldest first, each once), no_sale (its stays without a
	 * sale date) and stays (the stays themselves, in order).
	 *
	 * @param array[]      $stays Hydrated stays of one tank.
	 * @param array[]|null $sizes The standard sizes in their own order.
	 * @return array{lines: array[], stays: int, no_sale: int}
	 */
	public static function card( array $stays, $sizes = null ) {
		$lines = array();
		$slots = array();
		$names = array();
		$none  = 0;

		foreach ( self::sort( $stays, $sizes ) as $stay ) {
			$size  = Sizes::of( $stay, $sizes );
			$state = self::state_key( $stay );
			$spa   = ! empty( $stay['ready_in_spa'] );
			$fish  = self::fish_key( $stay );
			$key   = $fish . '|' . ( $size ? $size['slug'] : '' ) . '|' . $state . '|' . ( $spa ? '1' : '0' );
			$date  = Replay::is_date( $stay['sale_ready_date'] ?? null ) ? (string) $stay['sale_ready_date'] : '';

			// The name printed: a product's spelling before a typed one.
			if ( ! isset( $names[ $fish ] ) || ( ! $names[ $fish ]['product'] && ! empty( $stay['product_id'] ) ) ) {
				$names[ $fish ] = array(
					'name'    => (string) ( $stay['species'] ?? '' ),
					'product' => ! empty( $stay['product_id'] ),
				);
			}

			if ( ! isset( $slots[ $key ] ) ) {
				$slots[ $key ] = count( $lines );
				$lines[]       = array(
					'key'         => $fish,
					'species'     => (string) ( $stay['species'] ?? '' ),
					'repeat'      => false,
					'unknown'     => false,
					'size'        => $size,
					'state'       => $state,
					'in_spa'      => $spa,
					'qty'         => 0,
					'day_min'     => null,
					'day_max'     => null,
					'day_unknown' => false,
					'sale_dates'  => array(),
					'no_sale'     => 0,
					'stays'       => array(),
				);
			}
			$at = $slots[ $key ];

			$lines[ $at ]['stays'][] = $stay;
			$lines[ $at ]['qty']    += (int) ( $stay['qty_live'] ?? 0 );
			if ( ! empty( $stay['needs_id'] ) ) {
				$lines[ $at ]['unknown'] = true;
			}
			if ( isset( $stay['day'] ) && null !== $stay['day'] ) {
				$day                     = (int) $stay['day'];
				$lines[ $at ]['day_min'] = null === $lines[ $at ]['day_min'] ? $day : min( $lines[ $at ]['day_min'], $day );
				$lines[ $at ]['day_max'] = null === $lines[ $at ]['day_max'] ? $day : max( $lines[ $at ]['day_max'], $day );
			} elseif ( ! empty( $stay['checkin_unknown'] ) ) {
				$lines[ $at ]['day_unknown'] = true;
			}
			if ( '' === $date ) {
				$lines[ $at ]['no_sale']++;
				$none++;
			} elseif ( ! in_array( $date, $lines[ $at ]['sale_dates'], true ) ) {
				$lines[ $at ]['sale_dates'][] = $date;
			}
		}

		foreach ( $lines as $at => $line ) {
			sort( $lines[ $at ]['sale_dates'] );
			$lines[ $at ]['species'] = $names[ $line['key'] ]['name'];
			$lines[ $at ]['repeat']  = $at > 0 && $lines[ $at - 1 ]['key'] === $line['key'];
		}

		return array(
			'lines'   => $lines,
			'stays'   => count( $stays ),
			'no_sale' => $none,
		);
	}

	/**
	 * A line's day beside its count: "day 15" when its stays agree, "day
	 * 15–20" when they do not, "day ?" when no check-in is known — and
	 * "day 15 / ?" when only some are. No number is ever guessed; ''
	 * without either. "QT day …" on a temporary tank's card, where the
	 * tank has a day of its own.
	 *
	 * @param array $line day_min, day_max, day_unknown.
	 * @param bool  $qt   Say "QT day".
	 * @return string
	 */
	public static function day_text( array $line, $qt = false ) {
		$days = '';
		if ( isset( $line['day_min'], $line['day_max'] ) ) {
			$days = (int) $line['day_min'] === (int) $line['day_max'] ? (string) (int) $line['day_min'] : (int) $line['day_min'] . '–' . (int) $line['day_max'];
		}
		if ( ! empty( $line['day_unknown'] ) ) {
			$days = '' === $days ? '?' : $days . ' / ?';
		}
		if ( '' === $days ) {
			return '';
		}
		/* translators: %s: a day number, a range of them ("15–20") or "?" */
		return sprintf( $qt ? __( 'QT day %s', 'fishotel-misc-plugin' ) : __( 'day %s', 'fishotel-misc-plugin' ), $days );
	}

	/**
	 * What a line says about its sale date: nothing once it is for sale
	 * (its badge says so) or while none of its stays has a date (the
	 * card's foot counts those); else "on sale 12-12-26", or the first
	 * and the last date when its stays differ. A gathered line never
	 * lends a date to a group that has none: "on sale 12-12-26 · 1 not
	 * set" when some of its stays have a date and some do not.
	 *
	 * @param array $line state, sale_dates, no_sale.
	 * @return string
	 */
	public static function sale_text( array $line ) {
		$dates = array_values( (array) ( $line['sale_dates'] ?? array() ) );
		if ( 'ready' === (string) ( $line['state'] ?? '' ) || ! $dates ) {
			return '';
		}
		if ( 1 === count( $dates ) ) {
			/* translators: %s: date */
			$text = sprintf( __( 'on sale %s', 'fishotel-misc-plugin' ), Dates::us( $dates[0] ) );
		} else {
			/* translators: 1: the first sale date, 2: the last */
			$text = sprintf( __( 'on sale %1$s – %2$s', 'fishotel-misc-plugin' ), Dates::us( $dates[0] ), Dates::us( $dates[ count( $dates ) - 1 ] ) );
		}
		$none = (int) ( $line['no_sale'] ?? 0 );
		if ( $none > 0 ) {
			/* translators: 1: "on sale <date>", 2: how many groups of the line have no sale date */
			$text = sprintf( __( '%1$s · %2$d not set', 'fishotel-misc-plugin' ), $text, $none );
		}
		return $text;
	}

	/**
	 * Whether a line matches the Board's species filter: one of its stays
	 * does (an empty filter matches nothing here — no line is lit).
	 *
	 * @param array  $line    stays.
	 * @param string $species The filter.
	 * @return bool
	 */
	public static function matches( array $line, $species ) {
		$species = trim( (string) $species );
		if ( '' === $species ) {
			return false;
		}
		foreach ( (array) ( $line['stays'] ?? array() ) as $stay ) {
			if ( false !== stripos( (string) ( $stay['species'] ?? '' ), $species ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The quarantine tank each stay came out of: stay id → tank number.
	 * Read from its moves in order — a move of the whole group out of
	 * quarantine (the Spa, or a temporary tank set up as quarantine) into
	 * anything else names the tank; a move back into quarantine forgets
	 * it. The fish a split leaves behind have not moved. A stay that never
	 * made such a move is not in the list.
	 *
	 * @param array[]            $events Move events (hydrated), any order.
	 * @param array<int, string> $areas  tank id => area (Store::tank_areas()).
	 * @return array<int, int>
	 */
	public static function origins( array $events, array $areas ) {
		$out = array();
		foreach ( Replay::sort_events( $events ) as $event ) {
			if ( ! Replay::is_whole_move( $event ) ) {
				continue;
			}
			$stay_id = (int) ( $event['stay_id'] ?? 0 );
			$from    = Replay::event_area( $event, 'from', $areas );
			$to      = Replay::event_area( $event, 'to', $areas );
			if ( 'spa' === $to ) {
				unset( $out[ $stay_id ] );
			} elseif ( 'spa' === $from && ! empty( $event['from_tank_number'] ) ) {
				$out[ $stay_id ] = (int) $event['from_tank_number'];
			}
		}
		return $out;
	}
}
