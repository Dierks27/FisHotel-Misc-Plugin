<?php
/**
 * Clean Room reserved fish, BY HAND — PURE: "I should be able to mark it
 * reserved. In a perfect world I could note who reserved it…"
 *
 *   a hold     some fish of one tank group set aside for somebody: a
 *              customer of the shop (their account: user id and e-mail)
 *              or any name typed (a forum member, a phone call), with a
 *              short note. Never more than the group has READY (alive,
 *              for sale, nothing else holding them). It is not an event:
 *              the fish alive are unchanged. It never runs out by itself
 *              — its age is shown, an old one stands out — and "Release"
 *              ends it.
 *   counted    with the orders' reservations: held fish are not ready —
 *              no order, invoice, request or move takes them. Orders come
 *              first: when fish die, a hold shrinks (never the orders),
 *              so orders and holds together never exceed the fish alive.
 *   taken      a hold tied to an ACCOUNT is taken by that customer's own
 *              paid order for the same fish in the same size (Reserve's
 *              top rung): it becomes the order's reservation and the hold
 *              closes ("taken by order #1240"). Never by somebody else's
 *              order. A hold with a typed name is never matched by itself:
 *              an order of that fish and size that came in after it is
 *              SUGGESTED ("Is #1240 the order for the fish held for Mike
 *              S.?") and one tap gives the held fish to it.
 *
 * Kept in one option (Holds::OPTION, not autoloaded): { next, holds: { id
 * => hold } }. A hold names its group by stay id, so it follows the group
 * when it moves (a whole group keeps its id; a part moved off leaves the
 * hold in the group, as an order's reservation does). At most MAX_OPEN
 * open holds — a new one beyond is refused, never one dropped; only
 * closed holds are trimmed (the newest KEEP_CLOSED stay as history).
 *
 * No WordPress beyond the translation helpers.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Holds
 */
class Holds {

	/**
	 * The option holding every hold by hand (not autoloaded).
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_cleanroom_holds';

	/**
	 * How many holds may be open at once: a new one beyond is refused
	 * (none is ever dropped to make room).
	 *
	 * @var int
	 */
	const MAX_OPEN = 300;

	/**
	 * How many closed holds are kept as history (the oldest go first).
	 *
	 * @var int
	 */
	const KEEP_CLOSED = 100;

	/**
	 * A hold this many days old stands out.
	 *
	 * @var int
	 */
	const OLD_DAYS = 14;

	/**
	 * The longest name and note kept.
	 */
	const WHO_MAX  = 60;
	const NOTE_MAX = 120;

	/* ============================================================== */
	/*  The book                                                       */
	/* ============================================================== */

