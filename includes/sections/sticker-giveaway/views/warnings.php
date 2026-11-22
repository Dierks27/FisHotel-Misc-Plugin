<?php
/**
 * Sticker Giveaway settings — the warnings under the pool.
 *
 * @package FisHotel\Misc\Sections\Sticker_Giveaway
 *
 * @var string[] $warnings Admin::pool_warnings().
 */

defined( 'ABSPATH' ) || exit;

if ( ! $warnings ) {
	return;
}
?>
<ul class="fh-sg-warnings">
	<?php foreach ( $warnings as $warning ) : ?>
		<li><?php echo esc_html( $warning ); ?></li>
	<?php endforeach; ?>
</ul>
