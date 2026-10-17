<?php
/**
 * Clean Room settings — option storage, defaults, the sanitised AJAX
 * saves (settings, the protocol template), and the layout-editor / alias
 * actions of the Settings tab. Everything here needs manage_options and a
 * nonce.
 *
 * Two options:
 *   fishotel_cleanroom_settings   sale_ready_days (a stay is sale-ready that
 *                                 many days after check-in, default 28), the
 *                                 copper floor and target the
 *                                 tank screen warns against (2.25 / 2.5),
 *                                 the voice preview's auto-confirm seconds
 *                                 and whether the Mic button is offered;
 *   fishotel_cleanroom_protocol   the treatment template (Protocol), seeded
 *                                 with the rough version on first read.
 *
 * The layout (buildings and tanks) and the learned aliases live in their
 * tables, written through the Store with the numbering rule enforced by
 * Layout::validate_*().
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Nonce action for the settings form and every Settings-tab action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_cleanroom_settings';

	/**
	 * Default values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'sale_ready_days'      => 28,
		'copper_floor'         => 2.25,
		'copper_target'        => 2.5,
		'auto_confirm_seconds' => 4,
		'voice_enabled'        => true,
	);

	const SALE_READY_MIN   = 1;
	const SALE_READY_MAX   = 120;
	const COPPER_MIN       = 0.0;
	const COPPER_MAX       = 9.99;
	const AUTO_CONFIRM_MIN = 0;
	const AUTO_CONFIRM_MAX = 15;

	/**
	 * The least the undo toast stays (seconds): it then stays until the
	 * next action on the phone, so a wet thumb has time to change its
	 * mind. Fixed, not a setting; the server honours an undo for
	 * Front_Desk::UNDO_WINDOW regardless.
	 *
	 * @var int
	 */
	const UNDO_SECONDS = 10;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_cleanroom_save', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_protocol', array( $this, 'save_protocol' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_layout', array( $this, 'ajax_layout' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_alias_delete', array( $this, 'ajax_alias_delete' ) );
	}

	/* ============================================================== */
	/*  Settings                                                       */
	/* ============================================================== */

	/**
	 * Current settings, merged with defaults and normalised.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( Clean_Room::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::normalize( wp_parse_args( $stored, self::DEFAULTS ) );
	}

	/**
	 * Clamp and type every key.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function normalize( array $settings ) {
		$floor  = self::clamp_float( $settings['copper_floor'] ?? null, self::COPPER_MIN, self::COPPER_MAX, self::DEFAULTS['copper_floor'] );
		$target = self::clamp_float( $settings['copper_target'] ?? null, self::COPPER_MIN, self::COPPER_MAX, self::DEFAULTS['copper_target'] );
		if ( $target < $floor ) {
			$target = $floor;
		}

		return array(
			'sale_ready_days'      => self::clamp( $settings['sale_ready_days'] ?? null, self::SALE_READY_MIN, self::SALE_READY_MAX, self::DEFAULTS['sale_ready_days'] ),
			'copper_floor'         => $floor,
			'copper_target'        => $target,
			'auto_confirm_seconds' => self::clamp( $settings['auto_confirm_seconds'] ?? null, self::AUTO_CONFIRM_MIN, self::AUTO_CONFIRM_MAX, self::DEFAULTS['auto_confirm_seconds'] ),
			'voice_enabled'        => ! empty( $settings['voice_enabled'] ),
		);
	}

	/**
	 * The options the Replay needs, from the settings.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function replay_opts( array $settings ) {
		return array(
			'sale_ready_days' => (int) ( $settings['sale_ready_days'] ?? self::DEFAULTS['sale_ready_days'] ),
			'tz'              => Clean_Room::timezone(),
		);
	}

	/**
	 * AJAX handler — save the settings.
	 */
	public function save_settings() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$dropped  = array();
		$settings = self::sanitize( wp_unslash( $_POST ), $dropped ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		update_option( Clean_Room::OPTION_KEY, $settings );

		$message = __( 'Settings saved.', 'fishotel-misc-plugin' );
		if ( $dropped ) {
			/* translators: %s: semicolon-separated list of what was not saved */
			$message .= ' ' . sprintf( __( 'Not saved: %s.', 'fishotel-misc-plugin' ), implode( '; ', $dropped ) );
		}

		wp_send_json_success(
			array(
				'message'  => $message,
				'settings' => $settings,
				'dropped'  => $dropped,
			)
		);
	}

	/**
	 * Sanitise a raw (unslashed) POST payload into a settings array.
	 * Checkboxes are absent when unticked, so every boolean comes from the
	 * payload, never from the stored value.
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with reasons for anything that fell back.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$dropped = array();

		$days = isset( $raw['sale_ready_days'] ) && is_scalar( $raw['sale_ready_days'] ) ? (int) $raw['sale_ready_days'] : self::DEFAULTS['sale_ready_days'];
		if ( $days < self::SALE_READY_MIN || $days > self::SALE_READY_MAX ) {
			$dropped[] = __( 'sale-ready days after check-in must be 1–120 (default 28 used)', 'fishotel-misc-plugin' );
			$days      = self::DEFAULTS['sale_ready_days'];
		}

		$floor = isset( $raw['copper_floor'] ) && is_scalar( $raw['copper_floor'] ) && is_numeric( $raw['copper_floor'] ) ? (float) $raw['copper_floor'] : self::DEFAULTS['copper_floor'];
		if ( $floor < self::COPPER_MIN || $floor > self::COPPER_MAX ) {
			$dropped[] = __( 'copper floor must be 0–9.99 (default 2.25 used)', 'fishotel-misc-plugin' );
			$floor     = self::DEFAULTS['copper_floor'];
		}

		$target = isset( $raw['copper_target'] ) && is_scalar( $raw['copper_target'] ) && is_numeric( $raw['copper_target'] ) ? (float) $raw['copper_target'] : self::DEFAULTS['copper_target'];
		if ( $target < self::COPPER_MIN || $target > self::COPPER_MAX ) {
			$dropped[] = __( 'copper target must be 0–9.99 (default 2.5 used)', 'fishotel-misc-plugin' );
			$target    = self::DEFAULTS['copper_target'];
		}
		if ( $target < $floor ) {
			$dropped[] = __( 'copper target was below the floor (raised to the floor)', 'fishotel-misc-plugin' );
		}

		$confirm = isset( $raw['auto_confirm_seconds'] ) && is_scalar( $raw['auto_confirm_seconds'] ) ? (int) $raw['auto_confirm_seconds'] : self::DEFAULTS['auto_confirm_seconds'];
		if ( $confirm < self::AUTO_CONFIRM_MIN || $confirm > self::AUTO_CONFIRM_MAX ) {
			$dropped[] = __( 'auto-confirm must be 0–15 seconds (default 4 used)', 'fishotel-misc-plugin' );
			$confirm   = self::DEFAULTS['auto_confirm_seconds'];
		}

		return self::normalize(
			array(
				'sale_ready_days'      => $days,
				'copper_floor'         => $floor,
				'copper_target'        => $target,
				'auto_confirm_seconds' => $confirm,
				'voice_enabled'        => ! empty( $raw['voice_enabled'] ),
			)
		);
	}

	/**
	 * An int within [min, max], else the default.
	 *
	 * @param mixed $value   Raw.
	 * @param int   $min     Min.
	 * @param int   $max     Max.
	 * @param int   $default Default.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return (int) $default;
		}

		$value = (int) $value;

		return ( $value < $min || $value > $max ) ? (int) $default : $value;
	}

	/**
	 * A float within [min, max] (rounded to 2 places), else the default.
	 *
	 * @param mixed $value   Raw.
	 * @param float $min     Min.
	 * @param float $max     Max.
	 * @param float $default Default.
	 * @return float
	 */
	public static function clamp_float( $value, $min, $max, $default ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return (float) $default;
		}

		$value = round( (float) $value, 2 );

		return ( $value < $min || $value > $max ) ? (float) $default : $value;
	}

	/* ============================================================== */
	/*  Protocol template                                              */
	/* ============================================================== */

	/**
	 * The treatment template: the stored steps, or the seed when nothing
	 * was ever saved.
	 *
	 * @return array[]
	 */
	public static function protocol() {
		$stored = get_option( Protocol::OPTION_KEY, null );

		if ( ! is_array( $stored ) ) {
			return Protocol::seed();
		}

		return Protocol::normalize( $stored );
	}

	/**
	 * AJAX handler — save the protocol template. The form posts
	 * steps[n][label|kind|day|every|until|where|note]; an empty list is
	 * allowed (no to-do at all) and "reset" restores the seed.
	 */
	public function save_protocol() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		if ( ! empty( $raw['reset'] ) ) {
			delete_option( Protocol::OPTION_KEY );
			wp_send_json_success(
				array(
					'message' => __( 'Protocol reset to the rough seed.', 'fishotel-misc-plugin' ),
					'steps'   => Protocol::seed(),
				)
			);
		}

		$steps = Protocol::normalize( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array() );

		update_option( Protocol::OPTION_KEY, $steps, false );

		wp_send_json_success(
			array(
				/* translators: %d: number of steps */
				'message' => sprintf( __( 'Protocol saved: %d steps.', 'fishotel-misc-plugin' ), count( $steps ) ),
				'steps'   => $steps,
			)
		);
	}

	/* ============================================================== */
	/*  Layout editor                                                  */
	/* ============================================================== */

	/**
	 * AJAX: add / save a building or a tank. `op` is one of
	 * building_add, building_save, tank_add, tank_save. A tank's number is
	 * derived from building / level / position — never typed.
	 */
	public function ajax_layout() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$op  = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : '';

		$store = new Store();
		if ( ! Store::tables_exist() ) {
			wp_send_json_error( array( 'message' => __( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ) ), 500 );
		}

		try {
			switch ( $op ) {
				case 'building_add':
				case 'building_save':
					$result = self::save_building( $store, $raw, 'building_save' === $op );
					break;
				case 'tank_add':
				case 'tank_save':
					$result = self::save_tank( $store, $raw, 'tank_save' === $op );
					break;
				default:
					$result = array( 'errors' => array( __( 'Unknown layout action.', 'fishotel-misc-plugin' ) ) );
			}
		} catch ( \Throwable $e ) {
			$result = array( 'errors' => array( $e->getMessage() ) );
		}

		if ( ! empty( $result['errors'] ) ) {
			wp_send_json_error( array( 'message' => implode( '; ', array_map( 'strval', (array) $result['errors'] ) ) ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Validate and store a building from a raw payload.
	 *
	 * @param Store $store    Store.
	 * @param array $raw      Unslashed payload.
	 * @param bool  $existing Whether `id` names an existing row.
	 * @return array{message?: string, id?: int, errors?: string[]}
	 */
	public static function save_building( Store $store, array $raw, $existing ) {
		$id      = $existing && isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
		$current = $id ? $store->building( $id ) : null;
		if ( $existing && ! $current ) {
			return array( 'errors' => array( __( 'That building no longer exists.', 'fishotel-misc-plugin' ) ) );
		}

		$building = array(
			'id'     => $id,
			'area'   => isset( $raw['area'] ) ? sanitize_key( $raw['area'] ) : ( $current ? $current['area'] : 'clean' ),
			'number' => isset( $raw['number'] ) ? absint( $raw['number'] ) : ( $current ? (int) $current['number'] : 0 ),
			'name'   => isset( $raw['name'] ) ? substr( sanitize_text_field( $raw['name'] ), 0, 60 ) : ( $current ? $current['name'] : '' ),
			'status' => isset( $raw['status'] ) ? sanitize_key( $raw['status'] ) : ( $current ? $current['status'] : 'active' ),
			'sort'   => isset( $raw['sort'] ) ? (int) $raw['sort'] : ( $current ? (int) $current['sort'] : 0 ),
			'notes'  => isset( $raw['notes'] ) ? sanitize_textarea_field( $raw['notes'] ) : ( $current ? (string) $current['notes'] : '' ),
		);

		$taken = array();
		foreach ( $store->buildings() as $row ) {
			if ( (int) $row['id'] !== $id ) {
				$taken[] = (int) $row['number'];
			}
		}

		$errors = Layout::validate_building( $building, $taken );
		if ( $errors ) {
			return array( 'errors' => $errors );
		}

		// Renumbering a building with tanks would break every tank number.
		if ( $current && (int) $current['number'] !== $building['number'] && $store->tanks_in_building( $id ) ) {
			return array( 'errors' => array( __( 'A building with tanks keeps its number (the tank numbers depend on it). Retire it and add a new one instead.', 'fishotel-misc-plugin' ) ) );
		}

		$saved = $store->save_building( $building );
		if ( ! $saved ) {
			return array( 'errors' => array( __( 'The building could not be saved.', 'fishotel-misc-plugin' ) ) );
		}

		return array(
			'message' => $existing ? __( 'Building saved.', 'fishotel-misc-plugin' ) : __( 'Building added.', 'fishotel-misc-plugin' ),
			'id'      => (int) $saved,
		);
	}

	/**
	 * Validate and store a tank from a raw payload.
	 *
	 * @param Store $store    Store.
	 * @param array $raw      Unslashed payload.
	 * @param bool  $existing Whether `id` names an existing row.
	 * @return array{message?: string, id?: int, number?: int, errors?: string[]}
	 */
	public static function save_tank( Store $store, array $raw, $existing ) {
		$id      = $existing && isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
		$current = $id ? $store->tank( $id ) : null;
		if ( $existing && ! $current ) {
			return array( 'errors' => array( __( 'That tank no longer exists.', 'fishotel-misc-plugin' ) ) );
		}

		$building_id = $current ? (int) $current['building_id'] : ( isset( $raw['building_id'] ) ? absint( $raw['building_id'] ) : 0 );
		$building    = $building_id ? $store->building( $building_id ) : null;
		if ( ! $building ) {
			return array( 'errors' => array( __( 'Pick a building first.', 'fishotel-misc-plugin' ) ) );
		}

		$tank = array(
			'id'              => $id,
			'building_id'     => $building_id,
			'building_number' => (int) $building['number'],
			'level'           => $current ? (int) $current['level'] : ( isset( $raw['level'] ) ? (int) $raw['level'] : 0 ),
			'position'        => $current ? (int) $current['position'] : ( isset( $raw['position'] ) ? (int) $raw['position'] : 0 ),
			'size_label'      => isset( $raw['size_label'] ) ? substr( sanitize_text_field( $raw['size_label'] ), 0, 40 ) : ( $current ? $current['size_label'] : '' ),
			'gallons'         => isset( $raw['gallons'] ) ? absint( $raw['gallons'] ) : ( $current ? (int) $current['gallons'] : 0 ),
			'status'          => isset( $raw['status'] ) ? sanitize_key( $raw['status'] ) : ( $current ? $current['status'] : 'active' ),
			'sort'            => isset( $raw['sort'] ) ? (int) $raw['sort'] : ( $current ? (int) $current['sort'] : 0 ),
		);

		$taken = array();
		foreach ( $store->tanks() as $row ) {
			if ( (int) $row['id'] !== $id ) {
				$taken[] = (int) $row['number'];
			}
		}

		$errors = Layout::validate_tank( $tank, $taken );
		if ( $errors ) {
			return array( 'errors' => $errors );
		}

		$tank['number'] = Layout::tank_number( $tank['building_number'], $tank['level'], $tank['position'] );

		$saved = $store->save_tank( $tank );
		if ( ! $saved ) {
			return array( 'errors' => array( __( 'The tank could not be saved.', 'fishotel-misc-plugin' ) ) );
		}

		return array(
			/* translators: %d: tank number */
			'message' => sprintf( $existing ? __( 'Tank %d saved.', 'fishotel-misc-plugin' ) : __( 'Tank %d added.', 'fishotel-misc-plugin' ), $tank['number'] ),
			'id'      => (int) $saved,
			'number'  => (int) $tank['number'],
		);
	}

	/* ============================================================== */
	/*  Aliases                                                        */
	/* ============================================================== */

	/**
	 * AJAX: forget a learned alias.
	 */
	public function ajax_alias_delete() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$store = new Store();

		if ( ! $id || ! $store->delete_alias( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'The alias could not be removed.', 'fishotel-misc-plugin' ) ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Alias removed.', 'fishotel-misc-plugin' ) ) );
	}
}
