<?php
/**
 * Clean Room costing — PURE: the landed-cost maths of a supplier order,
 * replicating the workbook's order sheet exactly so Jeff's numbers do not
 * change when the sheet retires.
 *
 * Two modes, as the sheet had two generations of the layout:
 *
 *   box mode   fraction_i = received_i ÷ (per box of the line, else of the
 *              species, else 24) — 0 when nothing was received;
 *              fees per box = (domestic flight + import & re-ox fees) ÷ Σ fraction;
 *              landed_i = received_i × price_i × (1 + tariffs ÷ Σ(received × price))
 *                       + fraction_i × fees per box
 *              (tariffs spread in proportion to the goods value, the
 *              flight and import fees in proportion to box space).
 *   bag mode   units_i = bags_i when typed, else received_i;
 *              per bag = (flight + import + tariffs) ÷ Σ units;
 *              landed_i = units_i × price_i + units_i × per bag.
 *
 * Per line: landed per fish = landed ÷ received; the suggested price =
 * landed per fish × markup (4) rounded to the nearest 5; profit =
 * received × worth − landed; margin = profit ÷ landed; "worth it" when
 * the margin is above the threshold (2.75); revenue = worth × survived;
 * DOA cost = DOA × price.
 *
 * Per order: total = Σ landed; grand total = total − credit; revenue =
 * Σ revenue; up/down = revenue − grand total; ratio = revenue ÷ grand
 * total; fill rate = Σ received ÷ Σ ordered; survival = Σ survived ÷
 * (Σ received − Σ DOA); DOA rate = Σ DOA ÷ Σ received; the sheet's DOA
 * cost = Σ (DOA × price) + Σ DOA × fee rate — in box mode the fee rate is
 * a WHOLE box's fees, a carry-over from the bag version, so the per-fish
 * figure (fees per box ÷ per box) is given beside it.
 *
 * Every division is guarded: an undefined ratio is null (never a
 * warning), a sum stays 0. Full float precision is kept throughout —
 * money is rounded only by the format helpers and when a figure is
 * stored.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Costing
 */
class Costing {

	const MODES = array( 'box', 'bag' );

	/**
	 * Fish per box when neither the line nor the species says.
	 *
	 * @var int
	 */
	const DEFAULT_PER_BOX = 24;

	/**
	 * The suggested-price multiplier on the landed cost per fish.
	 *
	 * @var float
	 */
	const DEFAULT_MARKUP = 4.0;

	/**
	 * A line is "worth it" when its margin (profit ÷ landed) is above this.
	 *
	 * @var float
	 */
	const DEFAULT_THRESHOLD = 2.75;

	/**
	 * The suggested price rounds to the nearest multiple of this.
	 *
	 * @var int
	 */
	const PRICE_STEP = 5;

