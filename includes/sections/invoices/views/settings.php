<?php
/**
 * Settings — the Invoices category, the title and short-description
 * formats, which shipping classes "Premium" and "Free" stand for, and
 * the two featured images with the total that separates them. Nothing
 * is seeded: until two images are chosen an invoice gets none.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      classes, images, category.
 * @var array  $settings  Settings.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Format;
use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Lines;
use FisHotel\Misc\Sections\Invoices\Settings;

/**
 * The <option>s of a shipping-class select.
 *
 * @param array[] $classes The store's classes.
 * @param string  $current The stored slug.
 */
$fh_options = static function ( array $classes, $current ) {
	$known = false;
	?>
	<option value=""><?php esc_html_e( '— not set —', 'fishotel-misc-plugin' ); ?></option>
	<?php
	foreach ( $classes as $class ) :
		$known = $known || $class['slug'] === $current;
		?>
		<option value="<?php echo esc_attr( $class['slug'] ); ?>" <?php selected( $current, $class['slug'] ); ?>><?php echo esc_html( $class['name'] . ' (' . $class['slug'] . ')' ); ?></option>
		<?php
	endforeach;
	if ( '' !== $current && ! $known ) :
		?>
		<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: shipping class slug */ __( '%s — does not exist on this site', 'fishotel-misc-plugin' ), $current ) ); ?></option>
		<?php
	endif;
};
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap">

	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<form id="fh-inv-settings" class="fishotel-misc-card fh-inv-card">
		<h3 class="fh-inv-h3"><?php esc_html_e( 'The product an invoice becomes', 'fishotel-misc-plugin' ); ?></h3>
		<div class="fh-inv-fields">
			<label class="fh-inv-field">
				<span><?php esc_html_e( 'Product category (slug)', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-inv-input" name="settings[category_slug]" maxlength="100" value="<?php echo esc_attr( $settings['category_slug'] ); ?>">
				<small class="<?php echo $data['category'] ? '' : 'fh-inv-warn'; ?>">
					<?php
					echo esc_html(
						$data['category']
							/* translators: %s: category name */
							? sprintf( __( 'Found: "%s". Every invoice is filed there, and nowhere else.', 'fishotel-misc-plugin' ), $data['category']['name'] )
							: __( 'This category does not exist. Nothing can be published until it does — it is never created for you.', 'fishotel-misc-plugin' )
					);
					?>
				</small>
			</label>
			<label class="fh-inv-field">
				<span><?php esc_html_e( 'Title', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-inv-input" name="settings[title_format]" maxlength="120" value="<?php echo esc_attr( $settings['title_format'] ); ?>">
				<small>
					<?php
					/* translators: 1: {handle}, 2: {date}, 3: an example title */
					echo esc_html( sprintf( __( '%1$s is the handle, %2$s the site date as month/day/year. Now: %3$s', 'fishotel-misc-plugin' ), '{handle}', '{date}', Format::title( $settings['title_format'], 'Handle', Invoices::today() ) ) );
					?>
				</small>
			</label>
			<label class="fh-inv-field">
				<span><?php esc_html_e( 'Short description', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-inv-input" name="settings[excerpt_format]" maxlength="120" value="<?php echo esc_attr( $settings['excerpt_format'] ); ?>">
				<small><?php echo esc_html( sprintf( /* translators: %s: an example */ __( 'Now: %s', 'fishotel-misc-plugin' ), Format::excerpt( $settings['excerpt_format'], 'Handle' ) ) ); ?></small>
			</label>
		</div>

		<h3 class="fh-inv-h3"><?php esc_html_e( 'Shipping', 'fishotel-misc-plugin' ); ?></h3>
		<?php if ( ! $data['classes'] ) : ?>
			<p class="fh-inv-footnote fh-inv-warn"><?php esc_html_e( 'This store has no shipping classes (WooCommerce → Settings → Shipping → Classes).', 'fishotel-misc-plugin' ); ?></p>
		<?php endif; ?>
		<div class="fh-inv-fields">
			<?php foreach ( array( 'premium', 'free' ) as $fh_choice ) : ?>
				<label class="fh-inv-field">
					<span><?php echo esc_html( sprintf( /* translators: %s: Premium / Free */ __( '"%s" is the shipping class', 'fishotel-misc-plugin' ), Settings::shipping_label( $fh_choice ) ) ); ?></span>
					<select class="fh-inv-input" name="settings[shipping][<?php echo esc_attr( $fh_choice ); ?>]">
						<?php $fh_options( $data['classes'], $settings['shipping'][ $fh_choice ] ); ?>
					</select>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="fh-inv-footnote"><?php esc_html_e( 'Premium is what a new invoice starts with. An invoice can also be given any other class of the store.', 'fishotel-misc-plugin' ); ?></p>

		<h3 class="fh-inv-h3"><?php esc_html_e( 'Featured image', 'fishotel-misc-plugin' ); ?></h3>
		<div class="fh-inv-fields">
			<?php
			$fh_images = array(
				'image_under' => __( 'Image for a total UNDER the threshold (media id)', 'fishotel-misc-plugin' ),
				'image_over'  => __( 'Image for a total AT or OVER it (media id)', 'fishotel-misc-plugin' ),
			);
			foreach ( $fh_images as $fh_key => $fh_label ) :
				$fh_image = $data['images'][ $fh_key ];
				?>
				<label class="fh-inv-field fh-inv-image">
					<span><?php echo esc_html( $fh_label ); ?></span>
					<span class="fh-inv-image__row">
						<input type="number" class="fh-inv-input fh-inv-input--num" name="settings[<?php echo esc_attr( $fh_key ); ?>]" min="0" step="1" value="<?php echo esc_attr( $settings[ $fh_key ] > 0 ? $settings[ $fh_key ] : '' ); ?>">
						<button type="button" class="button fh-inv-media"><?php esc_html_e( 'Choose…', 'fishotel-misc-plugin' ); ?></button>
						<?php if ( $fh_image && '' !== $fh_image['url'] ) : ?>
							<img class="fh-inv-thumb" src="<?php echo esc_url( $fh_image['url'] ); ?>" alt="">
						<?php endif; ?>
					</span>
					<small class="<?php echo $settings[ $fh_key ] > 0 && ! $fh_image ? 'fh-inv-warn' : ''; ?>">
						<?php
						if ( $fh_image ) {
							echo esc_html( $fh_image['title'] );
						} elseif ( $settings[ $fh_key ] > 0 ) {
							esc_html_e( 'This id is not in the media library: such an invoice gets no image.', 'fishotel-misc-plugin' );
						} else {
							esc_html_e( 'Not set: such an invoice gets no image.', 'fishotel-misc-plugin' );
						}
						?>
					</small>
				</label>
			<?php endforeach; ?>
			<label class="fh-inv-field fh-inv-field--small">
				<span><?php esc_html_e( 'Threshold ($)', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" inputmode="decimal" class="fh-inv-input fh-inv-input--num" name="settings[image_threshold]" value="<?php echo esc_attr( Lines::money_text( $settings['image_threshold'] ) ); ?>">
			</label>
		</div>

		<div class="fh-inv-actionbar">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-inv-status" id="fh-inv-settings-status" aria-live="polite"></span>
		</div>
		<ul class="fh-inv-forum__skipped" id="fh-inv-settings-notes"></ul>
	</form>

	<p class="fh-inv-footnote">
		<?php esc_html_e( 'What is never a setting: an invoice is a simple product, published, hidden from the catalog, stock managed with quantity 1 and no backorders, the regular price its total — and this section never sets or clears a sale price.', 'fishotel-misc-plugin' ); ?>
	</p>
</div>
