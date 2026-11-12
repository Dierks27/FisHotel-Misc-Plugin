<?php
/**
 * The box on the order — on invented orders: confirming the suggestion,
 * choosing another box, clearing it; the record that is stored, and that
 * it is ORDER META written through the order object only (so it works
 * with High-Performance Order Storage on or off); the capability, the
 * nonce and every validation; the Packing box on both order screens and
 * what it draws; and the three contracts other code relies on, with their
 * filters and actions.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/order-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Order_Box;
use FisHotel\Misc\Sections\Packing\Order_Packing;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Settings;

fh_test_settings();
$nonce = wp_create_nonce( Order_Packing::NONCE_ACTION );

/**
 * The invented catalogue and one order: 6 Small + 2 Large bags.
 */
function fh_shop() {
	fh_reset_shop();
	fh_product( new Fh_Fake_Product( 100, 'variable' ) );
	fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 102, 'variation', array( 'pa_size' => 'big' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 103, 'variation', array( 'pa_size' => 'show' ), array(), 100 ) );
	fh_product( new Fh_Fake_Product( 120, 'simple' ) );
	$GLOBALS['fh_terms'] = array(
		100 => array( 'quarantined-fish' ),
		120 => array( 'dry-goods' ),
	);
	return fh_order(
		new Fh_Fake_Order(
			700,
			array(
				new Fh_Fake_Item( 1, 'Invented Goby - Small', 6, 100, array(), 101 ),
				new Fh_Fake_Item( 2, 'Invented Goby - Big', 2, 100, array(), 102 ),
				new Fh_Fake_Item( 3, 'Reef <b>Salt</b> 5 lb', 1, 120 ),
			)
		)
	);
}

// The size list is the invented one for every call in this file.
fh_use_sizes( fh_test_sizes() );

$order = fh_shop();

echo "1. Who may confirm, and with what\n";
$post = array(
	'_wpnonce' => $nonce,
	'order_id' => '700',
	'op'       => 'confirm',
	'choice'   => 'suggested',
);
fh_login( 0 );
$out = Order_Packing::confirm_request( $post );
check( 'nobody logged in: 403, the order untouched', 403 === $out['status'] && array() === $order->writes() && ! isset( $out['order'] ) );
fh_login( 8, array( 'edit_shop_orders' ) );
$out = Order_Packing::confirm_request( $post );
check( 'a user who can edit orders but not manage WooCommerce: 403', 403 === $out['status'] && array() === $order->writes() );
fh_login( 5, array( 'manage_woocommerce' ) );
$out = Order_Packing::confirm_request( array( '_wpnonce' => wp_create_nonce( Settings::NONCE_ACTION ) ) + $post );
check( 'the nonce of the settings page is not the order box\'s: 403', 403 === $out['status'] && array() === $order->writes() );
$out = Order_Packing::confirm_request( array_diff_key( $post, array( '_wpnonce' => 1 ) ) );
check( 'no nonce: 403', 403 === $out['status'] && array() === $order->writes() );
$out = Order_Packing::confirm_request( array( 'order_id' => '999' ) + $post );
check( 'an order that does not exist: 404', 404 === $out['status'] );
$out = Order_Packing::confirm_request( array( 'order_id' => array( '700' ) ) + $post );
check( 'an order id that is not a number: 404', 404 === $out['status'] );
$out = Order_Packing::confirm_request( array( 'op' => 'explode' ) + $post );
check( 'an unknown action: 400, the order untouched', 400 === $out['status'] && array() === $order->writes() );
$out = Order_Packing::confirm_request( array( 'op' => 'view' ) + $post );
check( '"view" only draws the box again: nothing is written', 200 === $out['status'] && $out['order'] === $order && array() === $order->writes() );

