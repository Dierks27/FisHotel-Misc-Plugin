/**
 * Front Desk JS — a system that is not fed because its fish ship, as the
 * phone shows it: js/front-desk.js is started against a tiny stand-in
 * DOM (the one tests/front-desk-status.test.js uses, cut down to what
 * these two screens touch).
 *
 *   - a tank's own screen: the Today strip's one line says "no feeding",
 *     and opened it gives the reason where the Feed chip would be — and
 *     no chip to tap; a feed ticked before the order came in keeps its
 *     tick and says when;
 *   - the day's list: the fasting system's block is there with the
 *     reason and nothing to tick, not greyed out as an empty one is; the
 *     sentence at the top when a shipping fish is in no tank the site
 *     knows; an ordinary day shows none of it.
 *
 * The sentences themselves are the server's (Feeding, feeding-test.php):
 * the page only shows what it is handed. Every fish, name and number
 * below is invented.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk-feeding.test.js
 */
'use strict';

var path = require( 'path' );
var file = path.join( __dirname, '..', 'js', 'front-desk.js' );

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

/* ------------------------------------------------------------------ */
/*  A stand-in DOM: just what front-desk.js touches                     */
/* ------------------------------------------------------------------ */

function El( tag ) {
	this.tag        = tag;
	this.children   = [];
	this.attrs      = {};
	this.listeners  = {};
	this.className  = '';
	this.style      = { setProperty: function () {} };
	this.value      = '';
	this.hidden     = false;
	this.disabled   = false;
	this.parentNode = null;
	this._text      = '';
}
Object.defineProperty( El.prototype, 'textContent', {
	get: function () {
		if ( '#text' === this.tag || ! this.children.length ) {
			return this._text;
		}
		return this.children.map( function ( c ) { return c.textContent; } ).join( '' );
	},
	set: function ( value ) {
		this.children = [];
		this._text    = String( value );
	}
} );
Object.defineProperty( El.prototype, 'firstChild', { get: function () { return this.children[0] || null; } } );
Object.defineProperty( El.prototype, 'nextSibling', {
	get: function () {
		var all = this.parentNode ? this.parentNode.children : [];
		return all[ all.indexOf( this ) + 1 ] || null;
	}
} );
El.prototype.appendChild = function ( child ) {
	return this.insertBefore( child, null );
};
El.prototype.insertBefore = function ( child, before ) {
	if ( child.parentNode ) {
		child.parentNode.removeChild( child );
	}
	child.parentNode = this;
	var at = before ? this.children.indexOf( before ) : -1;
	if ( at < 0 ) {
		this.children.push( child );
	} else {
		this.children.splice( at, 0, child );
	}
	return child;
};
El.prototype.removeChild = function ( child ) {
	var i = this.children.indexOf( child );
	if ( i >= 0 ) {
		this.children.splice( i, 1 );
		child.parentNode = null;
	}
	return child;
};
El.prototype.setAttribute = function ( key, value ) {
	this.attrs[ key ] = value;
	if ( 'value' === key ) {
		this.value = value;
	}
};
El.prototype.addEventListener = function ( type, fn ) {
	( this.listeners[ type ] = this.listeners[ type ] || [] ).push( fn );
};
El.prototype.focus = function () {};
El.prototype.querySelectorAll = function ( selector ) {
	var cls = String( selector ).replace( /^\./, '' );
	return find( this, function ( n ) { return has( n, cls ); } );
};
El.prototype.getBoundingClientRect = function () {
	return { height: 0 };
};

function has( node, cls ) {
	return -1 !== ( ' ' + node.className + ' ' ).indexOf( ' ' + cls + ' ' );
}

function find( node, test, out ) {
	out = out || [];
	if ( ! node || ! node.children ) {
		return out;
	}
	if ( test( node ) ) {
		out.push( node );
	}
	node.children.forEach( function ( c ) { find( c, test, out ); } );
	return out;
}

function byClass( node, cls ) {
	return find( node, function ( n ) { return has( n, cls ); } );
}

