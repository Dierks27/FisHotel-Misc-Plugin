<?php
/**
 * Lottery Draw admin — create draws, add fish, commit the seed, run the
 * draw in the browser, and copy the result out as BBCode.
 *
 * Every write goes through admin-post.php with a nonce and a capability
 * check, and every write re-reads the stored payload and re-checks the
 * lifecycle status server-side. The UI hides buttons that no longer
 * apply; the handlers assume the UI was bypassed.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Transient prefix used to carry a notice across the post-redirect-get.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'fh_draw_notice_';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_post_fishotel_draw_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_fishotel_draw_update_seed', array( $this, 'handle_update_seed' ) );
		add_action( 'admin_post_fishotel_draw_add_fish', array( $this, 'handle_add_fish' ) );
		add_action( 'admin_post_fishotel_draw_remove_fish', array( $this, 'handle_remove_fish' ) );
		add_action( 'admin_post_fishotel_draw_commit_seed', array( $this, 'handle_commit_seed' ) );
		add_action( 'admin_post_fishotel_draw_publish', array( $this, 'handle_publish' ) );
		add_action( 'admin_post_fishotel_draw_delete', array( $this, 'handle_delete' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the draw engine and admin assets on this section's page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, Lottery_Draw::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-lottery-admin',
			Lottery_Draw::url() . 'css/lottery-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		// The one and only draw implementation, shared verbatim with the
		// public page. The admin screen runs it to produce a result; the
		// public page runs it to check that result.
		wp_enqueue_script(
			'fishotel-draw-core',
			Lottery_Draw::url() . 'js/draw.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_enqueue_script(
			'fishotel-lottery-admin',
			Lottery_Draw::url() . 'js/lottery-admin.js',
			array( 'fishotel-draw-core' ),
			FISHOTEL_MISC_VERSION,
			true
		);

		$draw_id = isset( $_GET['draw'] ) ? sanitize_title( wp_unslash( $_GET['draw'] ) ) : '';
		$raw     = '';

		if ( $draw_id ) {
			$post = Store::find_post( $draw_id );

			if ( $post ) {
				$raw = Store::get_raw( $post->ID );
			}
		}

		wp_add_inline_script(
			'fishotel-lottery-admin',
			'var fishotelDrawAdmin = ' . wp_json_encode(
				array(
					'raw' => $raw,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the section page: the draw list, or one draw's editor.
	 */
	public function render_page() {
		if ( ! current_user_can( Lottery_Draw::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel-misc-plugin' ) );
		}

		$notice = $this->pull_notice();
		$draw_id = isset( $_GET['draw'] ) ? sanitize_title( wp_unslash( $_GET['draw'] ) ) : '';

		if ( $draw_id ) {
			$post = Store::find_post( $draw_id );

			if ( $post ) {
				$payload = Store::get_payload( $post->ID );

				if ( $payload ) {
					include Lottery_Draw::path() . 'views/edit.php';
					return;
				}
			}
		}

		$draws = Store::all();
		include Lottery_Draw::path() . 'views/list.php';
	}

	/**
	 * Create a draw.
	 */
	public function handle_create() {
		$this->guard( 'fishotel_draw_create' );

		$method = ( isset( $_POST['seed_method'] ) && Lottery_Draw::SEED_EXTERNAL === $_POST['seed_method'] )
			? Lottery_Draw::SEED_EXTERNAL
			: Lottery_Draw::SEED_COMMIT_EARLY;

		$result = Store::create(
			sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			sanitize_title( wp_unslash( $_POST['draw_id'] ?? '' ) ),
			$method,
			sanitize_text_field( wp_unslash( $_POST['seed'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['seed_source'] ?? '' ) ),
			sanitize_title( wp_unslash( $_POST['supersedes'] ?? '' ) )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result, '' );
		}

		$payload = Store::get_payload( $result );

		$this->set_notice( 'success', __( 'Draw created. Add your fish, then commit the seed.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Update the seed, seed method, or external source on a draft draw.
	 */
	public function handle_update_seed() {
		$this->guard( 'fishotel_draw_update_seed' );

		list( $post, $payload ) = $this->load_draw();

		if ( ! Store::is_editable( $payload ) ) {
			$this->redirect_with_error(
				new \WP_Error( 'fh_draw_locked', __( 'The seed is committed and can no longer be changed.', 'fishotel-misc-plugin' ) ),
				$payload['id']
			);
		}

		$payload['seed']        = sanitize_text_field( wp_unslash( $_POST['seed'] ?? '' ) );
		$payload['seed_source'] = sanitize_text_field( wp_unslash( $_POST['seed_source'] ?? '' ) );
		$payload['seed_method'] = ( isset( $_POST['seed_method'] ) && Lottery_Draw::SEED_EXTERNAL === $_POST['seed_method'] )
			? Lottery_Draw::SEED_EXTERNAL
			: Lottery_Draw::SEED_COMMIT_EARLY;

		Store::save_payload( $post->ID, $payload );

		$this->set_notice( 'success', __( 'Seed saved. It is not committed until you press Commit Seed.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Add a fish to a draft draw.
	 */
	public function handle_add_fish() {
		$this->guard( 'fishotel_draw_add_fish' );

		list( $post, $payload ) = $this->load_draw();

		$updated = Store::add_fish(
			$payload,
			wp_unslash( $_POST['fish_name'] ?? '' ),
			wp_unslash( $_POST['fish_sci'] ?? '' ),
			(int) ( $_POST['fish_stock'] ?? 0 ),
			(int) ( $_POST['fish_group_size'] ?? 1 ),
			wp_unslash( $_POST['fish_entrants'] ?? '' )
		);

		if ( is_wp_error( $updated ) ) {
			$this->redirect_with_error( $updated, $payload['id'] );
		}

		Store::save_payload( $post->ID, $updated );

		$this->set_notice( 'success', __( 'Fish added.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Remove a fish from a draft draw.
	 */
	public function handle_remove_fish() {
		$this->guard( 'fishotel_draw_remove_fish' );

		list( $post, $payload ) = $this->load_draw();

		$updated = Store::remove_fish( $payload, (int) ( $_POST['fish_index'] ?? -1 ) );

		if ( is_wp_error( $updated ) ) {
			$this->redirect_with_error( $updated, $payload['id'] );
		}

		Store::save_payload( $post->ID, $updated );

		$this->set_notice( 'success', __( 'Fish removed.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Commit the seed — lock seed and entrants.
	 */
	public function handle_commit_seed() {
		$this->guard( 'fishotel_draw_commit_seed' );

		list( $post, $payload ) = $this->load_draw();

		$updated = Store::commit_seed( $payload );

		if ( is_wp_error( $updated ) ) {
			$this->redirect_with_error( $updated, $payload['id'] );
		}

		Store::save_payload( $post->ID, $updated );

		$this->set_notice( 'success', __( 'Seed committed. Post it to the thread before entries close, then run the draw.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Store the results the admin's browser computed, and publish.
	 */
	public function handle_publish() {
		$this->guard( 'fishotel_draw_publish' );

		list( $post, $payload ) = $this->load_draw();

		$results = json_decode( wp_unslash( $_POST['results'] ?? '' ), true );

		if ( ! is_array( $results ) ) {
			$this->redirect_with_error(
				new \WP_Error( 'fh_draw_result_shape', __( 'The browser did not send a usable result. Nothing was saved.', 'fishotel-misc-plugin' ) ),
				$payload['id']
			);
		}

		$updated = Store::publish_results( $payload, $results );

		if ( is_wp_error( $updated ) ) {
			$this->redirect_with_error( $updated, $payload['id'] );
		}

		Store::save_payload( $post->ID, $updated );

		$this->set_notice( 'success', __( 'Draw published. It is now immutable — corrections must be published as a new draw.', 'fishotel-misc-plugin' ) );
		$this->redirect( $payload['id'] );
	}

	/**
	 * Delete a draw that was never published.
	 *
	 * A published draw is a record members were asked to trust. Deleting
	 * it is exactly as destructive as editing it, so it is refused here
	 * for the same reason.
	 */
	public function handle_delete() {
		$this->guard( 'fishotel_draw_delete' );

		list( $post, $payload ) = $this->load_draw();

		if ( Store::is_published( $payload ) ) {
			$this->redirect_with_error(
				new \WP_Error( 'fh_draw_immutable', __( 'A published draw cannot be deleted.', 'fishotel-misc-plugin' ) ),
				$payload['id']
			);
		}

		wp_delete_post( $post->ID, true );

		$this->set_notice( 'success', __( 'Draft draw deleted.', 'fishotel-misc-plugin' ) );
		$this->redirect( '' );
	}

	/**
	 * Capability and nonce check shared by every write handler.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( Lottery_Draw::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fishotel-misc-plugin' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Load the draw named by the request, or bail.
	 *
	 * @return array{0: \WP_Post, 1: array}
	 */
	private function load_draw() {
		$draw_id = sanitize_title( wp_unslash( $_POST['draw_id'] ?? '' ) );
		$post    = $draw_id ? Store::find_post( $draw_id ) : null;
		$payload = $post ? Store::get_payload( $post->ID ) : null;

		if ( ! $post || ! $payload ) {
			wp_die( esc_html__( 'That draw does not exist.', 'fishotel-misc-plugin' ), 404 );
		}

		// Belt and braces: no handler may touch a published draw, whatever
		// it thinks it is doing. Publishing itself is gated separately, in
		// Store::publish_results().
		if ( Store::is_published( $payload ) && 'fishotel_draw_publish' !== ( $_POST['action'] ?? '' ) ) {
			$this->redirect_with_error(
				new \WP_Error( 'fh_draw_immutable', __( 'This draw is published and cannot be changed. Publish a correction as a new draw instead.', 'fishotel-misc-plugin' ) ),
				$payload['id']
			);
		}

		return array( $post, $payload );
	}

	/**
	 * Store a notice for the next page load.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message text.
	 */
	private function set_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Read and clear the pending notice.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private function pull_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}

	/**
	 * Record an error and bounce back to the draw.
	 *
	 * @param \WP_Error $error   The error.
	 * @param string    $draw_id Draw to return to ('' for the list).
	 */
	private function redirect_with_error( $error, $draw_id ) {
		$this->set_notice( 'error', $error->get_error_message() );
		$this->redirect( $draw_id );
	}

	/**
	 * Redirect back into the admin UI and stop.
	 *
	 * @param string $draw_id Draw to open ('' for the list).
	 */
	private function redirect( $draw_id ) {
		$url = admin_url( 'admin.php?page=' . Lottery_Draw::MENU_SLUG );

		if ( $draw_id ) {
			$url = add_query_arg( 'draw', $draw_id, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
