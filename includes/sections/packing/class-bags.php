<?php
/**
 * Which bag a fish is packed in.
 *
 * The answer for a product or a variation (for_product()):
 *   1. its own Bag size — the hidden meta `_fh_bag_size` set in the product
 *      editor (a bag id). It overrides everything;
 *   2. else the size → bag map: the bag of the variation's size (the
 *      store's fish Size attribute), or of a simple product's only size;
 *   3. else none.
 *
 * For an order line (for_item()): the line's product as above; and when
 * the product gives nothing — an invoice line, an unlisted fish, a product
 * that was deleted — the size the LINE itself carries (WooCommerce copies
 * a variation's attributes onto the order line; the filter
 * fishotel_packing_item_size can name one), through the same map.
 *
 * Only what is packed in a bag has one: for_product() and for_item() —
 * the contracts — answer none for a product the Livestock rule says is not
 * bagged (the "Livestock — packed in a bag" checkbox of the product, else
 * the fish categories). A Bag size stored on such a product is kept and
 * does not count. product_bag() and item_bag() are the same answers
 * without that question, for a caller that has asked it already.
 *
 * The bag size is read through the WooCommerce product object, never
 * written here, and nothing in this file prints anything: it is a record.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bags
 */
class Bags {

	const SOURCE_OWN = 'own';
	const SOURCE_MAP = 'map';

	/**
	 * The bag with an id, from the list.
	 *
	 * @param array[] $bags Settings' bags.
	 * @param mixed   $id   Bag id.
	 * @return array|null
	 */
	public static function find( array $bags, $id ) {
		$id = is_scalar( $id ) && is_numeric( $id ) ? (int) $id : 0;
		if ( $id < 1 ) {
			return null;
		}
		foreach ( $bags as $bag ) {
			if ( (int) ( $bag['id'] ?? 0 ) === $id ) {
				return $bag;
			}
		}
		return null;
	}

	/**
	 * A bag's dimensions for a person: "6 × 12 in", "6 in wide", '' when
	 * none were typed.
	 *
	 * @param array $bag width, length.
	 * @return string
	 */
	public static function dims_text( array $bag ) {
		$width  = (string) ( $bag['width'] ?? '' );
		$length = (string) ( $bag['length'] ?? '' );
		if ( '' !== $width && '' !== $length ) {
			return $width . ' × ' . $length . ' in';
		}
		if ( '' !== $width ) {
			/* translators: %s: inches */
			return sprintf( __( '%s in wide', 'fishotel-misc-plugin' ), $width );
		}
		if ( '' !== $length ) {
			/* translators: %s: inches */
			return sprintf( __( '%s in long', 'fishotel-misc-plugin' ), $length );
		}
		return '';
	}

	/**
	 * A bag's name with its dimensions: "Medium (6 × 12 in)".
	 *
	 * @param array $bag Bag.
	 * @return string
	 */
	public static function label( array $bag ) {
		$dims = self::dims_text( $bag );
		return (string) ( $bag['name'] ?? '' ) . ( '' !== $dims ? ' (' . $dims . ')' : '' );
	}

	/**
	 * A bag as the contract functions hand it out.
	 *
	 * @param array  $bag    The bag of the list.
	 * @param string $source SOURCE_OWN | SOURCE_MAP.
	 * @param string $size   The size's slug ('' when not known).
	 * @param array  $sizes  The standard sizes, for the label.
	 * @return array{id: int, name: string, width: string, length: string, label: string, sort: int, source: string, size: string, size_label: string}
	 */
	public static function describe( array $bag, $source, $size = '', array $sizes = array() ) {
		return array(
			'id'         => (int) $bag['id'],
			'name'       => (string) $bag['name'],
			'width'      => (string) ( $bag['width'] ?? '' ),
			'length'     => (string) ( $bag['length'] ?? '' ),
			'label'      => self::label( $bag ),
			'sort'       => (int) ( $bag['sort'] ?? 0 ),
			'source'     => (string) $source,
			'size'       => (string) $size,
			'size_label' => '' !== (string) $size ? Size_List::label( $sizes, $size ) : '',
		);
	}

