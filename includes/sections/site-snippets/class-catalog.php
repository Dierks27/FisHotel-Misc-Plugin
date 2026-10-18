<?php
/**
 * Catalog — the snippets/ folder as an index: every snippet's header
 * fields, the declarations of each PHP file, the broken files with their
 * reason and the names two files both declare.
 *
 * build() is PURE (file name => text in, index out). get() is the cached
 * read every request makes: one directory listing plus a stat per file
 * gives a signature; while it matches the stored index (an autoloaded
 * option, so no query) nothing is read or parsed. A deploy that adds,
 * removes or edits a file changes the signature, and the first request
 * after it rebuilds the index once.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Catalog
 */
class Catalog {

	/**
	 * Autoloaded option holding the index.
	 *
	 * @var string
	 */
	const OPTION_INDEX = 'fishotel_snippets_index';

	/**
	 * Bumped whenever the index shape or the parsing rules change, so an
	 * index built by an older version is never trusted.
	 *
	 * @var int
	 */
	const FORMAT = 2;

	/**
	 * Files larger than this are refused (a snippet is a few KB).
	 *
	 * @var int
	 */
	const MAX_FILE_BYTES = 262144;

	/**
	 * Per-request memo of get().
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Another folder instead of snippets/ (tests).
	 *
	 * @var string|null
	 */
	private static $dir_override = null;

	/**
	 * The snippets folder.
	 *
	 * @return string With a trailing slash.
	 */
	public static function dir() {
		return null !== self::$dir_override ? self::$dir_override : __DIR__ . '/snippets/';
	}

	/**
	 * Read another folder instead of snippets/ (tests); null restores it.
	 *
	 * @param string|null $dir Folder.
	 */
	public static function use_dir( $dir ) {
		self::$dir_override = null === $dir ? null : rtrim( (string) $dir, '/' ) . '/';
		self::$memo         = null;
	}

