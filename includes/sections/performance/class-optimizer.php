<?php
/**
 * Performance optimizations — conditionally registers each feature
 * based on the saved settings.
 *
 * All optimisations use PHP / WordPress hooks only (no .htaccess).
 * Designed for Cloudways Nginx (Lightning Stack) + Varnish environments.
 *
 * @package FisHotel\Misc\Sections\Performance
 */

namespace FisHotel\Misc\Sections\Performance;

defined( 'ABSPATH' ) || exit;

/**
 * Class Optimizer
 */
class Optimizer {

	/**
	 * Active settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Initialise optimizations based on saved settings.
	 */
	public function init() {
		$this->settings = wp_parse_args(
			get_option( Performance::OPTION_KEY, array() ),
			Performance::DEFAULTS
		);

		/*
		 * ── 1. Script / Style Cleanup ────────────────────────────────────
		 * Performance Optimization Module
		 *
		 * Dequeue Contact Form 7 assets on pages that do not contain
		 * the [contact-form-7] shortcode to reduce HTTP requests.
		 */
		if ( ! empty( $this->settings['script_cleanup'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'cleanup_scripts' ), 99 );
		}

		/*
		 * ── 2. Cache-Control Headers ─────────────────────────────────────
		 * Performance Optimization Module
		 *
		 * Add Cache-Control: public, max-age=3600 for regular front-end
		 * pages. WooCommerce cart, checkout, and account pages are
		 * explicitly set to no-store, no-cache.
		 */
		if ( ! empty( $this->settings['cache_headers'] ) ) {
			add_action( 'send_headers', array( $this, 'cache_headers' ) );
		}

		/*
		 * ── 3. Gzip Compression ──────────────────────────────────────────
		 * Performance Optimization Module
		 *
		 * Enable PHP output compression when the server is not already
		 * handling it (e.g. via zlib.output_compression or mod_deflate).
		 */
		if ( ! empty( $this->settings['gzip_compression'] ) ) {
			add_action( 'init', array( $this, 'gzip_compression' ), 1 );
		}

		/*
		 * ── 4. Remove Query Strings ──────────────────────────────────────
		 * Performance Optimization Module
		 *
		 * Strip ?ver= query strings from CSS and JS URLs to improve
		 * proxy and CDN caching.
		 */
		if ( ! empty( $this->settings['remove_query_strings'] ) ) {
			add_filter( 'script_loader_src', array( $this, 'remove_query_strings' ), 15 );
			add_filter( 'style_loader_src', array( $this, 'remove_query_strings' ), 15 );
		}
	}

	/*
	 * =====================================================================
	 *  1. SCRIPT / STYLE CLEANUP — Performance Optimization Module
	 * =====================================================================
	 */

	/**
	 * Dequeue Contact Form 7 assets on pages that do not use the shortcode.
	 */
	public function cleanup_scripts() {
		if ( is_admin() ) {
			return;
		}

		$post = get_post();

		$has_cf7 = $post
			&& ( has_shortcode( $post->post_content, 'contact-form-7' )
				|| has_shortcode( $post->post_content, 'contact-form' ) );

		if ( ! $has_cf7 ) {
			wp_dequeue_style( 'contact-form-7' );
			wp_deregister_style( 'contact-form-7' );
			wp_dequeue_script( 'contact-form-7' );
			wp_deregister_script( 'contact-form-7' );
		}
	}

	/*
	 * =====================================================================
	 *  2. CACHE-CONTROL HEADERS — Performance Optimization Module
	 * =====================================================================
	 */

	/**
	 * Send Cache-Control headers for front-end pages.
	 *
	 * WooCommerce transactional pages are explicitly excluded.
	 */
	public function cache_headers() {
		if ( is_admin() ) {
			return;
		}

		// Never cache WooCommerce transactional pages.
		if (
			( function_exists( 'is_cart' ) && is_cart() ) ||
			( function_exists( 'is_checkout' ) && is_checkout() ) ||
			( function_exists( 'is_account_page' ) && is_account_page() )
		) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			return;
		}

		header( 'Cache-Control: public, max-age=3600' );
	}

	/*
	 * =====================================================================
	 *  3. GZIP COMPRESSION — Performance Optimization Module
	 * =====================================================================
	 */

	/**
	 * Start output buffering with gzip compression when possible.
	 */
	public function gzip_compression() {
		if (
			! ini_get( 'zlib.output_compression' ) &&
			! headers_sent() &&
			is_callable( 'ob_gzhandler' )
		) {
			ob_start( 'ob_gzhandler' );
		}
	}

	/*
	 * =====================================================================
	 *  4. REMOVE QUERY STRINGS — Performance Optimization Module
	 * =====================================================================
	 */

	/**
	 * Strip version query strings from static asset URLs.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function remove_query_strings( $src ) {
		if ( strpos( $src, '?ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}
}
