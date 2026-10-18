<?php
/**
 * Snippets admin — the page under FisHotel Tools (every snippet with its
 * switch, the broken files, the name clashes), the AJAX switch, and the
 * admin notices (safe mode, snippets the Code Snippets guard holds back,
 * snippets that threw or were not loaded on this request).
 *
 * No code editor: a snippet is changed in GitHub and arrives with the
 * plugin update. Every write checks manage_options and a nonce; the view
 * escapes every value; the JS sets text only.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for the switch.
	 *
	 * @var string
	 */
	const NONCE = 'fishotel_snippets_admin';

	/**
	 * Where the files live in the repository (for the page's links).
	 *
	 * @var string
	 */
	const GITHUB_TREE = 'https://github.com/Dierks27/fishotel-misc-plugin/blob/main/includes/sections/site-snippets/snippets/';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_ajax_fishotel_snippets_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * The page URL, keeping the safe-mode flag while it is on by query.
	 *
	 * @param array $extra Extra query args.
	 * @return string
	 */
	public static function page_url( array $extra = array() ) {
		$args = array( 'page' => Site_Snippets::MENU_SLUG );
		if ( 'query' === Loader::safe_mode()['reason'] ) {
			$args[ Loader::SAFE_MODE_QUERY ] = '1';
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * Human label of where a snippet runs.
	 *
	 * @param string $type  Type.
	 * @param string $scope Scope.
	 * @return string
	 */
	public static function scope_label( $type, $scope ) {
		$labels = array(
			'php'  => array(
				'global'    => __( 'Everywhere', 'fishotel-misc-plugin' ),
				'front-end' => __( 'Front end', 'fishotel-misc-plugin' ),
				'admin'     => __( 'Admin', 'fishotel-misc-plugin' ),
			),
			'css'  => array(
				'front-end' => __( 'Front-end styles', 'fishotel-misc-plugin' ),
				'admin'     => __( 'Admin styles', 'fishotel-misc-plugin' ),
				'login'     => __( 'Login styles', 'fishotel-misc-plugin' ),
			),
			'js'   => array(
				'front-end' => __( 'Front-end script (footer)', 'fishotel-misc-plugin' ),
				'footer'    => __( 'Front-end script (footer)', 'fishotel-misc-plugin' ),
				'head'      => __( 'Front-end script (head)', 'fishotel-misc-plugin' ),
				'admin'     => __( 'Admin script', 'fishotel-misc-plugin' ),
				'login'     => __( 'Login script', 'fishotel-misc-plugin' ),
			),
			'html' => array(
				'shortcode' => __( 'Shortcode', 'fishotel-misc-plugin' ),
				'head'      => __( 'Head content', 'fishotel-misc-plugin' ),
				'body'      => __( 'Body-open content', 'fishotel-misc-plugin' ),
				'footer'    => __( 'Footer content', 'fishotel-misc-plugin' ),
			),
		);

		return isset( $labels[ $type ][ $scope ] ) ? $labels[ $type ][ $scope ] : $type . ' / ' . $scope;
	}

	/**
	 * A snippet's status line — PURE given its inputs.
	 *
	 * @param array $snippet     Index entry.
	 * @param mixed $enabled_map Enabled option.
	 * @param array $held        Guard result (slug => reason).
	 * @param array $safe        Loader::safe_mode().
	 * @return array{key: string, label: string, on: bool, locked: bool}
	 *         key on|off|held|refused|safe; locked = the switch cannot turn on.
	 */
	public static function status( array $snippet, $enabled_map, array $held, array $safe ) {
		$slug = (string) $snippet['slug'];
		$on   = Rules::is_enabled( $slug, ! empty( $snippet['default_on'] ), $enabled_map );

		if ( '' !== (string) $snippet['refused'] ) {
			return array(
				'key'    => 'refused',
				'label'  => __( 'Refused', 'fishotel-misc-plugin' ) . ' — ' . $snippet['refused'],
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

		if ( isset( $held[ $slug ] ) ) {
			return array(
				'key'    => 'held',
				'label'  => __( 'Waiting', 'fishotel-misc-plugin' ) . ' — ' . $held[ $slug ],
				'on'     => true,
				'locked' => false,
			);
		}

		$label = 'html' === $snippet['type'] && 'shortcode' === $snippet['scope']
			/* translators: %s: the shortcode */
			? sprintf( __( 'On — renders where %s is placed', 'fishotel-misc-plugin' ), '[fh_snippet slug="' . $slug . '"]' )
			: __( 'On', 'fishotel-misc-plugin' ) . ' — ' . self::scope_label( $snippet['type'], $snippet['scope'] );

		return array(
			'key'    => 'on',
			'label'  => $label,
			'on'     => true,
			'locked' => false,
		);
	}

	/**
	 * The broken-file reason for a slug ('' when none).
	 *
	 * @param array  $index Catalog index.
	 * @param string $slug  Slug.
	 * @return string
	 */
	public static function broken_reason( array $index, $slug ) {
		foreach ( (array) $index['broken'] as $row ) {
			if ( preg_replace( '/\.(php|css|js|html)$/', '', (string) $row['file'] ) === $slug ) {
				return $row['file'] . ': ' . $row['reason'];
			}
		}
		return '';
	}

	/* ============================================================== */
	/*  Page                                                           */
	/* ============================================================== */

	/**
	 * Render the page.
	 */
	public function render_page() {
		if ( ! current_user_can( Loader::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		// Always a fresh read here: the page is where a new or edited file
		// shows up, and it refreshes the stored index when it changed.
		$index     = Catalog::get( null, true );
		$enabled   = Loader::enabled_map();
		$safe      = Loader::safe_mode();
		$held      = Loader::held( $index );
		$errors    = Loader::stored_errors();
		$state     = Loader::state();
		$cs_active = Loader::code_snippets_active();
		$map       = Rules::shortcode_map( $index['snippets'] );
		$rows      = array();

		foreach ( $index['snippets'] as $slug => $snippet ) {
			$rows[ $slug ] = array(
				'snippet' => $snippet,
				'status'  => self::status( $snippet, $enabled, $held, $safe ),
				'error'   => isset( $errors[ $slug ] ) ? $errors[ $slug ] : null,
				'skipped' => isset( $state['skipped'][ $slug ] ) ? $state['skipped'][ $slug ] : '',
				'code'    => self::source( $snippet ),
			);
		}

		include Site_Snippets::path() . 'views/page.php';
	}

	/**
	 * A snippet's file text for the read-only view ('' when large).
	 *
	 * @param array $snippet Index entry.
	 * @return string
	 */
	private static function source( array $snippet ) {
		$path = Catalog::path_of( $snippet );
		if ( ! is_file( $path ) || filesize( $path ) > 65536 ) {
			return '';
		}
		$text = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		return false === $text ? '' : $text;
	}

	/* ============================================================== */
	/*  Switch                                                         */
	/* ============================================================== */

	/**
	 * AJAX: switch a snippet on or off.
	 */
	public function ajax_toggle() {
		if ( ! current_user_can( Loader::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$enable = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];

		$result = self::switch_snippet( $slug, $enable );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Switch a snippet (the AJAX handler minus the request plumbing).
	 *
	 * Turning on re-reads every file first and refuses a snippet whose
	 * file no longer parses or that clashes with another file; a PHP
	 * snippet that is off is then test-run once (Loader::test_run(), as
	 * Code Snippets does on save) and refused if it throws, hits an error
	 * the parser did not catch, or declares a name that already exists — a
	 * fatal there ends only this request, before the option is written.
	 * Turning off is always allowed for a listed or broken file, or a slug
	 * already stored; an unknown slug is refused (nothing to switch, and the
	 * option is autoloaded on every request).
	 *
	 * @param string $slug   Slug.
	 * @param bool   $enable On?
	 * @return array{ok: bool, message: string, slug?: string, enabled?: bool, status?: array}
	 */
	public static function switch_snippet( $slug, $enable ) {
		if ( '' === $slug ) {
			return array(
				'ok'      => false,
				'message' => __( 'Missing snippet.', 'fishotel-misc-plugin' ),
			);
		}

		$index = Catalog::get( null, true );

		if ( $enable ) {
			if ( ! isset( $index['snippets'][ $slug ] ) ) {
				$reason = self::broken_reason( $index, $slug );
				return array(
					'ok'      => false,
					'message' => '' !== $reason
						/* translators: %s: why the file is broken */
						? sprintf( __( 'Refused: the file is broken — %s', 'fishotel-misc-plugin' ), $reason )
						: __( 'Refused: no such snippet file.', 'fishotel-misc-plugin' ),
				);
			}
			if ( '' !== (string) $index['snippets'][ $slug ]['refused'] ) {
				return array(
					'ok'      => false,
					/* translators: %s: the clash */
					'message' => sprintf( __( 'Refused: %s', 'fishotel-misc-plugin' ), $index['snippets'][ $slug ]['refused'] ),
				);
			}

			$snippet = $index['snippets'][ $slug ];
			if ( 'php' === $snippet['type'] && ! Rules::is_enabled( $slug, ! empty( $snippet['default_on'] ), Loader::enabled_map() ) ) {
				$failed = Loader::test_run( $snippet );
				if ( '' !== $failed ) {
					if ( ! empty( $snippet['cs_id'] ) && Loader::code_snippets_active() ) {
						/* translators: %d: Code Snippets snippet id */
						$failed .= ' ' . sprintf( __( 'If Code Snippets #%d is still active, deactivate it there first, then switch this on.', 'fishotel-misc-plugin' ), (int) $snippet['cs_id'] );
					}
					return array(
						'ok'      => false,
						/* translators: %s: what the test run hit */
						'message' => sprintf( __( 'Refused: the test run failed — %s', 'fishotel-misc-plugin' ), $failed ),
					);
				}
			}
		} elseif ( ! isset( $index['snippets'][ $slug ] ) && '' === self::broken_reason( $index, $slug ) && ! array_key_exists( $slug, Loader::enabled_map() ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Refused: no such snippet file.', 'fishotel-misc-plugin' ),
			);
		}

		$map          = Loader::enabled_map();
		$map[ $slug ] = (bool) $enable;
		ksort( $map );
		update_option( Loader::OPTION_ENABLED, $map, true );
		Loader::clear_error( $slug );

		$message = $enable ? __( 'Switched on.', 'fishotel-misc-plugin' ) : __( 'Switched off.', 'fishotel-misc-plugin' );
		$status  = array(
			'key'    => 'off',
			'label'  => __( 'Off', 'fishotel-misc-plugin' ),
			'on'     => false,
			'locked' => false,
		);

		if ( isset( $index['snippets'][ $slug ] ) ) {
			$held   = Loader::held( $index );
			$status = self::status( $index['snippets'][ $slug ], $map, $held, Loader::safe_mode() );
			if ( 'held' === $status['key'] ) {
				/* translators: %s: why it waits */
				$message = sprintf( __( 'Switched on, but it will not run yet: %s', 'fishotel-misc-plugin' ), $held[ $slug ] );
			}
		}

		return array(
			'ok'      => true,
			'message' => $message,
			'slug'    => $slug,
			'enabled' => (bool) $enable,
			'status'  => $status,
		);
	}

	/* ============================================================== */
	/*  Notices                                                        */
	/* ============================================================== */

	/**
	 * Admin notices for manage_options users: safe mode, held back,
	 * not loaded, threw (this request).
	 */
	public function notices() {
		if ( ! current_user_can( Loader::CAPABILITY ) ) {
			return;
		}

		$safe = Loader::safe_mode();

		if ( $safe['active'] ) {
			$text = 'constant' === $safe['reason']
				? __( 'FisHotel Snippets safe mode is on (FISHOTEL_SNIPPETS_SAFE_MODE or CODE_SNIPPETS_SAFE_MODE in wp-config.php): no snippet runs. Remove the constant to run them again.', 'fishotel-misc-plugin' )
				: __( 'FisHotel Snippets safe mode is on for this page only: no snippet runs. Switch off the snippet that broke the site, then load any page without the flag.', 'fishotel-misc-plugin' );

			echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Snippets', 'fishotel-misc-plugin' ) . '</a></p></div>';
			return;
		}

		$index = Catalog::get();
		$held  = Loader::held( $index );
		$state = Loader::state();

		if ( ! empty( $held ) ) {
			$items = array();
			foreach ( $held as $slug => $reason ) {
				$items[] = $slug . ' (' . $reason . ')';
			}
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'FisHotel Snippets waiting for Code Snippets:', 'fishotel-misc-plugin' ) . '</strong> ' . esc_html( implode( '; ', $items ) ) . ' ' . esc_html__( 'Running both copies would redeclare functions and take the site down.', 'fishotel-misc-plugin' ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Snippets', 'fishotel-misc-plugin' ) . '</a></p></div>';
		}

		foreach ( (array) $state['skipped'] as $slug => $reason ) {
			/* translators: 1: slug, 2: reason */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'FisHotel Snippets: %1$s was not loaded — %2$s', 'fishotel-misc-plugin' ), $slug, $reason ) ) . '</p></div>';
		}

		foreach ( (array) $state['errors'] as $slug => $message ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'FisHotel Snippets: ' . $message ) . ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Snippets', 'fishotel-misc-plugin' ) . '</a></p></div>';
		}
	}

	/* ============================================================== */
	/*  Assets                                                         */
	/* ============================================================== */

	/**
	 * CSS / JS on the section page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Site_Snippets::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-snippets-page',
			Site_Snippets::url() . 'css/snippets-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-snippets-page',
			Site_Snippets::url() . 'js/snippets-admin.js',
			array( 'jquery' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		$ajax = admin_url( 'admin-ajax.php' );
		if ( 'query' === Loader::safe_mode()['reason'] ) {
			// The switch must not run the snippets it is switching off.
			$ajax = add_query_arg( Loader::SAFE_MODE_QUERY, '1', $ajax );
		}

		wp_localize_script(
			'fishotel-snippets-page',
			'fhSnippetsAdmin',
			array(
				'ajaxUrl' => $ajax,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'saving' => __( 'Saving…', 'fishotel-misc-plugin' ),
					'error'  => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}
}
