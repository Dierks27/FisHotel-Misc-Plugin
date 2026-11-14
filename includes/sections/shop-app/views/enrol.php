<?php
/**
 * The page behind a one-time enrolment link. Opening the link shows one
 * button and uses nothing up; the button posts the link's token, which
 * uses it up and sets the device cookie. No script, nothing stored.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var string $state  confirm|expired|https.
 * @var string $name   The device's name (confirm).
 * @var string $token  The link's token (confirm; '' otherwise).
 * @var string $action Where the button posts.
 * @var string $app    The app's address.
 * @var string $css    css/app.css.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<meta name="theme-color" content="<?php echo esc_attr( \FisHotel\Misc\Sections\Shop_App\App::THEME_COLOR ); ?>">
	<title><?php esc_html_e( 'Set up this device', 'fishotel-misc-plugin' ); ?> · FisHotel Shop</title>
	<style><?php echo str_replace( '</style', '<\/style', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own stylesheet, inlined. ?></style>
</head>
<body class="fh-sa">
	<div class="fh-sa-enrol">
		<div class="fh-sa-brand">FisHotel Shop</div>
		<?php if ( 'confirm' === $state ) : ?>
			<h1><?php esc_html_e( 'Set up this device?', 'fishotel-misc-plugin' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: the device's name */
					esc_html__( 'It will be enrolled for the FisHotel Shop app as %s. The people of the shop then unlock it with their PIN.', 'fishotel-misc-plugin' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<input type="hidden" name="k" value="<?php echo esc_attr( $token ); ?>">
				<button type="submit" class="fh-sa-btn fh-sa-btn--primary"><?php esc_html_e( 'Set up this device', 'fishotel-misc-plugin' ); ?></button>
			</form>
			<p><?php esc_html_e( 'This link works once, and for ten minutes.', 'fishotel-misc-plugin' ); ?></p>
		<?php elseif ( 'https' === $state ) : ?>
			<h1><?php esc_html_e( 'The Shop App needs HTTPS', 'fishotel-misc-plugin' ); ?></h1>
			<p><?php esc_html_e( 'A device can only be set up at an https:// address.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<h1><?php esc_html_e( 'This link no longer works', 'fishotel-misc-plugin' ); ?></h1>
			<p><?php esc_html_e( 'It was already used, or its ten minutes are over. Ask for a new one: FisHotel Tools → Shop App → Devices.', 'fishotel-misc-plugin' ); ?></p>
			<a class="fh-sa-btn fh-sa-btn--quiet" href="<?php echo esc_url( $app ); ?>"><?php esc_html_e( 'Open the app', 'fishotel-misc-plugin' ); ?></a>
		<?php endif; ?>
	</div>
</body>
</html>
