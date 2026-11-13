<?php
/**
 * Clean Room stats, real sales — what customers ACTUALLY paid per fish and
 * year, read from WooCommerce's own order tables. Everything here is pure
 * (arrays in, arrays out) except the two small WordPress helpers at the
 * top: the list of order statuses that count and the cache flush.
 *
 * WHERE THE NUMBERS COME FROM. WooCommerce keeps one row per order line of
 * every order and refund in wc_order_product_lookup (quantity, net
 * revenue — after discounts, before tax and shipping) and one row per
 * order and refund in wc_order_stats (status, date, parent), whatever the
 * order storage (HPOS on or off). Store::sales_rows() hands those rows
 * over joined; this class decides what counts:
 *
 *   - a row counts when its ORDER's status is in the list (statuses()) —
 *     WooCommerce's paid statuses plus the store's shipped-style ones; a
 *     refund row takes the status and the date of the order it refunds,
 *     so refunds are NET and a fish sold in December and refunded in
 *     January is simply not a December sale;
 *   - a refund line is netted into the order line it refunds
 *     (_refunded_item_id), so units and money are per original line;
 *   - money refunded without naming a line and a quantity cannot be taken
 *     off any fish: it is counted apart (refunds), never spread.
 *
 * WHICH FISH A LINE IS — in this order, and nothing else:
 *
 *   1. its product (or variation) is a species row's linked product;
 *   2. it is an invoice fish line the Invoices section knows
 *      (fishotel_invoices_for_order(), when that section is there) and
 *      that line names a species or a linked product;
 *   3. an EXACT lower-cased name: the species name, the title of a
 *      species' linked product, or a learned alias (name_map()) — for a
 *      shop product that is not linked, its title; for an invoice fish
 *      line (the theme's _fh_invoice_fish lines, or the "Includes"
 *      snapshot _fh_invoice_lines of an invoice kept as one line), the
 *      name typed on the invoice.
 *
 * Everything else is NOT MATCHED and listed with its units and its money
 * — shop products without a species, invoice names that are no species,
 * invoices kept as one line that cannot be split, lines without a
 * product, credits inside such invoices and an invoice's "Adjustment"
 * (money that the theme carries as a fish line; told by the Invoices
 * section's fishotel_invoices_is_adjustment()) — so matched + not
 * matched is always every paid line, and nothing is guessed. An invoice kept as one
 * line is split into its fish only when its lines carry prices that add
 * up to what was paid for it, to the cent; otherwise it stays whole,
 * under "not matched".
 *
 * rows() then sets the sales beside the supplier orders of the same year
 * (Stats::by_species()): units sold, actual revenue, the average price
 * paid beside the list price, the estimated revenue beside the actual,
 * and the actual profit = actual revenue − units sold × that year's
 * average landed cost per fish. A fish sold in a year in which none
 * landed has NO profit figure — the row says so (no_cost) and carries
 * the last earlier year's cost for reference only.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats_Sales
 */
class Stats_Sales {

	/**
	 * Option holding the order statuses that count as a sale (slugs
	 * without "wc-"); absent until Settings → Sales statuses is saved.
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_cleanroom_sales_statuses';

	/**
	 * Transient holding the ledgers already read, per year.
	 *
	 * @var string
	 */
	const TRANSIENT = 'fishotel_cleanroom_sales';

	/**
	 * The shape of the cached ledgers; a change here drops the old cache.
	 *
	 * @var int
	 */
	const CACHE_VERSION = 1;

	/**
	 * How long a year's ledger is kept (seconds): twelve hours — it is
	 * dropped sooner whenever an order changes status or WooCommerce
	 * updates its order tables.
	 *
	 * @var int
	 */
	const CACHE_TTL = 43200;

	/**
	 * Bounds of one read: rows per query, queries per year, the orders the
	 * Invoices section is asked about, the years a cache holds, the items
	 * a "not matched" list shows.
	 */
	const PAGE_ROWS          = 5000;
	const MAX_PAGES          = 40;
	const INVOICE_ORDERS_MAX = 1500;
	const CACHE_YEARS        = 8;
	const LIST_MAX           = 40;
	const STATUSES_MAX       = 40;

	/**
	 * WooCommerce's paid statuses, for a site where it cannot be asked.
	 *
	 * @var string[]
	 */
	const WOO_PAID = array( 'processing', 'completed' );

	/**
	 * Lines add up when they are within this of what was paid.
	 *
	 * @var float
	 */
	const TOLERANCE = 0.01;

	/**
	 * The theme's item meta (inc/invoice-lines.php): a fish line of an
	 * itemised invoice, the "Includes" snapshot of one kept whole, the
	 * invoice product either came from — and WooCommerce's pointer from a
	 * refund line to the line it refunds.
	 */
	const META_FISH       = '_fh_invoice_fish';
	const META_LINES      = '_fh_invoice_lines';
	const META_PRODUCT_ID = '_fh_invoice_product_id';
	const META_REFUNDED   = '_refunded_item_id';

	/**
	 * Why an invoice kept as one line was not split.
	 *
	 * @var string[]
	 */
	const UNSPLIT = array( 'no_lines', 'quantity', 'unpriced', 'mismatch' );

	/* ============================================================== */
	/*  WordPress: the statuses that count, the cache                  */
	/* ============================================================== */

