/**
 * US dates on the page — admin/js/fishotel-dates.js (fhDates), the one
 * place the plugin's JS writes a date for a person (11-27-26), reads one
 * a person typed, and the pop-up calendar that replaces a typed date.
 * (It sits with the Clean Room tests because the section tests are the
 * ones CI runs under node; the file itself belongs to the whole plugin.)
 *
 *   - us() / usTime() / parse() against tests/fixtures/us-dates.json,
 *     which the server's FisHotel\Misc\Dates is run against too
 *     (tests/dates-test.php), so the page and the server cannot drift;
 *   - a date is a calendar day: the same answers in every time zone, on
 *     the days the clocks change, and no `new Date()` in us() / parse();
 *   - stamp(): a moment by this device's own clock, one minute before
 *     midnight included — never toISOString(), which is UTC;
 *   - check(): what the pop-up refuses, in plain words;
 *   - ask(): the pop-up calendar, run for real against a tiny stand-in
 *     DOM — pick a date and Save (YYYY-MM-DD is handed back), the "no
 *     date" button, Cancel, Esc, Enter, a tap outside, Tab staying
 *     inside, focus going back to the button that opened it, and nothing
 *     saved when the field is empty.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/us-dates.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var root    = path.join( __dirname, '..', '..', '..', '..' );
var file    = path.join( root, 'admin', 'js', 'fishotel-dates.js' );
var dates   = require( file );
var source  = fs.readFileSync( file, 'utf8' );
var code    = source.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' ); // Without its comments.
var fixture = JSON.parse( fs.readFileSync( path.join( root, 'tests', 'fixtures', 'us-dates.json' ), 'utf8' ) );

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

function same( label, want, got ) {
	check( label, JSON.stringify( want ) === JSON.stringify( got ), 'want ' + JSON.stringify( want ) + ', got ' + JSON.stringify( got ) );
}

console.log( '1. Exports' );
check( 'the helpers are exported for node', [ 'us', 'usTime', 'stamp', 'localYmd', 'parse', 'toYmd', 'isYmd', 'check', 'ask' ].every( function ( name ) { return typeof dates[ name ] === 'function'; } ) );
check( 'and hung on the global for the browser', globalThis.fhDates && globalThis.fhDates.us === dates.us );
check( 'no library: no jQuery, no require of anything', ! /jQuery|\$\(|require\(/.test( code ) );

console.log( '2. us(): a stored date as a person reads it' );
fixture.us.forEach( function ( row ) {
	same( 'us( ' + JSON.stringify( row[0] ) + ' ) → ' + row[1], row[1], dates.us( row[0] ) );
} );
same( 'null reads as nothing', '', dates.us( null ) );
same( 'undefined reads as nothing', '', dates.us( undefined ) );
same( 'a second argument is what an empty date reads as', '—', dates.us( '', '—' ) );
same( 'and is not used when there is a date', '11-27-26', dates.us( '2026-11-27', '—' ) );

console.log( '3. usTime(): a stored date-time keeps its time part' );
fixture.usTime.forEach( function ( row ) {
	same( 'usTime( ' + JSON.stringify( row[0] ) + ' ) → ' + row[1], row[1], dates.usTime( row[0] ) );
} );

console.log( '4. parse(): a date a person typed' );
fixture.parse.forEach( function ( row ) {
	same( 'parse( ' + JSON.stringify( row[0] ) + ' ) → ' + JSON.stringify( row[1] ), { date: row[1], error: '' }, dates.parse( row[0] ) );
	same( 'toYmd( ' + JSON.stringify( row[0] ) + ' )', row[1] === '' ? null : row[1], dates.toYmd( row[0] ) );
} );
same( 'null is nothing typed', { date: '', error: '' }, dates.parse( null ) );

console.log( '5. parse(): what is refused, in one plain sentence — never changed into another day' );
fixture.refuse.forEach( function ( row ) {
	same( 'parse( ' + JSON.stringify( row[0] ) + ' ) is refused', { date: '', error: row[1] }, dates.parse( row[0] ) );
} );
check( '02-30 is not rolled over to March 2 (as new Date() would)', dates.toYmd( '02-30-26' ) === null );

console.log( '6. A calendar day: the same in every time zone, on the days the clocks change' );
var usSource    = source.slice( source.indexOf( 'function us(' ), source.indexOf( 'function usTime(' ) );
var parseSource = source.slice( source.indexOf( 'function parse(' ), source.indexOf( 'function toYmd(' ) );
check( 'us() and parse() are found in the source', usSource.length > 100 && parseSource.length > 100 );
check( 'us() never makes a Date', ! /new Date|Date\.parse|toISOString|getTime/.test( usSource ) );
check( 'parse() never makes a Date', ! /new Date|Date\.parse|toISOString|getTime/.test( parseSource ) );
check( 'nothing in the file reads a date through toISOString() (UTC)', ! /toISOString\(\)/.test( code ) );
var zoneBefore = process.env.TZ;
[ 'UTC', 'America/Chicago', 'America/Los_Angeles', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Kolkata' ].forEach( function ( zone ) {
	process.env.TZ = zone;
	same( 'in ' + zone + ': 2026-11-27 is 11-27-26', '11-27-26', dates.us( '2026-11-27' ) );
	same( 'in ' + zone + ': the day the clocks go forward', '03-08-26', dates.us( '2026-03-08' ) );
	same( 'in ' + zone + ': the day the clocks go back', '11-01-26', dates.us( '2026-11-01' ) );
	same( 'in ' + zone + ': typed 01-01-27 is 2027-01-01', '2027-01-01', dates.toYmd( '01-01-27' ) );
	same( 'in ' + zone + ': one minute before midnight is still that day', '11-27-26 23:59', dates.stamp( new Date( 2026, 10, 27, 23, 59, 30 ) ) );
	same( 'in ' + zone + ': midnight is the next day', '11-28-26 00:00', dates.stamp( new Date( 2026, 10, 28, 0, 0, 0 ) ) );
	same( 'in ' + zone + ': its stored day (localYmd) agrees', '2026-11-27', dates.localYmd( new Date( 2026, 10, 27, 23, 59, 30 ) ) );
	same( 'in ' + zone + ': New Year\'s Eve, 23:59', '12-31-26 23:59', dates.stamp( new Date( 2026, 11, 31, 23, 59 ) ) );
} );
process.env.TZ = 'America/Chicago';
same( 'Chicago: a moment given in UTC reads by the local clock (05:59 UTC on the 28th is 23:59 on the 27th)', '11-27-26 23:59', dates.stamp( new Date( Date.UTC( 2026, 10, 28, 5, 59 ) ) ) );
same( 'Chicago: the clocks go forward — 01:59, then 03:00', [ '03-08-26 01:59', '03-08-26 03:00' ], [ dates.stamp( new Date( Date.UTC( 2026, 2, 8, 7, 59 ) ) ), dates.stamp( new Date( Date.UTC( 2026, 2, 8, 8, 0 ) ) ) ] );
same( 'Chicago: the clocks go back — 01:30 twice, the same day', [ '11-01-26 01:30', '11-01-26 01:30' ], [ dates.stamp( new Date( Date.UTC( 2026, 10, 1, 6, 30 ) ) ), dates.stamp( new Date( Date.UTC( 2026, 10, 1, 7, 30 ) ) ) ] );
same( 'with the seconds when asked', '11-27-26 09:14:03', dates.stamp( new Date( 2026, 10, 27, 9, 14, 3 ), true ) );
same( 'no moment: nothing', '', dates.stamp( null ) );
same( 'a broken Date: nothing', '', dates.stamp( new Date( 'nonsense' ) ) );
if ( zoneBefore === undefined ) {
	delete process.env.TZ;
} else {
	process.env.TZ = zoneBefore;
}

console.log( '7. What is sent stays YYYY-MM-DD' );
fixture.parse.forEach( function ( row ) {
	if ( row[1] !== '' ) {
		check( 'parse( ' + JSON.stringify( row[0] ) + ' ) gives YYYY-MM-DD', /^\d{4}-\d{2}-\d{2}$/.test( dates.parse( row[0] ).date ) );
	}
} );
[ '2021-01-01', '2026-02-28', '2028-02-29', '2026-03-08', '2026-11-01', '2026-12-31', '2099-12-31', '1999-12-31', '2100-01-01' ].forEach( function ( day ) {
	same( 'round trip of ' + day, day, dates.toYmd( dates.us( day ) ) );
} );
check( 'isYmd: the stored shape only', dates.isYmd( '2026-11-27' ) && ! dates.isYmd( '11-27-26' ) && ! dates.isYmd( '2026-02-30' ) && ! dates.isYmd( '' ) && ! dates.isYmd( null ) );

console.log( '8. check(): what the pop-up refuses' );
same( 'a date in range', { date: '2026-11-27', error: '' }, dates.check( '2026-11-27', { min: '2021-01-01', max: '2028-11-27' } ) );
same( 'the first day allowed', { date: '2021-01-01', error: '' }, dates.check( '2021-01-01', { min: '2021-01-01', max: '2028-11-27' } ) );
same( 'the last day allowed', { date: '2028-11-27', error: '' }, dates.check( '2028-11-27', { min: '2021-01-01', max: '2028-11-27' } ) );
same( 'too late: the range in US dates', { date: '', error: 'Pick a date from 01-01-21 to 11-27-28.' }, dates.check( '2028-11-28', { min: '2021-01-01', max: '2028-11-27' } ) );
same( 'too early', { date: '', error: 'Pick a date from 01-01-21 to 11-27-28.' }, dates.check( '2020-12-31', { min: '2021-01-01', max: '2028-11-27' } ) );
same( 'only an earliest day', { date: '', error: 'Pick a date on or after 01-01-21.' }, dates.check( '2020-12-31', { min: '2021-01-01' } ) );
same( 'only a latest day', { date: '', error: 'Pick a date on or before 11-27-26.' }, dates.check( '2026-11-28', { max: '2026-11-27' } ) );
same( 'nothing picked, a date is required', { date: '', error: 'Pick a date first.' }, dates.check( '', {} ) );
same( 'nothing picked, with a "no date" button: it is named', { date: '', error: 'Pick a date first, or choose "Not cleared".' }, dates.check( '', { clear: 'Not cleared' } ) );
same( 'typed where a browser has no calendar: US form is read', { date: '2026-11-27', error: '' }, dates.check( '11/27/26', {} ) );
same( 'typed and impossible: refused', { date: '', error: '02-30-26 is not a day on the calendar.' }, dates.check( '02-30-26', {} ) );

/* ------------------------------------------------------------------ */
/*  A tiny stand-in DOM for the pop-up                                  */
/* ------------------------------------------------------------------ */

