<?php
/**
 * The Sticker screen's warning for a sticker product saved with no
 * category: WooCommerce files it under the store's default category.
 * Pure (Admin::category_warning()).
 *
 * Run from the plugin root:
 *   php includes/sections/sticker-giveaway/tests/category-warning-test.php
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/sticker-giveaway/class-admin.php';

use FisHotel\Misc\Sections\Sticker_Giveaway\Admin;

$checks   = 0;
$failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $checks, $failures;
	$checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

$fh_warn = Admin::category_warning( 'Invented Sticker', array( 15 ), 15, 'Uncategorized' );
check( 'only in the default category: a warning that names the product and the category', false !== strpos( $fh_warn, 'Invented Sticker was saved with no category' ) && false !== strpos( $fh_warn, '"Uncategorized"' ) && false !== strpos( $fh_warn, 'a category of its own' ), $fh_warn );
check( 'no category at all: the same warning', '' !== Admin::category_warning( 'Invented Sticker', array(), 15, 'Uncategorized' ) );
check( 'the default category\'s name not known: still warned, without it', false !== strpos( Admin::category_warning( 'X', array(), 15 ), 'the store\'s default category.' ) );
check( 'a category of its own: no warning', '' === Admin::category_warning( 'X', array( 40 ), 15, 'Uncategorized' ) && '' === Admin::category_warning( 'X', array( 15, 40 ), 15, 'Uncategorized' ) );
check( 'a store with no default category set: only "no category at all" warns', '' === Admin::category_warning( 'X', array( 40 ), 0 ) && '' !== Admin::category_warning( 'X', array(), 0 ) );

echo "\n" . ( $failures ? "$failures of $checks checks FAILED\n" : "All $checks checks passed\n" );
exit( $failures ? 1 : 0 );
