<?php
/**
 * Med listings — the composer: writes the plain listing.
 *
 * From a product's own facts only — its name, its active-ingredient
 * field, whether it is a food, its form, the food base and the sizes
 * of its variations, who ships it — it writes the description, the
 * short description and the search-engine description, e.g.
 *
 *   General Trio Flake
 *   Flake food containing kanamycin, metronidazole, and nitrofurazone.
 *   Available in omnivore or herbivore flake, 2 oz to 3 lb.
 *   Keep sealed, store cool and dry. Not for human consumption. Keep out of reach of children.
 *   Ships direct from our supplier.
 *
 * Ingredients are never guessed: with the field empty the line carries
 * BLANK, which the Apply button refuses until the owner fills it in.
 *
 * Pure: no WordPress call.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Composer
 */
class Composer {

	/**
	 * What stands where the ingredients go when the product has none.
	 * Anything holding "[[" cannot be applied.
	 *
	 * @var string
	 */
	const BLANK = '[[fill in the active ingredients — confirm against the EA product]]';

	/**
	 * An active-ingredient field that says the product has none ("None
	 * (test kit)", "n/a", "-"): no "Contains" line is written, and the
	 * product is flagged for the owner instead.
	 *
	 * @var string
	 */
	const NONE = '/^\s*(?:none|n\/?a|no active ingredients?|not applicable|-+|—)\b/iu';

	/**
	 * Forms a product can be, read from its food base or its name.
	 *
	 * @var array<string, string> pattern => form
	 */
	const FORMS = array(
		'flakes?'           => 'flake',
		'pellets?'          => 'pellet',
		'gel'               => 'gel',
		'granules?'         => 'granule',
		'powders?'          => 'powder',
		'tablets?|tabs'     => 'tablet',
		'capsules?'         => 'capsule',
		'liquid|solution'   => 'liquid',
	);

	/**
	 * Size units → how much of the base unit one is (weights in grams,
	 * volumes in millilitres, counts in pieces).
	 *
	 * @var array<string, array{0: string, 1: float}>
	 */
	const UNITS = array(
		'mg'      => array( 'g', 0.001 ),
		'g'       => array( 'g', 1.0 ),
		'gm'      => array( 'g', 1.0 ),
		'gram'    => array( 'g', 1.0 ),
		'grams'   => array( 'g', 1.0 ),
		'kg'      => array( 'g', 1000.0 ),
		'oz'      => array( 'g', 28.3495 ),
		'ounce'   => array( 'g', 28.3495 ),
		'ounces'  => array( 'g', 28.3495 ),
		'lb'      => array( 'g', 453.592 ),
		'lbs'     => array( 'g', 453.592 ),
		'pound'   => array( 'g', 453.592 ),
		'pounds'  => array( 'g', 453.592 ),
		'ml'      => array( 'ml', 1.0 ),
		'l'       => array( 'ml', 1000.0 ),
		'liter'   => array( 'ml', 1000.0 ),
		'litre'   => array( 'ml', 1000.0 ),
		'fl oz'   => array( 'ml', 29.5735 ),
		'gal'     => array( 'ml', 3785.41 ),
		'gallon'  => array( 'ml', 3785.41 ),
		'ct'      => array( 'pc', 1.0 ),
		'count'   => array( 'pc', 1.0 ),
		'tabs'    => array( 'pc', 1.0 ),
		'tablets' => array( 'pc', 1.0 ),
		'caps'    => array( 'pc', 1.0 ),
		'capsules' => array( 'pc', 1.0 ),
	);

	/**
	 * The facts the composer reads, with their empty values.
	 *
	 * @return array
	 */
	public static function blank_facts() {
		return array(
			'name'        => '',
			'ingredients' => '',     // The product's own active-ingredient field, as typed.
			'is_food'     => false,
			'form'        => '',     // flake / pellet / powder …; '' to read it from bases or the name.
			'bases'       => array(), // Food base values (Omnivore Flake, Herbivore Flake, 1 mm …).
			'sizes'       => array(), // Size values (2 oz, 8 oz, 3 lb …).
			'is_ea'       => false,
			'storage'     => '',
			'supplier'    => '',
		);
	}

