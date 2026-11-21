<?php
/**
 * Tidy up the sizes — the thinking (Sizes_Tidy, pure), with an invented
 * shop on the in-memory Fake_Tidy_Shop: how a label is read, the rule
 * that suggests what each of the shop's 31 sizes becomes (the expected
 * map is written out row by row), odd labels, a short list that is
 * edited (a size added, reordered, removed while rows point at it), the
 * owner's own choices, and the dry run — the clean renames, the
 * products where two variations would become one, the sizes typed on a
 * product, the sizes taken off (out of stock now; for sale today: left,
 * moved or taken off), a product left with nothing to buy, the terms
 * that go, wait or retire, the other places a size is stored, and the
 * totals.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sizes-tidy-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy-run.php';
require_once __DIR__ . '/fake-tidy-shop.php';
require_once __DIR__ . '/tidy-fixture.php';

use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;

echo "1. Reading a label\n";
$read = static function ( $label ) {
	$size = Sizes_Tidy::parse( $label );
	return $size['kind'] . ( null !== $size['lo'] ? ' ' . $size['lo'] : '' ) . ( null !== $size['hi'] ? '-' . $size['hi'] : '' );
};
check_same( 'an inch range', 'range 2-3', $read( '2-3"' ) );
check_same( 'spaces and a decimal', 'range 2-3.5', $read( '2 - 3.5"' ) );
check_same( 'curly inch marks', 'range 3-4', $read( '3”-4”' ) );
check_same( 'stored with entities', 'range 1-2', $read( '1-2&quot;' ) );
check_same( 'an open-ended size', 'plus 10', $read( '10"+' ) );
check_same( 'the plus before the mark, or in words', array( 'plus 5', 'plus 6', 'plus 4' ), array( $read( '5+"' ), $read( '6 inches and up' ), $read( 'over 4"' ) ) );
check_same( 'under a number', 'under 0-1', $read( '<1"' ) );
check_same( 'under a number, as it is stored', 'under 0-1', $read( '&lt;1&quot;' ) );
check_same( 'a lone number is a range of itself', 'range 3-3', $read( '3"' ) );
check_same( 'a range written "to", backwards', 'range 2-4', $read( '4 to 2 in' ) );
check_same( 'a fish on backorder reads so; one with stock counted reads its number', array( 'on backorder', 'on backorder (-2)', '3 in stock', 'in stock', 'out of stock' ), array( Sizes_Tidy::stock_text( array( 'stock' => 0, 'stock_status' => 'onbackorder' ) ), Sizes_Tidy::stock_text( array( 'stock' => -2, 'stock_status' => 'onbackorder' ) ), Sizes_Tidy::stock_text( array( 'stock' => 3, 'stock_status' => 'instock' ) ), Sizes_Tidy::stock_text( array( 'stock' => null, 'stock_status' => 'instock' ) ), Sizes_Tidy::stock_text( array( 'stock' => 0, 'stock_status' => 'outofstock' ) ) ) );
check_same( 'a word has no numbers the rule can read', array( 'word', 'word', 'word', 'word' ), array( $read( 'Show size' ), $read( 'Medium' ), $read( 'Size 3 or so' ), $read( '' ) ) );
check( 'a word between two size words', Sizes_Tidy::between( 'Small-Medium' ) && Sizes_Tidy::between( 'Medium-Large' ) && Sizes_Tidy::between( 'Large-XL' ) && Sizes_Tidy::between( 'Large / X-Large' ) && Sizes_Tidy::between( 'small to medium' ) );
check( 'one word is not between two: Medium, X-Large, Show size, Pair, Large-Large', ! Sizes_Tidy::between( 'Medium' ) && ! Sizes_Tidy::between( 'X-Large' ) && ! Sizes_Tidy::between( 'Show size' ) && ! Sizes_Tidy::between( 'Pair' ) && ! Sizes_Tidy::between( 'Large-Large' ) && ! Sizes_Tidy::between( '2-3"' ) );

echo "2. The short list\n";
$shop  = fh_tidy_shop();
$terms = $shop->read_shop( 'pa_size' )['terms'];
$short = Sizes_Tidy::short_list( array(), $terms );
check_same( 'nothing typed: the default list, ten sizes, in order', array( '1-2', '2-3', '3-4', '4-5', '5', 'small', 'medium', 'large', 'pair', 'all' ), array_column( $short, 'slug' ) );
check_same( 'the default is the constant, whatever its length', count( Sizes_Tidy::DEFAULT_LIST ), count( $short ) );
check( 'every one of them is a term already (5"+ found by its name, whatever its slug)', array_fill( 0, 10, true ) === array_column( $short, 'exists' ) );
check_same( 'the labels are the terms\' own, as plain text', '5"+', $short[4]['label'] );
$short2 = Sizes_Tidy::short_list( "Small\n 1-2\" \n6-8\"\nsmall\n\nNano", $terms );
check_same( 'a typed list: its own order, repeats dropped', array( 'small', '1-2', '6-8', 'nano' ), array_column( $short2, 'slug' ) );
check_same( 'a size that has no term yet is one to create', array( true, true, false, false ), array_column( $short2, 'exists' ) );
check_same( 'a short list on a shop without terms: every size is to create', array( false, false ), array_column( Sizes_Tidy::short_list( array( 'A', 'B' ), array() ), 'exists' ) );

echo "3. The rule, on the shop's 31 sizes (default short list)\n";
$rows = Sizes_Tidy::rows( $shop->read_shop( 'pa_size' ), $short );
$got  = array();
foreach ( $rows as $row ) {
	if ( ! $row['typed'] ) {
		$got[ $row['label'] ] = $row['action'];
	}
}
$expected = array(
	'<1"'          => 'to:1-2',
	'1-2"'         => 'stay',
	'1-2.5"'       => 'to:1-2',
	'1-3"'         => 'to:1-2',
	'2"+'          => 'to:2-3',
	'2-3"'         => 'stay',
	'2-3.5"'       => 'to:2-3',
	'2-4"'         => 'to:2-3',
	'2.5-4"'       => 'to:3-4',
	'3"+'          => 'to:3-4',
	'3-4"'         => 'stay',
	'3-5"'         => 'to:3-4',
	'3.5"+'        => 'to:3-4',
	'4"+'          => 'to:4-5',
	'4-5"'         => 'stay',
	'4-6"'         => 'to:4-5',
	'5"+'          => 'stay',
	'5-6"'         => 'to:5',
	'5-7"'         => 'to:5',
	'6"+'          => 'to:5',
	'7"+'          => 'to:5',
	'All'          => 'stay',
	'Large'        => 'stay',
	'Large-XL'     => 'remove',
	'Medium'       => 'stay',
	'Medium-Large' => 'remove',
	'Pair'         => 'stay',
	'Small'        => 'stay',
	'Small-Medium' => 'remove',
	'Tiny'         => 'remove',
	'XL'           => 'remove',
);
check_same( 'the shop has 31 sizes', 31, count( $shop->terms ) );
check_same( 'the rows read in order: the inch sizes by where they start (then where they end, "and up" last), then the words by name', array( '<1"', '1-2"', '1-2.5"', '1-3"', '2-3"', '2-3.5"', '2-4"', '2"+', '2.5-4"', '3-4"', '3-5"', '3"+', '3.5"+', '4-5"', '4-6"', '4"+', '5-6"', '5-7"', '5"+', '6"+', '7"+', 'All', 'Large', 'Large-XL', 'Medium', 'Medium-Large', 'Pair', 'Small', 'Small-Medium', 'Tiny', 'XL' ), array_keys( $got ) );
foreach ( $expected as $label => $action ) {
	check_same( $label . ' → ' . $action, $action, isset( $got[ $label ] ) ? $got[ $label ] : '(no row)' );
}
/**
 * The rows by label: the terms' rows, or the typed sizes' rows.
 */
