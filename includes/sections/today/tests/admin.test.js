/**
 * Today admin JS — the pure helpers: the printf-lite formatter, the
 * "Cached N min ago" label and HTML escaping.
 *
 * Runs js/today-admin.js without jQuery or a DOM: the file exports its
 * helpers and skips the DOM wiring when jQuery is absent.
 *
 * Run from the plugin root:  node includes/sections/today/tests/admin.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'today-admin.js' ) );

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

console.log( '1. Exports' );
check( 'helpers are exported for node', helpers && typeof helpers.fmt === 'function' && typeof helpers.ageLabel === 'function' && typeof helpers.escapeHtml === 'function' );
check( 'helpers are also hung on the global for the browser', globalThis.fhTodayAdmin && globalThis.fhTodayAdmin.helpers === helpers );

console.log( '2. fmt()' );
check( 'sequential %s / %d', 'ship Mon for 6 orders' === helpers.fmt( 'ship %s for %d orders', 'Mon', 6 ) );
check( 'positional', 'b then a' === helpers.fmt( '%2$s then %1$s', 'a', 'b' ) );
check( 'missing args become empty', 'x  y' === helpers.fmt( 'x %s y' ) );
check( 'no template', '' === helpers.fmt() );

console.log( '3. ageLabel()' );
check( 'under a minute, cached', 'Cached just now' === helpers.ageLabel( 12, true ) );
check( 'under a minute, built', 'Built just now' === helpers.ageLabel( 0, false ) );
check( 'minutes', 'Cached 3 min ago' === helpers.ageLabel( 199, true ) );
check( '59 minutes is still minutes', 'Cached 59 min ago' === helpers.ageLabel( 3599, true ) );
check( 'hours', 'Cached 2 h ago' === helpers.ageLabel( 7300, true ) );
check( 'negative / garbage ages read as now', 'Cached just now' === helpers.ageLabel( -40, true ) && 'Cached just now' === helpers.ageLabel( 'soon', true ) );
check( 'i18n strings are used', 'Zwischengespeichert vor 3 Min.' === helpers.ageLabel( 180, true, { cached: 'Zwischengespeichert', minAgo: 'vor %d Min.' } ) );
check( 'i18n prefix for built', 'Erstellt gerade eben' === helpers.ageLabel( 5, false, { built: 'Erstellt', justNow: 'gerade eben' } ) );

console.log( '4. escapeHtml()' );
check( 'escapes the five characters', '&lt;b&gt;&amp;&quot;&#039;' === helpers.escapeHtml( '<b>&"\'' ) );
check( 'null / undefined → empty string', '' === helpers.escapeHtml( null ) && '' === helpers.escapeHtml( undefined ) );
check( 'numbers pass through as text', '98' === helpers.escapeHtml( 98 ) );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
