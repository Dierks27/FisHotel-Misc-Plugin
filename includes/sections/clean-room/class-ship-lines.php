<?php
/**
 * Clean Room ship lines — the fish that go out the door today, read
 * from the shop. The WooCommerce edge of the feeding rule (Feeding is
 * the rule itself, and pure).
 *
 * Nothing here is a third way to read an order. It asks the readers the
 * plugin already has:
 *
 *   - the orders of one delivery date, in one store-agnostic query:
 *     the Weather section's Checker::open_orders() — the query the
 *     Today page runs;
 *   - the lines of an order: the Lost Fish section's Orders::lines() —
 *     refunds netted, an invoice's "Adjustment" dropped, an invoice
 *     kept as one line split into its fish, the Invoices section's link
 *     to a tank group added;
 *   - which product is a fish: the Today section's
 *     Collector::fish_categories() and product_category_slugs().
 *
 * Those three sections may be switched off while the Clean Room is on.
 * Their class files are then loaded here, one by one and only on a
 * cache miss (load_readers()): six files that register no hook, open no
 * table and run nothing at load — nobody tells whether Weather, Today
 * or Lost Fish is on by these classes being there.
 *
 * Cost: today() is cached in one transient for CACHE_SECONDS and
 * dropped whenever an order changes (HOOKS), so a new order takes Feed
 * off on the next page load and a cancelled one brings it back. A cache
 * miss is one order query for tomorrow's delivery date plus the lines
 * of that day's orders. With the tick in Settings off, or without the
 * Batch Manager (no order has a ship day), nothing is asked at all.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ship_Lines
 */
class Ship_Lines {

	/**
	 * The transient holding today's shipping fish.
	 *
	 * @var string
	 */
	const TRANSIENT = 'fishotel_cleanroom_ship_lines';

	/**
	 * How long it is kept (seconds) when no order changes.
	 *
	 * @var int
	 */
	const CACHE_SECONDS = 300;

	/**
	 * The order events that drop it: the three the Today section listens
	 * to, and a new order (one created straight into "processing" changes
	 * no status).
	 *
	 * @var string[]
	 */
	const HOOKS = array( 'woocommerce_order_status_changed', 'woocommerce_update_order', 'woocommerce_order_note_added', 'woocommerce_new_order' );

	/**
	 * Order and item meta read here (written by the Batch Manager and the
	 * theme).
	 */
	const META_DELIVERY    = '_fishotel_shipping_date';
	const META_WRAPPER     = '_fishotel_is_fulfillment';
	const META_SOURCES     = '_fishotel_fulfillment_sources';
	const META_FOLDED      = '_fishotel_fulfilled_by_order';
	const META_LINE_STATUS = '_fishotel_line_status';

	/**
	 * The classes this file asks, and the file each lives in (under
	 * includes/sections/).
	 *
	 * @var array<string, string>
	 */
	const READERS = array(
		'FisHotel\\Misc\\Sections\\Weather\\Note'     => 'weather/class-note.php',
		'FisHotel\\Misc\\Sections\\Weather\\Checker'  => 'weather/class-checker.php',
		'FisHotel\\Misc\\Sections\\Today\\Collector'  => 'today/class-collector.php',
		'FisHotel\\Misc\\Sections\\Lost_Fish\\Money'  => 'lost-fish/class-money.php',
		'FisHotel\\Misc\\Sections\\Lost_Fish\\Cover'  => 'lost-fish/class-cover.php',
		'FisHotel\\Misc\\Sections\\Lost_Fish\\Orders' => 'lost-fish/class-orders.php',
	);

	/**
	 * Drop the cache whenever an order changes. Called from the section's
	 * boot only: while the Clean Room is switched off nothing is hooked.
	 */
	public static function register() {
		foreach ( self::HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush' ), 10, 0 );
		}
	}

	/**
	 * Forget today's shipping fish (the next page load reads them again).
	 */
	public static function flush() {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Whether the Batch Manager is active — the plugin that gives every
	 * order its delivery date, and so its ship day.
	 *
	 * @return bool
	 */
	public static function batch_present() {
		$present = defined( 'FISHOTEL_VERSION' );
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'fishotel_cleanroom_batch_present', $present ) : $present;
	}

