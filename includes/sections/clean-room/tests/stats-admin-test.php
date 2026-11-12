<?php
/**
 * The Stats tab's further views on the fake store — Compare years,
 * Species, Prices to raise, Real sales: which view a request asks for,
 * the years a comparison shows (the last two with orders by default),
 * the Species view's year / search / sort, the export sheets (the
 * comparison with its change rows and its empty cells for a missing
 * year, the species by year) and the export switch — and the SCREENS
 * themselves, rendered: the strip of views, the dash for a supplier with
 * no order in a year, a change in points, which numbers every view calls
 * an estimate and which actual, "no landed cost" instead of a profit,
 * the "not matched" lists, the Refresh form and the Settings card with
 * their nonces, a name with markup escaped, and no PHP notice anywhere.
 * Then what holds past the newest 500 orders one read of the store
 * returns (Compare years, Real sales' landed cost, the note on Prices),
 * the Species rows of a group adding up to "By year"'s, the pointer to
 * the theme's tool for old invoice orders, and a reading that failed.
 *
 * Run from the plugin root:  php includes/sections/clean-room/tests/stats-admin-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Clean_Room\Admin_Stats;
use FisHotel\Misc\Sections\Clean_Room\Admin_Workbook;
use FisHotel\Misc\Sections\Clean_Room\Clean_Room;
use FisHotel\Misc\Sections\Clean_Room\Orders;
use FisHotel\Misc\Sections\Clean_Room\Settings;
use FisHotel\Misc\Sections\Clean_Room\Species;
use FisHotel\Misc\Sections\Clean_Room\Stats;
use FisHotel\Misc\Sections\Clean_Room\Stats_Sales;
use FisHotel\Misc\Sections\Clean_Room\Xlsx;
use FisHotel\Misc\Sections\Clean_Room\Tests\Fake_Store;

// A PHP notice or warning in a view is a failure, not a line in a log.
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	}
);

// The template helpers the views call (wp-stubs.php has the rest).
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr_e( $text, $domain = null ) {
		echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function selected( $a, $b = true, $echo = true ) {
		$out = (string) $a === (string) $b ? ' selected="selected"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
	function checked( $a, $b = true, $echo = true ) {
		$out = (string) $a === (string) $b ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}
	function wc_get_is_paid_statuses() {
		return array( 'processing', 'completed' );
	}
	function wc_get_order_statuses() {
		return array(
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-completed'  => 'Completed',
			'wc-shipped'    => 'Shipped',
		);
	}
}

function near( $a, $b, $eps = 1e-6 ) {
	return null !== $a && null !== $b && abs( (float) $a - (float) $b ) < $eps;
}

/**
 * Render one view of the Stats tab as the admin page does, and hand its HTML back.
 */
function fh_render( $store, array $get ) {
	$_GET = array_merge( array( 'page' => Clean_Room::MENU_SLUG, 'tab' => 'stats' ), $get );
	ob_start();
	try {
		Admin_Workbook::render(
			'stats',
			array(
				'store'        => $store,
				'settings'     => Settings::get(),
				'today'        => '2026-11-12',
				'tables_ok'    => true,
				'can_settings' => true,
				'labels_url'   => '',
			)
		);
	} catch ( \Throwable $e ) {
		ob_end_clean();
		$_GET = array();
		return 'RENDER FAILED: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
	$_GET = array();
	return (string) ob_get_clean();
}

/*
 * The store. Supplier orders, no fees (landed = received × price):
 *
 *   2024-1  Test Supplier One, Testland   wrasse 10 ordered / 9 received / 9 survived, price 5, worth 40
 *   2025-1  Test Supplier Two, Testland   goby 5 / 5 / survivors not typed, price 4, worth 20
 *   2026-1  Test Supplier One, Testland   wrasse 10 / 8 / 1 DOA / 6 survived, price 5, worth 40
 *                                         <b>bold</b> fish 2 / 2 / 2 survived, price 3, worth 10   (a name with markup)
 *   2026-2  Test Supplier Two, Elsewhere  goby 20 / 10 / 2 DOA / 4 survived, price 4, worth 30 (bag mode)
 */
$store = new Fake_Store( array( 12 => 'Blue Test Wrasse (shop)' ) );
$ids   = array();
foreach ( array(
	array( 'name' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'product_id' => 12, 'my_price' => 30 ),
	array( 'name' => 'Red Test Goby', 'group_name' => 'Gobies', 'my_price' => 20 ),
	array( 'name' => '<b>bold</b> fish', 'group_name' => 'Odd & ends', 'my_price' => 10 ),
) as $row ) {
	$ids[] = $store->save_species( Species::normalize( $row ) );
}
list( $wrasse, $goby, $bold ) = $ids;
$store->product_prices        = array( 12 => array( 'prices' => array( 30.0 ), 'variations' => array() ) );

$save = static function ( array $header, array $lines ) use ( $store ) {
	$id = $store->save_order( Orders::normalize_order( $header ) );
	$store->save_order_lines( $id, Orders::normalize_lines( $lines ) );
	return $id;
};
$save( array( 'ref' => '2024-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2024-06-01', 'status' => 'closed' ), array(
	array( 'species_id' => $wrasse, 'species' => 'Blue Test Wrasse', 'qty_ordered' => 10, 'qty_received' => 9, 'qty_survived' => 9, 'unit_price' => 5, 'worth' => 40 ),
) );
$save( array( 'ref' => '2025-1', 'supplier' => 'Test Supplier Two', 'origin' => 'Testland', 'received_date' => '2025-06-01', 'status' => 'closed' ), array(
	array( 'species_id' => $goby, 'species' => 'Red Test Goby', 'qty_ordered' => 5, 'qty_received' => 5, 'unit_price' => 4, 'worth' => 20 ),
) );
$save( array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-03-01', 'status' => 'received' ), array(
	array( 'species_id' => $wrasse, 'species' => 'Blue Test Wrasse', 'qty_ordered' => 10, 'qty_received' => 8, 'qty_doa' => 1, 'qty_survived' => 6, 'unit_price' => 5, 'worth' => 40 ),
	array( 'species_id' => $bold, 'species' => '<b>bold</b> fish', 'qty_ordered' => 2, 'qty_received' => 2, 'qty_survived' => 2, 'unit_price' => 3, 'worth' => 10 ),
) );
$save( array( 'ref' => '2026-2', 'supplier' => 'Test Supplier Two', 'origin' => 'Elsewhere', 'order_date' => '2026-05-01', 'status' => 'ordered', 'cost_mode' => 'bag' ), array(
	array( 'species_id' => $goby, 'species' => 'Red Test Goby', 'qty_ordered' => 20, 'qty_received' => 10, 'qty_doa' => 2, 'qty_survived' => 4, 'unit_price' => 4, 'worth' => 30 ),
) );

