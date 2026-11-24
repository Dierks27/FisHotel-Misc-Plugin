<?php
/**
 * ShipTracker's USPS letters, read — one answer for every section that
 * asks whether an order's sticker letter exists or was mailed.
 *
 * ShipTracker 1.10.0 (DB 1.5.0) keeps a letter as a row of its own table
 * `wp_fst_letters` (one row per barcode issued: `state` active / resent /
 * voided, `mailed_at` set once it is marked mailed or USPS scans it), and
 * answers `FST_Letters::get_by_order( order id )` with every row of an
 * order, voided ones included. That public function is the only thing
 * read here: never the table itself, and nothing is ever written.
 *
 * Without ShipTracker 1.10.0, or before its database reached 1.5.0 (the
 * option `fst_db_version`; the table does not exist yet then), every
 * order has no letter — Misc works as it did with ShipTracker 1.9.x.
 *
 * state() is pure:
 *   - 'none'    — no letter (none made yet, or every one voided);
 *   - 'to_mail' — a letter whose barcode is made but not mailed (a new
 *                 label, a regenerated one, or a resend not mailed yet);
 *   - 'mailed'  — every letter still standing has been mailed.
 *
 * @package FisHotel\Misc
 */

namespace FisHotel\Misc;

defined( 'ABSPATH' ) || exit;

/**
 * Class ShipTracker_Letters
 */
class ShipTracker_Letters {

	/**
	 * Order meta the Sticker giveaway section writes on an EA-only order
	 * whose stickers go by letter (how many). ShipTracker reads the same
	 * key (`FST_Letters::STICKER_META`).
	 *
	 * @var string
	 */
	const STICKER_META = '_fishotel_sticker_mail_letter';

	/**
	 * ShipTracker's class and the database version that has its table.
	 *
	 * @var string
	 */
	const LETTERS_CLASS = 'FST_Letters';
	const MIN_DB        = '1.5.0';

	/**
	 * The states.
	 *
	 * @var string
	 */
	const NONE    = 'none';
	const TO_MAIL = 'to_mail';
	const MAILED  = 'mailed';

	/**
	 * Rows read in this request, by order id.
	 *
	 * @var array<int, array>
	 */
	private static $cache = array();

	/**
	 * Whether ShipTracker's letters can be asked.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! class_exists( self::LETTERS_CLASS, false ) || ! is_callable( array( self::LETTERS_CLASS, 'get_by_order' ) ) ) {
			return false;
		}
		$db = function_exists( 'get_option' ) ? get_option( 'fst_db_version', '0' ) : '0';
		return is_scalar( $db ) && version_compare( (string) $db, self::MIN_DB, '>=' );
	}

	/**
	 * The letter rows of an order (newest first), or none.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public static function rows( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 || ! self::available() ) {
			return array();
		}
		if ( ! array_key_exists( $order_id, self::$cache ) ) {
			try {
				$rows = call_user_func( array( self::LETTERS_CLASS, 'get_by_order' ), $order_id );
			} catch ( \Throwable $e ) {
				$rows = array();
			}
			self::$cache[ $order_id ] = is_array( $rows ) ? $rows : array();
		}
		return self::$cache[ $order_id ];
	}

	/**
	 * The state of an order's letters (pure).
	 *
	 * @param mixed $rows Letter rows (objects or arrays: state, mailed_at).
	 * @return string self::NONE | self::TO_MAIL | self::MAILED.
	 */
	public static function state( $rows ) {
		$mailed = false;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : $row;
			if ( ! is_array( $row ) ) {
				continue;
			}
			$state = isset( $row['state'] ) && is_scalar( $row['state'] ) ? (string) $row['state'] : '';
			if ( 'voided' === $state ) {
				continue;
			}
			$sent = isset( $row['mailed_at'] ) && is_scalar( $row['mailed_at'] ) && '' !== trim( (string) $row['mailed_at'] ) && 0 !== strpos( (string) $row['mailed_at'], '0000-00-00' );
			if ( 'active' === $state && ! $sent ) {
				return self::TO_MAIL;
			}
			$mailed = $mailed || $sent;
		}
		return $mailed ? self::MAILED : self::NONE;
	}

	/**
	 * The state of an order's letters.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function state_of( $order_id ) {
		return self::state( self::rows( $order_id ) );
	}

	/**
	 * Whether every letter of the order still standing has been mailed.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function is_mailed( $order_id ) {
		return self::MAILED === self::state_of( $order_id );
	}

	/**
	 * Whether an order has a sticker letter to think about: the Sticker
	 * giveaway's flag, or a letter in ShipTracker.
	 *
	 * @param \WC_Order|object|null $order Order.
	 * @return bool
	 */
	public static function order_has_letter( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		if ( method_exists( $order, 'get_meta' ) && (int) $order->get_meta( self::STICKER_META, true ) > 0 ) {
			return true;
		}
		return method_exists( $order, 'get_id' ) && self::NONE !== self::state_of( (int) $order->get_id() );
	}

	/**
	 * Forget what was read (tests).
	 */
	public static function forget() {
		self::$cache = array();
	}
}
