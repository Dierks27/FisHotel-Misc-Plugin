<?php
/**
 * Admin UI for the Product Archive section — bulk actions, row actions,
 * and the FisHotel Tools dashboard sub-page.
 *
 * @package FisHotel\Misc\Sections\Product_Archive
 */

namespace FisHotel\Misc\Sections\Product_Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Register hooks.
	 */
	public function init() {
		// Bulk actions on the products list table.
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) );

		// Single-product row actions.
		add_filter( 'post_row_actions', array( $this, 'register_row_actions' ), 10, 2 );
		add_action( 'admin_action_fishotel_archive_product', array( $this, 'handle_row_action' ) );
		add_action( 'admin_action_fishotel_unarchive_product', array( $this, 'handle_row_action' ) );

		// Dashboard CSS.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
	}

	/**
	 * Add Archive / Unarchive entries to the bulk actions dropdown.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		if ( ! current_user_can( Product_Archive::CAPABILITY ) ) {
			return $actions;
		}

		$actions['fishotel_archive']   = __( 'Archive', 'fishotel-misc-plugin' );
		$actions['fishotel_unarchive'] = __( 'Unarchive', 'fishotel-misc-plugin' );

		return $actions;
	}

	/**
	 * Handle the bulk archive / unarchive actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action key.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'fishotel_archive', 'fishotel_unarchive' ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( Product_Archive::CAPABILITY ) ) {
			return $redirect_to;
		}

		$is_archive = ( 'fishotel_archive' === $action );
		$new_status = $is_archive ? Product_Archive::STATUS : 'publish';
		$count      = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $new_status,
				),
				true
			);

			if ( ! is_wp_error( $result ) ) {
				$this->clear_product_cache( $post_id );
				$count++;
			}
		}

		$key = $is_archive ? 'fishotel_archived_count' : 'fishotel_unarchived_count';

		return add_query_arg( $key, $count, $redirect_to );
	}

	/**
	 * Render an admin notice after a bulk archive / unarchive operation.
	 */
	public function bulk_action_notices() {
		if ( ! empty( $_GET['fishotel_archived_count'] ) ) {
			$count = absint( $_GET['fishotel_archived_count'] );
			printf(
				'<div class="updated notice is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of products archived */
						_n( '%d product archived.', '%d products archived.', $count, 'fishotel-misc-plugin' ),
						$count
					)
				)
			);
		}

		if ( ! empty( $_GET['fishotel_unarchived_count'] ) ) {
			$count = absint( $_GET['fishotel_unarchived_count'] );
			printf(
				'<div class="updated notice is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of products unarchived */
						_n( '%d product unarchived.', '%d products unarchived.', $count, 'fishotel-misc-plugin' ),
						$count
					)
				)
			);
		}
	}

	/**
	 * Add an Archive / Unarchive row action to product rows.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    The post.
	 * @return array
	 */
	public function register_row_actions( $actions, $post ) {
		if ( ! $post || 'product' !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( Product_Archive::CAPABILITY ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$is_archived = ( Product_Archive::STATUS === $post->post_status );
		$action_key  = $is_archived ? 'fishotel_unarchive_product' : 'fishotel_archive_product';
		$label       = $is_archived ? __( 'Unarchive', 'fishotel-misc-plugin' ) : __( 'Archive', 'fishotel-misc-plugin' );

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=' . $action_key . '&post=' . $post->ID ),
			Product_Archive::ROW_NONCE . '_' . $post->ID
		);

		$key = $is_archived ? 'fishotel_unarchive' : 'fishotel_archive';

		$actions[ $key ] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);

		return $actions;
	}

	/**
	 * Handle a single-product Archive / Unarchive row action.
	 */
	public function handle_row_action() {
		$action       = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$is_archive   = ( 'fishotel_archive_product' === $action );
		$is_unarchive = ( 'fishotel_unarchive_product' === $action );

		if ( ! $is_archive && ! $is_unarchive ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid product.', 'fishotel-misc-plugin' ) );
		}

		check_admin_referer( Product_Archive::ROW_NONCE . '_' . $post_id );

		if ( ! current_user_can( Product_Archive::CAPABILITY ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fishotel-misc-plugin' ) );
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Not a product.', 'fishotel-misc-plugin' ) );
		}

		$new_status = $is_archive ? Product_Archive::STATUS : 'publish';

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $new_status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$this->clear_product_cache( $post_id );

		$key      = $is_archive ? 'fishotel_archived_count' : 'fishotel_unarchived_count';
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=product' );
		}

		wp_safe_redirect( add_query_arg( $key, 1, $redirect ) );
		exit;
	}

	/**
	 * Clear cached product data after a status change.
	 *
	 * @param int $post_id Product ID.
	 */
	private function clear_product_cache( $post_id ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
	}

	/**
	 * Enqueue dashboard CSS only on the Product Archive sub-page.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_dashboard_assets( $hook_suffix ) {
		unset( $hook_suffix );

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, Product_Archive::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-product-archive-admin',
			Product_Archive::url() . 'css/admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);
	}

	/**
	 * Render the FisHotel Tools → Product Archive dashboard page.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( Product_Archive::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$counts          = wp_count_posts( 'product' );
		$archived_count  = isset( $counts->{Product_Archive::STATUS} ) ? (int) $counts->{Product_Archive::STATUS} : 0;
		$published_count = isset( $counts->publish ) ? (int) $counts->publish : 0;

		$archive_url      = admin_url( 'edit.php?post_status=' . Product_Archive::STATUS . '&post_type=product' );
		$all_products_url = admin_url( 'edit.php?post_type=product' );

		include Product_Archive::path() . 'views/dashboard.php';
	}
}
