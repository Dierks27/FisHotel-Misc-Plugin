<?php
/**
 * Product checks — the rules (pure: no WordPress).
 *
 * The owner asked for products-list filters: "how many fish need photos,
 * especially live fish, what ones need fields filled in, and what ones
 * still have the old style before we started splitting apart the info so
 * it looks nice in a table". So each product gets a few flags — one
 * hidden meta key per problem, stored only when there is a problem (a
 * clean product stores nothing) — worked out here from its facts
 * (Collector).
 *
 * Who is checked:
 *   L — a live fish: a product in one of the statuses publish, private,
 *       draft, pending or future, not in the category `invoices`, with
 *       one of its OWN categories in the "live" list (the setting; by
 *       default the Batch plugin's fish categories, `quarantined-fish` and
 *       `inverts`) or "Livestock — packed in a bag" ticked;
 *   T — gets the facts table: its own category is `quarantined-fish` (the
 *       theme prints the table for that category only). Inverts are live
 *       but get no table checks for now (the owner's answer).
 *
 * The flags (`_fh_todo_<key>`):
 *   photo     L, and no real photo: 'none' (no featured image, or one that
 *             is gone) or 'standin' (a placeholder of the theme's libraries
 *             or WooCommerce's, the same image featured on 3 or more
 *             products, or a file named placeholder / coming soon /
 *             no image);
 *   gallery   L, and no gallery photo;
 *   table     T, and how many of the 8 table facts are missing (scientific
 *             name, common names, max length, min tank size, temperament,
 *             reef safe — yes, no or caution —, foods & feeding and habitat
 *             & behaviour — these two not counting when they are the site's
 *             default text); region is not required;
 *   oldstyle  L, and the facts are still typed in the description: two or
 *             more different labels at the start of a line, and at least
 *             one of them with its field empty;
 *   sci       T, and the scientific name is missing in one of its two
 *             places (the short description, `_fh_scientific_name`) or
 *             they differ;
 *   price     published or private, any product: in stock with no regular
 *             price — a simple product ('1'), or the enabled in-stock
 *             variations without one (their sizes, comma-separated);
 *   bag       L, packed in a bag, the Packing section on, and an in-stock
 *             size (or the simple product) that gets no bag from Packing's
 *             own lookup — '1' or the sizes;
 *   subcat    filed under a category below `quarantined-fish` but not in
 *             `quarantined-fish` itself (no table, no certificate).
 * Plus `_fh_todo` = how many flags (for sorting) and `_fh_todo_v` = the
 * rule version.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	/**
	 * The rule version: up it when a rule changes, and every product is
	 * checked again (Sweep).
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * The meta key prefix of a flag, the count and the version.
	 */
	const PREFIX    = '_fh_todo_';
	const META_SUM  = '_fh_todo';
	const META_VER  = '_fh_todo_v';

	/**
	 * The flags, in the order they are shown.
	 *
	 * @var string[]
	 */
	const FLAGS = array( 'photo', 'table', 'oldstyle', 'bag', 'price', 'sci', 'gallery', 'subcat' );

	/**
	 * Statuses of L, and of the price check.
	 *
	 * @var string[]
	 */
	const LIVE_STATUSES  = array( 'publish', 'private', 'draft', 'pending', 'future' );
	const PRICE_STATUSES = array( 'publish', 'private' );

	/**
	 * The category of the facts table, and of a fish that is "filed".
	 *
	 * @var string
	 */
	const TABLE_CAT = 'quarantined-fish';

	/**
	 * The 8 table facts: field → `_fh_` meta suffix.
	 *
	 * @var string[]
	 */
	const TABLE_FIELDS = array( 'scientific_name', 'common_names', 'max_length', 'min_tank_size', 'temperament', 'reef_safe', 'foods_feeding', 'habitat' );

	/**
	 * The labels of an old-style description, at the start of a line
	 * (FisHotel Theme's migration reads the same ones).
	 *
	 * @var string
	 */
	const LABELS = '/^[\s*•\-]*(?<label>Scientific\s+Name|Common\s+Names?|Max(?:imum)?\.?\s+Lengths?|Min(?:imum)?\.?\s+(?:Aquarium|Tank)\s+Sizes?|Temperament|Reef[\s\-]?Safe(?:ty)?|Region|Foods?\s+(?:and|&)\s+Feeding(?:\s+Habits)?|Habitat\s+(?:and|&)\s+Behaviou?r|Habitat|Fun\s+Facts?)\s*(?:[:\-–—|]|\t|$)/imu';

	/**
	 * Whether a value is filled (pure).
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function filled( $value ) {
		return is_scalar( $value ) && ! is_bool( $value ) && '' !== trim( (string) $value );
	}

	/**
	 * A text as it is compared: no markup, entities read, lower-case,
	 * whitespace collapsed.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	public static function norm( $text ) {
		if ( ! is_scalar( $text ) || is_bool( $text ) ) {
			return '';
		}
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );
		$text = trim( $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * A description made into lines, as the label regex reads it: line and
	 * cell ends as new lines, no markup, entities read, no-break spaces as
	 * spaces.
	 *
	 * @param mixed $html The description and the short description.
	 * @return string
	 */
	public static function lines( $html ) {
		if ( ! is_scalar( $html ) || is_bool( $html ) ) {
			return '';
		}
		$text = (string) preg_replace( '#<br\s*/?>|</(?:p|li|div|tr|td|h[1-6])>#i', "\n", (string) $html );
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return str_replace( "\xC2\xA0", ' ', $text );
	}

	/**
	 * The field a label stands for ('' for "Fun Facts": evidence only).
	 *
	 * @param string $label A label as found.
	 * @return string
	 */
	public static function field_of( $label ) {
		$label = strtolower( (string) preg_replace( '/\s+/', ' ', (string) $label ) );
		$map   = array(
			'/^scientific/' => 'scientific_name',
			'/^common/'     => 'common_names',
			'/^max/'        => 'max_length',
			'/^min/'        => 'min_tank_size',
			'/^temperament/' => 'temperament',
			'/^reef/'       => 'reef_safe',
			'/^region/'     => 'region',
			'/^food/'       => 'foods_feeding',
			'/^habitat/'    => 'habitat',
		);
		foreach ( $map as $pattern => $field ) {
			if ( 1 === preg_match( $pattern, $label ) ) {
				return $field;
			}
		}
		return '';
	}

	/**
	 * The different labels found at the start of a line (pure).
	 *
	 * @param mixed $html The description and the short description.
	 * @return string[] field ('' for Fun Facts) → as found, in the order found.
	 */
	public static function labels( $html ) {
		$found = array();
		if ( false === preg_match_all( self::LABELS, self::lines( $html ), $m ) ) {
			return $found; // Not UTF-8: no labels read.
		}
		foreach ( $m['label'] as $label ) {
			$field = self::field_of( $label );
			$key   = '' === $field ? 'fun' : $field;
			if ( ! isset( $found[ $key ] ) ) {
				$found[ $key ] = $field;
			}
		}
		return array_values( $found );
	}

	/**
	 * Whether a table fact is filled (pure): reef safe must be yes, no or
	 * caution; foods & habitat must not be the site's default text.
	 *
	 * @param string $field    A field of TABLE_FIELDS (or region).
	 * @param mixed  $value    Its value.
	 * @param array  $defaults foods_feeding, habitat: the site's default texts.
	 * @return bool
	 */
	public static function fact_filled( $field, $value, array $defaults = array() ) {
		if ( ! self::filled( $value ) ) {
			return false;
		}
		if ( 'reef_safe' === $field ) {
			return in_array( strtolower( trim( (string) $value ) ), array( 'yes', 'no', 'caution' ), true );
		}
		if ( isset( $defaults[ $field ] ) && self::filled( $defaults[ $field ] ) ) {
			return self::norm( $value ) !== self::norm( $defaults[ $field ] );
		}
		return true;
	}

	/**
	 * Whether a description is "old style" (pure): two or more different
	 * labels, and one of them whose field is empty.
	 *
	 * @param mixed $html     Description + short description.
	 * @param array $facts    field → value.
	 * @param array $defaults The site's default texts.
	 * @return bool
	 */
	public static function old_style( $html, array $facts, array $defaults = array() ) {
		$labels = self::labels( $html );
		if ( count( $labels ) < 2 ) {
			return false;
		}
		foreach ( $labels as $field ) {
			if ( '' !== $field && ! self::fact_filled( $field, $facts[ $field ] ?? '', $defaults ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a product is a live fish (L, pure).
	 *
	 * @param array $p Facts: status, cats (own slugs), packs.
	 * @param array $live_cats The live categories.
	 * @return bool
	 */
	public static function is_live( array $p, array $live_cats ) {
		$cats = isset( $p['cats'] ) && is_array( $p['cats'] ) ? $p['cats'] : array();
		if ( ! in_array( (string) ( $p['status'] ?? '' ), self::LIVE_STATUSES, true ) || in_array( 'invoices', $cats, true ) ) {
			return false;
		}
		return 'yes' === ( $p['packs'] ?? '' ) || (bool) array_intersect( $cats, $live_cats );
	}

	/**
	 * Whether a product gets the facts table (T, pure).
	 *
	 * @param array $p Facts.
	 * @return bool
	 */
	public static function gets_table( array $p ) {
		$cats = isset( $p['cats'] ) && is_array( $p['cats'] ) ? $p['cats'] : array();
		return in_array( (string) ( $p['status'] ?? '' ), self::LIVE_STATUSES, true ) && ! in_array( 'invoices', $cats, true ) && in_array( self::TABLE_CAT, $cats, true );
	}

	/**
	 * The flags of one product (pure).
	 *
	 * @param array $p         Facts (Collector::facts()): status, cats, under_table_cat, packs, thumb (0 = none, -1 = gone),
	 *                         thumb_standin, gallery, text, excerpt, facts (field → value), defaults,
	 *                         old_style (null = work it out here), price_missing (list of sizes, or ['']
	 *                         for a simple product), bag_missing (same; null = the Packing section is off).
	 * @param array $live_cats The live categories.
	 * @return array<string, string> flag → value ('1' when there is nothing more to say).
	 */
	public static function flags( array $p, array $live_cats ) {
		$out   = array();
		$live  = self::is_live( $p, $live_cats );
		$table = self::gets_table( $p );
		$facts = isset( $p['facts'] ) && is_array( $p['facts'] ) ? $p['facts'] : array();
		$def   = isset( $p['defaults'] ) && is_array( $p['defaults'] ) ? $p['defaults'] : array();

		if ( $live ) {
			$thumb = (int) ( $p['thumb'] ?? 0 );
			if ( $thumb < 1 ) {
				$out['photo'] = 'none';
			} elseif ( ! empty( $p['thumb_standin'] ) ) {
				$out['photo'] = 'standin';
			}
			if ( ! self::filled( $p['gallery'] ?? '' ) ) {
				$out['gallery'] = '1';
			}
			$old = $p['old_style'] ?? null;
			if ( null === $old ) {
				$old = self::old_style( (string) ( $p['text'] ?? '' ) . "\n" . (string) ( $p['excerpt'] ?? '' ), $facts, $def );
			}
			if ( $old ) {
				$out['oldstyle'] = '1';
			}
			if ( 'no' !== ( $p['packs'] ?? '' ) && isset( $p['bag_missing'] ) && is_array( $p['bag_missing'] ) && $p['bag_missing'] ) {
				$out['bag'] = self::sizes( $p['bag_missing'] );
			}
		}

		if ( $table ) {
			$missing = 0;
			foreach ( self::TABLE_FIELDS as $field ) {
				if ( ! self::fact_filled( $field, $facts[ $field ] ?? '', $def ) ) {
					++$missing;
				}
			}
			if ( $missing > 0 ) {
				$out['table'] = (string) $missing;
			}
			$sub = self::norm( $p['excerpt'] ?? '' );
			$sci = self::norm( $facts['scientific_name'] ?? '' );
			if ( '' === $sub || '' === $sci || $sub !== $sci ) {
				$out['sci'] = '1';
			}
		}

		if ( in_array( (string) ( $p['status'] ?? '' ), self::PRICE_STATUSES, true ) && isset( $p['price_missing'] ) && is_array( $p['price_missing'] ) && $p['price_missing'] ) {
			$out['price'] = self::sizes( $p['price_missing'] );
		}

		$cats = isset( $p['cats'] ) && is_array( $p['cats'] ) ? $p['cats'] : array();
		if ( ! empty( $p['under_table_cat'] ) && ! in_array( self::TABLE_CAT, $cats, true ) ) {
			$out['subcat'] = '1';
		}

		$ordered = array();
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $out[ $flag ] ) ) {
				$ordered[ $flag ] = $out[ $flag ];
			}
		}
		return $ordered;
	}

	/**
	 * A list of sizes as one value: '1' when it names none (a simple
	 * product), else the names, comma-separated, each once.
	 *
	 * @param array $sizes Size names ('' = the product itself).
	 * @return string
	 */
	public static function sizes( array $sizes ) {
		$names = array();
		foreach ( $sizes as $size ) {
			$size = is_scalar( $size ) ? trim( (string) $size ) : '';
			if ( '' !== $size && ! in_array( $size, $names, true ) ) {
				$names[] = $size;
			}
		}
		return $names ? implode( ', ', $names ) : '1';
	}

	/**
	 * What to write for a product (pure): every meta key of the checks →
	 * its value, or null to delete it. A clean product stores nothing.
	 *
	 * @param array $flags flags().
	 * @return array<string, string|null>
	 */
	public static function meta( array $flags ) {
		$out = array();
		foreach ( self::FLAGS as $flag ) {
			$out[ self::PREFIX . $flag ] = isset( $flags[ $flag ] ) ? (string) $flags[ $flag ] : null;
		}
		$out[ self::META_SUM ] = $flags ? (string) count( $flags ) : null;
		$out[ self::META_VER ] = $flags ? (string) self::VERSION : null;
		return $out;
	}

	/**
	 * The chips of a product's flags (pure), in order: label and the box
	 * of the edit screen they point to.
	 *
	 * @param array $flags flag → value (stored meta read back).
	 * @return array[] label, flag, anchor.
	 */
	public static function chips( array $flags ) {
		$out = array();
		foreach ( self::FLAGS as $flag ) {
			if ( ! isset( $flags[ $flag ] ) || ! self::filled( $flags[ $flag ] ) ) {
				continue;
			}
			$value = (string) $flags[ $flag ];
			switch ( $flag ) {
				case 'photo':
					$label  = 'standin' === $value ? __( 'Stand-in photo', 'fishotel-misc-plugin' ) : __( 'Photo', 'fishotel-misc-plugin' );
					$anchor = 'postimagediv';
					break;
				case 'table':
					/* translators: %d: how many of the 8 table facts are filled */
					$label  = sprintf( __( 'Table %d/8', 'fishotel-misc-plugin' ), max( 0, 8 - (int) $value ) );
					$anchor = 'fishotel_hotel_data';
					break;
				case 'oldstyle':
					$label  = __( 'Old style', 'fishotel-misc-plugin' );
					$anchor = 'fishotel_hotel_data';
					break;
				case 'bag':
					/* translators: %s: the sizes with no bag */
					$label  = '1' === $value ? __( 'Bag size', 'fishotel-misc-plugin' ) : sprintf( __( 'Bag: %s', 'fishotel-misc-plugin' ), $value );
					$anchor = 'woocommerce-product-data';
					break;
				case 'price':
					/* translators: %s: the sizes with no price */
					$label  = '1' === $value ? __( 'Price', 'fishotel-misc-plugin' ) : sprintf( __( 'Price: %s', 'fishotel-misc-plugin' ), $value );
					$anchor = 'woocommerce-product-data';
					break;
				case 'sci':
					$label  = __( 'Scientific name', 'fishotel-misc-plugin' );
					$anchor = 'fishotel-essentials';
					break;
				case 'gallery':
					$label  = __( 'Gallery', 'fishotel-misc-plugin' );
					$anchor = 'woocommerce-product-images';
					break;
				default:
					$label  = __( 'Sub-category only', 'fishotel-misc-plugin' );
					$anchor = 'product_catdiv';
			}
			$out[] = array(
				'flag'   => $flag,
				'label'  => $label,
				'anchor' => $anchor,
			);
		}
		return $out;
	}

	/**
	 * Whether an image is a stand-in (pure).
	 *
	 * @param int      $thumb        The featured image id.
	 * @param int[]    $placeholders The placeholder libraries' and WooCommerce's placeholder ids.
	 * @param int[]    $shared       Images featured on 3 or more products.
	 * @param string   $file         The image's file name.
	 * @return bool
	 */
	public static function standin( $thumb, array $placeholders, array $shared, $file ) {
		$thumb = (int) $thumb;
		if ( $thumb < 1 ) {
			return false;
		}
		if ( in_array( $thumb, array_map( 'intval', $placeholders ), true ) || in_array( $thumb, array_map( 'intval', $shared ), true ) ) {
			return true;
		}
		return 1 === preg_match( '/placeholder|coming[\s_\-]?soon|no[\s_\-]?image/i', basename( (string) $file ) );
	}
}
