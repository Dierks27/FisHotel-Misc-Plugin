<?php
/**
 * Just enough WordPress and WooCommerce, in memory, to run the Med
 * listings section headless: options, posts, post meta, terms and the
 * products in them, WC products with attributes, comments. Every
 * product, word and order in the tests is invented.
 *
 * The functions behave as WordPress's do where the section relies on it:
 * update_post_meta() and wp_update_post() unslash what they are given,
 * metadata_exists() tells "not there" from "empty", wp_delete_term()
 * takes the term off its products, a product with no category left gets
 * the default one.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

define( 'ABSPATH', true );
define( 'FISHOTEL_MISC_PATH', dirname( __DIR__, 4 ) . '/' );
define( 'FISHOTEL_MISC_URL', 'https://example.test/wp-content/plugins/fishotel-misc-plugin/' );
define( 'FISHOTEL_MISC_VERSION', 'test' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['fh'] = array();

/** Empty the store. */
function fh_reset() {
	$GLOBALS['fh'] = array(
		'options'  => array(),
		'posts'    => array(),
		'meta'     => array(),
		'terms'    => array(),
		'rel'      => array(), // "tax" => [object_id => [term_id, …]]
		'attrs'    => array(), // product id => [key => WC_Product_Attribute]
		'comments' => array(),
		'next'     => 1000,
		'user'     => 1,
		'redirect' => null,
	);
}
fh_reset();

class WP_Error {
	private $m;
	public function __construct( $code = '', $message = '' ) {
		$this->m = $message;
	}
	public function get_error_message() {
		return $this->m;
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}

function __( $t, $d = null ) {
	return $t;
}
function _n( $s, $p, $n, $d = null ) {
	return 1 === (int) $n ? $s : $p;
}
function wp_slash( $v ) {
	return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v );
}
function wp_unslash( $v ) {
	return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v );
}
function wp_kses_post( $s ) {
	return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $s );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_title( $s ) {
	return trim( preg_replace( '/[^a-z0-9_]+/', '-', strtolower( (string) $s ) ), '-' );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function get_current_user_id() {
	return $GLOBALS['fh']['user'];
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( $u, $c );
}
function home_url( $p = '' ) {
	return 'https://shop.test' . $p;
}
function admin_url( $p = '' ) {
	return 'https://shop.test/wp-admin/' . $p;
}

/* Options */
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['fh']['options'] ) ? $GLOBALS['fh']['options'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['fh']['options'][ $k ] = $v;
	return true;
}
function add_option( $k, $v, $x = '', $a = null ) {
	$GLOBALS['fh']['options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['fh']['options'][ $k ] );
	return true;
}

/* Posts */
function fh_post( array $data ) {
	$id   = isset( $data['ID'] ) ? (int) $data['ID'] : ++$GLOBALS['fh']['next'];
	$post = (object) array_merge(
		array(
			'ID'           => $id,
			'post_title'   => '',
			'post_content' => '',
			'post_excerpt' => '',
			'post_status'  => 'publish',
			'post_type'    => 'product',
			'post_parent'  => 0,
			'post_name'    => '',
		),
		$data,
		array( 'ID' => $id )
	);
	$GLOBALS['fh']['posts'][ $id ] = $post;
	return $id;
}
function get_post( $id ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	return $GLOBALS['fh']['posts'][ (int) $id ] ?? null;
}
function get_post_type( $id ) {
	$p = get_post( $id );
	return $p ? $p->post_type : false;
}
function get_post_status( $id ) {
	$p = get_post( $id );
	return $p ? $p->post_status : false;
}
function get_the_title( $id ) {
	$p = get_post( $id );
	return $p ? $p->post_title : '';
}
function get_permalink( $id ) {
	$p = get_post( $id );
	return $p ? 'https://shop.test/shop/' . ( $p->post_name ?: 'p' . $p->ID ) . '/' : '';
}
function wp_update_post( $data, $wp_error = false ) {
	$id = (int) $data['ID'];
	if ( ! isset( $GLOBALS['fh']['posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'x', 'no post' ) : 0;
	}
	foreach ( $data as $k => $v ) {
		if ( 'ID' !== $k ) {
			$GLOBALS['fh']['posts'][ $id ]->$k = wp_unslash( $v );
		}
	}
	return $id;
}
function clean_post_cache( $id ) {}
function wc_delete_product_transients( $id = 0 ) {}
function get_post_thumbnail_id( $id ) {
	return (int) get_post_meta( $id, '_thumbnail_id', true );
}
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['fh']['posts'] as $p ) {
		$types = (array) ( $args['post_type'] ?? 'post' );
		if ( ! in_array( $p->post_type, $types, true ) ) {
			continue;
		}
		if ( isset( $args['post_parent'] ) && (int) $p->post_parent !== (int) $args['post_parent'] ) {
			continue;
		}
		$st = $args['post_status'] ?? 'publish';
		if ( 'any' !== $st && ! in_array( $p->post_status, (array) $st, true ) ) {
			continue;
		}
		if ( ! empty( $args['tax_query'] ) ) {
			$q    = $args['tax_query'][0];
			$have = $GLOBALS['fh']['rel'][ $q['taxonomy'] ][ $p->ID ] ?? array();
			if ( ! array_intersect( $have, $q['terms'] ) ) {
				continue;
			}
		}
		$out[] = ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) ? $p->ID : $p;
	}
	return $out;
}

