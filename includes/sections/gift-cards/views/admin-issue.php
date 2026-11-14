<?php
/**
 * Issue a new card without an order (Live).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $ctx   Admin::context().
 * @var array $flash Admin::take_flash() (keep).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Service;

$fh_keep  = $flash['keep'];
$fh_value = static function ( $key, $default = '' ) use ( $fh_keep ) {
	return isset( $fh_keep[ $key ] ) ? (string) $fh_keep[ $key ] : (string) $default;
};
$fh_deliv = $fh_value( 'delivery', 'now' );
?>
<?php if ( 'live' !== $mode || ! $ctx['can_edit'] ) : ?>
	<div class="fishotel-misc-notice"><?php esc_html_e( 'Cards are issued here in Live mode, by those allowed to change balances (Settings).', 'fishotel-misc-plugin' ); ?></div>
<?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form fh-gc-issue">
	<?php wp_nonce_field( Admin::NONCE ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
	<input type="hidden" name="op" value="issue">
	<input type="hidden" name="back" value="<?php echo esc_attr( Admin::page_url( 'issue' ) ); ?>">
	<input type="hidden" name="request_key" value="<?php echo esc_attr( Service::new_request_key() ); ?>">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-plus-alt"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'A new gift card', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'For a prize, a thank-you, a card sold in the shop — no order needed. It gets a new long random code and never expires. Issuing it is written in the audit log.', 'fishotel-misc-plugin' ); ?></p>
	<div class="fh-gc-grid">
		<label><span>
		<?php
		/* translators: %s: largest */
		echo esc_html( sprintf( __( 'Amount ($1.00 to %s)', 'fishotel-misc-plugin' ), Money::words( Money::MAX_CENTS ) ) );
		?>
		</span><input type="text" inputmode="decimal" name="amount" value="<?php echo esc_attr( $fh_value( 'amount' ) ); ?>" placeholder="25.00" required></label>
		<label><span><?php esc_html_e( 'Recipient\'s name', 'fishotel-misc-plugin' ); ?></span><input type="text" name="recipient_name" value="<?php echo esc_attr( $fh_value( 'recipient_name' ) ); ?>" maxlength="120"></label>
		<label><span><?php esc_html_e( 'Recipient\'s e-mail address', 'fishotel-misc-plugin' ); ?></span><input type="email" name="recipient_email" value="<?php echo esc_attr( $fh_value( 'recipient_email' ) ); ?>" maxlength="190"></label>
		<label><span><?php esc_html_e( 'From (name)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="sender_name" value="<?php echo esc_attr( $fh_value( 'sender_name', get_bloginfo( 'name' ) ) ); ?>" maxlength="120"></label>
		<label class="fh-gc-grid__wide"><span><?php esc_html_e( 'Message (optional, in the e-mail)', 'fishotel-misc-plugin' ); ?></span><textarea name="message" rows="3" maxlength="500"><?php echo esc_textarea( $fh_value( 'message' ) ); ?></textarea></label>
		<fieldset class="fh-gc-grid__wide fh-gc-radios">
			<legend><?php esc_html_e( 'The e-mail with the code', 'fishotel-misc-plugin' ); ?></legend>
			<label><input type="radio" name="delivery" value="now" <?php checked( 'now', $fh_deliv ); ?>> <?php esc_html_e( 'Send it now', 'fishotel-misc-plugin' ); ?></label>
			<label><input type="radio" name="delivery" value="date" <?php checked( 'date', $fh_deliv ); ?>> <?php esc_html_e( 'Send it on', 'fishotel-misc-plugin' ); ?> <input type="date" name="deliver_on" value="<?php echo esc_attr( $fh_value( 'deliver_on' ) ); ?>" min="<?php echo esc_attr( $ctx['service']->today() ); ?>"> <small><?php esc_html_e( '(9 am that day)', 'fishotel-misc-plugin' ); ?></small></label>
			<label><input type="radio" name="delivery" value="none" <?php checked( 'none', $fh_deliv ); ?>> <?php esc_html_e( 'Do not send it (you hand the code over yourself)', 'fishotel-misc-plugin' ); ?></label>
		</fieldset>
		<label class="fh-gc-grid__wide"><span><?php esc_html_e( 'Why (optional — it goes in the audit log)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" value="<?php echo esc_attr( $fh_value( 'reason' ) ); ?>" maxlength="500"></label>
	</div>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Issue the card', 'fishotel-misc-plugin' ); ?></button></p>
</form>
<?php endif; ?>
