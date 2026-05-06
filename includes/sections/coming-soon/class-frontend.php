<?php
/**
 * Coming Soon frontend — purchase blocks, ribbon overlay,
 * countdown widgets, single-product replacement, and shop sort.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

namespace FisHotel\Misc\Sections\Coming_Soon;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Register hooks.
	 */
	public function init() {
		// ── Server-side purchase blocks ──────────────────────────────
		add_filter( 'woocommerce_is_purchasable', array( $this, 'block_purchase' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'block_purchase' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'block_add_to_cart' ), 10, 3 );

		// ── Shop loop / archive display ─────────────────────────────
		add_filter( 'post_class', array( $this, 'product_classes' ), 10, 3 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_loop_ribbon' ), 9 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_countdown' ), 11 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_add_to_cart' ), 10, 2 );

		// ── Single product page ─────────────────────────────────────
		add_action( 'template_redirect', array( $this, 'maybe_replace_single_add_to_cart' ) );
		add_action( 'woocommerce_product_thumbnails', array( $this, 'render_single_ribbon' ), 5 );

		// ── Shop / archive / search sort ────────────────────────────
		add_filter( 'posts_clauses', array( $this, 'sort_clauses' ), 10, 2 );

		// ── Asset enqueue ───────────────────────────────────────────
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/* ============================================================== */
	/*  Purchase Blocks                                                */
	/* ============================================================== */

	/**
	 * Filter `woocommerce_is_purchasable` and
	 * `woocommerce_variation_is_purchasable` — return false while
	 * the product (or its parent) is in Coming Soon mode.
	 *
	 * @param bool        $purchasable Current value.
	 * @param \WC_Product $product     The product or variation.
	 * @return bool
	 */
	public function block_purchase( $purchasable, $product ) {
		if ( $purchasable && Coming_Soon::is_coming_soon( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * Filter `woocommerce_add_to_cart_validation` — reject any
	 * add-to-cart attempt for a coming-soon product (covers direct
	 * `?add-to-cart=ID` URL bypass attempts).
	 *
	 * @param bool $passed     Current validation result.
	 * @param int  $product_id Parent product ID being added.
	 * @param int  $quantity   Quantity (unused).
	 * @return bool
	 */
	public function block_add_to_cart( $passed, $product_id, $quantity ) {
		unset( $quantity );

		if ( ! $passed ) {
			return $passed;
		}

		if ( Coming_Soon::is_coming_soon( $product_id ) ) {
			$ribbon = Settings::get_value( 'ribbon_text' );
			wc_add_notice(
				sprintf(
					/* translators: %s: ribbon text, e.g. "Coming Soon" */
					__( 'This product is %s and cannot be purchased yet.', 'fishotel-misc-plugin' ),
					$ribbon
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/* ============================================================== */
	/*  Shop Loop / Archive Display                                    */
	/* ============================================================== */

	/**
	 * Add `fh-coming-soon` to product post classes (shop + single).
	 *
	 * @param array      $classes Existing classes.
	 * @param array|string $css_class Extra classes (unused).
	 * @param int        $post_id Post ID.
	 * @return array
	 */
	public function product_classes( $classes, $css_class, $post_id ) {
		unset( $css_class );

		if ( 'product' !== get_post_type( $post_id ) ) {
			return $classes;
		}

		if ( Coming_Soon::is_coming_soon( $post_id ) ) {
			$classes[] = 'fh-coming-soon';
		}

		return $classes;
	}

	/**
	 * Render the ribbon overlay inside the shop-loop product link.
	 *
	 * Fires on `woocommerce_before_shop_loop_item_title` (priority 9 —
	 * just before the title at priority 10). The ribbon is positioned
	 * absolutely over the product image via CSS.
	 */
	public function render_loop_ribbon() {
		global $product;

		if ( ! $product || ! Coming_Soon::is_coming_soon( $product ) ) {
			return;
		}

		$this->print_ribbon();
	}

	/**
	 * Render the ribbon on the single product page (inside the gallery
	 * container so absolute positioning is anchored to the image).
	 */
	public function render_single_ribbon() {
		global $product;

		if ( ! $product || ! Coming_Soon::is_coming_soon( $product ) ) {
			return;
		}

		$this->print_ribbon();
	}

	/**
	 * Print the ribbon HTML.
	 */
	private function print_ribbon() {
		$text = Settings::get_value( 'ribbon_text' );
		printf(
			'<span class="fh-cs-ribbon" aria-hidden="true">%s</span>',
			esc_html( $text )
		);
	}

	/**
	 * Render the countdown widget in the shop loop, after the title.
	 */
	public function render_loop_countdown() {
		if ( ! Settings::get_value( 'show_countdown_loop' ) ) {
			return;
		}

		global $product;
		if ( ! $product || ! Coming_Soon::is_coming_soon( $product ) ) {
			return;
		}

		$ts = Coming_Soon::get_release_timestamp( $product );
		echo $this->build_countdown_html( $ts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Replace the loop add-to-cart link with a "Notify Me" anchor.
	 *
	 * @param string      $link    Default add-to-cart link HTML.
	 * @param \WC_Product $product Loop product.
	 * @return string
	 */
	public function filter_loop_add_to_cart( $link, $product ) {
		if ( ! $product || ! Coming_Soon::is_coming_soon( $product ) ) {
			return $link;
		}

		return $this->build_notify_button( 'fh-cs-notify-button button' );
	}

	/* ============================================================== */
	/*  Single Product Page Replacement                                */
	/* ============================================================== */

	/**
	 * On product pages where the product is in Coming Soon mode,
	 * unhook the default add-to-cart template and replace it with our
	 * custom render at the same priority.
	 *
	 * Hooked to `template_redirect` so that `is_product()` and
	 * `wc_get_product()` are reliable.
	 */
	public function maybe_replace_single_add_to_cart() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! Coming_Soon::is_coming_soon( $product ) ) {
			return;
		}

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_coming_soon' ), 30 );
	}

	/**
	 * Render the Coming Soon block in place of the single-product
	 * add-to-cart form: countdown, price range, total stock, notify
	 * button. Variations dropdown is omitted entirely.
	 */
	public function render_single_coming_soon() {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$release_ts = Coming_Soon::get_release_timestamp( $product );
		if ( ! $release_ts ) {
			return;
		}

		$show_countdown = (bool) Settings::get_value( 'show_countdown_single' );
		$price_html     = $this->get_price_html( $product );
		$stock_html     = $this->get_total_stock_html( $product );

		echo '<div class="fh-cs-single">';

		if ( $show_countdown ) {
			echo $this->build_countdown_html( $release_ts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $price_html ) {
			echo '<div class="fh-cs-price">' . $price_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $stock_html ) {
			echo '<div class="fh-cs-stock">' . esc_html( $stock_html ) . '</div>';
		}

		echo $this->build_notify_button( 'fh-cs-notify-button fh-cs-notify-button--single button alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';
	}

	/**
	 * Build the price-range string for the single product page.
	 *
	 * @param \WC_Product $product Product.
	 * @return string HTML (already escaped via wc_price).
	 */
	private function get_price_html( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );

			if ( $min && $max && $min !== $max ) {
				return sprintf(
					/* translators: 1: minimum price, 2: maximum price */
					__( 'From %1$s &ndash; %2$s', 'fishotel-misc-plugin' ),
					wc_price( $min ),
					wc_price( $max )
				);
			}

			if ( $min ) {
				return wc_price( $min );
			}
		}

		$price = $product->get_price();
		if ( '' !== $price && null !== $price ) {
			return wc_price( $price );
		}

		return '';
	}

	/**
	 * Build the total-stock string ("X available at release") for
	 * variable products with managed stock on every variation.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Plain-text label, or empty string.
	 */
	private function get_total_stock_html( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return '';
		}

		$total       = 0;
		$all_managed = true;

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->variation_is_active() ) {
				continue;
			}

			if ( ! $variation->managing_stock() ) {
				$all_managed = false;
				break;
			}

			$total += max( 0, (int) $variation->get_stock_quantity() );
		}

		if ( ! $all_managed ) {
			return __( 'Available at release', 'fishotel-misc-plugin' );
		}

		return sprintf(
			/* translators: %d: total stock quantity */
			_n( '%d available at release', '%d available at release', $total, 'fishotel-misc-plugin' ),
			$total
		);
	}

	/**
	 * Build a "Notify Me" anchor pointing at the configured URL.
	 *
	 * @param string $classes CSS classes to apply to the anchor.
	 * @return string
	 */
	private function build_notify_button( $classes ) {
		$url = Settings::get_value( 'notify_me_url' );

		if ( empty( $url ) ) {
			$url = Settings::DEFAULTS['notify_me_url'];
		}

		// Honour root-relative URLs verbatim, otherwise pass through esc_url.
		$href = ( '/' === substr( $url, 0, 1 ) && '/' !== substr( $url, 1, 1 ) )
			? esc_url( home_url( $url ) )
			: esc_url( $url );

		return sprintf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			$href,
			esc_attr( $classes ),
			esc_html__( 'Notify Me', 'fishotel-misc-plugin' )
		);
	}

	/**
	 * Build a countdown widget HTML snippet.
	 *
	 * @param int $release_ts Unix release timestamp.
	 * @return string
	 */
	private function build_countdown_html( $release_ts ) {
		return sprintf(
			'<div class="fh-cs-countdown" data-release-ts="%1$d" role="timer" aria-live="polite">'
				. '<span class="fh-cs-countdown__digits">&mdash;</span>'
			. '</div>',
			absint( $release_ts )
		);
	}

	/* ============================================================== */
	/*  Sort: coming-soon first                                        */
	/* ============================================================== */

	/**
	 * Modify product-archive query clauses so coming-soon products
	 * always appear first regardless of the user-selected sort.
	 *
	 * Implementation: LEFT JOIN on `_fh_release_datetime`, then
	 * prepend a primary `(meta_value > NOW) DESC` to the existing
	 * ORDER BY. The user's chosen sort becomes secondary, so within
	 * the coming-soon group and within the live group the relative
	 * order they expect is preserved.
	 *
	 * @param array     $clauses SQL clauses.
	 * @param \WP_Query $query   The query.
	 * @return array
	 */
	public function sort_clauses( $clauses, $query ) {
		if ( is_admin() || ! $query instanceof \WP_Query ) {
			return $clauses;
		}

		if ( ! Settings::get_value( 'sort_first' ) ) {
			return $clauses;
		}

		if ( ! $this->query_targets_products( $query ) ) {
			return $clauses;
		}

		global $wpdb;

		$alias       = 'fh_cs_release';
		$join_clause = " LEFT JOIN {$wpdb->postmeta} AS {$alias} "
			. "ON {$alias}.post_id = {$wpdb->posts}.ID "
			. "AND {$alias}.meta_key = '" . esc_sql( Coming_Soon::META_KEY ) . "' ";

		// Avoid duplicate joins when posts_clauses fires more than once.
		if ( strpos( $clauses['join'], $alias ) === false ) {
			$clauses['join'] .= $join_clause;
		}

		$now     = (int) time();
		// COALESCE handles the LEFT JOIN's NULL rows for products
		// without our meta (and any non-products that sneak into a
		// mixed query like search). They evaluate as 0 → FALSE → the
		// "live" group, where the user's chosen sort applies normally.
		$primary = "(CAST(COALESCE({$alias}.meta_value, '0') AS UNSIGNED) > {$now}) DESC";

		if ( ! empty( $clauses['orderby'] ) ) {
			$clauses['orderby'] = $primary . ', ' . $clauses['orderby'];
		} else {
			$clauses['orderby'] = $primary;
		}

		return $clauses;
	}

	/**
	 * Whether a query is one we want to coming-soon-sort.
	 *
	 * Only main queries on shop archive, product taxonomy archives,
	 * and search results — NOT related-products / upsell / cross-sell
	 * sub-queries (those keep their own ordering, which is what the
	 * customer expects from "you may also like" lists).
	 *
	 * @param \WP_Query $query The query.
	 * @return bool
	 */
	private function query_targets_products( $query ) {
		if ( ! $query->is_main_query() ) {
			return false;
		}

		if ( $query->is_post_type_archive( 'product' ) ) {
			return true;
		}

		$product_taxes = get_object_taxonomies( 'product' );
		if ( ! empty( $product_taxes ) && $query->is_tax( $product_taxes ) ) {
			return true;
		}

		// Frontend search may include products even with no post_type set.
		if ( $query->is_search() ) {
			return true;
		}

		return false;
	}

	/* ============================================================== */
	/*  Asset Enqueue                                                  */
	/* ============================================================== */

	/**
	 * Enqueue frontend CSS / JS.
	 *
	 * Always loaded on shop / archive / single product so that the
	 * countdown can run wherever a coming-soon product appears
	 * (related products, upsells, custom blocks). Only the JS does
	 * any work — if no countdown elements exist, it's a no-op.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		$is_wc_page = is_woocommerce() || is_shop() || is_product() || is_product_category() || is_product_tag() || is_cart() || is_checkout();
		if ( ! $is_wc_page && ! is_search() ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-coming-soon-front',
			Coming_Soon::url() . 'css/coming-soon-front.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-coming-soon-front',
			Coming_Soon::url() . 'js/coming-soon-front.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_localize_script(
			'fishotel-coming-soon-front',
			'fhComingSoon',
			array(
				'serverTime'    => time(),
				'autoReload'    => (bool) Settings::get_value( 'auto_reload' ),
				'jitterSeconds' => max( 0, (int) Settings::get_value( 'reload_jitter' ) ),
				'i18n'          => array(
					'available' => __( 'Now available &mdash; refresh page', 'fishotel-misc-plugin' ),
					'days'      => __( 'd', 'fishotel-misc-plugin' ),
					'hours'     => __( 'h', 'fishotel-misc-plugin' ),
					'minutes'   => __( 'm', 'fishotel-misc-plugin' ),
					'seconds'   => __( 's', 'fishotel-misc-plugin' ),
				),
			)
		);
	}
}
