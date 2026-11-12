<?php
/**
 * Requests follow — the state of a request follows the fish by itself.
 *
 *   waiting       → on_order       a supplier order that is a draft or ordered
 *                                  has a line of that fish (and size, when both
 *                                  have one): the oldest request first, never
 *                                  more fish than the line orders
 *   on_order      → in_quarantine  that line was checked in to a tank
 *   in_quarantine → ready          that tank group's sale date has come (the
 *                                  Clean Room's own sale-ready state)
 *   ready         → offered        the person is told (Service::offer())
 *
 * and back again when the fish is not there after all: the order arrived
 * without it, the line was removed, fewer fish are alive than were asked
 * for (the oldest requests keep their place; a request the owner matched by
 * hand goes first; a request that was already told is never taken back).
 *
 * plan() is PURE: requests + a snapshot of the supplier orders in, a list
 * of steps out. run() reads the snapshot through the Clean Room adapter
 * and applies the steps through the Service, which logs each one.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Follow
 */
class Follow {

	/**
	 * The supplier order statuses a waiting request may be matched to.
	 *
	 * @var string[]
	 */
	const OPEN_ORDER_STATUSES = array( 'draft', 'ordered' );

	/* ============================================================== */
	/*  Matching                                                       */
	/* ============================================================== */

	/**
	 * Whether a request and a supplier order line name the same fish: the
	 * species-list id when both have one, else the product, else the name
	 * (compared without case) — and the size, when both have one. A line
	 * whose fish still has to be identified never matches.
	 *
	 * @param array $request species_id, product_id, fish, size_slug.
	 * @param array $line    species_id, species_product_id, species, size_slug, needs_id.
	 * @return bool
	 */
	public static function same_fish( array $request, array $line ) {
		if ( ! empty( $line['needs_id'] ) ) {
			return false;
		}

		$request_species = (int) ( $request['species_id'] ?? 0 );
		$line_species    = (int) ( $line['species_id'] ?? 0 );
		$request_product = (int) ( $request['product_id'] ?? 0 );
		$line_product    = (int) ( $line['species_product_id'] ?? 0 );

		if ( $request_species > 0 && $line_species > 0 ) {
			$same = $request_species === $line_species;
		} elseif ( $request_product > 0 && $line_product > 0 && $request_product === $line_product ) {
			$same = true;
		} else {
			$name = Rules::name_key( (string) ( $request['fish'] ?? '' ) );
			$same = '' !== $name && Rules::name_key( (string) ( $line['species'] ?? '' ) ) === $name;
		}
		if ( ! $same ) {
			return false;
		}

		$request_size = (string) ( $request['size_slug'] ?? '' );
		$line_size    = (string) ( $line['size_slug'] ?? '' );

		return '' === $request_size || '' === $line_size || $request_size === $line_size;
	}

	/**
	 * Whether a line's fish merely resembles what was asked for (one name
	 * contains the other) — offered to the owner for a match by hand,
	 * never used by the job.
	 *
	 * @param array $request fish.
	 * @param array $line    species, needs_id.
	 * @return bool
	 */
	public static function similar_fish( array $request, array $line ) {
		$a = Rules::name_key( (string) ( $request['fish'] ?? '' ) );
		$b = Rules::name_key( (string) ( $line['species'] ?? '' ) );
		if ( '' === $a || '' === $b || ! empty( $line['needs_id'] ) ) {
			return false;
		}
		return false !== strpos( $a, $b ) || false !== strpos( $b, $a );
	}

	/**
	 * Where a line stands:
	 *   tank    it was checked in (it has tank groups, alive or not)
	 *   order   its order is a draft or ordered
	 *   missed  its order was received or closed without this line's fish
	 *
	 * @param array $order The line's order (status).
	 * @param array $line  The line (stay_id, stays).
	 * @return string
	 */
	public static function phase( array $order, array $line ) {
		if ( ! empty( $line['stays'] ) || ! empty( $line['stay_id'] ) ) {
			return 'tank';
		}
		return in_array( (string) ( $order['status'] ?? '' ), self::OPEN_ORDER_STATUSES, true ) ? 'order' : 'missed';
	}

