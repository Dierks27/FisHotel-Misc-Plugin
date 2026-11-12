<?php
/**
 * Planner — the one path a plan is made through, whoever asks (the admin
 * page, the REST route): the settings and the box list as they are now,
 * the stock as it is now, Plan::build(), the L-numbers reserved, the
 * draft saved. Nothing in the stock changes here — only "Mark as cut"
 * (Stock::apply) does that.
 *
 * Also what a plan looks like to the outside (export(): the stored data
 * plus its id, status, whether the stock still matches, and the print
 * URL) and the key that opens the printable page.
 *
 * The print URL carries a nonce made the way WordPress makes one (a hash
 * of a 12-hour tick, the action, the plan and the user, under the nonce
 * salt) but without the browser session in it — on purpose: a URL handed
 * out over REST to an application password has no session, and must
 * still open in that user's logged-in browser. The page itself always
 * asks for a logged-in user with manage_woocommerce as well.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Planner
 */
class Planner {

	/**
	 * The admin-post action of the printable page.
	 *
	 * @var string
	 */
	const PRINT_ACTION = 'fishotel_foam_cuts_print';

	/**
	 * What a plan is made from right now: the settings, the box list (with
	 * the cut list each box would use), where the list comes from and what
	 * was not available.
	 *
	 * @return array{settings: array, source: string, notes: string[], boxes: array[]}
	 */
	public static function context() {
		$settings = Settings::get();
		$list     = Boxes::all( $settings );

		return array(
			'settings' => $settings,
			'source'   => $list['source'],
			'notes'    => $list['notes'],
			'boxes'    => Boxes::with_cut_lists( $list['boxes'], $settings ),
		);
	}

	/**
	 * Make a plan and save it as a draft.
	 *
	 * @param object $store   Store.
	 * @param array  $counts  Box key → how many to cut for.
	 * @param array  $for     What it is for: origin ('typed' | 'orders'), and for
	 *                        orders: ship_date, delivery_date, orders[], extra[]
	 *                        (boxes that are not on the list: key, name, length,
	 *                        width, height in units, count).
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string, id: int}
	 */
	public static function create( $store, array $counts, array $for = array(), $user_id = 0 ) {
		$context = self::context();
		$boxes   = array();

		foreach ( $context['boxes'] as $box ) {
			$count = isset( $counts[ $box['key'] ] ) && is_numeric( $counts[ $box['key'] ] ) ? (int) $counts[ $box['key'] ] : 0;
			if ( $count > 0 ) {
				$box['count'] = min( Plan::MAX_BOX_COUNT, $count );
				$boxes[]      = $box;
			}
		}

		// Boxes an order named that are not on the list: known by their size, cut by the suggestion.
		$extra = isset( $for['extra'] ) && is_array( $for['extra'] ) ? $for['extra'] : array();
		foreach ( Boxes::with_cut_lists( Boxes::clean( $extra ), $context['settings'] ) as $box ) {
			foreach ( $extra as $row ) {
				if ( is_array( $row ) && isset( $row['name'], $row['count'] ) && Boxes::key( (string) $row['name'] ) === $box['key'] && (int) $row['count'] > 0 ) {
					$box['count'] = min( Plan::MAX_BOX_COUNT, (int) $row['count'] );
					$boxes[]      = $box;
					break;
				}
			}
		}

		$plan_settings = Settings::for_plan( $context['settings'] );
		$stock         = Stock::snapshot( $store );
		$from          = (int) $store->next_label();
		$data          = Plan::build( $boxes, $stock, $plan_settings, $from );

		if ( ! empty( $data['errors'] ) ) {
			return array(
				'ok'      => false,
				'message' => implode( ' ', $data['errors'] ),
				'id'      => 0,
			);
		}

		$count    = (int) $data['labels']['count'];
		$reserved = (int) $store->reserve_labels( $count );
		if ( $reserved !== $from ) {
			// Someone took numbers in between: the same layout, the labels moved up.
			$data = Plan::build( $boxes, $stock, $plan_settings, $reserved );
		}

		$origin = isset( $for['origin'] ) && 'orders' === $for['origin'] ? 'orders' : 'typed';
		if ( 'orders' === $origin ) {
			$data['for'] = array(
				'origin'        => 'orders',
				'ship_date'     => isset( $for['ship_date'] ) ? (string) $for['ship_date'] : '',
				'delivery_date' => isset( $for['delivery_date'] ) ? (string) $for['delivery_date'] : '',
				'orders'        => self::order_lines( isset( $for['orders'] ) && is_array( $for['orders'] ) ? $for['orders'] : array() ),
			);
		} else {
			$data['for'] = array( 'origin' => 'typed' );
		}

		$id = $store->add_plan(
			array(
				'title'       => Plan::title( $data ),
				'origin'      => $origin,
				'ship_date'   => 'orders' === $origin && isset( $for['ship_date'] ) ? (string) $for['ship_date'] : '',
				'sheets'      => Plan::sheets( $data ),
				'label_from'  => $reserved,
				'label_count' => $count,
				'data'        => $data,
				'created_by'  => (int) $user_id,
			)
		);

		if ( ! $id ) {
			$store->release_labels( $reserved, $count );
			return array(
				'ok'      => false,
				'message' => __( 'The plan could not be saved. Nothing was changed.', 'fishotel-misc-plugin' ),
				'id'      => 0,
			);
		}

		self::announce( 'fishotel_foam_cuts_plan_created', (int) $id, $data );

		return array(
			'ok'      => true,
			/* translators: %d: plan number */
			'message' => sprintf( __( 'Plan %d is ready. Print it, cut, then press "Mark as cut".', 'fishotel-misc-plugin' ), (int) $id ),
			'id'      => (int) $id,
		);
	}

