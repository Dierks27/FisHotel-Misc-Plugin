<?php
/**
 * Layout — the seed obeys the numbering rule, numbers are unique, the
 * shares-water lists and the title / label text are right, the editor's
 * validation enforces the rule.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/layout-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Layout;

$seed = Layout::seed();

echo "1. The seed\n";
check( 'six buildings', 6 === count( $seed ) );
check( 'numbers 100…600 in order', array( 100, 200, 300, 400, 500, 600 ) === array_column( $seed, 'number' ) );
check( 'areas: 100–400 clean, 500/600 spa', array( 'clean', 'clean', 'clean', 'clean', 'spa', 'spa' ) === array_column( $seed, 'area' ) );
check( '400 is coming soon with no tanks', 'coming_soon' === $seed[3]['status'] && array() === $seed[3]['tanks'] );
check( 'every other building is active', 'active' === $seed[0]['status'] && 'active' === $seed[4]['status'] && 'active' === $seed[5]['status'] );

$all     = array();
$numbers = array();
foreach ( $seed as $building ) {
	foreach ( $building['tanks'] as $tank ) {
		$tank['building_number'] = $building['number'];
		$all[]     = $tank;
		$numbers[] = $tank['number'];
	}
}
check( '22 tanks in all (5 + 4 + 4 + 0 + 7 + 2)', 22 === count( $all ), count( $all ) );
check( 'every tank number is unique', count( $numbers ) === count( array_unique( $numbers ) ) );

$rule_holds = true;
foreach ( $all as $tank ) {
	$parts = Layout::parse_number( $tank['number'] );
	if ( ! $parts || $parts['building'] !== $tank['building_number'] || $parts['level'] !== $tank['level'] || $parts['position'] !== $tank['position'] ) {
		$rule_holds = false;
		echo "       rule broken for tank {$tank['number']}\n";
	}
	if ( Layout::tank_number( $tank['building_number'], $tank['level'], $tank['position'] ) !== $tank['number'] ) {
		$rule_holds = false;
	}
}
check( 'hundreds = building, tens = level, ones = position for every seeded tank', $rule_holds );

function fh_numbers_of( array $seed, $building_number ) {
	foreach ( $seed as $building ) {
		if ( $building['number'] === $building_number ) {
			return array_column( $building['tanks'], 'number' );
		}
	}
	return array();
}
check( 'building 100: 121, 122, 123 on top, 111, 112 below', array( 121, 122, 123, 111, 112 ) === fh_numbers_of( $seed, 100 ) );
check( 'building 200 / 300: 2 + 2', array( 221, 222, 211, 212 ) === fh_numbers_of( $seed, 200 ) && array( 321, 322, 311, 312 ) === fh_numbers_of( $seed, 300 ) );
check( 'building 500: 521–524 on top, 511–513 below', array( 521, 522, 523, 524, 511, 512, 513 ) === fh_numbers_of( $seed, 500 ) );
check( 'building 600: 621 top, 611 bottom', array( 621, 611 ) === fh_numbers_of( $seed, 600 ) );

function fh_tank( array $all, $number ) {
	foreach ( $all as $tank ) {
		if ( $tank['number'] === $number ) {
			return $tank;
		}
	}
	return null;
}
check( 'top row of 100 is 20G long / 20 gal', '20G long' === fh_tank( $all, 121 )['size_label'] && 20 === fh_tank( $all, 123 )['gallons'] );
check( 'bottom row of 100 is 40G breeder / 40 gal', '40G breeder' === fh_tank( $all, 111 )['size_label'] && 40 === fh_tank( $all, 112 )['gallons'] );
check( 'spa: 52x are 20G long, 51x are 40G breeder, both 6xx are 40G breeder', '20G long' === fh_tank( $all, 524 )['size_label'] && '40G breeder' === fh_tank( $all, 513 )['size_label'] && '40G breeder' === fh_tank( $all, 621 )['size_label'] && '40G breeder' === fh_tank( $all, 611 )['size_label'] );
check( 'every seeded tank is active', 22 === count( array_filter( $all, static function ( $t ) { return 'active' === $t['status']; } ) ) );

echo "2. The numbering rule\n";
check( 'tank_number(100, 2, 1) = 121', 121 === Layout::tank_number( 100, 2, 1 ) );
check( 'tank_number(500, 1, 3) = 513', 513 === Layout::tank_number( 500, 1, 3 ) );
check( 'a building number is rounded down to its hundred', 221 === Layout::tank_number( 250, 2, 1 ) );
check( 'parse_number(121)', array( 'building' => 100, 'level' => 2, 'position' => 1 ) === Layout::parse_number( 121 ) );
check( 'parse_number(513)', array( 'building' => 500, 'level' => 1, 'position' => 3 ) === Layout::parse_number( '513' ) );
check( 'level 3 breaks the rule', null === Layout::parse_number( 131 ) );
check( 'level 0 breaks the rule', null === Layout::parse_number( 101 ) );
check( 'position 0 breaks the rule', null === Layout::parse_number( 120 ) );
check( 'two digits / garbage / four digits → null', null === Layout::parse_number( 99 ) && null === Layout::parse_number( 'abc' ) && null === Layout::parse_number( 1211 ) );
check( 'number_fits', Layout::number_fits( 121, 100 ) && ! Layout::number_fits( 121, 200 ) && ! Layout::number_fits( 130, 100 ) );

echo "3. Shares water (one sump per building)\n";
$tanks = fh_seed_tanks();
check( '121 shares with 111, 112, 122, 123', array( 111, 112, 122, 123 ) === Layout::shares_water( array( 'number' => 121 ), $tanks ) );
check( '611 shares with 621 only', array( 621 ) === Layout::shares_water( array( 'number' => 611 ), $tanks ) );
check( '513 shares with the other six in 500', array( 511, 512, 521, 522, 523, 524 ) === Layout::shares_water( array( 'number' => 513 ), $tanks ) );
$with_offline = $tanks;
foreach ( $with_offline as &$t ) {
	if ( 122 === $t['number'] ) {
		$t['status'] = 'offline';
	}
}
unset( $t );
check( 'an offline tank is dry and left out', array( 111, 112, 123 ) === Layout::shares_water( array( 'number' => 121 ), $with_offline ) );
check( 'by building id when both carry one', Layout::same_building( array( 'building_id' => 3, 'number' => 999 ), array( 'building_id' => 3, 'number' => 111 ) ) && ! Layout::same_building( array( 'building_id' => 3, 'number' => 121 ), array( 'building_id' => 4, 'number' => 122 ) ) );

echo "4. Text\n";
$b100 = array( 'area' => 'clean', 'number' => 100, 'name' => '' );
$t121 = array( 'number' => 121, 'size_label' => '20G long' );
check( 'the phone title', 'Tank 121 · Clean Room · Building 100 · 20G long · shares water with 111, 112, 122, 123' === Layout::tank_title( $t121, $b100, array( 111, 112, 122, 123 ) ), Layout::tank_title( $t121, $b100, array( 111, 112, 122, 123 ) ) );
check( 'a named building uses its name', 'Tank 121 · Clean Room · Big rack · 20G long · shares water with 111' === Layout::tank_title( $t121, array_merge( $b100, array( 'name' => 'Big rack' ) ), array( 111 ) ) );
check( 'a lone tank has its own water', 'Tank 621 · Spa · Building 600 · 40G breeder · its own water' === Layout::tank_title( array( 'number' => 621, 'size_label' => '40G breeder' ), array( 'area' => 'spa', 'number' => 600, 'name' => '' ), array() ) );
check( 'no size label → no size part', 'Tank 121 · Clean Room · Building 100 · its own water' === Layout::tank_title( array( 'number' => 121 ), $b100, array() ) );
check( 'the path the fish page shows', 'Clean Room · Building 100 · Tank 121' === Layout::tank_path( $t121, $b100 ) );
check( 'spa path', 'Spa · Building 500 · Tank 521' === Layout::tank_path( array( 'number' => 521 ), array( 'area' => 'spa', 'number' => 500 ) ) );
check( 'the printed label', array( 'number' => '121', 'line' => 'Clean Room · Building 100 · 20G long' ) === Layout::label( $t121, $b100 ) );
check( 'area labels', 'Clean Room' === Layout::area_label( 'clean' ) && 'Spa' === Layout::area_label( 'spa' ) && 'Other' === Layout::area_label( 'other' ) );
check( 'building label', 'Building 300' === Layout::building_label( array( 'number' => 300, 'name' => '' ) ) && 'Sump A' === Layout::building_label( array( 'number' => 300, 'name' => 'Sump A' ) ) );

echo "5. Validation (the editor enforces the rule)\n";
check( 'a good building', array() === Layout::validate_building( array( 'area' => 'clean', 'number' => 700, 'name' => '', 'status' => 'active' ), array( 100, 200 ) ) );
check( '150 is not a round hundred', 1 === count( Layout::validate_building( array( 'area' => 'clean', 'number' => 150, 'status' => 'active' ) ) ) );
check( 'a taken number', in_array( 'building number 100 is already used', Layout::validate_building( array( 'area' => 'clean', 'number' => 100, 'status' => 'active' ), array( 100 ) ), true ) );
check( 'unknown area / status / long name', 3 === count( Layout::validate_building( array( 'area' => 'lab', 'number' => 700, 'status' => 'wet', 'name' => str_repeat( 'x', 61 ) ) ) ) );
check( 'a good tank', array() === Layout::validate_tank( array( 'building_number' => 100, 'level' => 2, 'position' => 4, 'size_label' => '10G', 'gallons' => 10, 'status' => 'active' ), array( 121, 122, 123 ) ) );
check( 'level 3 is refused', in_array( 'level must be 1 (bottom) or 2 (top)', Layout::validate_tank( array( 'building_number' => 100, 'level' => 3, 'position' => 1 ) ), true ) );
check( 'position 0 / 10 refused', 1 === count( Layout::validate_tank( array( 'building_number' => 100, 'level' => 1, 'position' => 0 ) ) ) && 1 === count( Layout::validate_tank( array( 'building_number' => 100, 'level' => 1, 'position' => 10 ) ) ) );
check( 'a duplicate derived number is refused by name', in_array( 'tank 121 already exists', Layout::validate_tank( array( 'building_number' => 100, 'level' => 2, 'position' => 1 ), array( 121 ) ), true ) );
check( 'no building → refused', in_array( 'tank needs a building', Layout::validate_tank( array( 'level' => 2, 'position' => 1 ) ), true ) );
check( 'gallons out of range', in_array( 'gallons must be 0–9999', Layout::validate_tank( array( 'building_number' => 100, 'level' => 2, 'position' => 5, 'gallons' => -1 ) ), true ) );

echo "6. Ordering, grouping, positions\n";
$sorted = Layout::sort_tanks( array( array( 'number' => 111, 'level' => 1, 'sort' => 4 ), array( 'number' => 123, 'level' => 2, 'sort' => 3 ), array( 'number' => 121, 'level' => 2, 'sort' => 1 ) ) );
check( 'top row first, then by sort', array( 121, 123, 111 ) === array_column( $sorted, 'number' ) );
$buildings = array();
foreach ( $seed as $i => $building ) {
	$buildings[] = array_merge( $building, array( 'id' => $i + 1 ) );
}
$groups = Layout::group( $buildings, $tanks );
check( 'areas in order: clean then spa', array( 'clean', 'spa' ) === array_keys( $groups ) );
check( 'four clean buildings, two spa', 4 === count( $groups['clean']['buildings'] ) && 2 === count( $groups['spa']['buildings'] ) );
check( 'building 400 is present with empty rows', 400 === $groups['clean']['buildings'][3]['building']['number'] && array() === $groups['clean']['buildings'][3]['levels'][2] && array() === $groups['clean']['buildings'][3]['levels'][1] );
check( 'building 100: top row 121-123, bottom 111-112', array( 121, 122, 123 ) === array_column( $groups['clean']['buildings'][0]['levels'][2], 'number' ) && array( 111, 112 ) === array_column( $groups['clean']['buildings'][0]['levels'][1], 'number' ) );
$b500 = array_filter( $tanks, static function ( $t ) { return 500 === $t['building']; } );
check( 'top left of 500 is 521, top right 524, bottom middle 512', 521 === Layout::tank_at( $b500, 2, 'left' )['number'] && 524 === Layout::tank_at( $b500, 2, 'right' )['number'] && 512 === Layout::tank_at( $b500, 1, 'middle' )['number'] );
check( 'top second / third', 522 === Layout::tank_at( $b500, 2, 'second' )['number'] && 523 === Layout::tank_at( $b500, 2, '3' )['number'] );
check( 'no middle in an even row', null === Layout::tank_at( $b500, 2, 'middle' ) );
check( 'out of range / no position → null', null === Layout::tank_at( $b500, 2, 'fifth' ) && null === Layout::tank_at( $b500, 2, '' ) && null === Layout::tank_at( array(), 2, 'left' ) );

echo "7. The schedule and the system gallons of a building\n";
check( 'the seed: 205 gal for building 500, 100 for 600 (Jeff\'s figures), nothing for the Clean Room buildings; no schedule yet', 205 === $seed[4]['system_gallons'] && 100 === $seed[5]['system_gallons'] && null === $seed[0]['system_gallons'] && null === $seed[3]['system_gallons'] && array( '', '', '', '', '', '' ) === array_column( $seed, 'schedule' ) && 205 === Layout::SPA_500_GALLONS && 100 === Layout::SPA_600_GALLONS );
$b500_row = array( 'id' => 5, 'number' => 500, 'system_gallons' => 205 );
$b100_row = array( 'id' => 1, 'number' => 100, 'system_gallons' => null );
check( 'system_gallons: the typed figure wins over the tanks', 205 === Layout::system_gallons( $b500_row, $tanks ) );
$sized = fh_seed_tanks();
foreach ( $sized as &$t ) {
	$t['gallons'] = 2 === (int) $t['level'] ? 20 : 40;
}
unset( $t );
check( 'blank: the sum of the building\'s tanks (100: 3 × 20 + 2 × 40 = 140), by building id', 140 === Layout::system_gallons( $b100_row, $sized ) );
check( 'by the hundreds of the numbers when no ids are given', 140 === Layout::system_gallons( array( 'number' => 100 ), array_map( static function ( $t ) { unset( $t['building_id'] ); return $t; }, $sized ) ) );
$one_off = $sized;
foreach ( $one_off as &$t ) {
	if ( 121 === (int) $t['number'] ) {
		$t['status'] = 'offline';
	}
}
unset( $t );
check( 'an offline tank is dry: 120', 120 === Layout::system_gallons( $b100_row, $one_off ) );
check( 'a zero is "blank" too; nothing known → 0', 140 === Layout::system_gallons( array( 'id' => 1, 'number' => 100, 'system_gallons' => 0 ), $sized ) && 0 === Layout::system_gallons( array( 'id' => 9, 'number' => 900 ), $sized ) );
check( 'system_label', '205 gal system' === Layout::system_label( 205 ) && '' === Layout::system_label( 0 ) );
check( 'validate_building: a schedule name of 40 characters and gallons 0–9999 pass; blank gallons pass', array() === Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'schedule' => str_repeat( 'x', 40 ), 'system_gallons' => 9999 ) ) && array() === Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'schedule' => '', 'system_gallons' => null ) ) );
check( 'validate_building: a 41-character schedule name, negative / huge / junk gallons are refused', in_array( 'schedule name is longer than 40 characters', Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'schedule' => str_repeat( 'x', 41 ) ) ), true ) && in_array( 'system gallons must be blank or 0–9999', Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'system_gallons' => -1 ) ), true ) && 1 === count( Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'system_gallons' => 10000 ) ) ) && 1 === count( Layout::validate_building( array( 'area' => 'spa', 'number' => 700, 'status' => 'active', 'system_gallons' => 'lots' ) ) ) );

fh_finish();
