<?php
/**
 * Clean Room reserved fish — the WooCommerce edge of Reserve: it reads
 * the shop's orders, keeps each fish line's reservation on its order
 * item, writes the `sold` events that take a shipped fish out of its
 * tank, and keeps the short list of the fish that shipped but whose tank
 * could not be found.
 *
 * Nothing here is a new way to read an order: the order's head and its
 * lines are Ship_Lines' (the Lost Fish section's Orders::lines() with
 * the Invoices link, the theme's line status, the size), its fish are
 * Feeding::fish_lines()', and its shipments are the Lost Fish section's
 * Orders::shipments() (ShipTracker's own table).
 *
 * When it runs:
 *   - on a read: the Board, the To do tab, the phone, the Invoices
 *     picker, the Requests job — ensure() works the reservations out
 *     once (one transient, dropped on every order hook the feeding cache
 *     listens to) and gives every open order that is not reserved yet
 *     its groups: orders already open when this was switched on are
 *     reserved too;
 *   - on an order hook in wp-admin or cron (ShipTracker adds tracking,
 *     an order is completed, cancelled, refunded): at shutdown, that
 *     order is reserved if it is open (or was, until a moment ago) and
 *     its shipped fish are written; on the storefront (checkout) an
 *     order hook only drops the cache — the next read reserves it;
 *   - hourly (WP-cron): the orders that left in the last 7 days are
 *     looked at again — removing tracking in ShipTracker fires no hook.
 *
 * Install safety: the first time the feature runs it stores its
 * switch-on time (kept when it is switched off and on), and only a line
 * seen OPEN after it (_fh_stay_seen) is ever written: an order that had
 * already shipped is never reserved and never taken out.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reserve_Shop
 */
class Reserve_Shop {

	/**
	 * The transient holding every group's reserved fish.
	 *
	 * @var string
	 */
	const TRANSIENT = 'fishotel_cleanroom_reserved';

	/**
	 * How long it is kept when no order changes (seconds).
	 *
	 * @var int
	 */
	const CACHE_SECONDS = 600;

	/**
	 * The option holding the fish that shipped and whose tank could not be
	 * found: "order id|ref" => entry.
	 *
	 * @var string
	 */
	const LOST_OPTION = 'fishotel_cleanroom_reserve_lost';

	/**
	 * The hourly sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'fishotel_cleanroom_reserve_sweep';

	/**
	 * How far back the sweep looks (days).
	 *
	 * @var int
	 */
	const SWEEP_DAYS = 7;

	/**
	 * How many open orders one read looks at, and how many orders the
	 * sweep looks at again.
	 */
	const OPEN_LIMIT  = 400;
	const SWEEP_LIMIT = 200;

	/**
	 * How many entries the not-found list keeps (the oldest go first).
	 *
	 * @var int
	 */
	const LOST_MAX = 200;

	/**
	 * The admin-post action of the To do tab's corrections, and its nonce.
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_cleanroom_reserve';

	/**
	 * What _fst_ship_mode is (ShipTracker).
	 *
	 * @var string
	 */
	const META_SHIP_MODE = '_fst_ship_mode';

	/**
	 * The orders an order hook asked for, worked at shutdown: id => was open.
	 *
	 * @var array<int, bool>
	 */
	private static $queue = array();

	/**
	 * What this request already worked out: counts, the groups.
	 *
	 * @var array
	 */
	private static $memo = array();

	/* ============================================================== */
	/*  On, off, since                                                 */
	/* ============================================================== */

	/**
	 * Whether the feature runs: the tick, and the shop's orders.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function enabled( array $settings ) {
		return ! empty( $settings[ Reserve::SETTING ] ) && Ship_Lines::shop_present();
	}

	/**
	 * The moment it was first switched on (UNIX time; 0 = never) — stored
	 * the first time, never moved after.
	 *
	 * @param bool $start Store now when it was never stored.
	 * @return int
	 */
	public static function since( $start = false ) {
		$since = (int) get_option( Reserve::SINCE_OPTION, 0 );
		if ( $since <= 0 && $start ) {
			$since = time();
			update_option( Reserve::SINCE_OPTION, $since, false );
		}
		return $since;
	}

	/**
	 * Forget what this request worked out (the tests start over with it).
	 */
	public static function reset() {
		self::$memo  = array();
		self::$queue = array();
	}

	/**
	 * Hooks — called from the section's boot only: while the Clean Room is
	 * switched off nothing is hooked (and boot_disabled() unschedules).
	 *
	 * @param array $settings Settings.
	 */
	public static function register( array $settings ) {
		add_action( self::CRON_HOOK, array( self::class, 'cron' ), 10, 0 );
		if ( ! self::enabled( $settings ) ) {
			self::unschedule();
			return;
		}
		self::since( true );

		foreach ( Ship_Lines::HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush' ), 10, 0 );
		}
		foreach ( Ship_Lines::ITEM_META_HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush_for_item_meta' ), 10, 3 );
		}
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status' ), 20, 3 );
		add_action( 'woocommerce_new_order', array( self::class, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_order_note_added', array( self::class, 'on_note' ), 20, 2 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_order' ), 20, 1 );
		self::schedule();
	}

