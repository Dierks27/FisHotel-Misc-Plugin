<?php
/**
 * Clean Room shop price — what an order line's fish sells for, READ from
 * the shop instead of typed. "I don't know what it's worth when I am
 * importing the fish… I have the cost."
 *
 * For every line, in this order:
 *
 *   1. the line's product — the one its species-list row is linked to,
 *      else the ONE fish product named exactly like the line
 *      (Orders::product_named()): its current price. A product with
 *      variations answers with the variation of the line's size when
 *      exactly one price fits it, else with its lowest price ("from");
 *   2. else the species list's own price (my_price);
 *   3. else nothing — the order page then says "no shop price yet".
 *
 * The price itself is Stats_Prices::fish_price() — the same number the
 * "Prices to raise" view shows, read through Store::product_prices() (the
 * product's active price, straight from the product tables). Nothing is
 * stored and nothing here writes a price anywhere: the figure is read
 * again every time an order is resolved, so it follows the shop until the
 * owner types a price of their own on the line (worth — an override the
 * costing prefers, Costing::worth_of()). The read price is still handed
 * along beside an override, so the page can say what the shop says.
 *
 * A fish that still has to be identified has no product and no list
 * price: it has no shop price.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shop_Price
 */
class Shop_Price {

	/**
	 * Where a line's worth can come from (Costing::worth_of()): typed on the
	 * line, or read — a variation, a product, the species list.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'typed', 'variation', 'product', 'list' );

	/**
	 * Read the shop price of every line of a set of orders, in one read of
	 * the product prices (Store::product_prices()).
	 *
	 * @param object  $store  Store or stand-in (fish_product_names(), product_prices()).
	 * @param array[] $orders Orders, each with 'lines'.
	 * @return array[] The orders, every line with shop_price (float|null), shop_price_from
	 *                 (variation | product | list | ''), shop_price_range (bool: the lowest of
	 *                 several prices) and shop_product_id (int|null).
	 */
	public static function fill_orders( $store, array $orders ) {
		$names = is_object( $store ) && method_exists( $store, 'fish_product_names' ) ? self::name_index( (array) $store->fish_product_names() ) : array();

		$ids = array();
		foreach ( $orders as $o => $order ) {
			foreach ( (array) ( $order['lines'] ?? array() ) as $l => $line ) {
				$id = is_array( $line ) ? self::product_of( $line, $names ) : null;
				$orders[ $o ]['lines'][ $l ]['shop_product_id'] = $id;
				if ( null !== $id ) {
					$ids[ $id ] = true;
				}
			}
		}

		$prices = $ids && is_object( $store ) && method_exists( $store, 'product_prices' ) ? (array) $store->product_prices( array_keys( $ids ) ) : array();

		foreach ( $orders as $o => $order ) {
			foreach ( (array) ( $order['lines'] ?? array() ) as $l => $line ) {
				$orders[ $o ]['lines'][ $l ] = array_merge( $line, self::line_price( $line, $prices ) );
			}
		}

		return $orders;
	}

	/**
	 * One order (fill_orders() for a single one).
	 *
	 * @param object $store Store or stand-in.
	 * @param array  $order The order with its lines.
	 * @return array
	 */
	public static function fill_order( $store, array $order ) {
		$filled = self::fill_orders( $store, array( $order ) );
		return $filled[0];
	}

