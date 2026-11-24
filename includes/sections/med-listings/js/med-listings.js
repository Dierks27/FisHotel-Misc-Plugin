/**
 * Med listings — the page's buttons.
 *
 * Every change is one request (admin-ajax): Apply and Undo go one product
 * after the other, the category rows one after the other, the page check
 * one page after the other, so nothing runs into the server's time limit
 * and the page says after each one what happened.
 *
 * The report (med-cleanup-report.md) is written here from the page as it
 * stands — the proposals with the owner's own edits, what was changed,
 * the flags, the redirects and the check results. buildReport() is pure
 * and tested headless (tests/report.test.js).
 */
( function () {
	'use strict';

	/** One cell of a Markdown table. */
	function cell( s ) {
		return String( s == null ? '' : s ).replace( /\|/g, '\\|' ).replace( /\r?\n/g, '<br>' ).trim();
	}

	/**
	 * The report as Markdown (pure).
	 *
	 * @param {Object} d { date, products: [{id, name, rows: [{label, current, proposed, ticked}]}],
	 *                   flags: [{type, product, productId, text}], changes: [{productId, product, field, old, new}],
	 *                   termChanges: [string], redirects: [{from, to}], checks: [{label, url, result}] }
	 * @return {string}
	 */
	function buildReport( d ) {
		var out = [];
		out.push( '# Med listings cleanup report' );
		out.push( '' );
		out.push( 'Made ' + ( d.date || '' ) + '. Every medication and medicated-food listing as a plain product listing: name, ingredients, sizes, price — no treatment claims, no dosing, no links to the calculator or QT pages.' );
		out.push( '' );

		out.push( '## Proposed changes' );
		out.push( '' );
		var any = false;
		out.push( '| Product ID | Name | Field | Current | Proposed | Ticked |' );
		out.push( '|---|---|---|---|---|---|' );
		( d.products || [] ).forEach( function ( p ) {
			( p.rows || [] ).forEach( function ( r ) {
				any = true;
				out.push( '| ' + [ p.id, cell( p.name ), cell( r.label ), cell( r.current ), cell( r.proposed === '' ? '(empty)' : r.proposed ), r.ticked ? 'yes' : 'no' ].join( ' | ' ) + ' |' );
			} );
		} );
		if ( ! any ) {
			out.push( '| — | — | — | nothing left to change | — | — |' );
		}
		out.push( '' );

		out.push( '## Changed by the tool' );
		out.push( '' );
		if ( ( d.changes || [] ).length ) {
			out.push( '| Product ID | Name | Field | Old | New |' );
			out.push( '|---|---|---|---|---|' );
			d.changes.forEach( function ( c ) {
				out.push( '| ' + [ c.productId, cell( c.product ), cell( c.field ), cell( c.old ), cell( c.new ) ].join( ' | ' ) + ' |' );
			} );
		} else {
			out.push( 'Nothing yet.' );
		}
		out.push( '' );
		if ( ( d.termChanges || [] ).length ) {
			out.push( 'Categories and tags:' );
			out.push( '' );
			d.termChanges.forEach( function ( t ) {
				out.push( '- ' + t );
			} );
			out.push( '' );
		}

		out.push( '## For the owner to decide (never changed by the tool)' );
		out.push( '' );
		if ( ( d.flags || [] ).length ) {
			d.flags.forEach( function ( f ) {
				out.push( '- **' + cell( f.product ) + '** (#' + f.productId + ', ' + f.type + '): ' + cell( f.text ) );
			} );
		} else {
			out.push( 'Nothing.' );
		}
		out.push( '' );

		out.push( '## Redirects (301)' );
		out.push( '' );
		if ( ( d.redirects || [] ).length ) {
			d.redirects.forEach( function ( r ) {
				out.push( '- `' + r.from + '` → `' + r.to + '` (and its /page/N/ and feed addresses)' );
			} );
		} else {
			out.push( 'None.' );
		}
		out.push( '' );

		if ( ( d.checks || [] ).length ) {
			out.push( '## Page check' );
			out.push( '' );
			d.checks.forEach( function ( c ) {
				out.push( '- ' + cell( c.label ) + ' — ' + c.url + ' — ' + cell( c.result ) );
			} );
			out.push( '' );
		}
		return out.join( '\n' );
	}

	/**
	 * The ingredients as they go into a line (pure; the same as PHP
	 * Composer::ingredient_phrase()): split on , ; + & "and"; ordinary
	 * capitalised words lower case, abbreviations kept; a food joins them
	 * "a, b, and c", anything else "a, b".
	 *
	 * @param {string}  field  As typed.
	 * @param {boolean} isFood A food.
	 * @return {string}
	 */
	function ingredientPhrase( field, isFood ) {
		var parts = String( field || '' ).trim().split( /\s*(?:,|;|(?<=[\p{L}\s])\+|\s&\s|\band\b)\s*/iu ).map( function ( p ) {
			return p.trim().replace( /(?<![\p{L}\p{N}])\p{Lu}\p{Ll}+(?![\p{L}\p{N}])/gu, function ( w ) {
				return w.toLowerCase();
			} );
		} ).filter( function ( p ) {
			return p !== '';
		} );
		if ( ! isFood ) {
			return parts.join( ', ' );
		}
		if ( parts.length < 3 ) {
			return parts.join( ' and ' );
		}
		return parts.slice( 0, -1 ).join( ', ' ) + ', and ' + parts[ parts.length - 1 ];
	}

	/** A proposal with the [[ … ]] blank filled (pure). */
	function fillBlank( template, phrase ) {
		return phrase ? String( template ).replace( /\[\[[^\]]*\]\]/g, phrase ) : String( template );
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { buildReport: buildReport, ingredientPhrase: ingredientPhrase, fillBlank: fillBlank };
	}
	if ( typeof document === 'undefined' ) {
		return;
	}

	var cfg = window.fhMedListings || { ajaxUrl: '', nonce: '', i18n: {} };
	var t   = cfg.i18n || {};

	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}
	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	/** POST to admin-ajax; resolves with the JSON answer. */
	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.catch( function () {
				return { success: false, data: { message: t.failed || 'Not done' } };
			} );
	}

	function say( el, text, kind ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text;
		el.className = el.className.replace( / ?fhml-(ok|bad)/g, '' ) + ( kind ? ' fhml-' + kind : '' );
	}

	/* ── Review rows: a [[ blank ]] cannot be ticked until filled ─────── */
	$$( '.fhml-row' ).forEach( function ( row ) {
		var box  = $( 'textarea.fhml-proposed', row );
		var tick = $( '.fhml-tick', row );
		if ( ! box ) {
			return;
		}
		var hadBlank = box.value.indexOf( '[[' ) !== -1;
		box.addEventListener( 'input', function () {
			var blank = box.value.indexOf( '[[' ) !== -1;
			if ( blank ) {
				tick.checked = false;
			} else if ( hadBlank ) {
				tick.checked = true;
				hadBlank = false;
			}
		} );
		tick.addEventListener( 'change', function () {
			if ( tick.checked && box.value.indexOf( '[[' ) !== -1 ) {
				tick.checked = false;
				say( $( '.fhml-status', row ), t.fillIn, 'bad' );
			}
		} );
	} );

	/* ── One ingredient box per product fills every blank of it ────────── */
	$$( '.fhml-product' ).forEach( function ( block ) {
		var box = $( '.fhml-ingredient', block );
		if ( ! box ) {
			return;
		}
		var isFood = block.getAttribute( 'data-food' ) === '1';
		var boxRow = box.closest( '.fhml-row' );
		var filled = $$( 'textarea.fhml-proposed', block ).filter( function ( ta ) {
			return ta.value.indexOf( '[[' ) !== -1;
		} );
		filled.forEach( function ( ta ) {
			ta.setAttribute( 'data-template', ta.value );
		} );
		box.addEventListener( 'input', function () {
			var phrase = ingredientPhrase( box.value, isFood );
			$( '.fhml-tick', boxRow ).checked = phrase !== '';
			filled.forEach( function ( ta ) {
				ta.value = fillBlank( ta.getAttribute( 'data-template' ), phrase );
				ta.dispatchEvent( new Event( 'input' ) );
			} );
		} );
	} );

	/* ── Apply / Undo per product ────────────────────────────────────── */
	function applyProduct( block ) {
		var rows = $$( '.fhml-row', block ).filter( function ( r ) {
			return $( '.fhml-tick', r ).checked;
		} );
		var status = $( '.fhml-product-status', block );
		if ( ! rows.length ) {
			return Promise.resolve( 0 );
		}
		say( status, t.working );
		var payload = rows.map( function ( r ) {
			return {
				object: parseInt( r.getAttribute( 'data-object' ), 10 ),
				field: r.getAttribute( 'data-field' ),
				hash: r.getAttribute( 'data-hash' ),
				value: $( '.fhml-proposed', r ).value
			};
		} );
		return post( 'fishotel_med_listings_apply', {
			product_id: block.getAttribute( 'data-product' ),
			rows: JSON.stringify( payload )
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				say( status, ( res && res.data && res.data.message ) || t.failed, 'bad' );
				return 0;
			}
			var ok = 0, bad = 0;
			res.data.rows.forEach( function ( r ) {
				var tr = $( '.fhml-row[data-key="' + r.key.replace( /"/g, '' ) + '"]', block );
				if ( r.ok ) {
					ok++;
					if ( tr ) {
						tr.classList.add( 'fhml-row--done' );
						$( '.fhml-tick', tr ).checked = false;
						say( $( '.fhml-status', tr ), t.applied, 'ok' );
					}
				} else {
					bad++;
					if ( tr ) {
						say( $( '.fhml-status', tr ), r.message, 'bad' );
					}
				}
			} );
			say( status, t.applied + ': ' + ok + ( bad ? ' · ' + t.failed + ': ' + bad : '' ) + ' — ' + t.reload, bad ? 'bad' : 'ok' );
			return ok;
		} );
	}

	function undoProduct( block ) {
		var status = $( '.fhml-product-status', block );
		say( status, t.working );
		return post( 'fishotel_med_listings_undo', { product_id: block.getAttribute( 'data-product' ) } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				say( status, t.failed, 'bad' );
				return;
			}
			var kept = res.data.kept || [];
			say( status, t.undone + ': ' + res.data.restored + ( kept.length ? ' · ' + t.failed + ': ' + kept.join( ', ' ) : '' ) + ' — ' + t.reload, kept.length ? 'bad' : 'ok' );
		} );
	}

	$$( '.fhml-product' ).forEach( function ( block ) {
		var a = $( '.fhml-apply', block );
		var u = $( '.fhml-undo', block );
		if ( a ) {
			a.addEventListener( 'click', function () {
				applyProduct( block );
			} );
		}
		if ( u ) {
			u.addEventListener( 'click', function () {
				undoProduct( block );
			} );
		}
	} );

	function inTurn( list, fn ) {
		return list.reduce( function ( p, item ) {
			return p.then( function () {
				return fn( item );
			} );
		}, Promise.resolve() );
	}

	var applyAll = $( '#fhml-apply-all' );
	if ( applyAll ) {
		applyAll.addEventListener( 'click', function () {
			if ( ! window.confirm( t.confirmAll ) ) {
				return;
			}
			var status = $( '#fhml-all-status' );
			say( status, t.working );
			inTurn( $$( '.fhml-product' ), applyProduct ).then( function () {
				say( status, t.reload, 'ok' );
			} );
		} );
	}

	var undoAll = $( '#fhml-undo-all' );
	if ( undoAll ) {
		undoAll.addEventListener( 'click', function () {
			if ( ! window.confirm( t.confirmUndo ) ) {
				return;
			}
			var status = $( '#fhml-all-status' );
			say( status, t.working );
			inTurn( $$( '.fhml-product' ).filter( function ( b ) {
				return !! $( '.fhml-undo', b );
			} ), undoProduct ).then( function () {
				say( status, t.reload, 'ok' );
			} );
		} );
	}

	/* ── Reviews: hide / show again ──────────────────────────────────── */
	$$( '.fhml-review' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var hide = btn.getAttribute( 'data-hide' ) === '1';
			btn.disabled = true;
			post( 'fishotel_med_listings_review', { comment_id: btn.getAttribute( 'data-comment' ), hide: hide ? '1' : '0' } ).then( function ( res ) {
				btn.disabled = false;
				var note = btn.nextElementSibling;
				if ( res && res.success ) {
					btn.setAttribute( 'data-hide', hide ? '0' : '1' );
					btn.textContent = hide ? 'Show it again' : 'Hide';
					say( note, hide ? 'hidden by this page' : 'shown again', 'ok' );
				} else {
					say( note, ( res && res.data && res.data.message ) || t.failed, 'bad' );
				}
			} );
		} );
	} );

	/* ── Categories ──────────────────────────────────────────────────── */
	function showWhen( tr ) {
		var action = $( '.fhml-action', tr ).value;
		$$( '.fhml-when', tr ).forEach( function ( el ) {
			el.style.display = el.classList.contains( 'fhml-when--' + action ) ? '' : 'none';
		} );
	}
	$$( '.fhml-term' ).forEach( function ( tr ) {
		showWhen( tr );
		$( '.fhml-action', tr ).addEventListener( 'change', function () {
			showWhen( tr );
			var tick = $( '.fhml-tick', tr );
			if ( ! tick.disabled ) {
				tick.checked = $( '.fhml-action', tr ).value !== 'keep';
			}
		} );
	} );

	var termsApply = $( '#fhml-terms-apply' );
	if ( termsApply ) {
		termsApply.addEventListener( 'click', function () {
			var rows = $$( '.fhml-term' ).filter( function ( tr ) {
				return $( '.fhml-tick', tr ).checked;
			} );
			if ( ! rows.length || ! window.confirm( t.confirmTerms ) ) {
				return;
			}
			var status = $( '#fhml-terms-status' );
			say( status, t.working );
			inTurn( rows, function ( tr ) {
				var val = function ( sel ) {
					var el = $( sel, tr );
					return el ? el.value : '';
				};
				var row = {
					id: parseInt( tr.getAttribute( 'data-id' ), 10 ),
					tax: tr.getAttribute( 'data-tax' ),
					action: val( '.fhml-action' ),
					merge_to: val( '.fhml-merge-to' ) || 'parent',
					redirect_to: val( '.fhml-redirect-to' ) || 'parent',
					new_name: val( '.fhml-new-name' ),
					new_slug: val( '.fhml-new-slug' ),
					new_desc: val( '.fhml-new-desc' )
				};
				return post( 'fishotel_med_listings_term', { row: JSON.stringify( row ) } ).then( function ( res ) {
					var ok = res && res.success && res.data.ok;
					say( $( '.fhml-status', tr ), ( res && res.data && res.data.message ) || t.failed, ok ? 'ok' : 'bad' );
					if ( ok ) {
						$( '.fhml-tick', tr ).checked = false;
						tr.classList.add( 'fhml-row--done' );
					}
				} );
			} ).then( function () {
				say( status, t.reload, 'ok' );
			} );
		} );
	}

	$$( '.fhml-term-undo' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var li = btn.closest( '.fhml-term-change' );
			btn.disabled = true;
			post( 'fishotel_med_listings_term_undo', { change: li.getAttribute( 'data-change' ) } ).then( function ( res ) {
				var ok = res && res.success && res.data.ok;
				say( $( '.fhml-status', li ), ( ( res && res.data && res.data.message ) || t.failed ) + ( ok ? ' — ' + t.reload : '' ), ok ? 'ok' : 'bad' );
				btn.disabled = ok;
			} );
		} );
	} );

	$$( '.fhml-redirect-remove' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var tr = btn.closest( '.fhml-redirect' );
			btn.disabled = true;
			post( 'fishotel_med_listings_redirect_remove', { from: tr.getAttribute( 'data-from' ) } ).then( function ( res ) {
				if ( res && res.success ) {
					tr.parentNode.removeChild( tr );
				} else {
					btn.disabled = false;
				}
			} );
		} );
	} );

	/* ── Verify ──────────────────────────────────────────────────────── */
	var purge = $( '#fhml-purge' );
	if ( purge ) {
		purge.addEventListener( 'click', function () {
			var status = $( '#fhml-verify-status' );
			say( status, t.working );
			post( 'fishotel_med_listings_purge', {} ).then( function ( res ) {
				say( status, ( res && res.data && res.data.message ) || t.failed, res && res.success ? 'ok' : 'bad' );
			} );
		} );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = String( s == null ? '' : s );
		return d.innerHTML;
	}

	var check = $( '#fhml-check' );
	if ( check ) {
		check.addEventListener( 'click', function () {
			var status   = $( '#fhml-verify-status' );
			var pages    = $$( '.fhml-page' );
			var baseline = {};
			var failed   = 0;
			var stopped  = false;
			say( status, t.working );
			check.disabled = true;
			inTurn( pages, function ( tr ) {
				if ( stopped ) {
					return Promise.resolve();
				}
				var out = $( '.fhml-result', tr );
				out.innerHTML = esc( t.working );
				return post( 'fishotel_med_listings_check', { url: tr.getAttribute( 'data-url' ) } ).then( function ( res ) {
					if ( ! res || ! res.success ) {
						out.innerHTML = '<span class="fhml-bad">' + esc( ( res && res.data && res.data.message ) || t.failed ) + '</span>';
						tr.setAttribute( 'data-result', t.failed );
						return;
					}
					if ( ! res.data.fetched ) {
						out.innerHTML = '<span class="fhml-bad">' + esc( res.data.error ) + '</span>';
						tr.setAttribute( 'data-result', res.data.error );
						if ( ! res.data.code && tr.getAttribute( 'data-kind' ) === 'home' ) {
							stopped = true;
							say( status, t.noFetch, 'bad' );
						}
						return;
					}
					var kind = tr.getAttribute( 'data-kind' );
					var hits = res.data.hits || [];
					if ( kind === 'home' ) {
						hits.forEach( function ( h ) {
							baseline[ h.key ] = true;
						} );
						out.innerHTML = hits.length ? '<span class="fhml-muted">' + esc( t.everyPage ) + ':</span><ul>' + hits.map( function ( h ) {
							return '<li><code>' + esc( h.word ) + '</code> ' + esc( h.where ) + ': ' + esc( h.snippet ) + '</li>';
						} ).join( '' ) + '</ul>' : '<span class="fhml-ok">' + esc( t.pass ) + '</span>';
						tr.setAttribute( 'data-result', hits.length ? hits.length + ' words on every page' : 'clean' );
						return;
					}
					var own = hits.filter( function ( h ) {
						return ! baseline[ h.key ];
					} );
					if ( ! own.length ) {
						out.innerHTML = '<span class="fhml-ok">✓ ' + esc( t.pass ) + '</span>';
						tr.setAttribute( 'data-result', 'clean' );
						return;
					}
					failed++;
					out.innerHTML = '<span class="fhml-bad">✗ ' + esc( t.fail ) + ':</span><ul>' + own.map( function ( h ) {
						return '<li><code>' + esc( h.word ) + '</code> <span class="fhml-muted">' + esc( h.where ) + '</span> — ' + esc( h.snippet ) + '</li>';
					} ).join( '' ) + '</ul>';
					tr.setAttribute( 'data-result', 'FAIL: ' + own.map( function ( h ) {
						return h.word + ' (' + h.where + ': ' + h.snippet + ')';
					} ).join( '; ' ) );
				} );
			} ).then( function () {
				check.disabled = false;
				if ( ! stopped ) {
					say( status, failed ? failed + ' ✗' : '✓', failed ? 'bad' : 'ok' );
				}
			} );
		} );
	}

	/* ── Report download ─────────────────────────────────────────────── */
	function collect() {
		return {
			date: new Date().toISOString().slice( 0, 16 ).replace( 'T', ' ' ),
			products: $$( '.fhml-product' ).map( function ( b ) {
				return {
					id: b.getAttribute( 'data-product' ),
					name: b.getAttribute( 'data-name' ),
					rows: $$( '.fhml-row', b ).filter( function ( r ) {
						return ! r.classList.contains( 'fhml-row--done' );
					} ).map( function ( r ) {
						return {
							label: r.getAttribute( 'data-label' ),
							current: r.getAttribute( 'data-current' ),
							proposed: $( '.fhml-proposed', r ).value,
							ticked: $( '.fhml-tick', r ).checked
						};
					} )
				};
			} ),
			flags: $$( '.fhml-flag' ).map( function ( li ) {
				return {
					type: li.getAttribute( 'data-type' ),
					product: li.getAttribute( 'data-product' ),
					productId: li.getAttribute( 'data-product-id' ),
					text: li.getAttribute( 'data-text' )
				};
			} ),
			changes: $$( '.fhml-change' ).map( function ( li ) {
				return {
					productId: li.getAttribute( 'data-product-id' ),
					product: li.getAttribute( 'data-product' ),
					field: li.getAttribute( 'data-field' ),
					old: li.getAttribute( 'data-old' ),
					new: li.getAttribute( 'data-new' )
				};
			} ),
			termChanges: $$( '.fhml-term-change' ).map( function ( li ) {
				return li.getAttribute( 'data-text' );
			} ),
			redirects: $$( '.fhml-redirect' ).map( function ( tr ) {
				return { from: tr.getAttribute( 'data-from' ), to: tr.getAttribute( 'data-to' ) };
			} ),
			checks: $$( '.fhml-page' ).filter( function ( tr ) {
				return tr.hasAttribute( 'data-result' );
			} ).map( function ( tr ) {
				return { label: tr.getAttribute( 'data-label' ), url: tr.getAttribute( 'data-url' ), result: tr.getAttribute( 'data-result' ) };
			} )
		};
	}

	var report = $( '#fhml-report' );
	if ( report ) {
		report.addEventListener( 'click', function () {
			var blob = new Blob( [ buildReport( collect() ) ], { type: 'text/markdown;charset=utf-8' } );
			var a    = document.createElement( 'a' );
			a.href     = URL.createObjectURL( blob );
			a.download = 'med-cleanup-report.md';
			document.body.appendChild( a );
			a.click();
			setTimeout( function () {
				URL.revokeObjectURL( a.href );
				a.parentNode.removeChild( a );
			}, 500 );
		} );
	}
}() );
