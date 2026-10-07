<?php
/**
 * Sticker Giveaway section — a free FisHotel sticker with every fish
 * order, picked by the customer at checkout, plus a cheap add-on upsell.
 *
 * WHY this exists: Jeff has three sticker designs and wants to give one
 * away with every fish order without doing it by hand. The sticker is a
 * real WooCommerce product so it shows up as a line item on the order,
 * the packing slip and the customer email — the person packing the box
 * sees exactly which design to drop in. The picker lives inside the
 * checkout <form> so the choice rides along with WooCommerce's own
 * update_order_review AJAX; no custom endpoints are needed on checkout.
 *
 * Architecture:
 *   - class-rules.php     pure eligibility / clamping / pricing — no WP
 *                         calls, unit-tested headless.
 *   - class-cart.php      keeps the cart in step with the picker: adds and
 *                         removes sticker lines (flagged with cart item
 *                         data `fh_sticker` = free|extra), zeroes the free
 *                         line's price, keeps stickers out of the shipping
 *                         package so table rates never change.
 *   - class-frontend.php  renders the picker card and the tiny JS bridge.
 *   - class-settings.php  option storage, defaults, AJAX save.
 *   - class-admin.php     settings page under FisHotel Tools.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sticker_Giveaway
 */
class Sticker_Giveaway {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_sticker_giveaway_settings';

	/**
	 * Admin submenu slug for the settings page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-sticker-giveaway';

	/**
	 * Capability required for the settings page and AJAX save.
	 *
	 * @var string
	 */
	const SETTINGS_CAP = 'manage_woocommerce';

	/**
	 * Cart item data key that marks a line as a picker-managed sticker.
	 * Its presence (with a distinct value) also makes the cart item key
	 * differ from a normal purchase of the same product.
	 *
	 * @var string
	 */
	const ITEM_KEY = 'fh_sticker';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/sticker-giveaway/';
	}

	/**
	 * A price as plain text ("$1.00"), safe to pass through esc_html().
	 *
	 * wc_price() returns markup with the currency symbol as an HTML entity
	 * (`&#36;`); escaping that again shows the entity literally.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function plain_price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The currency symbol as plain text, safe to pass through esc_html().
	 *
	 * @return string
	 */
	public static function plain_symbol() {
		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Sticker Giveaway', 'fishotel-misc-plugin' ),
			'description' => __( 'Let customers pick a free FisHotel sticker at checkout on fish orders, with an optional add-on sticker upsell.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-tag',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 *
	 * Gated on WooCommerce — every hook here is a cart or checkout hook.
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The plugin autoloader maps namespace segments to directories
		// verbatim; our directory is dashed (`sticker-giveaway`) while the
		// namespace is underscored, so require the files explicitly.
		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-rules.php';
		require_once self::path() . 'class-cart.php';
		require_once self::path() . 'class-frontend.php';

		$cart = new Cart();
		$cart->init();

		$frontend = new Frontend();
		$frontend->init();

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$settings = new Settings();
			$settings->init();

			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-rules.php';
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Sticker Giveaway', 'fishotel-misc-plugin' ),
			__( 'Sticker Giveaway', 'fishotel-misc-plugin' ),
			self::SETTINGS_CAP,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}
}
