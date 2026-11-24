<?php
/**
 * Clean Room feeding on a ship day — PURE: a fish that goes in a bag
 * today is not fed today ("we do not feed fish during the day if they
 * are going in the bag, to pre-empty their stomachs as much as we can"),
 * and a building is one water, so the whole system the fish is in goes
 * without ("The whole system is tied together so I can't just skip a
 * tank").
 *
 * The rule, in the order it is worked out:
 *
 *   going_out()   the customer orders that go out the door today: the
 *                 delivery date the checkout stored is TOMORROW (a box
 *                 ships the day before it arrives) and the order is
 *                 processing, on hold, a combined box or already marked
 *                 shipped (tracking is added during the day; Feed must
 *                 not come back when it is). A combined box is read
 *                 through the orders in it, not by its own copied lines
 *                 (those only when none of its orders can be read); an
 *                 order folded into a box is read through that box.
 *   fish_lines()  the lines of such an order that are fish: a product in
 *                 a fish category, or a fish line out of an invoice —
 *                 never a line marked "not available", never one fully
 *                 refunded.
 *   plan()        each fish line → the tank group it comes out of
 *                 (Sold_Line::stays()) → the water that group is in. A
 *                 Clean Room building holding one FASTS; so does a clean
 *                 temporary tank holding one. A group in a clean water
 *                 answers before one in quarantine: by product or by name
 *                 the site only guesses, and a fish for sale is in a
 *                 clean tank. A fish the Invoices section tied to a
 *                 quarantine group changes nothing: quarantine is fed as
 *                 always. A fish the site cannot find in any CLEAN tank —
 *                 in no tank at all, or only guessed into a quarantine
 *                 one — makes EVERY Clean Room building fast: not feeding
 *                 a fish that is about to be bagged matters more than a
 *                 missed feed, and the rule narrows by itself as tanks
 *                 get logged. An invert (LOOSE_CATEGORIES) makes the
 *                 system it is found in fast and, when it is found
 *                 nowhere, changes nothing — inverts are often in no tank
 *                 on the site, and that would never narrow.
 *
 * The fast lasts the whole site day. What the to-do does with it:
 * for_tank() / without_feed() take the open Feed off a fasting tank (a
 * feed ticked before the order came in stays, and says so); block(),
 * list_notice() and today_words() are the sentences the screens show —
 * the reason is always given.
 *
 * No WordPress beyond the translation helpers: orders, lines, stays and
 * tanks are plain arrays (Ship_Lines reads the shop).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Feeding
 */
class Feeding {

	/**
	 * The settings key of the one tick (Settings → Numbers), on by default.
	 *
	 * @var string
	 */
	const SETTING = 'no_feed_on_ship_day';

	/**
	 * The order statuses (without "wc-") an order going out today may be
	 * in. "shipped" is here on purpose: ShipTracker flips an order to it
	 * the moment its tracking is saved, and the fast lasts the whole day.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'processing', 'on-hold', 'fulfillment', 'shipped' );

	/**
	 * The statuses of an order inside a combined box that ships nothing.
	 *
	 * @var string[]
	 */
	const DEAD_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * The theme's line status of a fish that is not coming.
	 *
	 * @var string
	 */
	const LINE_NOT_AVAILABLE = 'na';

	/**
	 * The categories whose animals need not be found in a tank: an invert
	 * that ships makes the system it is logged in go without, but one
	 * logged nowhere does not stop the feeding of every clean system (a
	 * fish does). A product that is also in another fish category is a
	 * fish.
	 *
	 * @var string[]
	 */
	const LOOSE_CATEGORIES = array( 'inverts' );

	/**
	 * How many fish a sentence names before it says "and N more fish".
	 *
	 * @var int
	 */
	const LIST_MAX = 4;

	/**
	 * What the rule is today.
	 */
	const STATE_ON       = 'on';       // It applies.
	const STATE_OFF      = 'off';      // The tick in Settings is off.
	const STATE_NO_BATCH = 'no_batch'; // The Batch Manager is not there: no order has a ship day.
	const STATE_NO_SHOP  = 'no_shop';  // WooCommerce's orders cannot be read.

	/* ============================================================== */
	/*  Which orders go out today                                      */
	/* ============================================================== */

	/**
	 * What the rule is today (pure).
	 *
	 * @param array $settings      Settings (the tick).
	 * @param bool  $batch_present Whether the Batch Manager is active.
	 * @param bool  $shop_present  Whether WooCommerce's orders can be read.
	 * @return string One of the STATE_ constants.
	 */
	public static function state( array $settings, $batch_present, $shop_present = true ) {
		if ( empty( $settings[ self::SETTING ] ) ) {
			return self::STATE_OFF;
		}
		if ( ! $batch_present ) {
			return self::STATE_NO_BATCH;
		}
		return $shop_present ? self::STATE_ON : self::STATE_NO_SHOP;
	}

	/**
	 * The delivery date of a box that ships on a site date: the day
	 * after (the Batch Manager's rule — ship date = delivery date − 1).
	 *
	 * @param string $today Site date, Y-m-d.
	 * @return string Y-m-d, '' for a date that is none.
	 */
	public static function delivery_for( $today ) {
		return Replay::is_date( $today ) ? (string) Replay::add_days( (string) $today, 1 ) : '';
	}

