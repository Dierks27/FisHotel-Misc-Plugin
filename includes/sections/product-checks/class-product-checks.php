<?php
/**
 * Product checks section — what each product still needs, counted on the
 * products list.
 *
 * The owner's ask: "how many fish need photos, especially live fish, what
 * ones need fields filled in, and what ones still have the old style before
 * we started splitting apart the info so it looks nice in a table."
 *
 * Architecture:
 *   - class-rules.php          PURE: who is checked, the flags, the chips.
 *   - class-collector.php      the facts of one product (reads only).
 *   - class-store.php          the only file that queries the database;
 *                              writes the flags; the
 *                              counts kept in one option.
 *   - class-checker.php        a product checked again when it changes; the
 *                              sweep on WP-cron.
 *   - class-settings.php       which categories count as live.
 *   - class-products-list.php  the "To fix" row, dropdown, column, row and
 *                              bulk actions on the products list.
 *   - class-admin.php          FisHotel Tools → Product checks; the chips of
 *                              the Edit screens section's Fish essentials.
 *
 * Outside wp-admin only the checker's hooks are registered (a product's
 * stock can change at checkout): each queues an id and the product is
 * checked once, at the end of the request. Nothing is shown to a visitor.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Checks
 */
class Product_Checks {

	/**
	 * Admin submenu slug, and its capability.
	 */
	const MENU_SLUG  = 'fishotel-misc-product-checks';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/product-checks/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Product checks', 'fishotel-misc-plugin' ),
			'description' => __( 'What each product still needs, counted on the products list: live fish without a real photo, missing table facts, old-style descriptions, no bag size, in stock with no price, Clean Room drafts — a "To fix" row, a dropdown, a column, and "Copy facts from the description".', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-yes-alt',
		);
	}

	/**
	 * Load the classes (no side effects).
	 */
	public static function load() {
		require_once __DIR__ . '/class-rules.php';
		require_once __DIR__ . '/class-settings.php';
		require_once __DIR__ . '/class-store.php';
		require_once __DIR__ . '/class-collector.php';
		require_once __DIR__ . '/class-checker.php';
		require_once __DIR__ . '/class-products-list.php';
		require_once __DIR__ . '/class-admin.php';
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		self::load();
		$store   = new Store();
		$checker = new Checker( $store );
		$checker->init();

		if ( ! is_admin() ) {
			return;
		}
		( new Products_List( $store, $checker ) )->init();
		( new Admin( $store, $checker ) )->init();
	}

	/**
	 * While the section is off: no sweep on WP-cron (wp-admin only, once).
	 */
	public static function boot_disabled() {
		if ( ! is_admin() || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( wp_next_scheduled( 'fishotel_product_checks_nightly' ) || wp_next_scheduled( 'fishotel_product_checks_sweep' ) ) {
			self::load();
			Checker::unschedule();
		}
	}

	/**
	 * Register the admin submenu.
	 */
	public static function register_menu() {
		self::load();
		add_submenu_page(
			'fishotel-misc',
			__( 'Product checks', 'fishotel-misc-plugin' ),
			__( 'Product checks', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin-activation callback: the nightly start.
	 */
	public static function activate() {
		self::load();
		Checker::ensure_nightly();
	}

	/**
	 * Plugin-deactivation callback: no sweep while the plugin is off. The
	 * flags stay (hidden meta; the next sweep corrects them).
	 */
	public static function deactivate() {
		self::load();
		Checker::unschedule();
	}
}
