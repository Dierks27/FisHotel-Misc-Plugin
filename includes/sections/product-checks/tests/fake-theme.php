<?php
/**
 * The theme's "copy facts" (FisHotel Theme 1.28+) as it answers — loaded
 * by checks-test.php at the point the theme is "there".
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

if ( realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === realpath( __FILE__ ) ) {
	exit( 0 ); // A helper, not a test.
}

/**
 * Fill the empty facts from the description: the keys it filled.
 *
 * @param int $id Product ID.
 * @return string[]
 */
function fishotel_copy_facts_from_description( $id ) {
	if ( empty( $GLOBALS['fh_theme_on'] ) ) {
		return array();
	}
	$GLOBALS['fh_post_meta'][ (int) $id ]['_fh_common_names'] = 'Invented Tang';
	return array( '_fh_common_names' );
}
