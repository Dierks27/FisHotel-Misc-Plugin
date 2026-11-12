<?php
/**
 * The Packing page: header, tabs, and the tab that was asked for.
 *
 * @package FisHotel\Misc\Sections\Packing
 *
 * @var string $tab         bags | sizes | boxes.
 * @var array  $tabs        Tab key → label.
 * @var array  $settings    Settings::get().
 * @var bool   $woocommerce Whether WooCommerce is loaded.
 * @var array  $usage       Bag id → how many products / variations name it (Bags tab).
 * @var array  $standard    Size_List::standard() (Sizes tab).
 * @var array  $counts      Store::variation_counts() (Sizes tab).
 * @var string $blocked     Why the fill tool cannot run, or '' (Sizes tab).
 * @var array  $current     Boxes::current() (Boxes tab).
 * @var array  $boxes       The boxes in use with their "how many fit" (Boxes tab).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Packing\Admin;
use FisHotel\Misc\Sections\Packing\Packing;
?>
<div class="wrap fishotel-misc-wrap fh-pack-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Packing', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<nav class="fh-pack-tabs">
		<?php foreach ( $tabs as $fh_key => $fh_label ) : ?>
			<a class="fh-pack-tab<?php echo $tab === $fh_key ? ' is-active' : ''; ?>" href="<?php echo esc_url( Admin::tab_url( $fh_key ) ); ?>"><?php echo esc_html( $fh_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( ! $woocommerce ) : ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error">
			<?php esc_html_e( 'WooCommerce is not active: there are no products to give a Bag size to and no orders to suggest a box for. The lists on this page can still be filled in.', 'fishotel-misc-plugin' ); ?>
		</div>
	<?php endif; ?>

	<p class="fh-pack-intro">
		<?php esc_html_e( 'One bag per fish, in one of your standard bags; how many of each bag fit each box; and from those two a suggested box on every order. The Bag size is a record for you and the pick list — customers never see it.', 'fishotel-misc-plugin' ); ?>
	</p>

	<?php include Packing::path() . 'views/tab-' . $tab . '.php'; ?>

</div>
