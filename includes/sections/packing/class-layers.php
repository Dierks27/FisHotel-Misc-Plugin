<?php
/**
 * Bag layers — how many bags, one inside the other, a fish is packed in.
 *
 * "This should always be double for reg fish and triple for anything with
 * sharp spines or like a tang that has a scalpel. We double and triple bag
 * everything." So every bag Packing plans is used in LAYERS: 2 by default,
 * 3 for a fish with sharp spines or a scalpel. How a fish is known, in this
 * order (decide()):
 *
 *   1. the product's own choice — "Bagging: Auto / Double / Triple" in the
 *      product editor's Shipping tab, stored on the PARENT product as the
 *      hidden meta `_fh_bag_layers` ('2' or '3'; Auto stores nothing);
 *   2. a word of the "Triple-bag these" list (Packing → Bags), found as a
 *      WHOLE word, whatever the letter case, in the order line's name or in
 *      one of its product's category names — so it also reaches the fish
 *      of an opened-up Invoice and a fish line with no product. One or
 *      many makes no difference: "tang" finds "Tangs" and "tangs" finds
 *      "Tang" — never "Tangerine";
 *   3. otherwise 2.
 *
 * The filter fishotel_packing_bag_layers has the last word (for_line()).
 *
 * The outer and the inner bags are the same stock item, so a count of
 * layers is a count of single bags to pull.
 *
 * Everything here is pure except stored(), category_names() and the filter
 * in for_line(). Nothing here writes anything.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Layers
 */
class Layers {

	const DOUBLE = 2;
	const TRIPLE = 3;

	/**
	 * Why a fish has its layers: the product says so, a word matched
	 * ("word:tang"), nothing said anything, or other code overruled.
	 */
	const WHY_PRODUCT = 'product';
	const WHY_WORD    = 'word:';
	const WHY_DEFAULT = 'default';
	const WHY_FILTER  = 'filter';

	/**
	 * The most words the list keeps, and the longest a word may be.
	 */
	const MAX_WORDS = 60;
	const MAX_WORD  = 40;

	/**
	 * The words the list starts with — until the owner saves a list of
	 * their own (an empty one included).
	 *
	 * @return string[]
	 */
	public static function default_words() {
		return array( 'tang', 'surgeonfish', 'unicornfish', 'rabbitfish', 'foxface', 'lionfish', 'scorpionfish', 'triggerfish', 'angelfish', 'squirrelfish' );
	}

