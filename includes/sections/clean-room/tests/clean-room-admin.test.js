/**
 * Clean Room admin JS — the pure client helpers: the input-name
 * renumbering (steps / lines / rates, a template's __i__), the
 * import-fees suggestion that mirrors Settings::suggest_import_fees(),
 * and the percent-of-a-box maths of the order page (a typed percent and
 * box number, the per-box summary and its states, the remembered share
 * of a line, its suggestion, "How full") — run against the same
 * fixtures/box-maths.json the PHP tests read, so the page recalculates
 * exactly what the server does.
 *
 * Runs js/clean-room-admin.js without a DOM or jQuery: the file exports
 * its helpers and skips the page code when there is no jQuery.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/clean-room-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var helpers = require( path.join( __dirname, '..', 'js', 'clean-room-admin.js' ) );
var fixture = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'box-maths.json' ), 'utf8' ) );

/**
 * Deep equality: numbers within a hair, everything else strictly.
 */
function same( a, b ) {
	if ( typeof a === 'number' && typeof b === 'number' ) {
		return Math.abs( a - b ) < 1e-9;
	}
	if ( a === null || b === null || typeof a !== 'object' || typeof b !== 'object' ) {
		return a === b;
	}
	if ( Array.isArray( a ) !== Array.isArray( b ) ) {
		return false;
	}
	var ka = Object.keys( a ).sort();
	var kb = Object.keys( b ).sort();
	if ( ka.length !== kb.length ) {
		return false;
	}
	return ka.every( function ( key, i ) {
		return key === kb[ i ] && same( a[ key ], b[ key ] );
	} );
}

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

console.log( '1. Exports' );
check( 'helpers are exported for node', helpers && typeof helpers.renumber === 'function' && typeof helpers.suggestImportFees === 'function' );
check( 'helpers are also hung on the global for the browser', globalThis.fhCleanRoomAdmin && globalThis.fhCleanRoomAdmin.helpers.renumber === helpers.renumber );

console.log( '2. renumber()' );
check( 'steps by default', JSON.stringify( [ 'steps[3][label]', 'steps[3][day]' ] ) === JSON.stringify( helpers.renumber( [ 'steps[7][label]', 'steps[0][day]' ], 3 ) ) );
check( 'lines with the prefix, a template row\'s __i__ included', JSON.stringify( [ 'lines[2][species]', 'lines[2][qty_ordered]' ] ) === JSON.stringify( helpers.renumber( [ 'lines[__i__][species]', 'lines[9][qty_ordered]' ], 2, 'lines' ) ) );
check( 'rates', 'rates[0][per_box]' === helpers.renumber( [ 'rates[5][per_box]' ], 0, 'rates' )[0] );
check( 'other names are left alone', 'order_id' === helpers.renumber( [ 'order_id' ], 4, 'lines' )[0] && 'steps[1][x]' === helpers.renumber( [ 'steps[1][x]' ], 4, 'lines' )[0] );
check( 'no names → empty', JSON.stringify( [] ) === JSON.stringify( helpers.renumber( null, 1 ) ) );

console.log( '3. suggestImportFees()' );
var rates = [
	{ supplier: 'Test Supplier', origin: 'Testland', per_box: '10', reox: '2.5' },
	{ supplier: 'Test Supplier', origin: '', per_box: 7, reox: 1 },
	{ supplier: 'Other Supplier', origin: 'Testland', per_box: 100, reox: 0 }
];
check( 'the exact pair, case-insensitively: 3 × (10 + 2.5) = 37.5', 37.5 === helpers.suggestImportFees( rates, ' test SUPPLIER ', 'testland', '3' ) );
check( 'an unknown origin falls back to the supplier\'s origin-less rate: 2 × 8 = 16', 16 === helpers.suggestImportFees( rates, 'Test Supplier', 'Elsewhere', 2 ) );
check( 'an unknown supplier → null', null === helpers.suggestImportFees( rates, 'Nobody', 'Testland', 2 ) );
check( 'no boxes → null', null === helpers.suggestImportFees( rates, 'Test Supplier', 'Testland', '' ) && null === helpers.suggestImportFees( rates, 'Test Supplier', 'Testland', 0 ) );
check( 'no rates → null', null === helpers.suggestImportFees( [], 'Test Supplier', 'Testland', 2 ) && null === helpers.suggestImportFees( null, 'x', 'y', 1 ) );
check( 'rounded to cents', 0.3 === helpers.suggestImportFees( [ { supplier: 'a', origin: 'b', per_box: 0.1, reox: 0.2 } ], 'a', 'b', 1 ) );
check( 'junk money reads as 0', 4 === helpers.suggestImportFees( [ { supplier: 'a', origin: '', per_box: 'x', reox: 2 } ], 'a', '', 2 ) );

