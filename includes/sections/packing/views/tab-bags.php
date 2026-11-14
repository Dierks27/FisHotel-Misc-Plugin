<?php
/**
 * Packing page — Bags: the standard bags.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var array $settings Settings::get().
 * @var array $usage    Bag id → how many products / variations name it.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Settings;

$fh_bags  = $settings['bags'];
$fh_spare = $fh_bags ? 1 : 4;
$fh_next  = count( $fh_bags ) + $fh_spare;
?>
<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-archive"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Bag sizes', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'The bags you pack fish in — nothing is filled in for you. A name is all a bag needs; width and length are optional. The sort number orders the list everywhere (smallest first reads best). A bag keeps its # for good, so renaming one changes nothing on the products that use it.', 'fishotel-misc-plugin' ); ?>
	</p>

	<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-reload="1">
		<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
		<input type="hidden" name="action" value="fishotel_packing_bags">

		<table class="fh-pack-table" data-next="<?php echo esc_attr( $fh_next ); ?>">
			<thead>
				<tr>
					<th class="fh-pack-col-id">#</th>
					<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Width (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Length (in)', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Sort', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Set on', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fh_bags as $fh_i => $fh_bag ) : ?>
					<?php $fh_on = (int) ( $usage[ (int) $fh_bag['id'] ] ?? 0 ); ?>
					<tr>
						<td class="fh-pack-col-id">
							<?php echo esc_html( (int) $fh_bag['id'] ); ?>
							<input type="hidden" name="bags[<?php echo esc_attr( $fh_i ); ?>][id]" value="<?php echo esc_attr( (int) $fh_bag['id'] ); ?>">
						</td>
						<td><input type="text" class="fh-pack-input" name="bags[<?php echo esc_attr( $fh_i ); ?>][name]" value="<?php echo esc_attr( $fh_bag['name'] ); ?>" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][width]" value="<?php echo esc_attr( $fh_bag['width'] ); ?>" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][length]" value="<?php echo esc_attr( $fh_bag['length'] ); ?>" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][sort]" value="<?php echo esc_attr( (int) $fh_bag['sort'] ); ?>" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">
							<?php
							/* translators: %d: number of products and variations */
							echo esc_html( sprintf( _n( '%d product / variation', '%d products / variations', $fh_on, 'fishotel-misc-plugin' ), $fh_on ) );
							?>
						</td>
						<td><label class="fh-pack-check"><input type="checkbox" name="bags[<?php echo esc_attr( $fh_i ); ?>][remove]" value="1"> <span class="screen-reader-text"><?php esc_html_e( 'Remove this bag', 'fishotel-misc-plugin' ); ?></span></label></td>
					</tr>
				<?php endforeach; ?>

				<?php for ( $fh_i = count( $fh_bags ); $fh_i < $fh_next; $fh_i++ ) : ?>
					<tr class="fh-pack-row--new">
						<td class="fh-pack-col-id fh-pack-muted"><?php esc_html_e( 'new', 'fishotel-misc-plugin' ); ?></td>
						<td><input type="text" class="fh-pack-input" name="bags[<?php echo esc_attr( $fh_i ); ?>][name]" value="" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][width]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][length]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
						<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[<?php echo esc_attr( $fh_i ); ?>][sort]" value="" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
						<td class="fh-pack-muted">&mdash;</td>
						<td></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>

		<script type="text/template" class="fh-pack-row-template">
			<tr class="fh-pack-row--new">
				<td class="fh-pack-col-id fh-pack-muted"><?php esc_html_e( 'new', 'fishotel-misc-plugin' ); ?></td>
				<td><input type="text" class="fh-pack-input" name="bags[__i__][name]" value="" maxlength="<?php echo esc_attr( Settings::MAX_NAME ); ?>" aria-label="<?php esc_attr_e( 'Name', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][width]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Width in inches', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][length]" value="" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Length in inches', 'fishotel-misc-plugin' ); ?>"></td>
				<td><input type="number" class="fh-pack-input fh-pack-input--num" name="bags[__i__][sort]" value="" step="1" aria-label="<?php esc_attr_e( 'Sort order', 'fishotel-misc-plugin' ); ?>"></td>
				<td class="fh-pack-muted">&mdash;</td>
				<td></td>
			</tr>
		</script>

		<p class="fh-pack-actions">
			<button type="button" class="button fh-pack-add-row"><?php esc_html_e( 'Add a bag', 'fishotel-misc-plugin' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save bag sizes', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-pack-status" role="status" aria-live="polite"></span>
		</p>
	</form>

	<p class="fishotel-misc-card-desc">
		<?php
		printf(
			/* translators: %s: link to the Sizes tab */
			esc_html__( 'Next: %s — which bag each fish size goes in, so the Bag size does not have to be set on every variation by hand.', 'fishotel-misc-plugin' ),
			'<a href="' . esc_url( Admin::tab_url( 'sizes' ) ) . '">' . esc_html__( 'Sizes → bags', 'fishotel-misc-plugin' ) . '</a>'
		);
		?>
	</p>
</div>
