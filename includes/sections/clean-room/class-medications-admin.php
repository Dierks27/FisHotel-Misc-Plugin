<?php
/**
 * Clean Room medications — the admin side: Settings → Medications (the
 * list editor: add, edit, retire, bring back) and the Board's Calculator.
 *
 * The editor saves through ONE AJAX action,
 * fishotel_cleanroom_medications (op add | save | retire | restore); its
 * testable half, request(), checks the Settings capability
 * (manage_options — like every other save of the Settings tab) and the
 * settings nonce itself, runs the operation on the list
 * (Medications::apply_op()) and stores it in the section's settings
 * option (Settings::save_medications()). Nothing else is written.
 *
 * Beside the rate of a medication dosed to a level the panel shows the
 * figure the store's public Medication Calculator uses, when the theme's
 * data is there (Medications::theme_figure()), so the two cannot drift
 * apart unseen — and says plainly when it is not there.
 *
 * The Calculator on the Board is the phone's: the same sum
 * (js/dose-math.js) for any number of gallons, nothing saved. Its script
 * (js/medications-admin.js) puts the button into the Board's toolbar
 * itself, so the Board's own view is not touched.
 *
 * Hooked from Settings::init() (admin requests only).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Medications_Admin
 */
class Medications_Admin {

	/**
	 * The AJAX action of the editor.
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_cleanroom_medications';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 21 );
	}

	/* ============================================================== */
	/*  Settings → Medications                                         */
	/* ============================================================== */

