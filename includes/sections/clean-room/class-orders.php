<?php
/**
 * Clean Room orders — the supplier order (what the workbook's "Recieved
 * (N)" sheet held) on top of the Store: the sanitised header and lines
 * from a form, the live figures of a line that was checked in, the
 * costing of an order, and the check-in itself.
 *
 * Nothing here touches $wpdb: every method takes the store it needs (the
 * real Store or the tests' in-memory one), exactly as Front_Desk::perform()
 * does, so the check-in path runs headless.
 *
 * Check-in (check_in()): for every line that received fish and has no
 * stay yet, one stay through the SAME Store::create_stay() +
 * record_event() path the Front Desk uses — an `arrival` of the received
 * count on the order's received date, into the line's tank, else the
 * first empty active Spa tank of the order's building, else any empty
 * Spa tank, else the first Spa tank of the building, else the first Spa
 * tank — with source 'order', the order line, the supplier, the order
 * ref and the landed cost per fish filled; then a `loss` of the DOA count
 * with the note "doa". A line with a stay is skipped, so a second click
 * creates nothing; the order's status becomes "received".
 *
 * Once a line is linked, its figures are live: survived = arrived − every
 * loss (any reason), sold = the shipped events, DOA = the losses noted
 * "doa" (undos honoured). Imported history (lines without stays) keeps
 * the typed survived / DOA numbers.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Orders
 */
class Orders {

	const STATUSES = array( 'draft', 'ordered', 'received', 'closed' );

	const MAX_LINES = 400;
	const MAX_QTY   = 9999;
	const MAX_MONEY = 999999.99;

	/**
	 * The note on the loss event a check-in writes for the DOA count (the
	 * Front Desk's own DOA reason).
	 *
	 * @var string
	 */
	const DOA_NOTE = 'doa';

	/* ============================================================== */
	/*  From a form                                                    */
	/* ============================================================== */

	/**
	 * Clean an order header from a form (or the importer).
	 *
	 * @param array $raw ref, supplier, origin, order_date, received_date, status, boxes, cost_mode,
	 *                   tariffs, domestic_flight, import_fees, credit, markup, worth_threshold,
	 *                   schedule, building_id, notes, id.
	 * @return array
	 */
	public static function normalize_order( array $raw ) {
		$text = static function ( $key, $max ) use ( $raw ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( preg_replace( '/\s+/', ' ', (string) $raw[ $key ] ) ) : '';
			return (string) substr( $value, 0, $max );
		};
		$date = static function ( $key ) use ( $raw ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
			return Replay::is_date( $value ) ? $value : null;
		};
		$money = static function ( $key, $default ) use ( $raw ) {
			$value = Costing::money_in( $raw[ $key ] ?? null, $default );
			return max( 0.0, min( self::MAX_MONEY, $value ) );
		};

		$status = isset( $raw['status'] ) && is_scalar( $raw['status'] ) ? strtolower( trim( (string) $raw['status'] ) ) : 'draft';
		$mode   = isset( $raw['cost_mode'] ) && is_scalar( $raw['cost_mode'] ) ? strtolower( trim( (string) $raw['cost_mode'] ) ) : 'box';
		$boxes  = isset( $raw['boxes'] ) && is_scalar( $raw['boxes'] ) && '' !== trim( (string) $raw['boxes'] ) && is_numeric( $raw['boxes'] ) ? (int) $raw['boxes'] : null;
		$markup = $money( 'markup', Costing::DEFAULT_MARKUP );

		return array(
			'id'              => isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? max( 0, (int) $raw['id'] ) : 0,
			'ref'             => $text( 'ref', 40 ),
			'supplier'        => $text( 'supplier', 80 ),
			'origin'          => $text( 'origin', 80 ),
			'order_date'      => $date( 'order_date' ),
			'received_date'   => $date( 'received_date' ),
			'status'          => in_array( $status, self::STATUSES, true ) ? $status : 'draft',
			'boxes'           => null === $boxes ? null : max( 0, min( 999, $boxes ) ),
			'cost_mode'       => in_array( $mode, Costing::MODES, true ) ? $mode : 'box',
			'tariffs'         => $money( 'tariffs', 0 ),
			'domestic_flight' => $money( 'domestic_flight', 0 ),
			'import_fees'     => $money( 'import_fees', 0 ),
			'credit'          => $money( 'credit', 0 ),
			'markup'          => $markup > 0 ? $markup : Costing::DEFAULT_MARKUP,
			'worth_threshold' => $money( 'worth_threshold', Costing::DEFAULT_THRESHOLD ),
			'schedule'        => $text( 'schedule', 40 ),
			'building_id'     => isset( $raw['building_id'] ) && is_scalar( $raw['building_id'] ) && (int) $raw['building_id'] > 0 ? (int) $raw['building_id'] : null,
			'notes'           => isset( $raw['notes'] ) && is_scalar( $raw['notes'] ) ? (string) substr( trim( (string) $raw['notes'] ), 0, 4000 ) : '',
		);
	}

