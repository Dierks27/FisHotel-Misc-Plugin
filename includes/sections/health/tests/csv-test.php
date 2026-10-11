<?php
/**
 * CSV — the cell guard: a leading = + - @ tab or CR gets a single quote so
 * a logged message can never run as a spreadsheet formula.
 *
 * Run from the plugin root:  php includes/sections/health/tests/csv-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Health\Admin;
use FisHotel\Misc\Sections\Health\Store;
use FisHotel\Misc\Sections\Health\Tests\Fake_Store;

echo "1. csv_cell()\n";
check( '=SUM(A1) is prefixed', "'=SUM(A1)" === Admin::csv_cell( '=SUM(A1)' ) );
check( '+1 is prefixed', "'+1" === Admin::csv_cell( '+1' ) );
check( '-1 is prefixed', "'-1" === Admin::csv_cell( '-1' ) );
check( '@cmd is prefixed', "'@cmd" === Admin::csv_cell( '@cmd' ) );
check( 'leading tab is prefixed', "'\t=1" === Admin::csv_cell( "\t=1" ) );
check( 'leading CR is prefixed', "'\r=1" === Admin::csv_cell( "\r=1" ) );
check( 'plain text untouched', 'GitHub returned 401 for api.github.com/x' === Admin::csv_cell( 'GitHub returned 401 for api.github.com/x' ) );
check( 'a = later in the string is fine', 'a=b' === Admin::csv_cell( 'a=b' ) );
check( 'empty stays empty', '' === Admin::csv_cell( '' ) && '' === Admin::csv_cell( null ) );
check( 'numbers become text', '5' === Admin::csv_cell( 5 ) && '0' === Admin::csv_cell( 0 ) );
check( 'a negative number is a formula to a spreadsheet, so it is prefixed too', "'-5" === Admin::csv_cell( -5 ) );
check( 'arrays are JSON-encoded then guarded', '{"a":1}' === Admin::csv_cell( array( 'a' => 1 ) ) );
check( 'a leading space defeats nothing we care about (not prefixed)', ' =1' === Admin::csv_cell( ' =1' ) );

echo "2. csv_row()\n";
$row = Admin::csv_row(
	array(
		'id'        => 7,
		'logged_at' => '2026-04-01 21:00:00',
		'level'     => 'error',
		'source'    => '=evil',
		'message'   => '=HYPERLINK("http://x","click")',
		'repeats'   => 3,
		'context'   => array( 'cmd' => '-rf /', 'note' => 'ok' ),
	)
);
check( 'seven columns', 7 === count( $row ) );
check( 'id, when, level pass through', array( '7', '2026-04-01 21:00:00', 'error' ) === array_slice( $row, 0, 3 ) );
check( 'source and message guarded', "'=evil" === $row[3] && "'=HYPERLINK(\"http://x\",\"click\")" === $row[4] );
check( 'repeats as text', '3' === $row[5] );
check( 'context JSON starts with { so it is not guarded', '{"cmd":"-rf \/","note":"ok"}' === $row[6], $row[6] );
$row = Admin::csv_row( array( 'message' => 'x' ) );
check( 'missing keys → empty cells and repeats 1', '' === $row[0] && '' === $row[1] && '1' === $row[5] && '[]' === $row[6] );

echo "3. Filters from a request\n";
$filters = Admin::filters_from_request( array( 'level' => 'ERROR', 'source' => ' Theme/Packing Slip ', 's' => '  <b>needle</b> ' ) );
check( 'level is lower-cased and validated', 'error' === $filters['level'] );
check( 'source is cleaned like the logger does', 'Theme/Packing-Slip' === $filters['source'] );
check( 'search is a sanitised text field', 'needle' === $filters['search'] );
check( 'unknown level → any', '' === Admin::filters_from_request( array( 'level' => 'fatal' ) )['level'] );
check( 'blank source → any (not "app")', '' === Admin::filters_from_request( array( 'source' => '  ' ) )['source'] );
check( 'nothing → all empty', array( 'level' => '', 'source' => '', 'search' => '' ) === Admin::filters_from_request( null ) );
$sources = Admin::sources_for_filter( array( array( 'source' => 'theme/x' ), array( 'source' => 'php' ) ), 'custom' );
check( 'dropdown sources: known + page + selection, sorted, unique', array( 'custom', 'mail', 'php', 'smtp', 'theme/x', 'updater' ) === $sources, implode( ',', $sources ) );

echo "4. request_args() — the CSV link and pagination carry the search as `s`\n";
$filters = array( 'level' => 'error', 'source' => 'theme/packing-slip', 'search' => 'packing slip' );
$args    = Admin::request_args( $filters );
check( 'search is emitted under the request key s', array( 'level' => 'error', 'source' => 'theme/packing-slip', 's' => 'packing slip' ) === $args, json_encode( $args ) );
check( 'empty filters are left out', array( 's' => 'x' ) === Admin::request_args( array( 'level' => '', 'source' => '', 'search' => 'x' ) ) && array() === Admin::request_args( array() ) );
$url = add_query_arg( array_merge( array( 'action' => 'fishotel_health_csv' ), $args ), admin_url( 'admin-post.php' ) );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );
check( 'round trip: the CSV URL parsed back through filters_from_request() gives the same filters', $filters === Admin::filters_from_request( $parsed ), json_encode( Admin::filters_from_request( $parsed ) ) );
check( 'a zero-like search ("0") survives', array( 's' => '0' ) === Admin::request_args( array( 'search' => '0' ) ) );

echo "5. write_csv() — pages past the store's 500-row clamp up to the cap\n";
$store = new Fake_Store();
for ( $i = 1; $i <= 620; $i++ ) {
	$store->insert(
		array(
			'logged_at'   => gmdate( 'Y-m-d H:i:s', 1800000000 + $i ),
			'level'       => 0 === $i % 10 ? 'warning' : 'error',
			'source'      => 'mail',
			'message'     => "failure $i",
			'context'     => array( 'repeats' => 1 ),
			'fingerprint' => md5( (string) $i ),
		)
	);
}
check( 'the fake store clamps a page to Store::MAX_PER_PAGE like the real one', 500 === Store::MAX_PER_PAGE && 500 === count( $store->query( array(), 1, 5000 )['rows'] ) );
$out = fopen( 'php://memory', 'w+' );
$n   = Admin::write_csv( $out, $store, array( 'level' => '', 'source' => '', 'search' => '' ) );
rewind( $out );
$lines = array_values( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) );
fclose( $out );
check( 'all 620 rows are written, not just the first 500', 620 === $n && 621 === count( $lines ), $n . ' rows, ' . count( $lines ) . ' lines' );
check( 'header first, newest (last-seen) row first, oldest last, no notice row', 'id,logged_at_utc,level,source,message,repeats,context' === $lines[0] && false !== strpos( $lines[1], 'failure 620' ) && false !== strpos( $lines[620], 'failure 1' ) && false === strpos( end( $lines ), 'truncated' ) );
$out = fopen( 'php://memory', 'w+' );
$n   = Admin::write_csv( $out, $store, array( 'level' => 'warning', 'source' => '', 'search' => '' ) );
rewind( $out );
$lines = array_values( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) );
fclose( $out );
check( 'filters apply: 62 warning rows', 62 === $n && 63 === count( $lines ) );
$out = fopen( 'php://memory', 'w+' );
$n   = Admin::write_csv( $out, $store, array( 'level' => '', 'source' => '', 'search' => 'failure 61' ) );
rewind( $out );
$lines = array_values( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) );
fclose( $out );
check( 'the search filter reaches the CSV (61, 610–619)', 11 === $n && 12 === count( $lines ), $n . ' rows' );

// A store bigger than the cap: a stand-in whose query() reports 5600
// matching rows and serves full pages of them.
$big = new class() {
	public $calls = 0;
	public function query( array $filters, $page = 1, $per_page = 50 ) {
		$this->calls++;
		$per_page = max( 1, min( Store::MAX_PER_PAGE, (int) $per_page ) );
		$start    = ( $page - 1 ) * $per_page;
		$rows     = array();
		for ( $i = $start + 1; $i <= min( 5600, $start + $per_page ); $i++ ) {
			$rows[] = array( 'id' => 5601 - $i, 'logged_at' => '2026-04-01 21:00:00', 'level' => 'error', 'source' => 'php', 'message' => "row $i", 'repeats' => 1, 'context' => array() );
		}
		return array( 'rows' => $rows, 'total' => 5600 );
	}
};
$out = fopen( 'php://memory', 'w+' );
$n   = Admin::write_csv( $out, $big, array() );
rewind( $out );
$lines = array_values( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) );
fclose( $out );
check( 'stops at CSV_MAX_ROWS (5000) after 10 pages', 5000 === Admin::CSV_MAX_ROWS && 5000 === $n && 10 === $big->calls, $n . ' rows, ' . $big->calls . ' calls' );
check( 'and ends with a notice row saying how many were left out', 5002 === count( $lines ) && false !== strpos( end( $lines ), 'notice,export,"truncated: 5000 of 5600 matching rows exported"' ), end( $lines ) );
$out = fopen( 'php://memory', 'w+' );
check( 'an empty store writes just the header', 0 === Admin::write_csv( $out, new Fake_Store(), array() ) );
rewind( $out );
check( 'with no notice', 1 === count( array_filter( explode( "\n", stream_get_contents( $out ) ), 'strlen' ) ) );
fclose( $out );

fh_finish();