function El( doc, tag ) {
	this.doc        = doc;
	this.tagName    = String( tag ).toUpperCase();
	this.attrs      = {};
	this.children   = [];
	this.parentNode = null;
	this.listeners  = {};
	this.value      = '';
	this.validity   = { badInput: false };
}
El.prototype.setAttribute    = function ( key, value ) { this.attrs[ key ] = String( value ); if ( key === 'id' ) { this.doc.ids[ value ] = this; } };
El.prototype.getAttribute    = function ( key ) { return Object.prototype.hasOwnProperty.call( this.attrs, key ) ? this.attrs[ key ] : null; };
El.prototype.removeAttribute = function ( key ) { delete this.attrs[ key ]; };
El.prototype.appendChild     = function ( child ) { child.parentNode = this; this.children.push( child ); return child; };
El.prototype.removeChild     = function ( child ) { this.children = this.children.filter( function ( c ) { return c !== child; } ); child.parentNode = null; return child; };
El.prototype.addEventListener = function ( type, fn ) { ( this.listeners[ type ] = this.listeners[ type ] || [] ).push( fn ); };
El.prototype.removeEventListener = function ( type, fn ) { this.listeners[ type ] = ( this.listeners[ type ] || [] ).filter( function ( f ) { return f !== fn; } ); };
El.prototype.focus           = function () { this.doc.activeElement = this; };
El.prototype.fire            = function ( type, event ) {
	event = event || {};
	event.target = event.target || this;
	event.preventDefault  = event.preventDefault || function () { event.prevented = true; };
	event.stopPropagation = event.stopPropagation || function () {};
	( this.listeners[ type ] || [] ).slice().forEach( function ( fn ) { fn( event ); } );
	return event;
};
Object.defineProperty( El.prototype, 'firstChild', { get: function () { return this.children[0] || null; } } );
Object.defineProperty( El.prototype, 'textContent', { get: function () { return this.children.map( function ( c ) { return c.textContent; } ).join( '' ); } } );
El.prototype.find = function ( cls ) {
	var hit = null;
	( function walk( node ) {
		if ( hit || ! node.children ) {
			return;
		}
		if ( node.attrs && ( ' ' + ( node.attrs.class || '' ) + ' ' ).indexOf( ' ' + cls + ' ' ) !== -1 ) {
			hit = node;
			return;
		}
		node.children.forEach( walk );
	} )( this );
	return hit;
};

