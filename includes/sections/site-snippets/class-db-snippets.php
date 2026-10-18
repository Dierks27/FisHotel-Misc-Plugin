<?php
/**
 * Database snippets at run time — PURE apart from nothing: the snippets the
 * site owner writes in wp-admin (stored in {prefix}fh_snippets by Db_Store)
 * as the request sees them.
 *
 * An ordinary request never queries the snippet tables. Every write in the
 * editor rebuilds ONE autoloaded option, fishotel_snippets_db_active, from
 * the active rows (active_option()): slug => { id, slug, type, scope,
 * priority, code, version, declares }. The loader reads only that option
 * and merge()s it with the file index:
 *
 *   - a database snippet becomes an index-shaped entry keyed `db:<slug>`
 *     (a slug cannot contain a colon, so it never meets a file's key or the
 *     switch map), source 'db', switched on (only active ones are stored);
 *   - one whose slug a file in snippets/ also has (usable or broken) is
 *     SUPERSEDED by the GitHub file and never runs;
 *   - one that declares a function or class a file snippet or another
 *     database snippet declares (unguarded, as Symbols::conflicts() reads
 *     it) is refused — the file keeps running. The editor refuses such a
 *     save; this catches a file deployed later that clashes.
 *
 * The stored code has no opening <?php tag; syntax_error() and declared()
 * read it as PHP by prefixing one, with line numbers of the stored code.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Db_Snippets
 */
class Db_Snippets {

	/**
	 * Autoloaded option: the active database snippets, rebuilt on every write.
	 *
	 * @var string
	 */
	const OPTION_ACTIVE = 'fishotel_snippets_db_active';

	/**
	 * Prefix of a database snippet's key in the merged map.
	 *
	 * @var string
	 */
	const KEY_PREFIX = 'db:';

	/**
	 * The merged-map key of a database snippet.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function key( $slug ) {
		return self::KEY_PREFIX . (string) $slug;
	}

	/**
	 * Whether a merged-map key belongs to a database snippet.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_key( $key ) {
		return 0 === strpos( (string) $key, self::KEY_PREFIX );
	}

	/**
	 * The first syntax error of stored PHP code (no opening tag), with the
	 * stored code's line number.
	 *
	 * @param string $code Stored code.
	 * @return array{error: string, line: int}
	 */
	public static function syntax_error( $code ) {
		$found = Symbols::syntax_error( "<?php\n" . (string) $code );
		if ( '' !== $found['error'] ) {
			$found['line'] = max( 1, (int) $found['line'] - 1 );
		}
		return $found;
	}

	/**
	 * Functions and classes stored PHP code declares (Symbols::declared()
	 * shape, lines of the stored code).
	 *
	 * @param string $code Stored code.
	 * @return array
	 */
	public static function declared( $code ) {
		$out = Symbols::declared( "<?php\n" . (string) $code );
		foreach ( $out as $n => $decl ) {
			$out[ $n ]['line'] = max( 1, (int) $decl['line'] - 1 );
		}
		return $out;
	}

