/**
 * Front Desk JS — the status line, run for real: js/front-desk.js is
 * started against a tiny stand-in DOM, a clock the test moves by hand, a
 * ResizeObserver it can fire and a fetch that records what is posted.
 *
 * The status line is the first row of the button bar, on every screen,
 * from the first paint; whatever the page has to say is said there, so
 * nothing ever comes up over the page. Covered here:
 *
 *   - its place: in the page as it arrives, first in every screen's bar
 *     (a tank, a sheet, and — alone — the picker and the day's list);
 *   - a message changes no size: the bar's and the line's heights, what
 *     the stylesheet is handed, and the line's own three parts are the
 *     same before, during and after a message, an error, a long one;
 *   - its life: the words, a filled Undo at the left named by what it
 *     undoes, the thin bar running out; then the Undo turns quiet in
 *     place and the words read "Last: … · 2 min ago"; it stays through a
 *     redraw and onto a sheet; the next write replaces it; the end of
 *     the server's window takes it away;
 *   - a phone that slept (the clock on, its timers held): looked at
 *     again the Undo past its time has gone, and a tap on one answers
 *     plainly without a request;
 *   - an error keeps an Undo that is still good for after, and keeps
 *     its place: the error's words do not start where the Undo was;
 *   - a request that got no answer (no signal) is said in plain words,
 *     never in the browser's own ("Failed to fetch", "Load failed");
 *   - the tap guard, and that no tap elsewhere on the line undoes; a
 *     write on the tank screen leaves the page where it was scrolled to;
 *   - on a sheet the line's Undo opens the whole message first: one
 *     stray tap takes back nothing and drops nothing that was typed;
 *   - a tap on the words opens the whole message with its Undo, and Back
 *     shows the screen under it as it was, what was typed included — a
 *     page that was at its foot too;
 *   - what a screen reader is told: the words of a message alone, once,
 *     in one span that is always in the line;
 *   - the words for the last thing done;
 *   - the bar measured as it stands for the room at the page's foot,
 *     and what css/front-desk.css fixes: one line, one height, no toast.
 *
 * Every fish, name and number below is invented.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk-status.test.js
 */
'use strict';

var path = require( 'path' );
var fs   = require( 'fs' );
var file = path.join( __dirname, '..', 'js', 'front-desk.js' );
var css  = fs.readFileSync( path.join( __dirname, '..', 'css', 'front-desk.css' ), 'utf8' );
var php  = fs.readFileSync( path.join( __dirname, '..', 'class-front-desk.php' ), 'utf8' );
var view = fs.readFileSync( path.join( __dirname, '..', 'views', 'front-desk.php' ), 'utf8' );

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

/*
 * How tall things stand, as the stylesheet has it: the status line one
 * fixed height (larger text makes it taller, its words never do), a row
 * of buttons, and the bar as tall as what is in it. A script that hid
 * the line, took it out of the bar or gave it a height of its own would
 * show here; that the stylesheet really lays it out so is looked at in a
 * browser, not here.
 */
var layout = { line: 48, row: 56, gap: 8, pad: 14 };

/* Every style the script writes, by the class of what it wrote it on. */
var styleWrites = [];

function Style( node ) {
	var own = { setProperty: function ( name, value ) { styleWrites.push( [ node.className, name, value ] ); own[ name ] = value; } };
	return new Proxy( own, {
		set: function ( target, name, value ) {
			styleWrites.push( [ node.className, name, value ] );
			target[ name ] = value;
			return true;
		}
	} );
}

function El( tag ) {
	this.tag        = tag;
	this.children   = [];
	this.attrs      = {};
	this.listeners  = {};
	this.className  = '';
	this.style      = Style( this );
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
/* A node lives in one place: put somewhere new, it leaves where it was. */
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
	return { height: heightOf( this ) };
};

function heightOf( node ) {
	if ( node.hidden ) {
		return 0;
	}
	if ( has( node, 'fh-fd-status' ) ) {
		return node.style.height ? parseFloat( node.style.height ) : layout.line;
	}
	if ( has( node, 'fh-fd-bar__inner' ) ) {
		var rows = Math.ceil( node.children.length / 3 );
		return rows * layout.row + ( rows - 1 ) * layout.gap;
	}
	if ( has( node, 'fh-fd-bar' ) ) {
		return layout.pad + node.children.reduce( function ( sum, child ) { return sum + heightOf( child ); }, 0 );
	}
	return 0;
}

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

/* Whether a thumb could meet it: neither it nor anything it is in is hidden. */
function visible( node ) {
	for ( var at = node; at; at = at.parentNode ) {
		if ( at.hidden ) {
			return false;
		}
	}
	return true;
}

/* A tap: every listener on the node, then on what holds it — unless one stops it. */
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
		todoList: { date: '2026-11-20', left: 1, total: 1, all_done: false, flags: [], blocks: [ { type: 'system', building_id: 7, title: 'Building 700', gallons: 0, schedule: '', day_text: '', tanks: 1, left: 1, items: [ { key: 'a', kind: 'step', label: 'Test ammonia', scope: 'system', building_id: 7, done: false } ] } ] },
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

var LOSS    = '1 × Invented Goby lost';
var LONG    = '12 × Invented Goby with a very long invented name, size small to medium, lost in Tank 721 — and 3 × Made-up Blenny moved along with them';
var REFUSED = { refused: { message: 'No fish left to lose.' } };
var OFFLINE      = 'No connection — not saved. Try again.';
var OFFLINE_READ = 'No connection. Try again.';
var SAVED   = function ( fields, config ) { return { message: 'Saved', undo: { eventIds: [], label: '' }, screen: config.screen }; };

/* The server: a loss is written and can be undone (each with event ids of its own); an undo is done; anything else is saved with nothing to undo. */
function server( fields, config, n ) {
	if ( 'undo' === fields.act ) {
		return { message: 'Undone', undo: { eventIds: [], label: 'Undone' }, screen: config.screen };
	}
	if ( 'loss' === fields.act ) {
		return { message: config.message || LOSS, undo: { eventIds: [ 40 + n, 140 + n ], label: config.message || LOSS }, screen: config.screen };
	}
	return SAVED( fields, config );
}

/* The n-th reply is replies[n - 1]( … ), where there is one; else the server's. */
function inTurn( replies ) {
	return function ( fields, config, n ) {
		return ( replies[ n - 1 ] || server )( fields, config, n );
	};
}

/*
 * Start the app on a fresh page, as views/front-desk.php writes it: the
 * app's box, and the button bar holding the status line. config: screen,
 * reply( fields, config, n ), search (the address's query), noObserver (a
 * browser without ResizeObserver), hold (replies wait for
 * page.release()), bareLine (a page whose line came without its span
 * for the screen reader), i18n (the page's own words, where it has any).
 *
 * A reply { offline: … } is no answer at all — fetch itself rejects: with
 * Chrome's TypeError for true, with a TypeError of those words for a
 * string, with the very thing given for anything else.
 */
