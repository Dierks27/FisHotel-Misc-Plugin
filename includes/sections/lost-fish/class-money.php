<?php
/**
 * Lost Fish money — PURE: every amount is an integer number of cents, and
 * the money for each of the four choices comes from one place.
 *
 * The rule, as the owner set it:
 *   - refund the lost fish  = what was paid for the fish MINUS the shipping
 *                             difference (what shipping costs more once the
 *                             fish is out of the order — the order dropped
 *                             under the reduced-shipping threshold);
 *   - take a credit         = the same amount (a credit does not keep the
 *                             cheaper shipping either);
 *   - try again             = nothing moves, the order's shipping stays as
 *                             it is;
 *   - cancel the whole order = everything paid on the order that was not
 *                             refunded already, less what earlier cases
 *                             of the order already gave back as credit.
 *
 * A refund is never negative: when the shipping difference is more than
 * the fish, the refund is zero and the rest is reported (shortfall) so the
 * screen can say so — nothing is ever collected from a customer here.
 *
 * A shipping difference that is not known (null) makes the refund and the
 * credit unknown (null) too: the case is then not ready to send.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Money
 */
class Money {

	/**
	 * The four choices, in the order the e-mail lists them.
	 *
	 * @var string[]
	 */
	const CHOICES = array( 'refund', 'credit', 'retry', 'cancel' );

	/**
	 * The most a hand-typed amount may be (cents): $100,000.
	 *
	 * @var int
	 */
	const MAX_CENTS = 10000000;