function fh_rows_by( array $rows, $typed = false ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( $row['typed'] === $typed ) {
			$by[ $row['label'] ] = $row;
		}
	}
	return $by;
}
$by    = fh_rows_by( $rows );
$typed = fh_rows_by( $rows, true );
check_same( 'the sizes typed on a product have rows of their own', array( '1-2"', '2 - 3.5"', '2-3"', 'About 5 inches', 'Jumbo-ish' ), fh_sorted( array_keys( $typed ) ) );
check( '…after every term', true === $rows[ count( $rows ) - 1 ]['typed'] && false === $rows[30]['typed'] && true === $rows[31]['typed'] );
check_same( 'the "and up" sizes are marked, quietly — no question asked', array( 'plus' ), $by['3"+']['flags'] );
check_same( 'a size below the smallest goes to the smallest, flagged', array( 'below' ), $by['<1"']['flags'] );
check_same( 'the in-between words are taken off, and say why', array( array( 'between' ), array( 'between' ), array( 'between' ) ), array( $by['Small-Medium']['flags'], $by['Medium-Large']['flags'], $by['Large-XL']['flags'] ) );
check_same( 'a size no product uses is removed, and says why', array( array( 'unused' ), array( 'unused' ) ), array( $by['Tiny']['flags'], $by['XL']['flags'] ) );
check_same( 'a row counts its products and variations', array( 2, 2 ), array( $by['3-5"']['products'], $by['3-5"']['variations'] ) );
check_same( 'and where else the size is stored', array( 'stays' => 2, 'order_lines' => 1, 'requests' => 1, 'lostfish_cases' => 2, 'invoice_lines' => 1, 'box_shares' => 1 ), $by['3-5"']['stores'] );
check_same( 'and the past order lines that carry it', 14, $by['3-5"']['orders'] );
check_same( 'a typed size that reads like one of the list goes to it; one that reads like an inch size follows the rule; a word is kept', array( 'to:2-3', 'to:1-2', 'to:2-3', 'keep', 'keep' ), array( $typed['2-3"']['action'], $typed['1-2"']['action'], $typed['2 - 3.5"']['action'], $typed['About 5 inches']['action'], $typed['Jumbo-ish']['action'] ) );

