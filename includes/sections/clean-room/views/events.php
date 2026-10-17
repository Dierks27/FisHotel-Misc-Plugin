<?php
/**
 * Events tab — the latest stay events and tank log entries.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array[] $events       Latest events.
 * @var array[] $logs         Latest tank logs.
 * @var int[]   $undone       Ids of the listed events that a listed undo already reversed.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Replay;

$tab = 'events';
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-cr-columns fh-cr-columns--events">
		<div>
			<h2 class="fh-cr-h2"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Stay events (latest %d)', 'fishotel-misc-plugin' ), Admin::EVENTS_LIMIT ) ); ?></h2>
			<?php if ( ! $events ) : ?>
				<div class="fishotel-misc-empty"><span class="dashicons dashicons-location-alt"></span><p><?php esc_html_e( 'No events yet.', 'fishotel-misc-plugin' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped fh-cr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When (site time)', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $fh_event ) : ?>
							<?php $fh_can_undo = ! Replay::is_reversal( $fh_event ) && ! in_array( (int) $fh_event['id'], $undone, true ) && null !== Replay::compensating( $fh_event ); ?>
							<tr data-stay="<?php echo esc_attr( $fh_event['stay_id'] ); ?>" data-event="<?php echo esc_attr( $fh_event['id'] ); ?>">
								<td class="fh-cr-when"><?php echo esc_html( Admin::site_time( $fh_event['event_at'] ) ); ?></td>
								<td><?php echo esc_html( $fh_event['species'] ); ?> <span class="fh-cr-sub">#<?php echo esc_html( $fh_event['stay_id'] ); ?></span></td>
								<td><span class="fh-cr-type fh-cr-type--<?php echo esc_attr( $fh_event['type'] ); ?>"><?php echo esc_html( $fh_event['type'] ); ?></span> <?php echo esc_html( Admin::event_text( $fh_event ) ); ?></td>
								<td><?php echo esc_html( '' !== $fh_event['actor_name'] ? $fh_event['actor_name'] : '—' ); ?> <span class="fh-cr-sub"><?php echo esc_html( $fh_event['source'] ); ?></span></td>
								<td class="fh-cr-row__actions">
									<?php if ( $fh_can_undo ) : ?>
										<button type="button" class="button button-small fh-cr-undo"><?php esc_html_e( 'Undo', 'fishotel-misc-plugin' ); ?></button>
									<?php elseif ( in_array( (int) $fh_event['id'], $undone, true ) ) : ?>
										<span class="fh-cr-sub"><?php esc_html_e( 'undone', 'fishotel-misc-plugin' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div>
			<h2 class="fh-cr-h2"><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Tank log (latest %d)', 'fishotel-misc-plugin' ), Admin::LOGS_LIMIT ) ); ?></h2>
			<?php if ( ! $logs ) : ?>
				<div class="fishotel-misc-empty"><span class="dashicons dashicons-clipboard"></span><p><?php esc_html_e( 'No copper readings or tick-offs yet.', 'fishotel-misc-plugin' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped fh-cr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When (site time)', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tank', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $fh_log ) : ?>
							<tr>
								<td class="fh-cr-when"><?php echo esc_html( Admin::site_time( $fh_log['logged_at'] ) ); ?></td>
								<td><?php echo esc_html( $fh_log['tank_number'] ? $fh_log['tank_number'] : '—' ); ?></td>
								<td><?php echo esc_html( Admin::log_text( $fh_log ) ); ?></td>
								<td><?php echo esc_html( '' !== $fh_log['actor_name'] ? $fh_log['actor_name'] : '—' ); ?> <span class="fh-cr-sub"><?php echo esc_html( $fh_log['source'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'Nothing is ever edited or deleted: Undo writes a compensating event (marked "undo") and is refused a second time for the same event. Times are stored in UTC and shown in site time.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
