<?php
/**
 * The packing of one order: its suggestion, and the box the owner
 * confirmed.
 *
 * The suggestion (for_order()) puts the pieces together and writes
 * nothing: the order's fish lines with their bags (Lines), the bags needed
 * by size, the boxes in use with how many fit (Boxes), the box or boxes
 * that hold them (Suggest), and what a person should read about it.
 *
 * The confirmed box is ORDER META, read and written through the order
 * object only (get_meta / update_meta_data / delete_meta_data / save), so
 * it works with High-Performance Order Storage on or off:
 *
 *   _fh_packing_box       the record: v, boxes (name, length, width,
 *                         height), fill (as it stood), suggested (the
 *                         choice was the suggestion), complete (every fish
 *                         line had a bag size), by (user id), at (UTC)
 *   _fh_packing_box_name  the same as plain text: "Medium box",
 *                         "2 × Large box + Small box"
 *   _fh_packing_bags      the bags chosen by hand: line key → bag id
 *
 * The bag each fish goes in (Plan): its minimum bag chooses the box; inside
 * the box (the confirmed one, else the suggestion's) the fish are moved up
 * to the largest bags that still fit (Upsize); a bag chosen by hand on a
 * line is kept.
 *
 * Confirming, changing and clearing go through confirm_request(), which
 * checks the capability (manage_woocommerce) and the nonce itself and
 * answers with a status. It sends no e-mail, adds no note, touches no
 * stock and changes no status: two meta keys on the order, nothing else.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Packing
 */
class Order_Packing {

	/**
	 * Nonce action of the Packing box on the order screen.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_packing_order';

	/**
	 * The most boxes of one kind a hand-made choice may name.
	 *
	 * @var int
	 */
	const MAX_COUNT = 20;

	/**
	 * The most bags chosen by hand one order keeps.
	 *
	 * @var int
	 */
	const MAX_OVERRIDES = 200;

	/**
	 * The plans bag_to_use() worked out in this request, by order id.
	 *
	 * @var array<int, array>
	 */
	private static $plans = array();

	/* ============================================================== */
	/*  The suggestion                                                 */
	/* ============================================================== */

