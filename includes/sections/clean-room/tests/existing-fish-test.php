<?php
/**
 * "Add fish already here" — fish that were in the room before it was on
 * the site go straight into a tank, any tank, with what is known of
 * their arrival, their clearing and where they came from; Unknown is a
 * state, never a guessed date. Driven through Front_Desk::perform() (the
 * phone) and Admin::add_existing() (the Stays tab) against the in-memory
 * Fake_Store:
 *
 *   1. section off: nothing registered, nothing answered;
 *   2. schema 1.7: an empty check-in, the source 'existing', the gate and
 *      the ALTER that keeps every row;
 *   3. the Replay with unknown dates (pure);
 *   4. the sheet's answers (pure): every combination of known / unknown,
 *      a clean tank against a quarantine one;
 *   5. the act on a Clean Room tank, a Spa tank and temporary tanks;
 *   6. every later action on a stay with unknown dates: loss, shipped,
 *      recount, move, split, dates set later, sale date, undo;
 *   7. the To do list with unknown dates;
 *   8. the product facts: null, never "unknown";
 *   9. the Stats: counted in live / shipped / lost, left out of the rest;
 *  10. the phone's screen, the Board's words, the exports;
 *  11. the Stays tab's form and the two AJAX endpoints: capability, nonce,
 *      the tables, the upgrade.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/existing-fish-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events with the real clock.
$settings  = Settings::get();
$protocols = Settings::protocols();

/**
 * A store with two products and the standard sizes.
 */
function fh_existing_store() {
	$store                  = new Fake_Store( array( 12 => 'Test Cardinalfish', 13 => 'Test Clownfish' ) );
	$store->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms      = array(
		'pa_size' => array(
			array( 'term_id' => 30, 'name' => '1-2"', 'slug' => '1-2', 'menu_order' => 0 ),
			array( 'term_id' => 32, 'name' => '2-3"', 'slug' => '2-3', 'menu_order' => 1 ),
		),
	);
	return $store;
}

function ctx( Fake_Store $store, $tank_number, $actor = 8 ) {
	global $settings, $today;
	return array(
		'store'    => $store,
		'settings' => $settings,
		'today'    => $today,
		'tank'     => $tank_number > 0 ? $store->tank_by_number( $tank_number ) : null,
		'base'     => array(
			'actor_id' => $actor,
			'source'   => 'qr',
		),
	);
}

function act( Fake_Store $store, $tank_number, $act, array $raw, $actor = 8 ) {
	return Front_Desk::perform( $act, ctx( $store, $tank_number, $actor ), $raw );
}

function stay_of( Fake_Store $store, $id ) {
	global $today, $settings;
	return $store->stay( (int) $id, $today, $settings );
}

/* ------------------------------------------------------------------ */
echo "1. Switched off: nothing registered, nothing answered\n";

check( 'the section has not booted in this run', false === Clean_Room::is_booted() );
check( 'no AJAX action of the phone or of the Stays tab is registered while it is off', empty( $GLOBALS['fh_actions']['wp_ajax_fishotel_cleanroom_act'] ) && empty( $GLOBALS['fh_actions']['wp_ajax_fishotel_cleanroom_existing'] ) );
check( 'the product facts answer null while it is off', null === Clean_Room::product_facts( 12 ) && null === fishotel_cleanroom_product_facts( 12 ) );
( new Admin() )->init();
( new Front_Desk() )->init();
check( 'switched on, the Stays tab\'s action and the phone\'s are registered (the hook callbacks exist)', ! empty( $GLOBALS['fh_actions']['wp_ajax_fishotel_cleanroom_existing'] ) && ! empty( $GLOBALS['fh_actions']['wp_ajax_fishotel_cleanroom_act'] ) && is_callable( $GLOBALS['fh_actions']['wp_ajax_fishotel_cleanroom_existing'][0][0] ) );
$GLOBALS['fh_actions'] = array();

/* ------------------------------------------------------------------ */
echo "2. Schema 1.7: an empty check-in and the source 'existing'\n";

$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$tables          = array();
foreach ( Store::schema() as $statement ) {
	if ( preg_match( '/CREATE TABLE \S*fh_(\w+) \(/', $statement, $m ) ) {
		$tables[ $m[1] ] = $statement;
	}
}
check( 'the schema version is 1.7', '1.7' === Store::DB_VERSION );
check( 'fh_stays.checkin_date takes NULL (an arrival nobody knows)', 1 === preg_match( "/\n\t+checkin_date DATE NULL,\n/", $tables['stays'] ) );
check( 'the stays and their events take the source \'existing\'; the tank logs do not need it', false !== strpos( $tables['stays'], "source ENUM('manual','voice','qr','import','order','existing')" ) && false !== strpos( $tables['stay_events'], "source ENUM('manual','voice','qr','import','order','existing')" ) && false === strpos( $tables['tank_logs'], 'existing' ) );
check( 'the store knows the source: stays and events, not the logs', in_array( 'existing', Store::STAY_SOURCES, true ) && ! in_array( 'existing', Store::SOURCES, true ) && 'existing' === Store::SOURCE_EXISTING );
check( 'the gate waits for both new ENUM values', array( 'existing' ) === Store::REQUIRED_ENUM_VALUES['stays.source'] && array( 'existing' ) === Store::REQUIRED_ENUM_VALUES['stay_events.source'] );
$old = array(
	'buildings.area'     => "enum('clean','spa','temp')",
	'stays.source'       => "enum('manual','voice','qr','import','order')",
	'stay_events.source' => "enum('manual','voice','qr','import','order')",
);
check( 'from 1.6: the two sources are missing — the gate stays shut', array( 'stays.source=existing', 'stay_events.source=existing' ) === Store::missing_enum_values( $old ) );
$new = array_merge( $old, array( 'stays.source' => "ENUM('manual','voice','qr','import','order','existing')", 'stay_events.source' => "enum('manual','voice','qr','import','order','existing')" ) );
check( 'with both it opens (whatever the case)', array() === Store::missing_enum_values( $new ) );
check( 'and it waits for the check-in to take NULL', array( 'stays.checkin_date' ) === Store::missing_nullable( array( 'stays.checkin_date' => false ) ) && array( 'stays.checkin_date' ) === Store::missing_nullable( array() ) && array() === Store::missing_nullable( array( 'stays.checkin_date' => true ) ) );
check( 'what makes it so: DATE NULL (a MODIFY keeps every row and its date)', array( 'stays.checkin_date' => 'DATE NULL' ) === Store::REQUIRED_NULLABLE );

