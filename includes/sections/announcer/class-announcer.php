<?php
/**
 * Announcer section — notification bars, banners, and announcement messages.
 *
 * @package FisHotel\Misc\Sections\Announcer
 */

namespace FisHotel\Misc\Sections\Announcer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Announcer
 */
class Announcer {

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/announcer/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/announcer/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Announcer', 'fishotel-misc-plugin' ),
			'description' => __( 'Display notification bars, announcement banners, and promotional messages across your site.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-megaphone',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		require_once self::path() . 'class-post-type.php';
		require_once self::path() . 'class-frontend.php';

		$post_type = new Post_Type();
		$post_type->init();

		if ( ! is_admin() ) {
			$frontend = new Frontend();
			$frontend->init();
		}
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		add_submenu_page(
			'fishotel-misc',
			__( 'Announcer', 'fishotel-misc-plugin' ),
			__( 'Announcer', 'fishotel-misc-plugin' ),
			'manage_options',
			'edit.php?post_type=fishotel_announce'
		);
	}
}
