<?php
/**
 * Tidy up the sizes — PURE: the thinking behind the tool of Settings →
 * Sizes that brings the shop's long list of sizes down to a short one.
 * Nothing in this file reads or writes anything; it turns what the site
 * read (Sizes_Tidy_Site::read_shop()) and what the owner chose into a
 * plan — the dry run the tool shows, and exactly what Apply then does
 * (Sizes_Tidy_Run).
 *
 * The short list: plain lines, DEFAULT_LIST to start with.
 *
 * The map: every size of the attribute (and every size typed on a fish
 * product) gets one of
 *   stay      it is in the short list;
 *   to:<slug> it becomes that size of the short list;
 *   keep      it is left as it is;
 *   remove    it is taken off the products.
 * suggested by a rule the owner can overrule row by row (suggest()):
 *   - an inch range a–b goes to the inch size of the list that holds its
 *     midpoint, a tie going to the lower one (3-5" → 3-4", 4-6" → 4-5");
 *   - an open-ended n"+ goes to the inch size that starts at n, else the
 *     one that holds n, and to the list's open-ended top size from that
 *     size's start upward (3"+ → 3-4", 6"+ → 5"+);
 *   - a size below the list's smallest goes to the smallest, flagged;
 *   - a WORD is never guessed into inches: one between two size words
 *     (Small-Medium) is taken off, one no product uses is removed, any
 *     other stays as it is, flagged.
 * Inch sizes are recognised by their numbers, words by having none, so
 * the rule works for any short list.
 *
 * "Taken off" never deletes a variation: it is switched off (not for
 * sale, hidden from the fish page), keeps its price, stock, SKU and id,
 * and loses its size. A variation that is for sale today is left alone
 * unless the owner says otherwise (IN_STOCK_*): its size then stays in
 * the shop, "retiring", until nothing in stock carries it.
 *
 * Two variations of one product that would end up the same size are
 * never merged: the product is left untouched and listed, until the
 * owner picks the one to keep (the others are switched off, kept).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sizes_Tidy
 */
class Sizes_Tidy {

	/**
	 * The short list the tool starts from, in this order.
	 */
	const DEFAULT_LIST = array( '1-2"', '2-3"', '3-4"', '4-5"', '5"+', 'Small', 'Medium', 'Large', 'Pair', 'All' );

	/**
	 * What becomes of a size.
	 */
	const STAY   = 'stay';
	const KEEP   = 'keep';
	const REMOVE = 'remove';
	const TO     = 'to:';

	/**
	 * What happens to a variation that is for sale today and whose size
	 * is being taken off: left until it sells out (the default), moved to
	 * a size the owner picks, or switched off now.
	 */
	const IN_STOCK_LEAVE = 'leave';
	const IN_STOCK_MOVE  = 'move';
	const IN_STOCK_OFF   = 'off';

	/**
	 * The words a size is made of ("Small-Medium" sits between two).
	 */
	const WORDS = array( 'tiny', 'xs', 'small', 'sm', 's', 'medium', 'med', 'm', 'large', 'lg', 'l', 'xl', 'xxl', 'jumbo' );

	/**
	 * The product meta that lists the sizes a tidy-up took off a product
	 * (variation id → slug, label), for the "Sizes to fill in" list.
	 */
	const FILL_META = '_fh_sizes_to_fill';

	/* ============================================================== */
	/*  Reading a label                                                */
	/* ============================================================== */

	/**
	 * What a label says: an inch range (lo–hi; a lone number is a range
	 * of itself), an open-ended size (lo and up), a size under a number
	 * (0–hi), or a word — anything without numbers the rule can read.
	 *
	 * @param string $label Label ('2 - 3.5"', '3”-4”', '10"+', '<1"', 'Show size').
	 * @return array{kind: string, lo: float|null, hi: float|null} kind is range|plus|under|word.
	 */
	public static function parse( $label ) {
		$text = Sizes::plain( (string) $label );
		$text = str_replace( array( '”', '“', '″', '′', '’', '‘', "''" ), '"', $text );
		$text = str_replace( array( '–', '—', '−' ), '-', $text );
		$text = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
		$num  = '(\d+(?:\.\d+)?|\.\d+)';
		$unit = '\s*(?:"|in\.?|inch(?:es)?)?\s*';
		$m    = array();

		if ( preg_match( '/^(?:<|under|below|less than|up to)\s*' . $num . $unit . '$/', $text, $m ) ) {
			return array(
				'kind' => 'under',
				'lo'   => 0.0,
				'hi'   => (float) $m[1],
			);
		}
		if ( preg_match( '/^' . $num . $unit . '(?:\+|and up|& up|and over|or more|and larger)' . $unit . '$/', $text, $m ) || preg_match( '/^(?:>|over|above)\s*' . $num . $unit . '$/', $text, $m ) ) {
			return array(
				'kind' => 'plus',
				'lo'   => (float) $m[1],
				'hi'   => null,
			);
		}
		if ( preg_match( '/^' . $num . $unit . '(?:-|to)\s*' . $num . $unit . '$/', $text, $m ) ) {
			return array(
				'kind' => 'range',
				'lo'   => min( (float) $m[1], (float) $m[2] ),
				'hi'   => max( (float) $m[1], (float) $m[2] ),
			);
		}
		if ( preg_match( '/^' . $num . $unit . '$/', $text, $m ) ) {
			return array(
				'kind' => 'range',
				'lo'   => (float) $m[1],
				'hi'   => (float) $m[1],
			);
		}

		return array(
			'kind' => 'word',
			'lo'   => null,
			'hi'   => null,
		);
	}

	/**
	 * Whether a word size sits between two size words ("Small-Medium",
	 * "Large / XL", "Medium to Large") — "X-Large" is one word.
	 *
	 * @param string $label Label.
	 * @return bool
	 */
	public static function between( $label ) {
		$text  = strtolower( Sizes::plain( (string) $label ) );
		$text  = (string) preg_replace( '/\b(?:x|extra)[\s-]*large\b/', 'xl', $text );
		$text  = (string) preg_replace( '/\b(?:x|extra)[\s-]*small\b/', 'xs', $text );
		$parts = preg_split( '/\s*(?:-|\/|\bto\b)\s*/', trim( $text ) );

		return is_array( $parts ) && 2 === count( $parts ) && $parts[0] !== $parts[1]
			&& in_array( $parts[0], self::WORDS, true ) && in_array( $parts[1], self::WORDS, true );
	}

	/* ============================================================== */
	/*  The short list                                                 */
	/* ============================================================== */

