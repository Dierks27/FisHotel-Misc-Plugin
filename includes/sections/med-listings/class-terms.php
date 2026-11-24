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
		'food'   => array( 'Medicated Foods', 'medicated-foods' ),
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
			$slugs[] = 'medicated-food';
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
	 * The proposal table.
	 *
	 * @param array $words Words::get().
	 * @return array<int, array>
	 */
	public static function proposals( array $words ) {
		$rows   = array();
		$parent = Inventory::parent_term();
		$tree   = Inventory::med_term_ids();
		$claim  = array( 'diseases', 'claims', 'dosing', 'labels' );
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
			$in_tree = in_array( (int) $t->term_id, $tree, true );
			$name_hit = Finder::has( $t->name . ' ' . str_replace( '-', ' ', $t->slug ), $words, $claim );
			$desc_hit = Finder::has( (string) $t->description, $words, $claim );
			$is_ther  = in_array( $t->slug, self::THERAPEUTIC, true );
			if ( ! $is_ther && ! $name_hit && ! ( $in_tree && $desc_hit ) && ! isset( $targets_ids[ (int) $t->term_id ] ) ) {
				continue;
			}
			$row = self::row( $t, 'product_cat' );
			$row['hits'] = Finder::words( Finder::find( $t->name . ' · ' . $t->description, $words, $claim ) );

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
					$types[] = self::type_of_product( $pid, $words );
				}
				$count             = array_count_values( $types );
				arsort( $count );
				$row['redirect_to'] = $count ? (string) key( $count ) : 'parent';
				$row['merge_to']    = $row['redirect_to'];
				$row['types']       = $count;
				$row['note']        = __( 'Each product goes to Medicated Foods, Water Chemistry or Powders & Chemicals by what it is; this category is then removed and its address sent on.', 'fishotel-misc-plugin' );
			} else {
				$row['action']   = 'rename';
				$row['new_desc'] = Finder::clean( (string) $t->description, $words, $claim );
			}
			$rows[] = $row;
		}

		$taxes = array_merge( array( 'product_tag' ), self::CLAIM_ATTRIBUTE_TAXONOMIES );
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
				$attr = in_array( $tax, self::CLAIM_ATTRIBUTE_TAXONOMIES, true );
				if ( ! $attr && ! Finder::has( $t->name, $words, $claim ) ) {
					continue;
				}
				$row                = self::row( $t, $tax );
				$row['hits']        = Finder::words( Finder::find( $t->name, $words, $claim ) );
				$row['action']      = 'delete';
				$row['redirect_to'] = 'parent';
				$row['note']        = $attr
					? __( 'A value of the theme\'s Treats / Use In attribute: removed with its filter page.', 'fishotel-misc-plugin' )
					: __( 'A disease or medicine-type tag: taken off every product and removed; its page goes to Medications.', 'fishotel-misc-plugin' );
				$rows[]             = $row;
			}
		}

		return $rows;
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
		if ( ! in_array( $tax, array_merge( array( 'product_cat', 'product_tag' ), self::CLAIM_ATTRIBUTE_TAXONOMIES ), true ) ) {
			return self::answer( false, __( 'Not a category or tag this step changes.', 'fishotel-misc-plugin' ) );
		}
		$t = get_term( (int) ( $row['id'] ?? 0 ), $tax );
		if ( ! $t || is_wp_error( $t ) ) {
			return self::answer( false, __( 'That category is not there any more — reload the page.', 'fishotel-misc-plugin' ) );
		}
		$parent = Inventory::parent_term();
		if ( 'keep' === $action ) {
			return self::answer( true, __( 'Left as it is.', 'fishotel-misc-plugin' ) );
		}
		if ( in_array( $action, array( 'sort', 'merge', 'delete' ), true ) && $parent && (int) $t->term_id === (int) $parent->term_id ) {
			return self::answer( false, __( 'The Medications category itself is never removed: the theme finds medications by it.', 'fishotel-misc-plugin' ) );
		}

		$change = 'c' . time() . '_' . (int) $t->term_id;
		$old    = array(
			'term_id'     => (int) $t->term_id,
			'name'        => (string) $t->name,
			'slug'        => (string) $t->slug,
			'description' => (string) $t->description,
			'parent'      => (int) $t->parent,
		);
		$old_paths = self::old_paths( $t, $tax );
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
			$redirect_id = self::resolve( (string) ( $row['redirect_to'] ?? 'parent' ), $created );
		}
		$entry['created'] = $created;

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

		if ( 'rename' === $e['type'] ) {
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
		} else {
			$back = get_term_by( 'slug', $old['slug'], $tax );
			if ( $back && ! is_wp_error( $back ) ) {
				$tid = (int) $back->term_id;
			} else {
				$parent = (int) $old['parent'];
				if ( $parent && ! get_term( $parent, $tax ) ) {
					$parent = 0;
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
			}
			foreach ( (array) $e['objects'] as $pid ) {
				wp_set_object_terms( (int) $pid, array( $tid ), $tax, true );
			}
			foreach ( (array) $e['added'] as $pair ) {
				wp_remove_object_terms( (int) $pair[0], (int) $pair[1], 'product_cat' );
			}
			foreach ( (array) $e['created'] as $cid ) {
				$c = get_term( (int) $cid, 'product_cat' );
				if ( $c && ! is_wp_error( $c ) && ! self::products_in( (int) $cid, 'product_cat' ) ) {
					wp_delete_term( (int) $cid, 'product_cat' );
				}
			}
			foreach ( (array) $e['objects'] as $pid ) {
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( (int) $pid );
				}
			}
		}

		Redirects::remove( $change );
		$l = Ledger::all();
		unset( $l['terms'][ $change ] );
		Ledger::save( $l );
		return self::answer( true, __( 'Put back.', 'fishotel-misc-plugin' ), $change );
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