echo "2. Confirming the suggestion\n";
$suggestion = fishotel_packing_suggest( 700 );
check( 'before: the contract says unavailable while the section is switched off', false === $suggestion['available'] && 'disabled' === $suggestion['reason'] && 'unavailable' === $suggestion['status'] && null === fishotel_packing_confirmed_box( 700 ) );
Packing::set_enabled( true );
$suggestion = fishotel_packing_suggest( 700 );
check( 'switched on: 6/12 + 2/4 = 100 % of Cube 12, on the line, nothing confirmed yet', true === $suggestion['available'] && 'fits' === $suggestion['status'] && 'Cube 12' === $suggestion['boxes'][0]['name'] && 100.0 === $suggestion['boxes'][0]['fill_pct'] && true === $suggestion['on_the_line'] && null === $suggestion['confirmed'] && 700 === $suggestion['order_id'] );
check( 'asking for the suggestion wrote nothing', array() === $order->writes() );

$GLOBALS['fh_did'] = array();
$out               = Order_Packing::confirm_request( $post );
check( 'confirmed: 200 and the box named', 200 === $out['status'] && true === $out['ok'] && 'Packing box confirmed: Cube 12.' === $out['message'], $out['message'] );
check( 'written through the order object: two update_meta_data() and one save() — nothing else', array( 'update_meta_data:_fh_packing_box', 'update_meta_data:_fh_packing_box_name', 'save' ) === $order->writes(), fh_test_export( $order->writes() ) );
$record = $order->meta['_fh_packing_box'];
check( 'the record: the box with its size, the fill as it stood, that it was the suggestion and complete, who and when', 1 === $record['v'] && array( array( 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '12' ) ) === $record['boxes'] && array( 1.0 ) === $record['fill'] && true === $record['suggested'] && true === $record['complete'] && 5 === $record['by'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['at'] ), fh_test_export( $record ) );
check( 'the name is stored beside it as plain text', 'Cube 12' === $order->meta['_fh_packing_box_name'] );
check( 'both keys are hidden meta (a leading underscore)', '_' === Packing::META_BOX[0] && '_' === Packing::META_BOX_NAME[0] );
check( 'no order note, no status change', array() === $order->notes && 'processing' === $order->status );
check( 'the action fishotel_packing_box_confirmed fired with the order and the box', array( 'fishotel_packing_box_confirmed' ) === fh_actions_done() && 700 === $GLOBALS['fh_did'][0][1][0] && 'Cube 12' === $GLOBALS['fh_did'][0][1][1]['name'] );

$box = fishotel_packing_confirmed_box( 700 );
check( 'fishotel_packing_confirmed_box(): the boxes with their size, the first box\'s size at the top, who and when', 700 === $box['order_id'] && 1 === $box['count'] && 'Cube 12' === $box['name'] && '12x12x12' === $box['dims'] && 12.0 === $box['length'] && 12.0 === $box['width'] && 12.0 === $box['height'] && 1728.0 === $box['boxes'][0]['volume'] && array( 1.0 ) === $box['fill'] && true === $box['suggested'] && true === $box['complete'] && 5 === $box['by'] && 'User 5' === $box['by_name'] && $record['at'] === $box['at'], fh_test_export( $box ) );
check( 'the suggestion carries it too', $box === fishotel_packing_suggest( 700 )['confirmed'] );
check( 'an order without one, or no order at all: null', null === fishotel_packing_confirmed_box( 999 ) && null === fishotel_packing_confirmed_box( 0 ) && null === fishotel_packing_confirmed_box( 'x' ) );

echo "3. Choosing another box\n";
$order->calls = array();
$out          = Order_Packing::confirm_request( array( 'choice' => 'long 20' ) + $post );
$box          = fishotel_packing_confirmed_box( 700 );
check( 'a box of the list by its key: stored, with the fill the order has in it (6/30 + 2/10 = 40 %)', 200 === $out['status'] && 'Long 20' === $box['name'] && '20x14x12' === $box['dims'] && array( 0.4 ) === $box['fill'] && false === $box['suggested'], fh_test_export( $box ) );
$out = Order_Packing::confirm_request( array( 'choice' => 'Cube 12', 'count' => '1' ) + $post );
check( 'choosing the very box that is suggested counts as the suggestion', true === fishotel_packing_confirmed_box( 700 )['suggested'] );
$out = Order_Packing::confirm_request( array( 'choice' => 'cube 8', 'count' => '3' ) + $post );
$box = fishotel_packing_confirmed_box( 700 );
check( 'three of a box: three boxes, named "3 × …"; a box a Large bag does not go in has no fill to report', 200 === $out['status'] && 3 === $box['count'] && '3 × Cube 8' === $box['name'] && '3 × Cube 8' === $order->meta['_fh_packing_box_name'] && array() === $box['fill'] && 3 === count( $box['boxes'] ), fh_test_export( $box ) );
$out = Order_Packing::confirm_request( array( 'choice' => 'long 20', 'count' => '2' ) + $post );
check( 'two of a box that takes every bag: the fill is shared between them', array( 0.2, 0.2 ) === fishotel_packing_confirmed_box( 700 )['fill'] );

