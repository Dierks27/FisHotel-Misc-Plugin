<?php
/**
 * Weather checker — one order end to end, and the nightly scan.
 *
 * For an order: read the delivery date (`_fishotel_shipping_date`, Y-m-d,
 * written by the Batch plugin) and the shipping address, geocode, fetch
 * the gridpoint forecast, take the daytime period for the delivery date
 * (and the day after for the extremes line), classify the HIGH, build the
 * note, and persist: ONE private order note plus structured meta. A
 * fulfillment order (combined box) is the destination; with the
 * `note_sources` setting on, each source order gets the same note and
 * meta so customers' orders carry it too.
 *
 * Any failure between the address and the forecast produces the
 * `unavailable` action and a "check manually" note — never a band guessed
 * from partial data.
 *
 * @package FisHotel\Misc\Sections\Weather
 */

namespace FisHotel\Misc\Sections\Weather;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checker
 */
class Checker {

	/**
	 * Order meta written by this section.
	 */
	const META_CHECKED_FOR     = '_fh_weather_checked_for';
	const META_CHECKED_AT      = '_fh_weather_checked_at';
	const META_HIGH            = '_fh_weather_high';
	const META_LOW             = '_fh_weather_low';
	const META_ACTION          = '_fh_weather_action';
	const META_SUMMARY         = '_fh_weather_summary';
	const META_NOTE            = '_fh_weather_note';
	const META_EMAIL_DRAFT     = '_fh_weather_email_draft';
	const META_AT_LINE         = '_fh_weather_at_line';
	const META_UNAVAILABLE_FOR = '_fh_weather_unavailable_for';

	/**
	 * Order meta read from other FisHotel code.
	 */
	const META_DELIVERY_DATE  = '_fishotel_shipping_date';
	const META_IS_FULFILLMENT = '_fishotel_is_fulfillment';
	const META_SOURCES        = '_fishotel_fulfillment_sources';
	const META_FULFILLED_BY   = '_fishotel_fulfilled_by_order';

	/**
	 * The nightly job looks at delivery dates from today up to this many
	 * days out.
	 *
	 * WHY today and not tomorrow: WP-Cron is request-driven, so the 21:00
	 * event on a quiet night commonly fires after midnight; by then
	 * "tomorrow" has become "today" and a window starting at +1 would skip
	 * the nearest delivery date entirely. Already-noted orders are skipped,
	 * so including today costs nothing on an on-time run.
	 *
	 * @var int
	 */
	const DAYS_AHEAD = 3;

	/**
	 * Delivery dates within this many days of today are re-checked every
	 * night even when already noted, so the morning Jeff processes an order
	 * works from the previous evening's forecast rather than one taken up to
	 * three days earlier. A date further out is checked once and then left
	 * until it comes within this distance.
	 *
	 * @var int
	 */
	const REFRESH_DAYS = 2;

	/**
	 * Source-order statuses that never receive the fan-out note: the same
	 * exclusion list the theme's fulfillment workflow uses when it mirrors
	 * completion to sources (plus trash).
	 *
	 * @var string[]
	 */
	const DEAD_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * The dates the nightly job scans: today .. today + DAYS_AHEAD.
	 *
	 * @param string $today Y-m-d (site date).
	 * @return string[]
	 */
	public static function target_dates( $today ) {
		$dates = array();
		for ( $i = 0; $i <= self::DAYS_AHEAD; $i++ ) {
			$dates[] = Note::add_days( $today, $i );
		}
		return $dates;
	}

	/**
	 * Whether the nightly job re-checks an already-noted order for a
	 * delivery date: yes when the date is within REFRESH_DAYS of today.
	 *
	 * @param string $delivery_ymd Y-m-d.
	 * @param string $today        Y-m-d (site date).
	 * @return bool
	 */
	public static function should_refresh( $delivery_ymd, $today ) {
		return $delivery_ymd <= Note::add_days( $today, self::REFRESH_DAYS );
	}

