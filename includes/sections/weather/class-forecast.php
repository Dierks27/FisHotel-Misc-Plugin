<?php
/**
 * NWS forecast parsing — pure functions over decoded JSON, no WordPress
 * and no network. The client fetches; this file decides which period is
 * "the delivery day" and what its numbers are.
 *
 * The NWS daily forecast (/gridpoints/{office}/{x},{y}/forecast) returns
 * up to 14 half-day periods: "Tonight" / "Wednesday" / "Wednesday Night"
 * / ... Each has startTime in the gridpoint's local offset, isDaytime,
 * temperature (+ unit), probabilityOfPrecipitation and shortForecast.
 * The delivery-day HIGH is the daytime period starting on the delivery
 * date; the LOW is the night period starting on that date (weather.gov
 * pairs "Wednesday" with "Wednesday Night"). Forecasts run 7 days — a
 * date beyond that simply has no period and is reported unavailable.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Forecast
 */
class Forecast {

	/**
	 * Pull the periods array out of a decoded /forecast response.
	 *
	 * @param mixed $decoded Decoded JSON (array) or JSON string.
	 * @return array[]|null Periods, or null when the shape is wrong.
	 */
	public static function periods( $decoded ) {
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$periods = $decoded['properties']['periods'] ?? ( isset( $decoded[0] ) ? $decoded : null );

		if ( ! is_array( $periods ) ) {
			return null;
		}

		$out = array();
		foreach ( $periods as $period ) {
			if ( is_array( $period ) && isset( $period['startTime'] ) ) {
				$out[] = $period;
			}
		}

		return $out;
	}

