<?php
/**
 * To do — everything due today as ONE list, by water system: a block per
 * building with fish (the whole shared system) and a block per temporary
 * tank that is set up. A due dose, test or water change is one line for
 * the system and is ticked once; the feeding is one line that opens to
 * the tanks.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $list         Front_Desk::todo_screen( Todo::build() ).
 * @var string $today        Site date.
 * @var string $tab          'todo'.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Temp;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;

$fh_left  = (int) $list['left'];
$fh_total = (int) $list['total'];
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

	<div class="fh-cr-todo-top<?php echo ! empty( $list['all_done'] ) ? ' is-done' : ''; ?>">
		<p class="fh-cr-todo-count">
			<?php if ( 0 === $fh_total ) : ?>
				<?php esc_html_e( 'Nothing is due today.', 'fishotel-misc-plugin' ); ?>
			<?php elseif ( 0 === $fh_left ) : ?>
				<?php esc_html_e( 'All done.', 'fishotel-misc-plugin' ); ?>
			<?php else : ?>
				<strong><?php echo esc_html( $fh_left ); ?></strong>
				<?php echo esc_html( _n( 'thing left to do', 'things left to do', $fh_left, 'fishotel-misc-plugin' ) ); ?>
				<span class="fh-cr-muted"><?php echo esc_html( sprintf( /* translators: %d: every line of the list */ __( 'of %d', 'fishotel-misc-plugin' ), $fh_total ) ); ?></span>
			<?php endif; ?>
		</p>
		<?php foreach ( (array) $list['flags'] as $fh_flag ) : ?>
			<span class="fh-cr-todo-flag<?php echo ( ! empty( $fh_flag['low'] ) || ! empty( $fh_flag['overdue'] ) ) ? ' is-bad' : ''; ?>"><?php echo esc_html( Admin_Temp::flag_text( $fh_flag ) ); ?></span>
		<?php endforeach; ?>
		<span class="fh-cr-todo-top__meta">
			<?php echo esc_html( $today ); ?>
			· <a href="<?php echo esc_url( Clean_Room::front_desk_url( 0 ) . '&list=1' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'On the phone', 'fishotel-misc-plugin' ); ?> &rarr;</a>
		</span>
	</div>
	<p class="fh-cr-status fh-cr-todo-status" aria-live="polite"></p>

	<?php if ( ! $list['blocks'] ) : ?>
		<p class="fh-cr-muted"><?php esc_html_e( 'No quarantine system and no temporary tank is in use.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<?php foreach ( (array) $list['blocks'] as $fh_block ) : ?>
		<?php
		$fh_temp = 'temp' === $fh_block['type'];
		$fh_meta = array();
		if ( $fh_temp ) {
			$fh_meta[] = $fh_block['kind_label'];
			if ( '' !== $fh_block['purpose'] ) {
				$fh_meta[] = $fh_block['purpose'];
			}
			$fh_meta[] = __( 'its own water', 'fishotel-misc-plugin' ) . ( $fh_block['gallons'] > 0 ? ' · ' . (int) $fh_block['gallons'] . ' gal' : '' );
		} elseif ( $fh_block['gallons'] > 0 ) {
			$fh_meta[] = Layout::system_label( $fh_block['gallons'] );
		}
		$fh_meta[] = $fh_block['schedule'];
		if ( '' !== $fh_block['day_text'] ) {
			$fh_meta[] = $fh_block['day_text'];
		}
		if ( $fh_temp ) {
			/* translators: %d: fish */
			$fh_meta[] = $fh_block['fish'] > 0 ? sprintf( _n( '%d fish', '%d fish', (int) $fh_block['fish'], 'fishotel-misc-plugin' ), (int) $fh_block['fish'] ) : __( 'no fish in it', 'fishotel-misc-plugin' );
		} elseif ( $fh_block['tanks'] > 0 ) {
			/* translators: %d: tanks */
			$fh_meta[] = sprintf( _n( '%d tank with fish', '%d tanks with fish', (int) $fh_block['tanks'], 'fishotel-misc-plugin' ), (int) $fh_block['tanks'] );
		}
		$fh_cu = Admin_Temp::copper_text( (array) $fh_block['copper'] );
		?>
		<section class="fh-cr-todo-block fh-cr-todo-block--<?php echo esc_attr( $fh_block['type'] ); ?><?php echo ( $fh_block['items'] && 0 === (int) $fh_block['left'] ) ? ' is-done' : ''; ?><?php echo ! $fh_block['items'] ? ' is-idle' : ''; ?>">
			<header class="fh-cr-todo-block__head">
				<h2>
					<?php if ( $fh_temp ) : ?>
						<span class="fh-cr-temp-mark"><?php esc_html_e( 'Temporary', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
					<?php echo esc_html( $fh_block['title'] ); ?>
				</h2>
				<span class="fh-cr-building__meta"><?php echo esc_html( implode( ' · ', array_filter( $fh_meta, 'strlen' ) ) ); ?></span>
				<?php if ( '' !== $fh_cu ) : ?>
					<span class="fh-cr-cu fh-cr-cu--<?php echo esc_attr( $fh_block['copper']['state'] ); ?>"><?php echo esc_html( $fh_cu ); ?></span>
				<?php endif; ?>
				<?php if ( $fh_temp ) : ?>
					<a class="fh-cr-tank__link" href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_block['number'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Front Desk', 'fishotel-misc-plugin' ); ?> &rarr;</a>
				<?php endif; ?>
			</header>

			<?php if ( ! $fh_block['items'] ) : ?>
				<p class="fh-cr-building__empty">
					<?php
					if ( $fh_temp ) {
						esc_html_e( 'Nothing is due on this tank today.', 'fishotel-misc-plugin' );
					} else {
						esc_html_e( 'No fish in this system — nothing is due.', 'fishotel-misc-plugin' );
					}
					?>
				</p>
			<?php else : ?>
				<ul class="fh-cr-todo-lines">
					<?php foreach ( $fh_block['items'] as $fh_item ) : ?>
						<?php
						$fh_feed   = 'feed' === $fh_item['scope'];
						$fh_detail = Admin_Temp::line_detail( $fh_item );
						?>
						<li class="fh-cr-todo-line<?php echo ! empty( $fh_item['done'] ) ? ' is-done' : ''; ?>">
							<?php if ( ! empty( $fh_item['done'] ) ) : ?>
								<span class="fh-cr-todo-check" aria-label="<?php esc_attr_e( 'done', 'fishotel-misc-plugin' ); ?>">&#10003;</span>
							<?php else : ?>
								<button type="button" class="button fh-cr-todo-tick" data-scope="<?php echo esc_attr( 'tank' === $fh_item['scope'] ? 'tank' : 'system' ); ?>" data-building="<?php echo esc_attr( $fh_item['building_id'] ); ?>" data-tank="<?php echo esc_attr( $fh_item['tank'] ); ?>" data-key="<?php echo esc_attr( $fh_item['key'] ); ?>" data-kind="<?php echo esc_attr( $fh_item['kind'] ); ?>" data-label="<?php echo esc_attr( $fh_item['label'] ); ?>">
									<?php echo esc_html( $fh_feed ? __( 'All fed', 'fishotel-misc-plugin' ) : __( 'Done', 'fishotel-misc-plugin' ) ); ?>
								</button>
							<?php endif; ?>
							<div class="fh-cr-todo-line__body">
								<span class="fh-cr-todo-line__step"><?php echo esc_html( $fh_feed ? Admin_Temp::feed_text( $fh_item ) : $fh_item['label'] ); ?></span>
								<?php if ( '' !== $fh_detail ) : ?>
									<span class="fh-cr-todo-line__dose"><?php echo esc_html( $fh_detail ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $fh_item['for'] ) : ?>
									<span class="fh-cr-todo-line__for"><?php echo esc_html( sprintf( /* translators: %s: "day 2 group" */ __( 'for the %s', 'fishotel-misc-plugin' ), $fh_item['for'] ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $fh_item['note'] ) : ?>
									<span class="fh-cr-todo-line__note"><?php echo esc_html( $fh_item['note'] ); ?></span>
								<?php endif; ?>
								<?php if ( $fh_feed ) : ?>
									<details class="fh-cr-todo-tanks"<?php echo ( $fh_item['left'] > 0 && $fh_item['left'] < $fh_item['count'] ) ? ' open' : ''; ?>>
										<summary><?php esc_html_e( 'the tanks', 'fishotel-misc-plugin' ); ?></summary>
										<?php foreach ( $fh_item['tanks'] as $fh_fed ) : ?>
											<?php if ( $fh_fed['done'] ) : ?>
												<span class="fh-cr-todo-tank is-done">&#10003; <?php echo esc_html( $fh_fed['number'] ); ?></span>
											<?php else : ?>
												<button type="button" class="button button-small fh-cr-todo-tick fh-cr-todo-tank" data-scope="tank" data-tank="<?php echo esc_attr( $fh_fed['number'] ); ?>" data-key="<?php echo esc_attr( $fh_item['key'] ); ?>" data-kind="feed" data-label="<?php echo esc_attr( $fh_item['label'] ); ?>"><?php echo esc_html( $fh_fed['number'] ); ?></button>
											<?php endif; ?>
										<?php endforeach; ?>
									</details>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'A building is one water: a dose, a test or a water change is listed once for the whole system and ticked once — here, on any of its tanks\' own screens, by a dose logged on the Front Desk or by a copper reading taken on any tank of that building; every tank then shows it done. The feeding is per tank: one line per building that opens to the tanks. A temporary tank is its own water, runs its own schedule and counts its days from its own treatment start.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