	/**
	 * An order status as this class compares it: lower-cased, without the
	 * "wc-" WooCommerce stores it with.
	 *
	 * @param mixed $status Raw.
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = is_scalar( $status ) ? strtolower( trim( (string) $status ) ) : '';
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $status );
	}

	/**
	 * A list of statuses: normalised, unique, without empties.
	 *
	 * @param mixed $list Raw list.
	 * @return string[]
	 */
	public static function normalize_statuses( $list ) {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $status ) {
			$status = self::normalize_status( $status );
			if ( '' !== $status && ! in_array( $status, $out, true ) ) {
				$out[] = $status;
			}
			if ( count( $out ) >= self::STATUSES_MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The list a site starts with (pure): WooCommerce's paid statuses plus
	 * every registered status whose slug contains "ship".
	 *
	 * @param string[] $paid       wc_get_is_paid_statuses().
	 * @param string[] $registered Every registered status slug.
	 * @return string[]
	 */
	public static function default_statuses( array $paid, array $registered ) {
		$out = self::normalize_statuses( $paid );
		foreach ( self::normalize_statuses( $registered ) as $status ) {
			if ( false !== strpos( $status, 'ship' ) && ! in_array( $status, $out, true ) ) {
				$out[] = $status;
			}
		}
		return $out;
	}

	/**
	 * The order statuses WooCommerce has registered: slug (without "wc-")
	 * → label. Empty when WooCommerce is not active.
	 *
	 * @return array<string, string>
	 */
	public static function registered_statuses() {
		$out = array();
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $out;
		}
		foreach ( (array) wc_get_order_statuses() as $slug => $label ) {
			$slug = self::normalize_status( $slug );
			if ( '' !== $slug ) {
				$out[ $slug ] = is_scalar( $label ) ? (string) $label : $slug;
			}
		}
		return $out;
	}

	/**
	 * The statuses WooCommerce leaves out of its own reports (its
	 * Analytics setting, and whatever a theme or plugin adds through
	 * woocommerce_analytics_excluded_order_statuses — a wrapper order
	 * that copies the lines of other orders, for one). The Settings card
	 * marks them: ticking one would count such lines a second time.
	 *
	 * @return string[]
	 */
	public static function excluded_statuses() {
		$excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
		$excluded = apply_filters( 'woocommerce_analytics_excluded_order_statuses', is_array( $excluded ) ? $excluded : array() );
		return self::normalize_statuses( $excluded );
	}

	/**
	 * The statuses a site starts with: default_statuses() over what
	 * WooCommerce says now.
	 *
	 * @return string[]
	 */
	public static function suggested_statuses() {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : self::WOO_PAID;
		return self::default_statuses( $paid, array_keys( self::registered_statuses() ) );
	}

	/**
	 * The statuses that count as a sale: the saved list, else the
	 * suggested one.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		$stored = self::normalize_statuses( get_option( self::OPTION, array() ) );
		return $stored ? $stored : self::suggested_statuses();
	}

	/**
	 * Drop the cached ledgers — hooked to an order's status change and to
	 * WooCommerce's own updates of its order tables (which run a moment
	 * after the order is saved, often in another request). Every call
	 * deletes: a queue runner lives for minutes, and a cache rebuilt by a
	 * view in the meantime must not survive the next order it handles.
	 * With nothing cached that is one indexed look-up.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
	}

	/* ============================================================== */
	/*  The ledger of one year                                         */
	/* ============================================================== */

	/**
	 * An empty ledger.
	 *
	 * @param int      $year     The year.
	 * @param string[] $statuses The statuses that count.
	 * @return array
	 */
	public static function new_ledger( $year, array $statuses ) {
		return array(
			'v'         => self::CACHE_VERSION,
			'year'      => (int) $year,
			'statuses'  => self::normalize_statuses( $statuses ),
			'built_at'  => '',
			'rows'      => 0,
			'skipped'   => 0,
			'qty'       => 0,
			'revenue'   => 0.0,
			'truncated' => false,
			'failed'    => false,
			'products'  => array(),
			'items'     => array(),
			'named'     => array(),
			'unsplit'   => array(),
			'credits'   => array(
				'lines'   => 0,
				'revenue' => 0.0,
			),
			'refunds'   => array(
				'count'  => 0,
				'amount' => 0.0,
			),
			'invoices'  => array(
				'provider' => false,
				'orders'   => 0,
				'answered' => 0,
				'capped'   => false,
			),
		);
	}

	/**
	 * The order lines a page of rows points at: a refund row's is the line
	 * it refunds, any other row's is its own.
	 *
	 * @param array[] $rows Store::sales_rows().
	 * @return int[]
	 */
	public static function source_ids( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			$id = self::source_id( $row );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * The order line one row counts for.
	 *
	 * @param array $row row_id, refunded_item_id.
	 * @return int
	 */
	private static function source_id( array $row ) {
		$refunded = isset( $row['refunded_item_id'] ) && is_numeric( $row['refunded_item_id'] ) ? (int) $row['refunded_item_id'] : 0;
		return $refunded > 0 ? $refunded : (int) ( $row['row_id'] ?? 0 );
	}

	/**
	 * Add a page of lookup rows to a ledger: each row whose order is in a
	 * status that counts and dated in the ledger's year. A plain product
	 * line adds to its product (and variation); a line that is an invoice
	 * fish, an invoice kept whole, or has no product is kept per order
	 * line until finish() — netted with its refunds either way.
	 *
	 * @param array   $ledger  The ledger (by reference).
	 * @param array[] $rows    Store::sales_rows(): row_id, order_id, product_id, variation_id, qty, revenue,
	 *                         parent_id, status, date_created, parent_status, parent_date, refunded_item_id.
	 * @param array   $details Order line id → name, fish, lines, invoice_product_id (Store::sales_item_details()).
	 * @return int Rows counted.
	 */
	public static function add_rows( array &$ledger, array $rows, array $details ) {
		$counted = 0;

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$is_refund = (int) ( $row['parent_id'] ?? 0 ) > 0;
			$status    = self::normalize_status( $is_refund && isset( $row['parent_status'] ) && '' !== (string) $row['parent_status'] ? $row['parent_status'] : ( $row['status'] ?? '' ) );
			$date      = (string) ( $is_refund && ! empty( $row['parent_date'] ) ? $row['parent_date'] : ( $row['date_created'] ?? '' ) );

			if ( ! in_array( $status, $ledger['statuses'], true ) || (int) substr( $date, 0, 4 ) !== (int) $ledger['year'] ) {
				$ledger['skipped']++;
				continue;
			}

			$item_id  = self::source_id( $row );
			$order_id = $is_refund ? (int) $row['parent_id'] : (int) ( $row['order_id'] ?? 0 );
			$product  = max( 0, (int) ( $row['product_id'] ?? 0 ) );
			$variant  = max( 0, (int) ( $row['variation_id'] ?? 0 ) );
			$qty      = (int) ( $row['qty'] ?? 0 );
			$revenue  = (float) ( $row['revenue'] ?? 0 );
			$detail   = isset( $details[ $item_id ] ) && is_array( $details[ $item_id ] ) ? $details[ $item_id ] : array();
			$fish     = ! empty( $detail['fish'] );
			$lines    = isset( $detail['lines'] ) && is_string( $detail['lines'] ) && '' !== trim( $detail['lines'] ) ? $detail['lines'] : null;
			$invoice  = max( 0, (int) ( $detail['invoice_product_id'] ?? 0 ) );

			$counted++;
			$ledger['rows']++;
			$ledger['qty']     += $qty;
			$ledger['revenue'] += $revenue;

			if ( $product > 0 && ! $fish && null === $lines && 0 === $invoice ) {
				$slot = $product . ':' . $variant;
				if ( ! isset( $ledger['products'][ $slot ] ) ) {
					$ledger['products'][ $slot ] = array(
						'product_id'   => $product,
						'variation_id' => $variant,
						'qty'          => 0,
						'revenue'      => 0.0,
						'lines'        => 0,
					);
				}
				$ledger['products'][ $slot ]['qty']     += $qty;
				$ledger['products'][ $slot ]['revenue'] += $revenue;
				$ledger['products'][ $slot ]['lines']   += $is_refund ? 0 : 1;
				continue;
			}

			if ( ! isset( $ledger['items'][ $item_id ] ) ) {
				$ledger['items'][ $item_id ] = array(
					'order_id'           => $order_id,
					'product_id'         => $product,
					'variation_id'       => $variant,
					'qty'                => 0,
					'revenue'            => 0.0,
					'name'               => trim( (string) ( $detail['name'] ?? '' ) ),
					'fish'               => $fish,
					'lines'              => $lines,
					'invoice_product_id' => $invoice,
				);
			}
			$ledger['items'][ $item_id ]['qty']     += $qty;
			$ledger['items'][ $item_id ]['revenue'] += $revenue;
		}

		return $counted;
	}

	/**
	 * The orders that hold invoice lines — the ones the Invoices section
	 * is asked about.
	 *
	 * @param array $ledger A ledger before finish().
	 * @return int[]
	 */
	public static function invoice_orders( array $ledger ) {
		$out = array();
		foreach ( (array) ( $ledger['items'] ?? array() ) as $item ) {
			if ( ( ! empty( $item['fish'] ) || null !== $item['lines'] || (int) $item['invoice_product_id'] > 0 ) && (int) $item['order_id'] > 0 ) {
				$out[ (int) $item['order_id'] ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * The lines of the invoices an order paid, as this class reads them —
	 * from EXACTLY what fishotel_invoices_for_order() answers (the
	 * Invoices section, Invoices::for_order()): a list of invoices, each an
	 * array with `product_id` (the invoice product) and `lines`, each line
	 * an array with
	 *
	 *   kind        fish | credit | discount | note (a line of any other
	 *               kind is not one of that section's and is dropped);
	 *   name        the name it goes by ("Credit: reason", "Discount",
	 *               "Adjustment", or the fish);
	 *   qty         how many (a fish);
	 *   unit_price  the price PER FISH, null when none was typed;
	 *   amount      what the line adds to its invoice, signed: qty × price
	 *               for a fish, negative for a credit and a "Discount",
	 *               positive for an "Adjustment" up to a stated price;
	 *   species_id, product_id, variation_id   what the line links to.
	 *
	 * Here a credit and a discount are both a credit (money, never a
	 * fish), carried by that signed amount — so the parts of an invoice
	 * add up to what was paid for it. Anything that is not this shape is
	 * no line: nothing is guessed.
	 *
	 * @param mixed $invoices What fishotel_invoices_for_order() answered.
	 * @return array[] kind, name, key, qty, price, total, species_id, product_id, variation_id, invoice_product_id.
	 */
	public static function invoice_parts( $invoices ) {
		$out = array();
		foreach ( is_array( $invoices ) ? $invoices : array() as $invoice ) {
			if ( ! is_array( $invoice ) || ! isset( $invoice['lines'] ) || ! is_array( $invoice['lines'] ) ) {
				continue;
			}
			$product = self::first_int( $invoice, array( 'product_id' ) );
			foreach ( $invoice['lines'] as $line ) {
				$part = is_array( $line ) ? self::invoice_part( $line, $product ) : null;
				if ( null !== $part ) {
					$out[] = $part;
				}
			}
		}
		return $out;
	}

	/**
	 * One line of the Invoices section as a part, or null when it is not
	 * one (no name, no kind of that section's).
	 *
	 * @param array $line    The line (Invoices::contract_line()).
	 * @param int   $invoice The invoice product it came with (0 = unknown).
	 * @return array|null
	 */
	private static function invoice_part( array $line, $invoice ) {
		$name = isset( $line['name'] ) && is_scalar( $line['name'] ) ? self::clean_name( $line['name'] ) : '';
		$kind = isset( $line['kind'] ) && is_string( $line['kind'] ) ? $line['kind'] : '';
		if ( '' === $name || ! in_array( $kind, array( 'fish', 'credit', 'discount', 'note' ), true ) ) {
			return null;
		}

		$price  = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null;
		$amount = isset( $line['amount'] ) && is_numeric( $line['amount'] ) ? (float) $line['amount'] : null;
		$qty    = 1;

		if ( 'fish' === $kind ) {
			$qty   = isset( $line['qty'] ) && is_numeric( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1;
			$total = null === $price ? null : ( null !== $amount ? $amount : $qty * $price ); // A fish without a price has no total: 0 there is "not typed".
		} elseif ( 'note' === $kind ) {
			$price = null;
			$total = null;
		} else {
			// A credit, a "Discount" (both negative) or an "Adjustment" (positive): what it adds, as it is.
			$kind  = 'credit';
			$total = $amount;
			$price = $amount;
		}

		return self::part( $kind, $name, $qty, $price, $total, self::first_int( $line, array( 'species_id' ) ), 'fish' === $kind ? self::first_int( $line, array( 'product_id' ) ) : 0, 'fish' === $kind ? self::first_int( $line, array( 'variation_id' ) ) : 0, (int) $invoice );
	}

	/**
	 * The lines of an invoice kept as ONE order line, from EXACTLY what
	 * the theme writes beside it (`_fh_invoice_lines`,
	 * FisHotel_Invoice_Lines::attach_fallback()): a JSON list of
	 * { name, qty, price, kind, product_id } — price per fish, kind fish /
	 * credit / note (without one, the theme's own rule: a negative price
	 * or a name starting "Credit" is a credit, the rest fish), a credit's
	 * price money off, a note without money.
	 *
	 * The theme knows no other kind, so the Invoices section's
	 * "Adjustment" arrives here as a fish row; it is told by its name
	 * (is_adjustment()) and counted as what it is — money added, a credit
	 * with a positive total — never as a fish sold.
	 *
	 * @param mixed $json The item meta: the JSON, or the list already decoded.
	 * @return array[] As invoice_parts(); invoice_product_id is 0 (the order line says which invoice).
	 */
	public static function snapshot_parts( $json ) {
		if ( is_string( $json ) ) {
			$json = '' !== trim( $json ) ? json_decode( $json, true ) : null;
		}
		$out = array();
		foreach ( is_array( $json ) ? $json : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? self::clean_name( $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$price   = isset( $row['price'] ) && is_numeric( $row['price'] ) ? (float) $row['price'] : null;
			$kind    = isset( $row['kind'] ) && is_string( $row['kind'] ) ? strtolower( trim( $row['kind'] ) ) : '';
			$product = self::first_int( $row, array( 'product_id' ) );
			if ( ! in_array( $kind, array( 'fish', 'credit', 'note' ), true ) ) {
				$kind = ( null !== $price && $price < 0 ) || 1 === preg_match( '/^credit\b/iu', $name ) ? 'credit' : 'fish';
			}

			if ( 'fish' === $kind && 0 === $product && self::is_adjustment( $name ) ) {
				$out[] = self::part( 'credit', $name, 1, $price, $price, 0, 0, 0, 0 );
			} elseif ( 'fish' === $kind ) {
				$qty   = isset( $row['qty'] ) && is_numeric( $row['qty'] ) ? max( 1, (int) $row['qty'] ) : 1;
				$out[] = self::part( 'fish', $name, $qty, $price, null === $price ? null : $qty * $price, 0, $product, 0, 0 );
			} elseif ( 'credit' === $kind ) {
				$off   = null === $price ? null : -1 * abs( $price );
				$out[] = self::part( 'credit', $name, 1, $off, $off, 0, 0, 0, 0 );
			} else {
				$out[] = self::part( 'note', $name, 1, null, null, 0, 0, 0, 0 );
			}
		}
		return $out;
	}

	/**
	 * Whether an invoice's fish line is its "Adjustment" — the line that
	 * brings an invoice up to a stated price, which the theme can only
	 * carry as a fish line. The Invoices section says which name that is
	 * (fishotel_invoices_is_adjustment(), declared whenever this plugin
	 * is active); without it nothing is one.
	 *
	 * @param mixed $name The line's name.
	 * @return bool
	 */
	public static function is_adjustment( $name ) {
		return function_exists( 'fishotel_invoices_is_adjustment' ) && true === \fishotel_invoices_is_adjustment( $name );
	}

	/**
	 * One part.
	 *
	 * @param string     $kind       fish | credit | note.
	 * @param string     $name       Its name.
	 * @param int        $qty        Units (1 for what is not a fish).
	 * @param float|null $price      Per fish.
	 * @param float|null $total      What it adds.
	 * @param int        $species_id Species (0 = none).
	 * @param int        $product_id Product (0 = none).
	 * @param int        $variation  Variation (0 = none).
	 * @param int        $invoice    The invoice product (0 = unknown).
	 * @return array
	 */
	private static function part( $kind, $name, $qty, $price, $total, $species_id, $product_id, $variation, $invoice ) {
		return array(
			'kind'               => $kind,
			'name'               => substr( $name, 0, 200 ),
			'key'                => Species::key( $name ),
			'qty'                => (int) $qty,
			'price'              => $price,
			'total'              => $total,
			'species_id'         => (int) $species_id > 0 ? (int) $species_id : null,
			'product_id'         => (int) $product_id,
			'variation_id'       => (int) $variation,
			'invoice_product_id' => (int) $invoice,
		);
	}

	/**
	 * Close a ledger: every order line kept by add_rows() becomes what it
	 * is —
	 *
	 *   an invoice fish line   a named sale, with the species or product
	 *                          the Invoices section's line of the same
	 *                          name (and invoice) gives it — except the
	 *                          invoice's "Adjustment", which is counted
	 *                          with the credits (money, not a fish);
	 *   an invoice kept whole  its fish as named sales when its lines —
	 *                          the Invoices section's, else the snapshot —
	 *                          all carry a price and add up to what was
	 *                          paid for it; a credit among them is counted
	 *                          as a credit; otherwise the whole line is
	 *                          "unsplit" with the reason;
	 *   a line without product a named sale that is never matched;
	 *
	 * and the refunds that named no line are counted. The per-line detail
	 * is dropped: what is cached is small.
	 *
	 * @param array             $ledger  The ledger after add_rows().
	 * @param array<int, array> $parts   Order id → invoice_parts() of what the Invoices section answered.
	 * @param array[]           $refunds Store::sales_refund_rows(): net_total, on_lines per refund.
	 * @return array
	 */
	public static function finish( array $ledger, array $parts = array(), array $refunds = array() ) {
		foreach ( (array) ( $ledger['items'] ?? array() ) as $item ) {
			$qty     = (int) $item['qty'];
			$revenue = (float) $item['revenue'];
			if ( 0 === $qty && abs( $revenue ) < self::TOLERANCE / 2 ) {
				continue; // Refunded in full, line and money: nothing was sold.
			}
			$known = isset( $parts[ (int) $item['order_id'] ] ) && is_array( $parts[ (int) $item['order_id'] ] ) ? $parts[ (int) $item['order_id'] ] : array();

			if ( ! empty( $item['fish'] ) && 0 === (int) $item['product_id'] && self::is_adjustment( (string) $item['name'] ) ) {
				// The invoice's "Adjustment", itemised by the theme as a fish line: money, not a fish sold.
				$ledger['credits']['lines']++;
				$ledger['credits']['revenue'] += $revenue;
				continue;
			}

			if ( ! empty( $item['fish'] ) ) {
				$hint = self::hint_for( $known, (string) $item['name'], (int) $item['invoice_product_id'] );
				self::add_named( $ledger, 'fish', (string) $item['name'], $qty, $revenue, (int) $item['product_id'] > 0 ? (int) $item['product_id'] : $hint['product_id'], (int) $item['product_id'] > 0 ? (int) $item['variation_id'] : $hint['variation_id'], $hint['species_id'] );
				continue;
			}

			if ( null === $item['lines'] && 0 === (int) $item['invoice_product_id'] ) {
				self::add_named( $ledger, 'other', '' !== (string) $item['name'] ? (string) $item['name'] : '(no name)', $qty, $revenue, 0, 0, null );
				continue;
			}

			// An invoice kept as one line.
			$split = self::parts_for( $known, (int) $item['invoice_product_id'] );
			if ( ! self::has_fish( $split ) ) {
				$split = self::snapshot_parts( $item['lines'] );
			}
			$reason = self::split_reason( $split, $qty, $revenue );
			if ( '' !== $reason ) {
				if ( ! isset( $ledger['unsplit'][ $reason ] ) ) {
					$ledger['unsplit'][ $reason ] = array(
						'lines'   => 0,
						'qty'     => 0,
						'revenue' => 0.0,
					);
				}
				$ledger['unsplit'][ $reason ]['lines']++;
				$ledger['unsplit'][ $reason ]['qty']     += $qty;
				$ledger['unsplit'][ $reason ]['revenue'] += $revenue;
				continue;
			}
			foreach ( $split as $part ) {
				if ( 'fish' === $part['kind'] ) {
					self::add_named( $ledger, 'kept', $part['name'], (int) $part['qty'], (float) $part['total'], (int) $part['product_id'], (int) $part['variation_id'], $part['species_id'] );
				} elseif ( 'credit' === $part['kind'] && null !== $part['total'] ) {
					$ledger['credits']['lines']++;
					$ledger['credits']['revenue'] += (float) $part['total'];
				}
			}
		}
		$ledger['items'] = array();

		foreach ( $refunds as $refund ) {
			if ( ! is_array( $refund ) ) {
				continue;
			}
			$gap = (float) ( $refund['net_total'] ?? 0 ) - (float) ( $refund['on_lines'] ?? 0 );
			if ( abs( $gap ) > self::TOLERANCE / 2 ) {
				$ledger['refunds']['count']++;
				$ledger['refunds']['amount'] += $gap;
			}
		}

		return $ledger;
	}

	/**
	 * Why an invoice kept as one line cannot be split into its fish ('' =
	 * it can): it has no fish line, it was bought more than once in one
	 * line, a fish has no price, or the lines do not add up to what was
	 * paid.
	 *
	 * @param array[] $parts   invoice_parts().
	 * @param int     $qty     The order line's quantity, net of refunds.
	 * @param float   $revenue What was paid for it, net of refunds.
	 * @return string
	 */
	public static function split_reason( array $parts, $qty, $revenue ) {
		if ( ! self::has_fish( $parts ) ) {
			return 'no_lines';
		}
		if ( 1 !== (int) $qty ) {
			return 'quantity';
		}
		$sum = 0.0;
		foreach ( $parts as $part ) {
			if ( 'note' === $part['kind'] ) {
				continue;
			}
			if ( null === $part['total'] ) {
				if ( 'fish' === $part['kind'] ) {
					return 'unpriced';
				}
				continue;
			}
			$sum += (float) $part['total'];
		}
		return abs( $sum - (float) $revenue ) > self::TOLERANCE ? 'mismatch' : '';
	}

	/**
	 * Whether a list of parts has a fish line.
	 *
	 * @param array[] $parts invoice_parts().
	 * @return bool
	 */
	private static function has_fish( array $parts ) {
		foreach ( $parts as $part ) {
			if ( 'fish' === $part['kind'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The parts of one invoice among an order's: those of that invoice
	 * product, or — when no part says which invoice it belongs to — all.
	 *
	 * @param array[] $parts   invoice_parts() of the order.
	 * @param int     $invoice The invoice product (0 = unknown).
	 * @return array[]
	 */
	private static function parts_for( array $parts, $invoice ) {
		$out = array();
		foreach ( $parts as $part ) {
			if ( 0 === (int) $part['invoice_product_id'] || 0 === (int) $invoice || (int) $part['invoice_product_id'] === (int) $invoice ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * What the Invoices section knows about an invoice fish line: the
	 * species and the product of its line with the same exact name on the
	 * same invoice.
	 *
	 * @param array[] $parts   invoice_parts() of the order.
	 * @param string  $name    The order line's name.
	 * @param int     $invoice The invoice product (0 = unknown).
	 * @return array{species_id: int|null, product_id: int, variation_id: int}
	 */
	private static function hint_for( array $parts, $name, $invoice ) {
		$key = Species::key( $name );
		foreach ( self::parts_for( $parts, $invoice ) as $part ) {
			if ( 'fish' === $part['kind'] && '' !== $key && $part['key'] === $key ) {
				return array(
					'species_id'   => $part['species_id'],
					'product_id'   => (int) $part['product_id'],
					'variation_id' => (int) $part['variation_id'],
				);
			}
		}
		return array(
			'species_id'   => null,
			'product_id'   => 0,
			'variation_id' => 0,
		);
	}

	/**
	 * Add a named sale to a ledger, summed with the others of the same
	 * name, product and species.
	 *
	 * @param array    $ledger     The ledger (by reference).
	 * @param string   $via        fish (an itemised invoice line) | kept (a fish of an invoice kept whole) | other.
	 * @param string   $name       The name on the line.
	 * @param int      $qty        Units.
	 * @param float    $revenue    Money.
	 * @param int      $product_id Product (0 = none).
	 * @param int      $variation  Variation (0 = none).
	 * @param int|null $species_id The species the Invoices section names.
	 */
	private static function add_named( array &$ledger, $via, $name, $qty, $revenue, $product_id, $variation, $species_id ) {
		$key  = Species::key( $name );
		$slot = md5( ( 'other' === $via ? 'o' : 'f' ) . '|' . $key . '|' . (int) $product_id . '|' . (int) $variation . '|' . (int) $species_id );
		if ( ! isset( $ledger['named'][ $slot ] ) ) {
			$ledger['named'][ $slot ] = array(
				'via'          => 'other' === $via ? 'other' : 'invoice',
				'name'         => substr( (string) $name, 0, 200 ),
				'key'          => $key,
				'qty'          => 0,
				'revenue'      => 0.0,
				'lines'        => 0,
				'product_id'   => (int) $product_id,
				'variation_id' => (int) $variation,
				'species_id'   => (int) $species_id > 0 ? (int) $species_id : null,
			);
		}
		$ledger['named'][ $slot ]['qty']     += (int) $qty;
		$ledger['named'][ $slot ]['revenue'] += (float) $revenue;
		$ledger['named'][ $slot ]['lines']++;
	}

	/* ============================================================== */
	/*  Which fish                                                     */
	/* ============================================================== */

	/**
	 * The exact names a fish answers to: lower-cased name → the species
	 * row and where the name comes from. The species' own names first,
	 * then the titles of their linked products, then the learned aliases
	 * (an alias of a linked product, or one whose species is a name
	 * already known) — the first to claim a name keeps it.
	 *
	 * @param array[]            $species Species rows (id, name, product_id).
	 * @param array<int, string> $titles  Product id → title.
	 * @param array[]            $aliases Alias rows (alias, product_id, species).
	 * @return array<string, array{id: int, source: string}>
	 */
	public static function name_map( array $species, array $titles = array(), array $aliases = array() ) {
		$map        = array();
		$by_product = array();

		foreach ( $species as $row ) {
			$id  = (int) ( $row['id'] ?? 0 );
			$key = Species::key( (string) ( $row['name'] ?? '' ) );
			if ( $id < 1 ) {
				continue;
			}
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'id'     => $id,
					'source' => 'name',
				);
			}
			if ( (int) ( $row['product_id'] ?? 0 ) > 0 && ! isset( $by_product[ (int) $row['product_id'] ] ) ) {
				$by_product[ (int) $row['product_id'] ] = $id;
			}
		}

		foreach ( $by_product as $product_id => $id ) {
			$key = isset( $titles[ $product_id ] ) ? Species::key( (string) $titles[ $product_id ] ) : '';
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'id'     => $id,
					'source' => 'title',
				);
			}
		}

		foreach ( $aliases as $alias ) {
			$key = is_array( $alias ) ? Species::key( (string) ( $alias['alias'] ?? '' ) ) : '';
			if ( '' === $key || isset( $map[ $key ] ) ) {
				continue;
			}
			$product = (int) ( $alias['product_id'] ?? 0 );
			$named   = Species::key( (string) ( $alias['species'] ?? '' ) );
			$id      = 0;
			if ( $product > 0 && isset( $by_product[ $product ] ) ) {
				$id = $by_product[ $product ];
			} elseif ( '' !== $named && isset( $map[ $named ] ) && 'alias' !== $map[ $named ]['source'] ) {
				$id = $map[ $named ]['id'];
			}
			if ( $id > 0 ) {
				$map[ $key ] = array(
					'id'     => $id,
					'source' => 'alias',
				);
			}
		}

		return $map;
	}

	/**
	 * Species id by linked product id.
	 *
	 * @param array[] $species Species rows.
	 * @return array<int, int>
	 */
	public static function by_product( array $species ) {
		$out = array();
		foreach ( $species as $row ) {
			$product = (int) ( $row['product_id'] ?? 0 );
			if ( $product > 0 && (int) ( $row['id'] ?? 0 ) > 0 && ! isset( $out[ $product ] ) ) {
				$out[ $product ] = (int) $row['id'];
			}
		}
		return $out;
	}

	/**
	 * The products a finished ledger names (to read their titles in one
	 * go).
	 *
	 * @param array $ledger A finished ledger.
	 * @return int[]
	 */
	public static function product_ids( array $ledger ) {
		$ids = array();
		foreach ( (array) ( $ledger['products'] ?? array() ) as $row ) {
			$ids[ (int) $row['product_id'] ] = true;
		}
		foreach ( (array) ( $ledger['named'] ?? array() ) as $row ) {
			if ( (int) $row['product_id'] > 0 ) {
				$ids[ (int) $row['product_id'] ] = true;
			}
		}
		unset( $ids[0] );
		return array_keys( $ids );
	}

	/**
	 * A finished ledger matched to the species list: per species its
	 * units, money and how they were matched; everything else under "not
	 * matched", by kind, with the biggest items listed.
	 *
	 * @param array $ledger A finished ledger.
	 * @param array $ctx    species (rows), titles (product id → title), aliases (rows).
	 * @return array{species: array<int, array>, unmatched: array<string, array>, refunds: array, totals: array}
	 */
	public static function match( array $ledger, array $ctx ) {
		$species    = isset( $ctx['species'] ) && is_array( $ctx['species'] ) ? $ctx['species'] : array();
		$titles     = isset( $ctx['titles'] ) && is_array( $ctx['titles'] ) ? $ctx['titles'] : array();
		$aliases    = isset( $ctx['aliases'] ) && is_array( $ctx['aliases'] ) ? $ctx['aliases'] : array();
		$index      = Stats::species_index( $species );
		$by_product = self::by_product( $species );
		$names      = self::name_map( $species, $titles, $aliases );

		$matched   = array();
		$unmatched = array();
		foreach ( array( 'products', 'names', 'invoices', 'other', 'credits' ) as $kind ) {
			$unmatched[ $kind ] = array(
				'units'   => 0,
				'revenue' => 0.0,
				'lines'   => 0,
				'items'   => array(),
				'more'    => 0,
			);
		}

		$credit = static function ( $id, $how, $qty, $revenue, $lines ) use ( &$matched ) {
			if ( ! isset( $matched[ $id ] ) ) {
				$matched[ $id ] = array(
					'units'   => 0,
					'revenue' => 0.0,
					'lines'   => 0,
					'via'     => array(),
				);
			}
			$matched[ $id ]['units']       += (int) $qty;
			$matched[ $id ]['revenue']     += (float) $revenue;
			$matched[ $id ]['lines']       += (int) $lines;
			$matched[ $id ]['via'][ $how ]  = ( $matched[ $id ]['via'][ $how ] ?? 0 ) + (int) $qty;
		};
		$miss = static function ( $kind, $label, $qty, $revenue, $lines, array $extra = array() ) use ( &$unmatched ) {
			$unmatched[ $kind ]['units']   += (int) $qty;
			$unmatched[ $kind ]['revenue'] += (float) $revenue;
			$unmatched[ $kind ]['lines']   += (int) $lines;
			$slot                           = isset( $extra['slot'] ) ? (string) $extra['slot'] : Species::key( (string) $label );
			unset( $extra['slot'] );
			if ( ! isset( $unmatched[ $kind ]['items'][ $slot ] ) ) {
				$unmatched[ $kind ]['items'][ $slot ] = array_merge(
					array(
						'label'   => (string) $label,
						'units'   => 0,
						'revenue' => 0.0,
						'lines'   => 0,
					),
					$extra
				);
			}
			$unmatched[ $kind ]['items'][ $slot ]['units']   += (int) $qty;
			$unmatched[ $kind ]['items'][ $slot ]['revenue'] += (float) $revenue;
			$unmatched[ $kind ]['items'][ $slot ]['lines']   += (int) $lines;
		};

		foreach ( (array) ( $ledger['products'] ?? array() ) as $row ) {
			$product = (int) $row['product_id'];
			$variant = (int) $row['variation_id'];
			$title   = isset( $titles[ $product ] ) ? (string) $titles[ $product ] : '';
			$key     = Species::key( $title );

			if ( isset( $by_product[ $product ] ) || ( $variant > 0 && isset( $by_product[ $variant ] ) ) ) {
				$credit( isset( $by_product[ $product ] ) ? $by_product[ $product ] : $by_product[ $variant ], 'product', $row['qty'], $row['revenue'], $row['lines'] );
			} elseif ( '' !== $key && isset( $names[ $key ] ) ) {
				$credit( $names[ $key ]['id'], $names[ $key ]['source'], $row['qty'], $row['revenue'], $row['lines'] );
			} elseif ( '' !== $title && 1 === preg_match( '/\binvoice\b/i', $title ) ) {
				$miss( 'invoices', 'no_lines', $row['qty'], $row['revenue'], $row['lines'], array( 'slot' => 'no_lines' ) );
			} else {
				$miss(
					'products',
					'' !== $title ? $title : '#' . $product,
					$row['qty'],
					$row['revenue'],
					$row['lines'],
					array(
						'slot'       => 'p' . $product,
						'product_id' => $product,
						'gone'       => '' === $title,
					)
				);
			}
		}

		foreach ( (array) ( $ledger['named'] ?? array() ) as $row ) {
			if ( 'other' === $row['via'] ) {
				$miss( 'other', $row['name'], $row['qty'], $row['revenue'], $row['lines'] );
				continue;
			}
			$product = (int) $row['product_id'];
			$variant = (int) $row['variation_id'];
			$hint    = (int) $row['species_id'];

			if ( $product > 0 && isset( $by_product[ $product ] ) ) {
				$credit( $by_product[ $product ], 'product', $row['qty'], $row['revenue'], $row['lines'] );
			} elseif ( $variant > 0 && isset( $by_product[ $variant ] ) ) {
				$credit( $by_product[ $variant ], 'product', $row['qty'], $row['revenue'], $row['lines'] );
			} elseif ( $hint > 0 && isset( $index['rows'][ $hint ] ) ) {
				$credit( $hint, 'invoice', $row['qty'], $row['revenue'], $row['lines'] );
			} elseif ( '' !== $row['key'] && isset( $names[ $row['key'] ] ) ) {
				$credit( $names[ $row['key'] ]['id'], $names[ $row['key'] ]['source'], $row['qty'], $row['revenue'], $row['lines'] );
			} else {
				$miss( 'names', $row['name'], $row['qty'], $row['revenue'], $row['lines'] );
			}
		}

		foreach ( (array) ( $ledger['unsplit'] ?? array() ) as $reason => $sum ) {
			$miss( 'invoices', (string) $reason, $sum['qty'], $sum['revenue'], $sum['lines'], array( 'slot' => (string) $reason ) );
		}

		if ( (int) ( $ledger['credits']['lines'] ?? 0 ) > 0 ) {
			$miss( 'credits', 'credits', 0, (float) $ledger['credits']['revenue'], (int) $ledger['credits']['lines'], array( 'slot' => 'credits' ) );
		}

		$totals = array(
			'matched_units'     => 0,
			'matched_revenue'   => 0.0,
			'unmatched_units'   => 0,
			'unmatched_revenue' => 0.0,
		);
		foreach ( $matched as $sum ) {
			$totals['matched_units']   += $sum['units'];
			$totals['matched_revenue'] += $sum['revenue'];
		}
		foreach ( $unmatched as $kind => $bucket ) {
			$totals['unmatched_units']   += $bucket['units'];
			$totals['unmatched_revenue'] += $bucket['revenue'];

			$items = array_values( $bucket['items'] );
			usort(
				$items,
				static function ( $a, $b ) {
					$order = abs( $b['revenue'] ) <=> abs( $a['revenue'] );
					return 0 !== $order ? $order : strcasecmp( (string) $a['label'], (string) $b['label'] );
				}
			);
			$unmatched[ $kind ]['more']  = max( 0, count( $items ) - self::LIST_MAX );
			$unmatched[ $kind ]['items'] = array_slice( $items, 0, self::LIST_MAX );
		}
		$totals['units']         = $totals['matched_units'] + $totals['unmatched_units'];
		$totals['revenue']       = $totals['matched_revenue'] + $totals['unmatched_revenue'];
		$totals['lines_revenue'] = (float) ( $ledger['revenue'] ?? 0 );
		$totals['balanced']      = abs( $totals['revenue'] - $totals['lines_revenue'] ) < self::TOLERANCE;

		return array(
			'species'   => $matched,
			'unmatched' => $unmatched,
			'refunds'   => array(
				'count'  => (int) ( $ledger['refunds']['count'] ?? 0 ),
				'amount' => (float) ( $ledger['refunds']['amount'] ?? 0 ),
			),
			'totals'    => $totals,
		);
	}

	/* ============================================================== */
	/*  Sales beside the supplier orders                               */
	/* ============================================================== */

	/**
	 * One row per fish of a year: what was sold beside what landed.
	 *
	 *   units, revenue   ACTUAL — match();
	 *   avg_paid         revenue ÷ units, beside list_price;
	 *   est_revenue      the ESTIMATE of the supplier orders of that year
	 *                    (survivors × the price typed on the line) —
	 *                    null when no order of that year has the fish, and
	 *                    when it landed but none of its lines has a
	 *                    survivor count yet (est_unknown: nothing to
	 *                    estimate from is not an estimate of 0);
	 *   landed_per_fish  that year's average landed cost of the fish;
	 *   cost_sold        units × landed_per_fish;
	 *   profit           revenue − cost_sold — only when the fish sold
	 *                    AND landed that year; no_cost is true when it
	 *                    sold without a landed cost that year (earlier
	 *                    then carries the last earlier year's cost, for
	 *                    reference: no profit is computed from it).
	 *
	 * Fish that landed but did not sell are listed too (0 units), as are
	 * the supplier lines that are no species row. Most actual revenue
	 * first, then most estimated.
	 *
	 * @param int     $year    The year.
	 * @param array   $match   match().
	 * @param array[] $species Species rows.
	 * @param array[] $landed  Stats::by_species() rows of EVERY year (this year's are the row's own,
	 *                         earlier ones answer "the last landed cost").
	 * @param array   $prices  Species id → Stats_Prices::fish_price().
	 * @return array[]
	 */
	public static function rows( $year, array $match, array $species, array $landed, array $prices = array() ) {
		$year    = (int) $year;
		$index   = Stats::species_index( $species );
		$now     = array();
		$earlier = array();
		foreach ( $landed as $row ) {
			$key = (string) ( $row['key'] ?? '' );
			if ( (int) ( $row['year'] ?? 0 ) === $year ) {
				$now[ $key ] = $row;
			} elseif ( (int) ( $row['year'] ?? 0 ) < $year && null !== ( $row['landed_per_fish'] ?? null ) && ( ! isset( $earlier[ $key ] ) || (int) $row['year'] > $earlier[ $key ]['year'] ) ) {
				$earlier[ $key ] = array(
					'year'            => (int) $row['year'],
					'landed_per_fish' => (float) $row['landed_per_fish'],
				);
			}
		}

		$keys = array();
		foreach ( array_keys( (array) ( $match['species'] ?? array() ) ) as $id ) {
			$keys[ 's:' . (int) $id ] = true;
		}
		foreach ( array_keys( $now ) as $key ) {
			$keys[ $key ] = true;
		}

		$out = array();
		foreach ( array_keys( $keys ) as $key ) {
			$key   = (string) $key;
			$id    = 0 === strpos( $key, 's:' ) ? (int) substr( $key, 2 ) : 0;
			$row   = $id > 0 && isset( $index['rows'][ $id ] ) ? $index['rows'][ $id ] : null;
			$sold  = $id > 0 && isset( $match['species'][ $id ] ) ? $match['species'][ $id ] : null;
			$stat  = isset( $now[ $key ] ) ? $now[ $key ] : null;
			$price = $id > 0 && isset( $prices[ $id ] ) && is_array( $prices[ $id ] ) ? $prices[ $id ] : array();

			$units   = $sold ? (int) $sold['units'] : 0;
			$revenue = $sold ? (float) $sold['revenue'] : 0.0;
			$cost    = $stat && null !== $stat['landed_per_fish'] ? (float) $stat['landed_per_fish'] : null;
			$has     = null !== $sold && ( 0 !== $units || abs( $revenue ) > self::TOLERANCE / 2 );
			$via     = $sold ? $sold['via'] : array();
			arsort( $via );

			$out[] = array(
				'key'             => $key,
				'species_id'      => $id > 0 ? $id : null,
				'name'            => $row ? (string) $row['name'] : ( $stat ? (string) $stat['name'] : '#' . $id ),
				'group'           => $row ? trim( (string) ( $row['group_name'] ?? '' ) ) : ( $stat ? (string) $stat['group'] : '' ),
				'year'            => $year,
				'sold'            => $has,
				'units'           => $units,
				'revenue'         => $revenue,
				'lines'           => $sold ? (int) $sold['lines'] : 0,
				'avg_paid'        => $units > 0 ? $revenue / $units : null,
				'list_price'      => isset( $price['price'] ) ? $price['price'] : null,
				'price_source'    => isset( $price['source'] ) ? (string) $price['source'] : '',
				'price_from'      => ! empty( $price['from'] ),
				'received'        => $stat ? (int) $stat['received'] : null,
				'survived'        => $stat && ! empty( $stat['survived_known'] ) ? (int) $stat['survived'] : null,
				'est_revenue'     => $stat && ! empty( $stat['survived_known'] ) ? (float) $stat['revenue'] : null,
				'est_unknown'     => $stat && empty( $stat['survived_known'] ) && (int) $stat['received'] > 0,
				'unknown_lines'   => $stat ? (int) $stat['unknown_lines'] : 0,
				'landed_per_fish' => $cost,
				'cost_sold'       => $has && null !== $cost ? $units * $cost : null,
				'profit'          => $has && null !== $cost ? $revenue - $units * $cost : null,
				'no_cost'         => $has && null === $cost,
				'earlier'         => $has && null === $cost && isset( $earlier[ $key ] ) ? $earlier[ $key ] : null,
				'via'             => array_keys( $via ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( abs( $a['revenue'] - $b['revenue'] ) > 1e-9 ) {
					return $b['revenue'] <=> $a['revenue'];
				}
				if ( abs( (float) $a['est_revenue'] - (float) $b['est_revenue'] ) > 1e-9 ) {
					return (float) $b['est_revenue'] <=> (float) $a['est_revenue'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * The sums under rows(): units and actual revenue, and — over the fish
	 * that have a profit figure only — their revenue, cost and profit, so
	 * the screen can say how much of the revenue the profit covers.
	 *
	 * @param array[] $rows rows().
	 * @return array{units: int, revenue: float, est_revenue: float, costed_revenue: float, cost_sold: float, profit: float, no_cost: int, no_cost_revenue: float}
	 */
	public static function row_totals( array $rows ) {
		$out = array(
			'units'           => 0,
			'revenue'         => 0.0,
			'est_revenue'     => 0.0,
			'costed_revenue'  => 0.0,
			'cost_sold'       => 0.0,
			'profit'          => 0.0,
			'no_cost'         => 0,
			'no_cost_revenue' => 0.0,
		);
		foreach ( $rows as $row ) {
			$out['units']       += (int) $row['units'];
			$out['revenue']     += (float) $row['revenue'];
			$out['est_revenue'] += (float) $row['est_revenue'];
			if ( null !== $row['profit'] ) {
				$out['costed_revenue'] += (float) $row['revenue'];
				$out['cost_sold']      += (float) $row['cost_sold'];
				$out['profit']         += (float) $row['profit'];
			} elseif ( ! empty( $row['no_cost'] ) ) {
				$out['no_cost']++;
				$out['no_cost_revenue'] += (float) $row['revenue'];
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Small readers                                                  */
	/* ============================================================== */

	/**
	 * A name as typed on an invoice, without markup: tags stripped,
	 * entities decoded, every run of white space (non-breaking included)
	 * one space.
	 *
	 * @param mixed $text Raw.
	 * @return string
	 */
	private static function clean_name( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The first of some keys that holds a whole number above zero, else 0.
	 *
	 * @param array    $row  Row.
	 * @param string[] $keys Keys, in order.
	 * @return int
	 */
	private static function first_int( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) && (int) $row[ $key ] > 0 ) {
				return (int) $row[ $key ];
			}
		}
		return 0;
	}
}
