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

use FisHotel\Misc\Dates;

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
	 * How long it is kept when no order changes (seconds) — every change
	 * that moves a reservation drops it, and the hourly sweep reads it
	 * again anyway.
	 *
	 * @var int
	 */
	const CACHE_SECONDS = 3600;

	/**
	 * The transient holding today's pull list (dropped with the reserved
	 * counts, and whenever today's shipping fish are read again).
	 *
	 * @var string
	 */
	const PULL_TRANSIENT = 'fishotel_cleanroom_pull';

	/**
	 * The option that keeps two requests from writing at once (an "add
	 * tracking" alongside the hourly sweep): add_option() is atomic — it
	 * fails when the row is there. A lock older than LOCK_SECONDS is taken
	 * over (a request that died holding it).
	 */
	const LOCK_OPTION  = 'fishotel_cleanroom_reserve_lock';
	const LOCK_SECONDS = 120;

	/**
	 * The order hooks that drop the reserved counts: a status change, a
	 * note (ShipTracker's tracking), a new order, a refund, items saved in
	 * wp-admin, and an order trashed, restored or deleted — not every
	 * order save (woocommerce_update_order fires on any save, ShipTracker's
	 * own included, and says nothing about a reservation).
	 *
	 * @var string[]
	 */
	const FLUSH_HOOKS = array(
		'woocommerce_order_status_changed',
		'woocommerce_order_note_added',
		'woocommerce_new_order',
		'woocommerce_order_refunded',
		'woocommerce_saved_order_items',
		'trashed_post',
		'untrashed_post',
		'deleted_post',
		'woocommerce_trash_order',
		'woocommerce_untrash_order',
		'woocommerce_delete_order',
	);

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

	/**
	 * The orders whose shipments this class primed (Lost Fish's reader),
	 * forgotten when the read is done.
	 *
	 * @var int[]
	 */
	private static $primed = array();

	/**
	 * How deep this request holds the lock (0 = not held).
	 *
	 * @var int
	 */
	private static $locked = 0;

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
		self::$memo   = array();
		self::$queue  = array();
		self::$locked = 0;
		self::unprime();
	}

	/**
	 * Take the write lock (held again by the same request: nested).
	 *
	 * @return bool Whether this request holds it now.
	 */
	public static function lock() {
		if ( self::$locked > 0 ) {
			self::$locked++;
			return true;
		}
		if ( ! function_exists( 'add_option' ) ) {
			self::$locked = 1;
			return true;
		}
		$now = time();
		if ( ! add_option( self::LOCK_OPTION, (string) $now, '', 'no' ) ) {
			$held = (int) get_option( self::LOCK_OPTION, 0 );
			if ( $held > 0 && $now - $held < self::LOCK_SECONDS ) {
				return false; // Another request is writing: the hourly sweep catches up.
			}
			delete_option( self::LOCK_OPTION );
			if ( ! add_option( self::LOCK_OPTION, (string) $now, '', 'no' ) ) {
				return false;
			}
		}
		self::$locked = 1;
		return true;
	}

	/**
	 * Give the write lock back.
	 */
	public static function unlock() {
		if ( self::$locked <= 0 ) {
			return;
		}
		self::$locked--;
		if ( 0 === self::$locked && function_exists( 'add_option' ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Hooks — called from the section's boot only: while the Clean Room is
	 * switched off nothing is hooked (and boot_disabled() unschedules).
	 *
	 * @param array $settings Settings.
	 */
	public static function register( array $settings ) {
		add_action( self::CRON_HOOK, array( self::class, 'cron' ), 10, 0 );
		// The tick switched off or on again: what was worked out before is not kept (one hook, nothing read).
		add_action( 'update_option_' . Clean_Room::OPTION_KEY, array( self::class, 'flush' ), 10, 0 );
		if ( ! self::enabled( $settings ) ) {
			self::unschedule();
			return;
		}
		self::since( true );

		foreach ( self::FLUSH_HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush' ), 10, 0 );
		}
		foreach ( Ship_Lines::HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush_pull' ), 10, 0 ); // Today's list is read again with them.
		}
		foreach ( Ship_Lines::ITEM_META_HOOKS as $hook ) {
			add_action( $hook, array( self::class, 'flush_for_item_meta' ), 10, 3 );
		}
		// A group logged or moved into clean water: a fish whose tank was not known may be reserved now.
		add_action( 'fishotel_cleanroom_event_recorded', array( self::class, 'flush_for_event' ), 10, 3 );
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
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::CRON_HOOK ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		// wp_schedule_event() reads the cron schedules, which WooCommerce names in its own language: asked
		// before `init` (the section boots earlier) that loads its translations too early — a notice in
		// debug.log the first time it is switched on. So it is armed at `init`.
		if ( function_exists( 'did_action' ) && ! did_action( 'init' ) ) {
			add_action( 'init', array( self::class, 'schedule' ), 20, 0 );
			return;
		}
		wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
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
			delete_transient( self::PULL_TRANSIENT );
		}
	}

	/**
	 * Forget today's pull list only (an order saved: its delivery date or
	 * its lines may have changed — the reservations did not).
	 */
	public static function flush_pull() {
		unset( self::$memo['pull'] );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::PULL_TRANSIENT );
		}
	}

	/**
	 * Forget them when a tank group changes in a way that can answer for a
	 * fish whose tank was not known (an arrival, a move, a clear) — and the
	 * pull list when a fish is sold or put back by hand. A loss, a count or
	 * a note changes neither (and the phone logs many of those).
	 *
	 * @param mixed $stay_id  Stay id (unused).
	 * @param mixed $event_id Event id (unused).
	 * @param mixed $data     The event as written (type).
	 */
	public static function flush_for_event( $stay_id = 0, $event_id = 0, $data = array() ) {
		$type = is_array( $data ) ? (string) ( $data['type'] ?? '' ) : '';
		if ( in_array( $type, array( 'arrival', 'move', 'clear' ), true ) ) {
			self::flush();
			return;
		}
		if ( in_array( $type, array( 'loss', 'count', 'sold' ), true ) && self::holds_stay( (int) $stay_id ) ) {
			// Fewer fish alive in a group with a hold by hand: the hold shrinks now (orders first), never an hour
			// later — until then another order could be given its fish.
			self::flush();
			return;
		}
		if ( 'sold' === $type ) {
			self::flush_pull();
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
	 * Whether a target is still open (process() and the sweep work the
	 * ones that left first: their fish come off before anything is given
	 * to another order).
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function is_open( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		return is_object( $order ) && in_array( Feeding::status( $order->get_status() ), Reserve::OPEN_STATUSES, true );
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
		if ( ! self::lock() ) {
			return 0; // Another request is writing: next hour.
		}
		try {
			return self::sweep_locked( $store, $settings, $now, $after );
		} finally {
			self::unlock();
		}
	}

	/**
	 * The sweep's body, under the lock.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $now      UNIX time.
	 * @param int    $after    Orders changed after this (UNIX time).
	 * @return int
	 */
	private static function sweep_locked( $store, array $settings, $now, $after ) {
		self::flush();

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
		// The orders that left first — their fish come off before the open ones are reserved out of
		// what is free — then every open order is reserved, then the open ones are worked.
		$open = array();
		foreach ( array_keys( $ids ) as $id ) {
			if ( self::is_open( (int) $id ) ) {
				$open[] = (int) $id;
				continue;
			}
			self::process( $store, $settings, (int) $id, false );
		}
		self::flush();
		self::ensure( $store, $settings );
		foreach ( $open as $id ) {
			self::process( $store, $settings, $id, false );
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
			$box     = is_object( $wrapper ) ? self::box_signals( $wrapper ) : null;
		}
		if ( '' === $head['delivery'] && is_array( $box ) && '' !== (string) ( $box['delivery'] ?? '' ) ) {
			// An order inside a combined box has no date of its own: the box's is the day its fish go out.
			$head['delivery'] = (string) $box['delivery'];
		}
		$head['lines']    = $lines;
		$head['signals']  = array_merge( self::signals( $order ), array( 'box' => $box ) );
		$head['paid']     = self::paid( $order );
		$head['customer'] = self::customer( $order );
		return $head;
	}

	/**
	 * Who placed an order — what a hold by hand for an account is matched
	 * on: the account, and the billing e-mail.
	 *
	 * @param object $order Order.
	 * @return array{user_id: int, email: string}
	 */
	private static function customer( $order ) {
		try {
			return array(
				'user_id' => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0,
				'email'   => method_exists( $order, 'get_billing_email' ) ? strtolower( trim( (string) $order->get_billing_email() ) ) : '',
			);
		} catch ( \Throwable $e ) {
			return array(
				'user_id' => 0,
				'email'   => '',
			);
		}
	}

	/**
	 * A combined box's signals (signals()) and its delivery date — the
	 * date of the orders inside it, which have none of their own.
	 *
	 * @param object $wrapper The combined box.
	 * @return array
	 */
	private static function box_signals( $wrapper ) {
		$delivery = $wrapper->get_meta( Ship_Lines::META_DELIVERY );
		$delivery = is_string( $delivery ) && Replay::is_date( trim( $delivery ) ) ? trim( $delivery ) : '';
		return array_merge( self::signals( $wrapper ), array( 'delivery' => $delivery ) );
	}

	/**
	 * Whether an order is paid (WooCommerce's own word, never a status
	 * name — as the Invoices section reads it).
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	private static function paid( $order ) {
		try {
			return ( method_exists( $order, 'get_date_paid' ) && (bool) $order->get_date_paid() ) || ( method_exists( $order, 'is_paid' ) && (bool) $order->is_paid() );
		} catch ( \Throwable $e ) {
			return false;
		}
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
		$box   = self::box_signals( $order );
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
		$user  = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		$email = method_exists( $order, 'get_billing_email' ) ? strtolower( trim( (string) $order->get_billing_email() ) ) : '';
		$rows  = self::offered_rows( $class );
		if ( null !== $rows ) {
			// Every offer of the open groups was read once for this pass: this customer's are picked out here.
			$out = array();
			foreach ( $rows as $row ) {
				if ( $row['product_id'] > 0 && $row['stay_id'] > 0 && ( ( $user > 0 && $row['user_id'] === $user ) || ( '' !== $email && $row['email'] === $email ) ) ) {
					$out[ $row['product_id'] ][] = $row['stay_id'];
				}
			}
			return $out;
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
	 * Every offer (or fill) of a request naming one of the open groups in
	 * clean water — read once per request (Requests' offered_to_stays()),
	 * null when that Requests section is older (each order is then asked on
	 * its own, as before).
	 *
	 * @param string $class The Requests store's class.
	 * @return array[]|null
	 */
	private static function offered_rows( $class ) {
		if ( array_key_exists( 'offered', self::$memo ) ) {
			return self::$memo['offered'];
		}
		if ( ! method_exists( $class, 'offered_to_stays' ) || ! isset( self::$memo['stays'] ) ) {
			return null;
		}
		try {
			$rows = ( new $class() )->offered_to_stays( array_column( self::$memo['stays']['clean'], 'id' ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		self::$memo['offered'] = is_array( $rows ) ? $rows : null; // Not read whole: each order is asked on its own.
		return self::$memo['offered'];
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
	 * Read the shipments of many orders in one query for the reads that
	 * follow (the Lost Fish section's reader, when it can).
	 *
	 * @param int[] $order_ids Order ids.
	 */
	private static function prime( array $order_ids ) {
		$class = '\\FisHotel\\Misc\\Sections\\Lost_Fish\\Orders';
		if ( ! class_exists( $class, false ) || ! method_exists( $class, 'prime_shipments' ) ) {
			return;
		}
		$rows = Store::shipment_rows( $order_ids );
		if ( null !== $rows ) {
			self::$primed = array_merge( self::$primed, (array) $class::prime_shipments( $rows ) );
		}
	}

	/**
	 * Forget the shipments this class primed.
	 */
	private static function unprime() {
		$class = '\\FisHotel\\Misc\\Sections\\Lost_Fish\\Orders';
		if ( self::$primed && class_exists( $class, false ) && method_exists( $class, 'unprime' ) ) {
			$class::unprime( self::$primed );
		}
		self::$primed = array();
	}

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
	 * @return array{counts: array<int, array{qty: int, orders: string[], invoice: int}>}
	 */
	public static function ensure( $store, array $settings, $force = false ) {
		if ( ! self::enabled( $settings ) ) {
			return array(
				'counts'  => array(),
				'holds'   => array(),
				'suggest' => array(),
			);
		}
		if ( ! $force && isset( self::$memo['ensure'] ) ) {
			return self::$memo['ensure'];
		}
		$cached = $force ? false : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['counts'] ) && is_array( $cached['counts'] ) ) {
			self::$memo['ensure'] = $cached;
			return $cached;
		}

		$out   = array(
			'counts'  => array(),
			'holds'   => array(),
			'suggest' => array(),
		);
		$wrote = true;
		if ( Ship_Lines::load_readers() ) {
			self::since( true );
			$wrote = self::lock();
			try {
				$out = self::pass( $store, $settings, $wrote );
			} finally {
				self::unprime();
				if ( $wrote ) {
					self::unlock();
				}
			}
		}
		if ( $wrote ) {
			set_transient( self::TRANSIENT, $out, self::CACHE_SECONDS ); // Only what was also stored is kept.
		}
		self::$memo['ensure'] = $out;
		return $out;
	}

	/**
	 * How many open orders one read looks at (filterable; the newest are
	 * read first — they ship soonest).
	 *
	 * @return int
	 */
	public static function open_limit() {
		$limit = function_exists( 'apply_filters' ) ? apply_filters( 'fishotel_cleanroom_reserve_open_limit', self::OPEN_LIMIT ) : self::OPEN_LIMIT;
		return max( 1, (int) $limit );
	}

	/**
	 * One pass over the open orders (ensure()).
	 *
	 * Lines that have already gone (a partial shipment, a box sent while
	 * the order still reads open) are not reserved again: what they hold
	 * and was not taken out yet still counts — those fish are spoken for
	 * until their events are written. An unseen line of an order already
	 * past its delivery date when this was switched on is left alone
	 * (Reserve::stale()).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param bool   $write    Store the reservations (false: another request holds the lock — count only).
	 * @return array{counts: array}
	 */
	private static function pass( $store, array $settings, $write = true ) {
		try {
			$found = wc_get_orders(
				array(
					'type'    => 'shop_order',
					'status'  => Reserve::OPEN_STATUSES,
					'limit'   => self::open_limit(),
					'orderby' => 'ID',
					'order'   => 'DESC',
					'return'  => 'objects',
				)
			);
		} catch ( \Throwable $e ) {
			$found = array();
		}

		// Every open order's shipments in one query (ShipTracker's table), not one or two per order.
		self::prime( array_map( static function ( $o ) { return is_object( $o ) && method_exists( $o, 'get_id' ) ? (int) $o->get_id() : 0; }, (array) $found ) );

		$orders = array();
		foreach ( (array) $found as $order ) {
			if ( ! is_object( $order ) ) {
				continue;
			}
			foreach ( self::targets( $order ) as $facts ) {
				if ( isset( $orders[ $facts['id'] ] ) || in_array( $facts['status'], Feeding::DEAD_STATUSES, true ) ) {
					continue;
				}
				$orders[ $facts['id'] ] = $facts;
			}
		}
		ksort( $orders ); // The oldest order first: it was bought first.

		$since = self::since();
		$today = Clean_Room::today();
		$stays = self::stays( $store, $settings );
		$list  = array();
		$gone  = array(); // Lines gone but maybe not taken out yet: [ order, line ].
		foreach ( $orders as $id => $facts ) {
			$lines   = array();
			$offered = null;
			foreach ( $facts['lines'] as $line ) {
				if ( (int) $line['qty'] <= 0 ) {
					continue; // Refunded to nothing: it holds no fish.
				}
				if ( Reserve::left( $facts['signals'], (int) $line['item_id'] ) ) {
					if ( $line['held'] ) {
						$gone[] = array( $facts, $line );
					}
					continue; // Gone already: it is never given a tank here.
				}
				if ( Reserve::stale( $line, $facts, $since, $today ) ) {
					continue; // Open at the switch-on but past its delivery date: it had left, unmarked.
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
				'id'       => $id,
				'number'   => $facts['number'],
				'paid'     => ! empty( $facts['paid'] ),
				'customer' => (array) ( $facts['customer'] ?? array() ),
				'created'  => (int) ( $facts['created'] ?? 0 ),
				'lines'    => $lines,
			);
		}

		// The holds by hand: what each holds now (the orders first, never more than alive), counted with them.
		$base  = self::gone_counts( $store, $gone );
		$holds = Holds::effective( Holds::open( self::book() ), self::alive( $stays['all'] ), Reserve::counted( $list, $base ) );
		$plan  = Reserve::plan( $list, $stays['clean'], $stays['all'], Holds::add_to( $base, $holds ), $holds );
		if ( $write ) {
			// The holds first: a request that dies between the two leaves a hold closed and its order's line
			// short — reserved anew on the next read — never the same fish counted for the hold and the order.
			self::apply_takes( $plan['takes'] );
			self::write_plan( $list, $plan['adds'] );
		}

		return array(
			'counts'  => self::with_invoices( $plan['counts'], $list, $plan['adds'] ),
			'holds'   => $plan['holds'],
			'suggest' => Holds::suggestions( $list, $plan['holds'], self::by_id( $stays['all'] ) ),
		);
	}

	/**
	 * Stay id => fish alive, of some groups.
	 *
	 * @param array[] $stays Groups.
	 * @return array<int, int>
	 */
	private static function alive( array $stays ) {
		$out = array();
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) && 'closed' !== (string) ( $stay['status'] ?? '' ) ) {
				$out[ (int) $stay['id'] ] = (int) ( $stay['qty_live'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * Groups by id.
	 *
	 * @param array[] $stays Groups.
	 * @return array<int, array>
	 */
	private static function by_id( array $stays ) {
		$out = array();
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) ) {
				$out[ (int) $stay['id'] ] = $stay;
			}
		}
		return $out;
	}

	/**
	 * What lines already gone still hold in their tanks (Reserve::holding()):
	 * one read of their groups' order events.
	 *
	 * @param object  $store Store or stand-in.
	 * @param array[] $gone  [ facts, line ] pairs.
	 * @return array Counts: stay id => { qty, orders }.
	 */
	private static function gone_counts( $store, array $gone ) {
		if ( ! $gone ) {
			return array();
		}
		$ids = array();
		foreach ( $gone as $pair ) {
			foreach ( array_keys( $pair[1]['held'] ) as $stay ) {
				$ids[ (int) $stay ] = true;
			}
		}
		$events = (array) $store->order_events( array_keys( $ids ) );
		$counts = array();
		foreach ( $gone as $pair ) {
			list( $facts, $line ) = $pair;
			$net = Reserve::written( $events, Reserve::note( $facts['id'], $line['ref'] ) )['net'];
			foreach ( Reserve::holding( $line['held'], (int) $line['qty'], $net ) as $stay => $n ) {
				if ( ! isset( $counts[ $stay ] ) ) {
					$counts[ $stay ] = array(
						'qty'    => 0,
						'orders' => array(),
					);
				}
				$counts[ $stay ]['qty'] += $n;
				if ( ! in_array( (string) $facts['number'], $counts[ $stay ]['orders'], true ) ) {
					$counts[ $stay ]['orders'][] = (string) $facts['number'];
				}
			}
		}
		return $counts;
	}

	/**
	 * Each count with the fish of it held by lines of an Invoices-section
	 * invoice whose order is not paid yet ("invoice") — the Invoices
	 * section counts that invoice as open still, so it takes them off the
	 * reserved count there and never counts them twice.
	 *
	 * @param array   $counts Counts.
	 * @param array[] $list   The orders planned (id, paid, lines).
	 * @param array   $adds   Reserve::plan()['adds'].
	 * @return array
	 */
	public static function with_invoices( array $counts, array $list, array $adds ) {
		foreach ( $counts as $stay => $count ) {
			$counts[ $stay ]['invoice'] = 0;
		}
		foreach ( $list as $order ) {
			if ( ! empty( $order['paid'] ) ) {
				continue;
			}
			foreach ( $order['lines'] as $line ) {
				$link = isset( $line['link'] ) && is_array( $line['link'] ) ? (int) ( $line['link']['stay_id'] ?? 0 ) : 0;
				if ( $link <= 0 ) {
					continue;
				}
				$held = Reserve::trim( Reserve::merge( $line['held'], $adds[ (int) $order['id'] ][ $line['ref'] ] ?? array() ), (int) $line['qty'] );
				if ( isset( $held[ $link ], $counts[ $link ] ) ) {
					$counts[ $link ]['invoice'] += $held[ $link ];
				}
			}
		}
		return $counts;
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
			self::flush_pull();
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
	 * Work one order (and the orders in it, for a combined box), under the
	 * write lock — nothing when another request holds it (the hourly
	 * sweep catches up).
	 *
	 *   open      its lines are reserved, then what already went (a partial
	 *             shipment) is taken out;
	 *   gone      what it holds is taken out FIRST — before anything could
	 *             count its fish as free — then a line that was never looked
	 *             at may still be given a tank, when it is sure it left
	 *             after the switch-on (Reserve::late_ok()), and is taken out;
	 *   dead      (cancelled, refunded, failed) nothing new is ever taken
	 *             out — what was is kept (a refund after it left).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $order_id Order id.
	 * @param bool   $was_open It was open until the change that asked.
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
		if ( ! self::lock() ) {
			return array();
		}
		try {
			$out = array();
			foreach ( self::targets( $order ) as $facts ) {
				$out[ $facts['id'] ] = self::process_one( $store, $settings, $facts, (bool) $was_open );
			}
			return $out;
		} finally {
			self::unlock();
		}
	}

	/**
	 * process() for one order.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $facts    facts().
	 * @param bool   $was_open It was open until the change that asked.
	 * @return array{reserved: int, written: int, lost: int}
	 */
	private static function process_one( $store, array $settings, array $facts, $was_open ) {
		$dead = in_array( $facts['status'], Feeding::DEAD_STATUSES, true );
		$open = in_array( $facts['status'], Reserve::OPEN_STATUSES, true );
		$did  = array(
			'reserved' => 0,
			'written'  => 0,
			'lost'     => 0,
		);
		if ( $open ) {
			if ( self::needs_plan( $facts, $was_open ) ) {
				// Only then are the other open orders read (a box of fish all reserved already costs nothing more).
				$facts           = self::reserve_one( $store, $settings, $facts, true, $was_open );
				$did['reserved'] = 1;
			}
			return array_merge( $did, self::reconcile( $store, $settings, $facts ) );
		}
		$done = self::reconcile( $store, $settings, $facts );
		if ( ! $dead && self::has_late( $facts, $was_open ) ) {
			$facts           = self::reserve_one( $store, $settings, $facts, false, $was_open );
			$did['reserved'] = 1;
			$done            = self::reconcile( $store, $settings, $facts );
		}
		return array_merge( $did, $done );
	}

	/**
	 * Whether an open order has a line to reserve or to mark seen — a line
	 * not seen yet, or one short of fish that has not gone.
	 *
	 * @param array $facts    facts().
	 * @param bool  $was_open It was open until the change being worked.
	 * @return bool
	 */
	private static function needs_plan( array $facts, $was_open ) {
		$since = self::since();
		$today = Clean_Room::today();
		foreach ( $facts['lines'] as $line ) {
			if ( (int) $line['qty'] <= 0 || null === Reserve::key( Reserve::META, $line['ref'] ) || Reserve::stale( $line, $facts, $since, $today ) ) {
				continue;
			}
			if ( Reserve::left( $facts['signals'], (int) $line['item_id'] ) ) {
				if ( Reserve::late_ok( $line, $facts, $since, $was_open ) ) {
					return true;
				}
				continue;
			}
			if ( (int) $line['seen'] <= 0 || (int) $line['qty'] > Reserve::total( Reserve::trim( $line['held'], (int) $line['qty'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a gone order has a line that may still be given a tank.
	 *
	 * @param array $facts    facts().
	 * @param bool  $was_open It was open until the change being worked.
	 * @return bool
	 */
	private static function has_late( array $facts, $was_open ) {
		$since = self::since();
		$today = Clean_Room::today();
		foreach ( $facts['lines'] as $line ) {
			if ( (int) $line['qty'] > 0 && ! Reserve::stale( $line, $facts, $since, $today ) && Reserve::late_ok( $line, $facts, $since, $was_open ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reserve one order's lines that hold too few fish — out of what the
	 * other open orders leave free. A line already gone is given a tank
	 * only when Reserve::late_ok() says so; a stale one never.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $facts    facts().
	 * @param bool   $open     Still open (ensure() counted it already).
	 * @param bool   $was_open It was open until the change being worked.
	 * @return array facts() with the reservations added.
	 */
	private static function reserve_one( $store, array $settings, array $facts, $open, $was_open = false ) {
		$counts  = self::ensure( $store, $settings )['counts'];
		$since   = self::since();
		$today   = Clean_Room::today();
		$offered = null;
		$lines   = array();
		foreach ( $facts['lines'] as $line ) {
			if ( (int) $line['qty'] <= 0 || Reserve::stale( $line, $facts, $since, $today ) ) {
				continue;
			}
			if ( Reserve::left( $facts['signals'], (int) $line['item_id'] ) && ! Reserve::late_ok( $line, $facts, $since, $was_open ) ) {
				continue; // Gone already: what it holds is what it holds.
			}
			if ( $open ) {
				// The counts hold this line already: take its own fish out of them first.
				foreach ( Reserve::trim( $line['held'], (int) $line['qty'] ) as $stay => $qty ) {
					if ( isset( $counts[ $stay ] ) ) {
						$counts[ $stay ]['qty'] = max( 0, $counts[ $stay ]['qty'] - $qty );
					}
				}
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
			'id'       => $facts['id'],
			'number'   => $facts['number'],
			'paid'     => ! empty( $facts['paid'] ),
			'customer' => (array) ( $facts['customer'] ?? array() ),
			'lines'    => $lines,
		);
		$stays = self::stays( $store, $settings );
		$plan  = Reserve::plan( array( $order ), $stays['clean'], $stays['all'], $counts, (array) ( self::ensure( $store, $settings )['holds'] ?? array() ) );
		self::apply_takes( $plan['takes'] ); // The holds first (as in pass()).
		self::write_plan( array( $order ), $plan['adds'] );
		if ( isset( self::$memo['ensure'] ) && $open ) {
			self::$memo['ensure']['counts'] = $plan['counts'];
			self::$memo['ensure']['holds']  = $plan['holds'];
		}
		if ( ( ! empty( $plan['adds'] ) || ! empty( $plan['takes'] ) ) && function_exists( 'delete_transient' ) ) {
			delete_transient( self::TRANSIENT );
		}

		$planned = array_flip( array_column( $lines, 'ref' ) );
		foreach ( $facts['lines'] as $i => $line ) {
			if ( ! empty( $plan['adds'][ $facts['id'] ][ $line['ref'] ] ) ) {
				$facts['lines'][ $i ]['held'] = Reserve::merge( $line['held'], $plan['adds'][ $facts['id'] ][ $line['ref'] ] );
			}
			if ( isset( $planned[ $line['ref'] ] ) && (int) $line['seen'] <= 0 && null !== Reserve::key( Reserve::META, $line['ref'] ) ) {
				$facts['lines'][ $i ]['seen'] = time();
			}
		}
		return $facts;
	}

	/**
	 * The start of a site date, in UTC.
	 *
	 * @param string $ymd Site date.
	 * @return string "Y-m-d H:i:s" UTC ('' when it cannot be told).
	 */
	private static function site_start_utc( $ymd ) {
		if ( ! Replay::is_date( (string) $ymd ) ) {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable( $ymd . ' 00:00:01', Clean_Room::timezone() );
			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Take an order's shipped fish out of their tanks — or put them back
	 * — writing only the difference, and keep the not-found list.
	 *
	 * A dead order (cancelled, refunded, failed) never gets a new fish
	 * taken out, and never has one put back: what was written is kept.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $facts    facts().
	 * @return array{written: int, lost: int} Events written; fish on the not-found list for this order.
	 */
	public static function reconcile( $store, array $settings, array $facts ) {
		$today = Clean_Room::today();
		$dead  = in_array( $facts['status'], Feeding::DEAD_STATUSES, true );
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

		$delivery = (string) ( $facts['delivery'] ?? '' );
		$ship_at  = Replay::is_date( $delivery ) ? self::site_start_utc( Replay::add_days( $delivery, -1 ) ) : '';
		$rows    = array();
		$lost    = self::lost();
		$changed = false;
		$written = 0;
		$missing = 0;
		foreach ( $lines as $line ) {
			$note = Reserve::note( $facts['id'], $line['ref'] );
			$done = Reserve::written( $events, $note );
			$gone = ! $dead && Reserve::left( $facts['signals'], (int) $line['item_id'] );
			$want = Reserve::wanted( $gone, $dead ? 'cancelled' : $facts['status'], $line['held'], (int) $line['qty'], $done['net'] );
			$alive = array();
			foreach ( array_keys( $want ) as $stay ) {
				if ( ! isset( $rows[ $stay ] ) ) {
					$row            = $store->stay( (int) $stay, $today, $settings );
					$rows[ $stay ] = is_array( $row ) ? $row : array( 'qty_live' => 0 );
				}
				$alive[ $stay ] = (int) ( $rows[ $stay ]['qty_live'] ?? 0 );
			}
			$diff = Reserve::diff( $want, $done['net'], $alive, $done['undone'] );
			foreach ( $diff['writes'] as $stay => $delta ) {
				$at     = $delta > 0 ? Reserve::shipped_at( $delivery, $today, $ship_at, (string) ( $rows[ $stay ]['checkin_date'] ?? '' ) ) : '';
				$result = $store->record_event(
					(int) $stay,
					array(
						'type'     => 'sold',
						'qty'      => (int) $delta,
						'note'     => $note,
						'event_at' => '' !== $at ? $at : gmdate( 'Y-m-d H:i:s' ),
						'actor_id' => 0,
						'source'   => Reserve::SOURCE,
					),
					$today,
					$settings
				);
				if ( $result ) {
					$written++;
					$rows[ $stay ]['qty_live'] = (int) ( $result['state']['qty_live'] ?? ( $alive[ $stay ] - $delta ) );
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
			} elseif ( isset( $lost[ $key ] ) && ! ( $dead && Reserve::left( $facts['signals'], (int) $line['item_id'] ) ) ) {
				unset( $lost[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save_lost( $lost );
		}
		if ( $written > 0 ) {
			self::flush(); // The groups' fish alive changed: what was read of them is not used again.
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
	public static function pick( $store, array $settings, $order_id, $ref, $stay_id, $from = 0, $qty = 0 ) {
		if ( ! self::enabled( $settings ) || ! Ship_Lines::load_readers() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Reserving sold fish is switched off.', 'fishotel-misc-plugin' ),
			);
		}
		if ( ! self::lock() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The site is writing the shipped fish right now — try again in a minute.', 'fishotel-misc-plugin' ),
			);
		}
		try {
			return self::pick_locked( $store, $settings, $order_id, $ref, $stay_id, $from, $qty );
		} finally {
			self::unlock();
		}
	}

	/**
	 * pick() under the lock.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @param int    $stay_id  The group it came out of.
	 * @param int    $from     The group it was held in and did not come out of (0 = the whole line).
	 * @param int    $qty      How many of $from's fish (0 = all; only before the order left).
	 * @return array{ok: bool, message: string}
	 */
	private static function pick_locked( $store, array $settings, $order_id, $ref, $stay_id, $from, $qty ) {
		$found = self::line_of( $order_id, $ref );
		$key   = Reserve::key( Reserve::META, $ref );
		if ( null === $found || null === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'That order line could not be found.', 'fishotel-misc-plugin' ),
			);
		}
		list( $facts, $line ) = $found;
		if ( ! Reserve::gated( $line ) && Reserve::left( $facts['signals'], (int) $line['item_id'] ) ) {
			// Install safety: an order that had left before this was switched on is never taken out of a tank.
			return array(
				'ok'      => false,
				'message' => __( 'That order had already left before reserving was switched on — nothing is taken out of a tank for it.', 'fishotel-misc-plugin' ),
			);
		}
		$stay = $store->stay( (int) $stay_id, Clean_Room::today(), $settings );
		if ( ! is_array( $stay ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That tank group could not be found.', 'fishotel-misc-plugin' ),
			);
		}

		$held = Reserve::held( $line['held'] );
		$was  = array_fill_keys( (array) $line['was'], 1 );
		$gone = Reserve::left( $facts['signals'], (int) $line['item_id'] );
		if ( (int) $from > 0 && isset( $held[ (int) $from ] ) && ! $gone && (int) $qty > 0 && (int) $qty < $held[ (int) $from ] ) {
			// Some of them only (before it left): the line is split over the two tanks.
			$n                     = (int) $qty;
			$held[ (int) $from ] -= $n;
		} elseif ( (int) $from > 0 && isset( $held[ (int) $from ] ) ) {
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
		return self::act_result( $store, $settings, $raw )['message'];
	}

	/**
	 * act(), with whether it was done (the notice is green, else yellow).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $raw      The posted form.
	 * @return array{ok: bool, message: string}
	 */
	public static function act_result( $store, array $settings, array $raw ) {
		$op    = isset( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
		$order = isset( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$ref   = isset( $raw['ref'] ) && is_scalar( $raw['ref'] ) ? preg_replace( '/[^a-z0-9:]/', '', strtolower( (string) $raw['ref'] ) ) : '';
		$ok = static function ( $done, $message ) {
			return array(
				'ok'      => (bool) $done,
				'message' => (string) $message,
			);
		};
		switch ( $op ) {
			case 'pick':
				return self::pick( $store, $settings, $order, $ref, isset( $raw['stay_id'] ) ? absint( $raw['stay_id'] ) : 0, isset( $raw['from'] ) ? absint( $raw['from'] ) : 0, isset( $raw['qty'] ) ? absint( $raw['qty'] ) : 0 );
			case 'ignore':
				return self::ignore( $order, $ref ) ? $ok( true, __( 'Ignored: that line is no longer listed.', 'fishotel-misc-plugin' ) ) : $ok( false, __( 'That order line could not be found.', 'fishotel-misc-plugin' ) );
			case 'ignore_all':
				$n = self::ignore_all();
				/* translators: %d: lines */
				return $ok( true, sprintf( _n( 'Ignored %d line.', 'Ignored %d lines.', $n, 'fishotel-misc-plugin' ), $n ) );
			case 'hold':
				return self::hold_create(
					$store,
					$settings,
					array(
						'stay_id' => isset( $raw['stay_id'] ) ? absint( $raw['stay_id'] ) : 0,
						'qty'     => isset( $raw['qty'] ) ? absint( $raw['qty'] ) : 0,
						'who'     => isset( $raw['who'] ) && is_scalar( $raw['who'] ) ? sanitize_text_field( (string) $raw['who'] ) : '',
						'user_id' => isset( $raw['user_id'] ) ? absint( $raw['user_id'] ) : 0,
						'note'    => isset( $raw['note'] ) && is_scalar( $raw['note'] ) ? sanitize_text_field( (string) $raw['note'] ) : '',
						'by'      => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
					)
				);
			case 'release':
				return self::hold_release( isset( $raw['hold_id'] ) ? absint( $raw['hold_id'] ) : 0 );
			case 'give':
				return self::hold_give( $store, $settings, isset( $raw['hold_id'] ) ? absint( $raw['hold_id'] ) : 0, $order, $ref );
			case 'not':
				return self::hold_dismiss( isset( $raw['hold_id'] ) ? absint( $raw['hold_id'] ) : 0, $order, $ref ) ? $ok( true, __( 'That order will not be asked about again for this hold.', 'fishotel-misc-plugin' ) ) : $ok( false, __( 'That hold is no longer there.', 'fishotel-misc-plugin' ) );
			default:
				return $ok( false, __( 'Unknown action.', 'fishotel-misc-plugin' ) );
		}
	}

	/* ============================================================== */
	/*  Held by hand                                                   */
	/* ============================================================== */

	/**
	 * The holds by hand as stored (Holds::book()) — read once per request.
	 *
	 * @return array
	 */
	public static function book() {
		if ( ! isset( self::$memo['book'] ) ) {
			self::$memo['book'] = Holds::book( function_exists( 'get_option' ) ? get_option( Holds::OPTION, array() ) : array() );
		}
		return self::$memo['book'];
	}

	/**
	 * Whether a group has an open hold by hand (one read of the holds per
	 * request).
	 *
	 * @param int $stay_id Stay id.
	 * @return bool
	 */
	public static function holds_stay( $stay_id ) {
		if ( (int) $stay_id <= 0 ) {
			return false;
		}
		foreach ( Holds::open( self::book() ) as $hold ) {
			if ( (int) $hold['stay_id'] === (int) $stay_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Store the holds (not autoloaded) and forget what was worked out
	 * from them.
	 *
	 * @param array $book Holds::book().
	 */
	private static function save_book( array $book ) {
		$book = Holds::trim_closed( Holds::book( $book ) );
		if ( function_exists( 'update_option' ) ) {
			update_option( Holds::OPTION, $book, false );
		}
		self::flush();
		self::$memo['book'] = $book;
	}

	/**
	 * Give the fish of holds to the orders that took them (Reserve::plan()'s
	 * takes): each hold keeps the rest, and closes when none are left.
	 *
	 * @param array $takes Hold id => [ { number, qty } ].
	 */
	private static function apply_takes( array $takes ) {
		if ( ! $takes ) {
			return;
		}
		$book  = self::book();
		$notes = array();
		foreach ( $takes as $id => $rows ) {
			$hold = $book['holds'][ (int) $id ] ?? null;
			foreach ( (array) $rows as $row ) {
				$book = Holds::take( $book, (int) $id, (int) $row['qty'], (string) $row['number'], time() );
				if ( is_array( $hold ) && (int) ( $row['order_id'] ?? 0 ) > 0 ) {
					$notes[] = array( (int) $row['order_id'], $hold, (int) $row['qty'] );
				}
			}
		}
		$by = self::by_id( (array) ( self::$memo['stays']['all'] ?? array() ) ); // Read before save_book() forgets them.
		self::save_book( $book );
		foreach ( $notes as $note ) {
			// Said on the order too: whose held fish it took, from which tank.
			$stay = $by[ (int) $note[1]['stay_id'] ] ?? null;
			self::order_note( $note[0], self::hold_note( $note[1], $stay, $note[2], __( 'taken by this order (the customer\'s own paid order)', 'fishotel-misc-plugin' ) ) );
		}
	}

	/**
	 * The order note of a hold given to an order: "Clean Room: 2 × Test
	 * Tang Medium in tank 121, held by hand for Ann B. since 11-20-26 —
	 * taken by this order (…)".
	 *
	 * @param array      $hold The hold.
	 * @param array|null $stay Its group.
	 * @param int        $n    Fish given.
	 * @param string     $how  How.
	 * @return string
	 */
	public static function hold_note( array $hold, $stay, $n, $how ) {
		$fish = is_array( $stay ) ? trim( (string) ( $stay['species'] ?? '' ) . ' ' . (string) ( $stay['size_label'] ?? '' ) ) : '';
		$tank = is_array( $stay ) ? (int) ( $stay['tank_number'] ?? 0 ) : 0;
		$when = Dates::day( (int) ( $hold['at'] ?? 0 ) ); // The site's day, read 11-20-26. A note already written keeps its words.
		/* translators: 1: fish, 2: the fish, 3: tank, 4: who they were held for, 5: since when, 6: how */
		return sprintf( __( 'Clean Room: %1$d × %2$s in tank %3$d, held by hand for %4$s since %5$s — %6$s.', 'fishotel-misc-plugin' ), (int) $n, '' !== $fish ? $fish : __( 'fish', 'fishotel-misc-plugin' ), $tank, (string) ( $hold['who'] ?? '' ), '' !== $when ? $when : '?', $how );
	}

	/**
	 * A private note on an order (never one the customer is e-mailed).
	 *
	 * @param int    $order_id Order id.
	 * @param string $text     Note.
	 */
	private static function order_note( $order_id, $text ) {
		try {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
			if ( is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( (string) $text, 0, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A customer of the shop, as a hold by hand names them: "Mike S." and
	 * their e-mail — read from their account, never from what was posted.
	 *
	 * @param int $user_id User id.
	 * @return array{user_id: int, who: string, email: string, label: string}|null
	 */
	public static function customer_of( $user_id ) {
		$user = function_exists( 'get_userdata' ) ? get_userdata( (int) $user_id ) : false;
		if ( ! is_object( $user ) ) {
			return null;
		}
		$meta  = static function ( $key ) use ( $user_id ) {
			return function_exists( 'get_user_meta' ) ? trim( (string) get_user_meta( (int) $user_id, $key, true ) ) : '';
		};
		$first = '' !== $meta( 'billing_first_name' ) ? $meta( 'billing_first_name' ) : $meta( 'first_name' );
		$last  = '' !== $meta( 'billing_last_name' ) ? $meta( 'billing_last_name' ) : $meta( 'last_name' );
		$email = '' !== $meta( 'billing_email' ) ? $meta( 'billing_email' ) : (string) ( $user->user_email ?? '' );
		$name  = trim( $first . ' ' . $last );
		return array(
			'user_id' => (int) $user_id,
			'who'     => Holds::short_name( $first, $last, (string) ( $user->display_name ?? '' ) ),
			'email'   => strtolower( trim( $email ) ),
			'label'   => ( '' !== $name ? $name : (string) ( $user->display_name ?? '' ) ) . ( '' !== $email ? ' <' . $email . '>' : '' ),
		);
	}

	/**
	 * Whether the person asking may search the shop's customers and tie a
	 * hold to an account — what WooCommerce's own customer search asks
	 * (edit_shop_orders); everybody else types a name.
	 *
	 * @return bool
	 */
	public static function can_pick_customers() {
		return function_exists( 'current_user_can' ) && ( current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) );
	}

	/**
	 * The fish of some groups on the Invoices section's open invoices
	 * (drafts and published, not paid): stay id => fish — empty when that
	 * section is not there.
	 *
	 * @param int[] $stay_ids Stay ids.
	 * @return array<int, int>
	 */
	public static function invoiced( array $stay_ids ) {
		$class = '\\FisHotel\\Misc\\Sections\\Invoices\\Store';
		if ( ! class_exists( $class, false ) || ! method_exists( $class, 'stay_reservations' ) || ( method_exists( $class, 'ready' ) && ! $class::ready() ) ) {
			return array();
		}
		try {
			$out = ( new $class() )->stay_reservations( array_map( 'intval', $stay_ids ), 0 );
		} catch ( \Throwable $e ) {
			return array();
		}
		return is_array( $out ) ? array_map( 'intval', $out ) : array();
	}

	/**
	 * The shop's customers whose name or e-mail holds a search text (at
	 * most 10) — what "Reserve…" offers under "For whom?".
	 *
	 * @param string $term Search text.
	 * @return array[] customer_of() rows.
	 */
	public static function customers( $term ) {
		$term = trim( (string) $term );
		if ( strlen( $term ) < 2 || ! self::can_pick_customers() ) {
			return array(); // Names and e-mails only for those who may see the shop's customers.
		}
		$ids = array();
		try {
			if ( class_exists( '\\WC_Data_Store' ) ) {
				$ids = (array) \WC_Data_Store::load( 'customer' )->search_customers( $term, 10 );
			} elseif ( function_exists( 'get_users' ) ) {
				$ids = (array) get_users(
					array(
						'search'         => '*' . $term . '*',
						'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
						'number'         => 10,
						'fields'         => 'ID',
					)
				);
			}
		} catch ( \Throwable $e ) {
			$ids = array();
		}
		$out = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 10 ) as $id ) {
			$row = $id > 0 ? self::customer_of( $id ) : null;
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * "Reserve…": some ready fish of a group set aside for somebody — a
	 * customer of the shop (user_id: their name and e-mail are read from
	 * the account) or any name typed. Never more than are ready.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $raw      stay_id, qty, who, user_id, note, by.
	 * @return array{ok: bool, message: string, id?: int}
	 */
	public static function hold_create( $store, array $settings, array $raw ) {
		if ( ! self::enabled( $settings ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Reserving sold fish is switched off.', 'fishotel-misc-plugin' ),
			);
		}
		if ( ! self::lock() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The site is writing the shipped fish right now — try again in a minute.', 'fishotel-misc-plugin' ),
			);
		}
		try {
			$today   = Clean_Room::today();
			$stay    = $store->stay( (int) ( $raw['stay_id'] ?? 0 ), $today, $settings );
			$user    = (int) ( $raw['user_id'] ?? 0 );
			if ( $user > 0 && ! self::can_pick_customers() ) {
				return array(
					'ok'      => false,
					'message' => __( 'Only somebody who can see the shop\'s customers can pick an account — type a name instead.', 'fishotel-misc-plugin' ),
				);
			}
			$account = $user > 0 ? self::customer_of( $user ) : null;
			if ( $user > 0 && ! $account ) {
				return array(
					'ok'      => false,
					'message' => __( 'That customer could not be found.', 'fishotel-misc-plugin' ),
				);
			}
			$who = $account ? $account['who'] : (string) ( $raw['who'] ?? '' );
			if ( is_array( $stay ) ) {
				// Everything held there now: the orders and the other holds.
				self::$memo = array( 'book' => self::$memo['book'] ?? self::book() );
				$counts = self::ensure( $store, $settings, true )['counts'];
				$count  = (array) ( $counts[ (int) $stay['id'] ] ?? array() );
				// …and the fish on the Invoices section's open invoices (an unpaid invoice's order is counted once).
				$stay['reserved'] = (int) ( $count['qty'] ?? 0 ) - (int) ( $count['invoice'] ?? 0 ) + (int) ( self::invoiced( array( (int) $stay['id'] ) )[ (int) $stay['id'] ] ?? 0 );
			}
			$book   = self::book();
			$qty    = (int) ( $raw['qty'] ?? 0 );
			$refuse = Holds::refuse( $stay, $qty, $who, $book );
			if ( '' !== $refuse ) {
				return array(
					'ok'      => false,
					'message' => $refuse,
				);
			}
			$made = Holds::add(
				$book,
				array(
					'stay_id' => (int) $stay['id'],
					'qty'     => $qty,
					'who'     => $who,
					'user_id' => $account ? $account['user_id'] : 0,
					'email'   => $account ? $account['email'] : '',
					'note'    => (string) ( $raw['note'] ?? '' ),
					'by'      => (int) ( $raw['by'] ?? 0 ),
				),
				time()
			);
			self::save_book( $made['book'] );
			return array(
				'ok'      => true,
				'id'      => $made['id'],
				/* translators: 1: fish, 2: the fish's name, 3: tank number, 4: who for */
				'message' => Holds::sentence( sprintf( __( '%1$d × %2$s in tank %3$d reserved for %4$s', 'fishotel-misc-plugin' ), $qty, (string) $stay['species'], (int) ( $stay['tank_number'] ?? 0 ), $made['book']['holds'][ $made['id'] ]['who'] ) ), // Never "Maria T.." (two full stops).
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * "Release": a hold by hand ends — its fish are ready again.
	 *
	 * @param int $id Hold id.
	 * @return array{ok: bool, message: string}
	 */
	public static function hold_release( $id ) {
		if ( ! self::lock() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The site is writing the shipped fish right now — try again in a minute.', 'fishotel-misc-plugin' ),
			);
		}
		try {
			self::$memo = array();
			$book       = self::book();
			$hold       = $book['holds'][ (int) $id ] ?? null;
			if ( ! is_array( $hold ) || ! empty( $hold['closed'] ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'That hold is no longer there.', 'fishotel-misc-plugin' ),
				);
			}
			self::save_book( Holds::release( $book, (int) $id, time() ) );
			return array(
				'ok'      => true,
				/* translators: 1: who the fish were held for, 2: fish */
				'message' => sprintf( _n( 'Released: the fish held for %1$s is ready again.', 'Released: the %2$d fish held for %1$s are ready again.', (int) $hold['qty'], 'fishotel-misc-plugin' ), $hold['who'], (int) $hold['qty'] ),
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * "Give them the held fish": an order the owner says is the one a hold
	 * with a typed name was made for takes the held fish — the hold becomes
	 * that line's reservation (in the hold's group first) and closes
	 * ("taken by order #1240"); the line's other fish stay where they were.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param int    $id       Hold id.
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @return array{ok: bool, message: string}
	 */
	public static function hold_give( $store, array $settings, $id, $order_id, $ref ) {
		if ( ! self::enabled( $settings ) || ! Ship_Lines::load_readers() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Reserving sold fish is switched off.', 'fishotel-misc-plugin' ),
			);
		}
		if ( ! self::lock() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The site is writing the shipped fish right now — try again in a minute.', 'fishotel-misc-plugin' ),
			);
		}
		try {
			self::$memo = array();
			$holds      = self::ensure( $store, $settings, true )['holds'];
			$book       = self::book(); // Read after the pass: what it took is in it (never written back stale).
			$hold       = $holds[ (int) $id ] ?? null;
			$found      = self::line_of( $order_id, $ref );
			$key        = Reserve::key( Reserve::META, $ref );
			if ( ! is_array( $hold ) || ! isset( $book['holds'][ (int) $id ] ) || ! empty( $book['holds'][ (int) $id ]['closed'] ) || (int) $hold['qty'] <= 0 ) {
				return array(
					'ok'      => false,
					'message' => __( 'That hold is no longer there.', 'fishotel-misc-plugin' ),
				);
			}
			if ( null === $found || null === $key ) {
				return array(
					'ok'      => false,
					'message' => __( 'That order line could not be found.', 'fishotel-misc-plugin' ),
				);
			}
			list( $facts, $line ) = $found;
			if ( ! in_array( $facts['status'], Reserve::OPEN_STATUSES, true ) || Reserve::left( $facts['signals'], (int) $line['item_id'] ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'That order is no longer open — nothing is given to it.', 'fishotel-misc-plugin' ),
				);
			}
			// Never a wrong fish: only the held fish in the held size, and an account's hold only to its own order.
			$stay   = self::by_id( self::stays( $store, $settings )['all'] )[ (int) $hold['stay_id'] ] ?? null;
			$refuse = Holds::give_refuse( $hold, $line, $stay, (array) ( $facts['customer'] ?? array() ) );
			if ( '' !== $refuse ) {
				return array(
					'ok'      => false,
					'message' => $refuse,
				);
			}
			$n    = min( (int) $hold['qty'], (int) $line['qty'] );
			$held = Holds::give_to_line( Reserve::held( $line['held'] ), (int) $line['qty'], (int) $hold['stay_id'], $n );
			// The hold first: a request that dies between the two leaves the hold closed and the line short —
			// read again, it is reserved anew — never the same fish counted for both.
			self::save_book( Holds::take( $book, (int) $id, $n, (string) $facts['number'], time() ) );
			self::write_meta( (int) $line['item_id'], $key, Reserve::encode( $held ) );
			if ( (int) $line['seen'] <= 0 ) {
				self::write_meta( (int) $line['item_id'], Reserve::key( Reserve::META_SEEN, $ref ), (string) time() );
			}
			Ship_Lines::flush();
			self::flush();
			self::order_note( (int) $facts['id'], self::hold_note( $hold, $stay, $n, __( 'given to this order by hand (To do → "Give them the held fish")', 'fishotel-misc-plugin' ) ) );
			return array(
				'ok'      => true,
				/* translators: 1: fish, 2: who they were held for, 3: order number */
				'message' => sprintf( _n( 'The fish held for %2$s now goes to order #%3$s.', 'The %1$d fish held for %2$s now go to order #%3$s.', $n, 'fishotel-misc-plugin' ), $n, (string) $hold['who'], (string) $facts['number'] ),
			);
		} finally {
			self::unlock();
		}
	}

	/**
	 * "Not this order": the suggestion is not asked again.
	 *
	 * @param int    $id       Hold id.
	 * @param int    $order_id Order id.
	 * @param string $ref      The line's ref.
	 * @return bool
	 */
	public static function hold_dismiss( $id, $order_id, $ref ) {
		if ( ! self::lock() ) {
			return false;
		}
		try {
			self::$memo = array();
			$book       = self::book();
			if ( ! isset( $book['holds'][ (int) $id ] ) ) {
				return false;
			}
			self::save_book( Holds::dismiss( $book, (int) $id, (int) $order_id . '|' . (string) $ref ) );
			return true;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Why a move of part of a group is refused (Holds::move_refuse()): it
	 * would take fish held by hand along — '' when it may move (or when
	 * reserving is off: holds are hidden then).
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $stay     The group (id, qty_live).
	 * @param int    $qty      Fish to move.
	 * @return string
	 */
	public static function move_refuse( $store, array $settings, array $stay, $qty ) {
		if ( ! self::enabled( $settings ) || (int) $qty <= 0 || (int) $qty >= (int) ( $stay['qty_live'] ?? 0 ) || ! self::holds_stay( (int) ( $stay['id'] ?? 0 ) ) ) {
			return '';
		}
		try {
			self::$memo = array( 'book' => self::book() );
			$count      = (array) ( self::ensure( $store, $settings, true )['counts'][ (int) $stay['id'] ] ?? array() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return Holds::move_refuse( (int) $stay['qty_live'], (int) ( $count['qty'] ?? 0 ), (array) ( $count['holds'] ?? array() ), (int) $qty );
	}

	/**
	 * Fish shipped BY HAND that were someone's held fish (the phone's
	 * "Shipped" with the hold ticked): each ticked hold of that group goes
	 * down by them, the oldest first, and closes when none are left.
	 *
	 * @param int   $stay_id  The group.
	 * @param int[] $hold_ids The holds ticked.
	 * @param int   $qty      Fish shipped.
	 * @return int How many held fish were taken off holds.
	 */
	public static function hold_shipped( $stay_id, array $hold_ids, $qty ) {
		$hold_ids = array_values( array_filter( array_map( 'intval', $hold_ids ) ) );
		if ( ! $hold_ids || (int) $qty <= 0 || ! self::lock() ) {
			return 0;
		}
		try {
			self::$memo = array();
			$book       = self::book();
			$left       = (int) $qty;
			$done       = 0;
			foreach ( Holds::open( $book ) as $hold ) {
				if ( $left <= 0 || (int) $hold['stay_id'] !== (int) $stay_id || ! in_array( (int) $hold['id'], $hold_ids, true ) ) {
					continue;
				}
				$n     = min( $left, (int) $hold['qty'] );
				$book  = Holds::ship( $book, (int) $hold['id'], $n, time() );
				$left -= $n;
				$done += $n;
			}
			if ( $done > 0 ) {
				self::save_book( $book );
			}
			return $done;
		} finally {
			self::unlock();
		}
	}

	/**
	 * The holds that closed lately, as the list shows them: who, how many
	 * they asked, the fish and tank (when the group is still open), why it
	 * ended ("taken by order #1240") and when.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @return array[]
	 */
	public static function closed_rows( $store, array $settings ) {
		$open = self::by_id( self::stays( $store, $settings )['all'] );
		$now  = time();
		$out  = array();
		foreach ( array_slice( Holds::closed_lately( self::book(), $now ), 0, 20 ) as $hold ) {
			$stay  = $open[ (int) $hold['stay_id'] ] ?? null;
			$asked = (int) $hold['qty'] + (int) array_sum( array_map( 'intval', array_column( (array) $hold['taken'], 'qty' ) ) );
			$out[] = array(
				'id'   => (int) $hold['id'],
				'who'  => (string) $hold['who'],
				'qty'  => max( 1, $asked ),
				'tank' => is_array( $stay ) ? (int) ( $stay['tank_number'] ?? 0 ) : 0,
				'fish' => is_array( $stay ) ? trim( (string) $stay['species'] . ' ' . (string) ( $stay['size_label'] ?? '' ) ) : '',
				'why'  => Holds::why_text( $hold ),
				'when' => Reserve::age_text( (int) $hold['closed'], $now ),
			);
		}
		return $out;
	}

	/**
	 * Every open hold as the list of all holds by hand shows it: its tank,
	 * fish and size, who for, how many (and how many it still holds when
	 * fish died), how long, the note.
	 *
	 * @param object $store    Store or stand-in.
	 * @param array  $settings Settings.
	 * @param array  $holds    ensure()['holds'].
	 * @return array[]
	 */
	public static function hold_rows( $store, array $settings, array $holds ) {
		$open = array();
		foreach ( self::stays( $store, $settings )['all'] as $stay ) {
			$open[ (int) $stay['id'] ] = $stay;
		}
		$now = time();
		$out = array();
		foreach ( $holds as $hold ) {
			$stay  = $open[ (int) $hold['stay_id'] ] ?? null;
			$out[] = array(
				'id'      => (int) $hold['id'],
				'tank'    => is_array( $stay ) ? (int) ( $stay['tank_number'] ?? 0 ) : 0,
				'fish'    => is_array( $stay ) ? trim( (string) $stay['species'] . ' ' . (string) ( $stay['size_label'] ?? '' ) ) : '',
				'who'     => (string) $hold['who'],
				'account' => Holds::is_account( $hold ),
				'qty'     => (int) $hold['qty'],
				'asked'   => (int) ( $hold['asked'] ?? $hold['qty'] ),
				'note'    => (string) $hold['note'],
				'age'     => Reserve::age_text( (int) $hold['at'], $now ),
				'old'     => Holds::is_old( $hold, $now ),
				'gone'    => ! is_array( $stay ),
			);
		}
		return $out;
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
			$ensure = self::ensure( $store, $settings );
			$out    = array( 'counts' => $ensure['counts'] );
			if ( $lists ) {
				$out['pull']    = self::pull( $store, $today, $settings, $tanks );
				$out['lost']    = array_values( self::lost() );
				$out['holds']   = self::hold_rows( $store, $settings, (array) ( $ensure['holds'] ?? array() ) ); // Every hold by hand, with Release.
				$out['suggest'] = (array) ( $ensure['suggest'] ?? array() ); // "Is #1240 the order for the fish held for Mike S.?"
				$out['closed']  = self::closed_rows( $store, $settings ); // Ended lately: "taken by order #1240", "released".
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Today's pull list (Reserve::pull()), with the other clean groups
	 * each row's fish may have come out of — kept in a transient for the
	 * day (dropped with the reserved counts, and whenever today's shipping
	 * fish are read again).
	 *
	 * @param object  $store    Store or stand-in.
	 * @param string  $today    Site date.
	 * @param array   $settings Settings.
	 * @param array[] $tanks    Every tank.
	 * @return array
	 */
	public static function pull( $store, $today, array $settings, array $tanks ) {
		$cached = self::$memo['pull'] ?? ( function_exists( 'get_transient' ) ? get_transient( self::PULL_TRANSIENT ) : false );
		if ( is_array( $cached ) && (string) ( $cached['date'] ?? '' ) === (string) $today && isset( $cached['buildings'] ) ) {
			self::$memo['pull'] = $cached;
			return $cached;
		}

		$ship  = Ship_Lines::today( (string) $today );
		$lines = isset( $ship['lines'] ) && is_array( $ship['lines'] ) ? $ship['lines'] : array();

		// Whether each order has gone (its status, its shipments, its box): their shipments in one query.
		$left = array();
		self::prime( array_column( $lines, 'order_id' ) );
		try {
			foreach ( $lines as $i => $line ) {
				$id = (int) ( $line['order_id'] ?? 0 );
				if ( ! isset( $left[ $id ] ) ) {
					$order       = $id > 0 ? wc_get_order( $id ) : null;
					$left[ $id ] = is_object( $order ) ? self::left_signals( $order ) : array();
				}
				$lines[ $i ]['left'] = $left[ $id ] && Reserve::left( $left[ $id ], (int) ( $line['item_id'] ?? 0 ) );
			}
		} finally {
			self::unprime();
		}

		$ids = array();
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
		// The groups: the open ones this request already read; only a closed one is read on its own.
		$open = array();
		foreach ( self::stays( $store, $settings )['all'] as $stay ) {
			$open[ (int) $stay['id'] ] = $stay;
		}
		$stays = array();
		foreach ( array_keys( $ids ) as $id ) {
			$stay = $open[ $id ] ?? $store->stay( $id, (string) $today, $settings );
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

		// The other clean groups each row's fish may have come out of — not for a fish already gone:
		// "It was tank…" on the not-found list is for those (and an order gone before the switch-on has none).
		$clean = self::stays( $store, $settings )['clean'];
		$by    = array();
		foreach ( $lines as $line ) {
			$by[ (int) ( $line['order_id'] ?? 0 ) . '|' . (string) ( $line['ref'] ?? '' ) ] = $line;
		}
		foreach ( $pull['buildings'] as $b => $building ) {
			foreach ( $building['tanks'] as $t => $tank ) {
				foreach ( $tank['rows'] as $r => $row ) {
					$line = $by[ $row['order_id'] . '|' . $row['ref'] ] ?? array();
					$pull['buildings'][ $b ]['tanks'][ $t ]['rows'][ $r ]['others'] = self::choices( Reserve::alternatives( $line, $clean, array( (int) $row['stay_id'] ) ) ); // A line split over two tanks: each row offers the other.
					$pull['buildings'][ $b ]['tanks'][ $t ]['rows'][ $r ]['line_qty'] = (int) ( $line['qty'] ?? 0 );
					$pull['buildings'][ $b ]['tanks'][ $t ]['rows'][ $r ]['left']     = ! empty( $line['left'] );
				}
			}
		}
		foreach ( $pull['unknown'] as $u => $row ) {
			$pull['unknown'][ $u ]['others'] = ! empty( $row['shipped'] ) ? array() : self::choices( Reserve::alternatives( $by[ $row['order_id'] . '|' . $row['ref'] ] ?? array(), $clean ) );
		}
		self::$memo['pull'] = $pull;
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::PULL_TRANSIENT, $pull, self::CACHE_SECONDS );
		}
		return $pull;
	}

	/**
	 * left()'s signals of an order: its own, and its combined box's.
	 *
	 * @param object $order Order.
	 * @return array
	 */
	private static function left_signals( $order ) {
		$box  = null;
		$head = Ship_Lines::head( $order );
		if ( $head && $head['folded'] ) {
			$wrapper = wc_get_order( (int) $order->get_meta( Ship_Lines::META_FOLDED ) );
			$box     = is_object( $wrapper ) ? self::signals( $wrapper ) : null;
		}
		return array_merge( self::signals( $order ), array( 'box' => $box ) );
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
	 * The shop's stock of some products, as Reserve::stock_warnings()
	 * reads it: a product that counts its stock as a whole, and each
	 * variation that counts its own (by its size).
	 *
	 * @param int[] $product_ids Product ids (at most 200 are read).
	 * @return array[] product_id, size_slug, label, stock.
	 */
	public static function stock_rows( array $product_ids ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) ), 0, 200 ) as $id ) {
			try {
				$product = wc_get_product( $id );
				if ( ! is_object( $product ) ) {
					continue;
				}
				$name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
				if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
					$out[] = array(
						'product_id' => $id,
						'size_slug'  => '',
						'label'      => $name,
						'stock'      => (int) $product->get_stock_quantity(),
					);
				}
				if ( ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
					continue;
				}
				foreach ( (array) $product->get_children() as $child ) {
					$variation = wc_get_product( (int) $child );
					if ( ! is_object( $variation ) || ! $variation->managing_stock() || (int) $variation->get_stock_managed_by_id() !== (int) $child ) {
						continue; // Counted on the parent (above), or not at all.
					}
					$size = '';
					$key  = '';
					foreach ( (array) $variation->get_attributes() as $attribute => $value ) {
						if ( Sizes::matches( (string) $attribute ) && is_scalar( $value ) && '' !== (string) $value ) {
							$size = (string) $value;
							$key  = (string) $attribute;
							break;
						}
					}
					if ( '' === $size ) {
						continue;
					}
					$out[] = array(
						'product_id' => $id,
						'size_slug'  => Sizes::slug( $size ),
						'label'      => $name . ' · ' . Ship_Lines::size_name( (string) $key, $size ),
						'stock'      => (int) $variation->get_stock_quantity(),
					);
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $out;
	}

	/**
	 * "Shop says 5 in stock, tanks have 3 ready" for the products of some
	 * groups — empty while the feature is off.
	 *
	 * @param object  $store    Store or stand-in.
	 * @param array   $settings Settings.
	 * @param array[] $stays    Groups (open ones count).
	 * @return array[] Reserve::stock_warnings().
	 */
	public static function stock_warnings( $store, array $settings, array $stays ) {
		if ( ! self::enabled( $settings ) || ! $stays ) {
			return array();
		}
		try {
			$counts = self::ensure( $store, $settings )['counts'];
			return Reserve::stock_warnings( self::stock_rows( array_column( $stays, 'product_id' ) ), $stays, $counts );
		} catch ( \Throwable $e ) {
			return array();
		}
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
		$counts = $stay_ids ? array_intersect_key( $counts, array_flip( array_map( 'intval', $stay_ids ) ) ) : $counts;
		foreach ( $counts as $stay => $count ) {
			// Who they are held for, in the Clean Room's words: "#1234; 1 for Mike S. — by hand, 3 days ago".
			$counts[ $stay ]['who'] = Reserve::reserved_who( (array) ( $count['orders'] ?? array() ), (array) ( $count['holds'] ?? array() ) );
		}
		return $counts;
	}
}
