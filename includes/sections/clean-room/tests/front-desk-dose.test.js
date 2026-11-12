/**
 * Front Desk JS — the Dose sheet, the Calculator and a dose phrase's
 * preview, run for real: js/front-desk.js (with js/dose-math.js) is
 * started against a tiny stand-in DOM and a fetch that records what is
 * posted, and the screens are tapped and typed into.
 *
 * Covers what the pure helpers cannot: the sheet fills "Now" with today's
 * reading and "Bring it to" with the due step's level, shows the result
 * line and its working, puts the result into the amount field and leaves
 * it editable, follows the water (whole system / this tank only), the
 * rate chips (the medication's own, a named dose, a schedule step's rate
 * due today), the Top-up switch; says before Save that a dose has no undo
 * and that the copper reading is logged with it; Save posts ONE request
 * with the amount as it stands and the sum's inputs; the Calculator posts
 * nothing, with a tank and without one; a dose phrase is previewed with
 * its sum, asks for a missing reading, is never auto-confirmed, and
 * Confirm posts the same Save. Every medication, name and number of the
 * page below is invented, except the copper sum the shop asked for.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk-dose.test.js
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
	this.tag         = tag;
	this.children    = [];
	this.attrs       = {};
	this.listeners   = {};
	this.style       = {};
	this.className   = '';
	this.value       = '';
	this.hidden      = false;
	this.disabled    = false;
	this.selected    = false;
	this.parentNode  = null;
	this._text       = '';
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

/* The text a person would read: hidden parts left out. */
function shown( node ) {
	if ( ! node || node.hidden ) {
		return '';
	}
	if ( '#text' === node.tag || ! node.children.length ) {
		return node._text;
	}
	return node.children.map( shown ).join( ' ' );
}

function fire( node, type, extra ) {
	var event = { target: node, key: '', stopPropagation: function () {}, preventDefault: function () {} };
	Object.keys( extra || {} ).forEach( function ( k ) { event[ k ] = extra[ k ]; } );
	( node.listeners[ type ] || [] ).slice().forEach( function ( fn ) { fn( event ); } );
}

/* ------------------------------------------------------------------ */
/*  The page                                                           */
/* ------------------------------------------------------------------ */

var MEDS = [
	{ key: 'copper', label: 'Copper Power', short: 'Copper', unit: 'ml', mode: 'to_level', rate: 0.692, per: 1, levelUnit: 'ppm', copper: true, doses: [], measure: null },
	{ key: 'metro', label: 'Metronidazole', short: 'Metro', unit: 'mg', mode: 'per_gallon', rate: 25, per: 1, levelUnit: '', copper: false, doses: [], measure: { label: 'scoop', amount: 0.5, unit: 'g' } },
	{ key: 'quine', label: 'Test Quine', short: 'Quine', unit: 'mg', mode: 'per_gallon', rate: 10, per: 1, levelUnit: '', copper: false, doses: [ { label: 'Loading', rate: 40 }, { label: 'Top-up', rate: 12 } ], measure: null },
	{ key: 'ramp', label: 'Test Ramp Liquid', short: 'Ramp', unit: 'ml', mode: 'to_level', rate: 2, per: 5, levelUnit: 'ppm', copper: false, doses: [], measure: null },
	{ key: 'salt', label: 'Salt', short: 'Salt', unit: 'tsp', mode: 'typed', rate: 0, per: 1, levelUnit: '', copper: false, doses: [], measure: null }
];

function water( gallons, reading, copperStart, quineStart ) {
	return {
		gallons: gallons,
		reading: reading,
		meds: {
			copper: copperStart,
			metro: { now: null, nowAt: '', target: null, targetFrom: '', rate: 25, rateFrom: '', options: [] },
			quine: quineStart,
			ramp: { now: null, nowAt: '', target: null, targetFrom: '', rate: null, rateFrom: '', options: [] }
		}
	};
}

