<?php
/**
 * Dark admin — the decisions, as pure functions (no WordPress call), so
 * every one of them is tested headless.
 *
 *   - whose switch says what: the "Dark admin" tick on a person's profile
 *     is ON until that person (or an admin) unticks it — an empty value
 *     means nobody has touched it, and that means dark;
 *   - what a profile save writes: only when the profile form itself was
 *     sent (its hidden marker), so another form that saves a user never
 *     switches anybody's dark admin off;
 *   - which screens turn dark: every wp-admin screen except the ones that
 *     are a preview of the shop or a full-page editor of their own — the
 *     block editor (and the block-based widgets / site editor screens),
 *     the Customizer — and never the login page or the storefront, where
 *     this section does not even load its files;
 *   - which colour scheme the menu and admin bar use: "FisHotel dark" on a
 *     dark screen, the person's own choice everywhere else and inside the
 *     colour-scheme picker (so saving the profile keeps their own choice
 *     for the day they switch dark off);
 *   - the class every dark rule hangs on, added once to the admin <body>
 *     and to the classic editor's writing area.
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

namespace FisHotel\Misc\Sections\Dark_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	/**
	 * The user meta that holds a person's switch: '1' on, '0' off, missing = on.
	 *
	 * @var string
	 */
	const META_KEY = 'fishotel_dark_admin';

	/**
	 * The checkbox on the profile screen.
	 *
	 * @var string
	 */
	const FIELD = 'fishotel_dark_admin';

	/**
	 * The hidden field sent with the checkbox, so an unticked box (which a
	 * browser does not send at all) can be told from a form without it.
	 *
	 * @var string
	 */
	const SENT = 'fishotel_dark_admin_sent';

	/**
	 * The registered admin colour scheme.
	 *
	 * @var string
	 */
	const SCHEME = 'fishotel-dark';

	/**
	 * The class every rule in dark-admin.css and editor-content.css hangs on.
	 *
	 * @var string
	 */
	const BODY_CLASS = 'fh-dark-admin';

	/**
	 * Screens (by $pagenow) that stay as WordPress draws them: the
	 * Customizer is a live preview of the shop, the site editor a
	 * full-page editor of the theme.
	 *
	 * @var string[]
	 */
	const LIGHT_PAGES = array( 'customize.php', 'site-editor.php' );

	/**
	 * Does this person want the dark admin? Anything but an explicit
	 * "off" is on — a person nobody has asked yet gets the dark admin.
	 *
	 * @param mixed $meta The stored user meta ('' when never saved).
	 * @return bool
	 */
	public static function wants_dark( $meta ) {
		if ( is_bool( $meta ) ) {
			return $meta;
		}
		$meta = strtolower( trim( (string) $meta ) );
		return ! in_array( $meta, array( '0', 'off', 'no', 'false' ), true );
	}

	/**
	 * What a profile save writes, from the posted fields: '1' (ticked),
	 * '0' (the profile form was sent with the box unticked), or null —
	 * the form was not the profile form, leave the switch as it is.
	 *
	 * @param array $post The posted fields.
	 * @return string|null
	 */
	public static function meta_to_save( array $post ) {
		if ( empty( $post[ self::SENT ] ) ) {
			return null;
		}
		return empty( $post[ self::FIELD ] ) ? '0' : '1';
	}

	/**
	 * Is this screen drawn dark (for a person who wants it)?
	 *
	 * @param array $ctx {
	 *     @type bool   $is_admin         Inside wp-admin.
	 *     @type string $pagenow          The admin file ('post.php', …).
	 *     @type bool   $is_block_editor  The screen is the block editor.
	 *     @type bool   $customize_preview The request draws the Customizer's preview.
	 * }
	 * @return bool
	 */
	public static function screen_is_dark( array $ctx ) {
		if ( empty( $ctx['is_admin'] ) || ! empty( $ctx['customize_preview'] ) || ! empty( $ctx['is_block_editor'] ) ) {
			return false;
		}
		return ! in_array( (string) ( $ctx['pagenow'] ?? '' ), self::LIGHT_PAGES, true );
	}

	/**
	 * The colour scheme to use: FisHotel dark on a dark screen for a
	 * person who wants it, their own choice otherwise — and always their
	 * own inside the colour-scheme picker, so the picker shows (and a
	 * profile save keeps) what they chose themselves.
	 *
	 * @param bool  $dark      Dark for this person on this screen.
	 * @param mixed $stored    Their stored choice (false / '' when none).
	 * @param bool  $in_picker The picker is being drawn.
	 * @return mixed
	 */
	public static function scheme( $dark, $stored, $in_picker ) {
		if ( ! $dark || $in_picker ) {
			return $stored;
		}
		return self::SCHEME;
	}

	/**
	 * A space-separated class list with the dark class added once.
	 *
	 * @param string $classes The classes so far.
	 * @return string
	 */
	public static function add_class( $classes ) {
		$list = preg_split( '/\s+/', trim( (string) $classes ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! in_array( self::BODY_CLASS, $list, true ) ) {
			$list[] = self::BODY_CLASS;
		}
		return ' ' . implode( ' ', $list ) . ' ';
	}

	/**
	 * The classic editor's comma-separated list of style sheets for its
	 * writing area, with ours added once at the end (so it wins over the
	 * theme's editor style).
	 *
	 * @param string $list The list so far.
	 * @param string $url  Our editor-content.css.
	 * @return string
	 */
	public static function add_editor_css( $list, $url ) {
		$items = array_values( array_filter( array_map( 'trim', explode( ',', (string) $list ) ), 'strlen' ) );
		$bare  = strtok( (string) $url, '?' );
		foreach ( $items as $item ) {
			if ( strtok( $item, '?' ) === $bare ) {
				return implode( ',', $items );
			}
		}
		$items[] = (string) $url;
		return implode( ',', $items );
	}
}
