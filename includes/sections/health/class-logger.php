<?php
/**
 * Health logger — pure except the Store it is given.
 *
 * log( level, source, message, context ):
 *   1. level ∈ debug|info|warning|error|critical (anything else → info);
 *      debug is dropped unless the store_debug setting is on;
 *   2. message truncated to 2000 chars; secrets scrubbed from it;
 *   3. context sanitised: keys matching pass|secret|token|key|authorization|
 *      cookie|nonce are redacted (recursively), strings that carry a Bearer
 *      / ghp_ / github_pat_ token or a Basic auth blob are scrubbed, and the
 *      JSON is capped at 8 KB;
 *   4. fingerprint = md5( level|source|message-without-digits ), so
 *      "order 123 failed" and "order 456 failed" are one fingerprint;
 *   5. the same fingerprint seen within 10 minutes bumps a `repeats`
 *      counter on the stored row instead of inserting a new one.
 *
 * The Store is duck-typed (insert / find_recent / bump_repeat) so the tests
 * give it an in-memory fake. Nothing here throws: any Store failure makes
 * log() return null.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

	/**
	 * Accepted levels, mildest first.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Message cap in characters.
	 *
	 * @var int
	 */
	const MESSAGE_MAX = 2000;

	/**
	 * Context JSON cap in bytes.
	 *
	 * @var int
	 */
	const CONTEXT_MAX_BYTES = 8192;

	/**
	 * Repeat-dedupe window in seconds.
	 *
	 * @var int
	 */
	const REPEAT_WINDOW = 600;

	/**
	 * Keys whose values are always redacted.
	 *
	 * @var string
	 */
	const SECRET_KEY_PATTERN = '/pass|secret|token|key|authorization|cookie|nonce/i';

	/**
	 * The store (insert / find_recent / bump_repeat).
	 *
	 * @var object
	 */
	private $store;

	/**
	 * Whether debug rows are stored.
	 *
	 * @var bool
	 */
	private $store_debug = false;

	/**
	 * Clock: callable returning a UNIX timestamp (tests pin it).
	 *
	 * @var callable|null
	 */
	private $clock = null;

	/**
	 * Called with the stored row after a NEW insert (not on repeats).
	 *
	 * @var callable|null
	 */
	private $on_insert = null;

	/**
	 * @param object $store    Store or fake.
	 * @param array  $settings Section settings (store_debug is read).
	 * @param mixed  $clock    Optional callable returning time().
	 */
	public function __construct( $store, array $settings = array(), $clock = null ) {
		$this->store       = $store;
		$this->store_debug = ! empty( $settings['store_debug'] );
		$this->clock       = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * Register the after-insert callback (the alert email lives there).
	 *
	 * @param callable|null $callback Callback( array $row ).
	 */
	public function set_on_insert( $callback ) {
		$this->on_insert = is_callable( $callback ) ? $callback : null;
	}

	/**
	 * Now, as a UNIX timestamp.
	 *
	 * @return int
	 */
	public function now() {
		return $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Write one entry.
	 *
	 * @param string $level   debug|info|warning|error|critical.
	 * @param string $source  Short source tag ('php', 'mail', 'theme/packing-slip', …).
	 * @param string $message Message.
	 * @param array  $context Context (sanitised before storage).
	 * @return array|null array( id, repeat => bool, row ) or null when skipped / failed.
	 */
	public function log( $level, $source, $message, array $context = array() ) {
		$level = self::normalize_level( $level );

		if ( 'debug' === $level && ! $this->store_debug ) {
			return null;
		}

		$now     = $this->now();
		$source  = self::clean_source( $source );
		$message = self::clean_message( $message );
		$context = self::sanitize_context( $context );

		$row = array(
			'logged_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			'level'       => $level,
			'source'      => $source,
			'message'     => $message,
			'context'     => $context,
			'fingerprint' => self::fingerprint( $level, $source, $message ),
		);

		try {
			$recent = $this->store->find_recent( $row['fingerprint'], $now - self::REPEAT_WINDOW );

			if ( $recent && ! empty( $recent['id'] ) ) {
				$this->store->bump_repeat( (int) $recent['id'], $row['logged_at'] );

				return array(
					'id'     => (int) $recent['id'],
					'repeat' => true,
					'row'    => $row,
				);
			}

			$id = $this->store->insert( $row );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! $id ) {
			return null;
		}

		$row['id'] = (int) $id;

		if ( $this->on_insert ) {
			try {
				call_user_func( $this->on_insert, $row );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return array(
			'id'     => (int) $id,
			'repeat' => false,
			'row'    => $row,
		);
	}

	/* ============================================================== */
	/*  Pure helpers (unit-tested)                                     */
	/* ============================================================== */

	/**
	 * A valid level, or 'info'.
	 *
	 * @param mixed $level Raw level.
	 * @return string
	 */
	public static function normalize_level( $level ) {
		$level = is_scalar( $level ) ? strtolower( trim( (string) $level ) ) : '';

		return in_array( $level, self::LEVELS, true ) ? $level : 'info';
	}

	/**
	 * A source tag: lowercase-ish, limited to [A-Za-z0-9_./-], max 60.
	 *
	 * @param mixed $source Raw source.
	 * @return string
	 */
	public static function clean_source( $source ) {
		$source = is_scalar( $source ) ? trim( (string) $source ) : '';
		$source = preg_replace( '/[^A-Za-z0-9_.\/-]+/', '-', $source );
		$source = trim( (string) $source, '-' );

		if ( '' === $source ) {
			$source = 'app';
		}

		return substr( $source, 0, 60 );
	}

	/**
	 * A single-line-ish message: scrubbed of secrets, capped at 2000 chars.
	 *
	 * @param mixed $message Raw message.
	 * @return string
	 */
	public static function clean_message( $message ) {
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = (string) json_encode( $message );
		}

		$message = self::scrub_string( (string) $message );

		if ( strlen( $message ) > self::MESSAGE_MAX ) {
			// The ellipsis is three bytes; mb_strcut never splits a character.
			$keep    = self::MESSAGE_MAX - 3;
			$message = ( function_exists( 'mb_strcut' ) ? mb_strcut( $message, 0, $keep, 'UTF-8' ) : substr( $message, 0, $keep ) ) . '…';
		}

		return $message;
	}

	/**
	 * Strip anything that looks like a credential from a string.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function scrub_string( $value ) {
		$value = (string) $value;

		$patterns = array(
			'/github_pat_[A-Za-z0-9_]{10,}/'               => '[redacted]',
			'/\bgh[pousr]_[A-Za-z0-9]{10,}/'               => '[redacted]',
			'/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i'         => 'Bearer [redacted]',
			'/\bBasic\s+[A-Za-z0-9+\/]{8,}={0,2}/'         => 'Basic [redacted]',
			'/\btoken=[A-Za-z0-9\-._~%]+/i'                => 'token=[redacted]',
			'/\b(access_token|client_secret|api_key)=[^&\s]+/i' => '$1=[redacted]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$value = (string) preg_replace( $pattern, $replacement, $value );
		}

		return $value;
	}

	/**
	 * Whether a context key names a secret.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		return is_string( $key ) && 1 === preg_match( self::SECRET_KEY_PATTERN, $key );
	}

	/**
	 * Redact secret keys recursively, scrub strings, drop objects/resources,
	 * and keep the JSON under the cap.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	public static function sanitize_context( array $context ) {
		$clean = self::redact( $context, 0 );

		if ( self::json_size( $clean ) <= self::CONTEXT_MAX_BYTES ) {
			return $clean;
		}

		// First pass: shorten every string to 200 bytes.
		$shorter = self::truncate_strings( $clean, 200 );
		if ( self::json_size( $shorter ) <= self::CONTEXT_MAX_BYTES ) {
			$shorter['_truncated'] = true;
			return $shorter;
		}

		// Still too big: keep as many top-level scalars as fit beside a
		// tail that names what was dropped, then guarantee the cap.
		$tail = array(
			'_truncated'    => true,
			'_dropped_keys' => array_values( array_slice( array_map( 'strval', array_keys( $clean ) ), 0, 50 ) ),
		);

		$budget  = self::CONTEXT_MAX_BYTES - self::json_size( $tail ) - 16;
		$scalars = array();

		foreach ( $shorter as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			$candidate         = $scalars;
			$candidate[ $key ] = $value;
			if ( self::json_size( $candidate ) > $budget ) {
				break;
			}
			$scalars = $candidate;
		}

		$out = array_merge( $scalars, $tail );

		while ( self::json_size( $out ) > self::CONTEXT_MAX_BYTES && ! empty( $out['_dropped_keys'] ) ) {
			array_pop( $out['_dropped_keys'] );
		}

		return $out;
	}

	/**
	 * The stored size of a context in bytes: what wp_json_encode() will
	 * write, which substitutes an invalid UTF-8 byte rather than failing.
	 * Anything that still cannot be encoded counts as infinitely large, so
	 * the cap logic drops it instead of treating a false encode as 0 bytes.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function json_size( $value ) {
		$flags = defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
		$json  = json_encode( $value, $flags );

		if ( false === $json && 0 !== $flags ) {
			$json = json_encode( $value );
		}

		return false === $json ? PHP_INT_MAX : strlen( $json );
	}

	/**
	 * Recursive redaction.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth guard.
	 * @return mixed
	 */
	private static function redact( $value, $depth ) {
		if ( $depth > 6 ) {
			return '[nested]';
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( self::is_secret_key( $key ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				$out[ $key ] = self::redact( $item, $depth + 1 );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return self::scrub_string( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		if ( $value instanceof \Throwable ) {
			return array(
				'class'   => get_class( $value ),
				'message' => self::scrub_string( $value->getMessage() ),
				'code'    => $value->getCode(),
			);
		}

		if ( is_object( $value ) ) {
			if ( method_exists( $value, '__toString' ) ) {
				return self::scrub_string( (string) $value );
			}
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
				return array(
					'wp_error' => $value->get_error_code(),
					'message'  => self::scrub_string( (string) $value->get_error_message() ),
				);
			}
			return '[' . get_class( $value ) . ']';
		}

		return '[' . gettype( $value ) . ']';
	}

	/**
	 * Shorten every string in a nested array to $max bytes, never splitting
	 * a UTF-8 character (a split one would make json_encode() fail).
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max string length in bytes.
	 * @return mixed
	 */
	private static function truncate_strings( $value, $max ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::truncate_strings( $item, $max );
			}
			return $value;
		}

		if ( is_string( $value ) && strlen( $value ) > $max ) {
			return ( function_exists( 'mb_strcut' ) ? mb_strcut( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max ) ) . '…';
		}

		return $value;
	}

	/**
	 * md5( level|source|message with digits removed ).
	 *
	 * @param string $level   Level.
	 * @param string $source  Source.
	 * @param string $message Message.
	 * @return string
	 */
	public static function fingerprint( $level, $source, $message ) {
		return md5( $level . '|' . $source . '|' . preg_replace( '/\d+/', '', (string) $message ) );
	}
}
