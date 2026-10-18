<?php
/**
 * Loader — runs the PHP snippets at plugins_loaded priority 1 (Code
 * Snippets' own timing) and keeps what happened on this request for the
 * admin notices: safe mode, the snippets the duplicate guard held back,
 * the ones skipped because a name they declare already exists, and the
 * ones that threw.
 *
 * The early loader (early-loader.php, included by the main plugin file
 * while plugins are still being loaded) hooks run_php() — the section
 * framework only boots at plugins_loaded 10, which is too late for code
 * that hooks plugins_loaded, init or anything Code Snippets ran before.
 *
 * Cost on an ordinary request: two autoloaded options (the enabled map,
 * the index), one directory listing plus a stat per snippet file, and the
 * includes themselves. One prepared query on {prefix}snippets only while
 * Code Snippets is active AND a switched-on snippet came from it.
 *
 * Errors: error_log() at once (the Health section has not booted at
 * priority 1), then at plugins_loaded 20 every recorded error is handed to
 * do_action( 'fishotel_log', … ) — the Health logger when that section is
 * on, a no-op otherwise — and the newest one per snippet is kept for the
 * admin page.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Loader
 */
class Loader {

	/**
	 * Autoloaded option: slug => bool, written by the admin switch.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'fishotel_snippets_enabled';

	/**
	 * Non-autoloaded option: slug => { time, message } — the newest error
	 * of each snippet, read by the admin page only.
	 *
	 * @var string
	 */
	const OPTION_ERRORS = 'fishotel_snippets_errors';

	/**
	 * Safe mode switches.
	 */
	const SAFE_MODE_CONSTANT    = 'FISHOTEL_SNIPPETS_SAFE_MODE';
	const CS_SAFE_MODE_CONSTANT = 'CODE_SNIPPETS_SAFE_MODE';
	const SAFE_MODE_QUERY       = 'fishotel-snippets-safe-mode';

	/**
	 * Capability for safe mode by query, the page and the switch.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Health log source.
	 *
	 * @var string
	 */
	const LOG_SOURCE = 'snippets';

	/**
	 * What happened on this request.
	 *
	 * @var array
	 */
	private static $state = array(
		'php_ran' => false,
		'results' => array(), // slug => ok|error|missing|skipped.
		'skipped' => array(), // slug => reason (a declared name exists already).
		'errors'  => array(), // slug => message.
	);

	/**
	 * Memo: safe mode, enabled map, guard result.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Errors waiting for flush_log().
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * The slug test_run() is running ('' when none).
	 *
	 * @var string
	 */
	private static $trial = '';

	/**
	 * Hook the early run. Called by early-loader.php at plugin load time.
	 */
	public static function hook() {
		add_action( 'plugins_loaded', array( self::class, 'run_php' ), 1 );
		add_action( 'plugins_loaded', array( self::class, 'flush_log' ), 20 );
	}

	/**
	 * plugins_loaded:1 — include the PHP snippets that run on this request.
	 */
	public static function run_php() {
		if ( self::$state['php_ran'] ) {
			return;
		}
		self::$state['php_ran'] = true;

		try {
			if ( self::safe_mode()['active'] ) {
				return;
			}

			$index = Catalog::get();
			$plan  = Rules::php_plan( $index['snippets'], self::enabled_map(), is_admin(), self::held( $index ) );

			if ( empty( $plan ) ) {
				return;
			}

			$items = array();
			foreach ( $plan as $snippet ) {
				$items[] = array(
					'slug'    => $snippet['slug'],
					'path'    => Catalog::path_of( $snippet ),
					'snippet' => $snippet,
				);
			}

			self::$state['results'] = Runner::run(
				$items,
				array( self::class, 'record_error' ),
				array( self::class, 'preflight' )
			);
		} catch ( \Throwable $e ) {
			// The loader itself must never take the site down.
			self::record_error( '', $e );
		}
	}

	/**
	 * Right before a snippet's include: refuse it when a function or class
	 * it declares without an exists-check guard already exists (another
	 * plugin, the theme, or Code Snippets declared it) — including it would
	 * fatal.
	 *
	 * @param array $item Runner item with 'snippet'.
	 * @return string '' to include, else the reason.
	 */
	public static function preflight( array $item ) {
		$reason = self::declared_already( $item['snippet'] );

		if ( '' !== $reason ) {
			self::$state['skipped'][ $item['slug'] ] = $reason;
			self::note( (string) $item['slug'], 'Snippet ' . $item['snippet']['file'] . ' was not loaded: ' . $reason, 'warning', array() );
		}

		return $reason;
	}

	/**
	 * Why including a snippet now would fatal with "Cannot redeclare": a
	 * function or class it declares without an exists-check guard already
	 * exists. '' when none does.
	 *
	 * @param array $snippet Index entry.
	 * @return string
	 */
	public static function declared_already( array $snippet ) {
		$declares = isset( $snippet['declares'] ) ? (array) $snippet['declares'] : array();

		foreach ( $declares as $decl ) {
			if ( empty( $decl['guarded'] ) && Symbols::exists_now( $decl ) ) {
				$what = 'function' === $decl['kind'] ? 'function ' . $decl['display'] . '()' : 'class ' . $decl['display'];
				return $what . ' already exists (declared by another plugin, the theme or Code Snippets), so loading it would fatal.';
			}
		}

		return '';
	}

