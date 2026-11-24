/**
 * Dark admin — the readability guard's sums and its promise to the
 * classic editor (js/dark-admin.js), without a browser: the file exports
 * its pure helpers under node and starts nothing.
 *
 * Run from the plugin root:  node includes/sections/dark-admin/tests/guard.test.js
 */
'use strict';

var path = require( 'path' );
var G = require( path.join( __dirname, '..', 'js', 'dark-admin.js' ) );

var failures = 0;
var checks = 0;

function check( label, condition, detail ) {
	checks++;
	if ( condition ) {
		console.log( '  ok   ' + label );
		return;
	}
	failures++;
	console.log( '  FAIL ' + label + ( detail ? '\n       ' + detail : '' ) );
}

function same( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

var WHITE = G.parse( '#ffffff' );
var PAGE = G.parse( '#1a1a1a' );
var CARD = G.parse( '#2d2d2d' );

console.log( 'Reading colours as the browser reports them' );
check( 'rgb()', same( G.parse( 'rgb(45, 45, 45)' ), { r: 45, g: 45, b: 45, a: 1 } ) );
check( 'rgba()', same( G.parse( 'rgba(0, 0, 0, 0.5)' ), { r: 0, g: 0, b: 0, a: 0.5 } ) );
check( 'the space syntax with a slash and a percentage', same( G.parse( 'rgb(10 20 30 / 50%)' ), { r: 10, g: 20, b: 30, a: 0.5 } ) );
check( 'short and long hex, with alpha', same( G.parse( '#fff' ), { r: 255, g: 255, b: 255, a: 1 } ) && G.parse( '#00000080' ).a === 0.502 );
check( 'transparent', same( G.parse( 'transparent' ), { r: 0, g: 0, b: 0, a: 0 } ) );
check( 'a colour function it does not know is left alone (null)', G.parse( 'oklch(0.7 0.1 200)' ) === null && G.parse( 'teal' ) === null && G.parse( 42 ) === null );
check( 'fractional channels (as WordPress 7 writes some) are kept', G.parse( 'rgb(12.15, 12.15, 12.15)' ).r === 12.15 );

console.log( '\nThe sums' );
check( 'black on white is 21 : 1', Math.abs( G.contrast( G.parse( '#000' ), WHITE ) - 21 ) < 1e-9 );
check( 'the dark admin\'s text on its page is 13.18 : 1', G.contrast( G.parse( '#e0e0e0' ), PAGE ).toFixed( 2 ) === '13.18' );
check( 'the same sums as the PHP palette (link on a card 5.77 : 1)', G.contrast( G.parse( '#0eb5e8' ), CARD ).toFixed( 2 ) === '5.77' );
check( 'half-black over white is mid grey', same( G.over( { r: 0, g: 0, b: 0, a: 0.5 }, WHITE ), { r: 127.5, g: 127.5, b: 127.5, a: 1 } ) );

console.log( '\nThe colour behind the text' );
check( 'the element\'s own solid background', same( G.behind( [ CARD, PAGE ] ), CARD ) );
check( 'see-through layers are painted over the first solid one', same( G.behind( [ { r: 255, g: 255, b: 255, a: 0.1 }, PAGE ] ), G.over( { r: 255, g: 255, b: 255, a: 0.1 }, PAGE ) ) );
check( 'transparent layers are skipped', same( G.behind( [ { r: 0, g: 0, b: 0, a: 0 }, null, CARD ] ), CARD ) );
check( 'nothing solid at all: the browser\'s white page', same( G.behind( [] ), WHITE ) );

console.log( '\nThe rescue' );
check( 'text that reads is left alone', G.rescue( G.parse( '#e0e0e0' ), CARD ) === null );
var grey = G.rescue( G.parse( '#646970' ), CARD );
check( 'WordPress\'s grey on a dark card is made lighter just enough', grey && ! grey.background && G.contrast( grey.color, CARD ) >= 4.5 && G.contrast( grey.color, CARD ) < 6 );
var red = G.rescue( G.parse( '#dc3232' ), CARD );
check( 'red stays red (lighter, still redder than it is blue)', red && red.color.r > red.color.b + 40 && G.contrast( red.color, CARD ) >= 4.5 );
var onWhite = G.rescue( G.parse( '#e0e0e0' ), WHITE );
check( 'the page\'s light text inside another plugin\'s white box goes dark', onWhite && G.luminance( onWhite.color ) < 0.2 && G.contrast( onWhite.color, WHITE ) >= 4.5 );
var link = G.rescue( G.parse( '#0eb5e8' ), WHITE );
check( 'a dark-admin link inside a white box stays blue, darker', link && link.color.b > link.color.r && G.contrast( link.color, WHITE ) >= 4.5 );
var mid = G.parse( '#777777' );
var stuck = G.rescue( G.parse( '#808080' ), mid );
check( 'even on the hardest mid grey, moving the text is enough (no backing needed)', stuck && ! stuck.background && G.contrast( stuck.color, mid ) >= 4.5 );
var worst = 1e9;
for ( var v = 0; v <= 255; v++ ) {
	var bgv = { r: v, g: v, b: v, a: 1 };
	worst = Math.min( worst, G.contrast( G.rescue( G.parse( '#7f7f7f' ), bgv ) ? G.rescue( G.parse( '#7f7f7f' ), bgv ).color : G.parse( '#7f7f7f' ), bgv ) );
}
check( 'every grey background from black to white: the rescued text reads', worst >= 4.5, 'worst ' + worst );
var faint = G.rescue( { r: 224, g: 224, b: 224, a: 0.3 }, CARD );
check( 'see-through text is judged as it shows on its background', faint && G.contrast( faint.color, CARD ) >= 4.5 );
check( 'a stricter bar can be asked for', G.rescue( G.parse( '#a0a0a0' ), CARD ) === null && G.rescue( G.parse( '#a0a0a0' ), CARD, 7 ) !== null );

console.log( '\nThe writing area: classes, never colours' );
check( 'readable: no class', same( G.editorClasses( G.parse( '#e0e0e0' ), G.parse( '#1e1e1e' ) ), [] ) );
check( 'a description\'s black text on the dark writing area: light ink', same( G.editorClasses( G.parse( '#000000' ), G.parse( '#1e1e1e' ) ), [ 'fh-da-ink-light' ] ) );
check( 'light text in a white table cell someone pasted: dark ink', same( G.editorClasses( G.parse( '#e0e0e0' ), WHITE ), [ 'fh-da-ink-dark' ] ) );
check( 'behind a mid grey: light ink on a dark backing', same( G.editorClasses( G.parse( '#808080' ), mid ), [ 'fh-da-ink-light', 'fh-da-paper' ] ) );
check( 'taking the guard\'s classes off leaves the author\'s', G.stripClasses( 'alignleft fh-da-ink-light size-full fh-da-paper' ) === 'alignleft size-full' );
check( 'only the guard\'s own (fh-da-…), not a look-alike', G.stripClasses( 'fh-dark fh-da-ink-dark' ) === 'fh-dark' );
check( 'nothing left: empty (the attribute is then dropped)', G.stripClasses( 'fh-da-ink-dark' ) === '' && G.stripClasses( null ) === '' );

console.log( '\nNothing the guard does is saved into a description' );
var events = {};
var filters = {};
var fakeEditor = {
	initialized: false,
	serializer: {
		addAttributeFilter: function ( name, fn ) {
			filters[ name ] = fn;
		}
	},
	on: function ( names, fn ) {
		names.split( ' ' ).forEach( function ( n ) {
			events[ n ] = fn;
		} );
	}
};
G.hookEditor( fakeEditor );
check( 'it asks the editor to filter the class attribute of whatever it hands over', typeof filters['class'] === 'function' );
function Node( cls ) {
	this.a = { 'class': cls };
}
Node.prototype.attr = function ( name, value ) {
	if ( arguments.length === 1 ) {
		return this.a[ name ];
	}
	if ( value === null ) {
		delete this.a[ name ];
	} else {
		this.a[ name ] = value;
	}
};
var nodes = [ new Node( 'fh-da-ink-light' ), new Node( 'aligncenter fh-da-ink-dark fh-da-paper' ), new Node( 'wp-caption' ) ];
filters['class']( nodes );
check( 'a class that was only the guard\'s: the attribute is gone', ! ( 'class' in nodes[0].a ) );
check( 'the author\'s classes stay, the guard\'s go', nodes[1].a['class'] === 'aligncenter' );
check( 'other elements untouched', nodes[2].a['class'] === 'wp-caption' );
check( 'it re-checks after the editor starts, new content, a paste, undo and redo', [ 'init', 'SetContent', 'Paste', 'Undo', 'Redo' ].every( function ( n ) {
	return typeof events[ n ] === 'function';
} ) );
var before = Object.keys( filters ).length;
G.hookEditor( fakeEditor );
check( 'an editor is hooked once, however often it is met', Object.keys( filters ).length === before && fakeEditor.fhDarkAdmin === true );

console.log( '\n' + checks + ' checks, ' + failures + ' failures' );
process.exit( failures ? 1 : 0 );