// Sales in 2026: the wrasse's product, an invoice fish whose name is no species, the bold fish by its exact name.
$fish = static function ( $name, $qty, $revenue ) {
	return array( 'name' => $name, 'qty' => $qty, 'revenue' => $revenue, 'meta' => array( '_fh_invoice_fish' => 1, '_fh_invoice_product_id' => 50 ) );
};
$store->sale( 'completed', '2026-04-01 10:00:00', array( array( 'product_id' => 12, 'name' => 'Blue Test Wrasse (shop)', 'qty' => 3, 'revenue' => 105 ) ) );
$store->sale( 'shipped', '2026-04-02 10:00:00', array( $fish( 'Mystery Test Eel', 1, 25 ), $fish( '<b>bold</b> fish', 1, 12 ), $fish( 'Red Test Goby', 2, 50 ) ) );
$store->sale( 'pending', '2026-04-03 10:00:00', array( array( 'product_id' => 12, 'name' => 'Blue Test Wrasse (shop)', 'qty' => 9, 'revenue' => 999 ) ) );

echo "1. Which view a request asks for\n";
check( 'no view, or an unknown one, is "By year" — the tab as it was', 'year' === Admin_Stats::view_from_request( array() ) && 'year' === Admin_Stats::view_from_request( array( 'view' => 'passwords' ) ) && 'year' === Admin_Stats::view_from_request( array( 'view' => array( 'sales' ) ) ) && 'year' === Admin_Stats::view_from_request( null ) );
check( 'the four further views by name, any case', array( 'compare', 'species', 'prices', 'sales' ) === array_map( array( Admin_Stats::class, 'view_from_request' ), array( array( 'view' => 'compare' ), array( 'view' => 'SPECIES' ), array( 'view' => 'prices' ), array( 'view' => 'sales' ) ) ) && array( 'year', 'compare', 'species', 'prices', 'sales' ) === Admin_Stats::VIEWS );
check( 'view_url: the Stats tab, with the view unless it is "By year", and any extras', false !== strpos( Admin_Stats::view_url( 'sales', array( 'year' => 2026 ) ), 'tab=stats' ) && false !== strpos( Admin_Stats::view_url( 'sales', array( 'year' => 2026 ) ), 'view=sales' ) && false !== strpos( Admin_Stats::view_url( 'sales', array( 'year' => 2026 ) ), 'year=2026' ) && false === strpos( Admin_Stats::view_url( 'year' ), 'view=' ) );
$nav = Admin_Stats::nav( 'prices' );
check( 'the strip: five views in order, the one shown marked', array( 'year', 'compare', 'species', 'prices', 'sales' ) === array_column( $nav, 'key' ) && array( false, false, false, true, false ) === array_column( $nav, 'active' ) && 'Prices to raise' === $nav[3]['label'] && 'Real sales' === $nav[4]['label'] && 'By year' === $nav[0]['label'] );

echo "2. Compare years: which years\n";
$d = Admin_Stats::compare_data( $store, array() );
check( 'nothing picked: the last two years that have orders, oldest first; every year with orders offered', array( 2025, 2026 ) === $d['picked'] && array( 2026, 2025, 2024 ) === $d['years'] && false === $d['defaulted'] && array( 2025, 2026 ) === $d['compare']['years'] );
$d = Admin_Stats::compare_data( $store, array( 2026, 2024 ) );
check( 'two picked: those two', array( 2024, 2026 ) === $d['picked'] && false === $d['defaulted'] && false !== strpos( $d['exports']['xlsx'], 'what=compare' ) && false !== strpos( $d['exports']['csv'], 'years=2024%2C2026' ) );
$d = Admin_Stats::compare_data( $store, array( 2024, 2025, 2026 ) );
check( 'three picked: all three, with two changes each', array( 2024, 2025, 2026 ) === $d['picked'] && 2 === count( $d['compare']['totals']['changes']['received'] ) );
$d1 = Admin_Stats::compare_data( $store, array( 2026 ) );
$d2 = Admin_Stats::compare_data( $store, array( 1999, 2026 ) );
check( 'one picked, or only one of the picked has orders: the last two instead — and the screen is told', array( 2025, 2026 ) === $d1['picked'] && true === $d1['defaulted'] && array( 2025, 2026 ) === $d2['picked'] && true === $d2['defaulted'] );
check( 'years_from_request: years[]=…, or years=a,b; whole years only, once each', array( 2025, 2026 ) === Admin_Stats::years_from_request( array( 'years' => array( '2025', '2026', '2025', 'x', '12' ) ) ) && array( 2024, 2026 ) === Admin_Stats::years_from_request( array( 'years' => '2024, 2026,abc' ) ) && array() === Admin_Stats::years_from_request( array() ) && array() === Admin_Stats::years_from_request( array( 'years' => array( array( 2026 ) ) ) ) && array() === Admin_Stats::years_from_request( 'years' ) );
$one_year = new Fake_Store();
$oid      = $one_year->save_order( Orders::normalize_order( array( 'ref' => '2026-1', 'received_date' => '2026-01-05' ) ) );
$one_year->save_order_lines( $oid, Orders::normalize_lines( array( array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 1, 'qty_received' => 1, 'unit_price' => 5 ) ) ) );
check( 'a store with one year of orders: that year alone, no change; an empty store: no year', array( 2026 ) === Admin_Stats::compare_data( $one_year, array() )['picked'] && array() === Admin_Stats::compare_data( new Fake_Store(), array( 2026, 2025 ) )['picked'] && array() === Admin_Stats::compare_data( new Fake_Store(), array() )['compare']['years'] );
$many_years = new Fake_Store();
for ( $y = 2015; $y <= 2026; $y++ ) {
	$many_years->save_order( Orders::normalize_order( array( 'ref' => $y . '-1', 'received_date' => $y . '-02-01' ) ) );
}
check( 'at most six years side by side: the newest six of those picked', array( 2021, 2022, 2023, 2024, 2025, 2026 ) === Admin_Stats::compare_data( $many_years, range( 2015, 2026 ) )['picked'] && 6 === Admin_Stats::COMPARE_MAX );

