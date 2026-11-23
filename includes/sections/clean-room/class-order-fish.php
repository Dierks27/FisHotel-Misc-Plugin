<?php
/**
 * Clean Room — PURE: the fish that are "in the system" only because a
 * fish order names them.
 *
 * A fish typed on a fish order line — "use as typed (unlisted fish)" in
 * the importer, or a name typed on a line — is linked to no product and
 * to no Species row, so the fish search (products, the Species list,
 * learned names) never found it. The owner's expectation is the right
 * one: a fish on one of their fish orders is a fish they can look up.
 *
 * So every fish search also offers:
 *   - the fish named on the lines of the fish orders that are NOT closed
 *     (draft, ordered, received), and
 *   - any unlisted fish that is alive in a tank,
 * each ONCE (case and spacing aside, in the spelling of its newest
 * line), with its scientific name when a line has one and a word saying
 * where it comes from ("on fish order 2026-9", "in tank 512").
 *
 * Never offered: a line or a group linked to a product or a Species row
 * (by its link, by the group it was checked in as, or by carrying the
 * exact name of one — it is found as that), an unknown "??" fish (it has no name), a line of a closed
 * order whose fish is no longer in a tank.
 *
 * "A product" is one that still exists — the rule "Fish with no product
 * yet" goes by (No_Product): a product in the trash, or deleted, is
 * none. The fish search does not find such a product, so a group that
 * is still linked to one is offered here by its own name, as that list
 * shows it. The rows handed to offered() carry a product id only for a
 * product that exists.
 *
 * Picking one is "use as an unlisted fish" with that exact spelling:
 * the item carries no product and no Species row, so nothing is created
 * and the group in the tank reads as the order line does.
 *
 * The store reads the rows and keeps the list in a transient
 * (Store::order_fish()); everything here is plain arrays in, plain
 * arrays out.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Fish
 */
class Order_Fish {

	/**
	 * Row caps of the two reads, and of the list that is kept. Closed
	 * orders are out by rule, so the reads do not grow with the history;
	 * the caps are the net under an order book that was never closed.
	 */
	const MAX_LINES   = 3000;
	const MAX_STAYS   = 1000;
	const MAX_OFFERED = 1000;

	/**
	 * The names the lists already know, as a set of keys: every fish
	 * product's title and every Species row's name. A line or a group
	 * that carries one of them IS that product or that Species row
	 * (Orders::line_product_id(), Identify::fish_from()).
	 *
	 * @param string[] $product_names Fish product titles.
	 * @param string[] $species_names Species row names.
	 * @return array<string, true>
	 */
	public static function known( array $product_names, array $species_names ) {
		$known = array();
		foreach ( array( $product_names, $species_names ) as $names ) {
			foreach ( $names as $name ) {
				$key = is_scalar( $name ) ? Species::key( (string) $name ) : '';
				if ( '' !== $key ) {
					$known[ $key ] = true;
				}
			}
		}
		return $known;
	}

	/**
	 * A name as it is offered and posted: the spelling as stored, spacing
	 * tidied (what Identify::fish_from() does to a typed name).
	 *
	 * @param mixed $name A stored name.
	 * @return string
	 */
	private static function spelling( $name ) {
		return is_scalar( $name ) ? trim( (string) preg_replace( '/\s+/', ' ', (string) $name ) ) : '';
	}

