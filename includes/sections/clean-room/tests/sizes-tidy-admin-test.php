<?php
/**
 * Tidy up the sizes — the tool on Settings → Sizes (Admin_Sizes_Tidy),
 * on the invented shop of tidy-fixture.php: who may use it (an owner,
 * the tool's own nonce, WooCommerce active — else it is not there), the
 * owner's choices read from a posted form, the dry run as the page shows
 * it (and that showing writes nothing), Apply behind "I have a backup",
 * the typed word and the preview's token, the steps, a second click,
 * Undo, the "Sizes to fill in" list, the name a past order line keeps —
 * and that nothing runs on its own: no cron, no activation hook, nothing
 * on an upgrade, and the record's tables are made by Apply only.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/sizes-tidy-admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-sizes-tidy-run.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/clean-room/class-admin-sizes-tidy.php';
require_once __DIR__ . '/fake-tidy-shop.php';
require_once __DIR__ . '/tidy-fixture.php';

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Admin_Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function selected( $a, $b = true, $echo = true ) {
		$out = (string) $a === (string) $b ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
	function checked( $a, $b = true, $echo = true ) {
		$out = (string) $a === (string) $b ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
	function disabled( $a, $b = true, $echo = true ) {
		$out = (string) $a === (string) $b ? ' disabled="disabled"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}

/**
 * The size attribute, as the section reads it.
 */
function fh_tidy_store() {
	$store                  = new Fake_Store();
	$store->size_attributes = array( array( 'taxonomy' => 'pa_size', 'name' => 'size', 'label' => 'Size', 'orderby' => 'menu_order' ) );
	$store->size_terms      = array( 'pa_size' => array( array( 'term_id' => 1, 'name' => 'Small', 'slug' => 'small', 'menu_order' => 0 ) ) );
	return $store;
}

/**
 * One click on the tool: the real wp_ajax handler, up to its reply.
 */
function fh_tidy_click( array $post, $nonce = null ) {
	$_POST    = $post;
	$_REQUEST = $post + array( 'nonce' => null === $nonce ? wp_create_nonce( Admin_Sizes_Tidy::NONCE ) : $nonce );
	try {
		( new Admin_Sizes_Tidy() )->ajax();
	} catch ( Fh_Test_Json $reply ) {
		return $reply;
	}
	return null;
}

$owner   = 7;
$manager = 8;
$GLOBALS['fh_user_caps']   = array( 'manage_options' => array( $owner ), 'manage_woocommerce' => array( $owner, $manager ) );
$GLOBALS['fh_user_logins'] = array( $owner => 'owner', $manager => 'manager' );
$GLOBALS['fh_current_user'] = $owner;

$shop = fh_tidy_shop();
$ids  = fh_tidy_ids( $shop );
$zero = $shop->dump();
Admin_Sizes_Tidy::use_shop( $shop, fh_tidy_store() );

