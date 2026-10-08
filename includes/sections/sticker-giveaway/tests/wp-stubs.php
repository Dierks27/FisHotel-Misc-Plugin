<?php
/**
 * Just enough WordPress to load the Sticker Giveaway rules headless.
 *
 * Rules is pure by design — it touches no WordPress function at all — so
 * the only things needed here are the constants the files guard on and
 * the translation helpers the Settings defaults file may call. Nothing
 * here talks to WooCommerce or a database.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

define( 'ABSPATH', true );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === $number ? $single : $plural;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function get_option( $key, $default = false ) {
	return $default;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-sticker-giveaway.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-settings.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-rules.php';
