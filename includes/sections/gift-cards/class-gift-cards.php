<?php
/**
 * Gift Cards section — FisHotel's own gift cards, to replace the paid
 * WooCommerce Gift Cards plugin: cards issued from wp-admin (no order
 * needed) or bought as a product, a code added to the customer's account,
 * all / part / none of that balance put toward an order at checkout as a
 * payment (not a coupon: tax and refunds stay right), put back exactly
 * once when the order fails, is cancelled or refunded, an append-only
 * audit log of every change, a clean list, stats for the books, no expiry
 * — and an importer that copies every old card and its history.
 *
 * It arrives switched OFF, and once on it starts in mode Off (Modes):
 * Shadow lets the import, the list and the stats be checked while the old
 * plugin is still in charge; Live hands the shop to this section, and
 * refuses while the old plugin is switched on.
 *
 * Architecture:
 *   - class-store.php      the ONLY file that touches $wpdb: three tables
 *                          (cards, the append-only ledger, the append-only
 *                          audit log) via dbDelta gated by
 *                          fishotel_gift_cards_db_version; the old plugin's
 *                          tables are only read (SELECT / SHOW).
 *   - class-money.php      PURE: cents, money words, the payment plan.
 *   - class-codes.php      the codes: long, random, masked.
 *   - class-settings.php   the one option: every owner decision, defaulted safe.
 *   - class-modes.php      Off / Shadow / Live and the Live guard.
 *   - class-audit.php      the audit rows, the hash chain, the export.
 *   - class-mailer.php     the gift card e-mail.
 *   - class-service.php    everything that can happen to a card, and the
 *                          money rules (store, settings, mailer and clock
 *                          as parameters).
 *   - class-legacy-map.php THE map of the old plugin's tables (one edit
 *                          once they are read on staging).
 *   - class-importer.php   schema check, dry run, import, report.
 *   - class-cron.php       the hourly job: e-mails whose day has come.
 *   - class-checkout.php   the balance box, the "Gift card" fee, paying,
 *                          putting back, old orders' deduction (Live).
 *   - class-product.php    gift card products: amounts, the purchase
 *                          fields, issuing when paid (Live).
 *   - class-account.php    My Account → Gift Cards (Live).
 *   - class-admin.php      FisHotel Tools → Gift Cards, and the order
 *                          screen's Gift cards box.
 *
 * The class file has no side effects; nothing runs until boot().
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gift_Cards
 */
class Gift_Cards {

	const MENU_SLUG = 'fishotel-misc-gift-cards';

	/**
	 * Who sees the list, the stats and the log (codes masked).
	 *
	 * @var string
	 */
	const VIEW_CAPABILITY = 'manage_woocommerce';

	/**
	 * Who sees full codes, changes the mode, imports and changes settings.
	 *
	 * @var string
	 */
	const OWNER_CAPABILITY = 'manage_options';

	/**
	 * The service of this request.
	 *
	 * @var Service|null
	 */
	private static $service = null;

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
		return FISHOTEL_MISC_URL . 'includes/sections/gift-cards/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Gift Cards', 'fishotel-misc-plugin' ),
			'description' => __( 'FisHotel\'s own gift cards, to replace the WooCommerce Gift Cards plugin: issue and change cards here with a reason, an audit log of every change, cards bought as a product, a balance customers choose how much of to use at checkout, stats for the books, no expiry, and an import of every old card. Starts in mode Off; Shadow to check the import, Live to take over.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-tickets-alt',
		);
	}

	/**
	 * Load every class file (no side effects).
	 */
	public static function load() {
		foreach ( array( 'money', 'codes', 'settings', 'modes', 'audit', 'store', 'mailer', 'service', 'legacy-map', 'importer', 'cron', 'checkout', 'product', 'account' ) as $file ) {
			require_once __DIR__ . '/class-' . $file . '.php';
		}
	}

	/**
	 * Boot the section when it is switched on. In mode Off only the admin
	 * screen exists (to change the mode); in Shadow, wp-admin and the
	 * job's clean-up; in Live, the shop too. An ordinary front-end request
	 * in Off or Shadow registers nothing and queries nothing.
	 */
	public static function boot() {
		self::load();

		if ( is_admin() ) {
			try {
				Store::maybe_install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$mode = Modes::current();

		if ( is_admin() ) {
			require_once __DIR__ . '/class-admin.php';
			$admin = new Admin();
			$admin->init( $mode );
		}

		if ( 'off' === $mode['stored'] ) {
			return;
		}

		Cron::init( $mode['effective'] );

		if ( 'live' !== $mode['effective'] ) {
			if ( ! empty( $mode['fallback'] ) ) {
				// Live fell back (the old plugin is on again): no paying with
				// these cards, but money they already took still goes back,
				// and a refunded purchase still holds its card's e-mail.
				( new Checkout() )->init_put_back();
				( new Product() )->init_undo();
			}
			return;
		}

		$checkout = new Checkout();
		$checkout->init();

		$product = new Product();
		$product->init();

		$account = new Account();
		$account->init();
	}

	/**
	 * While the section is switched off: its job (if one was left
	 * scheduled) is taken off the schedule. Reads the in-memory cron list
	 * only.
	 */
	public static function boot_disabled() {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'fishotel_gift_cards_hourly' ) && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'fishotel_gift_cards_hourly' );
		}
	}

	/**
	 * Register the admin submenu.
	 */
	public static function register_menu() {
		self::load();
		require_once __DIR__ . '/class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Gift Cards', 'fishotel-misc-plugin' ),
			__( 'Gift Cards', 'fishotel-misc-plugin' ),
			self::VIEW_CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin activation: install the tables (forced past the version gate).
	 */
	public static function activate() {
		self::load();
		try {
			( new Store() )->install( true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Plugin deactivation: drop the job. Tables, settings and the log stay.
	 */
	public static function deactivate() {
		self::load();
		Cron::clear();
	}

	/**
	 * Whether the section is switched on (one autoloaded option).
	 *
	 * @return bool
	 */
	public static function enabled() {
		$key = class_exists( '\\FisHotel\\Misc\\Section_Manager', false ) ? \FisHotel\Misc\Section_Manager::OPTION_KEY : 'fishotel_misc_enabled_sections';
		return in_array( 'gift-cards', (array) get_option( $key, array() ), true );
	}

	/**
	 * The service wired to the real store, mailer and clock.
	 *
	 * @param bool $fresh Build it again (the settings were just saved).
	 * @return Service
	 */
	public static function service( $fresh = false ) {
		if ( null === self::$service || $fresh ) {
			self::load();
			$settings      = Settings::get();
			self::$service = new Service( new Store(), $settings, new Mailer( $settings ), array() );
		}
		return self::$service;
	}

	/**
	 * Use this service from now on (the tests hand in their own).
	 *
	 * @param Service|null $service A service, or null to build the real one again.
	 */
	public static function use_service( $service ) {
		self::$service = $service;
	}

	/**
	 * Who may issue cards and change balances.
	 *
	 * @return string
	 */
	public static function edit_capability() {
		return (string) self::service()->settings['editor_capability'];
	}
}
