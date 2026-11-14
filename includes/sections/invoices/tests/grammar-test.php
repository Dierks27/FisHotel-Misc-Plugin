<?php
/**
 * Grammar — how an invoice made by hand is read: the fish / credit / note
 * grammar the theme and the Batch plugin's pick list share (dash and "x"
 * variants, a missing price, credits and negative amounts, nested tags
 * and entities, plain text without a list), the `_fh_invoice_lines` JSON
 * with its defaults, which of the two wins, the invoice rule (category
 * or the word in the name), and the sum the theme checks before it turns
 * an invoice into one order line per fish.
 *
 * Every expectation here is what the theme's own parser answers for the
 * same input.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/grammar-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Grammar;

function fish( $name, $qty, $price ) {
	return array(
		'name'       => $name,
		'qty'        => $qty,
		'price'      => $price,
		'kind'       => 'fish',
		'product_id' => 0,
	);
}

function credit( $name, $price ) {
	return array(
		'name'       => $name,
		'qty'        => 1,
		'price'      => $price,
		'kind'       => 'credit',
		'product_id' => 0,
	);
}

function note( $name ) {
	return array(
		'name'       => $name,
		'qty'        => 1,
		'price'      => null,
		'kind'       => 'note',
		'product_id' => 0,
	);
}

echo "1. Fish lines\n";
check_same( 'the plain case', array( fish( 'Yellow Test Goby', 1, 39.0 ) ), Grammar::parse_lines( '<ul><li>Yellow Test Goby x1 — $39</li></ul>' ) );
check_same( 'several lines, in order', array( fish( 'Yellow Test Goby', 1, 39.0 ), fish( 'Blue Test Tang', 2, 120.0 ) ), Grammar::parse_lines( "<ul>\n<li>Yellow Test Goby x1 — \$39</li>\n<li>Blue Test Tang x2 — \$120</li>\n</ul>" ) );
check_same( 'en dash', array( fish( 'Goby', 1, 39.0 ) ), Grammar::parse_lines( '<li>Goby x1 – $39</li>' ) );
check_same( 'hyphen', array( fish( 'Goby', 1, 39.0 ) ), Grammar::parse_lines( '<li>Goby x1 - $39</li>' ) );
check_same( 'hyphen with no spaces', array( fish( 'Goby', 1, 39.0 ) ), Grammar::parse_lines( '<li>Goby x1-$39</li>' ) );
check_same( 'a hyphen touching the $ is a separator, not a minus', array( fish( 'Goby', 1, 39.0 ) ), Grammar::parse_lines( '<li>Goby x1 -$39</li>' ) );
check_same( 'the multiplication sign', array( fish( 'Goby', 2, 78.0 ) ), Grammar::parse_lines( '<li>Goby ×2 — $78</li>' ) );
check_same( 'an upper-case X', array( fish( 'Goby', 2, 78.0 ) ), Grammar::parse_lines( '<li>Goby X2 — $78</li>' ) );
check_same( 'spaces around x and $', array( fish( 'Goby', 3, 117.0 ) ), Grammar::parse_lines( '<li>Goby x 3 — $ 117</li>' ) );
check_same( 'a name with an x in it', array( fish( 'Six Line Test Wrasse', 1, 25.0 ) ), Grammar::parse_lines( '<li>Six Line Test Wrasse x1 — $25</li>' ) );
check_same( 'a name ending in x', array( fish( 'Test Paddlefish Max', 2, 10.0 ) ), Grammar::parse_lines( '<li>Test Paddlefish Max x2 — $10</li>' ) );
check_same( 'a name with a size in brackets', array( fish( 'Blue Test Tang (Medium)', 1, 45.0 ) ), Grammar::parse_lines( '<li>Blue Test Tang (Medium) x1 — $45</li>' ) );
check_same( 'cents and thousands', array( fish( 'Rare Test Angel', 1, 1250.5 ) ), Grammar::parse_lines( '<li>Rare Test Angel x1 — $1,250.50</li>' ) );
check_same( 'no dash, no price: the price is null', array( fish( 'Goby', 1, null ) ), Grammar::parse_lines( '<li>Goby x1</li>' ) );
check_same( 'a dash but no price: null', array( fish( 'Goby', 4, null ) ), Grammar::parse_lines( '<li>Goby x4 —</li>' ) );
check_same( 'a quantity of 0 reads as 1', array( fish( 'Goby', 1, 5.0 ) ), Grammar::parse_lines( '<li>Goby x0 — $5</li>' ) );

echo "2. Credits\n";
check_same( 'the hand-made form: the name keeps "Credit:", the amount is negative', array( credit( 'Credit: lost Test Cleaner Wrasse', -82.0 ) ), Grammar::parse_lines( '<li>Credit: lost Test Cleaner Wrasse — -$82</li>' ) );
check_same( 'a "Credit…" line with a positive amount is still a reduction', array( credit( 'Credit lost fish', -40.0 ) ), Grammar::parse_lines( '<li>Credit lost fish $40</li>' ) );
check_same( 'only "Credit" and an amount', array( credit( 'Credit', -5.0 ) ), Grammar::parse_lines( '<li>Credit — -$5</li>' ) );
check_same( 'a "Credit…" line without an amount carries none', array( credit( 'Credit: to be decided', null ) ), Grammar::parse_lines( '<li>Credit: to be decided</li>' ) );
check_same( 'what this section writes for a flat total below the sum', array( credit( 'Discount', -50.0 ) ), Grammar::parse_lines( '<li>Discount — -$50</li>' ) );
check_same( 'negative amounts never read as fish, whatever the line says', array( credit( 'Lost wrasse x1', -82.0 ), credit( 'DOA x1', -10.0 ), credit( 'Refund x1', -5.0 ), credit( '-$20', -20.0 ) ), Grammar::parse_lines( '<ul><li>Lost wrasse x1 — -$82</li><li>DOA x1 — $-10</li><li>Refund x1 — −$5</li><li>-$20</li></ul>' ) );
check_same( 'the amount in the middle of a credit', array( credit( 'Credit of for the lost fish', -15.0 ) ), Grammar::parse_lines( '<li>Credit of $15 for the lost fish</li>' ) );

echo "3. Notes, tags, entities, text without a list\n";
check_same( 'anything else is a note', array( note( 'Pair of Test Clownfish (bonded)' ), fish( 'Goby', 1, 39.0 ) ), Grammar::parse_lines( '<ul><li>Pair of Test Clownfish (bonded)</li><li>Goby x1 — $39</li></ul>' ) );
check_same( 'nested tags and entities', array( fish( 'Blue Tang', 2, 90.0 ) ), Grammar::parse_lines( '<ol><li class="fh"><strong>Blue&nbsp;Tang</strong> x2 &mdash; $90</li></ol>' ) );
check_same( 'an escaped ampersand comes back as itself', array( fish( 'Black & White Test Clown', 1, 30.0 ) ), Grammar::parse_lines( '<li>Black &amp; White Test Clown x1 — $30</li>' ) );
check_same( 'plain text lines', array( fish( 'Yellow Test Goby', 1, 39.0 ), fish( 'Blue Test Tang', 2, 120.0 ) ), Grammar::parse_lines( "Yellow Test Goby x1 — \$39\r\n\r\nBlue Test Tang x2 — \$120\n" ) );
check_same( 'paragraphs and line breaks', array( fish( 'Yellow Test Goby', 1, 39.0 ), fish( 'Blue Test Tang', 2, 120.0 ), credit( 'Credit: DOA', -20.0 ) ), Grammar::parse_lines( '<p>Yellow Test Goby x1 — $39<br>Blue Test Tang x2 — $120</p><p>Credit: DOA — -$20</p>' ) );
check_same( 'an empty description, an empty list, and no text at all', array( array(), array(), array(), array() ), array( Grammar::parse_lines( '' ), Grammar::parse_lines( "  \n " ), Grammar::parse_lines( '<ul><li></li><li>&nbsp;</li></ul>' ), Grammar::parse_lines( null ) ) );
check_same( 'clean_text()', 'Blue Tang & co', Grammar::clean_text( " <b>Blue</b>&nbsp;Tang \n &amp;  co " ) );

echo "4. The JSON\n";
check_same( 'the documented shape', array( fish( 'A', 2, 45.0 ) ), Grammar::lines_from_json( '[{"name":"A","qty":2,"price":45,"kind":"fish"}]' ) );
$rows = Grammar::lines_from_json( '[{"name":"Credit: x","price":5},{"name":"B","qty":"3","price":"12.5","kind":"FISH","product_id":"77"},{"name":"","qty":1},{"name":"N","kind":"note","price":4,"qty":9},"junk",{"name":"Neg","price":-4,"qty":3},{"name":"Plain"}]' );
check( 'an unknown kind: a "Credit…" name or a negative price is a credit, else a fish', 'credit' === $rows[0]['kind'] && -5.0 === $rows[0]['price'] && 'credit' === $rows[3]['kind'] && 1 === $rows[3]['qty'] && 'fish' === $rows[4]['kind'] && null === $rows[4]['price'], json_encode( $rows ) );
check( 'strings are read as numbers, the kind in any case, the product id kept', 'fish' === $rows[1]['kind'] && 3 === $rows[1]['qty'] && 12.5 === $rows[1]['price'] && 77 === $rows[1]['product_id'] );
check( 'a nameless row and a row that is not a row are dropped; a note has no price and quantity 1', 5 === count( $rows ) && 'note' === $rows[2]['kind'] && null === $rows[2]['price'] && 1 === $rows[2]['qty'] );
check_same( 'unusable JSON gives nothing', array( array(), array(), array(), array() ), array( Grammar::lines_from_json( '{not json' ), Grammar::lines_from_json( '' ), Grammar::lines_from_json( null ), Grammar::lines_from_json( '"text"' ) ) );
check_same( 'an already decoded list is taken as it is', array( credit( 'Arr', -4.0 ) ), Grammar::lines_from_json( array( array( 'name' => 'Arr', 'qty' => 1, 'price' => -4 ) ) ) );

echo "5. Which one wins\n";
check_same( 'the JSON, when it is usable', array( fish( 'From Json', 1, 5.0 ) ), Grammar::product_lines( '[{"name":"From Json","qty":1,"price":5}]', '<li>From Description x1 — $9</li>' ) );
check_same( 'the description, when there is no JSON', array( fish( 'From Description', 1, 9.0 ) ), Grammar::product_lines( '', '<li>From Description x1 — $9</li>' ) );
check_same( 'the description, when the JSON is broken or empty', array( fish( 'From Description', 1, 9.0 ), fish( 'From Description', 1, 9.0 ) ), array_merge( Grammar::product_lines( '{broken', '<li>From Description x1 — $9</li>' ), Grammar::product_lines( '[]', '<li>From Description x1 — $9</li>' ) ) );

echo "6. Is it an invoice?\n";
check( 'the invoices category', Grammar::classify_invoice( array( 'invoices' ), 'Anything' ) && Grammar::classify_invoice( array( 'fish', ' Invoices ' ), 'Anything' ) );
check( 'the word in the name, whole and in any case', Grammar::classify_invoice( array(), 'Tester Invoice (7/3/26)' ) && Grammar::classify_invoice( array(), 'tester INVOICE' ) && ! Grammar::classify_invoice( array(), 'Invoicer Software' ) && ! Grammar::classify_invoice( array( 'fish' ), 'Blue Test Tang' ) );
check( 'a category slug of the section\'s own counts too', Grammar::classify_invoice( array( 'bills' ), 'Anything', 'bills' ) && ! Grammar::classify_invoice( array( 'bills' ), 'Anything' ) );

echo "7. The sum the theme checks, and a line back as text\n";
check_same( 'fish quantity × price plus the credits', 98.0, Grammar::sum( array( fish( 'A', 2, 45.0 ), fish( 'B', 1, 90.0 ), credit( 'Credit: c', -82.0 ), note( 'n' ) ) ) );
check_same( 'a fish without a price: no sum (the theme keeps the invoice as one line)', null, Grammar::sum( array( fish( 'A', 2, 45.0 ), fish( 'B', 1, null ) ) ) );
check_same( 'a fish at 0: no sum', null, Grammar::sum( array( fish( 'A', 2, 0.0 ) ) ) );
check_same( 'no fish at all: no sum', null, Grammar::sum( array( credit( 'Credit: c', -5.0 ), note( 'n' ) ) ) );
check_same( 'cents do not drift', 0.3, Grammar::sum( array( fish( 'A', 1, 0.1 ), fish( 'B', 1, 0.2 ) ) ) );
check_same( 'line_text()', array( "A x2 \u{2014} \$45", 'B x1', "Credit: c \u{2014} -\$82.50", 'n' ), array( Grammar::line_text( fish( 'A', 2, 45.0 ) ), Grammar::line_text( fish( 'B', 1, null ) ), Grammar::line_text( credit( 'Credit: c', -82.5 ) ), Grammar::line_text( note( 'n' ) ) ) );

fh_finish();
