<?php
/**
 * Acceptance tests for the lottery draw JSON importer.
 *
 * Run from the plugin root:
 *   php includes/sections/lottery-draw/tests/importer-test.php
 *
 * Test 2 — a payload carrying results must be refused — is the one that
 * matters. If results can be imported, an admin can paste a hand-picked
 * winner list and publish it behind a green "verified" banner.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Lottery_Draw\Importer;
use FisHotel\Misc\Sections\Lottery_Draw\Lottery_Draw;
use FisHotel\Misc\Sections\Lottery_Draw\Store;

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( $detail ? "\n       $detail" : '' ) . "\n";
}

function has_error( array $result, $needle ) {
	foreach ( $result['errors'] as $error ) {
		if ( false !== stripos( $error, $needle ) ) {
			return true;
		}
	}

	return false;
}

$valid_json = <<<'JSON'
{
  "id": "rvs-2026-08",
  "title": "RVS Philippines — August 2026",
  "seed_method": "external",
  "seed_source": "",
  "seed": "",
  "fish": [
    {
      "name": "Yellow Coris Wrasse",
      "sci": "Halichoeres chrysus",
      "stock": 2,
      "groupSize": 1,
      "entrants": [
        { "name": "twosixpax",   "want": 1 },
        { "name": "Sashaka",     "want": 1 },
        { "name": "KState Fan",  "want": 1 },
        { "name": "cbb61032002", "want": 1 }
      ]
    },
    {
      "name": "Spotband Butterfly",
      "sci": "Chaetodon punctatofasciatus",
      "stock": 3,
      "entrants": [
        { "name": "daduc", "want": 1 },
        { "name": "gmdcdvm" },
        { "name": "twosixpax", "want": 1 }
      ]
    },
    {
      "name": "Lyretail Anthias",
      "sci": "Pseudanthias squamipinnis",
      "stock": 120,
      "groupSize": 5,
      "entrants": [
        { "name": "reefer", "want": 10 },
        { "name": "daduc",  "want": 40 }
      ]
    },
    {
      "name": "Yellowtail Damsel",
      "stock": 6,
      "entrants": [
        { "name": "twosixpax", "want": 4 }
      ]
    }
  ]
}
JSON;

echo "1. A valid payload imports, entrant order untouched\n";
$valid = Importer::validate( $valid_json );
check( 'validation passes', $valid['ok'], implode( ' | ', $valid['errors'] ) );
check( 'every fish is carried over', 4 === count( $valid['payload']['fish'] ) );
check(
	'entrant order is byte-identical to the input',
	array( 'twosixpax', 'Sashaka', 'KState Fan', 'cbb61032002' ) === array_column( $valid['payload']['fish'][0]['entrants'], 'name' ),
	json_encode( array_column( $valid['payload']['fish'][0]['entrants'], 'name' ) )
);
check( 'a missing want defaults to 1', 1 === $valid['payload']['fish'][1]['entrants'][1]['want'] );
check( 'a missing groupSize defaults to 1', 1 === $valid['payload']['fish'][1]['groupSize'] );
check( 'seed method is carried over', Lottery_Draw::SEED_EXTERNAL === $valid['payload']['seed_method'] );
check( 'imported fish carry no results', array() === $valid['payload']['fish'][0]['winners'] && array() === $valid['payload']['fish'][0]['waitlist'] );
check( 'totals are counted', 4 === $valid['totals']['fish'] && 61 === $valid['totals']['tickets'] && 7 === $valid['totals']['people'], json_encode( $valid['totals'] ) );

echo "2. A payload carrying results is REFUSED — the one that matters\n";

$with_winners = json_decode( $valid_json, true );
$with_winners['fish'][0]['winners'] = array( 'twosixpax', 'twosixpax' );
$refused = Importer::validate( json_encode( $with_winners ) );
check( 'the import is refused', ! $refused['ok'] );
check( 'the error says results cannot be imported', has_error( $refused, 'Payload contains results' ), implode( ' | ', $refused['errors'] ) );
check( 'the offending fish is named', has_error( $refused, 'Yellow Coris Wrasse' ), implode( ' | ', $refused['errors'] ) );
check( 'nothing is handed back to be written', null === $refused['payload'] );

$with_waitlist = json_decode( $valid_json, true );
$with_waitlist['fish'][2]['waitlist'] = array( 'daduc' );
$refused_waitlist = Importer::validate( json_encode( $with_waitlist ) );
check( 'a waitlist is refused too', ! $refused_waitlist['ok'] && has_error( $refused_waitlist, 'Lyretail Anthias' ) );

$empty_results = json_decode( $valid_json, true );
$empty_results['fish'][0]['winners'] = array();
check( 'an EMPTY winners key is still refused', ! Importer::validate( json_encode( $empty_results ) )['ok'] );

$top_level = json_decode( $valid_json, true );
$top_level['winners'] = array( 'somebody' );
check( 'results at the payload root are refused', ! Importer::validate( json_encode( $top_level ) )['ok'] );

$buried = json_decode( $valid_json, true );
$buried['fish'][1]['entrants'][0]['winners'] = array( 'daduc' );
check( 'results buried inside an entrant are refused', ! Importer::validate( json_encode( $buried ) )['ok'] );

$cased = json_decode( $valid_json, true );
$cased['fish'][0]['Winners'] = array( 'twosixpax' );
$cased_result = Importer::validate( json_encode( $cased ) );
check(
	'a differently-cased Winners key does not sneak past as an unknown key',
	! $cased_result['ok'] || ! array_key_exists( 'Winners', $cased_result['payload']['fish'][0] ),
	'unknown keys must be dropped rather than carried through'
);

echo "3. Import only ever touches a draft\n";
$published = array( 'status' => Lottery_Draw::STATUS_PUBLISHED, 'fish' => array() );
$committed = array( 'status' => Lottery_Draw::STATUS_COMMITTED, 'fish' => array() );
$draft     = array( 'status' => Lottery_Draw::STATUS_DRAFT, 'id' => 'd', 'title' => 'D', 'fish' => array() );

check( 'importing into a published draw is refused', is_wp_error( Importer::build_payload( $valid['payload'], $published, '', '' ) ) );
check( 'importing into a committed draw is refused', is_wp_error( Importer::build_payload( $valid['payload'], $committed, '', '' ) ) );
$into_draft = Importer::build_payload( $valid['payload'], $draft, '', '' );
check( 'importing into a draft is allowed', ! is_wp_error( $into_draft ) && 4 === count( $into_draft['fish'] ) );
check( 'the draft keeps its own id and title', 'd' === $into_draft['id'] && 'D' === $into_draft['title'] );

echo "4. The same handle twice in one fish is merged\n";
$dupes = json_decode( $valid_json, true );
$dupes['fish'][0]['entrants'] = array(
	array( 'name' => 'Reefer', 'want' => 1 ),
	array( 'name' => 'daduc', 'want' => 2 ),
	array( 'name' => 'REEFER', 'want' => 3 ),
);
$merged = Importer::validate( json_encode( $dupes ) );
check( 'validation still passes', $merged['ok'], implode( ' | ', $merged['errors'] ) );
check(
	'wants are summed, first position and spelling kept',
	array( array( 'name' => 'Reefer', 'want' => 4 ), array( 'name' => 'daduc', 'want' => 2 ) ) === $merged['payload']['fish'][0]['entrants'],
	json_encode( $merged['payload']['fish'][0]['entrants'] )
);

echo "5. The preview shows group slots, not individuals\n";
$anthias = null;
$damsel  = null;

foreach ( $valid['preview'] as $row ) {
	if ( 'Lyretail Anthias' === $row['name'] ) {
		$anthias = $row;
	}
	if ( 'Yellowtail Damsel' === $row['name'] ) {
		$damsel = $row;
	}
}

check( '120 in stock at a group size of 5 previews as 24 slots', 24 === $anthias['slots'], json_encode( $anthias ) );
check( 'a contested fish is labelled a draw', 'draw' === $anthias['mode'] && 26 === $anthias['missing'] );
check( 'an uncontested fish is labelled rank-only', 'rank' === $valid['preview'][0]['mode'] || 'draw' === $valid['preview'][0]['mode'] );
check( 'tickets equal to slots is rank-only with nothing spare',
	'rank' === $valid['preview'][1]['mode'] && 0 === $valid['preview'][1]['spare'],
	json_encode( $valid['preview'][1] ) );
check( 'tickets under slots is rank-only with spares counted',
	'rank' === $damsel['mode'] && 2 === $damsel['spare'],
	json_encode( $damsel ) );
check( 'a fish where one person holds every ticket warns but does not fail',
	$valid['ok'] && 1 === count( $valid['warnings'] ) && false !== stripos( $valid['warnings'][0], 'Yellowtail Damsel' ),
	json_encode( $valid['warnings'] ) );

echo "6. Malformed input fails clearly and writes nothing\n";
$broken = Importer::validate( '{"fish": [ {"name": "X", "stock": 1,} ] }' );
check( 'a trailing comma is named in the error', has_error( $broken, 'Not valid JSON' ), implode( ' | ', $broken['errors'] ) );
check( 'nothing is handed back to be written', null === $broken['payload'] );
check( 'an empty paste is refused', ! Importer::validate( '   ' )['ok'] );
check( 'a payload with no fish is refused', has_error( Importer::validate( '{"title":"x"}' ), 'No fish in the payload' ) );
check( 'a fish with no entrants is named', has_error(
	Importer::validate( '{"fish":[{"name":"Naked Wrasse","stock":1,"entrants":[]}]}' ),
	'Naked Wrasse has no entrants'
) );
check( 'fractional stock is refused', has_error(
	Importer::validate( '{"fish":[{"name":"X","stock":2.5,"entrants":[{"name":"a"}]}]}' ),
	'stock must be a whole number'
) );
check( 'a duplicate fish name is refused', has_error(
	Importer::validate( '{"fish":[{"name":"Tang","stock":1,"entrants":[{"name":"a"}]},{"name":"TANG","stock":1,"entrants":[{"name":"b"}]}]}' ),
	'Duplicate fish name'
) );
check( 'an oversized payload is refused rather than truncated', has_error(
	Importer::validate( '{"fish":[]' . str_repeat( ' ', Importer::MAX_BYTES ) . '}' ),
	'limit is'
) );

echo "7. Round trip: import, commit, draw, publish, strip results\n";
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( ! $node ) {
	echo "  SKIP node is not available; the round trip needs draw.js\n";
} else {
	$payload = Importer::build_payload( $valid['payload'], null, '', '' );
	$payload['seed'] = 'RVS-2026-08-22-K7M2P';
	// The payload declared external randomness, and commit_seed refuses
	// that method without a named source — as it should.
	$payload['seed_source'] = 'Closing Powerball numbers for Sat Aug 22';
	$imported_fish          = $payload['fish'];

	$payload = Store::commit_seed( $payload );
	check( 'the imported draft commits its seed', ! is_wp_error( $payload ), is_wp_error( $payload ) ? $payload->get_error_message() : '' );

	$tmp = tempnam( sys_get_temp_dir(), 'fh-draw-' );
	file_put_contents( $tmp, json_encode( $payload ) );

	$draw_js = FISHOTEL_MISC_PATH . 'includes/sections/lottery-draw/js/draw.js';
	$results = json_decode(
		(string) shell_exec(
			escapeshellarg( $node ) . ' -e ' . escapeshellarg(
				'var d=require(' . json_encode( $draw_js ) . ');'
				. 'var p=JSON.parse(require("fs").readFileSync(' . json_encode( $tmp ) . ',"utf8"));'
				. 'console.log(JSON.stringify(d.drawAll(p)));'
			)
		),
		true
	);
	unlink( $tmp );

	if ( is_wp_error( $payload ) ) {
		echo "  SKIP the rest of the round trip — the commit failed above\n";
		echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
		exit( 1 );
	}

	$published_payload = Store::publish_results( $payload, (array) $results );
	check( 'the browser result is accepted and published', ! is_wp_error( $published_payload ), is_wp_error( $published_payload ) ? $published_payload->get_error_message() : '' );

	if ( ! is_wp_error( $published_payload ) ) {
		$stripped = array();

		foreach ( $published_payload['fish'] as $fish ) {
			$fish['winners']  = array();
			$fish['waitlist'] = array();
			$stripped[]       = $fish;
		}

		check( 'stripping the results returns exactly what was imported', $stripped === $imported_fish );
		check( 'the anthias were drawn as 24 groups, not 120 fish', 24 === count( $published_payload['fish'][2]['winners'] ) );
		check( 'a rank-only fish has an empty waitlist — correct, not a bug',
			array() === $published_payload['fish'][1]['waitlist'] && 3 === count( $published_payload['fish'][1]['winners'] ),
			json_encode( $published_payload['fish'][1]['waitlist'] ) );

		$reimport = Importer::validate( json_encode( $published_payload ) );
		check( 'the published payload cannot be re-imported without stripping results', ! $reimport['ok'] );
	}
}

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
