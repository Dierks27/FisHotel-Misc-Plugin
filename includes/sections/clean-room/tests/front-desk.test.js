/**
 * Front Desk JS — the pure client helpers: number-word normalisation (the
 * "Heard:" line), the tank-number phrases, the preview sentence builder
 * that mirrors the server's Parser::summary(), a group's sale state in
 * words (saleSummary), its size beside its name (sizeText), and the name
 * of an unknown fish (unknownName — against fixtures/unknown-names.json,
 * which the server's Identify::unknown_name() reads too), a dose phrase
 * in plain words (against fixtures/dose-phrases.json, which the server's
 * Parser::summary() reads too) and the Dose sheet's own pure parts: what
 * it starts with (doseStart), the rate chips (rateChoices, pickChoice)
 * and what Save posts (doseFields). The sheet itself is run in
 * front-desk-dose.test.js; the sums are dose-math.test.js.
 *
 * Runs js/front-desk.js without a DOM or config: the file exports its
 * helpers and skips the app when there is no document / window.fhFrontDesk.
 *
 * Run from the plugin root:  node includes/sections/clean-room/tests/front-desk.test.js
 */
'use strict';

var path    = require( 'path' );
var fs      = require( 'fs' );
var helpers = require( path.join( __dirname, '..', 'js', 'front-desk.js' ) );
var names   = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'unknown-names.json' ), 'utf8' ) );
var phrases = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'dose-phrases.json' ), 'utf8' ) );

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
check( 'helpers are exported for node', helpers && typeof helpers.normalizeNumberWords === 'function' && typeof helpers.previewSentence === 'function' && typeof helpers.tankValue === 'function' && typeof helpers.englishValue === 'function' && typeof helpers.saleSummary === 'function' );
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

console.log( '6. saleSummary()' );
var sale = helpers.saleSummary( { saleReadyDate: '2026-10-25', saleSource: 'batch', saleSuggested: '2026-10-29', readyInSpa: false } );
check( 'a batch date: "on sale <date> · from the batch", no warning', 'on sale 2026-10-25 · from the batch' === sale.text && 'on sale 2026-10-25' === sale.short && '' === sale.warning );
check( 'an own date: "· own date"', 'on sale 2026-11-02 · own date' === helpers.saleSummary( { saleReadyDate: '2026-11-02', saleSource: 'own' } ).text );
sale = helpers.saleSummary( { saleReadyDate: '', saleSuggested: '2026-10-29' } );
check( 'no date: "Sale date not set · suggested <date>" (the suggestion is only a hint)', 'Sale date not set · suggested 2026-10-29' === sale.text && 'Sale date not set' === sale.short );
check( 'no date and no check-in: no suggestion', 'Sale date not set' === helpers.saleSummary( {} ).text && 'Sale date not set' === helpers.saleSummary( null ).text );
check( 'for sale while still in the Spa: the warning', 'still in the Spa' === helpers.saleSummary( { saleReadyDate: '2026-10-15', saleSource: 'batch', readyInSpa: true } ).warning );
check( 'the page\'s translated strings are used when given', 'ON SALE 2026-10-25 · BATCH' === helpers.saleSummary( { saleReadyDate: '2026-10-25', saleSource: 'batch' }, { readyOn: 'ON SALE %s', saleBatch: 'BATCH' } ).text );

console.log( '7. sizeText()' );
check( 'a group with a standard size: its label', '2-3"' === helpers.sizeText( { size: '2-3"', sizeStandard: true } ) );
check( 'a size that is no longer in the list is flagged', 'Jumbo 8" (not a standard size)' === helpers.sizeText( { size: 'Jumbo 8"', sizeStandard: false } ) && 'Jumbo 8" (KEINE)' === helpers.sizeText( { size: 'Jumbo 8"', sizeStandard: false }, { notStandard: 'KEINE' } ) );
check( 'no size: nothing', '' === helpers.sizeText( { size: '' } ) && '' === helpers.sizeText( {} ) && '' === helpers.sizeText( null ) );

