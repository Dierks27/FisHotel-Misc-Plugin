<?php
/**
 * Parser — every verb family, number words, tank references (including
 * the building / level / position phrasing), species fuzzy matching with
 * aliases winning, ambiguity → chips, the implied tank, Spa-only
 * arrivals, copper readings, and the plain-words summary.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/parser-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Parser;

function v( $text, $kind ) {
	return array(
		'text' => $text,
		'kind' => $kind,
	);
}

$tanks   = fh_seed_tanks();
$species = array(
	array( 'product_id' => 12, 'name' => 'Pajama Cardinalfish', 'variants' => array( v( 'Pajama Cardinalfish', 'title' ), v( 'Sphaeramia nematoptera', 'sci' ), v( 'Spotted Cardinalfish', 'common' ), v( 'pj cardinal', 'alias' ) ) ),
	array( 'product_id' => 13, 'name' => 'Ocellaris Clownfish — Tank Raised', 'variants' => array( v( 'Ocellaris Clownfish — Tank Raised', 'title' ), v( 'Amphiprion ocellaris', 'sci' ), v( 'False Percula Clownfish', 'common' ) ) ),
	array( 'product_id' => 14, 'name' => 'Percula Clownfish', 'variants' => array( v( 'Percula Clownfish', 'title' ), v( 'Amphiprion percula', 'sci' ), v( 'True Percula', 'common' ) ) ),
	array( 'product_id' => 15, 'name' => 'Six Line Wrasse', 'variants' => array( v( 'Six Line Wrasse', 'title' ), v( 'Pseudocheilinus hexataenia', 'sci' ), v( 'Sixline Wrasse', 'common' ) ) ),
	array( 'product_id' => 16, 'name' => 'Banggai Cardinalfish', 'variants' => array( v( 'Banggai Cardinalfish', 'title' ), v( 'Pterapogon kauderni', 'sci' ), v( "Kaudern's Cardinal", 'common' ) ) ),
	array( 'product_id' => 17, 'name' => 'Yellow Tang', 'variants' => array( v( 'Yellow Tang', 'title' ), v( 'Zebrasoma flavescens', 'sci' ) ) ),
	array( 'product_id' => 18, 'name' => 'Yellow Clown Goby', 'variants' => array( v( 'Yellow Clown Goby', 'title' ), v( 'Gobiodon okinawae', 'sci' ) ) ),
);
$occupants = array(
	array( 'stay_id' => 7, 'species' => 'Pajama Cardinalfish', 'product_id' => 12, 'qty_live' => 4 ),
	array( 'stay_id' => 8, 'species' => 'Ocellaris Clownfish', 'product_id' => 13, 'qty_live' => 2 ),
);
$ctx = array(
	'tank'      => 521,
	'tanks'     => $tanks,
	'species'   => $species,
	'occupants' => $occupants,
);

function p( $text, array $over = array() ) {
	global $ctx;
	return Parser::parse( $text, array_merge( $ctx, $over ) );
}

function chips( array $r ) {
	return implode( '|', array_column( $r['candidates'], 'name' ) );
}

echo "1. Arrivals (always into a Spa tank)\n";
$r = p( 'four pajama cardinalfish in tank 521' );
check( 'intent, qty, tank, species', 'arrival' === $r['intent'] && 4 === $r['qty'] && 521 === $r['tank'] && $r['tank_spoken'] && 12 === $r['species']['product_id'] && $r['species']['confidence'] >= 0.75 && array() === $r['needs'], json_encode( $r['needs'] ) );
check( 'the summary', '4 × Pajama Cardinalfish arriving in Tank 521 today' === $r['summary'], $r['summary'] );
check( 'tank_area is spa', 'spa' === $r['tank_area'] );
$r = p( 'four pajama cardinalfish in tank 121' );
check( 'a Clean Room tank is not a check-in tank: needs a (Spa) tank', 121 === $r['tank'] && array( 'tank' ) === $r['needs'] && 'clean' === $r['tank_area'] );
check( '…and the summary asks for one', '4 × Pajama Cardinalfish arriving in which Spa tank? today' === $r['summary'], $r['summary'] );
$r = p( 'four pajama cardinalfish', array( 'tank' => 121 ) );
check( 'the page\'s tank is implied — and refused for an arrival when it is a Clean Room tank', 121 === $r['tank'] && ! $r['tank_spoken'] && array( 'tank' ) === $r['needs'] );
$r = p( 'put a dozen banggai cardinals into 522' );
check( '"put … into": arrival, a dozen = 12, tank 522, Banggai', 'arrival' === $r['intent'] && 12 === $r['qty'] && 522 === $r['tank'] && 16 === $r['species']['product_id'] && array() === $r['needs'] );
$r = p( 'add four clowns to 523' );
check( '"add … to 523" is an arrival into 523, not a move', 'arrival' === $r['intent'] && 523 === $r['tank'] && null === $r['to_tank'] );
$r = p( 'new yellow tang landed in 524' );
check( '"new / landed"', 'arrival' === $r['intent'] && 524 === $r['tank'] && 17 === $r['species']['product_id'] && 1 === (int) ( $r['qty'] ?? 1 ) );
$r = p( '4 yellow tangs' );
check( 'no verb at all: an assumed arrival with the page\'s (Spa) tank', 'arrival' === $r['intent'] && $r['assumed'] && 4 === $r['qty'] && 521 === $r['tank'] && array() === $r['needs'] );
check( 'a missing qty reads as 1 in the summary', '1 × Yellow Tang arriving in Tank 521 today' === p( 'tiny yellow tang in 521' )['summary'] );

echo "2. Loss — the fastest path\n";
$r = p( 'lost one cardinal in 521' );
check( 'loss, 1, the occupant stay 7', 'loss' === $r['intent'] && 1 === $r['qty'] && 521 === $r['tank'] && 7 === $r['species']['stay_id'] && array() === $r['needs'] );
check( 'summary', '1 × Pajama Cardinalfish lost in Tank 521' === $r['summary'], $r['summary'] );
$r = p( '2 dead in 513', array( 'tank' => 513, 'occupants' => array( $occupants[0] ) ) );
check( '"2 dead in 513" with one occupant: that one', 'loss' === $r['intent'] && 2 === $r['qty'] && 513 === $r['tank'] && 7 === $r['species']['stay_id'] );
$r = p( '2 dead' );
check( '"2 dead" with two occupants asks which fish, chips = the occupants', 'loss' === $r['intent'] && array( 'species' ) === $r['needs'] && 'Pajama Cardinalfish|Ocellaris Clownfish' === chips( $r ) );
check( '…summary', 'Which fish? 2 lost in Tank 521' === $r['summary'], $r['summary'] );
$r = p( 'lost 2 clowns doa' );
check( 'DOA reason', 'loss' === $r['intent'] && 'doa' === $r['reason'] && 8 === $r['species']['stay_id'] && '2 × Ocellaris Clownfish lost in Tank 521 (DOA)' === $r['summary'] );
check( '"dead on arrival"', 'doa' === p( 'one clown dead on arrival' )['reason'] );
$r = p( 'a clownfish died, jumped' );
check( '"a" = 1, reason jumped', 1 === $r['qty'] && 'jumped' === $r['reason'] && 8 === $r['species']['stay_id'] );
check( 'disease words', 'disease' === p( 'lost a clown to ich' )['reason'] && 'disease' === p( 'clown died sick' )['reason'] );
check( 'unknown reason', 'unknown' === p( 'lost one clown cause unknown' )['reason'] && 'unknown' === p( 'lost a clown not sure why' )['reason'] );
check( 'no reason → null', null === p( 'lost one clown' )['reason'] );
$r = p( 'a couple of clowns died' );
check( '"a couple" = 2', 2 === $r['qty'] && 'loss' === $r['intent'] );
$r = p( 'lost one cardinal' );
check( 'the implied tank', 521 === $r['tank'] && ! $r['tank_spoken'] && $r['tank_known'] );

echo "3. Sold / shipped\n";
$r = p( 'shipped 2 cardinals' );
check( 'shipped → sold, 2, stay 7', 'sold' === $r['intent'] && 2 === $r['qty'] && 7 === $r['species']['stay_id'] && '2 × Pajama Cardinalfish shipped from Tank 521' === $r['summary'], $r['summary'] );
check( 'sold / packed', 'sold' === p( 'sold a clown' )['intent'] && 'sold' === p( 'packed 3 pajamas' )['intent'] );

echo "4. Moves — one line, with a quantity = a split\n";
$r = p( 'move two clowns to two twenty one' );
check( '"to two twenty one" → 221, qty 2, occupant stay 8', 'move' === $r['intent'] && 2 === $r['qty'] && 521 === $r['tank'] && 221 === $r['to_tank'] && 8 === $r['species']['stay_id'] && array() === $r['needs'] );
check( 'summary', 'Move 2 × Ocellaris Clownfish from Tank 521 to Tank 221' === $r['summary'], $r['summary'] );
$r = p( 'move 3 cardinals 121 to 221', array( 'tank' => 121, 'occupants' => array( $occupants[0] ) ) );
check( '"move 3 cardinals 121 to 221": from 121 to 221, qty 3', 'move' === $r['intent'] && 3 === $r['qty'] && 121 === $r['tank'] && 221 === $r['to_tank'] && 7 === $r['species']['stay_id'] );
$r = p( 'move all to 222' );
check( '"move all": all, no species needed', 'move' === $r['intent'] && $r['all'] && 222 === $r['to_tank'] && array() === $r['needs'] && 'Move all fish from Tank 521 to Tank 222' === $r['summary'] );
$r = p( 'move the cardinals' );
check( 'no destination → needs to_tank', array( 'to_tank' ) === $r['needs'] && 'Move Pajama Cardinalfish from Tank 521 to which tank?' === $r['summary'], $r['summary'] );
$r = p( 'clowns over to tank 212' );
check( '"over to tank"', 'move' === $r['intent'] && 212 === $r['to_tank'] );
$r = p( 'move clowns from tank 521 to tank 221' );
check( '"from tank … to tank …"', 521 === $r['tank'] && 221 === $r['to_tank'] && 521 === $r['from_tank'] );
$r = p( 'move the cardinals 222' );
check( 'a bare known number after move is the destination', 222 === $r['to_tank'] && 521 === $r['tank'] );
$r = p( 'move 2 pajamas over to tank five two one' );
check( '"tank five two one" → 521 (the act will refuse the same tank)', 521 === $r['to_tank'] && 2 === $r['qty'] );
$r = p( 'move the clowns to tank 999' );
check( 'an unknown tank number needs a tank', in_array( 'to_tank', $r['needs'], true ) && 999 === $r['to_tank'] );

echo "5. Clear, count, note, copper\n";
$r = p( 'clear the cardinals' );
check( 'clear stay 7', 'clear' === $r['intent'] && 7 === $r['species']['stay_id'] && 'Pajama Cardinalfish in Tank 521 cleared today' === $r['summary'] );
check( '"are done" / "graduated"', 'clear' === p( 'the clowns are done' )['intent'] && 'clear' === p( 'graduated the pajamas' )['intent'] );
$r = p( 'count five clowns' );
check( 'count 5, stay 8', 'count' === $r['intent'] && 5 === $r['qty'] && 8 === $r['species']['stay_id'] && 'Count: 5 × Ocellaris Clownfish in Tank 521' === $r['summary'] );
$r = p( 'there are 3 pajamas' );
check( '"there are" is a count; "pajamas" is singularised and matched', 'count' === $r['intent'] && 3 === $r['qty'] && 7 === $r['species']['stay_id'] );
$r = p( 'i count 6 cardinals' );
check( '"i count"', 'count' === $r['intent'] && 6 === $r['qty'] );
$r = p( 'count the clowns' );
check( 'a count without a number needs one', in_array( 'qty', $r['needs'], true ) && 'Count: how many? Ocellaris Clownfish in Tank 521' === $r['summary'], $r['summary'] );
$r = p( 'note water change done' );
check( 'a note goes on the tank: no fish needed, the text kept', 'note' === $r['intent'] && 'water change done' === $r['note'] && array() === $r['needs'] && 'Note on Tank 521: water change done' === $r['summary'], $r['summary'] );
check( '"note:" with punctuation (the text is kept as spoken)', 'skimmer cleaned.' === p( 'Note: skimmer cleaned.' )['note'] );
$r = p( '521 copper 2.4' );
check( '"521 copper 2.4": copper, 2.4, tank 521', 'copper' === $r['intent'] && 2.4 === $r['value'] && 521 === $r['tank'] && null === $r['qty'] && array() === $r['needs'] && 'Copper 2.4 in Tank 521' === $r['summary'], $r['summary'] );
$r = p( 'copper two point four' );
check( '"two point four" with the implied tank', 'copper' === $r['intent'] && 2.4 === $r['value'] && 521 === $r['tank'] && ! $r['tank_spoken'] );
check( '"cu 2" is 2.0', 2.0 === p( 'cu 2' )['value'] && 'Copper 2 in Tank 521' === p( 'cu 2' )['summary'] );
check( '"copper 2.25 in 513"', 2.25 === p( 'copper 2.25 in 513' )['value'] && 513 === p( 'copper 2.25 in 513' )['tank'] );
check( 'copper with no reading needs a value', in_array( 'value', p( 'copper 521' )['needs'], true ) || in_array( 'value', p( 'copper' )['needs'], true ) );
$c1 = array( false, false );
$c2 = array( false, false, false );
$c3 = array( false );
check( 'decimal_value helper', 2.4 === Parser::decimal_value( array( 'copper', '2.4' ), $c1 ) && 2.5 === Parser::decimal_value( array( 'two', 'point', 'five' ), $c2 ) && null === Parser::decimal_value( array( 'two' ), $c3 ) );
check( '…and it marks the tokens it used', array( false, true ) === $c1 && array( true, true, true ) === $c2 && array( false ) === $c3 );

echo "6. Number words\n";
check( 'a dozen', 12 === p( 'a dozen clowns in 523' )['qty'] );
check( 'half a dozen', 6 === p( 'half a dozen pajama cardinals in 523' )['qty'] );
check( 'two dozen', 24 === p( 'two dozen pajama cardinals in 523' )['qty'] );
check( 'twenty two', 22 === p( 'twenty two pajama cardinals in 523' )['qty'] );
check( 'a pair', 2 === p( 'a pair of clowns in 523' )['qty'] );
check( 'digits', 7 === p( '7 clowns in 523' )['qty'] && 7 === p( '7x clowns in 523' )['qty'] );
check( 'one hundred', 100 === p( 'one hundred chromis in 523' )['qty'] );
$r = p( 'four six line wrasse in 522' );
check( '"four six line wrasse": qty 4, the six stays with the fish', 4 === $r['qty'] && 15 === $r['species']['product_id'] && 'six line wrasse' === $r['species']['text'] );
check( 'english_value', 22 === Parser::english_value( array( 20, 2 ) ) && 121 === Parser::english_value( array( 1, 100, 20, 1 ) ) && null === Parser::english_value( array( 4, 6 ) ) && null === Parser::english_value( array( 5, 20, 1 ) ) );

echo "7. Tank references\n";
check( 'tank_value: one twenty one', 121 === Parser::tank_value( array( 'one', 'twenty', 'one' ) ) );
check( 'tank_value: five twenty one', 521 === Parser::tank_value( array( 'five', 'twenty', 'one' ) ) );
check( 'tank_value: five two one', 521 === Parser::tank_value( array( 'five', 'two', 'one' ) ) );
check( 'tank_value: one hundred twenty one', 121 === Parser::tank_value( array( 'one', 'hundred', 'twenty', 'one' ) ) );
check( 'tank_value: one eleven', 111 === Parser::tank_value( array( 'one', 'eleven' ) ) );
check( 'tank_value: 121 / 1 21', 121 === Parser::tank_value( array( '121' ) ) && 121 === Parser::tank_value( array( '1', '21' ) ) );
check( 'tank_value: not a tank', null === Parser::tank_value( array( 'four' ) ) && null === Parser::tank_value( array( 'twenty', 'one' ) ) && null === Parser::tank_value( array( '12' ) ) && null === Parser::tank_value( array( '1234' ) ) );
check( '"tank 121"', 121 === p( 'clear the cardinals in tank 121' )['tank'] );
check( '"one twenty one" bare', 121 === p( 'lost one cardinal one twenty one' )['tank'] );
check( '"five twenty-one" (hyphen)', 521 === p( 'lost one cardinal in five twenty-one' )['tank'] );
check( '"tank five two one"', 521 === p( 'lost one cardinal tank five two one' )['tank'] );
check( '"one hundred and twenty one"', 121 === p( 'lost one cardinal in one hundred and twenty one' )['tank'] );
$r = p( 'move the cardinals to building one top left' );
check( '"building one top left" → 121', 121 === $r['to_tank'] );
check( '"building 100 top left"', 121 === p( 'move the cardinals to building 100 top left' )['to_tank'] );
check( '"building five hundred bottom middle" → 512', 512 === p( 'move the cardinals to building five hundred bottom middle' )['to_tank'] );
check( '"building 600 top left" → 621, "bottom right" of 500 → 513', 621 === p( 'move the clowns to building 600 top left' )['to_tank'] && 513 === p( 'move the clowns to building 500 bottom right' )['to_tank'] );
check( '"top second" / "top third" of 100', 122 === p( 'move the clowns to building 100 top second' )['to_tank'] && 123 === p( 'move the clowns to building 100 top third' )['to_tank'] );
$r = p( 'lost 1 clown in building 200 top middle' );
check( 'no middle in a row of two → needs a tank', in_array( 'tank', $r['needs'], true ) && ! $r['tank_known'] );
$r = p( 'move the clowns to the top right' );
check( '"top right" alone uses the page\'s building (500) → 524', 524 === $r['to_tank'] );
check( 'tank_known honours the list and offline tanks', Parser::tank_known( 121, $tanks ) && ! Parser::tank_known( 125, $tanks ) && ! Parser::tank_known( 121, array( array( 'number' => 121, 'status' => 'offline' ) ) ) && Parser::tank_known( 121, array() ) && ! Parser::tank_known( 130, array() ) );

echo "8. Species: fuzzy, aliases first, sizes ignored, ambiguity → chips\n";
$r = p( 'pj cardinal in 521' );
check( 'an alias is an exact win (1.0)', 12 === $r['species']['product_id'] && 1.0 === $r['species']['confidence'] );
$winner = $species;
$winner[4]['variants'][] = v( 'cardinals', 'alias' );
$r = p( 'four cardinals in 521', array( 'species' => $winner ) );
check( 'an alias beats a title that also matches', 16 === $r['species']['product_id'] && 1.0 === $r['species']['confidence'] );
check( 'typo within Levenshtein 2', 12 === p( 'pajma cardinalfish in 521' )['species']['product_id'] );
check( 'size words are ignored', 17 === p( 'two small yellow tangs in 521' )['species']['product_id'] && 17 === p( 'large yellow tang in 521' )['species']['product_id'] );
check( 'a scientific name', 12 === p( 'two sphaeramia nematoptera in 521' )['species']['product_id'] );
check( 'a common name', 14 === p( 'true percula in 521' )['species']['product_id'] );
check( '"clown fish" joins to clownfish', in_array( p( 'ocellaris clown fish in 521' )['species']['product_id'], array( 13 ), true ) );
$r = p( 'two clowns in 521' );
check( '"clowns" alone is ambiguous: Which fish? with three chips', array( 'species' ) === $r['needs'] && 3 === count( $r['candidates'] ) && false !== strpos( chips( $r ), 'Clownfish' ) );
check( '…summary', 'Which fish? 2 arriving in Tank 521 today' === $r['summary'], $r['summary'] );
$r = p( 'two flurbs in 521' );
check( 'an unknown fish: Which fish?, no chips, the words kept for "use as typed"', array( 'species' ) === $r['needs'] && array() === $r['candidates'] && 'flurbs' === $r['species']['text'] );
$r = p( 'two flurbs in 521', array( 'species' => array() ) );
check( 'with no catalogue at all the typed words are accepted', array() === $r['needs'] && 'Flurbs' === $r['species']['name'] );
$r = p( 'four pajama cardinals' );
check( 'the arrival pool is the catalogue, not the occupants (no stay id)', 12 === $r['species']['product_id'] && null === $r['species']['stay_id'] );
$two_cardinals = array( $occupants[0], array( 'stay_id' => 9, 'species' => 'Banggai Cardinalfish', 'product_id' => 16, 'qty_live' => 3 ) );
$r = p( 'lost one cardinal', array( 'occupants' => $two_cardinals ) );
check( 'two cardinal groups in the tank: Which fish? with both as chips', array( 'species' ) === $r['needs'] && 'Pajama Cardinalfish|Banggai Cardinalfish' === chips( $r ) );
$r = p( 'lost one', array( 'occupants' => array( $occupants[1] ) ) );
check( 'no species words with ONE occupant: that one', 8 === $r['species']['stay_id'] && 1.0 === $r['species']['confidence'] );
check( 'match_species scores best first', 12 === Parser::match_species( array( 'pajama', 'cardinalfish' ), $species )[0]['product_id'] );
check( 'singular()', 'cardinal' === Parser::singular( 'cardinals' ) && 'wrasse' === Parser::singular( 'wrasses' ) && 'goby' === Parser::singular( 'gobies' ) && 'chromis' === Parser::singular( 'chromis' ) && 'tang' === Parser::singular( 'tangs' ) && 'bass' === Parser::singular( 'bass' ) && 'blenny' === Parser::singular( 'blennies' ) && 'box' === Parser::singular( 'boxes' ), Parser::singular( 'wrasses' ) );
check( 'a number word inside a name is kept, as spoken: "four stripe damsels" / "six line wrasse"', 'six line wrasse' === p( 'four six line wrasse in 522' )['species']['text'] && 'four stripe damsels' === p( 'two four stripe damsels in 522' )['species']['text'] && 2 === p( 'two four stripe damsels in 522' )['qty'], p( 'two four stripe damsels in 522' )['species']['text'] );
check( 'token_sim', 1.0 === Parser::token_sim( 'tang', 'tang' ) && 0.85 === Parser::token_sim( 'pajma', 'pajama' ) && 0.7 === Parser::token_sim( 'clown', 'clownfish' ) && 0.0 === Parser::token_sim( 'cat', 'bat' ) );

echo "9. Empty and odd input\n";
$r = p( '' );
check( 'nothing said', 'unknown' === $r['intent'] && array( 'text' ) === $r['needs'] );
$big = array();
for ( $i = 0; $i < 500; $i++ ) {
	$big[] = array( 'product_id' => 100 + $i, 'name' => 'Fish ' . $i . ' Wrasse', 'variants' => array( v( 'Fish ' . $i . ' Wrasse', 'title' ), v( 'Genus species' . $i, 'sci' ), v( 'Common name ' . $i, 'common' ) ) );
}
$long  = trim( str_repeat( 'lovely little blue green striped spotted reef fish ', 7 ) ); // 357 characters, 56 words — a run-on transcript.
$start = microtime( true );
$r     = p( substr( $long, 0, 300 ), array( 'species' => $big ) );
$took  = microtime( true ) - $start;
check( 'a 300-character phrase is cut to 12 species words (matching is words × variants: uncapped it took seconds; "little" is a size word and goes first)', count( explode( ' ', $r['species']['text'] ) ) === Parser::MAX_SPECIES_WORDS && 12 === Parser::MAX_SPECIES_WORDS && 0 === strpos( $r['species']['text'], 'lovely blue green' ), $r['species']['text'] );
check( '…and 500 products take well under two seconds', $took < 2.0, sprintf( '%.2fs', $took ) );
check( 'a normal phrase is untouched by the cap', 'pajama cardinalfish' === p( 'four pajama cardinalfish in tank 521' )['species']['text'] && 12 === p( 'four pajama cardinalfish in tank 521' )['species']['product_id'] );
check( 'tokenize keeps decimals and splits hyphens', array( 'five', 'twenty', 'one', 'copper', '2.4', 'x' ) === Parser::tokenize( 'five twenty-one, copper 2.4. x' ) );
check( 'tokenize "4x" → 4', array( '4', 'clowns' ) === Parser::tokenize( '4x clowns' ) );
check( 'note_text', 'water change' === Parser::note_text( 'note water change' ) && 'copper off' === Parser::note_text( 'Notes: copper off' ) );

fh_finish();
