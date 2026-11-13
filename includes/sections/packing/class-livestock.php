<?php
/**
 * "Livestock — packed in a bag": whether a product is something that goes
 * in a bag at all. Fish and other livestock do; medication, food and dry
 * goods do not, and need no Bag size.
 *
 * The owner says so with a checkbox in the product editor (Shipping tab),
 * stored as the hidden product meta `_fh_packs_in_bag` on the PARENT
 * product: 'yes' or 'no'. A variation has none of its own — it is read
 * from its parent. Absent means "follow the default".
 *
 * THE ONE RULE (decide()), asked wherever a product decides whether
 * something is bagged — an order line, the fill tool, the bag contracts,
 * the product editor:
 *
 *   'yes'   packed in a bag, whatever its category;
 *   'no'    not packed in a bag — even in a fish category, and even with a
 *           Bag size stored earlier (which is kept, and stops counting);
 *   absent  packed in a bag when the product is in one of the fish
 *           categories (Lines::fish_categories()). And, so that nothing set
 *           before the checkbox existed silently changes, a product that
 *           carries a Bag size of its own still counts as it did.
 *
 * Nothing here writes anything, and nothing here prints anything: like the
 * Bag size, the answer is a record for packing only.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Livestock
 */
class Livestock {

	const YES = 'yes';
	const NO  = 'no';

	/**
	 * A stored value as the rule reads it (pure): 'yes', 'no', or '' for
	 * anything else — absent.
	 *
	 * @param mixed $value The `_fh_packs_in_bag` meta.
	 * @return string 'yes' | 'no' | ''.
	 */
	public static function flag( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		return self::YES === $value || self::NO === $value ? $value : '';
	}

	/**
	 * The rule itself (pure).
	 *
	 * @param mixed $flag             The stored checkbox value of the (parent) product.
	 * @param bool  $in_fish_category Whether the product is in one of the fish categories.
	 * @param bool  $has_own_bag      Whether it carries a Bag size of its own that names a bag of the list.
	 * @return bool Whether it is packed in a bag.
	 */
	public static function decide( $flag, $in_fish_category, $has_own_bag = false ) {
		$flag = self::flag( $flag );
		if ( self::YES === $flag ) {
			return true;
		}
		if ( self::NO === $flag ) {
			return false;
		}
		return (bool) $in_fish_category || (bool) $has_own_bag;
	}

	/**
	 * The product that carries the checkbox: the product itself, or the
	 * parent of a variation.
	 *
	 * @param object|null $product WC_Product.
	 * @return object|null
	 */
	public static function parent_of( $product ) {
		if ( ! is_object( $product ) ) {
			return null;
		}
		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( $parent_id < 1 ) {
			return $product;
		}
		$parent = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : null;
		return is_object( $parent ) ? $parent : null;
	}

	/**
	 * The stored checkbox value that goes for a product ('' when there is
	 * none) — a variation's is its parent's.
	 *
	 * @param object|null $product WC_Product.
	 * @return string 'yes' | 'no' | ''.
	 */
	public static function stored( $product ) {
		$parent = self::parent_of( $product );
		if ( null === $parent || ! method_exists( $parent, 'get_meta' ) ) {
			return '';
		}
		return self::flag( $parent->get_meta( Packing::META_PACKS, true ) );
	}

	/**
	 * Whether a product (its parent, for a variation) is in one of the fish
	 * categories.
	 *
	 * @param object|null   $product WC_Product.
	 * @param string[]|null $fish    Lines::fish_categories() (null = ask).
	 * @return bool
	 */
	public static function in_fish_category( $product, $fish = null ) {
		$fish = is_array( $fish ) ? $fish : Lines::fish_categories();
		foreach ( Lines::category_slugs( $product ) as $slug ) {
			if ( in_array( strtolower( trim( (string) $slug ) ), $fish, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The rule for a product or a variation.
	 *
	 * @param object|null   $product  WC_Product.
	 * @param array         $settings Settings (bags).
	 * @param string[]|null $fish     Lines::fish_categories() (null = ask).
	 * @return bool Whether it is packed in a bag.
	 */
	public static function for_product( $product, array $settings, $fish = null ) {
		if ( ! is_object( $product ) ) {
			return false;
		}

		$flag = self::stored( $product );
		if ( '' !== $flag ) {
			return self::YES === $flag;
		}

		$bags = isset( $settings['bags'] ) && is_array( $settings['bags'] ) ? $settings['bags'] : array();

		return self::decide( '', self::in_fish_category( $product, $fish ), null !== Bags::find( $bags, Bags::own_value( $product ) ) );
	}

	/**
	 * Why a line is ignored when its product says "not packed in a bag".
	 *
	 * @return string
	 */
	public static function unticked_reason() {
		return __( 'not packed in a bag — “Livestock — packed in a bag” is unticked on the product', 'fishotel-misc-plugin' );
	}
}
