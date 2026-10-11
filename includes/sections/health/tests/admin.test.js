/**
 * Health admin JS — the pure log-filter helper the "narrow this page" box
 * uses, HTML escaping and the level class.
 *
 * Runs js/health-admin.js without jQuery or a DOM: the file exports its
 * helpers and skips the DOM wiring when jQuery is absent.
 *
 * Run from the plugin root:  node includes/sections/health/tests/admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'health-admin.js' ) );

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

function ids( rows ) {
	return rows.map( function ( r ) { return r.id; } ).join( ',' );
}

var rows = [
	{ id: 1, level: 'error',    source: 'mail',    text: 'smtp error: could not authenticate {"to":["dana@example.test"]}' },
	{ id: 2, level: 'critical', source: 'php',     text: 'e_error: uncaught error in wp-content/plugins/x.php:12' },
	{ id: 3, level: 'warning',  source: 'updater', text: 'github returned 503 for api.github.com/repos/x' },
	{ id: 4, level: 'error',    source: 'updater', text: 'github returned 401 for api.github.com/repos/x' },
	{ id: 5, level: 'info',     source: 'theme/packing-slip', text: 'ea slip emailed' }
];

console.log( '1. Exports' );
check( 'helpers are exported for node', helpers && typeof helpers.filterRows === 'function' && typeof helpers.escapeHtml === 'function' && typeof helpers.levelClass === 'function' );
check( 'helpers are also hung on the global for the browser', globalThis.fhHealthAdmin && globalThis.fhHealthAdmin.helpers === helpers );

console.log( '2. filterRows()' );
check( 'no filters → every row', '1,2,3,4,5' === ids( helpers.filterRows( rows, {} ) ) );
check( 'undefined filters → every row', '1,2,3,4,5' === ids( helpers.filterRows( rows ) ) );
check( 'level filter', '1,4' === ids( helpers.filterRows( rows, { level: 'error' } ) ) );
check( 'level filter is case-insensitive', '2' === ids( helpers.filterRows( rows, { level: 'CRITICAL' } ) ) );
check( 'source filter', '3,4' === ids( helpers.filterRows( rows, { source: 'updater' } ) ) );
check( 'source with a slash', '5' === ids( helpers.filterRows( rows, { source: 'theme/packing-slip' } ) ) );
check( 'search matches the text', '3,4' === ids( helpers.filterRows( rows, { search: 'github' } ) ) );
check( 'search is case-insensitive', '3,4' === ids( helpers.filterRows( rows, { search: 'GitHub' } ) ) );
check( 'several terms are ANDed', '4' === ids( helpers.filterRows( rows, { search: 'github 401' } ) ) );
check( 'search also matches the source', '5' === ids( helpers.filterRows( rows, { search: 'packing-slip' } ) ) );
check( 'level + source + search combine', '4' === ids( helpers.filterRows( rows, { level: 'error', source: 'updater', search: 'returned' } ) ) );
check( 'no match → empty', 0 === helpers.filterRows( rows, { search: 'nothing-here' } ).length );
check( 'whitespace-only search is no filter', '1,2,3,4,5' === ids( helpers.filterRows( rows, { search: '   ' } ) ) );
check( 'null rows are dropped', 1 === helpers.filterRows( [ null, rows[0], undefined ], {} ).length );
check( 'empty input', 0 === helpers.filterRows( [], { level: 'error' } ).length && 0 === helpers.filterRows( undefined, {} ).length );
check( 'input is not mutated', 5 === rows.length && 1 === rows[0].id );

console.log( '3. escapeHtml()' );
check( 'escapes the five characters', '&lt;b&gt;&amp;&quot;&#039;' === helpers.escapeHtml( '<b>&"\'' ) );
check( 'null / undefined → empty string', '' === helpers.escapeHtml( null ) && '' === helpers.escapeHtml( undefined ) );
check( 'numbers pass through as text', '98' === helpers.escapeHtml( 98 ) );

console.log( '4. levelClass()' );
check( 'error', 'fh-health-level fh-health-level--error' === helpers.levelClass( 'error' ) );
check( 'upper-case critical', 'fh-health-level fh-health-level--critical' === helpers.levelClass( 'CRITICAL' ) );
check( 'unknown → info', 'fh-health-level fh-health-level--info' === helpers.levelClass( '"><script>' ) );
check( 'missing → info', 'fh-health-level fh-health-level--info' === helpers.levelClass() );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
