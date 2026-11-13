<?php
/**
 * Requests cron — one daily event, around a quarter past nine in the
 * morning (site time), that does everything nobody should have to
 * remember:
 *
 *   1. the state follows the fish (Follow::run()): waiting → on order →
 *      in quarantine → ready, and back when the fish is not there;
 *   2. everyone whose fish is ready is told (Follow::offers()), when
 *      Settings says "by themselves";
 *   3. the timers (Service::timers()): the one fee reminder, the fee
 *      expiry, "still want it?" and its expiry.
 *
 * Every change is logged on its request by the Service; the summary of
 * the last run is kept in an option for the list page ("Last check: …").
 * The same run can be started by hand from the list page.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests;

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
	const HOOK = 'fishotel_requests_daily';

	/**
	 * Option with the summary of the last run (not autoloaded: only the
	 * list page reads it).
	 *
	 * @var string
	 */
	const LAST_RUN_OPTION = 'fishotel_requests_last_run';

	/**
	 * The time of day (site zone) the job is first set for.
	 *
	 * @var string
	 */
	const RUN_TIME = '09:15';

	/**
	 * Register the handler and arm the event when nothing is pending
	 * (wp_next_scheduled() reads the autoloaded cron option: no query).
	 */
	public static function init() {
		add_action( self::HOOK, array( self::class, 'run' ) );
		// At `init`, not here: scheduling reads the cron schedules, where WooCommerce
		// translates its own names — and loading translations this early (sections
		// boot at plugins_loaded) logs a notice.
		add_action( 'init', array( self::class, 'ensure_scheduled' ), 10, 0 );
	}

	/**
	 * The next UNIX time HH:MM occurs in a zone, strictly after $now (pure).
	 *
	 * @param string        $time HH:MM.
	 * @param \DateTimeZone $tz   Zone.
	 * @param int           $now  UNIX time.
	 * @return int
	 */
	public static function next_run( $time, \DateTimeZone $tz, $now ) {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', (string) $time ) + array( 0, 0 ) );

		$local = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$next  = $local->setTime( $hour, $minute, 0 );
		if ( $next->getTimestamp() <= (int) $now ) {
			$next = $next->modify( '+1 day' )->setTime( $hour, $minute, 0 );
		}

		return $next->getTimestamp();
	}

	/**
	 * Arm the daily event only when nothing is pending.
	 */
	public static function ensure_scheduled() {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		wp_schedule_event( self::next_run( self::RUN_TIME, $tz, time() ), 'daily', self::HOOK );
	}

	/**
	 * Drop every pending occurrence (deactivation).
	 */
	public static function clear() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The daily job.
	 *
	 * @return array The summary (see daily()).
	 */
	public static function run() {
		try {
			$summary = self::daily( Requests::service(), Cleanroom::make() );
		} catch ( \Throwable $e ) {
			$summary = array(
				'at'    => time(),
				'error' => $e->getMessage(),
			);
			if ( function_exists( 'do_action' ) ) {
				do_action( 'fishotel_log', 'error', 'requests', 'The daily requests check failed: ' . $e->getMessage(), array() );
			}
		}

		update_option( self::LAST_RUN_OPTION, $summary, false );

		return $summary;
	}

	/**
	 * One pass over everything (no option, no clock of its own — the
	 * tests call this).
	 *
	 * @param Service     $service   The service.
	 * @param object|null $cleanroom The Clean Room adapter, or null.
	 * @return array{at: int, follow: array, told: int, timers: array}
	 */
	public static function daily( $service, $cleanroom ) {
		$follow = Follow::run( $service, $cleanroom );
		$told   = Follow::offers( $service );
		$timers = $service->timers();

		return array(
			'at'     => $service->now(),
			'follow' => $follow,
			'told'   => $told,
			'timers' => $timers,
		);
	}

	/**
	 * The last run in one line, for the list page.
	 *
	 * @param mixed $summary The stored summary.
	 * @return string '' when the job never ran.
	 */
	public static function summary_words( $summary ) {
		if ( ! is_array( $summary ) || empty( $summary['at'] ) ) {
			return '';
		}
		if ( ! empty( $summary['error'] ) ) {
			/* translators: %s: error */
			return sprintf( __( 'the last check failed: %s', 'fishotel-misc-plugin' ), (string) $summary['error'] );
		}

		$follow = isset( $summary['follow'] ) && is_array( $summary['follow'] ) ? $summary['follow'] : array();
		$timers = isset( $summary['timers'] ) && is_array( $summary['timers'] ) ? $summary['timers'] : array();
		$moved  = (int) ( $follow['on_order'] ?? 0 ) + (int) ( $follow['in_quarantine'] ?? 0 ) + (int) ( $follow['ready'] ?? 0 ) + (int) ( $follow['waiting'] ?? 0 );
		$parts  = array();

		if ( empty( $follow['available'] ) ) {
			$parts[] = __( 'the Clean Room is off, so no state followed a fish', 'fishotel-misc-plugin' );
		} else {
			/* translators: %d: requests */
			$parts[] = sprintf( _n( '%d request moved with its fish', '%d requests moved with their fish', $moved, 'fishotel-misc-plugin' ), $moved );
		}
		/* translators: %d: people */
		$parts[] = sprintf( _n( '%d person told', '%d people told', (int) ( $summary['told'] ?? 0 ), 'fishotel-misc-plugin' ), (int) ( $summary['told'] ?? 0 ) );
		/* translators: %d: reminders */
		$parts[] = sprintf( _n( '%d fee reminder', '%d fee reminders', (int) ( $timers['reminded'] ?? 0 ), 'fishotel-misc-plugin' ), (int) ( $timers['reminded'] ?? 0 ) );
		$expired = (int) ( $timers['expired'] ?? 0 ) + (int) ( $timers['ask_expired'] ?? 0 );
		/* translators: %d: requests */
		$parts[] = sprintf( _n( '%d expired', '%d expired', $expired, 'fishotel-misc-plugin' ), $expired );
		/* translators: %d: people */
		$parts[] = sprintf( _n( '%d asked "still want it?"', '%d asked "still want it?"', (int) ( $timers['asked'] ?? 0 ), 'fishotel-misc-plugin' ), (int) ( $timers['asked'] ?? 0 ) );

		return implode( ' · ', $parts );
	}
}
