<?php
/**
 * The once-per-person switch on the screens, its notice, and the two
 * links: "Put my old layout back" and "Reset to the FisHotel layout".
 *
 *   - On `add_meta_boxes` at priority 50 — after WooCommerce wrote its own
 *     product layout for a person who had none (priority 40), before any
 *     box is drawn — Switcher::maybe_switch() for the person and screen
 *     (product, legacy order, HPOS order). While the section is off the
 *     same hook gives back a tidied person's own layout instead
 *     (Edit_Screens::boot_disabled()).
 *   - The notice (that screen, until dismissed or undone): "This screen
 *     was tidied: … Put my old layout back · Dismiss".
 *   - Screen Options gets "FisHotel layout: Reset to the FisHotel layout ·
 *     Put my old layout back".
 *   - The links post to admin-post.php (action fishotel_edit_screens, op
 *     undo | reset | dismiss), each with its own nonce, for the person
 *     themself only, and come back to the screen.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tidy
 */
class Tidy {

	/**
	 * The admin-post action and the nonce action (with the op and the
	 * screen).
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_edit_screens';

	/**
	 * The query argument the screen comes back with.
	 *
	 * @var string
	 */
	const DONE = 'fh_es_done';

	/**
	 * The ops.
	 *
	 * @var string[]
	 */
	const OPS = array( 'undo', 'reset', 'dismiss' );

	/**
	 * The switch.
	 *
	 * @var Switcher
	 */
	private $switcher;

	/**
	 * @param Switcher|null $switcher The switch (a new one on the user store when null).
	 */
	public function __construct( $switcher = null ) {
		$this->switcher = $switcher instanceof Switcher ? $switcher : new Switcher( new User_Store() );
	}

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'on_screen' ), 50, 1 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'screen_settings', array( $this, 'screen_settings' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The screen being drawn, as Layouts names it ('' for another).
	 *
	 * @param mixed $screen What add_meta_boxes carries, or a WP_Screen.
	 * @return string
	 */
	public static function screen_id( $screen ) {
		if ( is_object( $screen ) && isset( $screen->id ) ) {
			$screen = $screen->id;
		}
		return Layouts::is_screen( $screen ) ? (string) $screen : '';
	}

	/**
	 * add_meta_boxes: the switch, when due.
	 *
	 * @param mixed $screen Post type (legacy) or screen id (HPOS).
	 */
	public function on_screen( $screen = '' ) {
		$screen = self::screen_id( $screen );
		if ( '' !== $screen ) {
			$this->switcher->maybe_switch( get_current_user_id(), $screen );
		}
	}

	/**
	 * The link of an op on a screen.
	 *
	 * @param string $op     undo | reset | dismiss.
	 * @param string $screen Screen id.
	 * @return string
	 */
	public static function link( $op, $screen ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'op'       => $op,
				'screen'   => $screen,
				'_wpnonce' => wp_create_nonce( self::ACTION . '|' . $op . '|' . $screen ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The notice on a tidied screen, and the line after an undo or a reset.
	 */
	public function notice() {
		$screen = self::screen_id( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
		if ( '' === $screen ) {
			return;
		}
		$uid  = get_current_user_id();
		$done = isset( $_GET[ self::DONE ] ) && is_scalar( $_GET[ self::DONE ] ) ? sanitize_key( wp_unslash( $_GET[ self::DONE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which line to show, nothing is written.

		if ( 'undo' === $done ) {
			echo '<div class="notice notice-success is-dismissible fh-es-notice"><p>' . esc_html__( 'Your old layout of this screen is back. Screen Options → "Reset to the FisHotel layout" tidies it again.', 'fishotel-misc-plugin' ) . '</p></div>';
			return;
		}
		if ( ! $this->switcher->notice_due( $uid, $screen ) ) {
			return;
		}
		$what = Layouts::PRODUCT === $screen
			? __( 'what a fish needs filled first is at the top, what is optional below it, and the rarely used boxes are closed at the bottom.', 'fishotel-misc-plugin' )
			: __( 'the boxes follow an order\'s life — just placed, packing, shipping — and the rarely used ones are closed at the bottom.', 'fishotel-misc-plugin' );
		echo '<div class="notice notice-info fh-es-notice"><p><strong>' . esc_html__( 'This screen was tidied:', 'fishotel-misc-plugin' ) . '</strong> ' . esc_html( $what ) . ' '
			. esc_html__( 'You can still drag the boxes; your own changes are kept from now on.', 'fishotel-misc-plugin' ) . ' '
			. '<a href="' . esc_url( self::link( 'undo', $screen ) ) . '">' . esc_html__( 'Put my old layout back', 'fishotel-misc-plugin' ) . '</a> · '
			. '<a href="' . esc_url( self::link( 'dismiss', $screen ) ) . '">' . esc_html__( 'Dismiss', 'fishotel-misc-plugin' ) . '</a></p></div>';
	}

	/**
	 * Screen Options: the two links.
	 *
	 * @param mixed $settings The screen's own Screen Options markup.
	 * @param mixed $screen   WP_Screen.
	 * @return string
	 */
	public function screen_settings( $settings, $screen = null ) {
		$settings = is_string( $settings ) ? $settings : '';
		$id       = self::screen_id( $screen );
		if ( '' === $id ) {
			return $settings;
		}
		$links = '<a href="' . esc_url( self::link( 'reset', $id ) ) . '">' . esc_html__( 'Reset to the FisHotel layout', 'fishotel-misc-plugin' ) . '</a>';
		if ( null !== $this->switcher->backup( get_current_user_id(), $id ) ) {
			$links .= ' · <a href="' . esc_url( self::link( 'undo', $id ) ) . '">' . esc_html__( 'Put my old layout back', 'fishotel-misc-plugin' ) . '</a>';
		}
		return $settings . '<fieldset class="fh-es-screen-options"><legend>' . esc_html__( 'FisHotel layout', 'fishotel-misc-plugin' ) . '</legend><p>' . $links . '</p></fieldset>';
	}

	/**
	 * What a link does (no output): the op for the person themself.
	 *
	 * @param array $raw Unslashed GET: op, screen, _wpnonce.
	 * @param int   $uid The person.
	 * @return string '' when refused, else the op done.
	 */
	public function run( array $raw, $uid ) {
		$op     = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? (string) $raw['op'] : '';
		$screen = isset( $raw['screen'] ) && is_scalar( $raw['screen'] ) ? (string) $raw['screen'] : '';
		$nonce  = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( (int) $uid < 1 || ! in_array( $op, self::OPS, true ) || ! Layouts::is_screen( $screen ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '|' . $op . '|' . $screen ) ) {
			return '';
		}
		$done = false;
		if ( 'undo' === $op ) {
			$done = $this->switcher->undo( $uid, $screen );
		} elseif ( 'reset' === $op ) {
			$done = $this->switcher->reset( $uid, $screen );
		} else {
			$done = $this->switcher->dismiss( $uid, $screen );
		}
		return $done ? $op : '';
	}

	/**
	 * admin-post: run the op, come back to the screen.
	 */
	public function handle() {
		$raw  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in run().
		$done = $this->run( is_array( $raw ) ? $raw : array(), get_current_user_id() );
		if ( '' === $done ) {
			wp_die( esc_html__( 'This link has expired. Go back, reload the screen and try again.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( self::DONE, $back ) : admin_url( 'edit.php?post_type=product' );
		wp_safe_redirect( add_query_arg( self::DONE, $done, $back ) );
		exit;
	}
}
