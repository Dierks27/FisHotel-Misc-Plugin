/**
 * FisHotel Lottery Draw — THE draw algorithm.
 *
 * This file is the single implementation of the draw. It runs in two
 * places from the same bytes: the admin screen, to produce a result, and
 * the public page, to let any visitor recompute that result from the
 * published inputs. There is deliberately no PHP counterpart. If a second
 * implementation existed and the two ever disagreed — one integer overflow
 * apart — published results would stop matching what members compute, and
 * the verifiability claim would collapse silently.
 *
 * DO NOT change the PRNG, the seeding string, the ticket order, or the
 * shuffle direction. Any change here re-rolls every previously published
 * draw and makes past results unverifiable.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */
( function ( root, factory ) {
	'use strict';

	var api = factory();

	// Node (the test harness) and the browser both get the same object.
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}

	if ( root ) {
		root.FisHotelDraw = api;
	}
}( typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	/**
	 * cyrb128 — hash a string seed down to a 32-bit integer.
	 *
	 * @param {string} str Seed string.
	 * @return {number} Unsigned 32-bit integer.
	 */
	function cyrb128( str ) {
		var h1 = 1779033703, h2 = 3144134277, h3 = 1013904242, h4 = 2773480762;
		for ( var i = 0; i < str.length; i++ ) {
			var k = str.charCodeAt( i );
			h1 = h2 ^ Math.imul( h1 ^ k, 597399067 );
			h2 = h3 ^ Math.imul( h2 ^ k, 2869860233 );
			h3 = h4 ^ Math.imul( h3 ^ k, 951274213 );
			h4 = h1 ^ Math.imul( h4 ^ k, 2716044179 );
		}
		h1 = Math.imul( h3 ^ ( h1 >>> 18 ), 597399067 );
		h2 = Math.imul( h4 ^ ( h2 >>> 22 ), 2869860233 );
		h3 = Math.imul( h1 ^ ( h3 >>> 17 ), 951274213 );
		h4 = Math.imul( h2 ^ ( h4 >>> 19 ), 2716044179 );
		return ( h1 ^ h2 ^ h3 ^ h4 ) >>> 0;
	}

	/**
	 * mulberry32 — seeded generator returning floats in [0, 1).
	 *
	 * @param {number} a 32-bit seed.
	 * @return {function(): number} Generator.
	 */
	function mulberry32( a ) {
		return function () {
			a |= 0; a = ( a + 0x6D2B79F5 ) | 0;
			var t = Math.imul( a ^ ( a >>> 15 ), 1 | a );
			t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;
			return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
		};
	}

	/**
	 * Fisher-Yates, descending, driven by a seeded generator.
	 *
	 * @param {Array}    arr Input array (not mutated).
	 * @param {function} rng Seeded generator.
	 * @return {Array} Shuffled copy.
	 */
	function shuffle( arr, rng ) {
		var a = arr.slice();
		for ( var i = a.length - 1; i > 0; i-- ) {
			var j = Math.floor( rng() * ( i + 1 ) );
			var t = a[ i ];
			a[ i ] = a[ j ];
			a[ j ] = t;
		}
		return a;
	}

	/**
	 * Deduplicate entrants case-insensitively, keeping first appearance
	 * and summing the `want` of every later appearance into it.
	 *
	 * Posting order of first appearances is preserved. It has no effect
	 * on odds — the shuffle destroys it — but members check it against
	 * the forum thread, so it must survive storage and display intact.
	 *
	 * Idempotent: running it on an already-normalised list is a no-op,
	 * which is why both the admin parser and the draw itself can call it.
	 *
	 * @param {Array} entrants Raw entrant list.
	 * @return {Array} Normalised [{name, want}].
	 */
	function normalizeEntrants( entrants ) {
		var out   = [];
		// Null-prototype map: entrant names are hostile input, and a
		// forum handle of "__proto__" must behave like any other key.
		var index = Object.create( null );

		( entrants || [] ).forEach( function ( entry ) {
			var name = String( ( entry && entry.name ) || '' ).trim();
			if ( ! name ) {
				return;
			}

			var want = Math.floor( Number( ( entry && entry.want ) || 0 ) );
			if ( ! isFinite( want ) || want < 0 ) {
				want = 0;
			}

			var key = name.toLowerCase();

			if ( index[ key ] !== undefined ) {
				out[ index[ key ] ].want += want;
				return;
			}

			index[ key ] = out.length;
			out.push( { name: name, want: want } );
		} );

		return out;
	}

	/**
	 * Slots available for a fish.
	 *
	 * Group species are drawn as groups, not individuals: 120 in stock
	 * at a group size of 5 is 24 slots, not 120.
	 *
	 * @param {Object} fish Fish record.
	 * @return {number} Slot count.
	 */
	function slotsFor( fish ) {
		var stock = Math.floor( Number( ( fish && fish.stock ) || 0 ) );
		if ( ! isFinite( stock ) || stock < 0 ) {
			stock = 0;
		}

		var groupSize = Math.floor( Number( ( fish && fish.groupSize ) || 1 ) );
		if ( ! isFinite( groupSize ) || groupSize < 1 ) {
			groupSize = 1;
		}

		return Math.floor( stock / groupSize );
	}

	/**
	 * Build the ticket list for a fish: one ticket per fish owed.
	 *
	 * @param {Object} fish Fish record.
	 * @return {Array<string>} Tickets in posting order.
	 */
	function ticketsFor( fish ) {
		var tickets = [];

		normalizeEntrants( fish && fish.entrants ).forEach( function ( entrant ) {
			for ( var i = 0; i < entrant.want; i++ ) {
				tickets.push( entrant.name );
			}
		} );

		return tickets;
	}

	/**
	 * Draw one fish.
	 *
	 * The RNG is seeded per fish, with `seed + '::' + lowercased name`.
	 * That is deliberate: correcting a typo in one fish's entrant list
	 * must not reshuffle every other fish in the same draw.
	 *
	 * @param {string} seed Draw seed.
	 * @param {Object} fish { name, stock, groupSize, entrants: [{name, want}] }.
	 * @return {{winners: Array<string>, waitlist: Array<string>}}
	 *         A name may appear more than once in either list — that
	 *         means they won (or are waiting on) that many of the fish.
	 */
	function drawFish( seed, fish ) {
		var slots   = slotsFor( fish );
		var tickets = ticketsFor( fish );
		var rng     = mulberry32( cyrb128( String( seed ) + '::' + String( ( fish && fish.name ) || '' ).toLowerCase() ) );
		var order   = shuffle( tickets, rng );

		return {
			winners: order.slice( 0, slots ),
			waitlist: order.slice( slots )
		};
	}

	/**
	 * Draw every fish in a payload.
	 *
	 * @param {Object} payload Draw payload.
	 * @return {Array<{name: string, winners: Array, waitlist: Array}>}
	 */
	function drawAll( payload ) {
		var seed = String( ( payload && payload.seed ) || '' );

		return ( ( payload && payload.fish ) || [] ).map( function ( fish ) {
			var result = drawFish( seed, fish );

			return {
				name: fish.name,
				winners: result.winners,
				waitlist: result.waitlist
			};
		} );
	}

	/**
	 * Compare two lists of names for exact, order-sensitive equality.
	 *
	 * @param {Array} a First list.
	 * @param {Array} b Second list.
	 * @return {boolean}
	 */
	function sameList( a, b ) {
		a = a || [];
		b = b || [];

		if ( a.length !== b.length ) {
			return false;
		}

		for ( var i = 0; i < a.length; i++ ) {
			if ( String( a[ i ] ) !== String( b[ i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recompute a published payload and compare it to the stored results.
	 *
	 * A fish is only `ok` when both its winner list and its waitlist match
	 * a fresh draw exactly, in order. Anything else is a mismatch, and the
	 * caller must say so loudly — a tampered result rendered as verified
	 * would launder a rigged draw.
	 *
	 * @param {Object} payload Published draw payload.
	 * @return {{ok: boolean, fish: Array}}
	 */
	function verify( payload ) {
		var seed = String( ( payload && payload.seed ) || '' );
		var ok   = true;

		var fish = ( ( payload && payload.fish ) || [] ).map( function ( entry ) {
			var fresh         = drawFish( seed, entry );
			var published     = {
				winners: ( entry.winners || [] ).map( String ),
				waitlist: ( entry.waitlist || [] ).map( String )
			};
			var winnersMatch  = sameList( fresh.winners, published.winners );
			var waitlistMatch = sameList( fresh.waitlist, published.waitlist );

			if ( ! winnersMatch || ! waitlistMatch ) {
				ok = false;
			}

			return {
				name: entry.name,
				ok: winnersMatch && waitlistMatch,
				winnersMatch: winnersMatch,
				waitlistMatch: waitlistMatch,
				expected: fresh,
				published: published
			};
		} );

		return { ok: ok, fish: fish };
	}

	/**
	 * Collapse repeated names into counts, keeping draw order of first
	 * appearance, so a double win renders as `Name (2)` rather than as
	 * two rows that look like a mistake.
	 *
	 * @param {Array<string>} names Winner or waitlist names, in draw order.
	 * @return {Array<{name: string, count: number}>}
	 */
	function tally( names ) {
		var out   = [];
		var index = Object.create( null );

		( names || [] ).forEach( function ( raw ) {
			var name = String( raw );
			var key  = name.toLowerCase();

			if ( index[ key ] !== undefined ) {
				out[ index[ key ] ].count++;
				return;
			}

			index[ key ] = out.length;
			out.push( { name: name, count: 1 } );
		} );

		return out;
	}

	/**
	 * Render a tallied list as text: "alice (2), bob".
	 *
	 * @param {Array<string>} names Names in draw order.
	 * @return {string}
	 */
	function formatNames( names ) {
		return tally( names ).map( function ( item ) {
			return item.count > 1 ? item.name + ' (' + item.count + ')' : item.name;
		} ).join( ', ' );
	}

	return {
		cyrb128: cyrb128,
		mulberry32: mulberry32,
		shuffle: shuffle,
		normalizeEntrants: normalizeEntrants,
		slotsFor: slotsFor,
		ticketsFor: ticketsFor,
		drawFish: drawFish,
		drawAll: drawAll,
		verify: verify,
		sameList: sameList,
		tally: tally,
		formatNames: formatNames
	};
} ) );