// The ALTER itself, against a database that still has the 1.6 column.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
$GLOBALS['wpdb'] = new class() {
	public $prefix  = 'wp_';
	public $null    = 'NO';
	public $queries = array();
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}
	public function get_row( $query, $output = null ) {
		return false !== strpos( $query, "LIKE 'checkin_date'" ) ? array( 'Field' => 'checkin_date', 'Type' => 'date', 'Null' => $this->null ) : null;
	}
	public function query( $query ) {
		$this->queries[] = $query;
		if ( 0 === strpos( $query, 'ALTER TABLE wp_fh_stays MODIFY checkin_date DATE NULL' ) ) {
			$this->null = 'YES';
		}
		return true;
	}
};
$make = new ReflectionMethod( Store::class, 'make_nullable' );
$make->setAccessible( true );
$make->invoke( null );
check( 'the upgrade runs one ALTER … MODIFY checkin_date DATE NULL on the stays table', array( 'ALTER TABLE wp_fh_stays MODIFY checkin_date DATE NULL' ) === $GLOBALS['wpdb']->queries, json_encode( $GLOBALS['wpdb']->queries ) );
$make->invoke( null );
check( 'and nothing once the column takes NULL (the next boot costs no ALTER)', 1 === count( $GLOBALS['wpdb']->queries ), json_encode( $GLOBALS['wpdb']->queries ) );
unset( $GLOBALS['wpdb'] );

update_option( Store::DB_VERSION_OPTION, '1.6' );
check( 'schema_current(): not until the 1.7 upgrade is written', false === Store::schema_current() );
update_option( Store::DB_VERSION_OPTION, '1.7' );
check( 'schema_current(): once it is', true === Store::schema_current() );

check( 'a new row: the date given, NULL when it is given as unknown, today as before when nothing is said', '2025-03-01' === Store::checkin_or_null( array( 'checkin_date' => '2025-03-01' ) ) && null === Store::checkin_or_null( array( 'checkin_date' => null ) ) && null === Store::checkin_or_null( array( 'checkin_date' => '' ) ) && gmdate( 'Y-m-d' ) === Store::checkin_or_null( array() ) );
check( 'the cached check-in: the replay\'s date, NULL when it is unknown', '2025-03-01' === Store::cached_checkin( array( 'checkin_date' => '2025-03-01' ) ) && null === Store::cached_checkin( array( 'checkin_date' => '', 'checkin_unknown' => true ) ) );

/* ------------------------------------------------------------------ */
echo "3. The Replay with unknown dates (pure)\n";

$at  = static function ( $days_ago, $time = '12:00:00' ) use ( $today ) {
	return Replay::add_days( $today, -$days_ago ) . ' ' . $time;
};
$ev  = static function ( $id, $type, array $more = array() ) {
	return array_merge( array( 'id' => $id, 'type' => $type, 'qty' => null, 'note' => '', 'event_at' => gmdate( 'Y-m-d H:i:s' ), 'source' => 'existing' ), $more );
};
$opt = array( 'tank_areas' => array( 1 => 'clean', 2 => 'clean', 10 => 'spa' ) );

$arrive = $ev( 1, 'arrival', array( 'qty' => 3, 'to_tank_id' => 1, 'note' => Replay::NOTE_ARRIVED_UNKNOWN . ' · already here' ) );
$s      = Replay::state( array( 'checkin_date' => '2024-01-01' ), array( $arrive ), $today, $opt );
check( 'an "arrived:unknown" arrival gives no check-in — not today, not the row\'s old date', '' === $s['checkin_date'] && true === $s['checkin_unknown'] && null === $s['day'] && '' === $s['sale_ready_suggested'] && 3 === $s['qty_live'] && 'in_qt' === $s['status'] );
$clear = $ev( 2, 'clear', array( 'note' => Replay::NOTE_CLEARED_UNKNOWN ) );
$s     = Replay::state( array(), array( $arrive, $clear ), $today, $opt );
check( 'a "cleared:unknown" clear: cleared, its date unknown (never a guessed one)', 'cleared' === $s['status'] && null === $s['cleared_date'] && true === $s['cleared_unknown'] );
$move = $ev( 3, 'move', array( 'from_tank_id' => 1, 'to_tank_id' => 10 ) );
$back = $ev( 4, 'move', array( 'from_tank_id' => 10, 'to_tank_id' => 2 ) );
$s    = Replay::state( array(), array( $arrive, $clear, $move, $back ), $today, $opt );
check( 'out to the Spa (hospital) and back into the Clean Room: still cleared on an unknown date, never "cleared today"', 'cleared' === $s['status'] && null === $s['cleared_date'] && $s['cleared_unknown'] && 2 === $s['tank_id'] );
$s = Replay::state( array(), array( $arrive, $clear, $ev( 5, 'clear', array( 'note' => Replay::NOTE_CLEARED . '2025-02-03' ) ) ), $today, $opt );
check( 'a cleared date set later wins: the screens behave as for any stay', '2025-02-03' === $s['cleared_date'] && false === $s['cleared_unknown'] && 'cleared' === $s['status'] );
$s = Replay::state( array(), array( $arrive, $clear, $ev( 5, 'clear', array( 'qty' => -1, 'note' => 'undo of #2' ) ) ), $today, $opt );
check( 'the undo of the clear (−1): not cleared any more', 'in_qt' === $s['status'] && null === $s['cleared_date'] && false === $s['cleared_unknown'] );
$s = Replay::state( array(), array( $arrive, $clear, $ev( 3, 'clear', array( 'qty' => -1 ) ), $ev( 4, 'move', array( 'from_tank_id' => 1, 'to_tank_id' => 10 ) ), $ev( 5, 'move', array( 'from_tank_id' => 10, 'to_tank_id' => 2 ) ) ), $today, $opt );
check( 'after that, a move out of the Spa into the Clean Room clears it today, as for any group', $today === $s['cleared_date'] && 'cleared' === $s['status'] );
$set = $ev( 6, 'note', array( 'note' => Replay::NOTE_CHECKIN . Replay::add_days( $today, -9 ) ) );
$s   = Replay::state( array(), array( $arrive, $set ), $today, $opt );
check( 'a check-in date set later: day 10, the suggested sale date follows', Replay::add_days( $today, -9 ) === $s['checkin_date'] && false === $s['checkin_unknown'] && 10 === $s['day'] && Replay::add_days( $today, 19 ) === $s['sale_ready_suggested'] );
$s = Replay::state( array( 'checkin_date' => Replay::add_days( $today, -9 ) ), array( $arrive, $set, $ev( 7, 'note', array( 'note' => Replay::NOTE_CHECKIN ) ) ), $today, $opt );
check( '"checkin:" with no date (the undo of that note): back to the arrival — unknown, whatever the row cached', '' === $s['checkin_date'] && $s['checkin_unknown'] && null === $s['day'] );
$known = $ev( 1, 'arrival', array( 'qty' => 3, 'to_tank_id' => 1, 'note' => 'already here', 'event_at' => $at( 40 ) ) );
$s     = Replay::state( array(), array( $known ), $today, $opt );
check( 'a known arrival date is the arrival\'s own (dated that day): day 41', Replay::add_days( $today, -40 ) === $s['checkin_date'] && 41 === $s['day'] && false === $s['checkin_unknown'] );
check( 'the undo of a "cleared:unknown" clear is a clear −1; of the arrival an arrival −n', array( 'clear', -1 ) === array( Replay::compensating( $clear )['type'], Replay::compensating( $clear )['qty'] ) && -3 === Replay::compensating( $arrive )['qty'] );
$unclear = $ev( 8, 'clear', array( 'qty' => -1 ) );
$c       = Replay::compensating( $unclear, array( 'cleared_unknown' => true, 'cleared_date' => null ) );
check( 'the undo of an un-clear of a "cleared: unknown" stay writes "cleared:unknown" back', 'clear' === $c['type'] && null === $c['qty'] && Replay::NOTE_CLEARED_UNKNOWN === $c['note'] );
$c = Replay::compensating( $unclear, array( 'cleared_unknown' => false, 'cleared_date' => '2025-02-03' ) );
check( '… of a dated one, its date', 'clear' === $c['type'] && null === $c['qty'] && Replay::NOTE_CLEARED . '2025-02-03' === $c['note'] );
check( '… of one that was not cleared: nothing to give back', null === Replay::compensating( $unclear, array( 'cleared_unknown' => false, 'cleared_date' => null ) ) );
$s = Replay::state( array(), array( $arrive, $clear, $unclear, $ev( 9, 'clear', Replay::compensating( $unclear, array( 'cleared_unknown' => true ) ) ) ), $today, $opt );
check( '… and the replay of that round trip: cleared, the date unknown', 'cleared' === $s['status'] && $s['cleared_unknown'] && null === $s['cleared_date'] );
check( 'is_arrived_unknown / is_cleared_unknown read the notes, not anything else', Replay::is_arrived_unknown( $arrive ) && ! Replay::is_arrived_unknown( $known ) && ! Replay::is_arrived_unknown( $ev( 9, 'note', array( 'note' => Replay::NOTE_ARRIVED_UNKNOWN ) ) ) && Replay::is_cleared_unknown( $clear ) && ! Replay::is_cleared_unknown( $ev( 9, 'clear', array( 'qty' => -1, 'note' => Replay::NOTE_CLEARED_UNKNOWN ) ) ) );
$e = Replay::effective( array( 'status' => 'cleared', 'checkin_date' => '', 'cleared_date' => null ), $today );
check( 'effective(): a stored "cleared" without a date stays cleared, its dates unknown, no day', 'cleared' === $e['status'] && $e['cleared_unknown'] && $e['checkin_unknown'] && null === $e['day'] && '' === $e['sale_ready_suggested'] );
$e = Replay::effective( array( 'status' => 'in_qt', 'checkin_date' => '2025-01-01', 'cleared_date' => null ), $today );
check( 'effective(): an ordinary stay reads as before', 'in_qt' === $e['status'] && ! $e['cleared_unknown'] && ! $e['checkin_unknown'] );

