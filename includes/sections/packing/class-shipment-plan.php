<?php
/**
 * The shipment plan — what Packing knows about an order, in the words a
 * shipment is recorded in: which box, and how many bags to pull for it.
 *
 * WHY: the owner picks the box and the bags in the Packing box, then had to
 * type the same box and count the same bags again where the shipment is
 * recorded (the theme's Shipments box, ShipTracker's materials). "Repeating
 * myself is one of those things that drives me mad." Two seams, both read
 * only:
 *
 *   - fishotel_packing_shipment_plan( $order_id ) — for_order_id() — the
 *     boxes of the order's plan (confirmed, else suggested), each with
 *     ShipTracker's own row for it, the bags in it, the LAYERS (single bags
 *     to pull: every bag is doubled, tripled for spines — Layers) and the
 *     order lines it holds. The theme prefills its form from it.
 *   - fish_count() — hooked on ShipTracker's filter `fst_box_fish_count`.
 *     ShipTracker uses its "fish count" only as the fish-bag quantity of a
 *     box's usual materials, so answering the layers there makes every
 *     place that asks agree — the Add form, the cards of a recorded box,
 *     a pickup, the materials recorded with a new tracking number, the REST
 *     answer — without ShipTracker changing.
 *
 * build(), standard_for() and bags_for_count() are pure. Nothing here
 * writes anything, and nothing here may throw past its caller.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shipment_Plan
 */
class Shipment_Plan {

	const MATCH_NAME = 'name';
	const MATCH_DIMS = 'dims';
	const MATCH_NONE = 'none';

	/**
	 * What fish_count() read in this request, by order id: `items` (order
	 * line id → its whole quantity) and `layers` (order line id → the single
	 * bags it takes). False for an order with no fish line. Kept only while
	 * the request reads (Order_Packing::reading()): the order screen asks
	 * line by line.
	 *
	 * @var array<int, array|false>
	 */
	private static $counts = array();

	/**
	 * The answer when there is nothing to say: every key of a real answer,
	 * empty, with `available` false and the reason.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   disabled | no_woocommerce | order_not_found | no_plan | error.
	 * @return array
	 */
	public static function nothing( $order_id, $reason ) {
		return Packing::no_shipment_plan( $order_id, $reason );
	}