	/**
	 * Arm the hourly sweep when nothing is pending (wp_next_scheduled()
	 * reads the autoloaded cron option: no query).
	 */
	public static function schedule() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) && function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Drop the hourly sweep (the tick off, the section off, the plugin
	 * deactivated).
	 */
	public static function unschedule() {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK ) && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Forget the reserved counts (the next read works them out again).
	 */
	public static function flush() {
		self::$memo = array();
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Forget them when a line's status or its reservation was written.
	 *
	 * @param mixed $meta_id  Meta id(s) (unused).
	 * @param mixed $item_id  Order item id (unused).
	 * @param mixed $meta_key The key written.
	 */
	public static function flush_for_item_meta( $meta_id = 0, $item_id = 0, $meta_key = '' ) {
		if ( Ship_Lines::META_LINE_STATUS === $meta_key ) {
			self::flush();
		}
	}

	/* ============================================================== */
	/*  The order hooks                                                */
	/* ============================================================== */

	/**
	 * Whether this request may do the work now: wp-admin (ShipTracker's
	 * buttons are admin-ajax), cron or the command line — never the
	 * storefront's checkout.
	 *
	 * @return bool
	 */
	private static function back_office() {
		return ( function_exists( 'is_admin' ) && is_admin() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * An order changed status. Into or within open: reserved (in
	 * wp-admin); out of open (shipped, completed, cancelled…): worked
	 * wherever it happens — it was open until now.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Old status.
	 * @param mixed $to       New status.
	 */
	public static function on_status( $order_id = 0, $from = '', $to = '' ) {
		self::flush();
		$was_open = in_array( Feeding::status( $from ), Reserve::OPEN_STATUSES, true );
		if ( self::back_office() || ( $was_open && ! in_array( Feeding::status( $to ), Reserve::OPEN_STATUSES, true ) ) ) {
			self::queue( (int) $order_id, $was_open );
		}
	}

	/**
	 * A new order, or a refund: in wp-admin, worked at shutdown.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function on_order( $order_id = 0 ) {
		self::flush();
		if ( self::back_office() ) {
			self::queue( (int) $order_id, false );
		}
	}

	/**
	 * A note on an order — ShipTracker writes one when tracking is added
	 * (also a partial shipment, which changes no status), and when an
	 * order is marked as a local pickup or as gone in another order's box.
	 *
	 * @param mixed $note_id Note id (unused).
	 * @param mixed $order   The order.
	 */
	public static function on_note( $note_id = 0, $order = null ) {
		self::flush();
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) && self::back_office() ) {
			self::queue( (int) $order->get_id(), false );
		}
	}

	/**
	 * Remember an order for the end of the request.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $was_open It was open until this change.
	 */
	public static function queue( $order_id, $was_open = false ) {
		if ( (int) $order_id <= 0 ) {
			return;
		}
		if ( ! self::$queue && function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( self::class, 'run_queue' ), 20, 0 );
		}
		self::$queue[ (int) $order_id ] = ! empty( self::$queue[ (int) $order_id ] ) || $was_open;
	}

	/**
	 * Work the orders the hooks asked for (shutdown).
	 *
	 * @param object|null $store    Store (null = the real one).
	 * @param array|null  $settings Settings (null = the stored ones).
	 */
	public static function run_queue( $store = null, $settings = null ) {
		$queue       = self::$queue;
		self::$queue = array();
		if ( ! $queue ) {
			return;
		}
		$settings = is_array( $settings ) ? $settings : Settings::get();
		try {
			if ( ! is_object( $store ) ) {
				if ( ! Store::tables_exist() ) {
					return;
				}
				$store = new Store();
			}
			if ( ! self::enabled( $settings ) ) {
				return;
			}
			foreach ( $queue as $order_id => $was_open ) {
				self::process( $store, $settings, (int) $order_id, (bool) $was_open );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		self::flush();
	}

	/**
	 * The hourly sweep.
	 */
	public static function cron() {
		$settings = Settings::get();
		if ( ! self::enabled( $settings ) ) {
			self::unschedule();
			return;
		}
		try {
			if ( Store::tables_exist() ) {
				self::sweep( new Store(), $settings );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Look again at every order that may have left in the last days:
	 * the ones whose fish were taken out lately, and the orders changed
	 * since (never before the switch-on).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $now      UNIX time (null = now).
	 * @return int How many orders were looked at.
	 */
	public static function sweep( $store, array $settings, $now = null ) {
		if ( ! self::enabled( $settings ) || ! Ship_Lines::load_readers() ) {
			return 0;
		}
		$now   = null === $now ? time() : (int) $now;
		$after = max( self::since( true ), $now - self::SWEEP_DAYS * DAY_IN_SECONDS );

		self::flush();
		self::ensure( $store, $settings );

		$ids = array();
		foreach ( (array) $store->order_ids_shipped_since( gmdate( 'Y-m-d H:i:s', $now - self::SWEEP_DAYS * DAY_IN_SECONDS ) ) as $id ) {
			$ids[ (int) $id ] = true;
		}
		try {
			$changed = wc_get_orders(
				array(
					'type'          => 'shop_order',
					'status'        => array_merge( Reserve::OPEN_STATUSES, Reserve::LEFT_STATUSES, Feeding::DEAD_STATUSES ),
					'date_modified' => '>' . $after,
					'limit'         => self::SWEEP_LIMIT,
					'orderby'       => 'ID',
					'order'         => 'DESC',
					'return'        => 'ids',
				)
			);
		} catch ( \Throwable $e ) {
			$changed = array();
		}
		foreach ( (array) $changed as $id ) {
			$id = is_object( $id ) && method_exists( $id, 'get_id' ) ? (int) $id->get_id() : (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		foreach ( array_keys( $ids ) as $id ) {
			self::process( $store, $settings, (int) $id, false );
		}
		self::flush();
		return count( $ids );
	}

	/* ============================================================== */
	/*  Reading an order                                               */
	/* ============================================================== */

	/**
	 * What the rules need to know about an order: its head, its fish
	 * lines (with what their items keep of the reservation), and whether
	 * they left — its status, ShipTracker's ship mode and shipments, and
	 * the same for the combined box it is in.
	 *
	 * @param object     $order Order.
	 * @param array|null $box   The box's signals, when the caller already has them.
	 * @return array|null id, number, status, wrapper, folded, sources, lines, signals.
	 */
	public static function facts( $order, $box = null ) {
		$head = Ship_Lines::head( $order );
		if ( ! $head ) {
			return null;
		}
		$rows         = Ship_Lines::rows( $order );
		$head['rows'] = $rows;
		$by_ref       = array();
		foreach ( $rows as $row ) {
			$by_ref[ (string) ( $row['ref'] ?? '' ) ] = $row;
		}
		$lines = array();
		foreach ( Feeding::fish_lines( $head, \FisHotel\Misc\Sections\Today\Collector::fish_categories(), Ship_Lines::loose_categories(), true ) as $line ) {
			$row     = $by_ref[ $line['ref'] ] ?? array();
			$lines[] = array_merge(
				$line,
				array(
					'seen'    => (int) ( $row['seen'] ?? 0 ),
					'was'     => (array) ( $row['was'] ?? array() ),
					'ignored' => ! empty( $row['ignored'] ),
				)
			);
		}
		unset( $head['rows'] );

		if ( null === $box && $head['folded'] ) {
			$wrapper = wc_get_order( (int) $order->get_meta( Ship_Lines::META_FOLDED ) );
			$box     = is_object( $wrapper ) ? self::signals( $wrapper ) : null;
		}
		$head['lines']   = $lines;
		$head['signals'] = array_merge( self::signals( $order ), array( 'box' => $box ) );
		return $head;
	}

	/**
	 * Whether an order went out: its status, ShipTracker's ship mode and
	 * its shipments.
	 *
	 * @param object $order Order.
	 * @return array{status: string, mode: string, whole: bool, items: array<int, true>}
	 */
	public static function signals( $order ) {
		$shipments = array(
			'whole' => false,
			'items' => array(),
		);
		if ( class_exists( '\\FisHotel\\Misc\\Sections\\Lost_Fish\\Orders', false ) ) {
			$reader    = new \FisHotel\Misc\Sections\Lost_Fish\Orders();
			$shipments = $reader->shipments( $order );
		}
		$mode = method_exists( $order, 'get_meta' ) ? $order->get_meta( self::META_SHIP_MODE ) : '';
		return array(
			'status' => Feeding::status( $order->get_status() ),
			'mode'   => is_scalar( $mode ) ? trim( (string) $mode ) : '',
			'whole'  => ! empty( $shipments['whole'] ),
			'items'  => (array) $shipments['items'],
		);
	}

	/**
	 * The orders a change to one order concerns: a combined box is worked
	 * through the orders in it (its own lines are copies) — itself only
	 * when none of them can be read.
	 *
	 * @param object $order Order.
	 * @return array[] facts() of each.
	 */
	private static function targets( $order ) {
		$head = Ship_Lines::head( $order );
		if ( ! $head ) {
			return array();
		}
		if ( ! $head['wrapper'] ) {
			$facts = self::facts( $order );
			return $facts ? array( $facts ) : array();
		}
		$box   = self::signals( $order );
		$out   = array();
		$known = 0;
		foreach ( $head['sources'] as $source_id ) {
			$source = wc_get_order( (int) $source_id );
			if ( ! is_object( $source ) ) {
				continue;
			}
			$known++;
			$facts = self::facts( $source, $box );
			if ( $facts && ! $facts['wrapper'] ) {
				$out[] = $facts;
			}
		}
		if ( 0 === $known ) {
			$facts = self::facts( $order );
			if ( $facts ) {
				$out[] = $facts;
			}
		}
		return $out;
	}

	/**
	 * The groups a customer was offered by a request (the Requests
	 * section, when it is on): product id => stay ids.
	 *
	 * @param object $order Order.
	 * @return array<int, int[]>
	 */
	private static function offered( $order ) {
		$class = '\\FisHotel\\Misc\\Sections\\Requests\\Store';
		if ( ! class_exists( $class, false ) || ! method_exists( $class, 'ready' ) || ! $class::ready() ) {
			return array();
		}
		$out = array();
		try {
			$store   = new $class();
			$filters = array();
			$user    = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
			$email   = method_exists( $order, 'get_billing_email' ) ? trim( (string) $order->get_billing_email() ) : '';
			if ( $user > 0 ) {
				$filters[] = array( 'user_id' => $user );
			}
			if ( '' !== $email ) {
				$filters[] = array( 'email' => strtolower( $email ) );
			}
			foreach ( $filters as $filter ) {
				foreach ( (array) $store->find( array_merge( $filter, array( 'state' => array( 'offered', 'filled' ) ) ) ) as $request ) {
					if ( ! empty( $request['product_id'] ) && ! empty( $request['stay_id'] ) ) {
						$out[ (int) $request['product_id'] ][] = (int) $request['stay_id'];
					}
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * A line's offered groups.
	 *
	 * @param array             $line    A fish line.
	 * @param array<int, int[]> $offered offered().
	 * @return int[]
	 */
	private static function offered_for( array $line, array $offered ) {
		$out = array();
		foreach ( array( (int) ( $line['product_id'] ?? 0 ), (int) ( $line['variation_id'] ?? 0 ) ) as $id ) {
			if ( $id > 0 && isset( $offered[ $id ] ) ) {
				$out = array_merge( $out, $offered[ $id ] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ============================================================== */
	/*  Reserved                                                       */
	/* ============================================================== */

	/**
	 * Every open group, and the ones in clean water — read once per
	 * request.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @return array{all: array[], clean: array[], tanks: array[]}
	 */
	private static function stays( $store, array $settings ) {
		if ( ! isset( self::$memo['stays'] ) ) {
			$tanks              = (array) $store->tanks();
			$all                = (array) $store->open_stays( array(), Clean_Room::today(), $settings );
			self::$memo['stays'] = array(
				'all'   => $all,
				'clean' => Feeding::clean_stays( $all, $tanks ),
				'tanks' => $tanks,
			);
		}
		return self::$memo['stays'];
	}

	/**
	 * Every group's reserved fish — from the cache, else worked out:
	 * every open order is read, and a line not reserved yet is given its
	 * groups (stored on its order item); a line seen for the first time is
	 * marked seen.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param bool   $force    Work it out even when cached.
	 * @return array{counts: array<int, array{qty: int, orders: string[]}>}
	 */
	public static function ensure( $store, array $settings, $force = false ) {
		if ( ! self::enabled( $settings ) ) {
			return array( 'counts' => array() );
		}
		if ( ! $force && isset( self::$memo['ensure'] ) ) {
			return self::$memo['ensure'];
		}
		$cached = $force ? false : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['counts'] ) && is_array( $cached['counts'] ) ) {
			self::$memo['ensure'] = $cached;
			return $cached;
		}

		$out = array( 'counts' => array() );
		if ( Ship_Lines::load_readers() ) {
			self::since( true );
			$out = self::pass( $store, $settings );
		}
		set_transient( self::TRANSIENT, $out, self::CACHE_SECONDS );
		self::$memo['ensure'] = $out;
		return $out;
	}

	/**
	 * One pass over the open orders (ensure()).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @return array{counts: array}
	 */
	private static function pass( $store, array $settings ) {
		try {
			$found = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => Reserve::OPEN_STATUSES,
					'limit'   => self::OPEN_LIMIT,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'objects',
				)
			);
		} catch ( \Throwable $e ) {
			$found = array();
		}

		$orders = array();
		foreach ( (array) $found as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}
			foreach ( self::targets( $order ) as $facts ) {
				if ( isset( $orders[ $facts['id'] ] ) || in_array( $facts['status'], Feeding::DEAD_STATUSES, true ) || Reserve::left( $facts['signals'], 0 ) ) {
					continue;
				}
				$orders[ $facts['id'] ] = $facts;
			}
		}
		ksort( $orders );

		$stays = self::stays( $store, $settings );
		$list  = array();
		foreach ( $orders as $id => $facts ) {
			$lines   = array();
			$offered = null;
			foreach ( $facts['lines'] as $line ) {
				if ( Reserve::left( $facts['signals'], (int) $line['item_id'] ) || (int) $line['qty'] <= 0 ) {
					continue; // Gone already (a partial shipment), or refunded to nothing: it holds no fish.
				}
				if ( (int) $line['qty'] > Reserve::total( Reserve::trim( $line['held'], (int) $line['qty'] ) ) ) {
					if ( null === $offered ) {
						$source  = wc_get_order( $id );
						$offered = is_object( $source ) ? self::offered( $source ) : array();
					}
					$line['offered'] = self::offered_for( $line, $offered );
				}
				$lines[] = $line;
			}
			$list[] = array(
				'id'     => $id,
				'number' => $facts['number'],
				'lines'  => $lines,
			);
		}

		$plan = Reserve::plan( $list, $stays['clean'], $stays['all'] );
		self::write_plan( $list, $plan['adds'] );

		return array( 'counts' => $plan['counts'] );
	}

	/**
	 * Store what a plan added, and mark every line seen.
	 *
	 * @param array[] $orders The orders planned (id, lines).
	 * @param array   $adds   Reserve::plan()['adds'].
	 */
	private static function write_plan( array $orders, array $adds ) {
		$wrote = false;
		foreach ( $orders as $order ) {
			foreach ( $order['lines'] as $line ) {
				$item = (int) $line['item_id'];
				$ref  = (string) $line['ref'];
				if ( $item <= 0 || null === Reserve::key( Reserve::META, $ref ) ) {
					continue;
				}
				if ( (int) ( $line['seen'] ?? 0 ) <= 0 ) {
					self::write_meta( $item, Reserve::key( Reserve::META_SEEN, $ref ), (string) time() );
					$wrote = true;
				}
				if ( ! empty( $adds[ (int) $order['id'] ][ $ref ] ) ) {
					self::write_meta( $item, Reserve::key( Reserve::META, $ref ), Reserve::encode( Reserve::merge( $line['held'], $adds[ (int) $order['id'] ][ $ref ] ) ) );
					$wrote = true;
				}
			}
		}
		if ( $wrote ) {
			Ship_Lines::flush(); // What it read of today's lines carried the reservations.
		}
	}

	/**
	 * Write one order item meta.
	 *
	 * @param int    $item_id Order item id.
	 * @param string $key     Key.
	 * @param string $value   Value ('' deletes).
	 */
	private static function write_meta( $item_id, $key, $value ) {
		if ( '' === (string) $value ) {
			if ( function_exists( 'wc_delete_order_item_meta' ) ) {
				wc_delete_order_item_meta( (int) $item_id, (string) $key );
			}
			return;
		}
		if ( function_exists( 'wc_update_order_item_meta' ) ) {
			wc_update_order_item_meta( (int) $item_id, (string) $key, (string) $value );
		}
	}

	/* ============================================================== */
	/*  One order: reserve it, take its shipped fish out               */
	/* ============================================================== */

	/**
	 * Work one order (and the orders in it, for a combined box).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $order_id Order id.
	 * @param bool   $was_open It was open until the change that asked: it is reserved now, as it was then.
	 * @return array[] What was done per order: id => { reserved, written, lost }.
	 */
	public static function process( $store, array $settings, $order_id, $was_open = false ) {
		if ( ! self::enabled( $settings ) || ! Ship_Lines::load_readers() ) {
			return array();
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! is_object( $order ) ) {
			return array();
		}
		$out = array();
		foreach ( self::targets( $order ) as $facts ) {
			$dead = in_array( $facts['status'], Feeding::DEAD_STATUSES, true );
			$open = in_array( $facts['status'], Reserve::OPEN_STATUSES, true );
			$did  = array(
				'reserved' => 0,
				'written'  => 0,
				'lost'     => 0,
			);
			if ( ! $dead && ( $open || $was_open ) ) {
				$facts           = self::reserve_one( $store, $settings, $facts, $open );
				$did['reserved'] = 1;
			}
			$done            = self::reconcile( $store, $settings, $facts );
			$did['written']  = $done['written'];
			$did['lost']     = $done['lost'];
			$out[ $facts['id'] ] = $did;
		}
		return $out;
	}

	/**
	 * Reserve one order's lines that hold too few fish — out of what the
	 * other open orders leave free.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $facts    facts().
	 * @param bool   $open     Still open (ensure() counted it already).
	 * @return array facts() with the reservations added.
	 */
	private static function reserve_one( $store, array $settings, array $facts, $open ) {
		$counts = self::ensure( $store, $settings )['counts'];
		if ( $open ) {
			// The counts may hold this order already: take its own fish out of them first.
			foreach ( $facts['lines'] as $line ) {
				foreach ( Reserve::trim( $line['held'], (int) $line['qty'] ) as $stay => $qty ) {
					if ( isset( $counts[ $stay ] ) ) {
						$counts[ $stay ]['qty'] = max( 0, $counts[ $stay ]['qty'] - $qty );
					}
				}
			}
		}
		$offered = null;
		$lines   = array();
		foreach ( $facts['lines'] as $line ) {
			if ( (int) $line['qty'] <= 0 ) {
				continue;
			}
			if ( (int) $line['qty'] > Reserve::total( Reserve::trim( $line['held'], (int) $line['qty'] ) ) ) {
				if ( null === $offered ) {
					$source  = wc_get_order( $facts['id'] );
					$offered = is_object( $source ) ? self::offered( $source ) : array();
				}
				$line['offered'] = self::offered_for( $line, $offered );
			}
			$lines[] = $line;
		}
		$order = array(
			'id'     => $facts['id'],
			'number' => $facts['number'],
			'lines'  => $lines,
		);
		$stays = self::stays( $store, $settings );
		$plan  = Reserve::plan( array( $order ), $stays['clean'], $stays['all'], $counts );
		self::write_plan( array( $order ), $plan['adds'] );
		if ( isset( self::$memo['ensure'] ) ) {
			self::$memo['ensure']['counts'] = $plan['counts'];
		}

		foreach ( $facts['lines'] as $i => $line ) {
			if ( ! empty( $plan['adds'][ $facts['id'] ][ $line['ref'] ] ) ) {
				$facts['lines'][ $i ]['held'] = Reserve::merge( $line['held'], $plan['adds'][ $facts['id'] ][ $line['ref'] ] );
			}
			if ( (int) $line['seen'] <= 0 && null !== Reserve::key( Reserve::META, $line['ref'] ) ) {
				$facts['lines'][ $i ]['seen'] = time();
			}
		}
		return $facts;
	}

	/**
	 * Take an order's shipped fish out of their tanks — or put them back
	 * — writing only the difference, and keep the not-found list.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $facts    facts().
	 * @return array{written: int, lost: int} Events written; fish on the not-found list for this order.
	 */
	public static function reconcile( $store, array $settings, array $facts ) {
		$today = Clean_Room::today();
		$lines = array();
		$ids   = array();
		foreach ( $facts['lines'] as $line ) {
			if ( null === Reserve::key( Reserve::META, $line['ref'] ) || ! Reserve::gated( $line ) ) {
				continue; // Never seen open after the switch-on: an order that had left before is never touched.
			}
			$lines[] = $line;
			foreach ( array_merge( array_keys( $line['held'] ), (array) $line['was'] ) as $stay ) {
				$ids[ (int) $stay ] = true;
			}
		}
		$events = $ids ? (array) $store->order_events( array_keys( $ids ) ) : array();

		$alive   = array();
		$lost    = self::lost();
		$changed = false;
		$written = 0;
		$missing = 0;
		foreach ( $lines as $line ) {
			$note = Reserve::note( $facts['id'], $line['ref'] );
			$done = Reserve::written( $events, $note );
			$gone = Reserve::left( $facts['signals'], (int) $line['item_id'] );
			$want = Reserve::wanted( $gone, $facts['status'], $line['held'], (int) $line['qty'], $done['net'] );
			foreach ( array_keys( $want ) as $stay ) {
				if ( ! isset( $alive[ $stay ] ) ) {
					$row            = $store->stay( (int) $stay, $today, $settings );
					$alive[ $stay ] = is_array( $row ) ? (int) $row['qty_live'] : 0;
				}
			}
			$diff = Reserve::diff( $want, $done['net'], $alive, $done['undone'] );
			foreach ( $diff['writes'] as $stay => $delta ) {
				$result = $store->record_event(
					(int) $stay,
					array(
						'type'     => 'sold',
						'qty'      => (int) $delta,
						'note'     => $note,
						'event_at' => gmdate( 'Y-m-d H:i:s' ),
						'actor_id' => 0,
						'source'   => Reserve::SOURCE,
					),
					$today,
					$settings
				);
				if ( $result ) {
					$written++;
					$alive[ $stay ] = (int) ( $result['state']['qty_live'] ?? ( $alive[ $stay ] - $delta ) );
				}
			}

			// Not found: it left, and fish of it could not be taken out of a tank the site knows.
			$key   = $facts['id'] . '|' . $line['ref'];
			$short = 0;
			if ( $gone && empty( $line['ignored'] ) && empty( $line['loose'] ) ) {
				$short = max( 0, (int) $line['qty'] - Reserve::total( Reserve::trim( $line['held'], (int) $line['qty'] ) ) ) + $diff['short'];
			}
			if ( $short > 0 ) {
				$entry = self::lost_entry( $facts, $line, $short, $diff['short'] > 0 ? 'short' : 'unknown', $today );
				if ( ! isset( $lost[ $key ] ) || array_diff_key( $entry, array( 'date' => 1 ) ) !== array_diff_key( $lost[ $key ], array( 'date' => 1 ) ) ) {
					$entry['date'] = isset( $lost[ $key ]['date'] ) ? $lost[ $key ]['date'] : $entry['date'];
					$lost[ $key ]  = $entry;
					$changed       = true;
				}
				$missing += $short;
			} elseif ( isset( $lost[ $key ] ) ) {
				unset( $lost[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save_lost( $lost );
		}
		return array(
			'written' => $written,
			'lost'    => $missing,
		);
	}

	/* ============================================================== */
	/*  Not found                                                      */
	/* ============================================================== */

	/**
	 * One entry of the not-found list.
	 *
	 * @param array  $facts facts().
	 * @param array  $line  The line.
	 * @param int    $qty   Fish not found.
	 * @param string $why   unknown (no tank) | short (its tank has fewer fish alive).
	 * @param string $today Site date.
	 * @return array
	 */
	private static function lost_entry( array $facts, array $line, $qty, $why, $today ) {
		return array(
			'order_id'     => (int) $facts['id'],
			'number'       => (string) $facts['number'],
			'ref'          => (string) $line['ref'],
			'name'         => (string) $line['name'],
			'qty'          => (int) $qty,
			'why'          => (string) $why,
			'date'         => (string) $today,
			'held'         => $line['held'],
			'product_id'   => (int) $line['product_id'],
			'variation_id' => (int) $line['variation_id'],
			'size_slug'    => (string) $line['size_slug'],
			'size_label'   => (string) $line['size_label'],
			'names'        => (array) $line['names'],
		);
	}

	/**
	 * The fish that shipped and whose tank could not be found.
	 *
	 * @return array<string, array> "order id|ref" => entry.
	 */
	public static function lost() {
		$lost = get_option( self::LOST_OPTION, array() );
		return is_array( $lost ) ? $lost : array();
	}

	/**
	 * Keep the list, the newest LOST_MAX.
	 *
	 * @param array $lost The list.
	 */
	private static function save_lost( array $lost ) {
		if ( count( $lost ) > self::LOST_MAX ) {
			$lost = array_slice( $lost, -self::LOST_MAX, null, true );
		}
		update_option( self::LOST_OPTION, $lost, false );
	}

	/**
	 * The fish on the not-found list.
	 *
	 * @param array $lost lost().
	 * @return int
	 */
	public static function lost_fish( array $lost ) {
		return (int) array_sum( array_map( 'intval', array_column( $lost, 'qty' ) ) );
	}

	/* ============================================================== */
	/*  The owner's corrections                                        */
	/* ============================================================== */

	/**
	 * A line of an order, read again.
	 *
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @return array{0: array, 1: array}|null facts() and the line.
	 */
	private static function line_of( $order_id, $ref ) {
		$order = wc_get_order( (int) $order_id );
		$facts = is_object( $order ) ? self::facts( $order ) : null;
		foreach ( $facts ? $facts['lines'] : array() as $line ) {
			if ( (string) $line['ref'] === (string) $ref ) {
				return array( $facts, $line );
			}
		}
		return null;
	}

	/**
	 * "It was tank…" / "Pulled from a different tank": the line's fish
	 * came out of another group. Its reservation is rewritten — only the
	 * fish of the group it names move ($from), or all of it — and, when it
	 * already shipped, the old group gets its fish back and the new one
	 * gives them (both by the same difference).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @param int    $stay_id  The group it came out of.
	 * @param int    $from     The group it was held in and did not come out of (0 = the whole line).
	 * @return array{ok: bool, message: string}
	 */
	public static function pick( $store, array $settings, $order_id, $ref, $stay_id, $from = 0 ) {
		if ( ! self::enabled( $settings ) || ! Ship_Lines::load_readers() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Reserving sold fish is switched off.', 'fishotel-misc-plugin' ),
			);
		}
		$found = self::line_of( $order_id, $ref );
		$key   = Reserve::key( Reserve::META, $ref );
		if ( null === $found || null === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'That order line could not be found.', 'fishotel-misc-plugin' ),
			);
		}
		list( $facts, $line ) = $found;
		$stay = $store->stay( (int) $stay_id, Clean_Room::today(), $settings );
		if ( ! is_array( $stay ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That tank group could not be found.', 'fishotel-misc-plugin' ),
			);
		}

		$held = Reserve::held( $line['held'] );
		$was  = array_fill_keys( (array) $line['was'], 1 );
		if ( (int) $from > 0 && isset( $held[ (int) $from ] ) ) {
			$n = $held[ (int) $from ];
			unset( $held[ (int) $from ] );
			$was[ (int) $from ] = 1;
		} else {
			$n = max( 1, (int) $line['qty'] );
			foreach ( array_keys( $held ) as $old ) {
				$was[ (int) $old ] = 1;
			}
			$held = array();
		}
		$held[ (int) $stay_id ] = ( $held[ (int) $stay_id ] ?? 0 ) + $n;
		unset( $was[ (int) $stay_id ] );

		$gone = Reserve::left( $facts['signals'], (int) $line['item_id'] );
		if ( $gone && (int) $stay['qty_live'] < $n ) {
			/* translators: 1: tank number, 2: fish alive */
			return array(
				'ok'      => false,
				'message' => sprintf( __( 'Tank %1$d has only %2$d of these alive — recount it first.', 'fishotel-misc-plugin' ), (int) $stay['tank_number'], (int) $stay['qty_live'] ),
			);
		}

		self::write_meta( (int) $line['item_id'], $key, Reserve::encode( $held ) );
		self::write_meta( (int) $line['item_id'], Reserve::key( Reserve::META_WAS, $ref ), $was ? Reserve::encode( $was ) : '' );
		if ( (int) $line['seen'] <= 0 ) {
			self::write_meta( (int) $line['item_id'], Reserve::key( Reserve::META_SEEN, $ref ), (string) time() );
		}
		Ship_Lines::flush();
		self::flush();

		$line['held'] = $held;
		$line['was']  = array_keys( $was );
		$line['seen'] = max( 1, (int) $line['seen'] );
		foreach ( $facts['lines'] as $i => $row ) {
			if ( $row['ref'] === $line['ref'] ) {
				$facts['lines'][ $i ] = $line;
			}
		}
		self::reconcile( $store, $settings, $facts );

		return array(
			'ok'      => true,
			/* translators: 1: fish, 2: the fish's name, 3: tank number, 4: order number */
			'message' => sprintf( __( '%1$d × %2$s of order #%4$s: tank %3$d.', 'fishotel-misc-plugin' ), $n, (string) $line['name'], (int) $stay['tank_number'], (string) $facts['number'] ),
		);
	}

	/**
	 * "Ignore": the line leaves the not-found list for good.
	 *
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @return bool
	 */
	public static function ignore( $order_id, $ref ) {
		$item = Reserve::item_of( $ref );
		$key  = Reserve::key( Reserve::META_IGNORE, $ref );
		if ( $item <= 0 || null === $key ) {
			return false;
		}
		self::write_meta( $item, $key, (string) time() );
		$lost = self::lost();
		unset( $lost[ (int) $order_id . '|' . $ref ] );
		self::save_lost( $lost );
		return true;
	}

	/**
	 * "Ignore all".
	 *
	 * @return int How many lines.
	 */
	public static function ignore_all() {
		$n = 0;
		foreach ( self::lost() as $entry ) {
			if ( self::ignore( (int) $entry['order_id'], (string) $entry['ref'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * One correction from a posted form (the handler's body, tested).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $raw      op, order_id, ref, stay_id, from.
	 * @return string What to tell the owner.
	 */
	public static function act( $store, array $settings, array $raw ) {
		$op    = isset( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
		$order = isset( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$ref   = isset( $raw['ref'] ) && is_scalar( $raw['ref'] ) ? preg_replace( '/[^a-z0-9:]/', '', strtolower( (string) $raw['ref'] ) ) : '';
		switch ( $op ) {
			case 'pick':
				$result = self::pick( $store, $settings, $order, $ref, isset( $raw['stay_id'] ) ? absint( $raw['stay_id'] ) : 0, isset( $raw['from'] ) ? absint( $raw['from'] ) : 0 );
				return $result['message'];
			case 'ignore':
				return self::ignore( $order, $ref ) ? __( 'Ignored: that line is no longer listed.', 'fishotel-misc-plugin' ) : __( 'That order line could not be found.', 'fishotel-misc-plugin' );
			case 'ignore_all':
				$n = self::ignore_all();
				/* translators: %d: lines */
				return sprintf( _n( 'Ignored %d line.', 'Ignored %d lines.', $n, 'fishotel-misc-plugin' ), $n );
			default:
				return __( 'Unknown action.', 'fishotel-misc-plugin' );
		}
	}

	/* ============================================================== */
	/*  What the screens read                                          */
	/* ============================================================== */

	/**
	 * What the room shows of it: every group's reserved fish and — where
	 * the day's list is built — the pull list and the not-found list.
	 * Empty when the feature is off.
	 *
	 * @param object $store    Store or stand-in.
	 * @param string $today    Site date.
	 * @param array  $settings Settings.
	 * @param array[] $stays   Every open group, hydrated.
	 * @param array[] $tanks   Every tank.
	 * @param bool   $lists    Also the pull list and the not-found list.
	 * @return array{counts?: array, pull?: array, lost?: array}
	 */
	public static function room_data( $store, $today, array $settings, array $stays, array $tanks, $lists = false ) {
		if ( ! self::enabled( $settings ) ) {
			return array();
		}
		try {
			if ( ! isset( self::$memo['stays'] ) ) {
				self::$memo['stays'] = array(
					'all'   => $stays,
					'clean' => Feeding::clean_stays( $stays, $tanks ),
					'tanks' => $tanks,
				);
			}
			$out = array( 'counts' => self::ensure( $store, $settings )['counts'] );
			if ( $lists ) {
				$out['pull'] = self::pull( $store, $today, $settings, $tanks );
				$out['lost'] = array_values( self::lost() );
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Today's pull list (Reserve::pull()), with the other clean groups
	 * each row's fish may have come out of.
	 *
	 * @param object  $store    Store or stand-in.
	 * @param string  $today    Site date.
	 * @param array   $settings Settings.
	 * @param array[] $tanks    Every tank.
	 * @return array
	 */
	public static function pull( $store, $today, array $settings, array $tanks ) {
		$ship  = Ship_Lines::today( (string) $today );
		$lines = isset( $ship['lines'] ) && is_array( $ship['lines'] ) ? $ship['lines'] : array();
		$ids   = array();
		foreach ( $lines as $line ) {
			foreach ( array_keys( Reserve::held( $line['held'] ?? array() ) ) as $stay ) {
				$ids[ (int) $stay ] = true;
			}
		}
		$labels = array();
		foreach ( $tanks as $tank ) {
			if ( isset( $tank['building'] ) && is_array( $tank['building'] ) ) {
				$labels[ (int) $tank['building_id'] ] = Layout::building_label( $tank['building'] );
			}
		}
		$stays = array();
		foreach ( array_keys( $ids ) as $id ) {
			$stay = $store->stay( $id, (string) $today, $settings );
			if ( is_array( $stay ) ) {
				$stay['building_label'] = $labels[ (int) $stay['building_id'] ] ?? '';
				$stays[ $id ]           = $stay;
			}
		}
		$events  = $ids ? (array) $store->order_events( array_keys( $ids ) ) : array();
		$shipped = array();
		foreach ( $lines as $line ) {
			$note             = Reserve::note( (int) ( $line['order_id'] ?? 0 ), (string) ( $line['ref'] ?? '' ) );
			$shipped[ $note ] = Reserve::written( $events, $note )['net'];
		}
		$pull         = Reserve::pull( $lines, $stays, $shipped );
		$pull['date'] = (string) $today;

		// The other clean groups each row's fish may have come out of.
		$clean = self::stays( $store, $settings )['clean'];
		$by    = array();
		foreach ( $lines as $line ) {
			$by[ (int) ( $line['order_id'] ?? 0 ) . '|' . (string) ( $line['ref'] ?? '' ) ] = $line;
		}
		foreach ( $pull['buildings'] as $b => $building ) {
			foreach ( $building['tanks'] as $t => $tank ) {
				foreach ( $tank['rows'] as $r => $row ) {
					$line = $by[ $row['order_id'] . '|' . $row['ref'] ] ?? array();
					$pull['buildings'][ $b ]['tanks'][ $t ]['rows'][ $r ]['others'] = self::choices( Reserve::alternatives( $line, $clean, array_keys( Reserve::held( $line['held'] ?? array() ) ) ) );
				}
			}
		}
		foreach ( $pull['unknown'] as $u => $row ) {
			$pull['unknown'][ $u ]['others'] = self::choices( Reserve::alternatives( $by[ $row['order_id'] . '|' . $row['ref'] ] ?? array(), $clean ) );
		}
		return $pull;
	}

	/**
	 * A picker's choices: "Tank 221 · Yellow Tang M · 3 alive".
	 *
	 * @param array[] $stays Groups.
	 * @return array[] stay_id, text.
	 */
	public static function choices( array $stays ) {
		$out = array();
		foreach ( array_slice( $stays, 0, 12 ) as $stay ) {
			/* translators: 1: tank number, 2: fish, 3: fish alive */
			$out[] = array(
				'stay_id' => (int) $stay['id'],
				'text'    => sprintf( __( 'Tank %1$d · %2$s · %3$d alive', 'fishotel-misc-plugin' ), (int) ( $stay['tank_number'] ?? 0 ), trim( (string) ( $stay['species'] ?? '' ) . ' ' . (string) ( $stay['size_label'] ?? '' ) ), (int) ( $stay['qty_live'] ?? 0 ) ),
			);
		}
		return $out;
	}

	/**
	 * The choices of a not-found line ("It was tank…").
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $entry    A lost() entry.
	 * @return array[] choices().
	 */
	public static function lost_choices( $store, array $settings, array $entry ) {
		$clean = self::stays( $store, $settings )['clean'];
		return self::choices( Reserve::alternatives( $entry, $clean, array_keys( Reserve::held( $entry['held'] ?? array() ) ) ) );
	}

	/**
	 * Every group's reserved fish for other sections (the Invoices picker,
	 * the Requests job): stay id => { qty, orders }. Empty while the
	 * feature (or the Clean Room) is off.
	 *
	 * @param int[] $stay_ids Only these (empty = all).
	 * @return array<int, array{qty: int, orders: string[]}>
	 */
	public static function counts_for( array $stay_ids = array() ) {
		try {
			$settings = Settings::get();
			if ( ! Clean_Room::is_booted() || ! self::enabled( $settings ) || ! Store::tables_exist() ) {
				return array();
			}
			$counts = self::ensure( new Store(), $settings )['counts'];
		} catch ( \Throwable $e ) {
			return array();
		}
		if ( ! $stay_ids ) {
			return $counts;
		}
		return array_intersect_key( $counts, array_flip( array_map( 'intval', $stay_ids ) ) );
	}
}
