<?php
/**
 * Sticker Giveaway piles — pure functions, no WordPress calls.
 *
 * A design has two piles. The SHOP pile is the product's (or the
 * variation's) own WooCommerce stock: the stickers set aside for selling
 * on the site. The GIVEAWAY pile is a count this section keeps: the
 * stickers that go into fish boxes. A free sticker or an add-on counts
 * the giveaway pile down and never touches the shop pile.
 *
 * Stickers go missing, so a pile is never trusted to its last sticker:
 *
 *   B        = the stickers put into the pile since it was last counted
 *              (the counted number plus what was added since)
 *   reserve  = B × the "go missing" percent, rounded up
 *   P        = the paper count (what the page says is in the pile)
 *   really there = P − reserve, never below 0
 *
 * The design is offered while P is above the reserve.
 *
 * A "pile" handed to these methods is
 *   [ 'paper' => int, 'basis' => int, 'alerted' => bool ]
 * and a ledger row (one per order line, see ledger()) is
 *   [ 'state' => 'taken'|'back', 'qty' => int, 'back' => int ]
 * or null when the order line has never moved a sticker.
 *
 * WHY pure: these are the numbers the owner reorders by and the rule that
 * decides whether a customer is offered a design. They run headless
 * under tests/piles-test.php; Store only writes down what they say.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Piles
 */
class Piles {

	/**
	 * The "go missing" percent: default and ceiling.
	 *
	 * @var int
	 */
	const LOSS_DEFAULT = 10;
	const LOSS_MAX     = 50;

	/**
	 * How far back "how many went" looks, in days.
	 *
	 * @var int
	 */
	const PACE_DAYS = 28;

	/**
	 * What a design reads in the stock table.
	 *
	 * @var string
	 */
	const STATUS_OK      = 'ok';
	const STATUS_REORDER = 'reorder';
	const STATUS_OUT     = 'out';

	/**
	 * Ledger states and events.
	 *
	 * @var string
	 */
	const TAKEN  = 'taken';
	const BACK   = 'back';
	const TAKE   = 'take';
	const RETURN = 'return';
	const REFUND = 'refund';

	/**
	 * The stock id WooCommerce sums a giveaway line under in the cart.
	 * WooCommerce adds up the quantities of every cart line by "whose
	 * stock is this" before it checks a line against its stock; a giveaway
	 * line answers 0 — no product — so it is never added to the shop pile's
	 * sum (see cart_quantities()).
	 *
	 * @var int
	 */
	const STOCK_BUCKET = 0;

	/* ============================================================== */
	/*  The reserve                                                    */
	/* ============================================================== */

	/**
	 * The "go missing" percent from the settings: a whole number, 0–50.
	 *
	 * @param array $settings Settings array.
	 * @return int
	 */
	public static function loss_percent( array $settings ) {
		$raw = $settings['loss_percent'] ?? self::LOSS_DEFAULT;

		if ( ! is_numeric( $raw ) ) {
			return self::LOSS_DEFAULT;
		}

		return (int) min( self::LOSS_MAX, max( 0, (int) round( (float) $raw ) ) );
	}

	/**
	 * How many stickers of a pile are held back as "probably gone".
	 *
	 * Rounded up: 10% of 15 is 2, not 1.5. Worked out in whole numbers so
	 * 10% of 30 is 3 and never 4 by float noise.
	 *
	 * @param int $basis   B — put into the pile since the last count.
	 * @param int $percent The "go missing" percent.
	 * @return int
	 */
	public static function reserve( $basis, $percent ) {
		$basis   = max( 0, (int) $basis );
		$percent = min( self::LOSS_MAX, max( 0, (int) $percent ) );

		return intdiv( $basis * $percent + 99, 100 );
	}

