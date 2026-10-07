<?php
/**
 * Sticker Giveaway picker — rendered inside <form name="checkout"> (or on
 * the cart page when enabled).
 *
 * Uses only fh-sticker-* classes so it looks right without the theme's
 * stylesheet; the colours mirror the theme's .fh-checkout-card.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var array $view {
 *   @type string        $context       'checkout' | 'cart'
 *   @type array         $settings      Section settings.
 *   @type \WC_Product[] $products      Available pool, keyed by ID.
 *   @type bool          $eligible      Cart qualifies for the free sticker.
 *   @type bool          $offer_upsell  Show add-on steppers.
 *   @type int           $selected_free Product ID checked (0 = no thanks).
 *   @type int[]         $extras        Current add-on quantities by ID.
 *   @type float         $extra_price   Add-on unit price.
 *   @type int           $max_qty       Add-on max per sticker.
 *   @type string        $heading_upsell Add-on heading for this cart's state.
 *   @type string        $intro_upsell  Add-on intro with {price} resolved.
 * }
 */

use FisHotel\Misc\Sections\Sticker_Giveaway\Cart;

defined( 'ABSPATH' ) || exit;

$s = $view['settings'];
?>
<div class="fh-sticker fh-sticker--<?php echo esc_attr( $view['context'] ); ?>" data-fh-sticker data-context="<?php echo esc_attr( $view['context'] ); ?>">
	<input type="hidden" name="<?php echo esc_attr( Cart::FIELD_MARKER ); ?>" value="1">

	<?php if ( $view['eligible'] ) : ?>
	<header class="fh-sticker__header">
		<span class="fh-sticker__eyebrow"><?php echo esc_html( $s['heading_free'] ); ?></span>
	</header>

	<?php if ( '' !== $s['intro_free'] ) : ?>
	<p class="fh-sticker__intro"><?php echo esc_html( $s['intro_free'] ); ?></p>
	<?php endif; ?>

	<div class="fh-sticker__grid" role="radiogroup" aria-label="<?php echo esc_attr( $s['heading_free'] ); ?>">
		<?php foreach ( $view['products'] as $pid => $product ) : ?>
		<label class="fh-sticker__option">
			<input class="fh-sticker__radio" type="radio" name="<?php echo esc_attr( Cart::FIELD_FREE ); ?>" value="<?php echo esc_attr( $pid ); ?>" <?php checked( $view['selected_free'], (int) $pid ); ?>>
			<span class="fh-sticker__card">
				<span class="fh-sticker__thumb"><?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="fh-sticker__name"><?php echo esc_html( $product->get_name() ); ?></span>
				<?php if ( '' !== $s['badge_text'] ) : ?>
				<span class="fh-sticker__badge"><?php echo esc_html( $s['badge_text'] ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php endforeach; ?>

		<label class="fh-sticker__option fh-sticker__option--none">
			<input class="fh-sticker__radio" type="radio" name="<?php echo esc_attr( Cart::FIELD_FREE ); ?>" value="0" <?php checked( $view['selected_free'], 0 ); ?>>
			<span class="fh-sticker__card">
				<span class="fh-sticker__name"><?php echo esc_html( $s['no_thanks_label'] ); ?></span>
			</span>
		</label>
	</div>
	<?php endif; ?>

	<?php if ( $view['offer_upsell'] ) : ?>
	<div class="fh-sticker__upsell">
		<header class="fh-sticker__header">
			<span class="fh-sticker__eyebrow"><?php echo esc_html( $view['heading_upsell'] ); ?></span>
		</header>

		<?php if ( '' !== $view['intro_upsell'] ) : ?>
		<p class="fh-sticker__intro"><?php echo esc_html( $view['intro_upsell'] ); ?></p>
		<?php endif; ?>

		<ul class="fh-sticker__rows">
			<?php
			foreach ( $view['products'] as $pid => $product ) :
				$qty = isset( $view['extras'][ $pid ] ) ? (int) $view['extras'][ $pid ] : 0;
				$id  = 'fh-sticker-extra-' . (int) $pid;
				?>
			<li class="fh-sticker__row">
				<span class="fh-sticker__row-thumb"><?php echo $product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php // A <span>, not a <label for>: the theme styles every label[for] in the checkout form as a gold uppercase field label. The input carries aria-label instead. ?>
				<span class="fh-sticker__row-name"><?php echo esc_html( $product->get_name() ); ?></span>
				<span class="fh-sticker__row-price"><?php echo wp_kses_post( wc_price( $view['extra_price'] ) ); ?></span>
				<span class="fh-sticker__stepper">
					<button type="button" class="fh-sticker__step" data-step="-1" aria-label="<?php esc_attr_e( 'One fewer', 'fishotel-misc-plugin' ); ?>">&minus;</button>
					<input
						type="number"
						id="<?php echo esc_attr( $id ); ?>"
						class="fh-sticker__qty"
						name="<?php echo esc_attr( Cart::FIELD_EXTRA ); ?>[<?php echo esc_attr( $pid ); ?>]"
						value="<?php echo esc_attr( $qty ); ?>"
						<?php /* translators: %s: sticker product name */ ?>
						aria-label="<?php echo esc_attr( sprintf( __( 'Quantity of %s', 'fishotel-misc-plugin' ), $product->get_name() ) ); ?>"
						min="0"
						max="<?php echo esc_attr( $view['max_qty'] ); ?>"
						step="1"
						inputmode="numeric">
					<button type="button" class="fh-sticker__step" data-step="1" aria-label="<?php esc_attr_e( 'One more', 'fishotel-misc-plugin' ); ?>">+</button>
				</span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>
</div>
