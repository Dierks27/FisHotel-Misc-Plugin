/**
 * Clean Room admin JS — the pure client helpers: the input-name
 * renumbering (steps / lines / rates, a template's __i__) and the
 * import-fees suggestion that mirrors Settings::suggest_import_fees().
 *
 * Runs js/clean-room-admin.js without a DOM or jQuery: the file exports
 * its helpers and skips the page code when there is no jQuery.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/clean-room-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'clean-room-admin.js' ) );

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

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
