<?php
/**
 * Med listings section — a one-time cleanup that turns every medication
 * and medicated-food listing into a plain product listing: what it is,
 * what is in it, its sizes and price, and nothing about what it treats.
 *
 * WHY: the FDA's warning letters to aquarium-medication sellers quote
 * the seller's own website — "treats ich", "kills flukes", dosing, a link
 * to a dosing guide — as the proof that a product is a drug. The model
 * listing is a name, the ingredients, a size and a price. The products
 * stay for sale.
 *
 * FisHotel Tools → Med listings, one page, six steps the owner presses
 * through: 1 backup, 2 find, 3 review (a dry run: nothing changes),
 * 4 apply (and undo), 5 categories (with 301s from the old addresses),
 * 6 verify (caches cleared, every page opened and read). Nothing runs by
 * itself and nothing changes until Apply is pressed.
 *
 *   class-words.php      the word lists (settings) and the plain lines
 *   class-finder.php     finds the words; highlights; cleans a line (pure)
 *   class-composer.php   writes the plain listing from the facts (pure)
 *   class-proposals.php  what to change on one product, what to flag (pure)
 *   class-inventory.php  which products; a snapshot of each
 *   class-fields.php     reads / writes one field through WordPress
 *   class-ledger.php     Apply, Undo, and the record of old values
 *   class-terms.php      the Categories step
 *   class-redirects.php  the 301 list for old category addresses
 *   class-verify.php     cache clearing and the page check
 *   class-admin.php      the page, its buttons, the downloads
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Med_Listings
 */
class Med_Listings {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-med-listings';

	/**
	 * Who may use it (the people who edit products).
	 *
	 * @var string
	 */
	const CAP = 'manage_woocommerce';

	/**
	 * Section path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/med-listings/';
	}

	/**
	 * Section URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/med-listings/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Med listings', 'fishotel-misc-plugin' ),
			'description' => __( 'A one-time cleanup that makes every medication and medicated-food listing a plain product listing, with undo and 301s for changed categories.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-clipboard',
		);
	}

	/**
	 * Load the classes.
	 */
	public static function load() {
		foreach ( array( 'words', 'finder', 'composer', 'proposals', 'fields', 'ledger', 'inventory', 'terms', 'redirects', 'verify' ) as $f ) {
			require_once self::path() . 'class-' . $f . '.php';
		}
	}

	/**
	 * Boot when switched on: the page, and the redirects.
	 */
	public static function boot() {
		self::load();
		Redirects::register();
		if ( is_admin() && class_exists( 'WooCommerce' ) ) {
			require_once self::path() . 'class-admin.php';
			( new Admin() )->init();
		}
	}

	/**
	 * While switched off: the old category addresses keep sending
	 * visitors on (they live on in search engines long after the cleanup
	 * is done). Nothing else runs.
	 */
	public static function boot_disabled() {
		require_once self::path() . 'class-redirects.php';
		Redirects::register();
	}

	/**
	 * The page under FisHotel Tools.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::load();
		require_once self::path() . 'class-admin.php';
		add_submenu_page(
			'fishotel-misc',
			__( 'Med listings', 'fishotel-misc-plugin' ),
			__( 'Med listings', 'fishotel-misc-plugin' ),
			self::CAP,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}
}
