<?php
/**
 * Health capture — the hooks that feed the logger.
 *
 *   - a shutdown handler that turns the last fatal (E_ERROR, E_PARSE,
 *     E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR)
 *     into a critical row, source 'php', with the file path relative to
 *     ABSPATH;
 *   - wp_mail_failed (WP_Error) → error, source 'mail', with the recipient
 *     list and subject — never the body or headers;
 *   - fhsmtp_email_failed( $connection ) from the FisHotel SMTP plugin →
 *     error, source 'smtp';
 *   - http_api_debug for the GitHub hosts (and any host in the settings
 *     list): a WP_Error or a 4xx → error, a 5xx → warning, source
 *     'updater', message "GitHub returned 401 for api.github.com/repos/…"
 *     with the query string removed and no header or token anywhere; a
 *     401/403 from a GitHub host (only — an extra host's 401 is just its
 *     own row) also sets the fishotel_health_github_auth_failed transient
 *     the checks read;
 *   - do_action( 'fishotel_log', $level, $source, $message, $context ) so
 *     any plugin or theme can log without depending on this class;
 *   - the global fishotel_log() helper (defined only if nothing else did).
 *
 * The event → row translation is in pure static methods (fatal_row,
 * mail_row, smtp_row, http_row) the tests call directly; the hook
 * callbacks are thin wrappers that swallow every Throwable. A depth
 * counter allows exactly ONE nested capture — so the alert email that
 * fails while a row is being stored is itself logged as a 'mail' row (and
 * the SMTP plugin's fhsmtp_email_failed for it as an 'smtp' row) — and
 * cuts anything deeper, so a capture that triggers a capture can never
 * loop; Settings::$sending is what stops a failed alert re-alerting.
 *
 * The file uses bracketed namespaces because the helper must live in the
 * global namespace.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Capture
	 */
	class Capture {

		/**
		 * Fatal error types the shutdown handler reports.
		 *
		 * @var int
		 */
		const FATAL_TYPES = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

		/**
		 * Hosts always watched by the http_api_debug capture.
		 *
		 * @var string[]
		 */
		const GITHUB_HOSTS = array(
			'github.com',
			'api.github.com',
			'raw.githubusercontent.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
		);

		/**
		 * The logger.
		 *
		 * @var Logger|null
		 */
		private static $logger = null;

		/**
		 * Extra hosts from the settings.
		 *
		 * @var string[]
		 */
		private static $extra_hosts = array();

		/**
		 * Nesting depth of emit(): 0 idle, 1 handling an event, 2 handling an
		 * event raised while storing that event's row (allowed once).
		 *
		 * @var int
		 */
		private static $depth = 0;

		/**
		 * Deepest emit() that is still stored.
		 *
		 * @var int
		 */
		const MAX_DEPTH = 2;

		/**
		 * Register every hook.
		 *
		 * @param Logger $logger   Logger.
		 * @param array  $settings Section settings.
		 */
		public static function init( Logger $logger, array $settings ) {
			self::$logger      = $logger;
			self::$extra_hosts = isset( $settings['extra_hosts'] ) && is_array( $settings['extra_hosts'] ) ? $settings['extra_hosts'] : array();

			if ( ! empty( $settings['capture_fatals'] ) ) {
				register_shutdown_function( array( self::class, 'on_shutdown' ) );
			}

			add_action( 'wp_mail_failed', array( self::class, 'on_mail_failed' ) );
			add_action( 'fhsmtp_email_failed', array( self::class, 'on_smtp_failed' ) );

			if ( ! empty( $settings['capture_http'] ) ) {
				add_action( 'http_api_debug', array( self::class, 'on_http_api_debug' ), 10, 5 );
			}

			add_action( 'fishotel_log', array( self::class, 'on_fishotel_log' ), 10, 4 );
		}

		/**
		 * Hand a row to the logger, guarded.
		 *
		 * @param array|null $row level, source, message, context.
		 * @return array|null Logger result.
		 */
		private static function emit( $row ) {
			if ( ! $row || ! self::$logger || self::$depth >= self::MAX_DEPTH ) {
				return null;
			}

			self::$depth++;

			try {
				$result = self::$logger->log( $row['level'], $row['source'], $row['message'], isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array() );
			} catch ( \Throwable $e ) {
				$result = null;
			} finally {
				self::$depth--;
			}

			return $result;
		}

		/* ============================================================== */
		/*  Hook callbacks (thin)                                          */
		/* ============================================================== */

		/**
		 * Shutdown: report the last fatal. Skipped under WP-CLI, which
		 * reports its own errors on the terminal.
		 */
		public static function on_shutdown() {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}

			try {
				$error = error_get_last();
				self::emit( self::fatal_row( is_array( $error ) ? $error : null ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * wp_mail_failed.
		 *
		 * @param mixed $error WP_Error.
		 */
		public static function on_mail_failed( $error ) {
			try {
				self::emit( self::mail_row( $error ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * fhsmtp_email_failed( $connection ).
		 *
		 * @param mixed $connection 'primary' | 'backup'.
		 */
		public static function on_smtp_failed( $connection = '' ) {
			try {
				self::emit( self::smtp_row( $connection ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * http_api_debug( $response, $context, $class, $args, $url ).
		 *
		 * @param mixed  $response Array or WP_Error.
		 * @param string $context  'response'.
		 * @param string $class    Transport class.
		 * @param array  $args     Request args.
		 * @param string $url      URL.
		 */
		public static function on_http_api_debug( $response, $context, $class, $args, $url ) {
			try {
				$row = self::http_row( $response, $context, $class, is_array( $args ) ? $args : array(), (string) $url, self::$extra_hosts );

				if ( ! $row ) {
					return;
				}

				if ( ! empty( $row['auth_failed'] ) && function_exists( 'set_transient' ) ) {
					set_transient(
						Health::TRANSIENT_GITHUB_AUTH,
						array(
							'host' => $row['context']['host'],
							'code' => $row['context']['code'],
							'time' => time(),
						),
						DAY_IN_SECONDS
					);
				}

				self::emit( $row );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * do_action( 'fishotel_log', $level, $source, $message, $context ).
		 *
		 * @param string $level   Level.
		 * @param string $source  Source.
		 * @param string $message Message.
		 * @param mixed  $context Context array.
		 */
		public static function on_fishotel_log( $level, $source = 'app', $message = '', $context = array() ) {
			try {
				self::emit(
					array(
						'level'   => $level,
						'source'  => $source,
						'message' => $message,
						'context' => is_array( $context ) ? $context : array( 'context' => $context ),
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/* ============================================================== */
		/*  Pure translations (unit-tested)                                */
		/* ============================================================== */

		/**
		 * A critical row for a fatal, or null for anything else.
		 *
		 * @param array|null $error error_get_last() shape: type, message, file, line.
		 * @return array|null
		 */
		public static function fatal_row( $error ) {
			if ( ! is_array( $error ) || empty( $error['type'] ) ) {
				return null;
			}

			$type = (int) $error['type'];

			if ( 0 === ( $type & self::FATAL_TYPES ) ) {
				return null;
			}

			$file = self::relative_path( (string) ( $error['file'] ?? '' ) );
			$line = (int) ( $error['line'] ?? 0 );

			// The first line of the message is the error; the trace that
			// follows ("Stack trace: #0 …") is noise in the headline.
			$message = trim( (string) ( $error['message'] ?? '' ) );
			$first   = strtok( $message, "\n" );
			$first   = false === $first ? $message : $first;

			return array(
				'level'   => 'critical',
				'source'  => 'php',
				'message' => sprintf( '%s: %s in %s:%d', self::error_type_label( $type ), $first, $file, $line ),
				'context' => array(
					'type' => $type,
					'file' => $file,
					'line' => $line,
					'uri'  => isset( $_SERVER['REQUEST_URI'] ) ? self::strip_query( (string) $_SERVER['REQUEST_URI'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				),
			);
		}

		/**
		 * Human label for an error type constant.
		 *
		 * @param int $type E_* constant.
		 * @return string
		 */
		public static function error_type_label( $type ) {
			$labels = array(
				E_ERROR             => 'E_ERROR',
				E_PARSE             => 'E_PARSE',
				E_CORE_ERROR        => 'E_CORE_ERROR',
				E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
				E_USER_ERROR        => 'E_USER_ERROR',
				E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
				E_WARNING           => 'E_WARNING',
				E_NOTICE            => 'E_NOTICE',
				E_DEPRECATED        => 'E_DEPRECATED',
			);

			return $labels[ (int) $type ] ?? ( 'E_' . (int) $type );
		}

		/**
		 * A path relative to ABSPATH (forward slashes), or as given.
		 *
		 * @param string $path Absolute path.
		 * @return string
		 */
		public static function relative_path( $path ) {
			$path = str_replace( '\\', '/', (string) $path );

			if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
				$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
				if ( 0 === strpos( $path, $root ) ) {
					return substr( $path, strlen( $root ) );
				}
			}

			return $path;
		}

		/**
		 * An error row for a wp_mail failure: recipients + subject, never
		 * the body or headers.
		 *
		 * @param mixed $error WP_Error.
		 * @return array|null
		 */
		public static function mail_row( $error ) {
			if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_message' ) ) {
				return null;
			}

			$data = method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : array();
			$data = is_array( $data ) ? $data : array();

			$to = $data['to'] ?? array();
			if ( is_string( $to ) ) {
				$to = array_map( 'trim', explode( ',', $to ) );
			}
			$to = array_values( array_filter( array_map( 'strval', (array) $to ) ) );

			$message = trim( (string) $error->get_error_message() );
			if ( '' === $message ) {
				$message = 'wp_mail failed';
			}

			return array(
				'level'   => 'error',
				'source'  => 'mail',
				'message' => $message,
				'context' => array(
					'to'      => $to,
					'subject' => (string) ( $data['subject'] ?? '' ),
					'code'    => (string) ( method_exists( $error, 'get_error_code' ) ? $error->get_error_code() : '' ),
				),
			);
		}

		/**
		 * An error row for an SMTP failover failure.
		 *
		 * @param mixed $connection 'primary' | 'backup'.
		 * @return array
		 */
		public static function smtp_row( $connection ) {
			$connection = is_scalar( $connection ) ? (string) $connection : '';
			if ( '' === $connection ) {
				$connection = 'unknown';
			}

			return array(
				'level'   => 'error',
				'source'  => 'smtp',
				'message' => sprintf( 'SMTP send failed on the %s connection', $connection ),
				'context' => array( 'connection' => $connection ),
			);
		}

		/**
		 * A row for an HTTP failure against a watched host, or null.
		 *
		 * @param mixed    $response    Array (WP_HTTP shape) or WP_Error.
		 * @param string   $context     'response' for completed requests.
		 * @param string   $class       Transport class.
		 * @param array    $args        Request args (only 'method' is read).
		 * @param string   $url         URL.
		 * @param string[] $extra_hosts Hosts from the settings.
		 * @return array|null level, source, message, context, auth_failed.
		 */
		public static function http_row( $response, $context, $class, array $args, $url, array $extra_hosts = array() ) {
			unset( $class );

			if ( 'response' !== (string) $context ) {
				return null;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || ! self::is_watched_host( $host, $extra_hosts ) ) {
				return null;
			}

			$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
			$target = $host . $path;
			$label  = self::is_github_host( $host ) ? 'GitHub' : $host;
			$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

			$is_error = is_object( $response ) && method_exists( $response, 'get_error_message' ) && ( ! function_exists( 'is_wp_error' ) || is_wp_error( $response ) );

			if ( $is_error ) {
				return array(
					'level'       => 'error',
					'source'      => 'updater',
					'message'     => sprintf( '%s request failed for %s: %s', $label, $target, Logger::scrub_string( (string) $response->get_error_message() ) ),
					'context'     => array(
						'host'   => $host,
						'path'   => $path,
						'method' => $method,
						'code'   => 0,
						'error'  => (string) $response->get_error_code(),
					),
					'auth_failed' => false,
				);
			}

			$code = 0;
			if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
				$code = (int) $response['response']['code'];
			}

			if ( $code < 400 ) {
				return null;
			}

			// Only GitHub's own 401/403 means "the updater token is bad"; an
			// extra watched host (EasyPost, worldpressit…) gets its row and
			// nothing more.
			$auth_failed = self::is_github_host( $host ) && ( 401 === $code || 403 === $code );

			return array(
				'level'       => $code >= 500 ? 'warning' : 'error',
				'source'      => 'updater',
				'message'     => sprintf( '%s returned %d for %s', $label, $code, $target ),
				'context'     => array(
					'host'   => $host,
					'path'   => $path,
					'method' => $method,
					'code'   => $code,
				),
				'auth_failed' => $auth_failed,
			);
		}

		/**
		 * Whether a host is one of GitHub's.
		 *
		 * @param string $host Host.
		 * @return bool
		 */
		public static function is_github_host( $host ) {
			return in_array( strtolower( (string) $host ), self::GITHUB_HOSTS, true );
		}

		/**
		 * Whether a host is watched (GitHub or in the settings list).
		 *
		 * @param string   $host        Host.
		 * @param string[] $extra_hosts Settings list.
		 * @return bool
		 */
		public static function is_watched_host( $host, array $extra_hosts = array() ) {
			$host = strtolower( (string) $host );

			if ( self::is_github_host( $host ) ) {
				return true;
			}

			foreach ( $extra_hosts as $extra ) {
				$extra = strtolower( trim( (string) $extra ) );
				if ( '' !== $extra && $extra === $host ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * A URI without its query string or fragment.
		 *
		 * @param string $uri URI.
		 * @return string
		 */
		public static function strip_query( $uri ) {
			$uri = (string) $uri;
			$cut = strpos( $uri, '?' );
			if ( false !== $cut ) {
				$uri = substr( $uri, 0, $cut );
			}
			$cut = strpos( $uri, '#' );
			if ( false !== $cut ) {
				$uri = substr( $uri, 0, $cut );
			}

			return $uri;
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_log' ) ) {
		/**
		 * Log to the FisHotel Health log from any plugin or theme.
		 *
		 * This function exists ONLY while the Misc plugin is active, the
		 * Health section is enabled and plugins_loaded has run — with the
		 * section toggled off (or the plugin gone) it is undefined and a bare
		 * call fatals. Callers in other plugins and the theme must therefore
		 * either guard it with function_exists( 'fishotel_log' ) or fire
		 * do_action( 'fishotel_log', … ) directly, which is always safe and
		 * simply does nothing when nobody listens.
		 *
		 * @param string $level   debug|info|warning|error|critical.
		 * @param string $source  Short source tag, e.g. 'theme/packing-slip'.
		 * @param string $message Message.
		 * @param array  $context Context (secrets are redacted on storage).
		 */
		function fishotel_log( $level, $source, $message, $context = array() ) {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'fishotel_log', $level, $source, $message, is_array( $context ) ? $context : array( 'context' => $context ) );
			}
		}
	}
}