/* Meta */
function metadata_exists( $type, $id, $key ) {
	return isset( $GLOBALS['fh']['meta'][ (int) $id ] ) && array_key_exists( $key, $GLOBALS['fh']['meta'][ (int) $id ] );
}
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['fh']['meta'][ (int) $id ][ $key ] ?? '';
	return is_int( $v ) || is_float( $v ) ? (string) $v : $v;
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['fh']['meta'][ (int) $id ][ $key ] = wp_unslash( $value );
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['fh']['meta'][ (int) $id ][ $key ] );
	return true;
}

/* Terms */
class WP_Term {
	public $term_id, $name, $slug, $taxonomy, $description = '', $parent = 0, $count = 0;
}
function taxonomy_exists( $t ) {
	return in_array( $t, array( 'product_cat', 'product_tag', 'pa_med_treats', 'pa_med_use_in' ), true );
}
function fh_term( $tax, $name, $slug = '', $parent = 0, $desc = '' ) {
	$t              = new WP_Term();
	$t->term_id     = ++$GLOBALS['fh']['next'];
	$t->name        = $name;
	$t->slug        = '' !== $slug ? $slug : sanitize_title( $name );
	$t->taxonomy    = $tax;
	$t->parent      = (int) $parent;
	$t->description = $desc;
	$GLOBALS['fh']['terms'][ $t->term_id ] = $t;
	return $t->term_id;
}
function fh_count( $t ) {
	$n = 0;
	foreach ( $GLOBALS['fh']['rel'][ $t->taxonomy ] ?? array() as $ids ) {
		$n += in_array( $t->term_id, $ids, true ) ? 1 : 0;
	}
	$t->count = $n;
	return $t;
}
function get_term( $id, $tax = '' ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	$t = $GLOBALS['fh']['terms'][ (int) $id ] ?? null;
	return $t && ( '' === $tax || $t->taxonomy === $tax ) ? fh_count( $t ) : null;
}
function get_term_by( $field, $value, $tax ) {
	foreach ( $GLOBALS['fh']['terms'] as $t ) {
		if ( $t->taxonomy === $tax && ( ( 'slug' === $field && $t->slug === $value ) || ( 'name' === $field && $t->name === $value ) ) ) {
			return fh_count( $t );
		}
	}
	return false;
}
function get_terms( $args ) {
	$out = array();
	foreach ( $GLOBALS['fh']['terms'] as $t ) {
		if ( $t->taxonomy === $args['taxonomy'] ) {
			$out[] = fh_count( $t );
		}
	}
	return $out;
}
function get_term_children( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['fh']['terms'] as $t ) {
		if ( $t->taxonomy === $tax && (int) $t->parent === (int) $id ) {
			$out[] = $t->term_id;
			$out   = array_merge( $out, get_term_children( $t->term_id, $tax ) );
		}
	}
	return $out;
}
function get_ancestors( $id, $tax, $type = '' ) {
	$out = array();
	$t   = get_term( $id, $tax );
	while ( $t && $t->parent ) {
		$out[] = $t->parent;
		$t     = get_term( $t->parent, $tax );
	}
	return $out;
}
function get_term_link( $term, $tax = '' ) {
	$t = is_object( $term ) ? $term : get_term( $term, $tax );
	if ( ! $t ) {
		return new WP_Error( 'x', 'no term' );
	}
	$base = array( 'product_cat' => 'product-category', 'product_tag' => 'product-tag', 'pa_med_treats' => 'med_treats', 'pa_med_use_in' => 'med_use_in' );
	$path = array( $t->slug );
	foreach ( get_ancestors( $t->term_id, $t->taxonomy ) as $a ) {
		array_unshift( $path, get_term( $a )->slug );
	}
	return 'https://shop.test/' . $base[ $t->taxonomy ] . '/' . implode( '/', $path ) . '/';
}
function wp_insert_term( $name, $tax, $args = array() ) {
	if ( get_term_by( 'slug', $args['slug'] ?? sanitize_title( $name ), $tax ) ) {
		return new WP_Error( 'term_exists', 'A term with that slug exists.' );
	}
	$id = fh_term( $tax, $name, $args['slug'] ?? '', $args['parent'] ?? 0, $args['description'] ?? '' );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}
