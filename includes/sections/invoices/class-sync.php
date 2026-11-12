<?php
/**
 * Paid sync — when an order that contains an invoice becomes paid, the
 * invoice is marked paid with that order and
 * do_action( 'fishotel_invoice_paid', $invoice_id, $order_id ) fires,
 * once. When that order is cancelled or fails, the invoice is released
 * again (as the theme releases its product) and
 * do_action( 'fishotel_invoice_released', $invoice_id, $order_id ) fires.
 *
 * An order "contains" an invoice when one of its lines is the invoice
 * product (an invoice kept as one line) or carries the theme's
 * `_fh_invoice_product_id` item meta (the lines the theme makes out of an
 * invoice). A fulfilment (combined-box) copy of other orders is ignored:
 * it mirrors their lines and pays for nothing.
 *
 * PAID comes from the order's paid date / WooCommerce's own is_paid() —
 * the Shop reports it — never from a status name. A refunded order keeps
 * its invoice paid (the theme does not release on a refund either).
 *
 * Cost: the two hooks cost nothing until an order is paid, cancelled or
 * failed — a pending or on-hold order returns before any query — and
 * then ONE indexed query on this section's own table, plus one read of
 * the row right before it is written (so a paid action never fires twice
 * for one payment).
 *
 * Only this section's own table is written here: no product, no stock,
 * no order and no e-mail.
 *
 * reconcile() is the same bookkeeping from the other side: when a screen
 * looks at an invoice and finds that the stored state is behind the
 * truth (an order was paid while the section was switched off, a product
 * was put in the Trash by hand), the row is brought up to date — and the
 * actions fire then.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sync
 */
class Sync {

	/**
	 * The state each order was last handled in during this request:
	 * order id → "status:paid".
	 *
	 * @var array<int, string>
	 */
	private static $seen = array();

	/**
	 * Register the order hooks (priority 20: after the theme locked or
	 * released the product).
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_order' ), 20, 1 );
	}

	/**
	 * An order changed status or its payment completed.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order( $order_id ) {
		try {
			if ( ! Store::ready() ) {
				return;
			}

			$shop  = new Shop();
			$order = $shop->order_brief( (int) $order_id );
			if ( ! $order ) {
				return;
			}

			if ( self::repeat( $order ) ) {
				return;
			}

			self::apply( new Store(), $shop, $order );
		} catch ( \Throwable $e ) {
			Invoices::log( 'error', sprintf( 'The paid sync of order #%1$d failed: %2$s', (int) $order_id, $e->getMessage() ), array( 'order_id' => (int) $order_id ) );
		}
	}

	/**
	 * Whether an order comes in the very state it was last handled in —
	 * the two hooks fire one after the other for one payment. Only the
	 * LAST state counts, not every state seen: an order that goes
	 * processing → failed → processing within one request is handled
	 * three times, so the invoice ends up paid again.
	 *
	 * @param array $order Shop::order_brief(): id, status, paid.
	 * @return bool
	 */
	public static function repeat( array $order ) {
		$id  = (int) ( isset( $order['id'] ) ? $order['id'] : 0 );
		$now = ( isset( $order['status'] ) ? (string) $order['status'] : '' ) . ':' . ( ! empty( $order['paid'] ) ? '1' : '0' );
		if ( isset( self::$seen[ $id ] ) && self::$seen[ $id ] === $now ) {
			return true;
		}
		self::$seen[ $id ] = $now;
		return false;
	}

	/**
	 * Bring the invoices in an order up to date with it (the testable
	 * core of on_order()).
	 *
	 * @param object $store Store (or its in-memory twin).
	 * @param object $shop  Shop (or twin) — asked only about another order that already holds an invoice.
	 * @param array  $order Shop::order_brief(): id, status, paid, mirror, product_ids.
	 * @return array{paid: int[], released: int[]} The invoice ids marked paid and released.
	 */
	public static function apply( $store, $shop, array $order ) {
		$out = array(
			'paid'     => array(),
			'released' => array(),
		);

		if ( ! empty( $order['mirror'] ) || empty( $order['product_ids'] ) || empty( $order['id'] ) ) {
			return $out;
		}

		$dead = Rules::order_is_dead( $order );
		$pays = ! $dead && ! empty( $order['paid'] );
		if ( ! $dead && ! $pays ) {
			return $out; // Pending, on hold: nothing to record, and no query.
		}

		$order_id = (int) $order['id'];

		foreach ( $store->invoices_by_products( (array) $order['product_ids'] ) as $invoice ) {
			$state  = (string) $invoice['state'];
			$holder = (int) $invoice['order_id'];

			if ( 'draft' === $state ) {
				continue;
			}

			if ( ! $pays ) {
				if ( 'paid' === $state && $holder === $order_id && self::release( $store, $shop, $invoice, $order_id ) ) {
					$out['released'][] = (int) $invoice['id'];
				}
				continue;
			}

			if ( 'paid' === $state && $holder === $order_id ) {
				continue;
			}

			if ( 'paid' === $state && $holder > 0 ) {
				// Another order already holds it: keep that one while it stands.
				$other = $shop->order_brief( $holder );
				if ( $other && ! Rules::order_is_dead( $other ) ) {
					Invoices::log(
						'warning',
						sprintf( 'Invoice #%1$d was already paid on order #%2$d and is now also in paid order #%3$d.', (int) $invoice['id'], $holder, $order_id ),
						array(
							'invoice_id' => (int) $invoice['id'],
							'order_id'   => $order_id,
						)
					);
					continue;
				}
			}

			if ( self::mark_paid( $store, $invoice, $order_id ) ) {
				$out['paid'][] = (int) $invoice['id'];
			}
		}

		return $out;
	}

