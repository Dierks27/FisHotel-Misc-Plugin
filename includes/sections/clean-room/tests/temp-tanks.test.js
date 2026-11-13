/**
 * Temporary tanks and the day's list — the pure client helpers of the
 * phone (js/front-desk.js) and of the Board (js/temp-tanks-admin.js):
 * what a tank is called at the top of its screen, "what is it for" → the
 * schedules carrying that word (and none for a word nobody carries), what
 * a schedule does before anything is saved, a line of the list in words
 * (the medication and the dose, the feeding of a whole building, a copper
 * flag), the count at the top, whether a move clears a group, which slots
 * a screen offers, and the Board's schedule editor rows as steps.
 *
 * The lines in words and the offers are checked against
 * fixtures/todo-lines.json — the file the server's temp-board-test.php
 * reads too, so the Board (rendered in PHP) and the phone (rendered here)
 * cannot drift apart.
 *
 * Runs both scripts without a DOM, jQuery or config: each exports its
 * helpers and skips its wiring.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/temp-tanks.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var phone   = require( path.join( __dirname, '..', 'js', 'front-desk.js' ) );
var board   = require( path.join( __dirname, '..', 'js', 'temp-tanks-admin.js' ) );
var fixture = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'todo-lines.json' ), 'utf8' ) );

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
check( 'the phone exports the temporary tanks\' and the list\'s helpers', [ 'tankHeading', 'offerTemplates', 'previewLines', 'lineDetail', 'feedText', 'flagText', 'todoHeadline', 'moveClears', 'gridTanks' ].every( function ( name ) { return typeof phone[ name ] === 'function'; } ) );
check( 'the Board\'s script exports its helpers and hangs them on the global', typeof board.offer === 'function' && typeof board.previewLines === 'function' && typeof board.stepsFromRows === 'function' && globalThis.fhCleanRoomTempHelpers === board );
check( 'the helpers the phone already had are still there', typeof phone.previewSentence === 'function' && typeof phone.saleSummary === 'function' && globalThis.fhFrontDeskHelpers === phone );

console.log( '2. What a tank is called' );
check( 'a slot: "TEMP 1 · 901"', 'TEMP 1 · 901' === phone.tankHeading( { number: 901, temp: { label: 'TEMP 1', free: false } } ) );
check( 'a free slot is called the same — the label is the slot\'s, not the setup\'s', 'TEMP 3 · 903' === phone.tankHeading( { number: 903, temp: { label: 'TEMP 3', free: true, setup: null } } ) );
check( 'a rack tank: "Tank 521"', 'Tank 521' === phone.tankHeading( { number: 521, temp: null } ) && 'Tank 521' === phone.tankHeading( { number: 521 } ) );

console.log( '3. What is it for → the schedules (fixtures/todo-lines.json)' );
var templates = fixture.offers.templates;
var names     = templates.map( function ( t ) { return t.name; } );
fixture.offers.cases.forEach( function ( c ) {
	var onPhone = phone.offerTemplates( templates, c.tag );
	var onBoard = board.offer( templates, c.tag );
	check( JSON.stringify( c.tag ) + ' → ' + JSON.stringify( c.matching ), same( c.matching, onPhone.matching ) && same( onPhone, onBoard ), JSON.stringify( onPhone ) );
	check( '  … and every other schedule can still be chosen directly', same( names.filter( function ( n ) { return c.matching.indexOf( n ) === -1; } ), onPhone.others ) );
} );
check( 'nothing is offered for a word no schedule carries', 0 === phone.offerTemplates( templates, 'fin rot' ).matching.length && 4 === phone.offerTemplates( templates, 'fin rot' ).others.length );
check( 'no templates, no word: nothing, never a crash', same( { matching: [], others: [] }, phone.offerTemplates( null, null ) ) && same( { matching: [], others: [] }, board.offer( undefined, undefined ) ) && same( { matching: [], others: [ 'Test Copper' ] }, board.offer( [ { name: 'Test Copper' } ], 'x' ) ) );

console.log( '4. What a schedule does, before anything is saved' );
var schedule = {
	name: 'Test Antibiotics',
	preview: [
		{ label: 'Test Combo A + B', kind: 'dose', when: 'day 1', day: 1, medication: 'Metronidazole', note: '' },
		{ label: 'Metronidazole', kind: 'dose', when: 'days 2, 4, 6', day: 2, medication: 'Metronidazole', note: '' },
		{ label: '25 % water change', kind: 'water_change', when: 'days 3, 5, 7', day: 3, medication: '', note: '' }
	]
};
var lines = [ 'Test Combo A + B — day 1 · Metronidazole', 'Metronidazole — days 2, 4, 6', '25 % water change — days 3, 5, 7' ];
check( 'each step with its days; the medication beside it when the label does not already say it', same( lines, phone.previewLines( schedule ) ), JSON.stringify( phone.previewLines( schedule ) ) );
check( 'the Board shows the same lines', same( lines, board.previewLines( schedule ) ) );
check( 'no treatment: no lines', same( [], phone.previewLines( null ) ) && same( [], board.previewLines( {} ) ) && same( [], phone.previewLines( { name: 'x', preview: [] } ) ) );

console.log( '5. A line of the list in words (fixtures/todo-lines.json)' );
fixture.details.forEach( function ( c, i ) {
	check( 'lineDetail #' + ( i + 1 ) + ': ' + JSON.stringify( c.text ), c.text === phone.lineDetail( c.item ), phone.lineDetail( c.item ) );
} );
check( 'lineDetail of nothing', '' === phone.lineDetail( null ) && '' === phone.lineDetail( {} ) );
fixture.feeds.forEach( function ( c, i ) {
	check( 'feedText #' + ( i + 1 ) + ': ' + JSON.stringify( c.text ), c.text === phone.feedText( c.item ), phone.feedText( c.item ) );
} );
check( 'feedText uses the page\'s strings when given', 'Feed: 2 TANKS · 1 OPEN' === phone.feedText( { label: 'Feed', count: 2, left: 1 }, { feedMany: '%1$s: %2$d TANKS', leftN: '%d OPEN' } ) );
fixture.flags.forEach( function ( c, i ) {
	check( 'flagText #' + ( i + 1 ) + ': ' + JSON.stringify( c.text ), c.text === phone.flagText( c.flag ), phone.flagText( c.flag ) );
} );

console.log( '6. The count at the top' );
check( 'things left', '7 things left to do' === phone.todoHeadline( { left: 7, total: 11 } ) && '1 thing left to do' === phone.todoHeadline( { left: 1, total: 11 } ) );
check( '"All done" when it is', 'All done' === phone.todoHeadline( { left: 0, total: 11 } ) );
check( 'nothing due is not "all done"', 'Nothing is due today' === phone.todoHeadline( { left: 0, total: 0 } ) && 'Nothing is due today' === phone.todoHeadline( null ) );
check( 'the page\'s strings are used when given', '3 OPEN' === phone.todoHeadline( { left: 3, total: 4 }, { todoLeft: '%d OPEN' } ) && 'FERTIG' === phone.todoHeadline( { left: 0, total: 4 }, { todoAllDone: 'FERTIG' } ) );

console.log( '7. A move that clears' );
var inQt    = { stayId: 1, status: 'in_qt' };
var cleared = { stayId: 2, status: 'cleared' };
check( 'a quarantine group into a clean tank: it clears', true === phone.moveClears( { clean: true, temp: true }, [ inQt ] ) );
check( 'one group of several still in quarantine is enough', true === phone.moveClears( { clean: true }, [ cleared, inQt ] ) );
check( 'a cleared group into a clean tank: nothing to say', false === phone.moveClears( { clean: true }, [ cleared ] ) );
check( 'into a quarantine tank — the Spa or a quarantine temporary tank — nothing clears', false === phone.moveClears( { clean: false, temp: true }, [ inQt ] ) && false === phone.moveClears( {}, [ inQt ] ) );
check( 'no destination, no groups: false', false === phone.moveClears( null, [ inQt ] ) && false === phone.moveClears( { clean: true }, [] ) && false === phone.moveClears( { clean: true }, null ) );

console.log( '8. The slots a screen offers' );
var temporary = { temp: true, tanks: [ { number: 901, temp: true, free: false }, { number: 902, temp: true, free: true }, { number: 903, temp: true, free: false } ] };
check( 'only the slots that are set up are tanks to pick', same( [ 901, 903 ], phone.gridTanks( temporary ).map( function ( t ) { return t.number; } ) ) );
check( 'every rack tank is offered', 2 === phone.gridTanks( { tanks: [ { number: 521 }, { number: 522, free: false } ] } ).length );
check( 'a building without tanks: none', same( [], phone.gridTanks( {} ) ) && same( [], phone.gridTanks( null ) ) );

console.log( '9. The Board\'s schedule editor: rows → steps' );
var steps = board.stepsFromRows( [
	{ label: '  Feed ', kind: 'feed', day: '1', every: '1', until: '', note: 'every day' },
	{ label: 'Test Medication', kind: 'dose', day: '2', every: '2', until: '12', note: '' },
	{ label: '   ', kind: 'dose', day: '3', every: '', until: '', note: 'no name' },
	{ label: 'Test bath', day: 'x', every: 'y', until: 'z' }
] );
check( 'a row without a name is left out', 3 === steps.length && same( [ 'Feed', 'Test Medication', 'Test bath' ], steps.map( function ( s ) { return s.label; } ) ) );
check( 'numbers are numbers; an empty "until" stays empty (the step is open-ended)', 1 === steps[0].day && 1 === steps[0].every && '' === steps[0].until && 2 === steps[1].day && 2 === steps[1].every && 12 === steps[1].until );
check( 'junk falls back: day 1, no repeat, kind "other", no note', 1 === steps[2].day && 0 === steps[2].every && '' === steps[2].until && 'other' === steps[2].kind && '' === steps[2].note );
check( 'a temporary tank is its own place: every step is "anywhere"', steps.every( function ( s ) { return 'any' === s.where; } ) );
check( 'no rows: no steps', same( [], board.stepsFromRows( [] ) ) && same( [], board.stepsFromRows( null ) ) );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
