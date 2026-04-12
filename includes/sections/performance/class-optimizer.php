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

		/*
		 * ── 5. CSS / JS File Combiner ────────────────────────────────────
		 * Performance Optimization Module
		 *
		 * Combine local CSS and non-deferred JS files into single cached
		 * files in wp-content/cache/fishotel-perf/ to reduce HTTP requests.
		 * External URLs (CDN, Google, PayPal, OneSignal) are skipped.
		 */
		if ( ! empty( $this->settings['combine_assets'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'combine_assets' ), 100 );
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

	/*
	 * =====================================================================
	 *  5. CSS / JS FILE COMBINER — Performance Optimization Module
	 * =====================================================================
	 */

	/**
	 * Combine local CSS and JS assets into single cached files.
	 */
	public function combine_assets() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$this->combine_styles();
		$this->combine_scripts();
	}

	/**
	 * Combine enqueued local stylesheets into a single cached CSS file.
	 */
	private function combine_styles() {
		$wp_styles = wp_styles();
		$wp_styles->all_deps( $wp_styles->queue );

		$handles    = array();
		$file_paths = array();

		foreach ( $wp_styles->to_do as $handle ) {
			if ( in_array( $handle, $wp_styles->done, true ) ) {
				continue;
			}

			$obj = isset( $wp_styles->registered[ $handle ] ) ? $wp_styles->registered[ $handle ] : null;
			if ( ! $obj || ! $obj->src ) {
				continue;
			}

			// Skip conditional stylesheets (e.g. IE-only).
			if ( ! empty( $obj->extra['conditional'] ) ) {
				continue;
			}

			$path = $this->url_to_path( $obj->src );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$handles[]             = $handle;
			$file_paths[ $handle ] = $path;
		}

		if ( count( $handles ) < 2 ) {
			return;
		}

		// Build a hash from file paths and modification times.
		$hash_input = '';
		foreach ( $file_paths as $path ) {
			$hash_input .= $path . '|' . filemtime( $path ) . ',';
		}
		$hash = substr( md5( $hash_input ), 0, 12 );

		$cache_file = $this->get_cache_dir() . 'combined-' . $hash . '.css';
		$cache_url  = $this->get_cache_url() . 'combined-' . $hash . '.css';

		if ( ! file_exists( $cache_file ) ) {
			$this->ensure_cache_dir();
			$combined = '';

			foreach ( $handles as $handle ) {
				$obj  = $wp_styles->registered[ $handle ];
				$path = $file_paths[ $handle ];
				$css  = file_get_contents( $path );

				if ( false === $css ) {
					continue;
				}

				// Rewrite relative url() references so they resolve from the cache directory.
				$base_url = dirname( $this->resolve_src( $obj->src ) );
				$css      = $this->rewrite_css_urls( $css, $base_url );

				// Wrap in @media if the stylesheet targets a specific media type.
				$media = ! empty( $obj->args ) ? $obj->args : 'all';
				if ( 'all' !== $media ) {
					$css = '@media ' . $media . " {\n" . $css . "\n}\n";
				}

				// Append inline styles added via wp_add_inline_style().
				if ( ! empty( $obj->extra['after'] ) ) {
					$css .= "\n" . implode( "\n", (array) $obj->extra['after'] );
				}

				$combined .= "/* {$handle} */\n" . $css . "\n\n";
			}

			$tmp = $cache_file . '.tmp';
			file_put_contents( $tmp, $combined );
			rename( $tmp, $cache_file );

			$this->cleanup_stale_cache( $cache_file, 'combined-*.css' );
		}

		// Mark originals as done so they don't output but still satisfy dependencies.
		foreach ( $handles as $handle ) {
			wp_dequeue_style( $handle );
			if ( isset( $wp_styles->registered[ $handle ] ) ) {
				$wp_styles->registered[ $handle ]->extra = array();
			}
			$wp_styles->done[] = $handle;
		}

		wp_enqueue_style( 'fishotel-combined', $cache_url, array(), null );
	}

	/**
	 * Combine enqueued local, non-deferred scripts into a single cached JS file.
	 */
	private function combine_scripts() {
		$wp_scripts = wp_scripts();
		$wp_scripts->all_deps( $wp_scripts->queue );

		$handles    = array();
		$file_paths = array();

		foreach ( $wp_scripts->to_do as $handle ) {
			if ( in_array( $handle, $wp_scripts->done, true ) ) {
				continue;
			}

			$obj = isset( $wp_scripts->registered[ $handle ] ) ? $wp_scripts->registered[ $handle ] : null;
			if ( ! $obj || ! $obj->src ) {
				continue;
			}

			// Skip deferred and async scripts.
			if ( ! empty( $obj->extra['strategy'] ) ) {
				continue;
			}

			// Skip conditional scripts.
			if ( ! empty( $obj->extra['conditional'] ) ) {
				continue;
			}

			$path = $this->url_to_path( $obj->src );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$handles[]             = $handle;
			$file_paths[ $handle ] = $path;
		}

		if ( count( $handles ) < 2 ) {
			return;
		}

		$hash_input = '';
		foreach ( $file_paths as $path ) {
			$hash_input .= $path . '|' . filemtime( $path ) . ',';
		}
		$hash = substr( md5( $hash_input ), 0, 12 );

		$cache_file = $this->get_cache_dir() . 'combined-' . $hash . '.js';
		$cache_url  = $this->get_cache_url() . 'combined-' . $hash . '.js';

		if ( ! file_exists( $cache_file ) ) {
			$this->ensure_cache_dir();
			$combined = '';

			foreach ( $handles as $handle ) {
				$obj  = $wp_scripts->registered[ $handle ];
				$path = $file_paths[ $handle ];

				$parts = array();

				// Inline "before" scripts (wp_add_inline_script with 'before').
				if ( ! empty( $obj->extra['before'] ) ) {
					$parts[] = implode( "\n", (array) $obj->extra['before'] );
				}

				// Localisation data (wp_localize_script).
				if ( ! empty( $obj->extra['data'] ) ) {
					$parts[] = $obj->extra['data'];
				}

				// Main file content.
				$js = file_get_contents( $path );
				if ( false !== $js ) {
					$parts[] = $js;
				}

				// Inline "after" scripts (wp_add_inline_script with 'after').
				if ( ! empty( $obj->extra['after'] ) ) {
					$parts[] = implode( "\n", (array) $obj->extra['after'] );
				}

				$combined .= "/* {$handle} */\n" . implode( "\n", $parts ) . "\n;\n";
			}

			$tmp = $cache_file . '.tmp';
			file_put_contents( $tmp, $combined );
			rename( $tmp, $cache_file );

			$this->cleanup_stale_cache( $cache_file, 'combined-*.js' );
		}

		// Mark originals as done so they don't output but still satisfy dependencies.
		foreach ( $handles as $handle ) {
			wp_dequeue_script( $handle );
			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$wp_scripts->registered[ $handle ]->extra = array();
			}
			$wp_scripts->done[] = $handle;
		}

		wp_enqueue_script( 'fishotel-combined', $cache_url, array(), null, true );
	}

	/*
	 * =====================================================================
	 *  SHARED HELPERS
	 * =====================================================================
	 */

	/**
	 * Convert a WordPress asset URL to a local file-system path.
	 *
	 * Returns false for external URLs.
	 *
	 * @param string $url Asset URL (absolute, protocol-relative, or root-relative).
	 * @return string|false Local path or false.
	 */
	private function url_to_path( $url ) {
		$url = strtok( $url, '?#' );

		if ( strpos( $url, '//' ) === 0 ) {
			$url = set_url_scheme( $url );
		}

		if ( strpos( $url, 'http' ) === 0 ) {
			$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
			$url_host  = wp_parse_url( $url, PHP_URL_HOST );

			if ( $url_host !== $site_host ) {
				return false;
			}

			$url_path  = wp_parse_url( $url, PHP_URL_PATH );
			$site_path = wp_parse_url( site_url(), PHP_URL_PATH );

			if ( $site_path && strpos( $url_path, $site_path ) === 0 ) {
				$relative = substr( $url_path, strlen( $site_path ) );
			} else {
				$relative = $url_path;
			}

			return ABSPATH . ltrim( $relative, '/' );
		}

		if ( strpos( $url, '/' ) === 0 ) {
			$site_path = wp_parse_url( site_url(), PHP_URL_PATH ) ?: '';
			if ( $site_path && strpos( $url, $site_path ) === 0 ) {
				$relative = substr( $url, strlen( $site_path ) );
			} else {
				$relative = $url;
			}
			return ABSPATH . ltrim( $relative, '/' );
		}

		return false;
	}

	/**
	 * Normalise a registered asset src to a full URL.
	 *
	 * @param string $src Registered src value.
	 * @return string Full URL.
	 */
	private function resolve_src( $src ) {
		$src = strtok( $src, '?#' );

		if ( strpos( $src, '//' ) === 0 ) {
			return set_url_scheme( $src );
		}

		if ( strpos( $src, '/' ) === 0 && strpos( $src, '//' ) !== 0 ) {
			return site_url( $src );
		}

		return $src;
	}

	/**
	 * Rewrite relative url() references in CSS to absolute URLs.
	 *
	 * @param string $css      CSS content.
	 * @param string $base_url Directory URL of the original CSS file.
	 * @return string CSS with rewritten URLs.
	 */
	private function rewrite_css_urls( $css, $base_url ) {
		$base_url = trailingslashit( $base_url );

		return preg_replace_callback(
			'/url\s*\(\s*([\'"]?)(?!(?:data:|https?:\/\/|\/|#))(.+?)\1\s*\)/i',
			function ( $m ) use ( $base_url ) {
				return 'url(' . $m[1] . $base_url . $m[2] . $m[1] . ')';
			},
			$css
		);
	}

	/**
	 * Local path to the asset cache directory.
	 *
	 * @return string
	 */
	private function get_cache_dir() {
		return WP_CONTENT_DIR . '/cache/fishotel-perf/';
	}

	/**
	 * Public URL for the asset cache directory.
	 *
	 * @return string
	 */
	private function get_cache_url() {
		return content_url( '/cache/fishotel-perf/' );
	}

	/**
	 * Create the cache directory if it does not exist.
	 */
	private function ensure_cache_dir() {
		$dir = $this->get_cache_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Remove stale cached files that do not match the current build.
	 *
	 * @param string $current_file Absolute path of the file to keep.
	 * @param string $pattern      Glob pattern (e.g. 'combined-*.css').
	 */
	private function cleanup_stale_cache( $current_file, $pattern ) {
		foreach ( glob( $this->get_cache_dir() . $pattern ) as $file ) {
			if ( $file !== $current_file ) {
				@unlink( $file );
			}
		}
	}
}
