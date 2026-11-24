<?php
/**
 * EA-only orders: the sticker is mailed by letter.
 *
 * WHY: medications are drop-shipped by the supplier, Everything Aquatic
 * (EA). When a cart holds only EA lines plus stickers, nothing ships from
 * FisHotel, so the owner mails the sticker as a plain stamped First-Class
 * letter. This class
 *
 *   - adds ONE cart fee of $1.00, "Sticker mailing (sent separately by
 *     letter)", when the cart is EA-only and holds at least one PAID
 *     sticker (the free sticker alone pays no stamp), taxed as the sticker
 *     product is, recalculated on every totals pass through
 *     `woocommerce_cart_calculate_fees`;
 *   - tells the customer, in the cart and on checkout under the sticker
 *     lines, and again in the processing / on-hold email;
 *   - flags the placed order: meta `_fishotel_sticker_mail_letter` = the
 *     number of stickers to mail, a private order note, a badge in the
 *     admin orders list (legacy and HPOS), a line in the admin new-order
 *     email, and "Stickers to mail by letter: #1234 (2)" on the Today page
 *     until the order is completed.
 *
 * "EA" is the theme's own answer, `fishotel_is_ea_fulfilled_product(
 * parent product id, variation id )` — the one source of truth; the
 * section keeps no flag of its own. Without the theme's function nothing
 * is EA, so nothing changes. Mixed carts (anything FisHotel ships), fish
 * carts and sticker-only carts are never touched (Rules::mail_letter()).
 *
 * The decisions are Rules::is_ea_only(), Rules::sticker_counts() and
 * Rules::mail_letter() — pure, tested headless.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Letter
 */
class Letter {

	/**
	 * Order meta: how many stickers to mail by letter (free + paid).
	 * Another plugin (letter tracking in ShipTracker) keys off it.
	 *
	 * @var string
	 */
	const META = '_fishotel_sticker_mail_letter';

	/**
	 * The fee's id in the cart, and the hidden meta its order line gets.
	 *
	 * @var string
	 */
	const FEE_ID        = 'fh-sticker-mailing';
	const FEE_ITEM_META = '_fh_sticker_mail_fee';

	/**
	 * The orders-list column.
	 *
	 * @var string
	 */
	const COLUMN = 'fh_sticker_letter';

	/**
	 * The emails the customer's message goes in, and the owner's.
	 *
	 * @var string[]
	 */
	const CUSTOMER_EMAILS = array( 'customer_processing_order', 'customer_on_hold_order' );
	const ADMIN_EMAIL     = 'new_order';

	/**
	 * Statuses that are not "still to mail": done, dead or not paid yet.
	 * Every other status (processing, on hold, the theme's own) is open
	 * until the order is completed.
	 *
	 * @var string[]
	 */
	const NOT_OPEN = array( 'completed', 'cancelled', 'refunded', 'failed', 'pending', 'checkout-draft', 'trash' );

	/**
	 * The Today line: filter, key, heading, how long a line may be, and how
	 * many orders are looked at.
	 *
	 * @var mixed
	 */
	const TODAY_FILTER = 'fishotel_today_lines';
	const TODAY_KEY    = 'stickers-letter';
	const TODAY_GROUP  = 'customers';
	const TODAY_LENGTH = 160;
	const TODAY_LIMIT  = 50;

	/**
	 * The Today line worked out once for this request (array( line|null )).
	 *
	 * @var array|null
	 */
	private static $today_cached = null;

