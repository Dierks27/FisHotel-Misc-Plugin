<?php
/**
 * Settings → Sizes: the "Tidy up the sizes" card (Admin_Sizes_Tidy::render()).
 * Only an owner sees it, and only while WooCommerce is active.
 *
 * @var string $taxonomy The size attribute's taxonomy ('' = the shop has none).
 * @var array  $status   Admin_Sizes_Tidy::status().
 * @var string $nonce    The tool's nonce.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Admin_Sizes_Tidy;

?>
<div id="fh-sizes-tidy" class="fishotel-misc-card fh-cr-card fh-cr-card--wide fh-tidy">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-filter"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Tidy up the sizes', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Brings the shop\'s long list of sizes down to a short one. You say which sizes the shop should have; every other size is turned into one of them, kept, or taken off — you see exactly what would change before anything does, and the last tidy-up can always be undone. Nothing here ever runs by itself.', 'fishotel-misc-plugin' ); ?></p>

	<?php if ( '' === $taxonomy ) : ?>
		<p class="fh-cr-muted"><?php esc_html_e( 'The shop has no product attribute with "size" in its name, so there is nothing to tidy.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<p class="fh-cr-sub">
			<?php
			/* translators: %s: the attribute's taxonomy */
			echo esc_html( sprintf( __( 'It works on the product attribute in use above (%s) — on every product that uses it, fish or not — and on sizes typed on a fish product. Other attributes are never touched.', 'fishotel-misc-plugin' ), $taxonomy ) );
			?>
		</p>
		<form class="fh-tidy__form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" onsubmit="return false;">
			<h3 class="fh-cr-h3"><?php esc_html_e( '1. The sizes the shop should have', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-cr-sub"><?php esc_html_e( 'One size per line, in the order they should be offered. Inch sizes first, then words — or however you like. A size that does not exist yet is created.', 'fishotel-misc-plugin' ); ?></p>
			<p>
				<label class="screen-reader-text" for="fh-tidy-short"><?php esc_html_e( 'The short list, one size per line', 'fishotel-misc-plugin' ); ?></label>
				<textarea id="fh-tidy-short" class="fh-cr-input fh-tidy__short" name="short" rows="<?php echo esc_attr( (string) max( 8, count( $status['short'] ) + 1 ) ); ?>" data-suggested="<?php echo esc_attr( implode( "\n", Sizes_Tidy::DEFAULT_LIST ) ); ?>"><?php echo esc_textarea( $status['short_text'] ); ?></textarea>
			</p>
			<p class="fh-cr-actions-row">
				<button type="button" class="button button-primary fh-tidy__preview"><?php esc_html_e( 'Show what would change', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button-link fh-tidy__suggested"><?php esc_html_e( 'Back to the suggested list', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-cr-muted fh-tidy__hint"><?php esc_html_e( 'Showing changes nothing.', 'fishotel-misc-plugin' ); ?></span>
			</p>
			<p class="fh-tidy__message" role="status" aria-live="polite" hidden></p>
			<div class="fh-tidy__result"></div>
		</form>
	<?php endif; ?>

	<div class="fh-tidy__status">
		<?php echo Admin_Sizes_Tidy::status_html( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the view. ?>
	</div>
</div>
