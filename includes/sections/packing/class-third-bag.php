<?php
/**
 * "Needs a 3rd bag" on the products list, and the one-time helper that
 * ticks it on every product the old word list matches.
 *
 * The products list gets a view "3rd bag ticked (n)" beside WooCommerce's
 * own (All, Published, …): a plain list of the products with the tick, so
 * the owner can check them. (The tick itself is on quick edit and bulk
 * edit — Product.)
 *
 * The helper (FisHotel Tools → Packing → Bags) lists the products that
 * the "Triple-bag these" words find — the same whole-word match on the
 * name and the category names as the order's Packing box — that are
 * packed in a bag and have no tick yet (nor a "Double" chosen earlier).
 * Every row starts ticked; the owner unticks any fish they disagree with
 * and one click ticks the rest. That click also sets "The word list
 * applies to" to "Lines with no product page only" (when it still says
 * every line): from then on every fish with a product follows its tick,
 * and the words reach only a fish with no product page. The list stays on
 * the page as a review — "these have a word but no tick" — never as a
 * rule.
 *
 * matches() is the pure rule; the request handler checks the capability
 * and the nonce itself and writes through the product object.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Third_Bag
 */
class Third_Bag {

	/**
	 * The products list's query argument of the view.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'fh_third_bag';

	/**
	 * The most products one click ticks.
	 *
	 * @var int
	 */
	const MAX_TICK = 2000;

	/**
	 * The store (the tests hand in a stand-in).
	 *
	 * @var object|null
	 */
	private $store;

	/**
	 * @param object|null $store Store, or a stand-in (null = a new Store when needed).
	 */
	public function __construct( $store = null ) {
		$this->store = is_object( $store ) ? $store : null;
	}

	/**
	 * Register the hooks (wp-admin only: the section loads this file there).
	 */
	public function init() {
		add_filter( 'views_edit-product', array( $this, 'add_view' ) );
		add_filter( 'request', array( $this, 'filter_request' ) );
		add_action( 'wp_ajax_fishotel_packing_tick_third', array( $this, 'ajax_tick' ) );
	}

	/**
	 * The store, made when first needed.
	 *
	 * @return object
	 */
	private function store() {
		if ( null === $this->store ) {
			$this->store = new Store();
		}
		return $this->store;
	}

	/* ============================================================== */
	/*  The rule                                                       */
	/* ============================================================== */

	/**
	 * The products the old word list matches and that have no choice yet
	 * (pure): packed in a bag ("Livestock" ticked, or with no stored choice
	 * in one of the fish categories), not an invoice, `_fh_bag_layers`
	 * empty, and a word found whole in the name or a category name.
	 *
	 * @param array[]  $rows       Store::third_bag_candidates().
	 * @param string[] $words      The "Triple-bag these" list.
	 * @param string[] $fish_slugs The fish categories.
	 * @return array[] Each row with `word` added, in the rows' order.
	 */
	public static function matches( array $rows, array $words, array $fish_slugs ) {
		$out = array();
		if ( ! $words ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$cats  = isset( $row['categories'] ) && is_array( $row['categories'] ) ? $row['categories'] : array();
			$slugs = array_map( 'strval', array_values( $cats ) );
			$packs = Livestock::flag( $row['packs'] ?? '' );

			if ( in_array( 'invoices', $slugs, true ) || Livestock::NO === $packs ) {
				continue;
			}
			if ( Livestock::YES !== $packs && ! array_intersect( $slugs, $fish_slugs ) ) {
				continue; // Not packed in a bag: what a word says of it never mattered.
			}
			if ( Layers::choice( $row['layers'] ?? '' ) > 0 ) {
				continue; // Ticked already, or set to Double by hand.
			}

			$texts = array_merge( array( (string) ( $row['name'] ?? '' ) ), array_map( 'strval', array_keys( $cats ) ) );
			$word  = Layers::find_word( $words, $texts );
			if ( '' === $word ) {
				continue;
			}
			$row['word'] = $word;
			$out[]       = $row;
		}
		return $out;
	}