	/**
	 * The live fish of a line's tank groups.
	 *
	 * @param array $line  The line (stays: id, qty_live, sale_ready, open).
	 * @param bool  $ready Only the groups whose sale date has come.
	 * @return int
	 */
	public static function live( array $line, $ready = false ) {
		$n = 0;
		foreach ( (array) ( $line['stays'] ?? array() ) as $stay ) {
			if ( empty( $stay['open'] ) || ( $ready && empty( $stay['sale_ready'] ) ) ) {
				continue;
			}
			$n += max( 0, (int) ( $stay['qty_live'] ?? 0 ) );
		}
		return $n;
	}

	/**
	 * The tank group a request of a line points at: one that is on sale
	 * (when asked), else one that still has fish, else the line's own.
	 *
	 * @param array $line  The line.
	 * @param bool  $ready Prefer a group whose sale date has come.
	 * @return array{stay_id: int|null, product_id: int|null}
	 */
	public static function stay_of( array $line, $ready ) {
		$pick = null;
		foreach ( (array) ( $line['stays'] ?? array() ) as $stay ) {
			if ( empty( $stay['open'] ) || (int) ( $stay['qty_live'] ?? 0 ) <= 0 ) {
				continue;
			}
			if ( $ready === ! empty( $stay['sale_ready'] ) ) {
				$pick = $stay;
				break;
			}
			if ( null === $pick ) {
				$pick = $stay;
			}
		}

		$product = $pick && ! empty( $pick['product_id'] ) ? (int) $pick['product_id'] : ( ! empty( $line['species_product_id'] ) ? (int) $line['species_product_id'] : null );

		return array(
			'stay_id'    => $pick ? (int) $pick['id'] : ( ! empty( $line['stay_id'] ) ? (int) $line['stay_id'] : null ),
			'product_id' => $product,
		);
	}

	/* ============================================================== */
	/*  The plan                                                       */
	/* ============================================================== */

