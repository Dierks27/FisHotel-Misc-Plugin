<?php
/**
 * The Packing page under FisHotel Tools — drawn headless with invented
 * bags, sizes and boxes: who may open it, the three tabs, each form with
 * its nonce and the fields its save reads, what the page says when
 * something is not available (no WooCommerce products to count, no bags
 * yet, no list of sizes, where the boxes come from), and that every name
 * a person typed is escaped.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/page-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Fill;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Size_List;

$admin = new Admin();
$nonce = wp_create_nonce( Settings::NONCE_ACTION );

/**
 * Draw a tab of the page.
 */
function fh_page( Admin $admin, $tab ) {
	$_GET = array( 'page' => Packing::MENU_SLUG, 'tab' => $tab );
	ob_start();
	try {
		$admin->render_page();
	} finally {
		$html = (string) ob_get_clean();
		$_GET = array();
	}
	return $html;
}

echo "1. Who may open it\n";
fh_login( 7, array( 'edit_products' ) );
$died = false;
try {
	fh_page( $admin, 'bags' );
} catch ( \RuntimeException $e ) {
	$died = 0 === strpos( $e->getMessage(), 'wp_die' );
}
check( 'a user who cannot manage WooCommerce is stopped', $died );
fh_login( 5, array( 'manage_woocommerce' ) );

echo "2. Nothing typed yet\n";
fh_use_sizes( Size_List::none() );
$html = fh_page( $admin, 'bags' );
check( 'the page: header, the three tabs, Bags first', false !== strpos( $html, '<h1>Packing</h1>' ) && false !== strpos( $html, 'class="fh-pack-tab is-active"' ) && 3 === preg_match_all( '/class="fh-pack-tab( is-active)?"/', $html ) && false !== strpos( $html, 'page=fishotel-misc-packing&tab=sizes' ) && false !== strpos( $html, 'page=fishotel-misc-packing&tab=boxes' ) );
check( 'no bag is filled in: only empty rows to type in', 0 === preg_match( '/name="bags\[\d+\]\[id\]"/', $html ) && 4 === preg_match_all( '/name="bags\[\d+\]\[name\]" value=""/', $html ) && false !== strpos( $html, 'nothing is filled in for you' ) );
check( 'the form posts to the bags save with the settings nonce', false !== strpos( $html, 'name="action" value="fishotel_packing_bags"' ) && false !== strpos( $html, 'name="_wpnonce" value="' . $nonce . '"' ) && false !== strpos( $html, 'data-reload="1"' ) );
check( 'a row template for "Add a bag", numbered by the script', false !== strpos( $html, 'class="fh-pack-row-template"' ) && false !== strpos( $html, 'name="bags[__i__][name]"' ) && false !== strpos( $html, 'data-next="4"' ) );
check( 'an unknown tab is the first tab', false !== strpos( fh_page( $admin, 'nonsense' ), 'fishotel_packing_bags' ) );

$html = fh_page( $admin, 'sizes' );
check( 'Sizes → bags without bags and without a list of sizes says both, plainly', false !== strpos( $html, 'No list of sizes is available' ) && false !== strpos( $html, 'There are no bag sizes yet' ) && false === strpos( $html, 'fishotel_packing_map' ) );
check( '… and, with the Clean Room not giving the sizes, does not send you there', false === strpos( $html, 'The size list lives in the Clean Room' ) );
check( 'the fill tool says why it cannot run, and offers no button', false !== strpos( $html, 'Add the bag sizes first.' ) && false === strpos( $html, 'fh-pack-fill-run' ) );

