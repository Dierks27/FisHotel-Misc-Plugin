<?php
/**
 * Lost Fish cover — PURE: is a lost fish replaced from the extra, or is
 * somebody now short?
 *
 * The KIND of a tank group is its product when it has one (every group of
 * that product is the same kind, whatever its size) and, without a
 * product, its species and size together.
 *
 *   COVERED   = the fish of that kind still alive across every open group.
 *   COMMITTED = the fish of that kind owed to customers: the lines of paid,
 *               not yet shipped, not closed orders (the holders — Orders
 *               finds them), each for its quantity MINUS what a refund
 *               already took off the line or an answered case released
 *               (whichever is larger — the owner may or may not have
 *               refunded with a quantity).
 *   SHORTAGE  = COMMITTED − COVERED, never below zero.
 *
 * COVERED >= COMMITTED: the customer's fish is replaced from the extra and
 * nobody is written to. Otherwise the shortage, minus the fish that open
 * cases already speak for, is allocated to the holders — the most recently
 * PAID order first (first paid, first served) — one case per order line,
 * for as many fish as that line is short.
 *
 * Everything here works on plain arrays, so the maths is tested without
 * WordPress, WooCommerce or the Clean Room.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cover
 */
class Cover {

	/**
	 * Case statuses that still wait for the customer: the fish is owed and
	 * the case already speaks for it.
	 *
	 * @var string[]
	 */
	const PENDING = array( 'open', 'sent' );

	/**
	 * Case statuses after the customer's answer: the fish is no longer
	 * owed from the tanks (refunded, credited, cancelled — or a request
	 * for a new one).
	 *
	 * @var string[]
	 */
	const RELEASED = array( 'answered', 'done' );

	/**
	 * The close reason that releases a line like an answer does: the owner
	 * settled it with the customer directly.
	 *
	 * @var string
	 */
	const CLOSE_SETTLED = 'settled';

