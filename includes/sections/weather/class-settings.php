<?php
/**
 * Weather settings — storage, defaults, AJAX save.
 *
 * WHY a single option: the run time, the statuses to scan and the band
 * thresholds all feed one nightly job, so one sanitised AJAX save (nonce +
 * manage_woocommerce) keeps them consistent and re-arms the cron in the
 * same request. Reads go through Settings::get() so defaults always fill
 * in missing keys and the thresholds are always six increasing integers.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

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
	const NONCE_ACTION = 'fishotel_weather_settings';

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'enabled'       => true,
		'contact_email' => '',
		'run_time'      => '21:00',
		'statuses'      => array( 'processing', 'on-hold', 'fulfillment' ),
		'thresholds'    => Bands::DEFAULT_THRESHOLDS,
		'note_sources'  => true,
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_weather_save', array( $this, 'save_settings' ) );
	}

	/**
	 * Current settings, merged with defaults and normalised.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Weather::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::DEFAULTS );

		$settings['thresholds'] = Bands::normalise_thresholds( $settings['thresholds'] );
		$settings['statuses']   = self::clean_statuses( $settings['statuses'] );
		$settings['run_time']   = self::clean_time( $settings['run_time'] );

		return $settings;
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get_value( $key ) {
		$settings = self::get();
		return $settings[ $key ] ?? null;
	}

	/**
	 * The contact email for the NWS User-Agent: the setting, else the
	 * site admin email.
	 *
	 * @return string
	 */
	public static function contact_email() {
		$email = (string) self::get_value( 'contact_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * AJAX handler — save settings and re-arm the nightly event.
	 */
	public function save_settings() {
		if ( ! current_user_can( Weather::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$dropped  = array();
		$settings = self::sanitize( wp_unslash( $_POST ), $dropped ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		update_option( Weather::OPTION_KEY, $settings );

		Cron::schedule( $settings );

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
				'next_run' => Cron::next_run_label(),
			)
		);
	}

	/**
	 * Sanitise a raw (unslashed) POST payload into a settings array.
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with human-readable reasons for
	 *                          anything that fell back to a default.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$dropped = array();

		$email = isset( $raw['contact_email'] ) && is_scalar( $raw['contact_email'] ) ? sanitize_email( (string) $raw['contact_email'] ) : '';
		if ( isset( $raw['contact_email'] ) && '' !== trim( (string) $raw['contact_email'] ) && '' === $email ) {
			$dropped[] = __( 'contact email is not a valid address (the site admin email will be used)', 'fishotel-misc-plugin' );
		}

		$run_time = isset( $raw['run_time'] ) && is_scalar( $raw['run_time'] ) ? trim( (string) $raw['run_time'] ) : '';
		if ( '' !== $run_time && self::clean_time( $run_time ) !== $run_time ) {
			$dropped[] = __( 'run time must be HH:MM (default 21:00 used)', 'fishotel-misc-plugin' );
		}

		$statuses = self::clean_statuses( (array) ( $raw['statuses'] ?? array() ) );
		if ( empty( $statuses ) ) {
			$statuses  = self::DEFAULTS['statuses'];
			$dropped[] = __( 'no statuses ticked (defaults used)', 'fishotel-misc-plugin' );
		}

		$raw_thresholds = isset( $raw['thresholds'] ) && is_array( $raw['thresholds'] ) ? $raw['thresholds'] : array();
		if ( ! Bands::thresholds_valid( $raw_thresholds ) ) {
			$dropped[] = __( 'thresholds must be six whole numbers, each higher than the last (defaults used)', 'fishotel-misc-plugin' );
		}

		return array(
			'enabled'       => ! empty( $raw['enabled'] ),
			'contact_email' => $email,
			'run_time'      => self::clean_time( $run_time ),
			'statuses'      => $statuses,
			'thresholds'    => Bands::normalise_thresholds( $raw_thresholds ),
			'note_sources'  => ! empty( $raw['note_sources'] ),
		);
	}

	/**
	 * Keep only statuses WooCommerce knows, without the `wc-` prefix.
	 *
	 * @param mixed $statuses Raw list.
	 * @return string[]
	 */
	public static function clean_statuses( $statuses ) {
		$known = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		$known = array_map(
			static function ( $slug ) {
				return 0 === strpos( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;
			},
			$known
		);

		$out = array();
		foreach ( (array) $statuses as $slug ) {
			$slug = sanitize_key( (string) $slug );
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
	 * HH:MM (24h) or the default.
	 *
	 * @param mixed $time Raw time.
	 * @return string
	 */
	public static function clean_time( $time ) {
		$time = is_scalar( $time ) ? trim( (string) $time ) : '';

		if ( 1 === preg_match( '/^(\d{1,2}):(\d{1,2})(?::\d{2})?$/', $time, $m ) ) {
			$h = (int) $m[1];
			$i = (int) $m[2];
			if ( $h >= 0 && $h <= 23 && $i >= 0 && $i <= 59 ) {
				return sprintf( '%02d:%02d', $h, $i );
			}
		}

		return self::DEFAULTS['run_time'];
	}
}
