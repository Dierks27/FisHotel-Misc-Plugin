<?php
/**
 * What kind of product a product is, for the edit screen (pure but for
 * fish_categories()).
 *
 * The kind decides which boxes the screen shows — never what is saved: a
 * box hidden for the kind is still printed and still posted, so nothing
 * saves differently. It is worked out from the categories the screen was
 * drawn with, and again by the script each time a category is ticked.
 *
 *   invoice  in the category `invoices` — treated as a dry good, never a
 *            fish (the Batch plugin's checkout counts it apart);
 *   fish     a live fish: "Livestock — packed in a bag" ticked, or with no
 *            stored choice one of the fish categories (the Batch plugin's
 *            list: `quarantined-fish`, `inverts` unless it says other —
 *            the same rule as the Packing section — plus any category
 *            ticked as live in Product checks, e.g. Corals one day);
 *   med      medication or food: the theme's categories for them, or a
 *            "Who ships it" (EA / Amazon / us) set;
 *   none     no category yet: nothing is hidden;
 *   dry      anything else (gift cards included).
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Kinds
 */
class Kinds {

	const FISH    = 'fish';
	const MED     = 'med';
	const DRY     = 'dry';
	const INVOICE = 'invoice';
	const NONE    = 'none';

	/**
	 * The theme's medication and food categories
	 * (inc/template-functions.php: fishotel_is_medication_product(),
	 * fishotel_is_food_product(); theme 1.28 adds the root `foods`).
	 *
	 * @var string[]
	 */
	const MED_SLUGS = array( 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer', 'foods', 'freeze-dried-foods', 'fish-food', 'medicated-food' );

	/**
	 * The boxes shown for a live fish only (and while no category is
	 * ticked).
	 *
	 * @var string[]
	 */
	const LIVE_ONLY = array( 'fishotel_hotel_data', 'fishotel-compat-category', 'fishotel_cleanroom' );

	/**
	 * The boxes NOT shown for a live fish: Fish essentials writes the short
	 * description (the scientific name under the product's name).
	 *
	 * @var string[]
	 */
	const NOT_LIVE = array( 'postexcerpt' );

	/**
	 * The fish categories: the Batch plugin's list (or the same default
	 * and filter it uses), plus any category ticked as live in Product
	 * checks — one shared answer (\FisHotel\Misc\Live_Categories), so a
	 * product the checks count as live is a live fish here too.
	 *
	 * @return string[]
	 */
	public static function fish_categories() {
		return self::slugs( \FisHotel\Misc\Live_Categories::fish_and_live() );
	}

	/**
	 * A list of slugs, cleaned (pure): lower-case, trimmed, each once.
	 *
	 * @param mixed $raw A list.
	 * @return string[]
	 */
	public static function slugs( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $slug ) {
			$slug = is_scalar( $slug ) && ! is_bool( $slug ) ? strtolower( trim( (string) $slug ) ) : '';
			if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * The kind (pure).
	 *
	 * @param string[] $slugs       The product's own category slugs.
	 * @param mixed    $packs       "Livestock — packed in a bag": 'yes' | 'no' | '' (no stored choice).
	 * @param mixed    $fulfillment "Who ships it": '' or 'none' = nobody else.
	 * @param string[] $fish_slugs  The fish categories.
	 * @return string One of the constants.
	 */
	public static function kind( array $slugs, $packs, $fulfillment, array $fish_slugs ) {
		$slugs = self::slugs( $slugs );
		$packs = is_scalar( $packs ) ? strtolower( trim( (string) $packs ) ) : '';
		$ships = is_scalar( $fulfillment ) ? strtolower( trim( (string) $fulfillment ) ) : '';

		if ( in_array( 'invoices', $slugs, true ) ) {
			return self::INVOICE;
		}
		if ( 'yes' === $packs || ( 'no' !== $packs && array_intersect( $slugs, self::slugs( $fish_slugs ) ) ) ) {
			return self::FISH;
		}
		if ( array_intersect( $slugs, self::MED_SLUGS ) || ( '' !== $ships && 'none' !== $ships ) ) {
			return self::MED;
		}
		return $slugs ? self::DRY : self::NONE;
	}

	/**
	 * Whether a box is shown for a kind (pure).
	 *
	 * @param string $box  Box id.
	 * @param string $kind A kind.
	 * @return bool
	 */
	public static function shows( $box, $kind ) {
		if ( in_array( $box, self::LIVE_ONLY, true ) ) {
			return in_array( $kind, array( self::FISH, self::NONE ), true );
		}
		if ( in_array( $box, self::NOT_LIVE, true ) ) {
			return self::FISH !== $kind;
		}
		return true;
	}
}
