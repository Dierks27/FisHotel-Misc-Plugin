<?php
/**
 * Dark admin section — every wp-admin screen in the FisHotel Tools dark
 * look, for every person who has not switched it off on their own
 * profile.
 *
 * Switched off (FisHotel Tools → Dashboard), wp-admin looks exactly as
 * it did: nothing here is loaded, and the per-person ticks stay saved for
 * the day it is switched back on.
 *
 *   class-rules.php      pure: whose switch says what, which screens turn
 *                        dark, which colour scheme, the body class
 *   class-palette.php    pure: the colours and the contrast sums
 *   class-hooks.php      WordPress: scheme, body class, files, profile tick
 *   css/scheme.css       the "FisHotel dark" admin colour scheme (menu, bar)
 *   css/dark-admin.css   the one layer of variables that darkens the rest
 *   css/editor-content.css  the classic editor's writing area
 *   js/dark-admin.js     the readability guard for text other plugins
 *                        coloured inline
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

namespace FisHotel\Misc\Sections\Dark_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dark_Admin
 */
class Dark_Admin {

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/dark-admin/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/dark-admin/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Dark admin', 'fishotel-misc-plugin' ),
			'description' => __( 'Every wp-admin screen in the FisHotel Tools dark look; each person can switch it off on their profile.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-admin-appearance',
		);
	}

	/**
	 * Load the class files.
	 */
	private static function load() {
		require_once self::path() . 'class-rules.php';
		require_once self::path() . 'class-palette.php';
		require_once self::path() . 'class-hooks.php';
	}

	/**
	 * Boot when switched on — wp-admin requests only.
	 */
	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		self::load();
		Hooks::init();
	}
}
