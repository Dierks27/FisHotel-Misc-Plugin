<?php
/**
 * Health log tab — filters, 50 rows a page, expandable context.
 *
 * @package FisHotel\Misc\Sections\Health
 *
 * @var array    $filters  level, source, search.
 * @var int      $paged    Current page.
 * @var array[]  $rows     Store rows (hydrated).
 * @var int      $total    Matching rows.
 * @var int      $pages    Page count.
 * @var string[] $sources  Dropdown sources.
 * @var string   $csv_url  Nonced CSV URL.
 * @var bool     $table_ok Whether the log table exists.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Health\Admin;
use FisHotel\Misc\Sections\Health\Health;
use FisHotel\Misc\Sections\Health\Store;

$fh_base_args = Admin::request_args( $filters );
?>
<div class="wrap fishotel-misc-wrap fh-health-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Health', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-health-tabs">
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'overview' ) ); ?>"><?php esc_html_e( 'Overview', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab is-active" href="<?php echo esc_url( Admin::page_url( 'log' ) ); ?>"><?php esc_html_e( 'Log', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<?php if ( ! $table_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The log table does not exist, so nothing is being recorded. Deactivate and reactivate the plugin to create it.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-health-toolbar">
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-health-filters" id="fh-health-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Health::MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="log">

			<label class="fh-health-filter">
				<span><?php esc_html_e( 'Level', 'fishotel-misc-plugin' ); ?></span>
				<select name="level" class="fh-health-input">
					<option value=""><?php esc_html_e( 'Any', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( Store::LEVELS as $fh_level ) : ?>
						<option value="<?php echo esc_attr( $fh_level ); ?>" <?php selected( $filters['level'], $fh_level ); ?>><?php echo esc_html( $fh_level ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="fh-health-filter">
				<span><?php esc_html_e( 'Source', 'fishotel-misc-plugin' ); ?></span>
				<select name="source" class="fh-health-input">
					<option value=""><?php esc_html_e( 'Any', 'fishotel-misc-plugin' ); ?></option>
					<?php foreach ( $sources as $fh_source ) : ?>
						<option value="<?php echo esc_attr( $fh_source ); ?>" <?php selected( $filters['source'], $fh_source ); ?>><?php echo esc_html( $fh_source ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="fh-health-filter fh-health-filter--grow">
				<span><?php esc_html_e( 'Search', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" name="s" class="fh-health-input" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'message or context', 'fishotel-misc-plugin' ); ?>">
			</label>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
			<a class="button" href="<?php echo esc_url( Admin::page_url( 'log' ) ); ?>"><?php esc_html_e( 'Reset', 'fishotel-misc-plugin' ); ?></a>
		</form>

		<div class="fh-health-actions">
			<label class="fh-health-filter fh-health-filter--quick">
				<span><?php esc_html_e( 'Narrow this page', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" class="fh-health-input fh-health-quick" placeholder="<?php esc_attr_e( 'type to filter the rows below', 'fishotel-misc-plugin' ); ?>">
			</label>
			<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV', 'fishotel-misc-plugin' ); ?></a>
			<button type="button" class="button fh-health-clear" <?php disabled( ! $table_ok ); ?>><?php esc_html_e( 'Clear log', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-health-status" aria-live="polite"></span>
		</div>
	</div>

	<p class="fh-health-count">
		<?php
		/* translators: 1: total rows, 2: page, 3: pages */
		echo esc_html( sprintf( __( '%1$d rows · page %2$d of %3$d · newest first', 'fishotel-misc-plugin' ), (int) $total, (int) $paged, (int) $pages ) );
		?>
	</p>

	<?php if ( empty( $rows ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-heart"></span>
			<p><?php esc_html_e( 'Nothing logged for these filters.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped fh-health-table" id="fh-health-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When (site time)', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Level', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $fh_row ) : ?>
					<?php $fh_context = Admin::context_json( $fh_row['context'] ); ?>
					<tr class="fh-health-row" data-id="<?php echo esc_attr( $fh_row['id'] ); ?>" data-level="<?php echo esc_attr( $fh_row['level'] ); ?>" data-source="<?php echo esc_attr( $fh_row['source'] ); ?>" data-text="<?php echo esc_attr( strtolower( $fh_row['message'] . ' ' . $fh_context ) ); ?>">
						<td class="fh-health-when">
							<?php echo esc_html( Admin::site_time( $fh_row['logged_at'] ) ); ?>
							<?php if ( $fh_row['repeats'] > 1 ) : ?>
								<span class="fh-health-repeats" title="<?php esc_attr_e( 'Repeats within 10 minutes, folded into this row', 'fishotel-misc-plugin' ); ?>">×<?php echo esc_html( $fh_row['repeats'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo Admin::level_html( $fh_row['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by level_html(). ?></td>
						<td><code class="fh-health-source"><?php echo esc_html( $fh_row['source'] ); ?></code></td>
						<td class="fh-health-message">
							<?php echo esc_html( $fh_row['message'] ); ?>
							<?php if ( '' !== $fh_context ) : ?>
								<pre class="fh-health-ctx" hidden><?php echo esc_html( $fh_context ); ?></pre>
							<?php endif; ?>
						</td>
						<td class="fh-health-row__actions">
							<?php if ( '' !== $fh_context ) : ?>
								<button type="button" class="button button-small fh-health-ctx-toggle" aria-expanded="false"><?php esc_html_e( 'Context', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<nav class="fh-health-pagination">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::page_url( 'log', array_merge( $fh_base_args, array( 'paged' => $paged - 1 ) ) ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
				<?php if ( $paged < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::page_url( 'log', array_merge( $fh_base_args, array( 'paged' => $paged + 1 ) ) ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?> &rarr;</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<p class="fh-health-footnote">
		<?php esc_html_e( 'Times are stored in UTC and shown in site time. Keys that look like passwords, tokens or cookies are redacted before storage; the same error within 10 minutes is folded into one row with a × count.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
