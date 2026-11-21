<?php
/**
 * Reorder, in words: what the stock table, the Today line and the one
 * email say, and what the settings page answers when a count is typed.
 * Pure — the sentences are built from plain rows.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/reorder-test.php
 *
 * Invented designs and numbers.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';

$fh_can = true;
function current_user_can( $cap ) {
	return $GLOBALS['fh_can'] && 'manage_woocommerce' === $cap;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_filters'][ $hook ][] = array( $callback, $priority );
	return true;
}

require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-reorder.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-today-line.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-admin.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Admin;
use FisHotel\Misc\Sections\Sticker_Giveaway\Piles;
use FisHotel\Misc\Sections\Sticker_Giveaway\Reorder;
use FisHotel\Misc\Sections\Sticker_Giveaway\Today_Line;

$failures = 0;
$checks   = 0;

function check( $label, $condition, $detail = '' ) {
	global $failures, $checks;
	$checks++;

	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}

	$failures++;
	echo "  FAIL $label" . ( '' !== (string) $detail ? "\n       $detail" : '' ) . "\n";
}

/**
 * A stock-table row as Reorder::rows() builds it, from the plain facts.
 */
function row( $name, array $facts ) {
	return array_merge( Piles::summary( array_merge( array( 'percent' => 10 ), $facts ) ), array( 'name' => $name ) );
}

$blue  = row( 'Blue Tang', array( 'keeps_pile' => true, 'paper' => 50, 'basis' => 200, 'shop' => 10, 'went' => 36, 'reorder_at' => 50 ) );
$clown = row( 'Clown Fish', array( 'keeps_pile' => true, 'paper' => 20, 'basis' => 200, 'shop' => 6, 'went' => 12, 'reorder_at' => 30 ) );
$gone  = row( 'Sea Green', array( 'keeps_pile' => true, 'paper' => 8, 'basis' => 100, 'shop' => 0, 'went' => 3, 'reorder_at' => 10 ) );
$round = row( 'Round Logo Sticker', array( 'keeps_pile' => false, 'shop' => 7, 'in_stock' => true, 'reorder_at' => 10 ) );

echo "1. What is left, in a few words\n";
check( 'a design with a giveaway pile: "about 40 left" (30 really there + 10 in the shop)', 'about 40 left' === Reorder::left_text( $blue ), Reorder::left_text( $blue ) );
check( 'its giveaway pile down to the reserve but stickers in the shop: says both', 'giveaway pile out, 6 in the shop' === Reorder::left_text( $clown ), Reorder::left_text( $clown ) );
check( 'nothing anywhere: "out"', 'out' === Reorder::left_text( $gone ) );
check( 'a design without a giveaway pile: the plain shop number, no "about"', '7 left' === Reorder::left_text( $round ) );

echo "2. How fast it goes\n";
check( '36 in 28 days with about 30 there', '36 went in the last 28 days · about 9 a week · roughly 3 weeks left' === Reorder::pace_text( $blue ), Reorder::pace_text( $blue ) );
check( 'nothing went: says so, no weeks', 'none went in the last 28 days' === Reorder::pace_text( row( 'x', array( 'keeps_pile' => true, 'paper' => 50, 'basis' => 50 ) ) ) );
check( '3 in 28 days: "less than 1 a week"', false !== strpos( Reorder::pace_text( row( 'x', array( 'keeps_pile' => true, 'paper' => 100, 'basis' => 100, 'went' => 3 ) ) ), 'less than 1 a week' ) );
check( 'one week reads "1 week"', false !== strpos( Reorder::pace_text( row( 'x', array( 'keeps_pile' => true, 'paper' => 19, 'basis' => 100, 'went' => 36 ) ) ), 'roughly 1 week left' ) );
check( 'nearly gone: "less than a week left"', false !== strpos( Reorder::pace_text( row( 'x', array( 'keeps_pile' => true, 'paper' => 12, 'basis' => 100, 'went' => 36 ) ) ), 'less than a week left' ) );
check( 'a pile that outlasts two years is not given a silly number', false !== strpos( Reorder::pace_text( row( 'x', array( 'keeps_pile' => true, 'paper' => 5000, 'basis' => 5000, 'went' => 4 ) ) ), 'more than 99 weeks left' ) );

echo "3. The Today line\n";
$url  = 'https://example.test/wp-admin/admin.php?page=fishotel-misc-sticker-giveaway';
$line = Today_Line::line( array( $blue ), $url );
check( 'one design: "Stickers to reorder: Blue Tang (about 40 left)"', 'Stickers to reorder: Blue Tang (about 40 left)' === $line['text'], $line['text'] );
check( 'it links to the Sticker Giveaway page, counts 1, is something to do', $url === $line['url'] && 1 === $line['count'] && 'todo' === $line['tone'] && 'stickers' === $line['key'] );
check( 'its group is one the Today page knows', in_array( $line['group'], array( 'fish-room', 'customers', 'shipping', 'site' ), true ) );

