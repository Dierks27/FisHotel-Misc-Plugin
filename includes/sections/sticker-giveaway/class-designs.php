<?php
/**
 * Sticker Giveaway designs — the WordPress / WooCommerce edge of "what is
 * a design and can it be handed out".
 *
 * A design is a simple product or ONE variation of a variable product;
 * its id is that product's own post id (Rules::design_id()). This class
 * turns an id into the things the picker, the cart and the settings page
 * need — the product, its name on the picker, its picture, its shop pile,
 * its giveaway pile — and gathers the plain facts Rules::blockers() decides
 * on. The deciding itself is in class-rules.php and class-piles.php.
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
	 * The store, one per request.
	 *
	 * @var Store|null
	 */
	private static $store = null;

	/**
	 * The store behind the piles.
	 *
	 * @return Store
	 */
	public static function store() {
		if ( null === self::$store ) {
			require_once Sticker_Giveaway::path() . 'class-store.php';
			self::$store = new Store();
		}

		return self::$store;
	}

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

		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent instanceof \WC_Product ) {
			return false;
		}

		return Rules::variation_is_fixed( self::attribute_keys( $parent ), (array) $variation->get_variation_attributes() );
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
	 * The shop pile: the product's own WooCommerce stock, or null when it
	 * does not manage stock (a variation that takes its stock from its
	 * product reads the product's).
	 *
	 * @param \WC_Product $product Design product, as WooCommerce hands it out.
	 * @return int|null
	 */
	public static function shop_stock( \WC_Product $product ) {
		if ( self::is_pile_product( $product ) ) {
			$product = self::product( $product->get_id() );
		}

		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}

		return (int) $product->get_stock_quantity();
	}

	/**
	 * A design's giveaway pile (an empty one when nothing was counted
	 * yet). Reads every pile of the pool in one query the first time.
	 *
	 * @param int   $design_id Design id.
	 * @param array $settings  Settings.
	 * @return array{paper: int, basis: int, alerted: bool}
	 */
	public static function pile( $design_id, array $settings ) {
		$design_id = (int) $design_id;
		$ids       = Rules::ids( (array) ( $settings['pile_ids'] ?? array() ) );

		if ( ! in_array( $design_id, $ids, true ) ) {
			$ids[] = $design_id;
		}

		$piles = self::store()->piles( $ids );

		return $piles[ $design_id ] ?? Piles::pile( null );
	}

	/**
	 * How many stickers of a design its giveaway pile can still hand out;
	 * null when the design keeps no giveaway pile.
	 *
	 * @param int   $design_id Design id.
	 * @param array $settings  Settings.
	 * @return int|null
	 */
	public static function available( $design_id, array $settings ) {
		if ( ! Rules::keeps_pile( $design_id, $settings ) ) {
			return null;
		}

		$pile = self::pile( $design_id, $settings );

		return Piles::really_there( $pile['paper'], $pile['basis'], Piles::loss_percent( $settings ) );
	}

	/**
	 * The plain facts about a design that Rules::blockers() decides on.
	 *
	 * @param \WC_Product $product  Design product (WooCommerce's, or the one on a cart line).
	 * @param array       $settings Settings.
	 * @return array
	 */
	public static function facts( \WC_Product $product, array $settings ) {
		$id         = (int) $product->get_id();
		$keeps_pile = Rules::keeps_pile( $id, $settings );
		$pile       = $keeps_pile ? self::pile( $id, $settings ) : Piles::pile( null );
		$variation  = $product->is_type( 'variation' );

		return array(
			'type'             => $variation ? 'variation' : ( $product->is_type( 'simple' ) ? 'simple' : 'other' ),
			'published'        => 'publish' === $product->get_status(),
			'parent_published' => ! $variation || 'publish' === get_post_status( $product->get_parent_id() ),
			'has_price'        => '' !== (string) $product->get_price(),
			'purchasable'      => (bool) $product->is_purchasable(),
			'fixed'            => self::is_fixed( $product ),
			'in_stock'         => (bool) $product->is_in_stock(),
			'keeps_pile'       => $keeps_pile,
			'paper'            => $pile['paper'],
			'basis'            => $pile['basis'],
		);
	}

	/**
	 * Why a design cannot be handed out right now (Rules::blockers());
	 * empty when it can.
	 *
	 * @param \WC_Product $product  Design product.
	 * @param array       $settings Settings.
	 * @return string[]
	 */
	public static function blockers( \WC_Product $product, array $settings ) {
		return Rules::blockers( self::facts( $product, $settings ), Piles::loss_percent( $settings ) );
	}

	/**
	 * Whether a design can be handed out right now. The same check gates
	 * the picker, the adding of a line and every totals pass, so a line
	 * that passes here is one the picker would produce today.
	 *
	 * @param \WC_Product $product  Design product.
	 * @param array       $settings Settings.
	 * @return bool
	 */
	public static function can_hand_out( \WC_Product $product, array $settings ) {
		return array() === self::blockers( $product, $settings );
	}

	/* ============================================================== */
	/*  Pile products                                                  */
	/* ============================================================== */

	/**
	 * Whether a product object is one of the giveaway-line objects
	 * (class-pile-products.php).
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	public static function is_pile_product( $product ) {
		return is_object( $product ) && method_exists( $product, 'is_giveaway_pile_line' );
	}

	/**
	 * The giveaway-line object for a design: the same product, read fresh,
	 * that tells WooCommerce it is in stock and takes nobody's stock (see
	 * class-pile-products.php for why). Null when it cannot be built — the
	 * caller then keeps WooCommerce's own object.
	 *
	 * @param \WC_Product|int $product Design product or id.
	 * @return \WC_Product|null
	 */
	public static function pile_product( $product ) {
		if ( self::is_pile_product( $product ) ) {
			return $product;
		}

		if ( ! $product instanceof \WC_Product ) {
			$product = self::product( (int) $product );
		}

		if ( ! $product instanceof \WC_Product || ! class_exists( '\WC_Product_Variation' ) || ! class_exists( '\WC_Product_Simple' ) ) {
			return null;
		}

		require_once Sticker_Giveaway::path() . 'class-pile-products.php';

		try {
			if ( $product->is_type( 'variation' ) ) {
				return new Pile_Variation( $product->get_id() );
			}
			if ( $product->is_type( 'simple' ) ) {
				return new Pile_Simple( $product->get_id() );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return null;
	}
}
