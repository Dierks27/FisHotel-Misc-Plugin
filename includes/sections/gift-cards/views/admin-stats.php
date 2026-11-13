<?php
/**
 * Stats: what is still owed (the number for the books), sold and redeemed,
 * never used, the average card, not used in over a year, and the health
 * check.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $data stats.
 * @var array $ctx  Admin::context().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Settings;

$fh_s    = $data['stats'];
$fh_tile = static function ( $label, $value, $sub = '' ) {
	echo '<div class="fh-gc-tile"><span class="fh-gc-tile__label">' . esc_html( $label ) . '</span><span class="fh-gc-tile__value">' . esc_html( $value ) . '</span>' . ( '' !== $sub ? '<span class="fh-gc-tile__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
};
/* translators: %d: cards */
$fh_n = static function ( $n ) {
	return sprintf( _n( '%d card', '%d cards', (int) $n, 'fishotel-misc-plugin' ), (int) $n );
};
?>
<div class="fishotel-misc-card fh-gc-books">
	<span class="fh-gc-tile__label"><?php esc_html_e( 'Still owed on all cards — the number for the books', 'fishotel-misc-plugin' ); ?></span>
	<span class="fh-gc-big"><?php echo esc_html( Money::words( (int) $fh_s['books_cents'] ) ); ?></span>
	<span class="fh-gc-sub">
	<?php
	/* translators: 1: active, 2: disabled */
	echo esc_html( sprintf( __( '%1$s on cards that work, %2$s on disabled cards', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['outstanding_active'] ), Money::words( (int) $fh_s['outstanding_disabled'] ) ) );
	echo ' — ';
	echo Settings::on( $ctx['service']->settings, 'books_include_disabled' ) ? esc_html__( 'both are counted (Settings).', 'fishotel-misc-plugin' ) : esc_html__( 'only the first is counted (Settings).', 'fishotel-misc-plugin' );
	?>
	</span>
</div>

<div class="fh-gc-tiles">
	<?php
	$fh_tile( __( 'Sold this month', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['month_sold_cents'] ), $fh_n( $fh_s['month_sold'] ) );
	$fh_tile( __( 'Sold this year', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['year_sold_cents'] ), $fh_n( $fh_s['year_sold'] ) );
	$fh_tile( __( 'Issued by hand this month', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['month_issued_cents'] ), $fh_n( $fh_s['month_issued'] ) );
	$fh_tile( __( 'Issued by hand this year', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['year_issued_cents'] ), $fh_n( $fh_s['year_issued'] ) );
	$fh_tile( __( 'Redeemed this month', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['month_redeemed_cents'] ), __( 'used on orders, less what was put back', 'fishotel-misc-plugin' ) );
	$fh_tile( __( 'Redeemed this year', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['year_redeemed_cents'] ) );
	$fh_tile( __( 'Never used', 'fishotel-misc-plugin' ), $fh_n( $fh_s['never_used'] ), Money::words( (int) $fh_s['never_used_cents'] ) );
	$fh_tile( __( 'Average card', 'fishotel-misc-plugin' ), Money::words( (int) $fh_s['average_amount'] ), $fh_n( $fh_s['cards'] ) . ' ' . __( 'in all', 'fishotel-misc-plugin' ) );
	$fh_tile( __( 'Not used in over a year', 'fishotel-misc-plugin' ), $fh_n( $fh_s['idle_year'] ), Money::words( (int) $fh_s['idle_year_cents'] ) . ' ' . __( 'on them', 'fishotel-misc-plugin' ) );
	?>
</div>
<p class="fh-gc-sub"><?php esc_html_e( 'Months and years are the site\'s calendar. Unclaimed-property (escheat) rules for old balances differ by state — a question for the accountant, not for this screen.', 'fishotel-misc-plugin' ); ?></p>

<h2><?php esc_html_e( 'Health check', 'fishotel-misc-plugin' ); ?></h2>
<?php if ( ! $fh_s['mismatches'] ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--success"><?php esc_html_e( 'Every card\'s balance matches its money history to the cent.', 'fishotel-misc-plugin' ); ?></div>
<?php else : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'These cards\' balances do NOT match their money history — something changed them outside this screen. Look at their audit log:', 'fishotel-misc-plugin' ); ?>
		<?php foreach ( $fh_s['mismatches'] as $fh_m ) : ?>
			<a href="<?php echo esc_url( Admin::card_url( (int) $fh_m['id'] ) ); ?>">#<?php echo esc_html( (string) (int) $fh_m['id'] ); ?></a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<?php if ( ! empty( $fh_s['unlogged'] ) ) : ?>
	<div class="fishotel-misc-notice fishotel-misc-notice--error">
		<?php esc_html_e( 'These cards\' money was moved without a matching row in the audit log — something changed them outside this plugin. Look at their history and audit log:', 'fishotel-misc-plugin' ); ?>
		<?php foreach ( $fh_s['unlogged'] as $fh_u ) : ?>
			<a href="<?php echo esc_url( Admin::card_url( (int) $fh_u ) ); ?>">#<?php echo esc_html( (string) (int) $fh_u ); ?></a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<p><a class="button" href="<?php echo esc_url( Admin::page_url( 'audit', array( 'verify' => 1 ) ) ); ?>"><?php esc_html_e( 'Check the audit log has not been tampered with', 'fishotel-misc-plugin' ); ?></a></p>
