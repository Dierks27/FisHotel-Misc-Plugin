/**
 * Requests form JS — the pure helpers of js/requests-form.js (the private
 * request form and the public shortcode): a new fish row from the
 * template, the row limit, the suggestion options (escaped, no name
 * twice), when a lookup is worth making, and the lookup's address. The
 * file exports its helpers and wires the page only when there is a
 * document, so it runs here without one. A tiny stand-in for the DOM then
 * drives boot(): "Add another fish" appears, adds a row with the next
 * index and number, and goes away at the limit.
 *
 * Run from the plugin root:  node includes/sections/requests/tests/requests-form.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'requests-form.js' ) );

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

function same( label, expected, actual ) {
	check( label, JSON.stringify( expected ) === JSON.stringify( actual ), 'expected ' + JSON.stringify( expected ) + ', got ' + JSON.stringify( actual ) );
}

console.log( 'Rows' );
same( 'a template row takes its index everywhere', '<input name="rows[4][fish]"><input name="rows[4][qty]">', helpers.rowHtml( '<input name="rows[__i__][fish]"><input name="rows[__i__][qty]">', 4 ) );
check( 'another row may be added below the limit', helpers.canAdd( 9, 10 ) );
check( 'not at the limit', ! helpers.canAdd( 10, 10 ) );
check( 'a missing limit means ten', helpers.canAdd( 9, 0 ) && ! helpers.canAdd( 10, undefined ) );

console.log( '\nSuggestions' );
same(
	'names become options, the scientific name their label',
	'<option value="Blue Test Wrasse" label="Testus caeruleus"></option><option value="Red Test Goby"></option>',
	helpers.optionsHtml( [ { name: 'Blue Test Wrasse', sci: 'Testus caeruleus' }, { name: 'Red Test Goby', sci: '' } ] )
);
same( 'no name twice, whatever the case', '<option value="Blue Test Wrasse"></option>', helpers.optionsHtml( [ { name: 'Blue Test Wrasse' }, { name: 'blue test wrasse ' } ] ) );
same( 'a name cannot break out of the option', '<option value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"></option>', helpers.optionsHtml( [ { name: '"><script>x</script>' } ] ) );
same( 'junk gives nothing', '', helpers.optionsHtml( [ null, {}, { name: 5 }, { name: '  ' } ] ) + helpers.optionsHtml( 'nope' ) + helpers.optionsHtml( null ) );
same( 'escaping', '&lt;a href=&quot;x&quot;&gt;&amp;&#039;', helpers.escapeHtml( '<a href="x">&\'' ) );
same( 'null is empty', '', helpers.escapeHtml( null ) + helpers.escapeHtml( undefined ) );

console.log( '\nLookups' );
check( 'two letters are worth a lookup', helpers.shouldLookup( 'wr', '' ) );
check( 'one is not', ! helpers.shouldLookup( 'w', '' ) && ! helpers.shouldLookup( '  w ', '' ) );
check( 'nor the term that was just looked up', ! helpers.shouldLookup( 'wrasse ', 'wrasse' ) );
check( 'nor something very long', ! helpers.shouldLookup( new Array( 80 ).join( 'x' ), '' ) );
same(
	'the address carries the action, the term and the link\'s token',
	'https://example.test/wp-admin/admin-ajax.php?action=fishotel_requests_suggest&term=blue%20wr&token=abc123',
	helpers.lookupUrl( { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', action: 'fishotel_requests_suggest' }, ' blue wr ', 'abc123' )
);
same( 'an address that already has a query keeps it', 'x.php?a=1&action=s&term=wr&token=', helpers.lookupUrl( { ajaxUrl: 'x.php?a=1', action: 's' }, 'wr', '' ) );

console.log( '\nThe page, with a stand-in for the DOM' );

/**
 * Just enough of a document for boot(): one form with a rows holder, a
 * template, an "Add another fish" button and a datalist.
 */
function fakePage( startRows ) {
	var listeners = {};
	var rows = {
		count: startRows,
		attrs: { 'data-next': String( startRows ) },
		added: [],
		querySelectorAll: function () { return { length: rows.count }; },
		querySelector: function () { return { focus: function () {} }; },
		getAttribute: function ( name ) { return rows.attrs[ name ]; },
		setAttribute: function ( name, value ) { rows.attrs[ name ] = value; },
		// As in a real document, appending a node takes it out of where it was.
		appendChild: function ( node ) { node.owner.children.shift(); rows.count++; rows.added.push( node ); }
	};
	var add = {
		hidden: true,
		addEventListener: function ( type, fn ) { listeners[ 'add:' + type ] = fn; }
	};
	var form = {
		querySelector: function ( selector ) {
			if ( selector === '.fh-rq-rows' ) { return rows; }
			if ( selector === '.fh-rq-row-template' ) { return { innerHTML: '<fieldset class="fh-rq-row"><span class="fh-rq-row__n"></span><input name="rows[__i__][fish]"></fieldset>' }; }
			if ( selector === '.fh-rq-add' ) { return add; }
			if ( selector === 'datalist' ) { return { innerHTML: '' }; }
			return null;
		},
		getAttribute: function () { return 'tok'; },
		addEventListener: function ( type, fn ) { listeners[ 'form:' + type ] = fn; }
	};
	var doc = {
		querySelectorAll: function () { return [ form ]; },
		createElement: function () {
			var holder = { children: [] };
			Object.defineProperty( holder, 'innerHTML', {
				set: function ( value ) { holder.children = [ { owner: holder, html: value, number: { textContent: '' } } ]; },
				get: function () { return holder.children.length ? holder.children[0].html : ''; }
			} );
			Object.defineProperty( holder, 'firstElementChild', { get: function () { return holder.children.length ? holder.children[0] : null; } } );
			holder.querySelector = function () { return holder.children.length ? holder.children[0].number : null; };
			return holder;
		}
	};
	return { root: { document: doc, setTimeout: function () {}, clearTimeout: function () {} }, rows: rows, add: add, listeners: listeners };
}

var page = fakePage( 3 );
helpers.boot( page.root, { maxRows: 5 } );
check( 'the button is shown once the script runs (it is hidden without it)', page.add.hidden === false );
page.listeners[ 'add:click' ]();
same( 'a click adds a row with the next index and the next number', [ 4, '<fieldset class="fh-rq-row"><span class="fh-rq-row__n"></span><input name="rows[3][fish]"></fieldset>', '4', '4' ], [ page.rows.count, page.rows.added[0].html, page.rows.added[0].number.textContent, page.rows.attrs[ 'data-next' ] ] );
page.listeners[ 'add:click' ]();
check( 'at the limit the button goes away', page.rows.count === 5 && page.add.hidden === true );
page.listeners[ 'add:click' ]();
check( 'and a further click adds nothing', page.rows.count === 5 );
check( 'without fetch the page still works: no lookup is wired', page.listeners[ 'form:input' ] === undefined );

console.log( '\n' + ( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' ) );
process.exit( failures ? 1 : 0 );
