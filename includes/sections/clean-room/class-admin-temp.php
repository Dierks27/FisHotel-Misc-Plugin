<?php
/**
 * Clean Room admin — the temporary tanks and the day's list on the Room
 * Board: the "To do" tab (everything due today, by water system — Todo),
 * the Temporary area of the Board (the slots, "Set up a temporary tank",
 * each setup's card with its own schedule editor, Edit and Take down),
 * the history of the setups that were taken down, and the one AJAX
 * action behind all of their writes.
 *
 * Every write is the same action the phone posts (Temp_Tanks::perform():
 * setup, setup_edit, setup_steps, takedown, todo_tick), behind
 * manage_woocommerce and the Board's nonce — request() checks both itself
 * and answers with a status, so it runs headless; ajax() only sends the
 * answer.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Temp
 */
class Admin_Temp {

	/**
	 * The AJAX action of the Board's temporary-tank and to-do writes.
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_cleanroom_temp';

	/**
	 * The tab this class renders.
	 *
	 * @var string
	 */
	const TAB = 'todo';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 21 );
	}

	/**
	 * The To do tab.
	 *
	 * @return string
	 */
	public static function todo_url() {
		return Admin::page_url( self::TAB );
	}

	/**
	 * The history of the temporary tanks: the list, or one setup.
	 *
	 * @param int $setup_id Setup id (0 = the list).
	 * @return string
	 */
	public static function history_url( $setup_id = 0 ) {
		return Admin::page_url( 'board', (int) $setup_id > 0 ? array( 'setup' => (int) $setup_id ) : array( 'temp_history' => '1' ) );
	}

	/**
	 * Enqueue the small script and stylesheet of these screens on the
	 * section page (after the section's own, which they build on).
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Clean_Room::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'fishotel-cleanroom-temp', Clean_Room::url() . 'css/temp-tanks-admin.css', array( 'fishotel-cleanroom-admin' ), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-cleanroom-temp', Clean_Room::url() . 'js/temp-tanks-admin.js', array( 'jquery' ), FISHOTEL_MISC_VERSION, true );
		wp_localize_script(
			'fishotel-cleanroom-temp',
			'fhCleanRoomTemp',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( Admin::NONCE_AJAX ),
				'i18n'    => array(
					'error'        => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'reloading'    => __( 'Saved. Reloading…', 'fishotel-misc-plugin' ),
					/* translators: %s: what is set up (name or slot) */
					'confirmDown'  => __( 'Take %s down? The slot is free again; its history is kept.', 'fishotel-misc-plugin' ),
					'confirmStep'  => __( 'Remove this step from this tank\'s schedule? The template stays as it is.', 'fishotel-misc-plugin' ),
					'usedFor'      => __( 'Used for this', 'fishotel-misc-plugin' ),
					'others'       => __( 'Other schedules', 'fishotel-misc-plugin' ),
					'noTreatment'  => __( 'No treatment, just watching', 'fishotel-misc-plugin' ),
					/* translators: %s: what it is for */
					'noneForTag'   => __( 'No schedule is marked "%s" yet (Settings → Treatment schedules → Used for). Pick any schedule, or no treatment.', 'fishotel-misc-plugin' ),
					'justFeeding'  => __( 'Only the daily feeding, while fish are in it.', 'fishotel-misc-plugin' ),
					'whatItDoes'   => __( 'What it does — nothing is saved yet:', 'fishotel-misc-plugin' ),
					'feedingToo'   => __( 'plus the daily feeding', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  The write                                                      */
	/* ============================================================== */

	/**
	 * One temporary-tank or to-do write from the Board — the testable half
	 * of ajax(): the capability and the nonce first, then the same switch
	 * the phone's action runs (Temp_Tanks::perform()).
	 *
	 * @param object $store    Store (or the tests' stand-in).
	 * @param array  $raw      Unslashed POST: nonce, act, tank (the slot's number, when the act is about one),
	 *                         tick_tank (the tank a tick is on), …
	 * @param string $today    Site date ('' = Clean_Room::today()).
	 * @param array  $settings Settings (null = Settings::get()).
	 * @return array{status: int, ok: bool, message: string, tank: int}
	 */
	public static function request( $store, array $raw, $today = '', $settings = null ) {
		$out = array(
			'status'  => 403,
			'ok'      => false,
			'message' => '',
			'tank'    => 0,
		);
		if ( ! current_user_can( Clean_Room::CAPABILITY ) ) {
			$out['message'] = __( 'Unauthorized.', 'fishotel-misc-plugin' );
			return $out;
		}
		$nonce = isset( $raw['nonce'] ) && is_scalar( $raw['nonce'] ) ? (string) $raw['nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, Admin::NONCE_AJAX ) ) {
			$out['message'] = __( 'This page has expired. Reload it and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$act = isset( $raw['act'] ) && is_scalar( $raw['act'] ) ? sanitize_key( (string) $raw['act'] ) : '';
		if ( ! in_array( $act, Temp_Tanks::ACTS, true ) ) {
			$out['status']  = 400;
			$out['message'] = __( 'Unknown action.', 'fishotel-misc-plugin' );
			return $out;
		}

		$number = isset( $raw['tank'] ) && is_scalar( $raw['tank'] ) && is_numeric( $raw['tank'] ) ? (int) $raw['tank'] : 0;
		try {
			$result = Temp_Tanks::perform(
				$act,
				array(
					'store'    => $store,
					'settings' => is_array( $settings ) ? $settings : Settings::get(),
					'today'    => Replay::is_date( $today ) ? (string) $today : Clean_Room::today(),
					// The slot the act is about (a tick names its own tank, as tick_tank).
					'tank'     => $number > 0 ? $store->tank_by_number( $number ) : null,
					'base'     => array(
						'actor_id' => (int) get_current_user_id(),
						'source'   => 'manual',
					),
				),
				$raw
			);
		} catch ( \Throwable $e ) {
			$result = array( 'error' => $e->getMessage() );
		}

		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			$out['status']  = 400;
			$out['message'] = is_array( $result ) ? (string) $result['error'] : __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['status']  = 200;
		$out['ok']      = true;
		$out['message'] = (string) ( $result['message'] ?? __( 'Saved.', 'fishotel-misc-plugin' ) );
		$out['tank']    = (int) ( $result['tank'] ?? 0 );

		return $out;
	}

	/**
	 * AJAX: one temporary-tank or to-do write (request()).
	 */
	public function ajax() {
		$outcome = self::request( new Store(), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in request().
		if ( ! $outcome['ok'] ) {
			wp_send_json_error( array( 'message' => $outcome['message'] ), $outcome['status'] );
		}
		wp_send_json_success(
			array(
				'message' => $outcome['message'],
				'tank'    => $outcome['tank'],
			)
		);
	}

	/* ============================================================== */
	/*  The Board's Temporary area                                     */
	/* ============================================================== */

	/**
	 * What the Board's Temporary area shows: the slots (each with its
	 * setup, its occupants, its to-do and its last reading), the last
	 * dose per medication of every setup, what the set-up form offers and
	 * how many setups are in the history. 'building' is null when the
	 * layout has no own-water building — the screen then says so.
	 *
	 * @param object  $store     Store (or stand-in).
	 * @param array[] $tanks     Tanks with occupants (room()['tanks']).
	 * @param array   $protocols Settings::protocols().
	 * @param string  $today     Site date.
	 * @return array{building: array|null, slots: array[], doses: array, sheet: array, closed: int}
	 */
	public static function board( $store, array $tanks, array $protocols, $today ) {
		$slots = Temp_Tanks::slots( $tanks );
		$ids   = array();
		foreach ( $slots as $tank ) {
			if ( ! empty( $tank['setup'] ) ) {
				$ids[] = (int) $tank['setup']['id'];
			}
		}

		return array(
			'building' => $store->temp_building(),
			'slots'    => $slots,
			'doses'    => $ids ? $store->setup_doses( $ids ) : array(),
			'sheet'    => Front_Desk::temp_sheet( $tanks, $protocols, $today ),
			'closed'   => count( $store->closed_setups() ),
		);
	}

	/* ============================================================== */
	/*  Pages                                                          */
	/* ============================================================== */

	/**
	 * The To do tab: everything due today, by water system.
	 *
	 * @param array $ctx store, settings, today, tables_ok, can_settings.
	 */
	public static function render_todo( array $ctx ) {
		$store        = $ctx['store'];
		$settings     = (array) $ctx['settings'];
		$today        = (string) $ctx['today'];
		$tables_ok    = ! empty( $ctx['tables_ok'] );
		$can_settings = ! empty( $ctx['can_settings'] );
		$tab          = self::TAB;

		$room = $tables_ok ? $store->room( $today, $settings, Settings::protocols() ) : array( 'todo' => null );
		$list = Front_Desk::todo_screen( is_array( $room['todo'] ) ? $room['todo'] : Todo::build( array(), array(), array(), array(), $today, $settings ) );

		include Clean_Room::path() . 'views/todo.php';
	}

	/**
	 * The history of the temporary tanks: every setup that was taken
	 * down, or one setup in full.
	 *
	 * @param array $ctx      store, today, tables_ok, can_settings.
	 * @param int   $setup_id Setup id (0 = the list).
	 */
	public static function render_history( array $ctx, $setup_id = 0 ) {
		$store        = $ctx['store'];
		$today        = (string) $ctx['today'];
		$tables_ok    = ! empty( $ctx['tables_ok'] );
		$can_settings = ! empty( $ctx['can_settings'] );
		$tab          = 'board';

		$setup   = (int) $setup_id > 0 ? $store->setup( (int) $setup_id ) : null;
		$history = $setup ? Temp_Tanks::history( $store, $setup ) : null;
		$closed  = $setup ? array() : $store->closed_setups();
		$missing = (int) $setup_id > 0 && ! $setup;

		include Clean_Room::path() . 'views/temp-history.php';
	}

	/* ============================================================== */
	/*  Rendering helpers                                              */
	/* ============================================================== */

	/**
	 * "Metronidazole · suggested 5.1 g for 205 gal" — what a line of the
	 * list says beside its step ('' when the step names neither).
	 *
	 * @param array $item A Todo item.
	 * @return string
	 */
	public static function line_detail( array $item ) {
		$bits = array();
		if ( '' !== (string) ( $item['medication'] ?? '' ) ) {
			$bits[] = (string) $item['medication'];
		}
		if ( '' !== (string) ( $item['dose'] ?? '' ) ) {
			$bits[] = (string) $item['dose'];
		}
		return implode( ' · ', $bits );
	}

	/**
	 * "Feed — 7 tanks with fish" (and "· 3 left" while some are not fed).
	 *
	 * @param array $item A Todo feed line.
	 * @return string
	 */
	public static function feed_text( array $item ) {
		$count = (int) ( $item['count'] ?? 0 );
		/* translators: 1: the feeding step, 2: number of tanks */
		$text = sprintf( _n( '%1$s — %2$d tank with fish', '%1$s — %2$d tanks with fish', $count, 'fishotel-misc-plugin' ), (string) $item['label'], $count );
		$left = (int) ( $item['left'] ?? 0 );
		if ( $left > 0 && $left < $count ) {
			/* translators: %d: tanks still to feed */
			$text .= ' · ' . sprintf( __( '%d left', 'fishotel-misc-plugin' ), $left );
		}
		return $text;
	}

	/**
	 * "Cu 2.4 · tank 513 · 3 h ago" for a block ('' without a reading).
	 *
	 * @param array $copper A block's copper (value, tank, ago, state).
	 * @return string
	 */
	public static function copper_text( array $copper ) {
		if ( null === ( $copper['value'] ?? null ) ) {
			return '';
		}
		$text = 'Cu ' . rtrim( rtrim( number_format( (float) $copper['value'], 2, '.', '' ), '0' ), '.' );
		if ( (int) ( $copper['tank'] ?? 0 ) > 0 ) {
			/* translators: %d: tank number */
			$text .= ' · ' . sprintf( __( 'tank %d', 'fishotel-misc-plugin' ), (int) $copper['tank'] );
		}
		if ( '' !== (string) ( $copper['ago'] ?? '' ) ) {
			/* translators: %s: age, e.g. "3 h" */
			$text .= ' · ' . sprintf( __( '%s ago', 'fishotel-misc-plugin' ), (string) $copper['ago'] );
		}
		return $text;
	}

	/**
	 * A copper flag at the top of the list, in words: "Building 500 · Cu
	 * 2.1 — below the floor", "… · copper reading due — last one 2.4,
	 * 26 h ago", "… · copper reading overdue — no reading yet".
	 *
	 * @param array $flag Todo::copper_flag() (+ ago).
	 * @return string
	 */
	public static function flag_text( array $flag ) {
		$value = null !== ( $flag['value'] ?? null ) ? rtrim( rtrim( number_format( (float) $flag['value'], 2, '.', '' ), '0' ), '.' ) : '';
		$bits  = array( (string) $flag['where'] );
		if ( ! empty( $flag['low'] ) ) {
			/* translators: %s: reading */
			$bits[] = sprintf( __( 'Cu %s — below the floor', 'fishotel-misc-plugin' ), $value );
		}
		if ( ! empty( $flag['due'] ) ) {
			$what = ! empty( $flag['overdue'] ) ? __( 'copper reading overdue', 'fishotel-misc-plugin' ) : __( 'copper reading due', 'fishotel-misc-plugin' );
			if ( '' === $value ) {
				$what .= ' — ' . __( 'no reading yet', 'fishotel-misc-plugin' );
			} else {
				/* translators: 1: reading, 2: age */
				$what .= ' — ' . sprintf( __( 'last one %1$s, %2$s ago', 'fishotel-misc-plugin' ), $value, (string) ( $flag['ago'] ?? '' ) );
			}
			$bits[] = $what;
		}
		return implode( ' · ', $bits );
	}
}