echo "3. The comparison as sheets\n";
$compare = Admin_Stats::compare_data( $store, array( 2025, 2026 ) )['compare'];
$sheets  = Admin_Stats::compare_sheets( $compare );
check( 'four sheets: Totals, By supplier, By origin, By group — each a name, a row label and the eleven figures', array( 'Totals', 'By supplier', 'By origin', 'By group' ) === array_keys( $sheets ) && array_merge( array( 'name', 'row' ), array_keys( Stats::COMPARE_FIGURES ) ) === $sheets['Totals'][0] && 'supplier' === $sheets['By supplier'][0][0] && 'origin' === $sheets['By origin'][0][0] && 'species_group' === $sheets['By group'][0][0] );
$tot = $sheets['Totals'];
check( 'Totals: a row per year, then the change and the change in percent — 4 rows under the header', 5 === count( $tot ) && array( '2025', '2026', '2025 to 2026 change', '2025 to 2026 change %' ) === array_column( array_slice( $tot, 1 ), 1 ) && 'all' === $tot[1][0] );
$y26 = array_combine( $tot[0], $tot[2] );
$chg = array_combine( $tot[0], $tot[3] );
$pct = array_combine( $tot[0], $tot[4] );
check( '2026, typed: 2 orders, 32 ordered, 20 received, 12 survived, 3 DOA, landed 86.00, est. revenue 380.00', 2 === $y26['orders'] && 32 === $y26['ordered'] && 20 === $y26['received'] && 12 === $y26['survived'] && 3 === $y26['doa'] && near( $y26['total'], 86 ) && near( $y26['revenue'], 380 ) && near( $y26['fill_rate'], 20 / 32 ) );
check( 'the change row: +1 order, +27 ordered, +15 received, the fill in POINTS (100% → 62.5% = −37.5), no change for the survivors (unknown in 2025)', 1 === $chg['orders'] && 27 === $chg['ordered'] && 15 === $chg['received'] && near( $chg['fill_rate'], -37.5 ) && null === $chg['survived'] && null === $chg['survival_rate'] && near( $chg['total'], 66 ) );
check( 'the percent row: fractions where a percent is defined (+100% orders, +540% ordered, +330% landed), empty for the rates, from zero, and for up / down', near( $pct['orders'], 1.0 ) && near( $pct['ordered'], 5.4 ) && near( $pct['total'], 3.3 ) && null === $pct['fill_rate'] && null === $pct['doa_rate'] && null === $pct['doa'] && null === $pct['revenue'] && null === $pct['up_down'] );
$sup = array();
foreach ( array_slice( $sheets['By supplier'], 1 ) as $line ) {
	$sup[ $line[0] ][ $line[1] ] = array_combine( $sheets['By supplier'][0], $line );
}
check( 'By supplier (Two first: it received more over the two years): Supplier One had no order in 2025 — its 2025 row is EMPTY cells (never 0), and so are both change rows', array( 'Test Supplier Two', 'Test Supplier One' ) === array_keys( $sup ) && null === $sup['Test Supplier One']['2025']['orders'] && null === $sup['Test Supplier One']['2025']['received'] && null === $sup['Test Supplier One']['2025']['total'] && null === $sup['Test Supplier One']['2025 to 2026 change']['orders'] && null === $sup['Test Supplier One']['2025 to 2026 change %']['orders'] && 1 === $sup['Test Supplier One']['2026']['orders'] && 10 === $sup['Test Supplier One']['2026']['received'] );
check( '… Supplier Two is in both: 1 → 1 order is a change of 0 (0%), received 5 → 10 is +5 (+100%)', 0 === $sup['Test Supplier Two']['2025 to 2026 change']['orders'] && near( $sup['Test Supplier Two']['2025 to 2026 change %']['orders'], 0.0 ) && 5 === $sup['Test Supplier Two']['2025 to 2026 change']['received'] && near( $sup['Test Supplier Two']['2025 to 2026 change %']['received'], 1.0 ) );
$one = Admin_Stats::compare_sheets( $compare, true );
check( 'for a CSV: ONE table — "Compare" — with a leading section column and every row of the four sheets', array( 'Compare' ) === array_keys( $one ) && 'section' === $one['Compare'][0][0] && count( $one['Compare'] ) - 1 === count( $tot ) - 1 + count( $sheets['By supplier'] ) - 1 + count( $sheets['By origin'] ) - 1 + count( $sheets['By group'] ) - 1 && array( 'Totals', 'By supplier', 'By origin', 'By group' ) === array_values( array_unique( array_column( array_slice( $one['Compare'], 1 ), 0 ) ) ) );
$out = fopen( 'php://memory', 'w+' );
Admin_Workbook::write_csv( $out, $one['Compare'] );
rewind( $out );
$csv = (string) stream_get_contents( $out );
fclose( $out );
check( 'written as CSV: the missing year is a row of empty cells, a negative change is a plain number', false !== strpos( $csv, '"By supplier","Test Supplier One",2025,,,,,,,,,,,' . "\n" ) && false !== strpos( $csv, ',-37.5,' ) && false === strpos( $csv, "'-" ), substr( $csv, 0, 400 ) );
$three = Admin_Stats::compare_sheets( Admin_Stats::compare_data( $store, array( 2024, 2025, 2026 ) )['compare'] );
check( 'three years: 3 year rows and 2 × 2 change rows per name', 8 === count( $three['Totals'] ) && array( '2024', '2025', '2024 to 2025 change', '2024 to 2025 change %', '2026', '2025 to 2026 change', '2025 to 2026 change %' ) === array_column( array_slice( $three['Totals'], 1 ), 1 ) );
check( 'nothing to compare: headers only', array( 'Totals', 'By supplier', 'By origin', 'By group' ) === array_keys( Admin_Stats::compare_sheets( Stats::compare( array() ) ) ) && 1 === count( Admin_Stats::compare_sheets( Stats::compare( array() ) )['By supplier'] ) && 1 === count( Admin_Stats::compare_sheets( Stats::compare( array() ) )['Totals'] ) );

