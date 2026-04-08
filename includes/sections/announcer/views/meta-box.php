<?php
/**
 * Announcer settings meta box — vertical sidebar tabs.
 *
 * @package FisHotel\Misc\Sections\Announcer
 * @var array   $meta All meta values with defaults applied.
 * @var WP_Post $post The current post.
 */

defined( 'ABSPATH' ) || exit;

$tabs = array(
	'cta'        => array( 'icon' => 'dashicons-megaphone',       'label' => __( 'Call to Actions', 'fishotel-misc-plugin' ) ),
	'display'    => array( 'icon' => 'dashicons-visibility',      'label' => __( 'Display', 'fishotel-misc-plugin' ) ),
	'position'   => array( 'icon' => 'dashicons-align-full-width','label' => __( 'Position', 'fishotel-misc-plugin' ) ),
	'style'      => array( 'icon' => 'dashicons-art',             'label' => __( 'Design', 'fishotel-misc-plugin' ) ),
	'close'      => array( 'icon' => 'dashicons-dismiss',         'label' => __( 'Close', 'fishotel-misc-plugin' ) ),
	'location'   => array( 'icon' => 'dashicons-admin-site-alt3', 'label' => __( 'Location Rules', 'fishotel-misc-plugin' ) ),
	'visitor'    => array( 'icon' => 'dashicons-groups',           'label' => __( 'Visitor Conditions', 'fishotel-misc-plugin' ) ),
	'multi'      => array( 'icon' => 'dashicons-slides',           'label' => __( 'Multiple Messages', 'fishotel-misc-plugin' ) ),
	'countdown'  => array( 'icon' => 'dashicons-clock',            'label' => __( 'Countdown Timer', 'fishotel-misc-plugin' ) ),
);
?>