	/**
	 * An amount as cents.
	 *
	 * @param mixed $amount A number or a numeric string (dollars).
	 * @return int
	 */
	public static function cents( $amount ) {
		if ( ! is_numeric( $amount ) ) {
			return 0;
		}
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * What a person typed as an amount → cents, or null when it is not an
	 * amount from zero to MAX_CENTS ("$12.50", "12.50", "12", "1,250.50";
	 * an empty field, a negative number and words are not amounts).
	 *
	 * @param mixed $typed The field.
	 * @return int|null
	 */
	public static function typed_cents( $typed ) {
		if ( ! is_scalar( $typed ) || is_bool( $typed ) ) {
			return null;
		}
		$text = trim( (string) $typed );
		$text = ltrim( $text, '$' );
		$text = str_replace( array( ',', ' ' ), '', $text );
		if ( '' === $text || 1 !== preg_match( '/^\d{1,7}(\.\d{1,2})?$/', $text ) ) {
			return null;
		}
		$cents = (int) round( (float) $text * 100 );

		return $cents <= self::MAX_CENTS ? $cents : null;
	}

	/**
	 * Cents as a plain decimal string for a form field ("12.50").
	 *
	 * @param int|null $cents Cents.
	 * @return string '' for null.
	 */
	public static function decimal( $cents ) {
		if ( null === $cents ) {
			return '';
		}
		return number_format( (int) $cents / 100, 2, '.', '' );
	}

	/**
	 * Cents as money for a person ("$1,250.50"; a negative amount as
	 * "-$5.00").
	 *
	 * @param int|null $cents  Cents.
	 * @param string   $symbol Currency symbol.
	 * @return string '' for null.
	 */
	public static function format( $cents, $symbol = '$' ) {
		if ( null === $cents ) {
			return '';
		}
		$cents = (int) $cents;

		return ( $cents < 0 ? '-' : '' ) . $symbol . number_format( abs( $cents ) / 100, 2, '.', ',' );
	}

	/**
	 * What was paid for one fish of a line: (line total + line tax) ÷ the
	 * line's quantity, to the cent.
	 *
	 * @param mixed $line_total The line's total after discounts (dollars).
	 * @param mixed $line_tax   The line's tax (dollars).
	 * @param int   $quantity   The line's quantity.
	 * @return int Cents (0 when the quantity is not positive).
	 */
	public static function unit_paid( $line_total, $line_tax, $quantity ) {
		$quantity = (int) $quantity;
		if ( $quantity <= 0 ) {
			return 0;
		}
		return (int) round( ( self::cents( $line_total ) + self::cents( $line_tax ) ) / $quantity );
	}

	/**
	 * The shipping difference with its share of the shipping tax: the
	 * order's own shipping tax rate (tax ÷ charge) applied to the
	 * difference. An order charged no shipping, or no shipping tax, adds
	 * nothing.
	 *
	 * @param int $diff_ex     The difference before tax (cents).
	 * @param int $charged_ex  The shipping charged before tax (cents).
	 * @param int $charged_tax The shipping tax charged (cents).
	 * @return int Cents.
	 */
	public static function with_shipping_tax( $diff_ex, $charged_ex, $charged_tax ) {
		$diff_ex = max( 0, (int) $diff_ex );
		if ( $diff_ex <= 0 || (int) $charged_ex <= 0 || (int) $charged_tax <= 0 ) {
			return $diff_ex;
		}
		return $diff_ex + (int) round( $diff_ex * (int) $charged_tax / (int) $charged_ex );
	}

	/**
	 * The money for each choice.
	 *
	 * @param array $in unit_paid (cents, one fish, tax included), qty, paid_known (bool, default true),
	 *                  ship_diff (cents, or null = not known), order_total (cents),
	 *                  order_refunded (cents), order_credited (cents given back as store credit by earlier
	 *                  cases of the order), offer_cancel (bool, default true).
	 * @return array{fish_paid: int, ship_diff: int|null, refund: int|null, credit: int|null, retry: int,
	 *               cancel: int|null, settled: int, shortfall: int, offer_cancel: bool}
	 */
	public static function choices( array $in ) {
		$qty        = max( 1, (int) ( $in['qty'] ?? 1 ) );
		$fish_paid  = max( 0, (int) ( $in['unit_paid'] ?? 0 ) ) * $qty;
		$paid_known = ! array_key_exists( 'paid_known', $in ) || ! empty( $in['paid_known'] );
		$ship_diff  = array_key_exists( 'ship_diff', $in ) && null !== $in['ship_diff'] ? max( 0, (int) $in['ship_diff'] ) : null;
		$offer      = ! array_key_exists( 'offer_cancel', $in ) || ! empty( $in['offer_cancel'] );

		$refund    = null;
		$shortfall = 0;
		if ( $paid_known && null !== $ship_diff ) {
			$refund    = max( 0, $fish_paid - $ship_diff );
			$shortfall = max( 0, $ship_diff - $fish_paid );
		}

		$cancel  = null;
		$settled = max( 0, (int) ( $in['order_refunded'] ?? 0 ) ) + max( 0, (int) ( $in['order_credited'] ?? 0 ) );
		if ( $offer && isset( $in['order_total'] ) ) {
			$cancel = max( 0, (int) $in['order_total'] - $settled );
		}

		return array(
			'fish_paid'    => $fish_paid,
			'ship_diff'    => $ship_diff,
			'refund'       => $refund,
			'credit'       => $refund,
			'retry'        => 0,
			'cancel'       => $cancel,
			'settled'      => null !== $cancel ? $settled : 0,
			'shortfall'    => $shortfall,
			'offer_cancel' => $offer && null !== $cancel,
		);
	}

	/**
	 * The amount a choice moves (what the owner has to pay back or credit).
	 *
	 * @param string $choice  refund | credit | retry | cancel.
	 * @param array  $choices choices().
	 * @return int|null Null when the choice has no known amount.
	 */
	public static function amount_for( $choice, array $choices ) {
		$choice = (string) $choice;
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return null;
		}
		if ( 'cancel' === $choice && empty( $choices['offer_cancel'] ) ) {
			return null;
		}
		return isset( $choices[ $choice ] ) ? (int) $choices[ $choice ] : null;
	}
}
