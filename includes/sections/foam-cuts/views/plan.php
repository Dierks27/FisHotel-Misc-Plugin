<?php
/**
 * Plan tab — make a plan (the numbers typed, or filled in from the orders
 * that ship on a day), the plan that is open, and the latest plans.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var array      $context   Planner::context(): settings, source, notes, boxes.
 * @var array      $stock     Stock::snapshot().
 * @var array[]    $plans     The latest plans (without data).
 * @var array|null $plan      The open plan (with data), or null.
 * @var array      $state     Stock::state() of the open plan.
 * @var array|null $orders    Orders::for_ship_date() when a day was asked for.
 * @var string     $reason    Why "today's orders" cannot be used ('' = it can).
 * @var string     $today     Today, Y-m-d (site time).
 * @var string     $ship      The day asked for ('' = none).
 * @var bool       $installed Whether the tables are there.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;
use FisHotel\Misc\Sections\Foam_Cuts\Boxes;
use FisHotel\Misc\Sections\Foam_Cuts\Cut_List;
use FisHotel\Misc\Sections\Foam_Cuts\Foam_Cuts;
use FisHotel\Misc\Sections\Foam_Cuts\Units;

$fh_prefill = $orders && $orders['ok'] ? $orders['counts'] : array();
$fh_sheets  = Admin::sheets_shown( $stock['sheets'] );
?>
<div class="fh-fc-columns">

	<section class="fh-fc-card">
		<h2><?php esc_html_e( 'New plan', 'fishotel-misc-plugin' ); ?></h2>

		<p class="fh-fc-facts">
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: whole sheets in stock */
				echo esc_html( sprintf( _n( '%d whole sheet in stock', '%d whole sheets in stock', $fh_sheets, 'fishotel-misc-plugin' ), $fh_sheets ) );
				?>
			</span>
			<span class="fh-fc-fact">
				<?php
				/* translators: %d: leftovers in stock */
				echo esc_html( sprintf( _n( '%d leftover', '%d leftovers', count( $stock['leftovers'] ), 'fishotel-misc-plugin' ), count( $stock['leftovers'] ) ) );
				?>
			</span>
			<a class="fh-fc-link" href="<?php echo esc_url( Admin::page_url( 'stock' ) ); ?>"><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?> →</a>
		</p>

		<?php if ( ! $context['boxes'] ) : ?>
			<p class="fh-fc-muted">
				<?php esc_html_e( 'There are no boxes yet, so there is nothing to cut for.', 'fishotel-misc-plugin' ); ?>
				<a class="fh-fc-link" href="<?php echo esc_url( Admin::page_url( 'boxes' ) ); ?>"><?php esc_html_e( 'Add your boxes', 'fishotel-misc-plugin' ); ?> →</a>
			</p>
		<?php else : ?>

			<div class="fh-fc-orders">
				<?php if ( '' !== $reason ) : ?>
					<p class="fh-fc-muted"><strong><?php esc_html_e( 'Today\'s orders:', 'fishotel-misc-plugin' ); ?></strong> <?php echo esc_html( $reason ); ?></p>
				<?php else : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-fc-inline">
						<input type="hidden" name="page" value="<?php echo esc_attr( Foam_Cuts::MENU_SLUG ); ?>" />
						<label for="fh-fc-ship"><?php esc_html_e( 'Orders shipping on', 'fishotel-misc-plugin' ); ?></label>
						<input type="date" id="fh-fc-ship" name="ship" value="<?php echo esc_attr( $orders ? $orders['ship_date'] : $today ); ?>" />
						<button type="submit" class="button"><?php esc_html_e( 'Fill in from these orders', 'fishotel-misc-plugin' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( $orders && ! $orders['ok'] ) : ?>
					<p class="fh-fc-warn"><?php echo esc_html( $orders['message'] ); ?></p>
				<?php elseif ( $orders ) : ?>
					<div class="fh-fc-orderlist">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of orders, 2: ship date, 3: delivery date */
									_n( '%1$d order ships on %2$s (delivery %3$s):', '%1$d orders ship on %2$s (delivery %3$s):', count( $orders['orders'] ), 'fishotel-misc-plugin' ),
									count( $orders['orders'] ),
									$orders['ship_date'],
									$orders['delivery_date']
								)
							);
							?>
						</p>
						<?php if ( $orders['orders'] ) : ?>
							<ul>
								<?php foreach ( $orders['orders'] as $fh_order ) : ?>
									<li>
										<strong>#<?php echo esc_html( $fh_order['number'] ); ?></strong>
										<?php
										$fh_names = array();
										foreach ( $fh_order['boxes'] as $fh_box ) {
											$fh_names[] = (int) $fh_box['count'] . ' × ' . $fh_box['name'] . ( $fh_box['listed'] ? '' : ' ' . __( '(not on the list — cut by its size)', 'fishotel-misc-plugin' ) );
										}
										foreach ( $fh_order['unknown'] as $fh_name ) {
											/* translators: %s: a box name nobody knows */
											$fh_names[] = sprintf( __( '"%s" is not a box on the list', 'fishotel-misc-plugin' ), $fh_name );
										}
										echo esc_html( $fh_names ? implode( ', ', $fh_names ) : __( 'no box yet', 'fishotel-misc-plugin' ) );

										if ( 'suggested' === $fh_order['how'] ) {
											echo ' <span class="fh-fc-chip">' . esc_html__( 'suggested', 'fishotel-misc-plugin' ) . '</span>';
										} elseif ( 'confirmed' === $fh_order['how'] ) {
											echo ' <span class="fh-fc-chip is-ok">' . esc_html__( 'confirmed', 'fishotel-misc-plugin' ) . '</span>';
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( $orders['without_box'] ) : ?>
							<p class="fh-fc-warn">
								<?php
								/* translators: %d: orders */
								echo esc_html( sprintf( _n( '%d order has no box yet and is not counted — give it one in the Packing section, or add its box to the numbers below.', '%d orders have no box yet and are not counted — give them one in the Packing section, or add their boxes to the numbers below.', count( $orders['without_box'] ), 'fishotel-misc-plugin' ), count( $orders['without_box'] ) ) );
								?>
							</p>
						<?php endif; ?>
						<p class="fh-fc-muted"><?php esc_html_e( 'The numbers below are filled in from these orders. Change them if you like, then make the plan.', 'fishotel-misc-plugin' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php
				Admin::form_fields(
					'plan',
					$orders && $orders['ok'] ? array(
						'origin'    => 'orders',
						'ship_date' => $orders['ship_date'],
					) : array( 'origin' => 'typed' )
				);
				?>
				<table class="widefat striped fh-fc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Box', 'fishotel-misc-plugin' ); ?></th>
							<th><?php esc_html_e( 'Inside (in)', 'fishotel-misc-plugin' ); ?></th>
							<th><?php esc_html_e( 'Foam per box', 'fishotel-misc-plugin' ); ?></th>
							<th class="fh-fc-num"><?php esc_html_e( 'Cut for how many', 'fishotel-misc-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $context['boxes'] as $fh_i => $fh_box ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $fh_box['name'] ); ?></strong>
									<?php if ( $fh_box['changed'] ) : ?>
										<span class="fh-fc-chip is-warn"><?php esc_html_e( 'box changed size — check its cut list', 'fishotel-misc-plugin' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Units::format( $fh_box['length'] ) . ' × ' . Units::format( $fh_box['width'] ) . ' × ' . Units::format( $fh_box['height'] ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: pieces, 2: square feet */
											_n( '%1$d piece, %2$s sq ft', '%1$d pieces, %2$s sq ft', Cut_List::piece_count( $fh_box['pieces'] ), 'fishotel-misc-plugin' ),
											Cut_List::piece_count( $fh_box['pieces'] ),
											number_format_i18n( Units::square_feet( Cut_List::area( $fh_box['pieces'] ) ), 1 )
										)
									);
									?>
									<span class="fh-fc-chip<?php echo 'saved' === $fh_box['cut_list'] ? ' is-ok' : ''; ?>"><?php echo 'saved' === $fh_box['cut_list'] ? esc_html__( 'your cut list', 'fishotel-misc-plugin' ) : esc_html__( 'suggested', 'fishotel-misc-plugin' ); ?></span>
								</td>
								<td class="fh-fc-num">
									<input type="hidden" name="boxes[<?php echo (int) $fh_i; ?>][key]" value="<?php echo esc_attr( $fh_box['key'] ); ?>" />
									<input type="number" class="fh-fc-count" name="boxes[<?php echo (int) $fh_i; ?>][count]" min="0" max="500" step="1" value="<?php echo esc_attr( isset( $fh_prefill[ $fh_box['key'] ] ) ? (string) (int) $fh_prefill[ $fh_box['key'] ] : '' ); ?>" placeholder="0" aria-label="<?php echo esc_attr( $fh_box['name'] ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $orders && $orders['ok'] && $orders['extra'] ) : ?>
					<p class="fh-fc-muted">
						<?php
						$fh_extra = array();
						foreach ( $orders['extra'] as $fh_box ) {
							$fh_extra[] = (int) $fh_box['count'] . ' × ' . $fh_box['name'];
						}
						/* translators: %s: boxes */
						echo esc_html( sprintf( __( 'Also cut for, from the orders: %s (not on the list, cut by the suggested pieces for that size).', 'fishotel-misc-plugin' ), implode( ', ', $fh_extra ) ) );
						?>
					</p>
				<?php endif; ?>
				<p class="fh-fc-actions">
					<button type="submit" class="button button-primary"<?php echo $installed ? '' : ' disabled'; ?>><?php esc_html_e( 'Make the plan', 'fishotel-misc-plugin' ); ?></button>
					<span class="fh-fc-muted"><?php esc_html_e( 'Making a plan changes nothing in the stock.', 'fishotel-misc-plugin' ); ?></span>
				</p>
			</form>

			<p class="fh-fc-muted">
				<?php
				/* translators: %s: where the box list comes from */
				echo esc_html( sprintf( __( 'Boxes come from %s.', 'fishotel-misc-plugin' ), Boxes::source_label( $context['source'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>

	<section class="fh-fc-card">
		<h2><?php esc_html_e( 'Plans', 'fishotel-misc-plugin' ); ?></h2>
		<?php if ( ! $plans ) : ?>
			<p class="fh-fc-muted"><?php esc_html_e( 'No plan yet.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat striped fh-fc-table">
				<thead>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'For', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Made', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $plans as $fh_row ) : ?>
						<tr<?php echo $plan && (int) $plan['id'] === (int) $fh_row['id'] ? ' class="is-open"' : ''; ?>>
							<td><?php echo (int) $fh_row['id']; ?></td>
							<td>
								<a class="fh-fc-link" href="<?php echo esc_url( Admin::page_url( 'plan', array( 'plan' => (int) $fh_row['id'] ) ) ); ?>"><?php echo esc_html( '' !== $fh_row['title'] ? $fh_row['title'] : __( '(empty)', 'fishotel-misc-plugin' ) ); ?></a>
								<?php if ( 'orders' === $fh_row['origin'] && '' !== $fh_row['ship_date'] ) : ?>
									<span class="fh-fc-muted">
										<?php
										/* translators: %s: date */
										echo esc_html( sprintf( __( 'orders of %s', 'fishotel-misc-plugin' ), $fh_row['ship_date'] ) );
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( Admin::when( $fh_row['created_at'] ) ); ?></td>
							<td>
								<?php if ( 'cut' === $fh_row['status'] ) : ?>
									<span class="fh-fc-chip is-ok">
										<?php
										/* translators: %s: date */
										echo esc_html( sprintf( __( 'cut %s', 'fishotel-misc-plugin' ), Admin::when( $fh_row['cut_at'], 'M j' ) ) );
										?>
									</span>
								<?php else : ?>
									<span class="fh-fc-chip"><?php esc_html_e( 'not cut yet', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
							<td><a class="fh-fc-link" href="<?php echo esc_url( Admin::print_link( (int) $fh_row['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print', 'fishotel-misc-plugin' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

</div>

<?php
if ( $plan ) {
	include __DIR__ . '/plan-detail.php';
}
