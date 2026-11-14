<?php
/**
 * A $wpdb that runs the Store's real SQL on SQLite (PDO), so the tests
 * prove the money rules on a real database: the UNIQUE keys, the guarded
 * UPDATE that cannot overdraw a card, transactions that roll back.
 *
 * The few MySQL-only statements the Store sends are translated:
 *   START TRANSACTION           → BEGIN
 *   SELECT GET_LOCK / RELEASE   → a lock table shared by every Fh_Wpdb in
 *                                 the process (so a second "request" on a
 *                                 second connection really waits / fails)
 *   SHOW TABLES LIKE / SHOW COLUMNS FROM / information_schema ENGINE
 *   LIKE '…'                    → LIKE '…' ESCAPE '\'
 * dbDelta() (defined in wp-stubs.php) turns the Store's CREATE TABLE into
 * SQLite tables and indexes, keeping each column's MySQL type for
 * SHOW COLUMNS — so the importer's schema check reads real types too.
 *
 * Every statement is kept in $queries (the off / Shadow tests count them).
 * fake-wpdb.php exits at once when run directly (the CI job runs every
 * tests/*.php).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'OBJECT', 'OBJECT' );
}

/**
 * Class Fh_Wpdb
 */
class Fh_Wpdb {

	public $prefix        = 'wp_';
	public $users         = 'wp_users';
	public $insert_id     = 0;
	public $last_error    = '';
	public $rows_affected = 0;
	public $queries       = array();
	public $pdo;

	/**
	 * MySQL column types by table (for SHOW COLUMNS).
	 *
	 * @var array<string, array<string, string>>
	 */
	public $types = array();

	/**
	 * Locks held, name => connection id (shared by every connection).
	 *
	 * @var array<string, int>
	 */
	public static $locks = array();

	/**
	 * Make GET_LOCK fail (a test of a busy site).
	 *
	 * @var bool
	 */
	public $lock_fails = false;

	/**
	 * Called before every statement: fn( string $sql ).
	 *
	 * @var callable|null
	 */
	public $before = null;

	private $suppress = false;
	private static $next = 1;
	private $id;

