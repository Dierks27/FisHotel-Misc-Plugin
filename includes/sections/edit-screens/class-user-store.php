<?php
/**
 * The user meta the switch reads and writes — the one file of the section
 * that writes user meta.
 *
 * WordPress keeps a person's layout of a screen as plain user meta
 * (`meta-box-order_product`, …: what dragging, closing and Screen Options
 * write) but reads it with get_user_option(), which prefers a copy under
 * the site's table prefix (`wp_meta-box-order_product`) when one exists.
 * So a layout part is read and put back as a pair — plain, prefixed —
 * and the FisHotel layout is written plain with the prefixed copy removed,
 * so what is written is what WordPress reads.
 *
 * @package FisHotel\Misc\Sections\Edit_Screens
 */

namespace FisHotel\Misc\Sections\Edit_Screens;

defined( 'ABSPATH' ) || exit;

/**
 * Class User_Store
 */
class User_Store {

	/**
	 * The site's prefix of a user option.
	 *
	 * @return string
	 */
	private function prefix() {
		global $wpdb;
		return is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? (string) $wpdb->get_blog_prefix() : '';
	}

	/**
	 * One value, or null when it is not stored at all.
	 *
	 * @param int    $uid User ID.
	 * @param string $key Meta key.
	 * @return mixed|null
	 */
	private function one( $uid, $key ) {
		if ( '' === $key || ! metadata_exists( 'user', $uid, $key ) ) {
			return null;
		}
		return get_user_meta( $uid, $key, true );
	}

	/**
	 * A layout part as a pair.
	 *
	 * @param int    $uid User ID.
	 * @param string $key The part's key (unprefixed).
	 * @return array{plain: mixed, prefixed: mixed}
	 */
	public function read( $uid, $key ) {
		$prefix = $this->prefix();
		return array(
			'plain'    => $this->one( (int) $uid, $key ),
			'prefixed' => '' !== $prefix ? $this->one( (int) $uid, $prefix . $key ) : null,
		);
	}

	/**
	 * Write a layout part where WordPress reads it.
	 *
	 * @param int    $uid   User ID.
	 * @param string $key   The part's key (unprefixed).
	 * @param mixed  $value The value.
	 */
	public function write( $uid, $key, $value ) {
		update_user_meta( (int) $uid, $key, $value );
		$prefix = $this->prefix();
		if ( '' !== $prefix ) {
			delete_user_meta( (int) $uid, $prefix . $key );
		}
	}

	/**
	 * Put a pair back exactly: a null half is deleted.
	 *
	 * @param int    $uid  User ID.
	 * @param string $key  The part's key (unprefixed).
	 * @param array  $pair plain, prefixed.
	 */
	public function put_back( $uid, $key, array $pair ) {
		$prefix = $this->prefix();
		$halves = array( $key => $pair['plain'] ?? null );
		if ( '' !== $prefix ) {
			$halves[ $prefix . $key ] = $pair['prefixed'] ?? null;
		}
		foreach ( $halves as $meta_key => $value ) {
			if ( null === $value ) {
				delete_user_meta( (int) $uid, $meta_key );
			} else {
				update_user_meta( (int) $uid, $meta_key, $value );
			}
		}
	}

	/**
	 * This section's own user meta.
	 *
	 * @param int    $uid User ID.
	 * @param string $key Switcher::STATE or Switcher::BACKUP.
	 * @return mixed
	 */
	public function get( $uid, $key ) {
		return get_user_meta( (int) $uid, $key, true );
	}

	/**
	 * Store this section's own user meta.
	 *
	 * @param int    $uid   User ID.
	 * @param string $key   Switcher::STATE or Switcher::BACKUP.
	 * @param mixed  $value The value.
	 */
	public function set( $uid, $key, $value ) {
		update_user_meta( (int) $uid, $key, $value );
	}
}
