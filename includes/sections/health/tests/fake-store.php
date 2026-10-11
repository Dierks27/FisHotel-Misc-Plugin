<?php
/**
 * An in-memory Store: the same methods the Logger, Facts and Checks use,
 * with no $wpdb. Rows are kept in insertion order with integer ids.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health\Tests;

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require. (The
// namespace statement has to come first, hence the order.)
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/**
	 * Stored rows, id => row.
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Next id.
	 *
	 * @var int
	 */
	public $next_id = 1;

	/**
	 * When true every method throws, to stand in for a dead database.
	 *
	 * @var bool
	 */
	public $poison = false;

	private function guard() {
		if ( $this->poison ) {
			throw new \RuntimeException( 'database gone' );
		}
	}

	public function insert( array $row ) {
		$this->guard();

		$id         = $this->next_id++;
		$row['id']  = $id;
		$row['context'] = is_array( $row['context'] ?? null ) ? $row['context'] : array();
		$this->rows[ $id ] = $row;

		return $id;
	}

	public function find_recent( $fingerprint, $since_ts ) {
		$this->guard();

		$since = gmdate( 'Y-m-d H:i:s', (int) $since_ts );

		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( $row['fingerprint'] === $fingerprint && $row['logged_at'] >= $since ) {
				return array(
					'id'        => $row['id'],
					'logged_at' => $row['logged_at'],
					'context'   => $row['context'],
				);
			}
		}

		return null;
	}

	public function bump_repeat( $id, $logged_at ) {
		$this->guard();

		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}

		$this->rows[ $id ]['context']['repeats'] = (int) ( $this->rows[ $id ]['context']['repeats'] ?? 1 ) + 1;
		$this->rows[ $id ]['logged_at']          = $logged_at;

		return true;
	}

	public function recent_errors( $n = 5 ) {
		$out = array();
		foreach ( array_reverse( $this->rows, true ) as $row ) {
			if ( in_array( $row['level'], array( 'error', 'critical' ), true ) ) {
				$row['logged_ts'] = (int) strtotime( $row['logged_at'] . ' UTC' );
				$row['repeats']   = (int) ( $row['context']['repeats'] ?? 1 );
				$out[]            = $row;
			}
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	}

	public function count_since( $level, $since_ts, $source = '' ) {
		$since = gmdate( 'Y-m-d H:i:s', (int) $since_ts );
		$n     = 0;
		foreach ( $this->rows as $row ) {
			if ( $row['logged_at'] < $since ) {
				continue;
			}
			if ( '' !== $level && $row['level'] !== $level ) {
				continue;
			}
			if ( '' !== $source && $row['source'] !== $source ) {
				continue;
			}
			$n++;
		}
		return $n;
	}

	public function sum_repeats_since( $level, $since_ts, $source = '' ) {
		$since = gmdate( 'Y-m-d H:i:s', (int) $since_ts );
		$sum   = 0;
		foreach ( $this->rows as $row ) {
			if ( $row['logged_at'] < $since ) {
				continue;
			}
			if ( '' !== $level && $row['level'] !== $level ) {
				continue;
			}
			if ( '' !== $source && $row['source'] !== $source ) {
				continue;
			}
			$sum += max( 1, (int) ( $row['context']['repeats'] ?? 1 ) );
		}
		return $sum;
	}

	/**
	 * Paged query, last-seen first like the real Store (logged_at DESC,
	 * id DESC), with the same MAX_PER_PAGE clamp.
	 */
	public function query( array $filters, $page = 1, $per_page = 50 ) {
		$this->guard();

		$rows = array_values(
			array_filter(
				$this->rows,
				static function ( $row ) use ( $filters ) {
					if ( ! empty( $filters['level'] ) && $row['level'] !== $filters['level'] ) {
						return false;
					}
					if ( ! empty( $filters['source'] ) && $row['source'] !== $filters['source'] ) {
						return false;
					}
					if ( ! empty( $filters['search'] ) && false === stripos( $row['message'] . ' ' . json_encode( $row['context'] ), (string) $filters['search'] ) ) {
						return false;
					}
					return true;
				}
			)
		);

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b['logged_at'], $a['logged_at'] ) ?: ( $b['id'] <=> $a['id'] );
			}
		);

		$rows = array_map(
			static function ( $row ) {
				$row['logged_ts'] = (int) strtotime( $row['logged_at'] . ' UTC' );
				$row['repeats']   = (int) ( $row['context']['repeats'] ?? 1 );
				return $row;
			},
			$rows
		);

		$page     = max( 1, (int) $page );
		$per_page = max( 1, min( \FisHotel\Misc\Sections\Health\Store::MAX_PER_PAGE, (int) $per_page ) );

		return array(
			'rows'  => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $rows ),
		);
	}

	public function last() {
		return $this->rows ? end( $this->rows ) : null;
	}

	public function count() {
		return count( $this->rows );
	}
}
