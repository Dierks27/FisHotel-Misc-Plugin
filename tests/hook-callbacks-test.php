<?php
/**
 * Hook callbacks — every callback this plugin hands to WordPress names a
 * method or a function that exists. Static: the PHP tokenizer reads the
 * files, nothing is loaded, no WordPress, no database.
 *
 * Run from the plugin root:
 *   php tests/hook-callbacks-test.php
 *
 * Why: a hook whose callback names a method that does not exist is a
 * fatal TypeError on PHP 8 the moment the hook fires, and nothing shows
 * until then. Every section's class file is loaded even while the
 * section is off, but its hooks only fire once it is on, so a typo in a
 * switched-off section waits silently for the day the owner switches it
 * on. The theme's v1.24.0 shipped exactly this on checkout.
 *
 * What is checked (across fishotel-misc-plugin.php and includes/, tests
 * folders skipped):
 *   add_action, add_filter, register_activation_hook,
 *   register_deactivation_hook, register_uninstall_hook, add_shortcode,
 *   add_menu_page, add_submenu_page, add_options_page,
 *   add_management_page, wp_add_dashboard_widget, add_meta_box,
 *   register_shutdown_function, set_error_handler, set_exception_handler,
 *   spl_autoload_register, and the callback / permission_callback keys of
 *   register_rest_route. wp_ajax_* and admin_post_* are add_action.
 *
 * How a callback is read:
 *   array( $this | self::class | static::class | __CLASS__, 'm' )
 *                                  → method m of the class around the call
 *   array( Name::class, 'm' ), array( new Name(), 'm' )
 *                                  → Name resolved through the file's
 *                                    namespace and use lines
 *   array( $var, 'm' ) / array( $this->prop, 'm' )
 *                                  → the class of `$var = new Name(` (or
 *                                    `$this->prop = new Name(`) in the
 *                                    same file, when there is exactly one
 *   array( X, 'prefix_' . $op ) inside foreach ( self::LIST as $op )
 *                                  → every name the constant LIST gives
 *   'function', __NAMESPACE__ . '\\function', 'Class::method'
 *                                  → a function of this plugin, of PHP,
 *                                    or one of core's __return_* helpers
 *   closures, arrow functions      → fine as they are
 * A method is found on the class or on a parent class of this plugin.
 * It must be public (WordPress calls it from outside the class), and
 * static when the callback names the class rather than an object
 * (self::class, static::class, __CLASS__, Name::class, 'Class::method');
 * either mistake is a fatal Error on PHP 8 when the hook fires. A parent
 * from WordPress that is loaded is asked through ReflectionMethod.
 * Anything the reader cannot work out is a failure too, unless listed in
 * $fh_allowed with the reason — so the check cannot quietly go blind.
 *
 * The first block plants a file with a missing method (and the other
 * ways a callback goes wrong) in a temporary folder and proves each one
 * is caught.
 *
 * @package FisHotel\Misc
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * Hook-taking functions and which arguments (0-based) are callbacks.
 */
const FH_HOOK_FUNCTIONS = array(
	'add_action'                 => array( 1 ),
	'add_filter'                 => array( 1 ),
	'register_activation_hook'   => array( 1 ),
	'register_deactivation_hook' => array( 1 ),
	'register_uninstall_hook'    => array( 1 ),
	'add_shortcode'              => array( 1 ),
	'add_menu_page'              => array( 4 ),
	'add_submenu_page'           => array( 5 ),
	'add_options_page'           => array( 4 ),
	'add_management_page'        => array( 4 ),
	'wp_add_dashboard_widget'    => array( 2, 3 ),
	'add_meta_box'               => array( 2 ),
	'register_shutdown_function' => array( 0 ),
	'set_error_handler'          => array( 0 ),
	'set_exception_handler'      => array( 0 ),
	'spl_autoload_register'      => array( 0 ),
	'register_rest_route'        => array(),
);

/**
 * Core's always-there "return a constant" helpers.
 */
const FH_CORE_FUNCTIONS = array( '__return_true', '__return_false', '__return_null', '__return_zero', '__return_empty_array', '__return_empty_string' );

/**
 * Tokens without whitespace and comments, each as array( id, text, line ).
 *
 * @param string $src PHP source.
 * @return array[]
 */
function fh_tokens( $src ) {
	$out = array();
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out[] = array( $t[0], $t[1], $t[2] );
		} else {
			$out[] = array( 0, $t, 0 );
		}
	}
	return $out;
}

/**
 * A name token's text (PHP 8 gives qualified names as one token).
 *
 * @param array $t Token.
 * @return bool
 */