echo "4. Odd labels, and other short lists\n";
$suggest = static function ( $label, array $short, array $more = array() ) {
	return Sizes_Tidy::suggest( array_merge( array( 'slug' => 'x-' . md5( $label ), 'label' => $label, 'typed' => false, 'used' => true ), $more ), $short )['action'];
};
check_same( '2 - 3.5" → 2-3"', 'to:2-3', $suggest( '2 - 3.5"', $short ) );
check_same( '3”-4” is the list\'s 3-4" written another way', 'to:3-4', $suggest( '3”-4”', $short ) );
check_same( '10"+ → the top size', 'to:5', $suggest( '10"+', $short ) );
check_same( '"Show size" is a word a product uses: kept, never guessed into inches', 'keep', $suggest( 'Show size', $short ) );
check_same( '"Show size" that nothing uses is removed', 'remove', $suggest( 'Show size', $short, array( 'used' => false ) ) );
check_same( 'a word that is retiring starts on "take off"', 'remove', $suggest( 'Show size', $short, array( 'retiring' => true ) ) );
check_same( 'an unused INCH size still follows the rule (the stays that carry it follow)', 'to:2-3', $suggest( '2-4"', $short, array( 'used' => false ) ) );
check_same( '4-6": the midpoint 5 is held by 4-5" and by 5"+ — the lower one', 'to:4-5', $suggest( '4-6"', $short ) );
check_same( '3-5": the midpoint 4 is held by 3-4" and 4-5" — the lower one', 'to:3-4', $suggest( '3-5"', $short ) );
check_same( '9-12" → the top size', 'to:5', $suggest( '9-12"', $short ) );
$no_top = Sizes_Tidy::short_list( array( '1-2"', '2-3"', '4-5"', 'Medium' ), array() );
check_same( 'a list without a top size: 6"+ goes to the nearest, flagged', array( 'action' => 'to:4-5', 'flags' => array( 'plus', 'nofit' ) ), Sizes_Tidy::suggest( array( 'slug' => 'six', 'label' => '6"+', 'used' => true ), $no_top ) );
check_same( 'a gap in the list (3-4" missing): 3.2-3.6" goes to the nearest, flagged', array( 'action' => 'to:2-3', 'flags' => array( 'nofit' ) ), Sizes_Tidy::suggest( array( 'slug' => 'gap', 'label' => '3.2-3.6"', 'used' => true ), $no_top ) );
check_same( '3"+ with no size starting at 3: the one that holds it', 'to:2-3', $suggest( '3"+', $no_top ) );
$words = Sizes_Tidy::short_list( array( 'Small', 'Medium', 'Large' ), array() );
check_same( 'a list of words only: an inch size has nowhere to go — kept, flagged', array( 'action' => 'keep', 'flags' => array( 'nofit' ) ), Sizes_Tidy::suggest( array( 'slug' => '2-3', 'label' => '2-3"', 'used' => true ), $words ) );
$with_ml = Sizes_Tidy::short_list( array( 'Small', 'Medium', 'Medium-Large', 'Large' ), $terms );
check_same( 'an in-between word the owner types into the list stays', 'stay', Sizes_Tidy::suggest( array( 'slug' => 'medium-large', 'label' => 'Medium-Large', 'used' => true ), $with_ml )['action'] );
$wide = Sizes_Tidy::short_list( array( '<2"', '2-4"', '4-6"', '6"+' ), array() );
check_same( 'another inch list altogether: <1" → <2", 1-2" → <2", 2-3.5" → 2-4", 3-5" → 2-4", 5-7" → 4-6", 7"+ → 6"+, 5"+ → 4-6"', array( 'to:2', 'to:2', 'to:2-4', 'to:2-4', 'to:4-6', 'to:6', 'to:4-6' ), array( $suggest( '<1"', $wide ), $suggest( '1-2"', $wide ), $suggest( '2-3.5"', $wide ), $suggest( '3-5"', $wide ), $suggest( '5-7"', $wide ), $suggest( '7"+', $wide ), $suggest( '5"+', $wide ) ) );

echo "5. The short list edited, and the owner's own choices\n";
$data  = $shop->read_shop( 'pa_size' );
$added = Sizes_Tidy::short_list( array_merge( Sizes_Tidy::DEFAULT_LIST, array( 'Medium-Large', '6-8"' ) ), $terms );
$rows  = Sizes_Tidy::rows( $data, $added );
$by    = fh_rows_by( $rows );
check_same( 'a size added to the list: Medium-Large now stays', 'stay', $by['Medium-Large']['action'] );
check_same( 'a new inch size sits under the top size: 5-7" still goes to 5"+ (its midpoint 6 is held by 5"+ and 6-8"… the lower)', 'to:5', $by['5-7"']['action'] );
$rows = Sizes_Tidy::rows( $data, $short, array( 'over' => array( 'g:3-5' => 'to:4-5', 'g:3' => 'keep', 'g:medium-large' => 'to:large', 'g:tiny' => 'keep', 'g:small' => 'remove', 'g:2-4' => 'to:nowhere', 'g:4-6' => 'to:4-6', 't:about-5-inches' => 'to:5', 't:jumbo-ish' => 'remove' ) ) );
$by    = fh_rows_by( $rows );
$typed = fh_rows_by( $rows, true );
check_same( 'the owner overrules a row: another size of the list, keep, a word folded into its neighbour', array( 'to:4-5', 'keep', 'to:large', 'keep' ), array( $by['3-5"']['action'], $by['3"+']['action'], $by['Medium-Large']['action'], $by['Tiny']['action'] ) );
check( 'an overruled row says so', in_array( 'yours', $by['3-5"']['flags'], true ) && ! in_array( 'yours', $by['1-3"']['flags'], true ) );
check_same( 'a size of the short list cannot be sent anywhere: it stays', 'stay', $by['Small']['action'] );
check_same( 'a choice that is not one (a size that is not in the list, itself): the suggestion, flagged', array( 'to:2-3', 'to:4-5', true, true ), array( $by['2-4"']['action'], $by['4-6"']['action'], in_array( 'gone', $by['2-4"']['flags'], true ), in_array( 'gone', $by['4-6"']['flags'], true ) ) );
check_same( 'a typed size can be sent to a size of the list, never removed', array( 'to:5', 'keep' ), array( $typed['About 5 inches']['action'], $typed['Jumbo-ish']['action'] ) );
// The list is edited while a row points at a size that is then removed from it.
$fewer = Sizes_Tidy::short_list( array( '1-2"', '2-3"', '3-4"', '5"+', 'Small', 'Medium', 'Large' ), $terms );
$rows  = Sizes_Tidy::rows( $data, $fewer, array( 'over' => array( 'g:3-5' => 'to:4-5', 'g:4' => 'to:4-5', 'g:2-4' => 'to:3-4' ) ) );
$by    = fh_rows_by( $rows );
check_same( '4-5" removed from the list while rows point at it: they go back to the rule for the new list (3-5" and 4"+ are both held by 3-4" now), flagged', array( 'to:3-4', true, 'to:3-4', true ), array( $by['3-5"']['action'], in_array( 'gone', $by['3-5"']['flags'], true ), $by['4"+']['action'], in_array( 'gone', $by['4"+']['flags'], true ) ) );
check_same( 'a row that points at a size still in the list keeps the owner\'s choice', 'to:3-4', $by['2-4"']['action'] );
check_same( '4-5" itself is now an old size: it follows the rule (its midpoint 4.5 has no holder — the nearest), flagged', array( 'to:3-4', array( 'nofit' ) ), array( $by['4-5"']['action'], $by['4-5"']['flags'] ) );
check_same( 'Pair and All left the list too: words a product uses are kept, flagged; unused ones removed', array( 'keep', 'remove' ), array( $by['Pair']['action'], $by['All']['action'] ) );
$reordered = Sizes_Tidy::short_list( array( 'Large', 'Medium', 'Small', '5"+', '4-5"', '3-4"', '2-3"', '1-2"', 'Pair', 'All' ), $terms );
$plan      = Sizes_Tidy::plan( $data, array( 'short' => Sizes_Tidy::short_labels( $reordered ) ) );
check_same( 'the list reordered: the same map', Sizes_Tidy::plan( $data )['map'], $plan['map'] );
check_same( '…and the attribute\'s order follows the list', array( 'large', 'medium', 'small', '5', '4-5' ), array_slice( Sizes_Tidy::order_after( $plan, Sizes_Tidy::order_now( array_column( $data['terms'], null, 'slug' ) ) ), 0, 5 ) );
$rows = Sizes_Tidy::rows( $data, $short, array( 'keep_plus' => true, 'over' => array( 'g:6' => 'to:5' ) ) );
$by   = fh_rows_by( $rows );
check_same( '"keep every and-up size": 2"+, 3"+, 3.5"+, 4"+, 7"+ are kept; the one the owner set stays as set; 5"+ is in the list', array( 'keep', 'keep', 'keep', 'keep', 'keep', 'to:5', 'stay' ), array( $by['2"+']['action'], $by['3"+']['action'], $by['3.5"+']['action'], $by['4"+']['action'], $by['7"+']['action'], $by['6"+']['action'], $by['5"+']['action'] ) );