	/**
	 * The suggestion of an order by id — fishotel_packing_suggest().
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public static function suggest( $order_id ) {
		$order_id = (int) $order_id;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return Packing::unavailable( $order_id, 'no_woocommerce' );
		}

		$order = self::load( $order_id );
		if ( null === $order ) {
			return Packing::unavailable( $order_id, 'order_not_found' );
		}

		return self::for_order( $order );
	}

	/**
	 * An order by id — an order, not a refund or anything else WooCommerce
	 * keeps beside orders.
	 *
	 * @param int $order_id Order ID.
	 * @return object|null WC_Order.
	 */
	public static function load( $order_id ) {
		$order = (int) $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		if ( method_exists( $order, 'get_type' ) && 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return $order;
	}

	/**
	 * The suggestion of an order.
	 *
	 * @param object     $order    WC_Order.
	 * @param array|null $settings Settings (null = the stored ones).
	 * @param array|null $standard Size_List::standard() (null = resolve it).
	 * @param array|null $current  Boxes::current() (null = resolve it).
	 * @return array See fishotel_packing_suggest().
	 */
	public static function for_order( $order, $settings = null, $standard = null, $current = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = Settings::get();
		}
		if ( ! is_array( $standard ) ) {
			$standard = Size_List::standard();
		}
		if ( ! is_array( $current ) ) {
			$current = Boxes::current( $settings );
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$gathered = Lines::for_order( $order, $settings, $standard );

		$needs      = array();
		$incomplete = array();
		foreach ( $gathered['lines'] as $line ) {
			if ( $line['bag'] ) {
				$id           = (int) $line['bag']['id'];
				$needs[ $id ] = ( $needs[ $id ] ?? 0 ) + (int) $line['bags'];
				continue;
			}
			if ( isset( $line['reason'] ) ) {
				$reason = (string) $line['reason'];
			} elseif ( '' !== (string) $line['size'] ) {
				/* translators: %s: size label */
				$reason = sprintf( __( 'no bag for the size %s in the size → bag map', 'fishotel-misc-plugin' ), $line['size_label'] );
			} else {
				$reason = __( 'no bag size', 'fishotel-misc-plugin' );
			}
			$incomplete[] = array(
				'item_id'  => (int) $line['item_id'],
				'order_id' => (int) $line['order_id'],
				'name'     => (string) $line['name'],
				'qty'      => (int) $line['qty'],
				'reason'   => $reason,
			);
		}

		$model         = Boxes::model( $settings );
		$boxes         = Boxes::with_fits( $current['boxes'], $settings['fits'], $settings['bags'], $model );
		$order_of_bags = array();
		foreach ( $settings['bags'] as $bag ) {
			$order_of_bags[] = (int) $bag['id'];
		}
		$plan = Suggest::suggest( $needs, $boxes, $settings['threshold'], $order_of_bags );

		// The bags to use: upsized inside the confirmed box, else the suggested one.
		$lines     = self::keyed( $gathered['lines'] );
		$confirmed = self::confirmed( $order );
		$overrides = self::overrides( $order, $lines, $settings );
		$folded    = (int) $gathered['packed_with'] > 0;
		$in_boxes  = null;
		$same      = false;
		if ( $confirmed && ! $folded ) {
			$in_boxes = self::confirmed_boxes( $confirmed, $settings, $model );
			$same     = in_array( $plan['status'], array( Suggest::STATUS_FITS, Suggest::STATUS_SPLIT ), true ) && self::boxes_name( $plan['boxes'] ) === $confirmed['name'];
		}
		$packed = Plan::build( $lines, $folded ? array() : $plan, $boxes, $order_of_bags, $overrides, $in_boxes, $same );
		$lines  = self::with_use( $lines, $packed, $settings );

		$used = array();
		foreach ( $lines as $line ) {
			foreach ( $line['use'] as $row ) {
				$used[ $row['id'] ] = ( $used[ $row['id'] ] ?? 0 ) + $row['count'];
			}
		}

		$bags      = array();
		$bags_used = array();
		$unplaced  = array();
		foreach ( $settings['bags'] as $bag ) {
			$id = (int) $bag['id'];
			if ( ! empty( $needs[ $id ] ) ) {
				$bags[] = array(
					'id'     => $id,
					'name'   => (string) $bag['name'],
					'width'  => (string) $bag['width'],
					'length' => (string) $bag['length'],
					'label'  => Bags::label( $bag ),
					'count'  => (int) $needs[ $id ],
				);
			}
			if ( ! empty( $used[ $id ] ) ) {
				$bags_used[] = array(
					'id'     => $id,
					'name'   => (string) $bag['name'],
					'width'  => (string) $bag['width'],
					'length' => (string) $bag['length'],
					'label'  => Bags::label( $bag ),
					'count'  => (int) $used[ $id ],
				);
			}
			if ( ! empty( $plan['unplaced'][ $id ] ) ) {
				$unplaced[] = array(
					'id'    => $id,
					'name'  => (string) $bag['name'],
					'count' => (int) $plan['unplaced'][ $id ],
				);
			}
		}

		$result = array(
			'available'      => true,
			'reason'         => '',
			'order_id'       => $order_id,
			'status'         => $plan['status'],
			'boxes'          => $plan['boxes'],
			'split'          => $plan['split'],
			'on_the_line'    => $plan['on_the_line'],
			'threshold'      => $plan['threshold'],
			'bags'           => $bags,
			'bag_total'      => $plan['bag_total'],
			'bags_used'      => $bags_used,
			'packed'         => self::packed_summary( $packed ),
			'overrides'      => $overrides,
			'unplaced'       => $unplaced,
			'lines'          => $lines,
			'incomplete'     => $incomplete,
			'complete'       => ! $incomplete,
			'ignored'        => $gathered['ignored'],
			'skipped'        => $gathered['skipped'],
			'box_source'     => $current['source'],
			'is_fulfillment' => $gathered['is_fulfillment'],
			'sources'        => $gathered['sources'],
			'packed_with'    => $gathered['packed_with'],
			'confirmed'      => $confirmed,
			'messages'       => array(),
		);

		$result['messages'] = self::messages( $result, $settings, $current, ! empty( $gathered['all_sources_dead'] ) );

		return $result;
	}

	/**
	 * What a person should read about a suggestion (pure).
	 *
	 * @param array $result   The suggestion so far.
	 * @param array $settings Settings.
	 * @param array $current  Boxes::current().
	 * @param bool  $all_dead A combined box whose every source is cancelled or refunded.
	 * @return string[]
	 */
	public static function messages( array $result, array $settings, array $current, $all_dead = false ) {
		$out = array();

		if ( $result['packed_with'] > 0 ) {
			/* translators: %d: order ID of the combined box */
			$out[] = sprintf( __( 'This order is packed with combined box #%d: the box is suggested and confirmed there.', 'fishotel-misc-plugin' ), $result['packed_with'] );
		}
		if ( $all_dead ) {
			$out[] = __( 'Every order in this combined box is cancelled or refunded: nothing to pack.', 'fishotel-misc-plugin' );
		}
		if ( ! $settings['bags'] ) {
			$out[] = __( 'No bag sizes yet: add them under FisHotel Tools → Packing.', 'fishotel-misc-plugin' );
		}

		if ( $result['incomplete'] ) {
			$names = array();
			foreach ( $result['incomplete'] as $line ) {
				$names[] = $line['qty'] . ' × ' . $line['name'];
			}
			$count = count( $result['incomplete'] );
			/* translators: 1: number of lines, 2: the lines, as "2 × Name" separated by commas */
			$out[] = sprintf( _n( '%1$d fish line has no bag size, so the suggestion is incomplete: %2$s.', '%1$d fish lines have no bag size, so the suggestion is incomplete: %2$s.', $count, 'fishotel-misc-plugin' ), $count, implode( ', ', $names ) );
		}

		switch ( $result['status'] ) {
			case Suggest::STATUS_EMPTY:
				if ( ! $result['lines'] && ! $all_dead ) {
					$out[] = __( 'No fish to bag on this order.', 'fishotel-misc-plugin' );
				}
				break;

			case Suggest::STATUS_NO_BOXES:
				$out[] = Boxes::SHIPTRACKER_ABSENT === $current['shiptracker']
					? __( 'There are no boxes to choose from: add them under FisHotel Tools → Packing → Boxes.', 'fishotel-misc-plugin' )
					: __( 'There are no boxes to choose from: add them in ShipTracker → Settings → Boxes & label costs, or under FisHotel Tools → Packing → Boxes.', 'fishotel-misc-plugin' );
				break;

			case Suggest::STATUS_NO_FIT:
				$out[] = __( 'No box takes these bags: type how many fit under FisHotel Tools → Packing → Boxes.', 'fishotel-misc-plugin' );
				break;

			case Suggest::STATUS_SPLIT:
			case Suggest::STATUS_PARTIAL:
				$count = count( $result['boxes'] );
				if ( $count > 1 ) {
					/* translators: %d: number of boxes */
					$out[] = sprintf( __( 'No single box holds it all: split into %d boxes.', 'fishotel-misc-plugin' ), $count );
				}
				break;
		}

		if ( $result['unplaced'] && Suggest::STATUS_NO_BOXES !== $result['status'] && Suggest::STATUS_NO_FIT !== $result['status'] ) {
			$names = array();
			foreach ( $result['unplaced'] as $bag ) {
				$names[] = $bag['count'] . ' × ' . $bag['name'];
			}
			if ( count( $result['boxes'] ) >= Suggest::MAX_BOXES ) {
				/* translators: 1: bags, as "2 × Name" separated by commas, 2: the most boxes a suggestion names */
				$out[] = sprintf( __( 'Not boxed: %1$s — a suggestion stops at %2$d boxes.', 'fishotel-misc-plugin' ), implode( ', ', $names ), Suggest::MAX_BOXES );
			} else {
				/* translators: %s: bags, as "2 × Name" separated by commas */
				$out[] = sprintf( __( 'No box takes: %s — type how many fit under FisHotel Tools → Packing → Boxes.', 'fishotel-misc-plugin' ), implode( ', ', $names ) );
			}
		}

		foreach ( $result['boxes'] as $box ) {
			if ( $box['on_the_line'] ) {
				/* translators: 1: box name, 2: percent full, 3: threshold percent */
				$out[] = sprintf( __( 'On the line: %1$s is %2$s%% full (%3$s%% or more) — confirm it.', 'fishotel-misc-plugin' ), $box['name'], Boxes::num_text( $box['fill_pct'], 1 ), Boxes::num_text( $result['threshold'], 1 ) );
			}
		}

		foreach ( isset( $result['packed']['boxes'] ) ? $result['packed']['boxes'] : array() as $box ) {
			if ( empty( $box['over'] ) ) {
				continue;
			}
			if ( ! empty( $box['missing'] ) ) {
				$names = array();
				foreach ( $box['missing'] as $bag_id ) {
					$bag     = Bags::find( $settings['bags'], $bag_id );
					$names[] = $bag ? (string) $bag['name'] : '#' . (int) $bag_id;
				}
				/* translators: 1: bag names, separated by commas, 2: box name */
				$out[] = sprintf( __( 'Check it: %1$s does not go in %2$s at all — choose another bag or another box.', 'fishotel-misc-plugin' ), implode( ', ', $names ), $box['name'] );
			} else {
				/* translators: 1: box name, 2: percent full */
				$out[] = sprintf( __( 'Check it: the bags chosen come to %2$s%% of %1$s — more than it holds.', 'fishotel-misc-plugin' ), $box['name'], Boxes::num_text( (float) $box['fill_pct'], 1 ) );
			}
		}

		// Two boxes of one kind, equally full, are said once.
		return array_values( array_unique( $out ) );
	}

	/* ============================================================== */
	/*  The bags to use                                                */
	/* ============================================================== */

	/**
	 * Each fish line with its key: "order id-item id-n", n counting the
	 * lines one order line opened up into (an Invoice's fish), from 0.
	 *
	 * @param array[] $lines Lines::for_order()['lines'].
	 * @return array[]
	 */
	public static function keyed( array $lines ) {
		$seen = array();
		foreach ( $lines as $index => $line ) {
			$base                   = (int) ( $line['order_id'] ?? 0 ) . '-' . (int) ( $line['item_id'] ?? 0 );
			$n                      = $seen[ $base ] ?? 0;
			$seen[ $base ]          = $n + 1;
			$lines[ $index ]['key'] = $base . '-' . $n;
		}
		return $lines;
	}

	/**
	 * The bags chosen by hand as stored on the order, cleaned: line key →
	 * bag id (pure apart from reading the meta).
	 *
	 * @param object $order WC_Order.
	 * @return array<string, int>
	 */
	public static function stored_overrides( $order ) {
		$raw = is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order->get_meta( Packing::META_BAGS, true ) : array();
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $bag_id ) {
			$key = (string) $key;
			if ( 1 === preg_match( '/^\d+-\d+-\d+$/', $key ) && is_numeric( $bag_id ) && (int) $bag_id > 0 ) {
				$out[ $key ] = (int) $bag_id;
			}
			if ( count( $out ) >= self::MAX_OVERRIDES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The bags chosen by hand that apply now: those of a fish line of the
	 * order that has a bag, naming a bag of the list.
	 *
	 * @param object  $order    WC_Order.
	 * @param array[] $lines    The keyed fish lines.
	 * @param array   $settings Settings.
	 * @return array<string, int>
	 */
	public static function overrides( $order, array $lines, array $settings ) {
		$stored = self::stored_overrides( $order );
		$out    = array();
		foreach ( $lines as $line ) {
			$key = (string) ( $line['key'] ?? '' );
			if ( ! empty( $line['bag'] ) && isset( $stored[ $key ] ) && Bags::find( $settings['bags'], $stored[ $key ] ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The confirmed boxes as boxes with their fits.
	 *
	 * @param array $confirmed read_record().
	 * @param array $settings  Settings.
	 * @param array $model     Boxes::model().
	 * @return array[]
	 */
	public static function confirmed_boxes( array $confirmed, array $settings, array $model ) {
		$rows = array();
		foreach ( $confirmed['boxes'] as $box ) {
			$row = Boxes::row( $box, 'confirmed' );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}
		return Boxes::with_fits( $rows, $settings['fits'], $settings['bags'], $model );
	}

	/**
	 * The fish lines with the bags to use (pure): `use` (each bag: id,
	 * name, label, count — the largest first), `use_name` ("Large",
	 * "2 × Large + Medium"), `upsized` (fish of the line were moved up),
	 * `chosen` (the bag id chosen by hand, 0 = Auto) and `tie_off` (the
	 * usable height of the box when a bag stands taller — tie it off at
	 * about that; null otherwise).
	 *
	 * @param array[] $lines    The keyed fish lines.
	 * @param array   $packed   Plan::build().
	 * @param array   $settings Settings.
	 * @return array[]
	 */
	public static function with_use( array $lines, array $packed, array $settings ) {
		$largest_first = array_reverse( $settings['bags'] );

		foreach ( $lines as $index => $line ) {
			$row = $packed['lines'][ $index ] ?? array(
				'use'    => array(),
				'boxes'  => array(),
				'moved'  => 0,
				'chosen' => 0,
			);

			$use = array();
			foreach ( $largest_first as $bag ) {
				$id = (int) $bag['id'];
				if ( ! empty( $row['use'][ $id ] ) ) {
					$use[] = array(
						'id'    => $id,
						'name'  => (string) $bag['name'],
						'label' => Bags::label( $bag ),
						'count' => (int) $row['use'][ $id ],
					);
				}
			}

			$tie_off = null;
			foreach ( $row['boxes'] as $b ) {
				foreach ( $use as $bag ) {
					$height = $packed['boxes'][ $b ]['tie_off'][ $bag['id'] ] ?? null;
					if ( null !== $height && ( null === $tie_off || $height < $tie_off ) ) {
						$tie_off = (float) $height;
					}
				}
			}

			$lines[ $index ]['use']      = $use;
			$lines[ $index ]['use_name'] = self::use_name( $use );
			$lines[ $index ]['upsized']  = $row['moved'] > 0;
			$lines[ $index ]['chosen']   = (int) $row['chosen'];
			$lines[ $index ]['tie_off']  = $tie_off;
		}

		return $lines;
	}

	/**
	 * The bags of a line in one go: "Large", "2 × Large + Medium" (pure).
	 *
	 * @param array[] $use name, count.
	 * @param string  $key name or label.
	 * @return string
	 */
	public static function use_name( array $use, $key = 'name' ) {
		if ( 1 === count( $use ) ) {
			return (string) $use[0][ $key ];
		}
		$parts = array();
		foreach ( $use as $bag ) {
			$parts[] = ( (int) $bag['count'] > 1 ? (int) $bag['count'] . ' × ' : '' ) . (string) $bag[ $key ];
		}
		return implode( ' + ', $parts );
	}

	/**
	 * What fishotel_packing_suggest() says about the upsizing: from
	 * (suggestion | confirmed | ''), boxes (each: key, name, sizes, bags =
	 * the bags to use, fill_min / fill_min_pct with the minimum bags, fill /
	 * fill_pct with the bags to use, over, moved, tie_off, missing), moved,
	 * over, chosen.
	 *
	 * @param array $packed Plan::build().
	 * @return array
	 */
	public static function packed_summary( array $packed ) {
		unset( $packed['lines'] );
		return $packed;
	}

	/**
	 * The bag to use for one order line, for fishotel_packing_bag_for_item():
	 * null when its order has no plan for it (the caller then keeps the
	 * minimum). The order's plan is worked out once per request (forget()
	 * drops it); an order that went into a combined box is read through
	 * the combined box, which holds its fish.
	 *
	 * @param object $item WC_Order_Item_Product.
	 * @param array  $min  Its minimum bag (Bags::describe()).
	 * @return array|null Bags::describe() of the bag to use, plus min,
	 *                    upsized, chosen_by_hand, mixed and bags (each: id,
	 *                    name, label, count).
	 */
	public static function bag_to_use( $item, array $min ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_order_id' ) || ! method_exists( $item, 'get_id' ) ) {
			return null;
		}
		$order_id = (int) $item->get_order_id();
		$item_id  = (int) $item->get_id();
		$order    = $order_id > 0 && $item_id > 0 ? self::load( $order_id ) : null;
		if ( null === $order ) {
			return null;
		}

		$owner   = $order;
		$wrapper = Lines::packed_with( $order );
		if ( $wrapper > 0 ) {
			$combined = self::load( $wrapper );
			if ( null !== $combined ) {
				$owner = $combined;
			}
		}

		$owner_id = method_exists( $owner, 'get_id' ) ? (int) $owner->get_id() : 0;
		if ( ! isset( self::$plans[ $owner_id ] ) ) {
			self::$plans[ $owner_id ] = self::for_order( $owner );
		}
		$result = self::$plans[ $owner_id ];

		$counts = array();
		$moved  = false;
		$chosen = 0;
		foreach ( $result['lines'] as $line ) {
			if ( (int) $line['order_id'] !== $order_id || (int) $line['item_id'] !== $item_id || empty( $line['use'] ) ) {
				continue;
			}
			foreach ( $line['use'] as $bag ) {
				$counts[ $bag['id'] ] = ( $counts[ $bag['id'] ] ?? 0 ) + $bag['count'];
			}
			$moved  = $moved || ! empty( $line['upsized'] );
			$chosen = $chosen > 0 ? $chosen : (int) $line['chosen'];
		}
		if ( ! $counts ) {
			return null;
		}

		$settings = Settings::get();
		$use      = array();
		$first    = null;
		foreach ( array_reverse( $settings['bags'] ) as $bag ) {
			$id = (int) $bag['id'];
			if ( ! empty( $counts[ $id ] ) ) {
				$first = null === $first ? $bag : $first;
				$use[] = array(
					'id'    => $id,
					'name'  => (string) $bag['name'],
					'label' => Bags::label( $bag ),
					'count' => (int) $counts[ $id ],
				);
			}
		}
		if ( null === $first ) {
			return null;
		}

		if ( $chosen > 0 ) {
			$source = 'hand';
		} elseif ( $moved ) {
			$source = 'upsized';
		} else {
			$source = $min['source'];
		}

		return array(
			'id'             => (int) $first['id'],
			'name'           => self::use_name( $use ),
			'width'          => (string) $first['width'],
			'length'         => (string) $first['length'],
			'label'          => self::use_name( $use, 'label' ),
			'sort'           => (int) $first['sort'],
			'source'         => $source,
			'size'           => $min['size'],
			'size_label'     => $min['size_label'],
			'min'            => $min,
			'upsized'        => $moved,
			'chosen_by_hand' => $chosen > 0,
			'mixed'          => count( $use ) > 1,
			'bags'           => $use,
		);
	}

	/**
	 * Drop the plans worked out for bag_to_use() in this request.
	 */
	public static function forget() {
		self::$plans = array();
	}

	/**
	 * Choose a line's bag by hand, or put it back on Auto — op "bag" of
	 * confirm_request() (the capability and the nonce are checked there).
	 * `line` is the line's key, `bag` a bag id of the list or '' / "auto".
	 * Stored on the order as META_BAGS through the order object; nothing
	 * else changes.
	 *
	 * @param object $order WC_Order.
	 * @param array  $raw   line, bag.
	 * @return array See answer().
	 */
	private static function bag_request( $order, array $raw ) {
		$settings = Settings::get();
		$result   = self::for_order( $order, $settings );

		if ( $result['packed_with'] > 0 ) {
			/* translators: %d: order ID of the combined box */
			return self::answer( 409, sprintf( __( 'This order is packed with combined box #%d — choose the bags there.', 'fishotel-misc-plugin' ), $result['packed_with'] ) );
		}

		$key  = isset( $raw['line'] ) && is_scalar( $raw['line'] ) && ! is_bool( $raw['line'] ) ? trim( (string) $raw['line'] ) : '';
		$line = null;
		foreach ( $result['lines'] as $one ) {
			if ( '' !== $key && $one['key'] === $key && ! empty( $one['bag'] ) ) {
				$line = $one;
				break;
			}
		}
		if ( null === $line ) {
			return self::answer( 400, __( 'That fish line is not on the order any more — reload the page.', 'fishotel-misc-plugin' ), $order );
		}

		if ( isset( $raw['bag'] ) && ( ! is_scalar( $raw['bag'] ) || is_bool( $raw['bag'] ) ) ) {
			return self::answer( 400, __( 'That bag is not in the list of bags any more — choose again.', 'fishotel-misc-plugin' ), $order );
		}
		$value = isset( $raw['bag'] ) ? trim( (string) $raw['bag'] ) : '';
		$bag   = null;
		if ( '' !== $value && 'auto' !== $value ) {
			$bag = 1 === preg_match( '/^\d{1,9}$/', $value ) ? Bags::find( $settings['bags'], (int) $value ) : null;
			if ( null === $bag ) {
				return self::answer( 400, __( 'That bag is not in the list of bags any more — choose again.', 'fishotel-misc-plugin' ), $order );
			}
		}

		$stored = self::stored_overrides( $order );
		if ( null === $bag ) {
			if ( ! isset( $stored[ $key ] ) ) {
				/* translators: %s: fish name */
				return self::answer( 200, sprintf( __( '%s was on Auto already.', 'fishotel-misc-plugin' ), $line['name'] ), $order );
			}
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = (int) $bag['id'];
			if ( count( $stored ) > self::MAX_OVERRIDES ) {
				return self::answer( 400, __( 'Too many bags chosen by hand on this order — put some back on Auto first.', 'fishotel-misc-plugin' ), $order );
			}
		}

		if ( $stored ) {
			$order->update_meta_data( Packing::META_BAGS, $stored );
		} else {
			$order->delete_meta_data( Packing::META_BAGS );
		}
		$order->save();
		self::forget();

		if ( null === $bag ) {
			/* translators: %s: fish name */
			return self::answer( 200, sprintf( __( '%s is back on Auto.', 'fishotel-misc-plugin' ), $line['name'] ), $order );
		}
		/* translators: 1: fish name, 2: bag name */
		return self::answer( 200, sprintf( __( '%1$s goes in %2$s — chosen by hand.', 'fishotel-misc-plugin' ), $line['name'], $bag['name'] ), $order );
	}

	/* ============================================================== */
	/*  The confirmed box                                              */
	/* ============================================================== */

	/**
	 * A list of boxes as plain text: "Medium box", "2 × Large box + Small
	 * box" (pure).
	 *
	 * @param array[] $boxes Boxes (name).
	 * @return string
	 */
	public static function boxes_name( array $boxes ) {
		$counts = array();
		foreach ( $boxes as $box ) {
			$name = (string) ( $box['name'] ?? '' );
			if ( '' !== $name ) {
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
		}
		$parts = array();
		foreach ( $counts as $name => $count ) {
			$parts[] = $count > 1 ? $count . ' × ' . $name : (string) $name;
		}
		return implode( ' + ', $parts );
	}

	/**
	 * A suggestion as the screen showed it, in one line (pure): each box
	 * with its fill — "Medium box 83.3%", "Large box 100% + Small box 40%".
	 * The Confirm button posts it back, so the server can tell whether the
	 * suggestion it is about to store is the one that was looked at.
	 *
	 * @param array[] $boxes The suggested boxes (name, fill_pct).
	 * @return string
	 */
	public static function shown_token( array $boxes ) {
		$parts = array();
		foreach ( $boxes as $box ) {
			$parts[] = (string) ( $box['name'] ?? '' ) . ' ' . Boxes::num_text( (float) ( $box['fill_pct'] ?? 0 ), 1 ) . '%';
		}
		return implode( ' + ', $parts );
	}

	/**
	 * The record that is stored on the order (pure).
	 *
	 * @param array[] $boxes     The chosen boxes (name, length, width, height).
	 * @param float[] $fills     Their fills as they stood (may be empty).
	 * @param bool    $suggested Whether the choice was the suggestion.
	 * @param bool    $complete  Whether every fish line had a bag size.
	 * @param int     $user_id   Who confirmed.
	 * @param string  $at        When, UTC (Y-m-d H:i:s).
	 * @return array|null Null when no box is usable.
	 */
	public static function record( array $boxes, array $fills, $suggested, $complete, $user_id, $at ) {
		$clean = array();
		foreach ( $boxes as $box ) {
			$row = Boxes::row( $box, 'confirmed' );
			if ( null !== $row ) {
				$clean[] = array(
					'name'   => $row['name'],
					'length' => Boxes::num_text( $row['length'] ),
					'width'  => Boxes::num_text( $row['width'] ),
					'height' => Boxes::num_text( $row['height'] ),
				);
			}
		}
		if ( ! $clean ) {
			return null;
		}

		$fill = array();
		foreach ( array_values( $fills ) as $value ) {
			if ( is_numeric( $value ) ) {
				$fill[] = round( (float) $value, 4 );
			}
		}

		return array(
			'v'         => 1,
			'boxes'     => $clean,
			'fill'      => count( $fill ) === count( $clean ) ? $fill : array(),
			'suggested' => (bool) $suggested,
			'complete'  => (bool) $complete,
			'by'        => max( 0, (int) $user_id ),
			'at'        => is_string( $at ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at ) ? $at : '',
		);
	}

	/**
	 * A stored record as the contract hands it out (pure apart from the
	 * user's name): null when the meta is empty or not a record.
	 *
	 * @param mixed $raw      The `_fh_packing_box` meta (the record, or its JSON).
	 * @param int   $order_id Order ID.
	 * @return array|null See fishotel_packing_confirmed_box().
	 */
	public static function read_record( $raw, $order_id = 0 ) {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || empty( $raw['boxes'] ) || ! is_array( $raw['boxes'] ) ) {
			return null;
		}

		$boxes = array();
		foreach ( $raw['boxes'] as $box ) {
			$row = Boxes::row( $box, 'confirmed' );
			if ( null !== $row ) {
				unset( $row['key'], $row['source'] );
				$boxes[] = $row;
			}
			if ( count( $boxes ) >= Suggest::MAX_BOXES ) {
				break;
			}
		}
		if ( ! $boxes ) {
			return null;
		}

		$fill = array();
		foreach ( isset( $raw['fill'] ) && is_array( $raw['fill'] ) ? array_values( $raw['fill'] ) : array() as $value ) {
			if ( is_numeric( $value ) ) {
				$fill[] = (float) $value;
			}
		}

		$by      = isset( $raw['by'] ) && is_numeric( $raw['by'] ) ? max( 0, (int) $raw['by'] ) : 0;
		$by_name = '';
		if ( $by > 0 && function_exists( 'get_userdata' ) ) {
			$user    = get_userdata( $by );
			$by_name = is_object( $user ) && isset( $user->display_name ) ? (string) $user->display_name : '';
		}

		return array(
			'order_id'  => (int) $order_id,
			'boxes'     => $boxes,
			'count'     => count( $boxes ),
			'name'      => self::boxes_name( $boxes ),
			'length'    => $boxes[0]['length'],
			'width'     => $boxes[0]['width'],
			'height'    => $boxes[0]['height'],
			'dims'      => $boxes[0]['dims'],
			'fill'      => count( $fill ) === count( $boxes ) ? $fill : array(),
			'suggested' => ! empty( $raw['suggested'] ),
			'complete'  => ! empty( $raw['complete'] ),
			'by'        => $by,
			'by_name'   => $by_name,
			'at'        => isset( $raw['at'] ) && is_string( $raw['at'] ) ? $raw['at'] : '',
		);
	}

	/**
	 * The box confirmed on an order.
	 *
	 * @param object $order WC_Order.
	 * @return array|null
	 */
	public static function confirmed( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		return self::read_record( $order->get_meta( Packing::META_BOX, true ), method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 );
	}

	/**
	 * The box confirmed on an order, by id — fishotel_packing_confirmed_box().
	 *
	 * @param int $order_id Order ID.
	 * @return array|null
	 */
	public static function confirmed_for( $order_id ) {
		$order = self::load( $order_id );
		return null !== $order ? self::confirmed( $order ) : null;
	}

	/* ============================================================== */
	/*  Confirm / change / clear                                       */
	/* ============================================================== */

	/**
	 * The Packing box's one write — the testable half of its AJAX handler.
	 * The capability (manage_woocommerce) and the nonce first; then `op`:
	 *
	 *   view     nothing is written (the box is drawn again);
	 *   confirm  `choice` = "suggested" (the suggestion as it stands now)
	 *            or the key of a box of the list, with `count` (1 unless
	 *            given) — stored on the order. With "suggested" the screen
	 *            says what it showed (`shown`, shown_token()): when the
	 *            suggestion is another one by now — the order, a bag size
	 *            or the numbers changed since the box was drawn — nothing
	 *            is stored and the answer is 409 with the order, so the box
	 *            is drawn again and the new suggestion is seen before it is
	 *            confirmed;
	 *   clear    the confirmed box is removed.
	 *
	 * An order that went into a combined box is confirmed there, not here.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, order_id, op, choice, count, shown.
	 * @return array{status: int, ok: bool, message: string, order?: object}
	 */
	public static function confirm_request( array $raw ) {
		$denied = Settings::guard( $raw, self::NONCE_ACTION );
		if ( $denied ) {
			return $denied;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::answer( 503, __( 'WooCommerce is not active: there are no orders.', 'fishotel-misc-plugin' ) );
		}

		$order_id = isset( $raw['order_id'] ) && is_scalar( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0;
		$order    = self::load( $order_id );
		if ( null === $order || ! method_exists( $order, 'update_meta_data' ) ) {
			return self::answer( 404, __( 'Order not found.', 'fishotel-misc-plugin' ) );
		}

		$op = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';

		if ( 'view' === $op ) {
			return self::answer( 200, '', $order );
		}

		if ( 'clear' === $op ) {
			if ( null === self::confirmed( $order ) && '' === Lines::scalar( $order->get_meta( Packing::META_BOX_NAME, true ) ) ) {
				return self::answer( 200, __( 'There was no confirmed box.', 'fishotel-misc-plugin' ), $order );
			}
			$order->delete_meta_data( Packing::META_BOX );
			$order->delete_meta_data( Packing::META_BOX_NAME );
			$order->save();
			self::forget();
			do_action( 'fishotel_packing_box_cleared', $order_id );

			return self::answer( 200, __( 'Confirmed box cleared.', 'fishotel-misc-plugin' ), $order );
		}

		if ( 'bag' === $op ) {
			return self::bag_request( $order, $raw );
		}

		if ( 'confirm' !== $op ) {
			return self::answer( 400, __( 'Unknown action.', 'fishotel-misc-plugin' ) );
		}

		$settings = Settings::get();
		$current  = Boxes::current( $settings );
		$result   = self::for_order( $order, $settings, null, $current );

		if ( $result['packed_with'] > 0 ) {
			/* translators: %d: order ID of the combined box */
			return self::answer( 409, sprintf( __( 'This order is packed with combined box #%d — confirm the box there.', 'fishotel-misc-plugin' ), $result['packed_with'] ) );
		}

		$choice = isset( $raw['choice'] ) && is_scalar( $raw['choice'] ) && ! is_bool( $raw['choice'] ) ? trim( (string) $raw['choice'] ) : '';

		if ( '' === $choice || 'suggested' === $choice ) {
			$usable = in_array( $result['status'], array( Suggest::STATUS_FITS, Suggest::STATUS_SPLIT ), true ) && $result['boxes'] && ! $result['unplaced'];
			if ( ! $usable ) {
				return self::answer( 409, __( 'There is no suggestion to confirm right now — choose a box from the list.', 'fishotel-misc-plugin' ), $order );
			}
			if ( isset( $raw['shown'] ) && ( ! is_scalar( $raw['shown'] ) || self::shown_token( $result['boxes'] ) !== trim( (string) $raw['shown'] ) ) ) {
				/* translators: %s: the suggested box or boxes, with their fill */
				return self::answer( 409, sprintf( __( 'Nothing was confirmed: the suggestion changed since this box was drawn — it is now %s. Look at it, then confirm.', 'fishotel-misc-plugin' ), self::shown_token( $result['boxes'] ) ), $order );
			}
			$boxes     = $result['boxes'];
			$fills     = array();
			$suggested = true;
			foreach ( $boxes as $box ) {
				$fills[] = $box['fill'];
			}
		} else {
			$box = Boxes::find( $current['boxes'], $choice );
			if ( null === $box ) {
				return self::answer( 400, __( 'That box is not in the list any more — choose again.', 'fishotel-misc-plugin' ), $order );
			}
			$count = 1;
			if ( isset( $raw['count'] ) && ! Settings::is_blank( $raw['count'] ) ) {
				$text = is_scalar( $raw['count'] ) ? trim( (string) $raw['count'] ) : 'x';
				if ( 1 !== preg_match( '/^\d{1,3}$/', $text ) || (int) $text < 1 || (int) $text > self::MAX_COUNT ) {
					/* translators: %d: the most boxes allowed */
					return self::answer( 400, sprintf( __( 'How many boxes is a whole number from 1 to %d.', 'fishotel-misc-plugin' ), self::MAX_COUNT ) );
				}
				$count = (int) $text;
			}

			$needs = array();
			foreach ( $result['bags'] as $bag ) {
				$needs[ (int) $bag['id'] ] = (int) $bag['count'];
			}
			$with  = Boxes::fitted( array( $box ), $settings );
			$fill  = Suggest::fill_of( $needs, $with[0] );
			$boxes = array_fill( 0, $count, $box );
			$fills = null === $fill || ! $needs ? array() : array_fill( 0, $count, $fill / $count );

			$suggested = 1 === $count && Suggest::STATUS_FITS === $result['status'] && isset( $result['boxes'][0]['key'] ) && $result['boxes'][0]['key'] === $box['key'];
		}

		$record = self::record( $boxes, $fills, $suggested, $result['complete'], get_current_user_id(), gmdate( 'Y-m-d H:i:s' ) );
		if ( null === $record ) {
			return self::answer( 400, __( 'That box could not be stored.', 'fishotel-misc-plugin' ) );
		}

		$name = self::boxes_name( $record['boxes'] );
		$order->update_meta_data( Packing::META_BOX, $record );
		$order->update_meta_data( Packing::META_BOX_NAME, $name );
		$order->save();
		self::forget();

		do_action( 'fishotel_packing_box_confirmed', $order_id, self::read_record( $record, $order_id ) );

		/* translators: %s: the confirmed box or boxes */
		return self::answer( 200, sprintf( __( 'Packing box confirmed: %s.', 'fishotel-misc-plugin' ), $name ), $order );
	}

	/**
	 * An answer of confirm_request().
	 *
	 * @param int         $status  HTTP status.
	 * @param string      $message Message.
	 * @param object|null $order   The order, when there is one to draw.
	 * @return array{status: int, ok: bool, message: string, order?: object}
	 */
	private static function answer( $status, $message, $order = null ) {
		$out = array(
			'status'  => (int) $status,
			'ok'      => 200 === (int) $status,
			'message' => (string) $message,
		);
		if ( null !== $order ) {
			$out['order'] = $order;
		}
		return $out;
	}
}
