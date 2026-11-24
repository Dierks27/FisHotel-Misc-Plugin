<?php
/**
 * ShipTracker's letters, read — the state of an order's sticker letter
 * from ShipTracker 1.10.0's `FST_Letters::get_by_order()`. No WordPress:
 * a stand-in FST_Letters (declared part-way through, as a site that adds
 * ShipTracker 1.10.0) answers from invented rows.
 *
 * Run from the plugin root:
 *   php tests/shiptracker-letters-test.php
 *
 * @package FisHotel\Misc
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['fh_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}

require_once dirname( __DIR__ ) . '/includes/class-shiptracker-letters.php';

use FisHotel\Misc\ShipTracker_Letters as L;

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * An invented letter row as ShipTracker stores it.
 *
 * @param string      $state  active | resent | voided.
 * @param string|null $mailed When it was mailed.
 * @return object
 */
function fh_row( $state, $mailed = null ) {
	return (object) array(
		'id'        => wp_rand_like(),
		'order_id'  => 5001,
		'state'     => $state,
		'mailed_at' => $mailed,
	);
}
function wp_rand_like() {
	static $n = 0;
	return ++$n;
}

/**
 * A stand-in order.
 */
class Fh_Order {
	public $id;
	public $meta;
	public function __construct( $id, array $meta = array() ) {
		$this->id   = $id;
		$this->meta = $meta;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}
}

echo "The state (pure)\n";
check( 'no letter: none', L::NONE === L::state( array() ) && L::NONE === L::state( null ) && L::NONE === L::state( 'x' ) );
check( 'a label made, not mailed: to mail', L::TO_MAIL === L::state( array( fh_row( 'active' ) ) ) );
check( 'marked mailed (or scanned): mailed', L::MAILED === L::state( array( fh_row( 'active', '2026-11-20 10:00:00' ) ) ) );
check( 'voided and regenerated, the new one not mailed: to mail', L::TO_MAIL === L::state( array( fh_row( 'active' ), fh_row( 'voided' ) ) ) );
check( 'every letter voided: none (the box offers a new one)', L::NONE === L::state( array( fh_row( 'voided' ), fh_row( 'voided', '2026-11-20 10:00:00' ) ) ) );
check( 'resent: the new letter not mailed yet — to mail again', L::TO_MAIL === L::state( array( fh_row( 'active' ), fh_row( 'resent', '2026-11-10 09:00:00' ) ) ) );
check( 'resent and the new one mailed too: mailed', L::MAILED === L::state( array( fh_row( 'active', '2026-11-21 09:00:00' ), fh_row( 'resent', '2026-11-10 09:00:00' ) ) ) );
check( 'rows as arrays read the same', L::MAILED === L::state( array( array( 'state' => 'active', 'mailed_at' => '2026-11-20 10:00:00' ) ) ) );
check( 'an empty or zero date is not a mailing', L::TO_MAIL === L::state( array( fh_row( 'active', '' ) ) ) && L::TO_MAIL === L::state( array( fh_row( 'active', '0000-00-00 00:00:00' ) ) ) );
check( 'nonsense rows are skipped', L::NONE === L::state( array( 'x', 3, null ) ) );

echo "Without ShipTracker 1.10.0\n";
$GLOBALS['fh_options']['fst_db_version'] = '1.5.0';
check( 'no FST_Letters (ShipTracker 1.9.x, or none): nothing to ask, no rows, none', ! class_exists( 'FST_Letters', false ) && ! L::available() && array() === L::rows( 5001 ) && L::NONE === L::state_of( 5001 ) && ! L::is_mailed( 5001 ) );
check( '— an order with the Sticker giveaway\'s flag has a letter all the same; one without has none', L::order_has_letter( new Fh_Order( 5001, array( '_fishotel_sticker_mail_letter' => 2 ) ) ) && ! L::order_has_letter( new Fh_Order( 5002 ) ) && ! L::order_has_letter( null ) );

/**
 * ShipTracker 1.10.0's letters, as a stand-in: its public get_by_order().
 */
if ( ! class_exists( 'FST_Letters', false ) ) { // Declared here, not hoisted: the checks above ran without it.
	class FST_Letters {
		public static $rows  = array();
		public static $asked = 0;
		public static function get_by_order( $order_id ) {
			self::$asked++;
			if ( 666 === $order_id ) {
				throw new RuntimeException( 'Table missing' );
			}
			return self::$rows[ $order_id ] ?? array();
		}
	}
}

echo "With ShipTracker 1.10.0\n";
unset( $GLOBALS['fh_options']['fst_db_version'] );
FST_Letters::$rows[5001] = array( fh_row( 'active', '2026-11-20 10:00:00' ) );
FST_Letters::$rows[5002] = array( fh_row( 'active' ) );
FST_Letters::$rows[5003] = array( fh_row( 'voided' ) );
check( 'its database not at 1.5.0 yet (no letters table): not asked', ! L::available() && array() === L::rows( 5001 ) && 0 === FST_Letters::$asked );
$GLOBALS['fh_options']['fst_db_version'] = '1.4.0';
check( '— 1.4.0 (label cost, supplies): not asked either', ! L::available() );
$GLOBALS['fh_options']['fst_db_version'] = '1.5.0';
check( 'at 1.5.0: asked', L::available() );
check( 'mailed, to mail, all voided', L::is_mailed( 5001 ) && L::TO_MAIL === L::state_of( 5002 ) && L::NONE === L::state_of( 5003 ) );
$asked = FST_Letters::$asked;
L::state_of( 5001 );
L::is_mailed( 5001 );
check( 'each order asked once per request', $asked === FST_Letters::$asked );
check( 'an order with a letter in ShipTracker but no flag (made by hand): has a letter', L::order_has_letter( new Fh_Order( 5002 ) ) );
check( '— every one voided and no flag: none', ! L::order_has_letter( new Fh_Order( 5003 ) ) );
check( 'ShipTracker failing: no rows, nothing breaks', array() === L::rows( 666 ) && L::NONE === L::state_of( 666 ) );
check( 'no order id: not asked', array() === L::rows( 0 ) && array() === L::rows( -3 ) );
$GLOBALS['fh_options']['fst_db_version'] = '1.10.2';
check( 'a later database version compares as a version, not as text', L::available() );

echo "Read only\n";
$code = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-shiptracker-letters.php' );
check( 'no SQL, no table name, nothing written', 0 === preg_match( '/\$wpdb|[\'"][a-z_]*fst_letters[\'"]|update_option|add_option|update_post_meta|->update_meta_data|->save\(/', $code ) );

echo "\n" . ( $fh_failures ? "$fh_failures of $fh_checks checks FAILED\n" : "All $fh_checks checks passed\n" );
exit( $fh_failures ? 1 : 0 );
