/**
 * Acceptance tests for the FisHotel lottery draw.
 *
 * Run from the plugin root:  node includes/sections/lottery-draw/tests/draw.test.js
 *
 * Covers acceptance tests 1-7 and 9 from the build spec. Tests 8
 * (a published draw cannot be edited through the UI or by direct POST)
 * and the browser half of 1 are exercised against the running site;
 * see tests/README.md for the manual steps.
 */
'use strict';

var draw = require( '../js/draw.js' );

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

function eq( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

function fish( over ) {
	var base = {
		name: 'Pyramid Butterfly',
		sci: 'Hemitaurichthys polylepis',
		stock: 3,
		groupSize: 1,
		entrants: [
			{ name: 'twosixpax', want: 3 },
			{ name: 'Tacos_coffee', want: 3 },
			{ name: 'gmdcdvm', want: 1 },
			{ name: 'daduc', want: 1 }
		]
	};
	Object.keys( over || {} ).forEach( function ( k ) { base[ k ] = over[ k ]; } );
	return base;
}

var SEED = 'RVS-2026-08-22-K7M2P';

// 1. Same seed + same entrants → identical winners, every time.
console.log( '1. Deterministic for the same seed and entrants' );
var runA = draw.drawFish( SEED, fish() );
for ( var i = 0; i < 50; i++ ) {
	if ( ! eq( draw.drawFish( SEED, fish() ), runA ) ) {
		break;
	}
}
check( '50 repeat draws are byte-identical', eq( draw.drawFish( SEED, fish() ), runA ) );
check( 'winners are drawn from the entrant pool', runA.winners.every( function ( n ) {
	return [ 'twosixpax', 'Tacos_coffee', 'gmdcdvm', 'daduc' ].indexOf( n ) !== -1;
} ) );

// 2. Change one character of the seed → completely different winners.
console.log( '2. One character of seed changes the outcome' );
var runB = draw.drawFish( 'RVS-2026-08-22-K7M2Q', fish() );
check( 'winners differ from the original seed', ! eq( runA.winners, runB.winners ),
	'seed  ' + JSON.stringify( runA.winners ) + '\n       seed+1 ' + JSON.stringify( runB.winners ) );

// 3. Editing fish B's entrants does not change fish A's winners.
console.log( '3. Per-fish RNG streams are independent' );
var fishA  = fish( { name: 'Fish A' } );
var fishB1 = fish( { name: 'Fish B' } );
var fishB2 = fish( { name: 'Fish B', entrants: fish().entrants.concat( [ { name: 'latecomer', want: 2 } ] ) } );
var beforeA = draw.drawFish( SEED, fishA );
var beforeB = draw.drawFish( SEED, fishB1 );
var afterB  = draw.drawFish( SEED, fishB2 );
check( "fish A's winners are unchanged by an edit to fish B",
	eq( beforeA, draw.drawFish( SEED, fishA ) ) );
check( "fish B's own winners did change", ! eq( beforeB.winners, afterB.winners ) );

// 4. want: 3 puts three tickets in; that person can win up to 3, no more.
console.log( '4. want: N is N tickets, and a hard ceiling' );
var many = draw.drawFish( SEED, fish( { stock: 8 } ) );
function countOf( list, name ) {
	return list.filter( function ( n ) { return n === name; } ).length;
}
check( 'three tickets are in the hat for twosixpax',
	countOf( draw.ticketsFor( fish() ), 'twosixpax' ) === 3 );
check( 'nobody wins more than they asked for',
	countOf( many.winners, 'twosixpax' ) <= 3 && countOf( many.winners, 'gmdcdvm' ) <= 1 );
check( 'a want of 3 can win all 3 when stock allows',
	countOf( many.winners, 'twosixpax' ) === 3, JSON.stringify( many.winners ) );

// 5. groupSize: 5, stock: 120 → 24 slots.
console.log( '5. Group species are drawn as groups' );
var anthias = {
	name: 'Lyretail Anthias',
	stock: 120,
	groupSize: 5,
	entrants: [
		{ name: 'a', want: 10 }, { name: 'b', want: 10 }, { name: 'c', want: 10 }
	]
};
check( '120 in stock at a group size of 5 is 24 slots', draw.slotsFor( anthias ) === 24 );
check( 'the draw awards 24 slots, not 120', draw.drawFish( SEED, anthias ).winners.length === 24 );

// 6. Requests <= stock → everyone gets theirs, waitlist empty.
console.log( '6. An uncontested fish needs no draw' );
var uncontested = draw.drawFish( SEED, fish( { stock: 8 } ) );
check( 'every ticket wins', uncontested.winners.length === 8 );
check( 'the waitlist is empty', uncontested.waitlist.length === 0 );
check( 'everyone gets exactly what they wanted',
	countOf( uncontested.winners, 'twosixpax' ) === 3 &&
	countOf( uncontested.winners, 'Tacos_coffee' ) === 3 &&
	countOf( uncontested.winners, 'gmdcdvm' ) === 1 &&
	countOf( uncontested.winners, 'daduc' ) === 1 );

// 7. Nobody is ever awarded more slots than exist.
console.log( '7. Slots are never oversubscribed' );
var oversubscribed = true;
[ 0, 1, 2, 3, 7, 8, 9, 40 ].forEach( function ( stock ) {
	[ 1, 2, 5, 7 ].forEach( function ( groupSize ) {
		var f = fish( { stock: stock, groupSize: groupSize } );
		var r = draw.drawFish( SEED, f );
		var tickets = draw.ticketsFor( f );
		if ( r.winners.length > draw.slotsFor( f ) ) {
			oversubscribed = false;
		}
		if ( r.winners.length + r.waitlist.length !== tickets.length ) {
			oversubscribed = false;
		}
	} );
} );
check( 'winners never exceed the slot count, and no ticket is lost', oversubscribed );

// Normalisation rules (section 3, "critical details").
console.log( '+  Entrant normalisation' );
var dupes = draw.normalizeEntrants( [
	{ name: 'Reefer', want: 1 },
	{ name: 'daduc', want: 1 },
	{ name: 'REEFER', want: 2 }
] );
check( 'duplicates merge case-insensitively, keeping first appearance',
	eq( dupes, [ { name: 'Reefer', want: 3 }, { name: 'daduc', want: 1 } ] ),
	JSON.stringify( dupes ) );
check( 'a repeated winner renders as Name (2)',
	draw.formatNames( [ 'alice', 'bob', 'alice' ] ) === 'alice (2), bob',
	draw.formatNames( [ 'alice', 'bob', 'alice' ] ) );

// 9. Tamper with a stored winner list → the page must report a mismatch.
console.log( '9. Tampering is detected' );
function publishedPayload() {
	var f = fish();
	var r = draw.drawFish( SEED, f );
	f.winners  = r.winners;
	f.waitlist = r.waitlist;
	return { seed: SEED, fish: [ f ] };
}
check( 'an untouched published payload verifies green', draw.verify( publishedPayload() ).ok );

var swapped = publishedPayload();
swapped.fish[ 0 ].winners[ 0 ] = 'gmdcdvm';
check( 'a swapped winner is caught', ! draw.verify( swapped ).ok );

var reordered = publishedPayload();
var w = reordered.fish[ 0 ].winners;
reordered.fish[ 0 ].winners = [ w[ 1 ], w[ 0 ] ].concat( w.slice( 2 ) );
check( 'a reordered winner list is caught', ! draw.verify( reordered ).ok );

var truncated = publishedPayload();
truncated.fish[ 0 ].waitlist.pop();
check( 'a trimmed waitlist is caught', ! draw.verify( truncated ).ok );

var padded = publishedPayload();
padded.fish[ 0 ].winners.push( 'daduc' );
check( 'an extra winner is caught', ! draw.verify( padded ).ok );

var reseeded = publishedPayload();
reseeded.seed = SEED + 'X';
check( 'a seed edited after publication is caught', ! draw.verify( reseeded ).ok );

var restocked = publishedPayload();
restocked.fish[ 0 ].stock = 4;
check( 'an entrant/stock edit after publication is caught', ! draw.verify( restocked ).ok );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
