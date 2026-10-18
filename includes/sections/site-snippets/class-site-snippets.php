<?php
/**
 * Snippets section — a home for the site's own small pieces of code (what
 * the Code Snippets plugin used to hold): one file per snippet in
 * snippets/, kept in GitHub with the rest of the plugin, switched on or
 * off on a FisHotel Tools page, run the way Code Snippets ran them.
 *
 * WHY this exists: Code Snippets keeps code in the database, where it has
 * no history, no review and no way back, and its own updates have taken
 * the site down. A file in this repository is reviewed, versioned and
 * deployed like everything else, and the CI lints it on PHP 7.4 and 8.3
 * before it can reach the store.
 *
 * Architecture:
 *   - early-loader.php    included by the main plugin file at load time;
 *                         when this section is enabled it hooks the PHP
 *                         run at plugins_loaded:1 (the framework boots at
 *                         plugins_loaded:10 — too late).
 *   - class-header.php    PURE: a file's header comment → its fields, or
 *                         why it is broken.
 *   - class-symbols.php   PURE: syntax check (TOKEN_PARSE) and the
 *                         functions / classes a PHP file declares; clashes.
 *   - class-rules.php     PURE: switched on?, runs here?, order, output
 *                         slots, safe mode, the Code Snippets guard, the
 *                         [code_snippet id=N] mapping.
 *   - class-catalog.php   the folder as an index, cached in an autoloaded
 *                         option under a signature of names + sizes + mtimes.
 *   - class-runner.php    the include-inside-a-function + try/catch loop.
 *   - class-loader.php    the request: safe mode, guard query, run, errors.
 *   - class-output.php    CSS / JS / HTML printing and the shortcodes.
 *   - class-admin.php     the page, the AJAX switch, the notices.
 *
 * Snippets written in wp-admin (the editor — FISHOTEL_SNIPPETS_EDITORS
 * only), stored in custom tables and run beside the files:
 *   - class-db-snippets.php   the active option and the merge with the files.
 *   - class-db-store.php      the tables (the only $wpdb file of the editor).
 *   - class-editor-rules.php  PURE: who may write, validation, summaries.
 *   - class-export.php        PURE: a database snippet as a snippet file.
 *   - class-editor.php        the save pipeline (store as a parameter).
 *   - class-admin-editor.php  My snippets / Log tabs, admin-post + AJAX.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Site_Snippets
 */
class Site_Snippets {

	/**
	 * Admin submenu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-snippets';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return __DIR__ . '/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/site-snippets/';
	}

	/**
	 * Section metadata for the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Snippets', 'fishotel-misc-plugin' ),
			'description' => __( 'The site\'s own code snippets as files in GitHub, switched on or off here and run the way Code Snippets ran them — with safe mode, a duplicate guard while Code Snippets is still installed, and clash checks. Plus an editor for snippets written in wp-admin (only for users named in wp-config), with history, roll back, an audit log and export to GitHub.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-editor-code',
		);
	}

	/**
	 * Load the classes the request path needs (no side effects).
	 */
	public static function load() {
		require_once __DIR__ . '/class-header.php';
		require_once __DIR__ . '/class-symbols.php';
		require_once __DIR__ . '/class-rules.php';
		require_once __DIR__ . '/class-catalog.php';
		require_once __DIR__ . '/class-runner.php';
		require_once __DIR__ . '/class-loader.php';
		require_once __DIR__ . '/class-output.php';
		require_once __DIR__ . '/class-db-snippets.php';
	}

	/**
	 * Load the editor's classes (admin side, activation).
	 */
	public static function load_editor() {
		self::load();
		require_once __DIR__ . '/class-db-store.php';
		require_once __DIR__ . '/class-editor-rules.php';
		require_once __DIR__ . '/class-export.php';
		require_once __DIR__ . '/class-editor.php';
		require_once __DIR__ . '/class-admin.php';
		require_once __DIR__ . '/class-admin-editor.php';
	}

	/**
	 * Boot (plugins_loaded:10). The PHP snippets already ran at priority 1
	 * through the early loader; this adds the CSS / JS / HTML output, the
	 * shortcodes and the admin side. The editor's tables are installed on
	 * the first boot (an autoloaded version option gates it: no query, no
	 * SHOW TABLES, no dbDelta on any later request).
	 */
	public static function boot() {
		self::load();

		require_once __DIR__ . '/class-db-store.php';
		try {
			Db_Store::maybe_install( is_admin() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		// Enabled in the dashboard during this request's lifetime without
		// the early loader having seen it: run_php() is a no-op once it ran.
		if ( ! has_action( 'plugins_loaded', array( Loader::class, 'flush_log' ) ) ) {
			add_action( 'plugins_loaded', array( Loader::class, 'flush_log' ), 20 );
		}

		try {
			Output::init();
		} catch ( \Throwable $e ) {
			Loader::record_error( '', $e );
		}

		if ( is_admin() ) {
			self::load_editor();

			$admin = new Admin();
			$admin->init();

			$editor = new Admin_Editor();
			$editor->init();
		}
	}

	/**
	 * Register the admin submenu.
	 */
	public static function register_menu() {
		self::load_editor();

		add_submenu_page(
			'fishotel-misc',
			__( 'Snippets', 'fishotel-misc-plugin' ),
			__( 'Snippets', 'fishotel-misc-plugin' ),
			Loader::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin activation: build the index once; install the editor's tables
	 * (forced past the version gate, so a dropped table comes back).
	 */
	public static function activate() {
		self::load();
		try {
			Catalog::get( null, true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		require_once __DIR__ . '/class-db-store.php';
		try {
			Db_Store::install( true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Plugin deactivation: drop the cached index (the on/off choices, the
	 * files, the editor's tables and its active option are kept).
	 */
	public static function deactivate() {
		self::load();
		Catalog::forget();
	}
}