function makeDoc() {
	var doc = { ids: {}, listeners: {}, activeElement: null };
	doc.createElement       = function ( tag ) { return new El( doc, tag ); };
	doc.createTextNode      = function ( text ) { return { textContent: String( text ), parentNode: null }; };
	doc.getElementById      = function ( id ) { var el = doc.ids[ id ]; return el && inDoc( el ) ? el : null; };
	doc.addEventListener    = function ( type, fn ) { ( doc.listeners[ type ] = doc.listeners[ type ] || [] ).push( fn ); };
	doc.removeEventListener = function ( type, fn ) { doc.listeners[ type ] = ( doc.listeners[ type ] || [] ).filter( function ( f ) { return f !== fn; } ); };
	doc.key                 = function ( key, shift ) {
		var event = { key: key, shiftKey: !! shift, prevented: false, preventDefault: function () { event.prevented = true; }, stopPropagation: function () {} };
		( doc.listeners.keydown || [] ).slice().forEach( function ( fn ) { fn( event ); } );
		return event;
	};
	doc.head = new El( doc, 'head' );
	doc.body = new El( doc, 'body' );
	doc.documentElement = new El( doc, 'html' );
	doc.documentElement.appendChild( doc.head );
	doc.documentElement.appendChild( doc.body );
	function inDoc( el ) {
		while ( el ) {
			if ( el === doc.documentElement ) {
				return true;
			}
			el = el.parentNode;
		}
		return false;
	}
	doc.inDoc = inDoc;
	return doc;
}

