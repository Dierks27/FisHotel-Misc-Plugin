<?php
/**
 * Which tank group a line of a customer's order comes out of —
 * Sold_Line::stays(), the one ladder every "this fish is sold" rule asks:
 * the Invoices section's link, then the same product and size, then the
 * exact name and size; every group that fits; no guess when none does.
 *
 * Invented fish, invented ids. No WordPress, no WooCommerce.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sold-line-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Sold_Line;

/**
 * An open stay as the Store hydrates it (only what the ladder reads).
 */
function stay( $id, $species, array $more = array() ) {
	return array_merge(
		array(
			'id'          => $id,
			'product_id'  => null,
			'species'     => $species,
			'size_slug'   => null,
			'size_label'  => null,
			'qty_live'    => 3,
			'tank_id'     => 10 + $id,
			'tank_number' => 100 + $id,
			'building_id' => 1,
			'area'        => 'clean',
			'own_water'   => false,
		),
		$more
	);
}

function ids( array $answer ) {
	return array_map( 'intval', array_column( $answer['stays'], 'id' ) );
}

$stays = array(
	stay( 1, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium' ) ),
	stay( 2, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'large', 'size_label' => 'Large' ) ),
	stay( 3, 'Test Tang', array( 'product_id' => 501, 'size_slug' => 'medium', 'size_label' => 'Medium', 'tank_number' => 221, 'building_id' => 2 ) ),
	stay( 4, 'Sample Angel' ),
	stay( 5, 'Sample Angel', array( 'size_slug' => '2-3-5', 'size_label' => '2 - 3.5"' ) ),
	stay( 6, 'Demo Wrasse', array( 'product_id' => 777 ) ),
	stay( 7, 'Gone Goby', array( 'product_id' => 888, 'qty_live' => 0 ) ),
	stay( 8, 'Mock Blenny', array( 'product_id' => 990, 'size_slug' => 'small', 'size_label' => 'Small' ) ),
);

/* ------------------------------------------------------------------ */
echo "1. The Invoices section's link comes first\n";

