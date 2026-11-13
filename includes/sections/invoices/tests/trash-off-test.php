<?php
/**
 * The Trash switched off — on a site with EMPTY_TRASH_DAYS = 0 WordPress
 * deletes a post for good when it is "moved to the Trash". This section
 * never deletes, so there it refuses to trash at all: the product and
 * the invoice stay exactly as they were.
 *
 * (A file of its own, because the constant can only be defined once.)
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/trash-off-test.php
 */

define( 'EMPTY_TRASH_DAYS', 0 );

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Publisher;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Shop;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Store;
use FisHotel\Misc\Sections\Invoices\Tests\Fake_Tanks;

$world     = fh_wc_world();
$store     = new Fake_Store();
$shop      = new Shop();
$publisher = new Publisher( $store, $shop, new Fake_Tanks(), Settings::defaults() );

$check = $publisher->precheck(
	array(
		'handle' => 'Tester',
		'lines'  => array( array( 'kind' => 'fish', 'name' => 'Blue Test Tang', 'qty' => 1, 'unit_price' => '45' ) ),
	),
	8,
	'2026-11-03'
);
$done  = $publisher->publish( $check['id'], $check['token'], array(), 8, '2026-11-03' );
check( 'an invoice is published', $done['ok'] );

$GLOBALS['fh_wc']['writes'] = array();
$before                     = json_encode( $GLOBALS['fh_wc']['products'][ $done['product_id'] ] );

check( 'the shop knows the Trash is off', false === $shop->trash_enabled() );
$r = $publisher->trash( $done['id'], 8 );
check( 'the Trash is refused, with the reason', ! $r['ok'] && 'shop' === $r['code'] && false !== strpos( $r['message'], 'EMPTY_TRASH_DAYS' ) && false !== strpos( $r['message'], 'delete it for good' ) );
check( 'the product still exists, published, untouched — nothing was deleted', isset( $GLOBALS['fh_wc']['products'][ $done['product_id'] ] ) && $before === json_encode( $GLOBALS['fh_wc']['products'][ $done['product_id'] ] ) && array() === $GLOBALS['fh_wc']['writes'] );
check_same( 'the invoice is still published', 'published', $store->invoice( $done['id'] )['state'] );
check( 'the Shop itself refuses as well', ! $shop->trash_product( $done['product_id'], $done['id'] )['ok'] && isset( $GLOBALS['fh_wc']['products'][ $done['product_id'] ] ) );

fh_finish();