	/**
	 * A status without its "wc-".
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	public static function status( $status ) {
		return (string) preg_replace( '/^wc-/', '', is_scalar( $status ) ? strtolower( trim( (string) $status ) ) : '' );
	}

	/**
	 * The orders whose fish go out the door today.
	 *
	 * @param array[]       $orders   Orders by id: id, number, status, delivery (Y-m-d), wrapper (bool: a combined
	 *                                box), folded (bool: inside a combined box), sources (int[]: the orders in a
	 *                                box), created (UNIX time), rows — the dated orders AND the orders inside
	 *                                their boxes.
	 * @param string        $today    Site date.
	 * @param string[]|null $statuses The statuses that count (null = STATUSES).
	 * @return array[] The orders to read fish from, by id — a box itself only when none of the orders in
	 *                 it can be read.
	 */
	public static function going_out( array $orders, $today, $statuses = null ) {
		$delivery = self::delivery_for( $today );
		$statuses = array_map( array( self::class, 'status' ), is_array( $statuses ) ? $statuses : self::STATUSES );
		$by_id    = array();
		foreach ( $orders as $order ) {
			if ( is_array( $order ) && (int) ( $order['id'] ?? 0 ) > 0 ) {
				$by_id[ (int) $order['id'] ] = $order;
			}
		}

		$out = array();
		if ( '' === $delivery ) {
			return $out;
		}
		foreach ( $by_id as $id => $order ) {
			if ( (string) ( $order['delivery'] ?? '' ) !== $delivery || ! in_array( self::status( $order['status'] ?? '' ), $statuses, true ) ) {
				continue;
			}
			if ( ! empty( $order['wrapper'] ) ) {
				// A combined box: its own lines are copies. The orders in it are read — the live ones.
				$known = 0;
				foreach ( (array) ( $order['sources'] ?? array() ) as $source_id ) {
					$source_id = (int) $source_id;
					if ( $source_id === $id || ! isset( $by_id[ $source_id ] ) ) {
						continue;
					}
					$known++;
					if ( ! empty( $by_id[ $source_id ]['wrapper'] ) || in_array( self::status( $by_id[ $source_id ]['status'] ?? '' ), self::DEAD_STATUSES, true ) ) {
						continue; // Never a box as an order; never an order that ships nothing.
					}
					$out[ $source_id ] = $by_id[ $source_id ];
				}
				if ( 0 === $known ) {
					// Not one of its orders can be read: the copies are all there is (as the Today page counts it).
					$out[ $id ] = $order;
				}
				continue;
			}
			if ( ! empty( $order['folded'] ) ) {
				continue; // Inside a combined box: that box says when it ships.
			}
			$out[ $id ] = $order;
		}

		return $out;
	}

	/* ============================================================== */
	/*  Which lines are fish                                           */
	/* ============================================================== */

