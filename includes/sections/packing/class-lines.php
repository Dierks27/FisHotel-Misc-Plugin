<?php
/**
 * An order's fish lines — what has to be bagged.
 *
 * The lines are read the way the Batch plugin's Ship Day pick list reads
 * them, so the Packing box and the pick list agree on what is a fish:
 *
 *   - only product lines; fees, shipping and the rest never count;
 *   - a line that already shipped or never ships (the theme's
 *     `_fishotel_line_status` = shipped / na) and a line that is fully
 *     refunded are skipped; a partly refunded line counts what is left;
 *   - an invoice credit (`_fh_invoice_credit`) is money, not a fish;
 *   - a line flagged `_fh_invoice_fish` (one fish of an itemised invoice)
 *     and a product-less line with `_fishotel_fish_id` (a batch-invoice
 *     fish) are fish;
 *   - an Invoice product kept as one line is opened up: its fish come from
 *     the `_fh_invoice_lines` snapshot on the line, else from the invoice's
 *     description through the Batch plugin or the theme when one of them
 *     is there — and when nothing can read it, the line is reported as a
 *     fish line without a bag size, never silently dropped;
 *   - otherwise a line is a fish when its product (the parent of a
 *     variation) is in a fish category — the Ship Day list when the Batch
 *     plugin has it, else `quarantined-fish` and `inverts` under the same
 *     filter (fishotel_ship_day_fish_categories) — OR when its product
 *     carries a Bag size of its own: something the owner gave a bag to is
 *     bagged, whatever its category;
 *   - everything else is not a fish: ignored, and named as ignored.
 *
 * A combined box (the theme's fulfillment order) is read through its live
 * source orders, as the pick list does; when no source loads, its own
 * copied lines are used. An order that went INTO a combined box says so
 * (`packed_with`): its box is the combined one.
 *
 * Nothing here writes anything.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lines
 */
class Lines {

	/**
	 * Order / line meta written by the theme and the Batch plugin (read
	 * only, through get_meta()).
	 */
	const META_LINE_STATUS    = '_fishotel_line_status';
	const META_FISH_ID        = '_fishotel_fish_id';
	const META_INV_FISH       = '_fh_invoice_fish';
	const META_INV_LINES      = '_fh_invoice_lines';
	const META_INV_CREDIT     = '_fh_invoice_credit';
	const META_IS_FULFILLMENT = '_fishotel_is_fulfillment';
	const META_SOURCES        = '_fishotel_fulfillment_sources';
	const META_FULFILLED_BY   = '_fishotel_fulfilled_by_order';

	/**
	 * Source-order statuses that never ship.
	 *
	 * @var string[]
	 */
	const DEAD_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	const KIND_FISH    = 'fish';
	const KIND_INVOICE = 'invoice';
	const KIND_CREDIT  = 'credit';
	const KIND_OTHER   = 'other';

