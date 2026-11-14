<?php
/**
 * Temporary tanks and the day's list — the guards around them: a group
 * cannot come back to life in a slot whose setup was taken down (or was
 * replaced by the next one), an undo cannot reach into a setup that is
 * history, a building that is not "active" but has fish keeps its block
 * on the list, the Temporary building cannot go offline under a running
 * tank, a step with "<" in its name can be ticked, a page that was
 * loaded before the slot changed hands writes nothing, a dose step that
 * names several medications is not done by one of them, and the count
 * for other screens stays null while the section is off.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/temp-guards-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today     = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events and logs with the real clock.
$settings  = Settings::get();
$protocols = Settings::protocols();
$base      = array(
	'actor_id' => 8,
	'source'   => 'manual',
);

$GLOBALS['fh_user_caps'][ Clean_Room::CAPABILITY ] = array( 8 );
$GLOBALS['fh_current_user']                        = 8;

/**
 * One write from the phone on a tank's page — as the page posts it: a
 * setup's edit, schedule change and take down carry the setup the page
 * was showing (its id and its token), unless the test names them.
 */
function act( Fake_Store $store, $tank_number, $act, array $raw ) {
	global $settings, $today;
	$tank = $tank_number > 0 ? $store->tank_by_number( $tank_number ) : null;
	if ( $tank && ! empty( $tank['setup'] ) && in_array( $act, array( 'setup_edit', 'setup_steps', 'takedown' ), true ) ) {
		$raw += array(
			'setup_id' => (int) $tank['setup']['id'],
			'seen'     => Temp_Tanks::seen( $tank['setup'] ),
		);
	}
	return Front_Desk::perform(
		$act,
		array(
			'store'    => $store,
			'settings' => $settings,
			'today'    => $today,
			'tank'     => $tank,
			'base'     => array(
				'actor_id' => 8,
				'source'   => 'qr',
			),
		),
		$raw
	);
}

function set_up( Fake_Store $store, array $raw, $tank_number = 0 ) {
	$r = act( $store, $tank_number, 'setup', $raw );
	if ( ! empty( $r['error'] ) ) {
		return $r;
	}
	$open = $store->open_setups();
	$tank = $store->tank_by_number( (int) $r['tank'] );
	return $open[ (int) $tank['id'] ];
}

function stay_of( Fake_Store $store, $id ) {
	global $settings, $today;
	return $store->stay( $id, $today, $settings );
}

function last_of( Fake_Store $store, $stay_id, $type ) {
	$found = null;
	foreach ( $store->events( $stay_id ) as $event ) {
		if ( $type === $event['type'] ) {
			$found = $event;
		}
	}
	return $found;
}

function tank_todo( Fake_Store $store, $number ) {
	global $settings, $today, $protocols;
	foreach ( $store->room( $today, $settings, $protocols )['tanks'] as $tank ) {
		if ( (int) $number === (int) $tank['number'] ) {
			return array_column( $tank['todo'], 'done', 'label' );
		}
	}
	return array();
}

function block_of( Fake_Store $store, $number ) {
	global $settings, $today, $protocols;
	foreach ( $store->room( $today, $settings, $protocols )['todo']['blocks'] as $block ) {
		if ( (int) $number === (int) $block['number'] ) {
			return $block;
		}
	}
	return null;
}

/* ------------------------------------------------------------------ */
echo "1. No group comes back to life in a slot whose setup is gone\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10 ) ); // 901
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
act( $store, 901, 'loss', array( 'stay_id' => 1, 'qty' => 1, 'reason' => 'disease' ) );
$first = last_of( $store, 1, 'loss' );
$r     = $store->undo_event( stay_of( $store, 1 ), $first, $base, $today, $settings );
check( 'while the setup stands, a loss in it is undone as anywhere', is_array( $r ) && 2 === stay_of( $store, 1 )['qty_live'] );
act( $store, 901, 'loss', array( 'stay_id' => 1, 'qty' => 2, 'reason' => 'disease' ) );
$loss = last_of( $store, 1, 'loss' );
check( 'the group is closed, still filed under the slot', 'closed' === stay_of( $store, 1 )['status'] && 901 === stay_of( $store, 1 )['tank_number'] );
$r = act( $store, 901, 'takedown', array() );
check( 'an empty setup comes down', empty( $r['error'] ), json_encode( $r ) );