echo "1. Who may use it\n";
$reply = fh_tidy_click( array( 'op' => 'preview' ), 'not-the-nonce' );
check_same( 'a wrong nonce: refused before anything is read', array( false, 403 ), array( $reply->success, $reply->status ) );
$reply = fh_tidy_click( array( 'op' => 'preview' ), wp_create_nonce( Settings::NONCE_ACTION ) );
check_same( 'the Settings tab\'s nonce is not the tool\'s', array( false, 403 ), array( $reply->success, $reply->status ) );
$GLOBALS['fh_current_user'] = $manager;
$reply = fh_tidy_click( array( 'op' => 'preview' ) );
check_same( 'a shop manager (no manage_options): refused', array( false, 403 ), array( $reply->success, $reply->status ) );
foreach ( array( 'apply', 'step', 'undo', 'dismiss', 'status' ) as $op ) {
	$reply = fh_tidy_click( array( 'op' => $op, 'run' => 1, 'product' => $ids['Flame Hawk'], 'backup' => '1', 'confirm' => 'TIDY' ) );
	check( "…for $op too", false === $reply->success && 403 === $reply->status );
}
ob_start();
Admin_Sizes_Tidy::render( fh_tidy_store(), Settings::get() );
check_same( '…and the card is not printed for them', '', ob_get_clean() );
check( '…the tool is not "allowed"', false === Admin_Sizes_Tidy::allowed() );
$GLOBALS['fh_current_user'] = $owner;
$shop->available            = false;
$reply                      = fh_tidy_click( array( 'op' => 'preview' ) );
check_same( 'WooCommerce is not active: refused, in plain words', array( false, 400, true ), array( $reply->success, $reply->status, false !== strpos( $reply->data['message'], 'WooCommerce is not active' ) ) );
ob_start();
Admin_Sizes_Tidy::render( fh_tidy_store(), Settings::get() );
check_same( '…and the card is absent', '', ob_get_clean() );
$shop->available = true;
check( 'an owner, with WooCommerce: allowed', true === Admin_Sizes_Tidy::allowed() );
check( 'nothing was written by any of that', 0 === $shop->writes && $zero === $shop->dump() && array() === $shop->runs );
Admin_Sizes_Tidy::use_shop( $shop, new Fake_Store() );
$reply = fh_tidy_click( array( 'op' => 'preview' ) );
check_same( 'a shop without a size attribute: nothing to tidy', array( false, true ), array( $reply->success, false !== strpos( $reply->data['message'], 'nothing to tidy' ) ) );
ob_start();
Admin_Sizes_Tidy::render( new Fake_Store(), Settings::get() );
$card = ob_get_clean();
check( '…the card says so and offers no form', false !== strpos( $card, 'so there is nothing to tidy' ) && false === strpos( $card, 'fh-tidy__preview' ) );
Admin_Sizes_Tidy::use_shop( $shop, fh_tidy_store() );
$reply = fh_tidy_click( array( 'op' => 'explode' ) );
check_same( 'an op the tool does not have: refused', array( false, 400 ), array( $reply->success, $reply->status ) );

echo "2. The owner's choices, from a posted form\n";
$request = Admin_Sizes_Tidy::request(
	array(
		'short'     => " 1-2\"\r\n<1\"\n\nSmall\n1-2\"",
		'over'      => array( 'g:3-5' => 'to:4-5', 'g:x' => array( 'y' ), '' => 'keep', "g:\x07tiny" => " keep\n" ),
		'keep_plus' => '1',
		'instock'   => 'move',
		'move'      => array( '1012' => 'medium', 'abc' => 'large', '1013' => '' ),
		'keep_one'  => array( '1005' => array( 'ab12' => '1007', 'cd' => '0' ), 'x' => array( 'ab' => '5' ), '1006' => 'junk' ),
		'convert'   => array( '1020' => '1', '1021' => '0', 'x' => '1' ),
		'junk'      => 'ignored',
	)
);
check_same( 'the short list: clean lines, the inch mark and "<" kept, repeats dropped', array( '1-2"', '<1"', 'Small' ), $request['short'] );
check_same( 'the rows set by hand: text only, control characters dropped', array( 'g:3-5' => 'to:4-5', 'g: tiny' => 'keep' ), $request['over'] );
check_same( 'the options', array( true, 'move' ), array( $request['keep_plus'], $request['instock'] ) );
check_same( 'a size per fish: numbered variations with a pick', array( 1012 => 'medium' ), $request['move'] );
check_same( 'the one to keep: numbered products and variations', array( 1005 => array( 'ab12' => 1007 ) ), $request['keep_one'] );
check_same( 'the fish ticked for converting', array( 1020 => 1 ), $request['convert'] );
check_same( 'an empty form is the default list and no choices', array( array(), array(), false, '', array(), array(), array() ), array_values( Admin_Sizes_Tidy::request( array() ) ) );

