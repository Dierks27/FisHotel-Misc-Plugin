<?php
/**
 * Performance settings view.
 *
 * @package FisHotel\Misc\Sections\Performance
 * @var array $settings Passed from Settings::render_page().
 */

defined( 'ABSPATH' ) || exit;

$features = array(
	'script_cleanup'       => array(
		'label' => __( 'Script & Style Cleanup', 'fishotel-misc-plugin' ),
		'desc'  => __( 'Dequeue Contact Form 7 CSS and JS on pages that do not contain the shortcode.', 'fishotel-misc-plugin' ),
	),
	'cache_headers'        => array(
		'label' => __( 'Browser Cache-Control Headers', 'fishotel-misc-plugin' ),
		'desc'  => __( 'Add Cache-Control: public, max-age=3600 for front-end pages. WooCommerce cart, checkout, and account pages are excluded.', 'fishotel-misc-plugin' ),
	),
	'gzip_compression'     => array(
		'label' => __( 'Gzip Compression', 'fishotel-misc-plugin' ),
		'desc'  => __( 'Enable PHP output compression when the server is not already handling it.', 'fishotel-misc-plugin' ),
	),
	'remove_query_strings' => array(
		'label' => __( 'Remove Query Strings', 'fishotel-misc-plugin' ),
		'desc'  => __( 'Strip ?ver= query strings from CSS and JS URLs to improve proxy and CDN caching.', 'fishotel-misc-plugin' ),
	),
	'combine_assets'       => array(
		'label' => __( 'CSS & JS File Combiner', 'fishotel-misc-plugin' ),
		'desc'  => __( 'Combine local CSS and non-deferred JS into single cached files to reduce HTTP requests. External assets (CDN, Google, PayPal, OneSignal) are skipped automatically.', 'fishotel-misc-plugin' ),
	),
);
?>

<div class="wrap fishotel-misc-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Performance Optimization', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--success">
			<?php esc_html_e( 'Settings saved.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['error'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php echo esc_html( rawurldecode( $_GET['error'] ) ); ?>
		</div>
	<?php endif; ?>

	<form id="fishotel-perf-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" onsubmit="return false;">
		<?php wp_nonce_field( 'fishotel_perf_settings' ); ?>
		<input type="hidden" name="action" value="fishotel_perf_save">

		<div class="fishotel-misc-card" style="max-width: 720px;">

			<?php foreach ( $features as $key => $feature ) : ?>
				<div class="fishotel-misc-card-footer" style="border-top: <?php echo 'script_cleanup' === $key ? 'none' : ''; ?>;">
					<div>
						<strong style="color: var(--fh-text-primary); font-size: 14px;">
							<?php echo esc_html( $feature['label'] ); ?>
						</strong>
						<p class="fishotel-misc-card-desc" style="margin-top: 4px;">
							<?php echo esc_html( $feature['desc'] ); ?>
						</p>
					</div>
					<label class="fishotel-misc-toggle">
						<input type="checkbox"
							name="<?php echo esc_attr( $key ); ?>"
							value="1"
							<?php checked( ! empty( $settings[ $key ] ) ); ?>>
						<span class="slider"></span>
					</label>
				</div>
			<?php endforeach; ?>

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
	$('#fishotel-perf-form').on('submit', function (e) {
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
