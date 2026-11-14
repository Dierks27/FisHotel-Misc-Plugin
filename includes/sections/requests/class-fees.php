<?php
/**
 * Requests fees — the request fee, paid through the store's existing
 * custom-order DEPOSIT system so the money lands in the customer's
 * wallet. Nothing here credits a wallet: FisHotel Batch Manager does.
 *
 * How it joins Batch Manager's own path (read in its code, v10.29):
 *
 *   - the wallet-deposit product is Batch Manager's (its
 *     fishotel_deposit_product_id option; this section never creates one);
 *   - a cart line carrying is_deposit + deposit_amount is priced at that
 *     amount by Batch Manager (set_deposit_cart_price), named "Wallet
 *     Refill" and made purchasable;
 *   - when the order is paid (woocommerce_payment_complete, or the order
 *     reaching processing / completed) Batch Manager's
 *     credit_wallet_on_payment() adds the deposit line's total to the
 *     wallet of the order's CUSTOMER ACCOUNT and marks the order
 *     _fishotel_wallet_credited.
 *
 * So the pay link of an invoice puts that product in the cart with those
 * two keys plus this section's own key (the carrier request, the amount
 * and their signature), tags the order line (and the order) with the
 * request, and — at priority 20 on the same three hooks, after Batch
 * Manager's 10 — records the fee as paid and logs whether the wallet was
 * credited.
 *
 * Where Batch Manager's handling would NOT credit the right amount to the
 * right customer, and what this class does about it:
 *
 *   - an order without a customer account (guest checkout) is credited to
 *     nobody → the pay link asks for a login first, and the checkout
 *     refuses a fee line without an account;
 *   - the wallet credited is the account that pays, whoever the request
 *     belongs to → a request that belongs to a site customer can only be
 *     paid while logged in as that customer; a request without an account
 *     joins the account that paid;
 *   - the credit is the line's total after discounts → no coupon is ever
 *     applied to a fee line, and its quantity is always 1, so what is
 *     credited is the fee (on a store whose prices include tax and whose
 *     deposit product is taxable, the credit is the fee before tax — the
 *     request's history then says how much was credited). One exception
 *     WooCommerce leaves no filter for: the remainder cent of a fixed-cart
 *     coupon can land on the fee line, so a line up to SHORT_BY below the
 *     fee still counts as paid (the wallet gets what was paid);
 *   - Batch Manager credits only its own deposit product, or a line whose
 *     name contains "Wallet Refill" → any other product typed in Requests →
 *     Settings makes fees unavailable, and the screens say why;
 *   - the amount is whatever the cart line says → it comes from a signed
 *     token and is checked again every time the cart is priced.
 *
 * Without WooCommerce, Batch Manager or the wallet-deposit product, fees
 * are unavailable: status() says why, the screens show it, and a request
 * can still be approved without a fee.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fees
 */
class Fees {

	/**
	 * The cart item key that marks a fee line (id, amount, sig).
	 *
	 * @var string
	 */
	const CART_KEY = 'fh_request_fee';

	/**
	 * Order line item meta: the carrier request, and the fee.
	 */
	const ITEM_META        = '_fh_request_fee';
	const ITEM_AMOUNT_META = '_fh_request_fee_amount';

	/**
	 * Order meta: the carrier requests paid with the order ("12,15").
	 *
	 * @var string
	 */
	const ORDER_META = '_fishotel_request_fee';

	/**
	 * Batch Manager: its class, its deposit product option and the order
	 * meta it sets once it has credited a wallet.
	 */
	const BATCH_CLASS    = 'FisHotel_Batch_Manager';
	const BATCH_OPTION   = 'fishotel_deposit_product_id';
	const BATCH_CREDITED = '_fishotel_wallet_credited';

	/**
	 * What Batch Manager looks for in the NAME of an order line that is not
	 * its own deposit product before it credits a wallet for it.
	 *
	 * @var string
	 */
	const BATCH_NAME = 'Wallet Refill';

	/**
	 * How far below the fee a paid line may fall and still count: a
	 * fixed-cart coupon's remainder cent lands on the dearest line past
	 * every filter (one cent per coupon).
	 *
	 * @var float
	 */
	const SHORT_BY = 0.05;

	/**
	 * The memoised status (null = not asked in this request).
	 *
	 * @var array|null
	 */
	private static $status = null;

