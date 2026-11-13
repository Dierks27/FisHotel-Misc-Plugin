<?php
/**
 * Storefront silence — the Bag size and the "Livestock — packed in a bag"
 * checkbox are records, so nothing a customer can reach may ever hear of
 * them:
 *
 *   - on a front-end request the section loads nothing but its own file,
 *     hooks nothing and reads no option (no query);
 *   - in wp-admin it hooks only the product editor, the order screen, its
 *     own page and its admin AJAX — none of the hooks WooCommerce uses to
 *     print variation data, cart / checkout / e-mail / invoice / My
 *     Account order-item data, or to build a REST or Store API answer;
 *   - its source names none of those hooks, registers no meta, no REST
 *     route and no public AJAX action, writes no order-item meta, sends no
 *     mail, changes no stock and no status, makes no attribute, term or
 *     variation — and only its Store touches $wpdb, read-only;
 *   - both meta keys are hidden (a leading underscore), and working out a
 *     suggestion or a line's bag writes nothing anywhere — whatever the
 *     checkbox of the product says.
 *
 * Run from the plugin root:  php includes/sections/packing/tests/storefront-test.php
 */

define( 'FH_PACKING_NO_LOAD', true );
require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Packing\Packing;

$section = FISHOTEL_MISC_PATH . 'includes/sections/packing/';

/**
 * The section's own files that are loaded right now (not the tests).
 */
function fh_loaded() {
	$out = array();
	foreach ( get_included_files() as $file ) {
		$file = str_replace( '\\', '/', $file );
		if ( false !== strpos( $file, '/includes/sections/packing/' ) && false === strpos( $file, '/tests/' ) ) {
			$out[] = basename( $file );
		}
	}
	sort( $out );
	return $out;
}

echo "1. A front-end request\n";
check( 'WordPress has loaded the section\'s own file and nothing else of it', array( 'class-packing.php' ) === fh_loaded() );
$GLOBALS['fh_is_admin']     = false;
$GLOBALS['fh_option_reads'] = 0;
Packing::boot();
check( 'boot() hooks nothing at all', array() === $GLOBALS['fh_hooks'], fh_test_export( array_keys( $GLOBALS['fh_hooks'] ) ) );
check( 'it reads no option — the request costs no query here', 0 === $GLOBALS['fh_option_reads'] );
check( 'it loads no further file', array( 'class-packing.php' ) === fh_loaded() );
check( 'it writes no option and enqueues nothing', array() === $GLOBALS['fh_option_writes'] && array() === $GLOBALS['fh_enqueued'] );
check( 'the section counts as switched on, so the contracts answer when somebody calls them', true === Packing::enabled() && function_exists( 'fishotel_packing_suggest' ) && function_exists( 'fishotel_packing_bag_for_item' ) && function_exists( 'fishotel_packing_confirmed_box' ) && function_exists( 'fishotel_packing_bag_for_product' ) && function_exists( 'fishotel_packing_bag_for_size' ) );

echo "2. A wp-admin request\n";
$GLOBALS['fh_is_admin'] = true;
fh_reset_hooks();
Packing::boot();
$hooks = array_keys( $GLOBALS['fh_hooks'] );
sort( $hooks );
$expected = array(
	'add_meta_boxes',
	'admin_enqueue_scripts',
	'fishotel_today_lines', // Its line on the Today page (read only by that page and its widget).
	'postbox_classes_product_woocommerce-product-data', // The classes of the product-data box, on the product edit screen.
	'woocommerce_admin_process_product_object',
	'woocommerce_product_after_variable_attributes',
	'woocommerce_product_options_shipping',
	'woocommerce_save_product_variation',
	'wp_ajax_fishotel_packing_bags',
	'wp_ajax_fishotel_packing_boxes',
	'wp_ajax_fishotel_packing_confirm',
	'wp_ajax_fishotel_packing_fill_preview',
	'wp_ajax_fishotel_packing_fill_run',
	'wp_ajax_fishotel_packing_fits',
	'wp_ajax_fishotel_packing_map',
);
check_same( 'the hooks are exactly: the product editor, the order screen, the page\'s assets, the admin AJAX saves and the Today line', $expected, $hooks );
check( 'booting in wp-admin reads no option either — only a screen that shows something does', 0 === $GLOBALS['fh_option_reads'] );

