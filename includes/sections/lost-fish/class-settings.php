<?php
/**
 * Lost Fish settings — one option (not autoloaded: nothing reads it on an
 * ordinary front-end request), its defaults and its sanitised save.
 *
 *   auto_send      "Send by itself after the undo window" — OFF unless the
 *                  owner switches it on. Off, no e-mail ever leaves without
 *                  the click on "Send to customer".
 *   reminder_days  one reminder that many days after the e-mail when no
 *                  answer came (0 = none); the case then stays under
 *                  "Needs you".
 *   link_days      how long the links in an e-mail are good for.
 *   subject, body  the e-mail template — plain text with {placeholders};
 *                  an empty field means the built-in default (Email).
 *   reply_to       an optional Reply-To address.
 *   store_design   wrap the e-mail in the store's own e-mail header and
 *                  footer when WooCommerce is there.
 *   free_classes   the shipping classes that mean "free on purpose" (an
 *                  invoice given free shipping): an order shipped free
 *                  under one of them has no shipping difference.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */

namespace FisHotel\Misc\Sections\Lost_Fish;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_lostfish_settings';

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'auto_send'     => false,
		'reminder_days' => 2,
		'link_days'     => 14,
		'subject'       => '',
		'body'          => '',
		'reply_to'      => '',
		'store_design'  => true,
		'free_classes'  => array( 'free' ),
	);

	const REMINDER_MIN = 0;
	const REMINDER_MAX = 30;
	const LINK_MIN     = 1;
	const LINK_MAX     = 90;
	const SUBJECT_MAX  = 200;
	const BODY_MAX     = 6000;

	/**
	 * The settings, normalised.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store settings (sanitised first).
	 *
	 * @param array $raw Raw values.
	 * @return array The stored settings.
	 */
	public static function save( array $raw ) {
		$clean = self::sanitize( $raw );
		update_option( self::OPTION_KEY, $clean, false );
		return $clean;
	}

	/**
	 * Stored values with the defaults and the right types (pure).
	 *
	 * @param array $stored Stored values.
	 * @return array
	 */
	public static function normalize( array $stored ) {
		$out = array_merge( self::DEFAULTS, array_intersect_key( $stored, self::DEFAULTS ) );

		$out['auto_send']     = ! empty( $out['auto_send'] );
		$out['store_design']  = ! empty( $out['store_design'] );
		$out['reminder_days'] = self::clamp( $out['reminder_days'], self::REMINDER_MIN, self::REMINDER_MAX, self::DEFAULTS['reminder_days'] );
		$out['link_days']     = self::clamp( $out['link_days'], self::LINK_MIN, self::LINK_MAX, self::DEFAULTS['link_days'] );
		$out['subject']       = is_string( $out['subject'] ) ? $out['subject'] : '';
		$out['body']          = is_string( $out['body'] ) ? $out['body'] : '';
		$out['reply_to']      = is_string( $out['reply_to'] ) ? $out['reply_to'] : '';
		$out['free_classes']  = self::slugs( $out['free_classes'] );

		return $out;
	}

	/**
	 * What a form posted, as the option stores it (pure apart from the
	 * WordPress sanitisers): a checkbox that is absent is off, a number
	 * out of range goes back to its default, the template is plain text.
	 *
	 * @param array $raw Unslashed form values.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$subject = isset( $raw['subject'] ) && is_scalar( $raw['subject'] ) ? sanitize_text_field( (string) $raw['subject'] ) : '';
		$body    = isset( $raw['body'] ) && is_scalar( $raw['body'] ) ? sanitize_textarea_field( (string) $raw['body'] ) : '';
		$reply   = isset( $raw['reply_to'] ) && is_scalar( $raw['reply_to'] ) ? sanitize_email( (string) $raw['reply_to'] ) : '';

		return self::normalize(
			array(
				'auto_send'     => ! empty( $raw['auto_send'] ) && '0' !== (string) $raw['auto_send'],
				'store_design'  => ! empty( $raw['store_design'] ) && '0' !== (string) $raw['store_design'],
				'reminder_days' => $raw['reminder_days'] ?? self::DEFAULTS['reminder_days'],
				'link_days'     => $raw['link_days'] ?? self::DEFAULTS['link_days'],
				'subject'       => substr( $subject, 0, self::SUBJECT_MAX ),
				'body'          => substr( $body, 0, self::BODY_MAX ),
				'reply_to'      => $reply,
				'free_classes'  => $raw['free_classes'] ?? self::DEFAULTS['free_classes'],
			)
		);
	}

	/**
	 * A list of slugs from an array or a comma / space separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function slugs( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $slug ) {
			$slug = is_scalar( $slug ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( (string) $slug ) ) ) : '';
			if ( '' !== $slug ) {
				$out[] = substr( $slug, 0, 60 );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 20 );
	}

	/**
	 * An integer within a range, or the default when it is not a whole
	 * number in that range.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $min     Lowest.
	 * @param int   $max     Highest.
	 * @param int   $default Default.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $default ) {
		if ( is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) || (float) $value !== (float) (int) $value ) {
			return (int) $default;
		}
		$value = (int) $value;
		return $value < $min || $value > $max ? (int) $default : $value;
	}
}