echo "4. Species: the year, the search, the sort\n";
$f = Admin_Stats::species_filters( array(), $store, '2026-11-12' );
check( 'no year in the request: the latest year that has orders; year=0 is every year', 2026 === $f['year'] && '' === $f['search'] && '' === $f['sort'] && 0 === Admin_Stats::species_filters( array( 'year' => '0' ), $store, '2026-11-12' )['year'] && 2024 === Admin_Stats::species_filters( array( 'year' => '2024' ), $store, '2026-11-12' )['year'] && 2031 === Admin_Stats::species_filters( array(), new Fake_Store(), '2031-01-01' )['year'] );
check( 'a sort only by a column of the view; a direction only asc or desc', array( 'year' => 2026, 'search' => 'wras', 'sort' => 'margin', 'dir' => 'asc' ) === Admin_Stats::species_filters( array( 'year' => 2026, 's' => ' wras ', 'sort' => 'margin', 'dir' => 'ASC' ), $store, '2026-11-12' ) && '' === Admin_Stats::species_filters( array( 'sort' => 'landed_cached; DROP' ), $store, '2026-11-12' )['sort'] && 'desc' === Admin_Stats::species_filters( array( 'dir' => 'up' ), $store, '2026-11-12' )['dir'] );
$s26 = Admin_Stats::species_data( $store, $f );
check( '2026: three fish, most received first; the sums of the year', array( 'Red Test Goby', 'Blue Test Wrasse', '<b>bold</b> fish' ) === array_column( $s26['rows'], 'name' ) && 3 === $s26['all'] && 20 === $s26['totals']['received'] && near( $s26['totals']['total'], 86 ) && near( $s26['totals']['revenue'], 380 ) && $s26['totals'] === $s26['year_totals'] && array( 2026, 2025, 2024 ) === $s26['years'] );
$all = Admin_Stats::species_data( $store, array( 'year' => 0 ) );
check( 'every year: one row per fish AND year (the wrasse twice, the goby twice), newest year first', 5 === count( $all['rows'] ) && array( 2026, 2026, 2026, 2025, 2024 ) === array_column( $all['rows'], 'year' ) && 34 === $all['totals']['received'] );
check( '… and its export link says year=0 (without it the export would fall back to the latest year)', false !== strpos( $all['exports']['xlsx'], 'year=0' ) && false !== strpos( $all['exports']['xlsx'], 'what=species-years' ) && 5 === count( Admin_Stats::export( 'species-years', 'xlsx', $store, array( 'year' => '0' ), '2026-11-12' )['sheets']['Species by year'] ) - 1 && 3 === count( Admin_Stats::export( 'species-years', 'xlsx', $store, array(), '2026-11-12' )['sheets']['Species by year'] ) - 1 );
$found = Admin_Stats::species_data( $store, array( 'year' => 0, 'search' => 'gob', 'sort' => 'year', 'dir' => 'asc' ) );
check( 'a search and a sort: the gobies, oldest year first; the sums are of the rows shown, the year\'s beside them; the link carries all of it', array( 2025, 2026 ) === array_column( $found['rows'], 'year' ) && 15 === $found['totals']['received'] && 34 === $found['year_totals']['received'] && 5 === $found['all'] && false !== strpos( $found['exports']['csv'], 's=gob' ) && false !== strpos( $found['exports']['csv'], 'sort=year' ) && false !== strpos( $found['exports']['csv'], 'dir=asc' ) );
$by_profit = Admin_Stats::species_data( $store, array( 'year' => 0, 'sort' => 'profit', 'dir' => 'asc' ) );
$by_surv   = Admin_Stats::species_data( $store, array( 'year' => 0, 'sort' => 'survived', 'dir' => 'desc' ) );
check( 'a fish without a survivor count has no estimate: sorted by profit or by survivors it goes LAST either way — not first as a loss of its whole landed cost, and not as 0 survivors', 'Red Test Goby' === $by_profit['rows'][4]['name'] && 2025 === $by_profit['rows'][4]['year'] && 'Red Test Goby' === $by_surv['rows'][4]['name'] && 2025 === $by_surv['rows'][4]['year'] && ! array_key_exists( '_sort', $by_profit['rows'][0] ) && near( $by_profit['rows'][0]['profit'], 14 ) && 9 === $by_surv['rows'][0]['survived'] );
check( 'a search that finds a group: "odd" → the bold fish', array( '<b>bold</b> fish' ) === array_column( Admin_Stats::species_data( $store, array( 'year' => 2026, 'search' => 'ODD' ) )['rows'], 'name' ) );
$link = Admin_Stats::sort_link( 'species', array( 'year' => 0, 'search' => 'gob', 'sort' => '', 'dir' => 'desc' ), 'total' );
check( 'a heading\'s link: that column, most first on the first click (A to Z for text), keeping the year and the search', false !== strpos( $link['url'], 'sort=total' ) && false !== strpos( $link['url'], 'dir=desc' ) && false !== strpos( $link['url'], 'year=0' ) && false !== strpos( $link['url'], 's=gob' ) && false !== strpos( $link['url'], 'view=species' ) && '' === $link['dir'] && false !== strpos( Admin_Stats::sort_link( 'species', array( 'year' => 2026, 'sort' => '', 'dir' => 'desc' ), 'name', true )['url'], 'dir=asc' ) );
$again = Admin_Stats::sort_link( 'species', array( 'year' => 2026, 'search' => '', 'sort' => 'total', 'dir' => 'desc' ), 'total' );
check( '… and the other way round on the column already sorted, which says which way it is sorted now', false !== strpos( $again['url'], 'dir=asc' ) && 'desc' === $again['dir'] && false === strpos( $again['url'], 's=' ) );

echo "5. Species: the sheet\n";
$sheet = Admin_Stats::species_rows( $all['rows'] );
$head  = $sheet[0];
$rows  = array();
foreach ( array_slice( $sheet, 1 ) as $line ) {
	$rows[ $line[0] . ' ' . $line[2] ] = array_combine( $head, $line );
}
$w = $rows['Blue Test Wrasse 2026'];
check( 'typed cells — the 2026 wrasse: 1 order, 10 ordered, 8 received, 1 DOA, 6 survived, landed 40.00 (5.00 a fish), est. revenue 240.00, profit 200.00, margin 5', 6 === count( $sheet ) && 'Wrasses' === $w['group'] && 2026 === $w['year'] && 1 === $w['orders'] && 10 === $w['ordered'] && 8 === $w['received'] && 1 === $w['doa'] && 6 === $w['survived'] && 40.0 === $w['landed_total'] && 5.0 === $w['landed_per_fish'] && 240.0 === $w['est_revenue'] && 200.0 === $w['est_profit'] && 5.0 === $w['est_margin'] && 0 === $w['lines_without_survivors'] && $wrasse === $w['species_id'] && near( $w['doa_rate'], 0.125 ) );
$g = $rows['Red Test Goby 2025'];
check( 'the 2025 goby has no survivor count: survived and the three estimates are EMPTY, not 0 and not a loss; the landed cost stands', null === $g['survived'] && null === $g['survival_rate'] && null === $g['est_revenue'] && null === $g['est_profit'] && null === $g['est_margin'] && 20.0 === $g['landed_total'] && 1 === $g['lines_without_survivors'] );

echo "6. The export switch\n";
$x = Admin_Stats::export( 'compare', 'xlsx', $store, array( 'years' => '2025,2026' ), '2026-11-12' );
$c = Admin_Stats::export( 'compare', 'csv', $store, array( 'years' => array( '2025', '2026' ) ), '2026-11-12' );
check( 'compare: four sheets as XLSX, the one table as CSV', array( 'Totals', 'By supplier', 'By origin', 'By group' ) === array_keys( $x['sheets'] ) && array( 'Compare' ) === array_keys( $c['sheets'] ) && 'Compare' === $c['csv'] && isset( $c['sheets'][ $c['csv'] ] ) );
$p = Admin_Stats::export( 'prices', 'csv', $store, array( 'all' => '1' ), '2026-11-12' );
$s = Admin_Stats::export( 'sales', 'xlsx', $store, array( 'year' => '2026' ), '2026-11-12' );
check( 'prices and sales: their sheets, and the sheet a CSV carries is one of them', array( 'Prices to raise' ) === array_keys( $p['sheets'] ) && isset( $p['sheets'][ $p['csv'] ] ) && 4 === count( $p['sheets']['Prices to raise'] ) && array( 'Sales by species', 'Not matched' ) === array_keys( $s['sheets'] ) && isset( $s['sheets'][ $s['csv'] ] ) );
check( 'anything else is not this class\'s export', null === Admin_Stats::export( 'stays', 'xlsx', $store, array(), '2026-11-12' ) && null === Admin_Stats::export( '', 'csv', $store, array(), '2026-11-12' ) && array( 'compare', 'species-years', 'prices', 'sales' ) === Admin_Stats::EXPORTS );
if ( class_exists( 'ZipArchive' ) ) {
	$ok = true;
	foreach ( array( $x, $p, $s, Admin_Stats::export( 'species-years', 'xlsx', $store, array( 'year' => '0' ), '2026-11-12' ) ) as $export ) {
		$bytes = Xlsx::build( $export['sheets'] );
		$ok    = $ok && is_string( $bytes ) && 'PK' === substr( $bytes, 0, 2 );
	}
	check( 'every export builds into an .xlsx (empty cells, negative numbers, bools and a name with markup included)', $ok );
} else {
	check( 'the zip extension is missing here: the XLSX build is skipped (the CSV path is tested above)', true );
}
foreach ( array( $p, $s ) as $export ) {
	$out = fopen( 'php://memory', 'w+' );
	$n   = Admin_Workbook::write_csv( $out, $export['sheets'][ $export['csv'] ] );
	rewind( $out );
	$text = (string) stream_get_contents( $out );
	fclose( $out );
	check( 'the ' . $export['csv'] . ' CSV: a header and its rows; a name that starts like a formula would be guarded, a negative gap is a number', $n === count( $export['sheets'][ $export['csv'] ] ) && false !== strpos( $text, 'species,group' ) && false === strpos( $text, "'-" ) );
}
check( 'the workbook admin hands these four to this class: its export link works for them', false !== strpos( Admin_Workbook::export_url( 'compare', 'xlsx', array( 'years' => '2025,2026' ) ), 'action=fishotel_cleanroom_export' ) && false !== strpos( (string) file_get_contents( Clean_Room::path() . 'class-admin-workbook.php' ), 'Admin_Stats::export(' ) );

