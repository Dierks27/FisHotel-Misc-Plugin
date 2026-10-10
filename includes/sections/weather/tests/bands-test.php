<?php
/**
 * Band classifier tests — the skill's chart, high by high.
 *
 * Run from the plugin root:
 *   php includes/sections/weather/tests/bands-test.php
 *
 * The decision that costs fish when wrong: which packing action a
 * delivery-day HIGH maps to, and the exact note ending for it.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Weather\Bands;

echo "1. Defaults are the skill's chart\n";
check( 'thresholds default to 30/40/70/80/90/95', array( 30, 40, 70, 80, 90, 95 ) === array_values( Bands::DEFAULT_THRESHOLDS ) );
check( 'normalise_thresholds() accepts the defaults unchanged', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( Bands::DEFAULT_THRESHOLDS ) );

echo "2. The highs from the task, action and note ending\n";
$expected = array(
	25 => array( 'hold_cold', 'HOLD: below 30°F — wait to ship.', false ),
	30 => array( 'hold_cold', 'AT THE LINE (30°F) — Jeff\'s call: HOLD (below 30°F) or UPS STORE (30-40°F).', true ),
	35 => array( 'ups_store_cold', 'UPS STORE (30-40°F): ship to local UPS Store for customer pickup. Email drafted for customer.', false ),
	40 => array( 'ups_store_cold', 'AT THE LINE (40°F) — Jeff\'s call: UPS STORE (30-40°F) or Heat pack (40-70°F).', true ),
	41 => array( 'heat_pack', 'Heat pack.', false ),
	70 => array( 'heat_pack', 'AT THE LINE (70°F) — Jeff\'s call: Heat pack (40-70°F) or Nothing needed (70-80°F).', true ),
	89 => array( 'cooling', 'Cooling packs.', false ),
	90 => array( 'ups_store_hot', 'AT THE LINE (90°F) — Jeff\'s call: Cooling packs (80-90°F) or UPS STORE (90-95°F).', true ),
	95 => array( 'hold_hot', 'AT THE LINE (95°F) — Jeff\'s call: UPS STORE (90-95°F) or HOLD (above 95°F).', true ),
	96 => array( 'hold_hot', 'HOLD: above 95°F — no ship.', false ),
);

foreach ( $expected as $high => $exp ) {
	$band = Bands::classify( $high );
	check( "{$high}°F → {$exp[0]}", $exp[0] === $band['action'], 'got ' . $band['action'] );
	check( "{$high}°F ending matches the skill", $exp[1] === $band['ending'], "got: {$band['ending']}" );
	check( "{$high}°F at_line is " . ( $exp[2] ? 'true' : 'false' ), $exp[2] === $band['at_line'] );
}

echo "3. The rest of the bands\n";
check( '76°F → none (the skill example)', 'none' === Bands::classify( 76 )['action'] );
check( '76°F ending', 'Nothing needed — great shipping conditions.' === Bands::classify( 76 )['ending'] );
check( '80°F is on the line, stored as cooling (more cautious)', 'cooling' === Bands::classify( 80 )['action'] && Bands::classify( 80 )['at_line'] );
check( '80°F flag names both bands', 'AT THE LINE (80°F) — Jeff\'s call: Nothing needed (70-80°F) or Cooling packs (80-90°F).' === Bands::classify( 80 )['ending'] );
check( '81°F → cooling', 'cooling' === Bands::classify( 81 )['action'] );
check( '91°F → ups_store_hot', 'ups_store_hot' === Bands::classify( 91 )['action'] );
check( '94°F → ups_store_hot', 'ups_store_hot' === Bands::classify( 94 )['action'] );
check( '-10°F → hold_cold', 'hold_cold' === Bands::classify( -10 )['action'] );
check( '120°F → hold_hot', 'hold_hot' === Bands::classify( 120 )['action'] );

echo "4. On a line, the stored action is the more cautious band\n";
check( 'line at 30 → hold_cold (below) over ups_store_cold (above)', 'hold_cold' === Bands::classify( 30 )['below'] && 'ups_store_cold' === Bands::classify( 30 )['above'] && 'hold_cold' === Bands::classify( 30 )['action'] );
check( 'line at 40 → ups_store_cold over heat_pack', 'ups_store_cold' === Bands::classify( 40 )['action'] );
check( 'line at 70 → heat_pack over none', 'heat_pack' === Bands::classify( 70 )['action'] );
check( 'line at 90 → ups_store_hot over cooling', 'ups_store_hot' === Bands::classify( 90 )['action'] );
check( 'line at 95 → hold_hot over ups_store_hot', 'hold_hot' === Bands::classify( 95 )['action'] );
check( 'more_cautious() is symmetric', 'hold_hot' === Bands::more_cautious( 'hold_hot', 'ups_store_hot' ) && 'hold_hot' === Bands::more_cautious( 'ups_store_hot', 'hold_hot' ) );

echo "5. Input coercion\n";
check( 'a numeric string classifies', 'heat_pack' === Bands::classify( '55' )['action'] );
check( '69.6 rounds to 70 (on the line)', Bands::classify( 69.6 )['at_line'] && 70 === Bands::classify( 69.6 )['high'] );
check( '69.4 rounds to 69 (heat pack)', 'heat_pack' === Bands::classify( 69.4 )['action'] );
check( 'null → unavailable, no ending', 'unavailable' === Bands::classify( null )['action'] && '' === Bands::classify( null )['ending'] );
check( 'a non-numeric high → unavailable', 'unavailable' === Bands::classify( 'warm' )['action'] );
check( 'unavailable is not a band', ! Bands::is_band( 'unavailable' ) && Bands::is_band( 'heat_pack' ) );

echo "6. Custom thresholds\n";
$custom = array(
	'cold_hold_below' => 32,
	'cold_ups_to'     => 45,
	'heat_pack_to'    => 65,
	'nothing_to'      => 78,
	'cooling_to'      => 88,
	'hot_ups_to'      => 93,
);
check( 'custom chart moves the bands', 'heat_pack' === Bands::classify( 46, $custom )['action'] && 'ups_store_cold' === Bands::classify( 40, $custom )['action'] );
check( 'custom chart numbers appear in the endings', 'HOLD: below 32°F — wait to ship.' === Bands::classify( 20, $custom )['ending'] );
check( 'custom chart numbers appear in the UPS ending', 'UPS STORE (88-93°F): ship to local UPS Store for customer pickup. Email drafted for customer.' === Bands::classify( 90, $custom )['ending'] );
check( 'thresholds_valid() accepts a strictly increasing set', Bands::thresholds_valid( $custom ) );

echo "7. Broken thresholds fall back to the defaults wholesale\n";
$bad = array_merge( Bands::DEFAULT_THRESHOLDS, array( 'heat_pack_to' => 85 ) ); // 85 > nothing_to 80.
check( 'a non-increasing set is rejected', ! Bands::thresholds_valid( $bad ) );
check( '…and classify() then uses the defaults', 'cooling' === Bands::classify( 85, $bad )['action'] );
check( 'a missing key is rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( array( 'cold_hold_below' => 30 ) ) );
check( 'a blank value is rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( array_merge( Bands::DEFAULT_THRESHOLDS, array( 'cooling_to' => '' ) ) ) );
check( 'a non-numeric value is rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( array_merge( Bands::DEFAULT_THRESHOLDS, array( 'cooling_to' => 'ninety' ) ) ) );
check( 'an out-of-range value is rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( array_merge( Bands::DEFAULT_THRESHOLDS, array( 'hot_ups_to' => 500 ) ) ) );
check( 'equal neighbours are rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( array_merge( Bands::DEFAULT_THRESHOLDS, array( 'cold_ups_to' => 30 ) ) ) );
check( 'a non-array is rejected', Bands::DEFAULT_THRESHOLDS === Bands::normalise_thresholds( 'nope' ) );
check( 'string numbers are accepted', array( 30, 40, 70, 80, 90, 95 ) === array_values( Bands::normalise_thresholds( array_map( 'strval', Bands::DEFAULT_THRESHOLDS ) ) ) );

echo "8. Helpers\n";
check( 'is_ups_store()', Bands::is_ups_store( 'ups_store_cold' ) && Bands::is_ups_store( 'ups_store_hot' ) && ! Bands::is_ups_store( 'cooling' ) );
check( 'is_hold()', Bands::is_hold( 'hold_cold' ) && Bands::is_hold( 'hold_hot' ) && ! Bands::is_hold( 'heat_pack' ) );
check( 'is_cold()', Bands::is_cold( 'heat_pack' ) && Bands::is_cold( 'hold_cold' ) && ! Bands::is_cold( 'none' ) && ! Bands::is_cold( 'cooling' ) );
check( 'is_extreme() is UPS Store or hold', Bands::is_extreme( 'hold_hot' ) && Bands::is_extreme( 'ups_store_cold' ) && ! Bands::is_extreme( 'cooling' ) && ! Bands::is_extreme( 'heat_pack' ) );
check( 'every action has a short label', count( array_filter( array_map( array( Bands::class, 'short_label' ), Bands::actions() ), 'strlen' ) ) === count( Bands::actions() ) );
check( 'band_label() for unavailable', 'Weather unavailable' === Bands::band_label( 'unavailable' ) );

fh_finish();