/* Timers never run here: nothing below waits for one. */
global.setTimeout    = function () { return 0; };
global.setInterval   = function () { return 0; };
global.clearTimeout  = function () {};
global.clearInterval = function () {};

/*
 * Start the app on a fresh page. config: screen, search (the address's
 * query — "?list=1" opens the day's list), stripOpen (whether the Today
 * strip was left open).
 */
function start( config ) {
	var body = new El( 'body' );
	var app  = new El( 'div' );
	var bar  = new El( 'div' );
	var line = new El( 'div' );
	var said = new El( 'span' );

	app.attrs.id   = 'fh-fd-app';
	bar.className  = 'fh-fd-bar';
	line.className = 'fh-fd-status';
	line.attrs.id  = 'fh-fd-status';
	said.className = 'fh-fd-status__said';
	said.attrs.id  = 'fh-fd-said';
	body.appendChild( app );
	body.appendChild( bar );
	bar.appendChild( line );
	line.appendChild( said );

	global.window   = global;
	global.document = {
		createElement: function ( tag ) { return new El( tag ); },
		createTextNode: function ( text ) { var n = new El( '#text' ); n._text = String( text ); return n; },
		getElementById: function ( id ) { return find( body, function ( n ) { return n.attrs.id === id; } )[0] || null; },
		querySelector: function ( selector ) { return find( body, function ( n ) { return '.' + n.className === selector; } )[0] || null; },
		addEventListener: function () {},
		body: body
	};
	global.sessionStorage   = { getItem: function () { return config.stripOpen ? '1' : '0'; }, setItem: function () {} };
	global.scrollTo         = function () {};
	global.pageYOffset      = 0;
	global.location         = { href: '', search: config.search || '', reload: function () {} };
	global.addEventListener = function () {};
	global.fetch            = function () { return new Promise( function () {} ); };
	delete global.ResizeObserver;
	delete global.SpeechRecognition;
	delete global.webkitSpeechRecognition;
	global.fhFrontDesk = { ajaxUrl: '/ajax', nonce: 'n', screen: config.screen, baseUrl: '#', boardUrl: '#board', view: '', i18n: {} };

	delete require.cache[ require.resolve( file ) ];
	require( file );

	return { app: app, body: body };
}

/* ------------------------------------------------------------------ */
/*  The page's data                                                    */
/* ------------------------------------------------------------------ */

var REASON = 'Building 700 — no feeding today. 2 fish ship from this system: 2 × Invented Tang (tank 721, #1234).';
var STRIP  = 'No feeding today — fish ship from here.';
var FED    = 'Fed at 7:40, before order #1251 came in.';
var NOTICE = 'No feeding in the clean tanks today — 4 fish ship and the site doesn\'t know their tank: 4 × Unlogged Clownfish (#1250).';
var HINT   = 'Add each one to the tank it is in (on the phone: Add fish already here) and only that system goes without.';

function feedLine( building, done ) {
	return { key: 'feed', kind: 'feed', label: 'Feed', scope: 'feed', building_id: building, note: done ? FED : '', medication: '', dose: '', 'for': '', done: !! done, count: 1, left: done ? 0 : 1, tanks: [ { tank_id: 1, number: building * 100 + 21, done: !! done } ] };
}

/*
 * fast: null (an ordinary day) or what the server hands a fasting tank
 * and its block; fed: its Feed was ticked before the order came in.
 */