$before = $order->meta;
$writes = count( $order->writes() );
$bad    = array(
	'a box that is not in the list' => array( 'choice' => 'crate 99' ),
	'a count of zero'               => array( 'choice' => 'long 20', 'count' => '0' ),
	'a count over the maximum'      => array( 'choice' => 'long 20', 'count' => (string) ( Order_Packing::MAX_COUNT + 1 ) ),
	'a count that is not whole'     => array( 'choice' => 'long 20', 'count' => '1.5' ),
	'a count that is not a number'  => array( 'choice' => 'long 20', 'count' => array( 2 ) ),
);
foreach ( $bad as $label => $extra ) {
	$out = Order_Packing::confirm_request( $extra + $post );
	check( $label . ': 400, the stored box unchanged', 400 === $out['status'] && $before === $order->meta && count( $order->writes() ) === $writes, $out['message'] );
}

echo "4. Clearing\n";
$GLOBALS['fh_did'] = array();
$order->calls      = array();
$out               = Order_Packing::confirm_request( array( 'op' => 'clear' ) + $post );
check( 'cleared through the order object: two delete_meta_data() and one save()', 200 === $out['status'] && array( 'delete_meta_data:_fh_packing_box', 'delete_meta_data:_fh_packing_box_name', 'save' ) === $order->writes() && ! isset( $order->meta['_fh_packing_box'] ) && ! isset( $order->meta['_fh_packing_box_name'] ) );
check( 'the action fishotel_packing_box_cleared fired; the contract answers null again', array( 'fishotel_packing_box_cleared' ) === fh_actions_done() && null === fishotel_packing_confirmed_box( 700 ) );
$order->calls = array();
$out          = Order_Packing::confirm_request( array( 'op' => 'clear' ) + $post );
check( 'clearing when there is nothing to clear saves nothing', 200 === $out['status'] && array() === $order->writes() );

echo "5. When the suggestion cannot simply be confirmed\n";
$order->items[4] = new Fh_Fake_Item( 4, 'Invented Goby - Show', 1, 100, array(), 103 );
$order->calls    = array();
$out             = Order_Packing::confirm_request( $post );
check( 'an incomplete suggestion can be confirmed — and is recorded as incomplete', 200 === $out['status'] && false === fishotel_packing_confirmed_box( 700 )['complete'] && false === $order->meta['_fh_packing_box']['complete'] );
unset( $order->items[4] );
Order_Packing::confirm_request( array( 'op' => 'clear' ) + $post );

$empty        = fh_order( new Fh_Fake_Order( 701, array( new Fh_Fake_Item( 1, 'Reef Salt 5 lb', 1, 120 ) ) ) );
$out          = Order_Packing::confirm_request( array( 'order_id' => '701' ) + $post );
check( 'nothing is suggested (no fish): 409, choose a box by hand', 409 === $out['status'] && array() === $empty->writes() );
$out = Order_Packing::confirm_request( array( 'order_id' => '701', 'choice' => 'cube 8' ) + $post );
check( 'a box can still be chosen by hand for it', 200 === $out['status'] && 'Cube 8' === fishotel_packing_confirmed_box( 701 )['name'] && array() === fishotel_packing_confirmed_box( 701 )['fill'] );

