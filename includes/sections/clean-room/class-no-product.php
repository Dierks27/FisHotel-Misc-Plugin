<?php
/**
 * Clean Room — fish with no product yet.
 *
 * "Is there a way that we can mark any of the fish/products that don't
 * have anything tied to them yet so I can easily do that…"
 *
 * ONE RULE, used by every screen that marks such a fish:
 *
 *   a fish order line   has a product when Shop_Price::product_of() finds
 *                       one — the product its species-list row is linked
 *                       to, else the ONE fish product named exactly like
 *                       the line — and that product still exists;
 *   an open stay        has a product when its own product link points at
 *                       a product that still exists;
 *   a closed order      is history, and nothing here writes on it: a line
 *                       of one that was only TYPED with a name of the
 *                       species list reads its product through the row
 *                       of that name (through_list());
 *   "still exists"      a product in the trash, or deleted, is none
 *                       (alive()). A DRAFT is a product: the owner is
 *                       working on it;
 *   "??"                a fish nobody knows yet is a different case — it
 *                       needs identifying first — and is never counted as
 *                       a fish without a product (line_state() and
 *                       stay_state() say 'unknown').
 *
 * Lines and stays are gathered into FISH the way the section already
 * decides two lines are the same fish (Stats::fish_key()): the species-
 * list row — the line's own, or the row with the same name —, else the
 * name as typed, case and spacing aside. A stay goes with its order line
 * while it still carries the line's name, else by its own name. One row
 * per fish (gather()), never one per line. An order's own count and its
 * lines' tags go by the same gathering: a line whose group in the tank
 * lacks the link is marked too, so the count beside a fish order is the
 * list narrowed to that order.
 *
 * What matters NOW (is_current(), gather() without closed_too): a line of
 * a fish order that is a draft, ordered or received — or of a closed one
 * whose own group is still in a tank —, and every open stay. The lines of
 * closed orders are history: the list shows them behind a switch.
 *
 * FROM THE LIST TO A PRODUCT there are two acts, both built from what the
 * section already has:
 *
 *   link()        the species-list row of the fish (the one it has, else
 *                 a new one under the name it was typed with) is linked to
 *                 the product — Store::link_species_product(), the Species
 *                 tab's own "Link product" —, every line of the fish on an
 *                 order that is NOT closed, typed with the row's name,
 *                 gets the row's id, so the line reads the product through
 *                 it (a closed order's line is left as it is: the row's
 *                 per box and group would come with the id and rewrite the
 *                 order's numbers), and every open stay gets the
 *                 product link through Identify::stay(), which writes the
 *                 link and nothing else when the name and the size are
 *                 unchanged (no event, no label, the line untouched). The
 *                 name the fish was typed under needs no alias of its own:
 *                 a species row linked to a product folds into that
 *                 product's names in every search
 *                 (Species::merge_candidates()).
 *   draft_plan()  what a DRAFT product for the fish is made of — the name,
 *                 the scientific name, the fish category, one variation
 *                 per size seen on its lines and stays, each at the planned
 *                 price typed for THAT size or at none (never a guessed
 *                 one) — and draft_decision(): a second click opens the
 *                 first product instead of making a second; a product the
 *                 shop already has under the fish's name, in whatever
 *                 category, is never doubled (the act 'twin').
 *
 * Nothing here touches the database or WooCommerce: link() takes the store it
 * writes to, like Identify::stay(); the product itself is created by
 * Admin_No_Product, at the edge.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class No_Product
 */
class No_Product {

	/**
	 * The Species tab's view that lists these fish (?tab=species&view=…).
	 *
	 * @var string
	 */
	const VIEW = 'no-product';

	const PER_PAGE = 50;

	/**
	 * The post statuses of a product that is no product any more.
	 *
	 * @var string[]
	 */
	const GONE = array( 'trash', 'auto-draft' );

	/**
	 * The meta a draft started here carries: the name key of the fish it
	 * was started for — how a second click finds the first product.
	 *
	 * @var string
	 */
	const DRAFT_META = '_fh_cleanroom_fish';

	/**
	 * Where this shop keeps a fish product's scientific name (the theme's
	 * product page and the section's own fish search read it).
	 *
	 * @var string
	 */
	const SCI_META = '_fh_scientific_name';

	/**
	 * The category a fish product must carry itself: the theme's "is this
	 * a quarantined fish" test reads the product's own terms.
	 *
	 * @var string
	 */
	const FISH_CATEGORY = 'quarantined-fish';

	/* ============================================================== */
	/*  The rule                                                       */
	/* ============================================================== */

	/**
	 * The products that still exist, from what Store::product_statuses()
	 * read (pure): a product in the trash is none, and a product the read
	 * did not find was deleted. A draft, a private or a scheduled product
	 * is a product.
	 *
	 * @param array $statuses product id => post status.
	 * @return array<int, string> product id => post status, the existing ones only.
	 */
	public static function alive( array $statuses ) {
		$out = array();
		foreach ( $statuses as $id => $status ) {
			if ( (int) $id > 0 && is_string( $status ) && '' !== $status && ! in_array( $status, self::GONE, true ) ) {
				$out[ (int) $id ] = $status;
			}
		}
		return $out;
	}

	/**
	 * Whether a product id names a product that still exists.
	 *
	 * @param mixed      $product_id A product id.
	 * @param array|null $alive      alive(); null = nobody read the statuses: every id counts.
	 * @return bool
	 */
	public static function exists( $product_id, $alive ) {
		$id = is_scalar( $product_id ) ? (int) $product_id : 0;
		return $id > 0 && ( null === $alive || isset( $alive[ $id ] ) );
	}

	/**
	 * Whether a line or a stay is a fish nobody knows yet: flagged, or
	 * named with the "??" mark.
	 *
	 * @param array $row needs_id, species.
	 * @return bool
	 */
	public static function is_unknown( array $row ) {
		return ! empty( $row['needs_id'] ) || Identify::is_unknown_name( $row['species'] ?? '' );
	}

	/**
	 * The product of an order line (pure): Shop_Price::product_of() — the
	 * one its species-list row is linked to, else the ONE fish product
	 * named exactly like it — when that product still exists.
	 *
	 * @param array      $line  The line (needs_id, species_product_id, species).
	 * @param array      $names Shop_Price::name_index().
	 * @param array|null $alive alive().
	 * @return int|null
	 */
	public static function line_product( array $line, array $names, $alive = null ) {
		if ( self::is_unknown( $line ) ) {
			return null;
		}
		$id = Shop_Price::product_of( $line, $names );
		return null !== $id && self::exists( $id, $alive ) ? (int) $id : null;
	}

	/**
	 * The product of a stay (pure): its own product link, when that
	 * product still exists.
	 *
	 * @param array      $stay  The stay (product_id, needs_id, species).
	 * @param array|null $alive alive().
	 * @return int|null
	 */
	public static function stay_product( array $stay, $alive = null ) {
		if ( self::is_unknown( $stay ) ) {
			return null;
		}
		$id = (int) ( $stay['product_id'] ?? 0 );
		return self::exists( $id, $alive ) ? $id : null;
	}

	/**
	 * Where a line stands: 'unknown' (it needs identifying first),
	 * 'linked', or 'none' — a fish with no product yet.
	 *
	 * @param array      $line  The line.
	 * @param array      $names Shop_Price::name_index().
	 * @param array|null $alive alive().
	 * @return string unknown|linked|none.
	 */
	public static function line_state( array $line, array $names, $alive = null ) {
		if ( self::is_unknown( $line ) ) {
			return 'unknown';
		}
		return null !== self::line_product( $line, $names, $alive ) ? 'linked' : 'none';
	}

	/**
	 * Where a stay stands (as line_state()).
	 *
	 * @param array      $stay  The stay.
	 * @param array|null $alive alive().
	 * @return string unknown|linked|none.
	 */
	public static function stay_state( array $stay, $alive = null ) {
		if ( self::is_unknown( $stay ) ) {
			return 'unknown';
		}
		return null !== self::stay_product( $stay, $alive ) ? 'linked' : 'none';
	}

