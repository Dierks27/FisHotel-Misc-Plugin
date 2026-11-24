<?php
/**
 * Product checks — the only file that touches $wpdb, and the only one
 * that writes the flags.
 *
 *   - counts(): how many products carry each flag (one grouped read) —
 *     and, for the "To fix" row, how many of them are for sale now
 *     (published and in stock: `sale`) — the Clean Room drafts, the 3rd-bag
 *     ticks; kept in a non-autoloaded option until a product is checked
 *     again;
 *   - refresh_shared(): the images featured on 3 or more products of
 *     different fish (one read, at the start of each sweep);
 *   - batch(): the next products a sweep checks, by id;
 *   - write(): a product's flags as post meta (Rules::meta(): a value, or
 *     deleted) — hidden meta, never shown to a customer.
 *
 * Every read is wrapped: a database error means an empty answer.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Store
 */
class Store {

	/**
	 * The option the counts are kept in (not autoloaded), and the one of
	 * the shared images.
	 */
	const COUNTS = 'fishotel_product_checks_counts';
	const SHARED = 'fishotel_product_checks_shared';

	/**
	 * The shape of the kept counts: the rule version and the shape
	 * together (a kept answer of another shape is read again).
	 *
	 * @var int
	 */
	const COUNTS_V = Rules::VERSION * 100 + 2;

	/**
	 * The statuses a product is counted in.
	 *
	 * @return string
	 */
	private static function statuses_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', Rules::LIVE_STATUSES ) ) . "'";
	}

	/**
	 * How many products carry each flag, the Clean Room drafts, and the
	 * products with the 3rd-bag tick — read once, then kept until a
	 * product is checked again.
	 *
	 * @return array flag | cleanroom | third → count; `sale` → flag → count of the products for sale now.
	 */
	public function counts() {
		$kept = get_option( self::COUNTS, null );
		if ( is_array( $kept ) && isset( $kept['v'] ) && self::COUNTS_V === (int) $kept['v'] && isset( $kept['counts'] ) && is_array( $kept['counts'] ) ) {
			return $kept['counts'];
		}

		global $wpdb;
		$out         = array_fill_keys( array_merge( Rules::FLAGS, array( 'cleanroom', 'third' ) ), 0 );
		$out['sale'] = array_fill_keys( Rules::FLAGS, 0 );
		if ( ! is_object( $wpdb ) ) {
			return $out;
		}
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS n FROM ' . $wpdb->postmeta . ' pm JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status IN (" . self::statuses_sql() . ') WHERE pm.meta_key LIKE %s GROUP BY pm.meta_key', $wpdb->esc_like( Rules::PREFIX ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$flag = substr( (string) $row['meta_key'], strlen( Rules::PREFIX ) );
				if ( in_array( $flag, Rules::FLAGS, true ) ) {
					$out[ $flag ] = (int) $row['n'];
				}
			}
			$sale = $wpdb->get_results( $wpdb->prepare( 'SELECT pm.meta_key, COUNT(DISTINCT pm.post_id) AS n FROM ' . $wpdb->postmeta . ' pm JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status = 'publish' JOIN " . $wpdb->postmeta . ' ss ON ss.post_id = p.ID AND ss.meta_key = %s AND ss.meta_value = %s WHERE pm.meta_key LIKE %s GROUP BY pm.meta_key', '_stock_status', 'instock', $wpdb->esc_like( Rules::PREFIX ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $sale ) ? $sale : array() as $row ) {
				$flag = substr( (string) $row['meta_key'], strlen( Rules::PREFIX ) );
				if ( in_array( $flag, Rules::FLAGS, true ) ) {
					$out['sale'][ $flag ] = (int) $row['n'];
				}
			}
			$out['cleanroom'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT p.ID) FROM ' . $wpdb->posts . ' p JOIN ' . $wpdb->postmeta . " pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'product' AND p.post_status = 'draft'", '_fh_cleanroom_fish' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$out['third']     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT pm.post_id) FROM ' . $wpdb->postmeta . ' pm JOIN ' . $wpdb->posts . " p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status IN (" . self::statuses_sql() . ') WHERE pm.meta_key = %s AND pm.meta_value = %s', '_fh_bag_layers', '3' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return $out;
		}

		update_option(
			self::COUNTS,
			array(
				'v'      => self::COUNTS_V,
				'counts' => $out,
			),
			false
		);
		return $out;
	}

	/**
	 * Forget the counts (a product was checked again).
	 */
	public function forget_counts() {
		delete_option( self::COUNTS );
	}

	/**
	 * The images standing in for many different products — featured on 3
	 * or more products (in a checked status) whose scientific names are not
	 * all the same (Rules::shared_images()) — worked out again now and kept
	 * for the checks of one product.
	 *
	 * @return int[]
	 */
	public function refresh_shared() {
		global $wpdb;
		$rows = array();
		if ( is_object( $wpdb ) ) {
			try {
				$statuses = self::statuses_sql();
				$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT t.meta_value AS thumb, p.ID AS id, COALESCE(NULLIF(TRIM(s.meta_value), \'\'), p.post_excerpt) AS name FROM ' . $wpdb->postmeta . ' t JOIN ' . $wpdb->posts . " p ON p.ID = t.post_id AND p.post_type = 'product' AND p.post_status IN (" . $statuses . ') LEFT JOIN ' . $wpdb->postmeta . ' s ON s.post_id = p.ID AND s.meta_key = %s WHERE t.meta_key = %s AND t.meta_value IN (SELECT x.meta_value FROM (SELECT pm.meta_value FROM ' . $wpdb->postmeta . ' pm JOIN ' . $wpdb->posts . " q ON q.ID = pm.post_id AND q.post_type = 'product' AND q.post_status IN (" . $statuses . ') WHERE pm.meta_key = %s AND pm.meta_value > 0 GROUP BY pm.meta_value HAVING COUNT(*) >= 3) x)', '_fh_scientific_name', '_thumbnail_id', '_thumbnail_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			} catch ( \Throwable $e ) {
				$rows = array();
			}
		}
		$ids = Rules::shared_images( is_array( $rows ) ? $rows : array() );
		update_option( self::SHARED, $ids, false );
		return $ids;
	}

	/**
	 * The images featured on 3 or more products, as last worked out.
	 *
	 * @return int[]
	 */
	public function shared() {
		$ids = get_option( self::SHARED, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * The next products a sweep checks: ids above the cursor, every status
	 * but the trash and auto-drafts (a product moved out of a counted
	 * status loses its flags).
	 *
	 * @param int $after Cursor.
	 * @param int $limit How many.
	 * @return int[]
	 */
	public function batch( $after, $limit ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return array();
		}
		try {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $after, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			return array();
		}
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Write a product's flags: each key its value, or deleted. A value
	 * that is already stored is not written again.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $meta       Rules::meta().
	 * @return bool Whether anything changed.
	 */
	public function write( $product_id, array $meta ) {
		$changed = false;
		foreach ( $meta as $key => $value ) {
			$now = get_post_meta( (int) $product_id, $key, true );
			if ( null === $value ) {
				if ( '' !== $now && false !== $now ) {
					delete_post_meta( (int) $product_id, $key );
					$changed = true;
				}
			} elseif ( (string) $now !== (string) $value ) {
				update_post_meta( (int) $product_id, $key, (string) $value );
				$changed = true;
			}
		}
		return $changed;
	}
}
