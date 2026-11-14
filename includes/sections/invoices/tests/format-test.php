<?php
/**
 * Format — the product title with the site date as n/j/y, the short
 * description, the image a total gets (under the threshold / at or over
 * it; none when unset), the forum list (one plain line per invoice, no
 * bold and no dash added, the BBCode mention for a handle with a forum
 * user id) and the UTC bounds of a site day.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/format-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Format;
use FisHotel\Misc\Sections\Invoices\Invoices;

echo "1. The date in a title: month / day / two-digit year, no leading zeros\n";
check_same( 'a November day', '11/3/26', Format::date_njy( '2026-11-03' ) );
check_same( 'a single-digit month and day', '7/3/26', Format::date_njy( '2026-07-03' ) );
check_same( 'two digits each', '12/25/26', Format::date_njy( '2026-12-25' ) );
check_same( 'the first day of a year ending in 0x', '1/1/09', Format::date_njy( '2009-01-01' ) );
check_same( 'a leap day', '2/29/28', Format::date_njy( '2028-02-29' ) );
check_same( 'not a date: nothing', array( '', '', '', '', '' ), array( Format::date_njy( '2026-02-30' ), Format::date_njy( '11/3/26' ), Format::date_njy( '' ), Format::date_njy( null ), Format::date_njy( '2026-13-01' ) ) );

echo "2. Title and short description\n";
check_same( 'the default title', 'Tester Invoice (11/3/26)', Format::title( Format::DEFAULT_TITLE, 'Tester', '2026-11-03' ) );
check_same( 'the handle exactly as typed', 'T&j_99 Invoice (1/9/27)', Format::title( '{handle} Invoice ({date})', 'T&j_99', '2027-01-09' ) );
check_same( 'another format', 'Invoice for Tester, 11/3/26', Format::title( 'Invoice for {handle}, {date}', 'Tester', '2026-11-03' ) );
check_same( 'a format that does not name the handle falls back to the default', 'Tester Invoice (11/3/26)', Format::title( 'Invoice ({date})', 'Tester', '2026-11-03' ) );
check_same( 'an empty format falls back too', 'Tester Invoice (11/3/26)', Format::title( '', 'Tester', '2026-11-03' ) );
check_same( 'the default short description', 'Tester Only', Format::excerpt( Format::DEFAULT_EXCERPT, 'Tester' ) );
check_same( 'another short description', 'For Tester', Format::excerpt( 'For {handle}', 'Tester' ) );
check( 'usable(): only a format with {handle}', Format::usable( '{handle} x' ) && ! Format::usable( 'x' ) && ! Format::usable( null ) && ! Format::usable( array() ) );
$GLOBALS['fh_now'] = '2026-11-03 23:30:00';
check_same( 'today() is the SITE date (the stubbed site clock)', '2026-11-03', Invoices::today() );
check_same( 'so a title made late in the evening carries that day', 'Tester Invoice (11/3/26)', Format::title( '', 'Tester', Invoices::today() ) );
$GLOBALS['fh_now'] = '2026-11-03 09:00:00';

echo "3. The image by threshold\n";
check_same( 'under the threshold: the "under" image', 11, Format::image_for_total( 399.99, 400, 11, 22 ) );
check_same( 'exactly at the threshold: the "at or over" image', 22, Format::image_for_total( 400, 400, 11, 22 ) );
check_same( 'over it', 22, Format::image_for_total( 1250.5, 400, 11, 22 ) );
check_same( 'a total of 0', 11, Format::image_for_total( 0, 400, 11, 22 ) );
check_same( 'the image for that side not set: none', 0, Format::image_for_total( 100, 400, 0, 22 ) );
check_same( 'neither set: none', 0, Format::image_for_total( 500, 400, 0, 0 ) );
check_same( 'another threshold', array( 11, 22 ), array( Format::image_for_total( 249.99, '250', 11, 22 ), Format::image_for_total( 250, '250', 11, 22 ) ) );

echo "4. The forum list\n";
check_same( 'one line: @Handle $Total link', '@Tester $599 https://example.test/shop/invoices/tester-invoice-11-3-26/', Format::forum_line( array( 'handle' => 'Tester', 'forum_user_id' => null, 'total' => 599, 'url' => 'https://example.test/shop/invoices/tester-invoice-11-3-26/' ) ) );
check_same( 'cents are kept, thousands are grouped', '@Tester $1,250.50 https://example.test/x/', Format::forum_line( array( 'handle' => 'Tester', 'total' => 1250.5, 'url' => 'https://example.test/x/' ) ) );
check_same( 'a handle with a forum user id: the BBCode mention', '[USER=12456]T&j[/USER] $7 https://example.test/x/', Format::forum_line( array( 'handle' => 'T&j', 'forum_user_id' => 12456, 'total' => 7, 'url' => 'https://example.test/x/' ) ) );
check_same( 'forum_mention()', array( '@Tester', '[USER=5]Tester[/USER]', '@Tester' ), array( Format::forum_mention( 'Tester', 0 ), Format::forum_mention( ' Tester ', '5' ), Format::forum_mention( 'Tester', null ) ) );
$list = Format::forum_list(
	array(
		array( 'handle' => 'Tester', 'forum_user_id' => null, 'total' => 599, 'url' => 'https://example.test/shop/invoices/tester-invoice-11-3-26/' ),
		array( 'handle' => 'T&j', 'forum_user_id' => 12456, 'total' => 82.5, 'url' => 'https://example.test/shop/invoices/tj-invoice-11-3-26/' ),
		array( 'handle' => '', 'total' => 5, 'url' => 'https://example.test/none/' ),
		array( 'handle' => 'Reefer Two', 'total' => 0, 'url' => '' ),
	)
);
check_same( 'the list: one line each, rows without a handle left out, a missing link simply absent', "@Tester \$599 https://example.test/shop/invoices/tester-invoice-11-3-26/\n[USER=12456]T&j[/USER] \$82.50 https://example.test/shop/invoices/tj-invoice-11-3-26/\n@Reefer Two \$0", $list );
$plain = preg_replace( '~https?://\S+~', '', $list );
check( 'no bold, no dash of any kind, no markup added outside the links', false === strpos( $plain, '*' ) && ! preg_match( '/[\x{2014}\x{2013}\-]/u', $plain ) && false === strpos( $plain, '<' ) && false === strpos( $plain, '_' ), $plain );
check_same( 'nothing to list: an empty text', '', Format::forum_list( array() ) );

echo "5. A site day in UTC\n";
$zone = new DateTimeZone( 'America/Chicago' );
check_same( 'a winter day: six hours behind UTC', array( '2026-11-03 06:00:00', '2026-11-04 06:00:00' ), Format::day_bounds_utc( '2026-11-03', $zone ) );
check_same( 'a summer day: five hours behind', array( '2026-07-03 05:00:00', '2026-07-04 05:00:00' ), Format::day_bounds_utc( '2026-07-03', $zone ) );
check_same( 'the day the clocks go back is 25 hours long', array( '2026-11-01 05:00:00', '2026-11-02 06:00:00' ), Format::day_bounds_utc( '2026-11-01', $zone ) );
check_same( 'UTC itself', array( '2026-11-03 00:00:00', '2026-11-04 00:00:00' ), Format::day_bounds_utc( '2026-11-03', new DateTimeZone( 'UTC' ) ) );
check_same( 'not a date: no bounds', null, Format::day_bounds_utc( 'today', $zone ) );

fh_finish();