function fh_is_name( array $t ) {
	$ids = array( T_STRING );
	foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $c ) {
		if ( defined( $c ) ) {
			$ids[] = constant( $c );
		}
	}
	return in_array( $t[0], $ids, true );
}

/**
 * A string literal's value, or null.
 *
 * @param array $t Token.
 * @return string|null
 */
function fh_literal( array $t ) {
	if ( T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
		return null;
	}
	$q   = $t[1][0];
	$raw = substr( $t[1], 1, -1 );
	return "'" === $q ? str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $raw ) : stripcslashes( $raw );
}

/**
 * Resolve a class name against a namespace and its use lines.
 *
 * @param string $name Name as written.
 * @param string $ns   Namespace.
 * @param array  $uses alias (lowercase) => FQCN.
 * @return string FQCN without a leading backslash.
 */
function fh_resolve( $name, $ns, array $uses ) {
	if ( '\\' === $name[0] ) {
		return ltrim( $name, '\\' );
	}
	$parts = explode( '\\', $name );
	$first = strtolower( $parts[0] );
	if ( isset( $uses[ $first ] ) ) {
		$parts[0] = $uses[ $first ];
		return implode( '\\', $parts );
	}
	return '' !== $ns ? $ns . '\\' . $name : $name;
}

/**
 * Read one file: its classes (methods, parent, constants), its functions,
 * and every hook call with its callback arguments as token lists.
 *
 * @param string $src PHP source.
 * @return array{classes: array, functions: string[], calls: array[]}
 */
