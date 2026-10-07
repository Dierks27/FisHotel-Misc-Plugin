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
	 * The giveaway pool as a clean list of positive, unique product IDs.
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
	 * Whether a product ID is in the pool.
	 *
	 * @param int   $product_id Product ID.
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
	 *      `quarantined-fish` matches). An EMPTY eligible list means no
	 *      category requirement at all — every order gets one.
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
		$subtotal       = 0.0;
		$has_line       = false;
		$category_ok    = empty( $eligible_slugs );

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
		$max = (int) ( $settings['upsell_max_qty'] ?? 0 );

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