	/**
	 * The fee lines already checked in this request ("id|cents" → result).
	 *
	 * @var array<string, array>
	 */
	private static $checked = array();

	/**
	 * The orders already handled in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $handled = array();

	/**
	 * Register the cart, checkout and payment hooks. Every callback
	 * returns at once unless a fee line or a tagged order is in front of
	 * it.
	 */
	public function init() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'cart_lines' ), 10000 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'no_discount' ), 10, 5 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'fixed_quantity' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item' ), 10, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );

		// After Batch Manager's own handling (priority 10) on the same hooks.
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			add_action( $hook, array( $this, 'order_paid' ), 20 );
		}
	}

	/* ============================================================== */
	/*  Is a fee possible?                                             */
	/* ============================================================== */

	/**
	 * The wallet-deposit product: the Requests setting when one is typed
	 * there, else Batch Manager's stored setting, else the product Batch
	 * Manager itself falls back to (asked from its own code — the number
	 * is not repeated here).
	 *
	 * @param array $settings Requests settings.
	 * @return int 0 when there is none.
	 */
	public static function product_id( array $settings ) {
		$id = (int) ( $settings['deposit_product'] ?? 0 );
		if ( $id <= 0 ) {
			$id = self::batch_product_id();
		}
		if ( function_exists( 'apply_filters' ) ) {
			$id = (int) apply_filters( 'fishotel_requests_deposit_product_id', $id );
		}
		return max( 0, $id );
	}

	/**
	 * Batch Manager's OWN deposit product — the one it credits a wallet
	 * for whatever the product is called: its stored setting, else the
	 * product it falls back to.
	 *
	 * @return int 0 when there is none.
	 */
	public static function batch_product_id() {
		$id = (int) get_option( self::BATCH_OPTION, 0 );
		return $id > 0 ? $id : self::batch_default();
	}

	/**
	 * Whether Batch Manager credits a wallet when this product is bought
	 * (its two tests in credit_wallet_on_payment(): the line is its own
	 * deposit product, or the line's name contains "Wallet Refill").
	 *
	 * @param int    $id   Product id.
	 * @param string $name Product name.
	 * @return bool
	 */
	public static function credited_by_batch( $id, $name ) {
		return ( (int) $id > 0 && (int) $id === self::batch_product_id() ) || false !== stripos( (string) $name, self::BATCH_NAME );
	}

	/**
	 * The deposit product Batch Manager uses when its setting was never
	 * saved, read through its own getter.
	 *
	 * @return int
	 */
	private static function batch_default() {
		$batch = isset( $GLOBALS['fishotel_instance'] ) ? $GLOBALS['fishotel_instance'] : null;
		$class = self::BATCH_CLASS;
		if ( ! is_object( $batch ) || ! class_exists( $class, false ) || ! ( $batch instanceof $class ) || ! method_exists( $batch, 'get_deposit_product_id' ) ) {
			return 0;
		}
		try {
			$read = \Closure::bind(
				static function ( $batch ) {
					return $batch->get_deposit_product_id();
				},
				null,
				$class
			);
			return $read ? max( 0, (int) $read( $batch ) ) : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Whether a fee can be charged, and why not — in words the screens show.
	 *
	 * @param array|null $settings Requests settings (null = read them).
	 * @param bool       $fresh    Ask again instead of using this request's answer.
	 * @return array{available: bool, reason: string, product_id: int, product_title: string}
	 */
	public static function status( $settings = null, $fresh = false ) {
		if ( null !== self::$status && ! $fresh ) {
			return self::$status;
		}

		$out = array(
			'available'     => false,
			'reason'        => '',
			'product_id'    => 0,
			'product_title' => '',
		);

		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
			$out['reason'] = __( 'WooCommerce is not active', 'fishotel-misc-plugin' );
		} elseif ( ! class_exists( self::BATCH_CLASS, false ) ) {
			$out['reason'] = __( 'FisHotel Batch Manager is not active (its wallet is where a fee goes)', 'fishotel-misc-plugin' );
		} elseif ( ! method_exists( self::BATCH_CLASS, 'credit_wallet_on_payment' ) ) {
			$out['reason'] = __( 'this version of FisHotel Batch Manager has no wallet deposit', 'fishotel-misc-plugin' );
		} else {
			$id                = self::product_id( is_array( $settings ) ? $settings : Settings::get() );
			$out['product_id'] = $id;
			$product           = $id > 0 ? wc_get_product( $id ) : null;
			if ( $id <= 0 ) {
				$out['reason'] = __( 'no wallet-deposit product is set (Batch Manager\'s settings, or Requests → Settings)', 'fishotel-misc-plugin' );
			} elseif ( ! is_object( $product ) ) {
				$out['reason'] = __( 'the deposit product is missing — set it under Requests → Settings → Fees', 'fishotel-misc-plugin' );
			} elseif ( method_exists( $product, 'get_status' ) && 'trash' === $product->get_status() ) {
				$out['reason'] = __( 'the deposit product is in the trash — restore it under Products, or set another under Requests → Settings → Fees', 'fishotel-misc-plugin' );
			} else {
				$out['product_title'] = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
				if ( self::credited_by_batch( $id, $out['product_title'] ) ) {
					$out['available'] = true;
				} else {
					// Paid with any other product the money would be taken and no wallet credited.
					$out['reason'] = sprintf(
						/* translators: 1: product id, 2: product name, 3: Batch Manager's product id, 4: "Wallet Refill" */
						__( 'product #%1$d ("%2$s") is not one Batch Manager credits a wallet for — it credits its own deposit product (#%3$d) or a product with "%4$s" in its name; leave the product empty in Requests → Settings to use Batch Manager\'s', 'fishotel-misc-plugin' ),
						$id,
						$out['product_title'],
						self::batch_product_id(),
						self::BATCH_NAME
					);
				}
			}
		}

		self::$status = $out;
		return $out;
	}

	/**
	 * Forget this request's answers (tests; a settings save).
	 */
	public static function forget() {
		self::$status  = null;
		self::$checked = array();
		self::$handled = array();
	}

	/* ============================================================== */
	/*  The pay link                                                   */
	/* ============================================================== */

	/**
	 * What to do with a pay link (no side effect, so it is testable):
	 *
	 *   message  show a page with 'message' (invalid, changed, paid, closed, unavailable, wrong account)
	 *   login    ask the visitor to log in first
	 *   cart     put the fee in the cart: carrier, amount, product_id
	 *
	 * @param mixed   $token   The token from the link.
	 * @param int     $user_id The logged-in user (0 = nobody).
	 * @param Service $service The service.
	 * @param array   $fees    status().
	 * @return array{do: string, message: string, carrier?: int, amount?: float, product_id?: int}
	 */
	public static function decide( $token, $user_id, $service, array $fees ) {
		$read = Tokens::read_pay( $token );
		if ( null === $read ) {
			return array(
				'do'      => 'message',
				'message' => __( 'This payment link is not valid. Please use the link in our e-mail exactly as it is.', 'fishotel-misc-plugin' ),
			);
		}

		$state = $service->payable( $read['id'], $read['amount'] );
		if ( ! $state['ok'] ) {
			return array(
				'do'      => 'message',
				'message' => $state['message'],
			);
		}

		if ( empty( $fees['available'] ) ) {
			return array(
				'do'      => 'message',
				'message' => __( 'The fee cannot be paid online right now. Please contact us — your request is safe.', 'fishotel-misc-plugin' ),
			);
		}

		if ( (int) $user_id <= 0 ) {
			return array(
				'do'      => 'login',
				'message' => __( 'Please log in to pay the request fee. The fee goes into your wallet, and the wallet lives on your account.', 'fishotel-misc-plugin' ),
			);
		}

		foreach ( array_merge( array( $state['carrier'] ), $state['members'] ) as $request ) {
			if ( (int) $request['user_id'] > 0 && (int) $request['user_id'] !== (int) $user_id ) {
				return array(
					'do'      => 'message',
					'message' => __( 'This bill belongs to another account. Please log out and log in with the account the request was made with.', 'fishotel-misc-plugin' ),
				);
			}
		}

		return array(
			'do'         => 'cart',
			'message'    => '',
			'carrier'    => (int) $state['carrier']['id'],
			'amount'     => (float) $state['carrier']['fee_amount'],
			'product_id' => (int) $fees['product_id'],
		);
	}

	/**
	 * What a fee line carries in the cart: Batch Manager's two keys (so
	 * its own pricing, naming and wallet credit apply) and this section's.
	 *
	 * @param int   $carrier_id The request that carries the invoice.
	 * @param float $amount     The fee.
	 * @return array
	 */
	public static function cart_item_data( $carrier_id, $amount ) {
		$amount = round( (float) $amount, 2 );
		return array(
			'is_deposit'     => true,
			'deposit_amount' => $amount,
			self::CART_KEY   => array(
				'id'     => (int) $carrier_id,
				'amount' => $amount,
				'sig'    => Tokens::pay_signature( $carrier_id, $amount ),
			),
		);
	}

	/**
	 * Put the fee of an invoice in the cart (replacing an earlier line of
	 * the same invoice).
	 *
	 * @param object $cart       WC_Cart.
	 * @param int    $carrier_id The request that carries the invoice.
	 * @param float  $amount     The fee.
	 * @param int    $product_id The wallet-deposit product.
	 * @return bool
	 */
	public static function add_to_cart( $cart, $carrier_id, $amount, $product_id ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_to_cart' ) || ! method_exists( $cart, 'get_cart' ) ) {
			return false;
		}
		try {
			foreach ( (array) $cart->get_cart() as $key => $item ) {
				if ( isset( $item[ self::CART_KEY ]['id'] ) && (int) $item[ self::CART_KEY ]['id'] === (int) $carrier_id ) {
					$cart->remove_cart_item( $key );
				}
			}
			return (bool) $cart->add_to_cart( (int) $product_id, 1, 0, array(), self::cart_item_data( $carrier_id, $amount ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ============================================================== */
	/*  The cart                                                       */
	/* ============================================================== */

	/**
	 * Whether a cart line is a genuine fee that can still be paid.
	 *
	 * @param mixed $fee The line's CART_KEY value.
	 * @return array{ok: bool, message: string, members: array[]}
	 */
	public static function check_line( $fee ) {
		$bad = array(
			'ok'      => false,
			'message' => __( 'A request fee was removed from your cart: its link is no longer valid.', 'fishotel-misc-plugin' ),
			'members' => array(),
		);
		if ( ! is_array( $fee ) || ! isset( $fee['id'], $fee['amount'], $fee['sig'] ) || ! Tokens::check_pay( $fee['id'], $fee['amount'], $fee['sig'] ) ) {
			return $bad;
		}

		$memo = (int) $fee['id'] . '|' . Tokens::cents( $fee['amount'] );
		if ( isset( self::$checked[ $memo ] ) ) {
			return self::$checked[ $memo ];
		}

		$status = self::status();
		if ( empty( $status['available'] ) ) {
			$result = array(
				'ok'      => false,
				'message' => __( 'A request fee was removed from your cart: it cannot be paid online right now. Please contact us — your request is safe.', 'fishotel-misc-plugin' ),
				'members' => array(),
			);
		} else {
			$state  = Requests::service()->payable( (int) $fee['id'], (float) $fee['amount'] );
			$result = array(
				'ok'      => $state['ok'],
				/* translators: %s: why */
				'message' => $state['ok'] ? '' : sprintf( __( 'A request fee was removed from your cart: %s', 'fishotel-misc-plugin' ), $state['message'] ),
				'members' => $state['members'],
			);
		}

		self::$checked[ $memo ] = $result;
		return $result;
	}

	/**
	 * Before the cart is priced: every fee line is checked again, priced
	 * at its signed amount — that line only — and kept at a quantity of 1;
	 * a line that is not (or no longer) a payable invoice is removed.
	 *
	 * @param object $cart WC_Cart.
	 */
	public function cart_lines( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		foreach ( (array) $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}

			$check = self::check_line( $item[ self::CART_KEY ] );
			if ( ! $check['ok'] ) {
				if ( method_exists( $cart, 'remove_cart_item' ) ) {
					$cart->remove_cart_item( $key );
				}
				if ( function_exists( 'wc_add_notice' ) && function_exists( 'wc_has_notice' ) && ! wc_has_notice( $check['message'], 'notice' ) ) {
					wc_add_notice( $check['message'], 'notice' );
				}
				continue;
			}

			if ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'set_price' ) ) {
				$item['data']->set_price( (float) $item[ self::CART_KEY ]['amount'] );
			}
			if ( isset( $item['quantity'] ) && 1 !== (int) $item['quantity'] && method_exists( $cart, 'set_quantity' ) ) {
				$cart->set_quantity( $key, 1, false );
			}
		}
	}

	/**
	 * No coupon takes anything off a fee line: what Batch Manager credits
	 * to the wallet is the line's total, and that must be the fee.
	 *
	 * @param float  $discount           The discount WooCommerce worked out.
	 * @param float  $discounting_amount The amount being discounted.
	 * @param array  $cart_item          The cart line.
	 * @param bool   $single             Whether it is per item.
	 * @param object $coupon             The coupon.
	 * @return float
	 */
	public function no_discount( $discount, $discounting_amount = 0, $cart_item = null, $single = false, $coupon = null ) {
		unset( $discounting_amount, $single, $coupon );
		return is_array( $cart_item ) && ! empty( $cart_item[ self::CART_KEY ] ) ? 0 : $discount;
	}

	/**
	 * The cart page shows a plain "1" for a fee line instead of a
	 * quantity field.
	 *
	 * @param string $html          The quantity field.
	 * @param string $cart_item_key The line's key.
	 * @param array  $cart_item     The line.
	 * @return string
	 */
	public function fixed_quantity( $html, $cart_item_key = '', $cart_item = null ) {
		unset( $cart_item_key );
		return is_array( $cart_item ) && ! empty( $cart_item[ self::CART_KEY ] ) ? '1' : $html;
	}

	/**
	 * Under the line's name in the cart and at the checkout: what the fee
	 * is for.
	 *
	 * @param array $data      The line's item data.
	 * @param array $cart_item The line.
	 * @return array
	 */
	public function item_data( $data, $cart_item = null ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::CART_KEY ] ) ) {
			return $data;
		}

		$check = self::check_line( $cart_item[ self::CART_KEY ] );
		$fish  = array();
		foreach ( $check['members'] as $member ) {
			$fish[] = Rules::fish_line( $member );
		}

		$data   = is_array( $data ) ? $data : array();
		$data[] = array(
			'key'   => esc_html__( 'Request fee for', 'fishotel-misc-plugin' ),
			'value' => esc_html( $fish ? implode( ', ', $fish ) : __( 'your fish request', 'fishotel-misc-plugin' ) ),
		);
		$data[] = array(
			'key'   => esc_html__( 'Goes to', 'fishotel-misc-plugin' ),
			'value' => esc_html__( 'your wallet — spend it on anything', 'fishotel-misc-plugin' ),
		);

		return $data;
	}

	/**
	 * A fee is only paid from an account: without one, Batch Manager has
	 * no wallet to credit.
	 *
	 * @param array  $data   The posted checkout fields.
	 * @param object $errors WP_Error.
	 */
	public function checkout_validation( $data, $errors = null ) {
		if ( ! function_exists( 'WC' ) || ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$has_fee = false;
		foreach ( (array) $cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ] ) ) {
				$has_fee = true;
				break;
			}
		}
		if ( ! $has_fee ) {
			return;
		}

		$logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$creating  = is_array( $data ) && ! empty( $data['createaccount'] );
		if ( ! $logged_in && ! $creating ) {
			$errors->add( 'fh_request_fee', __( 'Please log in (or create an account) to pay a request fee: it is credited to the wallet of your account.', 'fishotel-misc-plugin' ) );
		}
	}

	/* ============================================================== */
	/*  The order                                                      */
	/* ============================================================== */

	/**
	 * Checkout: tag the order line — and the order — with the request.
	 *
	 * @param object $item          WC_Order_Item_Product.
	 * @param string $cart_item_key The cart line's key.
	 * @param array  $values        The cart line.
	 * @param object $order         WC_Order.
	 */
	public function order_line_item( $item, $cart_item_key = '', $values = null, $order = null ) {
		unset( $cart_item_key );
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) || ! is_array( $values ) || empty( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$fee = $values[ self::CART_KEY ];
		if ( ! is_array( $fee ) || ! isset( $fee['id'], $fee['amount'], $fee['sig'] ) || ! Tokens::check_pay( $fee['id'], $fee['amount'], $fee['sig'] ) ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META, (int) $fee['id'], true );
		$item->add_meta_data( self::ITEM_AMOUNT_META, number_format( (float) $fee['amount'], 2, '.', '' ), true );

		if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'get_meta' ) ) {
			$ids   = array_filter( array_map( 'intval', explode( ',', (string) $order->get_meta( self::ORDER_META, true ) ) ) );
			$ids[] = (int) $fee['id'];
			$order->update_meta_data( self::ORDER_META, implode( ',', array_unique( $ids ) ) );
		}
	}

	/**
	 * An order was paid (or reached processing / completed): record the
	 * fee of every tagged line, then fill the requests that were offered
	 * to this customer for a product on the order.
	 *
	 * @param int $order_id Order id.
	 */
	public function order_paid( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || isset( self::$handled[ $order_id ] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		self::$handled[ $order_id ] = true;

		try {
			$order = wc_get_order( $order_id );
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'get_meta' ) ) {
				return;
			}
			self::record( $order, Requests::service() );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'fishotel_log', 'error', 'requests', sprintf( 'The paid order #%1$d could not be read for request fees: %2$s', $order_id, $e->getMessage() ), array( 'order_id' => $order_id ) );
			}
		}
	}

	/**
	 * Read a paid order: the fee lines and the products bought.
	 *
	 * @param object  $order   WC_Order (or the tests' stand-in).
	 * @param Service $service The service.
	 * @return array{paid: int[], short: int[], filled: int[]}
	 */
	public static function record( $order, $service ) {
		$out = array(
			'paid'   => array(),
			'short'  => array(),
			'filled' => array(),
		);

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$user_id  = method_exists( $order, 'get_user_id' ) ? (int) $order->get_user_id() : 0;
		$products = array();

		foreach ( (array) $order->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			foreach ( array( 'get_product_id', 'get_variation_id' ) as $getter ) {
				if ( method_exists( $item, $getter ) && (int) $item->$getter() > 0 ) {
					$products[] = (int) $item->$getter();
				}
			}

			$carrier_id = method_exists( $item, 'get_meta' ) ? (int) $item->get_meta( self::ITEM_META, true ) : 0;
			if ( $carrier_id <= 0 ) {
				continue;
			}
			$carrier = $service->store->get( $carrier_id );
			if ( ! $carrier || ! empty( $carrier['fee_paid_at'] ) ) {
				continue;
			}

			// What the customer paid for the line (with its tax, on a store whose
			// prices include it); what Batch Manager credits is the total before tax.
			$before_tax = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
			$paid       = $before_tax + ( method_exists( $item, 'get_total_tax' ) ? (float) $item->get_total_tax() : 0.0 );
			// A few cents short still counts (see SHORT_BY): the history names what was paid.
			if ( $paid + self::SHORT_BY < (float) $carrier['fee_amount'] ) {
				$out['short'][] = $carrier_id;
				$service->store->log(
					$carrier_id,
					'note',
					/* translators: 1: order number, 2: amount paid, 3: the fee */
					sprintf( __( 'Order #%1$d paid %2$s of the %3$s fee — NOT recorded as paid. Check the order.', 'fishotel-misc-plugin' ), $order_id, Rules::money( $paid ), Rules::money( (float) $carrier['fee_amount'] ) ),
					0,
					'system'
				);
				continue;
			}

			$credited = (bool) $order->get_meta( self::BATCH_CREDITED, true );
			$result   = $service->paid(
				$carrier_id,
				array(
					'order_id' => $order_id,
					'amount'   => $paid,
					'credit'   => $before_tax,
					'user_id'  => $user_id,
					'wallet'   => $credited,
				)
			);
			if ( ! empty( $result['ok'] ) ) {
				$out['paid'][] = $carrier_id;
			}
			if ( ! $credited && function_exists( 'do_action' ) ) {
				do_action(
					'fishotel_log',
					'error',
					'requests',
					sprintf( 'Order #%1$d paid the fee of request #%2$d, but Batch Manager did not credit a wallet (no customer account on the order, or Batch Manager is off).', $order_id, $carrier_id ),
					array(
						'order_id'   => $order_id,
						'request_id' => $carrier_id,
					)
				);
			}
		}

		$created = 0;
		if ( method_exists( $order, 'get_date_created' ) ) {
			$date    = $order->get_date_created();
			$created = is_object( $date ) && method_exists( $date, 'getTimestamp' ) ? (int) $date->getTimestamp() : 0;
		}
		$out['filled'] = Follow::bought(
			$service,
			array(
				'id'          => $order_id,
				'user_id'     => $user_id,
				'email'       => method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '',
				'created'     => $created,
				'product_ids' => $products,
			)
		);

		return $out;
	}
}
