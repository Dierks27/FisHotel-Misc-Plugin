<?php
/**
 * Med listings — which products the cleanup looks at, and a snapshot of
 * each one.
 *
 * A product is in when
 *   - it is in the Medications category or any category under it (the
 *     theme's rule for "a medication": FISHOTEL_MED_PARENT_SLUG), or in
 *     one of the other slugs the theme reads as medication or medicated
 *     food (template-functions.php fishotel_is_medication_product() /
 *     fishotel_is_food_product()); or
 *   - its name, short description or description names a drug from the
 *     owner's list (metronidazole, praziquantel, copper …),
 * unless it is in a category the owner leaves out (the fish, invoices).
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Inventory
 */
class Inventory {

	/**
	 * The theme's medications parent slug (FISHOTEL_MED_PARENT_SLUG).
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'medications';

	/**
	 * Other category slugs the theme reads as a medication or a medicated food.
	 *
	 * @var string[]
	 */
	const MED_SLUGS = array( 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'antifungal', 'dewormer', 'medicated-food', 'medicated-foods', 'quarantine-essentials' );

	/**
	 * Category slugs that make a product a food.
	 *
	 * @var string[]
	 */
	const FOOD_SLUGS = array( 'medicated-food', 'medicated-foods', 'fish-food', 'freeze-dried-foods', 'flakes', 'pellets' );

	/**
	 * Brand taxonomies looked at when present.
	 *
	 * @var string[]
	 */
	const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand' );

	/**
	 * Post statuses looked at (a draft can be published later).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * The medications parent term, or null.
	 *
	 * @return \WP_Term|null
	 */
	public static function parent_term() {
		$slug = defined( 'FISHOTEL_MED_PARENT_SLUG' ) ? (string) FISHOTEL_MED_PARENT_SLUG : self::PARENT_SLUG;
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		return $term && ! is_wp_error( $term ) ? $term : null;
	}

