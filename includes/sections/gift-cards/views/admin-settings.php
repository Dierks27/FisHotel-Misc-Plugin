<?php
/**
 * Settings: the mode (with the Live guard) and the options (owner only).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array  $data settings, blockers, legacy.
 * @var array  $ctx  Admin::context().
 * @var string $mode The effective mode.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Modes;
use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Settings;

$fh_set    = $data['settings'];
$fh_labels = Settings::labels();
$fh_stored = Modes::stored();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form" data-fh-confirm-live="<?php esc_attr_e( 'Switch to Live? From now on these gift cards take payments at checkout, show on My Account and send e-mails.', 'fishotel-misc-plugin' ); ?>">
	<?php wp_nonce_field( Admin::NONCE ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
	<input type="hidden" name="op" value="mode">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-controls-repeat"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Mode', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<fieldset class="fh-gc-radios fh-gc-radios--stack">
		<?php foreach ( Modes::MODES as $fh_m ) : ?>
			<label><input type="radio" name="mode" value="<?php echo esc_attr( $fh_m ); ?>" <?php checked( $fh_stored, $fh_m ); ?> <?php disabled( 'live' === $fh_m && $data['blockers'] && 'live' !== $fh_stored ); ?>> <?php echo esc_html( Modes::label( $fh_m ) ); ?></label>
		<?php endforeach; ?>
	</fieldset>
	<?php if ( $data['blockers'] ) : ?>
		<div class="fishotel-misc-notice"><strong><?php esc_html_e( 'Live cannot be switched on yet:', 'fishotel-misc-plugin' ); ?></strong>
			<ul class="fh-gc-list"><?php foreach ( $data['blockers'] as $fh_b ) : ?><li><?php echo esc_html( $fh_b ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php endif; ?>
	<?php if ( $data['legacy'] && 'off' !== $fh_stored ) : ?>
		<p class="fh-gc-sub"><?php esc_html_e( 'The old plugin is switched on — that is right for Shadow. Switch it off on the Plugins page (keep it installed) when you are ready for Live.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>
	<label><span><?php esc_html_e( 'Why (optional — it goes in the audit log)', 'fishotel-misc-plugin' ); ?></span><input type="text" name="reason" maxlength="500"></label>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the mode', 'fishotel-misc-plugin' ); ?></button></p>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-gc-form">
	<?php wp_nonce_field( Admin::NONCE ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
	<input type="hidden" name="op" value="settings">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-admin-generic"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Each of these is a decision for the shop owner; each starts at the safer answer. Every change is written in the audit log.', 'fishotel-misc-plugin' ); ?></p>
	<?php foreach ( Settings::YES_NO as $fh_k ) : ?>
		<label class="fh-gc-check"><input type="checkbox" name="<?php echo esc_attr( $fh_k ); ?>" value="1" <?php checked( 'yes', $fh_set[ $fh_k ] ); ?>> <?php echo esc_html( $fh_labels[ $fh_k ] ); ?></label>
	<?php endforeach; ?>
	<div class="fh-gc-grid">
		<label><span><?php echo esc_html( $fh_labels['editor_capability'] ); ?></span>
			<select name="editor_capability">
				<option value="manage_options" <?php selected( $fh_set['editor_capability'], 'manage_options' ); ?>><?php esc_html_e( 'Administrators only', 'fishotel-misc-plugin' ); ?></option>
				<option value="manage_woocommerce" <?php selected( $fh_set['editor_capability'], 'manage_woocommerce' ); ?>><?php esc_html_e( 'Shop managers too', 'fishotel-misc-plugin' ); ?></option>
			</select>
		</label>
		<label><span><?php echo esc_html( $fh_labels['custom_min'] ); ?></span><input type="text" inputmode="decimal" name="custom_min" value="<?php echo esc_attr( Money::plain( (int) $fh_set['custom_min'] ) ); ?>"></label>
		<label><span><?php echo esc_html( $fh_labels['custom_max'] ); ?></span><input type="text" inputmode="decimal" name="custom_max" value="<?php echo esc_attr( Money::plain( (int) $fh_set['custom_max'] ) ); ?>"></label>
		<label><span><?php echo esc_html( $fh_labels['redeem_tries'] ); ?></span><input type="number" min="3" max="50" name="redeem_tries" value="<?php echo esc_attr( (string) (int) $fh_set['redeem_tries'] ); ?>"></label>
		<label class="fh-gc-grid__wide"><span><?php echo esc_html( $fh_labels['email_subject'] ); ?></span><input type="text" name="email_subject" maxlength="200" value="<?php echo esc_attr( $fh_set['email_subject'] ); ?>"></label>
		<label class="fh-gc-grid__wide"><span><?php echo esc_html( $fh_labels['email_heading'] ); ?></span><input type="text" name="email_heading" maxlength="200" value="<?php echo esc_attr( $fh_set['email_heading'] ); ?>"></label>
		<label class="fh-gc-grid__wide"><span><?php echo esc_html( $fh_labels['email_body'] ); ?></span><textarea name="email_body" rows="9"><?php echo esc_textarea( $fh_set['email_body'] ); ?></textarea>
			<span class="fh-gc-sub">
			<?php
			/* translators: %s: placeholders */
			echo esc_html( sprintf( __( 'Placeholders: %s. {code} is always kept.', 'fishotel-misc-plugin' ), implode( ' ', Settings::PLACEHOLDERS ) ) );
			?>
			</span>
		</label>
	</div>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the settings', 'fishotel-misc-plugin' ); ?></button></p>
</form>