	/**
	 * Mark an invoice paid by an order and fire the action.
	 *
	 * @param object $store    Store.
	 * @param array  $invoice  The stored invoice.
	 * @param int    $order_id The order that paid it.
	 * @return bool
	 */
	public static function mark_paid( $store, array $invoice, $order_id ) {
		// The row as it is NOW: a screen may hold it from before the payment's own request marked it.
		$fresh = $store->invoice( (int) $invoice['id'] );
		if ( ! $fresh || ( 'paid' === $fresh['state'] && (int) $fresh['order_id'] === (int) $order_id ) ) {
			return false;
		}

		$result = $store->save_invoice(
			(int) $invoice['id'],
			array(
				'state'    => 'paid',
				'order_id' => (int) $order_id,
				'paid_at'  => $store->now(),
			),
			null,
			null,
			0
		);
		if ( empty( $result['ok'] ) ) {
			return false;
		}

		do_action( Invoices::PAID_ACTION, (int) $invoice['id'], (int) $order_id );

		Invoices::log(
			'info',
			sprintf( 'Invoice #%1$d (%2$s) was paid on order #%3$d.', (int) $invoice['id'], (string) $invoice['title'], (int) $order_id ),
			array(
				'invoice_id' => (int) $invoice['id'],
				'order_id'   => (int) $order_id,
			)
		);

		return true;
	}

	/**
	 * Release a paid invoice whose order was cancelled or failed: back to
	 * published — or, when its product meanwhile went to the Trash or is
	 * gone, to that — and fire the action.
	 *
	 * @param object $store    Store.
	 * @param object $shop     Shop.
	 * @param array  $invoice  The stored invoice.
	 * @param int    $order_id The order that no longer pays it.
	 * @return bool
	 */
	public static function release( $store, $shop, array $invoice, $order_id ) {
		// Only what is still paid by that order is released (and announced) — once.
		$fresh = $store->invoice( (int) $invoice['id'] );
		if ( ! $fresh || 'paid' !== $fresh['state'] || (int) $fresh['order_id'] !== (int) $order_id ) {
			return false;
		}

		$state   = 'published';
		$product = ! empty( $invoice['product_id'] ) ? $shop->product( (int) $invoice['product_id'] ) : null;
		if ( ! $product ) {
			$state = 'cancelled';
		} elseif ( 'trash' === $product['status'] ) {
			$state = 'trashed';
		}

		$result = $store->save_invoice(
			(int) $invoice['id'],
			array(
				'state'    => $state,
				'order_id' => null,
				'paid_at'  => null,
			),
			null,
			null,
			0
		);
		if ( empty( $result['ok'] ) ) {
			return false;
		}

		do_action( Invoices::RELEASED_ACTION, (int) $invoice['id'], (int) $order_id );

		Invoices::log(
			'info',
			sprintf( 'Invoice #%1$d (%2$s) was released: order #%3$d no longer pays it.', (int) $invoice['id'], (string) $invoice['title'], (int) $order_id ),
			array(
				'invoice_id' => (int) $invoice['id'],
				'order_id'   => (int) $order_id,
			)
		);

		return true;
	}

	/**
	 * Bring one stored invoice up to date with what a screen just found
	 * (Rules::effective()). Nothing is written when the two agree.
	 *
	 * @param object $store     Store.
	 * @param object $shop      Shop.
	 * @param array  $invoice   The stored invoice.
	 * @param array  $effective Rules::effective() for it.
	 * @return array The invoice as it is stored now.
	 */
	public static function reconcile( $store, $shop, array $invoice, array $effective ) {
		if ( empty( $effective['changed'] ) ) {
			return $invoice;
		}

		$id     = (int) $invoice['id'];
		$was    = (string) $invoice['state'];
		$now    = (string) $effective['state'];
		$holder = (int) $invoice['order_id'];

		if ( 'paid' === $now ) {
			self::mark_paid( $store, $invoice, (int) $effective['order_id'] );
		} elseif ( 'paid' === $was ) {
			self::release( $store, $shop, $invoice, $holder );
		} else {
			$store->save_invoice( $id, array( 'state' => $now ), null, null, 0 );
		}

		$fresh = $store->invoice( $id );
		return $fresh ? $fresh : $invoice;
	}
}
