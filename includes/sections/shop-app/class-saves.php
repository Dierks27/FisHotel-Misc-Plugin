<?php
/**
 * Shop App saves — a Front Desk write that is sent twice is written once.
 *
 * WHY: at a tank the connection drops. When a save fails the app keeps
 * what was entered and offers Retry — but "failed" seen from the phone
 * only means no answer came back; the server may well have written it. A
 * blind Retry would then count the same dead fish twice. So inside the
 * app every write of the Front Desk (its one action,
 * fishotel_cleanroom_act) carries a save id the app made up, and the same
 * id on every retry:
 *
 *   - first time: the id is claimed (a unique key, so of two requests at
 *     the same instant one wins), the Front Desk's own handler runs
 *     untouched, and at the end of the request its answer is looked at —
 *     a success is remembered (the toast text, the undo ids, the tank), a
 *     refusal gives the claim back;
 *   - the same id again after a success: the Front Desk's handler does
 *     NOT run; the remembered answer is repeated with a freshly read
 *     tank screen;
 *   - the same id again while the first is still running: "still
 *     saving" (HTTP 409) — the app waits and asks again.
 *
 * Nothing in the Clean Room is changed for this: it hooks the action
 * before the Front Desk's handler (priority 1, after the Gate's grant at
 * 0), checks the same capability and nonce the handler checks, and only
 * steps in for a request made with a live shop session that carries an
 * id. It is also the seam an offline queue will need: queued writes can
 * be replayed without fear.
 *
 * Remembered saves are kept for an hour.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

namespace FisHotel\Misc\Sections\Shop_App;

defined( 'ABSPATH' ) || exit;

/**
 * Class Saves
 */
class Saves {

	/**
	 * The POST field carrying the save id.
	 *
	 * @var string
	 */
	const FIELD = 'fh_save_id';

	/**
	 * A claim that did not finish within this long belongs to a request
	 * that died, and may be taken over (seconds).
	 *
	 * @var int
	 */
	const PENDING_SECONDS = 30;

	/**
	 * How long a save is remembered (seconds).
	 *
	 * @var int
	 */
	const KEEP_SECONDS = 3600;

	/**
	 * The claimed key of this request ('' = none).
	 *
	 * @var string
	 */
	private static $key = '';

	/**
	 * The output-buffer level this class opened.
	 *
	 * @var int
	 */
	private static $level = 0;

	/**
	 * Called by the Gate when a shop session was recognised on an AJAX
	 * rule: step in front of the Front Desk's write action only.
	 *
	 * @param array $rule The rule the request falls under.
	 */
	public static function arm( array $rule ) {
		$desk = Shop_App::front_desk();
		if ( 'ajax' === $rule['type'] && $rule['action'] === $desk['write'] ) {
			add_action( 'wp_ajax_' . $rule['action'], array( self::class, 'before' ), 1 );
		}
	}

