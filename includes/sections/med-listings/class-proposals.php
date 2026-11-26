<?php
/**
 * Med listings — what the review table proposes for one product.
 *
 * Reads a product snapshot (Inventory::snapshot(): every field already
 * read through Fields, its categories, attributes, images, variations,
 * reviews, and what this tool changed before) and gives back
 *
 *   rows   one per field to change: current text, its hits, the
 *          proposed text, whether it can be edited and starts ticked;
 *   flags  what is never changed by itself: a name that is a claim
 *          (with a neutral name to suggest), missing ingredients,
 *          reviews that name diseases or dosing, another brand, …;
 *   facts  what the composer was given.
 *
 * Pure: the snapshot holds everything; no WordPress call.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Proposals
 */
class Proposals {

	/**
	 * Attribute names that are claims whatever their values say.
	 *
	 * @var string
	 */
	const CLAIM_ATTRIBUTE = '/(?:^|[\s_\-])(?:treats?|use|uses|used|use[\s_\-]?in|indications?|dose|dosage|dosing|directions?|targets?|good[\s_\-]?for|effective)(?:$|[\s_\-])/i';

	/**
	 * Attribute names that are never a food base.
	 *
	 * @var string
	 */
	const NOT_BASE = '/(?:form|reef|brand|safe|ingredient)/i';

	/**
	 * The composer's facts for a snapshot.
	 *
	 * @param array $snap  Snapshot.
	 * @param array $words Words::get().
	 * @return array
	 */
	public static function facts( array $snap, array $words ) {
		$bases = array();
		$sizes = array();
		foreach ( (array) ( $snap['attributes'] ?? array() ) as $key => $raw ) {
			if ( self::is_claim_attribute( $key, $raw, $words ) ) {
				continue;
			}
			$values = (array) ( $raw['values'] ?? array() );
			$label  = (string) ( $raw['label'] ?? $key );
			$all    = $values && count( array_filter( $values, array( Composer::class, 'is_size' ) ) ) === count( $values );
			if ( $all ) {
				$sizes = array_merge( $sizes, $values );
			} elseif ( ! empty( $raw['variation'] ) && ! preg_match( self::NOT_BASE, $label . ' ' . $key ) ) {
				$bases = array_merge( $bases, $values );
			}
		}
		return array_merge(
			Composer::blank_facts(),
			array(
				'name'        => (string) ( $snap['name'] ?? '' ),
				'ingredients' => (string) ( $snap['ingredients'] ?? '' ),
				'is_food'     => ! empty( $snap['is_food'] ),
				'bases'       => $bases,
				'sizes'       => $sizes,
				'is_ea'       => ! empty( $snap['is_ea'] ),
				'storage'     => (string) ( $words['storage'] ?? '' ),
				'supplier'    => (string) ( $words['supplier'] ?? '' ),
			)
		);
	}

	/**
	 * Whether an attribute is a claim (by its name, or by a value).
	 *
	 * @param string $key   Attribute key.
	 * @param array  $raw   label, values, variation.
	 * @param array  $words Words::get().
	 * @return bool
	 */
	public static function is_claim_attribute( $key, array $raw, array $words ) {
		$name = str_replace( 'pa_', '', (string) $key ) . ' ' . (string) ( $raw['label'] ?? '' );
		if ( preg_match( self::CLAIM_ATTRIBUTE, ' ' . str_replace( array( 'med_', 'med-' ), ' ', $name ) . ' ' ) ) {
			return true;
		}
		return Finder::has( implode( ' · ', (array) ( $raw['values'] ?? array() ) ), $words, array( 'diseases', 'claims', 'dosing', 'labels' ) );
	}

