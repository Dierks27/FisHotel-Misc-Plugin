<?php
/**
 * Product checks — when a product is checked again, and the sweep.
 *
 * One product (a few milliseconds: its meta and one pattern over its
 * text) is checked again whenever it changes in WordPress:
 *   - saved anywhere — the editor, quick edit, bulk edit, the REST API, a
 *     CSV import (`woocommerce_new_product`, `woocommerce_update_product`);
 *   - a variation saved, or a stock status changed (its parent);
 *   - its featured image or gallery set (`added/updated/deleted_post_meta`
 *     of `_thumbnail_id` / `_product_image_gallery`: "Set featured image"
 *     does not save the product);
 *   - its categories set (`set_object_terms`, product_cat).
 * A product changed several times in one request is checked once, at the
 * end of it (`shutdown`).
 *
 * The sweep checks every product, 200 a run, on WP-cron, re-scheduling
 * itself until done: nightly (for what changed outside WordPress), when
 * the rule version changes, when a placeholder library, WooCommerce's
 * placeholder image or Packing's settings change, when the live
 * categories are saved, and on "Recount now". Its cursor is one option
 * (`fishotel_product_checks_sweep`).
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checker
 */
class Checker {

	/**
	 * The cron hooks: one run of the sweep, and the nightly start.
	 */
	const HOOK_RUN     = 'fishotel_product_checks_sweep';
	const HOOK_NIGHTLY = 'fishotel_product_checks_nightly';

	/**
	 * The option of the sweep: cursor, started, done, checked.
	 *
	 * @var string
	 */
	const SWEEP = 'fishotel_product_checks_sweep';

	/**
	 * How many products one run checks.
	 *
	 * @var int
	 */
	const BATCH = 200;

	/**
	 * The products to check at the end of this request.
	 *
	 * @var int[]
	 */
	private $queue = array();

	/**
	 * The store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * @param Store|null $store The store (a stand-in in the tests).
	 */
	public function __construct( $store = null ) {
		$this->store = is_object( $store ) ? $store : new Store();
	}

	/**
	 * Register the hooks — on every request: a product can change anywhere
	 * (a stock status at checkout). Each only queues an id; the work is
	 * done once, at the end of the request.
	 */
	public function init() {
		add_action( 'woocommerce_new_product', array( $this, 'queue' ) );
		add_action( 'woocommerce_update_product', array( $this, 'queue' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'queue_parent' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'queue_parent' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue' ) );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), 10, 4 );
		add_action( 'shutdown', array( $this, 'flush' ) );

		add_action( self::HOOK_RUN, array( $this, 'run' ) );
		add_action( self::HOOK_NIGHTLY, array( $this, 'start' ) );
		// The placeholder libraries, WooCommerce's placeholder image, Packing's settings (its size → bag map).
		foreach ( array( 'fh_placeholder_library', 'fh_cs_placeholder_library', 'woocommerce_placeholder_image', 'fishotel_packing_settings' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'start' ) );
			add_action( 'add_option_' . $option, array( $this, 'start' ) );
		}
	}

	/**
	 * Queue a product.
	 *
	 * @param mixed $id Product ID (or a product).
	 */
	public function queue( $id ) {
		$id = is_object( $id ) && method_exists( $id, 'get_id' ) ? (int) $id->get_id() : ( is_scalar( $id ) ? (int) $id : 0 );
		if ( $id > 0 && ! in_array( $id, $this->queue, true ) ) {
			$this->queue[] = $id;
		}
	}

	/**
	 * Queue a variation's parent.
	 *
	 * @param mixed $id Variation ID (or a variation).
	 */
	public function queue_parent( $id ) {
		$id     = is_object( $id ) && method_exists( $id, 'get_id' ) ? (int) $id->get_id() : ( is_scalar( $id ) ? (int) $id : 0 );
		$parent = $id > 0 && function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $id ) : 0;
		$this->queue( $parent > 0 ? $parent : $id );
	}

