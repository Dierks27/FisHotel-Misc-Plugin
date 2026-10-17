<?php
/**
 * An in-memory Store: the methods the Front Desk's perform() and the
 * Admin's stay edits call, with no $wpdb. Tanks come from the seed
 * layout; stays, events and tank logs are kept in insertion order with
 * integer ids and run through the SAME pure code as the real Store
 * (Replay::state for the replay, Store::hydrate_* for the row shapes,
 * Store::site_noon_utc for backdating).
 *
 * Two seams a test may use that the real Store has no need for:
 * record_event() honours a 'created_at' in the event (to age an event
 * past the undo window) and append_only() inserts without replaying
 * (another phone's write landing between this phone's read and write).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room\Tests;

use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require. (The
// namespace statement has to come first, hence the order.)
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/**
	 * Hydrated tanks (Store::hydrate_tank shape), id => tank.
	 *
	 * @var array[]
	 */
	public $tanks = array();

	/**
	 * Raw stay rows, id => row.
	 *
	 * @var array[]
	 */
	public $stays = array();

	/**
	 * Raw event rows, id => row.
	 *
	 * @var array[]
	 */
	public $events = array();

	/**
	 * Tank log rows, id => row.
	 *
	 * @var array[]
	 */
	public $logs = array();

	/**
	 * Learned aliases: alias => array( product_id, species ).
	 *
	 * @var array[]
	 */
	public $aliases = array();

	/**
	 * Products the species search knows: id => title.
	 *
	 * @var array<int, string>
	 */
	public $products = array();

	private $next_stay  = 1;
	private $next_event = 1;
	private $next_log   = 1;

	public function __construct( array $products = array() ) {
		$this->products = $products;

		$tank_id = 0;
		foreach ( Layout::seed() as $index => $building ) {
			foreach ( $building['tanks'] as $tank ) {
				$tank_id++;
				$this->tanks[ $tank_id ] = Store::hydrate_tank(
					array_merge(
						$tank,
						array(
							'id'              => $tank_id,
							'building_id'     => $index + 1,
							'building_area'   => $building['area'],
							'building_number' => $building['number'],
							'building_name'   => $building['name'] ?? '',
							'building_status' => $building['status'] ?? 'active',
						)
					)
				);
			}
		}
	}

	/* ---- tanks ---------------------------------------------------- */

	public function tanks( $include_offline = true ) {
		$out = array();
		foreach ( $this->tanks as $tank ) {
			if ( ! $include_offline && 'offline' === $tank['status'] ) {
				continue;
			}
			$out[] = $tank;
		}
		return $out;
	}

	public function tank( $id ) {
		return isset( $this->tanks[ (int) $id ] ) ? $this->tanks[ (int) $id ] : null;
	}

	public function tank_by_number( $number ) {
		foreach ( $this->tanks as $tank ) {
			if ( (int) $tank['number'] === (int) $number ) {
				return $tank;
			}
		}
		return null;
	}

	public function tank_areas() {
		$out = array();
		foreach ( $this->tanks as $id => $tank ) {
			$out[ (int) $id ] = (string) $tank['building']['area'];
		}
		return $out;
	}

	/* ---- stays ---------------------------------------------------- */

	public function create_stay( array $fields ) {
		$id  = $this->next_stay++;
		$now = gmdate( 'Y-m-d H:i:s' );

		$this->stays[ $id ] = array(
			'id'              => $id,
			'product_id'      => ! empty( $fields['product_id'] ) ? (int) $fields['product_id'] : null,
			'species'         => substr( (string) ( $fields['species'] ?? '' ), 0, 120 ),
			'qty_arrived'     => 0,
			'qty_live'        => 0,
			'checkin_date'    => Replay::is_date( $fields['checkin_date'] ?? null ) ? (string) $fields['checkin_date'] : gmdate( 'Y-m-d' ),
			'cleared_date'    => null,
			'sale_ready_date' => null,
			'closed_date'     => null,
			'status'          => 'in_qt',
			'tank_id'         => ! empty( $fields['tank_id'] ) ? (int) $fields['tank_id'] : null,
			'source'          => (string) ( $fields['source'] ?? 'manual' ),
			'order_line_id'   => ! empty( $fields['order_line_id'] ) ? (int) $fields['order_line_id'] : null,
			'supplier'        => '' !== trim( (string) ( $fields['supplier'] ?? '' ) ) ? trim( (string) $fields['supplier'] ) : null,
			'order_ref'       => '' !== trim( (string) ( $fields['order_ref'] ?? '' ) ) ? trim( (string) $fields['order_ref'] ) : null,
			'landed_cost'     => isset( $fields['landed_cost'] ) && is_numeric( $fields['landed_cost'] ) ? round( (float) $fields['landed_cost'], 2 ) : null,
			'notes'           => (string) ( $fields['notes'] ?? '' ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		return $id;
	}

	private function joined_row( array $row ) {
		$tank = $row['tank_id'] ? $this->tank( $row['tank_id'] ) : null;
		return array_merge(
			$row,
			array(
				'tank_number'     => $tank ? $tank['number'] : null,
				'tank_size'       => $tank ? $tank['size_label'] : '',
				'building_id'     => $tank ? $tank['building']['id'] : null,
				'building_number' => $tank ? $tank['building']['number'] : null,
				'building_area'   => $tank ? $tank['building']['area'] : '',
				'building_name'   => $tank ? $tank['building']['name'] : '',
			)
		);
	}

	public function stay( $id, $today = '', array $settings = array() ) {
		if ( ! isset( $this->stays[ (int) $id ] ) ) {
			return null;
		}
		$stay           = Store::hydrate_stay( $this->joined_row( $this->stays[ (int) $id ] ), $today, $settings );
		$stay['events'] = $this->events( (int) $id );
		return $stay;
	}

	public function open_stays( array $filters, $today, array $settings ) {
		$out = array();
		foreach ( $this->stays as $row ) {
			if ( empty( $filters['include_closed'] ) && 'closed' === $row['status'] ) {
				continue;
			}
			if ( ! empty( $filters['tank_id'] ) && (int) $row['tank_id'] !== (int) $filters['tank_id'] ) {
				continue;
			}
			if ( ! empty( $filters['product_id'] ) && (int) $row['product_id'] !== (int) $filters['product_id'] ) {
				continue;
			}
			$out[] = Store::hydrate_stay( $this->joined_row( $row ), $today, $settings );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['checkin_date'], $b['checkin_date'] ) ?: ( $a['id'] <=> $b['id'] );
			}
		);
		return $out;
	}

	public function update_stay_fields( $stay_id, array $fields ) {
		if ( ! isset( $this->stays[ (int) $stay_id ] ) ) {
			return false;
		}
		foreach ( array( 'product_id', 'species', 'supplier', 'order_ref', 'landed_cost', 'order_line_id', 'notes' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$this->stays[ (int) $stay_id ][ $key ] = $fields[ $key ];
			}
		}
		return true;
	}

	/* ---- events --------------------------------------------------- */

	private function event_row( array $row ) {
		$stay = isset( $this->stays[ (int) $row['stay_id'] ] ) ? $this->stays[ (int) $row['stay_id'] ] : array();
		$from = ! empty( $row['from_tank_id'] ) ? $this->tank( $row['from_tank_id'] ) : null;
		$to   = ! empty( $row['to_tank_id'] ) ? $this->tank( $row['to_tank_id'] ) : null;
		return Store::hydrate_event(
			array_merge(
				$row,
				array(
					'stay_species'     => $stay['species'] ?? '',
					'stay_product_id'  => $stay['product_id'] ?? null,
					'from_tank_number' => $from ? $from['number'] : null,
					'to_tank_number'   => $to ? $to['number'] : null,
					'actor_name'       => '',
				)
			)
		);
	}

	public function events( $stay_id ) {
		$out = array();
		foreach ( $this->events as $row ) {
			if ( (int) $row['stay_id'] === (int) $stay_id ) {
				$out[] = $this->event_row( $row );
			}
		}
		return Replay::sort_events( $out );
	}

	public function event( $id ) {
		return isset( $this->events[ (int) $id ] ) ? $this->event_row( $this->events[ (int) $id ] ) : null;
	}

	/**
	 * Insert an event WITHOUT replaying: the other phone's write landing
	 * between this phone's read and write. Returns the event id.
	 */
	public function append_only( $stay_id, array $event ) {
		$type = (string) ( $event['type'] ?? '' );
		if ( ! isset( $this->stays[ (int) $stay_id ] ) || ! in_array( $type, Replay::TYPES, true ) ) {
			return false;
		}
		$id = $this->next_event++;

		$this->events[ $id ] = array(
			'id'           => $id,
			'stay_id'      => (int) $stay_id,
			'type'         => $type,
			'qty'          => isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : null,
			'from_tank_id' => ! empty( $event['from_tank_id'] ) ? (int) $event['from_tank_id'] : null,
			'to_tank_id'   => ! empty( $event['to_tank_id'] ) ? (int) $event['to_tank_id'] : null,
			'note'         => (string) ( $event['note'] ?? '' ),
			'event_at'     => Store::utc_datetime( $event['event_at'] ?? '' ),
			'actor_id'     => (int) ( $event['actor_id'] ?? 0 ),
			'source'       => in_array( (string) ( $event['source'] ?? '' ), Store::SOURCES, true ) ? (string) $event['source'] : 'manual',
			'created_at'   => ! empty( $event['created_at'] ) ? Store::utc_datetime( $event['created_at'] ) : gmdate( 'Y-m-d H:i:s' ),
		);

		return $id;
	}

	public function record_event( $stay_id, array $event, $today, array $settings ) {
		$id = $this->append_only( $stay_id, $event );
		if ( ! $id ) {
			return false;
		}
		$state = $this->replay( (int) $stay_id, $today, $settings );

		return array(
			'event_id'  => $id,
			'state'     => $state,
			'overdrawn' => ! empty( $state['overdrawn'] ),
		);
	}

	public function replay_opts( array $settings ) {
		return array_merge( Settings::replay_opts( $settings ), array( 'tank_areas' => $this->tank_areas() ) );
	}

	public function replay( $stay_id, $today, array $settings ) {
		$stay = $this->stay( (int) $stay_id, $today, $settings );
		if ( ! $stay ) {
			return array();
		}
		$state = Replay::state( $stay, $stay['events'], $today, $this->replay_opts( $settings ) );

		$row                    = &$this->stays[ (int) $stay_id ];
		$row['qty_arrived']     = (int) $state['qty_arrived'];
		$row['qty_live']        = (int) $state['qty_live'];
		$row['checkin_date']    = '' !== $state['checkin_date'] ? $state['checkin_date'] : $row['checkin_date'];
		$row['cleared_date']    = $state['cleared_date'];
		$row['sale_ready_date'] = $state['sale_ready_override'];
		$row['closed_date']     = $state['closed_date'];
		$row['status']          = $state['stored_status'];
		$row['tank_id']         = $state['tank_id'];
		$row['updated_at']      = gmdate( 'Y-m-d H:i:s' );
		unset( $row );

		return $state;
	}

	public function state_before( array $stay, $event_id, $today, array $settings ) {
		$before = array();
		foreach ( Replay::sort_events( (array) ( $stay['events'] ?? array() ) ) as $event ) {
			if ( (int) $event['id'] === (int) $event_id ) {
				break;
			}
			$before[] = $event;
		}
		return Replay::state( $stay, $before, $today, $this->replay_opts( $settings ) );
	}

	public function undo_event( array $stay, array $event, array $base, $today, array $settings ) {
		$events = isset( $stay['events'] ) && is_array( $stay['events'] ) ? $stay['events'] : $this->events( (int) $stay['id'] );
		if ( Replay::is_undone( $events, (int) $event['id'] ) ) {
			return null;
		}
		$before = $this->state_before( array_merge( $stay, array( 'events' => $events ) ), (int) $event['id'], $today, $settings );
		$comp   = Replay::compensating( $event, $before );
		if ( ! $comp ) {
			return null;
		}
		return $this->record_event( (int) $stay['id'], array_merge( $base, $comp, array( 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
	}

	public function split_stay( array $stay, $qty, array $to_tank, array $base, $today, array $settings, $reason = '' ) {
		$qty    = (int) $qty;
		$reason = trim( (string) $reason );
		if ( $qty < 1 || $qty >= (int) $stay['qty_live'] ) {
			return false;
		}
		$sister = $this->create_stay(
			array(
				'product_id'   => $stay['product_id'],
				'species'      => $stay['species'],
				'checkin_date' => $stay['checkin_date'],
				'tank_id'      => $stay['tank_id'],
				'source'       => (string) ( $base['source'] ?? 'manual' ),
				'notes'        => 'split from #' . (int) $stay['id'],
			)
		);
		$ids   = array();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$birth = Store::site_noon_utc( $stay['checkin_date'] );
		if ( strcmp( $birth, $now ) > 0 ) {
			$birth = $now; // A same-day split before noon: the arrival must still sort before the move.
		}

		$written = $this->record_event( (int) $stay['id'], array_merge( $base, array( 'type' => 'move', 'qty' => $qty, 'from_tank_id' => $stay['tank_id'], 'to_tank_id' => (int) $to_tank['id'], 'note' => 'split to #' . $sister . ( '' !== $reason ? ' · ' . $reason : '' ), 'event_at' => $now ) ), $today, $settings );
		$ids[]   = $written['event_id'];
		$written = $this->record_event( $sister, array_merge( $base, array( 'type' => 'arrival', 'qty' => $qty, 'to_tank_id' => $stay['tank_id'], 'note' => 'split from #' . (int) $stay['id'], 'event_at' => $birth ) ), $today, $settings );
		$ids[]   = $written['event_id'];
		if ( ! empty( $stay['cleared_date'] ) ) {
			$this->record_event( $sister, array_merge( $base, array( 'type' => 'clear', 'note' => Replay::NOTE_CLEARED . $stay['cleared_date'], 'event_at' => $now ) ), $today, $settings );
		}
		if ( ! empty( $stay['sale_ready_override'] ) ) {
			$this->record_event( $sister, array_merge( $base, array( 'type' => 'note', 'note' => Replay::NOTE_SALE_READY . $stay['sale_ready_override'], 'event_at' => $birth ) ), $today, $settings );
		}
		$written = $this->record_event( $sister, array_merge( $base, array( 'type' => 'move', 'from_tank_id' => $stay['tank_id'], 'to_tank_id' => (int) $to_tank['id'], 'note' => $reason, 'event_at' => $now ) ), $today, $settings );
		$ids[]   = $written['event_id'];

		return array(
			'stay_id'   => (int) $sister,
			'event_ids' => $ids,
		);
	}

	/* ---- tank logs, species, aliases ------------------------------ */

	public function log_tank( $tank_id, array $log ) {
		if ( ! $this->tank( $tank_id ) ) {
			return false;
		}
		$id = $this->next_log++;

		$this->logs[ $id ] = array(
			'id'        => $id,
			'tank_id'   => (int) $tank_id,
			'kind'      => in_array( (string) ( $log['kind'] ?? '' ), Store::LOG_KINDS, true ) ? (string) $log['kind'] : 'note',
			'value'     => isset( $log['value'] ) && null !== $log['value'] && is_numeric( $log['value'] ) ? round( (float) $log['value'], 3 ) : null,
			'note'      => (string) ( $log['note'] ?? '' ),
			'logged_at' => Store::utc_datetime( $log['logged_at'] ?? '' ),
			'actor_id'  => (int) ( $log['actor_id'] ?? 0 ),
			'source'    => (string) ( $log['source'] ?? 'manual' ),
		);

		return $id;
	}

	public function product_title( $product_id ) {
		return isset( $this->products[ (int) $product_id ] ) ? (string) $this->products[ (int) $product_id ] : '';
	}

	public function add_alias( $alias, $product_id, $species ) {
		$alias = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $alias ) ) );
		if ( '' === $alias ) {
			return false;
		}
		$this->aliases[ $alias ] = array(
			'product_id' => (int) $product_id > 0 ? (int) $product_id : null,
			'species'    => (string) $species,
		);
		return true;
	}

	public function last_event() {
		return $this->events ? $this->event_row( end( $this->events ) ) : null;
	}

	public function event_count() {
		return count( $this->events );
	}
}