function fh_read_file( $src ) {
	$tk        = fh_tokens( $src );
	$n         = count( $tk );
	$ns        = '';
	$uses      = array();
	$classes   = array();
	$functions = array();
	$calls     = array();
	$depth     = 0;
	$class     = null; // array( fqcn, depth at which its body opened ).
	$pending   = null; // class declared, body not yet open.

	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tk[ $i ];

		if ( T_NAMESPACE === $t[0] && isset( $tk[ $i + 1 ] ) && fh_is_name( $tk[ $i + 1 ] ) ) {
			$ns = ltrim( $tk[ $i + 1 ][1], '\\' );
			continue;
		}

		if ( T_USE === $t[0] && 0 === $depth && isset( $tk[ $i + 1 ] ) && fh_is_name( $tk[ $i + 1 ] ) ) {
			$full  = ltrim( $tk[ $i + 1 ][1], '\\' );
			$alias = substr( $full, (int) strrpos( '\\' . $full, '\\' ) );
			if ( isset( $tk[ $i + 2 ], $tk[ $i + 3 ] ) && T_AS === $tk[ $i + 2 ][0] ) {
				$alias = $tk[ $i + 3 ][1];
			}
			$uses[ strtolower( $alias ) ] = $full;
			continue;
		}

		if ( in_array( $t[0], array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) && isset( $tk[ $i + 1 ] ) && T_STRING === $tk[ $i + 1 ][0] ) {
			if ( isset( $tk[ $i - 1 ] ) && T_DOUBLE_COLON === $tk[ $i - 1 ][0] ) {
				continue; // Name::class.
			}
			$fqcn   = ( '' !== $ns ? $ns . '\\' : '' ) . $tk[ $i + 1 ][1];
			$parent = null;
			if ( isset( $tk[ $i + 2 ], $tk[ $i + 3 ] ) && T_EXTENDS === $tk[ $i + 2 ][0] ) {
				$parent = fh_resolve( $tk[ $i + 3 ][1], $ns, $uses );
			}
			$classes[ strtolower( $fqcn ) ] = array(
				'name'      => $fqcn,
				'parent'    => $parent,
				'methods'   => array(),
				'constants' => array(),
			);
			$pending = $fqcn;
			continue;
		}

		if ( '{' === $t[1] || T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
			$depth++;
			if ( null !== $pending && '{' === $t[1] ) {
				$class   = array( $pending, $depth );
				$pending = null;
			}
			continue;
		}

		if ( '}' === $t[1] ) {
			if ( null !== $class && $depth === $class[1] ) {
				$class = null;
			}
			$depth--;
			continue;
		}

		if ( T_FUNCTION === $t[0] ) {
			$j = $i + 1;
			if ( isset( $tk[ $j ] ) && '&' === $tk[ $j ][1] ) {
				$j++;
			}
			if ( isset( $tk[ $j ] ) && T_STRING === $tk[ $j ][0] ) {
				if ( null !== $class && $depth === $class[1] ) {
					// Its modifiers, read backwards: public (also when none
					// is written) and static, which a callback depends on.
					$mods = array();
					for ( $k = $i - 1; $k >= 0 && in_array( $tk[ $k ][0], array( T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL ), true ); $k-- ) {
						$mods[] = $tk[ $k ][0];
					}
					$classes[ strtolower( $class[0] ) ]['methods'][ strtolower( $tk[ $j ][1] ) ] = array(
						'public' => ! in_array( T_PROTECTED, $mods, true ) && ! in_array( T_PRIVATE, $mods, true ),
						'static' => in_array( T_STATIC, $mods, true ),
					);
				} elseif ( null === $class ) {
					$functions[ strtolower( ( '' !== $ns ? $ns . '\\' : '' ) . $tk[ $j ][1] ) ] = true;
				}
			}
			continue;
		}

		// const NAME = array( 'a', 'b' ) inside a class: keep its string literals.
		if ( T_CONST === $t[0] && null !== $class && $depth === $class[1] ) {
			for ( $j = $i + 1; $j < $n && ';' !== $tk[ $j ][1]; $j++ ) {
				if ( T_STRING === $tk[ $j ][0] && isset( $tk[ $j + 1 ] ) && '=' === $tk[ $j + 1 ][1] ) {
					$name    = $tk[ $j ][1];
					$strings = array();
					for ( $k = $j + 2; $k < $n && ';' !== $tk[ $k ][1] && ! ( ',' === $tk[ $k ][1] && T_STRING === ( $tk[ $k + 1 ][0] ?? 0 ) && '=' === ( $tk[ $k + 2 ][1] ?? '' ) ); $k++ ) {
						$lit = fh_literal( $tk[ $k ] );
						if ( null !== $lit ) {
							$strings[] = $lit;
						}
					}
					$classes[ strtolower( $class[0] ) ]['constants'][ $name ] = $strings;
				}
			}
			continue;
		}

		if ( T_STRING !== $t[0] || ! array_key_exists( strtolower( $t[1] ), FH_HOOK_FUNCTIONS ) ) {
			continue;
		}
		$prev = $tk[ $i - 1 ] ?? array( 0, '', 0 );
		if ( in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) || ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev[0] ) ) {
			continue;
		}
		if ( ! isset( $tk[ $i + 1 ] ) || '(' !== $tk[ $i + 1 ][1] ) {
			continue;
		}

		// Split the arguments at depth-0 commas.
		$args = array( array() );
		$d    = 0;
		for ( $j = $i + 2; $j < $n; $j++ ) {
			$x = $tk[ $j ][1];
			if ( '(' === $x || '[' === $x || '{' === $x ) {
				$d++;
			} elseif ( ')' === $x || ']' === $x || '}' === $x ) {
				if ( 0 === $d ) {
					break;
				}
				$d--;
			} elseif ( ',' === $x && 0 === $d ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $tk[ $j ];
		}

		$fn      = strtolower( $t[1] );
		$targets = array();
		if ( 'register_rest_route' === $fn ) {
			$targets = fh_rest_callbacks( $args );
		} else {
			foreach ( FH_HOOK_FUNCTIONS[ $fn ] as $idx ) {
				if ( isset( $args[ $idx ] ) && array() !== $args[ $idx ] ) {
					$targets[] = $args[ $idx ];
				}
			}
		}

		foreach ( $targets as $expr ) {
			$calls[] = array(
				'fn'      => $fn,
				'line'    => $t[2],
				'expr'    => $expr,
				'class'   => null !== $class ? $class[0] : null,
				'ns'      => $ns,
				'uses'    => $uses,
				'context' => array_slice( $tk, max( 0, $i - 60 ), 60 ),
			);
		}
	}

	return array(
		'classes'   => $classes,
		'functions' => array_keys( $functions ),
		'calls'     => $calls,
		'tokens'    => $tk,
	);
}

/**
 * The callback / permission_callback values inside register_rest_route's
 * argument arrays.
 *
 * @param array[] $args Argument token lists.
 * @return array[] Token lists.
 */
function fh_rest_callbacks( array $args ) {
	$out = array();
	foreach ( $args as $arg ) {
		$m = count( $arg );
		for ( $j = 0; $j < $m; $j++ ) {
			$lit = fh_literal( $arg[ $j ] );
			if ( ! in_array( $lit, array( 'callback', 'permission_callback' ), true ) || ! isset( $arg[ $j + 1 ] ) || T_DOUBLE_ARROW !== $arg[ $j + 1 ][0] ) {
				continue;
			}
			$expr = array();
			$d    = 0;
			for ( $k = $j + 2; $k < $m; $k++ ) {
				$x = $arg[ $k ][1];
				if ( '(' === $x || '[' === $x ) {
					$d++;
				} elseif ( ')' === $x || ']' === $x ) {
					if ( 0 === $d ) {
						break;
					}
					$d--;
				} elseif ( ',' === $x && 0 === $d ) {
					break;
				}
				$expr[] = $arg[ $k ];
			}
			$out[] = $expr;
		}
	}
	return $out;
}

