<?php
/**
 * An in-memory Db_Store: the same methods, arrays instead of tables, the
 * same unique keys (snippet slug; revision snippet_id + version), the same
 * optimistic lock on update(), and transactions that really roll back.
 *
 * Seams a test may use that the real store has no need for:
 *   - $fail['method'] = true makes that method fail (false / null);
 *   - $persist = a file path: the state is written there as JSON after
 *     every write outside a transaction and on commit, so a test can read
 *     what a child process wrote before it died.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets\Tests;

// Helper, not a test: the CI runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Db_Store {

	/** @var array id => row */
	public $snippets = array();

	/** @var array id => row */
	public $revisions = array();

	/** @var array id => row */
	public $log = array();

	/** @var array table => next id */
	public $next = array(
		'snippets'  => 1,
		'revisions' => 1,
		'log'       => 1,
	);

	/** @var array method => true */
	public $fail = array();

	/** @var string[] begin / commit / rollback, in order */
	public $transactions = array();

	/** @var string File the state is written to ('' = none). */
	public $persist = '';

	/** @var array|null State at begin(). */
	private $snapshot = null;

	/* -------------------------------------------------------------- */

	public function begin() {
		$this->snapshot       = $this->state();
		$this->transactions[] = 'begin';
	}

	public function commit() {
		$this->snapshot       = null;
		$this->transactions[] = 'commit';
		$this->write_state();
	}

	public function rollback() {
		if ( null !== $this->snapshot ) {
			$this->load_state( $this->snapshot );
		}
		$this->snapshot       = null;
		$this->transactions[] = 'rollback';
		$this->write_state();
	}

	/* -------------------------------------------------------------- */

	public function all() {
		if ( ! empty( $this->fail['all'] ) ) {
			return null;
		}
		$rows = array_values( $this->snippets );
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);
		return $rows;
	}

	public function get( $id ) {
		return isset( $this->snippets[ (int) $id ] ) ? $this->snippets[ (int) $id ] : null;
	}

	public function get_by_slug( $slug ) {
		foreach ( $this->snippets as $row ) {
			if ( $row['slug'] === (string) $slug ) {
				return $row;
			}
		}
		return null;
	}

	public function insert( array $row ) {
		if ( ! empty( $this->fail['insert'] ) || null !== $this->get_by_slug( $row['slug'] ) ) {
			return false;
		}
		$id = ! empty( $row['id'] ) ? (int) $row['id'] : $this->next['snippets'];
		if ( isset( $this->snippets[ $id ] ) ) {
			return false;
		}
		$this->next['snippets'] = max( $this->next['snippets'], $id + 1 );

		$this->snippets[ $id ] = array(
			'id'          => $id,
			'slug'        => (string) $row['slug'],
			'name'        => (string) $row['name'],
			'description' => (string) $row['description'],
			'type'        => (string) $row['type'],
			'scope'       => (string) $row['scope'],
			'priority'    => (int) $row['priority'],
			'active'      => empty( $row['active'] ) ? 0 : 1,
			'code'        => (string) $row['code'],
			'version'     => (int) $row['version'],
			'created_by'  => (int) $row['created_by'],
			'created_at'  => (string) $row['created_at'],
			'updated_by'  => (int) $row['updated_by'],
			'updated_at'  => (string) $row['updated_at'],
			'exported_at' => null,
		);
		$this->write_state();

		return $id;
	}

	public function update( $id, array $row, $expected_version ) {
		$id = (int) $id;
		if ( ! empty( $this->fail['update'] ) || ! isset( $this->snippets[ $id ] ) || (int) $this->snippets[ $id ]['version'] !== (int) $expected_version ) {
			return false;
		}
		$other = $this->get_by_slug( $row['slug'] );
		if ( null !== $other && (int) $other['id'] !== $id ) {
			return false;
		}
		foreach ( array( 'slug', 'name', 'description', 'type', 'scope', 'code', 'updated_at' ) as $key ) {
			$this->snippets[ $id ][ $key ] = (string) $row[ $key ];
		}
		foreach ( array( 'priority', 'version', 'updated_by' ) as $key ) {
			$this->snippets[ $id ][ $key ] = (int) $row[ $key ];
		}
		$this->snippets[ $id ]['active'] = empty( $row['active'] ) ? 0 : 1;
		$this->write_state();
		return true;
	}

	public function delete( $id ) {
		if ( ! empty( $this->fail['delete'] ) || ! isset( $this->snippets[ (int) $id ] ) ) {
			return false;
		}
		unset( $this->snippets[ (int) $id ] );
		$this->write_state();
		return true;
	}

	public function set_exported( $id, $when ) {
		if ( ! empty( $this->fail['set_exported'] ) || ! isset( $this->snippets[ (int) $id ] ) ) {
			return false;
		}
		$this->snippets[ (int) $id ]['exported_at'] = (string) $when;
		$this->write_state();
		return true;
	}

	/* -------------------------------------------------------------- */

	public function add_revision( array $rev ) {
		if ( ! empty( $this->fail['add_revision'] ) ) {
			return false;
		}
		foreach ( $this->revisions as $row ) {
			if ( (int) $row['snippet_id'] === (int) $rev['snippet_id'] && (int) $row['version'] === (int) $rev['version'] ) {
				return false; // UNIQUE KEY snippet_version.
			}
		}
		$id                     = $this->next['revisions']++;
		$this->revisions[ $id ] = array(
			'id'         => $id,
			'snippet_id' => (int) $rev['snippet_id'],
			'version'    => (int) $rev['version'],
			'code'       => (string) $rev['code'],
			'meta'       => json_encode( \FisHotel\Misc\Sections\Site_Snippets\Editor_Rules::meta_of( (array) $rev['meta'] ) ),
			'user_id'    => (int) $rev['user_id'],
			'created_at' => (string) $rev['created_at'],
		);
		$this->write_state();
		return $id;
	}

	public function revisions( $snippet_id, $limit = 200 ) {
		$out = array();
		foreach ( $this->revisions as $row ) {
			if ( (int) $row['snippet_id'] === (int) $snippet_id ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['version'] - $a['version'];
			}
		);
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	public function revision( $id ) {
		return isset( $this->revisions[ (int) $id ] ) ? $this->revisions[ (int) $id ] : null;
	}

	public function last_revision( $snippet_id ) {
		$rows = $this->revisions( $snippet_id, 1 );
		return empty( $rows ) ? null : $rows[0];
	}

	/* -------------------------------------------------------------- */

	public function append_log( array $row ) {
		if ( ! empty( $this->fail['append_log'] ) ) {
			return false;
		}
		$id               = $this->next['log']++;
		$this->log[ $id ] = array_merge( array( 'id' => $id ), $row );
		$this->write_state();
		return $id;
	}

	public function log_entry( $id ) {
		return isset( $this->log[ (int) $id ] ) ? $this->log[ (int) $id ] : null;
	}

	public function log_rows( array $filters, $limit = 50, $offset = 0 ) {
		$rows = array_reverse( $this->filtered( $filters ) );
		return array_slice( $rows, max( 0, (int) $offset ), max( 1, (int) $limit ) );
	}

	public function log_count( array $filters ) {
		return count( $this->filtered( $filters ) );
	}

	public function log_after( $after_id, $limit = 500 ) {
		$out = array();
		foreach ( $this->log as $id => $row ) {
			if ( $id > (int) $after_id ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Actions of the log rows, oldest first (tests).
	 *
	 * @return string[]
	 */
	public function actions() {
		return array_values(
			array_map(
				static function ( $row ) {
					return $row['action'];
				},
				$this->log
			)
		);
	}

	/* -------------------------------------------------------------- */

	private function filtered( array $filters ) {
		$out = array();
		foreach ( $this->log as $row ) {
			if ( ! empty( $filters['action'] ) && $row['action'] !== $filters['action'] ) {
				continue;
			}
			if ( ! empty( $filters['slug'] ) && $row['slug'] !== $filters['slug'] ) {
				continue;
			}
			if ( ! empty( $filters['snippet_id'] ) && (int) $row['snippet_id'] !== (int) $filters['snippet_id'] ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	private function state() {
		return array(
			'snippets'  => $this->snippets,
			'revisions' => $this->revisions,
			'log'       => $this->log,
			'next'      => $this->next,
		);
	}

	private function load_state( array $state ) {
		$this->snippets  = $state['snippets'];
		$this->revisions = $state['revisions'];
		$this->log       = $state['log'];
		$this->next      = $state['next'];
	}

	private function write_state() {
		if ( '' === $this->persist || null !== $this->snapshot ) {
			return;
		}
		file_put_contents( $this->persist, json_encode( $this->state() ) );
	}
}
