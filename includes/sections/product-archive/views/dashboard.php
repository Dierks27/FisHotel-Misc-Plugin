<?php
/**
 * Product Archive dashboard view.
 *
 * @package FisHotel\Misc\Sections\Product_Archive
 *
 * @var int    $archived_count   Number of archived products.
 * @var int    $published_count  Number of published products.
 * @var string $archive_url      Admin URL filtered to archived products.
 * @var string $all_products_url Admin URL for the products list.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap fishotel-misc-wrap fishotel-pa-wrap">

	<div class="fishotel-misc-header">
		<h1><?php esc_html_e( 'Product Archive', 'fishotel-misc-plugin' ); ?></h1>
		<span class="version-badge">v<?php echo esc_html( FISHOTEL_MISC_VERSION ); ?></span>
	</div>

	<div class="fishotel-misc-card fishotel-pa-card">
		<p class="fishotel-pa-explainer">
			<?php
			esc_html_e(
				'Archiving hides a product from your shop, search results, related products, and the WooCommerce REST API — without deleting it. Past orders that contain archived products continue to display correctly, and admin product lookup (e.g. when adding a line item to a manual order) still finds them.',
				'fishotel-misc-plugin'
			);
			?>
		</p>

		<div class="fishotel-pa-stats">
			<div class="fishotel-pa-stat">
				<span class="fishotel-pa-stat__num"><?php echo esc_html( number_format_i18n( $archived_count ) ); ?></span>
				<span class="fishotel-pa-stat__label"><?php esc_html_e( 'Archived', 'fishotel-misc-plugin' ); ?></span>
			</div>
			<div class="fishotel-pa-stat">
				<span class="fishotel-pa-stat__num"><?php echo esc_html( number_format_i18n( $published_count ) ); ?></span>
				<span class="fishotel-pa-stat__label"><?php esc_html_e( 'Published', 'fishotel-misc-plugin' ); ?></span>
			</div>
		</div>

		<div class="fishotel-pa-actions">
			<a class="button button-primary fishotel-pa-btn" href="<?php echo esc_url( $archive_url ); ?>">
				<?php esc_html_e( 'View archived products', 'fishotel-misc-plugin' ); ?>
			</a>
			<a class="button fishotel-pa-btn" href="<?php echo esc_url( $all_products_url ); ?>">
				<?php esc_html_e( 'View all products', 'fishotel-misc-plugin' ); ?>
			</a>
		</div>
	</div>

	<div class="fishotel-misc-card fishotel-pa-card">
		<h2 class="fishotel-pa-h2"><?php esc_html_e( 'How it works', 'fishotel-misc-plugin' ); ?></h2>
		<ul class="fishotel-pa-list">
			<li><?php esc_html_e( 'Archive from the products list using the row "Archive" link or the Bulk Actions dropdown.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Archived products are hidden from the shop, search, related products, and the WooCommerce REST API.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Direct archived product URLs return a 404 to visitors.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Past orders containing archived products keep displaying line items correctly — order history is preserved.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Admins can still find archived products when looking up orders or adding line items to manual orders.', 'fishotel-misc-plugin' ); ?></li>
			<li><?php esc_html_e( 'Restore a product with the row "Unarchive" link or the Bulk Actions dropdown.', 'fishotel-misc-plugin' ); ?></li>
		</ul>
	</div>

</div>
