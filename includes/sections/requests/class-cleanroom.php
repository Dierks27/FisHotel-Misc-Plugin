<?php
/**
 * Requests ↔ Clean Room — the ONE place this section reads the Clean Room
 * section of the same plugin: the species suggestions, the standard
 * sizes, the supplier orders with their lines and tank groups, and the
 * quantities ordered in past years.
 *
 * The Clean Room may be switched off, older or absent, so nothing here
 * names its classes at load time: make() answers null unless its Store
 * class is loaded (the Clean Room booted) and its tables exist, and every
 * call asks method_exists() first. Without it the rest of the section
 * works: sizes are free text, suggestions come from the shop's published
 * products, and a request's state is set by hand.
 *
 * Nothing is ever written to the Clean Room — this class only reads,
 * through its Store's public methods (the tests hand it the Clean Room's
 * own in-memory store).
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cleanroom
 */
class Cleanroom {

	const NS = '\\FisHotel\\Misc\\Sections\\Clean_Room\\';

	/**
	 * How many supplier orders the past-demand table and the "match by
	 * hand" list read.
	 */
	const DEMAND_ORDERS = 500;
	const RECENT_ORDERS = 60;

	/**
	 * The Clean Room's store.
	 *
	 * @var object
	 */
	private $store;

	/**
	 * Today in the site zone (Y-m-d).
	 *
	 * @var string
	 */
	private $today;

	/**
	 * The Clean Room's settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * The adapter, or null when the Clean Room is not there.
	 *
	 * @return self|null
	 */
	public static function make() {
		$store_class = self::NS . 'Store';
		$room_class  = self::NS . 'Clean_Room';
		$settings    = self::NS . 'Settings';

		// class_exists() without autoloading: true only when the Clean Room booted.
		if ( ! class_exists( $store_class, false ) || ! class_exists( $room_class, false ) ) {
			return null;
		}

		try {
			if ( ! method_exists( $store_class, 'tables_exist' ) || ! call_user_func( array( $store_class, 'tables_exist' ) ) ) {
				return null;
			}
			$today = method_exists( $room_class, 'today' ) ? (string) call_user_func( array( $room_class, 'today' ) ) : gmdate( 'Y-m-d' );
			$opts  = class_exists( $settings, false ) && method_exists( $settings, 'get' ) ? (array) call_user_func( array( $settings, 'get' ) ) : array();

			return new self( new $store_class(), $today, $opts );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Constructor.
	 *
	 * @param object $store    The Clean Room's store (or its tests' twin).
	 * @param string $today    Site date.
	 * @param array  $settings The Clean Room's settings.
	 */
	public function __construct( $store, $today, array $settings = array() ) {
		$this->store    = $store;
		$this->today    = (string) $today;
		$this->settings = $settings;
	}

	private function can( $method ) {
		return is_object( $this->store ) && method_exists( $this->store, $method );
	}

	/* ============================================================== */
	/*  Sizes and names                                                */
	/* ============================================================== */

	/**
	 * The standard sizes (slug, label), or null when the Clean Room's
	 * Sizes class is not there or has no size yet — the size is then free
	 * text.
	 *
	 * @return array[]|null
	 */
	public function sizes() {
		$sizes = self::NS . 'Sizes';
		if ( ! class_exists( $sizes, false ) || ! method_exists( $sizes, 'standard' ) ) {
			return null;
		}
		try {
			$standard = call_user_func( array( $sizes, 'standard' ), $this->store, $this->settings );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $standard ) || ! isset( $standard['sizes'] ) || ! is_array( $standard['sizes'] ) ) {
			return null;
		}

		$out = array();
		foreach ( $standard['sizes'] as $size ) {
			if ( is_array( $size ) && '' !== (string) ( $size['slug'] ?? '' ) ) {
				$out[] = array(
					'slug'  => (string) $size['slug'],
					'label' => (string) ( $size['label'] ?? $size['slug'] ),
				);
			}
		}

		// No standard size is set up yet: a dropdown with nothing in it
		// would not let anybody name a size, so the size is free text.
		return $out ? $out : null;
	}