function start( config ) {
	var body      = new El( 'body' );
	var app       = new El( 'div' );
	var firstBar  = new El( 'div' );
	var line      = new El( 'div' );
	var said      = new El( 'span' );
	var posts     = [];
	var held      = [];
	var observers = [];
	var windowOn  = {};
	var documentOn = {};
	var reloads   = 0;
	var scrolls   = [];
	var scrollsAt = [];
	var reply     = config.reply || server;

	app.attrs.id       = 'fh-fd-app';
	firstBar.className = 'fh-fd-bar';
	line.className     = 'fh-fd-status';
	line.attrs.id      = 'fh-fd-status';
	body.appendChild( app );
	body.appendChild( firstBar );
	firstBar.appendChild( line );
	said.className  = 'fh-fd-status__said';
	said.attrs.id   = 'fh-fd-said';
	said.attrs.role = 'status';
	if ( ! config.bareLine ) {
		line.appendChild( said );
	}
	clock.timers = [];
	styleWrites  = [];

	global.window         = global;
	global.document       = {
		createElement: function ( tag ) { return new El( tag ); },
		createTextNode: function ( text ) { var n = new El( '#text' ); n._text = String( text ); return n; },
		getElementById: function ( id ) { return find( body, function ( n ) { return n.attrs.id === id; } )[0] || null; },
		querySelector: function ( selector ) { return find( body, function ( n ) { return '.' + n.className === selector; } )[0] || null; },
		addEventListener: function ( type, fn ) { documentOn[ type ] = fn; },
		body: body
	};
	global.sessionStorage = { getItem: function () { return '0'; }, setItem: function () {} };
	global.scrollTo       = function ( x, y ) { scrolls.push( y ); scrollsAt.push( body.style['--fd-bar-h'] ); global.pageYOffset = y; };
	global.pageYOffset    = 0;
	global.location       = { href: '', search: config.search || '', reload: function () { reloads++; } };
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
		var data = reply( fields, config, posts.length );
		if ( data && data.offline ) {
			// The request never got there.
			return Promise.reject( true === data.offline ? new TypeError( 'Failed to fetch' ) : ( 'string' === typeof data.offline ? new TypeError( data.offline ) : data.offline ) );
		}
		var answer = data && data.status
			? { status: data.status, json: function () { return Promise.resolve( {} ); } }
			: ( data && data.refused
				? { status: 400, json: function () { return Promise.resolve( { success: false, data: data.refused } ); } }
				: { status: 200, json: function () { return Promise.resolve( { success: true, data: data } ); } } );
		if ( config.hold ) {
			return new Promise( function ( resolve ) { held.push( function () { resolve( answer ); } ); } );
		}
		return Promise.resolve( answer );
	};
	delete global.SpeechRecognition;
	delete global.webkitSpeechRecognition;
	global.fhFrontDesk = { ajaxUrl: '/ajax', nonce: 'n', screen: config.screen, baseUrl: '#', boardUrl: '#board', view: '', i18n: config.i18n || {} };

	delete require.cache[ require.resolve( file ) ];
	var helpers = require( file );

	function part( cls ) {
		return find( line, function ( n ) { return has( n, cls ); } )[0] || null;
	}
	/* Every button a thumb could meet whose text holds the label; the status line's own two are asked for by name below. */
	function buttons( label ) {
		return find( body, function ( n ) {
			return 'button' === n.tag && -1 !== n.textContent.indexOf( label ) && 0 !== n.className.indexOf( 'fh-fd-status__' ) && visible( n ) && ! n.disabled;
		} );
	}

	var page = {
		app: app,
		body: body,
		line: line,
		firstBar: firstBar,
		posts: posts,
		observers: observers,
		windowOn: windowOn,
		documentOn: documentOn,
		helpers: helpers,
		reloads: function () { return reloads; },
		scrolls: scrolls,
		/* The room at the page's foot (what the stylesheet had been handed) at each scroll. */
		scrollsAt: scrollsAt,
		bar: function () { return find( body, function ( n ) { return 'fh-fd-bar' === n.className; } )[0] || null; },
		bars: function () { return find( body, function ( n ) { return 'fh-fd-bar' === n.className; } ).length; },
		inner: function () { return find( body, function ( n ) { return 'fh-fd-bar__inner' === n.className && ! n.hidden; } )[0] || null; },
		/* What the stylesheet was handed. */
		barVar: function () { return body.style['--fd-bar-h']; },
		can: function ( label ) { return buttons( label ).length > 0; },
		tap: function ( label ) {
			var hit = buttons( label )[0];
			if ( ! hit ) {
				throw new Error( 'no button "' + label + '"' );
			}
			fire( hit, 'click' );
			return hit;
		},
		/* The status line as a thumb meets it. */
		undoNode: function () { return part( 'fh-fd-status__undo' ); },
		undo: function () { var n = part( 'fh-fd-status__undo' ); return n && ! n.hidden && ! has( n, 'is-held' ) ? n : null; },
		/* The Undo's place kept, empty, behind an error: there, not seen, taking no tap. */
		held: function () { var n = part( 'fh-fd-status__undo' ); return !! n && ! n.hidden && has( n, 'is-held' ) && true === n.disabled; },
		/* What a screen reader is told. */
		said: function () { return part( 'fh-fd-status__said' ); },
		quiet: function () { var n = page.undo(); return !! n && has( n, 'is-quiet' ); },
		textNode: function () { return part( 'fh-fd-status__text' ); },
		words: function () { var n = part( 'fh-fd-status__text' ); return n && ! n.hidden ? find( n, function ( c ) { return ! c.hidden && ! c.children.length; } ).map( function ( c ) { return c._text; } ).join( '' ) : ''; },
		kind: function () { return line.className.replace( 'fh-fd-status', '' ).trim(); },
		run: function () { return parseFloat( part( 'fh-fd-status__bar' ).style.width ); },
		tapUndo: function () { fire( part( 'fh-fd-status__undo' ), 'click' ); },
		tapWords: function () { fire( part( 'fh-fd-status__text' ), 'click' ); },
		/* The sizes that must not change, and the line's own make-up. */
		sizes: function () {
			return JSON.stringify( {
				bar: heightOf( page.bar() ),
				line: heightOf( line ),
				handed: body.style['--fd-bar-h'],
				lineIsFirst: page.bar().children[0] === line,
				barHolds: page.bar().children.length,
				lineHolds: line.children.map( function ( c ) { return c.tag + '.' + c.className.split( ' ' )[0]; } ).join( ' ' )
			} );
		},
		/* The whole message's sheet, when it is open. */
		sheet: function () { return find( body, function ( n ) { return has( n, 'fh-fd-msg' ); } )[0] || null; },
		sheetText: function () { var s = page.sheet(); return s ? s.textContent : ''; },
		release: function () { held.splice( 0 ).forEach( function ( go ) { go(); } ); }
	};
	return page;
}

