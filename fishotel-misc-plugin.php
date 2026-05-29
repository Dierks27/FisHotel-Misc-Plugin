<?php
/**
 * Plugin Name: FisHotel Misc Plugin
 * Description: A modular container plugin with a dark theme admin interface for FisHotel tools.
 * Version:     0.33
 * Author:      FisHotel
 * Text Domain: fishotel-misc-plugin
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

define( 'FISHOTEL_MISC_VERSION', '0.33' );
define( 'FISHOTEL_MISC_FILE', __FILE__ );
define( 'FISHOTEL_MISC_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Autoload classes from the includes directory.
 */
spl_autoload_register( function ( $class ) {

	$prefix = 'FisHotel\\Misc\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );

	// Convert namespace separators and class name to file path.
	// FisHotel\Misc\Sections\Announcer\Announcer → sections/announcer/class-announcer.php
	$parts     = explode( '\\', $relative );
	$classname = array_pop( $parts );
	$filename  = 'class-' . str_replace( '_', '-', strtolower( $classname ) ) . '.php';

	$path = FISHOTEL_MISC_PATH . 'includes/';
	if ( ! empty( $parts ) ) {
		$path .= strtolower( implode( '/', $parts ) ) . '/';
	}
	$path .= $filename;

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

/**
 * Boot the plugin.
 */
function fishotel_misc_init() {
	define( 'FISHOTEL_MISC_URL', plugin_dir_url( FISHOTEL_MISC_FILE ) );

	$plugin = Plugin::get_instance();
	$plugin->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\fishotel_misc_init' );

/**
 * Run a lifecycle method on every enabled section that implements it.
 *
 * Resolves each enabled slug to its section class using the same
 * slug → namespace mapping as the Section Manager, then calls the
 * given static method (`activate` / `deactivate`) when present. This
 * is what lets DB-backed sections (e.g. Visitor Stats) create their
 * tables on plugin activation and tear down cron on deactivation,
 * without the core needing to know anything section-specific.
 *
 * @param string $method Lifecycle method name to invoke.
 */
function fishotel_misc_run_section_lifecycle( $method ) {
	$enabled = get_option( Section_Manager::OPTION_KEY, array() );

	foreach ( (array) $enabled as $slug ) {
		$class_file = FISHOTEL_MISC_PATH . 'includes/sections/' . $slug . '/class-' . $slug . '.php';

		if ( ! file_exists( $class_file ) ) {
			continue;
		}

		require_once $class_file;

		// 'visitor-stats' → 'Visitor_Stats'.
		$ns_segment = str_replace( ' ', '_', ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
		$class_name = 'FisHotel\\Misc\\Sections\\' . $ns_segment . '\\' . $ns_segment;

		if ( class_exists( $class_name ) && method_exists( $class_name, $method ) ) {
			$class_name::$method();
		}
	}
}

/**
 * Plugin activation — install any already-enabled DB-backed sections.
 *
 * Sections also install on their first boot() (idempotent via a stored
 * DB-version check), so this only matters for a deactivate/reactivate
 * cycle where the section was enabled beforehand.
 */
function fishotel_misc_activate() {
	if ( ! defined( 'FISHOTEL_MISC_URL' ) ) {
		define( 'FISHOTEL_MISC_URL', plugin_dir_url( FISHOTEL_MISC_FILE ) );
	}

	fishotel_misc_run_section_lifecycle( 'activate' );
}

/**
 * Plugin deactivation — let enabled sections clean up (e.g. unschedule
 * cron). Sections intentionally keep their data on deactivation.
 */
function fishotel_misc_deactivate() {
	fishotel_misc_run_section_lifecycle( 'deactivate' );
}

register_activation_hook( FISHOTEL_MISC_FILE, __NAMESPACE__ . '\\fishotel_misc_activate' );
register_deactivation_hook( FISHOTEL_MISC_FILE, __NAMESPACE__ . '\\fishotel_misc_deactivate' );
