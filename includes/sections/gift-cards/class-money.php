<?php
/**
 * Gift card money — PURE. Every amount in this section is a whole number
 * of cents (an int), from the form to the database and back; a float is
 * never stored or added up. This class turns what somebody typed into
 * cents, cents into words ("$12.50"), and does the sums the money rules
 * need: which cards pay how much of an order (oldest card first), how
 * much of an order's gift card money is still not back on a card.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Money
 */
class Money {

	/**
	 * The largest amount a card may be issued for, or changed by in one
	 * step ($5,000.00).
	 *
	 * @var int
	 */
	const MAX_CENTS = 500000;

	/**
	 * Cents from what somebody typed: "25", "25.5", "$25.50", "1,025.00".
	 * Null when it is not an amount (letters, a minus sign, more than two
	 * decimals, more than MAX_CENTS).
	 *
	 * @param mixed $input The typed amount (an int is taken as dollars).
	 * @param int   $max   The largest allowed, in cents.
	 * @return int|null
	 */
	public static function to_cents( $input, $max = self::MAX_CENTS ) {
		if ( is_int( $input ) ) {
			$input = (string) $input;
		}
		if ( ! is_string( $input ) && ! is_float( $input ) ) {
			return null;
		}
		if ( is_float( $input ) ) {
			$input = number_format( $input, 2, '.', '' );
		}

		$text = trim( str_replace( array( '$', ',', ' ' ), '', (string) $input ) );
		if ( '' === $text || ! preg_match( '/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $m ) ) {
			return null;
		}

		$cents = (int) $m[1] * 100 + ( isset( $m[2] ) ? (int) str_pad( $m[2], 2, '0' ) : 0 );

		return $cents > (int) $max ? null : $cents;
	}

	/**
	 * Cents from a decimal the database or the old plugin holds
	 * ("12.3400" → 1234). Null when it carries a fraction of a cent or is
	 * not a number — the importer lists such a value instead of rounding.
	 *
	 * @param mixed $value Decimal as text or number.
	 * @return int|null
	 */
	public static function decimal_to_cents( $value ) {
		if ( is_int( $value ) ) {
			return $value * 100;
		}
		if ( is_float( $value ) ) {
			$value = rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
		}
		$text = trim( (string) $value );
		if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m ) ) {
			return null;
		}
		$fraction = isset( $m[3] ) ? rtrim( $m[3], '0' ) : '';
		if ( strlen( $fraction ) > 2 ) {
			return null;
		}
		$cents = (int) $m[2] * 100 + ( '' !== $fraction ? (int) str_pad( $fraction, 2, '0' ) : 0 );

		return '-' === $m[1] ? -$cents : $cents;
	}

	/**
	 * Cents as money words: 1250 → "$12.50", -500 → "−$5.00".
	 *
	 * @param int    $cents  Amount in cents.
	 * @param string $symbol Currency symbol.
	 * @return string
	 */
	public static function format( $cents, $symbol = '$' ) {
		$cents = (int) $cents;
		$sign  = $cents < 0 ? '−' : '';
		$abs   = abs( $cents );

		return $sign . $symbol . number_format( intdiv( $abs, 100 ) ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Cents as a plain decimal for a form field or WooCommerce: 1250 → "12.50".
	 *
	 * @param int $cents Amount in cents.
	 * @return string
	 */
	public static function plain( $cents ) {
		$cents = (int) $cents;
		$abs   = abs( $cents );

		return ( $cents < 0 ? '-' : '' ) . intdiv( $abs, 100 ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Cents from a WooCommerce money float (an order total, a fee): rounded
	 * to the cent, which is what WooCommerce stores.
	 *
	 * @param float|string $amount Amount in dollars.
	 * @return int
	 */
	public static function from_wc( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * How much of the wallet goes toward the order (pure):
	 *
	 *   $choice null  → all of it (up to the order)
	 *   $choice 0     → none
	 *   $choice n     → n, but never more than the wallet or the order
	 *
	 * @param int      $wallet Cents on the customer's cards.
	 * @param int      $cap    Cents the order can take (its total before the card).
	 * @param int|null $choice Cents the customer asked for (null = all).
	 * @return int
	 */
	public static function choose( $wallet, $cap, $choice ) {
		$most = max( 0, min( (int) $wallet, (int) $cap ) );
		if ( null === $choice ) {
			return $most;
		}
		return max( 0, min( (int) $choice, $most ) );
	}

	/**
	 * Which card pays how much (pure): the cards in the order given
	 * (oldest first), each up to its balance, until the amount is covered.
	 *
	 * @param array[] $cards  Each with id and balance_cents.
	 * @param int     $amount Cents to cover.
	 * @return array<int, int>|null Card id → cents, or null when the cards do not cover it.
	 */
	public static function allocate( array $cards, $amount ) {
		$left = (int) $amount;
		$out  = array();
		if ( $left <= 0 ) {
			return array();
		}
		foreach ( $cards as $card ) {
			$have = (int) ( $card['balance_cents'] ?? 0 );
			if ( $have <= 0 ) {
				continue;
			}
			$take                          = min( $have, $left );
			$out[ (int) $card['id'] ]      = $take;
			$left                         -= $take;
			if ( 0 === $left ) {
				return $out;
			}
		}
		return null;
	}

	/**
	 * An order's gift card money per card (pure), from its ledger rows:
	 * what was used, what came back, and what is still out (used − back).
	 * The cards come in the order they were first used.
	 *
	 * @param array[] $rows Ledger rows of one order (type, card_id, amount_cents, idempotency_key).
	 * @return array<int, array{used: int, restored: int, outstanding: int, uses: int}>
	 */
	public static function order_cards( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$card = (int) $row['card_id'];
			$type = (string) $row['type'];
			if ( 'used' !== $type && 'restored' !== $type ) {
				continue;
			}
			if ( ! isset( $out[ $card ] ) ) {
				$out[ $card ] = array(
					'used'        => 0,
					'restored'    => 0,
					'outstanding' => 0,
					'uses'        => 0,
				);
			}
			if ( 'used' === $type ) {
				$out[ $card ]['used'] += -(int) $row['amount_cents'];
				$out[ $card ]['uses']++;
			} else {
				$out[ $card ]['restored'] += (int) $row['amount_cents'];
			}
			$out[ $card ]['outstanding'] = $out[ $card ]['used'] - $out[ $card ]['restored'];
		}
		return $out;
	}

	/**
	 * The total still out on an order (pure).
	 *
	 * @param array<int, array> $cards order_cards().
	 * @return int
	 */
	public static function outstanding( array $cards ) {
		$sum = 0;
		foreach ( $cards as $card ) {
			$sum += max( 0, (int) $card['outstanding'] );
		}
		return $sum;
	}

	/**
	 * The currency symbol of the shop as plain text.
	 *
	 * @return string
	 */
	public static function symbol() {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}
		return '$';
	}

	/**
	 * Cents in the shop's currency (the symbol of the shop).
	 *
	 * @param int $cents Amount.
	 * @return string
	 */
	public static function words( $cents ) {
		return self::format( $cents, self::symbol() );
	}
}
