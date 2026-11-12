/**
 * Packing admin JS — the pure helpers: the tiny sprintf, a new table row
 * from its template, the fill tool's run loop (it must end), and what a
 * button of the Packing box posts.
 *
 * Runs js/packing-admin.js without jQuery or a DOM: the file exports its
 * helpers and skips the DOM wiring when jQuery is absent.
 *
 * Run from the plugin root:  node includes/sections/packing/tests/packing-admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'packing-admin.js' ) );

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

console.log( '1. Exports' );
check( 'the helpers are exported for node', helpers && [ 'fmt', 'rowFromTemplate', 'fillStep', 'confirmPayload' ].every( function ( name ) {
	return typeof helpers[ name ] === 'function';
} ) );
check( 'and hung on the global for the browser', globalThis.fhPacking && globalThis.fhPacking.helpers === helpers );

console.log( '2. fmt()' );
check( 'placeholders in order', helpers.fmt( '%d set so far…', 12 ) === '12 set so far…' );
check( 'placeholders by position', helpers.fmt( 'Done: %1$d set (%2$d failed).', 40, 2 ) === 'Done: 40 set (2 failed).' && helpers.fmt( '%2$s before %1$s', 'a', 'b' ) === 'b before a' );
check( 'a missing argument is empty; no template is empty', helpers.fmt( '%d and %d', 1 ) === '1 and ' && helpers.fmt( null ) === '' && helpers.fmt( undefined, 1 ) === '' );
check( 'a percent sign that is not a placeholder stays', helpers.fmt( '90% full: %d', 3 ) === '90% full: 3' );

console.log( '3. rowFromTemplate()' );
var template = '<tr><td><input name="bags[__i__][name]"></td><td><input name="bags[__i__][sort]"></td></tr>';
check( 'every __i__ becomes the row number', helpers.rowFromTemplate( template, 7 ) === '<tr><td><input name="bags[7][name]"></td><td><input name="bags[7][sort]"></td></tr>' );
check( 'a number as text works; nonsense and negatives read as 0', helpers.rowFromTemplate( 'x[__i__]', '12' ) === 'x[12]' && helpers.rowFromTemplate( 'x[__i__]', 'abc' ) === 'x[0]' && helpers.rowFromTemplate( 'x[__i__]', -3 ) === 'x[0]' );
check( 'no template gives nothing', helpers.rowFromTemplate( null, 1 ) === '' && helpers.rowFromTemplate( undefined, 1 ) === '' );

console.log( '4. fillStep(): the run loop' );
var start = { set: 0, failed: 0, after: 0, steps: 0 };
var one   = helpers.fillStep( start, { success: true, data: { set: 100, failed: 0, last_id: 5150, done: false } } );
check( 'a batch adds what it set and moves the cursor; the run goes on', same( one, { set: 100, failed: 0, after: 5150, steps: 1, done: false, stopped: false } ), JSON.stringify( one ) );
var two = helpers.fillStep( one, { success: true, data: { set: 37, failed: 2, last_id: 5400, done: true } } );
check( 'the last batch ends it, with the totals', same( two, { set: 137, failed: 2, after: 5400, steps: 2, done: true, stopped: false } ), JSON.stringify( two ) );
check( 'the state that was passed in is not changed', same( start, { set: 0, failed: 0, after: 0, steps: 0 } ) && one.after === 5150 );
var stuck = helpers.fillStep( one, { success: true, data: { set: 0, failed: 0, last_id: 5150, done: false } } );
check( 'a cursor that did not move stops the run — it can never ask for the same batch for ever', stuck.stopped === true && stuck.done === false && stuck.set === 100 );
var back = helpers.fillStep( one, { success: true, data: { set: 3, failed: 0, last_id: 40, done: false } } );
check( 'nor can a cursor that went backwards; the cursor is never moved back', back.stopped === true && back.after === 5150 );
var refused = helpers.fillStep( one, { success: false, data: { message: 'This form has expired.' } } );
check( 'an answer that is not a success stops it and keeps what was set so far', refused.stopped === true && refused.set === 100 && refused.after === 5150 );
check( 'no answer at all stops it', helpers.fillStep( one, null ).stopped === true && helpers.fillStep( one, undefined ).stopped === true && helpers.fillStep( one, {} ).stopped === true );
check( 'a missing state starts from zero', same( helpers.fillStep( null, { success: true, data: { set: 1, failed: 0, last_id: 9, done: true } } ), { set: 1, failed: 0, after: 9, steps: 1, done: true, stopped: false } ) );
check( 'numbers that arrive as text are numbers', helpers.fillStep( start, { success: true, data: { set: '4', failed: '1', last_id: '77', done: false } } ).set === 4 && helpers.fillStep( start, { success: true, data: { set: '4', failed: '1', last_id: '77', done: false } } ).after === 77 );
var state = { set: 0, failed: 0, after: 0, steps: 1999 };
check( 'a run that never says done is cut off', helpers.fillStep( state, { success: true, data: { set: 1, failed: 0, last_id: 5, done: false } } ).stopped === true );
var loops = 0;
state = start;
while ( ! state.done && ! state.stopped && loops < 10000 ) {
	loops++;
	state = helpers.fillStep( state, { success: true, data: { set: 1, failed: 0, last_id: state.after + 1, done: state.after + 1 >= 25 } } );
}
check( 'a store of 25 batches takes 25 steps', loops === 25 && state.done === true && state.set === 25 );

console.log( '5. confirmPayload(): what a button of the Packing box posts' );
check( 'Confirm posts the suggestion', same( helpers.confirmPayload( 'confirm', 'suggested', 'long 20', '3' ), { op: 'confirm', choice: 'suggested' } ) );
check( '"Use this box" posts the picked box and how many', same( helpers.confirmPayload( 'confirm', 'chosen', 'long 20', '2' ), { op: 'confirm', choice: 'long 20', count: 2 } ) );
check( 'a count that is not a number, or below one, is one', helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', '' ).count === 1 && helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', '0' ).count === 1 && helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', 'x' ).count === 1 );
check( '"Use this box" with no box picked posts nothing', helpers.confirmPayload( 'confirm', 'chosen', '', '1' ) === null && helpers.confirmPayload( 'confirm', 'chosen', null, '1' ) === null && helpers.confirmPayload( 'confirm', 'chosen', undefined, '1' ) === null );
check( 'Clear and Refresh post only themselves', same( helpers.confirmPayload( 'clear', 'chosen', 'cube 8', '4' ), { op: 'clear' } ) && same( helpers.confirmPayload( 'view' ), { op: 'view' } ) );
check( 'an unknown button posts nothing', helpers.confirmPayload( 'delete-everything' ) === null && helpers.confirmPayload() === null );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