	/**
	 * The short list as sizes, each matched to the attribute's term when
	 * one has its slug or its name — else it is a size to create.
	 *
	 * @param mixed   $raw   Lines of a textarea or a list of labels (empty = DEFAULT_LIST).
	 * @param array[] $terms The attribute's terms (term_id, name, slug).
	 * @return array[] slug, label, exists (bool), kind, lo, hi — in the list's order.
	 */
	public static function short_list( $raw, array $terms = array() ) {
		$labels = Sizes::labels( $raw );
		if ( ! $labels ) {
			$labels = self::DEFAULT_LIST;
		}

		$by_slug = array();
		$by_name = array();
		foreach ( $terms as $term ) {
			$slug = (string) ( $term['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$name             = Sizes::plain( (string) ( $term['name'] ?? '' ) );
			$by_slug[ $slug ] = '' !== $name ? $name : $slug;
			$name             = strtolower( $name );
			if ( '' !== $name && ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = $slug;
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $labels as $label ) {
			$slug   = Sizes::slug( $label );
			$exists = false;
			if ( isset( $by_name[ strtolower( $label ) ] ) ) {
				$slug   = $by_name[ strtolower( $label ) ];
				$exists = true;
			} elseif ( isset( $by_slug[ $slug ] ) ) {
				$exists = true;
			}
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = array(
				'slug'   => $slug,
				'label'  => $exists ? $by_slug[ $slug ] : $label,
				'exists' => $exists,
			) + self::parse( $exists ? $by_slug[ $slug ] : $label );
		}

		return $out;
	}

	/**
	 * The size of the short list a label reads like (its name, else its
	 * slug), or null.
	 *
	 * @param string  $label Label.
	 * @param array[] $short short_list().
	 * @return string|null Its slug.
	 */
	public static function in_short( $label, array $short ) {
		$label = Sizes::plain( (string) $label );
		$slug  = Sizes::slug( $label );
		$found = null;
		foreach ( $short as $size ) {
			if ( strtolower( (string) $size['label'] ) === strtolower( $label ) ) {
				return (string) $size['slug'];
			}
			if ( null === $found && '' !== $slug && ( (string) $size['slug'] === $slug || Sizes::slug( (string) $size['label'] ) === $slug ) ) {
				$found = (string) $size['slug'];
			}
		}
		return $found;
	}

	/**
	 * The inch size of the short list a parsed size goes to (pure): the
	 * one that holds its midpoint — an open-ended one the one that starts
	 * at it —, a tie going to the lower one; nothing holds it: the
	 * nearest, flagged.
	 *
	 * @param array   $size  parse().
	 * @param array[] $short short_list().
	 * @return array{slug: string|null, flag: string} flag is '' | below | nofit.
	 */
	public static function fit( array $size, array $short ) {
		$none = array(
			'slug' => null,
			'flag' => 'nofit',
		);
		if ( 'word' === (string) ( $size['kind'] ?? 'word' ) ) {
			return $none;
		}

		$spans = array();
		$top   = null;
		foreach ( $short as $entry ) {
			if ( 'word' === $entry['kind'] ) {
				continue;
			}
			$span    = array(
				'slug' => (string) $entry['slug'],
				'lo'   => (float) $entry['lo'],
				'hi'   => 'plus' === $entry['kind'] ? INF : (float) $entry['hi'],
			);
			$spans[] = $span;
			if ( 'plus' === $entry['kind'] && ( null === $top || $span['lo'] > $top['lo'] ) ) {
				$top = $span;
			}
		}
		if ( ! $spans ) {
			return $none;
		}
		usort(
			$spans,
			static function ( $a, $b ) {
				return 0 !== ( $a['lo'] <=> $b['lo'] ) ? $a['lo'] <=> $b['lo'] : $a['hi'] <=> $b['hi'];
			}
		);

		if ( 'plus' === $size['kind'] ) {
			$point = (float) $size['lo'];
			if ( null !== $top && $point >= $top['lo'] ) {
				return array(
					'slug' => $top['slug'],
					'flag' => '',
				);
			}
			foreach ( $spans as $span ) {
				if ( abs( $span['lo'] - $point ) < 0.0001 ) {
					return array(
						'slug' => $span['slug'],
						'flag' => '',
					);
				}
			}
		} else {
			$point = ( (float) $size['lo'] + (float) $size['hi'] ) / 2;
		}

		foreach ( $spans as $span ) {
			if ( $point >= $span['lo'] - 0.0001 && $point <= $span['hi'] + 0.0001 ) {
				return array(
					'slug' => $span['slug'],
					'flag' => '',
				);
			}
		}

		$best = null;
		$gap  = INF;
		foreach ( $spans as $span ) {
			$away = max( $span['lo'] - $point, $point - $span['hi'], 0 );
			if ( $away < $gap - 0.0001 ) {
				$gap  = $away;
				$best = $span;
			}
		}

		return array(
			'slug' => $best['slug'],
			'flag' => $point < $spans[0]['lo'] ? 'below' : 'nofit',
		);
	}

	/* ============================================================== */
	/*  The map                                                        */
	/* ============================================================== */

	/**
	 * What the rule suggests for one size (pure).
	 *
	 * @param array   $row   label, typed (bool), used (bool), retiring (bool).
	 * @param array[] $short short_list().
	 * @return array{action: string, flags: string[]} flags: plus, below, nofit, word, between, unused, retiring.
	 */
	public static function suggest( array $row, array $short ) {
		$label = (string) ( $row['label'] ?? '' );
		$typed = ! empty( $row['typed'] );
		$slug  = (string) ( $row['slug'] ?? '' );

		if ( ! $typed ) {
			foreach ( $short as $size ) {
				if ( (string) $size['slug'] === $slug ) {
					return array(
						'action' => self::STAY,
						'flags'  => array(),
					);
				}
			}
		}
		$same = self::in_short( $label, $short );
		if ( null !== $same && ( $typed || $same !== $slug ) ) {
			// The same size written another way: a typed size that reads like one of the list, or a second term of the same name.
			return array(
				'action' => self::TO . $same,
				'flags'  => array(),
			);
		}

		$size = self::parse( $label );
		if ( 'word' !== $size['kind'] ) {
			$fit = self::fit( $size, $short );
			if ( null === $fit['slug'] ) {
				return array(
					'action' => self::KEEP,
					'flags'  => array( 'nofit' ),
				);
			}
			$flags = 'plus' === $size['kind'] ? array( 'plus' ) : array();
			if ( '' !== $fit['flag'] ) {
				$flags[] = $fit['flag'];
			}
			return array(
				'action' => self::TO . $fit['slug'],
				'flags'  => $flags,
			);
		}

		if ( $typed ) {
			return array(
				'action' => self::KEEP,
				'flags'  => array( 'word' ),
			);
		}
		if ( ! empty( $row['retiring'] ) ) {
			return array(
				'action' => self::REMOVE,
				'flags'  => array( 'retiring' ),
			);
		}
		if ( empty( $row['used'] ) ) {
			return array(
				'action' => self::REMOVE,
				'flags'  => array( 'unused' ),
			);
		}
		if ( self::between( $label ) ) {
			return array(
				'action' => self::REMOVE,
				'flags'  => array( 'between' ),
			);
		}
		return array(
			'action' => self::KEEP,
			'flags'  => array( 'word' ),
		);
	}

	/**
	 * Whether a posted choice is one a row may have: keep, remove (not
	 * for a typed size) or a size of the short list other than itself.
	 *
	 * @param mixed   $action The posted value.
	 * @param array   $row    slug, typed.
	 * @param array[] $short  short_list().
	 * @return bool
	 */
	public static function valid_action( $action, array $row, array $short ) {
		if ( ! is_string( $action ) ) {
			return false;
		}
		if ( self::KEEP === $action ) {
			return true;
		}
		if ( self::REMOVE === $action ) {
			return empty( $row['typed'] );
		}
		if ( 0 !== strpos( $action, self::TO ) ) {
			return false;
		}
		$target = substr( $action, strlen( self::TO ) );
		if ( empty( $row['typed'] ) && $target === (string) ( $row['slug'] ?? '' ) ) {
			return false;
		}
		foreach ( $short as $size ) {
			if ( (string) $size['slug'] === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The rows of the map (pure): one per term of the attribute, one per
	 * size found on a variation that has no term any more, and one per
	 * size typed on a fish product — each with how much uses it, what the
	 * rule suggests and what it becomes (the owner's choice when it is a
	 * valid one).
	 *
	 * @param array   $shop    Sizes_Tidy_Site::read_shop().
	 * @param array[] $short   short_list().
	 * @param array   $request over (row key → action: the rows the owner set by hand), keep_plus (bool).
	 * @return array[] key, slug, label, typed, orphan, products, variations, stores (name → n), orders, kind, auto, action, explicit, flags.
	 */
	public static function rows( array $shop, array $short, array $request = array() ) {
		$taxonomy = (string) ( $shop['taxonomy'] ?? '' );
		$over     = isset( $request['over'] ) && is_array( $request['over'] ) ? $request['over'] : array();
		$retiring = isset( $shop['state']['retiring'] ) && is_array( $shop['state']['retiring'] ) ? $shop['state']['retiring'] : array();

		$retired  = isset( $shop['state']['retired'] ) && is_array( $shop['state']['retired'] ) ? $shop['state']['retired'] : array();

		$rows = array();
		foreach ( (array) ( $shop['terms'] ?? array() ) as $term ) {
			$slug = (string) ( $term['slug'] ?? '' );
			if ( '' === $slug || isset( $rows[ 'g:' . $slug ] ) ) {
				continue;
			}
			$name                 = Sizes::plain( (string) ( $term['name'] ?? '' ) );
			$rows[ 'g:' . $slug ] = self::blank_row( 'g:' . $slug, $slug, '' !== $name ? $name : $slug, false );
		}

		$seen = array();
		foreach ( (array) ( $shop['products'] ?? array() ) as $pid => $product ) {
			foreach ( (array) ( $product['sizes'] ?? array() ) as $slug ) {
				$key = 'g:' . $slug;
				if ( ! isset( $rows[ $key ] ) ) {
					// A size without a term: it reads as the name a tidy-up remembers for it, else as its slug.
					$rows[ $key ]           = self::blank_row( $key, (string) $slug, isset( $retired[ $slug ] ) ? (string) $retired[ $slug ] : (string) $slug, false );
					$rows[ $key ]['orphan'] = true;
				}
				$seen[ $key ][ $pid ] = true;
			}
			$typed = isset( $product['typed'] ) && is_array( $product['typed'] ) ? $product['typed'] : null;
			foreach ( (array) ( $product['variations'] ?? array() ) as $variation ) {
				$slug = (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
				if ( '' !== $slug ) {
					$key = 'g:' . $slug;
					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ]           = self::blank_row( $key, $slug, isset( $retired[ $slug ] ) ? (string) $retired[ $slug ] : $slug, false );
						$rows[ $key ]['orphan'] = true;
					}
					$seen[ $key ][ $pid ] = true;
					$rows[ $key ]['variations']++;
				}
				$value = null !== $typed ? (string) ( $variation['attrs'][ (string) $typed['key'] ] ?? '' ) : '';
				if ( '' !== $value && '' !== Sizes::slug( $value ) ) {
					$key = 't:' . Sizes::slug( $value );
					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ] = self::blank_row( $key, Sizes::slug( $value ), Sizes::plain( $value ), true );
					}
					$seen[ $key ][ $pid ] = true;
					$rows[ $key ]['variations']++;
				}
			}
			foreach ( null !== $typed ? (array) ( $typed['options'] ?? array() ) : array() as $value ) {
				if ( '' === Sizes::slug( (string) $value ) ) {
					continue;
				}
				$key = 't:' . Sizes::slug( (string) $value );
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = self::blank_row( $key, Sizes::slug( (string) $value ), Sizes::plain( (string) $value ), true );
				}
				$seen[ $key ][ $pid ] = true;
			}
		}

		$out = array();
		foreach ( $rows as $key => $row ) {
			$row['products'] = isset( $seen[ $key ] ) ? count( $seen[ $key ] ) : 0;
			if ( ! $row['typed'] ) {
				foreach ( (array) ( $shop['stores'] ?? array() ) as $store => $counts ) {
					$count = (int) ( $counts[ $row['slug'] ] ?? 0 );
					if ( $count > 0 ) {
						$row['stores'][ (string) $store ] = $count;
					}
				}
				$row['orders'] = (int) ( $shop['orders'][ $row['slug'] ] ?? 0 );
			}

			$row['kind'] = self::parse( $row['label'] )['kind'];
			$suggested   = self::suggest(
				array(
					'slug'     => $row['slug'],
					'label'    => $row['label'],
					'typed'    => $row['typed'],
					'used'     => $row['products'] > 0 || $row['variations'] > 0,
					'retiring' => ! $row['typed'] && isset( $retiring[ $row['slug'] ] ),
				),
				$short
			);
			if ( $row['orphan'] && self::STAY !== $suggested['action'] ) {
				$suggested['flags'][] = 'orphan';
			}
			$row['auto']   = $suggested['action'];
			$row['action'] = $suggested['action'];
			$row['flags']  = $suggested['flags'];

			if ( self::STAY !== $row['auto'] ) {
				if ( array_key_exists( $key, $over ) ) {
					if ( self::valid_action( $over[ $key ], $row, $short ) ) {
						$row['explicit'] = true;
						if ( $over[ $key ] !== $row['auto'] ) {
							$row['action']  = (string) $over[ $key ];
							$row['flags']   = array_values( array_diff( $row['flags'], array( 'word', 'between', 'unused', 'below', 'nofit' ) ) );
							$row['flags'][] = 'yours';
						}
					} else {
						$row['flags'][] = 'gone';
					}
				} elseif ( ! empty( $request['keep_plus'] ) && 'plus' === $row['kind'] && ! $row['typed'] ) {
					$row['action'] = self::KEEP;
				}
			}
			$out[] = $row;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['typed'] !== $b['typed'] ) {
					return $a['typed'] ? 1 : -1;
				}
				$words = ( 'word' === $a['kind'] ) <=> ( 'word' === $b['kind'] );
				if ( 0 !== $words ) {
					return $words;
				}
				$by_label = strnatcasecmp( $a['label'], $b['label'] );
				return 0 !== $by_label ? $by_label : strcmp( $a['key'], $b['key'] );
			}
		);

		return $out;
	}

