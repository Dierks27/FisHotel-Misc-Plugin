<?php
/**
 * A gift card product's purchase fields, above Add to cart.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var object     $product WC_Product.
 * @var array|null $limits  Product::custom_limits() (null = fixed amount).
 * @var string     $today   Y-m-d at the site.
 * @var array      $post    The refused form's values (refill).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Money;
use FisHotel\Misc\Sections\Gift_Cards\Product;

$fh_v    = static function ( $key ) use ( $post ) {
	return is_array( $post ) && isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? (string) $post[ $key ] : '';
};
$fh_last = ( new \DateTimeImmutable( $today ) )->modify( '+' . Product::MAX_AHEAD . ' days' )->format( 'Y-m-d' );
?>
<div class="fh-gcard-buy wc-gc-giftcard-form">
	<?php if ( is_array( $limits ) ) : ?>
		<p class="form-row form-row-wide">
			<label for="fh_gcard_amount">
				<?php
				/* translators: 1: smallest, 2: largest */
				echo esc_html( sprintf( __( 'Amount (%1$s to %2$s)', 'fishotel-misc-plugin' ), Money::words( $limits['min'] ), Money::words( $limits['max'] ) ) );
				?>
			</label>
			<input type="text" inputmode="decimal" class="input-text" id="fh_gcard_amount" name="fh_gcard_amount" value="<?php echo esc_attr( $fh_v( 'fh_gcard_amount' ) ); ?>" placeholder="<?php echo esc_attr( Money::plain( $limits['min'] ) ); ?>">
		</p>
	<?php endif; ?>
	<p class="form-row form-row-first">
		<label for="fh_gcard_to_name"><?php esc_html_e( 'Who it is for', 'fishotel-misc-plugin' ); ?></label>
		<input type="text" class="input-text" id="fh_gcard_to_name" name="fh_gcard_to_name" maxlength="120" value="<?php echo esc_attr( $fh_v( 'fh_gcard_to_name' ) ); ?>">
	</p>
	<p class="form-row form-row-last">
		<label for="fh_gcard_to_email"><?php esc_html_e( 'Their e-mail address (empty = yours)', 'fishotel-misc-plugin' ); ?></label>
		<input type="email" class="input-text" id="fh_gcard_to_email" name="fh_gcard_to_email" maxlength="190" value="<?php echo esc_attr( $fh_v( 'fh_gcard_to_email' ) ); ?>">
	</p>
	<p class="form-row form-row-first">
		<label for="fh_gcard_from_name"><?php esc_html_e( 'From', 'fishotel-misc-plugin' ); ?></label>
		<input type="text" class="input-text" id="fh_gcard_from_name" name="fh_gcard_from_name" maxlength="120" value="<?php echo esc_attr( $fh_v( 'fh_gcard_from_name' ) ); ?>">
	</p>
	<p class="form-row form-row-last">
		<label for="fh_gcard_date"><?php esc_html_e( 'Send it on (empty = when paid)', 'fishotel-misc-plugin' ); ?></label>
		<input type="date" class="input-text" id="fh_gcard_date" name="fh_gcard_date" min="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $fh_last ); ?>" value="<?php echo esc_attr( $fh_v( 'fh_gcard_date' ) ); ?>">
	</p>
	<p class="form-row form-row-wide">
		<label for="fh_gcard_message"><?php esc_html_e( 'A message (optional)', 'fishotel-misc-plugin' ); ?></label>
		<textarea class="input-text" id="fh_gcard_message" name="fh_gcard_message" rows="3" maxlength="500"><?php echo esc_textarea( $fh_v( 'fh_gcard_message' ) ); ?></textarea>
	</p>
	<p class="fh-gcard-buy__note"><?php esc_html_e( 'The gift card is e-mailed with its code. It never expires.', 'fishotel-misc-plugin' ); ?></p>
</div>
