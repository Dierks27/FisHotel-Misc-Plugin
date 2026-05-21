<?php
/**
 * Visitor Stats dashboard view.
 *
 * @package FisHotel\Misc\Sections\Visitor_Stats
 *
 * @var string   $range         Active range key (24h|7d|30d).
 * @var int      $range_days    Day window for the active range.
 * @var int      $online_now    Visitors currently online.
 * @var object[] $top_pages     Top pages rows.
 * @var object[] $top_products  Top products rows (empty if WC inactive).
 * @var object[] $top_referrers Top referrers rows.
 * @var bool     $has_wc        Whether WooCommerce is active.
 * @var string   $base_url      Visitor Stats page URL (for range links).
 */

defined( 'ABSPATH' ) || exit;

$fh_ranges = array(
	'24h' => __( '24h', 'fishotel-misc-plugin' ),
	'7d'  => __( '7 days', 'fishotel-misc-plugin' ),
	'30d' => __( '30 days', 'fishotel-misc-plugin' ),
);
$fh_empty    = __( 'No data yet — stats start populating once visitors arrive.', 'fishotel-misc-plugin' );
?>

<div class="wrap fishotel-misc-wrap fishotel-stats-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Visitor Stats', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<!-- Online now hero -->
	<div class="fh-stats-online">
		<span class="fh-stats-online-now"><?php echo (int) $online_now; ?></span>
		<span class="fh-stats-online-label"><?php esc_html_e( 'on the site right now', 'fishotel-misc-plugin' ); ?></span>
	</div>

	<!-- Date range toggle -->
	<div class="fh-stats-range">
		<?php
		foreach ( $fh_ranges as $fh_key => $fh_label ) :
			$fh_is_active = ( $range === $fh_key );
			$fh_url       = add_query_arg( 'range', $fh_key, $base_url );
			?>
			<a class="fh-stats-range__btn<?php echo $fh_is_active ? ' is-active' : ''; ?>"
				href="<?php echo esc_url( $fh_url ); ?>"
				<?php echo $fh_is_active ? 'aria-current="true"' : ''; ?>>
				<?php echo esc_html( $fh_label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<!-- Two-column grid -->
	<div class="fh-stats-grid">

		<!-- Top pages -->
		<div class="fh-stats-card">
			<h2><?php esc_html_e( 'Top Pages', 'fishotel-misc-plugin' ); ?></h2>
			<table class="fh-stats-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-stats-table__num"><?php esc_html_e( 'Views', 'fishotel-misc-plugin' ); ?></th>
						<th class="fh-stats-table__num"><?php esc_html_e( 'Unique', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top_pages ) ) : ?>
						<tr><td class="fh-stats-empty" colspan="3"><?php echo esc_html( $fh_empty ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $top_pages as $fh_row ) :
							$fh_page_url   = home_url( $fh_row->url );
							$fh_page_title = '' !== $fh_row->title ? $fh_row->title : $fh_row->url;
							?>
							<tr>
								<td class="fh-stats-table__page">
									<a href="<?php echo esc_url( $fh_page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $fh_page_title ); ?></a>
									<span class="fh-stats-table__url"><?php echo esc_html( $fh_row->url ); ?></span>
								</td>
								<td class="fh-stats-table__num"><?php echo esc_html( number_format_i18n( $fh_row->views ) ); ?></td>
								<td class="fh-stats-table__num"><?php echo esc_html( number_format_i18n( $fh_row->unique_views ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $has_wc ) : ?>
			<!-- Top products -->
			<div class="fh-stats-card">
				<h2><?php esc_html_e( 'Top Products', 'fishotel-misc-plugin' ); ?></h2>
				<table class="fh-stats-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'fishotel-misc-plugin' ); ?></th>
							<th class="fh-stats-table__num"><?php esc_html_e( 'Views', 'fishotel-misc-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $top_products ) ) : ?>
							<tr><td class="fh-stats-empty" colspan="2"><?php echo esc_html( $fh_empty ); ?></td></tr>
						<?php else : ?>
							<?php
							foreach ( $top_products as $fh_row ) :
								$fh_name = get_the_title( $fh_row->post_id );
								if ( '' === $fh_name ) {
									$fh_name = '' !== $fh_row->title ? $fh_row->title : $fh_row->url;
								}
								$fh_permalink = get_permalink( $fh_row->post_id );
								$fh_edit_link = admin_url( 'post.php?post=' . (int) $fh_row->post_id . '&action=edit' );
								?>
								<tr>
									<td class="fh-stats-table__page">
										<?php if ( $fh_permalink ) : ?>
											<a href="<?php echo esc_url( $fh_permalink ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $fh_name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $fh_name ); ?>
										<?php endif; ?>
										<a class="fh-stats-table__edit" href="<?php echo esc_url( $fh_edit_link ); ?>"><?php esc_html_e( 'Edit', 'fishotel-misc-plugin' ); ?></a>
									</td>
									<td class="fh-stats-table__num"><?php echo esc_html( number_format_i18n( $fh_row->views ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

	</div>

	<!-- Top referrers (full width) -->
	<div class="fh-stats-card">
		<h2><?php esc_html_e( 'Top Referrers', 'fishotel-misc-plugin' ); ?></h2>
		<table class="fh-stats-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Domain', 'fishotel-misc-plugin' ); ?></th>
					<th class="fh-stats-table__num"><?php esc_html_e( 'Visits', 'fishotel-misc-plugin' ); ?></th>
					<th class="fh-stats-table__num"><?php esc_html_e( 'Unique', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$fh_has_referrers = false;
				foreach ( (array) $top_referrers as $fh_row ) {
					if ( '' !== $fh_row->referrer_domain ) {
						$fh_has_referrers = true;
						break;
					}
				}
				?>
				<?php if ( ! $fh_has_referrers ) : ?>
					<tr><td class="fh-stats-empty" colspan="3"><?php echo esc_html( $fh_empty ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $top_referrers as $fh_row ) :
						if ( '' === $fh_row->referrer_domain ) {
							continue;
						}
						$fh_ref_url = 'https://' . $fh_row->referrer_domain;
						?>
						<tr>
							<td class="fh-stats-table__page">
								<a href="<?php echo esc_url( $fh_ref_url ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $fh_row->referrer_domain ); ?></a>
							</td>
							<td class="fh-stats-table__num"><?php echo esc_html( number_format_i18n( $fh_row->visits ) ); ?></td>
							<td class="fh-stats-table__num"><?php echo esc_html( number_format_i18n( $fh_row->unique_visitors ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

</div>
