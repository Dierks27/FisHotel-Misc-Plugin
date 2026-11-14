<?php
/**
 * Who can FIND an invoice — an invoice is opened by its link, and by
 * nothing else.
 *
 * An invoice product is "hidden" for WooCommerce: not in the shop, not
 * in the category archive, not in the site search. But a hidden product
 * is still a published post, and WordPress and WooCommerce list
 * published products in places a customer never sees: the product
 * sitemap, the REST lists of products (wp/v2/product and the Store API's
 * wc/store/…/products) and the REST search. Each of them handed a
 * logged-out visitor every invoice — the handle, the fish, the price and
 * the link.
 *
 * This class takes the products of the Invoices category out of those
 * four lists and marks an invoice's own page "noindex". It changes no
 * product: an invoice made by hand is taken out of the lists the same
 * way and is otherwise untouched. Someone who may manage the shop still
 * sees everything.
 *
 * What it does not do: the invoice's own page, and the product asked for
 * by its id, still answer — the link is how the customer opens it. A
 * sitemap built by an SEO plugin is that plugin's to configure.
 *
 * Cost: registering five hooks. An ordinary front-end request asks
 * nothing here — the settings are read only when one of those lists is
 * really being built, and the noindex goes by what WordPress has already
 * loaded for the page (this section's mark, or the `invoices` category).
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices;

defined( 'ABSPATH' ) || exit;

/**
 * Class Visibility
 */
class Visibility {

	const TAXONOMY = 'product_cat';

	/**
	 * Register the hooks.
	 */
	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemap_args' ), 10, 2 );
		add_filter( 'rest_product_query', array( self::class, 'rest_args' ), 10, 1 );
		add_filter( 'rest_post_search_query', array( self::class, 'search_args' ), 10, 1 );
		add_action( 'pre_get_posts', array( self::class, 'store_api_query' ), 10, 1 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 10, 1 );
	}

	/**
	 * The category slugs an invoice product is in: the one named in
	 * Settings, and the theme's fixed `invoices`.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		$settings = Settings::get();
		$slugs    = array( Grammar::CATEGORY_SLUG );
		if ( ! empty( $settings['category_slug'] ) ) {
			$slugs[] = (string) $settings['category_slug'];
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Whether the lists stay whole for this request: the taxonomy is not
	 * there (WooCommerce is off — a condition on it would empty the
	 * list), or the visitor may manage the shop.
	 *
	 * @return bool
	 */
	private static function untouched() {
		return ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( self::TAXONOMY ) || current_user_can( Invoices::CAPABILITY );
	}

	/**
	 * Query arguments with "not in the Invoices category" added to
	 * whatever taxonomy conditions they already carry (pure).
	 *
	 * @param mixed    $args  WP_Query arguments.
	 * @param string[] $slugs Category slugs.
	 * @return array
	 */
	public static function without_invoices( $args, array $slugs ) {
		$args = is_array( $args ) ? $args : array();
		if ( ! $slugs ) {
			return $args;
		}

		$ours = array(
			'taxonomy' => self::TAXONOMY,
			'field'    => 'slug',
			'terms'    => array_values( $slugs ),
			'operator' => 'NOT IN',
		);

		$args['tax_query'] = empty( $args['tax_query'] ) || ! is_array( $args['tax_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- only on the sitemap and the REST product lists.
			? array( $ours )
			: array(
				'relation' => 'AND',
				$args['tax_query'],
				$ours,
			);

		return $args;
	}

	/**
	 * The core product sitemap.
	 *
	 * @param mixed  $args      WP_Query arguments.
	 * @param string $post_type Post type of the sitemap.
	 * @return mixed
	 */
	public static function sitemap_args( $args, $post_type = '' ) {
		if ( 'product' !== $post_type || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return $args;
		}
		return self::without_invoices( $args, self::slugs() );
	}

	/**
	 * The REST list of products (wp/v2/product).
	 *
	 * @param mixed $args WP_Query arguments.
	 * @return mixed
	 */
	public static function rest_args( $args ) {
		return self::untouched() ? $args : self::without_invoices( $args, self::slugs() );
	}

	/**
	 * The REST search (wp/v2/search), when it looks at products.
	 *
	 * @param mixed $args WP_Query arguments.
	 * @return mixed
	 */
	public static function search_args( $args ) {
		$types = is_array( $args ) && isset( $args['post_type'] ) ? (array) $args['post_type'] : array();
		if ( ! array_intersect( array( 'product', 'any' ), $types ) ) {
			return $args;
		}
		return self::rest_args( $args );
	}

	/**
	 * The Store API's product list (wc/store/…/products), which offers no
	 * filter of its own: its query is caught here. Every other query of
	 * every other request returns at the first line.
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public static function store_api_query( $query ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! is_object( $query ) ) {
			return;
		}

		$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( 0 !== strpos( $route, '/wc/store/' ) || ! in_array( 'product', (array) $query->get( 'post_type' ), true ) || self::untouched() ) {
			return;
		}

		$args = self::without_invoices( array( 'tax_query' => $query->get( 'tax_query' ) ), self::slugs() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$query->set( 'tax_query', $args['tax_query'] );
	}

	/**
	 * An invoice's own page: noindex. Decided from what WordPress loaded
	 * for the page anyway — the product's meta and terms — so it costs no
	 * query; a page that is not a single product returns at once.
	 *
	 * @param mixed $robots The robots directives.
	 * @return mixed
	 */
	public static function robots( $robots ) {
		if ( ! is_array( $robots ) || ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return $robots;
		}

		$id = (int) get_queried_object_id();
		if ( $id <= 0 || ! self::is_invoice_page( $id ) ) {
			return $robots;
		}

		$robots['noindex'] = true;
		unset( $robots['max-image-preview'] );
		return $robots;
	}

	/**
	 * Whether a product page is an invoice's: it carries this section's
	 * mark, or sits in the `invoices` category.
	 *
	 * @param int $post_id Product id.
	 * @return bool
	 */
	public static function is_invoice_page( $post_id ) {
		if ( (int) get_post_meta( (int) $post_id, Invoices::PRODUCT_MARKER_META, true ) > 0 ) {
			return true;
		}

		$terms = get_the_terms( (int) $post_id, self::TAXONOMY );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( is_object( $term ) && isset( $term->slug ) && Grammar::CATEGORY_SLUG === (string) $term->slug ) {
				return true;
			}
		}
		return false;
	}
}
