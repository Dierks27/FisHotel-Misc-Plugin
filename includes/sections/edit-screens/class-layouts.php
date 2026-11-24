<?php
/**
 * The FisHotel layouts of the product and order edit screens (pure).
 *
 * "Things that should be filled out up towards the top, the optional
 * things a bit lower and the non essentials at the bottom." Each layout is
 * what WordPress itself stores for a person who drags boxes around — the
 * order of the boxes per column (`meta-box-order_<screen>`), the boxes
 * shown closed (`closedpostboxes_<screen>`) and the boxes hidden in Screen
 * Options (`metaboxhidden_<screen>`) — written once per person and screen
 * by Switcher. Boxes are named by id only: the plugin or theme that adds a
 * box is never touched. An id the site does not have is skipped by
 * WordPress (Yoast's box, a theme box of a later version), and a box this
 * list does not name lands where its owner put it.
 *
 * On the product screen the first two boxes of the main column — Fish
 * essentials and WooCommerce's product data box — are shown under the
 * title, above the description: the script moves them there as the page
 * is drawn (Product_Screen). What is stored is an ordinary layout, so a
 * site without this section (or without the plugin) shows them as the
 * first boxes under the description, never lost. The order screens are the legacy one (`shop_order`) and WooCommerce's own
 * (`woocommerce_page_wc-orders`, HPOS): the same layout.
 *
 * VERSION goes up when a layout changes: everybody is switched once more,
 * with the same notice and undo.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Layouts
 */
class Layouts {

	/**
	 * The layout version: up it when a layout below changes.
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * The screens, as WordPress names them.
	 */
	const PRODUCT    = 'product';
	const ORDER      = 'shop_order';
	const ORDER_HPOS = 'woocommerce_page_wc-orders';

	/**
	 * The boxes shown under the title, above the description, in this
	 * order (Product_Screen moves them there).
	 *
	 * @var string[]
	 */
	const TOP = array( 'fishotel-essentials', 'woocommerce-product-data' );

	/**
	 * The box this section adds under the title.
	 *
	 * @var string
	 */
	const ESSENTIALS = 'fishotel-essentials';

	/**
	 * The theme's box of staff notes once it is split out of the fish box
	 * (theme 1.28): the ids it may carry. Unknown ids are skipped.
	 *
	 * @var string[]
	 */
	const STAFF_NOTES = array( 'fishotel_staff_notes', 'fishotel-staff-notes' );

	/**
	 * The user meta WordPress keeps a layout in, by part.
	 *
	 * @var array<string, string>
	 */
	const PARTS = array(
		'order'  => 'meta-box-order_',
		'closed' => 'closedpostboxes_',
		'hidden' => 'metaboxhidden_',
	);

	/**
	 * The screens this section lays out.
	 *
	 * @return string[]
	 */
	public static function screens() {
		return array( self::PRODUCT, self::ORDER, self::ORDER_HPOS );
	}

	/**
	 * Whether a screen is one of them.
	 *
	 * @param mixed $screen Screen id.
	 * @return bool
	 */
	public static function is_screen( $screen ) {
		return is_string( $screen ) && in_array( $screen, self::screens(), true );
	}

	/**
	 * Whether a screen is an order screen.
	 *
	 * @param mixed $screen Screen id.
	 * @return bool
	 */
	public static function is_order( $screen ) {
		return self::ORDER === $screen || self::ORDER_HPOS === $screen;
	}

	/**
	 * The product screen, by band.
	 *
	 * Band 1 — fill these first (open): Fish essentials and "Price, stock &
	 * sizes" (WooCommerce's Product data), first in the main column —
	 * shown under the title; Publish,
	 * categories (they decide what kind of product it is), the photo.
	 * Band 2 — should fill (open): the description (fixed by WordPress
	 * right after the top area), the fish facts, the short description
	 * (shown for what is not a live fish), gallery, tags, compatibility
	 * guide, Coming Soon, Clean room.
	 * Band 3 — rare (closed): staff notes, Yoast, reviews; Custom Fields,
	 * Slug and Brands hidden (Screen Options brings them back).
	 *
	 * @return array{order: array<string, string>, closed: string[], hidden: string[]}
	 */
	public static function product() {
		return array(
			'order'  => array(
				'side'     => implode(
					',',
					array(
						'submitdiv',
						'product_catdiv',
						'postimagediv',
						'woocommerce-product-images',
						'tagsdiv-product_tag',
						'fishotel-compat-category',
						'fishotel_coming_soon',
						'fishotel_cleanroom',
						'product_branddiv',
					)
				),
				'normal'   => implode(
					',',
					array_merge(
						self::TOP,
						array( 'fishotel_hotel_data', 'postexcerpt' ),
						self::STAFF_NOTES,
						array( 'wpseo_meta', 'commentsdiv', 'postcustom', 'slugdiv' )
					)
				),
				'advanced' => '',
			),
			'closed' => array_merge( self::STAFF_NOTES, array( 'wpseo_meta', 'commentsdiv' ) ),
			'hidden' => array( 'postcustom', 'slugdiv', 'product_branddiv' ),
		);
	}

	/**
	 * An order screen, by when in an order's life a box is used.
	 *
	 * Main: the warnings (only when there is one), Order data, Items,
	 * Packing, Shipments, then Custom Fields (closed) and WooCommerce's
	 * downloads (hidden by WooCommerce itself on these orders). Side: Order
	 * actions; ShipTracker's Pickup (pickup orders) and the combined box's
	 * Fulfillment; Weather; the EA slip and the medication terms; order
	 * notes; Combine Orders; then — closed — Date Change Override, Order
	 * attribution, Customer history.
	 *
	 * @return array{order: array<string, string>, closed: string[], hidden: string[]}
	 */
	public static function order() {
		return array(
			'order'  => array(
				'normal'   => implode(
					',',
					array(
						'fishotel-shipping-flag',
						'fishotel-combine-skipped',
						'woocommerce-order-data',
						'woocommerce-order-items',
						'fishotel-packing',
						'fishotel-shipments',
						'postcustom',
						'order_custom',
						'woocommerce-order-downloads',
					)
				),
				'side'     => implode(
					',',
					array(
						'woocommerce-order-actions',
						'fst-pickup',
						'fishotel-fulfillment-delete',
						'fishotel-weather',
						'fishotel-med-packing-slip',
						'fishotel-med-terms',
						'woocommerce-order-notes',
						'fishotel-combine-orders',
						'fst-shipment-tracking',
						'fishotel-order-fulfillment',
						'fishotel-date-change-unlock',
						'woocommerce-order-source-data',
						'woocommerce-customer-history',
					)
				),
				'advanced' => '',
			),
			'closed' => array( 'postcustom', 'order_custom', 'fishotel-date-change-unlock', 'woocommerce-order-source-data', 'woocommerce-customer-history' ),
			'hidden' => array(),
		);
	}

	/**
	 * The layout of a screen, or null for a screen this section does not
	 * lay out.
	 *
	 * @param mixed $screen Screen id.
	 * @return array|null
	 */
	public static function for_screen( $screen ) {
		if ( self::PRODUCT === $screen ) {
			return self::product();
		}
		return self::is_order( $screen ) ? self::order() : null;
	}

	/**
	 * The boxes of an order screen that are closed on an order folded into
	 * a combined box: each only points to the combined box there.
	 *
	 * @return string[]
	 */
	public static function folded_closed() {
		return array( 'fishotel-packing', 'fishotel-weather', 'fishotel-med-packing-slip', 'fishotel-combine-orders' );
	}
}