	/**
	 * Whether WooCommerce's orders can be read.
	 *
	 * @return bool
	 */
	public static function shop_present() {
		return function_exists( 'wc_get_orders' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * What the rule is today (Feeding::state()).
	 *
	 * @param array $settings Settings.
	 * @return string One of Feeding's STATE_ constants.
	 */
	public static function state( array $settings ) {
		return Feeding::state( $settings, self::batch_present(), self::shop_present() );
	}

	/**
	 * Which waters go without food today (Feeding::plan()) — never an
	 * exception for the room that asked: when the shop cannot be read the
	 * day is an ordinary one.
	 *
	 * @param string        $today    Site date.
	 * @param array         $settings Settings.
	 * @param array[]       $stays    Every open stay, hydrated.
	 * @param array[]       $tanks    Every tank.
	 * @param callable|null $gone     Gives the closed stays (hydrated, newest first) — asked only on a day
	 *                                fish ship: a group whose last fish was bagged today still says
	 *                                where that fish was.
	 * @return array Feeding::plan().
	 */
	public static function fast( $today, array $settings, array $stays, array $tanks, $gone = null ) {
		try {
			$state = self::state( $settings );
			if ( Feeding::STATE_ON !== $state ) {
				return Feeding::none( $state, $today );
			}
			$ship = self::today( $today );
			return Feeding::plan( $ship, $stays, $tanks, ! empty( $ship['lines'] ) && is_callable( $gone ) ? (array) call_user_func( $gone ) : array() );
		} catch ( \Throwable $e ) {
			return Feeding::none( Feeding::STATE_NO_SHOP, $today );
		}
	}

	/**
	 * The fish going out today, from the cache when it was read for this
	 * site date.
	 *
	 * @param string $today Site date.
	 * @return array Feeding::shipping(): date, orders, fish, lines.
	 */
	public static function today( $today ) {
		$today  = (string) $today;
		$cached = function_exists( 'get_transient' ) ? get_transient( self::TRANSIENT ) : false;
		if ( is_array( $cached ) && (string) ( $cached['date'] ?? '' ) === $today && isset( $cached['lines'] ) && is_array( $cached['lines'] ) ) {
			return $cached;
		}

		$ship = self::read( $today );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::TRANSIENT, $ship, self::CACHE_SECONDS );
		}

		return $ship;
	}

	/**
	 * Read the shop: the orders delivering tomorrow, the orders inside
	 * their combined boxes, and the lines of the ones that go out.
	 *
	 * @param string $today Site date.
	 * @return array Feeding::shipping().
	 */
	public static function read( $today ) {
		$delivery = Feeding::delivery_for( $today );
		if ( '' === $delivery || ! self::shop_present() || ! self::load_readers() ) {
			return Feeding::shipping( array(), $today, array() );
		}

		$objects = array();
		$orders  = array();
		foreach ( \FisHotel\Misc\Sections\Weather\Checker::open_orders( Feeding::STATUSES, array( $delivery ) ) as $order ) {
			$head = self::head( $order );
			if ( $head ) {
				$objects[ $head['id'] ] = $order;
				$orders[ $head['id'] ]  = $head;
			}
		}
		// The orders inside a combined box carry the fish; the box only says when it ships.
		foreach ( $orders as $head ) {
			foreach ( $head['wrapper'] ? $head['sources'] : array() as $source_id ) {
				if ( isset( $orders[ $source_id ] ) ) {
					continue;
				}
				$source = wc_get_order( $source_id );
				$inner  = is_object( $source ) ? self::head( $source ) : null;
				if ( $inner ) {
					$objects[ $inner['id'] ] = $source;
					$orders[ $inner['id'] ]  = $inner;
				}
			}
		}

		$going = Feeding::going_out( $orders, $today );
		foreach ( $going as $id => $head ) {
			$going[ $id ]['rows'] = self::rows( $objects[ $id ] );
		}

		return Feeding::shipping( $going, $today, \FisHotel\Misc\Sections\Today\Collector::fish_categories() );
	}

	/**
	 * Load the classes of the other sections this file asks, when their
	 * section did not (it is switched off).
	 *
	 * @return bool Whether all of them are there.
	 */
	public static function load_readers() {
		$dir = FISHOTEL_MISC_PATH . 'includes/sections/';
		foreach ( self::READERS as $class => $file ) {
			if ( class_exists( $class, false ) ) {
				continue;
			}
			if ( ! file_exists( $dir . $file ) ) {
				return false;
			}
			require_once $dir . $file;
		}
		return true;
	}

