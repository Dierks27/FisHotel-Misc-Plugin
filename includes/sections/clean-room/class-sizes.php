<?php
/**
 * Clean Room sizes — PURE: the ONE list of fish sizes a supplier order
 * line and a stay are sized with, which is the list the WooCommerce
 * product variations use, so everything matches.
 *
 * Where the list comes from (resolve()):
 *   - the global product attribute whose name or slug contains "size"
 *     (the theme reads any attribute key matching /size/ as the size —
 *     e.g. pa_size with term slugs like "2-3-5"): its terms, in the order
 *     the attribute is set to (custom ordering, name, numeric name or
 *     term id), labelled as stored — the labels are in inches;
 *   - when several attributes match, the one Settings → Sizes picked
 *     (settings key size_attribute), else the first;
 *   - when none exists, the plain list typed in Settings → Sizes
 *     (settings key size_list), so the feature works without the
 *     attribute — and headless.
 *
 * What is stored on a line / a stay is the size's slug plus a snapshot of
 * its label (size_slug, size_label). A stored size that is no longer in
 * the list still displays, marked "not a standard size", and stays
 * selectable on the row that has it; a slug that is neither in the list
 * nor the row's own is refused (pick()).
 *
 * The readers live in the Store (size_attributes(), size_terms(),
 * variation_size_values()); standard() is the one call that puts them
 * together, and it is only ever made from the section's own admin
 * screens and the Front Desk — never on an ordinary front-end request.
 * report() is the read-only "Sizes in use on products" list: nothing in
 * this file, or anywhere in the section, changes a product.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sizes
 */
class Sizes {

	/**
	 * Where the standard list comes from.
	 */
	const SOURCE_ATTRIBUTE = 'attribute';
	const SOURCE_LIST      = 'list';

	const MAX_SIZES = 60;
	const MAX_LABEL = 100;

	/**
	 * Whether an attribute name, slug or variation key is a size (the
	 * theme's rule: the key matches /size/).
	 *
	 * @param string $key Attribute name / slug / key.
	 * @return bool
	 */
	public static function matches( $key ) {
		return 1 === preg_match( '/size/i', (string) $key );
	}

