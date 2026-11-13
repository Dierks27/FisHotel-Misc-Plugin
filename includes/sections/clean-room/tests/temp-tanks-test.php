<?php
/**
 * Temporary tanks — the slots of the own-water building and their labels,
 * setting one up (validation, "what is it for" → the templates carrying
 * that tag, the schedule copied and independent of its template in both
 * directions, the tank's own clock), the quarantine / clean rules for
 * arrivals, moves and clearing, readings and doses as the tank's own
 * water, taking one down (refused with fish, the history kept, the next
 * setup in the slot starting clean), the 1.6 schema and its gate from
 * every earlier version, and a layout without a setup behaving as before.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/temp-tanks-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Front_Desk;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Protocol;
use FisHotel\Misc\Sections\Clean_Room\Qr;
use FisHotel\Misc\Sections\Clean_Room\Replay;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;
use FisHotel\Misc\Sections\Clean_Room\Todo;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

$today    = Replay::date_in_zone( time(), wp_timezone() ); // The acts stamp events and logs with the real clock.
$settings = Settings::get();

/**
 * A perform() context on a tank's page (0 = no tank: the picker).
 */
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

/**
 * One act as the page posts it: an edit, a schedule change and a take
 * down name the setup the page was showing (its id and its token).
 */
function act( Fake_Store $store, $tank_number, $act, array $raw, $actor = 8 ) {
	$ctx = ctx( $store, $tank_number, $actor );
	if ( in_array( $act, array( 'setup_edit', 'setup_steps', 'takedown' ), true ) && ! isset( $raw['setup_id'] ) && ! empty( $ctx['tank']['setup'] ) ) {
		$raw['setup_id'] = (int) $ctx['tank']['setup']['id'];
		$raw['seen']     = Temp_Tanks::seen( $ctx['tank']['setup'] );
	}
	return Front_Desk::perform( $act, $ctx, $raw );
}

/**
 * Set a slot up through the phone's action; returns the open setup.
 */
function set_up( Fake_Store $store, array $raw, $tank_number = 0 ) {
	$r = act( $store, $tank_number, 'setup', $raw );
	if ( ! empty( $r['error'] ) ) {
		return $r;
	}
	$open = $store->open_setups();
	$tank = $store->tank_by_number( (int) $r['tank'] );
	return $open[ (int) $tank['id'] ];
}

/* ------------------------------------------------------------------ */
echo "1. The slots: numbering and labels\n";

check( 'slot_number: building + slot', 901 === Layout::slot_number( 900, 1 ) && 909 === Layout::slot_number( 900, 9 ) && 803 === Layout::slot_number( 800, 3 ) );
check( 'parse_slot: hundreds = building, tens 0, ones = the slot', array( 'building' => 900, 'slot' => 1 ) === Layout::parse_slot( 901 ) && array( 'building' => 800, 'slot' => 9 ) === Layout::parse_slot( 809 ) );
check( 'parse_slot refuses a rack number, a round hundred, slot 10 and junk', null === Layout::parse_slot( 921 ) && null === Layout::parse_slot( 900 ) && null === Layout::parse_slot( 910 ) && null === Layout::parse_slot( 'x' ) && null === Layout::parse_slot( 1001 ) );
check( 'the rack rule is untouched: 901 is no rack number', null === Layout::parse_number( 901 ) && ! Layout::number_fits( 901, 900 ) );

$temp = Layout::temp_seed();
check( 'the Temporary building: 900, own water, area temp, six slots 901–906', 900 === $temp['number'] && 1 === $temp['own_water'] && 'temp' === $temp['area'] && 'Temporary' === $temp['name'] && array( 901, 902, 903, 904, 905, 906 ) === array_column( $temp['tanks'], 'number' ) );
$not_racked = true;
foreach ( $temp['tanks'] as $i => $row ) {
	$not_racked = $not_racked && 0 === $row['level'] && $i + 1 === $row['position'] && '' === $row['size_label'] && 0 === $row['gallons'] && 'active' === $row['status'];
}
check( 'a slot is not on a rack: level 0, position = the slot, no size and no gallons of its own', $not_racked );
check( 'six slots by default, nine at most', 6 === Layout::TEMP_SLOTS && 9 === Layout::MAX_SLOTS && 9 === count( Layout::temp_seed( 900, 40 )['tanks'] ) );
check( 'the building number: 900, else the highest free hundred, else none', 900 === Layout::temp_building_number( array( 100, 200, 300, 400, 500, 600 ) ) && 800 === Layout::temp_building_number( array( 100, 900 ) ) && 0 === Layout::temp_building_number( array( 100, 200, 300, 400, 500, 600, 700, 800, 900 ) ) );
check( 'another slot: the lowest free one, none once all nine exist', 7 === Layout::next_slot( 900, array( 901, 902, 903, 904, 905, 906 ) ) && 2 === Layout::next_slot( 900, array( 901, 903 ) ) && 0 === Layout::next_slot( 900, range( 901, 909 ) ) );

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Test Clownfish' ) );
$slot  = $store->tank_by_number( 901 );
check( 'the layout has the slots: own water, slot 1, nothing set up, neither Spa nor Clean Room', $slot && $slot['own_water'] && 1 === $slot['slot'] && null === $slot['setup'] && 'temp' === $slot['effective_area'] && 'temp' === $slot['building']['area'] );
$label = Layout::label( $slot, $slot['building'] );
check( 'the printed label: "TEMP 1" large, "901 · Temporary · …" small', 'TEMP 1' === $label['number'] && 0 === strpos( $label['line'], '901 · Temporary' ) && 1 === $label['slot'], json_encode( $label ) );
check( 'a rack tank\'s label is as it was', array( 'number' => '521', 'line' => 'Spa · Building 500 · 20G long' ) === Layout::label( $store->tank_by_number( 521 ), $store->tank_by_number( 521 )['building'] ) );
check( 'tank_label: "TEMP 1 · 901" / "Tank 521"', 'TEMP 1 · 901' === Layout::tank_label( $slot ) && 'Tank 521' === Layout::tank_label( $store->tank_by_number( 521 ) ) );
check( 'the label opens the same Front Desk URL, and it fits a QR', 'https://example.test/?fh_front_desk=1&tank=901' === Clean_Room::front_desk_url( 901 ) && '' !== Qr::svg( Clean_Room::front_desk_url( 909 ), 4, 4 ) );
check( 'a slot shares water with nobody; its building has no system volume', array() === Layout::shares_water( $slot, $store->tanks() ) && 0 === Layout::system_gallons( $store->temp_building(), $store->tanks() ) && array( 511, 512, 513, 522, 523, 524 ) === Layout::shares_water( $store->tank_by_number( 521 ), $store->tanks() ) );
check( 'its path names the slot', 'Temporary · TEMP 1 · Tank 901' === $slot['path'], $slot['path'] );
$groups = Layout::group( $store->buildings(), $store->tanks() );
check( 'the layout groups it under "Temporary", after the two areas', array( 'clean', 'spa', 'temp' ) === array_keys( $groups ) && 'Temporary' === $groups['temp']['label'] && 6 === count( $groups['temp']['buildings'][0]['tanks'] ) );
check( 'the area "temp" is the own-water building\'s alone', in_array( 'area must be clean or spa', Layout::validate_building( array( 'area' => 'temp', 'number' => 700 ) ), true ) && array() === Layout::validate_building( array( 'area' => 'temp', 'number' => 900, 'own_water' => true ) ) && array() !== Layout::validate_building( array( 'area' => 'spa', 'number' => 900, 'own_water' => true ) ) );
check( 'a free slot\'s title says so', 'TEMP 1 · 901 · Temporary · nothing set up here' === Temp_Tanks::title( $slot, $today ), Temp_Tanks::title( $slot, $today ) );

