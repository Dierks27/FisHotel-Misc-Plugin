<?php
/**
 * Packing section — which bag every fish goes in, and which box an order
 * fits in.
 *
 * WHY this exists: the owner packs every fish in one of a handful of
 * standard bags and knows from experience which box an order needs. That
 * knowledge has to be written down before anybody else can pack: a bag
 * size on every fish variation (a record only — never on the storefront;
 * asked for livestock only, not for medication or dry goods),
 * how many bags of each size fit each standard box, and from those two a
 * suggested box on every order, flagged "on the line" when it is nearly
 * full so the owner confirms it.
 *
 * Architecture:
 *   - class-settings.php       the ONE option (bags, own boxes, how many
 *                              fit, the size → bag map, the threshold):
 *                              pure sanitisers, and the four saves — each
 *                              checks the capability and the nonce itself
 *                              and answers with a status, so it runs
 *                              headless; the AJAX handlers only reply.
 *   - class-size-list.php      the fish sizes: the Clean Room's list when
 *                              that section is there, else the product
 *                              attribute whose name or slug contains
 *                              "size".
 *   - class-boxes.php          the standard boxes: ShipTracker's when its
 *                              build provides them, else this section's
 *                              own list; "how many fit" looked up per box.
 *   - class-suggest.php        PURE: bags needed → the smallest box that
 *                              holds them, the split when none does, the
 *                              fill, "on the line".
 *   - class-bags.php           a product's / an order line's bag: its own
 *                              value, else the size → bag map.
 *   - class-livestock.php      "Livestock — packed in a bag": the ONE rule
 *                              that says whether a product is bagged at all
 *                              (the checkbox on the product, else the fish
 *                              categories).
 *   - class-lines.php          an order's fish lines (the pick list's
 *                              rules), what is ignored, what is skipped.
 *   - class-order-packing.php  the suggestion of an order, the confirmed
 *                              box (order meta through the order API).
 *   - class-store.php          the ONLY file that touches $wpdb — reads
 *                              only: the size attribute and its terms, the
 *                              variations without a bag size, bag usage.
 *   - class-fill.php           "Fill empty ones from the map": preview,
 *                              then set only what has no value.
 *   - class-product.php        the "Livestock — packed in a bag" checkbox and
 *                              the Bag size dropdown in the product editor.
 *   - class-order-box.php      the Packing box on the order screen (legacy
 *                              and HPOS) and its Confirm / change AJAX.
 *   - class-admin.php          the page under FisHotel Tools.
 *
 * Nothing is stored in a table of its own: the lists are one option that
 * is not autoloaded and is read only by this section's admin screens and
 * its contract functions. The section registers no hook at all outside
 * wp-admin, so an ordinary front-end request costs no query and no option
 * read here.
 *
 * The file uses bracketed namespaces because the contract functions live
 * in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Packing
	 */
	class Packing {

		/**
		 * The option holding every list of this section (not autoloaded).
		 *
		 * @var string
		 */
		const OPTION_KEY = 'fishotel_packing_settings';

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-packing';

		/**
		 * Capability for the page, every save, the product field and the
		 * order box.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Product / variation meta: the bag size (a bag id). Hidden — the
		 * leading underscore keeps it out of everything WooCommerce shows
		 * a customer.
		 *
		 * @var string
		 */
		const META_BAG = '_fh_bag_size';

		/**
		 * Product meta (on the parent product, never on a variation):
		 * "Livestock — packed in a bag" — 'yes' or 'no'; absent = follow
		 * the default (the fish categories). Hidden, like the bag size.
		 *
		 * @var string
		 */
		const META_PACKS = '_fh_packs_in_bag';

		/**
		 * Order meta: the confirmed box (a JSON record) and its name as
		 * plain text.
		 *
		 * @var string
		 */
		const META_BOX      = '_fh_packing_box';
		const META_BOX_NAME = '_fh_packing_box_name';

		/**
		 * Whether the section booted in this request (it is enabled).
		 *
		 * @var bool
		 */
		private static $booted = false;

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
			return FISHOTEL_MISC_URL . 'includes/sections/packing/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Packing', 'fishotel-misc-plugin' ),
				'description' => __( 'Which bag every fish goes in and which box an order fits in: the standard bags, a Bag size on every fish variation (a record only — never on the storefront), a size → bag map with a fill tool, how many bags fit each standard box, and a Packing box on every order that suggests the smallest box that holds it, flagged when it is on the line, for the owner to confirm.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-archive',
			);
		}

		/**
		 * Load the classes the contracts and the admin side share (no side
		 * effects, no query).
		 */
		public static function load() {
			require_once __DIR__ . '/class-size-list.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-boxes.php';
			require_once __DIR__ . '/class-suggest.php';
			require_once __DIR__ . '/class-bags.php';
			require_once __DIR__ . '/class-livestock.php';
			require_once __DIR__ . '/class-lines.php';
			require_once __DIR__ . '/class-order-packing.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-fill.php';
		}

		/**
		 * Load the admin classes.
		 */
		public static function load_admin() {
			self::load();
			require_once __DIR__ . '/class-product.php';
			require_once __DIR__ . '/class-order-box.php';
			require_once __DIR__ . '/class-admin.php';
		}

		/**
		 * Boot the section when it is enabled. Outside wp-admin nothing is
		 * loaded and nothing is hooked: the bag size is a record, so the
		 * storefront, the cart, the checkout, the e-mails and the REST API
		 * never hear of this section. (admin-ajax.php counts as wp-admin,
		 * which is where the product editor and the order screen save.)
		 */
		public static function boot() {
			self::$booted = true;

			if ( ! is_admin() ) {
				return;
			}

			self::load_admin();

			$settings = new Settings();
			$settings->init();

			$fill = new Fill();
			$fill->init();

			$product = new Product();
			$product->init();

			$box = new Order_Box();
			$box->init();

			$admin = new Admin();
			$admin->init();
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load_admin();

			add_submenu_page(
				'fishotel-misc',
				__( 'Packing', 'fishotel-misc-plugin' ),
				__( 'Packing', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback. There is no table and no cron event:
		 * nothing to install.
		 */
		public static function activate() {
			// Nothing to do — the lists are one option, created by the first save.
		}

		/**
		 * Plugin-deactivation callback. The settings, the bag sizes on the
		 * products and the confirmed boxes on the orders are deliberately
		 * kept.
		 */
		public static function deactivate() {
			// Nothing to tear down.
		}

		/**
		 * Whether the section is enabled (it booted in this request).
		 *
		 * @return bool
		 */
		public static function enabled() {
			return self::$booted;
		}

		/**
		 * Mark the section as booted without registering a hook — the
		 * contracts then answer. For the tests and for code that loads the
		 * section by hand.
		 *
		 * @param bool $booted Whether it counts as enabled.
		 */
		public static function set_enabled( $booted = true ) {
			self::$booted = (bool) $booted;
		}

		/**
		 * The answer of fishotel_packing_suggest() when there is nothing to
		 * say: every key of a real answer, empty, with `available` false
		 * and the reason.
		 *
		 * @param int    $order_id Order ID.
		 * @param string $reason   disabled|no_woocommerce|order_not_found|error.
		 * @return array
		 */
		public static function unavailable( $order_id, $reason ) {
			return array(
				'available'      => false,
				'reason'         => (string) $reason,
				'order_id'       => (int) $order_id,
				'status'         => 'unavailable',
				'boxes'          => array(),
				'split'          => false,
				'on_the_line'    => false,
				'threshold'      => 90,
				'bags'           => array(),
				'bag_total'      => 0,
				'unplaced'       => array(),
				'lines'          => array(),
				'incomplete'     => array(),
				'complete'       => false,
				'ignored'        => array(),
				'skipped'        => array(),
				'box_source'     => '',
				'is_fulfillment' => false,
				'sources'        => array(),
				'packed_with'    => 0,
				'confirmed'      => null,
				'messages'       => array(),
			);
		}

		/**
		 * fishotel_packing_suggest(): the suggestion of an order, or the
		 * "unavailable" answer — never an exception.
		 *
		 * @param int $order_id Order ID.
		 * @return array
		 */
		public static function suggest( $order_id ) {
			$order_id = is_scalar( $order_id ) ? (int) $order_id : 0;

			if ( ! self::$booted ) {
				$result = self::unavailable( $order_id, 'disabled' );
			} else {
				try {
					self::load();
					$result = Order_Packing::suggest( $order_id );
				} catch ( \Throwable $e ) {
					$result = self::unavailable( $order_id, 'error' );
				}
			}

			$filtered = apply_filters( 'fishotel_packing_suggest', $result, $order_id );

			return is_array( $filtered ) ? $filtered : $result;
		}

		/**
		 * fishotel_packing_bag_for_item(): the bag of one order line.
		 *
		 * @param mixed $order_item A WC_Order_Item_Product (anything else gives null).
		 * @return array|null
		 */
		public static function bag_for_item( $order_item ) {
			$bag = null;

			if ( self::$booted && is_object( $order_item ) ) {
				try {
					self::load();
					$bag = Bags::for_item( $order_item );
				} catch ( \Throwable $e ) {
					$bag = null;
				}
			}

			$filtered = apply_filters( 'fishotel_packing_bag_for_item', $bag, $order_item );

			return is_array( $filtered ) ? $filtered : null;
		}

		/**
		 * fishotel_packing_bag_for_product(): the bag of a product or a
		 * variation.
		 *
		 * @param mixed $product A product id or a WC_Product.
		 * @return array|null
		 */
		public static function bag_for_product( $product ) {
			$bag = null;

			if ( self::$booted ) {
				try {
					self::load();
					$bag = Bags::for_product( $product );
				} catch ( \Throwable $e ) {
					$bag = null;
				}
			}

			$filtered = apply_filters( 'fishotel_packing_bag_for_product', $bag, $product );

			return is_array( $filtered ) ? $filtered : null;
		}

		/**
		 * fishotel_packing_bag_for_size(): the bag the size → bag map gives
		 * a standard size.
		 *
		 * @param mixed $size A size slug, or its label.
		 * @return array|null
		 */
		public static function bag_for_size( $size ) {
			$bag = null;

			if ( self::$booted && is_scalar( $size ) && ! is_bool( $size ) ) {
				try {
					self::load();
					$bag = Bags::for_size( (string) $size );
				} catch ( \Throwable $e ) {
					$bag = null;
				}
			}

			$filtered = apply_filters( 'fishotel_packing_bag_for_size', $bag, $size );

			return is_array( $filtered ) ? $filtered : null;
		}

		/**
		 * fishotel_packing_confirmed_box(): the box confirmed on an order.
		 *
		 * @param int $order_id Order ID.
		 * @return array|null
		 */
		public static function confirmed_box( $order_id ) {
			$order_id = is_scalar( $order_id ) ? (int) $order_id : 0;
			$box      = null;

			if ( self::$booted && $order_id > 0 ) {
				try {
					self::load();
					$box = Order_Packing::confirmed_for( $order_id );
				} catch ( \Throwable $e ) {
					$box = null;
				}
			}

			$filtered = apply_filters( 'fishotel_packing_confirmed_box', $box, $order_id );

			return is_array( $filtered ) ? $filtered : null;
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_packing_suggest' ) ) {
		/**
		 * The packing suggestion of an order: its fish lines with their
		 * bags, the bags needed by size, and the box (or boxes) that hold
		 * them.
		 *
		 * Always an array with the same keys. `available` is false — and
		 * everything else empty — while the Packing section is switched
		 * off, WooCommerce is not loaded or the order does not exist
		 * (`reason` says which). Guard the call with function_exists(): the
		 * function is declared by the Misc plugin.
		 *
		 *   available      bool
		 *   order_id       int
		 *   status         fits (one box holds every bag) | split (several
		 *                  do) | partial (boxes for what can be boxed — some
		 *                  bags go in no box, see unplaced) | no_fit (no bag
		 *                  goes in any box) | no_boxes | empty (no bags
		 *                  needed) | unavailable
		 *   boxes          the suggested box(es): key, name, length, width,
		 *                  height (inches), dims ("LxWxH"), volume, source,
		 *                  bags (bag id → how many go in it), fill (1.0 =
		 *                  full), fill_pct, on_the_line
		 *   split          bool — more than one box is suggested
		 *   on_the_line    bool — a suggested box is at or over the threshold
		 *   threshold      the "on the line" percent
		 *   bags           bags needed by size: id, name, width, length, count
		 *   bag_total      int
		 *   unplaced       bags no box takes: id, name, count
		 *   lines          every fish line: item_id, order_id, name, qty,
		 *                  fish_per_bag, bags, bag (null without one), size,
		 *                  size_label, flag
		 *   incomplete     the fish lines with no bag size: item_id,
		 *                  order_id, name, qty, reason
		 *   complete       bool — every fish line has a bag size
		 *   ignored        lines that are not fish, or whose product is not
		 *                  packed in a bag: item_id, order_id, name, qty,
		 *                  reason
		 *   skipped        lines left out: already shipped, never shipping,
		 *                  refunded
		 *   box_source     shiptracker | own
		 *   is_fulfillment bool — a combined box (its lines are its live
		 *                  source orders')
		 *   sources        the source order ids of a combined box
		 *   packed_with    the combined box an order went into (0 = none):
		 *                  the box is suggested and confirmed there
		 *   confirmed      fishotel_packing_confirmed_box() of the order
		 *   messages       what a person should read, as sentences
		 *
		 * @param int $order_id Order ID.
		 * @return array
		 */
		function fishotel_packing_suggest( $order_id ): array {
			return \FisHotel\Misc\Sections\Packing\Packing::suggest( $order_id );
		}
	}

	if ( ! function_exists( 'fishotel_packing_bag_for_item' ) ) {
		/**
		 * The bag one order line is packed in — for the pick list. Null
		 * when the line has no bag size, when its product is not packed in
		 * a bag ("Livestock — packed in a bag" unticked; with no stored
		 * choice, not in a fish category), or the section is off: id, name,
		 * width, length (inches, '' when not given), label ("Medium
		 * (6 × 12 in)"), sort, source (own = set on the variation or the
		 * product, map = from the size → bag map), size, size_label.
		 *
		 * @param mixed $order_item A WC_Order_Item_Product.
		 * @return array|null
		 */
		function fishotel_packing_bag_for_item( $order_item ): ?array {
			return \FisHotel\Misc\Sections\Packing\Packing::bag_for_item( $order_item );
		}
	}

	if ( ! function_exists( 'fishotel_packing_bag_for_product' ) ) {
		/**
		 * The bag of a product or a variation (its own value, else the size
		 * → bag map) — the same shape as fishotel_packing_bag_for_item().
		 * Null for a product that is not packed in a bag: "Livestock —
		 * packed in a bag" unticked on it (on the parent, for a variation),
		 * or, with no stored choice, not in a fish category.
		 *
		 * @param mixed $product A product id or a WC_Product.
		 * @return array|null
		 */
		function fishotel_packing_bag_for_product( $product ): ?array {
			return \FisHotel\Misc\Sections\Packing\Packing::bag_for_product( $product );
		}
	}

	if ( ! function_exists( 'fishotel_packing_bag_for_size' ) ) {
		/**
		 * The bag the size → bag map gives a standard fish size — for a
		 * fish that is not a shop product (an invoice line, an unlisted
		 * fish). Same shape, source "map".
		 *
		 * @param mixed $size The size's slug, or its label.
		 * @return array|null
		 */
		function fishotel_packing_bag_for_size( $size ): ?array {
			return \FisHotel\Misc\Sections\Packing\Packing::bag_for_size( $size );
		}
	}

	if ( ! function_exists( 'fishotel_packing_confirmed_box' ) ) {
		/**
		 * The box confirmed on an order, or null: boxes (each name,
		 * length, width, height, dims, volume), count, name ("Medium box",
		 * "Large box + Small box"), the first box's length / width /
		 * height / dims, fill (as it stood when confirmed), suggested (the
		 * choice was the suggestion), complete, by (user id), by_name, at
		 * (UTC, Y-m-d H:i:s). An order that went into a combined box has
		 * none of its own — ask the combined box (fishotel_packing_suggest()
		 * names it in `packed_with`).
		 *
		 * @param int $order_id Order ID.
		 * @return array|null
		 */
		function fishotel_packing_confirmed_box( $order_id ): ?array {
			return \FisHotel\Misc\Sections\Packing\Packing::confirmed_box( $order_id );
		}
	}
}
