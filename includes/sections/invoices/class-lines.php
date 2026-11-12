<?php
/**
 * Invoice lines — PURE (no WordPress, no database): what a line is, the
 * money, and the two things written onto the invoice product from the
 * lines: the description the customer reads and the JSON the theme reads.
 *
 * A line is one of four kinds:
 *
 *   fish      name, quantity, price PER FISH (may be blank), where it came
 *             from (source: tank / product / species / typed) with the ids
 *             of that place, a size, and the "take from shop stock" tick —
 *             only ever on a line picked from a shop product.
 *   credit    a reason and an amount, stored negative ("Credit: lost fish").
 *   discount  never typed: the difference "Charge a flat total" leaves
 *             between the lines and the stated price — negative is shown
 *             as "Discount", positive (the flat total is higher) as
 *             "Adjustment". with_flat_total() recomputes it every time, so
 *             the lines ALWAYS add up to the total.
 *   note      text only, no money.
 *
 * Money is added up in whole cents, so nothing drifts.
 *
 * The description is the list the hand-made invoices have always had —
 * one <li> per line, "Fish xN — $price" (price per fish), "Credit: reason
 * — -$82" — and reads back through the theme's grammar (Grammar) as the
 * same lines. The JSON is the theme's `_fh_invoice_lines`: a list of
 * {name, qty, price, kind} where kind is fish / credit / note (the theme
 * knows no other): a Discount goes out as a credit, an Adjustment as a
 * one-off fish line, because only a fish line adds money there. A line
 * carries product_id (the variation's id for a variation) ONLY when it is
 * ticked "take from shop stock": that is what makes WooCommerce reduce
 * that product's stock when the order is paid. Every other link stays in
 * this section's own table.
 *
 * js/invoices-admin.js mirrors the maths and the texts for the live
 * preview; tests/fixtures/invoice-maths.json is read by the PHP test and
 * the node test, so the page and the server cannot drift apart.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lines
 */
class Lines {

	const KINDS   = array( 'fish', 'credit', 'discount', 'note' );
	const SOURCES = array( 'tank', 'product', 'species', 'typed' );

	const MAX_QTY   = 999;
	const MAX_NAME  = 200;
	const MAX_SIZE  = 100;
	const MAX_LINES = 200;

	/**
	 * The largest amount a typed field takes.
	 *
	 * @var float
	 */
	const MAX_MONEY = 999999.99;

	/**
	 * The largest amount a column holds (decimal(12,2)), in whole cents:
	 * $9,999,999,999.99. A total or a Discount line beyond it could not be
	 * stored as it is charged, so validation refuses it.
	 *
	 * @var int
	 */
	const MAX_TOTAL_CENTS = 999999999999;

	const DISCOUNT_NAME   = 'Discount';
	const ADJUSTMENT_NAME = 'Adjustment';
	const CREDIT_PREFIX   = 'Credit';

	/**
	 * The em dash between a line and its price, as the hand-made invoices
	 * write it.
	 *
	 * @var string
	 */
	const DASH = "\u{2014}";

	/* ============================================================== */
	/*  Money                                                          */
	/* ============================================================== */

	/**
	 * A typed amount as a number rounded to the cent: "82", "$82",
	 * "1,250.50", "-$82", "$-82", "−82". Null for an empty field and for
	 * anything that is not an amount (so a typo never becomes 0).
	 *
	 * Typed text is read digit by digit — the third decimal rounds half
	 * up — and never through a float, so the page (which does the same)
	 * and the server always agree to the cent.
	 *
	 * @param mixed $value What was typed (or a number).
	 * @return float|null
	 */
	public static function money( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( ! is_finite( (float) $value ) || abs( (float) $value ) > self::MAX_MONEY ) {
				return null;
			}
			return self::from_cents( self::cents( $value ) );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\s*(-|\x{2212})?\s*\$?\s*(-|\x{2212})?\s*(\d[\d,]*(?:\.\d+)?|\.\d+)\s*$/u', $value, $m ) ) {
			return null;
		}

		$parts = explode( '.', str_replace( ',', '', $m[3] ), 2 );
		$whole = ltrim( $parts[0], '0' );
		$frac  = isset( $parts[1] ) ? $parts[1] : '';
		if ( strlen( $whole ) > 6 ) {
			return null; // Beyond MAX_MONEY.
		}

		$cents = (int) $whole * 100 + (int) substr( str_pad( $frac, 2, '0' ), 0, 2 );
		if ( strlen( $frac ) > 2 && (int) $frac[2] >= 5 ) {
			$cents++;
		}
		if ( '' !== $m[1] || '' !== $m[2] ) {
			$cents = -1 * $cents;
		}

