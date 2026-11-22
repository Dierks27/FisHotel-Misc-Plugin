<?php
/**
 * Reorder: what the stock table, the Today line and the one email say,
 * what the settings page answers when a count is typed, and — with
 * stand-ins for WooCommerce's product and for wp_mail — that a stock
 * change writes the one email once and costs nothing for a product that
 * is not a design.
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/reorder-test.php
 *
 * Invented designs and numbers: a variation on a custom attribute
 * ("Night Glow", #301, its own stock), a variable product on a global
 * attribute that keeps one stock number for its variations ("Logo
 * Stickers", #400), a simple product ("Round Logo Sticker", #77).
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
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['fh_actions'][ $hook ][] = $callback;
	return true;
}
// As WordPress: an option that is already there is not added again — also when another request added it a moment ago.
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( isset( $GLOBALS['fh_options'][ $key ] ) || ! empty( $GLOBALS['fh_elsewhere'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['fh_options'][ $key ] = $value;
	$GLOBALS['fh_writes'][]        = $key;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['fh_options'][ $key ] );
	return true;
}
function wc_get_product( $id ) {
	return $GLOBALS['fh_products'][ $id ] ?? false;
}
function wp_mail( $to, $subject, $body, $headers = '' ) {
	$GLOBALS['fh_mails'][] = compact( 'to', 'subject', 'body' );
	return $GLOBALS['fh_mail_ok'];
}
function get_bloginfo( $what = '' ) {
	return 'Invented Reef Shop';
}
function wp_specialchars_decode( $text, $flags = 0 ) {
	return $text;
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function taxonomy_exists( $taxonomy ) {
	return false;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function wp_kses_post( $html ) {
	return $html;
}

/**
 * A stand-in for the product WooCommerce hands to its stock actions.
 */
class WC_Product {
	public $id;
	public $type;
	public $name;
	public $stock;
	public $attributes;
	public function __construct( $id, $type, $name, $stock, array $attributes = array() ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->name       = $name;
		$this->stock      = $stock;
		$this->attributes = $attributes;
	}
	public function get_id() {
		return $this->id;
	}
	public function is_type( $type ) {
		return $type === $this->type;
	}
	public function get_name() {
		return $this->name;
	}
	public function managing_stock() {
		return null !== $this->stock;
	}
	public function get_stock_quantity() {
		return $this->stock;
	}
	public function get_variation_attributes() {
		return $this->attributes;
	}
}

$fh_mails   = array();
$fh_writes  = array();
$fh_mail_ok = true;

require_once FISHOTEL_MISC_PATH . 'includes/class-layout.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-reorder.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-today-line.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-admin.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Admin;
use FisHotel\Misc\Sections\Sticker_Giveaway\Settings;
use FisHotel\Misc\Sections\Sticker_Giveaway\Sticker_Giveaway;
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

$night = array( 'name' => 'Night Glow', 'stock' => 40, 'reorder_at' => 40 );
$logo  = array( 'name' => 'Logo Stickers', 'stock' => 7, 'reorder_at' => 25 );
$round = array( 'name' => 'Round Logo Sticker', 'stock' => 0, 'reorder_at' => 10 );

echo "1. What is left, in a few words\n";
check( '"40 left" — the stock number, nothing estimated', '40 left' === Reorder::left_text( $night ) );
check( 'nothing: "out"', 'out' === Reorder::left_text( $round ) );

echo "2. The Today line\n";
$url  = 'https://example.test/wp-admin/admin.php?page=fishotel-misc-sticker-giveaway';
$line = Today_Line::line( array( $night ), $url );
check( 'one design: "Stickers to reorder: Night Glow (40 left)"', 'Stickers to reorder: Night Glow (40 left)' === $line['text'], $line['text'] );
check( 'it links to the Sticker Giveaway page, counts 1, is something to do', $url === $line['url'] && 1 === $line['count'] && 'todo' === $line['tone'] && 'stickers' === $line['key'] );
check( 'it stands under Customers, the heading the section itself stands under', 'customers' === $line['group'] && in_array( 'sticker-giveaway', \FisHotel\Misc\Layout::GROUPS['customers'], true ) );

