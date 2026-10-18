<?php
/**
 * Clean Room admin — the "Import workbook" panel on the Settings tab:
 * upload the FisHotel-YEAR .xlsx / .xlsm (at most 20 MB) → the file is
 * kept in a private folder under the uploads directory → read (Workbook)
 * and mapped (Import_Map) → a preview of what a run would create, update
 * or skip, kept ten minutes in a transient per user → "Import now" with
 * the options (the stays cutoff, the Spa building the recent batches land
 * in, whether the treatment logs come along) → Import::run → a result
 * summary. The file is deleted after the import, on Discard, by a one-off
 * cron event scheduled for just after the preview expires (so a preview
 * nobody comes back to does not leave the workbook behind) and when a
 * later upload finds it expired; the mapped data is never stored outside
 * the Clean Room tables.
 *
 * manage_options and a nonce on every step; the mimes list is explicit;
 * the private folder refuses web requests on Apache (.htaccess, both the
 * 2.2 and 2.4 spellings) and holds an empty index.html — on nginx it is
 * only as private as the server's own rules, so the file is named at
 * random and lives there ten minutes at most.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Import
 */
class Admin_Import {

	const NONCE_UPLOAD  = 'fishotel_cleanroom_import_upload';
	const NONCE_RUN     = 'fishotel_cleanroom_import_run';
	const NONCE_DISCARD = 'fishotel_cleanroom_import_discard';

	/**
	 * Transients, each + the user id: the pending preview (with the file
	 * path) and the last result or error.
	 */
	const PENDING_TRANSIENT = 'fishotel_cleanroom_import_';
	const RESULT_TRANSIENT  = 'fishotel_cleanroom_import_result_';

	/**
	 * How long a preview (and its file) waits for "Import now".
	 *
	 * @var int
	 */
	const TTL = 10 * MINUTE_IN_SECONDS;

	const MAX_BYTES = 20971520;

	/**
	 * The private folder under wp_upload_dir()['basedir'].
	 *
	 * @var string
	 */
	const DIR = 'fishotel-private';

	/**
	 * The only file types the upload takes.
	 *
	 * @var array<string, string>
	 */
	const MIMES = array(
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
	);

	/**
	 * The panel's anchor on the Settings tab.
	 *
	 * @var string
	 */
	const ANCHOR = 'fh-cr-import';

	const STAYS_DAYS_MAX = 365;

	/**
	 * Register the admin-post handlers.
	 */
	public function init() {
		add_action( 'admin_post_fishotel_cleanroom_import_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_fishotel_cleanroom_import_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_fishotel_cleanroom_import_discard', array( $this, 'handle_discard' ) );
	}

	/* ============================================================== */
	/*  The private folder                                             */
	/* ============================================================== */

	/**
	 * The private folder, created with its .htaccess and index.html.
	 *
	 * @return string Path (no trailing slash), '' when it cannot exist.
	 */
	public static function private_dir() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', self::htaccess() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own folder.
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * The .htaccess that refuses every request to the folder (pure).
	 *
	 * @return string
	 */
	public static function htaccess() {
		return "# FisHotel: an uploaded workbook waits here for ten minutes; nothing is ever served.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
	}

