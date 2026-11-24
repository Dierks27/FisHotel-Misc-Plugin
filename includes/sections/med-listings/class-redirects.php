<?php
/**
 * Med listings — the redirect list for category and tag addresses the
 * cleanup changed or removed.
 *
 * WordPress keeps a product's old address when its slug changes, but not
 * a category's: a renamed or removed category's old address is a 404.
 * Every such change made by the Categories step adds an entry here, and
 * a visit to the old address (its /page/N/ pages and its feeds too, and
 * the ?product_cat=old form) is sent on with a 301 to where its products
 * went. The list stays and keeps working when the section is switched
 * off (Med_Listings::boot_disabled() keeps the hook), because the old
 * addresses live on in search engines and other sites long after the
 * cleanup is done. An entry can be taken off on the page.
 *
 * An old address that is in use again (a new category was later given
 * the old slug) is left alone.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Redirects
 */
class Redirects {

	/**
	 * Option that holds the list (small; loaded on every visit, so autoloaded).
	 *
	 * @var string
	 */
	const OPTION = 'fishotel_med_listings_redirects';

	/**
	 * The query variable each taxonomy answers to (?product_cat=slug).
	 *
	 * @var array<string, string>
	 */
	const QUERY_VARS = array(
		'product_cat' => 'product_cat',
		'product_tag' => 'product_tag',
	);

	/**
	 * Register the hook (early on template_redirect, before WordPress's
	 * own canonical guesses).
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * The list.
	 *
	 * @return array<int, array>
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Store the list.
	 *
	 * @param array $list Entries.
	 */
	public static function save( array $list ) {
		update_option( self::OPTION, array_values( $list ), true );
	}

	/**
	 * Add an entry (replacing one from the same old address).
	 *
	 * @param array $entry from, tax, slug, to_term, to_tax, to_url, change.
	 */
	public static function add( array $entry ) {
		$entry['from'] = self::normalise_path( $entry['from'] ?? '' );
		if ( '' === $entry['from'] || '/' === $entry['from'] ) {
			return;
		}
		$list = array();
		foreach ( self::all() as $e ) {
			if ( ( $e['from'] ?? '' ) !== $entry['from'] ) {
				$list[] = $e;
			}
		}
		$entry['at'] = time();
		$list[]      = $entry;
		self::save( $list );
	}

	/**
	 * Take off every entry a change made (on undo), or one by its old address.
	 *
	 * @param string $change_id Change id ('' to match by address).
	 * @param string $from      Old address.
	 * @return int How many were taken off.
	 */
	public static function remove( $change_id, $from = '' ) {
		$from = '' !== $from ? self::normalise_path( $from ) : '';
		$keep = array();
		$gone = 0;
		foreach ( self::all() as $e ) {
			$hit = ( '' !== $change_id && ( $e['change'] ?? '' ) === $change_id ) || ( '' !== $from && ( $e['from'] ?? '' ) === $from );
			if ( $hit ) {
				$gone++;
			} else {
				$keep[] = $e;
			}
		}
		if ( $gone ) {
			self::save( $keep );
		}
		return $gone;
	}