	/**
	 * Candidate files (base names, sorted): every non-hidden *.php / *.css /
	 * *.js / *.html directly in the folder. Anything else is ignored.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	public static function list_files( $dir ) {
		$all = glob( rtrim( $dir, '/' ) . '/*' );
		$out = array();

		foreach ( is_array( $all ) ? $all : array() as $path ) {
			$base = basename( $path );
			if ( '.' !== $base[0] && preg_match( '/\.(php|css|js|html)$/', $base ) && is_file( $path ) ) {
				$out[] = $base;
			}
		}

		sort( $out );
		return $out;
	}

	/**
	 * Signature of the folder: format, PHP version (the syntax check depends
	 * on it) and every file's name, size and modification time.
	 *
	 * @param string   $dir   Folder.
	 * @param string[] $files list_files().
	 * @return string
	 */
	public static function signature( $dir, array $files ) {
		$parts = array( 'f' . self::FORMAT, PHP_VERSION );
		$dir   = rtrim( $dir, '/' ) . '/';

		foreach ( $files as $file ) {
			$path    = $dir . $file;
			$parts[] = $file . ':' . (int) @filesize( $path ) . ':' . (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file deleted mid-listing just changes the signature.
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * The index for a set of files — PURE.
	 *
	 * @param array<string, string> $contents base name => file text.
	 * @return array{snippets: array<string, array>, broken: array<int, array{file: string, reason: string}>, conflicts: array}
	 *         snippets: slug => { slug, file, type, scope, priority, name,
	 *         description, origin, cs_id, default_on, declares[], refused }
	 *         where refused is '' or the reason the file is never loaded.
	 */
	public static function build( array $contents ) {
		ksort( $contents );

		$snippets = array();
		$broken   = array();
		$by_slug  = array(); // slug => base names, to catch foo.php + foo.css.

		foreach ( $contents as $file => $text ) {
			$file = (string) $file;

			if ( strlen( (string) $text ) > self::MAX_FILE_BYTES ) {
				$broken[] = array(
					'file'   => $file,
					'reason' => 'The file is larger than ' . (int) ( self::MAX_FILE_BYTES / 1024 ) . ' KB.',
				);
				continue;
			}

			$parsed = Header::parse( $file, (string) $text );

			if ( ! $parsed['ok'] ) {
				$broken[] = array(
					'file'   => $file,
					'reason' => $parsed['error'],
				);
				continue;
			}

			$entry = array_merge(
				array(
					'slug' => $parsed['slug'],
					'file' => $file,
				),
				$parsed['fields'],
				array(
					'declares' => array(),
					'refused'  => '',
				)
			);

			if ( 'php' === $parsed['type'] ) {
				$syntax = Symbols::syntax_error( (string) $text );
				if ( '' !== $syntax['error'] ) {
					$broken[] = array(
						'file'   => $file,
						'reason' => 'PHP syntax error on line ' . $syntax['line'] . ': ' . $syntax['error'],
					);
					continue;
				}
				$entry['declares'] = Symbols::declared( (string) $text );
			}

			$by_slug[ $parsed['slug'] ][] = $file;
			$snippets[ $parsed['slug'] ]  = $entry;
		}

		// One slug, two files (foo.php and foo.css): neither is usable, the
		// on/off choice is stored per slug.
		foreach ( $by_slug as $slug => $files ) {
			if ( count( $files ) > 1 ) {
				foreach ( $files as $file ) {
					$broken[] = array(
						'file'   => $file,
						'reason' => 'Two files share the name "' . $slug . '" (' . implode( ', ', $files ) . '); rename one.',
					);
				}
				unset( $snippets[ $slug ] );
			}
		}

		$decls = array();
		foreach ( $snippets as $slug => $entry ) {
			if ( ! empty( $entry['declares'] ) ) {
				$decls[ $slug ] = $entry['declares'];
			}
		}

		$conflicts = Symbols::conflicts( $decls );

		foreach ( $conflicts as $conflict ) {
			if ( ! $conflict['refused'] ) {
				continue;
			}
			foreach ( $conflict['slugs'] as $slug ) {
				if ( isset( $snippets[ $slug ] ) && '' === $snippets[ $slug ]['refused'] ) {
					$others = array();
					foreach ( $conflict['slugs'] as $other ) {
						if ( $other !== $slug ) {
							$others[] = isset( $snippets[ $other ]['file'] ) ? $snippets[ $other ]['file'] : $other;
						}
					}
					$where = empty( $others ) ? 'twice in this file' : 'also in ' . implode( ', ', $others );

					$snippets[ $slug ]['refused'] = ( 'function' === $conflict['kind'] ? 'function ' . $conflict['name'] . '()' : 'class ' . $conflict['name'] ) . ' is declared ' . $where . ' — loading both would fatal.';
				}
			}
		}

		ksort( $snippets );
		usort(
			$broken,
			static function ( $a, $b ) {
				return strcmp( $a['file'] . $a['reason'], $b['file'] . $b['reason'] );
			}
		);

		return array(
			'snippets'  => $snippets,
			'broken'    => $broken,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * The index, from the stored copy while the folder is unchanged.
	 *
	 * @param string|null $dir   Folder (default: snippets/ next to this file).
	 * @param bool        $fresh Rebuild even when the signature matches (the
	 *                           admin page and the switch use this).
	 * @return array build() result plus 'sig' and 'built' (UNIX time).
	 */
	public static function get( $dir = null, $fresh = false ) {
		$dir = null === $dir ? self::dir() : rtrim( $dir, '/' ) . '/';

		if ( ! $fresh && is_array( self::$memo ) && $dir === self::$memo['dir'] ) {
			return self::$memo['index'];
		}

		$files = self::list_files( $dir );
		$sig   = self::signature( $dir, $files );

		if ( ! $fresh ) {
			$stored = get_option( self::OPTION_INDEX );
			if ( is_array( $stored ) && isset( $stored['sig'], $stored['snippets'] ) && $sig === $stored['sig'] ) {
				self::$memo = array(
					'dir'   => $dir,
					'index' => $stored,
				);
				return $stored;
			}
		}

		$contents = array();
		foreach ( $files as $file ) {
			$text              = @file_get_contents( $dir . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			$contents[ $file ] = false === $text ? '' : $text;
		}

		$index  = self::build( $contents );
		$stored = get_option( self::OPTION_INDEX );

		// Write only when something changed: a fresh read of an unchanged
		// folder (every admin page view) costs no write.
		$changed = ! is_array( $stored ) || ! isset( $stored['sig'], $stored['snippets'], $stored['broken'], $stored['conflicts'] )
			|| $sig !== $stored['sig']
			|| serialize( $index ) !== serialize( array( 'snippets' => $stored['snippets'], 'broken' => $stored['broken'], 'conflicts' => $stored['conflicts'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- comparison only.

		$index['sig']   = $sig;
		$index['built'] = $changed || ! isset( $stored['built'] ) ? time() : (int) $stored['built'];

		if ( $changed ) {
			update_option( self::OPTION_INDEX, $index, true );

			// The switch map is read on every request. Until the first
			// switch it does not exist, and WordPress remembers a missing
			// option only for the request (without a persistent object
			// cache): one SELECT per request. Create it, empty, now.
			add_option( Loader::OPTION_ENABLED, array(), '', true );
		}

		self::$memo = array(
			'dir'   => $dir,
			'index' => $index,
		);

		return $index;
	}

	/**
	 * Drop the stored index and the memo (next get() rebuilds).
	 */
	public static function forget() {
		self::$memo = null;
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_INDEX );
		}
	}

	/**
	 * Full path of a snippet's file.
	 *
	 * @param array       $snippet Index entry.
	 * @param string|null $dir     Folder.
	 * @return string
	 */
	public static function path_of( array $snippet, $dir = null ) {
		$dir = null === $dir ? self::dir() : rtrim( $dir, '/' ) . '/';
		return $dir . basename( (string) $snippet['file'] );
	}
}