	/**
	 * The active option from table rows — only the active ones, only what
	 * the loader needs (no name, no description), PHP with its
	 * declarations so the exists-now preflight needs no tokenizer on an
	 * ordinary request.
	 *
	 * @param array $rows Rows of {prefix}fh_snippets.
	 * @return array{map: array<string, array>, bytes: int} bytes = serialized size.
	 */
	public static function active_option( array $rows ) {
		$map = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['active'] ) || ! isset( $row['slug'], $row['type'] ) ) {
				continue;
			}

			$type  = (string) $row['type'];
			$code  = isset( $row['code'] ) ? (string) $row['code'] : '';
			$entry = array(
				'id'       => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'slug'     => (string) $row['slug'],
				'type'     => $type,
				'scope'    => isset( $row['scope'] ) ? (string) $row['scope'] : '',
				'priority' => isset( $row['priority'] ) ? (int) $row['priority'] : 10,
				'code'     => $code,
				'version'  => isset( $row['version'] ) ? (int) $row['version'] : 1,
				'declares' => 'php' === $type ? self::declared( $code ) : array(),
			);

			$map[ $entry['slug'] ] = $entry;
		}

		ksort( $map );

		return array(
			'map'   => $map,
			'bytes' => strlen( serialize( $map ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the size WordPress will store.
		);
	}

	/**
	 * An index-shaped entry for one database snippet (active option entry
	 * or an equivalent array).
	 *
	 * @param array $active Active-option entry.
	 * @return array
	 */
	public static function entry( array $active ) {
		$slug = isset( $active['slug'] ) ? (string) $active['slug'] : '';

		return array(
			'slug'        => $slug,
			'key'         => self::key( $slug ),
			'source'      => 'db',
			'id'          => isset( $active['id'] ) ? (int) $active['id'] : 0,
			'file'        => '',
			'type'        => isset( $active['type'] ) ? (string) $active['type'] : '',
			'scope'       => isset( $active['scope'] ) ? (string) $active['scope'] : '',
			'priority'    => isset( $active['priority'] ) ? (int) $active['priority'] : 10,
			'name'        => $slug,
			'description' => '',
			'origin'      => '',
			'cs_id'       => 0,
			'default_on'  => true,
			'declares'    => isset( $active['declares'] ) && is_array( $active['declares'] ) ? $active['declares'] : array(),
			'refused'     => '',
			'superseded'  => '',
			'version'     => isset( $active['version'] ) ? (int) $active['version'] : 1,
			'code'        => isset( $active['code'] ) ? (string) $active['code'] : '',
		);
	}

	/**
	 * The file in snippets/ that supersedes a database snippet of this slug
	 * ('' when none): a usable file of that slug, or a broken one (a file in
	 * GitHub means the snippet moved there; the copy must not run beside it).
	 *
	 * @param string $slug  Slug.
	 * @param array  $index Catalog index (snippets, broken).
	 * @return string File name, or ''.
	 */
	public static function superseded_by( $slug, array $index ) {
		$slug = (string) $slug;

		if ( isset( $index['snippets'][ $slug ] ) ) {
			return isset( $index['snippets'][ $slug ]['file'] ) ? (string) $index['snippets'][ $slug ]['file'] : $slug;
		}

		foreach ( isset( $index['broken'] ) ? (array) $index['broken'] : array() as $row ) {
			$file = isset( $row['file'] ) ? (string) $row['file'] : '';
			if ( preg_replace( '/\.(php|css|js|html)$/', '', $file ) === $slug ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * The file snippets plus the active database snippets, keyed slug /
	 * `db:<slug>`, ready for Rules::php_plan() and Rules::output_plan().
	 * Without any active database snippet this is the index's own map,
	 * untouched.
	 *
	 * @param array $index     Catalog index.
	 * @param mixed $db_active The active option (slug => entry).
	 * @return array<string, array>
	 */
	public static function merge( array $index, $db_active ) {
		$files = isset( $index['snippets'] ) && is_array( $index['snippets'] ) ? $index['snippets'] : array();

		if ( ! is_array( $db_active ) || empty( $db_active ) ) {
			return $files;
		}

		$out       = $files;
		$decls     = array();
		$has_decls = false;

		foreach ( $files as $slug => $snippet ) {
			if ( ! empty( $snippet['declares'] ) ) {
				$decls[ (string) $slug ] = $snippet['declares'];
			}
		}

		foreach ( $db_active as $slug => $active ) {
			if ( ! is_array( $active ) || ! isset( $active['type'] ) ) {
				continue;
			}
			if ( ! isset( $active['slug'] ) ) {
				$active['slug'] = (string) $slug;
			}

			$entry = self::entry( $active );
			$file  = self::superseded_by( $entry['slug'], $index );

			if ( '' !== $file ) {
				$entry['superseded'] = $file;
				$entry['refused']    = 'Superseded by the GitHub file ' . $file . ': the database copy never runs.';
			} elseif ( ! empty( $entry['declares'] ) ) {
				$decls[ $entry['key'] ] = $entry['declares'];
				$has_decls              = true;
			}

			$out[ $entry['key'] ] = $entry;
		}

		if ( ! $has_decls ) {
			return $out;
		}

		foreach ( Symbols::conflicts( $decls ) as $conflict ) {
			if ( ! $conflict['refused'] ) {
				continue;
			}
			foreach ( $conflict['slugs'] as $key ) {
				$key = (string) $key;
				if ( ! self::is_key( $key ) || ! isset( $out[ $key ] ) || '' !== $out[ $key ]['refused'] ) {
					continue;
				}
				$out[ $key ]['refused'] = self::clash_text( $conflict, $key, $files );
			}
		}

		return $out;
	}

	/**
	 * "function x() is also declared by the file a.php, database snippet b
	 * — …" for one side of a clash.
	 *
	 * @param array  $conflict Symbols::conflicts() entry.
	 * @param string $self_key The key the text is for.
	 * @param array  $files    File snippets (slug => entry).
	 * @return string
	 */
	public static function clash_text( array $conflict, $self_key, array $files ) {
		$others = array();
		foreach ( $conflict['slugs'] as $other ) {
			$other = (string) $other;
			if ( $other === $self_key ) {
				continue;
			}
			if ( self::is_key( $other ) ) {
				$others[] = 'database snippet ' . substr( $other, strlen( self::KEY_PREFIX ) );
			} else {
				$others[] = 'the file ' . ( isset( $files[ $other ]['file'] ) ? $files[ $other ]['file'] : $other );
			}
		}

		$what  = 'function' === $conflict['kind'] ? 'function ' . $conflict['name'] . '()' : 'class ' . $conflict['name'];
		$where = empty( $others ) ? 'twice in this snippet' : 'also by ' . implode( ', ', $others );

		return $what . ' is declared ' . $where . ' — loading both would fatal.';
	}
}
