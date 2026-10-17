<?php
/**
 * Clean Room stats — PURE: the yearly figures over the supplier orders,
 * both ways the question can be answered.
 *
 *   weighted      Σ ÷ Σ over every order: fill = Σ received ÷ Σ ordered,
 *                 survival = Σ survived ÷ (Σ received − Σ DOA) over the
 *                 orders whose survivors are known, DOA = Σ DOA ÷ Σ received;
 *   sheet-style   what the workbook's Controller & Stats tab did: the
 *                 UNWEIGHTED average of each batch's fill / survival / DOA
 *                 rate over the batches that received anything (each rate
 *                 over the batches where it is defined — a batch whose
 *                 survivors are not typed yet is not in the survival
 *                 average, as it was "not in the formulas yet"), and
 *                 "total fish through QT" = Σ survived.
 *
 * Plus the same weighted figures grouped by supplier, by origin and by
 * species group (the species row's group; "(no group)" otherwise). Every
 * input order carries its lines with the survived / DOA figures already
 * resolved (typed for imported history, live from the stay once a line
 * is checked in); the costing runs here.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats
 */
class Stats {

	/**
	 * The species-group label for lines whose species has no group.
	 *
	 * @var string
	 */
	const NO_GROUP = '(no group)';

	/**
	 * The year an order belongs to: its received date, else its order
	 * date, else when it was created.
	 *
	 * @param array $order received_date, order_date, created_at.
	 * @return int|null
	 */
	public static function order_year( array $order ) {
		foreach ( array( 'received_date', 'order_date', 'created_at' ) as $key ) {
			$value = isset( $order[ $key ] ) ? (string) $order[ $key ] : '';
			if ( 1 === preg_match( '/^(\d{4})-\d{2}-\d{2}/', $value, $m ) && (int) $m[1] > 0 ) {
				return (int) $m[1];
			}
		}
		return null;
	}

	/**
	 * Everything the Stats tab shows for a set of orders.
	 *
	 * @param array[] $orders          Orders, each with 'lines' (resolved).
	 * @param int     $default_per_box Fish per box when nothing says.
	 * @return array{orders: array[], years: int[], totals: array, sheet: array, by_supplier: array[], by_origin: array[], by_group: array[]}
	 */
	public static function build( array $orders, $default_per_box = Costing::DEFAULT_PER_BOX ) {
		$rows         = array();
		$years        = array();
		$groups       = array();
		$group_orders = array(); // group → the orders with a line in it (an order counts once per group).

		foreach ( $orders as $n => $order ) {
			$lines     = isset( $order['lines'] ) && is_array( $order['lines'] ) ? $order['lines'] : array();
			$costing   = Costing::compute( $order, $lines, $default_per_box );
			$row       = self::row( $order, $costing['order'] );
			$rows[]    = $row;
			$order_key = $row['id'] > 0 ? 'id:' . $row['id'] : 'n:' . $n;
			if ( null !== $row['year'] ) {
				$years[ $row['year'] ] = true;
			}

			foreach ( $costing['lines'] as $index => $costed ) {
				$line  = isset( $lines[ $index ] ) ? $lines[ $index ] : array();
				$group = trim( (string) ( $line['group_name'] ?? '' ) );
				$group = '' !== $group ? $group : self::NO_GROUP;
				if ( ! isset( $groups[ $group ] ) ) {
					$groups[ $group ]       = self::empty_bucket( $group );
					$group_orders[ $group ] = array();
				}
				$group_orders[ $group ][ $order_key ] = true;
				$survived                             = null === $costed['qty_survived'] ? null : (int) $costed['qty_survived'];
				self::add_to_bucket(
					$groups[ $group ],
					array(
						'ordered'       => (int) $costed['qty_ordered'],
						'received'      => (int) $costed['qty_received'],
						'doa'           => (int) $costed['qty_doa'],
						'survived'      => $survived,
						'total'         => (float) $costed['landed_total'],
						'revenue'       => (float) $costed['revenue'],
						'lines'         => 1,
						'orders'        => 0,
						'survival_rate' => null === $survived ? null : 0.0,
					)
				);
			}
		}

		foreach ( $group_orders as $group => $seen ) {
			$groups[ $group ]['orders'] = count( $seen );
		}

		$years = array_keys( $years );
		rsort( $years );

		return array(
			'orders'      => $rows,
			'years'       => $years,
			'totals'      => self::weighted( $rows ),
			'sheet'       => self::sheet_style( $rows ),
			'by_supplier' => self::group_rows( $rows, 'supplier' ),
			'by_origin'   => self::group_rows( $rows, 'origin' ),
			'by_group'    => self::finish_buckets( $groups ),
		);
	}

