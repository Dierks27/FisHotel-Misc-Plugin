<?php
/**
 * The order edit screens (legacy and HPOS): on an order folded into a
 * combined box, the boxes that only point to the combined box — Packing,
 * Weather, EA Packing Slip, Combine Orders — are drawn closed (their
 * title still shows, and each says "see #…"), so the first screen is
 * Order data, Items and Shipments. Every other order: as the person's
 * layout says.
 *
 * ShipTracker's "USPS Letter Tracking" box (`fst-letter`, ShipTracker
 * 1.10.0) sits right under Order actions. It is open on an order with a
 * sticker letter — the Sticker giveaway's flag
 * `_fishotel_sticker_mail_letter`, or a letter ShipTracker already made —
 * and closed (its title only) on every other order. A person tidied
 * before the box was named in the layout gets it placed there as the
 * screen is drawn (Layouts::with_letter(); nothing is stored).
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
	 * Whether the order on this screen has a sticker letter — read once
	 * per request.
	 *
	 * @var bool|null
	 */
	private $letter = null;

	/**
	 * Who was switched to the FisHotel layout (Switcher), made when first
	 * needed.
	 *
	 * @var Switcher|null
	 */
	private $switcher;

	/**
	 * @param Switcher|null $switcher The switch (tests hand a stand-in store).
	 */
	public function __construct( $switcher = null ) {
		$this->switcher = $switcher;
	}

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		foreach ( array( Layouts::ORDER, Layouts::ORDER_HPOS ) as $screen ) {
			add_filter( 'postbox_classes_' . $screen . '_' . Layouts::LETTER, array( $this, 'letter_classes' ) );
			add_filter( 'get_user_option_' . Layouts::PARTS['order'] . $screen, array( $this, 'stored_order' ), 10, 3 );
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
	 * The classes of the letter box (pure): open on an order with a
	 * sticker letter, closed on every other.
	 *
	 * @param mixed $classes    Its classes.
	 * @param bool  $has_letter Whether the order has a sticker letter.
	 * @return array
	 */
	public static function letter_classes_for( $classes, $has_letter ) {
		$classes = is_array( $classes ) ? $classes : array();
		if ( $has_letter ) {
			return array_values(
				array_filter(
					$classes,
					static function ( $class ) {
						return 'closed' !== $class;
					}
				)
			);
		}
		return self::classes_for( $classes, true );
	}

	/**
	 * postbox_classes_*_fst-letter callback.
	 *
	 * @param mixed $classes The box's classes.
	 * @return array
	 */
	public function letter_classes( $classes ) {
		if ( null === $this->letter ) {
			$this->letter = self::has_letter( self::current_order_id() );
		}
		return self::letter_classes_for( $classes, $this->letter );
	}

	/**
	 * Whether an order has a sticker letter: the Sticker giveaway's flag,
	 * or a letter in ShipTracker.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function has_letter( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		try {
			return \FisHotel\Misc\ShipTracker_Letters::order_has_letter( wc_get_order( $order_id ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether a person's entry says the FisHotel layout is theirs now
	 * (pure): switched, not put back, not given back while off.
	 *
	 * @param mixed $entry The screen's entry of the state.
	 * @return bool
	 */
	public static function is_tidied( $entry ) {
		return is_array( $entry ) && isset( $entry['v'] ) && empty( $entry['undone'] ) && empty( $entry['off'] );
	}

	/**
	 * get_user_option_meta-box-order_<screen>: the letter box placed in a
	 * tidied person's layout that does not name it yet. Read only.
	 *
	 * @param mixed  $sorted The stored order of the boxes.
	 * @param string $option The option's name.
	 * @param mixed  $user   The person (WP_User).
	 * @return mixed
	 */
	public function stored_order( $sorted, $option = '', $user = null ) {
		$screen = substr( (string) $option, strlen( Layouts::PARTS['order'] ) );
		if ( ! Layouts::is_order( $screen ) ) {
			return $sorted;
		}
		$uid = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : (int) get_current_user_id();
		if ( null === $this->switcher ) {
			$this->switcher = new Switcher( new User_Store() );
		}
		return self::is_tidied( $this->switcher->entry( $uid, $screen ) ) ? Layouts::with_letter( $sorted ) : $sorted;
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
