<?php
/**
 * Sticker Giveaway designs — the WordPress / WooCommerce edge of "what is
 * a design and can it be handed out".
 *
 * A design is a simple product or ONE variation of a variable product;
 * its id is that product's own post id (Rules::design_id()). This class
 * turns an id into the things the picker, the cart and the settings page
 * need — the product, its name on the picker, its count — and gathers the
 * plain facts Rules::blockers() decides on.
 *
 * A design's COUNT is its WooCommerce stock and nothing else: the
 * variation's own, the variable product's when that keeps one stock
 * number for its variations, or the simple product's.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Designs
 */
class Designs {

	/**
	 * The product behind a design id: a simple product or a variation,
	 * as WooCommerce hands it out. Null for anything else.
	 *
	 * @param int $design_id Design id.
	 * @return \WC_Product|null
	 */
	public static function product( $design_id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $design_id ) : null;

		if ( ! $product instanceof \WC_Product || ! self::is_design_type( $product ) ) {
			return null;
		}

		return $product;
	}

	/**
	 * Whether a product is of a kind that can be a design.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function is_design_type( \WC_Product $product ) {
		return $product->is_type( 'simple' ) || $product->is_type( 'variation' );
	}

	/**
	 * The product a design belongs to: the variable product for a
	 * variation (its categories, its short description and its "sold
	 * individually" live there), else the product itself.
	 *
	 * @param \WC_Product $product Design product.
	 * @return int
	 */
	public static function parent_id( \WC_Product $product ) {
		$parent = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		return $parent > 0 ? $parent : (int) $product->get_id();
	}

	/**
	 * The keys of the attributes a variable product varies by, as a
	 * variation stores them (`attribute_style` for a custom attribute
	 * typed on the product, `attribute_pa_colour` for a global one).
	 *
	 * @param \WC_Product $parent The variable product.
	 * @return string[]
	 */
	public static function attribute_keys( \WC_Product $parent ) {
		$keys = array();

		foreach ( (array) $parent->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_variation' ) || ! $attribute->get_variation() ) {
				continue;
			}
			$keys[] = 'attribute_' . sanitize_title( $attribute->get_name() );
		}

		return $keys;
	}

	/**
	 * Whether a variation fixes every attribute its product varies by
	 * (none left on "Any").
	 *
	 * @param \WC_Product $variation Variation.
	 * @return bool
	 */
	public static function is_fixed( \WC_Product $variation ) {
		if ( ! $variation->is_type( 'variation' ) ) {
			return true;
		}

		// Asked on every totals pass for every sticker line: read the product once a request.
		static $known = array();
		$id           = (int) $variation->get_id();

		if ( ! isset( $known[ $id ] ) ) {
			$parent       = wc_get_product( $variation->get_parent_id() );
			$known[ $id ] = $parent instanceof \WC_Product && Rules::variation_is_fixed( self::attribute_keys( $parent ), (array) $variation->get_variation_attributes() );
		}

		return $known[ $id ];
	}

	/**
	 * A variation's attribute values as the customer should read them: a
	 * custom attribute's value exactly as the owner typed it ("Blue Tang"),
	 * a global attribute's term name rather than its slug.
	 *
	 * @param \WC_Product $variation Variation.
	 * @return string[]
	 */
	public static function labels( \WC_Product $variation ) {
		$labels = array();

		if ( ! $variation->is_type( 'variation' ) ) {
			return $labels;
		}

		foreach ( (array) $variation->get_variation_attributes() as $key => $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( '' === $value ) {
				continue;
			}

			$taxonomy = (string) preg_replace( '/^attribute_/', '', (string) $key );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term = get_term_by( 'slug', $value, $taxonomy );
				if ( $term instanceof \WP_Term && '' !== (string) $term->name ) {
					$value = (string) $term->name;
				}
			}

			$labels[] = rawurldecode( $value );
		}

		return $labels;
	}

	/**
	 * A design's name on the picker (Rules::design_name()).
	 *
	 * @param \WC_Product $product Design product.
	 * @return string
	 */
	public static function name( \WC_Product $product ) {
		return Rules::design_name( self::labels( $product ), wp_strip_all_tags( (string) $product->get_name() ) );
	}

	/**
	 * The count a design comes out of: the product that holds its stock
	 * number in WooCommerce — the design itself, or the variable product
	 * when that manages stock for its variations (they then share one
	 * count).
	 *
	 * @param \WC_Product $product Design product.
	 * @return int
	 */
	public static function count_id( \WC_Product $product ) {
		return (int) $product->get_stock_managed_by_id();
	}

	/**
	 * A product's stock number as WooCommerce has it, or null when
	 * WooCommerce does not track it ("Manage stock" off on the product, or
	 * stock management off for the whole shop). A variation whose product
	 * manages stock for it reads the product's.
	 *
	 * @param \WC_Product $product Product.
	 * @return int|null
	 */
	public static function stock( \WC_Product $product ) {
		return $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
	}

	/**
	 * The plain facts about a design that Rules::blockers() decides on.
	 *
	 * @param \WC_Product $product Design product (WooCommerce's, or the one on a cart line).
	 * @return array
	 */
	public static function facts( \WC_Product $product ) {
		$variation = $product->is_type( 'variation' );

		return array(
			'type'             => $variation ? 'variation' : ( $product->is_type( 'simple' ) ? 'simple' : 'other' ),
			'published'        => 'publish' === $product->get_status(),
			'parent_published' => ! $variation || 'publish' === get_post_status( $product->get_parent_id() ),
			'has_price'        => '' !== (string) $product->get_price(),
			'purchasable'      => (bool) $product->is_purchasable(),
			'fixed'            => self::is_fixed( $product ),
			'in_stock'         => (bool) $product->is_in_stock(),
			'stock'            => self::stock( $product ),
		);
	}

	/**
	 * Why a design cannot be handed out right now (Rules::blockers());
	 * empty when it can.
	 *
	 * @param \WC_Product $product Design product.
	 * @return string[]
	 */
	public static function blockers( \WC_Product $product ) {
		return Rules::blockers( self::facts( $product ) );
	}

	/**
	 * Whether a design can be handed out right now. The same check gates
	 * the picker, the adding of a line and every totals pass, so a line
	 * that passes here is one the picker would produce today.
	 *
	 * @param \WC_Product $product Design product.
	 * @return bool
	 */
	public static function can_hand_out( \WC_Product $product ) {
		return array() === self::blockers( $product );
	}
}
