<?php
/**
 * Editor rules — PURE decisions for the wp-admin snippet editor: who may
 * write, what a valid snippet is, how the stored code is normalised,
 * whether its declarations clash, and the text the history and the audit
 * log keep (never the code itself — hashes and line counts).
 *
 * Who may write (permission()): ONLY a user named in the wp-config constant
 * FISHOTEL_SNIPPETS_EDITORS (comma-separated logins or user IDs; an entry
 * made of digits is a user ID) who can also manage_options (be a super
 * admin on multisite), and never while DISALLOW_FILE_EDIT is true. Without
 * the constant nobody can write. The decision takes plain values so the
 * tests run without WordPress; Editor::who_now() fills them in.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Editor_Rules
 */
class Editor_Rules {

	/**
	 * The wp-config constant naming the editors.
	 *
	 * @var string
	 */
	const EDITORS_CONSTANT = 'FISHOTEL_SNIPPETS_EDITORS';

	/**
	 * Largest stored code of one snippet, and of every active one together
	 * (the active option is autoloaded on every request).
	 */
	const MAX_CODE_BYTES   = 65536;
	const MAX_ACTIVE_BYTES = 262144;

	/**
	 * Audit log actions.
	 *
	 * @var string[]
	 */
	const ACTIONS = array( 'create', 'update', 'activate', 'deactivate', 'delete', 'restore', 'export', 'test_failed', 'denied', 'safe_mode' );

	/**
	 * The fields a revision's meta keeps (and a change summary compares).
	 *
	 * @var string[]
	 */
	const META_FIELDS = array( 'name', 'description', 'type', 'scope', 'priority', 'active' );

	/**
	 * Longest summary kept in a log row.
	 *
	 * @var int
	 */
	const MAX_SUMMARY = 2000;

	/* ============================================================== */
	/*  Who may write                                                  */
	/* ============================================================== */

