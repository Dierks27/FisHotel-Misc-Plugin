<?php
/**
 * Invoice grammar — PURE: how an invoice that was made by hand is read.
 *
 * A hand-made invoice is a product whose description is a list of lines
 * ("Blue Tang x2 — $45", "Credit: lost Cleaner Wrasse — -$82"). The theme
 * (inc/invoice-lines.php) and the Batch plugin's Ship Day both read that
 * list with the same regular expressions; this class is the same grammar
 * once more — the same four expressions, the same order of the checks —
 * so this section agrees with them on what a line means:
 *
 *   - a line starting with "Credit" is a credit (its amount negated);
 *   - "Name xN — $price" is a fish with the price PER FISH (null when
 *     there is none);
 *   - any other line carrying a negative amount is a credit;
 *   - everything else is a note.
 *
 * The product meta `_fh_invoice_lines` (JSON) wins over the description
 * when it is usable, as in the theme (lines_from_json()).
 *
 * It is used to list a customer's hand-made invoices with their lines in
 * the pre-check, and to prove that a description this section writes
 * reads back as the lines it was written from (Rules::validate()).
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Grammar
 */
class Grammar {

	/**
	 * The category slug the theme and the Batch plugin recognise an
	 * invoice by (the section's own slug is a setting; this is theirs).
	 *
	 * @var string
	 */
	const CATEGORY_SLUG = 'invoices';

	/**
	 * "Name xN — $price": x / X / ×, optional spaces, — / – / - dash-run,
	 * price optional. The price needs "$digits" right after the dash-run,
	 * so "— -$82" can never read as a fish.
	 *
	 * @var string
	 */
	const RE_FISH = '/^(?<name>.+?)\s*[x\x{00D7}X]\s*(?<qty>\d+)\s*(?:[\x{2014}\x{2013}-]+\s*(?<price>\$\s*\d[\d,]*(?:\.\d+)?)?)?\s*$/u';

	/**
	 * A negative amount: the sign sits against the $ after a space, a dash
	 * or the start.
	 *
	 * @var string
	 */
	const RE_NEGATIVE = '/(?:^|(?<=[\s\x{2014}\x{2013}-]))(?:-|\x{2212})\$\s*\d|\$\s*(?:-|\x{2212})\s*\d/u';

	/**
	 * The first money token: "$82", "$ 1,250.50", "-$82", "$-82", "−$82".
	 *
	 * @var string
	 */
	const RE_AMOUNT = '/(?:-|\x{2212})?\s*\$\s*(?:-|\x{2212})?\s*(\d[\d,]*(?:\.\d+)?)/u';

	/**
	 * A trailing dash-run and money token, e.g. " — -$82".
	 *
	 * @var string
	 */
	const RE_AMOUNT_AT_END = '/\s*[\x{2014}\x{2013}-]*\s*(?:-|\x{2212})?\s*\$\s*(?:-|\x{2212})?\s*\d[\d,]*(?:\.\d+)?\s*$/u';

	/**
	 * The theme's invoice rule: the `invoices` category slug, or "Invoice"
	 * as a whole word in the name.
	 *
	 * @param string[] $category_slugs The product's category slugs.
	 * @param string   $name           Its name.
	 * @param string   $slug           The category slug to look for.
	 * @return bool
	 */
	public static function classify_invoice( array $category_slugs, $name, $slug = self::CATEGORY_SLUG ) {
		$slug = strtolower( trim( (string) $slug ) );
		foreach ( $category_slugs as $candidate ) {
			$candidate = strtolower( trim( (string) $candidate ) );
			if ( self::CATEGORY_SLUG === $candidate || ( '' !== $slug && $slug === $candidate ) ) {
				return true;
			}
		}
		return (bool) preg_match( '/\bInvoice\b/i', (string) $name );
	}

