<?php
/**
 * My snippets / Log while the snippet tables are not installed.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab mine|log.
 * @var array|null $notice Notice.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Db_Store;

$fh_stored = get_option( Db_Store::DB_VERSION_OPTION );
?>
<div class="wrap fishotel-misc-wrap fh-snip-wrap">
	<?php include __DIR__ . '/tabs.php'; ?>

	<div class="fh-snip-access" role="note">
		<p><strong><?php esc_html_e( 'The snippet tables are not installed.', 'fishotel-misc-plugin' ); ?></strong></p>
		<p>
			<?php
			echo is_string( $fh_stored ) && 0 === strpos( $fh_stored, 'failed:' )
				? esc_html__( 'Creating them failed (the database user may lack CREATE TABLE). It is retried at most once an hour, on an admin page; the files under Files keep working meanwhile.', 'fishotel-misc-plugin' )
				: esc_html__( 'They are created the next time the Snippets section boots — reload this page.', 'fishotel-misc-plugin' );
			?>
		</p>
	</div>
</div>