	/**
	 * One editor operation — the testable half of ajax(). The capability
	 * (manage_options) and the nonce first; then op add | save | retire |
	 * restore on the list in use; a refusal stores nothing.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, op, key and the form's fields (Medications::apply_op()).
	 * @return array{status: int, ok: bool, message: string, key?: string}
	 */
	public static function request( array $raw ) {
		$out = array(
			'status'  => 403,
			'ok'      => false,
			'message' => '',
		);
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			$out['message'] = __( 'Unauthorized.', 'fishotel-misc-plugin' );
			return $out;
		}
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, Settings::NONCE_ACTION ) ) {
			$out['message'] = __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$op     = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
		$result = Medications::apply_op( Settings::medications(), $op, $raw );
		if ( ! empty( $result['error'] ) ) {
			$out['status']  = 400;
			$out['message'] = (string) $result['error'] . ' ' . __( 'Nothing was changed.', 'fishotel-misc-plugin' );
			return $out;
		}

		Settings::save_medications( $result['list'] );

		$out['status']  = 200;
		$out['ok']      = true;
		$out['message'] = (string) $result['message'];
		$out['key']     = (string) $result['key'];
		return $out;
	}

	/**
	 * AJAX: one editor operation (request()).
	 */
	public function ajax() {
		$outcome = self::request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in request().
		if ( ! $outcome['ok'] ) {
			wp_send_json_error( array( 'message' => $outcome['message'] ), $outcome['status'] );
		}
		wp_send_json_success( array( 'message' => $outcome['message'] ) );
	}

	/**
	 * What Settings → Medications shows: every medication (retired ones
	 * too) with how it is dosed in words and, for one dosed to a level,
	 * the store's public calculator's figure beside it.
	 *
	 * @param array|null $list  key => medication (null = the list in use).
	 * @param mixed      $theme Medications::theme_data(), null when the theme's data is not there; false (the default) = read it.
	 * @return array{rows: array[], theme_available: bool, edited: bool}
	 */
	public static function panel( $list = null, $theme = false ) {
		$list  = is_array( $list ) ? $list : Settings::medications();
		$theme = false === $theme ? Medications::theme_data() : $theme;
		$rows  = array();
		foreach ( $list as $medication ) {
			$rows[] = array(
				'medication' => $medication,
				'how'        => self::how_text( $medication ),
				'doses'      => self::doses_text( $medication ),
				'measure'    => self::measure_text( $medication ),
				'copper'     => Medications::COPPER_KEY === (string) $medication['key'],
				'theme'      => self::theme_note( $medication, $theme ),
			);
		}
		return array(
			'rows'            => $rows,
			'theme_available' => is_array( $theme ),
			'edited'          => $list !== Medications::seed(),
		);
	}

	/**
	 * How a medication is dosed, in words: "25 mg per gallon", "0.692 ml
	 * per gallon raises the level by 1.0 ppm", "typed — no sum".
	 *
	 * @param array $medication Medication.
	 * @return string
	 */
	public static function how_text( array $medication ) {
		$mode = (string) $medication['mode'];
		if ( 'per_gallon' === $mode ) {
			return Dose::rate_text( $medication['rate'], $medication['unit'], $medication['per'] );
		}
		if ( 'to_level' === $mode ) {
			/* translators: 1: rate ("0.692 ml per gallon"), 2: the level's unit */
			return sprintf( __( '%1$s raises the level by 1.0 %2$s', 'fishotel-misc-plugin' ), self::plain_rate( $medication ), (string) $medication['level_unit'] );
		}
		return __( 'typed — no sum', 'fishotel-misc-plugin' );
	}

	/**
	 * A rate as it was typed — "0.692 ml per gallon", "2 ml per 5 gal" —
	 * without moving a weight into another unit.
	 *
	 * @param array $medication Medication.
	 * @return string
	 */
	private static function plain_rate( array $medication ) {
		$s      = Dose::strings();
		$amount = 'each' === (string) $medication['unit'] ? Dose::num( $medication['rate'], 4 ) : Dose::num( $medication['rate'], 4 ) . ' ' . (string) $medication['unit'];
		if ( abs( (float) $medication['per'] - 1 ) < 1e-9 ) {
			return sprintf( $s['perGallon'], $amount );
		}
		return sprintf( $s['perGallons'], $amount, Dose::num( $medication['per'], 2 ) );
	}

	/**
	 * The named doses in words: "Loading 40 mg per gallon · Top-up 12 mg
	 * per gallon" ('' without any).
	 *
	 * @param array $medication Medication.
	 * @return string
	 */
	public static function doses_text( array $medication ) {
		$parts = array();
		foreach ( (array) $medication['doses'] as $dose ) {
			$parts[] = (string) $dose['label'] . ' ' . Dose::rate_text( $dose['rate'], $medication['unit'], $medication['per'] );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * The measure in words: "one scoop = 0.5 g" ('' without one).
	 *
	 * @param array $medication Medication.
	 * @return string
	 */
	public static function measure_text( array $medication ) {
		$measure = isset( $medication['measure'] ) && is_array( $medication['measure'] ) ? $medication['measure'] : null;
		if ( ! $measure ) {
			return '';
		}
		/* translators: 1: measure name, 2: its amount */
		return sprintf( __( 'one %1$s = %2$s', 'fishotel-misc-plugin' ), (string) $measure['label'], Dose::amount_text( (float) $measure['amount'], (string) $measure['unit'] ) );
	}

	/**
	 * What to say beside a medication's rate about the store's public
	 * Medication Calculator (pure):
	 *   same     it uses the same figure;
	 *   differs  it uses another one — the two have drifted apart;
	 *   shown    it has a figure that cannot be compared (another unit);
	 *   absent   the copper product only: the theme's data is not there,
	 *            or has no entry of that name — said plainly;
	 *   ''       nothing to say (not dosed to a level; no entry for a
	 *            medication that is not the copper product).
	 *
	 * @param array      $medication Medication.
	 * @param array|null $theme      Medications::theme_data().
	 * @return array{state: string, text: string}
	 */
	public static function theme_note( array $medication, $theme ) {
		$none = array(
			'state' => '',
			'text'  => '',
		);
		if ( 'to_level' !== (string) $medication['mode'] ) {
			return $none;
		}
		$figure = Medications::theme_figure( $medication, $theme );
		if ( ! $figure ) {
			if ( Medications::COPPER_KEY !== (string) $medication['key'] ) {
				return $none;
			}
			return array(
				'state' => 'absent',
				'text'  => is_array( $theme )
					/* translators: %s: medication name */
					? sprintf( __( 'The store\'s public Medication Calculator has no figure for "%s", so the two cannot be compared here.', 'fishotel-misc-plugin' ), (string) $medication['label'] )
					: __( 'The store\'s public Medication Calculator data is not available on this site (the FisHotel theme is not active, or it has none), so its copper figure cannot be shown beside this one.', 'fishotel-misc-plugin' ),
			);
		}

		$theirs = Dose::num( $figure['rate'], 4 );
		if ( true === $figure['same'] ) {
			return array(
				'state' => 'same',
				/* translators: 1: the figure, 2: the theme's name for the product */
				'text'  => sprintf( __( 'The store\'s public Medication Calculator uses %1$s ml per gallon per 1.0 ppm for %2$s — the same figure.', 'fishotel-misc-plugin' ), $theirs, $figure['name'] ),
			);
		}
		if ( false === $figure['same'] ) {
			return array(
				'state' => 'differs',
				/* translators: 1: the theme's figure, 2: the theme's name for the product, 3: this list's rate */
				'text'  => sprintf( __( 'The store\'s public Medication Calculator uses %1$s ml per gallon per 1.0 ppm for %2$s — this list says %3$s. One of the two is out of date.', 'fishotel-misc-plugin' ), $theirs, $figure['name'], self::plain_rate( $medication ) ),
			);
		}
		return array(
			'state' => 'shown',
			/* translators: 1: the figure, 2: the theme's name for the product */
			'text'  => sprintf( __( 'The store\'s public Medication Calculator uses %1$s ml per gallon per 1.0 ppm for %2$s (this one is in another unit, so the two are not compared).', 'fishotel-misc-plugin' ), $theirs, $figure['name'] ),
		);
	}

	/* ============================================================== */
	/*  The Board's Calculator                                         */
	/* ============================================================== */

	/**
	 * What the Calculator on the Board is handed: the medications offered,
	 * each building's water as a quick pick, the copper target, the words.
	 *
	 * @param object $store    Store (or the tests' stand-in): buildings(), tanks().
	 * @param array  $settings Settings.
	 * @return array{medications: array[], waters: array[], copperTarget: float, strings: array, i18n: array}
	 */
	public static function calculator_config( $store, array $settings ) {
		$waters = array();
		try {
			$tanks = (array) $store->tanks( false );
			foreach ( (array) $store->buildings() as $building ) {
				$gallons = 'offline' === (string) ( $building['status'] ?? '' ) ? 0 : Layout::system_gallons( $building, $tanks );
				if ( $gallons > 0 ) {
					$waters[] = array(
						'label'   => Layout::building_label( $building ),
						'gallons' => $gallons,
					);
				}
			}
		} catch ( \Throwable $e ) {
			$waters = array();
		}

		return array(
			'medications'  => Front_Desk::medications( Medications::from_settings( $settings ) ),
			'waters'       => $waters,
			'copperTarget' => (float) ( $settings['copper_target'] ?? Settings::DEFAULTS['copper_target'] ),
			'strings'      => Dose::strings(),
			'i18n'         => array(
				'calculator'  => __( 'Calculator', 'fishotel-misc-plugin' ),
				'title'       => __( 'Dose calculator', 'fishotel-misc-plugin' ),
				'hint'        => __( 'Any water — a bucket, a tub, a tank that is not set up. Nothing is saved; a dose is logged on the tank\'s Front Desk screen.', 'fishotel-misc-plugin' ),
				'medication'  => __( 'Medication', 'fishotel-misc-plugin' ),
				'pick'        => __( 'Pick a medication', 'fishotel-misc-plugin' ),
				'gallons'     => __( 'Gallons', 'fishotel-misc-plugin' ),
				'gallonsAsk'  => __( 'Type the gallons.', 'fishotel-misc-plugin' ),
				'rate'        => __( 'How much', 'fishotel-misc-plugin' ),
				'now'         => __( 'Now', 'fishotel-misc-plugin' ),
				'target'      => __( 'Bring it to', 'fishotel-misc-plugin' ),
				'close'       => __( 'Close', 'fishotel-misc-plugin' ),
				'noMeds'      => __( 'No medication is offered — add or bring one back in Settings → Medications.', 'fishotel-misc-plugin' ),
				/* translators: 1: building, 2: gallons */
				'water'       => __( '%1$s · %2$s gal', 'fishotel-misc-plugin' ),
				'saved'       => __( 'Saved. Reloading…', 'fishotel-misc-plugin' ),
				'error'       => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
				'confirmRetire' => __( 'Retire this medication? It is no longer offered; the logs that name it still read, and it can be brought back.', 'fishotel-misc-plugin' ),
				/* translators: %s: an amount with its unit */
				'on100'       => __( '100 gal take %s', 'fishotel-misc-plugin' ),
				/* translators: %s: an amount with its unit */
				'on100Level'  => __( '100 gal take %s for each 1.0', 'fishotel-misc-plugin' ),
			),
		);
	}

	/**
	 * Enqueue the editor / calculator assets on the section's page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Clean_Room::MENU_SLUG ) || ! current_user_can( Clean_Room::CAPABILITY ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'board'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only tab switch.
		if ( ! in_array( $tab, array( 'board', 'settings' ), true ) ) {
			return; // Only the Board (the Calculator) and Settings (the editor) use them.
		}

		wp_enqueue_style( 'fishotel-cleanroom-medications', Clean_Room::url() . 'css/medications-admin.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-cleanroom-dose-math', Clean_Room::url() . 'js/dose-math.js', array(), FISHOTEL_MISC_VERSION, true );
		wp_enqueue_script( 'fishotel-cleanroom-medications', Clean_Room::url() . 'js/medications-admin.js', array( 'jquery', 'fishotel-cleanroom-dose-math' ), FISHOTEL_MISC_VERSION, true );

		$config            = self::calculator_config( new Store(), Settings::get() );
		$config['tab']     = $tab;
		$config['ajaxUrl'] = admin_url( 'admin-ajax.php' );
		$config['action']  = self::ACTION;
		$config['nonce']   = current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ? wp_create_nonce( Settings::NONCE_ACTION ) : '';
		wp_localize_script( 'fishotel-cleanroom-medications', 'fhCleanRoomDose', $config );
	}
}
