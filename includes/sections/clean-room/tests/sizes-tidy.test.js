/**
 * Tidy up the sizes — the page's helpers (js/sizes-tidy.js), without a
 * DOM or jQuery: which rows count as set by hand, the fields of a
 * request, and the loop that steps a run — to the end, through a failed
 * step that is asked again, past another click that holds the lock, and
 * stopping (so "Carry on" can pick it up) when the steps keep failing.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/sizes-tidy.test.js
 */
'use strict';

var path = require( 'path' );
var tidy = require( path.join( __dirname, '..', 'js', 'sizes-tidy.js' ) );

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

/**
 * A server that answers the steps from a script: a reply, or an Error to fail with.
 */
function server( script ) {
	var calls = [];
	var post  = function ( fields ) {
		calls.push( fields );
		var next = script.shift();
		return next instanceof Error ? Promise.reject( next ) : Promise.resolve( next );
	};
	post.calls = calls;
	return post;
}

function step( done, total, over ) {
	return { success: true, data: { over: !! over, message: done + ' of ' + total, progress: { why: '', done: done, total: total } } };
}

var noWait = function () {
	return Promise.resolve();
};

console.log( '1. The rows set by hand' );
check( 'a row left as the page showed it is not sent', same( tidy.overrides( [ { key: 'g:3-5', value: 'to:3-4', shown: 'to:3-4', yours: '0' } ] ), {} ) );
check( 'a row the owner changed is', same( tidy.overrides( [ { key: 'g:3-5', value: 'to:4-5', shown: 'to:3-4', yours: '0' } ] ), { 'g:3-5': 'to:4-5' } ) );
check( 'a row set by hand before stays set, even when it reads like the suggestion', same( tidy.overrides( [ { key: 'g:6', value: 'to:5', shown: 'to:5', yours: '1' } ] ), { 'g:6': 'to:5' } ) );
check( 'rows without a key, and no rows at all', same( tidy.overrides( [ { value: 'keep', shown: 'remove' }, null ] ), {} ) && same( tidy.overrides(), {} ) );

console.log( '2. The fields of a request' );
var sent = tidy.fields(
	[ { name: 'short', value: '1-2"\n2-3"' }, { name: 'instock', value: 'move' }, { name: 'move[12]', value: '' }, { name: 'move[13]', value: 'medium' }, { name: 'keep_one[5][ab]', value: '0' }, { name: 'keep_one[6][cd]', value: '77' }, { name: 'convert[9]', value: '1' }, { name: 'token', value: 'abc' } ],
	{ 'g:3-5': 'to:4-5' },
	{ op: 'preview' }
);
check( 'the op, the form\'s fields and the rows set by hand — what Apply cannot do without first', same( sent, { 'op': 'preview', 'short': '1-2"\n2-3"', 'token': 'abc', 'instock': 'move', 'move[13]': 'medium', 'keep_one[6][cd]': '77', 'convert[9]': '1', 'over[g:3-5]': 'to:4-5' } ), JSON.stringify( sent ) );
check( 'an empty pick and "leave this fish alone" are no choice', ! ( 'move[12]' in sent ) && ! ( 'keep_one[5][ab]' in sent ) );

console.log( '3. Stepping a run' );
var seen = [];
var post = server( [ step( 1, 4 ), step( 2, 4 ), step( 3, 4 ), step( 4, 4, true ) ] );
tidy.steps( post, 7, function ( data ) {
	seen.push( data.message );
}, { wait: noWait } ).then( function ( last ) {
	check( 'it asks for one step after another until the run is over', 4 === post.calls.length && same( post.calls[ 0 ], { op: 'step', run: 7 } ) && true === last.over );
	check( 'and says how far it is each time', same( seen, [ '1 of 4', '2 of 4', '3 of 4', '4 of 4' ] ) );

	post = server( [ step( 1, 3 ), new Error( 'timeout' ), { success: false, data: { message: 'oops' } }, step( 2, 3 ), step( 3, 3, true ) ] );
	return tidy.steps( post, 7, null, { wait: noWait } );
} ).then( function ( last ) {
	check( 'a step that fails (a timeout, an error reply) is asked again, and the run still finishes', 5 === post.calls.length && true === last.over );

	var waits = 0;
	post      = server( [ { success: true, data: { over: false, message: 'waiting', progress: { why: 'busy' } } }, step( 3, 3, true ) ] );
	return tidy.steps( post, 7, null, {
		wait: function () {
			waits++;
			return Promise.resolve();
		}
	} ).then( function ( done ) {
		check( 'another click holds the lock: it waits a moment and asks again', 1 === waits && 2 === post.calls.length && true === done.over );
	} );
} ).then( function () {
	post = server( [ step( 1, 9 ), new Error( 'a' ), new Error( 'b' ), new Error( 'c' ), step( 2, 9 ) ] );
	return tidy.steps( post, 7, null, { wait: noWait } ).then(
		function () {
			check( 'steps that keep failing stop the loop', false );
		},
		function () {
			check( 'steps that keep failing stop the loop after three tries — "Carry on" picks it up later', 4 === post.calls.length );
		}
	);
} ).then( function () {
	post = server( [ new Error( 'a' ), new Error( 'b' ), step( 1, 2 ), new Error( 'c' ), new Error( 'd' ), step( 2, 2, true ) ] );
	return tidy.steps( post, 7, null, { wait: noWait } );
} ).then( function ( last ) {
	check( 'a step that works resets the count of failures', 6 === post.calls.length && true === last.over );
	console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
	process.exit( failures ? 1 : 0 );
} ).catch( function ( error ) {
	console.log( '  FAIL the test itself threw: ' + ( error && error.stack ? error.stack : error ) );
	process.exit( 1 );
} );