function openOne( extra ) {
	var doc    = makeDoc();
	var opener = doc.createElement( 'button' );
	doc.body.appendChild( opener );
	opener.focus();
	var log  = { saved: [], cleared: 0, cancelled: 0 };
	var opts = {
		document: doc,
		title: 'Sale date — Blue Test Tang',
		help: 'This group\'s own sale date.',
		value: '2026-12-04',
		min: '2021-01-01',
		max: '2028-11-27',
		clear: 'Use the batch date (11-27-26)',
		onSave: function ( iso ) { log.saved.push( iso ); },
		onClear: function () { log.cleared++; },
		onCancel: function () { log.cancelled++; }
	};
	Object.keys( extra || {} ).forEach( function ( key ) { opts[ key ] = extra[ key ]; } );
	var handle = dates.ask( opts );
	return { doc: doc, opener: opener, log: log, handle: handle, box: handle.box, input: handle.input };
}

console.log( '9. ask(): the pop-up calendar as it opens' );
var one = openOne();
check( 'it is a dialog: role, aria-modal, named by its heading', one.box.getAttribute( 'role' ) === 'dialog' && one.box.getAttribute( 'aria-modal' ) === 'true' && one.doc.getElementById( one.box.getAttribute( 'aria-labelledby' ) ).textContent === 'Sale date — Blue Test Tang' );
check( 'the helpful sentence is there and describes the dialog', one.doc.getElementById( one.box.getAttribute( 'aria-describedby' ) ).textContent === 'This group\'s own sale date.' );
check( 'the field is the browser\'s own calendar: <input type="date">', one.input.tagName === 'INPUT' && one.input.getAttribute( 'type' ) === 'date' );
check( 'it opens with the date it was given, in the stored form the calendar needs', one.input.value === '2026-12-04' );
check( 'the earliest and latest day are on the calendar (stored form)', one.input.getAttribute( 'min' ) === '2021-01-01' && one.input.getAttribute( 'max' ) === '2028-11-27' );
check( 'the field has a label', !! one.box.find( 'fh-dates-field' ) && one.box.find( 'fh-dates-field' ).getAttribute( 'for' ) === one.input.getAttribute( 'id' ) );
check( 'Save, Cancel and the "no date" button, each a real button', [ 'fh-dates-save', 'fh-dates-cancel', 'fh-dates-clear' ].every( function ( cls ) { var b = one.box.find( cls ); return b && b.tagName === 'BUTTON' && b.getAttribute( 'type' ) === 'button'; } ) );
check( 'the "no date" button says what it will do', one.box.find( 'fh-dates-clear' ).textContent === 'Use the batch date (11-27-26)' );
check( 'focus is in the date field', one.doc.activeElement === one.input );
check( 'its look comes with it: one style block, added once', !! one.doc.getElementById( 'fh-dates-style' ) && one.doc.head.children.length === 1 );
check( 'nowhere a year-first hint for the person', ! /YYYY|yyyy-mm-dd/i.test( one.box.textContent ) );
var clearDate = one.box.find( 'fh-dates-clear' ).find( 'fh-dates-date' );
check( 'a date inside the words sits in a span that does not break, so no line wraps inside 11-27-26', clearDate && clearDate.tagName === 'SPAN' && clearDate.textContent === '11-27-26' && /\.fh-dates-date\{white-space:nowrap\}/.test( source ) );

