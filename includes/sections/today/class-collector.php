<?php
/**
 * Today collector — the only file in this section that touches
 * WooCommerce, the orders and the sibling plugins.
 *
 * It turns the open orders of the lookahead window into the plain arrays
 * the Model consumes, one destination per order (a fulfillment wrapper is
 * ONE destination made of its live source orders; orders folded into a
 * wrapper are skipped), and caches the whole window in one transient.
 *
 * What each piece comes from:
 *   today          fishotel_delivery_rules()->today() (Batch ≥ 10.26), else
 *                  current_time('Y-m-d') — the site-zone calendar date.
 *   open orders    ONE store-agnostic query over the window through the
 *                  Weather section's Checker::open_orders( statuses, dates ).
 *   fish / dry     FisHotel_Ship_Day::build_destination() (Batch ≥ 10.27),
 *                  else the line items by product category here.
 *   weather        the Weather section's _fh_weather_* meta on the
 *                  destination order (Model::weather_state).
 *   invoice        any of the filterable meta keys (WPO PDF Invoices'
 *                  _wcpdf_invoice_date by default) on the destination, or on
 *                  every live source of a wrapper.
 *   tracking       FST_Shipment::get_by_order() (ShipTracker) on the
 *                  destination, or on every live source of a wrapper.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Weather\Checker;

/**
 * Class Collector
 */
class Collector {

	/**
	 * The one transient holding the cached window (its key is inside).
	 *
	 * @var string
	 */
	const TRANSIENT = 'fishotel_today_cache';

	/**
	 * The Batch plugin version that introduced fishotel_delivery_rules()
	 * and the one that introduced FisHotel_Ship_Day.
	 */
	const BATCH_RULES_VERSION    = '10.26';
	const BATCH_SHIP_DAY_VERSION = '10.27';

	/**
	 * Default invoice meta keys (filter: fishotel_today_invoice_meta_keys).
	 * The first is WPO WooCommerce PDF Invoices' "PDF generated" marker.
	 *
	 * @var string[]
	 */
	const INVOICE_META_KEYS = array( '_wcpdf_invoice_date', '_fishotel_invoice_printed_at' );

	/**
	 * The WPO key: when the PDF plugin is absent and the filter adds no
	 * other key, the invoice column has nothing to read.
	 *
	 * @var string
	 */
	const WPO_META_KEY = '_wcpdf_invoice_date';

	/**
	 * Line statuses (theme item meta) that never ship.
	 *
	 * @var string[]
	 */
	const LINE_SKIP = array( 'shipped', 'na' );

	/* ============================================================== */
	/*  Environment                                                    */
	/* ============================================================== */

