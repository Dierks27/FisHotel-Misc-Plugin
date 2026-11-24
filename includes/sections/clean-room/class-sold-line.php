<?php
/**
 * Clean Room sold line — PURE: which tank group (stay) does a line of a
 * customer's order come out of?
 *
 * One small function, stays(), that every "this fish is sold" rule can
 * ask: today the feeding rule (Feeding: a clean system is not fed on the
 * day its fish ship); later a fish reserved in its tank, the "pull
 * today" list, a shipped fish taken out of its tank.
 *
 * The ladder, strongest first — the first rung with an answer wins:
 *
 *   held      the line was reserved in its tank group(s) when the order
 *             came in (the order item's _fh_stay, see Reserve) and one
 *             of them still has fish — or left today;
 *   invoice   the Invoices section tied the line to a tank group (the
 *             line's link.stay_id) and that group still has fish;
 *   product   open groups of the same shop product (the group's product
 *             is the line's product or its variation) and the same
 *             size; when none has that size, the groups of the product
 *             whose size was never written down; every group of the
 *             product when the line names no size (a size nobody
 *             recorded cannot rule a fish out);
 *   name      open groups whose species is, word for word, the line's
 *             name (its product's title, the invoice's name for it) —
 *             the same size again, or the name itself ending in the
 *             group's size label ("Yellow Tang - Medium").
 *
 * It answers with every group that fits: two groups of the same fish in
 * two tanks are two candidates, and the caller decides what that means.
 * Only a group with a live fish is a candidate — and one the caller
 * marks `left_today` (its last fish was bagged today: logging the
 * shipment must not turn a located fish into a lost one).
 * An empty answer means the site does not know where the fish is — no
 * guess is made.
 *
 * It answers over the groups it is GIVEN, and the strongest rung wins
 * among those only. A caller for whom one place counts more than a
 * better fit asks twice: the feeding rule gives it the groups in clean
 * water first (a fish for sale is in a clean tank), and all of them only
 * to follow the Invoices section's own link.
 *
 * No WordPress, no WooCommerce: a line and the stays are plain arrays.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sold_Line
 */
class Sold_Line {

	const RUNG_HELD    = 'held';
	const RUNG_INVOICE = 'invoice';
	const RUNG_PRODUCT = 'product';
	const RUNG_NAME    = 'name';

