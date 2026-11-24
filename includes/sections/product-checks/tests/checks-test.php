<?php
/**
 * Product checks — on a fake shop of invented products: the facts read
 * from a product, the flags written (and removed), when a product is
 * checked again, the sweep on WP-cron, the live categories setting, and
 * the products list (the "To fix" row, the dropdown's queries, the sort,
 * the column, row and bulk actions, the links), the Fish essentials chips
 * and the section's hooks.
 *
 * Run from the plugin root:  php includes/sections/product-checks/tests/checks-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Product_Checks\Admin;
use FisHotel\Misc\Sections\Product_Checks\Checker;
use FisHotel\Misc\Sections\Product_Checks\Collector;
use FisHotel\Misc\Sections\Product_Checks\Product_Checks;
use FisHotel\Misc\Sections\Product_Checks\Products_List;
use FisHotel\Misc\Sections\Product_Checks\Rules;
use FisHotel\Misc\Sections\Product_Checks\Settings;

// The shop: categories, an invented tang (variable), a goby (simple), salt, an invoice.
$GLOBALS['fh_term_tree'] = array(
	'quarantined-fish' => array( 10, '' ),
	'tangs'            => array( 11, 'quarantined-fish' ),
	'inverts'          => array( 12, '' ),
	'salt'             => array( 13, '' ),
	'invoices'         => array( 14, '' ),
	'corals'           => array( 15, '' ),
	'size-large'       => array( 20, '' ),
);
$GLOBALS['fh_post_types'][300] = 'attachment';
$GLOBALS['fh_post_types'][301] = 'attachment';
$GLOBALS['fh_files'][300]      = '/up/yellow-tang.jpg';
$GLOBALS['fh_files'][301]      = '/up/placeholder-fish.jpg';

$tang = new Fh_Test_Product( 100, 'variable', array( 'children' => array( 101, 102, 103 ), 'short' => 'Fishus inventus', 'desc' => "Scientific Name: Fishus inventus\nTemperament: calm\nMaximum Length: 6 in" ) );
new Fh_Test_Product( 101, 'variation', array( 'parent' => 100, 'price' => '49', 'attrs' => array( 'pa_size' => 'size-large' ) ) );
new Fh_Test_Product( 102, 'variation', array( 'parent' => 100, 'price' => '', 'attrs' => array( 'pa_size' => 'small' ) ) );
new Fh_Test_Product( 103, 'variation', array( 'parent' => 100, 'price' => '', 'in_stock' => false, 'attrs' => array( 'pa_size' => 'tiny' ) ) );
new Fh_Test_Product( 110, 'simple', array( 'price' => '20', 'short' => '', 'status' => 'draft' ) );
new Fh_Test_Product( 120, 'simple', array( 'price' => '' ) );
new Fh_Test_Product( 130, 'simple', array( 'price' => '5' ) );
$GLOBALS['fh_terms'] = array(
	100 => array( 'quarantined-fish' ),
	110 => array( 'tangs' ),
	120 => array( 'salt' ),
	130 => array( 'invoices', 'quarantined-fish' ),
);
$GLOBALS['fh_post_meta'][100] = array(
	'_thumbnail_id'       => '300',
	'_fh_scientific_name' => 'Fishus inventus',
	'_fh_temperament'     => 'calm',
);
$GLOBALS['fh_post_meta'][110] = array( '_thumbnail_id' => '301', '_product_image_gallery' => '5' );

echo "1. The facts of a product\n";
$facts = Collector::facts( 100 );
check( 'its own categories, its status, its photo, gallery, texts, table facts', array( 'quarantined-fish' ) === $facts['cats'] && 'publish' === $facts['status'] && 300 === $facts['thumb'] && false === $facts['thumb_standin'] && '' === $facts['gallery'] && 'Fishus inventus' === $facts['excerpt'] && 'calm' === $facts['facts']['temperament'] );
check_same( 'the in-stock enabled sizes with no price, by their names (a term\'s name, else the value)', array( 'small' ), $facts['price_missing'] );
check( 'no bag lookup while the Packing section is off', null === $facts['bag_missing'] );
check( 'no answer of the theme\'s here: the rules work the old style out', null === $facts['old_style'] );
$goby = Collector::facts( 110 );
check( 'a fish filed under a sub-category of Quarantined Fish', true === $goby['under_table_cat'] && array( 'tangs' ) === $goby['cats'] );
check( 'a placeholder image by its file name', true === $goby['thumb_standin'] );
$GLOBALS['fh_options']['fh_placeholder_library'] = array( '300' );
Collector::reset();
check( 'a placeholder of the theme\'s library', true === Collector::facts( 100 )['thumb_standin'] && in_array( 300, Collector::placeholders(), true ) );
unset( $GLOBALS['fh_options']['fh_placeholder_library'] );
Collector::reset();
check( 'an image featured on 3 or more products', true === Collector::facts( 100, array( 300 ) )['thumb_standin'] );
$GLOBALS['fh_post_meta'][100]['_thumbnail_id'] = '999';
check( 'a featured image that is gone: -1', -1 === Collector::facts( 100 )['thumb'] );
$GLOBALS['fh_post_meta'][100]['_thumbnail_id'] = '300';
check( 'a variation, or nothing: no facts', null === Collector::facts( 101 ) && null === Collector::facts( 999 ) );

echo "2. Checked, written, removed\n";
$store   = new Fh_Test_Store();
$checker = new Checker( $store );
$flags   = $checker->check( 100 );
check_same( 'the tang: gallery, table (6 of 8 missing), old style, a price missing for Small', array( 'table' => '6', 'oldstyle' => '1', 'price' => 'small', 'gallery' => '1' ), $flags );
$meta = $GLOBALS['fh_post_meta'][100];
check( 'written as hidden meta, with how many and the rule version', '6' === $meta['_fh_todo_table'] && '1' === $meta['_fh_todo_oldstyle'] && 'small' === $meta['_fh_todo_price'] && '4' === $meta['_fh_todo'] && (string) Rules::VERSION === $meta['_fh_todo_v'] && ! isset( $meta['_fh_todo_photo'] ) );
check( '— and the counts forgotten', 1 === $store->forgot );
$writes = $GLOBALS['fh_meta_writes'];
$checker->check( 100 );
check( 'checked again unchanged: nothing written, the counts kept', $writes === $GLOBALS['fh_meta_writes'] && 1 === $store->forgot );
$GLOBALS['fh_products'][102]->price = '30';
$GLOBALS['fh_post_meta'][100]['_product_image_gallery'] = '7,8';
$checker->check( 100 );
check( 'fixed: those flags removed, the count down', ! isset( $GLOBALS['fh_post_meta'][100]['_fh_todo_price'] ) && ! isset( $GLOBALS['fh_post_meta'][100]['_fh_todo_gallery'] ) && '2' === $GLOBALS['fh_post_meta'][100]['_fh_todo'] );
check( 'a dry good with a price: nothing stored at all', array() === $checker->check( 130 ) && ! isset( $GLOBALS['fh_post_meta'][130]['_fh_todo'] ) );
check_same( 'salt in stock with no price is flagged too (any product)', array( 'price' => '1' ), $checker->check( 120 ) );
$GLOBALS['fh_options'][ Settings::OPTION ] = array( 'live_cats' => array() );
check_same( 'no live category in the setting: a Quarantined Fish is checked for its table only — the facts and the old style (no photo, gallery or bag check)', array( 'table' => '6', 'oldstyle' => '1' ), $checker->check( 100 ) );
check( '— one filed under a sub-category only: flagged for that', array( 'subcat' => '1' ) === $checker->check( 110 ) );
unset( $GLOBALS['fh_options'][ Settings::OPTION ] );
$checker->check( 100 );
$checker->check( 110 );

echo "3. When a product is checked again\n";
fh_reset_hooks();
$checker = new Checker( $store );
$checker->init();
$hooks = array_keys( $GLOBALS['fh_hooks'] );
sort( $hooks );
check_same(
	'saved anywhere, a variation, a stock status, the photo or gallery, the categories; the end of the request; the sweep; the settings that change a check',
	array( 'add_option_fh_cs_placeholder_library', 'add_option_fh_placeholder_library', 'add_option_fishotel_packing_settings', 'add_option_woocommerce_placeholder_image', 'added_post_meta', 'deleted_post_meta', 'fishotel_product_checks_nightly', 'fishotel_product_checks_sweep', 'set_object_terms', 'shutdown', 'update_option_fh_cs_placeholder_library', 'update_option_fh_placeholder_library', 'update_option_fishotel_packing_settings', 'update_option_woocommerce_placeholder_image', 'updated_post_meta', 'woocommerce_new_product', 'woocommerce_product_set_stock_status', 'woocommerce_save_product_variation', 'woocommerce_update_product', 'woocommerce_variation_set_stock_status' ),
	$hooks
);
$checker->queue( 100 );
$checker->queue( $tang );
$checker->queue_parent( 102 );
$checker->meta_changed( 1, 100, '_thumbnail_id' );
$checker->meta_changed( 1, 100, '_price' );
$checker->meta_changed( 1, 300, '_thumbnail_id' );
$checker->terms_changed( 110, array(), array(), 'product_cat' );
$checker->terms_changed( 120, array(), array(), 'product_tag' );
check( 'each queues an id once — a variation\'s parent, a product\'s photo or category; never another meta, another post, another taxonomy', 2 === $checker->flush() );
check( '— checked at the end of the request, the queue emptied', 0 === $checker->flush() );

echo "4. The sweep\n";
$GLOBALS['fh_cron'] = array();
$store->batches     = range( 1, 450 );
$store->shared_ids  = array();
$checker->start();
check( 'started from the first product, the shared images worked out again, the first run in a minute', 0 === Checker::state()['cursor'] && 1 === $store->refreshed && isset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] ) );
unset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] );
check( 'a run checks 200 and schedules the next', 200 === $checker->run() && 200 === Checker::state()['cursor'] && isset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] ) );
unset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] );
$checker->run();
unset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] );
check( 'the last run checks what is left and stops', 50 === $checker->run() && ! empty( Checker::state()['done'] ) && 450 === Checker::state()['checked'] && ! isset( $GLOBALS['fh_cron'][ Checker::HOOK_RUN ] ) );
check( 'a run after the end does nothing', 0 === $checker->run() );
$GLOBALS['fh_options']['fishotel_product_checks_sweep_v'] = Rules::VERSION;
$checker->maybe_new_rules();
check( 'the same rules: no new sweep', ! empty( Checker::state()['done'] ) );
$GLOBALS['fh_options']['fishotel_product_checks_sweep_v'] = Rules::VERSION - 1;
$checker->maybe_new_rules();
check( 'new rules: a new sweep', empty( Checker::state()['done'] ) && Rules::VERSION === $GLOBALS['fh_options']['fishotel_product_checks_sweep_v'] );
$GLOBALS['fh_cron'] = array();
Checker::ensure_nightly();
Checker::ensure_nightly();
check( 'the nightly start, scheduled once, daily', isset( $GLOBALS['fh_cron'][ Checker::HOOK_NIGHTLY ] ) && 'daily' === $GLOBALS['fh_recurrence'][ Checker::HOOK_NIGHTLY ] );
Checker::unschedule();
check( 'taken off', array() === $GLOBALS['fh_cron'] );
check( 'the sweep\'s cursor is one option, not autoloaded', array( Checker::SWEEP, false ) === array_values( array_filter( $GLOBALS['fh_option_writes'], static function ( $w ) {
	return Checker::SWEEP === $w[0];
} ) )[0] );

echo "5. Which categories count as live\n";
check_same( 'the default: the Batch plugin\'s fish categories', array( 'quarantined-fish', 'inverts' ), Settings::live_cats() );
add_filter(
	'fishotel_ship_day_fish_categories',
	static function ( $c ) {
		return array_merge( $c, array( 'Corals' ) );
	}
);
check( '— and its filter', array( 'quarantined-fish', 'inverts', 'corals' ) === Settings::live_cats() );
fh_reset_hooks();
$nonce = wp_create_nonce( Settings::OPTION );
$known = array_keys( $GLOBALS['fh_term_tree'] );
check( 'nobody logged in, another nonce: refused', false === Settings::save_request( array( '_wpnonce' => $nonce ), $known )['ok'] && ( function () use ( $known ) {
	fh_login( 3, array( 'manage_woocommerce' ) );
	return false === Settings::save_request( array( '_wpnonce' => 'x', 'live_cats' => array( 'corals' ) ), $known )['ok'];
} )() );
$nonce = wp_create_nonce( Settings::OPTION );
$out   = Settings::save_request( array( '_wpnonce' => $nonce, 'live_cats' => array( 'quarantined-fish', 'corals', 'not-a-category', '<b>' ) ), $known );
check( 'corals ticked: saved, only categories that exist, not autoloaded', true === $out['ok'] && array( 'quarantined-fish', 'corals' ) === Settings::live_cats() && Settings::saved() && false !== strpos( $out['message'], 'corals' ) && array( Settings::OPTION, false ) === end( $GLOBALS['fh_option_writes'] ) );
$out = Settings::save_request( array( '_wpnonce' => $nonce, 'live_cats' => array() ), $known );
check( 'none ticked: saved, and the answer says only Livestock counts', true === $out['ok'] && array() === Settings::live_cats() && false !== strpos( $out['message'], 'Livestock' ) );
$out = Settings::save_request( array( '_wpnonce' => $nonce, 'use_default' => '1' ), $known );
check( '"Use the fish categories again"', true === $out['ok'] && ! Settings::saved() && array( 'quarantined-fish', 'inverts' ) === Settings::live_cats() );

echo "6. The products list\n";
fh_login( 3, array( 'manage_woocommerce', 'edit_products', 'edit_post' ) );
$list                 = new Products_List( $store, $checker );
$store->counts_answer = array( 'photo' => 30, 'table' => 50, 'oldstyle' => 7, 'bag' => 6, 'price' => 2, 'cleanroom' => 9, 'gallery' => 3, 'sci' => 1, 'subcat' => 0, 'third' => 4, 'sale' => array( 'photo' => 23, 'table' => 41, 'oldstyle' => 0, 'bag' => 6, 'price' => 2 ) );
$views                = $list->add_views( array( 'all' => '<a>All</a>' ) );
$row                  = $views['fh_todo'];
check( 'a "To fix (for sale now)" row after WooCommerce\'s own links', array( 'all', 'fh_todo' ) === array_keys( $views ) && 0 === strpos( $row, '<span class="fh-pc-views"><strong>To fix (for sale now):</strong>' ), $row );
check( 'each count — of the products for sale now — a link to its filter, in order; a 0 is not shown', 1 === preg_match( '/without a real photo <span class="count">\(23\)<\/span>.*missing table info <span class="count">\(41\)<\/span>.*without a bag size <span class="count">\(6\)<\/span>.*no price <span class="count">\(2\)<\/span>.*Clean Room drafts <span class="count">\(9\)<\/span>/', $row ) && false === strpos( $row, 'Old-style' ) && false !== strpos( $row, 'post_type=product&amp;fh_todo=photo&amp;post_status=publish&amp;stock_status=instock' ), $row );
check( '— a link lists exactly what its count counts: Published and WooCommerce\'s own "In stock"; the Clean Room drafts as drafts', 'https://example.test/wp-admin/edit.php?post_type=product&fh_todo=table&post_status=publish&stock_status=instock' === Products_List::view_url( 'table' ) && false === strpos( Products_List::view_url( 'cleanroom' ), 'post_status' ) );
check( '— and it says where drafts and sold-out fish are (the dropdown: every product checked)', false !== strpos( $row, 'drafts and sold-out fish: the &quot;To fix&quot; dropdown' ) );
check( '"Recount now" for who may manage the shop', false !== strpos( $row, '>Recount now</a>' ) );
$store->counts_answer = array();
check( 'nothing to fix: it says so', false !== strpos( $list->add_views( array() )['fh_todo'], 'nothing — every product for sale is complete' ) );
$GLOBALS['fh_options'][ Checker::SWEEP ] = array( 'cursor' => 110, 'started' => 1, 'done' => 0, 'checked' => 2, 'ever' => 0 );
$row = $list->add_views( array() )['fh_todo'];
check( 'the very first check still running: the row says it is still counting (not "nothing")', false !== strpos( $row, 'still counting' ) && false !== strpos( $row, '(2 so far)' ) && false === strpos( $row, 'nothing —' ) );
$cells_list = new Products_List( $store, $checker );
ob_start();
$cells_list->render_column( 'fh_todo', 130 );
$cells_list->render_column( 'fh_todo', 100 );
$cells = (string) ob_get_clean();
check( '— a product it has not reached yet shows "…", not ✓; one it has checked shows its answer', false !== strpos( $cells, 'fh-pc-wait' ) && false === strpos( $cells, 'fh-pc-ok' ) );
$GLOBALS['fh_options'][ Checker::SWEEP ] = array( 'cursor' => 110, 'started' => 1, 'done' => 0, 'checked' => 2, 'ever' => 1 );
check( '— a later sweep (one has finished before): the counts as they are', false === strpos( $list->add_views( array() )['fh_todo'], 'still counting' ) && ! Products_List::first_count_running( Checker::state() ) );
$checker->start();
check( '— a sweep started after one finished remembers it', 1 === Checker::state()['ever'] );
unset( $GLOBALS['fh_options'][ Checker::SWEEP ] );
check( 'the first sweep starts with nothing finished before', ( function () use ( $checker ) {
	$checker->start();
	$ever = Checker::state()['ever'];
	unset( $GLOBALS['fh_options'][ Checker::SWEEP ] );
	return 0 === $ever;
} )() );
fh_login( 4 );
check( 'not for a user who may not edit products', array( 'all' => 'x' ) === $list->add_views( array( 'all' => 'x' ) ) );
fh_login( 3, array( 'manage_woocommerce', 'edit_products', 'edit_post' ) );

check_same( 'the dropdown: the row\'s six, then four more', array( 'photo', 'table', 'oldstyle', 'bag', 'price', 'cleanroom', 'gallery', 'sci', 'subcat', 'third' ), array_keys( Products_List::filters() ) );
$_GET = array( 'fh_todo' => 'table' );
ob_start();
$list->render_filter();
$html = (string) ob_get_clean();
check( '— drawn beside WooCommerce\'s, the chosen one selected', false !== strpos( $html, '<select name="fh_todo"' ) && false !== strpos( $html, '<option value="table" selected="selected">' ) && array( 'product_type', 'fh_todo' ) === array_keys( $list->add_filter( array( 'product_type' => 'x' ) ) ) );
check_same( 'a filter is a plain EXISTS on one key', array( array( 'key' => '_fh_todo_table', 'compare' => 'EXISTS' ) ), Products_List::query_for( 'table', array() )['meta_query'] );
check( 'Clean Room drafts: drafts with the Clean Room\'s mark; 3rd bag: the tick', 'draft' === Products_List::query_for( 'cleanroom', array() )['post_status'] && '_fh_cleanroom_fish' === Products_List::query_for( 'cleanroom', array() )['meta_query'][0]['key'] && array( 'key' => '_fh_bag_layers', 'value' => '3' ) === Products_List::query_for( 'third', array() )['meta_query'][0] );
check( 'an unknown filter changes nothing', array( 'a' => 1 ) === Products_List::query_for( 'nope', array( 'a' => 1 ) ) );
$GLOBALS['pagenow'] = 'edit.php';
$vars               = $list->filter_request( array( 'post_type' => 'product', 'orderby' => 'fh_todo' ) );
check( 'on the products list: the filter and the sort by "To fix" — every product kept (EXISTS or not), by how many', '_fh_todo_table' === $vars['meta_query'][0]['key'] && 'fh_todo_n' === $vars['orderby'] && 'OR' === $vars['meta_query']['fh_todo_sum']['relation'] && 'NOT EXISTS' === $vars['meta_query']['fh_todo_sum'][0]['compare'] );
check( 'another list: left alone', array( 'post_type' => 'shop_order' ) === $list->filter_request( array( 'post_type' => 'shop_order' ) ) );
$_GET = array();

check_same( 'the "To fix" column right after Name', array( 'cb', 'thumb', 'name', 'fh_todo', 'sku' ), array_keys( $list->add_column( array( 'cb' => '', 'thumb' => '', 'name' => '', 'sku' => '' ) ) ) );
check( '— sortable', array( 'fh_todo', true ) === $list->sortable( array() )['fh_todo'] );
$GLOBALS['fh_post_meta'][100]['_fh_bag_layers'] = '3';
ob_start();
$list->render_column( 'fh_todo', 100 );
$list->render_column( 'fh_todo', 130 );
$list->render_column( 'sku', 100 );
$cells = (string) ob_get_clean();
check( 'chips for the tang, "×3" for its tick; a ✓ for a clean product; nothing in another column', false !== strpos( $cells, '<span class="fh-pc-chip fh-pc-chip--table">Table: 6 of 8 empty</span>' ) && false !== strpos( $cells, '<span class="fh-pc-third" title="Needs a 3rd bag">×3</span>' ) && 1 === substr_count( $cells, 'fh-pc-ok' ), $cells );

$actions = $list->row_actions( array( 'edit' => 'x' ), (object) array( 'ID' => 100, 'post_type' => 'product' ) );
check( 'row actions: Recount; no "Copy facts" while the theme has none', array( 'edit', 'fh_recount' ) === array_keys( $actions ) );
check( 'bulk: Recount; no "Copy facts" without the theme', array( 'fh_recount' ) === array_keys( $list->bulk_actions( array() ) ) );

require __DIR__ . '/fake-theme.php'; // The theme's copy (1.28+) is there from now on.
$GLOBALS['fh_theme_on'] = true;
$actions                = $list->row_actions( array(), (object) array( 'ID' => 100, 'post_type' => 'product' ) );
check( 'with the theme\'s copy: "Copy facts from the description" on an old-style fish only', isset( $actions['fh_copy_facts'] ) && ! isset( $list->row_actions( array(), (object) array( 'ID' => 130, 'post_type' => 'product' ) )['fh_copy_facts'] ) && isset( $list->bulk_actions( array() )['fh_copy_facts'] ) );
$out = $list->run( array( 'op' => 'copy', 'id' => 100, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|copy|100' ) ) );
check( 'the link copies (the theme\'s copy) and checks the product again: one more fact filled, the table count down', array( 'op' => 'copy', 'n' => 1 ) === $out && 'Invented Tang' === $GLOBALS['fh_post_meta'][100]['_fh_common_names'] && '5' === $GLOBALS['fh_post_meta'][100]['_fh_todo_table'], var_export( $GLOBALS['fh_post_meta'][100], true ) );
$GLOBALS['fh_post_meta'][130]['_fh_common_names'] = '';
$url = $list->handle_bulk( 'https://example.test/wp-admin/edit.php?post_type=product', 'fh_copy_facts', array( 130, 120 ) );
check( 'bulk "Copy facts" with a dry good and salt ticked ("Select all"): neither is "Old style", nothing is written onto them', '' === $GLOBALS['fh_post_meta'][130]['_fh_common_names'] && ! isset( $GLOBALS['fh_post_meta'][120]['_fh_common_names'] ) && false !== strpos( $url, 'fh_pc_done=copy&fh_pc_n=0' ) );
check( '— nor through a link for one of them', array( 'op' => 'copy', 'n' => 0 ) === $list->run( array( 'op' => 'copy', 'id' => 130, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|copy|130' ) ) ) && '' === $GLOBALS['fh_post_meta'][130]['_fh_common_names'] );
check( 'a link with another product\'s nonce, another op\'s, or none: refused', null === $list->run( array( 'op' => 'copy', 'id' => 110, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|copy|100' ) ) ) && null === $list->run( array( 'op' => 'recount', 'id' => 100, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|copy|100' ) ) ) && null === $list->run( array( 'op' => 'copy', 'id' => 100 ) ) && null === $list->run( array( 'op' => 'delete', 'id' => 100, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|delete|100' ) ) ) );
check( 'recount one product', array( 'op' => 'recount', 'n' => 1 ) === $list->run( array( 'op' => 'recount', 'id' => 110, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|recount|110' ) ) ) );
$store->batches = array( 100, 110, 120 );
$out            = $list->run( array( 'op' => 'recount', 'id' => 0, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|recount|0' ) ) );
check( 'recount the whole list: the first products now, the rest on WP-cron', array( 'op' => 'sweep', 'n' => 3 ) === $out );
fh_login( 5, array( 'edit_post' ) );
check( '— only for who may manage the shop', null === $list->run( array( 'op' => 'recount', 'id' => 0, '_wpnonce' => wp_create_nonce( 'fishotel_product_checks|recount|0' ) ) ) );
fh_login( 3, array( 'manage_woocommerce', 'edit_products', 'edit_post' ) );
$url = $list->handle_bulk( 'https://example.test/wp-admin/edit.php?post_type=product', 'fh_recount', array( 100, 110 ) );
check( 'bulk Recount: done, and the list says how many', false !== strpos( $url, 'fh_pc_done=recount&fh_pc_n=2' ) );
check( 'another bulk action is left to its owner', 'u' === $list->handle_bulk( 'u', 'trash', array( 100 ) ) );
$_GET = array( 'fh_pc_done' => 'recount', 'fh_pc_n' => '2' );
ob_start();
$list->notice();
check( '— the line it says', false !== strpos( (string) ob_get_clean(), '&quot;To fix&quot; checked again for 2 products.' ) );
$_GET = array();

echo "7. The Fish essentials chips\n";
$chips = Admin::missing_chips( array(), 100 );
check( 'the product\'s flags as chips, each a link to its box', array() !== $chips && '#fishotel_hotel_data' === $chips[0]['href'] && 0 === strpos( $chips[0]['label'], 'Table: ' ) );
check( 'a clean product: none; chips from someone else kept', array( array( 'label' => 'x' ) ) === Admin::missing_chips( array( array( 'label' => 'x' ) ), 130 ) );

echo "8. The section's hooks\n";
fh_reset_hooks();
$GLOBALS['fh_is_admin'] = false;
Product_Checks::boot();
check( 'outside wp-admin: only the checker\'s own hooks (a product can change at checkout)', array() === array_diff( array_keys( $GLOBALS['fh_hooks'] ), array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_save_product_variation', 'woocommerce_variation_set_stock_status', 'woocommerce_product_set_stock_status', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'set_object_terms', 'shutdown', 'fishotel_product_checks_sweep', 'fishotel_product_checks_nightly', 'update_option_fh_placeholder_library', 'add_option_fh_placeholder_library', 'update_option_fh_cs_placeholder_library', 'add_option_fh_cs_placeholder_library', 'update_option_woocommerce_placeholder_image', 'add_option_woocommerce_placeholder_image', 'update_option_fishotel_packing_settings', 'add_option_fishotel_packing_settings' ) ) && ! isset( $GLOBALS['fh_hooks']['views_edit-product'] ) );
fh_reset_hooks();
$GLOBALS['fh_is_admin'] = true;
Product_Checks::boot();
check( 'in wp-admin: the list, the page\'s save, the chips, admin_init', isset( $GLOBALS['fh_hooks']['views_edit-product'], $GLOBALS['fh_hooks']['woocommerce_products_admin_list_table_filters'], $GLOBALS['fh_hooks']['manage_product_posts_columns'], $GLOBALS['fh_hooks']['handle_bulk_actions-edit-product'], $GLOBALS['fh_hooks']['admin_post_fishotel_product_checks'], $GLOBALS['fh_hooks']['admin_post_fishotel_product_checks_save'], $GLOBALS['fh_hooks']['fishotel_edit_screens_missing'], $GLOBALS['fh_hooks']['admin_init'] ) );
$GLOBALS['fh_cron'] = array( Checker::HOOK_NIGHTLY => 1, Checker::HOOK_RUN => 2 );
Product_Checks::boot_disabled();
check( 'switched off: no sweep left on WP-cron', array() === $GLOBALS['fh_cron'] );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'edit-product' );
( new Admin( $store, $checker ) )->assets();
check( 'its stylesheet on the products list', array( 'style:fishotel-product-checks' ) === $GLOBALS['fh_enqueued'] );

echo "9. Nothing for a visitor\n";
$code = '';
foreach ( glob( dirname( __DIR__ ) . '/*.php' ) as $file ) {
	$code .= file_get_contents( $file );
}
check( 'no front-end output, no REST route, no e-mail', 0 === preg_match( '/wp_enqueue_scripts|register_rest_route|wp_mail|the_content|woocommerce_single_product/', $code ) );
check( 'the flags are hidden meta (a leading underscore)', '_' === Rules::PREFIX[0] && '_' === Rules::META_SUM[0] );
check( '$wpdb only in the store', 1 === count( array_filter( glob( dirname( __DIR__ ) . '/*.php' ), static function ( $file ) {
	return false !== strpos( (string) file_get_contents( $file ), '$wpdb' );
} ) ) );

fh_finish();
