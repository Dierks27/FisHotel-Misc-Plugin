<?php
/**
 * Visibility — an invoice is found by its link and by nothing else: the
 * products of the Invoices category are taken out of the product
 * sitemap, the REST list of products, the REST search and the Store
 * API's product list (each of which handed a logged-out visitor every
 * invoice: handle, fish, price and link), and an invoice's own page is
 * "noindex". Someone who may manage the shop still sees everything; no
 * product is changed; and an ordinary request asks nothing.
 *
 * Run from the plugin root:  php includes/sections/invoices/tests/visibility-test.php
 */

require_once __DIR__ . '/wp-stubs.php';

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Settings;
use FisHotel\Misc\Sections\Invoices\Store;
use FisHotel\Misc\Sections\Invoices\Visibility;

const KEEPER  = 8;
const VISITOR = 0;

/**
 * The few front-end functions the noindex asks, on the in-memory shop.
 */
$GLOBALS['fh_page']       = array( 'singular' => '', 'id' => 0 );
$GLOBALS['fh_meta_reads'] = 0;

function is_singular( $post_type = '' ) {
	return '' !== $GLOBALS['fh_page']['singular'] && $post_type === $GLOBALS['fh_page']['singular'];
}

function get_queried_object_id() {
	return (int) $GLOBALS['fh_page']['id'];
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$GLOBALS['fh_meta_reads']++;
	$meta = isset( $GLOBALS['fh_wc']['products'][ (int) $post_id ] ) ? $GLOBALS['fh_wc']['products'][ (int) $post_id ]['meta'] : array();
	return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
}

/**
 * A query object with the two methods the Store API hook uses.
 */
class Fh_Test_Query {
	public $vars = array();
	public function __construct( array $vars ) {
		$this->vars = $vars;
	}
	public function get( $key ) {
		return isset( $this->vars[ $key ] ) ? $this->vars[ $key ] : '';
	}
	public function set( $key, $value ) {
		$this->vars[ $key ] = $value;
	}
}

$world                                         = fh_wc_world();
$GLOBALS['fh_user_caps']['manage_woocommerce'] = array( KEEPER );
$GLOBALS['fh_current_user']                    = VISITOR;

$not_in = array(
	'taxonomy' => 'product_cat',
	'field'    => 'slug',
	'terms'    => array( 'invoices' ),
	'operator' => 'NOT IN',
);

echo "1. The hooks\n";
$GLOBALS['fh_filters'] = array();
$GLOBALS['fh_actions'] = array();
Visibility::init();
check_same( 'the sitemap, the REST product list, the REST search, the page\'s robots — and one action for the Store API', array( array( 'wp_sitemaps_posts_query_args', 'rest_product_query', 'rest_post_search_query', 'wp_robots' ), array( 'pre_get_posts' ) ), array( array_keys( $GLOBALS['fh_filters'] ), array_keys( $GLOBALS['fh_actions'] ) ) );
$GLOBALS['fh_filters'] = array();
$GLOBALS['fh_actions'] = array();
update_option( Store::DB_VERSION_OPTION, Store::DB_VERSION, true ); // The tables are there: boot() installs nothing.
Invoices::boot();
delete_option( Store::DB_VERSION_OPTION );
check( 'boot() registers them, and the filter for WooCommerce\'s "Duplicate"', isset( $GLOBALS['fh_filters']['wp_sitemaps_posts_query_args'], $GLOBALS['fh_filters']['rest_product_query'], $GLOBALS['fh_filters']['wp_robots'], $GLOBALS['fh_filters']['woocommerce_duplicate_product_exclude_meta'], $GLOBALS['fh_actions']['pre_get_posts'] ) );

