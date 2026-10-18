<?php
/**
 * Symbol scanner — PURE (token_get_all): which functions and classes a PHP
 * snippet declares, whether its syntax parses, and which names two
 * snippets both declare.
 *
 * WHY: two snippets that both declare `function fh_free_shipping()` take
 * the site down with "Cannot redeclare" — a compile error no try/catch can
 * stop. The admin page reports every such name and both files are refused
 * (never loaded, their switch will not turn on). A declaration is
 * "guarded" only when it sits inside braces AND the file calls
 * function_exists() (class_exists(), interface_exists(), trait_exists(),
 * enum_exists() for a class) with that exact name as a string — Code
 * Snippets' own exemption. Any other `if` (`if ( ! is_admin() )`) guards
 * nothing: both copies can run on the same request. Two guarded
 * declarations are only a warning; an unguarded one plus any other
 * refuses both, since whichever runs second would fatal.
 *
 * Names are compared case-insensitively with their namespace, as PHP does.
 * Methods, closures, arrow functions, `use function`, `Foo::class` and
 * anonymous classes are not declarations.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Symbols
 */
class Symbols {

	/**
	 * The first syntax error in a PHP file, parsed (never run) with
	 * TOKEN_PARSE by the PHP version serving the site.
	 *
	 * @param string $code Whole file text.
	 * @return array{error: string, line: int} error '' = parses.
	 */
	public static function syntax_error( $code ) {
		try {
			token_get_all( (string) $code, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return array(
				'error' => $e->getMessage(),
				'line'  => (int) $e->getLine(),
			);
		}

		return array(
			'error' => '',
			'line'  => 0,
		);
	}

	/**
	 * Functions and classes (interfaces, traits, enums) a PHP file declares.
	 *
	 * @param string $code Whole file text.
	 * @return array<int, array{kind: string, name: string, display: string, nested: bool, guarded: bool, line: int}>
	 *         kind function|class; name = lower-case namespaced key;
	 *         nested = inside braces (declared only when that code runs);
	 *         guarded = nested and the file checks function_exists() /
	 *         class_exists() … for this exact name.
	 */
	public static function declared( $code ) {
		$tokens = token_get_all( (string) $code );
		$count  = count( $tokens );

		$class_kinds = array( T_CLASS, T_INTERFACE, T_TRAIT );
		if ( defined( 'T_ENUM' ) ) {
			$class_kinds[] = constant( 'T_ENUM' );
		}

		$name_parts = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$name_parts[] = constant( 'T_NAME_QUALIFIED' );
		}

		$exists_calls = array(
			'function_exists'  => 'function',
			'class_exists'     => 'class',
			'interface_exists' => 'class',
			'trait_exists'     => 'class',
			'enum_exists'      => 'class',
		);
		$call_names   = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$call_names[] = constant( 'T_NAME_FULLY_QUALIFIED' ); // `\function_exists` on PHP 8.
		}

