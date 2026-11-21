<?php
/**
 * "Tidy up the sizes": the dry run — what each size becomes (the map,
 * with a choice per row), what that does to the products, and the Apply
 * box (Admin_Sizes_Tidy::plan_html()). Showing it writes nothing.
 *
 * @var array  $plan    Sizes_Tidy::plan().
 * @var string $confirm The word to type before Apply.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Admin_Sizes_Tidy;

$fh_totals = $plan['totals'];
$fh_labels = $plan['labels'];

// The variations behind each row, and the groups the preview calls out.
$fh_by_row  = array();
$fh_clean   = array();
$fh_off_out = array();
$fh_off_hid = array();
$fh_sale    = array();
$fh_dupes   = array();
foreach ( $plan['changes'] as $fh_change ) {
	$fh_key                 = 'convert' === $fh_change['why'] ? 't:' . Sizes::slug( $fh_change['from'] ) : 'g:' . $fh_change['from'];
	$fh_by_row[ $fh_key ][] = $fh_change;
	if ( in_array( $fh_change['why'], array( 'left', 'off_now', 'move' ), true ) ) {
		$fh_sale[] = $fh_change;
	} elseif ( 'off_out' === $fh_change['why'] && empty( $fh_change['out'] ) ) {
		// Not out of stock: nobody can buy it because its product is not on the shop (a draft), or it is switched off.
		$fh_off_hid[] = $fh_change;
	} elseif ( 'off_out' === $fh_change['why'] ) {
		$fh_off_out[] = $fh_change;
	} elseif ( 'dupe_off' === $fh_change['why'] ) {
		$fh_dupes[] = $fh_change;
	} else {
		$fh_clean[] = $fh_change;
	}
}

/**
 * Why nobody can buy a variation although it is not out of stock: its
 * product is not on the shop, or it is switched off itself.
 */
$fh_mark = static function ( array $change ) {
	$status = (string) ( $change['status'] ?? 'publish' );
	if ( 'draft' === $status ) {
		return __( 'a draft — not on the shop yet', 'fishotel-misc-plugin' );
	}
	if ( 'publish' !== $status ) {
		return __( 'its product is not published', 'fishotel-misc-plugin' );
	}
	if ( empty( $change['on'] ) ) {
		return '' === trim( (string) $change['price'] ) ? __( 'no price', 'fishotel-misc-plugin' ) : __( 'already switched off', 'fishotel-misc-plugin' );
	}
	return '';
};

/**
 * One changed variation, as a list item.
 */
$fh_item = static function ( array $change ) use ( $fh_mark ) {
	$title = '' !== (string) $change['title'] ? (string) $change['title'] : '#' . (int) $change['product'];
	$to    = '' !== (string) $change['to'] ? (string) $change['to_label'] : __( 'no size, switched off', 'fishotel-misc-plugin' );
	if ( $change['off'] && '' !== (string) $change['to'] ) {
		/* translators: %s: size label */
		$to = sprintf( __( '%s, switched off', 'fishotel-misc-plugin' ), (string) $change['to_label'] );
	}
	if ( 'left' === $change['why'] ) {
		$to = __( 'left as it is', 'fishotel-misc-plugin' );
	}
	if ( ! empty( $change['any'] ) && 'left' !== $change['why'] && 'move' !== $change['why'] ) {
		$to = $change['off'] ? __( 'off the product\'s list; the product lists no size any more, so this variation is switched off', 'fishotel-misc-plugin' ) : __( 'off the product\'s list', 'fishotel-misc-plugin' );
	}
	echo '<li><a href="' . esc_url( Admin_Sizes_Tidy::edit_url( $change['product'] ) ) . '">' . esc_html( $title ) . '</a> — ' . esc_html( (string) $change['from_label'] ) . ' → ' . esc_html( $to )
		. ' <span class="fh-cr-muted">· ' . esc_html( Admin_Sizes_Tidy::money( $change['price'] ) ) . ' · ' . esc_html( (string) $change['stock_text'] )
		. ( '' !== (string) $change['sku'] ? ' · ' . esc_html( sprintf( /* translators: %s: SKU */ __( 'SKU %s', 'fishotel-misc-plugin' ), (string) $change['sku'] ) ) : '' )
		. ( ! empty( $change['any'] ) ? ' · ' . esc_html__( 'sold as "any size"', 'fishotel-misc-plugin' ) : '' )
		. ( '' !== $fh_mark( $change ) ? ' · ' . esc_html( $fh_mark( $change ) ) : '' )
		. ( empty( $change['fish'] ) ? ' · ' . esc_html__( 'not in a fish category', 'fishotel-misc-plugin' ) : '' ) . '</span></li>';
};
?>
<input type="hidden" name="token" value="<?php echo esc_attr( $plan['token'] ); ?>">

