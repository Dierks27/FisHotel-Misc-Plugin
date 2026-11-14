<?php
/**
 * Gift Cards at checkout (Live only).
 *
 *   The box     "Use my gift card balance ($X available)" — a checkbox with
 *               the id the theme already styles and watches
 *               (#use_gift_card_balance: its CSS, its update_checkout wiring
 *               and its PayPal smart-button guard keep working) — and a
 *               "How much to use" amount: all of it unless changed, never
 *               more than the order. Rendered on
 *               woocommerce_review_order_before_order_total, which the theme
 *               fires once, outside the part the checkout refreshes.
 *   The code    "Have a gift card?" under Payment Method: a logged-in
 *               customer's code goes into their account; a guest's is used
 *               for this checkout only, when Settings allow guests.
 *   The amount  one negative fee "Gift card" with no tax of its own (tax
 *               stays worked out on the goods; WooCommerce, e-mails, PDFs and PayPal all
 *               see a fee, unlike the old plugin's hidden total adjustment).
 *               A hidden .fh-gc-applied marker goes in the totals so the
 *               theme hides PayPal's smart buttons, as it does today.
 *   Paying      when the order is created, the fee's amount is taken from
 *               the cards (Service::debit_order) — if the balance is gone
 *               (another tab), the checkout stops with a message and
 *               nothing is taken. Checked again when the order is paid.
 *   Putting back  failed, cancelled (a paid one too, unless Settings say
 *               no), refunded with every item, trashed or deleted unpaid:
 *               everything still out goes back, exactly once. Any other
 *               refund is put back with the order screen's Gift cards box
 *               (Admin). These hooks stay while Live has fallen back.
 *               Paid again after failed / cancelled: taken once more. Set
 *               to a paid status after a REFUND put it back (by itself or
 *               by hand): never taken again by itself — a note says so,
 *               and the Gift cards box takes it by hand if that was meant.
 *   Old orders  an imported order's deduction is shown in its totals
 *               (e-mails, My Account, the admin screen), and kept when
 *               Recalculate is pressed (Settings).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checkout
 */
class Checkout {

	const SESSION_CHOICE = 'fh_gcard_choice';
	const SESSION_CODES  = 'fh_gcard_codes';
	const FEE_ID         = 'fh-gift-card';
	const FEE_META       = '_fh_gift_card_fee';
	const CODES_META     = '_fh_gift_card_codes';
	const AJAX_APPLY     = 'fh_gcard_apply_code';
	const NONCE_APPLY    = 'fh_gcard_apply';

	/**
	 * Guards a status change this class makes from running it again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Orders noted "not taken again" in this request (order id => status),
	 * so the paid and the processing hooks of one payment note it once.
	 *
	 * @var array<int, string>
	 */
	private static $noted = array();