	/**
	 * A name as it is compared: lower case, every run of anything that is
	 * not a letter or a digit as one space ("Yellow Tang - Medium" and
	 * "yellow tang medium" are the same name).
	 *
	 * @param mixed $text Name.
	 * @return string
	 */
	public static function norm( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * A line in the one shape stays() reads, from whatever a reader has.
	 *
	 * @param array $raw name, names (other names the line goes by: the product's title, the invoice's
	 *                   name), product_id, variation_id, size_slug, size_label, link (the Invoices
	 *                   section's: product_id, variation_id, species, size_slug, stay_id), held (the
	 *                   reservation: stay id => fish, Reserve::held()).
	 * @return array{name: string, names: string[], product_ids: int[], size_slug: string, size_label: string, stay_id: int, held: int[]}
	 */
	public static function line( array $raw ) {
		$link = isset( $raw['link'] ) && is_array( $raw['link'] ) ? $raw['link'] : array();
		$int  = static function ( $value ) {
			return is_scalar( $value ) ? max( 0, (int) $value ) : 0;
		};
		$text = static function ( $value ) {
			return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
		};

		$products = array();
		foreach ( array( $raw['product_id'] ?? 0, $raw['variation_id'] ?? 0, $link['product_id'] ?? 0, $link['variation_id'] ?? 0 ) as $id ) {
			if ( $int( $id ) > 0 ) {
				$products[ $int( $id ) ] = true;
			}
		}

		$names = array();
		foreach ( array_merge( array( $raw['name'] ?? '', $link['species'] ?? '' ), isset( $raw['names'] ) && is_array( $raw['names'] ) ? $raw['names'] : array() ) as $name ) {
			$name = self::norm( $name );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}

		$size = Sizes::slug( $text( $raw['size_slug'] ?? '' ) );
		if ( '' === $size ) {
			$size = Sizes::slug( $text( $link['size_slug'] ?? '' ) );
		}

		// A name that ends in the line's own size ("Sample Angel - Large") is also the name without it.
		foreach ( array( self::norm( $raw['size_label'] ?? '' ), self::norm( $size ) ) as $tail ) {
			foreach ( array_keys( $names ) as $name ) {
				$name = (string) $name;
				if ( '' !== $tail && strlen( $name ) > strlen( $tail ) + 1 && substr( $name, -strlen( $tail ) - 1 ) === ' ' . $tail ) {
					$names[ substr( $name, 0, -strlen( $tail ) - 1 ) ] = true;
				}
			}
		}

		return array(
			'name'        => $text( $raw['name'] ?? '' ),
			'names'       => array_map( 'strval', array_keys( $names ) ),
			'product_ids' => array_keys( $products ),
			'size_slug'   => $size,
			'size_label'  => $text( $raw['size_label'] ?? '' ),
			'stay_id'     => $int( $link['stay_id'] ?? 0 ),
			'held'        => array_keys( Reserve::held( $raw['held'] ?? array() ) ),
		);
	}

	/**
	 * Whether a group is surely the line's fish AND its size: the same
	 * product or the same name word for word, and a size both name and
	 * agree on (or the line's name itself ending in the group's size
	 * label). A fish matched only by product or name, with a size one of
	 * them leaves open, is a guess — good enough to skip a feed, never to
	 * take a fish out of a tank by itself.
	 *
	 * @param array $line A line (raw, as stays() takes it).
	 * @param array $stay A stay (product_id, species, size_slug, size_label).
	 * @return bool
	 */
	public static function exact( array $line, array $stay ) {
		$line    = self::line( $line );
		$size    = Sizes::slug( (string) ( $stay['size_slug'] ?? '' ) );
		$species = self::norm( $stay['species'] ?? '' );
		$label   = self::norm( $stay['size_label'] ?? '' );
		if ( '' !== $species && '' !== $label && in_array( $species . ' ' . $label, $line['names'], true ) ) {
			return true; // "Yellow Tang Medium" names the group's size itself.
		}
		if ( '' === $line['size_slug'] || $size !== $line['size_slug'] ) {
			return false;
		}
		if ( $line['product_ids'] && in_array( (int) ( $stay['product_id'] ?? 0 ), $line['product_ids'], true ) ) {
			return true;
		}
		return '' !== $species && in_array( $species, $line['names'], true );
	}

	/**
	 * Of the groups of the right fish, the ones of the right size: the
	 * groups of the line's size; when there is none — or the line names no
	 * size — the groups whose size nobody wrote down, or all of them (a
	 * size nobody recorded cannot rule a fish out).
	 *
	 * @param array[] $stays     Groups of the right fish.
	 * @param string  $line_size The line's size slug ('' = it names none).
	 * @return array[]
	 */
	private static function of_size( array $stays, $line_size ) {
		if ( '' === (string) $line_size ) {
			return $stays;
		}
		$same  = array();
		$loose = array();
		foreach ( $stays as $stay ) {
			$size = Sizes::slug( (string) ( $stay['size_slug'] ?? '' ) );
			if ( $size === (string) $line_size ) {
				$same[] = $stay;
			} elseif ( '' === $size ) {
				$loose[] = $stay;
			}
		}
		return $same ? $same : $loose;
	}

	/**
	 * The tank groups an order line comes out of.
	 *
	 * @param array   $line  A line (see line(): name, names, product_id, variation_id, size_slug,
	 *                       size_label, link).
	 * @param array[] $stays Stays (id, product_id, species, size_slug, size_label, qty_live). One without
	 *                       a live fish is no candidate — unless it carries `left_today`: its last fish
	 *                       went out (or died) today, so this morning the fish was still in that water.
	 * @return array{rung: string, stays: array[]} rung: held | invoice | product | name, '' when no group fits;
	 *         stays: the groups as they were given, in their order.
	 */
	public static function stays( array $line, array $stays ) {
		$line = self::line( $line );
		$live = array();
		foreach ( $stays as $stay ) {
			// A group with fish in it — or one whose last fish left today (the caller says so).
			if ( is_array( $stay ) && ( (int) ( $stay['qty_live'] ?? 0 ) > 0 || ! empty( $stay['left_today'] ) ) ) {
				$live[] = $stay;
			}
		}

		// 0. The line was reserved in its group(s) when the order came in.
		if ( $line['held'] ) {
			$mine = array();
			foreach ( $live as $stay ) {
				if ( in_array( (int) ( $stay['id'] ?? 0 ), $line['held'], true ) ) {
					$mine[] = $stay;
				}
			}
			if ( $mine ) {
				return self::answer( self::RUNG_HELD, $mine );
			}
		}

		// 1. The Invoices section said which group.
		if ( $line['stay_id'] > 0 ) {
			foreach ( $live as $stay ) {
				if ( (int) ( $stay['id'] ?? 0 ) === $line['stay_id'] ) {
					return self::answer( self::RUNG_INVOICE, array( $stay ) );
				}
			}
		}

		// 2. The same shop product, the same size.
		if ( $line['product_ids'] ) {
			$fish = array();
			foreach ( $live as $stay ) {
				if ( in_array( (int) ( $stay['product_id'] ?? 0 ), $line['product_ids'], true ) ) {
					$fish[] = $stay;
				}
			}
			$fish = self::of_size( $fish, $line['size_slug'] );
			if ( $fish ) {
				return self::answer( self::RUNG_PRODUCT, $fish );
			}
		}

		// 3. The species by its exact name, and the size.
		if ( $line['names'] ) {
			$named = array(); // The name itself says the size ("Yellow Tang Medium").
			$fish  = array();
			foreach ( $live as $stay ) {
				$species = self::norm( $stay['species'] ?? '' );
				if ( '' === $species ) {
					continue;
				}
				$label = self::norm( $stay['size_label'] ?? '' );
				if ( '' !== $label && in_array( $species . ' ' . $label, $line['names'], true ) ) {
					$named[] = $stay;
				} elseif ( in_array( $species, $line['names'], true ) ) {
					$fish[] = $stay;
				}
			}
			$fish = $named ? $named : self::of_size( $fish, $line['size_slug'] );
			if ( $fish ) {
				return self::answer( self::RUNG_NAME, $fish );
			}
		}

		return self::answer( '', array() );
	}

	/**
	 * The answer's shape.
	 *
	 * @param string  $rung  Rung.
	 * @param array[] $stays Stays.
	 * @return array{rung: string, stays: array[]}
	 */
	private static function answer( $rung, array $stays ) {
		return array(
			'rung'  => (string) $rung,
			'stays' => array_values( $stays ),
		);
	}
}
