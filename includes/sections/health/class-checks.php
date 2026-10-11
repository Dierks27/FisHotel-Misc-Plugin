<?php
/**
 * Health checks — PURE. Facts in (see Facts::collect() for the shape),
 * an ordered list of rows out; no WordPress call, no clock but the one
 * passed in, so every rule is unit-tested with fixture facts.
 *
 * Row: id, group, label, status (ok|warn|error|info), value, hint.
 * Groups, in order: Plugins, Updates, Paperwork, Cron, Kill switches,
 * Server, Errors.
 *
 * @package FisHotel\Misc\Sections\Health
 */

namespace FisHotel\Misc\Sections\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checks
 */
class Checks {

	const STATUS_OK    = 'ok';
	const STATUS_WARN  = 'warn';
	const STATUS_ERROR = 'error';
	const STATUS_INFO  = 'info';

	const GROUP_PLUGINS  = 'Plugins';
	const GROUP_UPDATES  = 'Updates';
	const GROUP_PAPER    = 'Paperwork';
	const GROUP_CRON     = 'Cron';
	const GROUP_SWITCHES = 'Kill switches';
	const GROUP_SERVER   = 'Server';
	const GROUP_ERRORS   = 'Errors';

	/**
	 * An update check older than this is stale.
	 *
	 * @var int
	 */
	const UPDATE_STALE_AFTER = 25 * 3600;

	/**
	 * A cron event due longer ago than this is overdue.
	 *
	 * @var int
	 */
	const CRON_OVERDUE_AFTER = 2 * 3600;

	/**
	 * With DISABLE_WP_CRON, no run within this is an error.
	 *
	 * @var int
	 */
	const CRON_SILENT_AFTER = 3600;

	/**
	 * A logged error younger than this is an error row (and counts in the
	 * summary); older ones are listed as info with their age, matching the
	 * 24 h window of the mail-failures row and the GitHub-auth transient.
	 *
	 * @var int
	 */
	const ERROR_FRESH_FOR = 24 * 3600;

	const MEMORY_MIN_BYTES = 268435456;   // 256M
	const DISK_WARN_BYTES  = 2147483648;  // 2 GB
	const DISK_ERROR_BYTES = 524288000;   // 500 MB

	/**
	 * Group order.
	 *
	 * @return string[]
	 */
	public static function groups() {
		return array(
			self::GROUP_PLUGINS,
			self::GROUP_UPDATES,
			self::GROUP_PAPER,
			self::GROUP_CRON,
			self::GROUP_SWITCHES,
			self::GROUP_SERVER,
			self::GROUP_ERRORS,
		);
	}

	/**
	 * Evaluate every rule.
	 *
	 * @param array $facts  Facts::collect() shape (missing keys tolerated).
	 * @param int   $now_ts Now.
	 * @return array[] Rows.
	 */
	public static function evaluate( array $facts, $now_ts ) {
		$now  = (int) $now_ts;
		$rows = array();

		$rows = array_merge( $rows, self::plugin_rows( $facts ) );
		$rows = array_merge( $rows, self::update_rows( $facts, $now ) );
		$rows = array_merge( $rows, self::paperwork_rows( $facts ) );
		$rows = array_merge( $rows, self::cron_rows( $facts, $now ) );
		$rows = array_merge( $rows, self::kill_switch_rows( $facts ) );
		$rows = array_merge( $rows, self::server_rows( $facts ) );
		$rows = array_merge( $rows, self::error_rows( $facts, $now ) );

		return $rows;
	}

