/**
 * Front Desk JS — the toast with Undo, run for real: js/front-desk.js is
 * started against a tiny stand-in DOM, a clock the test moves by hand, a
 * ResizeObserver it can fire and a fetch that records what is posted.
 *
 * Covers the toast's life: shown whole with its Undo and its bar; the
 * bar runs out and it folds to the small "↶ Undo" pill, which says what
 * it undoes and counts nothing down; the pill stays through a redraw of
 * the same screen and goes on the next action, when a sheet opens by any
 * road, and by itself before the server's window ends (and is never
 * shown when the page was told no window); Undo from the pill posts
 * what the big Undo posts; a next action inside the seconds lets the
 * whole toast finish and then go, unfolded; a tap on the message folds
 * it at once; an error and a plain "Saved" never fold; an undo the
 * server refuses as too late says so; the Undo is not thrown away while
 * another write is under way.
 *
 * And its place: the button bar and the toast are measured as they are
 * (three rows, a sheet's one row, none; larger text through the
 * ResizeObserver; without one, at every render and when the window
 * changes) and handed to the stylesheet as --fd-bar-h and --fd-toast-h,
 * and css/front-desk.css places the toast and the page's foot from those
 * two — no counted rows.
 *
 * Every fish, name and number below is invented.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk-toast.test.js
 */
'use strict';

var path = require( 'path' );
var fs   = require( 'fs' );
var file = path.join( __dirname, '..', 'js', 'front-desk.js' );
var css  = fs.readFileSync( path.join( __dirname, '..', 'css', 'front-desk.css' ), 'utf8' );
var php  = fs.readFileSync( path.join( __dirname, '..', 'class-front-desk.php' ), 'utf8' );

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
/*  A clock moved by hand                                               */
/* ------------------------------------------------------------------ */

var realImmediate = setImmediate;
var clock         = { now: 1700000000000, seq: 0, timers: [] };

Date.now = function () { return clock.now; };

function arm( fn, ms, every ) {
	var id = ++clock.seq;
	clock.timers.push( { id: id, at: clock.now + Math.max( 0, ms || 0 ), fn: fn, every: every ? Math.max( 1, ms ) : 0 } );
	return id;
}
function disarm( id ) {
	clock.timers = clock.timers.filter( function ( timer ) { return timer.id !== id; } );
}
global.setTimeout    = function ( fn, ms ) { return arm( fn, ms, false ); };
global.setInterval   = function ( fn, ms ) { return arm( fn, ms, true ); };
global.clearTimeout  = disarm;
global.clearInterval = disarm;

/* Let `seconds` pass: every timer that falls due runs, in order, at its own time. */
function pass( seconds ) {
	var end = clock.now + Math.round( seconds * 1000 );
	for ( ;; ) {
		var due = clock.timers.filter( function ( timer ) { return timer.at <= end; } ).sort( function ( a, b ) { return a.at - b.at || a.id - b.id; } )[0];
		if ( ! due ) {
			break;
		}
		clock.now = Math.max( clock.now, due.at );
		if ( due.every ) {
			due.at += due.every;
		} else {
			disarm( due.id );
		}
		due.fn();
	}
	clock.now = end;
}

/* The replies of the fake server are promises: let them land. */
function settle() {
	return new Promise( function ( resolve ) { realImmediate( function () { realImmediate( resolve ); } ); } );
}

/* ------------------------------------------------------------------ */
/*  A stand-in DOM: just what front-desk.js touches                     */
/* ------------------------------------------------------------------ */

/* How tall the things the page measures stand, by class — the test changes these as a phone would. */
var tall = { 'fh-fd-bar': 197, 'fh-fd-toast': 74, 'is-pill': 55 };

