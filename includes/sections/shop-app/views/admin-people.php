<?php
/**
 * Shop App → People: a name, a PIN, the account the work is recorded
 * under, on or off. A PIN is typed here in plain digits — it is typed
 * once, and a slip nobody saw would leave a PIN nobody knows — and never
 * shown again once saved.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var array[]    $people   People rows (active first).
 * @var array|null $editing  The person being edited, or null (the form adds one).
 * @var array|null $form     What a refused save gave back: id, errors, input (name, user_id).
 * @var array      $accounts User id => name: the accounts the form may offer.
 * @var array      $settings Settings.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\People;

$fh_length  = (int) $settings['pin_length'];
$fh_errors  = is_array( $form ) && ! empty( $form['errors'] ) ? (array) $form['errors'] : array();
$fh_typed   = is_array( $form ) && isset( $form['input'] ) && is_array( $form['input'] ) ? $form['input'] : array();
$fh_name    = isset( $fh_typed['name'] ) ? (string) $fh_typed['name'] : ( $editing ? (string) $editing['name'] : '' );
$fh_account = isset( $fh_typed['user_id'] ) ? (int) $fh_typed['user_id'] : ( $editing ? (int) $editing['user_id'] : 0 );
$fh_has_pin = $editing && ! empty( $editing['active'] ) && '' !== (string) $editing['pin_hash'];
?>
<div class="fh-sa-columns">

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2">
			<?php
			if ( $editing ) {
				/* translators: %s: the person's name */
				echo esc_html( sprintf( __( 'Change %s', 'fishotel-misc-plugin' ), $editing['name'] ) );
			} else {
				esc_html_e( 'Add a person', 'fishotel-misc-plugin' );
			}
			?>
		</h2>

		<?php if ( $fh_errors ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--error" role="alert">
				<?php foreach ( $fh_errors as $fh_error ) : ?>
					<div><?php echo esc_html( $fh_error ); ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-sa-form fh-sa-form--stack" autocomplete="off">
			<input type="hidden" name="action" value="fishotel_shop_app_person">
			<input type="hidden" name="person" value="<?php echo esc_attr( (string) ( $editing ? (int) $editing['id'] : 0 ) ); ?>">
			<?php wp_nonce_field( Admin::NONCE ); ?>

			<label>
				<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" name="name" maxlength="80" required value="<?php echo esc_attr( $fh_name ); ?>">
			</label>

			<label>
				<span>
					<?php
					if ( $fh_has_pin ) {
						/* translators: %d: number of digits */
						echo esc_html( sprintf( __( 'New PIN (%d digits) — leave empty to keep the one they have', 'fishotel-misc-plugin' ), $fh_length ) );
					} else {
						/* translators: %d: number of digits */
						echo esc_html( sprintf( __( 'PIN (%d digits)', 'fishotel-misc-plugin' ), $fh_length ) );
					}
					?>
				</span>
				<input type="text" name="pin" class="fh-sa-pin" inputmode="numeric" pattern="[0-9]{<?php echo esc_attr( (string) $fh_length ); ?>}" minlength="<?php echo esc_attr( (string) $fh_length ); ?>" maxlength="<?php echo esc_attr( (string) $fh_length ); ?>" autocomplete="off" spellcheck="false"<?php echo $fh_has_pin ? '' : ' required'; ?>>
			</label>

			<label>
				<span><?php esc_html_e( 'Their work is recorded under', 'fishotel-misc-plugin' ); ?></span>
				<select name="user_id">
					<option value="0"<?php selected( $fh_account, 0 ); ?>><?php esc_html_e( 'Shop staff (the shared account)', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $accounts as $fh_id => $fh_label ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $fh_id ); ?>"<?php selected( $fh_account, (int) $fh_id ); ?>><?php echo esc_html( $fh_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<p class="fh-sa-help"><?php esc_html_e( 'Pick their own WordPress account when they have one, so the Clean Room shows their name on what they do. Everyone else shares the "Shop staff" account, which cannot log in to WordPress. Either way a PIN opens the fish-room screens only — never wp-admin, orders or customers.', 'fishotel-misc-plugin' ); ?></p>

			<p>
				<button type="submit" class="button button-primary"><?php echo esc_html( $editing && empty( $editing['active'] ) ? __( 'Save and switch on', 'fishotel-misc-plugin' ) : __( 'Save', 'fishotel-misc-plugin' ) ); ?></button>
				<?php if ( $editing ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::page_url( 'people' ) ); ?>"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
	</section>

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'About PINs', 'fishotel-misc-plugin' ); ?></h2>
		<ul class="fh-sa-list">
			<li><?php esc_html_e( 'A PIN works only on a device set up under Devices.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Every person needs a PIN of their own: it is how the app knows who is working. When a PIN is refused with "Choose a different PIN", pick another.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'The digits show while you type them, so you can see what you set. Once saved a PIN is never shown again and cannot be looked up — only replaced.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'A new PIN, or another account, ends the session that person has open: they unlock again with the new PIN.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Switching a person off destroys their PIN and ends their session. Switching them back on needs a new PIN.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'One person with one PIN is a complete setup.', 'fishotel-misc-plugin' ); ?></li>
		</ul>
	</section>

</div>

<h2 class="fh-sa-h2"><?php esc_html_e( 'People', 'fishotel-misc-plugin' ); ?></h2>

<?php if ( ! $people ) : ?>
	<div class="fishotel-misc-empty"><span class="dashicons dashicons-groups"></span><p><?php esc_html_e( 'Nobody yet. Add the first person above.', 'fishotel-misc-plugin' ); ?></p></div>
<?php else : ?>
	<table class="widefat striped fh-sa-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'PIN', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Recorded under', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Last unlock', 'fishotel-misc-plugin' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $people as $fh_person ) : ?>
				<?php
				$fh_pin     = People::pin_state( $fh_person, $settings );
				$fh_account = People::account_label( $fh_person );
				?>
				<tr class="<?php echo empty( $fh_person['active'] ) ? 'is-revoked' : ''; ?>">
					<td>
						<strong><?php echo esc_html( $fh_person['name'] ); ?></strong>
						<?php if ( empty( $fh_person['active'] ) ) : ?>
							<span class="fh-sa-pill fh-sa-pill--off"><?php esc_html_e( 'switched off', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</td>
					<td><span class="<?php echo 'ok' === $fh_pin['state'] ? '' : 'fh-sa-warn-text'; ?>"><?php echo esc_html( $fh_pin['label'] ); ?></span></td>
					<td><span class="<?php echo $fh_account['missing'] ? 'fh-sa-warn-text' : ''; ?>"><?php echo esc_html( $fh_account['label'] ); ?></span></td>
					<td><?php echo esc_html( Admin::when( $fh_person['last_unlock_at'] ) ); ?></td>
					<td class="fh-sa-actions">
						<a class="button" href="<?php echo esc_url( Admin::page_url( 'people', array( 'edit' => (int) $fh_person['id'] ) ) ); ?>"><?php echo esc_html( empty( $fh_person['active'] ) ? __( 'Switch on…', 'fishotel-misc-plugin' ) : __( 'Change…', 'fishotel-misc-plugin' ) ); ?></a>
						<?php if ( ! empty( $fh_person['active'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-sa-confirm" data-confirm="off">
								<input type="hidden" name="action" value="fishotel_shop_app_person_off">
								<input type="hidden" name="person" value="<?php echo esc_attr( (string) (int) $fh_person['id'] ); ?>">
								<?php wp_nonce_field( Admin::NONCE ); ?>
								<button type="submit" class="button fh-sa-danger"><?php esc_html_e( 'Switch off', 'fishotel-misc-plugin' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
