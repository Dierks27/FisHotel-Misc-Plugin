<?php
/**
 * Main plugin orchestrator.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Section manager instance.
	 *
	 * @var Section_Manager
	 */
	private $section_manager;

	/**
	 * Update checker instance.
	 *
	 * @var Update_Checker
	 */
	private $update_checker;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialise the plugin.
	 */
	public function init() {
		$this->section_manager = new Section_Manager();
		$this->section_manager->init();

		$this->update_checker = new Update_Checker();
		$this->update_checker->init();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menus' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
	}

	/**
	 * Register admin menus.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'FisHotel Tools', 'fishotel-misc-plugin' ),
			__( 'FisHotel Tools', 'fishotel-misc-plugin' ),
			'manage_options',
			'fishotel-misc',
			array( $this, 'render_dashboard' ),
			'dashicons-admin-tools',
			4
		);

		add_submenu_page(
			'fishotel-misc',
			__( 'Dashboard', 'fishotel-misc-plugin' ),
			__( 'Dashboard', 'fishotel-misc-plugin' ),
			'manage_options',
			'fishotel-misc',
			array( $this, 'render_dashboard' )
		);

		// Let sections add their own submenus.
		$this->section_manager->register_section_menus();
	}

	/**
	 * Enqueue admin CSS and JS on plugin pages only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'fishotel-misc' ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-misc-admin',
			FISHOTEL_MISC_URL . 'admin/css/admin-style.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-misc-admin',
			FISHOTEL_MISC_URL . 'admin/js/admin-script.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script( 'fishotel-misc-admin', 'fishotelMisc', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fishotel_misc_nonce' ),
		) );
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$sections = $this->section_manager->get_registered_sections();
		include FISHOTEL_MISC_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Get the section manager.
	 *
	 * @return Section_Manager
	 */
	public function get_section_manager() {
		return $this->section_manager;
	}
}