console.log( '8. unknownName(): "Unknown fish (??)"' );
names.unknown.forEach( function ( c ) {
	check( 'unknownName(' + JSON.stringify( c[0] ) + ') → ' + JSON.stringify( c[1] ), c[1] === helpers.unknownName( c[0] ), helpers.unknownName( c[0] ) );
} );
check( 'junk is no description; the name fits the column (120)', '??' === helpers.unknownName( { a: 1 } ) && '??' === helpers.unknownName( false ) && '??' === helpers.unknownName() && 120 === helpers.unknownName( new Array( 301 ).join( 'x' ) ).length );
check( 'an unknown arrival reads like any other in the preview sentence', '2 × ?? small wrasse arriving in Tank 521 today' === helpers.previewSentence( { intent: 'arrival', qty: 2, tank: 521, species: { name: helpers.unknownName( 'small wrasse' ) }, needs: [] } ) );

console.log( '9. A dose phrase in plain words (the fixture the server reads too)' );
phrases.sentences.forEach( function ( pair ) {
	check( pair[1], pair[1] === helpers.previewSentence( pair[0] ), helpers.previewSentence( pair[0] ) );
} );

console.log( '10. The Dose sheet\'s pure parts' );
check( 'the dose helpers are exported', [ 'doseStart', 'rateChoices', 'pickChoice', 'doseFields' ].every( function ( k ) { return typeof helpers[ k ] === 'function'; } ) );
var copper = { key: 'copper', label: 'Copper Power', unit: 'ml', mode: 'to_level', rate: 0.692, per: 1, levelUnit: 'ppm', copper: true, doses: [] };
var quine  = { key: 'quine', label: 'Test Quine', unit: 'mg', mode: 'per_gallon', rate: 10, per: 1, doses: [ { label: 'Loading', rate: 40 }, { label: 'Top-up', rate: 12 } ] };
var plain  = { key: 'metro', label: 'Metronidazole', unit: 'mg', mode: 'per_gallon', rate: 25, per: 1, doses: [] };
var water  = { gallons: 100, reading: { value: 2.16, at: '09:14' }, meds: { copper: { now: 2.16, nowAt: '09:14', target: 2.4, targetFrom: 'schedule', rate: null, rateFrom: '', options: [ { value: 2.4, label: 'Copper Power to 2.4', day: 2 } ] }, quine: { now: null, nowAt: '', target: null, targetFrom: '', rate: 40, rateFrom: 'schedule', options: [ { value: 40, label: 'Test Quine 40 mg/gal', day: 3 } ] } } };
var start  = helpers.doseStart( copper, water );
check( 'doseStart: the levels as text for the fields, their source, the due steps', '2.16' === start.now && '09:14' === start.nowAt && '2.4' === start.target && 'schedule' === start.targetFrom && 1 === start.options.length, JSON.stringify( start ) );
start = helpers.doseStart( quine, water );
check( '…a per-gallon medication: the due step\'s rate, no levels', 40 === start.rate && 'schedule' === start.rateFrom && '' === start.now && '' === start.target );
start = helpers.doseStart( plain, water );
check( '…one the water says nothing about: its own rate', 25 === start.rate && '' === start.rateFrom && 0 === start.options.length );
start = helpers.doseStart( copper, { gallons: 40, reading: null, meds: { copper: { now: 0, nowAt: '08:00', target: null } } } );
check( '…a reading of 0 is a reading; a missing target is empty', '0' === start.now && '' === start.target );
check( '…no medication, no water: nothing, never a throw', null === helpers.doseStart( null, null ).rate && '' === helpers.doseStart( copper, null ).now && '' === helpers.doseStart( copper, {} ).target );
var choices = helpers.rateChoices( quine, [ { value: 40, label: 'Test Quine 40 mg/gal', day: 3 } ] );
check( 'rateChoices: its own rate, then the named doses; a due step at a named dose\'s rate marks that one', 3 === choices.length && '10 mg per gallon' === choices[0].text && '' === choices[0].name && ! choices[0].due && 'Loading · 40 mg per gallon' === choices[1].text && 'Loading' === choices[1].name && true === choices[1].due && 'Test Quine 40 mg/gal' === choices[1].step && ! choices[2].due, JSON.stringify( choices ) );
choices = helpers.rateChoices( quine, [ { value: 20, label: 'Test Quine 20 mg/gal', day: 5 }, { value: 10, label: 'Test Quine 10 mg/gal', day: 9 } ] );
check( '…a due rate that is none of them gets its own chip; one that is the medication\'s own marks it', 4 === choices.length && 20 === choices[3].rate && '20 mg per gallon' === choices[3].text && true === choices[3].due && '' === choices[3].name && true === choices[0].due );
check( '…a medication with one rate: one choice; one not dosed per gallon: none', 1 === helpers.rateChoices( plain, [] ).length && 0 === helpers.rateChoices( copper, [] ).length && 0 === helpers.rateChoices( null, [] ).length );
check( '…a rate for 10 gallons reads so', '1 ml per 10 gal' === helpers.rateChoices( { mode: 'per_gallon', unit: 'ml', rate: 1, per: 10, doses: [] }, [] )[0].text );
choices = helpers.rateChoices( quine, [ { value: 40, label: 'x', day: 3 } ] );
check( 'pickChoice: a rate picks the named dose at it; a name picks that one; nothing known picks the medication\'s own', 'Loading' === helpers.pickChoice( choices, 40, '' ).name && 'Top-up' === helpers.pickChoice( choices, 12, 'Top-up' ).name && '' === helpers.pickChoice( choices, 10, '' ).name && 10 === helpers.pickChoice( choices, 99, '' ).rate && 10 === helpers.pickChoice( choices, null, '' ).rate && null === helpers.pickChoice( [], 10, '' ) );
var fields = helpers.doseFields( copper, { amount: '16.6', unit: 'ml', whole: true, now: '2.16', target: '2.4' }, { state: 'ok' } );
check( 'doseFields, to a level: the amount as it stands, the water, calc with now and target', JSON.stringify( { medication: 'copper', amount: '16.6', unit: 'ml', whole: 1, calc: 1, now: '2.16', target: '2.4' } ) === JSON.stringify( fields ), JSON.stringify( fields ) );
check( '…"nothing to add" and unknown gallons still carry the readings; a missing reading carries nothing', 1 === helpers.doseFields( copper, { amount: '4', unit: 'ml', now: '2.5', target: '2.4' }, { state: 'nothing' } ).calc && 1 === helpers.doseFields( copper, { amount: '4', unit: 'ml', now: '2.5', target: '2.4' }, { state: 'no_gallons' } ).calc && ! ( 'calc' in helpers.doseFields( copper, { amount: '4', unit: 'ml', now: '', target: '2.4' }, { state: 'needs_now' } ) ) && ! ( 'now' in helpers.doseFields( copper, { amount: '4', unit: 'ml' }, null ) ) );
fields = helpers.doseFields( quine, { amount: '4', unit: 'g', whole: false, rate: 40, rateLabel: 'Loading', topup: true, changed: '25', changedUnit: 'pct' }, { state: 'ok' } );
check( 'doseFields, per gallon: the rate, the named dose, the top-up', JSON.stringify( { medication: 'quine', amount: '4', unit: 'g', whole: 0, calc: 1, rate: 40, dose_label: 'Loading', topup: 1, changed: '25', changed_unit: 'pct' } ) === JSON.stringify( fields ), JSON.stringify( fields ) );
check( '…no sum (unknown gallons): a plain dose; a top-up without its number says only that it was one', ! ( 'calc' in helpers.doseFields( plain, { amount: '2', unit: 'g', whole: true, rate: 25 }, { state: 'no_gallons' } ) ) && 1 === helpers.doseFields( plain, { amount: '2', unit: 'g', topup: true, changed: '' }, { state: 'needs_changed' } ).topup && ! ( 'changed' in helpers.doseFields( plain, { amount: '2', unit: 'g', topup: true, changed: '' }, { state: 'needs_changed' } ) ) && 'gal' === helpers.doseFields( plain, { amount: '2', unit: 'g', topup: true, changed: '30', changedUnit: 'gal' }, { state: 'ok' } ).changed_unit );
check( '…a typed medication: never a sum', ! ( 'calc' in helpers.doseFields( { key: 'salt', mode: 'typed' }, { amount: '6', unit: 'tsp', whole: true }, { state: 'typed' } ) ) );

console.log( '' );
console.log( failures ? failures + ' of ' + checks + ' checks FAILED' : 'All ' + checks + ' checks passed' );
process.exit( failures ? 1 : 0 );
