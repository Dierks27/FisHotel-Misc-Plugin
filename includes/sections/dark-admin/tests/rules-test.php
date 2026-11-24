<?php
/**
 * Dark admin — the pure decisions (class-rules.php).
 *
 * Run from the plugin root:
 *   php includes/sections/dark-admin/tests/rules-test.php
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/class-rules.php';

use FisHotel\Misc\Sections\Dark_Admin\Rules;

echo "Whose switch says what\n";
check( 'nobody has touched it yet (no meta): dark', true === Rules::wants_dark( '' ) );
check( 'ticked: dark', true === Rules::wants_dark( '1' ) );
check( 'unticked: light', false === Rules::wants_dark( '0' ) );
check( 'a stored 0 as a number: light', false === Rules::wants_dark( 0 ) );
check( '"off", "no", "false" in any case and spacing: light', ! Rules::wants_dark( ' OFF ' ) && ! Rules::wants_dark( 'no' ) && ! Rules::wants_dark( 'False' ) );
check( 'false / true as booleans', false === Rules::wants_dark( false ) && true === Rules::wants_dark( true ) );
check( 'anything else (a stray value): dark', Rules::wants_dark( 'yes' ) && Rules::wants_dark( null ) );

echo "\nWhat a profile save writes\n";
check_same( 'the profile form, ticked: 1', '1', Rules::meta_to_save( array( Rules::SENT => '1', Rules::FIELD => '1' ) ) );
check_same( 'the profile form, unticked (the browser sends no box): 0', '0', Rules::meta_to_save( array( Rules::SENT => '1' ) ) );
check_same( 'another form that saves a user (no marker): leave it alone', null, Rules::meta_to_save( array( Rules::FIELD => '1', 'first_name' => 'Ana' ) ) );
check_same( 'nothing posted: leave it alone', null, Rules::meta_to_save( array() ) );

echo "\nWhich screens turn dark\n";
$fh_ctx = array( 'is_admin' => true, 'pagenow' => 'post.php', 'is_block_editor' => false, 'customize_preview' => false );
check( 'a classic product / order screen: dark', Rules::screen_is_dark( $fh_ctx ) );
foreach ( array( 'index.php', 'edit.php', 'upload.php', 'admin.php', 'users.php', 'plugins.php', 'options-general.php', 'media-upload.php', 'profile.php' ) as $fh_page ) {
	check( "$fh_page: dark", Rules::screen_is_dark( array_merge( $fh_ctx, array( 'pagenow' => $fh_page ) ) ) );
}
check( 'the block editor: left as WordPress draws it', ! Rules::screen_is_dark( array_merge( $fh_ctx, array( 'is_block_editor' => true ) ) ) );
check( 'the Customizer: left as it is', ! Rules::screen_is_dark( array_merge( $fh_ctx, array( 'pagenow' => 'customize.php' ) ) ) );
check( 'the site editor: left as it is', ! Rules::screen_is_dark( array_merge( $fh_ctx, array( 'pagenow' => 'site-editor.php' ) ) ) );
check( 'the Customizer\'s preview of the shop: never', ! Rules::screen_is_dark( array_merge( $fh_ctx, array( 'customize_preview' => true ) ) ) );
check( 'outside wp-admin (the storefront, the login page): never', ! Rules::screen_is_dark( array_merge( $fh_ctx, array( 'is_admin' => false ) ) ) );
check( 'no facts at all: not dark', ! Rules::screen_is_dark( array() ) );

echo "\nWhich colour scheme\n";
check_same( 'dark: FisHotel dark, whatever was chosen', 'fishotel-dark', Rules::scheme( true, 'ocean', false ) );
check_same( 'dark, nothing chosen: FisHotel dark', 'fishotel-dark', Rules::scheme( true, false, false ) );
check_same( 'not dark: their own choice', 'ocean', Rules::scheme( false, 'ocean', false ) );
check_same( 'not dark, nothing chosen: nothing (WordPress\'s default)', false, Rules::scheme( false, false, false ) );
check_same( 'inside the picker: their own choice, so a profile save keeps it', 'ocean', Rules::scheme( true, 'ocean', true ) );
check_same( 'the scheme is registered under the name the CSS uses', 'fishotel-dark', Rules::SCHEME );

echo "\nThe class every rule hangs on\n";
check_same( 'added to the admin body classes', ' folded fh-dark-admin ', Rules::add_class( ' folded ' ) );
check_same( 'added once, however often the filter runs', ' folded fh-dark-admin ', Rules::add_class( Rules::add_class( 'folded' ) ) );
check_same( 'to an empty list', ' fh-dark-admin ', Rules::add_class( '' ) );
check_same( 'a look-alike class is not taken for it', ' fh-dark-admin-old fh-dark-admin ', Rules::add_class( 'fh-dark-admin-old' ) );

echo "\nThe classic editor's style sheets\n";
$fh_url = 'https://example.test/editor-content.css?ver=1';
check_same( 'after the theme\'s own', 'https://example.test/theme/editor-style.css,' . $fh_url, Rules::add_editor_css( 'https://example.test/theme/editor-style.css', $fh_url ) );
check_same( 'on its own when there is none', $fh_url, Rules::add_editor_css( '', $fh_url ) );
check_same( 'once, even with another version number', 'a.css,https://example.test/editor-content.css?ver=0', Rules::add_editor_css( 'a.css, https://example.test/editor-content.css?ver=0', $fh_url ) );
check_same( 'stray commas and spaces tidied', 'a.css,b.css,' . $fh_url, Rules::add_editor_css( ' a.css,, b.css ,', $fh_url ) );

fh_done();
