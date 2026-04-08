<?php
/**
 * Announcer custom post type and meta boxes.
 *
 * @package FisHotel\Misc\Sections\Announcer
 */

namespace FisHotel\Misc\Sections\Announcer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Type
 */
class Post_Type {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'fishotel_announce';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_announcer_save';

	/**
	 * Default meta values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		// General.
		'_announcer_enabled'            => '1',
		'_announcer_position'           => 'top',
		'_announcer_sticky'             => '1',
		'_announcer_layout'             => 'full',

		// Display.
		'_announcer_display_trigger'    => 'immediate',
		'_announcer_delay_seconds'      => '0',
		'_announcer_scroll_percent'     => '50',
		'_announcer_schedule_enabled'   => '0',
		'_announcer_schedule_start'     => '',
		'_announcer_schedule_end'       => '',

		// Style.
		'_announcer_bg_color'           => '#0073aa',
		'_announcer_text_color'         => '#ffffff',
		'_announcer_font_size'          => '14',
		'_announcer_padding'            => '12',
		'_announcer_show_animation'     => 'slide',
		'_announcer_close_animation'    => 'slide',

		// Close.
		'_announcer_close_enabled'      => '1',
		'_announcer_cookie_days'        => '0',

		// CTA Buttons.
		'_announcer_cta_buttons'        => array(),

		// Location Rules.
		'_announcer_location_type'      => 'all',
		'_announcer_location_rules'     => array(),

		// Visitor Conditions.
		'_announcer_visitor_conditions' => array(),

		// Multiple Messages.
		'_announcer_multi_enabled'      => '0',
		'_announcer_multi_type'         => 'ticker',
		'_announcer_multi_autoplay'     => '1',
		'_announcer_multi_speed'        => '5',
		'_announcer_ticker_scroll'      => '0',

		// Countdown Timer.
		'_announcer_countdown_enabled'  => '0',
		'_announcer_countdown_date'     => '',
		'_announcer_countdown_label'    => '',
		'_announcer_countdown_complete' => 'hide',
	);

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
			add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
			add_action( 'admin_action_fishotel_duplicate_announcement', array( $this, 'duplicate_announcement' ) );
			add_action( 'wp_ajax_fishotel_announcer_toggle', array( $this, 'ajax_toggle_enabled' ) );
		}
	}

	/**
	 * Register the custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Announcements', 'fishotel-misc-plugin' ),
			'singular_name'      => __( 'Announcement', 'fishotel-misc-plugin' ),
			'add_new'            => __( 'Add New', 'fishotel-misc-plugin' ),
			'add_new_item'       => __( 'Add New Announcement', 'fishotel-misc-plugin' ),
			'edit_item'          => __( 'Edit Announcement', 'fishotel-misc-plugin' ),
			'new_item'           => __( 'New Announcement', 'fishotel-misc-plugin' ),
			'view_item'          => __( 'View Announcement', 'fishotel-misc-plugin' ),
			'search_items'       => __( 'Search Announcements', 'fishotel-misc-plugin' ),
			'not_found'          => __( 'No announcements found.', 'fishotel-misc-plugin' ),
			'not_found_in_trash' => __( 'No announcements found in Trash.', 'fishotel-misc-plugin' ),
			'all_items'          => __( 'All Announcements', 'fishotel-misc-plugin' ),
		);

		register_post_type( self::POST_TYPE, array(
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => false,
			'supports'     => array( 'title', 'editor' ),
			'capability_type' => 'post',
			'map_meta_cap' => true,
		) );
	}

	/**
	 * Add meta boxes.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'fishotel_announcer_settings',
			__( 'Announcement Settings', 'fishotel-misc-plugin' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'fishotel_announcer_preview',
			__( 'Live Preview', 'fishotel-misc-plugin' ),
			array( $this, 'render_preview_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the live preview meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_preview_box( $post ) {
		include Announcer::path() . 'views/meta-box-preview.php';
	}

	/**
	 * Render the settings meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, 'fishotel_announcer_nonce' );

		$meta = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = get_post_meta( $post->ID, $key, true );
			$meta[ $key ] = '' !== $value && false !== $value ? $value : $default;
		}

		include Announcer::path() . 'views/meta-box.php';
	}

	/**
	 * Save meta box data.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['fishotel_announcer_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['fishotel_announcer_nonce'], self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Checkboxes — store '1' if checked, '0' if not.
		$checkboxes = array(
			'_announcer_sticky',
			'_announcer_schedule_enabled',
			'_announcer_close_enabled',
			'_announcer_multi_enabled',
			'_announcer_multi_autoplay',
			'_announcer_ticker_scroll',
			'_announcer_countdown_enabled',
		);

		foreach ( $checkboxes as $key ) {
			$value = isset( $_POST[ $key ] ) ? '1' : '0';
			update_post_meta( $post_id, $key, $value );
		}

		// Scalar text/select/hidden fields.
		$text_fields = array(
			'_announcer_enabled',
			'_announcer_position',
			'_announcer_layout',
			'_announcer_display_trigger',
			'_announcer_delay_seconds',
			'_announcer_scroll_percent',
			'_announcer_schedule_start',
			'_announcer_schedule_end',
			'_announcer_bg_color',
			'_announcer_text_color',
			'_announcer_font_size',
			'_announcer_padding',
			'_announcer_show_animation',
			'_announcer_close_animation',
			'_announcer_cookie_days',
			'_announcer_location_type',
			'_announcer_multi_type',
			'_announcer_multi_speed',
			'_announcer_countdown_date',
			'_announcer_countdown_label',
			'_announcer_countdown_complete',
		);

		foreach ( $text_fields as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $post_id, $key, $value );
		}

		// CTA Buttons (array).
		$cta_buttons = array();
		if ( ! empty( $_POST['_announcer_cta_buttons'] ) && is_array( $_POST['_announcer_cta_buttons'] ) ) {
			foreach ( $_POST['_announcer_cta_buttons'] as $btn ) {
				if ( empty( $btn['text'] ) ) {
					continue;
				}
				$cta_buttons[] = array(
					'text'       => sanitize_text_field( wp_unslash( $btn['text'] ) ),
					'url'        => esc_url_raw( wp_unslash( $btn['url'] ?? '' ) ),
					'action'     => sanitize_key( $btn['action'] ?? 'link' ),
					'target'     => sanitize_key( $btn['target'] ?? '_self' ),
					'bg_color'   => sanitize_hex_color( $btn['bg_color'] ?? '#ffffff' ),
					'text_color' => sanitize_hex_color( $btn['text_color'] ?? '#000000' ),
					'animation'  => sanitize_key( $btn['animation'] ?? 'none' ),
					'message_index' => absint( $btn['message_index'] ?? 0 ),
				);
			}
		}
		update_post_meta( $post_id, '_announcer_cta_buttons', $cta_buttons );

		// Location Rules (array).
		$location_rules = array();
		if ( ! empty( $_POST['_announcer_location_rules'] ) && is_array( $_POST['_announcer_location_rules'] ) ) {
			foreach ( $_POST['_announcer_location_rules'] as $rule ) {
				if ( empty( $rule['type'] ) ) {
					continue;
				}
				$location_rules[] = array(
					'type'  => sanitize_key( $rule['type'] ),
					'value' => sanitize_text_field( wp_unslash( $rule['value'] ?? '' ) ),
				);
			}
		}
		update_post_meta( $post_id, '_announcer_location_rules', $location_rules );

		// Visitor Conditions (array).
		$visitor_conditions = array();
		if ( ! empty( $_POST['_announcer_visitor_conditions'] ) && is_array( $_POST['_announcer_visitor_conditions'] ) ) {
			foreach ( $_POST['_announcer_visitor_conditions'] as $cond ) {
				if ( empty( $cond['type'] ) ) {
					continue;
				}
				$visitor_conditions[] = array(
					'type'     => sanitize_key( $cond['type'] ),
					'operator' => sanitize_key( $cond['operator'] ?? 'is' ),
					'value'    => sanitize_text_field( wp_unslash( $cond['value'] ?? '' ) ),
				);
			}
		}
		update_post_meta( $post_id, '_announcer_visitor_conditions', $visitor_conditions );
	}

	/**
	 * Enqueue admin assets on the announcement edit screen.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-announcer-admin',
			Announcer::url() . 'css/announcer-admin.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		// List table page — only needs toggle CSS/JS.
		if ( 'edit.php' === $hook_suffix ) {
			wp_enqueue_script(
				'fishotel-announcer-admin',
				Announcer::url() . 'js/announcer-admin.js',
				array( 'jquery' ),
				FISHOTEL_MISC_VERSION,
				true
			);

			wp_localize_script( 'fishotel-announcer-admin', 'ancrList', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fishotel_announcer_list_nonce' ),
			) );
			return;
		}

		// Edit page — needs color picker and full JS.
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'fishotel-announcer-admin',
			Announcer::url() . 'js/announcer-admin.js',
			array( 'jquery', 'wp-color-picker' ),
			FISHOTEL_MISC_VERSION,
			true
		);
	}

	/**
	 * Custom columns for the announcements list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				// Insert our columns before the date column.
				$new['announcer_status']   = __( 'Status', 'fishotel-misc-plugin' );
				$new['announcer_display']  = __( 'Display', 'fishotel-misc-plugin' );
				$new['announcer_position'] = __( 'Position', 'fishotel-misc-plugin' );
				$new['announcer_sticky']   = __( 'Sticky', 'fishotel-misc-plugin' );
				$new['announcer_colors']   = __( 'Colors', 'fishotel-misc-plugin' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'announcer_status':
				$enabled = get_post_meta( $post_id, '_announcer_enabled', true );
				$checked = ( '1' === $enabled || '' === $enabled ) ? ' checked' : '';
				echo '<label class="ancr-list-toggle">';
				echo '<input type="checkbox" data-post-id="' . esc_attr( $post_id ) . '"' . $checked . '>';
				echo '<span class="ancr-list-slider"></span>';
				echo '</label>';
				break;

			case 'announcer_display':
				$scheduled = get_post_meta( $post_id, '_announcer_schedule_enabled', true );
				if ( '1' === $scheduled ) {
					$start = get_post_meta( $post_id, '_announcer_schedule_start', true );
					$end   = get_post_meta( $post_id, '_announcer_schedule_end', true );
					echo '<span>' . esc_html__( 'Scheduled between', 'fishotel-misc-plugin' ) . '</span><br>';
					echo '<small style="color:#a0a0a0;">';
					echo esc_html( $start ?: '—' ) . ' - ' . esc_html( $end ?: '—' );
					echo '</small>';
				} else {
					$trigger = self::get_meta( $post_id, '_announcer_display_trigger' );
					if ( 'delay' === $trigger ) {
						$secs = self::get_meta( $post_id, '_announcer_delay_seconds' );
						/* translators: %s: number of seconds */
						echo esc_html( sprintf( __( 'After %ss delay', 'fishotel-misc-plugin' ), $secs ) );
					} elseif ( 'scroll' === $trigger ) {
						$pct = self::get_meta( $post_id, '_announcer_scroll_percent' );
						/* translators: %s: scroll percentage */
						echo esc_html( sprintf( __( 'On %s%% scroll', 'fishotel-misc-plugin' ), $pct ) );
					} else {
						echo esc_html__( 'Immediate', 'fishotel-misc-plugin' );
					}
				}
				break;

			case 'announcer_position':
				$pos = get_post_meta( $post_id, '_announcer_position', true );
				echo esc_html( 'bottom' === $pos ? __( 'Bottom', 'fishotel-misc-plugin' ) : __( 'Top', 'fishotel-misc-plugin' ) );
				break;

			case 'announcer_sticky':
				$sticky = get_post_meta( $post_id, '_announcer_sticky', true );
				echo esc_html( '1' === $sticky ? __( 'Yes', 'fishotel-misc-plugin' ) : __( 'No', 'fishotel-misc-plugin' ) );
				break;

			case 'announcer_colors':
				$bg   = self::get_meta( $post_id, '_announcer_bg_color' );
				$text = self::get_meta( $post_id, '_announcer_text_color' );

				$cta_buttons = self::get_meta( $post_id, '_announcer_cta_buttons' );
				$btn_colors  = array();
				if ( is_array( $cta_buttons ) ) {
					foreach ( $cta_buttons as $btn ) {
						if ( ! empty( $btn['bg_color'] ) ) {
							$btn_colors[] = $btn['bg_color'];
						}
					}
				}

				echo '<span class="ancr-color-swatch" style="background:' . esc_attr( $bg ) . ';" title="' . esc_attr( 'BG: ' . $bg ) . '"></span>';
				echo '<span class="ancr-color-swatch" style="background:' . esc_attr( $text ) . ';" title="' . esc_attr( 'Text: ' . $text ) . '"></span>';
				foreach ( array_slice( $btn_colors, 0, 3 ) as $c ) {
					echo '<span class="ancr-color-swatch" style="background:' . esc_attr( $c ) . ';" title="' . esc_attr( 'CTA: ' . $c ) . '"></span>';
				}
				break;
		}
	}

	/**
	 * Add duplicate link to row actions.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    The post object.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_posts' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=fishotel_duplicate_announcement&post=' . $post->ID ),
			'fishotel_duplicate_' . $post->ID
		);

		$actions['duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'fishotel-misc-plugin' ) . '</a>';

		return $actions;
	}

	/**
	 * Handle the duplicate announcement action.
	 */
	public function duplicate_announcement() {
		if ( ! isset( $_GET['post'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Invalid request.', 'fishotel-misc-plugin' ) );
		}

		$post_id = absint( $_GET['post'] );

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'fishotel_duplicate_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'fishotel-misc-plugin' ) );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fishotel-misc-plugin' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Announcement not found.', 'fishotel-misc-plugin' ) );
		}

		$new_id = wp_insert_post( array(
			'post_title'   => $post->post_title . ' (' . __( 'Copy', 'fishotel-misc-plugin' ) . ')',
			'post_content' => $post->post_content,
			'post_status'  => 'draft',
			'post_type'    => self::POST_TYPE,
		) );

		if ( $new_id && ! is_wp_error( $new_id ) ) {
			$meta = get_post_meta( $post_id );
			foreach ( $meta as $key => $values ) {
				if ( 0 === strpos( $key, '_announcer_' ) ) {
					$value = maybe_unserialize( $values[0] );
					update_post_meta( $new_id, $key, $value );
				}
			}
			// Disable the duplicate by default.
			update_post_meta( $new_id, '_announcer_enabled', '0' );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}

	/**
	 * AJAX handler for the list-table toggle switch.
	 */
	public function ajax_toggle_enabled() {
		check_ajax_referer( 'fishotel_announcer_list_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'] ? '1' : '0';

		if ( ! $post_id || get_post_type( $post_id ) !== self::POST_TYPE ) {
			wp_send_json_error( array( 'message' => 'Invalid announcement.' ), 400 );
		}

		update_post_meta( $post_id, '_announcer_enabled', $enabled );

		wp_send_json_success( array(
			'post_id' => $post_id,
			'enabled' => $enabled,
		) );
	}

	/**
	 * Get a meta value with fallback to default.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return mixed
	 */
	public static function get_meta( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( '' === $value || false === $value ) {
			return self::DEFAULTS[ $key ] ?? '';
		}

		return $value;
	}
}