$events = $store->event_count();
$r      = $store->undo_event( stay_of( $store, 1 ), $loss, $base, $today, $settings );
check( 'undoing the loss after the take down is refused: nothing is set up there', false === $r && 'closed' === stay_of( $store, 1 )['status'] && 0 === stay_of( $store, 1 )['qty_live'] && $events === $store->event_count() );
$r = $store->record_event( 1, array_merge( $base, array( 'type' => 'count', 'qty' => 1, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
check( 'so is a count of 1 on the closed group', false === $r && 0 === stay_of( $store, 1 )['qty_live'] && $events === $store->event_count() );
check( 'and the screen says why', false !== strpos( Temp_Tanks::gone_error( $store, stay_of( $store, 1 ) ), 'taken down' ), Temp_Tanks::gone_error( $store, stay_of( $store, 1 ) ) );
$r = $store->record_event( 1, array_merge( $base, array( 'type' => 'note', 'note' => 'photo sent', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings );
check( 'a note on the closed group is still taken', is_array( $r ) && 0 === stay_of( $store, 1 )['qty_live'] );

$b = set_up( $store, array( 'name' => 'Holding B', 'kind' => 'clean', 'gallons' => 20 ), 901 );
check( 'the slot is set up again, as a clean tank', isset( $b['id'] ) && $b['id'] !== $a['id'] );
$events = $store->event_count();
$r      = $store->undo_event( stay_of( $store, 1 ), $loss, $base, $today, $settings );
check( 'the old group does not come back to life in the NEXT setup either', false === $r && 0 === stay_of( $store, 1 )['qty_live'] && $events === $store->event_count() );
check( 'a count on it is refused there too', false === $store->record_event( 1, array_merge( $base, array( 'type' => 'count', 'qty' => 1, 'event_at' => gmdate( 'Y-m-d H:i:s' ) ) ), $today, $settings ) );
check( 'the next setup has no group in its history', array() === Temp_Tanks::history( $store, $store->setup( $b['id'] ) )['groups'] );

check( 'slot_revival (pure): a rack tank is never in question', false === Store::slot_revival( array( 'tank_id' => 4, 'qty_live' => 0, 'events' => array() ), array( 'type' => 'count', 'qty' => 3 ), array( 30 => 0 ), $today, array() ) );
check( 'slot_revival: a count going down is taken even where nothing stands', false === Store::slot_revival( array( 'tank_id' => 30, 'qty_live' => 3, 'events' => array( array( 'id' => 1, 'type' => 'arrival', 'qty' => 3, 'to_tank_id' => 30, 'to_setup_id' => 7, 'event_at' => '2026-01-01 12:00:00' ) ) ), array( 'type' => 'loss', 'qty' => 1, 'event_at' => '2026-01-02 12:00:00' ), array( 30 => 0 ), $today, array() ) );
check( 'slot_revival: a count going up where the group\'s setup is gone is not', true === Store::slot_revival( array( 'tank_id' => 30, 'qty_live' => 0, 'events' => array( array( 'id' => 1, 'type' => 'arrival', 'qty' => 3, 'to_tank_id' => 30, 'to_setup_id' => 7, 'event_at' => '2026-01-01 12:00:00' ), array( 'id' => 2, 'type' => 'loss', 'qty' => 3, 'event_at' => '2026-01-02 12:00:00' ) ) ), array( 'type' => 'count', 'qty' => 1, 'event_at' => '2026-01-03 12:00:00' ), array( 30 => 9 ), $today, array() ) );

/* ------------------------------------------------------------------ */
echo "2. An undo does not reach into a setup that was taken down\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10 ) );
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
act( $store, 901, 'move', array( 'to_tank' => 522, 'stay_id' => 1 ) );
$move = last_of( $store, 1, 'move' );
$r    = $store->undo_event( stay_of( $store, 1 ), $move, $base, $today, $settings );
check( 'while the setup stands, a move out of it is undone: the group is back', is_array( $r ) && 901 === stay_of( $store, 1 )['tank_number'] );
act( $store, 901, 'move', array( 'to_tank' => 522, 'stay_id' => 1 ) );
$move = last_of( $store, 1, 'move' );
act( $store, 901, 'takedown', array() );
check( 'with the slot free the undo is refused', false === $store->undo_event( stay_of( $store, 1 ), $move, $base, $today, $settings ) && 522 === stay_of( $store, 1 )['tank_number'] );
$b      = set_up( $store, array( 'name' => 'Holding B', 'kind' => 'clean', 'gallons' => 20 ), 901 );
$events = $store->event_count();
$r      = $store->undo_event( stay_of( $store, 1 ), $move, $base, $today, $settings );
$stay   = stay_of( $store, 1 );
check( 'with the slot set up again it is refused too: the group stays in 522, uncleared', false === $r && 522 === $stay['tank_number'] && null === $stay['cleared_date'] && 'in_qt' === $stay['status'] && $events === $store->event_count(), json_encode( array( $r, $stay['tank_number'], $stay['cleared_date'] ) ) );
check( 'and the next setup can still be taken down', empty( act( $store, 901, 'takedown', array() )['error'] ) );
check( 'setup_gone (pure): the event\'s setup must still be the one in that slot', false === Store::setup_gone( array( 'from_tank_id' => 30, 'from_setup_id' => 7, 'to_tank_id' => 4, 'to_setup_id' => null ), array( 30 => 7 ) ) && true === Store::setup_gone( array( 'from_tank_id' => 30, 'from_setup_id' => 7, 'to_tank_id' => 4 ), array( 30 => 0 ) ) && true === Store::setup_gone( array( 'from_tank_id' => 4, 'to_tank_id' => 30, 'to_setup_id' => 7 ), array( 30 => 9 ) ) && false === Store::setup_gone( array( 'from_tank_id' => 4, 'to_tank_id' => 5 ), array( 30 => 9 ) ) );
check( 'the Stays tab says why', false !== strpos( Temp_Tanks::gone_error( $store, stay_of( $store, 1 ), $move ), 'taken down' ) && '' === Temp_Tanks::gone_error( $store, stay_of( $store, 1 ) ) );

/* ------------------------------------------------------------------ */
echo "3. A building that is not active but has fish keeps its block\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
act( $store, 611, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
$full = block_of( $store, 600 );
foreach ( $store->buildings as $id => $building ) {
	if ( 600 === (int) $building['number'] ) {
		$store->buildings[ $id ]['status'] = 'coming_soon';
		$six                               = $id;
	}
	if ( 500 === (int) $building['number'] ) {
		$store->buildings[ $id ]['status'] = 'offline';
	}
}
$block = block_of( $store, 600 );
$room  = $store->room( $today, $settings, $protocols );
check( 'Building 600 "coming soon" with fish in 611: its block is still on the list, as before', null !== $block && $full['items'] === $block['items'] && count( $full['items'] ) > 0 && $room['todo']['total'] === count( $block['items'] ), json_encode( $block ) );
check( 'tank 611 and the list agree on what is due', count( tank_todo( $store, 611 ) ) === count( $block['items'] ) );
check( 'Building 500 offline and empty has no block', null === block_of( $store, 500 ) );
$store->buildings[ $six ]['status'] = 'offline';
check( 'offline with fish: still listed', null !== block_of( $store, 600 ) );

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'CQ' ) );
$temp  = $store->temp_building();
check( 'the Temporary building cannot go offline or "coming soon" under a running tank', false !== strpos( Temp_Tanks::building_status_error( $temp, 'offline', $store->open_setups() ), 'TEMP 1' ) && '' !== Temp_Tanks::building_status_error( $temp, 'coming_soon', $store->open_setups() ) && '' === Temp_Tanks::building_status_error( $temp, 'active', $store->open_setups() ), Temp_Tanks::building_status_error( $temp, 'offline', $store->open_setups() ) );
check( 'with nothing set up it can; and a rack building is not this rule\'s business', '' === Temp_Tanks::building_status_error( $temp, 'offline', array() ) && '' === Temp_Tanks::building_status_error( $store->building( (int) $store->tank_by_number( 521 )['building_id'] ), 'offline', $store->open_setups() ) );
$store->buildings[ (int) $temp['id'] ]['status'] = 'offline'; // Data from before the guard.
$block = block_of( $store, 901 );
check( 'a tank that is running stays on the list whatever its building says', null !== $block && 'temp' === $block['type'] && count( $block['items'] ) > 0 );

/* ------------------------------------------------------------------ */
echo "4. A step with \"<\" in its name can be ticked\n";

check( 'the stub cleans text the way WordPress does: a lone "<" comes back as an entity', 'Keep salinity &lt; 1.015' === sanitize_text_field( 'Keep salinity < 1.015' ) && 'Keep it low' === sanitize_text_field( 'Keep <b>it</b> low' ) );
$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10 ) );
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 1 ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'add', 'label' => 'Keep <b>salinity</b> < 1.015', 'kind' => 'other', 'day' => 1 ) );
check( 'the step is stored as plain text, the "<" as typed', empty( $r['error'] ) && 'Keep salinity < 1.015' === end( $store->setup( $a['id'] )['steps'] )['label'], json_encode( $store->setup( $a['id'] )['steps'] ) );
check( 'step_text: plain text, the "<" as typed, tags gone', 'Keep salinity < 1.015' === Temp_Tanks::step_text( 'Keep salinity < 1.015' ) && 'a b' === Temp_Tanks::step_text( 'a <i>b</i>' ) && '' === Temp_Tanks::step_text( array( 'x' ) ) && 80 === strlen( Temp_Tanks::step_text( str_repeat( 'x', 200 ) ) ) );
$settled = true;
foreach ( array( 'Keep salinity < 1.015', 'pH < 8.4 <script>x</script>', '&lt;b&gt; twice', 'a << b', '1 < 2 > 0', '<', 'Dose & watch', 'x &amp;lt; y' ) as $typed ) {
	$stored  = Temp_Tanks::step_text( $typed );
	$key     = Protocol::tick_key( $stored . ' #0' );
	$settled = $settled && $stored === Temp_Tanks::step_text( $stored ) && $key === Todo::posted_key( $key );
}
check( 'whatever is typed: cleaning the stored name again changes nothing, and its key comes back from the page as itself', $settled );
check( 'posted_key: what the page posts is the key again, however WordPress cleaned it', 'keep salinity < 1.015 #0' === Todo::posted_key( 'keep salinity < 1.015 #0' ) && 'keep salinity < 1.015 #0' === Todo::posted_key( 'Keep  salinity &lt; 1.015 #0' ) && '' === Todo::posted_key( array() ) );
$room = $store->room( $today, $settings, $protocols );
$item = null;
foreach ( $room['tanks'] as $tank ) {
	foreach ( 901 === (int) $tank['number'] ? $tank['todo'] : array() as $line ) {
		if ( 'Keep salinity < 1.015' === $line['label'] ) {
			$item = $line;
		}
	}
}
check( 'it is due today and open', null !== $item && false === $item['done'] );
$r = act( $store, 0, 'todo_tick', array( 'scope' => 'tank', 'tick_tank' => 901, 'key' => $item['key'], 'kind' => 'other', 'label' => $item['label'] ) );
check( 'ticked on the list: done — and the reply names it as typed', empty( $r['error'] ) && true === tank_todo( $store, 901 )['Keep salinity < 1.015'] && 0 === strpos( $r['message'], 'Done: Keep salinity < 1.015' ), json_encode( array( $r, end( $store->logs )['note'] ) ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'save', 'steps' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'day' => 1, 'every' => 1 ), array( 'label' => 'pH < 8.4 <script>x</script>', 'kind' => 'test', 'day' => 1 ) ) ) );
check( 'the Board\'s editor stores its labels the same way', empty( $r['error'] ) && 'pH < 8.4 x' === $store->setup( $a['id'] )['steps'][1]['label'], json_encode( $store->setup( $a['id'] )['steps'] ) );
$key = Protocol::tick_key( 'pH < 8.4 x #0' );
$r   = act( $store, 901, 'tick', array( 'key' => $key, 'kind' => 'test', 'label' => 'pH < 8.4 x' ) );
check( 'ticked on the tank\'s own screen: done', empty( $r['error'] ) && true === tank_todo( $store, 901 )['pH < 8.4 x'], json_encode( array( $r, end( $store->logs )['note'] ) ) );

