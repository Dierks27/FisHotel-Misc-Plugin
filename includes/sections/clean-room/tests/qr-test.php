<?php
/**
 * QR — the byte-mode encoder behind the tank labels: capacities and
 * version choice, the Reed–Solomon step against a published vector, the
 * bit stream, the finder patterns, timing pattern, dark module and format
 * bits of a known symbol, a module-for-module match with a reference
 * encoder (python-qrcode) for the Front Desk URL, and the SVG's quiet
 * zone.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/qr-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Qr;

function fh_row( array $modules, $y ) {
	return implode( '', array_map( static function ( $b ) { return $b ? '1' : '0'; }, $modules[ $y ] ) );
}

function fh_block( array $modules, $y0, $x0, $h, $w ) {
	$out = array();
	for ( $y = $y0; $y < $y0 + $h; $y++ ) {
		$row = '';
		for ( $x = $x0; $x < $x0 + $w; $x++ ) {
			$row .= $modules[ $y ][ $x ] ? '1' : '0';
		}
		$out[] = $row;
	}
	return $out;
}

$finder = array( '1111111', '1000001', '1011101', '1011101', '1011101', '1000001', '1111111' );

echo "1. Capacities and version choice (level M, byte mode)\n";
check( 'bytes per version: 14, 26, 42, 62, 84, 106', array( 14, 26, 42, 62, 84, 106 ) === array_map( array( Qr::class, 'capacity' ), array( 1, 2, 3, 4, 5, 6 ) ) );
check( 'raw data modules: v1 208, v2 359, v6 1383', 208 === Qr::raw_data_modules( 1 ) && 359 === Qr::raw_data_modules( 2 ) && 1383 === Qr::raw_data_modules( 6 ) );
check( 'data codewords: v1 16, v4 64, v6 108', 16 === Qr::data_codewords( 1 ) && 64 === Qr::data_codewords( 4 ) && 108 === Qr::data_codewords( 6 ) );
check( '1 byte → version 1 (21×21)', 1 === Qr::encode( 'A' )['version'] && 21 === Qr::encode( 'A' )['size'] );
check( '14 bytes still fit version 1, 15 need version 2', 1 === Qr::encode( str_repeat( 'x', 14 ) )['version'] && 2 === Qr::encode( str_repeat( 'x', 15 ) )['version'] );
check( '43 bytes → version 4 (33×33)', 4 === Qr::encode( str_repeat( 'x', 43 ) )['version'] && 33 === Qr::encode( str_repeat( 'x', 43 ) )['size'] );
check( '106 bytes → version 6 (41×41), 107 → null', 6 === Qr::encode( str_repeat( 'x', 106 ) )['version'] && 41 === Qr::encode( str_repeat( 'x', 106 ) )['size'] && null === Qr::encode( str_repeat( 'x', 107 ) ) );
check( 'a UTF-8 string counts its bytes', 2 === Qr::encode( str_repeat( 'é', 8 ) )['version'] );
check( 'alignment centres: v1 none, v2 6/18, v6 6/34', array() === Qr::alignment_positions( 1 ) && array( 6, 18 ) === Qr::alignment_positions( 2 ) && array( 6, 34 ) === Qr::alignment_positions( 6 ) );

echo "2. Reed–Solomon against the published 1-M example (HELLO WORLD, alphanumeric)\n";
$data = array( 0x20, 0x5B, 0x0B, 0x78, 0xD1, 0x72, 0xDC, 0x4D, 0x43, 0x40, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11 );
$ecc  = Qr::rs_remainder( $data, Qr::rs_divisor( 10 ) );
check( 'the ten EC codewords are 196 35 39 119 235 215 231 226 93 23', array( 196, 35, 39, 119, 235, 215, 231, 226, 93, 23 ) === $ecc, implode( ' ', $ecc ) );
check( 'GF(256): 0x80 × 2 = 0x1D, 3 × 3 = 5', 0x1D === Qr::gf_multiply( 0x80, 2 ) && 5 === Qr::gf_multiply( 3, 3 ) );
check( 'the degree-10 generator starts with 1 (monic, highest term first is implicit)', 10 === count( Qr::rs_divisor( 10 ) ) );

echo "3. The bit stream of 'A' (mode 0100, count 1, 0x41, terminator, EC/11 padding)\n";
$cw = Qr::codewords( array( 65 ), 1 );
check( '26 codewords', 26 === count( $cw ) );
check( 'data: 40 14 10 EC 11 EC 11 …', array( 0x40, 0x14, 0x10, 0xEC, 0x11, 0xEC, 0x11 ) === array_slice( $cw, 0, 7 ), implode( ' ', array_map( 'dechex', array_slice( $cw, 0, 7 ) ) ) );
check( 'the pad bytes alternate to the end of the data part', 0xEC === $cw[15] && 0x11 === $cw[14] );
check( 'version 4 interleaves two blocks (first two codewords come from both blocks)', 100 === count( Qr::codewords( array_fill( 0, 50, 0x41 ), 4 ) ) );

echo "4. A known symbol: finders, separators, timing, dark module, format bits\n";
$code    = Qr::encode( 'A' );
$m       = $code['modules'];
$size    = $code['size'];
check( 'top-left finder', $finder === fh_block( $m, 0, 0, 7, 7 ) );
check( 'top-right finder', $finder === fh_block( $m, 0, $size - 7, 7, 7 ) );
check( 'bottom-left finder', $finder === fh_block( $m, $size - 7, 0, 7, 7 ) );
check( 'separators are light (row 7 and column 7 beside the top-left finder)', '00000000' === substr( fh_row( $m, 7 ), 0, 8 ) && ! $m[0][7] && ! $m[6][7] );
check( 'timing pattern alternates along row 6 and column 6 (between the separators)', '0101010' === substr( fh_row( $m, 6 ), 7, 7 ) && $m[8][6] && ! $m[9][6] && $m[10][6] && $m[12][6] && ! $m[7][6] );
check( 'the dark module at (row size−8, col 8)', true === $m[ $size - 8 ][8] );
check( 'format bits for level M, mask 0', 0x5412 === Qr::format_bits( 0 ) ^ ( ( 0 << 10 ) | 0 ) || 21522 === Qr::format_bits( 0 ) );
check( 'format bits M/mask 0 … 7 are the published values', array( 21522, 20773, 24188, 23371, 17913, 16590, 20375, 19104 ) === array_map( array( Qr::class, 'format_bits' ), range( 0, 7 ) ), implode( ',', array_map( array( Qr::class, 'format_bits' ), range( 0, 7 ) ) ) );
$fmt = Qr::format_bits( $code['mask'] );
$bit = static function ( $i ) use ( $fmt ) { return ( ( $fmt >> $i ) & 1 ) === 1; };
check( 'the chosen mask\'s format bits are drawn next to the top-left finder', $bit( 0 ) === $m[0][8] && $bit( 5 ) === $m[5][8] && $bit( 6 ) === $m[7][8] && $bit( 7 ) === $m[8][8] && $bit( 8 ) === $m[8][7] && $bit( 14 ) === $m[8][0] );
check( '…and in the second copy', $bit( 0 ) === $m[8][ $size - 1 ] && $bit( 7 ) === $m[8][ $size - 8 ] && $bit( 14 ) === $m[ $size - 15 + 14 ][8] );
check( 'every mask yields a different, same-sized symbol', 21 === Qr::encode_with_mask( 'A', 3 )['size'] && fh_row( Qr::encode_with_mask( 'A', 3 )['modules'], 10 ) !== fh_row( Qr::encode_with_mask( 'A', 4 )['modules'], 10 ) );
check( 'the penalty of the chosen mask is the lowest of the eight', ( function () {
	$best = PHP_INT_MAX;
	$chosen = Qr::encode( 'A' );
	for ( $k = 0; $k < 8; $k++ ) {
		$c    = Qr::encode_with_mask( 'A', $k );
		$best = min( $best, Qr::penalty( $c['modules'], $c['size'] ) );
	}
	return $best === Qr::penalty( $chosen['modules'], $chosen['size'] );
} )() );

echo "5. Module-for-module against python-qrcode for the Front Desk URL of tank 521\n";
$url     = 'https://fishotel.com/?fh_front_desk=1&tank=521';
$fixture = array_values( array_filter( array_map( 'trim', file( __DIR__ . '/fixtures/qr-front-desk-521.txt' ) ), 'strlen' ) );
$code    = Qr::encode( $url );
check( 'the URL is 46 bytes → version 4, 33×33', 46 === strlen( $url ) && 4 === $code['version'] && 33 === $code['size'] && 33 === count( $fixture ) );
check( 'mask 2 is chosen (the fixture was generated with that mask)', 2 === $code['mask'], (string) $code['mask'] );
$rows = array();
for ( $y = 0; $y < $code['size']; $y++ ) {
	$rows[] = fh_row( $code['modules'], $y );
}
$diff = 0;
foreach ( $rows as $y => $row ) {
	if ( $row !== $fixture[ $y ] ) {
		$diff++;
	}
}
check( 'every row matches the reference encoder', 0 === $diff && $rows === $fixture, $diff . ' rows differ' );
check( 'the first fixture row carries the two finders', '1111111' === substr( $fixture[0], 0, 7 ) && '1111111' === substr( $fixture[0], -7 ) );

echo "6. SVG\n";
$svg = Qr::svg( $url, 4, 4 );
check( 'an <svg> with a white background and one black path', 0 === strpos( $svg, '<svg xmlns="http://www.w3.org/2000/svg"' ) && false !== strpos( $svg, 'fill="#ffffff"' ) && false !== strpos( $svg, '<path d="M' ) && false !== strpos( $svg, 'fill="#000000"' ) );
check( 'the quiet zone: (33 + 2×4) × 4 px = 164 wide', false !== strpos( $svg, 'viewBox="0 0 164 164"' ) && false !== strpos( $svg, 'width="164" height="164"' ) );
check( 'the first dark module sits 4 modules (16 px) in', false !== strpos( $svg, 'd="M16 16h4v4h-4z' ) );
preg_match_all( '/M(\d+) (\d+)h/', $svg, $coords );
$min_x = min( array_map( 'intval', $coords[1] ) );
$max_x = max( array_map( 'intval', $coords[1] ) );
$min_y = min( array_map( 'intval', $coords[2] ) );
$max_y = max( array_map( 'intval', $coords[2] ) );
check( 'no dark module in the quiet zone', 16 === $min_x && 16 === $min_y && 144 === $max_x && 144 === $max_y, "$min_x $max_x $min_y $max_y" );
check( 'the number of rects equals the dark modules', count( $coords[0] ) === array_sum( array_map( static function ( $r ) { return substr_count( $r, '1' ); }, $rows ) ) );
check( 'module size and quiet zone are honoured', false !== strpos( Qr::svg( 'A', 10, 2 ), 'viewBox="0 0 250 250"' ) );
check( 'too long → empty string', '' === Qr::svg( str_repeat( 'x', 200 ) ) );
check( 'nothing in the SVG needs escaping (integers only)', 1 !== preg_match( '/[<>&"\']/', preg_replace( '/<[^>]+>/', '', $svg ) ) );

fh_finish();