	/**
	 * ShipTracker's own row for a box of the plan (pure): the standard box
	 * with Packing's name (letter case and extra spaces aside), else the
	 * FIRST standard box whose length, width and height are the same
	 * numbers, else none. The row's name and dims are handed on exactly as
	 * ShipTracker spells them — its forms select a box by them.
	 *
	 * @param array      $box  name, length, width, height.
	 * @param array|null $rows FST_Settings::get_standard_boxes() as it answered (null = it is not there).
	 * @return array{standard: array|null, match: string}
	 */
	public static function standard_for( array $box, $rows ) {
		$none = array(
			'standard' => null,
			'match'    => self::MATCH_NONE,
		);
		if ( ! is_array( $rows ) ) {
			return $none;
		}

		$key = Boxes::key( $box['name'] ?? '' );
		$by  = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_scalar( $row['name'] ) || '' === trim( (string) $row['name'] ) ) {
				continue;
			}
			if ( '' !== $key && Boxes::key( $row['name'] ) === $key ) {
				return array(
					'standard' => self::standard_row( $row ),
					'match'    => self::MATCH_NAME,
				);
			}
			if ( null === $by && self::same_size( $box, $row ) ) {
				$by = $row;
			}
		}

		if ( null === $by ) {
			return $none;
		}
		return array(
			'standard' => self::standard_row( $by ),
			'match'    => self::MATCH_DIMS,
		);
	}

	/**
	 * A ShipTracker row as the plan hands it on: its name and its dims,
	 * verbatim (the dims put together the way ShipTracker does when the row
	 * carries none).
	 *
	 * @param array $row A row of FST_Settings::get_standard_boxes().
	 * @return array{name: string, dims: string}
	 */
	private static function standard_row( array $row ) {
		$dims = isset( $row['dims'] ) && is_scalar( $row['dims'] ) ? (string) $row['dims'] : '';
		if ( '' === $dims ) {
			$dims = self::text( $row['length'] ?? '' ) . 'x' . self::text( $row['width'] ?? '' ) . 'x' . self::text( $row['height'] ?? '' );
		}
		return array(
			'name' => (string) $row['name'],
			'dims' => $dims,
		);
	}

	/**
	 * A scalar as text ('' for anything else).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a box and a ShipTracker row are the same size, as numbers
	 * ("12.0" is 12).
	 *
	 * @param array $box length, width, height.
	 * @param array $row length, width, height.
	 * @return bool
	 */
	private static function same_size( array $box, array $row ) {
		foreach ( array( 'length', 'width', 'height' ) as $side ) {
			$a = Boxes::number( $box[ $side ] ?? '' );
			$b = Boxes::number( $row[ $side ] ?? '' );
			if ( null === $a || null === $b || abs( $a - $b ) > 0.0001 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The plan from an order's suggestion (pure).
	 *
	 * @param array      $result   Order_Packing::for_order().
	 * @param array[]    $bags     Settings' bags (their order: smallest first).
	 * @param array|null $rows     FST_Settings::get_standard_boxes() as it answered (null = not there).
	 * @param int        $order_id The order asked.
	 * @return array See fishotel_packing_shipment_plan().
	 */
	public static function build( array $result, array $bags, $rows, $order_id ) {
		$order_id = (int) $order_id;
		$wrapper  = (int) ( $result['packed_with'] ?? 0 );
		$lines    = isset( $result['lines'] ) && is_array( $result['lines'] ) ? array_values( $result['lines'] ) : array();

		if ( ! $lines && $wrapper < 1 ) {
			return self::nothing( $order_id, 'no_plan' );
		}

		$folded = $wrapper > 0;
		$packed = ! $folded && isset( $result['packed']['boxes'] ) && is_array( $result['packed']['boxes'] ) ? array_values( $result['packed']['boxes'] ) : array();

		$names = array();
		foreach ( $bags as $bag ) {
			$names[ (int) $bag['id'] ] = (string) $bag['name'];
		}
		$largest_first = array_reverse( array_keys( $names ) );

		$boxes = array();
		foreach ( $packed as $b => $box ) {
			$found   = self::standard_for( $box, $rows );
			$boxes[] = array(
				'index'    => $b,
				'name'     => (string) ( $box['name'] ?? '' ),
				'dims'     => Boxes::dims( $box['length'] ?? 0, $box['width'] ?? 0, $box['height'] ?? 0 ),
				'standard' => $found['standard'],
				'match'    => $found['match'],
				'fish'     => 0,
				'bags'     => 0,
				'layers'   => 0,
				'by_bag'   => array(),
				'items'    => array(),
			);
		}

		$out_lines = array();
		$totals    = array(
			'fish'   => 0,
			'bags'   => 0,
			'layers' => 0,
		);
		$by_bag    = array(); // Box → bag id → [ count, layers ].

		foreach ( $lines as $line ) {
			$qty     = max( 0, (int) ( $line['qty'] ?? 0 ) );
			$per_bag = max( 1, (int) ( $line['fish_per_bag'] ?? 1 ) );
			$count   = ! empty( $line['bag'] ) ? max( 0, (int) ( $line['bags'] ?? 0 ) ) : Suggest::bags_for( $qty, $per_bag );
			$layers  = isset( $line['layers'] ) && (int) $line['layers'] > 0 ? (int) $line['layers'] : Layers::DOUBLE;
			$key     = (int) ( $line['order_id'] ?? 0 ) . '-' . (int) ( $line['item_id'] ?? 0 );
			$in      = array();
			$left    = $qty;

			foreach ( $folded ? array() : (array) ( $line['in_boxes'] ?? array() ) as $b => $load ) {
				$b = (int) $b;
				if ( ! isset( $boxes[ $b ] ) || ! is_array( $load ) ) {
					continue;
				}
				$here = 0;
				foreach ( $load as $bag_id => $n ) {
					$n = max( 0, (int) $n );
					if ( $n < 1 ) {
						continue;
					}
					$here += $n;
					$by_bag[ $b ][ (int) $bag_id ] = array(
						( $by_bag[ $b ][ (int) $bag_id ][0] ?? 0 ) + $n,
						( $by_bag[ $b ][ (int) $bag_id ][1] ?? 0 ) + $n * $layers,
					);
				}
				if ( $here < 1 ) {
					continue;
				}
				$fish                       = min( $left, $here * $per_bag );
				$left                      -= $fish;
				$in[]                       = $b;
				$boxes[ $b ]['fish']       += $fish;
				$boxes[ $b ]['bags']       += $here;
				$boxes[ $b ]['layers']     += $here * $layers;
				$boxes[ $b ]['items'][ $key ] = ( $boxes[ $b ]['items'][ $key ] ?? 0 ) + $here;
			}
			sort( $in );

			$out_lines[] = array(
				'order_id'    => (int) ( $line['order_id'] ?? 0 ),
				'item_id'     => (int) ( $line['item_id'] ?? 0 ),
				'name'        => (string) ( $line['name'] ?? '' ),
				'qty'         => $qty,
				'bags'        => $count,
				'layers_each' => $layers,
				'why'         => (string) ( $line['layers_why'] ?? Layers::WHY_DEFAULT ),
				'boxes'       => $in,
			);

			$totals['fish']   += $qty;
			$totals['bags']   += $count;
			$totals['layers'] += $count * $layers;
		}

		foreach ( $boxes as $b => $box ) {
			$ids = array_keys( $by_bag[ $b ] ?? array() );
			// The largest bag first, as the Packing box lists them; a bag that left the list last.
			usort(
				$ids,
				static function ( $x, $y ) use ( $largest_first ) {
					$px = array_search( $x, $largest_first, true );
					$py = array_search( $y, $largest_first, true );
					$px = false === $px ? PHP_INT_MAX : $px;
					$py = false === $py ? PHP_INT_MAX : $py;
					return $px === $py ? $x <=> $y : $px <=> $py;
				}
			);
			foreach ( $ids as $bag_id ) {
				$boxes[ $b ]['by_bag'][] = array(
					'id'     => (int) $bag_id,
					'name'   => isset( $names[ $bag_id ] ) ? $names[ $bag_id ] : '#' . (int) $bag_id,
					'count'  => (int) $by_bag[ $b ][ $bag_id ][0],
					'layers' => (int) $by_bag[ $b ][ $bag_id ][1],
				);
			}
		}

		$from = $boxes ? (string) ( $result['packed']['from'] ?? '' ) : '';

		return array(
			'available'   => true,
			'reason'      => '',
			'order_id'    => $order_id,
			'owner_id'    => $folded ? $wrapper : $order_id,
			'packed_with' => $wrapper,
			'from'        => in_array( $from, array( Plan::FROM_CONFIRMED, Plan::FROM_SUGGESTION ), true ) ? $from : '',
			'split'       => count( $boxes ) > 1,
			'boxes'       => $boxes,
			'lines'       => $out_lines,
			'totals'      => $totals,
		);
	}

	/**
	 * The plan of an order by id — fishotel_packing_shipment_plan(). While
	 * a page is being drawn the order's suggestion is the one its Packing
	 * box drew in this request (Order_Packing::plan_of()), not worked out
	 * again; in a request that may write it is worked out each time.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public static function for_order_id( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::nothing( $order_id, 'no_woocommerce' );
		}

		$order = Order_Packing::load( $order_id );
		if ( null === $order ) {
			return self::nothing( $order_id, 'order_not_found' );
		}

		$settings = Settings::get();

		return self::build( Order_Packing::plan_of( $order ), $settings['bags'], Boxes::shiptracker_rows(), $order_id );
	}

	/* ============================================================== */
	/*  ShipTracker's "fish count"                                     */
	/* ============================================================== */

	/**
	 * What each product line of an order takes when it is in a box: its
	 * whole quantity (`items`) and its single bags — bags × layers
	 * (`layers`). A line that is not a fish is in `items` only. Null for an
	 * order with no fish line: Packing has no plan for it.
	 *
	 * Every line counts WHOLE — a shipped or refunded one too: ShipTracker
	 * asks about the lines of a box that already left, and the theme scales
	 * a refund itself. A combined box is read through its source orders.
	 *
	 * @param object $order WC_Order.
	 * @return array{items: array<int, int>, layers: array<int, int>}|null
	 */
	public static function item_layers( $order ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( ! Order_Packing::reading() ) {
			$order_id = 0; // A request that may write: read the order again each time.
		}
		if ( $order_id > 0 && isset( self::$counts[ $order_id ] ) ) {
			return false === self::$counts[ $order_id ] ? null : self::$counts[ $order_id ];
		}

		$settings = Settings::get();
		$read     = Lines::for_order( $order, $settings, Size_List::standard(), true );
		$known    = null;

		if ( $read['lines'] ) {
			$layers = array();
			foreach ( $read['lines'] as $line ) {
				$bags    = ! empty( $line['bag'] ) ? (int) $line['bags'] : Suggest::bags_for( (int) $line['qty'], (int) $line['fish_per_bag'] );
				$item_id = (int) $line['item_id'];

				$layers[ $item_id ] = ( $layers[ $item_id ] ?? 0 ) + max( 0, $bags ) * (int) $line['layers'];
			}
			$known = array(
				'items'  => isset( $read['items'] ) && is_array( $read['items'] ) ? $read['items'] : array(),
				'layers' => $layers,
			);
		}

		if ( $order_id > 0 ) {
			self::$counts[ $order_id ] = null === $known ? false : $known;
		}
		return $known;
	}

	/**
	 * The single bags behind a count ShipTracker made (pure). Null = this
	 * cannot say: the count is left as it is.
	 *
	 *   - item ids given: the bags of those lines. A line that is not a fish
	 *     counts none, and neither does an id that is not on the order (as
	 *     ShipTracker counts) — unless none of them is, which is an order
	 *     read through other lines than these: left alone.
	 *   - no ids, the count is the order's whole quantity: all its bags.
	 *   - no ids, a smaller count (ShipTracker's "not in a box yet"): that
	 *     share of the bags, to a whole bag.
	 *
	 * @param mixed      $count    ShipTracker's count.
	 * @param array      $items    Order line id → its whole quantity.
	 * @param array      $layers   Order line id → its single bags.
	 * @param array|null $item_ids The lines in the box (null = the order).
	 * @return int|null
	 */
	public static function bags_for_count( $count, array $items, array $layers, $item_ids = null ) {
		if ( is_array( $item_ids ) ) {
			$sum   = 0;
			$known = 0;
			$seen  = array();
			foreach ( $item_ids as $id ) {
				$id = is_scalar( $id ) ? (int) $id : 0;
				if ( isset( $seen[ $id ] ) || ! isset( $items[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$known++;
				$sum += (int) ( $layers[ $id ] ?? 0 );
			}
			return $item_ids && 0 === $known ? null : $sum;
		}

		if ( null !== $item_ids || ! is_numeric( $count ) ) {
			return null;
		}

		$count = (float) $count;
		$total = (float) array_sum( $items );
		$all   = (int) array_sum( $layers );
		if ( $count <= 0 || $total <= 0 ) {
			return null;
		}
		if ( $count >= $total - 0.0001 ) {
			return $all;
		}

		return (int) round( $all * $count / $total );
	}

	/**
	 * The callback of ShipTracker's filter `fst_box_fish_count`: the single
	 * bags (layers) of the order — or of some of its lines — in place of
	 * the fish. The count comes back unchanged for an order Packing has no
	 * plan for, and whenever anything here fails.
	 *
	 * @param mixed $count    ShipTracker's count: the quantities added up.
	 * @param mixed $order    WC_Order.
	 * @param mixed $item_ids The order's items in the box; null = all of them.
	 * @return mixed
	 */
	public static function fish_count( $count, $order = null, $item_ids = null ) {
		try {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'get_meta' ) ) {
				return $count;
			}
			$known = self::item_layers( $order );
			if ( null === $known ) {
				return $count;
			}
			$bags = self::bags_for_count( $count, $known['items'], $known['layers'], $item_ids );

			return null === $bags ? $count : $bags;
		} catch ( \Throwable $e ) {
			return $count;
		}
	}

	/**
	 * Drop what fish_count() read in this request.
	 */
	public static function forget() {
		self::$counts = array();
	}
}