	/**
	 * The fish product names by their key, for the "named exactly like the
	 * line" rule — built once for a whole set of orders.
	 *
	 * @param array $products product id => title.
	 * @return array<string, int[]> name key => product ids.
	 */
	public static function name_index( array $products ) {
		$out = array();
		foreach ( $products as $id => $title ) {
			$key = Species::key( (string) $title );
			if ( (int) $id > 0 && '' !== $key ) {
				$out[ $key ][] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * The product a line's fish is (pure): the one its species row is linked
	 * to, else the ONE product named exactly like it; none for a fish that
	 * still has to be identified.
	 *
	 * @param array $line  The line (needs_id, species_product_id, species).
	 * @param array $names name_index().
	 * @return int|null
	 */
	public static function product_of( array $line, array $names ) {
		if ( ! empty( $line['needs_id'] ) ) {
			return null;
		}
		if ( ! empty( $line['species_product_id'] ) && (int) $line['species_product_id'] > 0 ) {
			return (int) $line['species_product_id'];
		}
		$key = Species::key( (string) ( $line['species'] ?? '' ) );
		return '' !== $key && isset( $names[ $key ] ) && 1 === count( $names[ $key ] ) ? (int) $names[ $key ][0] : null;
	}

	/**
	 * The shop price of one line from what was read (pure).
	 *
	 * @param array $line   The line: shop_product_id (product_of()), species_my_price, size_slug, needs_id.
	 * @param array $prices Product id → prices / variations (Store::product_prices()).
	 * @return array{shop_price: float|null, shop_price_from: string, shop_price_range: bool, shop_product_id: int|null}
	 */
	public static function line_price( array $line, array $prices ) {
		$product_id = ! empty( $line['shop_product_id'] ) ? (int) $line['shop_product_id'] : null;
		$list       = empty( $line['needs_id'] ) ? ( $line['species_my_price'] ?? null ) : null;

		$found = Stats_Prices::fish_price(
			array(
				'product_id' => (int) $product_id,
				'my_price'   => $list,
			),
			$prices,
			(string) ( $line['size_slug'] ?? '' )
		);

		return array(
			'shop_price'       => $found['price'],
			'shop_price_from'  => null !== $found['price'] ? (string) $found['source'] : '',
			'shop_price_range' => null !== $found['price'] && ! empty( $found['from'] ),
			'shop_product_id'  => $product_id,
		);
	}

	/**
	 * Where a line's price comes from, in a sentence — the tooltip on the
	 * order page's shop price.
	 *
	 * @param array $line A costed line: worth (typed or null), worth_from, shop_price, shop_price_from,
	 *                    shop_price_range, size_label.
	 * @return string
	 */
	public static function source_text( array $line ) {
		$read = isset( $line['shop_price'] ) && null !== $line['shop_price'] ? (float) $line['shop_price'] : null;
		$from = (string) ( $line['shop_price_from'] ?? '' );
		$size = trim( (string) ( $line['size_label'] ?? '' ) );

		if ( 'variation' === $from ) {
			/* translators: %s: a size */
			$shop = '' !== $size ? sprintf( __( 'the product\'s %s variation, as the shop has it now', 'fishotel-misc-plugin' ), $size ) : __( 'the product\'s variation of this size, as the shop has it now', 'fishotel-misc-plugin' );
		} elseif ( 'product' === $from ) {
			$shop = ! empty( $line['shop_price_range'] ) ? __( 'the lowest price of the product\'s variations, as the shop has it now', 'fishotel-misc-plugin' ) : __( 'the product\'s price, as the shop has it now', 'fishotel-misc-plugin' );
		} elseif ( 'list' === $from ) {
			$shop = __( 'the species list\'s price (no product price was found)', 'fishotel-misc-plugin' );
		} else {
			$shop = '';
		}

		if ( 'typed' === (string) ( $line['worth_from'] ?? '' ) ) {
			if ( null === $read ) {
				return __( 'Typed by hand on this line. The shop has no price for this fish yet.', 'fishotel-misc-plugin' );
			}
			/* translators: 1: a price, 2: where it comes from */
			return sprintf( __( 'Typed by hand on this line. The shop says %1$s — %2$s. Empty the field to follow the shop again.', 'fishotel-misc-plugin' ), Costing::money( $read ), $shop );
		}
		if ( null === $read ) {
			return __( 'No shop price yet: no product is linked to this fish (or it has no price), and the species list has none. Link a product on the Species tab, or type a price with the pencil.', 'fishotel-misc-plugin' );
		}
		/* translators: %s: where the price comes from */
		return sprintf( __( 'Read from the shop: %s. The pencil types a price of your own.', 'fishotel-misc-plugin' ), $shop );
	}
}
