<?php
/**
 * Today admin — the page under FisHotel Tools (Today / Settings), the
 * "Today at FisHotel" dashboard widget, and the Refresh actions (an AJAX
 * button on the page, a nonce'd link on the widget) that drop the cache.
 *
 * Every write path checks manage_woocommerce (settings: manage_options)
 * and a nonce. The views escape every value; the JS never builds markup
 * from data.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Section_Manager;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for the AJAX refresh.
	 *
	 * @var string
	 */
	const NONCE_AJAX = 'fishotel_today_admin';

	/**
	 * Nonce action for the widget's refresh link.
	 *
	 * @var string
	 */
	const NONCE_LINK = 'fishotel_today_refresh';

	/**
	 * Query arg of the widget's refresh link.
	 *
	 * @var string
	 */
	const QUERY_REFRESH = 'fh_today_refresh';

	/**
	 * Dashboard widget id.
	 *
	 * @var string
	 */
	const WIDGET_ID = 'fishotel_today_widget';

	/**
	 * The Weather section's page slug and the query var its overview reads.
	 */
	const WEATHER_PAGE_SLUG = 'fishotel-misc-weather';
	const WEATHER_DATE_VAR  = 'date';

	/**
	 * The Batch plugin's Ship Day tab (reads no date query var — its JS
	 * picks the date).
	 *
	 * @var string
	 */
	const SHIP_DAY_URL = 'admin.php?page=fishotel-invoicing&tab=ship-day';

	/**
	 * The capability the Batch plugin registers its Invoicing page (and so
	 * the Ship Day tab) with. A manage_woocommerce user without it gets
	 * "not allowed" from WordPress core, so the link is only offered to
	 * users who can open it.
	 *
	 * @var string
	 */
	const SHIP_DAY_CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_init', array( $this, 'maybe_refresh_from_link' ) );
		add_action( 'wp_ajax_fishotel_today_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab   today|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'today', array $extra = array() ) {
		$args = array( 'page' => Today::MENU_SLUG );
		if ( 'today' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the Weather section is enabled (its overview page exists).
	 *
	 * @return bool
	 */
	public static function weather_enabled() {
		$enabled = get_option( Section_Manager::OPTION_KEY, array() );
		return is_array( $enabled ) && in_array( 'weather', $enabled, true );
	}

	/**
	 * Link to the Weather overview for a date, or '' when the section is off.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	public static function weather_url( $ymd ) {
		if ( ! self::weather_enabled() ) {
			return '';
		}

		return add_query_arg(
			array(
				'page'                 => self::WEATHER_PAGE_SLUG,
				self::WEATHER_DATE_VAR => $ymd,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Link to the Batch plugin's Ship Day pick list, or '' when the class
	 * is absent or the current user cannot open that page.
	 *
	 * @return string
	 */
	public static function ship_day_url() {
		if ( ! Collector::has_ship_day() || ! current_user_can( self::SHIP_DAY_CAPABILITY ) ) {
			return '';
		}

		return admin_url( self::SHIP_DAY_URL );
	}

	/**
	 * The widget's refresh link (nonce'd, back to the dashboard).
	 *
	 * @return string
	 */
	public static function widget_refresh_url() {
		return wp_nonce_url( add_query_arg( self::QUERY_REFRESH, '1', admin_url( 'index.php' ) ), self::NONCE_LINK );
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Today::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'today'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab = 'settings' === $tab ? 'settings' : 'today';

		$can_settings = current_user_can( Today::SETTINGS_CAPABILITY );

		if ( 'settings' === $tab && $can_settings ) {
			$settings     = Settings::get();
			$statuses_all = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			$columns      = Collector::columns( $settings );

			include Today::path() . 'views/settings.php';
			return;
		}

		$settings = Settings::get();
		$tz       = Collector::timezone();

		try {
			$data = Collector::get( $settings );
		} catch ( \Throwable $e ) {
			$data = null;
			$fail = $e->getMessage();
		}

		if ( ! is_array( $data ) ) {
			echo '<div class="wrap fishotel-misc-wrap"><div class="fishotel-misc-notice fishotel-misc-notice--error">' . esc_html__( 'Today could not be built: ', 'fishotel-misc-plugin' ) . esc_html( isset( $fail ) ? $fail : '' ) . '</div></div>';
			return;
		}

		$header       = Model::header_line( $data['today'], $data['plan'], $data['chips'], $data['lookahead'], $tz );
		$weather_on   = self::weather_enabled();
		$ship_day_url = self::ship_day_url();

		include Today::path() . 'views/page.php';
	}

	/* ============================================================== */
	/*  Dashboard widget                                               */
	/* ============================================================== */

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget() {
		if ( ! current_user_can( Today::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Today at FisHotel', 'fishotel-misc-plugin' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Widget body.
	 */
	public function render_widget() {
		if ( ! current_user_can( Today::CAPABILITY ) ) {
			return;
		}

		try {
			$settings = Settings::get();
			$tz       = Collector::timezone();
			$data     = Collector::get( $settings );
			$header   = Model::header_line( $data['today'], $data['plan'], $data['chips'], $data['lookahead'], $tz );
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'Today could not be built: ', 'fishotel-misc-plugin' ) . esc_html( $e->getMessage() ) . '</p>';
			return;
		}

		include Today::path() . 'views/widget.php';
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue CSS / JS on the section page only (the widget carries one
	 * small inline style block).
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Today::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-today-admin',
			Today::url() . 'css/today-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-today-admin',
			Today::url() . 'js/today-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-today-admin',
			'fhTodayAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'i18n'    => array(
					'saved'      => __( 'Settings saved.', 'fishotel-misc-plugin' ),
					'error'      => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'refreshing' => __( 'Refreshing…', 'fishotel-misc-plugin' ),
					'refreshed'  => __( 'Refreshed. Reloading…', 'fishotel-misc-plugin' ),
					'justNow'    => __( 'just now', 'fishotel-misc-plugin' ),
					/* translators: %d: minutes */
					'minAgo'     => __( '%d min ago', 'fishotel-misc-plugin' ),
					/* translators: %d: hours */
					'hoursAgo'   => __( '%d h ago', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  Refresh                                                        */
	/* ============================================================== */

	/**
	 * AJAX: drop the cache (the page reloads itself).
	 */
	public function ajax_refresh() {
		if ( ! current_user_can( Today::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		Collector::clear_cache();

		wp_send_json_success( array( 'message' => __( 'Refreshed.', 'fishotel-misc-plugin' ) ) );
	}

	/**
	 * The widget's Refresh link: nonce + capability, drop the cache, back
	 * to the dashboard without the query args.
	 */
	public function maybe_refresh_from_link() {
		if ( ! isset( $_GET[ self::QUERY_REFRESH ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
			return;
		}

		if ( ! current_user_can( Today::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE_LINK );

		Collector::clear_cache();

		wp_safe_redirect( remove_query_arg( array( self::QUERY_REFRESH, '_wpnonce' ) ) );
		exit;
	}

	/* ============================================================== */
	/*  Rendering helpers                                              */
	/* ============================================================== */

	/**
	 * The weather badge.
	 *
	 * @param array $weather Model::weather_state() result.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( array $weather ) {
		$badge = Model::badge( $weather );
		$title = (string) ( $weather['summary'] ?? '' );

		return '<span class="fh-today-badge fh-today-badge--' . esc_attr( $badge['key'] ) . '"' . ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $badge['label'] ) . '</span>';
	}

	/**
	 * "Cached 2 min ago" / "Built just now".
	 *
	 * @param array $data Collector data.
	 * @return string
	 */
	public static function freshness_label( array $data ) {
		$age = max( 0, time() - (int) ( $data['generated_at'] ?? time() ) );

		if ( empty( $data['cached'] ) ) {
			return __( 'Built just now', 'fishotel-misc-plugin' );
		}
		if ( $age < 60 ) {
			return __( 'Cached just now', 'fishotel-misc-plugin' );
		}

		/* translators: %d: minutes */
		return sprintf( __( 'Cached %d min ago', 'fishotel-misc-plugin' ), (int) floor( $age / 60 ) );
	}
}