/* ------------------------------------------------------------------ */
echo "5. A page loaded before the slot changed hands writes nothing\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'Trifecta' ) );
$stale = array(
	'setup_id' => (int) $a['id'],
	'seen'     => Temp_Tanks::seen( $a ),
);
check( 'seen: a short token of what the setup is; any change changes it', 1 === preg_match( '/^[0-9a-f]{12}$/', $stale['seen'] ) && $stale['seen'] === Temp_Tanks::seen( $store->setup( $a['id'] ) ) && $stale['seen'] !== Temp_Tanks::seen( array_merge( $a, array( 'gallons' => 11 ) ) ) && $stale['seen'] !== Temp_Tanks::seen( array_merge( $a, array( 'steps' => array_slice( $a['steps'], 0, 2 ) ) ) ) );
act( $store, 901, 'takedown', array() );
$b      = set_up( $store, array( 'name' => 'Holding B', 'kind' => 'clean', 'gallons' => 20, 'template' => 'CQ' ), 901 );
$b_was  = $store->setup( $b['id'] );
$a_save = array(
	'op'    => 'save',
	'steps' => $a['steps'],
);

$r = act( $store, 901, 'setup_steps', $a_save + array( 'setup_id' => '', 'seen' => '' ) );
check( 'a page that names no setup writes nothing: it is told to reload', isset( $r['error'] ) && false !== strpos( $r['error'], 'eload' ) && $b_was === $store->setup( $b['id'] ), json_encode( $r ) );
$r = act( $store, 901, 'setup_steps', $a_save + $stale );
check( 'the old page\'s Schedule panel does not land on the new setup', isset( $r['error'] ) && $b_was === $store->setup( $b['id'] ), json_encode( $r ) );
$r = act( $store, 901, 'setup_edit', array( 'name' => 'Hospital A', 'gallons' => '10' ) + $stale );
check( 'nor its Edit', isset( $r['error'] ) && 'Holding B' === $store->setup( $b['id'] )['name'] && 20 === $store->setup( $b['id'] )['gallons'] );
$r = act( $store, 901, 'takedown', $stale );
check( 'nor its Take down', isset( $r['error'] ) && $store->setup( $b['id'] )['open'] );
$r = Admin_Temp::request( $store, array( 'nonce' => wp_create_nonce( Admin::NONCE_AJAX ), 'act' => 'takedown', 'tank' => 901 ), $today, $settings );
check( 'the Board\'s write is held to the same: no setup named, nothing taken down', 400 === $r['status'] && $store->setup( $b['id'] )['open'], json_encode( $r ) );

