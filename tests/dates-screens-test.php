<?php
/**
 * US dates on every screen — a guard over the sources: each place that
 * shows a person a stored date goes through the plugin's one helper
 * (FisHotel\Misc\Dates, admin/js/fishotel-dates.js), no section writes a
 * year-first date for a person on its own, and what a program reads —
 * a value="" attribute, a CSV, the REST route — still gets YYYY-MM-DD.
 *
 * The helpers themselves are run in tests/dates-test.php and
 * includes/sections/clean-room/tests/us-dates.test.js; the Clean Room's
 * screens and acts in includes/sections/clean-room/tests/us-dates-test.php.
 * This test only reads files. Pure: no WordPress.
 *
 * Run from the plugin root:
 *   php tests/dates-screens-test.php
 *
 * @package FisHotel\Misc
 */

$fh_root     = dirname( __DIR__ ) . '/';
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

function fh_src( $path ) {
	global $fh_root;
	return (string) file_get_contents( $fh_root . $path );
}

/** A file without its comments (PHP tokens), so a "2026-11-27" in a comment is not counted. */
function fh_code( $path ) {
	$out = '';
	foreach ( token_get_all( fh_src( $path ) ) as $token ) {
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

echo "1. The helper and its twin are there\n";
check( 'includes/class-dates.php: the format in one place', false !== strpos( fh_src( 'includes/class-dates.php' ), "const DATE = 'm-d-y';" ) );
check( 'admin/js/fishotel-dates.js: one file, hung on the page as fhDates', false !== strpos( fh_src( 'admin/js/fishotel-dates.js' ), 'root.fhDates = api;' ) );
check( 'the two say the same refusals', false !== strpos( fh_src( 'admin/js/fishotel-dates.js' ), '%s is not a day on the calendar.' ) && false !== strpos( fh_src( 'includes/class-dates.php' ), '%s is not a day on the calendar.' ) );

echo "2. Each screen that shows a stored date uses the helper\n";
$fh_uses = array(
	// Clean Room, wp-admin.
	'includes/sections/clean-room/views/stays.php'                   => array( "Dates::us( \$fh_stay['checkin_date'] )", "Dates::us( \$fh_stay['cleared_date'] )", "Dates::us( \$fh_sale['date'] )" ),
	'includes/sections/clean-room/views/meta-box.php'                => array( "Dates::us( \$facts['next']['sale_ready_date'] )", 'Dates::us( $fh_batch )' ),
	'includes/sections/clean-room/views/order.php'                   => array( "Dates::us( \$batch['suggested'] )", "Dates::us( \$fh_own['date'] )" ),
	'includes/sections/clean-room/views/arrivals.php'                => array( "Dates::us( \$fh_order['order_date'], '—' )", "Dates::us( \$fh_order['received_date'], '—' )" ),
	'includes/sections/clean-room/views/stats.php'                   => array( "Dates::us( \$fh_r['received_date'] )", "Dates::us( \$fh_r['order_date'] )" ),
	'includes/sections/clean-room/views/stats-prices.php'            => array( "Dates::us( \$fh_r['latest_date'] )" ),
	'includes/sections/clean-room/views/species.php'                 => array( "Dates::us( \$fh_row['online_date'] )" ),
	'includes/sections/clean-room/views/board.php'                   => array( 'Dates::us( $today )' ),
	'includes/sections/clean-room/views/todo.php'                    => array( 'Dates::us( $today )' ),
	'includes/sections/clean-room/views/board-temp.php'              => array( "Dates::us( \$fh_setup['start_date'] )" ),
	'includes/sections/clean-room/views/temp-history.php'            => array( "Dates::us( \$fh_row['start_date'] )", "Dates::us( \$setup['start_date'] )", "Dates::us( \$fh_group['in'] )", "Dates::us( \$fh_group['out'] )" ),
	'includes/sections/clean-room/views/pull-today.php'              => array( "Dates::us( (string) \$fh_entry['date'] )" ),
	'includes/sections/clean-room/views/settings-import-layouts.php' => array( "Dates::us( \$fh_layout['used'], '—' )" ),
	'includes/sections/clean-room/class-admin.php'                   => array( 'return Dates::stamp( $ts );', 'Dates::us( $suggested )', "'on sale %s', 'fishotel-misc-plugin' ), Dates::us( \$date )", "Dates::us( (string) \$stay['checkin_date'] )" ),
	'includes/sections/clean-room/class-admin-workbook.php'          => array( "'' !== \$date ? Dates::us( \$date )", 'Dates::us( $new )' ),
	'includes/sections/clean-room/class-board-lines.php'             => array( 'Dates::us( $dates[0] )' ),
	'includes/sections/clean-room/class-temp-tanks.php'              => array( "Dates::us( (string) \$setup['start_date'] )", "Dates::us( \$fields['start_date'] )" ),
	'includes/sections/clean-room/class-reserve-shop.php'            => array( "Dates::day( (int) ( \$hold['at'] ?? 0 ) )" ),
	'includes/sections/clean-room/class-admin-sizes-tidy.php'        => array( 'Dates::stamp( $time )' ),
	// Clean Room, the phone.
	'includes/sections/clean-room/class-front-desk.php'              => array( "Dates::us( \$answers['arrived'] )", "Dates::us( \$answers['cleared'] )", 'Dates::us( $batch )', "admin/js/fishotel-dates.js'" ),
	'includes/sections/clean-room/js/front-desk.js'                  => array( 'usDate(o.saleReadyDate)', 'usDate(o.saleSuggested)', 'usDate(o.checkinDate)', 'usDate(o.clearedDate)', 'usDate(o.batchSaleDate)', 'usDate(list.date)' ),
	'includes/sections/clean-room/js/clean-room-admin.js'            => array( 'us(batch)', 'dates.ask(ask)' ),
	// Shipping.
	'includes/sections/foam-cuts/views/print.php'                    => array( "Dates::us( (string) \$fh_for['ship_date'] )", "Dates::us( (string) \$fh_for['delivery_date'] )" ),
	'includes/sections/foam-cuts/views/plan.php'                     => array( "Dates::us( \$orders['ship_date'] )", "Dates::us( \$orders['delivery_date'] )", "Dates::us( \$fh_row['ship_date'] )" ),
	'includes/sections/foam-cuts/class-planner.php'                  => array( "Dates::us( (string) \$plan['ship_date'] )", "Dates::us( \$orders['ship_date'] )", 'Dates::us( $ship )' ),
	'includes/sections/weather/views/meta-box.php'                   => array( "Dates::us_time( \$stored['checked_at'] )" ),
	'includes/sections/weather/views/overview.php'                   => array( "Dates::us_time( \$last_run['at'] )" ),
	// The site.
	'includes/sections/requests/class-admin.php'                     => array( 'return Dates::stamp( Rules::ts( $utc ) );' ),
	'includes/sections/site-snippets/class-admin-editor.php'         => array( 'return Dates::from_utc( (string) $utc );' ),
	'includes/sections/site-snippets/views/editor-log.php'           => array( "Dates::us_time( (string) \$fh_log['created_at'] )" ),
	'includes/sections/health/class-admin.php'                       => array( 'return Dates::stamp( $ts );' ),
	'includes/sections/health/class-alarm.php'                       => array( '$when = Dates::stamp( self::now() );' ),
	'includes/sections/product-checks/class-admin.php'               => array( "Dates::stamp( (int) \$sweep['done'] )" ),
	'includes/sections/med-listings/js/med-listings.js'              => array( 'window.fhDates.stamp( new Date() )' ),
	'includes/sections/med-listings/class-admin.php'                 => array( 'Dates::register_script();', 'Dates::SCRIPT' ),
);
foreach ( $fh_uses as $fh_file => $fh_needles ) {
	$fh_text    = fh_src( $fh_file );
	$fh_missing = array();
	foreach ( $fh_needles as $fh_needle ) {
		if ( false === strpos( $fh_text, $fh_needle ) ) {
			$fh_missing[] = $fh_needle;
		}
	}
	check( $fh_file, array() === $fh_missing, 'missing: ' . implode( ' | ', $fh_missing ) );
}

echo "3. No section writes a year-first date and time for a screen on its own\n";
// 'Y-m-d H:i' (no seconds) was the label format of four helpers; the stored form is 'Y-m-d H:i:s' and stays.
$fh_left = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fh_root . 'includes', FilesystemIterator::SKIP_DOTS ) ) as $fh_file ) {
	$fh_path = str_replace( $fh_root, '', (string) $fh_file );
	if ( '.php' !== substr( $fh_path, -4 ) || false !== strpos( $fh_path, '/tests/' ) ) {
		continue;
	}
	if ( 1 === preg_match( "/'Y-m-d H:i'|'j M Y/", fh_code( $fh_path ) ) ) {
		$fh_left[] = $fh_path;
	}
}
// Kept, named one by one: the Shop app's when() follows the WordPress date setting (Settings → General); its
// year-first form is only what it falls back to where WordPress's wp_date() is missing — never on the site.
$fh_kept = array( 'includes/sections/shop-app/class-admin.php' );
check( "no display helper formats 'Y-m-d H:i' or day-first words any more", array() === array_values( array_diff( $fh_left, $fh_kept ) ), implode( ', ', array_diff( $fh_left, $fh_kept ) ) );
check( 'no page script builds a date for a person with toISOString()', false === strpos( fh_src( 'includes/sections/med-listings/js/med-listings.js' ), 'toISOString' ) && false === strpos( fh_src( 'includes/sections/clean-room/js/front-desk.js' ), 'toISOString' ) && false === strpos( fh_src( 'includes/sections/clean-room/js/clean-room-admin.js' ), 'toISOString' ) );
check( 'no screen asks for a date with prompt()', 0 === preg_match( '/prompt\([^\n]*(date|YYYY)/i', (string) preg_replace( '#^\s*(//|\*|/\*).*$#m', '', fh_src( 'includes/sections/clean-room/js/clean-room-admin.js' ) ) ) );