	/**
	 * The constant's value → user IDs and lower-cased logins. Only a string
	 * (or one positive whole number, a user ID) names anyone: an array, true
	 * or a float names nobody — cast to a string they would read as the
	 * login "array" or user #1.
	 *
	 * @param mixed $value FISHOTEL_SNIPPETS_EDITORS.
	 * @return array{ids: int[], logins: string[]}
	 */
	public static function parse_editors( $value ) {
		$out = array(
			'ids'    => array(),
			'logins' => array(),
		);

		if ( is_int( $value ) ) {
			if ( $value > 0 ) {
				$out['ids'][] = $value;
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $out;
		}

		foreach ( explode( ',', (string) $value ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( ctype_digit( $item ) ) {
				if ( (int) $item > 0 ) {
					$out['ids'][] = (int) $item;
				}
				continue;
			}
			$out['logins'][] = strtolower( $item );
		}

		$out['ids']    = array_values( array_unique( $out['ids'] ) );
		$out['logins'] = array_values( array_unique( $out['logins'] ) );

		return $out;
	}

	/**
	 * Whether this user may write snippets here.
	 *
	 * @param array $who {
	 *     @type mixed       $constant           FISHOTEL_SNIPPETS_EDITORS as defined (any type), null when not defined.
	 *     @type int         $user_id            Current user ID (0 = logged out).
	 *     @type string      $user_login         Current user login.
	 *     @type bool        $can_manage_options current_user_can( 'manage_options' ).
	 *     @type bool        $multisite          is_multisite().
	 *     @type bool        $super_admin        is_super_admin().
	 *     @type bool        $disallow_file_edit DISALLOW_FILE_EDIT is true.
	 * }
	 * @return array{allowed: bool, reason: string, message: string}
	 *         reason ok|file_edit|no_constant|empty_constant|logged_out|no_capability|not_listed.
	 */
	public static function permission( array $who ) {
		$who = array_merge(
			array(
				'constant'           => null,
				'user_id'            => 0,
				'user_login'         => '',
				'can_manage_options' => false,
				'multisite'          => false,
				'super_admin'        => false,
				'disallow_file_edit' => false,
			),
			$who
		);

		$deny = static function ( $reason, $message ) {
			return array(
				'allowed' => false,
				'reason'  => $reason,
				'message' => $message,
			);
		};

		if ( ! empty( $who['disallow_file_edit'] ) ) {
			return $deny( 'file_edit', 'Editing snippets is off: DISALLOW_FILE_EDIT is true in wp-config.php.' );
		}

		if ( null === $who['constant'] ) {
			return $deny( 'no_constant', 'Nobody may edit snippets yet: FISHOTEL_SNIPPETS_EDITORS is not defined in wp-config.php.' );
		}

		$list = self::parse_editors( $who['constant'] );
		if ( empty( $list['ids'] ) && empty( $list['logins'] ) ) {
			return $deny( 'empty_constant', 'Nobody may edit snippets: FISHOTEL_SNIPPETS_EDITORS in wp-config.php names no user (it must be text such as \'jeff\' or \'jeff, 12\').' );
		}

		$user_id = (int) $who['user_id'];
		if ( $user_id <= 0 ) {
			return $deny( 'logged_out', 'Not logged in.' );
		}

		$capable = ! empty( $who['multisite'] ) ? ! empty( $who['super_admin'] ) : ! empty( $who['can_manage_options'] );
		if ( ! $capable ) {
			return $deny( 'no_capability', ! empty( $who['multisite'] ) ? 'Only a network super admin may edit snippets.' : 'Only an administrator (manage_options) may edit snippets.' );
		}

		$login  = strtolower( trim( (string) $who['user_login'] ) );
		$listed = in_array( $user_id, $list['ids'], true ) || ( '' !== $login && in_array( $login, $list['logins'], true ) );

		if ( ! $listed ) {
			return $deny( 'not_listed', 'Your account is not named in FISHOTEL_SNIPPETS_EDITORS in wp-config.php.' );
		}

		return array(
			'allowed' => true,
			'reason'  => 'ok',
			'message' => '',
		);
	}

	/* ============================================================== */
	/*  Fields                                                         */
	/* ============================================================== */

	/**
	 * Strip a leading `<?php` (and the line break after it): the stored
	 * code has no opening tag, as in Code Snippets.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function strip_open_tag( $code ) {
		$code = (string) $code;
		if ( preg_match( '/^\s*<\?php(?=\s|$)/i', $code, $m ) ) {
			$code = (string) substr( $code, strlen( $m[0] ) );
			$code = (string) preg_replace( '/^[ \t]*(?:\r?\n)?/', '', $code, 1 );
		}
		return $code;
	}

	/**
	 * Normalise typed code: no byte-order mark, LF line breaks (a browser
	 * posts a textarea with CRLF), no opening tag for PHP.
	 *
	 * @param string $type Type.
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalise_code( $type, $code ) {
		$code = (string) $code;
		if ( 0 === strncmp( $code, "\xEF\xBB\xBF", 3 ) ) {
			$code = (string) substr( $code, 3 );
		}
		$code = str_replace( array( "\r\n", "\r" ), "\n", $code );

		return 'php' === $type ? self::strip_open_tag( $code ) : $code;
	}

	/**
	 * One line of header text: whitespace runs (line breaks included)
	 * become one space, control characters go.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	public static function one_line( $text ) {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Validate and normalise the editor's fields.
	 *
	 * @param array      $input   Raw values: slug, name, description, type, scope, priority, active, code.
	 * @param array|null $current The row being edited (its slug is kept: a slug never changes), null for a new one.
	 * @return array{ok: bool, errors: array<string, string>, fields: array}
	 */
	public static function validate( array $input, $current = null ) {
		$errors = array();
		$get    = static function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
		};

		// Slug: the same rule as a file name; fixed once created.
		if ( is_array( $current ) ) {
			$slug = (string) $current['slug'];
		} else {
			$slug = strtolower( trim( $get( 'slug' ) ) );
			if ( '' === $slug ) {
				$errors['slug'] = 'Give the snippet a slug (its id): lowercase letters, digits and single hyphens, e.g. hide-admin-bar.';
			} elseif ( strlen( $slug ) > 64 || ! preg_match( Header::SLUG_PATTERN, $slug ) ) {
				$errors['slug'] = 'The slug must be lowercase letters, digits and single hyphens (at most 64), e.g. hide-admin-bar.';
			}
		}

		$name = self::one_line( $get( 'name' ) );
		if ( '' === $name ) {
			$errors['name'] = 'Give the snippet a name.';
		} elseif ( self::length( $name ) > Header::MAX_NAME ) {
			$errors['name'] = 'The name is longer than ' . Header::MAX_NAME . ' characters.';
		} elseif ( self::breaks_header( $name ) ) {
			$errors['name'] = 'The name cannot contain */ or --> (it becomes the file header on export).';
		}

		$description = self::one_line( $get( 'description' ) );
		if ( self::length( $description ) > Header::MAX_DESCRIPTION ) {
			$errors['description'] = 'The description is longer than ' . Header::MAX_DESCRIPTION . ' characters.';
		} elseif ( self::breaks_header( $description ) ) {
			$errors['description'] = 'The description cannot contain */ or --> (it becomes the file header on export).';
		}

		$type = strtolower( trim( $get( 'type' ) ) );
		if ( ! in_array( $type, Header::TYPES, true ) ) {
			$errors['type'] = 'Pick a type: php, css, js or html.';
			$type           = '';
		}

		$scope = strtolower( trim( $get( 'scope' ) ) );
		if ( '' !== $type ) {
			if ( '' === $scope ) {
				$scope = Header::SCOPES[ $type ][0];
			} elseif ( ! in_array( $scope, Header::SCOPES[ $type ], true ) ) {
				$errors['scope'] = 'Scope "' . $scope . '" is not valid for a ' . $type . ' snippet (' . implode( ', ', Header::SCOPES[ $type ] ) . ').';
			}
		}

		$priority = trim( $get( 'priority' ) );
		if ( '' === $priority ) {
			$priority = 10;
		} elseif ( preg_match( '/^-?\d{1,6}$/', $priority ) ) {
			$priority = (int) $priority;
		} else {
			$errors['priority'] = 'The priority must be a whole number (lower runs first).';
		}

		$raw_active = isset( $input['active'] ) ? $input['active'] : '';
		$active     = ( true === $raw_active || 1 === $raw_active || in_array( (string) $raw_active, array( '1', 'on', 'yes', 'true' ), true ) ) ? 1 : 0;

		$code = self::normalise_code( $type, isset( $input['code'] ) && is_scalar( $input['code'] ) ? (string) $input['code'] : '' );
		if ( '' === trim( $code ) ) {
			$errors['code'] = 'The code is empty.';
		} elseif ( strlen( $code ) > self::MAX_CODE_BYTES ) {
			$errors['code'] = 'The code is larger than ' . (int) ( self::MAX_CODE_BYTES / 1024 ) . ' KB; a snippet that big belongs in a plugin.';
		}

		return array(
			'ok'     => empty( $errors ),
			'errors' => $errors,
			'fields' => array(
				'slug'        => $slug,
				'name'        => $name,
				'description' => $description,
				'type'        => $type,
				'scope'       => $scope,
				'priority'    => is_int( $priority ) ? $priority : 10,
				'active'      => $active,
				'code'        => $code,
			),
		);
	}

	/**
	 * A row's (or fields') meta as a revision keeps it.
	 *
	 * @param array $row Row or fields.
	 * @return array{name: string, description: string, type: string, scope: string, priority: int, active: int}
	 */
	public static function meta_of( array $row ) {
		return array(
			'name'        => isset( $row['name'] ) ? (string) $row['name'] : '',
			'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
			'type'        => isset( $row['type'] ) ? (string) $row['type'] : '',
			'scope'       => isset( $row['scope'] ) ? (string) $row['scope'] : '',
			'priority'    => isset( $row['priority'] ) ? (int) $row['priority'] : 10,
			'active'      => empty( $row['active'] ) ? 0 : 1,
		);
	}

	/**
	 * Whether a declaration of $slug clashes with a file snippet or another
	 * database snippet: the reason, or ''. A file of the SAME slug is left
	 * out (it supersedes this copy, they never both run), and so is any
	 * database snippet superseded by a file (it never runs).
	 *
	 * @param string $slug        The database snippet's slug.
	 * @param array  $declares    Its declarations (Db_Snippets::declared()).
	 * @param array  $files       File snippets (Catalog index 'snippets').
	 * @param array  $db_declares Other database snippets: slug => declarations.
	 * @return string
	 */
	public static function clash( $slug, array $declares, array $files, array $db_declares ) {
		if ( empty( $declares ) ) {
			return '';
		}

		$by_key = array();
		foreach ( $files as $file_slug => $snippet ) {
			if ( (string) $file_slug !== (string) $slug && ! empty( $snippet['declares'] ) ) {
				$by_key[ (string) $file_slug ] = $snippet['declares'];
			}
		}
		foreach ( $db_declares as $other => $list ) {
			if ( (string) $other !== (string) $slug && ! empty( $list ) && ! isset( $files[ (string) $other ] ) ) {
				$by_key[ Db_Snippets::key( $other ) ] = $list;
			}
		}

		$self            = Db_Snippets::key( $slug );
		$by_key[ $self ] = $declares;

		foreach ( Symbols::conflicts( $by_key ) as $conflict ) {
			if ( $conflict['refused'] && in_array( $self, array_map( 'strval', $conflict['slugs'] ), true ) ) {
				return Db_Snippets::clash_text( $conflict, $self, $files );
			}
		}

		return '';
	}

	/* ============================================================== */
	/*  History and audit text                                         */
	/* ============================================================== */

	/**
	 * Hash, line count and size of some code — what the log keeps instead
	 * of the code.
	 *
	 * @param string $code Code.
	 * @return array{hash: string, lines: int, bytes: int}
	 */
	public static function code_stats( $code ) {
		$code  = (string) $code;
		$lines = 0;
		if ( '' !== $code ) {
			$lines = substr_count( $code, "\n" ) + ( "\n" === substr( $code, -1 ) ? 0 : 1 );
		}

		return array(
			'hash'  => substr( hash( 'sha256', $code ), 0, 12 ),
			'lines' => $lines,
			'bytes' => strlen( $code ),
		);
	}

	/**
	 * "code 3f9a…e1 (12 lines)".
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function code_label( $code ) {
		$s = self::code_stats( $code );
		return $s['hash'] . ' (' . $s['lines'] . ( 1 === $s['lines'] ? ' line)' : ' lines)' );
	}

	/**
	 * What changed between two states of a snippet — the fields old -> new
	 * and the code's hash and line count; never the code.
	 *
	 * @param array|null  $before_meta Meta before (null = created).
	 * @param string|null $before_code Code before.
	 * @param array       $after_meta  Meta after.
	 * @param string      $after_code  Code after.
	 * @return string
	 */
	public static function change_summary( $before_meta, $before_code, array $after_meta, $after_code ) {
		$after = self::meta_of( $after_meta );

		if ( ! is_array( $before_meta ) ) {
			return 'created: ' . $after['type'] . ' / ' . $after['scope'] . ', priority ' . $after['priority'] . ', ' . ( $after['active'] ? 'on' : 'off' ) . '; code ' . self::code_label( $after_code );
		}

		$before = self::meta_of( $before_meta );
		$parts  = array();

		foreach ( self::META_FIELDS as $field ) {
			if ( $before[ $field ] === $after[ $field ] ) {
				continue;
			}
			if ( 'active' === $field ) {
				$parts[] = 'switched ' . ( $after['active'] ? 'off -> on' : 'on -> off' );
			} elseif ( 'priority' === $field ) {
				$parts[] = 'priority: ' . $before['priority'] . ' -> ' . $after['priority'];
			} elseif ( 'name' === $field || 'description' === $field ) {
				$parts[] = $field . ': "' . self::cut( $before[ $field ], 60 ) . '" -> "' . self::cut( $after[ $field ], 60 ) . '"';
			} else {
				$parts[] = $field . ': ' . $before[ $field ] . ' -> ' . $after[ $field ];
			}
		}

		if ( (string) $before_code !== (string) $after_code ) {
			$b       = self::code_stats( $before_code );
			$a       = self::code_stats( $after_code );
			$parts[] = 'code: ' . $b['hash'] . ' (' . $b['lines'] . ' lines) -> ' . $a['hash'] . ' (' . $a['lines'] . ' lines)';
		}

		return empty( $parts ) ? 'no change' : implode( '; ', $parts );
	}

	/**
	 * One audit log row's summary.
	 *
	 * @param string $action  One of ACTIONS.
	 * @param array  $context {
	 *     @type int         $version      The version written (or tried).
	 *     @type int         $from_version restore: the version restored.
	 *     @type array|null  $before       Row / fields before (meta + code).
	 *     @type array|null  $after        Row / fields after (meta + code).
	 *     @type string      $message      test_failed / denied: what happened.
	 *     @type string      $attempt      denied: what was tried.
	 *     @type string      $file         export: the file name.
	 *     @type string      $reason       safe_mode: constant|query.
	 * }
	 * @return string
	 */
	public static function audit_summary( $action, array $context = array() ) {
		$c = array_merge(
			array(
				'version'      => 0,
				'from_version' => 0,
				'before'       => null,
				'after'        => null,
				'message'      => '',
				'attempt'      => '',
				'file'         => '',
				'reason'       => '',
			),
			$context
		);

		$v      = 'v' . (int) $c['version'];
		$before = is_array( $c['before'] ) ? $c['before'] : null;
		$after  = is_array( $c['after'] ) ? $c['after'] : null;
		$code   = static function ( $row ) {
			return is_array( $row ) && isset( $row['code'] ) ? (string) $row['code'] : '';
		};

		switch ( $action ) {
			case 'create':
			case 'update':
			case 'activate':
			case 'deactivate':
				$text = $v . ' — ' . self::change_summary( $before, null === $before ? null : $code( $before ), (array) $after, $code( $after ) );
				break;

			case 'restore':
				$text = $v . ' — restored from v' . (int) $c['from_version'];
				if ( null === $before ) {
					$text .= ' after a delete, switched off; code ' . self::code_label( $code( $after ) );
				} else {
					$text .= '; ' . self::change_summary( $before, $code( $before ), (array) $after, $code( $after ) );
				}
				break;

			case 'delete':
				$text = 'deleted at ' . $v . '; last code ' . self::code_label( $code( $before ) ) . '; revisions and log kept';
				break;

			case 'export':
				$text = 'exported ' . $v . ' as ' . (string) $c['file'] . ' (Default: ' . ( is_array( $after ) && ! empty( $after['active'] ) ? 'on' : 'off' ) . '); code ' . self::code_label( $code( $after ) );
				break;

			case 'test_failed':
				$text = 'test run of ' . $v . ' failed: ' . self::cut( self::one_line( $c['message'] ), 400 ) . '; nothing saved; code ' . self::code_label( $code( $after ) );
				break;

			case 'denied':
				$text = 'refused ' . self::one_line( $c['attempt'] ) . ': ' . self::one_line( $c['message'] );
				break;

			case 'safe_mode':
				$text = 'safe mode is on' . ( 'constant' === $c['reason'] ? ' (FISHOTEL_SNIPPETS_SAFE_MODE / CODE_SNIPPETS_SAFE_MODE in wp-config.php)' : ' (?' . Loader::SAFE_MODE_QUERY . '=1 on this request)' ) . ': no snippet runs';
				break;

			default:
				$text = self::one_line( $c['message'] );
		}

		return self::cut( $text, self::MAX_SUMMARY );
	}

	/* ============================================================== */
	/*  Request, CSV                                                   */
	/* ============================================================== */

	/**
	 * Who made the request, as the log keeps it: REMOTE_ADDR (validated),
	 * the FIRST X-Forwarded-For value (untrusted — anyone can send it — cut
	 * to 100 characters, printable only) and the user agent (≤ 255).
	 *
	 * @param array $server $_SERVER.
	 * @return array{remote_addr: string, forwarded_for: string, user_agent: string}
	 */
	public static function client( array $server ) {
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? trim( $server['REMOTE_ADDR'] ) : '';
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			$remote = '';
		}

		$xff = isset( $server['HTTP_X_FORWARDED_FOR'] ) && is_string( $server['HTTP_X_FORWARDED_FOR'] ) ? $server['HTTP_X_FORWARDED_FOR'] : '';
		$xff = explode( ',', $xff );
		$xff = (string) preg_replace( '/[^\x21-\x7E]/', '', trim( (string) $xff[0] ) );

		$agent = isset( $server['HTTP_USER_AGENT'] ) && is_string( $server['HTTP_USER_AGENT'] ) ? $server['HTTP_USER_AGENT'] : '';
		$agent = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $agent );