		return self::from_cents( $cents );
	}

	/**
	 * An amount in whole cents.
	 *
	 * @param mixed $amount Amount (null = 0).
	 * @return int
	 */
	public static function cents( $amount ) {
		return null === $amount ? 0 : (int) round( (float) $amount * 100 );
	}

	/**
	 * Whole cents as an amount (never -0.0).
	 *
	 * @param int $cents Cents.
	 * @return float
	 */
	public static function from_cents( $cents ) {
		$cents = (int) $cents;
		return 0 === $cents ? 0.0 : (float) ( $cents / 100 );
	}

	/**
	 * An amount as the invoices print it, without the sign and without the
	 * dollar: "82", "82.50", "1,250".
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function money_text( $amount ) {
		$cents = abs( self::cents( $amount ) );
		return number_format( $cents / 100, 0 === $cents % 100 ? 0 : 2, '.', ',' );
	}

	/**
	 * An amount with its dollar and, when negative, a leading minus:
	 * "$82", "-$82.50".
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function dollars( $amount ) {
		return ( self::cents( $amount ) < 0 ? '-' : '' ) . '$' . self::money_text( $amount );
	}

	/**
	 * An amount as a WooCommerce price string: "599.00".
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function price_string( $amount ) {
		return number_format( self::cents( $amount ) / 100, 2, '.', '' );
	}

	/* ============================================================== */
	/*  Normalising                                                    */
	/* ============================================================== */

	/**
	 * Text without its HTML tags — real tags only. strip_tags() also eats
	 * everything after a bare "<" (a size written `<2"`), which silently
	 * cut a name or dropped a whole line and its money; the page strips
	 * the same way, so the preview and the server agree. Whatever is left
	 * is escaped wherever it is printed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function untag( $text ) {
		return (string) preg_replace( '/<\/?[a-zA-Z!][^>]*>/', '', (string) $text );
	}

	/**
	 * Plain single-line text: no tags, no control characters, whitespace
	 * (non-breaking spaces too) collapsed, cut to a length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Longest result in characters.
	 * @return string
	 */
	public static function text( $value, $max = self::MAX_NAME ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = self::untag( (string) $value );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );

		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $text, 0, (int) $max, 'UTF-8' ) );
		}
		return trim( substr( $text, 0, (int) $max ) );
	}

	/**
	 * A positive id, or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	public static function id( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{1,18}$/', $value ) ) ) {
			return (int) $value > 0 ? (int) $value : null;
		}
		return null;
	}

	/**
	 * A size slug as the attribute terms write them, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function slug( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$slug = strtolower( trim( (string) $value ) );
		$slug = (string) preg_replace( '/[^a-z0-9_%.\-]+/', '-', $slug );
		$slug = substr( trim( $slug, '-' ), 0, self::MAX_SIZE );
		return '' === $slug ? null : $slug;
	}

	/**
	 * One line from a form row or a stored row, or null when it carries
	 * nothing (a fish or a note without a name). The kind decides what is
	 * kept: a credit's amount is stored negative and its reason loses a
	 * typed "Credit:" in front; a note keeps its text only; every id, the
	 * size and the stock tick belong to a fish line, and the tick only to
	 * one picked from a shop product.
	 *
	 * @param mixed $raw  The row.
	 * @param int   $sort Its position.
	 * @return array|null
	 */
	public static function normalize_line( $raw, $sort = 0 ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$kind = isset( $raw['kind'] ) && is_string( $raw['kind'] ) ? strtolower( trim( $raw['kind'] ) ) : 'fish';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'fish';
		}

		$name  = self::text( isset( $raw['name'] ) ? $raw['name'] : '' );
		$price = self::money( isset( $raw['unit_price'] ) ? $raw['unit_price'] : null );

		$line = array(
			'id'           => self::id( isset( $raw['id'] ) ? $raw['id'] : null ),
			'kind'         => $kind,
			'name'         => $name,
			'qty'          => 1,
			'unit_price'   => null,
			'product_id'   => null,
			'variation_id' => null,
			'species_id'   => null,
			'stay_id'      => null,
			'size_slug'    => null,
			'size_label'   => null,
			'source'       => 'typed',
			'take_stock'   => false,
			'sort'         => (int) $sort,
		);

		if ( 'note' === $kind ) {
			return '' === $name ? null : $line;
		}

		if ( 'credit' === $kind ) {
			$line['name']       = trim( (string) preg_replace( '/^credit\b[\s:\x{2014}\x{2013}\-]*/iu', '', $name ) );
			$line['unit_price'] = null === $price ? null : self::from_cents( -1 * abs( self::cents( $price ) ) );
			return ( '' === $line['name'] && null === $line['unit_price'] ) ? null : $line;
		}

		if ( 'discount' === $kind ) {
			$line['unit_price'] = $price;
			$line['name']       = self::cents( $price ) > 0 ? self::ADJUSTMENT_NAME : self::DISCOUNT_NAME;
			return 0 === self::cents( $price ) ? null : $line;
		}

		if ( '' === $name ) {
			return null;
		}

		$qty                  = isset( $raw['qty'] ) && is_numeric( $raw['qty'] ) ? (int) $raw['qty'] : 1;
		$line['qty']          = max( 1, min( self::MAX_QTY, $qty ) );
		$line['unit_price']   = $price;
		$line['product_id']   = self::id( isset( $raw['product_id'] ) ? $raw['product_id'] : null );
		$line['variation_id'] = null !== $line['product_id'] ? self::id( isset( $raw['variation_id'] ) ? $raw['variation_id'] : null ) : null;
		$line['species_id']   = self::id( isset( $raw['species_id'] ) ? $raw['species_id'] : null );
		$line['stay_id']      = self::id( isset( $raw['stay_id'] ) ? $raw['stay_id'] : null );
		$line['size_slug']    = self::slug( isset( $raw['size_slug'] ) ? $raw['size_slug'] : null );
		$size_label           = self::text( isset( $raw['size_label'] ) ? $raw['size_label'] : '', self::MAX_SIZE );
		$line['size_label']   = '' === $size_label ? null : $size_label;

		$source         = isset( $raw['source'] ) && is_string( $raw['source'] ) ? strtolower( trim( $raw['source'] ) ) : 'typed';
		$line['source'] = in_array( $source, self::SOURCES, true ) ? $source : 'typed';

		// A source without its id is only a typed name.
		if ( 'tank' === $line['source'] && null === $line['stay_id'] ) {
			$line['source'] = 'typed';
		}
		if ( 'product' === $line['source'] && null === $line['product_id'] ) {
			$line['source'] = 'typed';
		}
		if ( 'species' === $line['source'] && null === $line['species_id'] ) {
			$line['source'] = 'typed';
		}

		$line['take_stock'] = 'product' === $line['source'] && self::truthy( isset( $raw['take_stock'] ) ? $raw['take_stock'] : false );

		return $line;
	}

	/**
	 * Whether a posted flag is on ("1", "true", "on", "yes", true, 1).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * The typed lines of an invoice in their order: empty rows dropped, a
	 * posted discount line ignored (it is never typed — with_flat_total()
	 * writes it), positions renumbered from 0, at most MAX_LINES.
	 *
	 * @param mixed $raw Rows.
	 * @return array[]
	 */
	public static function normalize( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$line = self::normalize_line( $row, count( $out ) );
			if ( null === $line || 'discount' === $line['kind'] ) {
				continue;
			}
			$out[] = $line;
			if ( count( $out ) >= self::MAX_LINES ) {
				break;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  The maths                                                      */
	/* ============================================================== */

	/**
	 * What one line adds to the invoice, in cents: quantity × price per
	 * fish (a blank price counts as 0), a credit's or a discount's amount
	 * as stored (a credit is never positive), nothing for a note.
	 *
	 * @param array $line Line.
	 * @return int
	 */
	public static function amount_cents( array $line ) {
		$kind  = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
		$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null;

		if ( 'note' === $kind || null === $price ) {
			return 0;
		}
		if ( 'credit' === $kind ) {
			return -1 * abs( self::cents( $price ) );
		}
		if ( 'discount' === $kind ) {
			return self::cents( $price );
		}

		return max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ) * self::cents( $price );
	}

	/**
	 * What one line adds to the invoice.
	 *
	 * @param array $line Line.
	 * @return float
	 */
	public static function amount( array $line ) {
		return self::from_cents( self::amount_cents( $line ) );
	}

	/**
	 * The sum of the typed lines (fish and credits; the computed discount
	 * is left out).
	 *
	 * @param array[] $lines Lines.
	 * @return float
	 */
	public static function subtotal( array $lines ) {
		$cents = 0;
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && 'discount' !== ( isset( $line['kind'] ) ? $line['kind'] : '' ) ) {
				$cents += self::amount_cents( $line );
			}
		}
		return self::from_cents( $cents );
	}

	/**
	 * The lines with the stated price applied: any earlier discount line
	 * is dropped, and when a flat total is given and differs from the sum,
	 * ONE discount line is appended that makes the lines add up to it —
	 * "Discount" (negative) or, when the flat total is higher,
	 * "Adjustment" (positive). Without a flat total the total is the sum.
	 *
	 * @param array[]    $lines      Lines (normalised).
	 * @param float|null $flat_total The stated price, or null.
	 * @return array{lines: array[], subtotal: float, discount: float, total: float}
	 */
	public static function with_flat_total( array $lines, $flat_total ) {
		$kept        = array();
		$discount_id = null;
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			if ( 'discount' === ( isset( $line['kind'] ) ? $line['kind'] : '' ) ) {
				// Keep the row id, so the stored discount line is updated in place.
				if ( null === $discount_id && ! empty( $line['id'] ) ) {
					$discount_id = (int) $line['id'];
				}
				continue;
			}
			$line['sort'] = count( $kept );
			$kept[]       = $line;
		}

		$subtotal = self::cents( self::subtotal( $kept ) );
		$flat     = null === $flat_total || '' === $flat_total ? null : self::money( $flat_total );
		$diff     = null === $flat ? 0 : self::cents( $flat ) - $subtotal;

		if ( 0 !== $diff ) {
			$kept[] = array(
				'id'           => $discount_id,
				'kind'         => 'discount',
				'name'         => $diff > 0 ? self::ADJUSTMENT_NAME : self::DISCOUNT_NAME,
				'qty'          => 1,
				'unit_price'   => self::from_cents( $diff ),
				'product_id'   => null,
				'variation_id' => null,
				'species_id'   => null,
				'stay_id'      => null,
				'size_slug'    => null,
				'size_label'   => null,
				'source'       => 'typed',
				'take_stock'   => false,
				'sort'         => count( $kept ),
			);
		}

		return array(
			'lines'    => $kept,
			'subtotal' => self::from_cents( $subtotal ),
			'discount' => self::from_cents( $diff ),
			'total'    => null === $flat ? self::from_cents( $subtotal ) : $flat,
		);
	}

	/**
	 * The sum of every line, the discount line included — what the
	 * invoice's price must equal.
	 *
	 * @param array[] $lines Lines.
	 * @return float
	 */
	public static function total( array $lines ) {
		$cents = 0;
		foreach ( $lines as $line ) {
			if ( is_array( $line ) ) {
				$cents += self::amount_cents( $line );
			}
		}
		return self::from_cents( $cents );
	}

	/**
	 * How many fish the lines hold (quantities added up).
	 *
	 * @param array[] $lines Lines.
	 * @return int
	 */
	public static function fish_count( array $lines ) {
		$count = 0;
		foreach ( $lines as $line ) {
			if ( is_array( $line ) && 'fish' === ( isset( $line['kind'] ) ? $line['kind'] : '' ) ) {
				$count += max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) );
			}
		}
		return $count;
	}

	/* ============================================================== */
	/*  The description and the theme's JSON                           */
	/* ============================================================== */

	/**
	 * The name a line goes by outside this section: a credit is
	 * "Credit: reason" (or just "Credit"), a discount "Discount" or
	 * "Adjustment", a fish or a note its own text.
	 *
	 * @param array $line Line.
	 * @return string
	 */
	public static function public_name( array $line ) {
		$kind = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
		$name = isset( $line['name'] ) ? trim( (string) $line['name'] ) : '';

		if ( 'credit' === $kind ) {
			return '' === $name ? self::CREDIT_PREFIX : self::CREDIT_PREFIX . ': ' . $name;
		}
		if ( 'discount' === $kind ) {
			return self::amount_cents( $line ) > 0 ? self::ADJUSTMENT_NAME : self::DISCOUNT_NAME;
		}
		return $name;
	}

	/**
	 * One line as the description prints it (plain text, not escaped):
	 *
	 *   fish        "Blue Tang x2 — $45"   (price per fish; "Blue Tang x2" without one)
	 *   credit      "Credit: lost Cleaner Wrasse — -$82"
	 *   discount    "Discount — -$50"
	 *   adjustment  "Adjustment x1 — $20"
	 *   note        the text
	 *
	 * @param array $line Line.
	 * @return string
	 */
	public static function line_text( array $line ) {
		$kind = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
		$name = self::public_name( $line );

		if ( 'note' === $kind ) {
			return $name;
		}

		$cents = self::amount_cents( $line );

		if ( 'credit' === $kind || ( 'discount' === $kind && $cents <= 0 ) ) {
			return $name . ' ' . self::DASH . ' -$' . self::money_text( $cents / 100 );
		}
		if ( 'discount' === $kind ) {
			return $name . ' x1 ' . self::DASH . ' $' . self::money_text( $cents / 100 );
		}

		$text  = $name . ' x' . max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) );
		$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null;
		if ( null !== $price ) {
			$text .= ' ' . self::DASH . ' $' . self::money_text( $price );
		}
		return $text;
	}

	/**
	 * The product description: a <ul> with one <li> per line, as the
	 * hand-made invoices have it.
	 *
	 * @param array[] $lines Lines (with the discount line, when there is one).
	 * @return string
	 */
	public static function description_html( array $lines ) {
		$items = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$text = self::line_text( $line );
			if ( '' !== $text ) {
				$items[] = '<li>' . htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8' ) . '</li>';
			}
		}

		return $items ? "<ul>\n" . implode( "\n", $items ) . "\n</ul>" : '';
	}

	/**
	 * The rows of the theme's `_fh_invoice_lines` meta, in the shape its
	 * parser documents: a list of {name, qty, price, kind} with kind fish /
	 * credit / note, price per fish (a credit's price negative, a note's
	 * null), and product_id — the variation's id for a variation — ONLY on
	 * a fish line ticked "take from shop stock". A Discount goes out as a
	 * credit; an Adjustment as a one-off fish line, the only kind that
	 * adds money there.
	 *
	 * A name goes out with its &, < and > as entities: the theme strips
	 * tags from a name and decodes entities, so only an escaped name reads
	 * back there exactly as it was typed ("<2\" Clown", "A &amp; B").
	 *
	 * @param array[] $lines Lines (with the discount line, when there is one).
	 * @return array[]
	 */
	public static function theme_rows( array $lines ) {
		$rows = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$kind = isset( $line['kind'] ) ? (string) $line['kind'] : 'fish';
			$name = htmlspecialchars( self::public_name( $line ), ENT_NOQUOTES, 'UTF-8' );
			if ( '' === $name ) {
				continue;
			}

			if ( 'note' === $kind ) {
				$rows[] = array(
					'name'  => $name,
					'qty'   => 1,
					'price' => null,
					'kind'  => 'note',
				);
				continue;
			}

			$cents = self::amount_cents( $line );

			if ( 'credit' === $kind || 'discount' === $kind ) {
				$rows[] = array(
					'name'  => $name,
					'qty'   => 1,
					'price' => self::json_number( $cents ),
					'kind'  => ( 'discount' === $kind && $cents > 0 ) ? 'fish' : 'credit',
				);
				continue;
			}

			$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? self::json_number( self::cents( $line['unit_price'] ) ) : null;
			$row   = array(
				'name'  => $name,
				'qty'   => max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ),
				'price' => $price,
				'kind'  => 'fish',
			);

			$stock_id = self::stock_product_id( $line );
			if ( $stock_id > 0 ) {
				$row['product_id'] = $stock_id;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * The product whose shop stock a line takes when the order is paid:
	 * the variation for a variation, else the product — and 0 for every
	 * line that is not a ticked shop-product fish line.
	 *
	 * @param array $line Line.
	 * @return int
	 */
	public static function stock_product_id( array $line ) {
		if ( 'fish' !== ( isset( $line['kind'] ) ? $line['kind'] : '' ) || empty( $line['take_stock'] ) ) {
			return 0;
		}
		if ( 'product' !== ( isset( $line['source'] ) ? $line['source'] : '' ) || empty( $line['product_id'] ) ) {
			return 0;
		}
		return ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : (int) $line['product_id'];
	}

	/**
	 * The JSON stored in `_fh_invoice_lines`.
	 *
	 * @param array[] $lines Lines (with the discount line, when there is one).
	 * @return string
	 */
	public static function theme_json( array $lines ) {
		$json = json_encode( self::theme_rows( $lines ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Cents as the number the JSON carries: whole dollars as an integer,
	 * anything else with its cents.
	 *
	 * @param int $cents Cents.
	 * @return int|float
	 */
	private static function json_number( $cents ) {
		$cents = (int) $cents;
		return 0 === $cents % 100 ? (int) ( $cents / 100 ) : $cents / 100;
	}

	/**
	 * A short one-line summary for lists: "Blue Tang × 2, Firefish × 1,
	 * Credit: lost Cleaner Wrasse".
	 *
	 * @param array[] $lines Lines.
	 * @return string
	 */
	public static function summary( array $lines ) {
		$parts = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$name = self::public_name( $line );
			if ( '' === $name ) {
				continue;
			}
			if ( 'fish' === ( isset( $line['kind'] ) ? $line['kind'] : '' ) ) {
				$name .= " \u{00D7} " . max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) );
			}
			$parts[] = $name;
		}
		return implode( ', ', $parts );
	}
}
