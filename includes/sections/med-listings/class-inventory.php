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

		// A product in a left-out category (the fish, invoices) is never a
		// medication here, even when it was put in a medication category by
		// mistake: the Categories step must never turn a fish into a medication.
		$leave = array_map( 'strtolower', Words::parse_commas( (string) $words['leave_out'] ) );
		if ( $leave ) {
			foreach ( array_keys( $out ) as $pid ) {
				$slugs = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'slugs' ) );
				if ( is_array( $slugs ) && array_intersect( $leave, array_map( 'strtolower', $slugs ) ) ) {
					unset( $out[ $pid ] );
				}
			}
		}

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
			if ( $leave ) {
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
	 * Whether a product is a food — by its category, its form or its name
	 * only. The food fields (Nutrition, Food source) do not decide: a
	 * copper liquid or a tablet with Nutrition text is not a food.
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
		return in_array( Composer::form_of( array(), $name ), array( 'flake', 'pellet', 'gel', 'granule' ), true ) || (bool) preg_match( '/\bfoods?\b/i', $name );
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
	 * An attribute's values in the product's own order: for an attribute
	 * the variations are built on, the order of the variations (the order
	 * the shop's dropdown and the owner's example use — "omnivore or
	 * herbivore"); otherwise as stored.
	 *
	 * @param int   $pid Product id.
	 * @param array $raw Fields::export_attribute().
	 * @return string[]
	 */
	public static function values_in_product_order( $pid, array $raw ) {
		$values = Fields::attribute_values( $raw );
		if ( empty( $raw['variation'] ) || count( $values ) < 2 ) {
			return $values;
		}
		$name  = (string) $raw['name'];
		$key   = 'attribute_' . ( function_exists( 'sanitize_title' ) ? sanitize_title( $name ) : strtolower( $name ) );
		$by    = array(); // slug or text (lower case) => name
		foreach ( (array) ( $raw['terms'] ?? array() ) as $pair ) {
			$by[ strtolower( (string) $pair[0] ) ] = (string) $pair[1];
		}
		foreach ( $values as $v ) {
			$by[ strtolower( $v ) ] = $v;
			if ( function_exists( 'sanitize_title' ) ) {
				$by[ sanitize_title( $v ) ] = $by[ sanitize_title( $v ) ] ?? $v;
			}
		}
		$ordered = array();
		foreach ( self::variation_ids( (int) $pid ) as $vid ) {
			$got = strtolower( (string) get_post_meta( $vid, $key, true ) );
			if ( '' !== $got && isset( $by[ $got ] ) && ! in_array( $by[ $got ], $ordered, true ) ) {
				$ordered[] = $by[ $got ];
			}
		}
		foreach ( $values as $v ) {
			if ( ! in_array( $v, $ordered, true ) ) {
				$ordered[] = $v;
			}
		}
		return $ordered;
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
			if ( '_fishotel_active_ingredient' === $key || metadata_exists( 'post', $pid, $key ) ) {
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
				'values'    => self::values_in_product_order( $pid, $raw ),
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
		$pictures = array();
		foreach ( self::image_ids( $pid ) as $aid => $where ) {
			$field( $aid, 'meta:_wp_attachment_image_alt', __( 'Picture alt text', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$field( $aid, 'post_title', __( 'Picture title', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$field( $aid, 'post_excerpt', __( 'Picture caption', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$field( $aid, 'post_content', __( 'Picture description', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$field( $aid, 'post_name', __( 'Picture address', 'fishotel-misc-plugin' ), $where . ' #' . $aid );
			$file             = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $aid ) : '';
			$pictures[ $aid ] = array(
				'where' => $where,
				'file'  => '' !== $file ? basename( $file ) : '',
				'edit'  => admin_url( 'post.php?post=' . (int) $aid . '&action=edit' ),
			);
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
			'pictures'    => $pictures,
			'slug'        => (string) $post->post_name,
			'ledger'      => Ledger::written( $pid ),
		);
	}

	/**
	 * Published pages and posts that link to or show one of these
	 * products or the medication categories, with whether it is one of
	 * the tool pages (Dosing Calculator, QT Scheduler, a quarantine guide).
	 *
	 * A product is found by its address as it is now, by its slug in any
	 * address (so a link still found after step 5 moved it, through the
	 * 301), by an add-to-cart link, and by its id in a shortcode
	 * ([product id="12"], [products ids="12,34"], [add_to_cart id=12] …) or
	 * a product block ({"productId":12}, {"products":[12,34]}). A category
	 * is found by its address now and by every old address on the redirect
	 * list.
	 *
	 * @param int[] $pids Product ids.
	 * @return array<int, array{id: int, title: string, url: string, edit: string, tool: bool, found: string[]}>
	 */
	public static function linking_pages( array $pids ) {
		$products = array();
		foreach ( $pids as $pid ) {
			$post = get_post( (int) $pid );
			if ( ! $post ) {
				continue;
			}
			$products[ (int) $pid ] = array(
				'title' => (string) get_the_title( (int) $pid ),
				'slug'  => (string) $post->post_name,
				'path'  => (string) wp_parse_url( (string) get_permalink( (int) $pid ), PHP_URL_PATH ),
			);
		}
		$paths  = array();
		$parent = self::parent_term();
		foreach ( self::med_term_ids() as $tid ) {
			$t    = get_term( (int) $tid, 'product_cat' );
			$link = $t && ! is_wp_error( $t ) ? get_term_link( $t ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$paths[ Redirects::normalise_path( $link ) ] = $t->name;
			}
		}
		if ( $parent ) {
			$paths['/product-category/' . $parent->slug . '/'] = $parent->name;
		}
		$paths['/product-category/medications/'] = 'Medications';
		foreach ( Redirects::all() as $e ) {
			if ( ! empty( $e['from'] ) ) {
				/* translators: %s: old address */
				$paths[ Redirects::normalise_path( (string) $e['from'] ) ] = sprintf( __( 'old address %s', 'fishotel-misc-plugin' ), (string) $e['from'] );
			}
		}

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
			$found = self::links_in( (string) $p->post_content, $products, $paths );
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
				'found' => $found,
			);
		}
		return $out;
	}

	/**
	 * What one page's content links to or shows (pure).
	 *
	 * @param string $content  post_content.
	 * @param array  $products id => [title, slug, path].
	 * @param array  $paths    category address => what it is.
	 * @return string[] what was found, each once.
	 */
	public static function links_in( $content, array $products, array $paths ) {
		$content = (string) $content;
		$plain   = str_replace( '\\/', '/', html_entity_decode( $content, ENT_QUOTES, 'UTF-8' ) );
		$found   = array();

		// Ids in shortcodes and product blocks.
		$ids = array();
		if ( preg_match_all( '/\[[a-z_]+\b([^\]]*)\]/i', $plain, $sc ) ) {
			foreach ( $sc[1] as $attrs ) {
				if ( preg_match_all( '/\b(?:id|ids|product_id|product_ids|product)\s*=\s*["\']?([\d,\s]+)/i', $attrs, $am ) ) {
					foreach ( $am[1] as $list ) {
						foreach ( preg_split( '/[\s,]+/', $list ) as $n ) {
							if ( '' !== $n ) {
								$ids[ (int) $n ] = true;
							}
						}
					}
				}
			}
		}
		if ( preg_match_all( '/"(?:productId|product_id|id)"\s*:\s*(\d+)/', $plain, $bm ) ) {
			foreach ( $bm[1] as $n ) {
				$ids[ (int) $n ] = true;
			}
		}
		if ( preg_match_all( '/"(?:products|productIds|ids)"\s*:\s*\[([\d,\s]*)\]/', $plain, $bm ) ) {
			foreach ( $bm[1] as $list ) {
				foreach ( preg_split( '/[\s,]+/', $list ) as $n ) {
					if ( '' !== $n ) {
						$ids[ (int) $n ] = true;
					}
				}
			}
		}
		if ( preg_match_all( '/(?:add-to-cart|add_to_cart|[?&]p)=(\d+)/i', $plain, $cm ) ) {
			foreach ( $cm[1] as $n ) {
				$ids[ (int) $n ] = true;
			}
		}

		// Addresses in links.
		$hrefs = array();
		if ( preg_match_all( '/\b(?:href|src|action|url)\s*=\s*(["\'])(.*?)\1/is', $plain, $hm ) ) {
			$hrefs = $hm[2];
		}
		if ( preg_match_all( '#https?://[^\s"\'<>\]]+#i', $plain, $um ) ) {
			$hrefs = array_merge( $hrefs, $um[0] );
		}
		$link_paths = array();
		foreach ( $hrefs as $h ) {
			$path = (string) wp_parse_url( (string) $h, PHP_URL_PATH );
			if ( '' !== $path ) {
				$link_paths[] = Redirects::normalise_path( $path );
			}
		}

		foreach ( $products as $pid => $p ) {
			$hit = isset( $ids[ (int) $pid ] );
			$slug = strtolower( (string) $p['slug'] );
			foreach ( $link_paths as $lp ) {
				if ( $hit ) {
					break;
				}
				if ( '' !== $p['path'] && '/' !== $p['path'] && Redirects::normalise_path( $p['path'] ) === $lp ) {
					$hit = true;
				} elseif ( '' !== $slug && false !== strpos( $lp, '/' . $slug . '/' ) ) {
					$hit = true; // The product slug in any address: an old address still goes there.
				}
			}
			if ( $hit ) {
				$found[] = (string) $p['title'];
			}
		}
		foreach ( $paths as $path => $what ) {
			$path = Redirects::normalise_path( (string) $path );
			foreach ( $link_paths as $lp ) {
				if ( $lp === $path || 0 === strpos( $lp, $path ) ) {
					$found[] = (string) $what;
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
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
