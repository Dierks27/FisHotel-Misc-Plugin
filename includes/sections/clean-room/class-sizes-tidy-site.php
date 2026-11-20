<?php
/**
 * Tidy up the sizes — the real shop: everything Sizes_Tidy_Run needs of
 * WordPress and WooCommerce (Sizes_Tidy_Shop), and nothing else. The
 * thinking is in Sizes_Tidy, the run in Sizes_Tidy_Run; this file only
 * reads and writes.
 *
 * Writes go through WooCommerce's own code wherever it has one — a
 * variation's size and "Enabled" through its product object and save()
 * (which rewrites its title and summary, its caches and the attribute
 * lookup table), a product's own list of sizes through
 * WC_Product_Attribute and save() (which sets its term relationships),
 * its default size through set_default_attributes(), then
 * WC_Product_Variable::sync() and wc_delete_product_transients(); a
 * term through wp_insert_term() / wp_delete_term(); the custom order
 * through wc_set_term_order() and wc_update_attribute().
 *
 * "Switched off" is WooCommerce's own: the variation's Enabled box
 * unticked (post_status private). Nothing is ever deleted but an old
 * term nothing uses.
 *
 * Undo puts back what a record says, exactly — the rows themselves
 * (post title, summary, status and dates, the meta rows with their
 * ids, a deleted term with its id, name, slug, order and meta) —, then
 * lets WooCommerce refresh what it derives from them.
 *
 * The record lives in two tables of its own, {prefix}fh_sizes_tidy_runs
 * and {prefix}fh_sizes_tidy_items, created the first time Apply is
 * pressed — never on activation, on an upgrade or by cron — and never
 * in an autoloaded option.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sizes_Tidy_Site
 */
class Sizes_Tidy_Site implements Sizes_Tidy_Shop {

	/**
	 * The record's tables (without the prefix).
	 */
	const TABLE_RUNS  = 'fh_sizes_tidy_runs';
	const TABLE_ITEMS = 'fh_sizes_tidy_items';

	/**
	 * The lock: an option row written and removed directly, one step at
	 * a time. A lock older than LOCK_SECONDS is one a timeout left behind.
	 */
	const LOCK_OPTION  = 'fishotel_cleanroom_sizes_tidy_lock';
	const LOCK_SECONDS = 180;

	/**
	 * The other places that store a size: store name → table (without
	 * the prefix). Each has an id, size_slug and size_label.
	 */
	const STORE_TABLES = array(
		'stays'          => 'fh_stays',
		'order_lines'    => 'fh_order_lines',
		'requests'       => 'fh_requests',
		'lostfish_cases' => 'fh_lostfish_cases',
		'invoice_lines'  => 'fh_invoice_lines',
		'box_shares'     => 'fh_box_shares',
	);

	/**
	 * The product meta a record keeps, row by row.
	 */
	const PARENT_META = array( '_product_attributes', '_default_attributes', '_price', '_stock_status', Sizes_Tidy::FILL_META );

	/**
	 * Row caps of the readers.
	 */
	const PRODUCTS_LIMIT   = 20000;
	const VARIATIONS_LIMIT = 400000;
	const FILL_LIMIT       = 500;

	/**
	 * Which tables are there, memoised per request.
	 *
	 * @var array<string,bool>
	 */
	private $has = array();

	/* ============================================================== */
	/*  Reading the shop                                               */
	/* ============================================================== */

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public function available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Attribute' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function has_table( $table ) {
		global $wpdb;
		if ( ! isset( $this->has[ $table ] ) ) {
			$this->has[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}
		return $this->has[ $table ];
	}

	/**
	 * The term ids of the fish categories and their children's.
	 *
	 * @return int[]
	 */
	private function fish_product_ids() {
		global $wpdb;

		$slugs = (array) apply_filters( 'fishotel_ship_day_fish_categories', Store::FISH_CATEGORIES );
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );
		if ( ! $slugs ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT t.term_id FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . " tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat' AND t.slug IN ({$in})", $slugs ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = implode( ',', array_map( 'intval', (array) $ids ) );
		if ( '' === $ids ) {
			return array();
		}

		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT tr.object_id FROM ' . $wpdb->term_relationships . ' tr INNER JOIN ' . $wpdb->term_taxonomy . " tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND (tt.term_id IN ({$ids}) OR tt.parent IN ({$ids}))" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Everything the plan is made of — read only, in a handful of
	 * queries whatever the size of the shop. Products in the trash are
	 * left out.
	 *
	 * @param string $taxonomy The size attribute's taxonomy.
	 * @return array
	 */
	public function read_shop( $taxonomy ) {
		global $wpdb;

		$taxonomy = (string) $taxonomy;
		$shop     = array(
			'taxonomy' => $taxonomy,
			'orderby'  => 'menu_order',
			'terms'    => array(),
			'orders'   => array(),
			'stores'   => array(),
			'packing'  => array(),
			'state'    => array(),
			'products' => array(),
		);
		if ( '' === $taxonomy || ! $this->available() ) {
			return $shop;
		}

		$store            = new Store();
		$shop['terms']    = $store->size_terms( $taxonomy );
		$shop['orderby']  = function_exists( 'wc_attribute_orderby' ) ? (string) wc_attribute_orderby( $taxonomy ) : 'menu_order';
		$state            = get_option( Sizes::STATE_OPTION, array() );
		$shop['state']    = is_array( $state ) && (string) ( $state['taxonomy'] ?? '' ) === $taxonomy ? $state : array();
		$packing          = get_option( Sizes_Tidy_Run::PACKING_OPTION, array() );
		$shop['packing']  = is_array( $packing ) && isset( $packing['size_map'] ) && is_array( $packing['size_map'] ) ? $packing['size_map'] : array();
		$variation_key    = 'attribute_' . $taxonomy;
		$fish             = array_fill_keys( $this->fish_product_ids(), true );

		// The products' own lists: their relationships to the attribute's terms.
		$lists = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT tr.object_id, t.slug FROM ' . $wpdb->term_relationships . ' tr INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tr.object_id ASC, tr.term_order ASC, t.term_id ASC', $taxonomy ), ARRAY_A ) as $row ) {
			$lists[ (int) $row['object_id'] ][] = (string) $row['slug'];
		}

		// Every variation with what the plan needs of it.
		$variations = array();
		$like       = $wpdb->esc_like( 'attribute_' ) . '%';
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT v.ID, v.post_parent, v.post_status, pm.meta_key, pm.meta_value FROM ' . $wpdb->posts . ' v LEFT JOIN ' . $wpdb->postmeta . " pm ON pm.post_id = v.ID AND (pm.meta_key LIKE %s OR pm.meta_key IN ('_price','_stock','_stock_status','_sku','_manage_stock')) WHERE v.post_type = 'product_variation' AND v.post_status IN ('publish','private') ORDER BY v.post_parent ASC, v.menu_order ASC, v.ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$like,
				self::VARIATIONS_LIMIT
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$vid = (int) $row['ID'];
			$pid = (int) $row['post_parent'];
			if ( ! isset( $variations[ $pid ][ $vid ] ) ) {
				$variations[ $pid ][ $vid ] = array(
					'id'           => $vid,
					'status'       => (string) $row['post_status'],
					'attrs'        => array(),
					'price'        => '',
					'stock'        => null,
					'stock_status' => 'instock',
					'sku'          => '',
					'managed'      => false,
				);
			}
			$key   = (string) $row['meta_key'];
			$value = (string) $row['meta_value'];
			if ( 0 === strpos( $key, 'attribute_' ) ) {
				$variations[ $pid ][ $vid ]['attrs'][ substr( $key, 10 ) ] = $value;
			} elseif ( '_price' === $key ) {
				$variations[ $pid ][ $vid ]['price'] = $value;
			} elseif ( '_stock' === $key ) {
				$variations[ $pid ][ $vid ]['stock'] = is_numeric( $value ) ? (int) $value : null;
			} elseif ( '_stock_status' === $key ) {
				$variations[ $pid ][ $vid ]['stock_status'] = '' !== $value ? $value : 'instock';
			} elseif ( '_sku' === $key ) {
				$variations[ $pid ][ $vid ]['sku'] = $value;
			} elseif ( '_manage_stock' === $key ) {
				$variations[ $pid ][ $vid ]['managed'] = 'yes' === $value;
			}
		}
		unset( $rows );

