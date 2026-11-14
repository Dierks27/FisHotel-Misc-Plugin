<?php
/**
 * Log — the append-only audit log of the snippet editor, newest first:
 * every create, update, switch, delete, restore, export, failed test run,
 * refused write and safe-mode use, with who, from where and what changed
 * (hashes and line counts, never the code). No row can be deleted here. A
 * delete row offers Restore (from the snippet's last version, switched off).
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab       log.
 * @var array|null $notice       Notice.
 * @var array      $perm         Permission.
 * @var array      $who          Editor::who_now().
 * @var array      $filters      action, slug.
 * @var int        $paged        Page.
 * @var int        $pages        Pages.
 * @var int        $total        Matching rows.
 * @var array      $log_rows     This page.
 * @var array      $existing     snippet id => true for snippets that exist.
 * @var string     $csv_url      CSV download.
 * @var string     $post_url     admin-post.php.
 * @var string     $nonce_action Nonce action.
 * @var string     $nonce_field  Nonce field.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor_Rules;

$fh_can_write = ! empty( $perm['allowed'] );
$fh_offered   = array(); // Snippet ids that already got a Restore button (newest delete only).
$fh_levels    = array(
	'denied'      => 'bad',
	'test_failed' => 'bad',
	'delete'      => 'warn',
	'safe_mode'   => 'warn',
);
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">
	<?php include __DIR__ . '/tabs.php'; ?>

	<p class="fh-snip-intro">
		<?php esc_html_e( 'Everything done to the snippets written here, and every refused attempt. Rows are only ever added — nothing here can be edited or deleted. Times are the site\'s; the CSV has UTC. X-Forwarded-For is whatever the browser or a proxy sent: anyone can forge it.', 'fishotel-misc-plugin' ); ?>
	</p>

	<form method="get" class="fh-snip-toolbar fh-snip-log-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( \FisHotel\Misc\Sections\Site_Snippets\Site_Snippets::MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="log">
		<label>
			<?php esc_html_e( 'Action', 'fishotel-misc-plugin' ); ?>
			<select name="log_action">
				<option value=""><?php esc_html_e( 'All', 'fishotel-misc-plugin' ); ?></option>
				<?php foreach ( Editor_Rules::ACTIONS as $fh_action ) : ?>
					<option value="<?php echo esc_attr( $fh_action ); ?>"<?php selected( $filters['action'], $fh_action ); ?>><?php echo esc_html( $fh_action ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<?php esc_html_e( 'Slug', 'fishotel-misc-plugin' ); ?>
			<input type="text" name="log_slug" value="<?php echo esc_attr( $filters['slug'] ); ?>" spellcheck="false">
		</label>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fishotel-misc-plugin' ); ?></button>
		<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV (all rows)', 'fishotel-misc-plugin' ); ?></a>
		<span class="fh-snip-muted">
			<?php
			/* translators: %s: number of rows */
			echo esc_html( sprintf( _n( '%s row', '%s rows', $total, 'fishotel-misc-plugin' ), number_format_i18n( $total ) ) );
			?>
		</span>
	</form>

	<?php if ( empty( $log_rows ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-list-view"></span>
			<p><?php esc_html_e( 'Nothing logged yet.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped fh-snip-table fh-snip-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Action', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Snippet', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log_rows as $fh_log ) : ?>
					<?php
					$fh_sid    = (int) $fh_log['snippet_id'];
					$fh_action = (string) $fh_log['action'];
					$fh_level  = isset( $fh_levels[ $fh_action ] ) ? $fh_levels[ $fh_action ] : 'ok';
					$fh_undel  = $fh_can_write && 'delete' === $fh_action && $fh_sid > 0 && ! isset( $existing[ $fh_sid ] ) && ! isset( $fh_offered[ $fh_sid ] );
					if ( $fh_undel ) {
						$fh_offered[ $fh_sid ] = true;
					}
					?>
					<tr>
						<td title="<?php echo esc_attr( (string) $fh_log['created_at'] . ' UTC' ); ?>"><?php echo esc_html( Admin_Editor::when( $fh_log['created_at'] ) ); ?></td>
						<td><span class="fh-snip-action is-<?php echo esc_attr( $fh_level ); ?>"><?php echo esc_html( $fh_action ); ?></span></td>
						<td>
							<?php if ( '' !== (string) $fh_log['slug'] ) : ?>
								<code><?php echo esc_html( (string) $fh_log['slug'] ); ?></code>
							<?php endif; ?>
							<?php if ( $fh_sid > 0 ) : ?>
								<a class="fh-snip-muted" href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'history', 'id' => $fh_sid ) ) ); ?>">#<?php echo esc_html( (string) $fh_sid ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( ( '' !== (string) $fh_log['user_login'] ? (string) $fh_log['user_login'] : '—' ) . ' (#' . (int) $fh_log['user_id'] . ')' ); ?></td>
						<td class="fh-snip-from">
							<?php echo esc_html( '' !== (string) $fh_log['remote_addr'] ? (string) $fh_log['remote_addr'] : '—' ); ?>
							<?php if ( '' !== (string) $fh_log['forwarded_for'] ) : ?>
								<span class="fh-snip-muted">
									<?php
									/* translators: %s: X-Forwarded-For value */
									echo esc_html( sprintf( __( 'X-Forwarded-For %s (untrusted)', 'fishotel-misc-plugin' ), (string) $fh_log['forwarded_for'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( '' !== (string) $fh_log['user_agent'] ) : ?>
								<span class="fh-snip-muted fh-snip-ua" title="<?php echo esc_attr( (string) $fh_log['user_agent'] ); ?>"><?php echo esc_html( Editor_Rules::cut( (string) $fh_log['user_agent'], 60 ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-snip-summary">
							<?php echo esc_html( (string) $fh_log['summary'] ); ?>
							<?php if ( $fh_undel ) : ?>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form" data-confirm="<?php esc_attr_e( 'Restore this snippet from its last version? It comes back switched off.', 'fishotel-misc-plugin' ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( Editor::UNDELETE_ACTION ); ?>">
									<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) (int) $fh_log['id'] ); ?>">
									<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
									<button type="submit" class="button"><?php esc_html_e( 'Restore', 'fishotel-misc-plugin' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="fh-snip-pager">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::page_url( array( 'tab' => 'log', 'log_action' => $filters['action'], 'log_slug' => $filters['slug'], 'paged' => $paged - 1 ) ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
				<span class="fh-snip-muted">
					<?php
					/* translators: 1: page, 2: pages */
					echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'fishotel-misc-plugin' ), $paged, $pages ) );
					?>
				</span>
				<?php if ( $paged < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( Admin::page_url( array( 'tab' => 'log', 'log_action' => $filters['action'], 'log_slug' => $filters['slug'], 'paged' => $paged + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
