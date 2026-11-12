<?php
/**
 * The printable cut sheet — a document of its own (no admin chrome,
 * nothing loaded from anywhere): one source per page with its drawing to
 * scale, the cuts in order, the pieces and the labels to write on the new
 * leftovers; then a last page with the pieces per box.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var array  $plan    Plan row.
 * @var array  $data    Its data.
 * @var array  $state   Stock::state(): stale, undo_blockers.
 * @var string $link    A fresh URL of this page for the current user.
 * @var string $site    Site name.
 * @var bool   $confirm True = the link's nonce did not check out: show a button, not the sheet.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Svg;

$fh_sources = isset( $data['sources'] ) ? (array) $data['sources'] : array();
$fh_totals  = isset( $data['totals'] ) ? (array) $data['totals'] : array();
$fh_for     = isset( $data['for'] ) && is_array( $data['for'] ) ? $data['for'] : array();
$fh_count   = count( $fh_sources );
/* translators: %d: plan number */
$fh_title = sprintf( __( 'Foam cut sheet — plan %d', 'fishotel-misc-plugin' ), (int) $plan['id'] );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $fh_title ); ?> · <?php echo esc_html( $site ); ?></title>
	<style>
		* { box-sizing: border-box; }
		@page { size: letter landscape; margin: 0.4in; }
		body { margin: 0; padding: 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 12px; line-height: 1.35; color: #000; background: #fff; }
		.toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 0 0 16px; padding: 10px 12px; background: #f0f0f1; border-radius: 6px; font-size: 14px; }
		.toolbar button, .toolbar a.go { font: inherit; padding: 8px 18px; cursor: pointer; border: 1px solid #2271b1; background: #2271b1; color: #fff; border-radius: 4px; text-decoration: none; }
		.note { margin: 0 0 10px; padding: 8px 10px; border: 2px solid #b32d2e; border-radius: 4px; font-weight: 600; }
		.note.soft { border-color: #dba617; font-weight: 400; }
		.sheet { page-break-after: always; break-after: page; margin: 0 0 28px; }
		.sheet:last-of-type { page-break-after: auto; break-after: auto; }
		.head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; border-bottom: 2px solid #000; padding-bottom: 4px; margin-bottom: 8px; }
		.head h1 { margin: 0; font-size: 20px; }
		.head .meta { font-size: 12px; color: #333; }
		.head .right { margin-left: auto; font-size: 11px; color: #555; }
		.drawing svg { display: block; width: auto; height: auto; max-width: 100%; max-height: 4.2in; }
		.lists { display: flex; gap: 22px; margin-top: 8px; }
		.lists > div { flex: 1 1 0; min-width: 0; }
		h2 { margin: 8px 0 3px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
		ol, ul { margin: 0 0 6px; padding-left: 20px; }
		ul.cols { column-count: 2; column-gap: 20px; }
		ol.cuts { column-count: 3; column-gap: 22px; font-size: 10px; line-height: 1.3; }
		ol.cuts li, ul.cols li { break-inside: avoid; margin-bottom: 2px; }
		li.box { list-style: none; margin-left: -20px; }
		li.box::before { content: "\2610\00a0"; font-size: 13px; }
		.label { display: inline-block; min-width: 34px; padding: 0 6px; border: 2px solid #000; border-radius: 3px; font-weight: 800; text-align: center; }
		table { border-collapse: collapse; width: 100%; }
		th, td { border: 1px solid #999; padding: 3px 6px; text-align: left; vertical-align: top; }
		th { background: #eee; }
		td.num, th.num { text-align: right; white-space: nowrap; }
		.foot { margin: 6px 0 0; font-size: 11px; color: #333; }
		@media print {
			body { padding: 0; }
			.toolbar { display: none; }
		}
	</style>
</head>
<body>
<?php if ( $confirm ) : ?>
	<div class="toolbar">
		<span>
			<?php
			/* translators: %d: plan number */
			echo esc_html( sprintf( __( 'This link to the cut sheet of plan %d has expired or was made for someone else.', 'fishotel-misc-plugin' ), (int) $plan['id'] ) );
			?>
		</span>
		<a class="go" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Open the cut sheet', 'fishotel-misc-plugin' ); ?></a>
	</div>
<?php else : ?>
	<div class="toolbar">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'fishotel-misc-plugin' ); ?></button>
		<span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of pages with a drawing */
					_n( '%d page with a drawing, then the pieces per box. Landscape; "fit to page" is fine — the drawing carries its own ruler.', '%d pages with a drawing, then the pieces per box. Landscape; "fit to page" is fine — every drawing carries its own ruler.', $fh_count, 'fishotel-misc-plugin' ),
					$fh_count
				)
			);
			?>
		</span>
	</div>

	<?php if ( $state['stale'] ) : ?>
		<p class="note">
			<?php esc_html_e( 'DO NOT CUT FROM THIS SHEET: the stock has changed since this plan was made.', 'fishotel-misc-plugin' ); ?>
			<?php echo esc_html( implode( ' ', $state['stale'] ) ); ?>
			<?php esc_html_e( 'Make the plan again.', 'fishotel-misc-plugin' ); ?>
		</p>
	<?php endif; ?>
	<?php if ( 'cut' === $plan['status'] ) : ?>
		<p class="note soft">
			<?php
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'This plan was marked as cut on %s — this is a reprint.', 'fishotel-misc-plugin' ), Admin::when( $plan['cut_at'] ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php foreach ( $fh_sources as $fh_i => $fh_source ) : ?>
		<section class="sheet">
			<div class="head">
				<h1><?php echo esc_html( $fh_source['label'] ); ?></h1>
				<span class="meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: size, 2: pieces, 3: cuts, 4: waste percent */
							__( '%1$s in · %2$d pieces · %3$d cuts · waste %4$s%%', 'fishotel-misc-plugin' ),
							Svg::size( $fh_source['length'], $fh_source['width'] ),
							count( $fh_source['pieces'] ),
							count( $fh_source['cuts'] ),
							number_format_i18n( (float) $fh_source['waste_pct'], 1 )
						)
					);
					?>
				</span>
				<span class="right">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: plan number, 2: what it is for, 3: page, 4: pages */
							__( 'Plan %1$d · %2$s · source %3$d of %4$d', 'fishotel-misc-plugin' ),
							(int) $plan['id'],
							$plan['title'],
							$fh_i + 1,
							$fh_count
						)
					);
					?>
				</span>
			</div>

			<div class="drawing">
				<?php echo Svg::source( $fh_source ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Svg::source(): numbers, and texts escaped for XML. ?>
			</div>

			<div class="lists">
				<div>
					<h2><?php esc_html_e( 'Pieces', 'fishotel-misc-plugin' ); ?></h2>
					<ul class="cols">
						<?php foreach ( Admin::group_pieces( $fh_source['pieces'] ) as $fh_group ) : ?>
							<li class="box"><?php echo esc_html( $fh_group['count'] . ' × ' . $fh_group['box'] . ' · ' . $fh_group['name'] . ' — ' . $fh_group['size'] . ' in' ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div>
					<h2><?php esc_html_e( 'Write on the leftovers', 'fishotel-misc-plugin' ); ?></h2>
					<?php if ( $fh_source['leftovers'] ) : ?>
						<ul class="cols">
							<?php foreach ( $fh_source['leftovers'] as $fh_left ) : ?>
								<li class="box"><span class="label"><?php echo esc_html( $fh_left['label'] ); ?></span> <?php echo esc_html( Svg::size( $fh_left['w'], $fh_left['h'] ) . ' in' ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e( 'Nothing worth keeping is left.', 'fishotel-misc-plugin' ); ?></p>
					<?php endif; ?>
					<?php if ( 'leftover' === $fh_source['kind'] ) : ?>
						<p class="foot">
							<?php
							/* translators: %s: leftover label */
							echo esc_html( sprintf( __( 'Cross out the old label %s: this leftover is used up.', 'fishotel-misc-plugin' ), $fh_source['label'] ) );
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<h2><?php esc_html_e( 'Cuts, in this order', 'fishotel-misc-plugin' ); ?></h2>
			<?php if ( $fh_source['cuts'] ) : ?>
				<ol class="cuts">
					<?php foreach ( $fh_source['cuts'] as $fh_cut ) : ?>
						<li><?php echo esc_html( preg_replace( '/^[^:]+:\s*/u', '', (string) $fh_cut['text'] ) ); ?></li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p><?php esc_html_e( 'No cut: it is used whole.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<section class="sheet summary">
		<div class="head">
			<h1><?php esc_html_e( 'The pieces per box', 'fishotel-misc-plugin' ); ?></h1>
			<span class="meta">
				<?php
				if ( $fh_totals ) {
					echo esc_html(
						sprintf(
							/* translators: 1: pieces, 2: boxes, 3: new sheets, 4: leftovers used, 5: new leftovers, 6: waste percent */
							__( '%1$d pieces for %2$d boxes · %3$d new sheet(s) · %4$d leftover(s) used up · %5$d new leftover(s) · waste %6$s%%', 'fishotel-misc-plugin' ),
							(int) $fh_totals['placed'],
							(int) $fh_totals['boxes'],
							(int) $fh_totals['sheets'],
							(int) $fh_totals['leftovers_used'],
							(int) $fh_totals['leftovers_new'],
							number_format_i18n( (float) $fh_totals['waste_pct'], 1 )
						)
					);
				}
				?>
			</span>
			<span class="right">
				<?php
				/* translators: 1: plan number, 2: when it was made */
				echo esc_html( sprintf( __( 'Plan %1$d · made %2$s', 'fishotel-misc-plugin' ), (int) $plan['id'], Admin::when( $plan['created_at'] ) ) );
				?>
			</span>
		</div>

		<?php if ( ! empty( $fh_for['origin'] ) && 'orders' === $fh_for['origin'] && ! empty( $fh_for['orders'] ) ) : ?>
			<p>
				<strong>
					<?php
					/* translators: 1: ship date, 2: delivery date */
					echo esc_html( sprintf( __( 'For the orders shipping %1$s (delivery %2$s):', 'fishotel-misc-plugin' ), (string) $fh_for['ship_date'], (string) $fh_for['delivery_date'] ) );
					?>
				</strong>
				<?php
				$fh_lines = array();
				foreach ( $fh_for['orders'] as $fh_order ) {
					$fh_lines[] = '#' . $fh_order['number'] . ' ' . ( $fh_order['boxes'] ? implode( ' + ', (array) $fh_order['boxes'] ) : __( '(no box yet)', 'fishotel-misc-plugin' ) );
				}
				echo esc_html( implode( '; ', $fh_lines ) );
				?>
			</p>
		<?php endif; ?>

		<?php foreach ( isset( $data['warnings'] ) ? (array) $data['warnings'] : array() as $fh_line ) : ?>
			<p class="note soft"><?php echo esc_html( $fh_line ); ?></p>
		<?php endforeach; ?>
		<?php foreach ( isset( $data['unplaced'] ) ? (array) $data['unplaced'] : array() as $fh_piece ) : ?>
			<p class="note"><?php echo esc_html( __( 'NOT ON THIS SHEET:', 'fishotel-misc-plugin' ) . ' ' . (int) $fh_piece['count'] . ' × ' . $fh_piece['text'] ); ?></p>
		<?php endforeach; ?>

		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Box', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Piece', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Size (in)', 'fishotel-misc-plugin' ); ?></th>
					<th class="num"><?php esc_html_e( 'Per box', 'fishotel-misc-plugin' ); ?></th>
					<th class="num"><?php esc_html_e( 'In all', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Cut from', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( isset( $data['summary'] ) ? (array) $data['summary'] : array() as $fh_box ) : ?>
					<?php foreach ( $fh_box['pieces'] as $fh_p => $fh_piece ) : ?>
						<tr>
							<td><?php echo 0 === $fh_p ? '<strong>' . esc_html( (int) $fh_box['count'] . ' × ' . $fh_box['box'] ) . '</strong>' : ''; ?></td>
							<td><?php echo esc_html( $fh_piece['name'] ); ?></td>
							<td><?php echo esc_html( Svg::size( $fh_piece['length'], $fh_piece['width'] ) ); ?></td>
							<td class="num"><?php echo (int) $fh_piece['per_box']; ?></td>
							<td class="num">
								<?php
								echo (int) $fh_piece['total'];
								if ( (int) $fh_piece['placed'] < (int) $fh_piece['total'] ) {
									/* translators: %d: pieces */
									echo ' ' . esc_html( sprintf( __( '(%d not placed)', 'fishotel-misc-plugin' ), (int) $fh_piece['total'] - (int) $fh_piece['placed'] ) );
								}
								?>
							</td>
							<td>
								<?php
								$fh_from = array();
								foreach ( (array) $fh_piece['from'] as $fh_label => $fh_n ) {
									$fh_from[] = $fh_label . ' × ' . (int) $fh_n;
								}
								echo esc_html( implode( ', ', $fh_from ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="foot"><?php esc_html_e( 'Each drawing reads like the foam lies in front of you: left is left, the top is the far edge. Sizes read across × down. Every cut runs straight from one edge of its part to the other; "its left edge" and "its top edge" are those of the part being cut.', 'fishotel-misc-plugin' ); ?></p>
		<p class="foot"><?php esc_html_e( 'When the foam is cut: FisHotel Tools → Foam Cuts → this plan → "Mark as cut". That takes the used leftovers and sheets out of the stock and adds the new leftovers under the labels above.', 'fishotel-misc-plugin' ); ?></p>
	</section>
<?php endif; ?>
</body>
</html>