	/**
	 * The fish lines of one order.
	 *
	 * @param array    $order           An order with its rows — each a line as the Lost Fish section reads
	 *                                  it (type: product | invoice_fish | invoice_line | invoice; name, qty,
	 *                                  qty_refunded, product_id, variation_id, link) plus, from the reader:
	 *                                  categories (the product's category slugs), line_status (the theme's),
	 *                                  size_slug, size_label, title (the product's own title).
	 * @param string[] $fish_categories The category slugs that are fish.
	 * @param string[]|null $loose      Of those, the ones whose animals need not be found in a tank (null =
	 *                                  LOOSE_CATEGORIES).
	 * @return array[] order_id, number, created, name, qty, product_id, variation_id, size_slug, size_label, names, link,
	 *                 loose (an invert: when no tank answers for it, nothing changes).
	 */
	public static function fish_lines( array $order, array $fish_categories, $loose = null ) {
		$out             = array();
		$fish_categories = array_map( 'strval', $fish_categories );
		$loose           = array_map( 'strval', is_array( $loose ) ? $loose : self::LOOSE_CATEGORIES );
		foreach ( isset( $order['rows'] ) && is_array( $order['rows'] ) ? $order['rows'] : array() as $row ) {
			if ( ! is_array( $row ) || self::LINE_NOT_AVAILABLE === (string) ( $row['line_status'] ?? '' ) ) {
				continue;
			}
			$qty = (int) ( $row['qty'] ?? 0 ) - abs( (int) ( $row['qty_refunded'] ?? 0 ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$type   = (string) ( $row['type'] ?? 'product' );
			$invert = false;
			if ( 'product' === $type ) {
				// A shop product is a fish by its category (the list the Ship Day page and the Today page use).
				$mine = array_intersect( array_map( 'strval', (array) ( $row['categories'] ?? array() ) ), $fish_categories );
				if ( ! $mine ) {
					continue;
				}
				$invert = ! array_diff( $mine, $loose ); // In no fish category but the inverts'.
			} elseif ( ! in_array( $type, array( 'invoice_fish', 'invoice_line', 'invoice' ), true ) ) {
				continue;
			}

			$title = isset( $row['title'] ) && is_scalar( $row['title'] ) ? trim( (string) $row['title'] ) : '';
			$out[] = array(
				'order_id'     => (int) ( $order['id'] ?? 0 ),
				'number'       => (string) ( $order['number'] ?? ( $order['id'] ?? '' ) ),
				'created'      => (int) ( $order['created'] ?? 0 ),
				'name'         => trim( (string) ( $row['name'] ?? '' ) ),
				'qty'          => $qty,
				'product_id'   => max( 0, (int) ( $row['product_id'] ?? 0 ) ),
				'variation_id' => max( 0, (int) ( $row['variation_id'] ?? 0 ) ),
				'size_slug'    => (string) ( $row['size_slug'] ?? '' ),
				'size_label'   => (string) ( $row['size_label'] ?? '' ),
				'names'        => '' !== $title ? array( $title ) : array(),
				'link'         => isset( $row['link'] ) && is_array( $row['link'] ) ? $row['link'] : array(),
				'loose'        => $invert,
			);
		}
		return $out;
	}

	/**
	 * Every fish that goes out the door today.
	 *
	 * @param array[]  $going_out       going_out(): the orders to read, each with its rows.
	 * @param string   $today           Site date.
	 * @param string[] $fish_categories The category slugs that are fish.
	 * @param string[]|null $loose      Of those, the inverts' (null = LOOSE_CATEGORIES) — see fish_lines().
	 * @return array{date: string, orders: int, fish: int, lines: array[]}
	 */
	public static function shipping( array $going_out, $today, array $fish_categories, $loose = null ) {
		$lines = array();
		$count = 0;
		$fish  = 0;
		foreach ( $going_out as $order ) {
			$mine = is_array( $order ) ? self::fish_lines( $order, $fish_categories, $loose ) : array();
			if ( ! $mine ) {
				continue;
			}
			$count++;
			foreach ( $mine as $line ) {
				$fish   += (int) $line['qty'];
				$lines[] = $line;
			}
		}
		return array(
			'date'   => (string) $today,
			'orders' => $count,
			'fish'   => $fish,
			'lines'  => $lines,
		);
	}

	/* ============================================================== */
	/*  The fasting set                                                */
	/* ============================================================== */

	/**
	 * A day on which the rule changes nothing.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @param string $today Site date.
	 * @return array See plan().
	 */
	public static function none( $state = self::STATE_OFF, $today = '' ) {
		return array(
			'state'     => (string) $state,
			'date'      => (string) $today,
			'orders'    => 0,
			'fish'      => 0,
			'buildings' => array(),
			'tanks'     => array(),
			'unlocated' => array(
				'fish'  => 0,
				'lines' => array(),
			),
			'all'       => false,
		);
	}

	/**
	 * Which waters go without food today.
	 *
	 * @param array   $ship  shipping(): the fish going out today.
	 * @param array[] $stays Every open stay, hydrated (id, product_id, species, size_slug, size_label, qty_live,
	 *                       tank_id, tank_number, building_id, area, own_water).
	 * @param array[] $tanks Every tank (a slot with its 'setup' — its kind says whether it is a clean one).
	 * @param array[] $gone  Closed stays, hydrated: the ones closed on the ship date still answer for
	 *                       their fish (left_today()) — bagging a fish and logging it does not lose it.
	 * @return array{state: string, date: string, orders: int, fish: int, buildings: array<int, array>,
	 *               tanks: array<int, array>, unlocated: array{fish: int, lines: array[]}, all: bool}
	 *         buildings: Clean Room building id => { fish, maybe, lines[] }; tanks: clean temporary tank id
	 *         => the same; fish: the fish that can only come out of this water, maybe: the fish that may
	 *         come out of it or out of another clean water (each of them goes without, and none counts
	 *         such a fish as surely its own); a line: qty, name, number, created, tanks (the tank numbers
	 *         it may come out of) and, only when it is so, also (the names of the other clean waters the
	 *         same fish is in, "another system" where a name is not known); unlocated: the fish no clean tank group answers for
	 *         — a line of it may carry quarantine (the quarantine tank numbers the site guesses it in);
	 *         all: every Clean Room building fasts.
	 */
	public static function plan( array $ship, array $stays, array $tanks, array $gone = array() ) {
		$out           = self::none( self::STATE_ON, (string) ( $ship['date'] ?? '' ) );
		$out['orders'] = (int) ( $ship['orders'] ?? 0 );
		$out['fish']   = (int) ( $ship['fish'] ?? 0 );
		$stays         = array_merge( $stays, self::left_today( $gone, $out['date'] ) );

		// A slot is clean or quarantine by what is set up in it.
		$slots = array();
		foreach ( $tanks as $tank ) {
			if ( is_array( $tank ) && Layout::is_slot( $tank ) ) {
				$slots[ (int) $tank['id'] ] = Temp_Tanks::effective_area( $tank );
			}
		}
		// The names of the clean waters, for "also in Clean Room 3".
		$names = array();
		foreach ( $tanks as $tank ) {
			if ( ! is_array( $tank ) ) {
				continue;
			}
			if ( Layout::is_slot( $tank ) ) {
				$name                                   = trim( (string) ( $tank['setup']['name'] ?? '' ) );
				$names[ 'tanks:' . (int) $tank['id'] ] = Layout::tank_label( $tank ) . ( '' !== $name ? ' · ' . $name : '' );
			} elseif ( isset( $tank['building'] ) && is_array( $tank['building'] ) && (int) ( $tank['building_id'] ?? 0 ) > 0 ) {
				$names[ 'buildings:' . (int) $tank['building_id'] ] = Layout::building_label( $tank['building'] );
			}
		}
		// The groups in a clean water: a Clean Room building, or a clean temporary tank.
		$clean = array();
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) && '' !== self::water( $stay, $slots ) ) {
				$clean[] = $stay;
			}
		}

