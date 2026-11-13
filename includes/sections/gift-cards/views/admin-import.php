<?php
/**
 * Import from the old plugin: its tables, the schema check, the dry run,
 * the import and the last report (owner only).
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $data check, report, blockers.
 * @var array $ctx  Admin::context().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Money;

$fh_check  = $data['check'];
$fh_report = $data['report'];
$fh_kinds  = array(
	'schema'                => __( 'The tables', 'fishotel-misc-plugin' ),
	'duplicate_code'        => __( 'Same code twice', 'fishotel-misc-plugin' ),
	'code_taken'            => __( 'Code already used here', 'fishotel-misc-plugin' ),
	'bad_amount'            => __( 'Amount not in cents', 'fishotel-misc-plugin' ),
	'negative_balance'      => __( 'Negative amount', 'fishotel-misc-plugin' ),
	'unknown_type'          => __( 'Unknown activity', 'fishotel-misc-plugin' ),
	'empty_code'            => __( 'No code', 'fishotel-misc-plugin' ),
	'left_more_than_issued' => __( 'More left than issued', 'fishotel-misc-plugin' ),
	'history_differs'       => __( 'History does not add up', 'fishotel-misc-plugin' ),
	'owner_missing'         => __( 'Owner is gone', 'fishotel-misc-plugin' ),
	'order_missing'         => __( 'Order is gone', 'fishotel-misc-plugin' ),
	'expiry'                => __( 'Old expiry date', 'fishotel-misc-plugin' ),
	'future_delivery'       => __( 'E-mail still waiting', 'fishotel-misc-plugin' ),
	'orphan_activity'       => __( 'Activity without a card', 'fishotel-misc-plugin' ),
	'changed_in_both'       => __( 'Used here since', 'fishotel-misc-plugin' ),
	'not_reconciled'        => __( 'Copy does not match', 'fishotel-misc-plugin' ),
	'failed'                => __( 'Could not be copied', 'fishotel-misc-plugin' ),
);
$fh_button = static function ( $op, $label, $primary = false, $confirm = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fh-gc-inline"' . ( '' !== $confirm ? ' data-fh-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
	wp_nonce_field( Admin::NONCE );
	echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
};
?>
<div class="fishotel-misc-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-database-import"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'The old plugin\'s cards', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'This copies every card, its balance and its whole history from WooCommerce Gift Cards. It only ever reads the old tables — they stay exactly as they are, as the backup. Run the dry run first: it writes nothing and shows what would be copied, what is owed to the cent, and every difference. The import can be run again at any time: it never copies anything twice.', 'fishotel-misc-plugin' ); ?></p>
	<ul class="fh-gc-list">
		<?php foreach ( $fh_check['tables'] as $fh_t ) : ?>
			<li><code><?php echo esc_html( $fh_t['table'] ); ?></code> — <?php echo $fh_t['exists'] ? esc_html( sprintf( /* translators: %d: columns */ __( 'found, %d columns', 'fishotel-misc-plugin' ), (int) $fh_t['columns'] ) ) : esc_html__( 'not on this site', 'fishotel-misc-plugin' ); ?></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $fh_check['found'] && ! $fh_check['ok'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<strong><?php esc_html_e( 'The old tables are not shaped the way this importer expects, so it will not import. Each difference:', 'fishotel-misc-plugin' ); ?></strong>
			<ul><?php foreach ( $fh_check['differences'] as $fh_d ) : ?><li><?php echo esc_html( $fh_d['detail'] ); ?></li><?php endforeach; ?></ul>
			<?php esc_html_e( 'The expected shape is in one file: includes/sections/gift-cards/class-legacy-map.php. Make it say what the real tables hold, and check again.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>
	<?php if ( $fh_check['found'] ) : ?>
		<p>
			<?php $fh_button( 'dry_run', __( 'Dry run (writes nothing)', 'fishotel-misc-plugin' ) ); ?>
			<?php $fh_button( 'import', __( 'Import', 'fishotel-misc-plugin' ), true, __( 'Copy the old cards into FisHotel\'s gift cards now? The old tables are not changed.', 'fishotel-misc-plugin' ) ); ?>
		</p>
	<?php endif; ?>
</div>

<?php if ( is_array( $fh_report ) ) : ?>
	<div class="fishotel-misc-card fh-gc-report">
		<h2>
		<?php
		echo 'import' === $fh_report['kind'] ? esc_html__( 'The last import', 'fishotel-misc-plugin' ) : esc_html__( 'The last dry run', 'fishotel-misc-plugin' );
		echo ' — ' . esc_html( wp_date( 'M j, Y g:i a', (int) $fh_report['at'] ) );
		?>
		</h2>
		<?php if ( 'import' === $fh_report['kind'] ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--<?php echo ! empty( $fh_report['reconciled'] ) ? 'success' : 'error'; ?>">
				<?php echo ! empty( $fh_report['reconciled'] ) ? esc_html__( 'Reconciled: every old card is here and holds exactly the old balance, to the cent.', 'fishotel-misc-plugin' ) : esc_html__( 'NOT reconciled: see the differences below.', 'fishotel-misc-plugin' ); ?>
			</div>
		<?php elseif ( empty( $fh_report['ok'] ) ) : ?>
			<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'Something below stops the import (marked "stops the import").', 'fishotel-misc-plugin' ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $fh_report['counts']['cards'] ) ) : ?>
			<table class="widefat fh-gc-table fh-gc-table--narrow">
				<tbody>
					<tr><th><?php esc_html_e( 'Cards', 'fishotel-misc-plugin' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1: all, 2: active, 3: disabled */ __( '%1$d (%2$d working, %3$d disabled)', 'fishotel-misc-plugin' ), (int) $fh_report['counts']['cards'], (int) $fh_report['counts']['active'], (int) $fh_report['counts']['disabled'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Activity rows', 'fishotel-misc-plugin' ); ?></th><td><?php echo esc_html( (string) (int) $fh_report['counts']['activity'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Owed on working cards', 'fishotel-misc-plugin' ); ?></th><td><?php echo esc_html( Money::words( (int) $fh_report['outstanding']['active'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Owed on disabled cards', 'fishotel-misc-plugin' ); ?></th><td><?php echo esc_html( Money::words( (int) $fh_report['outstanding']['disabled'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Owed in all', 'fishotel-misc-plugin' ); ?></th><td><strong><?php echo esc_html( Money::words( (int) $fh_report['outstanding']['total'] ) ); ?></strong></td></tr>
					<?php if ( isset( $fh_report['tally'] ) ) : ?>
						<tr><th><?php esc_html_e( 'This run', 'fishotel-misc-plugin' ); ?></th><td><?php echo esc_html( sprintf( /* translators: 1-5: counts */ __( '%1$d new, %2$d brought up to date, %3$d unchanged, %4$d used here since (left alone), %5$d could not be copied', 'fishotel-misc-plugin' ), (int) $fh_report['tally']['created'], (int) $fh_report['tally']['updated'], (int) $fh_report['tally']['same'], (int) $fh_report['tally']['both'], (int) $fh_report['tally']['failed'] ) ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $fh_report['differences'] ) : ?>
			<h3>
			<?php
			/* translators: %d: differences */
			echo esc_html( sprintf( _n( '%d difference', '%d differences', (int) $fh_report['listed'], 'fishotel-misc-plugin' ), (int) $fh_report['listed'] ) );
			?>
			</h3>
			<div class="fh-gc-scroll">
			<table class="widefat fh-gc-table">
				<thead><tr><th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Old card', 'fishotel-misc-plugin' ); ?></th><th><?php esc_html_e( 'Detail', 'fishotel-misc-plugin' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $fh_report['differences'] as $fh_d ) : ?>
					<tr class="<?php echo ! empty( $fh_d['blocking'] ) ? 'is-blocking' : ''; ?>">
						<td><?php echo esc_html( $fh_kinds[ $fh_d['kind'] ] ?? $fh_d['kind'] ); ?></td>
						<td><?php echo (int) $fh_d['card'] > 0 ? '#' . esc_html( (string) (int) $fh_d['card'] ) : '—'; ?></td>
						<td><?php echo esc_html( $fh_d['detail'] ); ?></td>
						<td><?php echo ! empty( $fh_d['blocking'] ) ? '<strong>' . esc_html__( 'stops the import', 'fishotel-misc-plugin' ) . '</strong>' : esc_html__( 'for your information', 'fishotel-misc-plugin' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div class="fishotel-misc-card">
	<h2><?php esc_html_e( 'Can Live be switched on?', 'fishotel-misc-plugin' ); ?></h2>
	<?php if ( ! $data['blockers'] ) : ?>
		<p><?php esc_html_e( 'Yes — nothing stops it (Settings → Mode).', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<ul class="fh-gc-list"><?php foreach ( $data['blockers'] as $fh_b ) : ?><li><?php echo esc_html( $fh_b ); ?></li><?php endforeach; ?></ul>
	<?php endif; ?>
</div>
