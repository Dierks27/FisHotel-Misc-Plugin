<?php
/**
 * Coming Soon admin — metabox on product edit screen, sortable
 * column on the products list table, asset enqueue.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 */

namespace FisHotel\Misc\Sections\Coming_Soon;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Nonce action for the metabox save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_coming_soon_save_meta';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'fishotel_coming_soon_nonce';

	/**
	 * Hidden form field carrying the datetime-local input value.
	 *
	 * @var string
	 */
	const FIELD_NAME = 'fh_coming_soon_release_datetime';

	/**
	 * Column key for the products list table.
	 *
	 * @var string
	 */
	const COLUMN_KEY = 'fh_coming_soon';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_meta' ), 10, 2 );

		add_filter( 'manage_edit-product_columns', array( $this, 'register_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_release' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/* ============================================================== */
	/*  Metabox                                                        */
	/* ============================================================== */

	/**
	 * Register the metabox on the product edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'fishotel_coming_soon',
			__( 'FisHotel Coming Soon', 'fishotel-misc-plugin' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post Current product post.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$timestamp = absint( get_post_meta( $post->ID, Coming_Soon::META_KEY, true ) );
		$tz        = wp_timezone();
		$tz_label  = $tz->getName();

		// Convert the stored UTC timestamp into a datetime-local
		// string in the site timezone for the form input.
		$datetime_local = '';
		if ( $timestamp ) {
			$dt = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz );
			$datetime_local = $dt->format( 'Y-m-d\TH:i' );
		}

		$now    = time();
		$status = '';
		if ( ! $timestamp ) {
			$status = 'none';
		} elseif ( $timestamp > $now ) {
			$status = 'future';
		} else {
			$status = 'past';
		}

		$released_label = '';
		if ( 'past' === $status ) {
			$released_label = wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				$timestamp
			);
		}

		include Coming_Soon::path() . 'views/metabox.php';
	}

	/**
	 * Save the metabox data.
	 *
	 * Converts the datetime-local input from the site timezone into a
	 * UNIX timestamp, then stores it in `_fh_release_datetime`.
	 *
	 * @param int      $post_id Product ID.
	 * @param \WP_Post $post    The product post.
	 */
	public function save_meta( $post_id, $post ) {
		unset( $post );

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_NAME ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( Coming_Soon::PRODUCT_CAP, $post_id ) ) {
			return;
		}

		$raw = isset( $_POST[ self::FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : '';

		// Empty input → clear the meta entirely.
		if ( '' === $raw ) {
			delete_post_meta( $post_id, Coming_Soon::META_KEY );
			delete_post_meta( $post_id, Coming_Soon::DISPATCHED_KEY );
			$this->bust_product_cache( $post_id );
			return;
		}

		$tz = wp_timezone();
		$dt = \DateTime::createFromFormat( 'Y-m-d\TH:i', $raw, $tz );

		if ( ! $dt ) {
			// Try with seconds if the browser sent them.
			$dt = \DateTime::createFromFormat( 'Y-m-d\TH:i:s', $raw, $tz );
		}

		if ( ! $dt ) {
			return;
		}

		$timestamp = $dt->getTimestamp();
		update_post_meta( $post_id, Coming_Soon::META_KEY, $timestamp );

		// New future window → reset the dispatched flag so the
		// release transition fires again when the time passes.
		if ( $timestamp > time() ) {
			delete_post_meta( $post_id, Coming_Soon::DISPATCHED_KEY );
		}

		$this->bust_product_cache( $post_id );
	}

	/**
	 * Clear cached product data after a meta change.
	 *
	 * @param int $post_id Product ID.
	 */
	private function bust_product_cache( $post_id ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
	}

	/* ============================================================== */
	/*  Products List Column                                           */
	/* ============================================================== */

	/**
	 * Insert our column before the date column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function register_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new[ self::COLUMN_KEY ] = __( 'Coming Soon', 'fishotel-misc-plugin' );
			}
			$new[ $key ] = $label;
		}

		// Append at the end if the date column wasn't found.
		if ( ! isset( $new[ self::COLUMN_KEY ] ) ) {
			$new[ self::COLUMN_KEY ] = __( 'Coming Soon', 'fishotel-misc-plugin' );
		}

		return $new;
	}

	/**
	 * Render the column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$timestamp = absint( get_post_meta( $post_id, Coming_Soon::META_KEY, true ) );

		if ( ! $timestamp ) {
			echo '<span class="fh-cs-col fh-cs-col--none">&mdash;</span>';
			return;
		}

		$now       = time();
		$formatted = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);

		if ( $timestamp > $now ) {
			printf(
				'<span class="fh-cs-col fh-cs-col--future">%s %s</span>',
				esc_html__( 'Coming:', 'fishotel-misc-plugin' ),
				esc_html( $formatted )
			);
		} else {
			printf(
				'<span class="fh-cs-col fh-cs-col--past">%s %s</span>',
				esc_html__( 'Released:', 'fishotel-misc-plugin' ),
				esc_html( $formatted )
			);
		}
	}

	/**
	 * Mark the column as sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;
		return $columns;
	}

	/**
	 * Apply ordering when the column header is clicked.
	 *
	 * @param \WP_Query $query Current admin query.
	 */
	public function sort_by_release( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::COLUMN_KEY !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', Coming_Soon::META_KEY );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/* ============================================================== */
	/*  Asset Enqueue                                                  */
	/* ============================================================== */

	/**
	 * Enqueue admin CSS / JS on relevant screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_product_screen   = ( 'product' === $screen->post_type );
		$on_settings_page    = ( false !== strpos( $screen->id, Coming_Soon::MENU_SLUG ) );

		if ( ! $on_product_screen && ! $on_settings_page ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-coming-soon-admin',
			Coming_Soon::url() . 'css/coming-soon-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		// JS only loads on the product edit screen (metabox interactions).
		if ( $on_product_screen && in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_script(
				'fishotel-coming-soon-admin',
				Coming_Soon::url() . 'js/coming-soon-admin.js',
				array( 'jquery' ),
				FISHOTEL_MISC_VERSION,
				true
			);

			wp_localize_script(
				'fishotel-coming-soon-admin',
				'fhComingSoonAdmin',
				array(
					'serverTime' => time(),
					'i18n'       => array(
						'confirmReleaseNow' => __( 'Set release datetime to right now? This will make the product live immediately when you click Update.', 'fishotel-misc-plugin' ),
						'confirmClear'      => __( 'Clear the Coming Soon datetime? The product will revert to normal display when you click Update.', 'fishotel-misc-plugin' ),
						'releasesIn'        => __( 'Releases in', 'fishotel-misc-plugin' ),
						'released'          => __( 'Released', 'fishotel-misc-plugin' ),
						'notComingSoon'     => __( 'Not coming soon', 'fishotel-misc-plugin' ),
						'days'              => __( 'd', 'fishotel-misc-plugin' ),
						'hours'             => __( 'h', 'fishotel-misc-plugin' ),
						'minutes'           => __( 'm', 'fishotel-misc-plugin' ),
						'seconds'           => __( 's', 'fishotel-misc-plugin' ),
					),
				)
			);
		}
	}
}