	/**
	 * A path as compared: lower case, one leading and one trailing slash,
	 * no query.
	 *
	 * @param string $path Path or full address.
	 * @return string
	 */
	public static function normalise_path( $path ) {
		$path = (string) $path;
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) ) {
			$path = (string) parse_url( $path, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure.
		}
		$path = strtolower( rawurldecode( (string) strtok( $path, '?#' ) ) );
		$path = '/' . trim( $path, '/' ) . '/';
		return '//' === $path ? '/' : $path;
	}

	/**
	 * Which entry a request is for, and the part after the old address
	 * that goes along ("page/2/", "feed/", "feed/atom/"); null when none.
	 *
	 * Pure: the caller passes the path and the query.
	 *
	 * @param string $path  Request path.
	 * @param array  $query Query arguments ($_GET).
	 * @param array  $list  Entries.
	 * @return array{entry: array, suffix: string}|null
	 */
	public static function find_match( $path, array $query, array $list ) {
		$path = self::normalise_path( $path );
		foreach ( $list as $e ) {
			$from = self::normalise_path( $e['from'] ?? '' );
			if ( '/' === $from ) {
				continue;
			}
			if ( $path === $from ) {
				return array(
					'entry'  => $e,
					'suffix' => '',
				);
			}
			if ( 0 === strpos( $path, $from ) ) {
				$rest = substr( $path, strlen( $from ) );
				if ( preg_match( '#^(?:page/\d+/|feed/(?:(?:rss2|rss|rdf|atom)/)?|(?:rss2|rss|rdf|atom)/)$#', $rest ) || ! empty( $e['keep_rest'] ) ) {
					// A renamed category also carries the categories under it ("old/child/" → "new/child/").
					return array(
						'entry'  => $e,
						'suffix' => $rest,
					);
				}
			}
		}
		foreach ( self::QUERY_VARS as $tax => $var ) {
			if ( empty( $query[ $var ] ) || ! is_string( $query[ $var ] ) ) {
				continue;
			}
			$slug = strtolower( trim( $query[ $var ] ) );
			foreach ( $list as $e ) {
				if ( ( $e['tax'] ?? '' ) === $tax && strtolower( (string) ( $e['slug'] ?? '' ) ) === $slug ) {
					return array(
						'entry'  => $e,
						'suffix' => '',
					);
				}
			}
		}
		return null;
	}

	/**
	 * A product address that went missing because a category in it was
	 * renamed or removed (the shop's product addresses carry the
	 * category: /shop/medications/antiparasitic/prazi-powder/): the
	 * product's slug, '' when the path holds no old category (pure).
	 *
	 * @param string $path Request path.
	 * @param array  $list Entries.
	 * @return string
	 */
	public static function product_slug_for( $path, array $list ) {
		$path = self::normalise_path( $path );
		foreach ( $list as $e ) {
			$seg = strtolower( trim( (string) ( $e['segment'] ?? '' ), '/' ) );
			if ( '' === $seg ) {
				continue;
			}
			$pos = strpos( $path, '/' . $seg . '/' );
			if ( false === $pos ) {
				continue;
			}
			$rest = trim( substr( $path, $pos + strlen( $seg ) + 2 ), '/' );
			$rest = preg_replace( '#/\d+$#', '', $rest ); // A page number after the product.
			if ( '' !== $rest && false === strpos( $rest, '/' ) && ! preg_match( '#^(?:page|feed)$#', $rest ) ) {
				return $rest;
			}
		}
		return '';
	}

	/**
	 * Where an entry sends a visitor now: the category's current address
	 * when it still exists (it may have been renamed again since), the
	 * address stored at the time otherwise.
	 *
	 * @param array $entry Entry.
	 * @return string '' when there is nowhere to go.
	 */
	public static function target_url( array $entry ) {
		$id  = (int) ( $entry['to_term'] ?? 0 );
		$tax = (string) ( $entry['to_tax'] ?? 'product_cat' );
		if ( $id && function_exists( 'get_term_link' ) ) {
			$link = get_term_link( $id, $tax );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		return (string) ( $entry['to_url'] ?? '' );
	}

	/**
	 * The address with the old address's page or feed part put on
	 * (pure).
	 *
	 * @param string $target Target address.
	 * @param string $suffix "page/2/" / "feed/" / ''.
	 * @return string
	 */
	public static function with_suffix( $target, $suffix ) {
		if ( '' === $suffix || '' === $target ) {
			return $target;
		}
		$parts = explode( '?', $target, 2 );
		$base  = rtrim( $parts[0], '/' ) . '/' . ltrim( $suffix, '/' );
		return $base . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
	}

	/**
	 * template_redirect: send an old address on.
	 */
	public static function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}
		$list = self::all();
		if ( ! $list ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
		$hit  = self::find_match( (string) $uri, wp_unslash( $_GET ), $list ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public address.
		if ( ! $hit ) {
			if ( is_404() ) {
				$slug = self::product_slug_for( (string) $uri, $list );
				$post = '' !== $slug ? get_page_by_path( $slug, OBJECT, 'product' ) : null;
				if ( $post && 'publish' === $post->post_status ) {
					wp_safe_redirect( get_permalink( $post ), 301, 'FisHotel Med listings' );
					exit;
				}
			}
			return;
		}
		$tax  = (string) ( $hit['entry']['tax'] ?? 'product_cat' );
		$slug = (string) ( $hit['entry']['slug'] ?? '' );
		if ( '' !== $slug && function_exists( 'get_term_by' ) ) {
			$now = get_term_by( 'slug', $slug, $tax );
			if ( $now && ! is_wp_error( $now ) ) {
				$link = get_term_link( $now );
				if ( is_string( $link ) && self::normalise_path( $link ) === self::normalise_path( $hit['entry']['from'] ) ) {
					return; // The old address is in use again.
				}
			}
		}
		$to = self::with_suffix( self::target_url( $hit['entry'] ), $hit['suffix'] );
		if ( '' === $to || self::normalise_path( $to ) === self::normalise_path( $uri ) ) {
			return;
		}
		wp_safe_redirect( $to, 301, 'FisHotel Med listings' );
		exit;
	}
}
