<?php
/**
 * Weather overview — one delivery date, every order going out that day,
 * its band, and the totals the pick list needs.
 *
 * @package FisHotel\Misc\Sections\Weather
 *
 * @var array    $settings Settings.
 * @var string   $today    Y-m-d.
 * @var array    $upcoming date => count.
 * @var string   $date     Selected Y-m-d.
 * @var array[]  $rows     Admin::overview_rows().
 * @var array    $totals   Admin::overview_totals().
 * @var array    $last_run Last nightly run summary (may be empty).
 * @var string   $next_run Next scheduled run label (may be '').
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Weather\Admin;
use FisHotel\Misc\Sections\Weather\Note;
use FisHotel\Misc\Sections\Weather\Weather;

$fh_page_url = add_query_arg( array( 'page' => Weather::MENU_SLUG ), admin_url( 'admin.php' ) );
$fh_tiles    = array(
	'heat_pack'   => array( __( 'Heat packs', 'fishotel-misc-plugin' ), 'heat_pack' ),
	'cooling'     => array( __( 'Cooling packs', 'fishotel-misc-plugin' ), 'cooling' ),
	'ups_store'   => array( __( 'UPS Store', 'fishotel-misc-plugin' ), 'ups_store_hot' ),
	'hold'        => array( __( 'Holds', 'fishotel-misc-plugin' ), 'hold_hot' ),
	'none'        => array( __( 'Nothing needed', 'fishotel-misc-plugin' ), 'none' ),
	'unavailable' => array( __( 'Unavailable', 'fishotel-misc-plugin' ), 'unavailable' ),
	'unchecked'   => array( __( 'Not checked', 'fishotel-misc-plugin' ), 'unchecked' ),
);
?>
<div class="wrap fishotel-misc-wrap fh-weather-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Weather', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-weather-tabs">
		<a class="fh-weather-tab is-active" href="<?php echo esc_url( $fh_page_url ); ?>"><?php esc_html_e( 'Overview', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-weather-tab" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $fh_page_url ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<?php if ( empty( $settings['enabled'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The nightly job is switched off in Settings. Orders can still be checked by hand from here.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-weather-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-weather-datepick">
			<input type="hidden" name="page" value="<?php echo esc_attr( Weather::MENU_SLUG ); ?>">
			<label for="fh-weather-date"><?php esc_html_e( 'Delivery date', 'fishotel-misc-plugin' ); ?></label>
			<input type="date" id="fh-weather-date" name="date" class="fh-weather-input" value="<?php echo esc_attr( $date ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Show', 'fishotel-misc-plugin' ); ?></button>
		</form>

		<?php if ( $upcoming ) : ?>
			<div class="fh-weather-dates">
				<?php foreach ( $upcoming as $fh_ymd => $fh_count ) : ?>
					<a class="fh-weather-date-chip<?php echo $fh_ymd === $date ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'date', $fh_ymd, $fh_page_url ) ); ?>">
						<?php echo esc_html( Note::short_day( $fh_ymd ) . ' ' . Note::long_date( $fh_ymd ) ); ?>
						<span class="fh-weather-date-chip__count"><?php echo esc_html( $fh_count ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="fh-weather-run">
			<button type="button" class="button fh-weather-run-scan"><?php esc_html_e( 'Run nightly scan now', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-weather-run__meta">
				<?php
				if ( ! empty( $last_run['at'] ) ) {
					/* translators: 1: date/time, 2: orders checked, 3: unavailable */
					echo esc_html( sprintf( __( 'Last run %1$s: %2$d checked, %3$d unavailable.', 'fishotel-misc-plugin' ), $last_run['at'], (int) ( $last_run['checked'] ?? 0 ), (int) ( $last_run['unavailable'] ?? 0 ) ) );
				} else {
					esc_html_e( 'The nightly job has not run yet.', 'fishotel-misc-plugin' );
				}
				if ( '' !== $next_run ) {
					/* translators: %s: date/time */
					echo ' ' . esc_html( sprintf( __( 'Next run: %s', 'fishotel-misc-plugin' ), $next_run ) );
				}
				?>
			</span>
			<span class="fh-weather-status" aria-live="polite"></span>
		</div>
	</div>

	<h2 class="fh-weather-heading">
		<?php
		/* translators: 1: weekday, 2: long date */
		echo esc_html( sprintf( __( 'Orders delivering %1$s, %2$s', 'fishotel-misc-plugin' ), Note::short_day( $date ), Note::long_date( $date ) ) );
		?>
	</h2>

	<div class="fh-weather-totals" id="fh-weather-totals">
		<div class="fh-weather-tile fh-weather-tile--orders">
			<span class="fh-weather-tile__count" data-total="orders"><?php echo esc_html( $totals['orders'] ); ?></span>
			<span class="fh-weather-tile__label"><?php esc_html_e( 'Orders', 'fishotel-misc-plugin' ); ?></span>
		</div>
		<?php foreach ( $fh_tiles as $fh_key => $fh_tile ) : ?>
			<div class="fh-weather-tile fh-weather-tile--<?php echo esc_attr( $fh_tile[1] ); ?>">
				<span class="fh-weather-tile__count" data-total="<?php echo esc_attr( $fh_key ); ?>"><?php echo esc_html( $totals[ $fh_key ] ); ?></span>
				<span class="fh-weather-tile__label"><?php echo esc_html( $fh_tile[0] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-cloud"></span>
			<p><?php esc_html_e( 'No open orders for this delivery date.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped fh-weather-table" id="fh-weather-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Destination', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-weather-num"><?php esc_html_e( 'High', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-weather-num"><?php esc_html_e( 'Low', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $fh_row ) : ?>
					<tr data-order-id="<?php echo esc_attr( $fh_row['order_id'] ); ?>" data-action="<?php echo esc_attr( $fh_row['action'] ); ?>">
						<td>
							<a href="<?php echo esc_url( $fh_row['edit_url'] ); ?>"><strong>#<?php echo esc_html( $fh_row['number'] ); ?></strong></a>
							<span class="fh-weather-sub"><?php echo esc_html( $fh_row['status'] ); ?></span>
							<?php if ( $fh_row['is_fulfillment'] ) : ?>
								<span class="fh-weather-sub">
									<?php
									/* translators: %d: number of source orders */
									echo esc_html( sprintf( _n( 'combined box · %d order', 'combined box · %d orders', $fh_row['sources'], 'fishotel-misc-plugin' ), $fh_row['sources'] ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $fh_row['customer'] ); ?></td>
						<td>
							<?php echo esc_html( $fh_row['place'] ); ?>
							<span class="fh-weather-sub"><?php echo esc_html( $fh_row['zip'] ); ?></span>
						</td>
						<td class="fh-weather-num" data-cell="high"><?php echo null !== $fh_row['high'] ? esc_html( $fh_row['high'] . '°F' ) : '&mdash;'; ?></td>
						<td class="fh-weather-num" data-cell="low"><?php echo null !== $fh_row['low'] ? esc_html( $fh_row['low'] . '°F' ) : '&mdash;'; ?></td>
						<td data-cell="action" title="<?php echo esc_attr( $fh_row['summary'] ); ?>">
							<?php echo Admin::badge_html( $fh_row['action'], $fh_row['at_line'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
							<?php if ( $fh_row['has_email'] ) : ?>
								<span class="fh-weather-sub"><?php esc_html_e( 'email drafted', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-cell="note_status" class="fh-weather-note-status fh-weather-note-status--<?php echo esc_attr( $fh_row['note_status'] ); ?>">
							<?php echo esc_html( Admin::note_status_label( $fh_row['note_status'] ) ); ?>
						</td>
						<td class="fh-weather-actions">
							<button type="button" class="button fh-weather-check" data-order-id="<?php echo esc_attr( $fh_row['order_id'] ); ?>"><?php esc_html_e( 'Check now', 'fishotel-misc-plugin' ); ?></button>
							<span class="fh-weather-status" aria-live="polite"></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="fh-weather-footnote">
		<?php esc_html_e( 'Every band goes by the delivery-day HIGH at the destination. Combined boxes show the fulfillment order; their source orders get the same note when "also note source orders" is on. Nothing here sends email or changes an order status.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