echo "6. The dry run (default list, default choices)\n";
$before = $shop->dump();
$plan   = Sizes_Tidy::plan( $data );
$ids    = fh_tidy_ids( $shop );
check( 'a dry run writes nothing', $before === $shop->dump() && 0 === $shop->writes );
check_same( 'the same shop and the same choices: the same token', $plan['token'], Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) )['token'] );
$change = array();
foreach ( $plan['changes'] as $row ) {
	$change[ $row['title'] ][] = $row['from_label'] . '>' . $row['to_label'] . ':' . $row['why'];
}
check_same( 'Clean — a variation that only changes its size name', array( '2-3.5">2-3":rename' ), $change['Banded Goby'] );
check_same( 'two renames on one product', array( '3"+>3-4":rename', '4-6">4-5":rename' ), $change['Dwarf Lion'] );
check( 'a product whose sizes are all in the list is not in the plan', ! isset( $plan['products'][ $ids['Azure Damsel'] ] ) && ! isset( $change['Azure Damsel'] ) );
check( 'a product that is not a fish but uses the attribute follows the same map', ! isset( $plan['products'][ $ids['Feeding Ring'] ] ) );
check_same( 'the product\'s own list of sizes follows its variations', array( '1-2', '2-3' ), $plan['products'][ $ids['Banded Goby'] ]['sizes'] );
check_same( 'and its default size', '2-3', $plan['products'][ $ids['Banded Goby'] ]['default'] );
check_same( 'Two become one — 3-4" and 3-5" of one product: it is listed and left untouched', array( true, false, false ), array( isset( $plan['two'][ $ids['Coral Beauty'] ] ), $plan['two'][ $ids['Coral Beauty'] ]['resolved'], isset( $plan['products'][ $ids['Coral Beauty'] ] ) ) );
$group = array_values( $plan['two'][ $ids['Coral Beauty'] ]['groups'] )[0];
check_same( 'the two variations are shown with their price, stock and SKU', array( array( '30.00', '2 in stock', 'CB-34' ), array( '35.00', '1 in stock', 'CB-35' ) ), array( array( $group['members'][0]['price'], $group['members'][0]['stock_text'], $group['members'][0]['sku'] ), array( $group['members'][1]['price'], $group['members'][1]['stock_text'], $group['members'][1]['sku'] ) ) );
check( 'a switched-off variation that lands on its switched-on sister\'s size is no clash: it is only renamed', ! isset( $plan['two'][ $ids['Lemon Tang'] ] ) && array( '2-4">2-3":rename' ) === $change['Lemon Tang'] );
check( 'two sizes that become one on DIFFERENT sexes are still two variations', ! isset( $plan['two'][ $ids['Mandarin Pair'] ] ) && array( '3-5">3-4":rename' ) === $change['Mandarin Pair'] );
check_same( 'Typed on the product — listed, not converted unless ticked', array( false, false ), array( $plan['typed'][ $ids['Koi Angel'] ]['ticked'], isset( $plan['products'][ $ids['Koi Angel'] ] ) ) );
check_same( 'a typed product whose every size has a size of the list can be converted', array( true, '' ), array( $plan['typed'][ $ids['Koi Angel'] ]['ok'], $plan['typed'][ $ids['Koi Angel'] ]['why'] ) );
check_same( 'one with a size that has none cannot, and says so', array( false, 'nosize' ), array( $plan['typed'][ $ids['Jade Blenny'] ]['ok'], $plan['typed'][ $ids['Jade Blenny'] ]['why'] ) );

