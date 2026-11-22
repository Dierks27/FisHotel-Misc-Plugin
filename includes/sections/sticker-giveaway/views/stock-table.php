<?php
/**
 * Sticker Giveaway settings — the stock table: one row per design.
 *
 * Each row is a small card rather than a table row, so the page reads on
 * a phone without sideways scrolling: every number carries its own label.
 * What the three number boxes do is said once, above the rows, not on
 * every card.
 * The number shown is the product's own WooCommerce stock. Designs of a
 * product that keeps ONE stock number for all its variations share a row.
 *
 * Posted with the page's Save: `stock_listed[]` (the rows on the page) and
 * `reorder_at[id]`. The two number boxes beside "Add stickers" and
 * "Recount" have no name on purpose: they are not settings and are never
 * posted with Save — their button sends them, at once, to WooCommerce.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var array[] $stock_rows Reorder::rows().
 */

use FisHotel\Misc\Sections\Sticker_Giveaway\Rules;

defined( 'ABSPATH' ) || exit;

if ( ! $stock_rows ) :
	?>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'No designs yet. Pick your sticker product above and save — its designs get a row each here.', 'fishotel-misc-plugin' ); ?></p>
	<?php
	return;
endif;

$fh_sg_status = array(
	Rules::STATUS_OK      => __( 'OK', 'fishotel-misc-plugin' ),
	Rules::STATUS_REORDER => __( 'Reorder', 'fishotel-misc-plugin' ),
	Rules::STATUS_OUT     => __( 'Out', 'fishotel-misc-plugin' ),
);
?>
<p class="fh-sg-hint"><?php esc_html_e( 'Reorder at: you are reminded when the stock drops to this number. Blank = no reminder. Saved with Save Settings.', 'fishotel-misc-plugin' ); ?></p>
<p class="fh-sg-hint"><?php esc_html_e( 'Add stickers adds what came in a new box; Recount replaces the stock number with what you counted by hand. Both are done at once. Typed it wrong? Recount again.', 'fishotel-misc-plugin' ); ?></p>
<div class="fh-sg-stock">
	<?php
	foreach ( $stock_rows as $row ) :
		$fh_id      = (int) $row['id'];
		$fh_tracked = null !== $row['stock'];
		?>
	<section class="fh-sg-stock__row fh-sg-stock__row--<?php echo esc_attr( $fh_tracked ? $row['status'] : 'off' ); ?>" data-count="<?php echo esc_attr( $fh_id ); ?>">
		<input type="hidden" name="stock_listed[]" value="<?php echo esc_attr( $fh_id ); ?>">

		<header class="fh-sg-stock__head">
			<span class="fh-sg-stock__thumb"><?php echo wp_kses_post( $row['image'] ); ?></span>
			<h3 class="fh-sg-stock__name"><?php echo esc_html( $row['name'] ); ?></h3>
			<?php if ( $fh_tracked ) : ?>
				<span class="fh-sg-tag fh-sg-tag--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $fh_sg_status[ $row['status'] ] ); ?></span>
			<?php endif; ?>
		</header>

		<?php if ( array( $fh_id ) !== array_keys( $row['designs'] ) ) : ?>
			<?php /* translators: %s: design names */ ?>
			<p class="fh-sg-hint"><?php echo esc_html( sprintf( __( 'One stock number for all of: %s. This product keeps the stock for its variations, so they share this row.', 'fishotel-misc-plugin' ), implode( ', ', $row['designs'] ) ) ); ?></p>
		<?php endif; ?>

		<p class="fh-sg-stock__line">
			<?php if ( $fh_tracked ) : ?>
				<?php /* translators: %d: stickers in stock */ ?>
				<?php echo esc_html( sprintf( __( 'In stock: %d', 'fishotel-misc-plugin' ), (int) $row['stock'] ) ); ?>
			<?php else : ?>
				<?php esc_html_e( 'In stock: not tracked', 'fishotel-misc-plugin' ); ?>
			<?php endif; ?>
		</p>
		<?php if ( ! $fh_tracked ) : ?>
			<p class="fh-sg-hint"><?php esc_html_e( 'WooCommerce does not count this product, so there is no reorder reminder. Tick "Manage stock" on the product page to count it.', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>

		<div class="fh-sg-stock__act">
			<label class="fh-sg-label" for="fh-sg-reorder-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'Reorder at', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-sg-reorder-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count" name="reorder_at[<?php echo esc_attr( $fh_id ); ?>]" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr( null === $row['reorder_at'] ? '' : (int) $row['reorder_at'] ); ?>">
		</div>

		<?php if ( $fh_tracked ) : ?>
		<div class="fh-sg-stock__acts">
			<div class="fh-sg-stock__act">
				<label class="fh-sg-label" for="fh-sg-add-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'A new box arrived', 'fishotel-misc-plugin' ); ?></label>
				<span class="fh-sg-stock__go">
					<input type="number" id="fh-sg-add-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count fh-sg-stock-n" min="1" step="1" inputmode="numeric">
					<button type="button" class="button fh-sg-stock-btn" data-act="add"><?php esc_html_e( 'Add stickers', 'fishotel-misc-plugin' ); ?></button>
				</span>
			</div>
			<div class="fh-sg-stock__act">
				<label class="fh-sg-label" for="fh-sg-recount-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'I counted them', 'fishotel-misc-plugin' ); ?></label>
				<span class="fh-sg-stock__go">
					<input type="number" id="fh-sg-recount-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count fh-sg-stock-n" min="0" step="1" inputmode="numeric">
					<button type="button" class="button fh-sg-stock-btn" data-act="recount"><?php esc_html_e( 'Recount', 'fishotel-misc-plugin' ); ?></button>
				</span>
			</div>
		</div>
		<?php endif; ?>
	</section>
	<?php endforeach; ?>
</div>
