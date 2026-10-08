<?php
/**
 * Performance Optimization section — script cleanup, browser caching,
 * gzip compression, and query-string removal.
 *
 * @package FisHotel\Misc\Sections\Performance
 */

namespace FisHotel\Misc\Sections\Performance;

defined( 'ABSPATH' ) || exit;

/**
 * Class Performance
 */
class Performance {

	/**
	 * Option key for performance settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_perf_settings';

	/**
	 * Default feature states.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'script_cleanup'       => true,
		// Off by default: Cloudways nginx / Varnish already gzip, the theme's
		// own cache-buster appends ?fhv= at priority 999 (so stripping ?ver=
		// gains nothing), and public Cache-Control headers need a deliberate
		// opt-in on a store whose header carries per-visitor cart state.
		// Only applies when the option has never been saved.
		'cache_headers'        => false,
		'gzip_compression'     => false,
		'remove_query_strings' => false,
		'combine_assets'       => false,
	);

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/performance/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/performance/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Performance', 'fishotel-misc-plugin' ),
			'description' => __( 'Optimize page speed with script cleanup, browser caching, gzip compression, and query-string removal.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-performance',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		$optimizer = new Optimizer();
		$optimizer->init();

		if ( is_admin() ) {
			$settings = new Settings();
			$settings->init();
		}
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		add_submenu_page(
			'fishotel-misc',
			__( 'Performance', 'fishotel-misc-plugin' ),
			__( 'Performance', 'fishotel-misc-plugin' ),
			'manage_options',
			'fishotel-misc-performance',
			array( new Settings(), 'render_page' )
		);
	}
}
