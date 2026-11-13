<?php
/**
 * One order's gift card money, and "put part of it back" (a partial
 * refund that should go to the card).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array  $data order_id, summary, cards.
 * @var array  $ctx  Admin::context().
 * @var string $mode The effective mode.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Codes;
use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Service;

$fh_sum = $data['summary'];
$fh_oid = (int) $data['order_id'];
?>
<p><a href="<?php echo esc_url( Admin::order_url( $fh_oid ) ); ?>">← <?php echo esc_html( sprintf( /* translators: %d: order */ __( 'Order #%d', 'fishotel-misc-plugin' ), $fh_oid ) ); ?></a></p>
<?php if ( ! $fh_sum['cards'] ) : ?>
	<div class="fishotel-misc-notice"><?php esc_html_e( 'No gift card was used on this order.', 'fishotel-misc-plugin' ); ?></div>
<?php else : ?>
	<table class="widefat fh-gc-table fh-gc-table--narrow">
		<thead><tr><th><?php esc_html_e( 'Card', 'fishotel-misc-plugin' ); ?></th><th class="fh-gc-num"><?php esc_html_e( 'Paid', 'fishotel-misc-plugin' ); ?></th><th class="fh-gc-num"><?php esc_html_e( 'Put back', 'fishotel-misc-plugin' ); ?></th><th class="fh-gc-num"><?php esc_html_e( 'Still on the order', 'fishotel-misc-plugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $fh_sum['cards'] as $fh_cid => $fh_m ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( Admin::card_url( (int) $fh_cid ) ); ?>"><code><?php echo esc_html( isset( $data['cards'][ $fh_cid ] ) ? Codes::mask( $data['cards'][ $fh_cid ]['code'] ) : '#' . $fh_cid ); ?></code></a></td>
				<td class="fh-gc-num"><?php echo esc_html( Money::words( (int) $fh_m['used'] ) ); ?></td>
				<td class="fh-gc-num"><?php echo esc_html( Money::words( (int) $fh_m['restored'] ) ); ?></td>
				<td class="fh-gc-num"><?php echo esc_html( Money::words( (int) $fh_m['outstanding'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $fh_sum['outstanding'] > 0 && 'live' === $mode && $ctx['can_edit'] ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
			<?php wp_nonce_field( Admin::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
			<input type="hidden" name="op" value="restore_part">
			<input type="hidden" name="order" value="<?php echo esc_attr( (string) $fh_oid ); ?>">
			<input type="hidden" name="back" value="<?php echo esc_attr( Admin::page_url( 'order', array( 'order' => $fh_oid ) ) ); ?>">
			<input type="hidden" name="request_key" value="<?php echo esc_attr( Service::new_request_key() ); ?>">
			<h3><?php esc_html_e( 'Put part of it back on the card', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-gc-sub"><?php esc_html_e( 'For a partial refund that should go to the gift card instead of the payment method. Record the refund in WooCommerce as "Refund manually" for the same amount, so the payment method is not refunded too. A full refund puts the card part back by itself.', 'fishotel-misc-plugin' ); ?></p>
			<label><span>
			<?php
			/* translators: %s: amount */
			echo esc_html( sprintf( __( 'Amount (up to %s)', 'fishotel-misc-plugin' ), Money::words( (int) $fh_sum['outstanding'] ) ) );
			?>
			</span><input type="text" inputmode="decimal" name="amount" required></label>
			<label><span><?php esc_html_e( 'Why (required)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" maxlength="500" required minlength="3"></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Put it back', 'fishotel-misc-plugin' ); ?></button></p>
		</form>
	<?php endif; ?>
<?php endif; ?>
