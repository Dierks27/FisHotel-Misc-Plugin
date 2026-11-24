<?php
/**
 * Product checks — the rules, by the numbers, on invented products:
 * who is a live fish, who gets the table; each flag; the old-style
 * detector (labels, HTML descriptions, a field filled or not, the site's
 * default text); what is stored (a clean product stores nothing); the
 * chips; a stand-in photo.
 *
 * Run from the plugin root:  php includes/sections/product-checks/tests/rules-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Product_Checks\Rules;

$live = array( 'quarantined-fish', 'inverts' );

/**
 * A complete invented fish: every check passes.
 *
 * @param array $over What to change.
 * @return array
 */
function fh_fish( array $over = array() ) {
	return array_merge(
		array(
			'status'          => 'publish',
			'cats'            => array( 'quarantined-fish' ),
			'under_table_cat' => false,
			'packs'           => '',
			'thumb'           => 12,
			'thumb_standin'   => false,
			'gallery'         => '13,14',
			'text'            => 'An invented tang that eats algae.',
			'excerpt'         => 'Fishus inventus',
			'facts'           => array(
				'scientific_name' => 'Fishus inventus',
				'common_names'    => 'Invented Tang',
				'max_length'      => '6 in',
				'min_tank_size'   => '75 gal',
				'temperament'     => 'Peaceful',
				'reef_safe'       => 'yes',
				'foods_feeding'   => 'Algae',
				'habitat'         => 'Reef',
				'region'          => '',
			),
			'defaults'        => array( 'foods_feeding' => 'Feed a varied diet.', 'habitat' => 'Lives on reefs.' ),
			'old_style'       => null,
			'price_missing'   => array(),
			'bag_missing'     => array(),
		),
		$over
	);
}

echo "1. Who is checked\n";
check( 'a complete fish has nothing to fix — and stores nothing', array() === Rules::flags( fh_fish(), $live ) && array( '_fh_todo_photo' => null, '_fh_todo_table' => null, '_fh_todo_oldstyle' => null, '_fh_todo_bag' => null, '_fh_todo_price' => null, '_fh_todo_sci' => null, '_fh_todo_gallery' => null, '_fh_todo_subcat' => null, '_fh_todo' => null, '_fh_todo_v' => null ) === Rules::meta( array() ) );
check( 'live: a fish category of its own, or Livestock ticked', Rules::is_live( fh_fish(), $live ) && Rules::is_live( fh_fish( array( 'cats' => array( 'inverts' ) ) ), $live ) && Rules::is_live( fh_fish( array( 'cats' => array( 'corals' ), 'packs' => 'yes' ) ), $live ) );
check( 'not live: a dry good, an invoice (even ticked), the trash, an archived product', ! Rules::is_live( fh_fish( array( 'cats' => array( 'salt' ) ) ), $live ) && ! Rules::is_live( fh_fish( array( 'cats' => array( 'invoices', 'quarantined-fish' ), 'packs' => 'yes' ) ), $live ) && ! Rules::is_live( fh_fish( array( 'status' => 'trash' ) ), $live ) && ! Rules::is_live( fh_fish( array( 'status' => 'fishotel_archived' ) ), $live ) );
check( 'drafts, pending, scheduled and private fish are live', Rules::is_live( fh_fish( array( 'status' => 'draft' ) ), $live ) && Rules::is_live( fh_fish( array( 'status' => 'future' ) ), $live ) && Rules::is_live( fh_fish( array( 'status' => 'private' ) ), $live ) );
check( 'corals ticked in the setting: a coral is live', Rules::is_live( fh_fish( array( 'cats' => array( 'corals' ) ) ), array_merge( $live, array( 'corals' ) ) ) && ! Rules::is_live( fh_fish( array( 'cats' => array( 'corals' ) ) ), $live ) );
check( 'the table: Quarantined Fish only — an invert gets none (the owner\'s answer)', Rules::gets_table( fh_fish() ) && ! Rules::gets_table( fh_fish( array( 'cats' => array( 'inverts' ) ) ) ) );
$invert = fh_fish( array( 'cats' => array( 'inverts' ), 'facts' => array(), 'excerpt' => '' ) );
check_same( 'an invert with no facts at all: no table, no scientific name flag — only photo, price, bag are its checks', array(), Rules::flags( $invert, $live ) );

