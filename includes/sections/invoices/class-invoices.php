<?php
/**
 * Invoices section — build a customer's invoice by picking fish, and
 * present it the way invoices have always been presented here: as a
 * hidden WooCommerce product the customer opens by link, adds to the
 * cart, shops on with, and checks out.
 *
 * WHY this exists: an invoice used to be typed by hand — a product
 * titled "<Handle> Invoice (M/D/YY)", its description a list of "Fish xN
 * — $price", its price the total, stock 1 so it cannot be paid twice —
 * and the rules that keep a customer from being charged twice lived in a
 * written procedure. This section picks the fish (from the tanks, the
 * shop, the species list, or typed), does the sums, writes that same
 * product, and enforces the procedure in code: read before you touch,
 * never change an invoice a customer already has, no fish on two
 * invoices, never touch a sale price, stock 1, credits on the NEW
 * invoice, Trash instead of delete.
 *
 * The invoice stays a product in the cart. Invoices made by hand keep
 * working untouched and are listed read-only.
 *
 * Architecture:
 *   - class-store.php      the ONLY $wpdb file: fh_invoices and
 *                          fh_invoice_lines via dbDelta behind the
 *                          autoloaded fishotel_invoices_db_version, and two
 *                          reads of WooCommerce's order-line tables.
 *   - class-lines.php      PURE: a line, the money in cents, the flat
 *                          total as a Discount / Adjustment line, the
 *                          description and the theme's JSON.
 *   - class-grammar.php    PURE: the theme's invoice grammar once more —
 *                          how a hand-made invoice is read.
 *   - class-format.php     PURE: title, short description, image by
 *                          threshold, the forum list.
 *   - class-rules.php      PURE: paid / locked / editable / trashable, the
 *                          fish-on-two-invoices check, validation.
 *   - class-settings.php   the one option (category, formats, shipping
 *                          map, images and threshold).
 *   - class-shop.php       every WooCommerce call (products, orders,
 *                          terms, customers) — the tests use a twin.
 *   - class-tanks.php      the Clean Room section, when it is on: tank
 *                          groups and the species list.
 *   - class-search.php     the picker's one search box, in groups.
 *   - class-precheck.php   the pre-check: the customer's orders and
 *                          invoices, the duplicates, what will be written.
 *   - class-publisher.php  save a draft, pre-check, publish, edit an
 *                          unpaid invoice, Trash, withdraw a draft.
 *   - class-sync.php       an order paid → the invoice paid, the action
 *                          fired; cancelled / failed → released.
 *   - class-visibility.php an invoice is found by its link only: out of
 *                          the sitemap and the REST product lists, noindex.
 *   - class-admin.php      FisHotel Tools → Invoices: the list, the
 *                          picker, the forum list, Settings; the AJAX.
 *
 * CONTRACTS other sections and plugins can rely on (see README.md):
 *   fishotel_invoices_lines_for_product( int $product_id ): array
 *   fishotel_invoices_for_order( int $order_id ): array
 *   do_action( 'fishotel_invoice_paid', $invoice_id, $order_id )
 *   do_action( 'fishotel_invoice_released', $invoice_id, $order_id )
 *
 * The file uses bracketed namespaces because the two contract functions
 * must live in the global namespace.
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Class Invoices
	 */
	class Invoices {

		/**
		 * Admin submenu slug.
		 *
		 * @var string
		 */
		const MENU_SLUG = 'fishotel-misc-invoices';

		/**
		 * Capability for every screen and every write of the section.
		 *
		 * @var string
		 */
		const CAPABILITY = 'manage_woocommerce';

		/**
		 * Product meta: the id of the invoice (this section's) that created
		 * the product. A product without it was made by hand and is never
		 * changed here.
		 *
		 * @var string
		 */
		const PRODUCT_MARKER_META = '_fh_invoice_id';

		/**
		 * The theme's meta (inc/invoice-lines.php), read here and — the
		 * lines JSON only — written: the lines on the product, the order
		 * that bought it, and on order lines the invoice they were made
		 * out of.
		 */
		const PRODUCT_LINES_META = '_fh_invoice_lines';
		const PRODUCT_ORDER_META = '_fh_invoice_order_id';
		const ITEM_PRODUCT_META  = '_fh_invoice_product_id';
		const ITEM_TITLE_META    = '_fh_invoice_title';
		const ITEM_FISH_META     = '_fh_invoice_fish';
		const ITEM_CREDIT_META   = '_fh_invoice_credit';
		const ITEM_LINES_META    = '_fh_invoice_lines';

		/**
		 * Order meta of a fulfilment (combined-box) copy of other orders:
		 * such an order mirrors lines and never pays for an invoice.
		 *
		 * @var string
		 */
		const MIRROR_META = '_fishotel_is_fulfillment';

		/**
		 * Fired once when an invoice becomes paid: ( $invoice_id, $order_id ).
		 *
		 * @var string
		 */
		const PAID_ACTION = 'fishotel_invoice_paid';

		/**
		 * Fired once when a paid invoice is released again because its
		 * order was cancelled or failed: ( $invoice_id, $order_id ).
		 *
		 * @var string
		 */
		const RELEASED_ACTION = 'fishotel_invoice_released';

		/**
		 * Source name in the Health log.
		 *
		 * @var string
		 */
		const LOG_SOURCE = 'invoices';

		/**
		 * The name of the line that brings the lines UP to a stated price
		 * (Lines::ADJUSTMENT_NAME — kept here as well because this file is
		 * the one that is loaded while the section is switched off). The
		 * theme knows fish, credits and notes only, so that line reaches it
		 * as a fish line: on the product's lines JSON, on an order's
		 * "Includes" snapshot and as a line of an order it is
		 * { name: "Adjustment", qty: 1, kind: "fish" } with no product —
		 * money, not a fish. Its name is the one thing that says so
		 * outside this section: see is_adjustment().
		 *
		 * @var string
		 */
		const ADJUSTMENT_NAME = 'Adjustment';

		/**
		 * Whether the section is switched on (boot() ran). The contract
		 * functions answer nothing while it is off.
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * A store and a shop the contract functions use instead of the real
		 * ones (use_doubles()); null in production.
		 *
		 * @var array{0: object, 1: object|null}|null
		 */
		private static $doubles = null;

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
			return FISHOTEL_MISC_URL . 'includes/sections/invoices/';
		}

		/**
		 * Section metadata for the Section Manager.
		 *
		 * @return array{name: string, description: string, icon: string}
		 */
		public static function get_section_info() {
			return array(
				'name'        => __( 'Invoices', 'fishotel-misc-plugin' ),
				'description' => __( 'Build a customer\'s invoice by picking fish — from the tanks, the shop, the species list or typed — and publish it as the hidden product the customer adds to the cart. A pre-check of the customer\'s orders and invoices before every publish, no fish on two invoices, paid invoices locked, stock 1, credits on the new invoice, Trash instead of delete, and the forum list to paste.', 'fishotel-misc-plugin' ),
				'icon'        => 'dashicons-media-spreadsheet',
			);
		}

		/**
		 * Load every class the section needs outside wp-admin (no side
		 * effects). Nothing here depends on the autoloader's directory
		 * mapping.
		 */
		public static function load() {
			require_once __DIR__ . '/class-lines.php';
			require_once __DIR__ . '/class-grammar.php';
			require_once __DIR__ . '/class-format.php';
			require_once __DIR__ . '/class-rules.php';
			require_once __DIR__ . '/class-settings.php';
			require_once __DIR__ . '/class-store.php';
			require_once __DIR__ . '/class-shop.php';
			require_once __DIR__ . '/class-sync.php';
			require_once __DIR__ . '/class-visibility.php';
			require_once __DIR__ . '/class-today-line.php';
		}

		/**
		 * Load the classes of the screens and their AJAX.
		 */
		public static function load_admin() {
			self::load();
			require_once __DIR__ . '/class-tanks.php';
			require_once __DIR__ . '/class-search.php';
			require_once __DIR__ . '/class-precheck.php';
			require_once __DIR__ . '/class-publisher.php';
			require_once __DIR__ . '/class-admin.php';
		}

		/**
		 * Boot the section when it is enabled. The tables install on the
		 * first boot only — the version gate is an autoloaded option, so
		 * every later request pays no query here. The paid sync listens to
		 * WooCommerce's order hooks and does nothing (not a query) until
		 * an order is paid, cancelled or failed; Visibility keeps invoices
		 * out of the sitemap and the REST product lists and asks nothing
		 * until one of those is built.
		 */
		public static function boot() {
			self::load();
			self::$booted = true;

			try {
				Store::maybe_install( is_admin() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			Sync::init();
			Visibility::init();
			add_filter( 'woocommerce_duplicate_product_exclude_meta', array( self::class, 'duplicate_exclude_meta' ), 10, 2 );

			if ( is_admin() ) {
				self::load_admin();

				$admin = new Admin();
				$admin->init();

				// Its line on the Today page: invoices published and not paid for a while.
				Today_Line::register();
			}
		}

		/**
		 * Register the admin submenu.
		 */
		public static function register_menu() {
			self::load_admin();

			add_submenu_page(
				'fishotel-misc',
				__( 'Invoices', 'fishotel-misc-plugin' ),
				__( 'Invoices', 'fishotel-misc-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( new Admin(), 'render_page' )
			);
		}

		/**
		 * Plugin-activation callback: install the tables (forced past the
		 * version gate, so a dropped table comes back).
		 */
		public static function activate() {
			self::load();

			try {
				Store::install( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Plugin-deactivation callback: nothing to undo — there is no cron
		 * and no cache, and the tables are deliberately kept.
		 */
		public static function deactivate() {
		}

		/**
		 * WooCommerce's "Duplicate" of a product: a copy of an invoice
		 * built here must not pass for that invoice. Without this the copy
		 * kept the invoice's mark and its lines for the theme — retitled
		 * for another customer it was listed nowhere, the pre-check for
		 * that customer did not see it, and the theme itemised it with the
		 * first customer's fish. So the mark never travels, and the lines
		 * JSON does not travel with it: the copy is an invoice made by
		 * hand, read from its description. A product that does not carry
		 * the mark is copied exactly as before.
		 *
		 * @param mixed $exclude  Meta keys left out of the copy so far.
		 * @param mixed $existing The meta keys the product has.
		 * @return array
		 */
		public static function duplicate_exclude_meta( $exclude, $existing = array() ) {
			$exclude   = is_array( $exclude ) ? $exclude : array();
			$exclude[] = self::PRODUCT_MARKER_META;
			if ( is_array( $existing ) && in_array( self::PRODUCT_MARKER_META, $existing, true ) ) {
				$exclude[] = self::PRODUCT_LINES_META;
			}
			return array_values( array_unique( $exclude ) );
		}

		/**
		 * Whether the section is switched on.
		 *
		 * @return bool
		 */
		public static function is_on() {
			return self::$booted || null !== self::$doubles;
		}

		/**
		 * Answer the contract functions from this store and shop from now
		 * on — the tests hand in their twins, so another section's real
		 * reader can be run against fishotel_invoices_for_order() itself.
		 * Null puts the real ones back.
		 *
		 * @param object|null $store A store, or null.
		 * @param object|null $shop  A shop, or null for the real one.
		 */
		public static function use_doubles( $store = null, $shop = null ) {
			self::$doubles = null === $store ? null : array( $store, $shop );
		}

		/**
		 * Today's calendar date on the site's clock.
		 *
		 * @return string Y-m-d
		 */
		public static function today() {
			return (string) current_time( 'Y-m-d' );
		}

		/**
		 * The site zone.
		 *
		 * @return \DateTimeZone
		 */
		public static function timezone() {
			if ( function_exists( 'wp_timezone' ) ) {
				return wp_timezone();
			}
			return new \DateTimeZone( 'UTC' );
		}

		/**
		 * Write to the Health log when that section is on (always safe).
		 *
		 * @param string $level   info|warning|error.
		 * @param string $message Message.
		 * @param array  $context Context.
		 */
		public static function log( $level, $message, array $context = array() ) {
			do_action( 'fishotel_log', (string) $level, self::LOG_SOURCE, (string) $message, $context );
		}

		/* ---------------------------------------------------------- */
		/*  Contracts                                                  */
		/* ---------------------------------------------------------- */

		/**
		 * One line the way the contract functions hand it out: every
		 * field of the stored line, with every id it kept.
		 *
		 * @param array $line A stored line (Store::hydrate_line()).
		 * @return array
		 */
		public static function contract_line( array $line ) {
			return array(
				'id'           => (int) ( isset( $line['id'] ) ? $line['id'] : 0 ),
				'invoice_id'   => (int) ( isset( $line['invoice_id'] ) ? $line['invoice_id'] : 0 ),
				'kind'         => (string) ( isset( $line['kind'] ) ? $line['kind'] : 'fish' ),
				'name'         => Lines::public_name( $line ),
				'qty'          => max( 1, (int) ( isset( $line['qty'] ) ? $line['qty'] : 1 ) ),
				'unit_price'   => isset( $line['unit_price'] ) && null !== $line['unit_price'] ? (float) $line['unit_price'] : null,
				'amount'       => Lines::amount( $line ),
				'product_id'   => ! empty( $line['product_id'] ) ? (int) $line['product_id'] : null,
				'variation_id' => ! empty( $line['variation_id'] ) ? (int) $line['variation_id'] : null,
				'species_id'   => ! empty( $line['species_id'] ) ? (int) $line['species_id'] : null,
				'stay_id'      => ! empty( $line['stay_id'] ) ? (int) $line['stay_id'] : null,
				'size_slug'    => isset( $line['size_slug'] ) && '' !== (string) $line['size_slug'] ? (string) $line['size_slug'] : null,
				'size_label'   => isset( $line['size_label'] ) && '' !== (string) $line['size_label'] ? (string) $line['size_label'] : null,
				'source'       => (string) ( isset( $line['source'] ) ? $line['source'] : 'typed' ),
				'take_stock'   => ! empty( $line['take_stock'] ),
				'sort'         => (int) ( isset( $line['sort'] ) ? $line['sort'] : 0 ),
			);
		}

		/**
		 * Whether a name is the "Adjustment" line's (see ADJUSTMENT_NAME):
		 * what every reader of an invoice's fish lines asks before it
		 * counts one — on an order line flagged `_fh_invoice_fish`, on a
		 * fish row of the `_fh_invoice_lines` JSON. Pure, and answers the
		 * same whether the section is switched on or off: the orders it
		 * wrote stay.
		 *
		 * @param mixed $name The line's name.
		 * @return bool
		 */
		public static function is_adjustment( $name ) {
			return is_scalar( $name ) && ! is_bool( $name ) && 0 === strcasecmp( self::ADJUSTMENT_NAME, trim( (string) $name ) );
		}

		/**
		 * The lines of a hand-made invoice (read by the theme's grammar)
		 * in the contract's shape: no ids but a product id the JSON named.
		 * An "Adjustment" — a fish row to the theme — is handed out as what
		 * it is, a `discount` line with a positive amount, as it is for an
		 * invoice built here.
		 *
		 * @param array[] $rows Grammar rows.
		 * @return array[]
		 */
		public static function contract_hand_lines( array $rows ) {
			$out = array();
			foreach ( array_values( $rows ) as $index => $row ) {
				$kind  = (string) ( isset( $row['kind'] ) ? $row['kind'] : 'note' );
				$price = isset( $row['price'] ) && null !== $row['price'] ? (float) $row['price'] : null;
				$qty   = max( 1, (int) ( isset( $row['qty'] ) ? $row['qty'] : 1 ) );
				if ( 'fish' === $kind && 1 === $qty && empty( $row['product_id'] ) && self::is_adjustment( isset( $row['name'] ) ? $row['name'] : '' ) ) {
					$kind = 'discount';
				}
				$out[] = array(
					'id'           => 0,
					'invoice_id'   => 0,
					'kind'         => $kind,
					'name'         => (string) ( isset( $row['name'] ) ? $row['name'] : '' ),
					'qty'          => $qty,
					'unit_price'   => $price,
					'amount'       => null === $price ? 0.0 : Lines::from_cents( ( 'fish' === $kind ? $qty : 1 ) * Lines::cents( $price ) ),
					'product_id'   => ! empty( $row['product_id'] ) ? (int) $row['product_id'] : null,
					'variation_id' => null,
					'species_id'   => null,
					'stay_id'      => null,
					'size_slug'    => null,
					'size_label'   => null,
					'source'       => 'hand',
					'take_stock'   => ! empty( $row['product_id'] ),
					'sort'         => $index,
				);
			}
			return $out;
		}

		/**
		 * The lines of the invoice a product is (see
		 * fishotel_invoices_lines_for_product()).
		 *
		 * @param int         $product_id Product id.
		 * @param object|null $store      A store to use instead of the real one (the tests' twin).
		 * @param object|null $shop       A shop to use instead of the real one.
		 * @return array[]
		 */
		public static function lines_for_product( $product_id, $store = null, $shop = null ) {
			$product_id = (int) $product_id;
			if ( null === $store && null !== self::$doubles ) {
				list( $store, $shop ) = self::$doubles;
			}
			if ( $product_id <= 0 || ( ! self::$booted && null === $store ) ) {
				return array();
			}

			try {
				self::load();
				$store = $store ? $store : new Store();
				$row   = $store->invoice_by_product( $product_id );
				if ( $row ) {
					return array_map( array( self::class, 'contract_line' ), $store->lines( $row['id'] ) );
				}

				$shop    = $shop ? $shop : new Shop();
				$product = $shop->product( $product_id );
				if ( ! $product || ! Grammar::classify_invoice( $product['category_slugs'], $product['name'], Settings::get()['category_slug'] ) ) {
					return array();
				}
				return self::contract_hand_lines( Grammar::product_lines( $product['lines_json'], $product['description'] ) );
			} catch ( \Throwable $e ) {
				return array();
			}
		}

		/**
		 * The invoices in an order, with their lines (see
		 * fishotel_invoices_for_order()).
		 *
		 * @param int         $order_id Order id.
		 * @param object|null $store    A store to use instead of the real one (the tests' twin).
		 * @param object|null $shop     A shop to use instead of the real one.
		 * @return array[]
		 */
		public static function for_order( $order_id, $store = null, $shop = null ) {
			$order_id = (int) $order_id;
			if ( null === $store && null !== self::$doubles ) {
				list( $store, $shop ) = self::$doubles;
			}
			if ( $order_id <= 0 || ( ! self::$booted && null === $store ) ) {
				return array();
			}

			try {
				self::load();
				$store    = $store ? $store : new Store();
				$shop     = $shop ? $shop : new Shop();
				$settings = Settings::get();
				$order    = $shop->order( $order_id, $settings['category_slug'] );
				if ( ! $order || ! $order['invoice_product_ids'] ) {
					return array();
				}

				$rows = array();
				foreach ( $store->invoices_by_products( $order['invoice_product_ids'] ) as $row ) {
					$rows[ (int) $row['product_id'] ] = $row;
				}
				$lines = $store->lines_for_invoices( array_column( $rows, 'id' ) );

				$out = array();
				foreach ( $order['invoice_product_ids'] as $product_id ) {
					$product = $shop->product( $product_id );
					if ( isset( $rows[ $product_id ] ) ) {
						$row   = $rows[ $product_id ];
						$out[] = array(
							'id'             => (int) $row['id'],
							'product_id'     => (int) $product_id,
							'made_by_hand'   => false,
							'handle'         => $row['handle'],
							'forum_user_id'  => $row['forum_user_id'],
							'customer_id'    => $row['customer_id'],
							'customer_name'  => $row['customer_name'],
							'customer_email' => $row['customer_email'],
							'state'          => $row['state'],
							'title'          => $row['title'],
							'total'          => (float) $row['total'],
							'flat_total'     => $row['flat_total'],
							'shipping'       => $row['shipping'],
							'shipping_slug'  => $row['shipping_slug'],
							'order_id'       => $row['order_id'],
							'url'            => $product ? $product['permalink'] : '',
							'lines'          => array_map( array( self::class, 'contract_line' ), isset( $lines[ $row['id'] ] ) ? $lines[ $row['id'] ] : array() ),
						);
						continue;
					}

					// An invoice made by hand: what its product says, or — the product gone — what the order kept.
					$hand = $product ? Grammar::product_lines( $product['lines_json'], $product['description'] ) : array();
					$name = $product ? $product['name'] : '';
					if ( ! $product ) {
						foreach ( $order['lines'] as $line ) {
							if ( (int) $line['invoice_product_id'] !== (int) $product_id ) {
								continue;
							}
							if ( '' === $name ) {
								$name = '' !== $line['invoice_title'] ? $line['invoice_title'] : ( $line['is_invoice'] ? $line['name'] : '' );
							}
							if ( $line['is_invoice'] && $line['snapshot'] ) {
								$hand = $line['snapshot'];
							}
						}
					}

					$out[] = array(
						'id'             => 0,
						'product_id'     => (int) $product_id,
						'made_by_hand'   => true,
						'handle'         => Rules::handle_from_title( $name, $settings['title_format'] ),
						'forum_user_id'  => null,
						'customer_id'    => $order['customer_id'] > 0 ? (int) $order['customer_id'] : null,
						'customer_name'  => $order['billing_name'],
						'customer_email' => $order['billing_email'],
						'state'          => 'hand',
						'title'          => $name,
						'total'          => $product && '' !== $product['regular_price'] ? (float) $product['regular_price'] : 0.0,
						'flat_total'     => null,
						'shipping'       => '',
						'shipping_slug'  => $product ? $product['shipping_class'] : '',
						'order_id'       => null,
						'url'            => $product ? $product['permalink'] : '',
						'lines'          => self::contract_hand_lines( $hand ),
					);
				}

				return $out;
			} catch ( \Throwable $e ) {
				return array();
			}
		}
	}
}

namespace {

	if ( ! function_exists( 'fishotel_invoices_lines_for_product' ) ) {
		/**
		 * The lines of the invoice a product is.
		 *
		 * For an invoice built in the Invoices section: its stored lines,
		 * each with every id it kept — the shop product and variation, the
		 * species-list entry, the tank group (Clean Room stay) — whether or
		 * not the line takes from shop stock. For an invoice made by hand:
		 * its lines as the theme reads them (the `_fh_invoice_lines` JSON,
		 * else the description), with `source` = 'hand' and no ids. An
		 * empty array for a product that is not an invoice.
		 *
		 * The function exists whenever the Misc plugin is active and
		 * answers an empty array while the Invoices section is switched
		 * off — guard it with function_exists().
		 *
		 * @param int $product_id Product id.
		 * @return array<int, array{
		 *   id: int, invoice_id: int, kind: string, name: string, qty: int,
		 *   unit_price: float|null, amount: float, product_id: int|null,
		 *   variation_id: int|null, species_id: int|null, stay_id: int|null,
		 *   size_slug: string|null, size_label: string|null, source: string,
		 *   take_stock: bool, sort: int
		 * }>
		 */
		function fishotel_invoices_lines_for_product( $product_id ) {
			return \FisHotel\Misc\Sections\Invoices\Invoices::lines_for_product( (int) $product_id );
		}
	}

	if ( ! function_exists( 'fishotel_invoices_for_order' ) ) {
		/**
		 * The invoices in an order, each with its lines (the shape of
		 * fishotel_invoices_lines_for_product()).
		 *
		 * An order "contains" an invoice when one of its lines is the
		 * invoice product, or was made out of it by the theme (the
		 * `_fh_invoice_product_id` item meta). Each entry: id (this
		 * section's invoice id, 0 for one made by hand), product_id,
		 * made_by_hand, handle, forum_user_id, customer_id, customer_name,
		 * customer_email, state (draft / published / paid / cancelled /
		 * trashed, or 'hand'), title, total, flat_total, shipping,
		 * shipping_slug, order_id (the order that paid it), url, lines.
		 *
		 * Same availability as fishotel_invoices_lines_for_product().
		 *
		 * @param int $order_id Order id.
		 * @return array[]
		 */
		function fishotel_invoices_for_order( $order_id ) {
			return \FisHotel\Misc\Sections\Invoices\Invoices::for_order( (int) $order_id );
		}
	}

	if ( ! function_exists( 'fishotel_invoices_is_adjustment' ) ) {
		/**
		 * Whether a name is the one an invoice's "Adjustment" goes by.
		 *
		 * An invoice whose stated price is higher than its lines carries
		 * one line that makes up the difference. The theme knows fish,
		 * credits and notes only, so that line reaches an order as a FISH
		 * line — a product-less line flagged `_fh_invoice_fish`, or a
		 * kind-"fish" row of the `_fh_invoice_lines` JSON — named
		 * "Adjustment", quantity 1. It is money, not a fish: ask this
		 * before counting an invoice's fish line as a fish (to bag, to
		 * replace, to sell). The two functions above already hand it out
		 * as kind `discount`.
		 *
		 * Pure: it answers the same while the Invoices section is switched
		 * off (the orders it wrote stay). Guard it with function_exists().
		 *
		 * @param mixed $name The line's name.
		 * @return bool
		 */
		function fishotel_invoices_is_adjustment( $name ) {
			return \FisHotel\Misc\Sections\Invoices\Invoices::is_adjustment( $name );
		}
	}
}
