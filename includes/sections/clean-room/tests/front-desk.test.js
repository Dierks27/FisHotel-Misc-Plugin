/**
 * Front Desk JS — the pure client helpers: number-word normalisation (the
 * "Heard:" line), the tank-number phrases, and the preview sentence
 * builder that mirrors the server's Parser::summary().
 *
 * Runs js/front-desk.js without a DOM or config: the file exports its
 * helpers and skips the app when there is no document / window.fhFrontDesk.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk.test.js
 */
'use strict';

var path    = require( 'path' );
var helpers = require( path.join( __dirname, '..', 'js', 'front-desk.js' ) );

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

var tanks = [ 111, 112, 121, 122, 123, 211, 212, 221, 222, 311, 312, 321, 322, 511, 512, 513, 521, 522, 523, 524, 611, 621 ];

console.log( '1. Exports' );
check( 'helpers are exported for node', helpers && typeof helpers.normalizeNumberWords === 'function' && typeof helpers.previewSentence === 'function' && typeof helpers.tankValue === 'function' && typeof helpers.englishValue === 'function' );
check( 'helpers are also hung on the global for the browser', globalThis.fhFrontDeskHelpers && globalThis.fhFrontDeskHelpers.previewSentence === helpers.previewSentence );

console.log( '2. englishValue() and tankValue()' );
check( 'twenty two → 22', 22 === helpers.englishValue( [ 20, 2 ] ) );
check( 'one hundred twenty one → 121', 121 === helpers.englishValue( [ 1, 100, 20, 1 ] ) );
check( 'four six is not a number', null === helpers.englishValue( [ 4, 6 ] ) );
check( 'five twenty one is not a plain number', null === helpers.englishValue( [ 5, 20, 1 ] ) );
check( 'tankValue: one twenty one', 121 === helpers.tankValue( [ 'one', 'twenty', 'one' ] ) );
check( 'tankValue: five twenty one', 521 === helpers.tankValue( [ 'five', 'twenty', 'one' ] ) );
check( 'tankValue: five two one', 521 === helpers.tankValue( [ 'five', 'two', 'one' ] ) );
check( 'tankValue: one hundred twenty one', 121 === helpers.tankValue( [ 'one', 'hundred', 'twenty', 'one' ] ) );
check( 'tankValue: one eleven', 111 === helpers.tankValue( [ 'one', 'eleven' ] ) );
check( 'tankValue: digits', 121 === helpers.tankValue( [ '121' ] ) && 121 === helpers.tankValue( [ '1', '21' ] ) );
check( 'tankValue: not a tank', null === helpers.tankValue( [ 'four' ] ) && null === helpers.tankValue( [ 'twenty', 'one' ] ) && null === helpers.tankValue( [] ) && null === helpers.tankValue( [ '12' ] ) );

console.log( '3. normalizeNumberWords()' );
check( 'four … one twenty one → 4 … 121', '4 pajama cardinals in 121' === helpers.normalizeNumberWords( 'four pajama cardinals in one twenty one', tanks ) );
check( 'twenty one clowns → 21', '21 clowns' === helpers.normalizeNumberWords( 'twenty one clowns', tanks ) );
check( 'four six line wrasse keeps the six with the fish', '4 6 line wrasse in 522' === helpers.normalizeNumberWords( 'four six line wrasse in 522', tanks ) );
check( 'tank five two one → 521', 'tank 521' === helpers.normalizeNumberWords( 'tank five two one', tanks ) );
check( 'five twenty-one (hyphen) → 521', 'lost 1 cardinal in 521' === helpers.normalizeNumberWords( 'lost one cardinal in five twenty-one', tanks ) );
check( 'one hundred and twenty one → 121', 'move to 121' === helpers.normalizeNumberWords( 'move to one hundred and twenty one', tanks ) );
check( 'an unknown tank-shaped number is not forced into a tank', '4 6' === helpers.normalizeNumberWords( 'four six', tanks ) );
check( 'with no tank list the rule decides', '121' === helpers.normalizeNumberWords( 'one twenty one' ) && '4 6' === helpers.normalizeNumberWords( 'four six' ) );
check( 'a couple / a dozen / two point four are left for the server', 'a couple of clowns' === helpers.normalizeNumberWords( 'a couple of clowns', tanks ) && 'a dozen clowns' === helpers.normalizeNumberWords( 'a dozen clowns', tanks ) && 'copper 2 point 4' === helpers.normalizeNumberWords( 'copper two point four', tanks ) );
check( 'two dozen → 2 dozen', '2 dozen cardinals' === helpers.normalizeNumberWords( 'two dozen cardinals', tanks ) );
check( 'digits pass through, case folded', '2 dead in 513' === helpers.normalizeNumberWords( '2 Dead in 513', tanks ) );
check( 'empty / garbage', '' === helpers.normalizeNumberWords( '' ) && '' === helpers.normalizeNumberWords( null ) );

