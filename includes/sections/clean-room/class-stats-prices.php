<?php
/**
 * Clean Room stats, prices to raise — PURE and READ-ONLY: per fish, what
 * the latest supplier order says it should sell for, beside what FisHotel
 * charges now. Nothing here writes a price anywhere.
 *
 * One row per fish that has a line which RECEIVED fish at a PRICE (a
 * line that received nothing, a free line and a fish nobody has
 * identified yet say nothing about a price). A fish is what
 * Stats::fish_key() says: its species-list row, else its lower-cased name.
 *
 *   landed, latest   the landed cost per fish in the latest order that
 *                    has the fish — Σ landed ÷ Σ received over its lines
 *                    there (one line: exactly the order page's figure);
 *   landed, last 3   the plain average of that per-fish cost over the
 *                    last three such orders (fewer when there are fewer);
 *   suggested        exactly the costing's: landed per fish (latest) ×
 *                    that order's markup, to the nearest
 *                    Costing::PRICE_STEP;
 *   FisHotel's price fish_price(): the linked product's current price —
 *                    for a product with variations, the variation of the
 *                    size the latest order's lines carry when one matches,
 *                    else the lowest variation price ("from") —, else the
 *                    species list's price, else none;
 *   gap              suggested − price (above 0 = priced under the
 *                    suggestion);
 *   margin           (price − landed, latest) ÷ landed — the costing's
 *                    margin for one fish at today's price.
 *
 * "Latest" is by the order's date (received, else ordered, else created),
 * then by its id. A supplier filter costs the fish from that supplier's
 * orders only; a group filter keeps the rows of one species group.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats_Prices
 */
class Stats_Prices {

	/**
	 * How many of a fish's latest orders the average landed cost reads.
	 *
	 * @var int
	 */
	const LAST_ORDERS = 3;

	/**
	 * A price counts as under the suggestion from this many cents below it.
	 *
	 * @var float
	 */
	const CENT = 0.005;

