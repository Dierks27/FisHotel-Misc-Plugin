<?php
/**
 * Sticker Giveaway orders — the moments the giveaway pile moves, and what
 * WooCommerce is told about a giveaway line on an order.
 *
 * An order line of a design that keeps a giveaway pile is stamped with
 * `_fh_sticker_pile` (the design id) when the order is created
 * (Cart::order_line_item_meta()). From then on:
 *
 *   - Whenever WooCommerce asks that order line for its product
 *     (`woocommerce_order_item_product`), it gets the giveaway-line object
 *     (class-pile-products.php): in stock, not managing stock. That one
 *     answer is what keeps WooCommerce's hands off the shop pile for this
 *     line — it holds no stock for it at Place Order, does not refuse the
 *     order because the shop has none, does not reduce the shop pile when
 *     the order is paid, and does not "restore" it on a cancel or a
 *     refund. No order note, no low-stock email, nothing saved on the
 *     product.
 *
 *   - The GIVEAWAY pile goes down when WooCommerce itself reduces stock
 *     for the order (`woocommerce_reduce_order_stock`: payment complete,
 *     or the order reaching processing / completed / on-hold), and comes
 *     back when WooCommerce itself restores it
 *     (`woocommerce_restore_order_stock`: the order cancelled, failed, or
 *     back to pending) or when a refund is made with "Restock refunded
 *     items" ticked (`woocommerce_refund_created`).
 *
 * Each of those happens once per order line however often the hooks fire:
 * Store::move() keeps one ledger row per order line and only the request
 * that changes the row moves the pile. The order line also carries
 * `_fh_sticker_pile_taken` (how many it holds from the pile right now), so
 * the order itself shows what was done.
 *
 * WooCommerce only fires its reduce / restore hooks while its own stock
 * management is on (WooCommerce → Settings → Products → Inventory); with
 * it off, no pile is counted down. The settings page says so.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Orders
 */
class Orders {

	/**
	 * Order line meta: the design whose giveaway pile the line comes out
	 * of (hidden).
	 *
	 * @var string
	 */
	const META_PILE = '_fh_sticker_pile';