/* ------------------------------------------------------------------ */
echo "4. The sheet's answers (pure): Unknown is the default\n";

$ans = static function ( $area, array $raw ) use ( $today ) {
	return Front_Desk::existing_answers( $area, $raw, $today );
};
$a = $ans( 'clean', array() );
check( 'a clean tank, nothing answered: arrival unknown, cleared on an unknown date, origin unknown', '' === $a['arrived'] && '' === $a['cleared'] && true === $a['cleared_unknown'] && '' === $a['supplier'] && '' === $a['origin'] );
$a = $ans( 'clean', array( 'arrived' => 'unknown', 'cleared' => 'unknown', 'supplier' => 'Unknown', 'origin' => 'UNKNOWN' ) );
check( '"unknown" said out loud is the same', '' === $a['arrived'] && $a['cleared_unknown'] && '' === $a['supplier'] && '' === $a['origin'] );
$a = $ans( 'clean', array( 'arrived' => '2025-05-01', 'cleared' => '2025-05-20', 'supplier' => ' Test Supplier ', 'origin' => 'Testland' ) );
check( 'a clean tank, every date known', '2025-05-01' === $a['arrived'] && '2025-05-20' === $a['cleared'] && false === $a['cleared_unknown'] && 'Test Supplier' === $a['supplier'] && 'Testland' === $a['origin'] );
$a = $ans( 'clean', array( 'arrived' => 'unknown', 'cleared' => '2025-05-20' ) );
check( 'a clean tank: arrival unknown, cleared date known', '' === $a['arrived'] && '2025-05-20' === $a['cleared'] && ! $a['cleared_unknown'] );
$a = $ans( 'clean', array( 'arrived' => '2025-05-01', 'cleared' => 'unknown' ) );
check( 'a clean tank: arrival known, cleared on an unknown date', '2025-05-01' === $a['arrived'] && $a['cleared_unknown'] );
$a = $ans( 'clean', array( 'cleared' => 'no' ) );
check( 'a clean tank: "not cleared yet" is refused — a fish there counts as cleared', isset( $a['error'] ) && false !== strpos( $a['error'], 'counts as cleared' ) );
$a = $ans( 'spa', array() );
check( 'a quarantine tank, nothing answered: not cleared yet (the default), arrival unknown', '' === $a['arrived'] && '' === $a['cleared'] && false === $a['cleared_unknown'] );
$a = $ans( 'spa', array( 'cleared' => 'no', 'arrived' => '2025-06-01' ) );
check( 'a quarantine tank: "no" is not cleared yet', '' === $a['cleared'] && ! $a['cleared_unknown'] && '2025-06-01' === $a['arrived'] );
$a = $ans( 'spa', array( 'cleared' => '2025-06-10' ) );
check( 'a quarantine tank: a cleared date may be given', '2025-06-10' === $a['cleared'] && ! $a['cleared_unknown'] );
$a = $ans( 'spa', array( 'cleared' => 'unknown' ) );
check( 'a quarantine tank: "cleared, date unknown" is not offered — refused', isset( $a['error'] ) && false !== strpos( $a['error'], 'Not cleared yet' ) );
foreach ( array(
	'a date in the future'        => array( 'arrived' => Replay::add_days( $today, 1 ) ),
	'the year 0026 (a typed "26")' => array( 'arrived' => '0026-05-01' ),
	'not a date'                  => array( 'arrived' => 'last spring' ),
	'a cleared date in the future' => array( 'cleared' => Replay::add_days( $today, 3 ) ),
	'a cleared date in 1999'      => array( 'cleared' => '1999-12-31' ),
	'cleared before it arrived'   => array( 'arrived' => '2025-05-10', 'cleared' => '2025-05-01' ),
) as $what => $raw ) {
	$a = $ans( 'clean', $raw );
	check( 'refused: ' . $what, isset( $a['error'] ), json_encode( $a ) );
}
$a = $ans( 'clean', array( 'arrived' => $today, 'cleared' => $today ) );
check( 'today is fine for both', $today === $a['arrived'] && $today === $a['cleared'] );