	/**
	 * One order as a Stats row.
	 *
	 * @param array $order   The order.
	 * @param array $costing Costing::compute()['order'].
	 * @return array
	 */
	public static function row( array $order, array $costing ) {
		return array(
			'id'             => (int) ( $order['id'] ?? 0 ),
			'ref'            => (string) ( $order['ref'] ?? '' ),
			'supplier'       => trim( (string) ( $order['supplier'] ?? '' ) ),
			'origin'         => trim( (string) ( $order['origin'] ?? '' ) ),
			'year'           => self::order_year( $order ),
			'order_date'     => isset( $order['order_date'] ) ? $order['order_date'] : null,
			'received_date'  => isset( $order['received_date'] ) ? $order['received_date'] : null,
			'status'         => (string) ( $order['status'] ?? '' ),
			'mode'           => (string) $costing['mode'],
			'lines'          => (int) $costing['lines'],
			'ordered'        => (int) $costing['ordered'],
			'received'       => (int) $costing['received'],
			'survived'       => (int) $costing['survived'],
			'survived_known' => ! empty( $costing['survived_known'] ),
			'doa'            => (int) $costing['doa'],
			'fill_rate'      => $costing['fill_rate'],
			'survival_rate'  => $costing['survival_rate'],
			'doa_rate'       => $costing['doa_rate'],
			'total'          => (float) $costing['total'],
			'grand_total'    => (float) $costing['grand_total'],
			'revenue'        => (float) $costing['revenue'],
			'up_down'        => (float) $costing['up_down'],
			'ratio'          => $costing['ratio'],
		);
	}

	/**
	 * The weighted (Σ ÷ Σ) totals of a set of rows. Survival counts only
	 * the orders whose survivors are known, in both the numerator and the
	 * denominator.
	 *
	 * @param array[] $rows Stats rows.
	 * @return array
	 */
	public static function weighted( array $rows ) {
		$bucket = self::empty_bucket( '' );
		foreach ( $rows as $row ) {
			self::add_to_bucket(
				$bucket,
				array(
					'ordered'       => (int) $row['ordered'],
					'received'      => (int) $row['received'],
					'doa'           => (int) $row['doa'],
					'survived'      => ! empty( $row['survived_known'] ) ? (int) $row['survived'] : null,
					'total'         => (float) $row['total'],
					'revenue'       => (float) $row['revenue'],
					'lines'         => (int) $row['lines'],
					'orders'        => 1,
					'survival_rate' => ! empty( $row['survived_known'] ) ? 0.0 : null,
				)
			);
		}
		return self::finish_bucket( $bucket );
	}

	/**
	 * The workbook's way: the unweighted average of each batch's rates
	 * over the batches that received anything, each average over the
	 * batches where that rate is defined; total fish through QT = Σ
	 * survived over every batch.
	 *
	 * @param array[] $rows Stats rows.
	 * @return array{batches: int, fill_rate: float|null, fill_n: int, survival_rate: float|null, survival_n: int, doa_rate: float|null, doa_n: int, through_qt: int}
	 */
	public static function sheet_style( array $rows ) {
		$out = array(
			'batches'       => 0,
			'fill_rate'     => null,
			'fill_n'        => 0,
			'survival_rate' => null,
			'survival_n'    => 0,
			'doa_rate'      => null,
			'doa_n'         => 0,
			'through_qt'    => 0,
		);
		$sums = array(
			'fill_rate'     => 0.0,
			'survival_rate' => 0.0,
			'doa_rate'      => 0.0,
		);

		foreach ( $rows as $row ) {
			$out['through_qt'] += (int) $row['survived'];
			if ( (int) $row['received'] <= 0 ) {
				continue;
			}
			$out['batches']++;
			foreach ( array( 'fill_rate' => 'fill_n', 'survival_rate' => 'survival_n', 'doa_rate' => 'doa_n' ) as $rate => $n ) {
				if ( isset( $row[ $rate ] ) && null !== $row[ $rate ] ) {
					$sums[ $rate ] += (float) $row[ $rate ];
					$out[ $n ]++;
				}
			}
		}

		foreach ( array( 'fill_rate' => 'fill_n', 'survival_rate' => 'survival_n', 'doa_rate' => 'doa_n' ) as $rate => $n ) {
			$out[ $rate ] = $out[ $n ] > 0 ? $sums[ $rate ] / $out[ $n ] : null;
		}

		return $out;
	}

