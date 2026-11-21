<?php
/**
 * Tidy up the sizes — Apply and Undo (Sizes_Tidy_Run), on the invented
 * shop of tidy-fixture.php held by the in-memory Fake_Tidy_Shop: the
 * record saved before the first write, the clean renames, a product
 * where two would become one left alone and then resolved by keeping
 * one, a typed size converted only when ticked, the products' own lists,
 * the in-between words taken off (out of stock now; for sale today left
 * to retire, moved, or taken off), a size retiring and then finished, a
 * product left with nothing to buy, the Clean Room's stays keeping a
 * retired size, the "Sizes to fill in" list filling and emptying, the
 * other places a size is stored, old terms deleted only when nothing
 * uses them, a second click, a batch cut off half-way, Undo (a full dump
 * before and after), a second run undone, the stack of three, and a shop
 * of 2,000 variations.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sizes-tidy-run-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy-run.php';
require_once __DIR__ . '/fake-tidy-shop.php';
require_once __DIR__ . '/tidy-fixture.php';

use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy_Run;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Tidy_Shop;

/**
 * Plan, start and run to the end. Returns the last progress.
 */
function fh_tidy_run( Fake_Tidy_Shop $shop, array $request = array(), $batch = 0 ) {
	$plan  = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ), $request );
	$start = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
	if ( ! $start['ok'] ) {
		return $start + array( 'finished' => false, 'status' => $start['why'] );
	}
	return fh_tidy_steps( $shop, $start['run'], $batch );
}

/**
 * Step a run until it is finished (or gone).
 */
function fh_tidy_steps( Fake_Tidy_Shop $shop, $run_id, $batch = 0 ) {
	for ( $i = 0; $i < 5000; $i++ ) {
		$progress = Sizes_Tidy_Run::step( $shop, $run_id, $batch );
		if ( $progress['finished'] ) {
			return $progress;
		}
	}
	return $progress;
}

/**
 * Undo the latest run, to the end.
 */
function fh_tidy_undo( Fake_Tidy_Shop $shop ) {
	$undo = Sizes_Tidy_Run::undo( $shop );
	return $undo['ok'] ? fh_tidy_steps( $shop, $undo['run'] ) : $undo + array( 'status' => $undo['why'] );
}

/**
 * The sizes the pickers offer: the attribute's terms, less the ones leaving.
 */
function fh_tidy_picker( Fake_Tidy_Shop $shop ) {
	$data = $shop->read_shop( 'pa_size' );
	return Sizes::resolve( array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => $data['orderby'] ) ), $data['terms'], array(), Sizes::leaving( 'pa_size' ) )['sizes'];
}

/**
 * The rows of a plan's map by slug.
 */
function fh_rows_by_slug( array $rows ) {
	$by = array();
	foreach ( $rows as $row ) {
		if ( ! $row['typed'] ) {
			$by[ $row['slug'] ] = $row;
		}
	}
	return $by;
}

echo "1. The record comes first\n";
$shop   = fh_tidy_shop();
$ids    = fh_tidy_ids( $shop );
$before = $shop->dump();
$plan   = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
$start  = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
check( 'Apply starts a run', true === $start['ok'] && $start['run'] > 0 && '' === $start['why'] );
check( 'starting writes nothing to the shop', $before === $shop->dump() && 0 === $shop->writes );
$run = $shop->run_get( $start['run'] );
check_same( 'the run: recording, who, the totals of the plan', array( 'recording', 'owner', $plan['totals'] ), array( $run['status'], $run['created_by'], $run['data']['totals'] ) );
$kinds = array();
foreach ( $shop->items as $item ) {
	$kinds[ $item['kind'] ] = ( $kinds[ $item['kind'] ] ?? 0 ) + 1;
}
check_same( 'its items: one per product, per page of rows of another store, the shares, the settings, the order, one per old term', array( 'product' => count( $plan['products'] ), 'rows' => 5, 'shares' => 1, 'option' => 2, 'order' => 1, 'term_delete' => count( $plan['delete'] ) ), $kinds );
$first = Sizes_Tidy_Run::step( $shop, $start['run'], 4 );
check( 'the first steps record every product as it is — still nothing written', 'recording' === $first['status'] && 0 === $shop->writes && $before === $shop->dump() );
check_same( 'four products recorded in a batch of four', 4, count( array_filter( $shop->items, static function ( $item ) { return 'product' === $item['kind'] && Sizes_Tidy_Run::RECORDED === $item['state']; } ) ) );
$step = Sizes_Tidy_Run::step( $shop, $start['run'], 50 );
check( 'the step that records the last product writes nothing either: the record is complete and saved first', 'applying' === $step['status'] && 0 === $shop->writes && $before === $shop->dump() );
$recorded = array();
foreach ( $shop->items as $item ) {
	if ( 'product' === $item['kind'] ) {
		$recorded[ $item['object_id'] ] = array( count( $item['before']['variations'] ), $item['before']['changing'] );
	}
}
check_same( 'a product\'s record holds all its variations, and names the ones the run changes', array( array( 2, array( $shop->vids( $ids['Banded Goby'] )[1] ) ), array( 2, array( $shop->vids( $ids['Flame Hawk'] )[0] ) ) ), array( $recorded[ $ids['Banded Goby'] ], $recorded[ $ids['Flame Hawk'] ] ) );
check( 'every item has its "before" by now', array() === array_filter( $shop->items, static function ( $item ) { return null === $item['before']; } ) );
check( 'progress counts up', $first['done'] < $step['done'] && $step['done'] < $step['total'] );
$again = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
check_same( 'Apply clicked again while the run is open: the same run carries on, no second one', array( true, $start['run'], 'open', 1 ), array( $again['ok'], $again['run'], $again['why'], count( $shop->runs ) ) );
$done = fh_tidy_steps( $shop, $start['run'] );
check_same( 'the run finishes', array( 'done', true, $done['total'] ), array( $done['status'], $done['finished'], $done['done'] ) );
check( 'the caches are dropped at the end', $shop->flushed >= 1 );