		return array(
			'remote_addr'   => $remote,
			'forwarded_for' => substr( $xff, 0, 100 ),
			'user_agent'    => self::cut( $agent, 255, '' ),
		);
	}

	/**
	 * A CSV cell a spreadsheet will not run: a value starting with = + - @
	 * a tab or a carriage return gets a leading apostrophe (as the Clean
	 * Room CSV does).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * The log CSV's columns.
	 *
	 * @return string[]
	 */
	public static function csv_header() {
		return array( 'id', 'created_at_utc', 'action', 'slug', 'snippet_id', 'user_id', 'user_login', 'remote_addr', 'x_forwarded_for_untrusted', 'user_agent', 'summary' );
	}

	/**
	 * One log row as CSV cells (guarded).
	 *
	 * @param array $row Log row.
	 * @return string[]
	 */
	public static function csv_row( array $row ) {
		$out = array();
		foreach ( array( 'id', 'created_at', 'action', 'slug', 'snippet_id', 'user_id', 'user_login', 'remote_addr', 'forwarded_for', 'user_agent', 'summary' ) as $key ) {
			$out[] = self::csv_cell( isset( $row[ $key ] ) ? $row[ $key ] : '' );
		}
		return $out;
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * Whether text would close a header comment.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function breaks_header( $text ) {
		return false !== strpos( $text, '*/' ) || false !== strpos( $text, '-->' );
	}

	/**
	 * Character length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * At most $n characters, with an ellipsis when cut.
	 *
	 * @param string $text     Text.
	 * @param int    $n        Characters.
	 * @param string $ellipsis Appended when cut.
	 * @return string
	 */
	public static function cut( $text, $n, $ellipsis = '…' ) {
		$text = (string) $text;
		if ( self::length( $text ) <= $n ) {
			return $text;
		}
		$keep = max( 0, $n - self::length( $ellipsis ) );
		$head = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $keep, 'UTF-8' ) : substr( $text, 0, $keep );
		return $head . $ellipsis;
	}
}