<div class="ancr-meta-wrap">

	<!-- Sidebar Navigation -->
	<div class="ancr-sidebar">
		<?php foreach ( $tabs as $id => $tab ) : ?>
			<button type="button"
				class="ancr-nav-item <?php echo 'cta' === $id ? 'ancr-nav-item--active' : ''; ?>"
				data-tab="<?php echo esc_attr( $id ); ?>">
				<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
				<?php echo esc_html( $tab['label'] ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<!-- Panel Area -->
	<div class="ancr-panel-area">

		<!-- ============================================================ -->
		<!-- CALL TO ACTIONS                                               -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="cta">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Add buttons to the announcement', 'fishotel-misc-plugin' ); ?></h3>

			<div id="ancr-cta-list">
				<?php
				$cta_buttons = is_array( $meta['_announcer_cta_buttons'] ) ? $meta['_announcer_cta_buttons'] : array();
				if ( ! empty( $cta_buttons ) ) :
					foreach ( $cta_buttons as $i => $btn ) :
						?>
						<div class="ancr-cta-item" data-index="<?php echo esc_attr( $i ); ?>">
							<div class="ancr-cta-header">
								<strong><?php esc_html_e( 'Button', 'fishotel-misc-plugin' ); ?> <span class="ancr-cta-num"><?php echo esc_html( $i + 1 ); ?></span></strong>
								<button type="button" class="ancr-cta-remove" title="<?php esc_attr_e( 'Remove', 'fishotel-misc-plugin' ); ?>">&times;</button>
							</div>
							<div class="ancr-cta-body">
								<div class="ancr-field-row">
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Button text', 'fishotel-misc-plugin' ); ?></label>
										<input type="text" name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][text]" value="<?php echo esc_attr( $btn['text'] ); ?>">
									</div>
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'On click', 'fishotel-misc-plugin' ); ?></label>
										<select name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][action]">
											<option value="link" <?php selected( $btn['action'] ?? 'link', 'link' ); ?>><?php esc_html_e( 'Open link', 'fishotel-misc-plugin' ); ?></option>
											<option value="close" <?php selected( $btn['action'] ?? '', 'close' ); ?>><?php esc_html_e( 'Close announcement', 'fishotel-misc-plugin' ); ?></option>
											<option value="link_close" <?php selected( $btn['action'] ?? '', 'link_close' ); ?>><?php esc_html_e( 'Open link & close', 'fishotel-misc-plugin' ); ?></option>
										</select>
									</div>
								</div>
								<div class="ancr-field-row">
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Link URL', 'fishotel-misc-plugin' ); ?></label>
										<input type="url" name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( $btn['url'] ?? '' ); ?>">
									</div>
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Open link in', 'fishotel-misc-plugin' ); ?></label>
										<select name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][target]">
											<option value="_self" <?php selected( $btn['target'] ?? '_self', '_self' ); ?>><?php esc_html_e( 'Same window', 'fishotel-misc-plugin' ); ?></option>
											<option value="_blank" <?php selected( $btn['target'] ?? '', '_blank' ); ?>><?php esc_html_e( 'New tab', 'fishotel-misc-plugin' ); ?></option>
										</select>
									</div>
								</div>
								<div class="ancr-field-row">
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Button bg color', 'fishotel-misc-plugin' ); ?></label>
										<input type="text" name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][bg_color]" value="<?php echo esc_attr( $btn['bg_color'] ?? '#ffffff' ); ?>" class="ancr-color-picker">
									</div>
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Button text color', 'fishotel-misc-plugin' ); ?></label>
										<input type="text" name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][text_color]" value="<?php echo esc_attr( $btn['text_color'] ?? '#000000' ); ?>" class="ancr-color-picker">
									</div>
								</div>
								<div class="ancr-field-row">
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Animation', 'fishotel-misc-plugin' ); ?></label>
										<select name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][animation]">
											<option value="none" <?php selected( $btn['animation'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None', 'fishotel-misc-plugin' ); ?></option>
											<option value="bounce" <?php selected( $btn['animation'] ?? '', 'bounce' ); ?>>Bounce</option>
											<option value="flash" <?php selected( $btn['animation'] ?? '', 'flash' ); ?>>Flash</option>
											<option value="pulse" <?php selected( $btn['animation'] ?? '', 'pulse' ); ?>>Pulse</option>
											<option value="shake" <?php selected( $btn['animation'] ?? '', 'shake' ); ?>>Shake</option>
											<option value="swing" <?php selected( $btn['animation'] ?? '', 'swing' ); ?>>Swing</option>
											<option value="tada" <?php selected( $btn['animation'] ?? '', 'tada' ); ?>>Tada</option>
											<option value="wobble" <?php selected( $btn['animation'] ?? '', 'wobble' ); ?>>Wobble</option>
											<option value="jello" <?php selected( $btn['animation'] ?? '', 'jello' ); ?>>Jello</option>
											<option value="heartbeat" <?php selected( $btn['animation'] ?? '', 'heartbeat' ); ?>>Heartbeat</option>
											<option value="rubberband" <?php selected( $btn['animation'] ?? '', 'rubberband' ); ?>>Rubber Band</option>
										</select>
									</div>
									<div class="ancr-field">
										<label class="ancr-label"><?php esc_html_e( 'Assign to message #', 'fishotel-misc-plugin' ); ?></label>
										<input type="number" name="_announcer_cta_buttons[<?php echo esc_attr( $i ); ?>][message_index]" value="<?php echo esc_attr( $btn['message_index'] ?? 0 ); ?>" min="0" step="1">
										<span class="ancr-hint"><?php esc_html_e( '0 = all messages', 'fishotel-misc-plugin' ); ?></span>
									</div>
								</div>
							</div>
						</div>
						<?php
					endforeach;
				endif;
				?>
			</div>

			<button type="button" id="ancr-add-cta" class="button ancr-add-btn"><?php esc_html_e( 'Add button', 'fishotel-misc-plugin' ); ?></button>

		</div>

		<!-- ============================================================ -->
		<!-- DISPLAY                                                       -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="display" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Display settings', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Display trigger', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_display_trigger" id="ancr-display-trigger">
					<option value="immediate" <?php selected( $meta['_announcer_display_trigger'], 'immediate' ); ?>><?php esc_html_e( 'Immediately', 'fishotel-misc-plugin' ); ?></option>
					<option value="delay" <?php selected( $meta['_announcer_display_trigger'], 'delay' ); ?>><?php esc_html_e( 'After a delay', 'fishotel-misc-plugin' ); ?></option>
					<option value="scroll" <?php selected( $meta['_announcer_display_trigger'], 'scroll' ); ?>><?php esc_html_e( 'On page scroll', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

			<div class="ancr-field ancr-conditional" data-show-when="delay">
				<label class="ancr-label"><?php esc_html_e( 'Delay (seconds)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" name="_announcer_delay_seconds" value="<?php echo esc_attr( $meta['_announcer_delay_seconds'] ); ?>" min="0" step="1">
			</div>

			<div class="ancr-field ancr-conditional" data-show-when="scroll">
				<label class="ancr-label"><?php esc_html_e( 'Scroll distance (%)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" name="_announcer_scroll_percent" value="<?php echo esc_attr( $meta['_announcer_scroll_percent'] ); ?>" min="0" max="100" step="1">
			</div>

			<hr class="ancr-divider">

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_schedule_enabled" value="1" <?php checked( $meta['_announcer_schedule_enabled'], '1' ); ?> id="ancr-schedule-toggle">
					<?php esc_html_e( 'Enable scheduling', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

			<div class="ancr-schedule-fields" id="ancr-schedule-fields">
				<div class="ancr-field-row">
					<div class="ancr-field">
						<label class="ancr-label"><?php esc_html_e( 'Start date/time', 'fishotel-misc-plugin' ); ?></label>
						<input type="datetime-local" name="_announcer_schedule_start" value="<?php echo esc_attr( $meta['_announcer_schedule_start'] ); ?>">
					</div>
					<div class="ancr-field">
						<label class="ancr-label"><?php esc_html_e( 'End date/time', 'fishotel-misc-plugin' ); ?></label>
						<input type="datetime-local" name="_announcer_schedule_end" value="<?php echo esc_attr( $meta['_announcer_schedule_end'] ); ?>">
					</div>
				</div>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- POSITION                                                      -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="position" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Position settings', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Position', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_position">
					<option value="top" <?php selected( $meta['_announcer_position'], 'top' ); ?>><?php esc_html_e( 'Top of page', 'fishotel-misc-plugin' ); ?></option>
					<option value="bottom" <?php selected( $meta['_announcer_position'], 'bottom' ); ?>><?php esc_html_e( 'Bottom of page', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_sticky" value="1" <?php checked( $meta['_announcer_sticky'], '1' ); ?>>
					<?php esc_html_e( 'Sticky (stays visible on scroll)', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Layout', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_layout">
					<option value="full" <?php selected( $meta['_announcer_layout'], 'full' ); ?>><?php esc_html_e( 'Full width', 'fishotel-misc-plugin' ); ?></option>
					<option value="boxed" <?php selected( $meta['_announcer_layout'], 'boxed' ); ?>><?php esc_html_e( 'Boxed / contained', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- DESIGN                                                        -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="style" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Design settings', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field-row">
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Background color', 'fishotel-misc-plugin' ); ?></label>
					<input type="text" name="_announcer_bg_color" value="<?php echo esc_attr( $meta['_announcer_bg_color'] ); ?>" class="ancr-color-picker">
				</div>
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Text color', 'fishotel-misc-plugin' ); ?></label>
					<input type="text" name="_announcer_text_color" value="<?php echo esc_attr( $meta['_announcer_text_color'] ); ?>" class="ancr-color-picker">
				</div>
			</div>

			<div class="ancr-field-row">
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Font size (px)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" name="_announcer_font_size" value="<?php echo esc_attr( $meta['_announcer_font_size'] ); ?>" min="10" max="40" step="1">
				</div>
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Padding (px)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" name="_announcer_padding" value="<?php echo esc_attr( $meta['_announcer_padding'] ); ?>" min="0" max="60" step="1">
				</div>
			</div>

			<hr class="ancr-divider">

			<div class="ancr-field-row">
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Show animation', 'fishotel-misc-plugin' ); ?></label>
					<select name="_announcer_show_animation">
						<option value="none" <?php selected( $meta['_announcer_show_animation'], 'none' ); ?>><?php esc_html_e( 'None', 'fishotel-misc-plugin' ); ?></option>
						<option value="slide" <?php selected( $meta['_announcer_show_animation'], 'slide' ); ?>><?php esc_html_e( 'Slide', 'fishotel-misc-plugin' ); ?></option>
						<option value="fade" <?php selected( $meta['_announcer_show_animation'], 'fade' ); ?>><?php esc_html_e( 'Fade', 'fishotel-misc-plugin' ); ?></option>
						<option value="bounce" <?php selected( $meta['_announcer_show_animation'], 'bounce' ); ?>><?php esc_html_e( 'Bounce', 'fishotel-misc-plugin' ); ?></option>
						<option value="zoom" <?php selected( $meta['_announcer_show_animation'], 'zoom' ); ?>><?php esc_html_e( 'Zoom', 'fishotel-misc-plugin' ); ?></option>
					</select>
				</div>
				<div class="ancr-field">
					<label class="ancr-label"><?php esc_html_e( 'Close animation', 'fishotel-misc-plugin' ); ?></label>
					<select name="_announcer_close_animation">
						<option value="none" <?php selected( $meta['_announcer_close_animation'], 'none' ); ?>><?php esc_html_e( 'None', 'fishotel-misc-plugin' ); ?></option>
						<option value="slide" <?php selected( $meta['_announcer_close_animation'], 'slide' ); ?>><?php esc_html_e( 'Slide', 'fishotel-misc-plugin' ); ?></option>
						<option value="fade" <?php selected( $meta['_announcer_close_animation'], 'fade' ); ?>><?php esc_html_e( 'Fade', 'fishotel-misc-plugin' ); ?></option>
						<option value="bounce" <?php selected( $meta['_announcer_close_animation'], 'bounce' ); ?>><?php esc_html_e( 'Bounce', 'fishotel-misc-plugin' ); ?></option>
						<option value="zoom" <?php selected( $meta['_announcer_close_animation'], 'zoom' ); ?>><?php esc_html_e( 'Zoom', 'fishotel-misc-plugin' ); ?></option>
					</select>
				</div>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- CLOSE                                                         -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="close" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Close settings', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_close_enabled" value="1" <?php checked( $meta['_announcer_close_enabled'], '1' ); ?>>
					<?php esc_html_e( 'Show close button', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Remember close for (days)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" name="_announcer_cookie_days" value="<?php echo esc_attr( $meta['_announcer_cookie_days'] ); ?>" min="0" step="1">
				<span class="ancr-hint"><?php esc_html_e( '0 = session only (reappears on next visit)', 'fishotel-misc-plugin' ); ?></span>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- LOCATION RULES                                                -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="location" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Location rules', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Show on', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_location_type" id="ancr-location-type">
					<option value="all" <?php selected( $meta['_announcer_location_type'], 'all' ); ?>><?php esc_html_e( 'All pages', 'fishotel-misc-plugin' ); ?></option>
					<option value="include" <?php selected( $meta['_announcer_location_type'], 'include' ); ?>><?php esc_html_e( 'Only these pages', 'fishotel-misc-plugin' ); ?></option>
					<option value="exclude" <?php selected( $meta['_announcer_location_type'], 'exclude' ); ?>><?php esc_html_e( 'All pages except', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

			<div id="ancr-location-rules-wrap">
				<div id="ancr-location-rules-list">
					<?php
					$location_rules = is_array( $meta['_announcer_location_rules'] ) ? $meta['_announcer_location_rules'] : array();
					if ( ! empty( $location_rules ) ) :
						foreach ( $location_rules as $i => $rule ) :
							?>
							<div class="ancr-rule-item">
								<select name="_announcer_location_rules[<?php echo esc_attr( $i ); ?>][type]">
									<option value="front_page" <?php selected( $rule['type'], 'front_page' ); ?>><?php esc_html_e( 'Front page', 'fishotel-misc-plugin' ); ?></option>
									<option value="blog" <?php selected( $rule['type'], 'blog' ); ?>><?php esc_html_e( 'Blog / Posts page', 'fishotel-misc-plugin' ); ?></option>
									<option value="page" <?php selected( $rule['type'], 'page' ); ?>><?php esc_html_e( 'Page (ID/slug)', 'fishotel-misc-plugin' ); ?></option>
									<option value="post" <?php selected( $rule['type'], 'post' ); ?>><?php esc_html_e( 'Post (ID/slug)', 'fishotel-misc-plugin' ); ?></option>
									<option value="category" <?php selected( $rule['type'], 'category' ); ?>><?php esc_html_e( 'Category (slug)', 'fishotel-misc-plugin' ); ?></option>
									<option value="post_type" <?php selected( $rule['type'], 'post_type' ); ?>><?php esc_html_e( 'Post type', 'fishotel-misc-plugin' ); ?></option>
									<option value="archive" <?php selected( $rule['type'], 'archive' ); ?>><?php esc_html_e( 'Archive pages', 'fishotel-misc-plugin' ); ?></option>
									<option value="search" <?php selected( $rule['type'], 'search' ); ?>><?php esc_html_e( 'Search results', 'fishotel-misc-plugin' ); ?></option>
									<option value="404" <?php selected( $rule['type'], '404' ); ?>><?php esc_html_e( '404 page', 'fishotel-misc-plugin' ); ?></option>
									<option value="url_contains" <?php selected( $rule['type'], 'url_contains' ); ?>><?php esc_html_e( 'URL contains', 'fishotel-misc-plugin' ); ?></option>
								</select>
								<input type="text" name="_announcer_location_rules[<?php echo esc_attr( $i ); ?>][value]" value="<?php echo esc_attr( $rule['value'] ); ?>" placeholder="<?php esc_attr_e( 'Value (if applicable)', 'fishotel-misc-plugin' ); ?>">
								<button type="button" class="ancr-rule-remove" title="<?php esc_attr_e( 'Remove', 'fishotel-misc-plugin' ); ?>">&times;</button>
							</div>
							<?php
						endforeach;
					endif;
					?>
				</div>
				<button type="button" id="ancr-add-location-rule" class="button ancr-add-btn"><?php esc_html_e( '+ Add Rule', 'fishotel-misc-plugin' ); ?></button>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- VISITOR CONDITIONS                                            -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="visitor" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Visitor conditions', 'fishotel-misc-plugin' ); ?></h3>
			<p class="ancr-desc"><?php esc_html_e( 'Only show when ALL conditions are met. Leave empty to show to everyone.', 'fishotel-misc-plugin' ); ?></p>

			<div id="ancr-visitor-list">
				<?php
				$visitor_conditions = is_array( $meta['_announcer_visitor_conditions'] ) ? $meta['_announcer_visitor_conditions'] : array();
				if ( ! empty( $visitor_conditions ) ) :
					foreach ( $visitor_conditions as $i => $cond ) :
						?>
						<div class="ancr-rule-item">
							<select name="_announcer_visitor_conditions[<?php echo esc_attr( $i ); ?>][type]">
								<option value="device" <?php selected( $cond['type'], 'device' ); ?>><?php esc_html_e( 'Device type', 'fishotel-misc-plugin' ); ?></option>
								<option value="browser" <?php selected( $cond['type'], 'browser' ); ?>><?php esc_html_e( 'Browser', 'fishotel-misc-plugin' ); ?></option>
								<option value="os" <?php selected( $cond['type'], 'os' ); ?>><?php esc_html_e( 'Operating system', 'fishotel-misc-plugin' ); ?></option>
								<option value="logged_in" <?php selected( $cond['type'], 'logged_in' ); ?>><?php esc_html_e( 'Logged in', 'fishotel-misc-plugin' ); ?></option>
								<option value="user_role" <?php selected( $cond['type'], 'user_role' ); ?>><?php esc_html_e( 'User role', 'fishotel-misc-plugin' ); ?></option>
								<option value="referrer" <?php selected( $cond['type'], 'referrer' ); ?>><?php esc_html_e( 'Referrer contains', 'fishotel-misc-plugin' ); ?></option>
								<option value="language" <?php selected( $cond['type'], 'language' ); ?>><?php esc_html_e( 'Browser language', 'fishotel-misc-plugin' ); ?></option>
							</select>
							<select name="_announcer_visitor_conditions[<?php echo esc_attr( $i ); ?>][operator]">
								<option value="is" <?php selected( $cond['operator'], 'is' ); ?>><?php esc_html_e( 'is', 'fishotel-misc-plugin' ); ?></option>
								<option value="is_not" <?php selected( $cond['operator'], 'is_not' ); ?>><?php esc_html_e( 'is not', 'fishotel-misc-plugin' ); ?></option>
							</select>
							<input type="text" name="_announcer_visitor_conditions[<?php echo esc_attr( $i ); ?>][value]" value="<?php echo esc_attr( $cond['value'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. mobile, chrome, yes', 'fishotel-misc-plugin' ); ?>">
							<button type="button" class="ancr-rule-remove" title="<?php esc_attr_e( 'Remove', 'fishotel-misc-plugin' ); ?>">&times;</button>
						</div>
						<?php
					endforeach;
				endif;
				?>
			</div>
			<button type="button" id="ancr-add-visitor-condition" class="button ancr-add-btn"><?php esc_html_e( '+ Add Condition', 'fishotel-misc-plugin' ); ?></button>

		</div>

		<!-- ============================================================ -->
		<!-- MULTIPLE MESSAGES                                             -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="multi" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Multiple messages', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_multi_enabled" value="1" <?php checked( $meta['_announcer_multi_enabled'], '1' ); ?>>
					<?php esc_html_e( 'Enable multiple messages', 'fishotel-misc-plugin' ); ?>
				</label>
				<span class="ancr-hint"><?php esc_html_e( 'Separate messages in the editor with: <!--message-->', 'fishotel-misc-plugin' ); ?></span>
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Display type', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_multi_type">
					<option value="ticker" <?php selected( $meta['_announcer_multi_type'], 'ticker' ); ?>><?php esc_html_e( 'Ticker / Slider (rotate)', 'fishotel-misc-plugin' ); ?></option>
					<option value="random" <?php selected( $meta['_announcer_multi_type'], 'random' ); ?>><?php esc_html_e( 'Random (one per page load)', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_multi_autoplay" value="1" <?php checked( $meta['_announcer_multi_autoplay'], '1' ); ?>>
					<?php esc_html_e( 'Auto-play (rotate automatically)', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Speed (seconds between messages)', 'fishotel-misc-plugin' ); ?></label>
				<input type="number" name="_announcer_multi_speed" value="<?php echo esc_attr( $meta['_announcer_multi_speed'] ); ?>" min="1" max="60" step="1">
			</div>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_ticker_scroll" value="1" <?php checked( $meta['_announcer_ticker_scroll'], '1' ); ?>>
					<?php esc_html_e( 'Horizontal scrolling ticker (marquee-style)', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

		</div>

		<!-- ============================================================ -->
		<!-- COUNTDOWN TIMER                                               -->
		<!-- ============================================================ -->
		<div class="ancr-panel" data-panel="countdown" style="display:none;">

			<h3 class="ancr-panel-title"><?php esc_html_e( 'Countdown timer', 'fishotel-misc-plugin' ); ?></h3>

			<div class="ancr-field">
				<label class="ancr-label">
					<input type="checkbox" name="_announcer_countdown_enabled" value="1" <?php checked( $meta['_announcer_countdown_enabled'], '1' ); ?>>
					<?php esc_html_e( 'Enable countdown timer', 'fishotel-misc-plugin' ); ?>
				</label>
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Countdown to date/time', 'fishotel-misc-plugin' ); ?></label>
				<input type="datetime-local" name="_announcer_countdown_date" value="<?php echo esc_attr( $meta['_announcer_countdown_date'] ); ?>">
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'Label (shown before timer)', 'fishotel-misc-plugin' ); ?></label>
				<input type="text" name="_announcer_countdown_label" value="<?php echo esc_attr( $meta['_announcer_countdown_label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Sale ends in:', 'fishotel-misc-plugin' ); ?>">
			</div>

			<div class="ancr-field">
				<label class="ancr-label"><?php esc_html_e( 'When countdown ends', 'fishotel-misc-plugin' ); ?></label>
				<select name="_announcer_countdown_complete">
					<option value="hide" <?php selected( $meta['_announcer_countdown_complete'], 'hide' ); ?>><?php esc_html_e( 'Hide the announcement', 'fishotel-misc-plugin' ); ?></option>
					<option value="keep" <?php selected( $meta['_announcer_countdown_complete'], 'keep' ); ?>><?php esc_html_e( 'Keep showing (hide timer)', 'fishotel-misc-plugin' ); ?></option>
					<option value="zeros" <?php selected( $meta['_announcer_countdown_complete'], 'zeros' ); ?>><?php esc_html_e( 'Show 00:00:00', 'fishotel-misc-plugin' ); ?></option>
				</select>
			</div>

		</div>

	</div><!-- .ancr-panel-area -->

</div><!-- .ancr-meta-wrap -->

<!-- Hidden fields for General tab (enabled is now in Publish box via column toggle) -->
<input type="hidden" name="_announcer_enabled" value="<?php echo esc_attr( $meta['_announcer_enabled'] ); ?>">
