<?php
/**
 * FisHotel Health dashboard widget — light WordPress styling, one small
 * inline style block.
 *
 * @package FisHotel\Misc\Sections\Health
 *
 * @var array   $facts    Facts::collect().
 * @var array[] $rows     Checks::evaluate().
 * @var array   $summary  Checks::summary().
 * @var array[] $problems Checks::problems() (max 8).
 * @var array[] $recent   Last 5 error rows.
 * @var int     $now      Now.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Health\Admin;
use FisHotel\Misc\Sections\Health\Checks;

$fh_pill_labels = array(
	'ok'    => __( 'All clear', 'fishotel-misc-plugin' ),
	'warn'  => __( 'Needs a look', 'fishotel-misc-plugin' ),
	'error' => __( 'Action needed', 'fishotel-misc-plugin' ),
);
$fh_plugins     = (array) ( $facts['plugins'] ?? array() );
$fh_bits        = array();
foreach ( array( 'misc', 'batch', 'shiptracker', 'smtp' ) as $fh_key ) {
	if ( ! empty( $fh_plugins[ $fh_key ]['active'] ) && ! empty( $fh_plugins[ $fh_key ]['version'] ) ) {
		$fh_bits[] = $fh_plugins[ $fh_key ]['label'] . ' ' . $fh_plugins[ $fh_key ]['version'];
	}
}
if ( ! empty( $facts['theme']['version'] ) ) {
	$fh_bits[] = 'Theme ' . $facts['theme']['version'];
}
?>
<style>
	.fh-hw-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff;background:#2e9e47}
	.fh-hw-pill--warn{background:#dba617}
	.fh-hw-pill--error{background:#d63638}
	.fh-hw-meta{color:#646970;font-size:12px;margin:4px 0 8px}
	.fh-hw-list{margin:0 0 8px;padding:0;list-style:none}
	.fh-hw-list li{padding:4px 0;border-top:1px solid #f0f0f1;font-size:12px}
	.fh-hw-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:#dba617;vertical-align:middle}
	.fh-hw-dot--error{background:#d63638}
	.fh-hw-hint{display:block;color:#646970}
	.fh-hw-age{color:#646970;white-space:nowrap}
	.fh-hw-h{margin:10px 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:#646970}
</style>
<p>
	<span class="fh-hw-pill fh-hw-pill--<?php echo esc_attr( $summary['status'] ); ?>"><?php echo esc_html( $fh_pill_labels[ $summary['status'] ] ?? $summary['status'] ); ?></span>
	<span class="fh-hw-meta" style="display:inline;margin-left:8px;">
		<?php
		/* translators: 1: errors, 2: warnings */
		echo esc_html( sprintf( __( '%1$d errors · %2$d warnings', 'fishotel-misc-plugin' ), (int) $summary['errors'], (int) $summary['warnings'] ) );
		?>
	</span>
</p>
<?php if ( $fh_bits ) : ?>
	<p class="fh-hw-meta"><?php echo esc_html( implode( ' · ', $fh_bits ) ); ?></p>
<?php endif; ?>

<?php if ( $problems ) : ?>
	<p class="fh-hw-h"><?php esc_html_e( 'Needs attention', 'fishotel-misc-plugin' ); ?></p>
	<ul class="fh-hw-list">
		<?php foreach ( $problems as $fh_row ) : ?>
			<li>
				<span class="fh-hw-dot fh-hw-dot--<?php echo esc_attr( $fh_row['status'] ); ?>"></span>
				<strong><?php echo esc_html( $fh_row['label'] ); ?></strong>
				<?php if ( '' !== $fh_row['value'] ) : ?>
					&mdash; <?php echo esc_html( $fh_row['value'] ); ?>
				<?php endif; ?>
				<?php if ( '' !== $fh_row['hint'] ) : ?>
					<span class="fh-hw-hint"><?php echo esc_html( $fh_row['hint'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<p class="fh-hw-meta"><?php esc_html_e( 'Every check passed.', 'fishotel-misc-plugin' ); ?></p>
<?php endif; ?>

<p class="fh-hw-h"><?php esc_html_e( 'Last errors', 'fishotel-misc-plugin' ); ?></p>
<?php if ( $recent ) : ?>
	<ul class="fh-hw-list">
		<?php foreach ( array_slice( $recent, 0, 5 ) as $fh_entry ) : ?>
			<?php
			$fh_entry = (array) $fh_entry;
			$fh_ts    = isset( $fh_entry['logged_ts'] ) ? (int) $fh_entry['logged_ts'] : 0;
			$fh_msg   = Checks::cut( (string) ( $fh_entry['message'] ?? '' ), 120 );
			?>
			<li>
				<code><?php echo esc_html( (string) ( $fh_entry['source'] ?? 'app' ) ); ?></code>
				<?php echo esc_html( $fh_msg ); ?>
				<?php if ( (int) ( $fh_entry['repeats'] ?? 1 ) > 1 ) : ?>
					<span class="fh-hw-age">×<?php echo esc_html( (int) $fh_entry['repeats'] ); ?></span>
				<?php endif; ?>
				<?php if ( $fh_ts > 0 ) : ?>
					<span class="fh-hw-age">· <?php echo esc_html( Checks::relative_age( max( 0, $now - $fh_ts ) ) ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<p class="fh-hw-meta"><?php esc_html_e( 'No errors logged.', 'fishotel-misc-plugin' ); ?></p>
<?php endif; ?>

<p>
	<a href="<?php echo esc_url( Admin::page_url( 'overview' ) ); ?>"><?php esc_html_e( 'Open the Health page', 'fishotel-misc-plugin' ); ?> &rarr;</a>
	&nbsp;·&nbsp;
	<a href="<?php echo esc_url( Admin::page_url( 'log' ) ); ?>"><?php esc_html_e( 'Log', 'fishotel-misc-plugin' ); ?></a>
</p>