function screen( withTank, systemGallons ) {
	var data = {
		today: '2026-11-12',
		tablesOk: true,
		grid: [ { area: 'spa', label: 'Spa', buildings: [ { id: 6, number: 600, label: 'Building 600', status: 'active', inQt: 1, tanks: [ { id: 21, number: 611, level: 1, sizeLabel: '40G breeder', empty: true, count: 0, current: false, url: '#611' }, { id: 22, number: 621, level: 2, sizeLabel: '40G breeder', empty: false, count: 1, current: true, url: '#621' } ] } ] } ],
		spaTanks: [],
		recent: [],
		sizes: [],
		medications: MEDS,
		settings: { autoConfirmSeconds: 4, voiceEnabled: true, undoSeconds: 10, copperFloor: 2.25, copperTarget: 2.5 },
		tank: null
	};
	if ( ! withTank ) {
		return data;
	}
	var gallons = typeof systemGallons === 'undefined' ? 100 : systemGallons;
	data.tank = {
		id: 22, number: 621, title: 'Tank 621', areaLabel: 'Spa', building: 'Building 600', buildingId: 6, isSpa: true, sizeLabel: '40G breeder', gallons: 40, sharesWith: [ 611 ],
		system: { gallons: gallons, label: gallons > 0 ? gallons + ' gal system' : '', schedule: '14 Day CM' },
		dose: {
			// The system: 2.16 read today at 09:14, a group on day 2 ("Copper Power to 2.4"), a quine step at 40 due.
			system: water( gallons, { value: 2.16, at: '09:14' }, { now: 2.16, nowAt: '09:14', target: 2.4, targetFrom: 'schedule', rate: null, rateFrom: '', options: [ { value: 2.4, label: 'Copper Power to 2.4', day: 2 } ] }, { now: null, nowAt: '', target: null, targetFrom: '', rate: 40, rateFrom: 'schedule', options: [ { value: 40, label: 'Test Quine 40 mg/gal', day: 3 } ] } ),
			// This tank alone: no reading of its own today, nothing due.
			tank: water( 40, null, { now: null, nowAt: '', target: 2.5, targetFrom: 'settings', rate: null, rateFrom: '', options: [] }, { now: null, nowAt: '', target: null, targetFrom: '', rate: 10, rateFrom: '', options: [] } )
		},
		occupants: [ { stayId: 7, species: 'Test Goby', size: '', sizeSlug: '', sizeStandard: true, productId: null, qtyLive: 2, qtyArrived: 2, day: 2, status: 'in_qt', stateLabel: 'in QT', checkinDate: '2026-11-11', clearedDate: null, saleReadyDate: '', saleReadyManual: null, saleSource: '', batchSaleDate: null, saleSuggested: '2026-12-09', saleReady: false, readyInSpa: false, needsId: false } ],
		todo: [],
		copper: { value: 2.16, loggedAt: '2026-11-12 15:14:00', ago: '5 min', state: 'low' },
		url: '#621'
	};
	return data;
}

/*
 * Start the app on a fresh page. Returns the handles a test needs.
 */
function start( config ) {
	var app   = new El( 'div' );
	var toast = new El( 'div' );
	var body  = new El( 'body' );
	var posts = [];
	var reply = config.reply || function () { return { message: 'Saved', undo: { eventIds: [] }, screen: config.screen }; };

	global.window         = global;
	global.document       = {
		createElement: function ( tag ) { return new El( tag ); },
		createTextNode: function ( text ) { var n = new El( '#text' ); n._text = String( text ); return n; },
		getElementById: function ( id ) { return 'fh-fd-app' === id ? app : ( 'fh-fd-toast' === id ? toast : find( body, function ( n ) { return n.attrs.id === id; } ).concat( find( app, function ( n ) { return n.attrs.id === id; } ) )[0] || null ); },
		querySelector: function ( selector ) { return find( body, function ( n ) { return '.' + n.className === selector; } )[0] || null; },
		body: body
	};
	global.sessionStorage = { getItem: function () { return '1'; }, setItem: function () {} };
	global.scrollTo       = function () {};
	global.location       = { href: '', reload: function () {} };
	global.fetch          = function ( url, options ) {
		var fields = {};
		new URLSearchParams( options.body ).forEach( function ( value, key ) { fields[ key ] = value; } );
		posts.push( fields );
		return Promise.resolve( { status: 200, json: function () { return Promise.resolve( { success: true, data: reply( fields ) } ); } } );
	};
	delete global.SpeechRecognition;
	delete global.webkitSpeechRecognition;
	global.fhFrontDesk    = { ajaxUrl: '/ajax', nonce: 'n', screen: config.screen, baseUrl: '#', boardUrl: '#board', view: config.view || '', i18n: {} };

	delete require.cache[ require.resolve( file ) ];
	require( file );

	/* Every button whose text holds the label — in the app, then in the bar under it; hidden ones too. */
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
		text: function () { return shown( app ).replace( /\s+/g, ' ' ); },
		tap: function ( label ) {
			var hit = buttons( label ).filter( function ( n ) { return ! n.disabled && ! n.hidden; } )[0];
			if ( ! hit ) {
				throw new Error( 'no button "' + label + '" in: ' + shown( app ).replace( /\s+/g, ' ' ).slice( 0, 300 ) );
			}
			fire( hit, 'click' );
			return hit;
		},
		/* A button by its whole text ("gal" is also in "100 gal"). */
		tapExact: function ( label ) {
			var hit = buttons( label ).filter( function ( n ) { return n.textContent === label && ! n.disabled && ! n.hidden; } )[0];
			if ( ! hit ) {
				throw new Error( 'no button reading exactly "' + label + '"' );
			}
			fire( hit, 'click' );
			return hit;
		},
		button: function ( label ) { return buttons( label )[0] || null; },
		input: function ( id ) { return find( app, function ( n ) { return n.attrs.id === id; } )[0] || null; },
		type: function ( id, value ) {
			var node = find( app, function ( n ) { return n.attrs.id === id; } )[0];
			if ( ! node ) {
				throw new Error( 'no field #' + id );
			}
			node.value = String( value );
			fire( node, 'input' );
			return node;
		},
		select: function () { return find( app, function ( n ) { return 'select' === n.tag; } )[0] || null; },
		byClass: function ( cls ) { return find( app, function ( n ) { return -1 !== ( ' ' + n.className + ' ' ).indexOf( ' ' + cls + ' ' ); } ); }
	};
}

