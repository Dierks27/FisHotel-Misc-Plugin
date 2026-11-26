<?php
/**
 * Med listings — the Categories step: therapeutic categories become
 * neutral ones, disease and medicine-type tags go, and every old address
 * gets a 301.
 *
 * The neutral categories are Medicated Foods, Powders & Chemicals and
 * Water Chemistry, all UNDER the Medications category. They must stay
 * under it: the theme decides "this is a medication" (the ornamental-use
 * notice, the checkout tick, the terms record) by that parent
 * (helpers.php fishotel_med_is_med_product()). Every product the step
 * moves is also put in the Medications category itself, so the theme's
 * other test (template-functions.php fishotel_is_medication_product(),
 * which reads slugs) still finds it after an "antiparasitic" category
 * is gone.
 *
 * What each row can do:
 *   sort    each product goes to Medicated Foods, Water Chemistry or
 *           Powders & Chemicals by what it is; the old category is
 *           removed; its address goes to the category chosen.
 *   merge   all its products go to one category; the old one is removed.
 *   rename  new name / slug / description on the same category; a new
 *           slug sends the old address on.
 *   delete  (tags, attribute values) taken off every product and removed.
 *   keep    nothing.
 *
 * Every change is recorded (Ledger, "terms") so Undo puts the category
 * back with its name, slug, description and parent, puts its products
 * back in it, takes them out of what they were added to, removes a
 * neutral category it created if that is empty again, and takes its
 * redirects off. A category put back by Undo has a new id: WordPress
 * gives a re-created term a new number.
 *
 * Only WordPress term functions: wp_insert_term, wp_update_term,
 * wp_delete_term, wp_set_object_terms, wp_remove_object_terms.
 *
 * @package FisHotel\Misc\Sections\Med_Listings
 */

namespace FisHotel\Misc\Sections\Med_Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Terms
 */
class Terms {

	/**
	 * The neutral categories: type => [name, slug].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const TARGETS = array(
		// Slug "medicated-food": the one the theme reads as a food (the theme
		// also knows "medicated-foods" since 1.29.0).
		'food'   => array( 'Medicated Foods', 'medicated-food' ),
		'powder' => array( 'Powders & Chemicals', 'powders-chemicals' ),
		'water'  => array( 'Water Chemistry', 'water-chemistry' ),
	);

	/**
	 * Category slugs that are a medicine type whatever their name says.
	 *
	 * @var string[]
	 */
	const THERAPEUTIC = array( 'antiparasitic', 'antibacterial', 'antifungal', 'dewormer', 'dewormers', 'antibiotics', 'antibiotic', 'quarantine-essentials', 'anti-parasitic', 'antiprotozoal' );

	/**
	 * Attribute taxonomies whose values are claims (the theme's Treats / Use In).
	 *
	 * @var string[]
	 */
	const CLAIM_ATTRIBUTE_TAXONOMIES = array( 'pa_med_treats', 'pa_med_use_in' );

	/**
	 * Which neutral category a product belongs in (pure).
	 *
	 * @param string $name        Name.
	 * @param string $ingredients Active ingredients.
	 * @param bool   $is_food     A food.
	 * @param array  $words       Words::get().
	 * @return string food / water / powder
	 */
	public static function type_of( $name, $ingredients, $is_food, array $words ) {
		if ( $is_food ) {
			return 'food';
		}
		if ( Finder::has( $name . ' ' . $ingredients, $words, array( 'water' ) ) ) {
			return 'water';
		}
		return 'powder';
	}