	/**
	 * Clean one order line from a form. A line without a species name is
	 * for the caller to drop (normalize_lines() does).
	 *
	 * @param array $raw id, species_id, species, sci_name, qty_ordered, qty_received, per_box, bags,
	 *                   unit_price, worth, qty_doa, qty_survived, tank_id, sort, notes.
	 * @return array
	 */
	public static function normalize_line( array $raw ) {
		$text = static function ( $key, $max ) use ( $raw ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( preg_replace( '/\s+/', ' ', (string) $raw[ $key ] ) ) : '';
			return (string) substr( $value, 0, $max );
		};
		$int = static function ( $key, $nullable ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) || '' === trim( (string) $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
				return $nullable ? null : 0;
			}
			return max( 0, min( self::MAX_QTY, (int) $raw[ $key ] ) );
		};
		$money = static function ( $key, $decimals ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				return null;
			}
			$value = str_replace( array( '$', ',', ' ' ), '', (string) $raw[ $key ] );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return null;
			}
			return max( 0.0, min( self::MAX_MONEY, round( (float) $value, $decimals ) ) );
		};

		$per_box = $int( 'per_box', true );
		$bags    = $int( 'bags', true );

		return array(
			'id'           => isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? max( 0, (int) $raw['id'] ) : 0,
			'species_id'   => isset( $raw['species_id'] ) && is_scalar( $raw['species_id'] ) && (int) $raw['species_id'] > 0 ? (int) $raw['species_id'] : null,
			'species'      => $text( 'species', 120 ),
			'sci_name'     => $text( 'sci_name', 120 ),
			'qty_ordered'  => $int( 'qty_ordered', false ),
			'qty_received' => $int( 'qty_received', true ),
			'per_box'      => null === $per_box || $per_box < 1 ? null : min( Species::MAX_PER_BOX, $per_box ),
			'bags'         => null === $bags || $bags < 1 ? null : $bags,
			'unit_price'   => $money( 'unit_price', 4 ),
			'worth'        => $money( 'worth', 2 ),
			'qty_doa'      => $int( 'qty_doa', false ),
			'qty_survived' => $int( 'qty_survived', true ),
			'tank_id'      => isset( $raw['tank_id'] ) && is_scalar( $raw['tank_id'] ) && (int) $raw['tank_id'] > 0 ? (int) $raw['tank_id'] : null,
			'sort'         => isset( $raw['sort'] ) && is_scalar( $raw['sort'] ) && is_numeric( $raw['sort'] ) ? (int) $raw['sort'] : 0,
			'notes'        => $text( 'notes', 500 ),
		);
	}

	/**
	 * Clean a form's line rows: empty species dropped, sort = position,
	 * at most MAX_LINES.
	 *
	 * @param mixed $raw lines[n][…].
	 * @return array[]
	 */
	public static function normalize_lines( $raw ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$line = self::normalize_line( $row );
			if ( '' === $line['species'] ) {
				continue;
			}
			$line['sort'] = count( $out );
			$out[]        = $line;
			if ( count( $out ) >= self::MAX_LINES ) {
				break;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  Figures                                                        */
	/* ============================================================== */

	/**
	 * The live figures of one stay from its events: arrived, lost (every
	 * reason), sold, doa (the losses noted "doa", their undos honoured),
	 * live, survived = arrived − lost.
	 *
	 * @param array[] $events The stay's events (any order).
	 * @return array{arrived: int, lost: int, sold: int, doa: int, live: int, survived: int}
	 */
	public static function figures_from_events( array $events ) {
		$state = Replay::state( array(), $events, '', array() );

		$by_id = array();
		foreach ( $events as $event ) {
			$by_id[ (int) ( $event['id'] ?? 0 ) ] = $event;
		}

		$doa = 0;
		foreach ( $events as $event ) {
			if ( 'loss' !== (string) ( $event['type'] ?? '' ) ) {
				continue;
			}
			$qty  = isset( $event['qty'] ) && null !== $event['qty'] ? (int) $event['qty'] : 1;
			$note = (string) ( $event['note'] ?? '' );
			if ( self::DOA_NOTE === $note ) {
				$doa += $qty;
				continue;
			}
			if ( 0 === strpos( $note, Replay::NOTE_UNDO ) ) {
				$target = (int) substr( $note, strlen( Replay::NOTE_UNDO ) );
				if ( $target > 0 && isset( $by_id[ $target ] ) && self::DOA_NOTE === (string) ( $by_id[ $target ]['note'] ?? '' ) ) {
					$doa += $qty; // The undo of a DOA loss carries a negative quantity.
				}
			}
		}

		return array(
			'arrived'  => (int) $state['qty_arrived'],
			'lost'     => (int) $state['qty_lost'],
			'sold'     => (int) $state['qty_sold'],
			'doa'      => max( 0, $doa ),
			'live'     => (int) $state['qty_live'],
			'survived' => max( 0, (int) $state['qty_arrived'] - (int) $state['qty_lost'] ),
		);
	}

	/**
	 * An order with its lines' figures resolved and costed: a linked line
	 * reads survived / DOA / live / sold from its stay, an unlinked one
	 * keeps what was typed. Adds 'costing' (Costing::compute()) and, on
	 * every line, 'live', 'sold', 'survived', 'doa', 'linked' and the
	 * costed columns.
	 *
	 * @param array $order           The order with 'lines'.
	 * @param array $figures         stay_id → figures_from_events() (for the linked lines).
	 * @param int   $default_per_box Fish per box when nothing says.
	 * @return array
	 */
	public static function resolve( array $order, array $figures, $default_per_box = Costing::DEFAULT_PER_BOX ) {
		$lines = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			$stay_id = isset( $line['stay_id'] ) && (int) $line['stay_id'] > 0 ? (int) $line['stay_id'] : null;
			$linked  = null !== $stay_id && isset( $figures[ $stay_id ] );
			if ( $linked ) {
				$f                    = $figures[ $stay_id ];
				$line['live']         = (int) $f['live'];
				$line['sold']         = (int) $f['sold'];
				$line['survived']     = (int) $f['survived'];
				$line['doa']          = (int) $f['doa'];
				$line['qty_survived'] = (int) $f['survived'];
				$line['qty_doa']      = (int) $f['doa'];
			} else {
				$line['live']     = null;
				$line['sold']     = null;
				$line['survived'] = isset( $line['qty_survived'] ) && null !== $line['qty_survived'] ? (int) $line['qty_survived'] : null;
				$line['doa']      = (int) ( $line['qty_doa'] ?? 0 );
			}
			$line['linked'] = $linked;
			$lines[]        = $line;
		}

		$costing = Costing::compute( $order, $lines, $default_per_box );
		foreach ( $costing['lines'] as $index => $costed ) {
			foreach ( array( 'fraction', 'units', 'landed_total', 'landed_per_fish', 'suggested_price', 'profit', 'margin', 'worth_it', 'revenue', 'doa_cost', 'doa_cost_per_fish' ) as $key ) {
				$lines[ $index ][ $key ] = $costed[ $key ];
			}
			$lines[ $index ]['per_box_used'] = $costed['per_box'];
		}

		$order['lines']   = $lines;
		$order['costing'] = $costing['order'];

		return $order;
	}

	/**
	 * The order's figures the lists show, from a resolved order.
	 *
	 * @param array $order Resolved order (resolve()).
	 * @return array
	 */
	public static function summary( array $order ) {
		$c = (array) ( $order['costing'] ?? array() );
		return array(
			'lines'         => (int) ( $c['lines'] ?? 0 ),
			'ordered'       => (int) ( $c['ordered'] ?? 0 ),
			'received'      => (int) ( $c['received'] ?? 0 ),
			'survived'      => (int) ( $c['survived'] ?? 0 ),
			'doa'           => (int) ( $c['doa'] ?? 0 ),
			'fill_rate'     => $c['fill_rate'] ?? null,
			'doa_rate'      => $c['doa_rate'] ?? null,
			'survival_rate' => $c['survival_rate'] ?? null,
			'total'         => (float) ( $c['total'] ?? 0 ),
			'grand_total'   => (float) ( $c['grand_total'] ?? 0 ),
			'revenue'       => (float) ( $c['revenue'] ?? 0 ),
			'up_down'       => (float) ( $c['up_down'] ?? 0 ),
		);
	}

	/* ============================================================== */
	/*  Check-in                                                       */
	/* ============================================================== */

	/**
	 * The Spa tank a line checks in to (pure): the line's own tank when it
	 * is an active Spa tank, else the first empty active Spa tank of the
	 * building, else any empty one, else the first active Spa tank of the
	 * building, else the first active Spa tank at all; null when the Spa
	 * has no active tank.
	 *
	 * @param array[]  $spa_tanks    Active Spa tanks in rack order (id, number, building_id).
	 * @param int[]    $occupied     Tank ids with fish in them.
	 * @param int|null $building_id  The order's building.
	 * @param int|null $line_tank_id The line's tank.
	 * @return array|null
	 */
	public static function pick_tank( array $spa_tanks, array $occupied, $building_id, $line_tank_id ) {
		if ( ! $spa_tanks ) {
			return null;
		}
		$occupied = array_map( 'intval', $occupied );

		if ( (int) $line_tank_id > 0 ) {
			foreach ( $spa_tanks as $tank ) {
				if ( (int) $tank['id'] === (int) $line_tank_id ) {
					return $tank;
				}
			}
		}

		$in_building = array();
		foreach ( $spa_tanks as $tank ) {
			if ( (int) $building_id > 0 && (int) ( $tank['building_id'] ?? 0 ) === (int) $building_id ) {
				$in_building[] = $tank;
			}
		}

		foreach ( array( $in_building, $spa_tanks ) as $list ) {
			foreach ( $list as $tank ) {
				if ( ! in_array( (int) $tank['id'], $occupied, true ) ) {
					return $tank;
				}
			}
		}

		return $in_building ? $in_building[0] : $spa_tanks[0];
	}

	/**
	 * The active Spa tanks of a store, in rack order.
	 *
	 * @param object $store Store (or stand-in) with tanks().
	 * @return array[]
	 */
	public static function spa_tanks( $store ) {
		$out = array();
		foreach ( $store->tanks( false ) as $tank ) {
			if ( 'spa' !== (string) ( $tank['building']['area'] ?? '' ) || 'offline' === (string) ( $tank['building']['status'] ?? '' ) ) {
				continue;
			}
			$out[] = $tank;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $a['number'] <=> (int) $b['number'];
			}
		);
		return $out;
	}

	/**
	 * Check an order's received fish in: one stay per line that received
	 * fish and has no stay yet (idempotent). Needs the store's tanks(),
	 * open_stays(), create_stay(), record_event(), set_line_stay(),
	 * update_order_fields() and stay_figures().
	 *
	 * @param object $store           Store or stand-in.
	 * @param array  $order           The order with its lines (Store::order()).
	 * @param string $today           Site date.
	 * @param array  $settings        Settings.
	 * @param array  $base            actor_id, source ('import' for the workbook import, 'order' otherwise).
	 * @param int    $default_per_box Fish per box when nothing says.
	 * @return array{created: array[], skipped: int, errors: string[], date: string}
	 */
	public static function check_in( $store, array $order, $today, array $settings, array $base, $default_per_box = Costing::DEFAULT_PER_BOX ) {
		$result = array(
			'created' => array(),
			'skipped' => 0,
			'errors'  => array(),
			'date'    => '',
		);

		$stay_ids = array();
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( ! empty( $line['stay_id'] ) ) {
				$stay_ids[] = (int) $line['stay_id'];
			}
		}
		$order = self::resolve( $order, $stay_ids ? $store->stay_figures( $stay_ids ) : array(), $default_per_box );

		$date = Replay::is_date( $order['received_date'] ?? null ) ? (string) $order['received_date'] : (string) $today;
		if ( strcmp( $date, (string) $today ) > 0 ) {
			$date = (string) $today; // A check-in cannot be in the future.
		}
		$result['date'] = $date;

		$spa      = self::spa_tanks( $store );
		$occupied = array();
		foreach ( $store->open_stays( array(), $today, $settings ) as $stay ) {
			if ( ! empty( $stay['tank_id'] ) ) {
				$occupied[] = (int) $stay['tank_id'];
			}
		}
		// The Arrivals button writes source 'order'; the workbook import 'import'.
		$source = 'import' === (string) ( $base['source'] ?? '' ) ? 'import' : 'order';
		$base   = array_merge( $base, array( 'source' => $source ) );

		foreach ( $order['lines'] as $line ) {
			if ( ! empty( $line['stay_id'] ) ) {
				$result['skipped']++;
				continue;
			}
			$qty = (int) ( $line['qty_received'] ?? 0 );
			if ( $qty < 1 ) {
				$result['skipped']++;
				continue;
			}

			$tank = self::pick_tank( $spa, $occupied, $order['building_id'] ?? null, $line['tank_id'] ?? null );
			if ( ! $tank ) {
				/* translators: %s: species */
				$result['errors'][] = sprintf( __( '%s: no active Spa tank to check in to.', 'fishotel-misc-plugin' ), (string) $line['species'] );
				continue;
			}

			$stay_id = $store->create_stay(
				array(
					'product_id'    => ! empty( $line['species_product_id'] ) ? (int) $line['species_product_id'] : null,
					'species'       => (string) $line['species'],
					'checkin_date'  => $date,
					'tank_id'       => (int) $tank['id'],
					'source'        => $source,
					'order_line_id' => (int) $line['id'],
					'supplier'      => (string) ( $order['supplier'] ?? '' ),
					'order_ref'     => (string) ( $order['ref'] ?? '' ),
					'landed_cost'   => isset( $line['landed_per_fish'] ) && null !== $line['landed_per_fish'] ? Costing::cents( $line['landed_per_fish'] ) : null,
				)
			);
			if ( ! $stay_id ) {
				/* translators: %s: species */
				$result['errors'][] = sprintf( __( '%s: the stay could not be created.', 'fishotel-misc-plugin' ), (string) $line['species'] );
				continue;
			}

			$event_at = $date === (string) $today ? gmdate( 'Y-m-d H:i:s' ) : Store::site_noon_utc( $date );
			$written  = $store->record_event(
				$stay_id,
				array_merge(
					$base,
					array(
						'type'       => 'arrival',
						'qty'        => $qty,
						'to_tank_id' => (int) $tank['id'],
						'event_at'   => $event_at,
					)
				),
				$today,
				$settings
			);
			if ( ! $written ) {
				/* translators: %s: species */
				$result['errors'][] = sprintf( __( '%s: the arrival could not be recorded.', 'fishotel-misc-plugin' ), (string) $line['species'] );
				continue;
			}

			$doa = (int) ( $line['qty_doa'] ?? 0 );
			if ( $doa > 0 ) {
				$store->record_event(
					$stay_id,
					array_merge(
						$base,
						array(
							'type'     => 'loss',
							'qty'      => min( $doa, $qty ),
							'note'     => self::DOA_NOTE,
							'event_at' => $event_at,
						)
					),
					$today,
					$settings
				);
			}

			$store->set_line_stay( (int) $line['id'], $stay_id, (int) $tank['id'] );
			$occupied[]          = (int) $tank['id'];
			$result['created'][] = array(
				'line_id'  => (int) $line['id'],
				'stay_id'  => (int) $stay_id,
				'species'  => (string) $line['species'],
				'qty'      => $qty,
				'doa'      => $doa,
				'tank'     => (int) $tank['number'],
				'tank_id'  => (int) $tank['id'],
				'event_id' => (int) $written['event_id'],
			);
		}

		if ( $result['created'] ) {
			$fields = array( 'status' => 'received' );
			if ( ! Replay::is_date( $order['received_date'] ?? null ) ) {
				$fields['received_date'] = $date;
			}
			$store->update_order_fields( (int) $order['id'], $fields );
		}

		return $result;
	}

	/**
	 * The check-in result in one line.
	 *
	 * @param array $result check_in().
	 * @return string
	 */
	public static function check_in_message( array $result ) {
		$n     = count( (array) $result['created'] );
		$tanks = array();
		foreach ( (array) $result['created'] as $row ) {
			$tanks[ (int) $row['tank'] ] = true;
		}
		$tanks = array_keys( $tanks );
		sort( $tanks );

		if ( 0 === $n ) {
			$text = __( 'Nothing to check in: every line with fish already has a stay.', 'fishotel-misc-plugin' );
		} else {
			/* translators: 1: stays created, 2: tank numbers */
			$text = sprintf( __( '%1$d stays created in Tank %2$s.', 'fishotel-misc-plugin' ), $n, implode( ', ', $tanks ) );
		}
		if ( (int) $result['skipped'] > 0 ) {
			/* translators: %d: lines skipped */
			$text .= ' ' . sprintf( __( '%d lines skipped (already checked in or nothing received).', 'fishotel-misc-plugin' ), (int) $result['skipped'] );
		}
		if ( ! empty( $result['errors'] ) ) {
			$text .= ' ' . implode( ' ', array_map( 'strval', (array) $result['errors'] ) );
		}
		return $text;
	}

	/* ============================================================== */
	/*  References                                                     */
	/* ============================================================== */

	/**
	 * The next free "YYYY-N" reference for a year (N = the highest used
	 * + 1, from 1).
	 *
	 * @param string[] $refs Existing refs.
	 * @param int      $year The year.
	 * @return string
	 */
	public static function next_ref( array $refs, $year ) {
		$year = (int) $year;
		$max  = 0;
		foreach ( $refs as $ref ) {
			if ( 1 === preg_match( '/^' . $year . '-(\d+)$/', trim( (string) $ref ), $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}
		return $year . '-' . ( $max + 1 );
	}
}
