/**
 * Settings → Sizes → "Tidy up the sizes": the tool's buttons.
 *
 * The page does no thinking of its own: it sends the owner's choices
 * (the short list, the rows set by hand, the choices per fish) to the
 * server, shows the dry run it gets back, and — on Apply or Undo — asks
 * for one step after another until the run is over, showing how far it
 * is. A step that fails is asked again a few times; after that the page
 * says it stopped, and "Carry on" picks it up where it was.
 *
 * What stands between a click on "Tidy up the sizes now" and the server
 * is said next to that button, not at the top of the tool a long scroll
 * away: the backup tick, the word, a choice changed since the preview
 * (the preview is then out of date — there is a second "Show what would
 * change" right there). While a tidy-up is not finished the list and
 * the preview are put away: it is carried on, or undone, first.
 *
 * The helpers are exported for the node test; the wiring only runs in
 * the browser.
 */
( function ( root ) {
	'use strict';

	/**
	 * The rows the owner set by hand: a row whose choice differs from
	 * what the page showed, or that was set by hand before.
	 *
	 * @param {Array} rows { key, value, shown, yours }.
	 * @return {Object} key → choice.
	 */
	function overrides( rows ) {
		var out = {};
		( rows || [] ).forEach( function ( row ) {
			if ( row && row.key && ( row.value !== row.shown || '1' === String( row.yours ) ) ) {
				out[ row.key ] = row.value;
			}
		} );
		return out;
	}

	/**
	 * The fields of a request: the form's own (name → value pairs, as
	 * jQuery's serializeArray() gives them) plus the rows set by hand.
	 *
	 * @param {Array}  pairs { name, value }.
	 * @param {Object} over  overrides().
	 * @param {Object} more  op, nonce, action, …
	 * @return {Object}
	 */
	function fields( pairs, over, more ) {
		var out = {};
		// What the click is comes first, and what Apply cannot do without: should a server cut a very
		// long form short (PHP's max_input_vars), it is the tail of the choices that goes, not these.
		Object.keys( more || {} ).forEach( function ( key ) {
			out[ key ] = more[ key ];
		} );
		( pairs || [] ).forEach( function ( pair ) {
			if ( pair && ( 'token' === pair.name || 'backup' === pair.name || 'confirm' === pair.name || 'short' === pair.name ) ) {
				out[ pair.name ] = pair.value;
			}
		} );
		( pairs || [] ).forEach( function ( pair ) {
			// A "leave alone" radio and an empty pick are no choice at all.
			if ( pair && pair.name && '' !== pair.value && ! ( /^keep_one\[/.test( pair.name ) && '0' === String( pair.value ) ) ) {
				out[ pair.name ] = pair.value;
			}
		} );
		Object.keys( over || {} ).forEach( function ( key ) {
			out[ 'over[' + key + ']' ] = over[ key ];
		} );
		return out;
	}

	/**
	 * What stands in the way of Apply, checked in the page before the
	 * server is asked (which checks it all again).
	 *
	 * @param {Object} state backup (ticked), word (as typed), expect (the word to type), stale (a choice
	 *                       changed since the preview).
	 * @return {string} '' when nothing does, else needBackup | needWord | stale.
	 */
	function blocked( state ) {
		if ( state && state.stale ) {
			return 'stale';
		}
		if ( ! state || ! state.backup ) {
			return 'needBackup';
		}
		if ( String( state.word || '' ).trim().toUpperCase() !== String( state.expect || '' ).toUpperCase() ) {
			return 'needWord';
		}
		return '';
	}

	/**
	 * Step a run until it is over.
	 *
	 * @param {Function} post     ( fields ) → Promise of the server's reply { success, data }.
	 * @param {number}   run      The run's id.
	 * @param {Function} progress Called with each reply's data.
	 * @param {Object}   options  tries (how often a failed step is asked again, default 3),
	 *                            wait ( ms ) → Promise, pause (ms between tries, default 1500).
	 * @return {Promise} Resolves with the last reply's data; rejects when it stopped.
	 */
	function steps( post, run, progress, options ) {
		var tries = options && options.tries ? options.tries : 3;
		var pause = options && undefined !== options.pause ? options.pause : 1500;
		var wait  = options && options.wait ? options.wait : function ( ms ) {
			return new Promise( function ( resolve ) {
				setTimeout( resolve, ms );
			} );
		};
		var failed = 0;

		function next() {
			return post( { op: 'step', run: run } ).then(
				function ( reply ) {
					if ( ! reply || ! reply.success || ! reply.data ) {
						throw new Error( reply && reply.data && reply.data.message ? reply.data.message : '' );
					}
					failed = 0;
					if ( progress ) {
						progress( reply.data );
					}
					if ( reply.data.over ) {
						return reply.data;
					}
					// Another click holds the lock: give it a moment.
					if ( reply.data.progress && 'busy' === reply.data.progress.why ) {
						return wait( pause ).then( next );
					}
					return next();
				}
			).catch( function ( error ) {
				failed++;
				if ( failed >= tries ) {
					throw error;
				}
				return wait( pause ).then( next );
			} );
		}

		return next();
	}

	/**
	 * The browser's wiring.
	 *
	 * @param {Function} $   jQuery.
	 * @param {Object}   cfg fhSizesTidy.
	 */
	function wire( $, cfg ) {
		var $root = $( '#fh-sizes-tidy' );
		if ( ! $root.length ) {
			return;
		}
		var busy  = false;
		var stale = false;

		function post( data ) {
			return new Promise( function ( resolve, reject ) {
				$.post( cfg.ajaxUrl, $.extend( { action: cfg.action, nonce: cfg.nonce }, data ) )
					.done( resolve )
					.fail( function ( xhr ) {
						if ( xhr && xhr.responseJSON ) {
							resolve( xhr.responseJSON );
						} else {
							reject( new Error( '' ) );
						}
					} );
			} );
		}

		function say( text, bad ) {
			$root.find( '.fh-tidy__message' ).not( '.fh-tidy__apply-message' ).text( text || '' ).prop( 'hidden', ! text ).toggleClass( 'fh-tidy__message--bad', !! bad );
		}

		// About Apply: said next to the Apply button (at the top when there is no Apply box).
		function sayApply( text ) {
			var $at = $root.find( '.fh-tidy__apply-message' );
			if ( ! $at.length ) {
				say( text, true );
				return;
			}
			$at.text( text || '' ).prop( 'hidden', ! text );
		}

		// A tidy-up that is not finished comes first: the list and the preview are put away until it
		// is carried on or undone.
		function held() {
			var open = $root.find( '.fh-tidy__status .fh-tidy__continue' ).length > 0;
			$root.find( '.fh-tidy__form' ).prop( 'hidden', open );
			$root.find( '.fh-tidy__held' ).prop( 'hidden', ! open );
		}

		function status( html ) {
			$root.find( '.fh-tidy__status' ).html( html );
			held();
		}

		function progress( text ) {
			$root.find( '.fh-tidy__progress' ).text( text || '' ).prop( 'hidden', ! text );
		}

		function lock( on ) {
			busy = on;
			$root.find( 'button, select, textarea, input' ).prop( 'disabled', on );
			$root.toggleClass( 'fh-tidy--busy', on );
		}

		function request( op, extra ) {
			var rows = $root.find( 'select.fh-tidy__becomes' ).map( function () {
				var $select = $( this );
				return { key: $select.data( 'key' ), value: $select.val(), shown: String( $select.data( 'shown' ) ), yours: String( $select.data( 'yours' ) ) };
			} ).get();
			return fields( $root.find( '.fh-tidy__form' ).serializeArray(), overrides( rows ), $.extend( { op: op }, extra || {} ) );
		}

		function preview( extra ) {
			if ( busy ) {
				return;
			}
			var data = request( 'preview', extra );
			lock( true );
			say( cfg.i18n.working );
			post( data ).then( function ( reply ) {
				lock( false );
				if ( ! reply || ! reply.success ) {
					say( reply && reply.data && reply.data.message ? reply.data.message : cfg.i18n.error, true );
					return;
				}
				say( '' );
				stale = false;
				$root.find( '.fh-tidy__result' ).html( reply.data.html );
			} ).catch( function () {
				lock( false );
				say( cfg.i18n.error, true );
			} );
		}

		function run( id, firstMessage ) {
			lock( true );
			say( '' );
			progress( firstMessage || cfg.i18n.working );
			steps( post, id, function ( data ) {
				progress( data.message );
			} ).then( function ( data ) {
				lock( false );
				$root.find( '.fh-tidy__result' ).empty();
				if ( data.status ) {
					status( data.status );
				}
				progress( data.message );
			} ).catch( function () {
				lock( false );
				progress( cfg.i18n.stopped );
				post( { op: 'status' } ).then( function ( reply ) {
					if ( reply && reply.success && reply.data.status ) {
						status( reply.data.status );
						progress( cfg.i18n.stopped );
					}
				} ).catch( function () {} );
			} );
		}

		held();

		$root.on( 'click', '.fh-tidy__preview', function () {
			preview();
		} );

		$root.on( 'click', '.fh-tidy__suggested', function () {
			var $short = $root.find( '.fh-tidy__short' );
			$short.val( $short.data( 'suggested' ) );
		} );

		// A choice that changes what the preview shows: show it again.
		$root.on( 'change', 'input[name="instock"], .fh-tidy__keep-plus', function () {
			preview();
		} );

		// Any other choice: the preview is out of date. Say so where Apply is — only once there is a
		// preview with an Apply box to be out of date.
		function changed() {
			if ( $root.find( '.fh-tidy__apply' ).length ) {
				stale = true;
				sayApply( cfg.i18n.stale );
			}
		}
		$root.on( 'change', '.fh-tidy__becomes, .fh-tidy__move, input[name^="keep_one["], input[name^="convert["]', changed );
		$root.on( 'input', '.fh-tidy__short', changed );

		$root.on( 'click', '.fh-tidy__finish', function () {
			preview();
			var top = $root.find( '.fh-tidy__form' ).offset();
			if ( top ) {
				root.scrollTo( 0, Math.max( 0, top.top - 60 ) );
			}
		} );

		$root.on( 'click', '.fh-tidy__apply-button', function () {
			if ( busy ) {
				return;
			}
			var why = blocked( {
				stale: stale,
				backup: $root.find( '.fh-tidy__backup' ).is( ':checked' ),
				word: $root.find( '.fh-tidy__confirm' ).val(),
				expect: cfg.word
			} );
			if ( why ) {
				sayApply( cfg.i18n[ why ].replace( '%s', cfg.word ) );
				return;
			}
			var data = request( 'apply' );
			lock( true );
			sayApply( '' );
			post( data ).then( function ( reply ) {
				if ( ! reply || ! reply.success ) {
					lock( false );
					var message = reply && reply.data && reply.data.message ? reply.data.message : cfg.i18n.error;
					if ( reply && reply.data && reply.data.open && reply.data.status ) {
						// A tidy-up is not finished: its box, with Carry on and Undo, takes the place of the preview.
						status( reply.data.status );
						progress( message );
						return;
					}
					stale = stale || !! ( reply && reply.data && reply.data.stale );
					sayApply( message );
					return;
				}
				run( reply.data.run, reply.data.message );
			} ).catch( function () {
				lock( false );
				sayApply( cfg.i18n.error );
			} );
		} );

		$root.on( 'click', '.fh-tidy__continue', function () {
			if ( ! busy ) {
				run( $( this ).data( 'run' ) );
			}
		} );

		$root.on( 'click', '.fh-tidy__undo', function () {
			if ( busy || ! root.confirm( cfg.i18n.confirmUndo ) ) {
				return;
			}
			lock( true );
			post( { op: 'undo' } ).then( function ( reply ) {
				if ( ! reply || ! reply.success ) {
					lock( false );
					say( reply && reply.data && reply.data.message ? reply.data.message : cfg.i18n.error, true );
					return;
				}
				run( reply.data.run, reply.data.message );
			} ).catch( function () {
				lock( false );
				say( cfg.i18n.error, true );
			} );
		} );

		$root.on( 'click', '.fh-tidy__dismiss', function () {
			if ( busy ) {
				return;
			}
			post( { op: 'dismiss', product: $( this ).data( 'product' ) } ).then( function ( reply ) {
				if ( reply && reply.success && reply.data.status ) {
					status( reply.data.status );
				}
			} ).catch( function () {} );
		} );
	}

	var api = { overrides: overrides, fields: fields, blocked: blocked, steps: steps, wire: wire };

	if ( 'object' === typeof module && module.exports ) {
		module.exports = api;
	}
	if ( root && root.jQuery && root.fhSizesTidy ) {
		root.jQuery( function () {
			wire( root.jQuery, root.fhSizesTidy );
		} );
	}
} )( 'undefined' !== typeof window ? window : null );
