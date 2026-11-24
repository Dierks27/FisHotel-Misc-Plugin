<?php
/**
 * The "Fish essentials" box — under a product's title, above its
 * description: what a fish needs filled first.
 *
 *   - **Scientific name** — ONE field for the name that is kept in two
 *     places today: the short description (the italic line under the
 *     product's name on its page, the Latin line on related-fish cards,
 *     and the key the Batch plugin's Master Fish library reads) and the
 *     theme's `_fh_scientific_name` (the first row of the facts table).
 *     Saving a changed name writes both: the short description through
 *     WordPress's own save of the post (`wp_insert_post_data`, so the
 *     Batch plugin's Master Fish sync on `save_post_product` already reads
 *     the new name), the table's meta after WooCommerce and the theme
 *     saved theirs. When the two differ today, both are shown with "Use
 *     this one"; nothing changes until the owner picks one (or types) and
 *     updates the product. Shown for a live fish.
 *     A short description that holds MORE than the name (a second line, a
 *     label with a colon, a link, a list …) is never overwritten: its own
 *     box stays on the screen, the name here goes to the facts table only,
 *     and the box says so.
 *   - "Copy them into these fields" in the theme's facts box hands the
 *     scientific name on (the jQuery event `fishotel-copy-facts`): the
 *     script fills this field when it is empty, and offers "Use this one"
 *     when it holds another name.
 *   - **Livestock — packed in a bag** and **Needs a 3rd bag (sharp spines
 *     or a scalpel)** — drawn by the Packing section (the same fields, the
 *     same save) when it is on; it then leaves them out of the Shipping tab.
 *   - **Still missing** — read-only chips ("Photo", "Price: Large",
 *     "Table: 3 of 8 empty"), each a link to the spot, from the Product checks
 *     section when it is on (the filter fishotel_edit_screens_missing).
 *     Absent when nothing is missing.
 *
 * sci_view(), sci_norm(), first_line(), excerpt_extra(), decide_sci() and
 * excerpt_data() are pure.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Essentials
 */
class Essentials {

	/**
	 * Form fields: the name, the name the field was drawn with, the nonce.
	 */
	const FIELD       = 'fh_es_sci';
	const FIELD_SHOWN = 'fh_es_sci_shown';
	const NONCE_FIELD = 'fh_es_nonce';
	const NONCE       = 'fishotel_edit_screens_essentials';

	/**
	 * The theme's meta of the scientific name (the facts table's first row).
	 *
	 * @var string
	 */
	const META_SCI = '_fh_scientific_name';

	/**
	 * The longest name kept.
	 *
	 * @var int
	 */
	const MAX = 200;