		$out          = array();
		$guards       = array( 'function' => array(), 'class' => array() ); // kind => name => true.
		$namespace    = '';
		$stack        = array(); // One entry per open brace: ns|class|other.
		$parens       = 0;
		$pending      = '';      // What the next "{" opens: ns|class|''.
		$pending_at   = 0;       // Paren depth at which $pending was set.
		$prev         = null;    // Previous significant token id (or char).

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) ) {
				$id = $token[0];

				if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
					continue;
				}

				if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
					$stack[] = 'other';
				} elseif ( in_array( $id, $call_names, true ) && isset( $exists_calls[ strtolower( ltrim( $token[1], '\\' ) ) ] )
					&& T_FUNCTION !== $prev && T_OBJECT_OPERATOR !== $prev && T_DOUBLE_COLON !== $prev ) {
					$target = self::exists_target( $tokens, $i, $namespace );
					if ( '' !== $target ) {
						$guards[ $exists_calls[ strtolower( ltrim( $token[1], '\\' ) ) ] ][ $target ] = true;
					}
				} elseif ( T_NAMESPACE === $id ) {
					$next = self::next_significant( $tokens, $i );
					// `namespace\foo()` is a relative name, not a declaration.
					if ( null !== $next && ! ( is_array( $tokens[ $next ] ) && T_NS_SEPARATOR === $tokens[ $next ][0] ) ) {
						$name = '';
						for ( $j = $i + 1; $j < $count; $j++ ) {
							$t = $tokens[ $j ];
							if ( is_array( $t ) && in_array( $t[0], $name_parts, true ) ) {
								$name .= $t[1];
							} elseif ( is_array( $t ) && ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
								continue;
							} else {
								break;
							}
						}
						$namespace  = trim( $name, '\\' );
						$pending    = 'ns';
						$pending_at = $parens;
						$i          = $j - 1;
					}
				} elseif ( T_FUNCTION === $id && T_USE !== $prev ) {
					$next = self::next_significant( $tokens, $i );
					// `function &name()`: a plain "&" before PHP 8.1, an
					// ampersand token after.
					if ( null !== $next && '&' === ( is_array( $tokens[ $next ] ) ? $tokens[ $next ][1] : $tokens[ $next ] ) ) {
						$next = self::next_significant( $tokens, $next );
					}
					if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] && ! in_array( 'class', $stack, true ) ) {
						$out[] = self::entry( 'function', $namespace, $tokens[ $next ][1], $stack, $tokens[ $next ][2] );
					}
				} elseif ( in_array( $id, $class_kinds, true ) && T_DOUBLE_COLON !== $prev ) {
					if ( T_NEW === $prev ) {
						// Anonymous class: its body is a class body, but no name.
						$pending    = 'class';
						$pending_at = $parens;
					} else {
						$next = self::next_significant( $tokens, $i );
						if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
							$out[]      = self::entry( 'class', $namespace, $tokens[ $next ][1], $stack, $tokens[ $next ][2] );
							$pending    = 'class';
							$pending_at = $parens;
						}
					}
				}

				$prev = $id;
				continue;
			}

			// Single-character tokens.
			if ( '{' === $token ) {
				if ( '' !== $pending && $parens === $pending_at ) {
					$stack[] = $pending;
					$pending = '';
				} else {
					$stack[] = 'other';
				}
			} elseif ( '}' === $token ) {
				array_pop( $stack );
			} elseif ( '(' === $token ) {
				$parens++;
			} elseif ( ')' === $token ) {
				$parens = max( 0, $parens - 1 );
			} elseif ( ';' === $token && 'ns' === $pending ) {
				$pending = '';
			}

			$prev = $token;
		}

		foreach ( $out as $n => $decl ) {
			$out[ $n ]['guarded'] = $decl['nested'] && isset( $guards[ $decl['kind'] ][ $decl['name'] ] );
		}

		return $out;
	}

	/**
	 * The name an exists check at $i tests (`function_exists( 'x' )`,
	 * `class_exists( __NAMESPACE__ . '\\X' )`): the first string literal
	 * in its parentheses, lower-case and namespaced like declared() names;
	 * '' when it is not a call or names no literal.
	 *
	 * @param array  $tokens    Tokens.
	 * @param int    $i         Index of the function name.
	 * @param string $namespace Current namespace.
	 * @return string
	 */
	private static function exists_target( array $tokens, $i, $namespace ) {
		$open = self::next_significant( $tokens, $i );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			return '';
		}

		$count  = count( $tokens );
		$depth  = 0;
		$ns_ref = false;
		for ( $j = $open; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t ) {
				$depth++;
			} elseif ( ')' === $t && 0 === --$depth ) {
				return '';
			} elseif ( is_array( $t ) && T_NS_C === $t[0] ) {
				$ns_ref = true;
			} elseif ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$name = str_replace( '\\\\', '\\', substr( $t[1], 1, -1 ) );
				$name = ltrim( $name, '\\' );
				if ( $ns_ref && '' !== $namespace ) {
					$name = $namespace . '\\' . $name;
				}
				return strtolower( $name );
			}
		}

		return '';
	}

	/**
	 * Names declared by two snippets, or by one in two places that can both
	 * run (twice at top level, or at top level and again unguarded inside
	 * braces — `if / else` branches of one file are fine).
	 *
	 * @param array<string, array> $by_slug slug => declared() list.
	 * @return array<int, array{kind: string, name: string, slugs: string[], refused: bool}>
	 *         refused = a declaration is unguarded, so loading both would
	 *         fatal; otherwise (every one guarded) a warning only.
	 */
	public static function conflicts( array $by_slug ) {
		$groups = array();

		ksort( $by_slug );
		foreach ( $by_slug as $slug => $list ) {
			foreach ( (array) $list as $decl ) {
				$key = $decl['kind'] . ':' . $decl['name'];
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'kind'      => $decl['kind'],
						'name'      => $decl['display'],
						'slugs'     => array(),
						'unguarded' => 0,
						'top'       => 0,
					);
				}
				$groups[ $key ]['slugs'][ (string) $slug ] = true;
				if ( empty( $decl['guarded'] ) ) {
					$groups[ $key ]['unguarded']++;
				}
				if ( empty( $decl['nested'] ) ) {
					$groups[ $key ]['top']++;
				}
			}
		}

		$out = array();
		foreach ( $groups as $group ) {
			$slugs = array_keys( $group['slugs'] );

			if ( count( $slugs ) >= 2 ) {
				$refused = $group['unguarded'] > 0;
			} elseif ( $group['top'] >= 1 && $group['unguarded'] >= 2 ) {
				$refused = true;
			} else {
				continue;
			}

			$out[] = array(
				'kind'    => $group['kind'],
				'name'    => $group['name'],
				'slugs'   => $slugs,
				'refused' => $refused,
			);
		}

		return $out;
	}

	/**
	 * Whether a declared name already exists in this request (another
	 * plugin, the theme or Code Snippets declared it first) — loading the
	 * snippet would then fatal with "Cannot redeclare".
	 *
	 * @param array $decl declared() entry.
	 * @return bool
	 */
	public static function exists_now( array $decl ) {
		$name = (string) $decl['display'];

		if ( 'function' === $decl['kind'] ) {
			return function_exists( $name );
		}

		return class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false )
			|| ( function_exists( 'enum_exists' ) && enum_exists( $name, false ) );
	}

	/**
	 * Build one declared() entry.
	 *
	 * @param string $kind      function|class.
	 * @param string $namespace Current namespace ('' = global).
	 * @param string $short     Declared name.
	 * @param array  $stack     Brace stack.
	 * @param int    $line      Line.
	 * @return array
	 */
	private static function entry( $kind, $namespace, $short, array $stack, $line ) {
		$display = '' === $namespace ? $short : $namespace . '\\' . $short;

		return array(
			'kind'        => $kind,
			'name'        => strtolower( $display ),
			'display'     => $display,
			'nested'      => in_array( 'other', $stack, true ),
			'guarded'     => false, // Set by declared() once every exists check is known.
			'line'        => (int) $line,
		);
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Start index (exclusive).
	 * @return int|null
	 */
	private static function next_significant( array $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) ) {
				continue;
			}
			return $j;
		}
		return null;
	}
}
