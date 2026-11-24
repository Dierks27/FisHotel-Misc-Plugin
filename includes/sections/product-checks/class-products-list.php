<?php
/**
 * Product checks — the products list (wp-admin → Products).
 *
 *   - Under WooCommerce's own links (All | Published | …), a row
 *     "To fix:" with a count per problem; a link with 0 is not shown —
 *     and "Recount now".
 *   - A "To fix" dropdown beside WooCommerce's filters: the same, plus no
 *     gallery photo, scientific name missing or different, filed only
 *     under a sub-category, 3rd bag ticked.
 *   - A "To fix" column after Name: small chips, a ✓ when clean, "×3" when
 *     the 3rd bag is ticked; sortable by how many problems.
 *   - Row and bulk actions: "Copy facts from the description" (the theme's
 *     own copy, never overwriting a filled field — only when the theme
 *     has it) and "Recount".
 * A filter is a plain `EXISTS` on one meta key; the column reads meta
 * WordPress already loaded for the page.
 *
 * @package FisHotel\Misc\Sections\Product_Checks
 */

namespace FisHotel\Misc\Sections\Product_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Class Products_List
 */
class Products_List {

	/**
	 * The query argument of a filter.
	 *
	 * @var string
	 */
	const VAR = 'fh_todo';

	/**
	 * The admin-post action of the links and its nonce.
	 *
	 * @var string
	 */
	const ACTION = 'fishotel_product_checks';

	/**
	 * The store.
	 *
	 * @var Store
	 */
	private $store;

	/**
	 * The checker.
	 *
	 * @var Checker
	 */
	private $checker;

	/**
	 * @param Store|null   $store   The store.
	 * @param Checker|null $checker The checker.
	 */
	public function __construct( $store = null, $checker = null ) {
		$this->store   = is_object( $store ) ? $store : new Store();
		$this->checker = is_object( $checker ) ? $checker : new Checker( $this->store );
	}

