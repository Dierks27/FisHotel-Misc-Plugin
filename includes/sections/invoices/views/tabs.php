<?php
/**
 * The header and the tab strip shared by every Invoices screen, with the
 * notices that matter everywhere: the tables are missing, WooCommerce is
 * not active.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var string $tab       Current tab: list|new|forum|settings.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;

$fh_tabs = array(
	'list'     => __( 'Invoices', 'fishotel-misc-plugin' ),
	'new'      => __( 'New invoice', 'fishotel-misc-plugin' ),
	'forum'    => __( 'Forum list', 'fishotel-misc-plugin' ),
	'settings' => __( 'Settings', 'fishotel-misc-plugin' ),
);
?>
<div class="fishotel-misc-header">
	<h1><?php esc_html_e( 'Invoices', 'fishotel-misc-plugin' ); ?></h1>
	<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
</div>

<nav class="fh-inv-tabs">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-inv-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( empty( $tables_ok ) ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'The Invoices tables do not exist yet, so nothing can be saved. They are created the next time an admin page loads; if this message stays, deactivate and reactivate the FisHotel Misc plugin.', 'fishotel-misc-plugin' ); ?>
	</div>
<?php endif; ?>

<?php if ( empty( $shop_ok ) ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'WooCommerce is not active. An invoice is a WooCommerce product, so nothing can be published and no order can be read until it is.', 'fishotel-misc-plugin' ); ?>
	</div>
<?php endif; ?>
