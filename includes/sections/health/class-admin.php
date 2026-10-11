<?php
/**
 * Health admin — the page under FisHotel Tools (Overview / Log /
 * Settings), the WordPress dashboard widget, the CSV download and the
 * "Clear log" action.
 *
 * Every write path checks manage_options and a nonce. The CSV guards
 * every cell that starts with = + - @ or a tab with a leading apostrophe
 * so a logged message can never become a spreadsheet formula.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for the AJAX calls (clear log).
	 *
	 * @var string
	 */
	const NONCE_AJAX = 'fishotel_health_admin';

	/**
	 * Nonce action for the CSV download (admin-post).
	 *
	 * @var string
	 */
	const NONCE_CSV = 'fishotel_health_csv';

	/**
	 * Log rows per page.
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * CSV row cap. Store::query() never returns more than Store::MAX_PER_PAGE
	 * rows at once (its memory guard), so the export reaches this by paging
	 * through the log in chunks of that size and writing each page as it
	 * arrives; when the log holds more, the last line of the file says so.
	 *
	 * @var int
	 */
	const CSV_MAX_ROWS = 5000;

	/**
	 * Dashboard widget id.
	 *
	 * @var string
	 */
	const WIDGET_ID = 'fishotel_health_widget';

	/**
	 * Sources always offered in the filter dropdown.
	 *
	 * @var string[]
	 */
	const KNOWN_SOURCES = array( 'php', 'mail', 'smtp', 'updater' );

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_post_fishotel_health_csv', array( $this, 'download_csv' ) );
		add_action( 'wp_ajax_fishotel_health_clear', array( $this, 'ajax_clear' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab overview|log|settings.
	 * @param array  $extra Extra query args.
	 * @return string
	 */
	public static function page_url( $tab = 'overview', array $extra = array() ) {
		$args = array( 'page' => Health::MENU_SLUG );
		if ( 'overview' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the section page.
	 */
	public function render_page() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( ! in_array( $tab, array( 'overview', 'log', 'settings' ), true ) ) {
			$tab = 'overview';
		}

		$store = new Store();

		if ( 'settings' === $tab ) {
			$settings   = Settings::get();
			$last_prune = get_option( Cron::LAST_PRUNE_OPTION, array() );
			$next_prune = wp_next_scheduled( Cron::HOOK );

			include Health::path() . 'views/settings.php';
			return;
		}

		if ( 'log' === $tab ) {
			$filters = self::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
			$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result  = $store->query( $filters, $paged, self::PER_PAGE );
			$rows    = $result['rows'];
			$total   = (int) $result['total'];
			$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
			$sources = self::sources_for_filter( $rows, $filters['source'] );
			$csv_url = wp_nonce_url(
				add_query_arg(
					array_merge( array( 'action' => 'fishotel_health_csv' ), self::request_args( $filters ) ),
					admin_url( 'admin-post.php' )
				),
				self::NONCE_CSV
			);
			$table_ok = Store::table_exists();

			include Health::path() . 'views/log.php';
			return;
		}

		$facts      = Facts::collect( $store );
		$rows       = Checks::evaluate( $facts, $facts['now'] );
		$summary    = Checks::summary( $rows );
		$groups     = Checks::by_group( $rows );
		$last_prune = get_option( Cron::LAST_PRUNE_OPTION, array() );
		$table_ok   = Store::table_exists();

		include Health::path() . 'views/overview.php';
	}

	/**
	 * level / source / search from a request array.
	 *
	 * @param array $request $_GET-like.
	 * @return array{level: string, source: string, search: string}
	 */
	public static function filters_from_request( $request ) {
		$request = is_array( $request ) ? $request : array();

		$level = isset( $request['level'] ) ? sanitize_key( wp_unslash( $request['level'] ) ) : '';
		if ( ! in_array( $level, Store::LEVELS, true ) ) {
			$level = '';
		}

		$source = isset( $request['source'] ) ? Logger::clean_source( sanitize_text_field( wp_unslash( $request['source'] ) ) ) : '';
		if ( isset( $request['source'] ) && '' === trim( (string) $request['source'] ) ) {
			$source = '';
		}

		$search = isset( $request['s'] ) ? sanitize_text_field( wp_unslash( $request['s'] ) ) : '';

		return array(
			'level'  => $level,
			'source' => $source,
			'search' => substr( $search, 0, 200 ),
		);
	}

	/**
	 * The inverse of filters_from_request(): the non-empty filters as the
	 * query args a request carries them under (the search box posts as
	 * `s`, not `search`), for pagination links and the CSV URL.
	 *
	 * @param array $filters level, source, search.
	 * @return array
	 */
	public static function request_args( array $filters ) {
		return array_filter(
			array(
				'level'  => (string) ( $filters['level'] ?? '' ),
				'source' => (string) ( $filters['source'] ?? '' ),
				's'      => (string) ( $filters['search'] ?? '' ),
			),
			'strlen'
		);
	}

	/**
	 * Sources for the dropdown: the known ones + what is on this page +
	 * the current selection.
	 *
	 * @param array[] $rows    Page rows.
	 * @param string  $current Selected source.
	 * @return string[]
	 */
	public static function sources_for_filter( array $rows, $current = '' ) {
		$sources = self::KNOWN_SOURCES;

		foreach ( $rows as $row ) {
			$source = (string) ( $row['source'] ?? '' );
			if ( '' !== $source && ! in_array( $source, $sources, true ) ) {
				$sources[] = $source;
			}
		}

		if ( '' !== (string) $current && ! in_array( (string) $current, $sources, true ) ) {
			$sources[] = (string) $current;
		}

		sort( $sources );

		return $sources;
	}

	/* ============================================================== */
	/*  Dashboard widget                                               */
	/* ============================================================== */

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'FisHotel Health', 'fishotel-misc-plugin' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Widget body.
	 */
	public function render_widget() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			return;
		}

		try {
			$store    = new Store();
			$facts    = Facts::collect( $store );
			$rows     = Checks::evaluate( $facts, $facts['now'] );
			$summary  = Checks::summary( $rows );
			$problems = Checks::problems( $rows, 8 );
			$recent   = (array) $facts['recent_errors'];
			$now      = (int) $facts['now'];
		} catch ( \Throwable $e ) {
			echo '<p>' . esc_html__( 'Health could not be evaluated: ', 'fishotel-misc-plugin' ) . esc_html( $e->getMessage() ) . '</p>';
			return;
		}

		include Health::path() . 'views/widget.php';
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * Enqueue CSS / JS on the section page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Health::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-health-admin',
			Health::url() . 'css/health-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-health-admin',
			Health::url() . 'js/health-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-health-admin',
			'fhHealthAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'i18n'    => array(
					'saved'        => __( 'Settings saved.', 'fishotel-misc-plugin' ),
					'error'        => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'confirmClear' => __( 'Delete every row in the Health log? This cannot be undone.', 'fishotel-misc-plugin' ),
					'cleared'      => __( 'Log cleared. Reloading…', 'fishotel-misc-plugin' ),
					'showContext'  => __( 'Context', 'fishotel-misc-plugin' ),
					'hideContext'  => __( 'Hide', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  Actions                                                        */
	/* ============================================================== */

	/**
	 * AJAX: clear the log.
	 */
	public function ajax_clear() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		$store = new Store();
		$ok    = $store->clear();

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'The log table could not be cleared.', 'fishotel-misc-plugin' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'fishotel-misc-plugin' ) ) );
	}

	/**
	 * admin-post: stream the (filtered) log as CSV.
	 */
	public function download_csv() {
		if ( ! current_user_can( Health::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download the log.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_CSV );

		$filters = self::filters_from_request( $_GET );
		$store   = new Store();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="fishotel-health-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		self::write_csv( $out, $store, $filters );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Write the header and up to CSV_MAX_ROWS matching rows to a stream,
	 * paging through the store in Store::MAX_PER_PAGE chunks so memory
	 * stays flat. When the log holds more than the cap, a final `notice`
	 * row says how many were left out, so the file itself says it is
	 * incomplete. Pure apart from the store (the tests feed a fake).
	 *
	 * @param resource $out     Writable stream.
	 * @param object   $store   Store (query()).
	 * @param array    $filters level, source, search.
	 * @return int Rows written (excluding the header and the notice).
	 */
	public static function write_csv( $out, $store, array $filters ) {
		fputcsv( $out, array( 'id', 'logged_at_utc', 'level', 'source', 'message', 'repeats', 'context' ) );

		$per_page = Store::MAX_PER_PAGE;
		$written  = 0;
		$total    = 0;

		for ( $page = 1; $written < self::CSV_MAX_ROWS; $page++ ) {
			$result = $store->query( $filters, $page, $per_page );
			$rows   = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();

			if ( 1 === $page ) {
				$total = (int) ( $result['total'] ?? 0 );
			}

			foreach ( $rows as $row ) {
				fputcsv( $out, self::csv_row( (array) $row ) );
				$written++;
				if ( $written >= self::CSV_MAX_ROWS ) {
					break;
				}
			}

			// A short page is the last one; so is reaching the first page's total.
			if ( count( $rows ) < $per_page || $written >= $total ) {
				break;
			}
		}

		if ( $total > $written ) {
			fputcsv( $out, array( '', '', 'notice', 'export', self::csv_cell( sprintf( 'truncated: %d of %d matching rows exported', $written, $total ) ), '', '' ) );
		}

		return $written;
	}

	/**
	 * A stored row as guarded CSV cells (pure).
	 *
	 * @param array $row Store row (hydrated).
	 * @return string[]
	 */
	public static function csv_row( array $row ) {
		$context = $row['context'] ?? array();
		if ( is_array( $context ) ) {
			$context = (string) json_encode( $context );
		}

		return array(
			self::csv_cell( (string) ( $row['id'] ?? '' ) ),
			self::csv_cell( (string) ( $row['logged_at'] ?? '' ) ),
			self::csv_cell( (string) ( $row['level'] ?? '' ) ),
			self::csv_cell( (string) ( $row['source'] ?? '' ) ),
			self::csv_cell( (string) ( $row['message'] ?? '' ) ),
			self::csv_cell( (string) (int) ( $row['repeats'] ?? 1 ) ),
			self::csv_cell( (string) $context ),
		);
	}

	/**
	 * Neutralise a cell that a spreadsheet would run as a formula: a
	 * leading = + - @ tab or CR gets a single quote in front (pure).
	 *
	 * @param mixed $value Cell.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) || null === $value ? (string) $value : (string) json_encode( $value );

		if ( '' === $value ) {
			return '';
		}

		$first = $value[0];

		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ============================================================== */
	/*  Rendering helpers                                              */
	/* ============================================================== */

	/**
	 * The status badge.
	 *
	 * @param string $status ok|warn|error|info.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( $status ) {
		$status = in_array( $status, array( 'ok', 'warn', 'error', 'info' ), true ) ? $status : 'info';
		$labels = array(
			'ok'    => __( 'OK', 'fishotel-misc-plugin' ),
			'warn'  => __( 'Warning', 'fishotel-misc-plugin' ),
			'error' => __( 'Error', 'fishotel-misc-plugin' ),
			'info'  => __( 'Info', 'fishotel-misc-plugin' ),
		);

		return '<span class="fh-health-badge fh-health-badge--' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
	}

	/**
	 * The level badge for a log row.
	 *
	 * @param string $level Level.
	 * @return string Escaped HTML.
	 */
	public static function level_html( $level ) {
		$level = in_array( $level, Store::LEVELS, true ) ? $level : 'info';

		return '<span class="fh-health-level fh-health-level--' . esc_attr( $level ) . '">' . esc_html( $level ) . '</span>';
	}

	/**
	 * A UTC logged_at as a site-time label.
	 *
	 * @param string $logged_at Y-m-d H:i:s (UTC).
	 * @return string
	 */
	public static function site_time( $logged_at ) {
		$ts = (int) strtotime( (string) $logged_at . ' UTC' );
		if ( $ts <= 0 ) {
			return (string) $logged_at;
		}

		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d H:i', $ts );
		}

		return gmdate( 'Y-m-d H:i', $ts );
	}

	/**
	 * Pretty JSON for a context array.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	public static function context_json( $context ) {
		if ( empty( $context ) ) {
			return '';
		}

		return (string) wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
