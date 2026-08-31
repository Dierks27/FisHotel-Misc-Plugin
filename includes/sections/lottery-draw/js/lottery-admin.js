/**
 * Lottery Draw admin behaviour.
 *
 * The draw itself runs here, in the admin's browser, using the same
 * draw.js the public page loads. The result is posted back as plain JSON
 * for storage — the server stores what this file produced and never
 * recomputes it, because a second implementation is exactly what would
 * make published results stop matching what members can reproduce.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */
( function () {
	'use strict';

	var draw = window.FisHotelDraw;

	/**
	 * Generate a seed of the form RVS-YYYY-MM-DD-XXXXX.
	 *
	 * Alphabet excludes O/0 and I/1 so a member reading the seed off a
	 * forum post cannot mistype it.
	 *
	 * @return {string}
	 */
	function generateSeed() {
		var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		var block    = '';
		var bytes    = new Uint8Array( 5 );

		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var f = 0; f < bytes.length; f++ ) {
				bytes[ f ] = Math.floor( Math.random() * 256 );
			}
		}

		for ( var i = 0; i < bytes.length; i++ ) {
			block += alphabet.charAt( bytes[ i ] % alphabet.length );
		}

		var now = new Date();
		var iso = now.getUTCFullYear() + '-'
			+ String( now.getUTCMonth() + 1 ).padStart( 2, '0' ) + '-'
			+ String( now.getUTCDate() ).padStart( 2, '0' );

		return 'RVS-' + iso + '-' + block;
	}

	/**
	 * Wire the "Generate" buttons next to seed fields.
	 */
	function bindSeedGeneration() {
		document.querySelectorAll( '[data-fh-generate-seed]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var form  = button.closest( 'form' );
				var field = form && form.querySelector( '[data-fh-seed-field]' );

				if ( field ) {
					field.value = generateSeed();
					field.focus();
				}
			} );
		} );
	}

	/**
	 * Show or hide the external-source field with the seed method.
	 */
	function bindSeedMethod() {
		document.querySelectorAll( 'form' ).forEach( function ( form ) {
			var radios = form.querySelectorAll( '[data-fh-seed-method]' );
			var rows   = form.querySelectorAll( '[data-fh-seed-source-row]' );

			if ( ! radios.length || ! rows.length ) {
				return;
			}

			var sync = function () {
				var external = false;

				radios.forEach( function ( radio ) {
					if ( radio.checked && 'external' === radio.value ) {
						external = true;
					}
				} );

				rows.forEach( function ( row ) {
					row.hidden = ! external;
				} );
			};

			radios.forEach( function ( radio ) {
				radio.addEventListener( 'change', sync );
			} );

			sync();
		} );
	}

	/**
	 * Render a preview of the computed result before it is submitted.
	 *
	 * @param {HTMLElement} target  Preview container.
	 * @param {Array}       results drawAll() output.
	 */
	function renderPreview( target, results ) {
		target.textContent = '';

		results.forEach( function ( fish ) {
			var block = document.createElement( 'div' );
			block.className = 'fh-draw-preview-fish';

			var heading = document.createElement( 'h4' );
			heading.textContent = fish.name;
			block.appendChild( heading );

			var winners = document.createElement( 'p' );
			winners.appendChild( document.createElement( 'strong' ) ).textContent = 'Winners: ';
			winners.appendChild( document.createTextNode( draw.formatNames( fish.winners ) || '—' ) );
			block.appendChild( winners );

			var waitlist = document.createElement( 'p' );
			waitlist.appendChild( document.createElement( 'strong' ) ).textContent = 'Waitlist: ';
			waitlist.appendChild( document.createTextNode( fish.waitlist.join( ', ' ) || '—' ) );
			block.appendChild( waitlist );

			target.appendChild( block );
		} );
	}

	/**
	 * Run the draw and hand the result to the storage form.
	 */
	function bindRunDraw() {
		var form = document.querySelector( '[data-fh-run-form]' );

		if ( ! form || ! draw ) {
			return;
		}

		var button  = form.querySelector( '[data-fh-run-draw]' );
		var field   = form.querySelector( '[data-fh-results-field]' );
		var submit  = form.querySelector( '[data-fh-publish]' );
		var preview = document.querySelector( '[data-fh-preview]' );
		var status  = document.querySelector( '[data-fh-run-status]' );

		if ( ! button || ! field || ! submit ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var payload;

			try {
				payload = JSON.parse( ( window.fishotelDrawAdmin || {} ).raw || '' );
			} catch ( e ) {
				window.alert( 'Could not read this draw. Reload the page and try again.' );
				return;
			}

			var results = draw.drawAll( payload );

			field.value = JSON.stringify( results );

			if ( preview ) {
				renderPreview( preview, results );
			}

			if ( status ) {
				status.textContent = 'Draw computed in this browser. Review it below, then publish — publishing is final.';
			}

			submit.disabled = false;
			submit.focus();
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( ! field.value ) {
				event.preventDefault();
				window.alert( 'Run the draw first.' );
				return;
			}

			if ( ! window.confirm( 'Publish these results? A published draw can never be edited — corrections have to be published as a new draw.' ) ) {
				event.preventDefault();
			}
		} );
	}

	/**
	 * Copy the BBCode block to the clipboard.
	 */
	function bindCopy() {
		document.querySelectorAll( '[data-fh-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var target = document.querySelector( button.getAttribute( 'data-fh-copy' ) );

				if ( ! target ) {
					return;
				}

				target.select();
				target.setSelectionRange( 0, target.value.length );

				var done = function () {
					var original = button.textContent;
					button.textContent = 'Copied';
					window.setTimeout( function () {
						button.textContent = original;
					}, 1500 );
				};

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( target.value ).then( done, function () {
						document.execCommand( 'copy' );
						done();
					} );
					return;
				}

				document.execCommand( 'copy' );
				done();
			} );
		} );
	}

	/**
	 * Confirm destructive or irreversible actions.
	 */
	function bindConfirms() {
		document.querySelectorAll( '[data-fh-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-fh-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	function init() {
		bindSeedGeneration();
		bindSeedMethod();
		bindRunDraw();
		bindCopy();
		bindConfirms();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
