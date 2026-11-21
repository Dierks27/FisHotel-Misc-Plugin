<?php
/**
 * Sticker Giveaway pile products — what a giveaway line shows WooCommerce
 * in place of the plain product, when its design keeps a giveaway pile.
 *
 * WHY: such a line is counted against the giveaway pile, which this
 * section keeps, and must be left alone by the shop pile, which is the
 * product's own WooCommerce stock. WooCommerce asks the product object on
 * the line three things before it lets a line through or reduces stock:
 * are you in stock, do you manage stock, and whose stock are you. A
 * giveaway line answers "yes", "no" and "nobody's":
 *
 *   is_in_stock()              true  — the shop pile at 0 does not refuse it
 *   managing_stock()           false — WooCommerce neither checks it
 *                                      against the shop pile, nor holds
 *                                      stock for it, nor reduces or
 *                                      restores the shop pile for it
 *   get_stock_managed_by_id()  0     — WooCommerce adds up cart quantities
 *                                      per stock owner; under 0 a giveaway
 *                                      line is never added to the sum a
 *                                      normal purchase of the same variation
 *                                      is checked against
 *
 * Nothing is written to the product: these are objects built for one cart
 * line or one order line, in memory, for the length of the request. A
 * normal purchase of the same product gets WooCommerce's own object and
 * is limited by the shop pile exactly as before.
 *
 * Loaded on first use (Designs::pile_product()), because the classes extend
 * WooCommerce's and must not be declared before WooCommerce is.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * The three answers, shared by both product kinds.
 */
trait Pile_Line {

	/**
	 * A giveaway line is in stock while its giveaway pile says so, and
	 * that was decided before the line was put in the cart.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		return true;
	}

	/**
	 * The shop pile is not this line's stock.
	 *
	 * @return bool
	 */
	public function managing_stock() {
		return false;
	}

	/**
	 * Never short of shop stock: it takes none.
	 *
	 * @param mixed $quantity Quantity asked for.
	 * @return bool
	 */
	public function has_enough_stock( $quantity ) {
		unset( $quantity );
		return true;
	}

	/**
	 * Never on backorder.
	 *
	 * @param int $qty_in_cart Quantity in the cart.
	 * @return bool
	 */
	public function is_on_backorder( $qty_in_cart = 0 ) {
		unset( $qty_in_cart );
		return false;
	}

	/**
	 * Nobody's stock (Piles::STOCK_BUCKET).
	 *
	 * @return int
	 */
	public function get_stock_managed_by_id() {
		return Piles::STOCK_BUCKET;
	}

	/**
	 * Marks the object for Designs::is_pile_product().
	 *
	 * @return bool
	 */
	public function is_giveaway_pile_line() {
		return true;
	}
}

/**
 * A simple-product design on a giveaway line.
 */
class Pile_Simple extends \WC_Product_Simple {
	use Pile_Line;
}

/**
 * A variation design on a giveaway line.
 */
class Pile_Variation extends \WC_Product_Variation {
	use Pile_Line;
}
