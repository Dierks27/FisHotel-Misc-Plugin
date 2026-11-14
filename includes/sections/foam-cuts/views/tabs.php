<?php
/**
 * The header, the tab strip and the one-time notice shared by every Foam
 * Cuts tab.
 *
 * @package FisHotel\Misc\Sections\Foam_Cuts
 *
 * @var string     $tab   Current tab.
 * @var array|null $flash { ok, lines } or null.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Foam_Cuts\Admin;

$fh_tabs = array(
	'plan'     => __( 'Plan', 'fishotel-misc-plugin' ),
	'stock'    => __( 'Stock', 'fishotel-misc-plugin' ),
	'boxes'    => __( 'Boxes & cut lists', 'fishotel-misc-plugin' ),
	'settings' => __( 'Settings', 'fishotel-misc-plugin' ),
);
?>
<div class="fishotel-misc-header fh-fc-header">
	<h1><?php esc_html_e( 'Foam Cuts', 'fishotel-misc-plugin' ); ?></h1>
	<span class="fh-fc-tagline"><?php esc_html_e( 'Which sheet, where to cut, what to write on the leftovers', 'fishotel-misc-plugin' ); ?></span>
</div>

<nav class="fh-fc-tabs">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-fc-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! empty( $flash ) ) : ?>
	<div class="fh-fc-notice <?php echo $flash['ok'] ? 'is-ok' : 'is-error'; ?>" role="status">
		<?php foreach ( $flash['lines'] as $fh_line ) : ?>
			<p><?php echo esc_html( $fh_line ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
