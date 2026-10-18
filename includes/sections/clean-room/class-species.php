<?php
/**
 * Clean Room species — PURE: the species master row (what the workbook's
 * Fishlist held: name, scientific name, group, fish per box, FisHotel's
 * price, a competitor's price / site / date, reef-safe, max size, an
 * optional product link) normalised from a form, and the merge of those
 * rows into the Arrival screen's candidate list next to the products.
 *
 * A species row may or may not be linked to a product: most species are
 * never listed, so creating draft products for all of them would clutter
 * WooCommerce. The Arrival screen's search and the voice parser read
 * both lists, deduped by lower-cased name (a species row whose name is a
 * product's title adds its scientific name to that product instead of a
 * second entry).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Species
 */
class Species {

	/**
	 * reef_safe values ('' = unknown).
	 *
	 * @var string[]
	 */
	const REEF_SAFE = array( '', 'yes', 'no', 'caution' );

	const MAX_PER_BOX = 999;
	const MAX_PRICE   = 99999.99;

	/**
	 * Clean a species row from a form (or the importer): typed, trimmed,
	 * clamped. The name is required — a row with an empty name is for the
	 * caller to refuse.
	 *
	 * @param array $raw name, sci_name, group_name, per_box, my_price, online_price, online_site,
	 *                   online_date, reef_safe, max_size, product_id, notes, id.
	 * @return array The clean row (id 0 = new).
	 */
	public static function normalize( array $raw ) {
		$text = static function ( $key, $max ) use ( $raw ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( preg_replace( '/\s+/', ' ', (string) $raw[ $key ] ) ) : '';
			return (string) substr( $value, 0, $max );
		};
		$money = static function ( $key ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				return null;
			}
			$value = str_replace( array( '$', ',', ' ' ), '', (string) $raw[ $key ] );
			if ( '' === $value || ! is_numeric( $value ) ) {
				return null;
			}
			$value = round( (float) $value, 2 );
			return ( $value < 0 || $value > self::MAX_PRICE ) ? null : $value;
		};

		$per_box = isset( $raw['per_box'] ) && is_scalar( $raw['per_box'] ) && is_numeric( $raw['per_box'] ) ? (int) $raw['per_box'] : 0;
		if ( $per_box < 1 || $per_box > self::MAX_PER_BOX ) {
			$per_box = Costing::DEFAULT_PER_BOX;
		}

		$reef = isset( $raw['reef_safe'] ) && is_scalar( $raw['reef_safe'] ) ? strtolower( trim( (string) $raw['reef_safe'] ) ) : '';
		if ( 'with caution' === $reef ) {
			$reef = 'caution';
		}
		if ( ! in_array( $reef, self::REEF_SAFE, true ) ) {
			$reef = '';
		}

		$date = $text( 'online_date', 10 );
		if ( ! Replay::is_date( $date ) ) {
			$date = null;
		}

		$site = $text( 'online_site', 60 );