$line = Today_Line::line( array( $night, $logo, $round ), $url );
check( 'three designs, each with what is left', 'Stickers to reorder: Night Glow (40 left), Logo Stickers (7 left), Round Logo Sticker (out)' === $line['text'] && 3 === $line['count'], $line['text'] );
check( 'no design due: no line', null === Today_Line::line( array(), $url ) );

$many = array();
for ( $i = 1; $i <= 9; $i++ ) {
	$many[] = array( 'name' => 'A Design With A Rather Long Name ' . $i, 'stock' => 40, 'reorder_at' => 50 );
}
$line = Today_Line::line( $many, $url );
check( 'many designs: the line stays within the Today page\'s 160 characters', strlen( $line['text'] ) <= 160, strlen( $line['text'] ) . ': ' . $line['text'] );
check( '... names the first ones and counts the rest', 0 === strpos( $line['text'], 'Stickers to reorder: A Design With A Rather Long Name 1 (40 left)' ) && (bool) preg_match( '/ and \d more$/', $line['text'] ) && 9 === $line['count'] );

$others = array( array( 'key' => 'packing', 'text' => 'Packing: 2 orders' ), array( 'key' => 'stickers', 'text' => 'an older sticker line' ) );
$with   = Today_Line::with_line( $others, Today_Line::line( array( $night ), $url ) );
check( 'added to the other sections\' lines; its own older line is replaced, never doubled', 2 === count( $with ) && 'packing' === $with[0]['key'] && 'Stickers to reorder: Night Glow (40 left)' === $with[1]['text'] );
check( 'no line leaves the others untouched', array( $others[0] ) === Today_Line::with_line( array( $others[0] ), null ) );

Today_Line::register();
check( 'it registers on the Today section\'s own filter and nothing else', array( 'fishotel_today_lines' ) === array_keys( $GLOBALS['fh_filters'] ) && 1 === count( $GLOBALS['fh_filters']['fishotel_today_lines'] ) );

