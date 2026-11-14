<?php
/**
 * The forum list — the plain block pasted into the thread: one line per
 * invoice, "@Handle $Total link", no bold and no dashes; a handle with a
 * forum user id as [USER=id]Handle[/USER]. For the ticked invoices, or
 * for everything published today.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      todays, recent, selected.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;

$fh_today = array_column( $data['todays'], 'id' );
$fh_rows  = $data['recent'];
$fh_have  = array_column( $fh_rows, 'id' );
$fh_auto  = (bool) $data['selected'];
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap" id="fh-inv-forum" data-auto="<?php echo $fh_auto ? '1' : '0'; ?>">

	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<div class="fishotel-misc-card fh-inv-card">
		<h3 class="fh-inv-h3"><?php esc_html_e( 'The list to paste into the thread', 'fishotel-misc-plugin' ); ?></h3>

		<div class="fh-inv-buttons">
			<button type="button" class="button button-primary" id="fh-inv-forum-today">
				<?php echo esc_html( sprintf( /* translators: %d: invoices */ _n( 'Everything published today (%d invoice)', 'Everything published today (%d invoices)', count( $fh_today ), 'fishotel-misc-plugin' ), count( $fh_today ) ) ); ?>
			</button>
			<button type="button" class="button" id="fh-inv-forum-picked"><?php esc_html_e( 'The ticked invoices', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-inv-status" id="fh-inv-forum-status" aria-live="polite"></span>
		</div>

		<label class="screen-reader-text" for="fh-inv-forum-text"><?php esc_html_e( 'The forum list', 'fishotel-misc-plugin' ); ?></label>
		<textarea class="fh-inv-input fh-inv-forum__text" id="fh-inv-forum-text" rows="8" readonly placeholder="<?php esc_attr_e( '@Handle $Total https://… — one line per invoice', 'fishotel-misc-plugin' ); ?>"></textarea>

		<div class="fh-inv-buttons">
			<button type="button" class="button button-primary" id="fh-inv-forum-copy" disabled><?php esc_html_e( 'Copy', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-inv-footnote"><?php esc_html_e( 'Plain text: no bold, no dashes. A handle with a forum user id is written as [USER=id]Handle[/USER], which tags where a typed @ does not.', 'fishotel-misc-plugin' ); ?></span>
		</div>
		<ul class="fh-inv-forum__skipped" id="fh-inv-forum-skipped"></ul>
	</div>

	<div class="fishotel-misc-card fh-inv-card">
		<h3 class="fh-inv-h3"><?php esc_html_e( 'Published and paid invoices, newest first', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $fh_rows ) : ?>
			<p class="fh-inv-footnote"><?php esc_html_e( 'Nothing is published yet.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat fh-inv-table">
				<thead>
					<tr>
						<td class="fh-inv-check"><input type="checkbox" class="fh-inv-check-all" aria-label="<?php esc_attr_e( 'Tick every invoice', 'fishotel-misc-plugin' ); ?>"></td>
						<th scope="col"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Invoice', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Published', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fh_rows as $fh_inv ) : ?>
						<tr>
							<td class="fh-inv-check"><input type="checkbox" class="fh-inv-pick" value="<?php echo esc_attr( $fh_inv['id'] ); ?>" <?php checked( in_array( (int) $fh_inv['id'], $data['selected'], true ) ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: handle */ __( 'Tick the invoice of %s', 'fishotel-misc-plugin' ), $fh_inv['handle'] ) ); ?>"></td>
							<td>
								<strong><?php echo esc_html( $fh_inv['handle'] ); ?></strong>
								<?php if ( $fh_inv['forum_user_id'] ) : ?>
									<span class="fh-inv-sub"><?php echo esc_html( sprintf( /* translators: %d: forum user id */ __( 'forum user %d', 'fishotel-misc-plugin' ), $fh_inv['forum_user_id'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( Admin::page_url( 'invoice', array( 'id' => $fh_inv['id'] ) ) ); ?>"><?php echo esc_html( $fh_inv['title'] ); ?></a></td>
							<td class="fh-inv-num"><?php echo esc_html( Lines::dollars( $fh_inv['total'] ) ); ?></td>
							<td><?php echo Admin::badge( $fh_inv['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></td>
							<td>
								<?php echo esc_html( Admin::site_time( $fh_inv['published_at'] ) ); ?>
								<?php if ( in_array( (int) $fh_inv['id'], $fh_today, true ) ) : ?>
									<span class="fh-inv-tag"><?php esc_html_e( 'today', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		// Ticked on the list page but older than the rows shown here: carried along so they are not lost.
		foreach ( array_diff( $data['selected'], $fh_have ) as $fh_id ) :
			?>
			<input type="checkbox" class="fh-inv-pick" value="<?php echo esc_attr( $fh_id ); ?>" checked hidden>
		<?php endforeach; ?>
	</div>
</div>