// The hooks WooCommerce and WordPress use to show something to a customer.
$storefront = array(
	'woocommerce_available_variation',           // The variation data of the product page.
	'woocommerce_get_item_data',                 // The cart and the checkout.
	'woocommerce_add_cart_item_data',
	'woocommerce_get_cart_item_from_session',
	'woocommerce_cart_item_name',
	'woocommerce_checkout_create_order_line_item', // What the order line is given at checkout.
	'woocommerce_checkout_create_order',
	'woocommerce_order_item_get_formatted_meta_data', // Order-item meta wherever it is printed.
	'woocommerce_order_item_display_meta_key',
	'woocommerce_order_item_display_meta_value',
	'woocommerce_display_item_meta',
	'woocommerce_order_item_meta_start',         // E-mails, the thank-you page, My Account.
	'woocommerce_order_item_meta_end',
	'woocommerce_order_item_name',
	'woocommerce_email_order_meta',
	'woocommerce_email_order_details',
	'woocommerce_email_after_order_table',
	'woocommerce_order_details_after_order_table',
	'woocommerce_view_order',
	'woocommerce_thankyou',
	'woocommerce_product_additional_information',
	'woocommerce_display_product_attributes',
	'woocommerce_single_product_summary',
	'woocommerce_product_get_attributes',
	'woocommerce_rest_prepare_product_object',   // The REST API.
	'woocommerce_rest_prepare_product_variation_object',
	'woocommerce_rest_prepare_shop_order_object',
	'woocommerce_store_api_product_schema',      // The Store API.
	'woocommerce_blocks_loaded',
	'rest_api_init',
	'wpo_wcpdf_after_item_meta',                 // PDF invoices.
	'wpo_wcpdf_order_item_data',
	'wp_head',
	'wp_footer',
	'wp_enqueue_scripts',
	'the_content',
	'init',
	'template_redirect',
	'woocommerce_before_calculate_totals',
	'woocommerce_hidden_order_itemmeta',
);
check( 'none of the hooks that print variation, cart, checkout, e-mail, invoice or order-item data — or build a REST / Store API answer — is used', array() === array_values( array_intersect( $storefront, $hooks ) ) );
$public = array_filter(
	$hooks,
	static function ( $hook ) {
		return 0 === strpos( $hook, 'wp_ajax_nopriv_' ) || 0 === strpos( $hook, 'rest_' ) || 0 === strpos( $hook, 'woocommerce_store_api' ) || 0 === strpos( $hook, 'woocommerce_email' ) || 0 === strpos( $hook, 'woocommerce_checkout' ) || 0 === strpos( $hook, 'woocommerce_cart' );
	}
);
check( 'no public AJAX action, no REST, no Store API, no e-mail, no checkout, no cart hook of any name', array() === $public );

echo "3. The source, file by file\n";
$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $section, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	$path = str_replace( '\\', '/', $file->getPathname() );
	if ( false !== strpos( $path, '/tests/' ) || ! preg_match( '/\.(php|js)$/', $path ) ) {
		continue;
	}
	$files[ substr( $path, strlen( $section ) ) ] = (string) file_get_contents( $path );
}
check( 'the section\'s code is read: its classes, its views and its script', count( $files ) >= 19 && isset( $files['class-packing.php'], $files['class-store.php'], $files['views/order-box.php'], $files['js/packing-admin.js'] ), (string) count( $files ) );

/**
 * The files of the section in which a pattern is found.
 */
function fh_grep( array $files, $pattern ) {
	$hits = array();
	foreach ( $files as $name => $code ) {
		if ( preg_match( $pattern, $code ) ) {
			$hits[] = $name;
		}
	}
	return $hits;
}

/**
 * The code of a PHP file without its comments (a docblock may name what the code must not do).
 */
