<?php
/**
 * Coming Soon product-edit metabox view.
 *
 * @package FisHotel\Misc\Sections\Coming_Soon
 *
 * @var int    $timestamp      Stored UNIX timestamp (0 if none).
 * @var string $datetime_local Pre-formatted datetime-local input value.
 * @var string $tz_label       Timezone identifier (e.g. America/Chicago).
 * @var string $status         'none' | 'future' | 'past'.
 * @var string $released_label Formatted "released" date for past status.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Coming_Soon\Admin;
?>

<div class="fh-cs-mb">

	<div class="fh-cs-mb__field">
		<label class="fh-cs-mb__label" for="fh-cs-datetime">
			<?php esc_html_e( 'Release datetime', 'fishotel-misc-plugin' ); ?>
		</label>
		<input
			type="datetime-local"
			id="fh-cs-datetime"
			name="<?php echo esc_attr( Admin::FIELD_NAME ); ?>"
			value="<?php echo esc_attr( $datetime_local ); ?>"
			class="fh-cs-mb__input"
			step="1"
		>
		<span class="fh-cs-mb__hint">
			<?php
			printf(
				/* translators: %s: site timezone identifier */
				esc_html__( 'Interpreted in site timezone: %s', 'fishotel-misc-plugin' ),
				'<code>' . esc_html( $tz_label ) . '</code>'
			);
			?>
		</span>
	</div>

	<div class="fh-cs-mb__status fh-cs-mb__status--<?php echo esc_attr( $status ); ?>"
		data-release-ts="<?php echo esc_attr( (int) $timestamp ); ?>"
		data-status="<?php echo esc_attr( $status ); ?>">

		<?php if ( 'none' === $status ) : ?>
			<span class="fh-cs-mb__status-label">
				<?php esc_html_e( 'Not coming soon', 'fishotel-misc-plugin' ); ?>
			</span>
		<?php elseif ( 'future' === $status ) : ?>
			<span class="fh-cs-mb__status-label">
				<span class="fh-cs-mb__status-prefix"><?php esc_html_e( 'Releases in', 'fishotel-misc-plugin' ); ?></span>
				<span class="fh-cs-mb__status-countdown">&hellip;</span>
			</span>
		<?php else : ?>
			<span class="fh-cs-mb__status-label">
				<?php
				printf(
					/* translators: %s: formatted date and time the product was released */
					esc_html__( 'Released %s', 'fishotel-misc-plugin' ),
					'<strong>' . esc_html( $released_label ) . '</strong>'
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<div class="fh-cs-mb__buttons">
		<button type="button" class="button fh-cs-mb__btn-release-now">
			<?php esc_html_e( 'Release Now', 'fishotel-misc-plugin' ); ?>
		</button>
		<button type="button" class="button fh-cs-mb__btn-clear">
			<?php esc_html_e( 'Clear', 'fishotel-misc-plugin' ); ?>
		</button>
	</div>

	<p class="fh-cs-mb__help">
		<?php esc_html_e( 'While in Coming Soon mode, the product shows a countdown and "Notify Me" button instead of add-to-cart. The product is server-side blocked from purchase until the release datetime passes.', 'fishotel-misc-plugin' ); ?>
	</p>

</div>