$html = fh_page( $admin, 'boxes' );
check( 'Boxes without ShipTracker says where the boxes come from: this section\'s own list', false !== strpos( $html, 'ShipTracker does not provide standard boxes on this site' ) && false !== strpos( $html, 'There are no boxes yet, so no box can be suggested.' ) );
check( 'the own list is a form of empty rows; no box is filled in', false !== strpos( $html, 'name="action" value="fishotel_packing_boxes"' ) && 3 === preg_match_all( '/name="boxes\[\d+\]\[name\]" value=""/', $html ) && false !== strpos( $html, 'name="boxes[__i__][height]"' ) );
check( 'how many fit says what is missing, and still offers the percent (default 90)', false !== strpos( $html, 'There are no bag sizes yet' ) && false !== strpos( $html, 'name="threshold" value="90"' ) && false !== strpos( $html, 'name="action" value="fishotel_packing_fits"' ) && false === strpos( $html, 'name="box_key[' ) );

echo "3. With bags, sizes and boxes\n";
$settings                = fh_test_settings();
$settings['bags'][3]     = array( 'id' => 4, 'name' => 'Jumbo <script>x</script> & co', 'width' => '', 'length' => '', 'sort' => 40 );
$settings['boxes'][]     = array( 'name' => 'Odd "box" <i>', 'length' => '9', 'width' => '9', 'height' => '9' );
$settings['size_map']    = array( 'tiny' => 1, 'mid' => 2, 'gone' => 3 );
$GLOBALS['fh_options'][ Packing::OPTION_KEY ] = $settings;
$settings                = Settings::get();
$list                    = fh_test_sizes();
$list['source']          = Size_List::SOURCE_CLEAN_ROOM;
$list['sizes'][]         = array( 'slug' => 'odd', 'label' => 'Odd <b>size</b>' );
fh_use_sizes( $list );

$html = fh_page( $admin, 'bags' );
check( 'each bag is a row with its id, name, dimensions and sort', 4 === preg_match_all( '/name="bags\[\d+\]\[id\]" value="\d+"/', $html ) && false !== strpos( $html, 'name="bags[0][name]" value="Small"' ) && false !== strpos( $html, 'name="bags[0][width]" value="4"' ) && false !== strpos( $html, 'name="bags[1][sort]" value="20"' ) && false !== strpos( $html, 'name="bags[0][remove]"' ) );
check( 'one spare row after them, and the template continues the numbering', 1 === preg_match_all( '/name="bags\[\d+\]\[name\]" value=""/', $html ) && false !== strpos( $html, 'data-next="5"' ) );
check( 'a name with markup in it is escaped (tags never survive a save anyway)', false === strpos( $html, '<script>x' ) && false !== strpos( $html, 'Jumbo x &amp; co' ) );

$html = fh_page( $admin, 'sizes' );
check( 'the map says where the sizes come from: the Clean Room\'s attribute', false !== strpos( $html, 'the list the Clean Room section uses' ) && false !== strpos( $html, '(pa_size)' ) );
check( 'one home per list: the map says the sizes are edited in the Clean Room, with a link there', false !== strpos( $html, 'The size list lives in the Clean Room' ) && false !== strpos( $html, 'page=fishotel-misc-clean-room&tab=settings#fishotel-cleanroom-sizes' ) );
check( 'one row per size, posting its slug and the chosen bag', 6 === preg_match_all( '/name="map\[\d+\]\[size\]" value="(tiny|small|mid|big|show|odd)"/', $html ) && false !== strpos( $html, 'name="action" value="fishotel_packing_map"' ) );
check( 'the bag of a size is selected; a size without one shows "no bag"', 1 === preg_match( '/name="map\[0\]\[bag\]".*?<option value="1"  selected="selected">Small \(4 × 12 in\)<\/option>/s', $html ) && 1 === preg_match( '/name="map\[4\]\[bag\]"[^>]*>\s*<option value="">— no bag —<\/option>\s*<option value="1" >/s', $html ), substr( $html, (int) strpos( $html, 'name="map[0][bag]"' ), 300 ) );
check( 'a size that left the list but is still in the map is shown, flagged, so it can be cleared', false !== strpos( $html, 'value="gone"' ) && false !== strpos( $html, 'not in the list of sizes any more' ) );
check( 'a size label is escaped', false !== strpos( $html, 'Odd &lt;b&gt;size&lt;/b&gt;' ) && false === strpos( $html, 'Odd <b>size</b>' ) );
check( 'saving the map draws the page again: whether the fill tool can run depends on the map (an empty map shows no Preview)', 1 === preg_match( '/<form class="fh-pack-form"[^>]*data-reload="1">\s*<input[^>]*_wpnonce[^>]*>\s*<input type="hidden" name="action" value="fishotel_packing_map">/', $html ), substr( $html, (int) strpos( $html, '<form' ), 300 ) );
check( 'the fill tool: Preview, a disabled "Set them", its own nonce', false !== strpos( $html, 'fh-pack-fill-preview' ) && 1 === preg_match( '/fh-pack-fill-run" disabled/', $html ) && false !== strpos( $html, 'data-nonce="' . wp_create_nonce( Fill::NONCE_ACTION ) . '"' ) );
check( 'with no WooCommerce database to count, the counts read 0 — not an error', false !== strpos( $html, 'Right now: 0 variations, 0 with a Bag size of their own.' ) );
check( 'the count sits in the element the script rewrites when a run ends', false !== strpos( $html, '<p class="fishotel-misc-card-desc fh-pack-fill-now">Right now: 0 variations, 0 with a Bag size of their own.</p>' ) );

