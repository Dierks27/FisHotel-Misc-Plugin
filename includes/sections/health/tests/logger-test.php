<?php
/**
 * Logger — level validation, truncation, redaction, the context cap, the
 * fingerprint, debug gating and the 10-minute repeat dedupe.
 *
 * Run from the plugin root:  php includes/sections/health/tests/logger-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Logger;
use FisHotel\Misc\Sections\Health\Tests\Fake_Store;

$t     = strtotime( '2026-04-01 21:00:00 UTC' );
$clock = static function () use ( &$t ) {
	return $t;
};

function fh_logger( Fake_Store $store, array $settings, $clock ) {
	return new Logger( $store, $settings, $clock );
}

echo "1. Level validation\n";
$store  = new Fake_Store();
$logger = fh_logger( $store, array(), $clock );
$logger->log( 'bogus', 'test', 'unknown level' );
check( 'unknown level → info', 'info' === $store->last()['level'] );
$logger->log( 'ERROR', 'test', 'upper-case level' );
check( 'levels are case-insensitive', 'error' === $store->last()['level'] );
$logger->log( ' critical ', 'test', 'padded level' );
check( 'levels are trimmed', 'critical' === $store->last()['level'] );
check( 'normalize_level() alone', 'info' === Logger::normalize_level( null ) && 'warning' === Logger::normalize_level( 'warning' ) );

echo "2. Source and message cleaning\n";
$logger->log( 'info', 'Theme/Packing Slip!', 'x' );
check( 'source keeps letters, digits, _ . / - and replaces the rest', 'Theme/Packing-Slip' === $store->last()['source'] );
$logger->log( 'info', '', 'x' );
check( 'empty source → app', 'app' === $store->last()['source'] );
$logger->log( 'info', str_repeat( 'abcdefghij', 10 ), 'x' );
check( 'source capped at 60', 60 === strlen( $store->last()['source'] ) );
$long = str_repeat( 'é', 3000 );
$logger->log( 'info', 'test', $long );
check( 'message truncated to ≤ 2000 bytes', strlen( $store->last()['message'] ) <= 2000, strlen( $store->last()['message'] ) . ' bytes' );
check( 'truncated message ends with an ellipsis and is valid UTF-8', '…' === mb_substr( $store->last()['message'], -1 ) && false !== mb_check_encoding( $store->last()['message'], 'UTF-8' ) );
$logger->log( 'info', 'test', array( 'a' => 1 ) );
check( 'array message is JSON-encoded, not "Array"', '{"a":1}' === $store->last()['message'] );

echo "3. Redaction of secret keys\n";
$logger->log(
	'error',
	'test',
	'keys',
	array(
		'password'      => 'hunter2',
		'api_token'     => 'abc',
		'Authorization' => 'Bearer x',
		'client_secret' => 'zzz',
		'cookie'        => 'session=1',
		'nonce'         => '1234',
		'order_key'     => 'wc_order_abc',
		'nested'        => array(
			'secret_key' => 'q',
			'fine'       => 'ok',
			'deeper'     => array( 'token' => 't', 'name' => 'n' ),
		),
		'order'         => 123,
		'ok_value'      => 'keep me',
	)
);
$ctx = $store->last()['context'];
check( 'password redacted', '[redacted]' === $ctx['password'] );
check( 'api_token redacted', '[redacted]' === $ctx['api_token'] );
check( 'Authorization redacted (case-insensitive)', '[redacted]' === $ctx['Authorization'] );
check( 'client_secret redacted', '[redacted]' === $ctx['client_secret'] );
check( 'cookie redacted', '[redacted]' === $ctx['cookie'] );
check( 'nonce redacted', '[redacted]' === $ctx['nonce'] );
check( 'order_key redacted (contains "key")', '[redacted]' === $ctx['order_key'] );
check( 'nested secret_key redacted', '[redacted]' === $ctx['nested']['secret_key'] );
check( 'nested fine value kept', 'ok' === $ctx['nested']['fine'] );
check( 'deeper token redacted, sibling kept', '[redacted]' === $ctx['nested']['deeper']['token'] && 'n' === $ctx['nested']['deeper']['name'] );
check( 'int kept', 123 === $ctx['order'] );
check( 'plain value kept', 'keep me' === $ctx['ok_value'] );

echo "4. Redaction of token-shaped strings inside values\n";
$ghp = 'ghp_' . str_repeat( 'A1b2C3d4', 5 );
$pat = 'github_pat_11AAAAAAA0' . str_repeat( 'x', 60 );
$logger->log(
	'error',
	'test',
	"Request with Authorization: Bearer abc.DEF-123_xyz failed; also $ghp and Basic dXNlcjpwYXNzd29yZA==",
	array(
		'note'  => "token was $pat here",
		'url'   => 'https://api.github.com/repos/x?access_token=abcdef&x=1',
		'basic' => 'Authorization: Basic dXNlcjpwYXNz',
		'list'  => array( "bearer: Bearer tok.en" ),
	)
);
$row = $store->last();
check( 'Bearer token scrubbed from the message', false !== strpos( $row['message'], 'Bearer [redacted]' ) && false === strpos( $row['message'], 'abc.DEF-123_xyz' ) );
check( 'ghp_ token scrubbed from the message', false === strpos( $row['message'], 'ghp_' ) );
check( 'Basic auth scrubbed from the message', false !== strpos( $row['message'], 'Basic [redacted]' ) && false === strpos( $row['message'], 'dXNlcjpwYXNzd29yZA' ) );
check( 'github_pat_ scrubbed inside a context value', false === strpos( $row['context']['note'], 'github_pat_' ) && false !== strpos( $row['context']['note'], '[redacted]' ) );
check( 'access_token= query value scrubbed', false === strpos( $row['context']['url'], 'abcdef' ) && false !== strpos( $row['context']['url'], 'x=1' ) );
check( 'Basic blob scrubbed inside a context value', 'Authorization: Basic [redacted]' === $row['context']['basic'] );
check( 'strings inside lists scrubbed too (case-insensitive Bearer)', 'bearer: Bearer [redacted]' === $row['context']['list'][0] );
check( 'the whole stored row carries no secret', false === strpos( json_encode( $row ), 'dXNlcjpwYXNz' ) && false === strpos( json_encode( $row ), 'ghp_' ) );

echo "5. Objects in context\n";
$logger->log( 'error', 'test', 'objects', array( 'e' => new RuntimeException( 'boom Bearer zzz', 7 ), 'err' => new WP_Error( 'code', 'msg' ), 'obj' => new stdClass() ) );
$ctx = $store->last()['context'];
check( 'Throwable becomes class/message/code with the message scrubbed', 'RuntimeException' === $ctx['e']['class'] && 'boom Bearer [redacted]' === $ctx['e']['message'] && 7 === $ctx['e']['code'] );
check( 'WP_Error becomes code + message', 'code' === $ctx['err']['wp_error'] && 'msg' === $ctx['err']['message'] );
check( 'other objects become their class name', '[stdClass]' === $ctx['obj'] );

echo "6. Context size cap\n";
$logger->log( 'error', 'test', 'big', array( 'blob' => str_repeat( 'x', 20000 ), 'small' => 'ok' ) );
$ctx = $store->last()['context'];
check( 'oversized context is reduced under 8 KB', strlen( json_encode( $ctx ) ) <= Logger::CONTEXT_MAX_BYTES, strlen( json_encode( $ctx ) ) . ' bytes' );
check( 'and marked _truncated', ! empty( $ctx['_truncated'] ) );
check( 'small values survive the first pass', 'ok' === $ctx['small'] );
$many = array();
for ( $i = 0; $i < 200; $i++ ) {
	$many[ 'k' . $i ] = str_repeat( 'y', 300 );
}
$logger->log( 'error', 'test', 'many', $many );
$ctx = $store->last()['context'];
check( 'a context too big even after shortening strings is still under 8 KB', strlen( json_encode( $ctx ) ) <= Logger::CONTEXT_MAX_BYTES, strlen( json_encode( $ctx ) ) . ' bytes' );
check( 'and lists the dropped keys', ! empty( $ctx['_truncated'] ) && ! empty( $ctx['_dropped_keys'] ) );
$logger->log( 'error', 'test', 'small', array( 'a' => 'b' ) );
check( 'a small context is stored untouched', array( 'a' => 'b' ) === $store->last()['context'] );

// Multibyte: a byte-wise cut at 200 would split a character, make
// json_encode() return false and let every later measurement read 0 bytes.
// (JSON escapes 'é' as é, six bytes, so 2000 of them is 12 KB.)
$logger->log( 'error', 'test', 'mb', array( 'name' => str_repeat( 'é', 2000 ), 'note' => 'ok' ) );
$ctx = $store->last()['context'];
check( 'a long multibyte string is cut without splitting a character', false !== json_encode( $ctx ) && false !== mb_check_encoding( $ctx['name'], 'UTF-8' ) && '…' === mb_substr( $ctx['name'], -1 ) && strlen( $ctx['name'] ) <= 203, bin2hex( substr( (string) $ctx['name'], -6 ) ) );
check( 'and the shortened context is kept (not demoted to scalars-only)', 'ok' === $ctx['note'] && ! isset( $ctx['_dropped_keys'] ) && ! empty( $ctx['_truncated'] ) );
$many = array();
for ( $i = 0; $i < 200; $i++ ) {
	$many[ 'k' . $i ] = str_repeat( '€', 100 );
}
$logger->log( 'error', 'test', 'many-mb', $many );
$ctx = $store->last()['context'];
check( '200 × 100 "€" is still encodable and under 8 KB', false !== json_encode( $ctx ) && strlen( json_encode( $ctx ) ) <= Logger::CONTEXT_MAX_BYTES, false === json_encode( $ctx ) ? 'json_encode false' : strlen( json_encode( $ctx ) ) . ' bytes' );
check( 'with far fewer than 200 keys kept', count( $ctx ) < 100 && ! empty( $ctx['_dropped_keys'] ) );
$many['bad'] = "\xff" . str_repeat( 'z', 300 ); // one invalid byte from the caller
$logger->log( 'error', 'test', 'invalid', $many );
$ctx  = $store->last()['context'];
$json = json_encode( $ctx, JSON_INVALID_UTF8_SUBSTITUTE );
check( 'a caller-supplied invalid byte cannot defeat the cap (measured as wp_json_encode would store it)', false !== $json && strlen( $json ) <= Logger::CONTEXT_MAX_BYTES, false === $json ? 'false' : strlen( $json ) . ' bytes' );
$logger->log( 'error', 'test', 'invalid-small', array( 'bad' => "ab\xffcd", 'n' => 1 ) );
check( 'a small context with an invalid byte is stored as is (the store substitutes it)', 1 === $store->last()['context']['n'] && "ab\xffcd" === $store->last()['context']['bad'] );

echo "7. Fingerprint\n";
check( 'digits are ignored', Logger::fingerprint( 'error', 'mail', 'order 123 failed' ) === Logger::fingerprint( 'error', 'mail', 'order 456 failed' ) );
check( 'level is part of it', Logger::fingerprint( 'error', 'mail', 'x' ) !== Logger::fingerprint( 'warning', 'mail', 'x' ) );
check( 'source is part of it', Logger::fingerprint( 'error', 'mail', 'x' ) !== Logger::fingerprint( 'error', 'smtp', 'x' ) );
check( 'it is an md5', 1 === preg_match( '/^[a-f0-9]{32}$/', Logger::fingerprint( 'error', 'mail', 'x' ) ) );
$logger->log( 'error', 'fp', 'stored fingerprint' );
check( 'the stored row carries the fingerprint', Logger::fingerprint( 'error', 'fp', 'stored fingerprint' ) === $store->last()['fingerprint'] );

echo "8. Debug gating\n";
$store  = new Fake_Store();
$logger = fh_logger( $store, array( 'store_debug' => false ), $clock );
check( 'debug skipped when store_debug is off', null === $logger->log( 'debug', 'test', 'dbg' ) && 0 === $store->count() );
$logger = fh_logger( $store, array( 'store_debug' => true ), $clock );
$res    = $logger->log( 'debug', 'test', 'dbg' );
check( 'debug stored when store_debug is on', is_array( $res ) && 1 === $store->count() && 'debug' === $store->last()['level'] );

echo "9. Repeat dedupe\n";
$store  = new Fake_Store();
$logger = fh_logger( $store, array(), $clock );
$t      = strtotime( '2026-04-01 21:00:00 UTC' );
$first  = $logger->log( 'error', 'mail', 'order 1 failed' );
check( 'first call inserts', is_array( $first ) && false === $first['repeat'] && 1 === $store->count() );
$t   += 5 * 60;
$again = $logger->log( 'error', 'mail', 'order 2 failed' );
check( 'same fingerprint 5 min later is a repeat of the same id', is_array( $again ) && true === $again['repeat'] && $again['id'] === $first['id'] && 1 === $store->count() );
check( 'repeats counter is 2', 2 === $store->rows[ $first['id'] ]['context']['repeats'] );
check( 'logged_at moved to the repeat time', '2026-04-01 21:05:00' === $store->rows[ $first['id'] ]['logged_at'] );
$t   += 5 * 60;
$logger->log( 'error', 'mail', 'order 3 failed' );
check( 'a third within the window makes 3', 3 === $store->rows[ $first['id'] ]['context']['repeats'] && 1 === $store->count() );
$t   += 10 * 60 + 1;
$new = $logger->log( 'error', 'mail', 'order 4 failed' );
check( 'more than 10 min after the LAST repeat → a new row', is_array( $new ) && false === $new['repeat'] && 2 === $store->count() );
$other = $logger->log( 'warning', 'mail', 'order 4 failed' );
check( 'a different level at the same time → a new row', false === $other['repeat'] && 3 === $store->count() );
check( 'sum_repeats_since() counts the folded events (3 + 1 + 1), count_since() the rows (3)', 5 === $store->sum_repeats_since( '', $t - 86400, 'mail' ) && 3 === $store->count_since( '', $t - 86400, 'mail' ) && 4 === $store->sum_repeats_since( 'error', $t - 86400, 'mail' ) );

echo "10. The after-insert callback\n";
$store  = new Fake_Store();
$logger = fh_logger( $store, array(), $clock );
$seen   = array();
$logger->set_on_insert(
	static function ( array $row ) use ( &$seen ) {
		$seen[] = $row;
	}
);
$logger->log( 'error', 'php', 'fatal 1' );
$logger->log( 'error', 'php', 'fatal 2' );
check( 'called once for the insert, not for the repeat', 1 === count( $seen ) && 'fatal 1' === $seen[0]['message'] && isset( $seen[0]['id'] ) );
$logger->set_on_insert(
	static function () {
		throw new RuntimeException( 'callback blew up' );
	}
);
$res = $logger->log( 'error', 'php', 'fatal after callback' );
check( 'a throwing callback does not break the log call', is_array( $res ) && 2 === $store->count() );

echo "11. Store failures never throw\n";
$store         = new Fake_Store();
$store->poison = true;
$logger        = fh_logger( $store, array(), $clock );
$res           = $logger->log( 'critical', 'php', 'db is gone' );
check( 'a throwing store → null, no exception', null === $res );
$store         = new Fake_Store();
$logger        = fh_logger( $store, array(), $clock );
$logger->log( 'error', 'x', 'fine' );
check( 'now() uses the clock', $t === $logger->now() );

fh_finish();