	/**
	 * Rows grouped by one of their text keys (supplier / origin), weighted
	 * inside each group, most fish received first. An empty key reads
	 * "(none)".
	 *
	 * @param array[] $rows Stats rows.
	 * @param string  $key  supplier|origin.
	 * @return array[]
	 */
	public static function group_rows( array $rows, $key ) {
		$buckets = array();
		foreach ( $rows as $row ) {
			$name = trim( (string) ( $row[ $key ] ?? '' ) );
			$name = '' !== $name ? $name : '(none)';
			$slot = strtolower( $name );
			if ( ! isset( $buckets[ $slot ] ) ) {
				$buckets[ $slot ] = self::empty_bucket( $name );
			}
			self::add_to_bucket(
				$buckets[ $slot ],
				array(
					'ordered'       => (int) $row['ordered'],
					'received'      => (int) $row['received'],
					'doa'           => (int) $row['doa'],
					'survived'      => ! empty( $row['survived_known'] ) ? (int) $row['survived'] : null,
					'total'         => (float) $row['total'],
					'revenue'       => (float) $row['revenue'],
					'lines'         => (int) $row['lines'],
					'orders'        => 1,
					'survival_rate' => ! empty( $row['survived_known'] ) ? 0.0 : null,
				)
			);
		}
		return self::finish_buckets( $buckets );
	}

	/**
	 * An empty accumulator.
	 *
	 * @param string $name Group name.
	 * @return array
	 */
	private static function empty_bucket( $name ) {
		return array(
			'name'           => (string) $name,
			'orders'         => 0,
			'lines'          => 0,
			'ordered'        => 0,
			'received'       => 0,
			'survived'       => 0,
			'doa'            => 0,
			'total'          => 0.0,
			'revenue'        => 0.0,
			'surv_received'  => 0,
			'surv_doa'       => 0,
			'survived_known' => false,
		);
	}

	/**
	 * Add one row's figures to an accumulator.
	 *
	 * @param array $bucket Accumulator (by reference).
	 * @param array $add    ordered, received, doa, survived (null = unknown), total, revenue, lines, orders.
	 */
	private static function add_to_bucket( array &$bucket, array $add ) {
		$bucket['orders']   += (int) $add['orders'];
		$bucket['lines']    += (int) $add['lines'];
		$bucket['ordered']  += (int) $add['ordered'];
		$bucket['received'] += (int) $add['received'];
		$bucket['doa']      += (int) $add['doa'];
		$bucket['total']    += (float) $add['total'];
		$bucket['revenue']  += (float) $add['revenue'];
		if ( null !== $add['survived'] ) {
			$bucket['survived']      += (int) $add['survived'];
			$bucket['surv_received'] += (int) $add['received'];
			$bucket['surv_doa']      += (int) $add['doa'];
			$bucket['survived_known'] = true;
		}
	}

	/**
	 * The rates of an accumulator.
	 *
	 * @param array $bucket Accumulator.
	 * @return array
	 */
	private static function finish_bucket( array $bucket ) {
		$denominator = $bucket['surv_received'] - $bucket['surv_doa'];
		return array(
			'name'           => $bucket['name'],
			'orders'         => $bucket['orders'],
			'lines'          => $bucket['lines'],
			'ordered'        => $bucket['ordered'],
			'received'       => $bucket['received'],
			'survived'       => $bucket['survived'],
			'survived_known' => $bucket['survived_known'],
			'doa'            => $bucket['doa'],
			'fill_rate'      => $bucket['ordered'] > 0 ? $bucket['received'] / $bucket['ordered'] : null,
			'survival_rate'  => $bucket['survived_known'] && $denominator > 0 ? $bucket['survived'] / $denominator : null,
			'doa_rate'       => $bucket['received'] > 0 ? $bucket['doa'] / $bucket['received'] : null,
			'total'          => $bucket['total'],
			'revenue'        => $bucket['revenue'],
			'up_down'        => $bucket['revenue'] - $bucket['total'],
			'ratio'          => abs( $bucket['total'] ) > 1e-9 ? $bucket['revenue'] / $bucket['total'] : null,
		);
	}

	/**
	 * Finish every accumulator and order them: most fish received first,
	 * then by name, "(no group)" / "(none)" last.
	 *
	 * @param array[] $buckets Accumulators.
	 * @return array[]
	 */
	private static function finish_buckets( array $buckets ) {
		$out = array();
		foreach ( $buckets as $bucket ) {
			$out[] = self::finish_bucket( $bucket );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$a_none = '(' === substr( $a['name'], 0, 1 );
				$b_none = '(' === substr( $b['name'], 0, 1 );
				if ( $a_none !== $b_none ) {
					return $a_none ? 1 : -1;
				}
				if ( $a['received'] !== $b['received'] ) {
					return $b['received'] <=> $a['received'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}
}