/* A loss on the tank screen: its message is in the line, with its Undo. */
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
	var page, first, big, small, before, node, typed;

	console.log( '1. Always there: in the page as it arrives, first in every bar' );
	check( 'the page itself is written with the bar and the status line in it', /<div class="fh-fd-bar"><div id="fh-fd-status" class="fh-fd-status">.*<\/div><\/div>/.test( view ), '' );
	check( '… and with no toast', -1 === view.indexOf( 'toast' ) );
	page = start( { screen: screen( true, 900 ) } );
	check( 'the tank screen has one bar', 1 === page.bars() && page.bar() !== page.firstBar );
	check( '… whose first row is the very status line the page came with', page.bar().children[0] === page.line && page.line.parentNode === page.bar() );
	check( '… above the screen\'s buttons', 2 === page.bar().children.length && 'fh-fd-bar__inner' === page.bar().children[1].className && 7 === page.bar().children[1].children.length );
	check( 'before anything was done the line is empty: no words, no Undo', '' === page.words() && null === page.undo() && '' === page.kind(), page.kind() + '|' + page.words() );
	check( '… and it is there all the same, at its height', 48 === heightOf( page.line ) );
	check( 'an empty line has nothing to tap: no post, no sheet', ( page.tapWords(), page.tapUndo(), fire( page.line, 'click' ), 0 === page.posts.length && null === page.sheet() ) );
	page.tap( 'Note' );
	check( 'a sheet has a bar of its own — the same line first in it, then Cancel and Save', 1 === page.bars() && page.bar().children[0] === page.line && 2 === page.bar().children[1].children.length );

	page = start( { screen: screen( false, 900 ) } );
	check( 'the tank picker has no buttons of its own: its bar is the status line alone', 1 === page.bars() && 1 === page.bar().children.length && page.bar().children[0] === page.line );
	page = start( { screen: screen( false, 900 ), search: '?list=1' } );
	check( 'the day\'s list: the same', -1 !== page.app.textContent.indexOf( 'Test ammonia' ) && 1 === page.bars() && 1 === page.bar().children.length && page.bar().children[0] === page.line );
	before = page.sizes();
	page.tap( 'Test ammonia' );
	await settle();
	check( 'a line ticked there is answered in that line', 'is-ok' === page.kind() && 'Saved' === page.words() && null === page.undo() && 'todo_tick' === page.posts[0].act, page.words() );
	check( '… which is as tall as before', before === page.sizes(), before + ' → ' + page.sizes() );
	page = start( { screen: screen( false, 900 ), search: '?list=1', reply: function () { return REFUSED; } } );
	page.tap( 'Test ammonia' );
	await settle();
	check( '… and a refusal there too', 'is-error' === page.kind() && 'No fish left to lose.' === page.words() );
	page = start( { screen: screen( false, 900 ) } );
	page.tap( 'Calculator' );
	check( 'the calculator: the line first, then Back', page.bar().children[0] === page.line && 1 === page.bar().children[1].children.length );

	console.log( '2. A message changes no size' );
	page = start( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return REFUSED; } ] ) } );
	before = page.sizes();
	first  = page.bar();
	check( 'the bar as it stands before: the line and three rows of buttons', '{"bar":246,"line":48,"handed":"246px","lineIsFirst":true,"barHolds":2,"lineHolds":"button.fh-fd-status__undo button.fh-fd-status__text span.fh-fd-status__bar span.fh-fd-status__said"}' === before, before );
	styleWrites = [];
	page.tap( '−1 lost' );
	await settle();
	check( 'the message is up: its words and an Undo', LOSS === page.words() && null !== page.undo() );
	check( '… and the bar and the line are as tall as before, the line made of the same parts', before === page.sizes(), page.sizes() );
	pass( 5 );
	check( 'half its seconds on: the same', before === page.sizes(), page.sizes() );
	pass( 5.2 );
	check( 'its seconds are up, the Undo quiet: the same', page.quiet() && before === page.sizes(), page.sizes() );
	pass( 120 );
	check( 'two minutes on: the same', before === page.sizes(), page.sizes() );
	page.tap( '−1 lost' );
	await settle();
	check( 'an error: the same', 'is-error' === page.kind() && before === page.sizes(), page.sizes() );
	pass( 4.2 );
	check( 'the error gone: the same', 'is-last' === page.kind() && before === page.sizes(), page.sizes() );
	var touched = styleWrites.filter( function ( w ) { return /fh-fd-(status|bar)/.test( w[0] ) && ! ( /^fh-fd-status__bar/.test( w[0] ) && 'width' === w[1] ); } );
	check( 'through all of it the script gave no size or place to the line, its parts or the bar: it only ran the thin bar', 0 === touched.length && styleWrites.some( function ( w ) { return 'fh-fd-status__bar' === w[0] && 'width' === w[1]; } ), JSON.stringify( touched ) );
	check( '… and handed the stylesheet nothing but the bar\'s height, the same each time', styleWrites.filter( function ( w ) { return /^--/.test( w[1] ); } ).every( function ( w ) { return '--fd-bar-h' === w[1] && '246px' === w[2]; } ), JSON.stringify( styleWrites.filter( function ( w ) { return /^--/.test( w[1] ); } ) ) );

	page = await lostOne( { screen: screen( true, 900 ), message: LONG } );
	check( 'a long message: all its words are in the line (the stylesheet cuts them), and nothing is taller', LONG === page.words() && before === page.sizes(), page.sizes() );
	check( 'the stylesheet keeps the words on one line and cuts them with "…"', /white-space:\s*nowrap;/.test( rule( '.fh-fd-status__words' ) ) && /text-overflow:\s*ellipsis;/.test( rule( '.fh-fd-status__words' ) ) && /overflow:\s*hidden;/.test( rule( '.fh-fd-status__words' ) ), rule( '.fh-fd-status__words' ) );
	check( '… and the line at one height that follows the text size only, with nothing let out of it', /\n\theight:\s*3em;/.test( rule( '.fh-fd-status' ) ) && /min-height:\s*48px;/.test( rule( '.fh-fd-status' ) ) && /overflow:\s*hidden;/.test( rule( '.fh-fd-status' ) ), rule( '.fh-fd-status' ) );
	layout.line = 62; // Larger text: the line is taller — the observer says so.
	page.observers[0].callback();
	check( 'larger text makes the line taller, and the room at the page\'s foot follows', '260px' === page.barVar(), page.barVar() );
	layout.line = 48;

	console.log( '3. Its life: the words, a filled Undo at the left, the bar running out' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	check( 'one request was posted: the loss', 1 === page.posts.length && 'loss' === page.posts[0].act && 'fishotel_cleanroom_act' === page.posts[0].action, JSON.stringify( page.posts ) );
	check( 'the line says what was written, in the plain colour', LOSS === page.words() && 'is-ok' === page.kind(), page.kind() + '|' + page.words() );
	check( '… with the Undo, filled', page.undo() && '↶ Undo' === page.undo().textContent && ! page.quiet(), page.undo() && page.undo().className );
	check( '… the first thing in the line: at its left end', page.line.children[0] === page.undo() && page.line.children[1] === page.textNode() );
	check( '… named by what it undoes', 'Undo: ' + LOSS === page.undo().attrs['aria-label'], page.undo().attrs['aria-label'] );
	check( '… and the thin bar, full', 100 === page.run(), String( page.run() ) );
	pass( 5 );
	check( 'half the seconds on: the bar is half gone, the Undo still filled', Math.abs( page.run() - 50 ) < 3 && ! page.quiet(), String( page.run() ) );
	pass( 4.9 );
	check( 'just before the seconds end it is still filled', null !== page.undo() && ! page.quiet() );
	node = page.undo();

	console.log( '4. The bar runs out: the Undo turns quiet, in place' );
	pass( 0.2 );
	check( 'the Undo is quiet now — outlined, not filled', page.quiet(), page.undo() && page.undo().className );
	check( '… the very same button, where it was', page.undo() === node && page.line.children[0] === node );
	check( '… still named by what it undoes', 'Undo: ' + LOSS === page.undo().attrs['aria-label'] );
	check( 'the thin bar has run out', 0 === page.run(), String( page.run() ) );
	check( 'the words are the last thing done, in muted words', 'Last: ' + LOSS + ' · just now' === page.words() && 'is-last' === page.kind(), page.kind() + '|' + page.words() );
	check( 'nothing was posted by any of it', 1 === page.posts.length );
	pass( 120 );
	check( 'two minutes on: "· 2 min ago", the Undo still there', 'Last: ' + LOSS + ' · 2 min ago' === page.words() && page.quiet(), page.words() );
	page.tap( 'Today' );
	check( 'a redraw of the same screen (the Today strip opened) keeps it, in the new bar', page.quiet() && page.bar().children[0] === page.line && page.undo() === node );

	console.log( '5. It stays onto a sheet — in the bar it covers nothing' );
	before = page.sizes();
	page.tap( 'Note' );
	check( 'a sheet opened from the bar: the quiet Undo is still in the line, first in the sheet\'s bar', page.quiet() && page.undo() === node && page.bar().children[0] === page.line && page.can( 'Cancel' ) && page.can( 'Save' ) );
	check( '… the line as tall as on the tank screen', 48 === heightOf( page.line ) && heightOf( page.bar() ) === 14 + 48 + 56 );
	page.tap( 'Cancel' );
	check( '… and back on the tank screen, unchanged', page.quiet() && before === page.sizes(), page.sizes() );
	page.tap( 'Mic' ); // Opens its sheet by its own road.
	check( 'the Mic sheet: the same', page.quiet() && page.bar().children[0] === page.line );

	console.log( '5a. On a sheet the Undo asks first: one stray tap beside Save takes back nothing' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 30 );
	page.tap( 'Note' );
	node       = find( page.app, function ( n ) { return 'textarea' === n.tag; } )[0];
	node.value = 'two more look thin';
	fire( node, 'input' );
	before = page.sizes();
	page.tapUndo();
	await settle();
	check( 'a tap on the line\'s Undo on the Note sheet posts nothing', 1 === page.posts.length, JSON.stringify( page.posts ) );
	check( '… it opens the whole message — what would be undone, and its Undo', null !== page.sheet() && -1 !== page.sheetText().indexOf( LOSS ) && page.can( '↶ Undo' ) && page.can( 'Back' ) );
	check( '… the Undo in the line is where it was', page.quiet() && page.line.children[0] === page.undo() );
	page.tapUndo();
	page.tap( '↶ Undo' );
	await settle();
	check( '… a second tap in the same moment, on either Undo, still posts nothing', 1 === page.posts.length );
	page.tap( 'Back' );
	typed = find( page.app, function ( n ) { return 'textarea' === n.tag; } )[0];
	check( 'Back: the Note sheet as it was, what was typed still in its field, the bar as before', null === page.sheet() && typed === node && 'two more look thin' === typed.value && page.can( 'Save' ) && page.can( 'Cancel' ) && before === page.sizes(), page.sizes() );
	page.tapUndo();
	pass( 1 );
	page.tap( '↶ Undo' );
	await settle();
	check( 'opened again and its Undo tapped: now it undoes, and the page is back on the tank screen', 2 === page.posts.length && 'undo' === page.posts[1].act && '41,141' === ( page.posts[1]['event_ids[]'] || [] ).join( ',' ) && null === page.sheet() && page.can( '−1 lost' ) && 'Undone' === page.words(), page.words() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 30 );
	page.tap( 'Mic' );
	page.tapUndo();
	check( 'the Mic sheet: the same — the message first', 1 === page.posts.length && null !== page.sheet() );
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… and with the message open the line\'s Undo undoes as the sheet\'s does', 2 === page.posts.length && 'undo' === page.posts[1].act && page.can( '−1 lost' ) );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 30 );
	page.tap( 'Today' );
	page.tapUndo();
	await settle();
	check( 'on the tank screen — its Today strip open or not — the Undo undoes at once', 2 === page.posts.length && 'undo' === page.posts[1].act && null === page.sheet() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 3 );
	page.tap( 'Note' );
	check( 'a sheet opened 3 s in: the message and its filled Undo go on in the line', LOSS === page.words() && null !== page.undo() && ! page.quiet() && Math.abs( page.run() - 70 ) < 3, String( page.run() ) );
	pass( 7.2 );
	check( '… and turn quiet there on their seconds', page.quiet() && 'is-last' === page.kind() );

	console.log( '6. The next write replaces it' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 60 );
	node = page.undo();
	page.tap( '−1 lost' );
	await settle();
	check( 'another loss: the line is its message, the Undo filled again', LOSS === page.words() && null !== page.undo() && ! page.quiet() && 100 === page.run() );
	check( '… the same button in the same place', page.undo() === node && page.line.children[0] === node );
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… and it undoes the second write, not the first', 3 === page.posts.length && 'undo' === page.posts[2].act && '42,142' === ( page.posts[2]['event_ids[]'] || [] ).join( ',' ), JSON.stringify( page.posts[2] ) );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, SAVED ] ) } );
	pass( 30 );
	page.tap( '−1 lost' );
	await settle();
	check( 'a write with nothing to undo: "Saved" in the plain colour, and the Undo of the write before has gone', 'Saved' === page.words() && 'is-ok' === page.kind() && null === page.undo() );
	pass( 9.8 );
	check( '… there for its seconds, the thin bar running', 'is-ok' === page.kind() && page.run() > 0 && page.run() < 5, String( page.run() ) );
	pass( 0.4 );
	check( '… then the last thing done, with no Undo', 'Last: Saved · just now' === page.words() && 'is-last' === page.kind() && null === page.undo(), page.words() );
	pass( 1200 );
	check( '… and no Undo ever comes back for it', null === page.undo() && 2 === page.posts.length );

	console.log( '7. The window\'s end removes the Undo' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 889 );
	check( 'eleven seconds before the server\'s 15 minutes are up the Undo is still offered', page.quiet() );
	before = page.sizes();
	pass( 1.5 );
	check( 'ten seconds before the end it has gone by itself', null === page.undo() );
	check( '… without posting anything', 1 === page.posts.length );
	check( '… the line still says what was done, and is as tall as ever', 'Last: ' + LOSS + ' · 14 min ago' === page.words() && before === page.sizes(), page.words() );
	check( 'a tap where the Undo was does nothing', ( page.tapUndo(), 1 === page.posts.length ) );
	pass( 3600 );
	check( 'an hour on it counts in hours', 'Last: ' + LOSS + ' · 1 h ago' === page.words(), page.words() );
	check( 'the page is told the window by the server\'s own constant', /'undoWindow'\s*=>\s*self::UNDO_WINDOW/.test( php ) );

	page = await lostOne( { screen: screen( true, 0 ) } );
	check( 'a page that was told no window still offers the Undo for its seconds', null !== page.undo() && ! page.quiet() );
	pass( 10.2 );
	check( '… and then none at all: it is never offered blind', null === page.undo() && 'Last: ' + LOSS + ' · just now' === page.words() );

	page = await lostOne( { screen: screen( true, 25 ) } );
	pass( 10.2 );
	check( 'a short window (25 s): quiet at 10 s', page.quiet() );
	pass( 5 );
	check( '… gone at 15 s, ten before its end', null === page.undo() );

	console.log( '8. Undo, filled or quiet, posts the same' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 1 );
	page.tapUndo();
	await settle();
	big = page.posts[1];
	check( 'the filled Undo posts the undo of the events just written', big && 'undo' === big.act && '41,141' === ( big['event_ids[]'] || [] ).join( ',' ) && '721' === big.tank, JSON.stringify( big ) );
	check( '… and the answer is a plain message: no Undo beside it', 'Undone' === page.words() && 'is-ok' === page.kind() && null === page.undo() );
	pass( 10.2 );
	check( '… which then reads as the last thing done', 'Last: Undone · just now' === page.words() && null === page.undo() );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 300 );
	page.tapUndo();
	await settle();
	small = page.posts[1];
	check( 'the quiet Undo, five minutes later, posts the very same request', JSON.stringify( small ) === JSON.stringify( big ), JSON.stringify( small ) );
	check( '… once', 2 === page.posts.length );
	check( '… and "Undone" is shown', 'Undone' === page.words() );

	console.log( '9. The second of two quick taps does not undo the first' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 0.3 );
	page.tapUndo();
	await settle();
	check( 'a tap on the Undo 0.3 s after it came up posts nothing', 1 === page.posts.length, JSON.stringify( page.posts ) );
	check( '… and the line stays as it was, Undo and all', LOSS === page.words() && null !== page.undo() && ! page.quiet() );
	pass( 0.3 );
	page.tapUndo();
	await settle();
	check( '… nor at 0.6 s', 1 === page.posts.length );
	pass( 0.2 );
	page.tapUndo();
	await settle();
	check( '… at 0.8 s it undoes', 2 === page.posts.length && 'undo' === page.posts[1].act, JSON.stringify( page.posts ) );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 60 );
	page.tap( '−1 lost' );
	await settle();
	pass( 0.3 );
	page.tapUndo();
	await settle();
	check( 'an Undo that has just taken another\'s place is guarded too: nothing posted', 2 === page.posts.length );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 2 );
	fire( page.line, 'click' );
	fire( page.line.children[2], 'click' );
	await settle();
	check( 'a tap on the line beside the Undo, or on the thin bar, does nothing', 1 === page.posts.length && null === page.sheet() && null !== page.undo() );
	page.tapWords();
	await settle();
	check( 'a tap on the words never undoes', 1 === page.posts.length );

	console.log( '9a. A second tap lands where the first did: a write on the tank screen does not scroll the page' );
	page = start( { screen: screen( true, 900 ) } );
	global.pageYOffset = 340; // Scrolled down a long tank to the row being counted.
	page.tap( '−1 lost' );
	await settle();
	check( 'after "−1 lost" on a row the page is where it was scrolled to, not back at the top', 340 === global.pageYOffset && 340 === page.scrolls[ page.scrolls.length - 1 ], JSON.stringify( page.scrolls ) );
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… and after its Undo too', 340 === global.pageYOffset && 2 === page.posts.length );
	page.tap( 'Note' );
	check( 'a sheet opens at its top, as before', 0 === global.pageYOffset );
	global.pageYOffset = 120;
	page.tap( 'Save' );
	await settle();
	check( '… and its Save comes back to the tank screen at the top', 0 === global.pageYOffset && 3 === page.posts.length && 'note' === page.posts[2].act, JSON.stringify( page.scrolls ) );
	global.pageYOffset = 200;
	page.tapWords();
	check( 'the whole message opens at its top', null !== page.sheet() && 0 === global.pageYOffset );
	check( '(its bar is shorter — Back alone under the line — and the page\'s foot room with it)', '118px' === page.barVar(), page.barVar() );
	page.tap( 'Back' );
	check( '… and Back puts the screen under it where it was', 200 === global.pageYOffset );
	check( '… with the room at the page\'s foot given back BEFORE the page is put there: one that was at its foot can be again', 200 === page.scrolls[ page.scrolls.length - 1 ] && '246px' === page.scrollsAt[ page.scrollsAt.length - 1 ], JSON.stringify( page.scrollsAt ) );

	console.log( '10. A phone that slept: the clock went on, its timers did not run' );
	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10.2 );
	clock.now += 30 * 60 * 1000; // Half an hour locked: no timer ran.
	check( 'the page hears when it is looked at again', 'function' === typeof page.documentOn.visibilitychange && 'function' === typeof page.windowOn.pageshow );
	check( '(the Undo is still on the page: its timer was held)', page.quiet() );
	page.documentOn.visibilitychange();
	check( 'looked at again after 30 minutes: the Undo has gone', null === page.undo() );
	check( '… the line says how long ago', 'Last: ' + LOSS + ' · 30 min ago' === page.words(), page.words() );
	check( '… and nothing was posted', 1 === page.posts.length );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10.2 );
	clock.now += 30 * 60 * 1000;
	before = page.sizes();
	page.tapUndo(); // The thumb is faster than the page's waking.
	await settle();
	check( 'a tap on an Undo whose window ended asks nothing of the server', 1 === page.posts.length, JSON.stringify( page.posts ) );
	check( '… and says so plainly, as the server would', 'is-error' === page.kind() && 'That can no longer be undone.' === page.words() && null === page.undo(), page.words() );
	check( '… in the line, which is as tall as before', before === page.sizes() );
	pass( 4.2 );
	check( '… and that Undo does not come back with the error gone', null === page.undo() && 'is-last' === page.kind() );
	check( 'the page has those words from the server', /'undoLate'\s*=>\s*__\( 'That can no longer be undone\.'/.test( php ) && 2 === php.split( "'That can no longer be undone.'" ).length - 1 );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 10.2 );
	clock.now += 5 * 60 * 1000;
	page.windowOn.pageshow();
	check( 'a five-minute sleep leaves the Undo: its window is not over', page.quiet() && 'Last: ' + LOSS + ' · 5 min ago' === page.words(), page.words() );
	page.tapUndo();
	await settle();
	check( '… and its tap undoes', 2 === page.posts.length && 'undo' === page.posts[1].act );

	page = await lostOne( { screen: screen( true, 900 ) } );
	pass( 4 );
	clock.now += 60 * 1000; // A minute asleep inside the message's seconds.
	page.documentOn.visibilitychange();
	check( 'a message that slept past its seconds is quiet when looked at again', page.quiet() && 'is-last' === page.kind() && 0 === page.run() );

	page = await lostOne( { screen: screen( true, 900 ), reply: SAVED } );
	pass( 4 );
	page.documentOn.visibilitychange();
	check( 'a "Saved" looked at again inside its seconds is still fresh', 'is-ok' === page.kind() );
	clock.now += 60 * 1000;
	page.documentOn.visibilitychange();
	check( '… and one that slept past them is the last thing done', 'is-last' === page.kind() && 'Last: Saved · 1 min ago' === page.words(), page.words() );

	console.log( '11. An error keeps an Undo that is still good' );
	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return REFUSED; } ] ) } );
	pass( 60 );
	node = page.undo();
	page.tap( '−1 lost' );
	await settle();
	check( 'a refusal: the server\'s words in the error colour', 'is-error' === page.kind() && 'No fish left to lose.' === page.words(), page.kind() + '|' + page.words() );
	check( '… with no Undo beside it — an error has nothing to undo', null === page.undo() );
	check( '… but the Undo\'s place is kept, empty: the same button, first in the line, not seen and taking no tap', page.held() && page.undoNode() === node && page.line.children[0] === node && page.line.children[1] === page.textNode() );
	check( '… the stylesheet hides it without giving up its box', /visibility:\s*hidden;/.test( rule( '.fh-fd-status__undo.is-held' ) ) && ! /display/.test( rule( '.fh-fd-status__undo.is-held' ) ), rule( '.fh-fd-status__undo.is-held' ) );
	before = page.sizes();
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… a tap where the Undo was does nothing: no undo, and the error is not opened as a sheet', 2 === page.posts.length && null === page.sheet() && 'is-error' === page.kind() );
	check( '… and its own thin bar', page.run() > 60 );
	pass( 2.8 );
	check( '… there for its 4 seconds', 'is-error' === page.kind() );
	pass( 0.4 );
	check( '… then the Undo of the write before is back, quiet, where it was', page.quiet() && ! page.held() && false === node.disabled && page.undo() === node && page.line.children[0] === node && 'Undo: ' + LOSS === node.attrs['aria-label'] );
	check( '… the line made of the same parts all the while', before === page.sizes(), page.sizes() );
	check( '… beside the last thing done', 'Last: ' + LOSS + ' · 1 min ago' === page.words(), page.words() );
	page.tapUndo();
	await settle();
	check( '… guarded as it comes back: a tap in its first moment posts nothing', 2 === page.posts.length );
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… and then it undoes the write it stood for', 3 === page.posts.length && 'undo' === page.posts[2].act && '41,141' === ( page.posts[2]['event_ids[]'] || [] ).join( ',' ), JSON.stringify( page.posts[2] ) );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return REFUSED; } ] ) } );
	pass( 3 );
	page.tap( '−1 lost' );
	await settle();
	check( 'an error inside the message\'s own seconds takes the line, the Undo\'s place kept', 'is-error' === page.kind() && null === page.undo() && page.held() );
	pass( 4.2 );
	check( '… and gives it back: the message, its Undo still filled, what is left of its bar', LOSS === page.words() && null !== page.undo() && ! page.quiet() && Math.abs( page.run() - 28 ) < 4, String( page.run() ) );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return REFUSED; } ] ) } );
	pass( 30 );
	page.tap( '−1 lost' );
	await settle();
	page.tap( 'Note' );
	check( 'another screen opened: the error has had its say, the Undo is back at once', 'is-last' === page.kind() && page.quiet() );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return REFUSED; } ] ) } );
	pass( 888 );
	page.tap( '−1 lost' );
	await settle();
	pass( 4.2 );
	check( 'an error that outlasts the Undo\'s time: no Undo comes back, and no place is kept for one', null === page.undo() && ! page.held() && true === page.undoNode().hidden && 'is-last' === page.kind() );

	page = start( { screen: screen( true, 900 ), reply: function () { return REFUSED; } } );
	page.tap( '−1 lost' );
	await settle();
	check( 'an error with no Undo behind it: no place is kept — its words start at the left', 'is-error' === page.kind() && ! page.held() && true === page.undoNode().hidden );
	page.tapWords();
	check( '… and a tap on them opens them', null !== page.sheet() && -1 !== page.sheetText().indexOf( 'No fish left to lose.' ) );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return { refused: { message: 'That can no longer be undone.' } }; } ] ) } );
	pass( 600 );
	page.tapUndo();
	await settle();
	check( 'an undo the server refuses as too late says so plainly', 'is-error' === page.kind() && 'That can no longer be undone.' === page.words(), page.words() );
	pass( 4.2 );
	check( '… and that Undo is over: it is not offered again', null === page.undo() && 2 === page.posts.length );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return { offline: true }; } ] ) } );
	pass( 20 );
	page.tapUndo();
	await settle();
	check( 'an undo that never reached the server (no signal): an error', 'is-error' === page.kind() && 2 === page.posts.length, page.words() );
	check( '… said in plain words, not the browser\'s', OFFLINE === page.words(), page.words() );
	pass( 4.2 );
	check( '… and the Undo is back for another try', page.quiet() );
	pass( 1 );
	page.tapUndo();
	await settle();
	check( '… which goes through', 3 === page.posts.length && 'undo' === page.posts[2].act && 'Undone' === page.words(), page.words() );

	console.log( '11b. No answer at all (no signal): plain words, never the browser\'s own' );
	var timedOut  = new Error( 'The operation was aborted.' );
	timedOut.name = 'AbortError';
	var browsers  = [
		[ 'Chrome', new TypeError( 'Failed to fetch' ) ],
		[ 'Safari', new TypeError( 'Load failed' ) ],
		[ 'Firefox', new TypeError( 'NetworkError when attempting to fetch resource.' ) ],
		[ 'a request given up on', timedOut ],
		[ 'a rejection with nothing in it', { name: '', message: '' } ]
	];
	for ( var b = 0; b < browsers.length; b++ ) {
		page = start( { screen: screen( true, 900 ), reply: ( function ( thrown ) { return function () { return { offline: thrown }; }; }( browsers[ b ][1] ) ) } );
		page.tap( '−1 lost' );
		await settle();
		check( 'a write that got no answer, ' + browsers[ b ][0] + ': "' + OFFLINE + '" in the error colour', 'is-error' === page.kind() && OFFLINE === page.words(), page.words() );
		page.tapWords();
		check( '… and the whole message says the same: nowhere "' + ( browsers[ b ][1].message || 'undefined' ) + '"', -1 !== page.sheetText().indexOf( OFFLINE ) && ( ! browsers[ b ][1].message || -1 === page.sheetText().indexOf( browsers[ b ][1].message ) ), page.sheetText() );
	}
	page = start( { screen: screen( true, 900 ), reply: function () { return { offline: true }; } } );
	page.tap( '−1 lost' );
	await settle();
	pass( 4.2 );
	page.tap( '−1 lost' );
	await settle();
	check( 'the page is not left busy by it: the next tap is sent', 2 === page.posts.length && 'loss' === page.posts[1].act && OFFLINE === page.words(), page.words() );
	page = start( { screen: screen( true, 900 ), reply: function () { return { offline: true }; } } );
	page.tap( 'Refresh' );
	await settle();
	check( 'a read that got no answer (Refresh) does not speak of saving: "' + OFFLINE_READ + '"', 'is-error' === page.kind() && OFFLINE_READ === page.words() && 'fishotel_cleanroom_screen' === page.posts[0].action, page.words() );
	page = start( { screen: screen( true, 900 ), i18n: { offline: 'Keine Verbindung.' }, reply: function () { return { offline: true }; } } );
	page.tap( '−1 lost' );
	await settle();
	check( 'the words are the page\'s own where it has them (i18n.offline)', 'Keine Verbindung.' === page.words(), page.words() );
	check( '… and the page has them: class-front-desk.php hands over the very words the script falls back on', -1 !== php.indexOf( "'offline'     => __( '" + OFFLINE + "'," ) && -1 !== php.indexOf( "'offlineRead' => __( '" + OFFLINE_READ + "'," ) );
	var GUARD = 'Not confirmed as saved. Check the connection, then save again — the same entry is written once.';
	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return { offline: new Error( GUARD ) }; } ] ) } );
	pass( 20 );
	page.tapUndo();
	await settle();
	check( 'a sentence written for a person by what stands before fetch (the Shop App\'s guard) is said as it is', 'is-error' === page.kind() && GUARD === page.words(), page.words() );
	pass( 4.2 );
	check( '… and an undo it was said for never got there either: its Undo is back', page.quiet() );
	page = start( { screen: screen( true, 900 ), reply: function () { return { refused: { message: 'offline' } }; } } );
	page.tap( '−1 lost' );
	await settle();
	check( 'the server\'s own no is never taken for it: a refusal is said in the server\'s words', 'is-error' === page.kind() && 'offline' === page.words(), page.words() );

	page = start( { screen: screen( true, 900 ), reply: function () { return { status: 403 }; } } );
	page.tap( '−1 lost' );
	await settle();
	check( 'a session that expired: said in the line, in the error colour', 'is-error' === page.kind() && -1 !== page.words().indexOf( 'Session expired' ), page.words() );
	pass( 60 );
	check( '… and it stays until it is tapped', 'is-error' === page.kind() );
	page.tapWords();
	check( '… a tap on its words reloads the page — it opens no sheet', 1 === page.reloads() && null === page.sheet() );

	console.log( '11a. What a screen reader is told: the words of a message, alone, once' );
	check( 'the page is written with one span for it in the line, a status region from the first paint', /<div id="fh-fd-status" class="fh-fd-status"><span id="fh-fd-said" class="fh-fd-status__said" role="status"><\/span><\/div>/.test( view ) );
	check( '… and the line itself is no live region, in the page or by the script', ! /fh-fd-status[^>]*aria-live/.test( view ) && ! /statusEl\.setAttribute\(\s*'aria-live'/.test( fs.readFileSync( file, 'utf8' ) ) );
	page = start( { screen: screen( true, 900 ), reply: inTurn( [ null, null, function () { return REFUSED; }, SAVED ] ) } );
	first = page.said();
	check( 'the script keeps the very span the page came with, in the line', first && first.parentNode === page.line && 'status' === first.attrs.role && '' === first.textContent );
	page.tap( '−1 lost' );
	await settle();
	check( 'a message: the span holds its words and nothing else — no "Undo:", no "Last:"', LOSS === page.said().textContent && page.said() === first, page.said().textContent );
	check( '… the line has no aria-live of its own, so "Undo: …" beside the words is not read with them', undefined === page.line.attrs['aria-live'] && 'Undo: ' + LOSS === page.undo().attrs['aria-label'] );
	pass( 10.2 );
	pass( 300 );
	check( 'the Undo turned quiet, "Last: … · 5 min ago": nothing new is told', LOSS === page.said().textContent && 'Last: ' + LOSS + ' · 5 min ago' === page.words(), page.said().textContent );
	page.tap( '−1 lost' );
	await settle();
	check( 'the same words a second time are told again: the span\'s text is not what it was', LOSS !== page.said().textContent && LOSS === page.said().textContent.trim() && LOSS === page.said().textContent.replace( /\u00a0$/, '' ), JSON.stringify( page.said().textContent ) );
	pass( 60 );
	page.tap( '−1 lost' );
	await settle();
	check( 'an error is told', 'No fish left to lose.' === page.said().textContent );
	pass( 4.2 );
	check( '… and when it has gone and the Undo is back, nothing is told again', 'No fish left to lose.' === page.said().textContent && page.quiet() );
	page.tap( 'Note' );
	check( 'on a sheet the span is still in the line, in the sheet\'s bar', page.said() === first && first.parentNode === page.line && page.line.parentNode === page.bar() );
	page.tap( 'Save' );
	await settle();
	check( 'a plain "Saved" is told', 'Saved' === page.said().textContent );
	check( 'the stylesheet takes the span out of sight without taking it from the reader, and gives it no room in the line', /position:\s*absolute;/.test( rule( '.fh-fd-status__said' ) ) && /width:\s*1px;/.test( rule( '.fh-fd-status__said' ) ) && /height:\s*1px;/.test( rule( '.fh-fd-status__said' ) ) && /overflow:\s*hidden;/.test( rule( '.fh-fd-status__said' ) ) && ! /display:\s*none|visibility/.test( rule( '.fh-fd-status__said' ) ), rule( '.fh-fd-status__said' ) );
	page = start( { screen: screen( true, 900 ), bareLine: true } );
	check( 'a page whose line came without the span is given one by the script', page.said() && 'status' === page.said().attrs.role && page.said().parentNode === page.line && page.line.children[0] === page.undoNode() );

	console.log( '12. The Undo is not thrown away while another write is under way' );
	page = start( { screen: screen( true, 900 ), hold: true } );
	page.tap( '−1 lost' );
	page.release();
	await settle();
	pass( 12 );
	page.tap( '−1 lost' ); // Under way: its reply is held.
	page.tapUndo();
	check( 'a tap on the Undo while a write is under way posts nothing', 2 === page.posts.length && 'loss' === page.posts[1].act );
	check( '… and the Undo stays for the next tap', page.quiet() );

	console.log( '13. A tap on the words opens the whole message' );
	page = await lostOne( { screen: screen( true, 900 ), message: LONG } );
	pass( 2 );
	before = page.sizes();
	page.tapWords();
	check( 'the whole message is on a sheet of its own, every word of it', null !== page.sheet() && -1 !== page.sheetText().indexOf( LONG ) && -1 !== page.sheetText().indexOf( 'Message' ) );
	check( '… the screen under it is put aside, not thrown away', true === page.app.hidden && ! page.can( '−1 lost' ) && ! page.can( 'Lost' ) );
	check( '… the bar is the same bar: the line first, then Back', page.bar().children[0] === page.line && page.can( 'Back' ) && 48 === heightOf( page.line ) );
	check( '… the Undo is there too, big, named by what it undoes', page.can( '↶ Undo' ) && 'Undo: ' + LONG === find( page.sheet(), function ( n ) { return 'button' === n.tag; } )[0].attrs['aria-label'] );
	check( '… and still in the line', null !== page.undo() );
	check( 'nothing was posted by opening it', 1 === page.posts.length );
	page.tap( '↶ Undo' );
	await settle();
	check( 'the sheet\'s Undo, tapped as the sheet comes up, takes no tap', 1 === page.posts.length && null !== page.sheet() );
	page.tap( 'Back' );
	check( 'Back: the tank screen as it was, the bar as tall as before', null === page.sheet() && false === page.app.hidden && page.can( '−1 lost' ) && page.can( 'Lost' ) && ! page.can( 'Back' ) && before === page.sizes(), page.sizes() );
	pass( 1 );
	page.tapWords();
	pass( 1 );
	page.tap( '↶ Undo' );
	await settle();
	check( 'opened again, the sheet\'s Undo undoes', 2 === page.posts.length && 'undo' === page.posts[1].act && '41,141' === ( page.posts[1]['event_ids[]'] || [] ).join( ',' ) );
	check( '… and the page is back on the tank screen, saying so', null === page.sheet() && false === page.app.hidden && 'Undone' === page.words() && page.can( '−1 lost' ) );

	page = await lostOne( { screen: screen( true, 900 ), message: LONG } );
	pass( 400 );
	page.tapWords();
	check( 'opened later: the words without "Last:", how long ago, the Undo while it is good', -1 !== page.sheetText().indexOf( LONG ) && -1 === page.sheetText().indexOf( 'Last:' ) && -1 !== page.sheetText().indexOf( '6 min ago' ) && page.can( '↶ Undo' ), page.sheetText() );
	pass( 500 );
	check( 'the Undo\'s time ends while the sheet is open: its Undo goes, the words stay', null !== page.sheet() && ! page.can( '↶ Undo' ) && -1 !== page.sheetText().indexOf( LONG ) && -1 !== page.sheetText().indexOf( '15 min ago' ), page.sheetText() );

	page = await lostOne( { screen: screen( true, 900 ), reply: inTurn( [ null, function () { return { refused: { message: LONG } }; } ] ) } );
	pass( 30 );
	page.tap( 'Note' );
	node  = find( page.app, function ( n ) { return 'textarea' === n.tag; } )[0];
	node.value = 'three dead at the back';
	fire( node, 'input' );
	page.tap( 'Save' );
	await settle();
	check( 'a long refusal on a sheet is cut in the line like any words', 'is-error' === page.kind() && LONG === page.words() );
	page.tapWords();
	check( '… a tap opens all of it', null !== page.sheet() && -1 !== page.sheetText().indexOf( LONG ) );
	check( '… with the Undo that is still good, and what it would undo', page.can( '↶ Undo' ) && -1 !== page.sheetText().indexOf( 'Last: ' + LOSS ), page.sheetText() );
	pass( 6 );
	check( '… and it is still there to read after the error has left the line', null !== page.sheet() && -1 !== page.sheetText().indexOf( LONG ) && 'is-last' === page.kind() );
	page.tap( 'Back' );
	typed = find( page.app, function ( n ) { return 'textarea' === n.tag; } )[0];
	check( 'Back: the sheet that was open, its own field with what was typed in it', false === page.app.hidden && typed === node && 'three dead at the back' === typed.value && page.can( 'Save' ) && page.can( 'Cancel' ) );
	page.tap( 'Save' );
	await settle();
	check( '… and its Save still posts it', 3 === page.posts.length && 'note' === page.posts[2].act && 'three dead at the back' === page.posts[2].note, JSON.stringify( page.posts[2] ) );

	console.log( '14. The words for the last thing done' );
	page = start( { screen: screen( true, 900 ) } );
	var ago = page.helpers.agoText;
	check( 'under a minute: "just now"', 'just now' === ago( 0 ) && 'just now' === ago( 59999 ) );
	check( 'minutes, counted down: "1 min ago", "59 min ago"', '1 min ago' === ago( 60000 ) && '2 min ago' === ago( 179999 ) && '59 min ago' === ago( 3599999 ) );
	check( 'then hours: "1 h ago", "3 h ago"', '1 h ago' === ago( 3600000 ) && '3 h ago' === ago( 3 * 3600000 + 5 ) );
	check( 'the page\'s own words are used when it has them', 'eben' === ago( 5, { justNow: 'eben' } ) && 'vor 4 Min.' === ago( 240000, { minAgo: 'vor %d Min.' } ) && 'vor 2 Std.' === ago( 7200000, { hoursAgo: 'vor %d Std.' } ) );
	check( 'a clock set back does not count backwards', 'just now' === ago( -5000 ) );
	check( 'the server sends those words', /'lastDone'\s*=>\s*__\( 'Last: %s'/.test( php ) && /'justNow'\s*=>/.test( php ) && /'minAgo'\s*=>\s*__\( '%d min ago'/.test( php ) && /'hoursAgo'\s*=>\s*__\( '%d h ago'/.test( php ) && /'message'\s*=>\s*__\( 'Message'/.test( php ) );

	console.log( '15. The bar is measured as it stands, for the room at the page\'s foot' );
	page = start( { screen: screen( true, 900 ) } );
	check( 'the tank screen\'s bar is measured at the first render: the line and three rows, 246 px', '246px' === page.barVar(), page.barVar() );
	check( 'one ResizeObserver watches the bar that is on the page', 1 === page.observers.length && 1 === page.observers[0].nodes.length && page.observers[0].nodes[0] === page.bar() );
	layout.row = 74; // Larger text: the labels wrap, the bar grows.
	page.observers[0].callback();
	check( 'the bar grows (larger text): the observer hands on the new height', '300px' === page.barVar(), page.barVar() );
	layout.row = 56;
	first = page.bar();
	page.tap( 'Note' );
	check( 'a sheet\'s bar — the line and one row — is measured anew', '118px' === page.barVar() && page.bar() !== first, page.barVar() );
	check( '… and the observer watches the new bar, not the one that left', page.observers[0].nodes[0] === page.bar() && -1 === page.observers[0].nodes.indexOf( first ) );
	page = start( { screen: screen( false, 900 ) } );
	check( 'the picker\'s bar is the line alone: room is kept for that', '62px' === page.barVar(), page.barVar() );
	page = start( { screen: screen( true, 900 ), noObserver: true } );
	check( 'without ResizeObserver the bar is still measured at the render', 0 === page.observers.length && '246px' === page.barVar(), page.barVar() );
	layout.row = 40;
	check( '… and when the window changes', 'function' === typeof page.windowOn.resize && ( page.windowOn.resize(), '198px' === page.barVar() ), page.barVar() );
	layout.row = 56;
	page.tap( '−1 lost' );
	await settle();
	pass( 2 );
	page.tapWords();
	check( '… and when the whole message opens (its bar has one row) and closes', '118px' === page.barVar() && ( page.tap( 'Back' ), '246px' === page.barVar() ), page.barVar() );

	console.log( '16. The stylesheet: one line in the bar, nothing that floats' );
	check( 'the page keeps room at its foot for the measured bar, and for nothing else', /padding-bottom:\s*calc\(max\(var\(--fd-bar-h\), env\(safe-area-inset-bottom, 0px\)\) \+ \d+px\)/.test( rule( 'body.fh-fd' ) ), rule( 'body.fh-fd' ) );
	check( 'the measure starts at nothing until the script has measured', /--fd-bar-h:\s*0px;/.test( rule( ':root' ) ) );
	check( 'no place is worked out from a counted number of rows', ! /var\(--fd-tap\)\s*\*/.test( css ) );
	check( 'the toast, its fold, its strip and its measure are gone from the stylesheet and the script', ! /toast|is-pill|is-sticky|fd-fold/.test( css ) && ! /toast|foldToast|headClear|elementFromPoint|is-pill/i.test( fs.readFileSync( file, 'utf8' ) ) );
	var fixed = ( css.replace( /\/\*[\s\S]*?\*\//g, '' ).match( /[^{}]+\{[^{}]*position:\s*fixed;[^{}]*\}/g ) || [] ).map( function ( block ) { return block.split( '{' )[0].trim(); } );
	check( 'the only thing fixed to the screen is the bar itself', 1 === fixed.length && '.fh-fd-bar' === fixed[0], fixed.join( ' | ' ) );
	check( 'the status line is laid out in the bar\'s own flow: it is not placed over anything', ! /position:\s*(fixed|absolute|sticky)/.test( rule( '.fh-fd-status' ) ) );
	check( 'the Undo is at least 44 px each way, and follows the text size', /min-height:\s*(4[4-9]|[5-9]\d)px;/.test( rule( '.fh-fd-status__undo' ) ) && /min-width:\s*(4[4-9]|[5-9]\d)px;/.test( rule( '.fh-fd-status__undo' ) ) && /\n\theight:\s*[\d.]+em;/.test( rule( '.fh-fd-status__undo' ) ), rule( '.fh-fd-status__undo' ) );
	check( 'quiet, it changes its colours only: no size, no place', ! /(height|width|padding|margin|font|display|position|flex)\b/.test( rule( '.fh-fd-status__undo.is-quiet' ) ) && /background:\s*transparent;/.test( rule( '.fh-fd-status__undo.is-quiet' ) ), rule( '.fh-fd-status__undo.is-quiet' ) );
	check( 'a message in its seconds, an error and the last thing done differ by colour only', [ '.fh-fd-status.is-ok', '.fh-fd-status.is-error', '.fh-fd-status.is-last' ].every( function ( sel ) { return '' !== rule( sel ) && ! /(height|width|padding|margin|font|display|position|flex|border)\b/.test( rule( sel ) ); } ) );
	check( 'the thin bar lies along the line\'s lower edge and takes no tap', /position:\s*absolute;/.test( rule( '.fh-fd-status__bar' ) ) && /bottom:\s*0;/.test( rule( '.fh-fd-status__bar' ) ) && /pointer-events:\s*none;/.test( rule( '.fh-fd-status__bar' ) ) );
	var calm = css.slice( css.indexOf( '@media (prefers-reduced-motion: reduce)' ) );
	check( 'reduced motion: the thin bar does not glide', -1 !== css.indexOf( '@media (prefers-reduced-motion: reduce)' ) && /\.fh-fd-status__bar \{\s*transition:\s*none;/.test( calm ) );
	check( 'no new colour: the line uses the page\'s own and the two the message always had', ( css.slice( css.indexOf( '\n.fh-fd-status {' ), css.indexOf( '/* The whole message, opened' ) ).match( /#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/gi ) || [] ).sort().join( ' ' ) === '#0b3d26 #4a1515' );

	console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
	process.exit( failures ? 1 : 0 );
}

run().catch( function ( error ) {
	console.log( '  FAIL the run threw: ' + ( error && error.stack ? error.stack : error ) );
	process.exit( 1 );
} );