	/**
	 * Run one PHP snippet once, now, before the switch stores it as on —
	 * what Code Snippets does when a snippet is saved active. PHP's parser
	 * (the index's syntax check) does not see compile-stage errors such as
	 * a parameter named twice; including the file does. A fatal then ends
	 * this one request (the AJAX switch) before the option is written,
	 * instead of every request after it.
	 *
	 * The snippet's code really runs (its hooks are added for the rest of
	 * this request, as Code Snippets' test run does); its output is
	 * discarded and nothing is recorded or logged.
	 *
	 * @param array $snippet Index entry of a PHP snippet.
	 * @return string '' when it ran cleanly, else why it cannot be switched on.
	 */
	public static function test_run( array $snippet ) {
		$reason = self::declared_already( $snippet );
		if ( '' !== $reason ) {
			return $reason;
		}

		// A compile-stage fatal cannot be caught, but a shutdown function
		// still runs: it answers the AJAX switch with the reason instead
		// of an empty error page.
		self::$trial = (string) $snippet['slug'];
		register_shutdown_function( array( self::class, 'test_run_died' ) );

		$error   = '';
		$results = Runner::run(
			array(
				array(
					'slug'    => (string) $snippet['slug'],
					'path'    => Catalog::path_of( $snippet ),
					'snippet' => $snippet,
				),
			),
			static function ( $slug, $e ) use ( &$error ) {
				$error = get_class( $e ) . ': ' . $e->getMessage() . ' (line ' . (int) $e->getLine() . ')';
			}
		);

		self::$trial = '';

		$result = isset( $results[ $snippet['slug'] ] ) ? $results[ $snippet['slug'] ] : 'missing';

		if ( 'ok' === $result ) {
			return '';
		}

		return '' !== $error ? $error : 'the file could not be included (' . $result . ').';
	}

	/**
	 * Shutdown after a fatal inside test_run(): the switch request dies
	 * before the option is written; tell the page why (JSON, as the AJAX
	 * switch answers) instead of leaving it a bare 500.
	 */
	public static function test_run_died() {
		if ( '' === self::$trial ) {
			return;
		}

		$slug        = self::$trial;
		self::$trial = '';
		$fatal       = error_get_last();

		if ( ! is_array( $fatal ) || ! in_array( $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}

		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON; the page sets it as text.
			array(
				'success' => false,
				'data'    => array(
					'message' => 'Refused: the test run of ' . $slug . ' hit a fatal error — ' . $fatal['message'] . ' (line ' . (int) $fatal['line'] . '). It stays off; fix the file.',
				),
			)
		);
	}

	/**
	 * Runner error callback: error_log now, queue for the Health log.
	 *
	 * @param string     $slug Slug ('' = the loader itself).
	 * @param \Throwable $e    What was thrown.
	 */
	public static function record_error( $slug, $e ) {
		$where = self::relative( $e->getFile() ) . ':' . $e->getLine();
		$label = '' === $slug ? 'The snippets loader' : 'Snippet ' . $slug;

		$message = $label . ' threw ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $where;

		self::$state['errors'][ (string) $slug ] = $message;

		self::note(
			(string) $slug,
			$message,
			'error',
			array(
				'snippet'   => (string) $slug,
				'exception' => get_class( $e ),
				'where'     => $where,
			)
		);
	}

	/**
	 * error_log() a line now and queue it for flush_log().
	 *
	 * @param string $slug    Slug.
	 * @param string $message Message.
	 * @param string $level   Health level.
	 * @param array  $context Context.
	 */
	private static function note( $slug, $message, $level, array $context ) {
		error_log( 'FisHotel Snippets: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the one channel that works at plugins_loaded:1.

		self::$queue[] = array(
			'slug'    => $slug,
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		// Recorded after the flush ran (a shortcode, a late hook): flush on shutdown.
		if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) && ! has_action( 'shutdown', array( self::class, 'flush_log' ) ) ) {
			add_action( 'shutdown', array( self::class, 'flush_log' ) );
		}
	}