echo "7. The screens: By year\n";
$html = fh_render( $store, array( 'year' => '2026' ) );
check( 'it renders (no notice, no error)', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'fh-cr-wrap' ), substr( $html, 0, 300 ) );
check( 'the strip of the five views, "By year" current', 4 === substr_count( $html, 'class="fh-cr-subtab"' ) && 1 === substr_count( $html, 'class="fh-cr-subtab is-active"' ) && false !== strpos( $html, 'aria-current="page">By year<' ) && false !== strpos( $html, 'view=compare' ) && false !== strpos( $html, 'view=sales' ) );
check( 'it SAYS which figures are estimates: the legend, "Est. revenue", "Est. up / down", "Estimated up or down" — and points at Real sales', false !== strpos( $html, 'fh-cr-kind--est' ) && false !== strpos( $html, 'are estimates' ) && false !== strpos( $html, 'Est. revenue' ) && false !== strpos( $html, 'Est. up / down' ) && false !== strpos( $html, 'Estimated up or down' ) && false !== strpos( $html, 'under Real sales' ) && false === strpos( $html, '>Revenue<' ) );

echo "8. The screens: Compare years\n";
$html = fh_render( $store, array( 'view' => 'compare', 'years' => array( '2025', '2026' ) ) );
check( 'it renders', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'fh-cr-compare' ), substr( $html, 0, 300 ) );
check( 'a checkbox per year with orders, the two compared ticked; both export links', 3 === substr_count( $html, 'name="years[]"' ) && 2 === substr_count( $html, 'checked="checked"' ) && false !== strpos( $html, 'what=compare' ) && false !== strpos( $html, 'format=csv' ) );
/**
 * The <tbody> of one name in a compared table.
 */
$tbody = static function ( $html, $name ) {
	$at    = (int) strpos( $html, '>' . $name . '<' );
	$start = (int) strrpos( substr( $html, 0, $at ), '<tbody>' );
	return substr( $html, $start, (int) strpos( $html, '</tbody>', $at ) - $start );
};
$block = $tbody( $html, 'Test Supplier One' );
check( 'Supplier One has no order in 2025: that row is marked, says "no order", and every figure in it is a dash — never a 0', false !== strpos( $block, 'fh-cr-compare__year is-missing' ) && false !== strpos( $block, 'no order' ) && 11 === substr_count( substr( $block, 0, (int) strpos( $block, '</tr>' ) ), '<td class="fh-cr-num">—</td>' ) );
$change = substr( $block, (int) strpos( $block, 'fh-cr-compare__change' ) );
check( '… and the change into 2026 is a dash in every column', 11 === substr_count( $change, '>—</td>' ) && false !== strpos( $change, '2025 → 2026' ) );
$block = $tbody( $html, 'Test Supplier Two' );
check( 'Supplier Two, in both years: a change in points for the fill ("-50.0 pts"), in fish and percent for received ("+5 (+100.0%)"), coloured as good or bad news', false !== strpos( $block, '-50.0 pts' ) && false !== strpos( $block, '+5 (+100.0%)' ) && false !== strpos( $block, 'fh-cr-down' ) && false !== strpos( $block, '+20.0 pts' ) );
check( 'the page says what is an estimate, and that a dash is not a zero', false !== strpos( $html, 'fh-cr-kind--est' ) && false !== strpos( $html, 'Est. revenue and Est. up / down are estimates' ) && false !== strpos( $html, 'A dash means there is no figure' ) );
$html = fh_render( $store, array( 'view' => 'compare', 'years' => array( '2026' ) ) );
check( 'one year picked: the notice, and the last two shown', false !== strpos( $html, 'Pick at least two years' ) && false !== strpos( $html, '2025 → 2026' ) );
$html = fh_render( new Fake_Store(), array( 'view' => 'compare' ) );
check( 'no orders at all: it says so instead of an empty table', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'nothing to compare' ) && false === strpos( $html, 'fh-cr-compare__year' ) );
$html = fh_render( $one_year, array( 'view' => 'compare' ) );
check( 'one year of orders: its figures and a note that there is no change yet', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'Only one year has supplier orders' ) && false === strpos( $html, 'fh-cr-compare__change' ) );

echo "9. The screens: Species\n";
$html = fh_render( $store, array( 'view' => 'species', 'year' => '0' ) );
check( 'it renders, "Species" current', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'aria-current="page">Species<' ), substr( $html, 0, 300 ) );
check( 'every heading sorts (15 links), the year picker offers "All years" and has it selected, the search box is there', 15 === substr_count( $html, 'fh-cr-sort"' ) && 15 === substr_count( $html, '&sort=' ) && false !== strpos( $html, 'sort=margin' ) && false !== strpos( $html, '<option value="0"  selected="selected">All years' ) && false !== strpos( $html, 'name="s"' ) );
check( 'the estimates are called estimates; a fish without a survivor count says so instead of showing a loss', false !== strpos( $html, 'fh-cr-kind--est' ) && false !== strpos( $html, 'Est. profit' ) && false !== strpos( $html, 'survivors not known yet' ) && false === strpos( $html, 'line without a survivor count' ) );
check( 'a name with markup is escaped', false !== strpos( $html, '&lt;b&gt;bold&lt;/b&gt; fish' ) && false === strpos( $html, '<b>bold</b>' ) );
$html = fh_render( $store, array( 'view' => 'species', 'year' => '2026', 'sort' => 'total', 'dir' => 'asc', 's' => 'test' ) );
check( 'sorted and searched: the sorted heading is marked with its direction, and the form keeps the sort', false !== strpos( $html, 'is-sorted' ) && false !== strpos( $html, 'aria-sort="ascending"' ) && false !== strpos( $html, 'Landed ▲' ) && false !== strpos( $html, 'name="sort" value="total"' ) && false !== strpos( $html, 'value="test"' ) && false !== strpos( $html, 'of 3 fish' ) );
$html = fh_render( $store, array( 'view' => 'species', 'year' => '2026', 's' => 'zzz' ) );
check( 'a search that finds nothing says so', false !== strpos( $html, 'No fish matches that search' ) );

