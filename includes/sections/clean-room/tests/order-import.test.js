/**
 * Import lines — the page's pure helpers (js/order-import.js), run
 * without a DOM or jQuery: "Received: all as ordered" (fills the EMPTY
 * received fields only), the foot of
 * the ordered preview as the ticks stand, what the page tells the server
 * about the owner's choices — and the order form's lines packed into one
 * field (packLines() of js/clean-room-admin.js), which
 * Admin_Workbook::unpack_lines() turns back into rows.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/order-import.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var helpers = require( path.join( __dirname, '..', 'js', 'order-import.js' ) );
var admin   = require( path.join( __dirname, '..', 'js', 'clean-room-admin.js' ) );

var checks   = 0;
var failures = 0;

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

console.log( '1. Received: all as ordered' );
var filled = helpers.receivedAsOrdered( [
	{ ordered: '12', received: '' },
	{ ordered: '6', received: '4' },
	{ ordered: '3', received: '0' },
	{ ordered: '', received: '' },
	{ ordered: ' 8 ', received: '  ' },
	{ ordered: 5, received: null },
	{ ordered: 'abc', received: '' },
	{ ordered: '0', received: undefined }
] );
check( 'every EMPTY received field takes its ordered count', filled[0] === '12' && filled[4] === '8' && filled[5] === '5', JSON.stringify( filled ) );
check( 'a field that holds a count is left alone — a 0 too (that fish did not come)', filled[1] === '4' && filled[2] === '0' );
check( 'a line without a usable ordered count stays empty', filled[3] === '' && filled[6] === '' );
check( 'an ordered 0 fills a 0', filled[7] === '0' );
check( 'nothing at all gives nothing', same( helpers.receivedAsOrdered( [] ), [] ) && same( helpers.receivedAsOrdered( null ), [] ) );

console.log( '2. The foot of the ordered preview' );
var rows = [
	{ on: true, ordered: 4, dup: '', dup_choice: '' },
	{ on: false, ordered: 6, dup: '', dup_choice: '' },
	{ on: true, ordered: 3, dup: 'Blue Test Wrasse', dup_choice: 'skip' },
	{ on: true, ordered: 2, dup: 'Red Test Goby', dup_choice: 'add' },
	{ on: true, ordered: null, dup: '', dup_choice: '' }
];
check( 'the lines that would be added and the fish on them: unticked rows and skipped duplicates do not count, "add anyway" does', same( helpers.orderedFoot( rows ), { lines: 3, fish: 6 } ), JSON.stringify( helpers.orderedFoot( rows ) ) );
check( 'no rows, no lines', same( helpers.orderedFoot( [] ), { lines: 0, fish: 0 } ) );
check( 'thousands separators, as the server writes them', helpers.thousands( 1188 ) === '1,188' && helpers.thousands( 61 ) === '61' && helpers.thousands( 1234567 ) === '1,234,567' );
check( 'the page fills the server\'s sentences', helpers.fmt( '%1$s lines · %2$s fish', '61', '412' ) === '61 lines · 412 fish' && helpers.fmt( 'code %s', 'XA1' ) === 'code XA1' && helpers.fmt( '%d fields', 3 ) === '3 fields' );
check( '… and a doubled percent sign is one, as in sprintf ("7% of a box", never "7%% of a box")', helpers.fmt( '%s%% of a box', 7 ) === '7% of a box' && helpers.fmt( '100%%' ) === '100%' && helpers.fmt( '%1$s%% · %2$d', '12.5', 3 ) === '12.5% · 3' );

console.log( '3. What the page tells the server' );
var plain = helpers.payloadOf( { mode: 'ordered', hand: false, too: false, rows: {}, lines: {}, remember: true, token: 'abc' } );
check( 'the moment, the ticks, the token — and no column map the owner did not set', plain.mode === 'ordered' && plain.hand === 0 && plain.remember === 1 && plain.token === 'abc' && ! ( 'map' in plain ) && ! ( 'use_layout' in plain ) && ! ( 'sections' in plain ) && plain.same_fish === 'sum' );
var hand = helpers.payloadOf( { mode: 'arrived', hand: true, map: { 0: 'fish', 2: 'qty' }, headerRow: null, useLayout: 'abc123', sections: [ 'Northland' ], sameFish: 'separate', useFilePrice: true, fees: true, feesReplace: false, rows: { 4: { on: 0 } }, lines: { 2: 'keep' } } );
check( 'the columns set by hand travel with the header row ("" = none), the layout to use, the sections, the choices', hand.hand === 1 && same( hand.map, { 0: 'fish', 2: 'qty' } ) && hand.header_row === '' && hand.use_layout === 'abc123' && same( hand.sections, [ 'Northland' ] ) && hand.same_fish === 'separate' && hand.use_file_price === 1 && hand.fees === 1 && hand.fees_replace === 0 && same( hand.rows, { 4: { on: 0 } } ) && same( hand.lines, { 2: 'keep' } ) );
check( 'a header on the first row is row 0, not "none"', helpers.payloadOf( { hand: true, map: {}, headerRow: 0 } ).header_row === 0 );

console.log( '4. The order form\'s lines as one field' );
var fields = [
	{ name: 'order_id', value: '7' },
	{ name: 'lines[0][id]', value: '11' },
	{ name: 'lines[0][species]', value: 'Blue Test Wrasse' },
	{ name: 'supplier', value: 'Example Aquatics' },
	{ name: 'lines[2][id]', value: '0' },
	{ name: 'lines[2][species]', value: 'Red "Test" Goby' },
	{ name: 'lines[2][needs_id]', value: '1' },
	{ name: 'notes', value: 'lines[9][x] is only text here' }
];
var packed = admin.packLines( fields );
check( 'every other field stays as it was; the lines become ONE field', packed.length === 4 && same( packed.slice( 0, 3 ), [ fields[0], fields[3], fields[7] ] ) && packed[3].name === 'lines_json' );
check( '… the rows in the page\'s order, each with its fields', same( JSON.parse( packed[3].value ), [ { id: '11', species: 'Blue Test Wrasse' }, { id: '0', species: 'Red "Test" Goby', needs_id: '1' } ] ) );
var many = [];
for ( var i = 0; i < 150; i++ ) {
	for ( var f = 0; f < 17; f++ ) {
		many.push( { name: 'lines[' + i + '][f' + f + ']', value: String( f ) } );
	}
}
check( 'an order of 150 fish: 2,550 form fields become one — it cannot be cut short by max_input_vars', many.length === 2550 && admin.packLines( many ).length === 1 && JSON.parse( admin.packLines( many )[0].value ).length === 150 );
check( 'an order without lines posts an empty list, so a Save that removed the last line still removes it', same( admin.packLines( [ { name: 'ref', value: 'x' } ] ), [ { name: 'ref', value: 'x' }, { name: 'lines_json', value: '[]' } ] ) );

var half = admin.packLines( [ { name: 'lines[0][species]', value: 'Blue Test Wrasse \ud83d' }, { name: 'lines[0][notes]', value: '\udc1f tail' }, { name: 'lines[1][species]', value: 'Whole \ud83d\udc1f fish' } ] )[0].value;
check( 'half an emoji in a name (a lone surrogate) never reaches the field: PHP could not read it back, and the whole order would be refused', half.indexOf( '\\ud83d' ) === -1 && half.indexOf( '\\udc1f' ) === -1 && same( JSON.parse( half ), [ { species: 'Blue Test Wrasse \ufffd', notes: '\ufffd tail' }, { species: 'Whole \ud83d\udc1f fish' } ] ) );
check( '… a whole one is left alone', admin.wellFormed( 'a \ud83d\udc1f b' ) === 'a \ud83d\udc1f b' && admin.wellFormed( null ) === '' && admin.wellFormed( 12 ) === '12' );

console.log( '4b. The foot, and where the focus goes back to' );
check( 'one line is "1 line"', helpers.footText( { lines: 1, fish: 3 }, { foot: '%1$s lines · %2$s fish', footOne: '%1$s line · %2$s fish' } ) === '1 line · 3 fish' && helpers.footText( { lines: 1200, fish: 3400 }, { foot: '%1$s lines · %2$s fish', footOne: '%1$s line · %2$s fish' } ) === '1,200 lines · 3,400 fish' );
check( 'after the preview is drawn again the control that had the focus gets it back by what it is and whose row it is — wherever the row now stands', helpers.focusIndex( [ { key: 'tick:4' }, { key: 'pick:9' }, { key: 'pair:4' } ], 'pair:4' ) === 2 && helpers.focusIndex( [ { key: 'pick:9' } ], 'pick:4' ) === -1 && helpers.focusIndex( [ { key: 'pick:9' } ], '' ) === -1 && helpers.focusIndex( null, 'x' ) === -1 );
var page = fs.readFileSync( path.join( __dirname, '..', 'js', 'order-import.js' ), 'utf8' );
check( '… every control of the arrived preview that redraws it carries such a key, and take() hands the focus back', [ "'pick:' + row.n", "'tick:' + row.n", "'pair:' + row.n", "'line:' + entry.line", "'same'", "'section:' + section.name" ].every( function ( key ) { return page.indexOf( ".attr('data-fk', " + key + ')' ) !== -1; } ) && /var was = held\(\);\s*render\(p\);\s*giveBack\(was\);/.test( page ) );

console.log( '5. Nothing is ever set as HTML' );
var source = fs.readFileSync( path.join( __dirname, '..', 'js', 'order-import.js' ), 'utf8' );
check( 'the page code never writes HTML from data: no .html( … ), no innerHTML, no insertAdjacentHTML', ! /\.html\s*\(\s*[^)\s]/.test( source ) && source.indexOf( 'innerHTML' ) === -1 && source.indexOf( 'insertAdjacentHTML' ) === -1 );
check( 'the only markup it builds itself is a bare checkbox', ( source.match( /\$\(\s*'<[^']*'\s*\)/g ) || [] ).every( function ( m ) { return /^\$\(\s*'<input type="checkbox"( checked)?>'\s*\)$/.test( m ); } ), JSON.stringify( source.match( /\$\(\s*'<[^']*'\s*\)/g ) ) );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