echo "2. What Apply did to the products\n";
$goby = $shop->products[ $ids['Banded Goby'] ];
check_same( 'a clean rename: the variation has the new size', array( '1-2', '2-3' ), array_values( array_map( static function ( $v ) { return $v['attrs']['pa_size']; }, $goby['variations'] ) ) );
check_same( '…the product\'s own list of sizes and its default follow', array( array( '1-2', '2-3' ), '2-3' ), array( $goby['sizes'], $goby['default'] ) );
check( '…its price, stock, SKU and status are as they were', array_column( $before['products'][ $ids['Banded Goby'] ]['variations'], 'price' ) === array_column( $goby['variations'], 'price' ) && array_column( $before['products'][ $ids['Banded Goby'] ]['variations'], 'sku' ) === array_column( $goby['variations'], 'sku' ) && array( 'publish', 'publish' ) === array_column( $goby['variations'], 'status' ) );
check( 'two become one: the product is left untouched, whole', $before['products'][ $ids['Coral Beauty'] ] === $shop->products[ $ids['Coral Beauty'] ] );
check( 'a typed size is not converted without its tick', $before['products'][ $ids['Koi Angel'] ] === $shop->products[ $ids['Koi Angel'] ] && $before['products'][ $ids['Jade Blenny'] ] === $shop->products[ $ids['Jade Blenny'] ] );
check( 'products whose sizes were fine are untouched', $before['products'][ $ids['Azure Damsel'] ] === $shop->products[ $ids['Azure Damsel'] ] && $before['products'][ $ids['Feeding Ring'] ] === $shop->products[ $ids['Feeding Ring'] ] );
check( 'no product and no variation was deleted', array_keys( $before['products'] ) === array_keys( $shop->products ) && array_map( 'count', array_column( $before['products'], 'variations' ) ) === array_map( 'count', array_column( $shop->products, 'variations' ) ) );

echo "3. The in-between words\n";
$hawk = $shop->products[ $ids['Flame Hawk'] ];
$off  = array_values( $hawk['variations'] )[0];
$was  = array_values( $before['products'][ $ids['Flame Hawk'] ]['variations'] )[0];
check_same( 'out of stock: the variation is switched off and has no size — it is still there, same id', array( 'private', '', $was['id'] ), array( $off['status'], $off['attrs']['pa_size'], $off['id'] ) );
check_same( '…with its price, stock and SKU', array( $was['price'], $was['stock'], $was['sku'] ), array( $off['price'], $off['stock'], $off['sku'] ) );
check_same( '…and the product\'s list has lost the size', array( 'medium' ), $hawk['sizes'] );
check( 'for sale today: left exactly as it was', $before['products'][ $ids['Golden Wrasse'] ] === $shop->products[ $ids['Golden Wrasse'] ] && $before['products'][ $ids['Harlequin Tusk'] ] === $shop->products[ $ids['Harlequin Tusk'] ] && $before['products'][ $ids['Neon Dottyback'] ] === $shop->products[ $ids['Neon Dottyback'] ] );
$state = $shop->option_get( Sizes::STATE_OPTION );
check_same( '…and its size stays in the shop, marked retiring', array( 'large-xl' => 'Large-XL', 'medium-large' => 'Medium-Large' ), $state['retiring'] );
check_same( '…with how many fish are left on it', array( 'medium-large' => 1, 'large-xl' => 2 ), $shop->retiring_counts( 'pa_size', array_map( 'strval', array_keys( $state['retiring'] ) ) ) );
check( 'a retiring size is still a term', isset( $shop->terms['medium-large'] ) && isset( $shop->terms['large-xl'] ) && ! isset( $shop->terms['small-medium'] ) );
$puffer = $shop->products[ $ids['Ivory Puffer'] ];
check_same( 'a fish left with nothing to buy: its one variation is off — the product itself is not hidden, trashed or renamed', array( 'publish', 'Ivory Puffer', array(), 'private' ), array( $puffer['status'], $puffer['title'], $puffer['sizes'], array_values( $puffer['variations'] )[0]['status'] ) );
check_same( 'the "Sizes to fill in" list: every fish that lost a size, with the size it had', array( 'Flame Hawk' => array( 'Small-Medium' ), 'Ivory Puffer' => array( 'Large-XL' ), 'Opal Jawfish' => array( 'Small-Medium' ) ), array_column( array_map( static function ( $row ) { return array( 't' => $row['title'], 's' => array_values( array_column( $row['sizes'], 'label' ) ) ); }, $shop->fill_list() ), 's', 't' ) );
$vid = $shop->vids( $ids['Flame Hawk'] )[0];
$shop->products[ $ids['Flame Hawk'] ]['variations'][ $vid ]['attrs']['pa_size'] = 'small';
check_same( 'the owner gives the variation a size: the fish leaves the list', array( 'Ivory Puffer', 'Opal Jawfish' ), array_values( array_column( $shop->fill_list(), 'title' ) ) );
$shop->fill_dismiss( $ids['Opal Jawfish'] );
check_same( 'or ticks it off', array( 'Ivory Puffer' ), array_values( array_column( $shop->fill_list(), 'title' ) ) );
$shop->products[ $ids['Flame Hawk'] ]['variations'][ $vid ]['attrs']['pa_size'] = '';
$shop->products[ $ids['Opal Jawfish'] ]['fill']                                 = $plan['products'][ $ids['Opal Jawfish'] ]['fill'];

echo "4. The terms, their order, and the pickers\n";
check_same( '31 sizes are now 13: the ten of the list, two retiring, one waiting for a product', array( 13, $plan['totals']['sizes_after'] ), array( count( $shop->terms ), $plan['totals']['sizes_after'] ) );
check( 'an old term something still uses is not deleted (3-5" is on a product that needs a look)', isset( $shop->terms['3-5'] ) );
check( 'old terms nothing uses are gone', ! isset( $shop->terms['tiny'] ) && ! isset( $shop->terms['2-3-5'] ) && ! isset( $shop->terms['7'] ) );
$data = $shop->read_shop( 'pa_size' );
check_same( 'the attribute\'s order: the short list first, in its order, then what is left', array( '1-2', '2-3', '3-4', '4-5', '5', 'small', 'medium', 'large', 'pair', 'all', '3-5', 'large-xl', 'medium-large' ), Sizes_Tidy::order_now( array_column( $data['terms'], null, 'slug' ) ) );
check_same( 'the Clean Room\'s pickers show the short list — the sizes on their way out are left out', array( '1-2"', '2-3"', '3-4"', '4-5"', '5"+', 'Small', 'Medium', 'Large', 'Pair', 'All' ), array_column( fh_tidy_picker( $shop ), 'label' ) );
check_same( 'the state remembers the list, what waits, and the names of the sizes that went (past orders still show them)', array( Sizes_Tidy::DEFAULT_LIST, array( '3-5' => '3-5"' ), 'Small-Medium', '2-3.5"', '<1"' ), array( $state['short'], $state['waiting'], $state['retired']['small-medium'], $state['retired']['2-3-5'], $state['retired']['lt-1'] ) );

