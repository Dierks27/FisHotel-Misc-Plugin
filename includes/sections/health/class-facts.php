<?php
/**
 * Health facts — collects live facts about the site into one array with
 * no formatting and no judgement; Checks::evaluate() turns them into rows.
 *
 * Everything here is guarded (function_exists / class_exists / defined)
 * because the point of the section is to keep working when a sibling
 * plugin or WooCommerce is missing.
 *
 * Shape (every key always present):
 *   now              int   UNIX timestamp the facts were taken at
 *   php              version, memory_limit (string, THIS request — wp-admin raises
 *                    it to WP_MAX_MEMORY_LIMIT), memory_limit_bytes (int|-1),
 *                    memory_limit_global (php.ini value before WordPress touched it),
 *                    wp_memory_limit, wp_max_memory_limit, effective_limit /
 *                    effective_limit_bytes (what a front-end, admin-ajax or
 *                    admin-post request runs under: the larger of the php.ini
 *                    value and WP_MEMORY_LIMIT; -1 = unlimited), max_execution_time
 *   wp               version, debug, debug_display (true only when WP_DEBUG AND
 *                    WP_DEBUG_DISPLAY are on — core only forces display_errors on
 *                    inside WP_DEBUG), disable_cron, object_cache
 *   woocommerce      version|null, hpos bool|null, active bool
 *   disk_free        int|null (bytes free under WP_CONTENT_DIR)
 *   plugins          key => name, constant, version|null, installed, active, file
 *   theme            name, version, is_fishotel
 *   dompdf           available, class, autoload, autoload_path
 *   updates          key => label, transient, present, checked_at|null, age|null
 *   github_auth_failed  array|null (host, code, time)
 *   cron             hooks: hook => label, owner, owner_active, inactive_reason
 *                    ('' or why an active owner is not expected to schedule it,
 *                    e.g. SMTP with retry off), next (int|false);
 *                    last_run int|null; disable_wp_cron bool
 *   kill_switches    shiptracker_installed, fst_fanout_constant, fst_fanout_option,
 *                    fst_debug_logging, fst_debug_log_exists, fst_debug_log_size
 *   sections         enabled (string[]), weather_enabled bool,
 *                    smtp_retry_enabled bool (fhsmtp_log_settings.retry_enabled === '1')
 *   mail_failures_24h int — failures, not rows: folded repeats are summed
 *   recent_errors    array[] (Store rows)
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Facts
 */
class Facts {

	/**
	 * Transient caching get_plugins().
	 *
	 * @var string
	 */
	const PLUGINS_TRANSIENT = 'fishotel_health_plugins';

	/**
	 * Option updated at the start of every cron request (see Cron::init).
	 *
	 * @var string
	 */
	const LAST_CRON_OPTION = 'fishotel_health_last_cron';

	/**
	 * The FisHotel plugins: key => constant, label, main file (relative to
	 * the plugins dir), update transient and that updater's cache TTL.
	 *
	 * @return array[]
	 */
	public static function plugin_registry() {
		return array(
			'misc'        => array(
				'label'      => 'FisHotel Misc',
				'constant'   => 'FISHOTEL_MISC_VERSION',
				'file'       => 'fishotel-misc-plugin/fishotel-misc-plugin.php',
				'transient'  => 'fishotel_misc_update_check',
				'ttl'        => 3600,
			),
			'batch'       => array(
				'label'      => 'FisHotel Batch Manager',
				'constant'   => 'FISHOTEL_VERSION',
				'file'       => 'fishotel-batch-plugin/fishotel-batch-manager.php',
				'transient'  => 'fishotel_github_updater_version',
				'ttl'        => 3600,
			),
			'shiptracker' => array(
				'label'      => 'FisHotel ShipTracker',
				'constant'   => 'FST_VERSION',
				'file'       => 'fishotel-shiptracker/fishotel-shiptracker.php',
				'transient'  => 'fst_github_update',
				'ttl'        => 21600,
			),
			'smtp'        => array(
				'label'      => 'FisHotel SMTP',
				'constant'   => 'FHSMTP_VERSION',
				'file'       => 'fishotel-smtp/fishotel-smtp.php',
				'transient'  => 'fhsmtp_update_check',
				'ttl'        => 43200,
			),
			'boards'      => array(
				'label'      => 'FH Boards',
				'constant'   => 'FHB_VERSION',
				'file'       => 'fh-boards/fh-boards.php',
				'transient'  => 'fhb_github_update',
				'ttl'        => 21600,
			),
			'wpit'        => array(
				'label'      => 'WPIT Updater',
				'constant'   => 'WPIT_VERSION',
				'file'       => 'wpit-updater/wpit-updater.php',
				'transient'  => 'wpit_github_version',
				'ttl'        => 43200,
			),
		);
	}

