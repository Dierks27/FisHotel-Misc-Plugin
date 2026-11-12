<?php
/**
 * The top of every further Stats view: the page header, the tab strip,
 * the "tables are missing" notice and the strip of Stats views.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var string $tab           'stats'.
 * @var string $fh_stats_view The view shown.
 * @var string $flash         One-time notice.
 * @var bool   $tables_ok     Whether the tables exist.
 * @var bool   $can_settings  Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
?>
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

<?php include Clean_Room::path() . 'views/stats-nav.php'; ?>
