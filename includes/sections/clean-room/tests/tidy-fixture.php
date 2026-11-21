<?php
/**
 * The invented shop the "Tidy up the sizes" tests share: the 31 sizes
 * the real shop grew to, a dozen fish that between them show every case
 * the tool has to handle, and a row or two in each other place that
 * stores a size. Names, prices and stock are made up.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

use FisHotel\Misc\Sections\Clean_Room\Box_Shares;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Tidy_Shop;

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

/**
 * The shop.
 *
 * @return Fake_Tidy_Shop
 */
function fh_tidy_shop() {
	$shop = new Fake_Tidy_Shop();
	$shop->add_terms(
		array(
			'lt-1'         => '<1"',
			'1-2'          => '1-2"',
			'1-2-5'        => '1-2.5"',
			'1-3'          => '1-3"',
			'2'            => '2"+',
			'2-3'          => '2-3"',
			'2-3-5'        => '2-3.5"',
			'2-4'          => '2-4"',
			'2-5-4'        => '2.5-4"',
			'3'            => '3"+',
			'3-4'          => '3-4"',
			'3-5'          => '3-5"',
			'3-5-2'        => '3.5"+',
			'4'            => '4"+',
			'4-5'          => '4-5"',
			'4-6'          => '4-6"',
			'5'            => '5"+',
			'5-6'          => '5-6"',
			'5-7'          => '5-7"',
			'6'            => '6"+',
			'7'            => '7"+',
			'all'          => 'All',
			'large'        => 'Large',
			'large-xl'     => 'Large-XL',
			'medium'       => 'Medium',
			'medium-large' => 'Medium-Large',
			'pair'         => 'Pair',
			'small'        => 'Small',
			'small-medium' => 'Small-Medium',
			'tiny'         => 'Tiny',
			'xl'           => 'XL',
		)
	);

	$shop->add_product( 'Azure Damsel', array( 'small' => array( '9.99', 5 ), 'medium' => array( '14.99', 0 ), 'large' => array( '19.99', 2 ) ) );
	$shop->add_product( 'Banded Goby', array( '1-2' => array( '12.00', 3 ), '2-3-5' => array( '18.00', 1 ) ), array( 'default' => '2-3-5' ) );
	$shop->add_product( 'Coral Beauty', array( '3-4' => array( '30.00', 2, 'sku' => 'CB-34' ), '3-5' => array( '35.00', 1, 'sku' => 'CB-35' ) ) );
	$shop->add_product( 'Dwarf Lion', array( '3' => array( '40.00', 1 ), '4-6' => array( '55.00', 0 ) ) );
	$shop->add_product( 'Flame Hawk', array( 'small-medium' => array( '22.00', 0 ), 'medium' => array( '25.00', 4 ) ) );
	$shop->add_product( 'Golden Wrasse', array( 'medium-large' => array( '45.00', 2 ), 'large' => array( '50.00', 1 ) ) );
	$shop->add_product( 'Harlequin Tusk', array( 'large-xl' => array( '120.00', 1 ) ) );
	$shop->add_product( 'Ivory Puffer', array( 'large-xl' => array( '90.00', 0 ) ) );
	$shop->add_product(
		'Jade Blenny',
		array(
			'a' => array( '16.00', 2, 'attrs' => array( 'size' => '2-3"' ) ),
			'b' => array( '28.00', 1, 'attrs' => array( 'size' => 'About 5 inches' ) ),
			'c' => array( '40.00', 1, 'attrs' => array( 'size' => 'Jumbo-ish' ) ),
		),
		array( 'typed' => array( 'key' => 'size', 'name' => 'Size', 'options' => array( '2-3"', 'About 5 inches', 'Jumbo-ish' ), 'default' => '' ) )
	);
	$shop->add_product(
		'Koi Angel',
		array(
			'a' => array( '33.00', 2, 'attrs' => array( 'size' => '1-2"' ) ),
			'b' => array( '44.00', 0, 'attrs' => array( 'size' => '2 - 3.5"' ) ),
		),
		array( 'typed' => array( 'key' => 'size', 'name' => 'Size', 'options' => array( '1-2"', '2 - 3.5"' ), 'default' => '1-2"' ) )
	);
	$shop->add_product( 'Lemon Tang', array( '2-3' => array( '20.00', 3 ), '2-4' => array( '20.00', 3, 'status' => 'private' ) ) );
	$shop->add_product(
		'Mandarin Pair',
		array(
			'a' => array( '70.00', 1, 'size' => '3-5', 'attrs' => array( 'pa_size' => '3-5', 'pa_sex' => 'male' ) ),
			'b' => array( '70.00', 1, 'size' => '3-4', 'attrs' => array( 'pa_size' => '3-4', 'pa_sex' => 'female' ) ),
		)
	);
	$shop->add_product( 'Neon Dottyback', array( 'large-xl' => array( '60.00', -1 ) ) );
	$shop->add_product( 'Opal Jawfish', array( 'small-medium' => array( '15.00', 4 ) ), array( 'status' => 'draft' ) );
	$shop->add_product( 'Percula Pair', array( 'pair' => array( '80.00', 1 ) ) );
	$shop->add_product( 'Feeding Ring', array( 'small' => array( '4.00', null ), 'large' => array( '6.00', null ) ), array( 'fish' => false ) );

	$shop->orders = array(
		'3-5'          => 14,
		'small-medium' => 30,
		'medium'       => 99,
	);

	$shop->add_row( 'stays', array( 'species' => 'Coral Beauty', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'stays', array( 'species' => 'Coral Beauty', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'stays', array( 'species' => 'Flame Hawk', 'size_slug' => 'small-medium', 'size_label' => 'Small-Medium' ) );
	$shop->add_row( 'stays', array( 'species' => 'Flame Hawk', 'size_slug' => 'medium', 'size_label' => 'Medium' ) );
	$shop->add_row( 'stays', array( 'species' => 'Azure Damsel', 'size_slug' => null, 'size_label' => null ) );

	$loc = Box_Shares::location_key( 'Reef Co', 'Bali' );
	$old = Box_Shares::share_key( $loc, 'id:5', '3-5' );
	$shop->add_row( 'order_lines', array( 'order_id' => 1, 'species' => 'Coral Beauty', 'size_slug' => '3-5', 'size_label' => '3-5"', 'share_key' => $old ) );
	$shop->add_row( 'order_lines', array( 'order_id' => 1, 'species' => 'Flame Hawk', 'size_slug' => 'medium', 'size_label' => 'Medium', 'share_key' => null ) );
	$shop->add_row( 'requests', array( 'fish' => 'Coral Beauty', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'lostfish_cases', array( 'product_id' => 0, 'species' => 'Coral Beauty', 'kind_key' => 's:coral beauty|3-5', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'lostfish_cases', array( 'product_id' => 77, 'species' => 'Coral Beauty', 'kind_key' => 'p:77', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'invoice_lines', array( 'invoice_id' => 1, 'draft' => true, 'name' => 'Coral Beauty (3-5")', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'invoice_lines', array( 'invoice_id' => 2, 'draft' => false, 'name' => 'Coral Beauty (3-5")', 'size_slug' => '3-5', 'size_label' => '3-5"' ) );
	$shop->add_row( 'box_shares', array( 'share_key' => $old, 'location_key' => $loc, 'supplier' => 'Reef Co', 'origin' => 'Bali', 'fish_key' => 'id:5', 'species' => 'Coral Beauty', 'size_slug' => '3-5', 'size_label' => '3-5"', 'pct_per_fish' => '2.5000', 'order_count' => 3, 'last_line_id' => 1, 'last_date' => '2026-03-01' ) );
	$shop->add_row( 'box_shares', array( 'share_key' => Box_Shares::share_key( $loc, 'id:5', '3-4' ), 'location_key' => $loc, 'supplier' => 'Reef Co', 'origin' => 'Bali', 'fish_key' => 'id:5', 'species' => 'Coral Beauty', 'size_slug' => '3-4', 'size_label' => '3-4"', 'pct_per_fish' => '2.0000', 'order_count' => 4, 'last_line_id' => 9, 'last_date' => '2026-05-01' ) );

	$shop->options['fishotel_packing_settings'] = array(
		'bags'     => array( 'bag-s' => 'Small bag', 'bag-m' => 'Medium bag', 'bag-l' => 'Large bag' ),
		'size_map' => array( '3-5' => 'bag-l', '3-4' => 'bag-m', '2-3-5' => 'bag-s', 'small-medium' => 'bag-s', 'medium' => 'bag-m' ),
	);

	return $shop;
}

/**
 * Product title → id.
 *
 * @param Fake_Tidy_Shop $shop Shop.
 * @return array<string,int>
 */
function fh_tidy_ids( Fake_Tidy_Shop $shop ) {
	$out = array();
	foreach ( $shop->products as $pid => $product ) {
		$out[ $product['title'] ] = $pid;
	}
	return $out;
}

/**
 * A list, sorted as text.
 *
 * @param array $list List.
 * @return array
 */
function fh_sorted( array $list ) {
	$list = array_map( 'strval', array_values( $list ) );
	sort( $list, SORT_STRING );
	return $list;
}
