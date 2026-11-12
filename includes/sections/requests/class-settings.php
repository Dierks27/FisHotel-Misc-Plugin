<?php
/**
 * Requests settings — one autoloaded option (created with its defaults
 * when the tables are installed, so reading it never costs a query):
 *
 *   - "Open to the public" (off), the default fee and the cap of open
 *     requests per customer for the [fishotel_request_form] shortcode
 *   - "Tell people by themselves when their fish is ready" (on)
 *   - the fee timers: one reminder after N days, expiry after M days
 *   - the "still want it?" timer: after K months of waiting
 *   - the "came from" list (e-mail, in person, …)
 *   - the wallet-deposit product, only when Batch Manager's own setting
 *     should not be used
 *   - the five e-mails: on / off, subject and body each
 *
 * sanitize() is pure, so the rules are testable.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Option key (autoloaded).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fishotel_requests_settings';

	/**
	 * The e-mail kinds, in the order the Settings tab shows them.
	 *
	 * @var string[]
	 */
	const MAIL_KINDS = array( 'invoice', 'reminder', 'ready', 'still_want', 'declined' );

	const MAX_CAME_FROM = 30;
	const MAX_SUBJECT   = 200;
	const MAX_BODY      = 6000;

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'public_open'     => 0,
			'default_fee'     => 0.0,
			'public_cap'      => 5,
			'auto_offer'      => 1,
			'reminder_days'   => 3,
			'expire_days'     => 7,
			'ask_months'      => 4,
			'came_from'       => array( 'e-mail', 'in person', 'local', 'long-time client', 'forum', 'phone', 'other' ),
			'deposit_product' => 0,
			'mail'            => self::mail_defaults(),
		);
	}

	/**
	 * The e-mails as they are before anybody edits them. The body is
	 * plain text; the placeholders in {braces} are filled in when it is
	 * sent (Mailer::vars()).
	 *
	 * @return array<string, array{on: int, subject: string, body: string}>
	 */
	public static function mail_defaults() {
		return array(
			'invoice'    => array(
				'on'      => 1,
				'subject' => __( 'Your fish request at {site}: the request fee', 'fishotel-misc-plugin' ),
				'body'    => __(
					"Hello {name},\n\nYour request is approved:\n\n{fish}\n\nBefore we start looking there is a request fee of {fee}. It is not an extra charge: it goes into your wallet at {site}, and you can spend it there on anything you like.\n\nPay it here (you will be asked to log in, so the fee lands in your own wallet):\n{pay_url}\n\nThe fee is due within {days} days; after that the request closes by itself.\n\nSee or cancel your requests: {view_url}\n\nThank you,\n{site}",
					'fishotel-misc-plugin'
				),
			),
			'reminder'   => array(
				'on'      => 1,
				'subject' => __( 'A reminder: the fee for your fish request at {site}', 'fishotel-misc-plugin' ),
				'body'    => __(
					"Hello {name},\n\nA short reminder — your request is approved, and the request fee of {fee} is still open:\n\n{fish}\n\nPay it here: {pay_url}\n\nThe fee goes into your wallet at {site}. If it is not paid within {days} more days, the request closes by itself; you can always ask again.\n\nSee or cancel your requests: {view_url}\n\nThank you,\n{site}",
					'fishotel-misc-plugin'
				),
			),
			'ready'      => array(
				'on'      => 1,
				'subject' => __( 'The fish you asked for is ready at {site}', 'fishotel-misc-plugin' ),
				'body'    => __(
					"Hello {name},\n\nGood news: the fish you asked for has finished quarantine and is ready.\n\n{fish}\n\n{ready_line}\n\nSee or cancel your requests: {view_url}\n\nThank you,\n{site}",
					'fishotel-misc-plugin'
				),
			),
			'still_want' => array(
				'on'      => 1,
				'subject' => __( 'Do you still want this fish? ({site})', 'fishotel-misc-plugin' ),
				'body'    => __(
					"Hello {name},\n\nA while ago you asked us for:\n\n{fish}\n\nWe have not found it yet. Do you still want it?\n\nYes, keep my request: {keep_url}\n\nNo, cancel it: {cancel_url}\n\nEach link opens a page with one button to confirm. If we do not hear from you within {days} days, the request closes by itself — you can always ask again.\n\nThank you,\n{site}",
					'fishotel-misc-plugin'
				),
			),
			'declined'   => array(
				'on'      => 1,
				'subject' => __( 'About your fish request at {site}', 'fishotel-misc-plugin' ),
				'body'    => __(
					"Hello {name},\n\nThank you for asking. Sadly we cannot take this request:\n\n{fish}\n\n{note}\n\nThank you,\n{site}",
					'fishotel-misc-plugin'
				),
			),
		);
	}

	/**
	 * The e-mail kinds with the words the Settings tab uses.
	 *
	 * @return array<string, array{label: string, when: string}>
	 */
	public static function mail_labels() {
		return array(
			'invoice'    => array(
				'label' => __( 'Invoice for the fee', 'fishotel-misc-plugin' ),
				'when'  => __( 'Sent when a request is approved with a fee (and when the fee is changed).', 'fishotel-misc-plugin' ),
			),
			'reminder'   => array(
				'label' => __( 'Fee reminder', 'fishotel-misc-plugin' ),
				'when'  => __( 'Sent once, when the fee is still unpaid after the reminder days.', 'fishotel-misc-plugin' ),
			),
			'ready'      => array(
				'label' => __( 'The fish is ready', 'fishotel-misc-plugin' ),
				'when'  => __( 'Sent when the tank group of a request goes on sale — by itself, or when you click "Tell them".', 'fishotel-misc-plugin' ),
			),
			'still_want' => array(
				'label' => __( 'Still want it?', 'fishotel-misc-plugin' ),
				'when'  => __( 'Sent after the months of waiting below, to requests that came through a link or the public form. Switched off, nobody is asked and nothing expires.', 'fishotel-misc-plugin' ),
			),
			'declined'   => array(
				'label' => __( 'Declined', 'fishotel-misc-plugin' ),
				'when'  => __( 'Sent only when you tick "e-mail them" while declining.', 'fishotel-misc-plugin' ),
			),
		);
	}

	/**
	 * The settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		return self::merge( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Stored values over the defaults (pure): a missing key, a missing
	 * e-mail kind or an empty subject / body falls back to its default.
	 *
	 * @param array $stored Stored option.
	 * @return array
	 */
	public static function merge( array $stored ) {
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );

		if ( ! is_array( $out['came_from'] ) ) {
			$out['came_from'] = $defaults['came_from'];
		}

		$mail = is_array( $out['mail'] ) ? $out['mail'] : array();
		foreach ( $defaults['mail'] as $kind => $default ) {
			$row = isset( $mail[ $kind ] ) && is_array( $mail[ $kind ] ) ? $mail[ $kind ] : array();

			$mail[ $kind ] = array(
				'on'      => array_key_exists( 'on', $row ) ? ( ! empty( $row['on'] ) ? 1 : 0 ) : $default['on'],
				'subject' => isset( $row['subject'] ) && '' !== trim( (string) $row['subject'] ) ? (string) $row['subject'] : $default['subject'],
				'body'    => isset( $row['body'] ) && '' !== trim( (string) $row['body'] ) ? (string) $row['body'] : $default['body'],
			);
		}
		$out['mail'] = array_intersect_key( $mail, $defaults['mail'] );

		return $out;
	}

	/**
	 * Clean posted settings (pure): typed, clamped, unknown keys dropped.
	 * An e-mail's subject or body left empty goes back to its default.
	 *
	 * @param array $raw The posted form.
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$defaults = self::defaults();

		$int = static function ( $key, $min, $max, $default ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) || 1 !== preg_match( '/^\d{1,5}$/', trim( (string) $raw[ $key ] ) ) ) {
				return $default;
			}
			return max( $min, min( $max, (int) trim( (string) $raw[ $key ] ) ) );
		};

		$fee = Rules::fee( $raw['default_fee'] ?? '' );

		$out = array(
			'public_open'     => ! empty( $raw['public_open'] ) ? 1 : 0,
			'default_fee'     => null === $fee ? $defaults['default_fee'] : $fee,
			'public_cap'      => $int( 'public_cap', 1, 100, $defaults['public_cap'] ),
			'auto_offer'      => ! empty( $raw['auto_offer'] ) ? 1 : 0,
			'reminder_days'   => $int( 'reminder_days', 1, 60, $defaults['reminder_days'] ),
			'expire_days'     => $int( 'expire_days', 1, 120, $defaults['expire_days'] ),
			'ask_months'      => $int( 'ask_months', 1, 36, $defaults['ask_months'] ),
			'came_from'       => self::came_from_list( $raw['came_from'] ?? '' ),
			'deposit_product' => $int( 'deposit_product', 0, 99999, 0 ),
			'mail'            => array(),
		);

		// A product id may be longer than five digits.
		if ( isset( $raw['deposit_product'] ) && is_scalar( $raw['deposit_product'] ) && 1 === preg_match( '/^\d{1,12}$/', trim( (string) $raw['deposit_product'] ) ) ) {
			$out['deposit_product'] = (int) trim( (string) $raw['deposit_product'] );
		}

		// The reminder comes before the expiry, or it would never be sent.
		if ( $out['reminder_days'] >= $out['expire_days'] ) {
			$out['expire_days'] = $out['reminder_days'] + 1;
		}

		if ( ! $out['came_from'] ) {
			$out['came_from'] = $defaults['came_from'];
		}

		$mail = isset( $raw['mail'] ) && is_array( $raw['mail'] ) ? $raw['mail'] : array();
		foreach ( $defaults['mail'] as $kind => $default ) {
			$row     = isset( $mail[ $kind ] ) && is_array( $mail[ $kind ] ) ? $mail[ $kind ] : array();
			$subject = Rules::text( $row['subject'] ?? '', self::MAX_SUBJECT );
			$body    = Rules::lines( $row['body'] ?? '', self::MAX_BODY );

			$out['mail'][ $kind ] = array(
				'on'      => ! empty( $row['on'] ) ? 1 : 0,
				'subject' => '' !== $subject ? $subject : $default['subject'],
				'body'    => '' !== $body ? $body : $default['body'],
			);
		}

		return $out;
	}

	/**
	 * The "came from" list from a textarea (one per line) or an array:
	 * plain text, trimmed, no empties, no repeats, at most MAX_CAME_FROM.
	 *
	 * @param mixed $raw Lines or a list.
	 * @return string[]
	 */
	public static function came_from_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $raw as $item ) {
			$item = Rules::text( $item, 60 );
			$key  = Rules::name_key( $item );
			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= self::MAX_CAME_FROM ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Save cleaned settings (autoloaded).
	 *
	 * @param array $settings sanitize().
	 * @return bool
	 */
	public static function save( array $settings ) {
		return (bool) update_option( self::OPTION_KEY, $settings, true );
	}

	/**
	 * A "came from" value as it is stored on a request: one of the list
	 * (compared without case), else ''.
	 *
	 * @param mixed    $value The posted value.
	 * @param string[] $list  The settings' list.
	 * @return string
	 */
	public static function came_from( $value, array $list ) {
		$key = Rules::name_key( Rules::text( $value, 60 ) );
		if ( '' === $key ) {
			return '';
		}
		foreach ( $list as $item ) {
			if ( Rules::name_key( $item ) === $key ) {
				return (string) $item;
			}
		}
		return '';
	}
}
