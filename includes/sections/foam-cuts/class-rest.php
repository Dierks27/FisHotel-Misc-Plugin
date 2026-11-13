<?php
/**
 * Foam Cuts REST routes — for the assistant that prints the day's order
 * paperwork, so the cut sheet can be part of the same request.
 *
 *   GET  /fishotel-misc/v1/foam-cuts/plan/<id>     one plan as JSON plus a
 *        print_url (<id> may also be "latest": the newest plan)
 *   GET  /fishotel-misc/v1/foam-cuts/plans         the latest plans (id,
 *        status, title, sheets, dates, print_url) — to find an id
 *   POST /fishotel-misc/v1/foam-cuts/plan          make a draft plan:
 *        { "source": "orders", "ship_date": "YYYY-MM-DD" } (the date is
 *        optional: today) or { "boxes": { "<box name>": <how many>, … } }
 *        → 201 with the plan, as GET gives it. The orders of a day asked
 *        for again while their draft (same boxes) is still there → 200
 *        with THAT plan and "reused": true — never a second plan for the
 *        same foam. More than 500 of one box → 400.
 *
 * Every route asks for manage_woocommerce. They work with the cookie +
 * X-WP-Nonce of wp-admin and with WordPress Application Passwords (HTTP
 * Basic over HTTPS): nothing here hooks authentication. Making a plan
 * never touches the stock; "Mark as cut" has no route on purpose — the
 * person who cut the foam presses it.
 *
 * The print_url is for the user the request was made as: it opens in that
 * user's logged-in browser (see Planner for why its nonce carries no
 * session).
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 */

namespace FisHotel\Misc\Sections\Foam_Cuts;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest
 */
class Rest {

	/**
	 * The store (a test may set its own).
	 *
	 * @var object|null
	 */
	public $store = null;

