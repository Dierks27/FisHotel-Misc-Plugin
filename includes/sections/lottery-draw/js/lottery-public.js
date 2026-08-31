/**
 * Public draw verification.
 *
 * Re-runs the draw in the visitor's browser from the published inputs
 * and compares it, name by name and position by position, to the results
 * the page is displaying. A mismatch is reported loudly and in full: a
 * tampered result rendered as verified would be worse than no verification
 * at all, because it would launder a rigged draw as a fair one.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */
( function () {
	'use strict';

	var draw = window.FisHotelDraw;

	/**
	 * Build a DOM element with text content and an optional class.
	 *
	 * Everything the payload contains is untrusted forum input, so it
	 * only ever reaches the page through textContent — never innerHTML.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className Class attribute.
	 * @param {string} text      Text content.
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}

		return node;
	}

	/**
	 * Put the banner into one of its three states.
	 *
	 * @param {HTMLElement} banner Banner element.
	 * @param {string}      state  'pending' | 'ok' | 'fail'.
	 * @param {string}      text   Message.
	 */
	function setBanner( banner, state, text ) {
		banner.className   = 'fh-draw-verify is-' + state;
		banner.textContent = text;
	}

	/**
	 * Render the per-fish diff for a failed verification.
	 *
	 * @param {HTMLElement} target Diff container.
	 * @param {Object}      report draw.verify() report.
	 */
	function renderDiff( target, report ) {
		target.textContent = '';
		target.hidden      = false;

		target.appendChild( el( 'h3', null, 'What does not match' ) );
		target.appendChild( el(
			'p',
			'fh-draw-note',
			'"Published" is what this page is showing you. "Recomputed" is what the seed and entrant lists on this page actually produce. They should be identical.'
		) );

		report.fish.forEach( function ( fish ) {
			if ( fish.ok ) {
				return;
			}

			target.appendChild( el( 'h4', 'fh-draw-subhead', fish.name ) );

			if ( ! fish.winnersMatch ) {
				var winners = el( 'dl', 'fh-draw-diff-pair' );
				winners.appendChild( el( 'dt', null, 'Winners — published' ) );
				winners.appendChild( el( 'dd', null, draw.formatNames( fish.published.winners ) || '(none)' ) );
				winners.appendChild( el( 'dt', null, 'Winners — recomputed' ) );
				winners.appendChild( el( 'dd', null, draw.formatNames( fish.expected.winners ) || '(none)' ) );
				target.appendChild( winners );
			}

			if ( ! fish.waitlistMatch ) {
				var waitlist = el( 'dl', 'fh-draw-diff-pair' );
				waitlist.appendChild( el( 'dt', null, 'Waitlist — published' ) );
				waitlist.appendChild( el( 'dd', null, fish.published.waitlist.join( ', ' ) || '(none)' ) );
				waitlist.appendChild( el( 'dt', null, 'Waitlist — recomputed' ) );
				waitlist.appendChild( el( 'dd', null, fish.expected.waitlist.join( ', ' ) || '(none)' ) );
				target.appendChild( waitlist );
			}
		} );
	}

	/**
	 * Verify one rendered draw.
	 *
	 * @param {HTMLElement} root Draw container.
	 */
	function verifyOne( root ) {
		var banner = root.querySelector( '[data-fh-role="banner"]' );
		var diff   = root.querySelector( '[data-fh-role="diff"]' );

		if ( ! banner ) {
			return;
		}

		var raw = ( window.fishotelDrawPayloads || {} )[ root.getAttribute( 'data-fh-draw' ) ];

		if ( ! raw || ! draw ) {
			setBanner( banner, 'fail', 'Could not verify: the draw data did not load. Reload the page — and if this keeps happening, treat these results as unverified.' );
			return;
		}

		var payload;

		try {
			payload = JSON.parse( raw );
		} catch ( e ) {
			setBanner( banner, 'fail', 'Could not verify: the published draw file is not readable. Treat these results as unverified.' );
			return;
		}

		var report;

		try {
			report = draw.verify( payload );
		} catch ( e ) {
			setBanner( banner, 'fail', 'Could not verify: re-running the draw failed. Treat these results as unverified.' );
			return;
		}

		if ( report.ok ) {
			setBanner( banner, 'ok', 'Verified in your browser. These results are reproducible.' );
			return;
		}

		setBanner( banner, 'fail', 'MISMATCH — the published results do not match a fresh draw from this seed.' );

		if ( diff ) {
			renderDiff( diff, report );
		}
	}

	/**
	 * Verify every draw on the page.
	 */
	function run() {
		var roots = document.querySelectorAll( '[data-fh-draw]' );

		for ( var i = 0; i < roots.length; i++ ) {
			verifyOne( roots[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
}() );