console.log( '4. previewSentence()' );
var sp = function ( name, stay ) { return { name: name, product_id: 12, stay_id: stay || null, confidence: 0.9 }; };
check( 'arrival', '4 × Pajama Cardinalfish arriving in Tank 521 today' === helpers.previewSentence( { intent: 'arrival', qty: 4, tank: 521, species: sp( 'Pajama Cardinalfish' ), needs: [] } ) );
check( 'arrival without a quantity reads 1', '1 × Yellow Tang arriving in Tank 521 today' === helpers.previewSentence( { intent: 'arrival', qty: null, tank: 521, species: sp( 'Yellow Tang' ), needs: [] } ) );
check( 'arrival into a Clean Room tank asks for a Spa tank', '4 × Pajama Cardinalfish arriving in which Spa tank? today' === helpers.previewSentence( { intent: 'arrival', qty: 4, tank: 121, species: sp( 'Pajama Cardinalfish' ), needs: [ 'tank' ] } ) );
check( 'Which fish?', 'Which fish? 2 arriving in Tank 521 today' === helpers.previewSentence( { intent: 'arrival', qty: 2, tank: 521, species: { name: '', text: 'clowns' }, needs: [ 'species' ] } ) );
check( 'loss', '1 × Pajama Cardinalfish lost in Tank 521' === helpers.previewSentence( { intent: 'loss', qty: 1, tank: 521, species: sp( 'Pajama Cardinalfish', 7 ), needs: [] } ) );
check( 'loss with DOA', '2 × Ocellaris Clownfish lost in Tank 521 (DOA)' === helpers.previewSentence( { intent: 'loss', qty: 2, tank: 521, reason: 'doa', species: sp( 'Ocellaris Clownfish', 8 ), needs: [] } ) );
check( 'loss, which fish', 'Which fish? 2 lost in Tank 513' === helpers.previewSentence( { intent: 'loss', qty: 2, tank: 513, species: { name: '' }, needs: [ 'species' ] } ) );
check( 'shipped', '2 × Pajama Cardinalfish shipped from Tank 521' === helpers.previewSentence( { intent: 'sold', qty: 2, tank: 521, species: sp( 'Pajama Cardinalfish', 7 ), needs: [] } ) );
check( 'move', 'Move 2 × Ocellaris Clownfish from Tank 521 to Tank 221' === helpers.previewSentence( { intent: 'move', qty: 2, tank: 521, to_tank: 221, species: sp( 'Ocellaris Clownfish', 8 ), needs: [] } ) );
check( 'move all', 'Move all fish from Tank 521 to Tank 222' === helpers.previewSentence( { intent: 'move', qty: null, all: true, tank: 521, to_tank: 222, species: { name: '' }, needs: [] } ) );
check( 'move without a destination', 'Move Pajama Cardinalfish from Tank 521 to which tank?' === helpers.previewSentence( { intent: 'move', qty: null, tank: 521, to_tank: null, species: sp( 'Pajama Cardinalfish', 7 ), needs: [ 'to_tank' ] } ) );
check( 'clear', 'Pajama Cardinalfish in Tank 521 cleared today' === helpers.previewSentence( { intent: 'clear', tank: 521, species: sp( 'Pajama Cardinalfish', 7 ), needs: [] } ) );
check( 'count', 'Count: 5 × Ocellaris Clownfish in Tank 521' === helpers.previewSentence( { intent: 'count', qty: 5, tank: 521, species: sp( 'Ocellaris Clownfish', 8 ), needs: [] } ) );
check( 'count without a number', 'Count: how many? Ocellaris Clownfish in Tank 521' === helpers.previewSentence( { intent: 'count', qty: null, tank: 521, species: sp( 'Ocellaris Clownfish', 8 ), needs: [ 'qty' ] } ) );
check( 'note', 'Note on Tank 521: water change done' === helpers.previewSentence( { intent: 'note', tank: 521, note: 'water change done', needs: [] } ) );
check( 'copper', 'Copper 2.4 in Tank 521' === helpers.previewSentence( { intent: 'copper', value: 2.4, tank: 521, needs: [] } ) );
check( 'copper, whole number', 'Copper 2 in Tank 521' === helpers.previewSentence( { intent: 'copper', value: 2, tank: 521, needs: [] } ) );
check( 'copper without a reading', 'Copper how much? in Tank 521' === helpers.previewSentence( { intent: 'copper', value: null, tank: 521, needs: [ 'value' ] } ) );
check( 'no tank at all', 'Which fish? fish arriving in which tank? today' === helpers.previewSentence( { intent: 'arrival', qty: null, tank: null, species: { name: '' }, needs: [ 'tank', 'species' ] } ).replace( 'which Spa tank?', 'which tank?' ) );
check( 'unknown intent → the hint', 0 === helpers.previewSentence( { intent: 'unknown' } ).indexOf( 'Say what happened' ) );
check( 'nothing at all', 0 === helpers.previewSentence().indexOf( 'Say what happened' ) );

console.log( '5. fmt() and escapeHtml()' );
check( 'sequential %s / %d', 'day 12 of 28' === helpers.fmt( 'day %d of %d', 12, 28 ) );
check( 'positional', 'b then a' === helpers.fmt( '%2$s then %1$s', 'a', 'b' ) );
check( 'escapes the five characters', '&lt;b&gt;&amp;&quot;&#039;' === helpers.escapeHtml( '<b>&"\'' ) );
check( 'null → empty', '' === helpers.escapeHtml( null ) );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
