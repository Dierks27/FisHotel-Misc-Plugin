<?php
/**
 * Saved import layouts (Import_Layouts): built from a mapping the owner
 * set, stored in one option, recognised by itself the next time — with
 * and without a header row —, an ordered and an arrived layout of one
 * supplier side by side, another supplier's layout offered and never
 * applied silently, the same headings at two suppliers, a layout that
 * fits only partly, Forget, the bounds, and a hostile heading saved and
 * shown.
 *
 * Every supplier here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-import-layouts-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Import_Layouts as L;
use FisHotel\Misc\Sections\Clean_Room\Order_Import as OI;

// The view helpers the Settings list needs.
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}

$sheet = array(
	array( 'Example Aquatics Export', '', '', '', '' ),
	array( 'KODE', 'NAMA', 'PESAN', 'HARGA', 'STOK' ),
	array( 'XA1', 'Blue Test Wrasse', '4', '2.50', '2m' ),
	array( 'XA2', 'Red Test Goby', '', '1.25', '1sm' ),
);
$map   = array( 0 => 'code', 1 => 'fish', 2 => 'ordered', 3 => 'price' );

echo "1. A layout from a mapping the owner set\n";
check( 'nothing is seeded: the option is empty until the owner saves one', array() === L::all() && false === get_option( L::OPTION, false ) );
$layout = L::build( array( 'supplier' => ' Example  Aquatics ', 'origin' => 'Northland', 'moment' => 'ordered', 'grid' => $sheet, 'header_row' => 1, 'map' => $map, 'sheet' => 'Order form', 'junk' => array( 'SPECIAL OFFERS', 'special offers', '' ), 'today' => '2026-03-02' ) );
check( 'the supplier and the origin, cleaned; the moment; the row the header stands on (the second); the sheet\'s name', 'Example Aquatics' === $layout['supplier'] && 'Northland' === $layout['origin'] && 'ordered' === $layout['moment'] && true === $layout['header'] && 2 === $layout['header_row'] && 'Order form' === $layout['sheet'] );
check_same( 'each column\'s role keyed by its normalised heading TEXT — the ignored column too, so it stays ignored', array( array( 'text' => 'kode', 'role' => 'code' ), array( 'text' => 'nama', 'role' => 'fish' ), array( 'text' => 'pesan', 'role' => 'ordered' ), array( 'text' => 'harga', 'role' => 'price' ), array( 'text' => 'stok', 'role' => '' ) ), $layout['columns'] );
check_same( 'the junk words, lower-cased, once each', array( 'special offers' ), $layout['junk'] );
check( 'both dates are the day it was saved', '2026-03-02' === $layout['saved'] && '2026-03-02' === $layout['used'] );
check( 'a mapping without a fish or a quantity column, or without a supplier, is no layout', null === L::build( array( 'supplier' => 'Example Aquatics', 'grid' => $sheet, 'header_row' => 1, 'map' => array( 1 => 'fish' ) ) ) && null === L::build( array( 'supplier' => '', 'grid' => $sheet, 'header_row' => 1, 'map' => $map ) ) );
L::save( $layout );
check( 'saved: one layout in the option, not autoloaded data of the settings themselves', 1 === count( L::all() ) && $layout === L::all()[0] && is_array( get_option( L::OPTION ) ) );
check_same( 'the columns in words', 'kode → the supplier\'s code · nama → the fish · pesan → ordered · harga → price each', L::describe( $layout ) );

echo "2. Recognised by itself the next time\n";
$next = array(
	array( 'Example Aquatics Export — week 12', '', '', '', '' ),
	array( 'Valid until Friday', '', '', '', '' ),
	array( 'KODE', 'NAMA', 'PESAN', 'HARGA', 'STOK' ),
	array( 'XA9', 'Green Test Chromis', '12', '0.75', '5' ),
);
$known = L::recognise( L::all(), $next, 'example aquatics', 'Northland', 'ordered' );
check( 'the same supplier, the same headings (one row further down this week): applied, in full', $known['applied'] && $layout['id'] === $known['applied']['id'] && false === $known['partial'] && null === $known['offer'] );
check_same( '… the header row is found by its texts, and every column gets its role back — "stok" stays ignored', array( 'row' => 2, 'map' => $map ), array( 'row' => $known['fit']['row'], 'map' => $known['fit']['map'] ) );
$moved = array( array( 'STOK', 'HARGA', 'NAMA', 'KODE', 'PESAN' ), array( '5', '0.75', 'Green Test Chromis', 'XA9', '12' ) );
check_same( 'columns in another order: still found, by their text and never by their position', array( 1 => 'price', 2 => 'fish', 3 => 'code', 4 => 'ordered' ), L::recognise( L::all(), $moved, 'Example Aquatics', 'Northland', 'ordered' )['fit']['map'] );
check( 'the supplier\'s layout answers for another origin of the same supplier too', null !== L::recognise( L::all(), $next, 'Example Aquatics', 'Southland', 'ordered' )['applied'] );
check( 'a sheet that has nothing of it: not applied, nothing offered', null === L::recognise( L::all(), array( array( 'fish', 'qty' ), array( 'Blue Test Wrasse', '4' ) ), 'Example Aquatics', 'Northland', 'ordered' )['applied'] );

echo "3. Without a header row\n";
$bare    = array( array( 'Blue Test Wrasse', 'XA1', '4', '2.50' ), array( 'Red Test Goby', 'XA2', '6', '1.25' ) );
$no_head = L::build( array( 'supplier' => 'Sample Reef Supply', 'origin' => '', 'moment' => 'ordered', 'grid' => $bare, 'header_row' => null, 'map' => array( 0 => 'fish', 2 => 'qty', 3 => 'price' ), 'today' => '2026-03-03' ) );
check( 'a sheet without headings is kept by position, with its column count', false === $no_head['header'] && 0 === $no_head['header_row'] && 4 === count( $no_head['columns'] ) && array( 'fish', '', 'qty', 'price' ) === array_column( $no_head['columns'], 'role' ) && array( '', '', '', '' ) === array_column( $no_head['columns'], 'text' ) );
L::save( $no_head );
$again = L::recognise( L::all(), array( array( 'Green Test Chromis', 'XA9', '12', '0.75' ) ), 'Sample Reef Supply', '', 'ordered' );
check_same( 'recognised through the order\'s supplier, when the column count is the same', array( 0 => 'fish', 2 => 'qty', 3 => 'price' ), $again['fit']['map'] );
check( '… not with another column count', null === L::recognise( L::all(), array( array( 'Green Test Chromis', '12', '0.75' ) ), 'Sample Reef Supply', '', 'ordered' )['applied'] );
$nobody = L::recognise( L::all(), array( array( 'Green Test Chromis', 'XA9', '12', '0.75' ) ), 'Somebody Else', '', 'ordered' );
check( 'a file without headers can only be recognised through the order\'s supplier: never offered to another', null === $nobody['applied'] && null === $nobody['offer'] );
check_same( 'in words: by position', 'column 1 → the fish · column 3 → quantity · column 4 → price each', L::describe( $no_head ) );

echo "4. An ordered and an arrived layout side by side\n";
$invoice = array( array( 'BOX', 'KODE', 'JML', 'NAMA', 'HARGA' ), array( '1', 'XA1', '4', 'Blue Test Wrasse', '2.50' ) );
$arrived = L::build( array( 'supplier' => 'Example Aquatics', 'origin' => 'Northland', 'moment' => 'arrived', 'grid' => $invoice, 'header_row' => 0, 'map' => array( 0 => 'box', 1 => 'code', 2 => 'qty', 3 => 'fish', 4 => 'price' ), 'today' => '2026-03-09' ) );
L::save( $arrived );
check( 'a supplier has one of each: two layouts, different ids', 3 === count( L::all() ) && $arrived['id'] !== $layout['id'] && L::id( 'EXAMPLE aquatics', 'northland', 'arrived' ) === $arrived['id'] );
check( 'the invoice is read with the arrived layout, the order sheet with the ordered one', 'arrived' === L::recognise( L::all(), $invoice, 'Example Aquatics', 'Northland', 'arrived' )['applied']['moment'] && 'ordered' === L::recognise( L::all(), $next, 'Example Aquatics', 'Northland', 'ordered' )['applied']['moment'] );
check( 'the order sheet under the ARRIVED moment is not read with the invoice\'s layout', null === L::recognise( L::all(), $next, 'Example Aquatics', 'Northland', 'arrived' )['applied'] );
check_same( 'the last used comes first', array( 'arrived', 'ordered', 'ordered' ), array_column( L::all(), 'moment' ) );

echo "5. Another supplier's layout: offered, never applied silently\n";
$other = L::recognise( L::all(), $next, 'Demo Fish Trading', '', 'ordered' );
check( 'an order of another supplier, a sheet with Example Aquatics\' headings: NOT applied — offered', null === $other['applied'] && $other['offer'] && 'Example Aquatics' === $other['offer']['supplier'] );
check( 'a new order with no supplier chosen yet: offered too', 'Example Aquatics' === L::recognise( L::all(), $next, '', '', 'ordered' )['offer']['supplier'] );
$theirs = L::build( array( 'supplier' => 'Demo Fish Trading', 'origin' => '', 'moment' => 'ordered', 'grid' => $sheet, 'header_row' => 1, 'map' => array( 1 => 'fish', 2 => 'ordered', 3 => 'worth' ), 'today' => '2026-03-10' ) );
L::save( $theirs );
$mine = L::recognise( L::all(), $next, 'Demo Fish Trading', '', 'ordered' );
$them = L::recognise( L::all(), $next, 'Example Aquatics', 'Northland', 'ordered' );
check( 'two suppliers whose sheets have the same headings each keep their own layout — and their own roles', 'Demo Fish Trading' === $mine['applied']['supplier'] && 'worth' === $mine['fit']['map'][3] && 'Example Aquatics' === $them['applied']['supplier'] && 'price' === $them['fit']['map'][3] && null === $mine['offer'] && null === $them['offer'] );
$weak = array( array( 'NAMA', 'PESAN', 'Something', 'Else' ), array( 'Green Test Chromis', '12', 'x', 'y' ) );
check( 'another supplier\'s layout that fits only partly is not offered', null === L::recognise( L::all(), $weak, 'Third Supplier', '', 'ordered' )['offer'] );

echo "6. A layout that fits only partly\n";
$lost = array( array( 'KODE', 'NAMA', 'PESAN', 'STOK' ), array( 'XA9', 'Green Test Chromis', '12', '5' ) );
$part = L::recognise( L::all(), $lost, 'Example Aquatics', 'Northland', 'ordered' );
check( 'the sheet lost a column: applied to the columns that still fit, and it says which did not', $part['applied'] && true === $part['partial'] && array( 'harga' ) === $part['fit']['missing'] && array( 0 => 'code', 1 => 'fish', 2 => 'ordered' ) === $part['fit']['map'] );
$gained = array( array( 'KODE', 'NAMA', 'PESAN', 'HARGA', 'STOK', 'Size', 'Catatan' ), array( 'XA9', 'Green Test Chromis', '12', '0.75', '5', 'M', 'x' ) );
$more   = L::recognise( L::all(), $gained, 'Example Aquatics', 'Northland', 'ordered' );
check( 'the sheet gained columns: partly — the new headings are named, and one the importer knows by itself takes its role', true === $more['partial'] && array( 'size', 'catatan' ) === $more['fit']['extra'] && 'size' === $more['fit']['map'][5] && ! isset( $more['fit']['map'][6] ) && array() === $more['fit']['missing'] );
$gone = array( array( 'KODE', 'HARGA', 'STOK' ), array( 'XA9', '0.75', '5' ) );
check( 'a sheet that lost its fish or its quantity column cannot be read with the layout at all', null === L::recognise( L::all(), $gone, 'Example Aquatics', 'Northland', 'ordered' )['applied'] );

echo "7. Used, updated, forgotten\n";
check( 'a layout that was used: its date moves, and the rows the owner said to leave out join its junk words', true === L::touch( $layout['id'], '2026-04-01', array( 'Clearance Corner' ) ) && '2026-04-01' === L::find( L::all(), $layout['id'] )['used'] && array( 'special offers', 'clearance corner' ) === L::find( L::all(), $layout['id'] )['junk'] && '2026-03-02' === L::find( L::all(), $layout['id'] )['saved'] );
$update = L::build( array( 'supplier' => 'Example Aquatics', 'origin' => 'Northland', 'moment' => 'ordered', 'grid' => $lost, 'header_row' => 0, 'map' => array( 1 => 'fish', 2 => 'ordered' ), 'today' => '2026-04-02' ) );
L::save( $update );
$stored = L::find( L::all(), $layout['id'] );
check( 'saving again for the same supplier, origin and moment replaces the layout — and keeps what it had learned to leave out', 4 === count( L::all() ) && 4 === count( $stored['columns'] ) && array( 'special offers', 'clearance corner' ) === $stored['junk'] && 1 === $stored['header_row'] );
check( 'touching a layout that is gone says so', false === L::touch( 'nonexistent00', '2026-04-01' ) );
check( 'Forget: the layout goes, the others stay', true === L::forget( $arrived['id'] ) && 3 === count( L::all() ) && null === L::find( L::all(), $arrived['id'] ) && false === L::forget( $arrived['id'] ) );
check( '… and the next invoice from that supplier is asked about again', null === L::recognise( L::all(), $invoice, 'Example Aquatics', 'Northland', 'arrived' )['applied'] );

echo "8. Bounds and sanitising\n";
$many = array();
for ( $i = 0; $i < L::MAX_LAYOUTS + 15; $i++ ) {
	$many[] = array( 'supplier' => 'Supplier ' . $i, 'moment' => 'ordered', 'header' => true, 'header_row' => 1, 'columns' => array( array( 'text' => 'fish', 'role' => 'fish' ), array( 'text' => 'qty', 'role' => 'qty' ) ), 'used' => sprintf( '2026-01-%02d', 1 + $i % 28 ) );
}
check( 'at most ' . L::MAX_LAYOUTS . ' layouts are kept — the last used', L::MAX_LAYOUTS === count( L::store( $many ) ) && '2026-01-28' === L::all()[0]['used'] );
$wide = array();
for ( $i = 0; $i < 90; $i++ ) {
	$wide[] = array( 'text' => 'heading ' . $i, 'role' => 0 === $i ? 'fish' : ( 1 === $i ? 'qty' : '' ) );
}
$big = L::sanitize_one( array( 'supplier' => str_repeat( 'S', 300 ), 'origin' => array( 'x' ), 'moment' => 'nonsense', 'header_row' => 9999, 'columns' => $wide, 'junk' => array_map( 'strval', range( 1, 80 ) ), 'sheet' => str_repeat( 'n', 90 ), 'saved' => 'yesterday', 'used' => '2026-13-45' ) );
check( 'one layout: at most ' . OI::MAX_COLS . ' columns and ' . L::MAX_JUNK . ' junk words, the texts cut, a bad moment → ordered, a bad date → none, the header row within the rows searched', OI::MAX_COLS === count( $big['columns'] ) && L::MAX_JUNK === count( $big['junk'] ) && 80 === strlen( $big['supplier'] ) && '' === $big['origin'] && 'ordered' === $big['moment'] && OI::HEADER_SEARCH_ROWS === $big['header_row'] && 31 === strlen( $big['sheet'] ) && '' === $big['saved'] && '' === $big['used'] );
check_same( 'junk in the option is dropped: not an array, no supplier, an unknown role, a role named twice', array( array( 'fish', 'qty', '', '' ) ), array_map( static function ( $l ) { return array_column( $l['columns'], 'role' ); }, L::sanitize( array( 'junk', 42, array( 'supplier' => '' ), array( 'supplier' => 'Example Aquatics', 'columns' => array( array( 'text' => 'a', 'role' => 'fish' ), array( 'text' => 'b', 'role' => 'qty' ), array( 'text' => 'c', 'role' => 'qty' ), array( 'text' => 'd', 'role' => 'drop table' ), 'x' ) ) ) ) ) );
check( 'what is not an array is no list', array() === L::sanitize( 'x' ) && array() === L::sanitize( null ) );

echo "9. A hostile heading, saved and shown\n";
$evil_grid = array( array( '<script>alert(1)</script>', '"><img src=x onerror=alert(2)>', 'Qty' ), array( 'Blue Test Wrasse', 'x', '4' ) );
$evil      = L::build( array( 'supplier' => '<b>Example</b> & "Sons"', 'origin' => "North\x00land<i>", 'moment' => 'ordered', 'grid' => $evil_grid, 'header_row' => 0, 'map' => array( 0 => 'fish', 2 => 'qty' ), 'sheet' => '<svg onload=alert(3)>', 'junk' => array( '<u>heading</u>' ), 'today' => '2026-05-01' ) );
L::store( array( $evil ) );
$saved = L::all()[0];
check( 'saved as the text it is — the heading has to match the file\'s own text next time — without control characters', '<script>alert(1)</script>' === $saved['columns'][0]['text'] && 'North land<i>' === $saved['origin'] && false === strpos( wp_json_encode( $saved ), '\u0000' ) );
check( '… and it is recognised again by that text', null !== L::recognise( L::all(), $evil_grid, '<b>Example</b> & "Sons"', '', 'ordered' )['applied'] );
$import_layouts = L::all();
ob_start();
include Clean_Room::path() . 'views/settings-import-layouts.php';
$html = (string) ob_get_clean();
check( 'the Settings list shows it escaped: no tag of the heading, the supplier, the origin, the sheet or the junk word reaches the page', false === strpos( $html, '<script>' ) && false === strpos( $html, '<img' ) && false === strpos( $html, '<svg' ) && false === strpos( $html, '<b>' ) && false === strpos( $html, '<u>' ) && false === strpos( $html, '<i>' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false !== strpos( $html, '&lt;b&gt;Example&lt;/b&gt; &amp; &quot;Sons&quot;' ) );
check( '… one row with its id, the moment, the columns in words, the last use and Forget', 1 === substr_count( $html, 'data-layout="' . $saved['id'] . '"' ) && false !== strpos( $html, 'ordered (the order sheet)' ) && false !== strpos( $html, '→ the fish' ) && false !== strpos( $html, '2026-05-01' ) && false !== strpos( $html, 'fh-cr-layout-forget' ) && false !== strpos( $html, 'header on row 1' ) );
$import_layouts = array();
ob_start();
include Clean_Room::path() . 'views/settings-import-layouts.php';
$html = (string) ob_get_clean();
check( 'with nothing saved the list says so', false !== strpos( $html, 'Nothing saved yet' ) && false === strpos( $html, '<table' ) );

fh_finish();
