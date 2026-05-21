<?php
/**
 * Visitor Stats tracker — public REST endpoint, bot filtering, and the
 * single row insert per tracked pageview.
 *
 * @package FisHotel\Misc\Sections\Visitor_Stats
 */

namespace FisHotel\Misc\Sections\Visitor_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tracker
 */
class Tracker {

	/**
	 * User-Agent substrings that mark a request as a bot / automated
	 * client. Matched case-insensitively.
	 *
	 * @var string
	 */
	const BOT_REGEX = '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|preview|monitor|uptimerobot|pingdom|headless|phantom|selenium|puppeteer|playwright|curl|wget|python-requests|go-http-client|java\/|okhttp/i';

	/**
	 * Path prefixes we never track (admin / login / API / system).
	 *
	 * @var string[]
	 */
	const EXCLUDED_PREFIXES = array(
		'/wp-admin',
		'/wp-login.php',
		'/wp-json',
		'/wp-cron.php',
		'/xmlrpc.php',
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the public POST /pageview REST route.
	 */
	public function register_route() {
		register_rest_route(
			Visitor_Stats::REST_NAMESPACE,
			Visitor_Stats::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public endpoint — it is a tracker. Every field is still
				// validated / sanitised below and bots are filtered out.
				'permission_callback' => '__return_true',
				'args'                => array(
					'url'        => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 500;
						},
						'sanitize_callback' => static function ( $value ) {
							return esc_url_raw( sanitize_text_field( $value ) );
						},
					),
					'title'      => array(
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && strlen( $value ) <= 255;
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'referrer'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => static function ( $value ) {
							return esc_url_raw( sanitize_text_field( $value ) );
						},
					),
					'visitor_id' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
						},
					),
					'post_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Handle a pageview beacon.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// 1. Bot filter — before any insert. Bots get a silent 204.
		if ( $this->is_bot( $ua ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$raw_url    = (string) $request->get_param( 'url' );
		$title      = (string) $request->get_param( 'title' );
		$raw_ref    = (string) $request->get_param( 'referrer' );
		$visitor_id = (string) $request->get_param( 'visitor_id' );
		$hint_pid   = (int) $request->get_param( 'post_id' );

		// 2. Normalise to a path on our own domain, or bail silently.
		$path = $this->normalize_url( $raw_url );
		if ( false === $path ) {
			return new \WP_REST_Response( null, 204 );
		}

		// 3. Resolve the post. url_to_postid() is authoritative; only
		// fall back to the JS-supplied hint when it returns 0 (custom
		// rewrites where the lookup is ambiguous).
		$post_id   = 0;
		$post_type = '';

		$resolved = url_to_postid( $raw_url );
		if ( $resolved > 0 ) {
			$post_id   = $resolved;
			$post_type = (string) get_post_type( $resolved );
		} elseif ( $hint_pid > 0 ) {
			$hint_type = get_post_type( $hint_pid );
			if ( $hint_type ) {
				$post_id   = $hint_pid;
				$post_type = (string) $hint_type;
			}
		}

		// 4. Referrer domain (empty for internal navigation).
		$referrer_domain = $this->extract_referrer_domain( $raw_ref );

		// 5. Insert. No IP, no User-Agent — only the opaque visitor ID.
		// created_at is stored in UTC (gmdate) so it pairs with the
		// UTC_TIMESTAMP()-based queries regardless of the MySQL server
		// or site timezone.
		global $wpdb;

		$wpdb->insert(
			Visitor_Stats::table_name(),
			array(
				'url'             => $path,
				'title'           => $title,
				'visitor_id'      => $visitor_id,
				'referrer_domain' => $referrer_domain,
				'post_id'         => $post_id,
				'post_type'       => $post_type,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Whether a User-Agent string looks like a bot / automated client.
	 *
	 * @param string $ua User-Agent string.
	 * @return bool
	 */
	private function is_bot( $ua ) {
		if ( '' === $ua ) {
			// No UA is characteristic of scripts / scanners, not real
			// browsers — treat as a bot.
			return true;
		}

		return 1 === preg_match( self::BOT_REGEX, $ua );
	}

	/**
	 * Normalise a URL to a path on this site, or reject it.
	 *
	 * Strips protocol, host, query string and fragment; rejects URLs
	 * pointing at another domain and admin / login / API / feed paths.
	 *
	 * @param string $raw Raw URL.
	 * @return string|false Path (leading slash) or false if excluded.
	 */
	private function normalize_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return false;
		}

		$parts = wp_parse_url( $raw );
		if ( false === $parts ) {
			return false;
		}

		// A host, if present, must be our own.
		if ( ! empty( $parts['host'] ) ) {
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( strtolower( $parts['host'] ) !== strtolower( (string) $site_host ) ) {
				return false;
			}
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		// Admin / login / API / system paths.
		foreach ( self::EXCLUDED_PREFIXES as $prefix ) {
			if ( 0 === stripos( $path, $prefix ) ) {
				return false;
			}
		}

		// Feeds: /feed, /feed/ or a trailing /feed segment (comment and
		// taxonomy feeds), without catching pages like /feedback.
		if ( 1 === preg_match( '#^/feed(/|$)|/feed/?$#i', $path ) ) {
			return false;
		}

		// Sitemaps / data endpoints.
		if ( 1 === preg_match( '#\.(xml|json)$#i', $path ) ) {
			return false;
		}

		return $path;
	}

	/**
	 * Extract a bare referrer host (without `www.`), or '' for internal
	 * navigation / no referrer.
	 *
	 * @param string $raw Raw referrer URL.
	 * @return string
	 */
	private function extract_referrer_domain( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}

		$host = wp_parse_url( $raw, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return '';
		}

		$host = preg_replace( '/^www\./i', '', strtolower( $host ) );

		$site_host = preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $host === $site_host ) {
			return '';
		}

		if ( strlen( $host ) > 255 ) {
			$host = substr( $host, 0, 255 );
		}

		return $host;
	}
}
