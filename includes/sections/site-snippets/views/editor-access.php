<?php
/**
 * Who may edit — shown above the editor screens while the current user may
 * not write: why, and how to add yourself to the wp-config constant.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var array $perm Editor_Rules::permission() for this user.
 * @var array $who  Editor::who_now().
 */

defined( 'ABSPATH' ) || exit;

if ( ! empty( $perm['allowed'] ) ) {
	return;
}
?>
<div class="fh-snip-access" role="note">
	<p><strong><?php esc_html_e( 'Read only.', 'fishotel-misc-plugin' ); ?></strong> <?php echo esc_html( $perm['message'] ); ?></p>

	<?php if ( 'file_edit' === $perm['reason'] ) : ?>
		<p><?php esc_html_e( 'DISALLOW_FILE_EDIT turns off every code editor in wp-admin, this one included. Remove it from wp-config.php (or set it to false) only if you want to edit snippets here; the files in GitHub still work either way.', 'fishotel-misc-plugin' ); ?></p>
	<?php elseif ( in_array( $perm['reason'], array( 'no_constant', 'empty_constant', 'not_listed' ), true ) ) : ?>
		<p><?php esc_html_e( 'Only the people named in wp-config.php may write, switch, restore, export or delete snippets here. To add yourself, put this line in wp-config.php, above "That\'s all, stop editing!":', 'fishotel-misc-plugin' ); ?></p>
		<pre class="fh-snip-pre">define( 'FISHOTEL_SNIPPETS_EDITORS', 'jeff' );</pre>
		<p>
			<?php
			/* translators: %s: the current user's login */
			echo esc_html( sprintf( __( 'Use WordPress usernames or user IDs, separated by commas (an entry made of digits is a user ID). Your username is %s. The person must also be an administrator, and DISALLOW_FILE_EDIT must not be true.', 'fishotel-misc-plugin' ), '' !== $who['user_login'] ? $who['user_login'] : '—' ) );
			?>
		</p>
	<?php endif; ?>
</div>
