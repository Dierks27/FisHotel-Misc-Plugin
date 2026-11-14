<?php
/**
 * The top of every Lost Fish tab: the header, the tab strip, the one-time
 * notice and what is not available.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var string     $tab       Current tab.
 * @var array|null $flash     A one-time notice (message, type).
 * @var bool       $tables_ok Whether the tables exist.
 * @var array      $status    Admin::connections().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Admin;

$fh_tabs = array(
	'cases'    => __( 'Cases', 'fishotel-misc-plugin' ),
	'new'      => __( 'Open a case', 'fishotel-misc-plugin' ),
	'log'      => __( 'Log', 'fishotel-misc-plugin' ),
	'settings' => __( 'Settings', 'fishotel-misc-plugin' ),
);
?>
<div class="fishotel-misc-header">
	<h1><?php esc_html_e( 'Lost Fish', 'fishotel-misc-plugin' ); ?></h1>
	<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
</div>

<nav class="fh-lf-tabs">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-lf-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! empty( $flash ) ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--<?php echo esc_attr( $flash['type'] ); ?>" role="status"><?php echo esc_html( $flash['message'] ); ?></div>
<?php endif; ?>

<?php if ( ! $tables_ok ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'The Lost Fish tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
	</div>
<?php endif; ?>

<?php foreach ( array( 'woocommerce', 'cleanroom' ) as $fh_key ) : ?>
	<?php if ( empty( $status[ $fh_key ]['ok'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( $status[ $fh_key ]['note'] ); ?></div>
	<?php endif; ?>
<?php endforeach; ?>
