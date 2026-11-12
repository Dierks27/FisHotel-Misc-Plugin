<?php
/**
 * The Packing settings — the one option and its four saves, on invented
 * bags and boxes: nothing is seeded; a bag keeps its id (renamed, never
 * reused); the own box list and a rename that carries "how many fit"
 * along; how many fit (blank and 0 = does not go; anything else that is
 * not a whole number refused); the "on the line" percent; the size → bag
 * map; what a corrupted option reads as; and for every save the
 * capability, the nonce, and "one invalid value and nothing is saved".
 *
 * Run from the plugin root:  php includes/sections/packing/tests/settings-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Boxes;
use FisHotel\Misc\Sections\Packing\Packing;
use FisHotel\Misc\Sections\Packing\Settings;

$nonce = wp_create_nonce( Settings::NONCE_ACTION );

echo "1. Nothing is seeded\n";
$empty = Settings::get();
check( 'no bags, no boxes, no numbers, no map until the owner types them', array() === $empty['bags'] && array() === $empty['boxes'] && array() === $empty['fits'] && array() === $empty['size_map'] && 0 === $empty['last_bag_id'] );
check( 'the "on the line" percent starts at 90', 90 === $empty['threshold'] && 90 === Settings::THRESHOLD_DEFAULT );
check( 'reading the settings writes nothing', array() === $GLOBALS['fh_option_writes'] );

echo "2. Who may save, and with what\n";
$post = array(
	'_wpnonce' => $nonce,
	'bags'     => array( array( 'name' => 'Small' ) ),
);
fh_login( 0 );
$out = Settings::bags_request( $post );
check( 'nobody logged in: 403, nothing stored', 403 === $out['status'] && false === $out['ok'] && array() === $GLOBALS['fh_option_writes'] );
fh_login( 7, array( 'edit_products' ) );
$out = Settings::bags_request( $post );
check( 'a user who can edit products but not manage WooCommerce: 403', 403 === $out['status'] && array() === $GLOBALS['fh_option_writes'] );
fh_login( 5, array( 'manage_woocommerce' ) );
$out = Settings::bags_request( array( 'bags' => array( array( 'name' => 'Small' ) ) ) );
check( 'no nonce: 403', 403 === $out['status'] && array() === $GLOBALS['fh_option_writes'] );
$out = Settings::bags_request( array( '_wpnonce' => wp_create_nonce( 'something-else' ) ) + $post );
check( 'the nonce of another form: 403', 403 === $out['status'] );
$out = Settings::bags_request( array( '_wpnonce' => array( $nonce ) ) + $post );
check( 'a nonce that is not text: 403', 403 === $out['status'] && array() === $GLOBALS['fh_option_writes'] );
foreach ( array( 'boxes_request', 'fits_request', 'map_request' ) as $method ) {
	fh_login( 7 );
	$denied = call_user_func( array( Settings::class, $method ), array( '_wpnonce' => $nonce ), fh_test_sizes() );
	fh_login( 5 );
	$stale = call_user_func( array( Settings::class, $method ), array( '_wpnonce' => 'nope' ), fh_test_sizes() );
	check( $method . '(): the same two refusals', 403 === $denied['status'] && 403 === $stale['status'] && array() === $GLOBALS['fh_option_writes'] );
}

echo "3. Bag sizes\n";
fh_login( 5 );
$out = Settings::bags_request(
	array(
		'_wpnonce' => $nonce,
		'bags'     => array(
			array( 'name' => ' Small ', 'width' => '4', 'length' => '12.50', 'sort' => '' ),
			array( 'name' => 'Large', 'width' => '', 'length' => '', 'sort' => '5' ),
			array( 'name' => '', 'width' => '', 'length' => '', 'sort' => '' ),
			array( 'name' => "Medium\t<b>bag</b>", 'width' => '6', 'length' => '', 'sort' => '' ),
		),
	)
);
$bags = Settings::get()['bags'];
check( 'three bags saved; the empty spare row is not a bag', 200 === $out['status'] && 3 === count( $bags ) && false !== strpos( $out['message'], '3 bags' ), $out['message'] );
check( 'each gets the next id; the list is ordered by the sort number', array( 2, 1, 3 ) === array_column( $bags, 'id' ) && array( 'Large', 'Small', 'Medium bag' ) === array_column( $bags, 'name' ), fh_test_export( array_column( $bags, 'name' ) ) );
check( 'a blank sort is the row\'s place times ten; dimensions are optional and tidied', array( 5, 10, 40 ) === array_column( $bags, 'sort' ) && '12.5' === $bags[1]['length'] && '4' === $bags[1]['width'] && '' === $bags[0]['width'] && '' === $bags[2]['length'] );
check( 'the option is stored without autoload', array( Packing::OPTION_KEY, false ) === end( $GLOBALS['fh_option_writes'] ) );
check( 'the highest id ever given is remembered', 3 === Settings::get()['last_bag_id'] );

$out  = Settings::bags_request(
	array(
		'_wpnonce' => $nonce,
		'bags'     => array(
			array( 'id' => '1', 'name' => 'Small bag', 'width' => '4', 'length' => '12.5', 'sort' => '10' ),
			array( 'id' => '3', 'name' => 'Medium bag', 'remove' => '1' ),
			array( 'name' => 'Jumbo', 'sort' => '90' ),
		),
	),
	new Fh_Fake_Store()
);
$bags = Settings::get()['bags'];
check( 'a renamed bag keeps its id', 'Small bag' === $bags[1]['name'] && 1 === $bags[1]['id'] );
check( 'a bag the form did not post is left alone (a stale page never deletes)', 2 === $bags[0]['id'] && 'Large' === $bags[0]['name'] );
check( 'a removed bag is gone; a new one never takes its id', array( 2, 1, 4 ) === array_column( $bags, 'id' ) && 4 === Settings::get()['last_bag_id'] && false !== strpos( $out['message'], '"Medium bag" was removed' ), $out['message'] );

fh_product( new Fh_Fake_Product( 901, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '4' ), 900 ) );
fh_product( new Fh_Fake_Product( 902, 'variation', array( 'pa_size' => 'mid' ), array( '_fh_bag_size' => '4' ), 900 ) );
$out = Settings::bags_request( array( '_wpnonce' => $nonce, 'bags' => array( array( 'id' => '4', 'remove' => '1' ) ) ), new Fh_Fake_Store() );
check( 'removing a bag that products name says how many still name it', 200 === $out['status'] && false !== strpos( $out['message'], '2 products or variations still name it' ), $out['message'] );
check( 'the products themselves are not touched', '4' === wc_get_product( 901 )->meta['_fh_bag_size'] && 0 === wc_get_product( 901 )->meta_saves );
fh_reset_shop();

$before = Settings::get();
$writes = count( $GLOBALS['fh_option_writes'] );
$bad    = array(
	'a bag without a name'       => array( array( 'id' => '1', 'name' => '' ) ),
	'a width that is not inches' => array( array( 'name' => 'Odd', 'width' => 'wide' ) ),
	'a negative length'          => array( array( 'name' => 'Odd', 'length' => '-3' ) ),
	'a length of zero'           => array( array( 'name' => 'Odd', 'length' => '0' ) ),
	'a sort that is not whole'   => array( array( 'name' => 'Odd', 'sort' => '1.5' ) ),
	'two bags of the same name'  => array( array( 'name' => 'large' ) ),
);
foreach ( $bad as $label => $rows ) {
	$out = Settings::bags_request( array( '_wpnonce' => $nonce, 'bags' => array_merge( array( array( 'name' => 'Fine', 'width' => '5' ) ), $rows ) ) );
	check( $label . ': 400, and the valid row beside it is not saved either', 400 === $out['status'] && 0 === strpos( $out['message'], 'Nothing was saved' ) && $before === Settings::get() && count( $GLOBALS['fh_option_writes'] ) === $writes, $out['message'] );
}
$many = array();
for ( $i = 0; $i < Settings::MAX_BAGS + 1; $i++ ) {
	$many[] = array( 'name' => 'Bag ' . $i );
}
$out = Settings::bags_request( array( '_wpnonce' => $nonce, 'bags' => $many ) );
check( 'more than ' . Settings::MAX_BAGS . ' bags: refused', 400 === $out['status'] && $before === Settings::get() );
$out = Settings::bags_request( array( '_wpnonce' => $nonce, 'bags' => 'junk' ) );
check( 'a form with no rows changes nothing', 200 === $out['status'] && $before['bags'] === Settings::get()['bags'] );

echo "4. The field sanitisers\n";
check( 'a name: tags, control characters and backslashes dropped, whitespace collapsed, cut to ' . Settings::MAX_NAME, 'A b c' === Settings::name_in( " A \x07 b\\ <i>c</i> " ) && Settings::MAX_NAME === strlen( Settings::name_in( str_repeat( 'x', 90 ) ) ) && '' === Settings::name_in( array( 'x' ) ) && '' === Settings::name_in( true ) );
check( 'a dimension: blank stays blank, a number is tidied, anything else is null', '' === Settings::dim_in( '' ) && '' === Settings::dim_in( null ) && '6' === Settings::dim_in( '6.00' ) && '6.25' === Settings::dim_in( ' 6.250 ' ) && null === Settings::dim_in( 'six' ) && null === Settings::dim_in( '0' ) && null === Settings::dim_in( '1000' ) && null === Settings::dim_in( array( 6 ) ) );
check( 'how many fit: blank and 0 mean "does not go", a whole number from 1 to ' . Settings::MAX_FIT . ' counts, the rest is null', 0 === Settings::fit_in( '' ) && 0 === Settings::fit_in( null ) && 0 === Settings::fit_in( '0' ) && 12 === Settings::fit_in( ' 12 ' ) && Settings::MAX_FIT === Settings::fit_in( (string) Settings::MAX_FIT ) && null === Settings::fit_in( (string) ( Settings::MAX_FIT + 1 ) ) && null === Settings::fit_in( '2.5' ) && null === Settings::fit_in( '-1' ) && null === Settings::fit_in( 'lots' ) && null === Settings::fit_in( array( 3 ) ) );
check( 'the percent: a whole number from 1 to 100', 90 === Settings::threshold_in( '90' ) && 1 === Settings::threshold_in( 1 ) && 100 === Settings::threshold_in( '100' ) && null === Settings::threshold_in( '0' ) && null === Settings::threshold_in( '101' ) && null === Settings::threshold_in( '89.5' ) && null === Settings::threshold_in( '' ) && null === Settings::threshold_in( null ) );

echo "5. This section's own boxes\n";
$out   = Settings::boxes_request(
	array(
		'_wpnonce' => $nonce,
		'boxes'    => array(
			array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8.0', 'height' => '8' ),
			array( 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12.5' ),
			array( 'name' => '', 'length' => '', 'width' => '', 'height' => '' ),
		),
	)
);
$boxes = Settings::get()['boxes'];
check( 'two boxes saved, sizes tidied, the spare row skipped', 200 === $out['status'] && 2 === count( $boxes ) && array( 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12.5' ) === $boxes[1] && '8' === $boxes[0]['width'], fh_test_export( $boxes ) );
$current = Boxes::current( Settings::get(), null, false );
check( 'they are the boxes in use (ShipTracker provides none), smallest first, each with its size and volume', Boxes::SOURCE_OWN === $current['source'] && array( 'cube 8', 'long 20' ) === array_column( $current['boxes'], 'key' ) && '20x14x12.5' === $current['boxes'][1]['dims'] && 3500.0 === $current['boxes'][1]['volume'] );

$before = Settings::get();
$bad    = array(
	'a box without a name'     => array( 'name' => '', 'length' => '5', 'width' => '5', 'height' => '5' ),
	'a box without a height'   => array( 'name' => 'Flat', 'length' => '5', 'width' => '5', 'height' => '' ),
	'a size of zero'           => array( 'name' => 'Flat', 'length' => '5', 'width' => '0', 'height' => '5' ),
	'a size that is not a number' => array( 'name' => 'Flat', 'length' => 'long', 'width' => '5', 'height' => '5' ),
	'a second box of the name' => array( 'name' => 'cube 8', 'length' => '9', 'width' => '9', 'height' => '9' ),
);
foreach ( $bad as $label => $row ) {
	$out = Settings::boxes_request( array( '_wpnonce' => $nonce, 'boxes' => array( $before['boxes'][0], $before['boxes'][1], $row ) ) );
	check( $label . ': 400, nothing saved', 400 === $out['status'] && $before === Settings::get(), $out['message'] );
}

echo "6. How many fit, and the percent\n";
$current = Boxes::current( Settings::get(), null, false );
$out     = Settings::fits_request(
	array(
		'_wpnonce'  => $nonce,
		'box_key'   => array( 'cube 8', 'long 20' ),
		'bag_ids'   => array( '2', '1' ),
		'fits'      => array(
			array( 1 => '6', 2 => '' ),
			array( 1 => '30', 2 => '10' ),
		),
		'threshold' => '85',
	)
);
$fits    = Settings::get()['fits'];
check( 'the numbers are stored per box, with the box\'s name and size', 200 === $out['status'] && array( 1 => 6 ) === $fits['cube 8']['bags'] && array( 2 => 10, 1 => 30 ) === $fits['long 20']['bags'] && 'Long 20' === $fits['long 20']['name'] && '20x14x12.5' === $fits['long 20']['dims'], fh_test_export( $fits ) );
check( 'a blank cell is no entry at all: that bag does not go in that box', ! isset( $fits['cube 8']['bags'][2] ) );
check( 'the percent is saved with them', 85 === Settings::get()['threshold'] && false !== strpos( $out['message'], '85%' ) );

$out  = Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'cube 8' ), 'bag_ids' => array( '1', '2' ), 'fits' => array( array( 1 => '0', 2 => '3' ) ) ) );
$fits = Settings::get()['fits'];
check( 'a typed 0 clears the cell; a box the form did not post keeps its numbers; the percent is left alone when not posted', array( 2 => 3 ) === $fits['cube 8']['bags'] && array( 2 => 10, 1 => 30 ) === $fits['long 20']['bags'] && 85 === Settings::get()['threshold'], fh_test_export( $fits ) );
$out  = Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'long 20' ), 'bag_ids' => array( '1' ), 'fits' => array( array( 1 => '28' ) ) ) );
$fits = Settings::get()['fits'];
check( 'a bag column the form never had (a bag added since) keeps its number', array( 2 => 10, 1 => 28 ) === $fits['long 20']['bags'], fh_test_export( $fits['long 20']['bags'] ) );
$out  = Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'cube 8' ), 'bag_ids' => array( '1', '2' ), 'fits' => array( array( 1 => '', 2 => '' ) ) ) );
check( 'a box with every cell blank has no entry', ! isset( Settings::get()['fits']['cube 8'] ) );

$before = Settings::get();
$bad    = array(
	'half a bag'             => array( 'fits' => array( array( 1 => '2.5' ) ) ),
	'a negative number'      => array( 'fits' => array( array( 1 => '-4' ) ) ),
	'a word'                 => array( 'fits' => array( array( 1 => 'plenty' ) ) ),
	'more than the maximum'  => array( 'fits' => array( array( 1 => '10000' ) ) ),
	'a percent of zero'      => array( 'fits' => array( array( 1 => '5' ) ), 'threshold' => '0' ),
	'a percent over 100'     => array( 'fits' => array( array( 1 => '5' ) ), 'threshold' => '120' ),
	'a percent with a point' => array( 'fits' => array( array( 1 => '5' ) ), 'threshold' => '92.5' ),
);
foreach ( $bad as $label => $extra ) {
	$out = Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'cube 8' ), 'bag_ids' => array( '1', '2' ) ) + $extra );
	check( $label . ': 400, nothing saved — not even the valid cells', 400 === $out['status'] && $before === Settings::get(), $out['message'] );
}
$out = Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'no such box' ), 'bag_ids' => array( '1' ), 'fits' => array( array( 1 => '9' ) ) ) );
check( 'numbers for a box that is not in the list are not stored', 200 === $out['status'] && $before['fits'] === Settings::get()['fits'] );

echo "7. A renamed box keeps its numbers\n";
$out  = Settings::boxes_request(
	array(
		'_wpnonce' => $nonce,
		'boxes'    => array(
			array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ),
			array( 'name' => 'Long box', 'length' => '20', 'width' => '14', 'height' => '12.5' ),
		),
	)
);
$fits = Settings::get()['fits'];
check( 'the own list: a row that comes back under its number with another name was renamed — the numbers follow', 200 === $out['status'] && ! isset( $fits['long 20'] ) && array( 2 => 10, 1 => 28 ) === $fits['long box']['bags'] && 'Long box' === $fits['long box']['name'], fh_test_export( $fits ) );

$settings = Settings::get();
$renamed  = Boxes::rows( array( array( 'name' => 'Shipper L', 'length' => '20', 'width' => '14', 'height' => '12.5' ), array( 'name' => 'Brand new', 'length' => '9', 'width' => '9', 'height' => '9' ) ), Boxes::SOURCE_SHIPTRACKER );
$with     = Boxes::with_fits( $renamed, $settings['fits'] );
check( 'a box under a new name with exactly the same size takes over the numbers of the one that left the list', array( 2 => 10, 1 => 28 ) === $with[1]['fits'] && 'long box' === $with[1]['fits_from'] && 'Shipper L' === $with[1]['name'] );
check( 'a box of another size starts empty', array() === $with[0]['fits'] && '' === $with[0]['fits_from'] );
$both = Boxes::with_fits( Boxes::rows( array( array( 'name' => 'Long box', 'length' => '20', 'width' => '14', 'height' => '12.5' ), array( 'name' => 'Twin', 'length' => '20', 'width' => '14', 'height' => '12.5' ) ), Boxes::SOURCE_OWN ), $settings['fits'] );
check( 'numbers that belong to a box still in the list are never taken over by another', array() === $both[1]['fits'] && 'long box' === $both[0]['fits_from'] );
$saved = Settings::apply_fits( $settings, $renamed, array( 'box_key' => array( 'brand new', 'shipper l' ), 'bag_ids' => array( '1', '2' ), 'fits' => array( array( 1 => '4' ), array( 1 => '28', 2 => '10' ) ) ) );
check( 'saving them stores them under the new name and drops the old entry', array() === $saved['errors'] && ! isset( $saved['settings']['fits']['long box'] ) && array( 2 => 10, 1 => 28 ) === $saved['settings']['fits']['shipper l']['bags'] && 'Shipper L' === $saved['settings']['fits']['shipper l']['name'] && array( 1 => 4 ) === $saved['settings']['fits']['brand new']['bags'], fh_test_export( $saved['settings']['fits'] ) );

echo "8. The size → bag map\n";
$sizes = fh_test_sizes();
$out   = Settings::map_request(
	array(
		'_wpnonce' => $nonce,
		'map'      => array(
			array( 'size' => 'tiny', 'bag' => '1' ),
			array( 'size' => 'mid', 'bag' => '2' ),
			array( 'size' => 'big', 'bag' => '' ),
		),
	),
	$sizes
);
check( 'each size gets the bag chosen for it; "no bag" is no entry', 200 === $out['status'] && array( 'tiny' => 1, 'mid' => 2 ) === Settings::get()['size_map'] && false !== strpos( $out['message'], '2 sizes' ), $out['message'] );
$out = Settings::map_request( array( '_wpnonce' => $nonce, 'map' => array( array( 'size' => 'mid', 'bag' => '' ) ) ), $sizes );
check( 'choosing "no bag" removes the entry; sizes the form did not post stay', array( 'tiny' => 1 ) === Settings::get()['size_map'] );
$before = Settings::get();
$out    = Settings::map_request( array( '_wpnonce' => $nonce, 'map' => array( array( 'size' => 'mid', 'bag' => '2' ), array( 'size' => 'big', 'bag' => '99' ) ) ), $sizes );
check( 'a bag that is not in the list: 400, nothing saved', 400 === $out['status'] && $before === Settings::get(), $out['message'] );
$out = Settings::map_request( array( '_wpnonce' => $nonce, 'map' => array( array( 'size' => 'made-up', 'bag' => '1' ) ) ), $sizes );
check( 'a size that is not in the list: 400, nothing saved', 400 === $out['status'] && $before === Settings::get(), $out['message'] );
$out = Settings::map_request( array( '_wpnonce' => $nonce, 'map' => array( array( 'size' => 'tiny', 'bag' => '2' ) ) ), array( 'sizes' => array() ) );
check( 'a size that left the list but is still in the map can be changed (or cleared)', 200 === $out['status'] && array( 'tiny' => 2 ) === Settings::get()['size_map'] );

echo "9. Removing a bag drops what pointed at it\n";
Settings::fits_request( array( '_wpnonce' => $nonce, 'box_key' => array( 'cube 8' ), 'bag_ids' => array( '1', '2' ), 'fits' => array( array( 1 => '6', 2 => '3' ) ) ) );
Settings::bags_request( array( '_wpnonce' => $nonce, 'bags' => array( array( 'id' => '2', 'remove' => '1' ) ) ) );
$after = Settings::get();
check( 'its "how many fit" numbers and its sizes in the map go with it', array( 1 => 6 ) === $after['fits']['cube 8']['bags'] && array() === $after['size_map'] && array( 1 => 28 ) === $after['fits']['long box']['bags'], fh_test_export( $after['fits'] ) );

echo "10. Whatever the option holds, what is read is safe\n";
$GLOBALS['fh_options'][ Packing::OPTION_KEY ] = 'not an array';
check( 'a broken option reads as nothing typed', Settings::defaults() === Settings::get() );
$GLOBALS['fh_options'][ Packing::OPTION_KEY ] = array(
	'bags'        => array( 'x', array( 'id' => 0, 'name' => 'No id' ), array( 'id' => 3, 'name' => '' ), array( 'id' => 9, 'name' => 'Nine', 'width' => 'wide', 'sort' => 'last' ), array( 'id' => 9, 'name' => 'Nine again' ) ),
	'last_bag_id' => 'many',
	'boxes'       => array( array( 'name' => 'No size' ), array( 'name' => 'Ok', 'length' => 5, 'width' => 5, 'height' => 5 ), 7 ),
	'fits'        => array( 'Ok' => array( 'name' => 'Ok', 'dims' => 'five cubed', 'bags' => array( 9 => '4', 8 => 2, 'x' => 1 ) ), 'empty' => array( 'bags' => array() ), 'bad' => 'x' ),
	'size_map'    => array( 'mid' => 9, 'big' => 8, '' => 9 ),
	'threshold'   => 400,
);
$read = Settings::get();
check( 'only what is a bag stays; ids are unique; a bad width is blank', array( array( 'id' => 9, 'name' => 'Nine', 'width' => '', 'length' => '', 'sort' => 0 ) ) === $read['bags'] && 9 === $read['last_bag_id'], fh_test_export( $read['bags'] ) );
check( 'only what is a box stays', array( array( 'name' => 'Ok', 'length' => '5', 'width' => '5', 'height' => '5' ) ) === $read['boxes'] );
check( 'numbers for unknown bags and entries with none are dropped; a bad size is blank; the key is the lower-cased name', array( 'ok' => array( 'name' => 'Ok', 'dims' => '', 'bags' => array( 9 => 4 ) ) ) === $read['fits'], fh_test_export( $read['fits'] ) );
check( 'the map keeps only known bags; an impossible percent is 90', array( 'mid' => 9 ) === $read['size_map'] && 90 === $read['threshold'] );

echo "11. The AJAX handlers only send the answer\n";
$GLOBALS['fh_options'] = array();
$handlers              = new Settings();
$_POST                 = array( '_wpnonce' => $nonce, 'boxes' => array( array( 'name' => 'Cube 8', 'length' => '8', 'width' => '8', 'height' => '8' ) ) );
try {
	$handlers->ajax_boxes();
	$reply = null;
} catch ( Fh_Test_Json $reply ) {
	$replied = true; // The reply is what the handler would have sent.
}
check( 'a good save answers success with the message — and never sends the settings back', $reply instanceof Fh_Test_Json && true === $reply->success && isset( $reply->data['message'] ) && ! isset( $reply->data['settings'] ) );
$_POST = array( '_wpnonce' => 'stale' );
try {
	$handlers->ajax_fits();
	$reply = null;
} catch ( Fh_Test_Json $reply ) {
	$replied = true; // The reply is what the handler would have sent.
}
check( 'a refused save answers an error with its status', $reply instanceof Fh_Test_Json && false === $reply->success && 403 === $reply->status );
$_POST = array();

fh_reset_hooks();
$handlers->init();
check( 'the four saves are admin AJAX actions (there is no nopriv twin)', array( 'wp_ajax_fishotel_packing_bags', 'wp_ajax_fishotel_packing_boxes', 'wp_ajax_fishotel_packing_fits', 'wp_ajax_fishotel_packing_map' ) === array_keys( $GLOBALS['fh_hooks'] ) );

fh_finish();
