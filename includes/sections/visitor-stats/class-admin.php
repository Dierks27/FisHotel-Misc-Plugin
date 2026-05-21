<?php
/**
 * Visitor Stats admin — the dashboard page + its queries, the AJAX poll
 * powering the live "online now" counter, and the WP dashboard widget.
 *
 * @package FisHotel\Misc\Sections\Visitor_Stats
 */

namespace FisHotel\Misc\Sections\Visitor_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action shared by the AJAX poll and the localized scripts.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_stats_nonce';

	/**
	 * AJAX action name for the live online-now poll.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'fishotel_stats_online_now';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_online_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/* ============================================================== */
	/*  Queries                                                        */
	/* ============================================================== */

	/**
	 * Distinct visitors seen within the online-now window.
	 *
	 * @return int
	 */
	public static function get_online_now() {
		global $wpdb;

		$table = Visitor_Stats::table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT visitor_id) FROM {$table} WHERE created_at > DATE_SUB( NOW(), INTERVAL %d MINUTE )",
				Visitor_Stats::ONLINE_NOW_WINDOW_MINUTES
			)
		);
	}

	/**
	 * Pageviews recorded today (site timezone, via created_at).
	 *
	 * @return int
	 */
	public static function get_pageviews_today() {
		global $wpdb;

		$table = Visitor_Stats::table_name();

		// No variables to bind — the table name comes from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()" );
	}

	/**
	 * Top pages by views over the last N days.
	 *
	 * @param int $days  Day window.
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public static function get_top_pages( $days, $limit = 10 ) {
		global $wpdb;

		$table = Visitor_Stats::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, MAX(title) AS title, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS unique_views
				FROM {$table}
				WHERE created_at > DATE_SUB( NOW(), INTERVAL %d DAY )
				GROUP BY url
				ORDER BY views DESC
				LIMIT %d",
				$days,
				$limit
			)
		);
	}

	/**
	 * Top products by views over the last N days.
	 *
	 * @param int $days  Day window.
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public static function get_top_products( $days, $limit = 10 ) {
		global $wpdb;

		$table = Visitor_Stats::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, MAX(url) AS url, MAX(title) AS title, COUNT(*) AS views
				FROM {$table}
				WHERE created_at > DATE_SUB( NOW(), INTERVAL %d DAY )
					AND post_type = 'product'
					AND post_id > 0
				GROUP BY post_id
				ORDER BY views DESC
				LIMIT %d",
				$days,
				$limit
			)
		);
	}

	/**
	 * Top referrer domains over the last N days.
	 *
	 * @param int $days  Day window.
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public static function get_top_referrers( $days, $limit = 10 ) {
		global $wpdb;

		$table = Visitor_Stats::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_domain, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS unique_visitors
				FROM {$table}
				WHERE created_at > DATE_SUB( NOW(), INTERVAL %d DAY )
					AND referrer_domain != ''
				GROUP BY referrer_domain
				ORDER BY visits DESC
				LIMIT %d",
				$days,
				$limit
			)
		);
	}

	/* ============================================================== */
	/*  Dashboard Page                                                 */
	/* ============================================================== */

	/**
	 * Render the FisHotel Tools → Visitor Stats page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( Visitor_Stats::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '7d'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $range, array( '24h', '7d', '30d' ), true ) ) {
			$range = '7d';
		}

		$range_days = self::range_to_days( $range );

		$online_now    = self::get_online_now();
		$has_wc        = class_exists( 'WooCommerce' );
		$top_pages     = self::get_top_pages( $range_days );
		$top_products  = $has_wc ? self::get_top_products( $range_days ) : array();
		$top_referrers = self::get_top_referrers( $range_days );

		// Resolved here (not in the view): an included file runs in the
		// global namespace and cannot reference the section class.
		$base_url = admin_url( 'admin.php?page=' . Visitor_Stats::MENU_SLUG );

		include Visitor_Stats::path() . 'views/dashboard.php';
	}

	/**
	 * Map a range key to its day window.
	 *
	 * @param string $range Range key (24h|7d|30d).
	 * @return int
	 */
	private static function range_to_days( $range ) {
		switch ( $range ) {
			case '24h':
				return 1;
			case '30d':
				return 30;
			case '7d':
			default:
				return 7;
		}
	}

	/* ============================================================== */
	/*  Live Counter (AJAX)                                            */
	/* ============================================================== */

	/**
	 * AJAX handler for the live online-now poll.
	 */
	public function ajax_online_now() {
		check_ajax_referer( self::NONCE, '_wpnonce' );

		if ( ! current_user_can( Visitor_Stats::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		wp_send_json_success( array( 'count' => self::get_online_now() ) );
	}

	/* ============================================================== */
	/*  Dashboard Widget                                               */
	/* ============================================================== */

	/**
	 * Register the at-a-glance dashboard widget (admins only).
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( Visitor_Stats::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'fishotel_stats_widget',
			__( 'Visitor Stats', 'fishotel-misc-plugin' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget body.
	 */
	public function render_dashboard_widget() {
		$online      = self::get_online_now();
		$today_views = self::get_pageviews_today();
		$top_today   = self::get_top_pages( 1, 3 );
		$full_url    = admin_url( 'admin.php?page=' . Visitor_Stats::MENU_SLUG );
		?>
		<div class="fh-stats-widget">
			<p class="fh-stats-widget__big">
				<span class="fh-stats-online-now"><?php echo (int) $online; ?></span>
				<span class="fh-stats-widget__label"><?php esc_html_e( 'on the site right now', 'fishotel-misc-plugin' ); ?></span>
			</p>

			<p class="fh-stats-widget__today">
				<?php
				printf(
					/* translators: %s: number of pageviews today */
					esc_html( _n( '%s pageview today', '%s pageviews today', $today_views, 'fishotel-misc-plugin' ) ),
					'<strong>' . esc_html( number_format_i18n( $today_views ) ) . '</strong>'
				);
				?>
			</p>

			<?php if ( ! empty( $top_today ) ) : ?>
				<h4 class="fh-stats-widget__h"><?php esc_html_e( 'Top pages today', 'fishotel-misc-plugin' ); ?></h4>
				<ul class="fh-stats-widget__list">
					<?php
					foreach ( $top_today as $row ) :
						$label = '' !== $row->title ? $row->title : $row->url;
						?>
						<li>
							<span class="fh-stats-widget__page"><?php echo esc_html( $label ); ?></span>
							<span class="fh-stats-widget__count"><?php echo esc_html( number_format_i18n( $row->views ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="fh-stats-widget__footer">
				<a href="<?php echo esc_url( $full_url ); ?>"><?php esc_html_e( 'View full stats →', 'fishotel-misc-plugin' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* ============================================================== */
	/*  Asset Enqueue                                                  */
	/* ============================================================== */

	/**
	 * Enqueue admin CSS / JS on the wp-admin home (for the widget) and
	 * on the Visitor Stats page.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen        = get_current_screen();
		$is_dashboard  = ( 'index.php' === $hook );
		$is_stats_page = ( $screen && false !== strpos( $screen->id, Visitor_Stats::MENU_SLUG ) );

		if ( ! $is_dashboard && ! $is_stats_page ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-visitor-stats-admin',
			Visitor_Stats::url() . 'css/visitor-stats-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-visitor-stats-admin',
			Visitor_Stats::url() . 'js/visitor-stats-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-visitor-stats-admin',
			'fishotelStatsAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'pollInterval' => 10000,
			)
		);
	}
}
