<?php
/**
 * A client that never touches the network: every method answers from the
 * fixtures, or with the WP_Error the test asks for. Shared by
 * forecast-test.php and checker-test.php.
 *
 * The fixture forecast (fixtures/nws-forecast.json) starts with "Tonight"
 * on 2026-03-31 and runs April 1 (76°F) … April 5 (90°F): March 31 has no
 * daytime period, so an order delivering that day is "unavailable".
 *
 * @package FisHotel\Misc\Sections\Weather
 */

use FisHotel\Misc\Sections\Weather\Forecast;
use FisHotel\Misc\Sections\Weather\Nws_Client;

class Fake_Client extends Nws_Client {

	public $periods;
	public $fail = '';

	/**
	 * How many forecast fetches were asked for (a cache-miss count for
	 * the real client would be the same number).
	 *
	 * @var int
	 */
	public $forecast_calls = 0;

	public function __construct() {
		parent::__construct( 'test@example.test' );
		$this->periods = Forecast::periods( fh_fixture( 'nws-forecast.json' ) );
	}

	public function locate( array $address ) {
		if ( 'geocode' === $this->fail ) {
			return new \WP_Error( 'x', 'geocode failed: ZIP 00000 not found' );
		}
		$match           = Forecast::census_match( fh_fixture( 'census-geocode.json' ) );
		$match['source'] = 'census';
		return $match;
	}

	public function point( $lat, $lon ) {
		if ( 'point' === $this->fail ) {
			return new \WP_Error( 'x', 'api.weather.gov returned HTTP 503' );
		}
		return Forecast::point( fh_fixture( 'nws-points.json' ) );
	}

	public function periods( array $point ) {
		$this->forecast_calls++;
		if ( 'forecast' === $this->fail ) {
			return new \WP_Error( 'x', 'api.weather.gov returned HTTP 500' );
		}
		return $this->periods;
	}

	public function alerts( $lat, $lon ) {
		return 'alerts' === $this->fail ? array() : json_decode( fh_fixture( 'nws-alerts.json' ), true )['features'];
	}
}