	/**
	 * The listing.
	 *
	 * @param array $facts blank_facts() filled in.
	 * @return array{description: string, excerpt: string, meta: string, lines: string[], missing_ingredients: bool}
	 */
	public static function compose( array $facts ) {
		$f     = array_merge( self::blank_facts(), $facts );
		$name  = trim( (string) $f['name'] );
		$form  = '' !== $f['form'] ? (string) $f['form'] : self::form_of( $f['bases'], $name );
		$none  = self::says_none( $f['ingredients'] );
		$parts = $none ? array() : self::split_ingredients( $f['ingredients'] );
		$list  = array_map( array( __CLASS__, 'ingredient_words' ), $parts );
		$miss  = ! $none && array() === $list;

		if ( $none ) {
			$contains = ''; // The field says there is nothing in it: no line, a flag instead.
		} elseif ( $f['is_food'] ) {
			$what     = '' !== $form ? ucfirst( $form ) . ' food containing ' : 'Food containing ';
			$contains = $what . ( $miss ? self::BLANK : self::and_list( $list ) ) . '.';
		} else {
			$contains = 'Contains: ' . ( $miss ? self::BLANK : implode( ', ', $list ) ) . '.';
		}

		$available = self::available_line( $f['is_food'] ? $f['bases'] : array(), $form, $f['sizes'] );

		$lines = array_values(
			array_filter(
				array(
					$name,
					$contains,
					$available,
					trim( (string) $f['storage'] ),
					$f['is_ea'] ? trim( (string) $f['supplier'] ) : '',
				),
				'strlen'
			)
		);

		$short = trim( $contains . ( '' !== $available && '' !== $contains ? ' ' : '' ) . $available );

		return array(
			'description'         => implode( "\n", array_map( array( __CLASS__, 'paragraph' ), $lines ) ),
			'excerpt'             => $short,
			'meta'                => trim( ( '' !== $name ? $name . '. ' : '' ) . $short ),
			'lines'               => $lines,
			'missing_ingredients' => $miss,
			'none_ingredients'    => $none,
		);
	}

	/**
	 * Whether the active-ingredient field says the product has none.
	 *
	 * @param string $field As typed.
	 * @return bool
	 */
	public static function says_none( $field ) {
		return (bool) preg_match( self::NONE, (string) $field );
	}

	/**
	 * The ingredient list as it goes into a line: "kanamycin,
	 * metronidazole, and nitrofurazone" for a food ("… food containing …"),
	 * "metronidazole 99%+, praziquantel" otherwise ("Contains: …"). The
	 * page's ingredient box writes the same in JavaScript.
	 *
	 * @param string $field   As typed.
	 * @param bool   $is_food A food.
	 * @return string '' when the field is empty.
	 */
	public static function ingredient_phrase( $field, $is_food ) {
		$list = array_map( array( __CLASS__, 'ingredient_words' ), self::split_ingredients( $field ) );
		return $is_food ? self::and_list( $list ) : implode( ', ', $list );
	}

	/**
	 * "Available in omnivore or herbivore flake, 2 oz to 3 lb." — '' when
	 * there is neither a base nor a size.
	 *
	 * @param string[] $bases Food bases.
	 * @param string   $form  Form.
	 * @param string[] $sizes Sizes.
	 * @return string
	 */
	public static function available_line( array $bases, $form, array $sizes ) {
		$b = self::bases_phrase( $bases, $form );
		$s = self::sizes_phrase( $sizes );
		if ( '' === $b && '' === $s ) {
			return '';
		}
		return 'Available in ' . implode( ', ', array_filter( array( $b, $s ), 'strlen' ) ) . '.';
	}

	/**
	 * "omnivore or herbivore flake" from Omnivore Flake / Herbivore Flake
	 * (or from Omnivore / Herbivore with the form flake).
	 *
	 * @param string[] $bases Values.
	 * @param string   $form  Form ('' when unknown).
	 * @return string
	 */
	public static function bases_phrase( array $bases, $form ) {
		$vals = array();
		foreach ( $bases as $b ) {
			$b = trim( preg_replace( '/\s+/u', ' ', (string) $b ) );
			if ( '' !== $b && ! in_array( strtolower( $b ), array_map( 'strtolower', $vals ), true ) ) {
				$vals[] = $b;
			}
		}
		if ( ! $vals ) {
			return '';
		}
		$tail = '';
		if ( '' !== $form ) {
			$pat = '/\s+' . preg_quote( $form, '/' ) . 's?$/i';
			$all = true;
			foreach ( $vals as $v ) {
				$all = $all && preg_match( $pat, $v );
			}
			if ( $all ) {
				$vals = array_map(
					function ( $v ) use ( $pat ) {
						return preg_replace( $pat, '', $v );
					},
					$vals
				);
			}
			$tail = ' ' . $form;
			foreach ( $vals as $v ) {
				if ( preg_match( '/\b' . preg_quote( $form, '/' ) . 's?\b/i', $v ) ) {
					$tail = ''; // The form is already in the words.
				}
			}
		}
		$vals = array_map( array( __CLASS__, 'lower_words' ), $vals );
		return self::or_list( $vals ) . $tail;
	}