echo "5. The other places a size is stored\n";
check_same( 'stays: a size that became another is rewritten', array( array( '3-4', '3-4"' ), array( '3-4', '3-4"' ) ), array( array( $shop->tables['stays'][1]['size_slug'], $shop->tables['stays'][1]['size_label'] ), array( $shop->tables['stays'][2]['size_slug'], $shop->tables['stays'][2]['size_label'] ) ) );
check( 'stays: a size that was taken off stays on the fish in the tanks', $before['tables']['stays'][3] === $shop->tables['stays'][3] && $before['tables']['stays'][4] === $shop->tables['stays'][4] && $before['tables']['stays'][5] === $shop->tables['stays'][5] );
check_same( '…and reads "not a standard size", as the section shows such sizes', 'Small-Medium (not a standard size)', Sizes::text( $shop->tables['stays'][3], fh_tidy_picker( $shop ) ) );
check_same( 'a stay that carries a retiring size reads the same way', 'Medium-Large (not a standard size)', Sizes::text( array( 'size_slug' => 'medium-large', 'size_label' => 'Medium-Large' ), fh_tidy_picker( $shop ) ) );
check_same( 'a stay that was rewritten is a standard size', '3-4"', Sizes::text( $shop->tables['stays'][1], fh_tidy_picker( $shop ) ) );
$loc = Box_Shares::location_key( 'Reef Co', 'Bali' );
$key = Box_Shares::share_key( $loc, 'id:5', '3-4' );
check_same( 'order lines: the size, and the key of the share the line counted in', array( '3-4', '3-4"', $key ), array( $shop->tables['order_lines'][1]['size_slug'], $shop->tables['order_lines'][1]['size_label'], $shop->tables['order_lines'][1]['share_key'] ) );
check( 'order lines: another line is untouched', $before['tables']['order_lines'][2] === $shop->tables['order_lines'][2] );
check_same( 'requests', array( '3-4', '3-4"' ), array( $shop->tables['requests'][1]['size_slug'], $shop->tables['requests'][1]['size_label'] ) );
check_same( 'lost-fish cases: the size — and the kind key when there is no product', array( array( '3-4', 's:coral beauty|3-4' ), array( '3-4', 'p:77' ) ), array( array( $shop->tables['lostfish_cases'][1]['size_slug'], $shop->tables['lostfish_cases'][1]['kind_key'] ), array( $shop->tables['lostfish_cases'][2]['size_slug'], $shop->tables['lostfish_cases'][2]['kind_key'] ) ) );
check_same( 'invoice lines: a draft\'s line is rewritten, a published invoice keeps what it said', array( '3-4', '3-5' ), array( $shop->tables['invoice_lines'][1]['size_slug'], $shop->tables['invoice_lines'][2]['size_slug'] ) );
check( '…and the line\'s name is left as it was written', $before['tables']['invoice_lines'][1]['name'] === $shop->tables['invoice_lines'][1]['name'] );
check_same( 'box shares: the two that landed on one key are one — the latest, with both counts', array( array( 2 ), $key, 7, '2.0000' ), array( array_keys( $shop->tables['box_shares'] ), $shop->tables['box_shares'][2]['share_key'], $shop->tables['box_shares'][2]['order_count'], $shop->tables['box_shares'][2]['pct_per_fish'] ) );
check_same( 'the Packing map is re-keyed; the rest of its settings are as they were', array( array( '3-4' => 'bag-m', 'small-medium' => 'bag-s', 'medium' => 'bag-m', '2-3' => 'bag-s' ), $before['options']['fishotel_packing_settings']['bags'] ), array( $shop->options['fishotel_packing_settings']['size_map'], $shop->options['fishotel_packing_settings']['bags'] ) );

echo "6. A second click, and a later run\n";
$after = $shop->dump();
$plan2 = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
check( 'the same choices again: nothing left to do', true === $plan2['nothing'] && array() === $plan2['products'] && array() === $plan2['delete'] );
$again = Sizes_Tidy_Run::start( $shop, $plan2, 'owner' );
check_same( 'Apply on it starts nothing and changes nothing', array( false, 'nothing', 1, true ), array( $again['ok'], $again['why'], count( $shop->runs ), $after === $shop->dump() ) );
check_same( 'a finished run is not run again', array( 'done', true ), array( Sizes_Tidy_Run::step( $shop, $start['run'] )['status'], $after === $shop->dump() ) );
$rows = array();
foreach ( $plan2['rows'] as $row ) {
	$rows[ $row['label'] ] = $row;
}
check_same( 'the retiring words still start on "take off" in the next run', array( 'remove', 'remove', array( 'retiring' ) ), array( $rows['Medium-Large']['action'], $rows['Large-XL']['action'], $rows['Medium-Large']['flags'] ) );

// The Golden Wrasse sells out: its retiring size can be finished.
$wrasse = $shop->vids( $ids['Golden Wrasse'] )[0];
$shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['stock']        = 0;
$shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['stock_status'] = 'outofstock';
$done = fh_tidy_run( $shop );
check_same( 'finish retiring: nothing in stock carries Medium-Large any more — its variation is switched off, the term goes, Large-XL keeps retiring', array( 'done', 'private', '', false, array( 'large-xl' => 'Large-XL' ) ), array( $done['status'], $shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['status'], $shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['attrs']['pa_size'], isset( $shop->terms['medium-large'] ), $shop->option_get( Sizes::STATE_OPTION )['retiring'] ) );
check( '…and the wrasse is on the "Sizes to fill in" list', in_array( 'Golden Wrasse', array_column( $shop->fill_list(), 'title' ), true ) );
check_same( 'the past orders keep the name of every size that went, from both runs', array( 'Medium-Large', 'Small-Medium' ), array( $shop->option_get( Sizes::STATE_OPTION )['retired']['medium-large'], $shop->option_get( Sizes::STATE_OPTION )['retired']['small-medium'] ) );