	/**
	 * A neutral category's term, found by slug or, for Medicated Foods,
	 * the theme's own "medicated-food"; null when it does not exist yet.
	 *
	 * @param string $type food / powder / water.
	 * @return \WP_Term|null
	 */
	public static function target_term( $type ) {
		if ( ! isset( self::TARGETS[ $type ] ) ) {
			return null;
		}
		$slugs = array( self::TARGETS[ $type ][1] );
		if ( 'food' === $type ) {
			$slugs[] = 'medicated-foods';
		}
		foreach ( $slugs as $slug ) {
			$t = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Category slugs that say what a product looks like, not what it does
	 * (the theme's Form filter on the Medications page reads them):
	 * slug => the neutral category they could be merged into.
	 *
	 * @var array<string, string>
	 */
	const FORM_SLUGS = array(
		'flakes'  => 'food',
		'pellets' => 'food',
		'powders' => 'powder',
		'liquid'  => 'powder',
		'liquids' => 'powder',
		'tablets' => 'powder',
	);

	/**
	 * Brand taxonomies (kept brands: only a description with a claim is
	 * cleaned; another brand is flagged on the product).
	 *
	 * @var string[]
	 */
	const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand' );

	/**
	 * The proposal table.
	 *
	 * Only categories INSIDE the Medications tree, or with one of the
	 * medicine-type slugs (THERAPEUTIC), are ever proposed — never "Fish
	 * Treats" or "Hospital Tank" somewhere else in the shop — and never one
	 * in the left-out list. A row outside the tree, or a category that also
	 * holds products that are not medications, starts unticked (and the
	 * second cannot be sorted or merged at all: its other products would
	 * become medications).
	 *
	 * Tags: a disease or medicine-type tag only on medications is removed;
	 * one that is also on other products (a fish) is only taken off the
	 * medications; one on no medication is not listed. A kept tag or a
	 * brand on a medication whose description makes a claim gets a cleaned
	 * description.
	 *
	 * The theme's Treats / Use In attributes are one row each: the whole
	 * attribute (with its values and its filter pages) is removed once no
	 * product uses it any more (step 4 takes it off the products).
	 *
	 * @param array $words Words::get().
	 * @return array<int, array>
	 */
	public static function proposals( array $words ) {
		$rows    = array();
		$parent  = Inventory::parent_term();
		$tree    = Inventory::med_term_ids();
		$claim   = array( 'diseases', 'claims', 'dosing', 'labels' );
		$leave   = array_map( 'strtolower', Words::parse_commas( (string) ( $words['leave_out'] ?? '' ) ) );
		$med_set = array_fill_keys( array_map( 'intval', array_keys( Inventory::find( $words ) ) ), true );
		$targets_ids = array();
		foreach ( array_keys( self::TARGETS ) as $type ) {
			$t = self::target_term( $type );
			if ( $t ) {
				$targets_ids[ (int) $t->term_id ] = $type;
			}
		}

		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $cats ) ? $cats : array() as $t ) {
			if ( in_array( strtolower( $t->slug ), $leave, true ) ) {
				continue;
			}
			$in_tree  = in_array( (int) $t->term_id, $tree, true );
			$is_ther  = in_array( $t->slug, self::THERAPEUTIC, true );
			if ( ! $in_tree && ! $is_ther ) {
				continue; // Not a medication category: never touched here.
			}
			$name_hit = Finder::has( $t->name . ' ' . str_replace( '-', ' ', $t->slug ), $words, $claim );
			$desc_hit = Finder::has( (string) $t->description, $words, $claim );
			$is_form  = isset( self::FORM_SLUGS[ $t->slug ] ) && ! isset( $targets_ids[ (int) $t->term_id ] );
			if ( ! $is_ther && ! $name_hit && ! $desc_hit && ! $is_form && ! isset( $targets_ids[ (int) $t->term_id ] ) ) {
				continue;
			}
			$row         = self::row( $t, 'product_cat' );
			$row['hits'] = Finder::words( Finder::find( $t->name . ' · ' . $t->description, $words, $claim ) );
			$others      = self::others_in( (int) $t->term_id, $med_set );

			if ( isset( $targets_ids[ (int) $t->term_id ] ) ) {
				// A neutral category: its name as it should be, its description cleaned.
				$want = self::TARGETS[ $targets_ids[ (int) $t->term_id ] ][0];
				if ( $want === $t->name && ! $desc_hit ) {
					continue;
				}
				$row['action']   = 'rename';
				$row['new_name'] = $want;
				$row['new_desc'] = Finder::clean( (string) $t->description, $words, $claim );
				$row['note']     = __( 'Neutral category: name tidied, slug kept (the theme reads it).', 'fishotel-misc-plugin' );
			} elseif ( $parent && (int) $t->term_id === (int) $parent->term_id ) {
				if ( ! $desc_hit && ! $name_hit ) {
					continue;
				}
				$row['action']   = 'rename';
				$row['new_desc'] = Finder::clean( (string) $t->description, $words, $claim );
				$row['note']     = __( 'The Medications category itself keeps its name and slug (the theme finds medications by it); only its description is cleaned.', 'fishotel-misc-plugin' );
			} elseif ( $is_ther || $name_hit ) {
				$row['action'] = 'sort';
				$types         = array();
				foreach ( self::products_in( (int) $t->term_id, 'product_cat' ) as $pid ) {
					if ( isset( $med_set[ $pid ] ) ) {
						$types[] = self::type_of_product( $pid, $words );
					}
				}
				$count = array_count_values( $types );
				// Most products first; a tie goes to Powders & Chemicals, then Medicated Foods.
				$order = array( 'powder' => 0, 'food' => 1, 'water' => 2 );
				uksort(
					$count,
					function ( $a, $b ) use ( $count, $order ) {
						return $count[ $b ] <=> $count[ $a ] ?: $order[ $a ] <=> $order[ $b ];
					}
				);
				$row['redirect_to'] = $count ? (string) key( $count ) : 'parent';
				$row['merge_to']    = $row['redirect_to'];
				$row['types']       = $count;
				$row['note']        = __( 'Each product goes to Medicated Foods, Water Chemistry or Powders & Chemicals by what it is (and stays in Medications); this category is then removed and its address sent on.', 'fishotel-misc-plugin' );
			} elseif ( $is_form ) {
				$row['action']   = 'merge';
				$row['merge_to'] = self::FORM_SLUGS[ $t->slug ];
				$row['ticked']   = false;
				$row['note']     = __( 'A form category next to the new ones ("Powders" next to "Powders & Chemicals"). Merge it if you want one category per product. The Form filter on the Medications page (Flakes / Pellets / Powders / Liquid) reads this category: merged, that filter button finds nothing. Left unticked: you decide.', 'fishotel-misc-plugin' );
			} else {
				$row['action']   = 'rename';
				$row['new_desc'] = Finder::clean( (string) $t->description, $words, $claim );
			}

			if ( in_array( $row['action'], array( 'sort', 'merge' ), true ) ) {
				$row['affects'] = self::delete_preview( $t, 'product_cat' );
			}
			if ( $others && in_array( $row['action'], array( 'sort', 'merge' ), true ) ) {
				$row['action']  = 'keep';
				$row['ticked']  = false;
				$row['blocked'] = true;
				$row['note']    = sprintf( /* translators: %s: product names */ __( 'Also holds products that are not medications (%s). Sorting or merging it would turn them into medications, so this page leaves it alone: move those products out by hand first.', 'fishotel-misc-plugin' ), implode( ', ', array_slice( $others, 0, 5 ) ) . ( count( $others ) > 5 ? ' …' : '' ) );
			}
			if ( ! $in_tree ) {
				$row['ticked'] = false;
				$row['note']  .= ' ' . __( '(Not under Medications: left unticked.)', 'fishotel-misc-plugin' );
			}
			$rows[] = $row;
		}

		// Tags and brands.
		$taxes = array_merge( array( 'product_tag' ), self::BRAND_TAXONOMIES );
		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$objs   = self::products_in( (int) $t->term_id, $tax );
				$on_med = array_values( array_filter( $objs, function ( $o ) use ( $med_set ) {
					return isset( $med_set[ (int) $o ] );
				} ) );
				$others = count( $objs ) - count( $on_med );
				$name_hit = 'product_tag' === $tax && Finder::has( $t->name, $words, $claim );
				$desc_hit = Finder::has( (string) $t->description, $words, $claim );
				if ( $name_hit && ( $on_med || ! $objs ) ) {
					$row           = self::row( $t, $tax );
					$row['hits']   = Finder::words( Finder::find( $t->name, $words, $claim ) );
					$row['action'] = $others ? 'untag' : 'delete';
					if ( $others ) {
						// The tag and its page stay (for the fish): its description must not make a claim.
						$row['new_desc'] = $desc_hit ? Finder::clean( (string) $t->description, $words, $claim ) : (string) $t->description;
					}
					$row['note']   = $others
						? sprintf( /* translators: %d: products */ __( 'A disease or medicine-type tag that is also on %d other products (fish …): it is only taken off the medications. The tag and its page stay for the others.', 'fishotel-misc-plugin' ), $others )
						: __( 'A disease or medicine-type tag: taken off every product and removed; its page goes to Medications.', 'fishotel-misc-plugin' );
					$rows[] = $row;
				} elseif ( $desc_hit && $on_med ) {
					$row             = self::row( $t, $tax );
					$row['hits']     = Finder::words( Finder::find( (string) $t->description, $words, $claim ) );
					$row['action']   = 'rename';
					$row['new_desc'] = Finder::clean( (string) $t->description, $words, $claim );
					$row['note']     = 'product_tag' === $tax
						? __( 'A tag that stays (an ingredient, a form): only its description is cleaned. It is printed on its tag page and in the shop\'s product data.', 'fishotel-misc-plugin' )
						: __( 'A brand on medications: only its description is cleaned (it is printed on the brand page).', 'fishotel-misc-plugin' );
					$rows[] = $row;
				}
			}
		}