Today_Line::use_source(
	static function () use ( $night ) {
		return array( 301 => $night );
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

echo "3. The one email, in words\n";
$mail = Reorder::mail( array( $night ), 'Invented Reef Shop', $url );
check( 'the subject names the site and the design', '[Invented Reef Shop] Stickers to reorder: Night Glow' === $mail['subject'], $mail['subject'] );
check( 'the body says where the stock is and what to do next', false !== strpos( $mail['body'], "Night Glow is down to 40 (reorder at 40).\n\nTime to count the stickers by hand and reorder." ), $mail['body'] );
check( 'it says where the counted number goes, and links to the settings page', false !== strpos( $mail['body'], 'under Recount on the Sticker Giveaway page' ) && false !== strpos( $mail['body'], "\n" . $url . "\n" ) );
check( 'it says there will be no second email until the stock is back above the number', false !== strpos( $mail['body'], 'You will not get another email about a design until its stock is back above its "Reorder at" number.' ) );
check( 'plain text: no markup', $mail['body'] === strip_tags( $mail['body'] ) && $mail['subject'] === strip_tags( $mail['subject'] ) );
$mail = Reorder::mail( array( $night, $round ), 'Invented Reef Shop', '' );
check( 'two designs found at a settings save: one email naming both; one at 0 is "out of stock"', '[Invented Reef Shop] Stickers to reorder: Night Glow, Round Logo Sticker' === $mail['subject'] && false !== strpos( $mail['body'], 'Round Logo Sticker is out of stock (reorder at 10).' ) );

echo "4. A stock change: the email once, and nothing for other products\n";
Reorder::register();
check( 'the look hangs on WooCommerce\'s two "stock changed" actions — every route ends there', array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) === array_keys( $GLOBALS['fh_actions'] ) );

$fh_options = array(
	Sticker_Giveaway::OPTION_KEY          => array(
		'sticker_product_ids' => array( 301, 302, 401, 402, 77 ),
		'reorder_at'          => array( 301 => 40, 400 => 25, 77 => 10 ),
	),
	'woocommerce_stock_email_recipient' => 'stock@example.test',
	'admin_email'                       => 'owner@example.test',
);
// WooCommerce changes a product's stock (one step of a request) ...
$step = static function ( $id, $type, $name, $stock, array $attributes = array() ) {
	$GLOBALS['fh_products'][ $id ] = new WC_Product( $id, $type, $name, $stock, $attributes );
	Reorder::stock_changed( $GLOBALS['fh_products'][ $id ] );
};
// ... and the request ends.
$change = static function ( $id, $type, $name, $stock, array $attributes = array() ) use ( $step ) {
	$step( $id, $type, $name, $stock, $attributes );
	Reorder::look_at_changed();
};
$sent       = static function ( $id ) {
	return isset( $GLOBALS['fh_options'][ Reorder::OPTION_SENT . $id ] );
};
$night_glow = array( 'attribute_style' => 'Night Glow' );

$step( 9001, 'simple', 'A Bag Of Salt', 3 );
$step( 302, 'variation', 'Reef Stickers - Coral Pop', 0, array( 'attribute_style' => 'Coral Pop' ) );
check( 'a product that is not a design, and a design without a "Reorder at" number: not even noted for the end of the request', ! isset( $GLOBALS['fh_actions']['shutdown'] ) && 0 === Reorder::look_at_changed() && array() === $fh_mails && array() === $fh_writes );

$change( 301, 'variation', 'Reef Stickers - Night Glow', 41, $night_glow );
check( 'Night Glow at 41 (reorder at 40): nothing yet', array() === $fh_mails && array() === $fh_writes );
check( '... the look itself waits for the end of the request', isset( $GLOBALS['fh_actions']['shutdown'] ) );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 40, $night_glow );
check( 'an order takes it to 40: one email, to WooCommerce\'s stock address', 1 === count( $fh_mails ) && 'stock@example.test' === $fh_mails[0]['to'] );
check( '... named by the variation\'s own value, not "Reef Stickers - Night Glow"', '[Invented Reef Shop] Stickers to reorder: Night Glow' === $fh_mails[0]['subject'] && 0 === strpos( $fh_mails[0]['body'], 'Night Glow is down to 40' ), $fh_mails[0]['subject'] );
check( '... and remembered in a tiny option of that count, never in the settings', $sent( 301 ) && array( Reorder::OPTION_SENT . '301' ) === $fh_writes );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 39, $night_glow );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 12, $night_glow );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 40, $night_glow );
check( 'further orders, and a recount that leaves it at 40: no second email', 1 === count( $fh_mails ) );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 200, $night_glow );
check( 'Add stickers puts it above the number: no email, and the next drop will write again', 1 === count( $fh_mails ) && ! $sent( 301 ) );

$step( 301, 'variation', 'Reef Stickers - Night Glow', 40, $night_glow );
$step( 301, 'variation', 'Reef Stickers - Night Glow', 39, $night_glow );
$step( 301, 'variation', 'Reef Stickers - Night Glow', 37, $night_glow );
check( 'one order takes it down in three steps (3 bought, the free sticker, 2 add-ons): nothing is written while WooCommerce is still counting', 1 === count( $fh_mails ) );
Reorder::look_at_changed();
check( '... and at the end ONE email, with the number the stock ended at', 2 === count( $fh_mails ) && 0 === strpos( $fh_mails[1]['body'], 'Night Glow is down to 37 (reorder at 40).' ), $fh_mails[1]['body'] ?? '' );

$change( 301, 'variation', 'Reef Stickers - Night Glow', 200, $night_glow );
$step( 301, 'variation', 'Reef Stickers - Night Glow', 40, $night_glow );
$step( 301, 'variation', 'Reef Stickers - Night Glow', 41, $night_glow );
Reorder::look_at_changed();
check( 'down to the number and back above it in one request (an order placed and cancelled): no email', 2 === count( $fh_mails ) && ! $sent( 301 ) );

$fh_elsewhere = array( Reorder::OPTION_SENT . '301' => true );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 38, $night_glow );
check( 'two orders paid in the same instant: the request that comes second to mark the count writes nothing — one email, not two', 2 === count( $fh_mails ) );
$fh_elsewhere = array();

