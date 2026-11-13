<?php
/**
 * One card: its details, money history and audit rows, and (Live) the
 * forms that change it. Every form has its own one-time key, so a double
 * click saves once.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $data  card, ledger, audit, owner.
 * @var array $ctx   Admin::context().
 * @var array $flash Admin::take_flash() (keep, reveal).
 * @var string $mode The effective mode.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Account;
use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Audit;
use FisHotel\Misc\Sections\Gift_Cards\Codes;
use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Service;

$fh_card  = $data['card'];
$fh_id    = (int) $fh_card['id'];
$fh_back  = Admin::card_url( $fh_id );
$fh_live  = 'live' === $mode && $ctx['can_edit'];
$fh_keep  = $flash['keep'];
$fh_value = static function ( $key, $default = '' ) use ( $fh_keep ) {
	return isset( $fh_keep[ $key ] ) ? (string) $fh_keep[ $key ] : (string) $default;
};
$fh_shown = is_array( $flash['reveal'] ) && (int) $flash['reveal']['card'] === $fh_id ? (string) $flash['reveal']['code'] : '';
$fh_date  = static function ( $utc, $format = 'M j, Y g:i a' ) {
	return $utc ? wp_date( $format, strtotime( $utc . ' UTC' ) ) : '—';
};
$fh_form  = static function ( $op ) use ( $fh_id, $fh_back ) {
	wp_nonce_field( Admin::NONCE );
	echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION ) . '">';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	echo '<input type="hidden" name="card" value="' . esc_attr( (string) $fh_id ) . '">';
	echo '<input type="hidden" name="back" value="' . esc_attr( $fh_back ) . '">';
	echo '<input type="hidden" name="request_key" value="' . esc_attr( Service::new_request_key() ) . '">';
};
$fh_states = array(
	'none'    => __( 'not sent (none asked for)', 'fishotel-misc-plugin' ),
	'pending' => __( 'waiting to be sent', 'fishotel-misc-plugin' ),
	'sending' => __( 'being sent', 'fishotel-misc-plugin' ),
	'sent'    => __( 'sent', 'fishotel-misc-plugin' ),
	'failed'  => __( 'could not be sent', 'fishotel-misc-plugin' ),
	'held'    => __( 'held back (its order was cancelled or refunded) — press Send to send it anyway', 'fishotel-misc-plugin' ),
);
?>
<p><a href="<?php echo esc_url( Admin::page_url( 'cards' ) ); ?>">← <?php esc_html_e( 'All cards', 'fishotel-misc-plugin' ); ?></a></p>

<div class="fishotel-misc-card fh-gc-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-tickets-alt"></span></div>
		<h2 class="fishotel-misc-card-title"><code><?php echo esc_html( '' !== $fh_shown ? $fh_shown : Codes::mask( $fh_card['code'] ) ); ?></code></h2>
		<span class="fh-gc-badge<?php echo 'active' !== $fh_card['status'] ? ' fh-gc-badge--off' : ''; ?>"><?php echo 'active' === $fh_card['status'] ? esc_html__( 'active', 'fishotel-misc-plugin' ) : esc_html__( 'disabled', 'fishotel-misc-plugin' ); ?></span>
	</div>
	<p class="fh-gc-big"><?php echo esc_html( Money::words( (int) $fh_card['balance_cents'] ) ); ?> <small>
	<?php
	/* translators: %s: amount */
	echo esc_html( sprintf( __( 'left of %s', 'fishotel-misc-plugin' ), Money::words( (int) $fh_card['amount_cents'] ) ) );
	?>
	</small></p>
	<dl class="fh-gc-details">
		<dt><?php esc_html_e( 'For', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( trim( $fh_card['recipient_name'] . ' ' . $fh_card['recipient_email'] ) ?: '—' ); ?></dd>
		<dt><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( trim( $fh_card['sender_name'] . ' ' . $fh_card['sender_email'] ) ?: '—' ); ?></dd>
		<dt><?php esc_html_e( 'Message', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo '' !== (string) $fh_card['message'] ? nl2br( esc_html( $fh_card['message'] ) ) : '—'; ?></dd>
		<dt><?php esc_html_e( 'In the account of', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo $data['owner'] ? '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $data['owner']->ID ) ) . '">' . esc_html( $data['owner']->display_name ) . '</a> · ' . esc_html( $fh_date( $fh_card['redeemed_at'] ) ) : esc_html__( 'nobody yet', 'fishotel-misc-plugin' ); ?></dd>
		<dt><?php esc_html_e( 'Issued', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_date( $fh_card['created_at'] ) ); ?> · <?php echo esc_html( array( 'admin' => __( 'by hand', 'fishotel-misc-plugin' ), 'order' => __( 'bought', 'fishotel-misc-plugin' ), 'import' => __( 'imported from the old plugin', 'fishotel-misc-plugin' ) )[ $fh_card['source'] ] ?? $fh_card['source'] ); ?><?php echo ! empty( $fh_card['order_id'] ) ? ' · <a href="' . esc_url( Admin::order_url( (int) $fh_card['order_id'] ) ) . '">' . esc_html( '#' . (int) $fh_card['order_id'] ) . '</a>' : ''; ?><?php echo ! empty( $fh_card['legacy_id'] ) ? ' · ' . esc_html( sprintf( /* translators: %d: old id */ __( 'old card #%d', 'fishotel-misc-plugin' ), (int) $fh_card['legacy_id'] ) ) : ''; ?></dd>
		<dt><?php esc_html_e( 'Last used', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_date( $fh_card['last_used_at'] ) ); ?></dd>
		<dt><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_states[ $fh_card['email_state'] ] ?? $fh_card['email_state'] ); ?><?php echo ! empty( $fh_card['deliver_at'] ) ? ' · ' . esc_html( sprintf( /* translators: %s: date */ __( 'on %s', 'fishotel-misc-plugin' ), $fh_date( $fh_card['deliver_at'] ) ) ) : ''; ?><?php echo ! empty( $fh_card['delivered_at'] ) ? ' · ' . esc_html( $fh_date( $fh_card['delivered_at'] ) ) : ''; ?></dd>
		<?php if ( ! empty( $fh_card['legacy_expires_at'] ) ) : ?>
			<dt><?php esc_html_e( 'Old expiry date', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_date( $fh_card['legacy_expires_at'], 'M j, Y' ) ); ?> — <?php esc_html_e( 'kept from the old plugin, never enforced: gift cards do not expire.', 'fishotel-misc-plugin' ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( $ctx['can_own'] && '' === $fh_shown ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-gc-inline">
			<?php $fh_form( 'reveal' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Show the full code', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-gc-sub"><?php esc_html_e( 'Only you see it, and showing it is written in the audit log.', 'fishotel-misc-plugin' ); ?></span>
		</form>
	<?php endif; ?>
</div>

<?php if ( $fh_live ) : ?>
	<div class="fh-gc-forms">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
			<?php $fh_form( 'adjust' ); ?>
			<h3><?php esc_html_e( 'Change the balance', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-gc-radios">
				<label><input type="radio" name="direction" value="credit" <?php checked( 'debit' !== $fh_value( 'direction' ) ); ?>> <?php esc_html_e( 'Add money', 'fishotel-misc-plugin' ); ?></label>
				<label><input type="radio" name="direction" value="debit" <?php checked( 'debit' === $fh_value( 'direction' ) ); ?>> <?php esc_html_e( 'Take money off', 'fishotel-misc-plugin' ); ?></label>
			</p>
			<label><span><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></span><input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr( $fh_value( 'amount' ) ); ?>" placeholder="10.00" required></label>
			<label><span><?php esc_html_e( 'Why (required — it goes in the audit log)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" value="<?php echo esc_attr( $fh_value( 'reason' ) ); ?>" maxlength="500" required minlength="3"></label>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the new balance', 'fishotel-misc-plugin' ); ?></button></p>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
			<?php $fh_form( 'details' ); ?>
			<h3><?php esc_html_e( 'Who it is for and from', 'fishotel-misc-plugin' ); ?></h3>
			<label><span><?php esc_html_e( 'Recipient\'s name', 'fishotel-misc-plugin' ); ?></span><input type="text" name="recipient_name" value="<?php echo esc_attr( $fh_value( 'recipient_name', $fh_card['recipient_name'] ) ); ?>" maxlength="120"></label>
			<label><span><?php esc_html_e( 'Recipient\'s e-mail address', 'fishotel-misc-plugin' ); ?></span><input type="email" name="recipient_email" value="<?php echo esc_attr( $fh_value( 'recipient_email', $fh_card['recipient_email'] ) ); ?>" maxlength="190"></label>
			<label><span><?php esc_html_e( 'From (name)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="sender_name" value="<?php echo esc_attr( $fh_value( 'sender_name', $fh_card['sender_name'] ) ); ?>" maxlength="120"></label>
			<label><span><?php esc_html_e( 'From (e-mail address)', 'fishotel-misc-plugin' ); ?></span><input type="email" name="sender_email" value="<?php echo esc_attr( $fh_value( 'sender_email', $fh_card['sender_email'] ) ); ?>" maxlength="190"></label>
			<label><span><?php esc_html_e( 'Message', 'fishotel-misc-plugin' ); ?></span><textarea name="message" rows="3" maxlength="500"><?php echo esc_textarea( $fh_value( 'message', (string) $fh_card['message'] ) ); ?></textarea></label>
			<label><span><?php esc_html_e( 'Why (optional)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" value="" maxlength="500"></label>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'fishotel-misc-plugin' ); ?></button></p>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
			<?php $fh_form( 'status' ); ?>
			<h3><?php echo 'active' === $fh_card['status'] ? esc_html__( 'Disable the card', 'fishotel-misc-plugin' ) : esc_html__( 'Switch the card back on', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-gc-sub"><?php esc_html_e( 'A disabled card keeps its money, but cannot be used or added to an account.', 'fishotel-misc-plugin' ); ?></p>
			<input type="hidden" name="status" value="<?php echo 'active' === $fh_card['status'] ? 'disabled' : 'active'; ?>">
			<label><span><?php esc_html_e( 'Why (required)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" value="" maxlength="500" required minlength="3"></label>
			<p><button type="submit" class="button"><?php echo 'active' === $fh_card['status'] ? esc_html__( 'Disable', 'fishotel-misc-plugin' ) : esc_html__( 'Switch on', 'fishotel-misc-plugin' ); ?></button></p>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
			<?php $fh_form( 'resend' ); ?>
			<h3><?php esc_html_e( 'Send the e-mail again', 'fishotel-misc-plugin' ); ?></h3>
			<label><span><?php esc_html_e( 'To (empty = the recipient)', 'fishotel-misc-plugin' ); ?></span><input type="email" name="to" value="" maxlength="190" placeholder="<?php echo esc_attr( $fh_card['recipient_email'] ); ?>"></label>
			<p><button type="submit" class="button"><?php esc_html_e( 'Send', 'fishotel-misc-plugin' ); ?></button></p>
		</form>
	</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Money', 'fishotel-misc-plugin' ); ?></h2>
<div class="fh-gc-scroll">
<table class="widefat fh-gc-table">
	<thead><tr><th><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th><th class="fh-gc-num"><?php esc_html_e( 'Amount', 'fishotel-misc-plugin' ); ?></th><th class="fh-gc-num"><?php esc_html_e( 'Left after', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th></tr></thead>
	<tbody>
	<?php foreach ( $data['ledger'] as $fh_row ) : ?>
		<tr>
			<td><?php echo esc_html( $fh_date( $fh_row['created_at'] ) ); ?></td>
			<td><?php echo esc_html( 'import_adjust' === $fh_row['type'] ? __( 'Import adjustment', 'fishotel-misc-plugin' ) : Account::activity_words( $fh_row ) ); ?><?php echo ! empty( $fh_row['order_id'] ) ? ' <a href="' . esc_url( Admin::order_url( (int) $fh_row['order_id'] ) ) . '">↗</a>' : ''; ?></td>
			<td class="fh-gc-num"><?php echo esc_html( 0 === (int) $fh_row['amount_cents'] ? '—' : Money::words( (int) $fh_row['amount_cents'] ) ); ?></td>
			<td class="fh-gc-num"><?php echo null === $fh_row['balance_after_cents'] || '' === (string) $fh_row['balance_after_cents'] ? '—' : esc_html( Money::words( (int) $fh_row['balance_after_cents'] ) ); ?></td>
			<td><?php echo esc_html( (string) $fh_row['note'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>

<h2><?php esc_html_e( 'Audit log of this card', 'fishotel-misc-plugin' ); ?></h2>
<?php
$fh_rows = $data['audit'];
include __DIR__ . '/admin-audit-rows.php';