/* ------------------------------------------------------------------ */
echo "5. The act: a Clean Room tank, a Spa tank, temporary tanks\n";

$store = fh_existing_store();
$r     = act( $store, 121, 'existing', array( 'product_id' => 12, 'qty' => 4, 'size' => '1-2' ) );
check( 'Clean Room tank 121, nothing else known: added straight in, the toast says what is known', empty( $r['error'] ) && '4 × Test Cardinalfish · 1-2" added to Tank 121 (already here) · arrived: unknown · cleared: unknown' === $r['message'] && 2 === count( $r['event_ids'] ), json_encode( $r ) );
$s   = stay_of( $store, $r['stay_id'] );
$row = $store->stays[ $r['stay_id'] ];
check( 'the stay: 4 live in 121, cleared, its dates empty (NULL in the row), source existing, product 12, size 1-2"', 4 === $s['qty_live'] && 121 === $s['tank_number'] && 'cleared' === $s['status'] && '' === $s['checkin_date'] && null === $row['checkin_date'] && null === $s['cleared_date'] && null === $row['cleared_date'] && 'existing' === $row['source'] && 12 === $s['product_id'] && '1-2' === $s['size_slug'], json_encode( $row ) );
check( '… the screens read it: arrival unknown, cleared on an unknown date, no day, no suggested sale date', $s['checkin_unknown'] && $s['cleared_unknown'] && null === $s['day'] && '' === $s['sale_ready_suggested'] && 'cleared' === $s['state_label'] );
$events = $store->events( $r['stay_id'] );
check( 'its events: an arrival noted "arrived:unknown" and a clear noted "cleared:unknown", both source existing, no Spa step', 2 === count( $events ) && 'arrival' === $events[0]['type'] && 0 === strpos( $events[0]['note'], Replay::NOTE_ARRIVED_UNKNOWN ) && 'clear' === $events[1]['type'] && Replay::NOTE_CLEARED_UNKNOWN === $events[1]['note'] && 'existing' === $events[0]['source'] && 'existing' === $events[1]['source'] && 121 === $events[0]['to_tank_number'] && null === $events[0]['from_tank_id'] );
$first = (int) $r['stay_id'];

$r = act( $store, 122, 'existing', array( 'species' => 'Test Clownfish', 'product_id' => 13, 'qty' => 2, 'arrived' => Replay::add_days( $today, -60 ), 'cleared' => Replay::add_days( $today, -30 ), 'supplier' => 'Test Supplier', 'origin' => 'Testland' ) );
$s = stay_of( $store, $r['stay_id'] );
check( 'Clean Room tank 122 with both dates: check-in 60 days ago (day 61), cleared 30 days ago, supplier kept, origin in the note', empty( $r['error'] ) && Replay::add_days( $today, -60 ) === $s['checkin_date'] && 61 === $s['day'] && Replay::add_days( $today, -30 ) === $s['cleared_date'] && ! $s['cleared_unknown'] && ! $s['checkin_unknown'] && 'Test Supplier' === $s['supplier'] && 'Origin: Testland' === $s['notes'] && null === $s['order_line_id'], json_encode( $r ) );
check( '… the toast names the dates', false !== strpos( $r['message'], 'arrived ' . Replay::add_days( $today, -60 ) ) && false !== strpos( $r['message'], 'cleared ' . Replay::add_days( $today, -30 ) ) );
check( '… and its arrival is dated that day, so the replay needs nothing else', Replay::add_days( $today, -60 ) === Replay::event_date( $store->events( $r['stay_id'] )[0]['event_at'], wp_timezone() ) && 'already here' === $store->events( $r['stay_id'] )[0]['note'] );

$r = act( $store, 521, 'existing', array( 'species' => 'Test Goby', 'qty' => 3 ) );
$s = stay_of( $store, $r['stay_id'] );
check( 'Spa tank 521, nothing known: in QT, not cleared, no clear event, an unlisted fish linked to no product', empty( $r['error'] ) && 'in_qt' === $s['status'] && null === $s['cleared_date'] && ! $s['cleared_unknown'] && 1 === count( $r['event_ids'] ) && null === $s['product_id'] && 'Test Goby' === $s['species'] && false !== strpos( $r['message'], 'not cleared yet' ), json_encode( $r ) );
$spa_unknown = (int) $r['stay_id'];
$r = act( $store, 521, 'existing', array( 'species' => 'small wrasse', 'unknown' => 1, 'qty' => 1, 'arrived' => Replay::add_days( $today, -3 ), 'cleared' => Replay::add_days( $today, -1 ) ) );
$s = stay_of( $store, $r['stay_id'] );
check( 'Spa tank 521, an unknown fish (??) with a cleared date: "?? small wrasse", needs identifying, cleared on that date', empty( $r['error'] ) && '?? small wrasse' === $s['species'] && $s['needs_id'] && Replay::add_days( $today, -1 ) === $s['cleared_date'] && 'cleared' === $s['status'] && 4 === $s['day'] );
$count = count( $store->stays );
$r     = act( $store, 521, 'existing', array( 'species' => 'Test Goby', 'qty' => 1, 'cleared' => 'unknown' ) );
check( 'Spa tank: "cleared, date unknown" refused, nothing written', isset( $r['error'] ) && $count === count( $store->stays ) );
foreach ( array(
	'no fish'                   => array( 'qty' => 2 ),
	'an unknown product only'   => array( 'product_id' => 99, 'qty' => 2 ),
	'qty 0'                     => array( 'species' => 'Test Goby', 'qty' => 0 ),
	'qty 1000'                  => array( 'species' => 'Test Goby', 'qty' => 1000 ),
	'a size not in the list'    => array( 'species' => 'Test Goby', 'size' => 'huge' ),
	'a clean tank, not cleared' => array( 'species' => 'Test Goby', 'cleared' => 'no', '_tank' => 121 ),
	'a date in the future'      => array( 'species' => 'Test Goby', 'arrived' => Replay::add_days( $today, 2 ) ),
) as $what => $raw ) {
	$tank = isset( $raw['_tank'] ) ? (int) $raw['_tank'] : 521;
	unset( $raw['_tank'] );
	$r = act( $store, $tank, 'existing', $raw );
	check( 'refused: ' . $what, isset( $r['error'] ), json_encode( $r ) );
}
check( '… and nothing was written by any of them', $count === count( $store->stays ) );

