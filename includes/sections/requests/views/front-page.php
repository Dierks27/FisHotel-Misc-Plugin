<?php
/**
 * The shell of the section's own plain pages (the private request form,
 * "my requests", keep / cancel, the pay link's messages): no theme, no
 * menu, noindex, its CSS and script inlined so the page makes no further
 * request.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string     $fh_title   Page title.
 * @var string     $fh_content The page's HTML (already escaped by its builder).
 * @var string     $fh_css     Inline stylesheet.
 * @var string     $fh_js      Inline script ('' = none).
 * @var array|null $fh_config  The script's configuration.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Urls;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'language' ) : 'en' ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $fh_title . ' — ' . Urls::site_name() ); ?></title>
<style><?php echo $fh_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the section's own stylesheet. ?></style>
</head>
<body class="fh-rq-body">
<main class="fh-rq-main">
	<p class="fh-rq-site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( Urls::site_name() ); ?></a></p>
	<?php echo $fh_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by Front. ?>
</main>
<?php if ( '' !== $fh_js ) : ?>
<script>window.fhRequestsForm = <?php echo wp_json_encode( $fh_config ); ?>;</script>
<script><?php echo $fh_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the section's own script. ?></script>
<?php endif; ?>
</body>
</html>
