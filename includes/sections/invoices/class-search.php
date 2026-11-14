<?php
/**
 * The picker's search — one box, the results in groups:
 *
 *   tanks     fish in the tanks now (Clean Room groups: species, size, how
 *             many alive, tank, which supplier order) — when that section
 *             is on; with how many of the group already stand on other
 *             open invoices.
 *   products  shop products, each variable product as its size variations.
 *   species   the species list.
 *   typed     "use what I typed".
 *
 * Each row carries the ids of where it came from and a price to fill in:
 * the variation's / product's price, else the species list's, else none.
 * A group that cannot be read says so in plain words instead of being
 * silently empty.
 *
 * Another section can add a group of its own through the filter
 * `fishotel_invoices_search_groups` (see README.md); whatever it returns
 * is cleaned by clean_group() before it reaches the page.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Search
 */
class Search {

	const MIN_LENGTH = 2;

	/**
	 * One row of a group, cleaned (pure): only known keys, typed values.
	 *
	 * @param mixed $row Row.
	 * @return array|null
	 */
	public static function clean_item( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$name = Lines::text( isset( $row['name'] ) ? $row['name'] : '' );
		if ( '' === $name ) {
			return null;
		}

		$int    = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) && (int) $row[ $key ] > 0 ? (int) $row[ $key ] : 0;
		};
		$source = isset( $row['source'] ) && in_array( $row['source'], Lines::SOURCES, true ) ? (string) $row['source'] : 'typed';
		$kind   = isset( $row['kind'] ) && in_array( $row['kind'], array( 'fish', 'credit', 'note' ), true ) ? (string) $row['kind'] : 'fish';
		$price  = isset( $row['price'] ) && null !== $row['price'] && is_numeric( $row['price'] ) ? Lines::money( (float) $row['price'] ) : null;
		$label  = Lines::text( isset( $row['size_label'] ) ? $row['size_label'] : '', Lines::MAX_SIZE );

		return array(
			'kind'         => $kind,
			'source'       => $source,
			'name'         => $name,
			'detail'       => Lines::text( isset( $row['detail'] ) ? $row['detail'] : '', 300 ),
			'warn'         => Lines::text( isset( $row['warn'] ) ? $row['warn'] : '', 300 ),
			'price'        => $price,
			'price_from'   => Lines::text( isset( $row['price_from'] ) ? $row['price_from'] : '', 20 ),
			'qty'          => max( 1, min( Lines::MAX_QTY, $int( 'qty' ) ) ),
			'product_id'   => $int( 'product_id' ),
			'variation_id' => $int( 'variation_id' ),
			'species_id'   => $int( 'species_id' ),
			'stay_id'      => $int( 'stay_id' ),
			'size_slug'    => (string) Lines::slug( isset( $row['size_slug'] ) ? $row['size_slug'] : '' ),
			'size_label'   => $label,
			'managed'      => ! empty( $row['managed'] ),
			'stock'        => isset( $row['stock'] ) && null !== $row['stock'] && is_numeric( $row['stock'] ) ? (int) $row['stock'] : null,
			'alive'        => isset( $row['alive'] ) && null !== $row['alive'] && is_numeric( $row['alive'] ) ? (int) $row['alive'] : null,
			'reserved'     => $int( 'reserved' ),
		);
	}

	/**
	 * One group, cleaned (pure): a key, a label, whether it could be
	 * read, a note, its rows.
	 *
	 * @param mixed $group Group.
	 * @return array|null
	 */
	public static function clean_group( $group ) {
		if ( ! is_array( $group ) ) {
			return null;
		}
		$key = isset( $group['key'] ) && is_scalar( $group['key'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $group['key'] ) ) : '';
		if ( '' === $key ) {
			return null;
		}

		$items = array();
		foreach ( isset( $group['items'] ) && is_array( $group['items'] ) ? $group['items'] : array() as $row ) {
			$item = self::clean_item( $row );
			if ( $item ) {
				$items[] = $item;
			}
			if ( count( $items ) >= 50 ) {
				break;
			}
		}

		return array(
			'key'       => $key,
			'label'     => Lines::text( isset( $group['label'] ) ? $group['label'] : $key, 80 ),
			'available' => ! isset( $group['available'] ) || ! empty( $group['available'] ),
			'note'      => Lines::text( isset( $group['note'] ) ? $group['note'] : '', 300 ),
			'items'     => $items,
		);
	}

	/**
	 * The name a line gets from a species and a size: "Blue Tang (Medium)".
	 *
	 * @param string      $species    Species.
	 * @param string|null $size_label Size label.
	 * @return string
	 */
	public static function sized_name( $species, $size_label ) {
		$species = trim( (string) $species );
		$size    = trim( (string) $size_label );
		return '' !== $size ? $species . ' (' . $size . ')' : $species;
	}

	/**
	 * The "in the tanks now" rows.
	 *
	 * @param object $shop       Shop.
	 * @param object $tanks      Tanks.
	 * @param object $store      Store.
	 * @param string $term       Search text.
	 * @param int    $invoice_id The invoice being built (its own lines do not count as "on other invoices").
	 * @return array[]
	 */
	private static function tank_items( $shop, $tanks, $store, $term, $invoice_id ) {
		$groups = $tanks->search( $term );
		if ( ! $groups ) {
			return array();
		}

		$reserved = $store->stay_reservations( array_column( $groups, 'stay_id' ), $invoice_id );
		$items    = array();

		foreach ( $groups as $group ) {
			$price      = array(
				'price'        => null,
				'variation_id' => 0,
				'from'         => '',
			);
			$species_id = 0;
			if ( $group['product_id'] ) {
				$price = $shop->price_for( $group['product_id'], $group['size_slug'] );
			}
			if ( null === $price['price'] ) {
				$listed = $tanks->species_price( $group['product_id'], $group['species'] );
				if ( null !== $listed['price'] ) {
					$price['price'] = $listed['price'];
					$price['from']  = 'species';
				}
				$species_id = $listed['species_id'];
			}

			$on_other = isset( $reserved[ $group['stay_id'] ] ) ? (int) $reserved[ $group['stay_id'] ] : 0;
			$detail   = array();
			/* translators: %d: fish alive in the group */
			$detail[] = sprintf( __( '%d alive', 'fishotel-misc-plugin' ), $group['alive'] );
			if ( $group['tank'] ) {
				/* translators: %d: tank number */
				$detail[] = sprintf( __( 'tank %d', 'fishotel-misc-plugin' ), $group['tank'] );
			}
			if ( '' !== $group['order_ref'] ) {
				/* translators: %s: supplier order reference */
				$detail[] = sprintf( __( 'order %s', 'fishotel-misc-plugin' ), $group['order_ref'] );
			}
			if ( $on_other > 0 ) {
				/* translators: %d: fish */
				$detail[] = sprintf( __( '%d on other open invoices', 'fishotel-misc-plugin' ), $on_other );
			}

			$items[] = array(
				'source'       => 'tank',
				'name'         => self::sized_name( $group['species'], $group['size_label'] ),
				'detail'       => implode( ' · ', $detail ),
				'warn'         => $group['needs_id'] ? __( 'not identified yet', 'fishotel-misc-plugin' ) : '',
				'price'        => $price['price'],
				'price_from'   => $price['from'],
				'product_id'   => (int) $group['product_id'],
				'variation_id' => (int) $price['variation_id'],
				'species_id'   => $species_id,
				'stay_id'      => $group['stay_id'],
				'size_slug'    => $group['size_slug'],
				'size_label'   => $group['size_label'],
				'alive'        => $group['alive'],
				'reserved'     => $on_other,
			);
		}

		return $items;
	}

	/**
	 * The "shop products" rows.
	 *
	 * @param object $shop     Shop.
	 * @param object $tanks    Tanks.
	 * @param array  $settings Settings.
	 * @param string $term     Search text.
	 * @return array[]
	 */
	private static function product_items( $shop, $tanks, array $settings, $term ) {
		$items = array();
		foreach ( $shop->search_products( $term, $settings['category_slug'] ) as $row ) {
			$detail = array();
			if ( ! empty( $row['managed'] ) ) {
				/* translators: %d: stock quantity */
				$detail[] = sprintf( __( 'shop stock %d', 'fishotel-misc-plugin' ), (int) $row['stock'] );
			} else {
				$detail[] = __( 'stock not tracked', 'fishotel-misc-plugin' );
			}
			if ( 'publish' !== $row['status'] ) {
				/* translators: %s: product status */
				$detail[] = sprintf( __( 'product is %s', 'fishotel-misc-plugin' ), $row['status'] );
			}
			if ( null !== $row['price'] && null !== $row['regular_price'] && Lines::cents( $row['price'] ) < Lines::cents( $row['regular_price'] ) ) {
				/* translators: %s: regular price */
				$detail[] = sprintf( __( 'on sale, regular %s', 'fishotel-misc-plugin' ), Lines::dollars( $row['regular_price'] ) );
			}

			$species_id = 0;
			if ( null === $row['price'] ) {
				$listed = $tanks->species_price( $row['product_id'], $row['name'] );
				if ( null !== $listed['price'] ) {
					$row['price']      = $listed['price'];
					$row['price_from'] = 'species';
				}
				$species_id = $listed['species_id'];
			}

			$row['species_id'] = $species_id;
			$row['detail']     = implode( ' · ', $detail );
			$items[]           = $row;
		}
		return $items;
	}

	/**
	 * Search everything.
	 *
	 * @param object $shop       Shop.
	 * @param object $tanks      Tanks.
	 * @param object $store      Store.
	 * @param array  $settings   Settings.
	 * @param string $term       Search text.
	 * @param int    $invoice_id The invoice being built.
	 * @return array[] Cleaned groups.
	 */
	public static function run( $shop, $tanks, $store, array $settings, $term, $invoice_id = 0 ) {
		$term   = Lines::text( $term, 120 );
		$groups = array();

		if ( strlen( $term ) < self::MIN_LENGTH ) {
			return array();
		}

		// (a) In the tanks now.
		$groups[] = array(
			'key'       => 'tanks',
			'label'     => __( 'In the tanks now', 'fishotel-misc-plugin' ),
			'available' => $tanks->available(),
			'note'      => $tanks->available() ? '' : $tanks->reason(),
			'items'     => $tanks->available() ? self::tank_items( $shop, $tanks, $store, $term, (int) $invoice_id ) : array(),
		);

		// (b) Shop products and their size variations.
		$groups[] = array(
			'key'       => 'products',
			'label'     => __( 'Shop products', 'fishotel-misc-plugin' ),
			'available' => $shop->available(),
			'note'      => $shop->available() ? '' : __( 'WooCommerce is not active, so there are no shop products to pick from.', 'fishotel-misc-plugin' ),
			'items'     => $shop->available() ? self::product_items( $shop, $tanks, $settings, $term ) : array(),
		);

		// (c) The species list.
		$species = array();
		foreach ( $tanks->available() ? $tanks->species_search( $term ) : array() as $row ) {
			$species[] = array(
				'source'     => 'species',
				'name'       => $row['name'],
				'detail'     => '' !== $row['sci_name'] ? $row['sci_name'] : '',
				'price'      => $row['price'],
				'price_from' => null !== $row['price'] ? 'species' : '',
				'species_id' => $row['species_id'],
				'product_id' => (int) $row['product_id'],
			);
		}
		$groups[] = array(
			'key'       => 'species',
			'label'     => __( 'Species list', 'fishotel-misc-plugin' ),
			'available' => $tanks->available(),
			'note'      => $tanks->available() ? '' : __( 'The species list belongs to the Clean Room section, which cannot be read.', 'fishotel-misc-plugin' ),
			'items'     => $species,
		);

		// (d) Use what I typed.
		$groups[] = array(
			'key'       => 'typed',
			'label'     => __( 'Use what I typed', 'fishotel-misc-plugin' ),
			'available' => true,
			'note'      => '',
			'items'     => array(
				array(
					'source' => 'typed',
					'name'   => $term,
					'detail' => __( 'a fish that is on no list — type its price', 'fishotel-misc-plugin' ),
				),
			),
		);

		/**
		 * Filter the picker's search groups — add a group of your own.
		 *
		 * @param array[] $groups     key, label, available, note, items (rows of Search::clean_item()).
		 * @param string  $term       The search text.
		 * @param int     $invoice_id The invoice being built (0 = a new one).
		 */
		$groups = apply_filters( 'fishotel_invoices_search_groups', $groups, $term, (int) $invoice_id );

		$out = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			$clean = self::clean_group( $group );
			if ( $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}
}
