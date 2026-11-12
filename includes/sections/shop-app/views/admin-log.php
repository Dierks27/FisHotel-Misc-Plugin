<?php
/**
 * Shop App → Log: the append-only access log, newest first.
 *
 * @package FisHotel\Misc\Sections\Shop_App
 *
 * @var array[] $rows  Log rows.
 * @var int     $total Rows in all.
 * @var int     $paged Page number.
 * @var int     $pages Pages in all.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Shop_App\Access;
use FisHotel\Misc\Sections\Shop_App\Admin;
?>
<p class="fh-sa-help"><?php esc_html_e( 'Every device that was set up or revoked, every unlock, lock and lockout, and every change to a person — with the device, the person and the time. Nothing here can be edited or removed.', 'fishotel-misc-plugin' ); ?></p>

<?php if ( ! $rows ) : ?>
	<div class="fishotel-misc-empty"><span class="dashicons dashicons-list-view"></span><p><?php esc_html_e( 'Nothing logged yet.', 'fishotel-misc-plugin' ); ?></p></div>
<?php else : ?>
	<table class="widefat striped fh-sa-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Device', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Person', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'WordPress account', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $fh_row ) : ?>
				<tr>
					<td class="fh-sa-when"><?php echo esc_html( Admin::when( $fh_row['created_at'] ) ); ?></td>
					<td><span class="fh-sa-event fh-sa-event--<?php echo esc_attr( sanitize_key( $fh_row['event'] ) ); ?>"><?php echo esc_html( Access::event_label( $fh_row['event'] ) ); ?></span></td>
					<td><?php echo esc_html( '' !== (string) $fh_row['device_name'] ? $fh_row['device_name'] : '—' ); ?></td>
					<td><?php echo esc_html( '' !== (string) $fh_row['person_name'] ? $fh_row['person_name'] : '—' ); ?></td>
					<td><?php echo esc_html( Admin::user_name( $fh_row['user_id'] ) ); ?></td>
					<td><?php echo esc_html( $fh_row['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<p class="fh-sa-pages">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'log', array( 'paged' => $paged - 1 ) ) ); ?>">‹ <?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a>
			<?php endif; ?>
			<span>
				<?php
				/* translators: 1: page, 2: pages, 3: rows */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d · %3$d entries', 'fishotel-misc-plugin' ), $paged, $pages, $total ) );
				?>
			</span>
			<?php if ( $paged < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( Admin::page_url( 'log', array( 'paged' => $paged + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?> ›</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
<?php endif; ?>