	/**
	 * The ids a click asks to tick (pure): a comma-separated list or a
	 * list; whole numbers above 0, each once, at most MAX_TICK.
	 *
	 * @param mixed $raw Posted ids.
	 * @return int[]
	 */
	public static function ids_in( $raw ) {
		if ( is_scalar( $raw ) && ! is_bool( $raw ) ) {
			$raw = explode( ',', (string) $raw );
		}
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			$id = is_scalar( $id ) && 1 === preg_match( '/^\s*\d{1,12}\s*$/', (string) $id ) ? (int) $id : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_TICK ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The helper's list as the Bags tab shows it.
	 *
	 * @return array[]
	 */
	public function review_list() {
		$settings = Settings::get();
		$store    = $this->store();
		$rows     = method_exists( $store, 'third_bag_candidates' ) ? (array) $store->third_bag_candidates() : array();
		return self::matches( $rows, $settings['triple_words'], Lines::fish_categories() );
	}

	/* ============================================================== */
	/*  The click                                                      */
	/* ============================================================== */

	/**
	 * Tick "Needs a 3rd bag" on the products asked — each only when it is
	 * a product (not a variation) with no choice yet — and let the word
	 * list reach only the lines with no product page from now on.
	 *
	 * @param array $raw Unslashed POST: _wpnonce, ids (comma-separated).
	 * @return array{status: int, ok: bool, message: string, ticked?: int}
	 */
	public static function tick_request( array $raw ) {
		$denied = Settings::guard( $raw );
		if ( $denied ) {
			return $denied;
		}

		$ids    = self::ids_in( $raw['ids'] ?? '' );
		$ticked = 0;
		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) || ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) ) {
				continue;
			}
			if ( Layers::choice( $product->get_meta( Packing::META_LAYERS, true ) ) > 0 ) {
				continue; // Ticked (or set to Double) since the page was drawn.
			}
			$product->update_meta_data( Packing::META_LAYERS, (string) Layers::TRIPLE );
			if ( method_exists( $product, 'save_meta_data' ) ) {
				$product->save_meta_data();
			}
			++$ticked;
		}

		$settings = Settings::get();
		$moved    = false;
		if ( Layers::SCOPE_ALL === $settings['triple_scope'] ) {
			$settings['triple_scope'] = Layers::SCOPE_NO_PRODUCT;
			Settings::store( $settings );
			$moved = true;
		}

		/* translators: %d: number of products */
		$message = sprintf( _n( 'Ticked "Needs a 3rd bag" on %d product.', 'Ticked "Needs a 3rd bag" on %d products.', $ticked, 'fishotel-misc-plugin' ), $ticked );
		if ( $moved ) {
			$message .= ' ' . __( 'The word list now reaches only the fish with no product page: every fish with a product follows its tick.', 'fishotel-misc-plugin' );
		}

		return array(
			'status'  => 200,
			'ok'      => true,
			'message' => $message,
			'ticked'  => $ticked,
		);
	}

	/**
	 * AJAX: tick_request().
	 */
	public function ajax_tick() {
		$outcome = self::tick_request( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in Settings::guard().
		unset( $outcome['ticked'] );
		Settings::reply( $outcome );
	}

	/* ============================================================== */
	/*  The products list                                              */
	/* ============================================================== */

	/**
	 * The view "3rd bag ticked (n)" — shown when at least one product has
	 * the tick.
	 *
	 * @param mixed $views WordPress's views.
	 * @return array
	 */
	public function add_view( $views ) {
		$views = is_array( $views ) ? $views : array();
		if ( ! current_user_can( Packing::CAPABILITY ) ) {
			return $views;
		}
		$store = $this->store();
		$count = method_exists( $store, 'third_bag_count' ) ? (int) $store->third_bag_count() : 0;
		if ( $count < 1 ) {
			return $views;
		}
		$current = isset( $_GET[ self::QUERY_VAR ] ) && '1' === (string) $_GET[ self::QUERY_VAR ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, nothing is written.
		$url     = add_query_arg(
			array(
				'post_type'     => 'product',
				self::QUERY_VAR => '1',
			),
			admin_url( 'edit.php' )
		);

		$views[ self::QUERY_VAR ] = '<a href="' . esc_url( $url ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>'
			. esc_html__( '3rd bag ticked', 'fishotel-misc-plugin' ) . ' <span class="count">(' . (int) $count . ')</span></a>';
		return $views;
	}

	/**
	 * The products list's query when the view is chosen: only the products
	 * with the tick.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! self::is_products_list( $vars ) ) {
			return $vars;
		}
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || '1' !== (string) $_GET[ self::QUERY_VAR ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, nothing is written.
			return $vars;
		}
		$query                = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();
		$query[]              = array(
			'key'   => Packing::META_LAYERS,
			'value' => '3',
		);
		$vars['meta_query'] = $query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $vars;
	}

	/**
	 * Whether a request is the products list of wp-admin.
	 *
	 * @param array $vars Query vars.
	 * @return bool
	 */
	public static function is_products_list( array $vars ) {
		global $pagenow;
		return is_admin() && 'edit.php' === $pagenow && isset( $vars['post_type'] ) && 'product' === $vars['post_type'];
	}
}
