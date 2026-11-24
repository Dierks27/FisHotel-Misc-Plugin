<?php
/**
 * Clean Room reserved fish — PURE: a fish a customer bought is RESERVED
 * in its tank when the order comes in, listed to PULL on its ship day,
 * and taken out of the tank by itself once the order has SHIPPED ("so
 * when we sell a fish we know what tank it's coming out of, and it will
 * automatically get pulled from the clean tanks").
 *
 *   reserved  each fish line of an open order (processing, on hold, a
 *             combined box) is given its tank group(s) ONCE and the
 *             choice is kept on the order item (_fh_stay = {stay: fish});
 *             a line of 3 may be spread over two tanks. Which group: the
 *             Invoices section's link, then the group the same customer
 *             was offered by a request, then the same product AND size,
 *             then the same name AND size — in clean water, with fish no
 *             other order holds ("free"), the group that ships first
 *             (for sale longest) first. A fish matched only by product or
 *             by name, with a size either side leaves open, is never
 *             reserved by itself: "tank not known", and asked again on the
 *             next read until the order leaves (logging the fish later
 *             fixes it). A reservation once made never moves by itself.
 *             Reserved is NOT an event: the tank's count is unchanged, the
 *             number reserved is worked out from the open orders.
 *   pull      the lines going out today (the feeding rule's orders),
 *             building → tank → fish → order, a line already shipped
 *             ticked, a line with no tank "Tank not known".
 *   shipped   once a line has left (its shipment, the order shipped or
 *             completed, a local pickup or "in another order's box") ONE
 *             `sold` event per tank group, noted "order:N item:M", takes
 *             its fish out — written as the DIFFERENCE between what is
 *             wanted and what that note already took out, so it can run
 *             any number of times (a negative difference puts them back:
 *             tracking removed, the order moved back). Never more than
 *             the group has alive: that line is "not found" instead.
 *
 * Only an order seen OPEN after the switch-on can ever be taken out by
 * itself (a line carries _fh_stay_seen): switching this on never takes a
 * fish out for an order that had already left.
 *
 * No WordPress beyond the translation helpers: orders, lines, stays and
 * events are plain arrays (Reserve_Shop is the edge).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reserve
 */
class Reserve {

	/**
	 * The settings key of the one tick (Settings → Numbers), on by default.
	 *
	 * @var string
	 */
	const SETTING = 'reserve_sold_fish';

	/**
	 * The option holding the moment the feature first ran (UNIX time) —
	 * kept when it is switched off and on again.
	 *
	 * @var string
	 */
	const SINCE_OPTION = 'fishotel_cleanroom_reserve_since';

	/**
	 * Order item meta. A fish of an invoice kept as one line ("i:<item>:<n>")
	 * has its own keys with "_<n>" added.
	 */
	const META        = '_fh_stay';        // The reservation: {stay id: fish}.
	const META_SEEN   = '_fh_stay_seen';   // When the line was first seen open (UNIX time) — the gate.
	const META_WAS    = '_fh_stay_was';    // Groups it was reserved in before a correction (their events are put back).
	const META_IGNORE = '_fh_stay_ignore'; // "Ignore" on the not-found list.

	/**
	 * The note of a shipped event: "order:<order id> item:<item id>[:<n>]".
	 *
	 * @var string
	 */
	const NOTE_ORDER = 'order:';

	/**
	 * The source of the events written here.
	 *
	 * @var string
	 */
	const SOURCE = 'order';

	/**
	 * Order statuses (without "wc-"): open (reserved, not gone), and gone.
	 */
	const OPEN_STATUSES = array( 'processing', 'on-hold', 'fulfillment' );
	const LEFT_STATUSES = array( 'shipped', 'completed' );

	/**
	 * ShipTracker's "how it went out without a label of its own"
	 * (_fst_ship_mode): a local pickup, or in another order's box.
	 *
	 * @var string[]
	 */
	const LEFT_MODES = array( 'local', 'with_order' );

	/* ============================================================== */
	/*  Keys, notes and the stored reservation                         */
	/* ============================================================== */

	/**
	 * The meta key of a line: the base for "i:<item>", base + "_<n>" for
	 * "i:<item>:<n>" — null for a line that is no order item of its own.
	 *
	 * @param string $base A META_ constant.
	 * @param string $ref  The line's ref (Lost Fish Orders::lines()).
	 * @return string|null
	 */
	public static function key( $base, $ref ) {
		if ( ! preg_match( '/^i:(\d+)(?::(\d+))?$/', (string) $ref, $m ) || (int) $m[1] <= 0 ) {
			return null;
		}
		return (string) $base . ( isset( $m[2] ) && '' !== $m[2] ? '_' . (int) $m[2] : '' );
	}