	/**
	 * Names to suggest for what somebody is typing: the species list and
	 * the fish products.
	 *
	 * @param string        $term      What was typed.
	 * @param int           $limit     How many.
	 * @param callable|null $is_public Given a product id, whether it may be shown to somebody
	 *                                 who is not the shop (null = show everything).
	 * @return array[] name, sci.
	 */
	public function suggest( $term, $limit = 8, $is_public = null ) {
		if ( ! $this->can( 'species_suggestions' ) ) {
			return array();
		}
		try {
			$items = (array) $this->store->species_suggestions( (string) $term, max( 1, (int) $limit ) * 2 );
		} catch ( \Throwable $e ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || '' === trim( (string) ( $item['name'] ?? '' ) ) ) {
				continue;
			}
			// A product that is not in the species list is only named when it is public.
			if ( null !== $is_public && empty( $item['species_id'] ) && ! empty( $item['product_id'] ) && ! call_user_func( $is_public, (int) $item['product_id'] ) ) {
				continue;
			}
			$key = Rules::name_key( $item['name'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'name' => (string) $item['name'],
				'sci'  => (string) ( $item['sci'] ?? '' ),
			);
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The species-list fish and the product a typed name IS (the same
	 * name, compared without case) — never a guess.
	 *
	 * @param string $fish The name as typed.
	 * @return array{species_id: int|null, product_id: int|null}
	 */
	public function match_name( $fish ) {
		$out = array(
			'species_id' => null,
			'product_id' => null,
		);
		$key = Rules::name_key( $fish );
		if ( '' === $key ) {
			return $out;
		}

		try {
			if ( $this->can( 'species_by_name' ) ) {
				$row = $this->store->species_by_name( (string) $fish );
				if ( is_array( $row ) && ! empty( $row['id'] ) ) {
					$out['species_id'] = (int) $row['id'];
					$out['product_id'] = ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null;
					return $out;
				}
			}
			if ( $this->can( 'species_suggestions' ) ) {
				foreach ( (array) $this->store->species_suggestions( (string) $fish, 20 ) as $item ) {
					if ( is_array( $item ) && Rules::name_key( (string) ( $item['name'] ?? '' ) ) === $key ) {
						$out['species_id'] = ! empty( $item['species_id'] ) ? (int) $item['species_id'] : null;
						$out['product_id'] = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : null;
						return $out;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $out;
	}

	/* ============================================================== */
	/*  Supplier orders                                                */
	/* ============================================================== */

	/**
	 * A supplier order's header as the follower reads it.
	 *
	 * @param array $order The Clean Room's order.
	 * @return array id, ref, status, supplier, date, lines.
	 */
	private static function order_brief( array $order ) {
		// The date an order files under, as the Clean Room has it: received, else ordered, else created.
		$date = '';
		foreach ( array( 'received_date', 'order_date', 'created_at' ) as $key ) {
			if ( ! empty( $order[ $key ] ) ) {
				$date = substr( (string) $order[ $key ], 0, 10 );
				break;
			}
		}
		return array(
			'id'       => (int) ( $order['id'] ?? 0 ),
			'ref'      => (string) ( $order['ref'] ?? '' ),
			'status'   => (string) ( $order['status'] ?? '' ),
			'supplier' => (string) ( $order['supplier'] ?? '' ),
			'date'     => $date,
			'lines'    => array(),
		);
	}

	/**
	 * A supplier order line as the follower reads it.
	 *
	 * @param array $line The Clean Room's line.
	 * @return array
	 */
	private static function line_brief( array $line ) {
		return array(
			'id'                 => (int) ( $line['id'] ?? 0 ),
			'order_id'           => (int) ( $line['order_id'] ?? 0 ),
			'species_id'         => ! empty( $line['species_id'] ) ? (int) $line['species_id'] : null,
			'species'            => (string) ( $line['species'] ?? '' ),
			'species_product_id' => ! empty( $line['species_product_id'] ) ? (int) $line['species_product_id'] : null,
			'size_slug'          => isset( $line['size_slug'] ) && null !== $line['size_slug'] ? (string) $line['size_slug'] : '',
			'size_label'         => isset( $line['size_label'] ) && null !== $line['size_label'] ? (string) $line['size_label'] : '',
			'qty_ordered'        => (int) ( $line['qty_ordered'] ?? 0 ),
			'needs_id'           => ! empty( $line['needs_id'] ),
			'stay_id'            => ! empty( $line['stay_id'] ) ? (int) $line['stay_id'] : null,
			'stays'              => array(),
		);
	}

	/**
	 * Supplier orders with their lines, by id.
	 *
	 * @param array[] $headers The Clean Room's order headers.
	 * @return array<int, array>
	 */
	private function with_lines( array $headers ) {
		$out = array();
		foreach ( $headers as $header ) {
			if ( is_array( $header ) && (int) ( $header['id'] ?? 0 ) > 0 ) {
				$out[ (int) $header['id'] ] = self::order_brief( $header );
			}
		}
		if ( ! $out || ! $this->can( 'lines_for_orders' ) ) {
			return $out;
		}

		foreach ( (array) $this->store->lines_for_orders( array_keys( $out ) ) as $order_id => $lines ) {
			if ( ! isset( $out[ (int) $order_id ] ) ) {
				continue;
			}
			foreach ( (array) $lines as $line ) {
				if ( is_array( $line ) ) {
					$out[ (int) $order_id ]['lines'][] = self::line_brief( $line );
				}
			}
		}

		return $out;
	}

	/**
	 * The tank groups of a line, as the follower reads them.
	 *
	 * @param int $line_id Line id.
	 * @return array[] id, qty_live, sale_ready, open, product_id, tank.
	 */
	private function stays( $line_id ) {
		if ( ! $this->can( 'stays_for_line' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $this->store->stays_for_line( (int) $line_id, $this->today, $this->settings ) as $stay ) {
			if ( ! is_array( $stay ) ) {
				continue;
			}
			$status = (string) ( $stay['status'] ?? ( $stay['stored_status'] ?? '' ) );
			$out[]  = array(
				'id'         => (int) ( $stay['id'] ?? 0 ),
				'qty_live'   => (int) ( $stay['qty_live'] ?? 0 ),
				'sale_ready' => ! empty( $stay['sale_ready'] ),
				'open'       => 'closed' !== $status,
				'product_id' => ! empty( $stay['product_id'] ) ? (int) $stay['product_id'] : null,
				'tank'       => ! empty( $stay['tank_number'] ) ? (int) $stay['tank_number'] : null,
			);
		}
		return $out;
	}

	/**
	 * What Follow::plan() needs: every draft and ordered supplier order,
	 * the order of every line a request is matched to, their lines, and
	 * the tank groups of the lines that matter. Null when the orders
	 * cannot be read — the follower then changes nothing.
	 *
	 * @param array[] $requests The requests the follower looks at (line_id, cr_order_id).
	 * @return array[]|null
	 */
	public function snapshot( array $requests ) {
		if ( ! $this->can( 'orders' ) || ! $this->can( 'lines_for_orders' ) ) {
			return null;
		}

		try {
			$headers = array();
			foreach ( Follow::OPEN_ORDER_STATUSES as $status ) {
				foreach ( (array) $this->store->orders( array( 'status' => $status ) ) as $header ) {
					if ( is_array( $header ) && (int) ( $header['id'] ?? 0 ) > 0 ) {
						$headers[ (int) $header['id'] ] = $header;
					}
				}
			}

			$linked = array();
			foreach ( $requests as $request ) {
				if ( (int) ( $request['line_id'] ?? 0 ) > 0 ) {
					$linked[ (int) $request['line_id'] ] = (int) ( $request['cr_order_id'] ?? 0 );
				}
			}
			foreach ( array_unique( array_filter( array_values( $linked ) ) ) as $order_id ) {
				if ( isset( $headers[ $order_id ] ) || ! $this->can( 'order' ) ) {
					continue;
				}
				$order = $this->store->order( $order_id );
				if ( is_array( $order ) ) {
					$headers[ $order_id ] = $order;
				}
			}

			$orders = $this->with_lines( array_values( $headers ) );

			foreach ( $orders as $order_id => $order ) {
				foreach ( $order['lines'] as $index => $line ) {
					if ( isset( $linked[ $line['id'] ] ) || ! empty( $line['stay_id'] ) ) {
						$orders[ $order_id ]['lines'][ $index ]['stays'] = $this->stays( $line['id'] );
					}
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return array_values( $orders );
	}

	/**
	 * The supplier order lines a request could be matched to by hand: the
	 * same fish (or a name that resembles it) on a recent order that is a
	 * draft, ordered or received.
	 *
	 * @param array[] $requests The requests to find lines for.
	 * @return array<int, array[]> request id → lines (id, order_id, ref, status, species, size_label,
	 *                             qty_ordered, exact).
	 */
	public function candidates( array $requests ) {
		if ( ! $requests || ! $this->can( 'orders' ) ) {
			return array();
		}

		try {
			$headers = array();
			foreach ( (array) $this->store->orders( array( 'limit' => self::RECENT_ORDERS ) ) as $header ) {
				if ( is_array( $header ) && in_array( (string) ( $header['status'] ?? '' ), array( 'draft', 'ordered', 'received' ), true ) ) {
					$headers[] = $header;
				}
			}
			$orders = $this->with_lines( array_slice( $headers, 0, self::RECENT_ORDERS ) );
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( $requests as $request ) {
			$found = array();
			foreach ( $orders as $order ) {
				foreach ( $order['lines'] as $line ) {
					$exact = Follow::same_fish( $request, $line );
					if ( ! $exact && ! Follow::similar_fish( $request, $line ) ) {
						continue;
					}
					$found[] = array(
						'id'          => $line['id'],
						'order_id'    => $order['id'],
						'ref'         => $order['ref'],
						'status'      => $order['status'],
						'species'     => $line['species'],
						'size_label'  => $line['size_label'],
						'qty_ordered' => $line['qty_ordered'],
						'exact'       => $exact,
					);
				}
			}
			if ( $found ) {
				usort(
					$found,
					static function ( $a, $b ) {
						return ( $b['exact'] <=> $a['exact'] ) ?: ( $b['order_id'] <=> $a['order_id'] );
					}
				);
				$out[ (int) $request['id'] ] = array_slice( $found, 0, 12 );
			}
		}

		return $out;
	}

	/**
	 * One supplier order line, for a match by hand.
	 *
	 * @param int $order_id Supplier order id.
	 * @param int $line_id  Line id.
	 * @return array|null id, order_id, ref, species.
	 */
	public function line( $order_id, $line_id ) {
		if ( ! $this->can( 'order' ) ) {
			return null;
		}
		try {
			$order = $this->store->order( (int) $order_id );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_array( $order ) ) {
			return null;
		}
		foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
			if ( is_array( $line ) && (int) ( $line['id'] ?? 0 ) === (int) $line_id ) {
				return array(
					'id'       => (int) $line['id'],
					'order_id' => (int) $order['id'],
					'ref'      => (string) ( $order['ref'] ?? '' ),
					'species'  => (string) ( $line['species'] ?? '' ),
				);
			}
		}
		return null;
	}

	/**
	 * The refs of some supplier orders, by id.
	 *
	 * @param int[] $order_ids Supplier order ids.
	 * @return array<int, string>
	 */
	public function refs( array $order_ids ) {
		$out = array();
		if ( ! $this->can( 'order' ) ) {
			return $out;
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) as $order_id ) {
			try {
				$order = $this->store->order( $order_id );
			} catch ( \Throwable $e ) {
				$order = null;
			}
			if ( is_array( $order ) ) {
				$out[ $order_id ] = (string) ( $order['ref'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Past demand: the quantities ORDERED on supplier orders per species
	 * and year. (The shop's past supplier orders were all customer
	 * requests, so what was ordered is what was asked for.)
	 *
	 * @return array{years: int[], rows: array<string, array{species: string, species_id: int|null, years: array<int, int>, total: int}>}
	 */
	public function ordered_by_year() {
		$out = array(
			'years' => array(),
			'rows'  => array(),
		);
		if ( ! $this->can( 'orders' ) || ! $this->can( 'lines_for_orders' ) ) {
			return $out;
		}

		try {
			$headers = (array) $this->store->orders( array( 'limit' => self::DEMAND_ORDERS ) );
			$years   = array();
			$by_id   = array();
			foreach ( $headers as $header ) {
				if ( ! is_array( $header ) || (int) ( $header['id'] ?? 0 ) <= 0 ) {
					continue;
				}
				$brief = self::order_brief( $header );
				$year  = (int) substr( $brief['date'], 0, 4 );
				if ( $year > 0 ) {
					$by_id[ $brief['id'] ] = $year;
					$years[ $year ]        = true;
				}
			}
			if ( ! $by_id ) {
				return $out;
			}

			foreach ( (array) $this->store->lines_for_orders( array_keys( $by_id ) ) as $order_id => $lines ) {
				if ( ! isset( $by_id[ (int) $order_id ] ) ) {
					continue;
				}
				$year = $by_id[ (int) $order_id ];
				foreach ( (array) $lines as $line ) {
					if ( ! is_array( $line ) || ! empty( $line['needs_id'] ) || (int) ( $line['qty_ordered'] ?? 0 ) <= 0 ) {
						continue;
					}
					$species_id = ! empty( $line['species_id'] ) ? (int) $line['species_id'] : null;
					$key        = null !== $species_id ? 's:' . $species_id : 't:' . Rules::name_key( (string) ( $line['species'] ?? '' ) );
					if ( ! isset( $out['rows'][ $key ] ) ) {
						$out['rows'][ $key ] = array(
							'species'    => (string) ( $line['species'] ?? '' ),
							'species_id' => $species_id,
							'years'      => array(),
							'total'      => 0,
						);
					}
					$qty                                 = (int) $line['qty_ordered'];
					$out['rows'][ $key ]['years'][ $year ] = ( isset( $out['rows'][ $key ]['years'][ $year ] ) ? $out['rows'][ $key ]['years'][ $year ] : 0 ) + $qty;
					$out['rows'][ $key ]['total']         += $qty;
				}
			}

			$out['years'] = array_keys( $years );
			rsort( $out['years'] );
		} catch ( \Throwable $e ) {
			return array(
				'years' => array(),
				'rows'  => array(),
			);
		}

		return $out;
	}
}
