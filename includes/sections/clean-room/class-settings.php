<?php
/**
 * Clean Room settings — option storage, defaults, the sanitised AJAX
 * saves (settings, the protocol template), and the layout-editor / alias
 * actions of the Settings tab. Everything here needs manage_options and a
 * nonce.
 *
 * Three options:
 *   fishotel_cleanroom_settings   sale_ready_days (the SUGGESTED sale date:
 *                                 that many days after check-in, default 28 —
 *                                 only the hint the screens show and what a
 *                                 "suggested" tap fills a date picker with;
 *                                 a sale date is always chosen, on the
 *                                 supplier order or per stay), the
 *                                 copper floor and target the
 *                                 tank screen warns against (2.25 / 2.5),
 *                                 the voice preview's auto-confirm seconds
 *                                 and whether the Mic button is offered; and
 *                                 the standard sizes' source — size_attribute
 *                                 (which product attribute is the size, when
 *                                 several match) and size_list (the plain
 *                                 list used while no such attribute exists),
 *                                 saved by Settings → Sizes on their own, so
 *                                 the Numbers form never touches them;
 *   fishotel_cleanroom_protocol   the treatment schedules (Protocol): the
 *                                 default template's name and the named
 *                                 templates, seeded with the workbook's five
 *                                 on first read; an older bare steps array
 *                                 is read as the "14 Day CM" template;
 *   fishotel_cleanroom_box_rates  the per-box shipping and re-ox fee by
 *                                 supplier and origin (the order editor's
 *                                 "suggest import fees" button reads it);
 *                                 empty until typed or imported — never
 *                                 seeded in code.
 *
 * The layout (buildings and tanks), the learned aliases and the
 * remembered box shares live in their tables, written through the Store
 * with the numbering rule enforced by Layout::validate_*(). The Temporary
 * building (each tank its own water) is laid out in slots: Settings →
 * Layout says how many there are and adds more (add_slot()); a slot can
 * be retired with its status while nothing is set up in it (save_slot()).
 *
 * A fourth option, fishotel_cleanroom_template_tags, holds what each
 * treatment template is "Used for" (Temp_Tanks) — saved with the
 * template's steps, renamed and deleted with the template.
 *
 * The two newest saves — sizes_request() and box_share_request() — check
 * the capability and the nonce themselves and answer with a status, so
 * they run headless; their AJAX handlers only send the answer.
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
		'size_attribute'       => '',
		'size_list'            => array(),
	);

	const SALE_READY_MIN   = 1;
	const SALE_READY_MAX   = 120;
	const COPPER_MIN       = 0.0;
	const COPPER_MAX       = 9.99;
	const AUTO_CONFIRM_MIN = 0;
	const AUTO_CONFIRM_MAX = 15;

	/**
	 * Option holding the box rates (supplier, origin, per box, re-ox).
	 *
	 * @var string
	 */
	const BOX_RATES_OPTION = 'fishotel_cleanroom_box_rates';

	const BOX_RATES_MAX = 200;
	const BOX_RATE_MAX  = 99999.99;

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
		add_action( 'wp_ajax_fishotel_cleanroom_box_rates', array( $this, 'save_box_rates' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_sizes', array( $this, 'ajax_sizes' ) );
		add_action( 'wp_ajax_fishotel_cleanroom_box_share', array( $this, 'ajax_box_share' ) );
	}

	/* ============================================================== */
	/*  Box rates                                                      */
	/* ============================================================== */

	/**
	 * The box rates: rows of supplier, origin, per_box, reox — empty until
	 * typed in Settings or filled by the importer.
	 *
	 * @return array[]
	 */
	public static function box_rates() {
		$stored = get_option( self::BOX_RATES_OPTION, array() );
		return self::sanitize_box_rates( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Store the box rates (sanitised first) — the editor's save and the
	 * workbook import write through here.
	 *
	 * @param array $rows Rows of supplier, origin, per_box, reox.
	 * @return array[] The stored rows.
	 */
	public static function store_box_rates( array $rows ) {
		$rates = self::sanitize_box_rates( $rows );
		update_option( self::BOX_RATES_OPTION, $rates, false );
		return $rates;
	}

	/**
	 * Clean a list of box-rate rows (pure): text trimmed, money ≥ 0, rows
	 * without a supplier and an origin dropped, duplicates (same supplier
	 * and origin) collapsed to the last, at most BOX_RATES_MAX, sorted.
	 *
	 * @param mixed $raw rows[n][supplier|origin|per_box|reox].
	 * @return array[]
	 */
	public static function sanitize_box_rates( $raw ) {
		$by_key = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$supplier = isset( $row['supplier'] ) && is_scalar( $row['supplier'] ) ? substr( trim( preg_replace( '/\s+/', ' ', (string) $row['supplier'] ) ), 0, 80 ) : '';
			$origin   = isset( $row['origin'] ) && is_scalar( $row['origin'] ) ? substr( trim( preg_replace( '/\s+/', ' ', (string) $row['origin'] ) ), 0, 80 ) : '';
			if ( '' === $supplier && '' === $origin ) {
				continue;
			}
			$per_box = max( 0.0, min( self::BOX_RATE_MAX, Costing::money_in( $row['per_box'] ?? 0 ) ) );
			$reox    = max( 0.0, min( self::BOX_RATE_MAX, Costing::money_in( $row['reox'] ?? 0 ) ) );

			// The same pair twice: the first spelling stays, the last numbers win.
			$key = strtolower( $supplier ) . '|' . strtolower( $origin );
			if ( isset( $by_key[ $key ] ) ) {
				$supplier = $by_key[ $key ]['supplier'];
				$origin   = $by_key[ $key ]['origin'];
			}
			$by_key[ $key ] = array(
				'supplier' => $supplier,
				'origin'   => $origin,
				'per_box'  => round( $per_box, 2 ),
				'reox'     => round( $reox, 2 ),
			);
		}

		$out = array_values( $by_key );
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['supplier'], $b['supplier'] ) ?: strcasecmp( $a['origin'], $b['origin'] );
			}
		);

		return array_slice( $out, 0, self::BOX_RATES_MAX );
	}

	/**
	 * The box rate for a supplier and origin (pure, case-insensitive): the
	 * exact pair, else the supplier's row with no origin, else null.
	 *
	 * @param array[] $rates    Box rates.
	 * @param string  $supplier Supplier.
	 * @param string  $origin   Origin.
	 * @return array|null
	 */
	public static function box_rate_for( array $rates, $supplier, $origin ) {
		$supplier = strtolower( trim( (string) $supplier ) );
		$origin   = strtolower( trim( (string) $origin ) );
		$fallback = null;
		foreach ( $rates as $rate ) {
			if ( strtolower( (string) ( $rate['supplier'] ?? '' ) ) !== $supplier ) {
				continue;
			}
			$rate_origin = strtolower( (string) ( $rate['origin'] ?? '' ) );
			if ( $rate_origin === $origin ) {
				return $rate;
			}
			if ( '' === $rate_origin && null === $fallback ) {
				$fallback = $rate;
			}
		}
		return $fallback;
	}

	/**
	 * The import & re-ox fees a box count suggests: boxes × (per box +
	 * re-ox) for the supplier / origin, or null when no rate is known
	 * (pure; the JS helper does the same on the order editor).
	 *
	 * @param array[]  $rates    Box rates.
	 * @param string   $supplier Supplier.
	 * @param string   $origin   Origin.
	 * @param int|null $boxes    Boxes.
	 * @return float|null
	 */
	public static function suggest_import_fees( array $rates, $supplier, $origin, $boxes ) {
		$rate = self::box_rate_for( $rates, $supplier, $origin );
		if ( ! $rate || null === $boxes || (int) $boxes <= 0 ) {
			return null;
		}
		return (int) $boxes * ( (float) $rate['per_box'] + (float) $rate['reox'] );
	}

	/**
	 * AJAX handler — save the box rates. The form posts
	 * rates[n][supplier|origin|per_box|reox]; an empty list is allowed.
	 */
	public function save_box_rates() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$raw   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$rates = self::store_box_rates( isset( $raw['rates'] ) && is_array( $raw['rates'] ) ? $raw['rates'] : array() );

		wp_send_json_success(
			array(
				/* translators: %d: number of rates */
				'message' => sprintf( __( 'Box rates saved: %d rows.', 'fishotel-misc-plugin' ), count( $rates ) ),
				'rates'   => $rates,
			)
		);
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
			'size_attribute'       => isset( $settings['size_attribute'] ) && is_scalar( $settings['size_attribute'] ) && ! is_bool( $settings['size_attribute'] ) ? substr( trim( (string) $settings['size_attribute'] ), 0, 60 ) : '',
			'size_list'            => Sizes::labels( isset( $settings['size_list'] ) && ( is_array( $settings['size_list'] ) || is_string( $settings['size_list'] ) ) ? $settings['size_list'] : array() ),
		);
	}

	/**
	 * Settings with the sizes' source taken from what is stored (pure):
	 * the Numbers form does not post size_attribute / size_list, so its
	 * save keeps them.
	 *
	 * @param array $settings The settings a form produced.
	 * @param array $stored   The stored settings.
	 * @return array
	 */
	public static function with_sizes( array $settings, array $stored ) {
		$stored = self::normalize( $stored );
		return array_merge(
			$settings,
			array(
				'size_attribute' => $stored['size_attribute'],
				'size_list'      => $stored['size_list'],
			)
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
		$settings = self::with_sizes( self::sanitize( wp_unslash( $_POST ), $dropped ), self::get() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

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
	 * payload, never from the stored value. The sizes' source is not part
	 * of this form: it comes back empty here and save_settings() puts the
	 * stored one back (with_sizes()).
	 *
	 * @param array    $raw     Raw request data.
	 * @param string[] $dropped Filled with reasons for anything that fell back.
	 * @return array
	 */
	public static function sanitize( array $raw, &$dropped = array() ) {
		$dropped = array();

		$days = isset( $raw['sale_ready_days'] ) && is_scalar( $raw['sale_ready_days'] ) ? (int) $raw['sale_ready_days'] : self::DEFAULTS['sale_ready_days'];
		if ( $days < self::SALE_READY_MIN || $days > self::SALE_READY_MAX ) {
			$dropped[] = __( 'the suggested sale date (days after check-in) must be 1–120 (default 28 used)', 'fishotel-misc-plugin' );
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
	 * The treatment schedules: { default, templates } — the stored option
	 * in its current shape (an old bare steps array becomes the "14 Day CM"
	 * template), or the five seeds when nothing was ever saved.
	 *
	 * @return array{default: string, templates: array<string, array[]>}
	 */
	public static function protocols() {
		return Protocol::normalize_option( get_option( Protocol::OPTION_KEY, null ) );
	}

	/**
	 * The default template's steps (what a building without a schedule of
	 * its own runs).
	 *
	 * @return array[]
	 */
	public static function protocol() {
		$protocols = self::protocols();
		return isset( $protocols['templates'][ $protocols['default'] ] ) ? $protocols['templates'][ $protocols['default'] ] : array();
	}

	/**
	 * The steps a building runs: its schedule template when set and
	 * existing, else the default.
	 *
	 * @param array $building Building row.
	 * @return array[]
	 */
	public static function steps_for( array $building ) {
		return Protocol::steps_for( $building, self::protocols() );
	}

	/**
	 * Store the schedules (normalised first).
	 *
	 * @param array $protocols { default, templates }.
	 * @return array The stored shape.
	 */
	public static function save_protocols( array $protocols ) {
		$protocols = Protocol::normalize_option( $protocols );
		update_option( Protocol::OPTION_KEY, $protocols, false );
		return $protocols;
	}

	/**
	 * One Settings → Protocol operation on the schedules (pure): save the
	 * steps of a template, add one (a seeded name gets its seed back, any
	 * other the daily feeding alone), rename, delete (never the last one;
	 * the default moves to the first remaining), make default, or reset a
	 * template to its seed.
	 *
	 * @param array  $protocols { default, templates }.
	 * @param string $op        save|add|rename|delete|make_default|reset.
	 * @param array  $raw       template (name), name (add / rename), steps[] (save).
	 * @return array{protocols: array, template: string, message: string, renamed?: array{0: string, 1: string}}|array{error: string}
	 */
	public static function apply_protocol_op( array $protocols, $op, array $raw ) {
		$protocols = Protocol::normalize_option( $protocols );
		$templates = $protocols['templates'];
		$template  = Protocol::template_name( isset( $raw['template'] ) ? $raw['template'] : '' );
		if ( '' === $template ) {
			$template = $protocols['default'];
		}
		$known = isset( $templates[ $template ] );
		$name  = Protocol::template_name( isset( $raw['name'] ) ? $raw['name'] : '' );

		switch ( (string) $op ) {
			case 'save':
				if ( ! $known ) {
					return array( 'error' => __( 'That template no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				$templates[ $template ] = Protocol::normalize( isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array() );
				/* translators: 1: template name, 2: number of steps */
				$message = sprintf( __( '"%1$s" saved: %2$d steps.', 'fishotel-misc-plugin' ), $template, count( $templates[ $template ] ) );
				break;

			case 'add':
				if ( '' === $name ) {
					return array( 'error' => __( 'Name the new template.', 'fishotel-misc-plugin' ) );
				}
				if ( isset( $templates[ $name ] ) ) {
					/* translators: %s: template name */
					return array( 'error' => sprintf( __( 'A template named "%s" already exists.', 'fishotel-misc-plugin' ), $name ) );
				}
				if ( count( $templates ) >= Protocol::MAX_TEMPLATES ) {
					/* translators: %d: the cap */
					return array( 'error' => sprintf( __( 'At most %d templates.', 'fishotel-misc-plugin' ), Protocol::MAX_TEMPLATES ) );
				}
				$seed               = Protocol::seed_template( $name );
				$templates[ $name ] = null !== $seed ? $seed : array( Protocol::feed_step() );
				$template           = $name;
				/* translators: %s: template name */
				$message = sprintf( __( '"%s" added.', 'fishotel-misc-plugin' ), $name );
				break;

			case 'rename':
				if ( ! $known ) {
					return array( 'error' => __( 'That template no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				if ( '' === $name ) {
					return array( 'error' => __( 'Name the template.', 'fishotel-misc-plugin' ) );
				}
				if ( $name !== $template && isset( $templates[ $name ] ) ) {
					/* translators: %s: template name */
					return array( 'error' => sprintf( __( 'A template named "%s" already exists.', 'fishotel-misc-plugin' ), $name ) );
				}
				$renamed = array();
				foreach ( $templates as $key => $steps ) {
					// A digits-only name ("21") is an int key in PHP: compare as text.
					$renamed[ (string) $key === $template ? $name : $key ] = $steps;
				}
				$templates = $renamed;
				if ( $protocols['default'] === $template ) {
					$protocols['default'] = $name;
				}
				$old      = $template;
				$template = $name;
				/* translators: 1: old name, 2: new name */
				$message = sprintf( __( '"%1$s" renamed to "%2$s".', 'fishotel-misc-plugin' ), $old, $name );
				return array(
					'protocols' => Protocol::normalize_option( array_merge( $protocols, array( 'templates' => $templates ) ) ),
					'template'  => $template,
					'message'   => $message,
					'renamed'   => array( $old, $name ),
				);

			case 'delete':
				if ( ! $known ) {
					return array( 'error' => __( 'That template no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				if ( count( $templates ) <= 1 ) {
					return array( 'error' => __( 'The last template stays — add another one first.', 'fishotel-misc-plugin' ) );
				}
				unset( $templates[ $template ] );
				if ( $protocols['default'] === $template ) {
					$protocols['default'] = (string) array_key_first( $templates );
				}
				$old      = $template;
				$template = $protocols['default'];
				/* translators: 1: deleted name, 2: the default now */
				$message = sprintf( __( '"%1$s" deleted; buildings that ran it now run the default ("%2$s").', 'fishotel-misc-plugin' ), $old, $protocols['default'] );
				return array(
					'protocols' => Protocol::normalize_option( array_merge( $protocols, array( 'templates' => $templates ) ) ),
					'template'  => $template,
					'message'   => $message,
					'renamed'   => array( $old, '' ),
				);

			case 'make_default':
				if ( ! $known ) {
					return array( 'error' => __( 'That template no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				$protocols['default'] = $template;
				/* translators: %s: template name */
				$message = sprintf( __( '"%s" is the default now.', 'fishotel-misc-plugin' ), $template );
				break;

			case 'reset':
				if ( ! $known ) {
					return array( 'error' => __( 'That template no longer exists — reload the page.', 'fishotel-misc-plugin' ) );
				}
				$seed                   = Protocol::seed_template( $template );
				$templates[ $template ] = null !== $seed ? $seed : array( Protocol::feed_step() );
				/* translators: %s: template name */
				$message = null !== $seed ? sprintf( __( '"%s" reset to the workbook\'s schedule.', 'fishotel-misc-plugin' ), $template ) : sprintf( __( '"%s" reset to the daily feeding.', 'fishotel-misc-plugin' ), $template );
				break;

			default:
				return array( 'error' => __( 'Unknown protocol action.', 'fishotel-misc-plugin' ) );
		}

		return array(
			'protocols' => Protocol::normalize_option( array_merge( $protocols, array( 'templates' => $templates ) ) ),
			'template'  => $template,
			'message'   => $message,
		);
	}

	/**
	 * AJAX handler — one operation on the schedules (apply_protocol_op()):
	 * `op` save (steps[n][label|kind|day|every|until|where|note] for
	 * `template`; an empty list is allowed), add / rename (`name`), delete,
	 * make_default, reset. A rename or delete follows through to the
	 * buildings that named the template.
	 */
	public function save_protocol() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$op  = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : ( ! empty( $raw['reset'] ) ? 'reset' : 'save' );

		$result = self::apply_protocol_op( self::protocols(), $op, $raw );
		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['error'] ), 400 );
		}

		$protocols = self::save_protocols( $result['protocols'] );
		if ( ! empty( $result['renamed'] ) ) {
			$store = new Store();
			$store->rename_schedule( (string) $result['renamed'][0], (string) $result['renamed'][1] );
		}
		self::follow_template_tags( $op, $result, $raw, $protocols );

		wp_send_json_success(
			array(
				'message'   => (string) $result['message'],
				'template'  => (string) $result['template'],
				'default'   => (string) $protocols['default'],
				'templates' => array_keys( $protocols['templates'] ),
				'steps'     => isset( $protocols['templates'][ $result['template'] ] ) ? $protocols['templates'][ $result['template'] ] : array(),
			)
		);
	}

	/**
	 * What a template is "Used for" follows the template (the testable
	 * half of save_protocol()'s tags): a save with a `used_for` field
	 * stores that template's tags; a rename carries them to the new name;
	 * a delete drops them.
	 *
	 * @param string $op        The protocol operation.
	 * @param array  $result    apply_protocol_op().
	 * @param array  $raw       The unslashed payload (used_for).
	 * @param array  $protocols The schedules as stored after the operation.
	 */
	public static function follow_template_tags( $op, array $result, array $raw, array $protocols ) {
		if ( ! empty( $result['renamed'] ) ) {
			Temp_Tanks::rename_tags( (string) $result['renamed'][0], (string) $result['renamed'][1] );
		}
		if ( 'save' === (string) $op && array_key_exists( 'used_for', $raw ) && ( is_string( $raw['used_for'] ) || is_array( $raw['used_for'] ) ) ) {
			Temp_Tanks::save_tags( (string) $result['template'], $raw['used_for'], $protocols );
		}
	}

	/* ============================================================== */
	/*  Layout editor                                                  */
	/* ============================================================== */

	/**
	 * AJAX: add / save a building or a tank. `op` is one of
	 * building_add, building_save, tank_add, tank_save, slot_add (one more
	 * slot for the temporary tanks). A tank's number is
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
				case 'slot_add':
					$result = self::add_slot( $store );
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
	 * The layout form's system gallons (pure): blank = null (the sum of
	 * the tanks), a number as a whole number — a negative one kept so
	 * Layout::validate_building() refuses it instead of absint() quietly
	 * turning "-205" into 205 —, anything else as typed (refused there too,
	 * never read as 0 = blank).
	 *
	 * @param mixed $value Raw field.
	 * @return int|string|null
	 */
	public static function gallons_input( $value ) {
		$text = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $text ) {
			return null;
		}
		return is_numeric( $text ) ? (int) round( (float) $text ) : $text;
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

		$gallons = $current ? $current['system_gallons'] : null;
		if ( array_key_exists( 'system_gallons', $raw ) && is_scalar( $raw['system_gallons'] ) ) {
			$gallons = self::gallons_input( $raw['system_gallons'] );
		}

		$building = array(
			'id'             => $id,
			'area'           => isset( $raw['area'] ) ? sanitize_key( $raw['area'] ) : ( $current ? $current['area'] : 'clean' ),
			'number'         => isset( $raw['number'] ) ? absint( $raw['number'] ) : ( $current ? (int) $current['number'] : 0 ),
			'name'           => isset( $raw['name'] ) ? substr( sanitize_text_field( $raw['name'] ), 0, 60 ) : ( $current ? $current['name'] : '' ),
			'status'         => isset( $raw['status'] ) ? sanitize_key( $raw['status'] ) : ( $current ? $current['status'] : 'active' ),
			'sort'           => isset( $raw['sort'] ) ? (int) $raw['sort'] : ( $current ? (int) $current['sort'] : 0 ),
			'notes'          => isset( $raw['notes'] ) ? sanitize_textarea_field( $raw['notes'] ) : ( $current ? (string) $current['notes'] : '' ),
			'schedule'       => isset( $raw['schedule'] ) ? Protocol::template_name( sanitize_text_field( $raw['schedule'] ) ) : ( $current ? (string) $current['schedule'] : '' ),
			'system_gallons' => $gallons,
		);
		if ( $current && Layout::is_own_water( $current ) ) {
			// The temporary tanks: each its own water. No schedule and no
			// system volume of the building's own, and its area stays.
			$building['own_water']      = 1;
			$building['area']           = Layout::TEMP_AREA;
			$building['schedule']       = '';
			$building['system_gallons'] = null;
		}

		$taken = array();
		foreach ( $store->buildings() as $row ) {
			if ( (int) $row['id'] !== $id ) {
				$taken[] = (int) $row['number'];
			}
		}

		$errors = Layout::validate_building( $building, $taken );
		// The Temporary building stays active while a tank in it is running.
		$running = $current && Layout::is_own_water( $current ) && 'active' !== $building['status'] ? Temp_Tanks::building_status_error( $current, $building['status'], $store->open_setups() ) : '';
		if ( '' !== $running ) {
			$errors[] = $running;
		}
		if ( '' !== $building['schedule'] && ! isset( self::protocols()['templates'][ $building['schedule'] ] ) ) {
			$errors[] = __( 'that schedule template does not exist', 'fishotel-misc-plugin' );
		}
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
		if ( Layout::is_own_water( $building ) ) {
			// A slot is not a rack tank: it has no level, size or gallons of its own.
			return $current ? self::save_slot( $store, $current, $raw ) : self::add_slot( $store );
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

	/**
	 * One more slot for the temporary tanks: the lowest free number of the
	 * own-water building, up to Layout::MAX_SLOTS (the ones digit is the
	 * slot).
	 *
	 * @param object $store Store (or the tests' stand-in) with temp_building(), add_slot().
	 * @return array{message?: string, number?: int, errors?: string[]}
	 */
	public static function add_slot( $store ) {
		if ( ! $store->temp_building() ) {
			return array( 'errors' => array( __( 'The layout has no building for temporary tanks.', 'fishotel-misc-plugin' ) ) );
		}
		$number = $store->add_slot();
		if ( ! $number ) {
			/* translators: %d: the most slots there can be */
			return array( 'errors' => array( sprintf( __( 'There can be %d temporary slots at most (the last digit of the number is the slot).', 'fishotel-misc-plugin' ), Layout::MAX_SLOTS ) ) );
		}
		$parts = Layout::parse_slot( $number );

		return array(
			/* translators: %s: slot label (TEMP 7 · 907) */
			'message' => sprintf( __( '%s added — print its label with "Print tank labels".', 'fishotel-misc-plugin' ), Layout::slot_name( $parts ? $parts['slot'] : 0 ) . ' · ' . (int) $number ),
			'number'  => (int) $number,
		);
	}

	/**
	 * A slot's one setting: its status. It can be retired (offline) only
	 * while nothing is set up in it — a setup with its fish stays
	 * reachable.
	 *
	 * @param object $store Store (or the tests' stand-in) with save_tank().
	 * @param array  $tank  The slot (hydrated, with its 'setup').
	 * @param array  $raw   Unslashed payload: status.
	 * @return array{message?: string, id?: int, number?: int, errors?: string[]}
	 */
	public static function save_slot( $store, array $tank, array $raw ) {
		$status = isset( $raw['status'] ) && is_scalar( $raw['status'] ) ? sanitize_key( (string) $raw['status'] ) : (string) $tank['status'];
		if ( ! in_array( $status, Layout::TANK_STATUSES, true ) ) {
			return array( 'errors' => array( __( 'unknown tank status', 'fishotel-misc-plugin' ) ) );
		}
		$label = Layout::tank_label( $tank );
		if ( 'offline' === $status && ! empty( $tank['setup'] ) ) {
			/* translators: %s: slot label */
			return array( 'errors' => array( sprintf( __( '%s is set up — take it down on the Board before retiring the slot.', 'fishotel-misc-plugin' ), $label ) ) );
		}

		$saved = $store->save_tank(
			array(
				'id'          => (int) $tank['id'],
				'building_id' => (int) $tank['building_id'],
				'number'      => (int) $tank['number'],
				'level'       => 0,
				'position'    => (int) $tank['position'],
				'size_label'  => '',
				'gallons'     => 0,
				'status'      => $status,
				'sort'        => (int) $tank['sort'],
			)
		);
		if ( ! $saved ) {
			return array( 'errors' => array( __( 'The slot could not be saved.', 'fishotel-misc-plugin' ) ) );
		}

		return array(
			/* translators: %s: slot label */
			'message' => sprintf( __( '%s saved.', 'fishotel-misc-plugin' ), $label ),
			'id'      => (int) $saved,
			'number'  => (int) $tank['number'],
		);
	}

	/* ============================================================== */
	/*  Sizes                                                          */
	/* ============================================================== */

	/**
	 * Settings → Sizes: which product attribute is the size (when several
	 * match) and the plain list used while none exists — the testable half
	 * of ajax_sizes(). The capability (manage_options) and the nonce
	 * first; then size_attribute must be empty (= the first match) or one
	 * of the attributes that match "size", and size_list is read as one
	 * size per line (Sizes::labels()). Only these two keys of the settings
	 * option change. Nothing here touches a product or a term.
	 *
	 * @param object $store Store (or the tests' stand-in) with size_attributes().
	 * @param array  $raw   Unslashed POST: _wpnonce, size_attribute, size_list.
	 * @return array{status: int, ok: bool, message: string, settings?: array}
	 */
	public static function sizes_request( $store, array $raw ) {
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
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$out['message'] = __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$stored    = self::get();
		$attribute = $stored['size_attribute'];
		if ( array_key_exists( 'size_attribute', $raw ) ) {
			// Compared as posted with the attributes that exist — a taxonomy name may
			// hold percent-encoded characters, which sanitize_text_field() would strip.
			$attribute = is_scalar( $raw['size_attribute'] ) && ! is_bool( $raw['size_attribute'] ) ? trim( (string) $raw['size_attribute'] ) : '';
			if ( '' !== $attribute ) {
				$known = false;
				foreach ( (array) $store->size_attributes() as $candidate ) {
					if ( (string) ( $candidate['taxonomy'] ?? '' ) === $attribute ) {
						$known = true;
						break;
					}
				}
				if ( ! $known || strlen( $attribute ) > 60 ) {
					$out['status']  = 400;
					$out['message'] = __( 'That is not a product attribute with "size" in its name. Nothing was changed.', 'fishotel-misc-plugin' );
					return $out;
				}
			}
		}

		$list = $stored['size_list'];
		if ( array_key_exists( 'size_list', $raw ) ) {
			if ( ! is_string( $raw['size_list'] ) && ! is_array( $raw['size_list'] ) ) {
				$out['status']  = 400;
				$out['message'] = __( 'The list of sizes could not be read. Nothing was changed.', 'fishotel-misc-plugin' );
				return $out;
			}
			// Plain text, one size per line: Sizes::labels() trims, drops control characters and caps
			// it; a label keeps its inch mark and a "<" as typed, and is escaped wherever it is shown.
			$list = Sizes::labels( $raw['size_list'] );
		}

		$settings = array_merge(
			$stored,
			array(
				'size_attribute' => $attribute,
				'size_list'      => $list,
			)
		);
		update_option( Clean_Room::OPTION_KEY, $settings );

		$standard       = Sizes::standard( $store, $settings );
		$out['status']  = 200;
		$out['ok']      = true;
		$out['message'] = Sizes::SOURCE_ATTRIBUTE === $standard['source']
			/* translators: 1: attribute label, 2: number of sizes */
			? sprintf( __( 'Sizes saved: the product attribute "%1$s" is the list (%2$d sizes).', 'fishotel-misc-plugin' ), $standard['label'], count( $standard['sizes'] ) )
			/* translators: %d: number of sizes */
			: sprintf( __( 'Sizes saved: %d sizes in the list.', 'fishotel-misc-plugin' ), count( $standard['sizes'] ) );
		$out['settings'] = $settings;

		return $out;
	}

	/**
	 * AJAX: save Settings → Sizes (sizes_request()).
	 */
	public function ajax_sizes() {
		$outcome = self::sizes_request( new Store(), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in sizes_request().
		if ( ! $outcome['ok'] ) {
			wp_send_json_error( array( 'message' => $outcome['message'] ), $outcome['status'] );
		}
		wp_send_json_success( array( 'message' => $outcome['message'] ) );
	}

	/* ============================================================== */
	/*  Remembered box shares                                          */
	/* ============================================================== */

	/**
	 * Settings → Box shares: change one remembered share's percent per
	 * fish, or forget it — the testable half of ajax_box_share(). The
	 * capability (manage_options) and the nonce first. A value typed here
	 * is dated today and belongs to no order line, so only an order dated
	 * today or later replaces it; a forgotten share is deleted and its
	 * lines count nowhere, so it comes back — counted afresh — the next
	 * time an order with that fish and size is saved with a percent.
	 *
	 * @param object $store Store (or the tests' stand-in).
	 * @param array  $raw   Unslashed POST: _wpnonce, op (save|delete), id, pct_per_fish.
	 * @param string $today Site date ('' = Clean_Room::today()).
	 * @return array{status: int, ok: bool, message: string, share?: array}
	 */
	public static function box_share_request( $store, array $raw, $today = '' ) {
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
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$out['message'] = __( 'This form has expired. Reload the page and try again.', 'fishotel-misc-plugin' );
			return $out;
		}

		$id    = isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? absint( $raw['id'] ) : 0;
		$op    = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? sanitize_key( (string) $raw['op'] ) : '';
		$share = $id > 0 ? $store->box_share( $id ) : null;
		if ( ! $share ) {
			$out['status']  = 404;
			$out['message'] = __( 'That remembered share no longer exists.', 'fishotel-misc-plugin' );
			return $out;
		}

		if ( 'delete' === $op ) {
			if ( ! $store->delete_box_share( $id ) ) {
				$out['status']  = 500;
				$out['message'] = __( 'The remembered share could not be removed.', 'fishotel-misc-plugin' );
				return $out;
			}
			$store->clear_line_share_keys( (string) $share['share_key'] ); // Its lines count nowhere now; each counts again when its order is next saved.
			$out['status']  = 200;
			$out['ok']      = true;
			$out['message'] = __( 'Remembered share forgotten.', 'fishotel-misc-plugin' );
			return $out;
		}

		if ( 'save' !== $op ) {
			$out['status']  = 400;
			$out['message'] = __( 'Unknown action.', 'fishotel-misc-plugin' );
			return $out;
		}

		$value = Box_Shares::per_fish_in( $raw['pct_per_fish'] ?? null );
		if ( null === $value ) {
			$out['status']  = 400;
			/* translators: %s: the most one fish may take */
			$out['message'] = sprintf( __( 'The percent of a box per fish must be above 0 and at most %s. Nothing was changed.', 'fishotel-misc-plugin' ), Costing::num( Box_Shares::MAX_PER_FISH ) );
			return $out;
		}
		if ( abs( $value - (float) $share['pct_per_fish'] ) < 0.00005 ) {
			$out['status']  = 200;
			$out['ok']      = true;
			$out['message'] = __( 'Nothing changed.', 'fishotel-misc-plugin' );
			$out['share']   = $share;
			return $out;
		}

		$today = Replay::is_date( $today ) ? (string) $today : Clean_Room::today();
		$saved = $store->update_box_share(
			$id,
			array(
				'pct_per_fish' => $value,
				'last_line_id' => null,
				'last_date'    => $today,
			)
		);
		if ( ! $saved ) {
			$out['status']  = 500;
			$out['message'] = __( 'The remembered share could not be saved.', 'fishotel-misc-plugin' );
			return $out;
		}

		$out['status']  = 200;
		$out['ok']      = true;
		/* translators: 1: fish, 2: percent */
		$out['message'] = sprintf( __( 'Remembered share saved: %1$s takes %2$s%% of a box per fish.', 'fishotel-misc-plugin' ), (string) $share['species'], Costing::num( $value, '0', 4 ) );
		$out['share']   = $store->box_share( $id );

		return $out;
	}

	/**
	 * AJAX: change or forget a remembered share (box_share_request()).
	 */
	public function ajax_box_share() {
		$outcome = self::box_share_request( new Store(), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in box_share_request().
		if ( ! $outcome['ok'] ) {
			wp_send_json_error( array( 'message' => $outcome['message'] ), $outcome['status'] );
		}
		wp_send_json_success(
			array(
				'message' => $outcome['message'],
				'value'   => isset( $outcome['share'] ) ? Costing::num( $outcome['share']['pct_per_fish'], '', 4 ) : '',
			)
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