	/**
	 * The cron hooks watched: hook => label, owner plugin key ('' = this
	 * plugin's Weather section).
	 *
	 * @return array[]
	 */
	public static function cron_registry() {
		return array(
			'fishotel_weather_nightly'   => array(
				'label' => 'Weather nightly scan',
				'owner' => 'weather',
			),
			'fishotel_health_daily'      => array(
				'label' => 'Health daily prune',
				'owner' => 'misc',
			),
			'fishotel_verification_cron' => array(
				'label' => 'Batch verification',
				'owner' => 'batch',
			),
			'fishotel_lastcall_cron'     => array(
				'label' => 'Batch last call',
				'owner' => 'batch',
			),
			'fhsmtp_retry_failed_emails' => array(
				'label' => 'SMTP retry failed emails',
				'owner' => 'smtp',
			),
			'fst_poll_shipments'         => array(
				'label' => 'ShipTracker poll',
				'owner' => 'shiptracker',
			),
		);
	}

	/**
	 * Collect everything.
	 *
	 * @param Store|null $store Store for the error counts (null = skip).
	 * @return array
	 */
	public static function collect( $store = null ) {
		$now = time();

		$facts = array(
			'now'                => $now,
			'php'                => self::php(),
			'wp'                 => self::wp(),
			'woocommerce'        => self::woocommerce(),
			'disk_free'          => self::disk_free(),
			'plugins'            => self::plugins(),
			'theme'              => self::theme(),
			'dompdf'             => self::dompdf(),
			'updates'            => array(),
			'github_auth_failed' => null,
			'cron'               => array(),
			'kill_switches'      => array(),
			'sections'           => self::sections(),
			'mail_failures_24h'  => 0,
			'recent_errors'      => array(),
		);

		$facts['updates']            = self::updates( $facts['plugins'], $now );
		$facts['github_auth_failed'] = self::github_auth_failed();
		$facts['cron']               = self::cron( $facts['plugins'], $facts['sections'] );
		$facts['kill_switches']      = self::kill_switches( $facts['plugins'] );

		if ( $store && method_exists( $store, 'count_since' ) ) {
			try {
				// A flood of the same failure is folded into one row with a
				// repeats counter, so sum the repeats rather than count rows.
				$facts['mail_failures_24h'] = method_exists( $store, 'sum_repeats_since' )
					? (int) $store->sum_repeats_since( '', $now - DAY_IN_SECONDS, 'mail' )
					: (int) $store->count_since( '', $now - DAY_IN_SECONDS, 'mail' );
				$facts['recent_errors']     = (array) $store->recent_errors( 5 );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return $facts;
	}

	/**
	 * PHP facts.
	 *
	 * @return array
	 */
	public static function php() {
		$limit = (string) ini_get( 'memory_limit' );

		// The facts are collected in wp-admin, where WordPress has already
		// raised memory_limit to WP_MAX_MEMORY_LIMIT for this request. The
		// php.ini value (global_value) is what front-end, admin-ajax and
		// admin-post requests — dompdf, the Batch imports — actually get,
		// raised only as far as WP_MEMORY_LIMIT.
		$global = $limit;
		if ( function_exists( 'ini_get_all' ) ) {
			try {
				$all = @ini_get_all( 'core', true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $all ) && isset( $all['memory_limit']['global_value'] ) && '' !== (string) $all['memory_limit']['global_value'] ) {
					$global = (string) $all['memory_limit']['global_value'];
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$wp_limit     = defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '';
		$wp_max_limit = defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : '';

		$global_bytes = self::to_bytes( $global );
		$wp_bytes     = self::to_bytes( $wp_limit );

		if ( -1 === $global_bytes || -1 === $wp_bytes ) {
			$effective       = '-1';
			$effective_bytes = -1;
		} elseif ( $wp_bytes > $global_bytes ) {
			$effective       = $wp_limit;
			$effective_bytes = $wp_bytes;
		} else {
			$effective       = $global;
			$effective_bytes = $global_bytes;
		}

		return array(
			'version'               => PHP_VERSION,
			'memory_limit'          => $limit,
			'memory_limit_bytes'    => self::to_bytes( $limit ),
			'memory_limit_global'   => $global,
			'wp_memory_limit'       => $wp_limit,
			'wp_max_memory_limit'   => $wp_max_limit,
			'effective_limit'       => $effective,
			'effective_limit_bytes' => $effective_bytes,
			'max_execution_time'    => (int) ini_get( 'max_execution_time' ),
		);
	}

	/**
	 * Shorthand ini size → bytes (-1 = unlimited).
	 *
	 * @param string $value e.g. '256M'.
	 * @return int
	 */
	public static function to_bytes( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( '-1' === $value ) {
			return -1;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				return (int) ( $number * 1024 * 1024 * 1024 );
			case 'm':
				return (int) ( $number * 1024 * 1024 );
			case 'k':
				return (int) ( $number * 1024 );
		}

		return (int) $number;
	}

	/**
	 * WordPress facts.
	 *
	 * @return array
	 */
	public static function wp() {
		return array(
			'version'       => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
			// Core defines WP_DEBUG_DISPLAY = true whenever wp-config does
			// not, but wp_debug_mode() only applies it inside WP_DEBUG, so
			// the constant alone says nothing about what visitors see.
			'debug_display' => defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'disable_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'object_cache'  => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
		);
	}

	/**
	 * WooCommerce facts.
	 *
	 * @return array
	 */
	public static function woocommerce() {
		$active = class_exists( 'WooCommerce' );
		$hpos   = null;

		if ( $active && class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			try {
				$hpos = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			} catch ( \Throwable $e ) {
				$hpos = null;
			}
		}

		return array(
			'active'  => $active,
			'version' => $active && defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
			'hpos'    => $hpos,
		);
	}

	/**
	 * Free bytes under WP_CONTENT_DIR, or null.
	 *
	 * @return int|null
	 */
	public static function disk_free() {
		if ( ! defined( 'WP_CONTENT_DIR' ) || ! function_exists( 'disk_free_space' ) ) {
			return null;
		}

		try {
			$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $free ? null : (int) $free;
	}

	/**
	 * get_plugins() cached in a 5-minute transient.
	 *
	 * @return array file => header array.
	 */
	public static function installed_plugins() {
		$cached = function_exists( 'get_transient' ) ? get_transient( self::PLUGINS_TRANSIENT ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$all  = get_plugins();
		$slim = array();
		foreach ( (array) $all as $file => $header ) {
			$slim[ $file ] = array(
				'Name'    => (string) ( $header['Name'] ?? '' ),
				'Version' => (string) ( $header['Version'] ?? '' ),
			);
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::PLUGINS_TRANSIENT, $slim, 5 * MINUTE_IN_SECONDS );
		}

		return $slim;
	}

	/**
	 * The FisHotel plugins, by constant and by the plugins list.
	 *
	 * @return array key => facts.
	 */
	public static function plugins() {
		$installed = self::installed_plugins();
		$out       = array();

		foreach ( self::plugin_registry() as $key => $def ) {
			$by_constant = defined( $def['constant'] ) ? (string) constant( $def['constant'] ) : null;
			$header      = $installed[ $def['file'] ] ?? null;
			$is_active   = function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( $def['file'] ) : ( null !== $by_constant );

			$out[ $key ] = array(
				'label'     => $def['label'],
				'constant'  => $def['constant'],
				'file'      => $def['file'],
				'version'   => null !== $by_constant ? $by_constant : ( $header ? $header['Version'] : null ),
				'installed' => null !== $header || null !== $by_constant,
				'active'    => $is_active || null !== $by_constant,
				'transient' => $def['transient'],
				'ttl'       => $def['ttl'],
			);
		}

		return $out;
	}

	/**
	 * Theme facts.
	 *
	 * @return array
	 */
	public static function theme() {
		$name    = '';
		$version = defined( 'FISHOTEL_THEME_VERSION' ) ? (string) FISHOTEL_THEME_VERSION : '';

		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( $theme ) {
				$name = (string) $theme->get( 'Name' );
				if ( '' === $version ) {
					$version = (string) $theme->get( 'Version' );
				}
			}
		}

		return array(
			'name'        => $name,
			'version'     => $version,
			'is_fishotel' => defined( 'FISHOTEL_THEME_VERSION' ) || false !== stripos( $name, 'fishotel' ),
		);
	}

	/**
	 * dompdf facts: the class, or the Composer autoloader the theme's
	 * packing-slip uses (vendor/autoload.php in the theme directory).
	 *
	 * @return array
	 */
	public static function dompdf() {
		$class    = class_exists( 'Dompdf\\Dompdf' );
		$autoload = '';

		if ( defined( 'FISHOTEL_THEME_DIR' ) ) {
			$autoload = rtrim( (string) FISHOTEL_THEME_DIR, '/' ) . '/vendor/autoload.php';
		} elseif ( function_exists( 'get_template_directory' ) ) {
			$autoload = rtrim( (string) get_template_directory(), '/' ) . '/vendor/autoload.php';
		}

		$has_autoload = '' !== $autoload && file_exists( $autoload );

		return array(
			'available'     => $class || $has_autoload,
			'class'         => $class,
			'autoload'      => $has_autoload,
			'autoload_path' => $autoload,
		);
	}

	/**
	 * Update-check transients and their age.
	 *
	 * A transient does not record when it was set, but each updater's TTL
	 * is known, so checked_at = expiry − TTL. With an external object cache
	 * the timeout option does not exist; then a present transient counts
	 * as checked now.
	 *
	 * @param array $plugins From self::plugins().
	 * @param int   $now     Now.
	 * @return array key => facts.
	 */
	public static function updates( array $plugins, $now ) {
		$out = array();

		foreach ( $plugins as $key => $plugin ) {
			$transient = (string) $plugin['transient'];
			$present   = function_exists( 'get_transient' ) ? ( false !== get_transient( $transient ) ) : false;
			$expires   = function_exists( 'get_option' ) ? (int) get_option( '_transient_timeout_' . $transient, 0 ) : 0;

			$checked_at = null;
			if ( $present ) {
				$checked_at = $expires > 0 ? $expires - (int) $plugin['ttl'] : $now;
			}

			$out[ $key ] = array(
				'label'      => $plugin['label'],
				'transient'  => $transient,
				'active'     => ! empty( $plugin['active'] ),
				'present'    => $present,
				'checked_at' => $checked_at,
				'age'        => null === $checked_at ? null : max( 0, $now - $checked_at ),
			);
		}

		// WordPress core's own last plugin check, for context.
		$core = function_exists( 'get_site_transient' ) ? get_site_transient( 'update_plugins' ) : null;
		$last = is_object( $core ) && isset( $core->last_checked ) ? (int) $core->last_checked : null;

		$out['wordpress'] = array(
			'label'      => 'WordPress plugin updates',
			'transient'  => 'update_plugins',
			'active'     => true,
			'present'    => null !== $last,
			'checked_at' => $last,
			'age'        => null === $last ? null : max( 0, $now - $last ),
		);

		return $out;
	}

	/**
	 * The GitHub auth-failed transient, or null.
	 *
	 * @return array|null
	 */
	public static function github_auth_failed() {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$value = get_transient( Health::TRANSIENT_GITHUB_AUTH );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * WP-Cron facts.
	 *
	 * @param array $plugins  From self::plugins().
	 * @param array $sections From self::sections().
	 * @return array
	 */
	public static function cron( array $plugins, array $sections ) {
		$hooks = array();

		foreach ( self::cron_registry() as $hook => $def ) {
			$owner           = $def['owner'];
			$inactive_reason = '';

			if ( 'weather' === $owner ) {
				$owner_active = ! empty( $sections['weather_enabled'] );
			} elseif ( 'smtp' === $owner ) {
				// FisHotel SMTP schedules the retry event only while its
				// "Enable Auto-Retry" setting is on (off by default), so an
				// active plugin with retry off is not expected to have it.
				$owner_active = ! empty( $plugins['smtp']['active'] ) && ! empty( $sections['smtp_retry_enabled'] );
				if ( ! $owner_active && ! empty( $plugins['smtp']['active'] ) ) {
					$inactive_reason = 'retry off';
				}
			} else {
				$owner_active = ! empty( $plugins[ $owner ]['active'] );
			}

			$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;

			// ShipTracker schedules through Action Scheduler when WooCommerce
			// provides it; WP-Cron then knows nothing about the hook.
			if ( false === $next && function_exists( 'as_next_scheduled_action' ) ) {
				try {
					$as = as_next_scheduled_action( $hook );
					if ( is_int( $as ) || ( is_string( $as ) && ctype_digit( $as ) ) ) {
						$next = (int) $as;
					} elseif ( true === $as ) {
						$next = time(); // Running right now.
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$hooks[ $hook ] = array(
				'label'           => $def['label'],
				'owner'           => $owner,
				'owner_active'    => $owner_active,
				'inactive_reason' => $inactive_reason,
				'next'            => false === $next ? false : (int) $next,
			);
		}

		$last = null;
		if ( function_exists( 'get_transient' ) ) {
			$lock = get_transient( 'doing_cron' );
			if ( $lock && is_numeric( $lock ) ) {
				$last = (int) (float) $lock;
			}
		}
		if ( function_exists( 'get_option' ) ) {
			$recorded = (int) get_option( self::LAST_CRON_OPTION, 0 );
			if ( $recorded > 0 && ( null === $last || $recorded > $last ) ) {
				$last = $recorded;
			}
		}

		return array(
			'hooks'           => $hooks,
			'last_run'        => $last,
			'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * ShipTracker kill switches and its debug log.
	 *
	 * @param array $plugins From self::plugins().
	 * @return array
	 */
	public static function kill_switches( array $plugins ) {
		$log_path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/fst-debug.log' : '';
		$exists   = '' !== $log_path && file_exists( $log_path );

		return array(
			'shiptracker_installed' => ! empty( $plugins['shiptracker']['installed'] ),
			'fst_fanout_constant'   => defined( 'FST_FULFILLMENT_FANOUT_OFF' ) && FST_FULFILLMENT_FANOUT_OFF,
			'fst_fanout_option'     => function_exists( 'get_option' ) ? (string) get_option( 'fst_fulfillment_fanout_enabled', '1' ) : '1',
			'fst_debug_logging'     => function_exists( 'get_option' ) && 'yes' === get_option( 'fst_debug_logging', 'no' ),
			'fst_debug_log_exists'  => $exists,
			'fst_debug_log_size'    => $exists ? (int) @filesize( $log_path ) : 0, // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		);
	}

	/**
	 * Misc sections, the Weather toggle and the SMTP plugin's retry toggle.
	 *
	 * @return array
	 */
	public static function sections() {
		$enabled = function_exists( 'get_option' ) ? get_option( 'fishotel_misc_enabled_sections', array() ) : array();
		$enabled = array_values( array_map( 'strval', (array) $enabled ) );

		$weather_enabled = false;
		if ( in_array( 'weather', $enabled, true ) && function_exists( 'get_option' ) ) {
			$settings        = get_option( 'fishotel_weather_settings', array() );
			$weather_enabled = ! is_array( $settings ) || ! array_key_exists( 'enabled', $settings ) ? true : ! empty( $settings['enabled'] );
		}

		// FisHotel SMTP: fhsmtp_log_settings.retry_enabled is '1' only after
		// Jeff ticks "Enable Auto-Retry" — the activation defaults omit it.
		$smtp_retry_enabled = false;
		if ( function_exists( 'get_option' ) ) {
			$smtp_log           = get_option( 'fhsmtp_log_settings', array() );
			$smtp_retry_enabled = is_array( $smtp_log ) && isset( $smtp_log['retry_enabled'] ) && '1' === (string) $smtp_log['retry_enabled'];
		}

		return array(
			'enabled'            => $enabled,
			'weather_enabled'    => $weather_enabled,
			'smtp_retry_enabled' => $smtp_retry_enabled,
		);
	}
}