echo "7. The in-between words: taken off, never deleted\n";
check_same( 'out of stock: taken off now — switched off, the size gone', array( 'Small-Medium>:off_out' ), $change['Flame Hawk'] );
check_same( '…the variation loses its size and is switched off; the product\'s list loses the term', array( array( 'from' => 'small-medium', 'to' => '', 'off' => true, 'why' => 'off_out' ), array( 'medium' ) ), array( array_values( $plan['products'][ $ids['Flame Hawk'] ]['vars'] )[0], $plan['products'][ $ids['Flame Hawk'] ]['sizes'] ) );
check_same( 'for sale today: left as it is (the default), named with its stock', array( 'Medium-Large>Medium-Large:left' ), $change['Golden Wrasse'] );
check( 'a product whose only change would be a fish left alone is not in the plan', ! isset( $plan['products'][ $ids['Golden Wrasse'] ] ) && ! isset( $plan['products'][ $ids['Harlequin Tusk'] ] ) );
$left = array_values( array_filter( $plan['changes'], static function ( $row ) { return 'left' === $row['why']; } ) );
check_same( 'the fish for sale today that carry one of these sizes, by name, with their stock', array( array( 'Golden Wrasse', '2 in stock' ), array( 'Harlequin Tusk', '1 in stock' ), array( 'Neon Dottyback', 'on backorder' ) ), array_map( static function ( $row ) { return array( $row['title'], $row['stock_text'] ); }, $left ) );
check_same( 'their sizes stay in the shop, retiring', array( 'large-xl' => 'Large-XL', 'medium-large' => 'Medium-Large' ), $plan['retiring'] );
check_same( '…and say how many fish are left on each', array( 1, 2 ), array( fh_rows_by( $plan['rows'] )['Medium-Large']['left'], fh_rows_by( $plan['rows'] )['Large-XL']['left'] ) );
check( 'a size nothing carries any more is deleted: Small-Medium, Tiny, XL', array() === array_diff( array( 'small-medium', 'tiny', 'xl' ), $plan['delete'] ) && ! in_array( 'medium-large', $plan['delete'], true ) );
check_same( 'a fish with nothing left to buy is listed', array( 'Ivory Puffer' ), array_values( array_column( $plan['unavailable'], 'title' ) ) );
check_same( 'the product that lost a size goes on the "Sizes to fill in" list, with the size it had', array( 'slug' => 'large-xl', 'label' => 'Large-XL' ), array_values( $plan['products'][ $ids['Ivory Puffer'] ]['fill'] )[0] );
check( 'a draft product\'s variation is not for sale: taken off now', array( 'Small-Medium>:off_out' ) === $change['Opal Jawfish'] );

$off = Sizes_Tidy::plan( $data, array( 'instock' => 'off' ) );
check_same( '"take them off the shop now": the fish for sale are switched off too', array( 'off_now', true, '' ), array( array_values( $off['products'][ $ids['Golden Wrasse'] ]['vars'] )[0]['why'], array_values( $off['products'][ $ids['Golden Wrasse'] ]['vars'] )[0]['off'], array_values( $off['products'][ $ids['Golden Wrasse'] ]['vars'] )[0]['to'] ) );
check_same( '…nothing retires, the three words are deleted', array( array(), true ), array( $off['retiring'], array() === array_diff( array( 'small-medium', 'medium-large', 'large-xl' ), $off['delete'] ) ) );
check_same( '…and two more fish have nothing left to buy', array( 'Harlequin Tusk', 'Ivory Puffer', 'Neon Dottyback' ), array_values( array_column( $off['unavailable'], 'title' ) ) );
$wrasse = $shop->vids( $ids['Golden Wrasse'] );
$tusk   = $shop->vids( $ids['Harlequin Tusk'] );
$move   = Sizes_Tidy::plan( $data, array( 'instock' => 'move', 'move' => array( $wrasse[0] => 'medium', $tusk[0] => 'nowhere' ) ) );
check_same( '"move them to a size I pick": the one with a size picked moves', array( 'from' => 'medium-large', 'to' => 'medium', 'off' => false, 'why' => 'move' ), $move['products'][ $ids['Golden Wrasse'] ]['vars'][ $wrasse[0] ] );
check( '…one without a pick (or with a size that is not in the list) is left as it is', ! isset( $move['products'][ $ids['Harlequin Tusk'] ] ) && array( 'large-xl' => 'Large-XL' ) === $move['retiring'] );
$move = Sizes_Tidy::plan( $data, array( 'instock' => 'move', 'move' => array( $wrasse[0] => 'large' ) ) );
check( 'a fish moved onto a size its product already sells: two become one, the product waits', isset( $move['two'][ $ids['Golden Wrasse'] ] ) && ! $move['two'][ $ids['Golden Wrasse'] ]['resolved'] && ! isset( $move['products'][ $ids['Golden Wrasse'] ] ) );
$members = array_values( $move['two'][ $ids['Golden Wrasse'] ]['groups'] )[0]['members'];
check_same( '…the plan marks which of the two is there by the owner\'s pick (the page has to carry that pick along)', array( $wrasse[0] => true, $wrasse[1] => false ), array_column( $members, 'moved', 'id' ) );
$gkey = array_keys( $move['two'][ $ids['Golden Wrasse'] ]['groups'] )[0];
$both = Sizes_Tidy::plan( $data, array( 'instock' => 'move', 'move' => array( $wrasse[0] => 'large' ), 'keep_one' => array( $ids['Golden Wrasse'] => array( $gkey => $wrasse[1] ) ) ) );
check_same( '…with the one to keep picked — the Large it sold already — the moved one becomes Large too and is switched off, kept', array( true, array( 'from' => 'medium-large', 'to' => 'large', 'off' => true, 'why' => 'dupe_off' ) ), array( $both['two'][ $ids['Golden Wrasse'] ]['resolved'], $both['products'][ $ids['Golden Wrasse'] ]['vars'][ $wrasse[0] ] ) );
check( '…and without the pick the same "keep one" means nothing: there is no clash to resolve', ! isset( Sizes_Tidy::plan( $data, array( 'instock' => 'move', 'keep_one' => array( $ids['Golden Wrasse'] => array( $gkey => $wrasse[1] ) ) ) )['two'][ $ids['Golden Wrasse'] ] ) );
check_same( 'a wrong choice for the fish in stock falls back to "leave"', 'leave', Sizes_Tidy::plan( $data, array( 'instock' => 'sell' ) )['instock'] );