	/**
	 * Rows and flags for a snapshot.
	 *
	 * @param array $snap  Snapshot.
	 * @param array $words Words::get().
	 * @return array{rows: array, flags: array, facts: array, composed: array}
	 */
	public static function build( array $snap, array $words ) {
		$facts    = self::facts( $snap, $words );
		$composed = Composer::compose( $facts );
		$rows     = array();
		$flags    = array();
		$pid      = (int) $snap['id'];
		$fields   = (array) ( $snap['fields'] ?? array() );
		$done     = (array) ( $snap['ledger'] ?? array() );
		$done_as  = (array) ( $snap['ledger_name'] ?? array() );
		$name_now = (string) $snap['name'];

		$add = function ( array $f, $proposed, array $extra = array() ) use ( &$rows, $words, $done, $done_as, $name_now ) {
			$key  = (int) $f['object'] . '|' . $f['field'];
			$read = $f['read'];
			// Changed by this tool already and not touched since: the owner decided —
			// unless it was written with a product name the owner has changed since
			// (a claim name renamed after Apply) and still carries that old name.
			if ( array_key_exists( $key, $done ) && Fields::same( $read['raw'], $done[ $key ] ) ) {
				$was = (string) ( $done_as[ $key ] ?? '' );
				$txt = (string) $read['text'];
				if ( '' === $was || $was === $name_now || ( false === stripos( $txt, $was ) && false === stripos( $txt, sanitize_title( $was ) ) ) ) {
					return;
				}
			}
			$current = (string) $read['text'];
			if ( empty( $extra['force'] ) && trim( $current ) === trim( (string) $proposed ) ) {
				return;
			}
			unset( $extra['force'] );
			$hits   = Finder::find( $current, $words );
			$rows[] = array_merge(
				array(
					'key'      => $key,
					'object'   => (int) $f['object'],
					'field'    => (string) $f['field'],
					'label'    => (string) $f['label'],
					'where'    => (string) ( $f['where'] ?? '' ),
					'current'  => $current,
					'hits'     => $hits,
					'proposed' => (string) $proposed,
					'editable' => true,
					'ticked'   => false === strpos( (string) $proposed, '[[' ),
					'note'     => false === strpos( (string) $proposed, '[[' ) ? '' : __( 'Fill in the ingredients from the product\'s own label first (confirm against the EA product).', 'fishotel-misc-plugin' ),
					'hash'     => Fields::hash( $read ),
				),
				$extra
			);
		};

		foreach ( $fields as $f ) {
			$read  = $f['read'];
			$field = (string) $f['field'];
			$text  = (string) $read['text'];

			if ( 'post_content' === $field && (int) $f['object'] === $pid ) {
				$lost  = self::facts_left_out( $text, $composed['description'] );
				$extra = $lost ? array( 'note' => sprintf( /* translators: %s: amounts */ __( 'The old text also gives %s — not in the new text. Add what belongs to the product (net weight, strength, pellet size) to the box before applying.', 'fishotel-misc-plugin' ), '"' . implode( '", "', $lost ) . '"' ) ) : array();
				if ( $lost && false !== strpos( (string) $composed['description'], '[[' ) ) {
					$extra['note'] = __( 'Fill in the ingredients from the product\'s own label first (confirm against the EA product).', 'fishotel-misc-plugin' ) . ' ' . $extra['note'];
				}
				$add( $f, $composed['description'], $extra );
				continue;
			}
			if ( 'meta:_fishotel_active_ingredient' === $field ) {
				// Never rewritten. Only when it is empty does the page offer one box
				// to type it in: it is saved to the field and fills every [[ … ]]
				// blank of this product's proposals as it is typed.
				if ( '' === trim( $text ) && $composed['missing_ingredients'] ) {
					$add(
						$f,
						'',
						array(
							'ticked' => false,
							'force'  => true,
							'kind'   => 'ingredient',
							'note'   => __( 'Type the active ingredients from the product\'s own label (confirm against the EA product), separated by commas. Saved to the product\'s Active ingredient(s) field, and filled into the blanks below as you type.', 'fishotel-misc-plugin' ),
						)
					);
				}
				continue;
			}
			if ( 'post_excerpt' === $field && (int) $f['object'] === $pid ) {
				$add( $f, $composed['excerpt'] );
				continue;
			}
			if ( 'tags' === $field ) {
				$keep = array();
				foreach ( (array) $read['raw'] as $name ) {
					if ( ! Finder::has( $name, $words, array( 'diseases', 'claims', 'dosing', 'labels' ) ) ) {
						$keep[] = $name;
					}
				}
				if ( count( $keep ) !== count( (array) $read['raw'] ) ) {
					$add( $f, implode( ', ', $keep ), array( 'note' => __( 'Tags are links to tag pages: the disease and medicine-type tags come off. Ingredient tags stay.', 'fishotel-misc-plugin' ) ) );
				}
				continue;
			}
			if ( 0 === strpos( $field, 'attr:' ) ) {
				$raw = (array) $f['attr'];
				if ( ! self::is_claim_attribute( substr( $field, 5 ), $raw, $words ) ) {
					continue;
				}
				if ( ! empty( $raw['variation'] ) ) {
					$flags[] = array(
						'type' => 'attribute',
						'text' => sprintf( /* translators: %s: attribute and values */ __( 'The attribute "%s" is a claim but the product\'s variations are built on it, so it is not taken off here. Change it on the product page.', 'fishotel-misc-plugin' ), $text ),
					);
					continue;
				}
				$add(
					$f,
					'',
					array(
						'editable' => false,
						'note'     => __( 'The whole attribute comes off the product (also off the "Additional information" list and its filter pages).', 'fishotel-misc-plugin' ),
					)
				);
				continue;
			}
			if ( 'post_name' === $field && (int) $f['object'] !== $pid ) {
				if ( Finder::has( str_replace( array( '-', '_' ), ' ', $text ), $words ) ) {
					$want = function_exists( 'sanitize_title' ) ? sanitize_title( (string) $snap['name'] ) : strtolower( (string) $snap['name'] );
					$add( $f, $want, array( 'note' => __( 'The picture\'s own page address (not its file, which keeps its name). The old page address then shows "page not found" — no text from it is left; the picture itself is not affected.', 'fishotel-misc-plugin' ) ) );
				}
				continue;
			}
			if ( in_array( $field, array( 'meta:_variation_description', 'meta:_wp_attachment_image_alt', 'post_title', 'post_excerpt', 'post_content' ), true ) && (int) $f['object'] !== $pid ) {
				if ( ! Finder::has( $text, $words ) ) {
					continue;
				}
				if ( 'meta:_wp_attachment_image_alt' === $field || 'post_title' === $field ) {
					$add( $f, (string) $snap['name'] );
				} else {
					$add( $f, Finder::clean( $text, $words ) );
				}
				continue;
			}
			if ( 0 === strpos( $field, 'meta:' ) ) {
				$key = substr( $field, 5 );
				if ( ! isset( Fields::META[ $key ] ) || '' === trim( $text ) ) {
					continue;
				}
				$policy = Fields::META[ $key ][1];
				if ( 'ingredient' === $policy ) {
					continue;
				}
				if ( 'empty' === $policy ) {
					$add( $f, '' );
				} elseif ( 'ack' === $policy ) {
					$add(
						$f,
						'',
						array(
							'ticked' => false,
							'note'   => __( 'Shown as an extra tick at checkout and kept on every order. Left unticked: you decide. Empty = no extra tick for this product.', 'fishotel-misc-plugin' ),
						)
					);
				} elseif ( Finder::has( $text, $words ) ) {
					if ( 'meta' === $policy ) {
						$add( $f, $composed['meta'] );
					} elseif ( Fields::is_title_key( $key ) ) {
						$add( $f, self::clean_title( $text, (string) $snap['name'], $words ), array( 'note' => __( 'Empty = the field is removed and Yoast / Rank Math use their own title (the product name and the site name).', 'fishotel-misc-plugin' ) ) );
					} else {
						$add( $f, Finder::clean( $text, $words ) );
					}
				}
			}
		}

		// Flags: never changed by themselves.
		$name_hits = Finder::find( (string) $snap['name'], $words, array( 'diseases', 'claims', 'labels', 'dosing' ) );
		if ( $name_hits ) {
			$flags[] = array(
				'type'    => 'name',
				'text'    => sprintf( /* translators: 1: name 2: words */ __( 'The product name "%1$s" is a claim itself (%2$s).', 'fishotel-misc-plugin' ), $snap['name'], implode( ', ', Finder::words( $name_hits ) ) ),
				'suggest' => Composer::neutral_name( (string) $snap['name'], $facts, $words ),
			);
		}
		$slug_hits = Finder::find( str_replace( '-', ' ', (string) ( $snap['slug'] ?? '' ) ), $words, array( 'diseases', 'claims', 'labels', 'dosing' ) );
		if ( $slug_hits ) {
			$flags[] = array(
				'type' => 'slug',
				'text' => sprintf( /* translators: 1: slug 2: words */ __( 'The product\'s address "%1$s" has a claim word in it (%2$s). On the product page, edit the permalink (after renaming the product): WordPress sends the old address on. Then open this page again: the fields that still carry the old name are proposed again.', 'fishotel-misc-plugin' ), (string) $snap['slug'], implode( ', ', Finder::words( $slug_hits ) ) ),
				'link' => (string) ( $snap['edit'] ?? '' ),
			);
		}
		if ( '' === trim( (string) $snap['ingredients'] ) ) {
			$flags[] = array(
				'type' => 'ingredients',
				'text' => __( 'No active ingredient on the product (FisHotel Medication tab → Active ingredient(s)). Type it once in the "Active ingredient(s)" box of its table below, from the label — confirm against the EA product. It fills the blanks and is saved to the product.', 'fishotel-misc-plugin' ),
			);
		} elseif ( ! empty( $composed['none_ingredients'] ) ) {
			$flags[] = array(
				'type' => 'ingredients',
				'text' => sprintf( /* translators: %s: field */ __( 'The active-ingredient field says "%s", so no "Contains" line is written. Check that this product really has no active ingredient (a test kit, a net); otherwise type the ingredients into the field.', 'fishotel-misc-plugin' ), trim( (string) $snap['ingredients'] ) ),
			);
		} elseif ( Finder::has( (string) $snap['ingredients'], $words ) ) {
			$flags[] = array(
				'type' => 'ingredients',
				'text' => sprintf( /* translators: %s: field */ __( 'The active-ingredient field itself says more than the ingredients: "%s". It is shown under the title; change it on the product page.', 'fishotel-misc-plugin' ), $snap['ingredients'] ),
			);
		}
		if ( isset( $snap['site_med'] ) && ! $snap['site_med'] ) {
			$flags[] = array(
				'type' => 'not_med',
				'text' => __( 'Found by its name, but it is not in Medications or a category under it, so the site does not treat it as a medication: no ornamental-use pop-up or checkout tick, and the footer on its page still links to the Dosing Calculator and the QT Scheduler. If it is a medication, add it to Medications (or a category under it) on its product page; step 5 then sorts it.', 'fishotel-misc-plugin' ),
			);
		}
		foreach ( (array) ( $snap['brands'] ?? array() ) as $brand ) {
			$flags[] = array(
				'type' => 'brand',
				'text' => sprintf( /* translators: %s: brand */ __( 'Sold under another brand: %s.', 'fishotel-misc-plugin' ), $brand ),
			);
		}
		if ( ! empty( $snap['asin'] ) ) {
			$flags[] = array(
				'type' => 'brand',
				'text' => sprintf( /* translators: %s: ASIN */ __( 'Sold through Amazon (ASIN %s) — another company\'s product and wording.', 'fishotel-misc-plugin' ), $snap['asin'] ),
			);
		}
		foreach ( (array) ( $snap['comments'] ?? array() ) as $c ) {
			$hits = Finder::find( (string) $c['content'], $words, array( 'diseases', 'claims', 'dosing' ) );
			if ( ! $hits ) {
				continue;
			}
			$flags[] = array(
				'type'       => 'review',
				'text'       => sprintf( /* translators: 1: author 2: words */ __( 'Review by %1$s mentions: %2$s. Reviews do not show on the product page, but their text is public in WooCommerce\'s review list for apps and search engines (/wp-json/wc/store/v1/products/reviews) — Hide takes it out of there too.', 'fishotel-misc-plugin' ), $c['author'], implode( ', ', Finder::words( $hits ) ) ),
				'quote'      => Finder::snippet( (string) $c['content'], $hits[0], 60 ),
				'link'       => (string) ( $c['link'] ?? '' ),
				'comment_id' => (int) $c['id'],
				'status'     => (string) ( $c['status'] ?? '' ),
			);
		}

		foreach ( (array) ( $snap['pictures'] ?? array() ) as $aid => $pic ) {
			$file = (string) ( $pic['file'] ?? '' );
			if ( '' !== $file && Finder::has( str_replace( array( '-', '_', '.' ), ' ', $file ), $words ) ) {
				$flags[] = array(
					'type' => 'picture',
					'text' => sprintf( /* translators: 1: file 2: where */ __( 'The picture file is named "%1$s" (%2$s): the name is part of its address on every page that shows it. Upload the picture again under a plain name and swap it in on the product; this page does not rename files.', 'fishotel-misc-plugin' ), $file, (string) ( $pic['where'] ?? '' ) ),
					'link' => (string) ( $pic['edit'] ?? '' ),
				);
			}
		}

		return array(
			'rows'     => $rows,
			'flags'    => $flags,
			'facts'    => $facts,
			'composed' => $composed,
		);
	}

