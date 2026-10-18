<?php
/**
 * Symbol scanner — PURE (token_get_all): which functions and classes a PHP
 * snippet declares, whether its syntax parses, and which names two
 * snippets both declare.
 *
 * WHY: two snippets that both declare `function fh_free_shipping()` take
 * the site down with "Cannot redeclare" — a compile error no try/catch can
 * stop. The admin page reports every such name and both files are refused
 * (never loaded, their switch will not turn on). A declaration inside
 * braces (`if ( ! function_exists( 'x' ) ) { function x() … }`) is
 * "conditional": two conditional declarations are only a warning; one
 * unconditional declaration plus any other refuses both, since whichever
 * runs second would fatal.
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
	 * @return array<int, array{kind: string, name: string, display: string, conditional: bool, line: int}>
	 *         kind function|class; name = lower-case namespaced key.
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

		$out          = array();
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

		return $out;
	}

	/**
	 * Names declared by two snippets (or twice, unconditionally, by one).
	 *
	 * @param array<string, array> $by_slug slug => declared() list.
	 * @return array<int, array{kind: string, name: string, slugs: string[], refused: bool}>
	 *         refused = at least one declaration is unconditional, so loading
	 *         both would fatal; otherwise a warning only.
	 */
	public static function conflicts( array $by_slug ) {
		$groups = array();

		ksort( $by_slug );
		foreach ( $by_slug as $slug => $list ) {
			foreach ( (array) $list as $decl ) {
				$key = $decl['kind'] . ':' . $decl['name'];
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'kind'          => $decl['kind'],
						'name'          => $decl['display'],
						'slugs'         => array(),
						'unconditional' => 0,
						'count'         => 0,
					);
				}
				$groups[ $key ]['slugs'][ (string) $slug ] = true;
				$groups[ $key ]['count']++;
				if ( empty( $decl['conditional'] ) ) {
					$groups[ $key ]['unconditional']++;
				}
			}
		}

		$out = array();
		foreach ( $groups as $group ) {
			$slugs = array_keys( $group['slugs'] );

			if ( count( $slugs ) >= 2 ) {
				$refused = $group['unconditional'] > 0;
			} elseif ( $group['unconditional'] >= 2 ) {
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
			'conditional' => in_array( 'other', $stack, true ),
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