echo "2. \"Not in the Invoices category\", added to whatever a query already asks\n";
check_same( 'a query without taxonomy conditions', array( 'post_type' => 'product', 'tax_query' => array( $not_in ) ), Visibility::without_invoices( array( 'post_type' => 'product' ), array( 'invoices' ) ) );
$theirs = array(
	'relation' => 'OR',
	array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => array( 'reef-safe' ) ),
	array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array( 'quarantined-fish' ) ),
);
check_same( 'a query with its own conditions keeps them as they are (their OR stays an OR), AND ours', array( 'tax_query' => array( 'relation' => 'AND', $theirs, $not_in ) ), Visibility::without_invoices( array( 'tax_query' => $theirs ), array( 'invoices' ) ) );
check_same( 'no slug, nothing added; arguments that are no array become one', array( array( 'a' => 1 ), array( 'tax_query' => array( $not_in ) ) ), array( Visibility::without_invoices( array( 'a' => 1 ), array() ), Visibility::without_invoices( null, array( 'invoices' ) ) ) );
check_same( 'the slugs: the theme\'s fixed "invoices"', array( 'invoices' ), Visibility::slugs() );
update_option( Settings::OPTION_KEY, array_merge( Settings::defaults(), array( 'category_slug' => 'bills' ) ), false );
check_same( '…and the one named in Settings, when it is another', array( 'invoices', 'bills' ), Visibility::slugs() );
delete_option( Settings::OPTION_KEY );

echo "3. The product sitemap\n";
check_same( 'products: without the invoices — for every visitor, the shop keeper too (a sitemap is public)', array( 'post_type' => 'product', 'tax_query' => array( $not_in ) ), apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'product' ), 'product' ) );
check_same( 'the sitemap of posts or pages is not touched', array( array( 'post_type' => 'post' ), array( 'post_type' => 'page' ) ), array( apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'post' ), 'post' ), apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'page' ), 'page' ) ) );

echo "4. The REST list of products and the REST search\n";
check_same( 'wp/v2/product for a visitor: without the invoices', array( 'tax_query' => array( $not_in ) ), apply_filters( 'rest_product_query', array(), null ) );
check_same( 'wp/v2/search that looks at products (or at everything): without the invoices', array( array( 'post_type' => array( 'post', 'product' ), 'tax_query' => array( $not_in ) ), array( 'post_type' => 'any', 'tax_query' => array( $not_in ) ) ), array( apply_filters( 'rest_post_search_query', array( 'post_type' => array( 'post', 'product' ) ), null ), apply_filters( 'rest_post_search_query', array( 'post_type' => 'any' ), null ) ) );
check_same( 'a search of posts and pages only is not touched', array( 'post_type' => array( 'post', 'page' ) ), apply_filters( 'rest_post_search_query', array( 'post_type' => array( 'post', 'page' ) ), null ) );
$GLOBALS['fh_current_user'] = KEEPER;
check_same( 'someone who may manage the shop still gets everything', array( array(), array( 'post_type' => 'any' ) ), array( apply_filters( 'rest_product_query', array(), null ), apply_filters( 'rest_post_search_query', array( 'post_type' => 'any' ), null ) ) );
$GLOBALS['fh_current_user'] = VISITOR;

echo "5. The Store API's product list\n";
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wc/store/v1/products' ) );
$query         = new Fh_Test_Query( array( 'post_type' => 'product' ) );
do_action( 'pre_get_posts', $query );
check_same( 'not a REST request (an ordinary page builds a product query): untouched', array( 'post_type' => 'product' ), $query->vars );
define( 'REST_REQUEST', true );
do_action( 'pre_get_posts', $query );
check_same( 'wc/store/v1/products for a visitor: without the invoices', array( 'post_type' => 'product', 'tax_query' => array( $not_in ) ), $query->vars );
$query = new Fh_Test_Query( array( 'post_type' => array( 'product', 'product_variation' ), 'tax_query' => $theirs ) );
do_action( 'pre_get_posts', $query );
check_same( 'a Store API query with its own conditions keeps them', array( 'relation' => 'AND', $theirs, $not_in ), $query->vars['tax_query'] );
$query = new Fh_Test_Query( array( 'post_type' => 'post' ) );
do_action( 'pre_get_posts', $query );
check_same( 'a query for something else inside that request is not touched', array( 'post_type' => 'post' ), $query->vars );
$GLOBALS['wp']->query_vars['rest_route'] = '/wc/v3/products';
$query                                   = new Fh_Test_Query( array( 'post_type' => 'product' ) );
do_action( 'pre_get_posts', $query );
check_same( 'another REST route (the shop\'s own, signed-in API) is not touched', array( 'post_type' => 'product' ), $query->vars );
$GLOBALS['wp']->query_vars['rest_route'] = '/wc/store/v1/products';
$GLOBALS['fh_current_user']              = KEEPER;
do_action( 'pre_get_posts', $query );
check_same( 'someone who may manage the shop still gets everything', array( 'post_type' => 'product' ), $query->vars );
$GLOBALS['fh_current_user'] = VISITOR;

