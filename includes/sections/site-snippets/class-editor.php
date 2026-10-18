<?php
/**
 * Editor — the save pipeline of the wp-admin snippet editor, with the store
 * as a parameter (Db_Store on the site, an in-memory twin in the tests).
 *
 * Every write follows the same order:
 *
 *   1. validate the fields (Editor_Rules::validate) — the slug rules of a
 *      file, the scope of the type, the priority, the size;
 *   2. PHP: parse check (token_get_all TOKEN_PARSE), then the clash scan
 *      against every file snippet and every other database snippet;
 *   3. when the snippet is or becomes active (and no file supersedes it),
 *      the PHP is TEST-RUN ONCE in this request (Loader::test_run(), as the
 *      Snippets page switch does for a file): a throw refuses the save with
 *      the message and is logged as test_failed; a compile-stage fatal ends
 *      only this request — nothing has been written yet;
 *   4. only then, in one transaction: the row (version + 1, optimistic
 *      lock on the version the editor read), one revision, one audit log
 *      row; then the active option is rebuilt and the entry goes to the
 *      Health log through fishotel_log.
 *
 * A refused write writes nothing but its log row (test_failed / denied);
 * the admin keeps the typed code as a draft so nothing is lost.
 *
 * Results are arrays: ok, code (a short reason key), message, errors
 * (field => text), id, version.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Editor
 */
class Editor {

	/**
	 * admin-post / AJAX actions, the nonce and its field.
	 */
	const SAVE_ACTION     = 'fishotel_snippets_save';
	const RESTORE_ACTION  = 'fishotel_snippets_restore';
	const DELETE_ACTION   = 'fishotel_snippets_delete';
	const UNDELETE_ACTION = 'fishotel_snippets_undelete';
	const EXPORT_ACTION   = 'fishotel_snippets_export';
	const CSV_ACTION      = 'fishotel_snippets_log_csv';
	const TOGGLE_ACTION   = 'fishotel_snippets_db_toggle';
	const NONCE           = 'fishotel_snippets_editor';
	const NONCE_FIELD     = '_fh_snippets_nonce';

	/**
	 * The store.
	 *
	 * @var Db_Store|object
	 */
	private $store;

	/**
	 * Dependencies.
	 *
	 * @var array
	 */
	private $deps;

	/**
	 * Constructor.
	 *
	 * @param Db_Store|object $store The store (or an object with the same methods).
	 * @param array           $deps  {
	 *     @type callable $files        (): the Catalog index (fresh).
	 *     @type callable $test_run     ( array $entry ): '' or why it failed.
	 *     @type array    $actor        user_id, user_login, remote_addr, forwarded_for, user_agent.
	 *     @type callable $now          (): UTC 'Y-m-d H:i:s'.
	 *     @type callable $emit         ( $level, $message, $context ): the Health log.
	 *     @type callable $write_active ( array $map ): store the active option.
	 *     @type callable $before_test  ( array $fields, int $id ): right before a test run.
	 * }
	 */
	public function __construct( $store, array $deps = array() ) {
		$this->store = $store;
		$this->deps  = array_merge(
			array(
				'files'        => null,
				'test_run'     => null,
				'actor'        => array(),
				'now'          => null,
				'emit'         => null,
				'write_active' => null,
				'before_test'  => null,
			),
			$deps
		);
	}

	/* ============================================================== */
	/*  Who                                                            */
	/* ============================================================== */

	/**
	 * Editor_Rules::permission()'s inputs for the current request.
	 *
	 * @return array
	 */
	public static function who_now() {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$id   = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
		$ms   = function_exists( 'is_multisite' ) && is_multisite();

		return array(
			'constant'           => defined( Editor_Rules::EDITORS_CONSTANT ) ? (string) constant( Editor_Rules::EDITORS_CONSTANT ) : null,
			'user_id'            => $id,
			'user_login'         => $id > 0 && isset( $user->user_login ) ? (string) $user->user_login : '',
			'can_manage_options' => $id > 0 && current_user_can( 'manage_options' ),
			'multisite'          => $ms,
			'super_admin'        => $ms && $id > 0 && function_exists( 'is_super_admin' ) && is_super_admin( $id ),
			'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
		);
	}

