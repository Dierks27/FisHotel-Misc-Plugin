<?php
/**
 * Which product categories hold live animals — one answer for every
 * section that asks.
 *
 *   - fish(): the Batch plugin's fish categories (its
 *     `FisHotel_Ship_Day::fish_categories()`, else the same default under
 *     the same filter, `fishotel_ship_day_fish_categories`:
 *     `quarantined-fish` and `inverts`).
 *   - live(): the categories the owner ticked as live in Product checks
 *     (the option `fishotel_product_checks`, key `live_cats` — a Corals
 *     category can simply be ticked there one day), else fish(). Edit
 *     screens reads the same list, so a product counted as live by the
 *     checks is a live fish on its edit screen too.
 *
 * Reads only; nothing is written here. slugs() is pure.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Live_Categories
 */
class Live_Categories {

	/**
	 * The option Product checks keeps the ticked live categories in.
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_product_checks';

	/**
	 * The most categories kept.
	 *
	 * @var int
	 */
	const MAX = 100;

	/**
	 * The default when the Batch plugin is not there.
	 *
	 * @var string[]
	 */
	const DEFAULT_FISH = array( 'quarantined-fish', 'inverts' );

	/**
	 * A list of slugs, cleaned (pure): lower-case, letters, digits,
	 * hyphens, underscores (and %-encoded letters), each once, at most MAX.
	 *
	 * @param mixed $raw A list.
	 * @return string[]
	 */
	public static function slugs( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $slug ) {
			$slug = is_scalar( $slug ) && ! is_bool( $slug ) ? strtolower( trim( (string) $slug ) ) : '';
			if ( '' !== $slug && 1 === preg_match( '/^[a-z0-9_%\-]{1,200}$/', $slug ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The Batch plugin's fish categories.
	 *
	 * @return string[]
	 */
	public static function fish() {
		$cats = self::DEFAULT_FISH;
		if ( class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'fish_categories' ) ) ) {
			try {
				$cats = (array) \FisHotel_Ship_Day::fish_categories();
			} catch ( \Throwable $e ) {
				$cats = self::DEFAULT_FISH;
			}
		} elseif ( function_exists( 'apply_filters' ) ) {
			$cats = (array) apply_filters( 'fishotel_ship_day_fish_categories', $cats );
		}
		return self::slugs( $cats );
	}

	/**
	 * The live categories as saved in Product checks, or null when none
	 * were saved.
	 *
	 * @return string[]|null
	 */
	public static function saved() {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null;
		if ( is_array( $stored ) && isset( $stored['live_cats'] ) && is_array( $stored['live_cats'] ) ) {
			return self::slugs( $stored['live_cats'] );
		}
		return null;
	}

	/**
	 * The live categories: as saved, else the fish categories.
	 *
	 * @return string[]
	 */
	public static function live() {
		$saved = self::saved();
		return null === $saved ? self::fish() : $saved;
	}

	/**
	 * The fish categories and the live ones together — for what is only
	 * ever SHOWN by them (Edit screens' fish boxes): a category ticked as
	 * live is added, and a fish category left unticked never hides a box.
	 *
	 * @return string[]
	 */
	public static function fish_and_live() {
		return self::slugs( array_merge( self::fish(), (array) self::saved() ) );
	}
}
