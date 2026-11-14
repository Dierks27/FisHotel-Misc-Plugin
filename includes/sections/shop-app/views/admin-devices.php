<?php
/**
 * Shop App → Devices: set up this device, make a one-time link for
 * another one, and the list.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var array[]  $devices  Device rows.
 * @var array[]  $people   People by id.
 * @var int      $this_id  The id of the device this browser is (0 = none).
 * @var object   $me       The current WordPress user.
 * @var string   $logout   The log-out address (back to the app).
 * @var bool     $https_ok Whether the site is served over HTTPS.
 * @var string   $app_url  The app's address.
 * @var array    $settings Settings.
 * @var int      $now      Timestamp.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Admin;
use FisHotel\Misc\Sections\Shop_App\Lockout;
use FisHotel\Misc\Sections\Shop_App\Store;

$fh_this_row = null;
foreach ( $devices as $fh_device ) {
	if ( $this_id > 0 && (int) $fh_device['id'] === (int) $this_id ) {
		$fh_this_row = $fh_device;
	}
}
?>
<div class="fh-sa-columns">

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'Set up this device', 'fishotel-misc-plugin' ); ?></h2>
		<?php if ( $fh_this_row ) : ?>
			<p class="fh-sa-ok">
				<?php
				/* translators: %s: the device's name */
				echo esc_html( sprintf( __( 'This device is set up as "%s".', 'fishotel-misc-plugin' ), $fh_this_row['name'] ) );
				?>
			</p>
			<p class="fh-sa-help">
				<?php
				/* translators: %s: a WordPress user's name */
				echo esc_html( sprintf( __( 'You are still logged in to WordPress here as %s. If this device stays in the shop, log out of WordPress on it — otherwise the tank labels open without a PIN, as you.', 'fishotel-misc-plugin' ), $me->display_name ) );
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $app_url ); ?>"><?php esc_html_e( 'Open the app', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" href="<?php echo esc_url( $logout ); ?>"><?php esc_html_e( 'Log out of WordPress on this device and open the app', 'fishotel-misc-plugin' ); ?></a>
			</p>
		<?php elseif ( ! $https_ok ) : ?>
			<p class="fh-sa-help"><?php esc_html_e( 'Needs HTTPS.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<p class="fh-sa-help"><?php esc_html_e( 'For the phone or tablet you are holding now: it becomes a shop device, and the people of the shop unlock it with their PIN. Nothing else on it changes.', 'fishotel-misc-plugin' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-sa-form">
				<input type="hidden" name="action" value="fishotel_shop_app_enrol_here">
				<?php wp_nonce_field( Admin::NONCE ); ?>
				<label>
					<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" name="name" maxlength="80" required placeholder="<?php esc_attr_e( 'Shop tablet', 'fishotel-misc-plugin' ); ?>">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Set up this device', 'fishotel-misc-plugin' ); ?></button>
			</form>
		<?php endif; ?>
	</section>

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'Set up another device', 'fishotel-misc-plugin' ); ?></h2>
		<?php if ( ! $https_ok ) : ?>
			<p class="fh-sa-help"><?php esc_html_e( 'Needs HTTPS.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<p class="fh-sa-help"><?php esc_html_e( 'Makes a link and a QR code that work once, for ten minutes. Open it on the phone or tablet (scan the code with its camera): one tap sets it up, and nobody types a WordPress password on it.', 'fishotel-misc-plugin' ); ?></p>
			<form class="fh-sa-form" id="fh-sa-link-form">
				<label>
					<span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span>
					<input type="text" name="name" maxlength="80" required placeholder="<?php esc_attr_e( 'Shop tablet', 'fishotel-misc-plugin' ); ?>">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Make a one-time link', 'fishotel-misc-plugin' ); ?></button>
			</form>
			<noscript><p class="fh-sa-help"><?php esc_html_e( 'Making a link needs JavaScript.', 'fishotel-misc-plugin' ); ?></p></noscript>
			<div id="fh-sa-link" class="fh-sa-link" hidden>
				<p class="fh-sa-link__status" role="status"></p>
				<img class="fh-sa-link__qr" alt="<?php esc_attr_e( 'QR code of the one-time link', 'fishotel-misc-plugin' ); ?>" hidden>
				<p class="fh-sa-link__note fh-sa-help" hidden></p>
				<input type="text" class="fh-sa-link__url" readonly aria-label="<?php esc_attr_e( 'The one-time link', 'fishotel-misc-plugin' ); ?>">
				<button type="button" class="button fh-sa-link__copy"><?php esc_html_e( 'Copy the link', 'fishotel-misc-plugin' ); ?></button>
			</div>
		<?php endif; ?>
	</section>

</div>

<h2 class="fh-sa-h2"><?php esc_html_e( 'Devices', 'fishotel-misc-plugin' ); ?></h2>

<?php if ( ! $devices ) : ?>
	<div class="fishotel-misc-empty"><span class="dashicons dashicons-smartphone"></span><p><?php esc_html_e( 'No device is set up yet.', 'fishotel-misc-plugin' ); ?></p></div>
<?php else : ?>
	<table class="widefat striped fh-sa-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Device', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Enrolled by', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Unlocked by', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Locked out', 'fishotel-misc-plugin' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $devices as $fh_device ) : ?>
				<?php
				$fh_status   = (string) $fh_device['status'];
				$fh_lock     = Lockout::status( $fh_device, $now );
				$fh_unlocked = Admin::unlocked_by( $fh_device, $people, $settings, $now );
				?>
				<tr class="<?php echo Store::STATUS_REVOKED === $fh_status ? 'is-revoked' : ''; ?>">
					<td>
						<strong><?php echo esc_html( $fh_device['name'] ); ?></strong>
						<?php if ( $this_id > 0 && (int) $fh_device['id'] === (int) $this_id ) : ?>
							<span class="fh-sa-pill fh-sa-pill--this"><?php esc_html_e( 'this device', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
						<?php if ( Store::STATUS_PENDING === $fh_status ) : ?>
							<span class="fh-sa-pill fh-sa-pill--wait">
								<?php
								/* translators: %s: time */
								echo esc_html( sprintf( __( 'waiting for its link — good until %s', 'fishotel-misc-plugin' ), Admin::when( $fh_device['enrol_expires'] ) ) );
								?>
							</span>
						<?php elseif ( Store::STATUS_REVOKED === $fh_status ) : ?>
							<span class="fh-sa-pill fh-sa-pill--off">
								<?php
								/* translators: 1: time, 2: user */
								echo esc_html( sprintf( __( 'revoked %1$s by %2$s', 'fishotel-misc-plugin' ), Admin::when( $fh_device['revoked_at'] ), Admin::user_name( $fh_device['revoked_by'] ) ) );
								?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( Admin::user_name( $fh_device['enrolled_by'] ) ); ?>
						<?php if ( $fh_device['enrolled_at'] ) : ?>
							<br><span class="fh-sa-muted"><?php echo esc_html( Admin::when( $fh_device['enrolled_at'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( Admin::when( $fh_device['last_used_at'] ) ); ?></td>
					<td>
						<?php if ( $fh_unlocked ) : ?>
							<?php echo esc_html( $fh_unlocked['name'] ); ?>
							<br><span class="fh-sa-muted">
								<?php
								/* translators: %s: time */
								echo esc_html( sprintf( __( 'since %s', 'fishotel-misc-plugin' ), Admin::when( $fh_unlocked['since'] ) ) );
								?>
							</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<?php if ( Store::STATUS_ACTIVE !== $fh_status ) : ?>
							—
						<?php elseif ( $fh_lock['locked'] ) : ?>
							<span class="fh-sa-bad">
								<?php
								/* translators: 1: how long, 2: time */
								echo esc_html( sprintf( __( 'Yes — %1$s more (until %2$s)', 'fishotel-misc-plugin' ), Lockout::human( $fh_lock['seconds'] ), Admin::when( $fh_device['locked_until'] ) ) );
								?>
							</span>
						<?php else : ?>
							<?php esc_html_e( 'No', 'fishotel-misc-plugin' ); ?>
							<?php if ( $fh_device['fail_count'] > 0 ) : ?>
								<br><span class="fh-sa-muted">
									<?php
									/* translators: 1: wrong PINs so far, 2: the limit */
									echo esc_html( sprintf( __( '%1$d of %2$d wrong PINs', 'fishotel-misc-plugin' ), (int) $fh_device['fail_count'], Lockout::MAX_ATTEMPTS ) );
									?>
								</span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="fh-sa-actions">
						<?php if ( Store::STATUS_ACTIVE === $fh_status && ( $fh_lock['locked'] || $fh_device['lock_level'] > 0 || $fh_device['fail_count'] > 0 ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="fishotel_shop_app_clear_lockout">
								<input type="hidden" name="device" value="<?php echo esc_attr( (string) (int) $fh_device['id'] ); ?>">
								<?php wp_nonce_field( Admin::NONCE ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Clear lockout', 'fishotel-misc-plugin' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( Store::STATUS_REVOKED !== $fh_status ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-sa-confirm" data-confirm="confirm">
								<input type="hidden" name="action" value="fishotel_shop_app_revoke">
								<input type="hidden" name="device" value="<?php echo esc_attr( (string) (int) $fh_device['id'] ); ?>">
								<?php wp_nonce_field( Admin::NONCE ); ?>
								<button type="submit" class="button fh-sa-danger"><?php echo esc_html( Store::STATUS_PENDING === $fh_status ? __( 'Cancel the link', 'fishotel-misc-plugin' ) : __( 'Revoke', 'fishotel-misc-plugin' ) ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="fh-sa-help">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: wrong PINs, 2: first lockout, 3: longest lockout, 4: how long wrong PINs stay counted */
				__( 'After %1$d wrong PINs within %4$s a device is locked for %2$s; every lockout after that doubles, up to %3$s, and starts over once a day has passed. A correct PIN does not clear the wrong ones before it, and every wrong PIN is in the log. A revoked device stops working at once.', 'fishotel-misc-plugin' ),
				Lockout::MAX_ATTEMPTS,
				Lockout::human( Lockout::BASE_SECONDS ),
				Lockout::human( Lockout::CAP_SECONDS ),
				Lockout::human( Lockout::FAIL_MEMORY )
			)
		);
		?>
	</p>
<?php endif; ?>
