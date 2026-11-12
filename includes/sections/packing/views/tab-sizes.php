<?php
/**
 * Packing page — Sizes → bags: the map, and the "Fill empty ones" tool.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var array  $settings Settings::get().
 * @var array  $standard Size_List::standard().
 * @var array  $counts   Store::variation_counts(): total, with_bag.
 * @var string $blocked  Why the fill tool cannot run, or ''.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Bags;
use FisHotel\Misc\Sections\Packing\Fill;
use FisHotel\Misc\Sections\Packing\Settings;
use FisHotel\Misc\Sections\Packing\Size_List;

$fh_bags  = $settings['bags'];
$fh_map   = $settings['size_map'];
$fh_sizes = $standard['sizes'];

// Sizes the map still holds although they left the list.
$fh_stale = array();
foreach ( $fh_map as $fh_slug => $fh_bag_id ) {
	if ( ! Size_List::find( $fh_sizes, (string) $fh_slug ) ) {
		$fh_stale[] = (string) $fh_slug;
	}
}

if ( Size_List::SOURCE_CLEAN_ROOM === $standard['source'] && Size_List::KIND_ATTRIBUTE === $standard['kind'] ) {
	/* translators: 1: attribute label, 2: attribute taxonomy */
	$fh_source = sprintf( __( 'The sizes are the terms of the product attribute "%1$s" (%2$s) — the list the Clean Room section uses, in the attribute\'s own order.', 'fishotel-misc-plugin' ), $standard['label'], $standard['attribute'] );
} elseif ( Size_List::SOURCE_CLEAN_ROOM === $standard['source'] ) {
	$fh_source = __( 'The sizes are the plain list typed under Clean Room → Settings → Sizes (no product attribute with "size" in its name exists). The map then answers for lines that carry such a size; product variations cannot be matched to it.', 'fishotel-misc-plugin' );
} elseif ( Size_List::SOURCE_ATTRIBUTE === $standard['source'] ) {
	/* translators: 1: attribute label, 2: attribute taxonomy */
	$fh_source = sprintf( __( 'The sizes are the terms of the product attribute "%1$s" (%2$s), found by its name (the Clean Room section is switched off, so this section looked for it itself).', 'fishotel-misc-plugin' ), $standard['label'], $standard['attribute'] );
} else {
	$fh_source = __( 'No list of sizes is available: there is no product attribute with "size" in its name, and the Clean Room section (which can hold a typed list) is switched off. A Bag size can still be set on each variation by hand.', 'fishotel-misc-plugin' );
}
?>
<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-randomize"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Size → bag', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc"><?php echo esc_html( $fh_source ); ?></p>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'A variation with no Bag size of its own is packed in the bag of its size. A Bag size set on the variation itself always wins. The same map answers for fish that are not shop products (an invoice line, an unlisted fish) from the size on the line.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php if ( ! $fh_bags ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php
			printf(
				/* translators: %s: link to the Bags tab */
				esc_html__( 'There are no bag sizes yet — add them under %s first.', 'fishotel-misc-plugin' ),
				'<a href="' . esc_url( Admin::tab_url( 'bags' ) ) . '">' . esc_html__( 'Bags', 'fishotel-misc-plugin' ) . '</a>'
			);
			?>
		</div>
	<?php elseif ( ! $fh_sizes && ! $fh_stale ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'There are no sizes to map.', 'fishotel-misc-plugin' ); ?></div>
	<?php else : ?>
		<?php // Reloads once saved: whether the fill tool below can run depends on the map. ?>
		<form class="fh-pack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-reload="1">
			<?php wp_nonce_field( Settings::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="fishotel_packing_map">

			<table class="fh-pack-table fh-pack-table--narrow">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></th>
						<th><?php esc_html_e( 'Bag', 'fishotel-misc-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$fh_row = 0;
					foreach ( array_merge( $fh_sizes, $fh_stale ) as $fh_size ) :
						$fh_is_stale = ! is_array( $fh_size );
						$fh_slug     = $fh_is_stale ? (string) $fh_size : (string) $fh_size['slug'];
						$fh_label    = $fh_is_stale ? $fh_slug : (string) $fh_size['label'];
						$fh_chosen   = (int) ( $fh_map[ $fh_slug ] ?? 0 );
						?>
						<tr>
							<td>
								<?php echo esc_html( $fh_label ); ?>
								<?php if ( $fh_is_stale ) : ?>
									<span class="fh-pack-muted"><?php esc_html_e( '(not in the list of sizes any more)', 'fishotel-misc-plugin' ); ?></span>
								<?php endif; ?>
								<input type="hidden" name="map[<?php echo esc_attr( $fh_row ); ?>][size]" value="<?php echo esc_attr( $fh_slug ); ?>">
							</td>
							<td>
								<select class="fh-pack-input" name="map[<?php echo esc_attr( $fh_row ); ?>][bag]" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: size label */ __( 'Bag for %s', 'fishotel-misc-plugin' ), $fh_label ) ); ?>">
									<option value=""><?php esc_html_e( '— no bag —', 'fishotel-misc-plugin' ); ?></option>
									<?php foreach ( $fh_bags as $fh_bag ) : ?>
										<option value="<?php echo esc_attr( (int) $fh_bag['id'] ); ?>" <?php selected( $fh_chosen, (int) $fh_bag['id'] ); ?>><?php echo esc_html( Bags::label( $fh_bag ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php
						$fh_row++;
					endforeach;
					?>
				</tbody>
			</table>

			<p class="fh-pack-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the map', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-pack-status" role="status" aria-live="polite"></span>
			</p>
		</form>
	<?php endif; ?>
</div>

<div class="fishotel-misc-card fh-pack-card">
	<div class="fishotel-misc-card-header">
		<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-update"></span></div>
		<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Fill empty ones from the map', 'fishotel-misc-plugin' ); ?></h2>
	</div>
	<p class="fishotel-misc-card-desc">
		<?php esc_html_e( 'Writes the map\'s bag onto every fish variation that has no Bag size, so each product shows it. It never changes a variation that already has one, it leaves products that are not in a fish category alone, and it writes nothing but that one hidden field. Get the map right and save it first, then preview, then set: a variation keeps the bag it was given even when the map changes later (set it back to "Not set" on the variation to make it follow the map again).', 'fishotel-misc-plugin' ); ?>
	</p>
	<p class="fishotel-misc-card-desc">
		<?php
		/* translators: 1: variations in all, 2: variations with a bag size */
		echo esc_html( sprintf( __( 'Right now: %1$d variations, %2$d with a Bag size of their own.', 'fishotel-misc-plugin' ), (int) $counts['total'], (int) $counts['with_bag'] ) );
		?>
	</p>

	<?php if ( '' !== $blocked ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( $blocked ); ?></div>
	<?php else : ?>
		<div class="fh-pack-fill" data-nonce="<?php echo esc_attr( wp_create_nonce( Fill::NONCE_ACTION ) ); ?>">
			<p class="fh-pack-actions">
				<button type="button" class="button fh-pack-fill-preview"><?php esc_html_e( 'Preview', 'fishotel-misc-plugin' ); ?></button>
				<button type="button" class="button button-primary fh-pack-fill-run" disabled><?php esc_html_e( 'Set them', 'fishotel-misc-plugin' ); ?></button>
				<span class="fh-pack-status" role="status" aria-live="polite"></span>
			</p>
			<ul class="fh-pack-fill-result" aria-live="polite"></ul>
		</div>
	<?php endif; ?>
</div>