$html = fh_page( $admin, 'boxes' );
check( 'the boxes in use, smallest first, with size and volume', 1 === preg_match( '/Cube 8.*Odd &quot;box&quot;.*Cube 12.*Long 20/s', $html ) && false !== strpos( $html, '8 × 8 × 8 in' ) && false !== strpos( $html, '>3360<' ) );
check( 'a box name with quotes and markup is escaped everywhere it shows', false === strpos( $html, 'Odd "box" <i>' ) && false !== strpos( $html, 'value="Odd &quot;box&quot;"' ) );
check( 'the own list is editable, each stored box under its own number', false !== strpos( $html, 'name="boxes[0][name]" value="Cube 8"' ) && false !== strpos( $html, 'name="boxes[2][length]" value="20"' ) && false !== strpos( $html, 'name="boxes[3][remove]"' ) && false !== strpos( $html, 'data-next="5"' ) );
check( 'each stored box says which box it is (so a rename is told from its name, never from its row); the spare row and the template say nothing', false !== strpos( $html, 'name="boxes[0][was]" value="Cube 8"' ) && false !== strpos( $html, 'name="boxes[3][was]" value="Odd &quot;box&quot;"' ) && 4 === preg_match_all( '/name="boxes\[\d+\]\[was\]"/', $html ) && false === strpos( $html, 'boxes[__i__][was]' ) );
check( 'the boxes form reloads once saved; "how many fit" below it does not', 1 === preg_match( '/data-reload="1">\s*<input[^>]*_wpnonce[^>]*>\s*<input type="hidden" name="action" value="fishotel_packing_boxes">/', $html ) && 1 === preg_match( '/<form class="fh-pack-form" method="post" action="[^"]*">\s*<input[^>]*_wpnonce[^>]*>\s*<input type="hidden" name="action" value="fishotel_packing_fits">/', $html ) );
check( 'the matrix: a row per box with its key, a column per bag, the typed numbers, blanks where a bag does not go', 4 === preg_match_all( '/name="box_key\[\d+\]" value="[^"]+"/', $html ) && 4 === preg_match_all( '/name="bag_ids\[\]" value="\d+"/', $html ) && false !== strpos( $html, 'name="fits[0][1]" value="6"' ) && false !== strpos( $html, 'name="fits[0][3]" value=""' ) && false !== strpos( $html, 'name="fits[3][4]" value="2"' ) && 16 === preg_match_all( '/name="fits\[\d+\]\[\d+\]"/', $html ), (string) preg_match_all( '/name="fits\[\d+\]\[\d+\]"/', $html ) );
check( 'the percent is the stored one', false !== strpos( $html, 'name="threshold" value="90"' ) );