	/**
	 * Whether the Batch plugin is active at least at a version.
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	public static function batch_at_least( $version ) {
		return defined( 'FISHOTEL_VERSION' ) && version_compare( (string) FISHOTEL_VERSION, $version, '>=' );
	}

	/**
	 * Today's calendar date in the site zone.
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		if ( function_exists( 'fishotel_delivery_rules' ) && self::batch_at_least( self::BATCH_RULES_VERSION ) ) {
			try {
				$rules = fishotel_delivery_rules();
				if ( is_object( $rules ) && method_exists( $rules, 'today' ) ) {
					$today = $rules->today();
					if ( $today instanceof \DateTimeInterface ) {
						return $today->format( 'Y-m-d' );
					}
					if ( is_string( $today ) && Model::parse( $today ) ) {
						return $today;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * The site zone (the delivery rules' zone when the Batch plugin has one).
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		if ( function_exists( 'fishotel_delivery_rules' ) && self::batch_at_least( self::BATCH_RULES_VERSION ) ) {
			try {
				$rules = fishotel_delivery_rules();
				if ( is_object( $rules ) && method_exists( $rules, 'timezone' ) ) {
					$tz = $rules->timezone();
					if ( $tz instanceof \DateTimeZone ) {
						return $tz;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Whether the Batch plugin's Ship Day class is available.
	 *
	 * @return bool
	 */
	public static function has_ship_day() {
		return class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'build_destination' ) );
	}

	/**
	 * Whether ShipTracker's shipment model is available.
	 *
	 * @return bool
	 */
	public static function has_shiptracker() {
		return class_exists( 'FST_Shipment' ) && is_callable( array( 'FST_Shipment', 'get_by_order' ) );
	}

	/**
	 * The invoice meta keys to look at.
	 *
	 * @return string[]
	 */
	public static function invoice_meta_keys() {
		$keys = self::INVOICE_META_KEYS;
		if ( function_exists( 'apply_filters' ) ) {
			$keys = apply_filters( 'fishotel_today_invoice_meta_keys', $keys );
		}

		$out = array();
		foreach ( (array) $keys as $key ) {
			$key = is_scalar( $key ) ? trim( (string) $key ) : '';
			if ( '' !== $key && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Whether the invoice column can be evaluated: the PDF plugin is
	 * active, or the filter supplied a key of its own.
	 *
	 * @return bool
	 */
	public static function invoice_available() {
		if ( class_exists( 'WPO_WCPDF' ) ) {
			return true;
		}

		// The filter supplied a key of its own (something other than the
		// WPO marker that is not just the stock list): there is something
		// to read without the PDF plugin.
		foreach ( self::invoice_meta_keys() as $key ) {
			if ( self::WPO_META_KEY !== $key && ! in_array( $key, self::INVOICE_META_KEYS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which columns are shown, and why a hidden one is hidden.
	 *
	 * @param array $settings Settings.
	 * @return array{weather: bool, invoice: bool, tracking: bool, notes: array<string, string>}
	 */
	public static function columns( array $settings ) {
		$notes = array();

		$invoice = ! empty( $settings['show_invoice'] );
		if ( $invoice && ! self::invoice_available() ) {
			$invoice = false;
			$notes['invoice'] = __( 'Invoice PDF check needs the PDF Invoices plugin', 'fishotel-misc-plugin' );
		}

		$tracking = ! empty( $settings['show_tracking'] );
		if ( $tracking && ! self::has_shiptracker() ) {
			$tracking = false;
			$notes['tracking'] = __( 'Tracking check needs the ShipTracker plugin', 'fishotel-misc-plugin' );
		}

		return array(
			'weather'  => ! empty( $settings['show_weather'] ),
			'invoice'  => $invoice,
			'tracking' => $tracking,
			'notes'    => $notes,
		);
	}

	/* ============================================================== */
	/*  Cache                                                          */
	/* ============================================================== */

	/**
	 * The window for the current settings — from the cache when it is
	 * fresh and was built for today with these settings, else collected
	 * now and stored for cache_minutes (0 = never stored).
	 *
	 * @param array|null $settings Settings (null = load).
	 * @return array See self::collect().
	 */
	public static function get( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();
		$today    = self::today();
		$key      = Model::cache_key( $today, $settings );
		$minutes  = (int) ( $settings['cache_minutes'] ?? 0 );

		if ( $minutes > 0 ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
				$data           = $cached['data'];
				$data['cached'] = true;
				return $data;
			}
		}

		$data = self::collect( $settings, $today );

		if ( $minutes > 0 ) {
			set_transient(
				self::TRANSIENT,
				array(
					'key'  => $key,
					'data' => $data,
				),
				$minutes * MINUTE_IN_SECONDS
			);
		}

		$data['cached'] = false;

		return $data;
	}

	/**
	 * Drop the cached window (Refresh, settings save, any order change).
	 */
	public static function clear_cache() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/* ============================================================== */
	/*  Collect                                                        */
	/* ============================================================== */

	/**
	 * Build the window: every date in today+1 … today+lookahead that has
	 * open orders (counts), the first dates_shown of them laid out in full
	 * (destinations, chips, attention), the ship plan and the columns.
	 *
	 * @param array       $settings Settings.
	 * @param string|null $today    Y-m-d (null = site today).
	 * @return array{
	 *   today: string, lookahead: int, dates_shown: int, window: string[],
	 *   counts: array<string, int>, blocks: array<string, array>, plan: array,
	 *   columns: array, generated_at: int, cached: bool
	 * }
	 */
	public static function collect( array $settings, $today = null ) {
		$today     = Model::parse( (string) $today ) ? (string) $today : self::today();
		$lookahead = (int) ( $settings['lookahead_days'] ?? Settings::DEFAULTS['lookahead_days'] );
		$shown     = max( 1, (int) ( $settings['dates_shown'] ?? Settings::DEFAULTS['dates_shown'] ) );
		$window    = Model::window( $today, $lookahead );
		$columns   = self::columns( $settings );
		$tz        = self::timezone();

		// ONE query over the whole window; folded sources are dropped
		// (their wrapper carries them).
		$by_date = array();
		foreach ( self::open_orders( (array) ( $settings['statuses'] ?? array() ), $window ) as $order ) {
			if ( Checker::is_folded_source( $order ) ) {
				continue;
			}
			$date = Checker::delivery_date( $order );
			if ( '' === $date || ! in_array( $date, $window, true ) ) {
				continue;
			}
			$by_date[ $date ][] = $order;
		}
		ksort( $by_date );

		$counts = array();
		foreach ( $by_date as $date => $orders ) {
			$counts[ $date ] = count( $orders );
		}

		$blocks = array();
		foreach ( array_slice( array_keys( $by_date ), 0, $shown ) as $date ) {
			$destinations = array();
			foreach ( $by_date[ $date ] as $order ) {
				$destination = self::destination( $order, $date, $columns );
				if ( $destination ) {
					$destinations[] = $destination;
				}
			}
			// The sort, the chips and the attention strip honour the column
			// toggles (a hidden Weather column contributes no reason, no
			// chip and no rank); the weather state itself stays on the
			// destination for the widget badge and the cache key.
			$destinations = Model::sort_destinations( $destinations, $columns );

			$blocks[ $date ] = array(
				'date'         => $date,
				'ship'         => Model::ship_date_for( $date, $tz ),
				'destinations' => $destinations,
				'chips'        => Model::chips( $destinations, $columns ),
				'attention'    => Model::needs_attention( $destinations, $columns ),
			);
		}

		$chips_by_date = array();
		foreach ( $blocks as $date => $block ) {
			$chips_by_date[ $date ] = $block['chips'];
		}
		foreach ( $counts as $date => $n ) {
			if ( ! isset( $chips_by_date[ $date ] ) ) {
				$chips_by_date[ $date ] = array( 'orders' => $n );
			}
		}

		return array(
			'today'        => $today,
			'lookahead'    => $lookahead,
			'dates_shown'  => $shown,
			'window'       => $window,
			'counts'       => $counts,
			'blocks'       => $blocks,
			'chips'        => $chips_by_date,
			'plan'         => Model::ship_plan( $today, array_keys( $counts ) ),
			'columns'      => $columns,
			'generated_at' => time(),
			'cached'       => false,
		);
	}

	/**
	 * Open orders in the statuses whose delivery date is in the list —
	 * one store-agnostic query via the Weather section's Checker.
	 *
	 * @param string[] $statuses Statuses without `wc-`.
	 * @param string[] $dates    Y-m-d list.
	 * @return \WC_Order[]
	 */
	public static function open_orders( array $statuses, array $dates ) {
		if ( empty( $statuses ) || empty( $dates ) || ! class_exists( '\FisHotel\Misc\Sections\Weather\Checker' ) ) {
			return array();
		}

		try {
			return Checker::open_orders( $statuses, $dates );
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}
	}

	/* ============================================================== */
	/*  One destination                                                */
	/* ============================================================== */

	/**
	 * One destination: a regular order, or a fulfillment wrapper with
	 * its live sources combined.
	 *
	 * @param object $order   WC_Order (or a fake with the same getters).
	 * @param string $ymd     Delivery date.
	 * @param array  $columns From self::columns().
	 * @return array|null Null when the order object is unusable.
	 */
	public static function destination( $order, $ymd, array $columns ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}

		$is_wrapper = Checker::is_fulfillment( $order );
		$sources    = $is_wrapper ? Checker::live_sources( $order ) : array();
		$number     = (string) $order->get_order_number();

		$source_numbers = array();
		$source_ids     = array();
		foreach ( $sources as $source ) {
			$source_numbers[] = (string) $source->get_order_number();
			$source_ids[]     = (int) $source->get_id();
		}

		$counts = self::counts( $order, $ymd, $is_wrapper, $sources );
		$status = (string) $order->get_status();

		// Customer and place always come from the order here ('City, ST'),
		// whatever the Ship Day class derives — its place() carries the ZIP.
		$destination = array(
			'order_id'       => (int) $order->get_id(),
			'number'         => $number,
			'label'          => '' !== $counts['label'] ? $counts['label'] : Model::order_label( $number, $source_numbers ),
			'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '',
			'is_fulfillment' => $is_wrapper,
			'sources'        => $source_numbers,
			'source_ids'     => $source_ids,
			'customer'       => self::customer_name( $order ),
			'place'          => self::place( $order ),
			'status'         => $status,
			'status_label'   => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : ucwords( str_replace( '-', ' ', $status ) ),
			'fish'           => (int) $counts['fish'],
			'dry'            => (int) $counts['dry'],
			'delivery_date'  => (string) $ymd,
			'weather'        => Model::weather_state( self::weather_meta( $order ), $ymd ),
			'invoice'        => ! empty( $columns['invoice'] ) ? self::invoice( $order, $sources ) : null,
			'tracking'       => ! empty( $columns['tracking'] ) ? self::tracking( $order, $sources ) : null,
			'has_note'       => self::has_note( $order, $sources ),
		);

		return $destination;
	}

	/**
	 * Whether the order, or any live source of a combined box, carries a
	 * customer note. The theme builds a wrapper from the lines and the
	 * addresses only, so the note a customer typed at checkout lives on
	 * the source order.
	 *
	 * @param object   $order   Destination order.
	 * @param object[] $sources Live sources of a wrapper.
	 * @return bool
	 */
	public static function has_note( $order, array $sources ) {
		foreach ( array_merge( array( $order ), $sources ) as $candidate ) {
			if ( is_object( $candidate ) && method_exists( $candidate, 'get_customer_note' ) && '' !== trim( (string) $candidate->get_customer_note() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fish and dry-goods counts (plus the label the Ship Day class derives,
	 * when it is there). Customer and place are never taken from Ship Day.
	 *
	 * @param object   $order      Order.
	 * @param string   $ymd        Delivery date.
	 * @param bool     $is_wrapper Whether the order is a fulfillment wrapper.
	 * @param object[] $sources    Live sources of a wrapper.
	 * @return array{fish: int, dry: int, label: string}
	 */
	private static function counts( $order, $ymd, $is_wrapper, array $sources ) {
		$out = array(
			'fish'  => 0,
			'dry'   => 0,
			'label' => '',
		);

		if ( self::has_ship_day() ) {
			try {
				$built  = \FisHotel_Ship_Day::build_destination( $order, $ymd );
				$totals = \FisHotel_Ship_Day::totals( array( $built ) );

				$out['fish']  = (int) ( $totals['fish'] ?? 0 );
				$out['dry']   = (int) ( $totals['dry'] ?? 0 );
				$out['label'] = (string) ( $built['label'] ?? '' );

				return $out;
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to the local count.
			}
		}

		if ( $is_wrapper ) {
			if ( $sources ) {
				foreach ( $sources as $source ) {
					$c = self::count_lines( $source );
					$out['fish'] += $c['fish'];
					$out['dry']  += $c['dry'];
				}
			} elseif ( ! Checker::source_ids( $order ) || ! self::any_source_loads( $order ) ) {
				// No source id loads as an order: the wrapper's own copied
				// lines are all there is.
				$c = self::count_lines( $order );
				$out['fish'] = $c['fish'];
				$out['dry']  = $c['dry'];
			}
			// Every listed source loaded but is dead: nothing to pack.
			return $out;
		}

		$c = self::count_lines( $order );
		$out['fish'] = $c['fish'];
		$out['dry']  = $c['dry'];

		return $out;
	}

	/**
	 * Whether any source id listed on a wrapper loads as an order (dead or
	 * alive).
	 *
	 * @param object $order Wrapper.
	 * @return bool
	 */
	private static function any_source_loads( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		foreach ( Checker::source_ids( $order ) as $id ) {
			$source = wc_get_order( $id );
			if ( is_object( $source ) && method_exists( $source, 'get_items' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fish / dry-goods quantities of an order's still-to-ship product
	 * lines: lines whose product (the parent for a variation) is in a fish
	 * category count as fish, the rest as dry goods. Lines marked shipped
	 * or na by the theme, and fully refunded lines, are skipped.
	 *
	 * @param object $order Order.
	 * @return array{fish: int, dry: int}
	 */
	public static function count_lines( $order ) {
		$out = array(
			'fish' => 0,
			'dry'  => 0,
		);

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}

		$fish_cats = self::fish_categories();

		foreach ( (array) $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}

			$status = method_exists( $item, 'get_meta' ) ? (string) $item->get_meta( '_fishotel_line_status' ) : '';
			if ( in_array( $status, self::LINE_SKIP, true ) ) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			if ( method_exists( $order, 'get_qty_refunded_for_item' ) ) {
				$id   = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_id;
				$qty -= abs( (int) $order->get_qty_refunded_for_item( $id ) );
			}
			if ( $qty <= 0 ) {
				continue;
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$slugs   = self::product_category_slugs( $product );

			if ( array_intersect( $slugs, $fish_cats ) ) {
				$out['fish'] += $qty;
			} else {
				$out['dry'] += $qty;
			}
		}

		return $out;
	}

	/**
	 * Product category slugs that count as fish — the Ship Day list when
	 * the Batch plugin has it, else the same default under the same filter.
	 *
	 * @return string[]
	 */
	public static function fish_categories() {
		if ( class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'fish_categories' ) ) ) {
			return (array) \FisHotel_Ship_Day::fish_categories();
		}

		$default = array( 'quarantined-fish', 'inverts' );
		$cats    = function_exists( 'apply_filters' ) ? apply_filters( 'fishotel_ship_day_fish_categories', $default ) : $default;

		$out = array();
		foreach ( (array) $cats as $cat ) {
			$cat = is_scalar( $cat ) ? trim( (string) $cat ) : '';
			if ( '' !== $cat && ! in_array( $cat, $out, true ) ) {
				$out[] = $cat;
			}
		}

		return $out;
	}

	/**
	 * Category slugs of a product (its parent's for a variation).
	 *
	 * @param object|null $product Product.
	 * @return string[]
	 */
	public static function product_category_slugs( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$pid = (int) $product->get_id();
		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$pid = (int) $product->get_parent_id();
		}

		$terms = get_the_terms( $pid, 'product_cat' );
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
	 * Customer name: shipping, billing fallback, 'Guest'.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function customer_name( $order ) {
		$name = '';

		if ( method_exists( $order, 'get_formatted_shipping_full_name' ) ) {
			$name = trim( (string) $order->get_formatted_shipping_full_name() );
		}
		if ( '' === $name ) {
			$name = trim( (string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name() );
		}
		if ( '' === $name && method_exists( $order, 'get_formatted_billing_full_name' ) ) {
			$name = trim( (string) $order->get_formatted_billing_full_name() );
		}
		if ( '' === $name ) {
			$name = trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() );
		}

		return '' !== $name ? $name : __( 'Guest', 'fishotel-misc-plugin' );
	}

	/**
	 * 'City, ST' of the destination address (shipping, else billing).
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function place( $order ) {
		$destination = Checker::destination( $order );
		return Model::place( $destination['city'], $destination['state'] );
	}

	/**
	 * The Weather section's meta on an order, keyed by meta name.
	 *
	 * @param object $order Order.
	 * @return array<string, string>
	 */
	public static function weather_meta( $order ) {
		$keys = array(
			Checker::META_CHECKED_FOR,
			Checker::META_CHECKED_AT,
			Checker::META_HIGH,
			Checker::META_LOW,
			Checker::META_ACTION,
			Checker::META_SUMMARY,
			Checker::META_NOTE, // The NWS alert sentence lives only in the note.
			Checker::META_AT_LINE,
			Checker::META_UNAVAILABLE_FOR,
		);

		$meta = array();
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( is_scalar( $value ) ) {
				$meta[ $key ] = (string) $value;
			}
		}

		return $meta;
	}

	/**
	 * Invoice done: any invoice meta key non-empty on the destination, or
	 * (wrapper) on every live source.
	 *
	 * @param object   $order   Destination order.
	 * @param object[] $sources Live sources of a wrapper.
	 * @return array{done: bool, where: string}
	 */
	public static function invoice( $order, array $sources ) {
		$keys = self::invoice_meta_keys();

		if ( self::has_invoice_meta( $order, $keys ) ) {
			return array(
				'done'  => true,
				'where' => 'order',
			);
		}

		if ( $sources ) {
			foreach ( $sources as $source ) {
				if ( ! self::has_invoice_meta( $source, $keys ) ) {
					return array(
						'done'  => false,
						'where' => '',
					);
				}
			}
			return array(
				'done'  => true,
				'where' => 'sources',
			);
		}

		return array(
			'done'  => false,
			'where' => '',
		);
	}

	/**
	 * Whether any of the keys is non-empty on an order.
	 *
	 * @param object   $order Order.
	 * @param string[] $keys  Meta keys.
	 * @return bool
	 */
	private static function has_invoice_meta( $order, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return true;
				}
				continue;
			}
			if ( is_object( $value ) ) {
				return true; // A DateTime-like object counts as set.
			}
			$text = trim( (string) $value );
			if ( '' !== $text && '0' !== $text ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tracking: ShipTracker rows on the destination, or (wrapper) on every
	 * live source. Count = distinct tracking numbers (ShipTracker fans one
	 * label of a combined box out to a row per source order, so that box
	 * counts once); status = the newest row's.
	 *
	 * @param object   $order   Destination order.
	 * @param object[] $sources Live sources of a wrapper.
	 * @return array{done: bool, count: int, status: string, status_label: string}
	 */
	public static function tracking( $order, array $sources ) {
		$out = array(
			'done'         => false,
			'count'        => 0,
			'status'       => '',
			'status_label' => '',
		);

		if ( ! self::has_shiptracker() ) {
			return $out;
		}

		$rows = self::shipments( (int) $order->get_id() );
		if ( $rows ) {
			$out['done']  = true;
			$out['count'] = count( $rows );
			self::apply_status( $out, $rows );
			return $out;
		}

		if ( ! $sources ) {
			return $out;
		}

		// Keyed by the normalised tracking number: the same label on two
		// source orders is one physical box. Rows arrive newest first per
		// order, so the first row seen for a number is kept.
		$all   = array();
		$every = true;
		foreach ( $sources as $source ) {
			$source_rows = self::shipments( (int) $source->get_id() );
			if ( ! $source_rows ) {
				$every = false;
			}
			foreach ( $source_rows as $row ) {
				$key = strtoupper( trim( (string) ( $row->tracking_number ?? '' ) ) );
				if ( '' === $key ) {
					$key = 'id:' . (int) ( $row->id ?? 0 );
				}
				if ( ! isset( $all[ $key ] ) ) {
					$all[ $key ] = $row;
				}
			}
		}

		$out['count'] = count( $all );
		if ( $all ) {
			self::apply_status( $out, array_values( $all ) );
		}
		$out['done'] = $every;

		return $out;
	}

	/**
	 * ShipTracker rows for an order (never throws).
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	private static function shipments( $order_id ) {
		try {
			$rows = \FST_Shipment::get_by_order( $order_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return array();
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) || is_array( $row ) ) {
				$out[] = (object) $row;
			}
		}

		return $out;
	}

	/**
	 * Put the newest row's status (and its label) on a tracking result.
	 *
	 * @param array    $out  Tracking result (by reference).
	 * @param object[] $rows Rows, newest first per order.
	 */
	private static function apply_status( array &$out, array $rows ) {
		$newest = null;
		foreach ( $rows as $row ) {
			if ( null === $newest || (string) ( $row->created_at ?? '' ) > (string) ( $newest->created_at ?? '' ) ) {
				$newest = $row;
			}
		}

		$status = $newest ? (string) ( $newest->status ?? '' ) : '';

		$out['status']       = $status;
		$out['status_label'] = self::tracking_status_label( $status );
	}

	/**
	 * Human label for a ShipTracker status (its own table when present).
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function tracking_status_label( $status ) {
		$status = (string) $status;
		if ( '' === $status ) {
			return '';
		}

		if ( class_exists( 'FST_Carrier' ) && is_callable( array( 'FST_Carrier', 'get_status_label' ) ) ) {
			try {
				return (string) \FST_Carrier::get_status_label( $status );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return ucwords( str_replace( '_', ' ', $status ) );
	}
}