	/**
	 * What going_out() needs to know about an order.
	 *
	 * @param object $order Order (or a stand-in with the same getters).
	 * @return array|null id, number, status, delivery, wrapper, folded, sources, created — null for anything that is no order.
	 */
	public static function head( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'get_status' ) || ! method_exists( $order, 'get_items' ) ) {
			return null;
		}
		$id = (int) $order->get_id();
		if ( $id <= 0 ) {
			return null;
		}

		$meta = static function ( $key ) use ( $order ) {
			$value = $order->get_meta( $key );
			return is_scalar( $value ) ? trim( (string) $value ) : $value;
		};

		$sources = array();
		$raw     = $meta( self::META_SOURCES );
		foreach ( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) as $source_id ) {
			$source_id = is_scalar( $source_id ) ? (int) trim( (string) $source_id ) : 0;
			if ( $source_id > 0 && $source_id !== $id ) {
				$sources[ $source_id ] = $source_id;
			}
		}

		$created = 0;
		try {
			$date = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
				$created = (int) $date->getTimestamp();
			}
		} catch ( \Throwable $e ) {
			$created = 0;
		}

		$delivery = $meta( self::META_DELIVERY );
		$folded   = $meta( self::META_FOLDED );

		return array(
			'id'       => $id,
			'number'   => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $id,
			'status'   => Feeding::status( $order->get_status() ),
			'delivery' => is_string( $delivery ) && Replay::is_date( $delivery ) ? $delivery : '',
			'wrapper'  => '1' === $meta( self::META_WRAPPER ),
			'folded'   => is_string( $folded ) && '' !== $folded,
			'sources'  => array_values( $sources ),
			'created'  => $created,
			'rows'     => array(),
		);
	}

	/**
	 * The lines of an order as the Lost Fish section reads them, each
	 * with what only its order item knows: the product's categories and
	 * title, the theme's line status, and its size.
	 *
	 * @param object $order Order.
	 * @return array[] Rows for Feeding::fish_lines().
	 */
	public static function rows( $order ) {
		$reader = new \FisHotel\Misc\Sections\Lost_Fish\Orders();
		$rows   = (array) $reader->lines( $order );

		$items = array();
		try {
			foreach ( (array) $order->get_items( 'line_item' ) as $key => $item ) {
				if ( is_object( $item ) ) {
					$items[ method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $key ] = $item;
				}
			}
		} catch ( \Throwable $e ) {
			$items = array();
		}

		$facts = array();
		foreach ( $rows as $index => $row ) {
			$item_id = (int) ( $row['item_id'] ?? 0 );
			if ( ! isset( $facts[ $item_id ] ) ) {
				$facts[ $item_id ] = isset( $items[ $item_id ] ) ? self::item_facts( $items[ $item_id ] ) : array(
					'categories'  => array(),
					'title'       => '',
					'line_status' => '',
					'size_slug'   => '',
					'size_label'  => '',
				);
			}
			$mine = $facts[ $item_id ];
			if ( 'product' !== (string) ( $row['type'] ?? '' ) && 'invoice_fish' !== (string) ( $row['type'] ?? '' ) ) {
				// A fish out of an invoice kept as one line: the item is the invoice, not the fish.
				$mine['title']      = '';
				$mine['size_slug']  = '';
				$mine['size_label'] = '';
			}
			$rows[ $index ] = array_merge( $row, $mine );
		}

		return $rows;
	}

	/**
	 * What an order item knows about its fish.
	 *
	 * @param object $item Order item.
	 * @return array{categories: string[], title: string, line_status: string, size_slug: string, size_label: string}
	 */
	public static function item_facts( $item ) {
		$out = array(
			'categories'  => array(),
			'title'       => '',
			'line_status' => '',
			'size_slug'   => '',
			'size_label'  => '',
		);

		try {
			$status = method_exists( $item, 'get_meta' ) ? $item->get_meta( self::META_LINE_STATUS ) : '';
			if ( is_scalar( $status ) ) {
				$out['line_status'] = strtolower( trim( (string) $status ) );
			}

			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			if ( is_object( $product ) ) {
				$out['categories'] = \FisHotel\Misc\Sections\Today\Collector::product_category_slugs( $product );
				$out['title']      = self::product_title( $product );
			}

			// The size: what the line was bought as (WooCommerce keeps the chosen size on the item), else
			// what the variation says now.
			$sizes = array();
			if ( method_exists( $item, 'get_meta_data' ) ) {
				foreach ( (array) $item->get_meta_data() as $meta ) {
					if ( is_object( $meta ) && isset( $meta->key, $meta->value ) && is_scalar( $meta->value ) ) {
						$sizes[ (string) $meta->key ] = (string) $meta->value;
					}
				}
			}
			if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 && method_exists( $product, 'get_attributes' ) ) {
				foreach ( (array) $product->get_attributes() as $key => $value ) {
					if ( is_scalar( $value ) && ! isset( $sizes[ (string) $key ] ) ) {
						$sizes[ (string) $key ] = (string) $value;
					}
				}
			}
			foreach ( $sizes as $key => $value ) {
				$key = 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key;
				if ( '_' !== substr( $key, 0, 1 ) && Sizes::matches( $key ) && '' !== trim( $value ) ) {
					$out['size_slug']  = Sizes::slug( $value );
					$out['size_label'] = trim( $value );
					break;
				}
			}
		} catch ( \Throwable $e ) {
			return $out;
		}

		return $out;
	}

	/**
	 * A product's own title — its parent's for a variation ("Yellow
	 * Tang", not "Yellow Tang - Medium").
	 *
	 * @param object $product Product.
	 * @return string
	 */
	private static function product_title( $product ) {
		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 && function_exists( 'get_the_title' ) ) {
			return trim( html_entity_decode( (string) get_the_title( (int) $product->get_parent_id() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return method_exists( $product, 'get_title' ) ? trim( (string) $product->get_title() ) : ( method_exists( $product, 'get_name' ) ? trim( (string) $product->get_name() ) : '' );
	}
}