	/**
	 * Cost an order.
	 *
	 * @param array   $order           cost_mode (box|bag), tariffs, domestic_flight, import_fees, credit,
	 *                                 markup, worth_threshold.
	 * @param array[] $lines           qty_ordered, qty_received, per_box, bags, unit_price, worth,
	 *                                 qty_survived (null = unknown), qty_doa, species_per_box.
	 * @param int     $default_per_box Fish per box when nothing says.
	 * @return array{mode: string, lines: array[], order: array}
	 */
	public static function compute( array $order, array $lines, $default_per_box = self::DEFAULT_PER_BOX ) {
		$mode      = 'bag' === (string) ( $order['cost_mode'] ?? 'box' ) ? 'bag' : 'box';
		$tariffs   = self::money_in( $order['tariffs'] ?? 0 );
		$flight    = self::money_in( $order['domestic_flight'] ?? 0 );
		$import    = self::money_in( $order['import_fees'] ?? 0 );
		$credit    = self::money_in( $order['credit'] ?? 0 );
		$markup    = self::money_in( $order['markup'] ?? null, self::DEFAULT_MARKUP );
		$threshold = self::money_in( $order['worth_threshold'] ?? null, self::DEFAULT_THRESHOLD );
		$fallback  = (int) $default_per_box > 0 ? (int) $default_per_box : self::DEFAULT_PER_BOX;

		$rows         = array();
		$sum_goods    = 0.0;
		$sum_units    = 0.0;
		$sum_ordered  = 0;
		$sum_received = 0;
		$sum_doa      = 0;
		$sum_survived = 0;
		$known        = false;

		foreach ( array_values( $lines ) as $line ) {
			$ordered  = max( 0, (int) ( $line['qty_ordered'] ?? 0 ) );
			$received = isset( $line['qty_received'] ) && null !== $line['qty_received'] ? max( 0, (int) $line['qty_received'] ) : 0;
			$doa      = max( 0, (int) ( $line['qty_doa'] ?? 0 ) );
			$survived = isset( $line['qty_survived'] ) && null !== $line['qty_survived'] && '' !== $line['qty_survived'] ? max( 0, (int) $line['qty_survived'] ) : null;
			$price    = self::money_in( $line['unit_price'] ?? 0 );
			$worth    = self::money_in( $line['worth'] ?? 0 );
			$bags     = max( 0, (int) ( $line['bags'] ?? 0 ) );
			$per_box  = (int) ( $line['per_box'] ?? 0 );
			if ( $per_box < 1 ) {
				$per_box = (int) ( $line['species_per_box'] ?? 0 );
			}
			if ( $per_box < 1 ) {
				$per_box = $fallback;
			}

			if ( 'box' === $mode ) {
				$units = $received > 0 ? $received / $per_box : 0.0;
			} else {
				$units = $bags > 0 ? (float) $bags : (float) $received;
			}

			$rows[] = array(
				'qty_ordered'  => $ordered,
				'qty_received' => $received,
				'qty_doa'      => $doa,
				'qty_survived' => $survived,
				'unit_price'   => $price,
				'worth'        => $worth,
				'per_box'      => $per_box,
				'bags'         => $bags,
				'units'        => $units,
			);

			$sum_goods    += $received * $price;
			$sum_units    += $units;
			$sum_ordered  += $ordered;
			$sum_received += $received;
			$sum_doa      += $doa;
			if ( null !== $survived ) {
				$sum_survived += $survived;
				$known         = true;
			}
		}

		if ( 'box' === $mode ) {
			$fee_rate      = $sum_units > 0 ? ( $flight + $import ) / $sum_units : 0.0;
			$tariff_factor = $sum_goods > 0 ? 1 + $tariffs / $sum_goods : 1.0;
		} else {
			$fee_rate      = $sum_units > 0 ? ( $flight + $import + $tariffs ) / $sum_units : 0.0;
			$tariff_factor = 1.0;
		}

		$out_lines         = array();
		$total             = 0.0;
		$revenue           = 0.0;
		$doa_cost_lines    = 0.0;
		$doa_cost_per_fish = 0.0;

		foreach ( $rows as $row ) {
			$received = $row['qty_received'];
			$price    = $row['unit_price'];
			$units    = $row['units'];

			if ( 'box' === $mode ) {
				$landed = $received * $price * $tariff_factor + $units * $fee_rate;
			} else {
				$landed = $units * $price + $units * $fee_rate;
			}

			$per_fish  = $received > 0 ? $landed / $received : null;
			$suggested = null !== $per_fish ? self::round_to( $per_fish * $markup, self::PRICE_STEP ) : null;
			$profit    = $received * $row['worth'] - $landed;
			$margin    = $landed > 0 ? $profit / $landed : null;
			$worth_it  = null !== $margin && $margin > $threshold;
			$survived  = null === $row['qty_survived'] ? 0 : $row['qty_survived'];
			$line_rev  = $row['worth'] * $survived;
			$doa_cost  = $row['qty_doa'] * $price;
			$fee_share = 'bag' === $mode ? $fee_rate : $fee_rate / $row['per_box'];
			$doa_fish  = $row['qty_doa'] * ( $price + $fee_share );

			$out_lines[] = array_merge(
				$row,
				array(
					'fraction'          => 'box' === $mode ? $units : null,
					'landed_total'      => $landed,
					'landed_per_fish'   => $per_fish,
					'suggested_price'   => $suggested,
					'profit'            => $profit,
					'margin'            => $margin,
					'worth_it'          => $worth_it,
					'revenue'           => $line_rev,
					'doa_cost'          => $doa_cost,
					'doa_cost_per_fish' => $doa_fish,
				)
			);

			$total             += $landed;
			$revenue           += $line_rev;
			$doa_cost_lines    += $doa_cost;
			$doa_cost_per_fish += $doa_fish;
		}

		$grand = $total - $credit;

		return array(
			'mode'  => $mode,
			'lines' => $out_lines,
			'order' => array(
				'mode'              => $mode,
				'lines'             => count( $out_lines ),
				'ordered'           => $sum_ordered,
				'received'          => $sum_received,
				'survived'          => $sum_survived,
				'survived_known'    => $known,
				'doa'               => $sum_doa,
				'goods'             => $sum_goods,
				'units'             => $sum_units,
				'boxes'             => 'box' === $mode ? $sum_units : null,
				'fee_rate'          => $fee_rate,
				'fees_per_box'      => 'box' === $mode ? $fee_rate : null,
				'per_bag'           => 'bag' === $mode ? $fee_rate : null,
				'tariff_factor'     => $tariff_factor,
				'tariffs'           => $tariffs,
				'domestic_flight'   => $flight,
				'import_fees'       => $import,
				'credit'            => $credit,
				'markup'            => $markup,
				'worth_threshold'   => $threshold,
				'total'             => $total,
				'grand_total'       => $grand,
				'revenue'           => $revenue,
				'up_down'           => $revenue - $grand,
				'ratio'             => abs( $grand ) > 1e-9 ? $revenue / $grand : null,
				'fill_rate'         => $sum_ordered > 0 ? $sum_received / $sum_ordered : null,
				'survival_rate'     => $known && ( $sum_received - $sum_doa ) > 0 ? $sum_survived / ( $sum_received - $sum_doa ) : null,
				'doa_rate'          => $sum_received > 0 ? $sum_doa / $sum_received : null,
				'doa_cost_sheet'    => $doa_cost_lines + $sum_doa * $fee_rate,
				'doa_cost_lines'    => $doa_cost_lines,
				'doa_cost_per_fish' => $doa_cost_per_fish,
			),
		);
	}

