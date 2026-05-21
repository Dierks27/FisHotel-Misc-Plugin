<?php
/**
 * Visitor Stats section — a lightweight, privacy-friendly pageview
 * tracker with a "live online now" counter, top pages / products /
 * referrers, and a WP admin dashboard widget.
 *
 * Tracking is done with a cache-safe JS beacon that POSTs to a public
 * REST endpoint, so it works behind page caching / Varnish. No IP and
 * no User-Agent are ever stored; the visitor is identified only by an
 * opaque random 32-char hex ID kept in the browser's localStorage.
 *
 * This is the first FisHotel section with its own custom DB table. The
 * install pattern here (DB-version-gated dbDelta + a plugin activation
 * hook in the bootstrap) is intended to be copied by future sections.
 *
 * @package FisHotel\Misc\Sections\Visitor_Stats
 */

namespace FisHotel\Misc\Sections\Visitor_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Class Visitor_Stats
 */
class Visitor_Stats {

	/**
	 * Section slug (matches the directory name).
	 *
	 * @var string
	 */
	const SECTION_SLUG = 'visitor-stats';

	/**
	 * Table name suffix appended to `$wpdb->prefix`.
	 *
	 * @var string
	 */
	const TABLE_SUFFIX = 'fishotel_pageviews';

	/**
	 * Schema version. Bump to trigger a dbDelta upgrade.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0';

	/**
	 * Option key storing the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fishotel_stats_db_version';

	/**
	 * Admin submenu slug for the dashboard page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-visitor-stats';

	/**
	 * REST namespace for the tracking endpoint.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'fishotel-misc/v1';

	/**
	 * REST route for the tracking endpoint.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/pageview';

	/**
	 * Number of days of pageview data to retain.
	 *
	 * @var int
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Window (minutes) used to count "online now" visitors.
	 *
	 * @var int
	 */
	const ONLINE_NOW_WINDOW_MINUTES = 5;

	/**
	 * Cron hook used to prune rows past the retention window.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'fishotel_stats_prune';

	/**
	 * Capability required to view stats and poll the live counter.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/visitor-stats/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/visitor-stats/';
	}

	/**
	 * Fully prefixed pageviews table name.
	 *
	 * Built from `$wpdb->prefix` (never user input), so it is safe to
	 * interpolate directly into queries — identifiers cannot be passed
	 * as prepared placeholders.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Visitor Stats', 'fishotel-misc-plugin' ),
			'description' => __( 'Track pageviews, see who\'s on the site right now, and view top pages, products, and referrers.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-chart-area',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		// Idempotent: only runs dbDelta when the stored version differs.
		self::install();

		// The plugin's autoloader assumes namespace segments match
		// directory names verbatim, but this directory uses a dash
		// (`visitor-stats`) while the namespace uses an underscore
		// (`Visitor_Stats`). Require classes explicitly to bypass that,
		// exactly as the Coming Soon section does.
		require_once self::path() . 'class-tracker.php';
		require_once self::path() . 'class-frontend.php';

		$tracker = new Tracker();
		$tracker->init();

		$frontend = new Frontend();
		$frontend->init();

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$admin = new Admin();
			$admin->init();
		}

		// Daily retention prune.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}

		add_action( self::CRON_HOOK, array( self::class, 'prune_old_rows' ) );
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Visitor Stats', 'fishotel-misc-plugin' ),
			__( 'Visitor Stats', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_dashboard' )
		);
	}

	/**
	 * Create / upgrade the pageviews table via dbDelta.
	 *
	 * Gated on the stored DB version so the dbDelta diff only runs when
	 * the schema actually changes. Safe to call on every boot().
	 */
	public static function install() {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: each column on its own line,
		// two spaces after PRIMARY KEY, KEY (not INDEX) with a name.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(500) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			visitor_id CHAR(32) NOT NULL,
			referrer_domain VARCHAR(255) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(20) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY visitor_id (visitor_id),
			KEY post_id (post_id),
			KEY post_type_created (post_type, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Plugin-activation callback (invoked from the bootstrap's
	 * register_activation_hook for enabled DB-backed sections).
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Plugin-deactivation callback. Unschedules the prune cron but
	 * deliberately keeps the table and its data — disabling the section
	 * should never silently destroy collected stats (same philosophy as
	 * Product Archive).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Delete pageview rows older than the retention window.
	 *
	 * Runs daily via WP-Cron. The interval is bound as an integer
	 * placeholder rather than concatenated into the SQL.
	 */
	public static function prune_old_rows() {
		global $wpdb;

		$table_name = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
				self::RETENTION_DAYS
			)
		);
	}
}
