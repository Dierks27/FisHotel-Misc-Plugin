<?php
/**
 * Today settings — storage, defaults, sanitised AJAX save.
 *
 * One option (`fishotel_today_settings`): how far ahead to look, how many
 * delivery dates to lay out, which order statuses count as open, which
 * columns to show and how long the collected window is cached. The save
 * checks a nonce and manage_options and drops the cache, so the next
 * page load reflects the new shape.
 *
 * @package FisHotel\Misc\Sections\Today
 */

namespace FisHotel\Misc\Sections\Today;

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
	const NONCE_ACTION = 'fishotel_today_settings';

	/**
	 * Default values.
	 *
	 * `shipped` is open here on purpose: ShipTracker (and the theme, for a
	 * combined box) flips an order to shipped the moment its tracking is
	 * saved, so without it a row would vanish exactly when Tracking turns
	 * to a tick and the ship-day counts would shrink during the run.
	 * Shipped rows keep their fish counts and simply leave the attention
	 * strip once weather, invoice and tracking are done.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'lookahead_days' => 21,
		'dates_shown'    => 3,
		'statuses'       => array( 'processing', 'on-hold', 'fulfillment', 'shipped' ),
		'show_weather'   => true,
		'show_invoice'   => true,
		'show_tracking'  => true,
		'cache_minutes'  => 5,
	);

	const LOOKAHEAD_MIN = 1;
	const LOOKAHEAD_MAX = 60;
	const DATES_MIN     = 1;
	const DATES_MAX     = 7;
	const CACHE_MIN     = 0;
	const CACHE_MAX     = 1440;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_today_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Current settings, merged with defaults and normalised.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Today::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::normalize( wp_parse_args( $stored, self::DEFAULTS ) );
	}

	/**
	 * Clamp and type every key. An empty status list falls back to the
	 * defaults — a window with no statuses would always be empty.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function normalize( array $settings ) {
		$statuses = self::clean_statuses( $settings['statuses'] ?? array() );
		if ( empty( $statuses ) ) {
			$statuses = self::clean_statuses( self::DEFAULTS['statuses'] );
		}
		if ( empty( $statuses ) ) {
			$statuses = self::DEFAULTS['statuses'];
		}

		return array(
			'lookahead_days' => self::clamp( $settings['lookahead_days'] ?? null, self::LOOKAHEAD_MIN, self::LOOKAHEAD_MAX, self::DEFAULTS['lookahead_days'] ),
			'dates_shown'    => self::clamp( $settings['dates_shown'] ?? null, self::DATES_MIN, self::DATES_MAX, self::DEFAULTS['dates_shown'] ),
			'statuses'       => $statuses,
			'show_weather'   => ! empty( $settings['show_weather'] ),
			'show_invoice'   => ! empty( $settings['show_invoice'] ),
			'show_tracking'  => ! empty( $settings['show_tracking'] ),
			'cache_minutes'  => self::clamp( $settings['cache_minutes'] ?? null, self::CACHE_MIN, self::CACHE_MAX, self::DEFAULTS['cache_minutes'] ),
		);
	}

	/**
	 * AJAX handler — save settings and drop the cache.
	 */
	public function save_settings() {
		if ( ! current_user_can( Today::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$dropped  = array();
		$settings = self::sanitize( wp_unslash( $_POST ), $dropped ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		update_option( Today::OPTION_KEY, $settings );

		Collector::clear_cache();

		$message = __( 'Settings saved.', 'fishotel-misc-plugin' );
		if ( $dropped ) {
			/* translators: %s: semicolon-separated list of what was not saved */
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
	 * Checkboxes are absent when unticked, so every boolean comes from the
	 * payload, never from the stored value.
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with reasons for anything that fell back.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$dropped = array();

		$lookahead = isset( $raw['lookahead_days'] ) && is_scalar( $raw['lookahead_days'] ) ? (int) $raw['lookahead_days'] : self::DEFAULTS['lookahead_days'];
		if ( $lookahead < self::LOOKAHEAD_MIN || $lookahead > self::LOOKAHEAD_MAX ) {
			$dropped[] = __( 'lookahead must be 1–60 days (default 21 used)', 'fishotel-misc-plugin' );
			$lookahead = self::DEFAULTS['lookahead_days'];
		}

		$dates = isset( $raw['dates_shown'] ) && is_scalar( $raw['dates_shown'] ) ? (int) $raw['dates_shown'] : self::DEFAULTS['dates_shown'];
		if ( $dates < self::DATES_MIN || $dates > self::DATES_MAX ) {
			$dropped[] = __( 'dates shown must be 1–7 (default 3 used)', 'fishotel-misc-plugin' );
			$dates     = self::DEFAULTS['dates_shown'];
		}

		$cache = isset( $raw['cache_minutes'] ) && is_scalar( $raw['cache_minutes'] ) ? (int) $raw['cache_minutes'] : self::DEFAULTS['cache_minutes'];
		if ( $cache < self::CACHE_MIN || $cache > self::CACHE_MAX ) {
			$dropped[] = __( 'cache must be 0–1440 minutes (default 5 used)', 'fishotel-misc-plugin' );
			$cache     = self::DEFAULTS['cache_minutes'];
		}

		$statuses = self::clean_statuses( (array) ( $raw['statuses'] ?? array() ) );
		if ( empty( $statuses ) ) {
			$dropped[] = __( 'no statuses ticked (defaults used)', 'fishotel-misc-plugin' );
		}

		return self::normalize(
			array(
				'lookahead_days' => $lookahead,
				'dates_shown'    => $dates,
				'statuses'       => $statuses,
				'show_weather'   => ! empty( $raw['show_weather'] ),
				'show_invoice'   => ! empty( $raw['show_invoice'] ),
				'show_tracking'  => ! empty( $raw['show_tracking'] ),
				'cache_minutes'  => $cache,
			)
		);
	}

	/**
	 * Keep only statuses WooCommerce knows, without the `wc-` prefix.
	 * Outside WooCommerce (tests) every well-formed slug is kept.
	 *
	 * @param mixed $statuses Raw list.
	 * @return string[]
	 */
	public static function clean_statuses( $statuses ) {
		$known = function_exists( 'wc_get_order_statuses' ) ? array_keys( (array) wc_get_order_statuses() ) : array();
		$known = array_map(
			static function ( $slug ) {
				return 0 === strpos( (string) $slug, 'wc-' ) ? substr( (string) $slug, 3 ) : (string) $slug;
			},
			$known
		);

		$out = array();
		foreach ( (array) $statuses as $slug ) {
			$slug = is_scalar( $slug ) ? sanitize_key( (string) $slug ) : '';
			if ( 0 === strpos( $slug, 'wc-' ) ) {
				$slug = substr( $slug, 3 );
			}
			if ( '' === $slug || in_array( $slug, $out, true ) ) {
				continue;
			}
			if ( $known && ! in_array( $slug, $known, true ) ) {
				continue;
			}
			$out[] = $slug;
		}

		return $out;
	}

	/**
	 * An int within [min, max], else the default.
	 *
	 * @param mixed $value   Raw.
	 * @param int   $min     Min.
	 * @param int   $max     Max.
	 * @param int   $default Default.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return (int) $default;
		}

		$value = (int) $value;

		return ( $value < $min || $value > $max ) ? (int) $default : $value;
	}
}
