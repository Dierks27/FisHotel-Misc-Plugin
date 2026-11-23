<?php
/**
 * Import lines — the pure half (Order_Import): the reader (pasted tabs,
 * commas with quoted cells, a header row or none, the workbook sheet's
 * own headings), the column finder (every synonym, the guess without a
 * header, the refusal without a fish or a quantity column), the number
 * parser (currency, thousands, "ea"; a decimal comma is NOT assumed; a
 * formula-looking cell is text), the sizes inside names, the matcher's
 * flags, the rows left out, the real shapes (a 1,200-row price list with
 * title rows, a STOCK and an ORDER column; a multi-box, two-origin
 * invoice block with fee lines and a subtotal), the reconciliation, the
 * fees, and the instruction text against the documented header.
 *
 * Every name, code and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-import-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Import_Map;
use FisHotel\Misc\Sections\Clean_Room\Order_Import as OI;
use FisHotel\Misc\Sections\Clean_Room\Parser;

$sizes = array(
	array( 'slug' => 'small', 'label' => 'Small' ),
	array( 'slug' => 'medium', 'label' => 'Medium' ),
	array( 'slug' => 'large', 'label' => 'Large' ),
	array( 'slug' => '2-3', 'label' => '2-3"' ),
);

echo "1. The reader\n";
$tabs = OI::grid_from_text( "fish\tordered\tprice\r\nBlue Test Wrasse\t4\t2.50\r\n\r\nRed Test Goby\t6\t\r\n" );
check_same( 'rows copied out of a spreadsheet are tab-separated; a blank line is dropped; every row as wide as the widest', array( array( 'fish', 'ordered', 'price' ), array( 'Blue Test Wrasse', '4', '2.50' ), array( 'Red Test Goby', '6', '' ) ), $tabs['grid'] );
check_same( 'the delimiter is the tab', "\t", $tabs['delimiter'] );
$csv = OI::grid_from_text( "\xEF\xBB\xBFname,qty,price\n\"Wrasse, Blue Test\",4,\"1,234.50\"\n\"Goby \"\"Red\"\" Test\",6,3\n\"Two\nlines\",1,1\n" );
check_same( 'commas with quoted cells: a comma, a doubled quote and a line break inside quotes; the byte-order mark goes', array( array( 'name', 'qty', 'price' ), array( 'Wrasse, Blue Test', '4', '1,234.50' ), array( 'Goby "Red" Test', '6', '3' ), array( 'Two lines', '1', '1' ) ), $csv['grid'] );
check_same( 'semicolons when more lines carry them than commas', array( array( 'a', 'b' ), array( 'Test, Fish', '2' ) ), OI::grid_from_text( "a;b\nTest, Fish;2\n" )['grid'] );
check_same( 'one column of names is one column', array( array( 'Blue Test Wrasse' ), array( 'Red Test Goby' ) ), OI::grid_from_text( "Blue Test Wrasse\nRed Test Goby" )['grid'] );
check_same( 'a cell: control characters dropped, spaces collapsed, a whole float without its .0, a long float kept', array( 'a b', '12', '0.2291666', '' ), array( OI::cell( " a\x00\x07 \t b " ), OI::cell( 12.0 ), OI::cell( 0.2291666 ), OI::cell( array( 'x' ) ) ) );
check( 'a cell is cut to ' . OI::MAX_CELL . ' characters, never half a character', OI::MAX_CELL === mb_strlen( OI::cell( str_repeat( 'é', 500 ) ) ) && mb_check_encoding( OI::cell( str_repeat( 'é', 500 ) ), 'UTF-8' ) );
$wide = OI::clean_grid( array( array_fill( 0, 90, 'x' ), 'junk', array( '', '', '' ), array( 'a' ) ) );
check( 'a grid: at most ' . OI::MAX_COLS . ' columns, junk and blank rows dropped', 2 === count( $wide['grid'] ) && OI::MAX_COLS === count( $wide['grid'][0] ) && OI::MAX_COLS === count( $wide['grid'][1] ) );
$tall = OI::clean_grid( array_fill( 0, OI::MAX_ROWS + 5, array( 'a', '1' ) ) );
check( 'a grid: at most ' . OI::MAX_ROWS . ' rows, and it says it was cut', OI::MAX_ROWS === count( $tall['grid'] ) && true === $tall['truncated'] && OI::MAX_ROWS >= 2000 );
check_same( 'the rows of a workbook sheet (row → column letter → value): gaps filled, numbers as text', array( array( '', 'Fish', 'Qty' ), array( 'AB1', 'Blue Test Wrasse', '4' ) ), OI::grid_from_rows( array( 3 => array( 'B' => 'Fish', 'C' => 'Qty' ), 4 => array( 'A' => 'AB1', 'B' => 'Blue Test Wrasse', 'C' => 4.0 ) ) )['grid'] );

echo "2. The column finder\n";
$synonyms = array(
	'fish'     => array( 'Fish', 'Species', 'NAME', 'Common Name', 'Description', 'Item', 'ENGLISH NAME' ),
	'sci'      => array( 'Scientific Name', 'Sci Name', 'LATIN NAME', 'Scientific' ),
	'size'     => array( 'Size', 'SIZE:' ),
	'ordered'  => array( 'Ordered', 'ORDER', 'Order Qty' ),
	'qty'      => array( 'Qty', 'QUANTITY', 'Pcs' ),
	'received' => array( 'Received', 'Shipped', 'Filled', "Rcv'd", 'Rcv’d' ),
	'price'    => array( 'Price', 'Cost', 'Unit Price', 'Each', 'PRICE US$', 'Unit' ),
	'amount'   => array( 'Total', 'Amount', 'Amt' ),
	'doa'      => array( 'DOA', 'Dead' ),
	'per_box'  => array( 'Per Box', 'QTY/BOX' ),
	'bags'     => array( 'Bags' ),
	'box'      => array( 'Box', 'Box No', 'BOX #' ),
	'pct'      => array( 'Percent of a box', '% of box', 'Percent' ),
	'tank'     => array( 'Tank' ),
	'label'    => array( 'What the label said', 'Label' ),
	'code'     => array( 'CODE', 'Item Code', 'SKU' ),
	'origin'   => array( 'Origin', 'Section', 'origin / section' ),
	'stock'    => array( 'STOCK', 'Available' ),
	'check'    => array( 'check' ),
	'worth'    => array( 'Worth $' ),
	'survived' => array( "Srv'd" ),
);
$wrong = array();
foreach ( $synonyms as $role => $texts ) {
	foreach ( $texts as $text ) {
		if ( OI::header_role( $text ) !== $role ) {
			$wrong[] = $text . ' → ' . OI::header_role( $text ) . ' (wanted ' . $role . ')';
		}
	}
}
check( 'every synonym names its role, by its text alone (any case, a trailing colon, a curly quote)', array() === $wrong, implode( '; ', $wrong ) );
check( 'every role the finder knows has words for the dropdown', array() === array_diff( array_keys( OI::header_rules() ), array_keys( OI::roles() ) ) );
$batch = array( 'name' => 'fish', 'sci_name' => 'sci', 'qty_ordered' => 'ordered', 'per_box' => 'per_box', 'bags' => 'bags', 'qty_received' => 'received', 'unit_price' => 'price', 'total' => 'amount', 'worth' => 'worth', 'qty_survived' => 'survived', 'qty_doa' => 'doa' );
$wrong = array();
foreach ( Import_Map::batch_headers() as $key => $texts ) {
	foreach ( $texts as $text ) {
		if ( ! isset( $batch[ $key ] ) || OI::header_role( $text ) !== $batch[ $key ] ) {
			$wrong[] = $key . ': ' . $text;
		}
	}
}
check( 'the workbook\'s own "Recieved (N)" sheet: every label Import_Map knows maps — read from that file, not copied', array() === $wrong && count( Import_Map::batch_headers() ) === count( $batch ), implode( '; ', $wrong ) );
check_same( 'a row copied straight out of that sheet', array( 0 => 'fish', 1 => 'sci', 2 => 'ordered', 3 => 'per_box', 4 => 'received', 5 => 'price', 6 => 'amount', 7 => 'worth', 8 => 'survived', 9 => 'doa' ), OI::roles_of_row( array( 'Name', 'Sci Name', 'Order', 'Per Box', "Rcv'd", 'Price', 'Total', 'Worth $', "Srv'd", 'DOA' ) ) );
check( 'nobody recognises a number, a formula, a long sentence or an unknown word', '' === OI::header_role( '12' ) && '' === OI::header_role( '=name' ) && '' === OI::header_role( 'The name of the company that sends the fish to us every week' ) && '' === OI::header_role( 'Zzyzx' ) );
check_same( 'each role once: the first from the left wins', array( 0 => 'fish', 2 => 'qty' ), OI::roles_of_row( array( 'Common Name', 'Local Name', 'Qty', 'Quantity' ) ) );
check( '"stock" is the supplier\'s stock — never a quantity; "qty/box" is the per-box count — not a quantity', 'stock' === OI::header_role( 'Stock' ) && 'per_box' === OI::header_role( 'Qty/Box' ) && ! in_array( 'stock', OI::QTY_ROLES, true ) && ! in_array( 'per_box', OI::QTY_ROLES, true ) );
$headed = OI::find_header( array( array( 'Example Aquatics Export', '', '' ), array( '12 Sample Street', '', '' ), array( 'CODE', 'ENGLISH NAME', 'ORDER' ), array( 'XA1', 'Blue Test Wrasse', '4' ) ) );
check( 'the header row is FOUND under the title rows: the first row with two or more recognised headings', 2 === $headed['row'] && array( 0 => 'code', 1 => 'fish', 2 => 'ordered' ) === $headed['map'] );
check( 'a header further down than ' . OI::HEADER_SEARCH_ROWS . ' rows is not looked for', null === OI::find_header( array_merge( array_fill( 0, OI::HEADER_SEARCH_ROWS, array( 'x', '' ) ), array( array( 'fish', 'qty' ) ) ) )['row'] );
$bare = array( array( 'Blue Test Wrasse', '4', '2.50' ), array( 'Red Test Goby', '6', '$1.25' ), array( 'Green Test Chromis', '12', '0.75' ) );
check( 'no header row: none is found', null === OI::find_header( $bare )['row'] );
check_same( '… and the guess: the wordy column is the fish, the first whole-number column the quantity, a decimal column the price', array( 0 => 'fish', 1 => 'qty', 2 => 'price' ), OI::guess_columns( $bare ) );
check_same( 'the guess with a code column in front: the longer wordy column is the fish', array( 1 => 'fish', 2 => 'qty' ), OI::guess_columns( array( array( 'XA1', 'Blue Test Wrasse', '4' ), array( 'XA2', 'Red Test Goby', '6' ) ) ) );
check( 'nothing can be saved without a fish column and one quantity column', false === OI::map_complete( array( 0 => 'fish' ) ) && false === OI::map_complete( array( 1 => 'qty', 2 => 'price' ) ) && false === OI::map_complete( array( 0 => 'fish', 1 => 'stock', 2 => 'per_box' ) ) && true === OI::map_complete( array( 0 => 'fish', 1 => 'received' ) ) );
check_same( 'a posted map: unknown roles, columns outside the grid and a role named twice are dropped', array( 0 => 'fish', 2 => 'qty' ), OI::clean_map( array( '0' => 'fish', '1' => 'nonsense', '2' => 'qty', '3' => 'qty', '9' => 'price', 'x' => 'size' ), 4 ) );

echo "3. Numbers\n";
$n = static function ( $text ) {
	$read = OI::number( $text );
	return 'ok' === $read['state'] ? $read['value'] : $read['state'];
};
check_same( 'currency signs, thousands separators and "ea" are tolerated', array( 12.5, 1234.5, 4.5, 4.5, 0.25, 0.2291666, 12.0, 1234.0, 0.5 ), array( $n( '$12.50' ), $n( '$1,234.50' ), $n( '4.50 ea' ), $n( '4.5/ea' ), $n( 'US$ 0.25' ), $n( '0.2291666' ), $n( '12 pcs' ), $n( '1,234' ), $n( '.5' ) ) );
check_same( 'a decimal comma is NOT assumed: flagged, not read as 6.5 or 650', array( 'bad', 'bad', 'bad' ), array( $n( '6,50' ), $n( '12,5' ), $n( '1.234,50' ) ) );
check_same( 'a formula-looking cell is text, never a number', array( 'bad', 'bad', 'bad', true, true, true, false ), array( $n( '=2+2' ), $n( '+5' ), $n( '@SUM(A1)' ), OI::is_formula( '=A1' ), OI::is_formula( ' +1' ), OI::is_formula( '@x' ), OI::is_formula( '5' ) ) );
check_same( 'blank, a dash and n/a are blank; words and negatives are flagged', array( 'blank', 'blank', 'blank', 'bad', 'bad', 'bad' ), array( $n( '' ), $n( '-' ), $n( 'N/A' ), $n( '2m' ), $n( '-3' ), $n( '1sm' ) ) );
check_same( 'a count is a whole number', array( 12, 'bad', 'bad', 0 ), array( OI::quantity( '12' )['value'], OI::quantity( '1.5' )['state'], OI::quantity( '99999' )['state'], OI::quantity( '0' )['value'] ) );

echo "4. Sizes inside names\n";
$lift = static function ( $name, $cell = '' ) use ( $sizes ) {
	$out = OI::lift_size( $name, $cell, $sizes );
	return array( $out['name'], $out['slug'], $out['written'] );
};
check_same( '"(L) (10-14 CM)": the size is lifted, the measure beside it goes with it', array( 'Blue Test Wrasse', 'large', 'L' ), $lift( 'Blue Test Wrasse (L) (10-14 CM)' ) );
check_same( '"M (6-9,5 cm)"', array( 'Green Test Chromis', 'medium', 'M' ), $lift( 'Green Test Chromis M (6-9,5 cm)' ) );
check_same( '"- S"', array( 'Red Test Goby', 'small', 'S' ), $lift( 'Red Test Goby - S' ) );
check_same( '"SM", "LG", "Med"', array( 'small', 'large', 'medium' ), array( $lift( 'Test Tang SM' )[1], $lift( 'Test Tang LG' )[1], $lift( 'Test Tang, Med' )[1] ) );
check_same( 'a size that is a measure of the list: \'2-3"\'', array( 'Test Tang', '2-3', '2-3"' ), $lift( 'Test Tang 2-3"' ) );
check_same( 'the second "M" of a name that has one inside it is the size, not the first', array( 'Coral Mimic Test', 'medium', 'M' ), $lift( 'Coral Mimic Test M' ) );
check_same( '"S/M" and "LG/XL" are not in this list: the name stays exactly as typed, the size as written is handed back', array( array( 'Test Tang S/M', null, 'S/M' ), array( 'Test Tang LG/XL', null, 'LG/XL' ) ), array( $lift( 'Test Tang S/M' ), $lift( 'Test Tang LG/XL' ) ) );
check_same( '… and they map when the list has them', 's-m', OI::lift_size( 'Test Tang S/M', '', array( array( 'slug' => 's-m', 'label' => 'S/M' ) ) )['slug'] );
check( 'a size from the size column says so (it is nowhere in the name); one found inside the name does not', true === OI::lift_size( 'Test Tang', 'XL', $sizes )['cell'] && true === OI::lift_size( 'Test Tang', 'M', $sizes )['cell'] && false === OI::lift_size( 'Test Tang S/M', '', $sizes )['cell'] && false === OI::lift_size( 'Test Tang - S', '', $sizes )['cell'] );
check_same( '"(Juv)" without a juvenile size: kept', array( 'Test Angel (Juv)', null, 'Juv' ), $lift( 'Test Angel (Juv)' ) );
check_same( '"Mated Pair", "male", "female", "NEW", "RARE" and "(INV)" are no sizes and stay in the name', array( 'Test Clown Mated Pair', 'Test Wrasse male', 'Test Wrasse female', 'Test Goby NEW', 'Test Goby RARE', 'Test Shrimp (INV)' ), array( $lift( 'Test Clown Mated Pair' )[0], $lift( 'Test Wrasse male' )[0], $lift( 'Test Wrasse female' )[0], $lift( 'Test Goby NEW' )[0], $lift( 'Test Goby RARE' )[0], $lift( 'Test Shrimp (INV)' )[0] ) );
check( '… and none of them is read as a size', null === $lift( 'Test Clown Mated Pair' )[1] && null === $lift( 'Test Wrasse male' )[1] && null === $lift( 'Test Shrimp (INV)' )[1] && '' === $lift( 'Test Wrasse female' )[2] );
check_same( '"(INV)" stays while the size beside it is lifted', array( 'Test Shrimp (INV)', 'large', 'L' ), $lift( 'Test Shrimp (INV) (L)' ) );
check_same( 'a size column of its own (S, M, M/L): mapped when it is in the list, the name untouched', array( array( 'Test Tang', 'medium', 'M' ), array( 'Test Tang', null, 'M/L' ) ), array( $lift( 'Test Tang', 'M' ), $lift( 'Test Tang', 'M/L' ) ) );
check_same( 'the matcher reads a name without its parentheses, its size words and its shouting', array( 'Blue Test Wrasse', 'Test Tang', 'Test Goby', 'Test Clown Mated Pair' ), array( OI::match_text( 'Blue Test Wrasse (L) (10-14 CM)' ), OI::match_text( 'Test Tang LG/XL' ), OI::match_text( 'Test Goby NEW' ), OI::match_text( 'Test Clown Mated Pair' ) ) );

echo "5. The matcher\n";
$candidates = array(
	array( 'product_id' => 11, 'name' => 'Blue Test Wrasse', 'variants' => array( array( 'text' => 'Blue Test Wrasse', 'kind' => 'title' ), array( 'text' => 'Testus caeruleus', 'kind' => 'sci' ), array( 'text' => 'azure sample', 'kind' => 'alias' ) ) ),
	array( 'product_id' => 12, 'name' => 'Red Test Goby', 'variants' => array( array( 'text' => 'Red Test Goby', 'kind' => 'title' ), array( 'text' => 'Testus ruber', 'kind' => 'sci' ) ) ),
	array( 'product_id' => 13, 'name' => 'Red Test Goby Deluxe', 'variants' => array( array( 'text' => 'Red Test Goby Deluxe', 'kind' => 'title' ) ) ),
	array( 'product_id' => null, 'name' => 'Spotted Sample Blenny', 'variants' => array( array( 'text' => 'Spotted Sample Blenny', 'kind' => 'title' ), array( 'text' => 'Exemplum maculatum', 'kind' => 'sci' ) ) ),
	array( 'product_id' => 15, 'name' => 'Striped Sample Blenny', 'variants' => array( array( 'text' => 'Striped Sample Blenny', 'kind' => 'title' ) ) ),
);
$index = OI::index_candidates( $candidates );
$m     = static function ( $name, $sci = '' ) use ( $index ) {
	$out = OI::match( $name, $sci, $index );
	return array( $out['kind'], $out['flag'], null !== $out['pick'] ? $out['candidates'][ $out['pick'] ]['name'] : null );
};
check_same( 'exact: the text is a name the lists know (any case, any spacing)', array( 'exact', false, 'Blue Test Wrasse' ), $m( 'blue  test WRASSE' ) );
check_same( 'exact through a size in the name', array( 'exact', false, 'Blue Test Wrasse' ), $m( 'Blue Test Wrasse (L)' ) );
check_same( 'alias: a learned name', array( 'alias', false, 'Blue Test Wrasse' ), $m( 'Azure Sample' ) );
check_same( 'the scientific name typed in the fish column', array( 'exact', false, 'Red Test Goby' ), $m( 'Testus ruber' ) );
check_same( 'close: a typo within two letters', array( 'close', false, 'Blue Test Wrasse' ), $m( 'Blue Test Wrase' ) );
check_same( 'weak: flagged and NOT chosen', array( 'weak', true, null ), $m( 'Blue Thing' ) );
check_same( 'ambiguous: two as good as each other — flagged, not chosen', array( 'ambiguous', true, null ), $m( 'Sample Blenny' ) );
check_same( 'none: nothing comes near', array( 'none', false, null ), $m( 'Zzyzx Qwerty' ) );
check_same( 'unknown: no name, or a name typed "??"', array( array( 'unknown', true, null ), array( 'unknown', true, null ) ), array( $m( '' ), $m( '?? small brown one' ) ) );
check_same( 'sci: the fish column says nothing sure, the scientific name is exactly one fish', array( 'sci', false, 'Spotted Sample Blenny' ), $m( 'Sample Blenny', 'Exemplum maculatum' ) );
$weak = OI::match( 'Sample Blenny', '', $index );
check( 'a flagged row still offers its candidates, at most ' . OI::MAX_CANDIDATES, in_array( 'Spotted Sample Blenny', array_column( $weak['candidates'], 'name' ), true ) && in_array( 'Striped Sample Blenny', array_column( $weak['candidates'], 'name' ), true ) && count( $weak['candidates'] ) <= OI::MAX_CANDIDATES );
$same = true;
foreach ( array( 'Blue Test Wrase', 'red goby', 'sample blenny striped', 'Testus', 'deluxe goby red test' ) as $text ) {
	$mine   = OI::score( $text, $index );
	$parser = Parser::match_species( Parser::tokenize( $text ), $candidates );
	$same   = $same && array_column( $mine, 'name' ) === array_column( $parser, 'name' ) && array_map( static function ( $s ) { return round( $s, 6 ); }, array_column( $mine, 'score' ) ) === array_map( static function ( $s ) { return round( $s, 6 ); }, array_column( $parser, 'score' ) );
}
check( 'the scores are the voice parser\'s own (Parser::match_species()), reached through the index', $same );

echo "6. Rows, and what is left out\n";
$grid = OI::grid_from_text(
	"Example Aquatics — invoice\n" .
	"fish\tqty\tprice\ttotal\n" .
	"FISH\n" .
	"Blue Test Wrasse\t4\t2.50\t10.00\n" .
	"Red Test Goby\t6\t1.25\t7.50\n" .
	"\n" .
	"fish\tqty\tprice\ttotal\n" .
	"INVERTS\n" .
	"Test Shrimp (INV)\t=3+3\t6,50\t\n" .
	"Green Test Chromis\t0\t0.75\t\n" .
	"Packing charge\t\t\t12.00\n" .
	"Subtotal\t\t\t17.50\n" .
	"Invoice total\t\t\t29.50\n"
);
$found = OI::find_header( $grid['grid'] );
$a     = OI::analyse( $grid['grid'], array( 'mode' => 'arrived', 'map' => $found['map'], 'header_row' => $found['row'], 'sizes' => $sizes ) );
check_same( 'the fish rows, in order', array( 'Blue Test Wrasse', 'Red Test Goby', 'Test Shrimp (INV)' ), array_column( $a['rows'], 'typed' ) );
check_same( 'left out, each with its reason: the repeated header, the section headings, the row with no count, the fee line, the totals', array( 'FISH' => 'heading', 'fish' => 'header', 'INVERTS' => 'heading', 'Green Test Chromis' => 'zero', 'Packing charge' => 'fee', 'Subtotal' => 'total', 'Invoice total' => 'total' ), array_column( $a['left_out'], 'reason', 'text' ) );
$blank_count = OI::analyse( array( array( 'fish', 'received', 'price' ), array( 'Blue Test Wrasse', '', '2.50' ), array( 'Red Test Goby', '3', '1.25' ) ), array( 'mode' => 'arrived', 'map' => array( 0 => 'fish', 1 => 'received', 2 => 'price' ), 'header_row' => 0 ) );
check( 'on an invoice, a fish whose count was left blank is left out and NAMED — so the owner looks at the invoice; on an order sheet it is simply a row nobody ordered', array( 'Blue Test Wrasse' => 'zero' ) === array_column( $blank_count['left_out'], 'reason', 'text' ) && 1 === count( $blank_count['rows'] ) && array() === OI::analyse( array( array( 'fish', 'ordered', 'price' ), array( 'Blue Test Wrasse', '', '2.50' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'ordered', 2 => 'price' ), 'header_row' => 0 ) )['left_out'] );
check( 'a fee line is NEVER a fish line: it is kept apart, with its amount', 1 === count( $a['fees'] ) && 'Packing charge' === $a['fees'][0]['name'] && 12.0 === $a['fees'][0]['amount'] && 'import' === $a['fees'][0]['kind'] && ! in_array( 'Packing charge', array_column( $a['rows'], 'typed' ), true ) );
check( 'the invoice\'s own subtotal and total are read off the totals lines', 17.5 === $a['subtotal'] && 29.5 === $a['total'] );
$bad = $a['rows'][2];
check( 'numbers that would not parse are flagged on their cell: the formula-looking quantity and the decimal-comma price', in_array( 'qty', $bad['flags'], true ) && in_array( 'price', $bad['flags'], true ) && true === $bad['bad_qty'] && null === $bad['arrived'] && null === $bad['price'] );
check( 'a formula-looking NAME is kept as text', '=HYPERLINK("x")' === OI::analyse( array( array( 'fish', 'qty' ), array( '=HYPERLINK("x")', '2' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'qty' ), 'header_row' => 0 ) )['rows'][0]['typed'] );
$learned = OI::analyse( $grid['grid'], array( 'mode' => 'arrived', 'map' => $found['map'], 'header_row' => $found['row'], 'junk' => array( 'red test GOBY' ) ) );
check( 'a row the owner taught the layout to leave out is left out by its text', array( 'Blue Test Wrasse', 'Test Shrimp (INV)' ) === array_column( $learned['rows'], 'typed' ) && in_array( 'learned', array_column( $learned['left_out'], 'reason' ), true ) );
$both = OI::analyse( array( array( 'fish', 'ordered', 'received' ), array( 'Blue Test Wrasse', '12', '8' ), array( 'Red Test Goby', '6', '' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'ordered', 2 => 'received' ), 'header_row' => 0 ) );
check( 'a sheet with ordered and received: both are read, each from its own column', array( 12, 6 ) === array_column( $both['rows'], 'ordered' ) && array( 8, null ) === array_column( $both['rows'], 'received' ) );
$only = OI::analyse( array( array( 'fish', 'ordered' ), array( 'Blue Test Wrasse', '12' ) ), array( 'mode' => 'arrived', 'map' => array( 0 => 'fish', 1 => 'ordered' ), 'header_row' => 0 ) );
check( 'a row with only "ordered": in the arrived mode that one quantity is what arrived', 12 === $only['rows'][0]['arrived'] && 12 === $only['rows'][0]['ordered'] && null === $only['rows'][0]['received'] );
$came_map = array( 0 => 'fish', 1 => 'ordered', 2 => 'received', 3 => 'price' );
$came_grid = array(
	array( 'fish', 'ordered', 'received', 'price' ),
	array( 'Blue Test Wrasse', '12', '8', '2.50' ),
	array( 'Zzyzx Whatsit', '0', '2', '9.00' ),
	array( 'Pale Test Tang', '', '1', '30' ),
	array( 'Green Test Chromis', '0', '0', '0.75' ),
	array( 'Red Test Goby', '', '', '1.25' ),
);
$came      = OI::analyse( $came_grid, array( 'mode' => 'ordered', 'map' => $came_map, 'header_row' => 0 ) );
check_same( 'ORDERED, a sheet with a received column: a row with 0 (or no) order quantity and a received count is KEPT — ordered 0, its received count, flagged', array( array( 'Blue Test Wrasse', 'Zzyzx Whatsit', 'Pale Test Tang' ), array( 12, 0, 0 ), array( 8, 2, 1 ), array( false, true, true ) ), array( array_column( $came['rows'], 'typed' ), array_column( $came['rows'], 'ordered' ), array_column( $came['rows'], 'received' ), array_column( $came['rows'], 'unasked' ) ) );
check( '… counted apart from the rows with an order quantity; a price-list row with neither is still left out and only counted', 1 === $came['with_qty'] && 2 === $came['unasked'] && 5 === $came['data_rows'] && 3 === $came['received'] && array() === $came['left_out'] );
$came_qty = OI::analyse( array( array( 'fish', 'qty', 'shipped' ), array( 'Blue Test Wrasse', '4', '4' ), array( 'Zzyzx Whatsit', '', '3' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'qty', 2 => 'received' ), 'header_row' => 0 ) );
check( '… the same when the order quantity stands in a plain "qty" column', array( 4, 0 ) === array_column( $came_qty['rows'], 'ordered' ) && array( 4, 3 ) === array_column( $came_qty['rows'], 'received' ) && array( false, true ) === array_column( $came_qty['rows'], 'unasked' ) );
$no_col = OI::analyse( array( array( 'fish', 'ordered', 'price' ), array( 'Blue Test Wrasse', '12', '2.50' ), array( 'Zzyzx Whatsit', '0', '9.00' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'ordered', 2 => 'price' ), 'header_row' => 0 ) );
check( '… and without a received column nothing changes: the 0 row is a price-list row nobody ordered', array( 'Blue Test Wrasse' ) === array_column( $no_col['rows'], 'typed' ) && 0 === $no_col['unasked'] && false === $no_col['rows'][0]['unasked'] );
$bad_came = OI::analyse( array( array( 'fish', 'ordered', 'received' ), array( 'Blue Test Wrasse', '12', '8' ), array( 'Zzyzx Whatsit', '0', '=1+1' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'ordered', 2 => 'received' ), 'header_row' => 0 ) );
check( '… a received count that would not parse is flagged on its cell as it always was — never read as "arrived"', 2 === count( $bad_came['rows'] ) && true === $bad_came['rows'][1]['bad_qty'] && false === $bad_came['rows'][1]['unasked'] && 0 === $bad_came['unasked'] );
$none_ordered = OI::analyse( array( array( 'fish', 'ordered', 'received' ), array( 'Blue Test Wrasse', '', '8' ), array( 'Zzyzx Whatsit', '0', '2' ) ), array( 'mode' => 'ordered', 'map' => array( 0 => 'fish', 1 => 'ordered', 2 => 'received' ), 'header_row' => 0 ) );
check( 'ORDERED, and NOT ONE row has an order quantity: that sheet is what ARRIVED — nothing is listed, as before (the preview offers the other button)', array() === $none_ordered['rows'] && 0 === $none_ordered['with_qty'] && 0 === $none_ordered['unasked'] && 2 === $none_ordered['received'] && array() === $none_ordered['sections'] );
$came_arrived = OI::analyse( $came_grid, array( 'mode' => 'arrived', 'map' => $came_map, 'header_row' => 0 ) );
check_same( 'ARRIVED is as it was: the rows with a received count, the two without one left out and named, nothing flagged', array( array( 'Blue Test Wrasse', 'Zzyzx Whatsit', 'Pale Test Tang' ), array( 12, 0, null ), array( 8, 2, 1 ), array( false, false, false ), array( 'Green Test Chromis' => 'zero', 'Red Test Goby' => 'zero' ), 3, 0 ), array( array_column( $came_arrived['rows'], 'typed' ), array_column( $came_arrived['rows'], 'ordered' ), array_column( $came_arrived['rows'], 'arrived' ), array_column( $came_arrived['rows'], 'unasked' ), array_column( $came_arrived['left_out'], 'reason', 'text' ), $came_arrived['with_qty'], $came_arrived['unasked'] ) );
check( 'the words of every reason', '' !== OI::reason_text( 'header' ) && OI::reason_text( 'total' ) !== OI::reason_text( 'heading' ) && '' !== OI::reason_text( 'anything' ) );

echo "7. The real shapes: a price list with an ORDER column\n";
$adjectives = array( 'Blue', 'Red', 'Green', 'Golden', 'Spotted', 'Striped', 'Dusky', 'Pale', 'Banded', 'Dotted', 'Lined', 'Masked' );
$nouns      = array( 'Wrasse', 'Goby', 'Chromis', 'Blenny', 'Tang', 'Angel', 'Basslet', 'Hawk', 'Damsel', 'Anthias' );
$suffixes   = array( '', ' (L) (10-14 CM)', ' M (6-9,5 cm)', ' - S', ' S/M', ' Mated Pair', ' (INV)', ' NEW', ' (Juv)', ' LG/XL' );
$lines      = array( 'Example Aquatics Export', '12 Sample Street, Testville', 'PRICE LIST AND ORDER FORM', '', "CODE\tSTOCK\tORDER\tENGLISH NAME\tLATIN NAME\tQTY/BOX\tPRICE US\$\tLOCAL NAME\t\tBOXES" );
$ordered    = 0;
for ( $i = 0; $i < 1200; $i++ ) {
	$name   = $adjectives[ $i % 12 ] . ' Test ' . $nouns[ intdiv( $i, 12 ) % 10 ] . ' ' . ( 1 + intdiv( $i, 120 ) ) . $suffixes[ intdiv( $i, 20 ) % 10 ];
	$order  = 0 === $i % 20 ? (string) ( 1 + $i % 7 ) : ( 7 === $i % 20 ? '0' : '' );
	$stock  = array( '2m', '1sm', '12', '', '5' )[ $i % 5 ];
	$price  = 13 === $i % 50 ? '0' : (string) ( ( 3 + $i % 40 ) / 9 );
	$fish   = 13 === $i % 50 ? '' : $name;
	$latin  = 13 === $i % 50 ? '' : 'Testus exemplum';
	$boxes  = '' !== $order && '0' !== $order ? '=C' . ( $i + 6 ) . '/F' . ( $i + 6 ) : '';
	$ordered += '' !== $order && '0' !== $order ? 1 : 0;
	$lines[] = implode( "\t", array( sprintf( 'XA%04d', $i ), $stock, $order, $fish, $latin, (string) ( 6 + $i % 30 ), $price, 'nama ' . $i, '', $boxes ) );
}
$start = microtime( true );
$price = OI::grid_from_text( implode( "\n", $lines ) );
$head  = OI::find_header( $price['grid'] );
$list  = OI::analyse( $price['grid'], array( 'mode' => 'ordered', 'map' => $head['map'], 'header_row' => $head['row'], 'sizes' => $sizes ) );
$took  = microtime( true ) - $start;
check( 'a 1,200-row price list is read whole (the limit is on the lines created, not the rows read)', 1204 === count( $price['grid'] ) && false === $price['truncated'] );
check_same( 'the header row is found under three title rows; ORDER is the ordered quantity, STOCK is the stock, QTY/BOX the per-box count; the spacer and the formula column are nobody\'s', array( 0 => 'code', 1 => 'stock', 2 => 'ordered', 3 => 'fish', 4 => 'sci', 5 => 'per_box', 6 => 'price' ), $head['map'] );
check( 'ONLY the rows with an order quantity above 0 become lines: 60 of 1,200', 60 === $ordered && 60 === count( $list['rows'] ) && 60 === $list['with_qty'] && 1200 === $list['data_rows'] );
check( 'STOCK ("2m", "1sm", "12") is never taken for a quantity', array() === array_filter( $list['rows'], static function ( $row ) { return $row['ordered'] > 7; } ) );
check( 'the supplier\'s code, the per-box count and the long-float price are read on every line', 'XA0000' === $list['rows'][0]['code'] && 6 === $list['rows'][0]['per_box'] && abs( $list['rows'][0]['price'] - 3 / 9 ) < 1e-6 && 60 === count( array_filter( array_column( $list['rows'], 'code' ) ) ) );
check( 'the rows with only a code and a 0 price, and the rows nobody ordered, are not listed one by one', count( $list['left_out'] ) < 5 );
$by_size = array();
foreach ( $list['rows'] as $fh_row ) {
	$by_size[ (string) $fh_row['size']['slug'] ][] = $fh_row;
}
check( 'sizes inside the names: "(L) (10-14 CM)", "M (6-9,5 cm)" and "- S" are lifted on every row that has them — the name is clean, what was typed is kept', 6 === count( $by_size['large'] ) && 6 === count( $by_size['medium'] ) && 6 === count( $by_size['small'] ) && false === strpos( $by_size['large'][0]['name'], '(' ) && false !== strpos( $by_size['large'][0]['typed'], '(L) (10-14 CM)' ) && 1 === preg_match( '/ \d+$/', $by_size['medium'][0]['name'] ) && 1 === preg_match( '/ \d+$/', $by_size['small'][0]['name'] ), json_encode( array_map( 'count', $by_size ) ) );
$kept = array_column( $by_size[''], 'name' );
check( '… and "S/M", "LG/XL", "(Juv)" (not in this list), "Mated Pair", "(INV)" and "NEW" stay in the name', 6 === count( preg_grep( '/ S\/M$/', $kept ) ) && 6 === count( preg_grep( '/ LG\/XL$/', $kept ) ) && 6 === count( preg_grep( '/ \(Juv\)$/', $kept ) ) && 6 === count( preg_grep( '/ Mated Pair$/', $kept ) ) && 6 === count( preg_grep( '/ \(INV\)$/', $kept ) ) && 6 === count( preg_grep( '/ NEW$/', $kept ) ), json_encode( $kept ) );
check( 'reading and analysing 1,200 rows is quick', $took < 3.0, round( $took, 2 ) . 's' );

echo "8. The real shapes: a multi-box, two-origin invoice block with fee lines\n";
$block = implode(
	"\n",
	array(
		OI::block_header(),
		"Blue Test Wrasse\tTestus caeruleus\tM\t\t8\t2.50\t\t1\t\t\tXA0000\tNorthland\t",
		"Red Test Goby\t\t\t\t6\t1.25\t1\t1\t\t\tXA0020\tNorthland\t?",
		"Red Test Goby\t\t\t\t4\t1.25\t\t2\t\t\tXA0020\tNorthland\t",
		"Green Test Chromis\t\t\t\t20\t0.75\t\t3\t\t\tXB0001\tSouthland\t",
		'',
		OI::fee_header(),
		"Packing charge\t24.00\tNorthland",
		"Freight per kg\t180.00\tNorthland",
		"Re-oxygen and re-water\t30.00\tSouthland",
		"Fuel surcharge (check)\t15.00\t",
		"Tariff 10%\t5.25\t",
		"Heat packs\tabc\tNorthland",
		"Livestock subtotal\t47.50\t",
		"Invoice total\t301.75\t",
	)
);
$bg = OI::grid_from_text( $block );
$bh = OI::find_header( $bg['grid'] );
$b  = OI::analyse( $bg['grid'], array( 'mode' => 'arrived', 'map' => $bh['map'], 'header_row' => $bh['row'], 'sizes' => $sizes ) );
check_same( 'the block\'s own header maps every column', array( 'fish', 'sci', 'size', 'ordered', 'received', 'price', 'doa', 'box', 'pct', 'label', 'code', 'origin', 'check' ), array_values( $bh['map'] ) );
check( 'four fish rows, the box, the origin and the code on each; the "?" row is flagged', 4 === count( $b['rows'] ) && array( 1, 1, 2, 3 ) === array_column( $b['rows'], 'box' ) && array( 'Northland', 'Northland', 'Northland', 'Southland' ) === array_column( $b['rows'], 'origin' ) && array( false, true, false, false ) === array_column( $b['rows'], 'check' ) && 'XA0020' === $b['rows'][1]['code'] && 1 === $b['rows'][1]['doa'] );
check_same( 'the sections of the invoice, with their rows', array( 'Northland' => 3, 'Southland' => 1 ), $b['sections'] );
check( 'the fee part: six fee lines, none of them a fish; the tariff is told apart; an unreadable or "(check)" amount is marked', 6 === count( $b['fees'] ) && array( 'import', 'import', 'import', 'import', 'tariffs', 'import' ) === array_column( $b['fees'], 'kind' ) && array( false, false, false, true, false, true ) === array_column( $b['fees'], 'check' ) && null === $b['fees'][5]['amount'] );
check( 'the livestock subtotal and the invoice total are read, not counted as fees', 47.5 === $b['subtotal'] && 301.75 === $b['total'] );
$all   = OI::fee_sums( $b['fees'] );
$north = OI::fee_sums( $b['fees'], array( 'northland' ) );
check( 'the fees added up: tariffs, and everything else; the unreadable one is not counted', 5.25 === $all['tariffs'] && 249.0 === $all['import'] && 6 === $all['count'] && 1 === $all['check'] );
check( 'for one chosen section: its own fees and the ones that name no section; the other section\'s left out and counted', 5.25 === $north['tariffs'] && 219.0 === $north['import'] && 1 === $north['left_out'] );
$sum = 0.0;
foreach ( $b['rows'] as $row ) {
	$sum += $row['price'] * $row['arrived'];
}
check( 'the rows add up to the invoice\'s own subtotal (8 × 2.50 + 10 × 1.25 + 20 × 0.75)', abs( $sum - $b['subtotal'] ) < 0.005 );
$headings = OI::analyse(
	OI::grid_from_text( "BOX\tCODE\tQTY\tScientific\tCommon\tUnit\tAmt\nBOX NO 3 — Northland\n\tXA1\t5\tTestus caeruleus\tBlue Test Wrasse\t2.00\t10.00\n\tXA2\t2\t\tRed Test Goby\t1.00\t2.00\nBox charge\t\t\t\t\t\t8.00\nBOX NO 4 — Southland\n4\tXB1\t3\t\tGreen Test Chromis\t1.00\t3.00\n\tXB2\t1\t\tPale Test Tang\t9.00\t9.00\nClearance and handling\t\t\t\t\t\t40.00\nTOTAL\t\t\t\t\t\t72.00\n" )['grid'],
	array( 'mode' => 'arrived', 'map' => array( 0 => 'box', 1 => 'code', 2 => 'qty', 3 => 'sci', 4 => 'fish', 5 => 'price', 6 => 'amount' ), 'header_row' => 0 )
);
check( 'the invoice as printed — BOX · CODE · QTY · Scientific · Common · Unit · Amt: a box heading, or a box number on the box\'s first row only, is carried down with its origin', array( 3, 3, 4, 4 ) === array_column( $headings['rows'], 'box' ) && array( 'Northland', 'Northland', 'Southland', 'Southland' ) === array_column( $headings['rows'], 'origin' ) );
check( '… its fee lines after each box are fees of that box\'s origin, and its total is a total', array( 'Northland', 'Southland' ) === array_column( $headings['fees'], 'origin' ) && array( 8.0, 40.0 ) === array_column( $headings['fees'], 'amount' ) && 72.0 === $headings['total'] && 4 === count( $headings['rows'] ) );
check( 'fee words: packing, freight, clearance, re-oxygen, heat and ice packs, a surcharge → the import & re-ox field; a tariff or duty → tariffs; a fish is neither', 'import' === OI::fee_kind( 'Packing / box charge' ) && 'import' === OI::fee_kind( 'Freight per kg' ) && 'import' === OI::fee_kind( 'Clearance' ) && 'import' === OI::fee_kind( 'Re-ox' ) && 'import' === OI::fee_kind( 'Ice packs' ) && 'import' === OI::fee_kind( 'Fuel surcharge' ) && 'tariffs' === OI::fee_kind( 'Tariff' ) && 'tariffs' === OI::fee_kind( 'Import duty' ) && null === OI::fee_kind( 'Blue Test Boxfish' ) && null === OI::fee_kind( 'Fire Test Goby' ) );
check_same( 'a fee field: empty → the invoice\'s amount on the first tick; typed → kept, replaced only on the second tick; never added to; nothing without a tick', array( 24.0, null, 24.0, null, null ), array( OI::fee_value( '0.00', 24, true, false ), OI::fee_value( '10.00', 24, true, false ), OI::fee_value( '10.00', 24, true, true ), OI::fee_value( '0', 24, false, false ), OI::fee_value( '', 0, true, true ) ) );

echo "9. The reconciliation\n";
$line = static function ( $key, $species, $ordered, array $more = array() ) {
	return array_merge( array( 'key' => (string) $key, 'species' => $species, 'species_id' => null, 'size_slug' => null, 'qty_ordered' => $ordered, 'qty_received' => null, 'unit_price' => null, 'qty_doa' => 0, 'box_no' => null, 'box_pct' => null, 'stay_id' => null, 'notes' => '' ), $more );
};
$row = static function ( $n, $name, $arrived, array $more = array() ) {
	return array_merge( array( 'n' => $n, 'typed' => $name, 'keys' => array( strtolower( $name ) ), 'species_id' => null, 'size' => array( 'slug' => null ), 'code' => '', 'arrived' => $arrived, 'box' => null, 'on' => true ), $more );
};
$order = array(
	$line( 0, 'Blue Test Wrasse', 12 ),
	$line( 1, 'Red Test Goby', 10, array( 'notes' => '[code XA0020] [as written: Goby rouge test]' ) ),
	$line( 2, 'Green Test Chromis', 20 ),
	$line( 3, 'Pale Test Tang', 2 ),
	$line( 4, 'Dusky Test Angel', 3, array( 'stay_id' => 77, 'qty_received' => 3 ) ),
	$line( 5, 'Banded Test Hawk', 4, array( 'qty_received' => 4 ) ),
	$line( 6, 'Blue Test Wrasse', 5, array( 'size_slug' => 'large' ) ),
);
$came  = array(
	$row( 10, 'Blue Test Wrasse', 12 ),
	$row( 11, 'Something else entirely', 6, array( 'code' => 'xa0020', 'box' => 1 ) ),
	$row( 12, 'Goby rouge test', 6, array( 'box' => 2 ) ),
	$row( 13, 'Green Test Chromis', 25 ),
	$row( 14, 'Masked Test Damsel', 7 ),
	$row( 15, 'Dusky Test Angel', 3 ),
	$row( 16, 'Blue Test Wrasse', 5, array( 'size' => array( 'slug' => 'large' ) ) ),
	$row( 17, 'Lined Test Basslet', 2, array( 'on' => false ) ),
);
$r     = OI::reconcile( $came, $order );
$of    = static function ( array $entries ) {
	return array_column( $entries, 'line' );
};
check_same( 'Came as ordered: the plain line, and the Large line through its size', array( '0', '6' ), $of( $r['groups']['same'] ) );
check_same( 'Came short / over: the two boxes of the goby added up to 12 of 10 (over), the chromis 25 of 20', array( '1', '2' ), $of( $r['groups']['diff'] ) );
check( '… paired by the supplier\'s CODE first (any case) whatever the invoice calls the fish, then by what the line was written as; the boxes noted', array( 11, 12 ) === $r['lines']['1']['rows'] && 12 === $r['lines']['1']['arrived'] && array( 1, 2 ) === $r['lines']['1']['boxes'] && 'code' === $r['lines']['1']['how'] );
check_same( 'Did not come: the ordered line no row matched — received becomes 0 and the line STAYS', array( '3' => 0 ), array( '3' => $r['lines']['3']['arrived'] ) );
check( '… a line that already holds a received count is kept as it is, and said so', array( '3', '5' ) === $of( $r['groups']['missing'] ) && ! empty( $r['groups']['missing'][1]['keep'] ) && ! isset( $r['lines']['5'] ) );
check_same( 'Extra: the row no ordered line matched', array( 14 ), $r['extra'] );
check( 'a line that has a stay is never changed: the row that pairs with it is set aside', array( '4' ) === $of( $r['groups']['stay'] ) && ! isset( $r['lines']['4'] ) );
check( 'an unticked row is nobody\'s', ! in_array( 17, $r['extra'], true ) );
check_same( 'the foot: ordered 56 (every line), arrived 12 + 12 + 25 + 0 + 3 + 4 + 5 + the extra 7 = 68', array( 56, 68, 1 ), array( $r['foot']['ordered'], $r['foot']['arrived'], $r['foot']['missing'] ) );
check_same( '… said the way the owner says it', 'Ordered 150 · arrived 104 · 69% filled · 11 kinds did not come', OI::foot_text( array( 'ordered' => 150, 'arrived' => 104, 'filled' => 104 / 150, 'missing' => 11 ) ) );
check_same( '… with nothing ordered there is no percent, and with nothing missing no kinds', 'Ordered 0 · arrived 7', OI::foot_text( array( 'ordered' => 0, 'arrived' => 7, 'filled' => null, 'missing' => 0 ) ) );
$sep = OI::reconcile( $came, $order, array( 'same_fish' => 'separate' ) );
check( 'the same fish in two boxes, kept as two lines at the owner\'s choice: the first row fills the ordered line, the second is an extra', array( 11 ) === $sep['lines']['1']['rows'] && 6 === $sep['lines']['1']['arrived'] && array( 12, 14 ) === $sep['extra'] );
$hand = OI::reconcile( $came, $order, array( 'pairs' => array( 14 => 'instead:3', 13 => 'new', 10 => 'line:2' ), 'lines' => array( '5' => 'zero', '3' => 'zero' ) ) );
check( 'a substitution paired by hand: the row is an extra "instead of" the ordered line, which stays at 0', array( 'row' => 14, 'arrived' => 7, 'instead' => '3', 'could' => array() ) === $hand['groups']['extra'][1] && 0 === $hand['lines']['3']['arrived'] );
check( 'an owner-corrected pairing wins over the matcher\'s; "a new line" takes a row away from its line', array( 10 ) === $hand['lines']['2']['rows'] && 'hand' === $hand['lines']['2']['how'] && in_array( 13, $hand['extra'], true ) && 0 === $hand['lines']['0']['arrived'] );
check( 'a line the owner says did not come goes to 0 even when it held a count', 0 === $hand['lines']['5']['arrived'] );
check( 'never ordered and nothing matched (an extra of an earlier import): left alone', ! isset( OI::reconcile( array(), array( $line( 0, 'Old Extra', 0, array( 'qty_received' => 3 ) ), $line( 1, 'Never Came', 0 ) ) )['lines']['0'] ) && ! isset( OI::reconcile( array(), array( $line( 1, 'Never Came', 0 ) ) )['lines']['1'] ) );

echo "10. Notes\n";
check_same( 'the supplier\'s code lives in the line\'s notes', array( 'XA0020', '' ), array( OI::code_of( '[code XA0020] [as written: Goby]' ), OI::code_of( 'plain note' ) ) );
check_same( 'a tag is set once, the tags first, brackets in a value made harmless, 500 characters at most', array( '[code B] free note', '[as written: Goby (x)] note', 500 ), array( OI::note_with( '[code A] free note', OI::NOTE_CODE, 'B' ), OI::note_with( 'note', OI::NOTE_WRITTEN, 'Goby [x]' ), strlen( OI::note_with( str_repeat( 'n', 600 ), OI::NOTE_CODE, 'B' ) ) ) );
check_same( 'an empty value takes the tag away', 'free note', OI::note_with( '[code A] free note', OI::NOTE_CODE, '' ) );

echo "11. The instruction text and the documented header\n";
$text   = OI::instructions();
$readme = (string) file_get_contents( dirname( __DIR__ ) . '/README.md' );
check( 'the copied text asks for exactly the header the importer reads — tab-separated', false !== strpos( $text, implode( "\t", OI::BLOCK_HEADER ) ) && false !== strpos( $text, implode( "\t", OI::FEE_HEADER ) ) );
check( '… the README documents the same header, column for column (one test reads both)', false !== strpos( $readme, '`' . implode( ', ', OI::BLOCK_HEADER ) . '`' ) && false !== strpos( $readme, '`' . implode( ', ', OI::FEE_HEADER ) . '`' ) );
check_same( '… fish, scientific name, size, ordered, received, price, DOA, box, percent, label — then code, origin, and "check" last', array( 'fish', 'scientific name', 'size', 'ordered', 'received', 'price', 'DOA', 'box', 'percent', 'label', 'code', 'origin', 'check' ), OI::BLOCK_HEADER );
check( '… and the importer maps its own header without a dropdown', OI::map_complete( OI::roles_of_row( OI::BLOCK_HEADER ) ) && count( OI::BLOCK_HEADER ) === count( OI::roles_of_row( OI::BLOCK_HEADER ) ) );
check( 'it covers what these invoices hold: several boxes, the origin, waybill pages, hand-written amounts, collided cells, the fees and the subtotal; nothing invented, no totals', 1 === preg_match( '/BOX NO 3/', $text ) && false !== stripos( $text, 'air waybill' ) && false !== stripos( $text, 'hand-written' ) && false !== stripos( $text, 'ran into the cell' ) && false !== stripos( $text, 'Never invent' ) && false !== stripos( $text, 'No totals' ) && false !== stripos( $text, 'Livestock subtotal' ) && false !== stripos( $text, 'two boxes is two rows' ) );
check( 'it names no product and no model', 0 === preg_match( '/claude|chatgpt|gpt|gemini|copilot|openai|anthropic|llm|\bAI\b/i', $text ) );

echo "12. A letterhead above the header, fee lines under any heading, fish with a fee's word\n";
$letter = array( array( 'Customer Name:', 'Test Shop', 'Order No:', '1234' ), array( 'CODE', 'STOCK', 'ORDER', 'ENGLISH NAME', 'PRICE US$' ), array( 'A2', '2m', '6', 'Blue Test Wrasse', '2.5' ), array( 'A3', '40', '', 'Red Test Goby', '1' ) );
$lh     = OI::find_header( $letter );
check( 'a letterhead row ("Customer Name: … Order No: …") is no header: the header is the row with the most headings', 1 === $lh['row'] && array( 'code', 'stock', 'ordered', 'fish', 'price' ) === array_values( $lh['map'] ) );
check( '… "Customer Name" is not the fish column and "Order No" not the order quantity', '' === OI::header_role( 'Customer Name:' ) && '' === OI::header_role( 'Order No:' ) && '' === OI::header_role( 'Order Date' ) && '' === OI::header_role( 'Ship to' ) && 'ordered' === OI::header_role( 'Order Qty' ) && 'fish' === OI::header_role( 'Common Name' ) && 'code' === OI::header_role( 'Supplier Code' ) );
$lh2 = OI::find_header( array( array( 'Name', 'Order' ), array( 'Test Shop', '1234' ), array( 'CODE', 'STOCK', 'ORDER', 'ENGLISH NAME' ), array( 'A2', '2m', '6', 'Blue Test Wrasse' ) ) );
check( '… and two letterhead cells that DO read as "fish" and "order" lose to the real header under them — the stock column is never the quantity', 2 === $lh2['row'] && 'stock' === $lh2['map'][1] && 'ordered' === $lh2['map'][2] );
$la = OI::analyse( $letter, array( 'mode' => 'ordered', 'map' => $lh['map'], 'header_row' => $lh['row'], 'sizes' => $sizes ) );
check( '… so the line is the fish with its order quantity: "Blue Test Wrasse × 6", one of two rows', 1 === count( $la['rows'] ) && 'Blue Test Wrasse' === $la['rows'][0]['name'] && 6 === $la['rows'][0]['ordered'] && 'A2' === $la['rows'][0]['code'] && 2 === $la['data_rows'] );
check( 'a complete header (a fish and a quantity) wins over a row with more headings that has neither', 1 === OI::find_header( array( array( 'Code', 'Stock', 'Price', 'Box' ), array( 'Fish', 'Qty' ), array( 'Blue Test Wrasse', '4' ) ) )['row'] );

$inv_head = array( 'BOX', 'CODE', 'QTY', 'Scientific', 'Common', 'Unit', 'Amt' );
$inv_map  = OI::roles_of_row( $inv_head );
$inv      = OI::analyse(
	array(
		$inv_head,
		array( '1', 'XA1', '4', 'Testus caeruleus', 'Blue Test Wrasse', '2.50', '10.00' ),
		array( '', '', '38', 'Freight (kg)', '', '4.50', '171.00' ),
		array( '', '', '', '', 'Box charge', '', '12.00' ),
		array( '', '', '1', 'Fuel surcharge', '', '', '' ),
	),
	array( 'mode' => 'arrived', 'map' => $inv_map, 'header_row' => 0, 'sizes' => $sizes )
);
check( 'a fee line is NEVER a fish, under whichever heading its words stand: "Freight (kg)" in the scientific-name column with 38 in QTY makes no line', 1 === count( $inv['rows'] ) && 'Blue Test Wrasse' === $inv['rows'][0]['name'] && 1 === $inv['with_qty'] );
check_same( '… it is a fee, with the line\'s own amount; one without an amount is kept apart to be checked', array( array( 'Freight (kg)', 171.0, false ), array( 'Box charge', 12.0, false ), array( 'Fuel surcharge', null, true ) ), array_map( static function ( $f ) { return array( $f['name'], $f['amount'], $f['check'] ); }, $inv['fees'] ) );
check( '… and the rows left out name it', 3 === count( preg_grep( '/a fee line/', array_map( static function ( $l ) { return OI::reason_text( $l['reason'] ); }, $inv['left_out'] ) ) ) && 'Freight (kg)' === $inv['left_out'][0]['text'] );

$block_map = OI::roles_of_row( OI::BLOCK_HEADER );
$named     = OI::analyse(
	array(
		OI::BLOCK_HEADER,
		array( 'Opal Mock Clam (INV) CITES', '', '', '', '5', '9.00', '', '3', '', '', '', '', '' ),
		array( 'Blue Pallet Test Tang', '', '', '', '2', '30', '', '3', '', '', '', '', '' ),
		array( 'Sample Seahorse (handling stock)', 'Testus equus', '', '', '4', '12', '', '3', '', '', '', '', '' ),
		array( 'Clearance Test Goby', '', '', '', '6', '1', '', '3', '', '', 'XC9', '', '' ),
		array( 'Heat packs', '', '', '', '2', '9.00', '', '3', '', '', '', '', '' ),
	),
	array( 'mode' => 'arrived', 'map' => $block_map, 'header_row' => 0, 'sizes' => $sizes )
);
check_same( 'a fish is not dropped for a word in its name: "CITES" and "Pallet" are no fee words; a row with a scientific name or a supplier\'s code is a fish whatever its name says', array( 'Opal Mock Clam (INV) CITES', 'Blue Pallet Test Tang', 'Sample Seahorse (handling stock)', 'Clearance Test Goby' ), array_column( $named['rows'], 'typed' ) );
check_same( '… the two that carry a fee\'s word are flagged for the owner to look at, the others are not', array( false, false, true, true ), array_column( $named['rows'], 'fee_word' ) );
check( '… and a real fee among them is worth its price × its quantity — never its box number', 1 === count( $named['fees'] ) && 'Heat packs' === $named['fees'][0]['name'] && 18.0 === $named['fees'][0]['amount'] );
check( 'the fee words still catch the fees', null === OI::fee_kind( 'Opal Mock Clam CITES' ) && null === OI::fee_kind( 'Blue Pallet Test Tang' ) && 'import' === OI::fee_kind( 'Clearance / handling' ) && 'import' === OI::fee_kind( 'Health certificate' ) );

echo "13. A packing list by scientific name alone\n";
$sci_head = array( 'Scientific', 'Qty', 'Unit' );
check( 'a scientific-name column and a quantity can be saved from: the scientific name stands in for the fish', OI::map_complete( OI::roles_of_row( $sci_head ) ) && ! OI::map_complete( array( 0 => 'sci' ) ) && ! OI::map_complete( array( 0 => 'code', 1 => 'qty' ) ) );
$sci_rows = OI::analyse( array( $sci_head, array( 'Testus caeruleus', '4', '2.50' ), array( 'Testus pallidus', '2', '30' ) ), array( 'mode' => 'arrived', 'map' => OI::roles_of_row( $sci_head ), 'header_row' => 0, 'sizes' => $sizes ) );
check_same( '… each row is that fish, typed as the list writes it', array( array( 'Testus caeruleus', 'Testus caeruleus', 4 ), array( 'Testus pallidus', 'Testus pallidus', 2 ) ), array_map( static function ( $r ) { return array( $r['typed'], $r['sci'], $r['arrived'] ); }, $sci_rows['rows'] ) );
$blank = OI::analyse( array( OI::BLOCK_HEADER, array( '', 'Testus caeruleus', '', '', '4', '2.50', '', '1', '', '', '', '', '' ), array( 'Red Test Goby', '', '', '', '6', '1', '', '1', '', '', '', '', '' ) ), array( 'mode' => 'arrived', 'map' => $block_map, 'header_row' => 0, 'sizes' => $sizes ) );
check_same( '… and in the assistant\'s block a blank fish with a scientific name is that fish, not "??"', array( 'Testus caeruleus', 'Red Test Goby' ), array_column( $blank['rows'], 'name' ) );
$sci_index = OI::index_candidates( array( array( 'product_id' => 0, 'name' => 'Blue Test Wrasse', 'variants' => array( array( 'text' => 'Blue Test Wrasse', 'kind' => 'common' ), array( 'text' => 'Testus caeruleus', 'kind' => 'sci' ) ) ) ) );
$sci_match = OI::match( 'Testus caeruleus', 'Testus caeruleus', $sci_index );
check( '… which the matcher then finds exactly', null !== $sci_match['pick'] && 'Blue Test Wrasse' === $sci_match['candidates'][ $sci_match['pick'] ]['name'] && false === $sci_match['flag'] );

echo "14. Codes that contradict the fish, lost leading zeros, a row without a size\n";
$lines2 = array(
	$line( 0, 'Red Test Goby', 7, array( 'notes' => '[code XA2]' ) ),
	$line( 1, 'Green Test Chromis', 20, array( 'notes' => '[code XA4]' ) ),
	$line( 2, 'Pale Test Tang', 3, array( 'size_slug' => 'small', 'notes' => '[code 0058]' ) ),
	$line( 3, 'Pale Test Tang', 2, array( 'size_slug' => 'large', 'notes' => '[code 0059]' ) ),
	$line( 4, 'Dusky Test Angel', 1, array( 'size_slug' => 'small' ) ),
	$line( 5, 'Dusky Test Angel', 1, array( 'size_slug' => 'large' ) ),
	$line( 6, 'Banded Test Hawk', 2 ),
	$line( 7, 'Banded Test Hawk', 2, array( 'size_slug' => 'large' ) ),
);
$r2 = OI::reconcile(
	array(
		$row( 10, 'Green Test Chromis', 7, array( 'code' => 'XA2' ) ),
		$row( 11, 'Pale Test Tang', 2, array( 'code' => '59' ) ),
		$row( 12, 'Dusky Test Angel', 1 ),
		$row( 13, 'Banded Test Hawk', 2 ),
		$row( 14, 'Goby as the invoice spells it', 7, array( 'code' => 'xa2' ) ),
	),
	$lines2
);
check( 'a row that IS another line\'s fish goes to that line whatever its code says: the chromis is not added onto the goby, and it is said', array( 10 ) === $r2['lines']['1']['rows'] && 'fish' === $r2['lines']['1']['how'] && true === $r2['lines']['1']['clash'] && 7 === $r2['lines']['1']['arrived'] );
check( '… while a row whose name is on no line is still paired by its code', array( 14 ) === $r2['lines']['0']['rows'] && 'code' === $r2['lines']['0']['how'] && false === $r2['lines']['0']['clash'] );
check_same( 'a code is one code with or without its leading zeros ("0059" on the sheet, 59 on the invoice)', array( '59', '59', 'xa0020', '000', '' ), array( OI::code_key( '0059' ), OI::code_key( 59 ), OI::code_key( ' XA0020 ' ), OI::code_key( '000' ), OI::code_key( null ) ) );
check( '… so the size-less invoice row goes to the Large line its code names, not to the Small one', array( 11 ) === $r2['lines']['3']['rows'] && 'code' === $r2['lines']['3']['how'] && 0 === $r2['lines']['2']['arrived'] );
$extra2 = array_column( $r2['groups']['extra'], null, 'row' );
check( 'a row without a size against lines of two sizes is paired with NEITHER: it waits among the extras, with the lines it could be', isset( $extra2[12] ) && array( '4', '5' ) === $extra2[12]['could'] && 0 === $r2['lines']['4']['arrived'] && 0 === $r2['lines']['5']['arrived'] );
check( '… a row without a size takes the line without a size when there is one', array( 13 ) === $r2['lines']['6']['rows'] && ! isset( $extra2[13] ) );
check( '… and the owner\'s word settles it', array( 12 ) === OI::reconcile( array( $row( 12, 'Dusky Test Angel', 1 ) ), $lines2, array( 'pairs' => array( 12 => 'line:5' ) ) )['lines']['5']['rows'] );

echo "15. What a chat assistant does to a table\n";
$fence = "Here is the block:\n\n```text\nfish\treceived\tprice\nBlue Test Wrasse\t4\t2.50\n```\n";
check_same( 'the lines of a code fence go', array( array( 'Here is the block:', '', '' ), array( 'fish', 'received', 'price' ), array( 'Blue Test Wrasse', '4', '2.50' ) ), OI::grid_from_text( $fence )['grid'] );
$md = "| fish | scientific name | received | price |\n|:---|---|:-:|---:|\n| Blue Test Wrasse | Testus caeruleus | 4 | 2.50 |\n| Red Test Goby |  | 6 | 1 |\n";
check_same( 'a Markdown table is read cell by cell, a blank cell kept, its ruler line dropped', array( array( 'fish', 'scientific name', 'received', 'price' ), array( 'Blue Test Wrasse', 'Testus caeruleus', '4', '2.50' ), array( 'Red Test Goby', '', '6', '1' ) ), OI::grid_from_text( $md )['grid'] );
check_same( 'columns set apart by runs of spaces are read when every row is as wide as the header', array( array( 'fish', 'received', 'price' ), array( 'Blue Test Wrasse', '4', '2.50' ), array( 'Red Test Goby', '6', '1' ) ), OI::grid_from_text( "fish    received    price\nBlue Test Wrasse    4       2.50\nRed Test Goby       6       1\n" )['grid'] );
$ran = OI::grid_from_text( "fish    scientific name    received    price\nBlue Test Wrasse    4    2.50\n" )['grid'];
check( '… and NOT when a cell is missing somewhere — a number must never slide into its neighbour\'s column: the text stays one column, and that is noticed', 1 === count( $ran[0] ) && true === OI::run_together( $ran ) && true === OI::run_together( OI::grid_from_text( implode( ' ', OI::BLOCK_HEADER ) . "\nBlue Test Wrasse 4 2.50\n" )['grid'] ) );
check( '… a plain list of names, or a title, did not run together', false === OI::run_together( array( array( 'Blue Test Wrasse' ), array( 'Red Test Goby' ) ) ) && false === OI::run_together( array( array( 'Fish Price List', '' ), array( 'fish', 'qty' ) ) ) );
check( 'a text with tabs is taken as it stands, pipes and all', array( array( 'fish', 'qty' ), array( 'Blue | Test | Wrasse', '4' ) ) === OI::grid_from_text( "fish\tqty\nBlue | Test | Wrasse\t4\n" )['grid'] );
check( 'the instructions ask for a code block and for real tabs', false !== stripos( OI::instructions(), 'inside one code block' ) && false !== stripos( OI::instructions(), 'Real tabs between the columns' ) );
$tail = OI::analyse(
	OI::grid_from_text( implode( "\n", array( OI::block_header(), "Blue Test Wrasse\t\t\t\t4\t2.50\t\t1\t\t\t\t\t", '', OI::fee_header(), "Packing charge\t24.00\t", "Fuel surcharge (check)\t\t", 'Let me know if anything looks off.', '```' ) ) )['grid'],
	array( 'mode' => 'arrived', 'map' => $block_map, 'header_row' => 0, 'sizes' => $sizes )
);
check_same( 'what is written after the fee table is no fee: the two fees, and the closing words left out as not part of the table', array( array( 'Packing charge', 'Fuel surcharge (check)' ), 'not part of the table' ), array( array_column( $tail['fees'], 'name' ), OI::reason_text( end( $tail['left_out'] )['reason'] ) ) );

echo "16. The last size word, and a column that only counts\n";
check_same( 'a size after "(INV)" or "(RARE)" is lifted: the last size word counts, and the name keeps its mark', array( array( 'Opal Mock Clam (INV)', 'medium' ), array( 'Test Tang (RARE)', 'large' ), array( 'Test Clam (INV)', null ) ), array_map( static function ( $n ) use ( $sizes ) { $o = OI::lift_size( $n, '', $sizes ); return array( $o['name'], $o['slug'] ); }, array( 'Opal Mock Clam (INV) M', 'Test Tang (RARE) - L', 'Test Clam (INV)' ) ) );
check_same( 'without a header, a column that counts 1, 2, 3 … is the sheet\'s row number, never the quantity', array( 1 => 'fish', 2 => 'qty', 3 => 'price' ), OI::guess_columns( array( array( '1', 'Blue Test Wrasse', '4', '2.50' ), array( '2', 'Red Test Goby', '6', '3.00' ), array( '3', 'Green Test Chromis', '2', '1.25' ) ) ) );
check_same( '… a quantity column that happens to start 1, 2 is still one', array( 0 => 'fish', 1 => 'qty' ), OI::guess_columns( array( array( 'Blue Test Wrasse', '1' ), array( 'Red Test Goby', '2' ), array( 'Green Test Chromis', '2' ) ) ) );

echo "17. Two lines of one fish in one size\n";
// One fish ordered in two sizes, and a tidy-up of the sizes has made the two sizes one since: the
// lines now read alike. The invoice lists the dearer one first, under a size that is no size any more.
$twins = array(
	$line( 0, 'Banded Test Goby', 4, array( 'size_slug' => '2-3', 'unit_price' => 6.0 ) ),
	$line( 1, 'Banded Test Goby', 2, array( 'size_slug' => '2-3', 'unit_price' => 7.0 ) ),
);
$sized = array( 'slug' => '2-3' );
$rows_of = static function ( array $r ) {
	return array_map( static function ( $entry ) { return $entry['rows']; }, $r['lines'] );
};
$t1 = OI::reconcile( array( $row( 10, 'Banded Test Goby', 2, array( 'price' => 7.0 ) ), $row( 11, 'Banded Test Goby', 4, array( 'size' => $sized, 'price' => 6.0 ) ) ), $twins );
check( 'the row goes to the line with ITS price, not to the first line nobody took: both came as ordered', array( '0' => array( 11 ), '1' => array( 10 ) ) === $rows_of( $t1 ) && array( '0', '1' ) === $of( $t1['groups']['same'] ) && array() === $t1['groups']['diff'] && array() === $t1['extra'] );
$t2 = OI::reconcile( array( $row( 10, 'Banded Test Goby', 2, array( 'size' => $sized, 'price' => 6.9966 ) ), $row( 11, 'Banded Test Goby', 4, array( 'price' => 6.0 ) ) ), $twins );
check( '… the other way round too (the row that still has its size comes first), a price compared to the cent', array( '0' => array( 11 ), '1' => array( 10 ) ) === $rows_of( $t2 ) && array( '0', '1' ) === $of( $t2['groups']['same'] ) );
$t3 = OI::reconcile( array( $row( 10, 'Banded Test Goby', 2, array( 'size' => $sized, 'price' => 6.0, 'box' => 1 ) ), $row( 11, 'Banded Test Goby', 2, array( 'size' => $sized, 'price' => 6.0, 'box' => 2 ) ), $row( 12, 'Banded Test Goby', 2, array( 'size' => $sized, 'price' => 7.0, 'box' => 2 ) ) ), $twins );
check( '… and two boxes of the cheaper one are added up on its line: the second box does not go to the dearer line because that one is still free', array( '0' => array( 10, 11 ), '1' => array( 12 ) ) === $rows_of( $t3 ) && 4 === $t3['lines']['0']['arrived'] && array( 1, 2 ) === $t3['lines']['0']['boxes'] );
$bare = array( $row( 10, 'Banded Test Goby', 2 ), $row( 11, 'Banded Test Goby', 4, array( 'size' => $sized ) ) );
$t4   = OI::reconcile( $bare, $twins );
$x4   = array_column( $t4['groups']['extra'], null, 'row' );
check( 'with no price to go by, nothing tells the two lines apart: each row is paired with NEITHER and waits among the extras, naming both lines', array( 10, 11 ) === $t4['extra'] && array( '0', '1' ) === $x4[10]['could'] && array( '0', '1' ) === $x4[11]['could'] && 0 === $t4['lines']['0']['arrived'] && 0 === $t4['lines']['1']['arrived'] );
$t5 = OI::reconcile( $bare, $twins, array( 'pairs' => array( 10 => 'line:1' ) ) );
check( '… the owner says which line one row is, and the other row takes the line that is left', array( '0' => array( 11 ), '1' => array( 10 ) ) === $rows_of( $t5 ) && array() === $t5['extra'] && 'hand' === $t5['lines']['1']['how'] );
$same_price = array( $line( 0, 'Banded Test Goby', 4, array( 'unit_price' => 6.0 ) ), $line( 1, 'Banded Test Goby', 2, array( 'unit_price' => 6.0 ) ) );
check( 'one fish typed twice at one price, without a size: a price both lines carry settles nothing', array( 10, 11 ) === OI::reconcile( array( $row( 10, 'Banded Test Goby', 2, array( 'price' => 6.0 ) ), $row( 11, 'Banded Test Goby', 4, array( 'price' => 6.0 ) ) ), $same_price )['extra'] );
$as_written = array(
	$line( 0, 'Banded Test Goby', 4, array( 'size_slug' => '2-3', 'notes' => '[as written: Banded Test Goby 2-3"]' ) ),
	$line( 1, 'Banded Test Goby', 2, array( 'size_slug' => '2-3', 'notes' => '[as written: Banded Test Goby 2-3.5"]' ) ),
);
$t6 = OI::reconcile( array( $row( 10, 'Banded Test Goby 2-3.5"', 2, array( 'keys' => array( 'banded test goby', 'banded test goby 2-3.5"' ) ) ), $row( 11, 'Banded Test Goby 2-3"', 4, array( 'keys' => array( 'banded test goby', 'banded test goby 2-3"' ), 'size' => $sized ) ) ), $as_written );
check( 'a line that was WRITTEN as the row is takes it, price or no price', array( '0' => array( 11 ), '1' => array( 10 ) ) === $rows_of( $t6 ) && array() === $t6['extra'] );
$stays = array( $line( 0, 'Banded Test Goby', 4, array( 'stay_id' => 70, 'qty_received' => 4 ) ), $line( 1, 'Banded Test Goby', 2, array( 'stay_id' => 71, 'qty_received' => 2 ) ) );
$t7    = OI::reconcile( array( $row( 10, 'Banded Test Goby', 2 ) ), $stays );
check( 'two such lines that already became stays: the row is set aside as before — it is no extra, and nothing is written', array() === $t7['extra'] && array( '0' ) === $of( $t7['groups']['stay'] ) && array() === $t7['lines'] );

echo "18. Sizes that share a slug, and a bare number\n";
// The shop's own long list holds 3-5" and 3.5"+ — one slug once the dot and the inch mark are dashes.
$long = array(
	array( 'slug' => '3', 'label' => '3"+' ),
	array( 'slug' => '3-4', 'label' => '3-4"' ),
	array( 'slug' => '3-5', 'label' => '3-5"' ),
	array( 'slug' => '3-5-2', 'label' => '3.5"+' ),
	array( 'slug' => '5', 'label' => '5"+' ),
	array( 'slug' => 'all', 'label' => 'All' ),
	array( 'slug' => 'large', 'label' => 'Large' ),
);
$slug_of = static function ( $text ) use ( $long ) {
	$found = OI::standard_size( $text, $long );
	return $found ? $found['slug'] : null;
};
check_same( 'a text that reads as a size\'s own label is that size, whatever other label makes the same slug', array( '3-5', '3-5-2', '3-5', '3-5-2', '3-5' ), array_map( $slug_of, array( '3-5"', '3.5"+', '3-5', '3.5+', "3 - 5\xE2\x80\x9D" ) ) );
check_same( '… in a size column, in the name\'s parenthesis and at the name\'s end', array( array( 'Blue Test Chromis', '3-5' ), array( 'Blue Test Chromis', '3-5' ), array( 'Blue Test Chromis', '3-5' ) ), array_map( static function ( $in ) use ( $long ) { $o = OI::lift_size( $in[0], $in[1], $long ); return array( $o['name'], $o['slug'] ); }, array( array( 'Blue Test Chromis', '3-5"' ), array( 'Blue Test Chromis (3-5")', '' ), array( 'Blue Test Chromis 3-5"', '' ) ) ) );
check_same( 'a bare number is no size: "5" is not 5"+, in a size column or in a parenthesis — the name keeps it', array( null, null, array( 'Blue Test Chromis (5)', null ), array( 'Blue Test Chromis', null, '5' ) ), array( $slug_of( '5' ), $slug_of( '3' ), array_values( array_intersect_key( OI::lift_size( 'Blue Test Chromis (5)', '', $long ), array( 'name' => 1, 'slug' => 1 ) ) ), array_values( array_intersect_key( OI::lift_size( 'Blue Test Chromis', '5', $long ), array( 'name' => 1, 'slug' => 1, 'written' => 1 ) ) ) ) );
check_same( '… the size written out still is one, and so are a size word and a size named in full', array( '5', '5', 'large', 'large', 'all' ), array_map( $slug_of, array( '5"+', '5+', 'LG', 'large', 'All' ) ) );
check_same( '… and a list that holds a size written as a bare number has it', '6', OI::standard_size( '6', array( array( 'slug' => '6', 'label' => '6' ), array( 'slug' => '6-2', 'label' => '6"+' ) ) )['slug'] );
check_same( 'a text two sizes fit equally is still no clean match', null, OI::standard_size( 'M', array( array( 'slug' => 'medium', 'label' => 'Medium' ), array( 'slug' => 'med', 'label' => 'Med' ) ) ) );

fh_finish();
