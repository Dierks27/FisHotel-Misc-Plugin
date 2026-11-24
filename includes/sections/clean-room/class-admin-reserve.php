<?php
/**
 * Clean Room reserved fish — the To do tab's corrections (wp-admin):
 * "It was tank…" and "Ignore" / "Ignore all" on the not-found list,
 * "Pulled from a different tank" on a pull row. Each is one admin-post
 * form; the work is Reserve_Shop::act().
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
	 * One correction, then back to the To do tab with what was done.
	 */
	public static function admin_post() {
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), 403 );
		}
		check_admin_referer( Reserve_Shop::ACTION );
		$raw     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$message = Reserve_Shop::act( new Store(), Settings::get(), is_array( $raw ) ? $raw : array() );
		Admin_Workbook::flash( $message );
		wp_safe_redirect( Admin::page_url( Admin_Temp::TAB ) );
		exit;
	}
}
