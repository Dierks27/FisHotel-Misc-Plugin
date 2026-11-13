<?php
/**
 * "Fill empty ones from the map" — so the Bag size does not have to be set
 * on hundreds of variations by hand.
 *
 * The size → bag map already answers for every variation that has no Bag
 * size of its own; this tool WRITES that answer onto the variation, so the
 * product record shows it. Two steps:
 *
 *   preview  counts, and changes nothing: how many variations have no Bag
 *            size, how many of those the map would set (and to which bag),
 *            how many carry a size the map has no bag for, how many carry
 *            no size at all, how many are of a product that is not packed
 *            in a bag;
 *   run      sets them, a batch at a time (the page asks again until it is
 *            done). It never touches a variation that already has a value:
 *            the list comes from the variations WITHOUT one, and each is
 *            checked once more on the product object just before it is
 *            written.
 *
 * Only what is PACKED IN A BAG is filled — the one rule of
 * Livestock::decide(), read from the variation's product: "Livestock —
 * packed in a bag" ticked on it; unticked, never; with no stored choice, a
 * product in one of the fish categories (Lines::fish_categories()). A
 * variation of any other product is counted and left alone: a net or a jar
 * of food that happens to use the Size attribute needs no Bag size.
 *
 * A value is written through the WooCommerce product object
 * (update_meta_data() + save_meta_data()): the hidden meta `_fh_bag_size`,
 * nothing else — no attribute, no variation, no price, no stock.
 *
 * plan() is pure; preview_request() and run_request() check the capability
 * (manage_woocommerce) and the nonce themselves and answer with a status.
 *
 * @package FisHotel\Misc\Sections\Packing
 */

namespace FisHotel\Misc\Sections\Packing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fill
 */
class Fill {

	/**
	 * Nonce action of the tool.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'fishotel_packing_fill';

	/**
	 * Variations looked at per request while running.
	 *
	 * @var int
	 */
	const BATCH = 150;

	/**
	 * Register the AJAX actions.
	 */
	public function init() {
		add_action( 'wp_ajax_fishotel_packing_fill_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_fishotel_packing_fill_run', array( $this, 'ajax_run' ) );
	}

