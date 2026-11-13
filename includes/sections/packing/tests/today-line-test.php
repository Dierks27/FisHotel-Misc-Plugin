<?php
/**
 * The Packing line on the Today page — "Packing: 3 orders ship today or
 * tomorrow with no box confirmed": the line itself (pure), the dates it
 * looks at, the one query behind it (legacy order tables and HPOS), and
 * the filter with the section on, empty, off (never hooked: only boot()
 * in wp-admin hooks it) and for a user who may not open the section.
 *
 * Run from the plugin root:
 *   php includes/sections/packing/tests/today-line-test.php
 *
 * @package FisHotel\Misc\Sections\Packing
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Store;
use FisHotel\Misc\Sections\Packing\Today_Line;

Packing::load();

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-03-02';
	}
}

/**
 * A $wpdb that keeps the last query and answers the ids it is given.
 */
class Fh_Today_Wpdb {
	public $prefix   = 'wp_';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries  = array();
	public $ids      = array();

	public function prepare( $sql, $args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return $this->ids;
	}
}
$GLOBALS['wpdb'] = new Fh_Today_Wpdb();

$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( 1 );
$GLOBALS['fh_current_user']                    = 1;

echo "1. The line\n";
check( 'every order has its box: no line', null === Today_Line::line( array( 'orders' => array() ), 'u' ) && null === Today_Line::line( array(), 'u' ) );
$fh_one = Today_Line::line( array( 'orders' => array( 1201 ), 'edit' => 'https://example.test/wp-admin/post.php?post=1201&action=edit' ), 'https://example.test/list' );
check( 'one: "Packing: 1 order ships today or tomorrow with no box confirmed", under Shipping', 'Packing: 1 order ships today or tomorrow with no box confirmed' === $fh_one['text'] && 'shipping' === $fh_one['group'] && 1 === $fh_one['count'] && 'packing' === $fh_one['key'] );
check( '… its number beside it, and the link opens that order', '#1201' === $fh_one['detail'] && 'https://example.test/wp-admin/post.php?post=1201&action=edit' === $fh_one['url'] );
$fh_many = Today_Line::line( array( 'orders' => array( 1, 2, 3, 4, 5, 6, 7 ), 'edit' => 'x' ), 'https://example.test/list' );
check( 'seven: five numbers "and 2 more", and the link is the orders list', 'Packing: 7 orders ship today or tomorrow with no box confirmed' === $fh_many['text'] && '#1, #2, #3, #4, #5 and 2 more' === $fh_many['detail'] && 'https://example.test/list' === $fh_many['url'] );

echo "2. The days\n";
check( 'shipping today and tomorrow = delivering tomorrow and the day after', array( '2026-03-03', '2026-03-04' ) === Today_Line::delivery_dates( '2026-03-02' ) && array( '2026-02-28', '2026-03-01' ) === Today_Line::delivery_dates( '2026-02-27' ) && array() === Today_Line::delivery_dates( 'soon' ) );

echo "3. The query\n";
$fh_store = new Store();
$GLOBALS['wpdb']->ids = array( '1201', '1305' );
check( 'the ids come back as numbers', array( 1201, 1305 ) === $fh_store->unconfirmed_orders( array( '2026-03-03', '2026-03-04' ), array( 'processing', 'wc-fulfillment' ), false ) );
$fh_sql = end( $GLOBALS['wpdb']->queries );
check( 'legacy order tables: shop orders in the open statuses, delivering on the two dates', false !== strpos( $fh_sql, "FROM wp_posts p INNER JOIN wp_postmeta d ON d.post_id = p.ID AND d.meta_key = '_fishotel_shipping_date'" ) && false !== strpos( $fh_sql, "p.post_status IN ('wc-processing','wc-fulfillment')" ) && false !== strpos( $fh_sql, "d.meta_value IN ('2026-03-03','2026-03-04')" ), $fh_sql );
check( '… with no confirmed box and not folded into a combined box', false !== strpos( $fh_sql, "b.meta_key = '_fh_packing_box' AND b.meta_value <> ''" ) && false !== strpos( $fh_sql, "f.meta_key = '_fishotel_fulfilled_by_order'" ) && false !== strpos( $fh_sql, 'LIMIT 50' ), $fh_sql );
$fh_store->unconfirmed_orders( array( '2026-03-03' ), array( 'processing' ), true );
$fh_sql = end( $GLOBALS['wpdb']->queries );
check( 'HPOS: the same on WooCommerce\'s own tables', false !== strpos( $fh_sql, "FROM wp_wc_orders o INNER JOIN wp_wc_orders_meta d ON d.order_id = o.id" ) && false !== strpos( $fh_sql, "o.type = 'shop_order' AND o.status IN ('wc-processing')" ), $fh_sql );
$fh_before = count( $GLOBALS['wpdb']->queries );
check( 'no dates or no statuses: no query, nothing', array() === $fh_store->unconfirmed_orders( array(), array( 'processing' ), false ) && array() === $fh_store->unconfirmed_orders( array( 'x' ), array( 'processing' ), false ) && array() === $fh_store->unconfirmed_orders( array( '2026-03-03' ), array(), false ) && count( $GLOBALS['wpdb']->queries ) === $fh_before );

echo "4. Off\n";
check( 'the section never booted in wp-admin: no line is hooked', array() === apply_filters( 'fishotel_today_lines', array() ) );

echo "5. On\n";
Today_Line::register();
$GLOBALS['wpdb']->ids     = array();
$GLOBALS['wpdb']->queries = array();
check( 'every order has its box: no line (empty)', array() === apply_filters( 'fishotel_today_lines', array() ) );
check( '… after one query, for today\'s two delivery dates', 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $GLOBALS['wpdb']->queries[0], "IN ('2026-03-03','2026-03-04')" ) );
apply_filters( 'fishotel_today_lines', array() );
check( '… asked once per request', 1 === count( $GLOBALS['wpdb']->queries ) );

$GLOBALS['wpdb']->ids = array( '1201' );
Today_Line::use_source();
$fh_lines = apply_filters( 'fishotel_today_lines', array( array( 'key' => 'other', 'text' => 'Another section' ) ) );
check( 'one order without a box: the line joins the others, linked to that order', 2 === count( $fh_lines ) && 'packing' === $fh_lines[1]['key'] && false !== strpos( $fh_lines[1]['url'], 'post.php?post=1201&action=edit' ), fh_test_export( $fh_lines ) );
$GLOBALS['wpdb']->ids = array( '1201', '1202' );
Today_Line::use_source();
$fh_lines = apply_filters( 'fishotel_today_lines', array() );
check( 'two: linked to the open orders list', false !== strpos( $fh_lines[0]['url'], 'edit.php?post_type=shop_order' ) );
$GLOBALS['fh_current_user'] = 2;
check( 'a user who may not open Packing: no line', array() === apply_filters( 'fishotel_today_lines', array() ) );
$GLOBALS['fh_current_user'] = 1;
Today_Line::use_source( static function () {
	throw new \RuntimeException( 'x' );
} );
check( 'a source that fails adds nothing', array() === apply_filters( 'fishotel_today_lines', array() ) );

fh_finish();