	/**
	 * The permission decision for the current request.
	 *
	 * @return array{allowed: bool, reason: string, message: string}
	 */
	public static function permission_now() {
		return Editor_Rules::permission( self::who_now() );
	}

	/**
	 * The actor fields of a log row for the current request.
	 *
	 * @return array
	 */
	public static function actor_now() {
		$who = self::who_now();

		return array_merge(
			array(
				'user_id'    => $who['user_id'],
				'user_login' => $who['user_login'],
			),
			Editor_Rules::client( $_SERVER ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated / cut / stripped in client().
		);
	}

	/* ============================================================== */
	/*  Writes                                                         */
	/* ============================================================== */

	/**
	 * Create ($id 0) or update a snippet from the editor form.
	 *
	 * @param array $input Raw fields (+ base_version when editing).
	 * @param int   $id    Snippet id, 0 for a new one.
	 * @return array
	 */
	public function save( array $input, $id = 0 ) {
		$id     = (int) $id;
		$before = null;

		if ( $id > 0 ) {
			$before = $this->store->get( $id );
			if ( ! is_array( $before ) ) {
				return self::fail( 'not_found', 'Not saved: that snippet no longer exists (deleted meanwhile?). Your code is kept below.' );
			}
		}

		$check = Editor_Rules::validate( $input, $before );
		if ( ! $check['ok'] ) {
			return self::fail( 'invalid', 'Not saved — fix the fields marked below.', $check['errors'] );
		}
		$fields = $check['fields'];

		if ( is_array( $before ) && isset( $input['base_version'] ) && '' !== (string) $input['base_version'] && (int) $input['base_version'] !== (int) $before['version'] ) {
			return self::fail( 'conflict', 'Not saved: this snippet was changed since you opened it (it is now version ' . (int) $before['version'] . '). Your code is kept below — copy what you need, reload the snippet and merge.' );
		}

		if ( ! is_array( $before ) ) {
			$refusal = $this->slug_refusal( $fields['slug'] );
			if ( '' !== $refusal ) {
				return self::fail( 'slug', $refusal, array( 'slug' => $refusal ) );
			}
		} elseif ( self::same( $before, $fields ) ) {
			return self::done( 'unchanged', 'Nothing changed, so nothing was saved.', $id, (int) $before['version'] );
		}

		$action = 'create';
		if ( is_array( $before ) ) {
			$action = self::meta_and_code_equal( $before, $fields, true ) ? ( $fields['active'] ? 'activate' : 'deactivate' ) : 'update';
		}

		return $this->commit( $before, $fields, $action );
	}

	/**
	 * Switch a snippet on or off (the list's switch). On = the same checks
	 * and test run as a save.
	 *
	 * @param int  $id Snippet id.
	 * @param bool $on On?
	 * @return array
	 */
	public function set_active( $id, $on ) {
		$before = $this->store->get( (int) $id );
		if ( ! is_array( $before ) ) {
			return self::fail( 'not_found', 'That snippet no longer exists.' );
		}

		if ( (bool) $before['active'] === (bool) $on ) {
			return self::done( 'unchanged', $on ? 'It is already on.' : 'It is already off.', (int) $id, (int) $before['version'] );
		}

		if ( $on ) {
			$file = Db_Snippets::superseded_by( $before['slug'], $this->files() );
			if ( '' !== $file ) {
				return self::fail( 'superseded', 'Refused: the GitHub file ' . $file . ' supersedes this copy, so switching it on would change nothing. Switch the file on under Files, and delete this copy.' );
			}
		}

		$check = Editor_Rules::validate( array_merge( $before, array( 'active' => $on ? 1 : 0 ) ), $before );
		if ( ! $check['ok'] ) {
			return self::fail( 'invalid', 'Refused: the stored snippet no longer validates — ' . implode( ' ', $check['errors'] ) );
		}

		return $this->commit( $before, $check['fields'], $on ? 'activate' : 'deactivate' );
	}

	/**
	 * Restore a revision's code and fields as a new version (the snippet
	 * keeps its current on / off state; on = test-run like a save).
	 *
	 * @param int        $id           Snippet id.
	 * @param int        $revision_id  Revision id.
	 * @param int|string $base_version The version the history page showed ('' = skip the check).
	 * @return array
	 */
	public function restore_revision( $id, $revision_id, $base_version = '' ) {
		$before = $this->store->get( (int) $id );
		$rev    = $this->store->revision( (int) $revision_id );

		if ( ! is_array( $before ) ) {
			return self::fail( 'not_found', 'That snippet no longer exists — restore it from the Log tab instead.' );
		}
		if ( ! is_array( $rev ) || (int) $rev['snippet_id'] !== (int) $before['id'] ) {
			return self::fail( 'no_revision', 'That version does not belong to this snippet.' );
		}
		if ( '' !== (string) $base_version && (int) $base_version !== (int) $before['version'] ) {
			return self::fail( 'conflict', 'Not restored: the snippet changed since the history page was opened (it is now version ' . (int) $before['version'] . '). Reload and try again.' );
		}

		$meta  = self::decode_meta( $rev );
		$check = Editor_Rules::validate(
			array_merge( $meta, array( 'active' => $before['active'], 'code' => (string) $rev['code'] ) ),
			$before
		);
		if ( ! $check['ok'] ) {
			return self::fail( 'invalid', 'Not restored: version ' . (int) $rev['version'] . ' no longer validates — ' . implode( ' ', $check['errors'] ) );
		}
		if ( self::same( $before, $check['fields'] ) ) {
			return self::done( 'unchanged', 'Version ' . (int) $rev['version'] . ' is the same as the current one; nothing to restore.', (int) $id, (int) $before['version'] );
		}

		return $this->commit( $before, $check['fields'], 'restore', array( 'from_version' => (int) $rev['version'] ) );
	}

	/**
	 * Delete a snippet: the row goes, its revisions and log rows stay, the
	 * delete is logged with the last code's hash.
	 *
	 * @param int $id Snippet id.
	 * @return array
	 */
	public function delete( $id ) {
		$before = $this->store->get( (int) $id );
		if ( ! is_array( $before ) ) {
			return self::fail( 'not_found', 'That snippet no longer exists.' );
		}

		$this->store->begin();
		try {
			if ( ! $this->store->delete( (int) $id ) ) {
				throw new \RuntimeException( 'the snippet row could not be deleted' );
			}
			$summary = Editor_Rules::audit_summary(
				'delete',
				array(
					'version' => (int) $before['version'],
					'before'  => $before,
				)
			);
			if ( ! $this->store->append_log( $this->log_row( 'delete', (int) $id, $before['slug'], $summary ) ) ) {
				throw new \RuntimeException( 'the log entry could not be written' );
			}
			$this->store->commit();
		} catch ( \Throwable $e ) {
			$this->store->rollback();
			return self::fail( 'db', 'Not deleted: the database refused the write (' . $e->getMessage() . ').' );
		}

		$this->rebuild_active();
		$this->emit( 'warning', 'delete', $before['slug'], (int) $id, $summary );

		return self::done( 'deleted', 'Deleted "' . $before['name'] . '". Its history and log are kept; the Log tab can restore it.', (int) $id, (int) $before['version'] );
	}

	/**
	 * Restore a deleted snippet from its last revision (the Log tab's
	 * button on a delete row). It comes back switched OFF, under its old
	 * id and slug, as a new version; switching it on test-runs it.
	 *
	 * @param int $log_id The delete row's id.
	 * @return array
	 */
	public function restore_deleted( $log_id ) {
		$log = $this->store->log_entry( (int) $log_id );
		if ( ! is_array( $log ) || 'delete' !== $log['action'] || empty( $log['snippet_id'] ) ) {
			return self::fail( 'no_delete', 'That log row is not a delete.' );
		}

		$snippet_id = (int) $log['snippet_id'];
		if ( is_array( $this->store->get( $snippet_id ) ) ) {
			return self::fail( 'exists', 'That snippet is not deleted any more (it is under My snippets).' );
		}

		$rev = $this->store->last_revision( $snippet_id );
		if ( ! is_array( $rev ) ) {
			return self::fail( 'no_revision', 'No saved version of that snippet is left to restore.' );
		}

		$refusal = $this->slug_refusal( (string) $log['slug'] );
		if ( '' !== $refusal ) {
			return self::fail( 'slug', 'Not restored: ' . $refusal );
		}

		$check = Editor_Rules::validate(
			array_merge(
				self::decode_meta( $rev ),
				array(
					'slug'   => (string) $log['slug'],
					'active' => 0,
					'code'   => (string) $rev['code'],
				)
			),
			null
		);
		if ( ! $check['ok'] ) {
			return self::fail( 'invalid', 'Not restored: its last version no longer validates — ' . implode( ' ', $check['errors'] ) );
		}

		return $this->commit(
			null,
			$check['fields'],
			'restore',
			array(
				'insert_id'    => $snippet_id,
				'version'      => (int) $rev['version'] + 1,
				'from_version' => (int) $rev['version'],
			)
		);
	}

	/**
	 * Export a snippet as a file: the file's text, exported_at set, logged.
	 *
	 * @param int    $id   Snippet id.
	 * @param string $date Y-m-d for the Origin line.
	 * @return array + filename, file.
	 */
	public function export( $id, $date ) {
		$row = $this->store->get( (int) $id );
		if ( ! is_array( $row ) ) {
			return self::fail( 'not_found', 'That snippet no longer exists.' );
		}

		$actor    = $this->actor();
		$filename = Export::filename( $row );
		$file     = Export::build( $row, $actor['user_login'], (string) $date );
		$summary  = Editor_Rules::audit_summary(
			'export',
			array(
				'version' => (int) $row['version'],
				'after'   => $row,
				'file'    => $filename,
			)
		);

		$this->store->begin();
		try {
			if ( ! $this->store->set_exported( (int) $id, $this->now() ) ) {
				throw new \RuntimeException( 'exported_at could not be written' );
			}
			if ( ! $this->store->append_log( $this->log_row( 'export', (int) $id, $row['slug'], $summary ) ) ) {
				throw new \RuntimeException( 'the log entry could not be written' );
			}
			$this->store->commit();
		} catch ( \Throwable $e ) {
			$this->store->rollback();
			return self::fail( 'db', 'Not exported: the database refused the write (' . $e->getMessage() . ').' );
		}

		$this->emit( 'info', 'export', $row['slug'], (int) $id, $summary );

		$out             = self::done( 'exported', 'Exported ' . $filename . '.', (int) $id, (int) $row['version'] );
		$out['filename'] = $filename;
		$out['file']     = $file;

		return $out;
	}

	/**
	 * Log a refused write ('denied').
	 *
	 * @param string $attempt    What was tried (save, delete, …).
	 * @param string $slug       Slug when known.
	 * @param int    $snippet_id Snippet id when known.
	 * @param string $reason     Why it was refused.
	 * @return bool Whether the row was written.
	 */
	public function deny( $attempt, $slug, $snippet_id, $reason ) {
		$summary = Editor_Rules::audit_summary(
			'denied',
			array(
				'attempt' => (string) $attempt,
				'message' => (string) $reason,
			)
		);

		$ok = (bool) $this->store->append_log( $this->log_row( 'denied', (int) $snippet_id, (string) $slug, $summary ) );
		$this->emit( 'warning', 'denied', (string) $slug, (int) $snippet_id, $summary );

		return $ok;
	}

	/**
	 * Log safe mode in use ('safe_mode').
	 *
	 * @param string $reason constant|query.
	 * @return bool
	 */
	public function log_safe_mode( $reason ) {
		$summary = Editor_Rules::audit_summary( 'safe_mode', array( 'reason' => (string) $reason ) );
		$ok      = (bool) $this->store->append_log( $this->log_row( 'safe_mode', 0, '', $summary ) );
		$this->emit( 'warning', 'safe_mode', '', 0, $summary );
		return $ok;
	}

	/**
	 * A save whose test run never came back (a compile-stage fatal ended
	 * that request): log it as test_failed now, from the draft the save
	 * kept. Returns the message to show.
	 *
	 * @param array $draft Draft: id, fields, time.
	 * @return string
	 */
	public function settle_dead_test( array $draft ) {
		$fields  = isset( $draft['fields'] ) && is_array( $draft['fields'] ) ? $draft['fields'] : array();
		$id      = isset( $draft['id'] ) ? (int) $draft['id'] : 0;
		$message = 'the test run ended the save request with a fatal error PHP cannot catch (a compile-stage error such as a parameter named twice, or a memory / time limit); nothing was saved';
		$summary = Editor_Rules::audit_summary(
			'test_failed',
			array(
				'version' => isset( $draft['version'] ) ? (int) $draft['version'] : 0,
				'after'   => $fields,
				'message' => $message,
			)
		);
		$slug    = isset( $fields['slug'] ) ? (string) $fields['slug'] : '';

		$this->store->append_log( $this->log_row( 'test_failed', $id, $slug, $summary ) );
		$this->emit( 'warning', 'test_failed', $slug, $id, $summary );

		return 'Refused: ' . $message . '. Your code is kept below — fix it and save again.';
	}

	/**
	 * Rebuild the active option from the table. Skipped when the table
	 * cannot be read: an error must never empty the option (that would
	 * stop every database snippet).
	 *
	 * @return array|null Active option result, or null when skipped.
	 */
	public function rebuild_active() {
		$rows = $this->store->all();
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$built = Db_Snippets::active_option( $rows );
		if ( is_callable( $this->deps['write_active'] ) ) {
			call_user_func( $this->deps['write_active'], $built['map'] );
		}

		return $built;
	}

	/* ============================================================== */
	/*  The pipeline                                                   */
	/* ============================================================== */

	/**
	 * Checks, test run, then the write (row + revision + log), the active
	 * option and the Health log.
	 *
	 * @param array|null $before Row before (null = new or restored-deleted).
	 * @param array      $fields Validated fields.
	 * @param string     $action create|update|activate|deactivate|restore.
	 * @param array      $opts   insert_id, version, from_version.
	 * @return array
	 */
	private function commit( $before, array $fields, $action, array $opts = array() ) {
		$files    = $this->files();
		$file_map = isset( $files['snippets'] ) && is_array( $files['snippets'] ) ? $files['snippets'] : array();
		$slug     = $fields['slug'];
		$id       = is_array( $before ) ? (int) $before['id'] : ( isset( $opts['insert_id'] ) ? (int) $opts['insert_id'] : 0 );
		$version  = is_array( $before ) ? (int) $before['version'] + 1 : ( isset( $opts['version'] ) ? (int) $opts['version'] : 1 );
		$rows     = null;
		$declares = array();

		if ( 'php' === $fields['type'] ) {
			$syntax = Db_Snippets::syntax_error( $fields['code'] );
			if ( '' !== $syntax['error'] ) {
				$text = 'PHP syntax error on line ' . (int) $syntax['line'] . ': ' . $syntax['error'];
				return self::fail( 'syntax', 'Not saved: ' . $text . '. Your code is kept below.', array( 'code' => $text ) );
			}

			$declares = Db_Snippets::declared( $fields['code'] );
			if ( ! empty( $declares ) ) {
				$rows = $this->store->all();
				if ( ! is_array( $rows ) ) {
					return self::fail( 'db', 'Not saved: the snippet table could not be read to check for clashes. Your code is kept below.' );
				}
				$others = array();
				foreach ( $rows as $row ) {
					if ( (int) $row['id'] === $id || 'php' !== $row['type'] || '' !== Db_Snippets::superseded_by( $row['slug'], $files ) ) {
						continue;
					}
					$others[ $row['slug'] ] = Db_Snippets::declared( $row['code'] );
				}
				$clash = Editor_Rules::clash( $slug, $declares, $file_map, $others );
				if ( '' !== $clash ) {
					return self::fail( 'clash', 'Refused: ' . $clash . ' Rename it, or wrap it in if ( ! function_exists( … ) ). Your code is kept below.', array( 'code' => $clash ) );
				}
			}
		}

		$superseded = Db_Snippets::superseded_by( $slug, $files );

		if ( $fields['active'] ) {
			if ( null === $rows ) {
				$rows = $this->store->all();
			}
			if ( is_array( $rows ) ) {
				$next   = array();
				$placed = false;
				foreach ( $rows as $row ) {
					if ( (int) $row['id'] === $id ) {
						$row    = array_merge( $row, $fields );
						$placed = true;
					}
					$next[] = $row;
				}
				if ( ! $placed ) {
					$next[] = array_merge( $fields, array( 'id' => $id ) );
				}
				$size = Db_Snippets::active_option( $next );
				if ( $size['bytes'] > Editor_Rules::MAX_ACTIVE_BYTES ) {
					return self::fail( 'too_big', 'Refused: the switched-on snippets together would be ' . (int) ceil( $size['bytes'] / 1024 ) . ' KB, more than the ' . (int) ( Editor_Rules::MAX_ACTIVE_BYTES / 1024 ) . ' KB loaded on every request. Switch something off or move it to GitHub. Your code is kept below.' );
				}
			}
		}

		// The test run: PHP that will run from now on, run once here first.
		if ( $fields['active'] && 'php' === $fields['type'] && '' === $superseded ) {
			if ( is_callable( $this->deps['before_test'] ) ) {
				call_user_func( $this->deps['before_test'], array_merge( $fields, array( 'version' => $version ) ), $id );
			}

			$failed = '';
			if ( is_callable( $this->deps['test_run'] ) ) {
				$entry           = Db_Snippets::entry(
					array(
						'id'       => $id,
						'slug'     => $slug,
						'type'     => 'php',
						'scope'    => $fields['scope'],
						'priority' => $fields['priority'],
						'code'     => $fields['code'],
						'version'  => $version,
						'declares' => $declares,
					)
				);
				$failed          = (string) call_user_func( $this->deps['test_run'], $entry );
			}

			if ( '' !== $failed ) {
				$summary = Editor_Rules::audit_summary(
					'test_failed',
					array(
						'version' => $version,
						'after'   => $fields,
						'message' => $failed,
					)
				);
				$this->store->append_log( $this->log_row( 'test_failed', $id, $slug, $summary ) );
				$this->emit( 'warning', 'test_failed', $slug, $id, $summary );

				return self::fail( 'test_failed', 'Refused: the test run failed — ' . $failed . ' Nothing was saved; your code is kept below.', array( 'code' => $failed ) );
			}
		}

		$now   = $this->now();
		$actor = $this->actor();
		$row   = array_merge(
			$fields,
			array(
				'version'    => $version,
				'updated_by' => (int) $actor['user_id'],
				'updated_at' => $now,
			)
		);
		if ( ! is_array( $before ) ) {
			$row['created_by'] = (int) $actor['user_id'];
			$row['created_at'] = $now;
			if ( $id > 0 ) {
				$row['id'] = $id;
			}
		}

		$summary = Editor_Rules::audit_summary(
			$action,
			array(
				'version'      => $version,
				'from_version' => isset( $opts['from_version'] ) ? (int) $opts['from_version'] : 0,
				'before'       => $before,
				'after'        => $fields,
			)
		);

		$this->store->begin();
		try {
			if ( is_array( $before ) ) {
				if ( ! $this->store->update( $id, $row, (int) $before['version'] ) ) {
					throw new \RuntimeException( 'the snippet changed meanwhile or the row could not be written' );
				}
			} else {
				$id = (int) $this->store->insert( $row );
				if ( $id <= 0 ) {
					throw new \RuntimeException( 'the snippet row could not be written' );
				}
			}

			$rev = $this->store->add_revision(
				array(
					'snippet_id' => $id,
					'version'    => $version,
					'code'       => $fields['code'],
					'meta'       => Editor_Rules::meta_of( $fields ),
					'user_id'    => (int) $actor['user_id'],
					'created_at' => $now,
				)
			);
			if ( ! $rev ) {
				throw new \RuntimeException( 'the revision could not be written' );
			}

			if ( ! $this->store->append_log( $this->log_row( $action, $id, $slug, $summary ) ) ) {
				throw new \RuntimeException( 'the log entry could not be written' );
			}

			$this->store->commit();
		} catch ( \Throwable $e ) {
			$this->store->rollback();
			return self::fail( 'db', 'Not saved: the database refused the write (' . $e->getMessage() . '). Nothing was changed; your code is kept below.' );
		}

		$this->rebuild_active();
		$this->emit( 'info', $action, $slug, $id, $summary );

		$verbs = array(
			'create'     => 'Created',
			'update'     => 'Saved',
			'activate'   => 'Switched on',
			'deactivate' => 'Switched off',
			'restore'    => 'Restored',
		);
		$text  = ( isset( $verbs[ $action ] ) ? $verbs[ $action ] : 'Saved' ) . ' — version ' . $version . '.';
		if ( $fields['active'] && 'php' === $fields['type'] && '' === $superseded ) {
			$text .= ' The test run passed; it runs from the next request.';
		} elseif ( '' !== $superseded ) {
			$text .= ' Note: the GitHub file ' . $superseded . ' supersedes it, so this copy never runs.';
		}

		return self::done( $action, $text, $id, $version );
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * Why a new snippet cannot take this slug ('' when it can).
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private function slug_refusal( $slug ) {
		if ( is_array( $this->store->get_by_slug( $slug ) ) ) {
			return 'Another snippet here already uses the slug "' . $slug . '"; pick another.';
		}

		$file = Db_Snippets::superseded_by( $slug, $this->files() );
		if ( '' !== $file ) {
			return 'The GitHub file ' . $file . ' already uses the slug "' . $slug . '" (it would supersede this copy); pick another.';
		}

		return '';
	}

	/**
	 * Whether the fields equal the row (meta and code).
	 *
	 * @param array $row    Row.
	 * @param array $fields Fields.
	 * @return bool
	 */
	private static function same( array $row, array $fields ) {
		return self::meta_and_code_equal( $row, $fields, false );
	}

	/**
	 * Meta + code equality, optionally ignoring the on / off state.
	 *
	 * @param array $row           Row.
	 * @param array $fields        Fields.
	 * @param bool  $ignore_active Leave `active` out.
	 * @return bool
	 */
	private static function meta_and_code_equal( array $row, array $fields, $ignore_active ) {
		$a = Editor_Rules::meta_of( $row );
		$b = Editor_Rules::meta_of( $fields );
		if ( $ignore_active ) {
			unset( $a['active'], $b['active'] );
		}
		return $a === $b && (string) $row['code'] === (string) $fields['code'];
	}

	/**
	 * A revision's meta as editor input.
	 *
	 * @param array $rev Revision row.
	 * @return array
	 */
	private static function decode_meta( array $rev ) {
		$meta = is_array( $rev['meta'] ) ? $rev['meta'] : json_decode( (string) $rev['meta'], true );
		$meta = is_array( $meta ) ? $meta : array();

		return array(
			'name'        => isset( $meta['name'] ) ? (string) $meta['name'] : '',
			'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			'type'        => isset( $meta['type'] ) ? (string) $meta['type'] : '',
			'scope'       => isset( $meta['scope'] ) ? (string) $meta['scope'] : '',
			'priority'    => isset( $meta['priority'] ) ? (string) (int) $meta['priority'] : '10',
		);
	}

	/**
	 * One log row for this request's actor.
	 *
	 * @param string $action     Action.
	 * @param int    $snippet_id Snippet id (0 = none).
	 * @param string $slug       Slug.
	 * @param string $summary    Summary.
	 * @return array
	 */
	private function log_row( $action, $snippet_id, $slug, $summary ) {
		$actor = $this->actor();

		return array(
			'snippet_id'    => $snippet_id > 0 ? (int) $snippet_id : null,
			'slug'          => (string) $slug,
			'action'        => (string) $action,
			'user_id'       => (int) $actor['user_id'],
			'user_login'    => (string) $actor['user_login'],
			'remote_addr'   => (string) $actor['remote_addr'],
			'forwarded_for' => (string) $actor['forwarded_for'],
			'user_agent'    => (string) $actor['user_agent'],
			'summary'       => (string) $summary,
			'created_at'    => $this->now(),
		);
	}

	/**
	 * Hand a log entry to the Health log.
	 *
	 * @param string $level      info|warning.
	 * @param string $action     Action.
	 * @param string $slug       Slug.
	 * @param int    $snippet_id Snippet id.
	 * @param string $summary    Summary.
	 */
	private function emit( $level, $action, $slug, $snippet_id, $summary ) {
		if ( ! is_callable( $this->deps['emit'] ) ) {
			return;
		}

		$actor   = $this->actor();
		$who     = '' !== $actor['user_login'] ? $actor['user_login'] : 'user #' . (int) $actor['user_id'];
		$message = 'Snippet editor: ' . $action . ( '' !== (string) $slug ? ' ' . $slug : '' ) . ' by ' . $who . ' — ' . $summary;

		try {
			call_user_func(
				$this->deps['emit'],
				$level,
				$message,
				array(
					'action'      => (string) $action,
					'slug'        => (string) $slug,
					'snippet_id'  => (int) $snippet_id,
					'user_id'     => (int) $actor['user_id'],
					'remote_addr' => (string) $actor['remote_addr'],
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The actor fields.
	 *
	 * @return array
	 */
	private function actor() {
		return array_merge(
			array(
				'user_id'       => 0,
				'user_login'    => '',
				'remote_addr'   => '',
				'forwarded_for' => '',
				'user_agent'    => '',
			),
			(array) $this->deps['actor']
		);
	}

	/**
	 * Now, UTC.
	 *
	 * @return string
	 */
	private function now() {
		return is_callable( $this->deps['now'] ) ? (string) call_user_func( $this->deps['now'] ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * The file index.
	 *
	 * @return array
	 */
	private function files() {
		$index = is_callable( $this->deps['files'] ) ? call_user_func( $this->deps['files'] ) : array();
		return is_array( $index ) ? $index : array();
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    Reason key.
	 * @param string $message Text.
	 * @param array  $errors  field => text.
	 * @return array
	 */
	private static function fail( $code, $message, array $errors = array() ) {
		return array(
			'ok'      => false,
			'code'    => (string) $code,
			'message' => (string) $message,
			'errors'  => $errors,
			'id'      => 0,
			'version' => 0,
		);
	}

	/**
	 * A success.
	 *
	 * @param string $code    What happened.
	 * @param string $message Text.
	 * @param int    $id      Snippet id.
	 * @param int    $version Version.
	 * @return array
	 */
	private static function done( $code, $message, $id, $version ) {
		return array(
			'ok'      => true,
			'code'    => (string) $code,
			'message' => (string) $message,
			'errors'  => array(),
			'id'      => (int) $id,
			'version' => (int) $version,
		);
	}
}