/**
 * Find a method on a class (or on a parent this plugin defines).
 *
 * @param string $fqcn    Class.
 * @param string $method  Method.
 * @param array  $classes Index.
 * @return array{public: bool, static: bool}|false|null False when the
 *         method is missing, null when the class itself is unknown.
 */
function fh_find_method( $fqcn, $method, array $classes ) {
	$seen = 0;
	$key  = strtolower( ltrim( $fqcn, '\\' ) );
	if ( ! isset( $classes[ $key ] ) ) {
		return null;
	}
	while ( isset( $classes[ $key ] ) && $seen++ < 10 ) {
		if ( isset( $classes[ $key ]['methods'][ strtolower( $method ) ] ) ) {
			return $classes[ $key ]['methods'][ strtolower( $method ) ];
		}
		$parent = $classes[ $key ]['parent'];
		if ( null === $parent ) {
			return false;
		}
		$key = strtolower( $parent );
		if ( ! isset( $classes[ $key ] ) ) {
			// A parent from WordPress (WP_Widget, WP_List_Table …): ask PHP
			// only if it is loaded, else trust the parent.
			if ( ! class_exists( $parent ) ) {
				return array( 'public' => true, 'static' => true, 'trusted' => true );
			}
			if ( ! method_exists( $parent, $method ) ) {
				return false;
			}
			$r = new ReflectionMethod( $parent, $method );
			return array( 'public' => $r->isPublic(), 'static' => $r->isStatic() );
		}
	}
	return false;
}

/**
 * Whether WordPress can call a method found by fh_find_method(): it calls
 * from outside the class, so the method must be public; a callback that
 * names the class (not an object) calls it statically, so it must be
 * static too. Either mistake is a fatal Error on PHP 8 when the hook fires.
 *
 * @param array|false|null $found     fh_find_method() result.
 * @param bool             $by_class  The callback names the class, not an object.
 * @param string           $what      Class::method for the message.
 * @return array{ok: bool, what: string}
 */
function fh_callable_verdict( $found, $by_class, $what ) {
	if ( null === $found ) {
		return array( 'ok' => false, 'what' => "$what(), whose class this plugin does not define" );
	}
	if ( false === $found ) {
		return array( 'ok' => false, 'what' => "$what(), which does not exist" );
	}
	if ( ! $found['public'] ) {
		return array( 'ok' => false, 'what' => "$what(), which is not public (WordPress cannot call it)" );
	}
	if ( $by_class && ! $found['static'] ) {
		return array( 'ok' => false, 'what' => "$what(), which is not static but the callback names the class, not an object" );
	}
	return array( 'ok' => true, 'what' => $what );
}

/**
 * Work out what one callback names.
 *
 * @param array $call     A call from fh_read_file().
 * @param array $file     The file's fh_read_file() result.
 * @param array $classes  Index of every class.
 * @param array $function Index of every function (lowercase FQ => true).
 * @return array{kind: string, ok: bool, what: string}
 */
