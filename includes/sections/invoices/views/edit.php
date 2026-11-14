<?php
/**
 * The picker — New / Edit: the customer, one search box that finds fish
 * in the tanks, in the shop, in the species list or takes what was
 * typed, the lines with their quantities and prices, credits and notes,
 * the total or a flat total, the shipping, a live preview of the
 * description as it will be written — and the pre-check that comes
 * before every Publish.
 *
 * The page script (js/invoices-admin.js) renders the lines and does the
 * sums as they are typed; the server does them again on every save.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      Admin::edit_data(): state, classes, effective, product, tanks_on, tanks_why.
 * @var array  $settings  Settings.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Settings;

$fh_state  = $data['state'];
$fh_update = 'update' === $fh_state['mode'];
$fh_new    = 0 === (int) $fh_state['id'];
$fh_known  = false;
foreach ( $data['classes'] as $fh_class ) {
	if ( $fh_class['slug'] === $fh_state['shipping_slug'] ) {
		$fh_known = true;
	}
}
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap">

	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<?php if ( $fh_update ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--warning fh-inv-editing">
			<strong><?php esc_html_e( 'You are editing a PUBLISHED invoice that is not paid yet.', 'fishotel-misc-plugin' ); ?></strong>
			<?php esc_html_e( 'The customer may already have its link and may be looking at it — or paying it — right now. The title and the link stay as they are. It is checked again when you save: if it was paid meanwhile, nothing is changed.', 'fishotel-misc-plugin' ); ?>
			<?php esc_html_e( 'Extra fish normally go on a NEW invoice:', 'fishotel-misc-plugin' ); ?>
			<a href="<?php echo esc_url( Admin::page_url( 'new', array( 'from' => $fh_state['id'] ) ) ); ?>"><?php esc_html_e( 'Add fish for this customer instead', 'fishotel-misc-plugin' ); ?></a>
		</div>
	<?php endif; ?>

	<div id="fh-inv-editor" class="fh-inv-editor" data-mode="<?php echo esc_attr( $fh_state['mode'] ); ?>">

		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></h3>
			<div class="fh-inv-fields">
				<label class="fh-inv-field fh-inv-suggest-wrap">
					<span><?php esc_html_e( 'Handle (the forum name)', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-inv-input" id="fh-inv-handle" maxlength="120" autocomplete="off" value="<?php echo esc_attr( $fh_state['handle'] ); ?>" <?php echo $fh_update ? 'readonly' : ''; ?>>
					<?php if ( $fh_update ) : ?>
						<small><?php esc_html_e( 'The handle is in the title, and the title is the link: it cannot be changed here.', 'fishotel-misc-plugin' ); ?></small>
					<?php endif; ?>
				</label>
				<label class="fh-inv-field fh-inv-field--small">
					<span><?php esc_html_e( 'Forum user id (optional)', 'fishotel-misc-plugin' ); ?></span>
					<input type="number" class="fh-inv-input" id="fh-inv-forum" min="0" step="1" value="<?php echo esc_attr( $fh_state['forum_user_id'] ); ?>">
					<small><?php esc_html_e( 'For a handle the forum will not tag with @: the list then uses [USER=id].', 'fishotel-misc-plugin' ); ?></small>
				</label>
				<label class="fh-inv-field fh-inv-suggest-wrap">
					<span><?php esc_html_e( 'Site customer (optional)', 'fishotel-misc-plugin' ); ?></span>
					<input type="search" class="fh-inv-input" id="fh-inv-customer-search" autocomplete="off" placeholder="<?php esc_attr_e( 'find by name or e-mail…', 'fishotel-misc-plugin' ); ?>">
					<span class="fh-inv-linked" id="fh-inv-customer-linked" hidden></span>
				</label>
				<label class="fh-inv-field">
					<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-inv-input" id="fh-inv-name" maxlength="190" value="<?php echo esc_attr( $fh_state['customer_name'] ); ?>">
				</label>
				<label class="fh-inv-field">
					<span><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></span>
					<input type="email" class="fh-inv-input" id="fh-inv-email" maxlength="190" value="<?php echo esc_attr( $fh_state['customer_email'] ); ?>">
				</label>
			</div>
			<p class="fh-inv-footnote"><?php esc_html_e( 'The name, the e-mail and the linked customer are what this customer\'s orders are found by in the pre-check. Nothing is ever sent to the customer from here.', 'fishotel-misc-plugin' ); ?></p>
		</div>

		<div class="fishotel-misc-card fh-inv-card">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></h3>

			<?php if ( ! $data['tanks_on'] ) : ?>
				<p class="fh-inv-footnote fh-inv-warn"><?php echo esc_html( $data['tanks_why'] ); ?></p>
			<?php endif; ?>

			<div class="fh-inv-searchbox">
				<label class="screen-reader-text" for="fh-inv-search"><?php esc_html_e( 'Find a fish', 'fishotel-misc-plugin' ); ?></label>
				<input type="search" class="fh-inv-input fh-inv-input--big" id="fh-inv-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Type a fish: in the tanks, in the shop, in the species list — or any name…', 'fishotel-misc-plugin' ); ?>">
				<div class="fh-inv-results" id="fh-inv-results" hidden></div>
			</div>

			<table class="widefat fh-inv-table fh-inv-lines">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-col-qty"><?php esc_html_e( 'Qty', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-col-price"><?php esc_html_e( 'Price each', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-col-tools"></th>
					</tr>
				</thead>
				<tbody id="fh-inv-lines"></tbody>
			</table>

			<div class="fh-inv-buttons">
				<button type="button" class="button" id="fh-inv-add-credit"><?php esc_html_e( '+ Credit', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button" id="fh-inv-add-note"><?php esc_html_e( '+ Note', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-inv-footnote"><?php esc_html_e( 'A lost fish the customer already paid for is a credit here, on the NEW invoice — the paid invoice is never changed.', 'fishotel-misc-plugin' ); ?></span>
			</div>
		</div>

		<div class="fh-inv-grid">
			<div class="fishotel-misc-card fh-inv-card">
				<h3 class="fh-inv-h3"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></h3>
				<dl class="fh-inv-dl fh-inv-sums">
					<dt><?php esc_html_e( 'Sum of the lines', 'fishotel-misc-plugin' ); ?></dt>
					<dd id="fh-inv-subtotal">$0</dd>
					<dt>
						<label><input type="checkbox" id="fh-inv-flat-on" <?php checked( $fh_state['flat_on'] ); ?>> <?php esc_html_e( 'Charge a flat total', 'fishotel-misc-plugin' ); ?></label>
					</dt>
					<dd><input type="text" inputmode="decimal" class="fh-inv-input fh-inv-input--num" id="fh-inv-flat" value="<?php echo esc_attr( '' !== $fh_state['flat_total'] ? number_format( (float) $fh_state['flat_total'], 2, '.', '' ) : '' ); ?>" placeholder="599" <?php echo $fh_state['flat_on'] ? '' : 'disabled'; ?>></dd>
					<dt id="fh-inv-discount-label" hidden></dt>
					<dd id="fh-inv-discount" hidden></dd>
					<dt class="fh-inv-sums__total"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></dt>
					<dd class="fh-inv-sums__total" id="fh-inv-total">$0</dd>
				</dl>
				<p class="fh-inv-footnote"><?php esc_html_e( 'A stated price is the customer\'s price: tick the box, type it, and a Discount line (or an Adjustment line, when it is higher) makes the lines add up to it.', 'fishotel-misc-plugin' ); ?></p>
			</div>

			<div class="fishotel-misc-card fh-inv-card">
				<h3 class="fh-inv-h3"><?php esc_html_e( 'Shipping', 'fishotel-misc-plugin' ); ?></h3>
				<div class="fh-inv-radios">
					<?php foreach ( Settings::SHIPPING_CHOICES as $fh_choice ) : ?>
						<label>
							<input type="radio" name="fh-inv-shipping" value="<?php echo esc_attr( $fh_choice ); ?>" <?php checked( $fh_state['shipping'], $fh_choice ); ?>>
							<?php echo esc_html( Settings::shipping_label( $fh_choice ) ); ?>
							<?php // The shipping class's code name is not shown; only a choice that is not set up yet says so. ?>
							<?php if ( 'other' !== $fh_choice && '' === Settings::shipping_slug( $settings, $fh_choice ) ) : ?>
								<small><?php esc_html_e( 'not set in Settings yet', 'fishotel-misc-plugin' ); ?></small>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
					<select class="fh-inv-input" id="fh-inv-shipping-slug" aria-label="<?php esc_attr_e( 'The other shipping class', 'fishotel-misc-plugin' ); ?>" <?php echo 'other' === $fh_state['shipping'] ? '' : 'disabled'; ?>>
						<option value=""><?php esc_html_e( 'choose a class…', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( $data['classes'] as $fh_class ) : ?>
							<option value="<?php echo esc_attr( $fh_class['slug'] ); ?>" <?php selected( $fh_state['shipping_slug'], $fh_class['slug'] ); ?>><?php echo esc_html( $fh_class['name'] ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' !== $fh_state['shipping_slug'] && ! $fh_known ) : ?>
							<option value="<?php echo esc_attr( $fh_state['shipping_slug'] ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: shipping class slug */ __( '%s (no longer exists)', 'fishotel-misc-plugin' ), $fh_state['shipping_slug'] ) ); ?></option>
						<?php endif; ?>
					</select>
				</div>
				<?php if ( ! $data['classes'] ) : ?>
					<p class="fh-inv-footnote fh-inv-warn"><?php esc_html_e( 'This store has no shipping classes, so an invoice cannot be given one — and cannot be published.', 'fishotel-misc-plugin' ); ?></p>
				<?php endif; ?>

				<h3 class="fh-inv-h3"><?php esc_html_e( 'Notes (internal — the customer never sees them)', 'fishotel-misc-plugin' ); ?></h3>
				<textarea class="fh-inv-input" id="fh-inv-notes" rows="3" maxlength="2000"><?php echo esc_textarea( $fh_state['notes'] ); ?></textarea>
			</div>
		</div>

		<div class="fishotel-misc-card fh-inv-card fh-inv-preview">
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Preview — the product as it will be written', 'fishotel-misc-plugin' ); ?></h3>
			<div class="fh-inv-preview__box">
				<div class="fh-inv-preview__title" id="fh-inv-preview-title"></div>
				<div class="fh-inv-preview__excerpt" id="fh-inv-preview-excerpt"></div>
				<ul class="fh-inv-preview__list" id="fh-inv-preview-list"></ul>
				<div class="fh-inv-preview__price" id="fh-inv-preview-price"></div>
			</div>
			<p class="fh-inv-footnote"><?php esc_html_e( 'The customer opens the link, adds this product to the cart, shops on, and checks out as always. Stock 1, no backorders: it cannot be paid twice.', 'fishotel-misc-plugin' ); ?></p>
		</div>

		<div class="fh-inv-actionbar">
			<?php if ( $fh_update ) : ?>
				<button type="button" class="button button-primary" id="fh-inv-precheck"><?php esc_html_e( 'Pre-check the changes…', 'fishotel-misc-plugin' ); ?></button>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'invoice', array( 'id' => $fh_state['id'] ) ) ); ?>"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></a>
			<?php else : ?>
				<button type="button" class="button" id="fh-inv-save"><?php esc_html_e( 'Save draft', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button button-primary" id="fh-inv-precheck"><?php esc_html_e( 'Pre-check and publish…', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button fh-inv-discard" id="fh-inv-discard" <?php echo $fh_new ? 'hidden' : ''; ?>><?php esc_html_e( 'Withdraw draft', 'fishotel-misc-plugin' ); ?></button>
			<?php endif; ?>
			<span class="fh-inv-status" id="fh-inv-status" aria-live="polite"></span>
		</div>

		<div class="fishotel-misc-card fh-inv-card fh-inv-precheck" id="fh-inv-precheck-panel" hidden>
			<h3 class="fh-inv-h3"><?php esc_html_e( 'Pre-check: read before you touch', 'fishotel-misc-plugin' ); ?></h3>
			<div id="fh-inv-precheck-body"></div>
			<div class="fh-inv-actionbar">
				<button type="button" class="button button-primary button-hero" id="fh-inv-publish" disabled>
					<?php echo $fh_update ? esc_html__( 'Save to the published invoice', 'fishotel-misc-plugin' ) : esc_html__( 'Publish this invoice', 'fishotel-misc-plugin' ); ?>
				</button>
				<span class="fh-inv-status" id="fh-inv-publish-status" aria-live="polite"></span>
			</div>
		</div>

		<div class="fishotel-misc-card fh-inv-card fh-inv-done" id="fh-inv-done" hidden>
			<h3 class="fh-inv-h3" id="fh-inv-done-title"></h3>
			<div class="fh-inv-linkrow">
				<label for="fh-inv-done-link"><?php esc_html_e( 'The link for the customer', 'fishotel-misc-plugin' ); ?></label>
				<input type="text" id="fh-inv-done-link" class="fh-inv-input fh-inv-link" readonly>
				<button type="button" class="button button-primary fh-inv-copy" id="fh-inv-done-copy"><?php esc_html_e( 'Copy', 'fishotel-misc-plugin' ); ?></button>
			</div>
			<ul class="fh-inv-done__warnings" id="fh-inv-done-warnings"></ul>
			<div class="fh-inv-buttons">
				<a class="button" id="fh-inv-done-view" href="#"><?php esc_html_e( 'Open the invoice\'s page', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" id="fh-inv-done-forum" href="#"><?php esc_html_e( 'Forum line', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'new' ) ); ?>"><?php esc_html_e( 'New invoice', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'forum' ) ); ?>"><?php esc_html_e( 'Forum list of today', 'fishotel-misc-plugin' ); ?></a>
			</div>
		</div>
	</div>

	<script type="application/json" id="fh-inv-state"><?php echo wp_json_encode( $fh_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a data block, tags and quotes hex-escaped. ?></script>
</div>