	/**
	 * An empty row of the map.
	 *
	 * @param string $key   Row key.
	 * @param string $slug  Slug.
	 * @param string $label Label.
	 * @param bool   $typed Typed on a product.
	 * @return array
	 */
	private static function blank_row( $key, $slug, $label, $typed ) {
		return array(
			'key'        => $key,
			'slug'       => $slug,
			'label'      => $label,
			'typed'      => (bool) $typed,
			'orphan'     => false,
			'products'   => 0,
			'variations' => 0,
			'stores'     => array(),
			'orders'     => 0,
			'kind'       => 'word',
			'auto'       => self::KEEP,
			'action'     => self::KEEP,
			'explicit'   => false,
			'flags'      => array(),
		);
	}

	/* ============================================================== */
	/*  The plan (the dry run)                                         */
	/* ============================================================== */

	/**
	 * Whether a variation can be bought today: its product and itself
	 * are published, it has a price and it is in stock or on backorder.
	 *
	 * @param array $product   status.
	 * @param array $variation status, price, stock_status.
	 * @return bool
	 */
	public static function for_sale( array $product, array $variation ) {
		return 'publish' === (string) ( $product['status'] ?? '' ) && self::enabled( $variation )
			&& 'outofstock' !== (string) ( $variation['stock_status'] ?? 'instock' );
	}

