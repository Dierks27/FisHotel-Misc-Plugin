<?php
/**
 * Packing admin — the page under FisHotel Tools (Bags · Sizes → bags ·
 * Boxes) and the assets it shares with the Packing box on the order
 * screen.
 *
 * The page only reads; every save is an AJAX call to a handler that
 * checks manage_woocommerce and a nonce (Settings, Fill). The views say
 * plainly what is not available: no WooCommerce, no bag sizes yet, no
 * list of sizes, where the boxes come from.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	const TABS = array( 'bags', 'sizes', 'boxes' );

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Enqueue the assets on the section page (the order screen asks for
	 * them itself when it adds the Packing box).
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function maybe_enqueue( $hook_suffix ) {
		unset( $hook_suffix );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, Packing::MENU_SLUG ) ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue the section's stylesheet and script (once).
	 */
	public static function enqueue_assets() {
		if ( ! function_exists( 'wp_enqueue_script' ) || wp_script_is( 'fishotel-packing-admin', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-packing-admin',
			Packing::url() . 'css/packing-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-packing-admin',
			Packing::url() . 'js/packing-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-packing-admin',
			'fhPacking',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'saving'   => __( 'Saving…', 'fishotel-misc-plugin' ),
					'error'    => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'counting' => __( 'Counting…', 'fishotel-misc-plugin' ),
					'working'  => __( 'Working…', 'fishotel-misc-plugin' ),
					'unsaved'  => __( 'Nothing was saved: another form on this page has changes that are not saved, and saving this one reloads the page. Save that one first (or reload the page to drop them).', 'fishotel-misc-plugin' ),
					/* translators: %d: number of variations set so far */
					'progress' => __( '%d set so far…', 'fishotel-misc-plugin' ),
					/* translators: 1: variations set, 2: variations that could not be saved */
					'done'     => __( 'Done — Bag size set on: %1$d · could not be saved: %2$d.', 'fishotel-misc-plugin' ),
					'stopped'  => __( 'Stopped — the last step did not get an answer. What was set so far stays set; run it again to carry on.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/**
	 * The URL of a tab of the page.
	 *
	 * @param string $tab bags|sizes|boxes.
	 * @return string
	 */
	public static function tab_url( $tab = 'bags' ) {
		$args = array( 'page' => Packing::MENU_SLUG );
		if ( 'bags' !== $tab && in_array( $tab, self::TABS, true ) ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render the page.
	 */
	public function render_page() {
		if ( ! current_user_can( Packing::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bags'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'bags';

		$settings    = Settings::get();
		$store       = new Store();
		$woocommerce = function_exists( 'wc_get_product' );
		$tabs        = array(
			'bags'  => __( 'Bags', 'fishotel-misc-plugin' ),
			'sizes' => __( 'Sizes → bags', 'fishotel-misc-plugin' ),
			'boxes' => __( 'Boxes', 'fishotel-misc-plugin' ),
		);

		// What each tab's view reads.
		$usage    = array();
		$standard = Size_List::none();
		$counts   = array(
			'total'    => 0,
			'with_bag' => 0,
		);
		$blocked  = '';
		$current  = array();
		$boxes    = array();

		if ( 'bags' === $tab ) {
			$usage = $store->bag_usage();
		} elseif ( 'sizes' === $tab ) {
			$standard = Size_List::standard();
			$counts   = $store->variation_counts();
			$blocked  = Fill::blocked( $settings, $standard );
		} else {
			$current = Boxes::current( $settings );
			$boxes   = Boxes::with_fits( $current['boxes'], $settings['fits'] );
		}

		include Packing::path() . 'views/page.php';
	}
}