	/**
	 * Product category slugs whose products are fish — the Ship Day list
	 * when the Batch plugin has it, else the same default under the same
	 * filter (as the Today section does).
	 *
	 * @return string[]
	 */
	public static function fish_categories() {
		if ( class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'fish_categories' ) ) ) {
			try {
				$cats = (array) \FisHotel_Ship_Day::fish_categories();
			} catch ( \Throwable $e ) {
				$cats = array( 'quarantined-fish', 'inverts' );
			}
		} else {
			$cats = apply_filters( 'fishotel_ship_day_fish_categories', array( 'quarantined-fish', 'inverts' ) );
		}

		$out = array();
		foreach ( (array) $cats as $cat ) {
			$cat = is_scalar( $cat ) ? strtolower( trim( (string) $cat ) ) : '';
			if ( '' !== $cat && ! in_array( $cat, $out, true ) ) {
				$out[] = $cat;
			}
		}

		return $out;
	}

	/**
	 * True for the ways a "1" flag arrives from meta: 1, '1', true, 'yes',
	 * 'true', 'on'.
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		if ( true === $value ) {
			return true;
		}
		return is_scalar( $value ) && in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * What one order line is (pure) — the pick list's order of questions.
	 *
	 * @param array    $meta            The line's meta by key ('' when absent).
	 * @param string[] $category_slugs  Its product's category slugs (the parent's for a variation).
	 * @param string   $name            The line's name.
	 * @param bool     $has_product     Whether the line still has a product.
	 * @param string[] $fish_categories fish_categories().
	 * @param bool     $has_own_bag     Whether its product carries a Bag size of its own.
	 * @return string KIND_*.
	 */
	public static function kind( array $meta, array $category_slugs, $name, $has_product, array $fish_categories, $has_own_bag = false ) {
		if ( self::truthy( $meta[ self::META_INV_CREDIT ] ?? '' ) ) {
			return self::KIND_CREDIT;
		}
		if ( self::truthy( $meta[ self::META_INV_FISH ] ?? '' ) ) {
			return self::KIND_FISH;
		}

		$slugs = array();
		foreach ( $category_slugs as $slug ) {
			$slugs[] = strtolower( trim( (string) $slug ) );
		}
		if ( in_array( 'invoices', $slugs, true ) || 1 === preg_match( '/\bInvoice\b/i', (string) $name ) ) {
			return self::KIND_INVOICE;
		}
		if ( array_intersect( $slugs, $fish_categories ) ) {
			return self::KIND_FISH;
		}
		if ( ! $has_product && is_scalar( $meta[ self::META_FISH_ID ] ?? null ) && '' !== trim( (string) $meta[ self::META_FISH_ID ] ) ) {
			return self::KIND_FISH;
		}
		if ( $has_own_bag ) {
			return self::KIND_FISH;
		}

		return self::KIND_OTHER;
	}

	/**
	 * The fish of an Invoice line kept whole, from its `_fh_invoice_lines`
	 * snapshot (pure): a JSON string or an array of { name, qty, kind,
	 * product_id, size }. Credits and notes are not fish. Null when the
	 * snapshot is absent or unreadable.
	 *
	 * @param mixed $json The meta value.
	 * @return array[]|null name, qty, product_id, size.
	 */
	public static function invoice_fish( $json ) {
		if ( is_array( $json ) ) {
			$data = $json;
		} else {
			if ( ! is_string( $json ) || '' === trim( $json ) ) {
				return null;
			}
			$data = json_decode( trim( $json ), true );
			if ( ! is_array( $data ) ) {
				return null;
			}
		}
		if ( isset( $data['name'] ) || isset( $data['kind'] ) ) {
			$data = array( $data ); // One object where a list was expected.
		}

		$out = array();
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name = isset( $entry['name'] ) && is_scalar( $entry['name'] ) ? trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $entry['name'] ) ) ) : '';
			$kind = isset( $entry['kind'] ) && is_scalar( $entry['kind'] ) ? strtolower( trim( (string) $entry['kind'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( '' === $kind ) {
				$negative = isset( $entry['price'] ) && is_numeric( $entry['price'] ) && (float) $entry['price'] < 0;
				$kind     = ( $negative || 1 === preg_match( '/^credit\b/i', $name ) ) ? 'credit' : 'fish';
			}
			if ( 'fish' !== $kind ) {
				continue;
			}
			$size = '';
			foreach ( array( 'size_slug', 'size' ) as $key ) {
				if ( '' === $size && isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && ! is_bool( $entry[ $key ] ) ) {
					$size = trim( (string) $entry[ $key ] );
				}
			}
			$out[] = array(
				'name'       => $name,
				'qty'        => isset( $entry['qty'] ) && is_numeric( $entry['qty'] ) ? max( 1, (int) $entry['qty'] ) : 1,
				'product_id' => isset( $entry['product_id'] ) && is_numeric( $entry['product_id'] ) ? max( 0, (int) $entry['product_id'] ) : 0,
				'size'       => $size,
			);
		}

		return $out;
	}

	/**
	 * Category slugs of a product (its parent's for a variation).
	 *
	 * @param object|null $product Product.
	 * @return string[]
	 */
	public static function category_slugs( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$id = (int) $product->get_id();
		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$id = (int) $product->get_parent_id();
		}

		$terms = get_the_terms( $id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->slug ) ) {
				$slugs[] = (string) $term->slug;
			}
		}

		return $slugs;
	}

	/**
	 * The source order ids a combined box lists.
	 *
	 * @param object $order Order.
	 * @return int[]
	 */
	public static function source_ids( $order ) {
		$raw = $order->get_meta( self::META_SOURCES );
		$ids = is_array( $raw ) ? $raw : explode( ',', is_scalar( $raw ) ? (string) $raw : '' );
		$own = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$out = array();
		foreach ( $ids as $id ) {
			$id = is_scalar( $id ) ? (int) trim( (string) $id ) : 0;
			if ( $id > 0 && $id !== $own && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether an order is a combined box.
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	public static function is_fulfillment( $order ) {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) && '1' === (string) self::scalar( $order->get_meta( self::META_IS_FULFILLMENT ) );
	}

	/**
	 * The combined box an order went into (0 = none).
	 *
	 * @param object $order Order.
	 * @return int
	 */
	public static function packed_with( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || self::is_fulfillment( $order ) ) {
			return 0;
		}
		$id = (int) self::scalar( $order->get_meta( self::META_FULFILLED_BY ) );
		return $id > 0 && ( ! method_exists( $order, 'get_id' ) || $id !== (int) $order->get_id() ) ? $id : 0;
	}

	/**
	 * A meta value as trimmed text ('' for arrays, objects and null).
	 *
	 * @param mixed $value Meta value.
	 * @return string
	 */
	public static function scalar( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Everything to bag for an order: its fish lines with their bags, what
	 * is ignored and what is skipped.
	 *
	 * @param object $order    WC_Order.
	 * @param array  $settings Settings.
	 * @param array  $standard Size_List::standard().
	 * @return array{lines: array[], ignored: array[], skipped: array[], is_fulfillment: bool, sources: int[], packed_with: int, all_sources_dead: bool}
	 */
	public static function for_order( $order, array $settings, array $standard ) {
		$out = array(
			'lines'            => array(),
			'ignored'          => array(),
			'skipped'          => array(),
			'is_fulfillment'   => self::is_fulfillment( $order ),
			'sources'          => array(),
			'packed_with'      => self::packed_with( $order ),
			'all_sources_dead' => false,
		);

		if ( ! $out['is_fulfillment'] ) {
			return self::read( $order, $settings, $standard, $out );
		}

		// A combined box: its live source orders carry the lines.
		$resolved = 0;
		if ( function_exists( 'wc_get_order' ) ) {
			foreach ( self::source_ids( $order ) as $source_id ) {
				$source = wc_get_order( $source_id );
				if ( ! is_object( $source ) || ! method_exists( $source, 'get_items' ) ) {
					continue;
				}
				$resolved++;
				if ( method_exists( $source, 'has_status' ) && $source->has_status( self::DEAD_STATUSES ) ) {
					continue;
				}
				$out['sources'][] = (int) $source_id;
				$out              = self::read( $source, $settings, $standard, $out );
			}
		}
		if ( 0 === $resolved ) {
			// No source loads: the copies on the combined box are all there is.
			$out = self::read( $order, $settings, $standard, $out );
		} elseif ( ! $out['sources'] ) {
			// Every source loaded but is cancelled / refunded / failed: nothing ships.
			$out['all_sources_dead'] = true;
		}

		return $out;
	}

	/**
	 * Read one order's product lines into the result.
	 *
	 * @param object $order    WC_Order.
	 * @param array  $settings Settings.
	 * @param array  $standard Size_List::standard().
	 * @param array  $out      The result so far.
	 * @return array
	 */
	private static function read( $order, array $settings, array $standard, array $out ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$fish     = self::fish_categories();
		$items    = (array) $order->get_items( 'line_item' );

		// The invoices whose fish are itemised on this order are not opened up again.
		$itemised = array();
		foreach ( $items as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) && self::truthy( $item->get_meta( self::META_INV_FISH ) ) ) {
				$invoice = (int) self::scalar( $item->get_meta( '_fh_invoice_product_id' ) );
				if ( $invoice > 0 ) {
					$itemised[ $invoice ] = true;
				}
			}
		}

		foreach ( $items as $item_key => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}

			$item_id = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_key;
			$name    = method_exists( $item, 'get_name' ) ? trim( (string) $item->get_name() ) : '';
			$name    = '' !== $name ? $name : __( '(unnamed item)', 'fishotel-misc-plugin' );
			$meta    = array();
			foreach ( array( self::META_LINE_STATUS, self::META_FISH_ID, self::META_INV_FISH, self::META_INV_LINES, self::META_INV_CREDIT ) as $key ) {
				$value        = method_exists( $item, 'get_meta' ) ? $item->get_meta( $key ) : '';
				$meta[ $key ] = ( is_scalar( $value ) || is_array( $value ) ) ? $value : '';
			}

			$qty  = (int) $item->get_quantity();
			$base = array(
				'item_id'  => $item_id,
				'order_id' => $order_id,
				'name'     => $name,
				'qty'      => $qty,
			);

			$status = self::scalar( $meta[ self::META_LINE_STATUS ] );
			if ( 'shipped' === $status || 'na' === $status ) {
				$out['skipped'][] = $base + array( 'reason' => 'shipped' === $status ? __( 'already shipped', 'fishotel-misc-plugin' ) : __( 'does not ship', 'fishotel-misc-plugin' ) );
				continue;
			}
			if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
				$qty -= abs( (int) $order->get_qty_refunded_for_item( $item_id ) ); // WooCommerce reports refunds as negatives.
			}
			if ( $qty <= 0 ) {
				$out['skipped'][] = $base + array( 'reason' => __( 'refunded', 'fishotel-misc-plugin' ) );
				continue;
			}
			$base['qty'] = $qty;

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$product = is_object( $product ) ? $product : null;
			$own     = null !== $product && null !== Bags::find( $settings['bags'], Bags::own_value( $product ) );
			$kind    = self::kind( $meta, self::category_slugs( $product ), $name, null !== $product, $fish, $own );

			if ( self::KIND_CREDIT === $kind ) {
				continue; // Money, not a thing in the box.
			}

			if ( self::KIND_OTHER === $kind ) {
				$out['ignored'][] = $base + array(
					'reason' => null === $product
						? __( 'no product — nothing says it is a fish', 'fishotel-misc-plugin' )
						: __( 'not a fish', 'fishotel-misc-plugin' ),
				);
				continue;
			}

			if ( self::KIND_INVOICE === $kind ) {
				$product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
				$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
				if ( ( $product_id > 0 && isset( $itemised[ $product_id ] ) ) || ( $variation_id > 0 && isset( $itemised[ $variation_id ] ) ) ) {
					continue; // Its fish are already lines of their own.
				}
				$out = self::open_invoice( $out, $base, $meta[ self::META_INV_LINES ], $product, $settings, $standard );
				continue;
			}

			$bag     = Bags::for_item( $item, $settings, $standard );
			$per_bag = Bags::fish_per_bag( $product, $item );
			$size    = $bag ? $bag['size'] : ( null !== $product ? Bags::product_size( $product, $standard ) : '' );
			if ( '' === $size ) {
				$size = Bags::item_size( $item, $standard );
			}

			$out['lines'][] = $base + array(
				'fish_per_bag' => $per_bag,
				'bags'         => $bag ? Suggest::bags_for( $qty, $per_bag ) : 0,
				'bag'          => $bag,
				'size'         => $size,
				'size_label'   => '' !== $size ? Size_List::label( $standard['sizes'], $size ) : '',
				'flag'         => 'backordered' === $status ? 'backordered' : '',
				'from'         => '',
			);
		}

		return $out;
	}

	/**
	 * Open an Invoice line up into its fish.
	 *
	 * @param array       $out      The result so far.
	 * @param array       $base     item_id, order_id, name, qty of the Invoice line.
	 * @param mixed       $snapshot Its `_fh_invoice_lines` meta.
	 * @param object|null $product  The Invoice product.
	 * @param array       $settings Settings.
	 * @param array       $standard Size_List::standard().
	 * @return array
	 */
	private static function open_invoice( array $out, array $base, $snapshot, $product, array $settings, array $standard ) {
		$fish = self::invoice_fish( $snapshot );

		if ( null === $fish && null !== $product ) {
			$fish = self::invoice_fish_from_description( $product );
		}

		if ( null === $fish || ! $fish ) {
			// Nothing here can read it: say so rather than pack an order short.
			$out['lines'][] = $base + array(
				'fish_per_bag' => 1,
				'bags'         => 0,
				'bag'          => null,
				'size'         => '',
				'size_label'   => '',
				'flag'         => 'invoice',
				'from'         => '',
				'reason'       => null === $fish
					? __( 'an invoice whose fish cannot be read here', 'fishotel-misc-plugin' )
					: __( 'an invoice with no fish lines', 'fishotel-misc-plugin' ),
			);
			return $out;
		}

		foreach ( $fish as $row ) {
			$qty     = max( 1, (int) $row['qty'] ) * max( 1, (int) $base['qty'] );
			$line    = null;
			$bag     = null;
			$size    = '';
			$per_bag = 1;

			if ( $row['product_id'] > 0 && function_exists( 'wc_get_product' ) ) {
				$line = wc_get_product( $row['product_id'] );
				$line = is_object( $line ) ? $line : null;
			}
			if ( null !== $line ) {
				$bag     = Bags::for_product( $line, $settings, $standard );
				$size    = Bags::product_size( $line, $standard );
				$per_bag = Bags::fish_per_bag( $line, null );
			}
			if ( ! $bag && '' !== $row['size'] ) {
				$bag  = Bags::for_size( $row['size'], $settings, $standard );
				$size = $bag ? $bag['size'] : Size_List::match_value( $row['size'], $standard['sizes'] );
			}

			$out['lines'][] = array(
				'item_id'      => $base['item_id'],
				'order_id'     => $base['order_id'],
				'name'         => $row['name'],
				'qty'          => $qty,
				'fish_per_bag' => $per_bag,
				'bags'         => $bag ? Suggest::bags_for( $qty, $per_bag ) : 0,
				'bag'          => $bag,
				'size'         => $size,
				'size_label'   => '' !== $size ? Size_List::label( $standard['sizes'], $size ) : '',
				'flag'         => '',
				'from'         => $base['name'],
			);
		}

		return $out;
	}

	/**
	 * The fish of an Invoice product from its description — only through
	 * the Batch plugin's or the theme's own parser, when one of them is
	 * there. Null when neither is.
	 *
	 * @param object $product The Invoice product.
	 * @return array[]|null name, qty, product_id, size.
	 */
	private static function invoice_fish_from_description( $product ) {
		$rows = null;

		try {
			if ( class_exists( 'FisHotel_Invoice_Lines' ) && is_callable( array( 'FisHotel_Invoice_Lines', 'get_lines' ) ) ) {
				$rows = \FisHotel_Invoice_Lines::get_lines( $product );
			} elseif ( class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'parse_invoice_lines' ) ) && method_exists( $product, 'get_description' ) ) {
				$rows = \FisHotel_Ship_Day::parse_invoice_lines( (string) $product->get_description() );
			}
		} catch ( \Throwable $e ) {
			$rows = null;
		}

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// The theme says name / kind (fish, credit, note); the Batch plugin says
			// fish / flag and has already left the credits out — a line it could
			// not read fully still counts, so the order is never packed short.
			$name = self::scalar( $row['name'] ?? ( $row['fish'] ?? '' ) );
			$kind = strtolower( self::scalar( $row['kind'] ?? 'fish' ) );
			if ( '' === $name || 'fish' !== $kind ) {
				continue;
			}
			$out[] = array(
				'name'       => $name,
				'qty'        => isset( $row['qty'] ) && is_numeric( $row['qty'] ) ? max( 1, (int) $row['qty'] ) : 1,
				'product_id' => isset( $row['product_id'] ) && is_numeric( $row['product_id'] ) ? max( 0, (int) $row['product_id'] ) : 0,
				'size'       => '',
			);
		}

		return $out;
	}
}