		// The theme's Treats / Use In attributes.
		foreach ( self::CLAIM_ATTRIBUTE_TAXONOMIES as $tax ) {
			$attr = self::attribute_def( $tax );
			if ( ! $attr ) {
				continue;
			}
			$values = taxonomy_exists( $tax ) ? get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) : array();
			$values = is_array( $values ) ? $values : array();
			$used   = array();
			foreach ( $values as $v ) {
				$used = array_merge( $used, self::products_in( (int) $v->term_id, $tax ) );
			}
			$used  = array_values( array_unique( $used ) );
			$names = wp_list_pluck( $values, 'name' );
			$row   = array(
				'id'          => (int) $attr['id'],
				'tax'         => 'attribute',
				'name'        => sprintf( /* translators: %s: attribute */ __( 'Attribute "%s"', 'fishotel-misc-plugin' ), $attr['name'] ),
				'slug'        => $tax,
				'desc'        => $names ? sprintf( /* translators: %s: values */ __( 'Values: %s', 'fishotel-misc-plugin' ), implode( ', ', $names ) ) : '',
				'count'       => count( $used ),
				'link'        => '',
				'action'      => $used ? 'keep' : 'delete_attribute',
				'ticked'      => ! $used,
				'blocked'     => (bool) $used,
				'new_name'    => '',
				'new_slug'    => '',
				'new_desc'    => '',
				'merge_to'    => 'parent',
				'redirect_to' => 'parent',
				'hits'        => array(),
				'types'       => array(),
				'affects'     => array(),
				'note'        => $used
					? sprintf( /* translators: %d: products */ __( 'Still on %d products. Apply step 4 first (it takes the attribute off each product); an attribute the variations are built on is flagged there for you to change by hand.', 'fishotel-misc-plugin' ), count( $used ) )
					: sprintf( /* translators: %d: values */ __( 'The theme\'s "%1$s" attribute and its %2$d values: removed, with their filter pages (also from WooCommerce\'s public attribute list). Its addresses go to Medications.', 'fishotel-misc-plugin' ), $attr['name'], count( $values ) ),
			);
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Names of the products in a category that are not medications.
	 *
	 * @param int   $term_id Category.
	 * @param array $med_set Medication product ids => true.
	 * @return string[]
	 */
	private static function others_in( $term_id, array $med_set ) {
		$out = array();
		foreach ( self::products_in( (int) $term_id, 'product_cat' ) as $pid ) {
			if ( ! isset( $med_set[ $pid ] ) && 'product' === get_post_type( $pid ) ) {
				$out[] = (string) get_the_title( $pid );
			}
		}
		return $out;
	}

