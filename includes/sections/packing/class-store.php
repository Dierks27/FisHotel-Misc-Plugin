<?php
/**
 * Packing store — the ONLY file of the section that touches $wpdb, and it
 * only ever READS:
 *
 *   - size_attributes() / size_terms(): the global product attribute whose
 *     name or label contains "size" and its terms — used only while the
 *     Clean Room section (which resolves the same list) is not there;
 *   - variations_without_bag(): the variations that have no Bag size yet,
 *     with the size each carries — what the "Fill empty ones from the map"
 *     tool previews and then sets;
 *   - variation_counts() and bag_usage(): how many variations there are,
 *     how many carry a bag, and how many products / variations name each
 *     bag — for the Packing page;
 *   - packs_flags(): the "Livestock — packed in a bag" value stored on a
 *     list of products, in one go — for the fill tool; variation_bags():
 *     the Bag sizes stored on one product's variations — for the product
 *     editor's checkbox.
 *
 * The section has no table of its own. Every write goes through the
 * WooCommerce product object (Fill, Product) or the order object
 * (Order_Packing) — never through this file.
 *
 * Every method is wrapped: a database error or a missing table means an
 * empty answer, never an exception. None of them runs on a front-end
 * request: they are called from the Packing page and its AJAX only.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * Parent product statuses whose variations count (fish in quarantine
	 * are often still drafts).
	 *
	 * @var string[]
	 */
	const PRODUCT_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	const SIZES_LIMIT = 200;

	/**
	 * The most variations one scan reads.
	 *
	 * @var int
	 */
	const SCAN_LIMIT = 20000;

	/**
	 * The global product attributes whose name or label contains "size",
	 * in the order they were created. None when WooCommerce's attribute
	 * table is not there.
	 *
	 * @return array[] taxonomy ("pa_size"), name ("size"), label ("Size"), orderby.
	 */
	public function size_attributes() {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';

		try {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return array();
			}
			$rows = $wpdb->get_results( 'SELECT attribute_name, attribute_label, attribute_orderby FROM ' . $table . ' ORDER BY attribute_id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name  = (string) ( $row['attribute_name'] ?? '' );
			$label = (string) ( $row['attribute_label'] ?? '' );
			if ( '' === $name || ( ! Size_List::matches( $name ) && ! Size_List::matches( $label ) ) ) {
				continue;
			}
			$out[] = array(
				'taxonomy' => 'pa_' . $name,
				'name'     => $name,
				'label'    => '' !== $label ? $label : $name,
				'orderby'  => (string) ( $row['attribute_orderby'] ?? 'menu_order' ),
			);
		}

		return $out;
	}

	/**
	 * The terms of one attribute taxonomy with their custom order (the
	 * "order_<taxonomy>" term meta WooCommerce's drag-and-drop writes),
	 * unsorted — Size_List::order_terms() sorts them.
	 *
	 * @param string $taxonomy Taxonomy ("pa_size").
	 * @return array[] term_id, name, slug, menu_order.
	 */
	public function size_terms( $taxonomy ) {
		$taxonomy = (string) $taxonomy;
		if ( '' === $taxonomy ) {
			return array();
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, tm.meta_value AS menu_order FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_id = t.term_id LEFT JOIN ' . $wpdb->termmeta . ' tm ON tm.term_id = t.term_id AND tm.meta_key = %s WHERE tt.taxonomy = %s ORDER BY t.term_id ASC LIMIT %d', 'order_' . $taxonomy, $taxonomy, self::SIZES_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) $row['term_id'];
			if ( isset( $out[ $id ] ) ) {
				continue; // A term with its order meta twice is still one size.
			}
			$out[ $id ] = array(
				'term_id'    => $id,
				'name'       => (string) $row['name'],
				'slug'       => (string) $row['slug'],
				'menu_order' => isset( $row['menu_order'] ) && is_numeric( $row['menu_order'] ) ? (int) $row['menu_order'] : 0,
			);
		}

		return array_values( $out );
	}

	/**
	 * The variations that have no Bag size (no `_fh_bag_size` meta, or an
	 * empty one), oldest first, each with the attribute values whose key
	 * says "size" — Size_List::size_of() tells which size that is.
	 * Variations in the trash and those of a trashed product are left out.
	 *
	 * @param int $after_id Only variations with a higher id (0 = from the start).
	 * @param int $limit    At most this many (0 = SCAN_LIMIT).
	 * @return array[] id, parent, attributes (key → value).
	 */
	public function variations_without_bag( $after_id = 0, $limit = 0 ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		$limit    = (int) $limit > 0 ? min( (int) $limit, self::SCAN_LIMIT ) : self::SCAN_LIMIT;
		$statuses = "'" . implode( "','", self::PRODUCT_STATUSES ) . "'";

		$sql = 'SELECT v.ID AS id, v.post_parent AS parent FROM ' . $wpdb->posts . ' v'
			. ' INNER JOIN ' . $wpdb->posts . " p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status IN ({$statuses})"
			. " WHERE v.post_type = 'product_variation' AND v.post_status <> 'trash' AND v.ID > %d"
			. ' AND NOT EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . " bm WHERE bm.post_id = v.ID AND bm.meta_key = %s AND bm.meta_value <> '')"
			. ' ORDER BY v.ID ASC LIMIT %d';

		try {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $after_id, Packing::META_BAG, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id         = (int) $row['id'];
			$out[ $id ] = array(
				'id'         => $id,
				'parent'     => (int) $row['parent'],
				'attributes' => array(),
			);
		}
		if ( ! $out ) {
			return array();
		}

		// Their attribute values, a thousand variations at a time.
		foreach ( array_chunk( array_keys( $out ), 1000 ) as $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			try {
				$meta = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . " WHERE post_id IN ({$in}) AND meta_key LIKE %s AND meta_value <> ''", $wpdb->esc_like( 'attribute_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} catch ( \Throwable $e ) {
				$meta = array();
			}
			foreach ( is_array( $meta ) ? $meta : array() as $row ) {
				$id  = (int) $row['post_id'];
				$key = substr( (string) $row['meta_key'], strlen( 'attribute_' ) );
				if ( isset( $out[ $id ] ) && '' !== $key && Size_List::matches( $key ) && ! isset( $out[ $id ]['attributes'][ $key ] ) ) {
					$out[ $id ]['attributes'][ $key ] = (string) $row['meta_value'];
				}
			}
		}

		return array_values( $out );
	}

	/**
	 * How many variations there are (of products that are not in the
	 * trash) and how many of them carry a Bag size.
	 *
	 * @return array{total: int, with_bag: int}
	 */
	public function variation_counts() {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array(
				'total'    => 0,
				'with_bag' => 0,
			);
		}

		$statuses = "'" . implode( "','", self::PRODUCT_STATUSES ) . "'";
		$from     = ' FROM ' . $wpdb->posts . ' v INNER JOIN ' . $wpdb->posts . " p ON p.ID = v.post_parent AND p.post_type = 'product' AND p.post_status IN ({$statuses}) WHERE v.post_type = 'product_variation' AND v.post_status <> 'trash'";

		try {
			$total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$with  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $from . ' AND EXISTS (SELECT 1 FROM ' . $wpdb->postmeta . " bm WHERE bm.post_id = v.ID AND bm.meta_key = %s AND bm.meta_value <> '')", Packing::META_BAG ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array(
				'total'    => 0,
				'with_bag' => 0,
			);
		}

		return array(
			'total'    => $total,
			'with_bag' => min( $total, $with ),
		);
	}

	/**
	 * The "Livestock — packed in a bag" value stored on each of a list of
	 * products — only those that have one.
	 *
	 * @param int[] $product_ids Product IDs (parents, not variations).
	 * @return array<int, string> Product id → 'yes' | 'no'.
	 */
	public function packs_flags( array $product_ids ) {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		if ( ! is_object( $wpdb ) || ! $product_ids ) {
			return array();
		}

		$out = array();
		foreach ( array_chunk( $product_ids, 1000 ) as $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			try {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_value FROM ' . $wpdb->postmeta . " WHERE post_id IN ({$in}) AND meta_key = %s ORDER BY meta_id ASC", Packing::META_PACKS ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			} catch ( \Throwable $e ) {
				$rows = array();
			}
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$flag = Livestock::flag( $row['meta_value'] ?? '' );
				if ( '' !== $flag ) {
					$out[ (int) $row['post_id'] ] = $flag;
				}
			}
		}

		return $out;
	}

	/**
	 * The Bag sizes stored on the variations of one product (each value
	 * once; variations in the trash left out).
	 *
	 * @param int $product_id The variable product.
	 * @return string[] The stored values (bag ids as text).
	 */
	public function variation_bags( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( ! is_object( $wpdb ) || $product_id < 1 ) {
			return array();
		}

		try {
			$values = $wpdb->get_col(
				$wpdb->prepare( 'SELECT DISTINCT pm.meta_value FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . " v ON v.ID = pm.post_id WHERE v.post_parent = %d AND v.post_type = 'product_variation' AND v.post_status <> 'trash' AND pm.meta_key = %s AND pm.meta_value <> '' LIMIT 50", $product_id, Packing::META_BAG ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$out[] = trim( (string) $value );
		}

		return $out;
	}

	/**
	 * How many products and variations name each bag in their own Bag
	 * size.
	 *
	 * @return array<int, int> Bag id → how many.
	 */
	public function bag_usage() {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return array();
		}

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT pm.meta_value AS bag, COUNT(DISTINCT pm.post_id) AS uses FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_type IN ('product','product_variation') AND p.post_status <> 'trash' GROUP BY pm.meta_value", Packing::META_BAG ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$bag = is_numeric( $row['bag'] ) ? (int) $row['bag'] : 0;
			if ( $bag > 0 ) {
				$out[ $bag ] = ( $out[ $bag ] ?? 0 ) + (int) $row['uses'];
			}
		}

		return $out;
	}
}
