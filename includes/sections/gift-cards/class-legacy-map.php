<?php
/**
 * THE OLD PLUGIN'S TABLES — the one map the importer reads.
 *
 * WooCommerce Gift Cards 2.7.7 (the paid plugin on the live shop) is NOT
 * on the machine this was built on, so its tables could not be read.
 * Only these facts are proven, by what the FisHotel theme queries:
 *
 *   cards     {prefix}woocommerce_gc_cards — columns id, recipient,
 *             sender_email, redeemed_by (empty / NULL / '0' = in nobody's
 *             account, else a user id)
 *   activity  {prefix}woocommerce_gc_activity — columns gc_id, object_id
 *             (the order id for a checkout use), type, amount; types
 *             'used' (a positive amount taken off the card on an order) and
 *             'refunded' (money put back)
 *
 * EVERYTHING ELSE BELOW IS AN INFERENCE from general knowledge of that
 * plugin, marked "INFERENCE". Before the first real import, read the real
 * structure on staging (no customer rows are needed):
 *
 *   SHOW CREATE TABLE wp_woocommerce_gc_cards;
 *   SHOW CREATE TABLE wp_woocommerce_gc_activity;
 *   SELECT type, COUNT(*) FROM wp_woocommerce_gc_activity GROUP BY type;
 *
 * and make this file say exactly that — it is the only edit needed. The
 * importer's schema check compares the real tables with COLUMNS here and
 * refuses to import while ANY column is missing, extra or of another kind,
 * naming each one; so a wrong guess here can only stop the import, never
 * copy the wrong thing.
 *
 * One more thing to prove on real rows before importing: if both
 * `balance` and `remaining` exist, which one is the amount the card was
 * issued for and which is what is left on it. ROLES says (INFERENCE)
 * balance = issued amount, remaining = left. The dry run checks it: a
 * card whose "left" is more than its "issued" amount is listed as a
 * difference.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Legacy_Map
 */
class Legacy_Map {

	/**
	 * The old tables, without the site's prefix. PROVEN (theme queries).
	 *
	 * @var array<string, string>
	 */
	const TABLES = array(
		'cards'    => 'woocommerce_gc_cards',
		'activity' => 'woocommerce_gc_activity',
	);

	/**
	 * Every column of each old table and its kind: int, decimal, text,
	 * datetime. The schema check wants EXACTLY these — no more, no fewer.
	 *
	 * @var array<string, array<string, string>>
	 */
	const COLUMNS = array(
		'cards'    => array(
			'id'            => 'int',      // PROVEN.
			'code'          => 'text',     // INFERENCE.
			'order_id'      => 'int',      // INFERENCE.
			'order_item_id' => 'int',      // INFERENCE.
			'recipient'     => 'text',     // PROVEN (an e-mail address).
			'redeemed_by'   => 'int',      // PROVEN (a user id).
			'sender'        => 'text',     // INFERENCE (a name).
			'sender_email'  => 'text',     // PROVEN.
			'message'       => 'text',     // INFERENCE.
			'balance'       => 'decimal',  // INFERENCE (the amount issued).
			'remaining'     => 'decimal',  // INFERENCE (what is left).
			'template_id'   => 'text',     // INFERENCE.
			'create_date'   => 'int',      // INFERENCE (UNIX time).
			'deliver_date'  => 'int',      // INFERENCE (UNIX time, 0 = at once).
			'delivered'     => 'text',     // INFERENCE ('yes' / 'no' or a time).
			'expire_date'   => 'int',      // INFERENCE (UNIX time, 0 = none).
			'redeem_date'   => 'int',      // INFERENCE (UNIX time).
			'is_virtual'    => 'text',     // INFERENCE ('on' / 'off').
			'is_active'     => 'text',     // INFERENCE ('on' / 'off').
		),
		'activity' => array(
			'id'         => 'int',      // INFERENCE.
			'type'       => 'text',     // PROVEN.
			'user_id'    => 'int',      // INFERENCE.
			'user_email' => 'text',     // INFERENCE.
			'object_id'  => 'int',      // PROVEN (the order id).
			'gc_id'      => 'int',      // PROVEN (the card id).
			'gc_code'    => 'text',     // INFERENCE.
			'amount'     => 'decimal',  // PROVEN (positive).
			'date'       => 'int',      // INFERENCE (UNIX time).
			'note'       => 'text',     // INFERENCE.
		),
	);

	/**
	 * Which old column feeds which field of the new card / ledger row.
	 * A role whose column is '' is not there (the field stays empty).
	 *
	 * @var array<string, array<string, string>>
	 */
	const ROLES = array(
		'cards'    => array(
			'legacy_id'       => 'id',
			'code'            => 'code',
			'order_id'        => 'order_id',
			'recipient_name'  => '',
			'recipient_email' => 'recipient',
			'owner_user_id'   => 'redeemed_by',
			'sender_name'     => 'sender',
			'sender_email'    => 'sender_email',
			'message'         => 'message',
			'amount'          => 'balance',
			'balance'         => 'remaining',
			'created_at'      => 'create_date',
			'deliver_at'      => 'deliver_date',
			'delivered'       => 'delivered',
			'expires_at'      => 'expire_date',
			'redeemed_at'     => 'redeem_date',
			'active'          => 'is_active',
		),
		'activity' => array(
			'legacy_id' => 'id',
			'type'      => 'type',
			'user_id'   => 'user_id',
			'order_id'  => 'object_id',
			'card_id'   => 'gc_id',
			'amount'    => 'amount',
			'date'      => 'date',
			'note'      => 'note',
		),
	);

	/**
	 * How dates are stored: 'unix' (an integer, 0 = none) or 'datetime'.
	 * INFERENCE: unix (the theme passes the getters to date_i18n()).
	 *
	 * @var string
	 */
	const DATES = 'unix';

	/**
	 * The values of is_active that mean "active". INFERENCE.
	 *
	 * @var string[]
	 */
	const ACTIVE_VALUES = array( 'on', 'yes', '1' );

	/**
	 * The values of delivered that mean "the e-mail went". INFERENCE
	 * (any other non-empty, non-zero value is taken as a time it went).
	 *
	 * @var string[]
	 */
	const NOT_DELIVERED_VALUES = array( '', '0', 'no', 'off' );

	/**
	 * Old activity types → the new ledger type and the sign of its amount
	 * (old amounts are positive). 'used' and 'refunded' are PROVEN; the
	 * rest are INFERENCE. A type not listed here is reported by the dry run
	 * and stops the import.
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	const ACTIVITY_TYPES = array(
		'issued'            => array( 'issued', 1 ),
		'redeemed'          => array( 'redeemed_to_account', 0 ),
		'used'              => array( 'used', -1 ),
		'refunded'          => array( 'restored', 1 ),
		'manually_credited' => array( 'credit', 1 ),
		'manually_debited'  => array( 'debit', -1 ),
	);
}