function screen( fast, fed, notice ) {
	var todo = fed ? [ { key: 'feed', kind: 'feed', label: 'Feed', note: FED, done: true, fed: true } ] : ( fast ? [] : [ { key: 'feed', kind: 'feed', label: 'Feed', note: '', done: false } ] );
	return {
		today: '2026-11-24',
		tablesOk: true,
		grid: [ { area: 'clean', label: 'Clean Room', buildings: [ { id: 7, number: 700, label: 'Building 700', status: 'active', inQt: 0, tanks: [ { id: 32, number: 721, level: 2, sizeLabel: '20G', empty: false, count: 1, current: true, url: '#721' } ] } ] } ],
		spaTanks: [],
		recent: [],
		sizes: [],
		medications: [],
		todoList: {
			date: '2026-11-24',
			left: fast ? 1 : 2,
			total: fast ? ( fed ? 2 : 1 ) : 2,
			all_done: false,
			flags: [],
			fast: fast ? { text: notice || '', hint: notice ? HINT : '', words: 'no feeding in Building 700 (ship day)' } : null,
			blocks: [
				{ type: 'system', building_id: 5, number: 500, title: 'Spa · Building 500', gallons: 205, schedule: '14 Day CM', day_text: 'day 20', tanks: 1, left: 1, items: [ feedLine( 5, false ) ], copper: { value: null }, fast: null },
				{ type: 'system', building_id: 7, number: 700, title: 'Clean Room · Building 700', gallons: 0, schedule: '14 Day CM', day_text: 'day 20', tanks: 1, left: fast ? 0 : 1, items: fast ? ( fed ? [ feedLine( 7, true ) ] : [] ) : [ feedLine( 7, false ) ], copper: { value: null }, fast: fast ? { text: REASON, strip: STRIP, fish: 2, all: false } : null },
				{ type: 'system', building_id: 6, number: 600, title: 'Spa · Building 600', gallons: 100, schedule: '14 Day CM', day_text: '', tanks: 0, left: 0, items: [], copper: { value: null }, fast: null }
			]
		},
		settings: { autoConfirmSeconds: 4, voiceEnabled: false, undoSeconds: 10, copperFloor: 2.25, copperTarget: 2.5 },
		tank: {
			id: 32, number: 721, title: 'Tank 721', areaLabel: 'Clean Room', building: 'Building 700', buildingId: 7, isSpa: false, sizeLabel: '20G', gallons: 20, sharesWith: [],
			system: { gallons: 0, label: '', schedule: '' },
			occupants: [ { stayId: 9, species: 'Invented Tang', size: '', sizeSlug: '', sizeStandard: true, productId: null, qtyLive: 4, qtyArrived: 4, day: 20, status: 'cleared', stateLabel: 'cleared', checkinDate: '2026-11-05', clearedDate: '2026-11-20', saleReadyDate: '', saleReadyManual: null, saleSource: '', batchSaleDate: null, saleSuggested: '2026-12-03', saleReady: false, readyInSpa: false, needsId: false } ],
			todo: todo,
			fast: fast ? { text: REASON, strip: STRIP, fed: fed ? FED : '', fish: 2, all: false } : null,
			copper: null,
			url: '#721'
		}
	};
}

function strip( page ) {
	return byClass( page.app, 'fh-fd-today' )[0] || null;
}

function blockOf( page, title ) {
	return byClass( page.app, 'fh-fd-todo-block' ).filter( function ( node ) {
		return byClass( node, 'fh-fd-todo-block__title' ).some( function ( n ) { return n.textContent === title; } );
	} )[0] || null;
}

/* ------------------------------------------------------------------ */
console.log( '1. A tank\'s own screen: the Today strip' );

var page = start( { screen: screen( null ), stripOpen: true } );
var node = strip( page );
check( 'an ordinary day: "Today · 1 to do", the Feed chip, not a word about shipping', node && 'Today · 1 to do' === byClass( node, 'fh-fd-today__toggle' )[0].children[0].textContent && 1 === byClass( node, 'fh-fd-chip--todo' ).length && 0 === byClass( node, 'fh-fd-fast' ).length );

page = start( { screen: screen( true ), stripOpen: false } );
node = strip( page );
check( 'fish ship from this water — the strip closed: its one line already says so', node && 'Today · no feeding' === byClass( node, 'fh-fd-today__toggle' )[0].children[0].textContent && 0 === byClass( node, 'fh-fd-fast' ).length );

