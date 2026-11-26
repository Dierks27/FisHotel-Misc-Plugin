<?php
/**
 * Med listings — reading and writing one field, through WordPress and
 * WooCommerce only.
 *
 * A field is named by a key:
 *   post_content / post_excerpt   the post's own text (wp_update_post)
 *   post_title / post_name        a PICTURE's title and address only (the
 *                                 product's own name is never written:
 *                                 a name that is a claim is flagged)
 *   meta:<key>                    one post meta value (update_post_meta /
 *                                 delete_post_meta; "not there" is kept
 *                                 apart from "empty" so undo puts back
 *                                 exactly what was)
 *   tags                          the product's tags, by name
 *                                 (wp_set_object_terms)
 *   attr:<key>                    one product attribute (the WC product
 *                                 object: get_attributes / set_attributes
 *                                 / save — WooCommerce itself clears the
 *                                 attribute's terms off the product)
 *
 * No SQL. Values are slashed where WordPress unslashes them.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fields
 */
class Fields {

	/**
	 * The meta fields the cleanup looks at: label and what it proposes.
	 *   empty      always proposed empty when it has text
	 *   clean      the pieces carrying a claim taken out (only when it has one)
	 *   meta       the composed search-engine description (only when it has a claim)
	 *   ack        proposed empty but left unticked: the owner decides
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const META = array(
		'_fishotel_active_ingredient'         => array( 'Active ingredient(s)', 'ingredient' ),
		'_fishotel_med_eyebrow'               => array( 'Tagline above the title', 'empty' ),
		'_fishotel_dosing_anchor'             => array( 'Calculator link (dosing anchor)', 'empty' ),
		'_fishotel_treats'                    => array( '"Treats" row', 'empty' ),
		'_fishotel_use_in'                    => array( '"Use in" row', 'empty' ),
		'_fishotel_best_for'                  => array( '"Best for" row', 'clean' ),
		'_fishotel_nutrition'                 => array( '"Nutrition" row', 'clean' ),
		'_fishotel_additives'                 => array( '"Additives" row', 'clean' ),
		'_fishotel_food_source'               => array( 'Food source', 'clean' ),
		'_purchase_note'                      => array( 'Purchase note (printed in order e-mails)', 'clean' ),
		'_fishotel_med_extra_ack'             => array( 'Extra checkout tick', 'ack' ),
		'_yoast_wpseo_title'                  => array( 'Yoast SEO title', 'clean' ),
		'_yoast_wpseo_metadesc'               => array( 'Yoast meta description', 'meta' ),
		'_yoast_wpseo_focuskw'                => array( 'Yoast focus keyphrase', 'clean' ),
		'_yoast_wpseo_opengraph-title'        => array( 'Yoast Facebook title', 'clean' ),
		'_yoast_wpseo_opengraph-description'  => array( 'Yoast Facebook description', 'meta' ),
		'_yoast_wpseo_twitter-title'          => array( 'Yoast X/Twitter title', 'clean' ),
		'_yoast_wpseo_twitter-description'    => array( 'Yoast X/Twitter description', 'meta' ),
		'rank_math_title'                     => array( 'Rank Math title', 'clean' ),
		'rank_math_description'               => array( 'Rank Math description', 'meta' ),
		'rank_math_focus_keyword'             => array( 'Rank Math focus keyword', 'clean' ),
		'rank_math_facebook_title'            => array( 'Rank Math Facebook title', 'clean' ),
		'rank_math_facebook_description'      => array( 'Rank Math Facebook description', 'meta' ),
		'rank_math_twitter_title'             => array( 'Rank Math X/Twitter title', 'clean' ),
		'rank_math_twitter_description'       => array( 'Rank Math X/Twitter description', 'meta' ),
	);

	/**
	 * Meta on a variation, an image.
	 *
	 * @var string[]
	 */
	const OTHER_META = array( '_variation_description', '_wp_attachment_image_alt' );