echo "8. Two become one, resolved by keeping one\n";
$coral = $shop->vids( $ids['Coral Beauty'] );
$gkey  = array_keys( $plan['two'][ $ids['Coral Beauty'] ]['groups'] )[0];
$kept  = Sizes_Tidy::plan( $data, array( 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $coral[0] ) ) ) );
check_same( 'the one kept is untouched; the other takes the size and is switched off — kept, not deleted', array( $coral[1] => array( 'from' => '3-5', 'to' => '3-4', 'off' => true, 'why' => 'dupe_off' ) ), $kept['products'][ $ids['Coral Beauty'] ]['vars'] );
check_same( 'the product is resolved: nothing needs a look any more', array( true, 0 ), array( $kept['two'][ $ids['Coral Beauty'] ]['resolved'], $kept['totals']['look'] ) );
$kept2 = Sizes_Tidy::plan( $data, array( 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $coral[1] ) ) ) );
check_same( 'keeping the other one instead: it is renamed, its sister switched off', array( $coral[0] => array( 'from' => '3-4', 'to' => '3-4', 'off' => true, 'why' => 'dupe_off' ), $coral[1] => array( 'from' => '3-5', 'to' => '3-4', 'off' => false, 'why' => 'rename' ) ), $kept2['products'][ $ids['Coral Beauty'] ]['vars'] );
check( 'a variation of another product is not a choice', ! Sizes_Tidy::plan( $data, array( 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $coral[0] + 99999 ) ) ) )['two'][ $ids['Coral Beauty'] ]['resolved'] );
check( 'while a product waits, the old size it carries is not deleted: it waits too', in_array( '3-5', array_keys( $plan['waiting'] ), false ) && ! in_array( '3-5', $plan['delete'], true ) && in_array( '3-5', $kept['delete'], true ) );
check_same( '…and says how many products hold it', 1, fh_rows_by( $plan['rows'] )['3-5"']['stuck'] );

echo "9. Typed on the product, converted only when ticked\n";
$conv = Sizes_Tidy::plan( $data, array( 'convert' => array( $ids['Koi Angel'] => 1, $ids['Jade Blenny'] => 1 ) ) );
$koi  = $shop->vids( $ids['Koi Angel'] );
check_same( 'ticked and convertible: every variation gets the term, the product the attribute', array( array( 'key' => 'size', 'values' => array( '1-2"' => '1-2', '2 - 3.5"' => '2-3' ) ), array( '1-2', '2-3' ), '1-2' ), array( $conv['products'][ $ids['Koi Angel'] ]['convert'], $conv['products'][ $ids['Koi Angel'] ]['sizes'], $conv['products'][ $ids['Koi Angel'] ]['default'] ) );
check_same( '…each variation by the same map', array( 'from' => '2 - 3.5"', 'to' => '2-3', 'off' => false, 'why' => 'convert' ), $conv['products'][ $ids['Koi Angel'] ]['vars'][ $koi[1] ] );
check( 'ticked but not convertible: left alone', ! isset( $conv['products'][ $ids['Jade Blenny'] ] ) );
$conv = Sizes_Tidy::plan( $data, array( 'convert' => array( $ids['Jade Blenny'] => 1 ), 'over' => array( 't:about-5-inches' => 'to:5', 't:jumbo-ish' => 'to:5' ) ) );
check_same( 'two typed sizes sent to one size: two would become one — not converted, and it says so', array( false, 'two', false ), array( $conv['typed'][ $ids['Jade Blenny'] ]['ok'], $conv['typed'][ $ids['Jade Blenny'] ]['why'], isset( $conv['products'][ $ids['Jade Blenny'] ] ) ) );
$conv = Sizes_Tidy::plan( $data, array( 'convert' => array( $ids['Jade Blenny'] => 1 ), 'over' => array( 't:about-5-inches' => 'to:5', 't:jumbo-ish' => 'to:large' ) ) );
check_same( 'each typed size given its own size of the list: converted', array( '2-3"' => '2-3', 'About 5 inches' => '5', 'Jumbo-ish' => 'large' ), $conv['products'][ $ids['Jade Blenny'] ]['convert']['values'] );

echo "10. The totals, and the terms\n";
$totals = $plan['totals'];
check_same( 'sizes before → after', array( 31, 31 - count( $plan['delete'] ) ), array( $totals['sizes_before'], $totals['sizes_after'] ) );
check_same( 'the short list\'s real length', count( Sizes_Tidy::DEFAULT_LIST ), $totals['short'] );
check_same( 'variations renamed, switched off, left; products that need a look; typed products waiting', array( 5, 3, 3, 1, 2 ), array( $totals['renamed'], $totals['off'], $totals['left'], $totals['look'], $totals['typed'] ) );
check_same( 'variations deleted: none, ever', 0, $totals['deleted'] );
check_same( 'nothing to create: every size of the default list is a term', array(), $plan['create'] );
check_same( 'the old sizes that go — 18 of the 21 old ones: 3-5" waits for a product, two words retire', array( '1-2-5', '1-3', '2', '2-3-5', '2-4', '2-5-4', '3', '3-5-2', '4', '4-6', '5-6', '5-7', '6', '7', 'lt-1', 'small-medium', 'tiny', 'xl' ), fh_sorted( $plan['delete'] ) );
check_same( 'with everything resolved and the fish in stock taken off: 31 sizes become the ten of the list', 10, Sizes_Tidy::plan( $data, array( 'instock' => 'off', 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $coral[0] ) ) ) )['totals']['sizes_after'] );
$more = Sizes_Tidy::plan( $data, array( 'short' => array_merge( Sizes_Tidy::DEFAULT_LIST, array( 'Nano', '6-8"' ) ) ) );
check_same( 'a size typed into the list that has no term yet is created', array( array( 'slug' => 'nano', 'label' => 'Nano' ), array( 'slug' => '6-8', 'label' => '6-8"' ) ), $more['create'] );
check_same( '…and counted', 12, $more['totals']['short'] );
check( 'a different list is a different plan', $more['token'] !== $plan['token'] );