	/**
	 * The stored book, made safe: { next: int, holds: { id => hold } }.
	 *
	 * @param mixed $raw The option's value.
	 * @return array{next: int, holds: array<int, array>}
	 */
	public static function book( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$holds = array();
		foreach ( (array) ( $raw['holds'] ?? array() ) as $id => $hold ) {
			if ( ! is_array( $hold ) || (int) $id <= 0 || (int) ( $hold['stay_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$holds[ (int) $id ] = self::hold( array_merge( $hold, array( 'id' => (int) $id ) ) );
		}
		ksort( $holds );
		$next = max( (int) ( $raw['next'] ?? 1 ), $holds ? max( array_keys( $holds ) ) + 1 : 1 );
		return array(
			'next'  => $next,
			'holds' => $holds,
		);
	}

	/**
	 * One hold, every field there.
	 *
	 * @param array $raw The stored hold.
	 * @return array
	 */
	private static function hold( array $raw ) {
		return array(
			'id'      => (int) ( $raw['id'] ?? 0 ),
			'stay_id' => (int) ( $raw['stay_id'] ?? 0 ),
			'qty'     => max( 0, (int) ( $raw['qty'] ?? 0 ) ),
			'who'     => self::clip( $raw['who'] ?? '', self::WHO_MAX ),
			'user_id' => max( 0, (int) ( $raw['user_id'] ?? 0 ) ),
			'email'   => strtolower( self::clip( $raw['email'] ?? '', 120 ) ),
			'note'    => self::clip( $raw['note'] ?? '', self::NOTE_MAX ),
			'at'      => max( 0, (int) ( $raw['at'] ?? 0 ) ),
			'by'      => max( 0, (int) ( $raw['by'] ?? 0 ) ),
			'closed'  => max( 0, (int) ( $raw['closed'] ?? 0 ) ),
			'why'     => self::clip( $raw['why'] ?? '', 120 ),
			'taken'   => array_values( array_filter( (array) ( $raw['taken'] ?? array() ), 'is_array' ) ),
			'not'     => array_values( array_filter( array_map( 'strval', (array) ( $raw['not'] ?? array() ) ), 'strlen' ) ),
		);
	}

	/**
	 * A text cut to a length, one line.
	 *
	 * @param mixed $text Text.
	 * @param int   $max  Length.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = is_scalar( $text ) ? trim( preg_replace( '/\s+/u', ' ', (string) $text ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, (int) $max, 'UTF-8' ) : substr( $text, 0, (int) $max );
	}

	/**
	 * The open holds, oldest first.
	 *
	 * @param array $book book().
	 * @return array[]
	 */
	public static function open( array $book ) {
		$out = array();
		foreach ( (array) ( $book['holds'] ?? array() ) as $hold ) {
			if ( empty( $hold['closed'] ) && (int) $hold['qty'] > 0 ) {
				$out[] = $hold;
			}
		}
		return $out;
	}

	/**
	 * Whether a hold names a shop customer's account (it may be taken by
	 * their own order) — not a name typed.
	 *
	 * @param array $hold Hold.
	 * @return bool
	 */
	public static function is_account( array $hold ) {
		return (int) ( $hold['user_id'] ?? 0 ) > 0 || '' !== (string) ( $hold['email'] ?? '' );
	}

	/**
	 * Whether an order is that customer's: the same account, or the same
	 * e-mail (a guest checkout by the same person). A hold with a typed
	 * name answers for nobody.
	 *
	 * @param array $hold     Hold.
	 * @param array $customer user_id, email.
	 * @return bool
	 */
	public static function owned_by( array $hold, array $customer ) {
		$user  = (int) ( $customer['user_id'] ?? 0 );
		$email = strtolower( trim( (string) ( $customer['email'] ?? '' ) ) );
		if ( (int) ( $hold['user_id'] ?? 0 ) > 0 && $user > 0 && (int) $hold['user_id'] === $user ) {
			return true;
		}
		return '' !== (string) ( $hold['email'] ?? '' ) && '' !== $email && (string) $hold['email'] === $email;
	}

	/**
	 * "Mike S." from a customer's first and last name (else what is shown
	 * for them).
	 *
	 * @param string $first   First name.
	 * @param string $last    Last name.
	 * @param string $display Display name.
	 * @return string
	 */
	public static function short_name( $first, $last, $display = '' ) {
		$first = trim( (string) $first );
		$last  = trim( (string) $last );
		if ( '' !== $first ) {
			$initial = '' !== $last ? ( function_exists( 'mb_substr' ) ? mb_substr( $last, 0, 1, 'UTF-8' ) : substr( $last, 0, 1 ) ) : '';
			return self::clip( $first . ( '' !== $initial ? ' ' . strtoupper( $initial ) . '.' : '' ), self::WHO_MAX );
		}
		return self::clip( '' !== trim( (string) $display ) ? $display : $last, self::WHO_MAX );
	}

	/* ============================================================== */
	/*  Making, ending, taking                                         */
	/* ============================================================== */

	/**
	 * Why a new hold is refused ('' = it may be made).
	 *
	 * @param array|null $stay  The group (status, sale_ready, qty_live, reserved: everything held now, tank_number).
	 * @param int        $qty   How many.
	 * @param string     $who   Who for.
	 * @param array      $book  book().
	 * @return string
	 */
	public static function refuse( $stay, $qty, $who, array $book ) {
		if ( ! is_array( $stay ) || 'closed' === (string) ( $stay['status'] ?? '' ) || (int) ( $stay['qty_live'] ?? 0 ) <= 0 ) {
			return __( 'That tank group has no fish alive.', 'fishotel-misc-plugin' );
		}
		if ( '' === trim( (string) $who ) ) {
			return __( 'Who are they for? Pick a customer or type a name.', 'fishotel-misc-plugin' );
		}
		$ready = Reserve::ready( $stay );
		if ( empty( $stay['sale_ready'] ) ) {
			return __( 'These fish are not for sale yet — nothing is ready to reserve.', 'fishotel-misc-plugin' );
		}
		if ( (int) $qty < 1 || (int) $qty > $ready ) {
			/* translators: %d: fish ready */
			return $ready > 0 ? sprintf( _n( 'Only %d is ready to reserve in this group.', 'Only %d are ready to reserve in this group.', $ready, 'fishotel-misc-plugin' ), $ready ) : __( 'None of these is ready: every fish is reserved already.', 'fishotel-misc-plugin' );
		}
		if ( count( self::open( $book ) ) >= self::MAX_OPEN ) {
			/* translators: %d: open holds */
			return sprintf( __( 'There are already %d holds by hand — release the ones no longer wanted first.', 'fishotel-misc-plugin' ), self::MAX_OPEN );
		}
		return '';
	}

	/**
	 * A new hold in the book.
	 *
	 * @param array $book   book().
	 * @param array $fields stay_id, qty, who, user_id, email, note, by.
	 * @param int   $now    UNIX time.
	 * @return array{book: array, id: int}
	 */
	public static function add( array $book, array $fields, $now ) {
		$book = self::book( $book );
		$id   = (int) $book['next'];
		$book['holds'][ $id ] = self::hold( array_merge( $fields, array( 'id' => $id, 'at' => (int) $now, 'closed' => 0, 'why' => '', 'taken' => array(), 'not' => array() ) ) );
		$book['next']         = $id + 1;
		return array(
			'book' => $book,
			'id'   => $id,
		);
	}

	/**
	 * A hold ended by hand ("Release").
	 *
	 * @param array $book book().
	 * @param int   $id   Hold id.
	 * @param int   $now  UNIX time.
	 * @return array book() — unchanged when there is no such open hold.
	 */
	public static function release( array $book, $id, $now ) {
		$book = self::book( $book );
		if ( isset( $book['holds'][ (int) $id ] ) && empty( $book['holds'][ (int) $id ]['closed'] ) ) {
			$book['holds'][ (int) $id ]['closed'] = (int) $now;
			$book['holds'][ (int) $id ]['why']    = 'released';
		}
		return self::trim_closed( $book );
	}

	/**
	 * Some of a hold's fish given to an order: the hold keeps the rest,
	 * and closes when none are left ("taken by order #1240").
	 *
	 * @param array  $book   book().
	 * @param int    $id     Hold id.
	 * @param int    $n      Fish given.
	 * @param string $number The order's number.
	 * @param int    $now    UNIX time.
	 * @return array book().
	 */
	public static function take( array $book, $id, $n, $number, $now ) {
		$book = self::book( $book );
		$id   = (int) $id;
		if ( ! isset( $book['holds'][ $id ] ) || ! empty( $book['holds'][ $id ]['closed'] ) || (int) $n <= 0 ) {
			return $book;
		}
		$n                              = min( (int) $n, (int) $book['holds'][ $id ]['qty'] );
		$book['holds'][ $id ]['qty']   -= $n;
		$book['holds'][ $id ]['taken'][] = array(
			'order' => (string) $number,
			'qty'   => $n,
			'at'    => (int) $now,
		);
		if ( (int) $book['holds'][ $id ]['qty'] <= 0 ) {
			$book['holds'][ $id ]['closed'] = (int) $now;
			/* translators: %s: order number */
			$book['holds'][ $id ]['why'] = sprintf( __( 'taken by order #%s', 'fishotel-misc-plugin' ), (string) $number );
		}
		return self::trim_closed( $book );
	}

	/**
	 * Some of a hold's fish shipped BY HAND (the phone's "Shipped" with
	 * "these were theirs" ticked): the hold goes down by them, and closes
	 * when none are left ("shipped by hand").
	 *
	 * @param array $book book().
	 * @param int   $id   Hold id.
	 * @param int   $n    Fish shipped.
	 * @param int   $now  UNIX time.
	 * @return array book().
	 */
	public static function ship( array $book, $id, $n, $now ) {
		$book = self::book( $book );
		$id   = (int) $id;
		if ( ! isset( $book['holds'][ $id ] ) || ! empty( $book['holds'][ $id ]['closed'] ) || (int) $n <= 0 ) {
			return $book;
		}
		$n                               = min( (int) $n, (int) $book['holds'][ $id ]['qty'] );
		$book['holds'][ $id ]['qty']    -= $n;
		$book['holds'][ $id ]['taken'][] = array(
			'order' => '',
			'qty'   => $n,
			'at'    => (int) $now,
		);
		if ( (int) $book['holds'][ $id ]['qty'] <= 0 ) {
			$book['holds'][ $id ]['closed'] = (int) $now;
			$book['holds'][ $id ]['why']    = __( 'shipped by hand', 'fishotel-misc-plugin' );
		}
		return self::trim_closed( $book );
	}

	/**
	 * The holds that closed lately (taken by an order, given, released,
	 * shipped by hand) — the newest first, so a hold never just vanishes
	 * from the list: "Mike S. · taken by order #1240".
	 *
	 * @param array $book book().
	 * @param int   $now  UNIX time.
	 * @param int   $days How far back.
	 * @return array[]
	 */
	public static function closed_lately( array $book, $now, $days = 7 ) {
		$out = array();
		foreach ( (array) ( $book['holds'] ?? array() ) as $hold ) {
			if ( ! empty( $hold['closed'] ) && (int) $hold['closed'] >= (int) $now - (int) $days * DAY_IN_SECONDS ) {
				$out[] = $hold;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( (int) $b['closed'] <=> (int) $a['closed'] ) ?: ( (int) $b['id'] <=> (int) $a['id'] );
			}
		);
		return $out;
	}

	/**
	 * Why a closed hold ended, in words: "taken by order #1240",
	 * "released", "shipped by hand".
	 *
	 * @param array $hold A closed hold.
	 * @return string
	 */
	public static function why_text( array $hold ) {
		$why = trim( (string) ( $hold['why'] ?? '' ) );
		if ( 'released' === $why ) {
			return __( 'released', 'fishotel-misc-plugin' );
		}
		return '' !== $why ? $why : __( 'ended', 'fishotel-misc-plugin' );
	}

	/**
	 * Why "Give them the held fish" may not give a hold to an order line
	 * ('' = it may): only a hold of the line's OWN fish in its size
	 * (Sold_Line::exact() against the hold's group) — never a goby line
	 * given a tang — and a hold tied to a customer's ACCOUNT only to that
	 * customer's own order (Holds::owned_by()).
	 *
	 * @param array      $hold     The hold (user_id, email, who).
	 * @param array      $line     The order line (a fish line).
	 * @param array|null $stay     The hold's group (hydrated; null = gone).
	 * @param array      $customer The order's user_id, email.
	 * @return string
	 */
	public static function give_refuse( array $hold, array $line, $stay, array $customer ) {
		if ( ! is_array( $stay ) ) {
			return __( 'That hold\'s tank group is gone — nothing is given.', 'fishotel-misc-plugin' );
		}
		if ( ! Sold_Line::exact( array_merge( $line, array( 'held' => array(), 'link' => array() ) ), $stay ) ) {
			return __( 'That order line is not the held fish in the held size — nothing is given.', 'fishotel-misc-plugin' );
		}
		if ( self::is_account( $hold ) && ! self::owned_by( $hold, $customer ) ) {
			/* translators: %s: who the fish are held for */
			return sprintf( __( 'Those fish are held for %s\'s account — only their own order takes them.', 'fishotel-misc-plugin' ), (string) $hold['who'] );
		}
		return '';
	}

	/**
	 * Why a move of PART of a group is refused ('' = it may move): it would
	 * take fish held by hand along — the hold stays with the group, so it
	 * would quietly shrink and its fish be ready for anybody in the other
	 * tank. At most the fish nothing holds may move off; the whole group
	 * (its hold goes with it) always may.
	 *
	 * @param int     $alive    Fish alive in the group.
	 * @param int     $reserved Fish reserved in it (orders and by hand).
	 * @param array[] $holds    Its holds by hand (who, qty).
	 * @param int     $qty      Fish to move (0 or all = the whole group).
	 * @return string
	 */
	public static function move_refuse( $alive, $reserved, array $holds, $qty ) {
		$alive = (int) $alive;
		$qty   = (int) $qty;
		$hand  = 0;
		$who   = array();
		foreach ( $holds as $hold ) {
			if ( is_array( $hold ) && (int) ( $hold['qty'] ?? 0 ) > 0 ) {
				$hand += (int) $hold['qty'];
				$who[] = trim( (string) ( $hold['who'] ?? '' ) );
			}
		}
		if ( $hand <= 0 || $qty <= 0 || $qty >= $alive ) {
			return '';
		}
		$free = max( 0, $alive - max( 0, (int) $reserved ) );
		if ( $qty <= $free ) {
			return '';
		}
		$who = Feeding::sentence_list( array_values( array_unique( array_filter( $who, 'strlen' ) ) ) );
		if ( $free > 0 ) {
			/* translators: 1: fish held by hand, 2: who for, 3: fish that may move */
			return sprintf( _n( '%1$d of these is held by hand for %2$s: move at most %3$d, move the whole group (the hold goes with it), or release the hold first.', '%1$d of these are held by hand for %2$s: move at most %3$d, move the whole group (the hold goes with it), or release the hold first.', $hand, 'fishotel-misc-plugin' ), $hand, $who, $free );
		}
		/* translators: 1: fish held by hand, 2: who for */
		return sprintf( _n( '%1$d of these is held by hand for %2$s and none is free to move: move the whole group (the hold goes with it), or release the hold first.', '%1$d of these are held by hand for %2$s and none is free to move: move the whole group (the hold goes with it), or release the hold first.', $hand, 'fishotel-misc-plugin' ), $hand, $who );
	}

	/**
	 * What a hold still holds when fish died: "only 1 still in the tank for
	 * it" / "none of its fish is left in the tank — release it".
	 *
	 * @param int $qty What it holds now.
	 * @return string
	 */
	public static function short_text( $qty ) {
		if ( (int) $qty <= 0 ) {
			return __( 'none of its fish is left in the tank — release it', 'fishotel-misc-plugin' );
		}
		/* translators: %d: fish */
		return sprintf( __( 'only %d still in the tank for it', 'fishotel-misc-plugin' ), (int) $qty );
	}

	/**
	 * A sentence ended once: "…for Maria T." never "…for Maria T..".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function sentence( $text ) {
		$text = rtrim( (string) $text );
		return '' === $text || '.' === substr( $text, -1 ) ? $text : $text . '.';
	}

	/**
	 * "Not this order": a suggestion that is not asked again.
	 *
	 * @param array  $book book().
	 * @param int    $id   Hold id.
	 * @param string $key  "order id|ref".
	 * @return array book().
	 */
	public static function dismiss( array $book, $id, $key ) {
		$book = self::book( $book );
		if ( isset( $book['holds'][ (int) $id ] ) && ! in_array( (string) $key, $book['holds'][ (int) $id ]['not'], true ) ) {
			$book['holds'][ (int) $id ]['not'][] = (string) $key;
			$book['holds'][ (int) $id ]['not']   = array_slice( $book['holds'][ (int) $id ]['not'], -50 );
		}
		return $book;
	}

	/**
	 * Only the newest KEEP_CLOSED closed holds are kept — an open hold is
	 * never dropped.
	 *
	 * @param array $book book().
	 * @return array
	 */
	public static function trim_closed( array $book ) {
		$closed = array();
		foreach ( $book['holds'] as $id => $hold ) {
			if ( ! empty( $hold['closed'] ) ) {
				$closed[ $id ] = (int) $hold['closed'];
			}
		}
		if ( count( $closed ) > self::KEEP_CLOSED ) {
			arsort( $closed );
			foreach ( array_slice( array_keys( $closed ), self::KEEP_CLOSED ) as $id ) {
				unset( $book['holds'][ $id ] );
			}
		}
		return $book;
	}

	/* ============================================================== */
	/*  Counted with the orders                                        */
	/* ============================================================== */

	/**
	 * What each open hold holds now: its fish, but never more than its
	 * group has alive once the orders' reservations are counted — the
	 * orders first (a paid order is never short because of a hold), then
	 * the older hold before the newer.
	 *
	 * @param array[] $holds  open().
	 * @param array   $alive  Stay id => fish alive (a group not listed has none: closed).
	 * @param array   $counts What the orders hold: stay id => { qty }.
	 * @return array<int, array> Hold id => the hold with qty = what it holds now and asked = what was set aside.
	 */
	public static function effective( array $holds, array $alive, array $counts ) {
		$room = array();
		$out  = array();
		usort(
			$holds,
			static function ( $a, $b ) {
				return ( (int) $a['at'] <=> (int) $b['at'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);
		foreach ( $holds as $hold ) {
			$stay = (int) $hold['stay_id'];
			if ( ! isset( $room[ $stay ] ) ) {
				$room[ $stay ] = max( 0, (int) ( $alive[ $stay ] ?? 0 ) - (int) ( $counts[ $stay ]['qty'] ?? 0 ) );
			}
			$n               = min( (int) $hold['qty'], $room[ $stay ] );
			$room[ $stay ]  -= $n;
			$out[ (int) $hold['id'] ] = array_merge( $hold, array( 'asked' => (int) $hold['qty'], 'qty' => $n ) );
		}
		return $out;
	}

	/**
	 * The counts with the holds added: stay id => { qty (orders and holds),
	 * orders, hand (fish held by hand), holds: [ { id, who, qty, at, note,
	 * account } ] }.
	 *
	 * @param array $counts    Counts (stay id => { qty, orders, … }).
	 * @param array $effective effective().
	 * @return array
	 */
	public static function add_to( array $counts, array $effective ) {
		foreach ( $counts as $stay => $count ) {
			$counts[ $stay ]['hand']  = (int) ( $count['hand'] ?? 0 );
			$counts[ $stay ]['holds'] = (array) ( $count['holds'] ?? array() );
		}
		foreach ( $effective as $hold ) {
			if ( (int) $hold['qty'] <= 0 ) {
				continue;
			}
			$stay = (int) $hold['stay_id'];
			if ( ! isset( $counts[ $stay ] ) ) {
				$counts[ $stay ] = array(
					'qty'    => 0,
					'orders' => array(),
					'hand'   => 0,
					'holds'  => array(),
				);
			}
			$counts[ $stay ]['qty']    += (int) $hold['qty'];
			$counts[ $stay ]['hand']   += (int) $hold['qty'];
			$counts[ $stay ]['holds'][] = self::shown( $hold );
		}
		return $counts;
	}

	/**
	 * What a screen shows of a hold.
	 *
	 * @param array $hold An effective() hold.
	 * @return array{id: int, who: string, qty: int, at: int, note: string, account: bool}
	 */
	public static function shown( array $hold ) {
		return array(
			'id'      => (int) $hold['id'],
			'who'     => (string) $hold['who'],
			'qty'     => (int) $hold['qty'],
			'at'      => (int) $hold['at'],
			'note'    => (string) ( $hold['note'] ?? '' ),
			'account' => self::is_account( $hold ),
		);
	}

	/**
	 * Some fish of a hold given to an order inside the counts: still held
	 * (the count of the group is the same), now for the order.
	 *
	 * @param array  $counts Counts with holds (add_to()).
	 * @param int    $stay   Stay id.
	 * @param int    $id     Hold id.
	 * @param int    $n      Fish.
	 * @param string $number The order's number.
	 * @return array
	 */
	public static function move_in_counts( array $counts, $stay, $id, $n, $number ) {
		if ( ! isset( $counts[ (int) $stay ] ) ) {
			return $counts;
		}
		$count          = $counts[ (int) $stay ];
		$count['hand']  = max( 0, (int) ( $count['hand'] ?? 0 ) - (int) $n );
		$holds          = array();
		foreach ( (array) ( $count['holds'] ?? array() ) as $hold ) {
			if ( (int) $hold['id'] === (int) $id ) {
				$hold['qty'] -= (int) $n;
			}
			if ( (int) $hold['qty'] > 0 ) {
				$holds[] = $hold;
			}
		}
		$count['holds'] = $holds;
		if ( '' !== (string) $number && ! in_array( (string) $number, (array) $count['orders'], true ) ) {
			$count['orders'][] = (string) $number;
		}
		$counts[ (int) $stay ] = $count;
		return $counts;
	}

	/**
	 * The account holds a line of an order may take (Reserve's top rung):
	 * the same customer's, of that fish in that size (Sold_Line::exact()),
	 * holding fish now — oldest first.
	 *
	 * @param array $line      A fish line.
	 * @param array $customer  user_id, email.
	 * @param array $effective effective() (qty = what each holds now).
	 * @param array $stays     Stay id => hydrated stay (open ones).
	 * @return array[] The holds.
	 */
	public static function for_line( array $line, array $customer, array $effective, array $stays ) {
		$out = array();
		$ask = array_merge( $line, array( 'held' => array(), 'link' => array() ) );
		foreach ( $effective as $hold ) {
			$stay = $stays[ (int) $hold['stay_id'] ] ?? null;
			if ( (int) $hold['qty'] > 0 && self::is_account( $hold ) && self::owned_by( $hold, $customer ) && is_array( $stay ) && Sold_Line::exact( $ask, $stay ) ) {
				$out[] = $hold;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  A typed name: suggested, never matched by itself               */
	/* ============================================================== */

	/**
	 * The orders that may be the one a hold with a typed name was made
	 * for: a line of an open order, not gone, of that fish in that size,
	 * from an order that came in after the hold was made — unless "Not
	 * this order" was said.
	 *
	 * @param array[] $orders    Open orders: id, number, created (UNIX time), lines (ref, item_id, qty, … — gone ones not passed).
	 * @param array   $effective effective().
	 * @param array   $stays     Stay id => hydrated stay.
	 * @return array[] hold_id, order_id, number, ref, qty (fish it would get), who, name.
	 */
	public static function suggestions( array $orders, array $effective, array $stays ) {
		$out = array();
		foreach ( $effective as $hold ) {
			$stay = $stays[ (int) $hold['stay_id'] ] ?? null;
			if ( self::is_account( $hold ) || (int) $hold['qty'] <= 0 || ! is_array( $stay ) ) {
				continue;
			}
			foreach ( $orders as $order ) {
				if ( (int) ( $order['created'] ?? 0 ) < (int) $hold['at'] ) {
					continue;
				}
				foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
					$key = (int) $order['id'] . '|' . (string) ( $line['ref'] ?? '' );
					if ( (int) ( $line['qty'] ?? 0 ) <= 0 || null === Reserve::key( Reserve::META, (string) ( $line['ref'] ?? '' ) ) || in_array( $key, $hold['not'], true ) ) {
						continue;
					}
					if ( ! Sold_Line::exact( array_merge( $line, array( 'held' => array(), 'link' => array() ) ), $stay ) ) {
						continue;
					}
					$out[] = array(
						'hold_id'  => (int) $hold['id'],
						'order_id' => (int) $order['id'],
						'number'   => (string) ( $order['number'] ?? '' ),
						'ref'      => (string) $line['ref'],
						'qty'      => min( (int) $hold['qty'], (int) $line['qty'] ),
						'who'      => (string) $hold['who'],
						'name'     => (string) ( $line['name'] ?? '' ),
						'tank'     => (int) ( $stay['tank_number'] ?? 0 ),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * "Is #1240 the order for the fish held for Mike S.?"
	 *
	 * @param array $suggestion suggestions() row.
	 * @return string
	 */
	public static function question( array $suggestion ) {
		/* translators: 1: order number, 2: who the fish are held for */
		return sprintf( __( 'Is #%1$s the order for the fish held for %2$s?', 'fishotel-misc-plugin' ), (string) $suggestion['number'], (string) $suggestion['who'] );
	}

	/**
	 * Whether a hold has been held long enough to stand out.
	 *
	 * @param array $hold at.
	 * @param int   $now  UNIX time.
	 * @return bool
	 */
	public static function is_old( array $hold, $now ) {
		return (int) $now - (int) ( $hold['at'] ?? 0 ) >= self::OLD_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * A reservation with some fish of a hold's group put first: the line
	 * keeps the rest of what it held (cut to its fish), the hold's group
	 * gets n more.
	 *
	 * @param array<int, int> $held The line's reservation.
	 * @param int             $qty  The line's fish.
	 * @param int             $stay The hold's group.
	 * @param int             $n    Fish given.
	 * @return array<int, int>
	 */
	public static function give_to_line( array $held, $qty, $stay, $n ) {
		$n    = max( 0, min( (int) $n, (int) $qty ) );
		$rest = Reserve::trim( $held, (int) $qty - $n );
		return Reserve::merge( $rest, array( (int) $stay => $n ) );
	}
}