	/**
	 * The local calendar date (Y-m-d) a period starts on. startTime carries
	 * the gridpoint's own offset ("2026-04-01T06:00:00-04:00"), so the first
	 * ten characters ARE the local date — no timezone math needed.
	 *
	 * @param array $period Period.
	 * @return string Y-m-d or ''.
	 */
	public static function period_date( array $period ) {
		$start = (string) ( $period['startTime'] ?? '' );
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $start, $m ) ? $m[1] : '';
	}

	/**
	 * The daytime period starting on a date, or null.
	 *
	 * @param array[] $periods Periods.
	 * @param string  $ymd     Y-m-d.
	 * @return array|null
	 */
	public static function pick_day( array $periods, $ymd ) {
		foreach ( $periods as $period ) {
			if ( ! empty( $period['isDaytime'] ) && self::period_date( $period ) === $ymd ) {
				return $period;
			}
		}

		return null;
	}

	/**
	 * The night period starting on a date, or null.
	 *
	 * @param array[] $periods Periods.
	 * @param string  $ymd     Y-m-d.
	 * @return array|null
	 */
	public static function pick_night( array $periods, $ymd ) {
		foreach ( $periods as $period ) {
			if ( empty( $period['isDaytime'] ) && self::period_date( $period ) === $ymd ) {
				return $period;
			}
		}

		return null;
	}

	/**
	 * A period's temperature in °F, converting from °C when NWS says so.
	 *
	 * @param array $period Period.
	 * @return int|null
	 */
	public static function temperature_f( array $period ) {
		if ( ! isset( $period['temperature'] ) || ! is_numeric( $period['temperature'] ) ) {
			return null;
		}

		$value = (float) $period['temperature'];
		$unit  = strtoupper( (string) ( $period['temperatureUnit'] ?? 'F' ) );

		if ( 'C' === $unit ) {
			$value = $value * 9 / 5 + 32;
		}

		return (int) round( $value );
	}

	/**
	 * A period's probability of precipitation (percent), or null.
	 *
	 * @param array $period Period.
	 * @return int|null
	 */
	public static function pop( array $period ) {
		$value = $period['probabilityOfPrecipitation']['value'] ?? null;

		return ( null === $value || ! is_numeric( $value ) ) ? null : (int) round( (float) $value );
	}

	/**
	 * Everything the note needs for one date, or null when there is no
	 * daytime period for it (date beyond the 7-day window, or today after
	 * sunset when only "Tonight" remains).
	 *
	 * @param array[] $periods Periods.
	 * @param string  $ymd     Y-m-d.
	 * @return array{high: int, low: int|null, short: string, detailed: string, pop: int|null, name: string}|null
	 */
	public static function day_summary( array $periods, $ymd ) {
		$day = self::pick_day( $periods, $ymd );
		if ( ! $day ) {
			return null;
		}

		$high = self::temperature_f( $day );
		if ( null === $high ) {
			return null;
		}

		$night = self::pick_night( $periods, $ymd );
		$low   = $night ? self::temperature_f( $night ) : null;

		return array(
			'high'     => $high,
			'low'      => $low,
			'short'    => trim( (string) ( $day['shortForecast'] ?? '' ) ),
			'detailed' => trim( (string) ( $day['detailedForecast'] ?? '' ) ),
			'pop'      => self::pop( $day ),
			'name'     => (string) ( $day['name'] ?? '' ),
		);
	}

	/**
	 * What the checker needs from a decoded /points response.
	 *
	 * @param mixed $decoded Decoded JSON (array) or JSON string.
	 * @return array{forecast_url: string, grid_key: string, city: string, state: string, timezone: string}|null
	 */
	public static function point( $decoded ) {
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}

		$props = is_array( $decoded ) ? ( $decoded['properties'] ?? null ) : null;
		if ( ! is_array( $props ) || empty( $props['forecast'] ) ) {
			return null;
		}

		$url = (string) $props['forecast'];
		if ( 0 !== strpos( $url, 'https://api.weather.gov/' ) ) {
			return null;
		}

		$grid = '';
		if ( isset( $props['gridId'], $props['gridX'], $props['gridY'] ) ) {
			$grid = sprintf( '%s/%d,%d', preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $props['gridId'] ) ), (int) $props['gridX'], (int) $props['gridY'] );
		}
		if ( '' === $grid ) {
			$grid = md5( $url );
		}

		return array(
			'forecast_url' => $url,
			'grid_key'     => $grid,
			'city'         => (string) ( $props['relativeLocation']['properties']['city'] ?? '' ),
			'state'        => (string) ( $props['relativeLocation']['properties']['state'] ?? '' ),
			'timezone'     => (string) ( $props['timeZone'] ?? '' ),
		);
	}

	/**
	 * Lat/lon from a decoded US Census onelineaddress response, or null
	 * when there is no match.
	 *
	 * @param mixed $decoded Decoded JSON (array) or JSON string.
	 * @return array{lat: float, lon: float, matched: string}|null
	 */
	public static function census_match( $decoded ) {
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}

		$matches = is_array( $decoded ) ? ( $decoded['result']['addressMatches'] ?? null ) : null;
		if ( ! is_array( $matches ) || empty( $matches ) ) {
			return null;
		}

		$first = reset( $matches );
		$x     = $first['coordinates']['x'] ?? null;
		$y     = $first['coordinates']['y'] ?? null;

		if ( ! is_numeric( $x ) || ! is_numeric( $y ) ) {
			return null;
		}

		return array(
			'lat'     => (float) $y,
			'lon'     => (float) $x,
			'matched' => (string) ( $first['matchedAddress'] ?? '' ),
		);
	}

	/**
	 * Lat/lon from a decoded Zippopotam ZIP response (centroid fallback).
	 *
	 * @param mixed $decoded Decoded JSON (array) or JSON string.
	 * @return array{lat: float, lon: float, matched: string}|null
	 */
	public static function zip_match( $decoded ) {
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}

		$places = is_array( $decoded ) ? ( $decoded['places'] ?? null ) : null;
		if ( ! is_array( $places ) || empty( $places ) ) {
			return null;
		}

		$first = reset( $places );
		$lat   = $first['latitude'] ?? null;
		$lon   = $first['longitude'] ?? null;

		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return null;
		}

		return array(
			'lat'     => (float) $lat,
			'lon'     => (float) $lon,
			'matched' => trim( (string) ( $first['place name'] ?? '' ) . ', ' . (string) ( $first['state abbreviation'] ?? '' ), ', ' ),
		);
	}

	/**
	 * Unique names of active alerts that cover a date.
	 *
	 * An alert covers the date when its onset (or effective) is on or
	 * before the date and its ends (or expires) is on or after it; a
	 * missing bound is open.
	 *
	 * @param mixed  $decoded Decoded /alerts/active response, or features array.
	 * @param string $ymd     Y-m-d.
	 * @return string[]
	 */
	public static function alerts_for_day( $decoded, $ymd ) {
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}

		$features = is_array( $decoded ) ? ( $decoded['features'] ?? ( isset( $decoded[0] ) ? $decoded : array() ) ) : array();
		$names    = array();

		foreach ( (array) $features as $feature ) {
			$props = is_array( $feature ) ? ( $feature['properties'] ?? null ) : null;
			if ( ! is_array( $props ) || empty( $props['event'] ) ) {
				continue;
			}

			$start = self::iso_date( $props['onset'] ?? ( $props['effective'] ?? '' ) );
			$end   = self::iso_date( $props['ends'] ?? ( $props['expires'] ?? '' ) );

			if ( '' !== $start && $start > $ymd ) {
				continue;
			}
			if ( '' !== $end && $end < $ymd ) {
				continue;
			}

			$name = trim( preg_replace( '/\s+/', ' ', (string) $props['event'] ) );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Leading Y-m-d of an ISO-8601 string, or ''.
	 *
	 * @param mixed $iso ISO string.
	 * @return string
	 */
	private static function iso_date( $iso ) {
		return ( is_string( $iso ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $iso, $m ) ) ? $m[1] : '';
	}
}
