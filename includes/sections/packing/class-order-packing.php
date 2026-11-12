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

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return Packing::unavailable( $order_id, 'order_not_found' );
		}

		return self::for_order( $order );
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

		$boxes = Boxes::with_fits( $current['boxes'], $settings['fits'] );
		$order_of_bags = array();
		foreach ( $settings['bags'] as $bag ) {
			$order_of_bags[] = (int) $bag['id'];
		}
		$plan = Suggest::suggest( $needs, $boxes, $settings['threshold'], $order_of_bags );

		$bags     = array();
		$unplaced = array();
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
			'unplaced'       => $unplaced,
			'lines'          => $gathered['lines'],
			'incomplete'     => $incomplete,
			'complete'       => ! $incomplete,
			'ignored'        => $gathered['ignored'],
			'skipped'        => $gathered['skipped'],
			'box_source'     => $current['source'],
			'is_fulfillment' => $gathered['is_fulfillment'],
			'sources'        => $gathered['sources'],
			'packed_with'    => $gathered['packed_with'],
			'confirmed'      => self::confirmed( $order ),
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
			/* translators: %s: bags, as "2 × Name" separated by commas */
			$out[] = sprintf( __( 'No box takes: %s — type how many fit under FisHotel Tools → Packing → Boxes.', 'fishotel-misc-plugin' ), implode( ', ', $names ) );
		}

		foreach ( $result['boxes'] as $box ) {
			if ( $box['on_the_line'] ) {
				/* translators: 1: box name, 2: percent full, 3: threshold percent */
				$out[] = sprintf( __( 'On the line: %1$s is %2$s%% full (%3$s%% or more) — confirm it.', 'fishotel-misc-plugin' ), $box['name'], Boxes::num_text( $box['fill_pct'], 1 ), Boxes::num_text( $result['threshold'], 1 ) );
			}
		}

		return $out;
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
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( (int) $order_id );
		return is_object( $order ) ? self::confirmed( $order ) : null;
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
	 *            given) — stored on the order;
	 *   clear    the confirmed box is removed.
	 *
	 * An order that went into a combined box is confirmed there, not here.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, order_id, op, choice, count.
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
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'update_meta_data' ) ) {
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
			do_action( 'fishotel_packing_box_cleared', $order_id );

			return self::answer( 200, __( 'Confirmed box cleared.', 'fishotel-misc-plugin' ), $order );
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
				return self::answer( 409, __( 'There is no suggestion to confirm right now — choose a box from the list.', 'fishotel-misc-plugin' ) );
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
				return self::answer( 400, __( 'That box is not in the list any more. Reload the page and choose again.', 'fishotel-misc-plugin' ) );
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
			$with  = Boxes::with_fits( array( $box ), $settings['fits'] );
			$fill  = Suggest::fill( $needs, $with[0]['fits'] );
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