	/**
	 * Strip tags, decode entities, normalise whitespace (non-breaking
	 * spaces too).
	 *
	 * @param mixed $html Text or HTML.
	 * @return string
	 */
	public static function clean_text( $html ) {
		$text = strip_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\xAF" ), ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * A description as lines: each <li> is one (without a list, every
	 * non-empty text line is one).
	 *
	 * @param mixed $html The description.
	 * @return array<int, array{name: string, qty: int, price: float|null, kind: string, product_id: int}>
	 */
	public static function parse_lines( $html ) {
		$html = is_scalar( $html ) ? (string) $html : '';
		if ( '' === trim( $html ) ) {
			return array();
		}

		if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $html, $m ) && ! empty( $m[1] ) ) {
			$raw_lines = $m[1];
		} else {
			$text      = preg_replace( '/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\s*\/?\s*>/i', "\n", $html );
			$raw_lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		}

		$out = array();
		foreach ( (array) $raw_lines as $raw ) {
			$text = self::clean_text( $raw );
			if ( '' !== $text ) {
				$out[] = self::parse_line( $text );
			}
		}
		return $out;
	}

	/**
	 * One cleaned line as a fish, a credit or a note.
	 *
	 * @param string $text The line.
	 * @return array{name: string, qty: int, price: float|null, kind: string, product_id: int}
	 */
	public static function parse_line( $text ) {
		$text = (string) $text;

		// A "Credit…" line is money, not a fish — checked first.
		if ( preg_match( '/^credit\b/iu', $text ) ) {
			return self::credit_line( $text );
		}

		if ( preg_match( self::RE_FISH, $text, $mm ) && '' !== trim( $mm['name'] ) ) {
			$price = null;
			if ( isset( $mm['price'] ) && '' !== $mm['price'] ) {
				$price = (float) str_replace( array( '$', ',', ' ' ), '', $mm['price'] );
			}
			return array(
				'name'       => trim( $mm['name'] ),
				'qty'        => max( 1, (int) $mm['qty'] ),
				'price'      => $price,
				'kind'       => 'fish',
				'product_id' => 0,
			);
		}

		// Not a fish line: a negative amount anywhere makes it a credit.
		if ( preg_match( self::RE_NEGATIVE, $text ) ) {
			return self::credit_line( $text );
		}

		return array(
			'name'       => $text,
			'qty'        => 1,
			'price'      => null,
			'kind'       => 'note',
			'product_id' => 0,
		);
	}

	/**
	 * "Credit: lost Cleaner Wrasse — -$82" as a credit: the name without
	 * the amount, the price -82.
	 *
	 * @param string $text The line.
	 * @return array{name: string, qty: int, price: float|null, kind: string, product_id: int}
	 */
	private static function credit_line( $text ) {
		$amount = null;
		if ( preg_match( self::RE_AMOUNT, $text, $m ) ) {
			$amount = -1 * abs( (float) str_replace( ',', '', $m[1] ) );
		}

		$name = preg_replace( self::RE_AMOUNT_AT_END, '', $text );
		if ( $name === $text && null !== $amount ) {
			// The amount is not at the end: drop the first money token wherever it sits.
			$name = preg_replace( self::RE_AMOUNT, ' ', $text, 1 );
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $name ) );
		$name = (string) preg_replace( '/[\s:\x{2014}\x{2013}-]+$/u', '', $name );
		if ( '' === $name ) {
			$name = $text;
		}

		return array(
			'name'       => $name,
			'qty'        => 1,
			'price'      => $amount,
			'kind'       => 'credit',
			'product_id' => 0,
		);
	}

	/**
	 * Lines from the `_fh_invoice_lines` JSON: a list of {name, qty, price,
	 * kind?, product_id?}. Entries without a name are dropped; an unknown
	 * kind is a credit for a negative price or a "Credit…" name, else a
	 * fish. A credit has quantity 1 and a negative price; a note has no
	 * price.
	 *
	 * @param mixed $json The meta value (a JSON string, or the decoded list).
	 * @return array Same shape as parse_lines(); empty when unusable.
	 */
	public static function lines_from_json( $json ) {
		if ( is_array( $json ) ) {
			$data = $json;
		} else {
			if ( ! is_string( $json ) || '' === trim( $json ) ) {
				return array();
			}
			$data = json_decode( $json, true );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}

		$out = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = ( isset( $row['name'] ) && is_scalar( $row['name'] ) ) ? self::clean_text( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$price = ( isset( $row['price'] ) && is_numeric( $row['price'] ) ) ? (float) $row['price'] : null;
			$qty   = ( isset( $row['qty'] ) && is_numeric( $row['qty'] ) ) ? max( 1, (int) $row['qty'] ) : 1;
			$kind  = ( isset( $row['kind'] ) && is_string( $row['kind'] ) ) ? strtolower( trim( $row['kind'] ) ) : '';
			if ( ! in_array( $kind, array( 'fish', 'credit', 'note' ), true ) ) {
				$kind = ( ( null !== $price && $price < 0 ) || preg_match( '/^credit\b/iu', $name ) ) ? 'credit' : 'fish';
			}
			$product_id = ( isset( $row['product_id'] ) && is_numeric( $row['product_id'] ) ) ? max( 0, (int) $row['product_id'] ) : 0;

			if ( 'credit' === $kind ) {
				$qty   = 1;
				$price = ( null === $price ) ? null : -1 * abs( $price );
			} elseif ( 'note' === $kind ) {
				$qty   = 1;
				$price = null;
			}

			$out[] = array(
				'name'       => $name,
				'qty'        => $qty,
				'price'      => $price,
				'kind'       => $kind,
				'product_id' => $product_id,
			);
		}
		return $out;
	}

	/**
	 * A product's lines the way the theme takes them: the JSON meta when
	 * it is present and usable, else the parsed description.
	 *
	 * @param mixed $json        The `_fh_invoice_lines` meta.
	 * @param mixed $description The product description.
	 * @return array
	 */
	public static function product_lines( $json, $description ) {
		$lines = self::lines_from_json( $json );
		return $lines ? $lines : self::parse_lines( $description );
	}

	/**
	 * One read line back as text, the way the invoices print it: "Blue
	 * Tang x2 — $45", "Credit: lost Cleaner Wrasse — -$82", a note as it
	 * is.
	 *
	 * @param array $row A parse_lines() / lines_from_json() row.
	 * @return string
	 */
	public static function line_text( array $row ) {
		$kind  = isset( $row['kind'] ) ? (string) $row['kind'] : 'note';
		$name  = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
		$price = ( isset( $row['price'] ) && is_numeric( $row['price'] ) ) ? (float) $row['price'] : null;

		if ( 'fish' === $kind ) {
			$text = $name . ' x' . max( 1, (int) ( isset( $row['qty'] ) ? $row['qty'] : 1 ) );
			return null === $price ? $text : $text . ' ' . Lines::DASH . ' $' . Lines::money_text( $price );
		}
		if ( 'credit' === $kind && null !== $price ) {
			return $name . ' ' . Lines::DASH . ' -$' . Lines::money_text( $price );
		}
		return $name;
	}

	/**
	 * What the lines add up to, the way the theme decides whether an
	 * invoice can become one order line per fish: fish quantity × price
	 * plus the credits. Null when a fish line has no price above zero or
	 * there is no fish line (the theme then keeps the invoice as one line).
	 *
	 * @param array $lines parse_lines() / lines_from_json() rows.
	 * @return float|null
	 */
	public static function sum( array $lines ) {
		$fish  = 0;
		$cents = 0;
		foreach ( $lines as $line ) {
			$kind  = isset( $line['kind'] ) ? $line['kind'] : 'note';
			$price = ( isset( $line['price'] ) && is_numeric( $line['price'] ) ) ? (float) $line['price'] : null;
			if ( 'fish' === $kind ) {
				$fish++;
				if ( null === $price || $price <= 0 ) {
					return null;
				}
				$cents += max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ) * (int) round( $price * 100 );
			} elseif ( 'credit' === $kind && null !== $price ) {
				$cents -= (int) round( abs( $price ) * 100 );
			}
		}
		return 0 === $fish ? null : (float) ( $cents / 100 );
	}
}