	/**
	 * MARK AS CUT (Stock::apply), and tell whoever listens:
	 * do_action( 'fishotel_foam_cuts_plan_cut', $plan_id, $plan_data ).
	 *
	 * @param object $store   Store.
	 * @param array  $plan    Plan row (with data).
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function cut( $store, array $plan, $user_id = 0 ) {
		$result = Stock::apply( $store, $plan, $user_id );
		if ( $result['ok'] ) {
			self::announce( 'fishotel_foam_cuts_plan_cut', (int) $plan['id'], isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array() );
		}
		return $result;
	}

	/**
	 * UNDO (Stock::undo), and tell whoever listens:
	 * do_action( 'fishotel_foam_cuts_plan_undone', $plan_id, $plan_data ).
	 *
	 * @param object $store   Store.
	 * @param array  $plan    Plan row (with data).
	 * @param int    $user_id Who.
	 * @return array{ok: bool, message: string}
	 */
	public static function undo( $store, array $plan, $user_id = 0 ) {
		$result = Stock::undo( $store, $plan, $user_id );
		if ( $result['ok'] ) {
			self::announce( 'fishotel_foam_cuts_plan_undone', (int) $plan['id'], isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array() );
		}
		return $result;
	}

	/**
	 * Fire an action; a listener that throws never undoes what happened.
	 *
	 * @param string $hook    Action name.
	 * @param int    $plan_id Plan id.
	 * @param array  $data    Plan data.
	 */
	private static function announce( $hook, $plan_id, array $data ) {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		try {
			do_action( $hook, (int) $plan_id, $data );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Make a plan for the orders that ship on a day.
	 *
	 * @param object $store    Store.
	 * @param string $ship_ymd Y-m-d.
	 * @param int    $user_id  Who.
	 * @return array{ok: bool, message: string, id: int, orders: array}
	 */
	public static function create_for_orders( $store, $ship_ymd, $user_id = 0 ) {
		$context = self::context();
		$orders  = Orders::for_ship_date( $ship_ymd, $context['boxes'] );

		if ( ! $orders['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $orders['message'],
				'id'      => 0,
				'orders'  => $orders,
			);
		}

		if ( ! $orders['counts'] && ! $orders['extra'] ) {
			return array(
				'ok'      => false,
				'message' => $orders['orders']
					/* translators: %s: date */
					? sprintf( __( 'None of the orders shipping on %s has a box yet, so there is nothing to cut for.', 'fishotel-misc-plugin' ), $orders['ship_date'] )
					/* translators: 1: ship date, 2: delivery date */
					: sprintf( __( 'No open order ships on %1$s (delivery %2$s).', 'fishotel-misc-plugin' ), $orders['ship_date'], $orders['delivery_date'] ),
				'id'      => 0,
				'orders'  => $orders,
			);
		}

		$made = self::create(
			$store,
			$orders['counts'],
			array(
				'origin'        => 'orders',
				'ship_date'     => $orders['ship_date'],
				'delivery_date' => $orders['delivery_date'],
				'orders'        => $orders['orders'],
				'extra'         => $orders['extra'],
			),
			$user_id
		);

		$made['orders'] = $orders;
		return $made;
	}

	/**
	 * The orders a plan was made for, as it remembers them: the order's id
	 * and number and its boxes — nothing about the customer.
	 *
	 * @param array $orders Orders::for_ship_date()['orders'].
	 * @return array<int, array{id: int, number: string, how: string, boxes: string[]}>
	 */
	public static function order_lines( array $orders ) {
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) || empty( $order['id'] ) ) {
				continue;
			}
			$boxes = array();
			foreach ( isset( $order['boxes'] ) ? (array) $order['boxes'] : array() as $box ) {
				$boxes[] = (int) $box['count'] . ' × ' . (string) $box['name'];
			}
			$out[] = array(
				'id'     => (int) $order['id'],
				'number' => isset( $order['number'] ) ? (string) $order['number'] : (string) $order['id'],
				'how'    => isset( $order['how'] ) ? (string) $order['how'] : '',
				'boxes'  => $boxes,
			);
		}
		return $out;
	}

	/**
	 * Delete a draft (its unused L-numbers are given back when they were
	 * the last ones handed out).
	 *
	 * @param object $store Store.
	 * @param array  $plan  Plan row.
	 * @return array{ok: bool, message: string}
	 */
	public static function delete( $store, array $plan ) {
		if ( 'draft' !== $plan['status'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'A plan that is marked as cut is kept — undo it first if it was a mistake.', 'fishotel-misc-plugin' ),
			);
		}

		if ( ! $store->delete_plan( (int) $plan['id'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plan could not be deleted.', 'fishotel-misc-plugin' ),
			);
		}

		$store->release_labels( (int) $plan['label_from'], (int) $plan['label_count'] );

		return array(
			'ok'      => true,
			/* translators: %d: plan number */
			'message' => sprintf( __( 'Plan %d deleted. The stock was not touched.', 'fishotel-misc-plugin' ), (int) $plan['id'] ),
		);
	}

	/**
	 * A plan as other code gets it (the REST answer): the stored data plus
	 * its id, its status, whether the stock still matches, and the print URL.
	 *
	 * @param array  $plan  Plan row (with data).
	 * @param object $store Store.
	 * @return array
	 */
	public static function export( array $plan, $store ) {
		$state = Stock::state( $store, $plan );
		$data  = isset( $plan['data'] ) && is_array( $plan['data'] ) ? $plan['data'] : array();

		return array_merge(
			array(
				'id'            => (int) $plan['id'],
				'status'        => (string) $plan['status'],
				'title'         => (string) $plan['title'],
				'origin'        => (string) $plan['origin'],
				'ship_date'     => '' !== (string) $plan['ship_date'] ? (string) $plan['ship_date'] : null,
				'created_at'    => self::iso( $plan['created_at'] ),
				'cut_at'        => 'cut' === $plan['status'] ? self::iso( $plan['cut_at'] ) : null,
				'stale'         => (bool) $state['stale'],
				'stale_reasons' => $state['stale'],
				'can_undo'      => 'cut' === $plan['status'] && ! $state['undo_blockers'],
			),
			$data,
			array(
				'print_url' => self::print_url( (int) $plan['id'] ),
			)
		);
	}

	/**
	 * A stored UTC "Y-m-d H:i:s" as ISO 8601, or null.
	 *
	 * @param mixed $utc Stored time.
	 * @return string|null
	 */
	public static function iso( $utc ) {
		$utc = (string) $utc;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc ) || 0 === strpos( $utc, '0000' ) ) {
			return null;
		}
		return str_replace( ' ', 'T', $utc ) . 'Z';
	}

	/* ============================================================== */
	/*  The printable page's URL                                       */
	/* ============================================================== */

	/**
	 * The URL of the printable page of a plan, for the current user.
	 *
	 * @param int $plan_id Plan id.
	 * @return string
	 */
	public static function print_url( $plan_id ) {
		return add_query_arg(
			array(
				'action' => self::PRINT_ACTION,
				'plan'   => (int) $plan_id,
				'key'    => self::print_key( (int) $plan_id, (int) get_current_user_id() ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The 12-hour tick a key is made under.
	 *
	 * @return int
	 */
	private static function tick() {
		if ( function_exists( 'wp_nonce_tick' ) ) {
			return (int) wp_nonce_tick( self::PRINT_ACTION );
		}
		return (int) ceil( time() / 43200 );
	}

	/**
	 * The key that opens a plan's printable page for one user.
	 *
	 * @param int      $plan_id Plan id.
	 * @param int      $user_id User id.
	 * @param int|null $tick    The tick (null = now).
	 * @return string
	 */
	public static function print_key( $plan_id, $user_id, $tick = null ) {
		$tick = null === $tick ? self::tick() : (int) $tick;
		return substr( wp_hash( $tick . '|' . self::PRINT_ACTION . '|' . (int) $plan_id . '|' . (int) $user_id, 'nonce' ), -12, 10 );
	}

	/**
	 * Whether a key opens a plan's printable page for a user (made in
	 * this 12-hour tick or the one before).
	 *
	 * @param mixed $key     The key from the URL.
	 * @param int   $plan_id Plan id.
	 * @param int   $user_id User id.
	 * @return bool
	 */
	public static function verify_print_key( $key, $plan_id, $user_id ) {
		if ( ! is_string( $key ) || '' === $key || (int) $user_id < 1 || (int) $plan_id < 1 ) {
			return false;
		}

		$tick = self::tick();

		return hash_equals( self::print_key( $plan_id, $user_id, $tick ), $key )
			|| hash_equals( self::print_key( $plan_id, $user_id, $tick - 1 ), $key );
	}
}