/* ------------------------------------------------------------------ */
echo "2. Setting one up: validation\n";

$protocols = Settings::protocols();
$vctx      = array(
	'tanks'     => $store->tanks(),
	'protocols' => $protocols,
	'today'     => $today,
);
$good      = array(
	'name'     => 'Hospital A',
	'purpose'  => 'bacterial infection',
	'kind'     => 'quarantine',
	'gallons'  => '10',
	'template' => 'Trifecta',
	'note'     => 'by the sink',
);
$v         = Temp_Tanks::validate( $good, $vctx );
check( 'a complete setup passes: the first free slot, today, the treatment\'s steps', array() === $v['errors'] && 901 === $v['setup']['tank_number'] && 1 === $v['setup']['slot'] && $today === $v['setup']['start_date'] && 10 === $v['setup']['gallons'] && 'quarantine' === $v['setup']['kind'] && 'Trifecta' === $v['setup']['template'] && 4 === count( $v['setup']['steps'] ), json_encode( $v['errors'] ) );
$v = Temp_Tanks::validate( array_diff_key( $good, array( 'kind' => 1 ) ), $vctx );
check( 'the kind is asked, never assumed', null === $v['setup'] && array( 'Is it a clean tank or a quarantine tank? Pick one.' ) === $v['errors'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'kind' => 'spa' ) ), $vctx );
check( 'a kind that is neither is refused', 1 === count( $v['errors'] ) );
foreach ( array( 'abc', '-5', '10000' ) as $bad ) {
	$v = Temp_Tanks::validate( array_merge( $good, array( 'gallons' => $bad ) ), $vctx );
	check( 'gallons "' . $bad . '" refused', null === $v['setup'] && 1 === count( $v['errors'] ) && false !== strpos( $v['errors'][0], 'Gallons' ) );
}
$v = Temp_Tanks::validate( array_merge( $good, array( 'gallons' => '' ) ), $vctx );
check( 'blank gallons = not known (0), allowed', array() === $v['errors'] && 0 === $v['setup']['gallons'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'template' => 'No Such Schedule' ) ), $vctx );
check( 'a treatment that does not exist is refused', null === $v['setup'] && false !== strpos( $v['errors'][0], 'does not exist' ) );
$v = Temp_Tanks::validate( array_merge( $good, array( 'template' => '' ) ), $vctx );
check( '"no treatment, just watching": the daily feeding alone', array() === $v['errors'] && '' === $v['setup']['template'] && 1 === count( $v['setup']['steps'] ) && 'feed' === $v['setup']['steps'][0]['kind'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'start_date' => 'next week' ) ), $vctx );
check( 'a start date that is no date is refused', null === $v['setup'] && false !== strpos( $v['errors'][0], 'not a date' ) );
$v = Temp_Tanks::validate( array_merge( $good, array( 'start_date' => Replay::add_days( $today, -400 ) ) ), $vctx );
check( 'a start more than a year back is refused', null === $v['setup'] && false !== strpos( $v['errors'][0], 'Pick a start date' ) );
$v = Temp_Tanks::validate( array_merge( $good, array( 'start_date' => Replay::add_days( $today, 61 ) ) ), $vctx );
check( 'a start more than 60 days ahead is refused', null === $v['setup'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'start_date' => Replay::add_days( $today, -1 ) ) ), $vctx );
check( 'yesterday is fine', array() === $v['errors'] && Replay::add_days( $today, -1 ) === $v['setup']['start_date'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'slot' => 521 ) ), $vctx );
check( 'a rack tank is no slot', null === $v['setup'] && false !== strpos( $v['errors'][0], 'not a temporary slot' ) );
$v = Temp_Tanks::validate( array_merge( $good, array( 'slot' => 904 ) ), $vctx );
check( 'a named free slot is taken as named', array() === $v['errors'] && 904 === $v['setup']['tank_number'] && 4 === $v['setup']['slot'] );
$v = Temp_Tanks::validate( array_merge( $good, array( 'name' => '<b>Hospital</b>   ' . str_repeat( 'x', 80 ), 'purpose' => str_repeat( 'p', 200 ), 'note' => str_repeat( 'n', 3000 ) ) ), $vctx );
check( 'the texts are plain and cut to size', array() === $v['errors'] && 60 === strlen( $v['setup']['name'] ) && 0 === strpos( $v['setup']['name'], 'Hospital x' ) && 120 === strlen( $v['setup']['purpose'] ) && 2000 === strlen( $v['setup']['note'] ) );
$v = Temp_Tanks::validate( array( 'kind' => 'clean' ), $vctx );
check( 'only the kind is required: no name, no purpose, no treatment', array() === $v['errors'] && '' === $v['setup']['name'] && '' === $v['setup']['purpose'] && '' === $v['setup']['template'] );
$bare = new Fake_Store( array(), false );
$v    = Temp_Tanks::validate( $good, array( 'tanks' => $bare->tanks(), 'protocols' => $protocols, 'today' => $today ) );
check( 'a layout without slots says so', null === $v['setup'] && false !== strpos( $v['errors'][0], 'no temporary slots' ) );

$a = set_up( $store, $good );
check( 'set up from the picker: the first free slot, the message names it', isset( $a['id'] ) && 901 === $a['tank_number'] && 'Hospital A' === $a['name'] && 'quarantine' === $a['kind'] && $a['open'] && 8 === $a['created_by'], json_encode( $a ) );
$slot = $store->tank_by_number( 901 );
check( 'the slot now carries the setup and behaves as a Spa tank', $slot['setup'] && (int) $a['id'] === (int) $slot['setup']['id'] && 'spa' === $slot['effective_area'] && ! Temp_Tanks::is_free( $slot ) );
check( 'its title: slot, name, kind, purpose, own water, gallons, schedule, day', 'TEMP 1 · 901 · Hospital A · Temporary · Quarantine · bacterial infection · its own water · 10 gal · schedule Trifecta · day 1' === Temp_Tanks::title( $slot, $today ), Temp_Tanks::title( $slot, $today ) );
$r = act( $store, 0, 'setup', array_merge( $good, array( 'slot' => 901 ) ) );
check( 'a slot that is set up cannot be set up again', isset( $r['error'] ) && false !== strpos( $r['error'], 'TEMP 1 · 901 is already set up (Hospital A)' ), json_encode( $r ) );
check( 'the store refuses a second open setup in one slot too', false === $store->insert_setup( array( 'tank_id' => (int) $slot['id'], 'slot' => 1, 'kind' => 'clean', 'start_date' => $today ) ) && false === $store->insert_setup( array( 'tank_id' => (int) $store->tank_by_number( 521 )['id'], 'slot' => 1, 'kind' => 'clean', 'start_date' => $today ) ) );
$b = set_up( $store, array( 'name' => 'Holding', 'kind' => 'clean', 'gallons' => 20 ), 903 );
check( 'scanned from a free slot\'s label: that slot', isset( $b['id'] ) && 903 === $b['tank_number'] && 'clean' === $b['kind'] && 'clean' === $store->tank_by_number( 903 )['effective_area'] );
$c = set_up( $store, array( 'kind' => 'clean', 'gallons' => 15, 'name' => 'Clean B' ) );
check( 'the next one from the picker takes the first FREE slot (902)', isset( $c['id'] ) && 902 === $c['tank_number'] );
foreach ( array( 904, 905, 906 ) as $n ) {
	set_up( $store, array( 'kind' => 'quarantine' ) );
}
$r = act( $store, 0, 'setup', array( 'kind' => 'quarantine' ) );
check( 'every slot in use: said plainly', isset( $r['error'] ) && false !== strpos( $r['error'], 'Every temporary slot is in use' ) );
check( 'another slot can be added (907), up to nine', 907 === $store->add_slot() && 908 === $store->add_slot() && 909 === $store->add_slot() && false === $store->add_slot() );
$d = set_up( $store, array( 'kind' => 'quarantine' ) );
check( 'and the new slot can be set up', isset( $d['id'] ) && 907 === $d['tank_number'] );

