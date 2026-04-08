<?php
/**
 * Announcer frontend — renders active announcement bars.
 *
 * @package FisHotel\Misc\Sections\Announcer
 */

namespace FisHotel\Misc\Sections\Announcer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Register hooks.
	 */
	public function init() {
		// Primary: inject right after <body> tag.
		add_action( 'wp_body_open', array( $this, 'render_announcements' ), 1 );
		// Fallback: if theme doesn't call wp_body_open, use wp_footer.
		add_action( 'wp_footer', array( $this, 'render_announcements_fallback' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'fishotel_announcement', array( $this, 'shortcode' ) );
	}

	/**
	 * Track whether announcements were already rendered via wp_body_open.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Enqueue frontend CSS and JS.
	 */
	public function enqueue_assets() {
		$announcements = $this->get_active_announcements();

		if ( empty( $announcements ) ) {
			return;
		}

		wp_enqueue_style(
			'fishotel-announcer-front',
			Announcer::url() . 'css/announcer-front.css',
			array(),
			FISHOTEL_MISC_VERSION
		);

		wp_enqueue_script(
			'fishotel-announcer-front',
			Announcer::url() . 'js/announcer-front.js',
			array(),
			FISHOTEL_MISC_VERSION,
			true
		);
	}

	/**
	 * Render all active announcements (called from wp_body_open).
	 */
	public function render_announcements() {
		if ( $this->rendered ) {
			return;
		}
		$this->rendered = true;

		$announcements = $this->get_active_announcements();

		if ( empty( $announcements ) ) {
			return;
		}

		foreach ( $announcements as $post ) {
			echo $this->build_announcement_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Fallback renderer for themes that don't call wp_body_open.
	 */
	public function render_announcements_fallback() {
		if ( $this->rendered ) {
			return;
		}
		$this->render_announcements();
	}

	/**
	 * Shortcode handler: [fishotel_announcement id="123"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'fishotel_announcement' );
		$id   = absint( $atts['id'] );

		if ( ! $id ) {
			return '';
		}

		$post = get_post( $id );

		if ( ! $post || Post_Type::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		// Enqueue assets for shortcode usage.
		wp_enqueue_style( 'fishotel-announcer-front', Announcer::url() . 'css/announcer-front.css', array(), FISHOTEL_MISC_VERSION );
		wp_enqueue_script( 'fishotel-announcer-front', Announcer::url() . 'js/announcer-front.js', array(), FISHOTEL_MISC_VERSION, true );

		return $this->build_announcement_html( $post, true );
	}

	/**
	 * Get all active announcements that should display on the current page.
	 *
	 * @return \WP_Post[]
	 */
	private function get_active_announcements() {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		$posts = get_posts( array(
			'post_type'      => Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => '_announcer_enabled',
					'value' => '1',
				),
				array(
					'key'     => '_announcer_enabled',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		$cached = array();

		foreach ( $posts as $post ) {
			if ( ! $this->passes_schedule( $post->ID ) ) {
				continue;
			}
			if ( ! $this->passes_location_rules( $post->ID ) ) {
				continue;
			}
			if ( ! $this->passes_visitor_conditions( $post->ID ) ) {
				continue;
			}

			$cached[] = $post;
		}

		return $cached;
	}

	/**
	 * Check scheduling rules.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function passes_schedule( $post_id ) {
		if ( '1' !== Post_Type::get_meta( $post_id, '_announcer_schedule_enabled' ) ) {
			return true;
		}

		$now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$start = Post_Type::get_meta( $post_id, '_announcer_schedule_start' );
		$end   = Post_Type::get_meta( $post_id, '_announcer_schedule_end' );

		if ( $start && strtotime( $start ) > $now ) {
			return false;
		}

		if ( $end && strtotime( $end ) < $now ) {
			return false;
		}

		return true;
	}

	/**
	 * Check location (page targeting) rules.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function passes_location_rules( $post_id ) {
		$type  = Post_Type::get_meta( $post_id, '_announcer_location_type' );
		$rules = Post_Type::get_meta( $post_id, '_announcer_location_rules' );

		if ( 'all' === $type || empty( $rules ) || ! is_array( $rules ) ) {
			return true;
		}

		$matches = false;

		foreach ( $rules as $rule ) {
			if ( $this->rule_matches( $rule ) ) {
				$matches = true;
				break;
			}
		}

		return 'include' === $type ? $matches : ! $matches;
	}

	/**
	 * Evaluate a single location rule against the current page.
	 *
	 * @param array $rule Rule data.
	 * @return bool
	 */
	private function rule_matches( $rule ) {
		$value = $rule['value'] ?? '';

		switch ( $rule['type'] ) {
			case 'front_page':
				return is_front_page();

			case 'blog':
				return is_home();

			case 'page':
				return is_page( is_numeric( $value ) ? (int) $value : $value );

			case 'post':
				return is_single( is_numeric( $value ) ? (int) $value : $value );

			case 'category':
				return is_category( $value ) || ( is_single() && has_category( $value ) );

			case 'post_type':
				return is_singular( $value );

			case 'archive':
				return is_archive();

			case 'search':
				return is_search();

			case '404':
				return is_404();

			case 'url_contains':
				return $value && false !== strpos( $_SERVER['REQUEST_URI'] ?? '', $value );
		}

		return false;
	}

	/**
	 * Check visitor condition rules (server-side where possible).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function passes_visitor_conditions( $post_id ) {
		$conditions = Post_Type::get_meta( $post_id, '_announcer_visitor_conditions' );

		if ( empty( $conditions ) || ! is_array( $conditions ) ) {
			return true;
		}

		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

		foreach ( $conditions as $cond ) {
			$match = false;

			switch ( $cond['type'] ) {
				case 'logged_in':
					$match = is_user_logged_in();
					if ( 'no' === strtolower( $cond['value'] ) ) {
						$match = ! $match;
						$cond['operator'] = 'is'; // Normalize.
					}
					break;

				case 'user_role':
					if ( is_user_logged_in() ) {
						$user  = wp_get_current_user();
						$match = in_array( strtolower( $cond['value'] ), array_map( 'strtolower', $user->roles ), true );
					}
					break;

				case 'device':
					$val   = strtolower( $cond['value'] );
					$is_mobile = wp_is_mobile();
					if ( 'mobile' === $val ) {
						$match = $is_mobile;
					} elseif ( 'desktop' === $val ) {
						$match = ! $is_mobile;
					}
					break;

				case 'browser':
					$val = strtolower( $cond['value'] );
					$match = false !== stripos( $ua, $val );
					break;

				case 'os':
					$val = strtolower( $cond['value'] );
					$match = false !== stripos( $ua, $val );
					break;

				case 'referrer':
					$ref   = $_SERVER['HTTP_REFERER'] ?? '';
					$match = $cond['value'] && false !== stripos( $ref, $cond['value'] );
					break;

				case 'language':
					$lang  = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
					$match = $cond['value'] && false !== stripos( $lang, $cond['value'] );
					break;
			}

			// Apply operator.
			if ( 'is_not' === ( $cond['operator'] ?? 'is' ) ) {
				$match = ! $match;
			}

			// All conditions must pass (AND logic).
			if ( ! $match ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the HTML for a single announcement bar.
	 *
	 * @param \WP_Post $post        The announcement post.
	 * @param bool     $is_shortcode Whether this is rendered via shortcode.
	 * @return string
	 */
	private function build_announcement_html( $post, $is_shortcode = false ) {
		$id = $post->ID;

		$position       = Post_Type::get_meta( $id, '_announcer_position' );
		$sticky         = Post_Type::get_meta( $id, '_announcer_sticky' );
		$layout         = Post_Type::get_meta( $id, '_announcer_layout' );
		$trigger        = Post_Type::get_meta( $id, '_announcer_display_trigger' );
		$delay          = Post_Type::get_meta( $id, '_announcer_delay_seconds' );
		$scroll_pct     = Post_Type::get_meta( $id, '_announcer_scroll_percent' );
		$bg_color       = Post_Type::get_meta( $id, '_announcer_bg_color' );
		$text_color     = Post_Type::get_meta( $id, '_announcer_text_color' );
		$font_size      = Post_Type::get_meta( $id, '_announcer_font_size' );
		$padding        = Post_Type::get_meta( $id, '_announcer_padding' );
		$show_anim      = Post_Type::get_meta( $id, '_announcer_show_animation' );
		$close_anim     = Post_Type::get_meta( $id, '_announcer_close_animation' );
		$close_enabled  = Post_Type::get_meta( $id, '_announcer_close_enabled' );
		$cookie_days    = Post_Type::get_meta( $id, '_announcer_cookie_days' );
		$multi_enabled  = Post_Type::get_meta( $id, '_announcer_multi_enabled' );
		$multi_type     = Post_Type::get_meta( $id, '_announcer_multi_type' );
		$multi_autoplay = Post_Type::get_meta( $id, '_announcer_multi_autoplay' );
		$multi_speed    = Post_Type::get_meta( $id, '_announcer_multi_speed' );
		$ticker_scroll  = Post_Type::get_meta( $id, '_announcer_ticker_scroll' );
		$cd_enabled     = Post_Type::get_meta( $id, '_announcer_countdown_enabled' );
		$cd_date        = Post_Type::get_meta( $id, '_announcer_countdown_date' );
		$cd_label       = Post_Type::get_meta( $id, '_announcer_countdown_label' );
		$cd_complete    = Post_Type::get_meta( $id, '_announcer_countdown_complete' );
		$cta_buttons    = Post_Type::get_meta( $id, '_announcer_cta_buttons' );

		if ( ! is_array( $cta_buttons ) ) {
			$cta_buttons = array();
		}

		// Parse content for multiple messages.
		$content  = apply_filters( 'the_content', $post->post_content );
		$messages = array( $content );

		if ( '1' === $multi_enabled ) {
			$raw      = $post->post_content;
			$parts    = preg_split( '/<!--\s*message\s*-->/i', $raw );
			if ( count( $parts ) > 1 ) {
				$messages = array_map( function ( $part ) {
					return apply_filters( 'the_content', trim( $part ) );
				}, $parts );
			}

			// Random mode — pick one.
			if ( 'random' === $multi_type && count( $messages ) > 1 ) {
				$messages = array( $messages[ array_rand( $messages ) ] );
			}
		}

		// Build classes.
		$classes = array( 'ancr-bar' );
		$classes[] = 'ancr-pos-' . sanitize_html_class( $position );
		if ( '1' === $sticky && ! $is_shortcode ) {
			$classes[] = 'ancr-sticky';
		}
		if ( 'boxed' === $layout ) {
			$classes[] = 'ancr-boxed';
		}
		if ( $is_shortcode ) {
			$classes[] = 'ancr-shortcode';
		}
		if ( 'immediate' !== $trigger ) {
			$classes[] = 'ancr-hidden';
		}
		if ( '1' === $multi_enabled && 'ticker' === $multi_type && '1' === $ticker_scroll ) {
			$classes[] = 'ancr-marquee';
		}

		// Data attributes for JS.
		$data = array(
			'id'            => $id,
			'trigger'       => $trigger,
			'delay'         => absint( $delay ),
			'scroll'        => absint( $scroll_pct ),
			'show-anim'     => $show_anim,
			'close-anim'    => $close_anim,
			'cookie-days'   => absint( $cookie_days ),
			'multi'         => $multi_enabled,
			'multi-type'    => $multi_type,
			'multi-auto'    => $multi_autoplay,
			'multi-speed'   => absint( $multi_speed ),
			'ticker-scroll' => $ticker_scroll,
			'countdown'     => $cd_enabled,
			'cd-date'       => $cd_date,
			'cd-complete'   => $cd_complete,
		);

		$data_attrs = '';
		foreach ( $data as $key => $val ) {
			$data_attrs .= ' data-ancr-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		// Build inline styles — critical positioning + design.
		$pos_top    = 'top' === $position ? 'top:0;bottom:auto;' : 'bottom:0;top:auto;';
		$fixed_or_relative = $is_shortcode ? 'position:relative;' : 'position:fixed;left:0;right:0;';

		$style = sprintf(
			'%s%swidth:100%%;height:auto;max-height:200px;overflow:hidden;z-index:99999;box-sizing:border-box;margin:0;background-color:%s;color:%s;font-size:%spx;padding:%spx 20px;',
			$fixed_or_relative,
			$is_shortcode ? '' : $pos_top,
			esc_attr( $bg_color ),
			esc_attr( $text_color ),
			esc_attr( $font_size ),
			esc_attr( $padding )
		);

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo $data_attrs; // phpcs:ignore ?> style="<?php echo esc_attr( $style ); ?>" role="alert">
			<div class="ancr-inner">

				<?php if ( '1' === $cd_enabled && $cd_date ) : ?>
					<div class="ancr-countdown">
						<?php if ( $cd_label ) : ?>
							<span class="ancr-cd-label"><?php echo esc_html( $cd_label ); ?></span>
						<?php endif; ?>
						<span class="ancr-cd-timer" data-ancr-cd-target="<?php echo esc_attr( $cd_date ); ?>">
							<span class="ancr-cd-part" data-unit="d">00</span><span class="ancr-cd-sep">:</span>
							<span class="ancr-cd-part" data-unit="h">00</span><span class="ancr-cd-sep">:</span>
							<span class="ancr-cd-part" data-unit="m">00</span><span class="ancr-cd-sep">:</span>
							<span class="ancr-cd-part" data-unit="s">00</span>
						</span>
					</div>
				<?php endif; ?>

				<div class="ancr-messages" data-count="<?php echo count( $messages ); ?>">
					<?php foreach ( $messages as $idx => $msg ) : ?>
						<div class="ancr-message <?php echo 0 === $idx ? 'ancr-message--active' : ''; ?>" data-index="<?php echo esc_attr( $idx ); ?>">
							<div class="ancr-content"><?php echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

							<?php
							// CTA buttons for this message.
							$msg_buttons = array_filter( $cta_buttons, function ( $btn ) use ( $idx ) {
								$mi = absint( $btn['message_index'] ?? 0 );
								return 0 === $mi || $mi === ( $idx + 1 );
							} );

							if ( ! empty( $msg_buttons ) ) :
								?>
								<div class="ancr-cta-wrap">
									<?php foreach ( $msg_buttons as $btn ) :
										$btn_style = sprintf(
											'background-color:%s;color:%s;',
											esc_attr( $btn['bg_color'] ?? '#ffffff' ),
											esc_attr( $btn['text_color'] ?? '#000000' )
										);
										$btn_classes = 'ancr-cta-btn';
										$anim        = $btn['animation'] ?? 'none';
										if ( 'none' !== $anim ) {
											$btn_classes .= ' ancr-anim-' . sanitize_html_class( $anim );
										}
										$action = $btn['action'] ?? 'link';
										$target = $btn['target'] ?? '_self';
										$url    = $btn['url'] ?? '#';
										?>
										<a href="<?php echo esc_url( $url ); ?>"
											class="<?php echo esc_attr( $btn_classes ); ?>"
											style="<?php echo esc_attr( $btn_style ); ?>"
											target="<?php echo esc_attr( $target ); ?>"
											data-ancr-action="<?php echo esc_attr( $action ); ?>"
											><?php echo esc_html( $btn['text'] ); ?></a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( '1' === $multi_enabled && 'ticker' === $multi_type && count( $messages ) > 1 && '1' !== $ticker_scroll ) : ?>
					<div class="ancr-nav">
						<button type="button" class="ancr-nav-btn ancr-nav-prev" aria-label="<?php esc_attr_e( 'Previous', 'fishotel-misc-plugin' ); ?>">&lsaquo;</button>
						<button type="button" class="ancr-nav-btn ancr-nav-next" aria-label="<?php esc_attr_e( 'Next', 'fishotel-misc-plugin' ); ?>">&rsaquo;</button>
					</div>
				<?php endif; ?>

				<?php if ( '1' === $close_enabled ) : ?>
					<button type="button" class="ancr-close" aria-label="<?php esc_attr_e( 'Close', 'fishotel-misc-plugin' ); ?>">&times;</button>
				<?php endif; ?>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