	/**
	 * Term ids of the medication categories: the parent, everything under
	 * it, and the other medication slugs.
	 *
	 * @return int[]
	 */
	public static function med_term_ids() {
		$ids    = array();
		$parent = self::parent_term();
		if ( $parent ) {
			$ids[] = (int) $parent->term_id;
			$kids  = get_term_children( (int) $parent->term_id, 'product_cat' );
			if ( is_array( $kids ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $kids ) );
			}
		}
		foreach ( self::MED_SLUGS as $slug ) {
			$t = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$ids[] = (int) $t->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The products to look at, with why each one is in.
	 *
	 * @param array $words Words::get().
	 * @return array<int, string[]> product id => reasons
	 */
	public static function find( array $words ) {
		$out     = array();
		$med_ids = self::med_term_ids();
		if ( $med_ids ) {
			$in_cats = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => self::STATUSES,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-time admin page.
						array(
							'taxonomy'         => 'product_cat',
							'field'            => 'term_id',
							'terms'            => $med_ids,
							'include_children' => true,
						),
					),
				)
			);
			foreach ( (array) $in_cats as $pid ) {
				$out[ (int) $pid ][] = __( 'in a medication category', 'fishotel-misc-plugin' );
			}
		}

		$leave = array_map( 'strtolower', Words::parse_commas( (string) $words['leave_out'] ) );
		$all   = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => self::STATUSES,
				'posts_per_page'   => -1,
				'suppress_filters' => true,
				'orderby'          => 'title',
				'order'            => 'ASC',
			)
		);
		foreach ( (array) $all as $post ) {
			$pid  = (int) $post->ID;
			$hits = Finder::find( $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content, $words, array( 'drugs' ) );
			if ( ! $hits ) {
				continue;
			}
			if ( ! isset( $out[ $pid ] ) && $leave ) {
				$slugs = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'slugs' ) );
				if ( is_array( $slugs ) && array_intersect( $leave, array_map( 'strtolower', $slugs ) ) ) {
					continue;
				}
			}
			$out[ $pid ][] = sprintf( /* translators: %s: drug words */ __( 'names %s', 'fishotel-misc-plugin' ), implode( ', ', Finder::words( $hits ) ) );
		}

		// Name order, as the products list shows them.
		$names = array();
		foreach ( array_keys( $out ) as $pid ) {
			$names[ $pid ] = strtolower( (string) get_the_title( $pid ) );
		}
		asort( $names );
		$sorted = array();
		foreach ( array_keys( $names ) as $pid ) {
			$sorted[ $pid ] = array_values( array_unique( $out[ $pid ] ) );
		}
		return $sorted;
	}

	/**
	 * The post ids a product owns: itself, its variations, its pictures
	 * (main, gallery, each variation's).
	 *
	 * @param int $pid Product id.
	 * @return int[]
	 */
	public static function owned_ids( $pid ) {
		$pid = (int) $pid;
		$ids = array( $pid );
		foreach ( self::variation_ids( $pid ) as $vid ) {
			$ids[] = $vid;
		}
		return array_values( array_unique( array_merge( $ids, array_keys( self::image_ids( $pid ) ) ) ) );
	}

	/**
	 * A product's variation ids.
	 *
	 * @param int $pid Product id.
	 * @return int[]
	 */
	public static function variation_ids( $pid ) {
		$kids = get_posts(
			array(
				'post_type'        => 'product_variation',
				'post_parent'      => (int) $pid,
				'post_status'      => array( 'publish', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'menu_order',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		return array_map( 'intval', (array) $kids );
	}

	/**
	 * A product's pictures: id => where it is shown.
	 *
	 * @param int $pid Product id.
	 * @return array<int, string>
	 */
	public static function image_ids( $pid ) {
		$pid = (int) $pid;
		$out = array();
		$main = (int) get_post_thumbnail_id( $pid );
		if ( $main ) {
			$out[ $main ] = __( 'main picture', 'fishotel-misc-plugin' );
		}
		foreach ( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) ) as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = __( 'gallery picture', 'fishotel-misc-plugin' );
			}
		}
		foreach ( self::variation_ids( $pid ) as $vid ) {
			$id = (int) get_post_thumbnail_id( $vid );
			if ( $id && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = __( 'variation picture', 'fishotel-misc-plugin' );
			}
		}
		return $out;
	}

	/**
	 * Whether a product is a food (by category, form or food fields).
	 *
	 * @param int    $pid   Product id.
	 * @param string $name  Name.
	 * @param array  $slugs Category slugs.
	 * @return bool
	 */
	public static function is_food( $pid, $name, array $slugs ) {
		if ( array_intersect( self::FOOD_SLUGS, $slugs ) ) {
			return true;
		}
		if ( in_array( Composer::form_of( array(), $name ), array( 'flake', 'pellet', 'gel', 'granule' ), true ) || preg_match( '/\bfoods?\b/i', $name ) ) {
			return true;
		}
		foreach ( array( '_fishotel_food_source', '_fishotel_nutrition' ) as $key ) {
			if ( '' !== trim( (string) get_post_meta( (int) $pid, $key, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether our supplier ships it (the theme's own test when it is there).
	 *
	 * @param int $pid Product id.
	 * @return bool
	 */
	public static function is_ea( $pid ) {
		if ( function_exists( 'fishotel_is_ea_fulfilled_product' ) ) {
			return (bool) fishotel_is_ea_fulfilled_product( (int) $pid );
		}
		return 'ea' === (string) get_post_meta( (int) $pid, '_fishotel_fulfillment', true );
	}

	/**
	 * Everything Proposals needs to know about one product.
	 *
	 * @param int   $pid   Product id.
	 * @param array $words Words::get().
	 * @return array|null
	 */
	public static function snapshot( $pid, array $words ) {
		$pid     = (int) $pid;
		$post    = get_post( $pid );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $post || ! $product ) {
			return null;
		}
		$name  = (string) $post->post_title;
		$cats  = wp_get_post_terms( $pid, 'product_cat' );
		$cats  = is_array( $cats ) ? $cats : array();
		$slugs = wp_list_pluck( $cats, 'slug' );

		$fields = array();
		$field  = function ( $object, $key, $label, $where = '' ) use ( &$fields ) {
			$fields[] = array(
				'object' => (int) $object,
				'field'  => $key,
				'label'  => $label,
				'where'  => $where,
				'read'   => Fields::read( (int) $object, $key ),
			);
			return count( $fields ) - 1;
		};

		$field( $pid, 'post_content', __( 'Description', 'fishotel-misc-plugin' ) );
		$field( $pid, 'post_excerpt', __( 'Short description', 'fishotel-misc-plugin' ) );
		foreach ( Fields::META as $key => $def ) {
			if ( metadata_exists( 'post', $pid, $key ) ) {
				$field( $pid, 'meta:' . $key, $def[0] );
			}
		}
		$field( $pid, 'tags', __( 'Tags', 'fishotel-misc-plugin' ) );

		$attributes = array();
		foreach ( $product->get_attributes( 'edit' ) as $key => $attr ) {
			if ( ! is_object( $attr ) ) {
				continue;
			}
			$raw                = Fields::export_attribute( $attr );
			$attributes[ $key ] = array(
				'label'     => function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $raw['name'], $product ) : $raw['name'],
				'values'    => Fields::attribute_values( $raw ),
				'variation' => (bool) $raw['variation'],
			);
			$i                      = $field( $pid, 'attr:' . $key, sprintf( /* translators: %s: attribute label */ __( 'Attribute "%s"', 'fishotel-misc-plugin' ), $attributes[ $key ]['label'] ) );
			$fields[ $i ]['attr'] = $attributes[ $key ];
		}

		foreach ( self::variation_ids( $pid ) as $vid ) {
			$var  = function_exists( 'wc_get_product' ) ? wc_get_product( $vid ) : null;
			$what = $var && function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $var, true, false ) : '#' . $vid;
			$field( $vid, 'meta:_variation_description', __( 'Variation description', 'fishotel-misc-plugin' ), sprintf( /* translators: %s: the variation's options */ __( 'variation: %s', 'fishotel-misc-plugin' ), wp_strip_all_tags( (string) $what ) ) );
		}
		foreach ( self::image_ids( $pid ) as $aid => $where ) {
			$field( $aid, 'meta:_wp_attachment_image_alt', __( 'Picture alt text', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$field( $aid, 'post_excerpt', __( 'Picture caption', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
		}

		$own    = array_map( 'strtolower', Words::parse_commas( (string) $words['own_brands'] ) );
		$brands = array();
		foreach ( self::BRAND_TAXONOMIES as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$names = wp_get_post_terms( $pid, $tax, array( 'fields' => 'names' ) );
			foreach ( is_array( $names ) ? $names : array() as $b ) {
				if ( ! in_array( strtolower( trim( $b ) ), $own, true ) ) {
					$brands[] = $b;
				}
			}
		}

		$comments = array();
		$list     = get_comments(
			array(
				'post_id' => $pid,
				'status'  => 'all',
				'type__not_in' => array( 'order_note', 'webhook_delivery', 'action_log' ),
			)
		);
		foreach ( (array) $list as $c ) {
			$comments[] = array(
				'id'      => (int) $c->comment_ID,
				'author'  => (string) $c->comment_author,
				'content' => (string) $c->comment_content,
				'status'  => (string) wp_get_comment_status( $c ),
				'link'    => admin_url( 'comment.php?action=editcomment&c=' . (int) $c->comment_ID ),
			);
		}

		return array(
			'id'          => $pid,
			'name'        => $name,
			'status'      => (string) $post->post_status,
			'url'         => (string) get_permalink( $pid ),
			'edit'        => admin_url( 'post.php?post=' . $pid . '&action=edit' ),
			'cats'        => wp_list_pluck( $cats, 'name' ),
			'cat_slugs'   => $slugs,
			'ingredients' => (string) get_post_meta( $pid, '_fishotel_active_ingredient', true ),
			'is_food'     => self::is_food( $pid, $name, $slugs ),
			'is_ea'       => self::is_ea( $pid ),
			'asin'        => trim( (string) get_post_meta( $pid, '_fishotel_amazon_asin', true ) ),
			'brands'      => array_values( array_unique( $brands ) ),
			'fields'      => $fields,
			'attributes'  => $attributes,
			'comments'    => $comments,
			'ledger'      => Ledger::written( $pid ),
		);
	}

	/**
	 * Published pages and posts that link to or show one of these
	 * products or the medication categories (a product address, an
	 * add-to-cart, a [product] shortcode or a product block naming its
	 * id), with whether it is one of the tool pages (Dosing Calculator,
	 * QT Scheduler, a quarantine guide).
	 *
	 * @param int[] $pids Product ids.
	 * @return array<int, array{id: int, title: string, url: string, edit: string, tool: bool, found: string[]}>
	 */
	public static function linking_pages( array $pids ) {
		$needles = array();
		foreach ( $pids as $pid ) {
			$url = (string) get_permalink( (int) $pid );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$needles[ $path ] = get_the_title( (int) $pid );
			}
			$needles[ 'add-to-cart=' . (int) $pid ] = get_the_title( (int) $pid );
			$needles[ 'id="' . (int) $pid . '"' ]    = get_the_title( (int) $pid );
			$needles[ '"productId":' . (int) $pid ]  = get_the_title( (int) $pid );
		}
		$parent = self::parent_term();
		if ( $parent ) {
			$link = get_term_link( $parent );
			if ( is_string( $link ) ) {
				$needles[ rtrim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' ) ] = $parent->name;
			}
		}
		$needles['/product-category/medications'] = 'Medications';

		$out   = array();
		$posts = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		foreach ( (array) $posts as $p ) {
			$found = array();
			foreach ( $needles as $needle => $what ) {
				if ( false !== stripos( (string) $p->post_content, (string) $needle ) ) {
					$found[] = (string) $what;
				}
			}
			if ( ! $found ) {
				continue;
			}
			$template = (string) get_post_meta( $p->ID, '_wp_page_template', true );
			$out[]    = array(
				'id'    => (int) $p->ID,
				'title' => (string) $p->post_title,
				'url'   => (string) get_permalink( $p ),
				'edit'  => admin_url( 'post.php?post=' . (int) $p->ID . '&action=edit' ),
				'tool'  => self::is_tool_page( (string) $p->post_name, (string) $p->post_title, $template ),
				'found' => array_values( array_unique( $found ) ),
			);
		}
		return $out;
	}

	/**
	 * Whether a page is one of the tool or quarantine-guide pages (pure).
	 *
	 * @param string $slug     Page slug.
	 * @param string $title    Title.
	 * @param string $template Page template file.
	 * @return bool
	 */
	public static function is_tool_page( $slug, $title, $template ) {
		if ( in_array( $template, array( 'page-quarantine-help.php', 'page-qt-scheduler.php' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(?:qt|quarantine|dosing|calculator|medication)/i', $slug . ' ' . $title );
	}

	/**
	 * The tool pages' addresses (for the page check: they must not link
	 * to the products).
	 *
	 * @return string[]
	 */
	public static function tool_page_urls() {
		$out   = array();
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		foreach ( (array) $pages as $p ) {
			$template = (string) get_post_meta( $p->ID, '_wp_page_template', true );
			if ( self::is_tool_page( (string) $p->post_name, (string) $p->post_title, $template ) ) {
				$out[] = (string) get_permalink( $p );
			}
		}
		return $out;
	}
}
