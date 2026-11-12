<?php
/**
 * The "Identify… / Correct the fish…" panel — a row the page's JS opens
 * under a stay (Stays tab) or under an order line (order page): the real
 * fish (the same search the order editor uses: products, the species
 * list, or a typed name), its size, or "still unknown" with what it looks
 * like. The inputs carry no name, so they are never part of a form that
 * surrounds the table; the JS posts them.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $sizes               The standard sizes (slug, label).
 * @var int     $fh_identify_colspan The columns of the table the row goes into.
 */

defined( 'ABSPATH' ) || exit;
?>
<template id="fh-cr-identify-template">
	<tr class="fh-cr-identify">
		<td colspan="<?php echo esc_attr( (int) $fh_identify_colspan ); ?>">
			<div class="fh-cr-fields__row fh-cr-identify__row">
				<label class="fh-cr-identify__fish">
					<?php esc_html_e( 'The real fish', 'fishotel-misc-plugin' ); ?>
					<span class="fh-cr-suggest-wrap"><input type="text" class="fh-cr-input fh-cr-identify-search" maxlength="120" autocomplete="off"></span>
				</label>
				<label>
					<?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?>
					<select class="fh-cr-input fh-cr-identify-size">
						<option value=""><?php esc_html_e( 'none', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( $sizes as $fh_size ) : ?>
							<option value="<?php echo esc_attr( $fh_size['slug'] ); ?>"><?php echo esc_html( $fh_size['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="fh-cr-identify__unknown"><input type="checkbox" class="fh-cr-identify-unknown"> <?php esc_html_e( 'Still unknown (??)', 'fishotel-misc-plugin' ); ?></label>
				<button type="button" class="button button-primary fh-cr-identify-save"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button fh-cr-identify-cancel"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></button>
			</div>
			<p class="fh-cr-sub fh-cr-identify__hint"><?php esc_html_e( 'One correction: the stay, its supplier order line (which keeps what the label said) and the remembered box share follow. While a group that was split still has another fish in a sister stay, only this stay changes and the order line gets a note.', 'fishotel-misc-plugin' ); ?></p>
		</td>
	</tr>
</template>