	/**
	 * The fish to offer, from the order lines and the stays.
	 *
	 * @param array[] $lines id, species, sci_name, needs_id, species_id, stay_product_id (the product
	 *                       of the group the line became, if it has one that still exists), order_ref,
	 *                       order_status.
	 * @param array[] $stays id, species, sci_name and line_species_id (its order line's), needs_id,
	 *                       product_id (a product that still exists, else none), qty_live, status,
	 *                       tank_number.
	 * @param array   $known known().
	 * @return array[] name, sci, from (order|tank), ref, tank — one per fish, by name.
	 */
	public static function offered( array $lines, array $stays, array $known ) {
		$by_id = static function ( $a, $b ) {
			return (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ); // Newest first.
		};
		usort( $lines, $by_id );
		usort( $stays, $by_id );

		$out = array();
		$add = static function ( array $row, $from ) use ( &$out, $known ) {
			$name = self::spelling( $row['species'] ?? '' );
			$key  = Species::key( $name );
			if ( '' === $key || isset( $known[ $key ] ) || ! empty( $row['needs_id'] ) || Identify::is_unknown_name( $name ) ) {
				return;
			}
			$sci = self::spelling( $row['sci_name'] ?? '' );
			if ( isset( $out[ $key ] ) ) {
				// The newest line gave the spelling; an older one may still know the scientific name.
				if ( '' === $out[ $key ]['sci'] && '' !== $sci ) {
					$out[ $key ]['sci'] = $sci;
				}
				return;
			}
			$out[ $key ] = array(
				'name' => $name,
				'sci'  => $sci,
				'from' => $from,
				'ref'  => 'order' === $from ? self::spelling( $row['order_ref'] ?? '' ) : '',
				'tank' => 'tank' === $from && (int) ( $row['tank_number'] ?? 0 ) > 0 ? (int) $row['tank_number'] : null,
			);
		};

		foreach ( $lines as $line ) {
			if ( 'closed' === (string) ( $line['order_status'] ?? '' ) || (int) ( $line['species_id'] ?? 0 ) > 0 || (int) ( $line['stay_product_id'] ?? 0 ) > 0 ) {
				continue; // A closed order's; a Species-list fish; a line whose group in the tank is a product.
			}
			$add( $line, 'order' );
		}
		foreach ( $stays as $stay ) {
			if ( 'closed' === (string) ( $stay['status'] ?? '' ) || (int) ( $stay['qty_live'] ?? 0 ) < 1 || (int) ( $stay['product_id'] ?? 0 ) > 0 || (int) ( $stay['line_species_id'] ?? 0 ) > 0 ) {
				continue; // Gone; a product; a group whose order line is a Species-list fish (whatever the row is called now).
			}
			$add( $stay, 'tank' );
		}

		if ( count( $out ) > self::MAX_OFFERED ) {
			// Too many to keep: the fish alive in a tank stay, then the NEWEST lines' (newest first here).
			$tanks = array();
			$rest  = array();
			foreach ( $out as $fish ) {
				if ( 'tank' === $fish['from'] ) {
					$tanks[] = $fish;
				} else {
					$rest[] = $fish;
				}
			}
			$out = array_slice( array_merge( $tanks, $rest ), 0, self::MAX_OFFERED );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return array_values( $out );
	}

	/**
	 * Where an offered fish comes from, in a few words.
	 *
	 * @param array $fish One of offered().
	 * @return string
	 */
	public static function where( array $fish ) {
		if ( 'order' === ( $fish['from'] ?? '' ) ) {
			/* translators: %s: a fish order's ref, e.g. 2026-9 */
			return '' !== (string) ( $fish['ref'] ?? '' ) ? sprintf( __( 'on fish order %s', 'fishotel-misc-plugin' ), (string) $fish['ref'] ) : __( 'on a fish order', 'fishotel-misc-plugin' );
		}
		/* translators: %d: a tank number */
		return ! empty( $fish['tank'] ) ? sprintf( __( 'in tank %d', 'fishotel-misc-plugin' ), (int) $fish['tank'] ) : __( 'in a tank', 'fishotel-misc-plugin' );
	}

	/**
	 * An offered fish as a search-box item: the shape
	 * Store::species_suggestions() returns, with no product and no
	 * Species row — picking it is "use as an unlisted fish" with this
	 * spelling — plus `where`, and `ref`: the fish order it is on ('' for
	 * a fish in a tank), which an Arrival writes on the group it makes.
	 *
	 * @param array $fish One of offered().
	 * @return array
	 */
	public static function suggestion( array $fish ) {
		return array(
			'product_id' => null,
			'name'       => (string) ( $fish['name'] ?? '' ),
			'common'     => '',
			'sci'        => (string) ( $fish['sci'] ?? '' ),
			'match'      => 'tank' === ( $fish['from'] ?? '' ) ? 'tank' : 'order',
			'alias'      => '',
			'where'      => self::where( $fish ),
			'ref'        => 'order' === ( $fish['from'] ?? '' ) ? (string) ( $fish['ref'] ?? '' ) : '',
		);
	}

	/**
	 * The words of a search term, lower-cased.
	 *
	 * @param string $term Search text.
	 * @return string[]
	 */
	private static function words( $term ) {
		$key = Species::key( $term );
		return '' === $key ? array() : explode( ' ', $key );
	}

	/**
	 * The offered fish a term finds, as search-box items: every word of
	 * the term stands somewhere in the name or in the scientific name —
	 * the rule the product search follows.
	 *
	 * @param array[] $offered offered().
	 * @param string  $term    Search text.
	 * @return array[] suggestion()s, in the order given.
	 */
	public static function matching( array $offered, $term ) {
		$words = self::words( $term );
		if ( ! $words ) {
			return array();
		}
		$out = array();
		foreach ( $offered as $fish ) {
			$name = Species::key( (string) ( $fish['name'] ?? '' ) );
			$sci  = Species::key( (string) ( $fish['sci'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			foreach ( $words as $word ) {
				if ( false === strpos( $name, $word ) && false === strpos( $sci, $word ) ) {
					continue 2;
				}
			}
			$out[] = self::suggestion( $fish );
		}
		return $out;
	}

	/**
	 * How well an item's NAME answers a term (lower is better):
	 *   0  the name is what was typed;
	 *   1  the name starts with it;
	 *   2  every word typed stands in the name;
	 *   3  it does not: found by something else (the scientific name, say).
	 *
	 * @param string $name An item's name.
	 * @param string $term Search text.
	 * @return int
	 */
	public static function quality( $name, $term ) {
		$name = Species::key( $name );
		$term = Species::key( $term );
		if ( '' === $name || '' === $term ) {
			return 3;
		}
		if ( $name === $term ) {
			return 0;
		}
		if ( 0 === strpos( $name, $term ) ) {
			return 1;
		}
		foreach ( explode( ' ', $term ) as $word ) {
			if ( false === strpos( $name, $word ) ) {
				return 3;
			}
		}
		return 2;
	}

	/**
	 * How well a listed item answers a term: the best of its name, the
	 * learned name that found it and each of its common names — a product
	 * found by "pj cardinal" answers "pj" as well as a fish typed
	 * "PJ Cardinal" does, and stays above it.
	 *
	 * @param array  $item A suggestion: name, alias, common.
	 * @param string $term Search text.
	 * @return int
	 */
	public static function item_quality( array $item, $term ) {
		$best = min( self::quality( (string) ( $item['name'] ?? '' ), $term ), self::quality( (string) ( $item['alias'] ?? '' ), $term ) );
		foreach ( Store::split_names( (string) ( $item['common'] ?? '' ) ) as $common ) {
			$best = min( $best, self::quality( $common, $term ) );
		}
		return $best;
	}

	/**
	 * The offered fish whose name is NEARLY what was typed (a letter off —
	 * Species::near(), the rule the lists' own second try follows), as
	 * search-box items marked match 'near': each still says where it comes
	 * from and carries its fish order's ref. Asked only when nothing
	 * answered letter for letter.
	 *
	 * @param array[] $offered offered().
	 * @param string  $term    Search text.
	 * @param int     $limit   Row cap.
	 * @return array[] suggestion()s with match 'near', the nearest first.
	 */
	public static function nearly( array $offered, $term, $limit = 10 ) {
		$candidates = array();
		$by_key     = array();
		foreach ( $offered as $fish ) {
			$name = is_array( $fish ) ? (string) ( $fish['name'] ?? '' ) : '';
			$key  = Species::key( $name );
			if ( '' === $key || isset( $by_key[ $key ] ) ) {
				continue;
			}
			$variants = array(
				array(
					'text' => $name,
					'kind' => 'title',
				),
			);
			if ( '' !== trim( (string) ( $fish['sci'] ?? '' ) ) ) {
				$variants[] = array(
					'text' => (string) $fish['sci'],
					'kind' => 'sci',
				);
			}
			$by_key[ $key ] = $fish;
			$candidates[]   = array(
				'product_id' => null,
				'name'       => $name,
				'variants'   => $variants,
			);
		}

		$out = array();
		foreach ( Species::near( $term, $candidates, max( 1, (int) $limit ) ) as $item ) {
			$key = Species::key( (string) ( $item['name'] ?? '' ) );
			if ( isset( $by_key[ $key ] ) ) {
				$out[] = array_merge( self::suggestion( $by_key[ $key ] ), array( 'match' => 'near' ) );
			}
		}
		return $out;
	}

	/**
	 * Whether the lists themselves — products, Species rows, learned names
	 * — answered a search letter for letter: an item that is neither a near
	 * spelling nor a fish that only a fish order line or a group in a tank
	 * names. What the hint about a product outside the fish categories
	 * asks (Store::search_hint()).
	 *
	 * @param array[] $items A search's items.
	 * @return bool
	 */
	public static function lists_answered( array $items ) {
		foreach ( $items as $item ) {
			if ( ! in_array( (string) ( $item['match'] ?? '' ), array( 'near', 'order', 'tank' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Put the order-line fish a term finds into a suggestion list, BELOW
	 * the products and the Species rows that answer the term as well: each
	 * goes right after the last item already listed that answers at least
	 * as well (item_quality()) as its own name does. The items already
	 * listed keep their order; a fish whose name is already in the list (a
	 * learned name of it, say) is not listed twice.
	 *
	 * A SIMILAR SPELLING stands under every letter-for-letter answer: the
	 * listed items marked match 'near' (the lists' own second try, made
	 * when no product, Species row or learned name answered) go under the
	 * order-line fish that answer the term exactly; and when nothing at
	 * all answered letter for letter, the order-line fish that are nearly
	 * what was typed (nearly()) follow the lists' near answers. What the
	 * lists answered is kept as it is; a fish of a fish order is offered
	 * once, and not at all under a name the lists already answered with.
	 *
	 * @param array[] $items   Alias / product / species suggestions, in their order.
	 * @param array[] $offered offered().
	 * @param string  $term    Search text.
	 * @param int     $limit   Row cap.
	 * @return array[]
	 */
	public static function place( array $items, array $offered, $term, $limit ) {
		$limit = max( 1, (int) $limit );
		$near  = array();
		$exact = array();
		foreach ( $items as $item ) {
			if ( 'near' === (string) ( $item['match'] ?? '' ) ) {
				$near[] = $item;
			} else {
				$exact[] = $item;
			}
		}
		$items = $exact;
		$fish  = self::matching( $offered, $term );

		// What the lists answered is never dropped; a fish of a fish order named like one of them is.
		$listed  = array();
		$quality = array();
		foreach ( $near as $item ) {
			$listed[ Species::key( (string) ( $item['name'] ?? '' ) ) ] = true;
		}
		foreach ( $items as $index => $item ) {
			$listed[ Species::key( (string) ( $item['name'] ?? '' ) ) ] = true;
			$quality[ $index ] = self::item_quality( $item, $term );
		}

		// Slot n = right before item n (count( $items ) = at the end).
		$slots = array();
		foreach ( $fish as $one ) {
			$key = Species::key( $one['name'] );
			if ( isset( $listed[ $key ] ) ) {
				continue;
			}
			$listed[ $key ] = true;
			$own            = self::quality( $one['name'], $term );
			$slot           = 0;
			foreach ( $quality as $index => $q ) {
				if ( $q <= $own ) {
					$slot = $index + 1;
				}
			}
			$slots[ $slot ][] = array( $own, $one );
		}

		$out = array();
		for ( $n = 0; $n <= count( $items ); $n++ ) {
			if ( isset( $slots[ $n ] ) ) {
				usort(
					$slots[ $n ],
					static function ( $a, $b ) {
						return $a[0] <=> $b[0] ? $a[0] <=> $b[0] : strcasecmp( $a[1]['name'], $b[1]['name'] );
					}
				);
				foreach ( $slots[ $n ] as $pair ) {
					$out[] = $pair[1];
				}
			}
			if ( isset( $items[ $n ] ) ) {
				$out[] = $items[ $n ];
			}
		}

		// The similar spellings, under whatever answered letter for letter: the lists' first, then the order-line fish's.
		$nearly = ! $out && count( $near ) < $limit ? self::nearly( $offered, $term, $limit ) : array();
		$out    = array_merge( $out, $near );
		foreach ( $nearly as $one ) {
			$key = Species::key( $one['name'] );
			if ( ! isset( $listed[ $key ] ) ) {
				$listed[ $key ] = true;
				$out[]          = $one;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Whether a stay as it was and as a replay left it changes the list:
	 * a named fish that came alive, is no longer alive, or stands in
	 * another tank now. (A "??" group is never in the list; a count that
	 * only went from 5 to 4 changes nothing. A group that carries a
	 * product is in the list when that product is in the trash or deleted
	 * — the group is an unlisted fish then —, and this rule cannot know
	 * that without a read: so a product on the group is no reason to keep
	 * the list, and its "in tank …" is never the old tank's.)
	 *
	 * @param array $before The stay before: needs_id, qty_live, tank_id.
	 * @param array $after  The replayed state: qty_live, tank_id.
	 * @return bool
	 */
	public static function stay_changes( array $before, array $after ) {
		if ( ! empty( $before['needs_id'] ) ) {
			return false;
		}
		$was = (int) ( $before['qty_live'] ?? 0 ) > 0;
		$is  = (int) ( $after['qty_live'] ?? 0 ) > 0;
		if ( $was !== $is ) {
			return true;
		}
		return $is && (int) ( $before['tank_id'] ?? 0 ) !== (int) ( $after['tank_id'] ?? 0 );
	}

	/**
	 * Whether a write to a stay's identity fields changes the list: its
	 * fish, its product link or its "??" flag.
	 *
	 * @param array $data The columns being written.
	 * @return bool
	 */
	public static function stay_fields_change( array $data ) {
		return array_key_exists( 'species', $data ) || array_key_exists( 'product_id', $data ) || array_key_exists( 'needs_id', $data );
	}
}