// The same setup on two screens: the phone changes the schedule, the Board still shows the old one.
$store  = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a      = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'Trifecta' ) );
$board  = array(
	'setup_id' => (int) $a['id'],
	'seen'     => Temp_Tanks::seen( $a ),
);
$labels = array_column( $a['steps'], 'label' );
$r      = act( $store, 901, 'setup_steps', array( 'op' => 'remove', 'index' => 2 ) );
check( 'the phone removes Furan-2', empty( $r['error'] ) && ! in_array( 'Furan-2', array_column( $store->setup( $a['id'] )['steps'], 'label' ), true ) && 'Furan-2' === $labels[2] );
$now = $store->setup( $a['id'] );
$r   = act( $store, 901, 'setup_steps', array( 'op' => 'save', 'steps' => $a['steps'] ) + $board );
check( 'the Board\'s stale save does not bring it back: "changed on another screen"', isset( $r['error'] ) && false !== strpos( $r['error'], 'another screen' ) && $now === $store->setup( $a['id'] ), json_encode( $r ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'remove', 'index' => 1 ) + $board );
check( 'a stale Remove does not take the step that now stands at that place', isset( $r['error'] ) && $now === $store->setup( $a['id'] ) );
$r = act( $store, 901, 'setup_edit', array( 'gallons' => '12' ) + $board );
check( 'a stale Edit is refused too', isset( $r['error'] ) && 10 === $store->setup( $a['id'] )['gallons'] );
$r = act( $store, 901, 'setup_edit', array( 'gallons' => '12' ) );
check( 'a page that is up to date saves', empty( $r['error'] ) && 12 === $store->setup( $a['id'] )['gallons'], json_encode( $r ) );
$r = act( $store, 901, 'takedown', $board );
check( 'Take down needs the setup, not the token: the same setup, empty, comes down', empty( $r['error'] ) && ! $store->setup( $a['id'] )['open'], json_encode( $r ) );
$screen = Front_Desk::slot_screen( array_merge( $store->tank_by_number( 902 ), array( 'occupants' => array() ) ), $today );
$c      = set_up( $store, array( 'name' => 'Hospital C', 'kind' => 'quarantine' ), 902 );
$screen = Front_Desk::slot_screen( array_merge( $store->tank_by_number( 902 ), array( 'occupants' => array() ) ), $today );
check( 'the phone is handed the setup\'s id and token', (int) $c['id'] === $screen['setup']['id'] && Temp_Tanks::seen( $c ) === $screen['setup']['seen'] );