	/**
	 * A post meta write: a product's featured image or gallery.
	 *
	 * @param mixed  $meta_ids Meta ID(s).
	 * @param int    $post_id  Post ID.
	 * @param string $key      Meta key.
	 */
	public function meta_changed( $meta_ids, $post_id, $key ) {
		if ( in_array( $key, array( '_thumbnail_id', '_product_image_gallery' ), true ) && 'product' === get_post_type( (int) $post_id ) ) {
			$this->queue( (int) $post_id );
		}
	}

	/**
	 * Terms set on an object: a product's categories.
	 *
	 * @param int    $object_id Object ID.
	 * @param mixed  $terms     Terms.
	 * @param mixed  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 */
	public function terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_cat' === $taxonomy ) {
			$this->queue( (int) $object_id );
		}
	}

	/**
	 * The queued products, checked (end of the request).
	 *
	 * @return int How many.
	 */
	public function flush() {
		$ids         = $this->queue;
		$this->queue = array();
		foreach ( $ids as $id ) {
			$this->check( $id );
		}
		return count( $ids );
	}

	/**
	 * Check one product again: its flags written, the counts forgotten when
	 * anything changed.
	 *
	 * @param int        $id     Product ID.
	 * @param int[]|null $shared Images featured on 3 or more products (null = as last worked out).
	 * @return array|null The flags, or null for what is not a product.
	 */
	public function check( $id, $shared = null ) {
		try {
			$facts = Collector::facts( (int) $id, null === $shared ? $this->store->shared() : $shared );
			if ( null === $facts ) {
				return null;
			}
			$flags = Rules::flags( $facts, Settings::live_cats() );
			if ( $this->store->write( (int) $id, Rules::meta( $flags ) ) ) {
				$this->store->forget_counts();
			}
			return $flags;
		} catch ( \Throwable $e ) {
			return null; // Never break a save over a check.
		}
	}

	/**
	 * Start a sweep when the rules changed since the last one (wp-admin,
	 * admin_init).
	 */
	public function maybe_new_rules() {
		if ( (int) get_option( self::SWEEP . '_v', 0 ) !== Rules::VERSION ) {
			update_option( self::SWEEP . '_v', Rules::VERSION, false );
			$this->start();
		}
	}

	/**
	 * The nightly start, scheduled while the section is on (wp-admin,
	 * admin_init — switching a section on runs no activation).
	 */
	public static function ensure_nightly() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK_NIGHTLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_NIGHTLY );
		}
	}

	/**
	 * Both cron hooks taken off (the section switched off).
	 */
	public static function unschedule() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK_RUN );
			wp_clear_scheduled_hook( self::HOOK_NIGHTLY );
		}
	}

	/**
	 * Start a sweep from the first product (the next run in a minute).
	 */
	public function start() {
		update_option(
			self::SWEEP,
			array(
				'cursor'  => 0,
				'started' => time(),
				'done'    => 0,
				'checked' => 0,
			),
			false
		);
		$this->store->refresh_shared();
		self::schedule( 60 );
	}

	/**
	 * The sweep's state.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::SWEEP, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Schedule the next run (once).
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function schedule( $delay = 60 ) {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK_RUN ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::HOOK_RUN );
		}
	}

	/**
	 * One run: the next BATCH products; the next run scheduled until done.
	 *
	 * @return int How many were checked.
	 */
	public function run() {
		$state = self::state();
		if ( ! isset( $state['cursor'] ) || ! empty( $state['done'] ) ) {
			return 0;
		}
		$ids    = $this->store->batch( (int) $state['cursor'], self::BATCH );
		$shared = $this->store->shared();
		foreach ( $ids as $id ) {
			$this->check( $id, $shared );
		}
		$state['checked'] = (int) ( $state['checked'] ?? 0 ) + count( $ids );
		if ( count( $ids ) < self::BATCH ) {
			$state['done'] = time();
		} else {
			$state['cursor'] = (int) end( $ids );
		}
		update_option( self::SWEEP, $state, false );
		$this->store->forget_counts();
		if ( empty( $state['done'] ) ) {
			self::schedule( 30 );
		}
		return count( $ids );
	}
}
