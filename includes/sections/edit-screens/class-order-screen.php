<?php
/**
 * The order edit screens (legacy and HPOS): on an order folded into a
 * combined box, the boxes that only point to the combined box — Packing,
 * Weather, EA Packing Slip, Combine Orders — are drawn closed (their
 * title still shows, and each says "see #…"), so the first screen is
 * Order data, Items and Shipments. Every other order: as the person's
 * layout says.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Screen
 */
class Order_Screen {

	/**
	 * Whether the order on this screen is folded into a combined box —
	 * read once per request.
	 *
	 * @var bool|null
	 */
	private $folded = null;

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		foreach ( array( Layouts::ORDER, Layouts::ORDER_HPOS ) as $screen ) {
			add_filter( 'postbox_classes_' . $screen . '_fishotel-packing', array( $this, 'folded_classes' ) );
			add_filter( 'postbox_classes_' . $screen . '_fishotel-weather', array( $this, 'folded_classes' ) );
			add_filter( 'postbox_classes_' . $screen . '_fishotel-med-packing-slip', array( $this, 'folded_classes' ) );
			add_filter( 'postbox_classes_' . $screen . '_fishotel-combine-orders', array( $this, 'folded_classes' ) );
		}
	}

	/**
	 * The classes of a box closed on a folded order (pure).
	 *
	 * @param mixed $classes Its classes.
	 * @param bool  $folded  Whether the order is folded into a combined box.
	 * @return array
	 */
	public static function classes_for( $classes, $folded ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( $folded && ! in_array( 'closed', $classes, true ) ) {
			$classes[] = 'closed';
		}
		return $classes;
	}

	/**
	 * postbox_classes_* callback.
	 *
	 * @param mixed $classes The box's classes.
	 * @return array
	 */
	public function folded_classes( $classes ) {
		if ( null === $this->folded ) {
			$this->folded = self::is_folded( self::current_order_id() );
		}
		return self::classes_for( $classes, $this->folded );
	}

	/**
	 * The order of this screen: the post (legacy) or `id` (HPOS).
	 *
	 * @return int
	 */
	public static function current_order_id() {
		global $post;
		if ( is_object( $post ) && isset( $post->ID, $post->post_type ) && 'shop_order' === $post->post_type ) {
			return (int) $post->ID;
		}
		$id = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the screen's own order, read only.
		return max( 0, $id );
	}

	/**
	 * Whether an order is folded into a combined box: the theme's answer
	 * when it is there, else its order meta.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function is_folded( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! is_object( $order ) ) {
				return false;
			}
			if ( function_exists( 'fishotel_order_get_fulfillment' ) ) {
				return (int) fishotel_order_get_fulfillment( $order ) > 0;
			}
			return method_exists( $order, 'get_meta' ) && (int) $order->get_meta( '_fishotel_fulfilled_by_order', true ) > 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
