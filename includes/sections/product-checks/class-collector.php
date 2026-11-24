<?php
/**
 * Product checks — the facts of one product, as the rules read them
 * (Rules::flags()). Reads only: the product through WooCommerce, its own
 * categories, a few post meta, the theme's default texts and old-style
 * answer when the theme is there, and Packing's own bag lookup when that
 * section is on.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Collector
 */
class Collector {

	/**
	 * The placeholder images (the theme's two libraries, WooCommerce's
	 * placeholder) — read once per request.
	 *
	 * @var int[]|null
	 */
	private static $placeholders = null;

	/**
	 * The site's default foods / habitat texts — read once per request.
	 *
	 * @var array|null
	 */
	private static $defaults = null;

	/**
	 * Forget what was read once (the tests, a sweep after a setting).
	 */
	public static function reset() {
		self::$placeholders = null;
		self::$defaults     = null;
	}

	/**
	 * The placeholder images.
	 *
	 * @return int[]
	 */
	public static function placeholders() {
		if ( null === self::$placeholders ) {
			$ids = array();
			foreach ( array( 'fh_placeholder_library', 'fh_cs_placeholder_library' ) as $key ) {
				$list = get_option( $key, array() );
				foreach ( is_array( $list ) ? $list : array() as $id ) {
					$ids[] = (int) $id;
				}
			}
			$ids[]              = (int) get_option( 'woocommerce_placeholder_image', 0 );
			self::$placeholders = array_values( array_unique( array_filter( $ids ) ) );
		}
		return self::$placeholders;
	}

	/**
	 * The site's default foods & feeding and habitat texts (the theme's
	 * settings; none without the theme).
	 *
	 * @return array{foods_feeding: string, habitat: string}
	 */
	public static function defaults() {
		if ( null === self::$defaults ) {
			$get            = static function ( $key ) {
				if ( class_exists( 'FisHotel_Admin_Settings' ) && is_callable( array( 'FisHotel_Admin_Settings', 'get' ) ) ) {
					try {
						return (string) \FisHotel_Admin_Settings::get( $key );
					} catch ( \Throwable $e ) {
						return '';
					}
				}
				return '';
			};
			self::$defaults = array(
				'foods_feeding' => $get( 'fh_default_foods' ),
				'habitat'       => $get( 'fh_default_habitat' ),
			);
		}
		return self::$defaults;
	}

	/**
	 * Whether the Packing section is on (its bag lookup answers).
	 *
	 * @return bool
	 */
	public static function packing_on() {
		return class_exists( '\FisHotel\Misc\Sections\Packing\Packing' ) && \FisHotel\Misc\Sections\Packing\Packing::enabled() && function_exists( 'fishotel_packing_bag_for_product' );
	}

	/**
	 * The facts of one product, or null when it is not a product (a
	 * variation, gone).
	 *
	 * @param int   $id     Product ID.
	 * @param int[] $shared Images featured on 3 or more products.
	 * @return array|null
	 */
	public static function facts( $id, array $shared = array() ) {
		$id      = (int) $id;
		$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! is_object( $product ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
			return null;
		}

		$cats  = array();
		$under = false;
		$qf    = function_exists( 'get_term_by' ) ? get_term_by( 'slug', Rules::TABLE_CAT, 'product_cat' ) : null;
		$terms = get_the_terms( $id, 'product_cat' );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->slug ) ) {
				continue;
			}
			$cats[] = strtolower( (string) $term->slug );
			if ( is_object( $qf ) && isset( $qf->term_id ) && (int) $term->term_id !== (int) $qf->term_id && function_exists( 'term_is_ancestor_of' ) && term_is_ancestor_of( (int) $qf->term_id, (int) $term->term_id, 'product_cat' ) ) {
				$under = true;
			}
		}

		$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
		$file  = '';
		if ( $thumb > 0 ) {
			if ( 'attachment' !== get_post_type( $thumb ) ) {
				$thumb = -1; // Points at an image that is gone.
			} else {
				$file = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $thumb ) : '';
			}
		}

		$facts = array();
		foreach ( array_merge( Rules::TABLE_FIELDS, array( 'region' ) ) as $field ) {
			$facts[ $field ] = (string) get_post_meta( $id, '_fh_' . $field, true );
		}

		$old = null;
		if ( function_exists( 'fishotel_fish_facts_old_style' ) ) {
			try {
				$old = (bool) fishotel_fish_facts_old_style( $id ); // The theme's own answer (1.28+), so the list and its facts box agree.
			} catch ( \Throwable $e ) {
				$old = null;
			}
		}

		list( $price, $bag ) = self::sizes( $product );

		return array(
			'id'              => $id,
			'status'          => method_exists( $product, 'get_status' ) ? (string) $product->get_status() : '',
			'cats'            => $cats,
			'under_table_cat' => $under,
			'packs'           => (string) get_post_meta( $id, '_fh_packs_in_bag', true ),
			'thumb'           => $thumb,
			'thumb_standin'   => Rules::standin( $thumb, self::placeholders(), $shared, $file ),
			'gallery'         => (string) get_post_meta( $id, '_product_image_gallery', true ),
			'text'            => method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '',
			'excerpt'         => method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '',
			'facts'           => $facts,
			'defaults'        => self::defaults(),
			'old_style'       => $old,
			'price_missing'   => $price,
			'bag_missing'     => self::packing_on() ? $bag : null,
		);
	}

	/**
	 * The in-stock sizes with no regular price, and those with no bag: ['']
	 * for a simple product; size names for the enabled variations.
	 *
	 * @param object $product WC_Product.
	 * @return array{0: string[], 1: string[]}
	 */
	private static function sizes( $product ) {
		$price = array();
		$bag   = array();
		$items = array();
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( ! is_object( $child ) || ( method_exists( $child, 'get_status' ) && 'publish' !== $child->get_status() ) ) {
					continue; // Not enabled.
				}
				$items[] = array( $child, self::size_name( $child ) );
			}
		} else {
			$items[] = array( $product, '' );
		}
		foreach ( $items as $item ) {
			list( $one, $name ) = $item;
			if ( method_exists( $one, 'is_in_stock' ) && ! $one->is_in_stock() ) {
				continue;
			}
			if ( method_exists( $one, 'get_regular_price' ) && ! Rules::filled( $one->get_regular_price() ) ) {
				$price[] = $name;
			}
			if ( self::packing_on() && null === fishotel_packing_bag_for_product( $one ) ) {
				$bag[] = $name;
			}
		}
		return array( $price, $bag );
	}

	/**
	 * A variation's size, in words ("Large"), else its number.
	 *
	 * @param object $variation WC_Product_Variation.
	 * @return string
	 */
	private static function size_name( $variation ) {
		$names = array();
		if ( method_exists( $variation, 'get_attributes' ) ) {
			foreach ( (array) $variation->get_attributes() as $taxonomy => $value ) {
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( '' === $value ) {
					continue;
				}
				$term    = 0 === strpos( (string) $taxonomy, 'pa_' ) && function_exists( 'get_term_by' ) ? get_term_by( 'slug', $value, (string) $taxonomy ) : null;
				$names[] = is_object( $term ) && isset( $term->name ) ? (string) $term->name : $value;
			}
		}
		return $names ? implode( ' / ', $names ) : '#' . ( method_exists( $variation, 'get_id' ) ? (int) $variation->get_id() : 0 );
	}
}