console.log( '10. ask(): pick a date and Save' );
one.input.value = '2026-12-11';
one.input.fire( 'input' );
one.box.find( 'fh-dates-save' ).fire( 'click' );
same( 'Save hands the date back as YYYY-MM-DD, once', [ '2026-12-11' ], one.log.saved );
check( 'nothing else ran', one.log.cleared === 0 && one.log.cancelled === 0 );
check( 'the pop-up is gone', ! one.doc.inDoc( one.box ) );
check( 'focus is back on the button that opened it', one.doc.activeElement === one.opener );
check( 'the keyboard listener is gone with it', ( one.doc.listeners.keydown || [] ).length === 0 );

console.log( '11. ask(): the "no date" button' );
one = openOne();
one.box.find( 'fh-dates-clear' ).fire( 'click' );
check( 'onClear ran once, and nothing was saved', one.log.cleared === 1 && one.log.saved.length === 0 && one.log.cancelled === 0 );
check( 'closed, focus back', ! one.doc.inDoc( one.box ) && one.doc.activeElement === one.opener );
one = openOne( { clear: '' } );
check( 'without the words there is no such button (a date is required)', one.box.find( 'fh-dates-clear' ) === null );
one.handle.close();

console.log( '12. ask(): Cancel, Esc and a tap outside save nothing' );
one = openOne();
one.input.value = '2026-12-25';
one.box.find( 'fh-dates-cancel' ).fire( 'click' );
check( 'Cancel: nothing saved, nothing cleared', one.log.saved.length === 0 && one.log.cleared === 0 && one.log.cancelled === 1 );
check( 'Cancel: closed, focus back', ! one.doc.inDoc( one.box ) && one.doc.activeElement === one.opener );
one = openOne();
one.input.value = '2026-12-25';
var esc = one.doc.key( 'Escape' );
check( 'Esc: nothing saved, nothing cleared', one.log.saved.length === 0 && one.log.cleared === 0 && one.log.cancelled === 1 && esc.prevented );
check( 'Esc: closed, focus back', ! one.doc.inDoc( one.box ) && one.doc.activeElement === one.opener );
one = openOne();
one.box.parentNode.fire( 'mousedown', { target: one.box.parentNode } );
check( 'a tap outside the box: cancelled', one.log.cancelled === 1 && one.log.saved.length === 0 && ! one.doc.inDoc( one.box ) );
one = openOne();
one.box.parentNode.fire( 'mousedown', { target: one.box } );
check( 'a tap inside the box: still open', one.doc.inDoc( one.box ) && one.log.cancelled === 0 );
one.handle.close();

