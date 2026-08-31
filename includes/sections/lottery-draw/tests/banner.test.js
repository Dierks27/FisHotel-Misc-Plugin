/**
 * Acceptance test 9 — the one that matters most.
 *
 * Tamper with a stored result and the public page must paint the red
 * MISMATCH banner and show the diff. If a tampered payload ever renders
 * green, the feature is worse than not having it: it launders a rigged
 * draw as a verified one.
 *
 * Runs lottery-public.js against a minimal DOM shim, so it needs no
 * browser and no dependencies.
 *
 * Run from the plugin root:  node includes/sections/lottery-draw/tests/banner.test.js
 */
'use strict';

var path      = require( 'path' );
var DRAW_JS   = path.join( __dirname, '..', 'js', 'draw.js' );
var PUBLIC_JS = path.join( __dirname, '..', 'js', 'lottery-public.js' );

var drawApi = require( DRAW_JS );

var failures = 0;

function check( label, condition, detail ) {
	if ( condition ) {
		console.log( '  ok   ' + label );
		return;
	}
	failures++;
	console.log( '  FAIL ' + label + ( detail ? '\n       ' + detail : '' ) );
}

/**
 * The smallest DOM that lottery-public.js can run against.
 *
 * @param {string} tag Tag name.
 * @return {Object} Fake element.
 */
function makeEl( tag ) {
	return {
		tagName: tag,
		className: '',
		textContent: '',
		hidden: false,
		children: [],
		appendChild: function ( child ) {
			this.children.push( child );
			return child;
		},
		querySelector: function () {
			return null;
		}
	};
}

/**
 * Load the public verifier against one payload and return what it painted.
 *
 * @param {string|undefined} payloadJson Raw payload string, or undefined.
 * @param {string}           instance    DOM instance key.
 * @return {{banner: Object, diff: Object}}
 */
function render( payloadJson, instance ) {
	var banner = makeEl( 'div' );
	var diff   = makeEl( 'div' );

	var root = {
		getAttribute: function () {
			return instance;
		},
		querySelector: function ( selector ) {
			if ( -1 !== selector.indexOf( 'banner' ) ) {
				return banner;
			}
			if ( -1 !== selector.indexOf( 'diff' ) ) {
				return diff;
			}
			return null;
		}
	};

	global.window   = global;
	global.document = {
		readyState: 'complete',
		createElement: makeEl,
		querySelectorAll: function () {
			return [ root ];
		},
		addEventListener: function () {}
	};

	global.fishotelDrawPayloads = {};
	global.fishotelDrawPayloads[ instance ] = payloadJson;
	global.FisHotelDraw = drawApi;

	delete require.cache[ require.resolve( PUBLIC_JS ) ];
	require( PUBLIC_JS );

	return { banner: banner, diff: diff };
}

/**
 * A published payload: inputs, plus the results draw.js produces from them.
 *
 * @return {Object}
 */
function publishedPayload() {
	var payload = {
		id: 'rvs-2026-08',
		title: 'RVS Philippines — August 2026',
		seed: 'RVS-2026-08-22-K7M2P',
		seed_method: 'commit_early',
		seed_source: '',
		supersedes: '',
		seed_published_at: '2026-08-20T23:00:00Z',
		drawn_at: '2026-08-22T02:15:00Z',
		status: 'published',
		fish: [
			{
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
			},
			{
				name: 'Lyretail Anthias',
				sci: 'Pseudanthias squamipinnis',
				stock: 120,
				groupSize: 5,
				entrants: [
					{ name: 'reefer', want: 10 },
					{ name: 'daduc', want: 40 }
				]
			}
		]
	};

	payload.fish.forEach( function ( fish ) {
		var result = drawApi.drawFish( payload.seed, fish );
		fish.winners  = result.winners;
		fish.waitlist = result.waitlist;
	} );

	return payload;
}

console.log( 'Public verification banner' );

var clean = render( JSON.stringify( publishedPayload() ), 'clean' );
check( 'an untouched payload paints the green banner', 'fh-draw-verify is-ok' === clean.banner.className, clean.banner.className );
check( 'the green banner claims reproducibility', /Verified in your browser/.test( clean.banner.textContent ), clean.banner.textContent );
check( 'no diff is shown', 0 === clean.diff.children.length );

console.log( 'Acceptance test 9 — a tampered winner list' );

var tampered = publishedPayload();
tampered.fish[ 0 ].winners[ 0 ] = tampered.fish[ 0 ].waitlist[ 0 ];
var rigged = render( JSON.stringify( tampered ), 'tampered' );
check( 'the banner turns red', 'fh-draw-verify is-fail' === rigged.banner.className, rigged.banner.className );
check( 'it says MISMATCH', /MISMATCH/.test( rigged.banner.textContent ), rigged.banner.textContent );
check( 'the diff is revealed', false === rigged.diff.hidden && rigged.diff.children.length > 0 );
check( 'the diff names the affected fish', rigged.diff.children.some( function ( child ) {
	return 'Pyramid Butterfly' === child.textContent;
} ) );

var swapped = publishedPayload();
swapped.fish[ 1 ].winners[ 0 ] = 'somebody-else';
check( 'a promoted stranger on the second fish is caught',
	'fh-draw-verify is-fail' === render( JSON.stringify( swapped ), 'swapped' ).banner.className );

console.log( 'Failure modes must fail closed' );

check( 'an unreadable payload is never green',
	'fh-draw-verify is-fail' === render( 'not json at all', 'broken' ).banner.className );
check( 'a payload that did not load is never green',
	'fh-draw-verify is-fail' === render( undefined, 'missing' ).banner.className );

console.log( '\n' + ( failures ? failures + ' checks FAILED' : 'All banner checks passed' ) );
process.exit( failures ? 1 : 0 );
