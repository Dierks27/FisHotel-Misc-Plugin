<?php
/**
 * Snippet file header — PURE: a file name and its text in, the snippet's
 * fields (or the reason it is broken) out. No WordPress, no file system.
 *
 * A snippet is ONE file in snippets/ named <slug>.php, <slug>.css,
 * <slug>.js or <slug>.html whose very first thing (after `<?php` for PHP)
 * is a comment block — `/* … *\/` for PHP / CSS / JS, `<!-- … -->` for
 * HTML — holding `Key: value` lines:
 *
 *   Snippet      required — the name shown on the admin page
 *   Description  optional — one line (continuation lines are appended)
 *   Type         php|css|js|html — must match the extension (from it when absent)
 *   Scope        php: global|front-end|admin
 *                css: front-end|admin|login
 *                js:  front-end|admin|login|head|footer
 *                html: shortcode|head|body|footer
 *                (absent: global / front-end / front-end / shortcode)
 *   Priority     integer, default 10 (lower runs first)
 *   Origin       free text, e.g. "Code Snippets #12, imported 2026-10-20"
 *   Default      on|off, default off — used while the admin page has never
 *                switched the snippet
 *
 * Anything that does not parse makes the file broken: it is listed with the
 * reason and never loaded, and its switch refuses to turn on.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Header
 */
class Header {

	/**
	 * The header comment must close within this many bytes of the start.
	 *
	 * @var int
	 */
	const MAX_HEADER_BYTES = 8192;

	/**
	 * Longest accepted name / origin / description.
	 */
	const MAX_NAME        = 120;
	const MAX_ORIGIN      = 200;
	const MAX_DESCRIPTION = 500;

	/**
	 * Slug: lowercase letters and digits in hyphen-separated runs, ≤ 64.
	 *
	 * @var string
	 */
	const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * Snippet types (= file extensions).
	 *
	 * @var string[]
	 */
	const TYPES = array( 'php', 'css', 'js', 'html' );

	/**
	 * Valid scopes per type, the first one being the default.
	 *
	 * @var array<string, string[]>
	 */
	const SCOPES = array(
		'php'  => array( 'global', 'front-end', 'admin' ),
		'css'  => array( 'front-end', 'admin', 'login' ),
		'js'   => array( 'front-end', 'admin', 'login', 'head', 'footer' ),
		'html' => array( 'shortcode', 'head', 'body', 'footer' ),
	);

	/**
	 * Header keys (lower-case) → field names.
	 *
	 * @var array<string, string>
	 */
	const KEYS = array(
		'snippet'     => 'name',
		'description' => 'description',
		'type'        => 'type',
		'scope'       => 'scope',
		'priority'    => 'priority',
		'origin'      => 'origin',
		'default'     => 'default',
	);

	/**
	 * Parse one snippet file.
	 *
	 * @param string $filename File name (a path is fine; only the base name is read).
	 * @param string $content  The file's text.
	 * @return array{ok: bool, error: string, slug: string, type: string, fields: array, body_offset: int}
	 *         fields: name, description, type, scope, priority (int), origin,
	 *         default_on (bool), cs_id (int, 0 = none).
	 */
	public static function parse( $filename, $content ) {
		$content = (string) $content;
		$result  = array(
			'ok'          => false,
			'error'       => '',
			'slug'        => '',
			'type'        => '',
			'fields'      => array(),
			'body_offset' => 0,
		);

		$base = basename( str_replace( '\\', '/', (string) $filename ) );

		if ( ! preg_match( '/^(.+)\.(php|css|js|html)$/', $base, $m ) ) {
			$result['error'] = 'Not a snippet file name: it must end in .php, .css, .js or .html.';
			return $result;
		}

		$slug = $m[1];
		$ext  = $m[2];

		$result['slug'] = $slug;
		$result['type'] = $ext;

		if ( strlen( $slug ) > 64 || ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			$result['error'] = 'The file name must be lowercase letters, digits and single hyphens (at most 64), e.g. hide-admin-bar.' . $ext . '.';
			return $result;
		}

		$block = self::comment_block( $ext, $content );
		if ( '' !== $block['error'] ) {
			$result['error'] = $block['error'];
			return $result;
		}

		$raw = self::read_keys( $block['inner'] );
		if ( '' !== $raw['error'] ) {
			$result['error'] = $raw['error'];
			return $result;
		}

		$fields = self::validate( $ext, $raw['values'] );
		if ( '' !== $fields['error'] ) {
			$result['error'] = $fields['error'];
			return $result;
		}

		$result['ok']          = true;
		$result['fields']      = $fields['fields'];
		$result['body_offset'] = $block['end'];

		return $result;
	}

