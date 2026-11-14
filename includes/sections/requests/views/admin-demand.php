<?php
/**
 * Demand: the most requested fish (open and all-time) beside the
 * quantities ordered on past supplier orders per year (the Clean Room's
 * order lines — past supplier orders were all customer requests), and
 * who has waited longest.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string  $tab     Current tab.
 * @var array   $ctx     Admin::context().
 * @var array   $flash   Admin::take_flash().
 * @var array   $needs   Listing::needs_you().
 * @var array   $demand  Listing::demand(): years, rows.
 * @var array[] $longest Listing::longest().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<section class="fishotel-misc-card fh-rq-card">
		<div class="fishotel-misc-card-header">
			<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
			<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Most requested fish', 'fishotel-misc-plugin' ); ?></h2>
		</div>
		<p class="fishotel-misc-card-desc">
			<?php
			echo esc_html(
				$ctx['cleanroom']
					? __( 'Requests taken here, beside what past supplier orders ordered of the same fish each year. Those orders were all customer requests, so what was ordered is what was asked for.', 'fishotel-misc-plugin' )
					: __( 'Requests taken here. The quantities ordered on past supplier orders would stand beside them, but the Clean Room section is switched off.', 'fishotel-misc-plugin' )
			);
			?>
		</p>

		<?php if ( ! $demand['rows'] ) : ?>
			<p class="fh-rq-sub"><?php esc_html_e( 'Nothing was requested yet, and no supplier order has a line.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<div class="fh-rq-scroll">
				<table class="widefat fh-rq-table fh-rq-demand">
					<thead>
						<tr>
							<th scope="col" rowspan="2"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
							<th scope="colgroup" colspan="2" class="fh-rq-num"><?php esc_html_e( 'Open now', 'fishotel-misc-plugin' ); ?></th>
							<th scope="colgroup" colspan="2" class="fh-rq-num"><?php esc_html_e( 'All-time', 'fishotel-misc-plugin' ); ?></th>
							<?php if ( $demand['years'] ) : ?>
								<th scope="colgroup" colspan="<?php echo esc_attr( count( $demand['years'] ) + 1 ); ?>" class="fh-rq-num"><?php esc_html_e( 'Ordered on supplier orders', 'fishotel-misc-plugin' ); ?></th>
							<?php endif; ?>
						</tr>
						<tr>
							<th scope="col" class="fh-rq-num"><?php esc_html_e( 'requests', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col" class="fh-rq-num"><?php esc_html_e( 'fish', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col" class="fh-rq-num"><?php esc_html_e( 'requests', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col" class="fh-rq-num"><?php esc_html_e( 'fish', 'fishotel-misc-plugin' ); ?></th>
							<?php foreach ( $demand['years'] as $fh_year ) : ?>
								<th scope="col" class="fh-rq-num"><?php echo esc_html( (string) $fh_year ); ?></th>
							<?php endforeach; ?>
							<?php if ( $demand['years'] ) : ?>
								<th scope="col" class="fh-rq-num"><?php esc_html_e( 'ever', 'fishotel-misc-plugin' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $demand['rows'] as $fh_r ) : ?>
							<tr>
								<td>
									<?php if ( $fh_r['all_requests'] > 0 ) : ?>
										<a href="<?php echo esc_url( Admin::page_url( 'list', array( 'state' => 'all', 's' => $fh_r['fish'] ) ) ); ?>"><?php echo esc_html( $fh_r['fish'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $fh_r['fish'] ); ?>
									<?php endif; ?>
								</td>
								<td class="fh-rq-num"><?php echo $fh_r['open_requests'] > 0 ? '<strong>' . esc_html( (string) $fh_r['open_requests'] ) . '</strong>' : '<span class="fh-rq-dim">0</span>'; ?></td>
								<td class="fh-rq-num"><?php echo $fh_r['open_qty'] > 0 ? '<strong>' . esc_html( (string) $fh_r['open_qty'] ) . '</strong>' : '<span class="fh-rq-dim">0</span>'; ?></td>
								<td class="fh-rq-num"><?php echo esc_html( (string) $fh_r['all_requests'] ); ?></td>
								<td class="fh-rq-num"><?php echo esc_html( (string) $fh_r['all_qty'] ); ?></td>
								<?php foreach ( $demand['years'] as $fh_year ) : ?>
									<td class="fh-rq-num"><?php echo isset( $fh_r['ordered'][ $fh_year ] ) ? esc_html( (string) $fh_r['ordered'][ $fh_year ] ) : '<span class="fh-rq-dim">·</span>'; ?></td>
								<?php endforeach; ?>
								<?php if ( $demand['years'] ) : ?>
									<td class="fh-rq-num"><?php echo $fh_r['ordered_total'] > 0 ? esc_html( (string) $fh_r['ordered_total'] ) : '<span class="fh-rq-dim">·</span>'; ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="fh-rq-sub"><?php esc_html_e( 'Sorted by the fish asked for now, then all-time, then ordered. A fish is matched to its order history by its species-list entry, else by its name; a fish nobody knows yet ("??") is left out. "Ever" counts every year, not only the ones shown.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="fishotel-misc-card fh-rq-card">
		<div class="fishotel-misc-card-header">
			<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-clock"></span></div>
			<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Who has waited longest', 'fishotel-misc-plugin' ); ?></h2>
		</div>
		<?php if ( ! $longest ) : ?>
			<p class="fh-rq-sub"><?php esc_html_e( 'Nobody is waiting.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat fh-rq-table">
				<thead>
					<tr>
						<th scope="col" class="fh-rq-num">#</th>
						<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Waiting', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $longest as $fh_r ) : ?>
						<tr>
							<td class="fh-rq-num"><a href="<?php echo esc_url( Admin::request_url( $fh_r['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_r['id'] ); ?></a></td>
							<td><?php echo esc_html( Rules::who( $fh_r ) ); ?></td>
							<td><?php echo esc_html( Rules::fish_line( $fh_r ) ); ?></td>
							<td><?php echo Admin::badge( $fh_r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge(). ?></td>
							<td title="<?php echo esc_attr( Admin::when( $fh_r['created_at'] ) ); ?>"><?php echo esc_html( $fh_r['age'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
