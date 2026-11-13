<?php
/**
 * The strip under the tabs of the Stats tab: By year · Compare years ·
 * Species · Prices to raise · Real sales.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var string $fh_stats_view The view shown (year when not set).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;

$fh_stats_view = isset( $fh_stats_view ) ? (string) $fh_stats_view : 'year';
?>
<nav class="fh-cr-subtabs" aria-label="<?php esc_attr_e( 'Stats views', 'fishotel-misc-plugin' ); ?>">
	<?php foreach ( Admin_Stats::nav( $fh_stats_view ) as $fh_item ) : ?>
		<a class="fh-cr-subtab<?php echo $fh_item['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $fh_item['url'] ); ?>"<?php echo $fh_item['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $fh_item['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
