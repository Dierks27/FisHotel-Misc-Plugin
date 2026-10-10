<?php
/**
 * Weather cron — one nightly event at the configured site-local time.
 *
 * WHY a single event that re-arms itself instead of a 'daily' schedule:
 * WP-Cron intervals are fixed seconds, so a 'daily' event drifts an hour
 * at every DST change. Scheduling the NEXT occurrence of 21:00 in the
 * site timezone at the start of each run (and on settings save / boot when
 * nothing is pending) keeps "nightly at 21:00" meaning exactly that.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron
 */
class Cron {

	/**
	 * Cron hook.
	 *
	 * @var string
	 */
	const HOOK = 'fishotel_weather_nightly';

	/**
	 * Register the handler.
	 */
	public static function init() {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/**
	 * The next UNIX timestamp at which HH:MM occurs in a timezone, strictly
	 * after $now. Pure — used by the tests.
	 *
	 * @param string        $run_time HH:MM.
	 * @param \DateTimeZone $tz       Site timezone.
	 * @param int|null      $now      Reference time (UNIX), null = time().
	 * @return int
	 */
	public static function next_run_timestamp( $run_time, \DateTimeZone $tz, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		list( $h, $i ) = array_map( 'intval', explode( ':', Settings::clean_time( $run_time ) ) );

		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$next  = $local->setTime( $h, $i, 0 );

		if ( $next->getTimestamp() <= $now ) {
			$next = $next->modify( '+1 day' )->setTime( $h, $i, 0 );
		}

		return $next->getTimestamp();
	}

	/**
	 * Clear and (if enabled) arm the next run. Called on settings save and
	 * at the start of each run.
	 *
	 * @param array $settings Settings.
	 */
	public static function schedule( array $settings ) {
		self::clear();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		wp_schedule_single_event(
			self::next_run_timestamp( (string) $settings['run_time'], wp_timezone() ),
			self::HOOK
		);
	}

	/**
	 * Arm the next run only when nothing is pending (boot / activation).
	 *
	 * @param array $settings Settings.
	 */
	public static function ensure_scheduled( array $settings ) {
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule( $settings );
		}
	}

	/**
	 * Drop every pending occurrence.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Human label of the next scheduled run in site time, or ''.
	 *
	 * @return string
	 */
	public static function next_run_label() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( ! $ts ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * The nightly job: arm tomorrow's run, then scan.
	 *
	 * The `enabled` toggle is honoured here (the cron path) and nowhere
	 * else, so "Run nightly scan now" still works with the job switched
	 * off. The next run is armed BEFORE the scan: a cold-cache first run
	 * can hit max_execution_time, and a fatal would skip a `finally`
	 * block and lose the re-arm for good.
	 */
	public static function run() {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			self::clear();
			return;
		}

		self::schedule( $settings );
		Checker::run_nightly( $settings );
	}
}
