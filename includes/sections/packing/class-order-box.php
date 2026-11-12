<?php
/**
 * The Packing box on the order screen — the legacy (post) screen and the
 * High-Performance Order Storage screen alike: each fish line with its
 * bag, the bags needed by size, the suggested box with its fill, the "on
 * the line" flag, and the Confirm / change control that stores the chosen
 * box on the order.
 *
 * The body is drawn by one view on the server; the AJAX reply ships that
 * same HTML again, so the script never builds markup from data. The one
 * write (confirm / change / clear) is Order_Packing::confirm_request():
 * manage_woocommerce + a nonce, order meta through the order object.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Box
 */
class Order_Box {

	/**
	 * Meta box id.
	 *
	 * @var string
	 */
	const BOX_ID = 'fishotel-packing';

	/**
	 * Register hooks.
	 */
	public function init() {
		// The generic hook carries the screen: 'shop_order' on the legacy
		// screen, WooCommerce's own page id on the HPOS one.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 30, 2 );
		add_action( 'wp_ajax_fishotel_packing_confirm', array( $this, 'ajax_confirm' ) );
	}

	/**
	 * The ids of the two order edit screens.
	 *
	 * @return string[]
	 */
	public static function screens() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = (string) wc_get_page_screen_id( 'shop-order' );
			if ( '' !== $hpos && ! in_array( $hpos, $screens, true ) ) {
				$screens[] = $hpos;
			}
		}
		return $screens;
	}

	/**
	 * Add the Packing box when the screen is an order's.
	 *
	 * @param string $screen_id     Post type (legacy) or screen id (HPOS).
	 * @param mixed  $post_or_order WP_Post or WC_Order (unused).
	 */
	public function register_meta_box( $screen_id = '', $post_or_order = null ) {
		unset( $post_or_order );

		if ( ! is_string( $screen_id ) || ! in_array( $screen_id, self::screens(), true ) ) {
			return;
		}
		if ( ! current_user_can( Packing::CAPABILITY ) ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'Packing', 'fishotel-misc-plugin' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'normal',
			'high' // Under the order's items, above the shipment: fish, bag, box, then the label.
		);

		Admin::enqueue_assets();
	}

	/**
	 * Meta box callback: a WP_Post on the legacy screen, a WC_Order on the
	 * HPOS one.
	 *
	 * @param mixed $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = null;
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_items' ) ) {
			$order = $post_or_order;
		} elseif ( is_object( $post_or_order ) && isset( $post_or_order->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $post_or_order->ID );
		}

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			echo '<p>' . esc_html__( 'Order not loaded yet.', 'fishotel-misc-plugin' ) . '</p>';
			return;
		}

		echo self::render_body( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the view escapes every value.
	}

	/**
	 * The box body (also the AJAX reply).
	 *
	 * @param object $order   WC_Order.
	 * @param string $message A line to show at the top (the outcome of a save).
	 * @param bool   $error   Whether that line is an error.
	 * @return string HTML.
	 */
	public static function render_body( $order, $message = '', $error = false ) {
		$settings = Settings::get();
		$current  = Boxes::current( $settings );

		try {
			$result = Order_Packing::for_order( $order, $settings, null, $current );
		} catch ( \Throwable $e ) {
			$result = Packing::unavailable( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0, 'error' );
		}

		$needs = array();
		foreach ( $result['bags'] as $bag ) {
			$needs[ (int) $bag['id'] ] = (int) $bag['count'];
		}
		$candidates = Suggest::candidates( $needs, Boxes::with_fits( $current['boxes'], $settings['fits'] ), $settings['threshold'] );
		$nonce      = wp_create_nonce( Order_Packing::NONCE_ACTION );
		$page_url   = admin_url( 'admin.php?page=' . Packing::MENU_SLUG );

		// An order that went into a combined box links to it: the box is suggested there.
		$wrapper_url = '';
		if ( (int) $result['packed_with'] > 0 && function_exists( 'wc_get_order' ) ) {
			$wrapper = wc_get_order( (int) $result['packed_with'] );
			if ( is_object( $wrapper ) && method_exists( $wrapper, 'get_edit_order_url' ) ) {
				$wrapper_url = (string) $wrapper->get_edit_order_url();
			}
		}

		ob_start();
		include Packing::path() . 'views/order-box.php';
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: view / confirm / clear (Order_Packing::confirm_request()). The
	 * reply carries the box body drawn again.
	 */
	public function ajax_confirm() {
		$outcome = Order_Packing::confirm_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in confirm_request().

		$html = '';
		if ( isset( $outcome['order'] ) && is_object( $outcome['order'] ) ) {
			// Read the order again so the reply shows exactly what was stored.
			$order_id = method_exists( $outcome['order'], 'get_id' ) ? (int) $outcome['order']->get_id() : 0;
			$fresh    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			$html     = self::render_body( is_object( $fresh ) ? $fresh : $outcome['order'], (string) $outcome['message'], false );
		}

		if ( empty( $outcome['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $outcome['message'] ), (int) $outcome['status'] );
		}

		wp_send_json_success(
			array(
				'message' => (string) $outcome['message'],
				'html'    => $html,
			)
		);
	}
}
