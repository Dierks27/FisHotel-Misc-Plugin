<?php
/**
 * Snippets page header and tabs (Files / My snippets / Log), plus the
 * one-shot notice of the last write.
 *
 * @package FisHotel\Misc\Sections\Site_Snippets
 *
 * @var string     $fh_tab files|mine|log.
 * @var array|null $notice { kind, text } (optional).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Site_Snippets\Admin;

$fh_tabs = array(
	'files' => __( 'Files (GitHub)', 'fishotel-misc-plugin' ),
	'mine'  => __( 'My snippets', 'fishotel-misc-plugin' ),
	'log'   => __( 'Log', 'fishotel-misc-plugin' ),
);
?>
<div class="fishotel-misc-header">
	<h1><?php esc_html_e( 'Snippets', 'fishotel-misc-plugin' ); ?></h1>
	<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
</div>

<nav class="fh-snip-tabs" aria-label="<?php esc_attr_e( 'Snippets', 'fishotel-misc-plugin' ); ?>">
	<?php foreach ( $fh_tabs as $fh_key => $fh_label ) : ?>
		<a class="fh-snip-tab<?php echo $fh_tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( 'files' === $fh_key ? array() : array( 'tab' => $fh_key ) ) ); ?>"<?php echo $fh_tab === $fh_key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $fh_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( ! empty( $notice ) && is_array( $notice ) ) : ?>
	<div class="fh-snip-notice is-<?php echo esc_attr( in_array( $notice['kind'], array( 'ok', 'info', 'error' ), true ) ? $notice['kind'] : 'info' ); ?>" role="status"><?php echo esc_html( $notice['text'] ); ?></div>
<?php endif; ?>
