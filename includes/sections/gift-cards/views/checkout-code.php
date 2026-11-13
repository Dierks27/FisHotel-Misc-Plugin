<?php
/**
 * Checkout: "Have a gift card?" under Payment Method. A logged-in
 * customer's code goes into their account; a guest's is used for this
 * order only (when Settings allow guests). The field has no name: it is
 * never sent with the order, only by its Apply button.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="fh-gcard-code">
	<label for="fh_gcard_code"><?php esc_html_e( 'Have a gift card?', 'fishotel-misc-plugin' ); ?></label>
	<span class="fh-gcard-code__row">
		<input type="text" id="fh_gcard_code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX">
		<button type="button" class="button fh-gcard-code__apply"><?php esc_html_e( 'Apply', 'fishotel-misc-plugin' ); ?></button>
	</span>
	<span class="fh-gcard-code__message" role="status" aria-live="polite"></span>
</div>
