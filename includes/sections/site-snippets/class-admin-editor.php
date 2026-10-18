<?php
/**
 * Snippets admin — the editor: the "My snippets" and "Log" tabs of the
 * Snippets page, and the ONLY ways in for a write: nonce'd admin-post
 * handlers (save, restore, delete, restore-after-delete, export, discard
 * a draft) and one nonce'd AJAX handler (the switch). No REST route.
 *
 * Every write first asks Editor_Rules::permission() (a user named in
 * FISHOTEL_SNIPPETS_EDITORS with manage_options, never with
 * DISALLOW_FILE_EDIT) and checks the nonce; a refusal is logged as
 * 'denied' (at most 30 rows per user an hour) and ends the request. The
 * pages only read; the log CSV needs manage_options and its own nonce.
 *
 * Never lose his work: a refused save keeps what was typed as a per-user
 * draft (user meta) and the edit screen shows it with the reason. The draft
 * is written BEFORE the test run too, so a compile-stage fatal — which ends
 * the save request — still leaves the code; the next Snippets page view
 * finds that draft still "testing", logs the test_failed row and shows it.
 *
 * Every value is escaped in the views; snippet code only ever appears in a
 * textarea (esc_textarea), a <pre> (esc_html) or wp_text_diff() output.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Editor
 */
class Admin_Editor {

	/**
	 * User meta holding the drafts: snippet id (0 = new) => draft.
	 *
	 * @var string
	 */
	const DRAFTS_META = 'fishotel_snippets_drafts';

	/**
	 * Transients: the one-shot page notice, the denied-row budget, the
	 * once-an-hour safe-mode row (each + user id).
	 */
	const NOTICE_TRANSIENT = 'fishotel_snippets_notice_';
	const DENIED_TRANSIENT = 'fishotel_snippets_denied_';
	const SAFE_TRANSIENT   = 'fishotel_snippets_safe_logged_';

	/**
	 * Limits.
	 */
	const DENIED_PER_HOUR = 30;
	const DRAFT_SECONDS   = 604800;
	const LOG_PER_PAGE    = 50;

	/**
	 * The admin-post action that discards a draft, and the CSV nonce.
	 */
	const DISCARD_ACTION = 'fishotel_snippets_discard_draft';
	const CSV_NONCE      = 'fishotel_snippets_log_csv';

	/**
	 * Test seam: called instead of exit after a download.
	 *
	 * @var callable|null
	 */
	public static $halt = null;