echo "2. Each flag\n";
check_same( 'no featured image', array( 'photo' => 'none' ), Rules::flags( fh_fish( array( 'thumb' => 0 ) ), $live ) );
check_same( 'an image that is gone counts as none', array( 'photo' => 'none' ), Rules::flags( fh_fish( array( 'thumb' => -1 ) ), $live ) );
check_same( 'a stand-in', array( 'photo' => 'standin' ), Rules::flags( fh_fish( array( 'thumb_standin' => true ) ), $live ) );
check_same( 'no gallery', array( 'gallery' => '1' ), Rules::flags( fh_fish( array( 'gallery' => '' ) ), $live ) );
check( 'a dry good with no photo is not counted', array() === Rules::flags( fh_fish( array( 'cats' => array( 'salt' ), 'thumb' => 0, 'gallery' => '' ) ), $live ) );
$facts                   = fh_fish()['facts'];
$facts['max_length']     = '';
$facts['reef_safe']      = 'maybe';
$facts['foods_feeding']  = ' feed a  varied diet. ';
check_same( 'the table: how many of the 8 are missing — reef safe must be yes / no / caution, the site\'s default text does not count', array( 'table' => '3' ), Rules::flags( fh_fish( array( 'facts' => $facts ) ), $live ) );
$facts                    = fh_fish()['facts'];
$facts['region']          = '';
check( 'region is not required', array() === Rules::flags( fh_fish( array( 'facts' => $facts ) ), $live ) );
check_same( 'the scientific name missing under the name', array( 'sci' => '1' ), Rules::flags( fh_fish( array( 'excerpt' => '' ) ), $live ) );
check_same( '— or different', array( 'sci' => '1' ), Rules::flags( fh_fish( array( 'excerpt' => 'Fishus other' ) ), $live ) );
check( '— markup and capitals are no difference', array() === Rules::flags( fh_fish( array( 'excerpt' => '<em>FISHUS  inventus</em>' ) ), $live ) );
$facts                    = fh_fish()['facts'];
$facts['scientific_name'] = '';
check_same( '— missing in the table: both flags', array( 'table' => '1', 'sci' => '1' ), Rules::flags( fh_fish( array( 'facts' => $facts ) ), $live ) );
check_same( 'in stock with no price — a simple product', array( 'price' => '1' ), Rules::flags( fh_fish( array( 'price_missing' => array( '' ) ) ), $live ) );
check_same( '— the sizes of a variable one', array( 'price' => 'Large, Small' ), Rules::flags( fh_fish( array( 'price_missing' => array( 'Large', 'Small', 'Large' ) ) ), $live ) );
check( '— published or private only; any product (a dry good too)', array() === Rules::flags( fh_fish( array( 'status' => 'draft', 'price_missing' => array( '' ) ) ), $live ) && array( 'price' => '1' ) === Rules::flags( fh_fish( array( 'cats' => array( 'salt' ), 'price_missing' => array( '' ) ) ), $live ) );
check_same( 'no bag size for an in-stock size', array( 'bag' => 'Small' ), Rules::flags( fh_fish( array( 'bag_missing' => array( 'Small' ) ) ), $live ) );
check( '— not when Livestock is unticked, nor while the Packing section is off (nothing to ask)', array() === Rules::flags( fh_fish( array( 'packs' => 'no', 'bag_missing' => array( 'Small' ) ) ), $live ) && array() === Rules::flags( fh_fish( array( 'bag_missing' => null ) ), $live ) );
check_same( 'filed only under a sub-category of Quarantined Fish', array( 'photo' => 'none', 'gallery' => '1', 'subcat' => '1' ), Rules::flags( fh_fish( array( 'cats' => array( 'tangs' ), 'under_table_cat' => true, 'thumb' => 0, 'gallery' => '', 'packs' => 'yes' ) ), $live ) );
check( '— in both: not flagged', ! isset( Rules::flags( fh_fish( array( 'cats' => array( 'quarantined-fish', 'tangs' ), 'under_table_cat' => true ) ), $live )['subcat'] ) );
$all = Rules::flags( fh_fish( array( 'thumb' => 0, 'gallery' => '', 'excerpt' => '', 'price_missing' => array( '' ), 'bag_missing' => array( '' ), 'old_style' => true, 'facts' => array() ) ), $live );
check_same( 'the flags come in the order they are shown', array( 'photo', 'table', 'oldstyle', 'bag', 'price', 'sci', 'gallery' ), array_keys( $all ) );
$meta = Rules::meta( $all );
check( 'stored: one key per flag, how many, the rule version', '8' === $meta['_fh_todo_table'] && '7' === $meta['_fh_todo'] && (string) Rules::VERSION === $meta['_fh_todo_v'] && null === $meta['_fh_todo_subcat'] );