$answer = Sold_Line::stays( array( 'name' => 'Something else entirely', 'link' => array( 'stay_id' => 2 ) ), $stays );
check_same( 'a line the Invoices section tied to a tank group: that group, whatever the line is called', array( 'invoice', array( 2 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang', 'product_id' => 501, 'size_slug' => 'medium', 'link' => array( 'stay_id' => 2 ) ), $stays );
check_same( 'the link wins over the product and the size the line itself names', array( 'invoice', array( 2 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang', 'product_id' => 501, 'size_slug' => 'large', 'link' => array( 'stay_id' => 4040 ) ), $stays );
check_same( 'a link to a group that is closed or gone: the next rung answers', array( 'product', array( 2 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Gone Goby', 'product_id' => 888, 'link' => array( 'stay_id' => 7 ) ), $stays );
check_same( 'a link to a group with no fish left is no answer, and neither is that group by its product', array( '', array() ), array( $answer['rung'], ids( $answer ) ) );

/* ------------------------------------------------------------------ */
echo "2. The same product, the same size\n";

$answer = Sold_Line::stays( array( 'name' => 'Test Tang - Medium', 'product_id' => 501, 'variation_id' => 502, 'size_slug' => 'medium' ), $stays );
check_same( 'product and size: every group of that size, in both systems', array( 'product', array( 1, 3 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang - Large', 'product_id' => 501, 'size_slug' => 'Large' ), $stays );
check_same( 'the size is compared as a slug ("Large" is "large")', array( 2 ), ids( $answer ) );

$answer = Sold_Line::stays( array( 'name' => 'Demo Wrasse', 'product_id' => 0, 'variation_id' => 777 ), $stays );
check_same( 'a group filed under the variation itself is found by the line\'s variation', array( 'product', array( 6 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'An invoice fish', 'link' => array( 'product_id' => 777 ) ), $stays );
check_same( 'the product the Invoices section names counts as the line\'s product', array( 'product', array( 6 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Demo Wrasse - Small', 'product_id' => 777, 'size_slug' => 'small' ), $stays );
check_same( 'a group whose size was never written down cannot be ruled out by the line\'s size', array( 'product', array( 6 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang', 'product_id' => 501 ), $stays );
check_same( 'a line without a size: every group of the product', array( 'product', array( 1, 2, 3 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Mock Blenny - Large', 'product_id' => 990, 'size_slug' => 'large' ), $stays );
check_same( 'the product in another size only: not that fish — no answer', array( '', array() ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang - Medium', 'product_id' => 501, 'size_slug' => 'medium' ), array( stay( 9, 'Test Tang', array( 'product_id' => 501 ) ), $stays[2], $stays[1], $stays[0] ) );
check_same( 'a group of the line\'s size beats one without a size, and the order the groups came in is kept', array( 3, 1 ), ids( $answer ) );

/* ------------------------------------------------------------------ */
echo "3. The exact name and the size\n";

$answer = Sold_Line::stays( array( 'name' => 'Sample Angel' ), $stays );
check_same( 'a line by name, no size: the groups of that species', array( 'name', array( 4, 5 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'sample   ANGEL', 'size_slug' => '2-3-5' ), $stays );
check_same( 'the name is compared without case or spacing; the sized group is the one', array( 'name', array( 5 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Sample Angel 2 - 3.5"' ), $stays );
check_same( 'a name that ends in a group\'s size label is that group', array( 'name', array( 5 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Sample Angel - Large', 'size_slug' => 'large' ), $stays );
check_same( 'a name ending in the line\'s own size is the species; no group has that size, so the one without a size is the candidate', array( 'name', array( 4 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Sample Angelfish' ), $stays );
check_same( 'nearly the name is not the name: no guess', array( '', array() ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Test Tang - Medium', 'names' => array( 'Test Tang' ), 'product_id' => 9999, 'size_slug' => 'medium' ), $stays );
check_same( 'a product no group is filed under: the product\'s own title names the species', array( 'name', array( 1, 3 ) ), array( $answer['rung'], ids( $answer ) ) );

$answer = Sold_Line::stays( array( 'name' => 'Invoice line 4', 'link' => array( 'species' => 'Demo Wrasse' ) ), $stays );
check_same( 'the name the Invoices section gives the fish counts too', array( 'name', array( 6 ) ), array( $answer['rung'], ids( $answer ) ) );

/* ------------------------------------------------------------------ */
echo "4. Nothing fits\n";

check_same( 'a fish in no tank the site knows: an empty answer', array( 'rung' => '', 'stays' => array() ), Sold_Line::stays( array( 'name' => 'Unlogged Clownfish', 'product_id' => 31337, 'size_slug' => 'small' ), $stays ) );
check_same( 'no stays at all', array( 'rung' => '', 'stays' => array() ), Sold_Line::stays( array( 'name' => 'Test Tang', 'product_id' => 501 ), array() ) );
check_same( 'a line that says nothing', array( 'rung' => '', 'stays' => array() ), Sold_Line::stays( array(), $stays ) );
check_same( 'junk in, nothing out', array( 'rung' => '', 'stays' => array() ), Sold_Line::stays( array( 'name' => array( 'x' ), 'product_id' => 'abc', 'link' => 'nope' ), array( 'not a stay', array( 'qty_live' => 1 ) ) ) );

/* ------------------------------------------------------------------ */
echo "5. A group whose last fish left today\n";

$bagged = stay( 20, 'Bagged Basslet', array( 'product_id' => 640, 'qty_live' => 0, 'left_today' => true ) );
$older  = stay( 21, 'Bagged Basslet', array( 'product_id' => 640, 'qty_live' => 0 ) );
check_same( 'marked as having left today, it still answers for its fish: by the link', array( 'invoice', array( 20 ) ), array( Sold_Line::stays( array( 'name' => 'x', 'link' => array( 'stay_id' => 20 ) ), array( $older, $bagged ) )['rung'], ids( Sold_Line::stays( array( 'name' => 'x', 'link' => array( 'stay_id' => 20 ) ), array( $older, $bagged ) ) ) ) );
check_same( '… by its product, and by its name', array( array( 20 ), array( 20 ) ), array( ids( Sold_Line::stays( array( 'name' => 'x', 'product_id' => 640 ), array( $older, $bagged ) ) ), ids( Sold_Line::stays( array( 'name' => 'Bagged Basslet' ), array( $older, $bagged ) ) ) ) );
check_same( 'an empty group nobody marked is no candidate', array( 'rung' => '', 'stays' => array() ), Sold_Line::stays( array( 'name' => 'Bagged Basslet', 'product_id' => 640, 'link' => array( 'stay_id' => 21 ) ), array( $older ) ) );

/* ------------------------------------------------------------------ */
echo "6. The line it reads\n";

$line = Sold_Line::line( array( 'name' => ' Test Tang - Medium ', 'names' => array( 'Test Tang', '' ), 'product_id' => '501', 'variation_id' => 502, 'size_label' => 'Medium', 'link' => array( 'product_id' => 501, 'species' => 'Test Tang', 'size_slug' => 'Medium', 'stay_id' => '3' ) ) );
check_same( 'one shape whatever the reader had', array( 'name' => 'Test Tang - Medium', 'names' => array( 'test tang medium', 'test tang' ), 'product_ids' => array( 501, 502 ), 'size_slug' => 'medium', 'size_label' => 'Medium', 'stay_id' => 3 ), $line );
check_same( 'a name as it is compared', 'yellow tang 2 3 5', Sold_Line::norm( '  Yellow-Tang (2 - 3.5")' ) );

fh_finish();