		foreach ( isset( $ship['lines'] ) && is_array( $ship['lines'] ) ? $ship['lines'] : array() as $line ) {
			$qty = (int) ( $line['qty'] ?? 0 );
			if ( $qty <= 0 ) {
				continue;
			}
			$entry = array(
				'qty'     => $qty,
				'name'    => (string) ( $line['name'] ?? '' ),
				'number'  => (string) ( $line['number'] ?? '' ),
				'created' => (int) ( $line['created'] ?? 0 ),
				'tanks'   => array(),
			);

			$found = Sold_Line::stays( $line, $stays );
			if ( Sold_Line::RUNG_INVOICE !== $found['rung'] ) {
				// By product or by name the site only guesses, and a fish for sale is in a clean tank: a
				// group in a clean water answers before a better-fitting one in quarantine. Guessed into
				// quarantine only, the fish is in no clean tank the site knows — its clean tank is simply
				// not logged yet — and the quarantine tanks are named so the group can be moved.
				$guess = $found['stays'];
				$found = Sold_Line::stays( $line, $clean );
				if ( ! $found['stays'] ) {
					$numbers = array_values( array_unique( array_filter( array_map( 'intval', array_column( $guess, 'tank_number' ) ) ) ) );
					sort( $numbers );
					if ( $numbers ) {
						$entry['quarantine'] = $numbers;
					}
				}
			}
			if ( ! $found['stays'] ) {
				if ( ! empty( $line['loose'] ) ) {
					continue; // An invert in no clean tank the site knows: nothing changes.
				}
				$out['unlocated']['fish']   += $qty;
				$out['unlocated']['lines'][] = $entry;
				continue;
			}

			$waters = array();
			foreach ( $found['stays'] as $stay ) {
				$water = self::water( $stay, $slots );
				if ( '' !== $water ) {
					$waters[ $water ][] = (int) ( $stay['tank_number'] ?? 0 );
				}
				// In quarantine by the Invoices section's own link: located, and nothing changes.
			}
			// The same fish in two clean waters: both go without, and each names the other — neither
			// counts the fish as surely its own.
			$shared = count( $waters ) > 1;
			foreach ( $waters as $key => $numbers ) {
				list( $kind, $id ) = explode( ':', $key );
				$numbers           = array_values( array_unique( array_filter( array_map( 'intval', $numbers ) ) ) );
				sort( $numbers );
				if ( ! isset( $out[ $kind ][ (int) $id ] ) ) {
					$out[ $kind ][ (int) $id ] = array(
						'fish'  => 0,
						'maybe' => 0,
						'lines' => array(),
					);
				}
				$here = array_merge( $entry, array( 'tanks' => $numbers ) );
				if ( $shared ) {
					$also = array();
					foreach ( array_keys( $waters ) as $other ) {
						if ( $other !== $key ) {
							$also[] = isset( $names[ $other ] ) && '' !== $names[ $other ] ? $names[ $other ] : __( 'another system', 'fishotel-misc-plugin' );
						}
					}
					$here['also']                       = array_values( array_unique( $also ) );
					$out[ $kind ][ (int) $id ]['maybe'] += $qty;
				} else {
					$out[ $kind ][ (int) $id ]['fish'] += $qty;
				}
				$out[ $kind ][ (int) $id ]['lines'][] = $here;
			}
		}

		$out['all'] = $out['unlocated']['fish'] > 0;