echo "7. Undo\n";
check_same( 'two runs are on the stack', 2, count( $shop->runs ) );
$undone = fh_tidy_undo( $shop );
check_same( 'the second run undone: the shop is as the first run left it — except the stock the owner changed', array( 'undone', 1 ), array( $undone['status'], count( $shop->runs ) ) );
$shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['stock']        = 2;
$shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['stock_status'] = 'instock';
check( '…a full dump says so', $after === $shop->dump() );
$undone = fh_tidy_undo( $shop );
check_same( 'the first run undone', array( 'undone', 0 ), array( $undone['status'], count( $shop->runs ) ) );
check( 'everything is back exactly: every variation, every product\'s list, every term with its name, slug, order and meta, every stay, line, request, case, share and setting', $before === $shop->dump() );
foreach ( array( 'terms', 'products', 'tables', 'options' ) as $part ) {
	check( '…' . $part, $before[ $part ] === $shop->dump()[ $part ] );
}
check( 'the record of an undone run is gone, with its items', array() === $shop->items );
check_same( 'the pickers offer the long list again', 31, count( fh_tidy_picker( $shop ) ) );
check_same( 'nothing left to undo', array( false, 'none' ), array( Sizes_Tidy_Run::undo( $shop )['ok'], Sizes_Tidy_Run::undo( $shop )['why'] ) );

// Between a run and its Undo the owner reprices a variation the run did not touch: Undo leaves it alone.
$shop  = fh_tidy_shop();
$ids   = fh_tidy_ids( $shop );
$zero  = $shop->dump();
fh_tidy_run( $shop );
$other = $shop->vids( $ids['Banded Goby'] )[0];
$shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['price'] = '13.50';
fh_tidy_undo( $shop );
check_same( 'a variation the run did not touch keeps what the owner did to it since', '13.50', $shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['price'] );
check_same( '…its summary line, which WooCommerce rewrote when its product\'s sizes changed, is back as it was', 'pa_size: 1-2', $shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['summary'] );
$shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['price'] = '12.00';
check( '…and everything else is back', $zero === $shop->dump() );
fh_tidy_run( $shop );
$shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['attrs']['pa_size'] = '3-4';
$shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['summary']          = 'Size: 3-4';
fh_tidy_undo( $shop );
check_same( 'one the owner gave another size since is left exactly as the owner left it', array( '3-4', 'Size: 3-4' ), array( $shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['attrs']['pa_size'], $shop->products[ $ids['Banded Goby'] ]['variations'][ $other ]['summary'] ) );

echo "8. Keeping one of two, converting a typed size, taking the fish in stock off — and Undo of all of it\n";
$shop  = fh_tidy_shop();
$ids   = fh_tidy_ids( $shop );
$zero  = $shop->dump();
$plan  = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
$coral = $shop->vids( $ids['Coral Beauty'] );
$gkey  = array_keys( $plan['two'][ $ids['Coral Beauty'] ]['groups'] )[0];
$done  = fh_tidy_run(
	$shop,
	array(
		'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $coral[0] ) ),
		'convert'  => array( $ids['Koi Angel'] => 1 ),
		'instock'  => 'off',
	)
);
$beauty = $shop->products[ $ids['Coral Beauty'] ];
check_same( 'the one kept is for sale as it was; the other has the same size, is switched off, and is still there with its stock', array( 'done', array( 'publish', '3-4', 2 ), array( 'private', '3-4', 1, '35.00', 'CB-35' ) ), array( $done['status'], array( $beauty['variations'][ $coral[0] ]['status'], $beauty['variations'][ $coral[0] ]['attrs']['pa_size'], $beauty['variations'][ $coral[0] ]['stock'] ), array( $beauty['variations'][ $coral[1] ]['status'], $beauty['variations'][ $coral[1] ]['attrs']['pa_size'], $beauty['variations'][ $coral[1] ]['stock'], $beauty['variations'][ $coral[1] ]['price'], $beauty['variations'][ $coral[1] ]['sku'] ) ) );
check_same( '…the product lists the one size', array( '3-4' ), $beauty['sizes'] );
check( '…a switched-off duplicate did not lose its size, so it is not on the "Sizes to fill in" list', ! in_array( 'Coral Beauty', array_column( $shop->fill_list(), 'title' ), true ) );
$koi = $shop->products[ $ids['Koi Angel'] ];
check_same( 'the typed size is the attribute\'s term now: on each variation, on the product, as its default', array( array( array( 'pa_size' => '1-2' ), array( 'pa_size' => '2-3' ) ), array( '1-2', '2-3' ), '1-2', null ), array( array_values( array_column( $koi['variations'], 'attrs' ) ), $koi['sizes'], $koi['default'], $koi['typed'] ) );
check( 'the typed product that was not ticked is as it was', $zero['products'][ $ids['Jade Blenny'] ] === $shop->products[ $ids['Jade Blenny'] ] );
$tusk = $shop->products[ $ids['Harlequin Tusk'] ];
check_same( '"take them off the shop now": the fish for sale is switched off, with its stock to re-enter', array( 'private', '', 1, '120.00' ), array( array_values( $tusk['variations'] )[0]['status'], array_values( $tusk['variations'] )[0]['attrs']['pa_size'], array_values( $tusk['variations'] )[0]['stock'], array_values( $tusk['variations'] )[0]['price'] ) );
check_same( '31 sizes are the ten of the list', array( '1-2', '2-3', '3-4', '4-5', '5', 'small', 'medium', 'large', 'pair', 'all' ), Sizes_Tidy::order_now( array_column( $shop->read_shop( 'pa_size' )['terms'], null, 'slug' ) ) );
check_same( 'nothing retires, nothing waits', array( array(), array() ), array( $shop->option_get( Sizes::STATE_OPTION )['retiring'], $shop->option_get( Sizes::STATE_OPTION )['waiting'] ) );
check_same( 'the "Sizes to fill in" list has every fish that lost a size', array( 'Flame Hawk', 'Golden Wrasse', 'Harlequin Tusk', 'Ivory Puffer', 'Neon Dottyback', 'Opal Jawfish' ), array_values( array_column( $shop->fill_list(), 'title' ) ) );
$undone = fh_tidy_undo( $shop );
check( 'Undo puts all of it back exactly', 'undone' === $undone['status'] && $zero === $shop->dump() );
check_same( '…the "Sizes to fill in" list is empty again', array(), $shop->fill_list() );