echo "10. The screens: Prices to raise\n";
$html = fh_render( $store, array( 'view' => 'prices' ) );
check( 'it renders and says it is read-only', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'fh-cr-kind--read' ) && false !== strpos( $html, 'Nothing on this page changes a price' ), substr( $html, 0, 300 ) );
check( 'no form on it posts anywhere: the only form is the GET filter, and there is no button that saves', 1 === substr_count( $html, '<form' ) && false !== strpos( $html, '<form method="get"' ) && false === strpos( $html, 'method="post"' ) && false === strpos( $html, 'name="_wpnonce"' ) && false === strpos( $html, 'admin-ajax.php' ) );
check( 'nobody is under here — the goby 20.00 against 4.00 × 4 = 16 → 15, the bold fish 10.00 against 3.00 × 4 = 12 → 10, the wrasse 30.00 against 5.00 × 4 = 20 — so the list is empty and says how to see them all', false !== strpos( $html, 'No fish here is priced under' ) && false !== strpos( $html, 'fh-cr-chip--ready' ) );
$html = fh_render( $store, array( 'view' => 'prices', 'all' => '1' ) );
check( '"Show all": the three fish with where their price comes from, the group and supplier pickers, the export links carrying the toggle', false !== strpos( $html, 'the product' ) && false !== strpos( $html, 'the species list' ) && false !== strpos( $html, '<option value="Wrasses"' ) && false !== strpos( $html, '<option value="Test Supplier Two"' ) && false !== strpos( $html, 'all=1' ) && false !== strpos( $html, '&lt;b&gt;bold&lt;/b&gt; fish' ) && false === strpos( $html, '<b>bold</b>' ) && false !== strpos( $html, 'checked="checked"' ) );
$store->species[ $wrasse ]['my_price'] = 30.0;
$store->product_prices[12]['prices']   = array( 12.0 );
$html                                  = fh_render( $store, array( 'view' => 'prices' ) );
check( 'with the wrasse\'s product at 12.00 against a suggested 20: it is listed, the gap +8.00 in the warning colour', false !== strpos( $html, 'Blue Test Wrasse' ) && false !== strpos( $html, '+8.00' ) && false !== strpos( $html, 'fh-cr-num fh-cr-down' ) && false !== strpos( $html, 'fh-cr-chip--warn' ) );
$store->product_prices[12]['prices'] = array( 30.0 );

echo "11. The screens: Real sales\n";
delete_transient( Stats_Sales::TRANSIENT );
$html = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
check( 'it renders, "Real sales" current', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'aria-current="page">Real sales<' ), substr( $html, 0, 300 ) );
check( 'it SAYS which numbers are actual and which are estimates, and which order statuses count', false !== strpos( $html, 'fh-cr-kind--actual' ) && false !== strpos( $html, 'fh-cr-kind--est' ) && false !== strpos( $html, 'Processing, Completed, Shipped' ) && false !== strpos( $html, 'net of refunds' ) && false !== strpos( $html, 'Est. revenue is the spreadsheet' ) && false !== strpos( $html, 'Change the statuses' ) );
check( 'the columns are marked: Units sold, Actual revenue, Avg paid and Actual profit as actual; Est. revenue as an estimate', 4 === substr_count( $html, 'fh-cr-sort fh-cr-actual' ) && 1 === substr_count( $html, 'fh-cr-sort fh-cr-est' ) && false !== strpos( $html, 'Actual profit' ) && false !== strpos( $html, 'Avg paid' ) && false !== strpos( $html, 'List price' ) );
check( 'the wrasse: 3 sold for 105.00, landed 5.00 → cost 15.00 → profit +90.00, matched by its product link', false !== strpos( $html, '+90.00' ) && false !== strpos( $html, 'product link' ) && false !== strpos( $html, '105.00' ) );
check( 'the goby sold 2 for 50.00 and landed in 2026 too (bag mode, 4.00 a fish): cost 8.00, profit +42.00 — and nothing on the page says "no landed cost"', false !== strpos( $html, 'Red Test Goby' ) && false !== strpos( $html, '+42.00' ) && false === strpos( $html, 'no landed cost in' ) && false === strpos( $html, 'fh-cr-nocost' ) );
check( 'what could not be matched is listed with its units and money: the invoice name that is no species', false !== strpos( $html, 'Not matched to a species' ) && false !== strpos( $html, 'Mystery Test Eel' ) && false !== strpos( $html, 'Invoice fish whose name is no species' ) && false !== strpos( $html, '25.00' ) );
check( '"How it adds up": matched + not matched = every paid line (167.00 + 25.00 = 192.00) — the pending order (999.00) is nowhere', false !== strpos( $html, 'How it adds up' ) && false !== strpos( $html, '167.00' ) && false !== strpos( $html, '192.00' ) && false === strpos( $html, '999.00' ) && false === strpos( $html, 'do not add up to the paid lines' ) );
check( 'the Refresh button is a POST form of its own with its action, the year and a nonce', false !== strpos( $html, 'name="action" value="' . Admin_Stats::REFRESH_ACTION . '"' ) && false !== strpos( $html, 'name="_wpnonce" value="' . wp_create_nonce( Admin_Stats::REFRESH_ACTION ) . '"' ) && false !== strpos( $html, 'admin-post.php' ) && false !== strpos( $html, 'name="year" value="2026"' ) );
check( 'it says when the sales were read, how long they are kept, and that the Invoices section is not active', false !== strpos( $html, 'Read from WooCommerce' ) && false !== strpos( $html, 'order lines counted' ) && false !== strpos( $html, 'twelve hours' ) && false !== strpos( $html, 'The Invoices section is not active' ) );
check( 'a name with markup is escaped here too', false !== strpos( $html, '&lt;b&gt;bold&lt;/b&gt; fish' ) && false === strpos( $html, '<b>bold</b>' ) );
$store->sale( 'completed', '2026-06-01 10:00:00', array( $fish( 'Red Test Goby', 1, 30 ) ) );
$save( array( 'ref' => '2023-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2023-06-01', 'status' => 'closed' ), array(
	array( 'species' => 'Grey Test Chromis', 'qty_ordered' => 4, 'qty_received' => 4, 'qty_survived' => 4, 'unit_price' => 2.5, 'worth' => 15 ),
) );
$chromis = $store->save_species( Species::normalize( array( 'name' => 'Grey Test Chromis', 'group_name' => 'Chromis' ) ) );
$store->sale( 'completed', '2026-07-01 10:00:00', array( $fish( 'grey test chromis', 2, 28 ) ) );
delete_transient( Stats_Sales::TRANSIENT );
$html = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
check( 'a fish that sold in 2026 but last landed in 2023: NO profit — the cell says "no landed cost in 2026 (last landed: 2.50 a fish in 2023)", and the line under the table counts it', false !== strpos( $html, 'fh-cr-nocost' ) && false !== strpos( $html, 'no landed cost in 2026 (last landed: 2.50 a fish in 2023)' ) && false !== strpos( $html, '1 species sold 28.00 in 2026 without a landed cost that year' ) );
$html = fh_render( $store, array( 'view' => 'sales', 'year' => '2026', 'sort' => 'profit', 'dir' => 'desc', 's' => 'test' ) );
check( 'sorted and searched: the heading marked, the sort kept by the form, the footer of the whole year left out', false !== strpos( $html, 'aria-sort="descending"' ) && false !== strpos( $html, 'name="sort" value="profit"' ) && false === strpos( $html, 'Every species' ) );
$store->wc_tables = false;
delete_transient( Stats_Sales::TRANSIENT );
$html = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
check( 'without WooCommerce\'s order tables it says so plainly, offers no Refresh, and still shows the estimates', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'Real sales are not available on this site' ) && false === strpos( $html, Admin_Stats::REFRESH_ACTION ) && false !== strpos( $html, 'Blue Test Wrasse' ) && false === strpos( $html, 'Read from WooCommerce' ) );
$store->wc_tables = true;

