<?php
/**
 * fishotel_requests_create() — the way in for another section: a customer
 * who chose "try again" after a lost fish gets a request that is owed,
 * never has a fee and is on the list at once; while the Requests section
 * is switched off (or its tables are not installed) the function answers
 * a WP_Error and creates nothing; fishotel_requests_get() reads one back.
 *
 * Run from the plugin root:  php includes/sections/requests/tests/create-test.php
 */

require_once __DIR__ . '/wp-stubs.php';
require_once FISHOTEL_MISC_PATH . 'includes/sections/requests/class-requests.php';

use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Store;

$GLOBALS['fh_users'] = array(
	7 => array( 'login' => 'pat', 'email' => 'pat@example.test', 'first' => 'Pat', 'last' => 'Example', 'meta' => array( '_fishotel_humble_username' => 'reef_pat' ) ),
);

echo "fishotel_requests_create() — a lost fish\n";

$service = fh_service();
$store   = $service->store;
Requests::use_service( $service );
fh_take_mails();

$GLOBALS['fh_options'] = array();
$result                = fishotel_requests_create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse', 'source' => 'lost_fish' ) );
check( 'while the section is switched off: a WP_Error, nothing created', is_wp_error( $result ) && array() === $store->rows );
update_option( 'fishotel_misc_enabled_sections', array( 'clean-room', 'requests' ) );
check( 'while the tables are not installed: a WP_Error', is_wp_error( fishotel_requests_create( array( 'user_id' => 7, 'fish' => 'Blue Test Wrasse', 'source' => 'lost_fish' ) ) ) );
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION );

$GLOBALS['fh_product_titles'] = array( 501 => 'Green Test Tang' );
$GLOBALS['fh_fired']          = array();
$id                           = fishotel_requests_create(
	array(
		'user_id'    => 7,
		'product_id' => 501,
		'size_slug'  => '2-3-5',
		'size_label' => '2 - 3.5"',
		'source'     => 'lost_fish',
		'fee'        => 30,
		'order_id'   => 4321,
		'note'       => 'Lost in transit.',
	)
);
check( 'it answers the new id', is_int( $id ) && $id > 0 );
$r = $store->get( $id );
check_same( 'on the list at once', 'waiting', $r['state'] );
check_same( 'owed', 1, $r['owed'] );
check_same( 'NEVER a fee, whatever was passed', array( 0.0, null ), array( $r['fee_amount'], $r['fee_ref'] ) );
check_same( 'the source', 'lost_fish', $r['source'] );
check_same( 'the fish is named after its product', 'Green Test Tang', $r['fish'] );
check_same( 'the size as handed over', array( '2-3-5', '2 - 3.5"' ), array( $r['size_slug'], $r['size_label'] ) );
check_same( 'the order it came from', 4321, $r['from_order_id'] );
check_same( 'the customer', array( 7, 'pat@example.test' ), array( $r['user_id'], $r['email'] ) );
check_same( 'no e-mail', array(), fh_take_mails() );
check_contains( 'the log says it is owed', 'owed', $store->log[0]['note'] );
check_same( 'the hook other sections can listen to fired', 1, fh_fired( 'fishotel_requests_created' ) );
check_same( 'fishotel_requests_get() reads it back', array( $id, 'waiting' ), array( (int) ( fishotel_requests_get( $id )['id'] ?? 0 ), (string) ( fishotel_requests_get( $id )['state'] ?? '' ) ) );
check( 'and answers null for a request that is not there', null === fishotel_requests_get( 9999 ) );
check( 'a lost fish without a name or a customer: a WP_Error', is_wp_error( fishotel_requests_create( array( 'fish' => 'X', 'source' => 'lost_fish' ) ) ) );
check( 'a request with the default source is by hand', 'hand' === $store->get( fishotel_requests_create( array( 'name' => 'Lee', 'fish' => 'X' ) ) )['source'] );
Requests::use_service( null );

fh_finish();
