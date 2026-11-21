<?php
/**
 * Sticker Giveaway cart — keeps WooCommerce's cart in step with the
 * checkout picker and makes sticker lines behave.
 *
 * WHY the cart carries the sticker as a real line: Jeff packs from the
 * order screen and the packing slip. A line item with "Sticker: Free with
 * fish order" is visible on the admin order, in every email and on the
 * PDF invoice with zero extra integration. The alternative (order meta)
 * is invisible exactly where it matters.
 *
 * Every sticker line carries cart item data `fh_sticker` = free|extra.
 * That value is part of WooCommerce's cart item key, so a free sticker, a
 * paid add-on of the same design and a normal purchase of that product
 * are three distinct lines that never merge.
 *
 * Sync runs on `woocommerce_checkout_update_order_review` (every AJAX
 * refresh — WC serialises the whole checkout form, our radios included)
 * and on `woocommerce_checkout_process` (final submit), and is diff-based
 * so an unchanged selection touches nothing.
 *
 * Shipping: stickers ride in the fish box. They are stripped out of each
 * shipping package (when anything else remains) so the theme's table-rate
 * result, keyed on shipping class, is identical with or without stickers.
 *
 * A design is a simple product or one variation of a variable product;
 * a line's design is read with Rules::design_id() (the variation id when
 * there is one). A variation design is a normal variation line —
 * add_to_cart( product, qty, variation, … ) — so the order screen, the
 * emails and the invoice name the design with nothing further.
 *
 * A design that keeps a GIVEAWAY PILE (see class-piles.php) is counted
 * against that pile, never against the product's own WooCommerce stock.
 * Its lines carry a product object that tells WooCommerce so
 * (class-pile-products.php): the shop pile at 0 does not refuse the line,
 * and the line is not added to what a normal purchase of the same
 * product is checked against.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cart
 */
class Cart {

	/**
	 * Session key remembering the customer's free-sticker choice, so a
	 * deliberate "No sticker, thanks" survives a page reload instead of
	 * being overridden by the pre-selected default.
	 *
	 * @var string
	 */
	const SESSION_CHOICE = 'fh_sticker_free_choice';

	/**
	 * Hidden form marker — sync only runs when the picker was actually in
	 * the submitted form. Without it, a checkout without the picker (pool
	 * empty, gateway-side submit) would wipe stickers it never showed.
	 *
	 * @var string
	 */
	const FIELD_MARKER = 'fh_sticker_picker';

	/**
	 * Radio field name for the free sticker.
	 *
	 * @var string
	 */
	const FIELD_FREE = 'fh_sticker_free';

	/**
	 * Stepper field name (array keyed by product ID) for add-ons.
	 *
	 * @var string
	 */
	const FIELD_EXTRA = 'fh_sticker_extra';

	/**
	 * True while sync() is mutating the cart, so our own removals do not
	 * get mistaken for a customer removing the line on the cart page.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Per-request cache of product ID → category slugs (with ancestors).
	 *
	 * @var array<int, string[]>
	 */
	private static $slug_cache = array();

	/**
	 * The design our own add_to_cart() is putting in right now (0 = none).
	 * While it is set, WooCommerce's stock questions about that one
	 * product are answered "yes" — see add_line().
	 *
	 * @var int
	 */
	private static $adding_pile = 0;

