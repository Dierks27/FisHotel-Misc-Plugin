<?php
/**
 * A screen that cannot be shown: the invoice does not exist, or the page
 * could not be put together.
 *
 * @package FisHotel\Misc\Sections\Invoices
 *
 * @var array  $data      message.
 * @var string $tab       Current tab.
 * @var bool   $tables_ok Whether the section's tables are installed.
 * @var bool   $shop_ok   Whether WooCommerce is active.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Invoices\Admin;
use FisHotel\Misc\Sections\Invoices\Invoices;
?>
<div class="wrap fishotel-misc-wrap fh-inv-wrap">
	<?php include Invoices::path() . 'views/tabs.php'; ?>

	<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php echo esc_html( isset( $data['message'] ) ? (string) $data['message'] : '' ); ?></div>

	<p><a class="button" href="<?php echo esc_url( Admin::page_url( 'list' ) ); ?>"><?php esc_html_e( 'Back to the invoices', 'fishotel-misc-plugin' ); ?></a></p>
</div>
