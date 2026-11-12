<?php
/**
 * Shop App → Install: the app's address and how to put it on a phone or
 * a tablet.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var string $app_url  The app's address.
 * @var bool   $https_ok Whether the site is served over HTTPS.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Admin;

$fh_qr = Admin::qr_data_uri( $app_url );
?>
<div class="fh-sa-columns">

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'What it is', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fh-sa-help"><?php esc_html_e( 'FisHotel Shop is not an app from a store. It is this website, installed: the browser gives it its own icon and a full-screen window with no address bar, on Android and on an iPhone or iPad alike. There is nothing to download or update — it changes when the site does.', 'fishotel-misc-plugin' ); ?></p>
		<p class="fh-sa-help">
			<?php esc_html_e( 'Its address:', 'fishotel-misc-plugin' ); ?>
			<br><a href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $app_url ); ?></code></a>
		</p>
		<?php if ( '' !== $fh_qr ) : ?>
			<img class="fh-sa-link__qr" src="<?php echo esc_attr( $fh_qr ); ?>" alt="<?php esc_attr_e( 'QR code of the app\'s address', 'fishotel-misc-plugin' ); ?>">
			<p class="fh-sa-help"><?php esc_html_e( 'This code only opens the app. It does not set a device up — that is the one-time link under Devices.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
		<?php if ( ! $https_ok ) : ?>
			<p class="fh-sa-bad"><?php esc_html_e( 'The Shop App needs HTTPS: it cannot be installed from this site as it is served now.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
		<p class="fh-sa-help"><?php esc_html_e( 'The tank labels keep working as they always did: scanned on a shop device they open the tank after the PIN, anywhere else they ask for a WordPress login.', 'fishotel-misc-plugin' ); ?></p>
	</section>

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'Android (Chrome)', 'fishotel-misc-plugin' ); ?></h2>
		<ol class="fh-sa-list">
			<li>
				<?php
				printf(
					/* translators: %s: a link to the Devices tab */
					esc_html__( 'Set the phone or tablet up first: %s → "Make a one-time link", then scan the code with its camera and tap "Set up this device".', 'fishotel-misc-plugin' ),
					'<a href="' . esc_url( Admin::page_url( 'devices' ) ) . '">' . esc_html__( 'Devices', 'fishotel-misc-plugin' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'The app opens on the keypad. Tap "Install app" under it — or the ⋮ menu → "Install app" / "Add to Home screen".', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Open "FisHotel Shop" from its icon. It stays set up: installing uses the same browser storage.', 'fishotel-misc-plugin' ); ?></li>
		</ol>
	</section>

	<section class="fh-sa-card">
		<h2 class="fh-sa-h2"><?php esc_html_e( 'iPhone and iPad (Safari)', 'fishotel-misc-plugin' ); ?></h2>
		<ol class="fh-sa-list">
			<li><?php esc_html_e( 'Open the app\'s address in Safari.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Tap Share (the square with the arrow) → "Add to Home Screen" → Add.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Open "FisHotel Shop" from its icon. A Home Screen app keeps its own storage on an iPhone, so it may say "This device is not set up": tap "Set up with a manager login" there, log in, and press "Set up this device" — then log out of WordPress on it with the button that page offers.', 'fishotel-misc-plugin' ); ?></li>
		</ol>
		<p class="fh-sa-help"><?php esc_html_e( 'The "Scan a tank label" button needs a browser that can read codes from the camera (Chrome on Android can). Where it cannot, the button is not shown and the tanks are picked from the list; the phone\'s own camera app still opens a label.', 'fishotel-misc-plugin' ); ?></p>
	</section>

</div>
