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

		$add = function ( array $f, $proposed, array $extra = array() ) use ( &$rows, $words, $done ) {
			$key  = (int) $f['object'] . '|' . $f['field'];
			$read = $f['read'];
			// Changed by this tool already and not touched since: the owner decided.
			if ( array_key_exists( $key, $done ) && Fields::same( $read['raw'], $done[ $key ] ) ) {
				return;
			}
			$current = (string) $read['text'];
			if ( trim( $current ) === trim( (string) $proposed ) ) {
				return;
			}
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
				$add( $f, $composed['description'] );
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
			if ( 'meta:_variation_description' === $field || 'meta:_wp_attachment_image_alt' === $field || ( 'post_excerpt' === $field && (int) $f['object'] !== $pid ) ) {
				if ( ! Finder::has( $text, $words ) ) {
					continue;
				}
				if ( 'meta:_wp_attachment_image_alt' === $field ) {
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
					$add( $f, 'meta' === $policy ? $composed['meta'] : Finder::clean( $text, $words ) );
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
		if ( '' === trim( (string) $snap['ingredients'] ) ) {
			$flags[] = array(
				'type' => 'ingredients',
				'text' => __( 'No active ingredient on the product (FisHotel Medication tab → Active ingredient(s)). The proposal leaves a blank: fill it in from the label — confirm against the EA product.', 'fishotel-misc-plugin' ),
			);
		} elseif ( Finder::has( (string) $snap['ingredients'], $words ) ) {
			$flags[] = array(
				'type' => 'ingredients',
				'text' => sprintf( /* translators: %s: field */ __( 'The active-ingredient field itself says more than the ingredients: "%s". It is shown under the title; change it on the product page.', 'fishotel-misc-plugin' ), $snap['ingredients'] ),
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
				'text'       => sprintf( /* translators: 1: author 2: words */ __( 'Review by %1$s mentions: %2$s.', 'fishotel-misc-plugin' ), $c['author'], implode( ', ', Finder::words( $hits ) ) ),
				'quote'      => Finder::snippet( (string) $c['content'], $hits[0], 60 ),
				'link'       => (string) ( $c['link'] ?? '' ),
				'comment_id' => (int) $c['id'],
				'status'     => (string) ( $c['status'] ?? '' ),
			);
		}

		return array(
			'rows'     => $rows,
			'flags'    => $flags,
			'facts'    => $facts,
			'composed' => $composed,
		);
	}
}