/* ------------------------------------------------------------------ */
echo "6. A dose step that names several medications is not done by one of them\n";

check( 'combo: one part per "+"; a label without one is no combo', array( 'Metronidazole', 'Kanamycin', 'Furan-2' ) === Todo::combo( 'Metronidazole + Kanamycin + Furan-2' ) && array() === Todo::combo( 'Furan-2' ) && array() === Todo::combo( 'Copper Power to 2.4' ) && array() === Todo::combo( '+ Furan-2' ) );
$rows = array( array( 'kind' => 'dose', 'note' => 'Metronidazole 0.25 g · Tank 901' ) );
check( 'part_dosed: by the medication list, or by its name as typed', Todo::part_dosed( 'Metronidazole', $rows ) && Todo::part_dosed( 'metro', $rows ) && ! Todo::part_dosed( 'Kanamycin', $rows ) && Todo::part_dosed( 'Kanamycin', array( array( 'kind' => 'dose', 'note' => 'kanamycin 0.5 g' ) ) ) && ! Todo::part_dosed( 'Furan', array( array( 'kind' => 'dose', 'note' => 'Furanace 1 g' ) ) ) && ! Todo::part_dosed( 'Kanamycin', array( array( 'kind' => 'other', 'note' => 'kanamycin' ) ) ) );

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'Trifecta' ) );
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 1 ) );
$combo = 'Metronidazole + Kanamycin + Furan-2';
check( 'Trifecta day 1: the combo is due and open', false === tank_todo( $store, 901 )[ $combo ] );
act( $store, 901, 'dose', array( 'medication' => 'metro', 'amount' => '0.25', 'unit' => 'g' ) );
$block = block_of( $store, 901 );
$line  = array_column( $block['items'], null, 'label' )[ $combo ];
check( 'Metronidazole alone does not tick it — on the tank or on the list', false === tank_todo( $store, 901 )[ $combo ] && false === $line['done'] && 2 === $block['left'], json_encode( $line ) );
check( 'the line names no single medication; the suggestion says whose it is', '' === $line['medication'] && 'Metronidazole: suggested 0.25 g for 10 gal' === $line['dose'], json_encode( $line ) );
$tank = (int) $store->tank_by_number( 901 )['id'];
$store->log_dose( 0, $tank, 'Kanamycin', 0.5, 'g' );
check( 'two of three: still open', false === tank_todo( $store, 901 )[ $combo ] );
$store->log_dose( 0, $tank, 'Furan-2', 1, 'packet' );
check( 'each of the three dosed today: done', true === tank_todo( $store, 901 )[ $combo ] && true === array_column( block_of( $store, 901 )['items'], null, 'label' )[ $combo ]['done'] );

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$a     = set_up( $store, array( 'name' => 'Hospital A', 'kind' => 'quarantine', 'gallons' => 10, 'template' => 'Trifecta' ) );
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 1 ) );
act( $store, 901, 'dose', array( 'medication' => 'metro', 'amount' => '0.25', 'unit' => 'g' ) );
$key = Protocol::tick_key( $combo . ' #0' );
act( $store, 901, 'tick', array( 'key' => $key, 'kind' => 'dose', 'label' => $combo ) );
check( 'a tick by hand always does', true === tank_todo( $store, 901 )[ $combo ] );

