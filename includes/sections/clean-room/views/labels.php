<?php
/**
 * Printable tank labels — one per active tank: the number large, the
 * area / building / size small, a QR of the Front Desk URL (inline SVG,
 * nothing loaded from anywhere). Admin only (admin-post, nonce'd).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $labels number, line, url, svg.
 * @var string  $site   Site name.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Tank labels', 'fishotel-misc-plugin' ); ?> · <?php echo esc_html( $site ); ?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin: 0; padding: 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #000; background: #fff; }
		.toolbar { display: flex; gap: 12px; align-items: center; margin: 0 0 16px; font-size: 14px; }
		.toolbar button { font: inherit; padding: 8px 16px; cursor: pointer; }
		.sheet { display: grid; grid-template-columns: repeat(2, 3.5in); gap: 0.2in; }
		.label { width: 3.5in; height: 2in; padding: 0.15in; border: 1px dashed #bbb; display: flex; align-items: center; gap: 0.15in; break-inside: avoid; page-break-inside: avoid; }
		.label svg { width: 1.55in; height: 1.55in; flex: 0 0 auto; }
		.label .text { flex: 1 1 auto; min-width: 0; }
		.label .number { font-size: 64px; font-weight: 800; line-height: 1; letter-spacing: -2px; }
		.label .line { margin-top: 6px; font-size: 12px; line-height: 1.3; }
		.label .url { margin-top: 4px; font-size: 8px; color: #555; word-break: break-all; }
		.missing { font-size: 10px; color: #a00; }
		@media print {
			body { padding: 0; }
			.toolbar { display: none; }
			.label { border-color: #ddd; }
		}
	</style>
</head>
<body>
	<div class="toolbar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'fishotel-misc-plugin' ); ?></button>
		<span><?php echo esc_html( sprintf( /* translators: %d: labels */ _n( '%d label — 2 × 3.5in labels per row; set the printer to actual size.', '%d labels — 2 × 3.5in labels per row; set the printer to actual size.', count( $labels ), 'fishotel-misc-plugin' ), count( $labels ) ) ); ?></span>
	</div>
	<div class="sheet">
		<?php foreach ( $labels as $fh_label ) : ?>
			<div class="label">
				<?php if ( '' !== $fh_label['svg'] ) : ?>
					<?php echo $fh_label['svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by Qr::svg() from integers only. ?>
				<?php else : ?>
					<span class="missing"><?php esc_html_e( 'URL too long for a QR', 'fishotel-misc-plugin' ); ?></span>
				<?php endif; ?>
				<div class="text">
					<div class="number"><?php echo esc_html( $fh_label['number'] ); ?></div>
					<div class="line"><?php echo esc_html( $fh_label['line'] ); ?></div>
					<div class="url"><?php echo esc_html( $fh_label['url'] ); ?></div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</body>
</html>
