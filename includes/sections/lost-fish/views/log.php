<?php
/**
 * Log tab — the section's own log, newest first.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var string     $tab       Current tab.
 * @var array|null $flash     A one-time notice.
 * @var bool       $tables_ok Whether the tables exist.
 * @var array      $status    Admin::connections().
 * @var array[]    $rows      Store::log_rows().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Store;
?>
<div class="wrap fishotel-misc-wrap fh-lf-wrap">

	<?php include Lost_Fish::path() . 'views/top.php'; ?>

	<p class="fh-lf-lead"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'The latest %d lines: every loss that touched a paid customer (also the ones replaced from the extra), and every stage of every case.', 'fishotel-misc-plugin' ), Store::LOG_LIMIT ) ); ?></p>

	<?php if ( ! $rows ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-heart"></span><p><?php esc_html_e( 'Nothing logged yet.', 'fishotel-misc-plugin' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped fh-lf-list">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When (site time)', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Case', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $fh_row ) : ?>
					<?php $fh_user = $fh_row['user_id'] > 0 && function_exists( 'get_userdata' ) ? get_userdata( $fh_row['user_id'] ) : null; ?>
					<tr>
						<td class="fh-lf-when"><?php echo esc_html( Lost_Fish::site_time( $fh_row['created_at'] ) ); ?></td>
						<td>
							<?php if ( $fh_row['case_id'] > 0 ) : ?>
								<a href="<?php echo esc_url( Cases::case_url( $fh_row['case_id'] ) ); ?>">#<?php echo (int) $fh_row['case_id']; ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><span class="fh-lf-type fh-lf-type--<?php echo esc_attr( $fh_row['event'] ); ?>"><?php echo esc_html( str_replace( '_', ' ', $fh_row['event'] ) ); ?></span> <?php echo esc_html( $fh_row['message'] ); ?></td>
						<td><?php echo esc_html( is_object( $fh_user ) && isset( $fh_user->display_name ) ? $fh_user->display_name : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

</div>
