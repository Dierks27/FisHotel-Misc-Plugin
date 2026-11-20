<?php
/**
 * The supplier order screen (views/order.php).
 *
 * Read as text: the "Goes on sale" card sits under the order's own
 * title, inside the order form, while its date and buttons still post to
 * their own form (an HTML form cannot sit in another, so they name it
 * with form="…"). The order's Save gathers only its own fields, so the
 * sale date never travels with it.
 *
 * RENDERED on the in-memory store, in both views of the lines: Entry
 * shows only what is typed, in its order, Numbers only what the site
 * works out, under a panel of the order's summary — and NOTHING IS LOST
 * BY HIDING: the two renders post exactly the same fields with the same
 * values, and a Save from either leaves every stored line as it was.
 * Also: which view an order opens in and that it is remembered per user,
 * "More columns", the shop price cell (read, typed, none), the Split
 * field of a line, the offer for a fish that was split last time, bag
 * mode, the row template, and the widths that let a whole Entry row fit
 * a 1280-px window.
 *
 * Every supplier, fish, code and price here is invented.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/order-view-test.php
 *
 * With --harness=<dir> (and optionally --jquery=<file>) it also writes
 * static copies of the page into that folder — entry.html, numbers.html,
 * bag.html — for a look in a real browser; tests/README.md says how the
 * 1280-px check is run with them.
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Order_Import;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Splits;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

// The template helpers the view echoes with (wp-stubs.php has the returning ones).
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo esc_html( $text );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = null ) {
		echo esc_attr( $text );
	}
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
/** selected() / checked() / disabled(): the attribute when the two values are the same. */
function fh_form_attribute( $name, $a, $b, $echo ) {
	$out = (string) $a === (string) $b ? ' ' . $name . '="' . $name . '"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute.
	}
	return $out;
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'selected', $a, $b, $echo );
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'checked', $a, $b, $echo );
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $echo = true ) {
		return fh_form_attribute( 'disabled', $a, $b, $echo );
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}
}

$fh_src = (string) file_get_contents( dirname( __DIR__ ) . '/views/order.php' );

echo "1. Goes on sale sits under the order's title\n";

$fh_sale_form  = strpos( $fh_src, '<form id="fh-cr-sale-form"' );
$fh_order_form = strpos( $fh_src, '<form id="fh-cr-order"' );
$fh_title      = strpos( $fh_src, 'class="fh-cr-order__title"' );
$fh_card       = strpos( $fh_src, 'class="fishotel-misc-card fh-cr-card fh-cr-sale"' );
$fh_head       = strpos( $fh_src, 'class="fishotel-misc-card fh-cr-card fh-cr-order__head"' );

check( 'every piece is there', false !== $fh_sale_form && false !== $fh_order_form && false !== $fh_title && false !== $fh_card && false !== $fh_head );
check( 'the card comes after the order\'s title and before its figures', $fh_title < $fh_card && $fh_card < $fh_head );
$fh_between = substr( $fh_src, $fh_sale_form, $fh_order_form - $fh_sale_form );
check( 'the sale form is closed before the order form opens (no form inside a form)', false !== strpos( $fh_between, '</form>' ) && 1 === substr_count( $fh_between, '<form' ) );
check( '… and carries the nonce, the action and the order', false !== strpos( $fh_between, 'wp_nonce_field( $batch[\'nonce_action\'] )' ) && false !== strpos( $fh_between, 'Admin_Workbook::SALE_DATE_ACTION' ) && false !== strpos( $fh_between, 'name="order_id"' ) );
$fh_card_src = substr( $fh_src, $fh_card, $fh_head - $fh_card );
check( 'the date, Save and Clear post to the sale form', 1 === substr_count( $fh_card_src, 'name="sale_date"' ) && 3 === substr_count( $fh_card_src, 'form="fh-cr-sale-form"' ) && 1 === preg_match( '/<input[^>]*form="fh-cr-sale-form"[^>]*name="sale_date"/', $fh_card_src ) );
check( 'the card itself is no form', false === strpos( $fh_card_src, '<form' ) && false === strpos( $fh_card_src, '</form>' ) );
check( 'the sale form and the card are shown for a saved order with a batch only', 2 === substr_count( $fh_src, '<?php if ( ! $is_new && ! empty( $batch ) ) : ?>' ) );

/* ------------------------------------------------------------------ */
/*  The page, rendered                                                 */
/* ------------------------------------------------------------------ */

update_option( Clean_Room::OPTION_KEY, array( 'size_list' => array( 'Small', 'Medium', 'Large' ) ) );
$today    = '2026-03-02';
$settings = Settings::get();
$GLOBALS['fh_current_user'] = 1; // The user the page is drawn for.

function fh_store() {
	$store = new Fake_Store( array( 12 => 'Copper Test Butterfly', 13 => 'Lilac Test Anthias', 14 => 'Plain Test Damsel' ) );
	$store->product_prices = array(
		12 => array( 'prices' => array( 60.0 ), 'variations' => array() ),
		13 => array( 'prices' => array( 30.0 ), 'variations' => array() ),
	);
	$store->save_species( Species::normalize( array( 'name' => 'Copper Test Butterfly', 'sci_name' => 'Testodon cupreus', 'per_box' => 6, 'product_id' => 12 ) ) ); // 1
	$store->save_species( Species::normalize( array( 'name' => 'Lilac Test Anthias', 'sci_name' => 'Testias lilacinus', 'per_box' => 24, 'product_id' => 13 ) ) );  // 2
	return $store;
}

function fh_tank( Fake_Store $store, $number ) {
	return (int) $store->tank_by_number( $number )['id'];
}

/** The page as wp-admin draws it for a user: Admin_Workbook::render() into a string. */
function fh_page( Fake_Store $store, $order ) {
	global $today, $settings;
	$_GET['order'] = (string) $order;
	ob_start();
	Admin_Workbook::render(
		'arrivals',
		array(
			'store'        => $store,
			'settings'     => $settings,
			'today'        => $today,
			'tables_ok'    => true,
			'can_settings' => true,
			'labels_url'   => '',
		)
	);
	unset( $_GET['order'] );
	return (string) ob_get_clean();
}

function fh_dom( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	return new DOMXPath( $dom );
}

function fh_has_class( DOMElement $el, $class ) {
	return in_array( $class, preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ), true );
}

/**
 * What a browser posts for the order form — its successful controls, as
 * serializeArray() and FormData gather them: every named input, select
 * and textarea that is not disabled, a checkbox only when ticked, a
 * file never. Whether a control is SHOWN plays no part (a hidden column
 * is display:none, which no browser reads when it posts a form). The
 * row template is not part of the form's fields.
 *
 * @return array[] name, value — in document order.
 */
