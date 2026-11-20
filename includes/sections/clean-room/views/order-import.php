<?php
/**
 * "Import lines" on the order page — the box that takes pasted rows or a
 * file, the two moments, and the panel "Have an assistant read the
 * invoice for you". The preview under it is drawn by js/order-import.js
 * from what Admin_Order_Import answers; nothing here has a name, so the
 * order's own Save never gathers these fields.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array $order        The order (status, supplier).
 * @var bool  $is_new       Whether the order is unsaved.
 * @var bool  $can_settings Whether the user may upload a file (what the workbook import asks for).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Order_Import;

$fh_arrived = in_array( (string) $order['status'], array( 'received', 'closed' ), true );
$fh_open    = isset( $_GET['import'] ) && '1' === (string) wp_unslash( $_GET['import'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opens a panel, nothing more.
?>
<div class="fishotel-misc-card fh-cr-card fh-cr-import" id="fh-cr-import" data-open="<?php echo $fh_open ? '1' : '0'; ?>" hidden>
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-upload"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Import lines', 'fishotel-misc-plugin' ); ?></h2>
	</div>

	<div class="fh-cr-import__modes" role="group" aria-label="<?php esc_attr_e( 'What is this file?', 'fishotel-misc-plugin' ); ?>">
		<button type="button" class="fh-cr-import__mode<?php echo $fh_arrived ? '' : ' is-on'; ?>" data-mode="ordered" aria-pressed="<?php echo $fh_arrived ? 'false' : 'true'; ?>">
			<strong><?php esc_html_e( 'This is what I ORDERED', 'fishotel-misc-plugin' ); ?></strong>
			<span><?php esc_html_e( 'My order sheet — the supplier\'s price list with my order quantities filled in. Every row with an order quantity becomes a line; received stays empty.', 'fishotel-misc-plugin' ); ?></span>
		</button>
		<button type="button" class="fh-cr-import__mode<?php echo $fh_arrived ? ' is-on' : ''; ?>" data-mode="arrived" aria-pressed="<?php echo $fh_arrived ? 'true' : 'false'; ?>">
			<strong><?php esc_html_e( 'This is what ARRIVED', 'fishotel-misc-plugin' ); ?></strong>
			<span><?php esc_html_e( 'The supplier\'s invoice or packing list. Every row is matched against the lines on this order: what came, what came short, what did not come, what is extra.', 'fishotel-misc-plugin' ); ?></span>
		</button>
	</div>

	<div class="fh-cr-import__in">
		<div class="fh-cr-import__box">
			<label class="screen-reader-text" for="fh-cr-import-text"><?php esc_html_e( 'Rows to import', 'fishotel-misc-plugin' ); ?></label>
			<textarea id="fh-cr-import-text" class="fh-cr-input fh-cr-import__text" rows="7" spellcheck="false" placeholder="<?php esc_attr_e( 'Paste rows here — copied out of a spreadsheet, or comma-separated text. With or without a header row.', 'fishotel-misc-plugin' ); ?>"></textarea>
			<p class="fh-cr-import__file-row">
				<?php if ( $can_settings ) : ?>
					<label for="fh-cr-import-file"><?php esc_html_e( '… or a file (CSV, TSV, XLSX, XLSM):', 'fishotel-misc-plugin' ); ?></label>
					<input type="file" id="fh-cr-import-file" class="fh-cr-import__file" accept=".csv,.tsv,.txt,.xlsx,.xlsm">
				<?php else : ?>
					<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'A file can be uploaded by an administrator; pasted rows work for everyone.', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
				<button type="button" class="button button-primary fh-cr-import__read"><?php esc_html_e( 'Read it', 'fishotel-misc-plugin' ); ?></button>
			</p>
			<p class="fh-cr-import__too-wrap" <?php echo $fh_arrived ? 'hidden' : ''; ?>>
				<label><input type="checkbox" class="fh-cr-import__too" <?php checked( $fh_arrived ); ?>> <?php esc_html_e( 'These fish have arrived — use the quantity as received too', 'fishotel-misc-plugin' ); ?></label>
			</p>
			<p class="fh-cr-sub" data-for-mode="ordered" <?php echo $fh_arrived ? 'hidden' : ''; ?>><?php esc_html_e( 'A whole price list is fine: only the rows with an order quantity above 0 become lines, and the supplier\'s stock column is never taken for a quantity. Nothing is saved before you confirm, and nothing already on the order is changed.', 'fishotel-misc-plugin' ); ?></p>
			<p class="fh-cr-sub" data-for-mode="arrived" <?php echo $fh_arrived ? '' : 'hidden'; ?>><?php esc_html_e( 'Nothing is saved before you confirm. Confirming writes the received counts on the order; it never removes a line, never changes a line that is already checked in, and never checks anything in.', 'fishotel-misc-plugin' ); ?></p>
		</div>

		<aside class="fh-cr-import__assistant">
			<h3><?php esc_html_e( 'Have an assistant read the invoice for you', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-cr-sub"><?php esc_html_e( 'An invoice is a PDF, often a scan — this page cannot read it. Copy these instructions, paste them into your chat assistant together with the invoice (the PDF, a photo, the e-mail), and paste the block it gives back into the box.', 'fishotel-misc-plugin' ); ?></p>
			<p>
				<button type="button" class="button fh-cr-import__copy"><?php esc_html_e( 'Copy the instructions', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-cr-sub fh-cr-sub--inline fh-cr-import__copied" aria-live="polite"></span>
			</p>
			<details>
				<summary><?php esc_html_e( 'Read the instructions', 'fishotel-misc-plugin' ); ?></summary>
				<textarea class="fh-cr-input fh-cr-import__instructions" rows="10" readonly><?php echo esc_textarea( Order_Import::instructions() ); ?></textarea>
			</details>
		</aside>
	</div>

	<p class="fh-cr-import__status" aria-live="polite"></p>
	<div class="fh-cr-import__preview"></div>

	<p class="fh-cr-import__actions" hidden>
		<label class="fh-cr-import__remember-wrap" hidden><input type="checkbox" class="fh-cr-import__remember" checked> <span></span></label>
		<button type="button" class="button button-primary fh-cr-import__confirm" disabled><?php esc_html_e( 'Import', 'fishotel-misc-plugin' ); ?></button>
		<button type="button" class="button fh-cr-import__cancel"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></button>
	</p>
</div>
