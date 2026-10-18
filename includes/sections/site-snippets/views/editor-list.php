<?php
/**
 * My snippets — the snippets written here in wp-admin: switch, status,
 * version, edit / history / export / delete, and Add new.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab       mine.
 * @var array|null $notice       Notice.
 * @var array      $perm         Permission.
 * @var array      $who          Editor::who_now().
 * @var array      $safe         Loader::safe_mode().
 * @var array      $list         Rows: { row, status, error, draft }.
 * @var bool       $read_error   The table could not be read.
 * @var array|null $new_draft    The user's unsaved new snippet.
 * @var string     $post_url     admin-post.php.
 * @var string     $nonce_action Nonce action.
 * @var string     $nonce_field  Nonce field.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor;

$fh_can_write = ! empty( $perm['allowed'] );
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">
	<?php include __DIR__ . '/tabs.php'; ?>

	<p class="fh-snip-intro">
		<?php esc_html_e( 'Snippets written here, stored in the database: they run exactly like the files (same scopes, order, safe mode and checks), next to them. Every change is a new version you can compare and roll back, and every action is in the Log. When a snippet is final, Export it and send the file to Claude: it goes to GitHub through a pull request, and this copy then shows as superseded.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php include __DIR__ . '/editor-access.php'; ?>

	<?php if ( $read_error ) : ?>
		<div class="fh-snip-notice is-error"><?php esc_html_e( 'The snippet table could not be read. Nothing was changed; reload the page.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<div class="fh-snip-toolbar">
		<?php if ( $fh_can_write ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add new', 'fishotel-misc-plugin' ); ?></a>
			<?php if ( is_array( $new_draft ) ) : ?>
				<a class="fh-snip-draft-link" href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'new' ) ) ); ?>"><?php esc_html_e( 'You have an unsaved new snippet — open it', 'fishotel-misc-plugin' ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( ! empty( $safe['active'] ) ) : ?>
			<span class="fh-snip-fact is-bad"><?php esc_html_e( 'Safe mode: nothing runs', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
	</div>

	<div class="fh-snip-status" aria-live="polite"></div>

	<?php if ( empty( $list ) ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-editor-code"></span>
			<p><?php esc_html_e( 'No snippets written here yet.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped fh-snip-table fh-snip-db-table">
			<thead>
				<tr>
					<th class="fh-snip-col-switch"><?php esc_html_e( 'On', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Snippet', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Type', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'fishotel-misc-plugin' ); ?></th>
					<th class="fh-snip-num"><?php esc_html_e( 'Priority', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Version', 'fishotel-misc-plugin' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $list as $fh_item ) : ?>
					<?php
					$fh_row    = $fh_item['row'];
					$fh_status = $fh_item['status'];
					$fh_id     = (int) $fh_row['id'];
					?>
					<tr class="fh-snip-row" data-db-row="<?php echo esc_attr( (string) $fh_id ); ?>">
						<td class="fh-snip-col-switch">
							<label class="fishotel-misc-toggle" title="<?php echo esc_attr( $fh_can_write ? __( 'Switch on or off (switching on test-runs PHP once)', 'fishotel-misc-plugin' ) : __( 'Read only', 'fishotel-misc-plugin' ) ); ?>">
								<input type="checkbox"
									data-db-snippet="<?php echo esc_attr( (string) $fh_id ); ?>"
									<?php checked( $fh_status['on'] ); ?>
									<?php disabled( ! $fh_can_write || ( $fh_status['locked'] && ! $fh_status['on'] ) ); ?>>
								<span class="slider"></span>
							</label>
						</td>
						<td>
							<strong class="fh-snip-name"><?php echo esc_html( $fh_row['name'] ); ?></strong>
							<code class="fh-snip-slug"><?php echo esc_html( $fh_row['slug'] ); ?></code>
							<?php if ( '' !== $fh_row['description'] ) : ?>
								<span class="fh-snip-desc"><?php echo esc_html( $fh_row['description'] ); ?></span>
							<?php endif; ?>
							<span class="fh-snip-state fh-snip-state--<?php echo esc_attr( $fh_status['key'] ); ?>" data-role="status"><?php echo esc_html( $fh_status['label'] ); ?></span>
							<?php if ( is_array( $fh_item['error'] ) ) : ?>
								<span class="fh-snip-error">
									<?php
									/* translators: 1: when, 2: message */
									echo esc_html( sprintf( __( 'Last error (%1$s): %2$s', 'fishotel-misc-plugin' ), Admin_Editor::when( gmdate( 'Y-m-d H:i:s', (int) $fh_item['error']['time'] ) ), $fh_item['error']['message'] ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( is_array( $fh_item['draft'] ) && $fh_can_write ) : ?>
								<a class="fh-snip-draft-link" href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'edit', 'id' => $fh_id ) ) ); ?>"><?php esc_html_e( 'You have unsaved changes to this snippet — open them', 'fishotel-misc-plugin' ); ?></a>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $fh_row['type'] ); ?></code></td>
						<td><?php echo esc_html( Admin::scope_label( $fh_row['type'], $fh_row['scope'] ) ); ?></td>
						<td class="fh-snip-num"><?php echo esc_html( (string) (int) $fh_row['priority'] ); ?></td>
						<td>
							<?php
							/* translators: 1: version, 2: who, 3: when */
							echo esc_html( sprintf( __( 'v%1$d · %2$s · %3$s', 'fishotel-misc-plugin' ), (int) $fh_row['version'], Admin_Editor::who( (int) $fh_row['updated_by'] ), Admin_Editor::when( $fh_row['updated_at'] ) ) );
							?>
							<?php if ( ! empty( $fh_row['exported_at'] ) ) : ?>
								<span class="fh-snip-muted">
									<?php
									/* translators: %s: when */
									echo esc_html( sprintf( __( 'exported %s', 'fishotel-misc-plugin' ), Admin_Editor::when( $fh_row['exported_at'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td class="fh-snip-actions">
							<a href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'edit', 'id' => $fh_id ) ) ); ?>"><?php echo $fh_can_write ? esc_html__( 'Edit', 'fishotel-misc-plugin' ) : esc_html__( 'View', 'fishotel-misc-plugin' ); ?></a>
							<a href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'history', 'id' => $fh_id ) ) ); ?>"><?php esc_html_e( 'History', 'fishotel-misc-plugin' ); ?></a>
							<?php if ( $fh_can_write ) : ?>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form">
									<input type="hidden" name="action" value="<?php echo esc_attr( Editor::EXPORT_ACTION ); ?>">
									<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $fh_id ); ?>">
									<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
									<button type="submit" class="button-link"><?php esc_html_e( 'Export', 'fishotel-misc-plugin' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form" data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: name */ __( 'Delete "%s"? Its history and log stay, and the Log tab can restore it.', 'fishotel-misc-plugin' ), $fh_row['name'] ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( Editor::DELETE_ACTION ); ?>">
									<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $fh_id ); ?>">
									<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
									<button type="submit" class="button-link fh-snip-danger"><?php esc_html_e( 'Delete', 'fishotel-misc-plugin' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 class="fh-snip-h2"><?php esc_html_e( 'Move a snippet to GitHub', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-snip-help">
		<?php esc_html_e( 'Export downloads the snippet as a file with the header the folder needs (Origin: written in wp-admin by you, Default matching whether it is on). Send that file to Claude: it lands in GitHub through a pull request and arrives with the next plugin update. This copy then shows as superseded by the GitHub file and never runs — switch the file on under Files, check the site, then delete this copy.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
