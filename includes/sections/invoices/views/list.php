<?php
/**
 * The list — the invoices built here, each with what it really is right
 * now (paid by which order, in the Trash, …), and read-only below them
 * the invoices made by hand: products of the Invoices category this
 * section did not create and never changes.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      Admin::list_data().
 * @var array  $settings  Settings.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Rules;
use FisHotel\Misc\Sections\Invoices\Settings;

$fh_filters = array(
	''          => __( 'Open', 'fishotel-misc-plugin' ),
	'draft'     => __( 'Drafts', 'fishotel-misc-plugin' ),
	'published' => __( 'Published, not paid', 'fishotel-misc-plugin' ),
	'paid'      => __( 'Paid', 'fishotel-misc-plugin' ),
	'trashed'   => __( 'In the Trash', 'fishotel-misc-plugin' ),
	'cancelled' => __( 'Withdrawn', 'fishotel-misc-plugin' ),
	'all'       => __( 'All', 'fishotel-misc-plugin' ),
);
$fh_counts  = $data['counts'];
$fh_open    = $fh_counts['draft'] + $fh_counts['published'] + $fh_counts['paid'];

$fh_url = static function ( array $args ) use ( $data ) {
	$base = array_filter(
		array(
			'state' => $data['state'],
			's'     => $data['search'],
		),
		'strlen'
	);
	return Admin::page_url( 'list', array_merge( $base, $args ) );
};
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap">

	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<div class="fh-inv-toolbar">
		<nav class="fh-inv-filters" aria-label="<?php esc_attr_e( 'Filter by state', 'fishotel-misc-plugin' ); ?>">
			<?php foreach ( $fh_filters as $fh_key => $fh_label ) : ?>
				<?php
				$fh_count = '';
				if ( '' === $fh_key ) {
					$fh_count = $fh_open;
				} elseif ( isset( $fh_counts[ $fh_key ] ) ) {
					$fh_count = $fh_counts[ $fh_key ];
				}
				?>
				<a class="fh-inv-chip<?php echo $data['state'] === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::page_url( 'list', array_filter( array( 'state' => $fh_key, 's' => $data['search'] ), 'strlen' ) ) ); ?>">
					<?php echo esc_html( $fh_label ); ?><?php if ( '' !== $fh_count ) : ?> <span class="fh-inv-chip__count"><?php echo esc_html( $fh_count ); ?></span><?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fh-inv-search-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( Invoices::MENU_SLUG ); ?>">
			<?php if ( '' !== $data['state'] ) : ?>
				<input type="hidden" name="state" value="<?php echo esc_attr( $data['state'] ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="fh-inv-s"><?php esc_html_e( 'Search by handle, name or title', 'fishotel-misc-plugin' ); ?></label>
			<input type="search" id="fh-inv-s" name="s" class="fh-inv-input" value="<?php echo esc_attr( $data['search'] ); ?>" placeholder="<?php esc_attr_e( 'handle, name or title…', 'fishotel-misc-plugin' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'fishotel-misc-plugin' ); ?></button>
		</form>

		<a class="button button-primary" href="<?php echo esc_url( Admin::page_url( 'new' ) ); ?>"><?php esc_html_e( 'New invoice', 'fishotel-misc-plugin' ); ?></a>
	</div>

	<?php if ( ! $data['items'] ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-media-spreadsheet"></span>
			<p>
				<?php
				echo '' !== $data['search'] || '' !== $data['state']
					? esc_html__( 'No invoice matches.', 'fishotel-misc-plugin' )
					: esc_html__( 'No invoice has been built here yet. "New invoice" starts one: pick the fish, check the customer, publish, and paste the link.', 'fishotel-misc-plugin' );
				?>
			</p>
		</div>
	<?php else : ?>
		<table class="widefat fh-inv-table fh-inv-list">
			<thead>
				<tr>
					<td class="fh-inv-check"><input type="checkbox" class="fh-inv-check-all" aria-label="<?php esc_attr_e( 'Tick every invoice on this page', 'fishotel-misc-plugin' ); ?>"></td>
					<th scope="col"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Invoice', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Total', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shipping', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Built', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['items'] as $fh_item ) : ?>
					<?php
					$fh_inv     = $fh_item['invoice'];
					$fh_eff     = $fh_item['effective'];
					$fh_state   = (string) $fh_eff['state'];
					$fh_product = $fh_item['product'];
					$fh_link    = $fh_product && 'publish' === $fh_product['status'] ? (string) $fh_product['permalink'] : '';
					$fh_view    = Admin::page_url( 'invoice', array( 'id' => $fh_inv['id'] ) );
					$fh_listed  = in_array( $fh_state, array( 'published', 'paid' ), true ) && '' !== $fh_link;
					?>
					<tr class="fh-inv-row fh-inv-row--<?php echo esc_attr( $fh_state ); ?>">
						<td class="fh-inv-check">
							<?php if ( $fh_listed ) : ?>
								<input type="checkbox" class="fh-inv-pick" value="<?php echo esc_attr( $fh_inv['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: handle */ __( 'Tick the invoice of %s for the forum list', 'fishotel-misc-plugin' ), $fh_inv['handle'] ) ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $fh_inv['handle'] ); ?></strong>
							<?php if ( '' !== $fh_inv['customer_name'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( $fh_inv['customer_name'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $fh_view ); ?>">
								<?php echo esc_html( '' !== $fh_inv['title'] ? $fh_inv['title'] : sprintf( /* translators: %d: invoice id */ __( 'Draft #%d', 'fishotel-misc-plugin' ), $fh_inv['id'] ) ); ?>
							</a>
							<?php if ( '' !== $fh_item['summary'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( $fh_item['summary'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-inv-num"><?php echo esc_html( Lines::dollars( $fh_inv['total'] ) ); ?></td>
						<td>
							<?php echo esc_html( Settings::shipping_label( $fh_inv['shipping'] ) ); ?>
							<?php if ( 'other' === $fh_inv['shipping'] && '' !== $fh_inv['shipping_slug'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( $fh_inv['shipping_slug'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo Admin::badge( $fh_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
							<?php if ( 'paid' === $fh_state && $fh_eff['order'] ) : ?>
								<a class="fh-inv-sub" href="<?php echo esc_url( $fh_eff['order']['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number and status */ __( 'order %s', 'fishotel-misc-plugin' ), Rules::order_label( $fh_eff['order'] ) ) ); ?></a>
							<?php elseif ( 'published' === $fh_state && $fh_eff['live_orders'] ) : ?>
								<a class="fh-inv-sub fh-inv-warn" href="<?php echo esc_url( $fh_eff['live_orders'][0]['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number and status */ __( 'in order %s — not paid yet', 'fishotel-misc-plugin' ), Rules::order_label( $fh_eff['live_orders'][0] ) ) ); ?></a>
							<?php elseif ( ! $fh_eff['orders_known'] && ! in_array( $fh_state, array( 'draft', 'cancelled' ), true ) ) : ?>
								<span class="fh-inv-sub fh-inv-warn"><?php esc_html_e( 'the orders could not be read', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( Admin::site_time( $fh_inv['created_at'] ) ); ?>
							<?php if ( '' !== $fh_item['who'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( $fh_item['who'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-inv-actions">
							<?php if ( 'draft' === $fh_state ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Admin::page_url( 'edit', array( 'id' => $fh_inv['id'] ) ) ); ?>"><?php esc_html_e( 'Edit draft', 'fishotel-misc-plugin' ); ?></a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( $fh_view ); ?>"><?php esc_html_e( 'View', 'fishotel-misc-plugin' ); ?></a>
							<?php endif; ?>
							<?php if ( $fh_listed ) : ?>
								<button type="button" class="button button-small fh-inv-copy" data-copy="<?php echo esc_attr( $fh_link ); ?>"><?php esc_html_e( 'Copy link', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
							<a class="button button-small" href="<?php echo esc_url( Admin::page_url( 'new', array( 'from' => $fh_inv['id'] ) ) ); ?>" title="<?php esc_attr_e( 'Starts a NEW invoice for this customer — an invoice a customer already has is never added to.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Add fish for this customer', 'fishotel-misc-plugin' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="fh-inv-below">
			<button type="button" class="button fh-inv-forum-picked" data-url="<?php echo esc_attr( Admin::page_url( 'forum', array( 'ids' => '__IDS__' ) ) ); ?>"><?php esc_html_e( 'Forum list of the ticked invoices', 'fishotel-misc-plugin' ); ?></button>
			<a class="button" href="<?php echo esc_url( Admin::page_url( 'forum' ) ); ?>"><?php esc_html_e( 'Everything published today', 'fishotel-misc-plugin' ); ?></a>
			<span class="fh-inv-status" aria-live="polite"></span>

			<?php if ( $data['pages'] > 1 ) : ?>
				<span class="fh-inv-pager">
					<?php if ( $data['paged'] > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( $fh_url( array( 'paged' => $data['paged'] - 1 ) ) ); ?>">&laquo;</a>
					<?php endif; ?>
					<span><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages */ __( '%1$d / %2$d', 'fishotel-misc-plugin' ), $data['paged'], $data['pages'] ) ); ?></span>
					<?php if ( $data['paged'] < $data['pages'] ) : ?>
						<a class="button" href="<?php echo esc_url( $fh_url( array( 'paged' => $data['paged'] + 1 ) ) ); ?>">&raquo;</a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h2 class="fh-inv-h2"><?php esc_html_e( 'Made by hand', 'fishotel-misc-plugin' ); ?></h2>
	<p class="fh-inv-footnote">
		<?php
		echo esc_html(
			$data['category']
				/* translators: %s: category name */
				? sprintf( __( 'Products of the "%s" category that were not built here. They are listed so they can be read — this section never changes a product it did not create.', 'fishotel-misc-plugin' ), $data['category']['name'] )
				/* translators: %s: category slug */
				: sprintf( __( 'The product category "%s" does not exist, so there is nothing to list. Name the right slug in Settings.', 'fishotel-misc-plugin' ), $settings['category_slug'] )
		);
		?>
	</p>

	<?php if ( $data['hand'] ) : ?>
		<table class="widefat fh-inv-table fh-inv-hand">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Customer', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Invoice', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-inv-num"><?php esc_html_e( 'Price', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['hand'] as $fh_item ) : ?>
					<?php
					$fh_product = $fh_item['product'];
					$fh_paid    = $fh_item['paid'];
					?>
					<tr class="fh-inv-row fh-inv-row--hand">
						<td><strong><?php echo esc_html( '' !== $fh_item['handle'] ? $fh_item['handle'] : '—' ); ?></strong></td>
						<td>
							<a href="<?php echo esc_url( $fh_product['edit_url'] ); ?>"><?php echo esc_html( $fh_product['name'] ); ?></a>
							<?php if ( '' !== $fh_item['summary'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( $fh_item['summary'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-inv-num">
							<?php echo esc_html( '' !== $fh_product['regular_price'] ? Lines::dollars( (float) $fh_product['regular_price'] ) : '—' ); ?>
							<?php if ( '' !== $fh_product['sale_price'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( sprintf( /* translators: %s: sale price */ __( 'sale price %s', 'fishotel-misc-plugin' ), Lines::dollars( (float) $fh_product['sale_price'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo Admin::badge( 'hand', __( 'Made by hand', 'fishotel-misc-plugin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
							<?php if ( ! $fh_paid['known'] ) : ?>
								<span class="fh-inv-sub fh-inv-warn"><?php esc_html_e( 'paid or not: unknown (the orders could not be read)', 'fishotel-misc-plugin' ); ?></span>
							<?php elseif ( $fh_paid['paid'] ) : ?>
								<a class="fh-inv-sub" href="<?php echo esc_url( $fh_paid['order']['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number and status */ __( 'PAID on order %s', 'fishotel-misc-plugin' ), Rules::order_label( $fh_paid['order'] ) ) ); ?></a>
							<?php else : ?>
								<span class="fh-inv-sub"><?php esc_html_e( 'not paid', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
							<?php if ( 'publish' !== $fh_product['status'] ) : ?>
								<span class="fh-inv-sub"><?php echo esc_html( sprintf( /* translators: %s: product status */ __( 'product is %s', 'fishotel-misc-plugin' ), $fh_product['status'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-inv-actions">
							<?php if ( 'publish' === $fh_product['status'] ) : ?>
								<button type="button" class="button button-small fh-inv-copy" data-copy="<?php echo esc_attr( $fh_product['permalink'] ); ?>"><?php esc_html_e( 'Copy link', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php elseif ( $data['category'] ) : ?>
		<p class="fh-inv-footnote"><?php esc_html_e( 'None on this page.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<?php if ( $data['hpaged'] > 1 || $data['hand_more'] ) : ?>
		<p class="fh-inv-pager">
			<?php if ( $data['hpaged'] > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $fh_url( array( 'paged' => $data['paged'], 'hpaged' => $data['hpaged'] - 1 ) ) ); ?>">&laquo; <?php esc_html_e( 'Newer', 'fishotel-misc-plugin' ); ?></a>
			<?php endif; ?>
			<?php if ( $data['hand_more'] ) : ?>
				<a class="button" href="<?php echo esc_url( $fh_url( array( 'paged' => $data['paged'], 'hpaged' => $data['hpaged'] + 1 ) ) ); ?>"><?php esc_html_e( 'Older', 'fishotel-misc-plugin' ); ?> &raquo;</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