	/**
	 * The "To fix" row's links, in order: key → words.
	 *
	 * @return array<string, string>
	 */
	public static function views() {
		return array(
			'photo'     => __( 'Live fish without a real photo', 'fishotel-misc-plugin' ),
			'table'     => __( 'Live fish missing table info', 'fishotel-misc-plugin' ),
			'oldstyle'  => __( 'Old-style description', 'fishotel-misc-plugin' ),
			'bag'       => __( 'Live fish without a bag size', 'fishotel-misc-plugin' ),
			'price'     => __( 'In stock with no price', 'fishotel-misc-plugin' ),
			'cleanroom' => __( 'Clean Room drafts', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The dropdown: the row's, then four more.
	 *
	 * @return array<string, string>
	 */
	public static function filters() {
		return self::views() + array(
			'gallery' => __( 'Live fish with no gallery photo', 'fishotel-misc-plugin' ),
			'sci'     => __( 'Scientific name missing or different', 'fishotel-misc-plugin' ),
			'subcat'  => __( 'Filed only under a sub-category', 'fishotel-misc-plugin' ),
			'third'   => __( '3rd bag ticked', 'fishotel-misc-plugin' ),
		);
	}

	/**
	 * The query vars of a filter (pure): what the list adds to its query.
	 *
	 * @param string $key  A key of filters().
	 * @param array  $vars The list's query vars.
	 * @return array
	 */
	public static function query_for( $key, array $vars ) {
		if ( ! isset( self::filters()[ $key ] ) ) {
			return $vars;
		}
		$query = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();
		if ( 'cleanroom' === $key ) {
			$query[]             = array(
				'key'     => '_fh_cleanroom_fish',
				'compare' => 'EXISTS',
			);
			$vars['post_status'] = 'draft';
		} elseif ( 'third' === $key ) {
			$query[] = array(
				'key'   => '_fh_bag_layers',
				'value' => '3',
			);
		} else {
			$query[] = array(
				'key'     => Rules::PREFIX . $key,
				'compare' => 'EXISTS',
			);
		}
		$vars['meta_query'] = $query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $vars;
	}

	/**
	 * The query vars when the list is sorted by "To fix" (pure): every
	 * product kept, the ones with most problems first (or last).
	 *
	 * @param array $vars The list's query vars.
	 * @return array
	 */
	public static function sort_for( array $vars ) {
		$query                = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array();
		$query['fh_todo_sum'] = array(
			'relation' => 'OR',
			'fh_todo_n' => array(
				'key'     => Rules::META_SUM,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => Rules::META_SUM,
				'compare' => 'NOT EXISTS',
			),
		);
		$vars['meta_query'] = $query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$vars['orderby']    = 'fh_todo_n';
		return $vars;
	}

	/**
	 * Register the hooks (wp-admin only).
	 */
	public function init() {
		add_filter( 'views_edit-product', array( $this, 'add_views' ), 20 );
		add_filter( 'woocommerce_products_admin_list_table_filters', array( $this, 'add_filter' ) );
		add_filter( 'request', array( $this, 'filter_request' ) );
		add_filter( 'manage_product_posts_columns', array( $this, 'add_column' ), 20 );
		add_filter( 'manage_edit-product_sortable_columns', array( $this, 'sortable' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_link' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Whether the theme's "copy facts" is there.
	 *
	 * @return bool
	 */
	public static function can_copy() {
		return function_exists( 'fishotel_copy_facts_from_description' );
	}

	/**
	 * The URL of the list with a filter.
	 *
	 * @param string $key A key of filters().
	 * @return string
	 */
	public static function url( $key ) {
		return add_query_arg(
			array(
				'post_type' => 'product',
				self::VAR   => $key,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The link of an op ("recount" of the whole list, or of one product;
	 * "copy" for one product).
	 *
	 * @param string $op recount | copy.
	 * @param int    $id Product ID (0 = all).
	 * @return string
	 */
	public static function link( $op, $id = 0 ) {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'op'       => $op,
				'id'       => (int) $id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '|' . $op . '|' . (int) $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The current filter ('' = none).
	 *
	 * @return string
	 */
	private static function current() {
		$key = isset( $_GET[ self::VAR ] ) && is_scalar( $_GET[ self::VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, nothing is written.
		return isset( self::filters()[ $key ] ) ? $key : '';
	}

	/**
	 * The "To fix" row under WooCommerce's own links.
	 *
	 * @param mixed $views WordPress's views.
	 * @return array
	 */
	public function add_views( $views ) {
		$views = is_array( $views ) ? $views : array();
		if ( ! current_user_can( 'edit_products' ) ) {
			return $views;
		}
		$counts = $this->store->counts();
		$now    = self::current();
		$links  = array();
		foreach ( self::views() as $key => $label ) {
			$n = (int) ( $counts[ $key ] ?? 0 );
			if ( $n < 1 ) {
				continue;
			}
			$links[] = '<a href="' . esc_url( self::url( $key ) ) . '"' . ( $now === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . $n . ')</span></a>';
		}
		$row  = '<span class="fh-pc-views"><strong>' . esc_html__( 'To fix:', 'fishotel-misc-plugin' ) . '</strong> ';
		$row .= $links ? implode( ' | ', $links ) : esc_html__( 'nothing — every product checked is complete', 'fishotel-misc-plugin' );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$row .= ' <a class="fh-pc-recount" href="' . esc_url( self::link( 'recount' ) ) . '">' . esc_html__( 'Recount now', 'fishotel-misc-plugin' ) . '</a>';
		}
		$views['fh_todo'] = $row . '</span>';
		return $views;
	}

	/**
	 * The dropdown, after WooCommerce's filters.
	 *
	 * @param mixed $filters WooCommerce's filter callbacks.
	 * @return array
	 */
	public function add_filter( $filters ) {
		$filters            = is_array( $filters ) ? $filters : array();
		$filters['fh_todo'] = array( $this, 'render_filter' );
		return $filters;
	}

	/**
	 * Draw the dropdown.
	 */
	public function render_filter() {
		$now = self::current();
		echo '<select name="' . esc_attr( self::VAR ) . '" id="fh-pc-filter" class="fh-pc-filter">';
		echo '<option value="">' . esc_html__( 'To fix', 'fishotel-misc-plugin' ) . '</option>';
		foreach ( self::filters() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( $now === $key ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * The list's query: the filter, the sort.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		global $pagenow;
		if ( ! is_array( $vars ) || ! is_admin() || 'edit.php' !== $pagenow || ( $vars['post_type'] ?? '' ) !== 'product' ) {
			return $vars;
		}
		$key = self::current();
		if ( '' !== $key ) {
			$vars = self::query_for( $key, $vars );
		}
		if ( isset( $vars['orderby'] ) && 'fh_todo' === $vars['orderby'] ) {
			$vars = self::sort_for( $vars );
		}
		return $vars;
	}

	/**
	 * The "To fix" column, after Name.
	 *
	 * @param mixed $columns The list's columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		$out     = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['fh_todo'] = __( 'To fix', 'fishotel-misc-plugin' );
			}
		}
		if ( ! isset( $out['fh_todo'] ) ) {
			$out['fh_todo'] = __( 'To fix', 'fishotel-misc-plugin' );
		}
		return $out;
	}

	/**
	 * The column can be sorted.
	 *
	 * @param mixed $columns Sortable columns.
	 * @return array
	 */
	public function sortable( $columns ) {
		$columns            = is_array( $columns ) ? $columns : array();
		$columns['fh_todo'] = array( 'fh_todo', true );
		return $columns;
	}

	/**
	 * The flags stored on a product.
	 *
	 * @param int $id Product ID.
	 * @return array flag → value.
	 */
	public static function stored( $id ) {
		$out = array();
		foreach ( Rules::FLAGS as $flag ) {
			$value = get_post_meta( (int) $id, Rules::PREFIX . $flag, true );
			if ( Rules::filled( $value ) ) {
				$out[ $flag ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * A cell of the column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( 'fh_todo' !== $column ) {
			return;
		}
		$chips = Rules::chips( self::stored( (int) $post_id ) );
		$html  = '';
		foreach ( $chips as $chip ) {
			$html .= '<span class="fh-pc-chip fh-pc-chip--' . esc_attr( $chip['flag'] ) . '">' . esc_html( $chip['label'] ) . '</span> ';
		}
		if ( '' === $html ) {
			$html = '<span class="fh-pc-ok" title="' . esc_attr__( 'Nothing to fix', 'fishotel-misc-plugin' ) . '">✓</span> ';
		}
		if ( '3' === (string) get_post_meta( (int) $post_id, '_fh_bag_layers', true ) ) {
			$html .= '<span class="fh-pc-third" title="' . esc_attr__( 'Needs a 3rd bag', 'fishotel-misc-plugin' ) . '">×3</span>';
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	/**
	 * Row actions: Copy facts (an old-style fish, the theme's copy there),
	 * Recount.
	 *
	 * @param mixed  $actions The row's actions.
	 * @param object $post    The post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! is_object( $post ) || 'product' !== ( $post->post_type ?? '' ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $actions;
		}
		if ( self::can_copy() && Rules::filled( get_post_meta( (int) $post->ID, Rules::PREFIX . 'oldstyle', true ) ) ) {
			$actions['fh_copy_facts'] = '<a href="' . esc_url( self::link( 'copy', (int) $post->ID ) ) . '">' . esc_html__( 'Copy facts from the description', 'fishotel-misc-plugin' ) . '</a>';
		}
		$actions['fh_recount'] = '<a href="' . esc_url( self::link( 'recount', (int) $post->ID ) ) . '">' . esc_html__( 'Recount', 'fishotel-misc-plugin' ) . '</a>';
		return $actions;
	}

	/**
	 * Bulk actions.
	 *
	 * @param mixed $actions The list's bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( self::can_copy() ) {
			$actions['fh_copy_facts'] = __( 'Copy facts from the description', 'fishotel-misc-plugin' );
		}
		$actions['fh_recount'] = __( 'Recount "To fix"', 'fishotel-misc-plugin' );
		return $actions;
	}

	/**
	 * Copy the facts of one product (the theme's own copy: empty fields
	 * only), then check it again.
	 *
	 * @param int $id Product ID.
	 * @return int How many fields were filled.
	 */
	public function copy( $id ) {
		if ( ! self::can_copy() || ! current_user_can( 'edit_post', (int) $id ) ) {
			return 0;
		}
		try {
			$written = fishotel_copy_facts_from_description( (int) $id );
		} catch ( \Throwable $e ) {
			$written = array();
		}
		$this->checker->check( (int) $id );
		return is_array( $written ) ? count( $written ) : 0;
	}

	/**
	 * Bulk: copy or recount the ticked products (WordPress checked its own
	 * nonce for the list).
	 *
	 * @param mixed  $redirect The URL to go back to.
	 * @param string $action   The bulk action.
	 * @param array  $ids      Product IDs.
	 * @return mixed
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'fh_copy_facts', 'fh_recount' ), true ) || ! is_string( $redirect ) ) {
			return $redirect;
		}
		$done = 0;
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, 500 ) as $id ) {
			if ( 'fh_copy_facts' === $action ) {
				$done += $this->copy( $id ) > 0 ? 1 : 0;
			} elseif ( current_user_can( 'edit_post', $id ) && null !== $this->checker->check( $id ) ) {
				++$done;
			}
		}
		return add_query_arg(
			array(
				'fh_pc_done' => 'fh_copy_facts' === $action ? 'copy' : 'recount',
				'fh_pc_n'    => $done,
			),
			remove_query_arg( array( 'fh_pc_done', 'fh_pc_n' ), $redirect )
		);
	}

	/**
	 * What a link does (no output).
	 *
	 * @param array $raw Unslashed GET: op, id, _wpnonce.
	 * @return array{op: string, n: int}|null Null when refused.
	 */
	public function run( array $raw ) {
		$op    = isset( $raw['op'] ) && is_scalar( $raw['op'] ) ? (string) $raw['op'] : '';
		$id    = isset( $raw['id'] ) && is_scalar( $raw['id'] ) ? (int) $raw['id'] : 0;
		$nonce = isset( $raw['_wpnonce'] ) && is_scalar( $raw['_wpnonce'] ) ? (string) $raw['_wpnonce'] : '';
		if ( ! in_array( $op, array( 'recount', 'copy' ), true ) || ! wp_verify_nonce( $nonce, self::ACTION . '|' . $op . '|' . $id ) ) {
			return null;
		}
		if ( 'copy' === $op ) {
			if ( $id < 1 || ! self::can_copy() || ! current_user_can( 'edit_post', $id ) ) {
				return null;
			}
			return array(
				'op' => 'copy',
				'n'  => $this->copy( $id ),
			);
		}
		if ( $id > 0 ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return null;
			}
			$this->checker->check( $id );
			return array(
				'op' => 'recount',
				'n'  => 1,
			);
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		// The whole list: the first products now, the rest on WP-cron.
		$this->checker->start();
		return array(
			'op' => 'sweep',
			'n'  => $this->checker->run(),
		);
	}

	/**
	 * admin-post: run a link, come back to the list.
	 */
	public function handle_link() {
		$raw  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in run().
		$done = $this->run( is_array( $raw ) ? $raw : array() );
		if ( null === $done ) {
			wp_die( esc_html__( 'This link has expired. Go back, reload the page and try again.', 'fishotel-misc-plugin' ), '', array( 'response' => 403 ) );
		}
		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'fh_pc_done', 'fh_pc_n' ), $back ) : admin_url( 'edit.php?post_type=product' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'fh_pc_done' => $done['op'],
					'fh_pc_n'    => $done['n'],
				),
				$back
			)
		);
		exit;
	}

	/**
	 * The line after a link or a bulk action.
	 */
	public function notice() {
		$done = isset( $_GET['fh_pc_done'] ) && is_scalar( $_GET['fh_pc_done'] ) ? sanitize_key( wp_unslash( $_GET['fh_pc_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which line to show, nothing is written.
		$n    = isset( $_GET['fh_pc_n'] ) && is_scalar( $_GET['fh_pc_n'] ) ? (int) $_GET['fh_pc_n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		if ( 'copy' === $done ) {
			/* translators: %d: number */
			$text = sprintf( _n( 'Copied the facts from the description: %d done (only empty fields are filled).', 'Copied the facts from the description: %d done (only empty fields are filled).', $n, 'fishotel-misc-plugin' ), $n );
		} elseif ( 'recount' === $done ) {
			/* translators: %d: number of products */
			$text = sprintf( _n( '"To fix" checked again for %d product.', '"To fix" checked again for %d products.', $n, 'fishotel-misc-plugin' ), $n );
		} elseif ( 'sweep' === $done ) {
			/* translators: %d: number of products */
			$text = sprintf( __( 'Recounting every product: %d checked now, the rest within a few minutes. Reload the list to see the new counts.', 'fishotel-misc-plugin' ), $n );
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
