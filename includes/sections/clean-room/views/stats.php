<?php
/**
 * Stats tab — the yearly figures: per-order rows, the weighted totals,
 * the sheet-style averages, by supplier / origin / species group.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $stats        Stats::build().
 * @var int[]   $years        Years with orders.
 * @var int     $year         The year shown (0 = all).
 * @var array   $exports      xlsx / csv URLs.
 * @var string  $flash        One-time notice.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;

$tab      = 'stats';
$fh_t     = $stats['totals'];
$fh_sheet = $stats['sheet'];

/**
 * A grouping table.
 *
 * @param array[] $groups Finished buckets.
 * @param string  $label  The first column's heading.
 */
$fh_group_table = static function ( array $groups, $label ) {
	if ( ! $groups ) {
		echo '<p class="fh-cr-sub">' . esc_html__( 'Nothing here.', 'fishotel-misc-plugin' ) . '</p>';
		return;
	}
	?>
	<table class="widefat striped fh-cr-table">
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html( $label ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Orders', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Lines', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Ordered', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Received', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Survived', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Fill', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Survival', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Landed', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Up / down', 'fishotel-misc-plugin' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $groups as $fh_g ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $fh_g['name'] ); ?></strong></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['orders'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['lines'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['ordered'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['received'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['survived_known'] ? $fh_g['survived'] : '—' ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( $fh_g['doa'] ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_g['fill_rate'] ) ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_g['survival_rate'] ) ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_g['doa_rate'] ) ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_g['total'] ) ); ?></td>
					<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_g['revenue'] ) ); ?></td>
					<td class="fh-cr-num <?php echo $fh_g['up_down'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_g['up_down'] > 0 ? '+' : '' ) . Costing::money( $fh_g['up_down'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
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
			<input type="hidden" name="tab" value="stats">
			<label class="fh-cr-filter">
				<span><?php esc_html_e( 'Year', 'fishotel-misc-plugin' ); ?></span>
				<select name="year" class="fh-cr-input" onchange="this.form.submit()">
					<option value="0" <?php selected( 0, $year ); ?>><?php esc_html_e( 'All years', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $years as $fh_year ) : ?>
						<option value="<?php echo esc_attr( $fh_year ); ?>" <?php selected( (int) $fh_year, $year ); ?>><?php echo esc_html( $fh_year ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button></noscript>
		</form>
		<div class="fh-cr-actions">
			<a class="button" href="<?php echo esc_url( $exports['xlsx'] ); ?>"><?php esc_html_e( 'Export XLSX', 'fishotel-misc-plugin' ); ?></a>
			<a class="button" href="<?php echo esc_url( $exports['csv'] ); ?>"><?php esc_html_e( 'Export CSV', 'fishotel-misc-plugin' ); ?></a>
		</div>
	</div>

	<div class="fh-cr-summary">
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['orders'] ); ?></strong> <?php esc_html_e( 'orders', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['ordered'] ); ?></strong> <?php esc_html_e( 'ordered', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['received'] ); ?></strong> <?php esc_html_e( 'received', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip fh-cr-chip--ready"><strong><?php echo esc_html( $fh_sheet['through_qt'] ); ?></strong> <?php esc_html_e( 'fish through QT', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-chip"><strong><?php echo esc_html( $fh_t['doa'] ); ?></strong> <?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></span>
		<span class="fh-cr-summary__meta"><?php echo esc_html( $year > 0 ? (string) $year : __( 'all years', 'fishotel-misc-plugin' ) ); ?></span>
	</div>

	<div class="fh-cr-columns">
		<div class="fishotel-misc-card fh-cr-card">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-chart-area"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Weighted (Σ ÷ Σ over every order)', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<dl class="fh-cr-stats-block">
				<div><dt><?php esc_html_e( 'Fill rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_t['fill_rate'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Survival rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_t['survival_rate'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_t['doa_rate'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Landed in all', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::money( $fh_t['total'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue (survivors at FisHotel prices)', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::money( $fh_t['revenue'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Up or down', 'fishotel-misc-plugin' ); ?></dt><dd class="<?php echo $fh_t['up_down'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_t['up_down'] > 0 ? '+' : '' ) . Costing::money( $fh_t['up_down'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue ÷ landed', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::num( $fh_t['ratio'] ) ); ?></dd></div>
			</dl>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'Survival counts only the orders whose survivors are known, in both the top and the bottom of the fraction.', 'fishotel-misc-plugin' ); ?></p>
		</div>

		<div class="fishotel-misc-card fh-cr-card">
			<div class="fishotel-misc-card-header">
				<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-media-spreadsheet"></span></div>
				<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'As the spreadsheet averaged them', 'fishotel-misc-plugin' ); ?></h2>
			</div>
			<dl class="fh-cr-stats-block">
				<div><dt><?php esc_html_e( 'Fill rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_sheet['fill_rate'] ) ); ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( sprintf( /* translators: %d: batches */ __( 'over %d batches', 'fishotel-misc-plugin' ), $fh_sheet['fill_n'] ) ); ?></span></dd></div>
				<div><dt><?php esc_html_e( 'Survival rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_sheet['survival_rate'] ) ); ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( sprintf( /* translators: %d: batches */ __( 'over %d batches', 'fishotel-misc-plugin' ), $fh_sheet['survival_n'] ) ); ?></span></dd></div>
				<div><dt><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Costing::pct( $fh_sheet['doa_rate'] ) ); ?> <span class="fh-cr-sub fh-cr-sub--inline"><?php echo esc_html( sprintf( /* translators: %d: batches */ __( 'over %d batches', 'fishotel-misc-plugin' ), $fh_sheet['doa_n'] ) ); ?></span></dd></div>
				<div><dt><?php esc_html_e( 'Total fish through QT (Σ survived)', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_sheet['through_qt'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Batches that received anything', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( $fh_sheet['batches'] ); ?></dd></div>
			</dl>
			<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The unweighted average of each batch\'s rate, as the workbook\'s Controller & Stats tab did: a small batch counts as much as a big one; a batch that received nothing is left out, and one whose survivors are not typed yet is left out of the survival average (it was "not in the formulas yet").', 'fishotel-misc-plugin' ); ?></p>
		</div>
	</div>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'Orders', 'fishotel-misc-plugin' ); ?></h2>
	<?php if ( ! $stats['orders'] ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-chart-bar"></span><p><?php esc_html_e( 'No orders in this year.', 'fishotel-misc-plugin' ); ?></p></div>
	<?php else : ?>
		<div class="fh-cr-grid-wrap">
			<table class="widefat striped fh-cr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Ref', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Supplier', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Origin', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Received', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Lines', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Ordered', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Received', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Survived', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Fill', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Survival', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'DOA rate', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Landed', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Revenue', 'fishotel-misc-plugin' ); ?></th>
						<th scope="col" class="fh-cr-num"><?php esc_html_e( 'Up / down', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['orders'] as $fh_r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_r['id'] ) ); ?>"><strong><?php echo esc_html( $fh_r['ref'] ); ?></strong></a></td>
							<td><?php echo esc_html( '' !== $fh_r['supplier'] ? $fh_r['supplier'] : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $fh_r['origin'] ? $fh_r['origin'] : '—' ); ?></td>
							<td><?php echo esc_html( $fh_r['received_date'] ? $fh_r['received_date'] : ( $fh_r['order_date'] ? $fh_r['order_date'] . ' ' . __( '(ordered)', 'fishotel-misc-plugin' ) : '—' ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['lines'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['ordered'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['received'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['survived_known'] ? $fh_r['survived'] : '—' ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( $fh_r['doa'] ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['fill_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['survival_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_r['doa_rate'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['total'] ) ); ?></td>
							<td class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_r['revenue'] ) ); ?></td>
							<td class="fh-cr-num <?php echo $fh_r['up_down'] >= 0 ? 'fh-cr-up' : 'fh-cr-down'; ?>"><?php echo esc_html( ( $fh_r['up_down'] > 0 ? '+' : '' ) . Costing::money( $fh_r['up_down'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="4"><?php esc_html_e( 'Weighted totals', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['lines'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['ordered'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['received'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['survived_known'] ? $fh_t['survived'] : '—' ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( $fh_t['doa'] ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_t['fill_rate'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_t['survival_rate'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::pct( $fh_t['doa_rate'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_t['total'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( Costing::money( $fh_t['revenue'] ) ); ?></th>
						<th class="fh-cr-num"><?php echo esc_html( ( $fh_t['up_down'] > 0 ? '+' : '' ) . Costing::money( $fh_t['up_down'] ) ); ?></th>
					</tr>
				</tfoot>
			</table>
		</div>
	<?php endif; ?>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'By supplier', 'fishotel-misc-plugin' ); ?></h2>
	<div class="fh-cr-grid-wrap"><?php $fh_group_table( $stats['by_supplier'], __( 'Supplier', 'fishotel-misc-plugin' ) ); ?></div>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'By origin', 'fishotel-misc-plugin' ); ?></h2>
	<div class="fh-cr-grid-wrap"><?php $fh_group_table( $stats['by_origin'], __( 'Origin', 'fishotel-misc-plugin' ) ); ?></div>

	<h2 class="fh-cr-h2"><?php esc_html_e( 'By species group', 'fishotel-misc-plugin' ); ?></h2>
	<div class="fh-cr-grid-wrap"><?php $fh_group_table( $stats['by_group'], __( 'Species group', 'fishotel-misc-plugin' ) ); ?></div>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'A species group comes from the species list (Species tab); lines whose species has no group are "(no group)". Fill = received ÷ ordered; survival = survived ÷ (received − DOA); DOA rate = DOA ÷ received. A year is the order\'s received date, else its order date.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
