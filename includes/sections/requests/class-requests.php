<?php
/**
 * Requests section — a request sheet for fish people ask for, kept in
 * one place instead of in e-mails, notes and memory.
 *
 * WHY this exists: requests come in by e-mail, in person, from locals,
 * for lost fish, from long-time clients — and one person cannot keep
 * track of them, so they stopped being taken. Here every request is a row
 * with a state that follows the fish by itself (waiting → on order → in
 * quarantine → ready → offered → filled), a fee that lands in the
 * customer's wallet, and a log of everything that happened to it.
 *
 * Hidden from the public at the start: no menu item, nothing in a
 * sitemap, noindex. The form exists only behind a private link the owner
 * creates; requests can also be added by hand, or by another section
 * through fishotel_requests_create().
 *
 * Architecture:
 *   - class-store.php      the ONLY file that touches $wpdb: three tables
 *                          (requests, private links, the append-only log)
 *                          via dbDelta gated by fishotel_requests_db_version.
 *   - class-rules.php      PURE: the states, validation, link rules, the fee
 *                          and "still want it?" timers, the shared words.
 *   - class-tokens.php     the signed pay / personal / answer tokens.
 *   - class-urls.php       every address the section hands out.
 *   - class-settings.php   the one option: switches, timers, the "came
 *                          from" list, the five e-mails.
 *   - class-mailer.php     the five e-mails through wp_mail(), logged.
 *   - class-service.php    everything that can happen to a request (store,
 *                          mailer and the outside world as parameters).
 *   - class-fees.php       the fee through Batch Manager's wallet deposit:
 *                          the pay link, the cart line, the paid order.
 *   - class-cleanroom.php  the one place the Clean Room is read (behind
 *                          class_exists): names, sizes, supplier orders.
 *   - class-follow.php     PURE plan + the run: the state follows the fish.
 *   - class-cron.php       the daily job.
 *   - class-listing.php    PURE: filter, sort, group by fish, "Needs you",
 *                          the Demand table.
 *   - class-front.php      the private form, "my requests" (personal link
 *                          and My Account), keep / cancel, the pay link,
 *                          the [fishotel_request_form] shortcode.
 *   - class-admin.php      FisHotel Tools → Requests: the list, Add, Links,
 *                          Demand, Settings; "Requested by" on the Clean
 *                          Room's supplier order page.
 *
 * The file uses bracketed namespaces because fishotel_requests_create()
 * must live in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Requests
 */

namespace FisHotel\Misc\Sections\Requests {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Requests
	 */
	class Requests {

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-requests';

		/**
		 * Capability for the page and every request write.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Capability for the Settings tab.
		 *
		 * @var string
		 */
		const SETTINGS_CAPABILITY = 'manage_options';

		/**
		 * The user meta Batch Manager and the theme keep the forum handle in.
		 *
		 * @var string[]
		 */
		const HANDLE_META = array( '_fishotel_humble_username', 'humble_fish_username' );

		/**
		 * The service of this request (built once).
		 *
		 * @var Service|null
		 */
		private static $service = null;

		/**
		 * Section base path.
		 *
		 * @return string
		 */
		public static function path() {
			return __DIR__ . '/';
		}

		/**
		 * Section base URL.
		 *
		 * @return string
		 */
		public static function url() {
			return FISHOTEL_MISC_URL . 'includes/sections/requests/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Requests', 'fishotel-misc-plugin' ),
				'description' => __( 'A request sheet for the fish people ask for: private links (nothing public), requests added by hand or by another section, approve with an optional fee that lands in the customer\'s wallet, a state that follows the fish through the Clean Room by itself, "your fish is ready" e-mails, a list grouped by fish with a "Needs you" strip, and a Demand tab.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-clipboard',
			);
		}

		/**
		 * Load every class file explicitly (no side effects).
		 */
		public static function load() {
			require_once __DIR__ . '/class-rules.php';
			require_once __DIR__ . '/class-tokens.php';
			require_once __DIR__ . '/class-urls.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-mailer.php';
			require_once __DIR__ . '/class-service.php';
			require_once __DIR__ . '/class-cleanroom.php';
			require_once __DIR__ . '/class-follow.php';
			require_once __DIR__ . '/class-fees.php';
			require_once __DIR__ . '/class-cron.php';
			require_once __DIR__ . '/class-listing.php';
			require_once __DIR__ . '/class-front.php';
			require_once __DIR__ . '/class-today-line.php';
		}

