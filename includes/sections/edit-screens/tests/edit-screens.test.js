/**
 * Edit screens JS — the pure helpers: the kind of product from what is
 * ticked (the same rule as Kinds::kind()), the <body> class swapped, the
 * boxes the area under the title takes. Runs js/edit-screens.js without
 * jQuery or a DOM.
 *
 * Run from the plugin root:  node includes/sections/edit-screens/tests/edit-screens.test.js
 */
'use strict';

var path = require( 'path' );
var h    = require( path.join( __dirname, '..', 'js', 'edit-screens.js' ) );

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

var terms = { fish: [ 15, 16 ], med: [ 30, 31 ], invoice: [ 40 ] };

console.log( '1. The kind follows what is ticked' );
check( 'the helpers are exported', [ 'kindFrom', 'withKind', 'topBoxes', 'ids' ].every( function ( n ) {
	return typeof h[ n ] === 'function';
} ) );
check( 'a fish category: a live fish', h.kindFrom( [ '15' ], terms, null, '' ) === 'fish' );
check( 'the Livestock box ticked outside the fish categories: a live fish', h.kindFrom( [ '99' ], terms, true, '' ) === 'fish' );
check( 'the Livestock box unticked in a fish category: not', h.kindFrom( [ '15' ], terms, false, '' ) === 'dry' );
check( 'a medication category, or "Who ships it" set: medication', h.kindFrom( [ '31' ], terms, null, 'none' ) === 'med' && h.kindFrom( [ '99' ], terms, false, 'ea' ) === 'med' );
check( 'an invoice is never a fish', h.kindFrom( [ '40', '15' ], terms, true, '' ) === 'invoice' );
check( 'nothing ticked: no kind yet; something else: a dry good', h.kindFrom( [], terms, null, '' ) === 'none' && h.kindFrom( [ '77' ], terms, null, '' ) === 'dry' );
check( 'nonsense in, a kind out', h.kindFrom( null, null, undefined, null ) === 'none' && h.kindFrom( [ 'x', '-1', '15' ], terms, null, undefined ) === 'fish' );

console.log( '2. The class on the page' );
check( 'the kind class swapped, every other class kept', h.withKind( 'wp-admin fh-es-on fh-es-kind-fish post-php', 'dry' ) === 'wp-admin fh-es-on post-php fh-es-kind-dry' );
check( 'a kind that is not one: the old one removed, none added', h.withKind( 'a fh-es-kind-med', 'nope' ) === 'a' );

console.log( '3. The boxes under the title' );
check( 'the two boxes, in order', JSON.stringify( h.topBoxes( 'fishotel-essentials, woocommerce-product-data' ) ) === JSON.stringify( [ 'fishotel-essentials', 'woocommerce-product-data' ] ) );
check( 'nothing that is not an id', JSON.stringify( h.topBoxes( 'a b,<x>,,ok_1' ) ) === JSON.stringify( [ 'ok_1' ] ) && h.topBoxes( null ).length === 0 );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