function result( page ) {
	var box = page.byClass( 'fh-fd-result' )[0];
	return box ? shown( box ).replace( /\s+/g, ' ' ).trim() : '';
}

function later( fn ) {
	return new Promise( function ( resolve ) { setTimeout( function () { resolve( fn() ); }, 0 ); } );
}

/* ------------------------------------------------------------------ */

async function run() {
	console.log( '1. The Dose sheet: the copper product' );
	var page = start( { screen: screen( true ) } );
	check( 'the tank screen offers the Dose entry and the Calculator', !! page.button( 'Dose +' ) && !! page.button( 'Calculator' ) );
	page.tap( 'Dose +' );
	check( 'the sheet: every medication offered as a chip, the water (the whole system first), the amount, and "a dose cannot be undone" before Save', [ 'Copper', 'Metro', 'Quine', 'Ramp', 'Salt' ].every( function ( m ) { return !! page.button( m ); } ) && -1 !== page.button( 'Whole system' ).className.indexOf( 'is-on' ) && -1 !== shown( page.button( 'Whole system' ) ).indexOf( 'Building 600 · 100 gal' ) && -1 !== shown( page.button( 'This tank only' ) ).indexOf( '621 · 40 gal' ) && !! page.input( 'fh-fd-amount' ) && -1 !== page.text().indexOf( 'A dose cannot be undone' ) && !! page.button( 'Save' ), page.text() );
	check( 'no medication picked: no sum yet, the amount empty', '' === result( page ) && '' === page.input( 'fh-fd-amount' ).value );
	page.tap( 'Copper' );
	check( '"Now" is today\'s reading for the system (2.16, with its time); "Bring it to" the due step\'s level (2.4)', '2.16' === page.input( 'fh-fd-now' ).value && '2.4' === page.input( 'fh-fd-target' ).value && -1 !== page.text().indexOf( 'today\'s reading, 09:14' ), page.text() );
	check( 'the result line with its working small: "Add 16.6 ml Copper Power" / "100 gal × 0.24 ppm × 0.692"', 'Add 16.6 ml Copper Power 100 gal × 0.24 ppm × 0.692' === result( page ), result( page ) );
	check( 'the amount field is filled with the result, in its unit', '16.6' === page.input( 'fh-fd-amount' ).value && 'ml' === page.select().value );
	check( 'it says the copper reading is logged with it, once', -1 !== page.text().indexOf( 'Saving also logs the copper reading 2.16 (once).' ) );
	check( 'the due step and the copper target are one tap away', !! page.button( '2.40 · Copper Power to 2.4 · day 2' ) && !! page.button( 'target 2.5' ) );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'Save posts ONE request: the dose with the sum\'s inputs — the server writes the working and the reading', 1 === page.posts.length && 'fishotel_cleanroom_act' === page.posts[0].action && 'dose' === page.posts[0].act && '621' === page.posts[0].tank && 'copper' === page.posts[0].medication && '16.6' === page.posts[0].amount && 'ml' === page.posts[0].unit && '1' === page.posts[0].whole && '1' === page.posts[0].calc && '2.16' === page.posts[0].now && '2.4' === page.posts[0].target && ! ( 'rate' in page.posts[0] ) && ! ( 'topup' in page.posts[0] ) && ! ( 'gallons' in page.posts[0] ), JSON.stringify( page.posts ) );
	check( 'the toast states what the server answered, and offers no Undo', -1 !== shown( page.toast ).indexOf( 'Saved' ) && 0 === find( page.toast, function ( n ) { return 'button' === n.tag; } ).length );

	console.log( '2. Typing follows the sum; the amount stays the page\'s to edit' );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Copper' );
	var nowField = page.type( 'fh-fd-now', '2.3' );
	check( 'a new "Now" runs the sum again in place (the field is not redrawn): 2.30 → 2.40 is 6.9 ml', 'Add 6.9 ml Copper Power 100 gal × 0.10 ppm × 0.692' === result( page ) && '6.9' === page.input( 'fh-fd-amount' ).value && nowField === page.input( 'fh-fd-now' ), result( page ) );
	page.type( 'fh-fd-now', '2.4' );
	check( 'at the target: "Nothing to add", the amount empty', 'Nothing to add 2.40 ppm is at or above 2.40 ppm' === result( page ) && '' === page.input( 'fh-fd-amount' ).value, result( page ) );
	page.type( 'fh-fd-now', '2.6' );
	check( 'above it: nothing to add either', 0 === result( page ).indexOf( 'Nothing to add' ) );
	page.type( 'fh-fd-now', '' );
	check( 'no reading: it asks for one; nothing is said about logging a reading', 'Type the reading now to get the amount.' === result( page ) && -1 === page.text().indexOf( 'Saving also logs' ) );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'Save without an amount posts nothing and says what is missing', 0 === page.posts.length && -1 !== shown( page.toast ).indexOf( 'Type the reading now' ) );
	page.type( 'fh-fd-now', '2.16' );
	page.type( 'fh-fd-target', '2.5' );
	check( '"Bring it to" is editable: 2.16 → 2.50 is 23.5 ml', 0 === result( page ).indexOf( 'Add 23.5 ml Copper Power' ), result( page ) );
	page.tap( '2.40 · Copper Power to 2.4' );
	check( 'the due step\'s chip puts 2.4 back', '2.4' === page.input( 'fh-fd-target' ).value && 0 === result( page ).indexOf( 'Add 16.6 ml' ) );
	page.type( 'fh-fd-amount', '17' );
	check( 'an amount typed over the result stays, and the result is one tap away', '17' === page.input( 'fh-fd-amount' ).value && !! page.button( 'Use the result: 16.6 ml' ) && ! page.button( 'Use the result: 16.6 ml' ).hidden );
	page.type( 'fh-fd-target', '2.45' );
	check( '…the sum moves on, the typed amount does not', '17' === page.input( 'fh-fd-amount' ).value && 0 === result( page ).indexOf( 'Add 20.1 ml' ), result( page ) );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'what was really added is what is posted — with the working\'s inputs', 1 === page.posts.length && '17' === page.posts[0].amount && '1' === page.posts[0].calc && '2.16' === page.posts[0].now && '2.45' === page.posts[0].target, JSON.stringify( page.posts ) );

	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Copper' );
	page.type( 'fh-fd-amount', '17' );
	page.tap( 'Use the result' );
	check( '"Use the result" fills the field again and it follows the sum once more', '16.6' === page.input( 'fh-fd-amount' ).value && page.button( 'Use the result' ).hidden );

	console.log( '3. The water: the whole system or this tank only' );
	page.tap( 'This tank only' );
	check( 'this tank alone has no reading of its own today: "Now" empty and asked for, "Bring it to" the copper target (2.5)', '' === page.input( 'fh-fd-now' ).value && '2.5' === page.input( 'fh-fd-target' ).value && -1 !== page.text().indexOf( 'no reading today — type it' ) && 'Type the reading now to get the amount.' === result( page ), page.text() );
	page.type( 'fh-fd-now', '2' );
	check( '…typed: the tank\'s 40 gal — 13.8 ml', 'Add 13.8 ml Copper Power 40 gal × 0.50 ppm × 0.692' === result( page ), result( page ) );
	page.tap( 'Whole system' );
	check( 'back to the system: what was typed stays typed (2 → 2.4 on 100 gal)', '2' === page.input( 'fh-fd-now' ).value && 'Add 27.7 ml Copper Power 100 gal × 0.40 ppm × 0.692' === result( page ), result( page ) );
	page.tap( 'This tank only' );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'the water is posted: whole=0', 1 === page.posts.length && '0' === page.posts[0].whole && '2' === page.posts[0].now );

	console.log( '4. Per gallon: the rate, a named dose, a schedule step, the top-up, a measure' );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Metro' );
	check( '100 gallons of Metronidazole: "Add 2.5 g Metronidazole" / "100 gal × 25 mg", and in scoops', 'Add 2.5 g Metronidazole 100 gal × 25 mg 5 scoops (one scoop = 0.5 g)' === result( page ) && '2.5' === page.input( 'fh-fd-amount' ).value && 'g' === page.select().value, result( page ) );
	check( 'one rate only: it is said, not offered as chips; no levels', -1 !== page.text().indexOf( '25 mg per gallon' ) && null === page.input( 'fh-fd-now' ) && ! page.button( 'usual' ) );
	page.tap( 'This tank only' );
	check( 'this tank only: 40 gal — 1 g', 0 === result( page ).indexOf( 'Add 1 g Metronidazole 40 gal × 25 mg' ) && '1' === page.input( 'fh-fd-amount' ).value, result( page ) );
	page.tap( 'Whole system' );
	page.tap( 'Top-up after a water change' );
	check( 'the Top-up switch asks how much water was changed', 'How much of the water was changed?' === result( page ) && !! page.input( 'fh-fd-changed' ) && '' === page.input( 'fh-fd-amount' ).value );
	page.type( 'fh-fd-changed', '25' );
	check( '25 % of 100 gal: the amount for the water that was replaced — 625 mg', 'Add 625 mg Metronidazole 25 gal × 25 mg (25 % of 100 gal) 1.3 scoops (one scoop = 0.5 g)' === result( page ) && '625' === page.input( 'fh-fd-amount' ).value && 'mg' === page.select().value, result( page ) );
	page.tapExact( 'gal' );
	check( '…or in gallons: 25 gal', 0 === result( page ).indexOf( 'Add 625 mg Metronidazole 25 gal × 25 mg' ) && -1 === result( page ).indexOf( '%' ), result( page ) );
	page.type( 'fh-fd-changed', '150' );
	check( 'more than the water holds is refused', 'That is more than this water holds (100 gal).' === result( page ) );
	page.type( 'fh-fd-changed', '30' );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'Save posts the rate and the top-up', 1 === page.posts.length && 'metro' === page.posts[0].medication && '750' === page.posts[0].amount && 'mg' === page.posts[0].unit && '1' === page.posts[0].calc && '25' === page.posts[0].rate && '1' === page.posts[0].topup && '30' === page.posts[0].changed && 'gal' === page.posts[0].changed_unit && ! ( 'now' in page.posts[0] ), JSON.stringify( page.posts ) );

	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Quine' );
	check( 'a schedule step due today picks its rate: the "Loading" chip (40 mg per gallon) is on and marked due — 4 g', -1 !== page.button( 'Loading · 40 mg per gallon · due today' ).className.indexOf( 'is-on' ) && 0 === result( page ).indexOf( 'Add 4 g Test Quine 100 gal × 40 mg' ), result( page ) );
	check( 'the medication\'s own rate and the other named dose are chips too', !! page.button( '10 mg per gallon · usual' ) && !! page.button( 'Top-up · 12 mg per gallon' ) );
	page.tap( 'Top-up · 12 mg per gallon' );
	check( 'a named dose chip picks that rate: 1.2 g', 0 === result( page ).indexOf( 'Add 1.2 g Test Quine 100 gal × 12 mg' ) && -1 !== page.button( 'Top-up · 12 mg per gallon' ).className.indexOf( 'is-on' ) );
	page.tap( 'This tank only' );
	check( 'the chosen rate stays when the water changes (40 gal — 480 mg)', 0 === result( page ).indexOf( 'Add 480 mg Test Quine 40 gal × 12 mg' ), result( page ) );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'Save posts the rate with the named dose\'s name', '12' === page.posts[0].rate && 'Top-up' === page.posts[0].dose_label && '480' === page.posts[0].amount && '0' === page.posts[0].whole );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Quine' );
	page.tap( 'This tank only' );
	check( 'nothing due in this tank alone: its own rate (10 mg per gallon — 400 mg), nothing marked due', -1 !== page.button( '10 mg per gallon · usual' ).className.indexOf( 'is-on' ) && 0 === result( page ).indexOf( 'Add 400 mg Test Quine' ) && ! page.button( 'due today' ), result( page ) );

	console.log( '5. Typed, another medication dosed to a level, unknown gallons' );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Salt' );
	check( 'a typed medication: no sum — the amount is typed, in its unit', 'No sum for Salt — type the amount.' === result( page ) && '' === page.input( 'fh-fd-amount' ).value && 'tsp' === page.select().value );
	page.type( 'fh-fd-amount', '6' );
	page.tap( 'Save' );
	await later( function () {} );
	check( '…and posted as a plain dose: no calc', 1 === page.posts.length && 'salt' === page.posts[0].medication && '6' === page.posts[0].amount && 'tsp' === page.posts[0].unit && ! ( 'calc' in page.posts[0] ) );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Ramp' );
	check( 'another medication dosed to a level: both levels empty and asked for; no copper reading is promised', '' === page.input( 'fh-fd-now' ).value && '' === page.input( 'fh-fd-target' ).value && -1 !== page.text().indexOf( 'type the reading' ) && -1 === page.text().indexOf( 'Saving also logs' ) && ! page.button( 'target 2.5' ) );
	page.type( 'fh-fd-now', '0.25' );
	page.type( 'fh-fd-target', '0.5' );
	check( '…typed: a rate for 5 gallons — 10 ml', 'Add 10 ml Test Ramp Liquid 100 gal × 0.25 ppm × 2 ml per 5 gal' === result( page ) && -1 === page.text().indexOf( 'Saving also logs' ), result( page ) );
	page = start( { screen: screen( true, 0 ) } );
	page.tap( 'Dose +' );
	check( 'a system of unknown size: the sheet starts on this tank', -1 !== page.button( 'This tank only' ).className.indexOf( 'is-on' ) );
	page.tap( 'Whole system' );
	page.tap( 'Metro' );
	check( '…and for the system it says so and lets the amount be typed (grams for a weight)', 'The gallons of this water are not known — type the amount.' === result( page ) && '' === page.input( 'fh-fd-amount' ).value && 'g' === page.select().value );
	page.type( 'fh-fd-amount', '2' );
	page.tap( 'Save' );
	await later( function () {} );
	check( '…posted as typed, without a sum', 1 === page.posts.length && '2' === page.posts[0].amount && 'g' === page.posts[0].unit && ! ( 'calc' in page.posts[0] ) );
	page = start( { screen: screen( true, 0 ) } );
	page.tap( 'Dose +' );
	page.tap( 'Whole system' );
	page.tap( 'Copper' );
	var unknown = result( page );
	page.type( 'fh-fd-amount', '9' );
	page.tap( 'Save' );
	await later( function () {} );
	check( 'to a level in a water of unknown size: it says so, and the readings still go along with the typed amount (the reading is logged)', 'The gallons of this water are not known — type the amount.' === unknown && 1 === page.posts.length && '1' === page.posts[0].calc && '2.16' === page.posts[0].now && '2.4' === page.posts[0].target && '9' === page.posts[0].amount && 'ml' === page.posts[0].unit, unknown + ' ' + JSON.stringify( page.posts ) );

	console.log( '6. The Calculator: any gallons, nothing saved' );
	page = start( { screen: screen( true ) } );
	page.tap( 'Calculator' );
	check( 'it says nothing is saved, and has no Save', -1 !== page.text().indexOf( 'Nothing is saved' ) && ! page.button( 'Save' ) && !! page.input( 'fh-fd-gallons' ) );
	page.tap( 'Metro' );
	check( 'no gallons yet: it asks for them', 'Type the gallons.' === result( page ) );
	page.type( 'fh-fd-gallons', '100' );
	check( '100 gal: the same result and working as the sheet', 'Add 2.5 g Metronidazole 100 gal × 25 mg 5 scoops (one scoop = 0.5 g)' === result( page ), result( page ) );
	page.type( 'fh-fd-gallons', '2.5' );
	check( 'a 2.5 gal bucket: 62.5 mg', 0 === result( page ).indexOf( 'Add 62.5 mg Metronidazole 2.5 gal × 25 mg' ), result( page ) );
	page.tap( 'System · 100 gal' );
	check( 'the page\'s own waters are one tap away', '100' === page.input( 'fh-fd-gallons' ).value && 0 === result( page ).indexOf( 'Add 2.5 g' ) && !! page.button( 'Tank 621 · 40 gal' ) );
	page.tap( 'Copper' );
	check( 'the gallons stay when the medication changes; "Bring it to" starts at the copper target, "Now" is typed', '100' === page.input( 'fh-fd-gallons' ).value && '' === page.input( 'fh-fd-now' ).value && '2.5' === page.input( 'fh-fd-target' ).value && 'Type the reading now to get the amount.' === result( page ) );
	page.type( 'fh-fd-now', '2.16' );
	page.type( 'fh-fd-target', '2.4' );
	check( 'the copper sum: 16.6 ml', 'Add 16.6 ml Copper Power 100 gal × 0.24 ppm × 0.692' === result( page ), result( page ) );
	page.tap( 'Quine' );
	page.tap( 'Loading · 40 mg per gallon' );
	check( 'named doses are chips here too (no schedule: nothing is due in a bucket)', 0 === result( page ).indexOf( 'Add 4 g Test Quine 100 gal × 40 mg' ) && ! page.button( 'due today' ) );
	page.tap( 'Salt' );
	check( 'a typed medication has no sum', 'No sum for Salt — type the amount.' === result( page ) );
	page.tap( 'Back' );
	check( 'Back is the tank again — and nothing at all was posted', !! page.button( 'Dose +' ) && 0 === page.posts.length );

	page = start( { screen: screen( false ), view: 'calc' } );
	check( 'without a tank (?calc=1): the Calculator alone, no quick waters, no Dose button', -1 !== page.text().indexOf( 'Nothing is saved' ) && ! page.button( 'System' ) && ! page.button( 'Dose' ) && !! page.button( 'Copper' ) );
	page.tap( 'Metro' );
	page.type( 'fh-fd-gallons', '55' );
	check( '…55 gal: 1.4 g', 0 === result( page ).indexOf( 'Add 1.4 g Metronidazole 55 gal × 25 mg' ), result( page ) );
	page.tap( 'Back' );
	check( 'Back is the tank picker, which offers the Calculator again', -1 !== page.text().indexOf( 'Pick a tank' ) && !! page.button( 'Calculator' ) && 0 === page.posts.length );
	var none   = screen( false );
	none.medications = [];
	page = start( { screen: none, view: 'calc' } );
	check( 'no medication offered: it says where to add one', -1 !== page.text().indexOf( 'No medication is offered' ) );

	console.log( '7. A dose phrase: previewed with its sum, never auto-confirmed, Confirm is the same Save' );
	var preview = {
		intent: 'dose', tank: 621, tank_spoken: false, tank_known: true, needs: [], qty: null, value: null, to_tank: null, species: {}, candidates: [], text: 'copper 2.16 to 2.4',
		dose: {
			medication: 'copper', label: 'Copper Power', mode: 'to_level', level_unit: 'ppm', scope: '', now: 2.16, target: 2.4, amount: null, unit: '', dose_label: '',
			whole: true, place: 'Building 600, whole system', gallons: 100, now_at: '', rate: null, rate_label: '',
			plan: { state: 'ok', amount: 16.6, unit: 'ml', line: 'Add 16.6 ml Copper Power', working: '100 gal × 0.24 ppm × 0.692', note: '2.16 → 2.40 ppm, 100 gal', measure: '' }
		}
	};
	function phrase( reply ) {
		var p = start( { screen: screen( true ), reply: function ( fields ) { return 'fishotel_cleanroom_parse' === fields.action ? { preview: reply( fields ) } : { message: 'Copper Power 16.6 ml · Building 600, whole system', undo: { eventIds: [] }, screen: screen( true ) }; } } );
		p.tap( 'Mic' ); // No speech recognition here: the typed box.
		var box = find( p.app, function ( n ) { return 'input' === n.tag && 'text' === n.attrs.type; } )[0];
		box.value = 'copper 2.16 to 2.4';
		p.tap( 'Go' );
		return p;
	}
	page = phrase( function () { return JSON.parse( JSON.stringify( preview ) ); } );
	await later( function () {} );
	check( 'the phrase goes to the server with the page\'s tank', 1 === page.posts.length && 'fishotel_cleanroom_parse' === page.posts[0].action && 'copper 2.16 to 2.4' === page.posts[0].text && '621' === page.posts[0].tank );
	check( 'the preview: the result line, the water, the working — and that a dose cannot be undone', -1 !== page.text().indexOf( 'Add 16.6 ml Copper Power' ) && -1 !== page.text().indexOf( 'Building 600, whole system · 100 gal' ) && -1 !== page.text().indexOf( '100 gal × 0.24 ppm × 0.692' ) && -1 !== page.text().indexOf( 'A dose cannot be undone' ), page.text() );
	check( 'no countdown: a dose is confirmed by a tap, never by itself', -1 === page.text().indexOf( 'Confirming in' ) && 0 === page.byClass( 'fh-fd-countdown' ).length && ! page.button( 'Confirm now' ).disabled && ! page.button( 'Not that fish' ) );
	page.tap( 'Confirm now' );
	await later( function () {} );
	check( 'Confirm posts what the sheet\'s Save posts, by voice', 2 === page.posts.length && 'dose' === page.posts[1].act && 'copper' === page.posts[1].medication && '16.6' === page.posts[1].amount && 'ml' === page.posts[1].unit && '1' === page.posts[1].whole && '1' === page.posts[1].calc && '2.16' === page.posts[1].now && '2.4' === page.posts[1].target && 'voice' === page.posts[1].via && '621' === page.posts[1].tank, JSON.stringify( page.posts[1] ) );

	var asks  = JSON.parse( JSON.stringify( preview ) );
	asks.needs       = [ 'now' ];
	asks.dose.now    = null;
	asks.dose.plan   = { state: 'needs_now', amount: null, unit: 'ml', line: 'Type the reading now to get the amount.', working: '', note: '', measure: '' };
	page = phrase( function () { return asks; } );
	await later( function () {} );
	check( '"raise copper to 2.4" with no reading today: it asks for Now and cannot be confirmed yet', -1 !== page.text().indexOf( 'Type the reading now' ) && page.button( 'Confirm now' ).disabled );
	var field = find( page.app, function ( n ) { return 'input' === n.tag && 'number' === n.attrs.type; } )[0];
	field.value = '2.16';
	fire( field, 'input' );
	page.tap( 'Go' );
	check( '…typed: the sum runs on the page — 16.6 ml — and it can be confirmed', -1 !== page.text().indexOf( 'Add 16.6 ml Copper Power' ) && -1 !== page.text().indexOf( '100 gal × 0.24 ppm × 0.692' ) && ! page.button( 'Confirm now' ).disabled, page.text() );
	page.tap( 'Confirm now' );
	await later( function () {} );
	check( '…and posts the reading that was typed', 'dose' === page.posts[1].act && '2.16' === page.posts[1].now && '16.6' === page.posts[1].amount );

	var spoken = JSON.parse( JSON.stringify( preview ) );
	spoken.dose = { medication: 'metro', label: 'Metronidazole', mode: 'per_gallon', level_unit: '', scope: '', now: null, target: null, amount: 5, unit: 'g', dose_label: '', whole: true, place: 'Building 600, whole system', gallons: 100, now_at: '', rate: null, rate_label: '', plan: { state: 'spoken', amount: 5, unit: 'g', line: 'Metronidazole 5 g', working: '', note: '', measure: '' } };
	page = phrase( function () { return spoken; } );
	await later( function () {} );
	page.tap( 'Confirm now' );
	await later( function () {} );
	check( 'a dose spoken with its amount: that amount, no sum behind it', -1 !== page.posts[1].medication.indexOf( 'metro' ) && '5' === page.posts[1].amount && 'g' === page.posts[1].unit && ! ( 'calc' in page.posts[1] ), JSON.stringify( page.posts[1] ) );

	var nothing = JSON.parse( JSON.stringify( preview ) );
	nothing.dose.now  = 2.5;
	nothing.dose.plan = { state: 'nothing', amount: null, unit: 'ml', line: 'Nothing to add', working: '2.50 ppm is at or above 2.40 ppm', note: '', measure: '' };
	page = phrase( function () { return nothing; } );
	await later( function () {} );
	check( 'nothing to add: said, and not confirmable', -1 !== page.text().indexOf( 'Nothing to add' ) && page.button( 'Confirm now' ).disabled );
	page.tap( 'Open the Dose sheet' );
	check( 'the Dose sheet opens with what was said: the copper product, 2.5 → 2.4', -1 !== page.button( 'Copper' ).className.indexOf( 'is-on' ) && '2.5' === page.input( 'fh-fd-now' ).value && '2.4' === page.input( 'fh-fd-target' ).value && 0 === result( page ).indexOf( 'Nothing to add' ) );

	var where = { intent: 'dose', tank: 999, tank_spoken: true, tank_known: false, needs: [ 'tank' ], species: {}, candidates: [], dose: { medication: 'metro', label: 'Metronidazole', mode: 'per_gallon', level_unit: '', scope: '', now: null, target: null, amount: null, unit: '', dose_label: '' } };
	var asked = 0;
	page = phrase( function ( fields ) { asked++; return fields.dose_tank ? preview : where; } );
	await later( function () {} );
	check( 'a tank that is not in the layout: the phrase in plain words and the tanks to pick from', -1 !== page.text().indexOf( 'Dose: Metronidazole · Tank 999' ) && !! page.button( '611' ) && page.button( 'Confirm now' ).disabled, page.text() );
	page.tap( '611' );
	await later( function () {} );
	check( '…picking one asks the server again for that tank', 2 === asked && '611' === page.posts[1].dose_tank && -1 !== page.text().indexOf( 'Add 16.6 ml Copper Power' ) );

	console.log( '8. What went in today already' );
	page = start( { screen: screen( true ) } );
	page.tap( 'Dose +' );
	page.tap( 'Copper' );
	check( 'nothing dosed today: no "Already logged today" on the sheet', -1 === page.text().indexOf( 'Already logged today' ) && 0 === page.byClass( 'fh-fd-dosed' ).length );
	var dosedScreen = screen( true );
	dosedScreen.tank.dose.system.meds.copper.dosed = { text: 'Copper Power 16.6 ml', at: '09:20', after: true };
	dosedScreen.tank.dose.system.meds.metro.dosed  = { text: 'Metronidazole 2.5 g', at: '08:05', after: false };
	page = start( { screen: dosedScreen } );
	page.tap( 'Dose +' );
	check( 'no medication picked: nothing told yet', 0 === page.byClass( 'fh-fd-dosed' ).length );
	page.tap( 'Copper' );
	check( 'copper dosed after today\'s reading: the sheet says what went in, when, and to read the level again — above the sum', -1 !== page.text().indexOf( 'Already logged today: Copper Power 16.6 ml, at 09:20. That went in after the reading — read the level again before adding more.' ) && 1 === page.byClass( 'is-after' ).length && page.text().indexOf( 'Already logged today' ) < page.text().indexOf( 'Add 16.6 ml' ), page.text() );
	check( '…the sum and Save stay the page\'s to use', 'Add 16.6 ml Copper Power 100 gal × 0.24 ppm × 0.692' === result( page ) && ! page.button( 'Save' ).disabled );
	page.tap( 'Metro' );
	check( 'another medication: its own dose, and no "read again"', -1 !== page.text().indexOf( 'Already logged today: Metronidazole 2.5 g, at 08:05.' ) && -1 === page.text().indexOf( 'read the level again' ) && -1 === page.text().indexOf( 'Copper Power 16.6 ml, at' ) && 0 === page.byClass( 'is-after' ).length, page.text() );
	page.tap( 'This tank only' );
	check( 'the other water (this tank alone, nothing dosed in it): not told', -1 === page.text().indexOf( 'Already logged today' ) );

	var again = JSON.parse( JSON.stringify( preview ) );
	again.dose.dosed = { text: 'Copper Power 16.6 ml', at: '09:20', after: true };
	page = phrase( function () { return again; } );
	await later( function () {} );
	check( 'a dose phrase: the preview tells of it too, before Confirm', -1 !== page.text().indexOf( 'Already logged today: Copper Power 16.6 ml, at 09:20. That went in after the reading' ) && ! page.button( 'Confirm now' ).disabled, page.text() );

	console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
	process.exit( failures ? 1 : 0 );
}

run().catch( function ( error ) {
	console.log( '  FAIL the run threw: ' + ( error && error.stack ? error.stack : error ) );
	process.exit( 1 );
} );