/* ------------------------------------------------------------------ */
echo "3. What it is for → the treatment\n";

$GLOBALS['fh_options'] = array();
$protocols             = Settings::protocols();
$map                   = Temp_Tanks::template_tags( $protocols, null );
check( 'the seed: only Trifecta, "bacterial infection"; every other template empty', array( 'bacterial infection' ) === $map['Trifecta'] && array() === $map['14 Day CM'] && array() === $map['14 Day CF'] && array() === $map['Xtra 14 Day CF'] && array() === $map['CQ'] && 5 === count( $map ) );
check( 'the tags offered: the ones that exist', array( 'bacterial infection' ) === Temp_Tanks::all_tags( $map ) );
check( 'choosing the tag offers the template carrying it (any case)', array( 'Trifecta' ) === Temp_Tanks::templates_for( 'Bacterial Infection', $map ) );
check( 'nothing is offered for a tag no template carries', array() === Temp_Tanks::templates_for( 'ich', $map ) && array() === Temp_Tanks::templates_for( '', $map ) && array() === Temp_Tanks::templates_for( 'bacterial', $map ) );
check( 'tags as typed: commas, semicolons, lines; repeats and markup dropped', array( 'ich', 'velvet', 'flukes' ) === Temp_Tanks::clean_tags( "ich, Ich ; <i>velvet</i>\nflukes,," ) && 12 === count( Temp_Tanks::clean_tags( implode( ',', range( 1, 30 ) ) ) ) && 40 === strlen( Temp_Tanks::clean_tags( str_repeat( 'x', 90 ) )[0] ) );
check( 'nothing is stored until a tag is saved', null === Temp_Tanks::stored_tags() );
$map = Temp_Tanks::save_tags( 'CQ', 'ich, velvet', $protocols );
check( 'saving one template\'s tags keeps the others (the seed included)', array( 'ich', 'velvet' ) === $map['CQ'] && array( 'bacterial infection' ) === $map['Trifecta'] && is_array( Temp_Tanks::stored_tags() ) );
$map = Temp_Tanks::save_tags( 'Xtra 14 Day CF', 'ich', $protocols );
check( 'a tag two templates carry offers both, in the templates\' order', array( 'Xtra 14 Day CF', 'CQ' ) === Temp_Tanks::templates_for( 'ICH', Temp_Tanks::tags( $protocols ) ) && array( 'bacterial infection', 'ich', 'velvet' ) === Temp_Tanks::all_tags( Temp_Tanks::tags( $protocols ) ) );
$map = Temp_Tanks::save_tags( 'Trifecta', '', $protocols );
check( 'a removed tag stays removed: the seed does not come back', array() === Temp_Tanks::tags( $protocols )['Trifecta'] && array() === Temp_Tanks::templates_for( 'bacterial infection', Temp_Tanks::tags( $protocols ) ) );
$before = Temp_Tanks::stored_tags();
Temp_Tanks::save_tags( 'No Such Template', 'x', $protocols );
check( 'a template that does not exist gets no tags', $before === Temp_Tanks::stored_tags() );
check( 'a renamed template keeps its tags; a deleted one loses them', Temp_Tanks::rename_tags( 'CQ', 'CQ plus' ) && array( 'ich', 'velvet' ) === Temp_Tanks::stored_tags()['CQ plus'] && ! isset( Temp_Tanks::stored_tags()['CQ'] ) && Temp_Tanks::rename_tags( 'CQ plus', '' ) && ! isset( Temp_Tanks::stored_tags()['CQ plus'] ) && ! Temp_Tanks::rename_tags( 'Nope', 'x' ) );
$GLOBALS['fh_options'] = array();
check( 'renaming the seeded template before anything was saved carries the seed along', Temp_Tanks::rename_tags( 'Trifecta', 'Antibiotics' ) && array( 'Antibiotics' => array( 'bacterial infection' ) ) === Temp_Tanks::stored_tags() );
$GLOBALS['fh_options'] = array();

$preview = Temp_Tanks::preview( $protocols['templates']['Trifecta'] );
check( 'before anything is saved: which medications, on which days — the feeding left out', 3 === count( $preview ) && 'Metronidazole + Kanamycin + Furan-2' === $preview[0]['label'] && 'day 1' === $preview[0]['when'] && 'Metronidazole' === $preview[0]['medication'] && 'days 2, 4, 6, 8, 10, 12, 14' === $preview[1]['when'] && 'days 3, 5, 7, 9, 11, 13, 15' === $preview[2]['when'] && '' === $preview[2]['medication'], json_encode( $preview ) );
check( 'when_text: once, daily, every other day, open-ended', 'day 16' === Temp_Tanks::when_text( Protocol::step( 'x', 'other', 16 ) ) && 'days 5–14, daily' === Temp_Tanks::when_text( Protocol::step( 'x', 'dose', 5, 1, 14 ) ) && 'daily from day 1' === Temp_Tanks::when_text( Protocol::step( 'x', 'feed', 1, 1 ) ) && 'from day 3, every 2 days' === Temp_Tanks::when_text( Protocol::step( 'x', 'dose', 3, 2 ) ) && 'days 1–120, every 3 days' === Temp_Tanks::when_text( Protocol::step( 'x', 'dose', 1, 3, 120 ) ) );

/* ------------------------------------------------------------------ */
echo "4. The copied schedule is the tank's own\n";

$store     = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Test Clownfish' ) );
$protocols = Settings::protocols();
$a         = set_up( $store, $good );
$copied    = $store->setup( $a['id'] )['steps'];
check( 'the steps were copied as the template stands', Protocol::normalize( $protocols['templates']['Trifecta'] ) === $copied && 4 === count( $copied ) );