function fh_posted( DOMXPath $x ) {
	$out = array();
	foreach ( $x->query( '//form[@id="fh-cr-order"]//*[self::input or self::select or self::textarea][@name][not(ancestor::template)]' ) as $el ) {
		if ( $el->hasAttribute( 'disabled' ) ) {
			continue;
		}
		$tag  = strtolower( $el->nodeName );
		$type = strtolower( $el->getAttribute( 'type' ) );
		if ( 'input' === $tag && in_array( $type, array( 'file', 'submit', 'button', 'image', 'reset' ), true ) ) {
			continue;
		}
		if ( 'input' === $tag && in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $el->hasAttribute( 'checked' ) ) {
			continue;
		}
		if ( 'select' === $tag ) {
			$value   = null;
			$options = $x->query( './/option', $el );
			foreach ( $options as $option ) {
				if ( $option->hasAttribute( 'selected' ) ) {
					$value = $option->getAttribute( 'value' );
				}
			}
			if ( null === $value ) {
				$value = $options->length ? $options->item( 0 )->getAttribute( 'value' ) : '';
			}
		} else {
			$value = 'textarea' === $tag ? $el->textContent : $el->getAttribute( 'value' );
		}
		$out[] = array(
			'name'  => $el->getAttribute( 'name' ),
			'value' => (string) $value,
		);
	}
	return $out;
}

/** The posted fields as PHP reads them ($_POST): lines[n][field] nested. */
function fh_request( array $posted ) {
	$pairs = array();
	foreach ( $posted as $field ) {
		$pairs[] = rawurlencode( $field['name'] ) . '=' . rawurlencode( $field['value'] );
	}
	parse_str( implode( '&', $pairs ), $raw );
	return $raw;
}

/** The stored order as a Save must leave it: every line with every stored field, and the split plans. */
function fh_stored( Fake_Store $store, $order_id ) {
	$order = $store->order( $order_id );
	$lines = array();
	foreach ( $order['lines'] as $line ) {
		unset( $line['landed_cached'], $line['stay_live'], $line['stay_status'] );
		$lines[] = $line;
	}
	$header = $order;
	unset( $header['lines'], $header['updated_at'], $header['line_count'] );
	return array(
		'header' => $header,
		'lines'  => $lines,
		'plans'  => Splits::plans( array_column( $order['lines'], 'id' ) ),
	);
}

function fh_heads( DOMXPath $x, $class, $without = '' ) {
	$out = array();
	foreach ( $x->query( '//table[@id="fh-cr-lines"]/thead/tr/th' ) as $th ) {
		if ( fh_has_class( $th, $class ) && ( '' === $without || ! fh_has_class( $th, $without ) ) ) {
			$out[] = trim( $th->textContent );
		}
	}
	return $out;
}

/*
 * The order: one line with EVERY field filled (and a split planned), an
 * unknown fish, a line that only carries what an order sheet gives, and
 * a line that is already checked in.
 */
$store = fh_store();
$saved = Admin_Workbook::save_order(
	$store,
	array(
		'ref'             => '',
		'supplier'        => 'Example Aquatics',
		'origin'          => 'Northland',
		'order_date'      => '2026-02-20',
		'received_date'   => '2026-03-01',
		'status'          => 'ordered',
		'cost_mode'       => 'box',
		'boxes'           => '2',
		'tariffs'         => '30',
		'domestic_flight' => '100',
		'import_fees'     => '200',
		'credit'          => '10',
		'markup'          => '4',
		'worth_threshold' => '2.75',
		'building_id'     => '5',
		'schedule'        => '14 Day CM',
		'notes'           => 'Two boxes, one short.',
		'lines'           => array(
			array( 'species' => 'Copper Test Butterfly', 'species_id' => '1', 'sci_name' => 'Testodon cupreus', 'size_slug' => 'medium', 'qty_ordered' => '8', 'qty_received' => '6', 'unit_price' => '12.5', 'qty_doa' => '1', 'box_no' => '1', 'box_pct' => '37.5', 'bags' => '3', 'per_box' => '6', 'qty_survived' => '5', 'worth' => '75', 'code' => 'TB-104', 'tank_id' => (string) fh_tank( $store, 521 ), 'split' => (string) json_encode( array( array( 2, fh_tank( $store, 521 ) ), array( 2, fh_tank( $store, 522 ) ), array( 1, fh_tank( $store, 523 ) ) ) ) ),
			array( 'species' => 'small brown wrasse', 'needs_id' => '1', 'qty_ordered' => '0', 'qty_received' => '2', 'unit_price' => '3', 'box_no' => '2', 'box_pct' => '10' ),
			array( 'species' => 'Plain Test Damsel', 'qty_ordered' => '20', 'unit_price' => '1.2291', 'code' => 'PD-7' ),
			array( 'species' => 'Lilac Test Anthias', 'species_id' => '2', 'sci_name' => 'Testias lilacinus', 'size_slug' => 'small', 'qty_ordered' => '12', 'qty_received' => '12', 'unit_price' => '4', 'box_no' => '2', 'box_pct' => '50', 'tank_id' => (string) fh_tank( $store, 513 ) ),
		),
	),
	0,
	$today
);
check( 'the order is saved', empty( $saved['error'] ) && 4 === count( $saved['line_ids'] ), json_encode( $saved ) );
$order_id = (int) $saved['order_id'];
// The anthias are checked in by hand before the page is drawn: that line is a stay.
$store->lines[ (int) $saved['line_ids'][0] ]['qty_received'] = null;
$store->lines[ (int) $saved['line_ids'][1] ]['qty_received'] = null;
Orders::check_in( $store, $store->order( $order_id ), $today, $settings, array( 'actor_id' => 1 ) );
$store->lines[ (int) $saved['line_ids'][0] ]['qty_received'] = 6;
$store->lines[ (int) $saved['line_ids'][1] ]['qty_received'] = 2;
$store->update_order_fields( $order_id, array( 'status' => 'ordered' ) );
$before = fh_stored( $store, $order_id );
check( 'one line is a stay, three are not; the first carries its plan, its code and a typed price', null !== $before['lines'][3]['stay_id'] && null === $before['lines'][0]['stay_id'] && 3 === count( $before['plans'][ (int) $saved['line_ids'][0] ] ) && 'TB-104' === Order_Import::code_of( $before['lines'][0]['notes'] ) && 75.0 === $before['lines'][0]['worth'] );