// Temporary tanks: a quarantine one is a Spa tank, a clean one a Clean Room tank, a free slot holds no fish.
$q = act( $store, 0, 'setup', array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10 ) );
$c = act( $store, 0, 'setup', array( 'name' => 'Holding', 'kind' => 'clean', 'gallons' => 20 ) );
check( 'two temporary tanks set up (901 quarantine, 902 clean)', 901 === (int) ( $q['tank'] ?? 0 ) && 902 === (int) ( $c['tank'] ?? 0 ), json_encode( array( $q, $c ) ) );
$r = act( $store, 901, 'existing', array( 'species' => 'Test Goby', 'qty' => 1 ) );
$s = stay_of( $store, $r['stay_id'] ?? 0 );
check( 'a quarantine temporary tank: as the Spa — not cleared yet by default', empty( $r['error'] ) && 'in_qt' === $s['status'] && 901 === $s['tank_number'], json_encode( $r ) );
$r = act( $store, 902, 'existing', array( 'species' => 'Test Goby', 'qty' => 1 ) );
$s = stay_of( $store, $r['stay_id'] ?? 0 );
check( 'a clean temporary tank: as the Clean Room — cleared, date unknown', empty( $r['error'] ) && 'cleared' === $s['status'] && $s['cleared_unknown'] && 902 === $s['tank_number'], json_encode( $r ) );
$r = act( $store, 902, 'existing', array( 'species' => 'Test Goby', 'qty' => 1, 'cleared' => 'no' ) );
check( 'a clean temporary tank: "not cleared yet" refused', isset( $r['error'] ) );
$r = act( $store, 903, 'existing', array( 'species' => 'Test Goby', 'qty' => 1 ) );
check( 'a free slot: refused, nothing written', isset( $r['error'] ) && false !== strpos( $r['error'], 'holds fish' ), json_encode( $r ) );

/* ------------------------------------------------------------------ */
echo "6. Everything later on a stay with unknown dates\n";

$r = act( $store, 121, 'loss', array( 'stay_id' => $first, 'qty' => 1, 'reason' => 'jumped' ) );
check( 'a loss: 3 live, the dates still unknown', empty( $r['error'] ) && 3 === stay_of( $store, $first )['qty_live'] && stay_of( $store, $first )['checkin_unknown'] );
$r = act( $store, 121, 'sold', array( 'stay_id' => $first, 'qty' => 1 ) );
check( 'shipped: 2 live', empty( $r['error'] ) && 2 === stay_of( $store, $first )['qty_live'] );
$r = act( $store, 121, 'count', array( 'stay_id' => $first, 'count' => 3 ) );
check( 'a full recount: 3', empty( $r['error'] ) && 3 === stay_of( $store, $first )['qty_live'] );
$r = act( $store, 121, 'move', array( 'stay_id' => $first, 'to_tank' => 123 ) );
$s = stay_of( $store, $first );
check( 'a Clean → Clean move: no "cleared today", still cleared on an unknown date', empty( $r['error'] ) && false === strpos( $r['message'], 'cleared today' ) && 123 === $s['tank_number'] && $s['cleared_unknown'] && null === $s['cleared_date'], json_encode( $r ) );
$r = act( $store, 123, 'move', array( 'stay_id' => $first, 'to_tank' => 122, 'qty' => 1 ) );
$sister = stay_of( $store, max( array_keys( $store->stays ) ) );
check( 'a split: the sister has 1 in 122, its arrival unknown, cleared on an unknown date, source existing', empty( $r['error'] ) && 1 === $sister['qty_live'] && 122 === $sister['tank_number'] && $sister['checkin_unknown'] && $sister['cleared_unknown'] && 'cleared' === $sister['status'] && 'existing' === $sister['source'] && 'split from #' . $first === $sister['notes'], json_encode( $sister ) );
$sister_events = $store->events( $sister['id'] );
check( '… its arrival says "arrived:unknown", its clear "cleared:unknown" — the same past, no guessed date', 0 === strpos( $sister_events[0]['note'], Replay::NOTE_ARRIVED_UNKNOWN ) && 'existing' === $sister_events[0]['source'] && Replay::NOTE_CLEARED_UNKNOWN === $sister_events[1]['note'] && 3 === count( $r['event_ids'] ) );
check( '… and the original keeps 2', 2 === stay_of( $store, $first )['qty_live'] );
$r = act( $store, 122, 'undo', array( 'event_ids' => $r['event_ids'] ) );
check( 'the split undone: the original has 3 again, the sister is closed', empty( $r['error'] ) && 3 === stay_of( $store, $first )['qty_live'] && 'closed' === stay_of( $store, $sister['id'] )['status'] );

$r = act( $store, 123, 'checkin', array( 'stay_id' => $first, 'date' => Replay::add_days( $today, -20 ) ) );
$s = stay_of( $store, $first );
check( 'the arrival date set later (Dates): day 21, the suggestion follows — it behaves as any other stay', empty( $r['error'] ) && Replay::add_days( $today, -20 ) === $s['checkin_date'] && 21 === $s['day'] && ! $s['checkin_unknown'] && Replay::add_days( $today, 8 ) === $s['sale_ready_suggested'] && Replay::add_days( $today, -20 ) === $store->stays[ $first ]['checkin_date'] );
$r = act( $store, 123, 'undo', array( 'event_ids' => $r['event_ids'] ) );
$s = stay_of( $store, $first );
check( 'its undo: unknown again (NULL in the row), no day', empty( $r['error'] ) && $s['checkin_unknown'] && null === $s['day'] && null === $store->stays[ $first ]['checkin_date'] );
$r = act( $store, 123, 'clear', array( 'stay_id' => $first, 'date' => Replay::add_days( $today, -5 ) ) );
$s = stay_of( $store, $first );
check( 'the cleared date set later: that date', empty( $r['error'] ) && Replay::add_days( $today, -5 ) === $s['cleared_date'] && ! $s['cleared_unknown'] );
$r = act( $store, 123, 'clear', array( 'stay_id' => $first, 'unset' => 1 ) );
$s = stay_of( $store, $first );
check( '"Not cleared" by hand: in QT, no cleared date of any kind', empty( $r['error'] ) && 'in_qt' === $s['status'] && null === $s['cleared_date'] && ! $s['cleared_unknown'] );
$r = act( $store, 123, 'undo', array( 'event_ids' => $r['event_ids'] ) );
$s = stay_of( $store, $first );
check( 'its undo gives the cleared date back (no longer "That can no longer be undone")', empty( $r['error'] ) && 'Undone' === $r['message'] && 'cleared' === $s['status'] && Replay::add_days( $today, -5 ) === $s['cleared_date'] && ! $s['cleared_unknown'], json_encode( $r ) );
$r = act( $store, 123, 'clear', array( 'stay_id' => $first, 'unset' => 1 ) );
check( '… and "Not cleared" again', empty( $r['error'] ) && 'in_qt' === stay_of( $store, $first )['status'] );