	/**
	 * What the map would do with the variations that have no Bag size
	 * (pure).
	 *
	 * @param array[]       $rows     Store::variations_without_bag(): id, parent, attributes.
	 * @param array         $settings Settings (bags, size_map).
	 * @param array         $standard Size_List::standard().
	 * @param callable|null $is_fish  function ( $parent_id ): bool — whether a product is packed in
	 *                                a bag (fish_test()); asked once per product. Null = every row is.
	 * @return array{empty: int, set: array<int, int>, by_bag: array<int, int>, unmapped: array<string, int>, no_size: int, not_fish: int}
	 *         empty = variations without a value; set = variation id → bag id;
	 *         by_bag = bag id → how many; unmapped = size slug → how many;
	 *         no_size = how many carry no size; not_fish = how many are of a
	 *         product that is not packed in a bag.
	 */
	public static function plan( array $rows, array $settings, array $standard, $is_fish = null ) {
		$out = array(
			'empty'    => 0,
			'set'      => array(),
			'by_bag'   => array(),
			'unmapped' => array(),
			'no_size'  => 0,
			'not_fish' => 0,
		);
		$fish = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) < 1 ) {
				continue;
			}
			$out['empty']++;

			if ( null !== $is_fish ) {
				$parent = (int) ( $row['parent'] ?? 0 );
				if ( ! isset( $fish[ $parent ] ) ) {
					$fish[ $parent ] = (bool) call_user_func( $is_fish, $parent );
				}
				if ( ! $fish[ $parent ] ) {
					$out['not_fish']++;
					continue;
				}
			}

			$size = Size_List::size_of( isset( $row['attributes'] ) && is_array( $row['attributes'] ) ? $row['attributes'] : array(), $standard );
			if ( '' === $size ) {
				$out['no_size']++;
				continue;
			}

			$resolved = Bags::resolve( '', $size, $settings );
			if ( ! $resolved['bag'] ) {
				$out['unmapped'][ $size ] = ( $out['unmapped'][ $size ] ?? 0 ) + 1;
				continue;
			}

			$bag_id                   = (int) $resolved['bag']['id'];
			$out['set'][ (int) $row['id'] ] = $bag_id;
			$out['by_bag'][ $bag_id ] = ( $out['by_bag'][ $bag_id ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * The question plan() asks about a variation's product: is it packed in
	 * a bag — the one rule the order lines go by (Livestock::decide()). The
	 * "Livestock — packed in a bag" checkbox of the product when it was
	 * ticked or unticked; with no stored choice, whether the product is in
	 * one of the fish categories (its own category slugs). The rows are the
	 * variations WITHOUT a Bag size, so a Bag size from before the checkbox
	 * is never in play here — also not one on another variation of the same
	 * product, whose box the editor draws ticked: describe() says so.
	 *
	 * @param int[]       $parent_ids The products about to be asked about (their categories and
	 *                                their checkbox values are read in one go).
	 * @param object|null $store      Store (or the tests' stand-in) with packs_flags(); without
	 *                                one each product is asked through its product object.
	 * @return callable function ( $parent_id ): bool.
	 */
	public static function fish_test( array $parent_ids = array(), $store = null ) {
		$categories = Lines::fish_categories();
		$parent_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		if ( $parent_ids && function_exists( 'update_object_term_cache' ) ) {
			update_object_term_cache( $parent_ids, 'product' );
		}

		// The checkbox values of the products named, read together; null = not read that way.
		$flags = null;
		if ( $parent_ids && is_object( $store ) && method_exists( $store, 'packs_flags' ) ) {
			$flags = array();
			$read  = (array) $store->packs_flags( $parent_ids );
			foreach ( $parent_ids as $id ) {
				$flags[ $id ] = Livestock::flag( $read[ $id ] ?? '' );
			}
		}

		return static function ( $parent_id ) use ( $categories, $flags ) {
			$parent_id = (int) $parent_id;
			if ( $parent_id < 1 ) {
				return false;
			}

			if ( null !== $flags && isset( $flags[ $parent_id ] ) ) {
				$flag = $flags[ $parent_id ];
			} else {
				$flag = function_exists( 'wc_get_product' ) ? Livestock::stored( wc_get_product( $parent_id ) ) : '';
			}

			$in_category = false;
			$terms       = '' === $flag && $categories && function_exists( 'get_the_terms' ) ? get_the_terms( $parent_id, 'product_cat' ) : false;
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( is_object( $term ) && isset( $term->slug ) && in_array( strtolower( (string) $term->slug ), $categories, true ) ) {
					$in_category = true;
					break;
				}
			}

			return Livestock::decide( $flag, $in_category );
		};
	}

	/**
	 * The preview in words.
	 *
	 * @param array $plan     plan().
	 * @param array $settings Settings.
	 * @param array $standard Size_List::standard().
	 * @param array $counts   Store::variation_counts() (total, with_bag) — optional.
	 * @return string[]
	 */
	public static function describe( array $plan, array $settings, array $standard, array $counts = array() ) {
		$lines = array();

		if ( isset( $counts['total'] ) ) {
			$total = (int) $counts['total'];
			$with  = (int) ( $counts['with_bag'] ?? 0 );
			/* translators: %d: variations in all */
			$line = sprintf( _n( '%d variation in all.', '%d variations in all.', $total, 'fishotel-misc-plugin' ), $total );
			/* translators: %d: variations that have a bag size */
			$lines[] = $line . ' ' . sprintf( _n( '%d already has a Bag size and is not touched.', '%d already have a Bag size and are not touched.', $with, 'fishotel-misc-plugin' ), $with );
		}

		$would = count( $plan['set'] );
		if ( 0 === (int) $plan['empty'] ) {
			$lines[] = __( 'No variation is without a Bag size: there is nothing to fill.', 'fishotel-misc-plugin' );
			return $lines;
		}

		$parts = array();
		foreach ( $settings['bags'] as $bag ) {
			if ( ! empty( $plan['by_bag'][ (int) $bag['id'] ] ) ) {
				$parts[] = $bag['name'] . ': ' . (int) $plan['by_bag'][ (int) $bag['id'] ];
			}
		}
		/* translators: %d: variations without a bag size */
		$line = sprintf( _n( '%d variation has no Bag size.', '%d variations have no Bag size.', (int) $plan['empty'], 'fishotel-misc-plugin' ), (int) $plan['empty'] );
		/* translators: %d: how many of them would be set */
		$line   .= ' ' . sprintf( __( 'The map would set %d of them', 'fishotel-misc-plugin' ), $would );
		$lines[] = $line . ( $parts ? ' (' . implode( ', ', $parts ) . ').' : '.' );

		if ( $plan['unmapped'] ) {
			$parts = array();
			foreach ( $plan['unmapped'] as $slug => $count ) {
				$parts[] = Size_List::label( $standard['sizes'], (string) $slug ) . ': ' . (int) $count;
			}
			$count = array_sum( $plan['unmapped'] );
			/* translators: 1: how many variations, 2: the sizes with their counts */
			$lines[] = sprintf( _n( '%1$d carries a size the map has no bag for (%2$s) — it stays empty.', '%1$d carry a size the map has no bag for (%2$s) — they stay empty.', $count, 'fishotel-misc-plugin' ), $count, implode( ', ', $parts ) );
		}
		if ( $plan['no_size'] > 0 ) {
			/* translators: %d: how many variations */
			$lines[] = sprintf( _n( '%d carries no size (any size, or none) — it stays empty.', '%d carry no size (any size, or none) — they stay empty.', (int) $plan['no_size'], 'fishotel-misc-plugin' ), (int) $plan['no_size'] );
		}
		if ( ! empty( $plan['not_fish'] ) ) {
			/* translators: %d: how many variations */
			// "Never saved as a choice": the editor draws the box ticked for a Bag size from before it, and stores nothing.
			$lines[] = sprintf( _n( '%d is of a product that is not packed in a bag (“Livestock — packed in a bag” is off on it, or was never saved as a choice) — it stays empty; if it is, tick that box on the product and save — click it off and on again where it already shows ticked.', '%d are of products that are not packed in a bag (“Livestock — packed in a bag” is off on them, or was never saved as a choice) — they stay empty; if they are, tick that box on the product and save — click it off and on again where it already shows ticked.', (int) $plan['not_fish'], 'fishotel-misc-plugin' ), (int) $plan['not_fish'] );
		}

		return $lines;
	}

	/**
	 * Set one variation's Bag size through its product object — only when
	 * it still has none.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $bag_id       Bag id.
	 * @return string set | skipped (it has a value by now) | failed.
	 */
	public static function write( $variation_id, $bag_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'failed';
		}

		try {
			$product = wc_get_product( (int) $variation_id );
			if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) || ! method_exists( $product, 'save_meta_data' ) ) {
				return 'failed';
			}
			if ( '' !== Bags::own_value( $product ) ) {
				return 'skipped';
			}
			$product->update_meta_data( Packing::META_BAG, (string) (int) $bag_id );
			$product->save_meta_data();
		} catch ( \Throwable $e ) {
			return 'failed';
		}

		return 'set';
	}

	/**
	 * One batch of the run: the next variations without a Bag size after a
	 * cursor, each one the map answers for set.
	 *
	 * @param object        $store    Store (or the tests' stand-in) with variations_without_bag().
	 * @param array         $settings Settings.
	 * @param array         $standard Size_List::standard().
	 * @param int           $after_id The cursor: the last variation id looked at (0 = start).
	 * @param int           $limit    Variations to look at.
	 * @param callable|null $writer   function ( $variation_id, $bag_id ): 'set'|'skipped'|'failed' (null = write()).
	 * @return array{set: int, skipped: int, failed: int, looked: int, last_id: int, done: bool}
	 */
	public static function run( $store, array $settings, array $standard, $after_id = 0, $limit = self::BATCH, $writer = null ) {
		$limit = max( 1, (int) $limit );
		$rows  = is_object( $store ) && method_exists( $store, 'variations_without_bag' ) ? (array) $store->variations_without_bag( (int) $after_id, $limit ) : array();
		$plan  = self::plan( $rows, $settings, $standard, self::fish_test( array_column( $rows, 'parent' ), $store ) );

		$out = array(
			'set'     => 0,
			'skipped' => 0,
			'failed'  => 0,
			'looked'  => count( $rows ),
			'last_id' => (int) $after_id,
			'done'    => count( $rows ) < $limit,
		);

		foreach ( $rows as $row ) {
			$id             = (int) ( $row['id'] ?? 0 );
			$out['last_id'] = max( $out['last_id'], $id );
			if ( ! isset( $plan['set'][ $id ] ) ) {
				continue;
			}
			$outcome = null === $writer ? self::write( $id, $plan['set'][ $id ] ) : call_user_func( $writer, $id, $plan['set'][ $id ] );
			if ( 'set' === $outcome ) {
				$out['set']++;
			} elseif ( 'skipped' === $outcome ) {
				$out['skipped']++;
			} else {
				$out['failed']++;
			}
		}

		return $out;
	}

	/**
	 * Why the tool cannot run, or '' when it can.
	 *
	 * @param array $settings Settings.
	 * @param array $standard Size_List::standard().
	 * @return string
	 */
	public static function blocked( array $settings, array $standard ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return __( 'WooCommerce is not active: there are no variations to fill.', 'fishotel-misc-plugin' );
		}
		if ( ! $settings['bags'] ) {
			return __( 'Add the bag sizes first.', 'fishotel-misc-plugin' );
		}
		if ( ! $settings['size_map'] ) {
			return __( 'The size → bag map is empty: choose a bag for the sizes first.', 'fishotel-misc-plugin' );
		}
		if ( ! $standard['sizes'] ) {
			return __( 'There is no list of sizes to go by.', 'fishotel-misc-plugin' );
		}
		return '';
	}

	/**
	 * "Right now: 10 variations, 4 with a Bag size of their own." — the
	 * line above the tool, and what the last step of a run sends back so
	 * the page does not go on showing the count from before the run.
	 *
	 * @param array $counts Store::variation_counts(): total, with_bag.
	 * @return string
	 */
	public static function now_line( array $counts ) {
		/* translators: 1: variations in all, 2: variations with a bag size */
		return sprintf( __( 'Right now: %1$d variations, %2$d with a Bag size of their own.', 'fishotel-misc-plugin' ), (int) ( $counts['total'] ?? 0 ), (int) ( $counts['with_bag'] ?? 0 ) );
	}

	/**
	 * The preview — the testable half of its AJAX handler. Changes nothing.
	 *
	 * @param array       $raw      Unslashed POST: _wpnonce.
	 * @param object|null $store    Store (null = a new one).
	 * @param array|null  $standard Size_List::standard() (null = resolve it).
	 * @return array{status: int, ok: bool, message: string, would_set?: int, empty?: int, lines?: string[]}
	 */
	public static function preview_request( array $raw, $store = null, $standard = null ) {
		$denied = Settings::guard( $raw, self::NONCE_ACTION );
		if ( $denied ) {
			return $denied;
		}

		$settings = Settings::get();
		$standard = is_array( $standard ) ? $standard : Size_List::standard();
		$blocked  = self::blocked( $settings, $standard );
		if ( '' !== $blocked ) {
			return array(
				'status'  => 409,
				'ok'      => false,
				'message' => $blocked,
			);
		}

		$store  = is_object( $store ) ? $store : new Store();
		$rows   = (array) $store->variations_without_bag();
		$plan   = self::plan( $rows, $settings, $standard, self::fish_test( array_column( $rows, 'parent' ), $store ) );
		$counts = method_exists( $store, 'variation_counts' ) ? (array) $store->variation_counts() : array();
		$lines  = self::describe( $plan, $settings, $standard, $counts );

		return array(
			'status'    => 200,
			'ok'        => true,
			'message'   => implode( ' ', $lines ),
			'lines'     => $lines,
			'would_set' => count( $plan['set'] ),
			'empty'     => (int) $plan['empty'],
		);
	}

	/**
	 * One batch of the run — the testable half of its AJAX handler.
	 *
	 * @param array         $raw      Unslashed POST: _wpnonce, after (the cursor).
	 * @param object|null   $store    Store (null = a new one).
	 * @param array|null    $standard Size_List::standard() (null = resolve it).
	 * @param callable|null $writer   See run().
	 * @return array{status: int, ok: bool, message: string, set?: int, skipped?: int, failed?: int, last_id?: int, done?: bool, now?: string}
	 */
	public static function run_request( array $raw, $store = null, $standard = null, $writer = null ) {
		$denied = Settings::guard( $raw, self::NONCE_ACTION );
		if ( $denied ) {
			return $denied;
		}

		$settings = Settings::get();
		$standard = is_array( $standard ) ? $standard : Size_List::standard();
		$blocked  = self::blocked( $settings, $standard );
		if ( '' !== $blocked ) {
			return array(
				'status'  => 409,
				'ok'      => false,
				'message' => $blocked,
			);
		}

		$after = isset( $raw['after'] ) && is_scalar( $raw['after'] ) ? absint( $raw['after'] ) : 0;
		$store = is_object( $store ) ? $store : new Store();
		$batch = self::run( $store, $settings, $standard, $after, self::BATCH, $writer );

		/* translators: %d: how many variations were set in this step */
		$message = sprintf( _n( '%d variation set.', '%d variations set.', $batch['set'], 'fishotel-misc-plugin' ), $batch['set'] );
		if ( $batch['failed'] > 0 ) {
			/* translators: %d: how many could not be saved */
			$message .= ' ' . sprintf( __( '%d could not be saved.', 'fishotel-misc-plugin' ), $batch['failed'] );
		}

		$out = array(
			'status'  => 200,
			'ok'      => true,
			'message' => $message,
			'set'     => $batch['set'],
			'skipped' => $batch['skipped'],
			'failed'  => $batch['failed'],
			'last_id' => $batch['last_id'],
			'done'    => $batch['done'],
		);

		// The run is over: the count as it is now, for the line above the tool.
		if ( $batch['done'] && method_exists( $store, 'variation_counts' ) ) {
			$out['now'] = self::now_line( (array) $store->variation_counts() );
		}

		return $out;
	}

	/**
	 * AJAX: the preview.
	 */
	public function ajax_preview() {
		Settings::reply( self::preview_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	/**
	 * AJAX: one batch of the run.
	 */
	public function ajax_run() {
		Settings::reply( self::run_request( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}
}