	/**
	 * Open a database (a file, so a second connection can share it).
	 *
	 * @param string   $file  SQLite file (':memory:' for one connection only).
	 * @param Fh_Wpdb|null $share Share this connection's types.
	 */
	public function __construct( $file = ':memory:', $share = null ) {
		$this->pdo = new PDO( 'sqlite:' . $file );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_TIMEOUT, 1 );
		$this->id = self::$next++;
		if ( $share ) {
			$this->types = &$share->types;
		}
	}

	public function get_charset_collate() {
		return '';
	}

	public function suppress_errors( $suppress = true ) {
		$prev           = $this->suppress;
		$this->suppress = (bool) $suppress;
		return $prev;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * wpdb::prepare(): %d %s %f (and %% ), values as one array or several.
	 */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$out = preg_replace_callback(
			'/%(%|d|s|f)/',
			function ( $m ) use ( &$i, $args ) {
				if ( '%' === $m[1] ) {
					return '%';
				}
				$v = $args[ $i++ ] ?? '';
				if ( 'd' === $m[1] ) {
					return (string) (int) $v;
				}
				if ( 'f' === $m[1] ) {
					return (string) (float) $v;
				}
				return "'" . str_replace( "'", "''", (string) $v ) . "'";
			},
			(string) $sql
		);
		return $out;
	}

	/**
	 * MySQL → SQLite, or a canned answer (array of rows) for SHOW / locks.
	 *
	 * @param string $sql SQL.
	 * @return string|array
	 */
	private function translate( $sql ) {
		$t = trim( $sql );
		if ( preg_match( '/^START TRANSACTION$/i', $t ) ) {
			return 'BEGIN';
		}
		if ( preg_match( "/^SELECT GET_LOCK\('([^']*)',\s*(-?\d+)\)$/i", $t, $m ) ) {
			$held = isset( self::$locks[ $m[1] ] ) && self::$locks[ $m[1] ] !== $this->id;
			if ( $this->lock_fails || $held ) {
				return array( array( 'v' => '0' ) );
			}
			self::$locks[ $m[1] ] = $this->id;
			return array( array( 'v' => '1' ) );
		}
		if ( preg_match( "/^SELECT RELEASE_LOCK\('([^']*)'\)$/i", $t, $m ) ) {
			if ( isset( self::$locks[ $m[1] ] ) && self::$locks[ $m[1] ] === $this->id ) {
				unset( self::$locks[ $m[1] ] );
			}
			return array( array( 'v' => '1' ) );
		}
		if ( preg_match( "/^SHOW TABLES LIKE '([^']*)'$/i", $t, $m ) ) {
			$name = str_replace( '\\_', '_', $m[1] );
			$st   = $this->pdo->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?" );
			$st->execute( array( $name ) );
			$row = $st->fetch( PDO::FETCH_NUM );
			return $row ? array( array( 'name' => $row[0] ) ) : array();
		}
		if ( preg_match( '/^SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`?$/i', $t, $m ) ) {
			if ( ! isset( $this->types[ $m[1] ] ) ) {
				throw new PDOException( "Table '{$m[1]}' doesn't exist" );
			}
			$rows = array();
			foreach ( $this->types[ $m[1] ] as $field => $type ) {
				$rows[] = array(
					'Field' => $field,
					'Type'  => $type,
				);
			}
			return $rows;
		}
		if ( false !== stripos( $t, 'information_schema.TABLES' ) ) {
			return array( array( 'ENGINE' => 'InnoDB' ) );
		}
		return preg_replace( "/LIKE ('(?:[^']|'')*')/", "LIKE $1 ESCAPE '\\\\'", $t );
	}

	/**
	 * Run a statement: an array of rows, an int (rows changed) or false.
	 */
	private function exec( $sql ) {
		$this->queries[] = $sql;
		if ( is_callable( $this->before ) ) {
			call_user_func( $this->before, $sql );
		}
		$this->last_error = '';
		try {
			$t = $this->translate( $sql );
			if ( is_array( $t ) ) {
				return $t;
			}
			if ( preg_match( '/^\s*(SELECT|PRAGMA)/i', $t ) ) {
				return $this->pdo->query( $t )->fetchAll( PDO::FETCH_ASSOC );
			}
			$n                   = $this->pdo->exec( $t );
			$this->rows_affected = (int) $n;
			if ( preg_match( '/^\s*INSERT/i', $t ) ) {
				$this->insert_id = (int) $this->pdo->lastInsertId();
			}
			return (int) $n;
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			if ( ! $this->suppress ) {
				$GLOBALS['fh_db_errors'][] = $this->last_error . ' — ' . $sql;
			}
			return false;
		}
	}

	public function query( $sql ) {
		$r = $this->exec( $sql );
		if ( is_array( $r ) ) {
			return count( $r );
		}
		return $r;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$r = $this->exec( $sql );
		if ( ! is_array( $r ) ) {
			return null;
		}
		return ARRAY_A === $output ? $r : array_map(
			static function ( $row ) {
				return (object) $row;
			},
			$r
		);
	}

	public function get_row( $sql, $output = OBJECT ) {
		$r = $this->get_results( $sql, $output );
		return $r ? $r[0] : null;
	}

	public function get_var( $sql ) {
		$r = $this->exec( $sql );
		if ( ! is_array( $r ) || ! $r ) {
			return null;
		}
		$v = array_values( $r[0] );
		return null === $v[0] ? null : (string) $v[0];
	}

	public function get_col( $sql ) {
		$r = $this->exec( $sql );
		if ( ! is_array( $r ) ) {
			return array();
		}
		return array_map(
			static function ( $row ) {
				$v = array_values( $row );
				return $v[0];
			},
			$r
		);
	}

	public function insert( $table, $data, $formats = null ) {
		$cols = array();
		$vals = array();
		foreach ( $data as $k => $v ) {
			$cols[] = '`' . $k . '`';
			$vals[] = null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : "'" . str_replace( "'", "''", (string) $v ) . "'" );
		}
		$r = $this->exec( 'INSERT INTO `' . $table . '` (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $vals ) . ')' );
		return false === $r ? false : $r;
	}

	/**
	 * A MySQL CREATE TABLE (dbDelta's dialect) as SQLite tables + indexes.
	 *
	 * @param string $sql CREATE TABLE.
	 */
	public function create_from_mysql( $sql ) {
		if ( ! preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*;?\s*$/s', $sql, $m ) ) {
			throw new RuntimeException( 'Not a CREATE TABLE: ' . $sql );
		}
		$table = $m[1];
		$cols  = array();
		$index = array();
		$types = array();
		foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^PRIMARY KEY/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^(UNIQUE )?KEY\s+(\w+)\s*\(([^)]*)\)/i', $line, $k ) ) {
				$index[] = 'CREATE ' . ( $k[1] ? 'UNIQUE ' : '' ) . "INDEX IF NOT EXISTS {$table}_{$k[2]} ON {$table} ({$k[3]})";
				continue;
			}
			if ( ! preg_match( '/^`?(\w+)`?\s+([a-z]+(?:\([^)]*\))?(?:\s+unsigned)?)(.*)$/i', $line, $c ) ) {
				continue;
			}
			$types[ $c[1] ] = strtolower( $c[2] );
			$rest           = $c[3];
			if ( false !== stripos( $rest, 'AUTO_INCREMENT' ) ) {
				$cols[] = "`{$c[1]}` INTEGER PRIMARY KEY AUTOINCREMENT";
				continue;
			}
			$kind = preg_match( '/int/i', $c[2] ) ? 'INTEGER' : 'TEXT';
			$cols[] = "`{$c[1]}` {$kind}" . preg_replace( '/\s+unsigned/i', '', $rest );
		}
		$this->pdo->exec( "CREATE TABLE IF NOT EXISTS `{$table}` (" . implode( ', ', $cols ) . ')' );
		foreach ( $index as $statement ) {
			$this->pdo->exec( $statement );
		}
		$this->types[ $table ] = $types;
	}

	/**
	 * A checksum of a table's rows (the "old tables are untouched" test).
	 *
	 * @param string $table Table.
	 * @return string
	 */
	public function checksum( $table ) {
		return md5( json_encode( $this->pdo->query( "SELECT * FROM `{$table}` ORDER BY 1" )->fetchAll( PDO::FETCH_ASSOC ) ) );
	}
}