function wp_update_term( $id, $tax, $args ) {
	$t = $GLOBALS['fh']['terms'][ (int) $id ] ?? null;
	if ( ! $t ) {
		return new WP_Error( 'x', 'no term' );
	}
	foreach ( array( 'name', 'slug', 'description' ) as $k ) {
		if ( isset( $args[ $k ] ) ) {
			$t->$k = $args[ $k ];
		}
	}
	return array( 'term_id' => $t->term_id );
}
function wp_delete_term( $id, $tax ) {
	if ( ! isset( $GLOBALS['fh']['terms'][ (int) $id ] ) ) {
		return false;
	}
	$dead = $GLOBALS['fh']['terms'][ (int) $id ];
	foreach ( $GLOBALS['fh']['terms'] as $t ) {
		if ( (int) $t->parent === (int) $id ) {
			$t->parent = $dead->parent;
		}
	}
	unset( $GLOBALS['fh']['terms'][ (int) $id ] );
	foreach ( $GLOBALS['fh']['rel'][ $tax ] ?? array() as $obj => $ids ) {
		$GLOBALS['fh']['rel'][ $tax ][ $obj ] = array_values( array_diff( $ids, array( (int) $id ) ) );
	}
	return true;
}
function wp_get_object_terms( $id, $tax, $args = array() ) {
	$out = array();
	foreach ( $GLOBALS['fh']['rel'][ $tax ][ (int) $id ] ?? array() as $tid ) {
		$t = get_term( $tid, $tax );
		if ( ! $t ) {
			continue;
		}
		$f     = $args['fields'] ?? 'all';
		$out[] = 'names' === $f ? $t->name : ( 'ids' === $f ? $t->term_id : ( 'slugs' === $f ? $t->slug : $t ) );
	}
	return $out;
}
function wp_get_post_terms( $id, $tax, $args = array() ) {
	return wp_get_object_terms( $id, $tax, $args );
}
function wp_set_object_terms( $id, $terms, $tax, $append = false ) {
	$ids = array();
	foreach ( (array) $terms as $t ) {
		if ( is_int( $t ) ) {
			$ids[] = $t;
			continue;
		}
		$found = get_term_by( 'slug', sanitize_title( $t ), $tax ) ?: get_term_by( 'name', $t, $tax );
		$ids[] = $found ? $found->term_id : fh_term( $tax, $t );
	}
	$now = $append ? ( $GLOBALS['fh']['rel'][ $tax ][ (int) $id ] ?? array() ) : array();
	$GLOBALS['fh']['rel'][ $tax ][ (int) $id ] = array_values( array_unique( array_merge( $now, $ids ) ) );
	return $ids;
}
function wp_remove_object_terms( $id, $terms, $tax ) {
	$GLOBALS['fh']['rel'][ $tax ][ (int) $id ] = array_values( array_diff( $GLOBALS['fh']['rel'][ $tax ][ (int) $id ] ?? array(), array_map( 'intval', (array) $terms ) ) );
	return true;
}
function get_objects_in_term( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['fh']['rel'][ $tax ] ?? array() as $obj => $ids ) {
		if ( in_array( (int) $id, $ids, true ) ) {
			$out[] = $obj;
		}
	}
	return $out;
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $o ) use ( $field ) {
			return is_object( $o ) ? $o->$field : $o[ $field ];
		},
		$list
	);
}