$edited                               = $protocols;
$edited['templates']['Trifecta'][1]['day'] = 5;
$edited['templates']['Trifecta'][]    = Protocol::step( 'Extra bath', 'water_change', 9 );
$edited                               = Settings::save_protocols( $edited );
check( 'the template was really changed', 5 === Settings::protocols()['templates']['Trifecta'][1]['day'] && 5 === count( Settings::protocols()['templates']['Trifecta'] ) );
check( 'a later edit of the template never reaches a tank that is running', $copied === $store->setup( $a['id'] )['steps'] );

$r = act( $store, 901, 'setup_steps', array( 'op' => 'move', 'index' => 2, 'days' => 1 ) );
check( 'a step moved a day, for this tank alone', empty( $r['error'] ) && 3 === $store->setup( $a['id'] )['steps'][2]['day'] && 15 === $store->setup( $a['id'] )['steps'][2]['until'] && false !== strpos( $r['message'], 'for this tank only' ), json_encode( $r ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'add', 'label' => 'Methylene blue dip', 'kind' => 'other', 'day' => 4, 'where' => 'spa' ) );
check( 'a step added (its "where" does not apply: the tank is its own place)', empty( $r['error'] ) && 5 === count( $store->setup( $a['id'] )['steps'] ) && 'Methylene blue dip' === $store->setup( $a['id'] )['steps'][4]['label'] && 'any' === $store->setup( $a['id'] )['steps'][4]['where'] );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'remove', 'index' => 1 ) );
check( 'a step removed', empty( $r['error'] ) && 4 === count( $store->setup( $a['id'] )['steps'] ) && 'Furan-2' === $store->setup( $a['id'] )['steps'][1]['label'] );
check( 'none of it touched the template', $edited === Settings::protocols() && 5 === count( Settings::protocols()['templates']['Trifecta'] ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'move', 'index' => 0, 'days' => -1 ) );
check( 'a step cannot move before day 1', isset( $r['error'] ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'remove', 'index' => 40 ) );
check( 'a step that is not there: refused', isset( $r['error'] ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'add', 'label' => '  ' ) );
check( 'a step without a name: refused', 'Name the step.' === ( $r['error'] ?? '' ) );
$r = act( $store, 901, 'setup_steps', array( 'op' => 'save', 'steps' => array( array( 'label' => 'Feed', 'kind' => 'feed', 'day' => 1, 'every' => 1 ), array( 'label' => 'Kanamycin', 'kind' => 'dose', 'day' => 1, 'every' => 2, 'until' => 9 ) ) ) );
check( 'the Board\'s editor replaces the whole list', empty( $r['error'] ) && array( 'Feed', 'Kanamycin' ) === array_column( $store->setup( $a['id'] )['steps'], 'label' ) );
$r = act( $store, 902, 'setup_steps', array( 'op' => 'add', 'label' => 'x' ) );
check( 'a free slot has no schedule to change', isset( $r['error'] ) && false !== strpos( $r['error'], 'Nothing is set up there' ) );
$GLOBALS['fh_options'] = array();
$protocols             = Settings::protocols();

/* ------------------------------------------------------------------ */
echo "5. Its own clock\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Test Clownfish' ) );
$a     = set_up( $store, array_merge( $good, array( 'start_date' => Replay::add_days( $today, -2 ) ) ) );
check( 'day 1 is the treatment start date: started two days ago = day 3', 3 === Temp_Tanks::day( $a, $today ) && 'day 3' === Temp_Tanks::day_text( $a, $today ) );
$late = set_up( $store, array( 'kind' => 'clean', 'template' => 'Trifecta', 'start_date' => Replay::add_days( $today, 3 ) ) );
check( 'a treatment that starts later is not on day 1 yet', -2 === Temp_Tanks::day( $late, $today ) && 'starts ' . Replay::add_days( $today, 3 ) === Temp_Tanks::day_text( $late, $today ) );

// A group checked in nine days ago, moved into the hospital tank today.
act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 3, 'date' => Replay::add_days( $today, -9 ) ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 901, 'stay_id' => 1, 'reason' => 'hospital' ) );
check( 'moved into the hospital tank, reason "hospital"', empty( $r['error'] ) && 'Blue Test Wrasse moved to Tank 901 · hospital' === $r['message'], json_encode( $r ) );
$stay = $store->stay( 1, $today, $settings );
check( 'the fish\'s own quarantine day and dates are untouched', 10 === $stay['day'] && Replay::add_days( $today, -9 ) === $stay['checkin_date'] && null === $stay['cleared_date'] && 'in_qt' === $stay['status'] );
$room  = $store->room( $today, $settings, $protocols );
$tanks = array_column( $room['tanks'], null, 'number' );
$todo  = $tanks[901]['todo'];
check( 'the to-do counts from the tank\'s start, not the fish\'s check-in: day 3 of Trifecta', array( 'Feed', '25 % water change, then the combo' ) === array_column( $todo, 'label' ) && 3 === $todo[1]['day'] && null === $todo[0]['day'], json_encode( $todo ) );
check( 'a tank whose treatment has not started lists nothing but (with fish) the feeding', array() === $tanks[902]['todo'] );
$r = act( $store, 901, 'setup_edit', array( 'start_date' => $today ) );
check( 'the clock can be restarted: the treatment counts from today', empty( $r['error'] ) && false !== strpos( $r['message'], 'counts from ' . $today ) && 1 === Temp_Tanks::day( $store->setup( $a['id'] ), $today ) );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'day 1 again: the combo', array( 'Feed', 'Metronidazole + Kanamycin + Furan-2' ) === array_column( $tanks[901]['todo'], 'label' ) );
$r = act( $store, 901, 'setup_edit', array( 'name' => 'Hospital One', 'gallons' => '12', 'purpose' => 'fin rot', 'note' => '' ) );
$s = $store->setup( $a['id'] );
check( 'its name, purpose, gallons and note can change; the kind and the slot cannot', empty( $r['error'] ) && 'Hospital One' === $s['name'] && 12 === $s['gallons'] && 'fin rot' === $s['purpose'] && 'quarantine' === $s['kind'] && 901 === $s['tank_number'] );
$r = act( $store, 901, 'setup_edit', array( 'kind' => 'clean', 'name' => 'Hospital One' ) );
check( 'posting a kind changes nothing', 'Nothing changed.' === ( $r['message'] ?? '' ) && 'quarantine' === $store->setup( $a['id'] )['kind'] );
$r = act( $store, 901, 'setup_edit', array( 'gallons' => 'lots' ) );
check( 'a bad edit is refused whole', isset( $r['error'] ) && 12 === $store->setup( $a['id'] )['gallons'] );

/* ------------------------------------------------------------------ */
echo "6. Quarantine or clean: arrivals, moves, clearing\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Test Clownfish' ) );
$q     = set_up( $store, array( 'name' => 'Hospital', 'kind' => 'quarantine', 'gallons' => 10 ) );      // 901
$k     = set_up( $store, array( 'name' => 'Holding', 'kind' => 'clean', 'gallons' => 20 ) );            // 902