function fh_judge( array $call, array $file, array $classes, array $function ) {
	$e = $call['expr'];
	$m = count( $e );

	$first = $e[0];
	if ( T_FUNCTION === $first[0] || T_FN === $first[0] || ( T_STATIC === $first[0] && isset( $e[1] ) && in_array( $e[1][0], array( T_FUNCTION, T_FN ), true ) ) ) {
		return array( 'kind' => 'closure', 'ok' => true, 'what' => 'closure' );
	}

	// A plain variable holding a closure: `$var = function` / `fn` / `static function` in this file.
	if ( 1 === $m && T_VARIABLE === $first[0] ) {
		$tk = $file['tokens'];
		$n  = count( $tk );
		for ( $i = 0; $i + 3 < $n; $i++ ) {
			if ( $first[1] === $tk[ $i ][1] && '=' === $tk[ $i + 1 ][1] && ( in_array( $tk[ $i + 2 ][0], array( T_FUNCTION, T_FN ), true ) || ( T_STATIC === $tk[ $i + 2 ][0] && in_array( $tk[ $i + 3 ][0], array( T_FUNCTION, T_FN ), true ) ) ) ) {
				return array( 'kind' => 'variable', 'ok' => true, 'what' => $first[1] . ' (a closure in a variable)' );
			}
		}
		return array( 'kind' => 'unknown', 'ok' => false, 'what' => $first[1] . ' — cannot tell what the variable holds' );
	}

	// 'function' or 'Class::method'.
	if ( 1 === $m && null !== fh_literal( $first ) ) {
		return fh_judge_string( fh_literal( $first ), $classes, $function );
	}

	// __NAMESPACE__ . '\\function'.
	if ( 3 === $m && T_NS_C === $first[0] && '.' === $e[1][1] && null !== fh_literal( $e[2] ) ) {
		return fh_judge_string( $call['ns'] . fh_literal( $e[2] ), $classes, $function );
	}

	// array( X, Y ) or [ X, Y ].
	$open  = null;
	$close = null;
	if ( T_ARRAY === $first[0] && isset( $e[1] ) && '(' === $e[1][1] && ')' === $e[ $m - 1 ][1] ) {
		$open = 2;
	} elseif ( '[' === $first[1] && ']' === $e[ $m - 1 ][1] ) {
		$open = 1;
	}
	if ( null === $open ) {
		return array( 'kind' => 'unknown', 'ok' => false, 'what' => fh_text( $e ) );
	}
	$parts = array( array() );
	$d     = 0;
	for ( $j = $open; $j < $m - 1; $j++ ) {
		$x = $e[ $j ][1];
		if ( '(' === $x || '[' === $x ) {
			$d++;
		} elseif ( ')' === $x || ']' === $x ) {
			$d--;
		} elseif ( ',' === $x && 0 === $d ) {
			$parts[] = array();
			continue;
		}
		$parts[ count( $parts ) - 1 ][] = $e[ $j ];
	}
	$parts = array_values( array_filter( $parts ) );
	if ( 2 !== count( $parts ) ) {
		return array( 'kind' => 'unknown', 'ok' => false, 'what' => fh_text( $e ) );
	}

	list( $obj, $meth ) = $parts;

	$target = fh_target_class( $obj, $call, $file );
	if ( null === $target ) {
		return array( 'kind' => 'unknown', 'ok' => false, 'what' => fh_text( $e ) . ' — cannot tell which class' );
	}

	$names = fh_method_names( $meth, $call, $classes );
	if ( null === $names ) {
		return array( 'kind' => 'unknown', 'ok' => false, 'what' => fh_text( $e ) . ' — cannot tell which method' );
	}

	// $this, new Name() and $var are objects; everything else (self::class,
	// static::class, __CLASS__, Name::class, 'Name') names the class.
	$obj_text = fh_text( $obj );
	$by_class = ! ( '$this' === $obj_text || T_NEW === $obj[0][0] || T_VARIABLE === $obj[0][0] );

	foreach ( $names as $name ) {
		$found = fh_find_method( $target, $name, $classes );
		if ( null === $found ) {
			return array( 'kind' => 'method', 'ok' => false, 'what' => "class $target, which this plugin does not define (for $name())" );
		}
		$verdict = fh_callable_verdict( $found, $by_class, "$target::$name" );
		if ( ! $verdict['ok'] ) {
			return array( 'kind' => 'method', 'ok' => false, 'what' => $verdict['what'] );
		}
	}

	return array( 'kind' => 'method', 'ok' => true, 'what' => $target . '::' . implode( '|', $names ) );
}

/**
 * A string callback: 'function' or 'Class::method'.
 *
 * @param string $s        Callback.
 * @param array  $classes  Index.
 * @param array  $function Function index.
 * @return array
 */
function fh_judge_string( $s, array $classes, array $function ) {
	$s = ltrim( $s, '\\' );
	if ( false !== strpos( $s, '::' ) ) {
		list( $c, $meth ) = explode( '::', $s, 2 );
		$verdict          = fh_callable_verdict( fh_find_method( $c, $meth, $classes ), true, "$c::$meth" );
		return array( 'kind' => 'method', 'ok' => $verdict['ok'], 'what' => $verdict['ok'] ? "$c::$meth()" : $verdict['what'] );
	}
	$ok = isset( $function[ strtolower( $s ) ] ) || in_array( strtolower( $s ), FH_CORE_FUNCTIONS, true ) || ( false === strpos( $s, '\\' ) && function_exists( $s ) );
	return array( 'kind' => 'function', 'ok' => $ok, 'what' => "$s()" . ( $ok ? '' : ', which no file defines' ) );
}

/**
 * The class an array callback's first element stands for.
 *
 * @param array $obj  Tokens.
 * @param array $call Call.
 * @param array $file File.
 * @return string|null
 */
