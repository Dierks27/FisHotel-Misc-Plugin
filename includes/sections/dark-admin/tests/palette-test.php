<?php
/**
 * Dark admin — the colours and the style sheets (class-palette.php,
 * css/dark-admin.css, css/editor-content.css, css/scheme.css).
 *
 * Reads the CSS files as text: the variables in dark-admin.css are the
 * palette's, the palette is the FisHotel Tools screens' own, every text
 * colour reaches 4.5 : 1 on every background it is put on, every rule
 * hangs on body.fh-dark-admin (so the files can do nothing on a screen
 * the section did not mark), and no colour is written into a rule
 * except through the variables.
 *
 * Run from the plugin root:
 *   php includes/sections/dark-admin/tests/palette-test.php
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/class-palette.php';

use FisHotel\Misc\Sections\Dark_Admin\Palette;

$fh_dir    = dirname( __DIR__ ) . '/css/';
$fh_layer  = file_get_contents( $fh_dir . 'dark-admin.css' );
$fh_editor = file_get_contents( $fh_dir . 'editor-content.css' );
$fh_scheme = file_get_contents( $fh_dir . 'scheme.css' );
$fh_tools  = file_get_contents( FISHOTEL_MISC_PATH . 'admin/css/admin-style.css' );

/**
 * The rules of a style sheet: list of array( selectors[], body ), comments
 * and data: URLs taken out, @media blocks opened up.
 */
function fh_rules( $css ) {
	$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
	$css   = preg_replace( '#url\(\s*"data:[^"]*"\s*\)|url\(\s*\'data:[^\']*\'\s*\)#', 'url(data)', $css );
	$css   = preg_replace( '#@media[^{]*\{((?:[^{}]*\{[^{}]*\})*)\s*\}#', '$1', $css );
	$rules = array();
	preg_match_all( '#([^{}]+)\{([^{}]*)\}#', $css, $m, PREG_SET_ORDER );
	foreach ( $m as $r ) {
		$rules[] = array( array_map( 'trim', explode( ',', trim( $r[1] ) ) ), $r[2] );
	}
	return $rules;
}

function fh_hexes( $text ) {
	preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $text, $m );
	return array_map( 'strtolower', $m[0] );
}

echo "The palette\n";
check_same( 'every text colour reaches 4.5 : 1 on every background it is put on', array(), Palette::unreadable_pairs() );
check( 'the sums: black on white is 21 : 1, a colour on itself 1 : 1', abs( Palette::contrast( '#000', '#ffffff' ) - 21 ) < 0.01 && abs( Palette::contrast( '#2d2d2d', '#2d2d2d' ) - 1 ) < 0.001 );
check( 'the sums: WordPress blue on white is the known 5.17 : 1', abs( Palette::contrast( '#2271b1', '#fff' ) - 5.17 ) < 0.01 );
check( 'a pair under the bar is caught (the plain red #dc3232 as text on a card)', in_array( 'danger-text on surface = 2.98', Palette::unreadable_pairs( array_merge( Palette::TOKENS, array( 'danger-text' => '#dc3232' ) ) ), true ) );
check( 'a pair with a missing colour is caught', in_array( 'text on bg = missing', Palette::unreadable_pairs( array_diff_key( Palette::TOKENS, array( 'bg' => 1 ) ) ), true ) );
check( 'a colour that is not a colour reads as black, never a crash', null === Palette::rgb( 'teal' ) && 0.0 === Palette::luminance( 'teal' ) );