$done  = fh_tidy_run( $shop, array( 'instock' => 'move', 'move' => array( $shop->vids( $ids['Golden Wrasse'] )[0] => 'medium' ) ) );
$moved = array_values( $shop->products[ $ids['Golden Wrasse'] ]['variations'] )[0];
check_same( '"move them to a size I pick": the fish is for sale under the size picked', array( 'publish', 'medium', array( 'large', 'medium' ), false ), array( $moved['status'], $moved['attrs']['pa_size'], $shop->products[ $ids['Golden Wrasse'] ]['sizes'], isset( $shop->terms['medium-large'] ) ) );
check( '…and Undo takes that back too', 'undone' === fh_tidy_undo( $shop )['status'] && $zero === $shop->dump() );

echo "9. A new size in the list, and a term somebody started using in between\n";
$plan  = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ), array( 'short' => array_merge( array( 'Nano' ), Sizes_Tidy::DEFAULT_LIST ) ) );
$start = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
// Between the preview and Apply, the owner puts Tiny on a new fish.
$quoy = $shop->add_product( 'Quoy Parrot', array( 'tiny' => array( '11.00', 2 ) ) );
$done = fh_tidy_steps( $shop, $start['run'] );
check( 'a size typed into the list is created, first in the order', isset( $shop->terms['nano'] ) && 'Nano' === $shop->terms['nano']['name'] && 'nano' === Sizes_Tidy::order_now( array_column( $shop->read_shop( 'pa_size' )['terms'], null, 'slug' ) )[0] );
check_same( 'a term that is in use after all is NOT deleted — the run says which', array( true, array( array( 'kind' => 'term_delete', 'id' => 0, 'key' => 'tiny', 'note' => 'used' ) ) ), array( isset( $shop->terms['tiny'] ), $done['skipped'] ) );
unset( $shop->products[ $quoy ] );
check( 'Undo removes the term it created and brings the others back', 'undone' === fh_tidy_undo( $shop )['status'] && $zero === $shop->dump() );

echo "10. A batch cut off half-way\n";
$whole = fh_tidy_shop();
fh_tidy_run( $whole );
foreach ( array( 3, 9, 14, 23, 31, 40 ) as $at ) {
	$shop  = fh_tidy_shop();
	$zero  = $shop->dump();
	$plan  = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
	$start = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
	Sizes_Tidy_Run::step( $shop, $start['run'], 100 ); // Recorded.
	$shop->fuse = $at;
	$cut        = false;
	try {
		Sizes_Tidy_Run::step( $shop, $start['run'], 100 );
	} catch ( \RuntimeException $e ) {
		$cut = true;
	}
	$middle = $shop->dump();
	$status = $shop->run_get( $start['run'] )['status'];
	$done   = fh_tidy_steps( $shop, $start['run'] );
	check( "cut off at write $at (" . $shop->calls[ $at - 1 ] . '): the run was half-way, the lock was given back, the next click finishes it — the same shop as a run that was never cut', $cut && 'applying' === $status && $middle !== $zero && false === $shop->locked && 'done' === $done['status'] && $whole->dump() === $shop->dump() );
	if ( 9 === $at ) {
		$shop2      = fh_tidy_shop();
		$start2     = Sizes_Tidy_Run::start( $shop2, Sizes_Tidy::plan( $shop2->read_shop( 'pa_size' ) ), 'owner' );
		Sizes_Tidy_Run::step( $shop2, $start2['run'], 100 );
		$shop2->fuse = $at;
		try {
			Sizes_Tidy_Run::step( $shop2, $start2['run'], 100 );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$undone = fh_tidy_undo( $shop2 );
		check( 'a run that was cut off can be undone instead of finished: everything back exactly', 'undone' === $undone['status'] && $zero === $shop2->dump() && array() === $shop2->runs );
	}
	check( '…and its Undo still puts everything back', 'undone' === fh_tidy_undo( $shop )['status'] && $zero === $shop->dump() );
}
$shop  = fh_tidy_shop();
$start = Sizes_Tidy_Run::start( $shop, Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) ), 'owner' );
$shop->lock();
$busy = Sizes_Tidy_Run::step( $shop, $start['run'] );
check_same( 'two clicks at once: the second waits its turn and writes nothing', array( false, 'busy', 0 ), array( $busy['ok'], $busy['why'], $shop->writes ) );
$shop->unlock();
check_same( 'a run that is gone', array( false, 'gone', true ), array( Sizes_Tidy_Run::step( $shop, 999 )['ok'], Sizes_Tidy_Run::step( $shop, 999 )['why'], Sizes_Tidy_Run::step( $shop, 999 )['finished'] ) );

echo "11. The stack of three\n";
$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
$zero = $shop->dump();
fh_tidy_run( $shop );
$one = $shop->dump();
fh_tidy_run( $shop, array( 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $shop->vids( $ids['Coral Beauty'] )[1] ) ) ) );
$two = $shop->dump();
fh_tidy_run( $shop, array( 'keep_one' => array( $ids['Coral Beauty'] => array( $gkey => $shop->vids( $ids['Coral Beauty'] )[1] ) ), 'convert' => array( $ids['Koi Angel'] => 1 ) ) );
$three = $shop->dump();
check_same( 'three runs, three records', array( 3, true ), array( count( $shop->runs ), $one !== $two && $two !== $three ) );
$done = fh_tidy_run( $shop, array( 'instock' => 'off' ) );
check_same( 'a fourth run: the oldest record makes room — the last three are kept', array( 'done', 3, false ), array( $done['status'], count( $shop->runs ), isset( $shop->runs[1] ) ) );
check( '…and so are their items only', array( 2, 3, 4 ) === array_values( array_unique( array_column( $shop->items, 'run_id' ) ) ) );
check( 'undo the fourth: back to the third', 'undone' === fh_tidy_undo( $shop )['status'] && $three === $shop->dump() );
check( 'undo the third: back to the second', 'undone' === fh_tidy_undo( $shop )['status'] && $two === $shop->dump() );
check( 'undo the second: back to the first', 'undone' === fh_tidy_undo( $shop )['status'] && $one === $shop->dump() );
check_same( 'the first run\'s record made room, so there is nothing left to undo', array( false, 'none', array() ), array( Sizes_Tidy_Run::undo( $shop )['ok'], Sizes_Tidy_Run::undo( $shop )['why'], $shop->runs ) );
check( '…and the shop is as the first run left it, not half-way', $one === $shop->dump() && $zero !== $shop->dump() );

