<?php
/**
 * My snippets — the edit / new screen: name, description, type, scope,
 * priority, on / off and the code (CodeMirror when the user's profile
 * allows syntax highlighting, a plain textarea otherwise). A refused save
 * comes back here with what was typed (the draft) and the reason.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab       mine.
 * @var array|null $notice       Notice.
 * @var array      $perm         Permission.
 * @var array      $who          Editor::who_now().
 * @var array|null $row          The snippet (null = new).
 * @var int        $id           Its id (0 = new).
 * @var array|null $draft        The user's draft of it.
 * @var int        $draft_id     The draft's slot (the id; for $orphan, the deleted snippet's id).
 * @var bool       $orphan       The snippet was deleted; its draft is offered as a new snippet.
 * @var array      $fields       Form values.
 * @var array      $errors       field => text.
 * @var string     $file         The GitHub file superseding it ('' = none).
 * @var array      $scopes       Header::SCOPES.
 * @var array|null $last_error   { time, message }.
 * @var string     $post_url     admin-post.php.
 * @var string     $nonce_action Nonce action.
 * @var string     $nonce_field  Nonce field.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin;
use FisHotel\Misc\Sections\Site_Snippets\Admin_Editor;
use FisHotel\Misc\Sections\Site_Snippets\Editor;

$fh_can_write = ! empty( $perm['allowed'] );
$fh_is_new    = ! is_array( $row );
$fh_types     = array(
	'php'  => __( 'PHP', 'fishotel-misc-plugin' ),
	'css'  => __( 'CSS', 'fishotel-misc-plugin' ),
	'js'   => __( 'JavaScript', 'fishotel-misc-plugin' ),
	'html' => __( 'HTML', 'fishotel-misc-plugin' ),
);
$fh_error     = static function ( $field ) use ( $errors ) {
	if ( isset( $errors[ $field ] ) && '' !== (string) $errors[ $field ] ) {
		echo '<span class="fh-snip-field-error">' . esc_html( $errors[ $field ] ) . '</span>';
	}
};
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">
	<?php include __DIR__ . '/tabs.php'; ?>

	<p class="fh-snip-back"><a href="<?php echo esc_url( Admin_Editor::url() ); ?>">&larr; <?php esc_html_e( 'My snippets', 'fishotel-misc-plugin' ); ?></a></p>

	<h2 class="fh-snip-h2 fh-snip-title">
		<?php
		echo $fh_is_new
			? esc_html__( 'New snippet', 'fishotel-misc-plugin' )
			/* translators: 1: name, 2: version */
			: esc_html( sprintf( __( '%1$s — version %2$d', 'fishotel-misc-plugin' ), $row['name'], (int) $row['version'] ) );
		?>
	</h2>

	<?php include __DIR__ . '/editor-access.php'; ?>

	<?php if ( '' !== $file ) : ?>
		<div class="fh-snip-notice is-error">
			<?php
			/* translators: %s: file name */
			echo esc_html( sprintf( __( 'The GitHub file %s supersedes this copy: it never runs. Switch the file on under Files, check the site, then delete this copy.', 'fishotel-misc-plugin' ), $file ) );
			?>
		</div>
	<?php endif; ?>

	<?php if ( $orphan && $fh_can_write ) : ?>
		<div class="fh-snip-notice is-error">
			<?php
			/* translators: %d: the deleted snippet's id */
			echo esc_html( sprintf( __( 'Snippet #%d was deleted (in another tab?) while you were editing it. Your unsaved code is below: create it as a new snippet, or restore the deleted one from its delete row on the Log tab — your code then shows on it again.', 'fishotel-misc-plugin' ), (int) $draft_id ) );
			?>
		</div>
	<?php endif; ?>

	<?php if ( is_array( $draft ) && $fh_can_write ) : ?>
		<div class="fh-snip-notice is-error fh-snip-draft">
			<p>
				<?php
				/* translators: %s: when */
				echo esc_html( sprintf( __( 'Your unsaved code from %s is shown below — nothing of it was saved.', 'fishotel-misc-plugin' ), Admin_Editor::when( gmdate( 'Y-m-d H:i:s', (int) $draft['time'] ) ) ) );
				?>
			</p>
			<?php if ( '' !== (string) $draft['message'] ) : ?>
				<p><strong><?php echo esc_html( $draft['message'] ); ?></strong></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Editor::DISCARD_ACTION ); ?>">
				<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $draft_id ); ?>">
				<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
				<button type="submit" class="button-link"><?php echo $fh_is_new ? esc_html__( 'Discard it', 'fishotel-misc-plugin' ) : esc_html__( 'Discard it and show the saved version', 'fishotel-misc-plugin' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( is_array( $last_error ) ) : ?>
		<div class="fh-snip-error">
			<?php
			/* translators: 1: when, 2: message */
			echo esc_html( sprintf( __( 'Last error on the site (%1$s): %2$s', 'fishotel-misc-plugin' ), Admin_Editor::when( gmdate( 'Y-m-d H:i:s', (int) $last_error['time'] ) ), $last_error['message'] ) );
			?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-form" id="fh-snip-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( Editor::SAVE_ACTION ); ?>">
		<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $id ); ?>">
		<input type="hidden" name="base_version" value="<?php echo esc_attr( $fields['base_version'] ); ?>">
		<?php if ( $orphan ) : ?>
			<input type="hidden" name="draft_id" value="<?php echo esc_attr( (string) $draft_id ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>

		<fieldset class="fh-snip-fields"<?php echo $fh_can_write ? '' : ' disabled'; ?>>
			<div class="fh-snip-grid">
				<p class="fh-snip-field">
					<label for="fh-snip-slug"><?php esc_html_e( 'Slug (its id; also the file name on export)', 'fishotel-misc-plugin' ); ?></label>
					<?php if ( $fh_is_new ) : ?>
						<input type="text" id="fh-snip-slug" name="slug" value="<?php echo esc_attr( $fields['slug'] ); ?>" maxlength="64" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="hide-admin-bar" required autocomplete="off" spellcheck="false">
					<?php else : ?>
						<code class="fh-snip-slug-fixed"><?php echo esc_html( $fields['slug'] ); ?></code>
						<?php // Never read on save (a slug is fixed); kept in a draft, so a snippet deleted meanwhile can be offered again under its slug. ?>
						<input type="hidden" name="slug" value="<?php echo esc_attr( $fields['slug'] ); ?>">
					<?php endif; ?>
					<?php $fh_error( 'slug' ); ?>
				</p>
				<p class="fh-snip-field">
					<label for="fh-snip-name"><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></label>
					<input type="text" id="fh-snip-name" name="name" value="<?php echo esc_attr( $fields['name'] ); ?>" maxlength="120" required>
					<?php $fh_error( 'name' ); ?>
				</p>
				<p class="fh-snip-field fh-snip-field--wide">
					<label for="fh-snip-description"><?php esc_html_e( 'Description', 'fishotel-misc-plugin' ); ?></label>
					<input type="text" id="fh-snip-description" name="description" value="<?php echo esc_attr( $fields['description'] ); ?>" maxlength="500">
					<?php $fh_error( 'description' ); ?>
				</p>
				<p class="fh-snip-field">
					<label for="fh-snip-type"><?php esc_html_e( 'Type', 'fishotel-misc-plugin' ); ?></label>
					<select id="fh-snip-type" name="type">
						<?php foreach ( $fh_types as $fh_value => $fh_label ) : ?>
							<option value="<?php echo esc_attr( $fh_value ); ?>"<?php selected( $fields['type'], $fh_value ); ?>><?php echo esc_html( $fh_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php $fh_error( 'type' ); ?>
				</p>
				<p class="fh-snip-field">
					<label for="fh-snip-scope"><?php esc_html_e( 'Where it runs', 'fishotel-misc-plugin' ); ?></label>
					<select id="fh-snip-scope" name="scope">
						<?php foreach ( $scopes as $fh_type => $fh_list ) : ?>
							<?php foreach ( $fh_list as $fh_scope ) : ?>
								<option value="<?php echo esc_attr( $fh_scope ); ?>" data-type="<?php echo esc_attr( $fh_type ); ?>"<?php echo $fh_type === $fields['type'] && $fh_scope === $fields['scope'] ? " selected='selected'" : ''; ?>><?php echo esc_html( Admin::scope_label( $fh_type, $fh_scope ) . ' (' . $fh_type . ': ' . $fh_scope . ')' ); ?></option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
					<?php $fh_error( 'scope' ); ?>
				</p>
				<p class="fh-snip-field">
					<label for="fh-snip-priority"><?php esc_html_e( 'Priority (lower runs first)', 'fishotel-misc-plugin' ); ?></label>
					<input type="number" id="fh-snip-priority" name="priority" value="<?php echo esc_attr( $fields['priority'] ); ?>" step="1" min="-999999" max="999999">
					<?php $fh_error( 'priority' ); ?>
				</p>
				<p class="fh-snip-field fh-snip-field--wide">
					<label class="fh-snip-check">
						<input type="checkbox" name="active" value="1"<?php checked( '1', $fields['active'] ); ?>>
						<?php esc_html_e( 'On — runs from the next request. PHP is test-run once before anything is saved; if it throws, nothing is saved and your code stays here.', 'fishotel-misc-plugin' ); ?>
					</label>
				</p>
			</div>

			<p class="fh-snip-field fh-snip-field--code">
				<label for="fh-snip-code"><?php esc_html_e( 'Code (PHP without the opening <?php tag — a leading one is removed on save)', 'fishotel-misc-plugin' ); ?></label>
				<textarea id="fh-snip-code" name="code" rows="24" cols="80" spellcheck="false" wrap="off" class="fh-snip-code-input"><?php echo esc_textarea( $fields['code'] ); ?></textarea>
				<?php $fh_error( 'code' ); ?>
			</p>

			<p class="fh-snip-submit">
				<button type="submit" class="button button-primary"><?php echo $fh_is_new ? esc_html__( 'Create snippet', 'fishotel-misc-plugin' ) : esc_html__( 'Save changes', 'fishotel-misc-plugin' ); ?></button>
				<?php if ( ! $fh_is_new ) : ?>
					<a href="<?php echo esc_url( Admin_Editor::url( array( 'view' => 'history', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'History', 'fishotel-misc-plugin' ); ?></a>
				<?php endif; ?>
			</p>
		</fieldset>
	</form>

	<?php if ( ! $fh_is_new && $fh_can_write ) : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="fh-snip-inline-form fh-snip-export-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( Editor::EXPORT_ACTION ); ?>">
			<input type="hidden" name="snippet_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( $nonce_action, $nonce_field, false ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Export for GitHub (download the saved version)', 'fishotel-misc-plugin' ); ?></button>
		</form>
	<?php endif; ?>
</div>
