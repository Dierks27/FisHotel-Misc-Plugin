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
		add_action( 'admin_post_fishotel_perf_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Save settings from the admin form.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'fishotel-misc-plugin' ) );
		}

		check_admin_referer( 'fishotel_perf_settings' );

		$settings = array(
			'script_cleanup'       => ! empty( $_POST['script_cleanup'] ),
			'cache_headers'        => ! empty( $_POST['cache_headers'] ),
			'gzip_compression'     => ! empty( $_POST['gzip_compression'] ),
			'remove_query_strings' => ! empty( $_POST['remove_query_strings'] ),
		);

		update_option( Performance::OPTION_KEY, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'fishotel-misc-performance',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
