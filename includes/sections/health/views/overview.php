<?php
/**
 * Health overview — the check rows grouped, with a summary strip.
 *
 * @package FisHotel\Misc\Sections\Health
 *
 * @var array   $facts      Facts::collect().
 * @var array[] $rows       Checks::evaluate().
 * @var array   $summary    Checks::summary().
 * @var array   $groups     Checks::by_group().
 * @var array   $last_prune Last prune summary (may be empty).
 * @var bool    $table_ok   Whether the log table exists.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Health\Admin;
use FisHotel\Misc\Sections\Health\Checks;

$fh_summary_labels = array(
	'ok'    => __( 'All clear', 'fishotel-misc-plugin' ),
	'warn'  => __( 'Needs a look', 'fishotel-misc-plugin' ),
	'error' => __( 'Action needed', 'fishotel-misc-plugin' ),
);
$fh_group_icons    = array(
	Checks::GROUP_PLUGINS  => 'dashicons-admin-plugins',
	Checks::GROUP_UPDATES  => 'dashicons-update',
	Checks::GROUP_PAPER    => 'dashicons-media-document',
	Checks::GROUP_CRON     => 'dashicons-clock',
	Checks::GROUP_SWITCHES => 'dashicons-admin-generic',
	Checks::GROUP_SERVER   => 'dashicons-cloud',
	Checks::GROUP_ERRORS   => 'dashicons-warning',
);
?>
<div class="wrap fishotel-misc-wrap fh-health-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Health', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-health-tabs">
		<a class="fh-health-tab is-active" href="<?php echo esc_url( Admin::page_url( 'overview' ) ); ?>"><?php esc_html_e( 'Overview', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'log' ) ); ?>"><?php esc_html_e( 'Log', 'fishotel-misc-plugin' ); ?></a>
		<a class="fh-health-tab" href="<?php echo esc_url( Admin::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'fishotel-misc-plugin' ); ?></a>
	</nav>

	<?php if ( ! $table_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The log table does not exist, so nothing is being recorded. Deactivate and reactivate the plugin to create it.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-health-summary fh-health-summary--<?php echo esc_attr( $summary['status'] ); ?>">
		<span class="fh-health-summary__pill"><?php echo esc_html( $fh_summary_labels[ $summary['status'] ] ?? $summary['status'] ); ?></span>
		<span class="fh-health-summary__counts">
			<?php
			/* translators: 1: errors, 2: warnings, 3: checks */
			echo esc_html( sprintf( __( '%1$d errors · %2$d warnings · %3$d checks', 'fishotel-misc-plugin' ), (int) $summary['errors'], (int) $summary['warnings'], count( $rows ) ) );
			?>
		</span>
		<span class="fh-health-summary__meta">
			<?php
			/* translators: %s: date/time */
			echo esc_html( sprintf( __( 'Checked %s (site time).', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $facts['now'] ) ) );
			if ( ! empty( $last_prune['at'] ) ) {
				/* translators: 1: date/time, 2: rows deleted */
				echo ' ' . esc_html( sprintf( __( 'Last prune %1$s: %2$d rows removed.', 'fishotel-misc-plugin' ), wp_date( get_option( 'date_format' ), (int) $last_prune['at'] ), (int) ( $last_prune['deleted'] ?? 0 ) ) );
			}
			?>
		</span>
	</div>

	<div class="fh-health-groups">
		<?php foreach ( $groups as $fh_group => $fh_rows ) : ?>
			<div class="fishotel-misc-card fh-health-card">
				<div class="fishotel-misc-card-header">
					<div class="fishotel-misc-card-icon"><span class="dashicons <?php echo esc_attr( $fh_group_icons[ $fh_group ] ?? 'dashicons-info' ); ?>"></span></div>
					<h2 class="fishotel-misc-card-title"><?php echo esc_html( $fh_group ); ?></h2>
				</div>
				<table class="fh-health-checks">
					<tbody>
						<?php foreach ( $fh_rows as $fh_row ) : ?>
							<tr class="fh-health-check fh-health-check--<?php echo esc_attr( $fh_row['status'] ); ?>" data-check="<?php echo esc_attr( $fh_row['id'] ); ?>">
								<td class="fh-health-check__status"><?php echo Admin::badge_html( $fh_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge_html(). ?></td>
								<td class="fh-health-check__label">
									<?php echo esc_html( $fh_row['label'] ); ?>
									<?php if ( '' !== $fh_row['hint'] ) : ?>
										<span class="fh-health-check__hint"><?php echo esc_html( $fh_row['hint'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="fh-health-check__value"><?php echo esc_html( $fh_row['value'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="fh-health-footnote">
		<?php esc_html_e( 'Other plugins and the theme log here with do_action( "fishotel_log", level, source, message, context ) — always safe — or, guarded with function_exists(), fishotel_log(), which only exists while this section is enabled. Email bodies, headers and tokens are never stored.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
