/**
 * Dose maths JS — js/dose-math.js against fixtures/dose-maths.json, the
 * same file tests/dose-test.php runs the server's Dose class against: the
 * rounding (one decimal from 1 up, two below, half up to the last digit),
 * the sensible unit of a weight, the words (levels, rates, a measure's
 * plural) and every sum — per gallon, to a level, a named dose, a
 * schedule step's rate, the top-up after a water change, zero and
 * negative differences, unknown gallons, typed — with its amount, result
 * line, working and note.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/dose-math.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var math    = require( path.join( __dirname, '..', 'js', 'dose-math.js' ) );
var fixture = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'dose-maths.json' ), 'utf8' ) );

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

function same( a, b ) {
	if ( typeof a === 'number' && typeof b === 'number' ) {
		return Math.abs( a - b ) < 1e-9;
	}
	return a === b;
}

console.log( '1. Exports' );
check( 'the maths is exported for node and hung on the global for the pages', typeof math.plan === 'function' && globalThis.fhDoseMath && globalThis.fhDoseMath.plan === math.plan );
check( 'every word of the sum has an English fallback', Object.keys( math.STRINGS ).length === 16 && Object.keys( math.STRINGS ).every( function ( k ) { return typeof math.STRINGS[ k ] === 'string' && math.STRINGS[ k ] !== ''; } ) );

console.log( '2. Rounding and units (the fixture the server reads too)' );
fixture.round_amount.forEach( function ( row ) {
	check( 'roundAmount(' + row[0] + ') = ' + row[1], same( math.roundAmount( row[0] ), row[1] ), String( math.roundAmount( row[0] ) ) );
} );
fixture.display.forEach( function ( row ) {
	var shown = math.display( row[0], row[1] );
	check( 'display(' + row[0] + ' ' + row[1] + ') = ' + row[2] + ' ' + row[3], same( shown.amount, row[2] ) && shown.unit === row[3], JSON.stringify( shown ) );
} );
check( 'convert(): mg and g go together, nothing else does', same( math.convert( 2.5, 'g', 'mg' ), 2500 ) && same( math.convert( 250, 'mg', 'g' ), 0.25 ) && same( math.convert( 3, 'ml', 'ml' ), 3 ) && null === math.convert( 1, 'ml', 'g' ) && null === math.convert( 1, 'tsp', 'ml' ) && math.compatible( 'g', 'mg' ) && ! math.compatible( 'drops', 'ml' ) );

console.log( '3. Words' );
fixture.level_text.forEach( function ( row ) {
	check( 'levelText(' + row[0] + ') = "' + row[1] + '"', math.levelText( row[0] ) === row[1], math.levelText( row[0] ) );
} );
fixture.num.forEach( function ( row ) {
	check( 'num(' + row[0] + ', ' + row[1] + ') = "' + row[2] + '"', math.num( row[0], row[1] ) === row[2], math.num( row[0], row[1] ) );
} );
fixture.rate_text.forEach( function ( row ) {
	check( 'rateText(' + row[0] + ' ' + row[1] + ' per ' + row[2] + ') = "' + row[3] + '"', math.rateText( row[0], row[1], row[2] ) === row[3], math.rateText( row[0], row[1], row[2] ) );
} );
fixture.plural.forEach( function ( row ) {
	check( 'plural("' + row[0] + '", ' + row[1] + ') = "' + row[2] + '"', math.plural( row[0], row[1] ) === row[2], math.plural( row[0], row[1] ) );
} );
check( 'amountText: a unit, or "×" for pieces', '2.5 g' === math.amountText( 2.5, 'g' ) && '4 ×' === math.amountText( 4, 'each' ) && '16.6 ml' === math.amountText( 16.6, 'ml' ) );
check( 'level(): a number from 0 to 999, else null', 2.16 === math.level( '2.16' ) && 0 === math.level( 0 ) && null === math.level( '' ) && null === math.level( null ) && null === math.level( 'abc' ) && null === math.level( -1 ) && null === math.level( 1000 ) && null === math.level( true ) );
check( 'numeric(): plain numbers only', 5 === math.numeric( '5' ) && 0.5 === math.numeric( '.5' ) && 2 === math.numeric( ' 2 ' ) && null === math.numeric( '1e3' ) && null === math.numeric( '5 g' ) && null === math.numeric( undefined ) && null === math.numeric( NaN ) );

console.log( '4. The sums' );
fixture.cases.forEach( function ( c ) {
	var got  = math.plan( fixture.medications[ c.medication ], c.args );
	var keys = [ 'state', 'amount', 'unit', 'line', 'working', 'note', 'measure' ];
	var bad  = keys.filter( function ( k ) { return ! same( got[ k ], c.expect[ k ] ); } );
	check( c.name, 0 === bad.length, bad.map( function ( k ) { return k + ': expected ' + JSON.stringify( c.expect[ k ] ) + ', got ' + JSON.stringify( got[ k ] ); } ).join( '; ' ) );
} );

console.log( '5. The page\'s own spelling of the inputs, and translated words' );
var metro  = fixture.medications.metro;
var copper = fixture.medications.copper;
var quine  = fixture.medications.quine;
var a      = math.plan( quine, { gallons: 100, rate: 40, rateLabel: 'Loading', topup: true, changed: 20, changedUnit: 'gal' } );
check( 'rateLabel / changedUnit (camelCase) read like rate_label / changed_unit', 'ok' === a.state && 800 === a.amount && 'Loading: 40 mg per gallon, 20 gal · top-up after a 20 gal water change' === a.note, JSON.stringify( a ) );
var b = math.plan( { key: 'copper', label: 'Copper Power', unit: 'ml', mode: 'to_level', rate: 0.692, per: 1, levelUnit: 'ppm' }, { gallons: 100, now: 2.16, target: 2.4 } );
check( 'levelUnit (camelCase) reads like level_unit', 'Add 16.6 ml Copper Power' === b.line && '100 gal × 0.24 ppm × 0.692' === b.working, JSON.stringify( b ) );
var c = math.plan( metro, { gallons: 100 }, { add: '%2$s: %1$s', gal: '%s G' } );
check( 'the words passed in are used, the missing ones stay English', 'Metronidazole: 2.5 g' === c.line && '100 G × 25 mg' === c.working && '25 mg per gallon, 100 G' === c.note, JSON.stringify( c ) );
check( 'no medication, no arguments: typed, never a throw', 'typed' === math.plan().state && 'typed' === math.plan( null, null ).state && 'needs_now' === math.plan( copper ).state && 'no_gallons' === math.plan( metro ).state );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
