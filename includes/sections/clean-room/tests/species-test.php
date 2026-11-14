<?php
/**
 * Species — the species master: a form row normalised, the search on
 * the in-memory store, the merge into the Arrival screen's candidate
 * list next to the products (deduped by lower-cased name, a linked or
 * same-named row folding into its product), the search-box suggestions,
 * the product link, delete-or-refuse, and the parser finding a species
 * that is not a product.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/species-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Parser;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Store;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

echo "1. A form row normalised\n";
$row = Species::normalize( array( 'id' => '7', 'name' => '  Blue   Test Wrasse ', 'sci_name' => ' Halichoeres  testus ', 'group_name' => 'Wrasses', 'per_box' => '0', 'my_price' => '$12.50', 'online_price' => 'n/a', 'online_site' => '', 'online_date' => '2026-13-01', 'reef_safe' => 'With Caution', 'max_size' => '4 in', 'product_id' => '0', 'notes' => ' hides ' ) );
check( 'whitespace collapsed, per box 0 → 24, money read, junk price null, bad date null, "With Caution" → caution, product 0 → null', 7 === $row['id'] && 'Blue Test Wrasse' === $row['name'] && 'Halichoeres testus' === $row['sci_name'] && 24 === $row['per_box'] && 12.5 === $row['my_price'] && null === $row['online_price'] && null === $row['online_site'] && null === $row['online_date'] && 'caution' === $row['reef_safe'] && '4 in' === $row['max_size'] && null === $row['product_id'] && 'hides' === $row['notes'], json_encode( $row ) );
$row = Species::normalize( array( 'name' => 'x', 'per_box' => '5000', 'my_price' => '-1', 'reef_safe' => 'maybe', 'online_date' => '2026-07-02', 'online_site' => 'Test Site', 'product_id' => '12' ) );
check( 'per box out of range → 24, a negative price → null, an unknown reef-safe → "", a good date and site kept, product 12', 24 === $row['per_box'] && null === $row['my_price'] && '' === $row['reef_safe'] && '2026-07-02' === $row['online_date'] && 'Test Site' === $row['online_site'] && 12 === $row['product_id'] && 0 === $row['id'] );
check( 'an empty name stays empty (the caller refuses it)', '' === Species::normalize( array( 'name' => '   ' ) )['name'] && '' === Species::normalize( array() )['name'] );
check( 'the dedupe key', 'blue test wrasse' === Species::key( '  Blue   TEST wrasse ' ) );

echo "2. The candidate merge (products + species rows, one entry per name)\n";
$products = array(
	array( 'product_id' => 12, 'name' => 'Blue Test Wrasse', 'common' => '', 'sci' => '' ),
	array( 'product_id' => 13, 'name' => 'Orange Test Clown', 'common' => 'Test Clown', 'sci' => 'Amphiprion testus' ),
	array( 'product_id' => 14, 'name' => 'blue test wrasse', 'common' => '', 'sci' => '' ),
);
$species = array(
	array( 'id' => 1, 'name' => 'Blue Test Wrasse', 'sci_name' => 'Halichoeres testus', 'product_id' => null, 'per_box' => 8, 'my_price' => 40 ),
	array( 'id' => 2, 'name' => 'blue test wrasse', 'sci_name' => 'dup', 'product_id' => null ),
	array( 'id' => 3, 'name' => 'Test Clown Alt', 'sci_name' => 'Amphiprion alt', 'product_id' => 13 ),
	array( 'id' => 4, 'name' => 'Green Test Blenny', 'sci_name' => 'Ecsenius testus', 'product_id' => null, 'per_box' => 24 ),
	array( 'id' => 5, 'name' => 'Linked Elsewhere', 'sci_name' => '', 'product_id' => 77 ),
	array( 'id' => 6, 'name' => '', 'sci_name' => 'nameless' ),
);
$merged = Species::merge_candidates( $products, $species );
check( 'five entries: two products (the duplicate product title dropped), two species of their own, nothing nameless', array( 'Blue Test Wrasse', 'Orange Test Clown', 'Green Test Blenny', 'Linked Elsewhere' ) === array_column( $merged, 'name' ), json_encode( array_column( $merged, 'name' ) ) );
check( 'the wrasse row folds into product 12: its scientific name, species id, per box and price; the duplicate row changes nothing', 12 === $merged[0]['product_id'] && 'Halichoeres testus' === $merged[0]['sci'] && 1 === $merged[0]['species_id'] && 8 === $merged[0]['per_box'] && 40 === $merged[0]['my_price'] );
check( 'the row linked to product 13 folds in: its name joins the common names, the product keeps its own scientific name', 13 === $merged[1]['product_id'] && 'Test Clown, Test Clown Alt' === $merged[1]['common'] && 'Amphiprion testus' === $merged[1]['sci'] && 3 === $merged[1]['species_id'] );
check( 'a species of its own has no product; one linked outside the fish list keeps its product id', null === $merged[2]['product_id'] && 4 === $merged[2]['species_id'] && 'Ecsenius testus' === $merged[2]['sci'] && 77 === $merged[3]['product_id'] );
check( 'products alone pass through; species alone stand alone', 2 === count( Species::merge_candidates( array_slice( $products, 0, 2 ), array() ) ) && 4 === count( Species::merge_candidates( array(), $species ) ) );

echo "3. On the store: save, search, groups\n";
$store = new Fake_Store( array( 12 => 'Blue Test Wrasse', 13 => 'Orange Test Clown' ) );
$ids   = array();
foreach ( array(
	array( 'name' => 'Blue Test Wrasse', 'sci_name' => 'Halichoeres testus', 'group_name' => 'Wrasses', 'per_box' => 8, 'my_price' => 40, 'product_id' => 12 ),
	array( 'name' => 'Green Test Blenny', 'sci_name' => 'Ecsenius testus', 'group_name' => 'Blennies', 'per_box' => 24 ),
	array( 'name' => 'Red Test Goby', 'sci_name' => 'Gobiodon testus', 'group_name' => 'Gobies' ),
	array( 'name' => 'Test Clown Alt', 'sci_name' => 'Amphiprion alt', 'product_id' => 13 ),
) as $raw ) {
	$ids[] = $store->save_species( Species::normalize( $raw ) );
}
check( 'four rows saved', array( 1, 2, 3, 4 ) === $ids && 4 === $store->species_count() );
check( 'a duplicate name (any case) is refused; an empty name is refused', false === $store->save_species( Species::normalize( array( 'name' => 'blue test WRASSE' ) ) ) && false === $store->save_species( Species::normalize( array( 'name' => '' ) ) ) );
check( 'update by id keeps the row and changes the fields', 1 === $store->save_species( Species::normalize( array( 'id' => 1, 'name' => 'Blue Test Wrasse', 'sci_name' => 'Halichoeres testus', 'group_name' => 'Wrasses', 'per_box' => 10, 'my_price' => 45, 'product_id' => 12 ) ) ) && 10 === $store->species( 1 )['per_box'] && 45.0 === $store->species( 1 )['my_price'] );
check( 'search: a prefix of the name', array( 'Blue Test Wrasse' ) === array_column( $store->species_list( array( 'search' => 'blu' ) ), 'name' ) );
check( 'search: a prefix of any word, by name', array( 'Blue Test Wrasse', 'Green Test Blenny', 'Red Test Goby', 'Test Clown Alt' ) === array_column( $store->species_list( array( 'search' => 'test' ) ), 'name' ) );
check( 'search: a prefix of the scientific name', array( 'Blue Test Wrasse' ) === array_column( $store->species_list( array( 'search' => 'Hali' ) ), 'name' ) && array( 'Blue Test Wrasse', 'Green Test Blenny', 'Red Test Goby' ) === array_column( $store->species_list( array( 'search' => 'testus' ) ), 'name' ) );
check( 'not a prefix: no match ("lue")', array() === $store->species_list( array( 'search' => 'lue' ) ) );
check( 'the group filter, the groups list, paging', array( 'Red Test Goby' ) === array_column( $store->species_list( array( 'group' => 'Gobies' ) ), 'name' ) && array( 'Blennies', 'Gobies', 'Wrasses' ) === $store->species_groups() && array( 'Green Test Blenny' ) === array_column( $store->species_list( array(), 1, 1 ), 'name' ) && 4 === $store->species_count( array( 'search' => 'test' ) ) );
check( 'by name, by product', 1 === $store->species_by_name( ' blue test wrasse ' )['id'] && null === $store->species_by_name( 'x' ) && 4 === $store->species_by_product( 13 )['id'] && null === $store->species_by_product( 99 ) );
check( 'Species::matches is the same rule', Species::matches( array( 'name' => 'Blue Test Wrasse', 'sci_name' => '' ), 'test' ) && ! Species::matches( array( 'name' => 'Blue Test Wrasse', 'sci_name' => '' ), 'lue' ) && Species::matches( array( 'name' => 'x', 'sci_name' => 'y' ), '' ) );

echo "4. The search-box suggestions\n";
$items = $store->species_suggestions( 'test' );
$names = array_column( $items, 'name' );
check( 'products first, then the species rows that are not already there — no name twice', array( 'Blue Test Wrasse', 'Orange Test Clown', 'Green Test Blenny', 'Red Test Goby' ) === $names, json_encode( $names ) );
check( 'the product entry carries its species row (id, per box, price, group) and gains the scientific name', 12 === $items[0]['product_id'] && 1 === $items[0]['species_id'] && 10 === $items[0]['per_box'] && 45.0 === $items[0]['my_price'] && 'Wrasses' === $items[0]['group_name'] && 'Halichoeres testus' === $items[0]['sci'] && 'title' === $items[0]['match'] );
check( 'the clown product is lent the row linked to it (Test Clown Alt does not appear twice)', 13 === $items[1]['product_id'] && 4 === $items[1]['species_id'] && 'Amphiprion alt' === $items[1]['sci'] && ! in_array( 'Test Clown Alt', $names, true ) );
check( 'a species of its own: match "species", no product, its fields', 'species' === $items[2]['match'] && null === $items[2]['product_id'] && 2 === $items[2]['species_id'] && 24 === $items[2]['per_box'] && null === $items[2]['my_price'] );
check( 'the Front Desk shape is intact on every item', array( 'product_id', 'name', 'common', 'sci', 'match', 'alias' ) === array_slice( array_keys( $items[2] ), 0, 6 ) && isset( $items[0]['alias'] ) );
check( 'an empty term → nothing; a scientific-name term finds the row', array() === $store->species_suggestions( '' ) && array( 'Red Test Goby' ) === array_column( $store->species_suggestions( 'gobiodon' ), 'name' ) );
$folded = Store::fold_species_rows( array( array( 'product_id' => 5, 'name' => 'Same Name', 'common' => '', 'sci' => '', 'match' => 'title', 'alias' => '' ) ), array( array( 'id' => 9, 'name' => 'same name', 'sci_name' => 'S. n.', 'product_id' => null, 'per_box' => 6 ), array( 'id' => 10, 'name' => 'Other', 'sci_name' => '', 'product_id' => null ) ) );
check( 'fold_species_rows (pure): a same-named row lends its fields, another is appended', 2 === count( $folded ) && 9 === $folded[0]['species_id'] && 'S. n.' === $folded[0]['sci'] && 6 === $folded[0]['per_box'] && 'Other' === $folded[1]['name'] && 10 === $folded[1]['species_id'] );

echo "5. The parser candidates include the species rows\n";
$candidates = $store->species_candidates();
check( 'four candidates: the two products and the two species of their own', array( 'Blue Test Wrasse', 'Orange Test Clown', 'Green Test Blenny', 'Red Test Goby' ) === array_column( $candidates, 'name' ) );
check( 'the wrasse product has the scientific-name variant from its row; the blenny has no product', in_array( array( 'text' => 'Halichoeres testus', 'kind' => 'sci' ), $candidates[0]['variants'], true ) && null === $candidates[2]['product_id'] );
$tanks = fh_seed_tanks();
$r     = Parser::parse( 'four green test blenny in tank 521', array( 'tank' => 521, 'tanks' => $tanks, 'species' => $candidates, 'occupants' => array() ) );
check( 'an arrival of a species that is not a product parses to that species with no product id', 'arrival' === $r['intent'] && 4 === $r['qty'] && 'Green Test Blenny' === $r['species']['name'] && null === $r['species']['product_id'] && $r['species']['confidence'] >= 0.75, json_encode( $r['species'] ) );
$r = Parser::parse( 'two halichoeres testus in 522', array( 'tank' => 521, 'tanks' => $tanks, 'species' => $candidates, 'occupants' => array() ) );
check( 'the scientific name from the species row finds the product', 12 === $r['species']['product_id'] && 'Blue Test Wrasse' === $r['species']['name'] );

echo "6. Product link, open stays, delete\n";
check( 'link a product, then unlink', $store->link_species_product( 2, 13 ) && 13 === $store->species( 2 )['product_id'] && $store->link_species_product( 2, 0 ) && null === $store->species( 2 )['product_id'] );
check( 'an unknown row cannot be linked', false === $store->link_species_product( 99, 13 ) );
$today = gmdate( 'Y-m-d' );
$stay  = $store->create_stay( array( 'species' => 'Blue Test Wrasse', 'product_id' => 12, 'checkin_date' => $today, 'tank_id' => 1 ) );
$store->record_event( $stay, array( 'type' => 'arrival', 'qty' => 3, 'to_tank_id' => 1 ), $today, array() );
$stay2 = $store->create_stay( array( 'species' => 'green test BLENNY', 'checkin_date' => $today, 'tank_id' => 2 ) );
$store->record_event( $stay2, array( 'type' => 'arrival', 'qty' => 2, 'to_tank_id' => 2 ), $today, array() );
$counts = $store->open_stay_counts();
check( 'open stays by product and by lower-cased name', 1 === $counts['product'][12] && 1 === $counts['name']['blue test wrasse'] && 1 === $counts['name']['green test blenny'] && ! isset( $counts['product'][13] ) );
$order = $store->save_order( Orders::normalize_order( array( 'ref' => '2026-1' ) ) );
$store->save_order_lines( $order, Orders::normalize_lines( array( array( 'species' => 'Red Test Goby', 'species_id' => 3, 'qty_ordered' => 1 ) ) ) );
check( 'a species named by an order line is not deleted', 'referenced' === $store->delete_species( 3 ) && null !== $store->species( 3 ) );
check( 'an unreferenced species is deleted; an unknown id is false', true === $store->delete_species( 2 ) && null === $store->species( 2 ) && false === $store->delete_species( 2 ) && 3 === $store->species_count() );

fh_finish();