$folded = fh_order( new Fh_Fake_Order( 702, array( new Fh_Fake_Item( 1, 'Invented Goby - Small', 1, 100, array(), 101 ) ), array( '_fishotel_fulfilled_by_order' => '800' ) ) );
$out    = Order_Packing::confirm_request( array( 'order_id' => '702' ) + $post );
check( 'an order that went into a combined box is confirmed there: 409, nothing written here', 409 === $out['status'] && false !== strpos( $out['message'], '#800' ) && array() === $folded->writes() );
$out = Order_Packing::confirm_request( array( 'order_id' => '702', 'choice' => 'cube 8' ) + $post );
check( 'not by hand either', 409 === $out['status'] && array() === $folded->writes() );

echo "6. What is read back\n";
check( 'the record as an array or as its JSON; junk, an empty value and a record with no usable box are nothing', 'Cube 8' === Order_Packing::read_record( array( 'boxes' => array( array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ) ) ) )['name'] && 'Cube 8' === Order_Packing::read_record( '{"boxes":[{"name":"Cube 8","length":"8","width":"8","height":"8"}]}' )['name'] && null === Order_Packing::read_record( '' ) && null === Order_Packing::read_record( 'x' ) && null === Order_Packing::read_record( array( 'boxes' => array( array( 'name' => 'No size' ) ) ) ) && null === Order_Packing::read_record( 12 ) );
$read = Order_Packing::read_record( array( 'boxes' => array( array( 'name' => 'Cube 8', 'length' => 8, 'width' => 8, 'height' => 8 ), array( 'name' => 'Long 20', 'length' => 20, 'width' => 14, 'height' => 12 ), array( 'name' => 'Cube 8', 'length' => 8, 'width' => 8, 'height' => 8 ) ), 'fill' => array( 1, 0.5 ), 'by' => 'nobody', 'at' => 7 ), 9 );
check( 'several boxes are named together; a fill list that does not match the boxes is dropped; a bad "by" is 0', '2 × Cube 8 + Long 20' === $read['name'] && 3 === $read['count'] && array() === $read['fill'] && 0 === $read['by'] && '' === $read['by_name'] && '' === $read['at'] && 9 === $read['order_id'] );
check( 'a record needs a box with a name and a size', null === Order_Packing::record( array( array( 'name' => 'x' ) ), array(), true, true, 1, '2026-01-01 00:00:00' ) && null === Order_Packing::record( array(), array(), true, true, 1, '2026-01-01 00:00:00' ) );

echo "7. The contracts: one filter each\n";
add_filter(
	'fishotel_packing_suggest',
	static function ( $result, $order_id ) {
		$result['filtered_for'] = $order_id;
		return $result;
	},
	10,
	2
);
add_filter(
	'fishotel_packing_confirmed_box',
	static function ( $box, $order_id ) {
		return 701 === $order_id ? null : $box;
	},
	10,
	2
);
add_filter(
	'fishotel_packing_bag_for_item',
	static function ( $bag, $item ) {
		if ( is_array( $bag ) ) {
			$bag['seen_item'] = $item->get_id();
		}
		return $bag;
	},
	10,
	2
);
check( 'fishotel_packing_suggest is filtered with the order id', 700 === fishotel_packing_suggest( 700 )['filtered_for'] );
check( 'fishotel_packing_confirmed_box is filtered with the order id', null === fishotel_packing_confirmed_box( 701 ) );
$bag = fishotel_packing_bag_for_item( $order->items[1] );
check( 'fishotel_packing_bag_for_item(): the bag of the line, filtered with the line', 1 === $bag['id'] && 'Small' === $bag['name'] && 'Small (4 × 12 in)' === $bag['label'] && 'map' === $bag['source'] && 'small' === $bag['size'] && 1 === $bag['seen_item'], fh_test_export( $bag ) );
check( 'a line that is not a fish with a bag, or not a line: null', null === fishotel_packing_bag_for_item( $order->items[3] ) && null === fishotel_packing_bag_for_item( 'x' ) && null === fishotel_packing_bag_for_item( null ) );
check( 'fishotel_packing_bag_for_product() and fishotel_packing_bag_for_size() answer from the same rule', 3 === fishotel_packing_bag_for_product( 102 )['id'] && 2 === fishotel_packing_bag_for_size( 'mid' )['id'] && null === fishotel_packing_bag_for_product( 120 ) && null === fishotel_packing_bag_for_size( 'show' ) && null === fishotel_packing_bag_for_size( array( 'mid' ) ) );
fh_reset_hooks();
add_filter(
	'fishotel_packing_suggest',
	static function () {
		return 'not an array';
	}
);
add_filter(
	'fishotel_packing_bag_for_item',
	static function () {
		return 'a bag';
	}
);
check( 'a filter that answers something else than an array cannot break the callers', is_array( fishotel_packing_suggest( 700 ) ) && 'fits' === fishotel_packing_suggest( 700 )['status'] && null === fishotel_packing_bag_for_item( $order->items[1] ) );
fh_reset_hooks();
check( 'an order that does not exist: unavailable, with every key of a real answer', array_keys( fishotel_packing_suggest( 700 ) ) === array_keys( fishotel_packing_suggest( 999 ) ) && 'order_not_found' === fishotel_packing_suggest( 999 )['reason'] && false === fishotel_packing_suggest( 999 )['available'] );
Packing::set_enabled( false );
check( 'switched off again: every contract answers "nothing", none throws', false === fishotel_packing_suggest( 700 )['available'] && null === fishotel_packing_bag_for_item( $order->items[1] ) && null === fishotel_packing_bag_for_product( 101 ) && null === fishotel_packing_bag_for_size( 'mid' ) && null === fishotel_packing_confirmed_box( 700 ) );
Packing::set_enabled( true );