echo "\nThe FisHotel Tools colours, reused\n";
$fh_from_tools = array(
	'bg'         => '--fh-bg-primary',
	'surface'    => '--fh-bg-secondary',
	'raised'     => '--fh-bg-hover',
	'text'       => '--fh-text-primary',
	'text-quiet' => '--fh-text-secondary',
	'accent'     => '--fh-accent',
	'link'       => '--fh-accent-hover',
	'success'    => '--fh-success',
	'warning'    => '--fh-warning',
	'danger'     => '--fh-danger',
);
foreach ( $fh_from_tools as $fh_token => $fh_var ) {
	preg_match( '/' . preg_quote( $fh_var, '/' ) . ':\s*(#[0-9a-fA-F]{3,6})/', $fh_tools, $fh_m );
	check_same( "$fh_token is FisHotel Tools' $fh_var", strtolower( $fh_m[1] ?? '?' ), Palette::TOKENS[ $fh_token ] );
}
check( 'the fields use the FisHotel Tools selects\' #1e1e1e', false !== stripos( $fh_tools, '#1e1e1e' ) && '#1e1e1e' === Palette::TOKENS['input'] );

echo "\ndark-admin.css\n";
$fh_rules = fh_rules( $fh_layer );
$fh_first = $fh_rules[0];
check_same( 'opens with the variables, on body.fh-dark-admin', array( 'body.fh-dark-admin' ), $fh_first[0] );
preg_match_all( '/--fh-da-([a-z-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/', $fh_first[1], $fh_m, PREG_SET_ORDER );
$fh_declared = array();
foreach ( $fh_m as $fh_d ) {
	$fh_declared[ $fh_d[1] ] = strtolower( $fh_d[2] );
}
check_same( 'the variables are exactly the palette, in its order', Palette::TOKENS, $fh_declared );

preg_match_all( '/var\(\s*--fh-da-([a-z-]+)/', $fh_layer, $fh_used );
$fh_unknown = array_diff( array_unique( $fh_used[1] ), array_keys( Palette::TOKENS ), array( 'fix-fg' ) );
check_same( 'every variable a rule uses is declared', array(), array_values( $fh_unknown ) );

$fh_loose = array();
$fh_raw   = array();
foreach ( $fh_rules as $fh_i => $fh_rule ) {
	foreach ( $fh_rule[0] as $fh_sel ) {
		if ( 0 !== strpos( $fh_sel, 'body.fh-dark-admin' ) && 0 !== strpos( $fh_sel, 'html:has(body.fh-dark-admin)' ) ) {
			$fh_loose[] = $fh_sel;
		}
	}
	$fh_is_light = in_array( 'body.fh-dark-admin .fh-da-light', $fh_rule[0], true );
	if ( 0 === $fh_i || $fh_is_light ) {
		continue;
	}
	foreach ( fh_hexes( $fh_rule[1] ) as $fh_hex ) {
		if ( '#000' !== $fh_hex ) { // the dimmed backdrop behind pop-ups
			$fh_raw[] = implode( ', ', $fh_rule[0] ) . ' → ' . $fh_hex;
		}
	}
}
check_same( 'every selector hangs on body.fh-dark-admin', array(), $fh_loose );
check_same( 'no rule writes a colour of its own (only the variables; black backdrops aside)', array(), $fh_raw );
check( 'it has rules for each part named in its header', count( $fh_rules ) > 150 );

$fh_light = null;
foreach ( $fh_rules as $fh_rule ) {
	if ( in_array( 'body.fh-dark-admin .fh-da-light', $fh_rule[0], true ) ) {
		$fh_light = $fh_rule[1];
	}
}
check( 'a box kept light on purpose sets the light colours again', null !== $fh_light && false !== strpos( (string) $fh_light, 'color-scheme: light' ) );
preg_match_all( '/--fh-da-(text|text-quiet|link|surface|sunken|raised):\s*(#[0-9a-f]{6})/i', (string) $fh_light, $fh_lm, PREG_SET_ORDER );
$fh_lt = array();
foreach ( $fh_lm as $fh_d ) {
	$fh_lt[ $fh_d[1] ] = $fh_d[2];
}
$fh_light_ok = isset( $fh_lt['text'], $fh_lt['text-quiet'], $fh_lt['link'], $fh_lt['surface'], $fh_lt['sunken'], $fh_lt['raised'] );
foreach ( array( 'text', 'text-quiet', 'link' ) as $fh_fg ) {
	foreach ( array( 'surface', 'sunken', 'raised' ) as $fh_bg ) {
		$fh_light_ok = $fh_light_ok && Palette::contrast( $fh_lt[ $fh_fg ], $fh_lt[ $fh_bg ] ) >= Palette::MIN_CONTRAST;
	}
}
check( 'and its text, quiet text and links read on its own backgrounds', $fh_light_ok );

