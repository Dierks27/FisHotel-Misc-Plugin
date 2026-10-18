<?php
/**
 * Room Board — Area → Building → Level grid of tank cards.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array  $groups       Layout::group() over tanks with occupants.
 * @var array  $filters      area, species.
 * @var array  $totals       Admin::totals().
 * @var array  $settings     Settings.
 * @var array  $protocols    Settings::protocols().
 * @var array  $doses        Store::latest_doses(): building id => medication => { label, logged_at }.
 * @var string $today        Site date.
 * @var bool   $tables_ok    Whether the tables exist.
 * @var bool   $can_settings Whether the Settings tab is offered.
 * @var string $labels_url   Printable labels URL.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Layout;
use FisHotel\Misc\Sections\Clean_Room\Protocol;

$fh_species = (string) $filters['species'];
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

	<div class="fh-cr-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-cr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Clean_Room::MENU_SLUG ); ?>">
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Area', 'fishotel-misc-plugin' ); ?></span>
				<select name="area" class="fh-cr-input">
					<option value=""><?php esc_html_e( 'Both', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( Layout::AREAS as $fh_key => $fh_label ) : ?>
						<option value="<?php echo esc_attr( $fh_key ); ?>" <?php selected( $filters['area'], $fh_key ); ?>><?php echo esc_html( $fh_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-cr-filter fh-cr-filter--grow">
				<span><?php esc_html_e( 'Species', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-cr-input" value="<?php echo esc_attr( $fh_species ); ?>" placeholder="<?php esc_attr_e( 'cardinal', 'fishotel-misc-plugin' ); ?>">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
			<a class="button" href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Reset', 'fishotel-misc-plugin' ); ?></a>
		</form>
		<div class="fh-cr-actions">
			<a class="button" href="<?php echo esc_url( Clean_Room::front_desk_url( 0 ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Front Desk', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $labels_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print tank labels', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<div class="fh-cr-summary">
		<span class="fh-cr-chip"><strong><?php echo esc_html( $totals['in_qt'] ); ?></strong> <?php esc_html_e( 'groups in QT', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $totals['cleared'] ); ?></strong> <?php esc_html_e( 'cleared', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip fh-cr-chip--ready"><strong><?php echo esc_html( $totals['ready'] ); ?></strong> <?php esc_html_e( 'sale-ready', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $totals['live'] ); ?></strong> <?php esc_html_e( 'fish alive', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $totals['empty'] ); ?></strong> / <?php echo esc_html( $totals['tanks'] ); ?> <?php esc_html_e( 'tanks empty', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-summary__meta"><?php echo esc_html( $today ); ?></span>
	</div>

	<?php foreach ( $groups as $fh_area => $fh_group ) : ?>
		<?php
		if ( '' !== $filters['area'] && $filters['area'] !== $fh_area ) {
			continue;
		}
		?>
		<section class="fh-cr-area">
			<h2 class="fh-cr-area__title"><?php echo esc_html( $fh_group['label'] ); ?></h2>

			<?php foreach ( $fh_group['buildings'] as $fh_entry ) : ?>
				<?php
				$fh_building = $fh_entry['building'];
				$fh_in_qt    = 0;
				$fh_ready    = 0;
				foreach ( $fh_entry['tanks'] as $fh_t ) {
					$fh_in_qt += (int) ( $fh_t['in_qt'] ?? 0 );
					$fh_ready += (int) ( $fh_t['ready'] ?? 0 );
				}
				$fh_coming  = 'coming_soon' === $fh_building['status'];
				$fh_off     = 'offline' === $fh_building['status'];
				$fh_gallons = Layout::system_gallons( $fh_building, $fh_entry['tanks'] );
				$fh_doses   = isset( $doses[ (int) $fh_building['id'] ] ) ? $doses[ (int) $fh_building['id'] ] : array();
				?>
				<div class="fh-cr-building<?php echo $fh_coming ? ' is-coming' : ''; ?><?php echo $fh_off ? ' is-offline' : ''; ?>">
					<header class="fh-cr-building__head">
						<h3><?php echo esc_html( $fh_entry['label'] ); ?></h3>
						<span class="fh-cr-building__meta">
							<?php echo esc_html( $fh_gallons > 0 ? Layout::system_label( $fh_gallons ) : __( 'shared water (sump + skimmer)', 'fishotel-misc-plugin' ) ); ?>
							· <?php echo esc_html( Protocol::template_for( $fh_building, $protocols ) ); ?>
							<?php if ( $fh_coming ) : ?>
								· <?php esc_html_e( 'coming soon', 'fishotel-misc-plugin' ); ?>
							<?php elseif ( $fh_off ) : ?>
								· <?php esc_html_e( 'offline', 'fishotel-misc-plugin' ); ?>
							<?php else : ?>
								· <?php echo esc_html( sprintf( /* translators: %d: groups in QT */ __( '%d in QT', 'fishotel-misc-plugin' ), $fh_in_qt ) ); ?>
								<?php if ( $fh_ready > 0 ) : ?>
									· <?php echo esc_html( sprintf( /* translators: %d: sale-ready groups */ __( '%d sale-ready', 'fishotel-misc-plugin' ), $fh_ready ) ); ?>
								<?php endif; ?>
							<?php endif; ?>
						</span>
						<?php if ( $fh_doses ) : ?>
							<span class="fh-cr-building__doses">
								<?php
								$fh_parts = array();
								foreach ( $fh_doses as $fh_dose ) {
									$fh_parts[] = $fh_dose['label'] . ' ' . Admin::site_time( $fh_dose['logged_at'] );
								}
								echo esc_html( __( 'last dose:', 'fishotel-misc-plugin' ) . ' ' . implode( ' · ', $fh_parts ) );
								?>
							</span>
						<?php endif; ?>
					</header>

					<?php if ( ! $fh_entry['tanks'] ) : ?>
						<p class="fh-cr-building__empty"><?php esc_html_e( 'No tanks yet.', 'fishotel-misc-plugin' ); ?></p>
					<?php endif; ?>

					<?php foreach ( array( 2, 1 ) as $fh_level ) : ?>
						<?php if ( empty( $fh_entry['levels'][ $fh_level ] ) ) { continue; } ?>
						<div class="fh-cr-level">
							<span class="fh-cr-level__label"><?php echo esc_html( Layout::LEVELS[ $fh_level ] ); ?></span>
							<div class="fh-cr-tanks">
								<?php foreach ( $fh_entry['levels'][ $fh_level ] as $fh_tank ) : ?>
									<?php
									$fh_occupants = (array) ( $fh_tank['occupants'] ?? array() );
									$fh_match     = false;
									if ( '' !== $fh_species ) {
										foreach ( $fh_occupants as $fh_stay ) {
											if ( Admin::matches_species( $fh_stay, $fh_species ) ) {
												$fh_match = true;
												break;
											}
										}
									}
									$fh_dim    = ! $fh_occupants || ( '' !== $fh_species && ! $fh_match ) || 'offline' === $fh_tank['status'];
									$fh_todo   = (array) ( $fh_tank['todo'] ?? array() );
									$fh_due    = array_values( array_filter( $fh_todo, static function ( $i ) { return empty( $i['done'] ); } ) );
									$fh_open   = count( $fh_due );
									$fh_labels = array_values( array_unique( array_map( static function ( $i ) { return (string) $i['label']; }, $fh_due ) ) );
									$fh_cu     = $fh_tank['copper'] ?? null;
									$fh_state  = $fh_cu ? Protocol::copper_state( (float) $fh_cu['value'], (float) $settings['copper_floor'] ) : 'none';
									?>
									<div class="fh-cr-tank<?php echo $fh_dim ? ' is-dim' : ''; ?>">
										<div class="fh-cr-tank__head">
											<span class="fh-cr-tank__number"><?php echo esc_html( $fh_tank['number'] ); ?></span>
											<span class="fh-cr-tank__size"><?php echo esc_html( $fh_tank['size_label'] ); ?><?php echo 'offline' === $fh_tank['status'] ? ' · ' . esc_html__( 'offline', 'fishotel-misc-plugin' ) : ''; ?></span>
										</div>
										<?php if ( $fh_occupants ) : ?>
											<ul class="fh-cr-tank__list">
												<?php foreach ( $fh_occupants as $fh_stay ) : ?>
													<li class="<?php echo ( '' !== $fh_species && Admin::matches_species( $fh_stay, $fh_species ) ) ? 'is-hit' : ''; ?>">
														<span class="fh-cr-tank__species"><?php echo esc_html( $fh_stay['species'] ); ?></span>
														· <?php echo esc_html( $fh_stay['qty_live'] ); ?>
														<?php if ( null !== $fh_stay['day'] ) : ?>
															· <?php echo esc_html( sprintf( /* translators: %d: day number */ __( 'day %d', 'fishotel-misc-plugin' ), $fh_stay['day'] ) ); ?>
														<?php endif; ?>
														<?php echo Admin::badge_html( $fh_stay ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p class="fh-cr-tank__empty"><?php esc_html_e( 'empty', 'fishotel-misc-plugin' ); ?></p>
										<?php endif; ?>
										<div class="fh-cr-tank__foot">
											<?php if ( $fh_cu ) : ?>
												<span class="fh-cr-cu fh-cr-cu--<?php echo esc_attr( $fh_state ); ?>">Cu <?php echo esc_html( rtrim( rtrim( number_format( (float) $fh_cu['value'], 2, '.', '' ), '0' ), '.' ) ); ?></span>
											<?php endif; ?>
											<?php if ( $fh_todo ) : ?>
												<span class="fh-cr-todo"><?php echo esc_html( sprintf( /* translators: 1: open items, 2: all items */ __( 'to-do %1$d/%2$d', 'fishotel-misc-plugin' ), $fh_open, count( $fh_todo ) ) ); ?></span>
												<?php if ( $fh_labels ) : ?>
													<span class="fh-cr-todo__due"><?php echo esc_html( implode( ' · ', $fh_labels ) ); ?></span>
												<?php endif; ?>
											<?php endif; ?>
											<a class="fh-cr-tank__link" href="<?php echo esc_url( Clean_Room::front_desk_url( (int) $fh_tank['number'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Front Desk', 'fishotel-misc-plugin' ); ?> &rarr;</a>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>

	<p class="fh-cr-footnote">
		<?php echo esc_html( sprintf( /* translators: %d: days after check-in (the sale-ready setting) */ __( 'Numbering: hundreds = building, tens = level (1 bottom, 2 top), ones = position from the left. Every building is one water system. Arrivals land in the Spa; a stay is cleared the day it moves from the Spa into the Clean Room and sale-ready %d days after check-in (Settings). Each tank card lists what is still due today; each tank\'s QR opens its Front Desk screen.', 'fishotel-misc-plugin' ), (int) $settings['sale_ready_days'] ) ); ?>
	</p>
</div>