$line = Today_Line::line( array( $blue, $clown, $gone ), $url );
check( 'three designs, each with what is left', 'Stickers to reorder: Blue Tang (about 40 left), Clown Fish (giveaway pile out, 6 in the shop), Sea Green (out)' === $line['text'] && 3 === $line['count'], $line['text'] );
check( 'no design due: no line', null === Today_Line::line( array(), $url ) );

$many = array();
for ( $i = 1; $i <= 9; $i++ ) {
	$many[] = row( 'A Design With A Rather Long Name ' . $i, array( 'keeps_pile' => true, 'paper' => 50, 'basis' => 200, 'shop' => 10, 'reorder_at' => 50 ) );
}
$line = Today_Line::line( $many, $url );
check( 'many designs: the line stays within the Today page\'s 160 characters', strlen( $line['text'] ) <= 160, strlen( $line['text'] ) . ': ' . $line['text'] );
check( '... names the first ones and counts the rest', 0 === strpos( $line['text'], 'Stickers to reorder: A Design With A Rather Long Name 1 (about 40 left)' ) && (bool) preg_match( '/ and \d more$/', $line['text'] ) && 9 === $line['count'] );

$others = array( array( 'key' => 'packing', 'text' => 'Packing: 2 orders' ), array( 'key' => 'stickers', 'text' => 'an older sticker line' ) );
$with   = Today_Line::with_line( $others, Today_Line::line( array( $blue ), $url ) );
check( 'added to the other sections\' lines; its own older line is replaced, never doubled', 2 === count( $with ) && 'packing' === $with[0]['key'] && 'Stickers to reorder: Blue Tang (about 40 left)' === $with[1]['text'] );
check( 'no line leaves the others untouched', array( $others[0] ) === Today_Line::with_line( array( $others[0] ), null ) );

Today_Line::register();
check( 'it registers on the Today section\'s own filter and nothing else', array( 'fishotel_today_lines' ) === array_keys( $GLOBALS['fh_filters'] ) && 1 === count( $GLOBALS['fh_filters']['fishotel_today_lines'] ) );

Today_Line::use_source(
	static function () use ( $blue ) {
		return array( 302 => $blue );
	}
);
$lines = Today_Line::filter( array( $others[0] ) );
check( 'the filter adds the line for a user who may open the section', 2 === count( $lines ) && 'stickers' === $lines[1]['key'] && $url === $lines[1]['url'] );
$fh_can = false;
check( '... and nothing for a user who may not', array( $others[0] ) === Today_Line::filter( array( $others[0] ) ) );
$fh_can = true;
Today_Line::use_source(
	static function () {
		throw new \RuntimeException( 'the database is away' );
	}
);
check( 'whatever goes wrong adds nothing and breaks nothing', array( $others[0] ) === Today_Line::filter( array( $others[0] ) ) );
Today_Line::use_source( null );

echo "4. The one email\n";
$mail = Reorder::mail( array( $blue ), 'Invented Reef Shop', $url );
check( 'the subject names the site and the design', '[Invented Reef Shop] Stickers to reorder: Blue Tang' === $mail['subject'], $mail['subject'] );
check( 'the body says what is left, where, and the reorder number', false !== strpos( $mail['body'], 'Blue Tang: about 40 left (giveaway pile about 30, shop 10). Reorder at 50.' ), $mail['body'] );
check( 'it says there will be no second email until a restock or a recount', false !== strpos( $mail['body'], 'You will not get another email about a design until it has been restocked or recounted above its reorder number.' ) );
check( 'it links to the settings page', false !== strpos( $mail['body'], 'Sticker Giveaway: ' . $url ) );
check( 'plain text: no markup', $mail['body'] === strip_tags( $mail['body'] ) && $mail['subject'] === strip_tags( $mail['subject'] ) );

$mail = Reorder::mail( array( $blue, $clown ), 'Invented Reef Shop', $url );
check( 'two designs dropping together: one email naming both', '[Invented Reef Shop] Stickers to reorder: Blue Tang, Clown Fish' === $mail['subject'] && false !== strpos( $mail['body'], '2 sticker designs are down to their reorder number:' ) );
check( 'a design whose giveaway pile no longer offers it is said to be off the picker', false !== strpos( $mail['body'], 'customers are no longer offered this design' ) );
$mail = Reorder::mail( array( $round ), 'Invented Reef Shop', '' );
check( 'a design without a giveaway pile: the shop number only', false !== strpos( $mail['body'], 'Round Logo Sticker: 7 left (shop 7). Reorder at 10.' ) && false === strpos( $mail['body'], 'giveaway pile' ), $mail['body'] );

