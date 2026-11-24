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
 * An EA-only cart that joins a customer's open order FisHotel ships (the
 * theme's combined shipping: "We'll ship both together") is not a letter
 * either: its sticker goes in that box. The theme's own signals decide —
 * the shipping package tagged `fishotel_reuse` = 'match' and the open
 * order it names (FisHotel_Reorder_Checkout_Lock::anchor_order()) in the
 * cart; `_fishotel_piggyback_of` and `_fishotel_fulfilled_by_order` on the
 * placed order (the second is written when the order is paid, so the
 * order is looked at again then). Refunds follow too: the number to mail
 * goes down with the refunded stickers, and a note reminds the owner to
 * refund the mailing fee once no paid sticker is left.
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
	 * Order meta: the owner was already told to refund the mailing fee of
	 * an order that rides along in a box FisHotel ships.
	 *
	 * @var string
	 */
	const FEE_NOTED = '_fh_sticker_mail_fee_noted';

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

		// After the theme folds a paid order into a box (priority 99), and after a refund.
		add_action( 'woocommerce_payment_complete', array( self::class, 'after_payment' ), 100, 1 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_refund' ), 20, 2 );

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

	/**
	 * The private note when an order no longer has a sticker to mail.
	 *
	 * @return string
	 */
	public static function gone_text() {
		return __( 'Sticker Giveaway: No sticker is left to mail by letter on this order.', 'fishotel-misc-plugin' );
	}

	/**
	 * The private note when the mailing fee was paid but nothing paid is
	 * left to mail: why, then "so refund the … fee to the customer."
	 *
	 * @param string $why The reason, without a full stop.
	 * @return string
	 */
	public static function refund_fee_text( $why ) {
		/* translators: 1: the reason, 2: the fee's name */
		return sprintf( __( '%1$s, so refund the "%2$s" fee to the customer.', 'fishotel-misc-plugin' ), $why, self::fee_name() );
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
				'price' => isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_price' ) ? (float) $item['data']->get_price() : null,
			);
		}

		return $lines;
	}

	/**
	 * An order's product lines as letter lines: the quantity left after
	 * refunds, and what one sticker cost before any coupon.
	 *
	 * @param \WC_Order|object $order Order.
	 * @return array[]
	 */
	public static function order_lines( $order ) {
		$lines    = array();
		$refunded = is_object( $order ) && method_exists( $order, 'get_qty_refunded_for_item' );

		foreach ( is_object( $order ) && method_exists( $order, 'get_items' ) ? (array) $order->get_items() : array() as $id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$kind    = Cart::kind( array( Sticker_Giveaway::ITEM_KEY => $item->get_meta( '_fh_sticker' ) ) );
			$bought  = (int) $item->get_quantity();
			$lines[] = array(
				'kind'  => $kind,
				'qty'   => max( 0, $bought + ( $refunded ? (int) $order->get_qty_refunded_for_item( $id ) : 0 ) ),
				'is_ea' => '' === $kind && self::is_ea_line( (int) $item->get_product_id(), (int) $item->get_variation_id() ),
				'price' => method_exists( $item, 'get_subtotal' ) && $bought > 0 ? (float) $item->get_subtotal() / $bought : null,
			);
		}

		return $lines;
	}

	/* ============================================================== */
	/*  Joining a box FisHotel ships (the theme's combined shipping)   */
	/* ============================================================== */

	/**
	 * The orders that share a fulfillment box with this one (the theme's
	 * `fishotel_order_get_fulfillment()` / `fishotel_fulfillment_get_sources()`),
	 * itself included; none when it is in no box or the theme is not there.
	 *
	 * @param \WC_Order|int $order_or_id Order or its id.
	 * @return int[]
	 */
	public static function box_of( $order_or_id ) {
		if ( ! function_exists( 'fishotel_order_get_fulfillment' ) || ! function_exists( 'fishotel_fulfillment_get_sources' ) ) {
			return array();
		}

		$box = (int) fishotel_order_get_fulfillment( $order_or_id );

		return $box > 0 ? array_map( 'intval', (array) fishotel_fulfillment_get_sources( $box ) ) : array();
	}

	/**
	 * Whether any of these orders (but $self) has something FisHotel ships.
	 *
	 * @param int[] $ids  Order ids.
	 * @param int   $self The order asking, left out.
	 * @return bool
	 */
	public static function any_ships( array $ids, $self = 0 ) {
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id < 1 || $id === (int) $self ) {
				continue;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( $order instanceof \WC_Order && Rules::ships_from_fishotel( self::order_lines( $order ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the live cart joins an open order whose box FisHotel ships.
	 * The theme tags the shipping package `fishotel_reuse` = 'match' when it
	 * combines the shipping, and names the open order through the re-order
	 * lock's anchor. Combined but no order named: the customer is told
	 * both ship together, so it rides along.
	 *
	 * @param \WC_Cart|object $cart Cart.
	 * @return bool
	 */
	public static function cart_rides_along( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_shipping_packages' ) ) {
			return false;
		}

		$joined = false;
		foreach ( (array) $cart->get_shipping_packages() as $package ) {
			if ( is_array( $package ) && 'match' === (string) ( $package['fishotel_reuse'] ?? '' ) ) {
				$joined = true;
				break;
			}
		}
		if ( ! $joined ) {
			return false;
		}

		$anchor = class_exists( 'FisHotel_Reorder_Checkout_Lock' ) && is_callable( array( 'FisHotel_Reorder_Checkout_Lock', 'anchor_order' ) )
			? \FisHotel_Reorder_Checkout_Lock::anchor_order()
			: null;

		if ( ! $anchor instanceof \WC_Order ) {
			return true;
		}

		return self::any_ships( array_merge( array( (int) $anchor->get_id() ), self::box_of( $anchor ) ) );
	}

	/**
	 * Whether a placed order rides along in a box FisHotel ships: the order
	 * it piggybacks on (`_fishotel_piggyback_of`) or the fulfillment it was
	 * folded into holds something FisHotel ships.
	 *
	 * @param \WC_Order|object $order Order.
	 * @return bool
	 */
	public static function order_rides_along( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		$ids    = array();
		$source = (int) $order->get_meta( '_fishotel_piggyback_of' );
		if ( $source > 0 ) {
			$ids   = self::box_of( $source );
			$ids[] = $source;
		}
		$ids = array_merge( $ids, self::box_of( $order ) );

		return $ids ? self::any_ships( $ids, method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 ) : false;
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
		if ( $letter['fee'] <= 0 || self::cart_rides_along( $cart ) ) {
			return;
		}

		$product = null;
		foreach ( $cart->get_cart() as $item ) {
			if ( Rules::KIND_EXTRA === Cart::kind( $item ) && isset( $item['data'] ) && $item['data'] instanceof \WC_Product && (float) $item['data']->get_price() > 0 ) {
				$product = $item['data'];
				break;
			}
		}

		$taxable   = $product && 'taxable' === $product->get_tax_status();
		$tax_class = $product ? (string) $product->get_tax_class() : '';

		$cart->fees_api()->add_fee(
			array(
				'id'        => self::FEE_ID,
				'name'      => self::fee_name(),
				'amount'    => self::fee_amount( $letter['fee'], $taxable, $tax_class, $cart ),
				'taxable'   => $taxable,
				'tax_class' => $tax_class,
			)
		);
	}

	/**
	 * The amount handed to WooCommerce. WooCommerce always adds a fee's tax
	 * on top, while a shop that enters prices with tax has the tax inside
	 * the $0.99 sticker. So, then, the tax the customer's rates put inside
	 * $1.00 is taken out first, and the customer pays $1.00 with its tax,
	 * as they pay $0.99 for a sticker. Prices entered without tax, or a fee
	 * that is not taxed: the $1.00 as it is.
	 *
	 * @param float           $gross     The fee as the customer should see it.
	 * @param bool            $taxable   Whether the fee is taxed.
	 * @param string          $tax_class Its tax class.
	 * @param \WC_Cart|object $cart      Cart (its customer's tax rates).
	 * @return float
	 */
	public static function fee_amount( $gross, $taxable, $tax_class, $cart ) {
		$gross = (float) $gross;

		if ( ! $taxable || ! function_exists( 'wc_prices_include_tax' ) || ! wc_prices_include_tax()
			|| ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) || ! class_exists( '\WC_Tax' ) ) {
			return $gross;
		}

		$customer = is_object( $cart ) && method_exists( $cart, 'get_customer' ) ? $cart->get_customer() : null;
		$taxes    = \WC_Tax::calc_tax( $gross, \WC_Tax::get_rates( (string) $tax_class, $customer ), true );

		return max( 0.0, $gross - (float) array_sum( (array) $taxes ) );
	}

	/**
	 * Whether the live cart is EA-only and holds a sticker.
	 *
	 * @return bool
	 */
	public static function cart_has_letter() {
		$cart = Cart::cart();

		return $cart && Rules::mail_letter( self::cart_lines( $cart ) )['letter'] && ! self::cart_rides_along( $cart );
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

		$rides  = self::order_rides_along( $order );
		$letter = Rules::mail_letter( self::order_lines( $order ), $rides );
		$saved  = self::recount( $order, $letter['count'] );

		// Joined a box FisHotel ships, yet the cart charged the mailing fee (the theme's two signals disagreed): say to refund it, once.
		if ( $rides && self::fee_left( $order ) > 0 && '' === (string) $order->get_meta( self::FEE_NOTED ) ) {
			$order->update_meta_data( self::FEE_NOTED, '1' );
			$order->add_order_note( self::refund_fee_text( __( 'Sticker Giveaway: this order ships in the same box as an order FisHotel ships, so its sticker goes in that box and nothing is mailed', 'fishotel-misc-plugin' ) ) );
			$saved = true;
		}

		if ( $saved ) {
			$order->save();
		}
	}

	/**
	 * Set the number to mail and say so in a private note, when it changed:
	 * the "MAIL STICKER BY LETTER" note with the new number, or $gone (by
	 * default gone_text()) when nothing is left to mail. The caller saves.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $count Stickers to mail now.
	 * @param string    $gone  The note when none is left.
	 * @return bool Whether it changed.
	 */
	private static function recount( $order, $count, $gone = '' ) {
		$count = max( 0, (int) $count );
		if ( self::flagged( $order ) === $count ) {
			return false;
		}

		if ( $count > 0 ) {
			$order->update_meta_data( self::META, $count );
			$order->add_order_note( self::note_text( $count ) );
		} else {
			$order->delete_meta_data( self::META );
			$order->add_order_note( '' !== $gone ? $gone : self::gone_text() );
		}

		return true;
	}

	/**
	 * What is left of the mailing fee the customer paid (fee lines less
	 * what was refunded of them).
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	public static function fee_left( $order ) {
		$left = 0.0;

		foreach ( (array) $order->get_items( 'fee' ) as $id => $fee ) {
			if ( ! self::is_fee_item( $fee ) ) {
				continue;
			}
			$left += (float) $fee->get_total() - ( method_exists( $order, 'get_total_refunded_for_item' ) ? (float) $order->get_total_refunded_for_item( $id, 'fee' ) : 0.0 );
		}

		return $left > 0.005 ? $left : 0.0;
	}

	/**
	 * Whether an order fee line is the mailing fee (by its mark, else its name).
	 *
	 * @param object $fee Order fee line.
	 * @return bool
	 */
	private static function is_fee_item( $fee ) {
		return is_object( $fee ) && ( '' !== (string) $fee->get_meta( self::FEE_ITEM_META ) || self::fee_name() === $fee->get_name() );
	}

	/**
	 * `woocommerce_payment_complete`, after the theme folded the paid order
	 * into a fulfillment box (priority 99): a flagged order that now shares
	 * a box with an order FisHotel ships is no letter after all — its
	 * sticker goes in that box. The flag goes, and a note says so (and to
	 * refund the mailing fee, when one was paid).
	 *
	 * @param int $order_id Order id.
	 */
	public static function after_payment( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || self::flagged( $order ) < 1 || ! self::order_rides_along( $order ) ) {
			return;
		}

		$box = function_exists( 'fishotel_order_get_fulfillment' ) ? (int) fishotel_order_get_fulfillment( $order ) : 0;
		$note = $box > 0
			/* translators: %d: fulfillment id */
			? sprintf( __( 'Sticker Giveaway: no letter after all — this order was combined into fulfillment #FF-%d with an order FisHotel ships, so its sticker goes in that box.', 'fishotel-misc-plugin' ), $box )
			: __( 'Sticker Giveaway: no letter after all — this order ships in the same box as an order FisHotel ships, so its sticker goes in that box.', 'fishotel-misc-plugin' );
		if ( self::fee_left( $order ) > 0 ) {
			$note .= ' ' . self::refund_fee_text( __( 'Nothing is mailed', 'fishotel-misc-plugin' ) );
		}

		self::recount( $order, 0, $note );
		$order->save();
	}

	/**
	 * `woocommerce_order_refunded` — a flagged order: the number to mail
	 * follows the stickers left after the refund, with a note; once no paid
	 * sticker is left while the mailing fee is still paid, a second note
	 * says to refund the fee too (WooCommerce never refunds a fee by
	 * itself, and the section never moves money).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public static function on_refund( $order_id, $refund_id = 0 ) {
		unset( $refund_id );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || self::flagged( $order ) < 1 ) {
			return;
		}

		$letter = Rules::mail_letter( self::order_lines( $order ) );
		if ( ! self::recount( $order, $letter['count'] ) ) {
			return;
		}

		if ( $letter['paid'] < 1 && self::fee_left( $order ) > 0 ) {
			$order->add_order_note( self::refund_fee_text( __( 'Sticker Giveaway: no paid sticker is left to mail', 'fishotel-misc-plugin' ) ) );
		}
		$order->save();
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

		$letter = Rules::mail_letter( self::order_lines( $order ), self::order_rides_along( $order ) );

		if ( $letter['fee'] <= 0 ) {
			foreach ( (array) $order->get_items( 'fee' ) as $id => $fee ) {
				if ( self::is_fee_item( $fee ) ) {
					$order->remove_item( $id );
				}
			}
		}

		// The number to mail follows what is left, with a fresh note when it changed.
		self::recount( $order, $letter['count'] );
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
