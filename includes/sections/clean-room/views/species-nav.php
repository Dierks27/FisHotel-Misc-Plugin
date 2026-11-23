<?php
/**
 * The strip under the tabs of the Species tab: Species list · Fish with
 * no product yet.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 *
 * @var string $fh_species_view The view shown ('' = the species list).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Clean_Room\Admin_No_Product;

$fh_species_view = isset( $fh_species_view ) ? (string) $fh_species_view : '';
?>
<nav class="fh-cr-subtabs" aria-label="<?php esc_attr_e( 'Species views', 'fishotel-misc-plugin' ); ?>">
	<?php foreach ( Admin_No_Product::nav( $fh_species_view ) as $fh_item ) : ?>
		<a class="fh-cr-subtab<?php echo $fh_item['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $fh_item['url'] ); ?>"<?php echo $fh_item['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $fh_item['label'] ); ?></a>
	<?php endforeach; ?>
</nav>
