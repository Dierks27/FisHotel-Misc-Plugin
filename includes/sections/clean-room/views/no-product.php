<?php
/**
 * Species tab → "Fish with no product yet": one row per fish that is on
 * a fish order or in a tank and is not linked to a product of the shop,
 * with the two ways to give it one — and, in a group of their own, the
 * "??" fish, which need a name first.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var array   $data         Admin_No_Product::data().
 * @var array[] $sizes        The standard sizes (for the Identify panel).
 * @var bool    $can_draft    Whether this user may make products.
 * @var string  $flash        One-time notice.
 * @var bool    $tables_ok    Whether the tables exist.
 * @var bool    $can_settings Whether the Settings tab is offered.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin;
use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Costing;

$tab             = 'species';
$fh_species_view = 'no-product';
$fh_rows         = (array) $data['rows'];
$fh_unknown      = (array) $data['unknown'];
$fh_order        = is_array( $data['order'] ) ? $data['order'] : null;
$fh_closed       = ! empty( $data['closed_too'] );
$fh_keep         = array_filter(
	array(
		'closed' => $fh_closed ? '1' : '',
		'order'  => $fh_order ? (int) $fh_order['id'] : '',
	)
);
?>
<div class="wrap fishotel-misc-wrap fh-cr-wrap fh-cr-np">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Clean Room', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<?php include Clean_Room::path() . 'views/tabs.php'; ?>
	<?php include Clean_Room::path() . 'views/species-nav.php'; ?>

	<?php if ( ! $tables_ok ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'The Clean Room tables do not exist. Deactivate and reactivate the plugin to create them.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<div class="fh-cr-np__head">
		<h2 class="fh-cr-np__title"><?php esc_html_e( 'Fish with no product yet', 'fishotel-misc-plugin' ); ?></h2>
		<p class="fh-cr-np__intro">
			<?php
			if ( $fh_order ) {
				echo esc_html( sprintf( /* translators: %s: order ref */ __( 'Only the fish of fish order %s that are not linked to a product of the shop.', 'fishotel-misc-plugin' ), (string) $fh_order['ref'] ) );
			} elseif ( $fh_closed ) {
				esc_html_e( 'Every fish that is not linked to a product of the shop — the fish of closed fish orders too.', 'fishotel-misc-plugin' );
			} else {
				esc_html_e( 'The fish that are on a fish order that is not closed, or alive in a tank, and are not linked to a product of the shop. Link each one to its product, or start a draft product for it.', 'fishotel-misc-plugin' );
			}
			?>
		</p>
		<p class="fh-cr-np__switch">
			<?php if ( $fh_order ) : ?>
				<a class="button" href="<?php echo esc_url( Admin_No_Product::url() ); ?>"><?php esc_html_e( 'Show the fish of every order', 'fishotel-misc-plugin' ); ?></a>
				<a class="button" href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_order['id'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order ref */ __( 'Back to fish order %s', 'fishotel-misc-plugin' ), (string) $fh_order['ref'] ) ); ?></a>
			<?php elseif ( $fh_closed ) : ?>
				<a class="button" href="<?php echo esc_url( Admin_No_Product::url() ); ?>"><?php esc_html_e( 'Only the fish that matter now', 'fishotel-misc-plugin' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( Admin_No_Product::url( array( 'closed' => '1' ) ) ); ?>"><?php esc_html_e( 'Show the fish of closed fish orders too', 'fishotel-misc-plugin' ); ?></a>
			<?php endif; ?>
			<span class="fh-cr-status" aria-live="polite"></span>
		</p>
	</div>

	<p class="fh-cr-count">
		<?php echo esc_html( sprintf( /* translators: 1: fish, 2: page, 3: pages */ _n( '%1$d fish with no product yet · page %2$d of %3$d', '%1$d fish with no product yet · page %2$d of %3$d', (int) $data['total'], 'fishotel-misc-plugin' ), (int) $data['total'], (int) $data['paged'], (int) $data['pages'] ) ); ?>
	</p>

	<?php if ( ! $fh_rows ) : ?>
		<div class="fishotel-misc-empty"><span class="dashicons dashicons-yes-alt"></span><p>
			<?php echo esc_html( $fh_closed || $fh_order ? __( 'Every fish here is linked to a product.', 'fishotel-misc-plugin' ) : __( 'Every fish on an open fish order or in a tank is linked to a product. Nothing to do here.', 'fishotel-misc-plugin' ) ); ?>
		</p></div>
	<?php else : ?>
		<table class="widefat striped fh-cr-table fh-cr-np-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where it is', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cost and price', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Give it a product', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fh_rows as $fh_fish ) : ?>
					<?php
					$fh_candidate = (int) ( $fh_fish['candidate'] ?? 0 );
					$fh_title     = $fh_candidate > 0 && isset( $data['titles'][ $fh_candidate ] ) ? (string) $data['titles'][ $fh_candidate ] : '';
					?>
					<tr class="fh-cr-np-row<?php echo ! empty( $fh_fish['in_tank'] ) ? ' is-in-tank' : ''; ?>" data-key="<?php echo esc_attr( $fh_fish['key'] ); ?>" data-name="<?php echo esc_attr( $fh_fish['name'] ); ?>">
						<td class="fh-cr-np-fish" data-label="<?php esc_attr_e( 'Fish', 'fishotel-misc-plugin' ); ?>">
							<strong><?php echo esc_html( $fh_fish['name'] ); ?></strong>
							<?php if ( '' !== $fh_fish['sci_name'] ) : ?>
								<span class="fh-cr-sub"><em><?php echo esc_html( $fh_fish['sci_name'] ); ?></em></span>
							<?php else : ?>
								<span class="fh-cr-sub fh-cr-muted"><?php esc_html_e( 'scientific name not known', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
							<span class="fh-cr-sub">
								<?php
								echo esc_html(
									$fh_fish['sizes']
										/* translators: %s: sizes */
										? sprintf( __( 'Sizes seen: %s', 'fishotel-misc-plugin' ), implode( ', ', array_column( $fh_fish['sizes'], 'label' ) ) )
										: __( 'no size noted', 'fishotel-misc-plugin' )
								);
								?>
							</span>
						</td>
						<td class="fh-cr-np-where" data-label="<?php esc_attr_e( 'Where it is', 'fishotel-misc-plugin' ); ?>">
							<?php if ( $fh_fish['orders'] ) : ?>
								<span class="fh-cr-np-line">
									<?php esc_html_e( 'Fish order', 'fishotel-misc-plugin' ); ?>
									<?php foreach ( $fh_fish['orders'] as $fh_n => $fh_o ) : ?>
										<?php echo $fh_n > 0 ? ', ' : ''; ?><a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_o['id'] ) ); ?>"><?php echo esc_html( '' !== $fh_o['ref'] ? $fh_o['ref'] : '#' . (int) $fh_o['id'] ); ?></a><?php if ( '' !== $fh_o['status'] ) : ?> <span class="fh-cr-muted">(<?php echo esc_html( $fh_o['status'] ); ?>)</span><?php endif; ?>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
							<?php if ( ! empty( $fh_fish['in_tank'] ) ) : ?>
								<span class="fh-cr-np-line">
									<a href="<?php echo esc_url( Admin::page_url( 'stays', array( 's' => $fh_fish['name'] ) ) ); ?>">
										<?php
										echo esc_html(
											$fh_fish['tanks']
												/* translators: 1: tank numbers, 2: fish alive */
												? sprintf( _n( 'Tank %1$s · %2$d alive', 'Tanks %1$s · %2$d alive', count( $fh_fish['tanks'] ), 'fishotel-misc-plugin' ), implode( ', ', $fh_fish['tanks'] ), (int) $fh_fish['alive'] )
												/* translators: %d: fish alive */
												: sprintf( __( 'In a tank · %d alive', 'fishotel-misc-plugin' ), (int) $fh_fish['alive'] )
										);
										?>
									</a>
								</span>
							<?php else : ?>
								<span class="fh-cr-np-line fh-cr-muted"><?php esc_html_e( 'not in a tank', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-cr-np-money" data-label="<?php esc_attr_e( 'Cost and price', 'fishotel-misc-plugin' ); ?>">
							<?php if ( null !== $fh_fish['cost'] ) : ?>
								<span class="fh-cr-np-line"><?php echo esc_html( sprintf( /* translators: 1: money, 2: order ref */ '' !== $fh_fish['cost_ref'] ? __( 'Landed %1$s per fish (%2$s)', 'fishotel-misc-plugin' ) : __( 'Landed %1$s per fish', 'fishotel-misc-plugin' ), Costing::money( $fh_fish['cost'] ), $fh_fish['cost_ref'] ) ); ?></span>
							<?php else : ?>
								<span class="fh-cr-np-line fh-cr-muted"><?php esc_html_e( 'landed cost not known yet', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $fh_fish['target'] ) : ?>
								<span class="fh-cr-np-line" title="<?php esc_attr_e( 'The landed cost per fish times the fish order\'s markup, to the nearest 5 — the order page\'s target price.', 'fishotel-misc-plugin' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: money */ __( 'Target price %s', 'fishotel-misc-plugin' ), Costing::num( $fh_fish['target'], '—', 0 ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $fh_fish['planned'] ) : ?>
								<span class="fh-cr-np-line"><strong><?php echo esc_html( sprintf( /* translators: %s: money */ __( 'Planned price %s', 'fishotel-misc-plugin' ), Costing::money( $fh_fish['planned'] ) ) ); ?></strong></span>
							<?php endif; ?>
						</td>
						<td class="fh-cr-np-acts" data-label="<?php esc_attr_e( 'Give it a product', 'fishotel-misc-plugin' ); ?>">
							<?php if ( $fh_candidate > 0 ) : ?>
								<span class="fh-cr-np-line">
									<?php echo esc_html( sprintf( /* translators: %s: product title */ __( 'The shop has "%s" under this name.', 'fishotel-misc-plugin' ), '' !== $fh_title ? $fh_title : '#' . $fh_candidate ) ); ?>
								</span>
								<button type="button" class="button button-primary fh-cr-np-candidate" data-product="<?php echo esc_attr( $fh_candidate ); ?>"><?php esc_html_e( 'Link to it', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
							<button type="button" class="button<?php echo $fh_candidate > 0 ? '' : ' button-primary'; ?> fh-cr-np-link-open"><?php esc_html_e( 'Link to a product…', 'fishotel-misc-plugin' ); ?></button>
							<?php if ( $can_draft && 0 === $fh_candidate ) : ?>
								<button type="button" class="button fh-cr-np-draft" title="<?php esc_attr_e( 'Makes a product for this fish as a DRAFT — customers cannot see it — with its name, scientific name, the fish category and its sizes, and links the fish to it. You finish it in the shop: photo, description, price, Publish.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Start a draft product', 'fishotel-misc-plugin' ); ?></button>
							<?php endif; ?>
							<span class="fh-cr-suggest-wrap fh-cr-np-linkbox" hidden>
								<input type="text" class="fh-cr-input fh-cr-np-search" autocomplete="off" aria-label="<?php esc_attr_e( 'Search a product to link this fish to', 'fishotel-misc-plugin' ); ?>" placeholder="<?php esc_attr_e( 'type a product\'s name, then pick it', 'fishotel-misc-plugin' ); ?>">
							</span>
							<span class="fh-cr-np-result" aria-live="polite"></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( (int) $data['pages'] > 1 ) : ?>
			<p class="fh-cr-pager">
				<?php if ( (int) $data['paged'] > 1 ) : ?><a class="button" href="<?php echo esc_url( Admin_No_Product::url( $fh_keep + array( 'paged' => (int) $data['paged'] - 1 ) ) ); ?>">&laquo;</a><?php endif; ?>
				<span><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages */ __( '%1$d / %2$d', 'fishotel-misc-plugin' ), (int) $data['paged'], (int) $data['pages'] ) ); ?></span>
				<?php if ( (int) $data['paged'] < (int) $data['pages'] ) : ?><a class="button" href="<?php echo esc_url( Admin_No_Product::url( $fh_keep + array( 'paged' => (int) $data['paged'] + 1 ) ) ); ?>">&raquo;</a><?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $fh_unknown ) : ?>
		<h3 class="fh-cr-np__title fh-cr-np__title--sub"><?php echo esc_html( sprintf( /* translators: %d: fish */ __( 'Not identified yet (%d)', 'fishotel-misc-plugin' ), count( $fh_unknown ) ) ); ?></h3>
		<p class="fh-cr-np__intro"><?php esc_html_e( 'Nobody knows yet what these fish are (their name starts with ??). They need a name before they can have a product: identify them first.', 'fishotel-misc-plugin' ); ?></p>
		<table class="widefat striped fh-cr-table fh-cr-np-table fh-cr-np-table--unknown">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where it is', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fh_unknown as $fh_u ) : ?>
					<tr class="fh-cr-np-row is-unknown">
						<td data-label="<?php esc_attr_e( 'Fish', 'fishotel-misc-plugin' ); ?>">
							<strong><?php echo esc_html( $fh_u['name'] ); ?></strong>
							<?php if ( '' !== $fh_u['size_label'] ) : ?><span class="fh-cr-sub"><?php echo esc_html( $fh_u['size_label'] ); ?></span><?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Where it is', 'fishotel-misc-plugin' ); ?>">
							<?php if ( (int) $fh_u['order_id'] > 0 ) : ?>
								<span class="fh-cr-np-line"><?php esc_html_e( 'Fish order', 'fishotel-misc-plugin' ); ?> <a href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_u['order_id'] ) ); ?>"><?php echo esc_html( '' !== $fh_u['order_ref'] ? $fh_u['order_ref'] : '#' . (int) $fh_u['order_id'] ); ?></a></span>
							<?php endif; ?>
							<?php if ( 'stay' === $fh_u['kind'] ) : ?>
								<span class="fh-cr-np-line"><?php echo esc_html( null !== $fh_u['tank'] ? sprintf( /* translators: 1: tank number, 2: fish alive */ __( 'Tank %1$d · %2$d alive', 'fishotel-misc-plugin' ), (int) $fh_u['tank'], (int) $fh_u['alive'] ) : sprintf( /* translators: %d: fish alive */ __( 'In a tank · %d alive', 'fishotel-misc-plugin' ), (int) $fh_u['alive'] ) ); ?></span>
							<?php else : ?>
								<span class="fh-cr-np-line fh-cr-muted"><?php esc_html_e( 'not in a tank', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="fh-cr-np-acts" data-label="<?php esc_attr_e( 'Identify it', 'fishotel-misc-plugin' ); ?>">
							<?php if ( 'stay' === $fh_u['kind'] ) : ?>
								<button type="button" class="button fh-cr-identify-open" data-stay="<?php echo esc_attr( (int) $fh_u['id'] ); ?>" data-size="<?php echo esc_attr( $fh_u['size_slug'] ); ?>" data-size-label="<?php echo esc_attr( $fh_u['size_label'] ); ?>"><?php esc_html_e( 'Identify…', 'fishotel-misc-plugin' ); ?></button>
							<?php else : ?>
								<a class="fh-cr-np-go" href="<?php echo esc_url( Admin_Workbook::order_url( (int) $fh_u['order_id'] ) ); ?>"><?php esc_html_e( 'Identify it on its fish order →', 'fishotel-misc-plugin' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$fh_identify_colspan = 3;
		include Clean_Room::path() . 'views/identify-panel.php';
		?>
	<?php endif; ?>

	<p class="fh-cr-footnote">
		<?php esc_html_e( 'A fish has a product when its row on the species list is linked to one, or when exactly one fish product of the shop carries its name; a group in a tank has one when it is linked to it. A product in the trash does not count; a draft does — you are still working on it. "Link to a product…" ties the fish, every fish order line of it and its groups in the tanks to the product you pick. "Start a draft product" makes the product as a draft that customers cannot see, and never publishes it.', 'fishotel-misc-plugin' ); ?>
		<?php if ( ! $can_draft ) : ?>
			<?php esc_html_e( 'Your account may not make products, so only "Link to a product…" is offered.', 'fishotel-misc-plugin' ); ?>
		<?php endif; ?>
		<?php if ( ! empty( $data['orders_capped'] ) ) : ?>
			<?php echo esc_html( sprintf( /* translators: %d: orders */ __( 'Only the newest %d fish orders are read here.', 'fishotel-misc-plugin' ), (int) $data['orders_read'] ) ); ?>
		<?php endif; ?>
	</p>
</div>
