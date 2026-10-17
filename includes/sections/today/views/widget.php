<?php
/**
 * Today at FisHotel dashboard widget — light WordPress styling, one small
 * inline style block: the header line, the first date's chips and its
 * destinations as a compact list, one line per further date, Refresh.
 *
 * @package FisHotel\Misc\Sections\Today
 *
 * @var array         $settings Settings.
 * @var \DateTimeZone $tz       Site zone.
 * @var array         $data     Collector::get().
 * @var string        $header   Model::header_line().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Today\Admin;
use FisHotel\Misc\Sections\Today\Model;

$fh_columns = (array) $data['columns'];
$fh_flags   = array(
	'weather'  => ! empty( $fh_columns['weather'] ),
	'invoice'  => ! empty( $fh_columns['invoice'] ),
	'tracking' => ! empty( $fh_columns['tracking'] ),
);
$fh_blocks  = (array) $data['blocks'];
$fh_first   = $fh_blocks ? reset( $fh_blocks ) : null;
$fh_rest    = $fh_blocks ? array_slice( $fh_blocks, 1, null, true ) : array();
$fh_more    = array_diff_key( (array) $data['counts'], $fh_blocks );
?>
<style>
	.fh-tw-head{font-weight:600;margin:0 0 6px}
	.fh-tw-head.is-ship-day{color:#1d6f3f}
	.fh-tw-meta{color:#646970;font-size:12px;margin:4px 0 8px}
	.fh-tw-chips{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:12px;margin:0 0 8px}
	.fh-tw-chips strong{font-weight:600}
	.fh-tw-list{margin:0 0 8px;padding:0;list-style:none}
	.fh-tw-list li{padding:4px 0;border-top:1px solid #f0f0f1;font-size:12px}
	.fh-tw-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;letter-spacing:.3px;color:#fff;background:#6b6b6b;vertical-align:middle;margin-left:4px}
	.fh-tw-badge--heat_pack{background:#d35400}.fh-tw-badge--none{background:#2e9e47}.fh-tw-badge--cooling{background:#0b7fc2}
	.fh-tw-badge--ups_store{background:#c0392b}.fh-tw-badge--hold{background:#8e1b1b}.fh-tw-badge--at_line{background:#b7791f}
	.fh-tw-badge--unavailable{background:#7a7a7a}.fh-tw-badge--not_checked{background:transparent;color:#8a8a8a;border:1px dashed #9a9a9a}
	.fh-tw-open{color:#8c8f94;font-size:11px;margin-left:6px}
	.fh-tw-h{margin:10px 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.3px;color:#646970}
	.fh-tw-note{color:#646970;font-size:11px}
</style>
<p class="fh-tw-head<?php echo ! empty( $data['plan']['ships_today'] ) ? ' is-ship-day' : ''; ?>"><?php echo esc_html( $header ); ?></p>

<?php foreach ( (array) ( $fh_columns['notes'] ?? array() ) as $fh_note ) : ?>
	<p class="fh-tw-note"><?php echo esc_html( $fh_note ); ?></p>
<?php endforeach; ?>

<?php if ( $fh_first ) : ?>
	<?php $fh_chips = (array) $fh_first['chips']; ?>
	<p class="fh-tw-h">
		<?php
		/* translators: 1: delivery date, 2: ship date */
		echo esc_html( sprintf( __( 'Deliver %1$s · ship %2$s', 'fishotel-misc-plugin' ), Model::date_label( $fh_first['date'], $tz, 'D M j' ), Model::date_label( $fh_first['ship'], $tz, 'D M j' ) ) );
		?>
	</p>
	<div class="fh-tw-chips">
		<span><strong><?php echo esc_html( (int) $fh_chips['orders'] ); ?></strong> <?php esc_html_e( 'orders', 'fishotel-misc-plugin' ); ?></span>
		<span><strong><?php echo esc_html( (int) $fh_chips['fish'] ); ?></strong> <?php esc_html_e( 'fish', 'fishotel-misc-plugin' ); ?></span>
		<span><strong><?php echo esc_html( (int) $fh_chips['dry'] ); ?></strong> <?php esc_html_e( 'dry', 'fishotel-misc-plugin' ); ?></span>
		<?php if ( $fh_flags['weather'] ) : ?>
			<?php if ( (int) $fh_chips['holds'] > 0 ) : ?>
				<span><strong><?php echo esc_html( (int) $fh_chips['holds'] ); ?></strong> <?php esc_html_e( 'holds', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
			<?php if ( (int) $fh_chips['ups_store'] > 0 ) : ?>
				<span><strong><?php echo esc_html( (int) $fh_chips['ups_store'] ); ?></strong> <?php esc_html_e( 'UPS Store', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
			<span><strong><?php echo esc_html( (int) $fh_chips['needs_weather'] ); ?></strong> <?php esc_html_e( 'need weather', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
		<?php if ( $fh_flags['invoice'] ) : ?>
			<span><strong><?php echo esc_html( (int) $fh_chips['needs_invoice'] ); ?></strong> <?php esc_html_e( 'need invoice', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
		<?php if ( $fh_flags['tracking'] ) : ?>
			<span><strong><?php echo esc_html( (int) $fh_chips['needs_tracking'] ); ?></strong> <?php esc_html_e( 'need tracking', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
	</div>
	<ul class="fh-tw-list">
		<?php foreach ( (array) $fh_first['destinations'] as $fh_d ) : ?>
			<?php
			$fh_open = array();
			foreach ( Model::checklist( $fh_d, $fh_flags ) as $fh_item ) {
				if ( empty( $fh_item['done'] ) ) {
					$fh_open[] = strtolower( $fh_item['label'] );
				}
			}
			// Active NWS alerts ride along in the same span (FLOOD WATCH …).
			if ( $fh_flags['weather'] && ! empty( $fh_d['weather']['alerts'] ) ) {
				$fh_open = array_merge( $fh_open, array_map( 'strval', (array) $fh_d['weather']['alerts'] ) );
			}
			$fh_badge = Model::badge( (array) $fh_d['weather'] );
			?>
			<li>
				<?php if ( '' !== $fh_d['edit_url'] ) : ?>
					<a href="<?php echo esc_url( $fh_d['edit_url'] ); ?>">#<?php echo esc_html( $fh_d['number'] ); ?></a>
				<?php else : ?>
					<strong>#<?php echo esc_html( $fh_d['number'] ); ?></strong>
				<?php endif; ?>
				<?php if ( $fh_d['is_fulfillment'] && $fh_d['sources'] ) : ?>
					<span class="fh-tw-note">(#<?php echo esc_html( implode( ', #', $fh_d['sources'] ) ); ?>)</span>
				<?php endif; ?>
				· <?php echo esc_html( $fh_d['customer'] ); ?>
				<?php if ( $fh_flags['weather'] ) : ?>
					<span class="fh-tw-badge fh-tw-badge--<?php echo esc_attr( $fh_badge['key'] ); ?>"><?php echo esc_html( $fh_badge['label'] ); ?></span>
				<?php endif; ?>
				<?php if ( $fh_open ) : ?>
					<span class="fh-tw-open"><?php echo esc_html( implode( ' · ', $fh_open ) ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php if ( $fh_rest || $fh_more ) : ?>
	<p class="fh-tw-h"><?php esc_html_e( 'Then', 'fishotel-misc-plugin' ); ?></p>
	<ul class="fh-tw-list">
		<?php foreach ( $fh_rest as $fh_ymd => $fh_block ) : ?>
			<li><?php echo esc_html( Model::date_summary( $fh_ymd, (array) $fh_block['chips'], $fh_flags, $tz ) ); ?></li>
		<?php endforeach; ?>
		<?php foreach ( $fh_more as $fh_ymd => $fh_n ) : ?>
			<li><?php echo esc_html( Model::date_label( $fh_ymd, $tz, 'D M j' ) . ' · ' . Model::plural( (int) $fh_n, __( 'order', 'fishotel-misc-plugin' ), __( 'orders', 'fishotel-misc-plugin' ) ) ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<p class="fh-tw-meta">
	<a href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Open the Today page', 'fishotel-misc-plugin' ); ?> &rarr;</a>
	&nbsp;·&nbsp;
	<a href="<?php echo esc_url( Admin::widget_refresh_url() ); ?>"><?php esc_html_e( 'Refresh', 'fishotel-misc-plugin' ); ?></a>
	&nbsp;·&nbsp;
	<?php echo esc_html( Admin::freshness_label( $data ) ); ?>
</p>
