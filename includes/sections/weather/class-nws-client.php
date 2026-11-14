<?php
/**
 * Weather network client — the only file in the section that goes online.
 *
 * Three free, keyless services, every call through wp_remote_get with a
 * timeout, and every failure returned as a WP_Error the checker turns into
 * the "weather unavailable, check manually" state (never a wrong band):
 *
 *   1. US Census geocoder — street address → lat/lon. Rural routes and PO
 *      boxes often miss; then the ZIP centroid (Zippopotam) is tried so a
 *      small-town order still gets its ZIP's forecast.
 *   2. NWS /points/{lat},{lon} → the gridpoint's forecast URL.
 *   3. NWS forecast (daily periods) and /alerts/active for the point.
 *
 * Caching (transients): geocodes and gridpoints for 90 days keyed by the
 * normalised address / rounded coordinates (an address does not move);
 * forecasts for 6 hours keyed by gridpoint (one fetch serves the delivery
 * date and the day after); alerts for 1 hour. NWS asks for a User-Agent
 * that identifies the app and a contact, hence the contact email setting.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Nws_Client
 */
class Nws_Client {

	const CENSUS_URL = 'https://geocoding.geo.census.gov/geocoder/locations/onelineaddress';
	const ZIP_URL    = 'https://api.zippopotam.us/us/';
	const POINTS_URL = 'https://api.weather.gov/points/';
	const ALERTS_URL = 'https://api.weather.gov/alerts/active';

	/**
	 * Seconds to wait for each request.
	 *
	 * @var int
	 */
	const TIMEOUT = 10;

	const GEOCODE_TTL  = 90 * DAY_IN_SECONDS;
	const FORECAST_TTL = 6 * HOUR_IN_SECONDS;
	const ALERTS_TTL   = HOUR_IN_SECONDS;

	/**
	 * Transient key prefixes.
	 */
	const KEY_GEO      = 'fh_weather_geo_';
	const KEY_POINT    = 'fh_weather_pt_';
	const KEY_FORECAST = 'fh_weather_fc_';
	const KEY_ALERTS   = 'fh_weather_al_';

	/**
	 * User-Agent sent to every service.
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * @param string $contact_email Contact for the NWS User-Agent.
	 */
	public function __construct( $contact_email ) {
		$contact_email = trim( (string) $contact_email );
		if ( '' === $contact_email ) {
			$contact_email = (string) get_option( 'admin_email' );
		}

		$this->user_agent = 'FisHotel/' . FISHOTEL_MISC_VERSION . ' (' . $contact_email . ')';
	}

	/**
	 * A stable cache key for a destination address: lowercase, punctuation
	 * stripped, whitespace collapsed.
	 *
	 * @param array $address Keys address_1, address_2, city, state, postcode.
	 * @return string
	 */
	public static function normalise_address( array $address ) {
		$parts = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $key ) {
			$parts[] = (string) ( $address[ $key ] ?? '' );
		}

