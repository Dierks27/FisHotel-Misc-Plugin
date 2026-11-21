/**
 * The order page's Entry view — the pure helpers of js/order-entry.js,
 * run without a DOM or jQuery: a line's `split` field read and written
 * (as Splits::parts_in() reads it), how many fish a plan places, the live
 * fish of a line, what is wrong with a plan (the sentences
 * Splits::errors() gives), "1 per tank" (as Splits::one_per_tank() lays
 * it out), the running "6 of 6 placed", and where Enter and Tab go from a
 * field of the grid — down a column, never across into a hidden one.
 *
 * The expectations of sections 1 to 4 are the ones tests/splits-test.php
 * holds the PHP half to, so the page says what the server will say.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/order-entry.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var helpers = require( path.join( __dirname, '..', 'js', 'order-entry.js' ) );
var source  = fs.readFileSync( path.join( __dirname, '..', 'js', 'order-entry.js' ), 'utf8' );

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

// The Spa as the tests' seed has it: building 5 (511–513, 521–524) and building 6 (611, 621).
var tanks = [
	{ id: 18, number: 511, label: '511', name: 'Tank 511', building_id: 5, area: 'spa', empty: true },
	{ id: 19, number: 512, label: '512', name: 'Tank 512', building_id: 5, area: 'spa', empty: true },
	{ id: 20, number: 513, label: '513', name: 'Tank 513', building_id: 5, area: 'spa', empty: true },
	{ id: 14, number: 521, label: '521', name: 'Tank 521', building_id: 5, area: 'spa', empty: true },
	{ id: 15, number: 522, label: '522', name: 'Tank 522', building_id: 5, area: 'spa', empty: true },
	{ id: 16, number: 523, label: '523', name: 'Tank 523', building_id: 5, area: 'spa', empty: true },
	{ id: 17, number: 524, label: '524', name: 'Tank 524', building_id: 5, area: 'spa', empty: true },
	{ id: 22, number: 611, label: '611', name: 'Tank 611', building_id: 6, area: 'spa', empty: true },
	{ id: 21, number: 621, label: '621', name: 'Tank 621', building_id: 6, area: 'spa', empty: true },
	{ id: 23, number: 901, label: 'TEMP 1 · 901', name: 'TEMP 1 · 901', building_id: 7, area: 'temp', empty: true }
];
var byId = helpers.tanksById( tanks );
var t    = {
	noQty: 'a part of the split has no count',
	noTank: 'a part of the split has no tank',
	area: 'a part of the split names a tank that takes no arrivals (Spa tanks and quarantine temporary tanks only)',
	twice: 'Tank %s is picked twice',
	live: 'the split places %1$d fish but only %2$d arrived alive (received − DOA)',
	placed: '%1$d of %2$d placed',
	placedOnly: '%1$d placed — the received count is not typed yet'
};

function numbers( parts ) {
	return parts.map( function ( part ) { return part.qty + '>' + byId[ part.tank_id ].number; } );
}

console.log( '1. A line\'s split field, read and written' );
check( 'JSON rows of [count, tank id]', same( helpers.splitParts( '[[2,14],[1,15]]' ), [ { qty: 2, tank_id: 14 }, { qty: 1, tank_id: 15 } ] ) );
check( '… or of {qty, tank_id}, numbers or digit strings', same( helpers.splitParts( [ { qty: '2', tank_id: '14' } ] ), [ { qty: 2, tank_id: 14 } ] ) );
check( 'nothing said: an empty field, a missing one, an empty list, junk', helpers.splitParts( '' ) === null && helpers.splitParts( null ) === null && helpers.splitParts( undefined ) === null && helpers.splitParts( '[]' ) === null && helpers.splitParts( 'two in tank 4' ) === null && helpers.splitParts( '{"a":1}' ) === null && helpers.splitParts( 7 ) === null );
check( '"keep together" is kept as said', helpers.splitParts( 'none' ) === 'none' && helpers.splitParts( ' NONE ' ) === 'none' );
check( 'a row with neither a count nor a tank is dropped; one with only one of them is kept', same( helpers.splitParts( '[[3,""],["",""],[0,15],[null,null]]' ), [ { qty: 3, tank_id: 0 }, { qty: 0, tank_id: 15 } ] ) );
check( 'a formula, a negative, a decimal or a huge number is no count', same( helpers.splitParts( '[["=1+1",14]]' ), [ { qty: 0, tank_id: 14 } ] ) && same( helpers.splitParts( '[[-2,14]]' ), [ { qty: 0, tank_id: 14 } ] ) && same( helpers.splitParts( '[[1.5,14]]' ), [ { qty: 0, tank_id: 14 } ] ) && same( helpers.splitParts( '[[12345678,14]]' ), [ { qty: 0, tank_id: 14 } ] ) );
var many = [];
for ( var i = 1; i <= 60; i++ ) {
	many.push( [ 1, i ] );
}
check( 'at most 40 parts', helpers.splitParts( many ).length === 40 );
check( 'written back as the server reads it: [[count, tank id], …]', helpers.splitField( [ { qty: 2, tank_id: 14 }, { qty: 1, tank_id: 15 } ] ) === '[[2,14],[1,15]]' && same( helpers.splitParts( helpers.splitField( helpers.splitParts( '[[2,14],[1,15]]' ) ) ), helpers.splitParts( '[[2,14],[1,15]]' ) ) );
check( 'nothing is an empty field; "keep together" is "none"; an empty row is not written', helpers.splitField( null ) === '' && helpers.splitField( [] ) === '' && helpers.splitField( 'none' ) === 'none' && helpers.splitField( [ { qty: 0, tank_id: 0 } ] ) === '' && helpers.splitField( [ { qty: 3, tank_id: 0 }, { qty: 0, tank_id: 0 } ] ) === '[[3,0]]' );
check( 'placed adds the counts up', helpers.splitPlaced( helpers.splitParts( '[[2,14],[1,15]]' ) ) === 3 && helpers.splitPlaced( null ) === 0 && helpers.splitPlaced( 'none' ) === 0 );
check( 'the live fish of a line: received − DOA, never below 0, unknown while nothing is received', helpers.splitLive( '6', '1' ) === 5 && helpers.splitLive( 2, 5 ) === 0 && helpers.splitLive( '', '0' ) === null && helpers.splitLive( null, 0 ) === null && helpers.splitLive( undefined ) === null && helpers.splitLive( '0', '' ) === 0 && helpers.splitLive( '6', '' ) === 6 );

console.log( '2. What is wrong with a plan' );
var ok = [ { qty: 3, tank_id: 18 }, { qty: 2, tank_id: 20 }, { qty: 1, tank_id: 23 } ];
check( 'a sound plan: three tanks that take arrivals, 6 of 6 placed', same( helpers.splitErrors( ok, 6, byId, t ), [] ) );
check( 'fewer placed than alive is fine: the rest stays in the first tank', same( helpers.splitErrors( ok, 9, byId, t ), [] ) );
check( 'more placed than alive', same( helpers.splitErrors( ok, 5, byId, t ), [ 'the split places 6 fish but only 5 arrived alive (received − DOA)' ] ) );
check( 'nothing received yet: the counts cannot be checked', same( helpers.splitErrors( ok, null, byId, t ), [] ) );
check( 'a tank picked twice', same( helpers.splitErrors( [ { qty: 1, tank_id: 18 }, { qty: 1, tank_id: 18 } ], 6, byId, t ), [ 'Tank 511 is picked twice' ] ) );
check( 'a tank that takes no arrivals', helpers.splitErrors( [ { qty: 1, tank_id: 18 }, { qty: 1, tank_id: 1 } ], 6, byId, t ).length === 1 && helpers.splitErrors( [ { qty: 1, tank_id: 99999 } ], 6, byId, t )[0].indexOf( 'takes no arrivals' ) > 0 );
check( 'a further row without a count, a row without a tank — in the order the server names them', same( helpers.splitErrors( [ { qty: 1, tank_id: 20 }, { qty: 0, tank_id: 18 }, { qty: 2, tank_id: 0 } ], 6, byId, t ), [ 'a part of the split has no count', 'a part of the split has no tank' ] ) );
check( 'the first tank needs no count: it keeps what is not placed elsewhere', same( helpers.splitErrors( [ { qty: 0, tank_id: 18 }, { qty: 2, tank_id: 20 } ], 6, byId, t ), [] ) && same( helpers.splitErrors( [ { qty: 3, tank_id: 0 }, { qty: 2, tank_id: 20 } ], 6, byId, t ), [ 'a part of the split has no tank' ] ) && helpers.splitField( [ { qty: 0, tank_id: 18 }, { qty: 2, tank_id: 20 } ] ) === '[[0,18],[2,20]]' );

console.log( '3. "1 per tank"' );
check( 'six live fish: one each over the next empty Spa tanks of the order\'s building, in rack order', same( numbers( helpers.onePerTank( 6, tanks, 5, [], 0 ) ), [ '1>511', '1>512', '1>513', '1>521', '1>522', '1>523' ] ) );
check( 'the line\'s own first tank leads', same( numbers( helpers.onePerTank( 3, tanks, '5', [], 15 ) ), [ '1>522', '1>511', '1>512' ] ) );
check( 'tanks other lines of the page already use are skipped', same( numbers( helpers.onePerTank( 2, tanks, 5, [ 18, '19' ], 0 ) ), [ '1>513', '1>521' ] ) );
check( 'another building: only its tanks — and the plan stops when they run out', same( numbers( helpers.onePerTank( 5, tanks, 6, [], 0 ) ), [ '1>611', '1>621' ] ) );
check( 'no building on the order ("any"): the whole Spa', helpers.onePerTank( 20, tanks, '', [], 0 ).length === 9 && same( numbers( helpers.onePerTank( 2, tanks, 0, [], 0 ) ), [ '1>511', '1>512' ] ) );
var busy = tanks.map( function ( tank ) { return tank.id === 18 ? Object.assign( {}, tank, { empty: false } ) : tank; } );
check( 'a tank with fish in it is not an empty tank — also not as the first', same( numbers( helpers.onePerTank( 2, busy, 5, [], 18 ) ), [ '1>512', '1>513' ] ) );
check( 'nothing alive: nothing to lay out; a temporary tank is never picked by itself', same( helpers.onePerTank( 0, tanks, 5, [], 0 ), [] ) && same( helpers.onePerTank( 3, [ tanks[9] ], '', [], 0 ), [] ) );
check( 'never more than 40 parts', helpers.onePerTank( 500, new Array( 60 ).fill( 0 ).map( function ( x, n ) { return { id: n + 1, number: 100 + n, building_id: 1, area: 'spa', empty: true }; } ), 1, [], 0 ).length === 40 );

console.log( '4. The running count' );
check( '"6 of 6 placed" — all of them', same( helpers.splitCount( ok, 6, t ), { text: '6 of 6 placed', state: 'full', placed: 6 } ) );
check( 'fewer: neither full nor over', same( helpers.splitCount( ok, 9, t ), { text: '6 of 9 placed', state: '', placed: 6 } ) );
check( 'more than are alive: over', helpers.splitCount( ok, 5, t ).state === 'over' && helpers.splitCount( ok, 5, t ).text === '6 of 5 placed' );
check( 'a count typed beside "— tank —" is not in a tank yet', same( helpers.splitCount( [ { qty: 5, tank_id: 18 }, { qty: 1, tank_id: 0 } ], 6, t ), { text: '5 of 6 placed', state: '', placed: 5 } ) );
check( 'while nothing is received it only says how many are placed', same( helpers.splitCount( ok, null, t ), { text: '6 placed — the received count is not typed yet', state: '', placed: 6 } ) );
check( 'nothing alive is never "all placed"', helpers.splitCount( [], 0, t ).state === '' );

console.log( '5. Enter and Tab walk down a column' );
var down   = [ 'qty_received', 'qty_doa', 'box_no', 'box_pct' ];
var counts = { qty_received: 5, qty_doa: 5, box_no: 6, box_pct: 6, qty_ordered: 6, unit_price: 6 };
check( 'Enter in Received: the next line\'s Received', same( helpers.nextField( 'qty_received', 0, counts, false, 'Enter', down ), { field: 'qty_received', index: 1 } ) );
check( 'Tab in Received: the same — down, not across', same( helpers.nextField( 'qty_received', 3, counts, false, 'Tab', down ), { field: 'qty_received', index: 4 } ) );
check( 'with Shift, the one above', same( helpers.nextField( 'qty_received', 3, counts, true, 'Tab', down ), { field: 'qty_received', index: 2 } ) && same( helpers.nextField( 'qty_doa', 1, counts, true, 'Enter', down ), { field: 'qty_doa', index: 0 } ) );
check( 'Tab at the end of Received goes on to the top of DOA — received, then DOA…', same( helpers.nextField( 'qty_received', 4, counts, false, 'Tab', down ), { field: 'qty_doa', index: 0 } ) && same( helpers.nextField( 'qty_doa', 4, counts, false, 'Tab', down ), { field: 'box_no', index: 0 } ) && same( helpers.nextField( 'box_no', 5, counts, false, 'Tab', down ), { field: 'box_pct', index: 0 } ) );
check( '… and Shift+Tab at the top of DOA back to the bottom of Received', same( helpers.nextField( 'qty_doa', 0, counts, true, 'Tab', down ), { field: 'qty_received', index: 4 } ) );
check( 'Tab out of the last receiving column, or back out of the first, is left to the browser', helpers.nextField( 'box_pct', 5, counts, false, 'Tab', down ) === undefined && helpers.nextField( 'qty_received', 0, counts, true, 'Tab', down ) === undefined );
check( 'Enter at the end of a column stays put — it never saves by accident', helpers.nextField( 'qty_received', 4, counts, false, 'Enter', down ) === null && helpers.nextField( 'box_pct', 0, counts, true, 'Enter', down ) === null );
check( 'bag mode: Received, DOA, then Bags', same( helpers.nextField( 'qty_doa', 4, { qty_received: 5, qty_doa: 5, bags: 6 }, false, 'Tab', [ 'qty_received', 'qty_doa', 'bags' ] ), { field: 'bags', index: 0 } ) );
check( 'a receiving column with no field left (every line checked in) is stepped over', same( helpers.nextField( 'qty_received', 4, { qty_received: 5, qty_doa: 0, box_no: 6, box_pct: 6 }, false, 'Tab', down ), { field: 'box_no', index: 0 } ) );
check( 'Enter in Ordered or Cost goes down its own column too', same( helpers.nextField( 'qty_ordered', 2, counts, false, 'Enter', down ), { field: 'qty_ordered', index: 3 } ) && same( helpers.nextField( 'unit_price', 2, counts, true, 'Enter', down ), { field: 'unit_price', index: 1 } ) && helpers.nextField( 'unit_price', 5, counts, false, 'Enter', down ) === null );
check( 'Tab keeps to the ordering fields: Ordered → Cost → the next line\'s fish, and back', same( helpers.nextField( 'qty_ordered', 2, counts, false, 'Tab', down ), { field: 'unit_price', same: true } ) && same( helpers.nextField( 'unit_price', 2, counts, false, 'Tab', down ), { field: 'species', row: 1 } ) && same( helpers.nextField( 'unit_price', 2, counts, true, 'Tab', down ), { field: 'qty_ordered', same: true } ) );
check( 'Shift+Tab in Ordered, and Tab anywhere else, do what they always do', helpers.nextField( 'qty_ordered', 2, counts, true, 'Tab', down ) === undefined && helpers.nextField( 'per_box', 1, { per_box: 6 }, false, 'Tab', down ) === undefined && helpers.nextField( 'worth', 1, { worth: 6 }, false, 'Tab', down ) === undefined );
check( 'no key ever names a column of the other view', [ 'qty_received', 'qty_doa', 'box_no', 'box_pct', 'bags', 'qty_ordered', 'unit_price' ].every( function ( field ) {
	return [ false, true ].every( function ( back ) {
		return [ 'Enter', 'Tab' ].every( function ( key ) {
			var to = helpers.nextField( field, 0, { qty_received: 1, qty_doa: 1, box_no: 1, box_pct: 1, bags: 1, qty_ordered: 1, unit_price: 1 }, back, key, [ 'qty_received', 'qty_doa', 'box_no', 'box_pct', 'bags' ] );
			return ! to || [ 'qty_received', 'qty_doa', 'box_no', 'box_pct', 'bags', 'qty_ordered', 'unit_price', 'species' ].indexOf( to.field ) >= 0;
		} );
	} );
} ) );

console.log( '6. What the page does with them' );
check( 'the keys only walk in Entry, and a field counts only while it is shown', source.indexOf( "$order.attr('data-view') !== 'entry'" ) > 0 && source.indexOf( 'this.offsetParent !== null' ) > 0 );
check( 'an offered split is laid out only in the Entry view — never written into a field the owner is not looking at', /if \(view === 'entry' && !offered\) \{\s*offerSplits\(\);/.test( source ) && /if \(\$order\.attr\('data-view'\) === 'entry'\) \{\s*offerSplits\(\);/.test( source ) );
var body = function ( name ) {
	var at = source.indexOf( 'function ' + name + '(' );
	var end = source.indexOf( '\n\t\t}\n', at );
	return at > 0 && end > at ? source.slice( at, end ) : '';
};
check( 'an offered split is a PROPOSAL: drawing it never writes the line\'s field — neither when the page loads nor when the received count moves under it', body( 'offerSplits' ).indexOf( 'propose($row);' ) > 0 && body( 'offerSplits' ).indexOf( 'commit(' ) === -1 && body( 'propose' ) !== '' && body( 'propose' ).indexOf( 'commit(' ) === -1 && /if \(\$row\.data\('auto'\) && \$panel\.data\('offer'\)\) \{\s*propose\(\$row\);/.test( source ) );
check( '… it becomes a plan on a press: "Use this split" commits what is laid out, and so does changing a row', /\$order\.on\('click', '\.fh-cr-split__use', function \(\) \{[\s\S]{0,260}commit\(\$row, parts\);/.test( source ) && body( 'commit' ).indexOf( "removeData('offer')" ) > 0 );
check( '… and "1 per tank" lays itself out only for as many fish as went one per tank last time: 3 last time lay out 3, never 93', helpers.offerLaysOut( 'ones', 3, 3 ) === true && helpers.offerLaysOut( 'ones', 3, 2 ) === true && helpers.offerLaysOut( 'ones', 3, 93 ) === false && helpers.offerLaysOut( 'ones', 3, 4 ) === false );
check( '… not without a received count, not for one fish, not when last time\'s count is not known, not for a fish that was only split somehow', helpers.offerLaysOut( 'ones', 3, null ) === false && helpers.offerLaysOut( 'ones', 3, 1 ) === false && helpers.offerLaysOut( 'ones', 0, 3 ) === false && helpers.offerLaysOut( 'some', 5, 3 ) === false && helpers.offerLaysOut( '', 5, 3 ) === false );
check( '"Keep together" on an offered line posts "none"; on any other line it posts nothing', source.indexOf( "commit($row, $row.attr('data-split-offer') ? 'none' : null);" ) > 0 );
check( 'a hidden column is never disabled or taken out of the form to hide it', ! /fh-cr-c-(entry|numbers|more)[^\n]*(remove|detach|disabled)/.test( source ) && source.indexOf( ".prop('disabled', true); // A tank cannot be picked twice for one line." ) > 0 && ( source.match( /\.prop\('disabled'/g ) || [] ).length === 1 );
check( 'nothing the page builds is set as HTML but the × of a part', ( source.match( /\.html\(/g ) || [] ).length === 0 && ( source.match( /\$\('<[^']*'\)/g ) || [] ).every( function ( tag ) { return ! /\+/.test( tag ); } ) );
check( 'the view a person switches to is remembered through the order\'s "view" op', source.indexOf( "op: 'view'" ) > 0 && source.indexOf( 'remember({ view: view })' ) > 0 && source.indexOf( 'remember({ more: this.checked ? 1 : 0 })' ) > 0 );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