// "Cleared: unknown" unset by mistake: Undo gives Unknown back, never a made-up date.
$lone  = fh_existing_store();
$added = act( $lone, 121, 'existing', array( 'species' => 'Test Goby', 'qty' => 1 ) );
$lid   = (int) $added['stay_id'];
$r     = act( $lone, 121, 'clear', array( 'stay_id' => $lid, 'unset' => 1 ) );
check( 'a Clean Room fish cleared on an unknown date, "Not cleared" tapped: in QT', empty( $r['error'] ) && 'in_qt' === stay_of( $lone, $lid )['status'] );
$r = act( $lone, 121, 'undo', array( 'event_ids' => $r['event_ids'] ) );
$s = stay_of( $lone, $lid );
check( '… its undo: cleared again, the date still unknown (empty in the row)', empty( $r['error'] ) && 'Undone' === $r['message'] && 'cleared' === $s['status'] && $s['cleared_unknown'] && null === $s['cleared_date'] && null === $lone->stays[ $lid ]['cleared_date'] && 'cleared' === $lone->stays[ $lid ]['status'], json_encode( $r ) );
$last = $lone->events( $lid );
$last = end( $last );
check( '… written as a "cleared:unknown" clear, not a guessed date', 'clear' === $last['type'] && Replay::NOTE_CLEARED_UNKNOWN === $last['note'] );
unset( $lone, $added, $lid, $last );
$r = act( $store, 123, 'ready', array( 'stay_id' => $first, 'date' => $today ) );
$s = stay_of( $store, $first );
check( 'a sale date works without a check-in: for sale today', empty( $r['error'] ) && $today === $s['sale_ready_date'] && $s['sale_ready'] );

$r  = act( $store, 521, 'move', array( 'stay_id' => $spa_unknown, 'to_tank' => 221 ) );
$s  = stay_of( $store, $spa_unknown );
check( 'a Spa group of unknown arrival moved into the Clean Room: cleared today, as any group', empty( $r['error'] ) && $today === $s['cleared_date'] && false !== strpos( $r['message'], 'cleared today' ), json_encode( $r ) );

// Undo of the whole addition, like an Arrival's.
$r    = act( $store, 211, 'existing', array( 'species' => 'Test Goby', 'qty' => 2 ) );
$id   = (int) $r['stay_id'];
$undo = act( $store, 211, 'undo', array( 'event_ids' => $r['event_ids'] ) );
$s    = stay_of( $store, $id );
check( 'Undo takes the addition back: nothing alive, the group closed, not cleared', empty( $undo['error'] ) && 'Undone' === $undo['message'] && 0 === $s['qty_live'] && 0 === $s['qty_arrived'] && 'closed' === $s['status'], json_encode( $undo ) );
$again = act( $store, 211, 'undo', array( 'event_ids' => $r['event_ids'] ) );
check( '… never twice', 'Already undone' === ( $again['message'] ?? '' ), json_encode( $again ) );
$r     = act( $store, 211, 'existing', array( 'species' => 'Test Goby', 'qty' => 2 ) );
$other = act( $store, 211, 'undo', array( 'event_ids' => $r['event_ids'] ), 9 );
check( '… and only by who wrote it', isset( $other['error'] ) );

/* ------------------------------------------------------------------ */
echo "7. The To do list with unknown dates\n";

$store = fh_existing_store();
act( $store, 521, 'existing', array( 'species' => 'Test Goby', 'qty' => 2 ) );
$room  = $store->room( $today, $settings, $protocols );
$block = null;
foreach ( $room['todo']['blocks'] as $b ) {
	if ( 'system' === $b['type'] && 500 === (int) $b['number'] ) {
		$block = $b;
	}
}
$kinds = $block ? array_values( array_unique( array_column( $block['items'], 'kind' ) ) ) : array();
check( 'a Spa building whose only group has no arrival date: its block is there, with the feeding and no treatment step', $block && array() === $block['days'] && array( 'feed' ) === $kinds, json_encode( $block ) );
$tank = null;
foreach ( $room['tanks'] as $t ) {
	if ( 521 === (int) $t['number'] ) {
		$tank = $t;
	}
}
check( '… and the tank\'s own to-do is the feeding alone', $tank && array( 'feed' ) === array_values( array_unique( array_column( (array) $tank['todo'], 'kind' ) ) ) );
act( $store, 522, 'arrival', array( 'species' => 'Test Clownfish', 'product_id' => 13, 'qty' => 2, 'date' => Replay::add_days( $today, -1 ) ) );
$room  = $store->room( $today, $settings, $protocols );
foreach ( $room['todo']['blocks'] as $b ) {
	if ( 'system' === $b['type'] && 500 === (int) $b['number'] ) {
		$block = $b;
	}
}
check( 'with a dated group beside it, the building\'s schedule drives the list as always (day 2)', array( 2 ) === $block['days'] && count( array_filter( $block['items'], static function ( $i ) { return 'feed' !== $i['kind']; } ) ) > 0 );
check( 'the count for other screens works', is_array( $room['todo'] ) && $room['todo']['total'] > 0 );

/* ------------------------------------------------------------------ */
echo "8. The product facts: null for a date nobody knows\n";

$store = fh_existing_store();
act( $store, 121, 'existing', array( 'product_id' => 12, 'qty' => 3 ) );
act( $store, 521, 'existing', array( 'product_id' => 12, 'qty' => 2 ) );
act( $store, 122, 'existing', array( 'product_id' => 12, 'qty' => 1, 'arrived' => Replay::add_days( $today, -40 ), 'cleared' => Replay::add_days( $today, -10 ) ) );
$facts = $store->product_facts( 12, $today, $settings );
check( 'live 6: the fish already here count', 6 === $facts['live_total'] && 2 === count( $facts['cleared'] ) && 1 === count( $facts['in_qt'] ) );
$unknown_cleared = null;
foreach ( $facts['cleared'] as $entry ) {
	if ( 3 === $entry['qty_live'] ) {
		$unknown_cleared = $entry;
	}
}
check( 'a stay of unknown dates: checkin_date, cleared_date and day are null — never "unknown", never a date', $unknown_cleared && null === $unknown_cleared['checkin_date'] && null === $unknown_cleared['cleared_date'] && null === $unknown_cleared['day'] && null === $facts['in_qt'][0]['checkin_date'] && null === $facts['in_qt'][0]['day'], json_encode( $facts ) );
check( 'nothing anywhere in the facts says "unknown"', false === stripos( (string) json_encode( $facts ), 'unknown' ) );
check( 'next: the stay with a known check-in sorts before the unknown ones', Replay::add_days( $today, -40 ) === $facts['next']['checkin_date'] && 41 === $facts['next']['day'] );
check( 'facts_from_stays(): the same for a lone stay of unknown dates', null === Store::facts_from_stays( array( array( 'id' => 1, 'status' => 'cleared', 'qty_live' => 1, 'checkin_date' => '', 'cleared_date' => null, 'day' => null ) ) )['next']['checkin_date'] );