echo "12. A shop of 2,000 variations\n";
$big = new Fake_Tidy_Shop();
$big->add_terms( array( 'lt-1' => '<1"', '1-2' => '1-2"', '1-3' => '1-3"', '2-3' => '2-3"', '2-3-5' => '2-3.5"', '3-4' => '3-4"', '3' => '3"+', '4-5' => '4-5"', '4-6' => '4-6"', '5' => '5"+', '6' => '6"+', 'small' => 'Small', 'small-medium' => 'Small-Medium', 'medium' => 'Medium', 'medium-large' => 'Medium-Large', 'large' => 'Large', 'large-xl' => 'Large-XL', 'pair' => 'Pair', 'all' => 'All' ) );
$sets = array( array( '1-3', '2-3-5', '3', '4-6' ), array( 'small', 'small-medium', 'medium' ), array( '1-2', '2-3', '3-4', '4-5' ), array( 'medium-large', 'large', 'large-xl' ), array( '2-3-5', '3-4', '6' ) );
for ( $i = 0; $i < 600; $i++ ) {
	$variations = array();
	foreach ( $sets[ $i % 5 ] as $n => $slug ) {
		$variations[ $slug ] = array( number_format( 10 + $i % 40 + $n, 2 ), ( $i + $n ) % 3 );
	}
	$big->add_product( 'Invented fish ' . $i, $variations );
}
for ( $i = 0; $i < 3000; $i++ ) {
	$big->add_row( 'stays', array( 'species' => 'Invented fish ' . ( $i % 600 ), 'size_slug' => $sets[ $i % 5 ][ $i % 3 ], 'size_label' => 'x' ) );
}
$count = 0;
foreach ( $big->products as $product ) {
	$count += count( $product['variations'] );
}
$zero  = $big->dump();
$t0    = microtime( true );
$plan  = Sizes_Tidy::plan( $big->read_shop( 'pa_size' ) );
$t1    = microtime( true );
$start = Sizes_Tidy_Run::start( $big, $plan, 'owner' );
$t2    = microtime( true );
check( "the shop has $count variations on 600 products", $count >= 2000 );
check( 'the dry run takes well under a second', $t1 - $t0 < 1.5, round( $t1 - $t0, 3 ) . ' s' );
check( 'saving the record takes well under a second, and a page of rows is ' . Sizes_Tidy_Run::ROWS_PAGE . ' at most', $t2 - $t1 < 1.5 && Sizes_Tidy_Run::ROWS_PAGE >= max( array_map( static function ( $item ) { return 'rows' === $item['kind'] ? count( $item['after'] ) : 0; }, $big->items ) ), round( $t2 - $t1, 3 ) . ' s' );
$steps   = 0;
$most    = 0;
$slowest = 0;
$peak    = memory_get_usage();
do {
	$loaded   = $big->loaded;
	$writes   = count( $big->calls );
	$states   = array_column( $big->items, 'state', 'id' );
	$t        = microtime( true );
	$progress = Sizes_Tidy_Run::step( $big, $start['run'] );
	$slowest  = max( $slowest, microtime( true ) - $t );
	$touched  = count( array_diff_assoc( array_column( $big->items, 'state', 'id' ), $states ) );
	$most     = max( $most, $touched, $big->loaded - $loaded );
	$peak     = max( $peak, memory_get_usage() );
	++$steps;
} while ( ! $progress['finished'] && $steps < 2000 );
check_same( 'the run finishes', 'done', $progress['status'] );
check( 'no step handles more than ' . Sizes_Tidy_Run::BATCH . " items or reads more than that many products ($steps steps)", $most <= Sizes_Tidy_Run::BATCH && $steps > count( $plan['products'] ) / Sizes_Tidy_Run::BATCH, "most: $most" );
check( 'no step takes long', $slowest < 2, round( $slowest, 3 ) . ' s' );
check( 'memory stays far below a small site\'s limit', memory_get_peak_usage() < 96 * 1024 * 1024, round( memory_get_peak_usage() / 1048576 ) . ' MB' );
check_same( 'every stay whose size became another was rewritten, none other', array( 0, 200 ), array( count( array_filter( $big->tables['stays'], static function ( $row ) { return in_array( $row['size_slug'], array( '1-3', '2-3-5', '3', '4-6', '6' ), true ); } ) ), count( array_filter( $big->tables['stays'], static function ( $row ) { return 'small-medium' === $row['size_slug']; } ) ) ) );
$undone = fh_tidy_undo( $big );
check( 'and Undo puts the 2,000 back exactly', 'undone' === $undone['status'] && $zero === $big->dump() );