<h3 class="fh-cr-h3"><?php esc_html_e( '2. What each size becomes', 'fishotel-misc-plugin' ); ?></h3>
<p class="fh-cr-sub">
	<?php
	/* translators: 1: sizes in the shop now, 2: sizes in the short list */
	echo esc_html( sprintf( __( 'The shop has %1$d sizes today; the short list has %2$d. Each of the others is suggested a place — change any row, then click "Show what would change" again.', 'fishotel-misc-plugin' ), (int) $fh_totals['sizes_before'], (int) $fh_totals['short'] ) );
	?>
</p>
<table class="widefat striped fh-cr-table fh-tidy__map">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'On the shop', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Also stored on', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Becomes →', 'fishotel-misc-plugin' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $plan['rows'] as $fh_row ) : ?>
			<?php
			$fh_stay  = Sizes_Tidy::STAY === $fh_row['action'];
			$fh_notes = array();
			foreach ( $fh_row['flags'] as $fh_flag ) {
				$fh_text = Admin_Sizes_Tidy::flag_text( $fh_flag );
				if ( '' !== $fh_text ) {
					$fh_notes[] = $fh_text;
				}
			}
			if ( isset( $fh_row['after'] ) ) {
				if ( 'deleted' === $fh_row['after'] ) {
					$fh_notes[] = __( 'then removed from the list', 'fishotel-misc-plugin' );
				} elseif ( 'retiring' === $fh_row['after'] ) {
					/* translators: %d: fish left */
					$fh_notes[] = sprintf( _n( 'stays for now: retiring — %d fish left', 'stays for now: retiring — %d fish left', (int) $fh_row['left'], 'fishotel-misc-plugin' ), (int) $fh_row['left'] );
				} elseif ( 'waiting' === $fh_row['after'] ) {
					/* translators: %d: products */
					$fh_notes[] = sprintf( _n( 'stays for now: %d product needs a look first', 'stays for now: %d products need a look first', max( 1, (int) ( $fh_row['stuck'] ?? 0 ) ), 'fishotel-misc-plugin' ), max( 1, (int) ( $fh_row['stuck'] ?? 0 ) ) );
				} elseif ( 'trash' === $fh_row['after'] ) {
					/* translators: %d: products */
					$fh_notes[] = sprintf( _n( 'stays in the list: %d product in the trash still uses it, and the trash is left alone. Delete that product for good (or restore it), then run this again', 'stays in the list: %d products in the trash still use it, and the trash is left alone. Delete those products for good (or restore them), then run this again', (int) ( $fh_row['trash'] ?? 1 ), 'fishotel-misc-plugin' ), (int) ( $fh_row['trash'] ?? 1 ) );
				}
			}
			if ( Sizes_Tidy::REMOVE === $fh_row['action'] && ! empty( $fh_row['stores']['invoice_lines'] ) ) {
				$fh_notes[] = __( 'a draft invoice that lists it keeps the size and the price on its line; only a fish of this size added to an invoice later finds no shop price for the size — type the price on the invoice', 'fishotel-misc-plugin' );
			}
			$fh_also = array();
			foreach ( $fh_row['stores'] as $fh_store => $fh_count ) {
				$fh_also[] = Admin_Sizes_Tidy::store_text( $fh_store, $fh_count );
			}
			if ( $fh_row['orders'] > 0 ) {
				/* translators: %d: count */
				$fh_also[] = sprintf( _n( '%d past order line', '%d past order lines', (int) $fh_row['orders'], 'fishotel-misc-plugin' ), (int) $fh_row['orders'] );
			}
			$fh_changes = isset( $fh_by_row[ $fh_row['key'] ] ) ? $fh_by_row[ $fh_row['key'] ] : array();
			?>
			<tr class="<?php echo esc_attr( $fh_stay ? 'fh-tidy__row fh-tidy__row--stay' : 'fh-tidy__row' ); ?>">
				<th scope="row">
					<?php echo esc_html( $fh_row['label'] ); ?>
					<?php if ( $fh_row['typed'] ) : ?>
						<span class="fh-cr-muted"><?php esc_html_e( '(typed on a product)', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
				</th>
				<td>
					<?php
					/* translators: %d: count */
					$fh_uses = sprintf( _n( '%d product', '%d products', (int) $fh_row['products'], 'fishotel-misc-plugin' ), (int) $fh_row['products'] )
						/* translators: %d: count */
						. ' · ' . sprintf( _n( '%d variation', '%d variations', (int) $fh_row['variations'], 'fishotel-misc-plugin' ), (int) $fh_row['variations'] );
					?>
					<?php if ( $fh_changes ) : ?>
						<details>
							<summary><?php echo esc_html( $fh_uses ); ?></summary>
							<ul class="fh-tidy__list"><?php array_map( $fh_item, $fh_changes ); ?></ul>
						</details>
					<?php else : ?>
						<?php echo esc_html( $fh_row['products'] > 0 || $fh_row['variations'] > 0 ? $fh_uses : __( 'not used', 'fishotel-misc-plugin' ) ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $fh_also ? implode( ' · ', $fh_also ) : '—' ); ?></td>
				<td>
					<?php if ( $fh_stay ) : ?>
						<?php esc_html_e( 'stays — it is in the short list', 'fishotel-misc-plugin' ); ?>
					<?php else : ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( 'fh-tidy-row-' . md5( $fh_row['key'] ) ); ?>">
							<?php
							/* translators: %s: size label */
							echo esc_html( sprintf( __( 'What %s becomes', 'fishotel-misc-plugin' ), $fh_row['label'] ) );
							?>
						</label>
						<select id="<?php echo esc_attr( 'fh-tidy-row-' . md5( $fh_row['key'] ) ); ?>" class="fh-cr-input fh-tidy__becomes" data-key="<?php echo esc_attr( $fh_row['key'] ); ?>" data-shown="<?php echo esc_attr( $fh_row['action'] ); ?>" data-yours="<?php echo esc_attr( ! empty( $fh_row['explicit'] ) ? '1' : '0' ); ?>" data-plus="<?php echo esc_attr( 'plus' === $fh_row['kind'] && ! $fh_row['typed'] ? '1' : '0' ); ?>">
							<?php foreach ( $plan['short'] as $fh_size ) : ?>
								<?php if ( ! $fh_row['typed'] && $fh_size['slug'] === $fh_row['slug'] ) { continue; } ?>
								<option value="<?php echo esc_attr( Sizes_Tidy::TO . $fh_size['slug'] ); ?>" <?php selected( $fh_row['action'], Sizes_Tidy::TO . $fh_size['slug'] ); ?>><?php echo esc_html( $fh_size['label'] ); ?></option>
							<?php endforeach; ?>
							<option value="<?php echo esc_attr( Sizes_Tidy::KEEP ); ?>" <?php selected( $fh_row['action'], Sizes_Tidy::KEEP ); ?>><?php esc_html_e( 'keep as it is', 'fishotel-misc-plugin' ); ?></option>
							<?php if ( ! $fh_row['typed'] ) : ?>
								<option value="<?php echo esc_attr( Sizes_Tidy::REMOVE ); ?>" <?php selected( $fh_row['action'], Sizes_Tidy::REMOVE ); ?>><?php esc_html_e( 'take off the products', 'fishotel-misc-plugin' ); ?></option>
							<?php endif; ?>
						</select>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( implode( '; ', $fh_notes ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p>
	<label><input type="checkbox" name="keep_plus" value="1" class="fh-tidy__keep-plus" <?php checked( ! empty( $plan['keep_plus'] ) ); ?>> <?php esc_html_e( 'Keep every "and up" size (3"+, 4"+ …) as it is instead', 'fishotel-misc-plugin' ); ?></label>
</p>
<p class="fh-cr-sub"><?php esc_html_e( '"Take off the products" never deletes anything you sell: the variation is switched off (not for sale, hidden from the fish page) and keeps its price, stock, SKU and number — it only loses the size. Undo puts all of it back.', 'fishotel-misc-plugin' ); ?></p>

<h3 class="fh-cr-h3"><?php esc_html_e( '3. What would change', 'fishotel-misc-plugin' ); ?></h3>

<?php if ( $fh_sale ) : ?>
	<div class="fh-tidy__box fh-tidy__box--warn">
		<h4 class="fh-tidy__h4">
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d fish for sale today carries a size that is being taken off', '%d fish for sale today carry a size that is being taken off', count( $fh_sale ), 'fishotel-misc-plugin' ), count( $fh_sale ) ) );
			?>
		</h4>
		<p class="fh-cr-sub"><?php esc_html_e( 'In stock or on backorder right now. What should happen to them?', 'fishotel-misc-plugin' ); ?></p>
		<p class="fh-tidy__choices">
			<label><input type="radio" name="instock" value="<?php echo esc_attr( Sizes_Tidy::IN_STOCK_LEAVE ); ?>" <?php checked( $plan['instock'], Sizes_Tidy::IN_STOCK_LEAVE ); ?>> <strong><?php esc_html_e( 'Leave them until they sell out', 'fishotel-misc-plugin' ); ?></strong> — <?php esc_html_e( 'they keep their size, and that size stays in the shop marked "retiring" until nothing in stock carries it. Nothing happens by itself: run this tool again later, or press "Finish retiring".', 'fishotel-misc-plugin' ); ?></label><br>
			<label><input type="radio" name="instock" value="<?php echo esc_attr( Sizes_Tidy::IN_STOCK_MOVE ); ?>" <?php checked( $plan['instock'], Sizes_Tidy::IN_STOCK_MOVE ); ?>> <strong><?php esc_html_e( 'Move them to a size I pick', 'fishotel-misc-plugin' ); ?></strong> — <?php esc_html_e( 'pick one per fish below; a fish without a pick is left as it is.', 'fishotel-misc-plugin' ); ?></label><br>
			<label><input type="radio" name="instock" value="<?php echo esc_attr( Sizes_Tidy::IN_STOCK_OFF ); ?>" <?php checked( $plan['instock'], Sizes_Tidy::IN_STOCK_OFF ); ?>> <strong><?php esc_html_e( 'Take them off the shop now', 'fishotel-misc-plugin' ); ?></strong> — <?php esc_html_e( 'switched off like the others; their stock is listed here so you can enter it again.', 'fishotel-misc-plugin' ); ?></label>
		</p>
		<table class="widefat striped fh-cr-table fh-tidy__sale">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stock', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happens', 'fishotel-misc-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $fh_sale as $fh_change ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( Admin_Sizes_Tidy::edit_url( $fh_change['product'] ) ); ?>"><?php echo esc_html( '' !== $fh_change['title'] ? $fh_change['title'] : '#' . $fh_change['product'] ); ?></a></td>
						<td>
							<?php echo esc_html( $fh_change['from_label'] ); ?>
							<?php if ( ! empty( $fh_change['any'] ) ) : ?>
								<span class="fh-cr-muted"><?php esc_html_e( '— sold as "any size": the customer picks a size from the product\'s list', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Admin_Sizes_Tidy::money( $fh_change['price'] ) ); ?></td>
						<td><?php echo esc_html( $fh_change['stock_text'] ); ?></td>
						<td>
							<?php if ( Sizes_Tidy::IN_STOCK_MOVE === $plan['instock'] ) : ?>
								<label class="screen-reader-text" for="<?php echo esc_attr( 'fh-tidy-move-' . $fh_change['id'] ); ?>"><?php esc_html_e( 'Move to', 'fishotel-misc-plugin' ); ?></label>
								<select id="<?php echo esc_attr( 'fh-tidy-move-' . $fh_change['id'] ); ?>" class="fh-cr-input fh-tidy__move" name="<?php echo esc_attr( 'move[' . $fh_change['id'] . ']' ); ?>">
									<option value=""><?php esc_html_e( '— leave as it is —', 'fishotel-misc-plugin' ); ?></option>
									<?php foreach ( $plan['short'] as $fh_size ) : ?>
										<option value="<?php echo esc_attr( $fh_size['slug'] ); ?>" <?php selected( 'move' === $fh_change['why'] ? $fh_change['to'] : '', $fh_size['slug'] ); ?>><?php echo esc_html( $fh_size['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'off_now' === $fh_change['why'] && ! empty( $fh_change['any'] ) && empty( $fh_change['off'] ) ) : ?>
								<?php esc_html_e( 'the size comes off the product\'s list now; the fish stays for sale in its other sizes', 'fishotel-misc-plugin' ); ?>
							<?php elseif ( 'off_now' === $fh_change['why'] ) : ?>
								<?php esc_html_e( 'taken off the shop now', 'fishotel-misc-plugin' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'left for sale as it is', 'fishotel-misc-plugin' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<details class="fh-tidy__group" <?php echo count( $fh_clean ) > 0 && count( $fh_clean ) <= 12 ? 'open' : ''; ?>>
	<summary>
		<strong><?php esc_html_e( 'Clean', 'fishotel-misc-plugin' ); ?></strong> —
		<?php
		/* translators: %d: count */
		echo esc_html( sprintf( _n( '%d variation only changes its size name', '%d variations only change their size name', count( $fh_clean ), 'fishotel-misc-plugin' ), count( $fh_clean ) ) );
		?>
	</summary>
	<ul class="fh-tidy__list"><?php array_map( $fh_item, $fh_clean ); ?></ul>
</details>

<?php if ( $fh_off_out ) : ?>
	<details class="fh-tidy__group">
		<summary>
			<strong><?php esc_html_e( 'Taken off now', 'fishotel-misc-plugin' ); ?></strong> —
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d variation that is out of stock — nobody can buy it anyway — is switched off and loses its size', '%d variations that are out of stock — nobody can buy them anyway — are switched off and lose their size', count( $fh_off_out ), 'fishotel-misc-plugin' ), count( $fh_off_out ) ) );
			?>
		</summary>
		<p class="fh-cr-sub"><?php esc_html_e( 'Checked again at the moment each one is written: a fish that is back in stock by then is left for sale as it is, and named at the end.', 'fishotel-misc-plugin' ); ?></p>
		<ul class="fh-tidy__list"><?php array_map( $fh_item, $fh_off_out ); ?></ul>
	</details>
<?php endif; ?>

<?php if ( $fh_off_hid ) : ?>
	<div class="fh-tidy__box">
		<h4 class="fh-tidy__h4">
			<strong><?php esc_html_e( 'Taken off now — not on the shop, but with stock', 'fishotel-misc-plugin' ); ?></strong> —
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d variation', '%d variations', count( $fh_off_hid ), 'fishotel-misc-plugin' ), count( $fh_off_hid ) ) );
			?>
		</h4>
		<p class="fh-cr-sub"><?php esc_html_e( 'Nobody can buy these today — the fish is a draft, or the variation is switched off or has no price — so they are switched off and lose their size like the ones above. But they are not sold out: their stock is kept and shown here, and each fish goes on the "Sizes to fill in" list. To leave one alone, set its size\'s row to "keep as it is".', 'fishotel-misc-plugin' ); ?></p>
		<ul class="fh-tidy__list"><?php array_map( $fh_item, $fh_off_hid ); ?></ul>
	</div>
<?php endif; ?>

<?php if ( $plan['two'] ) : ?>
	<div class="fh-tidy__box">
		<h4 class="fh-tidy__h4">
			<strong><?php esc_html_e( 'Two become one', 'fishotel-misc-plugin' ); ?></strong> —
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d product needs a look', '%d products need a look', (int) $fh_totals['look'], 'fishotel-misc-plugin' ), (int) $fh_totals['look'] ) );
			?>
		</h4>
		<p class="fh-cr-sub"><?php esc_html_e( 'Two (or more) variations of one fish would end up with the same size. The tool never merges or deletes variations: such a fish is left exactly as it is, unless you pick the one variation to keep. The others then get the same size and are switched off — kept, with their stock shown so you can move it by hand.', 'fishotel-misc-plugin' ); ?></p>
		<?php foreach ( $plan['two'] as $fh_product ) : ?>
			<div class="fh-tidy__two">
				<p><a href="<?php echo esc_url( Admin_Sizes_Tidy::edit_url( $fh_product['id'] ) ); ?>"><strong><?php echo esc_html( '' !== $fh_product['title'] ? $fh_product['title'] : '#' . $fh_product['id'] ); ?></strong></a></p>
				<?php foreach ( $fh_product['groups'] as $fh_gkey => $fh_group ) : ?>
					<?php
					$fh_name   = 'keep_one[' . $fh_product['id'] . '][' . $fh_gkey . ']';
					$fh_picked = 0;
					foreach ( $fh_group['members'] as $fh_member ) {
						$fh_picked = $fh_member['picked'] ? (int) $fh_member['id'] : $fh_picked;
					}
					?>
					<fieldset class="fh-tidy__pick">
						<legend>
							<?php
							/* translators: %s: size label */
							echo esc_html( sprintf( __( 'These would all be %s. Keep which one?', 'fishotel-misc-plugin' ), $fh_group['to_label'] ) );
							?>
						</legend>
						<label><input type="radio" name="<?php echo esc_attr( $fh_name ); ?>" value="0" <?php checked( 0, $fh_picked ); ?>> <?php esc_html_e( 'Leave this fish alone for now', 'fishotel-misc-plugin' ); ?></label><br>
						<?php foreach ( $fh_group['members'] as $fh_member ) : ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( $fh_name ); ?>" value="<?php echo esc_attr( (string) $fh_member['id'] ); ?>" <?php checked( (int) $fh_member['id'], $fh_picked ); ?>>
								<?php
								/* translators: 1: size label, 2: price, 3: stock, 4: SKU or a dash, 5: on / switched off */
								echo esc_html( sprintf( __( 'Keep the one that is %1$s today — %2$s · %3$s · SKU %4$s%5$s', 'fishotel-misc-plugin' ), $fh_member['from_label'], Admin_Sizes_Tidy::money( $fh_member['price'] ), $fh_member['stock_text'], '' !== $fh_member['sku'] ? $fh_member['sku'] : '—', $fh_member['on'] ? '' : ' · ' . __( 'already switched off', 'fishotel-misc-plugin' ) ) );
								?>
							</label><br>
						<?php endforeach; ?>
					</fieldset>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php if ( $fh_dupes ) : ?>
			<p class="fh-cr-sub"><?php esc_html_e( 'Switched off, kept — move their stock by hand:', 'fishotel-misc-plugin' ); ?></p>
			<ul class="fh-tidy__list"><?php array_map( $fh_item, $fh_dupes ); ?></ul>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $plan['typed'] ) : ?>
	<div class="fh-tidy__box">
		<h4 class="fh-tidy__h4">
			<strong><?php esc_html_e( 'Typed on the product', 'fishotel-misc-plugin' ); ?></strong> —
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d fish has its sizes typed on the product instead of picked from the list', '%d fish have their sizes typed on the product instead of picked from the list', count( $plan['typed'] ), 'fishotel-misc-plugin' ), count( $plan['typed'] ) ) );
			?>
		</h4>
		<p class="fh-cr-sub"><?php esc_html_e( 'A typed size is made a size of the list only for the fish you tick.', 'fishotel-misc-plugin' ); ?></p>
		<ul class="fh-tidy__list fh-tidy__typed">
			<?php foreach ( $plan['typed'] as $fh_product ) : ?>
				<?php
				$fh_reads = array();
				foreach ( $fh_product['values'] as $fh_value ) {
					$fh_reads[] = $fh_value['value'] . ' → ' . ( null !== $fh_value['to'] ? $fh_value['to_label'] : __( 'no size picked', 'fishotel-misc-plugin' ) );
				}
				$fh_why = '';
				if ( 'nosize' === $fh_product['why'] ) {
					$fh_why = __( 'cannot be converted yet: pick a size for each of its typed sizes in the table above', 'fishotel-misc-plugin' );
				} elseif ( 'both' === $fh_product['why'] ) {
					$fh_why = __( 'cannot be converted: it has sizes of the list as well — sort it out on the product', 'fishotel-misc-plugin' );
				} elseif ( 'two' === $fh_product['why'] ) {
					$fh_why = __( 'cannot be converted: two of its sizes would become the same — sort it out on the product', 'fishotel-misc-plugin' );
				}
				?>
				<li>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( 'convert[' . $fh_product['id'] . ']' ); ?>" value="1" <?php checked( $fh_product['ticked'] && $fh_product['ok'] ); ?> <?php disabled( ! $fh_product['ok'] ); ?>>
						<a href="<?php echo esc_url( Admin_Sizes_Tidy::edit_url( $fh_product['id'] ) ); ?>"><?php echo esc_html( '' !== $fh_product['title'] ? $fh_product['title'] : '#' . $fh_product['id'] ); ?></a>
					</label>
					— <?php echo esc_html( implode( ' · ', $fh_reads ) ); ?>
					<?php if ( '' !== $fh_why ) : ?>
						<span class="fh-cr-warn"><?php echo esc_html( $fh_why ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $plan['unavailable'] ) : ?>
	<div class="fh-tidy__box fh-tidy__box--warn">
		<h4 class="fh-tidy__h4">
			<?php
			/* translators: %d: count */
			echo esc_html( sprintf( _n( '%d fish will show as unavailable until you add a size', '%d fish will show as unavailable until you add a size', count( $plan['unavailable'] ), 'fishotel-misc-plugin' ), count( $plan['unavailable'] ) ) );
			?>
		</h4>
		<p class="fh-cr-sub"><?php esc_html_e( 'Every size they sell is being taken off. On the fish page they then read "Currently unavailable — contact us", with no price and no "Notify me" button, until a size is added. The products themselves are not hidden, trashed or changed in any other way; they go on the "Sizes to fill in" list.', 'fishotel-misc-plugin' ); ?></p>
		<ul class="fh-tidy__list">
			<?php foreach ( $plan['unavailable'] as $fh_product ) : ?>
				<li><a href="<?php echo esc_url( Admin_Sizes_Tidy::edit_url( $fh_product['id'] ) ); ?>"><?php echo esc_html( '' !== $fh_product['title'] ? $fh_product['title'] : '#' . $fh_product['id'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $plan['packing']['clashes'] ) : ?>
	<div class="fh-tidy__box">
		<h4 class="fh-tidy__h4"><?php esc_html_e( 'Packing: two sizes with different bags become one', 'fishotel-misc-plugin' ); ?></h4>
		<ul class="fh-tidy__list">
			<?php foreach ( $plan['packing']['clashes'] as $fh_clash ) : ?>
				<li>
					<?php
					/* translators: 1: old size, 2: new size, 3: bag kept, 4: bag dropped */
					echo esc_html( sprintf( __( '%1$s becomes %2$s, which already has the bag "%3$s" — that one is kept ("%4$s" is dropped). Check Packing → Sizes afterwards.', 'fishotel-misc-plugin' ), isset( $fh_labels[ $fh_clash['from'] ] ) ? $fh_labels[ $fh_clash['from'] ] : $fh_clash['from'], isset( $fh_labels[ $fh_clash['to'] ] ) ? $fh_labels[ $fh_clash['to'] ] : $fh_clash['to'], $fh_clash['kept'], $fh_clash['dropped'] ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $plan['create'] ) : ?>
	<p>
		<?php
		/* translators: %s: size labels */
		echo esc_html( sprintf( __( 'New sizes that will be created: %s.', 'fishotel-misc-plugin' ), implode( ', ', array_column( $plan['create'], 'label' ) ) ) );
		?>
	</p>
<?php endif; ?>

<ul class="fh-tidy__list fh-tidy__small">
	<li><?php esc_html_e( 'The Clean Room\'s stays and supplier order lines, fish requests, lost-fish cases, draft invoices, box shares and the Packing size map follow a size that BECOMES another. A size that is taken off stays on the fish in the tanks as it is — it shows as "not a standard size".', 'fishotel-misc-plugin' ); ?></li>
	<li><?php esc_html_e( 'Past orders are not touched: an order line keeps the size text it was bought with. Open baskets are not touched either — but a fish that is taken off the shop drops out of a basket it was in, as any fish that is no longer for sale does.', 'fishotel-misc-plugin' ); ?></li>
	<li><?php esc_html_e( 'An old size is removed from the list only once no product and no variation uses it any more — a product in the trash counts: the trash is left alone.', 'fishotel-misc-plugin' ); ?></li>
</ul>

<p class="fh-tidy__totals"><strong><?php echo esc_html( Admin_Sizes_Tidy::totals_text( $fh_totals ) ); ?></strong></p>

<?php if ( ! empty( $plan['nothing'] ) ) : ?>
	<p class="fh-cr-muted"><?php esc_html_e( 'There is nothing to tidy with these choices.', 'fishotel-misc-plugin' ); ?></p>
<?php else : ?>
	<div class="fh-tidy__box fh-tidy__apply">
		<h3 class="fh-cr-h3"><?php esc_html_e( '4. Tidy up', 'fishotel-misc-plugin' ); ?></h3>
		<p><label><input type="checkbox" name="backup" value="1" class="fh-tidy__backup"> <?php esc_html_e( 'I have a backup of the site', 'fishotel-misc-plugin' ); ?></label></p>
		<p>
			<label for="fh-tidy-confirm">
				<?php
				/* translators: %s: the word to type */
				echo esc_html( sprintf( __( 'Type %s to go ahead:', 'fishotel-misc-plugin' ), $confirm ) );
				?>
			</label>
			<input type="text" id="fh-tidy-confirm" class="fh-cr-input fh-tidy__confirm" name="confirm" autocomplete="off" size="8">
		</p>
		<p class="fh-tidy__message fh-tidy__message--bad fh-tidy__apply-message" role="alert" hidden></p>
		<p class="fh-cr-actions-row">
			<button type="button" class="button button-primary fh-tidy__apply-button"><?php esc_html_e( 'Tidy up the sizes now', 'fishotel-misc-plugin' ); ?></button>
			<button type="button" class="button fh-tidy__preview fh-tidy__preview--again"><?php esc_html_e( 'Show what would change', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-cr-muted"><?php esc_html_e( 'It works through the shop a few fish at a time and shows how far it is. If the page stops, "Carry on" picks it up where it stopped — with these choices, whatever the page shows by then. Afterwards "Undo the last tidy-up" puts everything back.', 'fishotel-misc-plugin' ); ?></span>
		</p>
	</div>
<?php endif; ?>
