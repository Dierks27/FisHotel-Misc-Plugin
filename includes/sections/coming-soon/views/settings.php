<?php
/**
 * Coming Soon settings page view.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 *
 * @var array $settings Active settings (passed from Settings::render_page()).
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap fishotel-misc-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Coming Soon', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<form id="fishotel-coming-soon-form"
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		onsubmit="return false;">

		<?php wp_nonce_field( 'fishotel_coming_soon_settings' ); ?>
		<input type="hidden" name="action" value="fishotel_coming_soon_save">

		<div class="fishotel-misc-card" style="max-width: 720px;">

			<div class="fishotel-misc-card-footer" style="border-top: none; flex-direction: column; align-items: stretch; gap: 6px;">
				<strong style="color: var(--fh-text-primary); font-size: 14px;">
					<?php esc_html_e( 'Notify Me URL', 'fishotel-misc-plugin' ); ?>
				</strong>
				<p class="fishotel-misc-card-desc" style="margin: 0;">
					<?php esc_html_e( 'Where the "Notify Me" button links — typically a special-order or contact page.', 'fishotel-misc-plugin' ); ?>
				</p>
				<input type="text"
					name="notify_me_url"
					class="fh-cs-input"
					value="<?php echo esc_attr( $settings['notify_me_url'] ); ?>">
			</div>

			<div class="fishotel-misc-card-footer" style="flex-direction: column; align-items: stretch; gap: 6px;">
				<strong style="color: var(--fh-text-primary); font-size: 14px;">
					<?php esc_html_e( 'Ribbon text', 'fishotel-misc-plugin' ); ?>
				</strong>
				<p class="fishotel-misc-card-desc" style="margin: 0;">
					<?php esc_html_e( 'Text shown on the ribbon overlay on the product image.', 'fishotel-misc-plugin' ); ?>
				</p>
				<input type="text"
					name="ribbon_text"
					class="fh-cs-input"
					value="<?php echo esc_attr( $settings['ribbon_text'] ); ?>">
			</div>

			<div class="fishotel-misc-card-footer">
				<div>
					<strong style="color: var(--fh-text-primary); font-size: 14px;">
						<?php esc_html_e( 'Show countdown on shop loop', 'fishotel-misc-plugin' ); ?>
					</strong>
					<p class="fishotel-misc-card-desc" style="margin-top: 4px;">
						<?php esc_html_e( 'Display a live countdown on each coming-soon product card in shop, category, and search archives.', 'fishotel-misc-plugin' ); ?>
					</p>
				</div>
				<label class="fishotel-misc-toggle">
					<input type="checkbox" name="show_countdown_loop" value="1" <?php checked( ! empty( $settings['show_countdown_loop'] ) ); ?>>
					<span class="slider"></span>
				</label>
			</div>

			<div class="fishotel-misc-card-footer">
				<div>
					<strong style="color: var(--fh-text-primary); font-size: 14px;">
						<?php esc_html_e( 'Show countdown on single product page', 'fishotel-misc-plugin' ); ?>
					</strong>
					<p class="fishotel-misc-card-desc" style="margin-top: 4px;">
						<?php esc_html_e( 'Display a live countdown above the price on the single product page.', 'fishotel-misc-plugin' ); ?>
					</p>
				</div>
				<label class="fishotel-misc-toggle">
					<input type="checkbox" name="show_countdown_single" value="1" <?php checked( ! empty( $settings['show_countdown_single'] ) ); ?>>
					<span class="slider"></span>
				</label>
			</div>

			<div class="fishotel-misc-card-footer">
				<div>
					<strong style="color: var(--fh-text-primary); font-size: 14px;">
						<?php esc_html_e( 'Auto-reload on countdown end', 'fishotel-misc-plugin' ); ?>
					</strong>
					<p class="fishotel-misc-card-desc" style="margin-top: 4px;">
						<?php esc_html_e( 'When a countdown reaches zero, reload the page so the product becomes purchasable.', 'fishotel-misc-plugin' ); ?>
					</p>
				</div>
				<label class="fishotel-misc-toggle">
					<input type="checkbox" name="auto_reload" value="1" <?php checked( ! empty( $settings['auto_reload'] ) ); ?>>
					<span class="slider"></span>
				</label>
			</div>

			<div class="fishotel-misc-card-footer" style="flex-direction: column; align-items: stretch; gap: 6px;">
				<strong style="color: var(--fh-text-primary); font-size: 14px;">
					<?php esc_html_e( 'Reload jitter (max seconds)', 'fishotel-misc-plugin' ); ?>
				</strong>
				<p class="fishotel-misc-card-desc" style="margin: 0;">
					<?php esc_html_e( 'Random delay added before reload, to avoid a thundering herd if many users hit the page simultaneously.', 'fishotel-misc-plugin' ); ?>
				</p>
				<input type="number"
					name="reload_jitter"
					class="fh-cs-input fh-cs-input--number"
					min="0"
					max="60"
					step="1"
					value="<?php echo esc_attr( (int) $settings['reload_jitter'] ); ?>">
			</div>

			<div class="fishotel-misc-card-footer">
				<div>
					<strong style="color: var(--fh-text-primary); font-size: 14px;">
						<?php esc_html_e( 'Sort coming-soon products first', 'fishotel-misc-plugin' ); ?>
					</strong>
					<p class="fishotel-misc-card-desc" style="margin-top: 4px;">
						<?php esc_html_e( 'On shop and category archives, list coming-soon products before live products. Preserves the user-selected sort within each group.', 'fishotel-misc-plugin' ); ?>
					</p>
				</div>
				<label class="fishotel-misc-toggle">
					<input type="checkbox" name="sort_first" value="1" <?php checked( ! empty( $settings['sort_first'] ) ); ?>>
					<span class="slider"></span>
				</label>
			</div>

		</div>

		<p style="margin-top: 20px;">
			<button type="submit" class="button button-primary" style="background: var(--fh-accent); border-color: var(--fh-accent);">
				<?php esc_html_e( 'Save Settings', 'fishotel-misc-plugin' ); ?>
			</button>
		</p>

	</form>

</div>

<script>
jQuery(function ($) {
	$('#fishotel-coming-soon-form').on('submit', function (e) {
		e.preventDefault();

		var $form = $(this);
		var $btn  = $form.find('button[type="submit"]');
		var $wrap = $form.closest('.fishotel-misc-wrap');

		$btn.prop('disabled', true);
		$wrap.find('.fishotel-misc-notice').remove();

		$.post(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, $form.serialize())
			.done(function (res) {
				var ok  = res && res.success;
				var msg = (res && res.data && res.data.message) ? res.data.message
						: (ok ? <?php echo wp_json_encode( __( 'Settings saved.', 'fishotel-misc-plugin' ) ); ?>
						: <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ) ); ?>);
				var cls = ok ? 'fishotel-misc-notice--success' : 'fishotel-misc-notice--error';

				$('<div class="fishotel-misc-notice ' + cls + '">' + msg + '</div>')
					.insertAfter($wrap.find('.fishotel-misc-header'))
					.delay(3000).fadeOut(300, function () { $(this).remove(); });
			})
			.fail(function () {
				$('<div class="fishotel-misc-notice fishotel-misc-notice--error">' +
					<?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ) ); ?> +
					'</div>')
					.insertAfter($wrap.find('.fishotel-misc-header'))
					.delay(3000).fadeOut(300, function () { $(this).remove(); });
			})
			.always(function () {
				$btn.prop('disabled', false);
			});

		return false;
	});
});
</script>
