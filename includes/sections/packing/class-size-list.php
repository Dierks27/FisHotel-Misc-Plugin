<?php
/**
 * The fish sizes the size → bag map is keyed by — the SAME list the
 * product variations and the Clean Room use, so a size means one thing
 * everywhere.
 *
 * Where it comes from (standard()):
 *   - the Clean Room section, when it is switched on: its Sizes class
 *     resolves the list (the product attribute whose name or slug contains
 *     "size", the one its Settings → Sizes picked when several match, or
 *     the plain list typed there while no such attribute exists). It is
 *     reached through class_exists() / method_exists() only;
 *   - otherwise this section finds the attribute itself: the first global
 *     product attribute whose name or label contains "size" (the filter
 *     fishotel_packing_size_attribute may name another), its terms in the
 *     order the attribute is set to;
 *   - with neither, there is no list — the screens say so, and a bag size
 *     can still be set on each variation by hand.
 *
 * The list is read once per request (memoised) and only when one of this
 * section's admin screens or contract functions asks.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Size_List
 */
class Size_List {

	/**
	 * Who resolved the list.
	 */
	const SOURCE_CLEAN_ROOM = 'clean-room';
	const SOURCE_ATTRIBUTE  = 'attribute';
	const SOURCE_NONE       = 'none';

	/**
	 * What the list is made of: the terms of a product attribute, or a
	 * plain typed list (the Clean Room's, while no attribute exists).
	 */
	const KIND_ATTRIBUTE = 'attribute';
	const KIND_LIST      = 'list';
	const KIND_NONE      = 'none';

	const MAX_LABEL = 100;

	/**
	 * The list of this request.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Whether an attribute name, slug or variation key is a size (the
	 * theme's rule: the key matches /size/).
	 *
	 * @param mixed $key Attribute name / slug / key.
	 * @return bool
	 */
	public static function matches( $key ) {
		return is_scalar( $key ) && 1 === preg_match( '/size/i', (string) $key );
	}