/* ------------------------------------------------------------------ */
echo "9. The Stats: in live / shipped / lost, out of everything that needs a date or a batch\n";

$store = fh_existing_store();
$a     = act( $store, 121, 'existing', array( 'species' => 'Test Goby', 'qty' => 5 ) );
$b     = act( $store, 521, 'existing', array( 'species' => 'Test Goby', 'qty' => 3, 'arrived' => Replay::add_days( $today, -8 ) ) );
act( $store, 121, 'loss', array( 'stay_id' => $a['stay_id'], 'qty' => 1 ) );
act( $store, 121, 'sold', array( 'stay_id' => $a['stay_id'], 'qty' => 2 ) );
act( $store, 121, 'move', array( 'stay_id' => $a['stay_id'], 'to_tank' => 122, 'qty' => 1 ) ); // A split: counted once.
$c = act( $store, 211, 'existing', array( 'species' => 'Test Goby', 'qty' => 4 ) );
act( $store, 211, 'undo', array( 'event_ids' => $c['event_ids'] ) ); // Added and taken back.
act( $store, 511, 'arrival', array( 'species' => 'Test Clownfish', 'qty' => 6 ) ); // An ordinary arrival: not theirs.
$x = Stats::existing_stock( $store->existing_stays( $today, $settings ) );
check( 'two groups, 8 fish added (the split and the undone one not counted twice or at all)', 2 === $x['groups'] && 8 === $x['added'], json_encode( $x ) );
check( 'alive 5 (2 + 3, the split half included), shipped 2, lost 1', 5 === $x['live'] && 2 === $x['sold'] && 1 === $x['lost'], json_encode( $x ) );
check( 'of the groups in tanks: two have no arrival date (the original and its split half), one cleared on an unknown date … twice', 2 === $x['no_arrival'] && 2 === $x['cleared_unknown'] && 3 === $x['open'], json_encode( $x ) );
check( 'left out of the batch figures, and it says so: 2 groups, 8 fish', 2 === $x['left_out_groups'] && 8 === $x['left_out_fish'] );
foreach ( $store->stays as $sid => $row ) {
	if ( Store::SOURCE_EXISTING === (string) $row['source'] ) {
		// Edit can change the notes: the split half's say something else, a whole group's look like a split.
		$store->stays[ $sid ]['notes'] = 0 === strpos( (string) $row['notes'], 'split from #' ) ? 'moved to the sump side' : 'split from #1 (typed by hand)';
	}
}
$y = Stats::existing_stock( $store->existing_stays( $today, $settings ) );
check( 'the notes edited: still two groups, 8 fish added — the split is read from its first arrival', 2 === $y['groups'] && 8 === $y['added'] && $x['live'] === $y['live'], json_encode( $y ) );
$sp = array( 'id' => 5, 'type' => 'arrival', 'qty' => 1, 'event_at' => '2026-01-01 12:00:00' );
check( 'split_from(): "split from #7", "arrived:unknown · split from #7"; an ordinary arrival 0; only the first arrival counts', 7 === Replay::split_from( array( array_merge( $sp, array( 'note' => 'split from #7' ) ) ) ) && 7 === Replay::split_from( array( array_merge( $sp, array( 'note' => Replay::NOTE_ARRIVED_UNKNOWN . ' · split from #7' ) ) ) ) && 0 === Replay::split_from( array( array_merge( $sp, array( 'note' => Replay::NOTE_ARRIVED_UNKNOWN . ' · already here' ) ) ) ) && 0 === Replay::split_from( array( array_merge( $sp, array( 'note' => 'already here' ) ), array_merge( $sp, array( 'id' => 6, 'event_at' => '2026-01-02 12:00:00', 'note' => 'split from #7' ) ) ) ) && 0 === Replay::split_from( array() ) );
check( 'nothing at all: zeros', 0 === Stats::existing_stock( array() )['groups'] && 0 === Stats::existing_stock( array() )['live'] );
$stats = Stats::build( array() );
check( 'the yearly figures are built from the supplier orders alone — no order, nothing to count there', 0 === (int) ( $stats['totals']['received'] ?? 0 ) );

/* ------------------------------------------------------------------ */
echo "10. The phone's screen, the Board's words, the exports\n";

$store = fh_existing_store();
$store->save_order( array( 'ref' => '2026-1', 'supplier' => 'Test Supplier', 'origin' => 'Testland' ) );
act( $store, 121, 'existing', array( 'species' => 'Test Goby', 'qty' => 2 ) );
act( $store, 521, 'existing', array( 'species' => 'Test Wrasse', 'qty' => 1 ) );
$screen = Front_Desk::screen_from( $store, 121, $settings, $today );
$o      = $screen['tank']['occupants'][0];
check( 'the occupant: arrival unknown, cleared on an unknown date, no day, added as already here', true === $o['checkinUnknown'] && true === $o['clearedUnknown'] && '' === $o['checkinDate'] && null === $o['clearedDate'] && null === $o['day'] && true === $o['existing'] && '' === $o['saleSuggested'], json_encode( $o ) );
check( 'the sheet\'s choices: the suppliers and origins the supplier orders name', array( 'Test Supplier' ) === $screen['picks']['suppliers'] && array( 'Testland' ) === $screen['picks']['origins'], json_encode( $screen['picks'] ) );
check( 'the strings the sheet needs are there', isset( $screen ) && '' !== Front_Desk::i18n()['existingAdd'] && 'day ?' === Front_Desk::i18n()['dayUnknown'] && 'treatment day unknown — set the arrival date' === Front_Desk::i18n()['treatUnknown'] );
$spa = Front_Desk::screen_from( $store, 521, $settings, $today )['tank']['occupants'][0];
check( 'a Spa group: in QT, arrival unknown', 'in_qt' === $spa['status'] && $spa['checkinUnknown'] && ! $spa['clearedUnknown'] );