// A whole building on Trifecta: the list, and every tank's own screen, say the same.
$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
foreach ( $store->buildings as $id => $building ) {
	if ( 500 === (int) $building['number'] ) {
		$store->buildings[ $id ]['schedule'] = 'Trifecta';
		$five                                = $id;
	}
}
act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
act( $store, 513, 'arrival', array( 'product_id' => 12, 'qty' => 1 ) );
act( $store, 521, 'dose', array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => '1' ) );
$line = array_column( block_of( $store, 500 )['items'], null, 'label' )[ $combo ];
check( 'a system dose of Metronidazole leaves the system\'s combo open — list and tanks alike', false === $line['done'] && false === tank_todo( $store, 521 )[ $combo ] && false === tank_todo( $store, 513 )[ $combo ], json_encode( $line ) );
$store->log_dose( $five, 0, 'Kanamycin', 10, 'g' );
$store->log_dose( $five, 0, 'Furan-2', 20, 'packet' );
check( 'all three on the system: done once, for every tank', true === array_column( block_of( $store, 500 )['items'], null, 'label' )[ $combo ]['done'] && true === tank_todo( $store, 521 )[ $combo ] && true === tank_todo( $store, 513 )[ $combo ] );

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
$open = tank_todo( $store, 521 );
act( $store, 521, 'dose', array( 'medication' => 'copper', 'amount' => '55', 'unit' => 'ml', 'whole' => '1' ) );
check( 'a step that names one medication is ticked by its dose, as before', false === $open['Copper Power to 2.0'] && true === tank_todo( $store, 521 )['Copper Power to 2.0'] && true === array_column( block_of( $store, 500 )['items'], null, 'label' )['Copper Power to 2.0']['done'] );

