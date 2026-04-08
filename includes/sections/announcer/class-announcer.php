<?php
/**
 * Announcer section — placeholder.
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
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Announcer', 'fishotel-misc-plugin' ),
			'description' => __( 'Broadcast announcements and notices across the site.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-megaphone',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		// Placeholder — feature logic will go here.
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
			'fishotel-misc-announcer',
			array( static::class, 'render_page' )
		);
	}

	/**
	 * Render the section admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		echo '<div class="wrap fishotel-misc-wrap">';
		echo '<h1>' . esc_html__( 'Announcer', 'fishotel-misc-plugin' ) . '</h1>';
		echo '<p>' . esc_html__( 'This section is under construction.', 'fishotel-misc-plugin' ) . '</p>';
		echo '</div>';
	}
}