echo "4. With ShipTracker providing the boxes\n";
if ( ! class_exists( 'FST_Settings' ) ) {
	/**
	 * ShipTracker's settings class, as far as this section calls it.
	 */
	class FST_Settings {

		public static function get_standard_boxes() {
			return array(
				array( 'name' => 'Shipper L', 'length' => '20', 'width' => '14', 'height' => '12', 'dims' => '20x14x12' ),
				array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8', 'dims' => '8x8x8' ),
			);
		}
	}
}
$html = fh_page( $admin, 'boxes' );
check( 'the page says the boxes come from ShipTracker and links to its settings', false !== strpos( $html, 'The boxes come from ShipTracker → Settings → Boxes &amp; label costs (2 boxes)' ) && false !== strpos( $html, 'admin.php?page=fst-settings' ) );
check( 'one home per list: this section\'s own list is hidden while ShipTracker provides the boxes', false === strpos( $html, 'This section\'s own list of boxes' ) && false === strpos( $html, 'name="boxes[0][name]"' ) && false !== strpos( $html, 'Edit the boxes in ShipTracker' ) && false !== strpos( $html, 'admin.php?page=fst-settings&tab=boxes' ) );
check( '… hidden, not deleted: the list is still saved, ready for the day ShipTracker has none', 'Cube 8' === ( Settings::get()['boxes'][0]['name'] ?? '' ) );
check( 'the matrix is ShipTracker\'s boxes; a box that took its numbers over from one of the same size says so', 2 === preg_match_all( '/name="box_key\[\d+\]" value="[^"]+"/', $html ) && false !== strpos( $html, 'value="shipper l"' ) && false !== strpos( $html, 'Carried over from a box of the same size that is no longer in the list: Shipper L.' ) && false !== strpos( $html, 'name="fits[1][4]" value="2"' ) );

echo "5. Assets\n";
$admin->init();
check( 'the page\'s assets hang on admin_enqueue_scripts only', array( 'admin_enqueue_scripts' ) === array_keys( $GLOBALS['fh_hooks'] ) );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'dashboard' );
$admin->maybe_enqueue( 'index.php' );
check( 'nothing is enqueued on another admin screen', array() === $GLOBALS['fh_enqueued'] );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'fishotel-tools_page_fishotel-misc-packing' );
$admin->maybe_enqueue( 'x' );
$admin->maybe_enqueue( 'x' );
check( 'on the Packing page: the stylesheet, the script and its strings — once', array( 'style:fishotel-packing-admin', 'script:fishotel-packing-admin', 'data:fhPacking' ) === $GLOBALS['fh_enqueued'] );
$GLOBALS['fh_enqueued'] = array();
$GLOBALS['fh_screen']   = (object) array( 'id' => 'edit-product' );
$admin->maybe_enqueue( 'edit.php' );
fh_login( 7 );
$GLOBALS['fh_screen'] = (object) array( 'id' => 'product' );
$admin->maybe_enqueue( 'post.php' );
check( 'not on the list of products, and not on the product edit screen of a user who is not shown the fields', array() === $GLOBALS['fh_enqueued'] );
fh_login( 5 );
$admin->maybe_enqueue( 'post.php' );
check( 'on the product edit screen: the same three — the script shows and hides the Bag size fields with the "Livestock — packed in a bag" checkbox', array( 'style:fishotel-packing-admin', 'script:fishotel-packing-admin', 'data:fhPacking' ) === $GLOBALS['fh_enqueued'] );
check( 'tab links', 'https://example.test/wp-admin/admin.php?page=fishotel-misc-packing' === Admin::tab_url() && 'https://example.test/wp-admin/admin.php?page=fishotel-misc-packing&tab=boxes' === Admin::tab_url( 'boxes' ) && Admin::tab_url() === Admin::tab_url( 'nope' ) );

fh_finish();