	/**
	 * Delete the workbooks that outlived a preview (older than TTL).
	 *
	 * @param string $keep A path to leave alone.
	 * @return int Files removed.
	 */
	public static function cleanup( $keep = '' ) {
		$dir = self::private_dir();
		if ( '' === $dir ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) glob( $dir . '/workbook-*' ) as $path ) {
			if ( $path === $keep || ! is_file( $path ) ) {
				continue;
			}
			if ( time() - (int) filemtime( $path ) > self::TTL && unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * The wp_check_filetype_and_ext filter of the workbook upload (pure).
	 * fileinfo reports every OOXML spreadsheet — an .xlsm included — as the
	 * .xlsx type, which WordPress reads as a mismatch for an .xlsm name and
	 * refuses ("not allowed to upload this file type"), so Jeff's
	 * FisHotel-YEAR.xlsm could never be uploaded. An .xlsx / .xlsm name
	 * whose content fileinfo calls a spreadsheet gets its type back;
	 * anything else stays as WordPress decided (a renamed PHP or text file
	 * is still refused), and the reader then insists on a real workbook.
	 *
	 * @param array        $data      ext, type, proper_filename.
	 * @param string       $filename  The client's file name.
	 * @param string|false $real_mime What fileinfo found (WordPress 5.1+).
	 * @return array
	 */
	public static function check_filetype( $data, $filename, $real_mime ) {
		$data = is_array( $data ) ? $data : array();
		$ext  = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( ( empty( $data['ext'] ) || empty( $data['type'] ) ) && isset( self::MIMES[ $ext ] ) && in_array( (string) $real_mime, self::MIMES, true ) ) {
			$data['ext']  = $ext;
			$data['type'] = self::MIMES[ $ext ];
		}
		return $data;
	}

	/**
	 * The year of the order refs of batches without a QT start date (pure):
	 * a 4-digit year in the client's file name, else the site's year —
	 * never the stored file's random name (or the user id in it).
	 *
	 * @param string $client_name The uploaded file's name.
	 * @param string $today       Site date (Y-m-d).
	 * @return int
	 */
	public static function workbook_year( $client_name, $today ) {
		if ( 1 === preg_match( '/(20\d\d)/', (string) $client_name, $m ) ) {
			return (int) $m[1];
		}
		$year = (int) substr( (string) $today, 0, 4 );
		return $year > 2000 ? $year : Import_Map::FALLBACK_YEAR;
	}

	/**
	 * A random name for the stored file (wp_handle_upload's
	 * unique_filename_callback).
	 *
	 * @param string $dir  Folder (unused).
	 * @param string $name Client name (unused).
	 * @param string $ext  Extension with its dot.
	 * @return string
	 */
	public static function file_name( $dir, $name, $ext ) {
		unset( $dir, $name );
		return 'workbook-' . (int) get_current_user_id() . '-' . wp_generate_password( 16, false, false ) . strtolower( (string) $ext );
	}

	/* ============================================================== */
	/*  State for the panel                                            */
	/* ============================================================== */

	/**
	 * The pending preview of this user, or null when there is none (or
	 * its file is gone).
	 *
	 * @return array|null path, name, year, preview, created.
	 */
	public static function pending() {
		$key     = self::PENDING_TRANSIENT . (int) get_current_user_id();
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending['path'] ) ) {
			return null;
		}
		if ( ! is_file( $pending['path'] ) ) {
			delete_transient( $key );
			return null;
		}
		return $pending;
	}

