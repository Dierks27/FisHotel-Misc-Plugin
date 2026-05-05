<?php
/**
 * Custom post status registration and customer-surface query exclusions.
 *
 * @package FisHotel\Misc\Sections\Product_Archive
 */

namespace FisHotel\Misc\Sections\Product_Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Status
 */
class Post_Status {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_status' ) );

		// Frontend / customer-facing query exclusions.
		// Most non-public statuses are auto-excluded by WP_Query, but we are
		// explicit here so a future change to status flags or a third-party
		// query that overrides defaults cannot accidentally leak archived
		// products into the shop.
		//
		// We deliberately do NOT touch admin queries — staff need to find
		// archived products when looking up old orders or building manual
		// orders. Sitemaps (Yoast, RankMath, WP Core) respect post_status =
		// publish, so archived products auto-exclude from XML sitemaps with
		// no extra hook needed.
		add_action( 'woocommerce_product_query', array( $this, 'exclude_from_product_query' ) );
		add_action( 'pre_get_posts', array( $this, 'exclude_from_pre_get_posts' ) );
		add_filter( 'rest_product_query', array( $this, 'exclude_from_rest' ), 10, 2 );
		add_filter( 'woocommerce_related_products_args', array( $this, 'exclude_from_related' ) );

		if ( is_admin() ) {
			add_action( 'admin_footer-post.php', array( $this, 'inject_status_dropdown_option' ) );
			add_action( 'admin_footer-post-new.php', array( $this, 'inject_status_dropdown_option' ) );
		}
	}

	/**
	 * Register the custom post status.
	 *
	 * Hooked to init so it is available for both admin filters and
	 * any front-end query that may run during the request.
	 */
	public function register_status() {
		register_post_status( Product_Archive::STATUS, array(
			'label'                     => _x( 'Archived', 'product status', 'fishotel-misc-plugin' ),
			'public'                    => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'fishotel-misc-plugin'
			),
		) );
	}

	/**
	 * Force WooCommerce shop / archive / taxonomy queries to publish only.
	 *
	 * @param \WP_Query $query The product query.
	 */
	public function exclude_from_product_query( $query ) {
		if ( is_admin() ) {
			return;
		}
		$query->set( 'post_status', array( 'publish' ) );
	}

	/**
	 * Force any front-end main query that touches the product CPT to
	 * publish only. Covers the shop, product archives, taxonomy archives,
	 * and front-end search.
	 *
	 * @param \WP_Query $query The query being prepared.
	 */
	public function exclude_from_pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		$touches_product = false;
		if ( 'product' === $post_type ) {
			$touches_product = true;
		} elseif ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
			$touches_product = true;
		} elseif ( $query->is_post_type_archive( 'product' ) ) {
			$touches_product = true;
		} else {
			$product_taxes = get_object_taxonomies( 'product' );
			if ( ! empty( $product_taxes ) && $query->is_tax( $product_taxes ) ) {
				$touches_product = true;
			}
		}

		// Front-end search may include products even when post_type is unset.
		if ( $query->is_search() ) {
			$touches_product = true;
		}

		if ( ! $touches_product ) {
			return;
		}

		$query->set( 'post_status', array( 'publish' ) );
	}

	/**
	 * Strip archived products from WooCommerce REST API responses.
	 *
	 * Applies to /wp-json/wc/v3/products and the product Store API.
	 * Admin REST consumers requesting `status=fishotel_archived`
	 * explicitly will still receive results because the request is
	 * authenticated against capability checks at the REST controller
	 * level — we only remove our status from the post_status arg, not
	 * the entire query.
	 *
	 * @param array            $args    Prepared query args.
	 * @param \WP_REST_Request $request The REST request.
	 * @return array
	 */
	public function exclude_from_rest( $args, $request ) {
		unset( $request );

		if ( empty( $args['post_status'] ) || 'any' === $args['post_status'] ) {
			$args['post_status'] = array( 'publish' );
			return $args;
		}

		if ( is_array( $args['post_status'] ) ) {
			$args['post_status'] = array_values( array_diff( $args['post_status'], array( Product_Archive::STATUS ) ) );
			if ( empty( $args['post_status'] ) ) {
				$args['post_status'] = array( 'publish' );
			}
			return $args;
		}

		if ( Product_Archive::STATUS === $args['post_status'] ) {
			$args['post_status'] = array( 'publish' );
		}

		return $args;
	}

	/**
	 * Force the related-products query to publish only.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function exclude_from_related( $args ) {
		$args['post_status'] = array( 'publish' );
		return $args;
	}

	/**
	 * Inject the Archived option into the post status dropdown on the
	 * product edit screen so admins can archive / unarchive from the
	 * publish meta box. Also rewrites the displayed status label when
	 * the post is currently archived (WP would otherwise fall back to
	 * "Draft" since Archived is not a built-in status).
	 *
	 * Targets the classic editor only. Bulk + row actions remain the
	 * primary archive surface and work regardless of editor.
	 */
	public function inject_status_dropdown_option() {
		global $post;

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		$is_archived = ( Product_Archive::STATUS === $post->post_status );
		?>
		<script>
		jQuery(function ($) {
			var label      = <?php echo wp_json_encode( __( 'Archived', 'fishotel-misc-plugin' ) ); ?>;
			var value      = <?php echo wp_json_encode( Product_Archive::STATUS ); ?>;
			var isArchived = <?php echo $is_archived ? 'true' : 'false'; ?>;
			var $sel       = $('select#post_status');

			if (!$sel.length) {
				return;
			}

			if (!$sel.find('option[value="' + value + '"]').length) {
				$('<option/>', { value: value, text: label }).appendTo($sel);
			}

			if (isArchived) {
				$sel.val(value);
				$('#post-status-display').text(label);
				$('input#hidden_post_status').val(value);
			}
		});
		</script>
		<?php
	}
}