echo "2. Entry: only what is typed, in its order\n";
$GLOBALS['fh_user_meta'] = array();
$entry_html = fh_page( $store, $order_id );
$entry      = fh_dom( $entry_html );
$form       = $entry->query( '//form[@id="fh-cr-order"]' )->item( 0 );
check( 'an order being worked on opens in Entry', 'entry' === $form->getAttribute( 'data-view' ) && 'working' === $form->getAttribute( 'data-stage' ) && '0' === $form->getAttribute( 'data-more' ) && 'box' === $form->getAttribute( 'data-mode' ) );
check( 'two big tabs above the lines, Entry the one that is on', array( 'entry', 'numbers' ) === array_map( static function ( $el ) { return $el->getAttribute( 'data-view' ); }, iterator_to_array( $entry->query( '//div[@id="fh-cr-views"]//button[contains(@class,"fh-cr-view-tab")]' ) ) ) && 1 === $entry->query( '//button[contains(@class,"fh-cr-view-tab") and contains(@class,"is-on") and @data-view="entry" and @aria-selected="true"]' )->length );
check( 'Entry\'s columns, in order: Fish · Sci name · Size · Ordered · Received · Cost · DOA · Box # · % of box · Bags · Tank', array( 'Sci name', 'Size', 'Ordered', 'Received', 'Cost', 'DOA', 'Box #', '% of box', 'Bags', 'Tank', '' ) === fh_heads( $entry, 'fh-cr-c-entry' ) && '#' === trim( $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th[1]' )->item( 0 )->textContent ) && 'Fish' === trim( $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th[2]' )->item( 0 )->textContent ), json_encode( fh_heads( $entry, 'fh-cr-c-entry' ) ) );
$mode = array();
foreach ( $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th' ) as $th ) {
	if ( fh_has_class( $th, 'fh-cr-col--bags' ) || fh_has_class( $th, 'fh-cr-col--per-box' ) ) {
		$mode[ trim( $th->textContent ) ] = fh_has_class( $th, 'fh-cr-col--bags' ) ? 'bag' : 'box';
	}
}
check( 'Bags is the bag mode\'s column, Box #, % of box and Per box the box mode\'s — one set shows', array( 'Box #' => 'box', '% of box' => 'box', 'Bags' => 'bag', 'Per box' => 'box' ) === $mode );
check( 'behind "More columns": Per box, the typed survivors, the supplier\'s code', array( 'Per box', 'Srv\'d', 'Code' ) === fh_heads( $entry, 'fh-cr-c-more' ) && 1 === $entry->query( '//div[contains(@class,"fh-cr-views__tools")]//input[contains(@class,"fh-cr-more-toggle")][not(@checked)]' )->length );
check( '"Received: all as ordered" lives in Entry\'s head', 1 === $entry->query( '//div[@id="fh-cr-views"]//div[contains(@class,"fh-cr-views__tools") and contains(@class,"fh-cr-c-entry")]//button[contains(@class,"fh-cr-received-all")]' )->length && 1 === substr_count( $entry_html, 'fh-cr-received-all"' ) );
check( 'Add line and "Import lines…" are Entry\'s too', 1 === $entry->query( '//p[contains(@class,"fh-cr-actions-row")]/button[contains(@class,"fh-cr-line-add") and contains(@class,"fh-cr-c-entry")]' )->length && 1 === $entry->query( '//p[contains(@class,"fh-cr-actions-row")]/button[contains(@class,"fh-cr-import-open") and contains(@class,"fh-cr-c-entry")]' )->length );
$cols  = (int) $entry->query( '//table[@id="fh-cr-lines"]' )->item( 0 )->getAttribute( 'data-cols' );
$cells = array();
foreach ( $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")]' ) as $tr ) {
	$cells[] = $entry->query( './td', $tr )->length;
}
check( 'every row, the head and the foot have the same ' . $cols . ' cells', 26 === $cols && array( $cols ) === array_values( array_unique( $cells ) ) && 4 === count( $cells ) && $cols === $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th' )->length && $cols === $entry->query( '//table[@id="fh-cr-lines"]/tfoot/tr/th' )->length && $cols === $entry->query( '//template[@id="fh-cr-line-template"]//td' )->length );
$marked = true;
foreach ( $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")]' ) as $tr ) {
	foreach ( $entry->query( './td', $tr ) as $i => $td ) {
		$th     = $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th' )->item( $i );
		$marked = $marked && fh_has_class( $td, 'fh-cr-c-entry' ) === fh_has_class( $th, 'fh-cr-c-entry' ) && fh_has_class( $td, 'fh-cr-c-more' ) === fh_has_class( $th, 'fh-cr-c-more' ) && fh_has_class( $td, 'fh-cr-c-numbers' ) === fh_has_class( $th, 'fh-cr-c-numbers' ) && fh_has_class( $td, 'fh-cr-col--per-box' ) === fh_has_class( $th, 'fh-cr-col--per-box' ) && fh_has_class( $td, 'fh-cr-col--bags' ) === fh_has_class( $th, 'fh-cr-col--bags' );
	}
}
check( 'every cell belongs to the view its column head says (and to the same mode)', $marked );
$foot = true;
foreach ( $entry->query( '//table[@id="fh-cr-lines"]/tfoot/tr/th' ) as $i => $td ) {
	$th   = $entry->query( '//table[@id="fh-cr-lines"]/thead/tr/th' )->item( $i );
	$foot = $foot && fh_has_class( $td, 'fh-cr-c-entry' ) === fh_has_class( $th, 'fh-cr-c-entry' ) && fh_has_class( $td, 'fh-cr-c-more' ) === fh_has_class( $th, 'fh-cr-c-more' ) && fh_has_class( $td, 'fh-cr-c-numbers' ) === fh_has_class( $th, 'fh-cr-c-numbers' ) && fh_has_class( $td, 'fh-cr-col--per-box' ) === fh_has_class( $th, 'fh-cr-col--per-box' ) && fh_has_class( $td, 'fh-cr-col--bags' ) === fh_has_class( $th, 'fh-cr-col--bags' );
}
check( '… and so does the foot', $foot );

echo "3. Numbers: the fish and what the site works out, nothing to type\n";
update_user_meta( 1, Admin_Workbook::VIEW_META, Admin_Workbook::remember_view( '', array( 'stage' => 'working', 'view' => 'numbers' ) ) );
$numbers_html = fh_page( $store, $order_id );
$numbers      = fh_dom( $numbers_html );
check( 'the user who left a working order in Numbers gets Numbers', 'numbers' === $numbers->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) && 1 === $numbers->query( '//button[contains(@class,"fh-cr-view-tab") and contains(@class,"is-on") and @data-view="numbers"]' )->length );
check( 'Numbers\' columns: shop price · landed per fish · 400% · profit · margin · Worth it? · revenue · survived · live · sold', array( 'Shop price', 'Landed per fish', '400%', 'Profit', 'Margin', 'Worth it?', 'Revenue', 'Survived', 'Live', 'Sold' ) === fh_heads( $numbers, 'fh-cr-c-numbers' ), json_encode( fh_heads( $numbers, 'fh-cr-c-numbers' ) ) );
$typing = array();
foreach ( $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr/td[contains(@class,"fh-cr-c-numbers")]//*[@name]' ) as $el ) {
	$typing[ preg_replace( '/^lines\[\d+\]/', '', $el->getAttribute( 'name' ) ) ] = $el->hasAttribute( 'hidden' );
}
check( 'nothing to type in it but the price behind the pencil — closed until the pencil is clicked', array( '[worth]' => true ) === $typing && 4 === $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr/td[contains(@class,"fh-cr-shop")]/button[contains(@class,"fh-cr-worth-edit")]' )->length );
check( 'the fish is text there: its name and its size', 'Copper Test Butterfly Medium' === trim( preg_replace( '/\s+/', ' ', $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr[1]//span[contains(@class,"fh-cr-line__read")]' )->item( 0 )->textContent ) ) && 1 === $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr[1]//span[contains(@class,"fh-cr-line__read") and contains(@class,"fh-cr-c-numbers")]' )->length && 1 === $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr[1]//span[contains(@class,"fh-cr-line__name") and contains(@class,"fh-cr-c-entry")]' )->length );
$panel = array();
foreach ( $numbers->query( '//div[@id="fh-cr-numbers-panel"]//div[contains(@class,"fh-cr-kpi")]/span[contains(@class,"fh-cr-kpi__label")]' ) as $label ) {
	$panel[] = trim( $label->textContent );
}
check( 'the order\'s summary as a panel of boxes at its head', array( 'Grand total', 'Up or down', 'Revenue', 'Fill rate', 'Survival', 'DOA', 'DOA cost', 'Credit' ) === $panel && 1 === $numbers->query( '//div[@id="fh-cr-numbers-panel" and contains(@class,"fh-cr-c-numbers")]' )->length );
$kpi = static function ( $key ) use ( $numbers ) {
	return trim( $numbers->query( '//div[@id="fh-cr-numbers-panel"]//*[@data-t="' . $key . '"]' )->item( 0 )->textContent );
};
$figures = Admin_Workbook::figures( Admin_Workbook::resolve_order( $store, $store->order( $order_id ) ) );
check( '… with the order\'s own figures', $figures['order']['grand_total'] === $kpi( 'grand_total' ) && $figures['order']['up_down'] === $kpi( 'up_down' ) && $figures['order']['revenue'] === $kpi( 'revenue' ) && $figures['order']['fill_rate'] === $kpi( 'fill_rate' ) && $figures['order']['survival_rate'] === $kpi( 'survival_rate' ) && $figures['order']['doa_cost_sheet'] === $kpi( 'doa_cost_sheet' ) && $figures['order']['credit'] === $kpi( 'credit' ) && '10.00' === $kpi( 'credit' ) );
check( 'the panel says how many lines have no shop price (the unknown fish and the damsel)', '2 lines have no shop price yet: they are left out of the revenue and of "up or down".' === $kpi( 'no_price' ) && ! $numbers->query( '//div[@id="fh-cr-numbers-panel"]//*[@data-t="no_price"]' )->item( 0 )->hasAttribute( 'hidden' ) );
check( 'the worth-it rule in plain words, from the order\'s own threshold', 'Worth it when the shop price is more than 3.75× what the fish cost landed.' === $kpi( 'worth_rule' ) && 'Worth it when the shop price is more than 3.75× what the fish cost landed.' === $numbers->query( '//table[@id="fh-cr-lines"]/thead/tr/th[@data-rule="worth_rule"]' )->item( 0 )->getAttribute( 'title' ) );
$shop = static function ( $n, $what ) use ( $numbers ) {
	$td = $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")][' . $n . ']/td[contains(@class,"fh-cr-shop")]' )->item( 0 );
	if ( 'class' === $what ) {
		return $td->getAttribute( 'class' );
	}
	$span = $numbers->query( './span[@data-f="shop_price"]', $td )->item( 0 );
	return 'title' === $what ? $span->getAttribute( 'title' ) : trim( $span->textContent );
};
check( 'a typed price shows as typed, with what the shop says in its tooltip', '75.00' === $shop( 1, 'text' ) && false !== strpos( $shop( 1, 'class' ), 'is-typed' ) && false !== strpos( $shop( 1, 'title' ), 'The shop says 60.00' ) );
check( 'a read price says where it was read from', '30.00' === $shop( 4, 'text' ) && false === strpos( $shop( 4, 'class' ), 'is-typed' ) && false === strpos( $shop( 4, 'class' ), 'is-none' ) && false !== strpos( $shop( 4, 'title' ), 'Read from the shop' ) );
$none = array();
foreach ( $numbers->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")][3]/td[contains(@class,"is-none")]' ) as $td ) {
	$cell   = $td->hasAttribute( 'data-f' ) ? $td : $numbers->query( './/*[@data-f]', $td )->item( 0 );
	$none[] = $cell->getAttribute( 'data-f' ) . '=' . trim( preg_replace( '/\s+/', ' ', $cell->textContent ) );
}
check( 'a fish without a shop price says so in its shop price, profit, margin and Worth it? — never "No", never −100%', array( 'shop_price=no shop price yet', 'profit=no shop price yet', 'margin=no shop price yet', 'worth_it=no shop price yet' ) === $none, json_encode( $none ) );
check( 'the full figures of the order sit under the lines in Numbers only', 1 === $numbers->query( '//div[contains(@class,"fh-cr-order__stats") and contains(@class,"fh-cr-c-numbers")]' )->length );

echo "4. Nothing is lost by hiding\n";
$post_entry   = fh_posted( $entry );
$post_numbers = fh_posted( $numbers );
check( 'both views post the same fields with the same values, in the same order', $post_entry === $post_numbers && count( $post_entry ) > 60, count( $post_entry ) . ' / ' . count( $post_numbers ) );
$fields = array();
foreach ( $post_entry as $field ) {
	if ( 1 === preg_match( '/^lines\[(\d+)\]\[([a-z_]+)\]$/', $field['name'], $m ) ) {
		$fields[ (int) $m[1] ][] = $m[2];
	}
}
$all = array( 'id', 'species_id', 'seen', 'split', 'species', 'sci_name', 'size_slug', 'qty_ordered', 'qty_received', 'unit_price', 'qty_doa', 'box_no', 'box_pct', 'bags', 'tank_id', 'per_box', 'qty_survived', 'code', 'worth' );
check( 'a line posts every field of BOTH views: what Entry shows, what "More columns" holds, and the price behind Numbers\' pencil', $all === $fields[0] && $all === $fields[2], json_encode( $fields[0] ) );
check( '… an unknown fish its ticked "??" too', array_merge( array_slice( $all, 0, 5 ), array( 'needs_id' ), array_slice( $all, 5 ) ) === $fields[1] );
check( '… and a line that is a stay everything but what its tanks count now (received, DOA, survivors, tank) and a split', array( 'id', 'species_id', 'seen', 'species', 'sci_name', 'size_slug', 'qty_ordered', 'unit_price', 'box_no', 'box_pct', 'bags', 'per_box', 'code', 'worth' ) === $fields[3], json_encode( $fields[3] ) );
$values = array();
foreach ( $post_entry as $field ) {
	if ( 0 === strpos( $field['name'], 'lines[0]' ) ) {
		$values[ substr( $field['name'], 9, -1 ) ] = $field['value'];
	}
}
check( 'the values of the full line, hidden ones included', array( 'Copper Test Butterfly', 'Testodon cupreus', 'medium', '8', '6', '12.5000', '1', '1', '37.5', '3', (string) fh_tank( $store, 521 ), '6', '5', 'TB-104', '75.00' ) === array( $values['species'], $values['sci_name'], $values['size_slug'], $values['qty_ordered'], $values['qty_received'], $values['unit_price'], $values['qty_doa'], $values['box_no'], $values['box_pct'], $values['bags'], $values['tank_id'], $values['per_box'], $values['qty_survived'], $values['code'], $values['worth'] ), json_encode( $values ) );
check( 'the header\'s fields travel with either view', array() === array_diff( array( 'order_id', 'ref', 'supplier', 'origin', 'order_date', 'received_date', 'status', 'building_id', 'schedule', 'cost_mode', 'boxes', 'tariffs', 'domestic_flight', 'import_fees', 'credit', 'markup', 'worth_threshold', 'notes' ), array_column( $post_numbers, 'name' ) ) );

foreach ( array( 'Entry' => $post_entry, 'Numbers' => $post_numbers ) as $name => $posted ) {
	$result = Admin_Workbook::save_order( $store, fh_request( $posted ), $order_id, $today );
	check( 'Save from ' . $name . ': saved, nothing refused', empty( $result['error'] ) && 'Order saved.' === $result['message'], json_encode( $result['error'] ?? $result['message'] ) );
	$after = fh_stored( $store, $order_id );
	check( '… every stored line is as it was — every field, the notes with the code, the typed price, the stay', $before['lines'] === $after['lines'], json_encode( array_map( static function ( $a, $b ) { return array_diff_assoc( array_map( 'json_encode', $a ), array_map( 'json_encode', $b ) ); }, $before['lines'], $after['lines'] ) ) );
	check( '… and so are the order\'s own fields and the split planned on the first line', $before['header'] === $after['header'] && $before['plans'] === $after['plans'], json_encode( array( $before['plans'], $after['plans'] ) ) );
}
// The same through the one field the page really sends (lines_json), from Numbers.
$packed = array();
$rows   = array();
foreach ( $post_numbers as $field ) {
	if ( 1 === preg_match( '/^lines\[(\d+)\]\[([a-z_]+)\]$/', $field['name'], $m ) ) {
		$rows[ (int) $m[1] ][ $m[2] ] = $field['value'];
	} else {
		$packed[ $field['name'] ] = $field['value'];
	}
}
$packed['lines_json'] = (string) json_encode( array_values( $rows ) );
$result               = Admin_Workbook::save_order( $store, Admin_Workbook::unpack_lines( $packed ), $order_id, $today );
check( 'the lines packed into one field, as the page posts them: the same', empty( $result['error'] ) && fh_stored( $store, $order_id ) === $before );
// A change typed in a column that is hidden in the view the Save comes from still arrives.
$changed = $post_numbers;
foreach ( $changed as $i => $field ) {
	if ( 'lines[2][qty_received]' === $field['name'] ) {
		$changed[ $i ]['value'] = '14';
	}
	if ( 'lines[2][code]' === $field['name'] ) {
		$changed[ $i ]['value'] = 'PD-8';
	}
	if ( 'lines[0][worth]' === $field['name'] ) {
		$changed[ $i ]['value'] = '';
	}
}
Admin_Workbook::save_order( $store, fh_request( $changed ), $order_id, $today );
$after = fh_stored( $store, $order_id );
check( 'what was typed in one view is saved from the other: a received count, a code, a price emptied', 14 === $after['lines'][2]['qty_received'] && 'PD-8' === Order_Import::code_of( $after['lines'][2]['notes'] ) && null === $after['lines'][0]['worth'] && $before['lines'][1] === $after['lines'][1] && $before['lines'][3] === $after['lines'][3] );
Admin_Workbook::save_order( $store, fh_request( $post_entry ), $order_id, $today );
check( '… and a Save of the page as it was puts it back', fh_stored( $store, $order_id ) === $before );

echo "5. Which view an order opens in, remembered per user\n";
$draft  = array( 'status' => 'draft', 'lines' => array() );
$recv   = array( 'status' => 'received', 'lines' => array( array( 'stay_id' => null, 'qty_received' => 4 ), array( 'stay_id' => 9, 'qty_received' => 2 ) ) );
$done   = array( 'status' => 'received', 'lines' => array( array( 'stay_id' => 9, 'qty_received' => 2 ), array( 'stay_id' => null, 'qty_received' => 0 ) ) );
$closed = array( 'status' => 'closed', 'lines' => array() );
check( 'a draft, an ordered order and one still being received are worked on; one that is all checked in, or closed, is done', 'working' === Admin_Workbook::order_stage( $draft ) && 'working' === Admin_Workbook::order_stage( array( 'status' => 'ordered', 'lines' => array() ) ) && 'working' === Admin_Workbook::order_stage( $recv ) && 'done' === Admin_Workbook::order_stage( $done ) && 'done' === Admin_Workbook::order_stage( $closed ) && 'working' === Admin_Workbook::order_stage( array( 'status' => 'received', 'lines' => array() ) ) );
$fresh = Admin_Workbook::view_prefs( '' );
check( 'by default: Entry while it is worked on, Numbers once it is done, "More columns" off', array( 'working' => 'entry', 'done' => 'numbers', 'more' => false ) === $fresh && 'entry' === Admin_Workbook::order_view( $draft, $fresh )['view'] && 'entry' === Admin_Workbook::order_view( $recv, $fresh )['view'] && 'numbers' === Admin_Workbook::order_view( $done, $fresh )['view'] && 'numbers' === Admin_Workbook::order_view( $closed, $fresh )['view'] );
$mine = Admin_Workbook::remember_view( $fresh, array( 'stage' => 'done', 'view' => 'entry' ) );
check( 'a switch is remembered for orders of that stage only', array( 'working' => 'entry', 'done' => 'entry', 'more' => false ) === $mine && 'entry' === Admin_Workbook::order_view( $closed, $mine )['view'] );
$mine = Admin_Workbook::remember_view( $mine, array( 'stage' => 'working', 'more' => '1' ) );
check( '"More columns" is remembered too, and leaves the views alone', array( 'working' => 'entry', 'done' => 'entry', 'more' => true ) === $mine && false === Admin_Workbook::remember_view( $mine, array( 'more' => '0' ) )['more'] );
check( 'anything else that is posted or stored is the default', $fresh === Admin_Workbook::view_prefs( array( 'working' => 'sideways', 'done' => array( 'x' ), 'more' => 0, 'extra' => '<b>' ) ) && $fresh === Admin_Workbook::remember_view( 'junk', array( 'stage' => '<script>', 'view' => 'everything' ) ) );
update_user_meta( 1, Admin_Workbook::VIEW_META, $mine );
update_user_meta( 2, Admin_Workbook::VIEW_META, Admin_Workbook::remember_view( '', array( 'stage' => 'working', 'view' => 'numbers' ) ) );
$more = fh_dom( fh_page( $store, $order_id ) );
check( 'the page draws what THIS user left it in (Entry, More columns on) — another user\'s choice is theirs', 'entry' === $more->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) && '1' === $more->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-more' ) && 1 === $more->query( '//input[contains(@class,"fh-cr-more-toggle")][@checked]' )->length && 'numbers' === Admin_Workbook::order_view( $draft, Admin_Workbook::view_prefs( get_user_meta( 2, Admin_Workbook::VIEW_META, true ) ) )['view'] );
check( '… with the same fields posted as ever', fh_posted( $more ) === $post_entry );
// A link may ask for a view for one page load: "Import lines" sends the page back to Entry.
$numbers_user = array( 'view' => 'numbers', 'stage' => 'working', 'more' => false );
check( 'view=entry (what an import comes back with) and import=1 draw Entry, whatever is remembered — and store nothing', 'entry' === Admin_Workbook::view_asked( $numbers_user, array( 'view' => 'entry' ) )['view'] && 'entry' === Admin_Workbook::view_asked( $numbers_user, array( 'import' => '1' ) )['view'] && 'numbers' === Admin_Workbook::view_asked( array( 'view' => 'entry' ), array( 'view' => 'NUMBERS' ) )['view'] && $numbers_user === Admin_Workbook::view_asked( $numbers_user, array( 'view' => '<script>', 'import' => '0' ) ) && $numbers_user === Admin_Workbook::view_asked( $numbers_user, array( 'view' => array( 'entry' ) ) ) );
update_user_meta( 1, Admin_Workbook::VIEW_META, Admin_Workbook::remember_view( '', array( 'stage' => 'working', 'view' => 'numbers' ) ) );
$_GET['view'] = 'entry';
$landed       = fh_dom( fh_page( $store, $order_id ) );
unset( $_GET['view'] );
check( 'a user who works in Numbers still lands in Entry after an import — and is back in Numbers on the next plain load', 'entry' === $landed->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) && 'numbers' === fh_dom( fh_page( $store, $order_id ) )->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) && 'numbers' === get_user_meta( 1, Admin_Workbook::VIEW_META, true )['working'] );
$GLOBALS['fh_user_meta'] = array();
$new = fh_dom( fh_page( $store, 'new' ) );
check( 'a new order opens in Entry, with no line and the row template ready', 'entry' === $new->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) && 0 === $new->query( '//table[@id="fh-cr-lines"]/tbody/tr' )->length && 1 === $new->query( '//template[@id="fh-cr-line-template"]//input[@name="lines[__i__][species]"]' )->length );
$template = array();
foreach ( $new->query( '//template[@id="fh-cr-line-template"]//*[@name]' ) as $el ) {
	$template[] = substr( $el->getAttribute( 'name' ), 13, -1 );
}
check( 'a line added on the page has every field of both views as well', array_merge( array_slice( $all, 0, 5 ), array( 'needs_id' ), array_slice( $all, 5 ) ) === $template, json_encode( $template ) );

echo "6. Split in the Tank cell\n";
$tank_cell = $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")][1]/td[contains(@class,"fh-cr-line__tank")]' )->item( 0 );
check( 'a line that is no stay yet: its tank, and a small Split button', 1 === $entry->query( './select[contains(@name,"[tank_id]")]', $tank_cell )->length && 1 === $entry->query( './button[contains(@class,"fh-cr-split-open")]', $tank_cell )->length && 'Split' === trim( $entry->query( './button[contains(@class,"fh-cr-split-open")]', $tank_cell )->item( 0 )->textContent ) && fh_has_class( $tank_cell, 'fh-cr-c-entry' ) );
check( 'the plan travels in the line\'s hidden field: [[count, tank id], …]', (string) json_encode( array( array( 2, fh_tank( $store, 521 ) ), array( 2, fh_tank( $store, 522 ) ), array( 1, fh_tank( $store, 523 ) ) ) ) === $values['split'] && 1 === $entry->query( '//input[@type="hidden" and contains(@class,"fh-cr-split-field") and @name="lines[0][split]"]' )->length );
check( 'a line that is a stay has no Split and no field for one: that group is split from its tank', 0 === $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"is-linked")]//button[contains(@class,"fh-cr-split-open")]' )->length && 0 === $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"is-linked")]//input[contains(@class,"fh-cr-split-field")]' )->length && '513' === trim( $entry->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"is-linked")]/td[contains(@class,"fh-cr-line__tank")]' )->item( 0 )->textContent ) );
$tanks = json_decode( $entry->query( '//table[@id="fh-cr-lines"]' )->item( 0 )->getAttribute( 'data-split-tanks' ), true );
check( 'the page knows the tanks a part may go into — Spa tanks, the empty ones first, the one with fish last', is_array( $tanks ) && 9 === count( $tanks ) && array( 'spa' ) === array_values( array_unique( array_column( $tanks, 'area' ) ) ) && 513 === $tanks[8]['number'] && false === $tanks[8]['empty'] && array( 511, 512, 521, 522, 523, 524, 611, 621 ) === array_slice( array_column( $tanks, 'number' ), 0, 8 ), json_encode( array_column( (array) $tanks, 'number' ) ) );
check( 'nothing is offered on a fish that was never split', 0 === $entry->query( '//tr[@data-split-offer]' )->length );
Splits::remember( array( 'species' => 'Plain Test Damsel' ), true, true, $today );
Splits::remember( array( 'species_id' => 2, 'species' => 'Lilac Test Anthias' ), true, false, $today );
$offer = fh_dom( fh_page( $store, $order_id ) );
check( 'a fish that was split one per tank last time: its line offers it — and only a line that is no stay and has no plan', 1 === $offer->query( '//tr[@data-split-offer]' )->length && 'ones' === $offer->query( '//table[@id="fh-cr-lines"]/tbody/tr[contains(@class,"fh-cr-line")][3]' )->item( 0 )->getAttribute( 'data-split-offer' ) );
check( 'an offer is no plan: the field is empty until the page lays it out in front of the owner', '' === $offer->query( '//input[@name="lines[2][split]"]' )->item( 0 )->getAttribute( 'value' ) && fh_posted( $offer ) === $post_entry );
Splits::keep( array( (int) $saved['line_ids'][2] => Splits::NONE ) );
$declined = fh_dom( fh_page( $store, $order_id ) );
check( '"Keep together" said once: the line posts it and is not offered the split again', 'none' === $declined->query( '//input[@name="lines[2][split]"]' )->item( 0 )->getAttribute( 'value' ) && 0 === $declined->query( '//tr[@data-split-offer]' )->length );
Splits::keep( array( (int) $saved['line_ids'][2] => null ) );
Splits::remember( array( 'species' => 'Plain Test Damsel' ), false, false, $today );
Splits::remember( array( 'species_id' => 2, 'species' => 'Lilac Test Anthias' ), false, false, $today );