	/**
	 * The order item a ref names (0 = none).
	 *
	 * @param string $ref Ref.
	 * @return int
	 */
	public static function item_of( $ref ) {
		return preg_match( '/^i:(\d+)(?::\d+)?$/', (string) $ref, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Whether a meta key is one of this feature's (what drops the caches).
	 *
	 * @param mixed $key Meta key.
	 * @return bool
	 */
	public static function is_meta_key( $key ) {
		return is_string( $key ) && 0 === strpos( $key, self::META );
	}

	/**
	 * The note of a line's shipped events.
	 *
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @return string '' for a line that is no order item.
	 */
	public static function note( $order_id, $ref ) {
		if ( (int) $order_id <= 0 || ! preg_match( '/^i:(\d+)(?::(\d+))?$/', (string) $ref, $m ) ) {
			return '';
		}
		return self::NOTE_ORDER . (int) $order_id . ' item:' . (int) $m[1] . ( isset( $m[2] ) && '' !== $m[2] ? ':' . (int) $m[2] : '' );
	}

	/**
	 * What a shipped event's note names.
	 *
	 * @param mixed $note Note.
	 * @return array{order_id: int, ref: string}|null
	 */
	public static function parse_note( $note ) {
		if ( ! is_string( $note ) || ! preg_match( '/^order:(\d+) item:(\d+)(?::(\d+))?$/', $note, $m ) ) {
			return null;
		}
		return array(
			'order_id' => (int) $m[1],
			'ref'      => 'i:' . (int) $m[2] . ( isset( $m[3] ) && '' !== $m[3] ? ':' . (int) $m[3] : '' ),
		);
	}

	/**
	 * A stored reservation: stay id => fish, every one above zero, in the
	 * order it was made.
	 *
	 * @param mixed $raw Array or JSON.
	 * @return array<int, int>
	 */
	public static function held( $raw ) {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $stay => $qty ) {
			if ( is_numeric( $stay ) && (int) $stay > 0 && is_numeric( $qty ) && (int) $qty > 0 ) {
				$out[ (int) $stay ] = ( $out[ (int) $stay ] ?? 0 ) + (int) $qty;
			}
		}
		return $out;
	}

	/**
	 * A reservation as it is stored.
	 *
	 * @param array<int, int> $held Held.
	 * @return string JSON object.
	 */
	public static function encode( array $held ) {
		$out = array();
		foreach ( self::held( $held ) as $stay => $qty ) {
			$out[ (string) $stay ] = $qty;
		}
		return (string) json_encode( (object) $out );
	}

	/**
	 * How many fish a reservation holds.
	 *
	 * @param array<int, int> $held Held.
	 * @return int
	 */
	public static function total( array $held ) {
		return (int) array_sum( self::held( $held ) );
	}

	/**
	 * A reservation cut to what the line still asks (a refund takes fish
	 * off the end).
	 *
	 * @param array<int, int> $held Held.
	 * @param int             $qty  The line's fish now.
	 * @return array<int, int>
	 */
	public static function trim( array $held, $qty ) {
		$left = max( 0, (int) $qty );
		$out  = array();
		foreach ( self::held( $held ) as $stay => $n ) {
			if ( $left <= 0 ) {
				break;
			}
			$out[ $stay ] = min( $n, $left );
			$left        -= $out[ $stay ];
		}
		return $out;
	}

	/* ============================================================== */
	/*  Which group, how many                                          */
	/* ============================================================== */

	/**
	 * The group that ships first comes first: for sale before not, the
	 * earliest sale date (none last), the earliest check-in (unknown
	 * last) — the order the product page's "next" uses — then the oldest
	 * group.
	 *
	 * @param array $a Hydrated stay.
	 * @param array $b Hydrated stay.
	 * @return int
	 */
	public static function ships_first( array $a, array $b ) {
		$by = Store::ships_first( $a, $b );
		return 0 !== $by ? $by : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
	}

	/**
	 * The fish of a group no other order holds.
	 *
	 * @param array $stay   Stay (id, qty_live).
	 * @param array $counts counts(): stay id => { qty }.
	 * @return int
	 */
	public static function free( array $stay, array $counts ) {
		return max( 0, (int) ( $stay['qty_live'] ?? 0 ) - (int) ( $counts[ (int) ( $stay['id'] ?? 0 ) ]['qty'] ?? 0 ) );
	}

	/**
	 * The groups a line may be reserved in, strongest rung first:
	 *
	 *   invoice  the Invoices section's link — the group it names, in any
	 *            water (a fish bought on an invoice while in quarantine
	 *            moves with its group);
	 *   request  a group the same customer was offered by a request
	 *            (line.offered: stay ids), in clean water, of the line's
	 *            own fish and size (Sold_Line::exact());
	 *   product  / name — the ladder (Sold_Line::stays()) over the clean
	 *            groups, kept only where it is sure of the size
	 *            (Sold_Line::exact()); otherwise 'coarse' with no group:
	 *            a guess never takes a fish by itself.
	 *
	 * @param array   $line  A fish line (Feeding::fish_lines(), plus offered).
	 * @param array[] $clean The open groups in clean water.
	 * @param array[] $all   Every open group (the invoice rung's).
	 * @return array{rung: string, stays: array[]} rung '' (nothing fits) or 'coarse' (only a guess) have no stays.
	 */
	public static function candidates( array $line, array $clean, array $all = array() ) {
		$alive = static function ( array $stays ) {
			return array_values(
				array_filter(
					$stays,
					static function ( $stay ) {
						return is_array( $stay ) && (int) ( $stay['qty_live'] ?? 0 ) > 0;
					}
				)
			);
		};
		$clean = $alive( $clean );
		$all   = $alive( $all ? $all : $clean );

		$link = isset( $line['link'] ) && is_array( $line['link'] ) ? (int) ( $line['link']['stay_id'] ?? 0 ) : 0;
		if ( $link > 0 ) {
			foreach ( $all as $stay ) {
				if ( (int) $stay['id'] === $link ) {
					return array(
						'rung'  => Sold_Line::RUNG_INVOICE,
						'stays' => array( $stay ),
					);
				}
			}
		}

		$offered = array_map( 'intval', isset( $line['offered'] ) && is_array( $line['offered'] ) ? $line['offered'] : array() );
		if ( $offered ) {
			$mine = array();
			$ask  = array_merge( $line, array( 'held' => array(), 'link' => array() ) );
			foreach ( $clean as $stay ) {
				// The group offered, and only when it is that fish in that size: a Medium once offered never
				// answers for a Large bought now (a request is matched on its product, not its size).
				if ( in_array( (int) $stay['id'], $offered, true ) && Sold_Line::exact( $ask, $stay ) ) {
					$mine[] = $stay;
				}
			}
			if ( $mine ) {
				return array(
					'rung'  => 'request',
					'stays' => $mine,
				);
			}
		}

		// The ladder without the two rungs answered above (the held one is the reservation itself).
		$ask = array_merge( $line, array( 'held' => array() ) );
		if ( isset( $ask['link'] ) && is_array( $ask['link'] ) ) {
			$ask['link']['stay_id'] = 0;
		}
		$found = Sold_Line::stays( $ask, $clean );
		if ( '' === $found['rung'] ) {
			return array(
				'rung'  => '',
				'stays' => array(),
			);
		}
		$sure = array();
		foreach ( $found['stays'] as $stay ) {
			if ( Sold_Line::exact( $ask, $stay ) ) {
				$sure[] = $stay;
			}
		}
		return array(
			'rung'  => $sure ? $found['rung'] : 'coarse',
			'stays' => $sure,
		);
	}

	/**
	 * How many fish of a line go to which group: the group that ships
	 * first first, never more than a group has free.
	 *
	 * @param int     $missing    Fish still to place.
	 * @param array[] $candidates candidates()['stays'].
	 * @param array   $counts     counts() so far.
	 * @return array<int, int> Stay id => fish.
	 */
	public static function assign( $missing, array $candidates, array $counts ) {
		usort( $candidates, array( self::class, 'ships_first' ) );
		$left = max( 0, (int) $missing );
		$out  = array();
		foreach ( $candidates as $stay ) {
			if ( $left <= 0 ) {
				break;
			}
			$take = min( $left, self::free( $stay, $counts ) );
			if ( $take > 0 ) {
				$out[ (int) $stay['id'] ] = $take;
				$left                    -= $take;
			}
		}
		return $out;
	}

	/**
	 * Add a line's held fish to the counts.
	 *
	 * @param array           $counts Counts (by reference).
	 * @param array<int, int> $held   Held (trimmed).
	 * @param string          $number The order's number.
	 */
	private static function count_in( array &$counts, array $held, $number ) {
		foreach ( $held as $stay => $qty ) {
			if ( ! isset( $counts[ $stay ] ) ) {
				$counts[ $stay ] = array(
					'qty'    => 0,
					'orders' => array(),
				);
			}
			$counts[ $stay ]['qty'] += (int) $qty;
			if ( '' !== (string) $number && ! in_array( (string) $number, $counts[ $stay ]['orders'], true ) ) {
				$counts[ $stay ]['orders'][] = (string) $number;
			}
		}
	}

	/**
	 * Every open order's reservation, and the lines still to place.
	 *
	 * Each line already reserved counts as it is (cut to its fish now);
	 * then each line short of fish — the oldest order first — is given
	 * groups out of what is still free. Nothing already held moves.
	 *
	 * @param array[] $orders Open orders, oldest first: id, number, lines (Feeding::fish_lines() rows with
	 *                        item_id, ref, held, offered; a line already gone is not passed).
	 * @param array[] $clean  The open groups in clean water.
	 * @param array[] $all    Every open group.
	 * @param array   $counts What other orders (not passed) already hold: stay id => { qty, orders }.
	 * @return array{counts: array<int, array{qty: int, orders: string[]}>, adds: array<int, array<string, array<int, int>>>,
	 *               unknown: array<int, array<string, int>>}
	 *         adds: order id => ref => the groups to add; unknown: order id => ref => fish no group was found for.
	 */
	public static function plan( array $orders, array $clean, array $all = array(), array $counts = array() ) {
		foreach ( $orders as $order ) {
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				self::count_in( $counts, self::trim( (array) ( $line['held'] ?? array() ), (int) ( $line['qty'] ?? 0 ) ), (string) ( $order['number'] ?? '' ) );
			}
		}

		$adds    = array();
		$unknown = array();
		foreach ( $orders as $order ) {
			$id = (int) ( $order['id'] ?? 0 );
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				$ref     = (string) ( $line['ref'] ?? '' );
				$missing = (int) ( $line['qty'] ?? 0 ) - self::total( self::trim( (array) ( $line['held'] ?? array() ), (int) ( $line['qty'] ?? 0 ) ) );
				if ( $missing <= 0 || null === self::key( self::META, $ref ) ) {
					continue;
				}
				$found = self::candidates( $line, $clean, $all );
				$add   = self::assign( $missing, $found['stays'], $counts );
				if ( $add ) {
					$adds[ $id ][ $ref ] = $add;
					self::count_in( $counts, $add, (string) ( $order['number'] ?? '' ) );
				}
				if ( $missing - self::total( $add ) > 0 && empty( $line['loose'] ) ) {
					$unknown[ $id ][ $ref ] = $missing - self::total( $add );
				}
			}
		}

