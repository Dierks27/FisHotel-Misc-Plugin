<?php
/**
 * Settings tab — Sales statuses: which WooCommerce order statuses count
 * as a sale on Stats → Real sales. Starts as WooCommerce's paid statuses
 * plus every registered status whose slug contains "ship"; saved by its
 * own admin-post form (Admin_Stats::statuses_request(): manage_options
 * and a nonce). It changes no order: only which ones are counted.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;

$fh_sales = Admin_Stats::statuses_panel();
?>
<form id="fishotel-cleanroom-sales-statuses" class="fishotel-misc-card fh-cr-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Stats::STATUSES_ACTION ); ?>">
	<?php wp_nonce_field( Admin_Stats::STATUSES_ACTION ); ?>
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Sales statuses', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'Stats → Real sales counts an order line as sold when its order is in one of the statuses ticked here. The suggested list is WooCommerce\'s paid statuses plus every status whose name contains "ship". Nothing here changes an order — only which ones are counted.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php if ( ! $fh_sales['woocommerce'] ) : ?>
		<p class="fh-cr-sub"><?php esc_html_e( 'WooCommerce is not active, so its statuses cannot be listed: the ones below are WooCommerce\'s usual paid statuses.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<div class="fh-cr-statuses">
		<?php foreach ( $fh_sales['rows'] as $fh_status ) : ?>
			<label class="fh-cr-filter fh-cr-filter--check">
				<input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $fh_status['slug'] ); ?>" <?php checked( $fh_status['counts'] ); ?>>
				<span>
					<?php echo esc_html( $fh_status['label'] ); ?>
					<code><?php echo esc_html( $fh_status['slug'] ); ?></code>
					<?php if ( $fh_status['suggested'] ) : ?>
						<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'suggested', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
					<?php if ( ! $fh_status['registered'] && $fh_sales['woocommerce'] ) : ?>
						<span class="fh-cr-sub fh-cr-sub--inline"><?php esc_html_e( 'not a status of this store now', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
				</span>
			</label>
		<?php endforeach; ?>
	</div>

	<p class="fh-cr-sub">
		<?php
		echo esc_html(
			$fh_sales['saved']
				? __( 'This list was saved by hand: a status added to the store later is not counted until it is ticked here.', 'fishotel-misc-plugin' )
				: __( 'Not saved yet: the suggested list applies, and follows the store\'s statuses until a list is saved here.', 'fishotel-misc-plugin' )
		);
		?>
	</p>

	<div class="fh-cr-actions-row">
		<button type="submit" name="op" value="save" class="button button-primary fh-cr-save"><?php esc_html_e( 'Save statuses', 'fishotel-misc-plugin' ); ?></button>
		<?php if ( $fh_sales['saved'] ) : ?>
			<button type="submit" name="op" value="reset" class="button"><?php esc_html_e( 'Back to the suggested list', 'fishotel-misc-plugin' ); ?></button>
		<?php endif; ?>
	</div>
</form>