echo "5. Typing a count on the settings page\n";
check( 'a whole number is taken', '' === Admin::count_error( 'add', '100' ) && '' === Admin::count_error( 'recount', '140' ) );
check( 'Recount 0 is a count (the pile is empty)', '' === Admin::count_error( 'recount', '0' ) );
check( 'Add 0 is refused, and says to use Recount to put a count right', false !== strpos( Admin::count_error( 'add', '0' ), 'Recount' ) );
check( 'blank: asks for a number', 'Type a number first.' === Admin::count_error( 'add', '' ) && 'Type a number first.' === Admin::count_error( 'recount', '   ' ) );
check( 'a minus, a decimal, words: refused in plain words', '' !== Admin::count_error( 'add', '-5' ) && '' !== Admin::count_error( 'recount', '12.5' ) && '' !== Admin::count_error( 'recount', 'about 40' ) );
check( 'a slipped key (seven digits) is refused before it becomes the count', '' !== Admin::count_error( 'recount', '2000000' ) && '' !== Admin::count_error( 'add', '100001' ) && '' === Admin::count_error( 'add', '100000' ) );
check( 'an unknown action is refused', '' !== Admin::count_error( 'delete', '5' ) );

check( 'after Add: what was added and what the pile now reads', 'Blue Tang: 100 added. 300 on paper · about 270 really there.' === Admin::count_message( 'add', 'Blue Tang', 100, 300, 270 ) );
check( 'after Recount: what was counted and what is really there', 'Blue Tang: counted 140. 140 on paper · about 126 really there.' === Admin::count_message( 'recount', 'Blue Tang', 140, 140, 126 ) );

echo "6. Why a ticked design is not offered\n";
foreach ( array( 'unpublished', 'parent_unpublished', 'no_price', 'not_purchasable', 'any_attribute', 'pile_empty', 'pile_reserve', 'out_of_stock', 'type' ) as $code ) {
	$text = Admin::blocker_text( $code, 'Blue Tang', true );
	check( "$code: a sentence that names the design", 0 === strpos( $text, 'Blue Tang' ) && strlen( $text ) > 30, $text );
}
check( 'the empty pile says what to do: count and Recount', false !== strpos( Admin::blocker_text( 'pile_empty', 'Blue Tang' ), 'Recount' ) );
check( 'the reserve says what to do: add, or recount', false !== strpos( Admin::blocker_text( 'pile_reserve', 'Blue Tang' ), 'Add stickers' ) && false !== strpos( Admin::blocker_text( 'pile_reserve', 'Blue Tang' ), 'recount' ) );
check( '"Any" is said plainly', false !== strpos( Admin::blocker_text( 'any_attribute', 'Blue Tang' ), 'leaves an attribute on "Any"' ) );
check( 'a variation that is switched off is told apart from an unpublished product', false !== strpos( Admin::blocker_text( 'unpublished', 'Blue Tang', true ), 'Enabled' ) && false === strpos( Admin::blocker_text( 'unpublished', 'Round Logo Sticker', false ), 'Enabled' ) );
check( 'the sentences a simple product had before are the same', 'Round Logo Sticker is not published — it will not appear in the picker.' === Admin::blocker_text( 'unpublished', 'Round Logo Sticker' ) && 'Round Logo Sticker is out of stock — it will not appear in the picker.' === Admin::blocker_text( 'out_of_stock', 'Round Logo Sticker' ) && 'Round Logo Sticker is not purchasable (another plugin is blocking it) — it will not appear in the picker.' === Admin::blocker_text( 'not_purchasable', 'Round Logo Sticker' ) && 'Round Logo Sticker has no price — give it a regular price (the picker overrides it) so WooCommerce treats it as purchasable.' === Admin::blocker_text( 'no_price', 'Round Logo Sticker' ) );
check( 'an unknown code says nothing', '' === Admin::blocker_text( 'bogus', 'x' ) );

echo "7. The theme's preset categories\n";
check( 'a theme that skips sticker lines: a sticker in "merchandise" is nothing to warn about', '' === Admin::preset_warning( 'Reef Stickers', array( 'merchandise' ), true ) );
$old = Admin::preset_warning( 'Reef Stickers', array( 'merchandise' ), false );
check( 'a theme that still counts sticker lines: warned, naming the product and the category', false !== strpos( $old, 'Reef Stickers is in the merchandise category' ) );
check( '... and told that a newer theme makes it go away, before being told to re-categorise', false !== strpos( $old, 'update the theme' ) && strpos( $old, 'update the theme' ) < strpos( $old, 'move the product' ) );
check( 'not in a preset category: no warning with either theme', '' === Admin::preset_warning( 'Reef Stickers', array(), false ) && '' === Admin::preset_warning( 'Reef Stickers', array(), true ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