	/**
	 * Whether a line matters NOW (pure): its order is a draft, ordered or
	 * received — or it is closed but the line's own group is still in a
	 * tank. Every other line of a closed order is history.
	 *
	 * @param array $order The line's order (status).
	 * @param array $line  The line (stay_status: its own stay's status, when it has one).
	 * @return bool
	 */
	public static function is_current( array $order, array $line ) {
		if ( 'closed' !== (string) ( $order['status'] ?? '' ) ) {
			return true;
		}
		$stay = isset( $line['stay_status'] ) && null !== $line['stay_status'] ? (string) $line['stay_status'] : '';
		return ! empty( $line['stay_id'] ) && '' !== $stay && 'closed' !== $stay;
	}

	/**
	 * The species list's products by name (pure): the key of a row's name
	 * => the product the row is linked to, for the rows that have one.
	 *
	 * @param array[] $species Species rows (id, name, product_id).
	 * @return array<string, int>
	 */
	public static function listed_products( array $species ) {
		$out = array();
		foreach ( $species as $row ) {
			$key = is_array( $row ) ? Species::key( (string) ( $row['name'] ?? '' ) ) : '';
			if ( '' !== $key && ! isset( $out[ $key ] ) && (int) ( $row['product_id'] ?? 0 ) > 0 ) {
				$out[ $key ] = (int) $row['product_id'];
			}
		}
		return $out;
	}

	/**
	 * A line as the rule reads it (pure). A closed order is history:
	 * "Link to a product…" never writes on its lines — the species row's
	 * per box and group would come with the row's id and change what the
	 * order cost. So a closed order's line that was only TYPED with a name
	 * of the species list (it carries no row of its own) reads its product
	 * through the row of that name. Every other line is returned as it is.
	 *
	 * @param array              $order  The line's order (status).
	 * @param array              $line   The line.
	 * @param array<string, int> $listed listed_products().
	 * @return array The line.
	 */
	public static function through_list( array $order, array $line, array $listed ) {
		if ( ! $listed || 'closed' !== (string) ( $order['status'] ?? '' ) || (int) ( $line['species_id'] ?? 0 ) > 0 || (int) ( $line['species_product_id'] ?? 0 ) > 0 ) {
			return $line;
		}
		$key = Species::key( (string) ( $line['species'] ?? '' ) );
		if ( isset( $listed[ $key ] ) ) {
			$line['species_product_id'] = (int) $listed[ $key ];
		}
		return $line;
	}

	/**
	 * Whether a line's own group in a tank lacks a product (pure): the
	 * line was checked in, the group is still open and still carries the
	 * line's name — it is the line's fish —, and its own product link
	 * points at nothing that exists. The line itself may have a product by
	 * its name all the same; the list then offers "Link to it".
	 *
	 * @param array      $line  The line (stay_id, stay_status, stay_species, stay_product_id).
	 * @param array|null $alive alive().
	 * @return bool
	 */
	public static function group_lacks( array $line, $alive = null ) {
		$status = isset( $line['stay_status'] ) && null !== $line['stay_status'] ? (string) $line['stay_status'] : '';
		if ( empty( $line['stay_id'] ) || '' === $status || 'closed' === $status || ! isset( $line['stay_species'] ) || self::is_unknown( $line ) ) {
			return false;
		}
		return Identify::same_fish( $line['species'] ?? '', $line['stay_species'] ) && ! self::exists( $line['stay_product_id'] ?? 0, $alive );
	}

