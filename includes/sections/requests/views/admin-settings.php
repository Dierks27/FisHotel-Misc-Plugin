<?php
/**
 * Requests settings: the switches, the timers, the "came from" list, the
 * wallet-deposit product, and the five e-mails (on / off, subject and
 * body each). Saved through the page's one admin-post action
 * (manage_options).
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string $tab    Current tab.
 * @var array  $ctx    Admin::context().
 * @var array  $flash  Admin::take_flash().
 * @var array  $needs  Listing::needs_you().
 * @var array  $labels Settings::mail_labels().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Settings;

$fh_s      = $ctx['settings'];
$fh_fees   = $ctx['fees'];
$fh_holder = array(
	'{name}'        => __( 'the person\'s name', 'fishotel-misc-plugin' ),
	'{fish}'        => __( 'the fish, one per line, with quantity and size', 'fishotel-misc-plugin' ),
	'{fee}'         => __( 'the fee', 'fishotel-misc-plugin' ),
	'{pay_url}'     => __( 'the pay link (bill, reminder)', 'fishotel-misc-plugin' ),
	'{days}'        => __( 'days left to pay, or to answer', 'fishotel-misc-plugin' ),
	'{view_url}'    => __( 'where they see and cancel their requests', 'fishotel-misc-plugin' ),
	'{ready_line}'  => __( '"You can buy it here: …" when the fish has a public product page, "already paid for…" when it is owed, else "reply to this e-mail…" (ready)', 'fishotel-misc-plugin' ),
	'{product_url}' => __( 'the product page alone, when there is one (ready)', 'fishotel-misc-plugin' ),
	'{keep_url}'    => __( 'the "keep it" link (still want it?)', 'fishotel-misc-plugin' ),
	'{cancel_url}'  => __( 'the "cancel it" link (still want it?)', 'fishotel-misc-plugin' ),
	'{note}'        => __( 'your note (declined)', 'fishotel-misc-plugin' ),
	'{site}'        => __( 'the site\'s name', 'fishotel-misc-plugin' ),
);
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-form">
		<?php wp_nonce_field( Admin::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
		<input type="hidden" name="op" value="settings">
		<input type="hidden" name="back" value="<?php echo esc_attr( Admin::page_url( 'settings' ) ); ?>">

		<section class="fishotel-misc-card fh-rq-card">
			<h3 class="fh-rq-card__title"><?php esc_html_e( 'By itself', 'fishotel-misc-plugin' ); ?></h3>
			<label class="fh-rq-check">
				<input type="checkbox" name="settings[auto_offer]" value="1" <?php checked( ! empty( $fh_s['auto_offer'] ) ); ?>>
				<strong><?php esc_html_e( 'Tell people by themselves when their fish is ready', 'fishotel-misc-plugin' ); ?></strong>
			</label>
			<p class="fh-rq-sub"><?php esc_html_e( 'On: the daily check e-mails the person the morning their tank group goes on sale. Off: the request waits under "Needs you" for one click.', 'fishotel-misc-plugin' ); ?></p>

			<div class="fh-rq-grid">
				<label><span><?php esc_html_e( 'Fee reminder after (days)', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="settings[reminder_days]" value="<?php echo esc_attr( (string) $fh_s['reminder_days'] ); ?>" min="1" max="60"></label>
				<label><span><?php esc_html_e( 'An unpaid request closes after (days)', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="settings[expire_days]" value="<?php echo esc_attr( (string) $fh_s['expire_days'] ); ?>" min="2" max="120"></label>
				<label><span><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Ask "still want it?" after (months of waiting; no answer in %d days closes it)', 'fishotel-misc-plugin' ), Rules::ASK_ANSWER_DAYS ) ); ?></span><input type="number" class="fh-rq-input" name="settings[ask_months]" value="<?php echo esc_attr( (string) $fh_s['ask_months'] ); ?>" min="1" max="36"></label>
			</div>
			<p class="fh-rq-sub"><?php esc_html_e( 'Only requests that came through a link or the public form are asked "still want it?". A request you added by hand and a fish that is owed are never asked and never close by themselves.', 'fishotel-misc-plugin' ); ?></p>
		</section>

		<section class="fishotel-misc-card fh-rq-card">
			<h3 class="fh-rq-card__title"><?php esc_html_e( 'Where requests come from', 'fishotel-misc-plugin' ); ?></h3>
			<label><span class="fh-rq-sub"><?php esc_html_e( 'One per line — the choices of "Came from" when you add a request by hand.', 'fishotel-misc-plugin' ); ?></span>
				<textarea class="fh-rq-input" name="settings[came_from]" rows="7"><?php echo esc_textarea( implode( "\n", $fh_s['came_from'] ) ); ?></textarea>
			</label>
		</section>

		<section class="fishotel-misc-card fh-rq-card">
			<h3 class="fh-rq-card__title"><?php esc_html_e( 'Fees', 'fishotel-misc-plugin' ); ?></h3>
			<p>
				<?php
				echo esc_html(
					$fh_fees['available']
						/* translators: 1: product title, 2: product id */
						? sprintf( __( 'Available. A fee is paid through Batch Manager\'s wallet deposit — product "%1$s" (#%2$d) — so it lands in the customer\'s wallet.', 'fishotel-misc-plugin' ), $fh_fees['product_title'], $fh_fees['product_id'] )
						/* translators: %s: why */
						: sprintf( __( 'Unavailable: %s.', 'fishotel-misc-plugin' ), $fh_fees['reason'] )
				);
				?>
			</p>
			<div class="fh-rq-grid">
				<label>
					<span><?php esc_html_e( 'Wallet-deposit product ID (leave empty to use Batch Manager\'s own — any other must have "Wallet Refill" in its name, or Batch Manager credits no wallet and fees are switched off)', 'fishotel-misc-plugin' ); ?></span>
					<input type="number" class="fh-rq-input" name="settings[deposit_product]" value="<?php echo esc_attr( (int) $fh_s['deposit_product'] > 0 ? (string) $fh_s['deposit_product'] : '' ); ?>" min="0">
				</label>
			</div>
			<p class="fh-rq-sub"><?php esc_html_e( 'The wallet is credited by Batch Manager when the order is paid, to the account that pays — so the pay link asks for a login, a fee line is never discounted by a coupon, and its amount cannot be changed by the customer.', 'fishotel-misc-plugin' ); ?></p>
		</section>

		<section class="fishotel-misc-card fh-rq-card">
			<h3 class="fh-rq-card__title"><?php esc_html_e( 'Later: open to the public', 'fishotel-misc-plugin' ); ?></h3>
			<label class="fh-rq-check">
				<input type="checkbox" name="settings[public_open]" value="1" <?php checked( ! empty( $fh_s['public_open'] ) ); ?>>
				<strong><?php esc_html_e( 'Open to the public', 'fishotel-misc-plugin' ); ?></strong>
			</label>
			<p class="fh-rq-sub"><?php esc_html_e( 'Off (as it starts): the form exists only behind a private link, and the [fishotel_request_form] shortcode prints nothing. On: a page with that shortcode shows the form to logged-in customers; what they send waits for your approval like a sheet from a link.', 'fishotel-misc-plugin' ); ?></p>
			<div class="fh-rq-grid">
				<label><span><?php esc_html_e( 'Default fee of a public sheet (empty = none)', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input fh-rq-input--money" name="settings[default_fee]" value="<?php echo esc_attr( (float) $fh_s['default_fee'] > 0 ? number_format( (float) $fh_s['default_fee'], 2, '.', '' ) : '' ); ?>" placeholder="0.00" inputmode="decimal"></label>
				<label><span><?php esc_html_e( 'Open requests one customer may have', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="settings[public_cap]" value="<?php echo esc_attr( (string) $fh_s['public_cap'] ); ?>" min="1" max="100"></label>
			</div>
		</section>

		<section class="fishotel-misc-card fh-rq-card">
			<h3 class="fh-rq-card__title"><?php esc_html_e( 'E-mails', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-rq-sub"><?php esc_html_e( 'Each is sent as simple HTML with a plain-text part, and every send is written to the request\'s history. Leave a subject or body empty to get the standard text back. Write plain text; the words in {braces} are filled in:', 'fishotel-misc-plugin' ); ?></p>
			<ul class="fh-rq-holders">
				<?php foreach ( $fh_holder as $fh_key => $fh_what ) : ?>
					<li><code><?php echo esc_html( $fh_key ); ?></code> <?php echo esc_html( $fh_what ); ?></li>
				<?php endforeach; ?>
			</ul>

			<?php foreach ( Settings::MAIL_KINDS as $fh_kind ) : ?>
				<?php $fh_mail = $fh_s['mail'][ $fh_kind ]; ?>
				<fieldset class="fh-rq-mail">
					<legend>
						<label class="fh-rq-check">
							<input type="checkbox" name="settings[mail][<?php echo esc_attr( $fh_kind ); ?>][on]" value="1" <?php checked( ! empty( $fh_mail['on'] ) ); ?>>
							<strong><?php echo esc_html( $labels[ $fh_kind ]['label'] ); ?></strong>
						</label>
					</legend>
					<p class="fh-rq-sub"><?php echo esc_html( $labels[ $fh_kind ]['when'] ); ?></p>
					<label><span><?php esc_html_e( 'Subject', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="settings[mail][<?php echo esc_attr( $fh_kind ); ?>][subject]" value="<?php echo esc_attr( $fh_mail['subject'] ); ?>" maxlength="<?php echo esc_attr( Settings::MAX_SUBJECT ); ?>"></label>
					<label><span><?php esc_html_e( 'Body', 'fishotel-misc-plugin' ); ?></span><textarea class="fh-rq-input fh-rq-input--body" name="settings[mail][<?php echo esc_attr( $fh_kind ); ?>][body]" rows="10" maxlength="<?php echo esc_attr( Settings::MAX_BODY ); ?>"><?php echo esc_textarea( $fh_mail['body'] ); ?></textarea></label>
				</fieldset>
			<?php endforeach; ?>
		</section>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the settings', 'fishotel-misc-plugin' ); ?></button></p>
	</form>
</div>