echo "8. The Packing box on the order screen\n";
$box_ui = new Order_Box();
$box_ui->init();
check( 'it is added through the generic add_meta_boxes hook and has one admin AJAX action', array( 'add_meta_boxes', 'wp_ajax_fishotel_packing_confirm' ) === array_keys( $GLOBALS['fh_hooks'] ) );
check( 'the two order screens: the legacy post screen and WooCommerce\'s own (HPOS)', array( 'shop_order', 'woocommerce_page_wc-orders' ) === Order_Box::screens() );
$box_ui->register_meta_box( 'product', null );
$box_ui->register_meta_box( 'comment', null );
check( 'no box on any other screen', array() === $GLOBALS['fh_meta_boxes'] && array() === $GLOBALS['fh_enqueued'] );
fh_login( 8 );
$box_ui->register_meta_box( 'shop_order', null );
check( 'no box for a user who cannot manage WooCommerce', array() === $GLOBALS['fh_meta_boxes'] );
fh_login( 5 );
$box_ui->register_meta_box( 'shop_order', null );
$box_ui->register_meta_box( 'woocommerce_page_wc-orders', $order );
check( 'the box is added on both screens, and brings its script and stylesheet', array( 'shop_order', 'woocommerce_page_wc-orders' ) === array_column( $GLOBALS['fh_meta_boxes'], 'screen' ) && array( 'fishotel-packing', 'fishotel-packing' ) === array_column( $GLOBALS['fh_meta_boxes'], 'id' ) && in_array( 'script:fishotel-packing-admin', $GLOBALS['fh_enqueued'], true ) && in_array( 'style:fishotel-packing-admin', $GLOBALS['fh_enqueued'], true ) && 1 === count( array_keys( $GLOBALS['fh_enqueued'], 'script:fishotel-packing-admin', true ) ) );

