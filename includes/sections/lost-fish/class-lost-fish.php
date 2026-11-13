<?php
/**
 * Lost Fish section — what happens when a fish somebody already paid for
 * dies in our care.
 *
 * WHY this exists: a loss used to be a tap on the phone and nothing else.
 * Whoever had paid for that fish found out when the box arrived without
 * it. Now a loss that leaves the tanks short of what paid customers are
 * owed opens a CASE: the owner sees the numbers for every choice and a
 * preview of the e-mail, clicks "Send to customer" once, and the customer
 * picks — refund the lost fish, take a credit, try again, or cancel the
 * whole order — on a small page behind a signed link. The section never
 * moves money, never changes stock and never cancels an order: after the
 * answer it shows the owner exactly what to do, with the amounts and the
 * links, and the owner ticks Done.
 *
 * Architecture:
 *   - class-store.php          the ONLY file that touches $wpdb: two tables
 *                              (cases, log) via dbDelta behind the autoloaded
 *                              fishotel_lostfish_db_version, conditional
 *                              status transitions, the open-order prefilter.
 *   - class-money.php          PURE: cents, the money for each choice.
 *   - class-cover.php          PURE: the kind of a tank group, COVERED,
 *                              COMMITTED, the shortage, who is suggested.
 *   - class-token.php          PURE: the signed, expiring link token bound to
 *                              one case and one choice.
 *   - class-settings.php       the option (send by itself, reminder days,
 *                              link life, the e-mail template) and its save.
 *   - class-rooms.php          the Clean Room, reached through class_exists:
 *                              a stay, the open stays of a kind, the undo
 *                              window.
 *   - class-orders.php         WooCommerce, through the order API only: the
 *                              holders of a kind (paid, not shipped, not
 *                              closed), an order's facts, its ship date, the
 *                              order notes; ShipTracker, the Invoices section
 *                              and the theme through class_exists /
 *                              function_exists.
 *   - class-shipping.php       the shipping difference: what WooCommerce's
 *                              shipping settings give for the order as placed
 *                              and without the fish — or "cannot tell".
 *   - class-email.php          the editable template, the choices block, the
 *                              preview, wp_mail.
 *   - class-cases.php          the life of a case: opened by a loss or by
 *                              hand, closed by an undo, sent, reminded,
 *                              answered, done, reopened.
 *   - class-customer-page.php  the page behind the links (no login): GET
 *                              shows, only a POST Confirm records.
 *   - class-admin.php          FisHotel Tools → Lost Fish (Cases / Log /
 *                              Settings), the admin-post handlers, the link
 *                              on each line of the order screen.
 *
 * The file uses bracketed namespaces because the contract helpers must
 * live in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Lost_Fish
	 */
	class Lost_Fish {

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-lost-fish';

		/**
		 * Capability for the screen and every write.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * The front-end query argument that carries a link token.
		 *
		 * @var string
		 */
		const QUERY_ARG = 'fh_lost_fish';

		/**
		 * The cron hook: the reminder after N days and, when switched on,
		 * the send after the undo window.
		 *
		 * @var string
		 */
		const TICK_HOOK = 'fishotel_lostfish_tick';

		/**
		 * The action the Clean Room fires after it recorded an event.
		 *
		 * @var string
		 */
		const CLEANROOM_EVENT = 'fishotel_cleanroom_event_recorded';

		/**
		 * Whether boot() ran in this request — that is, whether the
		 * section is switched on.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * A day to take for today (Y-m-d). The tests pin it; nothing else
		 * sets it.
		 *
		 * @var string|null
		 */
		public static $today = null;

		/**
		 * Section base path.
		 *
		 * @return string
		 */
		public static function path() {
			return __DIR__ . '/';
		}

		/**
		 * Section base URL.
		 *
		 * @return string
		 */
		public static function url() {
			return FISHOTEL_MISC_URL . 'includes/sections/lost-fish/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Lost Fish', 'fishotel-misc-plugin' ),
				'description' => __( 'When a fish a customer already paid for is lost: the tanks are checked for a spare, and when there is none a case opens with the numbers for every choice — refund, credit, try again, cancel — and an e-mail that waits for one click. The customer answers on a signed page; nothing moves by itself.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-heart',
			);
		}

		/**
		 * Load every class file explicitly (no side effects).
		 */
		public static function load() {
			require_once __DIR__ . '/class-money.php';
			require_once __DIR__ . '/class-cover.php';
			require_once __DIR__ . '/class-token.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-rooms.php';
			require_once __DIR__ . '/class-orders.php';
			require_once __DIR__ . '/class-shipping.php';
			require_once __DIR__ . '/class-email.php';
			require_once __DIR__ . '/class-cases.php';
			require_once __DIR__ . '/class-customer-page.php';
		}

		/**
		 * Boot the section when it is enabled. The tables install on the
		 * first boot only (the version gate is an autoloaded option, so every
		 * later request pays no query here). An ordinary front-end request
		 * then costs four add_action() calls and one isset(): nothing is
		 * read until a loss is recorded, the cron event fires or a link is
		 * opened.
		 */
		public static function boot() {
			self::load();
			self::$booted = true;

			try {
				Store::maybe_install( is_admin() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			add_action( self::CLEANROOM_EVENT, array( Cases::class, 'on_cleanroom_event' ), 10, 4 );
			add_action( self::TICK_HOOK, array( Cases::class, 'tick' ), 10, 0 );
			add_action( 'template_redirect', array( Customer_Page::class, 'maybe_render' ), 0, 0 );

			if ( is_admin() ) {
				require_once __DIR__ . '/class-admin.php';

				$admin = new Admin();
				$admin->init();

				self::ensure_tick();
			}
		}

		/**
		 * Keep the hourly event scheduled (wp-admin only; the schedule is
		 * read from the autoloaded cron option).
		 */
		public static function ensure_tick() {
			if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
				return;
			}
			if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
				wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::TICK_HOOK );
			}
		}

		/**
		 * Register the admin submenu. The title carries the number of cases
		 * that need the owner (from an autoloaded option — no query).
		 */
		public static function register_menu() {
			self::load();
			require_once __DIR__ . '/class-admin.php';

			$needs = Store::needs_you_count();
			$label = __( 'Lost Fish', 'fishotel-misc-plugin' );
			if ( $needs > 0 ) {
				$label .= ' <span class="awaiting-mod">' . (int) $needs . '</span>';
			}

			add_submenu_page(
				'fishotel-misc',
				__( 'Lost Fish', 'fishotel-misc-plugin' ),
				$label,
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback: install the tables (forced past the
		 * version gate so a dropped table comes back) and schedule the tick.
		 */
		public static function activate() {
			self::load();

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			self::ensure_tick();
		}

		/**
		 * Plugin-deactivation callback: unschedule the tick. The cases and
		 * the log are deliberately kept.
		 */
		public static function deactivate() {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::TICK_HOOK );
			}
		}

		/**
		 * The site zone.
		 *
		 * @return \DateTimeZone
		 */
		public static function timezone() {
			if ( function_exists( 'wp_timezone' ) ) {
				return wp_timezone();
			}
			return new \DateTimeZone( 'UTC' );
		}

		/**
		 * Today's calendar date in the site zone.
		 *
		 * @return string Y-m-d
		 */
		public static function today() {
			if ( is_string( self::$today ) && '' !== self::$today ) {
				return self::$today;
			}
			try {
				$now = new \DateTimeImmutable( 'now', self::timezone() );
				return $now->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				return gmdate( 'Y-m-d' );
			}
		}

		/**
		 * A UTC 'Y-m-d H:i:s' as a site-time label.
		 *
		 * @param string|null $utc    UTC datetime.
		 * @param string      $format PHP date format.
		 * @return string '' when there is none.
		 */
		public static function site_time( $utc, $format = 'M j, Y g:i a' ) {
			$ts = is_string( $utc ) && '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
			if ( false === $ts ) {
				return '';
			}
			try {
				$dt = new \DateTimeImmutable( '@' . (int) $ts );
				return $dt->setTimezone( self::timezone() )->format( $format );
			} catch ( \Exception $e ) {
				return gmdate( $format, (int) $ts );
			}
		}

		/**
		 * The cases of an order, for other sections (see
		 * fishotel_lostfish_cases_for_order()). While the section is
		 * switched off (boot() did not run) or its tables are not installed
		 * this is an empty list and no query.
		 *
		 * @param int $order_id Order id.
		 * @return array[]
		 */
		public static function cases_for_order( $order_id ) {
			if ( ! self::$booted || (int) $order_id <= 0 ) {
				return array();
			}
			try {
				if ( ! Store::installed() ) {
					return array();
				}
				$store = new Store();
				$out   = array();
				foreach ( $store->cases( array( 'order_id' => (int) $order_id ) ) as $case ) {
					$out[] = Cases::summary( $case );
				}
				return $out;
			} catch ( \Throwable $e ) {
				return array();
			}
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_lostfish_cases_for_order' ) ) {
		/**
		 * The lost-fish cases of a WooCommerce order: what a packing list,
		 * an invoice screen or the order page can read to know that a line
		 * is short and what the customer chose.
		 *
		 * This function exists whenever the Misc plugin is active (the
		 * Section Manager loads this file to list the section), so guard it
		 * with function_exists(). While the Lost Fish section is switched
		 * off, or before its tables exist, it returns an empty list and
		 * runs no query.
		 *
		 * Each row: id, status (open | sent | answered | done | closed),
		 * fish, qty, order_id, order_item_id, choice ('' | refund | credit |
		 * retry | cancel), amount (cents owed back for the choice, null when
		 * none was made), url (the case on the Lost Fish screen).
		 *
		 * @param int $order_id Order id.
		 * @return array<int, array{id: int, status: string, fish: string, qty: int, order_id: int, order_item_id: int, choice: string, amount: int|null, url: string}>
		 */
		function fishotel_lostfish_cases_for_order( $order_id ) {
			return \FisHotel\Misc\Sections\Lost_Fish\Lost_Fish::cases_for_order( (int) $order_id );
		}
	}
}
