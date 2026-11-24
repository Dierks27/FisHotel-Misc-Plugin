<?php
/**
 * Edit screens section — the product and order edit screens of wp-admin,
 * tidied: "things that should be filled out up towards the top, the
 * optional things a bit lower and the non essentials at the bottom."
 *
 * One place owns the layout; each box's owner (WooCommerce, the theme,
 * the Batch plugin, ShipTracker, Yoast, the other sections) only decides
 * whether its box has something to say. No box's code is touched: boxes
 * are placed, closed and hidden by their ids through what WordPress keeps
 * for each person who drags boxes (their user meta), renamed by title
 * only, and hidden by product kind on the page only — a hidden box is
 * still printed and posted, so nothing saves differently.
 *
 * Architecture:
 *   - class-layouts.php         PURE: the FisHotel layouts (box order by
 *                               column, closed, hidden) of the product
 *                               screen and the two order screens.
 *   - class-switcher.php        the once-per-person switch: backup, the
 *                               layout, the version; undo, reset, given
 *                               back while the section is off.
 *   - class-user-store.php      the only file that writes user meta.
 *   - class-tidy.php            when the switch runs; the notice; the
 *                               Screen Options links; their admin-post.
 *   - class-kinds.php           PURE: live fish / medication & food / dry
 *                               good / invoice / no category yet.
 *   - class-essentials.php      the Fish essentials box (scientific name;
 *                               Packing's two fields; Still missing).
 *   - class-product-screen.php  the area under the title, the names, the
 *                               product data tabs, the kind on the page.
 *   - class-order-screen.php    an order folded into a combined box: the
 *                               boxes that only point there, closed.
 *
 * Nothing runs outside wp-admin, and nothing at all for a visitor.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Edit_Screens
 */
class Edit_Screens {

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
		return FISHOTEL_MISC_URL . 'includes/sections/edit-screens/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Edit screens', 'fishotel-misc-plugin' ),
			'description' => __( 'The product and order edit screens tidied: what must be filled first at the top (a Fish essentials box under the title with the scientific name, Livestock and the 3rd-bag tick), the optional below, the rarely used closed at the bottom; fish boxes hidden on dry goods. Each person\'s screen is tidied once, with "Put my old layout back"; their own drags are kept after that.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-layout',
		);
	}

	/**
	 * Load the classes (no side effects).
	 */
	public static function load() {
		require_once __DIR__ . '/class-layouts.php';
		require_once __DIR__ . '/class-switcher.php';
		require_once __DIR__ . '/class-user-store.php';
		require_once __DIR__ . '/class-tidy.php';
		require_once __DIR__ . '/class-kinds.php';
		require_once __DIR__ . '/class-essentials.php';
		require_once __DIR__ . '/class-product-screen.php';
		require_once __DIR__ . '/class-order-screen.php';
	}

	/**
	 * Boot the section when it is enabled — in wp-admin only.
	 */
	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		self::load();

		( new Tidy() )->init();
		( new Product_Screen() )->init();
		( new Order_Screen() )->init();
	}

	/**
	 * While the section is switched off: a tidied person gets their own
	 * layout back the next time they open that screen.
	 */
	public static function boot_disabled() {
		if ( ! is_admin() ) {
			return;
		}
		self::load();
		add_action( 'add_meta_boxes', array( self::class, 'give_back' ), 50, 1 );
	}

	/**
	 * add_meta_boxes while the section is off: the person's own layout of
	 * this screen back, once.
	 *
	 * @param mixed $screen Post type (legacy) or screen id (HPOS).
	 */
	public static function give_back( $screen = '' ) {
		$screen = Tidy::screen_id( $screen );
		if ( '' !== $screen ) {
			( new Switcher( new User_Store() ) )->restore_off( get_current_user_id(), $screen );
		}
	}

	/**
	 * The stylesheet and the script (once).
	 */
	public static function enqueue() {
		if ( ! function_exists( 'wp_enqueue_script' ) || wp_script_is( 'fishotel-edit-screens', 'enqueued' ) ) {
			return;
		}
		$version = defined( 'FISHOTEL_MISC_VERSION' ) ? FISHOTEL_MISC_VERSION : '0';
		wp_enqueue_style( 'fishotel-edit-screens', self::url() . 'css/edit-screens.css', array(), $version );
		wp_enqueue_script( 'fishotel-edit-screens', self::url() . 'js/edit-screens.js', array( 'jquery' ), $version, true );
	}

	/**
	 * Plugin-activation callback: nothing to install.
	 */
	public static function activate() {
		// Nothing: the layouts are written per person, when they open a screen.
	}

	/**
	 * Plugin-deactivation callback: each person's layout is theirs and
	 * stays — an ordinary layout, which WordPress draws without this
	 * section.
	 */
	public static function deactivate() {
		// Nothing to tear down.
	}
}
