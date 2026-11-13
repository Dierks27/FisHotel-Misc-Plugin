<?php
/**
 * The tanks — this section's only way into the Clean Room section of the
 * same plugin: the groups of fish in the tanks now (species, size, how
 * many alive, tank, which supplier order) and the species list with its
 * prices.
 *
 * The Clean Room may be switched off, absent or older, so nothing here
 * names its classes at load time: each is looked up with class_exists()
 * (without autoloading — its files are only loaded when that section
 * booted) and each method with method_exists(), every call is wrapped,
 * and available() says plainly why there is nothing to pick from. This
 * section keeps working without it: fish can still be picked from the
 * shop or typed.
 *
 * Read-only: nothing in the Clean Room is ever written from here.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tanks
 */
class Tanks {

	/**
	 * The Clean Room's namespace.
	 *
	 * @var string
	 */
	const CLEAN_ROOM = '\\FisHotel\\Misc\\Sections\\Clean_Room\\';

	/**
	 * Memoised: array( store, today, settings ), or false when unavailable.
	 *
	 * @var array|false|null
	 */
	private $context = null;

	/**
	 * Why the tanks cannot be read ('' when they can).
	 *
	 * @var string
	 */
	private $reason = '';

	/**
	 * The Clean Room's store, today's date and its settings — or false.
	 *
	 * @return array|false
	 */
	private function context() {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$this->context = false;
		$store_class   = self::CLEAN_ROOM . 'Store';
		$main_class    = self::CLEAN_ROOM . 'Clean_Room';
		$set_class     = self::CLEAN_ROOM . 'Settings';

		if ( ! class_exists( $store_class, false ) || ! class_exists( $main_class, false ) || ! class_exists( $set_class, false ) ) {
			$this->reason = __( 'The Clean Room section is switched off, so there are no tank groups and no species list to pick from. Fish can still be picked from the shop or typed.', 'fishotel-misc-plugin' );
			return false;
		}
		if ( ! method_exists( $store_class, 'open_stays' ) || ! method_exists( $store_class, 'tables_exist' ) || ! method_exists( $main_class, 'today' ) || ! method_exists( $set_class, 'get' ) ) {
			$this->reason = __( 'This version of the Clean Room section cannot be read from here.', 'fishotel-misc-plugin' );
			return false;
		}

		try {
			if ( ! $store_class::tables_exist() ) {
				$this->reason = __( 'The Clean Room tables do not exist yet.', 'fishotel-misc-plugin' );
				return false;
			}
			$this->context = array( new $store_class(), (string) $main_class::today(), (array) $set_class::get() );
		} catch ( \Throwable $e ) {
			$this->reason = __( 'The Clean Room section could not be read.', 'fishotel-misc-plugin' );
		}

		return $this->context;
	}

	/**
	 * Whether the Clean Room can be read.
	 *
	 * @return bool
	 */
	public function available() {
		return false !== $this->context();
	}

	/**
	 * Why not, in plain words.
	 *
	 * @return string
	 */
	public function reason() {
		$this->context();
		return $this->reason;
	}

	/**
	 * One tank group as a small array.
	 *
	 * @param array $stay A Clean Room stay.
	 * @return array{stay_id: int, species: string, size_slug: string|null, size_label: string|null, alive: int, tank: int|null, order_ref: string, product_id: int|null, needs_id: bool, open: bool}
	 */
	private static function group( array $stay ) {
		$ref = isset( $stay['batch_order_ref'] ) && '' !== (string) $stay['batch_order_ref'] ? (string) $stay['batch_order_ref'] : (string) ( isset( $stay['order_ref'] ) ? $stay['order_ref'] : '' );

		return array(
			'stay_id'    => (int) ( isset( $stay['id'] ) ? $stay['id'] : 0 ),
			'species'    => (string) ( isset( $stay['species'] ) ? $stay['species'] : '' ),
			'size_slug'  => isset( $stay['size_slug'] ) && '' !== (string) $stay['size_slug'] ? (string) $stay['size_slug'] : null,
			'size_label' => isset( $stay['size_label'] ) && '' !== (string) $stay['size_label'] ? (string) $stay['size_label'] : null,
			'alive'      => (int) ( isset( $stay['qty_live'] ) ? $stay['qty_live'] : 0 ),
			'tank'       => ! empty( $stay['tank_number'] ) ? (int) $stay['tank_number'] : null,
			'order_ref'  => $ref,
			'product_id' => ! empty( $stay['product_id'] ) ? (int) $stay['product_id'] : null,
			'needs_id'   => ! empty( $stay['needs_id'] ),
			'open'       => 'closed' !== (string) ( isset( $stay['stored_status'] ) ? $stay['stored_status'] : ( isset( $stay['status'] ) ? $stay['status'] : '' ) ),
		);
	}