		return $out;
	}

	/**
	 * The clean water a tank group is in: "buildings:<id>" for a Clean
	 * Room building, "tanks:<id>" for a clean temporary tank — '' for a
	 * group in quarantine (the Spa, a quarantine temporary tank).
	 *
	 * @param array                $stay  Hydrated stay (tank_id, building_id, area, own_water).
	 * @param array<int, string>   $slots Temporary tank id => the area it behaves as.
	 * @return string
	 */
	private static function water( array $stay, array $slots ) {
		$tank_id = (int) ( $stay['tank_id'] ?? 0 );
		if ( ! empty( $stay['own_water'] ) || isset( $slots[ $tank_id ] ) ) {
			return 'clean' === ( $slots[ $tank_id ] ?? '' ) ? 'tanks:' . $tank_id : '';
		}
		if ( 'clean' === (string) ( $stay['area'] ?? '' ) && (int) ( $stay['building_id'] ?? 0 ) > 0 ) {
			return 'buildings:' . (int) $stay['building_id'];
		}
		return '';
	}

	/**
	 * Of the closed stays, the ones whose last fish left on a date —
	 * marked `left_today`, which is what lets Sold_Line::stays() still
	 * find them. A fish bagged at nine and logged as shipped was in that
	 * water this morning: its system goes on fasting, and the fish is not
	 * suddenly one "the site doesn't know".
	 *
	 * @param array[] $closed Closed stays (closed_date).
	 * @param string  $date   Site date.
	 * @return array[]
	 */
	public static function left_today( array $closed, $date ) {
		$out = array();
		foreach ( $closed as $stay ) {
			if ( is_array( $stay ) && '' !== (string) $date && (string) ( $stay['closed_date'] ?? '' ) === (string) $date ) {
				$stay['left_today'] = true;
				$out[]              = $stay;
			}
		}
		return $out;
	}

	/**
	 * Whether a fasting set changes anything at all.
	 *
	 * @param array $fast plan().
	 * @return bool
	 */
	public static function is_on( array $fast ) {
		return self::STATE_ON === (string) ( $fast['state'] ?? '' ) && ( ! empty( $fast['all'] ) || ! empty( $fast['buildings'] ) || ! empty( $fast['tanks'] ) );
	}

	/**
	 * Why a Clean Room building goes without food today, or null when it
	 * is fed.
	 *
	 * @param array $fast     plan().
	 * @param array $building Building (id, area).
	 * @return array{fish: int, lines: array[], all: bool}|null all: it fasts (also) because a shipping fish is in no tank the site knows.
	 */
	public static function for_building( array $fast, array $building ) {
		if ( ! self::is_on( $fast ) || 'clean' !== (string) ( $building['area'] ?? '' ) || Layout::is_own_water( $building ) ) {
			return null;
		}
		$id   = (int) ( $building['id'] ?? 0 );
		$mine = isset( $fast['buildings'][ $id ] ) && is_array( $fast['buildings'][ $id ] ) ? $fast['buildings'][ $id ] : null;
		if ( null === $mine && empty( $fast['all'] ) ) {
			return null;
		}
		return array(
			'fish'  => $mine ? (int) $mine['fish'] : 0,
			'maybe' => $mine ? (int) ( $mine['maybe'] ?? 0 ) : 0,
			'lines' => $mine ? (array) $mine['lines'] : array(),
			'all'   => ! empty( $fast['all'] ),
		);
	}

	/**
	 * Why a tank goes without food today, or null when it is fed: a rack
	 * tank with its building; a temporary tank only when it is a clean one
	 * and a shipping fish was found in it.
	 *
	 * @param array $fast plan().
	 * @param array $tank Hydrated tank (id, building_id, building, setup).
	 * @return array{fish: int, lines: array[], all: bool}|null
	 */
	public static function for_tank( array $fast, array $tank ) {
		if ( ! self::is_on( $fast ) ) {
			return null;
		}
		if ( Layout::is_slot( $tank ) ) {
			$id   = (int) ( $tank['id'] ?? 0 );
			$mine = isset( $fast['tanks'][ $id ] ) && is_array( $fast['tanks'][ $id ] ) ? $fast['tanks'][ $id ] : null;
			if ( null === $mine || 'clean' !== Temp_Tanks::effective_area( $tank ) ) {
				return null;
			}
			return array(
				'fish'  => (int) $mine['fish'],
				'maybe' => (int) ( $mine['maybe'] ?? 0 ),
				'lines' => (array) $mine['lines'],
				'all'   => false,
			);
		}
		$building = isset( $tank['building'] ) && is_array( $tank['building'] ) ? $tank['building'] : array();
		return self::for_building( $fast, array_merge( $building, array( 'id' => (int) ( $tank['building_id'] ?? ( $building['id'] ?? 0 ) ) ) ) );
	}

	/**
	 * Whether a tank holds a live fish.
	 *
	 * @param array[] $occupants The tank's open stays.
	 * @return bool
	 */
	public static function has_fish( array $occupants ) {
		foreach ( $occupants as $stay ) {
			if ( (int) ( $stay['qty_live'] ?? 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What a fasting tank carries for its own screens.
	 *
	 * @param array $tank Hydrated tank with its to-do after without_feed().
	 * @param array $why  for_tank().
	 * @return array{text: string, strip: string, fed: string, fish: int, all: bool}
	 *         text: the reason in full; strip: the phone's tank strip; fed: "Fed at 7:40, before order
	 *         #1251 came in." when this tank was fed before the order came in, else ''.
	 */
	public static function tank_note( array $tank, array $why ) {
		$slot = Layout::is_slot( $tank );
		if ( $slot ) {
			$name  = trim( (string) ( $tank['setup']['name'] ?? '' ) );
			$label = Layout::tank_label( $tank ) . ( '' !== $name ? ' · ' . $name : '' );
		} else {
			$label = Layout::building_label( isset( $tank['building'] ) && is_array( $tank['building'] ) ? $tank['building'] : array() );
		}
		$note        = self::block( $label, $why, $slot );
		$note['fed'] = '';
		foreach ( (array) ( $tank['todo'] ?? array() ) as $item ) {
			if ( ! empty( $item['fed'] ) ) {
				$note['fed'] = (string) ( $item['note'] ?? '' );
				break;
			}
		}
		return $note;
	}

	/* ============================================================== */
	/*  The to-do without the feed                                     */
	/* ============================================================== */

	/**
	 * A fasting tank's to-do: the open Feed is taken off. A feed that was
	 * ticked already — before the order came in — stays as it is and says
	 * when it was fed.
	 *
	 * @param array[] $todo The tank's to-do (Protocol::todo()).
	 * @param array[] $rows The tank's entries of today (kind, note, logged_at UTC).
	 * @param array   $why  for_tank().
	 * @param array   $fast plan() (the orders a fed line may have come before).
	 * @param mixed   $tz   The site zone (null = the site's).
	 * @return array[]
	 */
	public static function without_feed( array $todo, array $rows, array $why, array $fast, $tz = null ) {
		$out = array();
		foreach ( $todo as $item ) {
			if ( 'feed' !== (string) ( $item['kind'] ?? '' ) ) {
				$out[] = $item;
				continue;
			}
			if ( empty( $item['done'] ) ) {
				continue; // Not fed, and not to be fed today.
			}
			$item['note'] = self::fed_text( self::ticked_at( (string) ( $item['key'] ?? '' ), $rows ), self::orders_of( $why, $fast ), $tz );
			$item['fed']  = true;
			$out[]        = $item;
		}
		return $out;
	}

	/**
	 * When a to-do item was first ticked today.
	 *
	 * @param string  $key  The item's key.
	 * @param array[] $rows The day's entries (note, logged_at).
	 * @return string UTC "Y-m-d H:i:s", '' when no entry ticks it.
	 */
	public static function ticked_at( $key, array $rows ) {
		$at = '';
		foreach ( $rows as $row ) {
			$logged = (string) ( $row['logged_at'] ?? '' );
			if ( '' === (string) $key || '' === $logged || Protocol::tick_key( (string) ( $row['note'] ?? '' ) ) !== (string) $key ) {
				continue;
			}
			if ( '' === $at || strcmp( $logged, $at ) < 0 ) {
				$at = $logged;
			}
		}
		return $at;
	}

	/**
	 * The orders (number => created) that make a water fast.
	 *
	 * @param array $why  for_tank() / for_building().
	 * @param array $fast plan().
	 * @return array<string, int>
	 */
	public static function orders_of( array $why, array $fast ) {
		$lines = (array) ( $why['lines'] ?? array() );
		if ( ! empty( $why['all'] ) ) {
			$lines = array_merge( $lines, (array) ( $fast['unlocated']['lines'] ?? array() ) );
		}
		$out = array();
		foreach ( $lines as $line ) {
			$number = (string) ( $line['number'] ?? '' );
			if ( '' !== $number ) {
				$out[ $number ] = (int) ( $line['created'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * "Fed at 7:40, before order #1251 came in." — the first order that
	 * came in after the feed; "Fed at 7:40." when none did.
	 *
	 * @param string             $ticked_at UTC "Y-m-d H:i:s" ('' = not known).
	 * @param array<string, int> $orders    Order number => when it was placed (UNIX time).
	 * @param mixed              $tz        The site zone (null = the site's).
	 * @return string
	 */
	public static function fed_text( $ticked_at, array $orders, $tz = null ) {
		$tz = $tz instanceof \DateTimeZone ? $tz : Clean_Room::timezone();
		try {
			$at = '' !== (string) $ticked_at ? new \DateTimeImmutable( (string) $ticked_at, new \DateTimeZone( 'UTC' ) ) : null;
		} catch ( \Throwable $e ) {
			$at = null;
		}
		if ( null === $at ) {
			return __( 'Fed before the order came in.', 'fishotel-misc-plugin' );
		}

		$time  = $at->setTimezone( $tz )->format( 'g:i' );
		$after = null;
		foreach ( $orders as $number => $created ) {
			if ( (int) $created > $at->getTimestamp() && ( null === $after || (int) $created < $after[1] ) ) {
				$after = array( (string) $number, (int) $created );
			}
		}
		if ( null === $after ) {
			/* translators: %s: time of day, e.g. 7:40 */
			return sprintf( __( 'Fed at %s.', 'fishotel-misc-plugin' ), $time );
		}
		/* translators: 1: time of day, e.g. 7:40, 2: order number */
		return sprintf( __( 'Fed at %1$s, before order #%2$s came in.', 'fishotel-misc-plugin' ), $time, $after[0] );
	}

	/* ============================================================== */
	/*  The words                                                      */
	/* ============================================================== */

	/**
	 * "a, b and c".
	 *
	 * @param string[] $parts Parts.
	 * @return string
	 */
	public static function sentence_list( array $parts ) {
		$parts = array_values( array_filter( array_map( 'strval', $parts ), 'strlen' ) );
		if ( count( $parts ) < 2 ) {
			return $parts ? $parts[0] : '';
		}
		$last = array_pop( $parts );
		/* translators: 1: a list, comma separated, 2: its last entry */
		return sprintf( __( '%1$s and %2$s', 'fishotel-misc-plugin' ), implode( ', ', $parts ), $last );
	}

	/**
	 * The fish of a sentence: "2 × Yellow Tang (tank 121, #1234), 1 ×
	 * Flame Angel (tank 118, #1240)" — the first LIST_MAX of them, then
	 * "and 6 more fish": the FISH of the lines left out, so the sentence
	 * adds up to the number it opened with. A fish that is in another
	 * clean system too says so; one the site only guesses in quarantine
	 * names that tank.
	 *
	 * @param array[] $lines Lines (qty, name, number, tanks, also, quarantine).
	 * @return string
	 */
	public static function lines_text( array $lines ) {
		$parts = array();
		foreach ( array_slice( $lines, 0, self::LIST_MAX ) as $line ) {
			$where = array();
			$tanks = array_values( array_filter( array_map( 'intval', (array) ( $line['tanks'] ?? array() ) ) ) );
			if ( 1 === count( $tanks ) ) {
				/* translators: %d: tank number */
				$where[] = sprintf( __( 'tank %d', 'fishotel-misc-plugin' ), $tanks[0] );
			} elseif ( $tanks ) {
				/* translators: %s: tank numbers, e.g. "121 and 122" */
				$where[] = sprintf( __( 'tanks %s', 'fishotel-misc-plugin' ), self::sentence_list( array_map( 'strval', $tanks ) ) );
			}
			$also = array_values( array_filter( array_map( 'strval', (array) ( $line['also'] ?? array() ) ), 'strlen' ) );
			if ( $tanks && $also ) {
				/* translators: %s: one or more system names, e.g. "Clean Room 3" */
				$where[] = sprintf( __( 'also in %s', 'fishotel-misc-plugin' ), self::sentence_list( $also ) );
			}
			if ( '' !== (string) ( $line['number'] ?? '' ) ) {
				$where[] = '#' . (string) $line['number'];
			}
			$quarantine = array_values( array_filter( array_map( 'intval', (array) ( $line['quarantine'] ?? array() ) ) ) );
			if ( ! $tanks && $quarantine ) {
				/* translators: %s: one or more tank numbers, e.g. "512" or "512 and 513" */
				$where[] = sprintf( _n( 'on the site only in quarantine tank %s', 'on the site only in quarantine tanks %s', count( $quarantine ), 'fishotel-misc-plugin' ), self::sentence_list( array_map( 'strval', $quarantine ) ) );
			}
			$name    = '' !== trim( (string) ( $line['name'] ?? '' ) ) ? trim( (string) $line['name'] ) : __( 'a fish without a name', 'fishotel-misc-plugin' );
			$parts[] = (int) ( $line['qty'] ?? 0 ) . ' × ' . $name . ( $where ? ' (' . implode( ', ', $where ) . ')' : '' );
		}
		$text = implode( ', ', $parts );
		$more = 0;
		foreach ( array_slice( $lines, self::LIST_MAX ) as $line ) {
			$more += max( 0, (int) ( $line['qty'] ?? 0 ) );
		}
		if ( $more > 0 ) {
			/* translators: %d: how many more fish there are than the sentence names */
			$text .= ' ' . sprintf( __( 'and %d more fish', 'fishotel-misc-plugin' ), $more );
		}
		return $text;
	}

	/**
	 * What a fasting system's block says, and the same on its Board card:
	 * "Clean Room 2 — no feeding today. 3 fish ship from this system:
	 * 2 × Yellow Tang (tank 121, #1234), 1 × Flame Angel (tank 118,
	 * #1240)." A fish whose group is in another clean system too may come
	 * out of either: both go without, neither counts it as surely its own,
	 * and the fish names the other one — "1 fish ships from this system
	 * and 2 more may: 1 × Flame Angel (tank 118, #1240), 2 × Yellow Tang
	 * (tank 121, also in Clean Room 3, #1234)." A system that fasts only
	 * because a shipping fish is in no tank the site knows says that
	 * instead.
	 *
	 * @param string $label The system's (or the temporary tank's) name.
	 * @param array  $why   for_building() / for_tank().
	 * @param bool   $tank  Whether it is one temporary tank ("from this tank").
	 * @return array{text: string, strip: string, fish: int, all: bool}
	 *         text: the reason line; strip: the phone's tank strip; fish: the fish that surely ship from here.
	 */
	public static function block( $label, array $why, $tank = false ) {
		$fish  = (int) ( $why['fish'] ?? 0 );
		$maybe = (int) ( $why['maybe'] ?? 0 );
		$lines = (array) ( $why['lines'] ?? array() );

		if ( ( $fish + $maybe ) > 0 && $lines ) {
			// The fish that surely ship from here first, then the ones that may.
			$sure = array();
			$may  = array();
			foreach ( $lines as $line ) {
				if ( empty( $line['also'] ) ) {
					$sure[] = $line;
				} else {
					$may[] = $line;
				}
			}
			$list = self::lines_text( array_merge( $sure, $may ) );
			if ( $maybe > 0 && $fish > 0 && $tank ) {
				/* translators: 1: tank name, 2: fish that surely ship from it, 3: fish that may, 4: the fish */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish ships from this tank and %3$d more may: %4$s.', '%1$s — no feeding today. %2$d fish ship from this tank and %3$d more may: %4$s.', $fish, 'fishotel-misc-plugin' ), (string) $label, $fish, $maybe, $list );
			} elseif ( $maybe > 0 && $fish > 0 ) {
				/* translators: 1: building name, 2: fish that surely ship from it, 3: fish that may, 4: the fish, e.g. "2 × Yellow Tang (tank 121, also in Clean Room 3, #1234)" */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish ships from this system and %3$d more may: %4$s.', '%1$s — no feeding today. %2$d fish ship from this system and %3$d more may: %4$s.', $fish, 'fishotel-misc-plugin' ), (string) $label, $fish, $maybe, $list );
			} elseif ( $maybe > 0 && $tank ) {
				/* translators: 1: tank name, 2: number of fish, 3: the fish, e.g. "2 × Yellow Tang (tank 901, also in Clean Room 3, #1234)" */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish may ship from this tank: %3$s.', '%1$s — no feeding today. %2$d fish may ship from this tank: %3$s.', $maybe, 'fishotel-misc-plugin' ), (string) $label, $maybe, $list );
			} elseif ( $maybe > 0 ) {
				/* translators: 1: building name, 2: number of fish, 3: the fish, e.g. "2 × Yellow Tang (tank 121, also in Clean Room 3, #1234)" */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish may ship from this system: %3$s.', '%1$s — no feeding today. %2$d fish may ship from this system: %3$s.', $maybe, 'fishotel-misc-plugin' ), (string) $label, $maybe, $list );
			} elseif ( $tank ) {
				/* translators: 1: tank name, 2: number of fish, 3: the fish, e.g. "2 × Yellow Tang (tank 901, #1234)" */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish ships from this tank: %3$s.', '%1$s — no feeding today. %2$d fish ship from this tank: %3$s.', $fish, 'fishotel-misc-plugin' ), (string) $label, $fish, $list );
			} else {
				/* translators: 1: building name, 2: number of fish, 3: the fish, e.g. "2 × Yellow Tang (tank 121, #1234)" */
				$text = sprintf( _n( '%1$s — no feeding today. %2$d fish ships from this system: %3$s.', '%1$s — no feeding today. %2$d fish ship from this system: %3$s.', $fish, 'fishotel-misc-plugin' ), (string) $label, $fish, $list );
			}
			$strip = __( 'No feeding today — fish ship from here.', 'fishotel-misc-plugin' );
		} else {
			/* translators: %s: building name */
			$text  = sprintf( __( '%s — no feeding today. A fish ships today and the site doesn\'t know its tank, so no Clean Room system is fed.', 'fishotel-misc-plugin' ), (string) $label );
			$strip = __( 'No feeding today — a fish ships and the site doesn\'t know its tank.', 'fishotel-misc-plugin' );
		}

		return array(
			'text'  => $text,
			'strip' => $strip,
			'fish'  => $fish,
			'all'   => ! empty( $why['all'] ),
		);
	}

	/**
	 * The sentence at the top of the day's list when a shipping fish is
	 * in no clean tank the site knows: "No feeding in the Clean Room
	 * systems today — 4 fish ship and the site doesn't know their tank:
	 * 2 × Blue Tang (#1250), 2 × Royal Gramma (#1251)." — '' on any other
	 * day. ("Systems", not "tanks": a clean temporary tank is its own
	 * water and is fed unless a shipping fish is found in it.)
	 *
	 * @param array $fast plan().
	 * @return string
	 */
	public static function list_notice( array $fast ) {
		if ( ! self::is_on( $fast ) || empty( $fast['all'] ) ) {
			return '';
		}
		$fish = (int) ( $fast['unlocated']['fish'] ?? 0 );
		/* translators: 1: number of fish, 2: the fish, e.g. "2 × Blue Tang (#1250)" */
		return sprintf( _n( 'No feeding in the Clean Room systems today — %1$d fish ships and the site doesn\'t know its tank: %2$s.', 'No feeding in the Clean Room systems today — %1$d fish ship and the site doesn\'t know their tank: %2$s.', $fish, 'fishotel-misc-plugin' ), $fish, self::lines_text( (array) ( $fast['unlocated']['lines'] ?? array() ) ) );
	}

	/**
	 * How to end it — said once, under list_notice(). A fish that is on
	 * the site already — under its supplier's spelling, or still in the
	 * quarantine tank it arrived in — is corrected or moved first: adding
	 * it again would count it twice.
	 *
	 * @return string
	 */
	public static function list_hint() {
		return __( 'If one is already in a tank on the site under another name, or still in a quarantine tank there, correct or move that group (Identify… / Correct the fish… / Move) — do not add it again. Otherwise add it to the tank it is in (on the phone: Add fish already here). Then only that system goes without.', 'fishotel-misc-plugin' );
	}

	/**
	 * The few words another screen adds to the count: "no feeding in
	 * Clean Room 2 (ship day)" — '' on a day everything is fed.
	 *
	 * @param array    $fast   plan().
	 * @param string[] $labels The names of the fasting systems and temporary tanks that hold fish.
	 * @return string
	 */
	public static function today_words( array $fast, array $labels ) {
		if ( ! self::is_on( $fast ) ) {
			return '';
		}
		if ( ! empty( $fast['all'] ) ) {
			return __( 'no feeding in the Clean Room systems (ship day)', 'fishotel-misc-plugin' );
		}
		$labels = array_values( array_unique( array_filter( array_map( 'strval', $labels ), 'strlen' ) ) );
		if ( ! $labels ) {
			return '';
		}
		/* translators: %s: one or more building names */
		return sprintf( __( 'no feeding in %s (ship day)', 'fishotel-misc-plugin' ), self::sentence_list( $labels ) );
	}

	/**
	 * The two sentences under the tick in Settings, and — when the rule
	 * cannot run — why.
	 *
	 * @param string $state One of the STATE_ constants (what state() says with the tick on).
	 * @return array{label: string, text: string, why: string}
	 */
	public static function setting_text( $state ) {
		$why = '';
		if ( self::STATE_NO_BATCH === (string) $state ) {
			$why = __( 'It does nothing right now: the FisHotel Batch Manager plugin is not active, and that is what gives every order its ship day.', 'fishotel-misc-plugin' );
		} elseif ( self::STATE_NO_SHOP === (string) $state ) {
			$why = __( 'It does nothing right now: WooCommerce is not active, so there are no orders to read.', 'fishotel-misc-plugin' );
		}
		return array(
			'label' => __( 'Don\'t feed a clean system on a day its fish ship', 'fishotel-misc-plugin' ),
			'text'  => __( 'On a day a customer\'s fish go out the door, Feed is left off the to-do of the Clean Room system those fish are in, with the reason shown in its place; the Spa is fed as always. When the site does not know which Clean Room tank a shipping fish is in, no Clean Room system gets Feed that day and the To do tab names the fish — correct or move its group if it is on the site under another name or still in quarantine, else add it to its tank, and only that system goes without.', 'fishotel-misc-plugin' ),
			'why'   => $why,
		);
	}
}
