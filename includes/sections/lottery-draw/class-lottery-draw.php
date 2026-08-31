<?php
/**
 * Lottery Draw section — verifiable lottery allocation for group orders.
 *
 * Architecture note, and it is the whole point of this section: the draw
 * algorithm exists in exactly ONE implementation, `js/draw.js`. PHP never
 * draws. PHP stores the inputs, stores the winner list the admin's browser
 * produced, and prints both. The same `draw.js` runs on the admin screen to
 * produce a result and on the public page to let any visitor recompute it.
 *
 * If PHP also drew, the two implementations could disagree — one integer
 * overflow apart — and every published result would stop matching what a
 * member computes in their browser. The verifiability claim is the feature;
 * a second implementation is the only thing that can quietly break it.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lottery_Draw
 */
class Lottery_Draw {

	/**
	 * Section slug (matches the directory name).
	 *
	 * @var string
	 */
	const SECTION_SLUG = 'lottery-draw';

	/**
	 * Custom post type holding one draw per post.
	 *
	 * @var string
	 */
	const POST_TYPE = 'fishotel_draw';

	/**
	 * Post meta key holding the draw payload as a raw JSON string.
	 *
	 * Stored as a string — not as a structured array — so the bytes we
	 * serve at the public JSON URL are the bytes we stored, and members
	 * can diff the payload against the page without normalisation games.
	 *
	 * @var string
	 */
	const META_PAYLOAD = '_fh_draw_payload';

	/**
	 * Post meta key holding the draw's public identifier.
	 *
	 * @var string
	 */
	const META_DRAW_ID = '_fh_draw_id';

	/**
	 * Post meta key holding the ID of the draw this one corrects, if any.
	 *
	 * Mirrors the payload's `supersedes` field. It exists separately so a
	 * superseded draw's page can find its own correction with one query —
	 * a published draw can never be edited to point at its replacement.
	 *
	 * @var string
	 */
	const META_SUPERSEDES = '_fh_draw_supersedes';

	/**
	 * Post meta key holding the draw's import log.
	 *
	 * Kept beside the payload rather than inside it: the payload is the
	 * public, verifiable document, and who pressed Import is site
	 * bookkeeping rather than something members need to check a draw.
	 *
	 * @var string
	 */
	const META_LOG = '_fh_draw_log';

	/**
	 * Admin submenu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'fishotel-misc-lottery-draw';

	/**
	 * Capability required for every admin write.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Draw lifecycle statuses.
	 *
	 * draft            — entrants and seed still editable.
	 * seed_committed   — seed and entrants locked, draw not yet run.
	 * published        — results stored; the record is immutable.
	 *
	 * @var string
	 */
	const STATUS_DRAFT     = 'draft';
	const STATUS_COMMITTED = 'seed_committed';
	const STATUS_PUBLISHED = 'published';

	/**
	 * Seed commitment methods.
	 *
	 * @var string
	 */
	const SEED_COMMIT_EARLY = 'commit_early';
	const SEED_EXTERNAL     = 'external';

	/**
	 * Query var used to serve the raw JSON payload.
	 *
	 * @var string
	 */
	const JSON_QUERY_VAR = 'fishotel_draw_json';

	/**
	 * Permalink base for draws.
	 *
	 * @var string
	 */
	const REWRITE_SLUG = 'draw';

	/**
	 * Option holding the rewrite-rule version, bumped when the rules
	 * below change so they get flushed exactly once per change.
	 *
	 * @var string
	 */
	const REWRITE_OPTION  = 'fishotel_draw_rewrite_version';
	const REWRITE_VERSION = '1';

	/**
	 * Timezone used for display. Storage is always UTC.
	 *
	 * @var string
	 */
	const DISPLAY_TZ = 'America/Chicago';

	/**
	 * Section base path.
	 *
	 * @return string
	 */
	public static function path() {
		return FISHOTEL_MISC_PATH . 'includes/sections/lottery-draw/';
	}

