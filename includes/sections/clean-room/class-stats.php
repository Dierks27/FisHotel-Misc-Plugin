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
 * species group (the species row's group; "(no group)" otherwise) — and,
 * with them, how the labels held up (label_counts()): of the lines that
 * received fish, how many were MISLABELED (the label named another fish
 * than the one the line turned out to be — Identify::is_mislabeled()),
 * the rate over those received lines, and, counted apart, how many are
 * unknown fish NOT IDENTIFIED YET. Every
 * input order carries its lines with the survived / DOA figures already
 * resolved (typed for imported history, live from the stay once a line
 * is checked in); the costing runs here.
 *
 * Two more readings of the same orders:
 *
 *   year by year  compare(): the weighted figures of two or more years side
 *                 by side — for the totals, by supplier, by origin and by
 *                 species group — with the change between consecutive
 *                 years: absolute, and in percent for the counts and the
 *                 money that cannot be negative; a rate changes in POINTS.
 *                 A supplier, origin or group with no order in a year has
 *                 no figure there (null — the screens print a dash), and
 *                 no change into or out of that year: never a zero.
 *   per species   by_species(): one row per fish and year — received, DOA,
 *                 survived, landed, and the ESTIMATED revenue (survivors ×
 *                 the FisHotel price typed on the line), profit and margin.
 *                 A fish is its species-list row (a line typed with the
 *                 exact name of a row counts for that row), else its
 *                 lower-cased name; the fish nobody has identified yet are
 *                 one row, so the rows add up to the year's totals.
 *
 * The revenue, "up or down", profit and margin here are estimates, as the
 * spreadsheet's were: what customers really paid is Stats_Sales.
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
	 * The row of the fish nobody has identified yet (by_species()): its key
	 * and its name.
	 *
	 * @var string
	 */
	const UNKNOWN_KEY  = '?';
	const UNKNOWN_NAME = '(not identified yet)';

	/**
	 * The figures compare() puts side by side, in column order, each with
	 * how it changes: a count (absolute + percent), a rate (points), money
	 * that is never negative (absolute + percent), or a signed amount
	 * (absolute only — a percent of a figure that crosses zero says
	 * nothing).
	 *
	 * @var array<string, string>
	 */
	const COMPARE_FIGURES = array(
		'orders'        => 'count',
		'ordered'       => 'count',
		'received'      => 'count',
		'fill_rate'     => 'rate',
		'survived'      => 'count',
		'survival_rate' => 'rate',
		'doa'           => 'count',
		'doa_rate'      => 'rate',
		'total'         => 'money',
		'revenue'       => 'money',
		'up_down'       => 'signed',
	);

	/**
	 * The groupings compare() and build() share.
	 *
	 * @var string[]
	 */
	const SECTIONS = array( 'by_supplier', 'by_origin', 'by_group' );

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
			$row       = self::row( $order, $costing['order'], self::label_counts( $lines ) );
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
					array_merge(
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
						),
						self::label_counts( array( $line ) )
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
	 * How the labels of a set of order lines held up (pure). Only a line
	 * that received fish counts: it is "not identified yet" while it is an
	 * unknown fish (needs_id), else "mislabeled" when its label_name names
	 * another fish than the one it turned out to be.
	 *
	 * @param array[] $lines qty_received, species, needs_id, label_name.
	 * @return array{received_lines: int, mislabeled: int, unidentified: int}
	 */
	public static function label_counts( array $lines ) {
		$out = array(
			'received_lines' => 0,
			'mislabeled'     => 0,
			'unidentified'   => 0,
		);
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) || (int) ( $line['qty_received'] ?? 0 ) < 1 ) {
				continue;
			}
			$out['received_lines']++;
			if ( ! empty( $line['needs_id'] ) ) {
				$out['unidentified']++;
			} elseif ( Identify::is_mislabeled( $line ) ) {
				$out['mislabeled']++;
			}
		}
		return $out;
	}

	/**
	 * One order as a Stats row.
	 *
	 * @param array $order   The order.
	 * @param array $costing Costing::compute()['order'].
	 * @param array $labels  label_counts() of its lines.
	 * @return array
	 */
	public static function row( array $order, array $costing, array $labels = array() ) {
		$received_lines = (int) ( $labels['received_lines'] ?? 0 );
		$mislabeled     = (int) ( $labels['mislabeled'] ?? 0 );
		return array(
			'id'              => (int) ( $order['id'] ?? 0 ),
			'ref'             => (string) ( $order['ref'] ?? '' ),
			'supplier'        => trim( (string) ( $order['supplier'] ?? '' ) ),
			'origin'          => trim( (string) ( $order['origin'] ?? '' ) ),
			'year'            => self::order_year( $order ),
			'order_date'      => isset( $order['order_date'] ) ? $order['order_date'] : null,
			'received_date'   => isset( $order['received_date'] ) ? $order['received_date'] : null,
			'status'          => (string) ( $order['status'] ?? '' ),
			'mode'            => (string) $costing['mode'],
			'lines'           => (int) $costing['lines'],
			'ordered'         => (int) $costing['ordered'],
			'received'        => (int) $costing['received'],
			'survived'        => (int) $costing['survived'],
			'survived_known'  => ! empty( $costing['survived_known'] ),
			'doa'             => (int) $costing['doa'],
			'fill_rate'       => $costing['fill_rate'],
			'survival_rate'   => $costing['survival_rate'],
			'doa_rate'        => $costing['doa_rate'],
			'total'           => (float) $costing['total'],
			'grand_total'     => (float) $costing['grand_total'],
			'revenue'         => (float) $costing['revenue'],
			'up_down'         => (float) $costing['up_down'],
			'ratio'           => $costing['ratio'],
			'received_lines'  => $received_lines,
			'mislabeled'      => $mislabeled,
			'mislabeled_rate' => $received_lines > 0 ? $mislabeled / $received_lines : null,
			'unidentified'    => (int) ( $labels['unidentified'] ?? 0 ),
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
					'lines'          => (int) $row['lines'],
					'orders'         => 1,
					'survival_rate'  => ! empty( $row['survived_known'] ) ? 0.0 : null,
					'received_lines' => (int) ( $row['received_lines'] ?? 0 ),
					'mislabeled'     => (int) ( $row['mislabeled'] ?? 0 ),
					'unidentified'   => (int) ( $row['unidentified'] ?? 0 ),
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
					'lines'          => (int) $row['lines'],
					'orders'         => 1,
					'survival_rate'  => ! empty( $row['survived_known'] ) ? 0.0 : null,
					'received_lines' => (int) ( $row['received_lines'] ?? 0 ),
					'mislabeled'     => (int) ( $row['mislabeled'] ?? 0 ),
					'unidentified'   => (int) ( $row['unidentified'] ?? 0 ),
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
			'received_lines' => 0,
			'mislabeled'     => 0,
			'unidentified'   => 0,
		);
	}

	/**
	 * Add one row's figures to an accumulator.
	 *
	 * @param array $bucket Accumulator (by reference).
	 * @param array $add    ordered, received, doa, survived (null = unknown), total, revenue, lines, orders
	 *                      (+ received_lines, mislabeled, unidentified).
	 */
	private static function add_to_bucket( array &$bucket, array $add ) {
		$bucket['orders']   += (int) $add['orders'];
		$bucket['lines']    += (int) $add['lines'];
		$bucket['ordered']  += (int) $add['ordered'];
		$bucket['received'] += (int) $add['received'];
		$bucket['doa']      += (int) $add['doa'];
		$bucket['total']    += (float) $add['total'];
		$bucket['revenue']  += (float) $add['revenue'];
		foreach ( array( 'received_lines', 'mislabeled', 'unidentified' ) as $key ) {
			$bucket[ $key ] += (int) ( $add[ $key ] ?? 0 );
		}
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
			'name'            => $bucket['name'],
			'orders'          => $bucket['orders'],
			'lines'           => $bucket['lines'],
			'ordered'         => $bucket['ordered'],
			'received'        => $bucket['received'],
			'survived'        => $bucket['survived'],
			'survived_known'  => $bucket['survived_known'],
			'doa'             => $bucket['doa'],
			'fill_rate'       => $bucket['ordered'] > 0 ? $bucket['received'] / $bucket['ordered'] : null,
			'survival_rate'   => $bucket['survived_known'] && $denominator > 0 ? $bucket['survived'] / $denominator : null,
			'doa_rate'        => $bucket['received'] > 0 ? $bucket['doa'] / $bucket['received'] : null,
			'total'           => $bucket['total'],
			'revenue'         => $bucket['revenue'],
			'up_down'         => $bucket['revenue'] - $bucket['total'],
			'ratio'           => abs( $bucket['total'] ) > 1e-9 ? $bucket['revenue'] / $bucket['total'] : null,
			'received_lines'  => $bucket['received_lines'],
			'mislabeled'      => $bucket['mislabeled'],
			'mislabeled_rate' => $bucket['received_lines'] > 0 ? $bucket['mislabeled'] / $bucket['received_lines'] : null,
			'unidentified'    => $bucket['unidentified'],
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

	/* ============================================================== */
	/*  Year by year                                                   */
	/* ============================================================== */

	/**
	 * The date an order files under (the one order_year() reads): its
	 * received date, else its order date, else the day it was created.
	 *
	 * @param array $order received_date, order_date, created_at.
	 * @return string Y-m-d, or '' when it has none.
	 */
	public static function order_date( array $order ) {
		foreach ( array( 'received_date', 'order_date', 'created_at' ) as $key ) {
			$value = isset( $order[ $key ] ) ? (string) $order[ $key ] : '';
			if ( 1 === preg_match( '/^(\d{4})-\d{2}-\d{2}/', $value, $m ) && (int) $m[1] > 0 ) {
				return substr( $value, 0, 10 );
			}
		}
		return '';
	}

	/**
	 * Orders grouped by their year, newest year first (an order without a
	 * date is left out — it is in no year).
	 *
	 * @param array[] $orders Orders.
	 * @return array<int, array[]>
	 */
	public static function orders_by_year( array $orders ) {
		$out = array();
		foreach ( $orders as $order ) {
			$year = is_array( $order ) ? self::order_year( $order ) : null;
			if ( null !== $year ) {
				$out[ $year ][] = $order;
			}
		}
		krsort( $out );
		return $out;
	}

	/**
	 * Two or more years side by side: for the totals and for every
	 * supplier, origin and species group, the COMPARE_FIGURES of each year
	 * and the change between consecutive years.
	 *
	 * A row: name, present (year → whether it had an order that year),
	 * values (figure → year → number, or null when the row is missing
	 * that year or the figure is undefined there) and changes (figure →
	 * one entry per pair of consecutive years: from, to, abs, pct — both
	 * null when either year has no figure; pct null where a percent says
	 * nothing; a rate's abs is in points). The totals row is always
	 * present: a year without orders really has 0 orders.
	 *
	 * @param array<int, array> $by_year Year → build() of that year's orders.
	 * @return array{years: int[], totals: array, by_supplier: array[], by_origin: array[], by_group: array[]}
	 */
	public static function compare( array $by_year ) {
		$years = array();
		foreach ( $by_year as $year => $stats ) {
			if ( (int) $year > 0 && is_array( $stats ) ) {
				$years[] = (int) $year;
			}
		}
		$years = array_values( array_unique( $years ) );
		sort( $years );

		$totals = array();
		foreach ( $years as $year ) {
			$totals[ $year ] = isset( $by_year[ $year ]['totals'] ) && is_array( $by_year[ $year ]['totals'] ) ? $by_year[ $year ]['totals'] : self::weighted( array() );
		}

		$out = array(
			'years'  => $years,
			'totals' => self::compare_row( '', $years, $totals ),
		);

		foreach ( self::SECTIONS as $section ) {
			$names = array();
			$found = array();
			foreach ( $years as $year ) {
				foreach ( (array) ( $by_year[ $year ][ $section ] ?? array() ) as $bucket ) {
					if ( ! is_array( $bucket ) ) {
						continue;
					}
					$slot                    = strtolower( trim( (string) ( $bucket['name'] ?? '' ) ) );
					$names[ $slot ]          = (string) ( $bucket['name'] ?? '' ); // The latest year's spelling.
					$found[ $slot ][ $year ] = $bucket;
				}
			}

			$rows = array();
			foreach ( $found as $slot => $per_year ) {
				$row             = self::compare_row( $names[ $slot ], $years, $per_year );
				$row['received'] = 0;
				foreach ( $per_year as $bucket ) {
					$row['received'] += (int) ( $bucket['received'] ?? 0 );
				}
				$rows[] = $row;
			}
			usort(
				$rows,
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
			$out[ $section ] = $rows;
		}

		return $out;
	}

	/**
	 * One compared row from its per-year buckets.
	 *
	 * @param string            $name     Row name ('' = the totals).
	 * @param int[]             $years    The years, ascending.
	 * @param array<int, array> $per_year Year → finished bucket (missing = no order that year).
	 * @return array{name: string, present: array<int, bool>, values: array, changes: array}
	 */
	private static function compare_row( $name, array $years, array $per_year ) {
		$present = array();
		$values  = array();
		foreach ( $years as $year ) {
			$bucket           = isset( $per_year[ $year ] ) && is_array( $per_year[ $year ] ) ? $per_year[ $year ] : null;
			$present[ $year ] = null !== $bucket;
			foreach ( self::COMPARE_FIGURES as $key => $kind ) {
				$values[ $key ][ $year ] = null === $bucket ? null : self::compare_value( $bucket, $key, $kind );
			}
		}

		$changes = array();
		foreach ( self::COMPARE_FIGURES as $key => $kind ) {
			$changes[ $key ] = array();
			for ( $i = 1, $n = count( $years ); $i < $n; $i++ ) {
				$changes[ $key ][] = array_merge(
					array(
						'from' => $years[ $i - 1 ],
						'to'   => $years[ $i ],
					),
					self::change( $values[ $key ][ $years[ $i - 1 ] ], $values[ $key ][ $years[ $i ] ], $kind )
				);
			}
		}

		return array(
			'name'    => (string) $name,
			'present' => $present,
			'values'  => $values,
			'changes' => $changes,
		);
	}

	/**
	 * One figure of a finished bucket as compare() carries it: an int for
	 * a count, a float for money and rates, null when it is undefined —
	 * the survivors of a bucket where none is known, a rate without a
	 * denominator.
	 *
	 * @param array  $bucket Finished bucket.
	 * @param string $key    Figure.
	 * @param string $kind   count|rate|money|signed.
	 * @return int|float|null
	 */
	private static function compare_value( array $bucket, $key, $kind ) {
		if ( 'survived' === $key && empty( $bucket['survived_known'] ) ) {
			return null;
		}
		if ( ! isset( $bucket[ $key ] ) || null === $bucket[ $key ] || ! is_numeric( $bucket[ $key ] ) ) {
			return null;
		}
		return 'count' === $kind ? (int) $bucket[ $key ] : (float) $bucket[ $key ];
	}

	/**
	 * The change from one year's figure to the next (pure).
	 *
	 *   count   abs = b − a; pct = (b − a) ÷ a, undefined from 0;
	 *   money   the same, on amounts that are never negative;
	 *   rate    abs = (b − a) × 100 — POINTS, never a percent of a percent;
	 *   signed  abs = b − a only.
	 *
	 * @param int|float|null $a    The earlier year's figure.
	 * @param int|float|null $b    The later year's.
	 * @param string         $kind count|rate|money|signed.
	 * @return array{abs: int|float|null, pct: float|null}
	 */
	public static function change( $a, $b, $kind ) {
		$none = array(
			'abs' => null,
			'pct' => null,
		);
		if ( null === $a || null === $b || ! is_numeric( $a ) || ! is_numeric( $b ) ) {
			return $none;
		}

		if ( 'rate' === $kind ) {
			return array(
				'abs' => ( (float) $b - (float) $a ) * 100,
				'pct' => null,
			);
		}
		if ( 'count' === $kind ) {
			return array(
				'abs' => (int) $b - (int) $a,
				'pct' => (int) $a > 0 ? ( (int) $b - (int) $a ) / (int) $a : null,
			);
		}
		if ( 'money' === $kind ) {
			return array(
				'abs' => (float) $b - (float) $a,
				'pct' => (float) $a > 1e-9 ? ( (float) $b - (float) $a ) / (float) $a : null,
			);
		}
		return array(
			'abs' => (float) $b - (float) $a,
			'pct' => null,
		);
	}

	/* ============================================================== */
	/*  Per species, per year                                          */
	/* ============================================================== */

	/**
	 * The species list as the per-fish readers need it: lower-cased name →
	 * id, and id → row.
	 *
	 * @param array[] $species Species rows (id, name, group_name, product_id, my_price).
	 * @return array{names: array<string, int>, rows: array<int, array>}
	 */
	public static function species_index( array $species ) {
		$out = array(
			'names' => array(),
			'rows'  => array(),
		);
		foreach ( $species as $row ) {
			$id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
			if ( $id < 1 ) {
				continue;
			}
			$out['rows'][ $id ] = $row;
			$key                = Species::key( (string) ( $row['name'] ?? '' ) );
			if ( '' !== $key && ! isset( $out['names'][ $key ] ) ) {
				$out['names'][ $key ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Which fish an order line is: "s:<id>" for a species-list row — the
	 * line's own, or the row whose exact lower-cased name the line was
	 * typed with —, else "n:<lower-cased name>"; UNKNOWN_KEY while the
	 * fish still has to be identified.
	 *
	 * @param array              $line  species_id, species, needs_id.
	 * @param array<string, int> $names species_index()['names'].
	 * @return string
	 */
	public static function fish_key( array $line, array $names = array() ) {
		if ( ! empty( $line['needs_id'] ) ) {
			return self::UNKNOWN_KEY;
		}
		$id = (int) ( $line['species_id'] ?? 0 );
		if ( $id > 0 ) {
			return 's:' . $id;
		}
		$name = Species::key( (string) ( $line['species'] ?? '' ) );
		return isset( $names[ $name ] ) ? 's:' . (int) $names[ $name ] : 'n:' . $name;
	}

	/**
	 * One row per fish and year over a set of orders: orders, lines,
	 * ordered, received, DOA, survived (with the rates), the landed total
	 * and per fish, and the ESTIMATES — revenue (survivors × the FisHotel
	 * price typed on the line), profit (revenue − landed) and margin
	 * (profit ÷ landed). unknown_lines counts the lines that received fish
	 * whose survivors are not known yet: their revenue is not in the
	 * estimate. Newest year first, then most fish received, then by name.
	 *
	 * @param array[] $orders          Orders, each with 'lines' (resolved).
	 * @param array[] $species         Species rows — names and groups of the rows a line belongs to.
	 * @param int     $default_per_box Fish per box when nothing says.
	 * @return array[]
	 */
	public static function by_species( array $orders, array $species = array(), $default_per_box = Costing::DEFAULT_PER_BOX ) {
		$index   = self::species_index( $species );
		$buckets = array();
		$meta    = array();
		$seen    = array();

		foreach ( $orders as $n => $order ) {
			$lines     = isset( $order['lines'] ) && is_array( $order['lines'] ) ? array_values( $order['lines'] ) : array();
			$costing   = Costing::compute( $order, $lines, $default_per_box );
			$year      = self::order_year( $order );
			$order_key = (int) ( $order['id'] ?? 0 ) > 0 ? 'id:' . (int) $order['id'] : 'n:' . $n;

			foreach ( $costing['lines'] as $i => $costed ) {
				$line = isset( $lines[ $i ] ) && is_array( $lines[ $i ] ) ? $lines[ $i ] : array();
				$key  = self::fish_key( $line, $index['names'] );
				$slot = ( null === $year ? 0 : $year ) . '|' . $key;

				if ( ! isset( $buckets[ $slot ] ) ) {
					$id               = 0 === strpos( $key, 's:' ) ? (int) substr( $key, 2 ) : 0;
					$row              = $id > 0 && isset( $index['rows'][ $id ] ) ? $index['rows'][ $id ] : null;
					$name             = trim( (string) ( $line['species'] ?? '' ) );
					$buckets[ $slot ] = self::empty_bucket( '' );
					$seen[ $slot ]    = array();
					$meta[ $slot ]    = array(
						'key'           => $key,
						'species_id'    => $id > 0 ? $id : null,
						'name'          => self::UNKNOWN_KEY === $key ? self::UNKNOWN_NAME : ( $row && '' !== trim( (string) ( $row['name'] ?? '' ) ) ? (string) $row['name'] : ( '' !== $name ? $name : '(no name)' ) ),
						'group'         => $row ? trim( (string) ( $row['group_name'] ?? '' ) ) : ( self::UNKNOWN_KEY === $key ? '' : trim( (string) ( $line['group_name'] ?? '' ) ) ),
						'year'          => $year,
						'unknown_lines' => 0,
					);
				}

				$survived = null === $costed['qty_survived'] ? null : (int) $costed['qty_survived'];
				if ( null === $survived && (int) $costed['qty_received'] > 0 ) {
					$meta[ $slot ]['unknown_lines']++;
				}
				$seen[ $slot ][ $order_key ] = true;

				self::add_to_bucket(
					$buckets[ $slot ],
					array(
						'ordered'  => (int) $costed['qty_ordered'],
						'received' => (int) $costed['qty_received'],
						'doa'      => (int) $costed['qty_doa'],
						'survived' => $survived,
						'total'    => (float) $costed['landed_total'],
						'revenue'  => (float) $costed['revenue'],
						'lines'    => 1,
						'orders'   => 0,
					)
				);
			}
		}

		$out = array();
		foreach ( $buckets as $slot => $bucket ) {
			$bucket['orders'] = count( $seen[ $slot ] );
			$row              = self::finish_bucket( $bucket );
			unset( $row['received_lines'], $row['mislabeled'], $row['mislabeled_rate'], $row['unidentified'] );
			$out[] = array_merge(
				$row,
				$meta[ $slot ],
				array(
					'profit'          => $row['up_down'],
					'margin'          => $row['total'] > 1e-9 ? $row['up_down'] / $row['total'] : null,
					'landed_per_fish' => $row['received'] > 0 ? $row['total'] / $row['received'] : null,
				)
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['year'] !== $b['year'] ) {
					return (int) $b['year'] <=> (int) $a['year'];
				}
				if ( $a['received'] !== $b['received'] ) {
					return $b['received'] <=> $a['received'];
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * The sums under a list of by_species() rows — what the year's totals
	 * give when the rows are every fish of that year.
	 *
	 * @param array[] $rows by_species() rows.
	 * @return array{rows: int, lines: int, ordered: int, received: int, doa: int, survived: int, survived_known: bool, total: float, revenue: float, profit: float, margin: float|null, unknown_lines: int}
	 */
	public static function species_totals( array $rows ) {
		$out = array(
			'rows'           => count( $rows ),
			'lines'          => 0,
			'ordered'        => 0,
			'received'       => 0,
			'doa'            => 0,
			'survived'       => 0,
			'survived_known' => false,
			'total'          => 0.0,
			'revenue'        => 0.0,
			'profit'         => 0.0,
			'margin'         => null,
			'unknown_lines'  => 0,
		);
		foreach ( $rows as $row ) {
			foreach ( array( 'lines', 'ordered', 'received', 'doa', 'unknown_lines' ) as $key ) {
				$out[ $key ] += (int) ( $row[ $key ] ?? 0 );
			}
			if ( ! empty( $row['survived_known'] ) ) {
				$out['survived']      += (int) $row['survived'];
				$out['survived_known'] = true;
			}
			$out['total']   += (float) ( $row['total'] ?? 0 );
			$out['revenue'] += (float) ( $row['revenue'] ?? 0 );
		}
		$out['profit'] = $out['revenue'] - $out['total'];
		$out['margin'] = $out['total'] > 1e-9 ? $out['profit'] / $out['total'] : null;
		return $out;
	}

	/* ============================================================== */
	/*  Sorting and searching a report's rows                          */
	/* ============================================================== */

	/**
	 * Rows sorted by one of their columns (stable): numbers as numbers,
	 * text without regard to case; a row without a value in that column
	 * goes last whichever way the sort runs.
	 *
	 * @param array[] $rows Rows.
	 * @param string  $key  Column.
	 * @param string  $dir  asc|desc.
	 * @return array[]
	 */
	public static function sort_rows( array $rows, $key, $dir = 'desc' ) {
		$key  = (string) $key;
		$sign = 'asc' === $dir ? 1 : -1;
		$list = array();
		foreach ( array_values( $rows ) as $position => $row ) {
			$list[] = array( $position, $row );
		}

		usort(
			$list,
			static function ( $a, $b ) use ( $key, $sign ) {
				$x      = isset( $a[1][ $key ] ) ? $a[1][ $key ] : null;
				$y      = isset( $b[1][ $key ] ) ? $b[1][ $key ] : null;
				$x_none = null === $x || '' === $x;
				$y_none = null === $y || '' === $y;
				if ( $x_none || $y_none ) {
					if ( $x_none && $y_none ) {
						return $a[0] <=> $b[0];
					}
					return $x_none ? 1 : -1;
				}
				if ( is_bool( $x ) || is_bool( $y ) ) {
					$order = (int) $x <=> (int) $y;
				} elseif ( is_numeric( $x ) && is_numeric( $y ) ) {
					$order = (float) $x <=> (float) $y;
				} else {
					$order = strcasecmp( (string) $x, (string) $y );
				}
				return 0 !== $order ? $sign * $order : $a[0] <=> $b[0];
			}
		);

		$out = array();
		foreach ( $list as $entry ) {
			$out[] = $entry[1];
		}
		return $out;
	}

	/**
	 * The rows whose text columns contain every word of a search (any
	 * case); an empty search keeps them all.
	 *
	 * @param array[]  $rows Rows.
	 * @param string   $term What was typed.
	 * @param string[] $keys The text columns searched.
	 * @return array[]
	 */
	public static function search_rows( array $rows, $term, array $keys = array( 'name', 'group' ) ) {
		$words = array_filter( explode( ' ', Species::key( (string) $term ) ), 'strlen' );
		if ( ! $words ) {
			return array_values( $rows );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$text = '';
			foreach ( $keys as $key ) {
				$text .= ' ' . strtolower( (string) ( $row[ $key ] ?? '' ) );
			}
			foreach ( $words as $word ) {
				if ( false === strpos( $text, $word ) ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}