	/**
	 * Whether a variation is switched on: published, with a price.
	 *
	 * @param array $variation status, price.
	 * @return bool
	 */
	public static function enabled( array $variation ) {
		return 'publish' === (string) ( $variation['status'] ?? '' ) && '' !== trim( (string) ( $variation['price'] ?? '' ) );
	}

	/**
	 * A variation's stock in words.
	 *
	 * @param array $variation stock (int|null), stock_status.
	 * @return string
	 */
	public static function stock_text( array $variation ) {
		$status = (string) ( $variation['stock_status'] ?? 'instock' );
		$stock  = isset( $variation['stock'] ) && is_numeric( $variation['stock'] ) ? (int) $variation['stock'] : null;
		if ( 'onbackorder' === $status ) {
			return null !== $stock ? sprintf( /* translators: %d: stock */ __( 'on backorder (%d)', 'fishotel-misc-plugin' ), $stock ) : __( 'on backorder', 'fishotel-misc-plugin' );
		}
		if ( 'outofstock' === $status ) {
			return null !== $stock && 0 !== $stock ? sprintf( /* translators: %d: stock */ __( 'out of stock (%d)', 'fishotel-misc-plugin' ), $stock ) : __( 'out of stock', 'fishotel-misc-plugin' );
		}
		return null !== $stock ? sprintf( /* translators: %d: stock */ __( '%d in stock', 'fishotel-misc-plugin' ), $stock ) : __( 'in stock', 'fishotel-misc-plugin' );
	}