echo "13. A shop that does not stand still: stock is looked at again as each fish is written\n";
$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
$hawk = $shop->vids( $ids['Flame Hawk'] )[0];
$plan = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
check_same( 'when the owner looked, Flame Hawk Small-Medium was out of stock: to be taken off', 'off_out', $plan['products'][ $ids['Flame Hawk'] ]['vars'][ $hawk ]['why'] );
$start = Sizes_Tidy_Run::start( $shop, $plan, 'owner' );
Sizes_Tidy_Run::step( $shop, $start['run'], 100 ); // Recorded.
// The run stops here (a timeout, a closed tab). Before "Carry on", the fish is restocked.
$shop->products[ $ids['Flame Hawk'] ]['variations'][ $hawk ]['stock']        = 5;
$shop->products[ $ids['Flame Hawk'] ]['variations'][ $hawk ]['stock_status'] = 'instock';
$restocked = $shop->dump();
$done      = fh_tidy_steps( $shop, $start['run'] );
$now       = $shop->products[ $ids['Flame Hawk'] ];
check_same( 'Carry on does NOT switch it off: it is for sale now, so it is left — its size, Enabled and stock as they are', array( 'publish', 'small-medium', 5 ), array( $now['variations'][ $hawk ]['status'], $now['variations'][ $hawk ]['attrs']['pa_size'], $now['variations'][ $hawk ]['stock'] ) );
check_same( '…its product still lists the size, and is not on the "Sizes to fill in" list', array( array( 'medium', 'small-medium' ), false ), array( $now['sizes'], isset( $shop->fill_list()[ $ids['Flame Hawk'] ] ) ) );
check( '…the size is still a term, and is marked retiring (out of the pickers) with the fish left on it', isset( $shop->terms['small-medium'] ) && 'Small-Medium' === $shop->option_get( Sizes::STATE_OPTION )['retiring']['small-medium'] && 1 === $shop->retiring_counts( 'pa_size', array( 'small-medium' ) )['small-medium'] && ! in_array( 'Small-Medium', array_column( fh_tidy_picker( $shop ), 'label' ), true ) );
check_same( '…and the run names the fish at the end', array( array( 'variation' => $hawk, 'product' => $ids['Flame Hawk'], 'title' => 'Flame Hawk', 'sizes' => array( 'small-medium' => 'Small-Medium' ) ) ), $done['back'] );
check( 'everything else was tidied as planned', 'private' === array_values( $shop->products[ $ids['Ivory Puffer'] ]['variations'] )[0]['status'] && array( '1-2', '2-3' ) === $shop->products[ $ids['Banded Goby'] ]['sizes'] );
$again = Sizes_Tidy::plan( $shop->read_shop( 'pa_size' ) );
check( 'a later look: the fish is for sale, so it is left to retire like the others', true === $again['nothing'] && 1 === fh_rows_by_slug( $again['rows'] )['small-medium']['left'] );
fh_tidy_undo( $shop );
check( 'Undo puts the shop back as it was — with the restock', $restocked === $shop->dump() );

$shop  = fh_tidy_shop();
$ids   = fh_tidy_ids( $shop );
$opal  = $shop->vids( $ids['Opal Jawfish'] )[0];
$shop->before_apply = static function ( $pid ) use ( $shop, $ids ) {
	if ( $pid === $ids['Opal Jawfish'] ) {
		$shop->products[ $pid ]['status'] = 'publish'; // The draft goes on the shop while the tool works.
	}
};
$done = fh_tidy_run( $shop );
check_same( 'a draft fish with stock that is published in the meantime is left for sale too', array( 'publish', 'small-medium', array( 'Opal Jawfish' ) ), array( $shop->products[ $ids['Opal Jawfish'] ]['variations'][ $opal ]['status'], $shop->products[ $ids['Opal Jawfish'] ]['variations'][ $opal ]['attrs']['pa_size'], array_column( $done['back'], 'title' ) ) );
$shop  = fh_tidy_shop();
$ids   = fh_tidy_ids( $shop );
$wrasse = $shop->vids( $ids['Golden Wrasse'] )[0];
fh_tidy_run( $shop, array( 'instock' => 'off' ) );
check_same( '"take them off the shop now" is the owner\'s own word for the fish that WERE for sale: those are taken off, as shown', array( 'private', '' ), array( $shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['status'], $shop->products[ $ids['Golden Wrasse'] ]['variations'][ $wrasse ]['attrs']['pa_size'] ) );

echo "14. Sold as \"any size\", through Apply and Undo\n";
$shop  = fh_tidy_shop();
$quill = $shop->add_product( 'Quill Anthias', array( 'small-medium' => array( '30.00', 4, 'attrs' => array( 'pa_size' => '' ) ) ) );
$ruby  = $shop->add_product( 'Ruby Basslet', array( 'small-medium' => array( '18.00', 3, 'attrs' => array( 'pa_size' => '' ) ) ), array( 'sizes' => array( 'small-medium', 'medium' ) ) );
$sable = $shop->add_product( 'Sable Chromis', array( 'small-medium' => array( '12.00', 0, 'attrs' => array( 'pa_size' => '' ) ) ) );
$zero  = $shop->dump();
$done  = fh_tidy_run( $shop );
check( 'the fish in stock sold as any size are exactly as they were: still listed with Small-Medium, still for sale', 'done' === $done['status'] && $zero['products'][ $quill ] === $shop->products[ $quill ] && $zero['products'][ $ruby ] === $shop->products[ $ruby ] );
check_same( 'Small-Medium stays in the shop, retiring — 2 fish left', array( true, 2 ), array( isset( $shop->terms['small-medium'] ), $shop->retiring_counts( 'pa_size', array( 'small-medium' ) )['small-medium'] ) );
$gone = $shop->products[ $sable ];
check_same( 'the one out of stock: its product lists nothing, it is switched off, price and stock kept, and it is on the "Sizes to fill in" list', array( array(), 'private', '12.00', 0, true ), array( $gone['sizes'], array_values( $gone['variations'] )[0]['status'], array_values( $gone['variations'] )[0]['price'], array_values( $gone['variations'] )[0]['stock'], isset( $shop->fill_list()[ $sable ] ) ) );
// The last two sell out; "Finish retiring" (the same tool again) takes the size off.
foreach ( array( $quill, $ruby ) as $pid ) {
	$vid = $shop->vids( $pid )[0];
	$shop->products[ $pid ]['variations'][ $vid ]['stock']        = 0;
	$shop->products[ $pid ]['variations'][ $vid ]['stock_status'] = 'outofstock';
}
fh_tidy_run( $shop );
check_same( 'once they sold out, a second run finishes it: the size is gone, Ruby Basslet still lists Medium and its variation is still on', array( false, array(), array( 'medium' ), 'publish' ), array( isset( $shop->terms['small-medium'] ), $shop->products[ $quill ]['sizes'], $shop->products[ $ruby ]['sizes'], array_values( $shop->products[ $ruby ]['variations'] )[0]['status'] ) );
fh_tidy_undo( $shop );
fh_tidy_undo( $shop );
foreach ( array( $quill, $ruby ) as $pid ) {
	$vid = $shop->vids( $pid )[0];
	$shop->products[ $pid ]['variations'][ $vid ]['stock']        = $zero['products'][ $pid ]['variations'][ $vid ]['stock'];
	$shop->products[ $pid ]['variations'][ $vid ]['stock_status'] = 'instock';
}
check( 'both runs undone: the shop is as it was (the stock put back by hand — Undo never touches stock)', $zero === $shop->dump() );
$shop  = fh_tidy_shop();
$sable = $shop->add_product( 'Sable Chromis', array( 'small-medium' => array( '12.00', 0, 'attrs' => array( 'pa_size' => '' ) ) ) );
$vid   = $shop->vids( $sable )[0];
$shop->before_apply = static function ( $pid ) use ( $shop, $sable, $vid ) {
	if ( $pid === $sable ) {
		$shop->products[ $sable ]['variations'][ $vid ]['stock']        = 2;
		$shop->products[ $sable ]['variations'][ $vid ]['stock_status'] = 'instock';
	}
};
$done = fh_tidy_run( $shop );
check_same( 'an any-size fish restocked while the tool works keeps its product\'s list, and is named', array( array( 'small-medium' ), 'publish', array( 'small-medium' => 'Small-Medium' ) ), array( $shop->products[ $sable ]['sizes'], $shop->products[ $sable ]['variations'][ $vid ]['status'], $done['back'][0]['sizes'] ) );

