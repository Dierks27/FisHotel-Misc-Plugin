<?php
/**
 * Weather admin — the overview + settings page under FisHotel Tools, the
 * Weather meta box on the order screen (legacy and HPOS), the Weather
 * column and "Check weather" bulk action on the orders list, and the
 * "Check weather now" AJAX that re-runs one order.
 *
 * Every write path (AJAX check, AJAX scan, settings save, bulk action)
 * checks manage_woocommerce and a nonce. The meta box body is rendered by
 * one view on the server, and the AJAX reply ships that same rendered
 * HTML, so the JS never builds markup from data.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action shared by the check / scan AJAX calls.
	 *
	 * @var string
	 */
	const NONCE_AJAX = 'fishotel_weather_admin';

	/**
	 * Orders-list column key.
	 *
	 * @var string
	 */
	const COLUMN_KEY = 'fh_weather';

	/**
	 * Bulk action key.
	 *
	 * @var string
	 */
	const BULK_ACTION = 'fh_weather_check';

	/**
	 * Query args carrying the bulk result to the notice.
	 */
	const QUERY_CHECKED = 'fh_weather_checked';
	const QUERY_FAILED  = 'fh_weather_failed';

	/**
	 * Register hooks.
	 *
	 * Priority 20 on admin_enqueue_scripts so WooCommerce's own admin
	 * styles (registered at 10) are available as a dependency on the
	 * order screens.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		add_action( 'wp_ajax_fishotel_weather_check', array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_fishotel_weather_run_scan', array( $this, 'ajax_run_scan' ) );

		// Order edit screen — the screen-specific hooks are the HPOS-safe
		// way to reach the wc-orders page (same as the theme).
		add_action( 'add_meta_boxes_shop_order', array( $this, 'register_meta_box' ) );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'register_meta_box' ) );

		// Orders list column — legacy CPT table and HPOS table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'register_column' ), 30 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'register_column' ), 30 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Bulk action on both tables. WordPress verifies the list-table
		// nonce before handle_bulk_actions-* fires; the capability is ours.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'register_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	/* ============================================================== */
	/*  Section page                                                   */
	/* ============================================================== */

	/**
	 * Render the section page (Overview or Settings tab).
	 */
	public function render_page() {
		if ( ! current_user_can( Weather::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$settings = Settings::get();
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab      = 'settings' === $tab ? 'settings' : 'overview';

		if ( 'settings' === $tab ) {
			$statuses_all = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			$next_run     = Cron::next_run_label();
			$last_run     = get_option( Weather::LAST_RUN_OPTION, array() );

			include Weather::path() . 'views/settings.php';
			return;
		}

		$today    = current_time( 'Y-m-d' );
		$upcoming = self::upcoming_dates( $settings, $today );

		$requested = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$date      = Note::date( $requested ) ? $requested : self::default_date( $upcoming, $today );

		$rows     = self::overview_rows( $date, $settings );
		$totals   = self::overview_totals( $rows );
		$last_run = get_option( Weather::LAST_RUN_OPTION, array() );
		$next_run = Cron::next_run_label();

		include Weather::path() . 'views/overview.php';
	}

	/**
	 * Delivery dates from today on that have open orders, with counts.
	 *
	 * @param array  $settings Settings.
	 * @param string $today    Y-m-d.
	 * @return array<string, int> date => order count, ascending.
	 */
	public static function upcoming_dates( array $settings, $today ) {
		$counts = array();

		foreach ( Checker::open_orders( $settings['statuses'] ) as $order ) {
			if ( Checker::is_folded_source( $order ) ) {
				continue;
			}
			$date = Checker::delivery_date( $order );
			if ( $date < $today ) {
				continue;
			}
			$counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * The overview's default date: the next delivery date with orders,
	 * else today.
	 *
	 * @param array  $upcoming From self::upcoming_dates().
	 * @param string $today    Y-m-d.
	 * @return string
	 */
	public static function default_date( array $upcoming, $today ) {
		foreach ( array_keys( $upcoming ) as $date ) {
			if ( $date >= $today ) {
				return $date;
			}
		}

		return $today;
	}

	/**
	 * Table rows for one delivery date.
	 *
	 * @param string $ymd      Y-m-d.
	 * @param array  $settings Settings.
	 * @return array[]
	 */
	public static function overview_rows( $ymd, array $settings ) {
		$rows = array();

		foreach ( Checker::open_orders( $settings['statuses'], array( $ymd ) ) as $order ) {
			if ( Checker::is_folded_source( $order ) ) {
				continue;
			}
			$rows[] = self::row( $order, $ymd );
		}

		return $rows;
	}

	/**
	 * One overview row.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $ymd   The date the overview is showing.
	 * @return array
	 */
	public static function row( \WC_Order $order, $ymd ) {
		$stored      = Checker::stored( $order );
		$destination = Checker::destination( $order );
		$note_status = Checker::note_status( $order, $ymd );
		$action      = $stored && 'noted' === $note_status ? $stored['action'] : ( $stored && 'unavailable' === $note_status ? Bands::ACTION_UNAVAILABLE : '' );

		$name = trim( $order->get_formatted_shipping_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}
		if ( '' === $name ) {
			$name = __( 'Guest', 'fishotel-misc-plugin' );
		}

		return array(
			'order_id'       => $order->get_id(),
			'number'         => $order->get_order_number(),
			'edit_url'       => $order->get_edit_order_url(),
			'customer'       => $name,
			'place'          => Note::place( $destination['city'], $destination['state'] ),
			'zip'            => $destination['postcode'],
			'status'         => wc_get_order_status_name( $order->get_status() ),
			'is_fulfillment' => Checker::is_fulfillment( $order ),
			'sources'        => count( Checker::live_sources( $order ) ),
			'high'           => $stored && 'noted' === $note_status ? $stored['high'] : null,
			'low'            => $stored && 'noted' === $note_status ? $stored['low'] : null,
			'action'         => $action,
			'at_line'        => $stored ? (bool) $stored['at_line'] : false,
			'summary'        => $stored ? $stored['summary'] : '',
			'note_status'    => $note_status,
			'has_email'      => $stored && ! empty( $stored['email'] ),
		);
	}

	/**
	 * Totals per action for the tiles above the table.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string, int>
	 */
	public static function overview_totals( array $rows ) {
		$totals = array(
			'orders'      => count( $rows ),
			'heat_pack'   => 0,
			'none'        => 0,
			'cooling'     => 0,
			'ups_store'   => 0,
			'hold'        => 0,
			'unavailable' => 0,
			'unchecked'   => 0,
		);

		foreach ( $rows as $row ) {
			$action = (string) $row['action'];

			if ( Bands::is_ups_store( $action ) ) {
				$totals['ups_store']++;
			} elseif ( Bands::is_hold( $action ) ) {
				$totals['hold']++;
			} elseif ( '' === $action ) {
				$totals['unchecked']++;
			} elseif ( isset( $totals[ $action ] ) ) {
				$totals[ $action ]++;
			}
		}

		return $totals;
	}

	/**
	 * Human label for a note status.
	 *
	 * @param string $status From Checker::note_status().
	 * @return string
	 */
	public static function note_status_label( $status ) {
		switch ( $status ) {
			case 'noted':
				return __( 'Noted', 'fishotel-misc-plugin' );
			case 'unavailable':
				return __( 'Unavailable — check manually', 'fishotel-misc-plugin' );
			case 'stale':
				return __( 'Noted for another date', 'fishotel-misc-plugin' );
		}

		return __( 'Not checked', 'fishotel-misc-plugin' );
	}

	/**
	 * The coloured action badge.
	 *
	 * @param string $action  Action ('' = not checked).
	 * @param bool   $at_line Whether the high was exactly on a line.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( $action, $at_line = false ) {
		$action = (string) $action;

		if ( '' === $action ) {
			return '<span class="fh-weather-badge fh-weather-badge--unchecked">' . esc_html__( 'Not checked', 'fishotel-misc-plugin' ) . '</span>';
		}

		$label = Bands::short_label( $action );
		if ( '' === $label ) {
			$label = $action;
		}

		$classes = 'fh-weather-badge fh-weather-badge--' . sanitize_html_class( $action );
		if ( $at_line ) {
			$classes .= ' fh-weather-badge--line';
			$label    = __( 'AT THE LINE', 'fishotel-misc-plugin' ) . ' · ' . $label;
		}

		return '<span class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Whether a screen is the orders list or an order edit screen.
	 *
	 * @param \WP_Screen|null $screen Screen.
	 * @return bool
	 */
	private function is_orders_screen( $screen ) {
		if ( ! $screen ) {
			return false;
		}

		$ids = array( 'shop_order', 'edit-shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return in_array( $screen->id, $ids, true );
	}

	/**
	 * Enqueue CSS / JS on the section page and the order screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		unset( $hook_suffix );

		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_section = $screen && false !== strpos( $screen->id, Weather::MENU_SLUG );
		$on_orders  = $this->is_orders_screen( $screen );

		if ( ! $on_section && ! $on_orders ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-weather-admin',
			Weather::url() . 'css/weather-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-weather-admin',
			Weather::url() . 'js/weather-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-weather-admin',
			'fhWeatherAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE_AJAX ),
				'defaults' => Bands::DEFAULT_THRESHOLDS,
				'i18n'     => array(
					'saved'    => __( 'Settings saved.', 'fishotel-misc-plugin' ),
					'error'    => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'checking' => __( 'Checking…', 'fishotel-misc-plugin' ),
					'checked'  => __( 'Checked.', 'fishotel-misc-plugin' ),
					'copied'   => __( 'Copied.', 'fishotel-misc-plugin' ),
					'copyFail' => __( 'Could not copy — select the text and copy it by hand.', 'fishotel-misc-plugin' ),
					'scanning' => __( 'Running the nightly scan…', 'fishotel-misc-plugin' ),
					/* translators: 1: orders checked, 2: unavailable, 3: skipped */
					'scanDone' => __( 'Scan finished: %1$d checked, %2$d unavailable, %3$d already noted. Reloading…', 'fishotel-misc-plugin' ),
					'nextRun'  => __( 'Next run: %s', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  Meta box                                                       */
	/* ============================================================== */

	/**
	 * Register the Weather meta box on the current order screen.
	 */
	public function register_meta_box() {
		$screen = 'shop_order';

		if ( function_exists( 'wc_get_page_screen_id' ) && function_exists( 'get_current_screen' ) ) {
			$hpos    = wc_get_page_screen_id( 'shop-order' );
			$current = get_current_screen();
			if ( $current && $current->id === $hpos ) {
				$screen = $hpos;
			}
		}

		add_meta_box(
			'fishotel-weather',
			__( 'Weather', 'fishotel-misc-plugin' ),
			array( $this, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Meta box callback: WP_Post on legacy, WC_Order on HPOS.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null );

		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Order not loaded yet.', 'fishotel-misc-plugin' ) . '</p>';
			return;
		}

		echo $this->render_box_body( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- view escapes every value.
	}

	/**
	 * Render the meta box body (also the AJAX reply).
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML.
	 */
	public function render_box_body( \WC_Order $order ) {
		$stored      = Checker::stored( $order );
		$delivery    = Checker::delivery_date( $order );
		$destination = Checker::destination( $order );
		$note_status = '' !== $delivery ? Checker::note_status( $order, $delivery ) : 'none';
		$sources     = count( Checker::live_sources( $order ) );
		$folded_into = (int) $order->get_meta( Checker::META_FULFILLED_BY );

		ob_start();
		include Weather::path() . 'views/meta-box.php';
		return (string) ob_get_clean();
	}

	/* ============================================================== */
	/*  Orders list column                                             */
	/* ============================================================== */

	/**
	 * Add the Weather column after the order total (end of the table
	 * otherwise).
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function register_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_total' === $key ) {
				$new[ self::COLUMN_KEY ] = __( 'Weather', 'fishotel-misc-plugin' );
			}
		}

		if ( ! isset( $new[ self::COLUMN_KEY ] ) ) {
			$new[ self::COLUMN_KEY ] = __( 'Weather', 'fishotel-misc-plugin' );
		}

		return $new;
	}

	/**
	 * Render the column cell. Legacy passes a post ID, HPOS an order.
	 *
	 * @param string         $column      Column key.
	 * @param int|\WC_Order  $order_or_id Order or post ID.
	 */
	public function render_column( $column, $order_or_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( ! $order instanceof \WC_Order ) {
			echo '&mdash;';
			return;
		}

		$delivery = Checker::delivery_date( $order );
		$stored   = Checker::stored( $order );

		if ( ! $stored || '' === $delivery ) {
			echo '<span class="fh-weather-col fh-weather-col--none">&mdash;</span>';
			return;
		}

		$status = Checker::note_status( $order, $delivery );
		if ( 'noted' !== $status && 'unavailable' !== $status ) {
			echo '<span class="fh-weather-col fh-weather-col--none" title="' . esc_attr( self::note_status_label( $status ) ) . '">&mdash;</span>';
			return;
		}

		$action = 'unavailable' === $status ? Bands::ACTION_UNAVAILABLE : $stored['action'];
		$title  = $stored['summary'];
		if ( null !== $stored['high'] && 'noted' === $status ) {
			$title = sprintf( '%s · %s', Note::long_date( $delivery ), $stored['summary'] );
		}

		echo '<span class="fh-weather-col" title="' . esc_attr( $title ) . '">' . self::badge_html( $action, $stored['at_line'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge_html() escapes.
	}

	/* ============================================================== */
	/*  Bulk action                                                    */
	/* ============================================================== */

	/**
	 * Add "Check weather" to the bulk actions dropdown.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function register_bulk_action( $actions ) {
		if ( current_user_can( Weather::CAPABILITY ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Check weather', 'fishotel-misc-plugin' );
		}

		return $actions;
	}

	/**
	 * Run the lookup for each selected order.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action.
	 * @param array  $ids         Selected IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( Weather::CAPABILITY ) ) {
			return $redirect_to;
		}

		$checked = 0;
		$failed  = 0;

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( $id < 1 ) {
				continue;
			}

			$result = Checker::check_order( $id, true );
			if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
				$failed++;
			} else {
				$checked++;
			}
		}

		return add_query_arg(
			array(
				self::QUERY_CHECKED => $checked,
				self::QUERY_FAILED  => $failed,
			),
			remove_query_arg( array( self::QUERY_CHECKED, self::QUERY_FAILED ), $redirect_to )
		);
	}

	/**
	 * Notice after the bulk action.
	 */
	public function bulk_notice() {
		if ( ! isset( $_GET[ self::QUERY_CHECKED ] ) && ! isset( $_GET[ self::QUERY_FAILED ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only counters.
			return;
		}

		if ( ! current_user_can( Weather::CAPABILITY ) ) {
			return;
		}

		$checked = isset( $_GET[ self::QUERY_CHECKED ] ) ? absint( wp_unslash( $_GET[ self::QUERY_CHECKED ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed  = isset( $_GET[ self::QUERY_FAILED ] ) ? absint( wp_unslash( $_GET[ self::QUERY_FAILED ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$failed ? 'warning' : 'success',
			esc_html(
				sprintf(
					/* translators: 1: orders with a weather note, 2: orders where the weather was unavailable or that had no delivery date */
					__( 'Weather checked: %1$d noted, %2$d unavailable or without a delivery date.', 'fishotel-misc-plugin' ),
					$checked,
					$failed
				)
			)
		);
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * Capability + nonce for the AJAX endpoints.
	 */
	private function guard_ajax() {
		if ( ! current_user_can( Weather::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
	}

	/**
	 * Re-run the lookup for one order and reply with the fresh meta box
	 * body and the overview row.
	 */
	public function ajax_check() {
		$this->guard_ajax();

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'fishotel-misc-plugin' ) ), 404 );
		}

		$result = Checker::check_order( $order, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message'  => $result->get_error_message(),
					'box_html' => $this->render_box_body( $order ),
				),
				422
			);
		}

		// Re-read so the reply reflects exactly what was stored.
		$order = wc_get_order( $order_id );
		$date  = Checker::delivery_date( $order );
		$row   = self::row( $order, $date );

		wp_send_json_success(
			array(
				'message'       => ! empty( $result['ok'] )
					? __( 'Weather checked and noted.', 'fishotel-misc-plugin' )
					: sprintf(
						/* translators: %s: reason */
						__( 'Weather unavailable — check manually (%s).', 'fishotel-misc-plugin' ),
						(string) $result['reason']
					),
				'ok'            => ! empty( $result['ok'] ),
				'note_status'   => (string) $result['note_status'],
				'sources_noted' => (int) ( $result['sources_noted'] ?? 0 ),
				'box_html'      => $this->render_box_body( $order ),
				'row'           => array(
					'order_id'          => $row['order_id'],
					'high'              => $row['high'],
					'low'               => $row['low'],
					'action'            => $row['action'],
					'at_line'           => $row['at_line'],
					'badge_html'        => self::badge_html( $row['action'], $row['at_line'] ),
					'summary'           => $row['summary'],
					'note_status'       => $row['note_status'],
					'note_status_label' => self::note_status_label( $row['note_status'] ),
				),
			)
		);
	}

	/**
	 * Run the nightly scan on demand.
	 */
	public function ajax_run_scan() {
		$this->guard_ajax();

		$summary = Checker::run_nightly();

		wp_send_json_success(
			array(
				'summary' => $summary,
				'message' => sprintf(
					/* translators: 1: orders checked, 2: unavailable, 3: skipped */
					__( 'Scan finished: %1$d checked, %2$d unavailable, %3$d already noted.', 'fishotel-misc-plugin' ),
					(int) $summary['checked'],
					(int) $summary['unavailable'],
					(int) $summary['skipped']
				),
			)
		);
	}
}