		return array(
			'id'           => isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? max( 0, (int) $raw['id'] ) : 0,
			'name'         => $text( 'name', 120 ),
			'sci_name'     => $text( 'sci_name', 120 ),
			'group_name'   => $text( 'group_name', 60 ),
			'per_box'      => $per_box,
			'my_price'     => $money( 'my_price' ),
			'online_price' => $money( 'online_price' ),
			'online_site'  => '' !== $site ? $site : null,
			'online_date'  => $date,
			'reef_safe'    => $reef,
			'max_size'     => $text( 'max_size', 40 ),
			'product_id'   => isset( $raw['product_id'] ) && is_scalar( $raw['product_id'] ) && (int) $raw['product_id'] > 0 ? (int) $raw['product_id'] : null,
			'notes'        => isset( $raw['notes'] ) && is_scalar( $raw['notes'] ) ? (string) substr( trim( (string) $raw['notes'] ), 0, 2000 ) : '',
		);
	}

	/**
	 * The key two names are deduped on.
	 *
	 * @param string $name A name.
	 * @return string
	 */
	public static function key( $name ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
	}

	/**
	 * Merge the species rows into the product list the candidate builder
	 * and the search share: one entry per lower-cased name. A species row
	 * linked to a listed product, or named like one, folds into that
	 * product (its scientific name fills a missing one, a different common
	 * name joins the product's common names); any other row becomes an
	 * entry of its own with its product link (which may point at a product
	 * outside the fish categories) or none.
	 *
	 * @param array[] $products product_id, name, common, sci.
	 * @param array[] $species  id, name, sci_name, product_id (+ per_box, my_price).
	 * @return array[] product_id, name, common, sci, species_id (+ per_box, my_price when given).
	 */
	public static function merge_candidates( array $products, array $species ) {
		$out        = array();
		$by_product = array();
		$by_name    = array();

		foreach ( $products as $product ) {
			$name = (string) ( $product['name'] ?? '' );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$key = self::key( $name );
			if ( isset( $by_name[ $key ] ) ) {
				continue;
			}
			$index         = count( $out );
			$out[ $index ] = array(
				'product_id' => isset( $product['product_id'] ) && (int) $product['product_id'] > 0 ? (int) $product['product_id'] : null,
				'name'       => $name,
				'common'     => (string) ( $product['common'] ?? '' ),
				'sci'        => (string) ( $product['sci'] ?? '' ),
				'species_id' => null,
			);
			$by_name[ $key ] = $index;
			if ( null !== $out[ $index ]['product_id'] ) {
				$by_product[ $out[ $index ]['product_id'] ] = $index;
			}
		}

		foreach ( $species as $row ) {
			$name = (string) ( $row['name'] ?? '' );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$key   = self::key( $name );
			$pid   = isset( $row['product_id'] ) && (int) $row['product_id'] > 0 ? (int) $row['product_id'] : null;
			$sci   = (string) ( $row['sci_name'] ?? '' );
			$index = null;
			if ( null !== $pid && isset( $by_product[ $pid ] ) ) {
				$index = $by_product[ $pid ];
			} elseif ( isset( $by_name[ $key ] ) ) {
				$index = $by_name[ $key ];
			}

			if ( null !== $index ) {
				$entry = &$out[ $index ];
				if ( '' === trim( $entry['sci'] ) && '' !== trim( $sci ) ) {
					$entry['sci'] = $sci;
				}
				if ( self::key( $entry['name'] ) !== $key && ! in_array( $key, array_map( array( self::class, 'key' ), Store::split_names( $entry['common'] ) ), true ) ) {
					$entry['common'] = '' !== trim( $entry['common'] ) ? $entry['common'] . ', ' . $name : $name;
				}
				if ( null === $entry['species_id'] ) {
					$entry['species_id'] = (int) ( $row['id'] ?? 0 ) > 0 ? (int) $row['id'] : null;
					foreach ( array( 'per_box', 'my_price' ) as $extra ) {
						if ( array_key_exists( $extra, $row ) ) {
							$entry[ $extra ] = $row[ $extra ];
						}
					}
				}
				unset( $entry );
				$by_name[ $key ] = $index;
				continue;
			}

			$index         = count( $out );
			$out[ $index ] = array(
				'product_id' => $pid,
				'name'       => $name,
				'common'     => '',
				'sci'        => $sci,
				'species_id' => (int) ( $row['id'] ?? 0 ) > 0 ? (int) $row['id'] : null,
			);
			foreach ( array( 'per_box', 'my_price' ) as $extra ) {
				if ( array_key_exists( $extra, $row ) ) {
					$out[ $index ][ $extra ] = $row[ $extra ];
				}
			}
			$by_name[ $key ] = $index;
			if ( null !== $pid ) {
				$by_product[ $pid ] = $index;
			}
		}

		return array_values( $out );
	}

	/**
	 * Whether a species row matches a search term: a prefix of the name or
	 * of the scientific name, or of any word in them (the list filter and
	 * the in-memory store).
	 *
	 * @param array  $row  name, sci_name.
	 * @param string $term Search text.
	 * @return bool
	 */
	public static function matches( array $row, $term ) {
		$term = self::key( $term );
		if ( '' === $term ) {
			return true;
		}
		foreach ( array( (string) ( $row['name'] ?? '' ), (string) ( $row['sci_name'] ?? '' ) ) as $text ) {
			$text = self::key( $text );
			if ( 0 === strpos( $text, $term ) || false !== strpos( $text, ' ' . $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A species row as a search-box item (the shape species_suggestions()
	 * returns for products, plus the species fields the order editor
	 * prefills from).
	 *
	 * @param array $row Hydrated species row.
	 * @return array
	 */
	public static function suggestion( array $row ) {
		return array(
			'product_id' => isset( $row['product_id'] ) && (int) $row['product_id'] > 0 ? (int) $row['product_id'] : null,
			'name'       => (string) ( $row['name'] ?? '' ),
			'common'     => '',
			'sci'        => (string) ( $row['sci_name'] ?? '' ),
			'match'      => 'species',
			'alias'      => '',
			'species_id' => (int) ( $row['id'] ?? 0 ),
			'per_box'    => (int) ( $row['per_box'] ?? Costing::DEFAULT_PER_BOX ),
			'my_price'   => isset( $row['my_price'] ) && null !== $row['my_price'] ? (float) $row['my_price'] : null,
			'group_name' => (string) ( $row['group_name'] ?? '' ),
		);
	}
}
