<?php
/**
 * Sticker Giveaway settings — the variations of each picked variable
 * product, one tick per variation. One ticked variation is one design.
 *
 * Every listed variation is posted as "shown" whether ticked or not, and
 * every product whose list is on the page as "listed": the save can then
 * tell a variation the owner unticked from one that was added to the
 * product later (which is listed unticked and marked new — it is never
 * offered silently).
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var array[] $groups [ parent => \WC_Product, rows => [ id, name, image, fixed, ticked, new ] ].
 */

defined( 'ABSPATH' ) || exit;

foreach ( $groups as $group ) :
	$parent_id = (int) $group['parent']->get_id();
	?>
<fieldset class="fh-sg-variations" data-parent="<?php echo esc_attr( $parent_id ); ?>">
	<legend class="fh-sg-label">
		<?php
		/* translators: %s: product name */
		echo esc_html( sprintf( __( 'Designs of %s', 'fishotel-misc-plugin' ), $group['parent']->get_name() ) );
		?>
	</legend>
	<input type="hidden" name="sticker_variations_listed[]" value="<?php echo esc_attr( $parent_id ); ?>">

	<?php if ( empty( $group['rows'] ) ) : ?>
		<p class="fishotel-misc-card-desc"><?php esc_html_e( 'This product has no variations yet.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<p class="fishotel-misc-card-desc fh-sg-variations__hint"><?php esc_html_e( 'Each ticked variation is a design the customer can pick. It is shown under its own name and its own picture.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $group['rows'] as $row ) : ?>
	<label class="fh-sg-variation<?php echo $row['fixed'] ? '' : ' fh-sg-variation--off'; ?>">
		<input type="hidden" name="sticker_variation_shown[]" value="<?php echo esc_attr( $row['id'] ); ?>">
		<input type="checkbox"
			name="sticker_variation_ids[]"
			value="<?php echo esc_attr( $row['id'] ); ?>"
			<?php checked( ! empty( $row['ticked'] ) ); ?>
			<?php disabled( empty( $row['fixed'] ) ); ?>>
		<span class="fh-sg-variation__thumb"><?php echo wp_kses_post( $row['image'] ); ?></span>
		<span class="fh-sg-variation__name"><?php echo esc_html( $row['name'] ); ?></span>
		<?php if ( ! empty( $row['new'] ) ) : ?>
			<span class="fh-sg-tag fh-sg-tag--new"><?php esc_html_e( 'New — not offered yet', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
		<?php if ( empty( $row['fixed'] ) ) : ?>
			<span class="fh-sg-variation__why"><?php esc_html_e( 'Cannot be a design: it leaves an attribute on "Any". Choose a value for every attribute on the product page.', 'fishotel-misc-plugin' ); ?></span>
		<?php endif; ?>
	</label>
	<?php endforeach; ?>
</fieldset>
	<?php
endforeach;
