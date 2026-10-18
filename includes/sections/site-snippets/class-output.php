<?php
/**
 * Output — the CSS, JS and HTML snippets: printed inline on the screens
 * their Scope names, and the [fh_snippet slug="…"] shortcode (plus a
 * compatible [code_snippet id=N] once Code Snippets is gone).
 *
 *   css front-end / admin / login  → wp_add_inline_style() on an empty
 *                                    registered handle, on wp / admin /
 *                                    login enqueue
 *   js head                        → wp_add_inline_script() on an empty
 *                                    handle printed in <head> (front end)
 *   js front-end, footer           → same, before </body> (front end)
 *   js admin / login               → same, in the admin / login footer
 *   html head / body / footer      → echoed at wp_head / wp_body_open /
 *                                    wp_footer (front end), like Code
 *                                    Snippets' head / body / footer content
 *   html shortcode                 → [fh_snippet slug="…"] (attributes
 *                                    `format` and `shortcodes` as Code
 *                                    Snippets reads them)
 *
 * Hooks are only added for slots that have a switched-on snippet, so a
 * site without CSS / JS snippets pays nothing here. Content files are
 * printed as they are (they are code from the repository, reviewed in
 * GitHub); nothing in an .html file is ever executed.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Output
 */
class Output {

	/**
	 * Shortcode tags.
	 */
	const SHORTCODE        = 'fh_snippet';
	const COMPAT_SHORTCODE = 'code_snippet';

	/**
	 * Registered (empty) handles per slot.
	 *
	 * @var array<string, string>
	 */
	const HANDLES = array(
		Rules::SLOT_FRONT_STYLE  => 'fishotel-snippets-front',
		Rules::SLOT_ADMIN_STYLE  => 'fishotel-snippets-admin',
		Rules::SLOT_LOGIN_STYLE  => 'fishotel-snippets-login',
		Rules::SLOT_FRONT_HEAD   => 'fishotel-snippets-head',
		Rules::SLOT_FRONT_FOOT   => 'fishotel-snippets-footer',
		Rules::SLOT_ADMIN_SCRIPT => 'fishotel-snippets-admin',
		Rules::SLOT_LOGIN_SCRIPT => 'fishotel-snippets-login',
	);

	/**
	 * slot => snippets to print.
	 *
	 * @var array<string, array>
	 */
	private static $plan = array();

	/**
	 * Register the hooks (section boot, plugins_loaded:10).
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'register_compat_shortcode' ), 20 );

		if ( Loader::safe_mode()['active'] ) {
			return;
		}

		$index      = Catalog::get();
		self::$plan = Rules::output_plan( $index['snippets'], Loader::enabled_map(), Loader::held( $index ) );

		$has = static function ( array $slots ) {
			foreach ( $slots as $slot ) {
				if ( ! empty( self::$plan[ $slot ] ) ) {
					return true;
				}
			}
			return false;
		};

		if ( $has( array( Rules::SLOT_FRONT_STYLE, Rules::SLOT_FRONT_HEAD, Rules::SLOT_FRONT_FOOT ) ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front' ), 100 );
		}
		if ( $has( array( Rules::SLOT_ADMIN_STYLE, Rules::SLOT_ADMIN_SCRIPT ) ) ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ), 100 );
		}
		if ( $has( array( Rules::SLOT_LOGIN_STYLE, Rules::SLOT_LOGIN_SCRIPT ) ) ) {
			add_action( 'login_enqueue_scripts', array( self::class, 'enqueue_login' ), 100 );
		}
		if ( $has( array( Rules::SLOT_HTML_HEAD ) ) ) {
			add_action( 'wp_head', array( self::class, 'print_head' ) );
		}
		if ( $has( array( Rules::SLOT_HTML_BODY ) ) ) {
			add_action( 'wp_body_open', array( self::class, 'print_body' ) );
		}
		if ( $has( array( Rules::SLOT_HTML_FOOTER ) ) ) {
			add_action( 'wp_footer', array( self::class, 'print_footer' ) );
		}
	}

	/**
	 * Front end: styles, head scripts, footer scripts.
	 */
	public static function enqueue_front() {
		self::style( Rules::SLOT_FRONT_STYLE );
		self::script( Rules::SLOT_FRONT_HEAD, false );
		self::script( Rules::SLOT_FRONT_FOOT, true );
	}

	/**
	 * wp-admin: styles and footer scripts.
	 */
	public static function enqueue_admin() {
		self::style( Rules::SLOT_ADMIN_STYLE );
		self::script( Rules::SLOT_ADMIN_SCRIPT, true );
	}

	/**
	 * wp-login.php: styles and footer scripts.
	 */
	public static function enqueue_login() {
		self::style( Rules::SLOT_LOGIN_STYLE );
		self::script( Rules::SLOT_LOGIN_SCRIPT, true );
	}

	/**
	 * HTML head content.
	 */
	public static function print_head() {
		self::html( Rules::SLOT_HTML_HEAD );
	}

	/**
	 * HTML body-open content.
	 */
	public static function print_body() {
		self::html( Rules::SLOT_HTML_BODY );
	}

	/**
	 * HTML footer content.
	 */
	public static function print_footer() {
		self::html( Rules::SLOT_HTML_FOOTER );
	}