echo "12. The screens: Settings → Sales statuses\n";
ob_start();
include Clean_Room::path() . 'views/settings-sales.php';
$html = (string) ob_get_clean();
check( 'the card: a checkbox per status of the store, the paid ones and "shipped" ticked and marked as suggested; "pending" is marked as left out of WooCommerce\'s own reports', 4 === substr_count( $html, 'name="statuses[]"' ) && 3 === substr_count( $html, 'checked="checked"' ) && 3 === substr_count( $html, '>suggested<' ) && false !== strpos( $html, 'value="shipped"  checked="checked"' ) && false !== strpos( $html, 'value="pending" >' ) && 1 === substr_count( $html, 'left out of WooCommerce&#039;s reports' ) );
check( '… its own admin-post action and nonce, a note that nothing is saved yet, and no reset button until something is', false !== strpos( $html, 'name="action" value="' . Admin_Stats::STATUSES_ACTION . '"' ) && false !== strpos( $html, 'name="_wpnonce" value="' . wp_create_nonce( Admin_Stats::STATUSES_ACTION ) . '"' ) && false !== strpos( $html, 'Not saved yet' ) && false === strpos( $html, 'value="reset"' ) && false !== strpos( $html, 'id="fishotel-cleanroom-sales-statuses"' ) );
update_option( Stats_Sales::OPTION, array( 'completed', 'old-custom' ) );
ob_start();
include Clean_Room::path() . 'views/settings-sales.php';
$html = (string) ob_get_clean();
check( 'once saved: it says so, offers "Back to the suggested list", and keeps a status the store no longer has (marked) so it can be unticked', false !== strpos( $html, 'saved by hand' ) && false !== strpos( $html, 'value="reset"' ) && false !== strpos( $html, 'value="old-custom"  checked="checked"' ) && false !== strpos( $html, 'not a status of this store now' ) && 2 === substr_count( $html, 'checked="checked"' ) );
delete_option( Stats_Sales::OPTION );
check( 'the Settings tab includes the card, between the import and the aliases', false !== strpos( (string) file_get_contents( Clean_Room::path() . 'views/settings.php' ), "views/settings-sales.php" ) );

echo "13. Past the newest 500 orders (what one read of the store returns)\n";
$big = new Fake_Store();
$fid = $big->save_species( Species::normalize( array( 'name' => 'Plain Test Fish', 'group_name' => 'Plain', 'my_price' => 30 ) ) );
for ( $y = 2015; $y <= 2026; $y++ ) {
	for ( $i = 1; $i <= 50; $i++ ) {
		$oid = $big->save_order( Orders::normalize_order( array( 'ref' => $y . '-' . $i, 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => sprintf( '%d-%02d-%02d', $y, 1 + $i % 12, 1 + $i % 27 ), 'status' => 'closed' ) ) );
		$big->save_order_lines( $oid, Orders::normalize_lines( array( array( 'species_id' => $fid, 'species' => 'Plain Test Fish', 'qty_ordered' => 10, 'qty_received' => 10, 'qty_survived' => 9, 'unit_price' => 5, 'worth' => 30 ) ) ) );
	}
}
check( 'the fake store reads as the real one: 600 orders on file, 500 handed out by one read, every year of them on its own', 500 === Fake_Store::ORDERS_LIMIT && 500 === count( $big->orders() ) && 50 === count( $big->orders( array( 'year' => 2015 ) ) ) && 12 === count( $big->order_years() ) && true === Admin_Stats::orders_window_full( $big, $big->orders() ) && false === Admin_Stats::orders_window_full( $store, $store->orders() ) );
$old = Admin_Stats::compare_data( $big, array( 2015, 2016 ) );
check( 'Compare years offers every year with orders — the two oldest too — and shows them as "By year" does: 50 orders, 500 received each', range( 2026, 2015 ) === $old['years'] && array( 2015, 2016 ) === $old['picked'] && false === $old['defaulted'] && 50 === $old['compare']['totals']['values']['orders'][2015] && 500 === $old['compare']['totals']['values']['received'][2015] && Admin_Workbook::stats_for_year( $big, 2016 )['totals']['received'] === $old['compare']['totals']['values']['received'][2016] && near( $old['compare']['totals']['values']['total'][2016], Admin_Workbook::stats_for_year( $big, 2016 )['totals']['total'] ) );
delete_transient( Stats_Sales::TRANSIENT );
$sold = Admin_Stats::sales_data( $big, array( 'year' => 2015 ) );
check( 'Real sales of an old year: the year is offered, and its landed cost is the whole year\'s (500 received at 5.00), not the part inside the newest 500 orders', in_array( 2015, $sold['years'], true ) && 1 === count( $sold['rows'] ) && 500 === $sold['rows'][0]['received'] && near( $sold['rows'][0]['landed_per_fish'], 5 ) );
$sold = Admin_Stats::sales_data( $big, array( 'year' => 2017 ) );
check( '… and a year the newest 500 cut in half (2017: 50 orders, 500 received) is whole too', 500 === $sold['rows'][0]['received'] && 1 === count( $sold['rows'] ) );
delete_transient( Stats_Sales::TRANSIENT );
$p = Admin_Stats::prices_data( $big, array( 'all' => true ) );
check( 'Prices to raise keeps to the newest orders — a fish\'s latest order and its last three — and its data says on how many it rests (0 while every order fits)', 500 === $p['window'] && 0 === Admin_Stats::prices_data( $store, array() )['window'] && 1 === count( $p['rows'] ) && '2026-23' === $p['rows'][0]['latest_ref'] && '2026-12-24' === $p['rows'][0]['latest_date'], json_encode( array( $p['window'], $p['rows'][0]['latest_ref'] ?? null ) ) );
$html = fh_render( $big, array( 'view' => 'prices', 'all' => '1' ) );
check( '… and the screen says so; a store under the limit shows no such note', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'rests on the newest 500 supplier orders' ) && false === strpos( fh_render( $store, array( 'view' => 'prices', 'all' => '1' ) ), 'rests on the newest' ) );

