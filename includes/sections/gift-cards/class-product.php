<?php
/**
 * Gift card products (Live only).
 *
 *   The product  any simple or variable product with "Gift card" ticked on
 *                its General tab (meta _fh_gift_card = yes); the old plugin's
 *                flag (_gift_card = yes) is honoured too, read only, so the
 *                existing products work without being edited. Fixed amounts
 *                are the product's price or its variations' prices; "Let the
 *                buyer choose the amount" adds an amount field (its smallest
 *                and largest from the product, else Settings).
 *   Buying       the fields above Add to cart: amount (when it may be
 *                chosen), who it is for (name, e-mail), from, a message, and
 *                an optional delivery date. Checked on Add to cart, shown on
 *                the cart line and kept on the order line.
 *   Issuing      when the order is paid (payment complete / processing /
 *                completed): one card per unit of each gift card line, each
 *                exactly once; its e-mail goes at once or on the date.
 *
 * read_purchase() is pure.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product
 */
class Product {

	const FLAG        = '_fh_gift_card';
	const LEGACY_FLAG = '_gift_card';
	const CUSTOM      = '_fh_gift_card_custom';
	const MIN         = '_fh_gift_card_min';
	const MAX         = '_fh_gift_card_max';
	const CART_KEY    = 'fh_gcard';
	const ITEM_AMOUNT = '_fh_gcard_amount_cents';
	const ITEM_DATA   = '_fh_gcard_data';
	const MAX_AHEAD   = 366;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'purchase_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'line_item' ), 10, 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'issue' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'issue' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'undone_note' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'undone_note' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_meta' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'issued_note' ), 10, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'loop_url' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'no_ajax_add' ), 10, 3 );
	}

	/**
	 * Whether a product (or a variation's parent) is a gift card.
	 *
	 * @param object|int $product WC_Product or id.
	 * @return bool
	 */
	public static function is_gift( $product ) {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return false;
		}
		$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		return 'yes' === get_post_meta( $id, self::FLAG, true ) || 'yes' === get_post_meta( $id, self::LEGACY_FLAG, true );
	}

	/**
	 * The limits of a product's custom amount (cents), or null when the
	 * buyer may not choose it.
	 *
	 * @param int   $product_id Product (parent) id.
	 * @param array $settings   Settings.
	 * @return array{min: int, max: int}|null
	 */
	public static function custom_limits( $product_id, array $settings ) {
		if ( 'yes' !== get_post_meta( (int) $product_id, self::CUSTOM, true ) ) {
			return null;
		}
		$min = Money::to_cents( (string) get_post_meta( (int) $product_id, self::MIN, true ) );
		$max = Money::to_cents( (string) get_post_meta( (int) $product_id, self::MAX, true ) );
		$min = null === $min || $min < 100 ? (int) $settings['custom_min'] : $min;
		$max = null === $max || $max < $min ? max( $min, (int) $settings['custom_max'] ) : $max;
		return array(
			'min' => $min,
			'max' => min( $max, Money::MAX_CENTS ),
		);
	}

	/**
	 * Read the purchase fields (pure).
	 *
	 * @param array      $post   The posted fields.
	 * @param int        $price  The product's price in cents (0 = none).
	 * @param array|null $limits custom_limits().
	 * @param string     $today  Y-m-d at the site.
	 * @return array{ok: bool, errors: string[], data: array}
	 */
	public static function read_purchase( array $post, $price, $limits, $today ) {
		$get    = static function ( $key, $max = 190 ) use ( $post ) {
			return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? substr( trim( strip_tags( (string) $post[ $key ] ) ), 0, $max ) : '';
		};
		$errors = array();
		$amount = (int) $price;
		$typed  = $get( 'fh_gcard_amount', 20 );
		if ( is_array( $limits ) && '' !== $typed ) {
			$amount = Money::to_cents( $typed );
			if ( null === $amount || $amount < $limits['min'] || $amount > $limits['max'] ) {
				/* translators: 1: smallest, 2: largest */
				$errors[] = sprintf( __( 'Choose an amount between %1$s and %2$s.', 'fishotel-misc-plugin' ), Money::words( $limits['min'] ), Money::words( $limits['max'] ) );
				$amount   = 0;
			}
		} elseif ( $amount <= 0 ) {
			$errors[] = is_array( $limits ) ? __( 'Choose the amount of the gift card.', 'fishotel-misc-plugin' ) : __( 'This gift card has no amount yet. Please contact us.', 'fishotel-misc-plugin' );
		}

		$email = strtolower( $get( 'fh_gcard_to_email' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			$errors[] = __( 'The recipient\'s e-mail address does not look right.', 'fishotel-misc-plugin' );
		}
		$date = $get( 'fh_gcard_date', 10 );
		if ( '' !== $date ) {
			$d    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			$last = ( new \DateTimeImmutable( $today ) )->modify( '+' . self::MAX_AHEAD . ' days' )->format( 'Y-m-d' );
			if ( ! $d || $d->format( 'Y-m-d' ) !== $date || $date < $today || $date > $last ) {
				$errors[] = __( 'Pick a delivery date from today to a year ahead, or leave it empty to send it straight away.', 'fishotel-misc-plugin' );
			}
		}
		$message = isset( $post['fh_gcard_message'] ) && is_scalar( $post['fh_gcard_message'] ) ? trim( strip_tags( str_replace( "\r", '', (string) $post['fh_gcard_message'] ) ) ) : '';

		return array(
			'ok'     => ! $errors,
			'errors' => $errors,
			'data'   => array(
				'amount_cents'    => (int) $amount,
				'custom'          => is_array( $limits ) && '' !== $typed,
				'recipient_name'  => $get( 'fh_gcard_to_name', 120 ),
				'recipient_email' => $email,
				'sender_name'     => $get( 'fh_gcard_from_name', 120 ),
				'message'         => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, Service::MAX_MESSAGE ) : substr( $message, 0, Service::MAX_MESSAGE ),
				'deliver_on'      => $date,
			),
		);
	}

	/**
	 * A product's or variation's price in cents.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 * @return int
	 */
	private static function price_of( $product_id, $variation_id ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id ) : null;
		return is_object( $p ) ? Money::from_wc( $p->get_price() ) : 0;
	}

	/* ============================================================== */
	/*  The product screen                                             */
	/* ============================================================== */

	/**
	 * The General tab's fields.
	 */
	public function product_fields() {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		echo '<div class="options_group fh-gcard-product">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::FLAG,
				'label'       => __( 'Gift card', 'fishotel-misc-plugin' ),
				'description' => __( 'Selling this issues a gift card for its price (or each variation\'s price) when the order is paid.', 'fishotel-misc-plugin' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::CUSTOM,
				'label'       => __( 'Buyer chooses the amount', 'fishotel-misc-plugin' ),
				'description' => __( 'Adds an amount field; the price above is used when it is left empty.', 'fishotel-misc-plugin' ),
			)
		);
		foreach ( array(
			self::MIN => __( 'Smallest amount', 'fishotel-misc-plugin' ),
			self::MAX => __( 'Largest amount', 'fishotel-misc-plugin' ),
		) as $id => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'          => $id,
					'label'       => $label,
					'data_type'   => 'price',
					'desc_tip'    => true,
					'description' => __( 'Empty = the one in FisHotel Tools → Gift Cards → Settings.', 'fishotel-misc-plugin' ),
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Save them (WooCommerce checked the product form's nonce and the
	 * right to edit the product).
	 *
	 * @param object $product WC_Product.
	 */
	public function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce before this action.
		$product->update_meta_data( self::FLAG, isset( $_POST[ self::FLAG ] ) ? 'yes' : 'no' );
		$product->update_meta_data( self::CUSTOM, isset( $_POST[ self::CUSTOM ] ) ? 'yes' : 'no' );
		foreach ( array( self::MIN, self::MAX ) as $key ) {
			$cents = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? Money::to_cents( wp_unslash( (string) $_POST[ $key ] ) ) : null;
			$product->update_meta_data( $key, null === $cents ? '' : Money::plain( $cents ) );
		}
		// phpcs:enable
	}

	/* ============================================================== */
	/*  Buying                                                         */
	/* ============================================================== */

	/**
	 * The fields above Add to cart.
	 */
	public function purchase_fields() {
		global $product;
		if ( ! is_object( $product ) || ! self::is_gift( $product ) ) {
			return;
		}
		$limits = self::custom_limits( (int) $product->get_id(), Gift_Cards::service()->settings );
		$today  = Gift_Cards::service()->today();
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- refilling the form after a refused add to cart.
		include Gift_Cards::path() . 'views/product-fields.php';
	}

	/**
	 * Add to cart: check the fields.
	 *
	 * @param bool $passed       So far.
	 * @param int  $product_id   Product id.
	 * @param int  $qty          Quantity.
	 * @param int  $variation_id Variation id.
	 * @return bool
	 */
	public function validate_add( $passed, $product_id, $qty = 1, $variation_id = 0 ) {
		if ( ! $passed || ! self::is_gift( (int) $product_id ) ) {
			return $passed;
		}
		$read = self::read_purchase( wp_unslash( $_POST ), self::price_of( $product_id, $variation_id ), self::custom_limits( (int) $product_id, Gift_Cards::service()->settings ), Gift_Cards::service()->today() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- add to cart is a public action without a nonce in WooCommerce.
		foreach ( $read['errors'] as $error ) {
			wc_add_notice( $error, 'error' );
		}
		return $read['ok'];
	}

	/**
	 * Keep the fields on the cart line (and never merge two cards' lines).
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product id.
	 * @param int   $variation_id Variation id.
	 * @return array
	 */
	public function cart_item_data( $data, $product_id, $variation_id = 0 ) {
		if ( ! self::is_gift( (int) $product_id ) ) {
			return $data;
		}
		$read = self::read_purchase( wp_unslash( $_POST ), self::price_of( $product_id, $variation_id ), self::custom_limits( (int) $product_id, Gift_Cards::service()->settings ), Gift_Cards::service()->today() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see validate_add().
		if ( $read['ok'] ) {
			$data                    = is_array( $data ) ? $data : array();
			$data[ self::CART_KEY ]  = $read['data'];
			$data['fh_gcard_unique'] = md5( wp_json_encode( $read['data'] ) . microtime() );
		}
		return $data;
	}

	/**
	 * A chosen amount is the line's price.
	 *
	 * @param object $cart WC_Cart.
	 */
	public function set_prices( $cart ) {
		if ( ! is_object( $cart ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ]['custom'] ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( (int) $item[ self::CART_KEY ]['amount_cents'] / 100 );
			}
		}
	}

	/**
	 * The cart line shows who it is for, when it goes and the message.
	 *
	 * @param array $rows Rows.
	 * @param array $item Cart item.
	 * @return array
	 */
	public function item_data( $rows, $item ) {
		if ( empty( $item[ self::CART_KEY ] ) || ! is_array( $item[ self::CART_KEY ] ) ) {
			return $rows;
		}
		foreach ( self::shown( $item[ self::CART_KEY ] ) as $label => $value ) {
			$rows[] = array(
				'key'   => $label,
				'value' => $value,
			);
		}
		return $rows;
	}

	/**
	 * A line's details in words (pure).
	 *
	 * @param array $data read_purchase()['data'].
	 * @return array<string, string>
	 */
	public static function shown( array $data ) {
		$out = array();
		$to  = trim( $data['recipient_name'] . ( '' !== $data['recipient_email'] ? ' <' . $data['recipient_email'] . '>' : '' ) );
		$out[ __( 'Gift card for', 'fishotel-misc-plugin' ) ] = '' !== $to ? $to : __( 'you (sent to your e-mail address)', 'fishotel-misc-plugin' );
		if ( '' !== $data['sender_name'] ) {
			$out[ __( 'From', 'fishotel-misc-plugin' ) ] = $data['sender_name'];
		}
		$out[ __( 'Sent', 'fishotel-misc-plugin' ) ] = '' !== $data['deliver_on'] ? $data['deliver_on'] : __( 'when the order is paid', 'fishotel-misc-plugin' );
		if ( '' !== $data['message'] ) {
			$out[ __( 'Message', 'fishotel-misc-plugin' ) ] = $data['message'];
		}
		return $out;
	}

	/**
	 * Keep the details on the order line.
	 *
	 * @param object $item   WC_Order_Item_Product.
	 * @param string $key    Cart item key.
	 * @param array  $values Cart item.
	 * @param object $order  WC_Order.
	 */
	public function line_item( $item, $key, $values, $order ) {
		$data = isset( $values[ self::CART_KEY ] ) && is_array( $values[ self::CART_KEY ] ) ? $values[ self::CART_KEY ] : null;
		if ( null === $data && isset( $values['data'] ) && self::is_gift( $values['data'] ) ) {
			$data = self::read_purchase( array(), Money::from_wc( $values['data']->get_price() ), null, '1970-01-01' )['data'];
		}
		if ( null === $data || ! is_object( $item ) ) {
			return;
		}
		$item->add_meta_data( self::ITEM_AMOUNT, (int) $data['amount_cents'], true );
		$item->add_meta_data( self::ITEM_DATA, $data, true );
		foreach ( self::shown( $data ) as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}
	}

	/**
	 * Hide the section's own line meta on order screens.
	 *
	 * @param string[] $keys Hidden keys.
	 * @return string[]
	 */
	public function hidden_meta( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::ITEM_AMOUNT;
		$keys[] = self::ITEM_DATA;
		return $keys;
	}

	/**
	 * Shop pages: a gift card's button opens the product (its fields are there).
	 *
	 * @param string $url     URL.
	 * @param object $product WC_Product.
	 * @return string
	 */
	public function loop_url( $url, $product ) {
		return is_object( $product ) && self::is_gift( $product ) && ! ( function_exists( 'is_product' ) && is_product() ) ? (string) get_permalink( $product->get_id() ) : $url;
	}

	/**
	 * No one-click "Add to cart" on shop pages for a gift card.
	 *
	 * @param bool   $supports Supports.
	 * @param string $feature  Feature.
	 * @param object $product  WC_Product.
	 * @return bool
	 */
	public function no_ajax_add( $supports, $feature, $product ) {
		return 'ajax_add_to_cart' === $feature && self::is_gift( $product ) ? false : $supports;
	}

	/* ============================================================== */
	/*  Issuing                                                        */
	/* ============================================================== */

	/**
	 * The gift card lines of an order (pure apart from the order).
	 *
	 * @param object $order WC_Order.
	 * @return array[]
	 */
	public static function lines( $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$cents = (int) $item->get_meta( self::ITEM_AMOUNT );
			$data  = $item->get_meta( self::ITEM_DATA );
			if ( $cents <= 0 ) {
				continue;
			}
			$data    = is_array( $data ) ? $data : array();
			$lines[] = array(
				'item_id'         => (int) $item_id,
				'qty'             => (int) $item->get_quantity(),
				'amount_cents'    => $cents,
				'product_id'      => (int) $item->get_product_id(),
				'recipient_name'  => (string) ( $data['recipient_name'] ?? '' ),
				'recipient_email' => (string) ( $data['recipient_email'] ?? '' ),
				'sender_name'     => '' !== (string) ( $data['sender_name'] ?? '' ) ? (string) $data['sender_name'] : trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'sender_email'    => (string) $order->get_billing_email(),
				'message'         => (string) ( $data['message'] ?? '' ),
				'deliver_on'      => (string) ( $data['deliver_on'] ?? '' ),
			);
		}
		return $lines;
	}

	/**
	 * The order is paid: issue its cards (each exactly once).
	 *
	 * @param int $order_id Order id.
	 */
	public function issue( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || ! $order->is_paid() ) {
			return;
		}
		$lines = self::lines( $order );
		if ( ! $lines ) {
			return;
		}
		$out = Gift_Cards::service()->issue_for_order( (int) $order->get_id(), $lines, (int) $order->get_customer_id(), Audit::context( 'order', 'order paid' ) );
		if ( $out['issued'] > 0 ) {
			$codes = array();
			foreach ( $out['card_ids'] as $id ) {
				$card    = Gift_Cards::service()->store->card( $id );
				$codes[] = $card ? Codes::mask( $card['code'] ) : '#' . $id;
			}
			/* translators: %s: masked codes */
			$order->add_order_note( sprintf( _n( 'Gift card issued: %s.', 'Gift cards issued: %s.', $out['issued'], 'fishotel-misc-plugin' ), implode( ', ', $codes ) ) );
		}
		if ( $out['failed'] > 0 ) {
			/* translators: %d: cards */
			$order->add_order_note( sprintf( _n( '%d gift card could not be issued — see FisHotel Tools → Health.', '%d gift cards could not be issued — see FisHotel Tools → Health.', $out['failed'], 'fishotel-misc-plugin' ), $out['failed'] ) );
		}
	}

	/**
	 * An order that issued cards is cancelled or refunded: a note naming
	 * them. Nothing is switched off by itself — the card may already have
	 * been given away or spent.
	 *
	 * @param int $order_id Order id.
	 */
	public function undone_note( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			return;
		}
		$codes = array();
		foreach ( Gift_Cards::service()->store->cards_of_order( (int) $order_id ) as $card ) {
			if ( 'active' === $card['status'] ) {
				$codes[] = Codes::mask( $card['code'] ) . ' (' . Money::words( (int) $card['balance_cents'] ) . ' left)';
			}
		}
		if ( $codes ) {
			/* translators: %s: masked codes */
			$order->add_order_note( sprintf( __( 'This order issued gift cards that still work: %s. If their money was refunded, disable them on FisHotel Tools → Gift Cards.', 'fishotel-misc-plugin' ), implode( ', ', $codes ) ) );
		}
	}

	/**
	 * The order screen: which card a line issued (masked, with a link).
	 *
	 * @param int    $item_id Item id.
	 * @param object $item    Item.
	 * @param object $product Product.
	 */
	public function issued_note( $item_id, $item, $product ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || (int) $item->get_meta( self::ITEM_AMOUNT ) <= 0 ) {
			return;
		}
		$store = Gift_Cards::service()->store;
		$qty   = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
		$links = array();
		for ( $unit = 1; $unit <= $qty; $unit++ ) {
			$card = $store->card_for_item( (int) $item_id, $unit );
			if ( $card ) {
				$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Gift_Cards::MENU_SLUG . '&card=' . (int) $card['id'] ) ) . '">' . esc_html( Codes::mask( $card['code'] ) ) . '</a>';
			}
		}
		echo '<p class="fh-gcard-issued">' . esc_html__( 'Gift card:', 'fishotel-misc-plugin' ) . ' ' . ( $links ? wp_kses_post( implode( ', ', $links ) ) : esc_html__( 'issued when the order is paid', 'fishotel-misc-plugin' ) ) . '</p>';
	}
}
