<?php
/**
 * Clean Room reserved fish — the To do tab's corrections (wp-admin):
 * "It was tank…" and "Ignore" / "Ignore all" on the not-found list,
 * "Pulled from a different tank" on a pull row — and the holds by hand:
 * "Reserve…" and "Release" on a Board tank card, "Release", "Give them
 * the held fish" and "Not this order" on the To do tab. Each is one
 * admin-post form; the work is Reserve_Shop::act().
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Reserve
 */
class Admin_Reserve {

	/**
	 * Register the form handler.
	 */
	public static function init() {
		add_action( 'admin_post_' . Reserve_Shop::ACTION, array( self::class, 'admin_post' ) );
	}

	/**
	 * The URL a form posts to.
	 *
	 * @return string
	 */
	public static function form_url() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * An order's edit screen (the orders tables' when they are in use).
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public static function order_url( $order_id ) {
		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $util ) && method_exists( $util, 'get_order_admin_edit_url' ) ) {
			try {
				return (string) $util::get_order_admin_edit_url( (int) $order_id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}

	/**
	 * One correction, then back to the To do tab with what was done.
	 */
	public static function admin_post() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), 403 );
		}
		check_admin_referer( Reserve_Shop::ACTION );
		$raw     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$result = Reserve_Shop::act_result( new Store(), Settings::get(), is_array( $raw ) ? $raw : array() );
		Admin_Workbook::flash( $result['message'], ! empty( $result['ok'] ) ); // Green when done, yellow when refused.
		// Back where the button was: the Board (Reserve… / Release on a tank card) or the To do tab.
		$back = is_array( $raw ) && isset( $raw['back'] ) && 'board' === $raw['back'] ? Admin::page_url( 'board' ) : Admin::page_url( Admin_Temp::TAB );
		wp_safe_redirect( $back );
		exit;
	}
}