	/**
	 * Register hooks (wp-admin only).
	 */
	public function init() {
		add_action( 'admin_post_' . Editor::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . Editor::RESTORE_ACTION, array( $this, 'handle_restore' ) );
		add_action( 'admin_post_' . Editor::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . Editor::UNDELETE_ACTION, array( $this, 'handle_undelete' ) );
		add_action( 'admin_post_' . Editor::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_' . Editor::CSV_ACTION, array( $this, 'handle_log_csv' ) );
		add_action( 'admin_post_' . self::DISCARD_ACTION, array( $this, 'handle_discard' ) );
		add_action( 'wp_ajax_' . Editor::TOGGLE_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 21 );
		add_action( 'admin_init', array( $this, 'maybe_log_safe_mode' ) );
	}

	/* ============================================================== */
	/*  Wiring                                                         */
	/* ============================================================== */

	/**
	 * The Editor's dependencies on the site.
	 *
	 * @param array $extra Overrides (before_test).
	 * @return array
	 */
	public static function deps( array $extra = array() ) {
		return array_merge(
			array(
				'files'        => static function () {
					return Catalog::get( null, true );
				},
				'test_run'     => array( Loader::class, 'test_run' ),
				'actor'        => Editor::actor_now(),
				'now'          => static function () {
					return gmdate( 'Y-m-d H:i:s' );
				},
				'emit'         => static function ( $level, $message, $context ) {
					do_action( 'fishotel_log', $level, Loader::LOG_SOURCE, $message, $context );
				},
				'write_active' => static function ( array $map ) {
					update_option( Db_Snippets::OPTION_ACTIVE, $map, true );
				},
			),
			$extra
		);
	}

	/**
	 * An Editor on the real store.
	 *
	 * @param array $extra Extra dependencies.
	 * @return Editor
	 */
	public static function editor( array $extra = array() ) {
		return new Editor( self::store(), self::deps( $extra ) );
	}

	/**
	 * The store (a test may swap it).
	 *
	 * @var object|null
	 */
	public static $store = null;

	/**
	 * The store.
	 *
	 * @return Db_Store|object
	 */
	public static function store() {
		return null !== self::$store ? self::$store : new Db_Store();
	}

	/**
	 * Page URL on a tab of the Snippets page (keeps safe mode by query).
	 *
	 * @param array $args Query args (tab defaults to mine).
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return Admin::page_url( array_merge( array( 'tab' => 'mine' ), $args ) );
	}

	/**
	 * admin-post.php, keeping safe mode by query (a save must not run the
	 * snippets the owner is trying to fix).
	 *
	 * @return string
	 */
	public static function post_url() {
		$url = admin_url( 'admin-post.php' );
		if ( 'query' === Loader::safe_mode()['reason'] ) {
			$url = add_query_arg( Loader::SAFE_MODE_QUERY, '1', $url );
		}
		return $url;
	}

	/* ============================================================== */
	/*  Request helpers                                                */
	/* ============================================================== */

	/**
	 * A posted value, unslashed ('' when absent or not a string).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- every caller runs after authorize(); the code field is code, validated by the Editor and never echoed unescaped.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * A posted whole number.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	private static function posted_int( $key ) {
		return absint( self::posted( $key ) );
	}

	/**
	 * The editor form's fields as typed.
	 *
	 * @return array
	 */
	public static function read_input() {
		$out = array();
		foreach ( array( 'slug', 'name', 'description', 'type', 'scope', 'priority', 'code', 'base_version' ) as $key ) {
			$out[ $key ] = self::posted( $key );
		}
		$out['active'] = '1' === self::posted( 'active' ) ? '1' : '';
		return $out;
	}

	/**
	 * End a request after a redirect (wp_safe_redirect + exit).
	 *
	 * @param string $url URL.
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		self::halt();
	}

	/**
	 * exit, or the test seam.
	 */
	private static function halt() {
		if ( is_callable( self::$halt ) ) {
			call_user_func( self::$halt );
			return;
		}
		exit;
	}

	/* ============================================================== */
	/*  Who may write                                                  */
	/* ============================================================== */

	/**
	 * Refuse this write unless the user may edit snippets and the nonce is
	 * good: log 'denied' and end the request (403).
	 *
	 * @param string $attempt    What is tried.
	 * @param int    $snippet_id Snippet id (0 = none).
	 * @param bool   $ajax       Answer as JSON.
	 */
	private function authorize( $attempt, $snippet_id, $ajax = false ) {
		$perm  = Editor::permission_now();
		$nonce = self::posted( Editor::NONCE_FIELD );
		$ok    = '' !== $nonce && false !== wp_verify_nonce( $nonce, Editor::NONCE );

		if ( $perm['allowed'] && $ok ) {
			return;
		}

		$reason = $perm['allowed'] ? 'the form\'s security token was missing or expired — reload the page and try again' : $perm['message'];
		$slug   = '';
		if ( $snippet_id > 0 && Db_Store::installed() ) {
			$row  = self::store()->get( $snippet_id );
			$slug = is_array( $row ) ? (string) $row['slug'] : '';
		}
		$this->log_denied( $attempt, $slug, $snippet_id, $reason );

		if ( $ajax ) {
			wp_send_json_error( array( 'message' => 'Refused: ' . $reason ), 403 );
		}

		wp_die( esc_html( 'Refused: ' . $reason ), esc_html__( 'Snippets', 'fishotel-misc-plugin' ), array( 'response' => 403, 'back_link' => true ) );
	}

	/**
	 * Log a refused write, at most DENIED_PER_HOUR rows per user an hour
	 * (a logged-in customer cannot flood the log).
	 *
	 * @param string $attempt    What was tried.
	 * @param string $slug       Slug.
	 * @param int    $snippet_id Snippet id.
	 * @param string $reason     Why.
	 */
	private function log_denied( $attempt, $slug, $snippet_id, $reason ) {
		if ( ! Db_Store::installed() ) {
			return;
		}

		$key   = self::DENIED_TRANSIENT . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::DENIED_PER_HOUR ) {
			return;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		self::editor()->deny( $attempt, $slug, $snippet_id, $reason );
	}

	/* ============================================================== */
	/*  Handlers                                                       */
	/* ============================================================== */

	/**
	 * admin-post: save (create / update) from the edit form.
	 */
	public function handle_save() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'save', $id );

