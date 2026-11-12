<?php
/**
 * An in-memory twin of the Requests Store: the same public methods the
 * Service, the follower, the Fees and the screens call, with no $wpdb.
 * Rows go through the SAME Store::columns() / Store::hydrate() as the real
 * store, so their shapes cannot drift; the guards of update() (the states
 * a request must still be in, a column that must still be empty) and the
 * one-statement use_link() behave as the SQL does.
 *
 * One seam a test may use that the real Store has no need for: every row
 * and log line is public, and log lines carry the test clock's time when
 * $GLOBALS['fh_clock'] is set.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests\Tests;

use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Store;
use FisHotel\Misc\Sections\Requests\Tokens;

// Helper, not a test: the CI job runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require. (The namespace
// statement has to come first, hence the order.)
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/**
	 * Request rows, id => hydrated row.
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Log lines, in the order they were written.
	 *
	 * @var array[]
	 */
	public $log = array();

	/**
	 * Private links, id => row.
	 *
	 * @var array[]
	 */
	public $links = array();

	private $next_id   = 0;
	private $next_log  = 0;
	private $next_link = 0;

	private function now() {
		return gmdate( 'Y-m-d H:i:s', isset( $GLOBALS['fh_clock'] ) ? (int) $GLOBALS['fh_clock'] : time() );
	}

	/* ---- requests -------------------------------------------------- */

	public function insert( array $row ) {
		$now = $this->now();
		$row = array_merge(
			array(
				'qty'        => 1,
				'source'     => 'hand',
				'state'      => 'new',
				'auto_match' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$row
		);

		$this->next_id++;
		$this->rows[ $this->next_id ] = Store::hydrate( array_merge( Store::columns( $row ), array( 'id' => $this->next_id ) ) );

		return $this->next_id;
	}

	public function update( $id, array $fields, array $states = array(), $if_null = '' ) {
		$id = (int) $id;
		if ( $id <= 0 || ! isset( $this->rows[ $id ] ) ) {
			return false;
		}

		$fields = array_diff_key( $fields, array( 'id' => 1, 'created_at' => 1 ) );
		if ( ! isset( $fields['updated_at'] ) ) {
			$fields['updated_at'] = $this->now();
		}
		$data = Store::columns( $fields );
		if ( ! $data ) {
			return false;
		}

		$states = array_values( array_intersect( array_map( 'strval', $states ), Rules::STATES ) );
		if ( $states && ! in_array( $this->rows[ $id ]['state'], $states, true ) ) {
			return false;
		}
		$if_null = isset( Store::COLUMNS[ (string) $if_null ] ) ? (string) $if_null : '';
		if ( '' !== $if_null && null !== $this->rows[ $id ][ $if_null ] ) {
			return false;
		}

		$this->rows[ $id ] = Store::hydrate( array_merge( $this->rows[ $id ], $data ) );

		return true;
	}

	public function get( $id ) {
		$id = (int) $id;
		return isset( $this->rows[ $id ] ) ? $this->rows[ $id ] : null;
	}

	public function find( array $filters = array() ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( self::passes( $row, $filters ) ) {
				$out[] = $row;
			}
		}

		$orderby = isset( $filters['orderby'] ) && in_array( (string) $filters['orderby'], Store::ORDER_COLUMNS, true ) ? (string) $filters['orderby'] : 'id';
		$sign    = isset( $filters['order'] ) && 'DESC' === strtoupper( (string) $filters['order'] ) ? -1 : 1;
		usort(
			$out,
			static function ( $a, $b ) use ( $orderby, $sign ) {
				$cmp = 'id' === $orderby ? ( $a['id'] <=> $b['id'] ) : strcasecmp( (string) $a[ $orderby ], (string) $b[ $orderby ] );
				return $sign * ( 0 !== $cmp ? $cmp : ( $a['id'] <=> $b['id'] ) );
			}
		);

		$limit  = isset( $filters['limit'] ) ? max( 1, min( Store::FIND_LIMIT, (int) $filters['limit'] ) ) : Store::FIND_LIMIT;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		return array_slice( $out, $offset, $limit );
	}

	public function count( array $filters = array() ) {
		$n = 0;
		foreach ( $this->rows as $row ) {
			if ( self::passes( $row, $filters ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The WHERE of the real store, in PHP.
	 */
	private static function passes( array $row, array $filters ) {
		if ( isset( $filters['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', (array) $filters['ids'] ) );
			if ( ! in_array( (int) $row['id'], $ids, true ) ) {
				return false;
			}
		}
		if ( isset( $filters['state'] ) && '' !== $filters['state'] && array() !== $filters['state'] ) {
			$states = array_intersect( array_map( 'strval', (array) $filters['state'] ), Rules::STATES );
			if ( ! in_array( $row['state'], $states, true ) ) {
				return false;
			}
		}
		foreach ( array( 'source', 'came_from', 'email', 'sheet' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] && (string) $row[ $key ] !== (string) $filters[ $key ] ) {
				return false;
			}
		}
		foreach ( array( 'user_id', 'line_id', 'cr_order_id', 'fee_ref', 'link_id', 'product_id' ) as $key ) {
			if ( isset( $filters[ $key ] ) && (int) $filters[ $key ] > 0 && (int) $row[ $key ] !== (int) $filters[ $key ] ) {
				return false;
			}
		}
		if ( isset( $filters['search'] ) && '' !== trim( (string) $filters['search'] ) ) {
			$needle = strtolower( trim( (string) $filters['search'] ) );
			$found  = false;
			foreach ( array( 'name', 'email', 'handle', 'fish' ) as $key ) {
				if ( false !== strpos( strtolower( (string) $row[ $key ] ), $needle ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}
		return true;
	}

	public function demand() {
		$out = array();
		foreach ( $this->rows as $row ) {
			$key = $row['fish_key'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'fish_key'      => $key,
					'fish'          => $row['fish'],
					'species_id'    => $row['species_id'],
					'product_id'    => $row['product_id'],
					'open_requests' => 0,
					'open_qty'      => 0,
					'all_requests'  => 0,
					'all_qty'       => 0,
					'first_at'      => $row['created_at'],
				);
			}
			$out[ $key ]['fish'] = min( $out[ $key ]['fish'], $row['fish'] );
			$out[ $key ]['all_requests']++;
			$out[ $key ]['all_qty'] += (int) $row['qty'];
			if ( Rules::is_open( $row['state'] ) ) {
				$out[ $key ]['open_requests']++;
				$out[ $key ]['open_qty'] += (int) $row['qty'];
			}
		}
		$out = array_values( $out );
		usort(
			$out,
			static function ( $a, $b ) {
				return ( $b['open_qty'] <=> $a['open_qty'] ) ?: ( $b['all_qty'] <=> $a['all_qty'] );
			}
		);
		return $out;
	}

	public function came_from_values() {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( '' !== $row['came_from'] ) {
				$out[ $row['came_from'] ] = true;
			}
		}
		$out = array_keys( $out );
		sort( $out );
		return $out;
	}

	/* ---- the log --------------------------------------------------- */

	public function log( $request_id, $action, $note = '', $user_id = 0, $actor = '' ) {
		if ( (int) $request_id <= 0 ) {
			return false;
		}
		$this->next_log++;
		$this->log[] = array(
			'id'         => $this->next_log,
			'request_id' => (int) $request_id,
			'action'     => substr( (string) $action, 0, 30 ),
			'note'       => (string) $note,
			'user_id'    => max( 0, (int) $user_id ),
			'actor'      => (string) $actor,
			'created_at' => $this->now(),
		);
		return true;
	}

	public function logs( array $request_ids ) {
		$ids = array_filter( array_map( 'intval', $request_ids ) );
		$out = array();
		foreach ( $this->log as $line ) {
			if ( in_array( $line['request_id'], $ids, true ) ) {
				$out[ $line['request_id'] ][] = $line;
			}
		}
		return $out;
	}

	/**
	 * The log actions of one request, in order (a test's shorthand).
	 *
	 * @param int $request_id Request id.
	 * @return string[]
	 */
	public function actions( $request_id ) {
		$out = array();
		foreach ( $this->log as $line ) {
			if ( (int) $request_id === $line['request_id'] ) {
				$out[] = $line['action'];
			}
		}
		return $out;
	}

	/**
	 * The notes of one request's log, in order.
	 *
	 * @param int $request_id Request id.
	 * @return string[]
	 */
	public function notes( $request_id ) {
		$out = array();
		foreach ( $this->log as $line ) {
			if ( (int) $request_id === $line['request_id'] ) {
				$out[] = $line['note'];
			}
		}
		return $out;
	}

	/* ---- private links --------------------------------------------- */

	public function insert_link( array $link ) {
		if ( '' === (string) ( $link['token'] ?? '' ) ) {
			return false;
		}
		$this->next_link++;
		$this->links[ $this->next_link ] = Store::hydrate_link(
			array(
				'id'         => $this->next_link,
				'token'      => (string) $link['token'],
				'label'      => (string) ( $link['label'] ?? '' ),
				'fee_amount' => (float) ( $link['fee_amount'] ?? 0 ),
				'expires_at' => ! empty( $link['expires_at'] ) ? (string) $link['expires_at'] : null,
				'max_uses'   => max( 0, (int) ( $link['max_uses'] ?? 0 ) ),
				'uses'       => 0,
				'active'     => 1,
				'created_by' => (int) ( $link['created_by'] ?? 0 ),
				'created_at' => $this->now(),
			)
		);
		return $this->next_link;
	}

	public function set_link_active( $id, $active ) {
		if ( ! isset( $this->links[ (int) $id ] ) ) {
			return false;
		}
		$this->links[ (int) $id ]['active'] = (bool) $active;
		return true;
	}

	public function use_link( $id ) {
		$id = (int) $id;
		if ( ! isset( $this->links[ $id ] ) ) {
			return false;
		}
		$link = $this->links[ $id ];
		if ( ! $link['active'] || ( $link['max_uses'] > 0 && $link['uses'] >= $link['max_uses'] ) ) {
			return false;
		}
		$this->links[ $id ]['uses']++;
		return true;
	}

	public function link( $id ) {
		return isset( $this->links[ (int) $id ] ) ? $this->links[ (int) $id ] : null;
	}

	public function link_by_token( $token ) {
		if ( ! Tokens::is_link( $token ) ) {
			return null;
		}
		foreach ( $this->links as $link ) {
			if ( $link['token'] === $token ) {
				return $link;
			}
		}
		return null;
	}

	public function links() {
		$out = array();
		foreach ( array_reverse( $this->links, true ) as $link ) {
			$link['request_count'] = $this->count( array( 'link_id' => $link['id'] ) );
			$out[]                 = $link;
		}
		return $out;
	}
}