	/**
	 * The worst status and the counts.
	 *
	 * @param array[] $rows From evaluate().
	 * @return array{status: string, errors: int, warnings: int}
	 */
	public static function summary( array $rows ) {
		$errors   = 0;
		$warnings = 0;

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? self::STATUS_OK );
			if ( self::STATUS_ERROR === $status ) {
				$errors++;
			} elseif ( self::STATUS_WARN === $status ) {
				$warnings++;
			}
		}

		$status = self::STATUS_OK;
		if ( $errors > 0 ) {
			$status = self::STATUS_ERROR;
		} elseif ( $warnings > 0 ) {
			$status = self::STATUS_WARN;
		}

		return array(
			'status'   => $status,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Rows grouped by their group, in group order.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string, array[]>
	 */
	public static function by_group( array $rows ) {
		$out = array();
		foreach ( self::groups() as $group ) {
			$out[ $group ] = array();
		}
		foreach ( $rows as $row ) {
			$group = (string) ( $row['group'] ?? self::GROUP_SERVER );
			if ( ! isset( $out[ $group ] ) ) {
				$out[ $group ] = array();
			}
			$out[ $group ][] = $row;
		}

		return array_filter( $out );
	}

	/**
	 * Only the error and warning rows, errors first.
	 *
	 * @param array[] $rows Rows.
	 * @param int     $max  Cap.
	 * @return array[]
	 */
	public static function problems( array $rows, $max = 8 ) {
		$errors = array();
		$warns  = array();

		foreach ( $rows as $row ) {
			if ( self::STATUS_ERROR === ( $row['status'] ?? '' ) ) {
				$errors[] = $row;
			} elseif ( self::STATUS_WARN === ( $row['status'] ?? '' ) ) {
				$warns[] = $row;
			}
		}

		return array_slice( array_merge( $errors, $warns ), 0, max( 1, (int) $max ) );
	}

	/* ============================================================== */
	/*  Rule groups                                                    */
	/* ============================================================== */

	/**
	 * Build one row.
	 *
	 * @param string $id     Id.
	 * @param string $group  Group.
	 * @param string $label  Label.
	 * @param string $status Status.
	 * @param string $value  Value.
	 * @param string $hint   Hint.
	 * @return array
	 */
	private static function row( $id, $group, $label, $status, $value = '', $hint = '' ) {
		return array(
			'id'     => (string) $id,
			'group'  => (string) $group,
			'label'  => (string) $label,
			'status' => (string) $status,
			'value'  => (string) $value,
			'hint'   => (string) $hint,
		);
	}

	/**
	 * Plugins group.
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function plugin_rows( array $facts ) {
		$rows = array();

		foreach ( (array) ( $facts['plugins'] ?? array() ) as $key => $plugin ) {
			$label   = (string) ( $plugin['label'] ?? $key );
			$version = (string) ( $plugin['version'] ?? '' );

			if ( ! empty( $plugin['active'] ) ) {
				$rows[] = self::row( 'plugin-' . $key, self::GROUP_PLUGINS, $label, self::STATUS_OK, '' !== $version ? 'v' . $version : 'active' );
				continue;
			}

			$status = 'misc' === $key ? self::STATUS_INFO : self::STATUS_WARN;
			$value  = ! empty( $plugin['installed'] ) ? 'inactive' . ( '' !== $version ? ' (v' . $version . ')' : '' ) : 'not installed';
			$hint   = ! empty( $plugin['installed'] ) ? 'Activate it on the Plugins page if it is meant to run.' : 'Install it from its GitHub repo if it is meant to run on this site.';

			$rows[] = self::row( 'plugin-' . $key, self::GROUP_PLUGINS, $label, $status, $value, $hint );
		}

		$theme = (array) ( $facts['theme'] ?? array() );
		if ( ! empty( $theme['is_fishotel'] ) ) {
			$rows[] = self::row( 'theme', self::GROUP_PLUGINS, 'Theme', self::STATUS_OK, 'FisHotel' . ( ! empty( $theme['version'] ) ? ' v' . $theme['version'] : '' ) );
		} else {
			$rows[] = self::row( 'theme', self::GROUP_PLUGINS, 'Theme', self::STATUS_ERROR, '' !== (string) ( $theme['name'] ?? '' ) ? (string) $theme['name'] : 'unknown', 'The active theme is not FisHotel — the medication store, packing slips and the order screens live in the theme.' );
		}

		$wc = (array) ( $facts['woocommerce'] ?? array() );
		if ( ! empty( $wc['active'] ) ) {
			$hpos   = isset( $wc['hpos'] ) && null !== $wc['hpos'] ? ( $wc['hpos'] ? ', HPOS on' : ', HPOS off' ) : '';
			$rows[] = self::row( 'woocommerce', self::GROUP_PLUGINS, 'WooCommerce', self::STATUS_OK, 'v' . (string) ( $wc['version'] ?? '?' ) . $hpos );
		} else {
			$rows[] = self::row( 'woocommerce', self::GROUP_PLUGINS, 'WooCommerce', self::STATUS_WARN, 'inactive', 'ShipTracker, the Batch plugin and the Weather section need WooCommerce.' );
		}

		return $rows;
	}

	/**
	 * Updates group.
	 *
	 * @param array $facts Facts.
	 * @param int   $now   Now.
	 * @return array[]
	 */
	private static function update_rows( array $facts, $now ) {
		$rows = array();

		foreach ( (array) ( $facts['updates'] ?? array() ) as $key => $update ) {
			if ( isset( $update['active'] ) && ! $update['active'] ) {
				continue;
			}

			$label = (string) ( $update['label'] ?? $key );
			$age   = isset( $update['age'] ) && null !== $update['age'] ? (int) $update['age'] : null;

			if ( null === $age ) {
				$rows[] = self::row( 'update-' . $key, self::GROUP_UPDATES, $label, self::STATUS_WARN, 'last update check never', 'No cached update check found. Open Plugins → "Check for Updates" on that plugin, or wait for WP-Cron.' );
				continue;
			}

			if ( $age > self::UPDATE_STALE_AFTER ) {
				$rows[] = self::row( 'update-' . $key, self::GROUP_UPDATES, $label, self::STATUS_WARN, sprintf( 'last update check %d h ago', (int) floor( $age / 3600 ) ), 'The updater has not checked GitHub in over a day — WP-Cron may be stalled or the request is failing (see the Log tab, source "updater").' );
				continue;
			}

			$rows[] = self::row( 'update-' . $key, self::GROUP_UPDATES, $label, self::STATUS_OK, 'checked ' . self::relative_age( $age ) );
		}

		$auth = $facts['github_auth_failed'] ?? null;
		if ( is_array( $auth ) && ! empty( $auth ) ) {
			$code = (int) ( $auth['code'] ?? 401 );
			$host = (string) ( $auth['host'] ?? 'github.com' );
			$when = isset( $auth['time'] ) ? self::relative_age( max( 0, $now - (int) $auth['time'] ) ) : '';

			$rows[] = self::row(
				'github-auth',
				self::GROUP_UPDATES,
				'GitHub authentication',
				self::STATUS_ERROR,
				trim( sprintf( 'GitHub returned %d for %s %s', $code, $host, $when ) ),
				sprintf( 'GitHub returned %d — the token is expired or the repo is private; see wpit/updater settings', $code )
			);
		} else {
			$rows[] = self::row( 'github-auth', self::GROUP_UPDATES, 'GitHub authentication', self::STATUS_OK, 'no 401/403 in the last 24 h' );
		}

		return $rows;
	}

	/**
	 * Paperwork group.
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function paperwork_rows( array $facts ) {
		$dompdf = (array) ( $facts['dompdf'] ?? array() );

		if ( ! empty( $dompdf['available'] ) ) {
			$value = ! empty( $dompdf['class'] ) ? 'Dompdf class loaded' : 'theme vendor/autoload.php present';
			return array( self::row( 'dompdf', self::GROUP_PAPER, 'dompdf (EA packing-slip PDF)', self::STATUS_OK, $value ) );
		}

		return array( self::row( 'dompdf', self::GROUP_PAPER, 'dompdf (EA packing-slip PDF)', self::STATUS_ERROR, 'missing', 'EA packing slips send without the PDF' ) );
	}

	/**
	 * Cron group.
	 *
	 * @param array $facts Facts.
	 * @param int   $now   Now.
	 * @return array[]
	 */
	private static function cron_rows( array $facts, $now ) {
		$rows = array();
		$cron = (array) ( $facts['cron'] ?? array() );

		$last     = isset( $cron['last_run'] ) && null !== $cron['last_run'] ? (int) $cron['last_run'] : null;
		$disabled = ! empty( $cron['disable_wp_cron'] );
		$since    = null === $last ? null : max( 0, $now - $last );

		if ( $disabled ) {
			if ( null === $since || $since > self::CRON_SILENT_AFTER ) {
				$rows[] = self::row( 'wp-cron', self::GROUP_CRON, 'WP-Cron', self::STATUS_ERROR, null === $since ? 'DISABLE_WP_CRON is on and no run has been seen' : sprintf( 'DISABLE_WP_CRON is on, last run %s', self::relative_age( $since ) ), 'The system cron that is supposed to hit wp-cron.php is not running. Check the host\'s cron job (curl …/wp-cron.php?doing_wp_cron every 5–15 min).' );
			} else {
				$rows[] = self::row( 'wp-cron', self::GROUP_CRON, 'WP-Cron', self::STATUS_INFO, sprintf( 'DISABLE_WP_CRON on, last run %s', self::relative_age( $since ) ) );
			}
		} else {
			$rows[] = self::row( 'wp-cron', self::GROUP_CRON, 'WP-Cron', self::STATUS_INFO, null === $since ? 'built-in (request-driven), no run seen yet' : sprintf( 'built-in (request-driven), last run %s', self::relative_age( $since ) ) );
		}

		foreach ( (array) ( $cron['hooks'] ?? array() ) as $hook => $def ) {
			$label = (string) ( $def['label'] ?? $hook );
			$next  = $def['next'] ?? false;

			if ( empty( $def['owner_active'] ) ) {
				$reason = (string) ( $def['inactive_reason'] ?? '' );
				$hint   = '';
				if ( '' !== $reason && 'smtp' === ( $def['owner'] ?? '' ) ) {
					$hint = 'FisHotel SMTP only schedules this while "Enable Auto-Retry" is on (FisHotel SMTP → Logging). Turn it on there if you want failed emails retried.';
				}
				$rows[] = self::row( 'cron-' . $hook, self::GROUP_CRON, $label, self::STATUS_INFO, '' !== $reason ? $reason : 'owner not active', $hint );
				continue;
			}

			if ( false === $next || null === $next ) {
				$hint = 'weather' === ( $def['owner'] ?? '' )
					? 'The Weather section is enabled but its nightly event is missing. Save the Weather settings to re-arm it.'
					: 'The plugin is active but its event is not scheduled. Deactivate and reactivate the plugin to re-arm it.';
				$rows[] = self::row( 'cron-' . $hook, self::GROUP_CRON, $label, self::STATUS_ERROR, 'not scheduled', $hint );
				continue;
			}

			$next  = (int) $next;
			$delta = $next - $now;

			if ( $delta < -self::CRON_OVERDUE_AFTER ) {
				$rows[] = self::row( 'cron-' . $hook, self::GROUP_CRON, $label, self::STATUS_WARN, sprintf( 'WP-Cron overdue — due %s', self::relative_age( -$delta ) ), 'WP-Cron has not fired this event. On a quiet site visit the front end once, or set up a system cron.' );
				continue;
			}

			$value  = $delta >= 0 ? 'next in ' . self::duration( $delta ) : 'due ' . self::relative_age( -$delta );
			$rows[] = self::row( 'cron-' . $hook, self::GROUP_CRON, $label, self::STATUS_OK, $value );
		}

		return $rows;
	}

	/**
	 * Kill switches group (only when ShipTracker is installed).
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function kill_switch_rows( array $facts ) {
		$ks = (array) ( $facts['kill_switches'] ?? array() );

		if ( empty( $ks['shiptracker_installed'] ) ) {
			return array();
		}

		$rows = array();

		if ( ! empty( $ks['fst_fanout_constant'] ) || '0' === (string) ( $ks['fst_fanout_option'] ?? '1' ) ) {
			$why    = ! empty( $ks['fst_fanout_constant'] ) ? 'FST_FULFILLMENT_FANOUT_OFF is defined in wp-config.php' : 'option fst_fulfillment_fanout_enabled is 0';
			$rows[] = self::row( 'fst-fanout', self::GROUP_SWITCHES, 'ShipTracker fulfillment fan-out', self::STATUS_WARN, 'ShipTracker fan-out is OFF', $why . '. Tracking added to a combined box lands on the fulfillment order only; the source orders get no shipment email.' );
		} else {
			$rows[] = self::row( 'fst-fanout', self::GROUP_SWITCHES, 'ShipTracker fulfillment fan-out', self::STATUS_OK, 'ON' );
		}

		$size = (int) ( $ks['fst_debug_log_size'] ?? 0 );
		if ( ! empty( $ks['fst_debug_logging'] ) ) {
			$rows[] = self::row(
				'fst-debug-log',
				self::GROUP_SWITCHES,
				'ShipTracker debug logging',
				self::STATUS_WARN,
				sprintf( 'fst-debug.log is web-readable at /wp-content/fst-debug.log (%s): turn off debug logging or move it (ShipTracker fix pending)', self::kb( $size ) ),
				'ShipTracker → Settings → Advanced → Debug Logging off, then delete wp-content/fst-debug.log.'
			);
		} elseif ( ! empty( $ks['fst_debug_log_exists'] ) ) {
			$rows[] = self::row( 'fst-debug-log', self::GROUP_SWITCHES, 'ShipTracker debug logging', self::STATUS_INFO, sprintf( 'off, but fst-debug.log is still on disk (%s)', self::kb( $size ) ), 'Delete wp-content/fst-debug.log — it is readable from the web.' );
		} else {
			$rows[] = self::row( 'fst-debug-log', self::GROUP_SWITCHES, 'ShipTracker debug logging', self::STATUS_OK, 'off' );
		}

		return $rows;
	}

	/**
	 * Server group.
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function server_rows( array $facts ) {
		$rows = array();
		$php  = (array) ( $facts['php'] ?? array() );
		$wp   = (array) ( $facts['wp'] ?? array() );

		$version = (string) ( $php['version'] ?? '' );
		if ( '' !== $version && version_compare( $version, '8.0', '<' ) ) {
			$rows[] = self::row( 'php', self::GROUP_SERVER, 'PHP', self::STATUS_INFO, $version, 'PHP 7.4 is end-of-life; the FisHotel code runs on 8.x — ask the host to switch when convenient.' );
		} else {
			$rows[] = self::row( 'php', self::GROUP_SERVER, 'PHP', self::STATUS_OK, $version );
		}

		// Judge the limit front-end / admin-ajax / admin-post requests get
		// (effective_limit), not the one wp-admin raised for this page load.
		$admin = (string) ( $php['memory_limit'] ?? '' );
		if ( isset( $php['effective_limit_bytes'] ) ) {
			$bytes = (int) $php['effective_limit_bytes'];
			$limit = (string) ( $php['effective_limit'] ?? $admin );
		} else {
			$bytes = isset( $php['memory_limit_bytes'] ) ? (int) $php['memory_limit_bytes'] : 0;
			$limit = $admin;
		}
		$value = $limit . ( '' !== $admin && $admin !== $limit ? ' (admin ' . $admin . ')' : '' );
		if ( -1 === $bytes ) {
			$rows[] = self::row( 'memory', self::GROUP_SERVER, 'memory_limit', self::STATUS_OK, 'unlimited' );
		} elseif ( $bytes < self::MEMORY_MIN_BYTES ) {
			$rows[] = self::row( 'memory', self::GROUP_SERVER, 'memory_limit', self::STATUS_WARN, $value, 'Below 256M. dompdf and the Batch imports can run out of memory — raise WP_MEMORY_LIMIT / the php.ini value.' );
		} else {
			$rows[] = self::row( 'memory', self::GROUP_SERVER, 'memory_limit', self::STATUS_OK, $value );
		}

		$rows[] = self::row( 'max-exec', self::GROUP_SERVER, 'max_execution_time', self::STATUS_INFO, (string) (int) ( $php['max_execution_time'] ?? 0 ) . ' s' );

		$disk = $facts['disk_free'] ?? null;
		if ( null === $disk ) {
			$rows[] = self::row( 'disk', self::GROUP_SERVER, 'Disk free (wp-content)', self::STATUS_INFO, 'unknown' );
		} elseif ( (int) $disk < self::DISK_ERROR_BYTES ) {
			$rows[] = self::row( 'disk', self::GROUP_SERVER, 'Disk free (wp-content)', self::STATUS_ERROR, self::bytes( (int) $disk ), 'Under 500 MB. Uploads, PDFs and database writes will start failing — clear old backups / logs or ask the host for space.' );
		} elseif ( (int) $disk < self::DISK_WARN_BYTES ) {
			$rows[] = self::row( 'disk', self::GROUP_SERVER, 'Disk free (wp-content)', self::STATUS_WARN, self::bytes( (int) $disk ), 'Under 2 GB. Clear old backups or logs before it becomes a problem.' );
		} else {
			$rows[] = self::row( 'disk', self::GROUP_SERVER, 'Disk free (wp-content)', self::STATUS_OK, self::bytes( (int) $disk ) );
		}

		if ( ! empty( $wp['debug_display'] ) ) {
			$rows[] = self::row( 'debug-display', self::GROUP_SERVER, 'WP_DEBUG_DISPLAY', self::STATUS_WARN, 'on (WP_DEBUG on)', 'PHP notices are printed to visitors. Set WP_DEBUG_DISPLAY to false in wp-config.php (keep WP_DEBUG_LOG if you want a file).' );
		} else {
			$rows[] = self::row( 'debug-display', self::GROUP_SERVER, 'WP_DEBUG_DISPLAY', self::STATUS_OK, ! empty( $wp['debug'] ) ? 'off (WP_DEBUG on, display off)' : 'off' );
		}

		$rows[] = self::row( 'wp-version', self::GROUP_SERVER, 'WordPress', self::STATUS_INFO, (string) ( $wp['version'] ?? '' ) . ( ! empty( $wp['object_cache'] ) ? ', persistent object cache' : ', no persistent object cache' ) );

		return $rows;
	}

	/**
	 * Errors group.
	 *
	 * @param array $facts Facts.
	 * @param int   $now   Now.
	 * @return array[]
	 */
	private static function error_rows( array $facts, $now ) {
		$rows  = array();
		$mails = (int) ( $facts['mail_failures_24h'] ?? 0 );

		if ( $mails > 0 ) {
			$rows[] = self::row( 'mail-failures', self::GROUP_ERRORS, 'Mail failures (24 h)', self::STATUS_ERROR, (string) $mails, 'wp_mail reported failures (repeats within 10 minutes are counted, not just rows). Check FisHotel SMTP → connection status, and FisHotel SMTP → Email Logs for every recipient and subject; the Log tab here (source "mail") has the folded rows.' );
		} else {
			$rows[] = self::row( 'mail-failures', self::GROUP_ERRORS, 'Mail failures (24 h)', self::STATUS_OK, '0' );
		}

		$recent = (array) ( $facts['recent_errors'] ?? array() );
		if ( empty( $recent ) ) {
			$rows[] = self::row( 'recent-errors', self::GROUP_ERRORS, 'Recent errors', self::STATUS_OK, 'none logged' );
			return $rows;
		}

		foreach ( array_slice( $recent, 0, 5 ) as $entry ) {
			$entry   = (array) $entry;
			$id      = (int) ( $entry['id'] ?? 0 );
			$ts      = isset( $entry['logged_ts'] ) ? (int) $entry['logged_ts'] : ( isset( $entry['logged_at'] ) ? (int) strtotime( (string) $entry['logged_at'] . ' UTC' ) : 0 );
			$repeats = (int) ( $entry['repeats'] ?? ( $entry['context']['repeats'] ?? 1 ) );
			$message = self::cut( (string) ( $entry['message'] ?? '' ), 160 );

			// Only a fresh error keeps the summary red; an old one is history.
			$fresh = $ts > 0 && ( $now - $ts ) <= self::ERROR_FRESH_FOR;

			$rows[] = self::row(
				'error-' . $id,
				self::GROUP_ERRORS,
				(string) ( $entry['source'] ?? 'app' ) . ': ' . $message,
				$fresh ? self::STATUS_ERROR : self::STATUS_INFO,
				$ts > 0 ? self::relative_age( max( 0, $now - $ts ) ) : '',
				$repeats > 1 ? sprintf( 'repeated %d× within 10 minutes', $repeats ) : ''
			);
		}

		return $rows;
	}

	/**
	 * Shorten a UTF-8 string to at most $bytes bytes without splitting a
	 * character (a split one makes esc_html() return '' for the whole
	 * string); anything cut gets an ellipsis in the last three bytes.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Byte cap, including the ellipsis.
	 * @return string
	 */
	public static function cut( $text, $bytes ) {
		$text  = (string) $text;
		$bytes = max( 4, (int) $bytes );

		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}

		$keep = $bytes - 3; // The ellipsis is three bytes.

		return ( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $keep, 'UTF-8' ) : substr( $text, 0, $keep ) ) . '…';
	}

	/* ============================================================== */
	/*  Formatting helpers (pure)                                      */
	/* ============================================================== */

	/**
	 * "just now", "5 min ago", "3 h ago", "2 d ago".
	 *
	 * @param int $seconds Age.
	 * @return string
	 */
	public static function relative_age( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			return 'just now';
		}

		return self::duration( $seconds ) . ' ago';
	}

	/**
	 * "5 min", "3 h", "2 d".
	 *
	 * @param int $seconds Duration.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			return $seconds . ' s';
		}
		if ( $seconds < 3600 ) {
			return (int) floor( $seconds / 60 ) . ' min';
		}
		if ( $seconds < 2 * 86400 ) {
			return (int) floor( $seconds / 3600 ) . ' h';
		}

		return (int) floor( $seconds / 86400 ) . ' d';
	}

	/**
	 * "12 KB".
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function kb( $bytes ) {
		return (int) ceil( max( 0, (int) $bytes ) / 1024 ) . ' KB';
	}

	/**
	 * "1.5 GB" / "320 MB".
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );

		if ( $bytes >= 1073741824 ) {
			return round( $bytes / 1073741824, 1 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return (int) round( $bytes / 1048576 ) . ' MB';
		}

		return self::kb( $bytes );
	}
}