	/**
	 * Inline every CSS snippet of a slot on one empty handle.
	 *
	 * @param string $slot Slot.
	 */
	private static function style( $slot ) {
		if ( empty( self::$plan[ $slot ] ) ) {
			return;
		}

		$handle = self::HANDLES[ $slot ];
		wp_register_style( $handle, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- no file, inline only.
		wp_enqueue_style( $handle );

		foreach ( self::$plan[ $slot ] as $snippet ) {
			$css = self::body( $snippet );
			if ( '' !== trim( $css ) ) {
				wp_add_inline_style( $handle, '/* fh-snippet: ' . $snippet['slug'] . " */\n" . $css );
			}
		}
	}

	/**
	 * Inline every JS snippet of a slot on one empty handle.
	 *
	 * @param string $slot      Slot.
	 * @param bool   $in_footer Print before </body> instead of in <head>.
	 */
	private static function script( $slot, $in_footer ) {
		if ( empty( self::$plan[ $slot ] ) ) {
			return;
		}

		$handle = self::HANDLES[ $slot ];
		wp_register_script( $handle, false, array(), null, $in_footer ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- no file, inline only.
		wp_enqueue_script( $handle );

		foreach ( self::$plan[ $slot ] as $snippet ) {
			$js = self::body( $snippet );
			if ( '' !== trim( $js ) ) {
				wp_add_inline_script( $handle, '/* fh-snippet: ' . $snippet['slug'] . " */\n" . $js );
			}
		}
	}

	/**
	 * Echo every HTML snippet of a slot (as Code Snippets does: newline,
	 * content, newline).
	 *
	 * @param string $slot Slot.
	 */
	private static function html( $slot ) {
		if ( empty( self::$plan[ $slot ] ) ) {
			return;
		}

		foreach ( self::$plan[ $slot ] as $snippet ) {
			echo "\n" . self::body( $snippet ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the snippet IS markup, from a reviewed file in the plugin.
		}
	}

	/**
	 * [fh_snippet slug="…" format shortcodes].
	 *
	 * @param mixed $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = Rules::shortcode_flags( $atts, array( 'format', 'shortcodes' ) );
		$slug = isset( $atts['slug'] ) ? strtolower( trim( (string) $atts['slug'] ) ) : '';

		return self::render( $slug, $atts );
	}

	/**
	 * At init: register [code_snippet id=N] — only while Code Snippets is
	 * NOT loaded (it owns that tag) and at least one HTML snippet names a
	 * Code Snippets id in its Origin.
	 */
	public static function register_compat_shortcode() {
		if ( Loader::code_snippets_active() || shortcode_exists( self::COMPAT_SHORTCODE ) ) {
			return;
		}

		$index = Catalog::get();
		$map   = Rules::shortcode_map( $index['snippets'] );

		if ( ! empty( $map['map'] ) ) {
			add_shortcode( self::COMPAT_SHORTCODE, array( self::class, 'compat_shortcode' ) );
		}
	}

	/**
	 * [code_snippet id=N] → the snippet whose Origin is "Code Snippets #N".
	 *
	 * @param mixed $atts Attributes.
	 * @return string
	 */
	public static function compat_shortcode( $atts ) {
		$atts = Rules::shortcode_flags( $atts, array( 'network', 'php', 'format', 'shortcodes', 'debug' ) );
		$id   = ! empty( $atts['snippet_id'] ) ? (int) $atts['snippet_id'] : ( isset( $atts['id'] ) ? (int) $atts['id'] : 0 );

		$index = Catalog::get();
		$map   = Rules::shortcode_map( $index['snippets'] );

		return self::render( isset( $map['map'][ $id ] ) ? $map['map'][ $id ] : '', $atts );
	}

	/**
	 * Render a shortcode snippet: '' when it is unknown, not a shortcode
	 * snippet, switched off, refused, or safe mode is on (Code Snippets
	 * renders an inactive snippet as '' too).
	 *
	 * @param string $slug Slug.
	 * @param array  $atts Normalised attributes (format, shortcodes).
	 * @return string
	 */
	private static function render( $slug, array $atts ) {
		if ( '' === $slug || Loader::safe_mode()['active'] ) {
			return '';
		}

		$index = Catalog::get();
		if ( ! isset( $index['snippets'][ $slug ] ) ) {
			return '';
		}

		$snippet = $index['snippets'][ $slug ];
		if ( 'html' !== $snippet['type'] || 'shortcode' !== $snippet['scope'] || '' !== (string) $snippet['refused'] ) {
			return '';
		}
		if ( ! Rules::is_enabled( $slug, $snippet['default_on'], Loader::enabled_map() ) ) {
			return '';
		}

		$content = self::body( $snippet );

		if ( ! empty( $atts['format'] ) ) {
			foreach ( array( 'wptexturize', 'convert_smilies', 'convert_chars', 'wpautop', 'capital_P_dangit' ) as $fn ) {
				if ( function_exists( $fn ) ) {
					$content = call_user_func( $fn, $content );
				}
			}
		}

		if ( ! empty( $atts['shortcodes'] ) ) {
			// No recursion: our own tags are off while the inner pass runs.
			$had_compat = shortcode_exists( self::COMPAT_SHORTCODE ) && ! Loader::code_snippets_active();
			remove_shortcode( self::SHORTCODE );
			if ( $had_compat ) {
				remove_shortcode( self::COMPAT_SHORTCODE );
			}

			$content = do_shortcode( ! empty( $atts['format'] ) && function_exists( 'shortcode_unautop' ) ? shortcode_unautop( $content ) : $content );

			add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
			if ( $had_compat ) {
				add_shortcode( self::COMPAT_SHORTCODE, array( self::class, 'compat_shortcode' ) );
			}
		}

		return $content;
	}

	/**
	 * A content snippet's text after its header ('' if it no longer parses).
	 *
	 * @param array $snippet Index entry.
	 * @return string
	 */
	public static function body( array $snippet ) {
		$path = Catalog::path_of( $snippet );
		$text = is_file( $path ) ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.

		return false === $text ? '' : Header::body( $snippet['file'], $text );
	}

	/**
	 * The current plan (tests).
	 *
	 * @return array
	 */
	public static function plan() {
		return self::$plan;
	}
}
