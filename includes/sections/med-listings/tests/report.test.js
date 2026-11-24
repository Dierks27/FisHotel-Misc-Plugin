/**
 * Med listings — the report the page writes (med-cleanup-report.md):
 * product ID, name, field, current, proposed; what was changed; the
 * flags; the redirects; the check. Runs js/med-listings.js without a DOM
 * (the file exports buildReport and skips the page code).
 *
 * Run from the plugin root:  node includes/sections/med-listings/tests/report.test.js
 */
'use strict';

var path   = require( 'path' );
var assert = require( 'assert' );
var lib    = require( path.join( __dirname, '..', 'js', 'med-listings.js' ) );

var md = lib.buildReport( {
	date: '2026-11-24 10:00',
	products: [ {
		id: '1012',
		name: 'Sample Trio Flake',
		rows: [
			{ label: 'Description', current: 'Treats ich | velvet\nFeed 7 days', proposed: 'Sample Trio Flake\nFlake food containing kanamycin.', ticked: true },
			{ label: '"Treats" row', current: 'Bloat', proposed: '', ticked: false }
		]
	} ],
	flags: [ { type: 'name', product: 'Fluke Cure Powder', productId: '1020', text: 'The product name is a claim itself. Suggested name: Praziquantel Powder' } ],
	changes: [ { productId: '1012', product: 'Sample Trio Flake', field: 'meta:_fishotel_treats #1012', old: 'Bloat', new: '' } ],
	termChanges: [ 'sort: Antiparasitic (antiparasitic)' ],
	redirects: [ { from: '/product-category/medications/antiparasitic/', to: 'https://shop.test/product-category/medications/powders-chemicals/' } ],
	checks: [ { label: 'Sample Trio Flake', url: 'https://shop.test/shop/sample-trio-flake/', result: 'clean' } ]
} );

var checks = 0;
function ok( label, cond ) {
	checks++;
	assert.ok( cond, label );
	console.log( '  ok   ' + label );
}

ok( 'a title', md.indexOf( '# Med listings cleanup report' ) === 0 );
ok( 'the proposal table has product ID, name, field, current, proposed', md.indexOf( '| Product ID | Name | Field | Current | Proposed | Ticked |' ) !== -1 );
ok( 'a pipe in the text cannot break the table, a line break becomes <br>', md.indexOf( 'Treats ich \\| velvet<br>Feed 7 days' ) !== -1 );
ok( 'an empty proposal says (empty), and whether it is ticked', md.indexOf( '| 1012 | Sample Trio Flake | "Treats" row | Bloat | (empty) | no |' ) !== -1 );
ok( 'what was changed, with the old value', md.indexOf( '| 1012 | Sample Trio Flake | meta:_fishotel_treats #1012 | Bloat |  |' ) !== -1 );
ok( 'the category changes', md.indexOf( '- sort: Antiparasitic (antiparasitic)' ) !== -1 );
ok( 'the flags, with the suggested name', md.indexOf( '**Fluke Cure Powder** (#1020, name): The product name is a claim itself. Suggested name: Praziquantel Powder' ) !== -1 );
ok( 'the redirects', md.indexOf( '`/product-category/medications/antiparasitic/` → `https://shop.test/product-category/medications/powders-chemicals/`' ) !== -1 );
ok( 'the page check', md.indexOf( 'https://shop.test/shop/sample-trio-flake/ — clean' ) !== -1 );
ok( 'nothing yet: says so', lib.buildReport( {} ).indexOf( 'Nothing yet.' ) !== -1 && lib.buildReport( {} ).indexOf( 'nothing left to change' ) !== -1 );

console.log( '\nAll ' + checks + ' checks passed' );