	/**
	 * Hook everything (boot(), while the section is on).
	 */
	public static function register() {
		// The fee, worked out again on every totals pass (classic and block cart / checkout).
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'add_fee' ), 20 );

		// The customer's message under the sticker lines: theme cart page, checkout statement.
		add_action( 'woocommerce_after_cart_contents', array( self::class, 'cart_notice' ) );
		add_action( 'woocommerce_review_order_after_cart_contents', array( self::class, 'review_notice' ) );

		// The placed order.
		add_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'mark_fee_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'flag_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'flag_order' ), 20, 1 );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'email_line' ), 20, 4 );

		if ( is_admin() ) {
			add_filter( 'manage_edit-shop_order_columns', array( self::class, 'add_column' ), 30 );
			add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'add_column' ), 30 );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( self::class, 'render_column' ), 10, 2 );

			add_filter( self::TODAY_FILTER, array( self::class, 'today_filter' ), 21, 1 );
		}
	}

	/* ============================================================== */
	/*  Words                                                          */
	/* ============================================================== */

	/**
	 * The customer's message (cart, checkout, processing / on-hold email).
	 *
	 * @return string
	 */
	public static function message() {
		return __( "Heads up: your meds ship straight from our supplier, so your sticker gets mailed to you separately as a letter. You'll get an email with tracking once it's on its way. If you haven't gotten it in 2 weeks, just let us know and we'll send another one.", 'fishotel-misc-plugin' );
	}

	/**
	 * The fee's name on the cart, the order and the invoice.
	 *
	 * @return string
	 */
	public static function fee_name() {
		return __( 'Sticker mailing (sent separately by letter)', 'fishotel-misc-plugin' );
	}

	/**
	 * The private order note, and the admin new-order email's line.
	 *
	 * @param int $count Stickers to mail.
	 * @return string
	 */
	public static function note_text( $count ) {
		/* translators: %d: stickers to mail */
		return sprintf( __( 'MAIL STICKER BY LETTER: %d sticker(s) — EA-only order, nothing ships from FisHotel.', 'fishotel-misc-plugin' ), max( 0, (int) $count ) );
	}

	/* ============================================================== */
	/*  Which lines are EA                                             */
	/* ============================================================== */

	/**
	 * Whether the supplier drop-ships this line — the theme's own answer.
	 * A variation is asked as the theme asks it: the parent product's id
	 * (its `_fishotel_fulfillment` mode) and the variation's (its EA SKU).
	 *
	 * @param int $product_id   Parent product id (or a simple product's).
	 * @param int $variation_id Variation id, 0 for none.
	 * @return bool
	 */
	public static function is_ea_line( $product_id, $variation_id = 0 ) {
		if ( ! function_exists( 'fishotel_is_ea_fulfilled_product' ) || (int) $product_id < 1 ) {
			return false;
		}

		return (bool) fishotel_is_ea_fulfilled_product( (int) $product_id, (int) $variation_id );
	}

	/**
	 * A cart as letter lines (Rules::is_ea_only()).
	 *
	 * @param \WC_Cart|object $cart Cart.
	 * @return array[]
	 */
	public static function cart_lines( $cart ) {
		$lines = array();

		foreach ( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ? (array) $cart->get_cart() : array() as $item ) {
			$kind    = Cart::kind( $item );
			$lines[] = array(
				'kind'  => $kind,
				'qty'   => (int) ( $item['quantity'] ?? 0 ),
				'is_ea' => '' === $kind && self::is_ea_line( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) ),
			);
		}

		return $lines;
	}

	/**
	 * An order's product lines as letter lines.
	 *
	 * @param \WC_Order|object $order Order.
	 * @return array[]
	 */
	public static function order_lines( $order ) {
		$lines = array();

		foreach ( is_object( $order ) && method_exists( $order, 'get_items' ) ? (array) $order->get_items() : array() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$kind    = Cart::kind( array( Sticker_Giveaway::ITEM_KEY => $item->get_meta( '_fh_sticker' ) ) );
			$lines[] = array(
				'kind'  => $kind,
				'qty'   => (int) $item->get_quantity(),
				'is_ea' => '' === $kind && self::is_ea_line( (int) $item->get_product_id(), (int) $item->get_variation_id() ),
			);
		}

		return $lines;
	}

	/* ============================================================== */
	/*  Cart: the fee and the message                                  */
	/* ============================================================== */

	/**
	 * `woocommerce_cart_calculate_fees` — WooCommerce clears the fees and
	 * asks again on every totals pass, after `apply_pricing()` has removed
	 * or cut the sticker lines, so the fee always follows what the cart
	 * holds now: the last fish removed, it appears; a fish added, or the
	 * last paid sticker stepped back (make_room(), guard_lines()), it goes.
	 *
	 * Taxed as the sticker product is: its tax status ("taxable" → taxed)
	 * and its tax class (a variation's "same as parent" resolved by
	 * WooCommerce).
	 *
	 * @param \WC_Cart $cart Cart being totalled.
	 */
	public static function add_fee( $cart ) {
		if ( ! $cart instanceof \WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		$letter = Rules::mail_letter( self::cart_lines( $cart ) );
		if ( $letter['fee'] <= 0 ) {
			return;
		}

		$product = null;
		foreach ( $cart->get_cart() as $item ) {
			if ( Rules::KIND_EXTRA === Cart::kind( $item ) && isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$product = $item['data'];
				break;
			}
		}

		$cart->fees_api()->add_fee(
			array(
				'id'        => self::FEE_ID,
				'name'      => self::fee_name(),
				'amount'    => $letter['fee'],
				'taxable'   => $product && 'taxable' === $product->get_tax_status(),
				'tax_class' => $product ? (string) $product->get_tax_class() : '',
			)
		);
	}

	/**
	 * Whether the live cart is EA-only and holds a sticker.
	 *
	 * @return bool
	 */
	public static function cart_has_letter() {
		$cart = Cart::cart();

		return $cart ? Rules::mail_letter( self::cart_lines( $cart ) )['letter'] : false;
	}

	/**
	 * The message as markup, plain and small, in the theme's colours.
	 *
	 * @return string
	 */
	public static function notice_html() {
		return '<p class="fh-sticker-letter-note">' . esc_html( self::message() ) . '</p>';
	}

	/**
	 * `woocommerce_after_cart_contents` — the theme's cart page fires it
	 * right under the item list, inside the cart form, so WooCommerce's
	 * cart refresh (a line removed or changed) redraws it with the lines.
	 */
	public static function cart_notice() {
		if ( self::cart_has_letter() ) {
			echo '<div class="fh-sticker-letter">' . self::notice_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
		}
	}

	/**
	 * `woocommerce_review_order_after_cart_contents` — the checkout
	 * statement, under its last item row (the sticker lines are in it).
	 * Part of the fragment every checkout refresh redraws, so the message
	 * comes and goes with the sticker picks.
	 */
	public static function review_notice() {
		if ( self::cart_has_letter() ) {
			echo '<tr class="fh-sticker-letter"><td colspan="3">' . self::notice_html() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
		}
	}

	/* ============================================================== */
	/*  The placed order                                               */
	/* ============================================================== */

	/**
	 * `woocommerce_checkout_create_order_fee_item` — mark the order's fee
	 * line as this fee, so a later change (guard_order()) finds it by
	 * mark, not by name.
	 *
	 * @param \WC_Order_Item_Fee $item    Order fee line.
	 * @param string             $fee_key Fee key.
	 * @param object             $fee     The cart fee.
	 * @param \WC_Order          $order   Order.
	 */
	public static function mark_fee_item( $item, $fee_key = '', $fee = null, $order = null ) {
		unset( $fee_key, $order );

		if ( is_object( $fee ) && isset( $fee->id ) && self::FEE_ID === $fee->id && is_object( $item ) ) {
			$item->add_meta_data( self::FEE_ITEM_META, '1', true );
		}
	}

	/**
	 * `woocommerce_checkout_order_processed` (classic, an order id) and
	 * `woocommerce_store_api_checkout_order_processed` (block, the order) —
	 * an EA-only order with a sticker gets its meta and a private note. A
	 * checkout tried again on the same order works it out again: the meta
	 * follows what the order now holds, and a note is only written when the
	 * number changes.
	 *
	 * @param int|\WC_Order $order_or_id Order or its id.
	 */
	public static function flag_order( $order_or_id ) {
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_or_id ) : null );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$letter = Rules::mail_letter( self::order_lines( $order ) );
		$had    = (int) $order->get_meta( self::META );

		if ( ! $letter['letter'] ) {
			if ( $had > 0 ) {
				$order->delete_meta_data( self::META );
				$order->save();
			}
			return;
		}

		if ( $had === $letter['count'] ) {
			return;
		}

		$order->update_meta_data( self::META, $letter['count'] );
		$order->save();
		$order->add_order_note( self::note_text( $letter['count'] ) );
	}

	/**
	 * Called by Cart::guard_order() after it cut or took off sticker lines
	 * on the pay page of a failed order, before the order is totalled
	 * again: the fee goes when no paid sticker is left, and the number to
	 * mail follows what is left (gone when no sticker is).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function settle_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$letter = Rules::mail_letter( self::order_lines( $order ) );

		if ( $letter['fee'] <= 0 ) {
			foreach ( (array) $order->get_items( 'fee' ) as $id => $fee ) {
				if ( is_object( $fee ) && ( '' !== (string) $fee->get_meta( self::FEE_ITEM_META ) || self::fee_name() === $fee->get_name() ) ) {
					$order->remove_item( $id );
				}
			}
		}

		if ( $letter['count'] > 0 ) {
			$order->update_meta_data( self::META, $letter['count'] );
		} elseif ( '' !== (string) $order->get_meta( self::META ) ) {
			$order->delete_meta_data( self::META );
		}
	}

	/**
	 * How many stickers a placed order has to mail (0 = none).
	 *
	 * @param \WC_Order|object $order Order.
	 * @return int
	 */
	public static function flagged( $order ) {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) ? max( 0, (int) $order->get_meta( self::META ) ) : 0;
	}

	/**
	 * `woocommerce_email_before_order_table` — the customer's message in
	 * the processing and on-hold emails, and one line in the admin
	 * new-order email, for a flagged order only.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether it goes to the admin.
	 * @param bool      $plain_text    Plain-text email.
	 * @param object    $email         The email.
	 */
	public static function email_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		unset( $sent_to_admin );

		$count = self::flagged( $order );
		$id    = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';

		if ( $count < 1 ) {
			return;
		}

		if ( in_array( $id, self::CUSTOMER_EMAILS, true ) ) {
			$text = self::message();
		} elseif ( self::ADMIN_EMAIL === $id ) {
			$text = self::note_text( $count );
		} else {
			return;
		}

		if ( $plain_text ) {
			echo esc_html( $text ) . "\n\n";
			return;
		}

		echo '<p class="fh-sticker-letter-email" style="margin:0 0 16px;padding:10px 14px;border-left:3px solid #c9963a;">' . esc_html( $text ) . '</p>';
	}

	/* ============================================================== */
	/*  Admin orders list                                              */
	/* ============================================================== */

	/**
	 * The "Sticker letter" column, after the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$out   = array();
		$added = false;

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Sticker letter', 'fishotel-misc-plugin' );
				$added               = true;
			}
		}

		if ( ! $added ) {
			$out[ self::COLUMN ] = __( 'Sticker letter', 'fishotel-misc-plugin' );
		}

		return $out;
	}

	/**
	 * The column's cell: a badge on a flagged order that is still open, a
	 * quiet line once it is done.
	 *
	 * @param string            $column      Column.
	 * @param int|\WC_Order|mixed $order_or_id Post id (legacy table) or the order (HPOS).
	 */
	public static function render_column( $column, $order_or_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		echo self::badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
	}

	/**
	 * The badge's markup ('' for an order without a letter). It borrows
	 * WooCommerce's own status pill, so it looks like the list.
	 *
	 * @param \WC_Order|object $order Order.
	 * @return string
	 */
	public static function badge( $order ) {
		$count = self::flagged( $order );
		if ( $count < 1 ) {
			return '';
		}

		if ( method_exists( $order, 'has_status' ) && ! $order->has_status( self::NOT_OPEN ) ) {
			/* translators: %d: stickers */
			return '<mark class="order-status status-on-hold fh-sticker-letter-badge"><span>' . esc_html( sprintf( __( 'Mail sticker (%d)', 'fishotel-misc-plugin' ), $count ) ) . '</span></mark>';
		}

		/* translators: %d: stickers */
		return '<span class="description fh-sticker-letter-badge">' . esc_html( sprintf( __( 'By letter (%d)', 'fishotel-misc-plugin' ), $count ) ) . '</span>';
	}

	/* ============================================================== */
	/*  Today page                                                     */
	/* ============================================================== */

	/**
	 * The Today line (pure): null when no open order has a sticker to mail.
	 *
	 * @param array[] $rows Orders: [ 'number' => string, 'count' => int ].
	 * @param string  $url  Where the line links to.
	 * @return array|null
	 */
	public static function today_line( array $rows, $url ) {
		$named = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (int) ( $row['count'] ?? 0 ) > 0 ) {
				$named[] = sprintf( '#%s (%d)', (string) ( $row['number'] ?? '' ), (int) $row['count'] );
			}
		}

		$total = count( $named );
		if ( $total < 1 ) {
			return null;
		}

		$prefix = __( 'Stickers to mail by letter:', 'fishotel-misc-plugin' ) . ' ';

		// Name as many as fit the line; say how many are left out.
		do {
			$more = $total - count( $named );
			$text = $prefix . implode( ', ', $named );
			if ( $more > 0 ) {
				/* translators: %d: orders */
				$text .= ' ' . sprintf( __( 'and %d more', 'fishotel-misc-plugin' ), $more );
			}
			if ( strlen( $text ) <= self::TODAY_LENGTH || count( $named ) <= 1 ) {
				break;
			}
			array_pop( $named );
		} while ( true );

		return array(
			'key'    => self::TODAY_KEY,
			'group'  => self::TODAY_GROUP,
			'text'   => $text,
			'detail' => '',
			'url'    => (string) $url,
			'count'  => $total,
			'tone'   => 'todo',
		);
	}

	/**
	 * The open orders with a sticker to mail, oldest first.
	 *
	 * @return array[] [ 'number' => string, 'count' => int, 'url' => string ].
	 */
	public static function open_letters() {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$statuses = array();
		foreach ( array_keys( (array) wc_get_order_statuses() ) as $status ) {
			$status = 0 === strpos( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status;
			if ( ! in_array( $status, self::NOT_OPEN, true ) ) {
				$statuses[] = $status;
			}
		}
		if ( ! $statuses ) {
			return array();
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => $statuses,
			'limit'   => self::TODAY_LIMIT,
			'orderby' => 'date',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		// The HPOS store reads a meta query; the posts store ignores one and passes meta_key on to WP_Query.
		if ( self::hpos_enabled() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, at most TODAY_LIMIT orders, wp-admin only.
				array(
					'key'     => self::META,
					'compare' => 'EXISTS',
				),
			);
		} else {
			$args['meta_key']     = self::META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- as above.
			$args['meta_compare'] = 'EXISTS';
		}

		try {
			$found = wc_get_orders( $args );
		} catch ( \Throwable $e ) {
			return array();
		}

		$rows = array();
		foreach ( (array) $found as $order ) {
			$count = self::flagged( $order );
			if ( $count < 1 || ! method_exists( $order, 'get_order_number' ) ) {
				continue;
			}
			$rows[] = array(
				'number' => (string) $order->get_order_number(),
				'count'  => $count,
				'url'    => method_exists( $order, 'get_edit_order_url' ) ? (string) $order->get_edit_order_url() : '',
			);
		}

		return $rows;
	}

	/**
	 * True when WooCommerce's HPOS orders table is the active order store.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		return class_exists( $util )
			&& is_callable( array( $util, 'custom_orders_table_usage_is_enabled' ) )
			&& (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * Filter callback: the Today lines gain "Stickers to mail by letter",
	 * for a user who may run the shop's orders. One order links to that
	 * order, more to the orders list. Whatever goes wrong adds nothing.
	 *
	 * @param mixed $lines The lines so far.
	 * @return array[]
	 */
	public static function today_filter( $lines = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			return $lines;
		}

		if ( null === self::$today_cached ) {
			try {
				$rows = self::open_letters();
				$url  = 1 === count( $rows ) ? $rows[0]['url'] : admin_url( self::hpos_enabled() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' );

				self::$today_cached = array( self::today_line( $rows, $url ) );
			} catch ( \Throwable $e ) {
				self::$today_cached = array( null );
			}
		}

		$out = array();
		foreach ( $lines as $existing ) {
			if ( ! ( is_array( $existing ) && self::TODAY_KEY === (string) ( $existing['key'] ?? '' ) ) ) {
				$out[] = $existing;
			}
		}
		if ( is_array( self::$today_cached[0] ) ) {
			$out[] = self::$today_cached[0];
		}

		return $out;
	}

	/**
	 * Forget the cached Today line (tests).
	 */
	public static function forget_today() {
		self::$today_cached = null;
	}
}