echo "11. The other places a size is stored\n";
check_same( 'only a size that BECOMES another is rewritten there', array( 'slug' => '3-4', 'label' => '3-4"' ), $plan['targets']['3-5'] );
check( 'a size that is taken off is not: the fish in the tanks keep it', ! isset( $plan['targets']['small-medium'] ) && ! isset( $plan['targets']['medium'] ) );
check_same( 'a stay / an order line / a request', array( 'size_slug' => '3-4', 'size_label' => '3-4"' ), Sizes_Tidy::remap_row( array( 'id' => 1, 'size_slug' => '3-5', 'size_label' => '3-5"' ), $plan['targets'] ) );
check( 'a row whose size stays, or that has none, is not touched', null === Sizes_Tidy::remap_row( array( 'size_slug' => 'medium', 'size_label' => 'Medium' ), $plan['targets'] ) && null === Sizes_Tidy::remap_row( array( 'size_slug' => null ), $plan['targets'] ) && null === Sizes_Tidy::remap_row( array( 'size_slug' => 'small-medium' ), $plan['targets'] ) );
check_same( 'a lost-fish case without a product: its kind key ends in the size', array( 'size_slug' => '3-4', 'size_label' => '3-4"', 'kind_key' => 's:coral beauty|3-4' ), Sizes_Tidy::remap_row( array( 'size_slug' => '3-5', 'size_label' => '3-5"', 'kind_key' => 's:coral beauty|3-5', 'product_id' => 0 ), $plan['targets'] ) );
check_same( 'a lost-fish case with a product: its kind key is the product, left alone', array( 'size_slug' => '3-4', 'size_label' => '3-4"' ), Sizes_Tidy::remap_row( array( 'size_slug' => '3-5', 'size_label' => '3-5"', 'kind_key' => 'p:77', 'product_id' => 77 ), $plan['targets'] ) );
$loc    = Box_Shares::location_key( 'Reef Co', 'Bali' );
$shares = array(
	array( 'id' => 1, 'share_key' => Box_Shares::share_key( $loc, 'id:5', '3-5' ), 'location_key' => $loc, 'fish_key' => 'id:5', 'size_slug' => '3-5', 'size_label' => '3-5"', 'pct_per_fish' => '2.5000', 'order_count' => 3, 'last_date' => '2026-03-01' ),
	array( 'id' => 2, 'share_key' => Box_Shares::share_key( $loc, 'id:5', '3-4' ), 'location_key' => $loc, 'fish_key' => 'id:5', 'size_slug' => '3-4', 'size_label' => '3-4"', 'pct_per_fish' => '2.0000', 'order_count' => 4, 'last_date' => '2026-05-01' ),
	array( 'id' => 3, 'share_key' => Box_Shares::share_key( $loc, 'id:9', '2-4' ), 'location_key' => $loc, 'fish_key' => 'id:9', 'size_slug' => '2-4', 'size_label' => '2-4"', 'pct_per_fish' => '1.0000', 'order_count' => 1, 'last_date' => '2026-02-01' ),
);
$moved  = Sizes_Tidy::remap_shares( $shares, $plan['targets'] );
check_same( 'two remembered shares that land on one key become one: the latest wins, the counts add up', array( array( 2 => array( 'order_count' => 7 ), 3 => array( 'size_slug' => '2-3', 'size_label' => '2-3"', 'share_key' => Box_Shares::share_key( $loc, 'id:9', '2-3' ) ) ), array( 1 ) ), array( $moved['update'], $moved['delete'] ) );
check_same( '…and the order lines that counted in the old key follow', array( $shares[0]['share_key'] => $shares[1]['share_key'], $shares[2]['share_key'] => Box_Shares::share_key( $loc, 'id:9', '2-3' ) ), $moved['keys'] );
$shares[0]['last_date'] = '2026-06-01';
$moved                  = Sizes_Tidy::remap_shares( $shares, $plan['targets'] );
check_same( 'when the share that moves is the later one, it wins and takes the key', array( array( 'size_slug' => '3-4', 'size_label' => '3-4"', 'share_key' => $shares[1]['share_key'], 'order_count' => 7 ), array( 2 ) ), array( $moved['update'][1], $moved['delete'] ) );
$packing = Sizes_Tidy::remap_packing( array( '3-5' => 'bag-l', '3-4' => 'bag-m', '2-3-5' => 'bag-s', 'small-medium' => 'bag-s', 'medium' => 'bag-m' ), $plan['targets'] );
check_same( 'the Packing map: a size that becomes another hands its bag on; a size that is taken off keeps its row', array( '3-4' => 'bag-m', 'small-medium' => 'bag-s', 'medium' => 'bag-m', '2-3' => 'bag-s' ), $packing['map'] );
check_same( '…and two old sizes with different bags are named', array( array( 'to' => '3-4', 'kept' => 'bag-m', 'from' => '3-5', 'dropped' => 'bag-l' ) ), $packing['clashes'] );
check_same( 'the plan carries it', array( true, 1 ), array( $plan['packing']['changed'], count( $plan['packing']['clashes'] ) ) );