/* ------------------------------------------------------------------ */
echo "7. The count for other screens: nothing while the section is off\n";

// A database that has the tables and nothing in them.
$GLOBALS['wpdb'] = new class() {
	public $prefix    = 'wp_';
	public $users     = 'wp_users';
	public $posts     = 'wp_posts';
	public $insert_id = 0;
	public $queries   = 0;
	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		return vsprintf( str_replace( array( '%d', '%f' ), '%s', $query ), $args );
	}
	public function get_var( $query ) {
		$this->queries++;
		return 1 === preg_match( '/^SHOW TABLES LIKE (\S+)$/', (string) $query, $m ) ? $m[1] : null;
	}
	public function get_results( $query, $output = null ) {
		$this->queries++;
		return array();
	}
	public function get_row( $query, $output = null ) {
		$this->queries++;
		return null;
	}
	public function get_col( $query ) {
		$this->queries++;
		return array();
	}
};
check( 'the section has not booted in this run', false === Clean_Room::is_booted() );
check( 'switched off: null from the function and from the callback — and no query at all', null === fishotel_cleanroom_todo() && null === Clean_Room::todo_summary() && null === Clean_Room::filter_todo( null ) && 0 === $GLOBALS['wpdb']->queries );
check( 'switched off, the product facts are nobody\'s business either: null from fishotel_cleanroom_product_facts(), no query', null === fishotel_cleanroom_product_facts( 501 ) && null === Clean_Room::product_facts( 501 ) && 0 === $GLOBALS['wpdb']->queries );
$flag = new ReflectionProperty( Clean_Room::class, 'booted' );
$flag->setAccessible( true );
$flag->setValue( null, true );
$summary = fishotel_cleanroom_todo();
check( 'once it has booted, the same call answers', Clean_Room::is_booted() && is_array( $summary ) && 0 === $summary['left'] && 'Tanks: nothing due today' === $summary['text'] && $GLOBALS['wpdb']->queries > 0, json_encode( $summary ) );
$flag->setValue( null, false );

fh_finish();
