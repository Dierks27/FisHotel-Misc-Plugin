/**
 * FisHotel Misc — Dark admin: the readability guard.
 *
 * dark-admin.css darkens every screen WordPress, WooCommerce and the
 * FisHotel plugins draw with their own style sheets. What a style sheet
 * cannot see is a colour another plugin wrote straight onto an element
 * (style="color:#333", style="background:#fff", <font color>) — on a dark
 * page that is dark text on dark, or the page's light text on a white box.
 * This script looks at every piece of text the person can see, works out
 * the colour it is drawn in and the colour behind it, and where the two
 * are under 4.5 : 1 (WCAG's bar for normal text) it makes the text lighter
 * or darker — keeping its hue, so red stays red — until it reads. New text
 * (AJAX, variations, order items, React pages) is checked as it appears.
 *
 * In the classic editor's writing area (an iframe) it does the same with
 * two plain classes instead of colours, and takes them off again every
 * time the editor hands its content over, so nothing it does is ever
 * saved into a description.
 *
 * The sums (parse, luminance, contrast, compositing, the rescue) are pure
 * and exported for node: tests/guard.test.js.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.fhDarkAdmin = api;
		if ( root.document ) {
			api.start( root );
		}
	}
}( this, function () {
	'use strict';

	var MIN = 4.5;
	var WHITE = { r: 255, g: 255, b: 255, a: 1 };
	var BLACK = { r: 0, g: 0, b: 0, a: 1 };
	var INK_LIGHT = { r: 224, g: 224, b: 224, a: 1 }; // --fh-da-text
	var INK_DARK = { r: 30, g: 30, b: 30, a: 1 };     // --fh-da-light-ink
	var SKIP_TAGS = /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|SVG|PATH|CANVAS|IFRAME|OBJECT|VIDEO|AUDIO|IMG|BR|HR|META|LINK|OPTION|OPTGROUP)$/;
	var FIELD_TAGS = /^(INPUT|TEXTAREA|SELECT)$/;
	var TEXT_INPUTS = /^(text|search|email|url|tel|number|password|date|datetime-local|month|week|time|)$/;

	/* ---------------------------------------------------------------- */
	/*  Pure sums                                                        */
	/* ---------------------------------------------------------------- */

	/**
	 * A CSS colour as the browser reports it ("rgb(1, 2, 3)",
	 * "rgba(1, 2, 3, 0.5)", "rgb(1 2 3 / 50%)", "#abc", "#aabbcc",
	 * "#aabbccdd", "transparent") → { r, g, b, a }, or null for anything
	 * else (a colour function the sums do not know is left alone).
	 */
	function parse( str ) {
		if ( typeof str !== 'string' ) {
			return null;
		}
		var s = str.trim().toLowerCase();
		if ( s === 'transparent' ) {
			return { r: 0, g: 0, b: 0, a: 0 };
		}
		var m = s.match( /^#([0-9a-f]{3,8})$/ );
		if ( m ) {
			var h = m[1];
			if ( h.length === 3 || h.length === 4 ) {
				h = h.split( '' ).map( function ( c ) { return c + c; } ).join( '' );
			}
			if ( h.length !== 6 && h.length !== 8 ) {
				return null;
			}
			return {
				r: parseInt( h.substr( 0, 2 ), 16 ),
				g: parseInt( h.substr( 2, 2 ), 16 ),
				b: parseInt( h.substr( 4, 2 ), 16 ),
				a: h.length === 8 ? Math.round( parseInt( h.substr( 6, 2 ), 16 ) / 255 * 1000 ) / 1000 : 1
			};
		}
		m = s.match( /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,/]\s*([\d.]+)(%?)\s*)?\)$/ );
		if ( ! m ) {
			return null;
		}
		var a = m[4] === undefined ? 1 : parseFloat( m[4] ) / ( m[5] === '%' ? 100 : 1 );
		return { r: clamp( +m[1], 255 ), g: clamp( +m[2], 255 ), b: clamp( +m[3], 255 ), a: clamp( a, 1 ) };
	}

	function clamp( n, max ) {
		return Math.max( 0, Math.min( max, n ) );
	}

	/** { r, g, b, a } → "rgb(…)" / "rgba(…)". */
	function css( c ) {
		var r = Math.round( c.r ), g = Math.round( c.g ), b = Math.round( c.b );
		return c.a >= 1 ? 'rgb(' + r + ', ' + g + ', ' + b + ')' : 'rgba(' + r + ', ' + g + ', ' + b + ', ' + c.a + ')';
	}

	/** WCAG relative luminance, 0 (black) … 1 (white). */
	function luminance( c ) {
		function lin( v ) {
			v = v / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * lin( c.r ) + 0.7152 * lin( c.g ) + 0.0722 * lin( c.b );
	}

	/** WCAG contrast ratio, 1 … 21. */
	function contrast( a, b ) {
		var la = luminance( a ), lb = luminance( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	/** `top` painted over the opaque `bottom`. */
	function over( top, bottom ) {
		var a = top.a;
		return {
			r: top.r * a + bottom.r * ( 1 - a ),
			g: top.g * a + bottom.g * ( 1 - a ),
			b: top.b * a + bottom.b * ( 1 - a ),
			a: 1
		};
	}

	/**
	 * The colour actually behind a piece of text: the background colours
	 * from the element itself outwards (nearest first), painted over each
	 * other down to the first solid one — or over white, the browser's own
	 * page colour, when none is solid.
	 */
	function behind( layers ) {
		var stack = [];
		for ( var i = 0; i < layers.length; i++ ) {
			var c = layers[ i ];
			if ( ! c || c.a <= 0 ) {
				continue;
			}
			stack.push( c );
			if ( c.a >= 1 ) {
				break;
			}
		}
		var out = WHITE;
		for ( var j = stack.length - 1; j >= 0; j-- ) {
			out = over( stack[ j ], out );
		}
		return out;
	}

	/** `c` moved `t` (0 … 1) of the way to `to`. */
	function mix( c, to, t ) {
		return {
			r: c.r + ( to.r - c.r ) * t,
			g: c.g + ( to.g - c.g ) * t,
			b: c.b + ( to.b - c.b ) * t,
			a: 1
		};
	}

	/**
	 * What to do about text `fg` on `bg` (both opaque after compositing):
	 * null when it reads (≥ min) already, otherwise { color }: the same hue
	 * made lighter on a dark background, darker on a light one, just far
	 * enough to reach min. White or black always reaches 4.5 : 1 on any
	 * background (the worst case, a grey of luminance 0.18, still gives
	 * 4.58), so moving the text is always enough.
	 */
	function rescue( fg, bg, min ) {
		min = min || MIN;
		fg = fg.a < 1 ? over( fg, bg ) : fg;
		if ( contrast( fg, bg ) >= min ) {
			return null;
		}
		var toward = contrast( WHITE, bg ) >= contrast( BLACK, bg ) ? WHITE : BLACK;
		for ( var step = 1; step <= 20; step++ ) {
			var tried = mix( fg, toward, step / 20 );
			if ( contrast( tried, bg ) >= min ) {
				return { color: tried };
			}
		}
		return { color: toward };
	}

	/**
	 * The writing area's choice, in classes (never colours, never saved):
	 * 'fh-da-ink-light', 'fh-da-ink-dark', or both 'fh-da-ink-light' and
	 * 'fh-da-paper' (a dark backing) behind a mid grey.
	 */
	function editorClasses( fg, bg, min ) {
		min = min || MIN;
		fg = fg.a < 1 ? over( fg, bg ) : fg;
		if ( contrast( fg, bg ) >= min ) {
			return [];
		}
		var light = contrast( INK_LIGHT, bg ), dark = contrast( INK_DARK, bg );
		if ( Math.max( light, dark ) < min ) {
			return [ 'fh-da-ink-light', 'fh-da-paper' ];
		}
		return [ light >= dark ? 'fh-da-ink-light' : 'fh-da-ink-dark' ];
	}

	/** A class list with the guard's own classes taken out ('' when none is left). */
	function stripClasses( value ) {
		return String( value || '' ).split( /\s+/ ).filter( function ( c ) {
			return c && ! /^fh-da-/.test( c );
		} ).join( ' ' );
	}

	/* ---------------------------------------------------------------- */
	/*  The page                                                         */
	/* ---------------------------------------------------------------- */

	var stats = { checked: 0, fixed: 0, fixes: [] };

	function ownText( el ) {
		for ( var n = el.firstChild; n; n = n.nextSibling ) {
			if ( n.nodeType === 3 && /\S/.test( n.nodeValue ) ) {
				return true;
			}
		}
		return false;
	}

	function isField( el ) {
		if ( ! FIELD_TAGS.test( el.tagName ) ) {
			return false;
		}
		return el.tagName !== 'INPUT' || TEXT_INPUTS.test( ( el.getAttribute( 'type' ) || '' ).toLowerCase() );
	}

	/** Shown and not switched off (a disabled control may be faint on purpose). */
	function shown( el, cs ) {
		if ( cs.visibility === 'hidden' || cs.display === 'none' || parseFloat( cs.opacity ) === 0 ) {
			return false;
		}
		var r = el.getBoundingClientRect();
		var view = el.ownerDocument.defaultView;
		if ( r.width <= 1 || r.height <= 1 || r.bottom + view.pageYOffset <= 0 || r.right + view.pageXOffset <= 0 ) {
			return false; // .screen-reader-text and its kind, or parked off the page
		}
		if ( el.closest( ':disabled, .disabled, [aria-disabled="true"], .screen-reader-text, .screen-reader-shortcut, details:not([open]) > :not(summary)' ) ) {
			return false;
		}
		return true;
	}

	/** The background layers from `el` outwards; null when an image is behind the text. */
	function layers( el, win ) {
		var out = [];
		for ( var n = el; n && n.nodeType === 1; n = n.parentElement ) {
			var cs = win.getComputedStyle( n );
			var c = parse( cs.backgroundColor ) || { r: 0, g: 0, b: 0, a: 0 };
			if ( cs.backgroundImage && cs.backgroundImage !== 'none' && c.a < 1 ) {
				return null;
			}
			out.push( c );
			if ( c.a >= 1 ) {
				return out;
			}
		}
		return out;
	}

	/** Check one element; returns what was decided (or null). */
	function check( el, win ) {
		if ( ! el || el.nodeType !== 1 || SKIP_TAGS.test( el.tagName.toUpperCase() ) ) {
			return null;
		}
		if ( ! ownText( el ) && ! isField( el ) ) {
			return null;
		}
		var cs = win.getComputedStyle( el );
		if ( ! shown( el, cs ) ) {
			return null;
		}
		var fg = parse( cs.color );
		var under = layers( el, win );
		if ( ! fg || fg.a === 0 || ! under ) {
			return null;
		}
		stats.checked++;
		var bg = behind( under );
		return { el: el, fg: fg, bg: bg, fix: rescue( fg, bg ) };
	}

	function apply( found ) {
		var el = found.el, fix = found.fix;
		var important = /!\s*important/i.test( el.getAttribute( 'style' ) || '' );
		if ( important ) {
			el.style.setProperty( 'color', css( fix.color ), 'important' );
		} else {
			el.style.setProperty( '--fh-da-fix-fg', css( fix.color ) );
			el.classList.add( 'fh-da-fix' );
			// A sheet that says "!important" on a stronger selector still wins over the class:
			// then the colour goes on the element itself.
			var now = parse( el.ownerDocument.defaultView.getComputedStyle( el ).color );
			if ( now && contrast( now, found.bg ) < MIN ) {
				el.style.setProperty( 'color', css( fix.color ), 'important' );
			}
		}
		stats.fixed++;
		if ( stats.fixes.length < 200 ) {
			stats.fixes.push( {
				where: describe( el ),
				text: ( el.textContent || el.value || '' ).trim().slice( 0, 60 ),
				was: css( found.fg ),
				on: css( found.bg ),
				now: css( fix.color )
			} );
		}
	}

	function describe( el ) {
		var parts = [];
		for ( var n = el, i = 0; n && n.nodeType === 1 && i < 4; n = n.parentElement, i++ ) {
			var p = n.tagName.toLowerCase();
			if ( n.id ) {
				p += '#' + n.id;
				parts.unshift( p );
				break;
			}
			var cls = stripClasses( n.getAttribute( 'class' ) ).split( ' ' ).slice( 0, 2 ).join( '.' );
			parts.unshift( p + ( cls ? '.' + cls : '' ) );
		}
		return parts.join( ' > ' );
	}

	/** Check `scope` and everything in it (the page's own document). */
	function scan( scope, win ) {
		var list = [ scope ].concat( Array.prototype.slice.call( scope.querySelectorAll ? scope.querySelectorAll( '*' ) : [] ) );
		var todo = [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].closest && list[ i ].closest( '.mce-content-body, .fh-da-skip' ) ) {
				continue;
			}
			var found = check( list[ i ], win );
			if ( found && found.fix ) {
				todo.push( found );
			}
		}
		// Decide everything first, then write, so one fix does not change the next element's reading.
		todo.forEach( apply );
	}

	/* ---- the classic editor's writing area -------------------------- */

	function scanEditor( ed ) {
		var doc = ed.getDoc && ed.getDoc();
		var win = ed.getWin && ed.getWin();
		if ( ! doc || ! doc.body || ! win ) {
			return;
		}
		var els = doc.body.querySelectorAll( '*' );
		var todo = [];
		for ( var i = 0; i < els.length; i++ ) {
			var el = els[ i ];
			if ( /fh-da-ink-/.test( el.className || '' ) ) {
				continue; // already decided
			}
			var found = check( el, win );
			if ( found && found.fix ) {
				todo.push( { el: el, add: editorClasses( found.fg, found.bg ) } );
			}
		}
		todo.forEach( function ( t ) {
			t.add.forEach( function ( c ) {
				t.el.classList.add( c );
			} );
		} );
	}

	function hookEditor( ed ) {
		if ( ! ed || ed.fhDarkAdmin ) {
			return;
		}
		ed.fhDarkAdmin = true;
		var addFilter = function () {
			if ( ed.serializer && ! ed.fhDarkAdminFilter ) {
				ed.fhDarkAdminFilter = true;
				// Whatever the editor hands over — saving, autosave, the Text tab — leaves without our classes.
				ed.serializer.addAttributeFilter( 'class', function ( nodes ) {
					for ( var i = 0; i < nodes.length; i++ ) {
						var kept = stripClasses( nodes[ i ].attr( 'class' ) );
						nodes[ i ].attr( 'class', kept === '' ? null : kept );
					}
				} );
			}
		};
		var run = debounce( function () {
			addFilter();
			scanEditor( ed );
		}, 250 );
		addFilter();
		ed.on( 'PreInit', addFilter );
		ed.on( 'init', run );
		ed.on( 'SetContent Paste Undo Redo ExecCommand', run );
		if ( ed.initialized ) {
			run();
		}
	}

	function hookEditors( win ) {
		var t = win.tinymce;
		if ( ! t || ! t.on || t.fhDarkAdmin ) {
			return;
		}
		t.fhDarkAdmin = true;
		var editors = typeof t.get === 'function' ? t.get() : ( t.editors || [] );
		for ( var i = 0; i < editors.length; i++ ) {
			hookEditor( editors[ i ] );
		}
		t.on( 'AddEditor', function ( e ) {
			hookEditor( e.editor );
		} );
	}

	/* ---- start ------------------------------------------------------ */

	function debounce( fn, ms ) {
		var timer = null;
		return function () {
			clearTimeout( timer );
			timer = setTimeout( fn, ms );
		};
	}

	function start( win ) {
		var doc = win.document;
		var pending = [];
		var observer = null;

		var flush = debounce( function () {
			var targets = pending;
			pending = [];
			if ( targets.length > 40 || targets.indexOf( doc.body ) !== -1 ) {
				scan( doc.body, win );
			} else {
				targets.forEach( function ( t ) {
					if ( t.isConnected ) {
						scan( t, win );
					}
				} );
			}
			if ( observer ) {
				observer.takeRecords(); // what the fixes themselves changed
			}
			hookEditors( win );
		}, 200 );

		var go = function () {
			if ( ! doc.body || ! doc.body.classList.contains( 'fh-dark-admin' ) ) {
				return;
			}
			scan( doc.body, win );
			hookEditors( win );
			if ( win.MutationObserver ) {
				observer = new win.MutationObserver( function ( records ) {
					records.forEach( function ( r ) {
						var t = r.target.nodeType === 1 ? r.target : r.target.parentElement;
						if ( t && pending.indexOf( t ) === -1 ) {
							pending.push( t );
						}
					} );
					flush();
				} );
				observer.observe( doc.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: [ 'style', 'class', 'hidden' ] } );
			}
			// Late drawers (WooCommerce's React pages, editors) once more after they settle.
			win.setTimeout( function () {
				pending.push( doc.body );
				flush();
			}, 1500 );
		};

		if ( doc.readyState === 'loading' ) {
			doc.addEventListener( 'DOMContentLoaded', go );
		} else {
			go();
		}
	}

	return {
		MIN: MIN,
		parse: parse,
		css: css,
		luminance: luminance,
		contrast: contrast,
		over: over,
		behind: behind,
		mix: mix,
		rescue: rescue,
		editorClasses: editorClasses,
		stripClasses: stripClasses,
		stats: stats,
		scan: scan,
		hookEditor: hookEditor,
		start: start
	};
} ) );