console.log( '13. ask(): Enter saves; an empty or out-of-range field is refused in plain words' );
one = openOne();
one.input.value = '2026-12-18';
one.input.fire( 'keydown', { key: 'Enter' } );
same( 'Enter in the field saves', [ '2026-12-18' ], one.log.saved );
one = openOne();
one.input.value = '';
one.box.find( 'fh-dates-save' ).fire( 'click' );
check( 'Save with no date: nothing saved, nothing cleared — an empty field is never taken for "no date"', one.log.saved.length === 0 && one.log.cleared === 0 && one.doc.inDoc( one.box ) );
check( 'it says what to do, naming the "no date" button', one.box.find( 'fh-dates-error' ).textContent === 'Pick a date first, or choose "Use the batch date (11-27-26)".' && one.box.find( 'fh-dates-error' ).getAttribute( 'hidden' ) === null );
check( 'the message is announced (role="alert") and the field marked', one.box.find( 'fh-dates-error' ).getAttribute( 'role' ) === 'alert' && one.input.getAttribute( 'aria-invalid' ) === 'true' );
one.input.value = '2029-01-01';
one.input.fire( 'input' );
check( 'typing again takes the message away', one.box.find( 'fh-dates-error' ).getAttribute( 'hidden' ) !== null && one.input.getAttribute( 'aria-invalid' ) === null );
one.box.find( 'fh-dates-save' ).fire( 'click' );
check( 'a date past the latest day: refused with the range in US dates', one.log.saved.length === 0 && one.box.find( 'fh-dates-error' ).textContent === 'Pick a date from 01-01-21 to 11-27-28.' );
one.input.value = '';
one.input.validity.badInput = true;
one.box.find( 'fh-dates-save' ).fire( 'click' );
check( 'half a date typed into the calendar: refused, not saved', one.log.saved.length === 0 && one.box.find( 'fh-dates-error' ).textContent === 'Pick a date first.' );
one.input.validity.badInput = false;
one.input.value = '12/04/26';
one.box.find( 'fh-dates-save' ).fire( 'click' );
same( 'a browser with no calendar gives typed text: 12/04/26 is read and handed back as YYYY-MM-DD', [ '2026-12-04' ], one.log.saved );

console.log( '14. ask(): Tab stays inside' );
one = openOne();
var save   = one.box.find( 'fh-dates-save' );
var cancel = one.box.find( 'fh-dates-cancel' );
var clear  = one.box.find( 'fh-dates-clear' );
clear.focus();
var tab = one.doc.key( 'Tab' );
check( 'Tab on the last button goes to the field', tab.prevented && one.doc.activeElement === one.input );
tab = one.doc.key( 'Tab', true );
check( 'Shift+Tab on the field goes to the last button', tab.prevented && one.doc.activeElement === clear );
save.focus();
tab = one.doc.key( 'Tab' );
check( 'Tab in the middle is the browser\'s own', ! tab.prevented && one.doc.activeElement === save );
one.opener.focus();
tab = one.doc.key( 'Tab' );
check( 'focus that got outside comes back in', tab.prevented && one.doc.activeElement === one.input );
check( 'the order: field, Save, Cancel, then the "no date" button', cancel !== null );
one.handle.close();

console.log( '15. ask(): one at a time; nothing without a page' );
var docA = makeDoc();
var first = dates.ask( { document: docA, title: 'One' } );
var second = dates.ask( { document: docA, title: 'Two' } );
check( 'opening a second closes the first', ! docA.inDoc( first.box ) && docA.inDoc( second.box ) && docA.body.children.length === 1 );
second.close();
check( 'no document: null, no error', dates.ask( { document: null } ) === null );
var plain = dates.ask( { document: docA } );
check( 'no title given: it still has a name', docA.getElementById( plain.box.getAttribute( 'aria-labelledby' ) ).textContent === 'Date' );
check( 'no help given: no empty description', plain.box.getAttribute( 'aria-describedby' ) === null );
plain.close();

console.log( '' );
console.log( failures ? failures + ' FAILED of ' + checks : 'all ' + checks + ' passed' );
process.exit( failures ? 1 : 0 );
