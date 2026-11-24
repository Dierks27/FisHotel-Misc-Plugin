<?php
/**
 * Product checks — the one setting: which categories count as live for
 * the checks.
 *
 * By default the fish categories the Batch plugin uses everywhere
 * (`quarantined-fish` and `inverts`, unless its filter
 * `fishotel_ship_day_fish_categories` says other). The owner may bring
 * corals in again one day ("a special deal"): a Corals category is then
 * simply ticked here — no code. Stored as a list of category slugs in
 * the option `fishotel_product_checks` (not autoloaded); not saved yet =
 * the default.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * The option.
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_product_checks';

	/**
	 * The most categories kept.
	 *
	 * @var int
	 */
	const MAX = 100;

	/**
	 * The default: the Batch plugin's fish categories.
	 *
	 * @return string[]
	 */
	public static function default_cats() {
		$cats = array( 'quarantined-fish', 'inverts' );
		if ( class_exists( 'FisHotel_Ship_Day' ) && is_callable( array( 'FisHotel_Ship_Day', 'fish_categories' ) ) ) {
			try {
				$cats = (array) \FisHotel_Ship_Day::fish_categories();
			} catch ( \Throwable $e ) {
				$cats = array( 'quarantined-fish', 'inverts' );
			}
		} elseif ( function_exists( 'apply_filters' ) ) {
			$cats = (array) apply_filters( 'fishotel_ship_day_fish_categories', $cats );
		}
		return self::slugs_in( $cats );
	}

	/**
	 * A list of slugs, cleaned (pure): lower-case, letters, digits,
	 * hyphens and underscores, each once, at most MAX.
	 *
	 * @param mixed $raw A list.
	 * @return string[]
	 */
	public static function slugs_in( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $slug ) {
			$slug = is_scalar( $slug ) && ! is_bool( $slug ) ? strtolower( trim( (string) $slug ) ) : '';
			if ( '' !== $slug && 1 === preg_match( '/^[a-z0-9_%\-]{1,200}$/', $slug ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether a setting has been saved.
	 *
	 * @return bool
	 */
	public static function saved() {
		$stored = get_option( self::OPTION, null );
		return is_array( $stored ) && isset( $stored['live_cats'] ) && is_array( $stored['live_cats'] );
	}

	/**
	 * The live categories: as saved, else the default.
	 *
	 * @return string[]
	 */
	public static function live_cats() {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && isset( $stored['live_cats'] ) && is_array( $stored['live_cats'] ) ) {
			return self::slugs_in( $stored['live_cats'] );
		}
		return self::default_cats();
	}

	/**
	 * Save the live categories (the page's form): who may, the nonce, only
	 * categories that exist. "Use the default" forgets the saved list.
	 *
	 * @param array    $raw   Unslashed POST: _wpnonce, live_cats[], use_default.
	 * @param string[] $known The slugs of the shop's categories.
	 * @return array{ok: bool, message: string}
	 */
	public static function save_request( array $raw, array $known ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ),
			);
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::OPTION ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' ),
			);
		}
		if ( ! empty( $raw['use_default'] ) ) {
			delete_option( self::OPTION );
			return array(
				'ok'      => true,
				'message' => __( 'Saved: the live categories are the fish categories again. Every product is being checked again.', 'fishotel-misc-plugin' ),
			);
		}
		$cats = array_values( array_intersect( self::slugs_in( $raw['live_cats'] ?? array() ), self::slugs_in( $known ) ) );
		update_option( self::OPTION, array( 'live_cats' => $cats ), false );
		return array(
			'ok'      => true,
			'message' => $cats
				/* translators: %s: category slugs */
				? sprintf( __( 'Saved: live for the checks — %s. Every product is being checked again.', 'fishotel-misc-plugin' ), implode( ', ', $cats ) )
				: __( 'Saved: no category is live — only a product with "Livestock — packed in a bag" ticked is checked as a live fish.', 'fishotel-misc-plugin' ),
		);
	}
}
