<?php
/**
 * Dark admin — where WordPress is touched. Every decision is Rules'; this
 * file only asks WordPress the facts (who is looking, which screen) and
 * hands back what Rules says.
 *
 *   admin_init                     registers the "FisHotel dark" colour scheme
 *   get_user_option_admin_color    uses it on a dark screen (never inside the picker)
 *   admin_color_scheme_picker      marks the picker being drawn (priorities 9 / 11)
 *   admin_body_class               adds fh-dark-admin to <body>
 *   admin_enqueue_scripts          dark-admin.css + the readability guard (js/dark-admin.js)
 *   mce_css, tiny_mce_before_init  the classic editor's writing area
 *   personal_options               the "Dark admin" tick on Profile / Edit User
 *   personal_options_update,
 *   edit_user_profile_update       saves it
 *
 * None of these is added outside wp-admin (Dark_Admin::boot() checks
 * is_admin()), so the storefront, its admin bar and the login page never
 * see a dark file.
 *
 * @package FisHotel\Misc\Sections\Dark_Admin
 */

namespace FisHotel\Misc\Sections\Dark_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Hooks
 */
class Hooks {

	/**
	 * The colour-scheme picker is being drawn.
	 *
	 * @var bool
	 */
	private static $in_picker = false;

	/**
	 * Hook everything (wp-admin requests only).
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_scheme' ) );
		add_filter( 'get_user_option_admin_color', array( __CLASS__, 'admin_color' ), 10, 3 );
		add_action( 'admin_color_scheme_picker', array( __CLASS__, 'picker_start' ), 9 );
		add_action( 'admin_color_scheme_picker', array( __CLASS__, 'picker_end' ), 11 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 100 );
		add_filter( 'mce_css', array( __CLASS__, 'editor_css' ) );
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'editor_init' ) );
		add_action( 'personal_options', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
	}

	/**
	 * Does this user want it (their own switch)?
	 *
	 * @param int $user_id A user id (0 = nobody logged in).
	 * @return bool
	 */
	public static function user_wants( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && Rules::wants_dark( get_user_meta( $user_id, Rules::META_KEY, true ) );
	}

	/**
	 * Is the current screen one that turns dark?
	 *
	 * @return bool
	 */
	public static function screen_is_dark() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return Rules::screen_is_dark( array(
			'is_admin'          => is_admin(),
			'pagenow'           => isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '',
			'is_block_editor'   => $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor(),
			'customize_preview' => function_exists( 'is_customize_preview' ) && is_customize_preview(),
		) );
	}

	/**
	 * Dark for the person looking, on this screen.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::user_wants( get_current_user_id() ) && self::screen_is_dark();
	}

	/**
	 * Register "FisHotel dark" next to WordPress's own schemes.
	 */
	public static function register_scheme() {
		if ( ! function_exists( 'wp_admin_css_color' ) ) {
			return;
		}
		wp_admin_css_color(
			Rules::SCHEME,
			__( 'FisHotel dark', 'fishotel-misc-plugin' ),
			Dark_Admin::url() . 'css/scheme.css?ver=' . rawurlencode( FISHOTEL_MISC_VERSION ),
			array( '#1a1a1a', '#2d2d2d', '#00789f', '#0eb5e8' ),
			array(
				'base'    => '#a0a0a0',
				'focus'   => '#0eb5e8',
				'current' => '#ffffff',
			)
		);
	}

	/**
	 * The admin colour scheme a user is drawn with.
	 *
	 * @param mixed  $result The stored choice.
	 * @param string $option 'admin_color'.
	 * @param object $user   The user it is read for.
	 * @return mixed
	 */
	public static function admin_color( $result, $option = 'admin_color', $user = null ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : get_current_user_id();
		$dark    = self::user_wants( $user_id ) && self::screen_is_dark();

		return Rules::scheme( $dark, $result, self::$in_picker );
	}

	/**
	 * The picker starts: show the stored choice.
	 */
	public static function picker_start() {
		self::$in_picker = true;
	}

	/**
	 * The picker is drawn.
	 */
	public static function picker_end() {
		self::$in_picker = false;
	}

	/**
	 * Add the dark class to the admin <body>.
	 *
	 * @param string $classes The classes so far.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::active() ? Rules::add_class( $classes ) : $classes;
	}

	/**
	 * The dark layer and the readability guard.
	 */
	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'fishotel-dark-admin', Dark_Admin::url() . 'css/dark-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-dark-admin', Dark_Admin::url() . 'js/dark-admin.js', array(), FISHOTEL_MISC_VERSION, true );
	}

	/**
	 * The classic editor's writing area: our sheet after the theme's.
	 *
	 * @param string $list Comma-separated style sheet URLs.
	 * @return string
	 */
	public static function editor_css( $list ) {
		if ( ! self::active() ) {
			return $list;
		}
		return Rules::add_editor_css( $list, Dark_Admin::url() . 'css/editor-content.css?ver=' . rawurlencode( FISHOTEL_MISC_VERSION ) );
	}

	/**
	 * The classic editor's writing area: the class the sheet hangs on.
	 *
	 * @param array $init TinyMCE settings.
	 * @return array
	 */
	public static function editor_init( $init ) {
		if ( ! is_array( $init ) || ! self::active() ) {
			return $init;
		}
		$init['body_class'] = trim( Rules::add_class( isset( $init['body_class'] ) ? $init['body_class'] : '' ) );
		return $init;
	}

	/**
	 * The "Dark admin" tick, under Personal Options on Profile / Edit User.
	 *
	 * @param \WP_User $user The user whose profile this is.
	 */
	public static function profile_field( $user ) {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		$on      = Rules::wants_dark( get_user_meta( $user_id, Rules::META_KEY, true ) );
		?>
		<tr class="user-fishotel-dark-admin-wrap">
			<th scope="row"><?php esc_html_e( 'Dark admin', 'fishotel-misc-plugin' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( Rules::SENT ); ?>" value="1" />
				<label for="<?php echo esc_attr( Rules::FIELD ); ?>">
					<input type="checkbox" name="<?php echo esc_attr( Rules::FIELD ); ?>" id="<?php echo esc_attr( Rules::FIELD ); ?>" value="1" <?php checked( $on ); ?> />
					<?php esc_html_e( 'Show every wp-admin screen dark (the FisHotel Tools look)', 'fishotel-misc-plugin' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Only for this person. Untick it to get WordPress\'s own light screens back. While it is ticked the menu uses "FisHotel dark"; the colour scheme picked above comes back when it is unticked.', 'fishotel-misc-plugin' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the tick (WordPress has already checked the profile form's nonce).
	 *
	 * @param int $user_id The user being saved.
	 */
	public static function profile_save( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- user-edit.php checked update-user_{id} before this action.
		$value = Rules::meta_to_save( wp_unslash( $_POST ) );
		if ( null !== $value ) {
			update_user_meta( $user_id, Rules::META_KEY, $value );
		}
	}
}
