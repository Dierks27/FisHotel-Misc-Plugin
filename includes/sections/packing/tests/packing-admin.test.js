/**
 * Packing admin JS — the pure helpers: the tiny sprintf, a new table row
 * from its template, the fill tool's run loop (it must end), what a
 * button of the Packing box posts, what a form's save may and may not do
 * to the page (a second post, another form's unsaved numbers), and when
 * the product editor's "Livestock — packed in a bag" checkbox follows the
 * product's categories — and that a box the owner clicked is stored —
 * and that the Packing box tells the document each time it is drawn again.
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
check( 'the helpers are exported for node', helpers && [ 'fmt', 'rowFromTemplate', 'fillStep', 'nowLine', 'confirmPayload', 'bagPayload', 'afterSave', 'unsavedElsewhere', 'livestockFromCategories', 'drawnAsAfterClick', 'changedDetail' ].every( function ( name ) {
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
check( 'nowLine(): the last step of a run carries the count as it is now', helpers.nowLine( { success: true, data: { set: 3, done: true, now: 'Right now: 10 variations, 10 with a Bag size of their own.' } } ) === 'Right now: 10 variations, 10 with a Bag size of their own.' );
check( 'nowLine(): a step in the middle, a reply without the line, a refusal, no reply: nothing to show', helpers.nowLine( { success: true, data: { done: false, now: 'x' } } ) === '' && helpers.nowLine( { success: true, data: { done: true } } ) === '' && helpers.nowLine( { success: true, data: { done: true, now: 7 } } ) === '' && helpers.nowLine( { success: false, data: { done: true, now: 'x' } } ) === '' && helpers.nowLine( null ) === '' && helpers.nowLine( {} ) === '' );
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
check( 'Confirm posts the suggestion — and what the button showed, so the server can refuse one that changed', same( helpers.confirmPayload( 'confirm', 'suggested', 'long 20', '3', 'Cube 12 83.3%' ), { op: 'confirm', choice: 'suggested', shown: 'Cube 12 83.3%' } ) );
check( 'a button that carries nothing posts an empty "shown" (refused, and the box is drawn again)', same( helpers.confirmPayload( 'confirm', 'suggested' ), { op: 'confirm', choice: 'suggested', shown: '' } ) && helpers.confirmPayload( 'confirm', 'suggested', '', '', null ).shown === '' );
check( '"Use this box" posts the picked box and how many', same( helpers.confirmPayload( 'confirm', 'chosen', 'long 20', '2', 'Cube 12 83.3%' ), { op: 'confirm', choice: 'long 20', count: 2 } ) );
check( 'a count that is not a number, or below one, is one', helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', '' ).count === 1 && helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', '0' ).count === 1 && helpers.confirmPayload( 'confirm', 'chosen', 'cube 8', 'x' ).count === 1 );
check( '"Use this box" with no box picked posts nothing', helpers.confirmPayload( 'confirm', 'chosen', '', '1' ) === null && helpers.confirmPayload( 'confirm', 'chosen', null, '1' ) === null && helpers.confirmPayload( 'confirm', 'chosen', undefined, '1' ) === null );
check( 'Clear and Refresh post only themselves', same( helpers.confirmPayload( 'clear', 'chosen', 'cube 8', '4' ), { op: 'clear' } ) && same( helpers.confirmPayload( 'view' ), { op: 'view' } ) );
check( 'an unknown button posts nothing', helpers.confirmPayload( 'delete-everything' ) === null && helpers.confirmPayload() === null );

console.log( '5b. bagPayload(): what a fish line\'s bag dropdown posts' );
check( 'a bag chosen by hand: the line\'s key and the bag id', same( helpers.bagPayload( '910-1-0', '3' ), { op: 'bag', line: '910-1-0', bag: '3' } ) );
check( 'Auto posts an empty bag (back to the worked-out one)', same( helpers.bagPayload( '910-1-0', '' ), { op: 'bag', line: '910-1-0', bag: '' } ) && helpers.bagPayload( '910-1-0', null ).bag === '' && helpers.bagPayload( '910-1-0', 'x' ).bag === '' );
check( 'no line, or a line that is not a key, posts nothing', helpers.bagPayload( '', '3' ) === null && helpers.bagPayload( undefined, '3' ) === null && helpers.bagPayload( '910-1', '3' ) === null && helpers.bagPayload( '<b>', '3' ) === null );

console.log( '6. afterSave(): what follows a form\'s save' );
check( 'a saved form that reloads the page keeps its button disabled until the page is back (no second post of the same rows)', same( helpers.afterSave( { success: true, data: { message: 'Boxes saved: 2 boxes.' } }, true ), { ok: true, reload: true, enable: false } ) );
check( 'a saved form that stays on the page can be saved again', same( helpers.afterSave( { success: true }, false ), { ok: true, reload: false, enable: true } ) );
check( 'a refused save reloads nothing and gives the button back', same( helpers.afterSave( { success: false, data: { message: 'Nothing was saved' } }, true ), { ok: false, reload: false, enable: true } ) && same( helpers.afterSave( null, true ), { ok: false, reload: false, enable: true } ) );

console.log( '7. unsavedElsewhere(): a save that reloads must not drop what another form holds' );
var clean = [ { dirty: false }, { dirty: false } ];
var typed = [ { dirty: false }, { dirty: true } ];
check( 'boxes saved while "how many fit" has unsaved numbers: stopped', helpers.unsavedElsewhere( typed, 0, true ) === true );
check( 'nothing typed elsewhere: goes ahead', helpers.unsavedElsewhere( clean, 0, true ) === false );
check( 'the form\'s own changes are what is being saved', helpers.unsavedElsewhere( typed, 1, true ) === false && helpers.unsavedElsewhere( [ { dirty: true } ], 0, true ) === false );
check( 'a save that does not reload drops nothing', helpers.unsavedElsewhere( [ { dirty: true }, { dirty: false } ], 1, false ) === false );
check( 'no forms, or junk: goes ahead', helpers.unsavedElsewhere( [], 0, true ) === false && helpers.unsavedElsewhere( null, 0, true ) === false && helpers.unsavedElsewhere( [ null, undefined ], 0, true ) === false );

console.log( '8. livestockFromCategories(): the checkbox of a product with no stored choice follows its categories' );
var following = { follow: true, touched: false, legacy: false };
var fishIds   = [ '31', '32' ];
check( 'a new product put in a fish category: ticked, so its Bag size shows before the first save', helpers.livestockFromCategories( following, fishIds, [ '7', '32' ] ) === true );
check( 'taken out of it again, or put in other categories only: unticked', helpers.livestockFromCategories( following, fishIds, [ '7' ] ) === false && helpers.livestockFromCategories( following, fishIds, [] ) === false );
check( 'term ids arrive as text or as numbers, with or without spaces', helpers.livestockFromCategories( following, [ 31, ' 32 ' ], [ 32 ] ) === true && helpers.livestockFromCategories( following, [ '31' ], [ ' 31' ] ) === true );
check( 'a stored choice is never changed by the categories', helpers.livestockFromCategories( { follow: false, touched: false, legacy: false }, fishIds, [ '31' ] ) === null && helpers.livestockFromCategories( { follow: false, touched: false, legacy: false }, fishIds, [] ) === null );
check( 'nor is a box the owner has ticked or unticked on this page', helpers.livestockFromCategories( { follow: true, touched: true, legacy: false }, fishIds, [ '31' ] ) === null && helpers.livestockFromCategories( { follow: true, touched: true, legacy: false }, fishIds, [] ) === null );
check( 'a product with a Bag size from before the checkbox stays ticked whatever its categories', helpers.livestockFromCategories( { follow: true, touched: false, legacy: true }, fishIds, [] ) === true && helpers.livestockFromCategories( { follow: true, touched: false, legacy: true }, fishIds, [ '7' ] ) === true );
check( 'no fish categories known (an empty list, a blank attribute): nothing is a fish by category', helpers.livestockFromCategories( following, [], [ '31' ] ) === false && helpers.livestockFromCategories( following, [ '' ], [ '' ] ) === false && helpers.livestockFromCategories( following, null, null ) === false );
check( 'no box at all: left alone', helpers.livestockFromCategories( null, fishIds, [ '31' ] ) === null && helpers.livestockFromCategories( undefined, fishIds, [ '31' ] ) === null );

console.log( '9. drawnAsAfterClick(): a box the owner clicked is a choice' );
check( 'the form then says the box was drawn the other way round, so the save stores what it is now — also when it was clicked off and on again', helpers.drawnAsAfterClick( true ) === 'no' && helpers.drawnAsAfterClick( false ) === 'yes' );

console.log( '10. The Packing box says when it was drawn again' );
check( 'the event has a name others can listen for, and carries the order', helpers.CHANGED_EVENT === 'fishotel-packing-changed' && same( helpers.changedDetail( '5001' ), { orderId: 5001 } ) && same( helpers.changedDetail( 5001 ), { orderId: 5001 } ) );
check( 'what is not an order id is 0', same( helpers.changedDetail( '' ), { orderId: 0 } ) && same( helpers.changedDetail( undefined ), { orderId: 0 } ) && same( helpers.changedDetail( 'abc' ), { orderId: 0 } ) && same( helpers.changedDetail( '-4' ), { orderId: 0 } ) && same( helpers.changedDetail( '12abc' ), { orderId: 0 } ) );

// The wiring itself, against a stand-in for jQuery: a click on a button of
// the box posts, and each answer that draws the box again tells the document.
( function () {
	var handlers = {};
	var posts    = [];
	var heard    = [];
	var drawn    = [];

	function chain( attrs ) {
		var me = {
			length: 1,
			attr: function ( name ) {
				return attrs[ name ];
			},
			val: function () {
				return '';
			},
			replaceWith: function ( html ) {
				drawn.push( html );
				return me;
			},
			on: function ( events, selector, handler ) {
				handlers[ events + ' ' + selector ] = handler;
				return me;
			},
			trigger: function ( name, args ) {
				heard.push( [ name, args ] );
				return me;
			}
		};
		[ 'closest', 'find', 'prop', 'removeClass', 'addClass', 'text', 'last', 'first', 'data', 'toggleClass' ].forEach( function ( name ) {
			me[ name ] = function () {
				return me;
			};
		} );
		return me;
	}

	var page = chain( { 'data-op': 'view', 'data-order-id': '5001', 'data-nonce': 'n' } );
	var none = chain( {} );
	none.length = 0;

	function $( what ) {
		if ( typeof what === 'function' ) {
			what();
			return page;
		}
		return what === '#fh_packs_in_bag' ? none : page;
	}
	$.post = function ( url, payload ) {
		var call = { payload: payload };
		posts.push( call );
		return {
			done: function ( fn ) {
				call.done = fn;
				return this;
			},
			fail: function ( fn ) {
				call.fail = fn;
				return this;
			}
		};
	};

	var file = path.join( __dirname, '..', 'js', 'packing-admin.js' );
	delete require.cache[ require.resolve( file ) ];
	global.jQuery   = $;
	global.document = {};
	require( file );
	delete global.jQuery;

	var click = handlers[ 'click .fh-pack-box .fh-pack-confirm' ];
	check( 'the box\'s buttons are wired', typeof click === 'function' );
	if ( typeof click !== 'function' ) {
		delete global.document;
		return;
	}
	var event = { preventDefault: function () {} };

	click.call( {}, event );
	check( 'a click posts the order of the box', posts.length === 1 && posts[ 0 ].payload.order_id === '5001' && posts[ 0 ].payload.op === 'view' );
	check( 'nothing is said before the answer is there', heard.length === 0 );
	posts[ 0 ].done( { success: true, data: { html: '<div>new</div>' } } );
	check( 'the answer draws the box again and tells the document, with the order', same( drawn, [ '<div>new</div>' ] ) && same( heard, [ [ 'fishotel-packing-changed', [ { orderId: 5001 } ] ] ] ), JSON.stringify( heard ) );

	click.call( {}, event );
	posts[ 1 ].done( { success: false, data: { message: 'No.' } } );
	check( 'an answer that draws nothing says nothing', drawn.length === 1 && heard.length === 1 );

	click.call( {}, event );
	posts[ 2 ].fail( { responseJSON: { data: { html: '<div>as it stands</div>' } } } );
	check( 'a refusal that hands the box back as it stands now is a redraw too', drawn.length === 2 && heard.length === 2 && heard[ 1 ][ 0 ] === 'fishotel-packing-changed' );

	click.call( {}, event );
	posts[ 3 ].fail( null );
	check( 'a failed post is not', drawn.length === 2 && heard.length === 2 );
	delete global.document;
}() );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