	/**
	 * The text after the header comment (what a CSS / JS / HTML snippet
	 * prints), or '' when the file does not parse.
	 *
	 * @param string $filename File name.
	 * @param string $content  The file's text.
	 * @return string
	 */
	public static function body( $filename, $content ) {
		$parsed = self::parse( $filename, $content );
		if ( ! $parsed['ok'] ) {
			return '';
		}

		return ltrim( (string) substr( (string) $content, $parsed['body_offset'] ), "\r\n" );
	}

	/**
	 * The Code Snippets id named by an Origin ("Code Snippets #12, …"), or 0.
	 *
	 * @param string $origin Origin text.
	 * @return int
	 */
	public static function code_snippets_id( $origin ) {
		if ( preg_match( '/\bCode\s+Snippets\s*#\s*(\d{1,9})\b/i', (string) $origin, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * Find the header comment.
	 *
	 * @param string $ext     php|css|js|html.
	 * @param string $content File text.
	 * @return array{error: string, inner: string, end: int} end = offset just past the comment.
	 */
	private static function comment_block( $ext, $content ) {
		$out = array(
			'error' => '',
			'inner' => '',
			'end'   => 0,
		);

		if ( 0 === strncmp( $content, "\xEF\xBB\xBF", 3 ) ) {
			$out['error'] = 'The file starts with a byte-order mark; save it as UTF-8 without BOM.';
			return $out;
		}

		$pos = 0;

		if ( 'php' === $ext ) {
			if ( 0 !== strncmp( $content, '<?php', 5 ) || ( strlen( $content ) > 5 && ! ctype_space( $content[5] ) ) ) {
				$out['error'] = 'A PHP snippet must start with "<?php" on the first line, followed by the header comment.';
				return $out;
			}
			$pos = 5;
		}

		$len = strlen( $content );
		while ( $pos < $len && ctype_space( $content[ $pos ] ) ) {
			$pos++;
		}

		$open  = 'html' === $ext ? '<!--' : '/*';
		$close = 'html' === $ext ? '-->' : '*/';

		if ( substr( $content, $pos, strlen( $open ) ) !== $open ) {
			$out['error'] = 'Missing header: the file must begin with a ' . ( 'html' === $ext ? '<!-- … -->' : '/** … */' ) . ' comment holding at least "Snippet: <name>".';
			return $out;
		}

		$start = $pos + strlen( $open );
		$end   = strpos( $content, $close, $start );

		if ( false === $end || $end + strlen( $close ) > self::MAX_HEADER_BYTES ) {
			$out['error'] = 'The header comment is not closed within the first ' . self::MAX_HEADER_BYTES . ' bytes.';
			return $out;
		}

		$out['inner'] = substr( $content, $start, $end - $start );
		$out['end']   = $end + strlen( $close );

		return $out;
	}

	/**
	 * `Key: value` lines → raw values (keys lower-cased, unknown keys
	 * skipped, Description continuation lines appended).
	 *
	 * @param string $inner Comment text without its delimiters.
	 * @return array{error: string, values: array<string, string>}
	 */
	private static function read_keys( $inner ) {
		$values  = array();
		$current = '';
		$keys    = self::KEYS;

		foreach ( preg_split( '/\r\n|\r|\n/', $inner ) as $line ) {
			// Docblock decoration: leading whitespace and asterisks.
			$line = trim( preg_replace( '/^\s*\*+/', '', $line ) );

			if ( '' === $line ) {
				$current = '';
				continue;
			}

			if ( preg_match( '/^([A-Za-z][A-Za-z ]{0,30}?)\s*:\s*(.*)$/', $line, $m ) && isset( $keys[ strtolower( $m[1] ) ] ) ) {
				$key = $keys[ strtolower( $m[1] ) ];

				if ( array_key_exists( $key, $values ) ) {
					return array(
						'error'  => 'Header field "' . $m[1] . '" is given twice.',
						'values' => array(),
					);
				}

				$values[ $key ] = trim( $m[2] );
				$current        = $key;
				continue;
			}

			if ( 'description' === $current ) {
				$values['description'] = trim( $values['description'] . ' ' . $line );
			} else {
				$current = '';
			}
		}

		return array(
			'error'  => '',
			'values' => $values,
		);
	}

	/**
	 * Validate the raw values and fill the defaults.
	 *
	 * @param string $ext    Extension.
	 * @param array  $values Raw values.
	 * @return array{error: string, fields: array}
	 */
	private static function validate( $ext, array $values ) {
		$fail = static function ( $message ) {
			return array(
				'error'  => $message,
				'fields' => array(),
			);
		};

		$name = isset( $values['name'] ) ? $values['name'] : '';
		if ( '' === $name ) {
			return $fail( 'The header has no "Snippet: <name>" line.' );
		}
		if ( self::length( $name ) > self::MAX_NAME ) {
			return $fail( 'The Snippet name is longer than ' . self::MAX_NAME . ' characters.' );
		}

		$type = isset( $values['type'] ) ? strtolower( $values['type'] ) : '';
		if ( '' === $type ) {
			$type = $ext;
		} elseif ( ! in_array( $type, self::TYPES, true ) ) {
			return $fail( 'Type "' . $values['type'] . '" is not one of php, css, js, html.' );
		} elseif ( $type !== $ext ) {
			return $fail( 'Type "' . $type . '" does not match the .' . $ext . ' extension.' );
		}

		$scope = isset( $values['scope'] ) ? strtolower( $values['scope'] ) : '';
		if ( '' === $scope ) {
			$scope = self::SCOPES[ $type ][0];
		} elseif ( ! in_array( $scope, self::SCOPES[ $type ], true ) ) {
			return $fail( 'Scope "' . $values['scope'] . '" is not valid for a ' . $type . ' snippet (' . implode( ', ', self::SCOPES[ $type ] ) . ').' );
		}

		$priority = isset( $values['priority'] ) ? $values['priority'] : '';
		if ( '' === $priority ) {
			$priority = 10;
		} elseif ( preg_match( '/^-?\d{1,6}$/', $priority ) ) {
			$priority = (int) $priority;
		} else {
			return $fail( 'Priority "' . $values['priority'] . '" is not a whole number.' );
		}

		$default = isset( $values['default'] ) ? strtolower( $values['default'] ) : '';
		if ( '' === $default ) {
			$default = 'off';
		} elseif ( 'on' !== $default && 'off' !== $default ) {
			return $fail( 'Default "' . $values['default'] . '" must be on or off.' );
		}

		$origin = isset( $values['origin'] ) ? $values['origin'] : '';
		if ( self::length( $origin ) > self::MAX_ORIGIN ) {
			return $fail( 'The Origin is longer than ' . self::MAX_ORIGIN . ' characters.' );
		}

		$description = isset( $values['description'] ) ? $values['description'] : '';
		if ( self::length( $description ) > self::MAX_DESCRIPTION ) {
			$description = self::cut( $description, self::MAX_DESCRIPTION - 1 ) . '…';
		}

		return array(
			'error'  => '',
			'fields' => array(
				'name'        => $name,
				'description' => $description,
				'type'        => $type,
				'scope'       => $scope,
				'priority'    => $priority,
				'origin'      => $origin,
				'default_on'  => 'on' === $default,
				'cs_id'       => self::code_snippets_id( $origin ),
			),
		);
	}

	/**
	 * Character length (multibyte when available).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * First $n characters.
	 *
	 * @param string $text Text.
	 * @param int    $n    Characters.
	 * @return string
	 */
	private static function cut( $text, $n ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $n, 'UTF-8' ) : substr( $text, 0, $n );
	}
}
