<?php
/**
 * Lottery Draw frontend — the public, recomputable results page, the
 * `[fishotel_draw]` shortcode, and the raw JSON payload endpoint.
 *
 * Everything here is read-only and unauthenticated by design. The page
 * ships the stored payload to the browser and lets draw.js re-run the
 * draw against it; PHP never asserts that a result is correct, it only
 * prints what was stored and lets the visitor's own browser judge it.
 *
 * @package FisHotel\Misc\Sections\Lottery_Draw
 */

namespace FisHotel\Misc\Sections\Lottery_Draw;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Number of rendered draw instances on this request, used to give
	 * each one a unique DOM id when several share a page.
	 *
	 * @var int
	 */
	private $instances = 0;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_shortcode( 'fishotel_draw', array( $this, 'render_shortcode' ) );

		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_json' ) );

		// Priority 20: after wpautop, so the rendered markup is handed
		// back untouched rather than re-paragraphed.
		add_filter( 'the_content', array( $this, 'render_singular' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ) );
	}

	/**
	 * Expose the JSON query var.
	 *
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = Lottery_Draw::JSON_QUERY_VAR;

		return $vars;
	}

	/**
	 * Serve the raw stored payload at the public JSON URL.
	 *
	 * The bytes sent here are the bytes in the database, untouched, so a
	 * member can diff this against what the page claims without having to
	 * account for re-encoding.
	 */
	public function maybe_serve_json() {
		$draw_id = get_query_var( Lottery_Draw::JSON_QUERY_VAR );

		if ( ! $draw_id ) {
			return;
		}

		$post    = Store::find_post( $draw_id );
		$payload = $post ? Store::get_payload( $post->ID ) : null;

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );

		if ( ! $payload || ! Store::is_published( $payload ) ) {
			status_header( 404 );
			echo wp_json_encode( array( 'error' => 'draw_not_found' ) );
			exit;
		}

		status_header( 200 );

		// Deliberately unescaped: this response *is* the stored JSON
		// document, served with a JSON content type. Escaping it for
		// HTML would corrupt the payload members verify against.
		echo Store::get_raw( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Enqueue assets early when the current request will render a draw.
	 *
	 * The render path enqueues lazily too — this pass just gets the
	 * stylesheet into `wp_head` for the common cases.
	 */
	public function maybe_enqueue_early() {
		if ( is_singular( Lottery_Draw::POST_TYPE ) ) {
			$this->enqueue_assets();
			return;
		}

		if ( is_singular() ) {
			$post = get_post();

			if ( $post && has_shortcode( (string) $post->post_content, 'fishotel_draw' ) ) {
				$this->enqueue_assets();
			}
		}
	}

	/**
	 * Enqueue the draw engine, the public verifier, and the stylesheet.
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'fishotel-lottery-public',
			Lottery_Draw::url() . 'css/lottery-public.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		// Byte-for-byte the same file the admin screen ran to produce the
		// result. That is the whole basis of the verification claim.
		wp_enqueue_script(
			'fishotel-draw-core',
			Lottery_Draw::url() . 'js/draw.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);

		wp_enqueue_script(
			'fishotel-lottery-public',
			Lottery_Draw::url() . 'js/lottery-public.js',
			array( 'fishotel-draw-core' ),
			FISHOTEL_MISC_VERSION,
			true
		);
	}

	/**
	 * Render a draw on its own permalink.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function render_singular( $content ) {
		if ( ! is_singular( Lottery_Draw::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $this->render( get_post()->ID );
	}

	/**
	 * Shortcode handler: `[fishotel_draw id="rvs-2026-08"]`.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'fishotel_draw' );
		$post = Store::find_post( $atts['id'] );

		if ( ! $post ) {
			return '<p class="fh-draw-missing">' . esc_html__( 'That draw could not be found.', 'fishotel-misc-plugin' ) . '</p>';
		}

		return $this->render( $post->ID );
	}

	/**
	 * Render one draw.
	 *
	 * @param int $post_id Draw post ID.
	 * @return string HTML.
	 */
	private function render( $post_id ) {
		$payload = Store::get_payload( $post_id );

		if ( ! $payload ) {
			return '<p class="fh-draw-missing">' . esc_html__( 'That draw could not be found.', 'fishotel-misc-plugin' ) . '</p>';
		}

		if ( ! Store::is_published( $payload ) ) {
			$committed = Lottery_Draw::STATUS_COMMITTED === $payload['status'];

			return '<p class="fh-draw-pending">' . esc_html(
				$committed
					? __( 'The seed for this draw is committed. Results will appear here once the draw has been run.', 'fishotel-misc-plugin' )
					: __( 'This draw has not been published yet.', 'fishotel-misc-plugin' )
			) . '</p>';
		}

		$this->enqueue_assets();
		$this->instances++;

		$instance = 'fh-draw-' . $payload['id'] . '-' . $this->instances;
		$raw      = Store::get_raw( $post_id );

		// Hand the browser the stored payload verbatim. lottery-public.js
		// re-runs the draw from it and compares the result to the winner
		// lists it contains — so a payload edited in the database fails
		// its own check, loudly, on the next page load.
		wp_add_inline_script(
			'fishotel-lottery-public',
			'window.fishotelDrawPayloads = window.fishotelDrawPayloads || {};'
			. 'window.fishotelDrawPayloads[' . wp_json_encode( $instance ) . '] = '
			. wp_json_encode( $raw, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);

		$json_url = Store::json_url( $payload['id'] );

		ob_start();
		include Lottery_Draw::path() . 'views/public.php';

		return (string) ob_get_clean();
	}
}
