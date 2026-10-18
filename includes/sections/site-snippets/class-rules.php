<?php
/**
 * Rules — PURE decisions: is a snippet on, does it run on this request,
 * in what order, where does a CSS / JS / HTML snippet print, is safe mode
 * on, which snippets the Code Snippets duplicate guard holds back, and
 * which file answers an old `[code_snippet id=N]`.
 *
 * Everything takes plain values so tests run without WordPress.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 */

namespace FisHotel\Misc\Sections\Site_Snippets;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rules
 */
class Rules {

	/**
	 * Output slots for CSS / JS / HTML snippets. Each is printed on one
	 * kind of screen; a shortcode snippet has no slot.
	 */
	const SLOT_FRONT_STYLE  = 'front-style';
	const SLOT_FRONT_HEAD   = 'front-head-script';
	const SLOT_FRONT_FOOT   = 'front-footer-script';
	const SLOT_ADMIN_STYLE  = 'admin-style';
	const SLOT_ADMIN_SCRIPT = 'admin-script';
	const SLOT_LOGIN_STYLE  = 'login-style';
	const SLOT_LOGIN_SCRIPT = 'login-script';
	const SLOT_HTML_HEAD    = 'html-head';
	const SLOT_HTML_BODY    = 'html-body';
	const SLOT_HTML_FOOTER  = 'html-footer';

	/**
	 * Slots per screen: front (the site), admin (wp-admin), login (wp-login.php).
	 *
	 * @var array<string, string[]>
	 */
	const SCREEN_SLOTS = array(
		'front' => array( self::SLOT_FRONT_STYLE, self::SLOT_FRONT_HEAD, self::SLOT_FRONT_FOOT, self::SLOT_HTML_HEAD, self::SLOT_HTML_BODY, self::SLOT_HTML_FOOTER ),
		'admin' => array( self::SLOT_ADMIN_STYLE, self::SLOT_ADMIN_SCRIPT ),
		'login' => array( self::SLOT_LOGIN_STYLE, self::SLOT_LOGIN_SCRIPT ),
	);

	/**
	 * Whether a snippet is switched on: the stored choice when the admin
	 * page ever switched it, else the file's Default.
	 *
	 * @param string $slug        Slug.
	 * @param bool   $default_on  The header's Default.
	 * @param mixed  $enabled_map The fishotel_snippets_enabled option (slug => bool).
	 * @return bool
	 */
	public static function is_enabled( $slug, $default_on, $enabled_map ) {
		if ( is_array( $enabled_map ) && array_key_exists( (string) $slug, $enabled_map ) ) {
			$value = $enabled_map[ (string) $slug ];
			return true === $value || 1 === $value || '1' === $value;
		}

		return (bool) $default_on;
	}

	/**
	 * Whether a PHP snippet of this scope runs on this request — Code
	 * Snippets' own rule: global always, admin when is_admin() (which
	 * includes admin-ajax.php), front-end otherwise (REST, cron and CLI
	 * included).
	 *
	 * @param string $scope    global|front-end|admin.
	 * @param bool   $is_admin is_admin().
	 * @return bool
	 */
	public static function php_runs_here( $scope, $is_admin ) {
		switch ( $scope ) {
			case 'global':
				return true;
			case 'admin':
				return (bool) $is_admin;
			case 'front-end':
				return ! $is_admin;
		}
		return false;
	}

	/**
	 * Where a CSS / JS / HTML snippet prints ('' for PHP and shortcodes).
	 *
	 * @param string $type  php|css|js|html.
	 * @param string $scope Scope.
	 * @return string One of the SLOT_* constants, or ''.
	 */
	public static function slot( $type, $scope ) {
		$map = array(
			'css'  => array(
				'front-end' => self::SLOT_FRONT_STYLE,
				'admin'     => self::SLOT_ADMIN_STYLE,
				'login'     => self::SLOT_LOGIN_STYLE,
			),
			'js'   => array(
				'front-end' => self::SLOT_FRONT_FOOT,
				'footer'    => self::SLOT_FRONT_FOOT,
				'head'      => self::SLOT_FRONT_HEAD,
				'admin'     => self::SLOT_ADMIN_SCRIPT,
				'login'     => self::SLOT_LOGIN_SCRIPT,
			),
			'html' => array(
				'head'   => self::SLOT_HTML_HEAD,
				'body'   => self::SLOT_HTML_BODY,
				'footer' => self::SLOT_HTML_FOOTER,
			),
		);

		return isset( $map[ $type ][ $scope ] ) ? $map[ $type ][ $scope ] : '';
	}

