<?php
/**
 * The Shop App's shell — ONE static page: its CSS and JS inlined, no
 * name, no tank and no nonce in it, the same bytes for every visitor.
 * The script asks /?fh_shop_app=state what this device is and draws the
 * keypad or the home screen from the answer. That it is static is what
 * lets the service worker keep a copy of it.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var array  $config   The script's config (mode, urls, header, labelVar, i18n) — nothing personal.
 * @var string $css      css/app.css.
 * @var string $js       js/app.js.
 * @var string $manifest The manifest's address.
 * @var string $icon     The 192 px icon's address.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="<?php echo esc_attr( \FisHotel\Misc\Sections\Shop_App\App::THEME_COLOR ); ?>">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="FisHotel Shop">
	<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icon ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $icon ); ?>">
	<title>FisHotel Shop</title>
	<style><?php echo str_replace( '</style', '<\/style', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own stylesheet, inlined. ?></style>
</head>
<body class="fh-sa">
	<noscript>
		<p class="fh-sa-noscript"><?php esc_html_e( 'The Shop App needs JavaScript.', 'fishotel-misc-plugin' ); ?></p>
	</noscript>
	<div id="fh-sa-app" class="fh-sa-app" aria-live="polite">
		<p class="fh-sa-loading">FisHotel Shop …</p>
	</div>
	<script>window.fhShopApp = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML-safe flags. ?>;</script>
	<script><?php echo str_replace( '</script', '<\/script', $js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own script, inlined. ?></script>
</body>
</html>
