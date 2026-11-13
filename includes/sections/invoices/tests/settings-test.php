<?php
/**
 * Settings — the defaults (nothing seeded: no image), what a saved form
 * may and may not change (a title format without {handle} is refused and
 * the earlier one kept; a threshold that is not an amount likewise), how
 * a shipping choice becomes a class slug, and that the option is not
 * autoloaded.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/settings-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Settings;

echo "1. The defaults\n";
$d = Settings::defaults();
check_same( 'the category, the two formats, the shipping map', array( 'invoices', '{handle} Invoice ({date})', '{handle} Only', array( 'premium' => 'premium', 'free' => 'free' ) ), array( $d['category_slug'], $d['title_format'], $d['excerpt_format'], $d['shipping'] ) );
check( 'no image is seeded — an invoice gets none until two are chosen', 0 === $d['image_under'] && 0 === $d['image_over'] );
check_same( 'the threshold', 400.0, $d['image_threshold'] );
check_same( 'nothing stored: get() is the defaults', $d, Settings::get() );

echo "2. Saving\n";
$saved = Settings::save(
	array(
		'category_slug'   => ' Bills & Invoices ',
		'title_format'    => '  Bill for {handle}   ({date}) ',
		'excerpt_format'  => 'For {handle}',
		'shipping'        => array( 'premium' => 'Overnight Premium', 'free' => '' ),
		'image_under'     => '101',
		'image_over'      => '202',
		'image_threshold' => '$250',
		'evil'            => 'x',
	)
);
$s     = $saved['settings'];
check( 'no errors', array() === $saved['errors'] );
check_same( 'slugs are slugged, formats trimmed, ids and the threshold typed', array( 'bills-invoices', 'Bill for {handle} ({date})', 'For {handle}', 'overnight-premium', '', 101, 202, 250.0 ), array( $s['category_slug'], $s['title_format'], $s['excerpt_format'], $s['shipping']['premium'], $s['shipping']['free'], $s['image_under'], $s['image_over'], $s['image_threshold'] ) );
check( 'an unknown key is not stored', ! isset( $s['evil'] ) && ! isset( get_option( Settings::OPTION_KEY )['evil'] ) );
check_same( 'the option is NOT autoloaded: it is only read where an invoice is built or listed', false, $GLOBALS['fh_autoload'][ Settings::OPTION_KEY ] );
check_same( 'get() reads it back', $s, Settings::get() );

$saved = Settings::save( array( 'title_format' => 'Invoice ({date})', 'excerpt_format' => 'Only', 'image_threshold' => 'lots', 'category_slug' => '   ' ) );
check( 'a title format and a short description without {handle}, a threshold that is no amount: three errors', 3 === count( $saved['errors'] ) );
check_same( '…and the earlier values are kept, the empty category too', array( 'Bill for {handle} ({date})', 'For {handle}', 250.0, 'bills-invoices' ), array( $saved['settings']['title_format'], $saved['settings']['excerpt_format'], $saved['settings']['image_threshold'], $saved['settings']['category_slug'] ) );
check( 'what the form did not post is kept as it was', 101 === $saved['settings']['image_under'] && 'overnight-premium' === $saved['settings']['shipping']['premium'] );

$saved = Settings::save( array( 'image_threshold' => '-5' ) );
check( 'a negative threshold is refused', 1 === count( $saved['errors'] ) && 250.0 === $saved['settings']['image_threshold'] );
$saved = Settings::save( array( 'image_threshold' => '0', 'image_under' => '0', 'image_over' => '-7' ) );
check( 'a threshold of 0 is an amount; an image of 0 means none; a negative id is 0', array() === $saved['errors'] && 0.0 === $saved['settings']['image_threshold'] && 0 === $saved['settings']['image_under'] && 0 === $saved['settings']['image_over'] );
$saved = Settings::save( 'junk' );
check( 'junk instead of a form changes nothing', array() === $saved['errors'] && 'bills-invoices' === $saved['settings']['category_slug'] );

echo "3. Stored junk never reaches the code\n";
update_option( Settings::OPTION_KEY, array( 'category_slug' => array( 'x' ), 'title_format' => 'no handle here', 'shipping' => 'premium', 'image_under' => 'abc', 'image_threshold' => array() ), false );
check_same( 'get() falls back to the defaults, value by value', Settings::defaults(), Settings::get() );
update_option( Settings::OPTION_KEY, 'not an array', false );
check_same( 'an option that is not an array: the defaults', Settings::defaults(), Settings::get() );

echo "4. From a shipping choice to a class slug\n";
$settings = array( 'shipping' => array( 'premium' => 'overnight', 'free' => 'free-shipping' ) );
check_same( 'Premium and Free are what Settings maps them to', array( 'overnight', 'free-shipping' ), array( Settings::shipping_slug( $settings, 'premium' ), Settings::shipping_slug( $settings, 'free' ) ) );
check_same( 'another class is the invoice\'s own slug', 'quote', Settings::shipping_slug( $settings, 'other', ' Quote ' ) );
check_same( 'an unknown choice is Premium', 'overnight', Settings::shipping_slug( $settings, 'teleport', 'quote' ) );
check_same( 'nothing mapped: no slug', array( '', '' ), array( Settings::shipping_slug( array( 'shipping' => array( 'premium' => '' ) ), 'premium' ), Settings::shipping_slug( array(), 'free' ) ) );
check_same( 'another class without a slug: none', '', Settings::shipping_slug( $settings, 'other', '' ) );
check( 'the three choices have labels', 3 === count( array_unique( array_map( array( Settings::class, 'shipping_label' ), Settings::SHIPPING_CHOICES ) ) ) );

fh_finish();
