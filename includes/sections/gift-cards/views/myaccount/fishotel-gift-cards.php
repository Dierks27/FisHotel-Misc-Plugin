<?php
/**
 * My Account → Gift Cards. A theme may copy this file to
 * woocommerce/myaccount/fishotel-gift-cards.php to restyle it.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var int        $balance      Cents usable now.
 * @var array[]    $cards        The cards in the account.
 * @var array[]    $activity     Ledger rows, newest first.
 * @var array      $codes        Card id → its code (the user's own cards).
 * @var int        $current_page Activity page.
 * @var int        $total_pages  Activity pages.
 * @var array|null $flash        ok, message.
 * @var string     $page_url     This page.
 * @var string     $nonce        The forms' nonce.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Account;
use FisHotel\Misc\Sections\Gift_Cards\Codes;
use FisHotel\Misc\Sections\Gift_Cards\Money;

$fh_code = static function ( $code ) {
	return function_exists( 'fishotel_giftcard_code_html' ) ? fishotel_giftcard_code_html( $code ) : '<span class="fh-giftcard-code"><code>' . esc_html( $code ) . '</code></span>';
};
$fh_date = static function ( $utc ) {
	return $utc ? wp_date( get_option( 'date_format', 'M j, Y' ), strtotime( $utc . ' UTC' ) ) : '—';
};
?>
<div class="fh-gcard-account">
	<?php if ( $flash ) : ?>
		<div class="fh-flash <?php echo $flash['ok'] ? 'fh-flash--success woocommerce-message' : 'fh-flash--error woocommerce-error'; ?>" role="status"><?php echo esc_html( $flash['message'] ); ?></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Your balance', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-gcard-account__balance wc_gc_account_balance"><?php echo esc_html( Money::words( $balance ) ); ?></p>
	<p><?php esc_html_e( 'At checkout you choose how much of it to put toward an order — all of it, part of it, or none. Gift cards never expire.', 'fishotel-misc-plugin' ); ?></p>

	<h3><?php esc_html_e( 'Redeem a gift card', 'fishotel-misc-plugin' ); ?></h3>
	<form method="post" action="<?php echo esc_url( $page_url ); ?>" class="fh-gcard-redeem">
		<input type="hidden" name="fh_gcard_action" value="redeem">
		<input type="hidden" name="_fh_gcard_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<label class="screen-reader-text" for="fh_gcard_code_account"><?php esc_html_e( 'Gift card code', 'fishotel-misc-plugin' ); ?></label>
		<input type="text" id="fh_gcard_code_account" name="fh_gcard_code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX" required>
		<button type="submit" class="button"><?php esc_html_e( 'Redeem', 'fishotel-misc-plugin' ); ?></button>
	</form>

	<h3><?php esc_html_e( 'Your gift cards', 'fishotel-misc-plugin' ); ?></h3>
	<?php if ( ! $cards ) : ?>
		<p><?php esc_html_e( 'No gift card in your account yet.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<table class="shop_table shop_table_responsive woocommerce-giftcards-table">
			<thead><tr><th><?php esc_html_e( 'Code', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Left', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Issued for', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Added', 'fishotel-misc-plugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $cards as $fh_card ) : ?>
				<tr>
					<td class="wc_gc_code" data-title="<?php esc_attr_e( 'Code', 'fishotel-misc-plugin' ); ?>">
						<?php echo wp_kses_post( $fh_code( $codes[ (int) $fh_card['id'] ] ?? '' ) ); ?>
						<?php if ( 'active' !== $fh_card['status'] ) : ?>
							<br><small><?php esc_html_e( 'Not usable at the moment — please contact us.', 'fishotel-misc-plugin' ); ?></small>
						<?php endif; ?>
						<?php if ( ! empty( $fh_card['legacy_expires_at'] ) ) : ?>
							<br><small><?php esc_html_e( 'An old expiry date is on record; it does not apply.', 'fishotel-misc-plugin' ); ?></small>
						<?php endif; ?>
					</td>
					<td data-title="<?php esc_attr_e( 'Left', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( Money::words( (int) $fh_card['balance_cents'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Issued for', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( Money::words( (int) $fh_card['amount_cents'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Added', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( $fh_date( $fh_card['redeemed_at'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $activity ) : ?>
		<h3><?php esc_html_e( 'Activity', 'fishotel-misc-plugin' ); ?></h3>
		<table class="shop_table shop_table_responsive woocommerce-giftcards-table">
			<thead><tr><th><?php esc_html_e( 'Date', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Card', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $activity as $fh_row ) : ?>
				<tr>
					<td data-title="<?php esc_attr_e( 'Date', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( $fh_date( $fh_row['created_at'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'What', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( Account::activity_words( $fh_row ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Card', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( Codes::last4( $codes[ (int) $fh_row['card_id'] ] ?? '' ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Amount', 'fishotel-misc-plugin' ); ?>"><?php echo 0 === (int) $fh_row['amount_cents'] ? '—' : esc_html( ( (int) $fh_row['amount_cents'] > 0 ? '+' : '' ) . Money::words( (int) $fh_row['amount_cents'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total_pages > 1 ) : ?>
			<p class="fh-gcard-pages">
				<?php if ( $current_page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'fh_gcard_page', $current_page - 1, $page_url ) ); ?>"><?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
				<?php if ( $current_page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'fh_gcard_page', $current_page + 1, $page_url ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
