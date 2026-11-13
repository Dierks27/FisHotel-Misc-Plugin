<?php
/**
 * The header every Requests admin tab shares: the title, the tab strip,
 * the one-time notice, and — said plainly — what is unavailable (the
 * tables, fees, the Clean Room).
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string $tab   Current tab.
 * @var array  $ctx   Admin::context().
 * @var array  $flash Admin::take_flash().
 * @var array  $needs Listing::needs_you().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;

$fh_tabs = array(
	'list'   => __( 'Requests', 'fishotel-misc-plugin' ),
	'add'    => __( 'Add', 'fishotel-misc-plugin' ),
	'links'  => __( 'Links', 'fishotel-misc-plugin' ),
	'demand' => __( 'Demand', 'fishotel-misc-plugin' ),
);
if ( ! empty( $ctx['can_settings'] ) ) {
	$fh_tabs['settings'] = __( 'Settings', 'fishotel-misc-plugin' );
}
?>
<div class="fishotel-misc-header">
	<h1><?php esc_html_e( 'Requests', 'fishotel-misc-plugin' ); ?></h1>
	<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
</div>

<nav class="fh-rq-tabs">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-rq-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>">
			<?php echo esc_html( $fh_label ); ?>
			<?php if ( 'list' === $fh_key && $needs['count'] > 0 ) : ?>
				<span class="fh-rq-count" title="<?php esc_attr_e( 'Needs you', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( (string) $needs['count'] ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php if ( '' !== $flash['message'] ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--<?php echo $flash['ok'] ? 'success' : 'error'; ?>" role="status"><?php echo esc_html( $flash['message'] ); ?></div>
<?php endif; ?>

<?php if ( ! $ctx['tables_ok'] ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'The Requests tables do not exist yet, so nothing can be saved. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
	</div>
<?php endif; ?>

<?php if ( ! $ctx['fees']['available'] ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--warning">
		<?php
		/* translators: %s: why */
		echo esc_html( sprintf( __( 'Fees are unavailable: %s. Requests can still be taken and approved without a fee.', 'fishotel-misc-plugin' ), $ctx['fees']['reason'] ) );
		?>
	</div>
<?php endif; ?>

<?php if ( ! $ctx['cleanroom'] ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--warning">
		<?php esc_html_e( 'The Clean Room section is switched off, so a request cannot follow its fish by itself (on order → in quarantine → ready), sizes are typed freely, and there is no past demand to show. Everything else works; mark requests as filled by hand.', 'fishotel-misc-plugin' ); ?>
	</div>
<?php endif; ?>
