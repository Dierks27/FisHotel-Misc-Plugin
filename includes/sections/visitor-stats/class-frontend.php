<?php
/**
 * Visitor Stats frontend — enqueues the cache-safe tracking beacon on
 * public pageviews.
 *
 * @package FisHotel\Misc\Sections\Visitor_Stats
 */

namespace FisHotel\Misc\Sections\Visitor_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
	}

	/**
	 * Enqueue the beacon on trackable public pageviews only.
	 */
	public function enqueue_beacon() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( is_robots() || is_feed() || is_preview() || is_customize_preview() ) {
			return;
		}

		// Don't track admins — they would skew product-view counts.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// Current URL, query string stripped for storage hygiene (the
		// path is what we store and report on).
		$current_url = home_url( add_query_arg( null, null ) );
		$current_url = strtok( $current_url, '?' );

		wp_enqueue_script(
			'fishotel-visitor-stats-beacon',
			Visitor_Stats::url() . 'js/visitor-stats-beacon.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-visitor-stats-beacon',
			'fishotelStatsBeacon',
			array(
				'restUrl' => esc_url_raw( rest_url( Visitor_Stats::REST_NAMESPACE . Visitor_Stats::REST_ROUTE ) ),
				'url'     => esc_url_raw( $current_url ),
				'title'   => wp_get_document_title(),
				'postId'  => is_singular() ? get_queried_object_id() : 0,
			)
		);
	}
}