$r = act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
check( 'an arrival may go into a QUARANTINE temporary tank', empty( $r['error'] ) && '2 × Blue Test Wrasse in Tank 901' === $r['message'] && 901 === $r['tank'], json_encode( $r ) );
$r = act( $store, 902, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
check( 'never into a CLEAN one', 'Arrivals go into a Spa tank. Pick one.' === ( $r['error'] ?? '' ) );
$r = act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 2, 'to_tank' => 903 ) );
check( 'never into a slot with nothing set up', 'Arrivals go into a Spa tank. Pick one.' === ( $r['error'] ?? '' ) && 1 === count( $store->stays ) );
$r = act( $store, 121, 'arrival', array( 'product_id' => 13, 'qty' => 4, 'to_tank' => 901 ) );
check( 'from any page, a quarantine temporary tank can be the check-in tank', empty( $r['error'] ) && 901 === $r['tank'] );
$spa = array_column( Front_Desk::spa_tanks( $store->room( $today, $settings, $protocols )['tanks'] ), 'number' );
check( 'the check-in chooser offers the Spa tanks and the quarantine temporary tank — not the clean one, not a free slot', in_array( 901, $spa, true ) && in_array( 521, $spa, true ) && ! in_array( 902, $spa, true ) && ! in_array( 903, $spa, true ) && ! in_array( 121, $spa, true ) );

$s = $store->stay( 1, $today, $settings );
check( 'fish in a quarantine temporary tank stay uncleared', 'in_qt' === $s['status'] && null === $s['cleared_date'] && 'temp' === $s['area'] && $s['own_water'] && 'Temporary · TEMP 1 · Tank 901' === $s['tank_path'] );
$r = act( $store, 901, 'move', array( 'to_tank' => 121, 'stay_id' => 1 ) );
$s = $store->stay( 1, $today, $settings );
check( 'a move from it into the Clean Room clears the group, exactly as out of the Spa', empty( $r['error'] ) && false !== strpos( $r['message'], 'cleared today' ) && $today === $s['cleared_date'] && 'cleared' === $s['status'] );

$r = act( $store, 901, 'move', array( 'to_tank' => 902, 'stay_id' => 2 ) );
$s = $store->stay( 2, $today, $settings );
check( 'a quarantine group moved into a CLEAN temporary tank is cleared', empty( $r['error'] ) && false !== strpos( $r['message'], 'cleared today' ) && $today === $s['cleared_date'] && 'cleared' === $s['status'] && 902 === $s['tank_number'] );

act( $store, 521, 'arrival', array( 'species' => 'Test Goby', 'qty' => 5 ) );
$r = act( $store, 521, 'move', array( 'to_tank' => 902, 'stay_id' => 3 ) );
$s = $store->stay( 3, $today, $settings );
check( 'straight from the Spa into a clean temporary tank: cleared too', empty( $r['error'] ) && $today === $s['cleared_date'] );
$r = act( $store, 902, 'move', array( 'to_tank' => 122, 'stay_id' => 3 ) );
check( 'out of the clean temporary tank into the Clean Room: nothing more to clear', empty( $r['error'] ) && false === strpos( $r['message'], 'cleared today' ) && $today === $store->stay( 3, $today, $settings )['cleared_date'] );

act( $store, 902, 'clear', array( 'stay_id' => 2, 'unset' => 1 ) );
check( 'a hand unset…', null === $store->stay( 2, $today, $settings )['cleared_date'] );
$r = act( $store, 902, 'move', array( 'to_tank' => 123, 'stay_id' => 2 ) );
check( '…then clean temporary → Clean Room does not re-clear (clean → clean, as in the Clean Room)', empty( $r['error'] ) && null === $store->stay( 2, $today, $settings )['cleared_date'] );

act( $store, 522, 'arrival', array( 'species' => 'Test Blenny', 'qty' => 2 ) );
$r = act( $store, 522, 'move', array( 'to_tank' => 901, 'stay_id' => 4, 'reason' => 'hospital' ) );
check( 'Spa → quarantine temporary tank: still in quarantine', empty( $r['error'] ) && null === $store->stay( 4, $today, $settings )['cleared_date'] && false !== strpos( $r['message'], '· hospital' ) );
$r = act( $store, 901, 'move', array( 'to_tank' => 523, 'stay_id' => 4 ) );
check( 'and back to the Spa: still in quarantine', empty( $r['error'] ) && null === $store->stay( 4, $today, $settings )['cleared_date'] && 523 === $store->stay( 4, $today, $settings )['tank_number'] );

$r = act( $store, 523, 'move', array( 'to_tank' => 903, 'stay_id' => 4 ) );
check( 'a move into a slot with nothing set up is refused, and says what to do', 'Nothing is set up in TEMP 3 · 903 — set it up first.' === ( $r['error'] ?? '' ) && 523 === $store->stay( 4, $today, $settings )['tank_number'], json_encode( $r ) );
check( 'the store itself lets nothing land in a free slot', false === $store->record_event( 4, array( 'type' => 'move', 'from_tank_id' => (int) $store->tank_by_number( 523 )['id'], 'to_tank_id' => (int) $store->tank_by_number( 903 )['id'], 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings ) );
check( 'event_setups: the setup a move went into / came out of; a rack tank has none', array( 'from' => null, 'to' => 7 ) === Store::event_setups( 'move', 4, 30, array( 30 => 7, 31 => 0 ) ) && array( 'from' => 7, 'to' => null ) === Store::event_setups( 'move', 30, 4, array( 30 => 7 ) ) && false === Store::event_setups( 'arrival', 0, 31, array( 30 => 7, 31 => 0 ) ) && array( 'from' => null, 'to' => null ) === Store::event_setups( 'note', 0, 31, array( 31 => 0 ) ) && array( 'from' => null, 'to' => null ) === Store::event_setups( 'move', 4, 5, array() ) );

// A split out of the Spa into the clean temporary tank: the sister is cleared, the rest are not.
act( $store, 524, 'arrival', array( 'species' => 'Test Chromis', 'qty' => 6 ) );
$r = act( $store, 524, 'move', array( 'to_tank' => 902, 'stay_id' => 5, 'qty' => 2 ) );
check( 'a split into the clean temporary tank clears the two that moved, not the four that stayed', empty( $r['error'] ) && 4 === $store->stay( 5, $today, $settings )['qty_live'] && null === $store->stay( 5, $today, $settings )['cleared_date'] && 2 === $store->stay( 6, $today, $settings )['qty_live'] && $today === $store->stay( 6, $today, $settings )['cleared_date'] && 902 === $store->stay( 6, $today, $settings )['tank_number'] );

// The same slot, clean this month and quarantine the next: history keeps its meaning.
act( $store, 902, 'move', array( 'to_tank' => 111, 'stay_id' => 6 ) );
$r = act( $store, 902, 'takedown', array() );
check( 'the clean tank is empty and taken down', empty( $r['error'] ), json_encode( $r ) );
$again = set_up( $store, array( 'name' => 'Hospital two', 'kind' => 'quarantine' ), 902 );
check( 'the slot is set up again — as a QUARANTINE tank this time', isset( $again['id'] ) && 902 === $again['tank_number'] && 'spa' === $store->tank_by_number( 902 )['effective_area'] );
$store->record_event( 6, array( 'type' => 'note', 'note' => 'looking fine', 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings ); // Replays stay 6 from all of its events.
check( 'a group cleared by moving into that slot when it was clean is still cleared after a replay', $today === $store->stay( 6, $today, $settings )['cleared_date'] && 'cleared' === $store->stay( 6, $today, $settings )['status'] );
$events = $store->events( 6 );
$moves  = array_values( array_filter( $events, static function ( $e ) { return 'move' === $e['type']; } ) );
check( 'the move events carry the area the slot behaved as at the time', 'clean' === $moves[0]['to_area'] && (int) $k['id'] === $moves[0]['to_setup_id'] && 'clean' === $moves[1]['from_area'] && null === $moves[1]['to_area'] );

/* ------------------------------------------------------------------ */
echo "7. Readings and doses are that tank's own water\n";

$store = new Fake_Store( array( 12 => 'Blue Test Wrasse' ) );
$q     = set_up( $store, array( 'name' => 'Hospital', 'kind' => 'quarantine', 'gallons' => 10, 'template' => '14 Day CM', 'start_date' => Replay::add_days( $today, -1 ) ) ); // 901, day 2
act( $store, 901, 'arrival', array( 'product_id' => 12, 'qty' => 2 ) );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'day 2 of the copied 14 Day CM on the tank\'s own clock — the Spa-only steps apply here too', array( 'Feed', 'Copper Power to 2.4', 'Copper test', 'Metronidazole' ) === array_column( $tanks[901]['todo'], 'label' ) && array( false, false, false, false ) === array_column( $tanks[901]['todo'], 'done' ), json_encode( array_column( $tanks[901]['todo'], 'label' ) ) );

$r   = act( $store, 901, 'dose', array( 'medication' => 'metro', 'amount' => '0.25', 'unit' => 'g', 'whole' => '1' ) );
$log = end( $store->logs );
check( 'a dose posted as "whole system" lands on the tank: there is no system here', empty( $r['error'] ) && 'Metronidazole 0.25 g · Tank 901' === $r['message'] && (int) $store->tank_by_number( 901 )['id'] === $log['tank_id'] && null === $log['building_id'] && (int) $q['id'] === $log['setup_id'], json_encode( $r ) );
check( 'own_water_dose leaves a rack tank\'s dose as posted', array( 'whole' => '1' ) === Temp_Tanks::own_water_dose( $store->tank_by_number( 521 ), array( 'whole' => '1' ) ) && array( 'whole' => '0' ) === Temp_Tanks::own_water_dose( $store->tank_by_number( 901 ), array( 'whole' => '1' ) ) );
act( $store, 901, 'copper', array( 'value' => '2.4' ) );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'the dose ticks its step and the reading ticks the copper test — on this tank', array( false, false, true, true ) === array_column( $tanks[901]['todo'], 'done' ) && 2.4 === $tanks[901]['copper']['value'] );
check( 'no other tank sees them: not another slot, not the Spa', null === $tanks[902]['copper'] && null === $tanks[521]['copper'] );
act( $store, 901, 'tick', array( 'key' => $tanks[901]['todo'][1]['key'], 'kind' => 'dose', 'label' => 'Copper Power to 2.4' ) );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'a tick on the tank\'s own screen', array( false, true, true, true ) === array_column( $tanks[901]['todo'], 'done' ) );