	/**
	 * The steps that bring every request in line with the fish (pure).
	 *
	 * @param array[] $requests The requests in Rules::FOLLOW_STATES (id, state, fish, species_id,
	 *                          product_id, size_slug, qty, line_id, stay_id, link_manual, auto_match,
	 *                          created_at).
	 * @param array[] $orders   The supplier orders that matter: every draft and ordered one, and the
	 *                          order of every line a request is matched to — id, ref, status, date,
	 *                          lines[] (id, species_id, species, species_product_id, size_slug,
	 *                          qty_ordered, needs_id, stay_id, stays[] (id, qty_live, sale_ready, open,
	 *                          product_id, tank)).
	 * @return array[] id, to, line_id, order_id, stay_id, product_id, why, quiet (only the tank group
	 *                 changed) — only for requests that change.
	 */
	public static function plan( array $requests, array $orders ) {
		// Lines by id, each knowing its order.
		$lines       = array();
		$order_of    = array();
		$order_index = 0;
		usort(
			$orders,
			static function ( $a, $b ) {
				$by_date = strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) );
				return 0 !== $by_date ? $by_date : ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			}
		);
		foreach ( $orders as $order ) {
			$order_index++;
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				$line_id = (int) ( $line['id'] ?? 0 );
				if ( $line_id <= 0 ) {
					continue;
				}
				$line['_seq']         = $order_index * 100000 + count( $lines );
				$lines[ $line_id ]    = $line;
				$order_of[ $line_id ] = $order;
			}
		}

		// Requests by id, in the order they keep their place: told first,
		// then matched by hand, then the oldest.
		$by_id = array();
		foreach ( $requests as $request ) {
			if ( (int) ( $request['id'] ?? 0 ) > 0 && in_array( (string) ( $request['state'] ?? '' ), Rules::FOLLOW_STATES, true ) ) {
				$by_id[ (int) $request['id'] ] = $request;
			}
		}
		$rank = static function ( array $a, array $b ) {
			$told = ( 'offered' === $b['state'] ) <=> ( 'offered' === $a['state'] );
			if ( 0 !== $told ) {
				return $told;
			}
			$hand = ( ! empty( $b['link_manual'] ) ) <=> ( ! empty( $a['link_manual'] ) );
			if ( 0 !== $hand ) {
				return $hand;
			}
			$age = strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) );
			return 0 !== $age ? $age : ( (int) $a['id'] <=> (int) $b['id'] );
		};
		$oldest = static function ( array $a, array $b ) {
			$age = strcmp( (string) ( $a['created_at'] ?? '' ), (string) ( $b['created_at'] ?? '' ) );
			return 0 !== $age ? $age : ( (int) $a['id'] <=> (int) $b['id'] );
		};

		// 1. Where every request stands now.
		$assigned = array(); // request id → line id.
		$why      = array(); // request id → why it lost its line.
		foreach ( $by_id as $id => $request ) {
			$line_id = (int) ( $request['line_id'] ?? 0 );
			if ( 'waiting' === $request['state'] || $line_id <= 0 ) {
				continue;
			}
			if ( ! isset( $lines[ $line_id ] ) ) {
				if ( 'offered' !== $request['state'] ) {
					$why[ $id ] = __( 'Its supplier order line no longer exists — back to waiting.', 'fishotel-misc-plugin' );
				}
				continue;
			}
			if ( 'missed' === self::phase( $order_of[ $line_id ], $lines[ $line_id ] ) && 'offered' !== $request['state'] ) {
				/* translators: %s: order ref */
				$why[ $id ] = sprintf( __( 'Supplier order %s arrived without this fish — back to waiting.', 'fishotel-misc-plugin' ), (string) ( $order_of[ $line_id ]['ref'] ?? '' ) );
				continue;
			}
			$assigned[ $id ] = $line_id;
		}

		// 2. No line carries more requests than it has fish.
		$used   = array(); // line id → fish spoken for.
		$frozen = array(); // line id → true: checked in, but its tank groups could not be read.
		foreach ( array_unique( array_values( $assigned ) ) as $line_id ) {
			$line  = $lines[ $line_id ];
			$order = $order_of[ $line_id ];
			$phase = self::phase( $order, $line );
			if ( 'tank' === $phase && empty( $line['stays'] ) ) {
				// Nothing is known about the fish: leave every request of the line as it is.
				$frozen[ $line_id ] = true;
				continue;
			}
			$room  = 'tank' === $phase ? self::live( $line ) : max( 0, (int) ( $line['qty_ordered'] ?? 0 ) );
			$group = array();
			foreach ( $assigned as $id => $assigned_line ) {
				if ( $assigned_line === $line_id ) {
					$group[] = $by_id[ $id ];
				}
			}
			usort( $group, $rank );

			$used[ $line_id ] = 0;
			foreach ( $group as $request ) {
				$id   = (int) $request['id'];
				$qty  = max( 1, (int) ( $request['qty'] ?? 1 ) );
				$fits = $used[ $line_id ] + $qty <= $room;
				$keep = $fits || 'offered' === $request['state'] || ( 'order' === $phase && ! empty( $request['link_manual'] ) );
				if ( $keep ) {
					$used[ $line_id ] += $qty;
					continue;
				}
				unset( $assigned[ $id ] );
				$why[ $id ] = 'tank' === $phase
					? __( 'Fewer fish are alive in that tank group than were asked for — back to waiting (the oldest requests keep their place).', 'fishotel-misc-plugin' )
					/* translators: %s: order ref */
					: sprintf( __( 'Supplier order %s has fewer fish on that line than requests — back to waiting (the oldest requests keep their place).', 'fishotel-misc-plugin' ), (string) ( $order['ref'] ?? '' ) );
			}
		}

		// 3. Waiting requests find a line: the oldest request first, the
		// oldest order first, never more fish than the line orders.
		$candidates = array();
		foreach ( $lines as $line_id => $line ) {
			if ( 'order' === self::phase( $order_of[ $line_id ], $line ) && empty( $line['needs_id'] ) && (int) ( $line['qty_ordered'] ?? 0 ) > 0 ) {
				$candidates[ $line_id ] = $line;
			}
		}
		uasort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $a['_seq'] <=> (int) $b['_seq'];
			}
		);

		$seekers = array();
		foreach ( $by_id as $id => $request ) {
			if ( isset( $assigned[ $id ] ) || 'offered' === $request['state'] || empty( $request['auto_match'] ) ) {
				continue;
			}
			$seekers[] = $request;
		}
		usort( $seekers, $oldest );

		$matched = array(); // request id → true (found a line in this run).
		foreach ( $seekers as $request ) {
			$id  = (int) $request['id'];
			$qty = max( 1, (int) ( $request['qty'] ?? 1 ) );
			foreach ( $candidates as $line_id => $line ) {
				if ( ! self::same_fish( $request, $line ) ) {
					continue;
				}
				$taken = isset( $used[ $line_id ] ) ? $used[ $line_id ] : 0;
				if ( $taken + $qty > (int) $line['qty_ordered'] ) {
					continue;
				}
				$used[ $line_id ] = $taken + $qty;
				$assigned[ $id ]  = $line_id;
				$matched[ $id ]   = true;
				break;
			}
		}

		// 4. What each request should be, and the steps that get it there.
		$ready_used = array(); // line id → fish on sale spoken for.
		$steps      = array();
		$ordered    = array_values( $by_id );
		usort( $ordered, $rank );

		foreach ( $ordered as $request ) {
			$id = (int) $request['id'];

			if ( ! isset( $assigned[ $id ] ) ) {
				if ( 'waiting' !== $request['state'] && 'offered' !== $request['state'] ) {
					$steps[] = array(
						'id'         => $id,
						'to'         => 'waiting',
						'line_id'    => null,
						'order_id'   => null,
						'stay_id'    => null,
						'product_id' => null,
						'why'        => isset( $why[ $id ] ) ? $why[ $id ] : __( 'No supplier order carries it any more — back to waiting.', 'fishotel-misc-plugin' ),
					);
				}
				continue;
			}

			$line_id = $assigned[ $id ];
			if ( isset( $frozen[ $line_id ] ) ) {
				continue;
			}
			$line  = $lines[ $line_id ];
			$order = $order_of[ $line_id ];
			$phase = self::phase( $order, $line );
			$qty   = max( 1, (int) ( $request['qty'] ?? 1 ) );
			$ref   = (string) ( $order['ref'] ?? '' );

			if ( 'offered' === $request['state'] ) {
				// Told already: it only keeps its fish out of the others' count.
				$ready_used[ $line_id ] = ( isset( $ready_used[ $line_id ] ) ? $ready_used[ $line_id ] : 0 ) + $qty;
				continue;
			}

			if ( 'order' === $phase ) {
				$to   = 'on_order';
				$stay = array(
					'stay_id'    => null,
					'product_id' => ! empty( $line['species_product_id'] ) ? (int) $line['species_product_id'] : null,
				);
				$said = isset( $matched[ $id ] )
					/* translators: 1: order ref, 2: species on the line, 3: fish ordered */
					? sprintf( __( 'Supplier order %1$s carries it (%2$s, %3$d ordered) — matched by itself.', 'fishotel-misc-plugin' ), $ref, (string) ( $line['species'] ?? '' ), (int) $line['qty_ordered'] )
					/* translators: %s: order ref */
					: sprintf( __( 'Its line on supplier order %s is not in a tank (any more) — on order.', 'fishotel-misc-plugin' ), $ref );
			} else {
				$taken = isset( $ready_used[ $line_id ] ) ? $ready_used[ $line_id ] : 0;
				if ( $taken + $qty <= self::live( $line, true ) ) {
					$ready_used[ $line_id ] = $taken + $qty;
					$to                     = 'ready';
					$stay                   = self::stay_of( $line, true );
					$said                   = __( 'The sale date of its tank group has come — the fish is ready.', 'fishotel-misc-plugin' );
				} else {
					$to   = 'in_quarantine';
					$stay = self::stay_of( $line, false );
					$said = 'ready' === $request['state']
						? __( 'Its tank group is not on sale (any more) — back in quarantine.', 'fishotel-misc-plugin' )
						/* translators: %s: order ref */
						: sprintf( __( 'Checked in from supplier order %s — in quarantine.', 'fishotel-misc-plugin' ), $ref );
				}
			}

			$same_line = (int) ( $request['line_id'] ?? 0 ) === $line_id;
			$same_stay = (int) ( $request['stay_id'] ?? 0 ) === (int) $stay['stay_id'];
			$same      = $request['state'] === $to && $same_line;
			if ( $same && ( $same_stay || 'on_order' === $to ) ) {
				continue;
			}

			$steps[] = array(
				'id'         => $id,
				'to'         => $to,
				'line_id'    => $line_id,
				'order_id'   => (int) ( $order['id'] ?? 0 ),
				'stay_id'    => $stay['stay_id'],
				'product_id' => $stay['product_id'],
				'why'        => $said,
				// Only the tank group changed (a split, a move): nothing to tell anybody.
				'quiet'      => $same,
			);
		}

		return $steps;
	}

	/* ============================================================== */
	/*  The run                                                        */
	/* ============================================================== */

	/**
	 * Follow the fish once: read the supplier orders, plan, apply.
	 *
	 * @param Service     $service   The service.
	 * @param object|null $cleanroom The Clean Room adapter (snapshot()), or null when it is off.
	 * @return array{available: bool, on_order: int, in_quarantine: int, ready: int, waiting: int}
	 */
	public static function run( $service, $cleanroom ) {
		$out = array(
			'available'     => false,
			'on_order'      => 0,
			'in_quarantine' => 0,
			'ready'         => 0,
			'waiting'       => 0,
		);
		if ( ! is_object( $cleanroom ) || ! method_exists( $cleanroom, 'snapshot' ) ) {
			return $out;
		}

		$requests = $service->store->find( array( 'state' => Rules::FOLLOW_STATES ) );
		$orders   = $cleanroom->snapshot( $requests );
		if ( ! is_array( $orders ) ) {
			// The supplier orders could not be read: change nothing rather than send everyone back.
			return $out;
		}
		$out['available'] = true;

		$by_id = array();
		foreach ( $requests as $request ) {
			$by_id[ (int) $request['id'] ] = $request;
		}

		foreach ( self::plan( $requests, $orders ) as $step ) {
			if ( isset( $by_id[ $step['id'] ] ) && $service->follow( $by_id[ $step['id'] ], $step ) && empty( $step['quiet'] ) ) {
				$out[ $step['to'] ]++;
			}
		}

		return $out;
	}

	/**
	 * Tell everyone whose fish is ready — only when Settings says "by
	 * themselves", and only requests the job has not already tried.
	 *
	 * @param Service $service The service.
	 * @return int How many were told.
	 */
	public static function offers( $service ) {
		if ( empty( $service->settings['auto_offer'] ) ) {
			return 0;
		}

		$told = 0;
		foreach ( $service->store->find( array( 'state' => 'ready' ) ) as $request ) {
			if ( ! empty( $request['offered_at'] ) || '' !== $service->offer_blocker( $request ) ) {
				continue;
			}
			$result = $service->offer( (int) $request['id'], Service::system() );
			if ( ! empty( $result['ok'] ) ) {
				$told++;
			}
		}

		return $told;
	}

	/**
	 * A customer paid an order: every request that was offered to them
	 * for a product on that order — offered before the order was placed —
	 * is filled.
	 *
	 * @param Service $service The service.
	 * @param array   $order   id, user_id, email, created (UNIX time), product_ids (int[], parents included).
	 * @return int[] The requests that were filled.
	 */
	public static function bought( $service, array $order ) {
		$products = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $order['product_ids'] ?? array() ) ) ) ) );
		$user_id  = max( 0, (int) ( $order['user_id'] ?? 0 ) );
		$email    = Rules::email( $order['email'] ?? '' );
		if ( ! $products || ( 0 === $user_id && '' === $email ) ) {
			return array();
		}

		$offered = array();
		if ( $user_id > 0 ) {
			foreach ( $service->store->find(
				array(
					'state'   => 'offered',
					'user_id' => $user_id,
				)
			) as $request ) {
				$offered[ (int) $request['id'] ] = $request;
			}
		}
		if ( '' !== $email ) {
			foreach ( $service->store->find(
				array(
					'state' => 'offered',
					'email' => $email,
				)
			) as $request ) {
				$offered[ (int) $request['id'] ] = $request;
			}
		}

		$filled  = array();
		$created = (int) ( $order['created'] ?? 0 );
		foreach ( $offered as $id => $request ) {
			if ( ! empty( $request['owed'] ) || empty( $request['product_id'] ) || ! in_array( (int) $request['product_id'], $products, true ) ) {
				continue;
			}
			if ( $created > 0 && Rules::ts( $request['offered_at'] ?? '' ) > $created ) {
				continue; // Bought before the offer: not because of it.
			}
			/* translators: %d: order number */
			$result = $service->fill( $id, Service::system(), sprintf( __( 'Bought with order #%d — filled by itself.', 'fishotel-misc-plugin' ), (int) ( $order['id'] ?? 0 ) ) );
			if ( ! empty( $result['ok'] ) ) {
				$filled[] = $id;
			}
		}

		return $filled;
	}
}