function fh_target_class( array $obj, array $call, array $file ) {
	$text = fh_text( $obj );

	if ( in_array( $text, array( '$this', 'self::class', 'static::class', '__CLASS__' ), true ) ) {
		return $call['class'];
	}
	if ( 3 === count( $obj ) && fh_is_name( $obj[0] ) && T_DOUBLE_COLON === $obj[1][0] && 'class' === strtolower( $obj[2][1] ) ) {
		$n = $obj[0][1];
		if ( in_array( strtolower( $n ), array( 'self', 'static' ), true ) ) {
			return $call['class'];
		}
		return fh_resolve( $n, $call['ns'], $call['uses'] );
	}
	if ( T_NEW === $obj[0][0] && isset( $obj[1] ) && fh_is_name( $obj[1] ) ) {
		return fh_resolve( $obj[1][1], $call['ns'], $call['uses'] );
	}
	if ( 1 === count( $obj ) && null !== fh_literal( $obj[0] ) ) {
		return ltrim( fh_literal( $obj[0] ), '\\' );
	}

	// $var or $this->prop: the one `<same> = new Name(` in this file.
	if ( preg_match( '/^\$[A-Za-z_][A-Za-z0-9_]*(->[A-Za-z_][A-Za-z0-9_]*)?$/', $text ) ) {
		$found = array();
		$tk    = $file['tokens'];
		$want  = count( $obj );
		$n     = count( $tk );
		for ( $i = 0; $i + $want + 2 < $n; $i++ ) {
			if ( fh_text( array_slice( $tk, $i, $want ) ) !== $text ) {
				continue;
			}
			if ( '=' === $tk[ $i + $want ][1] && T_NEW === $tk[ $i + $want + 1 ][0] && fh_is_name( $tk[ $i + $want + 2 ] ) ) {
				$found[ fh_resolve( $tk[ $i + $want + 2 ][1], $call['ns'], $call['uses'] ) ] = true;
			}
		}
		if ( 1 === count( $found ) ) {
			return (string) key( $found );
		}
	}

	return null;
}

/**
 * The method name(s) an array callback's second element gives.
 *
 * @param array $meth    Tokens.
 * @param array $call    Call.
 * @param array $classes Index.
 * @return string[]|null
 */
function fh_method_names( array $meth, array $call, array $classes ) {
	if ( 1 === count( $meth ) && null !== fh_literal( $meth[0] ) ) {
		return array( fh_literal( $meth[0] ) );
	}

	// 'prefix_' . $var inside foreach ( self::LIST as $var ).
	if ( 3 === count( $meth ) && null !== fh_literal( $meth[0] ) && '.' === $meth[1][1] && T_VARIABLE === $meth[2][0] && null !== $call['class'] ) {
		$var = $meth[2][1];
		$ctx = $call['context'];
		for ( $i = count( $ctx ) - 1; $i >= 5; $i-- ) {
			if ( T_FOREACH === $ctx[ $i ][0] && 'self' === ( $ctx[ $i + 2 ][1] ?? '' ) && T_DOUBLE_COLON === ( $ctx[ $i + 3 ][0] ?? 0 ) && T_AS === ( $ctx[ $i + 5 ][0] ?? 0 ) && $var === ( $ctx[ $i + 6 ][1] ?? '' ) ) {
				$const = $ctx[ $i + 4 ][1];
				$list  = $classes[ strtolower( $call['class'] ) ]['constants'][ $const ] ?? array();
				if ( array() === $list ) {
					return null;
				}
				$prefix = fh_literal( $meth[0] );
				return array_map(
					function ( $op ) use ( $prefix ) {
						return $prefix . $op;
					},
					$list
				);
			}
		}
	}

	return null;
}

/**
 * Tokens back to compact text.
 *
 * @param array $tokens Tokens.
 * @return string
 */
function fh_text( array $tokens ) {
	return implode( '', array_map( function ( $t ) { return $t[1]; }, $tokens ) );
}

/**
 * Scan a folder.
 *
 * @param string   $root    Folder.
 * @param string[] $only    Relative paths/prefixes to read (empty = all).
 * @param array    $allowed 'file:line' => reason.
 * @return array{failures: string[], counts: array, files: int}
 */
