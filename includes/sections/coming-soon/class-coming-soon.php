<?php
/**
 * Coming Soon section — display variable WooCommerce products as
 * "Coming Soon" until a release datetime, with a live countdown,
 * server-side purchase blocking, and shop-loop priority sorting.
 *
 * Architecture: no cron, no scheduled state changes. The release
 * timestamp lives in `_fh_release_datetime` on the parent product
 * and is compared against `time()` on every request. Future =
 * Coming Soon (purchase blocked, ribbon + countdown shown). Past
 * or missing = product behaves 100% normally.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

namespace FisHotel\Misc\Sections\Coming_Soon;

defined( 'ABSPATH' ) || exit;

/**
 * Class Coming_Soon
 */
class Coming_Soon {

	/**
	 * Parent-product meta key holding the UNIX release timestamp.
	 *
	 * @var string
	 */
	const META_KEY = '_fh_release_datetime';

	/**
	 * Parent-product meta flag tracking whether the release-transition
	 * action has been fired for the current release window.
	 *
	 * @var string
	 */
	const DISPATCHED_KEY = '_fh_release_dispatched';

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_coming_soon_settings';

	/**
	 * Admin submenu slug for the settings page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-coming-soon';

	/**
	 * Capability required for the settings page.
	 *
	 * @var string
	 */
	const SETTINGS_CAP = 'manage_woocommerce';

	/**
	 * Capability required to edit the coming-soon meta on a product.
	 *
	 * @var string
	 */
	const PRODUCT_CAP = 'edit_product';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/coming-soon/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/coming-soon/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Coming Soon', 'fishotel-misc-plugin' ),
			'description' => __( 'Mark variable products as "Coming Soon" with a release datetime, live countdown, and server-side purchase blocking.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-clock',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 *
	 * Gated on WooCommerce being available — without WC, the
	 * frontend filters and product hooks have nothing to attach to.
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// The plugin's PSR-style autoloader assumes namespace segments
		// match directory names verbatim, but our directory uses a
		// dash (`coming-soon`) while the namespace uses an underscore
		// (`Coming_Soon`). Require classes explicitly to bypass that.
		require_once self::path() . 'class-settings.php';
		require_once self::path() . 'class-frontend.php';

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

		add_submenu_page(
			'fishotel-misc',
			__( 'Coming Soon', 'fishotel-misc-plugin' ),
			__( 'Coming Soon', 'fishotel-misc-plugin' ),
			self::SETTINGS_CAP,
			self::MENU_SLUG,
			array( new Settings(), 'render_page' )
		);
	}

	/**
	 * Read the saved release timestamp for a product (or its parent).
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return int Unix timestamp (0 if none).
	 */
	public static function get_release_timestamp( $product ) {
		$parent_id = self::get_parent_id( $product );
		if ( ! $parent_id ) {
			return 0;
		}
		return absint( get_post_meta( $parent_id, self::META_KEY, true ) );
	}

	/**
	 * Whether a product (or its parent) is currently in Coming Soon mode.
	 *
	 * Side-effect: the first time we observe that a release timestamp
	 * has passed (and the dispatched flag is unset), fire
	 * `fh_coming_soon_released` and set the flag. This makes
	 * second-precision detection a free byproduct of any render.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return bool
	 */
	public static function is_coming_soon( $product ) {
		$parent_id = self::get_parent_id( $product );
		if ( ! $parent_id ) {
			return false;
		}

		$release_ts = absint( get_post_meta( $parent_id, self::META_KEY, true ) );

		if ( ! $release_ts ) {
			return false;
		}

		if ( $release_ts <= time() ) {
			self::maybe_dispatch_release( $parent_id );
			return false;
		}

		return true;
	}

	/**
	 * Resolve the parent product ID for a product or product ID.
	 *
	 * Variations defer to their parent — the meta lives there.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return int Parent product ID, or 0 if not a product.
	 */
	public static function get_parent_id( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}

		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}

		if ( $product->is_type( 'variation' ) ) {
			return (int) $product->get_parent_id();
		}

		return (int) $product->get_id();
	}

	/**
	 * Fire `fh_coming_soon_released` once per release window.
	 *
	 * @param int $parent_id Parent product ID.
	 */
	public static function maybe_dispatch_release( $parent_id ) {
		$dispatched = get_post_meta( $parent_id, self::DISPATCHED_KEY, true );
		if ( '1' === $dispatched ) {
			return;
		}

		update_post_meta( $parent_id, self::DISPATCHED_KEY, '1' );

		/**
		 * Fires when a Coming Soon product transitions to live.
		 *
		 * @param int $product_id Parent product ID.
		 */
		do_action( 'fh_coming_soon_released', $parent_id );
	}
}