		$text = strtolower( implode( ' ', $parts ) );
		$text = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return $text;
	}

	/**
	 * Geocode a destination to lat/lon.
	 *
	 * @param array $address Keys address_1, address_2, city, state, postcode.
	 * @return array{lat: float, lon: float, source: string, matched: string}|\WP_Error
	 */
	public function locate( array $address ) {
		$normalised = self::normalise_address( $address );
		if ( '' === $normalised ) {
			return new \WP_Error( 'fh_weather_no_address', __( 'no shipping address on the order', 'fishotel-misc-plugin' ) );
		}

		$key    = self::KEY_GEO . md5( $normalised );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lon'] ) ) {
			return $cached;
		}

		$street = trim( (string) ( $address['address_1'] ?? '' ) );
		$city   = trim( (string) ( $address['city'] ?? '' ) );
		$state  = trim( (string) ( $address['state'] ?? '' ) );
		$zip    = trim( (string) ( $address['postcode'] ?? '' ) );

		$errors = array();

		// 1. Census, full street address.
		if ( '' !== $street ) {
			$line  = trim( implode( ', ', array_filter( array( $street, $city, trim( $state . ' ' . $zip ) ), 'strlen' ) ) );
			$match = $this->census( $line );
			if ( is_wp_error( $match ) ) {
				$errors[] = $match->get_error_message();
			} elseif ( $match ) {
				$match['source'] = 'census';
				set_transient( $key, $match, self::GEOCODE_TTL );
				return $match;
			}
		}

		// 2. ZIP centroid.
		$zip5 = substr( preg_replace( '/[^0-9]/', '', $zip ), 0, 5 );
		if ( 5 === strlen( $zip5 ) ) {
			$decoded = $this->get_json( self::ZIP_URL . rawurlencode( $zip5 ) );
			if ( is_wp_error( $decoded ) ) {
				$errors[] = $decoded->get_error_message();
			} else {
				$match = Forecast::zip_match( $decoded );
				if ( $match ) {
					$match['source'] = 'zip';
					set_transient( $key, $match, self::GEOCODE_TTL );
					return $match;
				}
				$errors[] = sprintf( 'ZIP %s not found', $zip5 );
			}
		} else {
			$errors[] = __( 'no usable ZIP on the order', 'fishotel-misc-plugin' );
		}

		return new \WP_Error( 'fh_weather_geocode', 'geocode failed: ' . implode( '; ', array_unique( $errors ) ) );
	}

	/**
	 * One Census onelineaddress lookup.
	 *
	 * @param string $line One-line address.
	 * @return array|null|\WP_Error Match, null for "no match", WP_Error on failure.
	 */
	private function census( $line ) {
		$url = add_query_arg(
			array(
				'address'   => rawurlencode( $line ),
				'benchmark' => 'Public_AR_Current',
				'format'    => 'json',
			),
			self::CENSUS_URL
		);

		$decoded = $this->get_json( $url );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return Forecast::census_match( $decoded );
	}

	/**
	 * Resolve a point to its NWS gridpoint / forecast URL.
	 *
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array{forecast_url: string, grid_key: string, city: string, state: string, timezone: string, lat: float, lon: float}|\WP_Error
	 */
	public function point( $lat, $lon ) {
		// NWS wants at most four decimals (more → a redirect).
		$lat = round( (float) $lat, 4 );
		$lon = round( (float) $lon, 4 );

		$key    = self::KEY_POINT . md5( $lat . ',' . $lon );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached['forecast_url'] ) ) {
			return $cached;
		}

		$decoded = $this->get_json( self::POINTS_URL . rawurlencode( $lat . ',' . $lon ), 'application/geo+json' );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$point = Forecast::point( $decoded );
		if ( ! $point ) {
			return new \WP_Error( 'fh_weather_point', __( 'NWS has no forecast grid for this point (outside the US?)', 'fishotel-misc-plugin' ) );
		}

		$point['lat'] = $lat;
		$point['lon'] = $lon;

		set_transient( $key, $point, self::GEOCODE_TTL );

		return $point;
	}

	/**
	 * The gridpoint's daily periods.
	 *
	 * @param array $point From self::point().
	 * @return array[]|\WP_Error Periods.
	 */
	public function periods( array $point ) {
		$key    = self::KEY_FORECAST . md5( (string) ( $point['grid_key'] ?? $point['forecast_url'] ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$decoded = $this->get_json( (string) $point['forecast_url'], 'application/geo+json' );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$periods = Forecast::periods( $decoded );
		if ( ! $periods ) {
			return new \WP_Error( 'fh_weather_forecast', __( 'NWS forecast had no periods', 'fishotel-misc-plugin' ) );
		}

		set_transient( $key, $periods, self::FORECAST_TTL );

		return $periods;
	}

	/**
	 * Active alert features for a point. Alerts are a nice-to-have: any
	 * failure returns an empty list so the note still gets written.
	 *
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array[]
	 */
	public function alerts( $lat, $lon ) {
		$lat = round( (float) $lat, 4 );
		$lon = round( (float) $lon, 4 );

		$key    = self::KEY_ALERTS . md5( $lat . ',' . $lon );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$decoded = $this->get_json( add_query_arg( 'point', rawurlencode( $lat . ',' . $lon ), self::ALERTS_URL ), 'application/geo+json' );
		if ( is_wp_error( $decoded ) ) {
			return array();
		}

		$features = isset( $decoded['features'] ) && is_array( $decoded['features'] ) ? $decoded['features'] : array();

		set_transient( $key, $features, self::ALERTS_TTL );

		return $features;
	}

	/**
	 * GET a JSON document.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept header.
	 * @return array|\WP_Error Decoded body, or WP_Error with a short reason.
	 */
	private function get_json( $url, $accept = 'application/json' ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent' => $this->user_agent,
					'Accept'     => $accept,
				),
			)
		);

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'fh_weather_http', sprintf( '%s unreachable (%s)', $host, $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'fh_weather_http', sprintf( '%s returned HTTP %d', $host, $code ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'fh_weather_json', sprintf( '%s returned unreadable JSON', $host ) );
		}

		return $decoded;
	}
}