function El( tag ) {
	this.tag        = tag;
	this.children   = [];
	this.attrs      = {};
	this.listeners  = {};
	this.style      = { setProperty: function ( name, value ) { this[ name ] = value; } };
	this.className  = '';
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
El.prototype.appendChild = function ( child ) {
	child.parentNode = this;
	this.children.push( child );
	return child;
};
El.prototype.removeChild = function ( child ) {
	var i = this.children.indexOf( child );
	if ( i >= 0 ) {
		this.children.splice( i, 1 );
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
/* The last class that has a height wins: a folded toast is "fh-fd-toast is-pill". */
El.prototype.getBoundingClientRect = function () {
	var height = 0;
	this.className.split( ' ' ).forEach( function ( cls ) {
		if ( Object.prototype.hasOwnProperty.call( tall, cls ) ) {
			height = tall[ cls ];
		}
	} );
	return { height: this.hidden ? 0 : height };
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

/* A tap: every listener on the node, then on what holds it — unless one stops it, as the Undo does. */
function fire( node, type ) {
	var stopped = false;
	var event   = { target: node, key: '', stopPropagation: function () { stopped = true; }, preventDefault: function () {} };
	for ( var at = node; at && ! stopped; at = at.parentNode ) {
		( at.listeners[ type ] || [] ).slice().forEach( function ( fn ) { fn( event ); } );
	}
}

/* ------------------------------------------------------------------ */
/*  The page                                                           */
/* ------------------------------------------------------------------ */

function screen( withTank, undoWindow ) {
	var data = {
		today: '2026-11-20',
		tablesOk: true,
		grid: [ { area: 'room', label: 'Test Room', buildings: [ { id: 7, number: 700, label: 'Building 700', status: 'active', inQt: 0, tanks: [ { id: 31, number: 711, level: 1, sizeLabel: '20G', empty: true, count: 0, current: false, url: '#711' }, { id: 32, number: 721, level: 2, sizeLabel: '20G', empty: false, count: 1, current: true, url: '#721' } ] } ] } ],
		spaTanks: [],
		recent: [],
		sizes: [],
		medications: [],
		settings: { autoConfirmSeconds: 4, voiceEnabled: true, undoSeconds: 10, copperFloor: 2.25, copperTarget: 2.5 },
		tank: null
	};
	if ( undoWindow ) {
		data.settings.undoWindow = undoWindow;
	}
	if ( ! withTank ) {
		return data;
	}
	data.tank = {
		id: 32, number: 721, title: 'Tank 721', areaLabel: 'Test Room', building: 'Building 700', buildingId: 7, isSpa: false, sizeLabel: '20G', gallons: 20, sharesWith: [],
		system: { gallons: 0, label: '', schedule: '' },
		occupants: [ { stayId: 9, species: 'Invented Goby', size: '', sizeSlug: '', sizeStandard: true, productId: null, qtyLive: 5, qtyArrived: 5, day: 3, status: 'in_qt', stateLabel: 'in QT', checkinDate: '2026-11-18', clearedDate: null, saleReadyDate: '', saleReadyManual: null, saleSource: '', batchSaleDate: null, saleSuggested: '2026-12-16', saleReady: false, readyInSpa: false, needsId: false } ],
		todo: [],
		copper: null,
		url: '#721'
	};
	return data;
}

var LOSS = '1 × Invented Goby lost';

/* The server: a loss is written and can be undone; an undo is done; anything else is saved with nothing to undo. */
function server( fields, config ) {
	if ( 'undo' === fields.act ) {
		return { message: 'Undone', undo: { eventIds: [], label: 'Undone' }, screen: config.screen };
	}
	if ( 'loss' === fields.act ) {
		return { message: LOSS, undo: { eventIds: [ 41, 42 ], label: LOSS }, screen: config.screen };
	}
	return { message: 'Saved', undo: { eventIds: [], label: '' }, screen: config.screen };
}

/*
 * Start the app on a fresh page. config: screen, reply( fields, config ),
 * noObserver (a browser without ResizeObserver), hold (replies wait for
 * page.release()).
 */
function start( config ) {
	var app       = new El( 'div' );
	var toast     = new El( 'div' );
	var body      = new El( 'body' );
	var posts     = [];
	var held      = [];
	var observers = [];
	var windowOn  = {};
	var reply     = config.reply || server;

	toast.className = 'fh-fd-toast';
	toast.hidden    = true;
	clock.timers    = [];

	global.window         = global;
	global.document       = {
		createElement: function ( tag ) { return new El( tag ); },
		createTextNode: function ( text ) { var n = new El( '#text' ); n._text = String( text ); return n; },
		getElementById: function ( id ) { return 'fh-fd-app' === id ? app : ( 'fh-fd-toast' === id ? toast : null ); },
		querySelector: function ( selector ) { return find( body, function ( n ) { return '.' + n.className === selector; } )[0] || null; },
		body: body
	};
	global.sessionStorage = { getItem: function () { return '0'; }, setItem: function () {} };
	global.scrollTo       = function () {};
	global.location       = { href: '', reload: function () {} };
	global.addEventListener = function ( type, fn ) { windowOn[ type ] = fn; };
	if ( config.noObserver ) {
		delete global.ResizeObserver;
	} else {
		global.ResizeObserver = function ( callback ) {
			this.callback = callback;
			this.nodes    = [];
			observers.push( this );
		};
		global.ResizeObserver.prototype.observe    = function ( node ) { this.nodes.push( node ); };
		global.ResizeObserver.prototype.disconnect = function () { this.nodes = []; };
	}
	global.fetch = function ( url, options ) {
		var fields = {};
		new URLSearchParams( options.body ).forEach( function ( value, key ) {
			if ( /\[\]$/.test( key ) ) {
				( fields[ key ] = fields[ key ] || [] ).push( value );
			} else {
				fields[ key ] = value;
			}
		} );
		posts.push( fields );
		var data   = reply( fields, config );
		var answer = data && data.refused
			? { status: 400, json: function () { return Promise.resolve( { success: false, data: data.refused } ); } }
			: { status: 200, json: function () { return Promise.resolve( { success: true, data: data } ); } };
		if ( config.hold ) {
			return new Promise( function ( resolve ) { held.push( function () { resolve( answer ); } ); } );
		}
		return Promise.resolve( answer );
	};
	delete global.SpeechRecognition;
	delete global.webkitSpeechRecognition;
	global.fhFrontDesk = { ajaxUrl: '/ajax', nonce: 'n', screen: config.screen, baseUrl: '#', boardUrl: '#board', view: '', i18n: {} };

	delete require.cache[ require.resolve( file ) ];
	require( file );

	function buttons( label ) {
		function test( n ) {
			return 'button' === n.tag && -1 !== n.textContent.indexOf( label );
		}
		return find( app, test ).concat( find( body, test ) );
	}

	return {
		app: app,
		body: body,
		toast: toast,
		posts: posts,
		observers: observers,
		windowOn: windowOn,
		bar: function () { return find( body, function ( n ) { return 'fh-fd-bar' === n.className; } )[0] || null; },
		/* What the stylesheet was handed. */
		barVar: function () { return body.style['--fd-bar-h']; },
		toastVar: function () { return body.style['--fd-toast-h']; },
		tap: function ( label ) {
			var hit = buttons( label ).filter( function ( n ) { return ! n.disabled && ! n.hidden; } )[0];
			if ( ! hit ) {
				throw new Error( 'no button "' + label + '"' );
			}
			fire( hit, 'click' );
			return hit;
		},
		/* The toast as a thumb meets it. */
		shown: function () { return ! toast.hidden; },
		folded: function () { return ! toast.hidden && has( toast, 'is-pill' ); },
		undo: function () { return find( toast, function ( n ) { return 'button' === n.tag; } )[0] || null; },
		countdown: function () { return find( toast, function ( n ) { return has( n, 'fh-fd-toast__bar' ); } )[0] || null; },
		words: function () { return toast.hidden ? '' : toast.textContent; },
		release: function () { held.splice( 0 ).forEach( function ( go ) { go(); } ); }
	};
}

/* A loss on the tank screen: the toast with Undo is up. */
async function lostOne( config ) {
	var page = start( config );
	page.tap( '−1 lost' );
	await settle();
	return page;
}

function rule( selector ) {
	var at = css.indexOf( '\n' + selector + ' {' );
	return at < 0 ? '' : css.slice( at, css.indexOf( '}', at ) );
}

async function run() {
	var page, first, big, small;

	console.log( '1. Shown whole: the words, a big Undo, the bar running out' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	check( 'one request was posted: the loss', 1 === page.posts.length && 'loss' === page.posts[0].act && 'fishotel_cleanroom_act' === page.posts[0].action, JSON.stringify( page.posts ) );
	check( 'the toast says what was written', page.shown() && -1 !== page.words().indexOf( LOSS ), page.words() );
	check( '… with an Undo beside it', page.undo() && 'Undo' === page.undo().textContent );
	check( '… and the bar, full', page.countdown() && '100%' === page.countdown().style.width, page.countdown() && page.countdown().style.width );
	check( '… not folded', ! page.folded() && has( page.toast, 'is-sticky' ), page.toast.className );
	pass( 5 );
	check( 'half the seconds on: the bar is half gone, the toast still whole', page.shown() && ! page.folded() && Math.abs( parseFloat( page.countdown().style.width ) - 50 ) < 3, page.countdown().style.width );
	pass( 4.9 );
	check( 'just before the seconds end it is still whole', page.shown() && ! page.folded() && null !== page.countdown() );

	console.log( '2. The bar runs out: it folds to the pill' );
	pass( 0.2 );
	check( 'the toast folds — it does not sit there whole', page.folded(), page.toast.className );
	check( 'what is left is one small button: "↶ Undo"', 1 === page.toast.children.length && page.undo() && '↶ Undo' === page.undo().textContent, page.words() );
	check( '… whose name says what it undoes', page.undo() && 'Undo: ' + LOSS === page.undo().attrs['aria-label'], page.undo() && page.undo().attrs['aria-label'] );
	check( '… with no countdown on it', null === page.countdown() );
	check( 'the fold is not an error and not the whole toast', ! has( page.toast, 'is-error' ) && ! has( page.toast, 'is-sticky' ), page.toast.className );
	check( 'nothing was posted by folding', 1 === page.posts.length );
	pass( 120 );
	check( 'two minutes on the pill is still there', page.folded() );
	page.tap( 'Today' );
	check( 'a redraw of the same screen (the Today strip opened) keeps it', page.folded() );

	console.log( '3. The next action removes it' );
	page.tap( 'Note' );
	check( 'a sheet was opened from the bar: the pill is gone at once', ! page.shown() );
	check( '… and nothing of it is left to measure', '0px' === page.toastVar(), page.toastVar() );
	pass( 1200 );
	check( '… and it does not come back', ! page.shown() && 1 === page.posts.length );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10 );
	first = page.undo();
	page.tap( '−1 lost' );
	await settle();
	check( 'another write: a new whole toast takes the pill\'s place', page.shown() && ! page.folded() && page.undo() !== first && 2 === page.posts.length, page.toast.className );

	console.log( '4. A sheet opening removes it, by any road' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10 );
	page.tap( 'Mic' ); // Opens its sheet without go(): the render itself must close the pill.
	check( 'the Mic sheet (which opens by its own road) carries no pill', ! page.shown(), page.toast.className );

	console.log( '5. The window\'s end removes it' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10 );
	pass( 879 );
	check( 'eleven seconds before the server\'s 15 minutes are up the pill is still offered', page.folded() );
	pass( 1.5 );
	check( 'ten seconds before the end it has gone by itself', ! page.shown() && '0px' === page.toastVar() );
	check( '… without posting anything', 1 === page.posts.length );
	check( 'the page is told the window by the server\'s own constant', /'undoWindow'\s*=>\s*self::UNDO_WINDOW/.test( php ) );

	page = await lostOne( { screen: screen( true, 0 ) } );
	check( 'a page that was told no window still shows the whole toast with Undo', page.shown() && null !== page.undo() );
	pass( 10.2 );
	check( '… and then no pill at all: it is never offered blind', ! page.shown() );

	page = await lostOne( { screen: screen( true, 25 ) } );
	pass( 10.2 );
	check( 'a short window (25 s): folded at 10 s', page.folded() );
	pass( 5 );
	check( '… gone at 15 s, ten before its end', ! page.shown() );

	console.log( '6. Undo from the pill posts what the big Undo posts' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	fire( page.undo(), 'click' );
	await settle();
	big = page.posts[1];
	check( 'the big Undo posts the undo of the events just written', big && 'undo' === big.act && '41,42' === ( big['event_ids[]'] || [] ).join( ',' ) && '721' === big.tank, JSON.stringify( big ) );
	check( '… and the answer is a plain toast: no Undo on it', page.shown() && -1 !== page.words().indexOf( 'Undone' ) && null === page.undo() );
	pass( 10.2 );
	check( '… which goes after its seconds and never folds', ! page.shown() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 300 );
	fire( page.undo(), 'click' );
	check( 'a tap on the pill takes it off the screen', ! page.shown() );
	await settle();
	small = page.posts[1];
	check( 'the pill, five minutes later, posts the very same request', JSON.stringify( small ) === JSON.stringify( big ), JSON.stringify( small ) );
	check( '… once', 2 === page.posts.length );
	check( '… and "Undone" is shown', -1 !== page.words().indexOf( 'Undone' ) && ! page.folded() );

	console.log( '7. Inside the seconds' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 3 );
	page.tap( 'Note' );
	check( 'a next action 3 s in: the whole toast stays for the rest of its seconds, Undo and all', page.shown() && ! page.folded() && null !== page.undo() );
	pass( 6.8 );
	check( '… still there just before they end', page.shown() && ! page.folded() );
	pass( 0.4 );
	check( '… then it goes — it does not fold onto the sheet', ! page.shown() );
	pass( 1200 );
	check( '… ever', ! page.shown() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 3 );
	fire( page.toast, 'click' );
	check( 'a tap on the message 3 s in folds it at once', page.folded() && '↶ Undo' === page.undo().textContent );
	pass( 886 );
	check( '… and the pill still ends by the window, counted from when the toast came up', page.folded() );
	pass( 1.5 );
	check( '… not later', ! page.shown() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10.2 );
	fire( page.toast, 'click' );
	check( 'a tap on the strip beside the pill does nothing: the pill stays', page.folded() && 1 === page.posts.length );

	console.log( '8. An error, and a plain "Saved", never fold' );
	page = await lostOne( { screen: screen( true, 900 ), reply: function () { return { refused: { message: 'No fish left to lose.' } }; } } );
	check( 'a refusal is an error toast with the server\'s words', page.shown() && has( page.toast, 'is-error' ) && -1 !== page.words().indexOf( 'No fish left to lose.' ), page.words() );
	check( '… with no Undo', null === page.undo() );
	pass( 3.8 );
	check( '… there for its 4 seconds', page.shown() && ! page.folded() );
	pass( 0.4 );
	check( '… then gone', ! page.shown() );
	pass( 1200 );
	check( '… and never a pill', ! page.shown() );

	page = await lostOne( { screen: screen( true, 900 ), reply: function ( fields, config ) { return { message: 'Saved', undo: { eventIds: [], label: '' }, screen: config.screen }; } } );
	check( 'a write with nothing to undo: "Saved", no Undo', page.shown() && -1 !== page.words().indexOf( 'Saved' ) && null === page.undo() && ! has( page.toast, 'is-sticky' ) );
	pass( 9.8 );
	check( '… there for its seconds', page.shown() && ! page.folded() );
	pass( 0.4 );
	check( '… then gone, unfolded', ! page.shown() );

	page = await lostOne( {
		screen: screen( true, 900 ),
		reply: function ( fields, config ) { return 'undo' === fields.act ? { refused: { message: 'That can no longer be undone.' } } : server( fields, config ); }
	} );
	pass( 600 );
	fire( page.undo(), 'click' );
	await settle();
	check( 'an undo the server refuses as too late says so plainly', page.shown() && has( page.toast, 'is-error' ) && -1 !== page.words().indexOf( 'That can no longer be undone.' ), page.words() );
	check( '… and offers no Undo again', null === page.undo() );
	pass( 4.2 );
	check( '… and goes like any error', ! page.shown() );

	console.log( '9. The Undo is not thrown away while another write is under way' );
	page = start( { screen: screen( true, 900 ), hold: true } );
	page.tap( '−1 lost' );
	page.release();
	await settle();
	pass( 10.2 );
	page.tap( '−1 lost' ); // Under way: its reply is held.
	fire( page.undo(), 'click' );
	check( 'a tap on the pill while a write is under way posts nothing', 2 === page.posts.length && 'loss' === page.posts[1].act );
	check( '… and the pill stays for the next tap', page.folded() );

	console.log( '10. The place is taken from the bar as it is' );
	page = start( { screen: screen( true, 900 ) } );
	check( 'the tank screen\'s bar is measured at the first render: three rows, 197 px', '197px' === page.barVar(), page.barVar() );
	check( '… and no toast: nothing', '0px' === page.toastVar(), page.toastVar() );
	check( 'one ResizeObserver watches the bar that is on the page, and the toast', 1 === page.observers.length && -1 !== page.observers[0].nodes.indexOf( page.bar() ) && -1 !== page.observers[0].nodes.indexOf( page.toast ) );
	tall['fh-fd-bar'] = 253; // Larger text: the labels wrap, the bar grows.
	page.observers[0].callback();
	check( 'the bar grows (larger text): the observer hands on the new height', '253px' === page.barVar(), page.barVar() );
	first = page.bar();
	tall['fh-fd-bar'] = 77; // A sheet's bar: one row.
	page.tap( 'Note' );
	check( 'a sheet has a bar of its own, one row: measured anew', '77px' === page.barVar() && page.bar() !== first, page.barVar() );
	check( '… and the observer watches the new bar, not the one that left', -1 !== page.observers[0].nodes.indexOf( page.bar() ) && -1 === page.observers[0].nodes.indexOf( first ) );
	tall['fh-fd-bar'] = 197;

	page = await lostOne( { screen: screen( true, 900 ) } );
	check( 'the whole toast is measured as it comes up', '74px' === page.toastVar(), page.toastVar() );
	tall['fh-fd-toast'] = 98; // The words wrap onto a third line.
	page.observers[0].callback();
	check( '… and again when its words wrap', '98px' === page.toastVar(), page.toastVar() );
	tall['fh-fd-toast'] = 74;
	pass( 10.2 );
	check( 'the pill\'s strip is measured when it folds', '55px' === page.toastVar(), page.toastVar() );

	page = start( { screen: screen( false, 900 ) } );
	check( 'the tank picker has no bar: nothing is kept for one', null === page.bar() && '0px' === page.barVar(), page.barVar() );

	page = start( { screen: screen( true, 900 ), noObserver: true } );
	check( 'without ResizeObserver the bar is still measured at the render', 0 === page.observers.length && '197px' === page.barVar(), page.barVar() );
	tall['fh-fd-bar'] = 141;
	check( '… and when the window changes', 'function' === typeof page.windowOn.resize && ( page.windowOn.resize(), '141px' === page.barVar() ), page.barVar() );
	tall['fh-fd-bar'] = 197;
	page.tap( '−1 lost' );
	await settle();
	pass( 10.2 );
	check( '… and the toast at each of its changes', page.folded() && '55px' === page.toastVar(), page.toastVar() );

	console.log( '11. The stylesheet places the toast and the page\'s foot from the two measures' );
	check( 'the toast sits above the measured bar', /bottom:\s*calc\(max\(var\(--fd-bar-h\), env\(safe-area-inset-bottom, 0px\)\) \+ \d+px\)/.test( rule( '.fh-fd-toast' ) ), rule( '.fh-fd-toast' ) );
	check( 'the page keeps room at its foot for the bar and the toast', /padding-bottom:\s*calc\(max\(var\(--fd-bar-h\), env\(safe-area-inset-bottom, 0px\)\) \+ var\(--fd-toast-h\) \+ \d+px\)/.test( rule( 'body.fh-fd' ) ), rule( 'body.fh-fd' ) );
	check( 'no place is worked out from a counted number of rows', ! /var\(--fd-tap\)\s*\*/.test( css ) );
	check( 'both measures start at nothing until the script has measured', /--fd-bar-h:\s*0px;/.test( rule( ':root' ) ) && /--fd-toast-h:\s*0px;/.test( rule( ':root' ) ) );
	check( 'the pill\'s strip stands on the bar, the whole width, the pill at the right', /bottom:\s*var\(--fd-bar-h\);/.test( rule( '.fh-fd-toast.is-pill' ) ) && /left:\s*0;/.test( rule( '.fh-fd-toast.is-pill' ) ) && /right:\s*0;/.test( rule( '.fh-fd-toast.is-pill' ) ) && /justify-content:\s*flex-end;/.test( rule( '.fh-fd-toast.is-pill' ) ), rule( '.fh-fd-toast.is-pill' ) );
	check( 'the pill is at least 44 px tall', /min-height:\s*(4[4-9]|[5-9]\d)px;/.test( rule( '.fh-fd-toast.is-pill button' ) ), rule( '.fh-fd-toast.is-pill button' ) );
	var calm = css.slice( css.indexOf( '@media (prefers-reduced-motion: reduce)' ) );
	check( 'reduced motion: the fold is not animated and the bar does not glide', -1 !== css.indexOf( '@media (prefers-reduced-motion: reduce)' ) && /\.fh-fd-toast\.is-pill \{\s*animation:\s*none;/.test( calm ) && /\.fh-fd-toast__bar \{\s*transition:\s*none;/.test( calm ) );
	check( 'the fold itself only fades: no movement, no bounce', /@keyframes fh-fd-fold \{\s*from \{ opacity: 0; \}\s*to \{ opacity: 1; \}\s*\}/.test( css ) );

	console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
	process.exit( failures ? 1 : 0 );
}

run().catch( function ( error ) {
	console.log( '  FAIL the run threw: ' + ( error && error.stack ? error.stack : error ) );
	process.exit( 1 );
} );