	/**
	 * Delivery date on an order, or '' when missing / malformed.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function delivery_date( $order ) {
		$date = trim( (string) $order->get_meta( self::META_DELIVERY_DATE ) );
		return Note::date( $date ) ? $date : '';
	}

	/**
	 * Whether the order is a fulfillment wrapper (combined box).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_fulfillment( $order ) {
		return '1' === (string) $order->get_meta( self::META_IS_FULFILLMENT );
	}

	/**
	 * Source order IDs of a fulfillment wrapper.
	 *
	 * @param \WC_Order $order Order.
	 * @return int[]
	 */
	public static function source_ids( $order ) {
		$raw = $order->get_meta( self::META_SOURCES );
		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out = array();

		foreach ( $ids as $id ) {
			$id = (int) trim( (string) $id );
			if ( $id > 0 && $id !== $order->get_id() && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether the order has been folded into a fulfillment (the wrapper
	 * carries the destination and gets the note).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_folded_source( $order ) {
		return '' !== trim( (string) $order->get_meta( self::META_FULFILLED_BY ) );
	}

	/**
	 * The source orders of a fulfillment wrapper that are still live: the
	 * theme never removes a cancelled or refunded source from the sources
	 * meta, so the list is filtered here before anything is written to it.
	 *
	 * @param \WC_Order $order Fulfillment wrapper.
	 * @return \WC_Order[]
	 */
	public static function live_sources( $order ) {
		if ( ! self::is_fulfillment( $order ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$out = array();
		foreach ( self::source_ids( $order ) as $source_id ) {
			$source = wc_get_order( $source_id );
			if ( ! $source instanceof \WC_Order || $source->has_status( self::DEAD_STATUSES ) ) {
				continue;
			}
			$out[] = $source;
		}

		return $out;
	}

	/**
	 * The destination address: shipping, else billing.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{address_1: string, address_2: string, city: string, state: string, postcode: string, country: string, first_name: string}
	 */
	public static function destination( $order ) {
		$use_shipping = '' !== trim( (string) $order->get_shipping_postcode() ) || '' !== trim( (string) $order->get_shipping_city() );
		$prefix       = $use_shipping ? 'shipping' : 'billing';

		$first = trim( (string) $order->get_shipping_first_name() );
		if ( '' === $first ) {
			$first = trim( (string) $order->get_billing_first_name() );
		}

		return array(
			'address_1'  => trim( (string) $order->{"get_{$prefix}_address_1"}() ),
			'address_2'  => trim( (string) $order->{"get_{$prefix}_address_2"}() ),
			'city'       => trim( (string) $order->{"get_{$prefix}_city"}() ),
			'state'      => trim( (string) $order->{"get_{$prefix}_state"}() ),
			'postcode'   => trim( (string) $order->{"get_{$prefix}_postcode"}() ),
			'country'    => trim( (string) $order->{"get_{$prefix}_country"}() ),
			'first_name' => $first,
		);
	}

	/**
	 * Look up the forecast for a destination and classify it. No order
	 * writes — the result is what persist() stores.
	 *
	 * @param array      $destination  From self::destination().
	 * @param string     $delivery_ymd Y-m-d.
	 * @param Nws_Client $client       Client.
	 * @param array      $thresholds   Band thresholds.
	 * @return array See self::empty_result() for the shape.
	 */
	public static function lookup( array $destination, $delivery_ymd, Nws_Client $client, array $thresholds ) {
		$result = self::empty_result( $destination, $delivery_ymd );

		$country = strtoupper( (string) ( $destination['country'] ?? '' ) );
		if ( '' !== $country && 'US' !== $country ) {
			return self::unavailable( $result, sprintf( 'NWS covers US addresses only, this one is %s', $country ) );
		}

		$location = $client->locate( $destination );
		if ( is_wp_error( $location ) ) {
			return self::unavailable( $result, $location->get_error_message() );
		}

		$point = $client->point( $location['lat'], $location['lon'] );
		if ( is_wp_error( $point ) ) {
			return self::unavailable( $result, $point->get_error_message() );
		}

		$periods = $client->periods( $point );
		if ( is_wp_error( $periods ) ) {
			return self::unavailable( $result, $periods->get_error_message() );
		}

		$day = Forecast::day_summary( $periods, $delivery_ymd );
		if ( ! $day ) {
			return self::unavailable( $result, sprintf( 'no NWS daytime period for %s yet (forecasts cover 7 days)', Note::long_date( $delivery_ymd ) ) );
		}

		$band = Bands::classify( $day['high'], $thresholds );
		if ( ! Bands::is_band( $band['action'] ) ) {
			return self::unavailable( $result, 'forecast had no usable high' );
		}

		$next_ymd = Note::add_days( $delivery_ymd, 1 );
		$next     = Forecast::day_summary( $periods, $next_ymd );
		$alerts   = Forecast::alerts_for_day( $client->alerts( $location['lat'], $location['lon'] ), $delivery_ymd );

		$result['ok']         = true;
		$result['high']       = $day['high'];
		$result['low']        = $day['low'];
		$result['conditions'] = Note::conditions( $day['short'], $day['pop'] );
		$result['warnings']   = $alerts;
		$result['action']     = $band['action'];
		$result['at_line']    = $band['at_line'];
		$result['ending']     = $band['ending'];
		$result['source']     = (string) ( $location['source'] ?? '' );
		$result['grid_key']   = (string) ( $point['grid_key'] ?? '' );

		if ( $next ) {
			$result['next_day'] = array(
				'date' => $next_ymd,
				'high' => $next['high'],
				'line' => Note::next_day_line( $next_ymd, $next['high'], $thresholds ),
			);
		}

		$result['summary'] = sprintf( 'High %d°F', $result['high'] )
			. ( null !== $result['low'] ? sprintf( ' / Low %d°F', $result['low'] ) : '' )
			. ' — ' . $result['conditions'];

		$result['note'] = Note::format(
			array(
				'delivery_date' => $delivery_ymd,
				'zip'           => $destination['postcode'],
				'city'          => $destination['city'],
				'state'         => $destination['state'],
				'high'          => $result['high'],
				'low'           => $result['low'],
				'conditions'    => $result['conditions'],
				'warnings'      => $alerts,
				'ending'        => $band['ending'],
				'next_day_line' => $result['next_day'] ? $result['next_day']['line'] : null,
			)
		);

		return $result;
	}

	/**
	 * Run the lookup for one order and persist the outcome.
	 *
	 * @param \WC_Order|int   $order  Order or ID.
	 * @param bool            $force  Re-check even when already noted for this date.
	 * @param Nws_Client|null $client Client (null = a real one). The tests pass a fake.
	 * @return array|\WP_Error The result (with `order_id`, `note_status`, `sources_noted`), or WP_Error.
	 */
	public static function check_order( $order, $force = false, ?Nws_Client $client = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'fh_weather_order', __( 'Order not found.', 'fishotel-misc-plugin' ) );
		}

		$delivery = self::delivery_date( $order );
		if ( '' === $delivery ) {
			return new \WP_Error( 'fh_weather_no_date', __( 'This order has no delivery date (_fishotel_shipping_date), so there is nothing to look up.', 'fishotel-misc-plugin' ) );
		}

		if ( ! $force && (string) $order->get_meta( self::META_CHECKED_FOR ) === $delivery ) {
			$stored = self::stored( $order );
			if ( $stored ) {
				$stored['note_status']   = 'skipped';
				$stored['sources_noted'] = 0;
				return $stored;
			}
		}

		$settings = Settings::get();
		if ( ! $client instanceof Nws_Client ) {
			$client = new Nws_Client( Settings::contact_email() );
		}
		$result = self::lookup( self::destination( $order ), $delivery, $client, $settings['thresholds'] );

		$result['order_id'] = $order->get_id();
		$result['email']    = null;

		if ( $result['ok'] && Bands::is_ups_store( $result['action'] ) ) {
			$result['email'] = Note::email_draft(
				array(
					'order_number'  => $order->get_order_number(),
					'first_name'    => $result['first_name'],
					'city'          => $result['city'],
					'state'         => $result['state'],
					'delivery_date' => $delivery,
					'high'          => $result['high'],
					'action'        => $result['action'],
				)
			);
		}

		$result['note_status'] = self::persist( $order, $result );

		$result['sources_noted'] = 0;
		if ( ! empty( $settings['note_sources'] ) && self::is_fulfillment( $order ) ) {
			// Cancelled / refunded / failed sources stay listed in the
			// wrapper's meta but will not ship, so they get no note, no
			// meta and no customer email draft.
			foreach ( self::live_sources( $order ) as $source ) {
				$source_result = $result;
				if ( $source_result['email'] ) {
					// The draft names the source order's own number.
					$source_result['email'] = Note::email_draft(
						array(
							'order_number'  => $source->get_order_number(),
							'first_name'    => $result['first_name'],
							'city'          => $result['city'],
							'state'         => $result['state'],
							'delivery_date' => $delivery,
							'high'          => $result['high'],
							'action'        => $result['action'],
						)
					);
				}

				self::persist( $source, $source_result );
				$result['sources_noted']++;
			}
		}

		return $result;
	}

	/**
	 * Write the note (once per date, or when the text changed) and the
	 * structured meta.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $result Lookup result.
	 * @return string 'noted' | 'unchanged' | 'unavailable' | 'unavailable_repeat'
	 */
	private static function persist( $order, array $result ) {
		$delivery   = $result['delivery_date'];
		$checked_at = current_time( 'mysql' );
		$previous   = (string) $order->get_meta( self::META_NOTE );

		if ( $result['ok'] ) {
			$status = ( $previous === $result['note'] && (string) $order->get_meta( self::META_CHECKED_FOR ) === $delivery ) ? 'unchanged' : 'noted';

			if ( 'noted' === $status ) {
				$order->add_order_note( $result['note'], false, false );
			}

			$order->update_meta_data( self::META_CHECKED_FOR, $delivery );
			$order->update_meta_data( self::META_HIGH, (int) $result['high'] );
			$order->update_meta_data( self::META_LOW, null === $result['low'] ? '' : (int) $result['low'] );
			$order->update_meta_data( self::META_AT_LINE, $result['at_line'] ? '1' : '' );
			$order->delete_meta_data( self::META_UNAVAILABLE_FOR );

			if ( $result['email'] ) {
				$order->update_meta_data( self::META_EMAIL_DRAFT, $result['email'] );
			} else {
				$order->delete_meta_data( self::META_EMAIL_DRAFT );
			}
		} else {
			// One failure note per delivery date: the reason text carries
			// the transport detail (HTTP code, cURL message), which varies
			// from night to night on a flaky NWS, so it is not part of the
			// repeat test. The latest reason is still stored in the summary
			// meta and shown in the meta box.
			$status = ( (string) $order->get_meta( self::META_UNAVAILABLE_FOR ) === $delivery ) ? 'unavailable_repeat' : 'unavailable';

			if ( 'unavailable' === $status ) {
				$order->add_order_note( $result['note'], false, false );
			}

			// A failed lookup must not look like a result: clear the
			// numbers, record that it was tried, and drop the success
			// marker — even when this date HAD been noted before — so
			// note_status() reads 'unavailable' and the nightly job retries
			// tomorrow instead of treating the order as already noted.
			$order->update_meta_data( self::META_UNAVAILABLE_FOR, $delivery );
			$order->update_meta_data( self::META_HIGH, '' );
			$order->update_meta_data( self::META_LOW, '' );
			$order->update_meta_data( self::META_AT_LINE, '' );
			$order->delete_meta_data( self::META_EMAIL_DRAFT );
			$order->delete_meta_data( self::META_CHECKED_FOR );
		}

		$order->update_meta_data( self::META_ACTION, $result['action'] );
		$order->update_meta_data( self::META_SUMMARY, $result['ok'] ? $result['summary'] : $result['reason'] );
		$order->update_meta_data( self::META_NOTE, $result['note'] );
		$order->update_meta_data( self::META_CHECKED_AT, $checked_at );
		$order->save();

		return $status;
	}

	/**
	 * The stored result on an order, or null when never checked.
	 *
	 * @param \WC_Order $order Order.
	 * @return array|null
	 */
	public static function stored( $order ) {
		$action = (string) $order->get_meta( self::META_ACTION );
		if ( '' === $action ) {
			return null;
		}

		$high  = $order->get_meta( self::META_HIGH );
		$low   = $order->get_meta( self::META_LOW );
		$email = $order->get_meta( self::META_EMAIL_DRAFT );

		return array(
			'ok'              => Bands::is_band( $action ),
			'order_id'        => $order->get_id(),
			'action'          => $action,
			'high'            => is_numeric( $high ) ? (int) $high : null,
			'low'             => is_numeric( $low ) ? (int) $low : null,
			'at_line'         => '1' === (string) $order->get_meta( self::META_AT_LINE ),
			'summary'         => (string) $order->get_meta( self::META_SUMMARY ),
			'note'            => (string) $order->get_meta( self::META_NOTE ),
			'email'           => ( is_array( $email ) && isset( $email['subject'], $email['body'] ) ) ? $email : null,
			'checked_for'     => (string) $order->get_meta( self::META_CHECKED_FOR ),
			'checked_at'      => (string) $order->get_meta( self::META_CHECKED_AT ),
			'unavailable_for' => (string) $order->get_meta( self::META_UNAVAILABLE_FOR ),
			'delivery_date'   => self::delivery_date( $order ),
		);
	}

	/**
	 * Note status of an order for a delivery date, for the overview.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $ymd   Y-m-d.
	 * @return string 'noted' | 'unavailable' | 'stale' | 'none'
	 */
	public static function note_status( $order, $ymd ) {
		$checked_for = (string) $order->get_meta( self::META_CHECKED_FOR );
		if ( $checked_for === $ymd ) {
			return 'noted';
		}
		if ( (string) $order->get_meta( self::META_UNAVAILABLE_FOR ) === $ymd ) {
			return 'unavailable';
		}
		return '' !== $checked_for ? 'stale' : 'none';
	}

	/**
	 * Open orders in the scanned statuses, optionally only those whose
	 * delivery date is in a list. Store-agnostic: the meta filter is passed
	 * to the query only on HPOS (the CPT store drops it and, since WC 9.2,
	 * logs a doing_it_wrong), and every returned order is re-checked
	 * through get_meta() regardless.
	 *
	 * @param string[]      $statuses Statuses without `wc-`.
	 * @param string[]|null $dates    Y-m-d list, or null for any dated order.
	 * @return \WC_Order[]
	 */
	public static function open_orders( array $statuses, $dates = null ) {
		if ( ! function_exists( 'wc_get_orders' ) || empty( $statuses ) ) {
			return array();
		}

		$args = array(
			'type'    => 'shop_order',
			'status'  => array_values( $statuses ),
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( self::hpos_enabled() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS honours it; CPT path filters in PHP.
				is_array( $dates ) && $dates
					? array(
						'key'     => self::META_DELIVERY_DATE,
						'value'   => array_values( $dates ),
						'compare' => 'IN',
					)
					: array(
						'key'     => self::META_DELIVERY_DATE,
						'compare' => 'EXISTS',
					),
			);
		}

		$out = array();
		foreach ( (array) wc_get_orders( $args ) as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$date = self::delivery_date( $order );
			if ( '' === $date ) {
				continue;
			}
			if ( is_array( $dates ) && ! in_array( $date, $dates, true ) ) {
				continue;
			}

			$out[] = $order;
		}

		return $out;
	}

	/**
	 * The nightly scan. Also what "Run nightly scan now" runs, so the
	 * `enabled` toggle is NOT honoured here — it only governs whether the
	 * cron event is armed (Cron::schedule / Cron::run).
	 *
	 * Orders delivering within REFRESH_DAYS of today are re-checked even
	 * when already noted (persist() writes a new note only when the text
	 * changed, and the forecast transient makes it one cached fetch per
	 * gridpoint); orders further out are checked once.
	 *
	 * @param array|null      $settings Settings (null = load).
	 * @param Nws_Client|null $client   Client (null = a real one). The tests pass a fake.
	 * @return array{at: string, dates: string[], checked: int, noted: int, unavailable: int, skipped: int, errors: int}
	 */
	public static function run_nightly( $settings = null, ?Nws_Client $client = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();
		$today    = current_time( 'Y-m-d' );
		$dates    = self::target_dates( $today );

		$summary = array(
			'at'          => current_time( 'mysql' ),
			'dates'       => $dates,
			'checked'     => 0,
			'noted'       => 0,
			'unavailable' => 0,
			'skipped'     => 0,
			'errors'      => 0,
		);

		foreach ( self::open_orders( $settings['statuses'], $dates ) as $order ) {
			if ( self::is_folded_source( $order ) ) {
				$summary['skipped']++;
				continue;
			}

			$force  = self::should_refresh( self::delivery_date( $order ), $today );
			$result = self::check_order( $order, $force, $client );

			if ( is_wp_error( $result ) ) {
				$summary['errors']++;
				continue;
			}

			$status = (string) ( $result['note_status'] ?? '' );
			if ( 'skipped' === $status ) {
				$summary['skipped']++;
				continue;
			}

			$summary['checked']++;
			if ( ! empty( $result['ok'] ) ) {
				$summary['noted']++;
			} else {
				$summary['unavailable']++;
			}
		}

		update_option( Weather::LAST_RUN_OPTION, $summary, false );

		return $summary;
	}

	/**
	 * True when WooCommerce's HPOS orders table is the active order store.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		return class_exists( $util )
			&& is_callable( array( $util, 'custom_orders_table_usage_is_enabled' ) )
			&& (bool) call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) );
	}

	/**
	 * The result skeleton every lookup starts from.
	 *
	 * @param array  $destination  Destination.
	 * @param string $delivery_ymd Y-m-d.
	 * @return array
	 */
	private static function empty_result( array $destination, $delivery_ymd ) {
		return array(
			'ok'            => false,
			'delivery_date' => $delivery_ymd,
			'zip'           => (string) ( $destination['postcode'] ?? '' ),
			'city'          => (string) ( $destination['city'] ?? '' ),
			'state'         => (string) ( $destination['state'] ?? '' ),
			'first_name'    => (string) ( $destination['first_name'] ?? '' ),
			'high'          => null,
			'low'           => null,
			'conditions'    => '',
			'warnings'      => array(),
			'action'        => Bands::ACTION_UNAVAILABLE,
			'at_line'       => false,
			'ending'        => '',
			'summary'       => '',
			'note'          => '',
			'next_day'      => null,
			'reason'        => '',
			'source'        => '',
			'grid_key'      => '',
		);
	}

	/**
	 * Mark a result unavailable with a reason and the matching note.
	 *
	 * @param array  $result Result skeleton.
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function unavailable( array $result, $reason ) {
		$result['ok']     = false;
		$result['action'] = Bands::ACTION_UNAVAILABLE;
		$result['reason'] = (string) $reason;
		$result['note']   = Note::unavailable(
			array(
				'delivery_date' => $result['delivery_date'],
				'zip'           => $result['zip'],
				'city'          => $result['city'],
				'state'         => $result['state'],
				'reason'        => $reason,
			)
		);
		$result['summary'] = (string) $reason;

		return $result;
	}
}
