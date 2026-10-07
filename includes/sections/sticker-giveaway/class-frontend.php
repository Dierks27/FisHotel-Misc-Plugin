<?php
/**
 * Sticker Giveaway frontend — renders the picker card inside the
 * checkout form and loads the small CSS/JS that goes with it.
 *
 * WHY `woocommerce_checkout_after_customer_details`: it fires inside
 * <form name="checkout"> in both WooCommerce's stock template and the
 * FisHotel theme's form-checkout.php (left column, after billing,
 * shipping and order notes). Anything inside that form is serialised
 * into every update_order_review request, which is how the choice
 * reaches the cart without a custom endpoint.
 *
 * The cart page is different: nothing there posts our fields, so when
 * `show_on_cart` is on the JS posts the selection to a tiny AJAX action
 * and then asks WC to refresh the cart.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Nonce action for the cart-page AJAX sync.
	 *
	 * @var string
	 */
	const AJAX_NONCE = 'fishotel_sticker_front';

	/**
	 * Guards against rendering twice on the cart page when a template
	 * fires both hooks we listen to.
	 *
	 * @var bool
	 */
	private $cart_rendered = false;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render_picker' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( Settings::get_value( 'show_on_cart' ) ) {
			// The theme's cart.php does not fire woocommerce_cart_collaterals;
			// WC's stock template fires both. Whichever comes first renders.
			add_action( 'woocommerce_cart_collaterals', array( $this, 'render_cart_picker' ), 5 );
			add_action( 'woocommerce_after_cart', array( $this, 'render_cart_picker' ), 5 );
			add_action( 'wp_ajax_fishotel_sticker_sync', array( $this, 'ajax_sync' ) );
			add_action( 'wp_ajax_nopriv_fishotel_sticker_sync', array( $this, 'ajax_sync' ) );
		}
	}

	/**
	 * Pool products that can actually be given away right now.
	 *
	 * @param array $settings Settings.
	 * @return \WC_Product[] Keyed by product ID, in pool order.
	 */
	public static function available_pool( array $settings ) {
		$products = array();

		foreach ( Rules::pool( $settings ) as $pid ) {
			$product = wc_get_product( $pid );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$products[ $pid ] = $product;
		}

		return $products;
	}

	/**
	 * Render the picker on the checkout page.
	 */
	public function render_picker() {
		$this->render( 'checkout' );
	}

	/**
	 * Render the picker on the cart page (once).
	 */
	public function render_cart_picker() {
		if ( $this->cart_rendered ) {
			return;
		}
		$this->cart_rendered = true;

		$this->render( 'cart' );
	}

	/**
	 * Build the view model and include the template.
	 *
	 * Renders nothing when the pool is empty or no pool product is
	 * purchasable, and nothing when the cart neither qualifies for a free
	 * sticker nor is offered add-ons.
	 *
	 * @param string $context 'checkout' or 'cart'.
	 */
	private function render( $context ) {
		$cart = Cart::cart();
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$settings = Settings::get();
		$products = self::available_pool( $settings );

		if ( empty( $products ) ) {
			return;
		}

		$eligible     = Cart::is_cart_eligible( $settings );
		$offer_upsell = Rules::offers_upsell( $eligible, $settings );

		if ( ! $eligible && ! $offer_upsell ) {
			return;
		}

		$current    = Cart::current_selection();
		$remembered = Cart::remembered_choice();
		$pool_ids   = array_keys( $products );

		// Which free radio is checked: the cart's line if it has one, else
		// the remembered choice (0 = "no thanks"), else the first sticker
		// so a customer who never looks at the card still gets one.
		if ( $current['free'] && isset( $products[ $current['free'] ] ) ) {
			$selected_free = $current['free'];
		} elseif ( null !== $remembered && ( 0 === $remembered || isset( $products[ $remembered ] ) ) ) {
			$selected_free = $remembered;
		} else {
			$selected_free = (int) $pool_ids[0];
		}

		$extra_price = Rules::price_for( Rules::KIND_EXTRA, $settings );
		$max_qty     = max( 1, (int) $settings['upsell_max_qty'] );
		$intro_upsel = str_replace( '{price}', Sticker_Giveaway::plain_price( $extra_price ), (string) $settings['intro_upsell'] );

		$view = array(
			'context'       => $context,
			'settings'      => $settings,
			'products'      => $products,
			'eligible'      => $eligible,
			'offer_upsell'  => $offer_upsell,
			'selected_free' => (int) $selected_free,
			'extras'        => $current['extras'],
			'extra_price'   => $extra_price,
			'max_qty'       => $max_qty,
			'intro_upsell'  => $intro_upsel,
		);

		include Sticker_Giveaway::path() . 'views/checkout-picker.php';
	}

	/**
	 * Enqueue the picker CSS/JS on checkout (and cart when enabled).
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}

		$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' );
		$on_cart     = is_cart() && Settings::get_value( 'show_on_cart' );

		if ( ! $on_checkout && ! $on_cart ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-sticker-front',
			Sticker_Giveaway::url() . 'css/sticker-front.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-sticker-front',
			Sticker_Giveaway::url() . 'js/sticker-front.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-sticker-front',
			'fhStickerFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
			)
		);
	}

	/**
	 * Cart-page AJAX — sync the selection, recalc, hand back to WC's
	 * cart.js (the JS triggers wc_update_cart on success).
	 */
	public function ajax_sync() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		$cart = Cart::cart();
		if ( ! $cart ) {
			wp_send_json_error( array( 'message' => __( 'Cart unavailable.', 'fishotel-misc-plugin' ) ), 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$free   = isset( $_POST[ Cart::FIELD_FREE ] ) ? (int) $_POST[ Cart::FIELD_FREE ] : 0;
		$extras = isset( $_POST[ Cart::FIELD_EXTRA ] ) && is_array( $_POST[ Cart::FIELD_EXTRA ] )
			? array_map( 'intval', wp_unslash( $_POST[ Cart::FIELD_EXTRA ] ) )
			: array();
		$touched = isset( $_POST[ Cart::FIELD_FREE ] );
		// phpcs:enable

		Cart::sync( $free, $extras, ! $touched );
		$cart->calculate_totals();

		wp_send_json_success( Cart::current_selection() );
	}
}