	/**
	 * A name as it is compared: lower case, every run of anything that is
	 * not a letter or a digit as one space.
	 *
	 * @param mixed $text Name.
	 * @return string
	 */
	public static function norm( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * The key of a kind: "p:<product id>", or "s:<species>|<size slug>".
	 *
	 * @param int    $product_id Product id (0 = none).
	 * @param string $species    Species name.
	 * @param string $size_slug  Size slug ('' = none).
	 * @return string '' when there is neither a product nor a species.
	 */
	public static function kind_key( $product_id, $species, $size_slug = '' ) {
		if ( (int) $product_id > 0 ) {
			return 'p:' . (int) $product_id;
		}
		$name = self::norm( $species );
		if ( '' === $name ) {
			return '';
		}
		return substr( 's:' . $name . '|' . strtolower( trim( (string) $size_slug ) ), 0, 191 );
	}

	/**
	 * The kind of a tank group.
	 *
	 * @param array  $stay          A stay (product_id, species, size_slug, size_label).
	 * @param string $product_title The product's title, when it has a product ('' = unknown).
	 * @return array{key: string, product_id: int, species: string, size_slug: string, size_label: string, names: string[]}
	 */
	public static function kind_of_stay( array $stay, $product_title = '' ) {
		$product_id = isset( $stay['product_id'] ) ? (int) $stay['product_id'] : 0;
		$species    = trim( (string) ( $stay['species'] ?? '' ) );
		$size_slug  = trim( (string) ( $stay['size_slug'] ?? '' ) );
		$size_label = trim( (string) ( $stay['size_label'] ?? '' ) );

		return array(
			'key'        => self::kind_key( $product_id, $species, $size_slug ),
			'product_id' => max( 0, $product_id ),
			'species'    => $species,
			'size_slug'  => $size_slug,
			'size_label' => $size_label,
			'names'      => self::names( $species, (string) $product_title, $size_label ),
		);
	}

	/**
	 * The names a product-less order line may carry for a kind: the
	 * species and the product title, each alone and with the size.
	 *
	 * @param string $species       Species.
	 * @param string $product_title Product title.
	 * @param string $size_label    Size label.
	 * @return string[] Normalised, unique, never empty strings.
	 */
	public static function names( $species, $product_title = '', $size_label = '' ) {
		$out = array();
		foreach ( array( $species, $product_title ) as $name ) {
			$base = self::norm( $name );
			if ( '' === $base ) {
				continue;
			}
			$out[] = $base;
			$size  = self::norm( $size_label );
			if ( '' !== $size ) {
				$out[] = $base . ' ' . $size;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a tank group is of a kind.
	 *
	 * @param array $kind kind_of_stay().
	 * @param array $stay A stay.
	 * @return bool
	 */
	public static function stay_matches( array $kind, array $stay ) {
		$product_id = isset( $stay['product_id'] ) ? (int) $stay['product_id'] : 0;

		if ( (int) $kind['product_id'] > 0 ) {
			return $product_id === (int) $kind['product_id'];
		}
		if ( $product_id > 0 ) {
			return false;
		}

		return '' !== self::norm( $kind['species'] )
			&& self::norm( $stay['species'] ?? '' ) === self::norm( $kind['species'] )
			&& strtolower( trim( (string) ( $stay['size_slug'] ?? '' ) ) ) === strtolower( (string) $kind['size_slug'] );
	}

	/**
	 * COVERED: the fish of a kind alive across the open groups. A closed
	 * group and a group whose fish nobody has identified yet count for
	 * nothing.
	 *
	 * @param array   $kind  kind_of_stay().
	 * @param array[] $stays Stays (any kind, any status).
	 * @return int
	 */
	public static function covered( array $kind, array $stays ) {
		$alive = 0;
		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) || 'closed' === (string) ( $stay['status'] ?? '' ) || ! empty( $stay['needs_id'] ) ) {
				continue;
			}
			if ( self::stay_matches( $kind, $stay ) ) {
				$alive += max( 0, (int) ( $stay['qty_live'] ?? 0 ) );
			}
		}
		return $alive;
	}

	/**
	 * The key of one order line across holders and cases.
	 *
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref within the order.
	 * @return string
	 */
	public static function line_key( $order_id, $ref ) {
		return (int) $order_id . '/' . (string) $ref;
	}

	/**
	 * Per order line: the fish answered cases released and the fish open
	 * cases already speak for.
	 *
	 * @param array[] $cases Cases of the kind (status, order_id, line_ref, qty, close_reason).
	 * @return array{released: array<string, int>, pending: array<string, int>}
	 */
	public static function tally( array $cases ) {
		$released = array();
		$pending  = array();
		foreach ( $cases as $case ) {
			if ( ! is_array( $case ) ) {
				continue;
			}
			$key    = self::line_key( $case['order_id'] ?? 0, $case['line_ref'] ?? '' );
			$qty    = max( 0, (int) ( $case['qty'] ?? 0 ) );
			$status = (string) ( $case['status'] ?? '' );

			if ( in_array( $status, self::PENDING, true ) ) {
				$pending[ $key ] = ( $pending[ $key ] ?? 0 ) + $qty;
			} elseif ( in_array( $status, self::RELEASED, true ) || ( 'closed' === $status && self::CLOSE_SETTLED === (string) ( $case['close_reason'] ?? '' ) ) ) {
				$released[ $key ] = ( $released[ $key ] ?? 0 ) + $qty;
			}
		}

		return array(
			'released' => $released,
			'pending'  => $pending,
		);
	}

	/**
	 * The holders, most recently paid first (the same moment: the newer
	 * order, then the later line), each with what it is still owed and
	 * what is free for a new case.
	 *
	 * @param array[] $holders Holder lines (order_id, ref, qty, qty_refunded, paid_at, …).
	 * @param array[] $cases   Cases of the kind.
	 * @return array[] The holders with owed, pending and free added.
	 */
	public static function rank_holders( array $holders, array $cases ) {
		$tally = self::tally( $cases );
		$out   = array();

		foreach ( $holders as $holder ) {
			if ( ! is_array( $holder ) ) {
				continue;
			}
			$key      = self::line_key( $holder['order_id'] ?? 0, $holder['ref'] ?? '' );
			$gone     = max( max( 0, (int) ( $holder['qty_refunded'] ?? 0 ) ), (int) ( $tally['released'][ $key ] ?? 0 ) );
			$owed     = max( 0, (int) ( $holder['qty'] ?? 0 ) - $gone );
			$pending  = min( $owed, (int) ( $tally['pending'][ $key ] ?? 0 ) );
			$out[]    = array_merge(
				$holder,
				array(
					'owed'    => $owed,
					'pending' => $pending,
					'free'    => $owed - $pending,
				)
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				$paid = (int) ( $b['paid_at'] ?? 0 ) <=> (int) ( $a['paid_at'] ?? 0 );
				if ( 0 !== $paid ) {
					return $paid;
				}
				$order = (int) ( $b['order_id'] ?? 0 ) <=> (int) ( $a['order_id'] ?? 0 );
				return 0 !== $order ? $order : strcmp( (string) ( $b['ref'] ?? '' ), (string) ( $a['ref'] ?? '' ) );
			}
		);

		return $out;
	}

	/**
	 * The whole question for one kind.
	 *
	 * @param array   $kind    kind_of_stay().
	 * @param array[] $stays   The stays that may be of this kind.
	 * @param array[] $holders The holder lines of this kind (Orders::holders()).
	 * @param array[] $cases   The cases of this kind that are not closed for good.
	 * @return array{covered: int, committed: int, shortage: int, pending: int, new: int,
	 *               holders: array[], allocations: array<int, array{holder: array, qty: int}>}
	 */
	public static function assess( array $kind, array $stays, array $holders, array $cases ) {
		$ranked    = self::rank_holders( $holders, $cases );
		$covered   = self::covered( $kind, $stays );
		$committed = 0;
		$pending   = 0;
		foreach ( $ranked as $holder ) {
			$committed += $holder['owed'];
			$pending   += $holder['pending'];
		}

		$shortage = max( 0, $committed - $covered );
		$new      = max( 0, $shortage - $pending );

		return array(
			'covered'     => $covered,
			'committed'   => $committed,
			'shortage'    => $shortage,
			'pending'     => $pending,
			'new'         => $new,
			'holders'     => $ranked,
			'allocations' => self::allocate( $new, $ranked ),
		);
	}

	/**
	 * Hand a number of fish out to the ranked holders: each takes what is
	 * free on its line, the most recently paid first.
	 *
	 * @param int     $count  Fish to hand out.
	 * @param array[] $ranked rank_holders().
	 * @return array<int, array{holder: array, qty: int}>
	 */
	public static function allocate( $count, array $ranked ) {
		$left = max( 0, (int) $count );
		$out  = array();
		foreach ( $ranked as $holder ) {
			if ( $left <= 0 ) {
				break;
			}
			$take = min( $left, max( 0, (int) ( $holder['free'] ?? 0 ) ) );
			if ( $take <= 0 ) {
				continue;
			}
			$out[] = array(
				'holder' => $holder,
				'qty'    => $take,
			);
			$left -= $take;
		}
		return $out;
	}

	/**
	 * The other holders an open case can be given to: every ranked holder
	 * with enough free fish, except the case's own line.
	 *
	 * @param array   $case   The case (order_id, line_ref, qty).
	 * @param array[] $ranked rank_holders() over the cases of the kind.
	 * @return array[]
	 */
	public static function alternatives( array $case, array $ranked ) {
		$own = self::line_key( $case['order_id'] ?? 0, $case['line_ref'] ?? '' );
		$qty = max( 1, (int) ( $case['qty'] ?? 1 ) );
		$out = array();
		foreach ( $ranked as $holder ) {
			if ( self::line_key( $holder['order_id'] ?? 0, $holder['ref'] ?? '' ) === $own ) {
				continue;
			}
			if ( (int) ( $holder['free'] ?? 0 ) >= $qty ) {
				$out[] = $holder;
			}
		}
		return $out;
	}
}
