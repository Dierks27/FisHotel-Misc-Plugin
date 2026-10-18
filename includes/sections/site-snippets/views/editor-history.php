<?php
/**
 * My snippets — a snippet's history: every version (who, when, what
 * changed), a diff of any version against the current code
 * (wp_text_diff(), which escapes every line) and Restore (a new version,
 * logged, test-run like a save while the snippet is on).
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab       mine.
 * @var array|null $notice       Notice.
 * @var array      $perm         Permission.
 * @var array      $who          Editor::who_now().
 * @var array|null $row          The snippet (null = deleted).
 * @var int        $id           Its id.
 * @var array      $revisions    Newest first.
 * @var array      $history      Admin_Editor::history_rows().
 * @var array      $current      { meta, code, version }.
 * @var array|null $diff         { rev, summary, html } for ?rev=.
 * @var string     $post_url     admin-post.php.
 * @var string     $nonce_action Nonce action.
 * @var string     $nonce_field  Nonce field.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor;

$fh_can_write = ! empty( $perm['allowed'] ) && is_array( $row );
$fh_name      = isset( $current['meta']['name'] ) ? (string) $current['meta']['name'] : '';
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">
	<?php include __DIR__ . '/tabs.php'; ?>

	<p class="fh-snip-back">
		<a href="<?php echo esc_url( Admin_Editor::url() ); ?>">&larr; <?php esc_html_e( 'My snippets', 'fishotel-misc-plugin' ); ?></a>
		<?php if ( is_array( $row ) ) : ?>
			· <a href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></a>
		<?php endif; ?>
	</p>

	<h2 class="fh-snip-h2 fh-snip-title">
		<?php
		/* translators: %s: name */
		echo esc_html( sprintf( __( 'History of %s', 'fishotel-misc-plugin' ), $fh_name ) );
		?>
	</h2>

	<?php include __DIR__ . '/editor-access.php'; ?>

	<?php if ( ! is_array( $row ) ) : ?>
		<div class="fh-snip-notice is-error"><?php esc_html_e( 'This snippet is deleted. Its versions are kept; restore it from its delete row on the Log tab.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<?php if ( is_array( $diff ) ) : ?>
		<div class="fh-snip-diff-box">
			<h3>
				<?php
				/* translators: 1: version, 2: current version */
				echo esc_html( sprintf( __( 'Version %1$d against the current code (version %2$d)', 'fishotel-misc-plugin' ), (int) $diff['rev']['version'], (int) $current['version'] ) );
				?>
			</h3>
			<p class="fh-snip-muted">
				<?php
				/* translators: %s: what differs */
				echo esc_html( sprintf( __( 'What differs: %s', 'fishotel-misc-plugin' ), $diff['summary'] ) );
				?>
			</p>
			<?php if ( '' === $diff['html'] ) : ?>
				<p><?php esc_html_e( 'The code is the same as the current code.', 'fishotel-misc-plugin' ); ?></p>
			<?php else : ?>
				<div class="fh-snip-diff"><?php echo wp_kses_post( $diff['html'] ); ?></div>
			<?php endif; ?>
			<details class="fh-snip-code">
				<summary>
					<?php
					/* translators: %d: version */
					echo esc_html( sprintf( __( 'Full code of version %d', 'fishotel-misc-plugin' ), (int) $diff['rev']['version'] ) );
					?>
				</summary>
				<pre><?php echo esc_html( (string) $diff['rev']['code'] ); ?></pre>
			</details>
		</div>
	<?php endif; ?>

	<table class="widefat striped fh-snip-table fh-snip-history">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Version', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'When', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'What changed', 'fishotel-misc-plugin' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'fishotel-misc-plugin' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $history as $fh_h ) : ?>
				<?php
				$fh_rev     = $fh_h['rev'];
				$fh_is_head = (int) $fh_rev['version'] === (int) $current['version'];
				?>
				<tr>
					<td>
						v<?php echo esc_html( (string) (int) $fh_rev['version'] ); ?>
						<?php if ( $fh_is_head ) : ?>
							<span class="fh-snip-muted"><?php esc_html_e( 'current', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $fh_h['who'] ); ?></td>
					<td><?php echo esc_html( $fh_h['when'] ); ?></td>
					<td class="fh-snip-summary"><?php echo esc_html( $fh_h['summary'] ); ?></td>
					<td class="fh-snip-actions">
						<?php if ( ! $fh_is_head ) : ?>
							<a href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'history', 'id' => $id, 'rev' => (int) $fh_rev['id'] ) ) ); ?>"><?php esc_html_e( 'Diff with current', 'fishotel-misc-plugin' ); ?></a>
							<?php if ( $fh_can_write ) : ?>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form" data-confirm="<?php echo esc_attr( sprintf( /* translators: %d: version */ __( 'Restore version %d as a new version? While the snippet is on, it is test-run first.', 'fishotel-misc-plugin' ), (int) $fh_rev['version'] ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( Editor::RESTORE_ACTION ); ?>">
									<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<input type="hidden" name="revision_id" value="<?php echo esc_attr( (string) (int) $fh_rev['id'] ); ?>">
									<input type="hidden" name="base_version" value="<?php echo esc_attr( (string) (int) $current['version'] ); ?>">
									<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Restore', 'fishotel-misc-plugin' ); ?></button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
