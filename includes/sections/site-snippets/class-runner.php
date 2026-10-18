<?php
/**
 * Runner — includes PHP snippet files the way Code Snippets eval()s them,
 * so code moved over behaves the same, and eval()s the stored code of a
 * database snippet (written in wp-admin, no opening tag) exactly as Code
 * Snippets does:
 *
 *   - inside a function: the snippet's top-level variables stay local to
 *     it (Code Snippets evaluates inside execute_snippet() too). The
 *     include / eval happens in an unbound static closure, so the snippet
 *     sees no $this, no class scope and no variable of ours;
 *   - its output is discarded (Code Snippets wraps eval() in
 *     ob_start() / ob_end_clean());
 *   - a Throwable is caught, handed to the error callback, and the next
 *     snippet still runs.
 *
 * What no runner can catch — a "Cannot redeclare" compile error, a fatal
 * from memory or time limits — is what the Catalog's symbol scan and
 * Loader's exists-now check keep away; another compile-stage error (a
 * parameter named twice) surfaces in the switch's test run, which dies
 * before the snippet is stored as on.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Runner
 */
class Runner {

	/**
	 * Include each file / eval each stored code, in order.
	 *
	 * @param array<int, array> $items     In run order: { slug, path } for a file,
	 *                                    { slug, code } for stored code.
	 * @param callable|null     $on_error  Called with ( slug, \Throwable ).
	 * @param callable|null     $preflight Called with the item right before
	 *                                     its include; a non-empty string it
	 *                                     returns skips the item (the reason).
	 * @return array<string, string> slug => ok|error|missing|skipped.
	 */
	public static function run( array $items, $on_error = null, $preflight = null ) {
		$include = self::includer();
		$eval    = self::evaluator();
		$results = array();

		foreach ( $items as $item ) {
			$slug    = (string) $item['slug'];
			$is_code = array_key_exists( 'code', $item );
			$path    = isset( $item['path'] ) ? (string) $item['path'] : '';

			if ( is_callable( $preflight ) && '' !== (string) call_user_func( $preflight, $item ) ) {
				$results[ $slug ] = 'skipped';
				continue;
			}

			if ( ! $is_code && ! is_file( $path ) ) {
				$results[ $slug ] = 'missing';
				if ( is_callable( $on_error ) ) {
					call_user_func( $on_error, $slug, new \RuntimeException( 'Snippet file is missing: ' . basename( $path ) ) );
				}
				continue;
			}

			$level = ob_get_level();
			ob_start();

			try {
				if ( $is_code ) {
					$eval( (string) $item['code'] );
				} else {
					$include( $path );
				}
				$results[ $slug ] = 'ok';
			} catch ( \Throwable $e ) {
				$results[ $slug ] = 'error';
				if ( is_callable( $on_error ) ) {
					try {
						call_user_func( $on_error, $slug, $e );
					} catch ( \Throwable $ignored ) {
						unset( $ignored );
					}
				}
			} finally {
				// Drop what the snippet printed, and any buffer it left open.
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
			}
		}

		return $results;
	}

	/**
	 * The include, in a closure with no $this and no class scope.
	 *
	 * @return \Closure
	 */
	private static function includer() {
		static $include = null;

		if ( null === $include ) {
			$include = \Closure::bind(
				static function () {
					// The path is read with func_get_arg() so not even a
					// parameter name leaks into the snippet's scope.
					include func_get_arg( 0 );
				},
				null,
				null
			);
		}

		return $include;
	}

	/**
	 * The eval() of stored code, in a closure with no $this and no class
	 * scope — what Code Snippets does with a snippet from its table.
	 *
	 * @return \Closure
	 */
	private static function evaluator() {
		static $eval = null;

		if ( null === $eval ) {
			$eval = \Closure::bind(
				static function () {
					// The code is read with func_get_arg() so not even a
					// parameter name leaks into the snippet's scope.
					eval( func_get_arg( 0 ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a database snippet runs as Code Snippets runs it; only an editor named in FISHOTEL_SNIPPETS_EDITORS can store one.
				},
				null,
				null
			);
		}

		return $eval;
	}
}