	/**
	 * The slug of a typed size label ('2 - 3.5"' → "2-3-5"), as a term
	 * slug would be: lower-cased, every run of other characters a dash.
	 *
	 * @param string $label Label.
	 * @return string '' when nothing is left.
	 */
	public static function slug( $label ) {
		$slug = strtolower( trim( (string) $label ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return substr( trim( $slug, '-' ), 0, self::MAX_LABEL );
	}

	/**
	 * The labels of a typed list — lines of a textarea or an array — as
	 * plain text: control characters dropped, trimmed, empty and repeated
	 * ones (by slug) dropped, at most MAX_SIZES. A label keeps its inch
	 * mark; it is escaped wherever it is shown.
	 *
	 * @param mixed $raw A string (one size per line) or a list of labels.
	 * @return string[]
	 */
	public static function labels( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $label ) {
			if ( ! is_scalar( $label ) || is_bool( $label ) ) {
				continue;
			}
			$label = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $label );
			$label = substr( trim( (string) preg_replace( '/\s+/', ' ', $label ) ), 0, self::MAX_LABEL );
			$slug  = self::slug( $label );
			if ( '' === $label || '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = $label;
			if ( count( $out ) >= self::MAX_SIZES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A list of labels as sizes (slug + label).
	 *
	 * @param mixed $raw labels().
	 * @return array[] slug, label.
	 */
	public static function from_labels( $raw ) {
		$out = array();
		foreach ( self::labels( $raw ) as $label ) {
			$out[] = array(
				'slug'  => self::slug( $label ),
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * An attribute's terms in the order the attribute is set to — what
	 * WooCommerce shows on the product: the custom (drag-and-drop) order,
	 * the name, the name read as a number, or the term id.
	 *
	 * @param array[] $terms   term_id, name, slug, menu_order.
	 * @param string  $orderby menu_order|name|name_num|id.
	 * @return array[]
	 */
	public static function order_terms( array $terms, $orderby = 'menu_order' ) {
		$terms   = array_values( $terms );
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
	 * The attribute in use: the one Settings picked when it is still
	 * among the matches, else the first match, else none.
	 *
	 * @param array[] $attributes Store::size_attributes().
	 * @param string  $chosen     The settings' size_attribute (a taxonomy).
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
	 * The standard list from what the Store read (pure): the chosen
	 * attribute's terms in their stored order, else the settings' plain
	 * list.
	 *
	 * @param array[] $attributes Store::size_attributes() — the attributes that match "size".
	 * @param array[] $terms      Store::size_terms() of the attribute in use.
	 * @param array   $settings   Settings (size_attribute, size_list).
	 * @return array{source: string, attribute: string, label: string, attributes: array[], sizes: array[]}
	 */
	public static function resolve( array $attributes, array $terms, array $settings ) {
		$attributes = array_values(
			array_filter(
				$attributes,
				static function ( $attribute ) {
					return is_array( $attribute ) && '' !== (string) ( $attribute['taxonomy'] ?? '' );
				}
			)
		);
		$attribute  = self::pick_attribute( $attributes, (string) ( $settings['size_attribute'] ?? '' ) );

		if ( null === $attribute ) {
			return array(
				'source'     => self::SOURCE_LIST,
				'attribute'  => '',
				'label'      => '',
				'attributes' => array(),
				'sizes'      => self::from_labels( $settings['size_list'] ?? array() ),
			);
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
			'source'     => self::SOURCE_ATTRIBUTE,
			'attribute'  => (string) $attribute['taxonomy'],
			'label'      => (string) ( $attribute['label'] ?? $attribute['taxonomy'] ),
			'attributes' => $attributes,
			'sizes'      => $sizes,
		);
	}

	/**
	 * The standard list, read through a store (the real Store or the
	 * tests' stand-in) — the only place the attribute and its terms are
	 * read. Call it from a Clean Room admin screen, an admin AJAX handler
	 * or the Front Desk only.
	 *
	 * @param object $store    Store with size_attributes() and size_terms().
	 * @param array  $settings Settings (size_attribute, size_list).
	 * @return array resolve().
	 */
	public static function standard( $store, array $settings ) {
		$attributes = is_object( $store ) && method_exists( $store, 'size_attributes' ) ? (array) $store->size_attributes() : array();
		$attribute  = self::pick_attribute( $attributes, (string) ( $settings['size_attribute'] ?? '' ) );
		$terms      = null !== $attribute && method_exists( $store, 'size_terms' ) ? (array) $store->size_terms( (string) $attribute['taxonomy'] ) : array();

		return self::resolve( $attributes, $terms, $settings );
	}

	/**
	 * The size with a slug, from a list.
	 *
	 * @param array[] $sizes Sizes (slug, label).
	 * @param string  $slug  Slug.
	 * @return array|null
	 */
	public static function find( array $sizes, $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $sizes as $size ) {
			if ( (string) ( $size['slug'] ?? '' ) === $slug ) {
				return array(
					'slug'  => $slug,
					'label' => (string) ( $size['label'] ?? $slug ),
				);
			}
		}
		return null;
	}

	/**
	 * The size a form picked, as it is stored: nothing ('' → no size), a
	 * size of the list (its slug with the label as it reads now), or the
	 * row's own stored size when it is no longer in the list (kept as it
	 * was). Any other slug — and anything that is not text — is refused:
	 * ok is false and nothing should be saved for it.
	 *
	 * @param mixed   $slug    The posted slug.
	 * @param array[] $sizes   The standard list.
	 * @param array   $current The row as stored (size_slug, size_label), when it exists.
	 * @return array{ok: bool, slug: string|null, label: string|null}
	 */
	public static function pick( $slug, array $sizes, array $current = array() ) {
		if ( null !== $slug && ( ! is_scalar( $slug ) || is_bool( $slug ) ) ) {
			return array(
				'ok'    => false,
				'slug'  => null,
				'label' => null,
			);
		}
		$slug = substr( trim( (string) $slug ), 0, self::MAX_LABEL );
		if ( '' === $slug ) {
			return array(
				'ok'    => true,
				'slug'  => null,
				'label' => null,
			);
		}
		$found = self::find( $sizes, $slug );
		if ( $found ) {
			return array(
				'ok'    => true,
				'slug'  => $found['slug'],
				'label' => $found['label'],
			);
		}
		if ( isset( $current['size_slug'] ) && (string) $current['size_slug'] === $slug ) {
			$label = isset( $current['size_label'] ) && '' !== trim( (string) $current['size_label'] ) ? (string) $current['size_label'] : $slug;
			return array(
				'ok'    => true,
				'slug'  => $slug,
				'label' => $label,
			);
		}
		return array(
			'ok'    => false,
			'slug'  => null,
			'label' => null,
		);
	}

	/**
	 * The stored size of a line or a stay for display: null without one,
	 * else its label (the snapshot — the list's label when the slug is
	 * still in it) and whether it is still a standard size.
	 *
	 * @param array        $row   size_slug, size_label.
	 * @param array[]|null $sizes The standard list (null = not known here: nothing is flagged).
	 * @return array{slug: string, label: string, standard: bool}|null
	 */
	public static function of( array $row, $sizes = null ) {
		$slug = isset( $row['size_slug'] ) && is_scalar( $row['size_slug'] ) ? trim( (string) $row['size_slug'] ) : '';
		if ( '' === $slug ) {
			return null;
		}
		$label = isset( $row['size_label'] ) && '' !== trim( (string) $row['size_label'] ) ? (string) $row['size_label'] : $slug;
		$found = is_array( $sizes ) ? self::find( $sizes, $slug ) : null;

		return array(
			'slug'     => $slug,
			'label'    => $found ? $found['label'] : $label,
			'standard' => null === $sizes || null !== $found,
		);
	}

	/**
	 * A stored size in words: '' without one, its label, and " (not a
	 * standard size)" when it is no longer in the list.
	 *
	 * @param array        $row   size_slug, size_label.
	 * @param array[]|null $sizes The standard list (null = nothing is flagged).
	 * @return string
	 */
	public static function text( array $row, $sizes = null ) {
		$size = self::of( $row, $sizes );
		if ( null === $size ) {
			return '';
		}
		if ( $size['standard'] ) {
			return $size['label'];
		}
		/* translators: %s: size label */
		return sprintf( __( '%s (not a standard size)', 'fishotel-misc-plugin' ), $size['label'] );
	}

	/**
	 * The options of a Size dropdown: the standard list, plus the row's
	 * own stored size when it is no longer in it (marked, and selected).
	 *
	 * @param array[] $sizes   The standard list.
	 * @param array   $current The row (size_slug, size_label).
	 * @return array[] value, label, selected (bool), standard (bool).
	 */
	public static function options( array $sizes, array $current = array() ) {
		$own = self::of( $current, $sizes );
		$out = array();
		foreach ( $sizes as $size ) {
			$slug = (string) ( $size['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$out[] = array(
				'value'    => $slug,
				'label'    => (string) ( $size['label'] ?? $slug ),
				'selected' => null !== $own && $own['slug'] === $slug,
				'standard' => true,
			);
		}
		if ( null !== $own && ! $own['standard'] ) {
			$out[] = array(
				'value'    => $own['slug'],
				'label'    => self::text( $current, $sizes ),
				'selected' => true,
				'standard' => false,
			);
		}
		return $out;
	}

	/**
	 * The "Sizes in use on products" report (pure): each distinct value
	 * the fish products' variations carry, where it comes from and whether
	 * it is in the standard list — a value of the attribute in use whose
	 * slug is in the list, or a custom per-product value that reads like a
	 * standard label. Values that are not standard come first: they are
	 * what needs cleaning up.
	 *
	 * @param array[] $values   Store::variation_size_values(): key, global, value, label, products.
	 * @param array   $standard resolve().
	 * @return array[] key, global, value, label, products, standard (bool), why ('' or the reason it is not standard).
	 */
	public static function report( array $values, array $standard ) {
		$sizes    = isset( $standard['sizes'] ) && is_array( $standard['sizes'] ) ? $standard['sizes'] : array();
		$taxonomy = (string) ( $standard['attribute'] ?? '' );
		$by_label = array();
		foreach ( $sizes as $size ) {
			$by_label[ strtolower( trim( (string) ( $size['label'] ?? '' ) ) ) ] = true;
		}

		$out = array();
		foreach ( $values as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key    = (string) ( $row['key'] ?? '' );
			$value  = (string) ( $row['value'] ?? '' );
			$label  = isset( $row['label'] ) && '' !== (string) $row['label'] ? (string) $row['label'] : $value;
			$global = ! empty( $row['global'] );
			$why    = '';

			if ( $global ) {
				if ( '' !== $taxonomy && $key !== $taxonomy ) {
					/* translators: %s: attribute taxonomy */
					$why = sprintf( __( 'a term of another attribute (%s)', 'fishotel-misc-plugin' ), $key );
				} elseif ( ! self::find( $sizes, $value ) ) {
					$why = __( 'not in the standard list', 'fishotel-misc-plugin' );
				}
			} elseif ( ! isset( $by_label[ strtolower( trim( $label ) ) ] ) && ! self::find( $sizes, self::slug( $label ) ) ) {
				$why = __( 'typed on the product, not in the standard list', 'fishotel-misc-plugin' );
			}

			$out[] = array(
				'key'      => $key,
				'global'   => $global,
				'value'    => $value,
				'label'    => $label,
				'products' => max( 0, (int) ( $row['products'] ?? 0 ) ),
				'standard' => '' === $why,
				'why'      => $why,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['standard'] !== $b['standard'] ) {
					return $a['standard'] ? 1 : -1;
				}
				$by_label = strnatcasecmp( $a['label'], $b['label'] );
				return 0 !== $by_label ? $by_label : strcmp( $a['key'], $b['key'] );
			}
		);

		return $out;
	}

	/**
	 * Set the size of posted order lines from their slugs (pure): every
	 * line gets size_slug + size_label through pick() — against its stored
	 * row when it has an id — and a slug that is refused leaves the line
	 * without a size and is named in the warnings.
	 *
	 * @param array[]  $lines    Clean lines (Orders::normalize_lines()), each with size_slug.
	 * @param array[]  $sizes    The standard list.
	 * @param array[]  $stored   The order's lines as stored, by id.
	 * @param string[] $warnings Filled with one line per refused size.
	 * @return array[]
	 */
	public static function apply_to_lines( array $lines, array $sizes, array $stored = array(), &$warnings = array() ) {
		$warnings = array();
		foreach ( $lines as $index => $line ) {
			$id      = (int) ( $line['id'] ?? 0 );
			$current = $id > 0 && isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : array();
			$picked  = self::pick( $line['size_slug'] ?? '', $sizes, $current );
			if ( ! $picked['ok'] ) {
				/* translators: 1: line number, 2: species */
				$warnings[] = sprintf( __( 'line %1$d (%2$s): that size is no longer in the list, so none was saved', 'fishotel-misc-plugin' ), $index + 1, (string) ( $line['species'] ?? '' ) );
			}
			$lines[ $index ]['size_slug']  = $picked['slug'];
			$lines[ $index ]['size_label'] = $picked['label'];
		}
		return $lines;
	}
}