	/**
	 * Sizes, smallest first: one ("8 oz"), two ("2 oz or 8 oz"), more
	 * ("2 oz to 3 lb"). Values that are not sizes are left out.
	 *
	 * @param string[] $sizes Values.
	 * @return string
	 */
	public static function sizes_phrase( array $sizes ) {
		$known = array();
		foreach ( $sizes as $i => $s ) {
			$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
			$v = self::size_value( $s );
			if ( null !== $v && ! isset( $known[ strtolower( $s ) ] ) ) {
				$known[ strtolower( $s ) ] = array( $s, $v[0], $v[1], $i );
			}
		}
		if ( ! $known ) {
			return '';
		}
		$list = array_values( $known );
		usort(
			$list,
			function ( $a, $b ) {
				// Same kind of unit: by amount. Different kinds: as the product lists them.
				return $a[1] === $b[1] ? $a[2] <=> $b[2] : $a[3] <=> $b[3];
			}
		);
		$names = array_column( $list, 0 );
		if ( 1 === count( $names ) ) {
			return $names[0];
		}
		if ( 2 === count( $names ) ) {
			return $names[0] . ' or ' . $names[1];
		}
		return $names[0] . ' to ' . $names[ count( $names ) - 1 ];
	}

	/**
	 * A size's kind of unit and amount in that unit's base, or null when
	 * the value is not a size ("2 oz" → [g, 56.7]; "Omnivore" → null).
	 *
	 * @param string $s Value.
	 * @return array{0: string, 1: float}|null
	 */
	public static function size_value( $s ) {
		$s = strtolower( trim( str_replace( array( '-', '_' ), ' ', (string) $s ) ) );
		if ( ! preg_match( '/^(\d+(?:[.,]\d+)?|\d+\s*\/\s*\d+)\s*(fl\.?\s*oz|[a-z]+)\.?(?:\s+(?:jar|bag|bottle|tub|pouch|pack|container|can))?$/', $s, $m ) ) {
			return null;
		}
		$unit = preg_replace( '/\s+|\./', ' ', $m[2] );
		$unit = trim( preg_replace( '/\s+/', ' ', $unit ) );
		if ( ! isset( self::UNITS[ $unit ] ) ) {
			return null;
		}
		if ( false !== strpos( $m[1], '/' ) ) {
			list( $a, $b ) = array_map( 'floatval', explode( '/', $m[1] ) );
			$amount        = $b > 0 ? $a / $b : 0.0;
		} else {
			$amount = (float) str_replace( ',', '.', $m[1] );
		}
		return array( self::UNITS[ $unit ][0], $amount * self::UNITS[ $unit ][1] );
	}

	/**
	 * Whether a value is a size.
	 *
	 * @param string $s Value.
	 * @return bool
	 */
	public static function is_size( $s ) {
		return null !== self::size_value( $s );
	}

	/**
	 * The form from the food bases first, then from the name; '' when
	 * neither says.
	 *
	 * @param string[] $bases Values.
	 * @param string   $name  Product name.
	 * @return string
	 */
	public static function form_of( array $bases, $name ) {
		foreach ( array( implode( ' ', $bases ), (string) $name ) as $text ) {
			foreach ( self::FORMS as $pat => $form ) {
				if ( preg_match( '/\b(?:' . $pat . ')\b/i', $text ) ) {
					return $form;
				}
			}
		}
		return '';
	}

