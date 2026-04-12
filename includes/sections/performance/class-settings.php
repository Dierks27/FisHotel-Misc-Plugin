<?php
/**
 * Performance settings admin page.
 *
 * @package FisHotel\Misc\Sections\Performance
 */

namespace FisHotel\Misc\Sections\Performance;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_perf_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Save settings from the admin form.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		check_ajax_referer( 'fishotel_perf_settings', '_wpnonce' );

		try {
			$settings = array(
				'script_cleanup'       => ! empty( $_POST['script_cleanup'] ),
				'cache_headers'        => ! empty( $_POST['cache_headers'] ),
				'gzip_compression'     => ! empty( $_POST['gzip_compression'] ),
				'remove_query_strings' => ! empty( $_POST['remove_query_strings'] ),
				'combine_assets'       => ! empty( $_POST['combine_assets'] ),
			);

			update_option( Performance::OPTION_KEY, $settings );

			// Pre-create the cache directory when the combiner is enabled.
			if ( ! empty( $settings['combine_assets'] ) ) {
				$cache_dir = WP_CONTENT_DIR . '/cache/fishotel-perf/';
				if ( ! is_dir( $cache_dir ) ) {
					wp_mkdir_p( $cache_dir );
				}
			}

			wp_send_json_success( array( 'message' => __( 'Settings saved.', 'fishotel-misc-plugin' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$settings = wp_parse_args(
			get_option( Performance::OPTION_KEY, array() ),
			Performance::DEFAULTS
		);

		include Performance::path() . 'views/settings.php';
	}
}
