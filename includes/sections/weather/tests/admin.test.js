/**
 * Weather admin JS — the pure helpers the overview relies on after a
 * "Check now" reply: totals per tile, HTML escaping, status class.
 *
 * Runs js/weather-admin.js without jQuery or a DOM: the file exports its
 * helpers and skips the DOM wiring when jQuery is absent.
 *
 * Run from the plugin root:  node includes/sections/weather/tests/admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'weather-admin.js' ) );

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
	return JSON.stringify( a ) === JSON.stringify( b );
}

console.log( '1. Exports' );
check( 'helpers are exported for node', helpers && typeof helpers.tally === 'function' && typeof helpers.escapeHtml === 'function' && typeof helpers.noteStatusClass === 'function' );
check( 'helpers are also hung on the global for the browser', globalThis.fhWeatherAdmin && globalThis.fhWeatherAdmin.helpers === helpers );

console.log( '2. tally()' );
var totals = helpers.tally( [ 'heat_pack', 'heat_pack', 'cooling', 'ups_store_cold', 'ups_store_hot', 'hold_cold', 'hold_hot', 'none', 'unavailable', '', undefined ] );
check( 'orders counts every row', 11 === totals.orders );
check( 'heat packs', 2 === totals.heat_pack );
check( 'cooling packs', 1 === totals.cooling );
check( 'UPS Store merges cold + hot', 2 === totals.ups_store );
check( 'holds merge cold + hot', 2 === totals.hold );
check( 'nothing needed', 1 === totals.none );
check( 'unavailable', 1 === totals.unavailable );
check( 'blank and undefined are "not checked"', 2 === totals.unchecked );
check( 'an unknown action is counted as an order but nowhere else', same( helpers.tally( [ 'bogus' ] ), { orders: 1, heat_pack: 0, none: 0, cooling: 0, ups_store: 0, hold: 0, unavailable: 0, unchecked: 0 } ) );
check( 'empty input', same( helpers.tally( [] ), { orders: 0, heat_pack: 0, none: 0, cooling: 0, ups_store: 0, hold: 0, unavailable: 0, unchecked: 0 } ) );
check( 'no input', 0 === helpers.tally().orders );
check( 'prototype keys cannot leak in', 0 === helpers.tally( [ 'hasOwnProperty', 'constructor' ] ).unchecked && 2 === helpers.tally( [ 'hasOwnProperty', 'constructor' ] ).orders );

console.log( '3. escapeHtml()' );
check( 'escapes the five characters', '&lt;b&gt;&amp;&quot;&#039;' === helpers.escapeHtml( '<b>&"\'' ) );
check( 'null / undefined → empty string', '' === helpers.escapeHtml( null ) && '' === helpers.escapeHtml( undefined ) );
check( 'numbers pass through as text', '98' === helpers.escapeHtml( 98 ) );

console.log( '4. noteStatusClass()' );
check( 'noted', 'fh-weather-note-status fh-weather-note-status--noted' === helpers.noteStatusClass( 'noted' ) );
check( 'unavailable', 'fh-weather-note-status fh-weather-note-status--unavailable' === helpers.noteStatusClass( 'unavailable' ) );
check( 'stale', 'fh-weather-note-status fh-weather-note-status--stale' === helpers.noteStatusClass( 'stale' ) );
check( 'unknown → none', 'fh-weather-note-status fh-weather-note-status--none' === helpers.noteStatusClass( '"><script>' ) );
check( 'missing → none', 'fh-weather-note-status fh-weather-note-status--none' === helpers.noteStatusClass() );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
