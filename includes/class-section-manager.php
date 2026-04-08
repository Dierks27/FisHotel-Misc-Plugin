<?php
/**
 * Section manager — registers, enables, and disables feature sections.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Section_Manager
 */
class Section_Manager {

	/**
	 * Option key used to persist enabled sections.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_misc_enabled_sections';

	/**
	 * Registered sections.
	 *
	 * @var array<string, array>
	 */
	private $sections = array();

	/**
	 * Sections that are currently enabled.
	 *
	 * @var array<string>
	 */
	private $enabled = array();

	/**
	 * Initialise the manager.
	 */
	public function init() {
		$this->enabled = get_option( self::OPTION_KEY, array() );

		$this->discover_sections();

		add_action( 'wp_ajax_fishotel_misc_toggle_section', array( $this, 'ajax_toggle_section' ) );
	}

	/**
	 * Discover and register sections from the sections directory.
	 */
	private function discover_sections() {
		$sections_dir = FISHOTEL_MISC_PATH . 'includes/sections/';

		if ( ! is_dir( $sections_dir ) ) {
			return;
		}

		foreach ( glob( $sections_dir . '*', GLOB_ONLYDIR ) as $dir ) {
			$slug       = basename( $dir );
			$class_file = $dir . '/class-' . $slug . '.php';

			if ( ! file_exists( $class_file ) ) {
				continue;
			}

			$class_name = 'FisHotel\\Misc\\Sections\\' . ucfirst( $slug ) . '\\' . ucfirst( $slug );

			if ( ! class_exists( $class_name ) ) {
				require_once $class_file;
			}

			if ( class_exists( $class_name ) && method_exists( $class_name, 'get_section_info' ) ) {
				$info = $class_name::get_section_info();

				$this->sections[ $slug ] = array(
					'name'        => $info['name'] ?? ucfirst( $slug ),
					'description' => $info['description'] ?? '',
					'icon'        => $info['icon'] ?? 'dashicons-admin-generic',
					'class'       => $class_name,
					'enabled'     => in_array( $slug, $this->enabled, true ),
				);

				// Boot the section if enabled.
				if ( $this->sections[ $slug ]['enabled'] && method_exists( $class_name, 'boot' ) ) {
					$class_name::boot();
				}
			}
		}
	}

	/**
	 * Get all registered sections.
	 *
	 * @return array
	 */
	public function get_registered_sections() {
		return $this->sections;
	}

	/**
	 * Check whether a section is enabled.
	 *
	 * @param string $slug Section slug.
	 * @return bool
	 */
	public function is_enabled( $slug ) {
		return in_array( $slug, $this->enabled, true );
	}

	/**
	 * Enable or disable a section and persist the state.
	 *
	 * @param string $slug   Section slug.
	 * @param bool   $enable True to enable, false to disable.
	 * @return bool Whether the operation succeeded.
	 */
	public function set_section_state( $slug, $enable ) {
		if ( ! isset( $this->sections[ $slug ] ) ) {
			return false;
		}

		if ( $enable ) {
			if ( ! in_array( $slug, $this->enabled, true ) ) {
				$this->enabled[] = $slug;
			}
		} else {
			$this->enabled = array_values( array_diff( $this->enabled, array( $slug ) ) );
		}

		$this->sections[ $slug ]['enabled'] = $enable;

		return update_option( self::OPTION_KEY, $this->enabled );
	}

	/**
	 * AJAX handler for toggling a section on or off.
	 */
	public function ajax_toggle_section() {
		check_ajax_referer( 'fishotel_misc_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$slug   = isset( $_POST['section'] ) ? sanitize_key( $_POST['section'] ) : '';
		$enable = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => 'Missing section slug.' ), 400 );
		}

		$result = $this->set_section_state( $slug, $enable );

		if ( $result ) {
			wp_send_json_success( array(
				'section' => $slug,
				'enabled' => $enable,
			) );
		}

		wp_send_json_error( array( 'message' => 'Could not update section state.' ), 500 );
	}

	/**
	 * Let enabled sections register their own admin submenus.
	 */
	public function register_section_menus() {
		foreach ( $this->sections as $slug => $section ) {
			if ( $section['enabled'] && method_exists( $section['class'], 'register_menu' ) ) {
				$section['class']::register_menu();
			}
		}
	}
}
