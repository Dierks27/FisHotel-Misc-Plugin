<?php
/**
 * Health cron — one daily event that prunes the log table per the
 * settings (retention days + row cap), re-armed on settings save and
 * cleared on deactivation.
 *
 * It also records the time of every WP-Cron request it sees (option
 * fishotel_health_last_cron) so the checks can tell "cron ran an hour ago"
 * from "cron has not run since Tuesday" — core's doing_cron transient
 * only exists while a run is in progress.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

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
	const HOOK = 'fishotel_health_daily';

	/**
	 * Option with the last prune summary.
	 *
	 * @var string
	 */
	const LAST_PRUNE_OPTION = 'fishotel_health_last_prune';

	/**
	 * Register the handler, arm the event if missing, note a cron run.
	 */
	public static function init() {
		add_action( self::HOOK, array( self::class, 'run' ) );

		self::ensure_scheduled();

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			self::record_cron_run();
		}
	}

	/**
	 * Remember that WP-Cron is running right now (at most one write per
	 * 5 minutes so a busy cron does not hammer the options table).
	 */
	public static function record_cron_run() {
		$last = (int) get_option( Facts::LAST_CRON_OPTION, 0 );

		if ( time() - $last >= 5 * MINUTE_IN_SECONDS ) {
			update_option( Facts::LAST_CRON_OPTION, time(), false );
		}
	}

	/**
	 * Arm the daily event only when nothing is pending.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear and re-arm (settings save).
	 */
	public static function schedule() {
		self::clear();
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
	}

	/**
	 * Drop every pending occurrence.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily job: prune.
	 */
	public static function run() {
		$settings = Settings::get();
		$deleted  = 0;

		try {
			$store   = new Store();
			$deleted = (int) $store->prune( (int) $settings['retention_days'], (int) $settings['max_rows'] );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		update_option(
			self::LAST_PRUNE_OPTION,
			array(
				'at'      => time(),
				'deleted' => $deleted,
			),
			false
		);
	}
}
