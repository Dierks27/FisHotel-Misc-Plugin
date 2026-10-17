<?php
/**
 * The tab strip shared by every Clean Room admin tab.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var string $tab          Current tab.
 * @var bool   $can_settings Whether the Settings tab is offered.
 * @var string $flash        A one-time notice (optional).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;

$fh_tabs = array(
	'board'    => __( 'Board', 'fishotel-misc-plugin' ),
	'stays'    => __( 'Stays', 'fishotel-misc-plugin' ),
	'arrivals' => __( 'Arrivals', 'fishotel-misc-plugin' ),
	'species'  => __( 'Species', 'fishotel-misc-plugin' ),
	'stats'    => __( 'Stats', 'fishotel-misc-plugin' ),
	'events'   => __( 'Events', 'fishotel-misc-plugin' ),
);
if ( ! empty( $can_settings ) ) {
	$fh_tabs['settings'] = __( 'Settings', 'fishotel-misc-plugin' );
}
?>
<nav class="fh-cr-tabs">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-cr-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
	<?php endforeach; ?>
</nav>
<?php if ( ! empty( $flash ) ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--warning"><?php echo esc_html( $flash ); ?></div>
<?php endif; ?>
