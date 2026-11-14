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

			// Convert slug to a valid namespace segment: 'product-archive' → 'Product_Archive'.
			$ns_segment = str_replace( ' ', '_', ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
			$class_name = 'FisHotel\\Misc\\Sections\\' . $ns_segment . '\\' . $ns_segment;

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
				} elseif ( ! $this->sections[ $slug ]['enabled'] && method_exists( $class_name, 'boot_disabled' ) ) {
					// A section that left something behind in a visitor's
					// session cleans it up while it is switched off.
					$class_name::boot_disabled();
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
	 * Each click on a switch is a request of its own, and each request
	 * read the whole list when it started. Two switches clicked close
	 * together would both write the list as they read it, plus their own
	 * change — and the one that finished last wiped the other's, while
	 * both answered "saved". So the list is read again from the database
	 * right here, under a lock that makes a second click wait its turn.
	 *
	 * @param string $slug   Section slug.
	 * @param bool   $enable True to enable, false to disable.
	 * @return bool Whether the section is now in the asked state.
	 */
	public function set_section_state( $slug, $enable ) {
		if ( ! isset( $this->sections[ $slug ] ) ) {
			return false;
		}

		$locked        = $this->lock();
		$this->enabled = $this->stored_now();
		$before        = $this->enabled;

		if ( $enable ) {
			if ( ! in_array( $slug, $this->enabled, true ) ) {
				$this->enabled[] = $slug;
			}
		} else {
			$this->enabled = array_values( array_diff( $this->enabled, array( $slug ) ) );
		}

		// Already as asked (a click repeated, or another window got there first): nothing to write.
		$saved = $this->enabled === $before || update_option( self::OPTION_KEY, $this->enabled );

		if ( $locked ) {
			$this->unlock();
		}

		if ( $saved ) {
			$this->sections[ $slug ]['enabled'] = (bool) $enable;
		}

		return $saved;
	}

	/**
	 * The enabled list as the database holds it at this moment, not as
	 * this request read it when it started.
	 *
	 * @return array<string>
	 */
	private function stored_now() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( self::OPTION_KEY, 'options' );
		}

		$stored = get_option( self::OPTION_KEY, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * The name of the database lock, one per site.
	 *
	 * @return string
	 */
	private function lock_name() {
		global $wpdb;

		return 'fh_misc_sections_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( is_object( $wpdb ) && isset( $wpdb->prefix ) ? $wpdb->prefix : '' ) );
	}

	/**
	 * Take the lock, waiting up to five seconds for another click to
	 * finish. Without a database (or when it will not give the lock) the
	 * save goes ahead as it always did.
	 *
	 * @return bool Whether the lock is held.
	 */
	private function lock() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name(), 5 ) );
	}

	/**
	 * Give the lock back.
	 */
	private function unlock() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
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
	 * Let enabled sections register their own admin submenus, in the
	 * order Layout::menu_order() gives: related screens together (Today,
	 * then the fish room, customers, shipping, site), the sections that
	 * were live before 0.46 in the order they always had among
	 * themselves. Only the order changes — every slug stays as it is.
	 */
	public function register_section_menus() {
		foreach ( Layout::menu_order( array_keys( $this->sections ) ) as $slug ) {
			$section = $this->sections[ $slug ];
			if ( $section['enabled'] && method_exists( $section['class'], 'register_menu' ) ) {
				$section['class']::register_menu();
			}
		}
	}
}