// A reading in the Spa is the Spa's; a building-level entry on Temporary reaches no slot.
act( $store, 521, 'copper', array( 'value' => '2.1' ) );
$store->log_building( (int) $store->temp_building()['id'], array( 'kind' => 'copper', 'value' => 9.9 ) );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'a Spa reading is the whole Spa system\'s — and not the temporary tank\'s', 2.1 === $tanks[521]['copper']['value'] && 2.1 === $tanks[513]['copper']['value'] && null === $tanks[621]['copper'] && 2.4 === $tanks[901]['copper']['value'] );
check( 'an entry on the Temporary building reaches no slot', 2.4 === $tanks[901]['copper']['value'] && null === $tanks[902]['copper'] );

/* ------------------------------------------------------------------ */
echo "8. Taking it down\n";

$r = act( $store, 901, 'takedown', array() );
check( 'with fish in it: refused, and the screen says to move them first', isset( $r['error'] ) && 'TEMP 1 still has fish in it (2 in 1 group). Move them out first, then take it down.' === $r['error'], json_encode( $r ) );
check( 'the setup still stands', $store->setup( $q['id'] )['open'] && null !== $store->tank_by_number( 901 )['setup'] );
act( $store, 901, 'loss', array( 'stay_id' => 1, 'qty' => 1, 'reason' => 'disease' ) );
act( $store, 901, 'note', array( 'note' => 'water looks cloudy' ) );
$r = act( $store, 901, 'move', array( 'to_tank' => 522, 'stay_id' => 1 ) );
$r = act( $store, 901, 'takedown', array() );
check( 'once it is empty it comes down', empty( $r['error'] ) && 'Hospital taken down — TEMP 1 is free. Its history is kept.' === $r['message'], json_encode( $r ) );
$closed = $store->setup( $q['id'] );
check( 'the setup is closed, not deleted', ! $closed['open'] && null !== $closed['closed_at'] && 8 === $closed['closed_by'] && array( $closed ) === $store->closed_setups() );
$slot = $store->tank_by_number( 901 );
check( 'the slot is free again', null === $slot['setup'] && 'temp' === $slot['effective_area'] && Temp_Tanks::is_free( $slot ) && array() === $store->open_setups() );
$r = act( $store, 901, 'takedown', array() );
check( 'there is nothing to take down twice', isset( $r['error'] ) );
$r = act( $store, 0, 'takedown', array( 'setup_id' => $q['id'] ) );
check( 'not by its id either', isset( $r['error'] ) );
check( 'a setup that was taken down can no longer be edited', false === $store->update_setup( $q['id'], array( 'name' => 'x' ) ) && 'Hospital' === $store->setup( $q['id'] )['name'] );

$h = Temp_Tanks::history( $store, $closed );
check( 'the history: what it was', 'Hospital' === $h['setup']['name'] && 'quarantine' === $h['setup']['kind'] && 10 === $h['setup']['gallons'] && Replay::add_days( $today, -1 ) === $h['setup']['start_date'] && '14 Day CM' === $h['setup']['template'] );
check( 'the doses and the readings logged', 1 === count( $h['doses'] ) && 'Metronidazole 0.25 g' === $h['doses'][0]['text'] && 0.25 === $h['doses'][0]['value'] && 2 === $h['doses'][0]['day'] && 1 === count( $h['readings'] ) && 2.4 === $h['readings'][0]['value'], json_encode( $h['doses'] ) );
check( 'what was ticked, with the day of its own clock', 1 === count( $h['ticks'] ) && 'copper power to 2.4' === $h['ticks'][0]['text'] && 2 === $h['ticks'][0]['day'] && 'dose' === $h['ticks'][0]['kind'] );
$run = array_column( $h['run'], null, 'label' );
check( 'the treatment as it was run: each step with the days it was done', array( 2 ) === $run['Copper Power to 2.4']['done'] && array( 2 ) === $run['Copper test']['done'] && array( 2 ) === $run['Metronidazole']['done'] && array() === $run['Praziquantel 1']['done'] && array() === $run['Feed']['done'] && 'days 2–15, daily' === $run['Copper test']['when'], json_encode( $h['run'] ) );
check( 'the tank notes', 1 === count( $h['notes'] ) && 'water looks cloudy' === $h['notes'][0]['text'] );
check( 'the groups that passed through: in, how many, out, where to', 1 === count( $h['groups'] ) && 'Blue Test Wrasse' === $h['groups'][0]['species'] && $today === $h['groups'][0]['in'] && 2 === $h['groups'][0]['qty'] && $today === $h['groups'][0]['out'] && 522 === $h['groups'][0]['to'], json_encode( $h['groups'] ) );