	/**
	 * A name as typed, made plain (pure): no markup, entities read,
	 * whitespace collapsed, at most MAX characters.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sci_plain( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		$text = html_entity_decode( strip_tags( (string) $raw ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
		if ( '' === $text && '' !== trim( (string) $raw ) ) {
			$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $raw ) ) ); // Not UTF-8: read as it is.
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX ) : substr( $text, 0, self::MAX );
	}

	/**
	 * A name as it is compared (pure): plain, lower-case.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sci_norm( $raw ) {
		$text = self::sci_plain( $raw );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * The first line of a short description, made plain (pure).
	 *
	 * @param mixed $raw The short description.
	 * @return string
	 */
	public static function first_line( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return '';
		}
		foreach ( self::lines( (string) $raw ) as $line ) {
			$line = self::sci_plain( $line );
			if ( '' !== $line ) {
				return $line;
			}
		}
		return '';
	}

	/**
	 * The lines of a text: line breaks, paragraph and block ends as new
	 * lines (pure).
	 *
	 * @param string $html Text.
	 * @return string[]
	 */
	private static function lines( $html ) {
		$text = (string) preg_replace( '#<br\s*/?>|</(?:p|div|li|h[1-6]|tr|td|th|blockquote)>#i', "\n", (string) $html );
		return preg_split( '/\r\n|\r|\n/', $text );
	}

	/**
	 * Whether a short description holds more than the name (pure): more
	 * than one line, a colon (a typed fact, "Reef safe: yes"), more than
	 * MAX characters, or markup other than a paragraph, a line break and
	 * the inline em / i / strong / b / span. Such a text is never
	 * overwritten by the name.
	 *
	 * @param mixed $raw The short description.
	 * @return bool
	 */
	public static function excerpt_extra( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return false;
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return false;
		}
		if ( preg_match_all( '#</?\s*([a-z][a-z0-9]*)#i', $raw, $m ) ) {
			foreach ( $m[1] as $tag ) {
				if ( ! in_array( strtolower( $tag ), array( 'p', 'br', 'em', 'i', 'strong', 'b', 'span' ), true ) ) {
					return true;
				}
			}
		}
		$lines = array();
		foreach ( self::lines( $raw ) as $line ) {
			$plain = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', html_entity_decode( strip_tags( $line ), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $plain ) {
				$lines[] = $plain;
			}
		}
		if ( count( $lines ) > 1 ) {
			return true;
		}
		$one = $lines ? $lines[0] : '';
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $one ) : strlen( $one );
		return false !== strpos( $one, ':' ) || $len > self::MAX;
	}

	/**
	 * What the field shows (pure): the table's name, else the subtitle's
	 * (the first line of the short description); and both when they
	 * differ.
	 *
	 * @param mixed $excerpt The short description.
	 * @param mixed $sci     `_fh_scientific_name`.
	 * @return array{value: string, subtitle: string, table: string, differ: bool}
	 */
	public static function sci_view( $excerpt, $sci ) {
		$subtitle = self::first_line( $excerpt );
		$table    = self::sci_plain( $sci );
		return array(
			'value'    => '' !== $table ? $table : $subtitle,
			'subtitle' => $subtitle,
			'table'    => $table,
			'differ'   => '' !== $subtitle && '' !== $table && self::sci_norm( $subtitle ) !== self::sci_norm( $table ),
		);
	}

	/**
	 * What a save does with the posted name (pure): null = nothing (the
	 * field was not on the form, or is as it was drawn), else the name to
	 * write to both places ('' clears both).
	 *
	 * @param mixed $posted The posted name.
	 * @param mixed $shown  The name the field was drawn with.
	 * @return string|null
	 */
	public static function decide_sci( $posted, $shown ) {
		if ( null === $posted || ! is_scalar( $posted ) || is_bool( $posted ) || null === $shown || ! is_scalar( $shown ) ) {
			return null;
		}
		$posted = self::sci_plain( $posted );
		return $posted === self::sci_plain( $shown ) ? null : $posted;
	}

	/* ============================================================== */
	/*  The box                                                        */
	/* ============================================================== */

	/**
	 * Draw the box.
	 *
	 * @param \WP_Post $post The product's post.
	 */
	public static function render( $post ) {
		$id      = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		$product = is_object( $product ) ? $product : null;
		$excerpt = is_object( $post ) && isset( $post->post_excerpt ) ? (string) $post->post_excerpt : '';
		$view    = self::sci_view( $excerpt, $id > 0 ? get_post_meta( $id, self::META_SCI, true ) : '' );

		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );

		echo '<div class="fh-es-grid">';
		echo '<div class="fh-es-field fh-es-sci fh-es-live-only">';
		echo '<label for="fh-es-sci"><strong>' . esc_html__( 'Scientific name', 'fishotel-misc-plugin' ) . '</strong></label>';
		echo '<input type="text" id="fh-es-sci" class="widefat" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( $view['value'] ) . '" placeholder="' . esc_attr__( 'e.g. Paracanthurus hepatus', 'fishotel-misc-plugin' ) . '" autocomplete="off" spellcheck="false">';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_SHOWN ) . '" value="' . esc_attr( $view['value'] ) . '">';
		echo '<p class="description">' . esc_html__( 'The italic line under the fish\'s name on its page, AND the first row of its facts table — typed once here, saved to both.', 'fishotel-misc-plugin' ) . '</p>';
		if ( self::excerpt_extra( $excerpt ) ) {
			echo '<p class="fh-es-extra">' . esc_html__( 'The short description holds more than the name, so it is never changed from here: it stays in its own box (Product short description, further down). A name typed here goes to the facts table only.', 'fishotel-misc-plugin' ) . '</p>';
		}
		if ( $view['differ'] ) {
			echo '<p class="fh-es-differ">' . esc_html__( 'The two places say different things today:', 'fishotel-misc-plugin' ) . '</p><ul class="fh-es-choices">';
			foreach ( array(
				'subtitle' => __( 'Under the name', 'fishotel-misc-plugin' ),
				'table'    => __( 'In the facts table', 'fishotel-misc-plugin' ),
			) as $key => $label ) {
				echo '<li><span class="fh-es-choice-where">' . esc_html( $label ) . ':</span> <em>' . esc_html( $view[ $key ] ) . '</em> <button type="button" class="button-link fh-es-use" data-value="' . esc_attr( $view[ $key ] ) . '">' . esc_html__( 'Use this one', 'fishotel-misc-plugin' ) . '</button></li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '<div class="fh-es-field fh-es-packing">';
		if ( function_exists( 'fishotel_packing_product_fields' ) ) {
			fishotel_packing_product_fields( $product );
		}
		echo '</div>';
		echo '</div>';

		$chips = function_exists( 'apply_filters' ) ? apply_filters( 'fishotel_edit_screens_missing', array(), $id ) : array();
		$chips = is_array( $chips ) ? $chips : array();
		if ( $chips ) {
			echo '<p class="fh-es-missing"><span class="fh-es-missing-title">' . esc_html__( 'Still missing:', 'fishotel-misc-plugin' ) . '</span> ';
			foreach ( $chips as $chip ) {
				if ( ! is_array( $chip ) || ! isset( $chip['label'] ) || ! is_scalar( $chip['label'] ) ) {
					continue;
				}
				$href = isset( $chip['href'] ) && is_scalar( $chip['href'] ) ? (string) $chip['href'] : '';
				echo '' !== $href
					? '<a class="fh-es-chip" href="' . esc_url( $href ) . '">' . esc_html( (string) $chip['label'] ) . '</a> '
					: '<span class="fh-es-chip">' . esc_html( (string) $chip['label'] ) . '</span> ';
			}
			echo '</p>';
		}
	}

	/**
	 * The posted name, when this request is the product's own edit form
	 * (its nonce, a person who may edit it): what decide_sci() says, else
	 * null.
	 *
	 * @param int $post_id Product ID.
	 * @return string|null
	 */
	private static function posted_name( $post_id ) {
		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! is_array( $raw ) || ! isset( $raw[ self::NONCE_FIELD ] ) || ! is_scalar( $raw[ self::NONCE_FIELD ] ) ) {
			return null;
		}
		if ( (int) $post_id < 1 || ! wp_verify_nonce( (string) $raw[ self::NONCE_FIELD ], self::NONCE ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return null;
		}
		if ( isset( $raw['post_ID'] ) && is_scalar( $raw['post_ID'] ) && (int) $raw['post_ID'] !== (int) $post_id ) {
			return null; // Another post saved during this request.
		}
		return self::decide_sci( $raw[ self::FIELD ] ?? null, $raw[ self::FIELD_SHOWN ] ?? null );
	}

	/**
	 * The short description WordPress is about to save (pure): the name,
	 * unless no name was decided or the short description holds more than
	 * the name. Null = leave it as it is.
	 *
	 * @param string|null $name    The decided name (decide_sci()).
	 * @param mixed       $excerpt The short description being saved (unslashed).
	 * @return string|null
	 */
	public static function excerpt_data( $name, $excerpt ) {
		if ( null === $name || self::excerpt_extra( $excerpt ) ) {
			return null;
		}
		return $name;
	}

	/**
	 * wp_insert_post_data: a changed name into the short description, in
	 * the same write as the rest of the post — before `save_post`, so the
	 * Batch plugin's Master Fish sync (`save_post_product`) reads the new
	 * name, not the one before.
	 *
	 * @param mixed $data    Slashed post data.
	 * @param mixed $postarr Slashed post array (its ID).
	 * @return mixed
	 */
	public static function insert_data( $data, $postarr = array() ) {
		if ( ! is_array( $data ) || 'product' !== ( $data['post_type'] ?? '' ) || ! is_array( $postarr ) ) {
			return $data;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}
		$id      = isset( $postarr['ID'] ) && is_scalar( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$current = wp_unslash( isset( $data['post_excerpt'] ) && is_scalar( $data['post_excerpt'] ) ? (string) $data['post_excerpt'] : '' );
		$excerpt = self::excerpt_data( self::posted_name( $id ), $current );
		if ( null !== $excerpt ) {
			$data['post_excerpt'] = wp_slash( $excerpt );
		}
		return $data;
	}

	/**
	 * Save a changed name to the facts table (`_fh_scientific_name`),
	 * after WordPress, WooCommerce and the theme have saved what they were
	 * posted (each from its own box). The short description was written
	 * with the post (insert_data()).
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save( $post_id ) {
		$name = self::posted_name( (int) $post_id );
		if ( null === $name || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( (int) $post_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
			return;
		}
		if ( '' === $name ) {
			$product->delete_meta_data( self::META_SCI );
		} else {
			$product->update_meta_data( self::META_SCI, $name );
		}
		$product->save();
	}
}