		/**
		 * Boot the section when it is enabled. The tables install on the
		 * first boot only — the version gate is an autoloaded option, so
		 * every later request pays no query here. Everything registered
		 * below is a hook whose callback returns at once unless a request
		 * link, a fee line or a paid order is in front of it: an ordinary
		 * front-end request costs no query from this section.
		 */
		public static function boot() {
			self::load();

			try {
				Store::maybe_install( is_admin() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			Cron::init();

			$fees = new Fees();
			$fees->init();

			$front = new Front();
			$front->init();

			if ( is_admin() ) {
				require_once __DIR__ . '/class-admin.php';

				$admin = new Admin();
				$admin->init();

				// Its line on the Today page: "Requests: 3 need you".
				Today_Line::register();
			}
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load();
			require_once __DIR__ . '/class-admin.php';

			add_submenu_page(
				'fishotel-misc',
				__( 'Requests', 'fishotel-misc-plugin' ),
				__( 'Requests', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin activation: install the tables (forced past the version
		 * gate, so a dropped table comes back) and arm the daily job.
		 */
		public static function activate() {
			self::load();

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			Cron::ensure_scheduled();
		}

		/**
		 * Plugin deactivation: drop the daily job. Every table and the
		 * settings are deliberately kept.
		 */
		public static function deactivate() {
			self::load();
			Cron::clear();
		}

		/* ========================================================== */
		/*  The service                                                */
		/* ========================================================== */

		/**
		 * The service wired to the real store, mailer and world.
		 *
		 * @param bool $fresh Build it again (the settings were just saved).
		 * @return Service
		 */
		public static function service( $fresh = false ) {
			if ( null === self::$service || $fresh ) {
				self::load();
				$store         = new Store();
				$settings      = Settings::get();
				self::$service = new Service( $store, $settings, new Mailer( $store, $settings ), self::world() );
			}
			return self::$service;
		}

		/**
		 * Use this service from now on (the tests hand in one that stands
		 * on an in-memory store).
		 *
		 * @param Service|null $service A service, or null to build the real one again.
		 */
		public static function use_service( $service ) {
			self::$service = $service;
		}

		/**
		 * The outside world the Service asks (see Service::$world).
		 *
		 * @return array<string, callable>
		 */
		public static function world() {
			return array(
				'fees'          => static function () {
					return Fees::status();
				},
				'user'          => array( self::class, 'user' ),
				'user_by_email' => static function ( $email ) {
					$user = function_exists( 'get_user_by' ) ? get_user_by( 'email', (string) $email ) : null;
					return is_object( $user ) && ! empty( $user->ID ) ? (int) $user->ID : 0;
				},
				'match_fish'    => array( self::class, 'match_fish' ),
				'product_url'   => array( self::class, 'product_url' ),
				'product_title' => static function ( $product_id ) {
					return function_exists( 'get_the_title' ) && (int) $product_id > 0 ? html_entity_decode( (string) get_the_title( (int) $product_id ), ENT_QUOTES, 'UTF-8' ) : '';
				},
			);
		}

		/**
		 * A site customer as the Service reads one.
		 *
		 * @param int $user_id User id.
		 * @return array{id: int, name: string, email: string, handle: string}|null
		 */
		public static function user( $user_id ) {
			$user = function_exists( 'get_userdata' ) && (int) $user_id > 0 ? get_userdata( (int) $user_id ) : null;
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				return null;
			}

			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
			if ( '' === $name ) {
				$name = (string) $user->display_name;
			}

			$handle = '';
			foreach ( self::HANDLE_META as $key ) {
				$value = get_user_meta( (int) $user->ID, $key, true );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$handle = trim( $value );
					break;
				}
			}

			return array(
				'id'     => (int) $user->ID,
				'name'   => $name,
				'email'  => (string) $user->user_email,
				'handle' => $handle,
			);
		}

		/**
		 * The species-list fish / product a typed name is: through the
		 * Clean Room when it is on, else a published product with exactly
		 * that title.
		 *
		 * @param string $fish The name as typed.
		 * @return array{species_id: int|null, product_id: int|null}
		 */
		public static function match_fish( $fish ) {
			$none = array(
				'species_id' => null,
				'product_id' => null,
			);

			$cleanroom = Cleanroom::make();
			if ( $cleanroom ) {
				return $cleanroom->match_name( (string) $fish );
			}

			if ( ! function_exists( 'get_posts' ) || '' === trim( (string) $fish ) ) {
				return $none;
			}
			$ids = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'title'            => trim( (string) $fish ),
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			if ( is_array( $ids ) && $ids ) {
				$none['product_id'] = (int) $ids[0];
			}
			return $none;
		}

		/**
		 * The public link of a request's product ('' unless it is published).
		 *
		 * @param array $request The request.
		 * @return string
		 */
		public static function product_url( array $request ) {
			$product_id = (int) ( $request['product_id'] ?? 0 );
			if ( $product_id <= 0 || ! function_exists( 'get_post_status' ) || 'publish' !== get_post_status( $product_id ) ) {
				return '';
			}
			$url = get_permalink( $product_id );
			return is_string( $url ) ? $url : '';
		}

		/**
		 * Whether the section is switched on in the FisHotel Tools
		 * dashboard (one autoloaded option — no query). The Section Manager
		 * loads this file for every section, enabled or not, so the global
		 * functions below ask this before they do anything.
		 *
		 * @return bool
		 */
		public static function enabled() {
			$key = class_exists( '\\FisHotel\\Misc\\Section_Manager', false ) ? \FisHotel\Misc\Section_Manager::OPTION_KEY : 'fishotel_misc_enabled_sections';
			return in_array( 'requests', (array) get_option( $key, array() ), true );
		}

		/**
		 * Create a request for another section (see fishotel_requests_create()).
		 *
		 * @param array $args See fishotel_requests_create().
		 * @return int|\WP_Error
		 */
		public static function create( array $args ) {
			self::load();
			if ( ! self::enabled() ) {
				return new \WP_Error( 'fishotel_requests_unavailable', __( 'The Requests section is switched off.', 'fishotel-misc-plugin' ) );
			}
			if ( ! Store::ready() ) {
				return new \WP_Error( 'fishotel_requests_unavailable', __( 'The Requests tables are not installed.', 'fishotel-misc-plugin' ) );
			}

			// The size a caller names is checked against the standard list when there is one.
			if ( ! array_key_exists( 'sizes', $args ) && ! empty( $args['size_slug'] ) ) {
				$cleanroom = Cleanroom::make();
				$sizes     = $cleanroom ? $cleanroom->sizes() : null;
				if ( is_array( $sizes ) ) {
					foreach ( $sizes as $size ) {
						if ( (string) $size['slug'] === (string) $args['size_slug'] ) {
							$args['sizes'] = $sizes;
							break;
						}
					}
				}
			}

			$actor = Service::system();
			if ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 && function_exists( 'wp_get_current_user' ) ) {
				$user  = wp_get_current_user();
				$actor = Service::staff( (int) $user->ID, (string) $user->user_login );
			}

			return self::service()->create( $args, $actor );
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_requests_create' ) ) {
		/**
		 * Create a fish request from another section or plugin.
		 *
		 * The function exists whenever the Misc plugin is active, so guard
		 * it with function_exists(); while the Requests section is switched
		 * off (or its tables are not installed) it answers a WP_Error and
		 * creates nothing, so check is_wp_error() too.
		 *
		 * What happens depends on the source:
		 *   'lost_fish'  a customer who chose "try again" after a lost fish:
		 *                the request is owed (already paid for), never has a
		 *                fee, and goes on the list ("waiting") at once.
		 *   'hand'       (the default) approved as it is saved: "waiting", or
		 *                "awaiting_payment" when a fee is given (the invoice is
		 *                e-mailed; fees need Batch Manager and an e-mail address).
		 *   'link' / 'public'  waits for the owner's approval ("new").
		 * No e-mail is sent for a request without a fee.
		 *
		 * @param array $args {
		 *     @type int    $user_id    Site customer (0 = none; name / e-mail / handle default to theirs).
		 *     @type string $name       Name (a name or a handle is required without a customer).
		 *     @type string $email      E-mail address.
		 *     @type string $handle     Forum handle.
		 *     @type string $fish       The fish as typed (required, unless product_id names a product).
		 *     @type int    $species_id Clean Room species-list id, when known.
		 *     @type int    $product_id Product id, when known.
		 *     @type string $size_slug  A standard size's slug, with $size_label its label.
		 *     @type int    $qty        Quantity (1–99, default 1).
		 *     @type string $note       A note.
		 *     @type string $source     link | hand | lost_fish | public (default hand).
		 *     @type string $came_from  One of the "came from" list in Settings.
		 *     @type bool   $owed       Already paid for (always true for lost_fish).
		 *     @type float  $fee        A fee (ignored when owed).
		 *     @type int    $order_id   The WooCommerce order it came from.
		 * }
		 * @return int|WP_Error The request id, or what is wrong.
		 */
		function fishotel_requests_create( array $args ) {
			return \FisHotel\Misc\Sections\Requests\Requests::create( $args );
		}
	}

	if ( ! function_exists( 'fishotel_requests_get' ) ) {
		/**
		 * One request as an array (id, state, fish, qty, owed, user_id,
		 * name, email, …), or null (also while the section is switched
		 * off). Guard with function_exists().
		 *
		 * @param int $request_id Request id.
		 * @return array|null
		 */
		function fishotel_requests_get( $request_id ) {
			if ( ! \FisHotel\Misc\Sections\Requests\Requests::enabled() ) {
				return null;
			}
			return \FisHotel\Misc\Sections\Requests\Requests::service()->store->get( (int) $request_id );
		}
	}
}