	/**
	 * What removing a category also touches, for the page to say before
	 * the button: menu items that point at it, categories under it, and
	 * whether it has its own settings (picture, display type, order).
	 *
	 * @param \WP_Term $t   Term.
	 * @param string   $tax Taxonomy.
	 * @return array{menus: string[], children: string[], settings: bool}
	 */
	public static function delete_preview( $t, $tax ) {
		$menus = array();
		foreach ( self::menu_items( (int) $t->term_id, $tax ) as $m ) {
			$menus[] = '' !== $m['title'] ? $m['title'] : (string) $t->name;
		}
		$children = array();
		$kids     = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'parent'     => (int) $t->term_id,
			)
		);
		foreach ( is_array( $kids ) ? $kids : array() as $k ) {
			$children[] = (string) $k->name;
		}
		$meta = function_exists( 'get_term_meta' ) ? get_term_meta( (int) $t->term_id ) : array();
		return array(
			'menus'    => $menus,
			'children' => $children,
			'settings' => ! empty( $meta ),
		);
	}

	/**
	 * Menu items that point at a term.
	 *
	 * @param int    $term_id Term.
	 * @param string $tax     Taxonomy.
	 * @return array<int, array{id: int, title: string, object: string, object_id: int}>
	 */
	public static function menu_items( $term_id, $tax ) {
		$out   = array();
		$items = get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'meta_key'         => '_menu_item_object_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time admin page.
				'meta_value'       => (string) (int) $term_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		);
		foreach ( (array) $items as $p ) {
			if ( 'taxonomy' !== (string) get_post_meta( $p->ID, '_menu_item_type', true ) || $tax !== (string) get_post_meta( $p->ID, '_menu_item_object', true ) ) {
				continue;
			}
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => (string) $p->post_title,
				'object'    => $tax,
				'object_id' => (int) $term_id,
			);
		}
		return $out;
	}

	/**
	 * A WooCommerce attribute by its taxonomy name, as a plain array, or
	 * null.
	 *
	 * @param string $tax pa_… name.
	 * @return array|null
	 */
	public static function attribute_def( $tax ) {
		if ( ! function_exists( 'wc_attribute_taxonomy_id_by_name' ) || ! function_exists( 'wc_get_attribute' ) ) {
			return null;
		}
		$id = (int) wc_attribute_taxonomy_id_by_name( (string) $tax );
		$a  = $id ? wc_get_attribute( $id ) : null;
		if ( ! $a ) {
			return null;
		}
		return array(
			'id'           => (int) $a->id,
			'name'         => (string) $a->name,
			'slug'         => (string) $a->slug,
			'type'         => (string) $a->type,
			'order_by'     => (string) $a->order_by,
			'has_archives' => (bool) $a->has_archives,
		);
	}

	/**
	 * A table row for a term, before the action is chosen.
	 *
	 * @param \WP_Term $t   Term.
	 * @param string   $tax Taxonomy.
	 * @return array
	 */
	private static function row( $t, $tax ) {
		$link = get_term_link( $t );
		return array(
			'id'          => (int) $t->term_id,
			'tax'         => $tax,
			'name'        => (string) $t->name,
			'slug'        => (string) $t->slug,
			'desc'        => (string) $t->description,
			'count'       => (int) $t->count,
			'link'        => is_string( $link ) ? $link : '',
			'action'      => 'keep',
			'ticked'      => true,
			'blocked'     => false,
			'affects'     => array(),
			'new_name'    => (string) $t->name,
			'new_slug'    => (string) $t->slug,
			'new_desc'    => (string) $t->description,
			'merge_to'    => 'parent',
			'redirect_to' => 'parent',
			'hits'        => array(),
			'note'        => '',
			'types'       => array(),
		);
	}

	/**
	 * Product ids in a term (any status).
	 *
	 * @param int    $term_id Term id.
	 * @param string $tax     Taxonomy.
	 * @return int[]
	 */
	public static function products_in( $term_id, $tax ) {
		$ids = get_objects_in_term( (int) $term_id, $tax );
		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/**
	 * Which neutral category a product belongs in.
	 *
	 * @param int   $pid   Product id.
	 * @param array $words Words::get().
	 * @return string
	 */
	public static function type_of_product( $pid, array $words ) {
		$name  = (string) get_the_title( (int) $pid );
		$slugs = wp_get_post_terms( (int) $pid, 'product_cat', array( 'fields' => 'slugs' ) );
		return self::type_of( $name, (string) get_post_meta( (int) $pid, '_fishotel_active_ingredient', true ), Inventory::is_food( (int) $pid, $name, is_array( $slugs ) ? $slugs : array() ), $words );
	}

	/**
	 * A target choice ("food", "powder", "water", "parent", "term:12")
	 * → its term id, creating a neutral category under Medications when
	 * it does not exist yet.
	 *
	 * @param string $choice  Choice.
	 * @param array  $created Term ids created (added to).
	 * @return int 0 when it cannot be had.
	 */
	public static function resolve( $choice, array &$created ) {
		$choice = (string) $choice;
		$parent = Inventory::parent_term();
		if ( 'parent' === $choice ) {
			return $parent ? (int) $parent->term_id : 0;
		}
		if ( 0 === strpos( $choice, 'term:' ) ) {
			$t = get_term( (int) substr( $choice, 5 ), 'product_cat' );
			return $t && ! is_wp_error( $t ) ? (int) $t->term_id : 0;
		}
		if ( ! isset( self::TARGETS[ $choice ] ) ) {
			return 0;
		}
		$t = self::target_term( $choice );
		if ( $t ) {
			return (int) $t->term_id;
		}
		$new = wp_insert_term(
			self::TARGETS[ $choice ][0],
			'product_cat',
			array(
				'slug'   => self::TARGETS[ $choice ][1],
				'parent' => $parent ? (int) $parent->term_id : 0,
			)
		);
		if ( is_wp_error( $new ) ) {
			return 0;
		}
		$created[] = (int) $new['term_id'];
		return (int) $new['term_id'];
	}

	/**
	 * The old addresses of a term (its own, and for a category under
	 * another the short form WordPress also answers to), and the part of
	 * a product address it stands in ("medications/antiparasitic/").
	 *
	 * @param \WP_Term $t   Term.
	 * @param string   $tax Taxonomy.
	 * @return array{paths: string[], segment: string}
	 */
	public static function old_paths( $t, $tax ) {
		$link = get_term_link( $t );
		if ( ! is_string( $link ) ) {
			return array(
				'paths'   => array(),
				'segment' => '',
			);
		}
		$path  = Redirects::normalise_path( $link );
		$paths = array( $path );
		$hier  = array( $t->slug );
		foreach ( get_ancestors( (int) $t->term_id, $tax, 'taxonomy' ) as $aid ) {
			$a = get_term( (int) $aid, $tax );
			if ( $a && ! is_wp_error( $a ) ) {
				array_unshift( $hier, $a->slug );
			}
		}
		$segment = strtolower( implode( '/', $hier ) ) . '/';
		if ( count( $hier ) > 1 && substr( $path, -strlen( $segment ) ) === $segment ) {
			$paths[] = substr( $path, 0, -strlen( $segment ) ) . strtolower( $t->slug ) . '/';
		}
		return array(
			'paths'   => array_values( array_unique( $paths ) ),
			'segment' => 'product_cat' === $tax ? $segment : '',
		);
	}

	/**
	 * Carry out one row.
	 *
	 * @param array $row id, tax, action, new_name, new_slug, new_desc, merge_to, redirect_to.
	 * @param array $words Words::get().
	 * @return array{ok: bool, message: string, change: string}
	 */
	public static function apply( array $row, array $words ) {
		$tax    = (string) ( $row['tax'] ?? '' );
		$action = (string) ( $row['action'] ?? 'keep' );
		if ( 'keep' === $action ) {
			return self::answer( true, __( 'Left as it is.', 'fishotel-misc-plugin' ) );
		}
		if ( 'attribute' === $tax ) {
			return 'delete_attribute' === $action ? self::delete_attribute( (int) ( $row['id'] ?? 0 ) ) : self::answer( false, __( 'An attribute can only be removed here.', 'fishotel-misc-plugin' ) );
		}
		if ( ! in_array( $tax, array_merge( array( 'product_cat', 'product_tag' ), self::BRAND_TAXONOMIES ), true ) ) {
			return self::answer( false, __( 'Not a category or tag this step changes.', 'fishotel-misc-plugin' ) );
		}
		$t = get_term( (int) ( $row['id'] ?? 0 ), $tax );
		if ( ! $t || is_wp_error( $t ) ) {
			return self::answer( false, __( 'That category is not there any more — reload the page.', 'fishotel-misc-plugin' ) );
		}
		$allowed = array(
			'product_cat' => array( 'sort', 'merge', 'rename' ),
			'product_tag' => array( 'delete', 'untag', 'rename' ),
		);
		if ( ! in_array( $action, $allowed[ $tax ] ?? array( 'rename' ), true ) ) {
			return self::answer( false, __( 'That cannot be done to this kind of term.', 'fishotel-misc-plugin' ) );
		}
		$parent  = Inventory::parent_term();
		$med_set = array_fill_keys( array_map( 'intval', array_keys( Inventory::find( $words ) ) ), true );
		if ( in_array( $action, array( 'sort', 'merge', 'delete' ), true ) && $parent && (int) $t->term_id === (int) $parent->term_id ) {
			return self::answer( false, __( 'The Medications category itself is never removed: the theme finds medications by it.', 'fishotel-misc-plugin' ) );
		}
		if ( 'product_cat' === $tax ) {
			$leave = array_map( 'strtolower', Words::parse_commas( (string) ( $words['leave_out'] ?? '' ) ) );
			if ( in_array( strtolower( $t->slug ), $leave, true ) || ( ! in_array( (int) $t->term_id, Inventory::med_term_ids(), true ) && ! in_array( $t->slug, self::THERAPEUTIC, true ) ) ) {
				return self::answer( false, __( 'Not a medication category: this page does not change it.', 'fishotel-misc-plugin' ) );
			}
			if ( in_array( $action, array( 'sort', 'merge' ), true ) && self::others_in( (int) $t->term_id, $med_set ) ) {
				return self::answer( false, __( 'It also holds products that are not medications: move them out by hand first.', 'fishotel-misc-plugin' ) );
			}
		}
		if ( 'delete' === $action ) {
			foreach ( self::products_in( (int) $t->term_id, $tax ) as $pid ) {
				if ( ! isset( $med_set[ $pid ] ) ) {
					return self::answer( false, __( 'This tag is also on products that are not medications: choose "Take off the medications only".', 'fishotel-misc-plugin' ) );
				}
			}
		}

		$change = 'c' . time() . '_' . (int) $t->term_id;
		$old    = array(
			'term_id'     => (int) $t->term_id,
			'name'        => (string) $t->name,
			'slug'        => (string) $t->slug,
			'description' => (string) $t->description,
			'parent'      => (int) $t->parent,
		);
		// The parent by slug too: when the parent is removed as well, Undo
		// makes it again under a new id and finds it by its slug.
		$parent_term        = $t->parent ? get_term( (int) $t->parent, $tax ) : null;
		$old['parent_slug'] = $parent_term && ! is_wp_error( $parent_term ) ? (string) $parent_term->slug : '';
		$old_paths          = self::old_paths( $t, $tax );
		$entry     = array(
			'type'    => $action,
			'tax'     => $tax,
			'old'     => $old,
			'objects' => array(),
			'added'   => array(),
			'created' => array(),
			'at'      => time(),
			'user'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);

		if ( 'rename' === $action ) {
			$args = array(
				'name'        => sanitize_text_field( (string) ( $row['new_name'] ?? $t->name ) ),
				'slug'        => sanitize_title( (string) ( $row['new_slug'] ?? $t->slug ) ),
				'description' => wp_kses_post( (string) ( $row['new_desc'] ?? $t->description ) ),
			);
			if ( '' === $args['name'] ) {
				return self::answer( false, __( 'A category needs a name.', 'fishotel-misc-plugin' ) );
			}
			$done = wp_update_term( (int) $t->term_id, $tax, $args );
			if ( is_wp_error( $done ) ) {
				return self::answer( false, $done->get_error_message() );
			}
			$now          = get_term( (int) $t->term_id, $tax );
			$entry['new'] = array(
				'name'        => (string) $now->name,
				'slug'        => (string) $now->slug,
				'description' => (string) $now->description,
			);
			if ( $now->slug !== $old['slug'] ) {
				foreach ( $old_paths['paths'] as $path ) {
					Redirects::add(
						array(
							'from'      => $path,
							'tax'       => $tax,
							'slug'      => $old['slug'],
							'segment'   => $old_paths['segment'],
							'to_term'   => (int) $t->term_id,
							'to_tax'    => $tax,
							'to_url'    => (string) get_term_link( $now ),
							'keep_rest' => true,
							'change'    => $change,
						)
					);
				}
			}
			self::record( $change, $entry );
			return self::answer( true, __( 'Renamed.', 'fishotel-misc-plugin' ), $change );
		}

		if ( 'untag' === $action ) {
			$desc = isset( $row['new_desc'] ) ? wp_kses_post( (string) $row['new_desc'] ) : (string) $t->description;
			if ( $desc !== (string) $t->description ) {
				$done = wp_update_term( (int) $t->term_id, $tax, array( 'description' => $desc ) );
				if ( is_wp_error( $done ) ) {
					return self::answer( false, $done->get_error_message() );
				}
				$entry['new'] = array( 'description' => $desc );
			}
			foreach ( self::products_in( (int) $t->term_id, $tax ) as $pid ) {
				if ( isset( $med_set[ $pid ] ) ) {
					wp_remove_object_terms( $pid, (int) $t->term_id, $tax );
					$entry['objects'][] = $pid;
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients( $pid );
					}
				}
			}
			self::record( $change, $entry );
			return self::answer( true, __( 'Taken off the medications.', 'fishotel-misc-plugin' ), $change );
		}

		// sort / merge / delete: move the products, then remove the term.
		$products         = self::products_in( (int) $t->term_id, $tax );
		$entry['objects'] = $products;
		$created          = array();
		$redirect_id      = 0;

		if ( 'product_cat' === $tax ) {
			$fixed = 'merge' === $action ? self::resolve( (string) ( $row['merge_to'] ?? 'parent' ), $created ) : 0;
			if ( 'merge' === $action && ( ! $fixed || $fixed === (int) $t->term_id ) ) {
				return self::answer( false, __( 'Choose another category to merge into.', 'fishotel-misc-plugin' ) );
			}
			foreach ( $products as $pid ) {
				$target = 'merge' === $action ? $fixed : self::resolve( self::type_of_product( $pid, $words ), $created );
				$want   = array_filter( array( $target, $parent ? (int) $parent->term_id : 0 ) );
				$have   = wp_get_object_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
				$have   = is_array( $have ) ? array_map( 'intval', $have ) : array();
				$add    = array_values( array_diff( $want, $have ) );
				if ( $add ) {
					wp_set_object_terms( $pid, $add, 'product_cat', true );
					foreach ( $add as $tid ) {
						$entry['added'][] = array( $pid, (int) $tid );
					}
				}
				wp_remove_object_terms( $pid, (int) $t->term_id, 'product_cat' );
			}
			$redirect_id = 'merge' === $action ? $fixed : self::resolve( (string) ( $row['redirect_to'] ?? 'parent' ), $created );
		} else {
			foreach ( $products as $pid ) {
				wp_remove_object_terms( $pid, (int) $t->term_id, $tax );
			}
			$redirect_id = self::resolve( 'parent', $created );
		}
		$entry['created'] = $created;
		if ( $created ) {
			$l                  = Ledger::all();
			$l['created_terms'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $l['created_terms'] ?? array() ) ), $created ) ) );
			Ledger::save( $l );
		}

		// What WordPress would lose with the term: kept so Undo puts it back.
		$entry['keep'] = self::keep_before_delete( $t, $tax, $redirect_id, $change );

		$deleted = wp_delete_term( (int) $t->term_id, $tax );
		if ( is_wp_error( $deleted ) || ! $deleted ) {
			self::record( $change, $entry );
			return self::answer( false, __( 'The products were moved but WordPress did not remove the old category. Undo puts them back.', 'fishotel-misc-plugin' ), $change );
		}
		foreach ( $products as $pid ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
		}

		$to_url = $redirect_id ? get_term_link( $redirect_id, 'product_cat' ) : '';
		foreach ( $old_paths['paths'] as $path ) {
			Redirects::add(
				array(
					'from'    => $path,
					'tax'     => $tax,
					'slug'    => $old['slug'],
					'segment' => $old_paths['segment'],
					'to_term' => $redirect_id,
					'to_tax'  => 'product_cat',
					'to_url'  => is_string( $to_url ) ? $to_url : '',
					'change'  => $change,
				)
			);
		}
		self::record( $change, $entry );
		return self::answer( true, 'delete' === $action ? __( 'Removed.', 'fishotel-misc-plugin' ) : __( 'Products moved, old category removed.', 'fishotel-misc-plugin' ), $change );
	}

	/**
	 * Before a term is removed: what WordPress would lose with it, recorded
	 * and looked after.
	 *   - menu items pointing at it are pointed at the category its address
	 *     goes to (a menu label that is a claim is emptied, so the new
	 *     category's name shows) instead of being deleted by WordPress;
	 *   - its own settings (term meta: picture, display type, order …);
	 *   - the categories under it (WordPress moves them up one level): their
	 *     old addresses get a 301 to where they are now;
	 *   - products whose Yoast primary category it was (pointed at the new one);
	 *   - its Yoast SEO settings (option wpseo_taxonomy_meta).
	 *
	 * @param \WP_Term $t           Term.
	 * @param string   $tax         Taxonomy.
	 * @param int      $redirect_id Category its address goes to.
	 * @param string   $change      Change id.
	 * @return array
	 */
	private static function keep_before_delete( $t, $tax, $redirect_id, $change ) {
		$tid  = (int) $t->term_id;
		$keep = array(
			'menus'    => array(),
			'meta'     => function_exists( 'get_term_meta' ) ? (array) get_term_meta( $tid ) : array(),
			'children' => array(),
			'primary'  => array(),
			'yoast'    => null,
		);
		$words = Words::get();
		foreach ( self::menu_items( $tid, $tax ) as $m ) {
			$keep['menus'][] = $m;
			if ( $redirect_id ) {
				update_post_meta( $m['id'], '_menu_item_object', 'product_cat' );
				update_post_meta( $m['id'], '_menu_item_object_id', (string) (int) $redirect_id );
				if ( '' !== $m['title'] && Finder::has( $m['title'], $words, array( 'diseases', 'claims', 'dosing', 'labels' ) ) ) {
					wp_update_post(
						array(
							'ID'         => $m['id'],
							'post_title' => '',
						)
					);
				}
			}
		}
		$kids = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'parent'     => $tid,
			)
		);
		foreach ( is_array( $kids ) ? $kids : array() as $k ) {
			$paths              = self::old_paths( $k, $tax );
			$keep['children'][] = array(
				'term_id' => (int) $k->term_id,
				'slug'    => (string) $k->slug,
			);
			foreach ( $paths['paths'] as $path ) {
				Redirects::add(
					array(
						'from'      => $path,
						'tax'       => $tax,
						'slug'      => (string) $k->slug,
						'segment'   => $paths['segment'],
						'to_term'   => (int) $k->term_id,
						'to_tax'    => $tax,
						'to_url'    => '',
						'keep_rest' => true,
						'child'     => true,
						'change'    => $change,
					)
				);
			}
		}
		if ( 'product_cat' === $tax ) {
			$primary = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'meta_key'         => '_yoast_wpseo_primary_product_cat', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time admin page.
					'meta_value'       => (string) $tid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'suppress_filters' => true,
				)
			);
			foreach ( (array) $primary as $pid ) {
				$keep['primary'][] = (int) $pid;
				if ( $redirect_id ) {
					update_post_meta( (int) $pid, '_yoast_wpseo_primary_product_cat', (string) (int) $redirect_id );
				}
			}
		}
		$y = get_option( 'wpseo_taxonomy_meta', array() );
		if ( is_array( $y ) && isset( $y[ $tax ][ $tid ] ) ) {
			$keep['yoast'] = $y[ $tax ][ $tid ];
		}
		return $keep;
	}

	/**
	 * After Undo made a removed term again (new id): put back what
	 * keep_before_delete() recorded.
	 *
	 * @param array  $keep    Record.
	 * @param int    $old_id  The term's old id.
	 * @param int    $new_id  Its new id.
	 * @param string $tax     Taxonomy.
	 */
	private static function restore_kept( array $keep, $old_id, $new_id, $tax ) {
		if ( function_exists( 'get_term_meta' ) ) {
			foreach ( array_keys( (array) get_term_meta( $new_id ) ) as $k ) {
				if ( 0 !== strpos( (string) $k, 'product_count_' ) ) {
					delete_term_meta( $new_id, $k );
				}
			}
			foreach ( (array) ( $keep['meta'] ?? array() ) as $k => $vals ) {
				if ( 0 === strpos( (string) $k, 'product_count_' ) ) {
					continue; // WooCommerce's own count, recorded after the products left: WooCommerce counts it again.
				}
				foreach ( (array) $vals as $v ) {
					add_term_meta( $new_id, (string) $k, maybe_unserialize( $v ) );
				}
			}
		}
		foreach ( (array) ( $keep['menus'] ?? array() ) as $m ) {
			if ( ! get_post( (int) $m['id'] ) ) {
				continue;
			}
			update_post_meta( (int) $m['id'], '_menu_item_object', (string) $m['object'] );
			update_post_meta( (int) $m['id'], '_menu_item_object_id', (string) (int) $new_id );
			if ( (string) get_post( (int) $m['id'] )->post_title !== (string) $m['title'] ) {
				wp_update_post(
					array(
						'ID'         => (int) $m['id'],
						'post_title' => wp_slash( (string) $m['title'] ),
					)
				);
			}
		}
		foreach ( (array) ( $keep['children'] ?? array() ) as $c ) {
			$child = get_term( (int) $c['term_id'], $tax );
			if ( ( ! $child || is_wp_error( $child ) ) && ! empty( $c['slug'] ) ) {
				$child = get_term_by( 'slug', (string) $c['slug'], $tax ); // Removed and made again since: a new id.
			}
			if ( $child && ! is_wp_error( $child ) ) {
				wp_update_term( (int) $child->term_id, $tax, array( 'parent' => (int) $new_id ) );
			}
		}
		foreach ( (array) ( $keep['primary'] ?? array() ) as $pid ) {
			update_post_meta( (int) $pid, '_yoast_wpseo_primary_product_cat', (string) (int) $new_id );
		}
		if ( null !== ( $keep['yoast'] ?? null ) ) {
			$y = get_option( 'wpseo_taxonomy_meta', array() );
			$y = is_array( $y ) ? $y : array();
			unset( $y[ $tax ][ (int) $old_id ] );
			$y[ $tax ][ (int) $new_id ] = $keep['yoast'];
			update_option( 'wpseo_taxonomy_meta', $y );
		}
	}

	/**
	 * Remove one of the theme's claim attributes (Treats / Use In) with its
	 * values, once no product uses it. Its definition and values are
	 * recorded; Undo makes it again with the same name, slug and values.
	 *
	 * @param int $id Attribute id.
	 * @return array{ok: bool, message: string, change: string}
	 */
	public static function delete_attribute( $id ) {
		$def = null;
		foreach ( self::CLAIM_ATTRIBUTE_TAXONOMIES as $tax ) {
			$d = self::attribute_def( $tax );
			if ( $d && (int) $d['id'] === (int) $id ) {
				$def = $d;
				$def['tax'] = $tax;
			}
		}
		if ( ! $def ) {
			return self::answer( false, __( 'Not one of the theme\'s Treats / Use In attributes.', 'fishotel-misc-plugin' ) );
		}
		$tax    = $def['tax'];
		$values = taxonomy_exists( $tax ) ? get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) : array();
		$values = is_array( $values ) ? $values : array();
		$terms  = array();
		$change = 'c' . time() . '_a' . (int) $id;
		foreach ( $values as $v ) {
			if ( self::products_in( (int) $v->term_id, $tax ) ) {
				return self::answer( false, __( 'Still on a product: apply step 4 first.', 'fishotel-misc-plugin' ) );
			}
			$terms[] = array(
				'name'        => (string) $v->name,
				'slug'        => (string) $v->slug,
				'description' => (string) $v->description,
				'meta'        => function_exists( 'get_term_meta' ) ? (array) get_term_meta( (int) $v->term_id ) : array(),
			);
			$link = get_term_link( $v );
			if ( $def['has_archives'] && is_string( $link ) ) {
				$paths = self::old_paths( $v, $tax );
				$to    = Inventory::parent_term();
				foreach ( $paths['paths'] as $path ) {
					Redirects::add(
						array(
							'from'    => $path,
							'tax'     => $tax,
							'slug'    => (string) $v->slug,
							'segment' => '',
							'to_term' => $to ? (int) $to->term_id : 0,
							'to_tax'  => 'product_cat',
							'to_url'  => $to ? (string) get_term_link( $to ) : '',
							'change'  => $change,
						)
					);
				}
			}
		}
		$done = wc_delete_attribute( (int) $id );
		if ( ! $done ) {
			Redirects::remove( $change );
			return self::answer( false, __( 'WooCommerce did not remove it.', 'fishotel-misc-plugin' ) );
		}
		self::record(
			$change,
			array(
				'type'      => 'delete_attribute',
				'tax'       => $tax,
				'old'       => array(
					'term_id'     => (int) $id,
					'name'        => (string) $def['name'],
					'slug'        => $tax,
					'description' => '',
					'parent'      => 0,
				),
				'attribute' => $def,
				'values'    => $terms,
				'objects'   => array(),
				'added'     => array(),
				'created'   => array(),
				'at'        => time(),
				'user'      => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);
		return self::answer( true, __( 'Attribute removed.', 'fishotel-misc-plugin' ), $change );
	}

	/**
	 * Put back a removed claim attribute by its taxonomy name (used when a
	 * product's Undo needs it before its own category Undo was pressed).
	 *
	 * @param string $tax pa_… name.
	 * @return bool
	 */
	public static function restore_attribute( $tax ) {
		foreach ( (array) Ledger::all()['terms'] as $change => $e ) {
			if ( 'delete_attribute' === ( $e['type'] ?? '' ) && (string) $e['tax'] === (string) $tax ) {
				return self::undo( (string) $change )['ok'];
			}
		}
		return false;
	}

	/**
	 * Undo one change.
	 *
	 * @param string $change Change id.
	 * @return array{ok: bool, message: string, change: string}
	 */
	public static function undo( $change ) {
		$l = Ledger::all();
		if ( empty( $l['terms'][ $change ] ) ) {
			return self::answer( false, __( 'Nothing to undo.', 'fishotel-misc-plugin' ) );
		}
		$e   = $l['terms'][ $change ];
		$tax = (string) $e['tax'];
		$old = (array) $e['old'];

		if ( 'delete_attribute' === $e['type'] ) {
			$def = (array) $e['attribute'];
			if ( ! wc_attribute_taxonomy_id_by_name( $tax ) ) {
				$made = wc_create_attribute(
					array(
						'name'         => (string) $def['name'],
						'slug'         => substr( $tax, 3 ),
						'type'         => (string) $def['type'],
						'order_by'     => (string) $def['order_by'],
						'has_archives' => (bool) $def['has_archives'],
					)
				);
				if ( is_wp_error( $made ) ) {
					return self::answer( false, $made->get_error_message() );
				}
			}
			if ( ! taxonomy_exists( $tax ) ) {
				// WooCommerce registers attribute taxonomies on "init": do it for this request.
				register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'public' => (bool) $def['has_archives'], 'rewrite' => false ) );
			}
			foreach ( (array) $e['values'] as $v ) {
				$t = get_term_by( 'slug', (string) $v['slug'], $tax );
				if ( ! $t ) {
					$m = wp_insert_term( (string) $v['name'], $tax, array( 'slug' => (string) $v['slug'], 'description' => (string) $v['description'] ) );
					if ( ! is_wp_error( $m ) && function_exists( 'add_term_meta' ) ) {
						foreach ( (array) $v['meta'] as $k => $vals ) {
							foreach ( (array) $vals as $one ) {
								add_term_meta( (int) $m['term_id'], (string) $k, maybe_unserialize( $one ) );
							}
						}
					}
				}
			}
		} elseif ( 'rename' === $e['type'] ) {
			$now = get_term( (int) $old['term_id'], $tax );
			if ( ! $now || is_wp_error( $now ) ) {
				return self::answer( false, __( 'The category is not there any more.', 'fishotel-misc-plugin' ) );
			}
			$new = (array) ( $e['new'] ?? array() );
			if ( $now->name !== ( $new['name'] ?? '' ) || $now->slug !== ( $new['slug'] ?? '' ) || (string) $now->description !== (string) ( $new['description'] ?? '' ) ) {
				return self::answer( false, __( 'Changed again since — left as it is.', 'fishotel-misc-plugin' ) );
			}
			$done = wp_update_term(
				(int) $old['term_id'],
				$tax,
				array(
					'name'        => $old['name'],
					'slug'        => $old['slug'],
					'description' => $old['description'],
				)
			);
			if ( is_wp_error( $done ) ) {
				return self::answer( false, $done->get_error_message() );
			}
		} elseif ( 'untag' === $e['type'] ) {
			$back = get_term( (int) $old['term_id'], $tax );
			if ( ! $back || is_wp_error( $back ) ) {
				return self::answer( false, __( 'The tag is not there any more.', 'fishotel-misc-plugin' ) );
			}
			foreach ( (array) $e['objects'] as $pid ) {
				wp_set_object_terms( (int) $pid, array( (int) $old['term_id'] ), $tax, true );
			}
			// Its description back, unless someone wrote a new one since.
			if ( isset( $e['new']['description'] ) && (string) $back->description === (string) $e['new']['description'] ) {
				wp_update_term( (int) $old['term_id'], $tax, array( 'description' => (string) $old['description'] ) );
			}
		} else {
			$back = get_term_by( 'slug', $old['slug'], $tax );
			if ( $back && ! is_wp_error( $back ) ) {
				$tid = (int) $back->term_id;
			} else {
				$parent  = (int) $old['parent'];
				$orphan  = false;
				if ( $parent && ! get_term( $parent, $tax ) ) {
					$parent = 0;
					$p_slug = (string) ( $old['parent_slug'] ?? '' );
					$p_now  = '' !== $p_slug ? get_term_by( 'slug', $p_slug, $tax ) : false;
					if ( $p_now && ! is_wp_error( $p_now ) ) {
						$parent = (int) $p_now->term_id;
					} elseif ( '' !== $p_slug ) {
						$orphan = true; // Its parent is not back yet: it goes under it when it is.
					}
				}
				$made = wp_insert_term(
					$old['name'],
					$tax,
					array(
						'slug'        => $old['slug'],
						'description' => $old['description'],
						'parent'      => $parent,
					)
				);
				if ( is_wp_error( $made ) ) {
					return self::answer( false, $made->get_error_message() );
				}
				$tid = (int) $made['term_id'];
				self::adopt( $tid, (string) $old['slug'], $tax, $orphan ? (string) $old['parent_slug'] : '' );
			}
			foreach ( (array) $e['objects'] as $pid ) {
				wp_set_object_terms( (int) $pid, array( $tid ), $tax, true );
			}
			foreach ( (array) $e['added'] as $pair ) {
				wp_remove_object_terms( (int) $pair[0], (int) $pair[1], 'product_cat' );
			}
			self::restore_kept( (array) ( $e['keep'] ?? array() ), (int) $old['term_id'], $tid, $tax );
			// Redirects (and the record) are cleared below before the empty
			// neutral categories go, so a menu item pointed back is never
			// left pointing at a category being removed.
			Redirects::remove( $change );
			$l = Ledger::all();
			unset( $l['terms'][ $change ] );
			Ledger::save( $l );
			self::forget_created( (array) $e['created'] );
			foreach ( (array) $e['objects'] as $pid ) {
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( (int) $pid );
				}
			}
			return self::answer( true, __( 'Put back.', 'fishotel-misc-plugin' ), $change );
		}

		Redirects::remove( $change );
		$l = Ledger::all();
		unset( $l['terms'][ $change ] );
		Ledger::save( $l );
		self::forget_created( array() );
		return self::answer( true, __( 'Put back.', 'fishotel-misc-plugin' ), $change );
	}

	/**
	 * A term made again by Undo: sub-categories put back before it (while
	 * it was still gone) now go under it; and when its own parent is not
	 * back yet, it waits on the list for that parent.
	 *
	 * @param int    $tid         The term made again.
	 * @param string $slug        Its slug.
	 * @param string $tax         Taxonomy.
	 * @param string $wait_for    Slug of its parent when that is not back yet ('' = none).
	 */
	private static function adopt( $tid, $slug, $tax, $wait_for ) {
		$l    = Ledger::all();
		$list = (array) ( $l['orphans'] ?? array() );
		$left = array();
		foreach ( $list as $o ) {
			if ( (string) $o['tax'] === (string) $tax && (string) $o['parent_slug'] === (string) $slug ) {
				$child = get_term_by( 'slug', (string) $o['slug'], $tax );
				if ( $child && ! is_wp_error( $child ) && 0 === (int) $child->parent ) {
					wp_update_term( (int) $child->term_id, $tax, array( 'parent' => (int) $tid ) );
				}
				continue;
			}
			$left[] = $o;
		}
		if ( '' !== $wait_for ) {
			$left[] = array(
				'tax'         => (string) $tax,
				'slug'        => (string) $slug,
				'parent_slug' => (string) $wait_for,
			);
		}
		$l['orphans'] = $left;
		Ledger::save( $l );
	}

	/**
	 * A neutral category this step made is removed again once it is empty
	 * and no change still on the record moved anything into it — whatever
	 * order the changes are undone in.
	 *
	 * @param int[] $created Term ids made by the change being undone.
	 */
	private static function forget_created( array $created ) {
		$l    = Ledger::all();
		$made = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $l['created_terms'] ?? array() ) ), array_map( 'intval', $created ) ) ) );
		$used = array();
		foreach ( (array) $l['terms'] as $e ) {
			foreach ( (array) ( $e['added'] ?? array() ) as $pair ) {
				$used[ (int) $pair[1] ] = true;
			}
		}
		$keep = array();
		foreach ( $made as $cid ) {
			$c = get_term( $cid, 'product_cat' );
			if ( ! $c || is_wp_error( $c ) ) {
				continue;
			}
			if ( isset( $used[ $cid ] ) || self::products_in( $cid, 'product_cat' ) ) {
				$keep[] = $cid;
				continue;
			}
			wp_delete_term( $cid, 'product_cat' );
		}
		$l['created_terms'] = $keep;
		Ledger::save( $l );
	}

	/**
	 * Store a change in the record.
	 *
	 * @param string $change Change id.
	 * @param array  $entry  Entry.
	 */
	private static function record( $change, array $entry ) {
		$l                    = Ledger::all();
		$l['terms'][ $change ] = $entry;
		Ledger::save( $l );
	}

	/**
	 * An answer.
	 *
	 * @param bool   $ok      Done.
	 * @param string $message Words.
	 * @param string $change  Change id.
	 * @return array{ok: bool, message: string, change: string}
	 */
	private static function answer( $ok, $message, $change = '' ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
			'change'  => (string) $change,
		);
	}
}
