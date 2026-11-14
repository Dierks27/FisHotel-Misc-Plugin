/**
 * Requests admin JS — the pure helpers of js/requests-admin.js: the fish
 * suggestion options, a site customer in one line and as a result button
 * (escaped — a customer's name is typed by the customer), when a lookup
 * is worth making, and the admin-ajax address. The file exports its
 * helpers and wires the page only when there is a document.
 *
 * Run from the plugin root:  node includes/sections/requests/tests/requests-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'requests-admin.js' ) );

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

function same( label, expected, actual ) {
	check( label, JSON.stringify( expected ) === JSON.stringify( actual ), 'expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
}

console.log( 'Fish suggestions' );
same( 'names become options', '<option value="Blue Test Wrasse" label="Testus caeruleus"></option>', helpers.optionsHtml( [ { name: 'Blue Test Wrasse', sci: 'Testus caeruleus' } ] ) );
same( 'no name twice', '<option value="Red Test Goby"></option>', helpers.optionsHtml( [ { name: 'Red Test Goby' }, { name: 'RED TEST GOBY' } ] ) );
same( 'escaped', '<option value="&lt;b&gt;&quot;"></option>', helpers.optionsHtml( [ { name: '<b>"' } ] ) );
same( 'junk gives nothing', '', helpers.optionsHtml( undefined ) + helpers.optionsHtml( [ {}, null ] ) );

console.log( '\nSite customers' );
same( 'name, e-mail and handle in one line', 'Pat Example — pat@example.test (reef_pat)', helpers.customerLabel( { id: 7, name: 'Pat Example', email: 'pat@example.test', handle: 'reef_pat' } ) );
same( 'without a handle', 'Pat Example — pat@example.test', helpers.customerLabel( { name: 'Pat Example', email: 'pat@example.test' } ) );
same( 'an e-mail alone', 'pat@example.test', helpers.customerLabel( { email: 'pat@example.test' } ) );
same( 'nothing', '', helpers.customerLabel( null ) );

var html = helpers.customersHtml( [
	{ id: 7, name: 'Pat "Reef" <b>Example</b>', email: 'pat@example.test', handle: '' },
	{ id: 0, name: 'Nobody' },
	null,
	{ id: '8', name: 'Sam Sample', email: 'sam@example.test', handle: 'reef_sam' }
] );
check( 'one button per customer with an id', ( html.match( /<button /g ) || [] ).length === 2 );
check( 'the id, the name, the e-mail and the handle ride on the button', html.indexOf( 'data-id="8" data-name="Sam Sample" data-email="sam@example.test" data-handle="reef_sam"' ) !== -1 );
check( 'a name typed by a customer cannot break the page', html.indexOf( '<b>' ) === -1 && html.indexOf( 'data-name="Pat &quot;Reef&quot; &lt;b&gt;Example&lt;/b&gt;"' ) !== -1 );
same( 'no customers, no buttons', '', helpers.customersHtml( [] ) + helpers.customersHtml( 'nope' ) );

console.log( '\nLookups' );
check( 'two letters are worth a lookup; one, or the same term again, is not', helpers.shouldLookup( 'pa', '' ) && ! helpers.shouldLookup( 'p', '' ) && ! helpers.shouldLookup( 'pat', 'pat' ) );
same(
	'the admin-ajax address, every value encoded',
	'https://example.test/wp-admin/admin-ajax.php?action=fishotel_requests_customers&nonce=abc&term=pat%20%26%20co',
	helpers.ajaxUrl( 'https://example.test/wp-admin/admin-ajax.php', { action: 'fishotel_requests_customers', nonce: 'abc', term: 'pat & co' } )
);
same( 'a missing value is empty, not "undefined"', 'x.php?a=1&term=', helpers.ajaxUrl( 'x.php?a=1', { term: undefined } ) );

console.log( '\nThe page' );
var booted = true;
try {
	helpers.boot( { document: { querySelector: function () { return null; } } }, {} );
} catch ( e ) {
	booted = false;
}
check( 'on a page that is not the Requests page the script does nothing', booted );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