	/**
	 * Whether a CSS / JS / HTML snippet prints on a screen.
	 *
	 * @param string $type   Type.
	 * @param string $scope  Scope.
	 * @param string $screen front|admin|login.
	 * @return bool
	 */
	public static function prints_on( $type, $scope, $screen ) {
		$slot    = self::slot( $type, $scope );
		$screens = self::SCREEN_SLOTS;
		return '' !== $slot && isset( $screens[ $screen ] ) && in_array( $slot, $screens[ $screen ], true );
	}

	/**
	 * Sort snippets by Priority (lower first), then slug — the order the
	 * PHP snippets are included and the CSS / JS / HTML ones printed.
	 *
	 * @param array $snippets List (or slug-keyed map) of index entries.
	 * @return array List, sorted.
	 */
	public static function sort( array $snippets ) {
		$list = array_values( $snippets );

		usort(
			$list,
			static function ( $a, $b ) {
				$pa = (int) $a['priority'];
				$pb = (int) $b['priority'];
				if ( $pa !== $pb ) {
					return $pa < $pb ? -1 : 1;
				}
				return strcmp( (string) $a['slug'], (string) $b['slug'] );
			}
		);

		return $list;
	}

	/**
	 * The PHP snippets to include on this request, in order: switched on,
	 * not refused, not held back by the guard, and of a scope that runs
	 * here.
	 *
	 * @param array $snippets    slug => index entry.
	 * @param mixed $enabled_map The enabled option.
	 * @param bool  $is_admin    is_admin().
	 * @param array $held        slug => reason (guard()).
	 * @return array List of index entries.
	 */
	public static function php_plan( array $snippets, $enabled_map, $is_admin, array $held = array() ) {
		$run = array();

		foreach ( $snippets as $slug => $s ) {
			if ( 'php' !== $s['type'] || '' !== (string) $s['refused'] || isset( $held[ $slug ] ) ) {
				continue;
			}
			if ( ! self::is_enabled( $slug, $s['default_on'], $enabled_map ) || ! self::php_runs_here( $s['scope'], $is_admin ) ) {
				continue;
			}
			$run[] = $s;
		}

		return self::sort( $run );
	}

	/**
	 * The CSS / JS / HTML snippets to print, per slot, in order (shortcode
	 * snippets are rendered where their shortcode is, not here).
	 *
	 * @param array $snippets    slug => index entry.
	 * @param mixed $enabled_map The enabled option.
	 * @param array $held        slug => reason (guard()).
	 * @return array<string, array> slot => list of index entries.
	 */
	public static function output_plan( array $snippets, $enabled_map, array $held = array() ) {
		$slots = array();

		foreach ( self::sort( $snippets ) as $s ) {
			$slot = self::slot( $s['type'], $s['scope'] );
			if ( '' === $slot || '' !== (string) $s['refused'] || isset( $held[ $s['slug'] ] ) ) {
				continue;
			}
			if ( ! self::is_enabled( $s['slug'], $s['default_on'], $enabled_map ) ) {
				continue;
			}
			$slots[ $slot ][] = $s;
		}

		return $slots;
	}

	/**
	 * The guard's candidates: switched-on snippets imported from Code
	 * Snippets (shortcode snippets excepted — a shortcode only renders
	 * where it is placed, so it cannot collide).
	 *
	 * @param array $snippets    slug => index entry.
	 * @param mixed $enabled_map The enabled option.
	 * @return array<string, int> slug => Code Snippets id.
	 */
	public static function guard_candidates( array $snippets, $enabled_map ) {
		$out = array();

		foreach ( $snippets as $slug => $s ) {
			if ( empty( $s['cs_id'] ) || ( 'html' === $s['type'] && 'shortcode' === $s['scope'] ) ) {
				continue;
			}
			if ( '' !== (string) $s['refused'] || ! self::is_enabled( $slug, $s['default_on'], $enabled_map ) ) {
				continue;
			}
			$out[ (string) $slug ] = (int) $s['cs_id'];
		}

		return $out;
	}

