<?php
/**
 * Gift Cards job — hourly, Live only: sends the e-mails of cards whose
 * delivery day has come (and any left half-sent by a run that died). It
 * is put on the schedule at `init` (never at boot: scheduling reads the
 * cron schedules, which WooCommerce translates), and taken off when the
 * mode is not Live.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

namespace FisHotel\Misc\Sections\Gift_Cards;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron
 */
class Cron {

	const HOOK            = 'fishotel_gift_cards_hourly';
	const LAST_RUN_OPTION = 'fishotel_gift_cards_last_run';

	/**
	 * Hook the job; schedule it (Live) or unschedule it (Shadow) at init.
	 *
	 * @param string $mode The effective mode.
	 */
	public static function init( $mode ) {
		add_action( self::HOOK, array( self::class, 'run' ) );
		if ( 'live' === $mode ) {
			add_action( 'init', array( self::class, 'ensure_scheduled' ), 10, 0 );
		} else {
			add_action( 'init', array( self::class, 'clear' ), 10, 0 );
		}
	}

	/**
	 * Put the job on the schedule (once).
	 */
	public static function ensure_scheduled() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Take the job off the schedule (when it is on it).
	 */
	public static function clear() {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::HOOK ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The job.
	 *
	 * @return array
	 */
	public static function run() {
		if ( 'live' !== Modes::current()['effective'] ) {
			return array();
		}
		try {
			$summary = Gift_Cards::service()->deliver_due( Audit::context( 'cron', self::HOOK ) );
		} catch ( \Throwable $e ) {
			$summary = array( 'error' => $e->getMessage() );
			do_action( 'fishotel_log', 'error', 'gift-cards', 'The hourly gift card job failed: ' . $e->getMessage(), array() );
		}
		$summary['at'] = time();
		update_option( self::LAST_RUN_OPTION, $summary, false );
		return $summary;
	}
}
