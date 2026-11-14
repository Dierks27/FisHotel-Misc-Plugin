<?php
/**
 * A table of audit rows (the Audit log tab and a card's page).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array[] $fh_rows Audit rows.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Audit;
?>
<?php if ( ! $fh_rows ) : ?>
	<p class="fh-gc-sub"><?php esc_html_e( 'Nothing in the log yet.', 'fishotel-misc-plugin' ); ?></p>
<?php else : ?>
	<div class="fh-gc-scroll">
	<table class="widefat fh-gc-table fh-gc-audit">
		<thead><tr><th><?php esc_html_e( 'When (site time)', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Card', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Why', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'From where', 'fishotel-misc-plugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $fh_rows as $fh_row ) : ?>
			<tr>
				<td><?php echo esc_html( $fh_row['site_time'] ); ?><br><small><?php echo esc_html( $fh_row['created_at_utc'] ); ?> UTC</small></td>
				<td><?php echo esc_html( $fh_row['actor_name'] ); ?><?php echo (int) $fh_row['actor_user_id'] > 0 ? '<br><small>#' . esc_html( (string) (int) $fh_row['actor_user_id'] ) . '</small>' : ''; ?></td>
				<td><?php echo esc_html( Audit::describe( $fh_row ) ); ?></td>
				<td><?php echo (int) $fh_row['card_id'] > 0 ? '<a href="' . esc_url( Admin::card_url( (int) $fh_row['card_id'] ) ) . '">#' . esc_html( (string) (int) $fh_row['card_id'] ) . '</a>' : '—'; ?></td>
				<td><?php echo esc_html( (string) $fh_row['reason'] ); ?></td>
				<td><?php echo esc_html( $fh_row['source'] ); ?><br><small><?php echo esc_html( trim( $fh_row['screen'] . ' ' . $fh_row['ip'] ) ); ?></small></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php endif; ?>
