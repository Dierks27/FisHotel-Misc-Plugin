<?php
/**
 * Settings → Saved import layouts: what the line importer remembers
 * about each supplier's sheet (Import_Layouts) — the supplier, the
 * moment, the columns in words, when it was last used — with Forget.
 * Nothing is seeded; a layout appears when the owner sets the columns of
 * an import by hand and leaves "Remember this layout" ticked.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $import_layouts Import_Layouts::all().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Import_Layouts;
?>
<div class="fishotel-misc-card fh-cr-card fh-cr-card--wide" id="fishotel-cleanroom-import-layouts">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-editor-table"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Saved import layouts', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'What "Import lines" on an order remembers about each supplier\'s sheet: which column is the fish, which the quantity, and so on — one for the order sheet and one for the invoice. The next file from that supplier is read the same way without asking. Forget one and the importer asks again.', 'fishotel-misc-plugin' ); ?></p>
	<?php if ( ! $import_layouts ) : ?>
		<p class="fh-cr-sub"><?php esc_html_e( 'Nothing saved yet. Set the columns of an import by hand once, leave "Remember this layout" ticked, and it appears here.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fh-cr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'For', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Columns', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last used', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $import_layouts as $fh_layout ) : ?>
					<tr data-layout="<?php echo esc_attr( $fh_layout['id'] ); ?>">
						<td><?php echo esc_html( Import_Layouts::owner_text( $fh_layout ) ); ?></td>
						<td><?php echo esc_html( Import_Layouts::moment_text( $fh_layout['moment'] ) ); ?></td>
						<td>
							<?php echo esc_html( Import_Layouts::describe( $fh_layout ) ); ?>
							<span class="fh-cr-sub">
								<?php
								echo esc_html(
									$fh_layout['header']
										/* translators: %d: row number */
										? sprintf( __( 'header on row %d', 'fishotel-misc-plugin' ), (int) $fh_layout['header_row'] )
										/* translators: %d: number of columns */
										: sprintf( __( 'no header row — %d columns, by position', 'fishotel-misc-plugin' ), count( $fh_layout['columns'] ) )
								);
								if ( '' !== $fh_layout['sheet'] ) {
									/* translators: %s: sheet name */
									echo esc_html( ' · ' . sprintf( __( 'sheet "%s"', 'fishotel-misc-plugin' ), $fh_layout['sheet'] ) );
								}
								if ( $fh_layout['junk'] ) {
									/* translators: %s: row texts */
									echo esc_html( ' · ' . sprintf( __( 'always left out: %s', 'fishotel-misc-plugin' ), implode( ', ', $fh_layout['junk'] ) ) );
								}
								?>
							</span>
						</td>
						<td><?php echo esc_html( '' !== $fh_layout['used'] ? $fh_layout['used'] : '—' ); ?></td>
						<td class="fh-cr-row__actions"><button type="button" class="button button-small fh-cr-layout-forget"><?php esc_html_e( 'Forget', 'fishotel-misc-plugin' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