	/**
	 * "About N really there": the paper count less the reserve, never
	 * below 0. Also how many stickers of the design can still be handed
	 * out.
	 *
	 * @param int $paper   P — the paper count.
	 * @param int $basis   B.
	 * @param int $percent The "go missing" percent.
	 * @return int
	 */
	public static function really_there( $paper, $basis, $percent ) {
		return max( 0, (int) $paper - self::reserve( $basis, $percent ) );
	}

	/**
	 * Whether the pile still offers its design: P above the reserve.
	 *
	 * @param int $paper   P.
	 * @param int $basis   B.
	 * @param int $percent The "go missing" percent.
	 * @return bool
	 */
	public static function offered( $paper, $basis, $percent ) {
		return self::really_there( $paper, $basis, $percent ) > 0;
	}

	/* ============================================================== */
	/*  Restock, recount, orders                                       */
	/* ============================================================== */

	/**
	 * A pile as clean numbers (a design nobody has counted yet is an empty
	 * pile).
	 *
	 * @param mixed $pile A pile, a database row, or nothing.
	 * @return array{paper: int, basis: int, alerted: bool}
	 */
	public static function pile( $pile ) {
		$pile = is_array( $pile ) ? $pile : array();

		return array(
			'paper'   => (int) ( $pile['paper'] ?? 0 ),
			'basis'   => max( 0, (int) ( $pile['basis'] ?? 0 ) ),
			'alerted' => ! empty( $pile['alerted'] ),
		);
	}

	/**
	 * "Add stickers": N more went into the pile. The paper count and B
	 * both grow by N.
	 *
	 * @param mixed $pile The pile before.
	 * @param int   $n    How many were added (below 1 changes nothing).
	 * @return array{paper: int, basis: int, alerted: bool}
	 */
	public static function after_add( $pile, $n ) {
		$pile = self::pile( $pile );
		$n    = max( 0, (int) $n );

		$pile['paper'] += $n;
		$pile['basis'] += $n;

		return $pile;
	}

	/**
	 * "Recount": the pile really holds N. The paper count becomes N and so
	 * does B — the loss so far is in the count, so the reserve starts over
	 * from what was counted.
	 *
	 * @param mixed $pile The pile before.
	 * @param int   $n    What was counted (never below 0).
	 * @return array{paper: int, basis: int, alerted: bool}
	 */
	public static function after_recount( $pile, $n ) {
		$pile = self::pile( $pile );
		$n    = max( 0, (int) $n );

		$pile['paper'] = $n;
		$pile['basis'] = $n;

		return $pile;
	}

	/**
	 * An order took stickers (a negative delta) or gave them back (a
	 * positive one): only the paper count moves. B stays — nothing was put
	 * into the pile from outside.
	 *
	 * @param mixed $pile  The pile before.
	 * @param int   $delta The change to the paper count.
	 * @return array{paper: int, basis: int, alerted: bool}
	 */
	public static function after_move( $pile, $delta ) {
		$pile           = self::pile( $pile );
		$pile['paper'] += (int) $delta;

		return $pile;
	}

	/* ============================================================== */
	/*  The ledger: once per order line                                */
	/* ============================================================== */

