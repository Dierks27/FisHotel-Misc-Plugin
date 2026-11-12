<?php
/**
 * Shop App → Settings: how long a PIN unlocks for, where that count
 * starts, how many digits a PIN has.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var array $settings Settings (session_minutes, session_mode, pin_length).
 * @var int   $usable   People who can unlock with the current PIN length.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\Pins;
use FisHotel\Misc\Sections\Shop_App\Session;
use FisHotel\Misc\Sections\Shop_App\Settings;

$fh_choices = array(
	30  => __( '30 minutes', 'fishotel-misc-plugin' ),
	60  => __( '1 hour', 'fishotel-misc-plugin' ),
	120 => __( '2 hours', 'fishotel-misc-plugin' ),
	180 => __( '3 hours', 'fishotel-misc-plugin' ),
	240 => __( '4 hours', 'fishotel-misc-plugin' ),
	360 => __( '6 hours', 'fishotel-misc-plugin' ),
	480 => __( '8 hours', 'fishotel-misc-plugin' ),
	720 => __( '12 hours', 'fishotel-misc-plugin' ),
);
$fh_minutes = (int) $settings['session_minutes'];
if ( ! isset( $fh_choices[ $fh_minutes ] ) ) {
	/* translators: %d: minutes */
	$fh_choices[ $fh_minutes ] = sprintf( __( '%d minutes', 'fishotel-misc-plugin' ), $fh_minutes );
	ksort( $fh_choices );
}
?>
<section class="fh-sa-card fh-sa-card--narrow">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-sa-form fh-sa-form--stack">
		<input type="hidden" name="action" value="fishotel_shop_app_settings">
		<?php wp_nonce_field( Admin::NONCE ); ?>

		<label>
			<span><?php esc_html_e( 'A PIN unlocks a device for', 'fishotel-misc-plugin' ); ?></span>
			<select name="session_minutes">
				<?php foreach ( $fh_choices as $fh_value => $fh_label ) : ?>
					<option value="<?php echo esc_attr( (string) (int) $fh_value ); ?>"<?php selected( $fh_minutes, (int) $fh_value ); ?>><?php echo esc_html( $fh_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<p class="fh-sa-help">
			<?php
			/* translators: 1: shortest, 2: longest (minutes) */
			echo esc_html( sprintf( __( 'From %1$d minutes to %2$d minutes (12 hours).', 'fishotel-misc-plugin' ), Settings::MIN_MINUTES, Settings::MAX_MINUTES ) );
			?>
		</p>

		<fieldset class="fh-sa-fieldset">
			<legend><?php esc_html_e( 'Counted from', 'fishotel-misc-plugin' ); ?></legend>
			<label class="fh-sa-radio">
				<input type="radio" name="session_mode" value="<?php echo esc_attr( Session::MODE_LAST_USE ); ?>"<?php checked( Session::MODE_UNLOCK !== $settings['session_mode'] ); ?>>
				<span><?php esc_html_e( 'the last use — a device in use stays unlocked; it locks that long after it was last touched', 'fishotel-misc-plugin' ); ?></span>
			</label>
			<label class="fh-sa-radio">
				<input type="radio" name="session_mode" value="<?php echo esc_attr( Session::MODE_UNLOCK ); ?>"<?php checked( Session::MODE_UNLOCK === $settings['session_mode'] ); ?>>
				<span><?php esc_html_e( 'the unlock — it locks that long after the PIN, in use or not', 'fishotel-misc-plugin' ); ?></span>
			</label>
		</fieldset>

		<label>
			<span><?php esc_html_e( 'A PIN has', 'fishotel-misc-plugin' ); ?></span>
			<select name="pin_length">
				<?php for ( $fh_n = Pins::MIN_LENGTH; $fh_n <= Pins::MAX_LENGTH; $fh_n++ ) : ?>
					<option value="<?php echo esc_attr( (string) $fh_n ); ?>"<?php selected( (int) $settings['pin_length'], $fh_n ); ?>>
						<?php
						/* translators: %d: number of digits */
						echo esc_html( sprintf( __( '%d digits', 'fishotel-misc-plugin' ), $fh_n ) );
						?>
					</option>
				<?php endfor; ?>
			</select>
		</label>
		<p class="fh-sa-help"><?php esc_html_e( 'The keypad sends a PIN the moment its last digit is pressed, so every PIN has this many digits. After changing it, each person needs a new PIN before they can unlock again (People shows who).', 'fishotel-misc-plugin' ); ?></p>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fishotel-misc-plugin' ); ?></button></p>
	</form>
</section>

<section class="fh-sa-card fh-sa-card--narrow">
	<h2 class="fh-sa-h2"><?php esc_html_e( 'The site secret', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-sa-help">
		<?php
		if ( Settings::secret_from_constant() ) {
			esc_html_e( 'PINs are hashed with the secret in wp-config.php (FISHOTEL_SHOP_APP_SECRET). Changing or removing that line makes every PIN unusable until it is set again.', 'fishotel-misc-plugin' );
		} else {
			esc_html_e( 'PINs are hashed with a per-person salt and a secret stored with this site\'s options. To keep the secret out of the database, define FISHOTEL_SHOP_APP_SECRET (32 characters or more) in wp-config.php — before the first PIN is given: changing the secret makes every PIN unusable until it is set again.', 'fishotel-misc-plugin' );
		}
		?>
	</p>
</section>
