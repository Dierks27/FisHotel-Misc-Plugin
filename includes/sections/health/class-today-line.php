<?php
/**
 * The Health line on the Today page and its widget (the Today section's
 * `fishotel_today_lines` filter): "Site health: 2 problems found", with
 * the errors and warnings counted beside it and a link to the Overview.
 * The same checks the Health widget runs (Facts::collect() +
 * Checks::evaluate()), counting only what the owner can act on
 * (Checks::owner_rows()) — nothing is sent anywhere.
 *
 * Shown only while this section is switched on (boot() hooks it, in
 * wp-admin only), for a user who may open the section (manage_options),
 * and only when there is at least one error or warning. Worked out once
 * per request.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Today_Line
 */
class Today_Line {

	/**
	 * The Today section's filter, this line's key and its heading there.
	 *
	 * @var string
	 */
	const FILTER = 'fishotel_today_lines';
	const KEY    = 'health';
	const GROUP  = 'site';

	/**
	 * Where the counts come from (null = counts()); a test hands in its
	 * own.
	 *
	 * @var callable|null
	 */
	private static $source = null;

	/**
	 * The line worked out once for this request (array( line|null )).
	 *
	 * @var array|null
	 */
	private static $cached = null;

	/**
	 * Hook the line (boot(), wp-admin only).
	 */
	public static function register() {
		add_filter( self::FILTER, array( self::class, 'filter' ), 20, 1 );
	}

	/**
	 * Use another source for the counts, and forget the cached line.
	 *
	 * @param callable|null $source Returns what counts() returns.
	 */
	public static function use_source( $source = null ) {
		self::$source = is_callable( $source ) ? $source : null;
		self::$cached = null;
	}

	/**
	 * The line (pure): null when every check is fine.
	 *
	 * @param array  $counts errors, warnings (Checks::summary()).
	 * @param string $url    The Health overview.
	 * @return array|null
	 */
	public static function line( array $counts, $url ) {
		$errors   = max( 0, (int) ( $counts['errors'] ?? 0 ) );
		$warnings = max( 0, (int) ( $counts['warnings'] ?? 0 ) );
		$total    = $errors + $warnings;
		if ( $total <= 0 ) {
			return null;
		}

		$parts = array();
		if ( $errors > 0 ) {
			/* translators: %d: errors */
			$parts[] = sprintf( _n( '%d error', '%d errors', $errors, 'fishotel-misc-plugin' ), $errors );
		}
		if ( $warnings > 0 ) {
			/* translators: %d: warnings */
			$parts[] = sprintf( _n( '%d warning', '%d warnings', $warnings, 'fishotel-misc-plugin' ), $warnings );
		}

		return array(
			'key'    => self::KEY,
			'group'  => self::GROUP,
			/* translators: %d: problems */
			'text'   => sprintf( _n( 'Site health: %d problem found', 'Site health: %d problems found', $total, 'fishotel-misc-plugin' ), $total ),
			'detail' => implode( ' · ', $parts ),
			'url'    => (string) $url,
			'count'  => $total,
			'tone'   => $errors > 0 ? 'bad' : 'todo',
		);
	}

	/**
	 * The counts: the Health checks as the widget runs them, without the
	 * ones the owner has nothing to do about (Checks::owner_rows()).
	 *
	 * @return array
	 */
	private static function counts() {
		$facts = Facts::collect( new Store() );
		return Checks::summary( Checks::owner_rows( Checks::evaluate( $facts, $facts['now'] ) ) );
	}

	/**
	 * Where the line leads: the Health overview.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg( array( 'page' => Health::MENU_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * The lines with this one added (pure): a line under the same key is
	 * replaced, never doubled; no line leaves the others untouched.
	 *
	 * @param mixed      $lines What the filter carries so far.
	 * @param array|null $line  line().
	 * @return array[]
	 */
	public static function with_line( $lines, $line ) {
		$out = array();
		foreach ( is_array( $lines ) ? $lines : array() as $existing ) {
			if ( is_array( $existing ) && self::KEY === (string) ( $existing['key'] ?? '' ) ) {
				continue;
			}
			$out[] = $existing;
		}
		if ( is_array( $line ) ) {
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Filter callback: the Today lines gain this section's one, for a
	 * user who may open it. Whatever goes wrong adds nothing.
	 *
	 * @param mixed $lines The lines so far.
	 * @return array[]
	 */
	public static function filter( $lines = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Health::CAPABILITY ) ) {
			return $lines;
		}

		if ( null === self::$cached ) {
			try {
				$counts       = null !== self::$source ? call_user_func( self::$source ) : self::counts();
				self::$cached = array( self::line( is_array( $counts ) ? $counts : array(), self::url() ) );
			} catch ( \Throwable $e ) {
				self::$cached = array( null );
			}
		}

		return self::with_line( $lines, self::$cached[0] );
	}
}
