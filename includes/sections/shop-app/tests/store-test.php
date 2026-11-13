<?php
/**
 * Store tests — the schema as dbDelta wants it, the install behind the
 * autoloaded version option (no query once installed; the option written
 * only when every table and the column a later version added really
 * exist; a failed install retried in wp-admin at most once an hour), the
 * site secret made with the tables, names cut by characters and not by
 * bytes, the access log that can only grow, $wpdb in one file only, the
 * in-memory twin the other tests use keeping pace with the real store,
 * and the section as the Section Manager finds it.
 *
 * Run from the plugin root:
 *   php includes/sections/shop-app/tests/store-test.php
 *
 * @package FisHotel\Misc\Sections\Shop_App
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Shop_App\Settings;
use FisHotel\Misc\Sections\Shop_App\Shop_App;
use FisHotel\Misc\Sections\Shop_App\Store;
use FisHotel\Misc\Sections\Shop_App\Tests\Fake_Store;

$dir  = dirname( __DIR__ ) . '/';
$wpdb = $GLOBALS['wpdb'];

echo "1. The schema\n";
$schema = Store::schema( 'DEFAULT CHARACTER SET utf8mb4' );
check( 'four tables: devices, people, log, saves', 4 === count( $schema ) && array( 'devices', 'people', 'log', 'saves' ) === Store::TABLES );
foreach ( Store::TABLES as $i => $name ) {
	check( 'CREATE TABLE wp_fh_shopapp_' . $name, 0 === strpos( $schema[ $i ], 'CREATE TABLE wp_fh_shopapp_' . $name . ' (' ) && 'wp_fh_shopapp_' . $name === Store::table( $name ) );
}
$all   = implode( "\n", $schema );
$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $all ) ) ) );
$types = array();
foreach ( $lines as $line ) {
	if ( preg_match( '/^([a-z_]+) ([a-z]+(\(\d+\))?( unsigned)?) /', $line, $m ) ) {
		$types[] = $m[2];
	} elseif ( preg_match( '/^[a-z_]+ [A-Za-z]+/', $line ) && ! preg_match( '/^(PRIMARY|UNIQUE|KEY|CREATE|\))/', $line ) ) {
		check( 'a column type as MySQL reports it: ' . $line, false );
	}
}
check( 'every column type is written as MySQL / MariaDB report it (lower case, with its width): dbDelta then alters nothing on a second run', count( $types ) >= 40 && array() === array_diff( array_unique( $types ), array( 'bigint(20) unsigned', 'varchar(80)', 'varchar(10)', 'varchar(30)', 'varchar(191)', 'char(64)', 'char(32)', 'datetime', 'smallint(5) unsigned', 'tinyint(3) unsigned', 'tinyint(1)', 'text' ) ), implode( ', ', array_unique( $types ) ) );
check( 'two spaces after PRIMARY KEY, KEY (never INDEX), one column per line', 4 === substr_count( $all, 'PRIMARY KEY  (id)' ) && false === stripos( $all, 'INDEX' ) && false === strpos( $all, ', ' ) );
check( 'the device row has a place for the token\'s hash — and none for a token', false !== strpos( $schema[0], 'token_hash char(64)' ) && false !== strpos( $schema[0], 'enrol_hash char(64)' ) && ! preg_match( '/\btoken (var)?char/', $schema[0] ) );
check( 'the people row has a place for the PIN\'s hash, salt and length — and none for a PIN', false !== strpos( $schema[1], 'pin_hash char(64)' ) && false !== strpos( $schema[1], 'pin_salt char(32)' ) && false !== strpos( $schema[1], 'pin_length' ) && ! preg_match( '/\bpin (var)?char/', $schema[1] ) );
check( 'a save id can be claimed once: a unique key', false !== strpos( $schema[3], 'UNIQUE KEY save_key (save_key)' ) );
check( 'the device row says when its count of wrong PINs began (1.1)', false !== strpos( $schema[0], "fail_since datetime NULL,\n" ) && '1.1' === Store::DB_VERSION );

echo "\n2. Install, behind the version option\n";
check( 'not installed yet', ! Store::installed() );
$GLOBALS['fh_option_reads'] = array();
$ran                        = Store::maybe_install( false );
check( 'the first boot installs: dbDelta once per table', $ran && 4 === count( $wpdb->deltas ) );
check( 'the version is stored — autoloaded', Store::DB_VERSION === $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] && true === $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ] && Store::installed() );
check( 'the site secret was made with the tables: 64 hex characters, NOT autoloaded', 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] ) && false === $GLOBALS['fh_autoload'][ Settings::SECRET_OPTION ] );

$wpdb->queries              = array();
$wpdb->deltas               = array();
$GLOBALS['fh_option_reads']  = array();
$GLOBALS['fh_option_writes'] = array();
$ran                         = Store::maybe_install( false );
check( 'every later boot: nothing runs', false === $ran );
check( '… no query, no SHOW TABLES, no dbDelta', array() === $wpdb->queries && array() === $wpdb->deltas );
check( '… one read of an autoloaded option, no write', array() === $GLOBALS['fh_option_reads'] && array() === $GLOBALS['fh_option_writes'] );
$secret = $GLOBALS['fh_options'][ Settings::SECRET_OPTION ];
Store::install( true );
check( 'a forced install (plugin activation) runs dbDelta again and keeps the secret', 4 === count( $wpdb->deltas ) && $secret === $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] );

echo "\n3. An install that fails\n";
delete_option( Store::DB_VERSION_OPTION );
delete_option( Settings::SECRET_OPTION );
$wpdb->tables   = array();
$wpdb->deltas   = array();
$wpdb->delta_ok = false; // dbDelta runs but no table appears.
Store::maybe_install( false );
$marker = (string) $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ];
check( 'the version is NOT written; a marker with the time is (autoloaded)', 1 === preg_match( '/^failed:1\.1:\d+$/', $marker ) && true === $GLOBALS['fh_autoload'][ Store::DB_VERSION_OPTION ] && ! Store::installed() );
check( '… and no secret is made for tables that are not there', ! isset( $GLOBALS['fh_options'][ Settings::SECRET_OPTION ] ) );
$wpdb->deltas  = array();
$wpdb->queries = array();
check( 'a front-end request does not try again (a visitor never pays a dbDelta)', false === Store::maybe_install( false ) && array() === $wpdb->deltas && array() === $wpdb->queries );
check( 'wp-admin does not try again within the hour', false === Store::maybe_install( true ) && array() === $wpdb->deltas );
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = 'failed:1.1:' . ( time() - Store::RETRY_SECONDS - 5 );
$wpdb->delta_ok                                    = true;
check( 'wp-admin tries again after an hour — and this time it works', true === Store::maybe_install( true ) && 4 === count( $wpdb->deltas ) && Store::installed() );

$wpdb->tables = array( 'wp_fh_shopapp_devices', 'wp_fh_shopapp_people', 'wp_fh_shopapp_log' );
check( 'three of four tables is not "installed"', ! Store::tables_exist() );
$wpdb->tables[] = 'wp_fh_shopapp_saves';
check( 'four of four is', Store::tables_exist() );

echo "\n3b. An upgrade from 1.0: the new column must really be there\n";
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = '1.0';
$wpdb->deltas                                      = array();
$wpdb->no_columns                                  = array( 'fail_since' ); // dbDelta ran, the ALTER did not take.
check( 'a site on 1.0 is not "installed"; the next boot runs dbDelta', ! Store::installed() && true === Store::maybe_install( false ) && 4 === count( $wpdb->deltas ) );
check( '… and with the column still missing the version is NOT written (a marker is)', 0 === strpos( (string) $GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ], 'failed:1.1:' ) && ! Store::columns_exist() && Store::tables_exist() );
$wpdb->no_columns                                  = array();
$GLOBALS['fh_options'][ Store::DB_VERSION_OPTION ] = '1.0';
check( 'with the column there the version is written', true === Store::maybe_install( false ) && Store::installed() && Store::columns_exist() );

echo "\n3c. Names are cut by characters, as the columns count them\n";
$accented = 'a' . str_repeat( 'é', 40 );      // 41 characters, 81 bytes.
$cjk      = str_repeat( '漁', 27 );            // 27 characters, 81 bytes.
$long     = str_repeat( 'é', 120 );
check( 'a name of 41 characters that is 81 bytes long is kept whole (cut by bytes it would end in half a letter, and the row would be refused)', $accented === Store::cut( $accented, 80 ) && $cjk === Store::cut( $cjk, 80 ) );
check( 'a name that is too long is cut to 80 characters — still valid text', str_repeat( 'é', 80 ) === Store::cut( $long, 80 ) && 1 === preg_match( '//u', Store::cut( $long, 80 ) ) && 1 === preg_match( '//u', Store::cut( 'ab' . $cjk, 3 ) ) && 'ab漁' === Store::cut( 'ab' . $cjk, 3 ) );
check( 'plain names, an empty one and a number come out as strings', 'Shop tablet' === Store::cut( 'Shop tablet', 80 ) && '' === Store::cut( '', 80 ) && '12' === Store::cut( 12, 80 ) && '' === Store::cut( null, 80 ) );
$twin = new Fake_Store();
$pid  = $twin->insert_person( array( 'name' => $accented, 'active' => 1 ) );
$did  = $twin->create_device( array( 'name' => $cjk, 'status' => Store::STATUS_ACTIVE ) );
$twin->append_log( array( 'event' => 'x', 'device_name' => $cjk, 'person_name' => $accented, 'note' => str_repeat( 'é', 300 ) ) );
check( 'the twin keeps such names whole too (person, device, log)', $accented === $twin->people[ $pid ]['name'] && $cjk === $twin->devices[ $did ]['name'] && $accented === $twin->log[0]['person_name'] && str_repeat( 'é', 191 ) === $twin->log[0]['note'] );
check( 'no write of the store cuts by bytes any more (one substr() is left: the last resort on a server without mbstring and with broken text)', 1 === preg_match_all( '/(?<!mb_)substr\( \$value/', (string) file_get_contents( $dir . 'class-store.php' ) ) && false === strpos( (string) file_get_contents( __DIR__ . '/fake-store.php' ), 'substr(' ) );

echo "\n4. Every method survives a database that answers nothing\n";
$store = new Store();
check( 'reads give null or an empty list', null === $store->device_by_token_hash( str_repeat( 'a', 64 ) ) && null === $store->device( 1 ) && array() === $store->devices() && array() === $store->people() && null === $store->person( 1 ) && array() === $store->log_rows() && 0 === $store->log_count() && null === $store->pending_by_enrol_hash( str_repeat( 'a', 64 ), time() ) );
check( 'writes say they did not happen', false === $store->create_device( array( 'name' => 'x' ) ) && false === $store->insert_person( array( 'name' => 'x' ) ) && false === $store->append_log( array( 'event' => 'x' ) ) && false === $store->revoke_device( 1, 1, time() ) && false === $store->reserve_attempt( 1, 5, time(), 900 ) && false === $store->lock_device( 1, array( 'locked_until' => '2030-01-01 00:00:00', 'lock_level' => 1 ), 5 ) && null === $store->claim_enrolment( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), time() ) );
check( 'a hash of the wrong length is never even looked up', ( function () use ( $store, $wpdb ) {
	$wpdb->queries = array();
	$store->device_by_token_hash( 'short' );
	$store->pending_by_enrol_hash( "x' OR 1=1", time() );
	$store->claim_enrolment( 'short', 'short', time() );
	$store->claim_save( 'short', 1, time(), 30 );
	return array() === $wpdb->queries;
} )() );
check( 'ending the sessions of nobody (person 0) is not even asked of the database', ( function () use ( $store, $wpdb ) {
	$wpdb->queries = array();
	return 0 === $store->end_person_sessions( 0 ) && array() === $wpdb->queries;
} )() );
check( 'update_device() and update_person() write only the columns they know', false === $store->update_device( 1, array( 'status' => 'active', 'token_hash' => 'x', 'enrolled_by' => 9 ) ) && false === $store->update_person( 1, array( 'id' => 5, 'created_by' => 9 ) ) );

echo "\n5. The log can only grow; \$wpdb lives in one file\n";
$source  = (string) file_get_contents( $dir . 'class-store.php' );
$methods = array();
preg_match_all( '/\n\tpublic (?:static )?function (\w+)\([^{]*\{(.*?)\n\t\}/s', $source, $found, PREG_SET_ORDER );
foreach ( $found as $m ) {
	$methods[ $m[1] ] = $m[2];
}
$touch_log = array();
foreach ( $methods as $name => $body ) {
	if ( preg_match( "/table\( 'log' \)|rows\( 'log'/", $body ) ) {
		$touch_log[] = $name;
	}
}
sort( $touch_log );
check( 'only four methods name the log table: schema (which creates it), append_log, log_count, log_rows', array( 'append_log', 'log_count', 'log_rows', 'schema' ) === $touch_log, implode( ', ', $touch_log ) );
check( 'append_log() inserts', false !== strpos( $methods['append_log'], '$wpdb->insert( self::table( \'log\' )' ) && false === strpos( $methods['append_log'], 'update' ) && false === strpos( $methods['append_log'], 'delete' ) );
check( 'the two readers only read', ! preg_match( '/->(update|delete|insert|query)\(/', $methods['log_rows'] . $methods['log_count'] ) );
check( 'no statement anywhere in the file updates or deletes from a table chosen at run time', ! preg_match( '/(UPDATE|DELETE FROM)\s+\'\s*\.\s*self::table\(\s*\$/', $source ) && ! preg_match( '/->(update|delete)\(\s*self::table\(\s*\$/', $source ) );
check( 'the in-memory twin has no way to change or drop a log row either', ! preg_match( '/unset\( \$this->log|\$this->log\[[^\]]+\]\[|\$this->log = /', (string) file_get_contents( __DIR__ . '/fake-store.php' ) ) );

$elsewhere = array();
foreach ( array_merge( glob( $dir . '*.php' ), glob( $dir . 'views/*.php' ), glob( $dir . 'tools/*.php' ) ) as $file ) {
	if ( 'class-store.php' === basename( $file ) ) {
		continue;
	}
	// Code only: a comment may speak of $wpdb.
	$code = '';
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	if ( false !== strpos( $code, '$wpdb' ) || false !== strpos( $code, 'wpdb->' ) ) {
		$elsewhere[] = basename( $file );
	}
}
check( 'class-store.php is the only file of the section whose code touches $wpdb', array() === $elsewhere, implode( ', ', $elsewhere ) );

echo "\n6. The in-memory store keeps pace with the real one\n";
$real = new ReflectionClass( Store::class );
$fake = new ReflectionClass( Fake_Store::class );
$gaps = array();
foreach ( $real->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
	if ( $method->isStatic() ) {
		continue;
	}
	if ( ! $fake->hasMethod( $method->getName() ) ) {
		$gaps[] = $method->getName() . ' is missing';
	} elseif ( $fake->getMethod( $method->getName() )->getNumberOfParameters() !== $method->getNumberOfParameters() ) {
		$gaps[] = $method->getName() . ' takes other arguments';
	}
}
check( 'every method of the store exists in the twin, with the same arguments', array() === $gaps, implode( '; ', $gaps ) );

echo "\n7. The section, as the Section Manager finds it\n";
$info = Shop_App::get_section_info();
check( 'the folder shop-app holds class-shop-app.php with the class Shop_App in the namespace the manager derives', is_file( $dir . 'class-shop-app.php' ) && class_exists( 'FisHotel\\Misc\\Sections\\Shop_App\\Shop_App' ) );
check( 'it has a name, a description and an icon', 'Shop App' === $info['name'] && strlen( $info['description'] ) > 40 && 0 === strpos( $info['icon'], 'dashicons-' ) );
foreach ( array( 'get_section_info', 'load', 'boot', 'register_menu', 'activate', 'deactivate' ) as $method ) {
	if ( ! method_exists( Shop_App::class, $method ) || ! ( new ReflectionMethod( Shop_App::class, $method ) )->isStatic() ) {
		check( 'static ' . $method . '()', false );
	}
}
check( 'get_section_info / load / boot / register_menu / activate / deactivate are all there, static', true );
check( 'it is off until switched on: nothing in the plugin enables it', false === strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'fishotel-misc-plugin.php' ), 'shop-app' ) && false === strpos( (string) file_get_contents( FISHOTEL_MISC_PATH . 'includes/class-section-manager.php' ), 'shop-app' ) );
Shop_App::register_menu();
check( 'its page sits under FisHotel Tools, for manage_woocommerce', 'fishotel-misc' === $GLOBALS['fh_submenu'][0] && 'Shop App' === $GLOBALS['fh_submenu'][2] && 'manage_woocommerce' === $GLOBALS['fh_submenu'][3] && 'fishotel-misc-shop-app' === $GLOBALS['fh_submenu'][4] );
$wpdb->deltas = array();
delete_option( Store::DB_VERSION_OPTION );
Shop_App::activate();
check( 'activation installs the tables', 4 === count( $wpdb->deltas ) && Store::installed() );
check( 'the global helper exists and says "nobody" outside a shop session', function_exists( 'fishotel_shop_app_person' ) && null === fishotel_shop_app_person() );
check( 'the app\'s address works with any permalink setting: a query on the home address', 'https://shop.example.test/?fh_shop_app=1' === Shop_App::app_url() && 'https://shop.example.test/?fh_shop_app=1&tank=521' === Shop_App::app_url( 521 ) && 'https://shop.example.test/?fh_shop_app=state' === Shop_App::route_url( 'state' ) );
check( 'without the Clean Room the app says plainly what is missing', false === Shop_App::front_desk()['available'] && false !== strpos( Shop_App::front_desk()['reason'], 'Clean Room section is switched off' ) && 'https://shop.example.test/?fh_front_desk=1&tank=521' === Shop_App::label_url( 521 ) );

fh_finish();
