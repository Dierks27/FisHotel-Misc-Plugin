<?php
/**
 * The history of the temporary tanks — every setup that was taken down,
 * or one setup in full: what it was, the treatment as it was run with
 * what was ticked, the doses and readings logged, the tank notes and the
 * groups that passed through.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array|null $setup        The setup shown in full (null = the list).
 * @var array|null $history      Temp_Tanks::history() of it.
 * @var array[]    $closed       The setups taken down, latest first (the list).
 * @var bool       $missing      A setup id that does not exist was asked for.
 * @var string     $today        Site date.
 * @var string     $tab          'board'.
 * @var bool       $tables_ok    Whether the tables exist.
 * @var bool       $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Temp_Tanks;

$fh_days = static function ( array $days ) {
	return $days ? implode( ', ', array_map( 'intval', $days ) ) : '—';
};
$fh_num  = static function ( $value ) {
	return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
};
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>

	<p class="fh-cr-count">
		<a href="<?php echo esc_url( Admin::page_url() . '#fh-cr-temp' ); ?>">&larr; <?php esc_html_e( 'Board', 'fishotel-misc-plugin' ); ?></a>
		<?php if ( $setup ) : ?>
			· <a href="<?php echo esc_url( Admin_Temp::history_url() ); ?>"><?php esc_html_e( 'History of the temporary tanks', 'fishotel-misc-plugin' ); ?></a>
		<?php endif; ?>
	</p>

	<?php if ( $missing ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--warning"><?php esc_html_e( 'That setup does not exist.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $setup ) : ?>

		<h2 class="fh-cr-h2"><?php esc_html_e( 'History of the temporary tanks', 'fishotel-misc-plugin' ); ?></h2>
		<?php if ( ! $closed ) : ?>
			<p class="fh-cr-muted"><?php esc_html_e( 'No temporary tank has been taken down yet. When one is, it is kept here: what it was, how its treatment ran, the doses and readings, and the groups that passed through.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Slot', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'For', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Gallons', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Treatment', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Set up', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Taken down', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $closed as $fh_row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( Layout::slot_name( (int) $fh_row['slot'] ) ); ?></strong> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( $fh_row['tank_number'] ); ?></span></td>
							<td><?php echo esc_html( '' !== $fh_row['name'] ? $fh_row['name'] : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $fh_row['purpose'] ? $fh_row['purpose'] : '—' ); ?></td>
							<td><?php echo esc_html( Temp_Tanks::kind_label( $fh_row['kind'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( (int) $fh_row['gallons'] > 0 ? (int) $fh_row['gallons'] : '—' ); ?></td>
							<td><?php echo esc_html( Temp_Tanks::schedule_label( $fh_row ) ); ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'from %s', 'fishotel-misc-plugin' ), $fh_row['start_date'] ) ); ?></span></td>
							<td><?php echo esc_html( Admin::site_time( $fh_row['created_at'] ) ); ?></td>
							<td><?php echo esc_html( $fh_row['closed_at'] ? Admin::site_time( $fh_row['closed_at'] ) : '—' ); ?></td>
							<td class="fh-cr-row__actions"><a class="button button-small" href="<?php echo esc_url( Admin_Temp::history_url( (int) $fh_row['id'] ) ); ?>"><?php esc_html_e( 'Open', 'fishotel-misc-plugin' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php else : ?>

		<?php $fh_label = Layout::slot_name( (int) $setup['slot'] ) . ' · ' . (int) $setup['tank_number']; ?>
		<h2 class="fh-cr-h2">
			<?php echo esc_html( $fh_label . ( '' !== $setup['name'] ? ' · ' . $setup['name'] : '' ) ); ?>
			<span class="fh-cr-badge fh-cr-badge--<?php echo 'clean' === $setup['kind'] ? 'cleared' : 'in_qt'; ?>"><?php echo esc_html( Temp_Tanks::kind_label( $setup['kind'] ) ); ?></span>
			<?php if ( $setup['open'] ) : ?>
				<span class="fh-cr-badge fh-cr-badge--ready"><?php esc_html_e( 'still set up', 'fishotel-misc-plugin' ); ?></span>
			<?php else : ?>
				<span class="fh-cr-badge fh-cr-badge--closed"><?php esc_html_e( 'taken down', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
		</h2>
		<div class="fh-cr-summary">
			<span class="fh-cr-chip"><?php esc_html_e( 'for', 'fishotel-misc-plugin' ); ?> <strong><?php echo esc_html( '' !== $setup['purpose'] ? $setup['purpose'] : '—' ); ?></strong></span>
			<span class="fh-cr-chip"><strong><?php echo esc_html( (int) $setup['gallons'] > 0 ? (int) $setup['gallons'] . ' gal' : __( 'gallons not known', 'fishotel-misc-plugin' ) ); ?></strong></span>
			<span class="fh-cr-chip"><?php esc_html_e( 'treatment', 'fishotel-misc-plugin' ); ?> <strong><?php echo esc_html( Temp_Tanks::schedule_label( $setup ) ); ?></strong> <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'from %s', 'fishotel-misc-plugin' ), $setup['start_date'] ) ); ?></span>
			<span class="fh-cr-chip"><?php esc_html_e( 'set up', 'fishotel-misc-plugin' ); ?> <strong><?php echo esc_html( Admin::site_time( $setup['created_at'] ) ); ?></strong><?php echo '' !== $setup['created_name'] ? ' · ' . esc_html( $setup['created_name'] ) : ''; ?></span>
			<?php if ( $setup['closed_at'] ) : ?>
				<span class="fh-cr-chip"><?php esc_html_e( 'taken down', 'fishotel-misc-plugin' ); ?> <strong><?php echo esc_html( Admin::site_time( $setup['closed_at'] ) ); ?></strong><?php echo '' !== $setup['closed_name'] ? ' · ' . esc_html( $setup['closed_name'] ) : ''; ?></span>
			<?php endif; ?>
		</div>
		<?php if ( '' !== trim( $setup['note'] ) ) : ?>
			<p class="fh-cr-temp-note"><?php echo esc_html( $setup['note'] ); ?></p>
		<?php endif; ?>

		<h3 class="fh-cr-h3"><?php esc_html_e( 'The treatment as it was run', 'fishotel-misc-plugin' ); ?></h3>
		<table class="widefat striped fh-cr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Step', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Kind', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Due', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Done on day', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history['run'] as $fh_step ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $fh_step['label'] ); ?></strong></td>
						<td><?php echo esc_html( str_replace( '_', ' ', $fh_step['kind'] ) ); ?></td>
						<td><?php echo esc_html( $fh_step['when'] ); ?></td>
						<td><?php echo esc_html( $fh_days( $fh_step['done'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $history['run'] ) : ?>
					<tr><td colspan="4" class="fh-cr-muted"><?php esc_html_e( 'No steps.', 'fishotel-misc-plugin' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<div class="fh-cr-columns">
			<div>
				<h3 class="fh-cr-h3"><?php esc_html_e( 'Doses', 'fishotel-misc-plugin' ); ?></h3>
				<?php if ( ! $history['doses'] ) : ?>
					<p class="fh-cr-muted"><?php esc_html_e( 'None logged.', 'fishotel-misc-plugin' ); ?></p>
				<?php else : ?>
					<table class="widefat striped fh-cr-table">
						<tbody>
							<?php foreach ( $history['doses'] as $fh_row ) : ?>
								<tr>
									<td><?php echo esc_html( Admin::site_time( $fh_row['logged_at'] ) ); ?></td>
									<td><?php echo null !== $fh_row['day'] ? esc_html( sprintf( /* translators: %d: day */ __( 'day %d', 'fishotel-misc-plugin' ), $fh_row['day'] ) ) : ''; ?></td>
									<td><strong><?php echo esc_html( $fh_row['text'] ); ?></strong></td>
									<td class="fh-cr-muted"><?php echo esc_html( $fh_row['actor'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<div>
				<h3 class="fh-cr-h3"><?php esc_html_e( 'Copper readings', 'fishotel-misc-plugin' ); ?></h3>
				<?php if ( ! $history['readings'] ) : ?>
					<p class="fh-cr-muted"><?php esc_html_e( 'None logged.', 'fishotel-misc-plugin' ); ?></p>
				<?php else : ?>
					<table class="widefat striped fh-cr-table">
						<tbody>
							<?php foreach ( $history['readings'] as $fh_row ) : ?>
								<tr>
									<td><?php echo esc_html( Admin::site_time( $fh_row['logged_at'] ) ); ?></td>
									<td><?php echo null !== $fh_row['day'] ? esc_html( sprintf( /* translators: %d: day */ __( 'day %d', 'fishotel-misc-plugin' ), $fh_row['day'] ) ) : ''; ?></td>
									<td><strong>Cu <?php echo esc_html( $fh_num( $fh_row['value'] ) ); ?></strong></td>
									<td class="fh-cr-muted"><?php echo esc_html( $fh_row['actor'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<h3 class="fh-cr-h3"><?php esc_html_e( 'Groups that passed through', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $history['groups'] ) : ?>
			<p class="fh-cr-muted"><?php esc_html_e( 'No fish were ever in it.', 'fishotel-misc-plugin' ); ?></p>
		<?php else : ?>
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Group', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'In', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Out', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history['groups'] as $fh_group ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $fh_group['species'] ); ?></strong> <span class="fh-cr-sub fh-cr-sub--inline">#<?php echo esc_html( $fh_group['stay_id'] ); ?><?php echo null !== $fh_group['qty'] ? ' · ' . esc_html( sprintf( /* translators: %d: fish */ __( '%d checked in here', 'fishotel-misc-plugin' ), (int) $fh_group['qty'] ) ) : ''; ?></span></td>
							<td><?php echo esc_html( $fh_group['in'] ); ?></td>
							<td>
								<?php
								if ( '' !== $fh_group['out'] ) {
									echo esc_html( $fh_group['out'] . ( $fh_group['to'] ? ' → ' . sprintf( /* translators: %d: tank number */ __( 'Tank %d', 'fishotel-misc-plugin' ), (int) $fh_group['to'] ) : '' ) );
								} else {
									echo esc_html( $setup['open'] ? __( 'still in it, or ended here', 'fishotel-misc-plugin' ) : __( 'ended here', 'fishotel-misc-plugin' ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $history['notes'] ) : ?>
			<h3 class="fh-cr-h3"><?php esc_html_e( 'Tank notes', 'fishotel-misc-plugin' ); ?></h3>
			<table class="widefat striped fh-cr-table">
				<tbody>
					<?php foreach ( $history['notes'] as $fh_row ) : ?>
						<tr>
							<td><?php echo esc_html( Admin::site_time( $fh_row['logged_at'] ) ); ?></td>
							<td><?php echo esc_html( $fh_row['text'] ); ?></td>
							<td class="fh-cr-muted"><?php echo esc_html( $fh_row['actor'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $history['ticks'] ) : ?>
			<h3 class="fh-cr-h3"><?php esc_html_e( 'Every tick', 'fishotel-misc-plugin' ); ?></h3>
			<table class="widefat striped fh-cr-table">
				<tbody>
					<?php foreach ( $history['ticks'] as $fh_row ) : ?>
						<tr>
							<td><?php echo esc_html( Admin::site_time( $fh_row['logged_at'] ) ); ?></td>
							<td><?php echo null !== $fh_row['day'] ? esc_html( sprintf( /* translators: %d: day */ __( 'day %d', 'fishotel-misc-plugin' ), $fh_row['day'] ) ) : ''; ?></td>
							<td><?php echo esc_html( $fh_row['text'] ); ?></td>
							<td class="fh-cr-muted"><?php echo esc_html( $fh_row['actor'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	<?php endif; ?>
</div>
