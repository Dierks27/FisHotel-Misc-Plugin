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
 * (design_id() reads it off a cart line). The numbers of a design's
 * giveaway pile live in class-piles.php.
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
	 * Which designs keep a giveaway pile after a save.
	 *
	 *   - A design whose row the page showed: as its switch was posted.
	 *   - A design already in the pool whose row was not posted: as before.
	 *   - A design picked just now: ON.
	 *
	 * A simple product that was in the pool before giveaway piles existed
	 * has no "before" (nothing was stored), so it stays OFF — it keeps
	 * using its own WooCommerce stock until the owner switches it on.
	 *
	 * @param int[] $pool     The pool after this save.
	 * @param int[] $old_pool The pool before it.
	 * @param int[] $old_on   The designs that kept a pile before it.
	 * @param int[] $on       Switches posted ON.
	 * @param int[] $listed   Designs whose row was in the form.
	 * @return int[]
	 */
	public static function pile_ids( array $pool, array $old_pool, array $old_on, array $on, array $listed ) {
		$old_pool = self::ids( $old_pool );
		$old_on   = self::ids( $old_on );
		$on       = self::ids( $on );
		$listed   = self::ids( $listed );
		$out      = array();

		foreach ( self::ids( $pool ) as $id ) {
			if ( in_array( $id, $listed, true ) ) {
				$keep = in_array( $id, $on, true );
			} elseif ( in_array( $id, $old_pool, true ) ) {
				$keep = in_array( $id, $old_on, true );
			} else {
				$keep = true;
			}

			if ( $keep ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The reorder number of every design after a save: as typed for a
	 * design whose row the page showed (blank = no reminder), as before for
	 * one that was not shown, none for a design that left the pool.
	 *
	 * @param int[] $pool     The pool after this save.
	 * @param array $typed    design id => what was typed.
	 * @param int[] $listed   Designs whose row was in the form.
	 * @param array $previous design id => number, before this save.
	 * @return array<int, int>
	 */
	public static function reorder_numbers( array $pool, array $typed, array $listed, array $previous ) {
		$listed = self::ids( $listed );
		$out    = array();

		foreach ( self::ids( $pool ) as $id ) {
			$number = in_array( $id, $listed, true )
				? Piles::reorder_number( $typed[ $id ] ?? '' )
				: Piles::reorder_number( $previous[ $id ] ?? '' );

			if ( null !== $number ) {
				$out[ $id ] = $number;
			}
		}

		return $out;
	}

	/**
	 * Whether a design keeps a giveaway pile.
	 *
	 * @param int   $design_id Design id.
	 * @param array $settings  Settings array.
	 * @return bool
	 */
	public static function keeps_pile( $design_id, array $settings ) {
		return in_array( (int) $design_id, self::ids( (array) ( $settings['pile_ids'] ?? array() ) ), true );
	}

	/**
	 * A design's reorder number, or null when it has no reminder.
	 *
	 * @param int   $design_id Design id.
	 * @param array $settings  Settings array.
	 * @return int|null
	 */
	public static function reorder_at( $design_id, array $settings ) {
		$numbers = (array) ( $settings['reorder_at'] ?? array() );

		return Piles::reorder_number( $numbers[ (int) $design_id ] ?? '' );
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
	 *   pile_empty          keeps a giveaway pile and it holds nothing
	 *   pile_reserve        the pile is down to its "go missing" reserve
	 *   out_of_stock        keeps no giveaway pile and is out of stock
	 *
	 * A design that keeps a giveaway pile is NOT asked whether the shop
	 * has stock: the shop pile may be at 0 and the design is still offered.
	 * One that keeps none is asked exactly what it always was — published,
	 * purchasable, in stock.
	 *
	 * @param array $facts   type, published, parent_published, has_price,
	 *                       purchasable, fixed, in_stock, keeps_pile, paper,
	 *                       basis (Designs::facts()).
	 * @param int   $percent The "go missing" percent.
	 * @return string[]
	 */
	public static function blockers( array $facts, $percent ) {
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

		if ( ! empty( $facts['keeps_pile'] ) ) {
			$paper = (int) ( $facts['paper'] ?? 0 );
			if ( $paper <= 0 ) {
				$out[] = 'pile_empty';
			} elseif ( ! Piles::offered( $paper, (int) ( $facts['basis'] ?? 0 ), $percent ) ) {
				$out[] = 'pile_reserve';
			}
		} elseif ( empty( $facts['in_stock'] ) ) {
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