console.log( '4. Percent of a box: what a typed percent and box number read as' );
check( 'the box helpers are exported', [ 'fmt', 'num', 'boxPct', 'boxNo', 'boxState', 'boxSummary', 'boxSummaryRows', 'boxTotalText', 'findShare', 'suggestPct', 'suggestFor', 'suggestText', 'howFull', 'howFullText' ].every( function ( name ) { return typeof helpers[ name ] === 'function'; } ) );
fixture.pct.forEach( function ( row ) {
	check( 'boxPct( ' + JSON.stringify( row[0] ) + ' ) → ' + JSON.stringify( row[1] ), same( row[1], helpers.boxPct( row[0] ) ), JSON.stringify( helpers.boxPct( row[0] ) ) );
} );
check( 'boxPct: a boolean, an object and nothing at all are no percent', null === helpers.boxPct( true ) && null === helpers.boxPct( {} ) && null === helpers.boxPct( undefined ) && null === helpers.boxPct( 'Infinity' ) );
fixture.box_no.forEach( function ( row ) {
	check( 'boxNo( ' + JSON.stringify( row[0] ) + ' ) → ' + JSON.stringify( row[1] ), same( row[1], helpers.boxNo( row[0] ) ), JSON.stringify( helpers.boxNo( row[0] ) ) );
} );
fixture.state.forEach( function ( row ) {
	check( 'boxState( ' + row[0] + ' ) → ' + row[1], row[1] === helpers.boxState( row[0] ) );
} );

console.log( '5. The per-box summary, its chips and its total' );
fixture.summary.forEach( function ( c ) {
	var summary = helpers.boxSummary( c.lines, c.boxes );
	check( 'boxSummary: ' + c.name, same( c.expect, summary ), JSON.stringify( summary ) );
	check( 'boxSummaryRows: ' + c.name, same( c.rows, helpers.boxSummaryRows( summary ) ), JSON.stringify( helpers.boxSummaryRows( summary ) ) );
	check( 'boxTotalText: ' + c.name, c.total === helpers.boxTotalText( summary ), helpers.boxTotalText( summary ) );
} );
check( 'boxSummary: no lines at all, junk rows skipped', same( { boxes: [], unboxed: { pct: 0, lines: 0 }, lines: 0, pct_total: 0, boxes_total: 0, order_boxes: null }, helpers.boxSummary( null ) ) && 0 === helpers.boxSummary( [ 'junk', null, 7 ], 'x' ).lines );
check( 'the chips and the total take a translated table', 'Kiste 1: 95.5 (voll)' === helpers.boxSummaryRows( { boxes: [ { box_no: 1, pct: 95.5, lines: 1, state: 'full' } ], unboxed: { pct: 0, lines: 0 } }, { box: 'Kiste %1$s: %2$s (%3$s)', full: 'voll' } )[0].text );
check( 'fmt: positional and plain arguments, a literal percent', 'a 2 b 1 100%' === helpers.fmt( 'a %2$d b %1$s 100%%', '1', 2 ) && 'x-y' === helpers.fmt( '%s-%s', 'x', 'y' ) );
check( 'num: no trailing zeros, two decimals at most', '95.5' === helpers.num( 95.5 ) && '100' === helpers.num( 100 ) && '0' === helpers.num( 0 ) && '89.99' === helpers.num( 89.99 ) && '' === helpers.num( null ) );

console.log( '6. Remembered shares: the share of a line, its suggestion, "How full"' );
fixture.suggest.forEach( function ( c ) {
	var suggestion = helpers.suggestFor( fixture.shares, c.line );
	check( 'suggestFor: ' + c.name, same( c.expect, suggestion ), JSON.stringify( suggestion ) );
	if ( c.expect ) {
		check( 'suggestText: ' + c.text, c.text === helpers.suggestText( suggestion ), helpers.suggestText( suggestion ) );
	}
} );
check( 'findShare: the id first, then the name; null when the fish is not remembered', 12.5 === helpers.findShare( fixture.shares, { species_id: 7, species: 'Blue Test Wrasse', size_slug: '2-3' } ).pct_per_fish && 99 === helpers.findShare( fixture.shares, { species: 'blue test wrasse', size_slug: '2-3' } ).pct_per_fish && null === helpers.findShare( fixture.shares, { species: 'Red Test Goby', size_slug: '2-3' } ) && null === helpers.findShare( null, {} ) );
check( 'suggestPct: no share, or a share worth nothing → null', null === helpers.suggestPct( null, { qty_received: 2 } ) && null === helpers.suggestPct( { pct_per_fish: 0, order_count: 1 }, { qty_received: 2 } ) );
fixture.how_full.forEach( function ( c ) {
	var full = helpers.howFull( c.lines, fixture.shares );
	check( 'howFull: ' + c.name, same( c.expect, full ), JSON.stringify( full ) );
	check( 'howFullText: ' + c.name, c.text === helpers.howFullText( full ), helpers.howFullText( full ) );
} );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
