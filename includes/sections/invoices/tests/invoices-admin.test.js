/**
 * Invoices admin JS — the pure client helpers of the picker: typed
 * amounts, the sums, the Discount / Adjustment line a flat total leaves,
 * the description lines and the rows for the theme — run against the
 * same fixtures/invoice-maths.json the PHP test (lines-test.php) reads,
 * so the picker's live preview shows exactly what the server will write
 * — plus the title, the short description and the date in a title.
 *
 * Runs js/invoices-admin.js without a DOM or jQuery: the file exports
 * its helpers and skips the page code when there is no jQuery.
 *
 * Run from the plugin root:  node includes/sections/invoices/tests/invoices-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var helpers = require( path.join( __dirname, '..', 'js', 'invoices-admin.js' ) );
var fixture = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'invoice-maths.json' ), 'utf8' ) );

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

function near( a, b ) {
	return ( a === null && b === null ) || ( a !== null && b !== null && Math.abs( a - b ) < 1e-9 );
}

function same( label, expected, actual ) {
	check( label, JSON.stringify( expected ) === JSON.stringify( actual ), 'expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
}

console.log( '1. Typed amounts — the same answers as Lines::money()' );
fixture.money.forEach( function ( row ) {
	var got = helpers.money( row.typed );
	check( 'money( ' + JSON.stringify( row.typed ) + ' ) = ' + JSON.stringify( row.amount ), near( got, row.amount ), String( got ) );
} );
check( 'a number is taken as it is; a huge or non-finite one is no amount', near( helpers.money( 12.5 ), 12.5 ) && near( helpers.money( -3 ), -3 ) && helpers.money( 1e9 ) === null && helpers.money( Infinity ) === null && helpers.money( NaN ) === null );
check( 'anything that is not text or a number is no amount (never 0)', helpers.money( null ) === null && helpers.money( undefined ) === null && helpers.money( true ) === null && helpers.money( [ '5' ] ) === null );
check( 'never -0', Object.is( helpers.money( '-0' ), 0 ) && Object.is( helpers.fromCents( 0 ), 0 ) );
fixture.money_text.forEach( function ( row ) {
	same( 'moneyText( ' + row.amount + ' )', row.text, helpers.moneyText( row.amount ) );
} );
same( 'dollars(): a minus in front of the dollar', [ '$82', '-$82.50', '$0' ], [ helpers.dollars( 82 ), helpers.dollars( -82.5 ), helpers.dollars( 0 ) ] );
same( 'cents(): whole cents, both signs, nothing for nothing', [ 1999, -8250, 0, 0, 30 ], [ helpers.cents( 19.99 ), helpers.cents( -82.5 ), helpers.cents( null ), helpers.cents( '' ), helpers.cents( 0.1 + 0.2 ) ] );

console.log( '2. The fixture: sums, the Discount / Adjustment line, the description lines, the rows for the theme' );
fixture.cases.forEach( function ( item ) {
	var sum   = helpers.withFlatTotal( helpers.normalize( item.lines ), item.flat_total );
	var texts = sum.lines.map( helpers.lineText );
	var rows  = helpers.themeRows( sum.lines );
	var total = sum.lines.reduce( function ( acc, line ) {
		return acc + helpers.amountCents( line );
	}, 0 );

	check( item.name + ' — subtotal, discount, total', near( sum.subtotal, item.subtotal ) && near( sum.discount, item.discount ) && near( sum.total, item.total ), JSON.stringify( [ sum.subtotal, sum.discount, sum.total ] ) );
	check( item.name + ' — the lines ALWAYS add up to the total', total === helpers.cents( sum.total ) );
	same( item.name + ' — description lines', item.texts, texts );
	same( item.name + ' — rows for the theme', item.rows, rows );
} );

console.log( '3. Normalising a line the way the server does' );
var line = helpers.normalizeLine( { id: '17', kind: 'FISH', name: '  <b>Blue</b>\tTest Tang \n', qty: '3', unit_price: '$45.00', product_id: '501', variation_id: 777, species_id: '0', stay_id: '', size_label: ' Medium ', source: 'product', take_stock: 'on' }, 4 );
check( 'tags stripped, whitespace collapsed, ids typed, the position kept', line.id === 17 && line.kind === 'fish' && line.name === 'Blue Test Tang' && line.qty === 3 && line.unit_price === 45 && line.product_id === 501 && line.variation_id === 777 && line.species_id === null && line.stay_id === null && line.size_label === 'Medium' && line.source === 'product' && line.take_stock === true && line.sort === 4, JSON.stringify( line ) );
check( 'a fish or a note without a name is no line', helpers.normalizeLine( { kind: 'fish', name: ' ', unit_price: '5' } ) === null && helpers.normalizeLine( { kind: 'note', name: '' } ) === null && helpers.normalizeLine( 'junk' ) === null && helpers.normalizeLine( null ) === null );
check( 'an unknown kind is a fish; an unknown source is typed', helpers.normalizeLine( { kind: 'gift', name: 'X' } ).kind === 'fish' && helpers.normalizeLine( { name: 'X', source: 'moon' } ).source === 'typed' );
check( 'a source without its id is only a typed name, and cannot take stock', helpers.normalizeLine( { name: 'X', source: 'product', take_stock: true } ).source === 'typed' && helpers.normalizeLine( { name: 'X', source: 'product', take_stock: true } ).take_stock === false && helpers.normalizeLine( { name: 'X', source: 'tank' } ).source === 'typed' && helpers.normalizeLine( { name: 'X', source: 'species' } ).source === 'typed' );
var credit = helpers.normalizeLine( { kind: 'credit', name: 'credit — lost Test Wrasse', unit_price: '82', qty: 5, product_id: 9, take_stock: true } );
check( 'a credit: amount negative, "Credit" in front dropped, qty 1, no ids, no stock tick', credit.unit_price === -82 && credit.name === 'lost Test Wrasse' && credit.qty === 1 && credit.product_id === null && credit.take_stock === false && helpers.amountCents( credit ) === -8200 );
check( 'a credit with only an amount is kept ("Credit"); with nothing at all it is no line', helpers.publicName( helpers.normalizeLine( { kind: 'credit', name: '', unit_price: '5' } ) ) === 'Credit' && helpers.normalizeLine( { kind: 'credit', name: '', unit_price: '' } ) === null );
var note = helpers.normalizeLine( { kind: 'note', name: 'Ships Tuesday', unit_price: '40', qty: 3, stay_id: 4 } );
check( 'a note keeps its text only and adds no money', note.unit_price === null && note.qty === 1 && note.stay_id === null && helpers.amountCents( note ) === 0 );
check( 'a name is cut to 200 characters, a quantity to 999', helpers.normalizeLine( { name: new Array( 301 ).join( 'a' ) } ).name.length === 200 && helpers.normalizeLine( { name: 'X', qty: 100000 } ).qty === 999 );
check( 'at most 200 lines', helpers.normalize( new Array( 250 ).fill( { name: 'X', unit_price: '1' } ) ).length === 200 );
same( 'positions are renumbered from 0 after empty rows are dropped', [ 0, 1 ], helpers.normalize( [ { name: 'A' }, { name: '' }, { name: 'B' } ] ).map( function ( l ) {
	return l.sort;
} ) );

console.log( '4. The flat total' );
var lines = helpers.normalize( [ { name: 'Blue Test Tang', qty: 2, unit_price: '45' } ] );
var sum   = helpers.withFlatTotal( lines, 80 );
check( 'a number works like typed text', sum.lines.length === 2 && sum.lines[ 1 ].kind === 'discount' && sum.lines[ 1 ].unit_price === -10 && sum.lines[ 1 ].name === 'Discount' );
var again = helpers.withFlatTotal( sum.lines, '95' );
check( 'applied again, the earlier discount line is replaced, never added to', again.lines.length === 2 && again.lines[ 1 ].unit_price === 5 && again.lines[ 1 ].name === 'Adjustment' && again.total === 95 );
check( 'without a flat total the discount line goes and the total is the sum', helpers.withFlatTotal( sum.lines, null ).lines.length === 1 && helpers.withFlatTotal( sum.lines, null ).total === 90 );
check( 'a flat total that is no amount is no flat total', helpers.withFlatTotal( lines, 'abc' ).total === 90 && helpers.withFlatTotal( lines, '' ).total === 90 );
check( 'a flat total of 0 is a flat total', helpers.withFlatTotal( lines, '0' ).total === 0 && helpers.withFlatTotal( lines, '0' ).discount === -90 );
check( 'the typed lines are not changed by it', lines.length === 1 && lines[ 0 ].sort === 0 );

console.log( '5. Title, short description, the date in a title' );
same( 'the default title', 'Tester Invoice (11/3/26)', helpers.title( '{handle} Invoice ({date})', 'Tester', '11/3/26' ) );
same( 'another format', 'Invoice for Tester, 11/3/26', helpers.title( 'Invoice for {handle}, {date}', 'Tester', '11/3/26' ) );
same( 'a format without {handle}, or none: the default', [ 'Tester Invoice (11/3/26)', 'Tester Invoice (11/3/26)' ], [ helpers.title( 'Invoice ({date})', 'Tester', '11/3/26' ), helpers.title( '', 'Tester', '11/3/26' ) ] );
same( 'the short description', [ 'Tester Only', 'For Tester', 'Tester Only' ], [ helpers.excerpt( '{handle} Only', 'Tester' ), helpers.excerpt( 'For {handle}', 'Tester' ), helpers.excerpt( 'nothing', 'Tester' ) ] );
same( 'dateNjy(): month / day / two-digit year, no leading zeros', [ '11/3/26', '7/3/26', '12/25/26', '1/1/09', '2/29/28' ], [ helpers.dateNjy( '2026-11-03' ), helpers.dateNjy( '2026-07-03' ), helpers.dateNjy( '2026-12-25' ), helpers.dateNjy( '2009-01-01' ), helpers.dateNjy( '2028-02-29' ) ] );
same( 'not a date: nothing', [ '', '', '', '', '' ], [ helpers.dateNjy( '2026-02-30' ), helpers.dateNjy( '11/3/26' ), helpers.dateNjy( '' ), helpers.dateNjy( null ), helpers.dateNjy( '2026-13-01' ) ] );

console.log( '6. Small things' );
same( 'sprintf(): %s, %d and numbered placeholders', [ 'only 2 in shop stock, 3 on this invoice', 'b then a', 'x' ], [ helpers.sprintf( 'only %1$s in shop stock, %2$s on this invoice', 2, 3 ), helpers.sprintf( '%2$s then %1$s', 'a', 'b' ), helpers.sprintf( '%s', 'x' ) ] );
same( 'text(): no tags, one line, non-breaking spaces as spaces', 'Blue Test Tang', helpers.text( ' <i>Blue</i> Test\n Tang ' ) );
check( 'the page code does not run without jQuery and the page data', typeof helpers.money === 'function' && Object.keys( helpers ).length === 17 );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