	/**
	 * The active-ingredient field split into its ingredients. The theme
	 * asks for a comma list; "+", ";", " & " and " and " are read too.
	 *
	 * @param string $field As typed.
	 * @return string[]
	 */
	public static function split_ingredients( $field ) {
		$field = trim( (string) $field );
		if ( '' === $field ) {
			return array();
		}
		// A "+" splits only after a word ("Prazi + Metro"), not in "99%+".
		$parts = preg_split( '/\s*(?:,|;|(?<=[\p{L}\s])\+|\s&\s|\band\b)\s*/iu', $field );
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/**
	 * An ingredient as it reads inside a sentence: ordinary capitalised
	 * words go lower case ("Metronidazole 99%+" → "metronidazole 99%+"),
	 * abbreviations stay ("TMP/Sulfa" → "TMP/sulfa").
	 *
	 * @param string $s Ingredient.
	 * @return string
	 */
	public static function ingredient_words( $s ) {
		return self::lower_words( $s );
	}

	/**
	 * Capitalised words to lower case, ALL-CAPS words kept.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function lower_words( $s ) {
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}])\p{Lu}\p{Ll}+(?![\p{L}\p{N}])/u',
			function ( $m ) {
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $m[0], 'UTF-8' ) : strtolower( $m[0] );
			},
			(string) $s
		);
	}

	/**
	 * "a", "a and b", "a, b, and c".
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	public static function and_list( array $items ) {
		return self::join_list( $items, 'and' );
	}

	/**
	 * "a", "a or b", "a, b, or c".
	 *
	 * @param string[] $items Items.
	 * @return string
	 */
	public static function or_list( array $items ) {
		return self::join_list( $items, 'or' );
	}

	/**
	 * A list joined the way a sentence reads.
	 *
	 * @param string[] $items Items.
	 * @param string   $word  and / or.
	 * @return string
	 */
	private static function join_list( array $items, $word ) {
		$items = array_values( $items );
		$n     = count( $items );
		if ( 0 === $n ) {
			return '';
		}
		if ( 1 === $n ) {
			return $items[0];
		}
		if ( 2 === $n ) {
			return $items[0] . ' ' . $word . ' ' . $items[1];
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ', ' . $word . ' ' . $last;
	}

	/**
	 * One line as a paragraph, escaped.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function paragraph( $line ) {
		return '<p>' . htmlspecialchars( $line, ENT_NOQUOTES, 'UTF-8' ) . '</p>';
	}

	/**
	 * A neutral name to suggest for a name that is a claim itself
	 * ("General Cure" → "Metronidazole + Praziquantel Powder"). The
	 * claim words are taken out; when what is left says little (one
	 * word, or only words like General / Duo / Trio), the name is made
	 * from the ingredients and the form. '' when the name has no claim.
	 *
	 * @param string $name  Product name.
	 * @param array  $facts blank_facts() filled in.
	 * @param array  $lists Words::get().
	 * @return string
	 */
	public static function neutral_name( $name, array $facts, array $lists ) {
		$hits = Finder::find( $name, $lists, array( 'diseases', 'claims', 'labels', 'dosing' ) );
		if ( ! $hits ) {
			return '';
		}
		$left = $name;
		foreach ( array_reverse( $hits ) as $hit ) {
			$left = substr( $left, 0, $hit['start'] ) . ' ' . substr( $left, $hit['start'] + $hit['length'] );
		}
		$left = trim( preg_replace( array( '/\s*[-–&+\/,:]\s*(?=\s|$)/u', '/(?<=^|\s)[-–&+\/,:]\s*/u', '/\s+/u' ), ' ', $left ) );
		$left = trim( preg_replace( '/\s+/u', ' ', $left ) );

		$filler = array( 'general', 'duo', 'trio', 'combo', 'pack', 'formula', 'mix', 'plus', 'pro', 'max', 'ultra', 'super', 'complete', 'kit', 'for', 'fish', 'all', 'in', 'one', 'the', 'and', 'with' );
		$useful = array_filter(
			preg_split( '/\s+/u', strtolower( $left ) ),
			function ( $w ) use ( $filler ) {
				return '' !== $w && ! in_array( $w, $filler, true );
			}
		);
		if ( count( $useful ) >= 2 ) {
			return $left;
		}

		$f     = array_merge( self::blank_facts(), $facts );
		$parts = self::says_none( $f['ingredients'] ) ? array() : self::split_ingredients( $f['ingredients'] );
		$form  = '' !== $f['form'] ? $f['form'] : self::form_of( $f['bases'], $name );
		$noun  = $f['is_food'] ? trim( ucfirst( $form ) . ' Food' ) : ucfirst( $form );
		if ( ! $parts ) {
			if ( ! $useful ) {
				return '(name to choose)'; // "Anti-Parasite Duo" leaves only "Duo": nothing worth keeping.
			}
			if ( '' !== self::form_of( array(), $left ) ) {
				$noun = ''; // "Tabs" already says what it is.
			}
			return trim( ( '' !== $left ? $left . ' ' : '' ) . $noun ) . ' (name to choose)';
		}
		$parts = array_map(
			function ( $p ) {
				return preg_replace_callback(
					'/(?<![\p{L}])\p{Ll}/u',
					function ( $m ) {
						return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[0], 'UTF-8' ) : strtoupper( $m[0] );
					},
					$p
				);
			},
			$parts
		);
		return trim( implode( ' + ', $parts ) . ( '' !== $noun ? ' ' . $noun : '' ) );
	}
}
