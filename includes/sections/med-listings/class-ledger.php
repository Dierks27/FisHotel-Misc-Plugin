<?php
/**
 * Med listings — Apply and Undo, and the record that makes Undo exact.
 *
 * Every field Apply writes is recorded first: the product, the post the
 * field sits on (the product, a variation or an image), the field, the
 * value it had (and whether it was there at all), the value written,
 * when and by whom. A second Apply on the same field keeps the first
 * old value, so Undo always goes back to what was there before the
 * tool ever touched it.
 *
 * Undo writes each old value back — but only where the field still
 * holds what the tool wrote. A field someone changed since is left as
 * it is and named, so an Undo never wipes later work.
 *
 * The record is one option, not autoloaded (it is read on this page
 * only).
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ledger
 */
class Ledger {

	/**
	 * Option that holds the record.
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_med_listings_ledger';

	/**
	 * The whole record: products => [pid => [key => entry]], terms => [id => change], reviews => [cid => entry].
	 *
	 * @return array
	 */
	public static function all() {
		$l = get_option( self::OPTION, array() );
		$l = is_array( $l ) ? $l : array();
		return array_merge(
			array(
				'products' => array(),
				'terms'    => array(),
				'reviews'  => array(),
			),
			$l
		);
	}

	/**
	 * Store the record.
	 *
	 * @param array $l Record.
	 */
	public static function save( array $l ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $l, '', false );
		} else {
			update_option( self::OPTION, $l, false );
		}
	}

	/**
	 * One product's entries (key "object|field" => entry).
	 *
	 * @param int $pid Product id.
	 * @return array
	 */
	public static function for_product( $pid ) {
		$l = self::all();
		return isset( $l['products'][ (int) $pid ] ) && is_array( $l['products'][ (int) $pid ] ) ? $l['products'][ (int) $pid ] : array();
	}

	/**
	 * key => the value the tool wrote, for Proposals (a field changed by
	 * the tool and untouched since is not proposed again).
	 *
	 * @param int $pid Product id.
	 * @return array
	 */
	public static function written( $pid ) {
		$out = array();
		foreach ( self::for_product( $pid ) as $key => $e ) {
			$out[ $key ] = $e['new'];
		}
		return $out;
	}

	/**
	 * Whether a post id belongs to a product: the product itself, one of
	 * its variations, or one of its pictures (main, gallery, a
	 * variation's).
	 *
	 * @param int   $object  Post id.
	 * @param array $allowed Ids the product owns (Inventory::owned_ids()).
	 * @return bool
	 */
	public static function owns( $object, array $allowed ) {
		return in_array( (int) $object, array_map( 'intval', $allowed ), true );
	}

	/**
	 * Apply rows to one product.
	 *
	 * Each row: object, field, value (the text to write), hash (of the
	 * field as the review table saw it). A row is skipped, with the
	 * reason, when the field is not one this tool writes, the post is not
	 * the product's, the text still holds a "[[ … ]]" blank, or the field
	 * changed since the table was drawn.
	 *
	 * @param int   $pid     Product id.
	 * @param array $rows    Rows.
	 * @param array $allowed Ids the product owns.
	 * @return array<int, array{key: string, ok: bool, message: string}>
	 */
	public static function apply( $pid, array $rows, array $allowed ) {
		$pid    = (int) $pid;
		$result = array();
		$l      = self::all();
		$mine   = isset( $l['products'][ $pid ] ) && is_array( $l['products'][ $pid ] ) ? $l['products'][ $pid ] : array();
		$user   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $rows as $row ) {
			$object = (int) ( $row['object'] ?? 0 );
			$field  = (string) ( $row['field'] ?? '' );
			$value  = (string) ( $row['value'] ?? '' );
			$key    = $object . '|' . $field;

			if ( ! Fields::allowed( $field ) || ! self::owns( $object, $allowed ) ) {
				$result[] = array( 'key' => $key, 'ok' => false, 'message' => __( 'Not a field of this product that this tool changes.', 'fishotel-misc-plugin' ) );
				continue;
			}
			if ( false !== strpos( $value, '[[' ) ) {
				$result[] = array( 'key' => $key, 'ok' => false, 'message' => __( 'Fill in the [[ … ]] blank first.', 'fishotel-misc-plugin' ) );
				continue;
			}
			$before = Fields::read( $object, $field );
			if ( isset( $row['hash'] ) && '' !== (string) $row['hash'] && Fields::hash( $before ) !== (string) $row['hash'] ) {
				$result[] = array( 'key' => $key, 'ok' => false, 'message' => __( 'Changed since this page was opened — reload the page and look again.', 'fishotel-misc-plugin' ) );
				continue;
			}
			$new    = Fields::input_to_raw( $field, $value );
			$exists = ! ( 0 === strpos( $field, 'attr:' ) );
			$done   = Fields::write( $object, $field, $new, $exists );
			if ( true !== $done ) {
				$result[] = array( 'key' => $key, 'ok' => false, 'message' => (string) $done );
				continue;
			}
			$after = Fields::read( $object, $field );
			if ( isset( $mine[ $key ] ) ) {
				$mine[ $key ]['new'] = $after['raw'];
				$mine[ $key ]['at']  = time();
			} else {
				$mine[ $key ] = array(
					'object'     => $object,
					'field'      => $field,
					'old'        => $before['raw'],
					'old_exists' => (bool) $before['exists'],
					'old_text'   => (string) $before['text'],
					'new'        => $after['raw'],
					'new_exists' => (bool) $after['exists'],
					'at'         => time(),
					'user'       => $user,
				);
			}
			$mine[ $key ]['new_text'] = (string) $after['text'];
			$result[]                 = array( 'key' => $key, 'ok' => true, 'message' => '' );
		}

		$l['products'][ $pid ] = $mine;
		self::save( $l );
		self::touch( $pid );
		return $result;
	}

	/**
	 * Undo one product: every recorded field back to its old value,
	 * last change first. Fields changed by someone since are kept and
	 * named.
	 *
	 * @param int $pid Product id.
	 * @return array{restored: int, kept: string[]}
	 */
	public static function undo( $pid ) {
		$pid  = (int) $pid;
		$l    = self::all();
		$mine = isset( $l['products'][ $pid ] ) && is_array( $l['products'][ $pid ] ) ? $l['products'][ $pid ] : array();
		$left = array();
		$kept = array();
		$done = 0;

		foreach ( array_reverse( $mine, true ) as $key => $e ) {
			$now = Fields::read( (int) $e['object'], (string) $e['field'] );
			if ( ! Fields::same( $now['raw'], $e['new'] ) || (bool) $now['exists'] !== (bool) ( $e['new_exists'] ?? true ) ) {
				$kept[]       = $key;
				$left[ $key ] = $e;
				continue;
			}
			$ok = Fields::write( (int) $e['object'], (string) $e['field'], $e['old'], (bool) $e['old_exists'] );
			if ( true === $ok ) {
				$done++;
			} else {
				$kept[]       = $key;
				$left[ $key ] = $e;
			}
		}

		if ( $left ) {
			$l['products'][ $pid ] = array_reverse( $left, true );
		} else {
			unset( $l['products'][ $pid ] );
		}
		self::save( $l );
		self::touch( $pid );
		return array(
			'restored' => $done,
			'kept'     => $kept,
		);
	}

	/**
	 * Hide or show a review again; the status it had is recorded so
	 * "Show again" puts back exactly that.
	 *
	 * @param int  $comment_id Comment id.
	 * @param bool $hide       True to hide (hold for moderation), false to put back.
	 * @return bool
	 */
	public static function review( $comment_id, $hide ) {
		$comment_id = (int) $comment_id;
		$l          = self::all();
		if ( $hide ) {
			$now = wp_get_comment_status( $comment_id );
			if ( false === $now ) {
				return false;
			}
			if ( ! isset( $l['reviews'][ $comment_id ] ) ) {
				$l['reviews'][ $comment_id ] = array(
					'old' => $now,
					'at'  => time(),
				);
			}
			$ok = (bool) wp_set_comment_status( $comment_id, 'hold' );
		} else {
			if ( ! isset( $l['reviews'][ $comment_id ] ) ) {
				return false;
			}
			$old = (string) $l['reviews'][ $comment_id ]['old'];
			$ok  = (bool) wp_set_comment_status( $comment_id, 'approved' === $old ? 'approve' : ( 'unapproved' === $old ? 'hold' : $old ) );
			if ( $ok ) {
				unset( $l['reviews'][ $comment_id ] );
			}
		}
		self::save( $l );
		return $ok;
	}

	/**
	 * Clear the caches a product's page is built from.
	 *
	 * @param int $pid Product id.
	 */
	private static function touch( $pid ) {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $pid );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
	}
}
