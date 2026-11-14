<?php
/**
 * Settings tab — sending, the reminder, the links, the e-mail template.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var string     $tab       Current tab.
 * @var array|null $flash     A one-time notice.
 * @var bool       $tables_ok Whether the tables exist.
 * @var array      $status    Admin::connections().
 * @var array      $settings  Settings::get().
 * @var array      $template  Email::template(): the subject and body in use.
 * @var array      $sample    Admin::sample_message(): the e-mail with invented data.
 * @var object     $cases     The cases (for the undo window).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Email;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Settings;

$fh_minutes = (int) round( $cases->rooms()->undo_window() / 60 );
?>
<div class="wrap fishotel-misc-wrap fh-lf-wrap">

	<?php include Lost_Fish::path() . 'views/top.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-lf-settings">
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION_SETTINGS ); ?>">
		<?php wp_nonce_field( Admin::NONCE_SETTINGS, '_wpnonce', false ); ?>

		<section class="fh-lf-panel">
			<h2 class="fh-lf-h2"><?php esc_html_e( 'Sending', 'fishotel-misc-plugin' ); ?></h2>

			<label class="fh-lf-check">
				<input type="checkbox" name="fh_lf[auto_send]" value="1" <?php checked( $settings['auto_send'] ); ?>>
				<span>
					<strong><?php esc_html_e( 'Send by itself after the undo window', 'fishotel-misc-plugin' ); ?></strong>
					<span class="fh-lf-sub">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: minutes */
								__( 'Off (the default): no e-mail ever leaves without your click on "Send to customer". On: %d minutes after a loss — the time it can still be undone on the phone — a case goes out by itself, but only when everything about it is certain: the order line was found by its product, the shipping difference was worked out, and the tanks are still short. A case that says "check", a case opened by hand and a case whose numbers you typed always wait for you.', 'fishotel-misc-plugin' ),
								$fh_minutes
							)
						);
						?>
					</span>
				</span>
			</label>

			<div class="fh-lf-fields">
				<label>
					<span><?php esc_html_e( 'Remind the customer after (days)', 'fishotel-misc-plugin' ); ?></span>
					<input type="number" class="fh-lf-input fh-lf-input--num" name="fh_lf[reminder_days]" min="<?php echo (int) Settings::REMINDER_MIN; ?>" max="<?php echo (int) Settings::REMINDER_MAX; ?>" step="1" value="<?php echo esc_attr( $settings['reminder_days'] ); ?>">
					<span class="fh-lf-sub"><?php esc_html_e( 'One reminder when no answer came; after it the case waits under "Needs you". 0 = no reminder.', 'fishotel-misc-plugin' ); ?></span>
				</label>
				<label>
					<span><?php esc_html_e( 'The links are good for (days)', 'fishotel-misc-plugin' ); ?></span>
					<input type="number" class="fh-lf-input fh-lf-input--num" name="fh_lf[link_days]" min="<?php echo (int) Settings::LINK_MIN; ?>" max="<?php echo (int) Settings::LINK_MAX; ?>" step="1" value="<?php echo esc_attr( $settings['link_days'] ); ?>">
					<span class="fh-lf-sub"><?php esc_html_e( 'Counted from the e-mail (and again from the reminder).', 'fishotel-misc-plugin' ); ?></span>
				</label>
				<label>
					<span><?php esc_html_e( 'Shipping classes that mean "free on purpose"', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-lf-input" name="fh_lf[free_classes]" value="<?php echo esc_attr( implode( ', ', $settings['free_classes'] ) ); ?>">
					<span class="fh-lf-sub"><?php esc_html_e( 'Class slugs, separated by commas. An order that shipped free with every line in one of these has no shipping difference.', 'fishotel-misc-plugin' ); ?></span>
				</label>
			</div>
		</section>

		<section class="fh-lf-panel">
			<h2 class="fh-lf-h2"><?php esc_html_e( 'The e-mail', 'fishotel-misc-plugin' ); ?></h2>

			<div class="fh-lf-fields">
				<label class="fh-lf-wide">
					<span><?php esc_html_e( 'Subject', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" class="fh-lf-input" name="fh_lf[subject]" maxlength="<?php echo (int) Settings::SUBJECT_MAX; ?>" value="<?php echo esc_attr( $template['subject'] ); ?>">
				</label>
				<label class="fh-lf-wide">
					<span><?php esc_html_e( 'Text', 'fishotel-misc-plugin' ); ?></span>
					<textarea class="fh-lf-input" name="fh_lf[body]" rows="16" maxlength="<?php echo (int) Settings::BODY_MAX; ?>"><?php echo esc_textarea( $template['body'] ); ?></textarea>
					<span class="fh-lf-sub">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: list of placeholders */
								__( 'Plain text; an empty line starts a new paragraph. Placeholders: %s. {choices} is the block with the four links and their amounts — it is added at the end when the text leaves it out. {ship_line} is the sentence about when the rest of the order ships ("We will confirm your ship date" when the order has none).', 'fishotel-misc-plugin' ),
								'{' . implode( '}, {', Email::PLACEHOLDERS ) . '}'
							)
						);
						?>
					</span>
				</label>
				<label>
					<span><?php esc_html_e( 'Reply-To address (optional)', 'fishotel-misc-plugin' ); ?></span>
					<input type="email" class="fh-lf-input" name="fh_lf[reply_to]" value="<?php echo esc_attr( $settings['reply_to'] ); ?>">
					<span class="fh-lf-sub"><?php esc_html_e( 'Empty: replies go to the store\'s sender address.', 'fishotel-misc-plugin' ); ?></span>
				</label>
				<label class="fh-lf-check">
					<input type="checkbox" name="fh_lf[store_design]" value="1" <?php checked( $settings['store_design'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Use the store\'s e-mail design', 'fishotel-misc-plugin' ); ?></strong>
						<span class="fh-lf-sub"><?php esc_html_e( 'The header and footer of the store\'s order e-mails, when WooCommerce is active; else a plain white card.', 'fishotel-misc-plugin' ); ?></span>
					</span>
				</label>
			</div>

			<p class="fh-lf-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fishotel-misc-plugin' ); ?></button>
				<button type="submit" class="button" name="reset" value="1" data-fh-lf-confirm="<?php esc_attr_e( 'Put the subject and the text back to the built-in ones?', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Back to the built-in text', 'fishotel-misc-plugin' ); ?></button>
			</p>
		</section>
	</form>

	<section class="fh-lf-panel">
		<h2 class="fh-lf-h2"><?php esc_html_e( 'How it reads — with invented data', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fh-lf-sub"><?php echo esc_html( sprintf( /* translators: %s: subject */ __( 'Subject: %s · Saved settings only; the links do nothing here.', 'fishotel-misc-plugin' ), $sample['subject'] ) ); ?></p>
		<iframe class="fh-lf-preview__frame" sandbox="" title="<?php esc_attr_e( 'E-mail preview', 'fishotel-misc-plugin' ); ?>" srcdoc="<?php echo esc_attr( $sample['html'] ); ?>"></iframe>
	</section>

</div>