	/**
	 * plugins_loaded:20 — hand queued errors to the Health logger (when
	 * that section is on) and keep the newest per snippet for the admin
	 * page. Nothing queued = nothing done (no query).
	 */
	public static function flush_log() {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = array();

		try {
			foreach ( $queue as $row ) {
				do_action( 'fishotel_log', $row['level'], self::LOG_SOURCE, $row['message'], $row['context'] );
			}

			$stored  = get_option( self::OPTION_ERRORS, array() );
			$stored  = is_array( $stored ) ? $stored : array();
			$changed = false;
			$now     = time();

			foreach ( $queue as $row ) {
				$key  = '' === $row['slug'] ? '_loader' : $row['slug'];
				$prev = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

				// The same message within the hour is not rewritten.
				if ( isset( $prev['message'], $prev['time'] ) && $prev['message'] === $row['message'] && $now - (int) $prev['time'] < HOUR_IN_SECONDS ) {
					continue;
				}

				$stored[ $key ] = array(
					'time'    => $now,
					'message' => function_exists( 'mb_substr' ) ? mb_substr( $row['message'], 0, 1000 ) : substr( $row['message'], 0, 1000 ),
				);
				$changed        = true;
			}

			if ( $changed ) {
				if ( count( $stored ) > 50 ) {
					uasort(
						$stored,
						static function ( $a, $b ) {
							return (int) $b['time'] - (int) $a['time'];
						}
					);
					$stored = array_slice( $stored, 0, 50, true );
				}
				update_option( self::OPTION_ERRORS, $stored, false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Safe mode for this request (memoised).
	 *
	 * @return array{active: bool, reason: string}
	 */
	public static function safe_mode() {
		if ( ! isset( self::$memo['safe'] ) ) {
			$constant = ( defined( self::SAFE_MODE_CONSTANT ) && constant( self::SAFE_MODE_CONSTANT ) )
				|| ( defined( self::CS_SAFE_MODE_CONSTANT ) && constant( self::CS_SAFE_MODE_CONSTANT ) );

			$flag = isset( $_GET[ self::SAFE_MODE_QUERY ] ) && '1' === (string) $_GET[ self::SAFE_MODE_QUERY ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- compared to '1' only; it can only turn snippets OFF, for a manage_options user.

			self::$memo['safe'] = Rules::safe_mode(
				$constant,
				$flag,
				static function () {
					return function_exists( 'current_user_can' ) && current_user_can( Loader::CAPABILITY );
				}
			);
		}

		return self::$memo['safe'];
	}

	/**
	 * The enabled map (autoloaded option).
	 *
	 * @return array slug => bool
	 */
	public static function enabled_map() {
		$map = get_option( self::OPTION_ENABLED, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Snippets the Code Snippets duplicate guard holds back on this request
	 * (memoised: at most one query per request).
	 *
	 * @param array $index Catalog index.
	 * @return array<string, string> slug => reason.
	 */
	public static function held( array $index ) {
		$candidates = Rules::guard_candidates( $index['snippets'], self::enabled_map() );
		$active     = self::code_snippets_active();
		$key        = md5( wp_json_encode( $candidates ) . ( $active ? '1' : '0' ) );

		if ( ! isset( self::$memo['held'][ $key ] ) ) {
			$ids = null;
			if ( $active && ! empty( $candidates ) ) {
				$ids = self::active_code_snippets_ids( array_values( array_unique( $candidates ) ) );
			}
			self::$memo['held'][ $key ] = Rules::guard( $candidates, $active, $ids );
		}

		return self::$memo['held'][ $key ];
	}

	/**
	 * Whether the Code Snippets plugin is loaded (defined while its main
	 * file is included — active, or network-active).
	 *
	 * @return bool
	 */
	public static function code_snippets_active() {
		return defined( 'CODE_SNIPPETS_FILE' );
	}

	/**
	 * Which of these Code Snippets ids are active in {prefix}snippets —
	 * one prepared query. Null when the table cannot be read.
	 *
	 * @param int[] $ids Ids.
	 * @return int[]|null
	 */
	public static function active_code_snippets_ids( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		sort( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$table        = $wpdb->prefix . 'snippets';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE active = 1 AND id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, ids as %d placeholders; must not be cached (it decides whether code runs).
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( '' !== $error || ! is_array( $rows ) ) {
			return null;
		}

		return array_map( 'intval', $rows );
	}

	/**
	 * What happened on this request.
	 *
	 * @return array
	 */
	public static function state() {
		return self::$state;
	}

	/**
	 * Stored newest error per snippet (admin page).
	 *
	 * @return array slug => { time, message }
	 */
	public static function stored_errors() {
		$stored = get_option( self::OPTION_ERRORS, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Forget a snippet's stored error (on a switch).
	 *
	 * @param string $slug Slug.
	 */
	public static function clear_error( $slug ) {
		$stored = self::stored_errors();
		if ( isset( $stored[ $slug ] ) ) {
			unset( $stored[ $slug ] );
			update_option( self::OPTION_ERRORS, $stored, false );
		}
	}

	/**
	 * Reset per-request state (tests).
	 */
	public static function reset() {
		self::$state = array(
			'php_ran' => false,
			'results' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);
		self::$memo  = array();
		self::$queue = array();
	}

	/**
	 * A path relative to ABSPATH when inside it.
	 *
	 * @param string $file Path.
	 * @return string
	 */
	private static function relative( $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		$root = defined( 'ABSPATH' ) ? str_replace( '\\', '/', ABSPATH ) : '';

		if ( '' !== $root && 0 === strpos( $file, $root ) ) {
			return substr( $file, strlen( $root ) );
		}

		return $file;
	}
}
