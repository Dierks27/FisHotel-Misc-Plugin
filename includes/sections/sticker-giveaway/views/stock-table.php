<?php
/**
 * Sticker Giveaway settings — the stock table: one row per design.
 *
 * Each row is a small card rather than a table row, so the page reads on
 * a phone without sideways scrolling: every number carries its own label.
 *
 * Posted with the page's Save: `stock_listed[]` (the rows on the page),
 * `pile_ids[]` (the "keeps a giveaway pile" switch) and `reorder_at[id]`.
 * The two number boxes beside "Add stickers" and "Recount" have no name
 * on purpose: they are not settings and are never posted with Save —
 * their button sends them, at once, to the pile.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var array[] $stock_rows Reorder::rows( …, true ).
 * @var array   $settings   Settings.
 */

use FisHotel\Misc\Sections\Sticker_Giveaway\Piles;
use FisHotel\Misc\Sections\Sticker_Giveaway\Reorder;

defined( 'ABSPATH' ) || exit;

if ( ! $stock_rows ) :
	?>
	<p class="fishotel-misc-card-desc"><?php esc_html_e( 'No designs yet. Pick your sticker product above and save — its designs get a row each here.', 'fishotel-misc-plugin' ); ?></p>
	<?php
	return;
endif;

$fh_sg_date = static function ( $utc ) {
	$time = '' !== (string) $utc ? strtotime( $utc . ' UTC' ) : false;
	return $time ? wp_date( get_option( 'date_format', 'Y-m-d' ), $time ) : '';
};