	/**
	 * Every product id the rule has to ask about, for ONE read of their
	 * statuses (pure).
	 *
	 * @param array[]            $orders Orders, each with 'lines'.
	 * @param array[]            $stays  Stays.
	 * @param array              $names  Shop_Price::name_index().
	 * @param array<string, int> $listed listed_products().
	 * @return int[]
	 */
	public static function product_ids( array $orders, array $stays, array $names, array $listed = array() ) {
		$ids = array();
		foreach ( $orders as $order ) {
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				$id = is_array( $line ) && ! self::is_unknown( $line ) ? Shop_Price::product_of( self::through_list( (array) $order, $line, $listed ), $names ) : null;
				if ( null !== $id ) {
					$ids[ (int) $id ] = true;
				}
				if ( is_array( $line ) && (int) ( $line['stay_product_id'] ?? 0 ) > 0 ) {
					$ids[ (int) $line['stay_product_id'] ] = true;
				}
			}
		}
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) && (int) ( $stay['product_id'] ?? 0 ) > 0 ) {
				$ids[ (int) $stay['product_id'] ] = true;
			}
		}
		return array_keys( $ids );
	}

	/* ============================================================== */
	/*  The same fish                                                  */
	/* ============================================================== */

	/**
	 * Which fish a stay is (pure): its order line's fish while the stay
	 * still carries the line's name, else the species-list row with its
	 * name, else its name as typed.
	 *
	 * @param array              $stay      species, order_line_id.
	 * @param array<string, int> $species   Stats::species_index()['names'].
	 * @param array[]            $line_keys line id => array( key, name ) of the lines at hand.
	 * @return string "s:<id>" or "n:<name>".
	 */
	public static function stay_key( array $stay, array $species = array(), array $line_keys = array() ) {
		$line_id = (int) ( $stay['order_line_id'] ?? 0 );
		if ( $line_id > 0 && isset( $line_keys[ $line_id ] ) && Identify::same_fish( $line_keys[ $line_id ][1], $stay['species'] ?? '' ) ) {
			return (string) $line_keys[ $line_id ][0];
		}
		$name = Species::key( (string) ( $stay['species'] ?? '' ) );
		return isset( $species[ $name ] ) ? 's:' . (int) $species[ $name ] : 'n:' . $name;
	}

	/* ============================================================== */
	/*  The marks: an order's lines, an order's count                  */
	/* ============================================================== */

	/**
	 * The lines of an order that are marked "no product yet" (pure): a
	 * known fish that matters now, and no product — on the line itself,
	 * or on its own group in the tank (group_lacks()): the list shows that
	 * fish for this order too.
	 *
	 * @param array              $order  The order with its lines.
	 * @param array              $names  Shop_Price::name_index().
	 * @param array|null         $alive  alive().
	 * @param array<string, int> $listed listed_products().
	 * @return array<int, bool> Index of the line in $order['lines'] => true.
	 */
	public static function marked_lines( array $order, array $names, $alive = null, array $listed = array() ) {
		$out = array();
		foreach ( array_values( (array) ( $order['lines'] ?? array() ) ) as $index => $line ) {
			if ( ! is_array( $line ) || ! self::is_current( $order, $line ) ) {
				continue;
			}
			$state = self::line_state( self::through_list( $order, $line, $listed ), $names, $alive );
			if ( 'none' === $state || ( 'linked' === $state && self::group_lacks( $line, $alive ) ) ) {
				$out[ $index ] = true;
			}
		}
		return $out;
	}

	/**
	 * How many FISH of an order have no product yet (pure) — the same fish
	 * in two sizes, or on two lines, is one.
	 *
	 * @param array              $order   The order with its lines.
	 * @param array              $names   Shop_Price::name_index().
	 * @param array|null         $alive   alive().
	 * @param array<string, int> $species Stats::species_index()['names'].
	 * @param array<string, int> $listed  listed_products().
	 * @return int
	 */
	public static function order_count( array $order, array $names, $alive = null, array $species = array(), array $listed = array() ) {
		$lines = array_values( (array) ( $order['lines'] ?? array() ) );
		$fish  = array();
		foreach ( array_keys( self::marked_lines( $order, $names, $alive, $listed ) ) as $index ) {
			$fish[ Stats::fish_key( $lines[ $index ], $species ) ] = true;
		}
		return count( $fish );
	}

	/**
	 * Orders with the mark on them (pure): every line gets no_product
	 * (bool), the order no_product (its count of fish).
	 *
	 * @param array[]            $orders  Orders, each with 'lines'.
	 * @param array              $names   Shop_Price::name_index().
	 * @param array|null         $alive   alive().
	 * @param array<string, int> $species Stats::species_index()['names'].
	 * @param array<string, int> $listed  listed_products().
	 * @return array[]
	 */
	public static function mark_orders( array $orders, array $names, $alive = null, array $species = array(), array $listed = array() ) {
		foreach ( $orders as $o => $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$marked = self::marked_lines( $order, $names, $alive, $listed );
			foreach ( array_values( (array) ( $order['lines'] ?? array() ) ) as $index => $line ) {
				if ( is_array( $line ) ) {
					$orders[ $o ]['lines'][ $index ]               = $line;
					$orders[ $o ]['lines'][ $index ]['no_product'] = isset( $marked[ $index ] );
				}
			}
			$orders[ $o ]['no_product'] = self::order_count( $order, $names, $alive, $species, $listed );
		}
		return $orders;
	}

	/* ============================================================== */
	/*  The list                                                       */
	/* ============================================================== */

	/**
	 * Lines and stays gathered into fish (pure) — the rows of "Fish with
	 * no product yet", and the "??" fish in a group of their own.
	 *
	 * By default only what matters now: the lines is_current() keeps, and
	 * the open stays handed in. With closed_too every line of every order
	 * handed in. With order_id only that order's lines — what matters now
	 * of them, or with closed_too all — and the stays checked in from it:
	 * the rows the count beside that order counted.
	 *
	 * A group put in a tank by hand — the phone's Arrival of a fish picked
	 * as "on fish order …" — stands for no order line: it carries its fish
	 * order as a ref only (order_ref). It is that order's all the same: its
	 * row names the order, and takes the scientific name, the sizes and
	 * the landed cost of the order's line of the same fish. With order_id it joins
	 * the fish that order's lines put on the list (its tank, its fish
	 * alive) and adds no row of its own: the count counted the lines.
	 *
	 * @param array[] $orders Orders with their lines — resolved (Orders::resolve(): landed_per_fish,
	 *                        suggested_price) for the cost and the target price; without, those are null.
	 * @param array[] $stays  The open stays (hydrated).
	 * @param array   $ctx    names (Shop_Price::name_index()), alive (alive() or null), species (rows:
	 *                        id, name, sci_name, product_id), sizes (the standard sizes in their order),
	 *                        closed_too (bool), order_id (int), ref_orders (orders that are not among
	 *                        $orders and that a stay names by its ref — closed ones, as a rule —, with
	 *                        their lines when the cost is wanted: they name the order, and make no row).
	 * @return array{fish: array[], unknown: array[]} fish: key, name, sci_name, species_id, sizes
	 *               (slug, label), orders (id, ref, status), tanks (numbers), alive, in_tank, line_ids,
	 *               stay_ids, cost (float|null), target (float|null), cost_ref, planned (float|null — the
	 *               newest planned price), planned_sizes (the planned price typed for each size: slug ('' =
	 *               a line without a size), label, price), candidate (a product the species list or the
	 *               shop already has under this name: id|null). unknown: kind (stay|line), id, name,
	 *               size_label, order_id, order_ref, tank, alive, size_slug.
	 */
	public static function gather( array $orders, array $stays, array $ctx = array() ) {
		$names      = isset( $ctx['names'] ) && is_array( $ctx['names'] ) ? $ctx['names'] : array();
		$alive      = array_key_exists( 'alive', $ctx ) && is_array( $ctx['alive'] ) ? $ctx['alive'] : null;
		$index      = Stats::species_index( isset( $ctx['species'] ) && is_array( $ctx['species'] ) ? $ctx['species'] : array() );
		$listed     = self::listed_products( $index['rows'] );
		$closed_too = ! empty( $ctx['closed_too'] );
		$only       = isset( $ctx['order_id'] ) ? (int) $ctx['order_id'] : 0;

		$fish      = array();
		$unknown   = array();
		$line_keys = array();
		$line_info = array(); // Order line id => what a group in a tank takes from its line: the scientific name, the order.
		$open_line = array(); // Order line id => true: an open stay stands for it.
		foreach ( $stays as $stay ) {
			if ( is_array( $stay ) && (int) ( $stay['order_line_id'] ?? 0 ) > 0 ) {
				$open_line[ (int) $stay['order_line_id'] ] = true;
			}
		}
		$by_ref   = array(); // An order's ref => the order, for a group that carries its fish order as a ref only.
		$of_order = array(); // Order id => fish key => what that order's line knows of the fish (line_facts()).
		foreach ( isset( $ctx['ref_orders'] ) && is_array( $ctx['ref_orders'] ) ? $ctx['ref_orders'] : array() as $order ) {
			if ( ! is_array( $order ) || (int) ( $order['id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$brief = self::order_brief( $order );
			if ( '' !== self::ref_key( $brief['ref'] ) ) {
				$by_ref[ self::ref_key( $brief['ref'] ) ] = $brief;
			}
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$line = self::through_list( $order, $line, $listed );
				if ( 'unknown' !== self::line_state( $line, $names, $alive ) ) {
					self::note_line( $of_order, $brief['id'], Stats::fish_key( $line, $index['names'] ), $line );
				}
			}
		}

		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}
			$order_id = (int) ( $order['id'] ?? 0 );
			$when     = Stats::order_date( $order ) . '#' . str_pad( (string) $order_id, 10, '0', STR_PAD_LEFT );
			$brief    = self::order_brief( $order );
			if ( $order_id > 0 && '' !== self::ref_key( $brief['ref'] ) ) {
				$by_ref[ self::ref_key( $brief['ref'] ) ] = $brief; // The order at hand, whatever ref_orders said.
			}
			foreach ( (array) ( $order['lines'] ?? array() ) as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$line    = self::through_list( $order, $line, $listed );
				$line_id = (int) ( $line['id'] ?? 0 );
				$state   = self::line_state( $line, $names, $alive );
				$key     = 'unknown' === $state ? '' : Stats::fish_key( $line, $index['names'] );
				if ( '' !== $key && $line_id > 0 ) {
					$line_keys[ $line_id ] = array( $key, (string) ( $line['species'] ?? '' ) );
					$line_info[ $line_id ] = array(
						'sci_name' => trim( (string) ( $line['sci_name'] ?? '' ) ),
						'order'    => $brief,
					);
				}
				if ( '' !== $key && $order_id > 0 ) {
					self::note_line( $of_order, $order_id, $key, $line );
				}
				if ( 'linked' === $state || ( $only > 0 && $only !== $order_id ) || ( ! $closed_too && ! self::is_current( $order, $line ) ) ) {
					continue;
				}
				if ( 'unknown' === $state ) {
					if ( ! isset( $open_line[ $line_id ] ) ) {
						$unknown[] = array(
							'kind'       => 'line',
							'id'         => $line_id,
							'name'       => (string) ( $line['species'] ?? '' ),
							'size_slug'  => (string) ( $line['size_slug'] ?? '' ),
							'size_label' => (string) ( $line['size_label'] ?? '' ),
							'order_id'   => $order_id,
							'order_ref'  => $brief['ref'],
							'tank'       => null,
							'alive'      => 0,
						);
					}
					continue;
				}

				if ( ! isset( $fish[ $key ] ) ) {
					$fish[ $key ] = self::blank_row( $key, $index );
				}
				$row               = &$fish[ $key ];
				$row['line_ids'][] = $line_id;
				if ( $order_id > 0 ) {
					$row['orders'][ $order_id ] = $brief;
				}
				self::add_size( $row, $line );
				// The newest order names the fish and gives its scientific name; an older one fills a gap.
				if ( '' === $row['name'] || $when > $row['named_at'] ) {
					$row['name']     = (string) ( $line['species'] ?? '' );
					$row['named_at'] = $when;
				}
				$sci = trim( (string) ( $line['sci_name'] ?? '' ) );
				if ( '' !== $sci && ( '' === $row['sci_name'] || $when > $row['sci_at'] ) ) {
					$row['sci_name'] = $sci;
					$row['sci_at']   = $when;
				}
				$facts = self::line_facts( $line );
				$cost  = $facts['cost'];
				if ( null !== $cost && $when > $row['cost_at'] ) {
					$row['cost']     = $cost;
					$row['target']   = $facts['target'];
					$row['cost_ref'] = $brief['ref'];
					$row['cost_at']  = $when;
				}
				// A price typed with the pencil on an order that is still open: a planned price. (A
				// closed order's price is the one it was closed with, which nobody planned.) It was
				// typed on a line of ONE size, and is the planned price of that size only.
				$typed = isset( $line['worth'] ) && is_numeric( $line['worth'] ) ? (float) $line['worth'] : 0.0;
				if ( $typed > 0 && 'closed' !== $brief['status'] ) {
					if ( $when > $row['planned_at'] ) {
						$row['planned']    = $typed;
						$row['planned_at'] = $when;
					}
					$slug = isset( $line['size_slug'] ) && null !== $line['size_slug'] ? trim( (string) $line['size_slug'] ) : '';
					if ( ! isset( $row['planned_sizes'][ $slug ] ) || $when > $row['planned_sizes'][ $slug ]['at'] ) {
						$row['planned_sizes'][ $slug ] = array(
							'slug'  => $slug,
							'price' => $typed,
							'at'    => $when,
						);
					}
				}
				unset( $row );
			}
		}

		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) ) {
				continue;
			}
			// Its fish order: the order of its line — or, for a group that stands for no line, the order its ref names.
			$batch_id = (int) ( $stay['batch_order_id'] ?? 0 );
			$named    = $batch_id > 0 ? null : self::named_order( $stay, $by_ref );
			$of_id    = $batch_id > 0 ? $batch_id : ( $named ? (int) $named['id'] : 0 );
			if ( $only > 0 && $only !== $of_id ) {
				continue;
			}
			$state = self::stay_state( $stay, $alive );
			if ( 'linked' === $state ) {
				continue;
			}
			$tank = (int) ( $stay['tank_number'] ?? 0 );
			if ( 'unknown' === $state ) {
				$unknown[] = array(
					'kind'       => 'stay',
					'id'         => (int) ( $stay['id'] ?? 0 ),
					'name'       => (string) ( $stay['species'] ?? '' ),
					'size_slug'  => (string) ( $stay['size_slug'] ?? '' ),
					'size_label' => (string) ( $stay['size_label'] ?? '' ),
					'order_id'   => $of_id,
					'order_ref'  => $named ? (string) $named['ref'] : (string) ( $stay['batch_order_ref'] ?? '' ),
					'tank'       => $tank > 0 ? $tank : null,
					'alive'      => (int) ( $stay['qty_live'] ?? 0 ),
				);
				continue;
			}

			$key = self::stay_key( $stay, $index['names'], $line_keys );
			if ( ! isset( $fish[ $key ] ) ) {
				if ( $only > 0 && $named ) {
					continue; // One order's rows are the fish its count counted: a group by hand joins one, and adds none.
				}
				$fish[ $key ] = self::blank_row( $key, $index );
			}
			$row               = &$fish[ $key ];
			$row['stay_ids'][] = (int) ( $stay['id'] ?? 0 );
			$row['alive']     += (int) ( $stay['qty_live'] ?? 0 );
			$row['in_tank']    = true;
			if ( $tank > 0 ) {
				$row['tanks'][ $tank ] = $tank;
			}
			// Its own line, when it is at hand and the group still is the line's fish: the line may have a
			// product by its name (it is on no row then), and still knows the scientific name and the order.
			$line_id = (int) ( $stay['order_line_id'] ?? 0 );
			$of_line = $line_id > 0 && isset( $line_info[ $line_id ], $line_keys[ $line_id ] ) && $line_keys[ $line_id ][0] === $key ? $line_info[ $line_id ] : null;
			if ( $of_line && '' === $row['sci_name'] ) {
				$row['sci_name'] = $of_line['sci_name'];
			}
			if ( $of_line && (int) $of_line['order']['id'] > 0 && ! isset( $row['orders'][ (int) $of_line['order']['id'] ] ) ) {
				$row['orders'][ (int) $of_line['order']['id'] ] = $of_line['order'];
			}
			if ( $batch_id > 0 && ! isset( $row['orders'][ $batch_id ] ) ) {
				$row['orders'][ $batch_id ] = array(
					'id'     => $batch_id,
					'ref'    => (string) ( $stay['batch_order_ref'] ?? '' ),
					'status' => '',
				);
			}
			// A group by hand: the order its ref names, and what that order's line of this fish knows.
			$facts = $named && isset( $of_order[ (int) $named['id'] ][ $key ] ) ? $of_order[ (int) $named['id'] ][ $key ] : null;
			if ( $named && ! isset( $row['orders'][ (int) $named['id'] ] ) ) {
				$row['orders'][ (int) $named['id'] ] = $named;
			}
			if ( $facts && '' === $row['sci_name'] ) {
				$row['sci_name'] = $facts['sci_name'];
			}
			foreach ( $facts ? $facts['sizes'] : array() as $size ) {
				self::add_size( $row, $size ); // The sizes the order named it in: the row reads the same once the order is closed.
			}
			if ( $facts && null === $row['cost'] && null !== $facts['cost'] ) {
				$row['cost']     = $facts['cost'];
				$row['target']   = $facts['target'];
				$row['cost_ref'] = (string) $named['ref'];
			}
			self::add_size( $row, $stay );
			if ( '' === $row['name'] ) {
				$row['name'] = (string) ( $stay['species'] ?? '' );
			}
			if ( null === $row['cost'] && isset( $stay['landed_cost'] ) && is_numeric( $stay['landed_cost'] ) && (float) $stay['landed_cost'] > 0 ) {
				// No order line at hand (its order is closed): what the stay was checked in with — a stay's landed cost is per fish.
				$row['cost']     = (float) $stay['landed_cost'];
				$row['cost_ref'] = (string) ( $stay['batch_order_ref'] ?? ( $stay['order_ref'] ?? '' ) );
			}
			unset( $row );
		}

		$sizes = isset( $ctx['sizes'] ) && is_array( $ctx['sizes'] ) ? $ctx['sizes'] : array();
		$rows  = array();
		foreach ( $fish as $row ) {
			$row['candidate'] = self::candidate( $row, $index, $names, $alive );
			$row['sizes']     = self::order_sizes( $row['sizes'], $sizes );
			$seen             = array_column( $row['sizes'], 'label', 'slug' );
			// The planned prices in the sizes' own order, the one typed on a line without a size last.
			$planned = array();
			foreach ( array_merge( array_column( $row['sizes'], 'slug' ), array( '' ) ) as $slug ) {
				if ( isset( $row['planned_sizes'][ $slug ] ) ) {
					$planned[] = array(
						'slug'  => (string) $slug,
						'label' => '' !== (string) $slug && isset( $seen[ $slug ] ) ? (string) $seen[ $slug ] : '',
						'price' => (float) $row['planned_sizes'][ $slug ]['price'],
					);
				}
			}
			$row['planned_sizes'] = $planned;
			$row['orders']    = array_values( $row['orders'] );
			sort( $row['tanks'] );
			$row['tanks'] = array_values( $row['tanks'] );
			unset( $row['named_at'], $row['sci_at'], $row['cost_at'], $row['planned_at'] );
			$rows[] = $row;
		}

		return array(
			'fish'    => self::sort( $rows ),
			'unknown' => self::sort_unknown( $unknown ),
		);
	}

	/**
	 * An order as a fish's row names it (pure).
	 *
	 * @param array $order id, ref, status.
	 * @return array{id: int, ref: string, status: string}
	 */
	private static function order_brief( array $order ) {
		return array(
			'id'     => (int) ( $order['id'] ?? 0 ),
			'ref'    => (string) ( $order['ref'] ?? '' ),
			'status' => (string) ( $order['status'] ?? '' ),
		);
	}

	/**
	 * A fish order's ref as two spellings of it are held together (pure):
	 * the case and the spaces around it aside.
	 *
	 * @param mixed $ref The ref.
	 * @return string
	 */
	public static function ref_key( $ref ) {
		return is_scalar( $ref ) ? strtolower( trim( (string) $ref ) ) : '';
	}

	/**
	 * The fish order a group that stands for no order line names by its
	 * own ref (pure) — the phone's Arrival of a fish picked as "on fish
	 * order …" writes the ref, and the Stays tab lets it be typed.
	 *
	 * @param array   $stay   batch_order_id, order_ref.
	 * @param array[] $by_ref ref_key() => the order (id, ref, status).
	 * @return array|null The order, or null: the group has its line's order, no ref, or a ref no order at hand has.
	 */
	public static function named_order( array $stay, array $by_ref ) {
		if ( (int) ( $stay['batch_order_id'] ?? 0 ) > 0 ) {
			return null;
		}
		$ref = self::ref_key( $stay['order_ref'] ?? '' );
		return '' !== $ref && isset( $by_ref[ $ref ] ) ? $by_ref[ $ref ] : null;
	}

	/**
	 * The refs the groups by hand name and no order at hand has (pure):
	 * what the list reads besides its orders, so that such a group still
	 * names its fish order once that order is closed.
	 *
	 * @param array[] $stays  Open stays.
	 * @param array[] $orders The orders at hand (ref).
	 * @param int     $limit  At most this many refs.
	 * @return string[] Refs as the stays spell them, one per order.
	 */
	public static function missing_refs( array $stays, array $orders, $limit = 200 ) {
		$have = array();
		foreach ( $orders as $order ) {
			if ( is_array( $order ) ) {
				$have[ self::ref_key( $order['ref'] ?? '' ) ] = true;
			}
		}
		$refs = array();
		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) || (int) ( $stay['batch_order_id'] ?? 0 ) > 0 ) {
				continue;
			}
			$ref = self::ref_key( $stay['order_ref'] ?? '' );
			if ( '' !== $ref && ! isset( $have[ $ref ] ) && ! isset( $refs[ $ref ] ) && count( $refs ) < (int) $limit ) {
				$refs[ $ref ] = trim( (string) $stay['order_ref'] );
			}
		}
		return array_values( $refs );
	}

	/**
	 * What an order's line knows of its fish besides the name (pure).
	 *
	 * @param array $line sci_name, size_slug, size_label, landed_per_fish, suggested_price (a resolved
	 *                    line; without, the last two are null).
	 * @return array{sci_name: string, cost: float|null, target: float|null, sizes: array[]}
	 */
	private static function line_facts( array $line ) {
		$slug = isset( $line['size_slug'] ) && null !== $line['size_slug'] ? trim( (string) $line['size_slug'] ) : '';
		return array(
			'sizes'    => '' !== $slug ? array( $slug => array( 'size_slug' => $slug, 'size_label' => (string) ( $line['size_label'] ?? '' ) ) ) : array(),
			'sci_name' => trim( (string) ( $line['sci_name'] ?? '' ) ),
			'cost'     => isset( $line['landed_per_fish'] ) && is_numeric( $line['landed_per_fish'] ) && (float) $line['landed_per_fish'] > 0 ? (float) $line['landed_per_fish'] : null,
			'target'   => isset( $line['suggested_price'] ) && is_numeric( $line['suggested_price'] ) && (float) $line['suggested_price'] > 0 ? (float) $line['suggested_price'] : null,
		);
	}

	/**
	 * Keep what a line knows of its fish under its order: the first line
	 * of the fish, a later one filling what it left empty.
	 *
	 * @param array  $of_order Order id => fish key => line_facts() (by reference).
	 * @param int    $order_id The order.
	 * @param string $key      The fish key.
	 * @param array  $line     The line.
	 */
	private static function note_line( array &$of_order, $order_id, $key, array $line ) {
		$facts = self::line_facts( $line );
		if ( ! isset( $of_order[ $order_id ][ $key ] ) ) {
			$of_order[ $order_id ][ $key ] = $facts;
			return;
		}
		$have           = &$of_order[ $order_id ][ $key ];
		$have['sizes'] += $facts['sizes'];
		if ( '' === $have['sci_name'] ) {
			$have['sci_name'] = $facts['sci_name'];
		}
		if ( null === $have['cost'] && null !== $facts['cost'] ) {
			$have['cost']   = $facts['cost'];
			$have['target'] = $facts['target'];
		}
		unset( $have );
	}

	/**
	 * An empty row for a fish; a species-list row gives its id and, until
	 * a line says otherwise, its name and scientific name.
	 *
	 * @param string $key   The fish key.
	 * @param array  $index Stats::species_index().
	 * @return array
	 */
	private static function blank_row( $key, array $index ) {
		$species_id = 0 === strpos( $key, 's:' ) ? (int) substr( $key, 2 ) : 0;
		$listed     = $species_id > 0 && isset( $index['rows'][ $species_id ] ) ? (array) $index['rows'][ $species_id ] : array();
		return array(
			'key'           => (string) $key,
			'name'          => '',
			'sci_name'      => trim( (string) ( $listed['sci_name'] ?? '' ) ),
			'species_id'    => $species_id > 0 ? $species_id : null,
			'sizes'         => array(),
			'orders'        => array(),
			'tanks'         => array(),
			'alive'         => 0,
			'in_tank'       => false,
			'line_ids'      => array(),
			'stay_ids'      => array(),
			'cost'          => null,
			'target'        => null,
			'cost_ref'      => '',
			'planned'       => null,
			'planned_sizes' => array(),
			'candidate'     => null,
			'named_at'      => '',
			'sci_at'        => '',
			'cost_at'       => '',
			'planned_at'    => '',
		);
	}

	/**
	 * Note the size of a line or a stay on a fish's row, once per slug.
	 *
	 * @param array $row    The row (by reference).
	 * @param array $source size_slug, size_label.
	 */
	private static function add_size( array &$row, array $source ) {
		$slug = isset( $source['size_slug'] ) && null !== $source['size_slug'] ? trim( (string) $source['size_slug'] ) : '';
		if ( '' === $slug || isset( $row['sizes'][ $slug ] ) ) {
			return;
		}
		$label                 = isset( $source['size_label'] ) && '' !== trim( (string) $source['size_label'] ) ? (string) $source['size_label'] : $slug;
		$row['sizes'][ $slug ] = array(
			'slug'  => $slug,
			'label' => $label,
		);
	}

	/**
	 * The sizes seen, in the standard sizes' own order; a size that left
	 * the list comes after them, by its label (pure).
	 *
	 * @param array[] $seen     slug => array( slug, label ).
	 * @param array[] $standard The standard sizes (slug, label) in their order.
	 * @return array[] slug, label, standard (bool).
	 */
	public static function order_sizes( array $seen, array $standard ) {
		$out = array();
		foreach ( $standard as $size ) {
			$slug = is_array( $size ) ? (string) ( $size['slug'] ?? '' ) : '';
			if ( '' !== $slug && isset( $seen[ $slug ] ) ) {
				$out[] = array(
					'slug'     => $slug,
					'label'    => isset( $size['label'] ) && '' !== (string) $size['label'] ? (string) $size['label'] : (string) $seen[ $slug ]['label'],
					'standard' => true,
				);
				unset( $seen[ $slug ] );
			}
		}
		uasort(
			$seen,
			static function ( $a, $b ) {
				return strnatcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);
		foreach ( $seen as $size ) {
			$out[] = array(
				'slug'     => (string) $size['slug'],
				'label'    => (string) $size['label'],
				'standard' => false,
			);
		}
		return $out;
	}

	/**
	 * A product the fish could be linked to with one click (pure): the one
	 * the species list already ties to its name, else the ONE fish product
	 * named exactly like it. A fish is on the list in spite of it when a
	 * stay never got the link, or a line is typed with a listed name
	 * without being tied to the row.
	 *
	 * @param array      $row   The fish's row (key, name).
	 * @param array      $index Stats::species_index().
	 * @param array      $names Shop_Price::name_index().
	 * @param array|null $alive alive().
	 * @return int|null
	 */
	private static function candidate( array $row, array $index, array $names, $alive ) {
		$species_id = (int) ( $row['species_id'] ?? 0 );
		$listed     = $species_id > 0 && isset( $index['rows'][ $species_id ] ) ? (array) $index['rows'][ $species_id ] : array();
		$id         = Shop_Price::product_of(
			array(
				'species'            => '' !== (string) $row['name'] ? (string) $row['name'] : (string) ( $listed['name'] ?? '' ),
				'species_product_id' => (int) ( $listed['product_id'] ?? 0 ),
			),
			$names
		);
		return null !== $id && self::exists( $id, $alive ) ? (int) $id : null;
	}

	/**
	 * The rows in the list's order (pure): the fish that are in a tank
	 * first, then by name — natural and case-blind.
	 *
	 * @param array[] $rows gather()'s fish.
	 * @return array[]
	 */
	public static function sort( array $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$tank = (int) ! empty( $b['in_tank'] ) <=> (int) ! empty( $a['in_tank'] );
				if ( 0 !== $tank ) {
					return $tank;
				}
				$name = strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
				return 0 !== $name ? $name : strcmp( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) );
			}
		);
		return $rows;
	}

	/**
	 * The "??" fish in order: those in a tank first, then by what they
	 * look like, then by their id.
	 *
	 * @param array[] $rows gather()'s unknown.
	 * @return array[]
	 */
	private static function sort_unknown( array $rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				$tank = (int) ( 'stay' === $b['kind'] ) <=> (int) ( 'stay' === $a['kind'] );
				if ( 0 !== $tank ) {
					return $tank;
				}
				$name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				return 0 !== $name ? $name : ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);
		return $rows;
	}

	/**
	 * One page of the list (pure).
	 *
	 * @param array[] $rows     The rows.
	 * @param int     $paged    The page asked for.
	 * @param int     $per_page Rows per page.
	 * @return array{rows: array[], total: int, pages: int, paged: int}
	 */
	public static function page( array $rows, $paged, $per_page = self::PER_PAGE ) {
		$per_page = max( 1, (int) $per_page );
		$total    = count( $rows );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = max( 1, min( $pages, (int) $paged ) );
		return array(
			'rows'  => array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page ),
			'total' => $total,
			'pages' => $pages,
			'paged' => $paged,
		);
	}

	/**
	 * The row of one fish in a gathered list, by its key — or, when the
	 * key is no longer there (a page loaded before the fish got its
	 * species-list row), by its name.
	 *
	 * @param array[] $rows gather()'s fish.
	 * @param string  $key  The fish key the page posted.
	 * @param string  $name The name the page showed.
	 * @return array|null
	 */
	public static function find( array $rows, $key, $name = '' ) {
		foreach ( $rows as $row ) {
			if ( (string) ( $row['key'] ?? '' ) === (string) $key && '' !== (string) $key ) {
				return $row;
			}
		}
		$wanted = Species::key( (string) $name );
		foreach ( $rows as $row ) {
			if ( '' !== $wanted && Species::key( (string) ( $row['name'] ?? '' ) ) === $wanted ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The count on the Board's chip, in words (pure): '' at 0 — the chip
	 * is not shown.
	 *
	 * @param int $count Fish with no product yet.
	 * @return string
	 */
	public static function chip_text( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}
		/* translators: %d: fish */
		return sprintf( _n( '%d fish with no product yet', '%d fish with no product yet', $count, 'fishotel-misc-plugin' ), $count );
	}

	/**
	 * The count beside a fish order on the Fish orders list (pure): '' at 0.
	 *
	 * @param int $count Fish of that order with no product yet.
	 * @return string
	 */
	public static function count_text( $count ) {
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}
		/* translators: %d: fish */
		return sprintf( __( '%d without a product', 'fishotel-misc-plugin' ), $count );
	}

	/**
	 * The words of the mark itself, the same on every screen.
	 *
	 * @return string
	 */
	public static function tag_text() {
		return __( 'no product yet', 'fishotel-misc-plugin' );
	}

	/* ============================================================== */
	/*  A page that was open before the link                           */
	/* ============================================================== */

	/**
	 * The lines of an order form with the species-list id put back where a
	 * page loaded earlier posts none (pure). The order page carries a
	 * line's species-list id in a hidden field; a page loaded before the
	 * fish was linked posts it empty, and the save would take the line off
	 * its species row — and so off its product — again. A line that names
	 * the fish it already is keeps the id it has; a line typed to another
	 * fish, or ticked "??", is taken as typed.
	 *
	 * @param array[] $lines  Clean lines (Orders::normalize_lines(), flag_lines()).
	 * @param array[] $before Line id → the line as stored.
	 * @return array[]
	 */
	public static function keep_species_ids( array $lines, array $before ) {
		foreach ( $lines as $index => $line ) {
			$id = is_array( $line ) ? (int) ( $line['id'] ?? 0 ) : 0;
			if ( $id <= 0 || ! isset( $before[ $id ] ) || ! empty( $line['needs_id'] ) || (int) ( $line['species_id'] ?? 0 ) > 0 ) {
				continue;
			}
			$stored = (int) ( $before[ $id ]['species_id'] ?? 0 );
			if ( $stored > 0 && empty( $before[ $id ]['needs_id'] ) && Identify::same_fish( $before[ $id ]['species'] ?? '', $line['species'] ?? '' ) ) {
				$lines[ $index ]['species_id'] = $stored;
			}
		}
		return $lines;
	}

	/* ============================================================== */
	/*  Link to a product                                              */
	/* ============================================================== */

	/**
	 * Link a fish to a product: its species-list row (a new one under the
	 * name it was typed with, when it has none), every line of it, every
	 * open stay of it. Needs the store's species(), species_by_name(),
	 * save_species(), link_species_product(), update_line_fields() and —
	 * through Identify::stay() — update_stay_fields().
	 *
	 * The lines: one that carries the row's id already follows by itself;
	 * one typed with the row's name gets the row's id — unless its order
	 * is closed. A closed order is history and is not written on: the
	 * row's per box and group would come with the id and change what that
	 * order cost and where the Stats count it. Such a line reads the
	 * product through the row of its name instead (through_list()). The
	 * stays: the product link, through Identify::stay() with the stay's
	 * own name and size — which writes the link alone.
	 *
	 * @param object  $store      Store or stand-in.
	 * @param array   $fish       The fish's row (find()): key, name, sci_name, species_id.
	 * @param array[] $lines      Every line of the fish (id, species_id, species, order_status), closed orders included.
	 * @param array[] $stays      Every open stay of the fish (hydrated).
	 * @param int     $product_id The product — the caller has checked that it exists.
	 * @param array   $ctx        today, settings, actor_id, source (as Identify::stay()).
	 * @return array ok, error, species_id, created (a species row was added), lines (ids that got
	 *               the row's id), stays (ids that got the link).
	 */
	public static function link( $store, array $fish, array $lines, array $stays, $product_id, array $ctx = array() ) {
		$out        = array(
			'ok'         => false,
			'error'      => '',
			'species_id' => 0,
			'created'    => false,
			'lines'      => array(),
			'stays'      => array(),
		);
		$product_id = (int) $product_id;
		$name       = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $fish['name'] ?? '' ) ) );
		if ( $product_id <= 0 || '' === $name || self::is_unknown( array( 'species' => $name ) ) ) {
			$out['error'] = __( 'Which fish, and which product?', 'fishotel-misc-plugin' );
			return $out;
		}

		$row = (int) ( $fish['species_id'] ?? 0 ) > 0 ? $store->species( (int) $fish['species_id'] ) : null;
		if ( ! $row ) {
			$row = $store->species_by_name( $name );
		}
		if ( $row ) {
			$species_id = (int) $row['id'];
			if ( (int) ( $row['product_id'] ?? 0 ) !== $product_id && ! $store->link_species_product( $species_id, $product_id ) ) {
				$out['error'] = __( 'The link could not be saved.', 'fishotel-misc-plugin' );
				return $out;
			}
		} else {
			$species_id = (int) $store->save_species(
				Species::normalize(
					array(
						'name'       => $name,
						'sci_name'   => (string) ( $fish['sci_name'] ?? '' ),
						'product_id' => $product_id,
					)
				)
			);
			if ( $species_id <= 0 ) {
				$out['error'] = __( 'The fish could not be added to the species list.', 'fishotel-misc-plugin' );
				return $out;
			}
			$out['created'] = true;
		}
		$out['species_id'] = $species_id;

		foreach ( $lines as $line ) {
			$line_id = is_array( $line ) ? (int) ( $line['id'] ?? 0 ) : 0;
			if ( $line_id <= 0 || 'closed' === (string) ( $line['order_status'] ?? '' ) ) {
				continue; // A closed order's line is never written on.
			}
			if ( (int) ( $line['species_id'] ?? 0 ) !== $species_id && ! self::is_unknown( $line ) && $store->update_line_fields( $line_id, array( 'species_id' => $species_id ) ) ) {
				$out['lines'][] = $line_id;
			}
		}

		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) || self::is_unknown( $stay ) || (int) ( $stay['product_id'] ?? 0 ) === $product_id ) {
				continue;
			}
			$size = isset( $stay['size_slug'] ) && '' !== (string) $stay['size_slug'] ? (string) $stay['size_slug'] : null;
			$done = Identify::stay(
				$store,
				$stay,
				array(
					'name'       => (string) ( $stay['species'] ?? '' ),
					'unknown'    => false,
					'product_id' => $product_id,
					'species_id' => $species_id,
					'sci_name'   => null,
					'size_slug'  => $size,
					'size_label' => null !== $size && isset( $stay['size_label'] ) ? (string) $stay['size_label'] : null,
				),
				$ctx
			);
			if ( ! empty( $done['changed'] ) ) {
				$out['stays'][] = (int) $stay['id'];
			}
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * The open stays of a fish follow its species-list row from one
	 * product to another (0 = to none): when the Species tab's "Link
	 * product" changes — or removes — a row's product, the groups in the
	 * tanks that carried the OLD product go with it, so a wrong pick is
	 * undone in one place. A group linked to some other product, or to
	 * none, is left as it is. Through Identify::stay() with the stay's own
	 * name and size: the link alone is written — no event, no label.
	 *
	 * @param object  $store      Store or stand-in.
	 * @param array[] $stays      Open stays of the fish (hydrated).
	 * @param int     $from       The product the row was linked to.
	 * @param int     $to         The product it is linked to now (0 = none).
	 * @param int     $species_id The row.
	 * @param array   $ctx        today, settings, actor_id, source (as Identify::stay()).
	 * @return int[] The stays that followed.
	 */
	public static function relink_stays( $store, array $stays, $from, $to, $species_id, array $ctx = array() ) {
		$from = (int) $from;
		$to   = max( 0, (int) $to );
		$out  = array();
		if ( $from <= 0 || $from === $to ) {
			return $out;
		}
		foreach ( $stays as $stay ) {
			if ( ! is_array( $stay ) || self::is_unknown( $stay ) || (int) ( $stay['product_id'] ?? 0 ) !== $from ) {
				continue;
			}
			$size = isset( $stay['size_slug'] ) && '' !== (string) $stay['size_slug'] ? (string) $stay['size_slug'] : null;
			$done = Identify::stay(
				$store,
				$stay,
				array(
					'name'       => (string) ( $stay['species'] ?? '' ),
					'unknown'    => false,
					'product_id' => $to,
					'species_id' => (int) $species_id,
					'sci_name'   => null,
					'size_slug'  => $size,
					'size_label' => null !== $size && isset( $stay['size_label'] ) ? (string) $stay['size_label'] : null,
				),
				$ctx
			);
			if ( ! empty( $done['changed'] ) ) {
				$out[] = (int) $stay['id'];
			}
		}
		return $out;
	}

	/**
	 * What a link did, in a sentence.
	 *
	 * @param string $name   The fish.
	 * @param string $title  The product's title.
	 * @param array  $result link().
	 * @return string
	 */
	public static function link_message( $name, $title, array $result ) {
		/* translators: 1: fish, 2: product title */
		$text  = sprintf( __( '%1$s is now linked to the product "%2$s".', 'fishotel-misc-plugin' ), $name, $title );
		$stays = count( (array) ( $result['stays'] ?? array() ) );
		if ( $stays > 0 ) {
			/* translators: %d: groups in tanks */
			$text .= ' ' . sprintf( _n( '%d group in the tanks follows.', '%d groups in the tanks follow.', $stays, 'fishotel-misc-plugin' ), $stays );
		}
		if ( ! empty( $result['created'] ) ) {
			$text .= ' ' . __( 'The fish was added to the species list under the name it was typed with.', 'fishotel-misc-plugin' );
		}
		return $text;
	}

	/* ============================================================== */
	/*  Start a draft product                                          */
	/* ============================================================== */

	/**
	 * What a draft product for a fish is made of (pure) — shaped as this
	 * shop's fish products are: a product with variations in the fish
	 * category, the scientific name in its meta and, in italics, as the
	 * short description (the line under the title), one variation per size
	 * seen on the fish's lines and stays, the price on the variations.
	 *
	 * The price of a size is the planned price typed on the fish order
	 * FOR THAT SIZE, or none: never a guessed one — a price typed on the
	 * 1-2" line says nothing about the 2-3" fish. A size becomes a
	 * variation only when the standard sizes are the shop's size attribute
	 * and the size is one of its terms; a planned price that has no
	 * variation to go on (typed on a line without a size, or for a size
	 * that is no term) is put nowhere, and the plan says so.
	 *
	 * @param array $fish     The fish's row (gather()): name, sci_name, sizes, planned_sizes.
	 * @param array $standard Sizes::standard(): attribute (the taxonomy, '' = a typed list), sizes.
	 * @return array name, sci_name, short_description, status, category, marker, taxonomy, sizes
	 *               (slug, label, price ('' = none) — the variations), skipped (labels of sizes that
	 *               are no term), priced (how many variations got a price), unplaced (planned prices
	 *               that were put nowhere, as money strings), left (what is left to do, in words).
	 */
	public static function draft_plan( array $fish, array $standard ) {
		$name     = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $fish['name'] ?? '' ) ) );
		$sci      = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $fish['sci_name'] ?? '' ) ) );
		$taxonomy = (string) ( $standard['attribute'] ?? '' );
		$terms    = array();
		foreach ( (array) ( $standard['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && '' !== (string) ( $size['slug'] ?? '' ) ) {
				$terms[ (string) $size['slug'] ] = (string) ( $size['label'] ?? $size['slug'] );
			}
		}

		$sizes   = array();
		$skipped = array();
		foreach ( self::order_sizes( array_column( (array) ( $fish['sizes'] ?? array() ), null, 'slug' ), (array) ( $standard['sizes'] ?? array() ) ) as $size ) {
			if ( '' !== $taxonomy && isset( $terms[ $size['slug'] ] ) ) {
				$sizes[] = array(
					'slug'  => $size['slug'],
					'label' => $terms[ $size['slug'] ],
					'price' => '',
				);
			} else {
				$skipped[] = (string) $size['label'];
			}
		}

		// Each planned price goes on the variation of the size it was typed for — and on no other.
		$priced   = 0;
		$unplaced = array();
		foreach ( (array) ( $fish['planned_sizes'] ?? array() ) as $planned ) {
			$amount = is_array( $planned ) && is_numeric( $planned['price'] ?? null ) && (float) $planned['price'] > 0 ? number_format( round( (float) $planned['price'], 2 ), 2, '.', '' ) : '';
			if ( '' === $amount ) {
				continue;
			}
			$placed = false;
			foreach ( $sizes as $index => $size ) {
				if ( '' !== (string) ( $planned['slug'] ?? '' ) && (string) $planned['slug'] === (string) $size['slug'] ) {
					$sizes[ $index ]['price'] = $amount;
					$placed                   = true;
					++$priced;
				}
			}
			if ( ! $placed ) {
				$unplaced[] = $amount;
			}
		}

		$left = array( __( 'a photo', 'fishotel-misc-plugin' ), __( 'the description', 'fishotel-misc-plugin' ) );
		if ( ! $sizes ) {
			$left[] = __( 'its sizes and their prices (none was known here)', 'fishotel-misc-plugin' );
		} elseif ( 0 === $priced ) {
			$left[] = __( 'the price of each size (none was typed on the fish order)', 'fishotel-misc-plugin' );
		} elseif ( $priced < count( $sizes ) ) {
			$left[] = __( 'the price of the sizes that have none, and a look at the others', 'fishotel-misc-plugin' );
		} else {
			$left[] = __( 'a look at the price of each size', 'fishotel-misc-plugin' );
		}
		if ( $sizes ) {
			$left[] = __( 'the stock of each size (it starts at 0)', 'fishotel-misc-plugin' );
		}
		if ( '' === $sci ) {
			$left[] = __( 'the scientific name', 'fishotel-misc-plugin' );
		}
		$left[] = __( 'Publish', 'fishotel-misc-plugin' );

		return array(
			'name'              => (string) substr( $name, 0, 200 ),
			'sci_name'          => $sci,
			'short_description' => '' !== $sci ? '<em>' . self::plain( $sci ) . '</em>' : '',
			'status'            => 'draft',
			'category'          => self::FISH_CATEGORY,
			'marker'            => Species::key( $name ),
			'taxonomy'          => $sizes ? $taxonomy : '',
			'sizes'             => $sizes,
			'skipped'           => $skipped,
			'priced'            => $priced,
			'unplaced'          => $unplaced,
			'left'              => $left,
		);
	}

	/**
	 * A text with the characters HTML reads escaped (pure — the short
	 * description is HTML).
	 *
	 * @param string $text A text.
	 * @return string
	 */
	private static function plain( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * What "Start a draft product" does for a fish (pure): 'open' the
	 * product it is linked to already — a second click, a second tab, a
	 * page loaded before the first click —, 'adopt' the draft that was
	 * started for it and link the fish to it (the first click made the
	 * product but the link did not get written), refuse because the shop
	 * has a product of exactly that name already — in whatever category,
	 * a 'twin': it is named, never doubled, and never linked without being
	 * asked —, or 'create' one. Never a second product.
	 *
	 * @param int|null $linked The product the fish is linked to now, when it exists.
	 * @param int|null $marked The product that carries the fish's marker (DRAFT_META), trash left out.
	 * @param int|null $twin   A product called exactly like the fish (Store::products_titled()), trash left out.
	 * @return array{act: string, product_id: int}
	 */
	public static function draft_decision( $linked, $marked, $twin = 0 ) {
		if ( (int) $linked > 0 ) {
			return array(
				'act'        => 'open',
				'product_id' => (int) $linked,
			);
		}
		if ( (int) $marked > 0 ) {
			return array(
				'act'        => 'adopt',
				'product_id' => (int) $marked,
			);
		}
		if ( (int) $twin > 0 ) {
			return array(
				'act'        => 'twin',
				'product_id' => (int) $twin,
			);
		}
		return array(
			'act'        => 'create',
			'product_id' => 0,
		);
	}

	/**
	 * Why no draft was made: the shop has a product of that name (pure).
	 *
	 * @param string $name   The fish.
	 * @param int    $twin   The product's id.
	 * @param string $status Its post status.
	 * @return string
	 */
	public static function twin_message( $name, $twin, $status = '' ) {
		/* translators: 1: fish, 2: product id, 3: ", a draft" or nothing */
		return sprintf( __( 'The shop already has a product called "%1$s" (#%2$d%3$s) — no second one was made. If it is this fish, link the fish to it ("Link to it" on its row, after a reload). If it is something else, rename one of the two first.', 'fishotel-misc-plugin' ), (string) $name, (int) $twin, 'draft' === (string) $status ? __( ', a draft', 'fishotel-misc-plugin' ) : '' );
	}

	/**
	 * What starting a draft did and what is left to do, in plain words.
	 *
	 * @param array  $plan draft_plan().
	 * @param string $act  create|adopt|open (draft_decision()).
	 * @return string
	 */
	public static function draft_message( array $plan, $act ) {
		$name = (string) ( $plan['name'] ?? '' );
		if ( 'open' === $act ) {
			/* translators: %s: fish */
			return sprintf( __( '%s already has a product — nothing new was made. It is linked; finish it in the shop.', 'fishotel-misc-plugin' ), $name );
		}
		if ( 'adopt' === $act ) {
			/* translators: %s: fish */
			$text = sprintf( __( 'A draft product for %s was started here before — nothing new was made. The fish is linked to it now.', 'fishotel-misc-plugin' ), $name );
		} else {
			$sizes    = array_column( (array) ( $plan['sizes'] ?? array() ), 'label' );
			$unplaced = array_map( array( Costing::class, 'money' ), (array) ( $plan['unplaced'] ?? array() ) );
			/* translators: %s: fish */
			$text = sprintf( __( 'A draft product "%s" was made and the fish is linked to it.', 'fishotel-misc-plugin' ), $name );
			if ( $sizes && ! empty( $plan['priced'] ) ) {
				$said = array();
				foreach ( (array) $plan['sizes'] as $size ) {
					$said[] = '' !== (string) ( $size['price'] ?? '' )
						/* translators: 1: size, 2: price */
						? sprintf( __( '%1$s at the planned price of %2$s', 'fishotel-misc-plugin' ), (string) $size['label'], Costing::money( $size['price'] ) )
						/* translators: %s: size */
						: sprintf( __( '%s with no price (none was typed for that size)', 'fishotel-misc-plugin' ), (string) $size['label'] );
				}
				/* translators: %s: sizes, each with its price */
				$text .= ' ' . sprintf( __( 'Sizes: %s.', 'fishotel-misc-plugin' ), implode( '; ', $said ) );
			} elseif ( $sizes ) {
				/* translators: %s: sizes */
				$text .= ' ' . sprintf( __( 'Sizes: %s. Price not set.', 'fishotel-misc-plugin' ), implode( ', ', $sizes ) );
			} else {
				$text .= ' ' . __( 'No size was known, so it has no sizes yet. Price not set.', 'fishotel-misc-plugin' );
			}
			if ( $unplaced ) {
				/* translators: %s: prices */
				$text .= ' ' . sprintf( _n( 'The planned price of %s was typed on a line that is no size of the product, so it was put nowhere: price not set there.', 'The planned prices of %s were typed on lines that are no size of the product, so they were put nowhere: price not set there.', count( $unplaced ), 'fishotel-misc-plugin' ), implode( ', ', $unplaced ) );
			}
			if ( ! empty( $plan['skipped'] ) ) {
				/* translators: %s: sizes */
				$text .= ' ' . sprintf( __( 'Not made a size of the product (not one of the shop\'s standard sizes): %s.', 'fishotel-misc-plugin' ), implode( ', ', (array) $plan['skipped'] ) );
			}
		}
		/* translators: %s: what is left to do */
		return $text . ' ' . sprintf( __( 'It is a draft: customers cannot see it. Left to do in the shop: %s.', 'fishotel-misc-plugin' ), implode( ', ', (array) ( $plan['left'] ?? array() ) ) );
	}
}