$next  = set_up( $store, array( 'name' => 'Next one', 'kind' => 'quarantine', 'gallons' => 10, 'template' => '14 Day CM', 'start_date' => Replay::add_days( $today, -1 ) ), 901 );
$store->record_event( $store->create_stay( array( 'species' => 'Test Goby', 'checkin_date' => $today, 'tank_id' => (int) $store->tank_by_number( 901 )['id'] ) ), array( 'type' => 'arrival', 'qty' => 1, 'to_tank_id' => (int) $store->tank_by_number( 901 )['id'], 'event_at' => gmdate( 'Y-m-d H:i:s' ) ), $today, $settings );
$tanks = array_column( $store->room( $today, $settings, $protocols )['tanks'], null, 'number' );
check( 'the next setup in that slot starts clean: nothing ticked, though the old one logged today', array( false, false, false, false ) === array_column( $tanks[901]['todo'], 'done' ) && array( 'Feed', 'Copper Power to 2.4', 'Copper test', 'Metronidazole' ) === array_column( $tanks[901]['todo'], 'label' ) );
check( 'and no reading: the old setup\'s copper is not this one\'s', null === $tanks[901]['copper'] );
check( 'its own log is empty; the old one\'s is whole', array() === $store->setup_logs( $next['id'] ) && 4 === count( $store->setup_logs( $q['id'] ) ) && 1 === count( Temp_Tanks::history( $store, $next )['groups'] ) );
check( 'the old history is unchanged by the new setup', $h['doses'] === Temp_Tanks::history( $store, $store->setup( $q['id'] ) )['doses'] && 1 === count( Temp_Tanks::history( $store, $store->setup( $q['id'] ) )['groups'] ) );

/* ------------------------------------------------------------------ */
echo "9. Schema 1.6 and its gate\n";

$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$tables          = array();
foreach ( Store::schema( 'DEFAULT CHARSET=utf8mb4' ) as $statement ) {
	if ( 1 === preg_match( '/^CREATE TABLE wp_fh_([a-z_]+) \(/', $statement, $m ) ) {
		$tables[ $m[1] ] = $statement;
	}
}
check( 'schema version 1.6: eleven tables, tank_setups the new one, one CREATE per table', '1.6' === Store::DB_VERSION && 11 === count( Store::TABLES ) && 'tank_setups' === Store::TABLES[10] && array_keys( $tables ) === Store::TABLES );
check( 'fh_buildings: own_water marks "each tank its own water"; the area can be temp', 1 === preg_match( "/\n\t+own_water TINYINT\(1\) NOT NULL DEFAULT 0,\n/", $tables['buildings'] ) && false !== strpos( $tables['buildings'], "area ENUM('clean','spa','temp') NOT NULL DEFAULT 'clean'," ) );
check( 'fh_tank_setups: the slot, name, purpose, kind, gallons, start date, the copied steps, note, who and when, closed when', 1 === preg_match( "/\n\t+tank_id BIGINT\(20\) UNSIGNED NOT NULL,\n\t+open_tank_id BIGINT\(20\) UNSIGNED NULL,\n\t+slot TINYINT NOT NULL DEFAULT 0,\n\t+name VARCHAR\(60\) NOT NULL DEFAULT '',\n\t+purpose VARCHAR\(120\) NOT NULL DEFAULT '',\n\t+kind ENUM\('quarantine','clean'\) NOT NULL DEFAULT 'quarantine',\n\t+gallons SMALLINT NOT NULL DEFAULT 0,\n\t+template VARCHAR\(40\) NOT NULL DEFAULT '',\n\t+start_date DATE NOT NULL,\n\t+steps LONGTEXT NULL,\n\t+note TEXT NULL,\n\t+created_by BIGINT\(20\) UNSIGNED NOT NULL DEFAULT 0,\n\t+created_at DATETIME NOT NULL,\n\t+updated_at DATETIME NOT NULL,\n\t+closed_by BIGINT\(20\) UNSIGNED NOT NULL DEFAULT 0,\n\t+closed_at DATETIME NULL,\n/", $tables['tank_setups'] ) );
check( 'one open setup per slot is a unique key', false !== strpos( $tables['tank_setups'], "PRIMARY KEY  (id),\n" ) && false !== strpos( $tables['tank_setups'], 'UNIQUE KEY open_tank_id (open_tank_id)' ) && false !== strpos( $tables['tank_setups'], 'KEY tank_id (tank_id)' ) );
check( 'fh_tank_logs.setup_id and fh_stay_events.from_setup_id / to_setup_id tie ticks, readings, doses and moves to one setup', 1 === preg_match( "/\n\t+setup_id BIGINT\(20\) UNSIGNED NULL,\n/", $tables['tank_logs'] ) && false !== strpos( $tables['tank_logs'], 'KEY setup_id (setup_id)' ) && 1 === preg_match( "/\n\t+from_setup_id BIGINT\(20\) UNSIGNED NULL,\n\t+to_setup_id BIGINT\(20\) UNSIGNED NULL,\n/", $tables['stay_events'] ) && false !== strpos( $tables['stay_events'], 'KEY to_setup_id (to_setup_id)' ) );

$all_in_schema = true;
foreach ( Store::REQUIRED_COLUMNS as $table => $columns ) {
	foreach ( $columns as $column ) {
		$all_in_schema = $all_in_schema && 1 === preg_match( "/\n\t+" . preg_quote( $column, '/' ) . ' [A-Z]/', $tables[ $table ] );
	}
}
check( 'every column the gate waits for is in the schema of its table', $all_in_schema && array( 'own_water' ) === Store::REQUIRED_COLUMNS['buildings'] && array( 'setup_id' ) === Store::REQUIRED_COLUMNS['tank_logs'] && array( 'from_setup_id', 'to_setup_id' ) === Store::REQUIRED_COLUMNS['stay_events'] );

