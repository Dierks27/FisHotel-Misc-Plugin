<?php
/**
 * Sticker Giveaway admin — the settings page under FisHotel Tools.
 *
 * WHY a product search instead of a dropdown: sticker designs are
 * ordinary products, so the pool is picked with WooCommerce's own product
 * search (wc-enhanced-select) rather than a hand-maintained list. The
 * search finds simple products and variable products; a variable product
 * lists its variations underneath, each with a tick — one variation, one
 * design. Eligible categories come from the live product_cat tree so a
 * renamed or added category never needs a code change.
 *
 * The page is one page: the pool, a stock table with one row per design
 * (its WooCommerce stock, its "Reorder at" number, and the two acts "Add
 * stickers" and "Recount"), then who gets a sticker, the add-ons and the
 * wording. Everything is saved by the one Save button EXCEPT the two
 * acts, which change the product's stock in WooCommerce the moment their
 * button is pressed.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 */

namespace FisHotel\Misc\Sections\Sticker_Giveaway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * The most stickers one "Add stickers" or "Recount" takes — a typo
	 * guard, far above any real stock.
	 *
	 * @var int
	 */
	const MAX_COUNT = 100000;

	/**
	 * Register hooks.
	 *
	 * Priority 20 on admin_enqueue_scripts: WooCommerce registers
	 * `wc-enhanced-select` and `woocommerce_admin_styles` at priority 10
	 * from a hook it adds on `init`; ours must run after that.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_ajax_fishotel_sticker_giveaway_stock', array( $this, 'ajax_stock' ) );
		add_action( 'wp_ajax_fishotel_sticker_giveaway_variations', array( $this, 'ajax_variations' ) );
	}

	/**
	 * Whether the current screen is our settings page.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && false !== strpos( $screen->id, Sticker_Giveaway::MENU_SLUG );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$settings   = Settings::get();
		$picked     = $this->picked_products( $settings );
		$categories = $this->category_tree();
		$fragments  = $this->fragments( $settings );
		$summary    = $fragments['summary'];

		include Sticker_Giveaway::path() . 'views/settings.php';
	}

	/**
	 * Enqueue admin CSS / JS on the settings page only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		unset( $hook_suffix );

		if ( ! $this->is_settings_screen() ) {
			return;
		}

		// WooCommerce's product search (selectWoo + wc-enhanced-select) and
		// the admin styles that size the select2 widget.
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_script( 'wc-enhanced-select' );

		wp_enqueue_style(
			'fishotel-sticker-admin',
			Sticker_Giveaway::url() . 'css/sticker-admin.css',
			array( 'woocommerce_admin_styles' ),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-sticker-admin',
			Sticker_Giveaway::url() . 'js/sticker-admin.js',
			array( 'jquery', 'wc-enhanced-select' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-sticker-admin',
			'fhStickerAdmin',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'currencySymbol' => Sticker_Giveaway::plain_symbol(),
				'i18n'           => array(
					'saved'         => __( 'Settings saved.', 'fishotel-misc-plugin' ),
					'error'         => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'stickers'      => __( '%d stickers in pool', 'fishotel-misc-plugin' ),
					'sticker'       => __( '%d sticker in pool', 'fishotel-misc-plugin' ),
					'noPool'        => __( 'no stickers in pool — picker hidden', 'fishotel-misc-plugin' ),
					'freeWith'      => __( 'free with: %s', 'fishotel-misc-plugin' ),
					'freeNone'      => __( 'no categories ticked — no free stickers', 'fishotel-misc-plugin' ),
					'minSub'        => __( 'min subtotal %s', 'fishotel-misc-plugin' ),
					'addons'        => __( 'add-ons %s', 'fishotel-misc-plugin' ),
					'addonsOff'     => __( 'add-ons off', 'fishotel-misc-plugin' ),
					'max'           => __( 'max %d', 'fishotel-misc-plugin' ),
					'priceRequired' => __( 'Enter a price per add-on sticker (0 is allowed).', 'fishotel-misc-plugin' ),
					'countRequired' => __( 'Type a number first.', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/* ============================================================== */
	/*  The pool                                                       */
	/* ============================================================== */

	/**
	 * The products picked in the search, for pre-rendering the select:
	 * each simple-product design, and each variable product whose
	 * variations are listed — in pool order, a variable product where its
	 * first ticked variation stands.
	 *
	 * @param array $settings Settings.
	 * @return array<int, \WC_Product>
	 */
	private function picked_products( array $settings ) {
		$parents  = Rules::ids( $settings['sticker_parent_ids'] ?? array() );
		$products = array();

		foreach ( Rules::pool( $settings ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( isset( $products[ $parent_id ] ) || ! in_array( $parent_id, $parents, true ) ) {
					continue;
				}
				$product = wc_get_product( $parent_id );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
			}

			$products[ (int) $product->get_id() ] = $product;
		}

		// A variable product with nothing ticked is still picked.
		foreach ( $parents as $parent_id ) {
			if ( ! isset( $products[ $parent_id ] ) ) {
				$product = wc_get_product( $parent_id );
				if ( $product instanceof \WC_Product ) {
					$products[ $parent_id ] = $product;
				}
			}
		}

		return $products;
	}

	/**
	 * A variable product's variations as the page lists them.
	 *
	 * @param \WC_Product $parent   The variable product.
	 * @param array       $settings Settings.
	 * @param bool        $saved    Whether the product is in the saved
	 *                              settings (false: picked just now — every
	 *                              variation that can be a design is ticked
	 *                              and none is marked new).
	 * @return array{parent: \WC_Product, rows: array[]}
	 */
	private function variation_group( \WC_Product $parent, array $settings, $saved ) {
		$pool = Rules::pool( $settings );
		$seen = Rules::ids( $settings['sticker_seen_ids'] ?? array() );
		$rows = array();

		foreach ( Rules::ids( $parent->get_children() ) as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof \WC_Product ) {
				continue;
			}

			$fixed  = Designs::is_fixed( $child );
			$rows[] = array(
				'id'     => $child_id,
				'name'   => Designs::name( $child ),
				'image'  => $child->get_image( 'thumbnail' ),
				'fixed'  => $fixed,
				'ticked' => $fixed && ( $saved ? in_array( $child_id, $pool, true ) : true ),
				'new'    => Rules::variation_is_new( $child_id, $fixed, $saved, $seen, $pool ),
			);
		}

		return array(
			'parent' => $parent,
			'rows'   => $rows,
		);
	}

	/**
	 * The variation lists of every picked variable product.
	 *
	 * @param array $settings Settings.
	 * @return array[]
	 */
	private function variation_groups( array $settings ) {
		$groups = array();

		foreach ( Rules::ids( $settings['sticker_parent_ids'] ?? array() ) as $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent instanceof \WC_Product && $parent->is_type( 'variable' ) ) {
				$groups[] = $this->variation_group( $parent, $settings, true );
			}
		}

		return $groups;
	}

	/**
	 * The parts of the page that change with a save or a stock act, as HTML:
	 * the variation lists, the stock table, the warnings — and the summary
	 * line. Rendered here for the first paint and sent back by every AJAX
	 * answer, so the page never shows anything the server did not store.
	 *
	 * @param array|null $settings Settings (defaults to the saved ones).
	 * @return array{variations: string, stock: string, warnings: string, summary: string}
	 */
	public function fragments( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : Settings::get();

		$groups     = $this->variation_groups( $settings );
		$stock_rows = Reorder::rows( $settings );
		$warnings   = $this->pool_warnings( $settings, $groups );

		return array(
			'variations' => self::capture( 'variations', compact( 'groups' ) ),
			'stock'      => self::capture( 'stock-table', compact( 'stock_rows' ) ),
			'warnings'   => self::capture( 'warnings', compact( 'warnings' ) ),
			'summary'    => self::summary( $settings, array_sum( array_map( 'count', array_column( $stock_rows, 'designs' ) ) ) ),
		);
	}

	/**
	 * Render a view into a string.
	 *
	 * @param string $view View name (views/<name>.php).
	 * @param array  $vars Variables for the view.
	 * @return string
	 */
	private static function capture( $view, array $vars ) {
		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- a view's own variables, named by this class.
		extract( $vars, EXTR_SKIP );
		include Sticker_Giveaway::path() . 'views/' . $view . '.php';

		return (string) ob_get_clean();
	}

	/* ============================================================== */
	/*  AJAX                                                           */
	/* ============================================================== */

	/**
	 * AJAX — the variations of a product picked in the search just now,
	 * as the list the page shows under the search (empty for a simple
	 * product).
	 */
	public function ajax_variations() {
		if ( ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( Settings::NONCE_ACTION, '_wpnonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_success(
				array(
					'html'    => '',
					'designs' => $product instanceof \WC_Product && $product->is_type( 'simple' ) ? 1 : 0,
				)
			);
		}

		$settings = Settings::get();
		$saved    = in_array( $product_id, Rules::ids( $settings['sticker_parent_ids'] ?? array() ), true );
		$groups   = array( $this->variation_group( $product, $settings, $saved ) );

		wp_send_json_success(
			array(
				'html' => self::capture( 'variations', compact( 'groups' ) ),
			)
		);
	}

	/**
	 * AJAX — "Add stickers" (+N: a new box arrived) and "Recount" (set to
	 * N: what was counted by hand) for one row of the stock table. Both
	 * change the product's stock through WooCommerce's own
	 * wc_update_product_stock(), so the product's own page shows the same
	 * number at once and the reorder look runs as for any stock change.
	 * Done at once; a wrong number is put right with another Recount.
	 */
	public function ajax_stock() {
		if ( ! current_user_can( Sticker_Giveaway::SETTINGS_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'fishotel-misc-plugin' ) ), 403 );
		}

		check_ajax_referer( Settings::NONCE_ACTION, '_wpnonce' );

		$settings = Settings::get();
		$count_id = isset( $_POST['count_id'] ) ? absint( $_POST['count_id'] ) : 0;
		$act      = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : '';
		$typed    = isset( $_POST['n'] ) && is_scalar( $_POST['n'] ) ? trim( (string) wp_unslash( $_POST['n'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by count_error() below.
		$rows     = Reorder::rows( $settings );
		$product  = isset( $rows[ $count_id ] ) ? wc_get_product( $count_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			wp_send_json_error( array( 'message' => __( 'That design is not in the pool. Save the settings first.', 'fishotel-misc-plugin' ) ), 400 );
		}

		$name = (string) $rows[ $count_id ]['name'];

		if ( ! $product->managing_stock() ) {
			/* translators: %s: design name */
			wp_send_json_error( array( 'message' => sprintf( __( '%s: WooCommerce does not track its stock. Tick "Manage stock" on the product page first.', 'fishotel-misc-plugin' ), $name ) ), 400 );
		}

		$error = self::count_error( $act, $typed );
		if ( '' !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 400 );
		}

		$now = wc_update_product_stock( $product, (int) $typed, 'add' === $act ? 'increase' : 'set' );

		wp_send_json_success(
			array(
				'message'   => self::count_message( $act, $name, (int) $typed, (int) $now ),
				'fragments' => $this->fragments( $settings ),
			)
		);
	}

	/**
	 * What is wrong with a typed count, in plain words ('' = nothing).
	 * Pure.
	 *
	 * @param string $act   'add' or 'recount'.
	 * @param string $typed What was typed.
	 * @return string
	 */
	public static function count_error( $act, $typed ) {
		if ( 'add' !== $act && 'recount' !== $act ) {
			return __( 'Unknown action.', 'fishotel-misc-plugin' );
		}

		$typed = trim( (string) $typed );

		if ( '' === $typed ) {
			return __( 'Type a number first.', 'fishotel-misc-plugin' );
		}
		if ( ! preg_match( '/^\d+$/', $typed ) ) {
			return __( 'Type a whole number, without a sign or a decimal point.', 'fishotel-misc-plugin' );
		}

		$n = (int) $typed;

		if ( 'add' === $act && $n < 1 ) {
			return __( 'Type how many stickers you are adding (1 or more). To put a wrong count right, use Recount.', 'fishotel-misc-plugin' );
		}
		if ( strlen( $typed ) > 7 || $n > self::MAX_COUNT ) {
			/* translators: %s: the largest count accepted */
			return sprintf( __( 'That is more than %s stickers. Check the number.', 'fishotel-misc-plugin' ), number_format( self::MAX_COUNT ) );
		}

		return '';
	}

	/**
	 * What an act did, in one sentence. Pure.
	 *
	 * @param string $act  'add' or 'recount'.
	 * @param string $name Design name.
	 * @param int    $n    The typed number.
	 * @param int    $now  The stock number now.
	 * @return string
	 */
	public static function count_message( $act, $name, $n, $now ) {
		if ( 'add' === $act ) {
			/* translators: 1: design, 2: added, 3: in stock now */
			return sprintf( __( '%1$s: %2$d added. In stock now: %3$d.', 'fishotel-misc-plugin' ), $name, $n, $now );
		}

		/* translators: 1: design, 2: in stock now */
		return sprintf( __( '%1$s: counted. In stock now: %2$d.', 'fishotel-misc-plugin' ), $name, $now );
	}

	/* ============================================================== */
	/*  Warnings                                                       */
	/* ============================================================== */

	/**
	 * Warnings shown under the pool: every ticked design that cannot be
	 * handed out and why, and anything about its product that will look
	 * wrong in the cart.
	 *
	 * @param array   $settings Settings.
	 * @param array[] $groups   variation_groups().
	 * @return string[]
	 */
	private function pool_warnings( array $settings, array $groups ) {
		$warnings = array();
		$designs  = array();

		foreach ( Rules::pool( $settings ) as $id ) {
			$design = wc_get_product( $id );
			if ( $design instanceof \WC_Product ) {
				$designs[ $id ] = $design;
			}
		}

		if ( $designs && 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			$warnings[] = __( 'WooCommerce\'s stock management is switched off (WooCommerce → Settings → Products → Inventory), so no sticker is counted and there is no reorder reminder.', 'fishotel-misc-plugin' );
		}

		// Per design: why it is not offered.
		foreach ( $designs as $design ) {
			foreach ( Designs::blockers( $design ) as $code ) {
				$warnings[] = self::blocker_text( $code, Designs::name( $design ), $design->is_type( 'variation' ) );
			}
		}

		// Per variable product: nothing ticked, or variations added since.
		foreach ( $groups as $group ) {
			$name   = $group['parent']->get_name();
			$ticked = count( array_filter( array_column( $group['rows'], 'ticked' ) ) );
			$new    = count( array_filter( array_column( $group['rows'], 'new' ) ) );

			if ( ! $group['rows'] ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s has no variations yet — add them on the product page, then tick them here.', 'fishotel-misc-plugin' ), $name );
			} elseif ( $ticked < 1 ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s: no variation is ticked, so none of its designs is offered.', 'fishotel-misc-plugin' ), $name );
			}
			if ( $new > 0 ) {
				/* translators: 1: product name, 2: number of variations */
				$warnings[] = sprintf( _n( '%1$s has %2$d new variation. It is not offered until you tick it and save.', '%1$s has %2$d new variations. They are not offered until you tick them and save.', $new, 'fishotel-misc-plugin' ), $name, $new );
			}
		}

		// Per product (a variable product once, not once per variation):
		// what will look wrong in the cart.
		$preset_cats = self::theme_preset_category_slugs();
		$theme_skips = self::theme_skips_sticker_lines();
		$seen        = array();

		foreach ( $designs as $design ) {
			$product_id = Designs::parent_id( $design );
			if ( isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$seen[ $product_id ] = true;

			$product = $product_id === (int) $design->get_id() ? $design : wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$name = $product->get_name();

			if ( $product->is_sold_individually() ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s is marked "Sold individually" — add-on quantity for it is capped at 1.', 'fishotel-misc-plugin' ), $name );
			}

			$default_cat      = (int) get_option( 'default_product_cat', 0 );
			$default_term     = $default_cat > 0 && function_exists( 'get_term' ) ? get_term( $default_cat, 'product_cat' ) : null;
			$category_warning = self::category_warning(
				$name,
				method_exists( $product, 'get_category_ids' ) ? (array) $product->get_category_ids() : array(),
				$default_cat,
				is_object( $default_term ) && isset( $default_term->name ) ? (string) $default_term->name : ''
			);
			if ( '' !== $category_warning ) {
				$warnings[] = $category_warning;
			}

			$preset_warning = self::preset_warning( $name, array_values( array_intersect( $preset_cats, Cart::category_slugs( $product_id ) ) ), $theme_skips );
			if ( '' !== $preset_warning ) {
				$warnings[] = $preset_warning;
			}

			if ( ! $theme_skips && '' !== trim( (string) $product->get_short_description() ) ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s has a short description — this theme version prints a line\'s short description as its italic scientific name in the cart. Leave it empty for stickers, or update the FisHotel theme (a newer one leaves it off sticker lines).', 'fishotel-misc-plugin' ), $name );
			}
		}

		return $warnings;
	}

	/**
	 * Why a design is not offered, as a sentence (pure). '' for a code
	 * that needs no words.
	 *
	 * @param string $code         One of Rules::blockers()'s codes.
	 * @param string $name         The design's name.
	 * @param bool   $is_variation Whether the design is a variation.
	 * @return string
	 */
	public static function blocker_text( $code, $name, $is_variation = false ) {
		switch ( $code ) {
			case 'type':
				/* translators: %s: design name */
				return sprintf( __( '%s is not a simple product or a variation — the picker only offers those. Take it out of the pool.', 'fishotel-misc-plugin' ), $name );
			case 'unpublished':
				return $is_variation
					/* translators: %s: design name */
					? sprintf( __( '%s is not enabled (the "Enabled" tick on the variation) — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name )
					/* translators: %s: design name */
					: sprintf( __( '%s is not published — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			case 'parent_unpublished':
				/* translators: %s: design name */
				return sprintf( __( '%s: its product is not published — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			case 'no_price':
				/* translators: %s: design name */
				return sprintf( __( '%s has no price — give it a regular price (the picker overrides it) so WooCommerce treats it as purchasable.', 'fishotel-misc-plugin' ), $name );
			case 'not_purchasable':
				/* translators: %s: design name */
				return sprintf( __( '%s is not purchasable (another plugin is blocking it) — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			case 'any_attribute':
				/* translators: %s: design name */
				return sprintf( __( '%s leaves an attribute on "Any". A design has to be one exact variation — choose a value for every attribute on the product page. Until then it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			case 'out_of_stock':
				/* translators: %s: design name */
				return sprintf( __( '%s is out of stock — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
		}

		return '';
	}

	/**
	 * The warning for a sticker product saved with no category of its own
	 * (pure): WooCommerce then files it under the store's default
	 * category, which the sticker was never meant to be in. '' when it has
	 * a category of its own.
	 *
	 * @param string $name         Product name.
	 * @param int[]  $category_ids The product's categories.
	 * @param int    $default_id   The store's default category (default_product_cat).
	 * @param string $default_name Its name ('' when not known).
	 * @return string
	 */
	public static function category_warning( $name, array $category_ids, $default_id, $default_name = '' ) {
		$ids = array_values( array_filter( array_map( 'intval', $category_ids ) ) );
		if ( $ids && ( (int) $default_id <= 0 || array( (int) $default_id ) !== $ids ) ) {
			return '';
		}
		$where = '' !== $default_name
			/* translators: %s: category name */
			? sprintf( __( 'the store\'s default category, "%s"', 'fishotel-misc-plugin' ), $default_name )
			: __( 'the store\'s default category', 'fishotel-misc-plugin' );

		/* translators: 1: product name, 2: where WooCommerce put it */
		return sprintf( __( '%1$s was saved with no category, so WooCommerce put it in %2$s. Give it a category of its own (e.g. "stickers") on the product page.', 'fishotel-misc-plugin' ), (string) $name, $where );
	}

	/**
	 * The warning for a sticker product in a category the theme maps to a
	 * cart preset (pure). The theme picks its cart and checkout wording
	 * from the categories of the cart's lines. A theme that skips sticker
	 * lines there is not thrown by a sticker in "Merchandise" — then there
	 * is nothing to warn about, and nothing to re-categorise. Only a theme
	 * that still counts sticker lines gets the warning.
	 *
	 * @param string   $name        Product name.
	 * @param string[] $in_preset   The preset-mapped categories it is in.
	 * @param bool     $theme_skips Whether the theme skips sticker lines.
	 * @return string
	 */
	public static function preset_warning( $name, array $in_preset, $theme_skips ) {
		if ( $theme_skips || ! $in_preset ) {
			return '';
		}

		/* translators: 1: product name, 2: comma-separated category slugs */
		return sprintf(
			__( '%1$s is in the %2$s category, which the theme uses to pick cart/checkout wording. This theme version counts sticker lines too, so a sticker in that category makes a fish order fall back to the generic wording. A newer FisHotel theme skips sticker lines — update the theme and this goes away by itself; otherwise move the product to a category the theme does not map (e.g. "stickers").', 'fishotel-misc-plugin' ),
			(string) $name,
			implode( ', ', $in_preset )
		);
	}

	/**
	 * Whether the active theme skips sticker lines when it reads the cart
	 * (its preset wording, its "N fish" count, the italic scientific
	 * name). `fishotel_cart_display_count()` arrived in the theme with
	 * exactly that skip, so its presence is the sign.
	 *
	 * @return bool
	 */
	private static function theme_skips_sticker_lines() {
		return function_exists( 'fishotel_cart_display_count' );
	}

	/**
	 * Category slugs the FisHotel theme maps to a cart preset (fish, meds,
	 * food, merch).
	 *
	 * @return string[]
	 */
	private static function theme_preset_category_slugs() {
		if ( ! function_exists( 'fishotel_cart_preset_slug_map' ) ) {
			return array();
		}

		$map = fishotel_cart_preset_slug_map();

		return is_array( $map ) ? array_map( 'strval', array_keys( $map ) ) : array();
	}

	/**
	 * Flat, depth-annotated list of product categories for the checkbox tree.
	 *
	 * @return array<int, array{term: \WP_Term, depth: int}>
	 */
	private function category_tree() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $by_parent ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $term ) {
				$out[] = array(
					'term'  => $term,
					'depth' => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * One-line summary of the live configuration, e.g.
	 * "3 stickers in pool · free with: quarantined-fish · add-ons $1.00 · max 5".
	 *
	 * @param array $settings   Settings.
	 * @param int   $pool_count Resolved pool size.
	 * @return string
	 */
	public static function summary( array $settings, $pool_count ) {
		$parts = array();

		if ( $pool_count < 1 ) {
			$parts[] = __( 'no stickers in pool — picker hidden', 'fishotel-misc-plugin' );
		} else {
			/* translators: %d: number of sticker products */
			$parts[] = sprintf( _n( '%d sticker in pool', '%d stickers in pool', $pool_count, 'fishotel-misc-plugin' ), $pool_count );
		}

		$slugs = Rules::eligible_slugs( $settings );
		if ( $slugs ) {
			/* translators: %s: comma-separated category slugs */
			$parts[] = sprintf( __( 'free with: %s', 'fishotel-misc-plugin' ), implode( ', ', $slugs ) );
		} else {
			$parts[] = __( 'no categories ticked — no free stickers', 'fishotel-misc-plugin' );
		}

		if ( (float) $settings['min_subtotal'] > 0 ) {
			/* translators: %s: formatted price */
			$parts[] = sprintf( __( 'min subtotal %s', 'fishotel-misc-plugin' ), Sticker_Giveaway::plain_price( (float) $settings['min_subtotal'] ) );
		}

		if ( ! empty( $settings['upsell_enabled'] ) ) {
			/* translators: %s: formatted price */
			$parts[] = sprintf( __( 'add-ons %s', 'fishotel-misc-plugin' ), Sticker_Giveaway::plain_price( Rules::price_for( Rules::KIND_EXTRA, $settings ) ) );
			/* translators: %d: max add-on quantity */
			$parts[] = sprintf( __( 'max %d', 'fishotel-misc-plugin' ), (int) $settings['upsell_max_qty'] );
		} else {
			$parts[] = __( 'add-ons off', 'fishotel-misc-plugin' );
		}

		return implode( ' · ', $parts );
	}
}
