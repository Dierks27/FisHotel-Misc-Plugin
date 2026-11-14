<?php
/**
 * Checkout: "Use my gift card balance" — the checkbox the theme styles and
 * watches (#use_gift_card_balance), and how much of it to use. Inside the
 * checkout form, so both fields go with every update and with Place order.
 *
 * @package FisHotel\Misc\Sections\Gift_Cards
 *
 * @var array $money  Checkout::applied(): applied, wallet (cents).
 * @var array $choice Checkout::choice().
 * @var int   $cap    The most the balance can pay on this order (cents).
 * @var int   $value  The amount shown in the box (cents).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Gift_Cards\Money;
?>
<div class="fh-gcard-checkout" data-wallet="<?php echo esc_attr( Money::plain( $money['wallet'] ) ); ?>" data-max="<?php echo esc_attr( Money::plain( $cap ) ); ?>">
	<label class="fh-gcard-checkout__use" for="use_gift_card_balance">
		<input type="checkbox" id="use_gift_card_balance" name="fh_gcard_use" value="1" <?php checked( $choice['use'] ); ?>>
		<?php
		/* translators: %s: balance */
		echo wp_kses_post( sprintf( __( 'Use my gift card balance (%s available)', 'fishotel-misc-plugin' ), '<strong>' . esc_html( Money::words( $money['wallet'] ) ) . '</strong>' ) );
		?>
	</label>
	<p class="fh-gcard-checkout__amount"<?php echo $choice['use'] ? '' : ' hidden'; ?>>
		<label for="fh_gcard_amount"><?php esc_html_e( 'How much to use', 'fishotel-misc-plugin' ); ?></label>
		<input type="text" inputmode="decimal" autocomplete="off" id="fh_gcard_amount" name="fh_gcard_amount" value="<?php echo esc_attr( Money::plain( $value ) ); ?>" aria-describedby="fh_gcard_amount_help">
		<small id="fh_gcard_amount_help">
			<?php
			/* translators: %s: the most that can be used */
			echo esc_html( sprintf( __( 'Up to %s on this order. Whatever it does not cover, you pay below.', 'fishotel-misc-plugin' ), Money::words( $cap ) ) );
			?>
		</small>
	</p>
</div>