echo "3. Show what would change (the dry run)\n";
$reply = fh_tidy_click( array( 'op' => 'preview', 'short' => '' ) );
$html  = (string) $reply->data['html'];
check( 'the dry run answers', true === $reply->success && '' !== $reply->data['token'] && false === $reply->data['nothing'] );
check( 'showing writes nothing', 0 === $shop->writes && $zero === $shop->dump() && array() === $shop->runs );
check_contains( 'the totals, with the real numbers', '31 sizes → 13 · 5 variations renamed · 3 switched off · 3 fish for sale left as they are · 1 product needs a look · 0 deleted', $html );
check_contains( 'the short list\'s real length is shown, not a fixed one', 'The shop has 31 sizes today; the short list has 10.', $html );
check_contains( 'a row with its dropdown, pre-filled by the rule', 'data-key="g:3-5" data-shown="to:3-4"', $html );
check( 'a size of the short list has no dropdown: it stays', 1 === preg_match( '/<th scope="row">\s*Medium\s*<\/th>.*?stays — it is in the short list/s', $html ) && false === strpos( $html, 'data-key="g:medium"' ) );
check_contains( 'an in-between word starts on "take off the products"', 'data-key="g:medium-large" data-shown="remove"', $html );
check_contains( 'a row counts in plain words', '1 product · 1 variation', $html );
check_contains( 'an "and up" size is marked quietly', 'was open-ended (“and up”)', $html );
check_contains( 'where else a size is stored', '2 stays · 1 supplier order line · 1 fish request · 2 lost-fish cases · 1 draft invoice line · 1 box share · 14 past order lines', $html );
check_contains( 'the fish for sale today, counted', '3 fish for sale today carry a size that is being taken off', $html );
check( '…named, with their stock, and the three choices — "leave" first and ticked', false !== strpos( $html, 'Golden Wrasse' ) && false !== strpos( $html, '2 in stock' ) && 1 === preg_match( '/name="instock" value="leave"\s+checked="checked"/', $html ) && false !== strpos( $html, 'Move them to a size I pick' ) && false !== strpos( $html, 'Take them off the shop now' ) );
check_contains( 'the out-of-stock ones are taken off now', '3 variations that nobody can buy anyway', $html );
check_contains( 'Clean', '5 variations only change their size name', $html );
check_contains( 'Two become one', '1 product needs a look', $html );
check( '…with the choice of the one to keep: its price, stock and SKU', false !== strpos( $html, 'Keep the one that is 3-5&quot; today — 35.00 · 1 in stock · SKU CB-35' ) && false !== strpos( $html, 'Leave this fish alone for now' ) );
check_contains( 'Typed on the product', '2 fish have their sizes typed on the product', $html );
check( '…a tick per fish; one that cannot be converted says why', 1 === preg_match( '/name="convert\[' . $ids['Koi Angel'] . '\]" value="1"\s+>/', $html ) && false !== strpos( $html, 'cannot be converted yet' ) );
check_contains( 'a fish left with nothing to buy is listed', '1 fish will show as unavailable until you add a size', $html );
check( '…each product links to its edit screen', false !== strpos( $html, 'post.php?post=' . $ids['Ivory Puffer'] . '&amp;action=edit' ) || false !== strpos( $html, 'post.php?post=' . $ids['Ivory Puffer'] . '&action=edit' ) );
check_contains( 'the Packing clash is named', 'already has the bag "bag-m"', str_replace( '&quot;', '"', $html ) );
check_contains( 'it says what is not touched', 'Past orders are not touched: an order line keeps the size text it was bought with.', $html );
check_contains( 'the Apply box: a backup tick and a word to type', 'I have a backup of the site', $html );
check_contains( '…the word', 'Type ' . Admin_Sizes_Tidy::CONFIRM_WORD . ' to go ahead', $html );
check( 'nothing in the page is unescaped: a size named <1" reads as text', false !== strpos( $html, '&lt;1&quot;' ) && false === strpos( $html, '<1"' ) );
$moved = fh_tidy_click( array( 'op' => 'preview', 'instock' => 'move' ) );
check( '"move": a dropdown per fish, pre-filled with nothing', 1 === preg_match( '/name="move\[' . $shop->vids( $ids['Golden Wrasse'] )[0] . '\]">\s*<option value="">— leave as it is —<\/option>/', (string) $moved->data['html'] ) && false === strpos( (string) $moved->data['html'], 'selected="selected">Medium</option>
								</select>' ) );
check( '…and moving no fish is the same plan as leaving them', $moved->data['token'] === $reply->data['token'] );
$kept = fh_tidy_click( array( 'op' => 'preview', 'keep_plus' => '1' ) );
check( '"keep every and-up size": those rows show "keep as it is"', false !== strpos( (string) $kept->data['html'], 'data-key="g:3" data-shown="keep"' ) && $kept->data['token'] !== $reply->data['token'] );
$own = fh_tidy_click( array( 'op' => 'preview', 'over' => array( 'g:3-5' => 'to:4-5' ) ) );
check( 'a row set by hand is shown as set, and remembered as the owner\'s', false !== strpos( (string) $own->data['html'], 'data-key="g:3-5" data-shown="to:4-5" data-yours="1"' ) && false !== strpos( (string) $own->data['html'], 'your choice' ) );

echo "4. Apply\n";
$token = $reply->data['token'];
$try   = fh_tidy_click( array( 'op' => 'apply', 'token' => $token, 'confirm' => 'TIDY' ) );
check_same( 'without "I have a backup": refused', array( false, true ), array( $try->success, false !== strpos( $try->data['message'], 'backup' ) ) );
$try = fh_tidy_click( array( 'op' => 'apply', 'token' => $token, 'backup' => '1', 'confirm' => 'yes' ) );
check_same( 'without the typed word: refused', array( false, true ), array( $try->success, false !== strpos( $try->data['message'], 'TIDY' ) ) );
$try = fh_tidy_click( array( 'op' => 'apply', 'token' => 'an-old-token', 'backup' => '1', 'confirm' => 'TIDY' ) );
check_same( 'with a token that is not this plan\'s: refused — preview again', array( false, 409, true ), array( $try->success, $try->status, ! empty( $try->data['stale'] ) ) );
$try = fh_tidy_click( array( 'op' => 'apply', 'token' => $token, 'backup' => '1', 'confirm' => 'TIDY', 'instock' => 'off' ) );
check_same( 'with choices that differ from the ones previewed: refused the same way', array( false, 409 ), array( $try->success, $try->status ) );
check( 'none of those wrote anything or saved a record', 0 === $shop->writes && $zero === $shop->dump() && array() === $shop->runs );
$go = fh_tidy_click( array( 'op' => 'apply', 'token' => $token, 'backup' => '1', 'confirm' => ' tidy ' ) );
check( 'the backup tick, the word (any case) and the token: the run starts — its record saved, the shop not yet touched', true === $go->success && $go->data['run'] > 0 && 1 === count( $shop->runs ) && 'owner' === $shop->runs[ $go->data['run'] ]['created_by'] && 0 === $shop->writes && $zero === $shop->dump() );
$twice = fh_tidy_click( array( 'op' => 'apply', 'token' => $token, 'backup' => '1', 'confirm' => 'TIDY' ) );
check_same( 'Apply clicked again: the same run, no second one', array( true, $go->data['run'], 1 ), array( $twice->success, $twice->data['run'], count( $shop->runs ) ) );
$first = fh_tidy_click( array( 'op' => 'step', 'run' => $go->data['run'] ) );
check( 'the first step only records how things are: nothing is written yet, and it says how far it is', true === $first->success && false === $first->data['over'] && 0 === $shop->writes && $zero === $shop->dump() && 1 === preg_match( '/^(Saving the record|Tidying) — \d+ of \d+/u', $first->data['message'] ) );
$messages = array();
for ( $i = 0; $i < 500; $i++ ) {
	$step       = fh_tidy_click( array( 'op' => 'step', 'run' => $go->data['run'] ) );
	$messages[] = $step->data['message'];
	if ( $step->data['over'] ) {
		break;
	}
}
check( 'the steps run it to the end, saying how far it is', true === $step->data['over'] && 'done' === $step->data['progress']['status'] && $i >= 1 && false !== strpos( implode( '|', $messages ), 'Tidying — ' ) && 'Done. The sizes are tidied.' === $step->data['message'] );
check_same( 'the shop is tidied', 13, count( $shop->terms ) );
$status = (string) $step->data['status'];
check_contains( 'the status panel comes back with it: Undo', 'Undo the last tidy-up', $status );
check_contains( '…the sizes on their way out, with the fish left', 'retiring — 2 fish left for sale', $status );
check_contains( '…a size waiting for a product', 'waiting for a product that needs a look', $status );
check( '…and the "Sizes to fill in" list, each fish linked', false !== strpos( $status, 'Sizes to fill in' ) && false !== strpos( $status, 'Flame Hawk' ) && false !== strpos( $status, 'post.php?post=' . $ids['Ivory Puffer'] ) && false !== strpos( $status, 'Small-Medium' ) );
$after = $shop->dump();
$again = fh_tidy_click( array( 'op' => 'preview' ) );
check( 'the same choices again: nothing to tidy, and no Apply box', true === $again->data['nothing'] && false !== strpos( (string) $again->data['html'], 'There is nothing to tidy with these choices.' ) && false === strpos( (string) $again->data['html'], 'fh-tidy__apply-button' ) );
$try = fh_tidy_click( array( 'op' => 'apply', 'token' => $again->data['token'], 'backup' => '1', 'confirm' => 'TIDY' ) );
check( 'Apply on it: refused, nothing changed', false === $try->success && $after === $shop->dump() && 1 === count( $shop->runs ) );
ob_start();
Admin_Sizes_Tidy::render( fh_tidy_store(), Settings::get() );
$card = ob_get_clean();
check( 'the card: the short list as it was saved, the buttons, the status', false !== strpos( $card, 'id="fh-sizes-tidy"' ) && false !== strpos( $card, 'name="short"' ) && false !== strpos( $card, "1-2&quot;\n2-3&quot;" ) && false !== strpos( $card, 'Show what would change' ) && false !== strpos( $card, 'Undo the last tidy-up' ) );

echo "5. The \"Sizes to fill in\" list, and a past order's size\n";
$tick = fh_tidy_click( array( 'op' => 'dismiss', 'product' => $ids['Opal Jawfish'] ) );
check( 'a fish ticked off leaves the list', true === $tick->success && false === strpos( (string) $tick->data['status'], 'Opal Jawfish' ) && false !== strpos( (string) $tick->data['status'], 'Flame Hawk' ) );
$line = (object) array( 'key' => 'pa_size', 'value' => 'small-medium' );
check_same( 'a past order line whose size was removed still reads as the size it was bought with', 'Small-Medium', Sizes::order_size_name( 'small-medium', $line ) );
check_same( '…with its inch mark, escaped as WooCommerce would have it', '2-3.5&quot;', Sizes::order_size_name( '2-3-5', (object) array( 'key' => 'pa_size', 'value' => '2-3-5' ) ) );
check_same( 'a size that is still there, another attribute, or a line without a size: left to WooCommerce', array( 'Medium', 'small-medium', 'x' ), array( Sizes::order_size_name( 'Medium', (object) array( 'key' => 'pa_size', 'value' => 'medium' ) ), Sizes::order_size_name( 'small-medium', (object) array( 'key' => 'pa_colour', 'value' => 'small-medium' ) ), Sizes::order_size_name( 'x', null ) ) );

echo "6. Undo\n";
$undo = fh_tidy_click( array( 'op' => 'undo' ) );
check( 'Undo starts', true === $undo->success && $undo->data['run'] === $go->data['run'] );
for ( $i = 0; $i < 500; $i++ ) {
	$step = fh_tidy_click( array( 'op' => 'step', 'run' => $undo->data['run'] ) );
	if ( $step->data['over'] ) {
		break;
	}
}
$shop->products[ $ids['Opal Jawfish'] ]['fill'] = array(); // Ticked off by hand above, as it was before the run.
check( 'the steps put everything back exactly', 'undone' === $step->data['progress']['status'] && $zero === $shop->dump() && array() === $shop->runs );
check_same( '…and say so', 'Done. Everything that tidy-up changed is back as it was.', $step->data['message'] );
check( '…the status panel has nothing left to undo or fill in', false === strpos( (string) $step->data['status'], 'Undo the last tidy-up' ) && false === strpos( (string) $step->data['status'], 'Sizes to fill in' ) );
$none = fh_tidy_click( array( 'op' => 'undo' ) );
check_same( 'Undo with nothing to undo: refused', array( false, 'There is no tidy-up to undo.' ), array( $none->success, $none->data['message'] ) );
check_same( 'a past order line reads as WooCommerce shows it again', '3-5&quot;', Sizes::order_size_name( '3-5&quot;', (object) array( 'key' => 'pa_size', 'value' => '3-5' ) ) );
$gone = fh_tidy_click( array( 'op' => 'step', 'run' => 4242 ) );
check( 'a step for a run that is not there is over at once', true === $gone->success && true === $gone->data['over'] && 'gone' === $gone->data['progress']['status'] );
Admin_Sizes_Tidy::use_shop( null );

echo "7. Nothing runs on its own\n";
$path  = Clean_Room::path();
$files = array( 'class-sizes-tidy.php', 'class-sizes-tidy-run.php', 'class-sizes-tidy-site.php', 'class-admin-sizes-tidy.php', 'views/sizes-tidy.php', 'views/sizes-tidy-plan.php', 'views/sizes-tidy-status.php', 'js/sizes-tidy.js' );
$code  = '';
foreach ( $files as $file ) {
	check( $file . ' is there', is_file( $path . $file ) );
	// Comments say what the tool does not do; only the code is searched.
	$source = (string) file_get_contents( $path . $file );
	$code  .= '.php' === substr( $file, -4 ) ? implode( '', array_map( static function ( $token ) { return is_array( $token ) ? ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $token[1] ) : $token; }, token_get_all( $source ) ) ) : $source;
}
foreach ( array( 'cron' => '/wp_schedule_|wp_cron|as_schedule_|as_enqueue_|wp_next_scheduled/i', 'an activation or upgrade hook' => '/register_activation_hook|register_deactivation_hook|upgrader_process_complete|plugins_loaded|activated_plugin/i', 'a front-end, init or shutdown hook' => "/add_action\(\s*'(init|wp|wp_loaded|shutdown|admin_init|template_redirect|woocommerce_[a-z_]+)'/", 'unserialize or eval' => '/\bunserialize\s*\(|\beval\s*\(/' ) as $what => $pattern ) {
	check( 'the tool has no ' . $what, 0 === preg_match( $pattern, $code ) );
}
$hooks = array();
preg_match_all( "/add_(?:action|filter)\(\s*([^,]+),/", (string) file_get_contents( $path . 'class-admin-sizes-tidy.php' ), $hooks );
check_same( 'its only hooks: its own AJAX action (logged-in users only) and the admin assets', array( "'wp_ajax_' . self::ACTION", "'admin_enqueue_scripts'" ), array_map( 'trim', $hooks[1] ) );
$site = (string) file_get_contents( $path . 'class-sizes-tidy-site.php' );
check_same( 'the record\'s tables are created in one place, called from one place: when a run is saved', array( 2, 1 ), array( substr_count( $site, 'CREATE TABLE IF NOT EXISTS' ), substr_count( $site, '$this->make_record()' ) ) );
check( '…and that place is run_create()', 1 === preg_match( '/function run_create\(.*?\{\s*global \$wpdb;\s*if \( ! \$this->make_record\(\) \)/s', $site ) );
$main = (string) file_get_contents( $path . 'class-clean-room.php' );
check( 'the section\'s activation callback does not know the tool', 1 === preg_match( '/public static function activate\(\) \{(.*?)\n\t\t\}/s', $main, $m ) && false === stripos( $m[1], 'tidy' ) );
check( 'the section\'s table installer does not know the record', false === strpos( (string) file_get_contents( $path . 'class-store.php' ), 'sizes_tidy' ) );
check( 'the tool is wired in wp-admin only', 1 === preg_match( '/if \( is_admin\(\) \) \{.*?new Admin_Sizes_Tidy\(\);.*?\n\t\t\t\}/s', $main ) && 1 === substr_count( $main, 'new Admin_Sizes_Tidy()' ) );
check( 'its state is never autoloaded', false !== strpos( $site, 'Sizes::STATE_OPTION === (string) $name ? false : null' ) );

fh_finish();
