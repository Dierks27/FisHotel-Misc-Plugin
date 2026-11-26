<?php
/**
 * Med listings — the Verify step: clear the caches, then open every
 * medication page the way a visitor gets it and read what it says.
 *
 * Caches: Breeze (the Cloudways cache plugin) is asked to clear
 * everything, which also clears Varnish when Breeze's Varnish option is
 * on (breeze_clear_all_cache; Breeze 2.6.5 inc/breeze-admin.php), then
 * Varnish on its own (breeze_clear_varnish). Each is called only when
 * Breeze has it. The theme's own list of calculator / scheduler links
 * (FisHotel_Tool_Product_Links::flush_cache) and WooCommerce's product
 * caches are cleared too. When Breeze is not there the page says to use
 * "Purge Varnish" in Cloudways.
 *
 * Pages: each medication product page, each medication category page,
 * and the tool pages (Dosing Calculator, QT Scheduler, quarantine
 * guides). The site fetches each one from itself, logged out, with a
 * cache-busting query, and the HTML is read where words reach a visitor
 * or a search engine: the visible text, the <title>, every meta tag's
 * content (description, Open Graph, Twitter), the JSON-LD blocks
 * (WooCommerce's product data, review texts included), picture alt
 * text, the variation data in the page source, and every link.
 *
 * A product or category page fails on a disease, claim, dosing or
 * medicine-type word, or a link to the calculator, QT pages, blog or
 * forum. A tool page fails when it links to or shows a medication
 * product or category. Words that are on the home page too are in the
 * header or footer of every page: they are listed once as "on every
 * page", not failed on each.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Verify
 */
class Verify {