foreach ( array( 'Page and type', 'Links', 'Buttons', 'Fields', 'Boxes', 'Tables and lists', 'Notices', 'Tabs, Screen Options, Help', 'Quick edit and bulk edit', 'The classic editor', 'Media', 'Pop-ups', 'Dashboard', 'WooCommerce: classic screens', 'React pages', 'Yoast', 'FisHotel plugins', 'readability guard', 'Kept light on purpose' ) as $fh_part ) {
	check( "has its part: $fh_part", false !== stripos( $fh_layer, $fh_part ) );
}
foreach ( array( '.select2-dropdown', '.ui-datepicker', '#TB_window', '.ui-dialog', '.iris-picker', '.media-modal-content', '#screen-meta', '.inline-edit-row', 'textarea.wp-editor-area', 'ul.wc-tabs', '.woocommerce_variation', '#order_data', 'ul.order_notes li .note_content', '.wc-backbone-modal', '.woocommerce-layout', '#wpseo_meta', '.notice' ) as $fh_sel ) {
	check( "styles $fh_sel", false !== strpos( $fh_layer, $fh_sel ) );
}

echo "\neditor-content.css (the writing area)\n";
$fh_loose = array();
$fh_off   = array();
foreach ( fh_rules( $fh_editor ) as $fh_rule ) {
	foreach ( $fh_rule[0] as $fh_sel ) {
		if ( 0 !== strpos( $fh_sel, 'body.fh-dark-admin' ) ) {
			$fh_loose[] = $fh_sel;
		}
	}
	foreach ( fh_hexes( $fh_rule[1] ) as $fh_hex ) {
		if ( ! in_array( $fh_hex, Palette::TOKENS, true ) ) {
			$fh_off[] = $fh_hex;
		}
	}
}
check_same( 'every selector hangs on body.fh-dark-admin', array(), $fh_loose );
check_same( 'every colour is one of the palette\'s', array(), array_values( array_unique( $fh_off ) ) );
foreach ( array( 'fh-da-ink-light', 'fh-da-ink-dark', 'fh-da-paper' ) as $fh_class ) {
	check( "styles the guard's class $fh_class", false !== strpos( $fh_editor, '.' . $fh_class ) );
}

echo "\nscheme.css (menu and admin bar)\n";
check( 'sets WordPress\'s theme colour for the scheme to the darker FisHotel blue', (bool) preg_match( '/body\.admin-color-fishotel-dark\s*\{[^}]*--wp-admin-theme-color:\s*' . Palette::TOKENS['accent-strong'] . ';/', $fh_scheme ) );
check( 'and its darker step to the button hover blue', false !== strpos( $fh_scheme, '--wp-admin-theme-color-darker-10: ' . Palette::TOKENS['accent-hover'] ) );
foreach ( array( 'bg', 'text', 'link', 'link-hover', 'accent-strong', 'surface' ) as $fh_token ) {
	check( "built from the palette's $fh_token", false !== stripos( $fh_scheme, Palette::TOKENS[ $fh_token ] ) );
}
check( 'it styles the admin menu and the admin bar', false !== strpos( $fh_scheme, '#adminmenu' ) && false !== strpos( $fh_scheme, '#wpadminbar' ) );
check( 'white menu text on the current item reads', Palette::contrast( '#ffffff', Palette::TOKENS['accent-strong'] ) >= Palette::MIN_CONTRAST );
check( 'menu text and quiet icons read on the menu', Palette::contrast( '#c8c8c8', Palette::TOKENS['surface'] ) >= Palette::MIN_CONTRAST && Palette::contrast( Palette::TOKENS['text'], Palette::TOKENS['bg'] ) >= Palette::MIN_CONTRAST );

fh_done();