$fh_sg_status = array(
	Piles::STATUS_OK      => array( __( 'OK', 'fishotel-misc-plugin' ), 'ok' ),
	Piles::STATUS_REORDER => array( __( 'Reorder', 'fishotel-misc-plugin' ), 'reorder' ),
	Piles::STATUS_OUT     => array( __( 'Out', 'fishotel-misc-plugin' ), 'out' ),
);
?>
<div class="fh-sg-stock">
	<?php
	foreach ( $stock_rows as $row ) :
		$fh_id      = (int) $row['id'];
		$fh_status  = $fh_sg_status[ $row['status'] ] ?? $fh_sg_status[ Piles::STATUS_OK ];
		$fh_counted = $fh_sg_date( $row['counted_at'] );
		$fh_added   = $fh_sg_date( $row['added_at'] );
		$fh_stock_blockers = array( 'pile_empty', 'pile_reserve', 'out_of_stock' );
		$fh_blocked = (bool) array_diff( (array) $row['blockers'], $fh_stock_blockers );
		?>
	<section class="fh-sg-stock__row fh-sg-stock__row--<?php echo esc_attr( $fh_status[1] ); ?>" data-design="<?php echo esc_attr( $fh_id ); ?>">
		<input type="hidden" name="stock_listed[]" value="<?php echo esc_attr( $fh_id ); ?>">

		<header class="fh-sg-stock__head">
			<span class="fh-sg-stock__thumb"><?php echo $row['product'] ? wp_kses_post( $row['product']->get_image( 'thumbnail' ) ) : ''; ?></span>
			<h3 class="fh-sg-stock__name"><?php echo esc_html( $row['name'] ); ?></h3>
			<span class="fh-sg-tag fh-sg-tag--<?php echo esc_attr( $fh_status[1] ); ?>"><?php echo esc_html( $fh_status[0] ); ?></span>
			<?php if ( $fh_blocked ) : ?>
				<span class="fh-sg-tag fh-sg-tag--out"><?php esc_html_e( 'Not offered — see the warnings above', 'fishotel-misc-plugin' ); ?></span>
			<?php endif; ?>
		</header>

		<?php if ( $row['keeps_pile'] ) : ?>
		<p class="fh-sg-stock__line">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: paper count, 2: really there */
					__( '%1$d on paper · about %2$d really there', 'fishotel-misc-plugin' ),
					(int) $row['paper'],
					(int) $row['really_there']
				)
			);
			?>
		</p>
		<?php endif; ?>

		<dl class="fh-sg-stock__facts">
			<?php if ( $row['keeps_pile'] ) : ?>
			<div>
				<dt><?php esc_html_e( 'Giveaway pile, on paper', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php echo esc_html( (int) $row['paper'] ); ?>
					<?php if ( '' !== $fh_counted ) : ?>
						<?php /* translators: %s: date */ ?>
						<small><?php echo esc_html( sprintf( __( 'last counted %s', 'fishotel-misc-plugin' ), $fh_counted ) ); ?></small>
					<?php else : ?>
						<small><?php esc_html_e( 'never counted', 'fishotel-misc-plugin' ); ?></small>
					<?php endif; ?>
					<?php if ( '' !== $fh_added && (string) $row['added_at'] > (string) $row['counted_at'] ) : ?>
						<?php /* translators: %s: date */ ?>
						<small><?php echo esc_html( sprintf( __( 'stickers added %s', 'fishotel-misc-plugin' ), $fh_added ) ); ?></small>
					<?php endif; ?>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Really there', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php /* translators: %d: stickers */ ?>
					<?php echo esc_html( sprintf( __( 'about %d', 'fishotel-misc-plugin' ), (int) $row['really_there'] ) ); ?>
					<?php /* translators: %d: stickers held back */ ?>
					<small><?php echo esc_html( sprintf( __( '%d held back as probably gone', 'fishotel-misc-plugin' ), (int) $row['reserve'] ) ); ?></small>
				</dd>
			</div>
			<?php endif; ?>
			<div>
				<dt><?php esc_html_e( 'Shop pile', 'fishotel-misc-plugin' ); ?></dt>
				<dd>
					<?php if ( null === $row['shop'] ) : ?>
						<?php esc_html_e( 'not counted', 'fishotel-misc-plugin' ); ?>
						<small><?php esc_html_e( 'the product does not manage stock', 'fishotel-misc-plugin' ); ?></small>
					<?php else : ?>
						<?php echo esc_html( (int) $row['shop'] ); ?>
						<small><?php esc_html_e( 'the product\'s own WooCommerce stock', 'fishotel-misc-plugin' ); ?></small>
					<?php endif; ?>
				</dd>
			</div>
			<?php if ( $row['keeps_pile'] ) : ?>
			<div class="fh-sg-stock__wide">
				<dt><?php esc_html_e( 'How fast it goes', 'fishotel-misc-plugin' ); ?></dt>
				<dd><?php echo esc_html( Reorder::pace_text( $row ) ); ?></dd>
			</div>
			<?php endif; ?>
		</dl>

		<div class="fh-sg-stock__setting">
			<label class="fh-sg-check">
				<input type="checkbox" name="pile_ids[]" value="<?php echo esc_attr( $fh_id ); ?>" <?php checked( $row['keeps_pile'] ); ?>>
				<span><?php esc_html_e( 'Keeps a giveaway pile', 'fishotel-misc-plugin' ); ?></span>
			</label>
			<p class="fh-sg-hint">
				<?php
				echo esc_html(
					$row['keeps_pile']
						? __( 'Free stickers and add-ons come out of the giveaway pile. The shop pile is left alone and may be at 0.', 'fishotel-misc-plugin' )
						: __( 'Off: free stickers and add-ons come out of the product\'s own WooCommerce stock, as they did before. Tick this and save to give this design a pile of its own — it starts empty, so count it straight away.', 'fishotel-misc-plugin' )
				);
				?>
			</p>
		</div>

		<div class="fh-sg-stock__setting">
			<label class="fh-sg-label" for="fh-sg-reorder-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'Reorder at', 'fishotel-misc-plugin' ); ?></label>
			<input type="number" id="fh-sg-reorder-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count" name="reorder_at[<?php echo esc_attr( $fh_id ); ?>]" min="0" step="1" inputmode="numeric" value="<?php echo esc_attr( null === $row['reorder_at'] ? '' : (int) $row['reorder_at'] ); ?>">
			<p class="fh-sg-hint">
				<?php
				if ( null !== $row['left'] ) {
					/* translators: %d: stickers left, both piles */
					echo esc_html( sprintf( __( 'Reminds you when what is left drops to this number. Left now, both piles together: %d. Blank = no reminder. Saved with Save Settings.', 'fishotel-misc-plugin' ), (int) $row['left'] ) );
				} else {
					esc_html_e( 'Reminds you when what is left drops to this number. Nothing is counted for this design yet, so there is nothing to compare it with. Blank = no reminder. Saved with Save Settings.', 'fishotel-misc-plugin' );
				}
				?>
			</p>
		</div>

		<?php if ( $row['keeps_pile'] ) : ?>
		<div class="fh-sg-stock__acts">
			<div class="fh-sg-stock__act">
				<label class="fh-sg-label" for="fh-sg-add-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'New stickers arrived', 'fishotel-misc-plugin' ); ?></label>
				<span class="fh-sg-stock__go">
					<input type="number" id="fh-sg-add-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count fh-sg-pile-n" data-act="add" min="1" step="1" inputmode="numeric">
					<button type="button" class="button fh-sg-pile-btn" data-act="add"><?php esc_html_e( 'Add stickers', 'fishotel-misc-plugin' ); ?></button>
				</span>
				<p class="fh-sg-hint"><?php esc_html_e( 'How many went into the giveaway pile. Adds to the paper count.', 'fishotel-misc-plugin' ); ?></p>
			</div>
			<div class="fh-sg-stock__act">
				<label class="fh-sg-label" for="fh-sg-recount-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'I counted the pile', 'fishotel-misc-plugin' ); ?></label>
				<span class="fh-sg-stock__go">
					<input type="number" id="fh-sg-recount-<?php echo esc_attr( $fh_id ); ?>" class="fh-sg-input fh-sg-input--count fh-sg-pile-n" data-act="recount" min="0" step="1" inputmode="numeric">
					<button type="button" class="button fh-sg-pile-btn" data-act="recount"><?php esc_html_e( 'Recount', 'fishotel-misc-plugin' ); ?></button>
				</span>
				<p class="fh-sg-hint"><?php esc_html_e( 'How many are really in it now. Replaces the paper count. Typed it wrong? Recount again.', 'fishotel-misc-plugin' ); ?></p>
			</div>
		</div>
		<?php endif; ?>
	</section>
	<?php endforeach; ?>
</div>
