<?php
/**
 * Lines — the line maths (typed amounts, a flat total as a Discount or an
 * Adjustment line, credits, notes), the description the customer reads
 * and the JSON the theme reads, against tests/fixtures/invoice-maths.json
 * — the same fixture the node test runs the page's script on — and the
 * rule of the stock tick: product_id is present on a ticked line picked
 * from a shop product and absent on every other line.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/lines-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Grammar;
use FisHotel\Misc\Sections\Invoices\Lines;

$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/invoice-maths.json' ), true );
if ( ! is_array( $fixture ) ) {
	fwrite( STDERR, "Cannot read fixtures/invoice-maths.json\n" );
	exit( 1 );
}

function near( $a, $b ) {
	return ( null === $a && null === $b ) || ( null !== $a && null !== $b && abs( (float) $a - (float) $b ) < 1e-9 );
}

echo "1. Typed amounts\n";
foreach ( $fixture['money'] as $row ) {
	$got = Lines::money( $row['typed'] );
	check( 'money( ' . json_encode( $row['typed'], JSON_UNESCAPED_UNICODE ) . ' ) = ' . json_encode( $row['amount'] ), near( $got, $row['amount'] ) && ( null === $got || is_float( $got ) ), var_export( $got, true ) );
}
check( 'a number is taken as it is, to the cent; a huge or non-finite one is no amount', near( Lines::money( 12.5 ), 12.5 ) && near( Lines::money( -3 ), -3.0 ) && null === Lines::money( 1e9 ) && null === Lines::money( INF ) );
check( 'anything that is not text or a number is no amount (never 0)', null === Lines::money( null ) && null === Lines::money( true ) && null === Lines::money( array( '5' ) ) );
check( 'never -0.0', '0' === (string) Lines::money( '-0' ) && 0.0 === Lines::from_cents( 0 ) );
foreach ( $fixture['money_text'] as $row ) {
	check_same( 'money_text( ' . json_encode( $row['amount'] ) . ' )', $row['text'], Lines::money_text( $row['amount'] ) );
}
check_same( 'dollars(): a minus in front of the dollar', array( '$82', '-$82.50', '$0' ), array( Lines::dollars( 82 ), Lines::dollars( -82.5 ), Lines::dollars( 0 ) ) );
check_same( 'price_string(): what WooCommerce is given', array( '599.00', '7.50', '0.00' ), array( Lines::price_string( 599 ), Lines::price_string( 7.5 ), Lines::price_string( 0 ) ) );

echo "2. The fixture: sums, the Discount / Adjustment line, the description lines, the theme's rows\n";
foreach ( $fixture['cases'] as $case ) {
	$sum   = Lines::with_flat_total( Lines::normalize( $case['lines'] ), $case['flat_total'] );
	$texts = array_map( array( Lines::class, 'line_text' ), $sum['lines'] );
	$rows  = Lines::theme_rows( $sum['lines'] );

	check( $case['name'] . ' — subtotal, discount, total', near( $sum['subtotal'], $case['subtotal'] ) && near( $sum['discount'], $case['discount'] ) && near( $sum['total'], $case['total'] ), json_encode( array( $sum['subtotal'], $sum['discount'], $sum['total'] ) ) );
	check( $case['name'] . ' — the lines ALWAYS add up to the total', near( Lines::total( $sum['lines'] ), $sum['total'] ) );
	check_same( $case['name'] . ' — description lines', $case['texts'], $texts );
	check( $case['name'] . ' — rows for the theme', json_encode( $case['rows'] ) === json_encode( $rows ), json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
}

echo "3. Normalising a line\n";
$l = Lines::normalize_line(
	array(
		'id'           => '17',
		'kind'         => 'FISH',
		'name'         => "  <b>Blue</b>\tTest&nbsp;Tang \n",
		'qty'          => '3',
		'unit_price'   => '$45.00',
		'product_id'   => '501',
		'variation_id' => 777,
		'species_id'   => '0',
		'stay_id'      => '',
		'size_slug'    => 'Medium Size!',
		'size_label'   => ' Medium ',
		'source'       => 'product',
		'take_stock'   => 'on',
	),
	4
);
check( 'tags stripped, whitespace collapsed, ids typed, the size slugged, the position kept', 17 === $l['id'] && 'fish' === $l['kind'] && 'Blue Test&nbsp;Tang' === $l['name'] && 3 === $l['qty'] && 45.0 === $l['unit_price'] && 501 === $l['product_id'] && 777 === $l['variation_id'] && null === $l['species_id'] && null === $l['stay_id'] && 'medium-size' === $l['size_slug'] && 'Medium' === $l['size_label'] && 'product' === $l['source'] && true === $l['take_stock'] && 4 === $l['sort'], json_encode( $l ) );
check( 'a fish or a note without a name is no line', null === Lines::normalize_line( array( 'kind' => 'fish', 'name' => ' ', 'unit_price' => '5' ) ) && null === Lines::normalize_line( array( 'kind' => 'note', 'name' => '' ) ) && null === Lines::normalize_line( 'junk' ) );
check( 'an unknown kind is a fish; an unknown source is typed', 'fish' === Lines::normalize_line( array( 'kind' => 'gift', 'name' => 'X' ) )['kind'] && 'typed' === Lines::normalize_line( array( 'name' => 'X', 'source' => 'moon' ) )['source'] );
check( 'a source without its id is only a typed name (and cannot take stock)', 'typed' === Lines::normalize_line( array( 'name' => 'X', 'source' => 'product', 'take_stock' => true ) )['source'] && false === Lines::normalize_line( array( 'name' => 'X', 'source' => 'product', 'take_stock' => true ) )['take_stock'] && 'typed' === Lines::normalize_line( array( 'name' => 'X', 'source' => 'tank' ) )['source'] && 'typed' === Lines::normalize_line( array( 'name' => 'X', 'source' => 'species' ) )['source'] );
check( 'a variation without its product is dropped', null === Lines::normalize_line( array( 'name' => 'X', 'variation_id' => 9 ) )['variation_id'] );
$c = Lines::normalize_line( array( 'kind' => 'credit', 'name' => 'credit — lost Test Wrasse', 'unit_price' => '82', 'qty' => 5, 'product_id' => 9, 'take_stock' => true ) );
check( 'a credit: amount stored negative, "Credit" in front dropped, qty 1, no ids, no stock tick', -82.0 === $c['unit_price'] && 'lost Test Wrasse' === $c['name'] && 1 === $c['qty'] && null === $c['product_id'] && false === $c['take_stock'] && -82.0 === Lines::amount( $c ) );
check( 'a credit with only an amount is kept ("Credit"); with nothing at all it is no line', 'Credit' === Lines::public_name( Lines::normalize_line( array( 'kind' => 'credit', 'name' => '', 'unit_price' => '5' ) ) ) && null === Lines::normalize_line( array( 'kind' => 'credit', 'name' => '', 'unit_price' => '' ) ) );
$n = Lines::normalize_line( array( 'kind' => 'note', 'name' => 'Ships Tuesday', 'unit_price' => '40', 'qty' => 3, 'stay_id' => 4 ) );
check( 'a note keeps its text only and adds no money', null === $n['unit_price'] && 1 === $n['qty'] && null === $n['stay_id'] && 0.0 === Lines::amount( $n ) );
check( 'a name is cut to 200 characters, a quantity to 999', 200 === strlen( Lines::normalize_line( array( 'name' => str_repeat( 'a', 300 ) ) )['name'] ) && 999 === Lines::normalize_line( array( 'name' => 'X', 'qty' => 100000 ) )['qty'] );
check( 'at most 200 lines', 200 === count( Lines::normalize( array_fill( 0, 250, array( 'name' => 'X', 'unit_price' => '1' ) ) ) ) );
check( 'positions are renumbered from 0 after empty rows are dropped', array( 0, 1 ) === array_column( Lines::normalize( array( array( 'name' => 'A' ), array( 'name' => '' ), array( 'name' => 'B' ) ) ), 'sort' ) );

echo "4. The flat total\n";
$lines = Lines::normalize( array( array( 'name' => 'Blue Test Tang', 'qty' => 2, 'unit_price' => '45' ) ) );
$sum   = Lines::with_flat_total( $lines, 80 );
check( 'a number works like typed text', 2 === count( $sum['lines'] ) && 'discount' === $sum['lines'][1]['kind'] && -10.0 === $sum['lines'][1]['unit_price'] && 'Discount' === $sum['lines'][1]['name'] && 1 === $sum['lines'][1]['sort'] );
$again = Lines::with_flat_total( $sum['lines'], '95' );
check( 'applied again, the earlier discount line is replaced, never added to', 2 === count( $again['lines'] ) && 5.0 === $again['lines'][1]['unit_price'] && 'Adjustment' === $again['lines'][1]['name'] && 95.0 === $again['total'] );
$stored       = $sum['lines'];
$stored[1]['id'] = 44;
$kept         = Lines::with_flat_total( $stored, '70' );
check( 'the stored discount row keeps its id, so it is updated in place', 44 === $kept['lines'][1]['id'] && -20.0 === $kept['lines'][1]['unit_price'] );
$none = Lines::with_flat_total( $sum['lines'], null );
check( 'without a flat total the discount line goes and the total is the sum', 1 === count( $none['lines'] ) && 90.0 === $none['total'] && 0.0 === $none['discount'] );
check( 'a flat total that is no amount is no flat total', 90.0 === Lines::with_flat_total( $lines, 'abc' )['total'] && 90.0 === Lines::with_flat_total( $lines, '' )['total'] );
check( 'a flat total of 0 is a flat total', 0.0 === Lines::with_flat_total( $lines, '0' )['total'] && -90.0 === Lines::with_flat_total( $lines, '0' )['discount'] );

echo "5. The description\n";
$lines = Lines::with_flat_total(
	Lines::normalize(
		array(
			array( 'name' => 'Black & White <Test> Clown', 'qty' => 1, 'unit_price' => '30' ),
			array( 'kind' => 'credit', 'name' => 'lost "Test" Firefish', 'unit_price' => '12' ),
			array( 'kind' => 'note', 'name' => 'Ships Tuesday' ),
		)
	),
	null
)['lines'];
$html  = Lines::description_html( $lines );
check_same( 'a <ul> with one <li> per line, a tag in a name dropped, & escaped', "<ul>\n<li>Black &amp; White Clown x1 \u{2014} \$30</li>\n<li>Credit: lost \"Test\" Firefish \u{2014} -\$12</li>\n<li>Ships Tuesday</li>\n</ul>", $html );
check_same( 'no lines, no list', '', Lines::description_html( array() ) );
$read = Grammar::parse_lines( $html );
check( 'it reads back through the theme\'s grammar as the same lines', 3 === count( $read ) && 'fish' === $read[0]['kind'] && 'Black & White Clown' === $read[0]['name'] && 30.0 === $read[0]['price'] && 'credit' === $read[1]['kind'] && -12.0 === $read[1]['price'] && 'note' === $read[2]['kind'], json_encode( $read ) );

echo "6. The JSON for the theme\n";
$json = Lines::theme_json(
	Lines::with_flat_total(
		Lines::normalize(
			array(
				array( 'name' => 'Blue "Test" Tang / Größe', 'qty' => 2, 'unit_price' => '45', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'take_stock' => true ),
				array( 'kind' => 'credit', 'name' => 'lost Test Wrasse', 'unit_price' => '82.50' ),
				array( 'kind' => 'note', 'name' => 'Ships Tuesday' ),
			)
		),
		'5'
	)['lines']
);
$data = json_decode( $json, true );
check( 'a list of {name, qty, price, kind} — the keys the theme\'s parser documents', is_array( $data ) && array( 0, 1, 2, 3 ) === array_keys( $data ) && array( 'name', 'qty', 'price', 'kind', 'product_id' ) === array_keys( $data[0] ) && array( 'name', 'qty', 'price', 'kind' ) === array_keys( $data[1] ), $json );
check( 'kinds are only the three the theme knows: fish, credit, note', array( 'fish', 'credit', 'note', 'credit' ) === array_column( $data, 'kind' ) );
check( 'a credit is quantity 1 with a negative price; a note has no price; the Discount is a credit', -82.5 === $data[1]['price'] && 1 === $data[1]['qty'] && null === $data[2]['price'] && 'Discount' === $data[3]['name'] && -2.5 === $data[3]['price'] );
check( 'unicode and slashes are written as they are', false !== strpos( $json, 'Größe' ) && false !== strpos( $json, 'Tang / ' ) && false === strpos( $json, '\\/' ) );
$theme = Grammar::lines_from_json( $json );
check( 'the theme\'s reader takes it back: same kinds, same prices, the product id on the stock line only', array( 'fish', 'credit', 'note', 'credit' ) === array_column( $theme, 'kind' ) && 777 === $theme[0]['product_id'] && array( 0, 0, 0 ) === array( $theme[1]['product_id'], $theme[2]['product_id'], $theme[3]['product_id'] ) && 45.0 === $theme[0]['price'] );
check( 'and adds them up to the total, so the order gets one line per fish', near( Grammar::sum( $theme ), 5.0 ) );
check_same( 'no lines: an empty list', '[]', Lines::theme_json( array() ) );

echo "7. The stock tick: product_id present or absent\n";
$tick = static function ( array $line ) {
	$rows = Lines::theme_rows( Lines::normalize( array( $line ) ) );
	return isset( $rows[0]['product_id'] ) ? $rows[0]['product_id'] : null;
};
check_same( 'a ticked simple product: its id', 501, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'product', 'product_id' => 501, 'take_stock' => true ) ) );
check_same( 'a ticked variation: the VARIATION id', 777, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'take_stock' => true ) ) );
check_same( 'unticked: absent', null, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'product', 'product_id' => 501, 'variation_id' => 777, 'take_stock' => false ) ) );
check_same( 'the tick missing: absent', null, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'product', 'product_id' => 501 ) ) );
check_same( 'a tank group linked to a product, ticked: absent — shop stock is never touched for it', null, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'tank', 'stay_id' => 9, 'product_id' => 501, 'take_stock' => true ) ) );
check_same( 'a species-list fish linked to a product, ticked: absent', null, $tick( array( 'name' => 'A', 'unit_price' => '5', 'source' => 'species', 'species_id' => 3, 'product_id' => 501, 'take_stock' => true ) ) );
check_same( 'a typed fish, ticked: absent', null, $tick( array( 'name' => 'A', 'unit_price' => '5', 'take_stock' => true ) ) );
check( 'the key is absent, not 0 or null', ! array_key_exists( 'product_id', Lines::theme_rows( Lines::normalize( array( array( 'name' => 'A', 'unit_price' => '5' ) ) ) )[0] ) );
check_same( 'stock_product_id() of a credit is 0 whatever it carries', 0, Lines::stock_product_id( array( 'kind' => 'credit', 'source' => 'product', 'product_id' => 5, 'take_stock' => true ) ) );

echo "8. Counting and summaries\n";
$lines = Lines::normalize( array( array( 'name' => 'A', 'qty' => 2 ), array( 'name' => 'B', 'qty' => 3 ), array( 'kind' => 'credit', 'name' => 'lost C', 'unit_price' => '5' ), array( 'kind' => 'note', 'name' => 'N' ) ) );
check_same( 'fish_count() adds the quantities of the fish lines', 5, Lines::fish_count( $lines ) );
check_same( 'summary()', "A \u{00D7} 2, B \u{00D7} 3, Credit: lost C, N", Lines::summary( $lines ) );

fh_finish();