echo "3. The old style\n";
$old = "Scientific Name: Fishus inventus\nMaximum Length: 6 inches\nReef Safety: Yes\nFoods and Feeding Habits: Algae\nan invented line";
$no  = array();
check_same( 'the labels at the start of a line, each once', array( 'scientific_name', 'max_length', 'reef_safe', 'foods_feeding' ), Rules::labels( $old . "\nScientific Name: again" ) );
check( 'two labels with an empty field: old style', true === Rules::old_style( $old, $no ) );
check( 'one label is not enough', false === Rules::old_style( 'Temperament: Peaceful', $no ) );
check( 'every found field filled: harmless, not flagged', false === Rules::old_style( $old, array( 'scientific_name' => 'x', 'max_length' => 'x', 'reef_safe' => 'yes', 'foods_feeding' => 'x' ) ) );
check( '— a reef safe of "maybe" is not filled', true === Rules::old_style( $old, array( 'scientific_name' => 'x', 'max_length' => 'x', 'reef_safe' => 'maybe', 'foods_feeding' => 'x' ) ) );
check( 'a description stored as paragraphs, with bullets, dashes, entities and no-break spaces', true === Rules::old_style( '<p>&bull; Common&nbsp;Names – Invented Tang</p><p><strong>Min. Tank Size</strong>: 75&nbsp;gal</p>', $no ) );
check( 'a label inside a sentence is not a label', false === Rules::old_style( "This fish has a scientific name: Fishus.\nIts temperament: calm", $no ) );
check( 'Fun Facts counts as evidence, not as a field', array( 'temperament', '' ) === Rules::labels( "Temperament: calm\nFun Facts: it hums" ) && true === Rules::old_style( "Temperament: calm\nFun Facts: it hums", $no ) && false === Rules::old_style( "Temperament: calm\nFun Facts: it hums", array( 'temperament' => 'calm' ) ) );
check( 'Habitat & Behaviour, Region, Max Length, Reef-Safe spellings', array( 'habitat', 'region', 'max_length', 'reef_safe' ) === Rules::labels( "Habitat & Behaviour: reefs\nRegion - Indo-Pacific\nMax Length: 4\nReef-Safe: no" ) );
check( 'a text that is not UTF-8, or not text, is read without a warning and gives no labels', array() === Rules::labels( "Temperament: \xFF\nRegion: x\xFE" ) && array() === Rules::labels( array( 'Temperament: x' ) ) && array() === Rules::labels( null ) );
check( 'the product\'s own answer (the theme\'s, when it is there) wins over the pattern', array() === Rules::flags( fh_fish( array( 'text' => $old, 'old_style' => false, 'facts' => array_merge( fh_fish()['facts'] ) ) ), $live ) && array( 'oldstyle' => '1' ) === Rules::flags( fh_fish( array( 'old_style' => true ) ), $live ) );
$f                   = fh_fish()['facts'];
$f['foods_feeding']  = '';
check_same( 'worked out here when there is no answer: the description and the short description read together', array( 'table' => '1', 'oldstyle' => '1' ), Rules::flags( fh_fish( array( 'text' => "Foods and Feeding: algae\nTemperament: calm", 'facts' => $f ) ), $live ) );

echo "4. The chips\n";
check_same(
	'each flag in words, pointing at its box',
	array( 'Stand-in photo', 'Table 5/8', 'Old style', 'Bag: Small', 'Price', 'Scientific name', 'Gallery', 'Sub-category only' ),
	array_column( Rules::chips( array( 'photo' => 'standin', 'table' => '3', 'oldstyle' => '1', 'bag' => 'Small', 'price' => '1', 'sci' => '1', 'gallery' => '1', 'subcat' => '1' ) ), 'label' )
);
check_same( 'the boxes', array( 'postimagediv', 'fishotel_hotel_data', 'woocommerce-product-data', 'fishotel-essentials' ), array_column( Rules::chips( array( 'photo' => 'none', 'table' => '8', 'price' => 'Large', 'sci' => '1' ) ), 'anchor' ) );
check( '"Photo" when there is none, "Price: Large" for a size, "Bag size" for a simple product', array( 'Photo', 'Bag size', 'Price: Large' ) === array_column( Rules::chips( array( 'photo' => 'none', 'price' => 'Large', 'bag' => '1' ) ), 'label' ) );
check( 'an empty or unknown value is no chip', array() === Rules::chips( array( 'photo' => '', 'other' => '1' ) ) );

echo "5. A stand-in photo\n";
check( 'one of the placeholder libraries\' images, or WooCommerce\'s', true === Rules::standin( 5, array( 5, 6 ), array(), 'fish.jpg' ) );
check( 'the same image featured on 3 or more products', true === Rules::standin( 9, array(), array( 9 ), 'fish.jpg' ) );
check( 'a file named so', true === Rules::standin( 7, array(), array(), '/up/2026/placeholder-blue.png' ) && true === Rules::standin( 7, array(), array(), 'Coming_Soon.jpg' ) && true === Rules::standin( 7, array(), array(), 'no-image.png' ) );
check( 'a real photo is not', false === Rules::standin( 7, array( 5 ), array( 9 ), '/up/2026/yellow-tang.jpg' ) && false === Rules::standin( 0, array( 0 ), array(), 'placeholder.png' ) );

fh_finish();
