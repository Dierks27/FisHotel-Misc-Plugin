<?php
/**
 * Layout tests — the menu order, the Dashboard's headings and the card
 * sentences. Pure: no WordPress.
 *
 * Run from the plugin root:
 *   php tests/layout-test.php
 *
 * @package FisHotel\Misc
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) {
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-layout.php';

use FisHotel\Misc\Layout;

$fh_checks   = 0;
$fh_failures = 0;

function check( $label, $condition, $detail = '' ) {
	global $fh_checks, $fh_failures;
	$fh_checks++;
	if ( $condition ) {
		echo "  ok   $label\n";
		return;
	}
	$fh_failures++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}

// Every section folder, in the order the Section Manager finds them (glob = alphabetical).
$fh_folders = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/sections/*', GLOB_ONLYDIR ) as $fh_dir ) {
	if ( is_file( $fh_dir . '/class-' . basename( $fh_dir ) . '.php' ) ) {
		$fh_folders[] = basename( $fh_dir );
	}
}
$fh_live = array( 'announcer', 'coming-soon', 'lottery-draw', 'performance', 'product-archive', 'visitor-stats' ); // Misc 0.35, on the live site.

echo "The menu\n";
$fh_order = Layout::menu_order( $fh_folders );
check( 'every section is in the menu order once, none added', count( $fh_order ) === count( $fh_folders ) && array() === array_diff( $fh_folders, $fh_order ) && count( $fh_order ) === count( array_unique( $fh_order ) ) );
check(
	'Today, then Fish room, Customers (Sticker Giveaway and Gift Cards with them), Shipping, Site (Health, Snippets, Shop App), then the rest',
	array( 'today', 'clean-room', 'invoices', 'requests', 'lost-fish', 'sticker-giveaway', 'gift-cards', 'packing', 'foam-cuts', 'weather', 'health', 'site-snippets', 'shop-app' ) === array_slice( $fh_order, 0, 13 ),
	implode( ', ', $fh_order )
);
check( 'the sections live today keep their order among themselves', $fh_live === array_values( array_intersect( $fh_order, $fh_live ) ) );
check( 'with only the live sections there, the menu is exactly as it is today', $fh_live === Layout::menu_order( $fh_live ) );
check( 'a section this list does not know comes after, in folder order', array( 'today', 'a-new', 'z-new' ) === Layout::menu_order( array( 'a-new', 'today', 'z-new' ) ) );
check( 'every section in the first list is a real folder', array() === array_diff( Layout::MENU_FIRST, $fh_folders ) );

$fh_manager = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-section-manager.php' );
check( 'the Section Manager registers the menus in that order', false !== strpos( $fh_manager, 'foreach ( Layout::menu_order( array_keys( $this->sections ) ) as $slug )' ) );

echo "\nThe Dashboard\n";
$fh_sections = array_fill_keys( $fh_folders, array( 'name' => 'x' ) );
$fh_groups   = Layout::grouped( $fh_sections );
check( 'Today on its own at the top, then the four headings', array( '', 'Fish room', 'Customers', 'Shipping', 'Site' ) === array_column( $fh_groups, 'title' ) );
check( 'Customers: Invoices, Requests, Lost Fish, Sticker Giveaway, Gift Cards; Shipping: Packing, Foam Cuts, Weather', array( 'invoices', 'requests', 'lost-fish', 'sticker-giveaway', 'gift-cards' ) === array_keys( $fh_groups[2]['sections'] ) && array( 'packing', 'foam-cuts', 'weather' ) === array_keys( $fh_groups[3]['sections'] ) );
check( 'Site: Health, Snippets, Shop App, then the rest — every card once', 'health' === array_keys( $fh_groups[4]['sections'] )[0] && count( $fh_folders ) === array_sum( array_map( 'count', array_column( $fh_groups, 'sections' ) ) ) );
check( 'a heading with no section is not shown', array( 'Customers' ) === array_column( Layout::grouped( array( 'requests' => 1 ) ), 'title' ) );

echo "\nThe cards\n";
foreach ( $fh_folders as $fh_slug ) {
	$fh_text = Layout::card_text( $fh_slug );
	check( "$fh_slug: one plain sentence that says what switching it on starts", '' !== $fh_text && strlen( $fh_text ) < 420 && ( false !== stripos( $fh_text, 'starts' ) ) );
}
check( 'the sections that send e-mail or call out by themselves say so', false !== strpos( Layout::card_text( 'requests' ), 'e-mails people by itself' ) && false !== strpos( Layout::card_text( 'lost-fish' ), 'hourly' ) && false !== strpos( Layout::card_text( 'weather' ), 'nightly call' ) && false !== strpos( Layout::card_text( 'health' ), 'Alert e-mail' ) && false !== strpos( Layout::card_text( 'health' ), 'the checkout alarm: every 5 minutes' ) );
check( 'an unknown section: no sentence (the card shows its own description)', '' === Layout::card_text( 'something-new' ) );

echo "\n" . ( $fh_failures ? "$fh_failures of $fh_checks checks FAILED\n" : "All $fh_checks checks passed\n" );
exit( $fh_failures ? 1 : 0 );