	/**
	 * Section base URL.
	 *
	 * @return string
	 */
	public static function url() {
		return FISHOTEL_MISC_URL . 'includes/sections/lottery-draw/';
	}

	/**
	 * Return section metadata used by the Section Manager.
	 *
	 * @return array{name: string, description: string, icon: string}
	 */
	public static function get_section_info() {
		return array(
			'name'        => __( 'Lottery Draw', 'fishotel-misc-plugin' ),
			'description' => __( 'Allocate contested group-order fish by a seeded lottery that members can recompute in their own browser.', 'fishotel-misc-plugin' ),
			'icon'        => 'dashicons-tickets-alt',
		);
	}

	/**
	 * Boot the section when it is enabled.
	 */
	public static function boot() {
		// The plugin's autoloader assumes namespace segments match
		// directory names verbatim, but this directory uses a dash
		// (`lottery-draw`) while the namespace uses an underscore
		// (`Lottery_Draw`). Require classes explicitly to bypass that,
		// exactly as the Coming Soon and Visitor Stats sections do.
		require_once self::path() . 'class-store.php';
		require_once self::path() . 'class-importer.php';
		require_once self::path() . 'class-frontend.php';

		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 20 );

		$frontend = new Frontend();
		$frontend->init();

		if ( is_admin() ) {
			require_once self::path() . 'class-admin.php';

			$admin = new Admin();
			$admin->init();
		}
	}

	/**
	 * Register an admin submenu for this section.
	 */
	public static function register_menu() {
		require_once self::path() . 'class-store.php';
		require_once self::path() . 'class-importer.php';
		require_once self::path() . 'class-admin.php';

		add_submenu_page(
			'fishotel-misc',
			__( 'Lottery Draw', 'fishotel-misc-plugin' ),
			__( 'Lottery Draw', 'fishotel-misc-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( new Admin(), 'render_page' )
		);
	}

	/**
	 * Plugin-activation hook for this section.
	 */
	public static function activate() {
		self::register_post_type();
		flush_rewrite_rules();
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Register the draw post type and its JSON rewrite rule.
	 *
	 * `show_ui` is false: draws are never edited through the standard
	 * post editor, because the editor cannot enforce the immutability
	 * rules that make a published draw evidence rather than a claim.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Lottery Draws', 'fishotel-misc-plugin' ),
					'singular_name' => __( 'Lottery Draw', 'fishotel-misc-plugin' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'has_archive'        => false,
				'exclude_from_search' => false,
				'rewrite'            => array(
					'slug'       => self::REWRITE_SLUG,
					'with_front' => false,
				),
				'supports'           => array( 'title' ),
				'capability_type'    => 'post',
			)
		);

		add_rewrite_rule(
			'^' . self::REWRITE_SLUG . '/([^/]+)/json/?$',
			'index.php?' . self::JSON_QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush rewrite rules once after the rules above change.
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Generate a seed of the form RVS-YYYY-MM-DD-XXXXX.
	 *
	 * The random block uses an unambiguous alphabet (no O/0, I/1) so a
	 * member reading the seed off a forum post cannot mistype it.
	 *
	 * @return string
	 */
	public static function generate_seed() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$block    = '';

		for ( $i = 0; $i < 5; $i++ ) {
			$block .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
		}

		return 'RVS-' . gmdate( 'Y-m-d' ) . '-' . $block;
	}

	/**
	 * Current time as an ISO-8601 UTC string.
	 *
	 * @return string
	 */
	public static function now_utc() {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Format a stored UTC timestamp for display in America/Chicago.
	 *
	 * @param string $iso ISO-8601 UTC timestamp.
	 * @return string Human-readable local time, or '' when unset/unparseable.
	 */
	public static function format_local( $iso ) {
		if ( empty( $iso ) ) {
			return '';
		}

		try {
			$dt = new \DateTime( $iso, new \DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new \DateTimeZone( self::DISPLAY_TZ ) );
		} catch ( \Exception $e ) {
			return '';
		}

		return $dt->format( 'M j, Y g:i A T' );
	}
}