	/**
	 * A number to the nearest multiple of a step (Excel's MROUND: halves
	 * away from zero).
	 *
	 * @param float $value Value.
	 * @param float $step  Step (0 → the value unchanged).
	 * @return float
	 */
	public static function round_to( $value, $step ) {
		$step = (float) $step;
		if ( $step <= 0 ) {
			return (float) $value;
		}
		return $step * round( (float) $value / $step );
	}

	/**
	 * A money / rate input as a float: blank, junk or null → the default.
	 *
	 * @param mixed      $value   Raw.
	 * @param float|null $default Default (0).
	 * @return float
	 */
	public static function money_in( $value, $default = 0.0 ) {
		if ( is_bool( $value ) || null === $value || ! is_scalar( $value ) ) {
			return (float) $default;
		}
		$value = str_replace( array( '$', ',', ' ' ), '', (string) $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return (float) $default;
		}
		return (float) $value;
	}

	/* ============================================================== */
	/*  Display — the only place anything is rounded                   */
	/* ============================================================== */

	/**
	 * '1,234.50' / the blank for null.
	 *
	 * @param float|null $value Value.
	 * @param string     $blank What a null shows as.
	 * @return string
	 */
	public static function money( $value, $blank = '—' ) {
		if ( null === $value || ! is_numeric( $value ) ) {
			return $blank;
		}
		return number_format( round( (float) $value, 2 ), 2, '.', ',' );
	}

	/**
	 * '80.7%' / the blank for null.
	 *
	 * @param float|null $value    A ratio (0.807).
	 * @param string     $blank    What a null shows as.
	 * @param int        $decimals Decimals.
	 * @return string
	 */
	public static function pct( $value, $blank = '—', $decimals = 1 ) {
		if ( null === $value || ! is_numeric( $value ) ) {
			return $blank;
		}
		return number_format( round( (float) $value * 100, (int) $decimals ), (int) $decimals, '.', ',' ) . '%';
	}

	/**
	 * '2.75' / '3' / the blank for null — a plain number without trailing zeros.
	 *
	 * @param float|null $value    Value.
	 * @param string     $blank    What a null shows as.
	 * @param int        $decimals Max decimals.
	 * @return string
	 */
	public static function num( $value, $blank = '—', $decimals = 2 ) {
		if ( null === $value || ! is_numeric( $value ) ) {
			return $blank;
		}
		$text = number_format( round( (float) $value, (int) $decimals ), (int) $decimals, '.', '' );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return '' === $text || '-0' === $text ? '0' : $text;
	}

	/**
	 * A money value rounded to cents for storage (null stays null).
	 *
	 * @param float|null $value Value.
	 * @return float|null
	 */
	public static function cents( $value ) {
		return null === $value || ! is_numeric( $value ) ? null : round( (float) $value, 2 );
	}
}