echo "7. Bag mode\n";
$store->update_order_fields( $order_id, array( 'cost_mode' => 'bag' ) );
$GLOBALS['fh_user_meta'] = array();
$bag_html = fh_page( $store, $order_id );
$bag      = fh_dom( $bag_html );
check( 'a bag-mode order says so on its form: the CSS then shows Bags in place of Box # and % of box', 'bag' === $bag->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-mode' ) && 'entry' === $bag->query( '//form[@id="fh-cr-order"]' )->item( 0 )->getAttribute( 'data-view' ) );
$bag_post = fh_posted( $bag );
check( 'it posts the same line fields — the bags and the hidden box columns alike', array_column( $bag_post, 'name' ) === array_column( $post_entry, 'name' ) && '3' === fh_request( $bag_post )['lines'][0]['bags'] && '37.5' === fh_request( $bag_post )['lines'][0]['box_pct'] );
// (A bag-mode order remembers no box share — Box_Shares gives the line's back —, so that key is left out here.)
$typed = static function () use ( $store, $order_id ) {
	$stored = fh_stored( $store, $order_id );
	foreach ( $stored['lines'] as $i => $line ) {
		unset( $stored['lines'][ $i ]['share_key'] );
	}
	return $stored;
};
$bag_before = $typed();
$result     = Admin_Workbook::save_order( $store, fh_request( $bag_post ), $order_id, $today );
check( 'a Save in bag mode leaves every line as it was, the box number and percent it does not show included', empty( $result['error'] ) && $typed() === $bag_before && 37.5 === fh_stored( $store, $order_id )['lines'][0]['box_pct'] && 3 === fh_stored( $store, $order_id )['lines'][0]['bags'] );
$store->update_order_fields( $order_id, array( 'cost_mode' => 'box' ) );

echo "8. The stylesheet: what hides, and a row that fits a 1280-px window\n";
$css = (string) file_get_contents( dirname( __DIR__ ) . '/css/clean-room-admin.css' );
check( 'a view hides the other view\'s cells with display:none — nothing is removed from the form', 1 === preg_match( '/\.fh-cr-order\[data-view="entry"\] \.fh-cr-c-numbers,\s*\.fh-cr-order\[data-view="numbers"\] \.fh-cr-c-entry,\s*\.fh-cr-order\[data-view="numbers"\] \.fh-cr-c-more,\s*\.fh-cr-order\[data-view="entry"\]:not\(\[data-more="1"\]\) \.fh-cr-c-more \{\s*display: none;/', $css ) );
check( 'the mode\'s own columns stay hidden in either view', 2 === substr_count( $css, '.fh-cr-order[data-mode="box"] .fh-cr-col--bags,' ) && 2 === substr_count( $css, '.fh-cr-order[data-mode="bag"] .fh-cr-col--per-box {' ) );
check( 'neither the view nor the script ever disables or removes a field to hide it', false === strpos( $fh_src, 'disabled( \'numbers\'' ) && 0 === preg_match( '/\.prop\(\s*\'disabled\'[^)]*\)[^;]*fh-cr-c-/', (string) file_get_contents( dirname( __DIR__ ) . '/js/order-entry.js' ) ) && 0 === preg_match( '/fh-cr-c-(entry|numbers|more)[^\n]*\.(remove|detach)\(/', (string) file_get_contents( dirname( __DIR__ ) . '/js/order-entry.js' ) ) );
check( 'the table is laid out with fixed widths, the fish taking what is left', 1 === preg_match( '/\.fh-cr-order \.fh-cr-grid \{\s*table-layout: fixed;\s*width: 100%;/', $css ) );
$width = static function ( $class, $section ) {
	return 1 === preg_match( '/\.fh-cr-order \.fh-cr-grid \.' . preg_quote( $class, '/' ) . '\s*\{ width: (\d+)(px|%); \}/', $section, $m ) ? array( (int) $m[1], $m[2] ) : null;
};
$main  = substr( $css, 0, strpos( $css, '@media (max-width: 1100px)' ) );
$small = substr( $css, strpos( $css, '@media (max-width: 1100px)' ) );
/*
 * wp-admin at 1280 px: the menu is 160 px, the content starts 20 px in, the page keeps its margins
 * and a page that scrolls loses its scrollbar — a headless Chrome measures 1045 px for the table
 * there, and 789 px in a 1024-px window (order-view-measure.js; tests/README.md).
 */
$entry_px = static function ( $section, $fallback, $page, $with_sci ) use ( $width ) {
	$w = static function ( $class ) use ( $width, $section, $fallback ) {
		$found = $width( $class, $section );
		return null !== $found ? $found : $width( $class, $fallback );
	};
	// # · Size · Ordered · Received · Cost · DOA · Box # · % of box · Tank · ×
	$fixed = $w( 'fh-cr-w-n' )[0] + $w( 'fh-cr-w-size' )[0] + 2 * $w( 'fh-cr-w-qty' )[0] + $w( 'fh-cr-w-money' )[0] + 2 * $w( 'fh-cr-w-qty' )[0] + $w( 'fh-cr-w-pct' )[0] + $w( 'fh-cr-w-tank' )[0] + $w( 'fh-cr-w-x' )[0];
	$sci   = $with_sci ? (int) round( $page * $w( 'fh-cr-w-sci' )[0] / 100 ) : 0;
	return array(
		'fixed' => $fixed,
		'fish'  => $page - $fixed - $sci,
	);
};
$at_1280 = $entry_px( $main, $main, 1045, true );
check( 'at 1280 px the typed columns take ' . $at_1280['fixed'] . ' px and leave the fish ' . $at_1280['fish'] . ' px — a whole row, no sideways scrolling', array( 15, '%' ) === $width( 'fh-cr-w-sci', $main ) && $at_1280['fixed'] <= 700 && $at_1280['fish'] >= 200, json_encode( $at_1280 ) );
/* At 1024 px the scientific name moves behind "More columns". */
$at_1024 = $entry_px( $small, $main, 789, false );
check( 'at 1024 px the scientific name goes behind "More columns" and the fish still has ' . $at_1024['fish'] . ' px', 1 === preg_match( '/\.fh-cr-order\[data-view="entry"\]:not\(\[data-more="1"\]\) \.fh-cr-c-sci \{\s*display: none;/', $small ) && $at_1024['fish'] >= 160, json_encode( $at_1024 ) );
check( 'the number boxes are as wide as their numbers: 62 px for a count (and its column\'s name), 82 px for a price, no spin buttons', array( 62, 'px' ) === $width( 'fh-cr-w-qty', $main ) && array( 82, 'px' ) === $width( 'fh-cr-w-money', $main ) && false !== strpos( $css, '.fh-cr-order .fh-cr-grid input[type="number"]::-webkit-inner-spin-button' ) && 1 === preg_match( '/\.fh-cr-order \.fh-cr-grid \.fh-cr-input \{[^}]*width: 100%;[^}]*min-width: 0;/', $css ) );
check( '"More columns" and Numbers may be wider than a small window — they say so and scroll', 1 === preg_match( '/\[data-view="entry"\]\[data-more="1"\] \.fh-cr-grid \{\s*min-width: \d+px;/', $css ) && 1 === preg_match( '/\[data-view="numbers"\] \.fh-cr-grid \{\s*min-width: \d+px;/', $css ) && false !== strpos( $css, ".fh-cr-grid-wrap {\n\toverflow-x: auto;" ) );

/* ------------------------------------------------------------------ */
/*  The harness: static copies of the page for a real browser          */
/* ------------------------------------------------------------------ */
$fh_opts = getopt( '', array( 'harness:', 'jquery:' ) );
if ( ! empty( $fh_opts['harness'] ) && is_dir( (string) $fh_opts['harness'] ) ) {
	$dir     = rtrim( (string) $fh_opts['harness'], '/' );
	$section = dirname( __DIR__ );
	$jquery  = ! empty( $fh_opts['jquery'] ) && is_file( (string) $fh_opts['jquery'] ) ? (string) $fh_opts['jquery'] : '';
	$wrap    = static function ( $body ) use ( $section, $jquery ) {
		$config = array(
			'ajaxUrl' => '',
			'nonce'   => 'test',
			'i18n'    => array( 'box' => Admin_Workbook::box_strings() ),
		);
		return '<!doctype html><html><head><meta charset="utf-8"><title>order page</title>'
			// wp-admin's frame, as far as the width goes: a 160-px menu, the content 20 px in, .wrap's margins.
			. '<style>html{background:#1a1a1a;color:#e0e0e0;font:13px/1.4 sans-serif}body{margin:0}#adminmenu{position:fixed;left:0;top:0;bottom:0;width:160px;background:#111}#wpcontent{margin-left:160px;padding-left:20px}.wrap{margin:10px 20px 0 2px}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}.widefat{border-spacing:0;width:100%}.button{font-size:13px;line-height:2;padding:0 10px}.button-small{font-size:11px;padding:0 8px}.button-link{border:0;background:none;padding:0}input,select{font-size:13px}</style>'
			. '<link rel="stylesheet" href="file://' . FISHOTEL_MISC_PATH . 'admin/css/admin-style.css">'
			. '<link rel="stylesheet" href="file://' . $section . '/css/clean-room-admin.css">'
			. '<link rel="stylesheet" href="file://' . $section . '/css/order-import.css">'
			. '</head><body><div id="adminmenu"></div><div id="wpcontent">' . $body . '</div>'
			. ( '' !== $jquery
				? '<script src="file://' . $jquery . '"></script><script>window.fhCleanRoomAdmin=' . wp_json_encode( $config ) . ';window.fhCleanRoomEntry=' . wp_json_encode( Admin_Workbook::entry_js_config() ) . ';window.fhErrors=[];window.onerror=function(m){window.fhErrors.push(String(m));};</script>'
					. '<script src="file://' . $section . '/js/clean-room-admin.js"></script><script src="file://' . $section . '/js/order-entry.js"></script>'
				: '' )
			. '<script src="file://' . __DIR__ . '/order-view-measure.js"></script>'
			. '</body></html>';
	};
	// A longer order, so the row that has to fit is not one lucky row: the first order's lines, three times over.
	$long = fh_store();
	$rows = array();
	foreach ( array( 'Copper Test Butterfly', 'Lilac Test Anthias', 'Plain Test Damsel', 'Long-named Spotted Sample Blenny of the Northern Reef', 'small brown wrasse' ) as $n => $name ) {
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array( 'species' => $name . ( $i ? ' ' . ( $i + 1 ) : '' ), 'sci_name' => 'Testodon exemplaris longissimus', 'size_slug' => 'medium', 'qty_ordered' => '120', 'qty_received' => '104', 'unit_price' => '112.5000', 'qty_doa' => '11', 'box_no' => '12', 'box_pct' => '137.5', 'bags' => '14', 'per_box' => '24', 'code' => 'TB-10' . $n, 'needs_id' => 4 === $n ? '1' : '' );
		}
	}
	$long_saved = Admin_Workbook::save_order( $long, array( 'ref' => '', 'supplier' => 'Example Aquatics', 'origin' => 'Northland', 'status' => 'ordered', 'cost_mode' => 'box', 'tariffs' => '30', 'domestic_flight' => '100', 'import_fees' => '200', 'building_id' => '5', 'lines' => $rows ), 0, $today );
	Splits::remember( array( 'species' => 'Copper Test Butterfly' ), true, true, $today );
	$GLOBALS['fh_user_meta'] = array();
	file_put_contents( $dir . '/entry.html', $wrap( fh_page( $long, (int) $long_saved['order_id'] ) ) );
	update_user_meta( 1, Admin_Workbook::VIEW_META, Admin_Workbook::remember_view( '', array( 'stage' => 'working', 'view' => 'numbers' ) ) );
	file_put_contents( $dir . '/numbers.html', $wrap( fh_page( $long, (int) $long_saved['order_id'] ) ) );
	$GLOBALS['fh_user_meta'] = array();
	$long->update_order_fields( (int) $long_saved['order_id'], array( 'cost_mode' => 'bag' ) );
	file_put_contents( $dir . '/bag.html', $wrap( fh_page( $long, (int) $long_saved['order_id'] ) ) );
	Splits::remember( array( 'species' => 'Copper Test Butterfly' ), false, false, $today );
	echo "\nHarness written to $dir (entry.html, numbers.html, bag.html)\n";
}

fh_finish();
