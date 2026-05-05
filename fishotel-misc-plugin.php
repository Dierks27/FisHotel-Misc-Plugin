<?php
/**
 * Plugin Name: FisHotel Misc Plugin
 * Description: A modular container plugin with a dark theme admin interface for FisHotel tools.
 * Version:     0.27
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

define( 'FISHOTEL_MISC_VERSION', '0.27' );
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
