<?php
/**
 * Dark admin — the colours, and the arithmetic that proves each text
 * colour is readable on each background it is used on.
 *
 * The values are the FisHotel Tools screens' own (admin/css/admin-style.css:
 * #1a1a1a page, #2d2d2d cards, #363636 hover, #e0e0e0 text, #a0a0a0 quiet
 * text, #00a0d2 accent, #0eb5e8 its lighter blue, #46b450 / #ffb900 /
 * #dc3232 success / warning / danger), plus the few a whole wp-admin needs
 * that those screens never did: an input background, a darker blue that
 * white button text is readable on, light versions of red and green for
 * text, and dark tints for notices and order notes.
 *
 * dark-admin.css declares exactly these as CSS variables (palette-test.php
 * reads the file and compares), and READABLE lists every text-on-background
 * pair the style sheet uses; each must reach 4.5:1, WCAG's bar for normal
 * text. The same sums run in the browser (js/dark-admin.js) to rescue text
 * another plugin coloured inline.
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

namespace FisHotel\Misc\Sections\Dark_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Palette
 */
class Palette {

	/**
	 * WCAG's minimum contrast for normal-size text.
	 *
	 * @var float
	 */
	const MIN_CONTRAST = 4.5;

	/**
	 * The variables dark-admin.css declares (name without "--fh-da-").
	 *
	 * @var array<string, string>
	 */
	const TOKENS = array(
		'bg'             => '#1a1a1a', // The page (FisHotel Tools --fh-bg-primary).
		'surface'        => '#2d2d2d', // Boxes, cards, tables (--fh-bg-secondary).
		'raised'         => '#363636', // Hover, table heads, active tabs (--fh-bg-hover).
		'sunken'         => '#242424', // Every other table row, tab strips.
		'input'          => '#1e1e1e', // Fields (the FisHotel Tools selects' #1e1e1e).
		'text'           => '#e0e0e0', // (--fh-text-primary).
		'text-quiet'     => '#a0a0a0', // Descriptions, counts (--fh-text-secondary).
		'link'           => '#0eb5e8', // (--fh-accent-hover).
		'link-hover'     => '#5fcff5',
		'accent'         => '#00a0d2', // Borders, switches, the current tab line (--fh-accent).
		'accent-strong'  => '#00789f', // Primary buttons and the current menu item, white text.
		'accent-hover'   => '#006a8e', // A primary button under the pointer.
		'on-accent'      => '#ffffff',
		'border'         => '#444444',
		'border-strong'  => '#5c5c5c',
		'success'        => '#46b450', // (--fh-success).
		'warning'        => '#ffb900', // (--fh-warning).
		'danger'         => '#dc3232', // (--fh-danger): borders and badges only, too dark for text.
		'danger-text'    => '#ff8a8a',
		'success-text'   => '#6fcf7a',
		'danger-tint'    => '#3a2222',
		'success-tint'   => '#223a26',
		'warning-tint'   => '#3a3220',
		'info-tint'      => '#1f3340',
		'note-private'   => '#2f3b45', // An order's private note.
		'note-customer'  => '#1f3a44', // A note to the customer.
		'note-system'    => '#3a2f38', // A note WooCommerce wrote by itself.
		'light-paper'    => '#ffffff', // Left light on purpose (see README).
		'light-ink'      => '#1e1e1e',
	);

	/**
	 * The classic editor's coloured inks (editor-content.css, and HUE_INKS in
	 * js/dark-admin.js): one per twelfth of the colour wheel, 0 red … 11
	 * pink. 'h' is the pure hue made lighter until it reads on every dark
	 * background (the lightest, 'raised', included); 'd' made darker until
	 * it reads on white. A coloured word in a description keeps its colour.
	 *
	 * @var array<string, string[]>
	 */
	const EDITOR_INKS = array(
		'h' => array( '#ff7373', '#ff8000', '#ffff00', '#80ff00', '#00ff00', '#00ff80', '#00ffff', '#4da6ff', '#9999ff', '#c68cff', '#ff59ff', '#ff73b9' ),
		'd' => array( '#e60000', '#b35900', '#737300', '#408000', '#008000', '#008040', '#008080', '#0073e6', '#0000ff', '#8000ff', '#cc00cc', '#e60073' ),
	);

	/**
	 * Every text colour → the backgrounds dark-admin.css puts it on.
	 *
	 * @var array<string, string[]>
	 */
	const READABLE = array(
		'text'         => array( 'bg', 'surface', 'raised', 'sunken', 'input', 'danger-tint', 'success-tint', 'warning-tint', 'info-tint', 'note-private', 'note-customer', 'note-system' ),
		'text-quiet'   => array( 'bg', 'surface', 'raised', 'sunken', 'input' ),
		'link'         => array( 'bg', 'surface', 'raised', 'sunken', 'input', 'info-tint', 'note-private', 'note-customer' ),
		'link-hover'   => array( 'bg', 'surface', 'raised', 'sunken' ),
		'on-accent'    => array( 'accent-strong', 'accent-hover', 'danger' ),
		'danger-text'  => array( 'bg', 'surface', 'raised', 'danger-tint' ),
		'success-text' => array( 'bg', 'surface', 'raised', 'success-tint' ),
		'success'      => array( 'bg', 'surface' ),
		'warning'      => array( 'bg', 'surface', 'raised', 'warning-tint' ),
		'light-ink'    => array( 'light-paper' ),
	);

	/**
	 * '#abc' / '#aabbcc' → array( r, g, b ), or null.
	 *
	 * @param string $hex A hex colour.
	 * @return int[]|null
	 */
	public static function rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * WCAG relative luminance (0 black … 1 white).
	 *
	 * @param string $hex A hex colour.
	 * @return float
	 */
	public static function luminance( $hex ) {
		$rgb = self::rgb( $hex );
		if ( null === $rgb ) {
			return 0.0;
		}
		$lin = array();
		foreach ( $rgb as $c ) {
			$c     = $c / 255;
			$lin[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/**
	 * WCAG contrast ratio of two colours (1 … 21).
	 *
	 * @param string $a A hex colour.
	 * @param string $b Another.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Every READABLE pair under the minimum: "text on bg = 3.1".
	 *
	 * @param array<string, string> $tokens The colours (TOKENS, or a test's).
	 * @return string[]
	 */
	public static function unreadable_pairs( array $tokens = self::TOKENS ) {
		$bad = array();
		foreach ( self::READABLE as $fg => $bgs ) {
			foreach ( $bgs as $bg ) {
				if ( ! isset( $tokens[ $fg ], $tokens[ $bg ] ) ) {
					$bad[] = "$fg on $bg = missing";
					continue;
				}
				$ratio = self::contrast( $tokens[ $fg ], $tokens[ $bg ] );
				if ( $ratio < self::MIN_CONTRAST ) {
					$bad[] = sprintf( '%s on %s = %.2f', $fg, $bg, $ratio );
				}
			}
		}
		return $bad;
	}

	/**
	 * The CSS variable block for dark-admin.css (and what the test compares
	 * the file against).
	 *
	 * @return string
	 */
	public static function css_variables() {
		$out = '';
		foreach ( self::TOKENS as $name => $hex ) {
			$out .= "\t--fh-da-$name: $hex;\n";
		}
		return $out;
	}
}
