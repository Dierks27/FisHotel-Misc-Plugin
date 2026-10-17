<?php
/**
 * Today page — the header line, then one block per upcoming delivery
 * date: chips, the needs-attention strip, the table and the links.
 *
 * @package FisHotel\Misc\Sections\Today
 *
 * @var array         $settings     Settings.
 * @var \DateTimeZone $tz           Site zone.
 * @var array         $data         Collector::get().
 * @var string        $header       Model::header_line().
 * @var bool          $weather_on   Whether the Weather section is enabled.
 * @var string        $ship_day_url Ship Day pick list URL ('' when absent).
 * @var bool          $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Today\Admin;
use FisHotel\Misc\Sections\Today\Model;

$fh_columns = (array) $data['columns'];
$fh_notes   = (array) ( $fh_columns['notes'] ?? array() );
$fh_blocks  = (array) $data['blocks'];
$fh_chip_defs = array(
	'orders'         => array( __( 'Orders', 'fishotel-misc-plugin' ), '' ),
	'fish'           => array( __( 'Fish', 'fishotel-misc-plugin' ), '' ),
	'dry'            => array( __( 'Dry goods', 'fishotel-misc-plugin' ), '' ),
	'holds'          => array( __( 'Holds', 'fishotel-misc-plugin' ), 'hold' ),
	'ups_store'      => array( __( 'UPS Store', 'fishotel-misc-plugin' ), 'ups_store' ),
	'at_line'        => array( __( 'At the line', 'fishotel-misc-plugin' ), 'at_line' ),
	'unavailable'    => array( __( 'Unavailable', 'fishotel-misc-plugin' ), 'unavailable' ),
	'needs_weather'  => array( __( 'Need weather', 'fishotel-misc-plugin' ), 'need' ),
	'needs_invoice'  => array( __( 'Need invoice', 'fishotel-misc-plugin' ), 'need' ),
	'needs_tracking' => array( __( 'Need tracking', 'fishotel-misc-plugin' ), 'need' ),
);
if ( empty( $fh_columns['weather'] ) ) {
	unset( $fh_chip_defs['holds'], $fh_chip_defs['ups_store'], $fh_chip_defs['at_line'], $fh_chip_defs['unavailable'], $fh_chip_defs['needs_weather'] );
}
if ( empty( $fh_columns['invoice'] ) ) {
	unset( $fh_chip_defs['needs_invoice'] );
}
if ( empty( $fh_columns['tracking'] ) ) {
	unset( $fh_chip_defs['needs_tracking'] );
}
?>
<div class="wrap fishotel-misc-wrap fh-today-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Today at FisHotel', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-today-tabs">
		<a class="fh-today-tab is-active" href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Today', 'fishotel-misc-plugin' ); ?></a>
		<?php if ( ! empty( $can_settings ) ) : ?>
			<a class="fh-today-tab" href="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
		<?php endif; ?>
	</nav>

	<div class="fh-today-hero<?php echo ! empty( $data['plan']['ships_today'] ) ? ' is-ship-day' : ''; ?>">
		<p class="fh-today-headline"><?php echo esc_html( $header ); ?></p>
		<div class="fh-today-hero__actions">
			<span class="fh-today-fresh" data-generated-at="<?php echo esc_attr( (int) $data['generated_at'] ); ?>" data-cached="<?php echo ! empty( $data['cached'] ) ? '1' : '0'; ?>"><?php echo esc_html( Admin::freshness_label( $data ) ); ?></span>
			<button type="button" class="button fh-today-refresh"><?php esc_html_e( 'Refresh', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-today-status" aria-live="polite"></span>
		</div>
	</div>

	<?php foreach ( $fh_notes as $fh_note ) : ?>
		<p class="fh-today-colnote"><?php echo esc_html( $fh_note ); ?></p>
	<?php endforeach; ?>

	<?php if ( empty( $fh_blocks ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-calendar-alt"></span>
			<p>
				<?php
				/* translators: %d: lookahead days */
				echo esc_html( sprintf( __( 'No open orders deliver in the next %d days.', 'fishotel-misc-plugin' ), (int) $data['lookahead'] ) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php foreach ( $fh_blocks as $fh_ymd => $fh_block ) : ?>
		<?php
		$fh_chips       = (array) $fh_block['chips'];
		$fh_attention   = (array) $fh_block['attention'];
		$fh_weather_url = $weather_on ? Admin::weather_url( $fh_ymd ) : '';
		$fh_is_tomorrow = ! empty( $data['plan']['ships_today'] ) && $data['plan']['delivery'] === $fh_ymd;
		?>
		<section class="fh-today-block<?php echo $fh_is_tomorrow ? ' is-tomorrow' : ''; ?>" data-date="<?php echo esc_attr( $fh_ymd ); ?>">

			<header class="fh-today-block__head">
				<div>
					<h2 class="fh-today-block__title">
						<?php
						/* translators: %s: delivery date */
						echo esc_html( sprintf( __( 'Deliver %s', 'fishotel-misc-plugin' ), Model::date_label( $fh_ymd, $tz, 'l, M j' ) ) );
						?>
						<?php if ( $fh_is_tomorrow ) : ?>
							<span class="fh-today-pill"><?php esc_html_e( 'ships today', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</h2>
					<p class="fh-today-block__sub">
						<?php
						/* translators: %s: ship date */
						echo esc_html( sprintf( __( 'Ship %s', 'fishotel-misc-plugin' ), Model::date_label( $fh_block['ship'], $tz, 'l, M j' ) ) );
						?>
					</p>
				</div>
				<div class="fh-today-chips">
					<?php foreach ( $fh_chip_defs as $fh_key => $fh_def ) : ?>
						<?php $fh_n = (int) ( $fh_chips[ $fh_key ] ?? 0 ); ?>
						<span class="fh-today-chip<?php echo '' !== $fh_def[1] ? ' fh-today-chip--' . esc_attr( $fh_def[1] ) : ''; ?><?php echo ( '' !== $fh_def[1] && 0 === $fh_n ) ? ' is-zero' : ''; ?>">
							<strong><?php echo esc_html( $fh_n ); ?></strong> <?php echo esc_html( $fh_def[0] ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			</header>

			<?php if ( $fh_attention ) : ?>
				<div class="fh-today-attention">
					<span class="fh-today-attention__label"><?php esc_html_e( 'Needs attention', 'fishotel-misc-plugin' ); ?></span>
					<ul class="fh-today-attention__list">
						<?php foreach ( $fh_attention as $fh_item ) : ?>
							<li class="fh-today-attention__item fh-today-attention__item--<?php echo esc_attr( $fh_item['kind'] ); ?>">
								<?php if ( '' !== $fh_item['edit_url'] ) : ?>
									<a href="<?php echo esc_url( $fh_item['edit_url'] ); ?>">#<?php echo esc_html( $fh_item['number'] ); ?></a>
								<?php else : ?>
									<strong>#<?php echo esc_html( $fh_item['number'] ); ?></strong>
								<?php endif; ?>
								<span><?php echo esc_html( implode( ' · ', $fh_item['reasons'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php else : ?>
				<p class="fh-today-allclear"><?php esc_html_e( 'Nothing needs attention for this date.', 'fishotel-misc-plugin' ); ?></p>
			<?php endif; ?>

			<table class="widefat striped fh-today-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Destination', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-today-num"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
						<?php if ( ! empty( $fh_columns['weather'] ) ) : ?>
							<th scope="col"><?php esc_html_e( 'Weather', 'fishotel-misc-plugin' ); ?></th>
						<?php endif; ?>
						<?php if ( ! empty( $fh_columns['invoice'] ) ) : ?>
							<th scope="col"><?php esc_html_e( 'Invoice', 'fishotel-misc-plugin' ); ?></th>
						<?php endif; ?>
						<?php if ( ! empty( $fh_columns['tracking'] ) ) : ?>
							<th scope="col"><?php esc_html_e( 'Tracking', 'fishotel-misc-plugin' ); ?></th>
						<?php endif; ?>
						<th scope="col" class="fh-today-note-col"><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fh_block['destinations'] as $fh_d ) : ?>
						<tr data-order-id="<?php echo esc_attr( $fh_d['order_id'] ); ?>">
							<td>
								<?php if ( '' !== $fh_d['edit_url'] ) : ?>
									<a href="<?php echo esc_url( $fh_d['edit_url'] ); ?>"><strong>#<?php echo esc_html( $fh_d['number'] ); ?></strong></a>
								<?php else : ?>
									<strong>#<?php echo esc_html( $fh_d['number'] ); ?></strong>
								<?php endif; ?>
								<?php if ( $fh_d['is_fulfillment'] ) : ?>
									<span class="fh-today-sub">
										<?php
										if ( $fh_d['sources'] ) {
											/* translators: %s: comma-separated source order numbers */
											echo esc_html( sprintf( __( 'combined: #%s', 'fishotel-misc-plugin' ), implode( ', #', $fh_d['sources'] ) ) );
										} else {
											esc_html_e( 'combined box — no live source orders', 'fishotel-misc-plugin' );
										}
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $fh_d['customer'] ); ?></td>
							<td><?php echo esc_html( $fh_d['place'] ); ?></td>
							<td><?php echo esc_html( $fh_d['status_label'] ); ?></td>
							<td class="fh-today-num">
								<?php echo esc_html( $fh_d['fish'] ); ?>
								<?php if ( (int) $fh_d['dry'] > 0 ) : ?>
									<span class="fh-today-sub">
										<?php
										/* translators: %d: dry-goods count */
										echo esc_html( sprintf( __( '+%d dry', 'fishotel-misc-plugin' ), (int) $fh_d['dry'] ) );
										?>
									</span>
								<?php endif; ?>
							</td>
							<?php if ( ! empty( $fh_columns['weather'] ) ) : ?>
								<td>
									<?php echo Admin::badge_html( (array) $fh_d['weather'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
									<?php if ( null !== $fh_d['weather']['high'] ) : ?>
										<span class="fh-today-sub"><?php echo esc_html( sprintf( '%d°F', (int) $fh_d['weather']['high'] ) ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $fh_d['weather']['alerts'] ) ) : ?>
										<span class="fh-today-sub fh-today-alerts"><?php echo esc_html( implode( ', ', (array) $fh_d['weather']['alerts'] ) ); ?></span>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<?php if ( ! empty( $fh_columns['invoice'] ) ) : ?>
								<td class="fh-today-cell <?php echo ! empty( $fh_d['invoice']['done'] ) ? 'is-done' : 'is-open'; ?>">
									<?php echo ! empty( $fh_d['invoice']['done'] ) ? '&#10003;' : esc_html__( 'open', 'fishotel-misc-plugin' ); ?>
								</td>
							<?php endif; ?>
							<?php if ( ! empty( $fh_columns['tracking'] ) ) : ?>
								<td class="fh-today-cell <?php echo ! empty( $fh_d['tracking']['done'] ) ? 'is-done' : 'is-open'; ?>">
									<?php if ( ! empty( $fh_d['tracking']['done'] ) ) : ?>
										&#10003; <?php echo esc_html( (int) $fh_d['tracking']['count'] ); ?><?php echo '' !== (string) $fh_d['tracking']['status_label'] ? ' · ' . esc_html( $fh_d['tracking']['status_label'] ) : ''; ?>
									<?php elseif ( '' !== $fh_d['edit_url'] ) : ?>
										<a href="<?php echo esc_url( $fh_d['edit_url'] ); ?>" title="<?php esc_attr_e( 'Add tracking in the Shipment Tracking box on the order', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'open', 'fishotel-misc-plugin' ); ?></a>
									<?php else : ?>
										<?php esc_html_e( 'open', 'fishotel-misc-plugin' ); ?>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<td class="fh-today-note-col">
								<?php if ( ! empty( $fh_d['has_note'] ) ) : ?>
									<span class="dashicons dashicons-format-chat fh-today-note-icon" title="<?php esc_attr_e( 'Customer note on the order', 'fishotel-misc-plugin' ); ?>"></span>
									<span class="screen-reader-text"><?php esc_html_e( 'Customer note', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="fh-today-links">
				<?php if ( '' !== $fh_weather_url ) : ?>
					<a href="<?php echo esc_url( $fh_weather_url ); ?>"><?php esc_html_e( 'Weather overview for this date', 'fishotel-misc-plugin' ); ?> &rarr;</a>
				<?php endif; ?>
				<?php if ( '' !== $ship_day_url ) : ?>
					<a href="<?php echo esc_url( $ship_day_url ); ?>"><?php esc_html_e( 'Ship Day pick list', 'fishotel-misc-plugin' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		</section>
	<?php endforeach; ?>

	<?php
	$fh_more = array_diff_key( (array) $data['counts'], $fh_blocks );
	if ( $fh_more ) :
		?>
		<p class="fh-today-more">
			<?php esc_html_e( 'Further out:', 'fishotel-misc-plugin' ); ?>
			<?php
			$fh_bits = array();
			foreach ( $fh_more as $fh_ymd => $fh_n ) {
				$fh_bits[] = Model::date_label( $fh_ymd, $tz, 'D M j' ) . ' · ' . Model::plural( (int) $fh_n, __( 'order', 'fishotel-misc-plugin' ), __( 'orders', 'fishotel-misc-plugin' ) );
			}
			echo esc_html( implode( '  |  ', $fh_bits ) );
			?>
		</p>
	<?php endif; ?>

	<p class="fh-today-footnote">
		<?php esc_html_e( 'Delivery dates come from the orders (the Batch plugin\'s delivery date); a box ships the day before it lands. Combined boxes show the fulfillment order with its source orders. Weather is the Weather section\'s note; Invoice is the PDF Invoices plugin\'s marker; Tracking is ShipTracker\'s shipment list. Nothing here changes an order.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
