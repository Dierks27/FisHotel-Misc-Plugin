<?php
/**
 * An in-memory Store: the same methods and the same rules as the real one
 * (a status changes only where it still is what the caller read, labels
 * are never handed out twice, transactions really roll back), with arrays
 * instead of tables and no $wpdb.
 *
 * Seams a test may use that the real store has no need for:
 *   - $fail['method'] = true makes that method fail once asked (false / 0);
 *   - $transactions = false behaves like tables without transactions:
 *     begin / commit answer yes, rollback undoes nothing;
 *   - $calls lists every write, in order.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts\Tests;

// Helper, not a test: the CI runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/** @var array id => row (id, label, length, width, status, origin, plan_id, used_plan_id, note, created_at, updated_at) */
	public $leftovers = array();

	/** @var array id => row */
	public $plans = array();

	/** @var array id => row (id, kind, sheets, plan_id, note, user_id, created_at) */
	public $sheet_log = array();

	/** @var int */
	public $next_label = 1;

	/** @var array table => next id */
	public $next = array(
		'leftovers' => 1,
		'plans'     => 1,
		'sheet_log' => 1,
	);

	/** @var array method => true */
	public $fail = array();

	/** @var bool */
	public $transactions = true;

	/** @var string[] */
	public $calls = array();

	/** @var array|null */
	private $snapshot = null;

	/**
	 * Everything that makes up the stock, for "nothing changed" checks.
	 *
	 * @return array
	 */
	public function state() {
		$leftovers = array();
		foreach ( $this->leftovers as $row ) {
			unset( $row['created_at'], $row['updated_at'] );
			$leftovers[ $row['id'] ] = $row;
		}
		$plans = array();
		foreach ( $this->plans as $row ) {
			$plans[ $row['id'] ] = $row['status'];
		}
		return array(
			'leftovers'  => $leftovers,
			'plans'      => $plans,
			'sheets'     => $this->sheets(),
			'entries'    => count( $this->sheet_log ),
			'next_label' => $this->next_label,
		);
	}

	/* Transactions */

	public function begin() {
		$this->calls[] = 'begin';
		if ( ! empty( $this->fail['begin'] ) ) {
			return false;
		}
		if ( $this->transactions ) {
			$this->snapshot = array( $this->leftovers, $this->plans, $this->sheet_log, $this->next );
		}
		return true;
	}

	public function commit() {
		$this->calls[] = 'commit';
		if ( ! empty( $this->fail['commit'] ) ) {
			// As a cluster does: a refused COMMIT keeps nothing.
			$this->rollback();
			return false;
		}
		$this->snapshot = null;
		return true;
	}

	public function rollback() {
		$this->calls[] = 'rollback';
		if ( $this->transactions && null !== $this->snapshot ) {
			list( $this->leftovers, $this->plans, $this->sheet_log, $this->next ) = $this->snapshot;
			$this->snapshot = null;
		}
		return true;
	}

	/* Leftovers */

	public function leftovers( $status = 'stock', $limit = 500 ) {
		$out = array();
		foreach ( $this->leftovers as $row ) {
			if ( $row['status'] === $status ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	public function leftover( $id ) {
		return isset( $this->leftovers[ (int) $id ] ) ? $this->leftovers[ (int) $id ] : null;
	}

	public function leftovers_by_ids( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->leftovers[ (int) $id ] ) ) {
				$out[ (int) $id ] = $this->leftovers[ (int) $id ];
			}
		}
		return $out;
	}

	public function leftovers_of_plan( $plan_id ) {
		$out = array();
		foreach ( $this->leftovers as $row ) {
			if ( (int) $plan_id === $row['plan_id'] ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function add_leftover( array $row ) {
		$this->calls[] = 'add_leftover';
		if ( ! empty( $this->fail['add_leftover'] ) ) {
			return false;
		}
		$id                     = $this->next['leftovers']++;
		$this->leftovers[ $id ] = array(
			'id'           => $id,
			'label'        => (string) ( isset( $row['label'] ) ? $row['label'] : '' ),
			'length'       => (int) $row['length'],
			'width'        => (int) $row['width'],
			'status'       => isset( $row['status'] ) ? (string) $row['status'] : 'stock',
			'origin'       => isset( $row['origin'] ) && 'plan' === $row['origin'] ? 'plan' : 'manual',
			'plan_id'      => isset( $row['plan_id'] ) ? (int) $row['plan_id'] : 0,
			'used_plan_id' => 0,
			'note'         => isset( $row['note'] ) ? (string) $row['note'] : '',
			'created_at'   => '2026-03-10 09:00:00',
			'updated_at'   => '2026-03-10 09:00:00',
		);
		return $id;
	}

	public function update_leftover( $id, array $fields ) {
		$this->calls[] = 'update_leftover';
		$id            = (int) $id;
		if ( ! empty( $this->fail['update_leftover'] ) || ! isset( $this->leftovers[ $id ] ) || 'stock' !== $this->leftovers[ $id ]['status'] ) {
			return false;
		}
		foreach ( array( 'length', 'width', 'note' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$this->leftovers[ $id ][ $key ] = 'note' === $key ? (string) $fields[ $key ] : (int) $fields[ $key ];
			}
		}
		return true;
	}

	public function mark_leftovers( array $ids, $from, $to, $plan_id = 0 ) {
		$this->calls[] = 'mark_leftovers';
		if ( ! empty( $this->fail['mark_leftovers'] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( ! isset( $this->leftovers[ $id ] ) || $this->leftovers[ $id ]['status'] !== $from ) {
				continue;
			}
			if ( 'used' === $from && $this->leftovers[ $id ]['used_plan_id'] !== (int) $plan_id ) {
				continue;
			}
			$this->leftovers[ $id ]['status']       = $to;
			$this->leftovers[ $id ]['used_plan_id'] = 'used' === $to ? (int) $plan_id : 0;
			$n++;
		}
		return $n;
	}

	public function delete_leftovers_of_plan( $plan_id ) {
		$this->calls[] = 'delete_leftovers_of_plan';
		if ( ! empty( $this->fail['delete_leftovers_of_plan'] ) || (int) $plan_id < 1 ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->leftovers as $id => $row ) {
			if ( (int) $plan_id === $row['plan_id'] && 'stock' === $row['status'] ) {
				unset( $this->leftovers[ $id ] );
				$n++;
			}
		}
		return $n;
	}

	/* Whole sheets */

	public function sheets() {
		return array_sum( array_column( $this->sheet_log, 'sheets' ) );
	}

	public function sheet_log( $limit = 30 ) {
		return array_slice( array_reverse( array_values( $this->sheet_log ) ), 0, $limit );
	}

	public function add_sheet_entry( $kind, $sheets, $plan_id = 0, $note = '', $user_id = 0 ) {
		$this->calls[] = 'add_sheet_entry';
		if ( ! empty( $this->fail['add_sheet_entry'] ) ) {
			return false;
		}
		$id                     = $this->next['sheet_log']++;
		$this->sheet_log[ $id ] = array(
			'id'         => $id,
			'kind'       => (string) $kind,
			'sheets'     => (int) $sheets,
			'plan_id'    => (int) $plan_id,
			'note'       => (string) $note,
			'user_id'    => (int) $user_id,
			'created_at' => '2026-03-10 09:00:00',
		);
		return $id;
	}

	/* Plans */

	public function plans( $limit = 20 ) {
		$out = array();
		foreach ( array_reverse( array_values( $this->plans ) ) as $row ) {
			unset( $row['data'] );
			$out[] = $row;
		}
		return array_slice( $out, 0, $limit );
	}

	public function plan( $id ) {
		return isset( $this->plans[ (int) $id ] ) ? $this->plans[ (int) $id ] : null;
	}

	public function latest_plan() {
		return $this->plans ? end( $this->plans ) : null;
	}

	public function plans_for_day( $ship_ymd, $limit = 20 ) {
		$out = array();
		foreach ( array_reverse( array_values( $this->plans ) ) as $row ) {
			if ( '' !== (string) $ship_ymd && $row['ship_date'] === (string) $ship_ymd && 'orders' === $row['origin'] ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	public function add_plan( array $row ) {
		$this->calls[] = 'add_plan';
		if ( ! empty( $this->fail['add_plan'] ) ) {
			return false;
		}
		$id = $this->next['plans']++;
		// Through JSON and back, as the real store keeps it.
		$this->plans[ $id ] = array(
			'id'          => $id,
			'status'      => 'draft',
			'title'       => (string) ( isset( $row['title'] ) ? $row['title'] : '' ),
			'origin'      => isset( $row['origin'] ) && 'orders' === $row['origin'] ? 'orders' : 'typed',
			'ship_date'   => isset( $row['ship_date'] ) ? (string) $row['ship_date'] : '',
			'sheets'      => isset( $row['sheets'] ) ? (int) $row['sheets'] : 0,
			'label_from'  => isset( $row['label_from'] ) ? (int) $row['label_from'] : 0,
			'label_count' => isset( $row['label_count'] ) ? (int) $row['label_count'] : 0,
			'created_by'  => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
			'created_at'  => '2026-03-10 09:00:00',
			'cut_by'      => 0,
			'cut_at'      => '',
			'undone_at'   => '',
			'updated_at'  => '2026-03-10 09:00:00',
			'data'        => json_decode( json_encode( $row['data'] ), true ),
		);
		return $id;
	}

	public function set_plan_status( $id, $from, $to, $user_id = 0 ) {
		$this->calls[] = 'set_plan_status';
		$id            = (int) $id;
		if ( ! empty( $this->fail['set_plan_status'] ) || $from === $to || ! isset( $this->plans[ $id ] ) || $this->plans[ $id ]['status'] !== $from ) {
			return false;
		}
		$this->plans[ $id ]['status'] = $to;
		if ( 'cut' === $to ) {
			$this->plans[ $id ]['cut_by'] = (int) $user_id;
			$this->plans[ $id ]['cut_at'] = '2026-03-10 10:00:00';
		} else {
			$this->plans[ $id ]['undone_at'] = '2026-03-10 11:00:00';
		}
		return true;
	}

	public function delete_plan( $id ) {
		$this->calls[] = 'delete_plan';
		$id            = (int) $id;
		if ( ! empty( $this->fail['delete_plan'] ) || ! isset( $this->plans[ $id ] ) || 'draft' !== $this->plans[ $id ]['status'] ) {
			return false;
		}
		unset( $this->plans[ $id ] );
		return true;
	}

	/* Labels */

	public function next_label() {
		return max( 1, (int) $this->next_label );
	}

	public function reserve_labels( $count ) {
		if ( ! empty( $this->fail['reserve_labels'] ) ) {
			return 0;
		}
		$from             = $this->next_label();
		$this->next_label = $from + max( 0, (int) $count );
		return $from;
	}

	public function release_labels( $from, $count ) {
		if ( (int) $from < 1 || (int) $count < 1 || $this->next_label() !== (int) $from + (int) $count ) {
			return false;
		}
		$this->next_label = (int) $from;
		return true;
	}
}