		$input = self::read_input();
		$uid   = get_current_user_id();

		if ( ! Db_Store::installed() ) {
			self::keep_draft( $uid, $id, $input, 'refused', 'Not saved: the snippet tables are not installed yet (see the notice on My snippets).', array() );
			self::redirect( self::url( $id > 0 ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'new' ) ) );
			return;
		}

		$editor = self::editor(
			array(
				'before_test' => static function ( array $fields ) use ( $uid, $id, $input ) {
					// Kept before the test run: a compile-stage fatal ends this
					// request, and the code must survive it.
					self::keep_draft( $uid, $id, array_merge( $input, array( 'code' => $fields['code'] ) ), 'testing', '', array(), (int) $fields['version'] );
				},
			)
		);

		$result = $editor->save( $input, $id );

		if ( $result['ok'] ) {
			self::drop_draft( $uid, $id );
			self::notice( 'unchanged' === $result['code'] ? 'info' : 'ok', $result['message'] );
			self::redirect( self::url( array( 'view' => 'edit', 'id' => $result['id'] ) ) );
			return;
		}

		self::keep_draft( $uid, $id, $input, 'refused', $result['message'], $result['errors'] );
		self::redirect( self::url( $id > 0 ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'new' ) ) );
	}

	/**
	 * admin-post: restore a revision (History).
	 */
	public function handle_restore() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'restore a version', $id );

		$uid    = get_current_user_id();
		$editor = self::editor(
			array(
				'before_test' => static function ( array $fields ) use ( $uid, $id ) {
					self::keep_draft( $uid, $id, $fields, 'testing', '', array(), (int) $fields['version'] );
				},
			)
		);
		$result = $editor->restore_revision( $id, self::posted_int( 'revision_id' ), self::posted( 'base_version' ) );

		if ( $result['ok'] || 'test_failed' !== $result['code'] ) {
			self::drop_draft( $uid, $id );
		} else {
			$draft = self::draft( $uid, $id );
			if ( is_array( $draft ) ) {
				self::keep_draft( $uid, $id, $draft['fields'], 'refused', $result['message'], $result['errors'] );
			}
		}

		self::notice( $result['ok'] ? 'ok' : 'error', $result['message'] );
		self::redirect( self::url( array( 'view' => 'history', 'id' => $id ) ) );
	}

	/**
	 * admin-post: delete a snippet.
	 */
	public function handle_delete() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'delete', $id );

		$result = self::editor()->delete( $id );
		if ( $result['ok'] ) {
			self::drop_draft( get_current_user_id(), $id );
		}

		self::notice( $result['ok'] ? 'ok' : 'error', $result['message'] );
		self::redirect( self::url() );
	}

	/**
	 * admin-post: restore a deleted snippet (Log tab).
	 */
	public function handle_undelete() {
		$this->authorize( 'restore a deleted snippet', 0 );

		$result = self::editor()->restore_deleted( self::posted_int( 'log_id' ) );

		self::notice( $result['ok'] ? 'ok' : 'error', $result['ok'] ? $result['message'] . ' It is switched off — check it, then switch it on.' : $result['message'] );
		self::redirect( $result['ok'] ? self::url( array( 'view' => 'edit', 'id' => $result['id'] ) ) : self::url( array( 'tab' => 'log' ) ) );
	}

	/**
	 * admin-post: download a snippet as a file for GitHub.
	 */
	public function handle_export() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'export', $id );

		$date   = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$result = self::editor()->export( $id, $date );

		if ( ! $result['ok'] ) {
			self::notice( 'error', $result['message'] );
			self::redirect( self::url() );
			return;
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo $result['file']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download (text/plain attachment), not a page.
		self::halt();
	}

	/**
	 * admin-post (GET): the audit log as CSV — manage_options and its own
	 * nonce (a read, not a write).
	 */
	public function handle_log_csv() {
		if ( ! current_user_can( Loader::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to download the snippet log.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::CSV_NONCE ) ) {
			wp_die( esc_html__( 'The link has expired; reload the Log tab and try again.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="fishotel-snippets-log-' . gmdate( 'Ymd-His' ) . '.csv"' );
		}

		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			self::write_csv( $out, self::store() );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		self::halt();
	}

	/**
	 * Write the log CSV (header + every row, oldest first, in batches).
	 *
	 * @param resource $out   Stream.
	 * @param object   $store Store.
	 */
	public static function write_csv( $out, $store ) {
		fputcsv( $out, Editor_Rules::csv_header(), ',', '"', '\\' );

		$after = 0;
		do {
			$rows = $store->log_after( $after, 500 );
			foreach ( $rows as $row ) {
				fputcsv( $out, Editor_Rules::csv_row( $row ), ',', '"', '\\' );
				$after = (int) $row['id'];
			}
		} while ( count( $rows ) >= 500 );
	}

	/**
	 * admin-post: forget the current user's draft of a snippet.
	 */
	public function handle_discard() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'discard a draft', $id );

		self::drop_draft( get_current_user_id(), $id );
		self::notice( 'info', __( 'The unsaved draft was discarded; the saved version is shown.', 'fishotel-misc-plugin' ) );
		self::redirect( self::url( $id > 0 ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'new' ) ) );
	}

	/**
	 * AJAX: switch a database snippet on or off.
	 */
	public function ajax_toggle() {
		$id = self::posted_int( 'snippet_id' );
		$this->authorize( 'switch', $id, true );

		$on     = '1' === self::posted( 'enabled' );
		$result = self::editor()->set_active( $id, $on );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		$row    = self::store()->get( $id );
		$status = is_array( $row ) ? self::db_status( $row, Db_Snippets::merge( Catalog::get(), Loader::db_active() ), Catalog::get(), Loader::safe_mode() ) : null;

		wp_send_json_success(
			array(
				'message' => $result['message'],
				'enabled' => is_array( $row ) && ! empty( $row['active'] ),
				'version' => $result['version'],
				'status'  => $status,
			)
		);
	}

	/**
	 * admin_init: log safe mode once an hour per user while it is on.
	 */
	public function maybe_log_safe_mode() {
		$safe = Loader::safe_mode();
		if ( ! $safe['active'] || ! current_user_can( Loader::CAPABILITY ) || ! Db_Store::installed() ) {
			return;
		}

		$key = self::SAFE_TRANSIENT . get_current_user_id() . '_' . $safe['reason'];
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		self::editor()->log_safe_mode( $safe['reason'] );
	}

	/* ============================================================== */
	/*  Drafts and notices                                             */
	/* ============================================================== */

	/**
	 * The user's drafts (expired ones dropped).
	 *
	 * @param int $user_id User.
	 * @return array id => draft.
	 */
	public static function drafts( $user_id ) {
		$all = get_user_meta( (int) $user_id, self::DRAFTS_META, true );
		$all = is_array( $all ) ? $all : array();
		$now = time();

		foreach ( $all as $id => $draft ) {
			if ( ! is_array( $draft ) || ! isset( $draft['time'] ) || $now - (int) $draft['time'] > self::DRAFT_SECONDS ) {
				unset( $all[ $id ] );
			}
		}

		return $all;
	}

	/**
	 * One draft.
	 *
	 * @param int $user_id User.
	 * @param int $id      Snippet id (0 = new).
	 * @return array|null
	 */
	public static function draft( $user_id, $id ) {
		$all = self::drafts( $user_id );
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Keep what was typed.
	 *
	 * @param int    $user_id User.
	 * @param int    $id      Snippet id (0 = new).
	 * @param array  $fields  Fields as typed.
	 * @param string $state   testing|refused.
	 * @param string $message Why it was refused.
	 * @param array  $errors  field => text.
	 * @param int    $version The version a test run was for.
	 */
	public static function keep_draft( $user_id, $id, array $fields, $state, $message, array $errors, $version = 0 ) {
		if ( (int) $user_id <= 0 ) {
			return;
		}

		$all              = self::drafts( $user_id );
		$all[ (int) $id ] = array(
			'id'      => (int) $id,
			'state'   => (string) $state,
			'fields'  => array_intersect_key( $fields, array_flip( array( 'slug', 'name', 'description', 'type', 'scope', 'priority', 'active', 'code', 'base_version' ) ) ),
			'message' => (string) $message,
			'errors'  => $errors,
			'version' => (int) $version,
			'time'    => time(),
		);

		update_user_meta( (int) $user_id, self::DRAFTS_META, $all );
	}

	/**
	 * Forget a draft.
	 *
	 * @param int $user_id User.
	 * @param int $id      Snippet id.
	 */
	public static function drop_draft( $user_id, $id ) {
		$all = self::drafts( $user_id );
		if ( ! array_key_exists( (int) $id, $all ) ) {
			return;
		}
		unset( $all[ (int) $id ] );

		if ( empty( $all ) ) {
			delete_user_meta( (int) $user_id, self::DRAFTS_META );
		} else {
			update_user_meta( (int) $user_id, self::DRAFTS_META, $all );
		}
	}

	/**
	 * Drafts still "testing" belong to a save whose test run never came
	 * back (a compile-stage fatal ended it): log each as test_failed now,
	 * and keep it as a refused draft with the reason.
	 *
	 * @param int $user_id User.
	 * @return int How many were settled.
	 */
	public function settle_drafts( $user_id ) {
		$n = 0;
		foreach ( self::drafts( $user_id ) as $id => $draft ) {
			if ( 'testing' !== $draft['state'] ) {
				continue;
			}
			$message = self::editor()->settle_dead_test( $draft );
			self::keep_draft( $user_id, (int) $id, $draft['fields'], 'refused', $message, array( 'code' => $message ), (int) $draft['version'] );
			$n++;
		}
		return $n;
	}

	/**
	 * A one-shot notice for the next Snippets page view.
	 *
	 * @param string $kind ok|info|error.
	 * @param string $text Text.
	 */
	public static function notice( $kind, $text ) {
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), array( 'kind' => (string) $kind, 'text' => (string) $text ), 300 );
	}

	/**
	 * Take the pending notice.
	 *
	 * @return array|null { kind, text }
	 */
	public static function take_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $notice ) && isset( $notice['kind'], $notice['text'] ) ? $notice : null;
	}

	/* ============================================================== */
	/*  Status                                                         */
	/* ============================================================== */

	/**
	 * A database snippet's status line — PURE given its inputs.
	 *
	 * @param array $row    Table row.
	 * @param array $merged Loader-style merged map (files + active database snippets).
	 * @param array $index  Catalog index.
	 * @param array $safe   Loader::safe_mode().
	 * @return array{key: string, label: string, on: bool, locked: bool}
	 */
	public static function db_status( array $row, array $merged, array $index, array $safe ) {
		$on   = ! empty( $row['active'] );
		$file = Db_Snippets::superseded_by( (string) $row['slug'], $index );
		$key  = Db_Snippets::key( (string) $row['slug'] );

		if ( '' !== $file ) {
			return array(
				'key'    => 'superseded',
				/* translators: %s: file name */
				'label'  => sprintf( __( 'Superseded by the GitHub file %s — this copy never runs. Switch the file on under Files, then delete this copy.', 'fishotel-misc-plugin' ), $file ),
				'on'     => $on,
				'locked' => true,
			);
		}

		if ( ! $on ) {
			return array(
				'key'    => 'off',
				'label'  => __( 'Off', 'fishotel-misc-plugin' ),
				'on'     => false,
				'locked' => false,
			);
		}

		if ( ! empty( $safe['active'] ) ) {
			return array(
				'key'    => 'safe',
				'label'  => __( 'On, but safe mode is on: nothing runs', 'fishotel-misc-plugin' ),
				'on'     => true,
				'locked' => false,
			);
		}

		if ( ! isset( $merged[ $key ] ) ) {
			return array(
				'key'    => 'pending',
				'label'  => __( 'On in the table, but not in the list the site runs (a write did not finish) — switch it off and on again.', 'fishotel-misc-plugin' ),
				'on'     => true,
				'locked' => false,
			);
		}

		if ( '' !== (string) $merged[ $key ]['refused'] ) {
			return array(
				'key'    => 'refused',
				'label'  => __( 'Refused', 'fishotel-misc-plugin' ) . ' — ' . $merged[ $key ]['refused'],
				'on'     => true,
				'locked' => false,
			);
		}

		$label = 'html' === $row['type'] && 'shortcode' === $row['scope']
			/* translators: %s: the shortcode */
			? sprintf( __( 'On — renders where %s is placed', 'fishotel-misc-plugin' ), '[fh_snippet slug="' . $row['slug'] . '"]' )
			: __( 'On', 'fishotel-misc-plugin' ) . ' — ' . Admin::scope_label( (string) $row['type'], (string) $row['scope'] );

		return array(
			'key'    => 'on',
			'label'  => $label,
			'on'     => true,
			'locked' => false,
		);
	}

	/**
	 * A user's login for display ("#id" when unknown).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function who( $user_id ) {
		$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;
		return is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '#' . (int) $user_id;
	}

	/**
	 * A stored UTC time in the site's zone.
	 *
	 * @param string|null $utc Y-m-d H:i:s.
	 * @return string
	 */
	public static function when( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}
		return function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( (string) $utc, 'Y-m-d H:i' ) : (string) $utc;
	}

	/* ============================================================== */
	/*  Pages                                                          */
	/* ============================================================== */

	/**
	 * Render a tab of the Snippets page (Admin::render_page() routes here
	 * for mine / log; it checked manage_options).
	 *
	 * @param string $tab mine|log.
	 */
	public function render( $tab ) {
		$fh_tab       = 'log' === $tab ? 'log' : 'mine';
		$perm         = Editor::permission_now();
		$installed    = Db_Store::installed();
		$uid          = get_current_user_id();
		$who          = Editor::who_now();
		$safe         = Loader::safe_mode();
		$nonce_action = Editor::NONCE;
		$nonce_field  = Editor::NONCE_FIELD;
		$post_url     = self::post_url();

		if ( $installed ) {
			$this->settle_drafts( $uid );
		}
		$notice = self::take_notice();

		if ( ! $installed ) {
			include Site_Snippets::path() . 'views/editor-missing.php';
			return;
		}

		$store = self::store();

		if ( 'log' === $fh_tab ) {
			$filters = array(
				'action' => isset( $_GET['log_action'] ) ? sanitize_key( wp_unslash( $_GET['log_action'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read filter.
				'slug'   => isset( $_GET['log_slug'] ) ? sanitize_key( wp_unslash( $_GET['log_slug'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read filter.
			);
			if ( ! in_array( $filters['action'], Editor_Rules::ACTIONS, true ) ) {
				$filters['action'] = '';
			}
			$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read filter.
			$total    = $store->log_count( $filters );
			$log_rows = $store->log_rows( $filters, self::LOG_PER_PAGE, ( $paged - 1 ) * self::LOG_PER_PAGE );
			$all      = $store->all();
			$existing = array();
			foreach ( is_array( $all ) ? $all : array() as $row ) {
				$existing[ (int) $row['id'] ] = true;
			}
			$csv_url = add_query_arg(
				array(
					'action'   => Editor::CSV_ACTION,
					'_wpnonce' => wp_create_nonce( self::CSV_NONCE ),
				),
				admin_url( 'admin-post.php' )
			);
			$pages   = max( 1, (int) ceil( $total / self::LOG_PER_PAGE ) );
			include Site_Snippets::path() . 'views/editor-log.php';
			return;
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen to show.
		$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which snippet to show.

		if ( 'new' === $view || 'edit' === $view ) {
			$row = $id > 0 ? $store->get( $id ) : null;
			if ( 'edit' === $view && ! is_array( $row ) ) {
				$notice = array(
					'kind' => 'error',
					'text' => __( 'That snippet does not exist (deleted?). The Log tab can restore a deleted snippet.', 'fishotel-misc-plugin' ),
				);
				$view   = 'list';
			} else {
				$id        = is_array( $row ) ? (int) $row['id'] : 0;
				$draft     = self::draft( $uid, $id );
				$fields    = self::form_fields( $row, $draft );
				$errors    = is_array( $draft ) && isset( $draft['errors'] ) && is_array( $draft['errors'] ) ? $draft['errors'] : array();
				$index     = Catalog::get( null, true );
				$file      = is_array( $row ) ? Db_Snippets::superseded_by( $row['slug'], $index ) : '';
				$editors   = Editor_Rules::parse_editors( null === $who['constant'] ? '' : $who['constant'] );
				$scopes    = Header::SCOPES;
				$last_error = is_array( $row ) ? self::stored_error( (string) $row['slug'] ) : null;
				include Site_Snippets::path() . 'views/editor-edit.php';
				return;
			}
		}

		if ( 'history' === $view ) {
			$row       = $id > 0 ? $store->get( $id ) : null;
			$revisions = $id > 0 ? $store->revisions( $id ) : array();
			if ( empty( $revisions ) ) {
				$notice = array(
					'kind' => 'error',
					'text' => __( 'No history for that snippet.', 'fishotel-misc-plugin' ),
				);
			} else {
				$history = self::history_rows( $revisions );
				$rev_id  = isset( $_GET['rev'] ) ? absint( wp_unslash( $_GET['rev'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which version to compare.
				$diff    = null;
				// "Current" is the row; for a deleted snippet, its newest version.
				$current = is_array( $row )
					? array(
						'meta'    => $row,
						'code'    => (string) $row['code'],
						'version' => (int) $row['version'],
					)
					: array(
						'meta'    => (array) json_decode( (string) $revisions[0]['meta'], true ),
						'code'    => (string) $revisions[0]['code'],
						'version' => (int) $revisions[0]['version'],
					);
				foreach ( $revisions as $rev ) {
					if ( (int) $rev['id'] === $rev_id ) {
						$diff = array(
							'rev'     => $rev,
							'summary' => Editor_Rules::change_summary( (array) json_decode( (string) $rev['meta'], true ), (string) $rev['code'], $current['meta'], $current['code'] ),
							'html'    => self::diff_html( (string) $rev['code'], $current['code'], (int) $rev['version'], $current['version'] ),
						);
					}
				}
				include Site_Snippets::path() . 'views/editor-history.php';
				return;
			}
		}

		$rows   = $store->all();
		$index  = Catalog::get( null, true );
		$merged = Db_Snippets::merge( $index, Loader::db_active() );
		$list   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[] = array(
				'row'    => $row,
				'status' => self::db_status( $row, $merged, $index, $safe ),
				'error'  => self::stored_error( (string) $row['slug'] ),
				'draft'  => self::draft( $uid, (int) $row['id'] ),
			);
		}
		$read_error = ! is_array( $rows );
		$new_draft  = self::draft( $uid, 0 );
		include Site_Snippets::path() . 'views/editor-list.php';
	}

	/**
	 * The edit form's values: the draft (what was typed) when there is
	 * one, else the row, else the defaults of a new PHP snippet.
	 *
	 * @param array|null $row   Row.
	 * @param array|null $draft Draft.
	 * @return array
	 */
	public static function form_fields( $row, $draft ) {
		$fields = array(
			'slug'         => '',
			'name'         => '',
			'description'  => '',
			'type'         => 'php',
			'scope'        => 'global',
			'priority'     => '10',
			'active'       => '',
			'code'         => '',
			'base_version' => '',
		);

		if ( is_array( $row ) ) {
			foreach ( array( 'slug', 'name', 'description', 'type', 'scope', 'code' ) as $key ) {
				$fields[ $key ] = (string) $row[ $key ];
			}
			$fields['priority']     = (string) (int) $row['priority'];
			$fields['active']       = empty( $row['active'] ) ? '' : '1';
			$fields['base_version'] = (string) (int) $row['version'];
		}

		if ( is_array( $draft ) && isset( $draft['fields'] ) && is_array( $draft['fields'] ) ) {
			foreach ( $draft['fields'] as $key => $value ) {
				if ( array_key_exists( $key, $fields ) && is_scalar( $value ) && ( 'base_version' !== $key || '' !== (string) $value ) ) {
					$fields[ $key ] = (string) $value;
				}
			}
			if ( is_array( $row ) ) {
				$fields['slug'] = (string) $row['slug'];
			}
		}

		return $fields;
	}

	/**
	 * History rows: each revision with who, when and what changed against
	 * the version before it.
	 *
	 * @param array $revisions Newest first.
	 * @return array[]
	 */
	public static function history_rows( array $revisions ) {
		$out   = array();
		$count = count( $revisions );

		for ( $i = 0; $i < $count; $i++ ) {
			$rev  = $revisions[ $i ];
			$prev = $i + 1 < $count ? $revisions[ $i + 1 ] : null;
			$meta = json_decode( (string) $rev['meta'], true );
			$meta = is_array( $meta ) ? $meta : array();

			$out[] = array(
				'rev'     => $rev,
				'meta'    => $meta,
				'who'     => self::who( (int) $rev['user_id'] ),
				'when'    => self::when( $rev['created_at'] ),
				'summary' => is_array( $prev )
					? Editor_Rules::change_summary( json_decode( (string) $prev['meta'], true ), (string) $prev['code'], $meta, (string) $rev['code'] )
					: Editor_Rules::change_summary( null, null, $meta, (string) $rev['code'] ),
			);
		}

		return $out;
	}

	/**
	 * wp_text_diff() of a version against the current code ('' when equal).
	 *
	 * @param string $old     Old code.
	 * @param string $new     Current code.
	 * @param int    $old_v   Old version.
	 * @param int    $new_v   Current version.
	 * @return string Escaped HTML table from wp_text_diff().
	 */
	public static function diff_html( $old, $new, $old_v, $new_v ) {
		if ( ! function_exists( 'wp_text_diff' ) ) {
			return '';
		}

		return (string) wp_text_diff(
			$old,
			$new,
			array(
				/* translators: %d: version */
				'title_left'  => sprintf( __( 'Version %d', 'fishotel-misc-plugin' ), (int) $old_v ),
				/* translators: %d: version */
				'title_right' => sprintf( __( 'Current (version %d)', 'fishotel-misc-plugin' ), (int) $new_v ),
			)
		);
	}

	/**
	 * The stored newest error of a database snippet.
	 *
	 * @param string $slug Slug.
	 * @return array|null { time, message }
	 */
	private static function stored_error( $slug ) {
		$errors = Loader::stored_errors();
		$key    = Db_Snippets::key( $slug );
		return isset( $errors[ $key ] ) && is_array( $errors[ $key ] ) ? $errors[ $key ] : null;
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * The editor's script, and the code editor on the edit screen.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Site_Snippets::MENU_SLUG ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen.
		if ( 'mine' !== $tab ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen.

		$code_editor = false;
		if ( 'edit' === $view || 'new' === $view ) {
			$type = 'php';
			$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which snippet.
			if ( $id > 0 && Db_Store::installed() ) {
				$row  = self::store()->get( $id );
				$type = is_array( $row ) ? (string) $row['type'] : 'php';
			}
			$mimes       = array(
				'php'  => 'text/x-php',
				'css'  => 'text/css',
				'js'   => 'text/javascript',
				'html' => 'text/html',
			);
			// False when the user ticked "Disable syntax highlighting" in
			// their profile: the plain textarea stays.
			$code_editor = wp_enqueue_code_editor(
				array(
					'type'       => isset( $mimes[ $type ] ) ? $mimes[ $type ] : 'text/x-php',
					'codemirror' => array(
						'indentUnit'     => 4,
						'indentWithTabs' => true,
						'lineWrapping'   => false,
					),
				)
			);
		}

		wp_enqueue_script(
			'fishotel-snippets-editor',
			Site_Snippets::url() . 'js/snippets-editor.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		$ajax = admin_url( 'admin-ajax.php' );
		if ( 'query' === Loader::safe_mode()['reason'] ) {
			$ajax = add_query_arg( Loader::SAFE_MODE_QUERY, '1', $ajax );
		}

		wp_localize_script(
			'fishotel-snippets-editor',
			'fhSnippetsEditor',
			array(
				'ajaxUrl'    => $ajax,
				'action'     => Editor::TOGGLE_ACTION,
				'nonceField' => Editor::NONCE_FIELD,
				'nonce'      => wp_create_nonce( Editor::NONCE ),
				'codeEditor' => $code_editor,
				'i18n'       => array(
					'saving'   => __( 'Saving…', 'fishotel-misc-plugin' ),
					'error'    => __( 'Something went wrong — the test run may have hit a fatal error. Nothing was switched; reload the page.', 'fishotel-misc-plugin' ),
					'leave'    => __( 'You have unsaved changes.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}
}