echo "12. Sold as \"any size\": it carries every size its product lists\n";
$shop  = fh_tidy_shop();
$quill = $shop->add_product( 'Quill Anthias', array( 'small-medium' => array( '30.00', 4, 'attrs' => array( 'pa_size' => '' ) ) ) );
$ruby  = $shop->add_product( 'Ruby Basslet', array( 'small-medium' => array( '18.00', 3, 'attrs' => array( 'pa_size' => '' ) ) ), array( 'sizes' => array( 'small-medium', 'medium' ) ) );
$sable = $shop->add_product( 'Sable Chromis', array( 'small-medium' => array( '12.00', 0, 'attrs' => array( 'pa_size' => '' ) ) ) );
$plain = $shop->add_product( 'Tiger Cowrie', array( 'small-medium' => array( '8.00', 2, 'attrs' => array( 'pa_colour' => 'brown' ) ) ), array( 'varies' => false, 'sizes' => array( 'small-medium' ) ) );
$data  = $shop->read_shop( 'pa_size' );
$plan  = Sizes_Tidy::plan( $data );
$by    = array();
foreach ( $plan['changes'] as $row ) {
	$by[ $row['title'] ][] = $row['from_label'] . '>' . $row['to_label'] . ':' . $row['why'] . ( ! empty( $row['any'] ) ? ':any' : '' ) . ( $row['off'] ? ':off' : '' );
}
check_same( 'a fish in stock whose ONE variation is "any size", on a product that lists only Small-Medium: it is for sale today, so it is left — and named', array( 'Small-Medium>Small-Medium:left:any' ), $by['Quill Anthias'] );
check( '…its product is not touched: the list keeps Small-Medium, the fish can still be bought', ! isset( $plan['products'][ $quill ] ) );
check( '…it is not among the fish with nothing left to buy, and the size stays in the shop, retiring', ! isset( $plan['unavailable'][ $quill ] ) && isset( $plan['retiring']['small-medium'] ) && ! in_array( 'small-medium', $plan['delete'], true ) );
check_same( 'a product that lists Small-Medium and Medium: Small-Medium does not vanish from its list either', array( array( 'Small-Medium>Small-Medium:left:any' ), false ), array( $by['Ruby Basslet'], isset( $plan['products'][ $ruby ] ) ) );
check_same( '…the row says how many fish are left on the size: the two sold as any size', 2, fh_rows_by( $plan['rows'] )['Small-Medium']['left'] );
check_same( 'out of stock: taken off like any other — the list loses the size, and with no size left to pick the variation is switched off and listed', array( 'Small-Medium>:off_out:any:off' ), $by['Sable Chromis'] );
check_same( '…as the plan writes it', array( 'from' => '', 'to' => '', 'off' => true, 'why' => 'off_out', 'list' => array( 'small-medium' ) ), array_values( $plan['products'][ $sable ]['vars'] )[0] );
check( '…it is a fish with nothing left to buy, and goes on the "Sizes to fill in" list with the size it had', isset( $plan['unavailable'][ $sable ] ) && array( 'slug' => 'small-medium', 'label' => 'Small-Medium' ) === array_values( $plan['products'][ $sable ]['fill'] )[0] );
check( 'a product that only LISTS the size (it does not vary by it) sells nothing through it: the size just leaves its list', ! isset( $by['Tiger Cowrie'] ) && array() === $plan['products'][ $plain ]['sizes'] && array() === $plan['products'][ $plain ]['vars'] );
check_same( 'the totals count them: one more left for sale, one more switched off', array( 5, 4 ), array( $plan['totals']['left'], $plan['totals']['off'] ) );

$off = Sizes_Tidy::plan( $data, array( 'instock' => 'off' ) );
check_same( '"take them off the shop now": the only size goes, so the any-size variation is switched off — said, with its stock', array( array( 'from' => '', 'to' => '', 'off' => true, 'why' => 'off_now', 'list' => array( 'small-medium' ) ), true ), array( array_values( $off['products'][ $quill ]['vars'] )[0], isset( $off['unavailable'][ $quill ] ) ) );
check_same( '…where another size is left, the list loses Small-Medium and the variation stays for sale in the other', array( array( 'medium' ), false, false ), array( $off['products'][ $ruby ]['sizes'], array_values( $off['products'][ $ruby ]['vars'] )[0]['off'], isset( $off['unavailable'][ $ruby ] ) ) );
$move = Sizes_Tidy::plan( $data, array( 'instock' => 'move', 'move' => array( $shop->vids( $quill )[0] => 'medium' ) ) );
check_same( '"move them to a size I pick": the product lists the picked size instead; the variation still sells it', array( array( 'medium' ), array() ), array( $move['products'][ $quill ]['sizes'], $move['products'][ $quill ]['vars'] ) );
check( '…one without a pick is left', ! isset( $move['products'][ $ruby ] ) && isset( $move['retiring']['small-medium'] ) );
check_same( 'any_size(): a switched-on variation without a size, on a product that lists sizes and varies by them', array( array( $shop->vids( $quill )[0] => true ), array( $shop->vids( $sable )[0] => false ), array(), array() ), array( Sizes_Tidy::any_size( $data['products'][ $quill ], 'pa_size' ), Sizes_Tidy::any_size( $data['products'][ $sable ], 'pa_size' ), Sizes_Tidy::any_size( $data['products'][ $plain ], 'pa_size' ), Sizes_Tidy::any_size( $data['products'][ $ids['Koi Angel'] ], 'pa_size' ) ) );

echo "13. A product in the trash is left alone — so a size it uses stays\n";
$shop          = fh_tidy_shop();
$shop->trashed = array( 'xl' => 2, '3-5' => 1 );
$plan          = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
$rows          = fh_rows_by( $plan['rows'] );
check( 'XL — no product uses it but one in the trash: it is not deleted', ! in_array( 'xl', $plan['delete'], true ) && in_array( 'tiny', $plan['delete'], true ) );
check_same( '…the row says why it stays, and how many products hold it', array( 'trash', 2 ), array( $rows['XL']['after'], $rows['XL']['trash'] ) );
check( '…and it waits, out of the pickers, until the trash is emptied', isset( $plan['waiting']['xl'] ) );
check_same( 'a size that waits for another reason keeps that reason', 'waiting', $rows['3-5"']['after'] );

echo "14. What nobody can buy, and why\n";
$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
$plan = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
$off  = array();
foreach ( $plan['changes'] as $row ) {
	if ( 'off_out' === $row['why'] ) {
		$off[ $row['title'] ] = array( $row['out'], $row['status'], $row['stock_text'] );
	}
}
check_same( 'a variation that is taken off because nobody can buy it says whether it is out of stock, and what its product is — a draft with stock is not "sold out"', array( 'Flame Hawk' => array( true, 'publish', 'out of stock' ), 'Ivory Puffer' => array( true, 'publish', 'out of stock' ), 'Opal Jawfish' => array( false, 'draft', '4 in stock' ) ), $off );
check_same( 'the plan names each product, for what the run says at the end', 'Flame Hawk', $plan['products'][ $ids['Flame Hawk'] ]['title'] );

fh_finish();
