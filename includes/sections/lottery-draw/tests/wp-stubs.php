<?php
/**
 * Just enough WordPress to run the section's pure PHP headless.
 *
 * Only functions the importer and the store actually touch, and only the
 * behaviour they rely on. Nothing here talks to a database — the classes
 * under test are deliberately split so validation and normalisation can
 * be exercised without one.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
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

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_title( $text ) {
	$text = strtolower( trim( (string) $text ) );
	$text = preg_replace( '/[^a-z0-9_\-]+/', '-', $text );

	return trim( $text, '-' );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function wp_rand( $min, $max ) {
	return random_int( $min, $max );
}

function get_option( $key, $default = false ) {
	return $default;
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function add_query_arg( ...$args ) {
	return 'https://example.test/';
}

function get_permalink( $post ) {
	return 'https://example.test/draw/example/';
}

function get_posts( $args = array() ) {
	return array();
}

class WP_Error {

	private $code;
	private $message;

	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/lottery-draw/class-lottery-draw.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lottery-draw/class-store.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/lottery-draw/class-importer.php';
