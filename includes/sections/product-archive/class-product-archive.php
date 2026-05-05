<?php
/**
 * Product Archive section — archive WooCommerce products via custom post status.
 *
 * Lets administrators "archive" products to hide them from the shop,
 * search, related products, and the WC REST API — without deleting.
 * Order history continues to display archived products correctly,
 * and admin product lookup (e.g. when adding line items to a manual
 * order) still finds them.
 *
 * Implementation uses a custom post status (fishotel_archived) rather
 * than a meta flag so that WordPress's native non-public-status
 * exclusions and admin status filters apply automatically.
 *
 * Plugin deactivation note: archived products keep their post_status
 * value of `fishotel_archived` while this section is disabled. They
 * simply will not appear in admin status filters until the section is
 * re-enabled. We intentionally do NOT mass-revert on uninstall, since
 * that would dump potentially hundreds of products back into the live
 * shop unexpectedly.
 *
 * @package FisHotel\Misc\Sections\Product_Archive
 */

namespace FisHotel\Misc\Sections\Product_Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Archive
 */
class Product_Archive {

	/**
	 * Custom post status slug used for archived products.
	 *
	 * @var string
	 */
	const STATUS = 'fishotel_archived';

	/**
	 * Admin submenu slug for the dashboard page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-product-archive';

	/**
	 * Capability required to archive / unarchive products and view
	 * the dashboard page. Matches WooCommerce's own admin gating.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Nonce action prefix for single-product row actions.
	 *
	 * @var string
	 */
	const ROW_NONCE = 'fishotel_pa_row';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/product-archive/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/product-archive/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Product Archive', 'fishotel-misc-plugin' ),
			'description' => __( 'Archive WooCommerce products to hide them from the shop, search, and customer-facing surfaces — without deleting. Preserves order history and admin lookup.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-archive',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		require_once self::path() . 'class-post-status.php';

		$status = new Post_Status();
		$status->init();

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';
			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		add_submenu_page(
			'fishotel-misc',
			__( 'Product Archive', 'fishotel-misc-plugin' ),
			__( 'Product Archive', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_dashboard' )
		);
	}
}