// The columns each table really has at every earlier version (the ones the gate looks at).
$base = array(
	'orders'      => array( 'id', 'ref', 'supplier', 'origin', 'order_date', 'received_date', 'sale_date', 'status', 'boxes', 'cost_mode', 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'markup', 'worth_threshold', 'schedule', 'building_id', 'notes', 'created_at', 'updated_at' ),
	'order_lines' => array( 'id', 'order_id', 'species_id', 'species', 'sci_name', 'qty_ordered', 'qty_received', 'per_box', 'bags', 'unit_price', 'worth', 'qty_doa', 'qty_survived', 'landed_total', 'stay_id', 'tank_id', 'sort', 'notes' ),
	'stays'       => array( 'id', 'product_id', 'species', 'qty_arrived', 'qty_live', 'checkin_date', 'cleared_date', 'sale_ready_date', 'closed_date', 'status', 'tank_id', 'source', 'order_line_id', 'supplier', 'order_ref', 'landed_cost', 'notes', 'created_at', 'updated_at' ),
	'buildings'   => array( 'id', 'area', 'number', 'name', 'status', 'sort', 'notes', 'schedule', 'system_gallons' ),
	'tank_logs'   => array( 'id', 'tank_id', 'kind', 'value', 'note', 'logged_at', 'actor_id', 'source', 'created_at', 'building_id' ),
	'stay_events' => array( 'id', 'stay_id', 'type', 'qty', 'from_tank_id', 'to_tank_id', 'note', 'event_at', 'actor_id', 'source', 'created_at' ),
);
$v13  = $base;
$v14  = $base;
$v14['order_lines'] = array_merge( $v14['order_lines'], array( 'size_slug', 'size_label', 'box_no', 'box_pct', 'share_key' ) );
$v14['stays']       = array_merge( $v14['stays'], array( 'size_slug', 'size_label' ) );
$v15  = $v14;
$v15['order_lines'] = array_merge( $v15['order_lines'], array( 'needs_id', 'label_name' ) );
$v15['stays'][]     = 'needs_id';
$new  = array( 'buildings.own_water', 'tank_logs.setup_id', 'stay_events.from_setup_id', 'stay_events.to_setup_id' );
check( 'from 1.5 the gate waits for exactly the four new columns', $new === Store::missing_columns( $v15 ) && ! Store::schema_ready( $v15 ) );
check( 'from 1.4: those, and what 1.5 added', array_merge( array( 'order_lines.needs_id', 'order_lines.label_name', 'stays.needs_id' ), $new ) === Store::missing_columns( $v14 ) && ! Store::schema_ready( $v14 ) );
check( 'from 1.3: those, and what 1.4 added', array_merge( array( 'order_lines.size_slug', 'order_lines.size_label', 'order_lines.box_no', 'order_lines.box_pct', 'order_lines.share_key', 'order_lines.needs_id', 'order_lines.label_name', 'stays.size_slug', 'stays.size_label', 'stays.needs_id' ), $new ) === Store::missing_columns( $v13 ) && ! Store::schema_ready( $v13 ) );
$v16                  = $v15;
$v16['buildings'][]   = 'own_water';
$v16['tank_logs'][]   = 'setup_id';
$v16['stay_events'][] = 'from_setup_id';
check( 'one column short (a failed ALTER) keeps it shut: the version is not written, the ALTER is retried', array( 'stay_events.to_setup_id' ) === Store::missing_columns( $v16 ) && ! Store::schema_ready( $v16 ) );
$v16['stay_events'][] = 'to_setup_id';
check( 'with every column there it opens', array() === Store::missing_columns( $v16 ) && Store::schema_ready( $v16 ) );
check( 'but not while the area column cannot hold "temp"', array( 'buildings.area=temp' ) === Store::missing_enum_values( array( 'buildings.area' => "enum('clean','spa')" ) ) && ! Store::schema_ready( $v16, array( 'buildings.area' => "enum('clean','spa')" ) ) && array( 'buildings.area=temp' ) === Store::missing_enum_values( array() ) );
check( 'once it can, the gate opens (the type compares whatever its case)', array() === Store::missing_enum_values( array( 'buildings.area' => "ENUM('clean','spa','temp')" ) ) && Store::schema_ready( $v16, array( 'buildings.area' => "enum('clean','spa','temp')" ) ) );

/* ------------------------------------------------------------------ */
echo "10. A site that never sets one up\n";

// The same morning in a layout without the Temporary building and in one with it (nothing set up).
$build = static function ( $temp ) use ( $today, $settings ) {
	$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Test Clownfish' ), $temp );
	act( $store, 521, 'arrival', array( 'product_id' => 12, 'qty' => 4, 'date' => Replay::add_days( $today, -1 ) ) );
	act( $store, 522, 'arrival', array( 'product_id' => 13, 'qty' => 2, 'date' => Replay::add_days( $today, -7 ) ) );
	act( $store, 611, 'arrival', array( 'species' => 'Test Goby', 'qty' => 1 ) );
	act( $store, 522, 'move', array( 'to_tank' => 121, 'stay_id' => 2 ) );
	act( $store, 521, 'copper', array( 'value' => '2.4' ) );
	act( $store, 521, 'dose', array( 'medication' => 'metro', 'amount' => '5.1', 'unit' => 'g', 'whole' => '1' ) );
	act( $store, 611, 'tick', array( 'key' => 'feed', 'kind' => 'feed', 'label' => 'Feed' ) );
	return $store;
};
$plain = $build( false );
$with  = $build( true );
$strip = static function ( array $room ) {
	$out = array();
	foreach ( $room['tanks'] as $tank ) {
		if ( ! empty( $tank['own_water'] ) ) {
			continue;
		}
		$out[ $tank['number'] ] = array(
			'todo'   => $tank['todo'],
			'copper' => $tank['copper'] ? $tank['copper']['value'] : null,
			'shares' => $tank['shares_with'],
			'in_qt'  => $tank['in_qt'],
			'area'   => $tank['effective_area'],
			'fish'   => array_column( $tank['occupants'], 'qty_live' ),
			'state'  => array_column( $tank['occupants'], 'status' ),
		);
	}
	return $out;
};
$room_plain = $plain->room( $today, $settings, $protocols );
$room_with  = $with->room( $today, $settings, $protocols );
check( 'every rack tank reads the same: to-do, copper, shared water, occupants, cleared', $strip( $room_plain ) === $strip( $room_with ) && 22 === count( $strip( $room_with ) ) );
check( 'the day\'s list is the same list', $room_plain['todo'] === $room_with['todo'] && array( 'system', 'system', 'system' ) === array_column( $room_with['todo']['blocks'], 'type' ) );
check( 'the check-in chooser and the voice parser\'s tanks do not know the free slots', Front_Desk::spa_tanks( $room_plain['tanks'] ) === Front_Desk::spa_tanks( $room_with['tanks'] ) );
check( 'the stays read the same', array_column( $plain->open_stays( array(), $today, $settings ), 'tank_path', 'id' ) === array_column( $with->open_stays( array(), $today, $settings ), 'tank_path', 'id' ) && array_column( $plain->open_stays( array(), $today, $settings ), 'cleared_date', 'id' ) === array_column( $with->open_stays( array(), $today, $settings ), 'cleared_date', 'id' ) );
check( 'the events carry no setup', array( null ) === array_values( array_unique( array_column( $with->events, 'to_setup_id' ) ) ) && array( null ) === array_values( array_unique( array_column( $with->logs, 'setup_id' ) ) ) );
check( 'the Board\'s counts do not count the free slots', Admin::totals( $room_plain['tanks'] ) === Admin::totals( $room_with['tanks'] ) && 22 === Admin::totals( $room_with['tanks'] )['tanks'] );
$with_setup = set_up( $with, array( 'kind' => 'clean', 'name' => 'Holding' ) );
check( 'a tank that is set up counts as a tank', 23 === Admin::totals( $with->room( $today, $settings, $protocols )['tanks'] )['tanks'] && 1 === count( array_filter( $with->room( $today, $settings, $protocols )['todo']['blocks'], static function ( $b ) { return 'temp' === $b['type']; } ) ) );

fh_finish();