	/**
	 * Order line meta: how many stickers the line holds from the pile
	 * right now (hidden; absent = none).
	 *
	 * @var string
	 */
	const META_TAKEN = '_fh_sticker_pile_taken';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_order_item_product', array( $this, 'pile_product' ), 20, 2 );
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'take' ), 10, 1 );
		add_action( 'woocommerce_restore_order_stock', array( $this, 'give_back' ), 10, 1 );
		add_action( 'woocommerce_refund_created', array( $this, 'refund' ), 10, 2 );
	}

	/**
	 * The design an order line takes from the giveaway pile, or 0.
	 *
	 * @param mixed $item Order line.
	 * @return int
	 */
	public static function pile_design( $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return 0;
		}

		return max( 0, (int) $item->get_meta( self::META_PILE, true ) );
	}

	/**
	 * `woocommerce_order_item_product` — the giveaway-line object for a
	 * stamped order line; every other line is handed back untouched.
	 *
	 * @param \WC_Product|bool $product What WooCommerce resolved.
	 * @param mixed            $item    Order line.
	 * @return \WC_Product|bool
	 */
	public function pile_product( $product, $item ) {
		if ( ! $product instanceof \WC_Product || ! self::pile_design( $item ) ) {
			return $product;
		}

		$pile = Designs::pile_product( $product );

		return $pile ? $pile : $product;
	}

	/**
	 * `woocommerce_reduce_order_stock` — WooCommerce has just reduced
	 * stock for this order: its giveaway lines count their piles down.
	 *
	 * @param mixed $order The order.
	 */
	public function take( $order ) {
		try {
			$moved = array();

			foreach ( self::pile_lines( $order ) as $item_id => $item ) {
				$design = self::pile_design( $item );
				$qty    = max( 0, (int) $item->get_quantity() );
				$delta  = Designs::store()->move( $item_id, Piles::TAKE, $qty, $design, (int) $order->get_id(), (string) $item->get_meta( '_fh_sticker', true ) );

				if ( 0 === $delta ) {
					continue;
				}

				$item->update_meta_data( self::META_TAKEN, $qty );
				$item->save();
				$moved[] = self::describe( $item, $delta );
			}

			self::note( $order, $moved );

			// A normal sale may have taken the shop pile down too: look at every reorder number.
			Reorder::check();
		} catch ( \Throwable $e ) {
			unset( $e ); // A sticker count never stops an order being paid.
		}
	}

	/**
	 * `woocommerce_restore_order_stock` — WooCommerce has just restored
	 * stock for this order (cancelled, failed, back to pending): what its
	 * giveaway lines still hold goes back on their piles.
	 *
	 * @param mixed $order The order.
	 */
	public function give_back( $order ) {
		try {
			$moved = array();

			foreach ( self::pile_lines( $order ) as $item_id => $item ) {
				$delta = Designs::store()->move( $item_id, Piles::RETURN );

				if ( 0 === $delta ) {
					continue;
				}

				$item->delete_meta_data( self::META_TAKEN );
				$item->save();
				$moved[] = self::describe( $item, $delta );
			}

			self::note( $order, $moved );
			Reorder::check();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * `woocommerce_refund_created` — a refund with "Restock refunded items"
	 * ticked puts the refunded quantity of each giveaway line back on its
	 * pile. A refund without the tick puts nothing back (the stickers are
	 * with the customer).
	 *
	 * @param int   $refund_id Refund id.
	 * @param array $args      What the refund was made with.
	 */
	public function refund( $refund_id, $args ) {
		unset( $refund_id );

		if ( ! is_array( $args ) || empty( $args['restock_items'] ) || empty( $args['line_items'] ) || ! is_array( $args['line_items'] ) ) {
			return;
		}

		try {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $args['order_id'] ?? 0 ) ) : null;
			if ( ! $order ) {
				return;
			}

			$lines = self::pile_lines( $order );
			$moved = array();

			foreach ( $args['line_items'] as $item_id => $line ) {
				$item_id = (int) $item_id;
				$qty     = is_array( $line ) ? abs( (int) ( $line['qty'] ?? 0 ) ) : 0;

				if ( $qty < 1 || ! isset( $lines[ $item_id ] ) ) {
					continue;
				}

				$delta = Designs::store()->move( $item_id, Piles::REFUND, $qty );
				if ( 0 === $delta ) {
					continue;
				}

				$item = $lines[ $item_id ];
				$held = max( 0, (int) $item->get_meta( self::META_TAKEN, true ) - $delta );
				if ( $held > 0 ) {
					$item->update_meta_data( self::META_TAKEN, $held );
				} else {
					$item->delete_meta_data( self::META_TAKEN );
				}
				$item->save();
				$moved[] = self::describe( $item, $delta );
			}

			self::note( $order, $moved );
			Reorder::check();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The order's lines that come out of a giveaway pile.
	 *
	 * @param mixed $order The order.
	 * @return array<int, \WC_Order_Item_Product>
	 */
	private static function pile_lines( $order ) {
		$out = array();

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}

		foreach ( (array) $order->get_items() as $item_id => $item ) {
			if ( is_object( $item ) && method_exists( $item, 'is_type' ) && $item->is_type( 'line_item' ) && self::pile_design( $item ) ) {
				$out[ (int) $item_id ] = $item;
			}
		}

		return $out;
	}

	/**
	 * One line of the order note: "Blue Tang −1" / "Blue Tang +1".
	 *
	 * @param mixed $item  Order line.
	 * @param int   $delta The change to the pile.
	 * @return string
	 */
	private static function describe( $item, $delta ) {
		$product = Designs::product( self::pile_design( $item ) );
		$name    = $product ? Designs::name( $product ) : (string) $item->get_name();

		return $name . ' ' . ( $delta < 0 ? '−' : '+' ) . abs( (int) $delta );
	}

	/**
	 * Say on the order what moved (a private note, as WooCommerce writes
	 * for its own stock).
	 *
	 * @param mixed    $order The order.
	 * @param string[] $moved describe() lines.
	 */
	private static function note( $order, array $moved ) {
		if ( ! $moved || ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		try {
			/* translators: %s: comma-separated "Design −1" entries */
			$order->add_order_note( sprintf( __( 'Sticker giveaway pile: %s.', 'fishotel-misc-plugin' ), implode( ', ', $moved ) ) );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