	/**
	 * Register the routes.
	 */
	public function register() {
		register_rest_route(
			Foam_Cuts::REST_NAMESPACE,
			Foam_Cuts::REST_BASE . '/plan/(?P<id>\d+|latest)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_plan' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'description' => 'Plan id, or "latest" for the newest plan.',
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			Foam_Cuts::REST_NAMESPACE,
			Foam_Cuts::REST_BASE . '/plans',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_plans' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			Foam_Cuts::REST_NAMESPACE,
			Foam_Cuts::REST_BASE . '/plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_plan' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'source'    => array(
						'description' => '"orders" (the orders that ship on ship_date) or "boxes" (the numbers in boxes). Default: "boxes" when boxes is given, else "orders".',
						'type'        => 'string',
						'required'    => false,
					),
					'ship_date' => array(
						'description' => 'For source "orders": the day the boxes are packed, YYYY-MM-DD. Default: today (site time).',
						'type'        => 'string',
						'required'    => false,
					),
					'boxes'     => array(
						'description' => 'For source "boxes": { "<box name>": <how many> }.',
						'required'    => false,
					),
				),
			)
		);
	}

	/**
	 * Who may use the routes.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		if ( current_user_can( Foam_Cuts::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to read the foam cut plans.', 'fishotel-misc-plugin' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * The store.
	 *
	 * @return object
	 */
	private function store() {
		return $this->store ? $this->store : new Store();
	}

	/**
	 * GET plan/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_plan( $request ) {
		$store = $this->store();
		$id    = (string) $request['id'];
		$plan  = 'latest' === $id ? $store->latest_plan() : $store->plan( (int) $id );

		if ( ! $plan ) {
			return new \WP_Error(
				'fishotel_foam_cuts_not_found',
				'latest' === $id ? __( 'There is no foam cut plan yet.', 'fishotel-misc-plugin' ) : __( 'There is no foam cut plan with that number.', 'fishotel-misc-plugin' ),
				array( 'status' => 404 )
			);
		}

		return new \WP_REST_Response( Planner::export( $plan, $store ), 200 );
	}

	/**
	 * GET plans.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_plans( $request ) {
		$out = array();

		foreach ( $this->store()->plans( 20 ) as $plan ) {
			$out[] = array(
				'id'         => (int) $plan['id'],
				'status'     => (string) $plan['status'],
				'title'      => (string) $plan['title'],
				'origin'     => (string) $plan['origin'],
				'ship_date'  => '' !== (string) $plan['ship_date'] ? (string) $plan['ship_date'] : null,
				'sheets'     => (int) $plan['sheets'],
				'created_at' => Planner::iso( $plan['created_at'] ),
				'cut_at'     => 'cut' === $plan['status'] ? Planner::iso( $plan['cut_at'] ) : null,
				'print_url'  => Planner::print_url( (int) $plan['id'] ),
			);
		}

		return new \WP_REST_Response( array( 'plans' => $out ), 200 );
	}

	/**
	 * POST plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_plan( $request ) {
		$store  = $this->store();
		$boxes  = $request['boxes'];
		$source = is_string( $request['source'] ) ? sanitize_key( $request['source'] ) : ( is_array( $boxes ) && $boxes ? 'boxes' : 'orders' );

		if ( ! Store::installed() && ! $this->store ) {
			return new \WP_Error( 'fishotel_foam_cuts_not_ready', __( 'The Foam Cuts tables are not installed yet. Open FisHotel Tools → Foam Cuts once in wp-admin.', 'fishotel-misc-plugin' ), array( 'status' => 503 ) );
		}

		if ( 'orders' === $source ) {
			$ship = is_string( $request['ship_date'] ) && '' !== $request['ship_date'] ? $request['ship_date'] : Orders::today();
			if ( ! Orders::is_date( $ship ) ) {
				return new \WP_Error( 'fishotel_foam_cuts_bad_date', __( 'ship_date must be a date, YYYY-MM-DD.', 'fishotel-misc-plugin' ), array( 'status' => 400 ) );
			}

			$made = Planner::create_for_orders( $store, $ship, (int) get_current_user_id() );
		} elseif ( 'boxes' === $source ) {
			if ( ! is_array( $boxes ) || ! $boxes ) {
				return new \WP_Error( 'fishotel_foam_cuts_no_boxes', __( 'boxes must be an object of box name → how many.', 'fishotel-misc-plugin' ), array( 'status' => 400 ) );
			}

			$counts  = array();
			$unknown = array();
			$context = Planner::context();
			foreach ( $boxes as $name => $count ) {
				$box = Boxes::find( $context['boxes'], (string) $name );
				if ( null === $box ) {
					$unknown[] = Store::clip( (string) $name, 40 );
					continue;
				}
				$counts[ $box['key'] ] = is_numeric( $count ) ? (int) $count : 0;
				if ( $counts[ $box['key'] ] > Plan::MAX_BOX_COUNT ) {
					return new \WP_Error(
						'fishotel_foam_cuts_too_many',
						/* translators: 1: the most boxes of one kind in a plan, 2: box name */
						sprintf( __( 'A plan lays out at most %1$d of one box; more were asked for of: %2$s. Make the plan in parts.', 'fishotel-misc-plugin' ), Plan::MAX_BOX_COUNT, $box['name'] ),
						array(
							'status' => 400,
							'max'    => Plan::MAX_BOX_COUNT,
						)
					);
				}
			}

			if ( $unknown ) {
				return new \WP_Error(
					'fishotel_foam_cuts_unknown_box',
					/* translators: %s: box names */
					sprintf( __( 'No box on the list is called: %s.', 'fishotel-misc-plugin' ), implode( ', ', $unknown ) ),
					array(
						'status' => 400,
						'boxes'  => array_column( $context['boxes'], 'name' ),
					)
				);
			}

			$made = Planner::create( $store, $counts, array( 'origin' => 'typed' ), (int) get_current_user_id() );
		} else {
			return new \WP_Error( 'fishotel_foam_cuts_bad_source', __( 'source must be "orders" or "boxes".', 'fishotel-misc-plugin' ), array( 'status' => 400 ) );
		}

		if ( ! $made['ok'] ) {
			$data = array( 'status' => isset( $made['code'] ) && 'too_many' === $made['code'] ? 400 : 409 );
			if ( isset( $made['orders'] ) ) {
				$data['ship_date']     = $made['orders']['ship_date'];
				$data['delivery_date'] = $made['orders']['delivery_date'];
				$data['orders']        = Planner::order_lines( $made['orders']['orders'] );
				$data['without_box']   = $made['orders']['without_box'];
			}
			return new \WP_Error( 'fishotel_foam_cuts_no_plan', $made['message'], $data );
		}

		$plan = $store->plan( (int) $made['id'] );
		$out  = Planner::export( $plan, $store );

		// The same day asked for again: the draft that was already there, not a second plan.
		$out['reused'] = ! empty( $made['existing'] );

		if ( isset( $made['orders'] ) ) {
			$out['orders_without_box'] = $made['orders']['without_box'];
			$out['unknown_boxes']      = $made['orders']['unknown'];
		}

		return new \WP_REST_Response( $out, $out['reused'] ? 200 : 201 );
	}
}