function fh_scan( $root, array $only = array(), array $allowed = array() ) {
	$paths = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$path = $f->getPathname();
		if ( '.php' !== substr( $path, -4 ) ) {
			continue;
		}
		$rel = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
		if ( preg_match( '~(^|/)(tests|vendor|node_modules|\.git)/~', $rel ) ) {
			continue;
		}
		if ( $only ) {
			$keep = false;
			foreach ( $only as $prefix ) {
				if ( 0 === strpos( $rel, $prefix ) ) {
					$keep = true;
				}
			}
			if ( ! $keep ) {
				continue;
			}
		}
		$paths[ $rel ] = $path;
	}
	ksort( $paths );

	$files     = array();
	$classes   = array();
	$functions = array();
	foreach ( $paths as $rel => $path ) {
		$files[ $rel ] = fh_read_file( (string) file_get_contents( $path ) );
		$classes      += $files[ $rel ]['classes'];
		foreach ( $files[ $rel ]['functions'] as $fn ) {
			$functions[ $fn ] = true;
		}
	}

	$failures = array();
	$counts   = array();
	foreach ( $files as $rel => $file ) {
		foreach ( $file['calls'] as $call ) {
			$verdict = fh_judge( $call, $file, $classes, $functions );
			$kind    = $verdict['kind'];
			if ( ! $verdict['ok'] && isset( $allowed[ $rel . ':' . $call['line'] ] ) ) {
				$kind = 'allowed';
			} elseif ( ! $verdict['ok'] ) {
				$failures[] = "$rel:{$call['line']} {$call['fn']}() — callback names " . $verdict['what'];
			}
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
		}
	}

	return array(
		'failures' => $failures,
		'counts'   => $counts,
		'files'    => count( $files ),
	);
}

/* ------------------------------------------------------------------ */
/*  1. The reader catches a planted mistake                           */
/* ------------------------------------------------------------------ */

echo "A planted missing method is caught\n";

$fh_tmp = sys_get_temp_dir() . '/fh-hook-callbacks-' . getmypid();
@mkdir( $fh_tmp . '/includes/sections/demo', 0777, true );

$fh_good = <<<'PHP'
<?php
namespace FisHotel\Misc\Sections\Demo;

use FisHotel\Misc\Sections\Demo\Helper as Aide;

class Base {
	public function from_parent() {}
}

class Demo extends Base {
	const OPS = array( 'save', 'load' );

	public function init() {
		add_action( 'init', array( $this, 'boot' ) );
		add_filter( 'the_title', [ self::class, 'title' ], 10, 1 );
		add_action( 'wp_footer', array( Aide::class, 'footer' ) );
		add_action( 'admin_init', array( $this, 'from_parent' ) );
		add_action( 'admin_menu', function () { return 1; } );
		add_filter( 'show_admin_bar', '__return_false' );
		foreach ( self::OPS as $op ) {
			add_action( 'wp_ajax_demo_' . $op, array( $this, 'ajax_' . $op ) );
		}
		$widget = new Helper();
		add_action( 'wp_dashboard_setup', array( $widget, 'footer' ) );
		register_rest_route( 'demo/v1', '/x', array( 'callback' => array( $this, 'boot' ), 'permission_callback' => '__return_true' ) );
		add_submenu_page( 'p', 't', 'm', 'cap', 'slug', array( new Helper(), 'footer' ) );
		add_filter( 'the_excerpt', array( $this, 'title' ) );
		add_action( 'shutdown', array( $this, 'no_word' ) );
	}

	function no_word() {}
	public function boot() {}
	public static function title( $t ) { return $t; }
	public function ajax_save() {}
	public function ajax_load() {}
}

class Helper {
	public static function footer() {}
}

function demo_init() {}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\demo_init' );
PHP;

$fh_bad = <<<'PHP'
<?php
namespace FisHotel\Misc\Sections\Demo;

class Broken {
	const OPS = array( 'save', 'gone' );

	public function init() {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'add_order_notes' ) );
		add_filter( 'the_content', array( self::class, 'nope' ) );
		add_action( 'init', array( Helper::class, 'not_there' ) );
		add_action( 'init', array( Missing_Class::class, 'run' ) );
		add_action( 'plugins_loaded', __NAMESPACE__ . '\\no_such_function' );
		add_shortcode( 'demo', 'fh_no_such_global' );
		foreach ( self::OPS as $op ) {
			add_action( 'wp_ajax_broken_' . $op, array( $this, 'ajax_' . $op ) );
		}
		register_rest_route( 'demo/v1', '/y', array( 'callback' => array( $this, 'rest_gone' ) ) );
		add_action( 'init', array( $this->who_knows(), 'run' ) );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'soon' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'later' ) );
		add_action( 'init', __NAMESPACE__ . '\\Broken::later' );
		add_action( 'init', array( $this, 'inside' ) );
		add_action( 'init', array( $this, 'soon' ) );
	}

	private static function soon() {}
	public function later() {}
	protected function inside() {}
	public function ajax_save() {}
	public function who_knows() { return $this; }
}
PHP;

file_put_contents( $fh_tmp . '/includes/sections/demo/class-demo.php', $fh_good );
$fh_res = fh_scan( $fh_tmp );
check( 'a file where every callback exists passes', array() === $fh_res['failures'], implode( "\n       ", $fh_res['failures'] ) );
check( 'it read all fourteen callbacks there (methods, closure, functions, the REST keys, the foreach list, a static method through $this, a method with no visibility word)', 14 === array_sum( $fh_res['counts'] ), json_encode( $fh_res['counts'] ) );