page = start( { screen: screen( true ), stripOpen: true } );
node = strip( page );
check( '… opened: the reason, where the Feed chip would be', node && 1 === byClass( node, 'fh-fd-fast' ).length && STRIP === byClass( node, 'fh-fd-fast' )[0].textContent );
check( '… and no Feed chip to tap, no "Nothing due today" beside the reason', node && 0 === byClass( node, 'fh-fd-chip--todo' ).length && 0 === byClass( node, 'fh-fd-muted' ).length );

page = start( { screen: screen( true, true ), stripOpen: true } );
node = strip( page );
check( 'fed before the order came in: the strip says no feeding and when it was fed', node && STRIP + ' ' + FED === byClass( node, 'fh-fd-fast' )[0].textContent && 'Today · all done · no feeding' === byClass( node, 'fh-fd-today__toggle' )[0].children[0].textContent );
check( '… the tick stands, and cannot be tapped again', node && 1 === byClass( node, 'fh-fd-chip--todo' ).length && true === byClass( node, 'fh-fd-chip--todo' )[0].disabled && 0 === byClass( node, 'fh-fd-chip--todo' )[0].textContent.indexOf( '✓ Feed' ) );

/* ------------------------------------------------------------------ */
console.log( '2. The day\'s list' );

page = start( { screen: screen( null ), search: '?list=1' } );
check( 'an ordinary day: the clean system\'s block has its Feed to tick, and nothing about shipping anywhere', blockOf( page, 'Clean Room · Building 700' ) && 1 === byClass( blockOf( page, 'Clean Room · Building 700' ), 'fh-fd-todo-line__tick' ).length && 0 === byClass( page.app, 'fh-fd-fast' ).length );

page = start( { screen: screen( true ), search: '?list=1' } );
node = blockOf( page, 'Clean Room · Building 700' );
check( 'ship day: the fasting system\'s block is there, with the reason', node && 1 === byClass( node, 'fh-fd-fast' ).length && REASON === byClass( node, 'fh-fd-fast' )[0].textContent );
check( '… nothing in it can be ticked', node && 0 === byClass( node, 'fh-fd-todo-line__tick' ).length && 0 === byClass( node, 'fh-fd-todo-line' ).length && 0 === find( node, function ( n ) { return 'button' === n.tag; } ).length );
check( '… it is not greyed out as a system without fish is, and does not say "no fish"', node && ! has( node, 'is-idle' ) && 0 === byClass( node, 'fh-fd-muted' ).length && has( blockOf( page, 'Spa · Building 600' ), 'is-idle' ) && 1 === byClass( blockOf( page, 'Spa · Building 600' ), 'fh-fd-muted' ).length );
check( '… the Spa keeps its Feed', 1 === byClass( blockOf( page, 'Spa · Building 500' ), 'fh-fd-todo-line__tick' ).length && 0 === byClass( blockOf( page, 'Spa · Building 500' ), 'fh-fd-fast' ).length );
check( '… every fish was found: no sentence at the top', 0 === byClass( page.app, 'fh-fd-fast--all' ).length );

page = start( { screen: screen( true, true ), search: '?list=1' } );
node = blockOf( page, 'Clean Room · Building 700' );
check( 'fed before the order came in: the done feed stands with the reason, and says when', node && 1 === byClass( node, 'fh-fd-todo-line' ).length && has( byClass( node, 'fh-fd-todo-line' )[0], 'is-done' ) && true === byClass( node, 'fh-fd-todo-line__tick' )[0].disabled && FED === byClass( node, 'fh-fd-todo-line__note' )[0].textContent && REASON === byClass( node, 'fh-fd-fast' )[0].textContent );

page = start( { screen: screen( true, false, NOTICE ), search: '?list=1' } );
check( 'a shipping fish in no tank the site knows: the sentence at the top, once, with how to end it', 1 === byClass( page.app, 'fh-fd-fast--all' ).length && NOTICE + ' ' + HINT === byClass( page.app, 'fh-fd-fast--all' )[0].textContent );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