	/**
	 * A search-engine title without its claim. When the name part goes with
	 * the claim ("General Cure | Broad-Spectrum …" → "FisHotel") the title is
	 * proposed empty: the field is removed and the SEO plugin's own title
	 * (the product name and the site name) is used.
	 *
	 * @param string $title Title.
	 * @param string $name  Product name.
	 * @param array  $words Words::get().
	 * @return string
	 */
	public static function clean_title( $title, $name, array $words ) {
		$clean = Finder::clean( (string) $title, $words );
		if ( '' === trim( $name ) || false === stripos( $clean, trim( $name ) ) ) {
			return '';
		}
		return $clean;
	}

	/**
	 * Amounts the old description gives that the new one does not: net
	 * weight, strength, pellet size ("100 g", "99%", "1 mm") — so the owner
	 * is told before they are dropped (pure).
	 *
	 * @param string $old Old description.
	 * @param string $new Proposed description.
	 * @return string[]
	 */
	public static function facts_left_out( $old, $new ) {
		$plain = html_entity_decode( preg_replace( '/<[^>]*>/', ' ', (string) $old ), ENT_QUOTES, 'UTF-8' );
		$norm  = function ( $x ) {
			return strtolower( preg_replace( '/\s+/u', '', (string) $x ) );
		};
		$have = $norm( html_entity_decode( preg_replace( '/<[^>]*>/', ' ', (string) $new ), ENT_QUOTES, 'UTF-8' ) );
		if ( ! preg_match_all( '/\b\d+(?:[.,]\d+)?\s*(?:%\+?|mm|cm|mg\/g|mg|g|kg|oz|lb|lbs|ml|l|fl\.?\s*oz|ct|count|tabs|tablets|capsules)(?![\p{L}])(?!\s*(?:per\b|\/))/iu', $plain, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $amount ) {
			$amount = trim( preg_replace( '/\s+/u', ' ', $amount ) );
			if ( false === strpos( $have, $norm( $amount ) ) && ! in_array( $amount, $out, true ) ) {
				$out[] = $amount;
			}
		}
		return $out;
	}
}
