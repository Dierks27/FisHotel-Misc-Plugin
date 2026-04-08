<?php
/**
 * Dashboard view — lists all registered sections.
 *
 * @package FisHotel\Misc
 * @var array $sections Passed from Plugin::render_dashboard().
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap fishotel-misc-wrap">

	<!-- Header -->
	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'FisHotel Misc Plugin', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php if ( empty( $sections ) ) : ?>

		<!-- Empty state -->
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-admin-tools"></span>
			<p><?php esc_html_e( 'No sections registered yet.', 'fishotel-misc-plugin' ); ?></p>
		</div>

	<?php else : ?>

		<?php
		$any_enabled = false;
		foreach ( $sections as $s ) {
			if ( $s['enabled'] ) {
				$any_enabled = true;
				break;
			}
		}
		if ( ! $any_enabled ) :
		?>
			<div class="fishotel-misc-notice">
				<?php esc_html_e( 'No sections enabled yet. Use the toggles below to activate a section.', 'fishotel-misc-plugin' ); ?>
			</div>
		<?php endif; ?>

		<!-- Section Grid -->
		<div class="fishotel-misc-grid">
			<?php foreach ( $sections as $slug => $section ) : ?>
				<div class="fishotel-misc-card">

					<div class="fishotel-misc-card-header">
						<div class="fishotel-misc-card-icon">
							<span class="dashicons <?php echo esc_attr( $section['icon'] ); ?>"></span>
						</div>
						<h2 class="fishotel-misc-card-title">
							<?php echo esc_html( $section['name'] ); ?>
						</h2>
					</div>

					<p class="fishotel-misc-card-desc">
						<?php echo esc_html( $section['description'] ); ?>
					</p>

					<div class="fishotel-misc-card-footer">
						<span class="fishotel-misc-badge <?php echo $section['enabled'] ? 'fishotel-misc-badge--active' : 'fishotel-misc-badge--inactive'; ?>">
							<?php echo $section['enabled']
								? esc_html__( 'Active', 'fishotel-misc-plugin' )
								: esc_html__( 'Inactive', 'fishotel-misc-plugin' ); ?>
						</span>

						<label class="fishotel-misc-toggle">
							<input type="checkbox"
								data-section="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $section['enabled'] ); ?>>
							<span class="slider"></span>
						</label>
					</div>

				</div>
			<?php endforeach; ?>
		</div>

	<?php endif; ?>

</div>

<script>
	window.fishotelMiscL10n = {
		active:   '<?php echo esc_js( __( 'Active', 'fishotel-misc-plugin' ) ); ?>',
		inactive: '<?php echo esc_js( __( 'Inactive', 'fishotel-misc-plugin' ) ); ?>',
		error:    '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'fishotel-misc-plugin' ) ); ?>'
	};
</script>