echo "15. Undo after the owner went on\n";
$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
$zero = $shop->dump();
fh_tidy_run( $shop );
// The owner works through "Sizes to fill in": the switched-off Flame Hawk gets Small, a new price, stock, and Enabled.
$hawk = $shop->vids( $ids['Flame Hawk'] )[0];
$shop->products[ $ids['Flame Hawk'] ]['variations'][ $hawk ] = array_merge( $shop->products[ $ids['Flame Hawk'] ]['variations'][ $hawk ], array( 'attrs' => array( 'pa_size' => 'small' ), 'status' => 'publish', 'price' => '23.00', 'stock' => 6, 'stock_status' => 'instock', 'title' => 'Flame Hawk - small' ) );
$shop->products[ $ids['Flame Hawk'] ]['sizes'][]             = 'small';
// Ivory Puffer's is only switched on again (it reads "any size"); the last Banded Goby sells.
$puffer = $shop->vids( $ids['Ivory Puffer'] )[0];
$shop->products[ $ids['Ivory Puffer'] ]['variations'][ $puffer ]['status'] = 'publish';
$goby = $shop->vids( $ids['Banded Goby'] )[1];
$shop->products[ $ids['Banded Goby'] ]['variations'][ $goby ]['stock']        = 0;
$shop->products[ $ids['Banded Goby'] ]['variations'][ $goby ]['stock_status'] = 'outofstock';
$undone = fh_tidy_undo( $shop );
$now    = $shop->products[ $ids['Flame Hawk'] ]['variations'][ $hawk ];
check_same( 'the variation that was given Small since is NOT made Small-Medium again: its size, price, stock and Enabled are the owner\'s', array( 'small', 'publish', '23.00', 6 ), array( $now['attrs']['pa_size'], $now['status'], $now['price'], $now['stock'] ) );
check_same( '…Undo says which one it left', array( array( 'variation' => $hawk, 'product' => $ids['Flame Hawk'], 'title' => 'Flame Hawk', 'size' => 'small', 'label' => 'Small' ) ), $undone['kept'] );
check_same( '…and its product lists what it listed before, plus the size that variation carries now', array( 'small-medium', 'medium', 'small' ), $shop->products[ $ids['Flame Hawk'] ]['sizes'] );
check_same( 'a variation that was only switched on again still has the size the run gave it (none): it goes back to what it was', array( 'large-xl', 'publish' ), array( $shop->products[ $ids['Ivory Puffer'] ]['variations'][ $puffer ]['attrs']['pa_size'], $shop->products[ $ids['Ivory Puffer'] ]['variations'][ $puffer ]['status'] ) );
check_same( 'a renamed fish that sold out since gets its old size name back — and is still sold out: Undo never writes stock', array( '2-3-5', 0, 'outofstock' ), array( $shop->products[ $ids['Banded Goby'] ]['variations'][ $goby ]['attrs']['pa_size'], $shop->products[ $ids['Banded Goby'] ]['variations'][ $goby ]['stock'], $shop->products[ $ids['Banded Goby'] ]['variations'][ $goby ]['stock_status'] ) );
$want = $zero;
$want['products'][ $ids['Flame Hawk'] ]['variations'][ $hawk ]              = $now;
$want['products'][ $ids['Flame Hawk'] ]['sizes'][]                          = 'small';
$want['products'][ $ids['Banded Goby'] ]['variations'][ $goby ]['stock']        = 0;
$want['products'][ $ids['Banded Goby'] ]['variations'][ $goby ]['stock_status'] = 'outofstock';
check( 'everything else is back exactly as it was', 'undone' === $undone['status'] && $want === $shop->dump() );
$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
fh_tidy_run( $shop, array( 'convert' => array( $ids['Koi Angel'] => 1 ) ) );
$koi = $shop->vids( $ids['Koi Angel'] )[0];
$shop->products[ $ids['Koi Angel'] ]['variations'][ $koi ]['attrs']['pa_size'] = '3-4';
$after  = $shop->products[ $ids['Koi Angel'] ];
$undone = fh_tidy_undo( $shop );
check( 'a typed size that was made a size of the list, then changed by the owner: the whole fish is left as it is and named — never half typed, half listed', $after === $shop->products[ $ids['Koi Angel'] ] && array( 'Koi Angel' ) === array_column( $undone['kept'], 'title' ) );

echo "16. A product in the trash keeps its size\n";
$shop          = fh_tidy_shop();
$shop->trashed = array( 'xl' => 1 );
$done          = fh_tidy_run( $shop );
check( 'XL is still a term after the run (Tiny, which nothing uses, is gone) — nothing was skipped: the plan knew', isset( $shop->terms['xl'] ) && ! isset( $shop->terms['tiny'] ) && array() === $done['skipped'] && ! in_array( 'term_delete xl', $shop->calls, true ) );
check( '…and it is out of the pickers, waiting', isset( $shop->option_get( Sizes::STATE_OPTION )['waiting']['xl'] ) && ! in_array( 'XL', array_column( fh_tidy_picker( $shop ), 'label' ), true ) );
$shop->trashed = array();
fh_tidy_run( $shop );
check( 'once the trash is emptied, the next run takes it off', ! isset( $shop->terms['xl'] ) );

fh_finish();