	/**
	 * Register hooks.
	 */
	public function init() {
		// ── Keep the cart in step with the picker ───────────────────
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'sync_from_order_review' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'sync_from_checkout_post' ) );

		// ── Pricing, quantity lock, eligibility re-check ────────────
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_pricing' ), 20 );

		// ── Cart page display ───────────────────────────────────────
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_class', array( $this, 'item_class' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'free_price_label' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'free_price_label' ), 10, 2 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'forget_choice_on_removal' ), 10, 2 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'forget_choice_on_zero_qty' ), 10, 4 );

		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'design_thumbnail' ), 10, 2 );

		// ── Lines of a design that keeps a giveaway pile ────────────
		add_filter( 'woocommerce_add_cart_item', array( $this, 'pile_line' ), 20, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'pile_line' ), 20, 1 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'guard_pile_lines' ), 0 );

		// ── Order line items ────────────────────────────────────────
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'order_line_item_meta' ), 10, 3 );

		// ── Shipping packages ───────────────────────────────────────
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'strip_from_packages' ), 20 );
	}

	/* ============================================================== */
	/*  Helpers                                                        */
	/* ============================================================== */

	/**
	 * The WC cart, or null when WooCommerce has not built one yet.
	 *
	 * @return \WC_Cart|null
	 */
	public static function cart() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return null;
		}

		$cart = WC()->cart;

		return ( $cart instanceof \WC_Cart ) ? $cart : null;
	}

	/**
	 * Sticker kind of a cart item: 'free', 'extra' or '' when it is an
	 * ordinary line.
	 *
	 * @param array $cart_item Cart item.
	 * @return string
	 */
	public static function kind( $cart_item ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ Sticker_Giveaway::ITEM_KEY ] ) ) {
			return '';
		}

		$kind = (string) $cart_item[ Sticker_Giveaway::ITEM_KEY ];

		return in_array( $kind, array( Rules::KIND_FREE, Rules::KIND_EXTRA ), true ) ? $kind : '';
	}

	/**
	 * Whether a cart item is a picker-managed sticker line.
	 *
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	public static function is_sticker_item( $cart_item ) {
		return '' !== self::kind( $cart_item );
	}

	/**
	 * Category slugs of a product, including every ancestor's slug, so
	 * `quarantined-fish > wrasses` still matches `quarantined-fish`.
	 *
	 * @param int $product_id Parent product ID.
	 * @return string[]
	 */
	public static function category_slugs( $product_id ) {
		$product_id = (int) $product_id;

		if ( isset( self::$slug_cache[ $product_id ] ) ) {
			return self::$slug_cache[ $product_id ];
		}

		$slugs = array();
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}

				$slugs[] = $term->slug;

				foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'product_cat' );
					if ( $ancestor instanceof \WP_Term ) {
						$slugs[] = $ancestor->slug;
					}
				}
			}
		}

		self::$slug_cache[ $product_id ] = array_values( array_unique( $slugs ) );

		return self::$slug_cache[ $product_id ];
	}

	/**
	 * Translate the live cart into the plain line arrays Rules expects.
	 *
	 * Subtotals use price × quantity rather than `line_subtotal` because
	 * this runs inside `woocommerce_before_calculate_totals`, before WC
	 * has filled those in.
	 *
	 * @param \WC_Cart|null $cart Cart (defaults to the live one).
	 * @return array
	 */
	public static function lines_for_rules( $cart = null ) {
		$cart  = $cart instanceof \WC_Cart ? $cart : self::cart();
		$lines = array();

		if ( ! $cart ) {
			return $lines;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$product    = isset( $item['data'] ) && $item['data'] instanceof \WC_Product ? $item['data'] : null;
			$quantity   = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$price      = $product ? (float) $product->get_price() : 0.0;

			$lines[] = array(
				'product_id'     => $product_id,
				'category_slugs' => $product_id ? self::category_slugs( $product_id ) : array(),
				'line_subtotal'  => $price * max( 0, $quantity ),
				'is_sticker'     => self::is_sticker_item( $item ),
			);
		}

		return $lines;
	}

	/**
	 * Whether the live cart qualifies for a free sticker.
	 *
	 * @param array|null $settings Settings (defaults to the saved ones).
	 * @return bool
	 */
	public static function is_cart_eligible( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();

		return Rules::is_eligible( self::lines_for_rules(), $settings );
	}

	/**
	 * What the cart currently holds: the free sticker's design ID (0 when
	 * none) and a [design_id => qty] map of add-ons.
	 *
	 * @return array{free: int, extras: array<int, int>}
	 */
	public static function current_selection() {
		$out  = array(
			'free'   => 0,
			'extras' => array(),
		);
		$cart = self::cart();

		if ( ! $cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $item ) {
			$kind = self::kind( $item );
			$pid  = Rules::design_id( $item );

			if ( ! $kind || ! $pid ) {
				continue;
			}

			if ( Rules::KIND_FREE === $kind ) {
				if ( ! $out['free'] ) {
					$out['free'] = $pid;
				}
			} else {
				$out['extras'][ $pid ] = ( $out['extras'][ $pid ] ?? 0 ) + max( 0, (int) ( $item['quantity'] ?? 0 ) );
			}
		}

		return $out;
	}

	/**
	 * The customer's remembered free-sticker choice, or null when they
	 * have not interacted with the picker in this session.
	 *
	 * @return int|null
	 */
	public static function remembered_choice() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
			return null;
		}

		$value = WC()->session->get( self::SESSION_CHOICE, null );

		return ( null === $value || '' === $value ) ? null : (int) $value;
	}

	/**
	 * Remember the free-sticker choice for this session.
	 *
	 * @param int $product_id Product ID, or 0 for "no sticker".
	 */
	private static function remember_choice( $product_id ) {
		if ( function_exists( 'WC' ) && WC() && WC()->session ) {
			WC()->session->set( self::SESSION_CHOICE, (int) $product_id );
		}
	}

	/* ============================================================== */
	/*  Sync                                                           */
	/* ============================================================== */

	/**
	 * `woocommerce_checkout_update_order_review` — WC passes the whole
	 * serialised checkout form as a string.
	 *
	 * @param string $post_data Serialised form data.
	 */
	public function sync_from_order_review( $post_data ) {
		$data = array();
		parse_str( (string) $post_data, $data );

		$this->sync_from_array( $data );
	}

	/**
	 * `woocommerce_checkout_process` — final submit; fields are in $_POST.
	 */
	public function sync_from_checkout_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verified the checkout nonce before firing this action.
		$this->sync_from_array( wp_unslash( $_POST ) );
	}

	/**
	 * Run sync() from a parsed form array when the picker marker is set.
	 *
	 * @param array $data Parsed form data.
	 */
	private function sync_from_array( $data ) {
		if ( ! is_array( $data ) || empty( $data[ self::FIELD_MARKER ] ) ) {
			return;
		}

		$free   = isset( $data[ self::FIELD_FREE ] ) ? (int) $data[ self::FIELD_FREE ] : 0;
		$extras = isset( $data[ self::FIELD_EXTRA ] ) && is_array( $data[ self::FIELD_EXTRA ] ) ? $data[ self::FIELD_EXTRA ] : array();

		self::sync( $free, $extras, ! isset( $data[ self::FIELD_FREE ] ) );
	}

	/**
	 * Bring the cart's sticker lines in line with a selection.
	 *
	 * Diff-based: an unchanged selection is a no-op, a changed free pick
	 * swaps one line, a changed stepper adjusts one quantity. Lines are
	 * only ever added when the product resolves, is purchasable and in
	 * stock; nothing here raises a WooCommerce notice.
	 *
	 * @param int|string $free_pid       Free sticker product ID (0 = none).
	 * @param array      $extras         Raw [product_id => qty] map.
	 * @param bool       $free_untouched True when the form carried no free
	 *                                   radio at all (cart not eligible when
	 *                                   the picker rendered, so no radios);
	 *                                   the free line already in the cart —
	 *                                   or failing that the remembered
	 *                                   choice — is then kept as-is.
	 */
	public static function sync( $free_pid, array $extras, $free_untouched = false ) {
		$cart = self::cart();
		if ( ! $cart ) {
			return;
		}

		$settings = Settings::get();

		if ( $free_untouched ) {
			// The customer was never shown the radios in this form, so
			// "free = 0" would be our invention, not their choice. Keep
			// whatever the cart holds (e.g. a sticker auto-added from
			// another tab once a fish went in), else the remembered pick.
			$free_pid = self::current_selection()['free'];
			if ( ! $free_pid ) {
				$free_pid = (int) self::remembered_choice();
			}
		}

		$selection = Rules::normalise_selection( $free_pid, $extras, $settings );
		$eligible  = self::is_cart_eligible( $settings );

		if ( ! $free_untouched ) {
			self::remember_choice( $selection['free'] );
		}

		if ( ! $eligible ) {
			$selection['free'] = 0;
		}

		if ( ! Rules::offers_upsell( $eligible, $settings ) ) {
			$selection['extras'] = array();
		}

		$was_syncing   = self::$syncing;
		self::$syncing = true;

		$current_free  = array(); // key => product_id.
		$current_extra = array(); // key => [pid, qty].

		foreach ( $cart->get_cart() as $key => $item ) {
			$kind = self::kind( $item );
			if ( ! $kind ) {
				continue;
			}

			$pid = Rules::design_id( $item );

			if ( Rules::KIND_FREE === $kind ) {
				$current_free[ $key ] = $pid;
			} else {
				$current_extra[ $key ] = array(
					'pid' => $pid,
					'qty' => (int) ( $item['quantity'] ?? 0 ),
				);
			}
		}

		// Free line: keep the first matching one, drop the rest.
		$keep_free = false;
		foreach ( $current_free as $key => $pid ) {
			if ( ! $keep_free && $pid === $selection['free'] ) {
				$keep_free = true;
				continue;
			}
			$cart->remove_cart_item( $key );
		}

		if ( ! $keep_free && $selection['free'] > 0 ) {
			self::add_line( $selection['free'], 1, Rules::KIND_FREE );
		}

		// Add-on lines: adjust, remove, then add what is missing.
		$seen = array();
		foreach ( $current_extra as $key => $row ) {
			$want = $selection['extras'][ $row['pid'] ] ?? 0;

			if ( $want < 1 || isset( $seen[ $row['pid'] ] ) ) {
				$cart->remove_cart_item( $key );
				continue;
			}

			$seen[ $row['pid'] ] = true;

			if ( $row['qty'] !== $want ) {
				$cart->set_quantity( $key, $want, false );
			}
		}

		foreach ( $selection['extras'] as $pid => $qty ) {
			if ( ! isset( $seen[ $pid ] ) ) {
				self::add_line( $pid, $qty, Rules::KIND_EXTRA );
			}
		}

		self::$syncing = $was_syncing;
	}

	/**
	 * Add one sticker line, guarding against products that have gone
	 * missing, been unpublished or run out since the pool was saved.
	 *
	 * WC_Cart::add_to_cart() raises a WooCommerce *error* notice when it
	 * refuses — a notice that would be printed on every checkout refresh
	 * and would block Place Order. So what it would refuse is checked here
	 * first, and any error notice WC still adds is discarded: a sticker
	 * must never stop a fish order going through; the line is simply not
	 * added.
	 *
	 * A design that keeps a giveaway pile is limited by that pile (what it
	 * can still hand out, less what this cart already holds of it) and not
	 * by the product's own stock. WooCommerce checks the product's own
	 * stock inside add_to_cart() on an object it reads itself, so for the
	 * length of that one call — and for that one product — its two stock
	 * questions are answered "in stock" and "enough" (see allow_*()). The
	 * moment the line exists it carries the giveaway-line object and the
	 * answers are withdrawn.
	 *
	 * A design that keeps none is checked as it always was: the product's
	 * stock against everything the cart holds of it (the free line, an
	 * add-on of the same design and a normal purchase are three lines of
	 * one product).
	 *
	 * A variation is added as a variation — the product, the variation id
	 * and no attributes of our own: WooCommerce fills the line's attributes
	 * from the variation itself, exactly as typed (a custom attribute's
	 * "Blue Tang" stays "Blue Tang").
	 *
	 * @param int    $design_id Design ID (a simple product's, or a variation's).
	 * @param int    $quantity  Quantity.
	 * @param string $kind      'free' or 'extra'.
	 * @return string|false Cart item key, or false when not added.
	 */
	private static function add_line( $design_id, $quantity, $kind ) {
		$cart     = self::cart();
		$product  = Designs::product( (int) $design_id );
		$settings = Settings::get();

		if ( ! $cart || ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( ! Designs::can_hand_out( $product, $settings ) ) {
			return false;
		}

		$quantity = max( 1, (int) $quantity );

		if ( $product->is_sold_individually() ) {
			$quantity = 1;
		}

		$available = Designs::available( $product->get_id(), $settings );

		if ( null !== $available ) {
			$room = Piles::room( $product->get_id(), $available, self::sticker_lines( $cart ) );

			if ( $room < 1 ) {
				return false;
			}
			$quantity = min( $quantity, $room );
		} elseif ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$in_cart = $cart->get_cart_item_quantities();
			$held    = (int) ( $in_cart[ $product->get_stock_managed_by_id() ] ?? 0 );
			$room    = (int) $product->get_stock_quantity() - $held;

			if ( $room < 1 ) {
				return false;
			}
			$quantity = min( $quantity, $room );
		}

		$is_variation  = $product->is_type( 'variation' );
		$errors_before = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : null;

		if ( null !== $available ) {
			self::begin_pile_add( $product->get_id() );
		}

		try {
			$key = $cart->add_to_cart(
				$is_variation ? $product->get_parent_id() : $product->get_id(),
				$quantity,
				$is_variation ? $product->get_id() : 0,
				array(),
				array( Sticker_Giveaway::ITEM_KEY => $kind )
			);
		} catch ( \Exception $e ) {
			$key = false;
		}

		self::end_pile_add();

		if ( ! $key && null !== $errors_before && function_exists( 'wc_set_notices' ) ) {
			$all          = wc_get_notices();
			$all['error'] = $errors_before;
			wc_set_notices( $all );
		}

		return $key ? $key : false;
	}

	/**
	 * The cart's sticker lines as plain rows for Piles::fit() / ::room().
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, array{design: int, kind: string, qty: int}>
	 */
	private static function sticker_lines( \WC_Cart $cart ) {
		$lines = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$kind = self::kind( $item );
			if ( ! $kind ) {
				continue;
			}

			$lines[ $key ] = array(
				'design' => Rules::design_id( $item ),
				'kind'   => $kind,
				'qty'    => max( 0, (int) ( $item['quantity'] ?? 0 ) ),
			);
		}

		return $lines;
	}

	/* ============================================================== */
	/*  Giveaway-pile lines and WooCommerce's stock checks             */
	/* ============================================================== */

	/**
	 * Start answering WooCommerce's stock questions for one design, for
	 * the length of our own add_to_cart().
	 *
	 * @param int $design_id Design ID.
	 */
	private static function begin_pile_add( $design_id ) {
		self::$adding_pile = (int) $design_id;
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'allow_in_stock' ), 99, 2 );
		add_filter( 'woocommerce_product_backorders_allowed', array( __CLASS__, 'allow_enough_stock' ), 99, 2 );
	}

	/**
	 * Stop answering them. Called as soon as the line exists (pile_line())
	 * and again when add_to_cart() returns, whichever comes first.
	 */
	private static function end_pile_add() {
		if ( ! self::$adding_pile ) {
			return;
		}

		self::$adding_pile = 0;
		remove_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'allow_in_stock' ), 99 );
		remove_filter( 'woocommerce_product_backorders_allowed', array( __CLASS__, 'allow_enough_stock' ), 99 );
	}

	/**
	 * `woocommerce_product_is_in_stock`, only while our own add runs and
	 * only for the design being added: the shop pile at 0 does not refuse
	 * a sticker that comes out of the giveaway pile.
	 *
	 * @param bool        $in_stock What WooCommerce worked out.
	 * @param \WC_Product $product  Product asked about.
	 * @return bool
	 */
	public static function allow_in_stock( $in_stock, $product ) {
		if ( self::$adding_pile && $product instanceof \WC_Product && (int) $product->get_id() === self::$adding_pile ) {
			return true;
		}

		return $in_stock;
	}

	/**
	 * `woocommerce_product_backorders_allowed`, under the same two
	 * conditions. It is the one answer both of add_to_cart()'s quantity
	 * checks accept ("enough stock" and "enough with what is already in
	 * the cart") for a product that manages stock, a variation that takes
	 * its stock from its product included. Nothing is saved; a normal
	 * purchase is never asked while this is on.
	 *
	 * @param bool $allowed    What WooCommerce worked out.
	 * @param int  $product_id Product asked about.
	 * @return bool
	 */
	public static function allow_enough_stock( $allowed, $product_id ) {
		if ( self::$adding_pile && (int) $product_id === self::$adding_pile ) {
			return true;
		}

		return $allowed;
	}

	/**
	 * `woocommerce_add_cart_item` and `woocommerce_get_cart_item_from_session`
	 * — a sticker line of a design that keeps a giveaway pile carries the
	 * giveaway-line object from the moment it exists, and again every time
	 * the cart is read back from the session.
	 *
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function pile_line( $cart_item ) {
		if ( ! is_array( $cart_item ) || ! self::is_sticker_item( $cart_item ) ) {
			return $cart_item;
		}

		// Our own add got as far as making the line: WooCommerce's checks are behind it.
		self::end_pile_add();

		if ( ! Rules::keeps_pile( Rules::design_id( $cart_item ), Settings::get() ) ) {
			return $cart_item;
		}

		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data'] : null;
		$pile    = $product ? Designs::pile_product( $product ) : null;

		if ( $pile ) {
			$cart_item['data'] = $pile;
		}

		return $cart_item;
	}

	/**
	 * Make sure a sticker line carries the right kind of product object
	 * for what its design is today: the giveaway-line object when the
	 * design keeps a giveaway pile, WooCommerce's own when it does not
	 * (the owner may have flipped the switch while the line sat in a
	 * cart, and WooCommerce itself swaps a line's object in one case).
	 *
	 * @param \WC_Cart $cart     Cart.
	 * @param string   $key      Cart item key.
	 * @param array    $item     Cart item.
	 * @param array    $settings Settings.
	 * @return \WC_Product|null The object the line now carries.
	 */
	private static function dress_line( \WC_Cart $cart, $key, array $item, array $settings ) {
		$product = isset( $item['data'] ) && $item['data'] instanceof \WC_Product ? $item['data'] : null;

		if ( ! $product || ! isset( $cart->cart_contents[ $key ] ) ) {
			return $product;
		}

		$keeps   = Rules::keeps_pile( Rules::design_id( $item ), $settings );
		$is_pile = Designs::is_pile_product( $product );

		if ( $keeps === $is_pile ) {
			return $product;
		}

		$swap = $keeps ? Designs::pile_product( $product ) : Designs::product( $product->get_id() );

		if ( $swap instanceof \WC_Product ) {
			$cart->cart_contents[ $key ]['data'] = $swap;
			return $swap;
		}

		return $product;
	}

	/**
	 * Bring the quantities of the sticker lines down to what the giveaway
	 * piles can still hand out (Piles::fit()): the free line first, then
	 * the add-ons. A line that no longer fits at all is dropped quietly.
	 * Lines of designs that keep no giveaway pile are not touched.
	 *
	 * @param \WC_Cart $cart     Cart.
	 * @param array    $settings Settings.
	 * @return bool Whether a line's quantity was cut down (a removed line
	 *              makes WooCommerce total the cart again by itself; a
	 *              changed quantity outside a totals pass does not).
	 */
	private static function fit_pile_lines( \WC_Cart $cart, array $settings ) {
		$lines     = self::sticker_lines( $cart );
		$available = array();
		$cut       = false;

		foreach ( $lines as $line ) {
			$design = $line['design'];
			if ( isset( $available[ $design ] ) ) {
				continue;
			}
			$room = Designs::available( $design, $settings );
			if ( null !== $room ) {
				$available[ $design ] = $room;
			}
		}

		if ( ! $available ) {
			return false;
		}

		foreach ( Piles::fit( $lines, $available ) as $key => $fits ) {
			if ( ! isset( $available[ $lines[ $key ]['design'] ] ) || $fits === $lines[ $key ]['qty'] ) {
				continue;
			}

			if ( $fits < 1 ) {
				self::remove_quietly( $cart, $key );
			} elseif ( isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ]['quantity'] = $fits;
				$cut                                     = true;
			}
		}

		return $cut;
	}

	/**
	 * `woocommerce_check_cart_items`, just before WooCommerce's own check
	 * — on the cart page, on checkout and at Place Order. The giveaway
	 * piles are read afresh: when a pile ran out between the picker and
	 * the order, the line is dropped quietly here and the order goes
	 * through without it, as the section already does for a product that
	 * went out of stock. Only lines of designs that keep a giveaway pile
	 * are looked at.
	 */
	public function guard_pile_lines() {
		$cart     = self::cart();
		$settings = Settings::get();

		if ( ! $cart || empty( $settings['pile_ids'] ) ) {
			return;
		}

		$fresh = false;
		$cut   = false;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_sticker_item( $item ) || ! Rules::keeps_pile( Rules::design_id( $item ), $settings ) ) {
				continue;
			}

			if ( ! $fresh ) {
				Store::forget();
				$fresh = true;
			}

			$product = self::dress_line( $cart, $key, $item, $settings );

			if ( ! $product || ! Designs::can_hand_out( $product, $settings ) ) {
				self::remove_quietly( $cart, $key );
				continue;
			}

			// WooCommerce swaps the line's object back to its own when a "sold individually" line holds more than one; never let it get that far.
			if ( $product->is_sold_individually() && (int) ( $item['quantity'] ?? 0 ) > 1 && isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ]['quantity'] = 1;
				$cut                                     = true;
			}
		}

		if ( $fresh && self::fit_pile_lines( $cart, $settings ) ) {
			$cut = true;
		}

		// A quantity changed here is outside a totals pass: total the cart again so the order is made from what is in it.
		if ( $cut ) {
			$cart->calculate_totals();
		}
	}

	/* ============================================================== */
	/*  Pricing + eligibility re-check                                 */
	/* ============================================================== */

	/**
	 * `woocommerce_before_calculate_totals` — price every sticker line
	 * and keep each one within what the current settings allow:
	 *
	 *   - any sticker line: removed when its design can no longer be handed
	 *     out (unpublished, no longer purchasable; out of stock when it uses
	 *     the product's own stock, or its giveaway pile down to the reserve
	 *     when it keeps one).
	 *     WC's own check_cart_items() would otherwise raise an *error*
	 *     notice for it at checkout and block Place Order — a sticker must
	 *     never stop a fish order, so the line is dropped quietly instead.
	 *   - free line: removed when the cart no longer qualifies or the
	 *     design left the pool; quantity forced back to 1.
	 *   - add-on line: removed when the upsell is off, is not offered to
	 *     this cart, or the design left the pool; quantity clamped to
	 *     `upsell_max_qty`. The theme's cart page renders its own qty
	 *     stepper (an older theme never applied
	 *     `woocommerce_cart_item_quantity`), so this is the only place the
	 *     max is enforced after the picker.
	 *
	 * Idempotent: WC calls this many times per request; set_price() on an
	 * already-priced product is a no-op, and a cart that is already clean
	 * removes nothing. The sellability check runs before set_price() so it
	 * sees the product's real price ('' = not purchasable), not the one
	 * this pass assigns.
	 *
	 * @param \WC_Cart $cart Cart being totalled.
	 */
	public function apply_pricing( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$settings    = Settings::get();
		$eligible    = null; // Resolved lazily — only carts with sticker lines pay for it.
		$free_price  = Rules::price_for( Rules::KIND_FREE, $settings );
		$extra_price = Rules::price_for( Rules::KIND_EXTRA, $settings );
		$max_extra   = Rules::max_extra_qty( $settings );

		foreach ( $cart->get_cart() as $key => $item ) {
			$kind = self::kind( $item );
			if ( ! $kind ) {
				continue;
			}

			$product = self::dress_line( $cart, $key, $item, $settings );
			$in_pool = Rules::in_pool( Rules::design_id( $item ), $settings );

			if ( ! $product || ! Designs::can_hand_out( $product, $settings ) ) {
				self::remove_quietly( $cart, $key );
				continue;
			}

			if ( Rules::KIND_FREE === $kind ) {
				if ( $in_pool && null === $eligible ) {
					$eligible = Rules::is_eligible( self::lines_for_rules( $cart ), $settings );
				}

				if ( ! $in_pool || ! $eligible ) {
					self::remove_quietly( $cart, $key );
					continue;
				}

				if ( (int) $item['quantity'] !== 1 && isset( $cart->cart_contents[ $key ] ) ) {
					$cart->cart_contents[ $key ]['quantity'] = 1;
				}

				$product->set_price( $free_price );
				continue;
			}

			// Add-on line.
			if ( $in_pool && $max_extra > 0 && ! empty( $settings['upsell_enabled'] ) && null === $eligible ) {
				$eligible = Rules::is_eligible( self::lines_for_rules( $cart ), $settings );
			}

			if ( ! $in_pool || $max_extra < 1 || ! Rules::offers_upsell( (bool) $eligible, $settings ) ) {
				self::remove_quietly( $cart, $key );
				continue;
			}

			$cap = $product->is_sold_individually() ? 1 : $max_extra;
			if ( (int) $item['quantity'] > $cap && isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ]['quantity'] = $cap;
			}

			$product->set_price( $extra_price );
		}

		// What is left may still be more than the giveaway piles can hand out.
		if ( ! empty( $settings['pile_ids'] ) ) {
			self::fit_pile_lines( $cart, $settings );
		}
	}

	/**
	 * Whether a sticker design can still be handed out
	 * (Designs::can_hand_out()): published and purchasable, and in stock —
	 * by its giveaway pile when it keeps one, else by the product's own
	 * stock. The same check gates the picker (Frontend::available_pool) and
	 * add_line(), so a line that passes here is one the picker would
	 * produce today.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function is_sellable( \WC_Product $product ) {
		return Designs::can_hand_out( $product, Settings::get() );
	}

	/**
	 * Remove a cart line as a settings-driven correction, not a customer
	 * action: the re-entrancy flag is raised so `forget_choice_on_removal`
	 * ignores it, and restored to whatever it was (this runs from inside
	 * WC's calculate_totals, which sync() itself can trigger).
	 *
	 * @param \WC_Cart $cart Cart.
	 * @param string   $key  Cart item key.
	 */
	private static function remove_quietly( \WC_Cart $cart, $key ) {
		$was_syncing   = self::$syncing;
		self::$syncing = true;
		$cart->remove_cart_item( $key );
		self::$syncing = $was_syncing;
	}

	/* ============================================================== */
	/*  Cart page display                                              */
	/* ============================================================== */

	/**
	 * `woocommerce_cart_item_quantity` — sticker quantities are managed by
	 * the picker, so show plain text instead of a qty input. The remove
	 * link is untouched; a customer can still take the line out.
	 *
	 * WooCommerce's stock cart template applies this filter, and so does
	 * the current FisHotel theme's cart.php. An older theme drew its own
	 * stepper without it, which is why `item_class()` tags the row (so CSS
	 * can hide the stepper on the free line) and `apply_pricing()` clamps
	 * whatever quantity comes back.
	 *
	 * @param string $product_quantity Quantity input HTML.
	 * @param string $cart_item_key    Cart item key.
	 * @param array  $cart_item        Cart item.
	 * @return string
	 */
	public function lock_quantity( $product_quantity, $cart_item_key, $cart_item ) {
		if ( ! self::is_sticker_item( $cart_item ) ) {
			return $product_quantity;
		}

		$qty = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

		return sprintf( '<span class="fh-sticker-qty-locked">%d</span>', $qty );
	}

	/**
	 * `woocommerce_cart_item_class` — tag sticker rows so the stylesheet
	 * can treat them (hide the theme's qty stepper on the free line, which
	 * is always 1). Applied by both the theme's cart.php and its
	 * review-order.php.
	 *
	 * @param string $class     Row class string.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function item_class( $class, $cart_item ) {
		$kind = self::kind( $cart_item );
		if ( ! $kind ) {
			return $class;
		}

		return trim( $class . ' fh-sticker-line fh-sticker-line--' . $kind );
	}

	/**
	 * `woocommerce_get_item_data` — label sticker lines in cart and checkout.
	 *
	 * The theme's cart and checkout templates print this row under the item
	 * title (`wc_get_formatted_cart_item_data()`), as stock WC does, so it is
	 * the one label a sticker line gets; a name pill on top would show the
	 * same thing twice.
	 *
	 * @param array $item_data Existing item data rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function item_data( $item_data, $cart_item ) {
		$kind = self::kind( $cart_item );
		if ( ! $kind ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'   => __( 'Sticker', 'fishotel-misc-plugin' ),
			'value' => Rules::KIND_FREE === $kind
				? __( 'Free with your fish order', 'fishotel-misc-plugin' )
				: __( 'Add-on', 'fishotel-misc-plugin' ),
		);

		return $item_data;
	}

	/**
	 * `woocommerce_cart_item_price` / `_subtotal` — show FREE on the free line.
	 *
	 * @param string $price     Price HTML.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function free_price_label( $price, $cart_item ) {
		if ( Rules::KIND_FREE !== self::kind( $cart_item ) ) {
			return $price;
		}

		return '<span class="fh-sticker-free-price">' . esc_html__( 'FREE', 'fishotel-misc-plugin' ) . '</span>';
	}

	/**
	 * `woocommerce_cart_item_thumbnail` — a sticker line of a variation
	 * shows the variation's own picture. WooCommerce's stock templates
	 * already do; the FisHotel theme draws a line's thumbnail from the
	 * product the variation belongs to, which is the same picture for
	 * every design. A variation without a picture of its own keeps what
	 * the template gave (the product's).
	 *
	 * @param string $thumbnail Thumbnail HTML.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function design_thumbnail( $thumbnail, $cart_item ) {
		if ( ! self::is_sticker_item( $cart_item ) ) {
			return $thumbnail;
		}

		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof \WC_Product ? $cart_item['data'] : null;

		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return $thumbnail;
		}

		$image_id = (int) $product->get_image_id( 'edit' );
		if ( $image_id < 1 ) {
			return $thumbnail;
		}

		// Keep the size the template asked for (WordPress writes it into the class: "size-…").
		$size = preg_match( '/\bsize-([A-Za-z0-9_-]+)/', (string) $thumbnail, $m ) ? $m[1] : 'woocommerce_thumbnail';
		$html = wp_get_attachment_image( $image_id, $size );

		return '' !== (string) $html ? $html : $thumbnail;
	}

	/**
	 * `woocommerce_cart_item_removed` — when the customer removes the free
	 * sticker themselves on the cart page, treat it as "no sticker" so the
	 * checkout picker does not quietly put it back.
	 *
	 * @param string   $cart_item_key Removed key.
	 * @param \WC_Cart $cart          Cart.
	 */
	public function forget_choice_on_removal( $cart_item_key, $cart ) {
		if ( self::$syncing || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$removed = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : null;

		if ( $removed && Rules::KIND_FREE === self::kind( $removed ) ) {
			self::remember_choice( 0 );
		}
	}

	/**
	 * `woocommerce_update_cart_validation` — the cart form's other way of
	 * dropping a line. WC_Cart::set_quantity( $key, 0 ) unsets the line
	 * WITHOUT firing `woocommerce_cart_item_removed`, so a customer who
	 * types 0 into the free sticker's quantity (the theme hides that
	 * stepper, but a stock template or a CSS failure would not) is
	 * remembered as "no sticker" here instead. The change is still allowed
	 * through; nothing here validates.
	 *
	 * @param bool   $passed        Whether the update is allowed.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      New quantity.
	 * @return bool
	 */
	public function forget_choice_on_zero_qty( $passed, $cart_item_key, $values, $quantity ) {
		unset( $cart_item_key );

		if ( $passed && ! self::$syncing && (int) $quantity < 1 && Rules::KIND_FREE === self::kind( $values ) ) {
			self::remember_choice( 0 );
		}

		return $passed;
	}

	/* ============================================================== */
	/*  Order line items                                               */
	/* ============================================================== */

	/**
	 * `woocommerce_checkout_create_order_line_item` — stamp sticker lines
	 * so the admin order screen, customer emails and PDF invoices all
	 * show what the line is. `_fh_sticker` (underscore = hidden) keeps a
	 * machine-readable kind for reporting.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 */
	public function order_line_item_meta( $item, $cart_item_key, $values ) {
		$kind = self::kind( $values );
		if ( ! $kind ) {
			return;
		}

		$label = Rules::KIND_FREE === $kind
			? __( 'Free with fish order', 'fishotel-misc-plugin' )
			: __( 'Add-on', 'fishotel-misc-plugin' );

		$item->add_meta_data( __( 'Sticker', 'fishotel-misc-plugin' ), $label, true );
		$item->add_meta_data( '_fh_sticker', $kind, true );

		// A design that keeps a giveaway pile: the order line says so, for
		// good — which pile it comes out of is decided here, at the order,
		// and not by whatever the setting says on the day it is paid.
		$design_id = Rules::design_id( $values );
		if ( $design_id && Rules::keeps_pile( $design_id, Settings::get() ) ) {
			$item->add_meta_data( Orders::META_PILE, $design_id, true );
		}
	}

	/* ============================================================== */
	/*  Shipping packages                                              */
	/* ============================================================== */

	/**
	 * `woocommerce_cart_shipping_packages` — stickers ride in the fish
	 * box. Drop them from every package that still has a non-sticker
	 * line, and recompute `contents_cost` the way WC does (sum of
	 * line_total for shippable items), so table rates see the exact
	 * package they would have seen without stickers.
	 *
	 * A package made of stickers only (add-ons bought on their own) is
	 * left alone — it genuinely needs to ship.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public function strip_from_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $i => $package ) {
			if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				continue;
			}

			$kept       = array();
			$has_normal = false;

			foreach ( $package['contents'] as $key => $item ) {
				if ( self::is_sticker_item( $item ) ) {
					continue;
				}
				$has_normal   = true;
				$kept[ $key ] = $item;
			}

			if ( ! $has_normal || count( $kept ) === count( $package['contents'] ) ) {
				continue;
			}

			$cost = 0.0;
			foreach ( $kept as $item ) {
				$product = isset( $item['data'] ) && $item['data'] instanceof \WC_Product ? $item['data'] : null;
				if ( $product && $product->needs_shipping() ) {
					$cost += (float) ( $item['line_total'] ?? 0 );
				}
			}

			$packages[ $i ]['contents']      = $kept;
			$packages[ $i ]['contents_cost'] = $cost;
		}

		return $packages;
	}
}