	/**
	 * The plan — the dry run (pure, nothing is written): what every size
	 * becomes, what that does to every product and variation, the groups
	 * the tool calls out, the terms that would be created and deleted and
	 * the totals. `products` is exactly what Apply does, product by
	 * product; `token` changes whenever the plan does.
	 *
	 * @param array $shop    Sizes_Tidy_Site::read_shop().
	 * @param array $request short, over, keep_plus, instock, move (variation id → slug),
	 *                       keep_one (product id → group → variation id), convert (product id → 1).
	 * @return array
	 */
	public static function plan( array $shop, array $request = array() ) {
		$taxonomy = (string) ( $shop['taxonomy'] ?? '' );
		$terms    = array();
		foreach ( (array) ( $shop['terms'] ?? array() ) as $term ) {
			if ( '' !== (string) ( $term['slug'] ?? '' ) ) {
				$terms[ (string) $term['slug'] ] = $term;
			}
		}
		$short  = self::short_list( $request['short'] ?? ( $shop['state']['short'] ?? array() ), array_values( $terms ) );
		$rows   = self::rows( $shop, $short, $request );
		$labels = array();
		foreach ( $rows as $row ) {
			if ( ! $row['typed'] ) {
				$labels[ $row['slug'] ] = $row['label'];
			}
		}
		$in_short = array();
		foreach ( $short as $size ) {
			$labels[ $size['slug'] ]   = $size['label'];
			$in_short[ $size['slug'] ] = true;
		}
		$map       = array();
		$typed_map = array();
		foreach ( $rows as $row ) {
			if ( $row['typed'] ) {
				$typed_map[ $row['slug'] ] = $row['action'];
			} else {
				$map[ $row['slug'] ] = $row['action'];
			}
		}

		$instock = (string) ( $request['instock'] ?? self::IN_STOCK_LEAVE );
		if ( ! in_array( $instock, array( self::IN_STOCK_LEAVE, self::IN_STOCK_MOVE, self::IN_STOCK_OFF ), true ) ) {
			$instock = self::IN_STOCK_LEAVE;
		}
		$move     = isset( $request['move'] ) && is_array( $request['move'] ) ? $request['move'] : array();
		$keep_one = isset( $request['keep_one'] ) && is_array( $request['keep_one'] ) ? $request['keep_one'] : array();
		$convert  = isset( $request['convert'] ) && is_array( $request['convert'] ) ? $request['convert'] : array();

		$products    = array();
		$changes     = array();
		$two         = array();
		$typed_list  = array();
		$unavailable = array();
		$used        = array();
		$left        = array();
		$stuck       = array();

		foreach ( (array) ( $shop['products'] ?? array() ) as $pid => $product ) {
			$pid   = (int) $pid;
			$title = (string) ( $product['title'] ?? '' );
			$vars  = isset( $product['variations'] ) && is_array( $product['variations'] ) ? $product['variations'] : array();
			$typed = isset( $product['typed'] ) && is_array( $product['typed'] ) ? $product['typed'] : null;
			$sizes = array_values( array_map( 'strval', (array) ( $product['sizes'] ?? array() ) ) );

			// What each variation would become.
			$next = array();
			foreach ( $vars as $vid => $variation ) {
				$from = (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
				if ( '' === $from ) {
					continue;
				}
				$action = isset( $map[ $from ] ) ? $map[ $from ] : self::KEEP;
				$entry  = array(
					'from' => $from,
					'to'   => $from,
					'off'  => false,
					'why'  => '',
				);
				if ( 0 === strpos( $action, self::TO ) ) {
					$entry['to']  = substr( $action, strlen( self::TO ) );
					$entry['why'] = 'rename';
				} elseif ( self::REMOVE === $action ) {
					if ( ! self::for_sale( $product, $variation ) || self::IN_STOCK_OFF === $instock ) {
						$entry['to']  = '';
						$entry['off'] = true;
						$entry['why'] = self::for_sale( $product, $variation ) ? 'off_now' : 'off_out';
					} elseif ( self::IN_STOCK_MOVE === $instock && isset( $move[ $vid ] ) && is_scalar( $move[ $vid ] ) && isset( $in_short[ (string) $move[ $vid ] ] ) ) {
						$entry['to']  = (string) $move[ $vid ];
						$entry['why'] = 'move';
					} else {
						$entry['why'] = 'left';
					}
				}
				$next[ (int) $vid ] = $entry;
			}

			// Two (or more) switched-on variations that would end up the same.
			$groups = array();
			foreach ( $next as $vid => $entry ) {
				if ( '' === $entry['to'] ) {
					continue;
				}
				$tuple              = (array) $vars[ $vid ]['attrs'];
				$tuple[ $taxonomy ] = $entry['to'];
				ksort( $tuple );
				$groups[ substr( md5( (string) wp_json_encode( $tuple ) ), 0, 10 ) ][] = $vid;
			}
			$clash    = array();
			$resolved = true;
			foreach ( $groups as $gkey => $members ) {
				$on      = 0;
				$changed = false;
				foreach ( $members as $vid ) {
					$on     += self::enabled( $vars[ $vid ] ) ? 1 : 0;
					$changed = $changed || $next[ $vid ]['to'] !== $next[ $vid ]['from'];
				}
				if ( count( $members ) < 2 || $on < 2 || ! $changed ) {
					continue;
				}
				$picked = isset( $keep_one[ $pid ] ) && is_array( $keep_one[ $pid ] ) ? (int) ( $keep_one[ $pid ][ $gkey ] ?? 0 ) : 0;
				if ( ! in_array( $picked, $members, true ) ) {
					$picked   = 0;
					$resolved = false;
				}
				$clash[ $gkey ] = array(
					'to'      => $next[ $members[0] ]['to'],
					'members' => $members,
					'picked'  => $picked,
				);
			}
			if ( $clash ) {
				$view = array(
					'id'       => $pid,
					'title'    => $title,
					'resolved' => $resolved,
					'groups'   => array(),
				);
				foreach ( $clash as $gkey => $group ) {
					$members = array();
					foreach ( $group['members'] as $vid ) {
						$members[] = self::variation_view( $vars[ $vid ], $vid ) + array(
							'from'       => $next[ $vid ]['from'],
							'from_label' => isset( $labels[ $next[ $vid ]['from'] ] ) ? $labels[ $next[ $vid ]['from'] ] : $next[ $vid ]['from'],
							'picked'     => $group['picked'] === $vid,
						);
					}
					$view['groups'][ $gkey ] = array(
						'to'       => $group['to'],
						'to_label' => isset( $labels[ $group['to'] ] ) ? $labels[ $group['to'] ] : $group['to'],
						'members'  => $members,
					);
				}
				$two[ $pid ] = $view;

				if ( ! $resolved ) {
					// Left untouched, whole.
					foreach ( $next as $vid => $entry ) {
						if ( $entry['to'] !== $entry['from'] && isset( $map[ $entry['from'] ] ) && self::KEEP !== $map[ $entry['from'] ] ) {
							$stuck[ $entry['from'] ][ $pid ] = true;
						}
						$used[ $entry['from'] ] = ( $used[ $entry['from'] ] ?? 0 ) + 1;
					}
					foreach ( $sizes as $slug ) {
						$used[ $slug ] = ( $used[ $slug ] ?? 0 ) + 1;
						if ( isset( $map[ $slug ] ) && ! in_array( $map[ $slug ], array( self::KEEP, self::STAY ), true ) ) {
							$stuck[ $slug ][ $pid ] = true;
						}
					}
					continue;
				}
				foreach ( $clash as $group ) {
					foreach ( $group['members'] as $vid ) {
						if ( $vid !== $group['picked'] && self::enabled( $vars[ $vid ] ) ) {
							$next[ $vid ]['off'] = true;
							$next[ $vid ]['why'] = 'dupe_off';
						}
					}
				}
			}

			// A size typed on the product instead of the attribute's term.
			$converted = null;
			if ( null !== $typed ) {
				$view = array(
					'id'     => $pid,
					'title'  => $title,
					'ticked' => ! empty( $convert[ $pid ] ),
					'ok'     => true,
					'why'    => '',
					'values' => array(),
				);
				$to     = array();
				$values = array();
				foreach ( (array) ( $typed['options'] ?? array() ) as $value ) {
					$values[ (string) $value ] = true;
				}
				foreach ( $vars as $variation ) {
					$value = (string) ( $variation['attrs'][ (string) $typed['key'] ] ?? '' );
					if ( '' !== $value ) {
						$values[ $value ] = true;
					}
				}
				foreach ( array_keys( $values ) as $value ) {
					$action = isset( $typed_map[ Sizes::slug( (string) $value ) ] ) ? $typed_map[ Sizes::slug( (string) $value ) ] : self::KEEP;
					$target = 0 === strpos( $action, self::TO ) ? substr( $action, strlen( self::TO ) ) : null;
					if ( null === $target ) {
						$view['ok']  = false;
						$view['why'] = 'nosize';
					} else {
						$to[ (string) $value ] = $target;
					}
					$view['values'][] = array(
						'value'    => (string) $value,
						'to'       => $target,
						'to_label' => null !== $target && isset( $labels[ $target ] ) ? $labels[ $target ] : '',
					);
				}
				if ( $view['ok'] && ( $next || $sizes ) ) {
					$view['ok']  = false;
					$view['why'] = 'both';
				}
				if ( $view['ok'] ) {
					$tuples = array();
					foreach ( $vars as $vid => $variation ) {
						$value = (string) ( $variation['attrs'][ (string) $typed['key'] ] ?? '' );
						if ( '' === $value || ! self::enabled( $variation ) ) {
							continue;
						}
						$tuple = (array) $variation['attrs'];
						unset( $tuple[ (string) $typed['key'] ] );
						$tuple[ $taxonomy ] = $to[ $value ];
						ksort( $tuple );
						$tuple = md5( (string) wp_json_encode( $tuple ) );
						if ( isset( $tuples[ $tuple ] ) ) {
							$view['ok']  = false;
							$view['why'] = 'two';
						}
						$tuples[ $tuple ] = true;
					}
				}
				$typed_list[ $pid ] = $view;

				if ( $view['ok'] && $view['ticked'] ) {
					$converted = array(
						'key'    => (string) $typed['key'],
						'values' => $to,
					);
					foreach ( $vars as $vid => $variation ) {
						$value = (string) ( $variation['attrs'][ (string) $typed['key'] ] ?? '' );
						if ( '' !== $value ) {
							$next[ (int) $vid ] = array(
								'from' => $value,
								'to'   => $to[ $value ],
								'off'  => false,
								'why'  => 'convert',
							);
						}
					}
				}
			}

			// The product's own list of sizes, in step with its variations.
			$after  = array();
			$holds  = array();
			$lost   = array();
			$change = array();
			foreach ( $next as $vid => $entry ) {
				if ( '' !== $entry['to'] ) {
					$holds[ $entry['to'] ] = true;
				}
				if ( $entry['to'] !== $entry['from'] || $entry['off'] ) {
					$change[ $vid ] = $entry;
				}
				if ( '' === $entry['to'] ) {
					$lost[ $vid ] = array(
						'slug'  => $entry['from'],
						'label' => isset( $labels[ $entry['from'] ] ) ? $labels[ $entry['from'] ] : $entry['from'],
					);
				}
				if ( 'left' === $entry['why'] ) {
					$left[ $entry['from'] ] = ( $left[ $entry['from'] ] ?? 0 ) + 1;
					$changes[]              = self::variation_view( $vars[ $vid ], $vid ) + array(
						'product'    => $pid,
						'title'      => $title,
						'fish'       => ! empty( $product['fish'] ),
						'from'       => $entry['from'],
						'from_label' => isset( $labels[ $entry['from'] ] ) ? $labels[ $entry['from'] ] : $entry['from'],
						'to'         => $entry['from'],
						'to_label'   => isset( $labels[ $entry['from'] ] ) ? $labels[ $entry['from'] ] : $entry['from'],
						'off'        => false,
						'why'        => 'left',
						'for_sale'   => true,
					);
				}
			}
			if ( null !== $converted ) {
				foreach ( $converted['values'] as $target ) {
					if ( ! in_array( $target, $after, true ) ) {
						$after[] = $target;
					}
				}
			} else {
				foreach ( $sizes as $slug ) {
					$action = isset( $map[ $slug ] ) ? $map[ $slug ] : self::KEEP;
					$to     = $slug;
					if ( 0 === strpos( $action, self::TO ) ) {
						$to = substr( $action, strlen( self::TO ) );
					} elseif ( self::REMOVE === $action && ! isset( $holds[ $slug ] ) ) {
						continue;
					}
					if ( isset( $holds[ $slug ] ) && ! in_array( $slug, $after, true ) && $to !== $slug ) {
						$after[] = $slug; // A variation still carries the old size.
					}
					if ( ! in_array( $to, $after, true ) ) {
						$after[] = $to;
					}
				}
				foreach ( array_keys( $holds ) as $slug ) {
					if ( ! in_array( (string) $slug, $after, true ) ) {
						$after[] = (string) $slug;
					}
				}
			}

			$default = null;
			$current = (string) ( $product['default'] ?? '' );
			if ( null !== $converted ) {
				$typed_default = (string) ( $typed['default'] ?? '' );
				$default       = '' !== $typed_default && isset( $converted['values'][ $typed_default ] ) ? $converted['values'][ $typed_default ] : '';
			} elseif ( '' !== $current ) {
				$action = isset( $map[ $current ] ) ? $map[ $current ] : self::KEEP;
				$to     = 0 === strpos( $action, self::TO ) ? substr( $action, strlen( self::TO ) ) : $current;
				if ( ! in_array( $to, $after, true ) ) {
					$to = '';
				}
				$default = $to !== $current ? $to : null;
			}

			foreach ( $next as $entry ) {
				if ( '' !== $entry['to'] ) {
					$used[ $entry['to'] ] = ( $used[ $entry['to'] ] ?? 0 ) + 1;
				}
			}
			foreach ( $after as $slug ) {
				$used[ $slug ] = ( $used[ $slug ] ?? 0 ) + 1;
			}

			if ( ! $change && $after === $sizes && null === $default && null === $converted ) {
				continue;
			}

			$fill = isset( $product['fill'] ) && is_array( $product['fill'] ) ? $product['fill'] : array();
			foreach ( $lost as $vid => $entry ) {
				$fill[ $vid ] = $entry;
			}
			$products[ $pid ] = array(
				'id'      => $pid,
				'vars'    => $change,
				'sizes'   => $after,
				'default' => $default,
				'convert' => $converted,
				'fill'    => $lost ? $fill : null,
			);

			$on_before = 0;
			$on_after  = 0;
			foreach ( $vars as $vid => $variation ) {
				$on         = self::enabled( $variation );
				$on_before += $on ? 1 : 0;
				$on_after  += $on && empty( $change[ $vid ]['off'] ) ? 1 : 0;
			}
			if ( $on_before > 0 && 0 === $on_after && 'publish' === (string) ( $product['status'] ?? '' ) ) {
				$unavailable[ $pid ] = array(
					'id'    => $pid,
					'title' => $title,
				);
			}

			foreach ( $change as $vid => $entry ) {
				$changes[] = self::variation_view( $vars[ $vid ], $vid ) + array(
					'product'    => $pid,
					'title'      => $title,
					'fish'       => ! empty( $product['fish'] ),
					'from'       => $entry['from'],
					'from_label' => isset( $labels[ $entry['from'] ] ) ? $labels[ $entry['from'] ] : $entry['from'],
					'to'         => $entry['to'],
					'to_label'   => '' !== $entry['to'] && isset( $labels[ $entry['to'] ] ) ? $labels[ $entry['to'] ] : $entry['to'],
					'off'        => $entry['off'],
					'why'        => $entry['why'],
					'for_sale'   => self::for_sale( $product, $vars[ $vid ] ),
				);
			}
		}

		// The terms: which are created, which go, which wait.
		$create = array();
		foreach ( $short as $size ) {
			if ( ! $size['exists'] ) {
				$create[] = array(
					'slug'  => $size['slug'],
					'label' => $size['label'],
				);
			}
		}
		$delete   = array();
		$retiring = array();
		$waiting  = array();
		foreach ( $rows as $index => $row ) {
			if ( $row['typed'] || in_array( $row['action'], array( self::STAY, self::KEEP ), true ) ) {
				continue;
			}
			$still = (int) ( $used[ $row['slug'] ] ?? 0 );
			if ( 0 === $still ) {
				if ( isset( $terms[ $row['slug'] ] ) ) {
					$delete[] = $row['slug'];
				}
				$rows[ $index ]['after'] = 'deleted';
			} elseif ( ! empty( $left[ $row['slug'] ] ) ) {
				$retiring[ $row['slug'] ] = $row['label'];
				$rows[ $index ]['after']  = 'retiring';
				$rows[ $index ]['left']   = (int) $left[ $row['slug'] ];
			} else {
				$waiting[ $row['slug'] ] = $row['label'];
				$rows[ $index ]['after'] = 'waiting';
				$rows[ $index ]['stuck'] = isset( $stuck[ $row['slug'] ] ) ? count( $stuck[ $row['slug'] ] ) : 0;
			}
		}

		// Where else a size is stored: only a size that BECOMES another is rewritten there.
		$targets = array();
		foreach ( $map as $slug => $action ) {
			if ( 0 === strpos( $action, self::TO ) ) {
				$to               = substr( $action, strlen( self::TO ) );
				$targets[ $slug ] = array(
					'slug'  => $to,
					'label' => isset( $labels[ $to ] ) ? $labels[ $to ] : $to,
				);
			}
		}
		$packing = self::remap_packing( isset( $shop['packing'] ) && is_array( $shop['packing'] ) ? $shop['packing'] : array(), $targets );

		$renamed = 0;
		$off     = 0;
		$kept    = 0;
		foreach ( $changes as $change ) {
			if ( 'left' === $change['why'] ) {
				++$kept;
				continue;
			}
			$off     += $change['off'] ? 1 : 0;
			$renamed += '' !== $change['to'] && $change['to'] !== $change['from'] ? 1 : 0;
		}
		$look = 0;
		foreach ( $two as $view ) {
			$look += $view['resolved'] ? 0 : 1;
		}
		$typed_waiting = 0;
		foreach ( $typed_list as $view ) {
			$typed_waiting += isset( $products[ $view['id'] ]['convert'] ) && null !== $products[ $view['id'] ]['convert'] ? 0 : 1;
		}

		$plan = array(
			'taxonomy'    => $taxonomy,
			'short'       => $short,
			'rows'        => $rows,
			'map'         => $map,
			'targets'     => $targets,
			'labels'      => $labels,
			'instock'     => $instock,
			'keep_plus'   => ! empty( $request['keep_plus'] ),
			'products'    => $products,
			'changes'     => $changes,
			'two'         => $two,
			'typed'       => $typed_list,
			'unavailable' => $unavailable,
			'create'      => $create,
			'delete'      => $delete,
			'retiring'    => $retiring,
			'waiting'     => $waiting,
			'packing'     => $packing,
			'totals'      => array(
				'sizes_before' => count( $terms ),
				'sizes_after'  => count( $terms ) - count( $delete ) + count( $create ),
				'short'        => count( $short ),
				'renamed'      => $renamed,
				'off'          => $off,
				'left'         => $kept,
				'look'         => $look,
				'typed'        => $typed_waiting,
				'products'     => count( $products ),
				'unavailable'  => count( $unavailable ),
				'deleted'      => 0, // Variations deleted: never.
			),
		);

		$plan['nothing'] = ! $products && ! $create && ! $delete && ! self::store_work( $shop, $targets ) && ! $packing['changed']
			&& self::short_labels( $short ) === array_values( (array) ( $shop['state']['short'] ?? array() ) )
			&& 'menu_order' === (string) ( $shop['orderby'] ?? 'menu_order' )
			&& self::order_now( $terms ) === self::order_after( $plan, self::order_now( $terms ) )
			&& $retiring == ( isset( $shop['state']['retiring'] ) ? (array) $shop['state']['retiring'] : array() ) // phpcs:ignore Universal.Operators.StrictComparisons -- the order of the keys does not matter.
			&& $waiting == ( isset( $shop['state']['waiting'] ) ? (array) $shop['state']['waiting'] : array() ); // phpcs:ignore Universal.Operators.StrictComparisons
		$plan['token']   = md5( (string) wp_json_encode( array( $taxonomy, self::short_labels( $short ), $map, $typed_map, $products, $create, $delete, $retiring, $waiting, $shop['stores'] ?? array(), $packing['map'] ) ) );

		return $plan;
	}

	/**
	 * A variation as the preview shows it.
	 *
	 * @param array $variation The variation.
	 * @param int   $vid       Its id.
	 * @return array id, price, stock, stock_text, sku, on.
	 */
	private static function variation_view( array $variation, $vid ) {
		return array(
			'id'         => (int) $vid,
			'price'      => (string) ( $variation['price'] ?? '' ),
			'stock'      => isset( $variation['stock'] ) && is_numeric( $variation['stock'] ) ? (int) $variation['stock'] : null,
			'stock_text' => self::stock_text( $variation ),
			'sku'        => (string) ( $variation['sku'] ?? '' ),
			'on'         => self::enabled( $variation ),
		);
	}

	/**
	 * The labels of a short list, in order.
	 *
	 * @param array[] $short short_list().
	 * @return string[]
	 */
	public static function short_labels( array $short ) {
		$out = array();
		foreach ( $short as $size ) {
			$out[] = (string) $size['label'];
		}
		return $out;
	}

	/**
	 * Whether a store holds a size that becomes another.
	 *
	 * @param array $shop    read_shop().
	 * @param array $targets old slug → slug, label.
	 * @return bool
	 */
	private static function store_work( array $shop, array $targets ) {
		foreach ( (array) ( $shop['stores'] ?? array() ) as $counts ) {
			foreach ( array_keys( $targets ) as $slug ) {
				if ( ! empty( $counts[ $slug ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The terms' slugs in the order they are in today.
	 *
	 * @param array[] $terms slug → term (menu_order, name, term_id).
	 * @return string[]
	 */
	public static function order_now( array $terms ) {
		$out = array();
		foreach ( Sizes::order_terms( array_values( $terms ), 'menu_order' ) as $term ) {
			$out[] = (string) $term['slug'];
		}
		return $out;
	}

	/**
	 * The order the attribute is given: the short list first, in its
	 * order, then whatever else is left, as it was.
	 *
	 * @param array    $plan    plan() (short, delete).
	 * @param string[] $current The slugs there now, in their order (order_now()).
	 * @return string[] Slugs.
	 */
	public static function order_after( array $plan, array $current ) {
		$out = array();
		foreach ( (array) $plan['short'] as $size ) {
			$out[] = (string) $size['slug'];
		}
		foreach ( $current as $slug ) {
			if ( ! in_array( (string) $slug, $out, true ) && ! in_array( (string) $slug, (array) $plan['delete'], true ) ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/* ============================================================== */
	/*  The other places a size is stored                              */
	/* ============================================================== */

	/**
	 * A stored row after the map (pure): its size_slug / size_label when
	 * its size becomes another — and, for a lost-fish case without a
	 * product, the kind key that ends in the slug. Null when nothing
	 * changes. A size that is taken off is NOT rewritten: the fish in the
	 * tanks keep the size they were given.
	 *
	 * @param array $row     size_slug, size_label, kind_key (optional), product_id (optional).
	 * @param array $targets old slug → slug, label.
	 * @return array|null The fields to write.
	 */
	public static function remap_row( array $row, array $targets ) {
		$slug = isset( $row['size_slug'] ) && is_scalar( $row['size_slug'] ) ? (string) $row['size_slug'] : '';
		if ( '' === $slug || ! isset( $targets[ $slug ] ) ) {
			return null;
		}
		$out = array(
			'size_slug'  => (string) $targets[ $slug ]['slug'],
			'size_label' => (string) $targets[ $slug ]['label'],
		);
		if ( isset( $row['kind_key'] ) && 0 === strpos( (string) $row['kind_key'], 's:' ) && (int) ( $row['product_id'] ?? 0 ) <= 0 ) {
			$tail = '|' . strtolower( trim( $slug ) );
			$key  = (string) $row['kind_key'];
			if ( strlen( $key ) >= strlen( $tail ) && substr( $key, -strlen( $tail ) ) === $tail ) {
				$out['kind_key'] = substr( substr( $key, 0, -strlen( $tail ) ) . '|' . strtolower( $out['size_slug'] ), 0, 191 );
			}
		}
		return $out;
	}

	/**
	 * The remembered box shares after the map (pure). A share's key is
	 * made of its place, its fish and its size, so a share whose size
	 * becomes another gets a new key — and two shares that land on one
	 * key become one: the latest wins (its percent, its last line), the
	 * order counts add up.
	 *
	 * @param array[] $rows    The shares concerned: those whose size is mapped AND those already
	 *                         holding a key one of them lands on (id, share_key, location_key,
	 *                         fish_key, size_slug, size_label, order_count, last_date, …).
	 * @param array   $targets old slug → slug, label.
	 * @return array{update: array[], delete: int[], keys: array<string,string>} update: id → fields; keys: old key → new key.
	 */
	public static function remap_shares( array $rows, array $targets ) {
		$groups = array();
		$keys   = array();
		foreach ( $rows as $row ) {
			$slug = (string) ( $row['size_slug'] ?? '' );
			$new  = $row;
			if ( '' !== $slug && isset( $targets[ $slug ] ) ) {
				$new['size_slug']  = (string) $targets[ $slug ]['slug'];
				$new['size_label'] = (string) $targets[ $slug ]['label'];
				$new['share_key']  = Box_Shares::share_key( (string) ( $row['location_key'] ?? '' ), (string) ( $row['fish_key'] ?? '' ), $new['size_slug'] );
				$keys[ (string) $row['share_key'] ] = $new['share_key'];
			}
			$groups[ (string) $new['share_key'] ][] = array( $row, $new );
		}

		$update = array();
		$delete = array();
		foreach ( $groups as $members ) {
			usort(
				$members,
				static function ( $a, $b ) {
					$by_date = strcmp( (string) ( $b[0]['last_date'] ?? '' ), (string) ( $a[0]['last_date'] ?? '' ) );
					return 0 !== $by_date ? $by_date : (int) $b[0]['id'] <=> (int) $a[0]['id'];
				}
			);
			$winner = $members[0];
			$count  = 0;
			foreach ( $members as $member ) {
				$count += (int) ( $member[0]['order_count'] ?? 0 );
			}
			$fields = array();
			foreach ( array( 'size_slug', 'size_label', 'share_key' ) as $key ) {
				if ( (string) $winner[0][ $key ] !== (string) $winner[1][ $key ] ) {
					$fields[ $key ] = $winner[1][ $key ];
				}
			}
			if ( count( $members ) > 1 && $count !== (int) ( $winner[0]['order_count'] ?? 0 ) ) {
				$fields['order_count'] = $count;
			}
			if ( $fields ) {
				$update[ (int) $winner[0]['id'] ] = $fields;
			}
			foreach ( array_slice( $members, 1 ) as $member ) {
				$delete[] = (int) $member[0]['id'];
				// A share that was on the key already and loses to a newer one: its lines follow too.
				$keys[ (string) $member[0]['share_key'] ] = (string) $winner[1]['share_key'];
			}
		}
		foreach ( $keys as $old => $new ) {
			if ( $old === $new ) {
				unset( $keys[ $old ] );
			}
		}

		return array(
			'update' => $update,
			'delete' => $delete,
			'keys'   => $keys,
		);
	}

	/**
	 * The Packing section's size → bag map after the map (pure): a size
	 * that becomes another hands its bag on, unless the new size has one
	 * already. Two sizes with different bags that become one are named.
	 *
	 * @param array $map     size slug → bag id.
	 * @param array $targets old slug → slug, label.
	 * @return array{map: array, changed: bool, clashes: array[]} clashes: to, kept, dropped (slug → bag).
	 */
	public static function remap_packing( array $map, array $targets ) {
		$out     = $map;
		$clashes = array();
		foreach ( $map as $slug => $bag ) {
			$slug = (string) $slug;
			if ( ! isset( $targets[ $slug ] ) ) {
				continue;
			}
			$to = (string) $targets[ $slug ]['slug'];
			unset( $out[ $slug ] );
			if ( ! isset( $out[ $to ] ) ) {
				$out[ $to ] = $bag;
			} elseif ( (string) $out[ $to ] !== (string) $bag ) {
				$clashes[] = array(
					'to'      => $to,
					'kept'    => (string) $out[ $to ],
					'from'    => $slug,
					'dropped' => (string) $bag,
				);
			}
		}

		return array(
			'map'     => $out,
			'changed' => $out !== $map,
			'clashes' => $clashes,
		);
	}
}