file_put_contents( $fh_tmp . '/includes/sections/demo/class-broken.php', $fh_bad );
$fh_res  = fh_scan( $fh_tmp );
$fh_text = implode( "\n", $fh_res['failures'] );
check( 'the planted missing method fails the test', false !== strpos( $fh_text, 'class-broken.php:8 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::add_order_notes(), which does not exist' ), $fh_text );
check( 'self::class with a missing method fails', false !== strpos( $fh_text, 'Broken::nope()' ) );
check( 'another class with a missing method fails', false !== strpos( $fh_text, 'Helper::not_there()' ) );
check( 'a class nobody defines fails', false !== strpos( $fh_text, 'Missing_Class' ) );
check( 'a namespaced function nobody defines fails', false !== strpos( $fh_text, 'no_such_function()' ) );
check( 'a global function nobody defines fails', false !== strpos( $fh_text, 'fh_no_such_global()' ) );
check( 'one missing name in a foreach list fails (ajax_gone)', false !== strpos( $fh_text, 'ajax_gone()' ) && false === strpos( $fh_text, 'ajax_save()' ) );
check( 'a REST callback that does not exist fails', false !== strpos( $fh_text, 'rest_gone()' ) );
check( 'a callback the reader cannot work out fails instead of passing quietly', false !== strpos( $fh_text, 'cannot tell which class' ) );
check( 'a private static method named through self::class fails (WordPress cannot call it)', false !== strpos( $fh_text, 'class-broken.php:19 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::soon(), which is not public' ), $fh_text );
check( 'a public method that is not static, named through __CLASS__, fails', false !== strpos( $fh_text, 'class-broken.php:20 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::later(), which is not static' ), $fh_text );
check( '… and through a \'Class::method\' string', false !== strpos( $fh_text, 'class-broken.php:21 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::later(), which is not static' ), $fh_text );
check( 'a protected method through $this fails', false !== strpos( $fh_text, 'class-broken.php:22 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::inside(), which is not public' ), $fh_text );
check( 'a private method through $this fails too', false !== strpos( $fh_text, 'class-broken.php:23 add_action() — callback names FisHotel\\Misc\\Sections\\Demo\\Broken::soon(), which is not public' ), $fh_text );
check( 'exactly those fourteen fail, the good file still passes', 14 === count( $fh_res['failures'] ), $fh_text );

$fh_res = fh_scan( $fh_tmp, array(), array( 'includes/sections/demo/class-broken.php:18' => 'test' ) );
check( 'a line on the allowed list is not a failure', 13 === count( $fh_res['failures'] ) && 1 === ( $fh_res['counts']['allowed'] ?? 0 ) );

foreach ( array( 'class-demo.php', 'class-broken.php' ) as $fh_f ) {
	unlink( $fh_tmp . '/includes/sections/demo/' . $fh_f );
}
@rmdir( $fh_tmp . '/includes/sections/demo' );
@rmdir( $fh_tmp . '/includes/sections' );
@rmdir( $fh_tmp . '/includes' );
@rmdir( $fh_tmp );

/* ------------------------------------------------------------------ */
/*  2. This plugin                                                    */
/* ------------------------------------------------------------------ */

echo "Every callback in this plugin exists\n";

/*
 * Lines the reader cannot work out on its own, each with the reason it
 * is fine. Keep this short: a new entry needs a reason a reviewer
 * accepts.
 */
$fh_allowed = array();

$fh_root = dirname( __DIR__ );
$fh_res  = fh_scan( $fh_root, array( 'fishotel-misc-plugin.php', 'includes/' ), $fh_allowed );
foreach ( $fh_res['failures'] as $fh_fail ) {
	check( $fh_fail, false );
}
$fh_total = array_sum( $fh_res['counts'] );
ksort( $fh_res['counts'] );
check(
	"no callback names a missing method or function ($fh_total callbacks in {$fh_res['files']} files: " . json_encode( $fh_res['counts'] ) . ')',
	array() === $fh_res['failures']
);
check( 'the reader still finds the hooks (over 300 callbacks — fewer means the reader went blind)', $fh_total > 300, (string) $fh_total );
check( 'the plugin file itself was read (its plugins_loaded and activation hooks)', ( $fh_res['counts']['function'] ?? 0 ) >= 3 );

echo "\n" . ( $fh_failures ? "$fh_failures FAILED of $fh_checks\n" : "all $fh_checks passed\n" );
exit( $fh_failures ? 1 : 0 );