	/**
	 * The groups in the tanks now whose species holds every word of a
	 * search, oldest arrival first.
	 *
	 * @param string $term  Search text.
	 * @param int    $limit Row cap.
	 * @return array[] Rows of group().
	 */
	public function search( $term, $limit = 12 ) {
		$context = $this->context();
		$term    = trim( (string) $term );
		if ( ! $context || '' === $term ) {
			return array();
		}

		$lower = static function ( $text ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		};
		$words = array_values( array_filter( (array) preg_split( '/\s+/u', $lower( $term ) ), 'strlen' ) );
		if ( ! $words ) {
			return array();
		}

		// The store filters on one text: ask with the longest word, match the rest here.
		$longest = '';
		foreach ( $words as $word ) {
			if ( strlen( $word ) > strlen( $longest ) ) {
				$longest = $word;
			}
		}

		try {
			$stays = $context[0]->open_stays(
				array(
					'species' => $longest,
					'limit'   => 200,
				),
				$context[1],
				$context[2]
			);
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $stays ) ? $stays : array() as $stay ) {
			if ( ! is_array( $stay ) ) {
				continue;
			}
			$name = $lower( isset( $stay['species'] ) ? $stay['species'] : '' );
			foreach ( $words as $word ) {
				if ( false === strpos( $name, $word ) ) {
					continue 2;
				}
			}
			$group = self::group( $stay );
			if ( $group['stay_id'] > 0 && $group['alive'] > 0 ) {
				$out[] = $group;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One tank group by its id (open or closed), or null.
	 *
	 * @param int $stay_id Stay id.
	 * @return array|null group().
	 */
	public function group_by_id( $stay_id ) {
		$context = $this->context();
		if ( ! $context || (int) $stay_id <= 0 || ! method_exists( $context[0], 'stay' ) ) {
			return null;
		}
		try {
			$stay = $context[0]->stay( (int) $stay_id, $context[1], $context[2] );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $stay ) ? self::group( $stay ) : null;
	}

	/**
	 * Species-list rows whose name (or scientific name) starts a word
	 * with the search text.
	 *
	 * @param string $term  Search text.
	 * @param int    $limit Row cap.
	 * @return array[] species_id, name, sci_name, price, product_id.
	 */
	public function species_search( $term, $limit = 12 ) {
		$context = $this->context();
		$term    = trim( (string) $term );
		if ( ! $context || '' === $term || ! method_exists( $context[0], 'species_list' ) ) {
			return array();
		}

		try {
			$rows = $context[0]->species_list( array( 'search' => $term ), (int) $limit );
		} catch ( \Throwable $e ) {
			return array();
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$out[] = array(
				'species_id' => (int) $row['id'],
				'name'       => (string) ( isset( $row['name'] ) ? $row['name'] : '' ),
				'sci_name'   => (string) ( isset( $row['sci_name'] ) ? $row['sci_name'] : '' ),
				'price'      => isset( $row['my_price'] ) && null !== $row['my_price'] ? Lines::money( (float) $row['my_price'] ) : null,
				'product_id' => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
			);
		}
		return $out;
	}

	/**
	 * The species list's price of a fish: the row linked to its product,
	 * else the row with its name. Null when the list has no price for it.
	 *
	 * @param int|null $product_id Product id.
	 * @param string   $name       Species name.
	 * @return array{price: float|null, species_id: int}
	 */
	public function species_price( $product_id, $name ) {
		$none    = array(
			'price'      => null,
			'species_id' => 0,
		);
		$context = $this->context();
		if ( ! $context ) {
			return $none;
		}

		try {
			$row = null;
			if ( (int) $product_id > 0 && method_exists( $context[0], 'species_by_product' ) ) {
				$row = $context[0]->species_by_product( (int) $product_id );
			}
			if ( ! $row && '' !== trim( (string) $name ) && method_exists( $context[0], 'species_by_name' ) ) {
				$row = $context[0]->species_by_name( (string) $name );
			}
		} catch ( \Throwable $e ) {
			return $none;
		}

		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return $none;
		}

		return array(
			'price'      => isset( $row['my_price'] ) && null !== $row['my_price'] ? Lines::money( (float) $row['my_price'] ) : null,
			'species_id' => (int) $row['id'],
		);
	}
}