	/**
	 * What an event does to an order line's ledger row, and by how much
	 * the paper count moves. One row per order line is what makes "the
	 * pile goes down once and comes back once" true however often
	 * WooCommerce fires its hooks:
	 *
	 *   take    no row, or a row that was given back → taken; −qty
	 *           already taken                        → nothing
	 *   return  taken → back; + what the line still holds (qty less what a
	 *           refund already returned)
	 *           anything else                        → nothing
	 *   refund  taken → the refunded quantity comes back, at most what the
	 *           line still holds
	 *           anything else                        → nothing
	 *
	 * @param array|null $row   The row before (null = none).
	 * @param string     $event TAKE, RETURN or REFUND.
	 * @param int        $qty   The line quantity (take) or the refunded
	 *                          quantity (refund); unused for return.
	 * @return array{0: array|null, 1: int} The row after and the change to
	 *                                      the paper count (0 = nothing
	 *                                      happened; the row is unchanged).
	 */
	public static function ledger( $row, $event, $qty = 0 ) {
		$row = self::ledger_row( $row );
		$qty = max( 0, (int) $qty );

		if ( self::TAKE === $event ) {
			if ( $qty < 1 || ( $row && self::TAKEN === $row['state'] ) ) {
				return array( $row, 0 );
			}

			return array(
				array(
					'state' => self::TAKEN,
					'qty'   => $qty,
					'back'  => 0,
				),
				-$qty,
			);
		}

		if ( ! $row || self::TAKEN !== $row['state'] ) {
			return array( $row, 0 );
		}

		$held = max( 0, $row['qty'] - $row['back'] );

		if ( self::RETURN === $event ) {
			return array(
				array(
					'state' => self::BACK,
					'qty'   => $row['qty'],
					'back'  => $row['qty'],
				),
				$held,
			);
		}

		if ( self::REFUND === $event ) {
			$give = min( $qty, $held );
			if ( $give < 1 ) {
				return array( $row, 0 );
			}

			return array(
				array(
					'state' => self::TAKEN,
					'qty'   => $row['qty'],
					'back'  => $row['back'] + $give,
				),
				$give,
			);
		}

		return array( $row, 0 );
	}