function fh_code_only( $php ) {
	$out = '';
	foreach ( token_get_all( $php ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}
$code = array();
foreach ( $files as $name => $text ) {
	$code[ $name ] = '.php' === substr( $name, -4 ) ? fh_code_only( $text ) : $text;
}

foreach ( $storefront as $hook ) {
	if ( in_array( $hook, array( 'init' ), true ) ) {
		continue; // A common word; the hook list above already proves it is not used.
	}
	$hits = fh_grep( $code, '/[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/' );
	if ( $hits ) {
		check( 'the code does not name the hook ' . $hook, false, implode( ', ', $hits ) );
	}
}
check( 'no file names a storefront, cart, checkout, e-mail, invoice or REST hook', true );

$forbidden = array(
	'a public AJAX action'                      => '/wp_ajax_nopriv/',
	'a REST route'                              => '/register_rest_route|rest_api_init|register_rest_field/',
	'registered meta (which could reach REST)'  => '/register_meta\s*\(|register_post_meta\s*\(|show_in_rest/',
	'a shortcode or a block'                    => '/add_shortcode\s*\(|register_block_type/',
	'order-item meta written by this section'   => '/wc_add_order_item_meta|wc_update_order_item_meta|woocommerce_add_order_item_meta|->add_meta_data\s*\(/',
	'post meta functions (orders and products go through their objects)' => '/\b(get|update|add|delete)_post_meta\s*\(/',
	'an e-mail'                                 => '/wp_mail\s*\(|WC\(\)->mailer|->send\s*\(/',
	'a stock change'                            => '/wc_update_product_stock|set_stock_quantity|wc_reduce_stock|wc_increase_stock|set_stock_status/',
	'an order status change or an order note'   => '/->update_status\s*\(|->set_status\s*\(|add_order_note|wc_create_order_note/',
	'money: a refund, a fee, a total'           => '/wc_create_refund|->set_total\s*\(|->calculate_totals\s*\(|->add_fee\s*\(|->set_price\s*\(|->set_regular_price\s*\(/',
	'an attribute, a term or a variation made'  => '/wc_create_attribute|wp_insert_term|wp_set_object_terms|register_taxonomy|->set_attributes\s*\(|WC_Product_Variation\s*\(|wp_insert_post|wp_update_post/',
	'a request to another server'               => '/wp_remote_|curl_init|file_get_contents\s*\(\s*[\'"]https?:/',
	'a cron event'                              => '/wp_schedule_|wp_next_scheduled/',
	'a table created or changed'                => '/dbDelta|CREATE TABLE|ALTER TABLE|DROP TABLE/i',
);
foreach ( $forbidden as $label => $pattern ) {
	$hits = fh_grep( $code, $pattern );
	check( 'no ' . $label, array() === $hits, implode( ', ', $hits ) );
}

check( '$wpdb is touched by the Store and by no other file', array( 'class-store.php' ) === fh_grep( $code, '/\$wpdb/' ), implode( ', ', fh_grep( $code, '/\$wpdb/' ) ) );
check( 'and the Store only reads: no INSERT, UPDATE, DELETE or REPLACE, no ->query(), ->insert(), ->update(), ->delete(), ->replace()', 0 === preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b|->(query|insert|update|delete|replace)\s*\(/', $code['class-store.php'] ) && 1 === preg_match( '/\bSELECT\b/', $code['class-store.php'] ) );

$meta_writes = array();
foreach ( $code as $name => $text ) {
	if ( preg_match_all( '/->(update_meta_data|delete_meta_data)\s*\(\s*([^,)]+)/', $text, $matches ) ) {
		foreach ( $matches[2] as $key ) {
			$meta_writes[ trim( $key ) ][] = $name;
		}
	}
}
ksort( $meta_writes );
check_same( 'the only meta ever written: the bag size and the "packed in a bag" choice on a product, the confirmed box (and its name) on an order', array( 'Packing::META_BAG', 'Packing::META_BOX', 'Packing::META_BOX_NAME', 'Packing::META_PACKS' ), array_keys( $meta_writes ) );
check( 'the bag size is written by the product editor and the fill tool only; the box by the order packing only', array( 'class-fill.php', 'class-product.php' ) === array_values( array_unique( $meta_writes['Packing::META_BAG'] ) ) && array( 'class-order-packing.php' ) === array_values( array_unique( $meta_writes['Packing::META_BOX'] ) ), fh_test_export( $meta_writes ) );
check( 'the "packed in a bag" choice is written by the product editor only — the fill tool, the order screen and the contracts only read it', array( 'class-product.php' ) === array_values( array_unique( $meta_writes['Packing::META_PACKS'] ) ), fh_test_export( $meta_writes ) );
$names_key = array_values( array_filter( array_keys( $code ), static function ( $name ) use ( $code ) { return false !== strpos( $code[ $name ], '_fh_packs_in_bag' ); } ) );
check( 'the key itself is spelled in one place, the section\'s constant: no view, no script and no other class names it', array( 'class-packing.php' ) === $names_key, implode( ', ', $names_key ) );
$views = array_values( array_filter( array_keys( $code ), static function ( $name ) use ( $code ) { return 0 === strpos( $name, 'views/' ) && ( false !== strpos( $code[ $name ], 'META_PACKS' ) || false !== strpos( $code[ $name ], 'fh_packs_in_bag' ) ); } ) );
check( 'no view reads the choice or draws the checkbox: it is drawn in the product editor\'s Shipping tab and nowhere else', array() === $views && 1 === preg_match( '/woocommerce_wp_checkbox/', $code['class-product.php'] ) && array( 'class-product.php' ) === fh_grep( $code, '/woocommerce_wp_checkbox/' ), implode( ', ', $views ) );

$handlers = fh_grep( $code, '/wp_ajax_/' );
sort( $handlers );
check( 'the admin AJAX actions are registered in three files, each behind Settings::guard() or Order_Packing::confirm_request()', array( 'class-fill.php', 'class-order-box.php', 'class-settings.php' ) === $handlers && 1 === preg_match( '/current_user_can\( Packing::CAPABILITY \)/', $code['class-settings.php'] ) && 1 === preg_match( '/wp_verify_nonce/', $code['class-settings.php'] ) && 1 === preg_match( '/Settings::guard\( \$raw, self::NONCE_ACTION \)/', $code['class-order-packing.php'] ) && 2 === preg_match_all( '/Settings::guard\( \$raw, self::NONCE_ACTION \)/', $code['class-fill.php'] ) );
check( 'every view and class file refuses to be opened directly', array() === array_values( array_filter( array_keys( $files ), static function ( $name ) use ( $files ) { return '.php' === substr( $name, -4 ) && false === strpos( $files[ $name ], "defined( 'ABSPATH' ) || exit;" ); } ) ) );
check( 'the script talks to admin-ajax.php only', 0 === preg_match( '/wp-json|\/wc\/store|fetch\s*\(/', $files['js/packing-admin.js'] ) );

echo "4. The meta keys are hidden, and reading writes nothing\n";
check( 'the bag size is hidden meta: the underscore keeps it out of every list of item or product data WooCommerce formats for a customer, and out of the Custom Fields box', '_fh_bag_size' === Packing::META_BAG && '_' === Packing::META_BAG[0] );
check( 'so is the "Livestock — packed in a bag" choice: hidden product meta, on the parent product — never on a variation, never on an order line', '_fh_packs_in_bag' === Packing::META_PACKS && '_' === Packing::META_PACKS[0] );
check( 'the confirmed box is hidden order meta', '_fh_packing_box' === Packing::META_BOX && '_fh_packing_box_name' === Packing::META_BOX_NAME );

Packing::load();
fh_test_settings();
fh_use_sizes( fh_test_sizes() );
fh_product( new Fh_Fake_Product( 100, 'variable' ) );
fh_product( new Fh_Fake_Product( 101, 'variation', array( 'pa_size' => 'small' ), array( '_fh_bag_size' => '2' ), 100 ) );
$GLOBALS['fh_terms'] = array( 100 => array( 'quarantined-fish' ) );
$item                = new Fh_Fake_Item( 1, 'Invented Goby - Small', 2, 100, array( 'pa_size' => 'small' ), 101 );
$order               = fh_order( new Fh_Fake_Order( 700, array( $item ) ) );
$writes              = count( $GLOBALS['fh_option_writes'] );
$bag                 = fishotel_packing_bag_for_item( $item );
$suggestion          = fishotel_packing_suggest( 700 );
check( 'the contracts answer: the line\'s bag and the order\'s box', 'Medium' === $bag['name'] && 'fits' === $suggestion['status'] && 'Cube 8' === $suggestion['boxes'][0]['name'] );
check( 'nothing was added to the order line: its meta is what WooCommerce put there', array() === $item->writes && array( 'pa_size' => 'small' ) === $item->meta );
check( 'nothing was written to the order, the product or the options', array() === $order->writes() && 0 === wc_get_product( 101 )->meta_saves && null === wc_get_product( 101 )->pending && count( $GLOBALS['fh_option_writes'] ) === $writes );
check( 'the line\'s own data — what WooCommerce prints from — does not contain the bag in any form', false === strpos( wp_json_encode( $item->get_meta_data() ), 'bag' ) && false === strpos( wp_json_encode( $item->get_meta_data() ), 'Medium' ) );

// The same with the checkbox stored on the product — ticked, then unticked.
foreach ( array( 'yes', 'no' ) as $fh_choice ) {
	wc_get_product( 100 )->meta[ Packing::META_PACKS ] = $fh_choice;
	$bag        = fishotel_packing_bag_for_item( $item );
	$by_product = fishotel_packing_bag_for_product( 101 );
	$suggestion = fishotel_packing_suggest( 700 );
	$answered   = 'yes' === $fh_choice
		? ( 'Medium' === $bag['name'] && 'Medium' === $by_product['name'] && 'fits' === $suggestion['status'] && array() === $suggestion['ignored'] )
		: ( null === $bag && null === $by_product && 'empty' === $suggestion['status'] && array( 'Invented Goby - Small' ) === array_column( $suggestion['ignored'], 'name' ) );
	check( 'with "Livestock — packed in a bag" stored as ' . $fh_choice . ' on the product the contracts answer by it', $answered, fh_test_export( array( $bag, $suggestion['status'], $suggestion['ignored'] ) ) );
	$line_data = (string) wp_json_encode( $item->get_meta_data() );
	check( '— and nothing was written anywhere: not to the line, the order, the product, the variation or the options', array() === $item->writes && array( 'pa_size' => 'small' ) === $item->meta && array() === $order->writes() && 0 === wc_get_product( 100 )->meta_saves && null === wc_get_product( 100 )->pending && 0 === wc_get_product( 101 )->meta_saves && null === wc_get_product( 101 )->pending && count( $GLOBALS['fh_option_writes'] ) === $writes );
	check( '— the line\'s own data says nothing of it: no key, no "packed in a bag", no "livestock"', false === stripos( $line_data, 'packs' ) && false === stripos( $line_data, 'bag' ) && false === stripos( $line_data, 'livestock' ) );
	check( '— the choice sits on the parent product alone: the variation a customer buys carries no such meta', ! array_key_exists( Packing::META_PACKS, wc_get_product( 101 )->meta ) && array( '_fh_bag_size' => '2' ) === wc_get_product( 101 )->meta );
}
unset( wc_get_product( 100 )->meta[ Packing::META_PACKS ] );

echo "5. The section itself\n";
$info = Packing::get_section_info();
check( 'the Section Manager gets a name, a description and an icon', 'Packing' === $info['name'] && '' !== $info['description'] && 0 === strpos( $info['icon'], 'dashicons-' ) );
foreach ( array( 'get_section_info', 'load', 'boot', 'register_menu', 'activate', 'deactivate' ) as $method ) {
	$reflection = new ReflectionMethod( Packing::class, $method );
	if ( ! $reflection->isStatic() || ! $reflection->isPublic() ) {
		check( $method . '() is public and static', false );
	}
}
check( 'the six lifecycle methods are public and static', true );
Packing::register_menu();
check( 'its page sits under FisHotel Tools, for users who can manage WooCommerce', array( array( 'parent' => 'fishotel-misc', 'page_title' => 'Packing', 'menu_title' => 'Packing', 'capability' => 'manage_woocommerce', 'slug' => 'fishotel-misc-packing' ) ) === $GLOBALS['fh_menus'] );
$writes = count( $GLOBALS['fh_option_writes'] );
Packing::activate();
Packing::deactivate();
check( 'activating and deactivating the plugin install nothing and remove nothing', count( $GLOBALS['fh_option_writes'] ) === $writes && array() !== $GLOBALS['fh_options'] );
check( 'the settings option is the only option the section ever wrote, without autoload', array( array( Packing::OPTION_KEY, false ) ) === array_values( array_unique( $GLOBALS['fh_option_writes'], SORT_REGULAR ) ) );

fh_finish();