	/**
	 * Clear every cache this site lets a plugin clear.
	 *
	 * @param int[] $pids Product ids whose WooCommerce caches go too.
	 * @return array{done: string[], varnish: bool}
	 */
	public static function purge( array $pids ) {
		$done    = array();
		$varnish = false;

		if ( class_exists( '\FisHotel_Tool_Product_Links' ) && method_exists( '\FisHotel_Tool_Product_Links', 'flush_cache' ) ) {
			\FisHotel_Tool_Product_Links::flush_cache();
			$done[] = __( 'The theme\'s calculator / scheduler link list', 'fishotel-misc-plugin' );
		}
		delete_transient( 'fishotel_tool_med_links_v3' );

		foreach ( $pids as $pid ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $pid );
			}
			clean_post_cache( (int) $pid );
		}
		if ( $pids ) {
			$done[] = __( 'WooCommerce\'s product caches', 'fishotel-misc-plugin' );
		}

		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$done[] = __( 'Breeze: all cached pages', 'fishotel-misc-plugin' );
		} elseif ( class_exists( '\Breeze_PurgeCache' ) && method_exists( '\Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
			\Breeze_PurgeCache::breeze_cache_flush();
			$done[] = __( 'Breeze: all cached pages', 'fishotel-misc-plugin' );
		}
		if ( has_action( 'breeze_clear_varnish' ) ) {
			do_action( 'breeze_clear_varnish' );
			$done[]  = __( 'Varnish (through Breeze)', 'fishotel-misc-plugin' );
			$varnish = true;
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$done[] = __( 'WordPress object cache', 'fishotel-misc-plugin' );
		}
		return array(
			'done'    => $done,
			'varnish' => $varnish,
		);
	}

	/**
	 * The pages to check: [url, kind (product|category|tool|home), label].
	 *
	 * @param int[] $pids Product ids.
	 * @return array<int, array{url: string, kind: string, label: string}>
	 */
	public static function pages( array $pids ) {
		$out   = array();
		$out[] = array(
			'url'   => home_url( '/' ),
			'kind'  => 'home',
			'label' => __( 'Home page — for information only: your fish service and the header and footer, not a product listing. Words found here are not counted against the other pages.', 'fishotel-misc-plugin' ),
		);
		foreach ( $pids as $pid ) {
			if ( 'publish' !== get_post_status( (int) $pid ) ) {
				continue;
			}
			$out[] = array(
				'url'   => (string) get_permalink( (int) $pid ),
				'kind'  => 'product',
				'label' => (string) get_the_title( (int) $pid ),
			);
		}
		$terms = array();
		foreach ( Inventory::med_term_ids() as $tid ) {
			$terms[ $tid ] = true;
		}
		foreach ( array_keys( Terms::TARGETS ) as $type ) {
			$t = Terms::target_term( $type );
			if ( $t ) {
				$terms[ (int) $t->term_id ] = true;
			}
		}
		foreach ( array_keys( $terms ) as $tid ) {
			$t    = get_term( (int) $tid, 'product_cat' );
			$link = $t && ! is_wp_error( $t ) ? get_term_link( $t ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$out[] = array(
					'url'   => $link,
					'kind'  => 'category',
					'label' => sprintf( /* translators: %s: category */ __( 'Category: %s', 'fishotel-misc-plugin' ), $t->name ),
				);
			}
		}
		foreach ( Inventory::tool_page_urls() as $url ) {
			$out[] = array(
				'url'   => $url,
				'kind'  => 'tool',
				'label' => sprintf( /* translators: %s: address */ __( 'Tool page: %s', 'fishotel-misc-plugin' ), (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			);
		}
		return $out;
	}

	/**
	 * Open one page from the site itself.
	 *
	 * @param string $url Address.
	 * @return array{ok: bool, html: string, error: string, code: int}
	 */
	public static function fetch( $url ) {
		$bust = add_query_arg( 'fh_med_check', (string) time() . wp_rand( 100, 999 ), $url );
		$res  = wp_remote_get(
			$bust,
			array(
				'timeout'     => 25,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter.
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'html'  => '',
				'error' => $res->get_error_message(),
				'code'  => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array(
			'ok'    => $code >= 200 && $code < 400,
			'html'  => (string) wp_remote_retrieve_body( $res ),
			'error' => $code >= 200 && $code < 400 ? '' : sprintf( /* translators: %d: HTTP status */ __( 'The page answered %d.', 'fishotel-misc-plugin' ), $code ),
			'code'  => $code,
		);
	}

	/**
	 * The texts of a page a visitor or a search engine reads (pure).
	 *
	 * @param string $html Page.
	 * @return array<string, string[]> where => texts
	 */
	public static function texts( $html ) {
		$html = (string) $html;
		$out  = array(
			'JSON-LD'        => array(),
			'meta tag'       => array(),
			'title'          => array(),
			'alt text'       => array(),
			'variation data' => array(),
			'page text'      => array(),
		);
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $block ) {
				$data                = json_decode( trim( $block ), true );
				$out['JSON-LD'][]    = is_array( $data ) ? implode( ' · ', self::strings( $data ) ) : $block;
			}
		}
		if ( preg_match_all( '#<meta\s[^>]*content=(["\'])(.*?)\1[^>]*>#is', $html, $m ) ) {
			foreach ( $m[2] as $c ) {
				$out['meta tag'][] = html_entity_decode( $c, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$out['title'][] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match_all( '#\salt=(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $a ) {
				$out['alt text'][] = html_entity_decode( $a, ENT_QUOTES, 'UTF-8' );
			}
		}
		if ( preg_match_all( '#data-product_variations=(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $v ) {
				$data                     = json_decode( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ), true );
				$out['variation data'][] = is_array( $data ) ? implode( ' · ', self::strings( $data, array( 'variation_description', 'description', 'image', 'alt', 'title', 'caption' ) ) ) : '';
			}
		}
		$body = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$body = preg_replace( '#<head\b[^>]*>.*?</head>#is', ' ', (string) $body );
		$body = preg_replace( '#<[^>]+>#', ' ', (string) $body );
		$out['page text'][] = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $body, ENT_QUOTES, 'UTF-8' ) ) );
		return $out;
	}

	/**
	 * Every link a visitor can follow on a page (pure): the href of each
	 * <a> and the action of each <form>. Stylesheets, scripts and icons
	 * (<link href>, anything under /wp-content/ or /wp-includes/) are not
	 * links to a page.
	 *
	 * @param string $html Page.
	 * @return string[]
	 */
	public static function links( $html ) {
		$out = array();
		if ( preg_match_all( '#<(?:a|area)\b[^>]*?\shref=(["\'])(.*?)\1#is', (string) $html, $m ) ) {
			$out = array_merge( $out, $m[2] );
		}
		if ( preg_match_all( '#<form\b[^>]*?\saction=(["\'])(.*?)\1#is', (string) $html, $m ) ) {
			$out = array_merge( $out, $m[2] );
		}
		$keep = array();
		foreach ( $out as $h ) {
			$h = html_entity_decode( $h, ENT_QUOTES, 'UTF-8' );
			if ( preg_match( '#/wp-(?:content|includes)/#i', $h ) ) {
				continue;
			}
			$keep[] = $h;
		}
		return array_values( array_unique( $keep ) );
	}

	/**
	 * The addresses a tool page must not link to (pure): every medication
	 * product and category page checked, plus every old address on the
	 * redirect list — a guide still linking to a product's old address
	 * reaches it through the 301.
	 *
	 * @param array $pages     pages() rows (url, kind).
	 * @param array $redirects Redirects::all().
	 * @return string[]
	 */
	public static function med_paths( array $pages, array $redirects ) {
		$out = array();
		foreach ( $pages as $p ) {
			if ( in_array( $p['kind'] ?? '', array( 'product', 'category' ), true ) ) {
				$out[] = (string) $p['url'];
			}
		}
		foreach ( $redirects as $e ) {
			if ( '' !== (string) ( $e['from'] ?? '' ) ) {
				$out[] = (string) $e['from'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * What a page says that it must not (pure).
	 *
	 * @param string   $html     Page.
	 * @param string   $kind     product / category / home / tool.
	 * @param array    $words    Words::get().
	 * @param string[] $med_urls Medication product and category addresses (for tool pages).
	 * @return array<int, array{where: string, kind: string, word: string, snippet: string, key: string}>
	 */
	public static function scan( $html, $kind, array $words, array $med_urls = array() ) {
		$hits = array();
		if ( 'tool' === $kind ) {
			$paths = array();
			foreach ( $med_urls as $u ) {
				$p = Redirects::normalise_path( $u );
				if ( '/' !== $p ) {
					$paths[] = rtrim( $p, '/' );
				}
			}
			$paths[] = '/product-category/medications';
			$raw     = (string) $html;
			foreach ( array_unique( $paths ) as $p ) {
				$pos = stripos( $raw, $p . '/' );
				if ( false === $pos ) {
					$pos = stripos( str_replace( '\/', '/', $raw ), $p . '/' );
				}
				if ( false !== $pos ) {
					$hits[] = self::hit( 'link or product data', 'product-link', $p . '/', substr( $raw, max( 0, $pos - 60 ), 160 ) );
				}
			}
			if ( preg_match( '#add-to-cart=\d+#i', $raw, $m ) ) {
				$hits[] = self::hit( 'link', 'product-link', 'add-to-cart', $m[0] );
			}
			return $hits;
		}

		foreach ( self::texts( $html ) as $where => $texts ) {
			foreach ( $texts as $text ) {
				foreach ( Finder::find( $text, $words, array( 'diseases', 'claims', 'dosing', 'labels' ) ) as $h ) {
					$hits[] = self::hit( $where, $h['kind'], strtolower( $h['match'] ), Finder::snippet( $text, $h, 50 ) );
				}
			}
		}
		foreach ( self::links( $html ) as $href ) {
			foreach ( Finder::find( $href, $words, array( 'links' ) ) as $h ) {
				$hits[] = self::hit( 'link', 'links', $h['word'], $href );
			}
		}
		// One line per word and snippet.
		$seen = array();
		$out  = array();
		foreach ( $hits as $h ) {
			if ( ! isset( $seen[ $h['key'] ] ) ) {
				$seen[ $h['key'] ] = true;
				$out[]             = $h;
			}
		}
		return $out;
	}

	/**
	 * One finding.
	 *
	 * @param string $where   Where on the page.
	 * @param string $kind    List.
	 * @param string $word    Word.
	 * @param string $snippet Around it.
	 * @return array
	 */
	private static function hit( $where, $kind, $word, $snippet ) {
		$snippet = trim( preg_replace( '/\s+/u', ' ', (string) $snippet ) );
		return array(
			'where'   => $where,
			'kind'    => $kind,
			'word'    => $word,
			'snippet' => $snippet,
			'key'     => md5( $kind . '|' . strtolower( $word ) . '|' . strtolower( $snippet ) ),
		);
	}

	/**
	 * Every string in a decoded JSON value (optionally only under some keys).
	 *
	 * @param mixed    $data Data.
	 * @param string[] $only Keys whose strings count ([] = all).
	 * @param bool     $in   Already under one of them.
	 * @return string[]
	 */
	private static function strings( $data, array $only = array(), $in = false ) {
		$out = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$under = $in || ! $only || ( is_string( $k ) && in_array( $k, $only, true ) );
				$out   = array_merge( $out, self::strings( $v, $only, $under ) );
			}
		} elseif ( is_string( $data ) && ( $in || ! $only ) ) {
			$s = trim( html_entity_decode( preg_replace( '#<[^>]+>#', ' ', $data ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $s && ! preg_match( '#^https?://#', $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}
}