	/**
	 * A ledger row as clean values, or null.
	 *
	 * @param mixed $row A row or nothing.
	 * @return array{state: string, qty: int, back: int}|null
	 */
	public static function ledger_row( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['state'] ) ) {
			return null;
		}

		$qty = max( 0, (int) ( $row['qty'] ?? 0 ) );

		return array(
			'state' => self::TAKEN === $row['state'] ? self::TAKEN : self::BACK,
			'qty'   => $qty,
			'back'  => min( $qty, max( 0, (int) ( $row['back'] ?? 0 ) ) ),
		);
	}

	/* ============================================================== */
	/*  Reorder                                                        */
	/* ============================================================== */

	/**
	 * What is left of a design, both piles together.
	 *
	 * @param int|null $giveaway "Really there" of the giveaway pile; null
	 *                           when the design keeps none.
	 * @param int|null $shop     The shop pile; null when the product does
	 *                           not manage stock.
	 * @return int|null Null when neither pile is counted.
	 */
	public static function left( $giveaway, $shop ) {
		if ( null === $giveaway && null === $shop ) {
			return null;
		}

		return max( 0, (int) $giveaway ) + max( 0, (int) $shop );
	}

	/**
	 * What the design reads in the stock table: "Out" when it is no longer
	 * offered, "Reorder" when what is left has reached the reorder number.
	 *
	 * @param bool     $offered    Whether customers are offered the design.
	 * @param int|null $left       left().
	 * @param int|null $reorder_at The reorder number (null = no reminder).
	 * @return string STATUS_*.
	 */
	public static function status( $offered, $left, $reorder_at ) {
		if ( ! $offered ) {
			return self::STATUS_OUT;
		}

		if ( null !== $reorder_at && null !== $left && (int) $left <= (int) $reorder_at ) {
			return self::STATUS_REORDER;
		}

		return self::STATUS_OK;
	}

	/**
	 * Whether a design is down to its reorder number (out or not).
	 *
	 * @param int|null $left       left().
	 * @param int|null $reorder_at The reorder number.
	 * @return bool
	 */
	public static function needs_reorder( $left, $reorder_at ) {
		return null !== $reorder_at && null !== $left && (int) $left <= (int) $reorder_at;
	}

	/**
	 * What to do about the one reorder email of a design:
	 *
	 *   'send'  it has just dropped to its reorder number (no email yet)
	 *   'clear' it is back above the number, or the number was removed:
	 *           the next drop may write again
	 *   ''      nothing — still fine, or already written about
	 *
	 * @param int|null $left       left().
	 * @param int|null $reorder_at The reorder number.
	 * @param bool     $alerted    Whether the email for this drop was sent.
	 * @return string
	 */
	public static function alert_step( $left, $reorder_at, $alerted ) {
		$due = self::needs_reorder( $left, $reorder_at );

		if ( $due ) {
			return $alerted ? '' : 'send';
		}

		return $alerted ? 'clear' : '';
	}

	/**
	 * A reorder number as typed: blank (or junk) means no reminder.
	 *
	 * @param mixed $raw The typed value.
	 * @return int|null
	 */
	public static function reorder_number( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$raw = trim( (string) $raw );

		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return null;
		}

		return (int) min( 1000000, floor( (float) $raw ) );
	}

	/**
	 * How many go in a week, from how many went in the last PACE_DAYS.
	 *
	 * @param int $went How many went.
	 * @return float
	 */
	public static function per_week( $went ) {
		return (float) ( max( 0, (int) $went ) * 7 / self::PACE_DAYS );
	}

	/**
	 * Roughly how many weeks the giveaway pile lasts at that pace; null
	 * when nothing went (no pace to go by).
	 *
	 * @param int $really_there really_there().
	 * @param int $went         How many went in the last PACE_DAYS.
	 * @return float|null
	 */
	public static function weeks_left( $really_there, $went ) {
		$pace = self::per_week( $went );

		if ( $pace <= 0 ) {
			return null;
		}

		return (float) ( max( 0, (int) $really_there ) / $pace );
	}

	/**
	 * Every number of one design's row in the stock table, from the plain
	 * facts.
	 *
	 * @param array $facts {
	 *   @type bool     $keeps_pile Whether the design keeps a giveaway pile.
	 *   @type int      $paper      P.
	 *   @type int      $basis      B.
	 *   @type int      $percent    The "go missing" percent.
	 *   @type int|null $shop       The shop pile (null = stock not managed).
	 *   @type bool     $in_stock   The product's own "in stock" (only read
	 *                              when the design keeps no giveaway pile).
	 *   @type int      $went       How many went in the last PACE_DAYS.
	 *   @type int|null $reorder_at The reorder number.
	 * }
	 * @return array{keeps_pile: bool, paper: int, reserve: int, really_there: int|null, shop: int|null, left: int|null, offered: bool, went: int, per_week: float, weeks: float|null, reorder_at: int|null, status: string}
	 */
	public static function summary( array $facts ) {
		$keeps   = ! empty( $facts['keeps_pile'] );
		$paper   = (int) ( $facts['paper'] ?? 0 );
		$basis   = max( 0, (int) ( $facts['basis'] ?? 0 ) );
		$percent = (int) ( $facts['percent'] ?? self::LOSS_DEFAULT );
		$shop    = isset( $facts['shop'] ) && null !== $facts['shop'] ? max( 0, (int) $facts['shop'] ) : null;
		$went    = max( 0, (int) ( $facts['went'] ?? 0 ) );
		$reorder = self::reorder_number( $facts['reorder_at'] ?? '' );

		$really  = $keeps ? self::really_there( $paper, $basis, $percent ) : null;
		$offered = $keeps ? self::offered( $paper, $basis, $percent ) : ! empty( $facts['in_stock'] );
		$left    = self::left( $really, $shop );

		return array(
			'keeps_pile'   => $keeps,
			'paper'        => $keeps ? $paper : 0,
			'reserve'      => $keeps ? self::reserve( $basis, $percent ) : 0,
			'really_there' => $really,
			'shop'         => $shop,
			'left'         => $left,
			'offered'      => $offered,
			'went'         => $keeps ? $went : 0,
			'per_week'     => $keeps ? self::per_week( $went ) : 0.0,
			'weeks'        => $keeps ? self::weeks_left( (int) $really, $went ) : null,
			'reorder_at'   => $reorder,
			'status'       => self::status( $offered, $left, $reorder ),
		);
	}

	/* ============================================================== */
	/*  Cart arithmetic                                                */
	/* ============================================================== */

	/**
	 * How many of each sticker line fit what the giveaway piles can still
	 * hand out. Lines are served in this order: free lines first (a free
	 * sticker is never squeezed out by the same cart's add-ons), then
	 * add-ons, each in cart order. A line whose design keeps no giveaway
	 * pile is not limited here (WooCommerce limits it by the product's own
	 * stock, as before).
	 *
	 * @param array $lines     key => [ 'design' => int, 'kind' => 'free'|'extra', 'qty' => int ].
	 * @param array $available design id => how many can be handed out
	 *                         (really_there()); a design that is not
	 *                         listed keeps no pile.
	 * @return array<string, int> key => quantity that fits (0 = drop the line).
	 */
	public static function fit( array $lines, array $available ) {
		$room = array();
		foreach ( $available as $design => $n ) {
			$room[ (int) $design ] = max( 0, (int) $n );
		}

		$out = array();

		foreach ( array( Rules::KIND_FREE, Rules::KIND_EXTRA ) as $pass ) {
			foreach ( $lines as $key => $line ) {
				$kind = Rules::KIND_FREE === ( $line['kind'] ?? '' ) ? Rules::KIND_FREE : Rules::KIND_EXTRA;
				if ( $kind !== $pass ) {
					continue;
				}

				$design = (int) ( $line['design'] ?? 0 );
				$qty    = max( 0, (int) ( $line['qty'] ?? 0 ) );

				if ( isset( $room[ $design ] ) ) {
					$qty              = min( $qty, $room[ $design ] );
					$room[ $design ] -= $qty;
				}

				$out[ $key ] = $qty;
			}
		}

		// Hand the answer back in cart order.
		$ordered = array();
		foreach ( $lines as $key => $line ) {
			$ordered[ $key ] = $out[ $key ] ?? 0;
		}

		return $ordered;
	}

	/**
	 * How many more stickers of one design a cart can take from its
	 * giveaway pile.
	 *
	 * @param int   $design    Design id.
	 * @param int   $available really_there() of its pile.
	 * @param array $lines     The cart's sticker lines, as for fit().
	 * @return int
	 */
	public static function room( $design, $available, array $lines ) {
		$held = 0;
		foreach ( $lines as $line ) {
			if ( (int) ( $line['design'] ?? 0 ) === (int) $design ) {
				$held += max( 0, (int) ( $line['qty'] ?? 0 ) );
			}
		}

		return max( 0, (int) $available - $held );
	}

	/**
	 * The sums WooCommerce checks cart lines against, by stock id — what
	 * WC_Cart::get_cart_item_quantities() works out once giveaway lines
	 * answer STOCK_BUCKET.
	 *
	 * A normal purchase of a variation is checked against the sum under
	 * the variation's own stock id, and that sum holds the normal lines
	 * only: the shop pile limits the normal line exactly as it did before
	 * there was a giveaway, whatever else the cart got for free. A sticker
	 * line of a design that keeps NO giveaway pile still counts with the
	 * product's own stock, as it always has.
	 *
	 * @param array $lines [ 'stock_id' => int, 'qty' => int, 'pile' => bool ]
	 *                     per cart line; `pile` true for a giveaway line of
	 *                     a design that keeps a giveaway pile.
	 * @return array<int, int> stock id => quantity.
	 */
	public static function cart_quantities( array $lines ) {
		$sums = array();

		foreach ( $lines as $line ) {
			$id          = ! empty( $line['pile'] ) ? self::STOCK_BUCKET : (int) ( $line['stock_id'] ?? 0 );
			$sums[ $id ] = ( $sums[ $id ] ?? 0 ) + max( 0, (int) ( $line['qty'] ?? 0 ) );
		}

		return $sums;
	}
}
