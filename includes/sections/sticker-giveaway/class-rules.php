<?php
/**
 * Sticker Giveaway rules — pure functions, no WordPress calls.
 *
 * WHY pure: "does this cart get a free sticker?" and "how many add-ons
 * may they buy?" are the two decisions that cost Jeff money if they go
 * wrong. Keeping them free of WC()/get_option()/get_terms() means they
 * run headless under `tests/rules-test.php`, and the Cart class is left
 * with only the boring job of translating cart items into plain arrays.
 *
 * A "line" handed to these methods is:
 *   [
 *     'product_id'     => int,
 *     'category_slugs' => string[]  (own slugs AND ancestor slugs — the
 *                                     caller resolves ancestry),
 *     'line_subtotal'  => float,
 *     'is_sticker'     => bool,
 *   ]
 *
 * A DESIGN is what a customer picks: a simple product, or one variation
 * of a variable product. Its id is the product's own post id — the
 * variation id for a variation — so the pool stays one flat list of ids
 * (design_id() reads it off a cart line). A design's count is its
 * product's own WooCommerce stock; the section keeps no count of its own.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	/**
	 * Kind marker for the free line.
	 *
	 * @var string
	 */
	const KIND_FREE = 'free';

	/**
	 * Kind marker for paid add-on lines.
	 *
	 * @var string
	 */
	const KIND_EXTRA = 'extra';

	/**
	 * How a count reads in the stock table (status()).
	 *
	 * @var string
	 */
	const STATUS_OK      = 'ok';
	const STATUS_REORDER = 'reorder';
	const STATUS_OUT     = 'out';

	/**
	 * The one cart fee an EA-only order with a paid sticker pays for the
	 * stamp: nothing ships from FisHotel, so the sticker goes as a letter.
	 *
	 * @var float
	 */
	const MAIL_FEE = 1.00;

	/**
	 * The giveaway pool as a clean list of positive, unique design IDs (a
	 * simple product's id, or a variation's).
	 *
	 * @param array $settings Settings array.
	 * @return int[]
	 */
	public static function pool( array $settings ) {
		$pool = array();

		foreach ( (array) ( $settings['sticker_product_ids'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $pool, true ) ) {
				$pool[] = $id;
			}
		}

		return $pool;
	}

	/**
	 * The design of a cart line: its variation when it has one, else its
	 * product. A cart line of a variation design is found again by this id
	 * (a design never leaves an attribute on "Any", so one variation is one
	 * design, and the line's attribute values are that variation's own).
	 *
	 * @param mixed $item A cart line (or anything with product_id / variation_id).
	 * @return int 0 when it names neither.
	 */
	public static function design_id( $item ) {
		if ( ! is_array( $item ) ) {
			return 0;
		}

		$variation = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		if ( $variation > 0 ) {
			return $variation;
		}

		return isset( $item['product_id'] ) ? max( 0, (int) $item['product_id'] ) : 0;
	}

	/**
	 * A design's name on the picker. For a variation that is its own
	 * attribute value(s) as the owner typed them — "Blue Tang", or
	 * "Blue Tang, Large" with two attributes — and never
	 * "Stickers - Blue Tang": the customer is choosing between the
	 * variations of one product, so the product's name says nothing. A
	 * simple product (no values) keeps its own name.
	 *
	 * @param array  $values   The attribute values, in the product's order.
	 * @param string $fallback The product's own name.
	 * @return string
	 */
	public static function design_name( array $values, $fallback ) {
		$parts = array();

		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return $parts ? implode( ', ', $parts ) : trim( (string) $fallback );
	}

	/**
	 * Whether a variation fixes every attribute its product varies by. A
	 * variation that leaves one on "Any" cannot be a design: WooCommerce
	 * will not put it in a cart without being told which value, and the
	 * picker has nobody to ask.
	 *
	 * @param string[] $attribute_keys The product's variation attributes
	 *                                 (`attribute_style`, `attribute_pa_colour`).
	 * @param array    $values         The variation's value per key.
	 * @return bool
	 */
	public static function variation_is_fixed( array $attribute_keys, array $values ) {
		foreach ( $attribute_keys as $key ) {
			$value = $values[ $key ] ?? '';
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Which variations of one picked product are designs after a save, and
	 * which ones the page has shown so far.
	 *
	 *   - The page listed them (ticks were posted): the ticked ones are
	 *     designs; every listed one counts as shown.
	 *   - The product was picked just now and the list was not posted
	 *     (saved before the page drew it): every variation is ticked.
	 *   - The product was already saved and no list came: nothing changes.
	 *
	 * A variation added to the product later was never shown, so it is not
	 * in `seen` and never in `ticked`: the page lists it unticked and marks
	 * it new — it is never offered silently. A variation with an attribute
	 * on "Any" is never ticked.
	 *
	 * @param int[] $children   Every variation of the product, in its order.
	 * @param int[] $fixed      Those that fix every attribute.
	 * @param bool  $listed     Whether the form carried this product's list.
	 * @param int[] $ticked     Ticked in the form.
	 * @param int[] $shown      Listed in the form.
	 * @param bool  $was_saved  Whether the product was picked before this save.
	 * @param int[] $old_pool   The pool before this save.
	 * @param int[] $old_seen   The variations shown before this save.
	 * @return array{ticked: int[], seen: int[]}
	 */
	public static function variation_ticks( array $children, array $fixed, $listed, array $ticked, array $shown, $was_saved, array $old_pool, array $old_seen ) {
		$children = self::ids( $children );
		$fixed    = array_values( array_intersect( $children, self::ids( $fixed ) ) );

		if ( $listed ) {
			$want = self::ids( $ticked );
			$seen = self::ids( $shown );
		} elseif ( $was_saved ) {
			$want = self::ids( $old_pool );
			$seen = self::ids( $old_seen );
		} else {
			$want = $fixed;
			$seen = $children;
		}

		return array(
			'ticked' => array_values( array_intersect( $fixed, $want ) ),
			'seen'   => array_values( array_intersect( $children, array_merge( $seen, $want ) ) ),
		);
	}

	/**
	 * Whether the page marks a variation "New — not offered yet": one that
	 * was added to an already saved product since the page last listed its
	 * variations. A variation that leaves an attribute on "Any" is never
	 * called new — it cannot be ticked, and its row says why instead.
	 *
	 * @param int   $id    The variation.
	 * @param bool  $fixed Whether it fixes every attribute.
	 * @param bool  $saved Whether its product is in the saved settings.
	 * @param int[] $seen  The variations the page has shown so far.
	 * @param int[] $pool  The pool.
	 * @return bool
	 */
	public static function variation_is_new( $id, $fixed, $saved, array $seen, array $pool ) {
		return $fixed && $saved && ! in_array( (int) $id, self::ids( array_merge( $seen, $pool ) ), true );
	}

	/**
	 * A typed "Reorder at" number: a whole number of 0 or more, or null
	 * for blank or junk (no reminder).
	 *
	 * @param mixed $raw What was typed or stored.
	 * @return int|null
	 */
	public static function reorder_number( $raw ) {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return null;
		}

		return (int) min( 1000000, floor( (float) $raw ) );
	}

	/**
	 * The "Reorder at" number of every count after a save: as typed for a
	 * count whose row the page showed (blank = no reminder), as before for
	 * one that was not shown, none for a count no design uses any more.
	 *
	 * A COUNT is the product whose WooCommerce stock a design comes out
	 * of: the design itself, or the variable product when that keeps one
	 * stock number for all its variations.
	 *
	 * @param int[] $counts   The counts of the pool after this save.
	 * @param array $typed    count id => what was typed.
	 * @param int[] $listed   Counts whose row was in the form.
	 * @param array $previous count id => number, before this save.
	 * @return array<int, int>
	 */
	public static function reorder_numbers( array $counts, array $typed, array $listed, array $previous ) {
		$listed = self::ids( $listed );
		$out    = array();

		foreach ( self::ids( $counts ) as $id ) {
			$number = self::reorder_number( in_array( $id, $listed, true ) ? ( $typed[ $id ] ?? '' ) : ( $previous[ $id ] ?? '' ) );

			if ( null !== $number ) {
				$out[ $id ] = $number;
			}
		}

		return $out;
	}

	/**
	 * A count's "Reorder at" number, or null when it has no reminder.
	 *
	 * @param int   $count_id Count id.
	 * @param array $settings Settings array.
	 * @return int|null
	 */
	public static function reorder_at( $count_id, array $settings ) {
		$numbers = (array) ( $settings['reorder_at'] ?? array() );

		return self::reorder_number( $numbers[ (int) $count_id ] ?? '' );
	}

	/**
	 * Whether a design has a sticker to hand out. The count is the
	 * product's own WooCommerce stock: at least 1 when WooCommerce tracks
	 * it — also when the product allows backorders; the giveaway never
	 * hands out a sticker that is not there — and "in stock" when it does
	 * not.
	 *
	 * @param bool     $in_stock What WooCommerce says (is_in_stock()).
	 * @param int|null $stock    The stock number; null when not tracked.
	 * @return bool
	 */
	public static function has_stock( $in_stock, $stock ) {
		return $in_stock && ( null === $stock || (int) $stock >= 1 );
	}

	/**
	 * How many each sticker line may keep, given what its count holds.
	 *
	 * One count is shared by everything that comes out of it: a normal
	 * purchase, the free sticker and an add-on of the same design (and
	 * every variation of a product that keeps one stock number). A sticker
	 * never costs a sale and never blocks an order, so the normal purchases
	 * are served first, then the free sticker, then the add-ons — a sticker
	 * line that no longer fits is cut down or dropped (0) before
	 * WooCommerce's own check can refuse the cart on its account.
	 *
	 * @param array $lines     key => [ 'count' => int, 'kind' => ''|'free'|'extra', 'qty' => int ].
	 * @param array $available count id => how many it can still hand out;
	 *                         a count that is not tracked is left out and
	 *                         limits nothing.
	 * @return array<string, int> Sticker line key => the quantity that fits.
	 */
	public static function fit( array $lines, array $available ) {
		$out = array();

		foreach ( array( '', self::KIND_FREE, self::KIND_EXTRA ) as $turn ) {
			foreach ( $lines as $key => $line ) {
				$count = (int) ( $line['count'] ?? 0 );
				$qty   = max( 0, (int) ( $line['qty'] ?? 0 ) );

				if ( (string) ( $line['kind'] ?? '' ) !== $turn ) {
					continue;
				}

				if ( isset( $available[ $count ] ) ) {
					$qty                 = min( $qty, max( 0, (int) $available[ $count ] ) );
					$available[ $count ] = (int) $available[ $count ] - $qty;
				}

				if ( '' !== $turn ) {
					$out[ $key ] = $qty;
				}
			}
		}

		return $out;
	}

	/**
	 * How a count reads in the stock table: '' when WooCommerce does not
	 * track it, "out" at 0, "reorder" at or below its "Reorder at" number,
	 * else "ok".
	 *
	 * @param int|null $stock      The stock number; null when not tracked.
	 * @param int|null $reorder_at The "Reorder at" number; null for none.
	 * @return string
	 */
	public static function status( $stock, $reorder_at ) {
		if ( null === $stock ) {
			return '';
		}
		if ( (int) $stock <= 0 ) {
			return self::STATUS_OUT;
		}

		return null !== $reorder_at && (int) $stock <= (int) $reorder_at ? self::STATUS_REORDER : self::STATUS_OK;
	}

	/**
	 * What the reorder email does when a count's stock is looked at:
	 * 'send' the first time the stock is at or below the number, nothing
	 * while it stays there, 'clear' (the next drop writes again) once it is
	 * back ABOVE the number or the number was taken away.
	 *
	 * @param int|null $stock      The stock number; null when not tracked.
	 * @param int|null $reorder_at The "Reorder at" number; null for none.
	 * @param bool     $sent       Whether the email for this drop has gone.
	 * @return string 'send', 'clear' or ''.
	 */
	public static function reorder_step( $stock, $reorder_at, $sent ) {
		if ( null === $reorder_at ) {
			return $sent ? 'clear' : '';
		}
		if ( null === $stock ) {
			return '';
		}
		if ( (int) $stock <= (int) $reorder_at ) {
			return $sent ? '' : 'send';
		}

		return $sent ? 'clear' : '';
	}

	/**
	 * Why a design cannot be handed out right now; empty when it can.
	 *
	 * The picker, the adding of a line and every totals pass ask this one
	 * question, so a design is offered exactly when a line of it would be
	 * kept. The reasons, as codes the settings page puts into words:
	 *
	 *   type                not a simple product or a variation
	 *   unpublished         the product (or the variation) is not published
	 *   parent_unpublished  the variation's product is not published
	 *   no_price            no price, so WooCommerce will not sell it
	 *   not_purchasable     has a price but WooCommerce still refuses it
	 *   any_attribute       the variation leaves an attribute on "Any"
	 *   out_of_stock        no sticker to hand out (has_stock())
	 *
	 * @param array $facts type, published, parent_published, has_price,
	 *                     purchasable, fixed, in_stock, stock
	 *                     (Designs::facts()).
	 * @return string[]
	 */
	public static function blockers( array $facts ) {
		$out  = array();
		$type = (string) ( $facts['type'] ?? '' );

		if ( 'simple' !== $type && 'variation' !== $type ) {
			return array( 'type' );
		}

		$published = ! empty( $facts['published'] );

		if ( ! $published ) {
			$out[] = 'unpublished';
		}
		if ( 'variation' === $type && empty( $facts['parent_published'] ) ) {
			$out[] = 'parent_unpublished';
		}
		if ( empty( $facts['purchasable'] ) ) {
			if ( empty( $facts['has_price'] ) ) {
				$out[] = 'no_price';
			} elseif ( $published ) {
				$out[] = 'not_purchasable';
			}
		}
		if ( 'variation' === $type && empty( $facts['fixed'] ) ) {
			$out[] = 'any_attribute';
		}
		if ( ! self::has_stock( ! empty( $facts['in_stock'] ), $facts['stock'] ?? null ) ) {
			$out[] = 'out_of_stock';
		}

		return $out;
	}

	/**
	 * A list of ids as positive, unique whole numbers, order kept.
	 *
	 * @param mixed $ids Ids.
	 * @return int[]
	 */
	public static function ids( $ids ) {
		$out = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$id = is_scalar( $id ) ? (int) $id : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether a design ID is in the pool.
	 *
	 * @param int   $product_id Design ID.
	 * @param array $settings   Settings array.
	 * @return bool
	 */
	public static function in_pool( $product_id, array $settings ) {
		return in_array( (int) $product_id, self::pool( $settings ), true );
	}

	/**
	 * Eligible category slugs as a clean string list.
	 *
	 * @param array $settings Settings array.
	 * @return string[]
	 */
	public static function eligible_slugs( array $settings ) {
		$slugs = array();

		foreach ( (array) ( $settings['eligible_category_slugs'] ?? array() ) as $slug ) {
			$slug = strtolower( trim( (string) $slug ) );
			if ( '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Does this cart qualify for the free sticker?
	 *
	 * Rules, in order:
	 *   1. Sticker lines never count — a cart holding only stickers is not
	 *      a fish order, and the free line must not keep itself eligible.
	 *   2. At least one remaining line must sit in an eligible category
	 *      (the caller passes ancestor slugs, so a child of
	 *      `quarantined-fish` matches). An EMPTY eligible list means NO
	 *      order qualifies — the giveaway is off until a category is
	 *      ticked. (Jeff: "mostly only fish orders get stickers for
	 *      free"; an accidental untick-all must never widen that to the
	 *      whole store.)
	 *   3. The subtotal of the remaining lines must reach `min_subtotal`
	 *      (0 = no minimum). Compared at cent precision so 49.995 does not
	 *      sneak past a $50 floor by float noise.
	 *
	 * @param array $lines    Lines as described in the file docblock.
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function is_eligible( array $lines, array $settings ) {
		$eligible_slugs = self::eligible_slugs( $settings );

		if ( empty( $eligible_slugs ) ) {
			return false;
		}

		$subtotal    = 0.0;
		$has_line    = false;
		$category_ok = false;

		foreach ( $lines as $line ) {
			if ( ! empty( $line['is_sticker'] ) ) {
				continue;
			}

			$has_line  = true;
			$subtotal += (float) ( $line['line_subtotal'] ?? 0 );

			if ( ! $category_ok ) {
				$line_slugs = array_map( 'strtolower', array_map( 'strval', (array) ( $line['category_slugs'] ?? array() ) ) );
				if ( array_intersect( $eligible_slugs, $line_slugs ) ) {
					$category_ok = true;
				}
			}
		}

		if ( ! $has_line || ! $category_ok ) {
			return false;
		}

		$min = (float) ( $settings['min_subtotal'] ?? 0 );

		return round( $subtotal, 2 ) >= round( $min, 2 );
	}

	/**
	 * Clamp a raw picker selection to what the settings allow.
	 *
	 * - `free` becomes 0 unless the ID is in the pool.
	 * - Extras keep only pool IDs with a quantity of 1..upsell_max_qty;
	 *   anything above the max is clamped down, zero/negative is dropped.
	 * - Extras are emptied entirely when the upsell is disabled.
	 *
	 * @param int|string $free_pid Raw free sticker product ID (0 = none).
	 * @param array      $extras   Raw [product_id => qty] map.
	 * @param array      $settings Settings array.
	 * @return array{free: int, extras: array<int, int>}
	 */
	public static function normalise_selection( $free_pid, array $extras, array $settings ) {
		$pool = self::pool( $settings );
		$free = (int) $free_pid;

		if ( ! in_array( $free, $pool, true ) ) {
			$free = 0;
		}

		$out = array();
		$max = self::max_extra_qty( $settings );

		if ( ! empty( $settings['upsell_enabled'] ) && $max > 0 ) {
			foreach ( $extras as $pid => $qty ) {
				$pid = (int) $pid;
				$qty = (int) $qty;

				if ( ! in_array( $pid, $pool, true ) ) {
					continue;
				}

				$qty = min( $max, max( 0, $qty ) );

				if ( $qty > 0 ) {
					$out[ $pid ] = $qty;
				}
			}
		}

		return array(
			'free'   => $free,
			'extras' => $out,
		);
	}

	/**
	 * The add-on quantity ceiling per design (0 when unset/invalid).
	 *
	 * Shared by the picker (clamping the posted selection) and the totals
	 * pass (clamping lines the customer bumped on the cart page), so the
	 * max is enforced wherever a quantity can change.
	 *
	 * @param array $settings Settings array.
	 * @return int
	 */
	public static function max_extra_qty( array $settings ) {
		return max( 0, (int) ( $settings['upsell_max_qty'] ?? 0 ) );
	}

	/**
	 * Unit price for a sticker line of the given kind.
	 *
	 * @param string $kind     'free' or 'extra'.
	 * @param array  $settings Settings array.
	 * @return float
	 */
	public static function price_for( $kind, array $settings ) {
		if ( self::KIND_EXTRA === $kind ) {
			return max( 0.0, round( (float) ( $settings['upsell_price'] ?? 0 ), 2 ) );
		}

		return 0.0;
	}

	/* ============================================================== */
	/*  EA-only orders: the sticker is mailed by letter                */
	/* ============================================================== */

	/**
	 * Whether a cart or an order holds only lines the supplier (Everything
	 * Aquatic) drop-ships, beside the sticker lines: every non-sticker line
	 * is an EA line and there is at least one. Nothing on such an order
	 * ships from FisHotel, so its sticker goes out as a letter. A cart of
	 * stickers only is not EA-only (no EA line).
	 *
	 * A "letter line" is [ 'kind' => ''|'free'|'extra', 'qty' => int,
	 * 'is_ea' => bool, 'price' => float (optional: what one sticker of the
	 * line costs) ]; the caller asks the theme whether a line is EA.
	 *
	 * @param array $lines Letter lines.
	 * @return bool
	 */
	public static function is_ea_only( array $lines ) {
		$has_ea = false;

		foreach ( $lines as $line ) {
			if ( '' !== (string) ( $line['kind'] ?? '' ) ) {
				continue;
			}
			if ( empty( $line['is_ea'] ) ) {
				return false;
			}
			$has_ea = true;
		}

		return $has_ea;
	}

	/**
	 * How many stickers the lines hold, free and paid. A paid sticker is an
	 * add-on line that costs something: an add-on priced at 0 in the
	 * settings is counted with the free ones (it still goes in the
	 * envelope, but there is no stamp to pay for). A line without a price
	 * is an add-on as always (paid).
	 *
	 * @param array $lines Letter lines.
	 * @return array{free: int, paid: int}
	 */
	public static function sticker_counts( array $lines ) {
		$out = array(
			'free' => 0,
			'paid' => 0,
		);

		foreach ( $lines as $line ) {
			$qty  = max( 0, (int) ( $line['qty'] ?? 0 ) );
			$kind = (string) ( $line['kind'] ?? '' );

			if ( self::KIND_FREE === $kind || ( self::KIND_EXTRA === $kind && isset( $line['price'] ) && (float) $line['price'] <= 0 ) ) {
				$out['free'] += $qty;
			} elseif ( self::KIND_EXTRA === $kind ) {
				$out['paid'] += $qty;
			}
		}

		return $out;
	}

	/**
	 * What mailing by letter means for a cart or an order:
	 *
	 *   - letter: an EA-only cart or order that holds a sticker (free or
	 *     paid) — the customer is told, the order is flagged;
	 *   - count:  the stickers to put in the envelope (free + paid), 0 when
	 *     there is no letter;
	 *   - fee:    MAIL_FEE once when there is a letter AND at least one paid
	 *     sticker; 0 for the free sticker alone (the shop pays that stamp).
	 *
	 * A mixed cart, a fish-only cart and a sticker-only cart get no letter,
	 * no fee and a count of 0. So does an EA-only cart or order that rides
	 * along in a box FisHotel ships anyway ($rides_along): it joins a
	 * customer's open order (the theme's combined shipping) that holds
	 * something FisHotel ships, so the sticker goes in that box.
	 *
	 * @param array $lines       Letter lines.
	 * @param bool  $rides_along Whether it joins a box FisHotel ships.
	 * @return array{letter: bool, count: int, paid: int, fee: float}
	 */
	public static function mail_letter( array $lines, $rides_along = false ) {
		$counts  = self::sticker_counts( $lines );
		$total   = $counts['free'] + $counts['paid'];
		$ea_only = ! $rides_along && $total > 0 && self::is_ea_only( $lines );

		return array(
			'letter' => $ea_only,
			'count'  => $ea_only ? $total : 0,
			'paid'   => $ea_only ? $counts['paid'] : 0,
			'fee'    => $ea_only && $counts['paid'] > 0 ? self::MAIL_FEE : 0.0,
		);
	}

	/**
	 * Whether FisHotel ships a box for these lines (an order the new one
	 * may join): something that is not a sticker and not EA, or stickers
	 * with no EA line beside them (a sticker-only order ships by the
	 * sticker's shipping class). An EA-only order — with or without its
	 * stickers — ships nothing from FisHotel.
	 *
	 * @param array $lines Letter lines.
	 * @return bool
	 */
	public static function ships_from_fishotel( array $lines ) {
		$has_ea      = false;
		$has_sticker = false;

		foreach ( $lines as $line ) {
			if ( '' !== (string) ( $line['kind'] ?? '' ) ) {
				$has_sticker = true;
			} elseif ( empty( $line['is_ea'] ) ) {
				return true;
			} else {
				$has_ea = true;
			}
		}

		return $has_sticker && ! $has_ea;
	}

	/**
	 * Whether add-on steppers should be offered to this cart.
	 *
	 * @param bool  $eligible Whether the cart qualifies for a free sticker.
	 * @param array $settings Settings array.
	 * @return bool
	 */
	public static function offers_upsell( $eligible, array $settings ) {
		if ( empty( $settings['upsell_enabled'] ) ) {
			return false;
		}

		return $eligible || ! empty( $settings['upsell_for_everyone'] );
	}
}
