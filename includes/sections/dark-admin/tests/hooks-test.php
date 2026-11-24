<?php
/**
 * Dark admin — the WordPress edges (class-hooks.php, class-dark-admin.php):
 * what is hooked and where, who gets the dark files on which screen, the
 * colour scheme, the profile tick and its save.
 *
 * Run from the plugin root:
 *   php includes/sections/dark-admin/tests/hooks-test.php
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/class-dark-admin.php';

use FisHotel\Misc\Sections\Dark_Admin\Dark_Admin;
use FisHotel\Misc\Sections\Dark_Admin\Hooks;
use FisHotel\Misc\Sections\Dark_Admin\Rules;

function fh_reset( $user = 7, $meta = null, $pagenow = 'post.php', $screen = null ) {
	$GLOBALS['fh_user']    = $user;
	$GLOBALS['fh_meta']    = null === $meta ? array() : array( $user => array( Rules::META_KEY => $meta ) );
	$GLOBALS['pagenow']    = $pagenow;
	$GLOBALS['fh_screen']  = $screen ? $screen : new FH_Screen( false );
	$GLOBALS['fh_styles']  = array();
	$GLOBALS['fh_scripts'] = array();
	$GLOBALS['fh_customizing'] = false;
	$GLOBALS['fh_is_admin']    = true;
}

echo "Booting\n";
$GLOBALS['fh_is_admin'] = false;
Dark_Admin::boot();
check( 'on the storefront (and the login page) it hooks nothing at all', array() === $GLOBALS['fh_hooks'] );

$GLOBALS['fh_is_admin'] = true;
Dark_Admin::boot();
$fh_expected = array( 'admin_init', 'get_user_option_admin_color', 'admin_color_scheme_picker', 'admin_body_class', 'admin_enqueue_scripts', 'mce_css', 'tiny_mce_before_init', 'personal_options', 'personal_options_update', 'edit_user_profile_update' );
check_same( 'in wp-admin it hooks exactly these', $fh_expected, array_keys( $GLOBALS['fh_hooks'] ) );
check( 'the admin colour filter takes the user it is read for (3 arguments)', 3 === $GLOBALS['fh_hooks']['get_user_option_admin_color'][0][2] );
check( 'the picker is marked around WordPress\'s own picker (9 and 11, it draws at 10)', array( 9, 11 ) === array_map( function ( $h ) { return $h[1]; }, $GLOBALS['fh_hooks']['admin_color_scheme_picker'] ) );
check( 'the dark sheet is added late (100), after other plugins\' sheets', 100 === $GLOBALS['fh_hooks']['admin_enqueue_scripts'][0][1] );
$fh_info = Dark_Admin::get_section_info();
check( 'the section card: "Dark admin", a sentence, an icon', 'Dark admin' === $fh_info['name'] && '' !== $fh_info['description'] && 0 === strpos( $fh_info['icon'], 'dashicons-' ) );

echo "\nThe colour scheme\n";
fh_run( 'admin_init' );
check( '"FisHotel dark" is registered next to WordPress\'s schemes', isset( $GLOBALS['fh_schemes']['fishotel-dark'] ) && 'FisHotel dark' === $GLOBALS['fh_schemes']['fishotel-dark']['name'] );
check( 'from the section\'s scheme.css, with the plugin version', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/includes/sections/dark-admin/css/scheme.css?ver=9.9.9' === $GLOBALS['fh_schemes']['fishotel-dark']['url'] );
check( 'its four swatches are the palette\'s', array( '#1a1a1a', '#2d2d2d', '#00789f', '#0eb5e8' ) === $GLOBALS['fh_schemes']['fishotel-dark']['colors'] );

fh_reset( 7, null );
$fh_me = (object) array( 'ID' => 7 );
check_same( 'a person nobody asked yet: FisHotel dark', 'fishotel-dark', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
fh_reset( 7, '0' );
check_same( 'a person who switched it off: their own scheme', 'ectoplasm', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
fh_reset( 7, '1', 'customize.php' );
check_same( 'in the Customizer: their own scheme', 'ectoplasm', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
fh_reset( 7, '1', 'post.php', new FH_Screen( true ) );
check_same( 'in the block editor: their own scheme', 'ectoplasm', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
fh_reset( 7, '1', 'profile.php' );
fh_run( 'admin_color_scheme_picker', 7 );
check_same( 'after the picker is drawn, dark again', 'fishotel-dark', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
Hooks::picker_start();
check_same( 'while the picker is drawn: their own choice is shown (and kept on save)', 'ectoplasm', fh_run( 'get_user_option_admin_color', 'ectoplasm', 'admin_color', $fh_me ) );
Hooks::picker_end();
fh_reset( 7, '1', 'user-edit.php' );
$GLOBALS['fh_meta'][9] = array( Rules::META_KEY => '0' );
check_same( 'read for another person who switched it off (an admin on their Edit User screen): theirs', 'midnight', fh_run( 'get_user_option_admin_color', 'midnight', 'admin_color', (object) array( 'ID' => 9 ) ) );

echo "\nWho gets the dark files\n";
fh_reset( 7, null, 'post.php' );
fh_run( 'admin_enqueue_scripts', 'post.php' );
check( 'a person nobody asked yet, on a product screen: the sheet', isset( $GLOBALS['fh_styles']['fishotel-dark-admin'] ) && false !== strpos( $GLOBALS['fh_styles']['fishotel-dark-admin'], 'dark-admin/css/dark-admin.css' ) );
check( 'and the readability guard, in the footer', isset( $GLOBALS['fh_scripts']['fishotel-dark-admin'] ) && true === $GLOBALS['fh_scripts']['fishotel-dark-admin'][1] );
check_same( 'and the body class', ' wp-admin fh-dark-admin ', fh_run( 'admin_body_class', ' wp-admin ' ) );

fh_reset( 7, '0' );
fh_run( 'admin_enqueue_scripts', 'post.php' );
check( 'switched off on their profile: no file at all', array() === $GLOBALS['fh_styles'] && array() === $GLOBALS['fh_scripts'] );
check_same( 'and no class', ' wp-admin ', fh_run( 'admin_body_class', ' wp-admin ' ) );

fh_reset( 0, null );
fh_run( 'admin_enqueue_scripts', 'post.php' );
check( 'nobody logged in: nothing', array() === $GLOBALS['fh_styles'] );

fh_reset( 7, '1', 'post.php', new FH_Screen( true ) );
fh_run( 'admin_enqueue_scripts', 'post.php' );
check( 'the block editor: nothing (left as WordPress draws it)', array() === $GLOBALS['fh_styles'] && ' x ' === fh_run( 'admin_body_class', ' x ' ) );

echo "\nThe classic editor's writing area\n";
fh_reset( 7, null );
check_same( 'our sheet after the theme\'s', 'theme.css,https://example.test/wp-content/plugins/fishotel-misc-plugin/includes/sections/dark-admin/css/editor-content.css?ver=9.9.9', fh_run( 'mce_css', 'theme.css' ) );
check_same( 'the class on the writing area\'s body', 'content post-type-product fh-dark-admin', fh_run( 'tiny_mce_before_init', array( 'body_class' => 'content post-type-product' ) )['body_class'] );
check_same( 'when the editor gave no body class', 'fh-dark-admin', fh_run( 'tiny_mce_before_init', array() )['body_class'] );
fh_reset( 7, '0' );
check_same( 'switched off: the editor as it was', 'theme.css', fh_run( 'mce_css', 'theme.css' ) );
check_same( 'switched off: no class', array( 'body_class' => 'content' ), fh_run( 'tiny_mce_before_init', array( 'body_class' => 'content' ) ) );

echo "\nThe profile tick\n";
fh_reset( 7, null, 'profile.php' );
ob_start();
fh_run( 'personal_options', (object) array( 'ID' => 7 ) );
$fh_html = ob_get_clean();
check( 'a "Dark admin" row in Personal Options', false !== strpos( $fh_html, '<th scope="row">Dark admin</th>' ) );
check( 'ticked for a person nobody asked yet', (bool) preg_match( '/name="fishotel_dark_admin"[^>]*checked/', $fh_html ) );
check( 'with the hidden marker that tells an unticked box from another form', false !== strpos( $fh_html, 'name="fishotel_dark_admin_sent" value="1"' ) );
check( 'and says it is for this person only, and how to get the light screens back', false !== strpos( $fh_html, 'Only for this person' ) && false !== strpos( $fh_html, 'Untick it' ) );
$GLOBALS['fh_meta'][7][ Rules::META_KEY ] = '0';
ob_start();
fh_run( 'personal_options', (object) array( 'ID' => 7 ) );
check( 'unticked for a person who switched it off', ! preg_match( '/name="fishotel_dark_admin"[^>]*checked/', ob_get_clean() ) );

fh_reset( 7, null, 'profile.php' );
$_POST = array( Rules::SENT => '1' );
fh_run( 'personal_options_update', 7 );
check_same( 'saving their profile with the box unticked: off', '0', $GLOBALS['fh_meta'][7][ Rules::META_KEY ] );
$_POST = array( Rules::SENT => '1', Rules::FIELD => '1' );
fh_run( 'personal_options_update', 7 );
check_same( 'ticked again: on', '1', $GLOBALS['fh_meta'][7][ Rules::META_KEY ] );
$_POST = array( 'first_name' => 'Sam' );
fh_run( 'edit_user_profile_update', 7 );
check_same( 'another form that saves the user: the switch is left alone', '1', $GLOBALS['fh_meta'][7][ Rules::META_KEY ] );
$_POST = array( Rules::SENT => '1' );
fh_run( 'edit_user_profile_update', 9 );
check_same( 'an admin unticking it on another person\'s Edit User screen: that person\'s switch', '0', $GLOBALS['fh_meta'][9][ Rules::META_KEY ] );
$GLOBALS['fh_can'] = false;
$_POST             = array( Rules::SENT => '1', Rules::FIELD => '1' );
fh_run( 'edit_user_profile_update', 9 );
check_same( 'someone who may not edit that user: nothing saved', '0', $GLOBALS['fh_meta'][9][ Rules::META_KEY ] );
$GLOBALS['fh_can'] = true;

echo "\nThe files the section names exist\n";
foreach ( array( 'css/dark-admin.css', 'css/scheme.css', 'css/editor-content.css', 'js/dark-admin.js', 'README.md', 'tests/README.md' ) as $fh_file ) {
	check( "$fh_file", is_file( Dark_Admin::path() . $fh_file ) );
}

fh_done();