/* WooCommerce */
class WC_Product_Attribute {
	private $d = array( 'id' => 0, 'name' => '', 'options' => array(), 'position' => 0, 'visible' => true, 'variation' => false );
	public function set_id( $v ) { $this->d['id'] = $v; }
	public function set_name( $v ) { $this->d['name'] = $v; }
	public function set_options( $v ) { $this->d['options'] = $v; }
	public function set_position( $v ) { $this->d['position'] = $v; }
	public function set_visible( $v ) { $this->d['visible'] = $v; }
	public function set_variation( $v ) { $this->d['variation'] = $v; }
	public function get_id() { return $this->d['id']; }
	public function get_name() { return $this->d['name']; }
	public function get_options() { return $this->d['options']; }
	public function get_position() { return $this->d['position']; }
	public function get_visible() { return $this->d['visible']; }
	public function get_variation() { return $this->d['variation']; }
}
class FH_Fake_Product {
	public $id;
	private $pending = null;
	public function __construct( $id ) { $this->id = $id; }
	public function get_attributes( $c = 'view' ) { return $GLOBALS['fh']['attrs'][ $this->id ] ?? array(); }
	public function set_attributes( $list ) {
		$this->pending = array();
		foreach ( $list as $a ) {
			$this->pending[ sanitize_title( $a->get_name() ) ] = $a;
		}
	}
	public function save() {
		if ( null === $this->pending ) {
			return;
		}
		foreach ( array_keys( $GLOBALS['fh']['attrs'][ $this->id ] ?? array() ) as $k ) {
			if ( ! isset( $this->pending[ $k ] ) && taxonomy_exists( $k ) ) {
				wp_set_object_terms( $this->id, array(), $k ); // As WooCommerce does for an attribute taken off.
			}
		}
		$GLOBALS['fh']['attrs'][ $this->id ] = $this->pending;
		foreach ( $this->pending as $k => $a ) {
			if ( $a->get_id() ) {
				wp_set_object_terms( $this->id, array_map( 'intval', $a->get_options() ), $a->get_name() );
			}
		}
	}
}
function wc_get_product( $id ) {
	$p = get_post( $id );
	return $p && in_array( $p->post_type, array( 'product', 'product_variation' ), true ) ? new FH_Fake_Product( (int) $id ) : false;
}
function wc_attribute_label( $name, $p = null ) {
	return 'pa_med_treats' === $name ? 'Treats' : ( 'pa_med_use_in' === $name ? 'Use In' : ucwords( str_replace( array( 'pa_', '_', '-' ), array( '', ' ', ' ' ), $name ) ) );
}
function fh_attr( $pid, $name, array $options, $variation = false, $tax_id = 0 ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_id( $tax_id );
	$a->set_options( $options );
	$a->set_variation( $variation );
	$GLOBALS['fh']['attrs'][ $pid ][ sanitize_title( $name ) ] = $a;
	if ( $tax_id ) {
		wp_set_object_terms( $pid, array_map( 'intval', $options ), $name );
	}
}

/* Comments */
function fh_comment( $pid, $author, $content, $status = 'approved' ) {
	$id = ++$GLOBALS['fh']['next'];
	$GLOBALS['fh']['comments'][ $id ] = (object) array(
		'comment_ID'      => $id,
		'comment_post_ID' => $pid,
		'comment_author'  => $author,
		'comment_content' => $content,
		'status'          => $status,
	);
	return $id;
}
function get_comments( $args ) {
	return array_values(
		array_filter(
			$GLOBALS['fh']['comments'],
			function ( $c ) use ( $args ) {
				return (int) $c->comment_post_ID === (int) $args['post_id'];
			}
		)
	);
}
function get_comment( $id ) {
	return $GLOBALS['fh']['comments'][ (int) $id ] ?? null;
}
function wp_get_comment_status( $c ) {
	$c = is_object( $c ) ? $c : get_comment( $c );
	return $c ? $c->status : false;
}
function wp_set_comment_status( $id, $s ) {
	$map = array( 'approve' => 'approved', 'hold' => 'unapproved' );
	$GLOBALS['fh']['comments'][ (int) $id ]->status = $map[ $s ] ?? $s;
	return true;
}

/* Hooks and redirects */
function add_action( $h, $cb, $p = 10, $n = 1 ) {
	$GLOBALS['fh']['hooks'][ $h ][] = $cb;
}
function has_action( $h ) {
	return ! empty( $GLOBALS['fh']['hooks'][ $h ] );
}
function is_admin() {
	return false;
}
function is_404() {
	return ! empty( $GLOBALS['fh']['is_404'] );
}
function get_page_by_path( $slug, $o = OBJECT, $type = 'page' ) {
	foreach ( $GLOBALS['fh']['posts'] as $p ) {
		if ( $p->post_type === $type && $p->post_name === $slug ) {
			return $p;
		}
	}
	return null;
}
class FH_Redirected extends Exception {}
function wp_safe_redirect( $to, $status = 302, $by = '' ) {
	$GLOBALS['fh']['redirect'] = array( $to, $status );
	throw new FH_Redirected( $to );
}

/* The checks every test file uses. */
$GLOBALS['fh_checks']   = 0;
$GLOBALS['fh_failures'] = 0;
function check( $label, $ok, $detail = '' ) {
	$GLOBALS['fh_checks']++;
	if ( $ok ) {
		echo "  ok   $label\n";
		return;
	}
	$GLOBALS['fh_failures']++;
	echo "  FAIL $label" . ( '' !== $detail ? "\n       $detail" : '' ) . "\n";
}
function fh_done() {
	echo "\n" . ( $GLOBALS['fh_failures'] ? $GLOBALS['fh_failures'] . ' of ' . $GLOBALS['fh_checks'] . " checks FAILED\n" : 'All ' . $GLOBALS['fh_checks'] . " checks passed\n" );
	exit( $GLOBALS['fh_failures'] ? 1 : 0 );
}

$fh_dir = FISHOTEL_MISC_PATH . 'includes/sections/med-listings/';
foreach ( array( 'words', 'finder', 'composer', 'proposals', 'fields', 'ledger', 'inventory', 'terms', 'redirects', 'verify' ) as $fh_f ) {
	require_once $fh_dir . 'class-' . $fh_f . '.php';
}

function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