	/**
	 * Register hooks.
	 */
	public function init() {
		$this->init_put_back();
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_control' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_code_field' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_marker' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'read_review_post' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'read_checkout_post' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ), 20 );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( $this, 'no_fee_tax' ), 10, 2 );
		add_action( 'woocommerce_order_item_fee_after_calculate_taxes', array( $this, 'no_item_fee_tax' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'remember_codes' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'debit' ) );
		add_action( 'woocommerce_checkout_order_exception', array( $this, 'order_exception' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'ensure_debited' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'ensure_debited' ) );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'ensure_debited' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'ensure_debited' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'order_totals' ), 10, 2 );
		add_action( 'woocommerce_admin_order_totals_after_discount', array( $this, 'admin_totals_row' ) );
		add_action( 'woocommerce_order_after_calculate_totals', array( $this, 'keep_old_deduction' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_meta' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'forget' ) );
		add_action( 'wp_ajax_' . self::AJAX_APPLY, array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_APPLY, array( $this, 'ajax_apply' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The putting-back hooks only. Live registers them with everything
	 * else; they also stay while Live has fallen back to Shadow (the old
	 * plugin is on again), so an order paid with these cards and then
	 * cancelled or refunded in the meantime still gets its money back.
	 */
	public function init_put_back() {
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_failed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_refunded' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_partial_refund' ), 10, 2 );
		add_action( 'woocommerce_trash_order', array( $this, 'on_trash' ) );
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_trash' ) );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ) );
		add_action( 'before_delete_post', array( $this, 'on_trash_post' ) );
	}

	/* ============================================================== */
	/*  The session                                                    */
	/* ============================================================== */

	/**
	 * WooCommerce's session, or null.
	 *
	 * @return object|null
	 */
	private static function session() {
		return function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;
	}

	/**
	 * The customer's choice: use (bool) and amount (cents; null = all).
	 *
	 * @return array{use: bool, amount: int|null}
	 */
	public static function choice() {
		$s      = self::session();
		$choice = $s ? $s->get( self::SESSION_CHOICE ) : null;
		return array(
			'use'    => is_array( $choice ) && ! empty( $choice['use'] ),
			'amount' => is_array( $choice ) && isset( $choice['amount'] ) && null !== $choice['amount'] ? max( 0, (int) $choice['amount'] ) : null,
		);
	}

	/**
	 * The choice a posted checkout form makes (pure).
	 *
	 * @param array $post The form's fields.
	 * @return array{use: bool, amount: int|null}
	 */
	public static function choice_from_post( array $post ) {
		$typed = isset( $post['fh_gcard_amount'] ) && is_scalar( $post['fh_gcard_amount'] ) ? trim( (string) $post['fh_gcard_amount'] ) : '';
		return array(
			'use'    => ! empty( $post['fh_gcard_use'] ),
			'amount' => '' === $typed ? null : Money::to_cents( $typed ),
		);
	}

	/**
	 * Keep a choice.
	 *
	 * @param array $choice use, amount.
	 */
	public static function set_choice( array $choice ) {
		$s = self::session();
		if ( $s ) {
			$s->set( self::SESSION_CHOICE, $choice );
		}
	}

	/**
	 * The cards typed at this checkout (a guest's).
	 *
	 * @return int[]
	 */
	public static function session_codes() {
		$s     = self::session();
		$codes = $s ? $s->get( self::SESSION_CODES ) : null;
		return is_array( $codes ) ? array_values( array_filter( array_map( 'intval', $codes ) ) ) : array();
	}

	/**
	 * The update_order_review request: the form's fields, serialized.
	 *
	 * @param string $post_data Serialized form.
	 */
	public function read_review_post( $post_data ) {
		$post = array();
		parse_str( is_string( $post_data ) ? $post_data : '', $post );
		self::set_choice( self::choice_from_post( $post ) );
	}

	/**
	 * Place order: the form's fields (WooCommerce checked its nonce).
	 */
	public function read_checkout_post() {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce-process_checkout before this action.
		self::set_choice( self::choice_from_post( is_array( $post ) ? $post : array() ) );
	}

	/**
	 * After an order: forget the choice and the typed codes.
	 */
	public function forget() {
		$s = self::session();
		if ( $s ) {
			$s->set( self::SESSION_CHOICE, null );
			$s->set( self::SESSION_CODES, null );
		}
	}

	/* ============================================================== */
	/*  The amount                                                     */
	/* ============================================================== */

	/**
	 * What the order can take, in cents: the goods, shipping and other fees
	 * before tax — what WooCommerce lets a negative fee cover. Gift cards in
	 * the cart are left out when Settings say the balance may not pay for them.
	 *
	 * @param object $cart     WC_Cart.
	 * @param array  $settings Settings.
	 * @return int
	 */
	public static function cap( $cart, array $settings ) {
		$cap = Money::from_wc( $cart->get_cart_contents_total() ) + Money::from_wc( $cart->get_shipping_total() );
		foreach ( $cart->get_fees() as $fee ) {
			if ( self::FEE_ID !== ( $fee->id ?? '' ) ) {
				$cap += Money::from_wc( $fee->amount );
			}
		}
		if ( ! Settings::on( $settings, 'wallet_pays_gift_cards' ) ) {
			foreach ( $cart->get_cart() as $item ) {
				if ( isset( $item['data'] ) && Product::is_gift( $item['data'] ) ) {
					$cap -= Money::from_wc( $item['line_total'] ?? 0 );
				}
			}
		}
		return max( 0, $cap );
	}

	/**
	 * The amount the balance pays on this cart (cents).
	 *
	 * @param object $cart WC_Cart.
	 * @return array{applied: int, wallet: int}
	 */
	public static function applied( $cart ) {
		$service = Gift_Cards::service();
		$choice  = self::choice();
		$cards   = $service->sources( (int) get_current_user_id(), self::session_codes() );
		$wallet  = array_sum( array_map( 'intval', array_column( $cards, 'balance_cents' ) ) );
		if ( ! $choice['use'] || $wallet <= 0 ) {
			return array(
				'applied' => 0,
				'wallet'  => $wallet,
			);
		}
		return array(
			'applied' => Money::choose( $wallet, self::cap( $cart, $service->settings ), $choice['amount'] ),
			'wallet'  => $wallet,
		);
	}

	/**
	 * Add the "Gift card" fee.
	 *
	 * @param object $cart WC_Cart.
	 */
	public function add_fee( $cart ) {
		if ( ! is_object( $cart ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		if ( ! self::choice()['use'] ) {
			return;
		}
		try {
			$applied = self::applied( $cart )['applied'];
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $applied > 0 ) {
			$cart->fees_api()->add_fee(
				array(
					'id'        => self::FEE_ID,
					'name'      => __( 'Gift card', 'fishotel-misc-plugin' ),
					'amount'    => -$applied / 100,
					'taxable'   => false,
					'tax_class' => '',
				)
			);
		}
	}

	/**
	 * The "Gift card" fee carries no tax. It is a payment, not a discount:
	 * WooCommerce splits a negative fee's tax over the goods even when the
	 * fee is not taxable, which would take the card's "own tax" off the
	 * order and leave the shop short by that much.
	 *
	 * @param array  $taxes The fee's taxes.
	 * @param object $fee   WC_Cart_Totals' fee (->object is the cart fee).
	 * @return array
	 */
	public function no_fee_tax( $taxes, $fee ) {
		return is_object( $fee ) && isset( $fee->object ) && is_object( $fee->object ) && self::FEE_ID === ( $fee->object->id ?? '' ) ? array() : $taxes;
	}

	/**
	 * Recalculate on the order screen: the same for the order's fee line.
	 *
	 * @param object $item WC_Order_Item_Fee.
	 */
	public function no_item_fee_tax( $item ) {
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) && 'yes' === $item->get_meta( self::FEE_META ) && method_exists( $item, 'set_taxes' ) ) {
			$item->set_taxes( false );
		}
	}

	/**
	 * Place order: a guest's typed codes need Settings to allow it.
	 *
	 * @param array  $data   Posted data.
	 * @param object $errors WP_Error.
	 */
	public function validate( $data, $errors ) {
		if ( ! is_user_logged_in() && self::session_codes() && ! Settings::on( Gift_Cards::service()->settings, 'guest_codes' ) && is_object( $errors ) ) {
			$errors->add( 'fh_gift_card', __( 'Please log in to use a gift card.', 'fishotel-misc-plugin' ) );
		}
	}

	/**
	 * Mark the order's gift card fee as ours.
	 *
	 * @param object $item    WC_Order_Item_Fee.
	 * @param string $fee_key Fee key.
	 * @param object $fee     The cart fee.
	 * @param object $order   WC_Order.
	 */
	public function tag_fee( $item, $fee_key, $fee, $order ) {
		if ( is_object( $fee ) && self::FEE_ID === ( $fee->id ?? '' ) && is_object( $item ) ) {
			$item->add_meta_data( self::FEE_META, 'yes', true );
		}
	}

	/**
	 * Keep the cards typed at this checkout on the order.
	 *
	 * @param object $order WC_Order.
	 * @param array  $data  Posted data.
	 */
	public function remember_codes( $order, $data = array() ) {
		$codes = self::session_codes();
		if ( $codes && is_object( $order ) ) {
			$order->update_meta_data( self::CODES_META, $codes );
		}
	}

	/**
	 * The cents an order's "Gift card" fee takes off (pure apart from the order).
	 *
	 * @param object $order WC_Order.
	 * @return int
	 */
	public static function fee_cents( $order ) {
		$cents = 0;
		foreach ( $order->get_fees() as $fee ) {
			if ( 'yes' === $fee->get_meta( self::FEE_META ) ) {
				$cents += -Money::from_wc( $fee->get_total() );
			}
		}
		return max( 0, $cents );
	}

	/**
	 * The order was created: take the fee's amount from the cards. A
	 * refusal stops the checkout with its message (WooCommerce shows it).
	 *
	 * @param object $order WC_Order.
	 * @throws \Exception When the cards no longer hold the amount.
	 */
	public function debit( $order ) {
		if ( ! is_object( $order ) ) {
			return;
		}
		$service = Gift_Cards::service();
		$cents   = self::fee_cents( $order );
		if ( 0 === $cents && 0 === $service->order_summary( (int) $order->get_id() )['outstanding'] ) {
			return;
		}
		$codes  = $order->get_meta( self::CODES_META );
		$result = $service->debit_order( (int) $order->get_id(), (int) $order->get_customer_id(), is_array( $codes ) ? $codes : array(), $cents, Audit::context( 'checkout', 'woocommerce_checkout_order_created' ) );
		if ( ! $result['ok'] ) {
			self::set_choice( array( 'use' => self::choice()['use'], 'amount' => null ) );
			throw new \Exception( $result['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WooCommerce escapes checkout notices.
		}
	}

	/**
	 * The checkout stopped after the order was created: say so on it.
	 *
	 * @param object $order WC_Order.
	 */
	public function order_exception( $order ) {
		if ( is_object( $order ) && $order->get_id() && self::fee_cents( $order ) > 0 && 0 === Gift_Cards::service()->order_summary( (int) $order->get_id() )['outstanding'] ) {
			$order->add_order_note( __( 'Checkout stopped: the gift card balance changed while the customer was checking out, so nothing was taken from a gift card. The customer was asked to place the order again.', 'fishotel-misc-plugin' ) );
		}
	}

	/**
	 * Paid / processing / on hold / completed: make sure the card part was
	 * taken — only when nothing is out on the order: never taken yet (the
	 * block checkout and pay-for-order take it here), or all put back by
	 * itself when the order failed or was cancelled and then paid after
	 * all. An amount already taken is never changed here. Money that went
	 * back because of a refund — by itself (refunded in full) or by hand
	 * (the order's Gift cards box) — is never taken again by a status
	 * change: a note says so and how to take it by hand if that was meant
	 * (a refunded order set back to Completed would otherwise charge the
	 * customer twice). If the card no longer holds it, the order is put on
	 * hold with a note.
	 *
	 * @param int $order_id Order id.
	 */
	public function ensure_debited( $order_id ) {
		if ( self::$busy ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		// The theme's combined-box Fulfillment order is not a sale: its source orders carry the money.
		if ( ! is_object( $order ) || 'fulfillment' === $order->get_status() || $order->get_meta( '_fishotel_fulfillment_sources' ) ) {
			return;
		}
		$service = Gift_Cards::service();
		$cents   = self::fee_cents( $order );
		$rows    = $cents > 0 ? $service->store->ledger_of_order( (int) $order->get_id() ) : array();
		if ( $cents <= 0 || ! self::should_take( $rows ) ) {
			$status = (string) $order->get_status();
			if ( $cents > 0 && self::refund_put_back( $rows ) && ( self::$noted[ (int) $order->get_id() ] ?? '' ) !== $status ) {
				self::$noted[ (int) $order->get_id() ] = $status;
				/* translators: 1: amount, 2: order status */
				$order->add_order_note( sprintf( __( 'The gift card part of this order (%1$s) was NOT taken from the customer\'s gift card again when it was set to %2$s: it went back on the card because of a refund. If the customer should pay it with the card after all, use the Gift cards box on this order (Take it from the card again).', 'fishotel-misc-plugin' ), Money::words( $cents ), $status ) );
			}
			return;
		}
		$codes  = $order->get_meta( self::CODES_META );
		$result = $service->debit_order( (int) $order->get_id(), (int) $order->get_customer_id(), is_array( $codes ) ? $codes : array(), $cents, Audit::context( 'order', 'order status ' . $order->get_status() ) );
		if ( $result['ok'] ) {
			return;
		}
		self::$busy = true;
		/* translators: %s: amount */
		$note = sprintf( __( 'The gift card part of this order (%s) could NOT be taken from the customer\'s gift cards — they no longer hold it. Check before shipping.', 'fishotel-misc-plugin' ), Money::words( $cents ) );
		if ( 'on-hold' !== $order->get_status() ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
		self::$busy = false;
	}

	/**
	 * Whether a paid order's card part is still to be taken (pure): nothing
	 * is out, no refund ever went back on it, and either nothing was ever
	 * taken or the newest movement is an automatic put-back (failed /
	 * cancelled).
	 *
	 * @param array[] $rows The order's ledger rows, oldest first.
	 * @return bool
	 */
	public static function should_take( array $rows ) {
		if ( Money::outstanding( Money::order_cards( $rows ) ) > 0 ) {
			return false;
		}
		$last = null;
		foreach ( $rows as $row ) {
			if ( self::by_refund( $row ) ) {
				return false;
			}
			if ( in_array( (string) $row['type'], array( 'used', 'restored' ), true ) ) {
				$last = $row;
			}
		}
		return null === $last || ( 'restored' === (string) $last['type'] && 0 === strpos( (string) $last['idempotency_key'], 'restore:' ) );
	}

	/**
	 * Whether nothing is out on an order because a refund went back on the
	 * card — the case a status change must not charge again (pure).
	 *
	 * @param array[] $rows The order's ledger rows, oldest first.
	 * @return bool
	 */
	public static function refund_put_back( array $rows ) {
		if ( ! $rows || Money::outstanding( Money::order_cards( $rows ) ) > 0 ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( self::by_refund( $row ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A ledger row that put money back because of a refund (pure): by hand
	 * (part:, the Gift cards box) or by itself with its refund's id
	 * (refunded in full). Failed / cancelled put-backs carry no refund.
	 *
	 * @param array $row Ledger row.
	 * @return bool
	 */
	private static function by_refund( array $row ) {
		return 'restored' === (string) $row['type'] && ( 0 === strpos( (string) $row['idempotency_key'], 'part:' ) || ! empty( $row['refund_id'] ) );
	}

	/* ============================================================== */
	/*  Putting back                                                   */
	/* ============================================================== */

	/**
	 * Put back what is still out on an order.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $why       In words.
	 * @param string $hook      The hook.
	 * @param int    $refund_id The refund it is for (0 = none: failed, cancelled, trashed).
	 * @return int Cents put back.
	 */
	private function restore( $order_id, $why, $hook, $refund_id = 0 ) {
		$service = Gift_Cards::service();
		if ( 0 === $service->order_summary( (int) $order_id )['outstanding'] ) {
			return 0;
		}
		$result = $service->restore_order( (int) $order_id, $why, Audit::context( 'order', $hook ), $refund_id );
		$back   = $result['ok'] ? (int) $result['restored'] : 0;
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( is_object( $order ) && $back > 0 ) {
			/* translators: 1: amount, 2: why */
			$order->add_order_note( sprintf( __( '%1$s was put back on the customer\'s gift card (order %2$s).', 'fishotel-misc-plugin' ), Money::words( $back ), $why ) );
		} elseif ( is_object( $order ) && ! $result['ok'] ) {
			/* translators: %s: reason */
			$order->add_order_note( sprintf( __( 'The gift card money of this order could not be put back yet: %s It will be tried again the next time the order changes; or use the Gift cards box.', 'fishotel-misc-plugin' ), $result['message'] ) );
		}
		return $back;
	}

	/**
	 * Failed.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_failed( $order_id ) {
		$this->restore( $order_id, __( 'failed', 'fishotel-misc-plugin' ), 'woocommerce_order_status_failed' );
	}

	/**
	 * Cancelled — a paid order too, unless Settings say no.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_cancelled( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$paid  = is_object( $order ) && $order->get_date_paid();
		if ( $paid && ! Settings::on( Gift_Cards::service()->settings, 'cancel_paid_restores' ) ) {
			if ( Gift_Cards::service()->order_summary( (int) $order_id )['outstanding'] > 0 ) {
				$order->add_order_note( __( 'Cancelled after it was paid: its gift card money was NOT put back (Settings). Use the Gift cards box to put it back.', 'fishotel-misc-plugin' ) );
			}
			return;
		}
		$this->restore( $order_id, __( 'cancelled', 'fishotel-misc-plugin' ), 'woocommerce_order_status_cancelled' );
	}

	/**
	 * Refunded: the card part goes back by itself only when every item's
	 * whole quantity was refunded. WooCommerce also calls an order
	 * "refunded" when the money paid some other way is all returned —
	 * which can be a small part of the order when a card paid most of it —
	 * so otherwise a note points at the Gift cards box.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_refunded( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( is_object( $order ) && ! self::all_items_refunded( $order ) ) {
			$out = Gift_Cards::service()->order_summary( (int) $order_id )['outstanding'];
			if ( $out > 0 ) {
				/* translators: %s: amount */
				$order->add_order_note( sprintf( __( 'This order is now marked Refunded, but not every item was refunded, so its gift card money (%s) was NOT put back by itself. If it should go back on the card, use the Gift cards box on this order.', 'fishotel-misc-plugin' ), Money::words( $out ) ) );
			}
			return;
		}
		$this->restore( $order_id, __( 'refunded', 'fishotel-misc-plugin' ), 'woocommerce_order_status_refunded', self::newest_refund_id( $order ) );
	}

	/**
	 * The id of an order's newest refund (0 = none): the put-back is marked
	 * with it, so a later status change knows a refund took it back.
	 *
	 * @param object $order WC_Order.
	 * @return int
	 */
	public static function newest_refund_id( $order ) {
		$id = 0;
		if ( is_object( $order ) && method_exists( $order, 'get_refunds' ) ) {
			foreach ( (array) $order->get_refunds() as $refund ) {
				if ( is_object( $refund ) && method_exists( $refund, 'get_id' ) ) {
					$id = max( $id, (int) $refund->get_id() );
				}
			}
		}
		return $id;
	}

	/**
	 * Whether every item of an order was refunded, its whole quantity.
	 *
	 * @param object $order WC_Order.
	 * @return bool
	 */
	public static function all_items_refunded( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_qty_refunded_for_item' ) ) {
			return false;
		}
		$items = $order->get_items( 'line_item' );
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item_id => $item ) {
			if ( abs( (float) $order->get_qty_refunded_for_item( $item_id ) ) < (float) $item->get_quantity() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A partial refund: a note pointing at the Gift cards box (never
	 * automatic — the refund may already have gone to the payment method).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public function on_partial_refund( $order_id, $refund_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || 'refunded' === $order->get_status() ) {
			return;
		}
		$out = Gift_Cards::service()->order_summary( (int) $order_id )['outstanding'];
		if ( $out > 0 ) {
			/* translators: %s: amount */
			$order->add_order_note( sprintf( __( 'Part of this order was refunded. %s of it was paid with a gift card: if part of the refund should go back on the card instead, use the Gift cards box on this order.', 'fishotel-misc-plugin' ), Money::words( $out ) ) );
		}
	}

	/**
	 * Trashed or deleted: put back only when it was never paid.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_trash( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || $order->get_date_paid() ) {
			return;
		}
		$this->restore( $order_id, __( 'trashed before it was paid', 'fishotel-misc-plugin' ), 'trash order' );
	}

	/**
	 * Trashed / deleted on the classic order screen (orders are posts).
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trash_post( $post_id ) {
		if ( function_exists( 'get_post_type' ) && in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			$this->on_trash( (int) $post_id );
		}
	}

	/* ============================================================== */
	/*  Old orders                                                     */
	/* ============================================================== */

	/**
	 * The deduction an imported order shows (cents; 0 = none to add).
	 *
	 * @param object $order WC_Order.
	 * @return int
	 */
	public static function old_deduction( $order ) {
		if ( ! is_object( $order ) || ! $order->get_id() || self::fee_cents( $order ) > 0 ) {
			return 0;
		}
		$summary = Gift_Cards::service()->order_summary( (int) $order->get_id() );
		return $summary['imported'] ? (int) $summary['used'] : 0;
	}

	/**
	 * Totals in e-mails, the thank-you page and My Account: an old order's
	 * gift card line before the total.
	 *
	 * @param array  $rows  Rows.
	 * @param object $order WC_Order.
	 * @return array
	 */
	public function order_totals( $rows, $order ) {
		$cents = self::old_deduction( $order );
		if ( $cents <= 0 || ! is_array( $rows ) ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $key => $row ) {
			if ( 'order_total' === $key ) {
				$out['fh_gift_card'] = array(
					'label' => __( 'Gift card:', 'fishotel-misc-plugin' ),
					'value' => '−' . ( function_exists( 'wc_price' ) ? wc_price( $cents / 100, array( 'currency' => $order->get_currency() ) ) : Money::words( $cents ) ),
				);
			}
			$out[ $key ] = $row;
		}
		return $out;
	}

	/**
	 * The admin order screen: the same line.
	 *
	 * @param int $order_id Order id.
	 */
	public function admin_totals_row( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$cents = self::old_deduction( $order );
		if ( $cents > 0 ) {
			printf(
				'<tr><td class="label">%1$s</td><td width="1%%"></td><td class="total">−%2$s</td></tr>',
				esc_html__( 'Gift card (old plugin):', 'fishotel-misc-plugin' ),
				wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $cents / 100, array( 'currency' => $order->get_currency() ) ) : Money::words( $cents ) )
			);
		}
	}

	/**
	 * Recalculate on an old order: WooCommerce does not know the old
	 * plugin's deduction and would put it back on the total.
	 *
	 * @param bool   $and_taxes Taxes too.
	 * @param object $order     WC_Order.
	 */
	public function keep_old_deduction( $and_taxes, $order ) {
		if ( ! Settings::on( Gift_Cards::service()->settings, 'recalc_guard' ) ) {
			return;
		}
		$cents = self::old_deduction( $order );
		if ( $cents > 0 ) {
			$order->set_total( max( 0, Money::from_wc( $order->get_total() ) - $cents ) / 100 );
		}
	}

	/**
	 * Hide the fee marker on order screens.
	 *
	 * @param string[] $keys Hidden keys.
	 * @return string[]
	 */
	public function hidden_meta( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::FEE_META;
		return $keys;
	}

	/* ============================================================== */
	/*  What the customer sees                                         */
	/* ============================================================== */

	/**
	 * The balance box.
	 */
	public function render_control() {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ) ) {
			return;
		}
		try {
			$money = self::applied( WC()->cart );
		} catch ( \Throwable $e ) {
			return;
		}
		if ( $money['wallet'] <= 0 ) {
			return;
		}
		$choice = self::choice();
		$cap    = Money::choose( $money['wallet'], self::cap( WC()->cart, Gift_Cards::service()->settings ), null );
		$value  = null === $choice['amount'] ? $cap : min( $choice['amount'], $cap );
		include Gift_Cards::path() . 'views/checkout-balance.php';
	}

	/**
	 * "Have a gift card?" under Payment Method.
	 */
	public function render_code_field() {
		if ( ! is_user_logged_in() && ! Settings::on( Gift_Cards::service()->settings, 'guest_codes' ) ) {
			return;
		}
		include Gift_Cards::path() . 'views/checkout-code.php';
	}

	/**
	 * The theme's marker: a card is applied (it hides PayPal's smart
	 * buttons, which do not see a fee taken off the cart's lines).
	 */
	public function render_marker() {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ) ) {
			return;
		}
		foreach ( WC()->cart->get_fees() as $fee ) {
			if ( self::FEE_ID === ( $fee->id ?? '' ) ) {
				echo '<tr class="fh-gcard-marker" style="display:none"><td colspan="2"><input type="hidden" class="fh-gc-applied" value="1"></td></tr>';
				return;
			}
		}
	}

	/**
	 * The checkout script.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'fishotel-gift-cards-front', Gift_Cards::url() . 'css/gift-cards-front.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-gift-cards-checkout', Gift_Cards::url() . 'js/gift-cards-checkout.js', array( 'jquery' ), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-gift-cards-checkout',
			'fhGiftCards',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_APPLY,
				'nonce'   => wp_create_nonce( self::NONCE_APPLY ),
				'words'   => array(
					'empty'   => __( 'Type the code first.', 'fishotel-misc-plugin' ),
					'working' => __( 'Checking…', 'fishotel-misc-plugin' ),
					'failed'  => __( 'That did not work. Please try again.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/**
	 * "Apply" a typed code (AJAX): nonce first, then who is asking.
	 */
	public function ajax_apply() {
		if ( ! check_ajax_referer( self::NONCE_APPLY, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'This page is out of date. Please reload it and try again.', 'fishotel-misc-plugin' ) ), 403 );
		}
		$service = Gift_Cards::service();
		$user_id = (int) get_current_user_id();
		$code    = isset( $_POST['code'] ) && is_scalar( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		$who     = self::who( $user_id, $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in who().
		$result  = self::apply_code( $service, $code, $user_id, $who );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ), 'login' === $result['code'] ? 403 : 200 );
	}

	/**
	 * The key wrong codes are counted under (pure): the user, or for a
	 * guest the connecting address only — a forwarded-for header is typed
	 * by the sender and would give every try a fresh count.
	 *
	 * @param int   $user_id User id (0 = guest).
	 * @param array $server  $_SERVER.
	 * @return string
	 */
	public static function who( $user_id, array $server ) {
		if ( (int) $user_id > 0 ) {
			return 'user:' . (int) $user_id;
		}
		$remote = isset( $server['REMOTE_ADDR'] ) ? filter_var( trim( (string) $server['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
		return 'ip:' . ( $remote ? $remote : 'unknown' );
	}

	/**
	 * Apply a typed code (the AJAX handler's work; the tests call it).
	 *
	 * @param Service $service Service.
	 * @param string  $code    Typed code.
	 * @param int     $user_id User id (0 = guest).
	 * @param string  $who     The rate-limit key.
	 * @return array
	 */
	public static function apply_code( Service $service, $code, $user_id, $who ) {
		if ( ! $service->may_try( $who ) ) {
			return Service::fail( 'limit', __( 'Too many codes were tried. Please wait an hour, or contact us.', 'fishotel-misc-plugin' ) );
		}
		if ( $user_id <= 0 && ! Settings::on( $service->settings, 'guest_codes' ) ) {
			return Service::fail( 'login', __( 'Please log in to use a gift card — it is then kept in your account.', 'fishotel-misc-plugin' ) );
		}
		if ( $user_id > 0 ) {
			$result = $service->redeem( $code, $user_id, Audit::context( 'checkout', 'checkout code' ) );
		} else {
			$result = $service->find_for_checkout( $code, 0 );
			if ( $result['ok'] ) {
				$codes   = self::session_codes();
				$codes[] = (int) $result['card_id'];
				$s       = self::session();
				if ( $s ) {
					$s->set( self::SESSION_CODES, array_values( array_unique( $codes ) ) );
				}
				$result['message'] = __( 'Gift card applied to this order.', 'fishotel-misc-plugin' );
			}
		}
		if ( ! $result['ok'] ) {
			if ( in_array( $result['code'], array( 'not_found', 'empty' ), true ) ) {
				$service->wrong_try( $who );
			}
			return $result;
		}
		self::set_choice(
			array(
				'use'    => true,
				'amount' => null,
			)
		);
		return $result;
	}
}
