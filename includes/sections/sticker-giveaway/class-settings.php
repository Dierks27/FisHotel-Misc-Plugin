<?php
/**
 * Sticker Giveaway settings — storage, defaults, AJAX save.
 *
 * WHY a separate class: the pool, the eligibility rule and the upsell
 * price all live in one option so a single AJAX save (nonce + capability
 * checked, every field sanitised) keeps them consistent. Reading goes
 * through `Settings::get()` so defaults always fill in missing keys —
 * the rules engine never has to null-check a setting.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_sticker_giveaway_settings';

	/**
	 * Hard ceiling for the add-on quantity so a typo in admin cannot let
	 * someone order 500 one-dollar stickers.
	 *
	 * @var int
	 */
	const MAX_UPSELL_QTY = 50;

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'sticker_product_ids'     => array(),
		'eligible_category_slugs' => array( 'quarantined-fish' ),
		'min_subtotal'            => 0.0,
		'free_count'              => 1,
		'upsell_enabled'          => true,
		'upsell_price'            => 1.00,
		'upsell_max_qty'          => 5,
		'upsell_for_everyone'     => true,
		'heading_free'            => 'PICK YOUR FREE STICKER',
		'intro_free'              => 'Every fish order ships with a free FisHotel sticker — choose your favourite.',
		'heading_upsell'          => 'WANT MORE?',
		'intro_upsell'            => 'Add extra stickers for {price} each.',
		'heading_upsell_only'     => 'ADD A FISHOTEL STICKER',
		'intro_upsell_only'       => 'Grab a sticker for {price} each — free with any fish order.',
		'no_thanks_label'         => 'No sticker, thanks',
		'badge_text'              => 'FREE WITH YOUR FISH ORDER',
		'show_on_cart'            => false,
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_sticker_giveaway_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Get the current settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Sticker_Giveaway::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::DEFAULTS );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get_value( $key ) {
		$settings = self::get();
		return $settings[ $key ] ?? null;
	}

	/**
	 * AJAX handler — save settings.
	 */
	public function save_settings() {
		if ( ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$dropped  = array();
		$settings = self::sanitize( wp_unslash( $_POST ), $dropped ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		update_option( Sticker_Giveaway::OPTION_KEY, $settings );

		$message = __( 'Settings saved.', 'fishotel-misc-plugin' );
		if ( $dropped ) {
			/* translators: %s: comma-separated list of what was not saved */
			$message .= ' ' . sprintf( __( 'Not saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', $dropped ) );
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'settings' => $settings,
				'dropped'  => $dropped,
			)
		);
	}

	/**
	 * Sanitise a raw (unslashed) POST payload into a settings array.
	 *
	 * Every field is coerced to its type; unknown product IDs and
	 * category slugs are dropped rather than stored, and each drop is
	 * reported in `$dropped` so the admin page can say so instead of
	 * quietly showing a pool the picker will not use.
	 *
	 * Money fields: a blank or non-numeric value restores the default,
	 * the same way the copy fields do. Without that, clearing the add-on
	 * price field and saving would store 0.00 and make every add-on free.
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with human-readable reasons for
	 *                          anything left out of the saved settings.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$d       = self::DEFAULTS;
		$dropped = array();

		// Pool — simple products only; keep IDs that resolve to a product.
		$pool = array();
		foreach ( (array) ( $raw['sticker_product_ids'] ?? array() ) as $id ) {
			$id = absint( $id );
			if ( $id < 1 || in_array( $id, $pool, true ) ) {
				continue;
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( $product && $product->is_type( 'simple' ) ) {
				$pool[] = $id;
			} elseif ( $product ) {
				/* translators: %s: product name */
				$dropped[] = sprintf( __( '%s is not a simple product (the picker only offers simple products)', 'fishotel-misc-plugin' ), $product->get_name() );
			} else {
				/* translators: %d: product ID */
				$dropped[] = sprintf( __( 'product #%d does not exist', 'fishotel-misc-plugin' ), $id );
			}
		}

		// Categories — keep slugs that exist as product_cat terms.
		$slugs = array();
		foreach ( (array) ( $raw['eligible_category_slugs'] ?? array() ) as $slug ) {
			$slug = sanitize_title( $slug );
			if ( '' === $slug || in_array( $slug, $slugs, true ) ) {
				continue;
			}
			if ( term_exists( $slug, 'product_cat' ) ) {
				$slugs[] = $slug;
			} else {
				/* translators: %s: category slug */
				$dropped[] = sprintf( __( 'category "%s" does not exist', 'fishotel-misc-plugin' ), $slug );
			}
		}

		$min_subtotal = self::money( $raw, 'min_subtotal' );
		$upsell_price = self::money( $raw, 'upsell_price' );
		$upsell_max   = isset( $raw['upsell_max_qty'] ) && is_scalar( $raw['upsell_max_qty'] ) && '' !== trim( (string) $raw['upsell_max_qty'] )
			? absint( $raw['upsell_max_qty'] )
			: $d['upsell_max_qty'];

		return array(
			'sticker_product_ids'     => $pool,
			'eligible_category_slugs' => $slugs,
			'min_subtotal'            => $min_subtotal,
			'free_count'              => 1, // UI fixes this at one; stored for a future "pick two" option.
			'upsell_enabled'          => ! empty( $raw['upsell_enabled'] ),
			'upsell_price'            => $upsell_price,
			'upsell_max_qty'          => min( self::MAX_UPSELL_QTY, max( 1, $upsell_max ) ),
			'upsell_for_everyone'     => ! empty( $raw['upsell_for_everyone'] ),
			'heading_free'            => self::text( $raw, 'heading_free' ),
			'intro_free'              => self::text( $raw, 'intro_free' ),
			'heading_upsell'          => self::text( $raw, 'heading_upsell' ),
			'intro_upsell'            => self::text( $raw, 'intro_upsell' ),
			'heading_upsell_only'     => self::text( $raw, 'heading_upsell_only' ),
			'intro_upsell_only'       => self::text( $raw, 'intro_upsell_only' ),
			'no_thanks_label'         => self::text( $raw, 'no_thanks_label' ),
			'badge_text'              => self::text( $raw, 'badge_text' ),
			'show_on_cart'            => ! empty( $raw['show_on_cart'] ),
		);
	}

	/**
	 * Sanitise a money field: blank or non-numeric restores the default,
	 * negatives become zero, and the result is rounded to cents.
	 *
	 * @param array  $raw Raw request data.
	 * @param string $key Field key.
	 * @return float
	 */
	private static function money( array $raw, $key ) {
		$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';

		if ( '' === $value || ! is_numeric( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : $value ) ) {
			return (float) self::DEFAULTS[ $key ];
		}

		$number = (float) ( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value ) : $value );

		return max( 0.0, round( $number, 2 ) );
	}

	/**
	 * Sanitise a copy field, falling back to the default when blank.
	 *
	 * @param array  $raw Raw request data.
	 * @param string $key Field key.
	 * @return string
	 */
	private static function text( array $raw, $key ) {
		$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		return '' === $value ? self::DEFAULTS[ $key ] : $value;
	}
}