	/**
	 * The rule itself (pure): the own value when it names a bag of the
	 * list, else the map's bag for the size, else none. `stale` says the
	 * own value names a bag that is no longer in the list.
	 *
	 * @param mixed  $own      The stored `_fh_bag_size`.
	 * @param string $size     The size's slug ('' without one).
	 * @param array  $settings Settings (bags, size_map).
	 * @param array  $sizes    The standard sizes, for the label.
	 * @return array{bag: array|null, stale: bool}
	 */
	public static function resolve( $own, $size, array $settings, array $sizes = array() ) {
		$bags  = isset( $settings['bags'] ) && is_array( $settings['bags'] ) ? $settings['bags'] : array();
		$map   = isset( $settings['size_map'] ) && is_array( $settings['size_map'] ) ? $settings['size_map'] : array();
		$size  = (string) $size;
		$stale = false;

		if ( is_scalar( $own ) && ! is_bool( $own ) && '' !== trim( (string) $own ) ) {
			$bag = self::find( $bags, trim( (string) $own ) );
			if ( $bag ) {
				return array(
					'bag'   => self::describe( $bag, self::SOURCE_OWN, $size, $sizes ),
					'stale' => false,
				);
			}
			$stale = true;
		}

		if ( '' !== $size && isset( $map[ $size ] ) ) {
			$bag = self::find( $bags, $map[ $size ] );
			if ( $bag ) {
				return array(
					'bag'   => self::describe( $bag, self::SOURCE_MAP, $size, $sizes ),
					'stale' => $stale,
				);
			}
		}

		return array(
			'bag'   => null,
			'stale' => $stale,
		);
	}

	/**
	 * A product's own bag size as stored ('' without one).
	 *
	 * @param object $product WC_Product.
	 * @return string
	 */
	public static function own_value( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
			return '';
		}
		$value = $product->get_meta( Packing::META_BAG, true );
		return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The size a product carries: a variation's size attribute, or a
	 * simple product's size attribute when it has exactly one value.
	 *
	 * @param object $product  WC_Product.
	 * @param array  $standard Size_List::standard().
	 * @return string The size's slug, '' without one.
	 */
	public static function product_size( $product, array $standard ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return '';
		}