echo "4. What a program reads keeps YYYY-MM-DD\n";
check( 'the Stays tab: the Edit row\'s calendar and the buttons\' data', false !== strpos( fh_src( 'includes/sections/clean-room/views/stays.php' ), "name=\"checkin_date\" value=\"<?php echo esc_attr( \$fh_stay['checkin_date'] ); ?>\"" ) && false !== strpos( fh_src( 'includes/sections/clean-room/views/stays.php' ), "data-batch=\"<?php echo esc_attr( (string) \$fh_stay['batch_sale_date'] ); ?>\"" ) );
check( 'the product box: the calendar\'s value and the hidden "shown" field', 2 === substr_count( fh_src( 'includes/sections/clean-room/views/meta-box.php' ), "value=\"<?php echo esc_attr( \$fh_words['date'] ); ?>\"" ) );
check( 'the batch calendar on a fish order', false !== strpos( fh_src( 'includes/sections/clean-room/views/order.php' ), "name=\"sale_date\" value=\"<?php echo esc_attr( \$batch['date'] ); ?>\"" ) && false !== strpos( fh_src( 'includes/sections/clean-room/views/order.php' ), "data-date=\"<?php echo esc_attr( \$batch['suggested'] ); ?>\"" ) );
check( 'the Foam cuts day picker', false !== strpos( fh_src( 'includes/sections/foam-cuts/views/plan.php' ), "name=\"ship\" value=\"<?php echo esc_attr( \$orders ? \$orders['ship_date'] : \$today ); ?>\"" ) );
check( 'the Foam cuts REST route: its fields and its own refusal, for programs', false !== strpos( fh_src( 'includes/sections/foam-cuts/class-rest.php' ), "'ship_date must be a date, YYYY-MM-DD.'" ) && false !== strpos( fh_src( 'includes/sections/foam-cuts/class-rest.php' ), "\$data['ship_date']     = \$made['orders']['ship_date'];" ) && false === strpos( fh_src( 'includes/sections/foam-cuts/class-rest.php' ), 'Dates::' ) );
check( 'the stays CSV row', false !== strpos( fh_src( 'includes/sections/clean-room/class-admin.php' ), "self::csv_cell( (string) ( \$stay['checkin_date'] ?? '' ) )," ) && false !== strpos( fh_src( 'includes/sections/clean-room/class-admin.php' ), "self::csv_cell( (string) ( \$stay['sale_ready_date'] ?? '' ) )," ) );
check( 'the event notes', false !== strpos( fh_src( 'includes/sections/clean-room/class-admin.php' ), "'note' => Replay::NOTE_SALE_READY . \$date" ) && false !== strpos( fh_src( 'includes/sections/clean-room/class-admin.php' ), "'note' => Replay::NOTE_CLEARED . \$date" ) );
check( 'the exports and stores never call the helper', false === strpos( fh_src( 'includes/sections/clean-room/class-store.php' ), 'Dates::' ) && false === strpos( fh_src( 'includes/sections/clean-room/class-xlsx.php' ), 'Dates::' ) && false === strpos( fh_src( 'includes/sections/clean-room/class-replay.php' ), 'Dates::' ) && false === strpos( fh_src( 'includes/sections/health/class-store.php' ), 'Dates::' ) );
check( 'the public lottery page keeps its published UTC stamp beside the worded time', false !== strpos( fh_src( 'includes/sections/lottery-draw/views/public.php' ), "<span class=\"fh-draw-utc\"><?php echo esc_html( \$payload['drawn_at'] ); ?></span>" ) );
check( 'invoice titles keep their own 11/3/26', false !== strpos( fh_src( 'includes/sections/invoices/class-format.php' ), 'date_njy' ) );

echo "\n" . ( $fh_failures ? "$fh_failures FAILED of $fh_checks\n" : "all $fh_checks passed\n" );
exit( $fh_failures ? 1 : 0 );