		// The products, with their attributes, default size and "Sizes to fill in" note.
		$meta = array();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pm.post_id, pm.meta_key, pm.meta_value FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key IN ('_product_attributes','_default_attributes',%s) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				Sizes_Tidy::FILL_META,
				self::PRODUCTS_LIMIT * 3
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$value                                                   = maybe_unserialize( $row['meta_value'] );
			$meta[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = is_array( $value ) ? $value : array();
		}
		unset( $rows );

		$posts = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_title, post_status FROM ' . $wpdb->posts . " WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') ORDER BY post_title ASC, ID ASC LIMIT %d", self::PRODUCTS_LIMIT ), ARRAY_A );
		foreach ( (array) $posts as $post ) {
			$pid        = (int) $post['ID'];
			$attributes = isset( $meta[ $pid ]['_product_attributes'] ) ? $meta[ $pid ]['_product_attributes'] : array();
			$defaults   = isset( $meta[ $pid ]['_default_attributes'] ) ? $meta[ $pid ]['_default_attributes'] : array();
			$is_fish    = isset( $fish[ $pid ] );
			$own        = isset( $variations[ $pid ] ) ? $variations[ $pid ] : array();

			// A size typed on the product (a custom attribute used for variations) — fish only.
			$typed = null;
			foreach ( $is_fish ? $attributes : array() as $key => $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || empty( $attribute['is_variation'] ) || ! Sizes::matches( (string) ( $attribute['name'] ?? $key ) ) ) {
					continue;
				}
				$options = array();
				foreach ( explode( '|', (string) ( $attribute['value'] ?? '' ) ) as $option ) {
					if ( '' !== trim( $option ) ) {
						$options[] = trim( $option );
					}
				}
				$typed = array(
					'key'     => (string) $key,
					'name'    => (string) ( $attribute['name'] ?? $key ),
					'options' => $options,
					'default' => (string) ( $defaults[ $key ] ?? '' ),
				);
				break;
			}

			$carries = isset( $lists[ $pid ] );
			foreach ( $own as $variation ) {
				$carries = $carries || '' !== (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
			}
			if ( ! $carries && null === $typed ) {
				continue;
			}
			foreach ( $own as $vid => $variation ) {
				if ( ! $variation['managed'] ) {
					$own[ $vid ]['stock'] = null;
				}
				unset( $own[ $vid ]['managed'] );
			}

			$shop['products'][ $pid ] = array(
				'id'         => $pid,
				'title'      => (string) $post['post_title'],
				'status'     => (string) $post['post_status'],
				'fish'       => $is_fish,
				'sizes'      => isset( $lists[ $pid ] ) ? $lists[ $pid ] : array(),
				'default'    => (string) ( $defaults[ $taxonomy ] ?? '' ),
				'typed'      => $typed,
				'fill'       => isset( $meta[ $pid ][ Sizes_Tidy::FILL_META ] ) ? $meta[ $pid ][ Sizes_Tidy::FILL_META ] : array(),
				'variations' => $own,
			);
		}
		unset( $variations, $meta, $posts, $variation_key );

		// Past order lines per size.
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( $this->has_table( $itemmeta ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_value, COUNT(*) AS uses FROM ' . $itemmeta . ' WHERE meta_key = %s GROUP BY meta_value', $taxonomy ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$shop['orders'][ (string) $row['meta_value'] ] = (int) $row['uses'];
			}
		}

		// The other places a size is stored.
		foreach ( self::STORE_TABLES as $name => $short ) {
			$table = $wpdb->prefix . $short;
			if ( ! $this->has_table( $table ) ) {
				continue;
			}
			$sql = 'SELECT s.size_slug, COUNT(*) AS uses FROM ' . $table . ' s';
			if ( 'invoice_lines' === $name ) {
				if ( ! $this->has_table( $wpdb->prefix . 'fh_invoices' ) ) {
					continue;
				}
				$sql .= ' INNER JOIN ' . $wpdb->prefix . "fh_invoices i ON i.id = s.invoice_id AND i.state = 'draft'";
			}
			$sql .= " WHERE s.size_slug IS NOT NULL AND s.size_slug <> '' GROUP BY s.size_slug";
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$shop['stores'][ $name ][ (string) $row['size_slug'] ] = (int) $row['uses'];
			}
		}

		return $shop;
	}

	/* ============================================================== */
	/*  Terms                                                          */
	/* ============================================================== */

	/**
	 * A term's rows, exactly as stored.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return array|null
	 */
	public function term_get( $taxonomy, $slug ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, t.term_group, tt.term_taxonomy_id, tt.description, tt.parent, tt.count FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1', (string) $taxonomy, (string) $slug ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['meta'] = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_key, meta_value FROM ' . $wpdb->termmeta . ' WHERE term_id = %d ORDER BY meta_id ASC', (int) $row['term_id'] ), ARRAY_A );

		return $row;
	}

	/**
	 * Create a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $label    Name.
	 * @param string $slug     Slug.
	 * @return bool
	 */
	public function term_add( $taxonomy, $label, $slug ) {
		$made = wp_insert_term( wp_slash( (string) $label ), (string) $taxonomy, array( 'slug' => (string) $slug ) );
		return ! is_wp_error( $made );
	}

	/**
	 * How many products list a term and how many variations carry it —
	 * of every product type and status but the trash.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return int
	 */
	public function term_uses( $taxonomy, $slug ) {
		global $wpdb;

		$listed  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->term_relationships . ' tr INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' t ON t.term_id = tt.term_id INNER JOIN ' . $wpdb->posts . " p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND t.slug = %s AND p.post_status NOT IN ('trash','auto-draft')", (string) $taxonomy, (string) $slug ) );
		$carried = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . ' v ON v.ID = pm.post_id INNER JOIN ' . $wpdb->posts . " p ON p.ID = v.post_parent WHERE pm.meta_key = %s AND pm.meta_value = %s AND v.post_type = 'product_variation' AND v.post_status IN ('publish','private') AND p.post_status NOT IN ('trash','auto-draft')", 'attribute_' . $taxonomy, (string) $slug ) );

		return $listed + $carried;
	}

	/**
	 * Delete a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return bool
	 */
	public function term_delete( $taxonomy, $slug ) {
		$term = get_term_by( 'slug', (string) $slug, (string) $taxonomy );
		return $term && ! is_wp_error( $term ) && true === wp_delete_term( (int) $term->term_id, (string) $taxonomy );
	}

	/**
	 * Put a deleted term back as it was: the same id when it is still
	 * free (it is, unless the database handed it to a newer term), its
	 * name, slug, description, order and every meta row.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $record   term_get().
	 * @return bool
	 */
	public function term_restore( $taxonomy, array $record ) {
		global $wpdb;

		$term_id = (int) ( $record['term_id'] ?? 0 );
		$tt_id   = (int) ( $record['term_taxonomy_id'] ?? 0 );
		$free    = $term_id > 0 && $tt_id > 0
			&& ! $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM ' . $wpdb->terms . ' WHERE term_id = %d', $term_id ) )
			&& ! $wpdb->get_var( $wpdb->prepare( 'SELECT term_taxonomy_id FROM ' . $wpdb->term_taxonomy . ' WHERE term_taxonomy_id = %d OR term_id = %d', $tt_id, $term_id ) );

		if ( $free ) {
			$wpdb->insert(
				$wpdb->terms,
				array(
					'term_id'    => $term_id,
					'name'       => (string) $record['name'],
					'slug'       => (string) $record['slug'],
					'term_group' => (int) ( $record['term_group'] ?? 0 ),
				)
			);
			$wpdb->insert(
				$wpdb->term_taxonomy,
				array(
					'term_taxonomy_id' => $tt_id,
					'term_id'          => $term_id,
					'taxonomy'         => (string) $taxonomy,
					'description'      => (string) ( $record['description'] ?? '' ),
					'parent'           => (int) ( $record['parent'] ?? 0 ),
					'count'            => 0,
				)
			);
		} else {
			$made = wp_insert_term(
				wp_slash( Sizes::plain( (string) $record['name'] ) ),
				(string) $taxonomy,
				array(
					'slug'        => (string) $record['slug'],
					'description' => wp_slash( (string) ( $record['description'] ?? '' ) ),
				)
			);
			if ( is_wp_error( $made ) ) {
				return false;
			}
			$term_id = (int) $made['term_id'];
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $term_id ) );
		}

		foreach ( (array) ( $record['meta'] ?? array() ) as $meta ) {
			$row = array(
				'term_id'    => $term_id,
				'meta_key'   => (string) $meta['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			);
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM ' . $wpdb->termmeta . ' WHERE meta_id = %d', (int) $meta['meta_id'] ) ) ) {
				$row = array( 'meta_id' => (int) $meta['meta_id'] ) + $row;
			}
			$wpdb->insert( $wpdb->termmeta, $row );
		}
		wp_cache_delete( $term_id, 'term_meta' );
		clean_term_cache( $term_id, (string) $taxonomy );

		return true;
	}

	/**
	 * The attribute's order: how it sorts, and each term's stored order
	 * (the "order" term meta WooCommerce's drag-and-drop writes).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array{orderby: string, order: array}
	 */
	public function order_get( $taxonomy ) {
		global $wpdb;

		$order = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.slug, tm.meta_value FROM ' . $wpdb->terms . ' t INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_id = t.term_id LEFT JOIN ' . $wpdb->termmeta . " tm ON tm.term_id = t.term_id AND tm.meta_key = 'order' WHERE tt.taxonomy = %s ORDER BY t.term_id ASC", (string) $taxonomy ), ARRAY_A ) as $row ) {
			if ( ! array_key_exists( (string) $row['slug'], $order ) ) {
				$order[ (string) $row['slug'] ] = null === $row['meta_value'] ? null : (int) $row['meta_value'];
			}
		}

		return array(
			'orderby' => function_exists( 'wc_attribute_orderby' ) ? (string) wc_attribute_orderby( (string) $taxonomy ) : 'menu_order',
			'order'   => $order,
		);
	}

	/**
	 * Set the attribute's order.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $order    slug → order (null = none).
	 * @param string $orderby  menu_order|name|name_num|id.
	 * @return bool
	 */
	public function order_set( $taxonomy, array $order, $orderby ) {
		foreach ( $order as $slug => $at ) {
			$term = get_term_by( 'slug', (string) $slug, (string) $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			if ( null === $at ) {
				delete_term_meta( (int) $term->term_id, 'order' );
			} elseif ( function_exists( 'wc_set_term_order' ) ) {
				wc_set_term_order( (int) $term->term_id, (int) $at, (string) $taxonomy );
			} else {
				update_term_meta( (int) $term->term_id, 'order', (int) $at );
			}
		}

		if ( function_exists( 'wc_attribute_orderby' ) && function_exists( 'wc_update_attribute' ) && function_exists( 'wc_attribute_taxonomy_id_by_name' ) && wc_attribute_orderby( (string) $taxonomy ) !== (string) $orderby ) {
			$id = (int) wc_attribute_taxonomy_id_by_name( (string) $taxonomy );
			if ( $id > 0 ) {
				wc_update_attribute( $id, array( 'order_by' => (string) $orderby ) );
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		clean_taxonomy_cache( (string) $taxonomy );

		return true;
	}

	/* ============================================================== */
	/*  Products                                                       */
	/* ============================================================== */

	/**
	 * The meta rows of a post for some keys (and every "attribute_…" key
	 * when asked), with their ids, as stored.
	 *
	 * @param int      $post_id    Post id.
	 * @param string[] $keys       Meta keys.
	 * @param bool     $attributes Also every key that starts with "attribute_".
	 * @return array[] meta_id, meta_key, meta_value.
	 */
	private function meta_rows( $post_id, array $keys, $attributes = false ) {
		global $wpdb;

		$where  = array();
		$params = array( (int) $post_id );
		if ( $keys ) {
			$where[] = 'meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_values( $keys ) );
		}
		if ( $attributes ) {
			$where[]  = 'meta_key LIKE %s';
			$params[] = $wpdb->esc_like( 'attribute_' ) . '%';
		}
		if ( ! $where ) {
			return array();
		}

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id = %d AND (' . implode( ' OR ', $where ) . ') ORDER BY meta_id ASC', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Put the meta rows of a post back exactly: what is there for those
	 * keys and is not in the record goes, what is missing comes back —
	 * with its old id while that id is free.
	 *
	 * @param int      $post_id    Post id.
	 * @param string[] $keys       Meta keys.
	 * @param bool     $attributes Also every "attribute_…" key.
	 * @param array[]  $rows       meta_rows() as recorded.
	 */
	private function meta_restore( $post_id, array $keys, $attributes, array $rows ) {
		global $wpdb;

		$sign = static function ( $row ) {
			return (int) $row['meta_id'] . '|' . (string) $row['meta_key'] . '|' . (string) $row['meta_value'];
		};
		$want = array();
		foreach ( $rows as $row ) {
			$want[ $sign( $row ) ] = $row;
		}
		$changed = false;
		foreach ( $this->meta_rows( $post_id, $keys, $attributes ) as $row ) {
			if ( isset( $want[ $sign( $row ) ] ) ) {
				unset( $want[ $sign( $row ) ] );
				continue;
			}
			$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $row['meta_id'] ) );
			$changed = true;
		}
		foreach ( $want as $row ) {
			$insert = array(
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $row['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			);
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM ' . $wpdb->postmeta . ' WHERE meta_id = %d', (int) $row['meta_id'] ) ) ) {
				$insert = array( 'meta_id' => (int) $row['meta_id'] ) + $insert;
			}
			$wpdb->insert( $wpdb->postmeta, $insert );
			$changed = true;
		}
		if ( $changed ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
	}

	/**
	 * The ids of a product's variations, switched on or off.
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	private function variation_ids( $product_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish','private') ORDER BY menu_order ASC, ID ASC", (int) $product_id ) ) );
	}

	/**
	 * The slugs a product has in a taxonomy, in their stored order.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return array[] slug, term_order.
	 */
	private function relationships( $product_id, $taxonomy ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.slug, tr.term_order FROM ' . $wpdb->term_relationships . ' tr INNER JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN ' . $wpdb->terms . ' t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tr.term_order ASC, t.term_id ASC', (int) $product_id, (string) $taxonomy ), ARRAY_A );
	}

	/**
	 * A product and its variations as stored — everything a run may
	 * change, row by row.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @return array|null
	 */
	public function product_get( $taxonomy, $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		$post       = $wpdb->get_row( $wpdb->prepare( 'SELECT post_status, post_modified, post_modified_gmt FROM ' . $wpdb->posts . " WHERE ID = %d AND post_type = 'product'", $product_id ), ARRAY_A );
		if ( ! is_array( $post ) ) {
			return null;
		}

		$record = array(
			'id'         => $product_id,
			'post'       => $post,
			'meta'       => $this->meta_rows( $product_id, self::PARENT_META ),
			'terms'      => array(
				(string) $taxonomy   => $this->relationships( $product_id, (string) $taxonomy ),
				'product_visibility' => $this->relationships( $product_id, 'product_visibility' ),
			),
			'variations' => array(),
		);
		foreach ( $this->variation_ids( $product_id ) as $vid ) {
			$record['variations'][ $vid ] = array(
				'post' => $wpdb->get_row( $wpdb->prepare( 'SELECT post_status, post_title, post_excerpt, post_name, post_modified, post_modified_gmt FROM ' . $wpdb->posts . ' WHERE ID = %d', $vid ), ARRAY_A ),
				'meta' => $this->meta_rows( $vid, array(), true ),
			);
		}

		return $record;
	}

	/**
	 * Apply one product of a plan through WooCommerce: each variation's
	 * size (and Enabled, when it is switched off), then the product's own
	 * list of sizes and its default size, then WooCommerce's own sync
	 * and caches. Every value is absolute: applying it twice is the same
	 * as once.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @param array  $change     vars, sizes, default, convert, fill.
	 * @return bool
	 */
	public function product_apply( $taxonomy, $product_id, array $change ) {
		$taxonomy = (string) $taxonomy;
		$product  = wc_get_product( (int) $product_id );
		if ( ! $product instanceof \WC_Product || $product instanceof \WC_Product_Variation ) {
			return false;
		}
		$convert = isset( $change['convert'] ) && is_array( $change['convert'] ) ? $change['convert'] : null;

		foreach ( (array) ( $change['vars'] ?? array() ) as $vid => $entry ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation instanceof \WC_Product_Variation || (int) $variation->get_parent_id() !== (int) $product_id ) {
				continue;
			}
			$attributes = (array) $variation->get_attributes( 'edit' );
			if ( null !== $convert ) {
				unset( $attributes[ (string) $convert['key'] ] );
			}
			$attributes[ $taxonomy ] = (string) $entry['to'];
			$variation->set_attributes( $attributes );
			if ( ! empty( $entry['off'] ) ) {
				$variation->set_status( 'private' );
			}
			$variation->save();
		}

		// The product's own list.
		$term_ids = array();
		foreach ( (array) ( $change['sizes'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		$attributes = (array) $product->get_attributes( 'edit' );
		$next       = array();
		$placed     = false;
		foreach ( $attributes as $key => $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( null !== $convert && (string) $key === (string) $convert['key'] ) {
				if ( ! $placed ) {
					$new = new \WC_Product_Attribute();
					$new->set_id( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ? (int) wc_attribute_taxonomy_id_by_name( $taxonomy ) : 0 );
					$new->set_name( $taxonomy );
					$new->set_options( $term_ids );
					$new->set_position( $attribute->get_position() );
					$new->set_visible( $attribute->get_visible() );
					$new->set_variation( true );
					$next[] = $new;
					$placed = true;
				}
				continue;
			}
			if ( (string) $key === $taxonomy || $attribute->get_name() === $taxonomy ) {
				if ( ! $placed ) {
					$attribute->set_options( $term_ids );
					$next[] = $attribute;
					$placed = true;
				}
				continue;
			}
			$next[] = $attribute;
		}
		if ( $placed ) {
			$product->set_attributes( $next );
		} elseif ( $term_ids || $this->relationships( (int) $product_id, $taxonomy ) ) {
			// Listed by its terms only, without the attribute on the product: keep the terms in step.
			wp_set_object_terms( (int) $product_id, $term_ids, $taxonomy );
		}

		if ( array_key_exists( 'default', $change ) && null !== $change['default'] || null !== $convert ) {
			$defaults = (array) $product->get_default_attributes( 'edit' );
			if ( null !== $convert ) {
				unset( $defaults[ (string) $convert['key'] ] );
			}
			if ( isset( $change['default'] ) && '' !== (string) $change['default'] ) {
				$defaults[ $taxonomy ] = (string) $change['default'];
			} elseif ( array_key_exists( 'default', $change ) && null !== $change['default'] ) {
				unset( $defaults[ $taxonomy ] );
			}
			$product->set_default_attributes( $defaults );
		}

		if ( isset( $change['fill'] ) && is_array( $change['fill'] ) ) {
			$product->update_meta_data( Sizes_Tidy::FILL_META, $change['fill'] );
		}
		$product->save();

		if ( $product instanceof \WC_Product_Variable ) {
			\WC_Product_Variable::sync( (int) $product_id );
		}
		wc_delete_product_transients( (int) $product_id );

		return true;
	}

	/**
	 * Put a product and its variations back exactly as recorded. First
	 * through WooCommerce (so its caches, the lookup tables and whatever
	 * listens to a save follow), then the rows themselves: the titles,
	 * summaries, statuses and dates, and the meta rows with their ids.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @param array  $record     product_get().
	 * @return bool
	 */
	public function product_restore( $taxonomy, $product_id, array $record ) {
		global $wpdb;

		$taxonomy   = (string) $taxonomy;
		$product_id = (int) $product_id;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . " WHERE ID = %d AND post_type = 'product'", $product_id ) ) ) {
			return false;
		}

		foreach ( (array) ( $record['variations'] ?? array() ) as $vid => $was ) {
			$vid       = (int) $vid;
			$variation = wc_get_product( $vid );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			$attributes = array();
			foreach ( (array) ( $was['meta'] ?? array() ) as $row ) {
				if ( 0 === strpos( (string) $row['meta_key'], 'attribute_' ) ) {
					$attributes[ substr( (string) $row['meta_key'], 10 ) ] = (string) $row['meta_value'];
				}
			}
			$variation->set_attributes( $attributes );
			if ( in_array( (string) ( $was['post']['post_status'] ?? '' ), array( 'publish', 'private' ), true ) ) {
				$variation->set_status( (string) $was['post']['post_status'] );
			}
			$variation->save();

			$this->meta_restore( $vid, array(), true, (array) ( $was['meta'] ?? array() ) );
			if ( is_array( $was['post'] ?? null ) ) {
				$wpdb->update( $wpdb->posts, $was['post'], array( 'ID' => $vid ) );
			}
			clean_post_cache( $vid );
		}

		// The product's own list: its terms, then its meta rows.
		$term_ids = array();
		foreach ( (array) ( $record['terms'][ $taxonomy ] ?? array() ) as $row ) {
			$term = get_term_by( 'slug', (string) $row['slug'], $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_ids[] = (int) $term->term_id;
			}
		}
		wp_set_object_terms( $product_id, $term_ids, $taxonomy );
		$this->meta_restore( $product_id, self::PARENT_META, false, (array) ( $record['meta'] ?? array() ) );
		clean_post_cache( $product_id );

		// WooCommerce's own: the prices and stock status it derives, its caches, its lookup table.
		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product_Variable ) {
			\WC_Product_Variable::sync( $product_id );
		}
		wc_delete_product_transients( $product_id );
		$this->refresh_lookup( $product_id );

		// And the rows again, should the sync have read them differently from how they were.
		$this->meta_restore( $product_id, self::PARENT_META, false, (array) ( $record['meta'] ?? array() ) );
		$visibility = array();
		foreach ( (array) ( $record['terms']['product_visibility'] ?? array() ) as $row ) {
			$visibility[] = (string) $row['slug'];
		}
		if ( taxonomy_exists( 'product_visibility' ) ) {
			wp_set_object_terms( $product_id, $visibility, 'product_visibility' );
		}
		if ( is_array( $record['post'] ?? null ) ) {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => (string) $record['post']['post_modified'],
					'post_modified_gmt' => (string) $record['post']['post_modified_gmt'],
				),
				array( 'ID' => $product_id )
			);
		}
		clean_post_cache( $product_id );

		return true;
	}

	/**
	 * Have WooCommerce rebuild its attribute lookup rows for a product
	 * (and its variations), where it keeps that table.
	 *
	 * @param int $product_id Product id.
	 */
	private function refresh_lookup( $product_id ) {
		$class = '\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $class ) ) {
			return;
		}
		try {
			$lookup = wc_get_container()->get( $class );
			if ( is_object( $lookup ) && method_exists( $lookup, 'on_product_changed' ) ) {
				$lookup->on_product_changed( (int) $product_id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/* ============================================================== */
	/*  The other places a size is stored                              */
	/* ============================================================== */

	/**
	 * A store's table, or '' when the store is unknown or its table is
	 * not there (its section never ran).
	 *
	 * @param string $store Store name.
	 * @return string
	 */
	private function store_table( $store ) {
		global $wpdb;
		if ( ! isset( self::STORE_TABLES[ (string) $store ] ) ) {
			return '';
		}
		$table = $wpdb->prefix . self::STORE_TABLES[ (string) $store ];
		return $this->has_table( $table ) ? $table : '';
	}

	/**
	 * Rows of a store whose size is one of the slugs. Invoice lines:
	 * only those of a draft — a published invoice keeps what it says.
	 *
	 * @param string   $store    Store name.
	 * @param string[] $slugs    Size slugs.
	 * @param int      $after_id Only rows after this id.
	 * @param int      $limit    At most.
	 * @return array[]
	 */
	public function rows_find( $store, array $slugs, $after_id, $limit ) {
		global $wpdb;

		$table = $this->store_table( $store );
		$slugs = array_values( array_map( 'strval', $slugs ) );
		if ( '' === $table || ! $slugs ) {
			return array();
		}
		$join = '';
		if ( 'invoice_lines' === (string) $store ) {
			if ( ! $this->has_table( $wpdb->prefix . 'fh_invoices' ) ) {
				return array();
			}
			$join = ' INNER JOIN ' . $wpdb->prefix . "fh_invoices i ON i.id = s.invoice_id AND i.state = 'draft'";
		}
		$in     = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$params = array_merge( array( (int) $after_id ), $slugs, array( max( 1, (int) $limit ) ) );

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT s.* FROM ' . $table . ' s' . $join . " WHERE s.id > %d AND s.size_slug IN ({$in}) ORDER BY s.id ASC LIMIT %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Rows of a store where share_key is one of the values.
	 *
	 * @param string   $store  Store name.
	 * @param string   $column share_key.
	 * @param string[] $values Values.
	 * @return array[]
	 */
	public function rows_with( $store, $column, array $values ) {
		global $wpdb;

		$table  = $this->store_table( $store );
		$values = array_values( array_map( 'strval', $values ) );
		if ( '' === $table || 'share_key' !== (string) $column || ! $values || ! in_array( (string) $store, array( 'box_shares', 'order_lines' ), true ) ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $values, 200 ) as $chunk ) {
			$in  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$out = array_merge( $out, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . " WHERE share_key IN ({$in}) ORDER BY id ASC", $chunk ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		return $out;
	}

	/**
	 * One row.
	 *
	 * @param string $store Store name.
	 * @param int    $id    Row id.
	 * @return array|null
	 */
	public function row_get( $store, $id ) {
		global $wpdb;
		$table = $this->store_table( $store );
		$row   = '' !== $table ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', (int) $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Write fields of a row.
	 *
	 * @param string $store  Store name.
	 * @param int    $id     Row id.
	 * @param array  $fields column → value.
	 * @return bool
	 */
	public function row_update( $store, $id, array $fields ) {
		global $wpdb;
		$table = $this->store_table( $store );
		unset( $fields['id'] );
		if ( '' === $table || ! $fields ) {
			return false;
		}
		return false !== $wpdb->update( $table, $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Put a deleted row back, with its id.
	 *
	 * @param string $store Store name.
	 * @param array  $row   The complete row.
	 * @return bool
	 */
	public function row_insert( $store, array $row ) {
		global $wpdb;
		$table = $this->store_table( $store );
		return '' !== $table && $row && false !== $wpdb->insert( $table, $row );
	}

	/**
	 * Delete a row.
	 *
	 * @param string $store Store name.
	 * @param int    $id    Row id.
	 * @return bool
	 */
	public function row_delete( $store, $id ) {
		global $wpdb;
		$table = $this->store_table( $store );
		return '' !== $table && false !== $wpdb->delete( $table, array( 'id' => (int) $id ) );
	}

	/**
	 * A setting, or null when it is not there.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function option_get( $name ) {
		return get_option( (string) $name, null );
	}

	/**
	 * Save a setting (null removes it). The tool's own state is never
	 * autoloaded; another section's setting keeps how it is loaded.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function option_set( $name, $value ) {
		if ( null === $value ) {
			delete_option( (string) $name );
			return true;
		}
		update_option( (string) $name, $value, Sizes::STATE_OPTION === (string) $name ? false : null );
		return true;
	}

	/* ============================================================== */
	/*  The record                                                     */
	/* ============================================================== */

	/**
	 * A record table's name.
	 *
	 * @param string $which runs|items.
	 * @return string
	 */
	public static function table( $which ) {
		global $wpdb;
		return $wpdb->prefix . ( 'items' === $which ? self::TABLE_ITEMS : self::TABLE_RUNS );
	}

	/**
	 * Whether the record's tables are there.
	 *
	 * @return bool
	 */
	public function has_record() {
		return $this->has_table( self::table( 'runs' ) ) && $this->has_table( self::table( 'items' ) );
	}

	/**
	 * Create the record's tables — only ever called when a run is about
	 * to be saved (the owner pressed Apply).
	 *
	 * @return bool
	 */
	private function make_record() {
		global $wpdb;

		if ( $this->has_record() ) {
			return true;
		}
		$collate = $wpdb->get_charset_collate();
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::table( 'runs' ) . " (\n\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\tstatus varchar(12) NOT NULL DEFAULT 'recording',\n\ttaxonomy varchar(64) NOT NULL DEFAULT '',\n\tcreated_at datetime NOT NULL,\n\tcreated_by varchar(60) NOT NULL DEFAULT '',\n\tfinished_at datetime NULL,\n\tdata longtext NULL,\n\tPRIMARY KEY  (id)\n) {$collate}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . self::table( 'items' ) . " (\n\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\trun_id bigint(20) unsigned NOT NULL,\n\tkind varchar(16) NOT NULL DEFAULT '',\n\tobject_id bigint(20) unsigned NOT NULL DEFAULT 0,\n\tobject_key varchar(191) NOT NULL DEFAULT '',\n\tstate tinyint(3) unsigned NOT NULL DEFAULT 0,\n\tbefore_data longtext NULL,\n\tafter_data longtext NULL,\n\tnote varchar(40) NOT NULL DEFAULT '',\n\tPRIMARY KEY  (id),\n\tKEY run_state (run_id, state, id)\n) {$collate}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$this->has = array();

		return $this->has_record();
	}

	/**
	 * Data as the record stores it: JSON, with any text that is not
	 * valid UTF-8 (a meta value can be anything) wrapped so that it comes
	 * back byte for byte.
	 *
	 * @param mixed $data Data.
	 * @return string|null
	 */
	public static function pack( $data ) {
		if ( null === $data ) {
			return null;
		}
		$wrap = static function ( $value ) use ( &$wrap ) {
			if ( is_array( $value ) ) {
				$out = array();
				foreach ( $value as $key => $inner ) {
					$out[ $key ] = $wrap( $inner );
				}
				return isset( $out['__b64'] ) ? array( '__arr' => $out ) : $out;
			}
			if ( is_string( $value ) && ! preg_match( '//u', $value ) ) {
				return array( '__b64' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- bytes kept as they are.
			}
			return $value;
		};
		$json = wp_json_encode( $wrap( $data ) );
		return false === $json ? null : $json;
	}

	/**
	 * Data back from the record.
	 *
	 * @param string|null $json pack().
	 * @return mixed
	 */
	public static function unpack( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$unwrap = static function ( $value ) use ( &$unwrap ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			if ( 1 === count( $value ) && isset( $value['__b64'] ) && is_string( $value['__b64'] ) ) {
				return (string) base64_decode( $value['__b64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			}
			if ( 1 === count( $value ) && isset( $value['__arr'] ) && is_array( $value['__arr'] ) ) {
				$value = $value['__arr'];
			}
			$out = array();
			foreach ( $value as $key => $inner ) {
				$out[ $key ] = $unwrap( $inner );
			}
			return $out;
		};
		return $unwrap( json_decode( (string) $json, true ) );
	}

	/**
	 * Save a run.
	 *
	 * @param array $run status, taxonomy, created_by, data.
	 * @return int
	 */
	public function run_create( array $run ) {
		global $wpdb;

		if ( ! $this->make_record() ) {
			return 0;
		}
		$ok = $wpdb->insert(
			self::table( 'runs' ),
			array(
				'status'     => (string) ( $run['status'] ?? 'recording' ),
				'taxonomy'   => (string) ( $run['taxonomy'] ?? '' ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_by' => substr( (string) ( $run['created_by'] ?? '' ), 0, 60 ),
				'data'       => self::pack( (array) ( $run['data'] ?? array() ) ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A run row as the run reads it.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate_run( array $row ) {
		$data = self::unpack( $row['data'] ?? null );
		return array(
			'id'          => (int) $row['id'],
			'status'      => (string) $row['status'],
			'taxonomy'    => (string) $row['taxonomy'],
			'created_at'  => (string) $row['created_at'],
			'created_by'  => (string) $row['created_by'],
			'finished_at' => isset( $row['finished_at'] ) ? (string) $row['finished_at'] : null,
			'data'        => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * A run.
	 *
	 * @param int $id Run id.
	 * @return array|null
	 */
	public function run_get( $id ) {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'runs' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? self::hydrate_run( $row ) : null;
	}

	/**
	 * Change a run.
	 *
	 * @param int   $id     Run id.
	 * @param array $fields status, finished_at, data.
	 * @return bool
	 */
	public function run_update( $id, array $fields ) {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return false;
		}
		$set = array();
		foreach ( array( 'status', 'finished_at' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$set[ $key ] = $fields[ $key ];
			}
		}
		if ( array_key_exists( 'data', $fields ) ) {
			$set['data'] = self::pack( (array) $fields['data'] );
		}
		return $set && false !== $wpdb->update( self::table( 'runs' ), $set, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a run and its items.
	 *
	 * @param int $id Run id.
	 * @return bool
	 */
	public function run_delete( $id ) {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return false;
		}
		$wpdb->delete( self::table( 'items' ), array( 'run_id' => (int) $id ) );
		return false !== $wpdb->delete( self::table( 'runs' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Every run, the latest first.
	 *
	 * @return array[]
	 */
	public function runs() {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table( 'runs' ) . ' ORDER BY id DESC LIMIT 20', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[] = self::hydrate_run( $row );
		}
		return $out;
	}

	/**
	 * Add items to a run, in order.
	 *
	 * @param int     $run_id Run id.
	 * @param array[] $items  kind, object_id, object_key, state, before, after.
	 * @return bool
	 */
	public function items_add( $run_id, array $items ) {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return false;
		}
		foreach ( $items as $item ) {
			$ok = $wpdb->insert(
				self::table( 'items' ),
				array(
					'run_id'      => (int) $run_id,
					'kind'        => (string) $item['kind'],
					'object_id'   => (int) ( $item['object_id'] ?? 0 ),
					'object_key'  => substr( (string) ( $item['object_key'] ?? '' ), 0, 191 ),
					'state'       => (int) ( $item['state'] ?? 0 ),
					'before_data' => self::pack( $item['before'] ?? null ),
					'after_data'  => self::pack( $item['after'] ?? array() ),
				)
			);
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The next items of a run in one of the states.
	 *
	 * @param int   $run_id  Run id.
	 * @param int[] $states  States.
	 * @param int   $limit   At most.
	 * @param bool  $reverse The last first.
	 * @return array[]
	 */
	public function items_next( $run_id, array $states, $limit, $reverse = false ) {
		global $wpdb;
		$states = array_values( array_map( 'intval', $states ) );
		if ( ! $this->has_record() || ! $states ) {
			return array();
		}
		$in  = implode( ',', $states );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'items' ) . " WHERE run_id = %d AND state IN ({$in}) ORDER BY id " . ( $reverse ? 'DESC' : 'ASC' ) . ' LIMIT %d', (int) $run_id, max( 1, (int) $limit ) ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array(
				'id'         => (int) $row['id'],
				'run_id'     => (int) $row['run_id'],
				'kind'       => (string) $row['kind'],
				'object_id'  => (int) $row['object_id'],
				'object_key' => (string) $row['object_key'],
				'state'      => (int) $row['state'],
				'before'     => self::unpack( $row['before_data'] ),
				'after'      => self::unpack( $row['after_data'] ),
				'note'       => (string) $row['note'],
			);
		}
		return $out;
	}

	/**
	 * Change an item.
	 *
	 * @param int   $id     Item id.
	 * @param array $fields state, before, note.
	 * @return bool
	 */
	public function item_update( $id, array $fields ) {
		global $wpdb;
		if ( ! $this->has_record() ) {
			return false;
		}
		$set = array();
		if ( array_key_exists( 'state', $fields ) ) {
			$set['state'] = (int) $fields['state'];
		}
		if ( array_key_exists( 'note', $fields ) ) {
			$set['note'] = substr( (string) $fields['note'], 0, 40 );
		}
		if ( array_key_exists( 'before', $fields ) ) {
			$set['before_data'] = self::pack( $fields['before'] );
		}
		return $set && false !== $wpdb->update( self::table( 'items' ), $set, array( 'id' => (int) $id ) );
	}

	/**
	 * How many items a run has in each state.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,int>
	 */
	public function items_count( $run_id ) {
		global $wpdb;
		$out = array();
		if ( ! $this->has_record() ) {
			return $out;
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS items FROM ' . self::table( 'items' ) . ' WHERE run_id = %d GROUP BY state', (int) $run_id ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ (int) $row['state'] ] = (int) $row['items'];
		}
		return $out;
	}

	/**
	 * Take the lock: one row, inserted — the database lets only one
	 * request do that. A lock a timeout left behind is taken over.
	 *
	 * @return bool
	 */
	public function lock() {
		global $wpdb;

		$now = time();
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . $wpdb->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) $now ) ) ) {
			return true;
		}
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', (string) $now, self::LOCK_OPTION, $now - self::LOCK_SECONDS ) );
	}

	/**
	 * Give the lock back.
	 */
	public function unlock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );
	}

	/**
	 * Drop what is cached of the sizes: the section's own transients,
	 * the Packing section's memoised list, WooCommerce's attribute and
	 * product transients.
	 */
	public function flush() {
		Stats_Sales::flush();
		Store::clear_species_cache();
		if ( class_exists( '\FisHotel\Misc\Sections\Packing\Size_List' ) ) {
			\FisHotel\Misc\Sections\Packing\Size_List::reset();
		}
		delete_transient( 'wc_attribute_taxonomies' );
		delete_transient( 'wc_term_counts' );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
	}

	/* ============================================================== */
	/*  After a tidy-up                                                */
	/* ============================================================== */

	/**
	 * The "Sizes to fill in" list: the products a tidy-up took a size
	 * off, while a variation it took it off still has none.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array[] product id → id, title, sizes (variation id → slug, label).
	 */
	public function fill_list( $taxonomy ) {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT pm.post_id, pm.meta_value, p.post_title FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.post_title ASC, p.ID ASC LIMIT %d", Sizes_Tidy::FILL_META, self::FILL_LIMIT ), ARRAY_A );
		$want = array();
		$vids = array();
		foreach ( $rows as $row ) {
			$entries = maybe_unserialize( $row['meta_value'] );
			foreach ( is_array( $entries ) ? $entries : array() as $vid => $entry ) {
				if ( (int) $vid > 0 && is_array( $entry ) ) {
					$want[ (int) $row['post_id'] ]['title']             = (string) $row['post_title'];
					$want[ (int) $row['post_id'] ]['sizes'][ (int) $vid ] = array(
						'slug'  => (string) ( $entry['slug'] ?? '' ),
						'label' => (string) ( $entry['label'] ?? '' ),
					);
					$vids[]                                             = (int) $vid;
				}
			}
		}
		if ( ! $vids ) {
			return array();
		}

		// A variation that is still there and still has no size.
		$open = array();
		foreach ( array_chunk( $vids, 500 ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT v.ID, pm.meta_value FROM ' . $wpdb->posts . ' v LEFT JOIN ' . $wpdb->postmeta . " pm ON pm.post_id = v.ID AND pm.meta_key = %s WHERE v.ID IN ({$in}) AND v.post_type = 'product_variation' AND v.post_status IN ('publish','private')", 'attribute_' . (string) $taxonomy ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( '' === (string) $row['meta_value'] ) {
					$open[ (int) $row['ID'] ] = true;
				}
			}
		}

		$out = array();
		foreach ( $want as $pid => $product ) {
			$sizes = array_intersect_key( $product['sizes'], $open );
			if ( $sizes ) {
				$out[ $pid ] = array(
					'id'    => $pid,
					'title' => $product['title'],
					'sizes' => $sizes,
				);
			}
		}
		return $out;
	}

	/**
	 * Tick a product off the "Sizes to fill in" list.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function fill_dismiss( $product_id ) {
		return (int) $product_id > 0 && 'product' === get_post_type( (int) $product_id ) && delete_post_meta( (int) $product_id, Sizes_Tidy::FILL_META );
	}

	/**
	 * How many variations that are for sale today carry each size.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param string[] $slugs    Slugs.
	 * @return array<string,int>
	 */
	public function retiring_counts( $taxonomy, array $slugs ) {
		global $wpdb;

		$slugs = array_values( array_map( 'strval', $slugs ) );
		if ( ! $slugs ) {
			return array();
		}
		$in     = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$params = array_merge( array( 'attribute_' . (string) $taxonomy ), $slugs );
		$out    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT pm.meta_value AS slug, COUNT(*) AS fish FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . " v ON v.ID = pm.post_id AND v.post_type = 'product_variation' AND v.post_status = 'publish' INNER JOIN " . $wpdb->posts . " p ON p.ID = v.post_parent AND p.post_status = 'publish' INNER JOIN " . $wpdb->postmeta . " pr ON pr.post_id = v.ID AND pr.meta_key = '_price' AND pr.meta_value <> '' LEFT JOIN " . $wpdb->postmeta . " st ON st.post_id = v.ID AND st.meta_key = '_stock_status' WHERE pm.meta_key = %s AND pm.meta_value IN ({$in}) AND (st.meta_value IS NULL OR st.meta_value <> 'outofstock') GROUP BY pm.meta_value", $params ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$out[ (string) $row['slug'] ] = (int) $row['fish'];
		}
		return $out;
	}
}