	/**
	 * One word (or a short phrase) as the list keeps it (pure): plain text,
	 * lower-cased, runs of whitespace collapsed. '' for anything that is not
	 * a word — no letter or digit in it, a single character, or longer than
	 * MAX_WORD.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function word_in( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $raw );
		$text = str_replace( '\\', '', strip_tags( $text ) );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $length < 2 || $length > self::MAX_WORD || 1 !== preg_match( '/[a-z0-9\x80-\xFF]/', $text ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * What was typed as a list, split up (pure): one entry per line or per
	 * comma; blanks dropped.
	 *
	 * @param mixed $raw A string (lines and commas) or a list.
	 * @return string[] The entries as typed, trimmed.
	 */
	public static function split( $raw ) {
		if ( is_scalar( $raw ) && ! is_bool( $raw ) ) {
			$raw = preg_split( '/[,;\r\n]+/', (string) $raw );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			$entry = is_scalar( $entry ) && ! is_bool( $entry ) ? trim( (string) $entry ) : '';
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * A list of words, cleaned (pure): each through word_in(), what is not
	 * a word and a word said twice dropped, at most MAX_WORDS.
	 *
	 * @param mixed $raw A string (lines and commas) or a list.
	 * @return string[]
	 */
	public static function words_in( $raw ) {
		$out = array();
		foreach ( self::split( $raw ) as $entry ) {
			$word = self::word_in( $entry );
			if ( '' !== $word && ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
			if ( count( $out ) >= self::MAX_WORDS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A stored product choice as the rule reads it (pure): 2, 3, or 0 for
	 * anything else — Auto.
	 *
	 * @param mixed $value The `_fh_bag_layers` meta, or a posted value.
	 * @return int 2 | 3 | 0.
	 */
	public static function choice( $value ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return 0;
		}
		$text = trim( (string) $value );
		if ( '2' === $text ) {
			return self::DOUBLE;
		}
		return '3' === $text ? self::TRIPLE : 0;
	}

	/**
	 * A listed word as it is looked for (pure): the word itself, and — when
	 * it was typed as a plural — the word without its "s" or "es", so that
	 * "tangs" on the list finds "Yellow Tang" just as "tang" finds "Tangs".
	 * A word of three letters or fewer, and one ending in "ss", is taken as
	 * it is.
	 *
	 * @param string $word A word of the list.
	 * @return string[] The spellings to look for, the word itself first.
	 */
	public static function stems( $word ) {
		$word = trim( (string) $word );
		$out  = array( $word );
		if ( '' === $word || 1 !== preg_match( '/^(.{3,}?)(e?)s$/is', $word, $m ) || 1 === preg_match( '/s$/i', $m[1] . $m[2] ) ) {
			return '' === $word ? array() : $out;
		}
		$out[] = $m[1] . $m[2];
		if ( '' !== $m[2] ) {
			$out[] = $m[1];
		}
		return $out;
	}

	/**
	 * The first word of the list found in one of the texts (pure) — as a
	 * whole word, whatever the letter case; one or many makes no difference
	 * ("tang" finds "Blue Tangs", "tangs" finds "Blue Tang"), a longer word
	 * does not count ("Tangerine").
	 *
	 * @param string[] $words The list.
	 * @param string[] $texts A fish's name, its category names.
	 * @return string The word as the list has it, '' when none is found.
	 */
	public static function find_word( array $words, array $texts ) {
		foreach ( $words as $word ) {
			$word = is_scalar( $word ) ? trim( (string) $word ) : '';
			if ( '' === $word ) {
				continue;
			}
			$quoted = array();
			foreach ( self::stems( $word ) as $stem ) {
				$quoted[] = preg_quote( $stem, '/' );
			}
			$quoted = '(?:' . implode( '|', $quoted ) . ')';
			foreach ( $texts as $text ) {
				if ( ! is_scalar( $text ) || '' === (string) $text ) {
					continue;
				}
				$found = preg_match( '/(?<![\p{L}\p{N}])' . $quoted . '(?:e?s)?(?![\p{L}\p{N}])/iu', (string) $text );
				if ( false === $found ) {
					// Text that is not UTF-8: read it byte by byte.
					$found = preg_match( '/(?<![A-Za-z0-9])' . $quoted . '(?:e?s)?(?![A-Za-z0-9])/i', (string) $text );
				}
				if ( 1 === $found ) {
					return $word;
				}
			}
		}
		return '';
	}

	/**
	 * The rule itself (pure).
	 *
	 * @param mixed    $stored     The product's `_fh_bag_layers` ('' = Auto, or no product).
	 * @param string[] $words      The "Triple-bag these" list.
	 * @param mixed    $name       The order line's name.
	 * @param string[] $categories Its product's category names.
	 * @return array{layers: int, why: string}
	 */
	public static function decide( $stored, array $words, $name, array $categories = array() ) {
		$choice = self::choice( $stored );
		if ( $choice > 0 ) {
			return array(
				'layers' => $choice,
				'why'    => self::WHY_PRODUCT,
			);
		}

		$word = self::find_word( $words, array_merge( array( is_scalar( $name ) ? (string) $name : '' ), $categories ) );
		if ( '' !== $word ) {
			return array(
				'layers' => self::TRIPLE,
				'why'    => self::WHY_WORD . $word,
			);
		}

		return array(
			'layers' => self::DOUBLE,
			'why'    => self::WHY_DEFAULT,
		);
	}

	/**
	 * The choice stored for a product ('' = Auto) — a variation's is its
	 * parent's.
	 *
	 * @param object|null $product WC_Product.
	 * @return string '2' | '3' | ''.
	 */
	public static function stored( $product ) {
		$parent = Livestock::parent_of( $product );
		if ( null === $parent || ! method_exists( $parent, 'get_meta' ) ) {
			return '';
		}
		$choice = self::choice( $parent->get_meta( Packing::META_LAYERS, true ) );
		return $choice > 0 ? (string) $choice : '';
	}

	/**
	 * The category names of a product (its parent's for a variation). A
	 * category with no name to read is given by its slug, the hyphens as
	 * spaces.
	 *
	 * @param object|null $product WC_Product.
	 * @return string[]
	 */
	public static function category_names( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$id = (int) $product->get_id();
		if ( method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$id = (int) $product->get_parent_id();
		}

		$terms = get_the_terms( $id, 'product_cat' );
		$names = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}
			if ( isset( $term->name ) && is_scalar( $term->name ) && '' !== trim( (string) $term->name ) ) {
				$names[] = (string) $term->name;
			} elseif ( isset( $term->slug ) && is_scalar( $term->slug ) ) {
				$names[] = str_replace( array( '-', '_' ), ' ', (string) $term->slug );
			}
		}
		return $names;
	}

	/**
	 * The layers of one fish line: the rule, then the filter
	 * fishotel_packing_bag_layers ( $layers, $product, $name, $order_item ) —
	 * an answer of 2 or 3 counts, anything else is ignored.
	 *
	 * @param object|null $product  The line's product (null without one).
	 * @param mixed       $name     The line's name.
	 * @param array       $settings Settings (triple_words).
	 * @param object|null $item     The order line, for the filter (null for a fish of an opened-up Invoice).
	 * @return array{layers: int, why: string}
	 */
	public static function for_line( $product, $name, array $settings, $item = null ) {
		$product = is_object( $product ) ? $product : null;
		$words   = isset( $settings['triple_words'] ) && is_array( $settings['triple_words'] ) ? $settings['triple_words'] : self::default_words();
		$out     = self::decide( self::stored( $product ), $words, $name, self::category_names( $product ) );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = self::choice( apply_filters( 'fishotel_packing_bag_layers', $out['layers'], $product, is_scalar( $name ) ? (string) $name : '', $item ) );
			if ( $filtered > 0 && $filtered !== $out['layers'] ) {
				$out = array(
					'layers' => $filtered,
					'why'    => self::WHY_FILTER,
				);
			}
		}

		return $out;
	}

	/**
	 * The layers in plain words, after a bag's name (pure): "×2",
	 * "×3 — spines".
	 *
	 * @param int $layers 2 or 3.
	 * @return string
	 */
	public static function words( $layers ) {
		$layers = (int) $layers;
		if ( $layers >= self::TRIPLE ) {
			/* translators: %d: how many bags, one inside the other */
			return sprintf( __( '×%d — spines', 'fishotel-misc-plugin' ), $layers );
		}
		return '×' . max( 1, $layers );
	}

	/**
	 * A line's bags with their layers (pure): "Medium ×2", "Large ×3 —
	 * spines"; "2 × Large + Medium, each ×2" when its fish ended in bags of
	 * different sizes; and "Large + Medium — ×2, ×3 for the spiny ones" when
	 * its fish are not all bagged alike (an Invoice kept as one line).
	 *
	 * @param string $use_name The bags of the line ("Large", "2 × Large + Medium").
	 * @param int    $layers   The layers of the line (the highest, when they differ).
	 * @param bool   $several  Whether the line's fish ended in bags of different sizes.
	 * @param bool   $differ   Whether its fish do not all have the same layers.
	 * @return string
	 */
	public static function label( $use_name, $layers, $several = false, $differ = false ) {
		$use_name = (string) $use_name;
		if ( $differ ) {
			/* translators: %s: the bags of a line */
			return sprintf( __( '%s — ×2, ×3 for the spiny ones', 'fishotel-misc-plugin' ), $use_name );
		}
		if ( $several ) {
			/* translators: 1: the bags of a line, 2: the layers, as "×2" */
			return sprintf( __( '%1$s, each %2$s', 'fishotel-misc-plugin' ), $use_name, self::words( $layers ) );
		}
		return trim( $use_name . ' ' . self::words( $layers ) );
	}

	/**
	 * Why a fish is bagged the way it is, as a sentence for the Packing box
	 * (pure).
	 *
	 * @param int    $layers 2 or 3.
	 * @param string $why    WHY_*.
	 * @return string
	 */
	public static function why_text( $layers, $why ) {
		$why   = (string) $why;
		$times = (int) $layers >= self::TRIPLE ? __( 'Triple-bagged', 'fishotel-misc-plugin' ) : __( 'Double-bagged', 'fishotel-misc-plugin' );

		if ( self::WHY_PRODUCT === $why ) {
			/* translators: %s: "Double-bagged" or "Triple-bagged" */
			return sprintf( __( '%s: set on the product (Bagging)', 'fishotel-misc-plugin' ), $times );
		}
		if ( 0 === strpos( $why, self::WHY_WORD ) ) {
			/* translators: 1: "Triple-bagged", 2: the word that matched */
			return sprintf( __( '%1$s: “%2$s” is on the Triple-bag list (Packing → Bags)', 'fishotel-misc-plugin' ), $times, substr( $why, strlen( self::WHY_WORD ) ) );
		}
		if ( self::WHY_FILTER === $why ) {
			/* translators: %s: "Double-bagged" or "Triple-bagged" */
			return sprintf( __( '%s: set by another plugin', 'fishotel-misc-plugin' ), $times );
		}
		/* translators: %s: "Double-bagged" */
		return sprintf( __( '%s: every fish is, unless it has spines', 'fishotel-misc-plugin' ), $times );
	}
}