$change( 400, 'variable', 'Logo Stickers', 25 );
$step( 301, 'variation', 'Reef Stickers - Night Glow', 38, $night_glow );
$step( 77, 'simple', 'Round Logo Sticker', null );
check( 'a product that keeps one stock number for its variations: the email names the product', 3 === count( $fh_mails ) && false !== strpos( $fh_mails[2]['subject'], 'Logo Stickers' ) && $sent( 400 ) );
Reorder::look_at_changed();
check( 'two designs dropping in one request: one email naming both; a design WooCommerce no longer tracks ("Manage stock" off): nothing', 4 === count( $fh_mails ) && '[Invented Reef Shop] Stickers to reorder: Night Glow' === $fh_mails[3]['subject'] && ! $sent( 77 ) );

$fh_mail_ok = false;
$change( 77, 'simple', 'Round Logo Sticker', 4 );
check( 'the mail could not be handed over: not remembered as sent', 5 === count( $fh_mails ) && ! $sent( 77 ) );
$fh_mail_ok = true;
$change( 77, 'simple', 'Round Logo Sticker', 4 );
check( '... so the next look tries again', 6 === count( $fh_mails ) && $sent( 77 ) );

unset( $fh_options['woocommerce_stock_email_recipient'], $fh_options[ Reorder::OPTION_SENT . '77' ] );
$change( 77, 'simple', 'Round Logo Sticker', 3 );
check( 'no stock address in WooCommerce: the site admin', 'owner@example.test' === $fh_mails[6]['to'] );

$fh_options[ Sticker_Giveaway::OPTION_KEY ]['reorder_email'] = false;
unset( $fh_options[ Reorder::OPTION_SENT . '301' ] );
$before = count( $fh_mails );
$change( 301, 'variation', 'Reef Stickers - Night Glow', 5, $night_glow );
check( '"Email me when a design needs reordering" switched off: no email, and nothing remembered as sent', count( $fh_mails ) === $before && ! $sent( 301 ) );

$fh_options[ Reorder::OPTION_SENT . '555' ] = 1;
Reorder::check( array( 'sticker_product_ids' => array() ), array( 555 ) );
check( 'a settings save after a design left the pool: the mark of its count is forgotten', ! $sent( 555 ) );
check( 'the switch is on unless it was switched off', true === Settings::DEFAULTS['reorder_email'] );

echo "5. Typing a count on the settings page\n";
check( 'a whole number is taken', '' === Admin::count_error( 'add', '100' ) && '' === Admin::count_error( 'recount', '140' ) );
check( 'Recount 0 is a count (none left)', '' === Admin::count_error( 'recount', '0' ) );
check( 'Add 0 is refused, and says to use Recount to put a count right', false !== strpos( Admin::count_error( 'add', '0' ), 'Recount' ) );
check( 'blank: asks for a number', 'Type a number first.' === Admin::count_error( 'add', '' ) && 'Type a number first.' === Admin::count_error( 'recount', '   ' ) );
check( 'a minus, a decimal, words: refused in plain words', '' !== Admin::count_error( 'add', '-5' ) && '' !== Admin::count_error( 'recount', '12.5' ) && '' !== Admin::count_error( 'recount', 'about 40' ) );
check( 'a slipped key (seven digits) is refused before it becomes the stock', '' !== Admin::count_error( 'recount', '2000000' ) && '' !== Admin::count_error( 'add', '100001' ) && '' === Admin::count_error( 'add', '100000' ) );
check( 'an unknown action is refused', '' !== Admin::count_error( 'delete', '5' ) );

check( 'after Add: what was added and what the stock now reads', 'Night Glow: 100 added. In stock now: 140.' === Admin::count_message( 'add', 'Night Glow', 100, 140 ) );
check( 'after Recount: what the stock now reads', 'Night Glow: counted. In stock now: 52.' === Admin::count_message( 'recount', 'Night Glow', 52, 52 ) );

