<?php
/**
 * The Front Desk — a self-contained phone page: no theme, its CSS and JS
 * inlined, no external requests. The JS renders every screen from the
 * config object below and re-renders from each AJAX reply.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $screen The tank screen data (Front_Desk::screen_data()).
 * @var array  $config The JS config (ajaxUrl, nonce, screen, baseUrl, boardUrl, i18n).
 * @var string $css    css/front-desk.css.
 * @var string $js     js/front-desk.js.
 */

defined( 'ABSPATH' ) || exit;

$fh_title = ! empty( $screen['tank'] ) ? 'Tank ' . (int) $screen['tank']['number'] : __( 'Front Desk', 'fishotel-misc-plugin' );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#0f1720">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<title><?php echo esc_html( $fh_title ); ?> · FisHotel</title>
	<style><?php echo str_replace( '</style', '<\/style', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own stylesheet, inlined. ?></style>
</head>
<body class="fh-fd">
	<noscript>
		<p class="fh-fd-noscript"><?php esc_html_e( 'The Front Desk needs JavaScript.', 'fishotel-misc-plugin' ); ?></p>
	</noscript>
	<div id="fh-fd-app" class="fh-fd-app" aria-live="polite">
		<p class="fh-fd-loading"><?php echo esc_html( $fh_title ); ?> …</p>
	</div>
	<div id="fh-fd-toast" class="fh-fd-toast" hidden></div>
	<script>window.fhFrontDesk = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML-safe flags. ?>;</script>
	<script><?php echo str_replace( '</script', '<\/script', $js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- our own script, inlined. ?></script>
</body>
</html>