$stay_spa   = stay_of( $store, 2 );
$stay_clean = stay_of( $store, 1 );
check( 'the Board: "day ?" (and "QT day ?" on a temporary tank\'s card)', 'day ?' === Admin::day_text( $stay_spa ) && 'QT day ?' === Admin::day_text( $stay_spa, true ) && 'day 3' === Admin::day_text( array( 'day' => 3 ) ) && '' === Admin::day_text( array( 'day' => null ) ) );
check( 'the Stays tab: "treatment day unknown — set the arrival date" on the Spa group only', 'treatment day unknown — set the arrival date' === Admin::treatment_unknown( $stay_spa ) && '' === Admin::treatment_unknown( $stay_clean ) && '' === Admin::treatment_unknown( array( 'status' => 'in_qt', 'checkin_unknown' => false ) ) );
check( 'the check-in in words, and the source', 'arrival unknown' === Admin::checkin_text( $stay_spa ) && '2025-01-02' === Admin::checkin_text( array( 'checkin_date' => '2025-01-02' ) ) && 'already here' === Admin::source_label( 'existing' ) && 'order' === Admin::source_label( 'order' ) );
$events = $store->events( 1 );
check( 'the Events tab: "2 added as already here in Tank 121 (arrival date unknown)" and "cleared (date unknown)"', '2 added as already here in Tank 121 (arrival date unknown)' === Admin::event_text( $events[0] ) && 'cleared (date unknown)' === Admin::event_text( $events[1] ), Admin::event_text( $events[0] ) . ' / ' . Admin::event_text( $events[1] ) );
$half = array( 'type' => 'arrival', 'qty' => 1, 'to_tank_number' => 111, 'source' => 'existing', 'note' => Replay::NOTE_ARRIVED_UNKNOWN . ' · split from #7' );
check( 'the Events tab: a split half of fish already here "1 arrived in Tank 111 (arrival date unknown)", never "added as already here"', '1 arrived in Tank 111 (arrival date unknown)' === Admin::event_text( $half ), Admin::event_text( $half ) );
$row = Admin::csv_row( $stay_clean );
$hdr = array_flip( Admin::csv_header() );
check( 'the CSV: the dates and the day empty, the state "cleared", the source "existing"', '' === $row[ $hdr['checkin_date'] ] && '' === $row[ $hdr['cleared_date'] ] && '' === $row[ $hdr['day'] ] && 'cleared' === $row[ $hdr['state'] ] && 'existing' === $row[ $hdr['source'] ] );

/* ------------------------------------------------------------------ */
echo "11. The Stays tab: its form and the two AJAX endpoints\n";

$store = fh_existing_store();
$tanks = Admin::existing_tanks( $store->tanks() );
$nums  = array_column( $tanks, 'number' );
check( 'the form offers every tank that holds fish: Spa and Clean Room, no free slot', in_array( 121, $nums, true ) && in_array( 521, $nums, true ) && ! in_array( 901, $nums, true ) );
act( $store, 0, 'setup', array( 'name' => 'Holding', 'kind' => 'clean', 'gallons' => 20 ) );
$tanks = array_column( Admin::existing_tanks( $store->tanks() ), null, 'number' );
check( '… and a temporary tank once it is set up, with the kind it was set up as', isset( $tanks[901] ) && 'clean' === $tanks[901]['area'] && 'TEMP 1 · 901 · Clean' === $tanks[901]['label'] && 'spa' === $tanks[521]['area'] && '521 · Spa' === $tanks[521]['label'], json_encode( $tanks[901] ?? null ) );

$r = Admin::add_existing( $store, array( 'tank' => '221', 'species' => 'Test Goby', 'qty' => '2', 'arrived' => 'unknown', 'cleared' => 'unknown', 'supplier' => 'unknown', 'origin' => 'unknown' ), 7, $today, $settings );
$s = stay_of( $store, $r['stay_id'] ?? 0 );
check( 'the desk form: the same act, into the tank it names — cleared, dates unknown, source existing', empty( $r['error'] ) && 221 === $s['tank_number'] && $s['cleared_unknown'] && $s['checkin_unknown'] && 'existing' === $s['source'] && null === $s['supplier'] && 7 === $store->events( $s['id'] )[0]['actor_id'], json_encode( $r ) );
$r = Admin::add_existing( $store, array( 'tank' => '999', 'species' => 'Test Goby' ), 7, $today, $settings );
check( 'a tank number that is not in the layout: refused', isset( $r['error'] ) && false !== strpos( $r['error'], 'not in the layout' ) );
$r = Admin::add_existing( $store, array( 'tank' => '902', 'species' => 'Test Goby' ), 7, $today, $settings );
check( 'a free slot: refused', isset( $r['error'] ) );

$call = static function ( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	try {
		$handler();
	} catch ( Fh_Test_Json $reply ) {
		return $reply;
	}
	return null;
};
$GLOBALS['wpdb'] = new class() { // A database without the Clean Room tables.
	public $prefix = 'wp_';
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}
	public function get_var( $query ) {
		return null;
	}
};
$GLOBALS['fh_user_caps'][ Clean_Room::CAPABILITY ] = array( 7 );
$endpoints = array(
	'the Stays tab (fishotel_cleanroom_existing)' => array( array( new Admin(), 'ajax_existing' ), Admin::NONCE_AJAX, array( 'tank' => '121', 'species' => 'x' ) ),
	'the phone (fishotel_cleanroom_act)'          => array( array( new Front_Desk(), 'ajax_act' ), Front_Desk::NONCE, array( 'act' => 'existing', 'tank' => '121', 'species' => 'x' ) ),
);
foreach ( $endpoints as $label => $endpoint ) {
	list( $handler, $action, $post ) = $endpoint;
	$GLOBALS['fh_current_user']      = 9; // No capability.
	$reply                           = $call( $handler, array_merge( $post, array( 'nonce' => wp_create_nonce( $action ) ) ) );
	check( $label . ': without the capability → 403', $reply instanceof Fh_Test_Json && false === $reply->success && 403 === $reply->status );
	$GLOBALS['fh_current_user'] = 7;
	$reply                      = $call( $handler, array_merge( $post, array( 'nonce' => 'wrong' ) ) );
	check( $label . ': with a wrong nonce → 403', $reply instanceof Fh_Test_Json && 403 === $reply->status && 'bad nonce' === $reply->getMessage() );
	$reply = $call( $handler, $post );
	check( $label . ': without a nonce → 403', $reply instanceof Fh_Test_Json && 403 === $reply->status );
	$reply = $call( $handler, array_merge( $post, array( 'nonce' => wp_create_nonce( $action ) ) ) );
	check( $label . ': with both, past the guards — and stopped at the tables this test does not have (500)', $reply instanceof Fh_Test_Json && false === $reply->success && 500 === $reply->status && false !== strpos( $reply->getMessage(), 'tables are missing' ), $reply ? $reply->getMessage() : 'none' );
}
// The tables there, the 1.7 upgrade not written yet: both wait for it (an empty check-in would not fit).
$flag = new ReflectionProperty( Store::class, 'tables_exist' );
$flag->setAccessible( true );
$flag->setValue( null, true );
update_option( Store::DB_VERSION_OPTION, '1.6' );
foreach ( $endpoints as $label => $endpoint ) {
	list( $handler, $action, $post ) = $endpoint;
	$reply                           = $call( $handler, array_merge( $post, array( 'nonce' => wp_create_nonce( $action ) ) ) );
	check( $label . ': before the 1.7 upgrade → 503, "reload and try again"', $reply instanceof Fh_Test_Json && 503 === $reply->status && false !== strpos( $reply->getMessage(), 'being upgraded' ), $reply ? $reply->getMessage() : 'none' );
}
$flag->setValue( null, null );
update_option( Store::DB_VERSION_OPTION, '1.7' );
$_POST    = array();
$_REQUEST = array();

fh_finish();
