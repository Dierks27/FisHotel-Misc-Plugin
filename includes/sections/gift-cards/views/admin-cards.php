<?php
/**
 * The card list: filters, search, sort, a page of cards.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $data spec, cards, counts, expiry.
 * @var array $ctx  Admin::context().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Admin;
use FisHotel\Misc\Sections\Gift_Cards\Codes;
use FisHotel\Misc\Sections\Gift_Cards\Money;

$fh_spec    = $data['spec'];
$fh_filters = array(
	'in_use'   => __( 'In use', 'fishotel-misc-plugin' ),
	'used_up'  => __( 'Used up', 'fishotel-misc-plugin' ),
	'disabled' => __( 'Disabled', 'fishotel-misc-plugin' ),
	'all'      => __( 'All', 'fishotel-misc-plugin' ),
);
if ( $data['expiry'] ) {
	$fh_filters['expiry'] = __( 'With an old expiry date', 'fishotel-misc-plugin' );
}
$fh_link = static function ( array $change ) use ( $fh_spec ) {
	$args = array_filter(
		array_merge(
			array(
				'filter' => $fh_spec['filter'],
				'q'      => $fh_spec['q'],
				'sort'   => $fh_spec['sort'],
				'dir'    => $fh_spec['dir'],
			),
			$change
		),
		'strlen'
	);
	return Admin::page_url( 'cards', $args );
};
$fh_sort = static function ( $key, $label ) use ( $fh_spec, $fh_link ) {
	$dir   = $fh_spec['sort'] === $key && 'desc' === $fh_spec['dir'] ? 'asc' : 'desc';
	$arrow = $fh_spec['sort'] === $key ? ( 'desc' === $fh_spec['dir'] ? ' ↓' : ' ↑' ) : '';
	return '<a href="' . esc_url( $fh_link( array( 'sort' => $key, 'dir' => $dir, 'paged' => '' ) ) ) . '">' . esc_html( $label . $arrow ) . '</a>';
};
$fh_total = (int) $data['counts'][ $fh_spec['filter'] ];
$fh_pages = max( 1, (int) ceil( $fh_total / Admin::PER_PAGE ) );
$fh_date  = static function ( $utc ) {
	return $utc ? wp_date( 'M j, Y', strtotime( $utc . ' UTC' ) ) : '—';
};
?>
<div class="fh-gc-toolbar">
	<div class="fh-gc-filters">
		<?php foreach ( $fh_filters as $fh_key => $fh_label ) : ?>
			<a class="fh-gc-filter<?php echo $fh_spec['filter'] === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $fh_link( array( 'filter' => $fh_key, 'paged' => '' ) ) ); ?>"><?php echo esc_html( $fh_label ); ?> <span class="fh-gc-count"><?php echo esc_html( number_format_i18n( (int) $data['counts'][ $fh_key ] ) ); ?></span></a>
		<?php endforeach; ?>
	</div>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-gc-search">
		<input type="hidden" name="page" value="<?php echo esc_attr( \FisHotel\Misc\Sections\Gift_Cards\Gift_Cards::MENU_SLUG ); ?>">
		<input type="hidden" name="filter" value="<?php echo esc_attr( $fh_spec['filter'] ); ?>">
		<label class="screen-reader-text" for="fh-gc-q"><?php esc_html_e( 'Search', 'fishotel-misc-plugin' ); ?></label>
		<input type="search" id="fh-gc-q" name="q" value="<?php echo esc_attr( $fh_spec['q'] ); ?>" placeholder="<?php esc_attr_e( 'Code (or its last 4), name, e-mail, order number', 'fishotel-misc-plugin' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'fishotel-misc-plugin' ); ?></button>
	</form>
</div>

<?php if ( ! $data['cards'] ) : ?>
	<div class="fishotel-misc-empty"><span class="dashicons dashicons-tickets-alt"></span><p><?php echo '' !== $fh_spec['q'] ? esc_html__( 'No card matches that search here. Try "All".', 'fishotel-misc-plugin' ) : esc_html__( 'No card here.', 'fishotel-misc-plugin' ); ?></p></div>
<?php else : ?>
	<div class="fh-gc-scroll">
	<table class="widefat fh-gc-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Card', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col" class="fh-gc-num"><?php echo wp_kses_post( $fh_sort( 'balance', __( 'Left', 'fishotel-misc-plugin' ) ) ); ?></th>
				<th scope="col" class="fh-gc-num"><?php echo wp_kses_post( $fh_sort( 'amount', __( 'Issued for', 'fishotel-misc-plugin' ) ) ); ?></th>
				<th scope="col"><?php esc_html_e( 'For', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'From / bought by', 'fishotel-misc-plugin' ); ?></th>
				<th scope="col"><?php echo wp_kses_post( $fh_sort( 'issued', __( 'Issued', 'fishotel-misc-plugin' ) ) ); ?></th>
				<th scope="col"><?php echo wp_kses_post( $fh_sort( 'last_used', __( 'Last used', 'fishotel-misc-plugin' ) ) ); ?></th>
				<th scope="col"><?php esc_html_e( 'Order', 'fishotel-misc-plugin' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['cards'] as $fh_card ) : ?>
			<tr class="<?php echo 'active' !== $fh_card['status'] ? 'is-disabled' : ''; ?>">
				<td><a href="<?php echo esc_url( Admin::card_url( (int) $fh_card['id'] ) ); ?>"><code><?php echo esc_html( Codes::mask( $fh_card['code'] ) ); ?></code></a>
					<?php if ( 'active' !== $fh_card['status'] ) : ?><span class="fh-gc-badge fh-gc-badge--off"><?php esc_html_e( 'disabled', 'fishotel-misc-plugin' ); ?></span><?php endif; ?>
					<?php if ( 'import' === $fh_card['source'] ) : ?><span class="fh-gc-badge"><?php esc_html_e( 'old', 'fishotel-misc-plugin' ); ?></span><?php endif; ?>
				</td>
				<td class="fh-gc-num"><?php echo esc_html( Money::words( (int) $fh_card['balance_cents'] ) ); ?></td>
				<td class="fh-gc-num"><?php echo esc_html( Money::words( (int) $fh_card['amount_cents'] ) ); ?></td>
				<td><?php echo esc_html( trim( $fh_card['recipient_name'] . ' ' . $fh_card['recipient_email'] ) ?: '—' ); ?><?php echo (int) $fh_card['owner_user_id'] > 0 ? '<br><small>' . esc_html__( 'in an account', 'fishotel-misc-plugin' ) . '</small>' : ''; ?></td>
				<td><?php echo esc_html( trim( $fh_card['sender_name'] . ' ' . $fh_card['sender_email'] ) ?: '—' ); ?></td>
				<td><?php echo esc_html( $fh_date( $fh_card['created_at'] ) ); ?></td>
				<td><?php echo esc_html( $fh_date( $fh_card['last_used_at'] ) ); ?></td>
				<td><?php echo ! empty( $fh_card['order_id'] ) ? '<a href="' . esc_url( Admin::order_url( (int) $fh_card['order_id'] ) ) . '">#' . esc_html( (string) (int) $fh_card['order_id'] ) . '</a>' : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php if ( $fh_pages > 1 ) : ?>
		<p class="fh-gc-pages">
			<?php if ( $fh_spec['paged'] > 1 ) : ?><a class="button" href="<?php echo esc_url( $fh_link( array( 'paged' => (string) ( $fh_spec['paged'] - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'fishotel-misc-plugin' ); ?></a><?php endif; ?>
			<span>
			<?php
			/* translators: 1: page, 2: pages */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'fishotel-misc-plugin' ), $fh_spec['paged'], $fh_pages ) );
			?>
			</span>
			<?php if ( $fh_spec['paged'] < $fh_pages ) : ?><a class="button" href="<?php echo esc_url( $fh_link( array( 'paged' => (string) ( $fh_spec['paged'] + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'fishotel-misc-plugin' ); ?></a><?php endif; ?>
		</p>
	<?php endif; ?>
<?php endif; ?>