	/**
	 * Whether a value has the shape of a save id.
	 *
	 * @param mixed $id Posted value.
	 * @return bool
	 */
	public static function is_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{16,64}$/D', $id );
	}

	/**
	 * The key a save is remembered under: the id is only ever meaningful
	 * for the device and the account that sent it.
	 *
	 * @param int    $device_id Device id.
	 * @param int    $user_id   The session's account.
	 * @param string $id        The save id.
	 * @return string 64 hex characters.
	 */
	public static function key( $device_id, $user_id, $id ) {
		return hash( 'sha256', (int) $device_id . '|' . (int) $user_id . '|' . (string) $id );
	}

	/**
	 * The short answer worth remembering from the Front Desk's reply
	 * (pure): null unless the reply was a success.
	 *
	 * @param mixed  $status The HTTP status of the reply.
	 * @param string $body   The reply's body.
	 * @return array{message: string, undo: array{eventIds: int[], label: string}, tank: int}|null
	 */
	public static function result_of( $status, $body ) {
		// No status at all (false) means none was set: PHP's default, 200.
		if ( false !== $status && null !== $status && 200 !== (int) $status ) {
			return null;
		}

		$json = json_decode( (string) $body, true );
		if ( ! is_array( $json ) || empty( $json['success'] ) || true !== $json['success'] ) {
			return null;
		}

		$data = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
		$undo = isset( $data['undo'] ) && is_array( $data['undo'] ) ? $data['undo'] : array();
		$ids  = isset( $undo['eventIds'] ) && is_array( $undo['eventIds'] ) ? array_values( array_map( 'intval', $undo['eventIds'] ) ) : array();
		$tank = isset( $data['screen']['tank']['number'] ) ? (int) $data['screen']['tank']['number'] : 0;

		return array(
			'message' => isset( $data['message'] ) && is_scalar( $data['message'] ) ? (string) $data['message'] : '',
			'undo'    => array(
				'eventIds' => $ids,
				'label'    => isset( $undo['label'] ) && is_scalar( $undo['label'] ) ? (string) $undo['label'] : '',
			),
			'tank'    => $tank,
		);
	}

	/**
	 * The reply repeated for a save that already went through (pure
	 * apart from reading the tank screen afresh).
	 *
	 * @param array $result result_of().
	 * @return array
	 */
	public static function replay_data( array $result ) {
		$desk   = '\\FisHotel\\Misc\\Sections\\Clean_Room\\Front_Desk';
		$screen = null;
		if ( class_exists( $desk, false ) && method_exists( $desk, 'screen_data' ) ) {
			try {
				$screen = call_user_func( array( $desk, 'screen_data' ), (int) ( $result['tank'] ?? 0 ) );
			} catch ( \Throwable $e ) {
				$screen = null;
			}
		}

		$out = array(
			'message'  => (string) ( $result['message'] ?? '' ),
			'undo'     => array(
				'eventIds' => array_values( array_map( 'intval', (array) ( $result['undo']['eventIds'] ?? array() ) ) ),
				'label'    => (string) ( $result['undo']['label'] ?? '' ),
			),
			'replayed' => true,
		);
		if ( null !== $screen ) {
			$out['screen'] = $screen;
		}
		return $out;
	}

	/**
	 * wp_ajax_fishotel_cleanroom_act, priority 1 — before the Front
	 * Desk's own handler.
	 */
	public static function before() {
		if ( ! Gate::is_shop_session() ) {
			return;
		}

		$id = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shape-checked by is_id(); the nonce is verified just below.
		if ( ! self::is_id( $id ) ) {
			return;
		}

		// The same two checks the Front Desk's handler makes. When either
		// fails this steps aside and that handler gives its own answer.
		$desk = Shop_App::front_desk();
		if ( ! current_user_can( $desk['capability'] ) || ! check_ajax_referer( $desk['nonce'], 'nonce', false ) ) {
			return;
		}

		$person = Gate::person();
		$access = Access::instance();
		$store  = $access->store();
		$now    = $access->now();
		$key    = self::key( $person['device_id'], $person['user_id'], $id );

		$store->prune_saves( $now - self::KEEP_SECONDS );
		$claim = $store->claim_save( $key, $person['device_id'], $now, self::PENDING_SECONDS );

		if ( 'claimed' === $claim ) {
			self::$key = $key;
			ob_start();
			self::$level = ob_get_level();
			// Before WordPress flushes the buffers (shutdown, priority 1).
			add_action( 'shutdown', array( self::class, 'after' ), 0 );
			return;
		}

		if ( is_array( $claim ) && 1 === (int) $claim['status'] ) {
			$result = json_decode( (string) $claim['result'], true );
			wp_send_json_success( self::replay_data( is_array( $result ) ? $result : array() ) );
		}

		if ( is_array( $claim ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Still saving — one moment.', 'fishotel-misc-plugin' ),
					'pending' => true,
				),
				409
			);
		}

		// The store could not say (a database error): let the save run as
		// it would without an id.
	}

	/**
	 * At the end of the request: remember the save when the Front Desk
	 * said it went through, give the claim back when it did not.
	 */
	public static function after() {
		if ( '' === self::$key ) {
			return;
		}
		$key       = self::$key;
		self::$key = '';

		$body = '';
		while ( ob_get_level() > self::$level ) {
			ob_end_flush();
		}
		if ( self::$level > 0 && ob_get_level() === self::$level ) {
			$body = (string) ob_get_contents();
		}

		$store  = Access::instance()->store();
		$result = self::result_of( function_exists( 'http_response_code' ) ? http_response_code() : 200, $body );

		if ( null !== $result ) {
			$store->finish_save( $key, (string) wp_json_encode( $result ) );
		} else {
			$store->release_save( $key );
		}
	}

	/**
	 * Start over (tests).
	 */
	public static function reset() {
		self::$key   = '';
		self::$level = 0;
	}
}
