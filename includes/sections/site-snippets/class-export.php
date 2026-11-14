<?php
/**
 * Export — PURE: a database snippet as a snippet FILE, ready to be put in
 * snippets/ through a pull request ("Move to GitHub").
 *
 * The file has the header the Header parser reads (Snippet, Description,
 * Type, Scope, Priority, Origin "written in wp-admin by <login>, exported
 * YYYY-MM-DD", Default matching whether the snippet is on), then for PHP
 * the opening tag and the `defined( 'ABSPATH' ) || exit;` guard the folder
 * test insists on — placed after a leading `declare(…);` / `namespace …;`
 * (or just inside the first `namespace … {`), where PHP allows it — then
 * the code. Once the file is merged, the database copy shows as
 * superseded and can be deleted.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Export
 */
class Export {

	/**
	 * The direct-access guard line.
	 *
	 * @var string
	 */
	const GUARD = "defined( 'ABSPATH' ) || exit;";

	/**
	 * The file name: <slug>.<type>.
	 *
	 * @param array $snippet Row.
	 * @return string
	 */
	public static function filename( array $snippet ) {
		return (string) $snippet['slug'] . '.' . (string) $snippet['type'];
	}

	/**
	 * The Origin line.
	 *
	 * @param string $login Who wrote it (the exporting user's login).
	 * @param string $date  Y-m-d.
	 * @return string
	 */
	public static function origin( $login, $date ) {
		$login = Editor_Rules::one_line( str_replace( array( '*/', '-->', ',' ), ' ', (string) $login ) );
		return 'written in wp-admin by ' . ( '' !== $login ? $login : 'an editor' ) . ', exported ' . $date;
	}

	/**
	 * The file's text.
	 *
	 * @param array  $snippet Row: slug, name, description, type, scope, priority, active, code.
	 * @param string $login   The exporting user's login.
	 * @param string $date    Y-m-d.
	 * @return string
	 */
	public static function build( array $snippet, $login, $date ) {
		$type  = (string) $snippet['type'];
		$code  = (string) $snippet['code'];
		$lines = array( 'Snippet: ' . Editor_Rules::one_line( $snippet['name'] ) );

		$description = Editor_Rules::one_line( isset( $snippet['description'] ) ? $snippet['description'] : '' );
		if ( '' !== $description ) {
			$lines[] = 'Description: ' . $description;
		}

		$lines[] = 'Type: ' . $type;
		$lines[] = 'Scope: ' . (string) $snippet['scope'];
		$lines[] = 'Priority: ' . (int) $snippet['priority'];
		$lines[] = 'Origin: ' . self::origin( $login, $date );
		$lines[] = 'Default: ' . ( empty( $snippet['active'] ) ? 'off' : 'on' );

		if ( 'html' === $type ) {
			$header = "<!--\n" . implode( "\n", $lines ) . "\n-->\n";
		} else {
			$header = "/**\n * " . implode( "\n * ", $lines ) . "\n */\n";
		}

		if ( 'php' !== $type ) {
			return $header . self::with_newline( ltrim( $code, "\r\n" ) );
		}

		$at   = self::guard_offset( $code );
		$head = rtrim( (string) substr( $code, 0, $at ) );
		$tail = ltrim( (string) substr( $code, $at ), "\r\n" );

		$body = '' === $head
			? self::GUARD . "\n\n" . $tail
			: $head . "\n\n" . self::GUARD . "\n\n" . $tail;

		return "<?php\n" . $header . "\n" . self::with_newline( $body );
	}

	/**
	 * Where the guard may go in stored PHP code: after any leading
	 * `declare(…);` statements and a leading `namespace Name;`, or right
	 * after the `{` of a leading `namespace Name {` (PHP allows nothing
	 * before those). 0 = at the very start.
	 *
	 * @param string $code Stored code (no opening tag).
	 * @return int Byte offset into $code.
	 */
	public static function guard_offset( $code ) {
		$prefix = "<?php\n";
		$tokens = token_get_all( $prefix . (string) $code );
		$count  = count( $tokens );
		$offset = 0;   // Bytes consumed, prefix included.
		$at     = 0;   // Best offset so far (into $code).
		$i      = 0;

		$skip = static function () use ( &$i, &$offset, $tokens, $count ) {
			while ( $i < $count ) {
				$t = $tokens[ $i ];
				if ( is_array( $t ) && in_array( $t[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$offset += strlen( $t[1] );
					$i++;
					continue;
				}
				break;
			}
		};

		$text = static function ( $t ) {
			return is_array( $t ) ? $t[1] : $t;
		};

		// Leading declare( … ); statements.
		while ( true ) {
			$skip();
			if ( $i >= $count || ! is_array( $tokens[ $i ] ) || T_DECLARE !== $tokens[ $i ][0] ) {
				break;
			}
			while ( $i < $count && ';' !== $tokens[ $i ] ) {
				$offset += strlen( $text( $tokens[ $i ] ) );
				$i++;
			}
			if ( $i >= $count ) {
				return 0;
			}
			$offset += 1;
			$i++;
			$at = $offset - strlen( $prefix );
		}

		$skip();
		if ( $i < $count && is_array( $tokens[ $i ] ) && T_NAMESPACE === $tokens[ $i ][0] ) {
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$j++;
			}
			// `namespace\foo()` is a call, not a declaration.
			if ( $j < $count && ! ( is_array( $tokens[ $j ] ) && T_NS_SEPARATOR === $tokens[ $j ][0] ) ) {
				while ( $i < $count && ';' !== $tokens[ $i ] && '{' !== $tokens[ $i ] ) {
					$offset += strlen( $text( $tokens[ $i ] ) );
					$i++;
				}
				if ( $i < $count ) {
					$offset += 1;
					$at      = $offset - strlen( $prefix );
				}
			}
		}

		return max( 0, $at );
	}

	/**
	 * Text ending in exactly one newline.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function with_newline( $text ) {
		return rtrim( (string) $text, "\r\n" ) . "\n";
	}
}