		return array(
			'counts'  => $counts,
			'adds'    => $adds,
			'unknown' => $unknown,
		);
	}

	/**
	 * A reservation with fish added (the groups already there keep their
	 * place).
	 *
	 * @param array<int, int> $held Held.
	 * @param array<int, int> $add  Added.
	 * @return array<int, int>
	 */
	public static function merge( array $held, array $add ) {
		$out = self::held( $held );
		foreach ( self::held( $add ) as $stay => $qty ) {
			$out[ $stay ] = ( $out[ $stay ] ?? 0 ) + $qty;
		}
		return $out;
	}

	/**
	 * The other clean groups a line's fish may have come out of — what
	 * "Pulled from a different tank" and "It was tank…" offer: every group
	 * of that fish the ladder knows in clean water (a size nobody wrote
	 * down too: the owner decides), with fish alive or its last fish gone
	 * today, minus the ones it is held in now.
	 *
	 * @param array   $line    A line (name, names, product_id, variation_id, size_slug, size_label).
	 * @param array[] $clean   The clean groups (a closed one may carry left_today).
	 * @param int[]   $exclude Stay ids not to offer.
	 * @return array[] The groups, the one that ships first first.
	 */
	public static function alternatives( array $line, array $clean, array $exclude = array() ) {
		$ask = array_merge( $line, array( 'held' => array(), 'link' => array() ) );
		$out = array();
		foreach ( Sold_Line::stays( $ask, $clean )['stays'] as $stay ) {
			if ( ! in_array( (int) $stay['id'], array_map( 'intval', $exclude ), true ) ) {
				$out[] = $stay;
			}
		}
		usort( $out, array( self::class, 'ships_first' ) );
		return $out;
	}

	/* ============================================================== */
	/*  Shipped                                                        */
	/* ============================================================== */

	/**
	 * Whether a line has left the building.
	 *
	 * @param array $signals status (without "wc-"), mode (_fst_ship_mode), whole (a ShipTracker shipment
	 *                       with no item list), items (item id => true: the items a shipment names), box (the
	 *                       same for the combined box the order is in, or null).
	 * @param int   $item_id The line's order item.
	 * @return bool
	 */
	public static function left( array $signals, $item_id ) {
		if ( in_array( Feeding::status( $signals['status'] ?? '' ), self::LEFT_STATUSES, true ) ) {
			return true;
		}
		if ( in_array( (string) ( $signals['mode'] ?? '' ), self::LEFT_MODES, true ) || ! empty( $signals['whole'] ) ) {
			return true;
		}
		if ( (int) $item_id > 0 && ! empty( $signals['items'][ (int) $item_id ] ) ) {
			return true;
		}
		$box = isset( $signals['box'] ) && is_array( $signals['box'] ) ? $signals['box'] : null;
		return null !== $box && self::left( array_merge( $box, array( 'items' => array(), 'box' => null ) ), 0 );
	}

	/**
	 * What one line's events have taken out of each group so far.
	 *
	 * @param array[] $events The sold events of the groups concerned (id, stay_id, type, qty, note).
	 * @param string  $note   The line's note (note()).
	 * @return array{net: array<int, int>, undone: array<int, bool>}
	 *         net: stay id => fish taken out (a hand undo of one of them counted too); undone: the groups
	 *         where somebody undid one of them by hand — left alone from then on.
	 */
	public static function written( array $events, $note ) {
		$net    = array();
		$undone = array();
		$mine   = array();
		foreach ( $events as $event ) {
			if ( 'sold' === (string) ( $event['type'] ?? '' ) && (string) $note === (string) ( $event['note'] ?? '' ) && '' !== (string) $note ) {
				$stay          = (int) $event['stay_id'];
				$mine[ (int) $event['id'] ] = $stay;
				$net[ $stay ]  = ( $net[ $stay ] ?? 0 ) + (int) ( $event['qty'] ?? 0 );
			}
		}
		foreach ( $events as $event ) {
			$text = (string) ( $event['note'] ?? '' );
			if ( 0 !== strpos( $text, Replay::NOTE_UNDO ) ) {
				continue;
			}
			$id = (int) substr( $text, strlen( Replay::NOTE_UNDO ) );
			if ( isset( $mine[ $id ] ) ) {
				$stay            = $mine[ $id ];
				$net[ $stay ]    = ( $net[ $stay ] ?? 0 ) + (int) ( $event['qty'] ?? 0 );
				$undone[ $stay ] = true;
			}
		}
		return array(
			'net'    => $net,
			'undone' => $undone,
		);
	}

	/**
	 * How many fish each group should have given to a line by now.
	 *
	 *   gone       what it holds (cut to its fish now) — but never less
	 *              than was already taken out: a refund after the box left
	 *              (a fish dead on arrival) changes nothing in the tanks;
	 *              a group no longer held (the owner picked another tank)
	 *              gives its fish back;
	 *   open       nothing: it has not left (any more) — tracking removed,
	 *              the order moved back;
	 *   otherwise  as it is (cancelled, refunded, failed after it left).
	 *
	 * @param bool            $gone    left().
	 * @param string          $status  The order's status.
	 * @param array<int, int> $held    The reservation as stored.
	 * @param int             $qty     The line's fish now (after refunds).
	 * @param array<int, int> $net     written()['net'].
	 * @return array<int, int> Stay id => fish.
	 */
	public static function wanted( $gone, $status, array $held, $qty, array $net ) {
		$out = array();
		if ( $gone ) {
			$cut = self::trim( $held, $qty );
			foreach ( self::held( $held ) as $stay => $n ) {
				$out[ (int) $stay ] = max( (int) ( $cut[ $stay ] ?? 0 ), (int) ( $net[ $stay ] ?? 0 ) );
			}
			foreach ( $net as $stay => $qty ) {
				if ( ! isset( $out[ (int) $stay ] ) ) {
					$out[ (int) $stay ] = 0;
				}
			}
			return $out;
		}
		if ( in_array( Feeding::status( $status ), self::OPEN_STATUSES, true ) ) {
			foreach ( $net as $stay => $qty ) {
				$out[ (int) $stay ] = 0;
			}
			return $out;
		}
		foreach ( $net as $stay => $qty ) {
			$out[ (int) $stay ] = (int) $qty;
		}
		return $out;
	}

	/**
	 * The events to write: wanted − already taken out, per group. A group
	 * somebody undid by hand is left alone; a group with fewer fish alive
	 * than it should give is not written at all — those fish are "short"
	 * and the line goes to the not-found list.
	 *
	 * @param array<int, int>  $wanted wanted().
	 * @param array<int, int>  $net    written()['net'].
	 * @param array<int, int>  $alive  Stay id => fish alive now (a group not known = 0).
	 * @param array<int, bool> $undone written()['undone'].
	 * @return array{writes: array<int, int>, short: int}
	 */
	public static function diff( array $wanted, array $net, array $alive, array $undone = array() ) {
		$writes = array();
		$short  = 0;
		foreach ( $wanted as $stay => $qty ) {
			$delta = (int) $qty - (int) ( $net[ $stay ] ?? 0 );
			if ( 0 === $delta || ! empty( $undone[ $stay ] ) ) {
				continue;
			}
			if ( $delta > 0 && $delta > (int) ( $alive[ $stay ] ?? 0 ) ) {
				$short += $delta;
				continue;
			}
			$writes[ (int) $stay ] = $delta;
		}
		return array(
			'writes' => $writes,
			'short'  => $short,
		);
	}

	/**
	 * Whether a line may ever be taken out of a tank by itself: it was
	 * seen open after the switch-on (or reserved then).
	 *
	 * @param array $line seen (UNIX time), held.
	 * @return bool
	 */
	public static function gated( array $line ) {
		return (int) ( $line['seen'] ?? 0 ) > 0 || ! empty( $line['held'] );
	}

	/**
	 * The fish a line that has gone still keeps out of the free count:
	 * what it holds less what its events already took out of each group
	 * (until they are written, those fish are spoken for — never given to
	 * another order).
	 *
	 * @param array<int, int> $held Held.
	 * @param int             $qty  The line's fish now.
	 * @param array<int, int> $net  written()['net'].
	 * @return array<int, int>
	 */
	public static function holding( array $held, $qty, array $net ) {
		$out = array();
		foreach ( self::trim( $held, $qty ) as $stay => $n ) {
			$left = $n - max( 0, (int) ( $net[ $stay ] ?? 0 ) );
			if ( $left > 0 ) {
				$out[ $stay ] = $left;
			}
		}
		return $out;
	}

	/**
	 * Whether a line that has ALREADY gone may still be given a tank by
	 * itself (and so be taken out of it) — install safety:
	 *
	 *   - a line looked at while its order was open (seen, or held) keeps
	 *     what it holds: a fish whose tank was not known then is "not
	 *     found" — never taken out of a group logged after it left;
	 *   - otherwise only when it is sure the line left AFTER the switch-on:
	 *     its order came in after it, or the order was open until the very
	 *     change being worked and nothing but that change says it left (no
	 *     shipment, no pickup, no box gone before).
	 *
	 * A line of an order that had left before the switch-on is so never
	 * given a tank, whatever hook or sweep looks at it later.
	 *
	 * @param array $line     A fish line (seen, held, item_id).
	 * @param array $order    created (UNIX time), signals.
	 * @param int   $since    The switch-on time.
	 * @param bool  $was_open The order was open until the change being worked.
	 * @return bool
	 */
	public static function late_ok( array $line, array $order, $since, $was_open ) {
		if ( self::gated( $line ) || (int) $since <= 0 ) {
			return false;
		}
		if ( (int) ( $order['created'] ?? 0 ) >= (int) $since ) {
			return true;
		}
		$signals = (array) ( $order['signals'] ?? array() );
		return $was_open && ! self::left( array_merge( $signals, array( 'status' => 'processing' ) ), (int) ( $line['item_id'] ?? 0 ) );
	}

	/**
	 * Whether an open order's line that was never looked at is left alone
	 * — install safety for the orders open at the switch-on that had in
	 * fact already gone and were never marked: one that came in before the
	 * switch-on and was to be delivered today or earlier (it shipped the
	 * day before its delivery date). It is never reserved, never marked
	 * seen, and so never taken out.
	 *
	 * @param array  $line  seen, held.
	 * @param array  $order created (UNIX time), delivery (site date).
	 * @param int    $since The switch-on time.
	 * @param string $today Site date.
	 * @return bool
	 */
	public static function stale( array $line, array $order, $since, $today ) {
		if ( self::gated( $line ) || (int) ( $order['created'] ?? 0 ) >= (int) $since ) {
			return false;
		}
		$delivery = (string) ( $order['delivery'] ?? '' );
		return Replay::is_date( $delivery ) && Replay::is_date( (string) $today ) && strcmp( $delivery, (string) $today ) <= 0;
	}

	/**
	 * When a shipped event happened: the start of the line's ship day (the
	 * day before its delivery date) when that is today or the two days
	 * before — the fish were pulled that day, so a recount logged after
	 * the pull already counted them gone and is not taken off twice — else
	 * now ('').
	 *
	 * @param string $delivery    The order's delivery date (site date, '' = none).
	 * @param string $today       Site date.
	 * @param string $ship_start  The ship day's start as "Y-m-d H:i:s" UTC (worked out by the caller).
	 * @param string $checkin     The group's check-in date (site date, '' = not known).
	 * @return string "Y-m-d H:i:s" UTC, or '' for now.
	 */
	public static function shipped_at( $delivery, $today, $ship_start, $checkin = '' ) {
		if ( ! Replay::is_date( (string) $delivery ) || ! Replay::is_date( (string) $today ) || '' === (string) $ship_start ) {
			return '';
		}
		$ship_day = Replay::add_days( (string) $delivery, -1 );
		if ( strcmp( $ship_day, (string) $today ) > 0 || strcmp( $ship_day, Replay::add_days( (string) $today, -2 ) ) < 0 ) {
			return '';
		}
		if ( ! Replay::is_date( (string) $checkin ) || strcmp( (string) $checkin, $ship_day ) >= 0 ) {
			return ''; // A group checked in on the ship day or later (or not known when): the event is now.
		}
		return (string) $ship_start;
	}

	/* ============================================================== */
	/*  Pull today                                                     */
	/* ============================================================== */

	/**
	 * The ship day's pull list: building → tank → fish → order. A line
	 * spread over two tanks is under each with its own fish; a line
	 * already gone ticked ("Shipped": its order left, or its fish were
	 * already taken out of that tank); a line no group holds is "Tank not
	 * known" (an invert in no tank is only named, quietly: inverts are
	 * often in no tank on the site) — ticked too once its order left.
	 *
	 * @param array[] $lines   Today's fish lines (Ship_Lines::today()['lines']), each may carry left (its
	 *                         order or its own shipment has gone out).
	 * @param array[] $stays   The groups the reservations name, by id (hydrated; a closed one too).
	 * @param array   $shipped Note => stay id => fish taken out (written()['net'] per line).
	 * @return array{buildings: array[], unknown: array[], loose: array[], fish: int, shipped: int, left: int}
	 *         buildings: [ { id, label, tanks: [ { number, rows: [ { name, size, qty, number, order_id, ref,
	 *         stay_id, shipped } ] } ] } ]; unknown / loose: [ { qty, name, number, order_id, ref, shipped } ];
	 *         fish: every fish on the list; shipped: the ones gone; left: the ones still here.
	 */
	public static function pull( array $lines, array $stays, array $shipped ) {
		$buildings = array();
		$unknown   = array();
		$loose     = array();
		$fish      = 0;
		$gone_fish = 0;
		foreach ( $lines as $line ) {
			$qty = (int) ( $line['qty'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			$fish += $qty;
			$gone  = ! empty( $line['left'] );
			$base  = array(
				'name'     => (string) ( $line['name'] ?? '' ),
				'number'   => (string) ( $line['number'] ?? '' ),
				'order_id' => (int) ( $line['order_id'] ?? 0 ),
				'ref'      => (string) ( $line['ref'] ?? '' ),
			);
			$held  = self::trim( (array) ( $line['held'] ?? array() ), $qty );
			$note  = self::note( $base['order_id'], $base['ref'] );
			$rest  = $qty;
			foreach ( $held as $stay_id => $n ) {
				$stay = $stays[ $stay_id ] ?? null;
				if ( ! is_array( $stay ) ) {
					continue;
				}
				$rest -= $n;
				$out   = (int) ( $shipped[ $note ][ $stay_id ] ?? 0 );
				$done  = $gone || $out >= $n;
				$gone_fish += $done ? $n : max( 0, min( $n, $out ) );
				$bid   = (int) ( $stay['building_id'] ?? 0 );
				$tank  = (int) ( $stay['tank_number'] ?? 0 );
				if ( ! isset( $buildings[ $bid ] ) ) {
					$buildings[ $bid ] = array(
						'id'    => $bid,
						'label' => (string) ( $stay['building_label'] ?? '' ),
						'tanks' => array(),
					);
				}
				$buildings[ $bid ]['tanks'][ $tank ]['number'] = $tank;
				$buildings[ $bid ]['tanks'][ $tank ]['rows'][] = array_merge(
					$base,
					array(
						'name'    => '' !== trim( (string) ( $stay['species'] ?? '' ) ) ? (string) $stay['species'] : $base['name'],
						'size'    => (string) ( $stay['size_label'] ?? '' ),
						'qty'     => (int) $n,
						'stay_id' => (int) $stay_id,
						'shipped' => $done,
					)
				);
			}
			if ( $rest > 0 ) {
				$gone_fish += $gone ? $rest : 0;
				$row        = array_merge(
					$base,
					array(
						'qty'     => $rest,
						'shipped' => $gone,
					)
				);
				if ( ! empty( $line['loose'] ) ) {
					$loose[] = $row;
				} else {
					$unknown[] = $row;
				}
			}
		}
		ksort( $buildings );
		foreach ( $buildings as $bid => $building ) {
			ksort( $building['tanks'] );
			foreach ( $building['tanks'] as $number => $tank ) {
				usort(
					$tank['rows'],
					static function ( $a, $b ) {
						return strnatcasecmp( $a['name'], $b['name'] ) ?: strnatcmp( $a['number'], $b['number'] );
					}
				);
				$building['tanks'][ $number ] = $tank;
			}
			$building['tanks']   = array_values( $building['tanks'] );
			$buildings[ $bid ]   = $building;
		}
		return array(
			'buildings' => array_values( $buildings ),
			'unknown'   => $unknown,
			'loose'     => $loose,
			'fish'      => $fish,
			'shipped'   => $gone_fish,
			'left'      => max( 0, $fish - $gone_fish ),
		);
	}

	/**
	 * One tank's line of the pull list: "Tank 121 — 2 × Yellow Tang M
	 * (#1234), 1 × Flame Angel S (#1240)".
	 *
	 * @param array $tank { number, rows }.
	 * @return string
	 */
	public static function tank_text( array $tank ) {
		$parts = array();
		foreach ( (array) ( $tank['rows'] ?? array() ) as $row ) {
			$parts[] = self::row_text( $row );
		}
		/* translators: 1: tank number, 2: the fish to pull from it */
		return sprintf( __( 'Tank %1$d — %2$s', 'fishotel-misc-plugin' ), (int) ( $tank['number'] ?? 0 ), implode( ', ', $parts ) );
	}

	/**
	 * "2 × Yellow Tang M (#1234)".
	 *
	 * @param array $row qty, name, size, number.
	 * @return string
	 */
	public static function row_text( array $row ) {
		$name = trim( (string) ( $row['name'] ?? '' ) . ( '' !== trim( (string) ( $row['size'] ?? '' ) ) ? ' ' . trim( (string) $row['size'] ) : '' ) );
		$name = '' !== $name ? $name : __( 'a fish without a name', 'fishotel-misc-plugin' );
		return (int) ( $row['qty'] ?? 0 ) . ' × ' . $name . ( '' !== (string) ( $row['number'] ?? '' ) ? ' (#' . $row['number'] . ')' : '' );
	}

	/**
	 * A not-found entry's row: "1 × Firefish 2-3.5" (#1234)" — the shop
	 * title and the size, as Pull today's rows read; without a title, the
	 * line's own name (which already carries its size: never twice).
	 *
	 * @param array $entry A not-found entry: qty, name, names, size_label, number.
	 * @return string
	 */
	public static function lost_row_text( array $entry ) {
		$names = isset( $entry['names'] ) && is_array( $entry['names'] ) ? array_values( $entry['names'] ) : array();
		$title = trim( (string) ( $names[0] ?? '' ) );
		$size  = trim( (string) ( $entry['size_label'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) ( $entry['name'] ?? '' );
			$size  = '';
		}
		return self::row_text( array_merge( $entry, array( 'name' => $title, 'size' => $size ) ) );
	}

	/**
	 * "Tank not known — 1 × Blue Hippo Tang (#1251)" — the fish still
	 * here only (one whose order left is ticked on its own row instead).
	 *
	 * @param array[] $rows unknown rows.
	 * @return string
	 */
	public static function unknown_text( array $rows ) {
		$parts = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['shipped'] ) ) {
				$parts[] = self::row_text( $row );
			}
		}
		/* translators: %s: the fish */
		return $parts ? sprintf( __( 'Tank not known — %s', 'fishotel-misc-plugin' ), implode( ', ', $parts ) ) : '';
	}

	/**
	 * The pull list with its words: each row's ("2 × Yellow Tang M
	 * (#1234)"), the count ("8 fish today · 2 shipped"), the fish whose
	 * tank is not known — one row each — and the hint, the inverts in no
	 * tank.
	 *
	 * @param array $pull  pull().
	 * @param bool  $quiet The feeding block right above already says what to do about a fish with no tank:
	 *                     no hint here.
	 * @return array
	 */
	public static function pull_words( array $pull, $quiet = false ) {
		foreach ( (array) ( $pull['buildings'] ?? array() ) as $b => $building ) {
			foreach ( (array) ( $building['tanks'] ?? array() ) as $t => $tank ) {
				foreach ( (array) ( $tank['rows'] ?? array() ) as $r => $row ) {
					$pull['buildings'][ $b ]['tanks'][ $t ]['rows'][ $r ]['text'] = self::row_text( $row );
				}
			}
		}
		$pull['count_text'] = self::count_text( (int) ( $pull['fish'] ?? 0 ), (int) ( $pull['shipped'] ?? 0 ) );
		$waiting            = false;
		foreach ( (array) ( $pull['unknown'] ?? array() ) as $u => $row ) {
			$pull['unknown'][ $u ]['text'] = self::row_text( $row );
			$waiting                       = $waiting || empty( $row['shipped'] );
		}
		$pull['unknown_text'] = self::unknown_text( (array) ( $pull['unknown'] ?? array() ) );
		$pull['unknown_head'] = (array) ( $pull['unknown'] ?? array() ) ? __( 'Tank not known', 'fishotel-misc-plugin' ) : '';
		$pull['hint']         = $waiting && ! $quiet ? self::unknown_hint() : '';
		$loose                = array();
		foreach ( (array) ( $pull['loose'] ?? array() ) as $row ) {
			$loose[] = self::row_text( $row );
		}
		/* translators: %s: inverts, e.g. "2 × Test Snail (#1251)" */
		$pull['loose_text'] = $loose ? sprintf( __( 'Not in a tank on the site: %s', 'fishotel-misc-plugin' ), implode( ', ', $loose ) ) : '';
		return $pull;
	}

	/**
	 * "8 fish today · 2 shipped" / "8 fish today · all shipped" — '' on a
	 * day with none. Counted the same way however long the fish wait in
	 * their bags for a label.
	 *
	 * @param int $fish    Every fish on the list.
	 * @param int $shipped The ones gone.
	 * @return string
	 */
	public static function count_text( $fish, $shipped ) {
		$fish    = max( 0, (int) $fish );
		$shipped = min( $fish, max( 0, (int) $shipped ) );
		if ( $fish <= 0 ) {
			return '';
		}
		if ( $shipped >= $fish ) {
			/* translators: %d: fish */
			return sprintf( _n( '%d fish today · all shipped', '%d fish today · all shipped', $fish, 'fishotel-misc-plugin' ), $fish );
		}
		/* translators: 1: fish, 2: fish shipped */
		return sprintf( _n( '%1$d fish today · %2$d shipped', '%1$d fish today · %2$d shipped', $fish, 'fishotel-misc-plugin' ), $fish, $shipped );
	}

	/**
	 * What to do about a fish whose tank is not known — Pull today's own,
	 * short: log its group, or correct the one already there under
	 * another name (the feeding rule's advice, without the feeding).
	 *
	 * @return string
	 */
	public static function unknown_hint() {
		return __( 'Log its group on the Board — or, if it is already there under another name, correct that group first — and it is reserved by itself.', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	/**
	 * "2 reserved · #1234" / "3 reserved · #1234, #1240".
	 *
	 * @param int      $qty     Fish reserved.
	 * @param string[] $numbers Order numbers.
	 * @return string
	 */
	public static function reserved_text( $qty, array $numbers ) {
		if ( (int) $qty <= 0 ) {
			return '';
		}
		$numbers = array_values( array_filter( array_map( 'strval', $numbers ), 'strlen' ) );
		/* translators: %d: fish reserved */
		$text = sprintf( __( '%d reserved', 'fishotel-misc-plugin' ), (int) $qty );
		return $numbers ? $text . ' · #' . implode( ', #', $numbers ) : $text;
	}

	/**
	 * What the phone's hand "Shipped" says when the group holds fish for
	 * an order: "2 of these are reserved for #1234 and will come off by
	 * themselves when it ships".
	 *
	 * @param int      $qty     Fish reserved.
	 * @param string[] $numbers Order numbers.
	 * @return string
	 */
	public static function sold_note( $qty, array $numbers ) {
		if ( (int) $qty <= 0 ) {
			return '';
		}
		$numbers = array_values( array_filter( array_map( 'strval', $numbers ), 'strlen' ) );
		$list    = Feeding::sentence_list(
			array_map(
				static function ( $n ) {
					return '#' . $n;
				},
				$numbers
			)
		);
		if ( '' === $list ) {
			/* translators: %d: fish reserved */
			return sprintf( _n( '%d of these is reserved for an order and will come off by itself when it ships.', '%d of these are reserved for an order and will come off by themselves when it ships.', (int) $qty, 'fishotel-misc-plugin' ), (int) $qty );
		}
		/* translators: 1: fish reserved, 2: order numbers */
		if ( count( $numbers ) > 1 ) {
			/* translators: 1: fish reserved, 2: order numbers */
			return sprintf( _n( '%1$d of these is reserved for %2$s and will come off by itself when they ship.', '%1$d of these are reserved for %2$s and will come off by themselves when they ship.', (int) $qty, 'fishotel-misc-plugin' ), (int) $qty, $list );
		}
		/* translators: 1: fish reserved, 2: order number */
		return sprintf( _n( '%1$d of these is reserved for %2$s and will come off by itself when it ships.', '%1$d of these are reserved for %2$s and will come off by themselves when it ships.', (int) $qty, 'fishotel-misc-plugin' ), (int) $qty, $list );
	}

	/**
	 * A shipped event in the owner's word: "Shipped · order #1234 (2
	 * fish)" — '' for an event that is no order's.
	 *
	 * @param array $event type, qty, note.
	 * @return string
	 */
	public static function event_text( array $event ) {
		$order = 'sold' === (string) ( $event['type'] ?? '' ) ? self::parse_note( $event['note'] ?? '' ) : null;
		if ( null === $order ) {
			return '';
		}
		$qty = (int) ( $event['qty'] ?? 0 );
		if ( $qty < 0 ) {
			/* translators: 1: order number, 2: fish */
			return sprintf( _n( 'Back in the tank · order #%1$d (%2$d fish)', 'Back in the tank · order #%1$d (%2$d fish)', abs( $qty ), 'fishotel-misc-plugin' ), $order['order_id'], abs( $qty ) );
		}
		/* translators: 1: order number, 2: fish */
		return sprintf( _n( 'Shipped · order #%1$d (%2$d fish)', 'Shipped · order #%1$d (%2$d fish)', $qty, 'fishotel-misc-plugin' ), $order['order_id'], $qty );
	}

	/**
	 * The rest of a shipped event's line once its chip says "Shipped" /
	 * "Back in the tank": "order #1234 (2 fish)".
	 *
	 * @param array $event type, qty, note.
	 * @return string
	 */
	public static function event_detail( array $event ) {
		$order = 'sold' === (string) ( $event['type'] ?? '' ) ? self::parse_note( $event['note'] ?? '' ) : null;
		if ( null === $order ) {
			return '';
		}
		$qty = abs( (int) ( $event['qty'] ?? 0 ) );
		/* translators: 1: order number, 2: fish */
		return sprintf( _n( 'order #%1$d (%2$d fish)', 'order #%1$d (%2$d fish)', $qty, 'fishotel-misc-plugin' ), $order['order_id'], $qty );
	}

	/**
	 * The word on an event's type chip: "Shipped" for a fish taken out by
	 * its order, "Back in the tank" for one put back — '' for any other
	 * event (its own type is shown).
	 *
	 * @param array $event type, qty, note.
	 * @return string
	 */
	public static function type_label( array $event ) {
		if ( 'sold' !== (string) ( $event['type'] ?? '' ) || null === self::parse_note( $event['note'] ?? '' ) ) {
			return '';
		}
		return (int) ( $event['qty'] ?? 0 ) < 0 ? __( 'Back in the tank', 'fishotel-misc-plugin' ) : __( 'Shipped', 'fishotel-misc-plugin' );
	}

	/**
	 * "Shop says 5 in stock, tanks have 3 free" — '' when they agree or
	 * the shop does not count it.
	 *
	 * @param int|null $stock The shop's stock (null = not tracked).
	 * @param int      $free  Fish alive in the tanks no open order holds.
	 * @return string
	 */
	public static function stock_text( $stock, $free ) {
		if ( null === $stock || (int) $stock === (int) $free ) {
			return '';
		}
		/* translators: 1: shop stock, 2: free fish in the tanks */
		return sprintf( __( 'Shop says %1$d in stock, tanks have %2$d free', 'fishotel-misc-plugin' ), (int) $stock, max( 0, (int) $free ) );
	}

	/**
	 * Where the shop's stock and the tanks disagree — a warning only, the
	 * shop's stock is never written: each stock row (a product counted as a
	 * whole, or one size of it) against the fish alive in its open groups
	 * that no open order holds.
	 *
	 * @param array[] $stock  Rows: product_id, size_slug ('' = the product as a whole), label, stock (null = not tracked).
	 * @param array[] $stays  Groups (id, product_id, size_slug, qty_live, status).
	 * @param array   $counts Reserved: stay id => { qty }.
	 * @return array[] The rows that differ, each with free and text.
	 */
	public static function stock_warnings( array $stock, array $stays, array $counts ) {
		$out = array();
		foreach ( $stock as $row ) {
			if ( ! is_array( $row ) || null === ( $row['stock'] ?? null ) ) {
				continue;
			}
			$free = 0;
			foreach ( $stays as $stay ) {
				if ( ! is_array( $stay ) || 'closed' === (string) ( $stay['status'] ?? '' ) || (int) ( $stay['product_id'] ?? 0 ) !== (int) $row['product_id'] ) {
					continue;
				}
				if ( '' !== (string) ( $row['size_slug'] ?? '' ) && Sizes::slug( (string) ( $stay['size_slug'] ?? '' ) ) !== Sizes::slug( (string) $row['size_slug'] ) ) {
					continue;
				}
				$free += self::free( $stay, $counts );
			}
			$text = self::stock_text( (int) $row['stock'], $free );
			if ( '' !== $text ) {
				$out[] = array_merge(
					$row,
					array(
						'free' => $free,
						'text' => $text,
					)
				);
			}
		}
		return $out;
	}

	/**
	 * The head of the not-found list: "Could not find a tank for 4 fish
	 * that shipped".
	 *
	 * @param int $fish Fish.
	 * @return string
	 */
	public static function lost_text( $fish ) {
		/* translators: %d: fish */
		return sprintf( _n( 'Could not find a tank for %d fish that shipped', 'Could not find a tank for %d fish that shipped', (int) $fish, 'fishotel-misc-plugin' ), (int) $fish );
	}

	/**
	 * The tick in Settings.
	 *
	 * @return array{label: string, text: string}
	 */
	public static function setting_text() {
		return array(
			'label' => __( 'Reserve sold fish in their tank and take them out when the order ships', 'fishotel-misc-plugin' ),
			'text'  => __( 'When a customer\'s order comes in, each fish is reserved in the tank it will come out of, and on its ship day the To do tab lists which tank to pull it from. When the order ships, the fish comes off that tank\'s count by itself — nothing to log by hand.', 'fishotel-misc-plugin' ),
		);
	}
}