Order_Packing::confirm_request( array( 'choice' => 'long 20' ) + $post );
ob_start();
$box_ui->render_meta_box( $order ); // HPOS hands the order itself.
$html = (string) ob_get_clean();
ob_start();
$box_ui->render_meta_box( (object) array( 'ID' => 700 ) ); // The legacy screen hands a post.
$legacy = (string) ob_get_clean();
check( 'the same box from an order (HPOS) and from a post (legacy)', $html === $legacy && false !== strpos( $html, 'class="fh-pack-box"' ) );
check( 'each fish line with its bag', false !== strpos( $html, 'Invented Goby - Small' ) && false !== strpos( $html, '<strong>Small</strong>' ) && false !== strpos( $html, '<strong>Large</strong>' ) && false !== strpos( $html, 'from the size map' ) );
check( 'the bag counts by size', false !== strpos( $html, '6 × Small · 2 × Large' ) && false !== strpos( $html, '8 bags in all' ) );
check( 'the suggested box with its fill, and the on-the-line flag', false !== strpos( $html, 'Suggested box' ) && false !== strpos( $html, '<strong>Cube 12</strong>' ) && false !== strpos( $html, '100% full' ) && false !== strpos( $html, 'fh-pack-badge--line' ) && false !== strpos( $html, 'On the line' ) );
check( 'what is ignored is named — and a product name is escaped', false !== strpos( $html, 'Ignored (not fish):' ) && false !== strpos( $html, 'Reef &lt;b&gt;Salt&lt;/b&gt; 5 lb' ) && false === strpos( $html, '<b>Salt</b>' ) );
check( 'the confirmed box, who confirmed it, and that the suggestion differs from it', false !== strpos( $html, 'Confirmed' ) && false !== strpos( $html, '<strong>Long 20</strong>' ) && false !== strpos( $html, 'by User 5' ) && false !== strpos( $html, 'The suggestion above is different now' ) );
check( 'the Confirm / change control: confirm the suggestion, choose from every box with its fill, clear', false !== strpos( $html, 'data-choice="suggested"' ) && false !== strpos( $html, 'data-choice="chosen"' ) && false !== strpos( $html, 'Cube 8 (8 × 8 × 8 in) — a bag size does not go in it' ) && false !== strpos( $html, 'Long 20 (20 × 14 × 12 in) — 40%' ) && false !== strpos( $html, 'data-op="clear"' ) );
check( 'the box carries its own nonce and the order id; its fields have no name, so the order form never posts them', false !== strpos( $html, 'data-nonce="' . $nonce . '"' ) && false !== strpos( $html, 'data-order-id="700"' ) && 0 === preg_match( '/<(select|input)[^>]*\sname=/', $html ) );
ob_start();
$box_ui->render_meta_box( (object) array( 'ID' => 999 ) );
check( 'an order that cannot be loaded says so', false !== strpos( (string) ob_get_clean(), 'Order not loaded yet.' ) );

$html = Order_Box::render_body( $folded );
check( 'an order that went into a combined box shows its lines and where it is packed — and no Confirm control', false !== strpos( $html, 'packed with combined box #800' ) && false === strpos( $html, 'data-op="confirm"' ) && false !== strpos( $html, 'Invented Goby - Small' ) );
$order->items[4] = new Fh_Fake_Item( 4, 'Invented Goby - Show', 1, 100, array(), 103 );
$html            = Order_Box::render_body( $order );
check( 'a line with no bag size is marked, and the suggestion is badged incomplete', false !== strpos( $html, 'class="is-missing"' ) && false !== strpos( $html, 'No bag size' ) && false !== strpos( $html, 'fh-pack-badge--incomplete' ) && false === strpos( $html, 'It was confirmed while' ) );
unset( $order->items[4] );

echo "9. The AJAX handler answers with the box drawn again\n";
$_POST = array( 'choice' => 'suggested' ) + $post;
try {
	$box_ui->ajax_confirm();
	$reply = null;
} catch ( Fh_Test_Json $reply ) {
	$replied = true; // The reply is what the handler would have sent.
}
check( 'success: the message and the fresh HTML, showing what was stored', $reply instanceof Fh_Test_Json && true === $reply->success && 'Packing box confirmed: Cube 12.' === $reply->data['message'] && false !== strpos( $reply->data['html'], 'fh-pack-box__flash' ) && false !== strpos( $reply->data['html'], 'Packing box confirmed: Cube 12.' ) && false === strpos( $reply->data['html'], 'The suggestion above is different now' ) );
$_POST = array( '_wpnonce' => 'stale' ) + $post;
try {
	$box_ui->ajax_confirm();
	$reply = null;
} catch ( Fh_Test_Json $reply ) {
	$replied = true;
}
check( 'refused: an error with its status, no HTML', $reply instanceof Fh_Test_Json && false === $reply->success && 403 === $reply->status && ! isset( $reply->data['html'] ) );
$_POST = array();

fh_finish();