	/**
	 * Search-engine fields that are removed (not left empty) when the new
	 * text is empty, so Yoast / Rank Math fall back to their own template
	 * ("Product name | FisHotel") instead of printing nothing.
	 *
	 * @var string[]
	 */
	const DELETE_WHEN_EMPTY = array(
		'_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
		'_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description',
		'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
		'rank_math_facebook_title', 'rank_math_facebook_description',
		'rank_math_twitter_title', 'rank_math_twitter_description',
	);

	/**
	 * Whether a meta field is a title (search engines, social), not a sentence.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_title_key( $key ) {
		return (bool) preg_match( '/(?:title)$/', (string) $key );
	}

	/**
	 * Whether a field key is one this tool may write.
	 *
	 * @param string $field Key.
	 * @return bool
	 */
	public static function allowed( $field ) {
		$field = (string) $field;
		if ( in_array( $field, array( 'post_content', 'post_excerpt', 'tags', 'post_title', 'post_name' ), true ) ) {
			return true; // post_title / post_name: write() refuses anything but a picture.
		}
		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key = substr( $field, 5 );
			return isset( self::META[ $key ] ) || in_array( $key, self::OTHER_META, true );
		}
		return (bool) preg_match( '/^attr:[a-z0-9_%\-]+$/', $field );
	}

	/**
	 * Read a field.
	 *
	 * @param int    $object_id Post id (product, variation or image).
	 * @param string $field     Key.
	 * @return array{exists: bool, raw: mixed, text: string}
	 */
	public static function read( $object_id, $field ) {
		$object_id = (int) $object_id;
		$field     = (string) $field;

		if ( 'post_name' === $field ) {
			// A picture's address, with the old addresses WordPress keeps for
			// it (_wp_old_slug), so Undo puts back exactly that list too.
			$post = get_post( $object_id );
			$slug = $post ? (string) $post->post_name : '';
			$old  = $post ? array_values( array_map( 'strval', (array) get_post_meta( $object_id, '_wp_old_slug' ) ) ) : array();
			sort( $old );
			return array(
				'exists' => (bool) $post,
				'raw'    => array(
					'slug'      => $slug,
					'old_slugs' => $old,
				),
				'text'   => $slug,
			);
		}
		if ( 'post_content' === $field || 'post_excerpt' === $field || 'post_title' === $field ) {
			$post = get_post( $object_id );
			$raw  = $post ? (string) $post->$field : '';
			return array(
				'exists' => (bool) $post,
				'raw'    => $raw,
				'text'   => $raw,
			);
		}
		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key    = substr( $field, 5 );
			$exists = (bool) metadata_exists( 'post', $object_id, $key );
			$raw    = $exists ? get_post_meta( $object_id, $key, true ) : '';
			return array(
				'exists' => $exists,
				'raw'    => $raw,
				'text'   => is_scalar( $raw ) ? (string) $raw : wp_json_encode( $raw ),
			);
		}
		if ( 'tags' === $field ) {
			$names = wp_get_object_terms( $object_id, 'product_tag', array( 'fields' => 'names' ) );
			$names = is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
			sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
			return array(
				'exists' => true,
				'raw'    => $names,
				'text'   => implode( ', ', $names ),
			);
		}
		if ( 0 === strpos( $field, 'attr:' ) ) {
			$key     = substr( $field, 5 );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $object_id ) : null;
			$attrs   = $product ? $product->get_attributes( 'edit' ) : array();
			// 'order': the attribute keys as the product holds them now. It is
			// not part of the value (nor of its fingerprint): Undo uses it to
			// put an attribute back in its old place, so attributes with the
			// same position keep the order the page showed them in.
			if ( ! isset( $attrs[ $key ] ) || ! is_object( $attrs[ $key ] ) ) {
				return array(
					'exists' => false,
					'raw'    => null,
					'text'   => '',
					'order'  => array_map( 'strval', array_keys( $attrs ) ),
				);
			}
			$raw = self::export_attribute( $attrs[ $key ] );
			return array(
				'exists' => true,
				'raw'    => $raw,
				'text'   => self::attribute_text( $raw ),
				'order'  => array_map( 'strval', array_keys( $attrs ) ),
			);
		}
		return array(
			'exists' => false,
			'raw'    => null,
			'text'   => '',
		);
	}

	/**
	 * Write a field.
	 *
	 * @param int    $object_id Post id.
	 * @param string $field     Key.
	 * @param mixed  $raw       Value as read() gives it (or input_to_raw()).
	 * @param bool   $exists    False to remove it (meta and attributes).
	 * @param array  $order     Attributes only: the keys in the order the product
	 *                          held them when this value was read (read()['order']).
	 *                          An attribute put back goes into that place.
	 * @return true|string True, or what went wrong in words.
	 */
	public static function write( $object_id, $field, $raw, $exists = true, array $order = array() ) {
		$object_id = (int) $object_id;
		$field     = (string) $field;
		if ( ! self::allowed( $field ) ) {
			return 'This field cannot be changed here.';
		}

		if ( ( 'post_title' === $field || 'post_name' === $field ) && 'attachment' !== get_post_type( $object_id ) ) {
			return 'Only a picture\'s title and address are changed here — a product\'s name is yours to change.';
		}
		if ( 'post_name' === $field ) {
			$raw  = is_array( $raw ) ? $raw : array( 'slug' => (string) $raw, 'old_slugs' => null );
			$slug = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
			if ( '' === $slug ) {
				return 'A picture needs an address.';
			}
			$done = wp_update_post(
				array(
					'ID'        => $object_id,
					'post_name' => $slug,
				),
				true
			);
			if ( is_wp_error( $done ) || ! $done ) {
				return is_wp_error( $done ) ? $done->get_error_message() : 'WordPress did not save it.';
			}
			// Apply leaves WordPress's own old-address list as WordPress made it
			// (the old address goes on to the new one). Undo writes the list back
			// exactly as it was.
			if ( isset( $raw['old_slugs'] ) && is_array( $raw['old_slugs'] ) ) {
				delete_post_meta( $object_id, '_wp_old_slug' );
				foreach ( $raw['old_slugs'] as $o ) {
					add_post_meta( $object_id, '_wp_old_slug', (string) $o );
				}
			}
			return true;
		}
		if ( 'post_content' === $field || 'post_excerpt' === $field || 'post_title' === $field ) {
			$done = wp_update_post(
				array(
					'ID'   => $object_id,
					$field => wp_slash( (string) $raw ),
				),
				true
			);
			return is_wp_error( $done ) ? $done->get_error_message() : ( $done ? true : 'WordPress did not save it.' );
		}

		if ( 0 === strpos( $field, 'meta:' ) ) {
			$key = substr( $field, 5 );
			if ( ! $exists || ( '' === $raw && in_array( $key, self::DELETE_WHEN_EMPTY, true ) ) ) {
				delete_post_meta( $object_id, $key );
				return true;
			}
			update_post_meta( $object_id, $key, wp_slash( $raw ) );
			// update_post_meta() answers false both for "failed" and "already so": read back.
			return get_post_meta( $object_id, $key, true ) == $raw ? true : 'WordPress did not save it.'; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- meta comes back as strings.
		}

		if ( 'tags' === $field ) {
			$names = array_values( array_filter( array_map( 'trim', (array) $raw ), 'strlen' ) );
			$done  = wp_set_object_terms( $object_id, $names, 'product_tag', false );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $object_id );
			}
			return is_wp_error( $done ) ? $done->get_error_message() : true;
		}

		if ( 0 === strpos( $field, 'attr:' ) ) {
			$key     = substr( $field, 5 );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $object_id ) : null;
			if ( ! $product ) {
				return 'The product could not be opened.';
			}
			$attrs = $product->get_attributes( 'edit' );
			if ( ! $exists || null === $raw ) {
				unset( $attrs[ $key ] );
			} elseif ( isset( $attrs[ $key ] ) || ! $order ) {
				$attrs[ $key ] = self::import_attribute( (array) $raw );
			} else {
				$attrs = self::insert_in_order( $attrs, $key, self::import_attribute( (array) $raw ), $order );
			}
			$product->set_attributes( array_values( array_filter( $attrs ) ) );
			$product->save();
			return true;
		}

		return 'This field cannot be changed here.';
	}

	/**
	 * Put $key back among $attrs where it stood in $order: before the first
	 * key that followed it then and is still there, else after the last one
	 * that preceded it, else at the end. Several attributes put back one by
	 * one, in any order, so end up in their old order again.
	 *
	 * @param array  $attrs Key => attribute, as the product holds them now.
	 * @param string $key   The attribute to put back.
	 * @param object $attr  It.
	 * @param array  $order The keys as they were.
	 * @return array
	 */
	public static function insert_in_order( array $attrs, $key, $attr, array $order ) {
		$order = array_values( array_map( 'strval', $order ) );
		$at    = array_search( (string) $key, $order, true );
		$keys  = array_map( 'strval', array_keys( $attrs ) );
		$slot  = count( $keys );
		if ( false !== $at ) {
			$found = false;
			foreach ( array_slice( $order, $at + 1 ) as $next ) {
				$i = array_search( $next, $keys, true );
				if ( false !== $i ) {
					$slot  = $i;
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				foreach ( array_reverse( array_slice( $order, 0, $at ) ) as $prev ) {
					$i = array_search( $prev, $keys, true );
					if ( false !== $i ) {
						$slot = $i + 1;
						break;
					}
				}
			}
		}
		$out = array();
		$n   = 0;
		foreach ( $attrs as $k => $a ) {
			if ( $n === $slot ) {
				$out[ $key ] = $attr;
			}
			$out[ $k ] = $a;
			$n++;
		}
		if ( ! isset( $out[ $key ] ) ) {
			$out[ $key ] = $attr;
		}
		return $out;
	}

	/**
	 * What the owner typed (or the proposal) → the value to write.
	 *
	 * @param string $field Key.
	 * @param string $text  Text.
	 * @return mixed
	 */
	public static function input_to_raw( $field, $text ) {
		$field = (string) $field;
		$text  = (string) $text;
		if ( 'tags' === $field ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $text ) ), 'strlen' ) );
		}
		if ( 0 === strpos( $field, 'attr:' ) ) {
			return null; // An attribute row only ever takes the attribute off.
		}
		if ( 'post_name' === $field ) {
			return array(
				'slug'      => function_exists( 'sanitize_title' ) ? sanitize_title( $text ) : strtolower( trim( $text ) ),
				'old_slugs' => null,
			);
		}
		if ( 'post_title' === $field ) {
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( $text );
		}
		if ( 'post_content' === $field || 'post_excerpt' === $field || 'meta:_purchase_note' === $field || 'meta:_variation_description' === $field ) {
			return function_exists( 'wp_kses_post' ) ? wp_kses_post( $text ) : $text;
		}
		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $text ) : trim( $text );
	}

	/**
	 * A fingerprint of a read, to see on Apply that nobody changed the
	 * field since the review table was drawn.
	 *
	 * @param array $read read() result.
	 * @return string
	 */
	public static function hash( array $read ) {
		return md5( ( $read['exists'] ? '1' : '0' ) . '|' . serialize( self::plain( $read['raw'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint only.
	}

	/**
	 * Numbers and strings alike (meta comes back as strings).
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	private static function plain( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'plain' ), $v );
		}
		if ( is_bool( $v ) ) {
			return $v ? '1' : '';
		}
		return null === $v ? null : (string) $v;
	}

	/**
	 * Whether two values are the same as stored.
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	public static function same( $a, $b ) {
		return self::plain( $a ) === self::plain( $b );
	}

	/**
	 * A WC_Product_Attribute as a plain array.
	 *
	 * @param object $a Attribute.
	 * @return array
	 */
	public static function export_attribute( $a ) {
		$raw = array(
			'name'      => (string) $a->get_name(),
			'id'        => (int) $a->get_id(),
			'options'   => array_values( (array) $a->get_options() ),
			'position'  => (int) $a->get_position(),
			'visible'   => (bool) $a->get_visible(),
			'variation' => (bool) $a->get_variation(),
		);
		// A global attribute's values are term ids. The Categories step may
		// remove those terms, and WordPress gives a re-created term a new id,
		// so the values are kept by slug and name too (see import_attribute()).
		if ( $raw['id'] && function_exists( 'get_term' ) ) {
			$raw['terms'] = array();
			foreach ( $raw['options'] as $opt ) {
				$t = get_term( (int) $opt, $raw['name'] );
				if ( $t && ! is_wp_error( $t ) ) {
					$raw['terms'][] = array( (string) $t->slug, (string) $t->name );
				}
			}
		}
		return $raw;
	}

	/**
	 * A plain array back to a WC_Product_Attribute.
	 *
	 * @param array $raw export_attribute().
	 * @return object
	 */
	public static function import_attribute( array $raw ) {
		$a  = new \WC_Product_Attribute();
		$id = (int) ( $raw['id'] ?? 0 );
		if ( $id && 0 === strpos( (string) ( $raw['name'] ?? '' ), 'pa_' ) ) {
			// The attribute itself may have been removed by the Categories step
			// and made again (with a new id): put it back first, then take its id.
			if ( ! taxonomy_exists( (string) $raw['name'] ) && class_exists( __NAMESPACE__ . '\\Terms' ) ) {
				Terms::restore_attribute( (string) $raw['name'] );
			}
			if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
				$now = (int) wc_attribute_taxonomy_id_by_name( (string) $raw['name'] );
				$id  = $now ? $now : $id;
			}
		}
		$a->set_id( $id );
		$a->set_name( (string) ( $raw['name'] ?? '' ) );
		$options = (array) ( $raw['options'] ?? array() );
		if ( ! empty( $raw['id'] ) && ! empty( $raw['terms'] ) && function_exists( 'get_term_by' ) ) {
			// The values by slug: the term as it is now, or made again when it was removed.
			$options = array();
			foreach ( (array) $raw['terms'] as $pair ) {
				$t = get_term_by( 'slug', (string) $pair[0], (string) $raw['name'] );
				if ( $t && ! is_wp_error( $t ) ) {
					$options[] = (int) $t->term_id;
					continue;
				}
				$made = wp_insert_term( (string) $pair[1], (string) $raw['name'], array( 'slug' => (string) $pair[0] ) );
				if ( ! is_wp_error( $made ) ) {
					$options[] = (int) $made['term_id'];
				}
			}
		}
		$a->set_options( $options );
		$a->set_position( (int) ( $raw['position'] ?? 0 ) );
		$a->set_visible( ! empty( $raw['visible'] ) );
		$a->set_variation( ! empty( $raw['variation'] ) );
		return $a;
	}

	/**
	 * "Treats: Ich, Velvet" — an attribute's label and values in words.
	 *
	 * @param array $raw export_attribute().
	 * @return string
	 */
	public static function attribute_text( array $raw ) {
		$name  = (string) $raw['name'];
		$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $name ) : $name;
		$vals  = array();
		foreach ( (array) $raw['options'] as $opt ) {
			if ( $raw['id'] && is_numeric( $opt ) && function_exists( 'get_term' ) ) {
				$term   = get_term( (int) $opt, $name );
				$vals[] = $term && ! is_wp_error( $term ) ? $term->name : (string) $opt;
			} else {
				$vals[] = (string) $opt;
			}
		}
		return $label . ': ' . implode( ', ', $vals );
	}

	/**
	 * An attribute's values in words (for the composer and the finder).
	 *
	 * @param array $raw export_attribute().
	 * @return string[]
	 */
	public static function attribute_values( array $raw ) {
		$text = self::attribute_text( $raw );
		$pos  = strpos( $text, ': ' );
		$vals = false === $pos ? '' : substr( $text, $pos + 2 );
		return array_values( array_filter( array_map( 'trim', explode( ', ', $vals ) ), 'strlen' ) );
	}
}
