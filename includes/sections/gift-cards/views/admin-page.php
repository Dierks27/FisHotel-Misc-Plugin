<?php
/**
 * FisHotel Tools → Gift Cards: the header, the tabs, the mode in plain
 * words, the one-time notice, then the tab.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var string $tab   The tab.
 * @var string $view  The view (a tab, or "card").
 * @var array  $ctx   Admin::context().
 * @var array  $flash Admin::take_flash().
 * @var string $mode  The effective mode.
 * @var array  $data  The view's data.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Gift_Cards;

$fh_tabs = array(
	'cards' => __( 'Cards', 'fishotel-misc-plugin' ),
	'issue' => __( 'Issue a card', 'fishotel-misc-plugin' ),
	'stats' => __( 'Stats', 'fishotel-misc-plugin' ),
	'audit' => __( 'Audit log', 'fishotel-misc-plugin' ),
);
if ( 'live' !== $mode || ! $ctx['can_edit'] ) {
	unset( $fh_tabs['issue'] );
}
if ( $ctx['can_own'] ) {
	$fh_tabs['import']   = __( 'Import', 'fishotel-misc-plugin' );
	$fh_tabs['settings'] = __( 'Settings', 'fishotel-misc-plugin' );
}
if ( 'off' === $mode ) {
	$fh_tabs = array( 'settings' => __( 'Settings', 'fishotel-misc-plugin' ) );
}
$fh_view_file = Gift_Cards::path() . 'views/admin-' . $view . '.php';
?>
<div class="wrap fishotel-misc-wrap fh-gc-wrap">
	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Gift Cards', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-gc-tabs">
		<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
			<a class="fh-gc-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'off' === $mode ) : ?>
		<div class="fishotel-misc-notice fh-gc-mode fh-gc-mode--off"><?php esc_html_e( 'Mode Off: nothing runs — no checkout box, no account page, no e-mails. Choose Shadow below to import and check the old cards while the old plugin stays in charge.', 'fishotel-misc-plugin' ); ?></div>
	<?php elseif ( 'shadow' === $mode && ! empty( $ctx['modes']['fallback'] ) ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error fh-gc-mode"><?php esc_html_e( 'Live is switched on, but the old gift card plugin is switched on again — so these gift cards have stopped taking payments and work as in Shadow until it is switched off.', 'fishotel-misc-plugin' ); ?></div>
	<?php elseif ( 'shadow' === $mode ) : ?>
		<div class="fishotel-misc-notice fh-gc-mode fh-gc-mode--shadow"><?php esc_html_e( 'Shadow: the old gift card plugin is still in charge — customers use it. Here you can import, look, search and count; cards are issued and changed in the old plugin until Live.', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<?php if ( '' !== $flash['message'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--<?php echo $flash['ok'] ? 'success' : 'error'; ?>" role="status"><?php echo esc_html( $flash['message'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! $ctx['ready'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'The gift card tables are not installed, so nothing can be saved. Reload this page as an administrator; if this stays, the database refused them (they need InnoDB).', 'fishotel-misc-plugin' ); ?></div>
	<?php endif; ?>

	<?php
	if ( file_exists( $fh_view_file ) ) {
		include $fh_view_file;
	}
	?>
</div>