echo "6. WooCommerce switched off\n";
$terms = $GLOBALS['fh_wc']['terms'];
unset( $GLOBALS['fh_wc']['terms']['product_cat'] );
$query = new Fh_Test_Query( array( 'post_type' => 'product' ) );
do_action( 'pre_get_posts', $query );
check( 'without the product_cat taxonomy nothing is added anywhere — a condition on a taxonomy that is not there would empty the list', array( 'post_type' => 'product' ) === $query->vars && array() === apply_filters( 'rest_product_query', array(), null ) && array() === apply_filters( 'wp_sitemaps_posts_query_args', array(), 'product' ) && array( 'post_type' => 'any' ) === apply_filters( 'rest_post_search_query', array( 'post_type' => 'any' ), null ) );
$GLOBALS['fh_wc']['terms'] = $terms;

echo "7. An invoice's own page: noindex\n";
$built = fh_wc_product( array( 'name' => 'Tester Invoice (11/3/26)', 'category_ids' => array( $world['cat_invoices'] ) ), array( Invoices::PRODUCT_MARKER_META => 4 ) );
$hand  = fh_wc_product( array( 'name' => 'Handy Invoice (7/3/26)', 'category_ids' => array( $world['cat_invoices'] ) ) );
$fish  = fh_wc_product( array( 'name' => 'Blue Test Tang', 'category_ids' => array( $world['cat_fish'] ) ) );
$base  = array( 'max-image-preview' => 'large' );
$GLOBALS['fh_page'] = array( 'singular' => 'product', 'id' => $built );
check_same( 'an invoice built here', array( 'noindex' => true ), apply_filters( 'wp_robots', $base ) );
$GLOBALS['fh_page']['id'] = $hand;
check_same( 'an invoice made by hand (the Invoices category)', array( 'noindex' => true ), apply_filters( 'wp_robots', $base ) );
$GLOBALS['fh_page']['id'] = $fish;
check_same( 'a fish of the shop is left alone', $base, apply_filters( 'wp_robots', $base ) );
$GLOBALS['fh_page']      = array( 'singular' => '', 'id' => $built );
$GLOBALS['fh_meta_reads'] = 0;
check( 'a page that is not a single product: returned at once, nothing looked up', $base === apply_filters( 'wp_robots', $base ) && 0 === $GLOBALS['fh_meta_reads'] );
check_same( 'directives that are no array pass through', 'x', apply_filters( 'wp_robots', 'x' ) );

echo "8. Nothing is changed, and an ordinary request asks nothing\n";
check( 'no product was written by any of this', array() === $GLOBALS['fh_wc']['writes'] );
$source = (string) file_get_contents( dirname( __DIR__ ) . '/class-visibility.php' );
check( 'the settings are read in ONE place, which only the list hooks reach — the noindex and the first lines of the Store API hook do not', 1 === substr_count( $source, 'Settings::get()' ) && false === strpos( substr( $source, strpos( $source, 'public static function robots' ) ), 'slugs()' ) );
check( 'it writes nothing: no product, no option, no query of its own', ! preg_match( '/\b(update_option|update_post_meta|wp_update_post|wp_insert_post|->save\(|\$wpdb)\b/', $source ) );

fh_finish();
