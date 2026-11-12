/**
 * Medications admin JS — the pure parts of js/medications-admin.js: what
 * 100 gallons take at a rate (the hint beside the editor's rate fields),
 * the rates the Board's Calculator offers, and its sum — the Dose sheet's
 * plan (js/dose-math.js) with "Type the gallons." where there are none.
 *
 * Runs the file without jQuery or a page: it exports its helpers and
 * skips the wiring.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/medications-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'medications-admin.js' ) );

var failures = 0;
var checks   = 0;

function check( label, condition, detail ) {
	checks++;
	if ( condition ) {
		console.log( '  ok   ' + label );
		return;
	}
	failures++;
	console.log( '  FAIL ' + label + ( detail ? '\n       ' + detail : '' ) );
}

var copper = { key: 'copper', label: 'Copper Power', unit: 'ml', mode: 'to_level', rate: 0.692, per: 1, levelUnit: 'ppm', copper: true, doses: [] };
var metro  = { key: 'metro', label: 'Metronidazole', unit: 'mg', mode: 'per_gallon', rate: 25, per: 1, doses: [] };
var quine  = { key: 'quine', label: 'Test Quine', unit: 'mg', mode: 'per_gallon', rate: 10, per: 1, doses: [ { label: 'Loading', rate: 40 } ] };

console.log( '1. Exports' );
check( 'the helpers are exported for node and hung on the global', typeof helpers.hundredHint === 'function' && typeof helpers.calcChoices === 'function' && typeof helpers.calcPlan === 'function' && globalThis.fhCleanRoomDoseHelpers === helpers );

console.log( '2. hundredHint(): what 100 gallons take' );
check( '25 mg per gallon: 2.5 g', '100 gal take 2.5 g' === helpers.hundredHint( 'per_gallon', '25', 'mg', '1' ) );
check( '1 ml per 10 gallons: 10 ml; 1 g per 100 gallons: 1 g; 9.5 mg: 0.95 g', '100 gal take 10 ml' === helpers.hundredHint( 'per_gallon', '1', 'ml', '10' ) && '100 gal take 1 g' === helpers.hundredHint( 'per_gallon', 1, 'g', 100 ) && '100 gal take 0.95 g' === helpers.hundredHint( 'per_gallon', '9.5', 'mg', '' ) );
check( 'to a level: for each 1.0', '100 gal take 69.2 ml for each 1.0' === helpers.hundredHint( 'to_level', '0.692', 'ml', '1' ) );
check( 'pieces and drops', '100 gal take 10 ×' === helpers.hundredHint( 'per_gallon', '1', 'each', '10' ) && '100 gal take 40 drops' === helpers.hundredHint( 'per_gallon', '2', 'drops', '5' ) );
check( 'no amount yet, a bad one, gallons of 0, a typed medication: nothing', '' === helpers.hundredHint( 'per_gallon', '', 'mg', '1' ) && '' === helpers.hundredHint( 'per_gallon', 'abc', 'mg', '1' ) && '' === helpers.hundredHint( 'per_gallon', '0', 'mg', '1' ) && '' === helpers.hundredHint( 'per_gallon', '25', 'mg', '0' ) && '' === helpers.hundredHint( 'typed', '25', 'mg', '1' ) && '' === helpers.hundredHint( 'per_gallon', null, 'mg', undefined ) );
check( 'the page\'s words are used', 'AUF 100: 2.5 g' === helpers.hundredHint( 'per_gallon', '25', 'mg', '1', { on100: 'AUF 100: %s' } ) );

console.log( '3. The Calculator' );
var choices = helpers.calcChoices( quine );
check( 'the rates offered: the medication\'s own, then its named doses', 2 === choices.length && 10 === choices[0].rate && '10 mg per gallon' === choices[0].text && '' === choices[0].name && 40 === choices[1].rate && 'Loading · 40 mg per gallon' === choices[1].text && 'Loading' === choices[1].name );
check( 'one rate, or none for a medication not dosed per gallon', 1 === helpers.calcChoices( metro ).length && 0 === helpers.calcChoices( copper ).length && 0 === helpers.calcChoices( null ).length );
var plan = helpers.calcPlan( metro, { gallons: '100' } );
check( '100 gallons of Metronidazole: the sheet\'s result and working', 'ok' === plan.state && 'Add 2.5 g Metronidazole' === plan.line && '100 gal × 25 mg' === plan.working );
plan = helpers.calcPlan( copper, { gallons: '100', now: '2.16', target: '2.4' } );
check( 'the copper sum: 16.6 ml', 'Add 16.6 ml Copper Power' === plan.line && '100 gal × 0.24 ppm × 0.692' === plan.working );
plan = helpers.calcPlan( quine, { gallons: '100', rate: 40, rateLabel: 'Loading' } );
check( 'a named dose: 4 g', 'Add 4 g Test Quine' === plan.line );
plan = helpers.calcPlan( metro, { gallons: '' }, null, 'Type the gallons.' );
check( 'no gallons: it asks for them', 'no_gallons' === plan.state && 'Type the gallons.' === plan.line && null === plan.amount );
check( 'at the target: nothing to add; no medication: nothing at all', 'Nothing to add' === helpers.calcPlan( copper, { gallons: '100', now: '2.4', target: '2.4' } ).line && null === helpers.calcPlan( null, { gallons: '100' } ) );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
