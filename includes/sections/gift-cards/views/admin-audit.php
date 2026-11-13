<?php
/**
 * The audit log: every change, newest first. Filters, the chain check, the
 * export. No edit, no delete.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $data spec, get, rows, total, paged, verify.
 * @var array $ctx  Admin::context().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Audit;
use FisHotel\Misc\Sections\Gift_Cards\Gift_Cards;

$fh_get   = $data['get'];
$fh_g     = static function ( $key ) use ( $fh_get ) {
	return isset( $fh_get[ $key ] ) && is_scalar( $fh_get[ $key ] ) ? (string) $fh_get[ $key ] : '';
};
$fh_pages = max( 1, (int) ceil( $data['total'] / 100 ) );
$fh_rows  = $data['rows'];
?>
<?php if ( is_array( $data['verify'] ) ) : ?>
	<?php if ( $data['verify']['ok'] ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--success">
			<?php
			/* translators: %d: rows */
			echo esc_html( sprintf( __( 'The audit log checks out: %d rows, none changed or removed.', 'fishotel-misc-plugin' ), (int) $data['verify']['checked'] ) );
			if ( $data['verify']['other_key'] > 0 ) {
				echo ' ';
				/* translators: %d: rows */
				echo esc_html( sprintf( __( '%d older rows were written under an earlier site key (the site\'s salts were changed since) and could not be checked.', 'fishotel-misc-plugin' ), (int) $data['verify']['other_key'] ) );
			}
			?>
		</div>
	<?php else : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<strong><?php esc_html_e( 'The audit log does not check out — rows were changed or removed outside this plugin:', 'fishotel-misc-plugin' ); ?></strong>
			<ul><?php foreach ( $data['verify']['problems'] as $fh_p ) : ?><li><?php echo esc_html( $fh_p ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php endif; ?>
<?php endif; ?>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-gc-toolbar">
	<input type="hidden" name="page" value="<?php echo esc_attr( Gift_Cards::MENU_SLUG ); ?>">
	<input type="hidden" name="tab" value="audit">
	<label><span><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></span>
		<select name="action_filter"><option value=""><?php esc_html_e( 'Everything', 'fishotel-misc-plugin' ); ?></option>
			<?php foreach ( Audit::actions() as $fh_k => $fh_l ) : ?><option value="<?php echo esc_attr( $fh_k ); ?>" <?php selected( $fh_g( 'action_filter' ), $fh_k ); ?>><?php echo esc_html( $fh_l ); ?></option><?php endforeach; ?>
		</select></label>
	<label><span><?php esc_html_e( 'From where', 'fishotel-misc-plugin' ); ?></span>
		<select name="source"><option value=""><?php esc_html_e( 'Anywhere', 'fishotel-misc-plugin' ); ?></option>
			<?php foreach ( Audit::SOURCES as $fh_k ) : ?><option value="<?php echo esc_attr( $fh_k ); ?>" <?php selected( $fh_g( 'source' ), $fh_k ); ?>><?php echo esc_html( $fh_k ); ?></option><?php endforeach; ?>
		</select></label>
	<label><span><?php esc_html_e( 'Card #', 'fishotel-misc-plugin' ); ?></span><input type="number" name="card_id" min="1" value="<?php echo esc_attr( $fh_g( 'card_id' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></span><input type="date" name="from" value="<?php echo esc_attr( $fh_g( 'from' ) ); ?>"></label>
	<label><span><?php esc_html_e( 'To', 'fishotel-misc-plugin' ); ?></span><input type="date" name="to" value="<?php echo esc_attr( $fh_g( 'to' ) ); ?>"></label>
	<button type="submit" class="button"><?php esc_html_e( 'Show', 'fishotel-misc-plugin' ); ?></button>
	<a class="button" href="<?php echo esc_url( Admin::page_url( 'audit', array( 'verify' => 1 ) ) ); ?>"><?php esc_html_e( 'Check the log', 'fishotel-misc-plugin' ); ?></a>
</form>

<?php if ( $ctx['can_own'] ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-gc-inline">
		<?php wp_nonce_field( Admin::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
		<input type="hidden" name="op" value="export">
		<?php foreach ( array( 'action_filter', 'source', 'card_id', 'from', 'to' ) as $fh_k ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $fh_k ); ?>" value="<?php echo esc_attr( $fh_g( $fh_k ) ); ?>">
		<?php endforeach; ?>
		<button type="submit" class="button"><?php esc_html_e( 'Export these rows as CSV', 'fishotel-misc-plugin' ); ?></button>
		<span class="fh-gc-sub">
		<?php
		/* translators: %d: rows */
		echo esc_html( sprintf( _n( '%d row', '%d rows', (int) $data['total'], 'fishotel-misc-plugin' ), (int) $data['total'] ) );
		?>
		· <?php esc_html_e( 'Rows are only ever added: nothing here can be edited or deleted.', 'fishotel-misc-plugin' ); ?></span>
	</form>
<?php endif; ?>

<?php include __DIR__ . '/admin-audit-rows.php'; ?>

<?php if ( $fh_pages > 1 ) : ?>
	<p class="fh-gc-pages">
		<?php if ( $data['paged'] > 1 ) : ?><a class="button" href="<?php echo esc_url( Admin::page_url( 'audit', array_filter( array( 'action_filter' => $fh_g( 'action_filter' ), 'source' => $fh_g( 'source' ), 'card_id' => $fh_g( 'card_id' ), 'from' => $fh_g( 'from' ), 'to' => $fh_g( 'to' ), 'paged' => (string) ( $data['paged'] - 1 ) ), 'strlen' ) ) ); ?>"><?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a><?php endif; ?>
		<?php if ( $data['paged'] < $fh_pages ) : ?><a class="button" href="<?php echo esc_url( Admin::page_url( 'audit', array_filter( array( 'action_filter' => $fh_g( 'action_filter' ), 'source' => $fh_g( 'source' ), 'card_id' => $fh_g( 'card_id' ), 'from' => $fh_g( 'from' ), 'to' => $fh_g( 'to' ), 'paged' => (string) ( $data['paged'] + 1 ) ), 'strlen' ) ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?></a><?php endif; ?>
	</p>
<?php endif; ?>