	/**
	 * The slug of a size label ('2 - 3.5"' → "2-3-5"), as a term slug
	 * would be.
	 *
	 * @param mixed $label Label.
	 * @return string '' when nothing is left.
	 */
	public static function slug( $label ) {
		if ( ! is_scalar( $label ) || is_bool( $label ) ) {
			return '';
		}
		$slug = strtolower( trim( (string) $label ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return substr( trim( $slug, '-' ), 0, self::MAX_LABEL );
	}

	/**
	 * An attribute's terms in the order the attribute is set to — the
	 * custom (drag-and-drop) order, the name, the name read as a number, or
	 * the term id.
	 *
	 * @param array[] $terms   term_id, name, slug, menu_order.
	 * @param string  $orderby menu_order|name|name_num|id.
	 * @return array[]
	 */
	public static function order_terms( array $terms, $orderby = 'menu_order' ) {
		$terms   = array_values( array_filter( $terms, 'is_array' ) );
		$orderby = (string) $orderby;

		usort(
			$terms,
			static function ( $a, $b ) use ( $orderby ) {
				$by_name = strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
				$by_id   = (int) ( $a['term_id'] ?? 0 ) <=> (int) ( $b['term_id'] ?? 0 );
				if ( 'id' === $orderby ) {
					return $by_id;
				}
				if ( 'name' === $orderby ) {
					return 0 !== $by_name ? $by_name : $by_id;
				}
				if ( 'name_num' === $orderby ) {
					$numeric = (float) ( $a['name'] ?? 0 ) <=> (float) ( $b['name'] ?? 0 );
					return 0 !== $numeric ? $numeric : ( 0 !== $by_name ? $by_name : $by_id );
				}
				$order = (int) ( $a['menu_order'] ?? 0 ) <=> (int) ( $b['menu_order'] ?? 0 );
				return 0 !== $order ? $order : ( 0 !== $by_name ? $by_name : $by_id );
			}
		);

		return $terms;
	}

	/**
	 * The list from an attribute and its terms (pure).
	 *
	 * @param array[] $attributes The attributes that match "size": taxonomy, label, orderby.
	 * @param array[] $terms      The terms of the attribute in use.
	 * @param string  $chosen     A taxonomy to prefer ('' = the first).
	 * @return array{source: string, kind: string, attribute: string, label: string, sizes: array[]}
	 */
	public static function from_attribute( array $attributes, array $terms, $chosen = '' ) {
		$attribute = self::pick_attribute( $attributes, $chosen );
		if ( null === $attribute ) {
			return self::none();
		}

		$sizes = array();
		$seen  = array();
		foreach ( self::order_terms( $terms, (string) ( $attribute['orderby'] ?? 'menu_order' ) ) as $term ) {
			$slug  = trim( (string) ( $term['slug'] ?? '' ) );
			$label = trim( (string) ( $term['name'] ?? '' ) );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$sizes[]       = array(
				'slug'  => substr( $slug, 0, self::MAX_LABEL ),
				'label' => substr( '' !== $label ? $label : $slug, 0, self::MAX_LABEL ),
			);
		}

		return array(
			'source'    => self::SOURCE_ATTRIBUTE,
			'kind'      => self::KIND_ATTRIBUTE,
			'attribute' => (string) $attribute['taxonomy'],
			'label'     => (string) ( $attribute['label'] ?? $attribute['taxonomy'] ),
			'sizes'     => $sizes,
		);
	}

	/**
	 * The attribute in use: the preferred one when it is among the
	 * matches, else the first match, else none.
	 *
	 * @param array[] $attributes The attributes that match "size".
	 * @param string  $chosen     A taxonomy to prefer.
	 * @return array|null
	 */
	public static function pick_attribute( array $attributes, $chosen = '' ) {
		$chosen = (string) $chosen;
		$first  = null;
		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) || '' === (string) ( $attribute['taxonomy'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $chosen && (string) $attribute['taxonomy'] === $chosen ) {
				return $attribute;
			}
			if ( null === $first ) {
				$first = $attribute;
			}
		}
		return $first;
	}

	/**
	 * No list at all.
	 *
	 * @return array{source: string, kind: string, attribute: string, label: string, sizes: array[]}
	 */
	public static function none() {
		return array(
			'source'    => self::SOURCE_NONE,
			'kind'      => self::KIND_NONE,
			'attribute' => '',
			'label'     => '',
			'sizes'     => array(),
		);
	}

	/**
	 * What the Clean Room's Sizes::standard() answered, in this section's
	 * shape (pure). Null when the answer is not usable.
	 *
	 * @param mixed $answer Sizes::standard().
	 * @return array|null
	 */
	public static function from_clean_room( $answer ) {
		if ( ! is_array( $answer ) || ! isset( $answer['sizes'] ) || ! is_array( $answer['sizes'] ) ) {
			return null;
		}

		$sizes = array();
		$seen  = array();
		foreach ( $answer['sizes'] as $size ) {
			$slug = is_array( $size ) && isset( $size['slug'] ) && is_scalar( $size['slug'] ) ? trim( (string) $size['slug'] ) : '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$label         = isset( $size['label'] ) && is_scalar( $size['label'] ) && '' !== trim( (string) $size['label'] ) ? trim( (string) $size['label'] ) : $slug;
			$sizes[]       = array(
				'slug'  => substr( $slug, 0, self::MAX_LABEL ),
				'label' => substr( $label, 0, self::MAX_LABEL ),
			);
		}

		$attribute = isset( $answer['attribute'] ) && is_scalar( $answer['attribute'] ) ? (string) $answer['attribute'] : '';

		return array(
			'source'    => self::SOURCE_CLEAN_ROOM,
			'kind'      => '' !== $attribute ? self::KIND_ATTRIBUTE : self::KIND_LIST,
			'attribute' => $attribute,
			'label'     => isset( $answer['label'] ) && is_scalar( $answer['label'] ) ? (string) $answer['label'] : '',
			'sizes'     => $sizes,
		);
	}

	/**
	 * The list of sizes — from the Clean Room when it is there, else found
	 * here. Memoised for the request.
	 *
	 * @param object|null $store This section's Store (or the tests' stand-in); null = a new Store.
	 * @return array{source: string, kind: string, attribute: string, label: string, sizes: array[]}
	 */
	public static function standard( $store = null ) {
		if ( null !== self::$memo && null === $store ) {
			return self::$memo;
		}

		$list = self::ask_clean_room();

		if ( null === $list ) {
			if ( null === $store ) {
				$own = new Store();
			} else {
				$own = $store;
			}
			$attributes = is_object( $own ) && method_exists( $own, 'size_attributes' ) ? (array) $own->size_attributes() : array();
			$chosen     = (string) apply_filters( 'fishotel_packing_size_attribute', '', $attributes );
			$attribute  = self::pick_attribute( $attributes, $chosen );
			$terms      = null !== $attribute && method_exists( $own, 'size_terms' ) ? (array) $own->size_terms( (string) $attribute['taxonomy'] ) : array();
			$list       = self::from_attribute( $attributes, $terms, $chosen );
		}

		if ( null === $store ) {
			self::$memo = $list;
		}

		return $list;
	}

	/**
	 * Forget the memoised list (the tests; a save that may change it).
	 */
	public static function reset() {
		self::$memo = null;
	}

	/**
	 * The Clean Room's list, when that section is loaded — reached through
	 * class_exists() / method_exists() only, and never trusted to not throw.
	 *
	 * @return array|null
	 */
	private static function ask_clean_room() {
		$sizes    = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Sizes';
		$store    = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Store';
		$settings = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Settings';

		if ( ! class_exists( $sizes, false ) || ! class_exists( $store, false ) || ! class_exists( $settings, false ) ) {
			return null;
		}
		if ( ! method_exists( $sizes, 'standard' ) || ! method_exists( $settings, 'get' ) ) {
			return null;
		}

		try {
			$answer = $sizes::standard( new $store(), (array) $settings::get() );
		} catch ( \Throwable $e ) {
			return null;
		}

		return self::from_clean_room( $answer );
	}

	/**
	 * The size with a slug, from a list.
	 *
	 * @param array[] $sizes Sizes (slug, label).
	 * @param mixed   $slug  Slug.
	 * @return array|null slug, label.
	 */
	public static function find( array $sizes, $slug ) {
		$slug = is_scalar( $slug ) && ! is_bool( $slug ) ? (string) $slug : '';
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && (string) ( $size['slug'] ?? '' ) === $slug ) {
				return array(
					'slug'  => $slug,
					'label' => (string) ( $size['label'] ?? $slug ),
				);
			}
		}
		return null;
	}

	/**
	 * The label of a size slug: the list's, else the slug itself.
	 *
	 * @param array[] $sizes Sizes.
	 * @param mixed   $slug  Slug.
	 * @return string
	 */
	public static function label( array $sizes, $slug ) {
		$found = self::find( $sizes, $slug );
		return $found ? $found['label'] : ( is_scalar( $slug ) ? (string) $slug : '' );
	}

	/**
	 * The standard size a value means: a slug of the list, a label of the
	 * list (whatever the case), or a text that reads like one ("2-3 in"
	 * for the slug "2-3-in"). '' when it means none.
	 *
	 * @param mixed   $value A slug or a label.
	 * @param array[] $sizes Sizes.
	 * @return string The slug.
	 */
	public static function match_value( $value, array $sizes ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( self::find( $sizes, $value ) ) {
			return $value;
		}

		$lower = strtolower( $value );
		$slug  = self::slug( $value );
		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$candidate = (string) ( $size['slug'] ?? '' );
			if ( '' === $candidate ) {
				continue;
			}
			if ( strtolower( trim( (string) ( $size['label'] ?? '' ) ) ) === $lower ) {
				return $candidate;
			}
			if ( '' !== $slug && ( $candidate === $slug || self::slug( (string) ( $size['label'] ?? '' ) ) === $slug ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The size a set of attributes carries (pure) — a variation's
	 * attributes, or the attribute meta of an order line. A value under
	 * the attribute the list is made of is a term slug and is taken as it
	 * is; a value under any other key that matches "size" (an attribute
	 * typed on the product, "Size") counts when it means a size of the
	 * list.
	 *
	 * @param array $attributes Key → value; a key may carry the "attribute_" prefix.
	 * @param array $standard   standard().
	 * @return string The size's slug, '' without one.
	 */
	public static function size_of( array $attributes, array $standard ) {
		$taxonomy = (string) ( $standard['attribute'] ?? '' );
		$sizes    = isset( $standard['sizes'] ) && is_array( $standard['sizes'] ) ? $standard['sizes'] : array();
		$other    = '';

		foreach ( $attributes as $key => $value ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				continue;
			}
			$key   = (string) $key;
			$value = trim( (string) $value );
			if ( 0 === strpos( $key, 'attribute_' ) ) {
				$key = substr( $key, strlen( 'attribute_' ) );
			}
			if ( '' === $value || ! self::matches( $key ) ) {
				continue;
			}
			if ( '' !== $taxonomy && $key === $taxonomy ) {
				return substr( $value, 0, self::MAX_LABEL );
			}
			if ( '' === $other ) {
				$other = self::match_value( $value, $sizes );
			}
		}

		return $other;
	}
}
