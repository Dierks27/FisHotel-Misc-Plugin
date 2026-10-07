<?php
/**
 * Sticker Giveaway admin — the settings page under FisHotel Tools.
 *
 * WHY a product search instead of a dropdown: Jeff adds sticker designs
 * as ordinary simple products, so the pool is picked with WooCommerce's
 * own product search (wc-enhanced-select) rather than a hand-maintained
 * list. Eligible categories come from the live product_cat tree so a
 * renamed or added category never needs a code change.
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
	 * Register hooks.
	 *
	 * Priority 20 on admin_enqueue_scripts: WooCommerce registers
	 * `wc-enhanced-select` and `woocommerce_admin_styles` at priority 10
	 * from a hook it adds on `init`; ours must run after that.
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
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

		$settings      = Settings::get();
		$pool_products = $this->pool_products( $settings );
		$categories    = $this->category_tree();
		$summary       = self::summary( $settings, count( $pool_products ) );
		$warnings      = $this->pool_warnings( $pool_products );

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
					'saved'     => __( 'Settings saved.', 'fishotel-misc-plugin' ),
					'error'     => __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ),
					'stickers'  => __( '%d stickers in pool', 'fishotel-misc-plugin' ),
					'sticker'   => __( '%d sticker in pool', 'fishotel-misc-plugin' ),
					'noPool'    => __( 'no stickers in pool — picker hidden', 'fishotel-misc-plugin' ),
					'freeWith'  => __( 'free with: %s', 'fishotel-misc-plugin' ),
					'freeAll'   => __( 'free with every order', 'fishotel-misc-plugin' ),
					'minSub'    => __( 'min subtotal %s', 'fishotel-misc-plugin' ),
					'addons'    => __( 'add-ons %s', 'fishotel-misc-plugin' ),
					'addonsOff' => __( 'add-ons off', 'fishotel-misc-plugin' ),
					'max'       => __( 'max %d', 'fishotel-misc-plugin' ),
				),
			)
		);
	}

	/**
	 * Resolve the saved pool to products for pre-rendering the select.
	 *
	 * @param array $settings Settings.
	 * @return array<int, \WC_Product>
	 */
	private function pool_products( array $settings ) {
		$products = array();

		foreach ( Rules::pool( $settings ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof \WC_Product ) {
				$products[ $pid ] = $product;
			}
		}

		return $products;
	}

	/**
	 * Per-product warnings shown under the pool field: anything that would
	 * stop the picker from handing the sticker out.
	 *
	 * @param array<int, \WC_Product> $products Pool products.
	 * @return string[]
	 */
	private function pool_warnings( array $products ) {
		$warnings = array();

		foreach ( $products as $product ) {
			$name = $product->get_name();

			if ( ! $product->is_type( 'simple' ) ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s is not a simple product — the picker only offers simple products.', 'fishotel-misc-plugin' ), $name );
			}
			if ( 'publish' !== $product->get_status() ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s is not published — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			}
			if ( ! $product->is_purchasable() ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s has no price — give it a regular price (the picker overrides it) so WooCommerce treats it as purchasable.', 'fishotel-misc-plugin' ), $name );
			}
			if ( ! $product->is_in_stock() ) {
				/* translators: %s: product name */
				$warnings[] = sprintf( __( '%s is out of stock — it will not appear in the picker.', 'fishotel-misc-plugin' ), $name );
			}
		}

		return $warnings;
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
			$parts[] = __( 'free with every order', 'fishotel-misc-plugin' );
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