echo "6. The stock table says what its boxes do once, not on every card\n";
$stock_rows = array();
foreach ( array( 301 => 'Night Glow', 302 => 'Coral Pop', 303 => 'Moon Jelly', 304 => 'Sea Fan' ) as $id => $name ) {
	$stock_rows[ $id ] = array(
		'id'         => $id,
		'name'       => $name,
		'designs'    => array( $id => $name ),
		'image'      => '',
		'stock'      => 120,
		'reorder_at' => 40,
		'status'     => 'ok',
	);
}
ob_start();
require FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/views/stock-table.php';
$table = ob_get_clean();
check( 'four designs: four cards, each with its three number boxes and two buttons', 4 === substr_count( $table, '<section' ) && 12 === substr_count( $table, 'type="number"' ) && 4 === substr_count( $table, 'data-act="add"' ) && 4 === substr_count( $table, 'data-act="recount"' ) );
check( '... and the explanations once above them: two short paragraphs for the whole table', 2 === substr_count( $table, 'fh-sg-hint' ) && 1 === substr_count( $table, 'Blank = no reminder' ) && 1 === substr_count( $table, 'Typed it wrong? Recount again.' ) );
$stock_rows[304]['stock'] = null;
ob_start();
require FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/views/stock-table.php';
$table = ob_get_clean();
check( 'a card keeps the word that is about that design alone: "not tracked" and what to tick', 3 === substr_count( $table, 'fh-sg-hint' ) && 1 === substr_count( $table, 'Tick &quot;Manage stock&quot; on the product page' ) );

echo "7. Why a ticked design is not offered\n";
foreach ( array( 'unpublished', 'parent_unpublished', 'no_price', 'not_purchasable', 'any_attribute', 'out_of_stock', 'type' ) as $code ) {
	$text = Admin::blocker_text( $code, 'Night Glow', true );
	check( "$code: a sentence that names the design", 0 === strpos( $text, 'Night Glow' ) && strlen( $text ) > 30, $text );
}
check( '"Any" is said plainly', false !== strpos( Admin::blocker_text( 'any_attribute', 'Night Glow' ), 'leaves an attribute on "Any"' ) );
check( 'a variation that is switched off is told apart from an unpublished product', false !== strpos( Admin::blocker_text( 'unpublished', 'Night Glow', true ), 'Enabled' ) && false === strpos( Admin::blocker_text( 'unpublished', 'Round Logo Sticker', false ), 'Enabled' ) );
check( 'the sentences a simple product had before are the same', 'Round Logo Sticker is not published — it will not appear in the picker.' === Admin::blocker_text( 'unpublished', 'Round Logo Sticker' ) && 'Round Logo Sticker is out of stock — it will not appear in the picker.' === Admin::blocker_text( 'out_of_stock', 'Round Logo Sticker' ) && 'Round Logo Sticker is not purchasable (another plugin is blocking it) — it will not appear in the picker.' === Admin::blocker_text( 'not_purchasable', 'Round Logo Sticker' ) && 'Round Logo Sticker has no price — give it a regular price (the picker overrides it) so WooCommerce treats it as purchasable.' === Admin::blocker_text( 'no_price', 'Round Logo Sticker' ) );
check( 'an unknown code says nothing', '' === Admin::blocker_text( 'bogus', 'x' ) );

echo "8. The theme's preset categories\n";
check( 'a theme that skips sticker lines: a sticker in "merchandise" is nothing to warn about', '' === Admin::preset_warning( 'Reef Stickers', array( 'merchandise' ), true ) );
$old = Admin::preset_warning( 'Reef Stickers', array( 'merchandise' ), false );
check( 'a theme that still counts sticker lines: warned, naming the product and the category', false !== strpos( $old, 'Reef Stickers is in the merchandise category' ) );
check( '... and told that a newer theme makes it go away, before being told to re-categorise', false !== strpos( $old, 'update the theme' ) && strpos( $old, 'update the theme' ) < strpos( $old, 'move the product' ) );
check( 'not in a preset category: no warning with either theme', '' === Admin::preset_warning( 'Reef Stickers', array(), false ) && '' === Admin::preset_warning( 'Reef Stickers', array(), true ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