	/**
	 * The last result or error of this user, read once.
	 *
	 * @return array|null error (string) — or file, result, options.
	 */
	public static function take_result() {
		$key    = self::RESULT_TRANSIENT . (int) get_current_user_id();
		$result = get_transient( $key );
		if ( false !== $result ) {
			delete_transient( $key );
		}
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Everything the panel renders.
	 *
	 * @param Store $store Store.
	 * @return array pending, result, spa_buildings, defaults, urls.
	 */
	public static function panel_data( $store ) {
		$spa = Import::spa_buildings( $store );
		return array(
			'pending'       => self::pending(),
			'result'        => self::take_result(),
			'spa_buildings' => $spa,
			'defaults'      => array(
				'stays_days'        => Import::DEFAULT_STAYS_DAYS,
				'stays_building_id' => self::default_building( $spa ),
				'dose_logs'         => true,
			),
			'action_url'    => admin_url( 'admin-post.php' ),
			'max_bytes'     => self::MAX_BYTES,
		);
	}

	/**
	 * The Spa building the recent batches land in unless chosen: the
	 * 205-gallon system, else the one with the most tanks, else the first.
	 *
	 * @param array[] $spa Import::spa_buildings().
	 * @return int|null
	 */
	public static function default_building( array $spa ) {
		if ( ! $spa ) {
			return null;
		}
		foreach ( $spa as $building ) {
			if ( Layout::SPA_500_GALLONS === (int) $building['gallons'] ) {
				return (int) $building['id'];
			}
		}
		$best = $spa[0];
		foreach ( $spa as $building ) {
			if ( (int) $building['tanks'] > (int) $best['tanks'] ) {
				$best = $building;
			}
		}
		return (int) $best['id'];
	}

	/**
	 * The import options from the form (pure): the stays cutoff in days
	 * (0–365, default 45), the Spa building (one of the active ones, else
	 * the default), the treatment logs (checkbox).
	 *
	 * @param array   $raw Unslashed POST.
	 * @param array[] $spa Import::spa_buildings().
	 * @return array{stays_days: int, stays_building_id: int|null, dose_logs: bool}
	 */
	public static function sanitize_options( array $raw, array $spa ) {
		$days = isset( $raw['stays_days'] ) && is_scalar( $raw['stays_days'] ) && is_numeric( $raw['stays_days'] ) ? (int) $raw['stays_days'] : Import::DEFAULT_STAYS_DAYS;
		$days = max( 0, min( self::STAYS_DAYS_MAX, $days ) );

		$ids      = array_map( 'intval', array_column( $spa, 'id' ) );
		$building = isset( $raw['stays_building_id'] ) && is_scalar( $raw['stays_building_id'] ) ? (int) $raw['stays_building_id'] : 0;
		if ( ! in_array( $building, $ids, true ) ) {
			$building = self::default_building( $spa );
		}

		return array(
			'stays_days'        => $days,
			'stays_building_id' => $building,
			'dose_logs'         => ! empty( $raw['dose_logs'] ) && '0' !== (string) $raw['dose_logs'],
		);
	}

	/**
	 * The result of a run in plain lines (pure).
	 *
	 * @param array $result Import::run().
	 * @return string[]
	 */
	public static function summary_lines( array $result ) {
		$s = (array) ( $result['species'] ?? array() );
		$o = (array) ( $result['orders'] ?? array() );
		$t = (array) ( $result['stays'] ?? array() );
		$l = (array) ( $result['logs'] ?? array() );
		$b = (array) ( $result['box_rates'] ?? array() );

		$lines = array(
			/* translators: 1: created, 2: updated, 3: skipped */
			sprintf( __( 'Species: %1$d added, %2$d updated, %3$d skipped.', 'fishotel-misc-plugin' ), (int) ( $s['created'] ?? 0 ), (int) ( $s['updated'] ?? 0 ), (int) ( $s['skipped'] ?? 0 ) ),
			/* translators: 1: created, 2: lines, 3: skipped */
			sprintf( __( 'Orders: %1$d created with %2$d lines, %3$d already on the site (skipped).', 'fishotel-misc-plugin' ), (int) ( $o['created'] ?? 0 ), (int) ( $o['lines'] ?? 0 ), (int) ( $o['skipped'] ?? 0 ) ),
			/* translators: 1: stays created, 2: orders checked in, 3: lines skipped */
			sprintf( __( 'Stays: %1$d created from %2$d recent orders, %3$d lines skipped (already checked in or nothing received).', 'fishotel-misc-plugin' ), (int) ( $t['created'] ?? 0 ), (int) ( $t['orders'] ?? 0 ), (int) ( $t['skipped'] ?? 0 ) ),
			/* translators: 1: created, 2: skipped */
			sprintf( __( 'Treatment logs: %1$d written, %2$d skipped (already there, or undated).', 'fishotel-misc-plugin' ), (int) ( $l['created'] ?? 0 ), (int) ( $l['skipped'] ?? 0 ) ),
			/* translators: %d: rows */
			sprintf( __( 'Box rates: %d rows in Settings now.', 'fishotel-misc-plugin' ), (int) ( $b['rows'] ?? 0 ) ),
		);
		return $lines;
	}

	/* ============================================================== */
	/*  admin-post handlers                                            */
	/* ============================================================== */

	/**
	 * Capability or die.
	 */
	private static function guard() {
		if ( ! current_user_can( Clean_Room::SETTINGS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to import a workbook.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Back to the panel.
	 */
	private static function redirect() {
		wp_safe_redirect( Admin::page_url( 'settings' ) . '#' . self::ANCHOR );
		exit;
	}

	/**
	 * Keep an error for the panel and go back.
	 *
	 * @param string $message The error.
	 */
	private static function fail( $message ) {
		set_transient( self::RESULT_TRANSIENT . (int) get_current_user_id(), array( 'error' => (string) $message ), self::TTL );
		self::redirect();
	}

	/**
	 * fail(), unless a result is already waiting: the second request of a
	 * double-clicked "Import now" must not replace the first one's result
	 * with an error.
	 *
	 * @param string $message Message.
	 */
	private static function fail_unless_result( $message ) {
		if ( false !== get_transient( self::RESULT_TRANSIENT . (int) get_current_user_id() ) ) {
			self::redirect();
		}
		self::fail( $message );
	}

	/**
	 * admin-post: the upload → the private folder → the preview.
	 */
	public function handle_upload() {
		self::guard();
		check_admin_referer( self::NONCE_UPLOAD );

		$file = isset( $_FILES['workbook'] ) && is_array( $_FILES['workbook'] ) ? $_FILES['workbook'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- handed to wp_handle_upload below.
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			self::fail( __( 'Pick the workbook file first.', 'fishotel-misc-plugin' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			/* translators: %d: PHP upload error code */
			self::fail( sprintf( __( 'The upload failed (error %d). A file over the server\'s upload limit?', 'fishotel-misc-plugin' ), (int) $file['error'] ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			self::fail( __( 'The file is larger than 20 MB.', 'fishotel-misc-plugin' ) );
		}
		$client_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$ext         = strtolower( pathinfo( $client_name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::MIMES[ $ext ] ) ) {
			self::fail( __( 'Only an .xlsx or .xlsm workbook can be imported.', 'fishotel-misc-plugin' ) );
		}

		self::cleanup();
		$dir = self::private_dir();
		if ( '' === $dir ) {
			self::fail( __( 'The private upload folder could not be created under the uploads directory.', 'fishotel-misc-plugin' ) );
		}
		$previous = self::pending();
		if ( $previous && is_file( $previous['path'] ) ) {
			unlink( $previous['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- the last preview's file.
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$into = static function ( $uploads ) use ( $dir ) {
			$uploads['path']    = $dir;
			$uploads['url']     = '';
			$uploads['subdir']  = '';
			$uploads['basedir'] = $dir;
			$uploads['baseurl'] = '';
			$uploads['error']   = false;
			return $uploads;
		};
		$filetype = static function ( $data, $tmp_file, $filename, $mimes = null, $real_mime = false ) {
			unset( $tmp_file, $mimes );
			return self::check_filetype( $data, $filename, $real_mime );
		};
		add_filter( 'upload_dir', $into );
		add_filter( 'wp_check_filetype_and_ext', $filetype, 10, 5 );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => self::MIMES,
				'unique_filename_callback' => array( self::class, 'file_name' ),
			)
		);
		remove_filter( 'wp_check_filetype_and_ext', $filetype, 10 );
		remove_filter( 'upload_dir', $into );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			self::fail( is_array( $moved ) && isset( $moved['error'] ) ? (string) $moved['error'] : __( 'The file could not be stored.', 'fishotel-misc-plugin' ) );
		}
		$path = (string) $moved['file'];
		// The file goes even when nobody comes back to the preview.
		wp_schedule_single_event( time() + self::TTL + MINUTE_IN_SECONDS, Clean_Room::IMPORT_CLEANUP_HOOK, array( basename( $path ) ) );

		$workbook = new Workbook();
		if ( ! $workbook->open( $path ) ) {
			$error = $workbook->last_error();
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			/* translators: %s: the reader's error */
			self::fail( sprintf( __( 'That file could not be read as a workbook: %s', 'fishotel-misc-plugin' ), $error ) );
		}
		$year = self::workbook_year( $client_name, Clean_Room::today() );
		$map  = Import_Map::map( $workbook, $year );
		$workbook->close();

		$store   = new Store();
		$preview = Import::preview(
			$map,
			self::existing( $store ),
			array(
				'today'      => Clean_Room::today(),
				'stays_days' => Import::DEFAULT_STAYS_DAYS,
			)
		);
		unset( $map );

		set_transient(
			self::PENDING_TRANSIENT . (int) get_current_user_id(),
			array(
				'path'    => $path,
				'name'    => $client_name,
				'year'    => $year,
				'preview' => $preview,
				'created' => time(),
			),
			self::TTL
		);
		delete_transient( self::RESULT_TRANSIENT . (int) get_current_user_id() );
		self::redirect();
	}

	/**
	 * What the site already has, for the preview.
	 *
	 * @param Store $store Store.
	 * @return array species, order_refs, box_rates.
	 */
	public static function existing( $store ) {
		return array(
			'species'    => array_column( $store->species_brief(), 'name' ),
			'order_refs' => $store->order_refs(),
			'box_rates'  => Settings::box_rates(),
		);
	}

	/**
	 * admin-post: Import now.
	 */
	public function handle_run() {
		self::guard();
		check_admin_referer( self::NONCE_RUN );

		$pending = self::pending();
		if ( ! $pending ) {
			self::fail_unless_result( __( 'The preview expired — upload the workbook again.', 'fishotel-misc-plugin' ) );
		}
		// Claim the preview before importing: only one request can delete the
		// transient, so a double-click on "Import now" cannot run the import
		// twice and duplicate stays and logs. The file itself is removed by
		// discard_pending() below (or by the scheduled cleanup).
		if ( ! delete_transient( self::PENDING_TRANSIENT . (int) get_current_user_id() ) ) {
			self::fail_unless_result( __( 'This import is already running or has finished — reload this page in a moment for its result.', 'fishotel-misc-plugin' ) );
		}
		if ( ! Store::tables_exist() ) {
			self::fail( __( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ) );
		}

		$store   = new Store();
		$spa     = Import::spa_buildings( $store );
		$options = self::sanitize_options( wp_unslash( $_POST ), $spa ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		$workbook = new Workbook();
		if ( ! $workbook->open( $pending['path'] ) ) {
			$error = $workbook->last_error();
			self::discard_pending( $pending );
			/* translators: %s: the reader's error */
			self::fail( sprintf( __( 'The stored workbook could not be read again: %s', 'fishotel-misc-plugin' ), $error ) );
		}
		$map = Import_Map::map( $workbook, ! empty( $pending['year'] ) ? (int) $pending['year'] : self::workbook_year( (string) ( $pending['name'] ?? '' ), Clean_Room::today() ) );
		$workbook->close();

		try {
			$result = Import::run(
				$map,
				$store,
				array_merge(
					$options,
					array(
						'today'    => Clean_Room::today(),
						'settings' => Settings::get(),
						'actor_id' => (int) get_current_user_id(),
					)
				)
			);
		} catch ( \Throwable $e ) {
			self::discard_pending( $pending );
			/* translators: %s: the error */
			self::fail( sprintf( __( 'The import stopped: %s', 'fishotel-misc-plugin' ), $e->getMessage() ) );
		}
		unset( $map );
		self::discard_pending( $pending );
		Store::clear_species_cache();

		set_transient(
			self::RESULT_TRANSIENT . (int) get_current_user_id(),
			array(
				'file'    => (string) $pending['name'],
				'result'  => $result,
				'options' => $options,
			),
			30 * MINUTE_IN_SECONDS
		);
		self::redirect();
	}

	/**
	 * admin-post: Discard the pending preview and its file.
	 */
	public function handle_discard() {
		self::guard();
		check_admin_referer( self::NONCE_DISCARD );

		$pending = self::pending();
		if ( $pending ) {
			self::discard_pending( $pending );
		}
		self::redirect();
	}

	/**
	 * Delete the pending preview's file and transient.
	 *
	 * @param array $pending pending().
	 */
	private static function discard_pending( array $pending ) {
		if ( ! empty( $pending['path'] ) && is_file( $pending['path'] ) ) {
			unlink( $pending['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		delete_transient( self::PENDING_TRANSIENT . (int) get_current_user_id() );
	}
}