		$flat = array();
		foreach ( (array) $product->get_attributes() as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$flat[ (string) $key ] = (string) $value; // A variation: key → the chosen value.
				continue;
			}
			if ( ! is_object( $value ) ) {
				continue;
			}
			// A simple product: an attribute object with every value it has.
			$values = array();
			if ( method_exists( $value, 'is_taxonomy' ) && $value->is_taxonomy() && method_exists( $value, 'get_slugs' ) ) {
				$values = (array) $value->get_slugs();
			} elseif ( method_exists( $value, 'get_options' ) ) {
				$values = (array) $value->get_options();
			}
			$values = array_values( array_filter( $values, 'is_scalar' ) );
			if ( 1 === count( $values ) ) {
				$name          = method_exists( $value, 'get_name' ) ? (string) $value->get_name() : (string) $key;
				$flat[ $name ] = (string) $values[0];
			}
		}

		return Size_List::size_of( $flat, $standard );
	}

	/**
	 * The bag of a product or a variation — none when the product is not
	 * packed in a bag (Livestock::for_product()).
	 *
	 * @param mixed      $product  A product id or a WC_Product.
	 * @param array|null $settings Settings (null = the stored ones).
	 * @param array|null $standard Size_List::standard() (null = resolve it).
	 * @return array|null describe().
	 */
	public static function for_product( $product, $settings = null, $standard = null ) {
		if ( ! is_object( $product ) ) {
			$product = is_scalar( $product ) && (int) $product > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : null;
		}
		if ( ! is_object( $product ) ) {
			return null;
		}
		if ( ! is_array( $settings ) ) {
			$settings = Settings::get();
		}
		if ( ! $settings['bags'] || ! Livestock::for_product( $product, $settings ) ) {
			return null;
		}
		if ( ! is_array( $standard ) ) {
			$standard = Size_List::standard();
		}

		return self::product_bag( $product, $settings, $standard );
	}

	/**
	 * The bag a product or a variation gives — its own value, else the map
	 * — without asking whether it is packed in a bag at all: for a caller
	 * that has asked already (an order's lines), or whose fish is one by
	 * another word than the product's (a fish of an invoice).
	 *
	 * @param object $product  WC_Product.
	 * @param array  $settings Settings.
	 * @param array  $standard Size_List::standard().
	 * @return array|null describe().
	 */
	public static function product_bag( $product, array $settings, array $standard ) {
		if ( ! is_object( $product ) || empty( $settings['bags'] ) ) {
			return null;
		}

		$resolved = self::resolve( self::own_value( $product ), self::product_size( $product, $standard ), $settings, $standard['sizes'] );

		return $resolved['bag'];
	}

	/**
	 * The size an order line itself carries: the attribute meta WooCommerce
	 * copied from the variation, or any other of its meta whose key says
	 * "size" and whose value is a size of the list. The filter
	 * fishotel_packing_item_size has the last word.
	 *
	 * @param object $item     WC_Order_Item.
	 * @param array  $standard Size_List::standard().
	 * @return string The size's slug, '' without one.
	 */
	public static function item_size( $item, array $standard ) {
		$flat = array();
		if ( is_object( $item ) && method_exists( $item, 'get_meta_data' ) ) {
			foreach ( (array) $item->get_meta_data() as $meta ) {
				$key   = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
				$value = is_object( $meta ) && isset( $meta->value ) ? $meta->value : null;
				if ( '' !== $key && is_scalar( $value ) && ! isset( $flat[ $key ] ) ) {
					$flat[ $key ] = (string) $value;
				}
			}
		}

		$size     = Size_List::size_of( $flat, $standard );
		$filtered = apply_filters( 'fishotel_packing_item_size', $size, $item );

		return is_scalar( $filtered ) && ! is_bool( $filtered ) ? trim( (string) $filtered ) : $size;
	}

	/**
	 * The bag of one order line — none when the line has a product and
	 * that product makes it something that is not bagged (Lines::bagged():
	 * the same questions, in the same order, as the order's Packing box
	 * asks). A line with no product is answered from the size it carries,
	 * as before.
	 *
	 * @param object     $item     WC_Order_Item_Product.
	 * @param array|null $settings Settings (null = the stored ones).
	 * @param array|null $standard Size_List::standard() (null = resolve it).
	 * @return array|null describe().
	 */
	public static function for_item( $item, $settings = null, $standard = null ) {
		if ( ! is_object( $item ) ) {
			return null;
		}
		if ( ! is_array( $settings ) ) {
			$settings = Settings::get();
		}
		if ( ! $settings['bags'] || ! Lines::bagged( $item, $settings ) ) {
			return null;
		}
		if ( ! is_array( $standard ) ) {
			$standard = Size_List::standard();
		}

		return self::item_bag( $item, $settings, $standard );
	}

	/**
	 * The bag an order line gives — its product's, else the bag of the size
	 * the line carries — without asking whether the line is bagged at all
	 * (see product_bag()).
	 *
	 * @param object $item     WC_Order_Item_Product.
	 * @param array  $settings Settings.
	 * @param array  $standard Size_List::standard().
	 * @return array|null describe().
	 */
	public static function item_bag( $item, array $settings, array $standard ) {
		if ( ! is_object( $item ) || empty( $settings['bags'] ) ) {
			return null;
		}

		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		if ( is_object( $product ) ) {
			$bag = self::product_bag( $product, $settings, $standard );
			if ( $bag ) {
				return $bag;
			}
		}

		$resolved = self::resolve( '', self::item_size( $item, $standard ), $settings, $standard['sizes'] );

		return $resolved['bag'];
	}

	/**
	 * The bag the map gives a size.
	 *
	 * @param string     $size     A size slug, or its label.
	 * @param array|null $settings Settings (null = the stored ones).
	 * @param array|null $standard Size_List::standard() (null = resolve it).
	 * @return array|null describe().
	 */
	public static function for_size( $size, $settings = null, $standard = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = Settings::get();
		}
		if ( ! $settings['bags'] || ! $settings['size_map'] ) {
			return null;
		}
		if ( ! is_array( $standard ) ) {
			$standard = Size_List::standard();
		}

		$size = trim( (string) $size );
		$slug = isset( $settings['size_map'][ $size ] ) ? $size : Size_List::match_value( $size, $standard['sizes'] );
		if ( '' === $slug ) {
			return null;
		}

		$resolved = self::resolve( '', $slug, $settings, $standard['sizes'] );

		return $resolved['bag'];
	}

	/**
	 * How many fish go in one bag for a line — one, unless the filter
	 * fishotel_packing_fish_per_bag says more. This is the seam for a
	 * product-level "fish per bag" number later.
	 *
	 * @param object|null $product The line's product (null without one).
	 * @param object|null $item    The order line.
	 * @return int At least 1.
	 */
	public static function fish_per_bag( $product = null, $item = null ) {
		$per_bag = apply_filters( 'fishotel_packing_fish_per_bag', 1, $product, $item );
		return is_numeric( $per_bag ) && (int) $per_bag > 1 ? (int) $per_bag : 1;
	}
}
