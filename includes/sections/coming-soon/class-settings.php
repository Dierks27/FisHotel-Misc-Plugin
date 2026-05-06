<?php
/**
 * Coming Soon settings — storage, defaults, page render, AJAX save.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

namespace FisHotel\Misc\Sections\Coming_Soon;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'notify_me_url'         => '/product/special-order/',
		'ribbon_text'           => 'Coming Soon',
		'show_countdown_loop'   => true,
		'show_countdown_single' => true,
		'auto_reload'           => true,
		'reload_jitter'         => 2,
		'sort_first'            => true,
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_coming_soon_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Get the current settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		return wp_parse_args(
			get_option( Coming_Soon::OPTION_KEY, array() ),
			self::DEFAULTS
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_value( $key ) {
		$settings = self::get();
		return $settings[ $key ] ?? null;
	}

	/**
	 * AJAX handler — save settings.
	 */
	public function save_settings() {
		if ( ! current_user_can( Coming_Soon::SETTINGS_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( 'fishotel_coming_soon_settings', '_wpnonce' );

		$notify_url = isset( $_POST['notify_me_url'] ) ? esc_url_raw( wp_unslash( $_POST['notify_me_url'] ) ) : '';
		if ( '' === $notify_url ) {
			$notify_url = self::DEFAULTS['notify_me_url'];
		}

		$ribbon = isset( $_POST['ribbon_text'] ) ? sanitize_text_field( wp_unslash( $_POST['ribbon_text'] ) ) : '';
		if ( '' === $ribbon ) {
			$ribbon = self::DEFAULTS['ribbon_text'];
		}

		$jitter = isset( $_POST['reload_jitter'] ) ? absint( $_POST['reload_jitter'] ) : 0;
		if ( $jitter > 60 ) {
			$jitter = 60;
		}

		$settings = array(
			'notify_me_url'         => $notify_url,
			'ribbon_text'           => $ribbon,
			'show_countdown_loop'   => ! empty( $_POST['show_countdown_loop'] ),
			'show_countdown_single' => ! empty( $_POST['show_countdown_single'] ),
			'auto_reload'           => ! empty( $_POST['auto_reload'] ),
			'reload_jitter'         => $jitter,
			'sort_first'            => ! empty( $_POST['sort_first'] ),
		);

		update_option( Coming_Soon::OPTION_KEY, $settings );

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'fishotel-misc-plugin' ) ) );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( Coming_Soon::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$settings = self::get();

		include Coming_Soon::path() . 'views/settings.php';
	}
}