	/**
	 * Where a FisHotel price came from.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'variation', 'product', 'list' );

	/**
	 * The report.
	 *
	 * @param array[] $orders          Orders, each with 'lines' (resolved or as stored).
	 * @param array[] $species         Species rows (id, name, group_name, product_id, my_price).
	 * @param array   $product_prices  Product id → prices / variations (Store::product_prices()).
	 * @param array   $filters         supplier (string), group (string).
	 * @param int     $default_per_box Fish per box when nothing says.
	 * @return array{rows: array[], suppliers: string[], groups: string[], counts: array{all: int, under: int, no_price: int}}
	 */
	public static function build( array $orders, array $species = array(), array $product_prices = array(), array $filters = array(), $default_per_box = Costing::DEFAULT_PER_BOX ) {
		$index    = Stats::species_index( $species );
		$supplier = Species::key( (string) ( $filters['supplier'] ?? '' ) );
		$group    = trim( (string) ( $filters['group'] ?? '' ) );

		// Newest order first: by its date, then by its id.
		$orders = array_values( array_filter( $orders, 'is_array' ) );
		usort(
			$orders,
			static function ( $a, $b ) {
				$order = strcmp( Stats::order_date( $b ), Stats::order_date( $a ) );
				return 0 !== $order ? $order : (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 );
			}
		);

		$suppliers = array();
		$fish      = array();
		foreach ( $orders as $order ) {
			$name = trim( (string) ( $order['supplier'] ?? '' ) );
			if ( '' !== $name && ! isset( $suppliers[ Species::key( $name ) ] ) ) {
				$suppliers[ Species::key( $name ) ] = $name;
			}
			if ( '' !== $supplier && Species::key( $name ) !== $supplier ) {
				continue;
			}

			$lines   = isset( $order['lines'] ) && is_array( $order['lines'] ) ? array_values( $order['lines'] ) : array();
			$costing = Costing::compute( $order, $lines, $default_per_box );
			$here    = array();
			foreach ( $costing['lines'] as $i => $costed ) {
				$line = isset( $lines[ $i ] ) && is_array( $lines[ $i ] ) ? $lines[ $i ] : array();
				if ( (int) $costed['qty_received'] < 1 || (float) $costed['unit_price'] <= 0 || ! empty( $line['needs_id'] ) || '' === trim( (string) ( $line['species'] ?? '' ) ) ) {
					continue;
				}
				$key = Stats::fish_key( $line, $index['names'] );
				if ( ! isset( $here[ $key ] ) ) {
					$here[ $key ] = array(
						'landed'   => 0.0,
						'received' => 0,
						'sizes'    => array(),
						'name'     => trim( (string) $line['species'] ),
						'group'    => trim( (string) ( $line['group_name'] ?? '' ) ),
					);
				}
				$here[ $key ]['landed']   += (float) $costed['landed_total'];
				$here[ $key ]['received'] += (int) $costed['qty_received'];
				$here[ $key ]['sizes'][ (string) ( $line['size_slug'] ?? '' ) ] = true;
			}

			foreach ( $here as $key => $sum ) {
				$fish[ $key ][] = array_merge(
					$sum,
					array(
						'order_id' => (int) ( $order['id'] ?? 0 ),
						'ref'      => (string) ( $order['ref'] ?? '' ),
						'date'     => Stats::order_date( $order ),
						'supplier' => $name,
						'markup'   => (float) $costing['order']['markup'],
						'per_fish' => $sum['landed'] / $sum['received'],
					)
				);
			}
		}

		$rows   = array();
		$groups = array();
		foreach ( $fish as $key => $entries ) {
			$latest = $entries[0];
			$recent = array_slice( $entries, 0, self::LAST_ORDERS );
			$id     = 0 === strpos( (string) $key, 's:' ) ? (int) substr( (string) $key, 2 ) : 0;
			$row    = $id > 0 && isset( $index['rows'][ $id ] ) ? $index['rows'][ $id ] : null;
			$sizes  = array_keys( $latest['sizes'] );
			$size   = 1 === count( $sizes ) ? (string) $sizes[0] : '';
			$price  = self::fish_price( $row, $product_prices, $size );

			$landed    = (float) $latest['per_fish'];
			$suggested = Costing::round_to( $landed * $latest['markup'], Costing::PRICE_STEP );
			$has_price = null !== $price['price'];
			$fish_grp  = $row ? trim( (string) ( $row['group_name'] ?? '' ) ) : $latest['group'];

			if ( '' !== $fish_grp ) {
				$groups[ $fish_grp ] = true;
			}

			$rows[] = array(
				'key'           => (string) $key,
				'species_id'    => $id > 0 ? $id : null,
				'name'          => $row && '' !== trim( (string) ( $row['name'] ?? '' ) ) ? (string) $row['name'] : $latest['name'],
				'group'         => $fish_grp,
				'product_id'    => $price['product_id'],
				'orders'        => count( $entries ),
				'latest_ref'    => $latest['ref'],
				'latest_id'     => $latest['order_id'],
				'latest_date'   => $latest['date'],
				'supplier'      => $latest['supplier'],
				'markup'        => $latest['markup'],
				'landed_latest' => $landed,
				'landed_avg'    => array_sum( array_column( $recent, 'per_fish' ) ) / count( $recent ),
				'avg_orders'    => count( $recent ),
				'suggested'     => $suggested,
				'price'         => $price['price'],
				'price_source'  => $price['source'],
				'price_from'    => $price['from'],
				'gap'           => $has_price ? $suggested - $price['price'] : null,
				'margin'        => $has_price && $landed > 1e-9 ? ( $price['price'] - $landed ) / $landed : null,
				'under'         => $has_price && $price['price'] < $suggested - self::CENT,
			);
		}

		if ( '' !== $group ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $group ) {
						return $row['group'] === $group;
					}
				)
			);
		}

		$counts = array(
			'all'      => count( $rows ),
			'under'    => 0,
			'no_price' => 0,
		);
		foreach ( $rows as $row ) {
			$counts['under']    += $row['under'] ? 1 : 0;
			$counts['no_price'] += null === $row['price'] ? 1 : 0;
		}

		$groups    = array_keys( $groups );
		$suppliers = array_values( $suppliers );
		natcasesort( $groups );
		natcasesort( $suppliers );

		return array(
			'rows'      => self::select( $rows, true ),
			'suppliers' => array_values( $suppliers ),
			'groups'    => array_values( $groups ),
			'counts'    => $counts,
		);
	}

	/**
	 * The rows a screen shows: the fish priced under the suggestion, the
	 * biggest gap first — and, with "show all", the fish at or above it
	 * after them (still by gap), then the fish with no price on file, by
	 * name.
	 *
	 * @param array[] $rows     build()['rows'].
	 * @param bool    $show_all Whether the fish that are not under are listed too.
	 * @return array[]
	 */
	public static function select( array $rows, $show_all = false ) {
		$rank = static function ( $row ) {
			if ( ! empty( $row['under'] ) ) {
				return 0;
			}
			return null === ( $row['price'] ?? null ) ? 2 : 1;
		};

		if ( ! $show_all ) {
			$rows = array_filter(
				$rows,
				static function ( $row ) {
					return ! empty( $row['under'] );
				}
			);
		}
		$rows = array_values( $rows );

		usort(
			$rows,
			static function ( $a, $b ) use ( $rank ) {
				if ( $rank( $a ) !== $rank( $b ) ) {
					return $rank( $a ) <=> $rank( $b );
				}
				if ( null !== $a['gap'] && null !== $b['gap'] && abs( $a['gap'] - $b['gap'] ) > 1e-9 ) {
					return $b['gap'] <=> $a['gap'];
				}
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * FisHotel's price for a fish: the linked product's current price when
	 * the species is linked and the product has one, else the species
	 * list's price, else none.
	 *
	 * A product with variations: the variation whose size attribute (any
	 * attribute the Sizes rule calls a size) is $size_slug, when exactly
	 * one price answers to it — source 'variation'; else the lowest
	 * variation price, source 'product', with 'from' true when the
	 * variations do not all cost the same.
	 *
	 * @param array|null $species        The species row (product_id, my_price), or null.
	 * @param array      $product_prices Product id → array{prices: float[], variations: array[]}.
	 * @param string     $size_slug      The size the fish came in ('' = unknown or mixed).
	 * @return array{price: float|null, source: string, from: bool, product_id: int|null}
	 */
	public static function fish_price( $species, array $product_prices, $size_slug = '' ) {
		$out = array(
			'price'      => null,
			'source'     => '',
			'from'       => false,
			'product_id' => null,
		);
		if ( ! is_array( $species ) ) {
			return $out;
		}

		$product_id = (int) ( $species['product_id'] ?? 0 );
		if ( $product_id > 0 ) {
			$out['product_id'] = $product_id;
			$info              = isset( $product_prices[ $product_id ] ) && is_array( $product_prices[ $product_id ] ) ? $product_prices[ $product_id ] : array();
			$found             = self::product_price( $info, (string) $size_slug );
			if ( null !== $found['price'] ) {
				return array_merge( $out, $found );
			}
		}

		$list = isset( $species['my_price'] ) && null !== $species['my_price'] && is_numeric( $species['my_price'] ) ? (float) $species['my_price'] : null;
		if ( null !== $list && $list > 0 ) {
			$out['price']  = $list;
			$out['source'] = 'list';
		}
		return $out;
	}

	/**
	 * The current price of one product from what Store::product_prices()
	 * read (pure).
	 *
	 * @param array  $info      prices (the product's own), variations (id, price, attributes).
	 * @param string $size_slug The size wanted ('' = none).
	 * @return array{price: float|null, source: string, from: bool}
	 */
	public static function product_price( array $info, $size_slug = '' ) {
		$none = array(
			'price'  => null,
			'source' => '',
			'from'   => false,
		);

		$variation_prices = array();
		$sized            = array();
		foreach ( (array) ( $info['variations'] ?? array() ) as $variation ) {
			$price = is_array( $variation ) ? self::positive( $variation['price'] ?? null ) : null;
			if ( null === $price ) {
				continue;
			}
			$variation_prices[] = $price;
			if ( '' === $size_slug ) {
				continue;
			}
			foreach ( (array) ( $variation['attributes'] ?? array() ) as $attribute => $value ) {
				if ( Sizes::matches( (string) $attribute ) && Species::key( (string) $value ) === Species::key( $size_slug ) ) {
					$sized[] = $price;
					break;
				}
			}
		}

		if ( $variation_prices ) {
			$sized = array_values( array_unique( $sized ) );
			if ( 1 === count( $sized ) ) {
				return array(
					'price'  => $sized[0],
					'source' => 'variation',
					'from'   => false,
				);
			}
			return array(
				'price'  => min( $variation_prices ),
				'source' => 'product',
				'from'   => max( $variation_prices ) - min( $variation_prices ) > self::CENT,
			);
		}

		$own = array();
		foreach ( (array) ( $info['prices'] ?? array() ) as $price ) {
			$price = self::positive( $price );
			if ( null !== $price ) {
				$own[] = $price;
			}
		}
		if ( ! $own ) {
			return $none;
		}
		return array(
			'price'  => min( $own ),
			'source' => 'product',
			'from'   => max( $own ) - min( $own ) > self::CENT,
		);
	}

	/**
	 * A price above zero as a float, else null.
	 *
	 * @param mixed $value Raw.
	 * @return float|null
	 */
	private static function positive( $value ) {
		if ( null === $value || is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value > 0 ? (float) $value : null;
	}
}
