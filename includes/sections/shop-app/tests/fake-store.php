<?php
/**
 * An in-memory Store: the same methods and the same guarantees — a token
 * hash finds only an enrolled device, a one-time link is used up by ONE
 * guarded step, an attempt is taken off the budget only while the device
 * is not locked and has tries left, a lockout is written once, a save id
 * is claimed once, and the log can only grow.
 *
 * Seams a test may use that the real store has no need for:
 *   - $queries counts every call a real store would answer with a query;
 *   - $fail['method'] = true makes that method fail (false / null).
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App\Tests;

use FisHotel\Misc\Sections\Shop_App\Lockout;
use FisHotel\Misc\Sections\Shop_App\Store;

// Helper, not a test: the CI runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/** @var array id => row */
	public $devices = array();

	/** @var array id => row */
	public $people = array();

	/** @var array list of rows, oldest first */
	public $log = array();

	/** @var array key => row */
	public $saves = array();

	/** @var array table => next id */
	public $next = array(
		'devices' => 1,
		'people'  => 1,
		'log'     => 1,
		'saves'   => 1,
	);

	/** @var int Calls a real store would answer with a query. */
	public $queries = 0;

	/** @var array method => true */
	public $fail = array();

	private function fails( $method ) {
		++$this->queries;
		return ! empty( $this->fail[ $method ] );
	}

	/* -------------------------------------------------------------- */
	/*  Devices                                                        */
	/* -------------------------------------------------------------- */

	public function device_by_token_hash( $token_hash ) {
		if ( $this->fails( __FUNCTION__ ) || 64 !== strlen( (string) $token_hash ) ) {
			return null;
		}
		foreach ( $this->devices as $row ) {
			if ( Store::STATUS_ACTIVE === $row['status'] && '' !== $row['token_hash'] && hash_equals( $row['token_hash'], (string) $token_hash ) ) {
				return Store::hydrate_device( $row );
			}
		}
		return null;
	}

	public function device( $id ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return null;
		}
		return isset( $this->devices[ (int) $id ] ) ? Store::hydrate_device( $this->devices[ (int) $id ] ) : null;
	}

	public function devices() {
		if ( $this->fails( __FUNCTION__ ) ) {
			return array();
		}
		$rank = array(
			Store::STATUS_ACTIVE  => 0,
			Store::STATUS_PENDING => 1,
			Store::STATUS_REVOKED => 2,
		);
		$rows = array_values( $this->devices );
		usort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				if ( $rank[ $a['status'] ] !== $rank[ $b['status'] ] ) {
					return $rank[ $a['status'] ] <=> $rank[ $b['status'] ];
				}
				return $b['id'] <=> $a['id'];
			}
		);
		return array_map( array( Store::class, 'hydrate_device' ), $rows );
	}

	public function create_device( array $row ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return false;
		}
		$id                   = $this->next['devices']++;
		$this->devices[ $id ] = array(
			'id'                 => $id,
			'name'               => substr( (string) ( $row['name'] ?? '' ), 0, 80 ),
			'status'             => Store::STATUS_ACTIVE === ( $row['status'] ?? '' ) ? Store::STATUS_ACTIVE : Store::STATUS_PENDING,
			'token_hash'         => (string) ( $row['token_hash'] ?? '' ),
			'enrol_hash'         => (string) ( $row['enrol_hash'] ?? '' ),
			'enrol_expires'      => ! empty( $row['enrol_expires'] ) ? (string) $row['enrol_expires'] : null,
			'enrolled_by'        => (int) ( $row['enrolled_by'] ?? 0 ),
			'enrolled_at'        => ! empty( $row['enrolled_at'] ) ? (string) $row['enrolled_at'] : null,
			'created_at'         => gmdate( 'Y-m-d H:i:s' ),
			'last_used_at'       => null,
			'revoked_by'         => 0,
			'revoked_at'         => null,
			'fail_count'         => 0,
			'lock_level'         => 0,
			'locked_until'       => null,
			'session_id'         => '',
			'session_person_id'  => 0,
			'session_started_at' => null,
		);
		return $id;
	}

	public function pending_by_enrol_hash( $enrol_hash, $now ) {
		if ( $this->fails( __FUNCTION__ ) || 64 !== strlen( (string) $enrol_hash ) ) {
			return null;
		}
		foreach ( $this->devices as $row ) {
			if ( Store::STATUS_PENDING === $row['status'] && '' !== $row['enrol_hash'] && hash_equals( $row['enrol_hash'], (string) $enrol_hash ) && Lockout::ts( $row['enrol_expires'] ) > (int) $now ) {
				return Store::hydrate_device( $row );
			}
		}
		return null;
	}

	public function claim_enrolment( $enrol_hash, $token_hash, $now ) {
		if ( $this->fails( __FUNCTION__ ) || 64 !== strlen( (string) $enrol_hash ) || 64 !== strlen( (string) $token_hash ) ) {
			return null;
		}
		foreach ( $this->devices as $id => $row ) {
			if ( Store::STATUS_PENDING === $row['status'] && '' !== $row['enrol_hash'] && hash_equals( $row['enrol_hash'], (string) $enrol_hash ) && Lockout::ts( $row['enrol_expires'] ) > (int) $now ) {
				$this->devices[ $id ]['status']        = Store::STATUS_ACTIVE;
				$this->devices[ $id ]['token_hash']    = (string) $token_hash;
				$this->devices[ $id ]['enrol_hash']    = '';
				$this->devices[ $id ]['enrol_expires'] = null;
				$this->devices[ $id ]['enrolled_at']   = gmdate( 'Y-m-d H:i:s', (int) $now );
				return Store::hydrate_device( $this->devices[ $id ] );
			}
		}
		return null;
	}

	public function revoke_device( $id, $by, $now ) {
		$id = (int) $id;
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->devices[ $id ] ) || Store::STATUS_REVOKED === $this->devices[ $id ]['status'] ) {
			return false;
		}
		$this->devices[ $id ] = array_merge(
			$this->devices[ $id ],
			array(
				'status'             => Store::STATUS_REVOKED,
				'token_hash'         => '',
				'enrol_hash'         => '',
				'enrol_expires'      => null,
				'revoked_by'         => (int) $by,
				'revoked_at'         => gmdate( 'Y-m-d H:i:s', (int) $now ),
				'session_id'         => '',
				'session_person_id'  => 0,
				'session_started_at' => null,
			)
		);
		return true;
	}

	public function purge_pending( $now ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->devices as $id => $row ) {
			if ( Store::STATUS_PENDING === $row['status'] && Lockout::ts( $row['enrol_expires'] ) <= (int) $now ) {
				unset( $this->devices[ $id ] );
				++$n;
			}
		}
		return $n;
	}

	public function update_device( $id, array $fields ) {
		$id      = (int) $id;
		$allowed = array( 'name', 'last_used_at', 'fail_count', 'lock_level', 'locked_until', 'session_id', 'session_person_id', 'session_started_at' );
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->devices[ $id ] ) ) {
			return false;
		}
		$any = false;
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$this->devices[ $id ][ $key ] = $value;
				$any                          = true;
			}
		}
		return $any;
	}

	public function reserve_attempt( $id, $max, $now ) {
		$id = (int) $id;
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->devices[ $id ] ) ) {
			return false;
		}
		$row = $this->devices[ $id ];
		if ( Store::STATUS_ACTIVE !== $row['status'] || (int) $row['fail_count'] >= (int) $max || Lockout::ts( $row['locked_until'] ) > (int) $now ) {
			return false;
		}
		++$this->devices[ $id ]['fail_count'];
		return true;
	}

	public function lock_device( $id, array $fields, $max ) {
		$id = (int) $id;
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->devices[ $id ] ) || (int) $this->devices[ $id ]['fail_count'] < (int) $max ) {
			return false;
		}
		$this->devices[ $id ]['locked_until'] = (string) $fields['locked_until'];
		$this->devices[ $id ]['lock_level']   = (int) $fields['lock_level'];
		$this->devices[ $id ]['fail_count']   = 0;
		return true;
	}

	/* -------------------------------------------------------------- */
	/*  People                                                         */
	/* -------------------------------------------------------------- */

	public function people() {
		if ( $this->fails( __FUNCTION__ ) ) {
			return array();
		}
		$rows = array_values( $this->people );
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return $b['active'] <=> $a['active'];
				}
				return strcmp( $a['name'], $b['name'] ) ?: ( $a['id'] <=> $b['id'] );
			}
		);
		return array_map( array( Store::class, 'hydrate_person' ), $rows );
	}

	public function person( $id ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return null;
		}
		return isset( $this->people[ (int) $id ] ) ? Store::hydrate_person( $this->people[ (int) $id ] ) : null;
	}

	public function insert_person( array $row ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return false;
		}
		$id                  = $this->next['people']++;
		$now                 = gmdate( 'Y-m-d H:i:s' );
		$this->people[ $id ] = array(
			'id'             => $id,
			'name'           => substr( (string) ( $row['name'] ?? '' ), 0, 80 ),
			'pin_hash'       => (string) ( $row['pin_hash'] ?? '' ),
			'pin_salt'       => (string) ( $row['pin_salt'] ?? '' ),
			'pin_length'     => (int) ( $row['pin_length'] ?? 0 ),
			'user_id'        => (int) ( $row['user_id'] ?? 0 ),
			'active'         => empty( $row['active'] ) ? 0 : 1,
			'created_by'     => (int) ( $row['created_by'] ?? 0 ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'last_unlock_at' => null,
		);
		return $id;
	}

	public function update_person( $id, array $fields ) {
		$id      = (int) $id;
		$allowed = array( 'name', 'pin_hash', 'pin_salt', 'pin_length', 'user_id', 'active', 'last_unlock_at' );
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->people[ $id ] ) ) {
			return false;
		}
		$any = false;
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				$this->people[ $id ][ $key ] = $value;
				$any                         = true;
			}
		}
		return $any;
	}

	/* -------------------------------------------------------------- */
	/*  Log — append only                                              */
	/* -------------------------------------------------------------- */

	public function append_log( array $row ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return false;
		}
		$id          = $this->next['log']++;
		$this->log[] = array(
			'id'          => $id,
			'event'       => substr( (string) ( $row['event'] ?? '' ), 0, 30 ),
			'device_id'   => (int) ( $row['device_id'] ?? 0 ),
			'device_name' => substr( (string) ( $row['device_name'] ?? '' ), 0, 80 ),
			'person_id'   => (int) ( $row['person_id'] ?? 0 ),
			'person_name' => substr( (string) ( $row['person_name'] ?? '' ), 0, 80 ),
			'user_id'     => (int) ( $row['user_id'] ?? 0 ),
			'note'        => substr( (string) ( $row['note'] ?? '' ), 0, 191 ),
			'created_at'  => ! empty( $row['created_at'] ) ? (string) $row['created_at'] : gmdate( 'Y-m-d H:i:s' ),
		);
		return $id;
	}

	public function log_rows( $limit = 50, $offset = 0 ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return array();
		}
		return array_slice( array_reverse( $this->log ), max( 0, (int) $offset ), max( 1, (int) $limit ) );
	}

	public function log_count() {
		++$this->queries;
		return count( $this->log );
	}

	/**
	 * The events logged so far, oldest first (tests).
	 *
	 * @return string[]
	 */
	public function events() {
		return array_map(
			static function ( $row ) {
				return $row['event'];
			},
			$this->log
		);
	}

	/* -------------------------------------------------------------- */
	/*  Remembered saves                                               */
	/* -------------------------------------------------------------- */

	public function claim_save( $key, $device_id, $now, $pending_seconds ) {
		if ( $this->fails( __FUNCTION__ ) || 64 !== strlen( (string) $key ) ) {
			return false;
		}
		if ( ! isset( $this->saves[ $key ] ) ) {
			$this->saves[ $key ] = array(
				'id'         => $this->next['saves']++,
				'save_key'   => (string) $key,
				'device_id'  => (int) $device_id,
				'status'     => 0,
				'result'     => null,
				'created_at' => gmdate( 'Y-m-d H:i:s', (int) $now ),
			);
			return 'claimed';
		}
		$row = $this->saves[ $key ];
		if ( 0 === (int) $row['status'] && (int) $now - Lockout::ts( $row['created_at'] ) > (int) $pending_seconds ) {
			$this->saves[ $key ]['created_at'] = gmdate( 'Y-m-d H:i:s', (int) $now );
			return 'claimed';
		}
		return $row;
	}

	public function finish_save( $key, $result ) {
		if ( $this->fails( __FUNCTION__ ) || ! isset( $this->saves[ $key ] ) ) {
			return false;
		}
		$this->saves[ $key ]['status'] = 1;
		$this->saves[ $key ]['result'] = (string) $result;
		return true;
	}

	public function release_save( $key ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return false;
		}
		if ( isset( $this->saves[ $key ] ) && 0 === (int) $this->saves[ $key ]['status'] ) {
			unset( $this->saves[ $key ] );
		}
		return true;
	}

	public function prune_saves( $before ) {
		if ( $this->fails( __FUNCTION__ ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $this->saves as $key => $row ) {
			if ( Lockout::ts( $row['created_at'] ) < (int) $before ) {
				unset( $this->saves[ $key ] );
				++$n;
			}
		}
		return $n;
	}
}