echo "14. Species: a group adds up to the same group on \"By year\"\n";
$typed = new Fake_Store();
$tw    = $typed->save_species( Species::normalize( array( 'name' => 'Blue Test Wrasse', 'group_name' => 'Wrasses', 'my_price' => 30 ) ) );
$typed->save_species( Species::normalize( array( 'name' => 'Green Test Tang', 'group_name' => 'Tangs', 'my_price' => 80 ) ) );
$oid = $typed->save_order( Orders::normalize_order( array( 'ref' => '2026-1', 'supplier' => 'Test Supplier One', 'origin' => 'Testland', 'received_date' => '2026-02-01', 'status' => 'closed' ) ) );
$typed->save_order_lines( $oid, Orders::normalize_lines( array(
	array( 'species_id' => $tw, 'species' => 'Blue Test Wrasse', 'qty_ordered' => 6, 'qty_received' => 6, 'qty_survived' => 5, 'unit_price' => 6, 'worth' => 40 ),
	array( 'species' => 'Blue Test Wrasse', 'qty_ordered' => 2, 'qty_received' => 2, 'qty_survived' => 2, 'unit_price' => 7, 'worth' => 40 ), // Typed with the exact name, not picked from the list.
	array( 'species' => 'Green Test Tang', 'qty_ordered' => 3, 'qty_received' => 3, 'qty_doa' => 1, 'qty_survived' => 2, 'unit_price' => 17, 'worth' => 80 ), // The same.
) ) );
$view   = Admin_Stats::species_data( $typed, array( 'year' => 2026 ) );
$sums   = array();
foreach ( $view['rows'] as $r ) {
	$name                      = '' !== $r['group'] ? $r['group'] : Stats::NO_GROUP;
	$sums[ $name ]['received'] = ( $sums[ $name ]['received'] ?? 0 ) + $r['received'];
	$sums[ $name ]['total']    = ( $sums[ $name ]['total'] ?? 0.0 ) + $r['total'];
}
$same   = true;
$groups = Admin_Workbook::stats_for_year( $typed, 2026 )['by_group'];
foreach ( $groups as $g ) {
	$same = $same && isset( $sums[ $g['name'] ] ) && $sums[ $g['name'] ]['received'] === $g['received'] && near( $sums[ $g['name'] ]['total'], $g['total'] );
}
check( 'lines typed with a species\' exact name but not linked to it: "By year" files them under "(no group)" (5 fish) and the linked wrasse line under Wrasses (6) — and the Species rows say the same, group by group', $same && count( $sums ) === count( $groups ) && 6 === $sums['Wrasses']['received'] && 5 === $sums[ Stats::NO_GROUP ]['received'] && ! isset( $sums['Tangs'] ), json_encode( $sums ) );
check( '… the wrasse is then two rows of the year (6 linked, 2 typed), both under its list name and id; the year\'s totals are unchanged', array( array( 'Blue Test Wrasse', 'Wrasses', 6 ), array( 'Green Test Tang', '', 3 ), array( 'Blue Test Wrasse', '', 2 ) ) === array_map( static function ( $r ) { return array( $r['name'], $r['group'], $r['received'] ); }, $view['rows'] ) && $tw === $view['rows'][2]['species_id'] && 11 === $view['year_totals']['received'] && Admin_Workbook::stats_for_year( $typed, 2026 )['totals']['received'] === $view['year_totals']['received'] );
delete_transient( Stats_Sales::TRANSIENT );
$typed_sales = Admin_Stats::sales_data( $typed, array( 'year' => 2026 ) );
check( 'Real sales still has ONE row per fish, with the landed cost of all its lines (the wrasse: 8 received) under the list\'s group', array( 'Blue Test Wrasse', 'Green Test Tang' ) === array_column( $typed_sales['rows'], 'name' ) && 8 === $typed_sales['rows'][0]['received'] && 'Wrasses' === $typed_sales['rows'][0]['group'] && 'Tangs' === $typed_sales['rows'][1]['group'] );
$html = fh_render( $typed, array( 'view' => 'species', 'year' => '2026' ) );
check( 'the screen renders the three rows: the wrasse twice, the tang once', false === strpos( $html, 'RENDER FAILED' ) && 2 === substr_count( $html, 'Blue Test Wrasse' ) && 1 === substr_count( $html, 'Green Test Tang' ), substr_count( $html, 'Blue Test Wrasse' ) . ' / ' . substr_count( $html, 'Green Test Tang' ) );

echo "15. Old invoice orders: where their fish lines can be recorded\n";
delete_transient( Stats_Sales::TRANSIENT );
check( 'without the theme that has the tool, nothing points at it', '' === Admin_Stats::backfill_hint() && false === strpos( Admin_Stats::unmatched_labels()['invoices'][1], 'Tools' ) );
if ( ! class_exists( 'FisHotel_Invoice_Lines' ) ) {
	// The theme's class, as far as this section looks at it.
	eval( 'class FisHotel_Invoice_Lines { public static function handle_backfill() {} }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in declared late, in a test.
}
check( 'with it, the help under "Invoices kept as one line" names the Tools button and says to press Refresh afterwards', false !== strpos( Admin_Stats::unmatched_labels()['invoices'][1], 'FisHotel Theme → Tools → "Add fish lines to past invoice orders"' ) && false !== strpos( Admin_Stats::backfill_hint(), 'then press Refresh here' ) );
$store->products[50] = 'tester Invoice (1/2/26)'; // An invoice product from before invoices were itemised: one line, no fish recorded.
$store->sale( 'completed', '2026-05-05 10:00:00', array( array( 'product_id' => 50, 'name' => 'tester Invoice (1/2/26)', 'qty' => 1, 'revenue' => 77 ) ) );
$html = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
check( '… on the screen, beside "no fish lines recorded on the order"', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'no fish lines recorded on the order' ) && false !== strpos( $html, 'Add fish lines to past invoice orders' ) && false !== strpos( $html, 'then press Refresh here' ), substr( $html, 0, 200 ) );

echo "16. Real sales: a reading that failed says so\n";
delete_transient( Stats_Sales::TRANSIENT );
$store->sales_reads = 0;
$store->sales_break = 'rows:1';
$html               = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
$store->sales_break = '';
$cache              = get_transient( Stats_Sales::TRANSIENT );
$cache              = is_array( $cache ) ? $cache : array();
check( 'the page says the tables could not be read and that every figure is INCOMPLETE — and keeps nothing of it', false === strpos( $html, 'RENDER FAILED' ) && false !== strpos( $html, 'could not be read all the way through' ) && false !== strpos( $html, 'INCOMPLETE' ) && ! isset( $cache['years'][2026] ) );
$html  = fh_render( $store, array( 'view' => 'sales', 'year' => '2026' ) );
$cache = get_transient( Stats_Sales::TRANSIENT );
check( 'reopened, it reads the year again and the notice is gone', false === strpos( $html, 'could not be read all the way through' ) && is_array( $cache ) && isset( $cache['years'][2026] ) );

fh_finish();