	/**
	 * Safe mode: the FISHOTEL_SNIPPETS_SAFE_MODE constant (or Code
	 * Snippets' own CODE_SNIPPETS_SAFE_MODE, which an owner used to that
	 * plugin will reach for first), or the query flag for a user who can
	 * manage options. The capability callback runs only when the flag is
	 * present, so an ordinary request never resolves the current user.
	 *
	 * @param bool     $constant   Whether a safe-mode constant is true.
	 * @param bool     $query_flag Whether ?fishotel-snippets-safe-mode=1 is on the request.
	 * @param callable $can_manage Returns whether the current user can manage options.
	 * @return array{active: bool, reason: string} reason constant|query|''.
	 */
	public static function safe_mode( $constant, $query_flag, $can_manage ) {
		if ( $constant ) {
			return array(
				'active' => true,
				'reason' => 'constant',
			);
		}

		if ( $query_flag && is_callable( $can_manage ) && call_user_func( $can_manage ) ) {
			return array(
				'active' => true,
				'reason' => 'query',
			);
		}

		return array(
			'active' => false,
			'reason' => '',
		);
	}

	/**
	 * The Code Snippets duplicate guard. While Code Snippets is active, a
	 * snippet imported from its #N is held back as long as #N is still
	 * active there — running both would redeclare functions and take the
	 * site down. When the active ids could not be read (null), every
	 * candidate is held back: failing closed is the safe side.
	 *
	 * @param array<string, int> $candidates slug => Code Snippets id (switched-on snippets only).
	 * @param bool               $cs_active  Whether the Code Snippets plugin is loaded.
	 * @param int[]|null         $active_ids Active Code Snippets ids among the candidates, null when unknown.
	 * @return array<string, string> slug => reason, for every snippet held back.
	 */
	public static function guard( array $candidates, $cs_active, $active_ids ) {
		if ( ! $cs_active || empty( $candidates ) ) {
			return array();
		}

		$out = array();

		if ( ! is_array( $active_ids ) ) {
			foreach ( $candidates as $slug => $id ) {
				$out[ (string) $slug ] = 'Code Snippets is active and its snippet table could not be read, so #' . (int) $id . ' may still be running.';
			}
			return $out;
		}

		$active = array_map( 'intval', $active_ids );
		foreach ( $candidates as $slug => $id ) {
			if ( in_array( (int) $id, $active, true ) ) {
				$out[ (string) $slug ] = 'Code Snippets #' . (int) $id . ' is still active — deactivate it there first.';
			}
		}

		return $out;
	}

	/**
	 * Code Snippets id → slug for the compatible [code_snippet id=N]
	 * shortcode: HTML snippets of scope shortcode whose Origin names
	 * "Code Snippets #N". Two files claiming one id are ambiguous and
	 * neither answers.
	 *
	 * @param array $snippets slug => index entry.
	 * @return array{map: array<int, string>, ambiguous: array<int, string[]>}
	 */
	public static function shortcode_map( array $snippets ) {
		$claims = array();

		foreach ( $snippets as $slug => $s ) {
			$slug = isset( $s['slug'] ) ? (string) $s['slug'] : (string) $slug;
			$id   = isset( $s['cs_id'] ) ? (int) $s['cs_id'] : 0;

			if ( $id > 0 && 'html' === $s['type'] && 'shortcode' === $s['scope'] ) {
				$claims[ $id ][] = $slug;
			}
		}

		ksort( $claims );

		$map       = array();
		$ambiguous = array();
		foreach ( $claims as $id => $slugs ) {
			sort( $slugs );
			if ( 1 === count( $slugs ) ) {
				$map[ $id ] = $slugs[0];
			} else {
				$ambiguous[ $id ] = $slugs;
			}
		}

		return array(
			'map'       => $map,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * Boolean shortcode flags the way Code Snippets reads them:
	 * `[code_snippet id=3 format]` arrives as array( 'id' => 3, 0 => 'format' ).
	 *
	 * @param mixed    $atts  Shortcode attributes.
	 * @param string[] $flags Flag names.
	 * @return array
	 */
	public static function shortcode_flags( $atts, array $flags ) {
		$atts = is_array( $atts ) ? $atts : array();

		foreach ( $atts as $key => $value ) {
			if ( is_int( $key ) && in_array( $value, $flags, true ) && ! isset( $atts[ $value ] ) ) {
				$atts[ $value ] = true;
				unset( $atts[ $key ] );
			}
		}

		foreach ( $flags as $flag ) {
			$v             = isset( $atts[ $flag ] ) ? $atts[ $flag ] : false;
			$atts[ $flag ] = true === $v || ( is_string( $v ) && in_array( strtolower( $v ), array( '1', 'true', 'yes', 'on' ), true ) ) || 1 === $v;
		}

		return $atts;
	}
}
